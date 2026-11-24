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
use GalBridge\Directory\Viewer;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Normalizer;

/**
 * Administrator command line tool (bin/galbridge.sh).
 *
 *  diag [--user=EMAIL]   check the installation, the configuration and the vmail database; with
 *                        --user report (as counts only) what that user would see
 *  grants --driver=mysql|pgsql [--host=localhost] [--db=vmail] [--role=galbridge]
 *                        print the least-privilege role statements with a fresh random password and
 *                        the matching DSN line (stdout only - nothing is written to disk)
 *
 * It never prints the database password or directory contents (counts only). Exit codes: 0 = OK,
 * 1 = usage error, 2 = problems found.
 */
final class Tool
{
    /** The only Roundcube release this plugin version supports (composer.json min-version). */
    public const SUPPORTED_ROUNDCUBE = '1.7.7';

    /** End of security support per PHP branch (https://www.php.net/supported-versions.php). */
    private const PHP_SECURITY_EOL = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];

    /** columns GAL Bridge reads (required ones FAIL when missing, optional ones WARN) */
    private const REQUIRED_COLUMNS = [
        'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver', 'expired'],
        'domain' => ['domain', 'active', 'backupmx', 'expired'],
        'forwardings' => ['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'],
    ];

    private const OPTIONAL_COLUMNS = [
        'mailbox' => ['first_name', 'last_name', 'department', 'telephone', 'mobile', 'birthday'],
        'alias' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'moderators' => ['address', 'moderator'],
        'maillist_owners' => ['address', 'owner'],
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
                    . "         [--host=localhost] [--db=vmail] [--role=galbridge]\n"
                    . "                               matching DSN (stdout only; run the SQL as the database administrator)\n");
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
        $host = is_string($args['host'] ?? null) ? $args['host'] : 'localhost';
        $db = is_string($args['db'] ?? null) ? $args['db'] : 'vmail';
        $role = is_string($args['role'] ?? null) ? $args['role'] : 'galbridge';
        if (!in_array($driver, ['mysql', 'pgsql'], true)
            || !preg_match('/^[a-z0-9_]{1,64}$/', $db)
            || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $role)
            || !preg_match('/^([a-z0-9.-]{1,253}|[0-9a-f:.]{2,45}|%)$/i', $host)) {
            $this->out("Usage: galbridge.sh grants --driver=mysql|pgsql [--host=localhost] [--db=vmail] [--role=galbridge]\n");
            return 1;
        }
        $file = $this->pluginDir . '/SQL/' . ($driver === 'mysql' ? 'grants.mysql.sql' : 'grants.postgres.sql');
        $sql = is_file($file) ? (string) file_get_contents($file) : '';
        if ($sql === '' || !str_contains($sql, 'CHANGE_ME_RANDOM_PASSWORD')) {
            $this->out("grant template not found: {$file}\n");
            return 2;
        }
        $password = bin2hex(random_bytes(20));
        $sql = str_replace('CHANGE_ME_RANDOM_PASSWORD', $password, $sql);
        if ($driver === 'mysql') {
            $sql = str_replace(["'galbridge'@'localhost'", 'vmail.'], ["'{$role}'@'{$host}'", $db . '.'], $sql);
            $dsnHost = $host === '%' || $host === 'localhost' ? 'localhost' : $host;
            $dsn = "mysql://{$role}:{$password}@{$dsnHost}/{$db}";
        } else {
            $sql = (string) preg_replace('/\bgalbridge\b/', $role, $sql);
            $sql = str_replace(['DATABASE vmail ', '-d vmail'], ["DATABASE {$db} ", "-d {$db}"], $sql);
            $dsnHost = $host === 'localhost' ? '127.0.0.1' : $host;
            $dsn = "pgsql://{$role}:{$password}@{$dsnHost}/{$db}";
        }
        $this->out('-- Generated by bin/galbridge.sh grants on ' . gmdate('Y-m-d H:i:s') . " UTC. Keep this output private.\n");
        $this->out($sql);
        $this->out("\n-- Put this line into plugins/galbridge/config.inc.php (owner root:<web server group>, mode 0640):\n");
        $this->out("-- \$config['galbridge_db_dsn'] = '{$dsn}';\n");
        if (!in_array($dsnHost, ['localhost', '127.0.0.1', '::1'], true)) {
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

        $this->section('Versions');
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

        $this->section('Configuration');
        $plugins = (array) $cfg->adminCore('plugins', []);
        $this->check(in_array('galbridge', $plugins, true), 'plugin enabled', in_array('galbridge', $plugins, true) ? "\$config['plugins'] contains galbridge" : "add 'galbridge' to \$config['plugins']", true);
        $this->configFile();
        foreach ($cfg->problems() as $p) {
            $this->check(false, $p['option'], $p['message'], $p['level'] !== 'fail');
        }
        if ($cfg->bool('galbridge_global_book') && $cfg->domainAllowList('galbridge_global_data_domains') === ['*']) {
            $wild = $cfg->bool('galbridge_global_allow_wildcard');
            $this->check($wild, 'global data domains', $wild ? "'*' = every hosted domain, including tenants added later" : "'*' without galbridge_global_allow_wildcard = true: the global book is empty", true);
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

        $this->section('IMAP host binding');
        $imapHost = $cfg->adminCore('imap_host', $cfg->adminCore('default_host'));
        $this->info('imap_host', is_string($imapHost) ? $imapHost : (is_array($imapHost) ? 'array (' . count($imapHost) . ' entries)' : 'not set'));
        $allowed = ViewerResolver::allowedImapHosts($cfg);
        $this->check($allowed !== null, 'directory hosts', $allowed !== null
            ? implode(', ', $allowed) . ($cfg->imapHosts() === [] ? ' (derived from imap_host)' : ' (galbridge_imap_hosts)')
            : 'plugin disabled: imap_host is templated (%d/%n/%s), user-selectable or an array - set galbridge_imap_hosts to bare host names');

        $this->section('Core interplay');
        $sortCol = (string) $rc->config->get('addressbook_sort_col', 'surname');
        $this->check($sortCol === 'name', 'addressbook_sort_col', $sortCol . ($sortCol === 'name' ? '' : " (recommended 'name': open-source iRedAdmin fills only mailbox.name; core re-sorts search results by this column)"), true);
        $ldap = (array) $rc->config->get('ldap_public', []);
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            if (array_key_exists($id, $ldap)) {
                $this->check(false, 'source id collision', "ldap_public['{$id}'] shadows the GAL Bridge book of the same id");
            }
        }
        $limits = Limits::fromConfig($cfg);
        $this->info('group expansion cap', (string) $limits->groupCap . ' members (min of galbridge_max_group_members and max_group_members)');
        $this->info('autocomplete minimum', $cfg->int('galbridge_min_search_length', 1, 10) . ' characters (raised in compose when the core value is lower)');

        $this->section('vmail database');
        $roundcubeDsn = $cfg->adminCore('db_dsnw', '');
        try {
            $dsn = new Dsn($cfg->string('galbridge_db_dsn'), $cfg, is_string($roundcubeDsn) ? $roundcubeDsn : null, $cfg->passwordPluginDsn());
        } catch (GalBridgeException $e) {
            $this->check(false, 'galbridge_db_dsn', $e->getMessage());
            return $this->result();
        }
        $this->ok('DSN', $dsn->describe());
        foreach ($dsn->warnings() as $w) {
            $this->check(false, 'DSN', $w, true);
        }
        $ext = $dsn->driver() === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';
        $this->check(extension_loaded($ext), 'PHP extension', $ext . (extension_loaded($ext) ? '' : ' is missing'));
        $this->check(extension_loaded('intl'), 'PHP extension', 'intl' . (extension_loaded('intl') ? '' : ' is missing (IDN domains and Unicode normalisation)'), true);

        $conn = new Connection($dsn, $cfg->int('galbridge_db_timeout', 1, 30));
        $started = microtime(true);
        try {
            $flavour = $conn->flavour();
        } catch (GalBridgeException $e) {
            $this->check(false, 'connect', 'cannot connect (see the Roundcube errors log for the driver message)');
            return $this->result();
        }
        $this->ok('connect', $flavour . ' (' . round((microtime(true) - $started) * 1000) . ' ms)');
        try {
            $this->privileges($conn, $flavour);
            $columns = $conn->visibleColumns();
            $this->columns($columns, $cfg);
            $this->dataSanity($conn, $flavour);
            if ($user !== '') {
                $this->userReport($cfg, $conn, $user);
            }
        } catch (GalBridgeException $e) {
            $this->check(false, 'query', $e->getMessage());
        }
        return $this->result();
    }

    private function configFile(): void
    {
        $candidates = [$this->pluginDir . '/config.inc.php'];
        if (defined('RCUBE_CONFIG_DIR')) {
            $candidates[] = rtrim((string) constant('RCUBE_CONFIG_DIR'), '/') . '/galbridge.inc.php';
        }
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $perms = fileperms($file) & 0o777;
                $this->check(($perms & 0o007) === 0, 'config file', $file . ' (mode ' . sprintf('%04o', $perms) . ($perms & 0o007 ? ': readable by everyone - use 0640 root:<web server group>' : '') . ')');
                return;
            }
        }
        $this->check(false, 'config file', 'neither plugins/galbridge/config.inc.php nor config/galbridge.inc.php exists');
    }

    private function privileges(Connection $conn, string $flavour): void
    {
        $state = $conn->probeFailure('SELECT password FROM `mailbox` WHERE 1 = 0');
        $this->check($state !== '', 'password column', $state !== '' ? 'not readable (SQLSTATE ' . $state . ')' : 'READABLE: the role is too broad - create the dedicated role (galbridge.sh grants)');
        $state = $conn->probeFailure('UPDATE `mailbox` SET name = name WHERE 1 = 0');
        $this->check($state !== '', 'write access', $state !== '' ? 'denied (SQLSTATE ' . $state . ')' : 'ALLOWED: the role can modify vmail - use a read-only role');
        if ($flavour === 'pgsql') {
            $rows = $conn->select("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS c, current_setting('statement_timeout') AS t, current_setting('lc_ctype') AS l");
            $create = in_array(strtolower((string) ($rows[0]['c'] ?? '')), ['t', 'true', '1'], true);
            $this->check(!$create, 'schema public', $create ? 'the role may CREATE in schema public (PostgreSQL < 15 default): REVOKE CREATE ON SCHEMA public FROM PUBLIC' : 'no CREATE privilege', true);
            $this->info('statement_timeout', (string) ($rows[0]['t'] ?? '?'));
            $lc = (string) ($rows[0]['l'] ?? '');
            $this->check(!in_array(strtoupper($lc), ['C', 'POSIX'], true), 'lc_ctype', $lc . (in_array(strtoupper($lc), ['C', 'POSIX'], true) ? ' (case-insensitive search folds ASCII only)' : ''), true);
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
        $this->check($secret === [], 'secret columns', $secret === [] ? 'not visible' : 'VISIBLE (' . implode(', ', $secret) . '): role too broad, the plugin refuses to run');
        foreach (self::REQUIRED_COLUMNS as $table => $cols) {
            $missing = array_diff($cols, $columns[$table] ?? []);
            $this->check($missing === [], 'columns ' . $table, $missing === [] ? 'ok' : 'missing or not granted: ' . implode(', ', $missing));
        }
        foreach (self::OPTIONAL_COLUMNS as $table => $cols) {
            $missing = array_diff($cols, $columns[$table] ?? []);
            $hint = $table === 'mailbox' ? ' (iRedMail update/1.7.3 adds first_name, last_name, mobile, telephone, birthday; or grant them)' : ' (needed for alias accounts / mailing lists)';
            $this->check($missing === [], 'optional columns ' . $table, $missing === [] ? 'ok' : 'missing or not granted: ' . implode(', ', $missing) . $hint, true);
        }
        $vis = $cfg->visibilityColumn();
        if (is_string($vis) && $vis !== '') {
            $this->check(in_array($vis, $columns['mailbox'] ?? [], true), 'visibility column', $vis . (in_array($vis, $columns['mailbox'] ?? [], true) ? '' : ' is not readable: every book is empty (fail closed)'));
        }
    }

    private function dataSanity(Connection $conn, string $flavour): void
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
        $this->indexes($conn, $flavour);
    }

    private function indexes(Connection $conn, string $flavour): void
    {
        $want = ['mailbox' => 'domain', 'forwardings' => 'forwarding'];
        foreach ($want as $table => $column) {
            if ($flavour === 'pgsql') {
                $rows = $conn->select('SELECT COUNT(*) AS n FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexdef LIKE ?', [$table, '%(' . $column . ')%']);
            } else {
                $rows = $conn->select('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1', [$table, $column]);
            }
            $n = (int) ($rows[0]['n'] ?? 0);
            $this->check($n > 0, 'index ' . $table . '(' . $column . ')', $n > 0 ? 'present' : 'not found (or not visible to this role)' . ($table === 'forwardings' ? ' - iRedMail update/1.6.3 adds it' : ''), true);
        }
    }

    private function userReport(Config $cfg, Connection $conn, string $user): void
    {
        $this->section('User ' . $user);
        $login = Normalizer::address($user);
        if ($login === null) {
            $this->check(false, 'login', 'not a valid address');
            return;
        }
        $resolver = new ViewerResolver($cfg, static fn (): Connection => $conn, 0, $login, '', '');
        $viewer = $resolver->lookup($login);
        if ($viewer === null) {
            $this->check(false, 'mailbox', 'not found or not active (status: ' . $resolver->status() . ')');
            return;
        }
        $this->ok('home domain', $viewer->home);
        $this->info('addresses', (string) count($viewer->addresses) . ' (login + per-user aliases, used for list policies)');
        $books = Entitlements::books($viewer, $cfg, static fn (string $l): string => $l);
        if ($books === []) {
            $this->check(false, 'books', 'none for this user (viewer lists, exclusions, empty data scope or a disabled deny-list)', true);
            return;
        }
        $fields = FieldMap::fromConfig($cfg, $viewer);
        foreach ($books as $id => $book) {
            $scope = $book->allDomains ? 'all hosted domains' . ($book->excludeDomains !== [] ? ' except ' . count($book->excludeDomains) : '') : count($book->domains) . ' domain(s)';
            $this->ok($id, $scope . ', groups: ' . $book->grouping . ', search-only: ' . var_export($book->searchonly, true));
            $queries = $this->queries($book, $viewer, $fields, $cfg, $conn);
            foreach ($queries->kinds() as $kind) {
                $label = [QueryBuilder::KIND_PERSON => 'persons', QueryBuilder::KIND_ALIAS => 'alias accounts', QueryBuilder::KIND_LIST => 'mailing lists'][$kind] ?? $kind;
                $where = $queries->root($kind);
                $started = microtime(true);
                $rows = $conn->select('SELECT COUNT(*) AS n FROM ' . $queries->from($kind) . ' WHERE ' . $where->sql, $where->params);
                $ms = (int) round((microtime(true) - $started) * 1000);
                $this->info('  ' . $label, (string) ($rows[0]['n'] ?? '0') . " ({$ms} ms)");
            }
        }
    }

    private function queries(BookDefinition $book, Viewer $viewer, FieldMap $fields, Config $cfg, Connection $conn): QueryBuilder
    {
        return new QueryBuilder(
            driver: $conn->driver(),
            book: $book,
            viewer: $viewer,
            fields: $fields,
            hideExpired: $cfg->bool('galbridge_hide_expired'),
            now: gmdate('Y-m-d H:i:s'),
            hiddenAddresses: $cfg->hiddenAddresses() ?? [],
            hiddenLocalparts: $cfg->hiddenLocalparts() ?? [],
            visibilityColumn: (string) $cfg->visibilityColumn(),
            includeAliases: $cfg->bool('galbridge_include_aliases'),
            includeLists: $cfg->bool('galbridge_include_mailing_lists'),
            listPolicy: $cfg->choice('galbridge_list_policy', Config::LIST_POLICIES),
            minLength: $cfg->int('galbridge_min_search_length', 1, 10),
            maxLength: $cfg->int('galbridge_max_search_length', 16, 255),
        );
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
