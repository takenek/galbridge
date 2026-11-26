<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
 * Original author: Marek Królikowski (TaKeN)
 * Original project: https://github.com/takenek/galbridge
 * SPDX-License-Identifier: GPL-3.0-or-later
 * See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
 *
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Cli;

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Db\RoleScope;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Entitlements;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\Limits;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Schema;
use GalBridge\Directory\Sql;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * Administrator command line tool (bin/galbridge.sh).
 *
 *  diag [--user=EMAIL] [--url=URL] [--cafile=FILE] [--strict]
 *                        check the installation, the configuration and the vmail database; with
 *                        --user report (as counts only) what that user would see; with --url ask the
 *                        web server whether it serves the plugin directory (--cafile: verify the
 *                        certificate of an https:// address against the PEM certificates in FILE,
 *                        e.g. the self-signed certificate iRedMail installs); with --strict a WARN
 *                        line counts as a problem (exit code 2) - for automated checks
 *  grants --driver=mysql|pgsql [--host=...] [--port=N] [--account-host=... (mysql)] [--db=vmail]
 *         [--role=galbridge] [--connection-limit=25] [--reset-password | --grants-only]
 *         [--clear-text-password (mysql)]
 *                        print the least-privilege role statements on standard output and the
 *                        matching DSN line on standard error (nothing is written to disk). By
 *                        default they CREATE the role and stop when it already exists;
 *                        --reset-password gives an existing role a new password; --grants-only
 *                        re-applies attributes, limits, view and grants and leaves the password
 *                        alone. Meant to run as root with --allow-root (bin/galbridge.sh checks the
 *                        code it loads and prints; README 2.2)
 *
 * Standard output carries the report of diag and the SQL of grants - nothing else: usage errors,
 * refusals and the DSN line of grants go to standard error, so a redirected SQL file holds no error
 * text and - except with --clear-text-password (MySQL 8.4 and later), whose statements carry the
 * password itself, which its header says - not the password. The SQL contains no backslash and no
 * line that a database client would take as a command of its own (checked before anything is
 * printed).
 *
 * It never prints the database password (apart from the DSN line of grants and the statements of
 * grants --clear-text-password) or directory contents
 * (counts only - except the addresses of entries that no book offers because they do not follow the
 * address rule, which the administrator needs in order to correct them). Every diag section runs on its own, so one failing check cannot hide the others.
 * Everything it prints passes Terminal::clean(): a value from a database server, an exception or a
 * file name cannot steer the terminal. Exit codes: 0 = no FAIL (the summary counts WARN lines; with
 * --strict they are problems too), 1 = usage error (bin/galbridge.sh also: refused as root, or
 * grants refused for the code or the identity it would run with), 2 = problems found or an
 * unexpected error ("Result: aborted"; bin/galbridge.sh ends a PHP fatal error and an error during
 * Roundcube's start the same way).
 */
final class Tool
{
    /** The Roundcube branch this plugin version supports (composer.json min-version / max-version). */
    public const SUPPORTED_BRANCH = '1.7';

    /**
     * The newest release of that branch when this plugin version was released (the suites ran on
     * every release from 1.7.0 up to it). Older cores are supported but lack security fixes of the
     * core itself; newer 1.7.x releases are supported without a new plugin release.
     */
    public const LATEST_KNOWN_ROUNDCUBE = '1.7.7';

    /** examples per kind of the entries diag finds not offered (entriesNotOffered()) */
    private const EXAMPLES = 10;

    /**
     * Above this many entries a full set (an export, a group expansion) costs a request noticeable
     * memory and time: diag warns when galbridge_max_export or the group cap is higher.
     */
    public const COSTLY_FULL_SET = 10000;

    /** End of security support per PHP branch (https://www.php.net/supported-versions.php). */
    private const PHP_SECURITY_EOL = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];

    /** optional vmail columns GAL Bridge can use, for the INFO line about unused ones */
    private const KNOWN_OPTIONAL = [
        'mailbox' => ['first_name', 'last_name', 'department', 'telephone', 'mobile', 'birthday'],
    ];

    /** role names grants never touches: system roles, and (added in grants()) every refused role */
    private const RESERVED_ROLES = ['public', 'mysql', 'mariadb', 'replication', 'debian'];

    /** PBKDF2 rounds of the PostgreSQL SCRAM-SHA-256 verifier (the server's own default) */
    private const SCRAM_ITERATIONS = 4096;

    /** des_key of Roundcube's config/defaults.inc.php: public, so no secret */
    private const ROUNDCUBE_DEFAULT_DES_KEY = 'rcmail-!24ByteDESkey*Str';

    /** the name Roundcube knows the plugin by: its class, its directory below plugins/ and its web path */
    private const PLUGIN_NAME = 'galbridge';

    /** the Composer package of the plugin (composer.json) */
    private const PACKAGE = 'takenek/galbridge';

    /** the options of each command: true = takes a value (--name=value), false = a flag */
    private const OPTIONS = [
        'diag' => ['user' => true, 'url' => true, 'cafile' => true, 'strict' => false, 'allow-root' => false],
        'grants' => [
            'driver' => true, 'host' => true, 'port' => true, 'account-host' => true, 'db' => true, 'role' => true, 'connection-limit' => true,
            'reset-password' => false, 'grants-only' => false, 'clear-text-password' => false, 'allow-root' => false,
        ],
    ];

    private const USAGE = [
        'diag' => 'Usage: galbridge.sh diag [--user=EMAIL] [--url=URL] [--cafile=FILE] [--strict]',
        'grants' => 'Usage: galbridge.sh grants --driver=mysql|pgsql [--host=H] [--port=N] [--account-host=A (mysql only)] [--db=vmail] [--role=galbridge] [--connection-limit=25] [--reset-password | --grants-only] [--clear-text-password (mysql only)]',
    ];

    /**
     * The long-form commands of the mysql / mariadb client (mysql --help, "help" in the client):
     * at the start of a line the client runs them itself instead of sending the line to the server
     * - "system" runs a program. No line of the grants output may start with one (psql's commands
     * all start with a backslash, which the output never contains). mysql --binary-mode leaves only
     * "charset" and "delimiter" of them in a piped script; the list is checked whatever the client.
     */
    private const CLIENT_COMMANDS = [
        '?', 'charset', 'clear', 'connect', 'delimiter', 'edit', 'ego', 'exit', 'go', 'help', 'nopager', 'notee', 'nowarning', 'pager',
        'print', 'prompt', 'query_attributes', 'quit', 'rehash', 'resetconnection', 'sandbox', 'source', 'ssl_session_data_print', 'status',
        'system', 'tee', 'use', 'warnings',
    ];

    /**
     * --clear-text-password: a statement MariaDB cannot run (the setting exists in MySQL 8.0.3 and
     * later only), right before the statement with the password. It stops the script on MariaDB
     * whatever the client does with comments - the MariaDB-only guard before it is a comment.
     */
    private const MYSQL_ONLY_GUARD = 'SELECT @@SESSION.information_schema_stats_expiry AS galbridge_mysql_8_only;';

    /** the first line of the paragraph of grants.mysql.sql that says no statement holds the password */
    private const VERIFIER_NOTE = '-- No statement of this file contains the password of the account';

    /**
     * the header sentence of grants.mysql.sql (two lines) that says the output carries the password
     * as its verifier and points at VERIFIER_NOTE: rewritten for --clear-text-password
     */
    private const VERIFIER_HEADER = '/^-- (It is this file with a fresh random password )\(as its verifier: see the note above the \[role\]\R-- block\)(, [^\r\n]*)$/m';

    /** diag: every switch of Config::BOOLEANS and Config::TRISTATES, by what it controls */
    private const SWITCH_LINES = [
        'switches (books)' => [
            'galbridge_domain_book', 'galbridge_domain_autocomplete', 'galbridge_domain_export', 'galbridge_domain_searchonly',
            'galbridge_global_book', 'galbridge_global_autocomplete', 'galbridge_global_export', 'galbridge_global_searchonly',
            'galbridge_global_include_home', 'galbridge_global_allow_wildcard',
        ],
        'switches (entries)' => [
            'galbridge_user_aliases', 'galbridge_include_aliases', 'galbridge_include_mailing_lists',
            'galbridge_hide_expired', 'galbridge_mark_foreign_domains',
        ],
        'switches (controls)' => ['galbridge_db_allow_plaintext', 'galbridge_db_allow_verify_ca', 'galbridge_imap_allow_plaintext', 'galbridge_debug'],
    ];

    /** what a release archive puts into the plugin directory (plus the local config.inc.php) */
    private const RELEASE_FILES = [
        'galbridge.php', 'lib', 'bin', 'SQL', 'localization', 'docs', 'config.inc.php', 'config.inc.php.dist', '.htaccess',
        'composer.json', 'LICENSE', 'COPYRIGHT', 'README.md', 'SECURITY.md', 'CHANGELOG.md',
    ];

    /**
     * What a release puts INTO its directories, as a pattern of the path below the directory (every
     * directory and file found there must match): anything else - a grants output saved next to the
     * templates, a backup of a class, an editor's copy - is named by diag like a foreign file at the
     * top level. Tool tests compare this with what git exports.
     */
    public const RELEASE_TREE = [
        'bin' => '~^galbridge\.sh$~D',
        'lib' => '~^(autoload\.php|GalBridge(/[A-Z][A-Za-z]*)*(/[A-Z][A-Za-z]*\.php)?)$~D',
        'SQL' => '~^grants\.(mysql|postgres)\.sql$~D',
        'localization' => '~^[a-z]{2}_[A-Z]{2}\.inc$~D',
        'docs' => '~^[A-Z][A-Z_]*\.md$~D',
    ];

    /** the directories with code the plugin loads or prints, checked recursively for who may change it */
    private const CODE_DIRS = ['bin', 'lib', 'SQL', 'localization'];

    /** entries below one directory of the plugin that diag looks at (a release has about fifty) */
    private const TREE_LIMIT = 5000;

    /** what only a git clone has: test harness (scripts that reset databases and Dovecot) and tooling */
    private const CLONE_FILES = ['tests', '.git', '.github', '.gitattributes', '.gitignore', '.gitguardian.yaml', '.gitleaks.toml', '.php-cs-fixer.dist.php', 'phpstan.neon.dist', 'phpunit.xml.dist'];

    private int $failures = 0;

    private int $warnings = 0;

    /** diag --strict: a WARN line is a problem as well (exit code 2) */
    private bool $strict = false;

    /** diag ran without --url: the summary says that the web access was not checked */
    private bool $webUnchecked = false;

    /** diag --cafile: the PEM file the certificate of an https:// --url is verified against (null = PHP's default CAs) */
    private ?string $cafile = null;

    /** @var array<string, true> directories of the plugin with more than TREE_LIMIT entries */
    private array $truncated = [];

    /** @var resource */
    private $output;

    /** @var resource */
    private $errors;

    /**
     * @param null|resource $output stream for the report of diag and the SQL of grants (default STDOUT)
     * @param null|resource $errors stream for usage errors, refusals and the DSN line of grants
     *                              (default STDERR; when only $output is given, $output - one
     *                              transcript for a caller that reads a single stream)
     */
    public function __construct(private readonly ?\rcube $rc, private readonly string $pluginDir, $output = null, $errors = null)
    {
        $this->output = $output ?? STDOUT;
        $this->errors = $errors ?? $output ?? STDERR;
    }

    /**
     * The command line words of bin/galbridge.sh as the arguments of run(): --name=value and --flag
     * by name, every other word by position. An option given twice is a usage error (the second
     * value would silently win); the problem is returned, the caller prints it with usage().
     *
     * @param list<string> $argv the words after the program name
     *
     * @return array{0: array<int|string, string|true>, 1: null|string} [arguments, problem]
     */
    public static function parseArguments(array $argv): array
    {
        $args = [];
        foreach ($argv as $arg) {
            // /s and \z: a value is everything after '=', a line break included - it is then refused by
            // the validation of the option instead of being cut off or dropped
            if (preg_match('/^--([a-z-]+)(?:=(.*))?\z/s', $arg, $m) === 1) {
                if (array_key_exists($m[1], $args)) {
                    return [$args, '--' . $m[1] . ' is given twice: give every option once'];
                }
                $args[$m[1]] = array_key_exists(2, $m) ? $m[2] : true;
            } else {
                $args[] = $arg;
            }
        }
        return [$args, null];
    }

    /**
     * The usage line of a command (of the tool, for an unknown one).
     */
    public static function usage(string $cmd): string
    {
        return self::USAGE[$cmd] ?? 'Usage: plugins/galbridge/bin/galbridge.sh diag|grants [options]  (plugins/galbridge/bin/galbridge.sh help)';
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function run(array $args): int
    {
        $cmd = is_string($args[0] ?? null) ? $args[0] : 'help';
        try {
            return $this->dispatch($cmd, $args);
        } catch (\Throwable $e) {
            // never "OK" by accident: an error outside a guarded diag section ends with exit code 2 -
            // at the end of the report of diag, and for grants on standard error (never into the SQL)
            $text = "\nerror: " . Log::sanitize(get_class($e) . ': ' . $e->getMessage()) . "\nResult: aborted\n";
            $cmd === 'diag' ? $this->out($text) : $this->err($text);
            return 2;
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function dispatch(string $cmd, array $args): int
    {
        if (isset(self::OPTIONS[$cmd])) {
            // An option that is misspelled, or written "--role name" instead of "--role=name", must
            // not silently leave the default in place: the statements would be for another role.
            $problem = self::argumentProblem($cmd, $args);
            if ($problem !== null) {
                $this->err(self::USAGE[$cmd] . "\n" . $problem . "\n");
                return 1;
            }
        }
        switch ($cmd) {
            case 'diag':
                if ($this->rc === null) {
                    $this->err("diag needs a Roundcube installation\n");
                    return 1;
                }
                $this->strict = ($args['strict'] ?? false) === true;
                $url = is_string($args['url'] ?? null) ? $args['url'] : '';
                if (is_string($args['cafile'] ?? null)) {
                    $problem = self::cafileProblem($args['cafile'], $url);
                    if ($problem !== null) {
                        $this->err(self::USAGE[$cmd] . "\n" . $problem . "\n");
                        return 1;
                    }
                    $this->cafile = $args['cafile'];
                }
                return $this->diag(is_string($args['user'] ?? null) ? $args['user'] : '', $url);
            case 'grants':
                return $this->grants($args);
            default:
                $help = "GAL Bridge administration tool\n\n"
                    . "Usage: plugins/galbridge/bin/galbridge.sh <command> [options]  (run from the Roundcube directory)\n\n"
                    . "  diag [--user=EMAIL]          check the installation and the vmail database (no secrets;\n"
                    . "                               --user reports, as counts, what that user would see)\n"
                    . "       [--url=URL]             also ask the web server at URL (the webmail address) whether it\n"
                    . "                               serves files of the plugin directory - it must not\n"
                    . "       [--cafile=FILE]         with an https:// --url: verify the certificate of the web server against\n"
                    . "                               the PEM certificate(s) in FILE (absolute path), e.g. the self-signed\n"
                    . "                               certificate of iRedMail, /etc/ssl/certs/iRedMail.crt\n"
                    . "       [--strict]              count WARN lines as problems as well (exit code 2)\n"
                    . "  grants --driver=mysql|pgsql  print the read-only role statements with a fresh password (standard\n"
                    . "                               output) and the matching DSN line (standard error; nothing is written\n"
                    . "                               to disk). Review the SQL and run it as the database administrator\n"
                    . "                               (README 2.2). They CREATE the role and stop when a role of that name exists\n"
                    . "         [--host=H]            database server written into the DSN (default localhost / 127.0.0.1)\n"
                    . "         [--port=N]            its port, written into the DSN as host:port (default: the driver's own);\n"
                    . "                               MariaDB/MySQL: not with the host localhost - PHP connects to it through\n"
                    . "                               the unix socket and ignores the port (use --host=127.0.0.1)\n"
                    . "         [--account-host=A]    MySQL/MariaDB only: host part of the account = where Roundcube connects\n"
                    . "                               from (default localhost; patterns like 10.0.0.% allowed)\n"
                    . "         [--db=vmail] [--role=galbridge] [--connection-limit=25]\n"
                    . "         [--reset-password]    for an EXISTING role as well: set a new password (the old one stops\n"
                    . "                               working at once)\n"
                    . "         [--grants-only]       for an EXISTING role: re-apply attributes, limits, view and grants (after a\n"
                    . "                               plugin upgrade); the password is not changed and no DSN is printed\n"
                    . "         [--clear-text-password]  MySQL 8.4 or later only (mysql_native_password not loaded): write the\n"
                    . "                               password itself into the statement instead of its verifier. Never for\n"
                    . "                               MariaDB, whose logs would keep it (the output refuses to run there)\n\n"
                    . "Do not run this tool as root: it loads PHP code from the plugin and the Roundcube directory. Run diag\n"
                    . "as the web server user. The one exception is grants: run it as root with --allow-root, which checks\n"
                    . "that root alone can change the code it loads and prints (a process of the web server user could read\n"
                    . "or change what the database administrator runs; grants refuses that user).\n";
                if ($cmd === 'help') {
                    $this->out($help);
                    return 0;
                }
                $this->err($help);
                return 1;
        }
    }

    /**
     * What is wrong with the arguments of a command, or null: an unknown option, an option that
     * needs a value and has none ("--role acme" instead of "--role=acme"), a flag with a value, or a
     * stray word.
     *
     * @param array<int|string, mixed> $args
     */
    private static function argumentProblem(string $cmd, array $args): ?string
    {
        foreach ($args as $name => $value) {
            if (is_int($name)) {
                if ($name > 0) {
                    return "unexpected argument '" . Log::sanitize(is_scalar($value) ? (string) $value : '') . "': options are written --name=value";
                }
                continue;
            }
            if (!array_key_exists($name, self::OPTIONS[$cmd])) {
                return 'unknown option --' . Log::sanitize($name);
            }
            if (self::OPTIONS[$cmd][$name] && !is_string($value)) {
                return "--{$name} needs a value: --{$name}=...";
            }
            if (!self::OPTIONS[$cmd][$name] && $value !== true) {
                return "--{$name} takes no value";
            }
        }
        return null;
    }

    /**
     * diag --cafile=FILE: the certificate(s) an https:// --url is verified against - for a web server
     * with a self-signed certificate (iRedMail installs one, /etc/ssl/certs/iRedMail.crt, until a
     * trusted certificate replaces it). Verification itself stays on: the file only says whom to
     * trust. An absolute path (as root the tool works in Roundcube's program/ directory, so a relative
     * name would mean something else than it says), a readable file with at least one PEM
     * certificate and no private key.
     */
    private static function cafileProblem(string $file, string $url): ?string
    {
        if ($url === '') {
            return '--cafile needs --url=https://...: it names the certificate(s) that the certificate of the web server is verified against';
        }
        if (!str_starts_with($url, 'https://')) {
            return '--cafile applies to an https:// address only (--url is not one)';
        }
        if (!str_starts_with($file, '/') || strlen($file) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $file) === 1) {
            return '--cafile must be an absolute path, e.g. --cafile=/etc/ssl/certs/iRedMail.crt';
        }
        $pem = is_file($file) && is_readable($file) ? @file_get_contents($file, false, null, 0, 1048576) : false;
        if (!is_string($pem)) {
            return '--cafile: no readable file ' . Log::sanitize($file);
        }
        if (str_contains($pem, 'PRIVATE KEY-----')) {
            return '--cafile: ' . Log::sanitize($file) . ' holds a private key - give the certificate only (iRedMail: /etc/ssl/certs/iRedMail.crt)';
        }
        if (!str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            return '--cafile: ' . Log::sanitize($file) . ' holds no PEM certificate (-----BEGIN CERTIFICATE-----)';
        }
        return null;
    }

    // ------------------------------------------------------------------ grants

    /**
     * @param array<int|string, mixed> $args
     */
    private function grants(array $args): int
    {
        $driver = is_string($args['driver'] ?? null) ? strtolower($args['driver']) : '';
        $driver = $driver === 'mysqli' || $driver === 'mariadb' ? 'mysql' : ($driver === 'postgres' || $driver === 'postgresql' ? 'pgsql' : $driver);
        $host = is_string($args['host'] ?? null) ? strtolower($args['host']) : ($driver === 'pgsql' ? '127.0.0.1' : 'localhost');
        $accountHost = is_string($args['account-host'] ?? null) ? strtolower($args['account-host']) : 'localhost';
        $db = is_string($args['db'] ?? null) ? $args['db'] : 'vmail';
        $role = is_string($args['role'] ?? null) ? $args['role'] : 'galbridge';
        // --port: digits only (no sign, no blank), 1-65535; present but anything else is a usage error
        $port = null;
        if (array_key_exists('port', $args)) {
            $raw = $args['port'];
            $port = is_string($raw) && preg_match('/^[0-9]{1,5}$/D', $raw) ? (int) $raw : 0;
        }
        $limit = 25;
        if (array_key_exists('connection-limit', $args)) {
            // present but not a plain integer: a usage error, never a silent default
            $raw = $args['connection-limit'];
            $limit = is_string($raw) && preg_match('/^[0-9]{1,5}$/D', $raw) ? (int) $raw : 0;
        }
        $reset = ($args['reset-password'] ?? false) === true;
        $grantsOnly = ($args['grants-only'] ?? false) === true;
        $clearText = ($args['clear-text-password'] ?? false) === true;
        if (!in_array($driver, ['mysql', 'pgsql'], true)
            || !preg_match('/^[a-z0-9_]{1,64}$/D', $db)
            || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $role)
            // IPv6 only in brackets, and without an embedded IPv4 part: Roundcube's DSN parser
            // cannot read [::ffff:10.0.0.5], so the plugin would refuse the printed DSN (Dsn)
            || !preg_match('/^([a-z0-9.-]{1,253}|\[[0-9a-f:]{2,39}\])$/D', $host)
            || !preg_match('/^[a-z0-9.%_:-]{1,255}$/D', $accountHost)
            || $limit < 1 || $limit > 10000
            || ($port !== null && ($port < 1 || $port > 65535))
            || ($reset && $grantsOnly)
            || (array_key_exists('reset-password', $args) && !$reset)
            || (array_key_exists('grants-only', $args) && !$grantsOnly)
            || (array_key_exists('clear-text-password', $args) && (!$clearText || $driver !== 'mysql' || $grantsOnly))) {
            $this->err(self::USAGE['grants'] . "\n");
            return 1;
        }
        if ($driver === 'pgsql' && array_key_exists('account-host', $args)) {
            // it would be ignored: the administrator must not believe the role is limited to a host
            $this->err(self::USAGE['grants'] . "\n--account-host is for MariaDB/MySQL accounts: PostgreSQL limits the client addresses of a role in pg_hba.conf (README 2.2)\n");
            return 1;
        }
        if ($driver === 'mysql' && $port !== null && $host === 'localhost') {
            // the printed DSN would name a port that is never used: PHP's MariaDB/MySQL driver
            // connects to "localhost" through the unix socket (the plugin refuses such a DSN, Dsn)
            $this->err(self::USAGE['grants'] . "\n--port with the host localhost: PHP connects to a MariaDB/MySQL server on localhost through the unix socket and ignores the port - use --host=127.0.0.1 (or the address of the server) with --port\n");
            return 1;
        }
        // the statements change password, limits and settings of the NAMED role: never for the role
        // of another component (the names the plugin itself refuses to connect with) or a system role
        if (in_array($role, [...Config::DEFAULTS['galbridge_db_refused_users'], ...self::RESERVED_ROLES], true) || str_starts_with($role, 'pg_')) {
            $this->err("grants: '{$role}' is a role of another component or of the database server (galbridge_db_refused_users): choose a dedicated role name, e.g. --role=galbridge\n");
            return 1;
        }
        $file = $this->pluginDir . '/SQL/' . ($driver === 'mysql' ? 'grants.mysql.sql' : 'grants.postgres.sql');
        $template = is_file($file) ? (string) file_get_contents($file) : '';
        $placeholder = $driver === 'mysql' ? 'CHANGE_ME_PASSWORD_VERIFIER' : 'CHANGE_ME_RANDOM_PASSWORD';
        if ($template === '' || !str_contains($template, $placeholder)
            || preg_match('/^-- \[role\]/m', $template) !== 1 || preg_match('/^-- \[\/role\]/m', $template) !== 1
            || ($driver === 'mysql' && !str_contains($template, "\n" . self::VERIFIER_NOTE))
            || ($clearText && preg_match(self::VERIFIER_HEADER, $template) !== 1)) {
            $this->err("grant template not found: {$file}\n");
            return 2;
        }
        if ($clearText) {
            // the header says the password is carried as its verifier, and the note it points at is
            // left out below: in this output the password itself stands in the statements
            $template = (string) preg_replace_callback(self::VERIFIER_HEADER, static fn (array $m): string => '-- ' . wordwrap(
                $m[1] . '(in clear text, in the statements of the [role] block below - keep this output private and delete it after use)' . $m[2],
                97,
                "\n-- ",
            ), $template, 1);
        }
        $password = bin2hex(random_bytes(20));
        // The statements carry a VERIFIER of the password, never the password: a server that writes
        // account statements to a log (PostgreSQL log_statement; the binary and slow query logs of
        // MariaDB, which keep such statements verbatim) cannot record the secret itself. The clear
        // text exists in the DSN line of the output only - except with --clear-text-password (MySQL
        // 8.4 and later, which rewrites passwords in its own logs), whose statements carry it.
        $secret = $driver === 'pgsql' ? self::scramVerifier($password) : self::mysqlVerifier($password);
        $replaceRole = $reset || $grantsOnly || $clearText;

        $local = ['localhost', '127.0.0.1', '::1', '[::1]'];
        // Roundcube connects from another host: the account requires TLS. Right after the account
        // statements, never at the end: a later statement that fails (an optional GRANT of a column
        // an older schema lacks) stops the script, and the account must not be left without it.
        $tls = $driver === 'mysql' && !in_array($accountHost, $local, true)
            ? ['-- Roundcube connects from another host: require TLS for this account', "ALTER USER '{$role}'@'{$accountHost}' REQUIRE SSL;"]
            : [];

        // rename only inside SQL statements - also the commented-out ones meant to be uncommented
        // (the optional personal-data grant) - while prose comments and the attribution header stay
        $lines = [];
        $inRole = false;
        $inNote = false;
        foreach (preg_split('/\R/', $template) ?: [] as $line) {
            $roleEnds = preg_match('/^-- \[\/role\]/', $line) === 1;
            if ($roleEnds) {
                $inRole = false;
            } elseif ($inRole && $replaceRole) {
                continue; // the "create" statements of the template are replaced (see below)
            }
            // --clear-text-password: the paragraph that says no statement holds the password is not
            // true for this output (it ends at the next empty line)
            $inNote = $clearText && ($inNote ? trim($line) !== '' : str_starts_with($line, self::VERIFIER_NOTE));
            if ($inNote) {
                continue;
            }
            $statement = !str_starts_with(ltrim($line), '--')
                || preg_match('/^\s*--\s*(GRANT|REVOKE|ALTER USER|ALTER ROLE)\b/', $line) === 1;
            if ($statement) {
                // ONE pass (strtr() never looks at what it has inserted): an account host such as
                // "devmail.example.com" contains "vmail." and must not be renamed with the database
                if ($driver === 'mysql') {
                    $line = strtr($line, [
                        $placeholder => $secret,
                        "'galbridge'@'localhost'" => "'{$role}'@'{$accountHost}'",
                        'vmail.' => $db . '.',
                        'MAX_USER_CONNECTIONS 25' => 'MAX_USER_CONNECTIONS ' . $limit,
                    ]);
                } else {
                    // the role first: its name is a plain identifier, the verifier is not looked at
                    $line = (string) preg_replace('/\bgalbridge\b/', $role, $line);
                    $line = strtr($line, [$placeholder => $secret, 'DATABASE vmail ' => "DATABASE {$db} ", 'CONNECTION LIMIT 25' => 'CONNECTION LIMIT ' . $limit]);
                }
            }
            if (preg_match('/^-- \[role\]/', $line) === 1) {
                $inRole = true;
                if ($replaceRole) {
                    // the sentence of the template describes ITS statements ("creates ... and stops
                    // when it exists"); this block does something else and says so
                    $what = $driver === 'mysql' ? 'account' : 'role';
                    $lines[] = '-- [role] ' . match (true) {
                        $reset => "(--reset-password) Creates the {$what} when it does not exist and gives it a NEW password in either case.",
                        $grantsOnly => "(--grants-only) The {$what} is neither created nor given a password: it has to exist.",
                        default => "(--clear-text-password) Creates the {$what} and STOPS when it already exists (ERROR 1396).",
                    };
                    array_push($lines, ...self::roleStatements($driver, $reset, $grantsOnly, $role, $accountHost, $clearText ? $password : $secret, $clearText));
                    continue;
                }
            }
            $lines[] = $line;
            if ($roleEnds) {
                array_push($lines, ...$tls);
            }
        }
        $sql = '-- Generated by bin/galbridge.sh grants on ' . gmdate('Y-m-d H:i:s') . " UTC. Keep this output private.\n"
            . match (true) {
                $reset => "-- It gives the role '{$role}' a NEW PASSWORD, also when the role already exists: the previous password stops working at once.\n",
                $grantsOnly => "-- It re-applies attributes, limits (connection limit: {$limit}, see --connection-limit), view and grants to the EXISTING role '{$role}'; its password is not changed.\n",
                default => "-- It creates the role '{$role}'. When a role of that name already exists it stops and changes nothing (see --reset-password, --grants-only).\n",
            }
        . rtrim(implode("\n", $lines)) . "\n"
        . ($grantsOnly ? "\n-- No DSN line: the password of the role is unchanged, keep the galbridge_db_dsn you have.\n" : '');
        $unsafe = self::clientCommand($sql);
        if ($unsafe !== null) {
            // nothing the tool builds has one (hexadecimal and base64 verifiers, dollar-quoted DO
            // blocks): a template that does was changed - its statements are not printed at all
            $this->err('grants: the statements built from ' . $file . ' contain ' . $unsafe . ', which a database client would run as a command of its own: nothing was printed - use the template of the verified release archive (README 2.1)' . "\n");
            return 2;
        }
        $this->out($sql);
        if ($grantsOnly) {
            return 0;
        }
        // The DSN line holds the password: on standard error - the terminal of the administrator -
        // never in the SQL file that standard output is redirected to (and that a client which
        // keeps comments would send to the server and its logs).
        $dsn = $driver . '://' . $role . ':' . $password . '@' . $host . ($port !== null ? ':' . $port : '') . '/' . $db;
        $this->err("\n-- Put this line into config/galbridge.inc.php (or plugins/galbridge/config.inc.php if you keep the configuration there; owner root:<web server group>, mode 0640) WITHOUT its leading \"-- \", then check the file with php -l:\n");
        $this->err("-- \$config['galbridge_db_dsn'] = '{$dsn}';\n");
        if (!in_array($host, $local, true)) {
            $this->err("-- Remote database: add TLS verification to the DSN (pgsql: ?sslmode=verify-full&sslrootcert=...; mysql: ?ca=...&verify_server_cert=1).\n");
        }
        return 0;
    }

    /**
     * What in a grants output a database client would run itself instead of sending it to the
     * server, or null: a backslash anywhere (every psql meta-command starts with one, also in the
     * middle of a line, and mysql has a short form of each command), or a line that starts with a
     * long-form command of the mysql client (CLIENT_COMMANDS). Comment lines count for the
     * backslash; they cannot start a command.
     */
    public static function clientCommand(string $sql): ?string
    {
        if (str_contains($sql, '\\')) {
            return 'a backslash';
        }
        foreach (preg_split('/\R/', $sql) ?: [] as $no => $line) {
            $line = ltrim($line);
            if (str_starts_with($line, '--') || preg_match('/^([A-Za-z_?]+)/', $line, $m) !== 1) {
                continue;
            }
            if (in_array(strtolower($m[1]), self::CLIENT_COMMANDS, true)) {
                return 'the client command "' . strtolower($m[1]) . '" (line ' . ($no + 1) . ')';
            }
        }
        return null;
    }

    /**
     * The role statements of --reset-password, --grants-only and --clear-text-password (they
     * replace the "create" block of the template, which refuses an existing role and carries a
     * verifier).
     *
     * @param string $secret the verifier - or, with $clearText (MySQL only), the password itself
     *
     * @return list<string>
     */
    private static function roleStatements(string $driver, bool $reset, bool $grantsOnly, string $role, string $accountHost, #[\SensitiveParameter] string $secret, bool $clearText): array
    {
        if ($driver === 'mysql') {
            if ($grantsOnly) {
                return ['-- (--grants-only) the account must exist: the next statement fails otherwise and nothing is granted'];
            }
            $account = "'{$role}'@'{$accountHost}'";
            $auth = $clearText ? "IDENTIFIED BY '{$secret}'" : "IDENTIFIED WITH mysql_native_password AS '{$secret}'";
            $out = [];
            if ($clearText) {
                // MariaDB keeps account statements verbatim in its binary log (and slow query log):
                // the output must never get as far as the password there. MySQL ignores the
                // MariaDB-only comment; a client that drops comments sends the statement after it,
                // which MariaDB cannot run either.
                $out[] = '-- (--clear-text-password) FOR MYSQL 8.4 OR LATER ONLY: the next statements contain the password itself.';
                $out[] = '-- MySQL rewrites passwords in its general, slow and binary logs. MariaDB does not: there the script stops here,';
                $out[] = '-- at the first of the next two statements, or at the second (a setting MariaDB does not have) when the client drops comments.';
                $out[] = "/*M! SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'this output is for MySQL: for MariaDB run grants without --clear-text-password' */;";
                $out[] = self::MYSQL_ONLY_GUARD;
            }
            if ($reset) {
                $out[] = "CREATE USER IF NOT EXISTS {$account} {$auth};";
                $out[] = "ALTER USER {$account} {$auth};";
            } else {
                $out[] = "CREATE USER {$account} {$auth};";
            }
            return $out;
        }
        $exists = "IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN";
        // the attributes of the template, asserted again for a role that exists: a role somebody
        // gave CREATEDB, BYPASSRLS or the like is a role the plugin refuses (role_too_broad)
        $attributes = [
            '-- the attributes of a role created by this file (an existing role may have been given more)',
            "ALTER ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;",
        ];
        return $reset ? [
            'DO $$',
            'BEGIN',
            '    ' . $exists,
            "        CREATE ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;",
            '    END IF;',
            'END',
            '$$;',
            ...$attributes,
            "ALTER ROLE {$role} WITH LOGIN PASSWORD '{$secret}';",
        ] : [
            'DO $$',
            'BEGIN',
            '    ' . $exists,
            "        RAISE EXCEPTION 'role \"{$role}\" does not exist: nothing was changed (create it without --grants-only)';",
            '    END IF;',
            'END',
            '$$;',
            ...$attributes,
        ];
    }

    /**
     * The mysql_native_password verifier of a password ('*' and 40 hexadecimal digits, as stored in
     * mysql.user): MariaDB and MySQL (up to 8.0; later versions when the plugin is loaded) accept it
     * in CREATE / ALTER USER ... IDENTIFIED WITH mysql_native_password AS instead of the clear
     * text. SHA-1 is what that authentication method defines; the password is 160 random bits.
     */
    public static function mysqlVerifier(#[\SensitiveParameter] string $password): string
    {
        return '*' . strtoupper(sha1(sha1($password, true)));
    }

    /**
     * PostgreSQL SCRAM-SHA-256 verifier of a password (RFC 5803 / RFC 7677), as stored in
     * pg_authid.rolpassword: SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>. The server
     * accepts it in CREATE / ALTER ROLE ... PASSWORD instead of the clear text.
     */
    public static function scramVerifier(#[\SensitiveParameter] string $password, ?string $salt = null): string
    {
        $salt ??= random_bytes(16);
        $salted = hash_pbkdf2('sha256', $password, $salt, self::SCRAM_ITERATIONS, 32, true);
        $stored = hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true);
        $server = hash_hmac('sha256', 'Server Key', $salted, true);
        return 'SCRAM-SHA-256$' . self::SCRAM_ITERATIONS . ':' . base64_encode($salt) . '$' . base64_encode($stored) . ':' . base64_encode($server);
    }

    // ------------------------------------------------------------------ diag

    private function diag(string $user, string $url): int
    {
        $rc = $this->rc;
        if ($rc === null) {
            return 1;
        }
        // the plugin is not loaded in the CLI task: read its configuration like rcube_plugin::load_config()
        // (the one routine of Config for the plugin's files)
        Config::loadFiles($rc->config, Config::pluginFiles($rc->config, $this->pluginDir));
        $cfg = new Config($rc->config, [], null, $this->pluginDir);
        $cfg->validate();

        $this->guard('Versions', fn () => $this->versions());
        $this->guard('Configuration', fn () => $this->configuration($cfg, $rc));
        $this->guard(null, fn () => $this->storedPreferences($rc));
        $this->guard('Installation', fn () => $this->installation($url, $cfg));
        $this->guard('IMAP host binding', fn () => $this->imapHosts($cfg, $rc));
        if ($cfg->hostConfigEnabled()) {
            $this->guard('Per-host configuration', fn () => $this->hostConfig($cfg, $rc));
        }
        $this->guard('Core interplay', fn () => $this->coreInterplay($cfg, $rc));

        // the connection is a check like the others: an unexpected error there is a FAIL line of this
        // section, and the counts and the summary below are kept
        $conn = null;
        $this->guard('vmail database', function () use ($cfg, &$conn): void {
            $conn = $this->connect($cfg);
        });
        if ($conn instanceof Connection) {
            // what the role can do beyond reading the directory: the catalogs the role can read
            // (the plugin refuses the same roles - RoleScope - so diag and the runtime agree)
            $scope = null;
            $this->guard(null, function () use ($conn, &$scope): void {
                $scope = RoleScope::read($conn);
                $this->privileges($conn, $scope);
            });
            $columns = [];
            $this->guard(null, function () use ($conn, $cfg, &$columns): void {
                $all = $conn->visibleColumnsEverywhere();
                $columns = Connection::directoryColumns($all);
                $this->columns($columns, $cfg, $all);
            });
            $this->guard(null, fn () => $this->view($conn, $columns, $scope));
            $this->guard(null, fn () => $this->dataSanity($conn, $cfg, $columns));
            if ($user !== '') {
                // validated before it is printed anywhere: the section title shows the normalised login
                $login = Normalizer::address($user);
                $this->guard('User ' . ($login ?? '(not a valid address)'), function () use ($cfg, $conn, $login): void {
                    if ($login === null) {
                        $this->check(false, 'login', 'not a valid address');
                        return;
                    }
                    $this->userReport($cfg, $conn, $login);
                });
            }
        }
        return $this->result();
    }

    /**
     * Run one diag section; an exception becomes a FAIL line and the next section still runs.
     */
    private function guard(?string $title, callable $check): void
    {
        if ($title !== null) {
            $this->section($title);
        }
        try {
            $check();
        } catch (\Throwable $e) {
            // the message of an unexpected exception can carry anything (a driver or server text, a
            // DSN): sanitised like a log value - controls removed, a DSN password masked, length capped
            $this->check(false, 'check aborted', Log::sanitize($e instanceof GalBridgeException ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()));
        }
    }

    /**
     * The version the Roundcube installation reports. A method with a declared return type on
     * purpose: the value differs from installation to installation ("1.7.7" in a release tarball,
     * "1.7-git" in a git checkout or the Packagist package), so the code that judges it must not be
     * analysed - or written - as if it were the constant of one particular tree.
     */
    private static function roundcubeVersion(): string
    {
        return defined('RCMAIL_VERSION') ? (string) constant('RCMAIL_VERSION') : '?';
    }

    private function versions(): void
    {
        // 1.0.5: which release runs - after an update the files of plugins/galbridge are the ones
        // that count (a Composer run without a terminal may have left them alone, "composer copy")
        $code = @file_get_contents($this->pluginDir . '/galbridge.php');
        if (is_string($code) && preg_match("/'version' => '(\\d+\\.\\d+\\.\\d+)'/", $code, $m) === 1) {
            $this->ok('GAL Bridge', $m[1] . ' (the files of the plugin directory)');
        } else {
            $this->info('GAL Bridge', 'version not found in galbridge.php');
        }
        $rcVersion = self::roundcubeVersion();
        $latest = self::LATEST_KNOWN_ROUNDCUBE;
        if (preg_match('/^' . preg_quote(self::SUPPORTED_BRANCH, '/') . '\.\d+$/D', $rcVersion) === 1) {
            $cmp = version_compare($rcVersion, $latest);
            if ($cmp < 0) {
                // supported, but the core itself is behind: its later releases are security releases
                $this->check(false, 'Roundcube', "{$rcVersion} (supported, but older than {$latest}: later 1.7.x releases fix security problems of Roundcube itself - update the core, GAL Bridge needs no new release for that)", true);
            } else {
                $this->ok('Roundcube', $rcVersion . ($cmp > 0 ? " (newer than {$latest}, the newest release known to this GAL Bridge version: 1.7.x patch releases are supported)" : ''));
            }
        } elseif ($rcVersion === self::SUPPORTED_BRANCH . '-git') {
            $this->check(false, 'Roundcube', $rcVersion . ' (a git checkout or Packagist core reports no patch level: make sure it is a current ' . self::SUPPORTED_BRANCH . '.x release)', true);
        } else {
            $this->check(false, 'Roundcube', $rcVersion . ' (not supported: this GAL Bridge version needs Roundcube ' . self::SUPPORTED_BRANCH . '.x)');
        }
        $branch = implode('.', array_slice(explode('.', (string) phpversion()), 0, 2));
        $eol = self::PHP_SECURITY_EOL[$branch] ?? null;
        $supported = $eol !== null && $eol >= gmdate('Y-m-d');
        $this->check($supported, 'PHP', PHP_VERSION . match (true) {
            $eol === null => ' (untested branch)',
            $supported => ' (security support until ' . $eol . ')',
            default => ' (security support by php.net ended on ' . $eol . ': use it only while your distribution still patches it, and plan the upgrade)',
        }, true);
        if (is_readable('/etc/iredmail-release')) {
            $this->info('iRedMail', Log::sanitize(trim((string) strtok((string) file_get_contents('/etc/iredmail-release'), "\n"))));
        }
        // diag is a command line process: what it reports about PHP and the environment is true for
        // this process, which need not be the PHP of the web server
        $uid = self::euid();
        $this->info('process', 'command line PHP (' . PHP_SAPI . ') as ' . ($uid === null ? 'a user that cannot be determined' : 'uid ' . $uid)
            . ': the PHP version, extensions, php.ini settings and ROUNDCUBE_* / PG* variables reported here are those of this process'
            . ' - the PHP of the web server (PHP-FPM pool) has its own: compare php-fpm -m (on Debian and Ubuntu php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' -m) and the pool\'s env[...] / clear_env');
    }

    /**
     * Does this core sort autocomplete suggestions with the ICU collator? mail/autocomplete calls
     * rcube_utils::asort() from Roundcube 1.7.6 on; older releases call PHP's asort() with
     * SORT_LOCALE_STRING. Judged by the method itself, so that a "1.7-git" checkout is told right.
     */
    public static function coreSortsWithIcu(): bool
    {
        return is_callable([\rcube_utils::class, 'asort']);
    }

    private function configuration(Config $cfg, \rcube $rc): void
    {
        $plugins = (array) $cfg->coreValue('plugins', []);
        $this->check(in_array('galbridge', $plugins, true), 'plugin enabled', in_array('galbridge', $plugins, true) ? "\$config['plugins'] contains galbridge" : "add 'galbridge' to \$config['plugins']", true);
        $this->configFile($rc);
        foreach ($cfg->problems() as $p) {
            $this->check(false, $p['option'], $p['message'], $p['level'] !== 'fail');
        }
        $this->secondReading($cfg, $rc);
        $this->dontOverride($cfg);
        // Roundcube encrypts session data (the IMAP password of the session) with des_key, and GAL
        // Bridge keys the configuration fingerprint in sessions with it
        $desKey = $cfg->coreValue('des_key');
        $desProblem = match (true) {
            $desKey === null || $desKey === '' => 'is not set',
            !is_string($desKey) => 'is not a string (' . get_debug_type($desKey) . ')',
            $desKey === self::ROUNDCUBE_DEFAULT_DES_KEY => "is Roundcube's published default",
            strlen($desKey) < 24 => 'is shorter than 24 characters',
            default => null,
        };
        if ($desProblem !== null) {
            $this->check(false, 'des_key', $desProblem
                . ': set a random 24-character des_key in config/config.inc.php - Roundcube encrypts session data (the IMAP password of each session) with it, and GAL Bridge keys the configuration fingerprint in sessions with it', true);
        }
        $this->looseningSwitches($cfg, $plugins);
        foreach ($cfg->envOverrides() as $name) {
            // the value is not shown: it may be the DSN
            $this->check(false, 'environment', 'ROUNDCUBE_' . strtoupper($name) . " overrides {$name} for every process with this variable (this tool included)", true);
        }
        if ($cfg->envOverrides() === []) {
            // no WARN above says nothing about the web server, whose PHP has an environment of its own
            $this->info('environment', 'no ROUNDCUBE_GALBRIDGE_* variable in this process - the PHP of the web server has its own environment and logs the names of the variables it applies (env_override)');
        }
        foreach (Config::coreEnvOverrides() as $name) {
            // Roundcube applies these as well, and the values diag judges (imap_host, des_key, plugins
            // ...) are read through them; the value is not shown
            $this->info('environment', 'ROUNDCUBE_' . strtoupper($name) . " overrides {$name} for this process"
                . ($name === 'log_driver' && self::euid() === 0 ? ' (set by galbridge.sh for --allow-root: the log lines of Roundcube and GAL Bridge go to syslog)' : ''));
        }
        if ($cfg->bool('galbridge_global_book') && $cfg->domainAllowList('galbridge_global_data_domains') === ['*']) {
            $this->check(false, 'global data domains', $cfg->bool('galbridge_global_allow_wildcard')
                ? "'*' is in effect: every hosted domain is listed, including tenants added later"
                : "'*' without galbridge_global_allow_wildcard = true: the global book is empty", true);
        }
        $ids = Entitlements::enabledIds($cfg);
        $disabled = $cfg->booksDisabled();
        $this->check($ids !== [] && !$disabled, 'enabled books', match (true) {
            !$cfg->switchesValid() => 'none: a switch has an ambiguous value (see above)',
            $disabled => 'none: a configuration error disables every book (see the FAIL lines above)',
            $ids === [] => 'none (galbridge_domain_book / galbridge_global_book)',
            default => implode(', ', $ids),
        });
        $this->policyLists($cfg);
        // EVERY switch as it is in effect, under the name of its option (without "galbridge_"): what
        // a configuration file or a ROUNDCUBE_GALBRIDGE_* variable was read as
        $shown = [];
        foreach (self::SWITCH_LINES as $label => $names) {
            $parts = [];
            foreach ($names as $name) {
                $v = $cfg->effectiveSwitch($name);
                $invalid = $v === null;
                if ($invalid) {
                    // an ambiguous value disables every book (a FAIL line above says so); what the
                    // switch itself is read as meanwhile is its restrictive value (Config::RESTRICTIVE)
                    $v = in_array($name, Config::TRISTATES, true) ? ($cfg->searchonly($name) ?? 'auto') : $cfg->bool($name);
                }
                $parts[] = substr($name, strlen('galbridge_')) . ' ' . ($v === 'auto' ? 'auto' : ($v === true ? 'on' : 'off')) . ($invalid ? ' (INVALID)' : '');
                $shown[] = $name;
            }
            if ($label === 'switches (entries)') {
                $parts[] = 'list_policy ' . $cfg->choice('galbridge_list_policy', Config::LIST_POLICIES);
            }
            $this->info($label, implode(', ', $parts));
        }
        if (array_diff([...Config::BOOLEANS, ...Config::TRISTATES], $shown) !== []) {
            // a switch added to Config without a place in SWITCH_LINES: never silently left out
            $this->check(false, 'switches', 'not shown: ' . implode(', ', array_diff([...Config::BOOLEANS, ...Config::TRISTATES], $shown)), true);
        }
        if ($cfg->bool('galbridge_global_book') && $cfg->bool('galbridge_global_autocomplete') && $cfg->bool('galbridge_mark_foreign_domains')) {
            // a consequence of the marker position (README 3.3): told here, because nobody would look
            // for it. How the core sorts depends on its release: rcube_utils::asort() (ICU collator)
            // from 1.7.6, PHP's asort() with the C library locale of the user's language before
            $this->info('autocomplete order', 'entries of other domains start with their "(domain)" marker and Roundcube sorts its suggestions by name '
                . (self::coreSortsWithIcu()
                    ? 'with the ICU collator (1.7.6 and later), where "(" comes before letters and digits: they are listed before unmarked names, and the first suggestion is the pre-selected one'
                    : 'with the C library locale of the user\'s language (before 1.7.6): "(" is usually ignored there, so they are listed by the domain in their marker among the unmarked names (first only in the C locale); the first suggestion is the pre-selected one')
                . ' - galbridge_global_autocomplete = false keeps the global book out of autocomplete');
        }
        $this->info('viewer cache', $cfg->int('galbridge_scope_ttl', 30, 3600) . ' s (galbridge_scope_ttl): a disabled, expired or moved mailbox loses the directory with its next request, and so does a session whose own alias was removed (strict list policy; that request reads nothing) - a new own alias applies from the request after the one that reads it; when the entry is renewed after this time the IMAP server confirms the credentials of the session - a changed password or a disabled IMAP service ends the directory for the rest of that session - and a changed column grant and the role checks apply');
        if (in_array('sql_global_addressbooks', $plugins, true)) {
            $this->check(false, 'old plugin', 'sql_global_addressbooks is still enabled: remove it (docs/MIGRATION.md)', true);
        }
        foreach (['_sql_domainbook_name', '_sql_globalbook_name', '_sql_supportbook_list', '_sql_supportbook'] as $old) {
            if ($cfg->adminCore($old) !== null) {
                $this->check(false, 'old plugin option', "{$old} is still set (docs/MIGRATION.md)", true);
            }
        }
    }

    /**
     * Does a second reading of the configuration files give what Roundcube applies? GAL Bridge reads
     * the options of the core from Roundcube's own object; the second reading serves option names a
     * stored preference shadows, and a derived IMAP binding needs both to agree. Names only, never a
     * value. Also: environment-specific files, which Roundcube uses instead of the plain ones when it
     * runs with an environment name - the web server may, this tool does not.
     */
    private function secondReading(Config $cfg, \rcube $rc): void
    {
        $names = $cfg->divergentOptions();
        if ($names !== []) {
            $this->check(false, 'configuration read again', 'differs for ' . self::names($names)
                . ' - set in a file that the configuration includes with include_once/require_once (it runs once per process), by an environment variable or by another plugin:'
                . (in_array('imap_host', $names, true) ? ' no directory server is derived from imap_host (set galbridge_imap_hosts);' : '')
                . ' a galbridge_* option is read again for a user who has a stored preference of that name - use plain assignments, include or require', true);
        }
        // The comparison above cannot see every case (as root, galbridge.sh reads the configuration
        // once before Roundcube starts, so Roundcube's object of this process misses such values as
        // well): the files themselves are looked at - tokens only, nothing is printed of them.
        $files = [];
        foreach (['config.inc.php', 'main.inc.php', 'db.inc.php'] as $name) {
            foreach (array_filter($rc->config->resolve_paths($name), 'is_file') as $file) {
                $files[$file] = true;
            }
        }
        foreach (Config::pluginFiles($rc->config, $this->pluginDir) as $file) {
            $files[$file] = true;
        }
        $once = [];
        foreach (array_keys($files) as $file) {
            $code = is_readable($file) ? file_get_contents($file) : false;
            foreach (is_string($code) ? token_get_all($code) : [] as $token) {
                if (is_array($token) && in_array($token[0], [T_INCLUDE_ONCE, T_REQUIRE_ONCE], true)) {
                    $once[] = Log::sanitize($file);
                    break;
                }
            }
        }
        if ($once !== []) {
            $this->check(false, 'include_once', self::names($once) . ' pulls in a file with include_once/require_once: every later reading of the configuration in the same PHP process lacks what that file sets - the second reading GAL Bridge makes for an option a stored preference shadows, and diag itself as root (galbridge.sh reads the configuration once before Roundcube starts) - use include or require', true);
        }
        $found = [];
        foreach ($rc->config->resolve_paths('.', false) as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if (preg_match('/^(defaults|config|main|db|galbridge)-[^\/]+\.inc\.php$/D', $entry) === 1) {
                    $found[] = Log::sanitize($dir . '/' . $entry);
                }
            }
        }
        foreach (scandir($this->pluginDir) ?: [] as $entry) {
            if (preg_match('/^config-[^\/]+\.inc\.php$/D', $entry) === 1) {
                $found[] = Log::sanitize($this->pluginDir . '/' . $entry);
            }
        }
        if ($found !== []) {
            $this->check(false, 'environment file', self::names($found) . ': Roundcube uses such a file INSTEAD of the plain one when it runs with that environment name (index.php / $env) - this tool runs without one and judges the plain files; keep the directory policy in the plain files - config/config.inc.php and the plugin\'s configuration file (config/galbridge.inc.php, or plugins/galbridge/config.inc.php if that is the one in use - never both)', true);
        }
    }

    /**
     * include_host_config: Roundcube loads a configuration file chosen by the Host header of the
     * request - by the client. Every file the client can select this way is merged over the
     * configuration of this tool (which has no request, hence no per-host file) and the plugin's
     * files, exactly as Roundcube does it, and the galbridge_* options are compared: a policy option
     * that differs disables every book for such a request (Config::hostDependentOptions()); the
     * book names may differ. The file's imap_host is checked against the directory servers and for a
     * protected transport. As root (--allow-root) a file is loaded only when the root check of
     * bin/galbridge.sh covered it (uncheckedHostFile()); any other is named with the reason (WARN)
     * and not loaded - loading a configuration file runs it.
     */
    private function hostConfig(Config $cfg, \rcube $rc): void
    {
        $include = $cfg->coreValue('include_host_config');
        if (!is_array($include)) {
            $include = $cfg->adminCore('include_host_config');
        }
        $candidates = [];
        if (is_array($include)) {
            // host name => file: only these files can be selected
            foreach ($include as $file) {
                if (is_string($file) && $file !== '') {
                    foreach (array_filter($rc->config->resolve_paths($file), 'is_file') as $path) {
                        $candidates[$path] = true;
                    }
                }
            }
        } else {
            // <host name>.inc.php: the client can name ANY *.inc.php file of a configuration directory
            foreach ($rc->config->resolve_paths('.', false) as $dir) {
                foreach (scandir($dir) ?: [] as $entry) {
                    if (str_ends_with($entry, '.inc.php') && is_file($dir . '/' . $entry)) {
                        $candidates[$dir . '/' . $entry] = true;
                    }
                }
            }
        }
        $this->info('per-host files', count($candidates) . ' file(s) a request can select' . (is_array($include) ? ' (include_host_config lists them)' : ' (include_host_config is not a list: every *.inc.php file of the configuration directories, named by the Host header)'));
        if (count($candidates) > self::TREE_LIMIT) {
            $this->check(false, 'per-host files', 'more than ' . self::TREE_LIMIT . ' files: not checked', true);
            return;
        }
        $plugin = Config::pluginFiles($rc->config, $this->pluginDir);
        $baseline = new \rcube_config();
        Config::loadFiles($baseline, $plugin);
        $allowed = ViewerResolver::allowedImapHosts($cfg) ?? [];
        // an option set through a ROUNDCUBE_<OPTION> variable is the same for every host name
        $fromEnvironment = $cfg->envOverrides();
        // Loading a file runs it. As root (galbridge.sh --allow-root; a user that cannot be
        // determined counts as root, as there) only a file that the root check of galbridge.sh
        // covered: inside the configuration directories it verified and handed to Roundcube - an
        // absolute or "../" name in include_host_config leads anywhere -, owned by root and writable
        // by nobody else (the check of galbridge.sh looks at *.php, *.inc, *.sql and *.sh files only)
        $uid = self::euid();
        $configDirs = $uid === null || $uid === 0 ? array_values($rc->config->resolve_paths('.', false)) : null;
        $clean = 0;
        foreach (array_keys($candidates) as $path) {
            $load = $path;
            if ($configDirs !== null) {
                $why = self::uncheckedHostFile($path, $configDirs);
                if ($why !== null) {
                    $this->check(false, 'per-host configuration', Log::sanitize($path) . ': not loaded as root - ' . $why . ': run diag as the web server user to check it', true);
                    continue;
                }
                $load = (string) realpath($path); // what was checked is what is loaded
            }
            $probe = new \rcube_config();
            $probe->load_from_file($load);
            Config::loadFiles($probe, $plugin);
            $policy = [];
            $labels = [];
            foreach (Config::DEFAULTS as $name => $default) {
                if (in_array($name, $fromEnvironment, true) || $probe->get($name, $default) === $baseline->get($name, $default)) {
                    continue;
                }
                if (in_array($name, Config::HOST_COSMETIC, true)) {
                    $labels[] = $name;
                } else {
                    $policy[] = $name;
                }
            }
            $shown = Log::sanitize(basename($path));
            if ($policy !== []) {
                $this->check(false, 'per-host configuration', $shown . ': sets ' . self::names($policy) . ' - requests with this host name get no books; keep the directory policy in the plugin\'s configuration file (config/galbridge.inc.php, or plugins/galbridge/config.inc.php if that is the one in use - never both)');
            }
            if ($labels !== []) {
                $this->info('per-host configuration', $shown . ': sets ' . self::names($labels) . ' (book names may differ per host)');
            }
            $imapHost = $probe->get('imap_host');
            if ($imapHost !== $baseline->get('imap_host')) {
                $this->imapEntries($cfg, $imapHost, $allowed, $shown);
                $policy[] = 'imap_host';
            }
            $clean += $policy === [] && $labels === [] ? 1 : 0;
        }
        if ($clean > 0) {
            $this->ok('per-host configuration', $clean . ' file(s) change no galbridge_* option and no imap_host');
        }
    }

    /**
     * Why a per-host file must not be loaded (run) by a process of root, or null. The root check of
     * bin/galbridge.sh covered it only when it lies inside one of the configuration directories that
     * check verified and handed to Roundcube ($dirs, canonical) - and, as that check looks at code
     * files only (*.php, *.inc, *.sql, *.sh), when the file itself belongs to root and nobody else
     * can write it.
     *
     * @param list<string> $dirs
     */
    private static function uncheckedHostFile(string $path, array $dirs): ?string
    {
        $real = realpath($path);
        $stat = $real === false ? false : @stat($real);
        if ($real === false || $stat === false) {
            return 'it cannot be checked';
        }
        $inside = false;
        foreach ($dirs as $dir) {
            $inside = $inside || str_starts_with($real, rtrim($dir, '/') . '/');
        }
        if (!$inside) {
            return 'it lies outside the configuration directories that galbridge.sh checked for --allow-root';
        }
        if ($stat['uid'] !== 0 || ($stat['mode'] & 0o022) !== 0) {
            return 'it is not owned by root or is writable by its group or by others (owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) . ')';
        }
        return null;
    }

    /**
     * What lies in the plugin directory, who may change it, and - with --url - whether the web
     * server hands it out (and the configuration file in use).
     */
    private function installation(string $url, ?Config $cfg = null): void
    {
        $entries = array_values(array_diff(scandir($this->pluginDir) ?: [], ['.', '..']));
        $clone = array_values(array_intersect(self::CLONE_FILES, $entries));
        if ($clone !== []) {
            $this->check(false, 'plugin directory', 'a git clone (' . implode(', ', $clone) . '): install the release archive or remove these - tests/ holds scripts that reset databases and Dovecot and has no place on a mail server', true);
        }
        $unexpected = array_values(array_diff($entries, self::RELEASE_FILES, self::CLONE_FILES));
        // and inside the directories of a release, only what a release puts there
        foreach (self::RELEASE_TREE as $dir => $pattern) {
            foreach ($this->tree($this->pluginDir . '/' . $dir) as $rel => $isDir) {
                if (preg_match($pattern, $rel) !== 1) {
                    $unexpected[] = $dir . '/' . $rel . ($isDir ? '/' : '');
                }
            }
        }
        if ($unexpected !== []) {
            // names only; anything an administrator leaves here (a generated grants file, a backup of
            // the configuration) can be downloadable where the web server serves plugin directories
            $this->check(false, 'plugin directory', 'files that are not part of the plugin: ' . self::names($unexpected) . ' - keep nothing else here (README 2.6)', true);
        }
        if ($clone === [] && $unexpected === []) {
            $this->ok('plugin directory', 'only the files of a release');
        }
        $this->composerBackups();
        $this->composerVendorCopy();

        $this->pluginCode();
        if ($this->truncated !== []) {
            // never a claim about files that were not looked at
            $this->check(false, 'plugin directory', 'more than ' . self::TREE_LIMIT . ' entries below ' . self::names(array_keys($this->truncated)) . ': the files beyond were not checked - a release holds about fifty', true);
        }

        if ($url === '') {
            $this->webUnchecked = true;
            if (is_file($this->pluginDir . '/config.inc.php')) {
                // the file with the database password where a web server that serves plugin files
                // (README 2.6) hands it out: never left as a mere note
                $this->check(false, 'web access', 'not checked, and the configuration file with the database password and the directory policy lies in the plugin directory: run diag --url=https://<webmail address>/ (README 2.6) or move it to config/galbridge.inc.php (README 2.3)', true);
            } else {
                $this->info('web access', 'not checked: run diag --url=https://<webmail address>/ to verify that the web server does not serve this directory (README 2.6)');
            }
            return;
        }
        $this->webAccess($url, $cfg);
    }

    /**
     * Who may change the code of the plugin: every directory and every file PHP loads or this tool
     * prints (*.php, *.inc, *.sql, *.sh) below bin/, lib/, SQL/ and localization/, the plugin
     * directory itself, the directories above it and Roundcube's plugins/ directory (where a
     * symbolically linked plugin can be replaced) with the directories above that. Code that the
     * account running this tool - the PHP / web server user - can modify is code a compromised web
     * application can modify, and administrators run it (this tool); a file that account OWNS counts
     * as modifiable whatever its mode says (the owner can change the mode). As root (--allow-root)
     * the rule of bin/galbridge.sh applies: owned by root, writable by nobody else.
     */
    private function pluginCode(): void
    {
        $uid = self::euid();
        if ($uid === null) {
            $this->check(false, 'plugin code', 'not checked: the user of this process cannot be determined (PHP has no posix extension and /proc is not available)', true);
            return;
        }
        $files = [$this->pluginDir, $this->pluginDir . '/galbridge.php'];
        foreach (self::CODE_DIRS as $dir) {
            $files[] = $this->pluginDir . '/' . $dir;
            foreach ($this->tree($this->pluginDir . '/' . $dir) as $rel => $isDir) {
                if ($isDir || preg_match('/\.(php|inc|sql|sh)$/D', $rel) === 1) {
                    $files[] = $this->pluginDir . '/' . $dir . '/' . $rel;
                }
            }
        }
        // the directories above the plugin directory, and Roundcube's plugins/ directory with the
        // directories above it
        $above = [];
        $install = defined('RCUBE_INSTALL_PATH') ? rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') : '';
        $real = realpath($this->pluginDir);
        $plugins = $install !== '' ? realpath($install . '/plugins') : false;
        foreach ([$real === false ? null : dirname($real), $plugins === false ? null : $plugins] as $dir) {
            for (; $dir !== null; $dir = $dir === dirname($dir) ? null : dirname($dir)) {
                $above[$dir] = true;
            }
        }
        $problems = [];
        foreach ($files as $path) {
            $stat = file_exists($path) ? @stat($path) : false;
            if ($stat === false) {
                continue;
            }
            $why = $uid === 0
                ? ($stat['uid'] !== 0 || ($stat['mode'] & 0o022) !== 0 ? 'owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) : null)
                : ($stat['uid'] === $uid ? 'owned by this user' : (is_writable($path) ? 'writable by this user' : null));
            if ($why !== null) {
                $problems[] = $this->relative($path) . ' (' . $why . ')';
            }
        }
        foreach (array_keys($above) as $dir) {
            // a directory with the sticky bit (/tmp) lets others add entries, not replace these
            $stat = @stat($dir);
            if ($stat === false) {
                continue;
            }
            $open = ($stat['mode'] & 0o1000) === 0;
            $why = $uid === 0
                ? ($stat['uid'] !== 0 || ($open && ($stat['mode'] & 0o022) !== 0) ? 'owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) : null)
                : ($stat['uid'] === $uid ? 'owned by this user' : ($open && is_writable($dir) ? 'writable by this user' : null));
            if ($why !== null) {
                $problems[] = $dir . ' (' . $why . ')';
            }
        }
        if ($problems === []) {
            $this->ok('plugin code', $uid === 0 ? 'owned by root and writable by nobody else, with the directories above it' : 'neither owned nor writable by this user (uid ' . $uid . '), nor are the directories above it');
            return;
        }
        $this->check(false, 'plugin code', ($uid === 0 ? 'not root\'s alone: ' : 'this user (uid ' . $uid . ', the PHP user) can change it: ') . self::names($problems)
            . ' - make root the owner and let nobody else write it (grants and --allow-root refuse to run from such a tree); the web server needs read access only', true);
    }

    /**
     * Every directory and file below a directory, by its path relative to it (true = a directory);
     * symbolic links are followed, a directory is visited once.
     *
     * @param array<string, true> $seen  directories visited (real paths)
     * @param int                 $count entries listed so far (at most TREE_LIMIT)
     *
     * @return array<string, bool>
     */
    private function tree(string $dir, string $prefix = '', array &$seen = [], int &$count = 0): array
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real) || isset($seen[$real])) {
            return [];
        }
        $seen[$real] = true;
        $out = [];
        foreach (scandir($real) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (++$count > self::TREE_LIMIT) {
                // a link to / or a huge tree must not stall the tool: what is left is reported
                $this->truncated[$this->relative($dir)] = true;
                break;
            }
            $rel = $prefix . $entry;
            $isDir = is_dir($real . '/' . $entry);
            $out[$rel] = $isDir;
            if ($isDir) {
                $out += $this->tree($real . '/' . $entry, $rel . '/', $seen, $count);
            }
        }
        return $out;
    }

    /** a path below the plugin directory relative to it ("." for the directory), any other as it is */
    private function relative(string $path): string
    {
        return $path === $this->pluginDir ? '.' : (str_starts_with($path, $this->pluginDir . '/') ? substr($path, strlen($this->pluginDir) + 1) : $path);
    }

    /**
     * At most eight sanitised names and the number of the others.
     *
     * @param list<string> $names
     */
    private static function names(array $names): string
    {
        $shown = array_map(static fn (string $n): string => Log::sanitize($n), array_slice($names, 0, 8));
        return implode(', ', $shown) . (count($names) > 8 ? ', ... (' . (count($names) - 8) . ' more)' : '');
    }

    /**
     * The effective user id of this process, or null when it cannot be determined: the posix
     * extension is a package of its own on several platforms and no requirement of the plugin; on
     * Linux the kernel tells as well. (The same rule as in bin/galbridge.sh, which has to decide
     * before it loads any class.)
     */
    public static function euid(): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
        // "Uid:" real, effective, saved, filesystem
        if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)\s/m', $status, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Ask the web server for files of the plugin directory (GET, nothing is sent): 200 = served.
     * Then for the configuration file in use (configOverHttp()).
     */
    private function webAccess(string $url, ?Config $cfg): void
    {
        if (preg_match('~^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?(/[A-Za-z0-9._\~/-]*)?$~D', $url) !== 1) {
            $this->check(false, 'web access', '--url must be the http(s) address of the webmail, e.g. https://mail.example.com/mail/');
            return;
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $this->check(false, 'web access', 'not checked (allow_url_fopen is off): use the curl commands of README 2.6', true);
            return;
        }
        $base = rtrim($url, '/') . '/';
        // A control first: "not served" says something only when the address IS this webmail - a
        // wrong prefix (iRedMail installs with and without /mail/) or another virtual host answers
        // 404 for everything. Every page of Roundcube creates its JavaScript object.
        [$status, $error, $body, $tls] = self::httpStatus($base, 131072, $this->cafile);
        $conclusive = $status === 200 && str_contains($body, 'new rcube_webmail()');
        if ($conclusive) {
            $this->ok('web access', $base . ' is the webmail');
            if ($this->cafile !== null) {
                $this->info('web access', 'the certificate of the web server was verified against --cafile=' . Log::sanitize($this->cafile));
            }
        } else {
            $this->check(false, 'web access', $base . ' does not answer like the Roundcube webmail (' . ($status === null ? 'no answer: ' . $error : 'HTTP ' . $status)
                . '): check the address - for another address the lines below say nothing about this installation', true);
        }
        if ($tls) {
            // said once, not with every path below: the requests fail before anything is asked
            $this->check(false, 'web access', $this->cafile === null
                ? 'TLS certificate not trusted by this PHP: for a self-signed certificate - iRedMail installs one, /etc/ssl/certs/iRedMail.crt, until a trusted certificate replaces it - run diag with --cafile=<that certificate>; a name mismatch means that --url does not use the host name of the certificate (README 2.6)'
                : 'TLS certificate not verified against --cafile=' . Log::sanitize($this->cafile) . ': the web server presents a certificate that the file does not hold or sign, or one issued for another host name than that of --url (README 2.6)', true);
        }
        // The web path is fixed by the NAME of the plugin (Roundcube loads plugins/<name>/<name>.php),
        // whatever the directory is called on disk: the target of a symlink or a checkout may have
        // another name, and asking for that name would report "not served" for files that are.
        $dir = self::PLUGIN_NAME;
        foreach (["plugins/{$dir}/composer.json", "static.php/plugins/{$dir}/composer.json", "plugins/{$dir}/SQL/grants.mysql.sql", "static.php/plugins/{$dir}/lib/autoload.php"] as $path) {
            [$status, $error] = self::httpStatus($base . $path, 0, $this->cafile);
            if ($status === null) {
                $this->check(false, 'web access', $path . ': no answer (' . $error . ')', true);
            } elseif ($status >= 200 && $status < 300) {
                $this->check(false, 'web access', $path . ' is SERVED (HTTP ' . $status . '): deny the plugin directory in the web server (README 2.6)');
            } elseif (in_array($status, [401, 403, 404, 410], true)) {
                // "not served" by an address that is not this webmail proves nothing (see above)
                $conclusive
                    ? $this->ok('web access', $path . ' is not served (HTTP ' . $status . ')')
                    : $this->check(false, 'web access', $path . ': HTTP ' . $status . ' - inconclusive, the address did not answer like the webmail', true);
            } else {
                $this->check(false, 'web access', $path . ': HTTP ' . $status . ' - check by hand (README 2.6)', true);
            }
        }
        $this->configOverHttp($base, $conclusive, $cfg);
    }

    /**
     * Ask the web server for the configuration file(s) in use - the database password - under the
     * web paths that reach them: plugins/galbridge/config.inc.php, or config/galbridge.inc.php of the
     * Roundcube directory, each also through static.php (which a web server may map straight onto
     * the directory, README 2.6). A file elsewhere has no web path of the webmail. A served file is
     * recognised by its text ("$config[" and "galbridge_"); nothing of an answer is ever printed.
     */
    private function configOverHttp(string $base, bool $conclusive, ?Config $cfg): void
    {
        if ($cfg === null) {
            if ($this->rc === null) {
                return;
            }
            $cfg = new Config($this->rc->config, [], null, $this->pluginDir);
        }
        $state = $cfg->configFiles();
        $install = defined('RCUBE_INSTALL_PATH') ? realpath(rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') . '/config') : false;
        $local = realpath($this->pluginDir . '/config.inc.php');
        foreach (array_values(array_unique([...$state['read'], ...$state['ignored']])) as $file) {
            $real = realpath($file);
            $paths = match (true) {
                $real === false => [],
                $real === $local => ['plugins/' . self::PLUGIN_NAME . '/config.inc.php', 'static.php/plugins/' . self::PLUGIN_NAME . '/config.inc.php'],
                $install !== false && dirname($real) === $install => ['config/' . basename($real), 'static.php/config/' . basename($real)],
                default => [],
            };
            if ($paths === []) {
                $this->info('config over HTTP', Log::sanitize($file) . ' lies outside the plugin and the config directory of Roundcube: no address of the webmail leads to it - keep it out of every document root');
                continue;
            }
            foreach ($paths as $path) {
                [$status, $error, $body] = self::httpStatus($base . $path, 65536, $this->cafile);
                if ($status === null) {
                    $this->check(false, 'config over HTTP', $path . ': no answer (' . $error . ')', true);
                } elseif ($status >= 200 && $status < 300) {
                    // the source text, or something else (PHP ran it - no output - or another page)
                    str_contains($body, '$config[') && str_contains($body, 'galbridge_')
                        ? $this->check(false, 'config over HTTP', $path . ' is SERVED as text: the database password can be downloaded - deny it in the web server (README 2.6), then change the password of the role (galbridge.sh grants --reset-password)')
                        : $this->info('config over HTTP', $path . ' answered HTTP ' . $status . ' without the text of the file (executed by PHP, or another page)');
                } elseif (in_array($status, [401, 403, 404, 410], true)) {
                    $conclusive
                        ? $this->ok('config over HTTP', $path . ' is not served (HTTP ' . $status . ')')
                        : $this->check(false, 'config over HTTP', $path . ': HTTP ' . $status . ' - inconclusive, the address did not answer like the webmail', true);
                } else {
                    $this->check(false, 'config over HTTP', $path . ': HTTP ' . $status . ' - check by hand (README 2.6)', true);
                }
            }
        }
    }

    /**
     * @param int $bytes how much of the body to read (0 = none)
     * @param null|string $cafile PEM certificate(s) to verify an https:// server against (diag --cafile)
     *
     * @return array{0: null|int, 1: string, 2: string, 3: bool} HTTP status (null = no answer), the reason,
     *                                                            the start of the body, whether the answer
     *                                                            failed because the server certificate was not verified
     */
    private static function httpStatus(string $url, int $bytes = 0, ?string $cafile = null): array
    {
        // certificate verification is never switched off; --cafile only names whom to trust
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($cafile !== null) {
            $ssl['cafile'] = $cafile;
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'user_agent' => 'galbridge-diag'],
            'ssl' => $ssl,
        ]);
        // PHP reports a failed TLS handshake in several warnings - "SSL operation failed ... certificate
        // verify failed", "Failed to enable crypto", "Failed to open stream: operation failed" -, of which
        // the last says nothing: keep them all
        $errors = [];
        set_error_handler(static function (int $no, string $message) use (&$errors): bool {
            $errors[] = $message;
            return true;
        });
        try {
            $stream = fopen($url, 'r', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            foreach ($errors as $message) {
                if (preg_match('~certificate verify failed|Peer certificate CN=.*did not match expected CN=.*~', $message, $m) === 1) {
                    return [null, 'TLS: the certificate of the web server was not verified (' . Log::sanitize($m[0]) . ')', '', true];
                }
            }
            return [null, Log::sanitize((string) preg_replace('~^.*?failed to open stream: ~i', '', (string) end($errors))), '', false];
        }
        $meta = stream_get_meta_data($stream);
        $body = $bytes > 0 ? (string) stream_get_contents($stream, $bytes) : '';
        fclose($stream);
        foreach ((array) ($meta['wrapper_data'] ?? []) as $header) {
            if (is_string($header) && preg_match('~^HTTP/\S+ (\d{3})~', $header, $m) === 1) {
                return [(int) $m[1], '', $body, false];
            }
        }
        return [null, 'no HTTP status line', '', false];
    }

    private function imapHosts(Config $cfg, \rcube $rc): void
    {
        // what Roundcube applies (a second reading that differs is reported under Configuration)
        $imapHost = $cfg->coreValue('imap_host');
        $this->info('imap_host', is_string($imapHost) ? Log::sanitize($imapHost) : (is_array($imapHost) ? 'array (' . count($imapHost) . ' entries)' : 'not set'));
        if ($cfg->hostConfigEnabled()) {
            // this tool has no request: the section "Per-host configuration" merges each selectable file
            $this->check(false, 'include_host_config', 'enabled (or ROUNDCUBE_INCLUDE_HOST_CONFIG is set): Roundcube merges a configuration file chosen by the Host header of the request - by the client - so imap_host and every option can differ per host name; see "Per-host configuration" below: a galbridge_* option (except the book names) that a per-host file sets disables every book for that host name, and every per-host imap_host must name an IMAP server of this vmail database', true);
        }
        $allowed = ViewerResolver::allowedImapHosts($cfg);
        if ($allowed === null) {
            $this->check(false, 'directory hosts', 'plugin disabled: ' . (ViewerResolver::bindingProblem($cfg) ?? 'no usable imap_host') . ' - set galbridge_imap_hosts to the IMAP servers of this vmail database (host, host:port or unix:///path)');
            return;
        }
        $explicit = $cfg->imapHosts() !== [];
        $this->ok('directory hosts', implode(', ', $allowed) . ($explicit ? ' (galbridge_imap_hosts)' : ' (derived from imap_host)'));
        if ($explicit) {
            foreach ($allowed as $entry) {
                // an entry without a port covers every port of its host (ViewerResolver::covers()) -
                // also another IMAP service there with a user database of its own; a derived binding
                // always has the port, a socket has none to give
                if (!str_starts_with($entry, 'unix://') && ViewerResolver::splitEntry($entry)[1] === null) {
                    $this->check(false, 'directory hosts', Log::sanitize($entry) . ' has no port: it covers every IMAP port of that host - write host:port ([IPv6]:port) unless every IMAP service on it authenticates against this vmail database', true);
                }
            }
        }
        if ($explicit && (count($allowed) > 1 || !is_string($imapHost) || trim($imapHost) === '' || str_contains($imapHost, '%'))) {
            // nothing in the plugin can verify this assumption
            $this->check(false, 'directory hosts', 'several or user-chosen IMAP servers: EVERY listed server must authenticate against this vmail database - a login from another user database with the same name would get that mailbox\'s directory', true);
        }

        // the hosts Roundcube will really put into sessions must be covered, over a protected link
        $this->imapEntries($cfg, $imapHost, $allowed);
        try {
            $db = $rc->get_dbh();
            $res = $db->query('SELECT mail_host, COUNT(*) AS n FROM ' . $db->table_name('users', true) . ' GROUP BY mail_host');
            if ($res === false || $db->is_error() !== null) {
                // Roundcube wrote the reason to standard error (neutralised by bin/galbridge.sh)
                throw new \RuntimeException('the query on the users table failed');
            }
            $odd = 0;
            $accounts = 0;
            $uncovered = false;
            while ($res && ($row = $db->fetch_assoc($res))) {
                // judged exactly as at runtime (the raw value, never parsed again); a value that is
                // not canonical is counted, never printed - it may carry anything a login form took
                $raw = (string) ($row['mail_host'] ?? '');
                $n = ViewerResolver::canonicalHost($raw, true);
                if ($n === null) {
                    $odd += $raw === '' ? 0 : (int) ($row['n'] ?? 0);
                } elseif (!ViewerResolver::covers($allowed, $n)) {
                    $uncovered = true;
                    $this->check(false, 'users on ' . $n, (string) ($row['n'] ?? '?') . ' Roundcube users on this IMAP host get no directory', true);
                } else {
                    $accounts += (int) ($row['n'] ?? 0);
                }
            }
            if ($odd > 0) {
                $this->check(false, 'users on other hosts', $odd . ' Roundcube users have a mail_host that is not a plain host name, IP address or socket (not shown): they get no directory - a login that typed such a server was authenticated by whatever that text named', true);
            } elseif (!$uncovered) {
                // a check that ran is never silent (README 2.7): say how many stored hosts it judged
                $this->check(true, 'stored IMAP hosts', $accounts . ' account(s) with a stored IMAP host, all covered by the directory hosts');
            }
        } catch (\Throwable) {
            // a check that could not run is no OK: the stored hosts may well leave users without a
            // directory
            $this->check(false, 'Roundcube users', 'not checked: the Roundcube database is not reachable from this process - the IMAP hosts stored for existing users (users.mail_host) are unknown', true);
        }
    }

    /**
     * One line per server of an imap_host value (a string, or the keys/values of a list): is it a
     * directory server (covered by $allowed), and is the link to it protected
     * (ViewerResolver::transportProblem(), judged with the scheme of the entry - as rcmail::login()
     * derives storage_ssl from it - and imap_conn_options)? $source names a per-host file.
     *
     * @param list<string> $allowed
     */
    private function imapEntries(Config $cfg, mixed $imapHost, array $allowed, string $source = ''): void
    {
        $configured = [];
        if (is_string($imapHost) && trim($imapHost) !== '' && !str_contains($imapHost, '%')) {
            $configured[] = $imapHost;
        } elseif (is_array($imapHost)) {
            foreach ($imapHost as $k => $v) {
                $configured[] = is_string($k) ? $k : (is_scalar($v) ? (string) $v : '');
            }
        }
        $from = $source === '' ? '' : $source . ': ';
        if ($configured === []) {
            $this->info('IMAP transport', $from . 'imap_host is empty or a template, so users choose the server and its scheme: a session on a server that is not local gets the directory only over TLS that verifies the server (ssl://, or tls:// on Roundcube 1.7.7 or later)');
            return;
        }
        $optOut = $cfg->bool('galbridge_imap_allow_plaintext');
        foreach ($configured as $h) {
            $n = ViewerResolver::normaliseHost($h);
            if ($n === '' || str_contains($n, '%')) {
                continue;
            }
            $shown = Log::sanitize($n);
            $parsed = \rcube_utils::parse_host_uri(trim($h), 143, 993);
            $covered = ViewerResolver::covers($allowed, $n, (int) $parsed[2]);
            $this->check($covered, 'imap_host ' . $shown, $from . ($covered ? 'covered' : 'not in galbridge_imap_hosts: logins on it get no directory'), count($configured) > 1 || $source !== '');
            // the scheme exactly as rcmail::login() reads it (case-sensitive: "SSL://" is no TLS there)
            $scheme = is_string($parsed[1] ?? null) ? $parsed[1] : '';
            $ssl = in_array($scheme, ['ssl', 'imaps', 'tls'], true) ? $scheme : '';
            $raw = str_starts_with($n, 'unix://') ? $n : \rcube_utils::idn_to_ascii(trim((string) $parsed[0]));
            $problem = ViewerResolver::transportProblem($n, $raw, $ssl, $cfg->coreValue('imap_conn_options'), ViewerResolver::coreVersion(), extension_loaded('openssl'));
            if ($problem === null) {
                $this->ok('IMAP transport ' . $shown, $from . (ViewerResolver::isLocalEndpoint($n) ? 'local server (no network in between)' : 'TLS that verifies the server'));
                continue;
            }
            $this->check(false, 'IMAP transport ' . $shown, $from . $problem . ($optOut
                ? ' - accepted because galbridge_imap_allow_plaintext is set: only for a link nobody else can reach'
                : ' - sessions on this server get no directory (galbridge_imap_allow_plaintext accepts it for a trusted private link)'), $optOut);
        }
    }

    private function coreInterplay(Config $cfg, \rcube $rc): void
    {
        $sortCol = (string) $rc->config->get('addressbook_sort_col', 'surname');
        $this->check($sortCol === 'name', 'addressbook_sort_col', $sortCol . ($sortCol === 'name' ? '' : " (recommended 'name': open-source iRedAdmin fills only mailbox.name; core re-sorts search results by this column)"), true);
        if ($cfg->bool('galbridge_global_book') && $cfg->bool('galbridge_mark_foreign_domains')) {
            // the "(domain)" marker is part of the NAME of a foreign entry: nothing of that entry may
            // stand before it in the name core composes (RecordMapper::textBeforeName())
            $withheld = RecordMapper::textBeforeName($rc->config->get('contactlist_name_template', '{name}'));
            if ($withheld !== []) {
                $this->check(false, 'contactlist_name_template', 'shows ' . implode(', ', $withheld) . ' before {name} (or has no {name}): entries of other domains are listed WITHOUT these fields, because their "(domain)" marker is part of the name and has to come first - start the template with {name} to show them', true);
            }
        }
        $ldap = (array) $rc->config->get('ldap_public', []);
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            if (array_key_exists($id, $ldap)) {
                $this->check(false, 'source id collision', "ldap_public['{$id}'] shadows the GAL Bridge book of the same id");
            }
        }
        $limits = Limits::fromConfig($cfg);
        $this->info('group expansion cap', $limits->groupCap . ' members (min of galbridge_max_group_members and max_group_members)');
        $this->info('autocomplete minimum', $cfg->int('galbridge_min_search_length', 1, 10) . ' characters (raised in compose when the core value is lower)');
        foreach (['galbridge_max_export' => $limits->maxExport, 'group expansion cap' => $limits->groupCap] as $what => $cap) {
            if ($cap > self::COSTLY_FULL_SET) {
                $this->check(false, $what, $cap . ': one request reads, maps and holds up to ' . $cap . ' entries (' . ($what === 'galbridge_max_export' ? 'whole-book export' : 'group expansion')
                    . ') - keep it at or below ' . self::COSTLY_FULL_SET . ', or give the PHP of the web server the memory_limit and the time for it', true);
            }
        }
        $this->pageSizes($cfg, $limits);
        if ($cfg->bool('galbridge_global_book') && $cfg->choice('galbridge_global_groups', Config::GROUPINGS) === 'department') {
            // per domain since the second verification pass, but counted against one bound
            $this->check(false, 'galbridge_global_groups', "'department': the number of department groups of the global book depends on the data of every tenant in it - above galbridge_max_groups ("
                . $limits->maxGroups . ') the global book shows no groups at all (fail closed); prefer \'domain\'', true);
        }
    }

    /**
     * Roundcube's page sizes beyond the plugin's page bound (Limits::HARD_PAGE_CAP): such a page is
     * served as a page of at most the bound with a notice (never as an export), and a page size of
     * 9999 or 99999 is what core uses for its full sets.
     */
    private function pageSizes(Config $cfg, Limits $limits): void
    {
        $sizes = [];
        $max = $cfg->get('max_pagesize');
        if (!is_numeric($max) || (int) $max <= 0) {
            $sizes[] = 'max_pagesize is ' . (is_scalar($max) ? Log::sanitize((string) $max) : get_debug_type($max)) . ' (no bound)';
        } elseif ((int) $max > Limits::HARD_PAGE_CAP) {
            $sizes[] = 'max_pagesize is ' . (int) $max;
        }
        foreach (['addressbook_pagesize', 'pagesize'] as $name) {
            $value = $cfg->get($name);
            if (is_numeric($value) && (int) $value > Limits::HARD_PAGE_CAP) {
                $sizes[] = $name . ' is ' . (int) $value;
            }
        }
        if ($sizes !== []) {
            $this->check(false, 'page size', implode(', ', $sizes) . ': the directory serves at most ' . $limits->corePageCap . ' entries per page (users see "The directory shows at most '
                . $limits->corePageCap . ' entries per page"); keep max_pagesize and the page sizes at or below ' . Limits::HARD_PAGE_CAP, true);
        }
    }

    private function connect(Config $cfg): ?Connection
    {
        $roundcubeDsn = $cfg->coreValue('db_dsnw', '');
        try {
            $dsn = new Dsn($cfg->string('galbridge_db_dsn'), $cfg, is_string($roundcubeDsn) ? $roundcubeDsn : null, $cfg->passwordPluginDsn());
        } catch (GalBridgeException $e) {
            $this->check(false, 'galbridge_db_dsn', $e->getMessage());
            return null;
        }
        $this->ok('DSN', Log::sanitize($dsn->describe()));
        foreach ($dsn->warnings() as $w) {
            $this->check(false, 'DSN', $w, true);
        }
        $this->socketDirectory($dsn);
        if ($dsn->driver() !== 'sqlite') {
            $ext = $dsn->driver() === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';
            $this->check(extension_loaded($ext), 'PHP extension', $ext . (extension_loaded($ext) ? '' : ' is missing'));
        }
        if ($dsn->driver() === 'mysql' && $dsn->remote() && !extension_loaded('mysqlnd')) {
            // verified here for mysqlnd only: it checks the certificate chain and the host name
            $this->check(false, 'client library', 'pdo_mysql is not built on mysqlnd: make sure that verify_server_cert=1 also checks the host name of the server certificate with this client library', true);
        }
        $this->check(extension_loaded('intl'), 'PHP extension', 'intl' . (extension_loaded('intl') ? '' : ' is missing (IDN domains and Unicode normalisation)'), true);

        $conn = new Connection($dsn, $cfg->int('galbridge_db_timeout', 1, 30));
        $this->readTimeout($dsn, $conn);
        $started = microtime(true);
        try {
            $flavour = $conn->flavour();
        } catch (GalBridgeException) {
            // the driver's text, an error the driver raised while connecting, or the session
            // setting (statement timeout / read-only mode) the server refused - the plugin does not
            // use such a connection either
            $reason = $conn->lastError();
            $this->check(false, 'connect', 'cannot connect' . ($reason !== '' ? ': ' . $reason : ''));
            return null;
        }
        $this->ok('connect', $flavour . ' (' . round((microtime(true) - $started) * 1000) . ' ms)');
        return $conn;
    }

    /**
     * The unix socket the driver connects to (also MySQL "localhost": pdo_mysql.default_socket) lies
     * in a directory that another local account can write: while the database server is down, that
     * account could put its own socket there and receive the role's credentials.
     */
    private function socketDirectory(Dsn $dsn): void
    {
        $socket = $dsn->socketPath();
        if ($socket === null) {
            return;
        }
        $dir = Log::sanitize(dirname($socket));
        $stat = @stat(dirname($socket));
        if ($stat === false) {
            $this->check(false, 'socket directory', $dir . ': not checked (it cannot be read by this process)', true);
            return;
        }
        $open = ((int) $stat['mode'] & 0o002) !== 0;
        $this->check(!$open, 'socket directory', $open
            ? $dir . ' is writable by every local account: while the database server is not running, another account can create the socket there and receive the password of the role - use the server\'s own socket directory'
            : $dir . ' (not writable by other accounts)', true);
    }

    /**
     * The read timeout of the plugin's connection (Connection::readTimeout()): on MariaDB/MySQL the
     * plugin lowers mysqlnd.net_read_timeout while it connects; libpq has none after connecting.
     */
    private function readTimeout(Dsn $dsn, Connection $conn): void
    {
        if ($dsn->driver() === 'pgsql') {
            $this->info('read timeout', 'none after connecting (libpq has none): a server that stops answering holds the PHP worker until the request timeout of PHP-FPM (request_terminate_timeout, SECURITY.md)');
            return;
        }
        if ($dsn->driver() !== 'mysql') {
            return;
        }
        $want = $conn->readTimeout();
        $current = ini_get('mysqlnd.net_read_timeout');
        if (!extension_loaded('mysqlnd') || $current === false) {
            $this->check(false, 'read timeout', 'none: pdo_mysql is not built on mysqlnd - a server that stops answering holds the PHP worker until the request timeout of PHP-FPM (request_terminate_timeout, SECURITY.md)', true);
        } elseif ((int) $current > 0 && (int) $current <= $want) {
            $this->ok('read timeout', (int) $current . ' s (mysqlnd.net_read_timeout of this PHP is not above the plugin\'s ' . $want . ' s and is kept)');
        } elseif (!function_exists('ini_set')) {
            $this->check(false, 'read timeout', 'ini_set is disabled in this PHP: the plugin cannot lower mysqlnd.net_read_timeout (' . (int) $current . ' s) to ' . $want . ' s - set it in the php.ini of the web server', true);
        } else {
            $this->ok('read timeout', $want . ' s for the plugin\'s MariaDB/MySQL connection (galbridge_db_timeout or galbridge_db_connect_timeout, whichever is longer, + ' . Connection::READ_MARGIN . ' s; mysqlnd.net_read_timeout of this PHP: ' . (int) $current . ' s)');
        }
    }

    /**
     * The configuration files Roundcube really loads, who can change them, and through which
     * directories: the plugin's own file - plugins/galbridge/config.inc.php when it exists (then
     * nothing else is loaded for the plugin), otherwise galbridge.inc.php from EVERY configuration
     * directory (rcube_plugin::load_config() loads it from each of them, RCUBE_CONFIG_PATH included)
     * - and the core files defaults.inc.php and config.inc.php, which can set galbridge_* options,
     * des_key and the list of plugins as well. A galbridge.inc.php that Roundcube ignores is checked
     * too (marked "NOT read"), and a Composer installation with its configuration inside the plugin
     * directory gets a WARN (the installer re-creates that file). A file is only as safe as its
     * directory and every directory above it: whoever can write one of them can replace the file.
     */
    private function configFile(\rcube $rc): void
    {
        // the files Roundcube loads, and the galbridge.inc.php files it does NOT load next to
        // plugins/galbridge/config.inc.php (Config reports them - every book is disabled - but they
        // may hold the DSN all the same)
        $state = (new Config($rc->config, [], null, $this->pluginDir))->configFiles();
        $files = $state['read'];
        $ignored = $state['ignored'];
        $local = $this->pluginDir . '/config.inc.php';
        $install = defined('RCUBE_INSTALL_PATH') ? rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') : '';
        $composer = self::composerManaged(dirname($this->pluginDir, 2)) || ($install !== '' && self::composerManaged($install));
        // Roundcube's plugin installer re-creates this file at every install and update
        $insidePlugin = $composer && in_array(realpath($local), array_map('realpath', $files), true);
        $composerHint = ' - Composer re-creates plugins/galbridge/config.inc.php at every install and update with the owner and umask of the user running it: move the configuration to config/galbridge.inc.php (README 2.1)';
        $self = self::euid();
        $dirs = [];
        foreach ([...$files, ...$ignored] as $file) {
            $perms = fileperms($file) & 0o777;
            $mode = sprintf('%04o', $perms);
            $isIgnored = in_array($file, $ignored, true);
            $this->check(($perms & 0o007) === 0, 'config file', Log::sanitize($file) . ($isIgnored ? ', NOT read by Roundcube' : '') . ' (mode ' . $mode . ($perms & 0o007 ? ': readable by everyone - use 0640 root:<web server group>' . ($insidePlugin && !$isIgnored ? $composerHint : '') : '') . ')');
            if ($perms & 0o020) {
                $this->check(false, 'config file', 'writable by its group (mode ' . $mode . '): the web server could change the directory policy - use 0640', true);
            }
            $owner = fileowner($file);
            if ($owner !== false && $owner !== 0) {
                // the PHP user as the owner can always rewrite the file, whatever its mode says
                $this->check(false, 'config file', 'owned by uid ' . $owner . ($owner === $self ? ' (the user running this tool, i.e. the PHP user: the web server process can change the directory policy)' : '') . ' - expected root, with the PHP group reading it (0640 root:<web server group>)', true);
            }
            $this->configDirectories($file, $dirs);
        }
        if ($insidePlugin) {
            $this->check(false, 'config file', 'the plugin is installed by Composer and its configuration is plugins/galbridge/config.inc.php: Composer re-creates that file at every install and update with the owner and umask of the user running Composer, and keeps a full copy of the plugin directory next to it while it runs - move the configuration to config/galbridge.inc.php (owner root, group of the PHP user, mode 0640) and delete plugins/galbridge/config.inc.php (README 2.1)', true);
        }
        if ($files === []) {
            $this->check(false, 'config file', 'neither plugins/galbridge/config.inc.php nor config/galbridge.inc.php exists');
        }
        foreach (['defaults.inc.php', 'config.inc.php'] as $name) {
            foreach (array_filter($rc->config->resolve_paths($name), 'is_file') as $file) {
                $stat = @stat($file);
                if ($stat === false) {
                    continue;
                }
                if ($stat['uid'] !== 0) {
                    $this->check(false, 'core config file', Log::sanitize($file) . ' is owned by uid ' . $stat['uid'] . ($stat['uid'] === $self ? ' (the user running this tool, i.e. the PHP user)' : '') . ': that account can change every option - galbridge_* options, des_key, plugins - expected root: chown root:<PHP group> <file> && chmod 0640 <file> (iRedMail installs config.inc.php as the PHP user with mode 0600, README 2.4)', true);
                }
                if (($stat['mode'] & 0o022) !== 0) {
                    $this->check(false, 'core config file', Log::sanitize($file) . ' is writable by group/others (mode ' . sprintf('%04o', $stat['mode'] & 0o7777) . ')', true);
                }
                $this->configDirectories($file, $dirs);
            }
        }
    }

    /**
     * 1.0.5: the connection limit of the role is a share of the server's connections, which the mail
     * services and Roundcube itself need as well - iRedMail keeps MariaDB at max_connections = 151 and
     * PostgreSQL at 100 on Debian and Ubuntu. A limit above the default 25 that takes more than a
     * quarter of the connections the server has for ordinary roles is a WARN; null when the limit is
     * fine or the server's value is unknown.
     */
    public static function connectionShareProblem(int $limit, ?int $available): ?string
    {
        if ($available === null || $available <= 0 || $limit <= 25 || $limit * 4 <= $available) {
            return null;
        }
        return $limit . ' of the ' . $available . ' connections the server has for ordinary roles (max_connections): under load the directory could take the connections that the mail services and Roundcube itself need - lower it (galbridge.sh grants --grants-only --connection-limit=25) or raise max_connections first (README 2.2)';
    }

    /**
     * Does the Composer installation at $root (its vendor/composer/installed.json: a list in Composer
     * 1, {"packages": [...]} in Composer 2) contain this plugin's package? Then Roundcube's plugin
     * installer manages plugins/galbridge.
     */
    public static function composerManaged(string $root): bool
    {
        $file = rtrim($root, '/') . '/vendor/composer/installed.json';
        $json = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        $packages = is_array($data) ? (array_is_list($data) ? $data : ($data['packages'] ?? null)) : null;
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && ($package['name'] ?? null) === self::PACKAGE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Directories that Roundcube's plugin installer leaves next to the plugin directory: during
     * "composer update" it copies the whole plugin directory - the configuration with the database
     * password included - to "<plugin dir>-<20 digits>" and removes the copy only after a successful
     * update. Such a copy lies outside the directory the web server rule denies (README 2.6). Names
     * only; nothing in them is read.
     */
    private function composerBackups(): void
    {
        $parents = [dirname($this->pluginDir) => true];
        $install = defined('RCUBE_INSTALL_PATH') ? rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') : '';
        if ($install !== '' && is_dir($install . '/plugins')) {
            $parents[$install . '/plugins'] = true;
        }
        $names = array_unique([basename($this->pluginDir), self::PLUGIN_NAME]);
        $pattern = '/^(' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names)) . ')-[0-9]{20}$/D';
        $found = [];
        foreach (array_keys($parents) as $parent) {
            foreach (scandir($parent) ?: [] as $entry) {
                if (preg_match($pattern, $entry) === 1 && is_dir($parent . '/' . $entry)) {
                    $found[] = $parent . '/' . $entry;
                }
            }
        }
        foreach (array_unique($found) as $dir) {
            $this->check(false, 'composer backup', Log::sanitize($dir) . ' was left by an interrupted Composer update: it holds a copy of the plugin configuration (the database password) outside the directory your web server rule denies - delete it');
        }
    }

    /**
     * A second copy of the package below vendor/: Composer installs it there when it runs as root
     * without a terminal (it disables its plugins, Roundcube's plugin installer among them) - and
     * then plugins/galbridge was not touched by that run, so an "update" did not update the plugin.
     * Roundcube's Composer autoloader may map GalBridge\ to that copy; lib/autoload.php is
     * registered first, so the plugin still runs its own classes. Names only.
     */
    private function composerVendorCopy(): void
    {
        $roots = [dirname($this->pluginDir, 2) => true];
        $install = defined('RCUBE_INSTALL_PATH') ? rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') : '';
        if ($install !== '') {
            $roots[$install] = true;
        }
        $found = [];
        foreach (array_keys($roots) as $root) {
            $copy = $root . '/vendor/' . self::PACKAGE;
            if (is_dir($copy)) {
                $found[(string) (realpath($copy) ?: $copy)] = true;
            }
        }
        foreach (array_keys($found) as $copy) {
            $this->check(false, 'composer copy', Log::sanitize($copy) . ' is a second copy of the plugin: Composer ran as root without a terminal and skipped Roundcube\'s plugin installer, so that run did not change plugins/galbridge - repeat it in an interactive shell or with COMPOSER_ALLOW_SUPERUSER=1 (README 2.1, 5 and 7)', true);
        }
    }

    /**
     * dont_override: Roundcube writes the current value of every option listed there into the
     * preferences of each user who saves settings - for an option set through a
     * ROUNDCUBE_GALBRIDGE_* variable that is its value, the DSN with the database password included.
     * GAL Bridge ignores galbridge_* preferences anyway, so the list adds nothing.
     */
    private function dontOverride(Config $cfg): void
    {
        $listed = [];
        foreach ((array) $cfg->coreValue('dont_override', []) as $name) {
            if (is_string($name) && str_starts_with($name, 'galbridge_')) {
                $listed[] = $name;
            }
        }
        if (in_array('galbridge_db_dsn', $listed, true)) {
            $this->check(false, 'dont_override', 'lists galbridge_db_dsn: Roundcube then writes the DSN with the database password into the preferences of every user who saves settings when the DSN comes from an environment variable - remove it (GAL Bridge ignores galbridge_* preferences anyway, SECURITY.md)');
        }
        $others = array_values(array_diff($listed, ['galbridge_db_dsn']));
        if ($others !== []) {
            $this->check(false, 'dont_override', 'lists ' . self::names($others) . ': Roundcube stores such an option in the preferences of every user who saves settings when it comes from an environment variable - remove it (GAL Bridge ignores galbridge_* preferences anyway)', true);
        }
    }

    /**
     * galbridge_* keys in the stored preferences of Roundcube's users (counts only; no value is read):
     * written by Roundcube for dont_override (see dontOverride()) or by an older setup. GAL Bridge
     * ignores them and drops them when a user saves settings.
     */
    private function storedPreferences(\rcube $rc): void
    {
        try {
            $db = $rc->get_dbh();
            $table = $db->table_name('users', true);
            $count = static function (string $pattern) use ($db, $table): int {
                $res = $db->query('SELECT COUNT(*) AS n FROM ' . $table . " WHERE preferences LIKE ? ESCAPE '!'", $pattern);
                if ($res === false || $db->is_error() !== null) {
                    throw new \RuntimeException('the query on the users table failed');
                }
                $row = $db->fetch_assoc($res);
                return (int) (is_array($row) ? ($row['n'] ?? 0) : 0);
            };
            // the serialised key: s:16:"galbridge_db_dsn"; ('_' is a wildcard of LIKE: escaped)
            $dsn = $count('%"galbridge!_db!_dsn"%');
            $any = $count('%"galbridge!_%');
        } catch (\Throwable) {
            $this->check(false, 'stored preferences', 'not checked: the Roundcube database is not reachable from this process - whether users have galbridge_* keys (the DSN) in their stored preferences is unknown', true);
            return;
        }
        if ($dsn > 0) {
            $this->check(false, 'stored preferences', $dsn . ' Roundcube user(s) have galbridge_db_dsn in their stored preferences (written by Roundcube for dont_override): set a new role password (galbridge.sh grants --reset-password) and the new DSN, remove galbridge_* from dont_override and check backups of the Roundcube database - the stored copies are dropped when each user next saves settings');
        }
        if ($any > $dsn) {
            $this->check(false, 'stored preferences', ($any - $dsn) . ' Roundcube user(s) have ' . ($dsn > 0 ? 'other ' : '') . 'galbridge_* keys in their stored preferences: ignored by GAL Bridge, dropped when each user next saves settings', true);
        }
        if ($any === 0) {
            $this->ok('stored preferences', 'no galbridge_* key in the stored preferences of any user');
        }
    }

    /**
     * Switches that loosen a protection are reported while they are on, also when nothing uses them
     * yet - a TLS exception applies the moment the DSN names a remote host, a wildcard opt-in the
     * moment '*' is set -, debug logging while it is on (personal data in the log), and the refused
     * roles are shown (the built-in names always apply).
     *
     * @param array<array-key, mixed> $plugins
     */
    private function looseningSwitches(Config $cfg, array $plugins): void
    {
        if ($cfg->bool('galbridge_db_allow_plaintext')) {
            $this->check(false, 'db_allow_plaintext', 'on: a remote database host is accepted without verified TLS as soon as the DSN names one - set it to false unless that is intended', true);
        }
        if ($cfg->bool('galbridge_db_allow_verify_ca')) {
            $this->check(false, 'db_allow_verify_ca', 'on: PostgreSQL sslmode=verify-ca (no host name check) is accepted for a remote host as soon as the DSN uses it - set it to false unless that is intended', true);
        }
        if ($cfg->bool('galbridge_debug')) {
            // also when ROUNDCUBE_GALBRIDGE_DEBUG switches it on (the plugin parses that variable itself)
            $this->check(false, 'debug', 'on (galbridge_debug): the log receives database driver error texts and exception messages, which can contain search words and addresses - switch it off after the diagnosis (README 3.10)', true);
        }
        if ($cfg->bool('galbridge_global_allow_wildcard') && $cfg->domainAllowList('galbridge_global_data_domains') !== ['*']) {
            $this->check(false, 'global_allow_wildcard', "on while galbridge_global_data_domains is not ['*']: it lets a later '*' list every hosted domain - set it to false unless that is intended", true);
        }
        if (in_array('password', $plugins, true) && $cfg->passwordPluginDriver() === 'sql' && $cfg->passwordPluginDsn() === null
            && !is_string($cfg->coreValue('password_db_dsn')) && !is_string($cfg->adminCore('password_db_dsn'))) {
            $this->check(false, 'password plugin', 'enabled with the sql driver, but no password_db_dsn was found (plugins/password/config.inc.php, password.inc.php, the main configuration): a database user of its own is not refused by name - add it to galbridge_db_refused_users (only the built-in names and the users of db_dsnw / db_dsnr are refused)', true);
        }
        $refused = $cfg->refusedUsers();
        if ($refused !== null) {
            $added = array_values(array_diff($refused, Config::DEFAULTS['galbridge_db_refused_users']));
            $this->info('refused roles', 'built in: ' . implode(', ', Config::DEFAULTS['galbridge_db_refused_users']) . ($added === [] ? '' : '; added: ' . self::names($added))
                . ' - plus the users of db_dsnw, db_dsnr and the password plugin\'s DSN');
        }
    }

    /**
     * The size of the deny-lists and of the organizations as they are in effect (counts, never an
     * entry): a list that did not take effect - a misspelt option, the other configuration file - is
     * noticed here.
     */
    private function policyLists(Config $cfg): void
    {
        $count = static fn (?array $list): string => $list === null ? 'INVALID' : (string) count($list);
        $this->info('policy lists', 'hidden_addresses ' . $count($cfg->hiddenAddresses())
            . ', hidden_localparts ' . $count($cfg->hiddenLocalparts())
            . ', domain_viewer_domains_exclude ' . $count($cfg->domainDenyList('galbridge_domain_viewer_domains_exclude'))
            . ', global_viewer_domains_exclude ' . $count($cfg->domainDenyList('galbridge_global_viewer_domains_exclude'))
            . ', global_data_domains_exclude ' . $count($cfg->domainDenyList('galbridge_global_data_domains_exclude'))
            . ', organizations ' . count($cfg->organizations()));
    }

    /**
     * WARN lines for the directory of a configuration file and the directories above it: owned by
     * another account than root, or writable by group/others without the sticky bit. Each directory
     * is reported once.
     *
     * @param array<string, true> $reported directories already looked at
     */
    private function configDirectories(string $file, array &$reported): void
    {
        $dir = realpath(dirname($file));
        if ($dir === false || isset($reported[$dir])) {
            return;
        }
        $reported[$dir] = true;
        $self = self::euid();
        $stat = @stat($dir);
        if ($stat === false) {
            return;
        }
        $shown = Log::sanitize($dir);
        $dperms = $stat['mode'] & 0o7777;
        if (($dperms & 0o022) !== 0 && ($dperms & 0o1000) === 0) {
            $this->check(false, 'config directory', $shown . ' is writable by group/others (mode ' . sprintf('%04o', $dperms) . ')', true);
        }
        if ($stat['uid'] !== 0) {
            $this->check(false, 'config directory', $shown . ' is owned by uid ' . $stat['uid'] . ($stat['uid'] === $self ? ' (the user running this tool, i.e. the PHP user)' : '') . ': that account can replace the configuration files in it - expected root', true);
        }
        for ($up = dirname($dir); ; $up = dirname($up)) {
            if (isset($reported[$up])) {
                break;
            }
            $reported[$up] = true;
            $st = @stat($up);
            if ($st !== false && ($st['uid'] !== 0 || (($st['mode'] & 0o022) !== 0 && ($st['mode'] & 0o1000) === 0))) {
                $this->check(false, 'config directory', Log::sanitize($up) . ' (above ' . $shown . ', owner uid ' . $st['uid'] . ', mode ' . sprintf('%04o', $st['mode'] & 0o7777) . ') is not root\'s alone: that account can replace the directories below it', true);
                break;
            }
            if ($up === dirname($up)) {
                break;
            }
        }
    }

    /**
     * The secret-column tripwire, write privileges (from the catalogs - every relation of the
     * schema on PostgreSQL, PUBLIC included - and on MariaDB/MySQL also from the server's own
     * answer to statements that can change nothing), what the role holds beyond the directory
     * (RoleScope: attributes, memberships, the database, global privileges, privileges outside),
     * what it may create and owns, the TLS requirement of a remote MariaDB/MySQL account,
     * connection limit, statement logging, PostgreSQL ctype.
     */
    private function privileges(Connection $conn, ?RoleScope $scope = null): void
    {
        $flavour = $conn->flavour();
        $scope ??= RoleScope::read($conn);
        // "denied" is the only good answer: any other error says nothing about the privilege
        $probe = $conn->probe('SELECT password FROM `mailbox` WHERE 1 = 0');
        $this->check($probe === 'denied', 'password column', match ($probe) {
            'denied' => 'not readable (refused by the server)',
            '' => 'READABLE: the role is too broad - create the dedicated role (galbridge.sh grants)',
            default => 'could not be checked (SQLSTATE ' . $probe . '): this is not a privilege error, so it proves nothing',
        });

        // 1. the catalogs (RoleScope): PostgreSQL - every relation and sequence of the schema, with
        //    what reaches the role through PUBLIC; MariaDB/MySQL - the account's own grants other
        //    than SELECT at every level of this database (also through a pattern such as `vmail%`)
        $writes = $scope->changes;
        $checked = $flavour === 'pgsql' ? 'every relation and sequence of the schema, PUBLIC included' : 'the grants of this account';
        if ($flavour === 'mariadb' || $flavour === 'mysql') {
            // 2. the server's own answer, which also covers privileges that reach the session through
            //    a role or PUBLIC: statements that can match no row, in a transaction that is rolled back
            foreach (Connection::TABLES as $table) {
                $column = $table === 'mailbox' ? 'username' : ($table === 'domain' ? '`domain`' : 'address');
                foreach (['UPDATE' => "UPDATE `{$table}` SET {$column} = {$column} WHERE 1 = 0", 'DELETE' => "DELETE FROM `{$table}` WHERE 1 = 0"] as $what => $sql) {
                    if ($conn->probeWrite($sql) === '') {
                        $writes[] = $what . ' on ' . $table;
                    }
                }
            }
            $checked .= '; UPDATE and DELETE refused by the server on the ' . count(Connection::TABLES) . ' relations GAL Bridge reads';
        }
        $this->check($writes === [], 'write privileges', $writes === []
            ? 'none (' . $checked . ')'
            : 'the role can modify vmail (' . RoleScope::names($writes) . ') - the plugin refuses it: use the read-only role from galbridge.sh grants');
        $this->roleScope($conn, $scope);

        if ($flavour === 'pgsql') {
            $rows = $conn->select("SELECT current_setting('statement_timeout') AS t,"
                . ' (SELECT datctype FROM pg_database WHERE datname = current_database()) AS l,'
                . ' (SELECT rolconnlimit FROM pg_roles WHERE rolname = current_user) AS cl,'
                // the connections the server has for ordinary roles (reserved_connections: PostgreSQL 16+)
                . " current_setting('max_connections') AS mxc, current_setting('superuser_reserved_connections') AS srv, current_setting('reserved_connections', true) AS rsv,"
                . " current_setting('log_min_error_statement') AS le, current_setting('log_statement') AS ls, current_setting('log_min_duration_statement') AS ld,"
                // sampled logging (PostgreSQL 12 / 13 and later; NULL on a server without the setting)
                . " (SELECT setting FROM pg_settings WHERE name = 'log_min_duration_sample') AS sd,"
                . " (SELECT setting FROM pg_settings WHERE name = 'log_statement_sample_rate') AS sr,"
                . " (SELECT setting FROM pg_settings WHERE name = 'log_transaction_sample_rate') AS tr,"
                // audit and plan logging of extensions (NULL when the server does not know the setting)
                . " current_setting('pgaudit.log', true) AS pa, current_setting('auto_explain.log_min_duration', true) AS ae");
            // CREATE on a schema or the database: an object of the role (a function, an operator)
            // could stand in for one that the statements of GAL Bridge use - the grant set revokes it.
            // RoleScope looked at the role's schema, the database, schema public through PUBLIC
            // (canCreate) and every other schema (createSchemas, also a refusal: privileges outside)
            if ($scope->createSchemas !== []) {
                $this->check(false, 'schema public', 'the role may create objects in another schema of the vmail database (CREATE on ' . RoleScope::names($scope->createSchemas) . ') - the plugin refuses the role (privileges outside): REVOKE CREATE ON SCHEMA ... FROM the role');
            }
            if ($scope->canCreate) {
                $this->check(false, 'schema public', 'the role may create objects in the vmail database (CREATE on schema public, the default up to PostgreSQL 14, or on its schema or the database): apply galbridge.sh grants --grants-only, which revokes it from PUBLIC (README 2.2)');
            }
            if (!$scope->canCreate && $scope->createSchemas === []) {
                $this->ok('schema public', 'no CREATE privilege (its schema, schema public, the other schemas and the database)');
            }
            // every value below comes from the server: sanitised like a log value before it is shown
            $this->statementTime($conn, (string) ($rows[0]['t'] ?? ''), self::postgresMilliseconds((string) ($rows[0]['t'] ?? '')));
            $lc = Log::sanitize((string) ($rows[0]['l'] ?? ''));
            $this->check(!in_array(strtoupper($lc), ['C', 'POSIX'], true), 'ctype', $lc . (in_array(strtoupper($lc), ['C', 'POSIX'], true) ? ' (case-insensitive search folds ASCII only)' : ''), true);
            $cl = (int) ($rows[0]['cl'] ?? -1);
            $mxc = (int) ($rows[0]['mxc'] ?? 0);
            $share = $cl >= 0 ? self::connectionShareProblem($cl, $mxc > 0 ? $mxc - (int) ($rows[0]['srv'] ?? 0) - (int) ($rows[0]['rsv'] ?? 0) : null) : null;
            $this->check($cl >= 0 && $share === null, 'connection limit', $share ?? ($cl < 0 ? 'unlimited: set one (galbridge.sh grants --grants-only --connection-limit=N, at most the PHP-FPM pm.max_children of Roundcube and what the server\'s max_connections can spare - README 2.2)' : $cl . ' (at most the PHP-FPM pm.max_children of Roundcube and what the server\'s max_connections can spare - README 2.2)'), true);
            // rcube_db inlines bound values, so a logged statement of this role carries search terms
            $logged = [];
            if (!in_array(strtolower((string) ($rows[0]['le'] ?? '')), ['panic', 'fatal', 'log'], true)) {
                $logged[] = 'log_min_error_statement=' . Log::sanitize((string) ($rows[0]['le'] ?? '?')) . ' (failed and cancelled statements)';
            }
            if (strtolower((string) ($rows[0]['ls'] ?? '')) !== 'none') {
                $logged[] = 'log_statement=' . Log::sanitize((string) ($rows[0]['ls'] ?? '?'));
            }
            if ((string) ($rows[0]['ld'] ?? '') !== '-1') {
                $logged[] = 'log_min_duration_statement=' . Log::sanitize((string) ($rows[0]['ld'] ?? '?'));
            }
            // a SAMPLE of the statements above a duration, or of whole transactions, is logged
            // whatever the three settings above say
            $sd = $rows[0]['sd'] ?? null;
            if ($sd !== null && (string) $sd !== '-1' && (float) ($rows[0]['sr'] ?? 1) > 0) {
                $logged[] = 'log_min_duration_sample=' . Log::sanitize((string) $sd) . ' with log_statement_sample_rate=' . Log::sanitize((string) ($rows[0]['sr'] ?? '?'));
            }
            if ((float) ($rows[0]['tr'] ?? 0) > 0) {
                $logged[] = 'log_transaction_sample_rate=' . Log::sanitize((string) $rows[0]['tr']);
            }
            // extensions log statements by settings of their own, which the grant template does not
            // touch: an audit trail of the role's statements necessarily records the search terms
            $audit = [];
            $pa = $rows[0]['pa'] ?? null;
            if ($pa !== null && trim(strtolower((string) $pa)) !== '' && trim(strtolower((string) $pa)) !== 'none') {
                $audit[] = 'pgaudit.log=' . Log::sanitize((string) $pa);
            }
            $ae = $rows[0]['ae'] ?? null;
            if ($ae !== null && trim((string) $ae) !== '' && trim((string) $ae) !== '-1') {
                $audit[] = 'auto_explain.log_min_duration=' . Log::sanitize((string) $ae);
            }
            if ($logged !== []) {
                $this->check(false, 'statement logging', 'the server log can record statements of this role with the search terms: ' . implode(', ', $logged) . ' - the grant template switches this off (galbridge.sh grants --grants-only)', true);
            }
            if ($audit !== []) {
                $this->check(false, 'statement logging', 'an extension can record statements of this role with the search terms: ' . implode(', ', $audit) . ' - the grant template does not change these settings: keep them only as a deliberate audit trail with a restricted, short-lived log, or switch them off for the role (ALTER ROLE ... SET, as a superuser; SECURITY.md)', true);
            }
            if ($logged === [] && $audit === []) {
                $this->ok('statement logging', 'off for this role (its statements contain search terms)');
            }
        } elseif ($flavour === 'mariadb' || $flavour === 'mysql') {
            $var = $flavour === 'mariadb' ? 'max_statement_time' : 'max_execution_time';
            $rows = $conn->select('SELECT @@' . $var . ' AS t, @@session.max_user_connections AS c, @@global.general_log AS g, @@global.max_connections AS mxc');
            // MariaDB max_statement_time in seconds (a decimal), MySQL max_execution_time in ms
            $t = (string) ($rows[0]['t'] ?? '');
            $ms = is_numeric($t) ? (int) round((float) $t * ($flavour === 'mariadb' ? 1000 : 1)) : null;
            $this->statementTime($conn, $var . '=' . $t, $ms);
            // the session value is the effective limit of this account (0 = neither account nor server limit)
            $cl = (int) ($rows[0]['c'] ?? 0);
            $mxc = (int) ($rows[0]['mxc'] ?? 0);
            $share = $cl > 0 ? self::connectionShareProblem($cl, $mxc > 0 ? $mxc : null) : null;
            $this->check($cl > 0 && $share === null, 'connection limit', $share ?? ($cl > 0 ? $cl . ' (at most the PHP-FPM pm.max_children of Roundcube and what the server\'s max_connections can spare - README 2.2)' : 'unlimited: only the number of PHP workers bounds the connections of this account - set one (galbridge.sh grants --grants-only --connection-limit=N)'), true);
            $general = in_array(strtolower((string) ($rows[0]['g'] ?? '0')), ['1', 'on'], true);
            $this->check(!$general, 'statement logging', $general ? 'the general query log is ON: it records every statement of the plugin with the search terms (SECURITY.md) - switch it off or restrict the log' : 'general query log off (the slow query log can still record statements with search terms, SECURITY.md)', true);
        }
    }

    /**
     * The statement time of the plugin's session as the server reports it: the plugin sets
     * galbridge_db_timeout in every session (a refused setting already fails the connection); a
     * different value means that something resets session settings (e.g. a connection pooler in
     * transaction mode) and the plugin's statements are not bounded as configured.
     */
    private function statementTime(Connection $conn, string $shown, ?int $ms): void
    {
        $want = $conn->statementTimeout();
        $value = Log::sanitize($shown === '' ? '?' : $shown);
        $this->check($ms === $want * 1000, 'statement time (this session)', $ms === $want * 1000
            ? $value . ' (galbridge_db_timeout)'
            : $value . ' - not the ' . $want . ' s of galbridge_db_timeout that the plugin set: its statements are not bounded as configured (a connection pooler that resets session settings?)');
    }

    /**
     * What the role holds beyond reading the directory (RoleScope) - each a FAIL, because the plugin
     * refuses such a role (owned objects and a MariaDB/MySQL server-wide test database: diag only,
     * the plugin logs a warning) - the databases a PostgreSQL role may connect to besides vmail, and
     * the server-side TLS requirement of the account.
     */
    private function roleScope(Connection $conn, RoleScope $scope): void
    {
        $flavour = $conn->flavour();
        if ($flavour === 'pgsql') {
            $this->check($scope->attributes === [], 'role attributes', $scope->attributes === []
                ? 'LOGIN only (no SUPERUSER, CREATEROLE, CREATEDB, REPLICATION, BYPASSRLS)'
                : RoleScope::names($scope->attributes) . ' - the plugin refuses the role: galbridge.sh grants --grants-only resets them');
            $this->check(!$scope->databaseOwner, 'database owner', $scope->databaseOwner
                ? 'the role OWNS the vmail database - the plugin refuses it: use the dedicated role of galbridge.sh grants'
                : 'not the owner of the database');
            $this->check($scope->owned === 0, 'owned objects', $scope->owned === 0
                ? 'none'
                : $scope->owned . ' object(s) owned by the role (it can change them): check them, then DROP OWNED BY the role as the administrator and apply galbridge.sh grants --grants-only');
        } else {
            $this->check($scope->global === [], 'global privileges', $scope->global === []
                ? 'none (USAGE only)'
                : RoleScope::names($scope->global) . ' - the plugin refuses the account: REVOKE them');
        }
        if ($scope->memberships === null) {
            $this->check(false, 'role memberships', 'not checked: this MySQL server has no information_schema.APPLICABLE_ROLES (8.0.19 and later)', true);
        } else {
            $this->check($scope->memberships === [], 'role memberships', $scope->memberships === []
                ? 'none'
                : RoleScope::names($scope->memberships) . ' - SET ROLE reaches their privileges, which no catalog of the role shows: the plugin refuses the role, REVOKE the membership');
        }
        $this->check($scope->outside === [], 'privileges outside', $scope->outside === []
            ? 'none outside the vmail ' . ($flavour === 'pgsql' ? 'schema and database' : 'database')
            : RoleScope::names($scope->outside) . ' - the plugin refuses the role: REVOKE them');
        if ($flavour !== 'pgsql') {
            // a server default open to every account (mariadb-install-db), not a grant of this
            // account: the plugin works and logs server_test_database, diag fails until it is gone
            $this->check($scope->serverTestDatabases === [], 'server-wide test database', $scope->serverTestDatabases === []
                ? 'none (no "test" or "test_*" database open to every account)'
                : RoleScope::names($scope->serverTestDatabases) . ' - open to every account of the server (mariadb-install-db creates "test" and grants all on "test" and "test_*" to everybody), the plugin\'s role included: remove it with mariadb-secure-installation ("Remove test database and access to it") or as the administrator: DROP DATABASE test; DELETE FROM mysql.db WHERE Db IN (\'test\', \'test\\\\_%\'); FLUSH PRIVILEGES;');
        }
        if ($flavour === 'pgsql' && $scope->connectable !== []) {
            $this->info('other databases', 'the role may connect to ' . RoleScope::names($scope->connectable) . ' (through PUBLIC as a rule): limit it to vmail in pg_hba.conf (README 2.2) - this tool cannot read pg_hba.conf, so the line stays once that is done; README 2.2 shows how to test it');
        }
        $this->accountTls($conn);
    }

    /**
     * MariaDB/MySQL: an account whose host part admits other hosts (%, a pattern, a name) without
     * REQUIRE SSL / X509 / ... - and without require_secure_transport - lets whoever has the password
     * log in over a plain connection (the plugin itself always uses TLS to a remote server). The
     * account statement is read, never printed: it holds the password hash. PostgreSQL keeps that
     * rule in pg_hba.conf, which the role cannot read.
     */
    private function accountTls(Connection $conn): void
    {
        if ($conn->driver() === 'pgsql') {
            $this->info('account TLS', 'enforced by pg_hba.conf (hostssl / hostnossl ... reject, README 2.2), which the role cannot read');
            return;
        }
        if ($conn->driver() !== 'mysql') {
            return;
        }
        $user = (string) ($conn->select('SELECT CURRENT_USER() AS u')[0]['u'] ?? '');
        if (!str_contains($user, '@')) {
            $this->check(false, 'account TLS', 'not checked: the server did not name the account (CURRENT_USER())', true);
            return;
        }
        $host = strtolower(substr($user, (int) strrpos($user, '@') + 1));
        $shown = Log::sanitize($user);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->ok('account TLS', $shown . ' (local connections only)');
            return;
        }
        $secure = false;
        foreach ($conn->select("SHOW GLOBAL VARIABLES LIKE 'require_secure_transport'") as $row) {
            $secure = in_array(strtolower((string) ($row['Value'] ?? $row['value'] ?? '')), ['on', '1'], true);
        }
        $statement = '';
        foreach (['SHOW CREATE USER CURRENT_USER()', 'SHOW GRANTS'] as $sql) {
            if ($conn->probe($sql) === '') {
                foreach ($conn->select($sql) as $row) {
                    $statement .= implode("\n", array_map(static fn (?string $v): string => (string) $v, $row)) . "\n";
                }
                break;
            }
        }
        if ($secure || self::requiresTls($statement)) {
            $this->ok('account TLS', $shown . ($secure ? ' (require_secure_transport is on)' : ' (REQUIRE SSL)'));
        } elseif ($statement === '') {
            $this->check(false, 'account TLS', $shown . ': not checked (the server shows neither SHOW CREATE USER nor SHOW GRANTS of the account)', true);
        } else {
            $this->check(false, 'account TLS', $shown . ' accepts connections from other hosts without REQUIRE SSL: whoever has the password can log in without TLS - add it (ALTER USER ... REQUIRE SSL; galbridge.sh grants does for a non-local --account-host)', true);
        }
    }

    /**
     * Does a CREATE USER / GRANT statement of MariaDB/MySQL require TLS (REQUIRE SSL, X509, ISSUER,
     * SUBJECT or CIPHER)? Quoted strings and identifiers - the password hash among them - never count.
     */
    public static function requiresTls(string $statement): bool
    {
        $bare = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'|`(?:[^`]|``)*`|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $statement);
        return preg_match('/\bREQUIRE\s+(SSL|X509|ISSUER|SUBJECT|CIPHER)\b/i', $bare) === 1;
    }

    /**
     * current_setting('statement_timeout') in milliseconds ("3s", "3000ms", "1min", "0"), or null.
     */
    private static function postgresMilliseconds(string $value): ?int
    {
        if (preg_match('/^(\d{1,9})\s*(ms|s|min|h)?$/D', trim($value), $m) !== 1) {
            return null;
        }
        return (int) $m[1] * match ($m[2] ?? '') {
            's' => 1000,
            'min' => 60000,
            'h' => 3600000,
            default => 1,
        };
    }

    /**
     * @param array<string, list<string>> $columns the relations GAL Bridge reads or must not read
     * @param array<string, list<string>> $all     every relation of the schema / database
     */
    private function columns(array $columns, Config $cfg, array $all = []): void
    {
        $sqlite = $this->isSqlite($columns);
        $all = $all === [] ? $columns : $all;
        $secret = [];
        foreach ($all as $table => $cols) {
            foreach (array_intersect(Connection::SECRET_COLUMNS, $cols) as $col) {
                $secret[] = $table . '.' . $col;
            }
        }
        $this->check($secret === [] || $sqlite, 'secret columns', $secret === [] ? 'not visible (in any relation of the database)' : 'VISIBLE (' . RoleScope::names($secret) . '): role too broad, the plugin refuses to run');
        foreach (Connection::FORBIDDEN_TABLES as $table) {
            $visible = ($columns[$table] ?? []) !== [];
            $this->check(!$visible || $sqlite, 'table ' . $table, !$visible
                ? 'not readable by the role (it reads the view ' . Schema::FORWARDINGS . ', checked below)'
                : "READABLE: the role can read every row, including the private forwarding targets of all mailboxes - the plugin refuses to run. Revoke the grant on {$table} and grant the view (galbridge.sh grants --grants-only; README 5)");
        }
        if (!$sqlite) {
            // columns no statement of GAL Bridge reads: the grant set never gives them (Schema::GRANTABLE)
            $extra = [];
            $vis = $cfg->visibilityColumn();
            foreach ($all as $table => $cols) {
                if (in_array($table, Connection::FORBIDDEN_TABLES, true)) {
                    continue;
                }
                $allowed = [...(Schema::GRANTABLE[$table] ?? []), ...($table === 'mailbox' && is_string($vis) && $vis !== '' ? [$vis] : [])];
                foreach (array_diff($cols, $allowed, Connection::SECRET_COLUMNS) as $col) {
                    $extra[] = $table . '.' . $col;
                }
            }
            if ($extra !== []) {
                $this->check(false, 'columns beyond the grant set', RoleScope::names($extra) . ' - GAL Bridge never reads them: revoke the grants (the grant set of galbridge.sh grants has none of them)', true);
            }
        }
        foreach (Schema::required($cfg) as $table => $cols) {
            $missing = array_diff($cols, $columns[$table] ?? []);
            $hint = $table === Schema::FORWARDINGS && ($columns[$table] ?? []) === [] ? ' (the view of the grant template: run galbridge.sh grants, --grants-only for an existing role)' : '';
            $this->check($missing === [], 'columns ' . $table, $missing === [] ? 'ok' : 'missing or not granted: ' . implode(', ', $missing) . $hint);
        }
        foreach (Schema::optional($cfg) as $table => $cols) {
            $missing = array_diff($cols, $columns[$table] ?? []);
            if ($cols === []) {
                continue;
            }
            $hint = $table === 'mailbox' ? ' (configured fields; iRedMail update/1.7.3 adds first_name, last_name, mobile, telephone, birthday - or grant them)' : ' (alias accounts / mailing lists are left out)';
            $this->check($missing === [], 'optional columns ' . $table, $missing === [] ? 'ok' : 'missing or not granted: ' . implode(', ', $missing) . $hint, true);
        }
        $configured = Schema::optional($cfg)['mailbox'] ?? [];
        $unused = array_intersect(array_diff(self::KNOWN_OPTIONAL['mailbox'], $configured), $columns['mailbox'] ?? []);
        if ($unused !== []) {
            $this->info('granted but unused', 'mailbox.' . implode(', mailbox.', $unused) . ' (not in galbridge_fields; the grant can be dropped)');
        }
        $vis = $cfg->visibilityColumn();
        if ($vis === '') {
            $this->info('visibility column', 'not used (galbridge_visibility_column is empty: no per-person filter)');
        } elseif (is_string($vis)) {
            $this->check(in_array($vis, $columns['mailbox'] ?? [], true), 'visibility column', $vis . (in_array($vis, $columns['mailbox'] ?? [], true) ? ' (persons are listed only when it is 1)' : ' is not readable: every book is empty (fail closed)'));
        }
    }

    /**
     * The view of the grant set, as far as the role can see it. Its contents - only alias,
     * alias-member and list rows (a view of that name with any other row, created by hand or
     * replaced, would hand the role the private forwarding targets the plugin refuses the table for;
     * the plugin refuses it too). The object (RoleScope): PostgreSQL - a view, owned by a superuser
     * or by the owner of the table, a security barrier, not security_invoker, fenced with OFFSET 0,
     * using no object but the table; MariaDB/MySQL - a view with SQL SECURITY DEFINER that is not
     * merged into the queries of the role. And on MariaDB/MySQL its definition (SHOW CREATE VIEW,
     * which the grant set allows): ALGORITHM=TEMPTABLE, the LIMIT fence, the table forwardings only.
     *
     * @param array<string, list<string>> $columns
     */
    private function view(Connection $conn, array $columns, ?RoleScope $scope): void
    {
        $visible = $columns[Schema::FORWARDINGS] ?? [];
        if (array_diff(['is_alias', 'is_list', 'is_maillist'], $visible) !== []) {
            return; // reported by the column check above
        }
        $label = 'view ' . Schema::FORWARDINGS;
        $rows = $conn->select('SELECT COUNT(*) AS n, SUM(' . ViewerResolver::OTHER_KIND_FLAG . ') AS o FROM `' . Schema::FORWARDINGS . '` f');
        $total = (int) ($rows[0]['n'] ?? 0);
        $other = (int) ($rows[0]['o'] ?? 0);
        $this->check($other === 0, $label, $other === 0
            ? 'holds alias, alias-member and list rows only (' . $total . ' rows: built afresh for every statement that reads it)'
            : 'holds ' . $other . ' row(s) of another kind - private forwarding targets; the plugin refuses it: re-create it from the grant set (galbridge.sh grants --grants-only)');
        if ($conn->driver() === 'sqlite') {
            return;
        }
        $problems = $scope === null ? [] : $scope->viewProblems;
        if ($conn->driver() === 'mysql') {
            $definition = $this->viewDefinition($conn);
            if ($definition === null) {
                $this->check(false, $label, 'definition not visible to the role (SHOW VIEW on the view, which the grant set of this release gives): the LIMIT fence is not checked - apply galbridge.sh grants --grants-only', true);
            } else {
                array_push($problems, ...self::viewDefinitionProblems($definition));
            }
        }
        foreach (array_values(array_unique($problems)) as $problem) {
            $this->check(false, $label, $problem . ' - apply galbridge.sh grants --grants-only (README 5)');
        }
        if ($problems === []) {
            $this->ok($label, $conn->driver() === 'pgsql'
                ? 'a security barrier fenced with OFFSET 0, owned by the administrator: no condition of the role sees a private row'
                : 'ALGORITHM=TEMPTABLE with the LIMIT fence, SQL SECURITY DEFINER: no condition of the role sees a private row');
        }
    }

    /**
     * SHOW CREATE VIEW of the view as the role (MariaDB/MySQL; null when the server refuses it).
     */
    private function viewDefinition(Connection $conn): ?string
    {
        $sql = 'SHOW CREATE VIEW `' . Schema::FORWARDINGS . '`';
        if ($conn->probe($sql) !== '') {
            return null;
        }
        $row = $conn->select($sql)[0] ?? [];
        return (string) ($row['Create View'] ?? $row['create view'] ?? '');
    }

    /**
     * How a MariaDB/MySQL view definition (SHOW CREATE VIEW) differs from the one of the grant set.
     *
     * @return list<string>
     */
    public static function viewDefinitionProblems(string $definition): array
    {
        $problems = [];
        if (preg_match('/\bALGORITHM\s*=\s*TEMPTABLE\b/i', $definition) !== 1) {
            $problems[] = 'it is not ALGORITHM=TEMPTABLE: the server may merge it into the queries of the role';
        }
        if (preg_match('/\blimit\s+18446744073709551615\s*$/i', trim($definition)) !== 1) {
            $problems[] = 'it is not fenced (no LIMIT 18446744073709551615 at the end): the server may push conditions of the role into it';
        }
        preg_match_all('/\b(?:from|join)\s+((?:`[^`]*`\.)?`[^`]*`|[A-Za-z0-9_.$]+)/i', $definition, $m);
        $tables = array_values(array_unique(array_map(static fn (string $t): string => strtolower((string) preg_replace('/^.*\./', '', str_replace('`', '', $t))), $m[1])));
        if ($tables !== [Schema::FORWARDINGS_TABLE]) {
            $problems[] = 'it reads ' . ($tables === [] ? 'no table' : RoleScope::names($tables)) . ', not the table ' . Schema::FORWARDINGS_TABLE . ' alone';
        }
        return $problems;
    }

    /**
     * @param array<string, list<string>> $columns
     */
    private function isSqlite(array $columns): bool
    {
        return in_array('password', $columns['mailbox'] ?? [], true) && defined('GALBRIDGE_ALLOW_SQLITE') && constant('GALBRIDGE_ALLOW_SQLITE') === true;
    }

    /**
     * @param array<string, list<string>> $columns the visible columns of the directory relations
     */
    private function dataSanity(Connection $conn, Config $cfg, array $columns = []): void
    {
        $rows = $conn->select('SELECT COUNT(d.`domain`) AS n FROM `domain` d WHERE d.active = 1 AND d.backupmx = 0');
        $this->info('hosted domains', (string) ($rows[0]['n'] ?? '0') . ' active');
        $rows = $conn->select('SELECT COUNT(m.username) AS n FROM `mailbox` m JOIN `domain` d ON d.`domain` = m.`domain` WHERE m.active = 1 AND m.enabledeliver = 1 AND d.active = 1 AND d.backupmx = 0');
        $this->info('deliverable mailboxes', (string) ($rows[0]['n'] ?? '0'));
        $this->entriesNotOffered($conn, $columns);
        $this->windowFunctions($conn, $cfg);
        $rows = $conn->select("SELECT COUNT(m.username) AS n FROM `mailbox` m WHERE m.active = 1 AND TRIM(m.name) = ''");
        $this->info('mailboxes without a name', (string) ($rows[0]['n'] ?? '0') . ' (shown with their address)');
        $flavour = $conn->flavour();
        foreach (['mailbox' => 'domain', Schema::FORWARDINGS_TABLE => 'forwarding'] as $table => $column) {
            if ($flavour === 'pgsql') {
                $rows = $conn->select('SELECT COUNT(*) AS n FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexdef LIKE ?', [$table, '%(' . $column . ')%']);
            } elseif ($flavour === 'sqlite' || $table === Schema::FORWARDINGS_TABLE) {
                // MariaDB/MySQL show the indexes of a table only to a role with a privilege on it; the
                // plugin's role has none on forwardings (it reads the view)
                continue;
            } else {
                $rows = $conn->select('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1', [$table, $column]);
            }
            $n = (int) ($rows[0]['n'] ?? 0);
            $this->check($n > 0, 'index ' . $table . '(' . $column . ')', $n > 0 ? 'present' : 'not found (or not visible to this role)' . ($table === Schema::FORWARDINGS_TABLE ? ' - iRedMail update/1.6.3 adds it' : ''), true);
        }
    }

    /**
     * Rows that no book offers because their address (or domain) does not follow the address rule
     * of the directory (Normalizer::CANONICAL_ADDRESS - the SQL of every book applies it,
     * QueryBuilder::canonicalAddressSql()): counted per kind, with up to EXAMPLES of each - the
     * administrator needs them to correct the row - written as JSON strings (control and non-ASCII
     * characters as \uXXXX) and with the tag the log gives such a row (row=...). The same rendering
     * of the rule as the books use, negated as "CASE WHEN rule THEN 1 ELSE 0 END = 0", so a NULL
     * column counts as not offered.
     *
     * @param array<string, list<string>> $columns
     */
    private function entriesNotOffered(Connection $conn, array $columns): void
    {
        $driver = $conn->driver();
        $active = ' AND d.active = 1 AND d.backupmx = 0';
        $kinds = [
            'persons' => ['mailbox', ['username', 'domain', 'active', 'enabledeliver'], 'm.username', '`mailbox` m JOIN `domain` d ON d.`domain` = m.`domain`',
                'm.active = 1 AND m.enabledeliver = 1' . $active, QueryBuilder::canonicalAddressSql($driver, 'm.username', 'm.`domain`')],
            'alias accounts' => ['alias', ['address', 'domain', 'active'], 'a.address', '`alias` a JOIN `domain` d ON d.`domain` = a.`domain`',
                'a.active = 1' . $active, QueryBuilder::canonicalAddressSql($driver, 'a.address', 'a.`domain`')],
            'mailing lists' => ['maillists', ['address', 'domain', 'active'], 'ml.address', '`maillists` ml JOIN `domain` d ON d.`domain` = ml.`domain`',
                'ml.active = 1' . $active, QueryBuilder::canonicalAddressSql($driver, 'ml.address', 'ml.`domain`')],
            'per-user aliases' => [Schema::FORWARDINGS, ['address', 'domain', 'is_alias', 'active'], 'f.address', '`' . Schema::FORWARDINGS . '` f JOIN `domain` d ON d.`domain` = f.`domain`',
                'f.is_alias = 1 AND f.active = 1' . $active, Sql::and(QueryBuilder::canonicalAddressSql($driver, 'f.address'), QueryBuilder::canonicalDomainSql($driver, 'f.`domain`'))],
            'hosted domains' => ['domain', ['domain', 'active', 'backupmx'], 'd.`domain`', '`domain` d', 'd.active = 1 AND d.backupmx = 0', QueryBuilder::canonicalDomainSql($driver, 'd.`domain`')],
        ];
        $counts = [];
        $examples = [];
        $unchecked = [];
        foreach ($kinds as $label => [$table, $needed, $column, $from, $where, $rule]) {
            if (array_diff($needed, $columns[$table] ?? []) !== [] || array_diff(['domain', 'active', 'backupmx'], $columns['domain'] ?? []) !== []) {
                $unchecked[] = $label;
                continue;
            }
            $condition = ' WHERE ' . $where . ' AND (CASE WHEN ' . $rule->sql . ' THEN 1 ELSE 0 END) = 0';
            $n = (int) ($conn->select('SELECT COUNT(*) AS n FROM ' . $from . $condition, $rule->params)[0]['n'] ?? 0);
            if ($n === 0) {
                continue;
            }
            $counts[$label] = $n;
            foreach ($conn->select('SELECT ' . $column . ' AS v FROM ' . $from . $condition . ' ORDER BY ' . $column, $rule->params, 0, self::EXAMPLES) as $row) {
                $value = (string) ($row['v'] ?? '');
                $examples[$label][] = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ' row=' . Log::tag($value);
            }
        }
        if ($counts === []) {
            $this->ok('entries not offered', 'none: every active address and hosted domain follows the address rule of the directory');
        } else {
            $parts = [];
            foreach ($counts as $label => $n) {
                $parts[] = $label . ' ' . $n;
            }
            $this->check(false, 'entries not offered', array_sum($counts) . ' (' . implode(', ', $parts) . ') - their address or domain does not follow the address rule (local part of'
                . ' lower-case letters, digits and . _ + = -, host name of at least two labels; README 3.4), so no book lists, counts, finds or groups them:'
                . ' correct them in iRedAdmin, or leave them out of the directory knowingly (the log names such a row by the same row= tag)', true);
            foreach ($examples as $label => $lines) {
                foreach ($lines as $line) {
                    $this->info('  ' . $label, $line);
                }
                $more = ($counts[$label] ?? 0) - count($lines);
                if ($more > 0) {
                    $this->info('  ' . $label, '(' . $more . ' more)');
                }
            }
        }
        if ($unchecked !== []) {
            $this->check(false, 'entries not offered', 'not checked for ' . implode(', ', $unchecked) . ': columns not readable by the role', true);
        }
        if ($conn->flavour() === 'mariadb' || $conn->flavour() === 'mysql') {
            $this->mysqlCase($conn, $columns);
        }
    }

    /**
     * MariaDB/MySQL: addresses written with capitals are offered (in lower case: iRedMail's
     * collation compares case-insensitively, so their IDs find them) - PostgreSQL would not offer
     * them. And a collation whose LOWER() maps 'I' to a dotless i would let such an address escape
     * the hidden lists and exclusions, which SQL compares in lower case.
     *
     * @param array<string, list<string>> $columns
     */
    private function mysqlCase(Connection $conn, array $columns): void
    {
        $capitals = 0;
        foreach (['mailbox' => 'username', 'alias' => 'address', 'maillists' => 'address'] as $table => $column) {
            if (!in_array($column, $columns[$table] ?? [], true)) {
                continue;
            }
            $rule = QueryBuilder::canonicalAddressSql('mysql', 't.' . $column);
            $capitals += (int) ($conn->select('SELECT COUNT(*) AS n FROM `' . $table . '` t WHERE ' . $rule->sql
                . ' AND CAST(t.' . $column . ' AS BINARY) <> CAST(LOWER(t.' . $column . ') AS BINARY)', $rule->params)[0]['n'] ?? 0);
        }
        if ($capitals > 0) {
            $this->info('addresses with capitals', $capitals . ' offered in lower case (this server compares addresses case-insensitively; PostgreSQL would not offer them)');
        }
        $rows = $conn->select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLLATION_NAME AS k FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND ((TABLE_NAME = 'mailbox' AND COLUMN_NAME IN ('username', 'domain')) OR (TABLE_NAME IN ('alias', 'maillists') AND COLUMN_NAME IN ('address', 'domain'))"
            . " OR (TABLE_NAME = 'domain' AND COLUMN_NAME = 'domain'))");
        foreach ($rows as $row) {
            $collation = (string) ($row['k'] ?? '');
            if (preg_match('/turkish|azerbaijani|azeri/i', $collation) === 1) {
                $this->check(false, 'collation', Log::sanitize($row['t'] . '.' . $row['c'] . ' uses ' . $collation)
                    . ": its LOWER() turns 'I' into a dotless i, so an address written with a capital I escapes the hidden lists and exclusions in SQL (the plugin then drops the row itself) - keep iRedMail's utf8mb4_general_ci", true);
            }
        }
    }

    /**
     * The per-user aliases of a person are read with a window function (ROW_NUMBER() per owner):
     * MariaDB 10.2, MySQL 8.0 (PostgreSQL and SQLite have it in every supported version).
     */
    private function windowFunctions(Connection $conn, Config $cfg): void
    {
        $flavour = $conn->flavour();
        if ($flavour !== 'mariadb' && $flavour !== 'mysql') {
            return;
        }
        $version = $conn->serverVersion();
        if (self::windowFunctionsSupported($flavour, $version)) {
            return;
        }
        $text = Log::sanitize($version) . ' has no window functions (MariaDB 10.2 / MySQL 8.0 needed)';
        if ($cfg->bool('galbridge_user_aliases')) {
            $this->check(false, 'server version', $text . ': with galbridge_user_aliases every page of persons fails (db_error) - upgrade the server or switch galbridge_user_aliases off');
        } else {
            $this->info('server version', $text . ': galbridge_user_aliases cannot be switched on with this server');
        }
    }

    /**
     * Does a MariaDB/MySQL server of this VERSION() have window functions (MariaDB 10.2, MySQL 8.0)?
     * An unparsable version counts as supported (no claim without evidence); the "5.5.5-" prefix
     * that some proxies put before a MariaDB version is ignored.
     */
    public static function windowFunctionsSupported(string $flavour, string $version): bool
    {
        if (preg_match('/^(?:5\.5\.5-)?(\d+)\.(\d+)/', $version, $m) !== 1) {
            return true;
        }
        $v = (int) $m[1] * 1000 + (int) $m[2];
        $mariadb = $flavour === 'mariadb' || stripos($version, 'mariadb') !== false;
        return $v >= ($mariadb ? 10002 : 8000);
    }

    private function userReport(Config $cfg, Connection $conn, string $login): void
    {
        $resolver = new ViewerResolver($cfg, static fn (): Connection => $conn, 0, $login, '', '');
        $viewer = $resolver->lookup($login);
        if ($viewer === null) {
            $this->check(false, 'mailbox', 'not found, not active or not usable (status: ' . $resolver->status() . ')');
            return;
        }
        $this->ok('home domain', $viewer->home);
        $this->info('addresses', count($viewer->addresses) . ' (login + per-user aliases of deliverable domains, used for list policies; at most ' . ViewerResolver::MAX_ALIASES . ' aliases)');
        $books = Entitlements::books($viewer, $cfg, static fn (string $l): string => $l);
        if ($books === []) {
            $this->check(false, 'books', 'none for this user (viewer lists, exclusions, empty data scope or a disabled deny-list)', true);
            return;
        }
        $fields = FieldMap::fromConfig($cfg, $viewer);
        $limits = Limits::fromConfig($cfg);
        foreach ($books as $id => $book) {
            $scope = $book->allDomains ? 'all hosted domains' . ($book->excludeDomains !== [] ? ' except ' . count($book->excludeDomains) : '') : count($book->domains) . ' domain(s)';
            $searchonly = $book->searchonly === null ? 'auto' : ($book->searchonly ? 'yes' : 'no');
            $queries = QueryBuilder::forBook($cfg, $conn->driver(), $book, $viewer, $fields);
            $grouping = Factory::effectiveGrouping($book, $fields);
            if ($grouping !== 'none' && ($q = $queries->groupsQuery($grouping)) !== null) {
                $groups = count($conn->select($q[0], $q[1], 0, $limits->maxGroups + 1));
                $grouping .= $groups > $limits->maxGroups ? ' (disabled: more than galbridge_max_groups groups)' : ' (' . $groups . ' groups)';
            }
            $this->ok($id, $scope . ', groups: ' . $grouping . ', search-only: ' . $searchonly);
            $total = 0;
            foreach ($queries->kinds() as $kind) {
                $label = [QueryBuilder::KIND_PERSON => 'persons', QueryBuilder::KIND_ALIAS => 'alias accounts', QueryBuilder::KIND_LIST => 'mailing lists'][$kind] ?? $kind;
                [$sql, $params] = $queries->kindCountQuery($kind, $limits->maxCount + 1);
                $started = microtime(true);
                $n = (int) ($conn->select($sql, $params)[0]['n'] ?? 0);
                $total += $n;
                $this->info('  ' . $label, $n . ' (' . (int) round((microtime(true) - $started) * 1000) . ' ms)');
            }
            if ($book->searchonly === null) {
                $this->info('  search-only auto', $total > $limits->searchonlyThreshold ? 'yes (more than ' . $limits->searchonlyThreshold . ' entries)' : 'no');
            }
            if ($book->searchonly === false && $total > $limits->maxCount) {
                $this->check(false, '  size', 'more than galbridge_max_count entries: listing totals are capped and later pages unreachable - consider search-only \'auto\'', true);
            }
            if ($book->export && $book->searchonly === false && $total > $limits->maxExport) {
                $this->check(false, '  export', 'more than galbridge_max_export entries: exporting the whole book is refused', true);
            }
        }
    }

    private function result(): int
    {
        // what was not looked at is part of the result, not only of a line far above
        $unchecked = $this->webUnchecked ? ' (not checked: web access - run with --url)' : '';
        $summary = match (true) {
            $this->failures > 0 => 'Result: ' . $this->failures . ' problem(s) found',
            $this->warnings > 0 && $this->strict => 'Result: ' . $this->warnings . ' warning(s) - --strict counts them as problems' . $unchecked,
            // exit code 0, but never a bare "OK" over security warnings
            $this->warnings > 0 => 'Result: OK with ' . $this->warnings . ' warning(s) - read the WARN lines' . $unchecked,
            default => 'Result: OK' . $unchecked,
        };
        $this->out("\n" . $summary . "\n");
        return $this->failures === 0 && !($this->strict && $this->warnings > 0) ? 0 : 2;
    }

    private function section(string $title): void
    {
        $this->out("\n== {$title}\n");
    }

    private function ok(string $what, string $value): void
    {
        $this->out(sprintf("  [ OK ] %-34s %s\n", $what, $value));
    }

    /** informational line: never counts as a problem */
    private function info(string $what, string $value): void
    {
        $this->out(sprintf("  [INFO] %-34s %s\n", $what, $value));
    }

    private function check(bool $cond, string $what, string $value, bool $warnOnly = false): void
    {
        if (!$cond) {
            $warnOnly ? $this->warnings++ : $this->failures++;
        }
        $this->out(sprintf("  [%s] %-34s %s\n", $cond ? ' OK ' : ($warnOnly ? 'WARN' : 'FAIL'), $what, $value));
    }

    /**
     * Every line of the tool goes through here: whatever a value carries (a database server's
     * answer, an exception text, a file name, a configuration value), the terminal receives no
     * control, format or separator character and no invalid UTF-8 raw (Terminal::clean()).
     */
    private function out(#[\SensitiveParameter] string $s): void
    {
        fwrite($this->output, Terminal::clean($s));
    }

    /**
     * Usage errors, refusals and the DSN line of grants: the error stream (standard error), never
     * the stream that carries the report or the SQL. Neutralised like out().
     */
    private function err(#[\SensitiveParameter] string $s): void
    {
        fwrite($this->errors, Terminal::clean($s));
    }
}
