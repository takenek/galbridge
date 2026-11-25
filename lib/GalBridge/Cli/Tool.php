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
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Entitlements;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\Limits;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Schema;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * Administrator command line tool (bin/galbridge.sh).
 *
 *  diag [--user=EMAIL] [--url=URL]
 *                        check the installation, the configuration and the vmail database; with
 *                        --user report (as counts only) what that user would see; with --url ask the
 *                        web server whether it serves the plugin directory
 *  grants --driver=mysql|pgsql [--host=...] [--account-host=...] [--db=vmail] [--role=galbridge]
 *         [--connection-limit=25] [--reset-password | --grants-only]
 *                        print the least-privilege role statements and the matching DSN line (stdout
 *                        only - nothing is written to disk). By default they CREATE the role and
 *                        stop when it already exists; --reset-password gives an existing role a new
 *                        password; --grants-only re-applies limits, view and grants and leaves the
 *                        password alone
 *
 * It never prints the database password (apart from the DSN line of grants) or directory contents
 * (counts only). Every diag section runs on its own, so one failing check cannot hide the others.
 * Exit codes: 0 = OK (the summary counts WARN lines), 1 = usage error, 2 = problems found or an
 * unexpected error.
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

    /** the options of each command: true = takes a value (--name=value), false = a flag */
    private const OPTIONS = [
        'diag' => ['user' => true, 'url' => true, 'allow-root' => false],
        'grants' => [
            'driver' => true, 'host' => true, 'account-host' => true, 'db' => true, 'role' => true, 'connection-limit' => true,
            'reset-password' => false, 'grants-only' => false, 'clear-text-password' => false, 'allow-root' => false,
        ],
    ];

    private const USAGE = [
        'diag' => 'Usage: galbridge.sh diag [--user=EMAIL] [--url=URL]',
        'grants' => 'Usage: galbridge.sh grants --driver=mysql|pgsql [--host=H] [--account-host=A] [--db=vmail] [--role=galbridge] [--connection-limit=25] [--reset-password | --grants-only] [--clear-text-password (mysql only)]',
    ];

    /** the first line of the paragraph of grants.mysql.sql that says no statement holds the password */
    private const VERIFIER_NOTE = '-- No statement of this file contains the password of the account';

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
        'switches (controls)' => ['galbridge_db_allow_plaintext', 'galbridge_db_allow_verify_ca', 'galbridge_debug'],
    ];

    /** what a release archive puts into the plugin directory (plus the local config.inc.php) */
    private const RELEASE_FILES = [
        'galbridge.php', 'lib', 'bin', 'SQL', 'localization', 'docs', 'config.inc.php', 'config.inc.php.dist', '.htaccess',
        'composer.json', 'LICENSE', 'COPYRIGHT', 'README.md', 'SECURITY.md', 'CHANGELOG.md',
    ];

    /** what only a git clone has: test harness (scripts that reset databases and Dovecot) and tooling */
    private const CLONE_FILES = ['tests', '.git', '.github', '.gitattributes', '.gitignore', '.gitguardian.yaml', '.gitleaks.toml', '.php-cs-fixer.dist.php', 'phpstan.neon.dist', 'phpunit.xml.dist'];

    private int $failures = 0;

    private int $warnings = 0;

    /** @var resource */
    private $output;

    /**
     * @param null|resource $output stream for the tool output (default STDOUT)
     */
    public function __construct(private readonly ?\rcube $rc, private readonly string $pluginDir, $output = null)
    {
        $this->output = $output ?? STDOUT;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function run(array $args): int
    {
        try {
            return $this->dispatch($args);
        } catch (\Throwable $e) {
            // never "OK" by accident: an error outside a guarded diag section ends with exit code 2
            $this->out("\nerror: " . Log::sanitize(get_class($e) . ': ' . $e->getMessage()) . "\nResult: aborted\n");
            return 2;
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function dispatch(array $args): int
    {
        $cmd = is_string($args[0] ?? null) ? $args[0] : 'help';
        if (isset(self::OPTIONS[$cmd])) {
            // An option that is misspelled, or written "--role name" instead of "--role=name", must
            // not silently leave the default in place: the statements would be for another role.
            $problem = self::argumentProblem($cmd, $args);
            if ($problem !== null) {
                $this->out(self::USAGE[$cmd] . "\n" . $problem . "\n");
                return 1;
            }
        }
        switch ($cmd) {
            case 'diag':
                if ($this->rc === null) {
                    $this->out("diag needs a Roundcube installation\n");
                    return 1;
                }
                return $this->diag(is_string($args['user'] ?? null) ? $args['user'] : '', is_string($args['url'] ?? null) ? $args['url'] : '');
            case 'grants':
                return $this->grants($args);
            default:
                $this->out("GAL Bridge administration tool\n\n"
                    . "Usage: plugins/galbridge/bin/galbridge.sh <command> [options]  (run from the Roundcube directory as the web server user)\n\n"
                    . "  diag [--user=EMAIL]          check the installation and the vmail database (no secrets;\n"
                    . "                               --user reports, as counts, what that user would see)\n"
                    . "       [--url=URL]             also ask the web server at URL (the webmail address) whether it\n"
                    . "                               serves files of the plugin directory - it must not\n"
                    . "  grants --driver=mysql|pgsql  print the read-only role statements with a fresh password and the\n"
                    . "                               matching DSN (stdout only; run the SQL as the database administrator).\n"
                    . "                               They CREATE the role and stop when a role of that name exists\n"
                    . "         [--host=H]            database server written into the DSN (default localhost / 127.0.0.1)\n"
                    . "         [--account-host=A]    MySQL/MariaDB: host part of the account = where Roundcube connects\n"
                    . "                               from (default localhost; patterns like 10.0.0.% allowed)\n"
                    . "         [--db=vmail] [--role=galbridge] [--connection-limit=25]\n"
                    . "         [--reset-password]    for an EXISTING role as well: set a new password (the old one stops\n"
                    . "                               working at once)\n"
                    . "         [--grants-only]       for an EXISTING role: re-apply limits, view and grants (after a plugin\n"
                    . "                               upgrade); the password is not changed and no DSN is printed\n"
                    . "         [--clear-text-password]  MySQL 8.4 or later only (mysql_native_password not loaded): write the\n"
                    . "                               password itself into the statement instead of its verifier. Never for\n"
                    . "                               MariaDB, whose logs would keep it (the output refuses to run there)\n\n"
                    . "Do not run this tool as root: it loads PHP code from the plugin and the Roundcube directory.\n");
                return $cmd === 'help' ? 0 : 1;
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
            || !preg_match('/^([a-z0-9.-]{1,253}|\[[0-9a-f:.]{2,45}\])$/D', $host)   // IPv6 only in brackets
            || !preg_match('/^[a-z0-9.%_:-]{1,255}$/D', $accountHost)
            || $limit < 1 || $limit > 10000
            || ($reset && $grantsOnly)
            || (array_key_exists('reset-password', $args) && !$reset)
            || (array_key_exists('grants-only', $args) && !$grantsOnly)
            || (array_key_exists('clear-text-password', $args) && (!$clearText || $driver !== 'mysql' || $grantsOnly))) {
            $this->out(self::USAGE['grants'] . "\n");
            return 1;
        }
        // the statements change password, limits and settings of the NAMED role: never for the role
        // of another component (the names the plugin itself refuses to connect with) or a system role
        if (in_array($role, [...Config::DEFAULTS['galbridge_db_refused_users'], ...self::RESERVED_ROLES], true) || str_starts_with($role, 'pg_')) {
            $this->out("grants: '{$role}' is a role of another component or of the database server (galbridge_db_refused_users): choose a dedicated role name, e.g. --role=galbridge\n");
            return 1;
        }
        $file = $this->pluginDir . '/SQL/' . ($driver === 'mysql' ? 'grants.mysql.sql' : 'grants.postgres.sql');
        $template = is_file($file) ? (string) file_get_contents($file) : '';
        $placeholder = $driver === 'mysql' ? 'CHANGE_ME_PASSWORD_VERIFIER' : 'CHANGE_ME_RANDOM_PASSWORD';
        if ($template === '' || !str_contains($template, $placeholder)
            || preg_match('/^-- \[role\]/m', $template) !== 1 || preg_match('/^-- \[\/role\]/m', $template) !== 1
            || ($driver === 'mysql' && !str_contains($template, "\n" . self::VERIFIER_NOTE))) {
            $this->out("grant template not found: {$file}\n");
            return 2;
        }
        $password = bin2hex(random_bytes(20));
        // The statements carry a VERIFIER of the password, never the password: a server that writes
        // account statements to a log (PostgreSQL log_statement; the binary and slow query logs of
        // MariaDB, which keep such statements verbatim) cannot record the secret itself. The clear
        // text exists in the DSN line of the output only.
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
        $sql = rtrim(implode("\n", $lines)) . "\n";
        $dsn = $driver . '://' . $role . ':' . $password . '@' . $host . '/' . $db;

        $this->out('-- Generated by bin/galbridge.sh grants on ' . gmdate('Y-m-d H:i:s') . " UTC. Keep this output private.\n");
        $this->out(match (true) {
            $reset => "-- It gives the role '{$role}' a NEW PASSWORD, also when the role already exists: the previous password stops working at once.\n",
            $grantsOnly => "-- It re-applies limits (connection limit: {$limit}, see --connection-limit), view and grants to the EXISTING role '{$role}'; its password is not changed.\n",
            default => "-- It creates the role '{$role}'. When a role of that name already exists it stops and changes nothing (see --reset-password, --grants-only).\n",
        });
        $this->out($sql);
        if ($grantsOnly) {
            $this->out("\n-- No DSN line: the password of the role is unchanged, keep the galbridge_db_dsn you have.\n");
            return 0;
        }
        $this->out("\n-- Put this line into plugins/galbridge/config.inc.php (owner root:<web server group>, mode 0640):\n");
        $this->out("-- \$config['galbridge_db_dsn'] = '{$dsn}';\n");
        if (!in_array($host, $local, true)) {
            $this->out("-- Remote database: add TLS verification to the DSN (pgsql: ?sslmode=verify-full&sslrootcert=...; mysql: ?ca=...&verify_server_cert=1).\n");
        }
        return 0;
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
    private static function roleStatements(string $driver, bool $reset, bool $grantsOnly, string $role, string $accountHost, string $secret, bool $clearText): array
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
                // the output must never get as far as the password there. MySQL ignores this comment.
                $out[] = '-- (--clear-text-password) FOR MYSQL 8.4 OR LATER ONLY: the next statements contain the password itself.';
                $out[] = '-- MySQL rewrites passwords in its general, slow and binary logs. MariaDB does not: there the script stops here.';
                $out[] = "/*M! SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'this output is for MySQL: for MariaDB run grants without --clear-text-password' */;";
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
        return $reset ? [
            'DO $$',
            'BEGIN',
            '    ' . $exists,
            "        CREATE ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT;",
            '    END IF;',
            'END',
            '$$;',
            "ALTER ROLE {$role} WITH LOGIN PASSWORD '{$secret}';",
        ] : [
            'DO $$',
            'BEGIN',
            '    ' . $exists,
            "        RAISE EXCEPTION 'role \"{$role}\" does not exist: nothing was changed (create it without --grants-only)';",
            '    END IF;',
            'END',
            '$$;',
        ];
    }

    /**
     * The mysql_native_password verifier of a password ('*' and 40 hexadecimal digits, as stored in
     * mysql.user): MariaDB and MySQL (up to 8.0; later versions when the plugin is loaded) accept it
     * in CREATE / ALTER USER ... IDENTIFIED WITH mysql_native_password AS instead of the clear
     * text. SHA-1 is what that authentication method defines; the password is 160 random bits.
     */
    public static function mysqlVerifier(string $password): string
    {
        return '*' . strtoupper(sha1(sha1($password, true)));
    }

    /**
     * PostgreSQL SCRAM-SHA-256 verifier of a password (RFC 5803 / RFC 7677), as stored in
     * pg_authid.rolpassword: SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>. The server
     * accepts it in CREATE / ALTER ROLE ... PASSWORD instead of the clear text.
     */
    public static function scramVerifier(string $password, ?string $salt = null): string
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
        if (is_file($this->pluginDir . '/config.inc.php')) {
            $rc->config->load_from_file($this->pluginDir . '/config.inc.php');
        } else {
            $rc->config->load_from_file('galbridge.inc.php');
        }
        $cfg = new Config($rc->config, []);
        $cfg->validate();

        $this->guard('Versions', fn () => $this->versions());
        $this->guard('Configuration', fn () => $this->configuration($cfg));
        $this->guard('Installation', fn () => $this->installation($url));
        $this->guard('IMAP host binding', fn () => $this->imapHosts($cfg, $rc));
        $this->guard('Core interplay', fn () => $this->coreInterplay($cfg, $rc));

        $this->section('vmail database');
        $conn = $this->connect($cfg);
        if ($conn !== null) {
            $this->guard(null, fn () => $this->privileges($conn));
            $columns = [];
            $this->guard(null, function () use ($conn, $cfg, &$columns): void {
                $columns = $conn->visibleColumns();
                $this->columns($columns, $cfg);
            });
            $this->guard(null, fn () => $this->viewContents($conn, $columns));
            $this->guard(null, fn () => $this->dataSanity($conn));
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
            $this->check(false, 'check aborted', $e instanceof GalBridgeException ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage());
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
            $this->info('iRedMail', trim((string) strtok((string) file_get_contents('/etc/iredmail-release'), "\n")));
        }
    }

    private function configuration(Config $cfg): void
    {
        $plugins = (array) $cfg->adminCore('plugins', []);
        $this->check(in_array('galbridge', $plugins, true), 'plugin enabled', in_array('galbridge', $plugins, true) ? "\$config['plugins'] contains galbridge" : "add 'galbridge' to \$config['plugins']", true);
        $this->configFile();
        foreach ($cfg->problems() as $p) {
            $this->check(false, $p['option'], $p['message'], $p['level'] !== 'fail');
        }
        // the configuration fingerprint kept in sessions covers the DSN and is an HMAC keyed with
        // des_key: with Roundcube's published default (or none) the key is known to everybody
        $desKey = $cfg->adminCore('des_key');
        if (!is_string($desKey) || $desKey === '' || $desKey === self::ROUNDCUBE_DEFAULT_DES_KEY) {
            $this->check(false, 'des_key', (is_string($desKey) && $desKey !== '' ? "is Roundcube's published default" : 'is not set')
                . ': set a random 24-character des_key in config/config.inc.php - Roundcube encrypts session data with it, and GAL Bridge keys the configuration fingerprint in sessions (which covers the DSN) with it', true);
        }
        foreach ($cfg->envOverrides() as $name) {
            // the value is not shown: it may be the DSN
            $this->check(false, 'environment', 'ROUNDCUBE_' . strtoupper($name) . " overrides {$name} for every process with this variable (this tool included)", true);
        }
        if ($cfg->bool('galbridge_global_book') && $cfg->domainAllowList('galbridge_global_data_domains') === ['*']) {
            $this->check(false, 'global data domains', $cfg->bool('galbridge_global_allow_wildcard')
                ? "'*' is in effect: every hosted domain is listed, including tenants added later"
                : "'*' without galbridge_global_allow_wildcard = true: the global book is empty", true);
        }
        $ids = Entitlements::enabledIds($cfg);
        $this->check($ids !== [], 'enabled books', $ids !== [] ? implode(', ', $ids) : ($cfg->switchesValid() ? 'none (galbridge_domain_book / galbridge_global_book)' : 'none: a switch has an ambiguous value (see above)'));
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
                    // switch itself is read as meanwhile is its conservative value
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
            // a consequence of the marker position (README 3.3): told here, because nobody would look for it
            $this->info('autocomplete order', 'entries of other domains start with their "(domain)" marker and Roundcube sorts its suggestions by name: they are listed before unmarked names, and the first suggestion is the pre-selected one - galbridge_global_autocomplete = false keeps the global book out of autocomplete');
        }
        $this->info('viewer cache', $cfg->int('galbridge_scope_ttl', 30, 3600) . ' s (galbridge_scope_ttl): a new or removed own alias (restricted lists) or a changed column grant applies after this time; a disabled or moved mailbox loses the directory with its next request');
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
     * What lies in the plugin directory, who may change it, and - with --url - whether the web
     * server hands it out.
     */
    private function installation(string $url): void
    {
        $entries = array_values(array_diff(scandir($this->pluginDir) ?: [], ['.', '..']));
        $clone = array_values(array_intersect(self::CLONE_FILES, $entries));
        if ($clone !== []) {
            $this->check(false, 'plugin directory', 'a git clone (' . implode(', ', $clone) . '): install the release archive or remove these - tests/ holds scripts that reset databases and Dovecot and has no place on a mail server', true);
        }
        $unexpected = array_values(array_diff($entries, self::RELEASE_FILES, self::CLONE_FILES));
        if ($unexpected !== []) {
            // names only; anything an administrator leaves here (a generated grants file, a backup of
            // the configuration) can be downloadable where the web server serves plugin directories
            $shown = array_map(static fn (string $f): string => Log::sanitize($f), array_slice($unexpected, 0, 8));
            $this->check(false, 'plugin directory', 'files that are not part of the plugin: ' . implode(', ', $shown) . (count($unexpected) > 8 ? ', ...' : '') . ' - keep nothing else here (README 2.6)', true);
        }
        if ($clone === [] && $unexpected === []) {
            $this->ok('plugin directory', 'only the files of a release');
        }

        // code that the account running this tool - the PHP / web server user - can modify is code a
        // compromised web application can modify, and administrators run it (this tool)
        $uid = self::euid();
        if ($uid === null) {
            $this->info('plugin code', 'not checked: the user of this process cannot be determined (PHP has no posix extension and /proc is not available)');
        } elseif ($uid !== 0) {
            $writable = [];
            foreach (['', 'galbridge.php', 'lib', 'lib/GalBridge', 'lib/GalBridge/Cli', 'bin', 'bin/galbridge.sh', 'SQL'] as $rel) {
                $path = $this->pluginDir . ($rel === '' ? '' : '/' . $rel);
                if (file_exists($path) && is_writable($path)) {
                    $writable[] = $rel === '' ? '.' : $rel;
                }
            }
            $this->check($writable === [], 'plugin code', $writable === [] ? 'not writable by this user' : 'writable by this user (' . implode(', ', $writable) . '): make root the owner, the web server needs read access only', true);
        }

        if ($url === '') {
            $this->info('web access', 'not checked: run diag --url=https://<webmail address>/ to verify that the web server does not serve this directory (README 2.6)');
            return;
        }
        $this->webAccess($url);
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
     */
    private function webAccess(string $url): void
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
        [$status, $error, $body] = self::httpStatus($base, 131072);
        $conclusive = $status === 200 && str_contains($body, 'new rcube_webmail()');
        if ($conclusive) {
            $this->ok('web access', $base . ' is the webmail');
        } else {
            $this->check(false, 'web access', $base . ' does not answer like the Roundcube webmail (' . ($status === null ? 'no answer: ' . $error : 'HTTP ' . $status)
                . '): check the address - for another address the lines below say nothing about this installation', true);
        }
        // The web path is fixed by the NAME of the plugin (Roundcube loads plugins/<name>/<name>.php),
        // whatever the directory is called on disk: the target of a symlink or a checkout may have
        // another name, and asking for that name would report "not served" for files that are.
        $dir = self::PLUGIN_NAME;
        foreach (["plugins/{$dir}/composer.json", "static.php/plugins/{$dir}/composer.json", "plugins/{$dir}/SQL/grants.mysql.sql", "static.php/plugins/{$dir}/lib/autoload.php"] as $path) {
            [$status, $error] = self::httpStatus($base . $path);
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
    }

    /**
     * @param int $bytes how much of the body to read (0 = none)
     *
     * @return array{0: null|int, 1: string, 2: string} HTTP status (null = no answer), the reason, the start of the body
     */
    private static function httpStatus(string $url, int $bytes = 0): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'user_agent' => 'galbridge-diag'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $error = '';
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $stream = fopen($url, 'r', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            return [null, Log::sanitize((string) preg_replace('~^.*?failed to open stream: ~', '', $error)), ''];
        }
        $meta = stream_get_meta_data($stream);
        $body = $bytes > 0 ? (string) stream_get_contents($stream, $bytes) : '';
        fclose($stream);
        foreach ((array) ($meta['wrapper_data'] ?? []) as $header) {
            if (is_string($header) && preg_match('~^HTTP/\S+ (\d{3})~', $header, $m) === 1) {
                return [(int) $m[1], '', $body];
            }
        }
        return [null, 'no HTTP status line', ''];
    }

    private function imapHosts(Config $cfg, \rcube $rc): void
    {
        $imapHost = $cfg->adminCore('imap_host', $cfg->adminCore('default_host'));
        $this->info('imap_host', is_string($imapHost) ? $imapHost : (is_array($imapHost) ? 'array (' . count($imapHost) . ' entries)' : 'not set'));
        if (!empty($cfg->adminCore('include_host_config'))) {
            // this tool has no request, so it never sees the per-host files
            $this->check(false, 'include_host_config', 'enabled: imap_host and the galbridge_* options can differ per requested host name and are NOT checked here - every host configuration must name only IMAP servers that authenticate against this vmail database', true);
        }
        $allowed = ViewerResolver::allowedImapHosts($cfg);
        if ($allowed === null) {
            $this->check(false, 'directory hosts', 'plugin disabled: ' . (ViewerResolver::bindingProblem($cfg) ?? 'no usable imap_host') . ' - set galbridge_imap_hosts to the IMAP servers of this vmail database (host, host:port or unix:///path)');
            return;
        }
        $explicit = $cfg->imapHosts() !== [];
        $this->ok('directory hosts', implode(', ', $allowed) . ($explicit ? ' (galbridge_imap_hosts)' : ' (derived from imap_host)'));
        if ($explicit && (count($allowed) > 1 || !is_string($imapHost) || trim($imapHost) === '' || str_contains($imapHost, '%'))) {
            // nothing in the plugin can verify this assumption
            $this->check(false, 'directory hosts', 'several or user-chosen IMAP servers: EVERY listed server must authenticate against this vmail database - a login from another user database with the same name would get that mailbox\'s directory', true);
        }

        // the hosts Roundcube will really put into sessions must be covered
        $configured = [];
        if (is_string($imapHost) && $imapHost !== '' && !str_contains($imapHost, '%')) {
            $configured[] = $imapHost;
        } elseif (is_array($imapHost)) {
            foreach ($imapHost as $k => $v) {
                $configured[] = is_string($k) ? $k : (string) $v;
            }
        }
        foreach ($configured as $h) {
            $n = ViewerResolver::normaliseHost($h);
            if ($n !== '' && !str_contains($n, '%')) {
                $parsed = \rcube_utils::parse_host_uri(trim($h), 143, 993);
                $covered = ViewerResolver::covers($allowed, $n, (int) $parsed[2]);
                $this->check($covered, 'imap_host ' . $n, $covered ? 'covered' : 'not in galbridge_imap_hosts: logins on it get no directory', count($configured) > 1);
            }
        }
        try {
            $db = $rc->get_dbh();
            $res = $db->query('SELECT mail_host, COUNT(*) AS n FROM ' . $db->table_name('users', true) . ' GROUP BY mail_host');
            $odd = 0;
            while ($res && ($row = $db->fetch_assoc($res))) {
                // judged exactly as at runtime (the raw value, never parsed again); a value that is
                // not canonical is counted, never printed - it may carry anything a login form took
                $raw = (string) ($row['mail_host'] ?? '');
                $n = ViewerResolver::canonicalHost($raw, true);
                if ($n === null) {
                    $odd += $raw === '' ? 0 : (int) ($row['n'] ?? 0);
                } elseif (!ViewerResolver::covers($allowed, $n)) {
                    $this->check(false, 'users on ' . $n, (string) ($row['n'] ?? '?') . ' Roundcube users on this IMAP host get no directory', true);
                }
            }
            if ($odd > 0) {
                $this->check(false, 'users on other hosts', $odd . ' Roundcube users have a mail_host that is not a plain host name, IP address or socket (not shown): they get no directory - a login that typed such a server was authenticated by whatever that text named', true);
            }
        } catch (\Throwable) {
            $this->info('Roundcube users', 'not checked (Roundcube database not reachable from the CLI)');
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
    }

    private function connect(Config $cfg): ?Connection
    {
        $roundcubeDsn = $cfg->adminCore('db_dsnw', '');
        try {
            $dsn = new Dsn($cfg->string('galbridge_db_dsn'), $cfg, is_string($roundcubeDsn) ? $roundcubeDsn : null, $cfg->passwordPluginDsn());
        } catch (GalBridgeException $e) {
            $this->check(false, 'galbridge_db_dsn', $e->getMessage());
            return null;
        }
        $this->ok('DSN', $dsn->describe());
        foreach ($dsn->warnings() as $w) {
            $this->check(false, 'DSN', $w, true);
        }
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
        $started = microtime(true);
        try {
            $flavour = $conn->flavour();
        } catch (GalBridgeException) {
            $reason = $conn->lastError();
            $this->check(false, 'connect', 'cannot connect' . ($reason !== '' ? ': ' . $reason : ''));
            return null;
        }
        $this->ok('connect', $flavour . ' (' . round((microtime(true) - $started) * 1000) . ' ms)');
        foreach ($conn->sessionWarnings() as $statement) {
            $this->check(false, 'session limit', $statement . ' was refused by the server: this limit of the plugin\'s own session (statement timeout / read-only mode) is NOT in effect, only the limits of the role are', true);
        }
        return $conn;
    }

    private function configFile(): void
    {
        $candidates = [$this->pluginDir . '/config.inc.php'];
        if (defined('RCUBE_CONFIG_DIR')) {
            $candidates[] = rtrim((string) constant('RCUBE_CONFIG_DIR'), '/') . '/galbridge.inc.php';
        }
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $perms = fileperms($file) & 0o777;
            $mode = sprintf('%04o', $perms);
            $this->check(($perms & 0o007) === 0, 'config file', $file . ' (mode ' . $mode . ($perms & 0o007 ? ': readable by everyone - use 0640 root:<web server group>' : '') . ')');
            if ($perms & 0o020) {
                $this->check(false, 'config file', 'writable by its group (mode ' . $mode . '): the web server could change the directory policy - use 0640', true);
            }
            $owner = fileowner($file);
            $self = self::euid();
            if ($owner !== false && $owner !== 0) {
                // the PHP user as the owner can always rewrite the file, whatever its mode says
                $this->check(false, 'config file', 'owned by uid ' . $owner . ($owner === $self ? ' (the user running this tool, i.e. the PHP user: the web server process can change the directory policy)' : '') . ' - expected root, with the PHP group reading it (0640 root:<web server group>)', true);
            }
            $dir = dirname($file);
            $dperms = fileperms($dir) & 0o7777;
            if (($dperms & 0o022) !== 0 && ($dperms & 0o1000) === 0) {
                $this->check(false, 'config directory', $dir . ' is writable by group/others (mode ' . sprintf('%04o', $dperms) . ')', true);
            }
            return;
        }
        $this->check(false, 'config file', 'neither plugins/galbridge/config.inc.php nor config/galbridge.inc.php exists');
    }

    /**
     * The secret-column tripwire, write privileges (from the catalogs, and on MariaDB/MySQL from the
     * server's own answer to statements that can change nothing), connection limit, statement
     * logging, PostgreSQL schema/ctype.
     */
    private function privileges(Connection $conn): void
    {
        $flavour = $conn->flavour();
        // "denied" is the only good answer: any other error says nothing about the privilege
        $probe = $conn->probe('SELECT password FROM `mailbox` WHERE 1 = 0');
        $this->check($probe === 'denied', 'password column', match ($probe) {
            'denied' => 'not readable (refused by the server)',
            '' => 'READABLE: the role is too broad - create the dedicated role (galbridge.sh grants)',
            default => 'could not be checked (SQLSTATE ' . $probe . '): this is not a privilege error, so it proves nothing',
        });

        $relations = [...Connection::TABLES, ...Connection::FORBIDDEN_TABLES];
        $in = implode(', ', array_fill(0, count($relations), '?'));
        $writes = [];
        if ($flavour === 'pgsql') {
            // has_*_privilege() answers for the role with everything it inherits; views included (a
            // simple view is updatable)
            $rows = $conn->select(
                "SELECT c.relname AS t FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace WHERE ns.nspname = current_schema() AND c.relkind IN ('r', 'v', 'p', 'm', 'f') AND c.relname IN ({$in})"
                . " AND (has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') OR has_any_column_privilege(current_user, c.oid, 'INSERT,UPDATE,REFERENCES'))",
                $relations,
            );
            foreach ($rows as $row) {
                $writes[] = (string) ($row['t'] ?? '?');
            }
        } elseif ($flavour === 'mariadb' || $flavour === 'mysql') {
            // 1. the catalogs: direct grants of the account at every level - anything but SELECT, on
            //    this database also through a pattern such as `vmail%`
            $grantee = "CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')";
            $rows = $conn->select(
                "SELECT (SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = {$grantee} AND PRIVILEGE_TYPE NOT IN ('USAGE', 'SELECT'))"
                . " + (SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = {$grantee} AND DATABASE() LIKE TABLE_SCHEMA AND PRIVILEGE_TYPE <> 'SELECT')"
                . " + (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = {$grantee} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE <> 'SELECT')"
                . " + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = {$grantee} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE <> 'SELECT') AS n",
            );
            if ((int) ($rows[0]['n'] ?? 0) > 0) {
                $writes[] = (int) $rows[0]['n'] . ' grant(s) other than SELECT';
            }
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
        }
        $this->check($writes === [], 'write privileges', $writes === [] ? 'none on the vmail tables' : 'the role can modify vmail (' . implode(', ', $writes) . ') - use the read-only role from galbridge.sh grants');

        if ($flavour === 'pgsql') {
            $rows = $conn->select("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS c, current_setting('statement_timeout') AS t,"
                . ' (SELECT datctype FROM pg_database WHERE datname = current_database()) AS l,'
                . ' (SELECT rolconnlimit FROM pg_roles WHERE rolname = current_user) AS cl,'
                . " current_setting('log_min_error_statement') AS le, current_setting('log_statement') AS ls, current_setting('log_min_duration_statement') AS ld,"
                // sampled logging (PostgreSQL 12 / 13 and later; NULL on a server without the setting)
                . " (SELECT setting FROM pg_settings WHERE name = 'log_min_duration_sample') AS sd,"
                . " (SELECT setting FROM pg_settings WHERE name = 'log_statement_sample_rate') AS sr,"
                . " (SELECT setting FROM pg_settings WHERE name = 'log_transaction_sample_rate') AS tr");
            $create = in_array(strtolower((string) ($rows[0]['c'] ?? '')), ['t', 'true', '1'], true);
            $this->check(!$create, 'schema public', $create ? 'the role may CREATE in schema public (PostgreSQL < 15 default): REVOKE CREATE ON SCHEMA public FROM PUBLIC' : 'no CREATE privilege', true);
            $this->info('statement_timeout', (string) ($rows[0]['t'] ?? '?'));
            $lc = (string) ($rows[0]['l'] ?? '');
            $this->check(!in_array(strtoupper($lc), ['C', 'POSIX'], true), 'ctype', $lc . (in_array(strtoupper($lc), ['C', 'POSIX'], true) ? ' (case-insensitive search folds ASCII only)' : ''), true);
            $cl = (int) ($rows[0]['cl'] ?? -1);
            $this->check($cl >= 0, 'connection limit', $cl < 0 ? 'unlimited: set one (galbridge.sh grants --grants-only --connection-limit=N, sized to the PHP-FPM pm.max_children of Roundcube)' : $cl . ' (size it to the PHP-FPM pm.max_children of Roundcube)', true);
            // rcube_db inlines bound values, so a logged statement of this role carries search terms
            $logged = [];
            if (!in_array(strtolower((string) ($rows[0]['le'] ?? '')), ['panic', 'fatal', 'log'], true)) {
                $logged[] = 'log_min_error_statement=' . (string) ($rows[0]['le'] ?? '?') . ' (failed and cancelled statements)';
            }
            if (strtolower((string) ($rows[0]['ls'] ?? '')) !== 'none') {
                $logged[] = 'log_statement=' . (string) ($rows[0]['ls'] ?? '?');
            }
            if ((string) ($rows[0]['ld'] ?? '') !== '-1') {
                $logged[] = 'log_min_duration_statement=' . (string) ($rows[0]['ld'] ?? '?');
            }
            // a SAMPLE of the statements above a duration, or of whole transactions, is logged
            // whatever the three settings above say
            $sd = $rows[0]['sd'] ?? null;
            if ($sd !== null && (string) $sd !== '-1' && (float) ($rows[0]['sr'] ?? 1) > 0) {
                $logged[] = 'log_min_duration_sample=' . (string) $sd . ' with log_statement_sample_rate=' . (string) ($rows[0]['sr'] ?? '?');
            }
            if ((float) ($rows[0]['tr'] ?? 0) > 0) {
                $logged[] = 'log_transaction_sample_rate=' . (string) $rows[0]['tr'];
            }
            $this->check($logged === [], 'statement logging', $logged === [] ? 'off for this role (its statements contain search terms)' : 'the server log can record statements of this role with the search terms: ' . implode(', ', $logged) . ' - the grant template switches this off (galbridge.sh grants --grants-only)', true);
        } elseif ($flavour === 'mariadb' || $flavour === 'mysql') {
            $var = $flavour === 'mariadb' ? 'max_statement_time' : 'max_execution_time';
            $rows = $conn->select('SELECT @@' . $var . ' AS t, @@session.max_user_connections AS c, @@global.general_log AS g');
            $this->info($var, (string) ($rows[0]['t'] ?? '?'));
            // the session value is the effective limit of this account (0 = neither account nor server limit)
            $cl = (int) ($rows[0]['c'] ?? 0);
            $this->check($cl > 0, 'connection limit', $cl > 0 ? $cl . ' (size it to the PHP-FPM pm.max_children of Roundcube)' : 'unlimited: only the number of PHP workers bounds the connections of this account - set one (galbridge.sh grants --grants-only --connection-limit=N)', true);
            $general = in_array(strtolower((string) ($rows[0]['g'] ?? '0')), ['1', 'on'], true);
            $this->check(!$general, 'statement logging', $general ? 'the general query log is ON: it records every statement of the plugin with the search terms (SECURITY.md) - switch it off or restrict the log' : 'general query log off (the slow query log can still record statements with search terms, SECURITY.md)', true);
        }
    }

    /**
     * @param array<string, list<string>> $columns
     */
    private function columns(array $columns, Config $cfg): void
    {
        $sqlite = $this->isSqlite($columns);
        $secret = array_intersect(Connection::SECRET_COLUMNS, $columns['mailbox'] ?? []);
        $this->check($secret === [] || $sqlite, 'secret columns', $secret === [] ? 'not visible' : 'VISIBLE (' . implode(', ', $secret) . '): role too broad, the plugin refuses to run');
        foreach (Connection::FORBIDDEN_TABLES as $table) {
            $visible = ($columns[$table] ?? []) !== [];
            $this->check(!$visible || $sqlite, 'table ' . $table, !$visible
                ? 'not readable (the role reads the view ' . Schema::FORWARDINGS . ', which has no private forwarding targets)'
                : "READABLE: the role can read every row, including the private forwarding targets of all mailboxes - the plugin refuses to run. Revoke the grant on {$table} and grant the view (galbridge.sh grants --grants-only; README 5)");
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
     * What the view of the grant set really holds (not what it is called): only alias, alias-member
     * and list rows. A view of that name with any other row - created by hand, or replaced - would
     * hand the role the private forwarding targets the plugin refuses the table for.
     *
     * @param array<string, list<string>> $columns
     */
    private function viewContents(Connection $conn, array $columns): void
    {
        $visible = $columns[Schema::FORWARDINGS] ?? [];
        if (array_diff(['is_alias', 'is_list', 'is_maillist'], $visible) !== []) {
            return; // reported by the column check above
        }
        $rows = $conn->select('SELECT COUNT(*) AS n FROM `' . Schema::FORWARDINGS . '` f WHERE NOT (f.is_alias = 1 OR f.is_list = 1 OR f.is_maillist = 1)');
        $other = (int) ($rows[0]['n'] ?? 0);
        $this->check($other === 0, 'view ' . Schema::FORWARDINGS, $other === 0
            ? 'holds alias, alias-member and list rows only'
            : 'holds ' . $other . ' row(s) of another kind - private forwarding targets: re-create it from the grant set (galbridge.sh grants --grants-only)');
    }

    /**
     * @param array<string, list<string>> $columns
     */
    private function isSqlite(array $columns): bool
    {
        return in_array('password', $columns['mailbox'] ?? [], true) && defined('GALBRIDGE_ALLOW_SQLITE') && constant('GALBRIDGE_ALLOW_SQLITE') === true;
    }

    private function dataSanity(Connection $conn): void
    {
        $rows = $conn->select('SELECT COUNT(d.`domain`) AS n FROM `domain` d WHERE d.active = 1 AND d.backupmx = 0');
        $this->info('hosted domains', (string) ($rows[0]['n'] ?? '0') . ' active');
        $rows = $conn->select('SELECT COUNT(m.username) AS n FROM `mailbox` m JOIN `domain` d ON d.`domain` = m.`domain` WHERE m.active = 1 AND m.enabledeliver = 1 AND d.active = 1 AND d.backupmx = 0');
        $this->info('deliverable mailboxes', (string) ($rows[0]['n'] ?? '0'));
        $rows = $conn->select('SELECT COUNT(m.username) AS n FROM `mailbox` m WHERE m.username <> LOWER(m.username) OR m.`domain` <> LOWER(m.`domain`)');
        $upper = (int) ($rows[0]['n'] ?? 0);
        $this->check($upper === 0, 'lower-case addresses', $upper === 0 ? 'ok' : $upper . ' mailboxes with upper-case letters (not matched on PostgreSQL)', true);
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
        $summary = match (true) {
            $this->failures > 0 => 'Result: ' . $this->failures . ' problem(s) found',
            // exit code 0, but never a bare "OK" over security warnings
            $this->warnings > 0 => 'Result: OK with ' . $this->warnings . ' warning(s) - read the WARN lines',
            default => 'Result: OK',
        };
        $this->out("\n" . $summary . "\n");
        return $this->failures === 0 ? 0 : 2;
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

    private function out(string $s): void
    {
        fwrite($this->output, $s);
    }
}
