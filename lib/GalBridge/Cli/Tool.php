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
use GalBridge\Directory\Schema;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Normalizer;

/**
 * Administrator command line tool (bin/galbridge.sh).
 *
 *  diag [--user=EMAIL]   check the installation, the configuration and the vmail database; with
 *                        --user report (as counts only) what that user would see
 *  grants --driver=mysql|pgsql [--host=...] [--account-host=...] [--db=vmail] [--role=galbridge]
 *         [--connection-limit=25]
 *                        print the least-privilege role statements with a fresh random password and
 *                        the matching DSN line (stdout only - nothing is written to disk)
 *
 * It never prints the database password or directory contents (counts only). Every diag section
 * runs on its own, so one failing check cannot hide the others. Exit codes: 0 = OK, 1 = usage
 * error, 2 = problems found.
 */
final class Tool
{
    /** The only Roundcube release this plugin version supports (composer.json min-version). */
    public const SUPPORTED_ROUNDCUBE = '1.7.7';

    /** End of security support per PHP branch (https://www.php.net/supported-versions.php). */
    private const PHP_SECURITY_EOL = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];

    /** optional vmail columns GAL Bridge can use, for the INFO line about unused ones */
    private const KNOWN_OPTIONAL = [
        'mailbox' => ['first_name', 'last_name', 'department', 'telephone', 'mobile', 'birthday'],
    ];

    private int $failures = 0;

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
        $cmd = is_string($args[0] ?? null) ? $args[0] : 'help';
        switch ($cmd) {
            case 'diag':
                if ($this->rc === null) {
                    $this->out("diag needs a Roundcube installation\n");
                    return 1;
                }
                return $this->diag(is_string($args['user'] ?? null) ? $args['user'] : '');
            case 'grants':
                return $this->grants($args);
            default:
                $this->out("GAL Bridge administration tool\n\n"
                    . "Usage: plugins/galbridge/bin/galbridge.sh <command> [options]  (run from the Roundcube directory as the web server user)\n\n"
                    . "  diag [--user=EMAIL]          check the installation and the vmail database (no secrets;\n"
                    . "                               --user reports, as counts, what that user would see)\n"
                    . "  grants --driver=mysql|pgsql  print the read-only role statements with a fresh password and the\n"
                    . "                               matching DSN (stdout only; run the SQL as the database administrator)\n"
                    . "         [--host=H]            database server written into the DSN (default localhost / 127.0.0.1)\n"
                    . "         [--account-host=A]    MySQL/MariaDB: host part of the account = where Roundcube connects\n"
                    . "                               from (default localhost; patterns like 10.0.0.% allowed)\n"
                    . "         [--db=vmail] [--role=galbridge] [--connection-limit=25]\n");
                return $cmd === 'help' ? 0 : 1;
        }
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
            $limit = is_string($raw) && preg_match('/^[0-9]{1,5}$/', $raw) ? (int) $raw : 0;
        }
        if (!in_array($driver, ['mysql', 'pgsql'], true)
            || !preg_match('/^[a-z0-9_]{1,64}$/', $db)
            || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $role)
            || !preg_match('/^([a-z0-9.-]{1,253}|\[[0-9a-f:.]{2,45}\])$/', $host)   // IPv6 only in brackets
            || !preg_match('/^[a-z0-9.%_:-]{1,255}$/', $accountHost)
            || $limit < 1 || $limit > 10000) {
            $this->out("Usage: galbridge.sh grants --driver=mysql|pgsql [--host=H] [--account-host=A] [--db=vmail] [--role=galbridge] [--connection-limit=25]\n");
            return 1;
        }
        $file = $this->pluginDir . '/SQL/' . ($driver === 'mysql' ? 'grants.mysql.sql' : 'grants.postgres.sql');
        $template = is_file($file) ? (string) file_get_contents($file) : '';
        if ($template === '' || !str_contains($template, 'CHANGE_ME_RANDOM_PASSWORD')) {
            $this->out("grant template not found: {$file}\n");
            return 2;
        }
        $password = bin2hex(random_bytes(20));

        // rename only inside SQL statements - also the commented-out ones meant to be uncommented
        // (optional grants, MariaDB limits) - while prose comments and the attribution header stay
        $lines = [];
        foreach (preg_split('/\R/', $template) ?: [] as $line) {
            $statement = !str_starts_with(ltrim($line), '--')
                || preg_match('/^\s*--\s*(GRANT|REVOKE|ALTER USER|ALTER ROLE)\b/', $line) === 1;
            if ($statement) {
                $line = str_replace('CHANGE_ME_RANDOM_PASSWORD', $password, $line);
                if ($driver === 'mysql') {
                    $line = str_replace(["'galbridge'@'localhost'", 'vmail.'], ["'{$role}'@'{$accountHost}'", $db . '.'], $line);
                } else {
                    $line = (string) preg_replace('/\bgalbridge\b/', $role, $line);
                    $line = str_replace(['DATABASE vmail ', 'CONNECTION LIMIT 25'], ["DATABASE {$db} ", 'CONNECTION LIMIT ' . $limit], $line);
                }
            }
            $lines[] = $line;
        }
        $sql = rtrim(implode("\n", $lines)) . "\n";
        $local = ['localhost', '127.0.0.1', '::1', '[::1]'];
        if ($driver === 'mysql' && !in_array($accountHost, $local, true)) {
            $sql .= "-- Roundcube connects from another host: require TLS for this account\nALTER USER '{$role}'@'{$accountHost}' REQUIRE SSL;\n";
        }
        $dsn = $driver . '://' . $role . ':' . $password . '@' . $host . '/' . $db;

        $this->out('-- Generated by bin/galbridge.sh grants on ' . gmdate('Y-m-d H:i:s') . " UTC. Keep this output private.\n");
        $this->out("-- Re-running it is safe: the account keeps its grants and gets the new password below.\n");
        $this->out($sql);
        $this->out("\n-- Put this line into plugins/galbridge/config.inc.php (owner root:<web server group>, mode 0640):\n");
        $this->out("-- \$config['galbridge_db_dsn'] = '{$dsn}';\n");
        if (!in_array($host, $local, true)) {
            $this->out("-- Remote database: add TLS verification to the DSN (pgsql: ?sslmode=verify-full&sslrootcert=...; mysql: ?ca=...&verify_server_cert=1).\n");
        }
        return 0;
    }

    // ------------------------------------------------------------------ diag

    private function diag(string $user): int
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
            $this->guard(null, fn () => $this->dataSanity($conn));
            if ($user !== '') {
                $this->guard('User ' . $user, fn () => $this->userReport($cfg, $conn, $user));
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

    private function versions(): void
    {
        $rcVersion = defined('RCMAIL_VERSION') ? (string) constant('RCMAIL_VERSION') : '?';
        if ($rcVersion === self::SUPPORTED_ROUNDCUBE) {
            $this->ok('Roundcube', $rcVersion);
        } else {
            $this->check(false, 'Roundcube', $rcVersion . ' (supported and tested: ' . self::SUPPORTED_ROUNDCUBE
                . ($rcVersion === '1.7-git' ? '; a git checkout or Packagist core reports 1.7-git - make sure it is 1.7.7' : '') . ')', true);
        }
        $branch = implode('.', array_slice(explode('.', (string) phpversion()), 0, 2));
        $eol = self::PHP_SECURITY_EOL[$branch] ?? null;
        $this->check($eol !== null && $eol >= gmdate('Y-m-d'), 'PHP', PHP_VERSION . ($eol !== null ? ' (security support until ' . $eol . ')' : ' (untested branch)'), true);
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
        if ($cfg->bool('galbridge_global_book') && $cfg->domainAllowList('galbridge_global_data_domains') === ['*']) {
            $this->check(false, 'global data domains', $cfg->bool('galbridge_global_allow_wildcard')
                ? "'*' is in effect: every hosted domain is listed, including tenants added later"
                : "'*' without galbridge_global_allow_wildcard = true: the global book is empty", true);
        }
        $ids = Entitlements::enabledIds($cfg);
        $this->check($ids !== [], 'enabled books', $ids !== [] ? implode(', ', $ids) : 'none (galbridge_domain_book / galbridge_global_book)');
        if (in_array('sql_global_addressbooks', $plugins, true)) {
            $this->check(false, 'old plugin', 'sql_global_addressbooks is still enabled: remove it (docs/MIGRATION.md)', true);
        }
        foreach (['_sql_domainbook_name', '_sql_globalbook_name', '_sql_supportbook_list', '_sql_supportbook'] as $old) {
            if ($cfg->adminCore($old) !== null) {
                $this->check(false, 'old plugin option', "{$old} is still set (docs/MIGRATION.md)", true);
            }
        }
    }

    private function imapHosts(Config $cfg, \rcube $rc): void
    {
        $imapHost = $cfg->adminCore('imap_host', $cfg->adminCore('default_host'));
        $this->info('imap_host', is_string($imapHost) ? $imapHost : (is_array($imapHost) ? 'array (' . count($imapHost) . ' entries)' : 'not set'));
        $allowed = ViewerResolver::allowedImapHosts($cfg);
        if ($allowed === null) {
            $this->check(false, 'directory hosts', 'plugin disabled: imap_host is templated (%d/%n/%s), user-selectable or an array - set galbridge_imap_hosts to bare host names');
            return;
        }
        $this->ok('directory hosts', implode(', ', $allowed) . ($cfg->imapHosts() === [] ? ' (derived from imap_host)' : ' (galbridge_imap_hosts)'));

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
                $this->check(in_array($n, $allowed, true), 'imap_host ' . $n, in_array($n, $allowed, true) ? 'covered' : 'not in galbridge_imap_hosts: logins on it get no directory', count($configured) > 1);
            }
        }
        try {
            $db = $rc->get_dbh();
            $res = $db->query('SELECT mail_host, COUNT(*) AS n FROM ' . $db->table_name('users', true) . ' GROUP BY mail_host');
            while ($res && ($row = $db->fetch_assoc($res))) {
                $n = ViewerResolver::normaliseHost((string) ($row['mail_host'] ?? ''));
                if ($n !== '' && !in_array($n, $allowed, true)) {
                    $this->check(false, 'users on ' . $n, (string) ($row['n'] ?? '?') . ' Roundcube users on this IMAP host get no directory', true);
                }
            }
        } catch (\Throwable) {
            $this->info('Roundcube users', 'not checked (Roundcube database not reachable from the CLI)');
        }
    }

    private function coreInterplay(Config $cfg, \rcube $rc): void
    {
        $sortCol = (string) $rc->config->get('addressbook_sort_col', 'surname');
        $this->check($sortCol === 'name', 'addressbook_sort_col', $sortCol . ($sortCol === 'name' ? '' : " (recommended 'name': open-source iRedAdmin fills only mailbox.name; core re-sorts search results by this column)"), true);
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
            $self = function_exists('posix_geteuid') ? posix_geteuid() : null;
            if ($owner !== false && $owner !== 0 && $owner !== $self) {
                $this->check(false, 'config file', 'owned by uid ' . $owner . ' (expected root or the PHP user)', true);
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
     * Write privileges from the catalogs (a statement probe would only hit the plugin's own read-only
     * session), the secret-column tripwire, PostgreSQL schema/ctype/connection limit.
     */
    private function privileges(Connection $conn): void
    {
        $flavour = $conn->flavour();
        $state = $conn->probeFailure('SELECT password FROM `mailbox` WHERE 1 = 0');
        $this->check($state !== '', 'password column', $state !== '' ? 'not readable (SQLSTATE ' . $state . ')' : 'READABLE: the role is too broad - create the dedicated role (galbridge.sh grants)');

        $in = implode(', ', array_fill(0, count(Connection::TABLES), '?'));
        if ($flavour === 'pgsql') {
            $rows = $conn->select(
                "SELECT COUNT(*) AS n FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace WHERE ns.nspname = current_schema() AND c.relkind = 'r' AND c.relname IN ({$in})"
                . " AND (has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE') OR has_any_column_privilege(current_user, c.oid, 'INSERT,UPDATE'))",
                Connection::TABLES,
            );
        } elseif ($flavour === 'mariadb' || $flavour === 'mysql') {
            $grantee = "CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')";
            $types = "('INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP', 'CREATE', 'INDEX', 'TRIGGER')";
            $rows = $conn->select(
                "SELECT (SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = {$grantee} AND PRIVILEGE_TYPE IN {$types})"
                . " + (SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = {$grantee} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE IN {$types})"
                . " + (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = {$grantee} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE IN {$types})"
                . " + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = {$grantee} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE IN ('INSERT', 'UPDATE')) AS n",
            );
        } else {
            $rows = [['n' => '0']]; // SQLite test mirror: no privileges
        }
        $writes = (int) ($rows[0]['n'] ?? 0);
        $this->check($writes === 0, 'write privileges', $writes === 0 ? 'none on the vmail tables' : 'the role can modify vmail (' . $writes . ' grant(s)) - use the read-only role from galbridge.sh grants');

        if ($flavour === 'pgsql') {
            $rows = $conn->select("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS c, current_setting('statement_timeout') AS t,"
                . ' (SELECT datctype FROM pg_database WHERE datname = current_database()) AS l,'
                . ' (SELECT rolconnlimit FROM pg_roles WHERE rolname = current_user) AS cl');
            $create = in_array(strtolower((string) ($rows[0]['c'] ?? '')), ['t', 'true', '1'], true);
            $this->check(!$create, 'schema public', $create ? 'the role may CREATE in schema public (PostgreSQL < 15 default): REVOKE CREATE ON SCHEMA public FROM PUBLIC' : 'no CREATE privilege', true);
            $this->info('statement_timeout', (string) ($rows[0]['t'] ?? '?'));
            $lc = (string) ($rows[0]['l'] ?? '');
            $this->check(!in_array(strtoupper($lc), ['C', 'POSIX'], true), 'ctype', $lc . (in_array(strtoupper($lc), ['C', 'POSIX'], true) ? ' (case-insensitive search folds ASCII only)' : ''), true);
            $cl = (int) ($rows[0]['cl'] ?? -1);
            $this->info('connection limit', $cl < 0 ? 'unlimited' : $cl . ' (size it to the PHP-FPM pm.max_children of Roundcube)');
        } elseif ($flavour === 'mariadb' || $flavour === 'mysql') {
            $var = $flavour === 'mariadb' ? 'max_statement_time' : 'max_execution_time';
            $rows = $conn->select('SELECT @@' . $var . ' AS t');
            $this->info($var, (string) ($rows[0]['t'] ?? '?'));
        }
    }

    /**
     * @param array<string, list<string>> $columns
     */
    private function columns(array $columns, Config $cfg): void
    {
        $secret = array_intersect(Connection::SECRET_COLUMNS, $columns['mailbox'] ?? []);
        $this->check($secret === [] || $this->isSqlite($columns), 'secret columns', $secret === [] ? 'not visible' : 'VISIBLE (' . implode(', ', $secret) . '): role too broad, the plugin refuses to run');
        foreach (Schema::required($cfg) as $table => $cols) {
            $missing = array_diff($cols, $columns[$table] ?? []);
            $this->check($missing === [], 'columns ' . $table, $missing === [] ? 'ok' : 'missing or not granted: ' . implode(', ', $missing));
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
        if (is_string($vis) && $vis !== '') {
            $this->check(in_array($vis, $columns['mailbox'] ?? [], true), 'visibility column', $vis . (in_array($vis, $columns['mailbox'] ?? [], true) ? '' : ' is not readable: every book is empty (fail closed)'));
        }
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
        foreach (['mailbox' => 'domain', 'forwardings' => 'forwarding'] as $table => $column) {
            if ($flavour === 'pgsql') {
                $rows = $conn->select('SELECT COUNT(*) AS n FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexdef LIKE ?', [$table, '%(' . $column . ')%']);
            } elseif ($flavour === 'sqlite') {
                continue;
            } else {
                $rows = $conn->select('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1', [$table, $column]);
            }
            $n = (int) ($rows[0]['n'] ?? 0);
            $this->check($n > 0, 'index ' . $table . '(' . $column . ')', $n > 0 ? 'present' : 'not found (or not visible to this role)' . ($table === 'forwardings' ? ' - iRedMail update/1.6.3 adds it' : ''), true);
        }
    }

    private function userReport(Config $cfg, Connection $conn, string $user): void
    {
        $login = Normalizer::address($user);
        if ($login === null) {
            $this->check(false, 'login', 'not a valid address');
            return;
        }
        $resolver = new ViewerResolver($cfg, static fn (): Connection => $conn, 0, $login, '', '');
        $viewer = $resolver->lookup($login);
        if ($viewer === null) {
            $this->check(false, 'mailbox', 'not found, not active or not usable (status: ' . $resolver->status() . ')');
            return;
        }
        $this->ok('home domain', $viewer->home);
        $this->info('addresses', count($viewer->addresses) . ' (login + per-user aliases, used for list policies)');
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
        $this->out("\n" . ($this->failures === 0 ? 'Result: OK' : 'Result: ' . $this->failures . ' problem(s) found') . "\n");
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
        if (!$cond && !$warnOnly) {
            $this->failures++;
        }
        $this->out(sprintf("  [%s] %-34s %s\n", $cond ? ' OK ' : ($warnOnly ? 'WARN' : 'FAIL'), $what, $value));
    }

    private function out(string $s): void
    {
        fwrite($this->output, $s);
    }
}
