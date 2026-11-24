<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/galbridge.sh (GalBridge\Cli\Tool), the administrator tool the README recommends for creating
 * the read-only role and for checking an installation:
 *
 *  - grants: argument validation (odd roles, databases and hosts are refused, '%' is never a
 *    database server), the output (the shipped template with a fresh password; renames only inside
 *    SQL statements, so the attribution header and the comments stay as shipped; the database
 *    server in the DSN is independent of the MySQL account host; a non-local account host gets
 *    REQUIRE SSL), and the output APPLIED to the throw-away MariaDB and PostgreSQL servers as a new
 *    role: the printed DSN works, only the granted columns are readable, SELECT * and UPDATE are
 *    denied, and re-running a fresh output resets the password so that its DSN works again;
 *  - diag: run as Roundcube runs it (php bin/galbridge.sh with ROUNDCUBE_CONFIG_DIR pointing to a
 *    temporary configuration), against the fixture role and against temporary roles that are too
 *    broad (write privileges), too narrow (missing optional or required columns), with malformed
 *    deny-lists, unsafe configuration files, the '*' wildcard, IMAP hosts that galbridge_imap_hosts
 *    does not cover and an unreachable server. Exit codes: 0 = OK (WARN lines allowed), 1 = usage,
 *    2 = problems.
 *
 * Extra roles are created and dropped ONLY on the throw-away *test* databases, through the
 * administrator access of tests/fixtures/vmail/load.sh (MYSQL_ADMIN / PSQL_ADMIN); every role is
 * dropped in tearDown. Temporary configuration directories are removed in tearDown.
 *
 * @license GPL-3.0-or-later
 */
final class CliToolTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    /** columns granted by SQL/grants.*.sql (the optional personal-data grant stays commented out) */
    private const TEMPLATE_COLUMNS = [
        'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver', 'expired', 'department', 'first_name', 'last_name'],
        'domain' => ['domain', 'active', 'backupmx', 'expired'],
        'forwardings' => ['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'],
        'alias' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'moderators' => ['address', 'moderator'],
        'maillist_owners' => ['address', 'owner'],
    ];

    /** the line diag adds for a non-local MySQL account host */
    private const TLS_COMMENT = '-- Roundcube connects from another host: require TLS for this account';

    /** @var array<string, null|list<string>> engine => working admin command (null = unavailable) */
    private static array $adminCommand = [];

    /** @var list<array{string, string, string}> roles to drop: [engine, db, role] */
    private array $roles = [];

    /** temporary directory of the test ('' = none) */
    private string $base = '';

    protected function setUp(): void
    {
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
    }

    protected function tearDown(): void
    {
        foreach ($this->roles as [$engine, $db, $role]) {
            self::dropRole($engine, $db, $role);
        }
        $this->roles = [];
        if ($this->base !== '') {
            self::rmTree($this->base);
            $this->base = '';
        }
        $_SESSION = [];
    }

    // ------------------------------------------------------------------ help

    public function testHelpUnknownCommandsAndDiagWithoutRoundcube(): void
    {
        [$code, $help] = self::tool([]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Usage: plugins/galbridge/bin/galbridge.sh <command>', $help);
        foreach (['diag [--user=EMAIL]', 'grants --driver=mysql|pgsql', '[--host=H]', 'database server written into the DSN', '[--account-host=A]', 'patterns like 10.0.0.% allowed', '--connection-limit=25'] as $text) {
            self::assertStringContainsString($text, $help);
        }
        self::assertSame([0, $help], self::tool(['help']));
        self::assertSame([1, $help], self::tool(['frobnicate']), 'an unknown command is a usage error');

        [$code, $out] = self::tool(['diag']);
        self::assertSame(1, $code);
        self::assertSame("diag needs a Roundcube installation\n", $out);
    }

    // ------------------------------------------------------------------ grants: arguments

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidGrantArguments(): array
    {
        return [
            'no driver' => [[]],
            'unknown driver' => [['driver' => 'sqlite']],
            'driver without a value' => [['driver' => true]],
            'role in upper case' => [['driver' => 'mysql', 'role' => 'GalBridge']],
            'role with a dash' => [['driver' => 'pgsql', 'role' => 'gal-bridge']],
            'role starting with a digit' => [['driver' => 'pgsql', 'role' => '1gal']],
            'role longer than 32 characters' => [['driver' => 'mysql', 'role' => 'g' . str_repeat('a', 32)]],
            'role with SQL' => [['driver' => 'mysql', 'role' => "gal'; DROP USER root; --"]],
            'empty role' => [['driver' => 'pgsql', 'role' => '']],
            'database with a dot' => [['driver' => 'mysql', 'db' => 'vmail.mailbox']],
            'database with a quote' => [['driver' => 'pgsql', 'db' => "vmail'"]],
            'database longer than 64 characters' => [['driver' => 'pgsql', 'db' => str_repeat('v', 65)]],
            'pgsql: host pattern %' => [['driver' => 'pgsql', 'host' => '%']],
            'mysql: host pattern % (the database server, not the account)' => [['driver' => 'mysql', 'host' => '%']],
            'host with a space' => [['driver' => 'pgsql', 'host' => 'db example']],
            'host with a slash' => [['driver' => 'mysql', 'host' => 'db/vmail']],
            'host with credentials' => [['driver' => 'mysql', 'host' => 'root@db.example.com']],
            'host with a quote' => [['driver' => 'pgsql', 'host' => "db'.example.com"]],
            'empty host' => [['driver' => 'pgsql', 'host' => '']],
            'empty account host' => [['driver' => 'mysql', 'account-host' => '']],
            'account host with a quote' => [['driver' => 'mysql', 'account-host' => "x'@'%"]],
            'account host with a space' => [['driver' => 'mysql', 'account-host' => 'a b']],
            'account host with brackets' => [['driver' => 'mysql', 'account-host' => '[::1]']],
            'connection limit 0' => [['driver' => 'pgsql', 'connection-limit' => '0']],
            'connection limit above 10000' => [['driver' => 'pgsql', 'connection-limit' => '10001']],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('invalidGrantArguments')]
    public function testInvalidGrantArgumentsAreRefused(array $args): void
    {
        [$code, $out] = self::tool(['grants'] + $args);
        self::assertSame(1, $code, $out);
        self::assertStringStartsWith('Usage: galbridge.sh grants --driver=mysql|pgsql', $out);
        self::assertStringNotContainsString('GRANT', $out, 'nothing is printed before the refusal');
        self::assertStringNotContainsString('galbridge_db_dsn', $out);
    }

    /**
     * A connection limit that is not a number is a typo, not a request for the default: an
     * administrator who typed --connection-limit=1OO must not silently get CONNECTION LIMIT 25.
     *
     * @return array<string, array{string}>
     */
    public static function malformedConnectionLimits(): array
    {
        return ['letter O for zero' => ['1OO'], 'negative' => ['-5'], 'empty' => ['']];
    }

    #[DataProvider('malformedConnectionLimits')]
    public function testMalformedConnectionLimitIsRefused(string $limit): void
    {
        [$code, $out] = self::tool(['grants', 'driver' => 'pgsql', 'connection-limit' => $limit]);
        self::assertSame(1, $code, 'accepted --connection-limit=' . $limit . ' and printed: ' . (preg_match('/CONNECTION LIMIT \d+/', $out, $m) ? $m[0] : $out));
        self::assertStringNotContainsString('CONNECTION LIMIT', $out);
    }

    /**
     * --host is written into the DSN: an IPv6 literal must come out bracketed (or be refused) -
     * "pgsql://role:pw@2001:db8::5/vmail" names host "2001", and "@::1/" no host at all.
     *
     * @return array<string, array{string, string}>
     */
    public static function ipv6Hosts(): array
    {
        $out = [];
        foreach (['mysql', 'pgsql'] as $driver) {
            foreach (['[::1]', '[2001:db8::5]', '::1', '2001:db8::5', '[::1'] as $host) {
                $out["{$driver}: {$host}"] = [$driver, $host];
            }
        }
        return $out;
    }

    #[DataProvider('ipv6Hosts')]
    public function testIpv6HostGivesAUsableDsnOrIsRefused(string $driver, string $host): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs rcube_db::parse_dsn (GALBRIDGE_RC)');
        }
        [$code, $out] = self::tool(['grants', 'driver' => $driver, 'host' => $host]);
        if ($code === 1) {
            self::assertStringStartsWith('Usage:', $out);
            return;
        }
        self::assertSame(0, $code, $out);
        $dsn = self::dsnOf($out);
        $printedHost = (string) preg_replace('~^[a-z]+://[^@]*@(.*)/[^/]*$~', '$1', $dsn);
        self::assertSame(substr_count($printedHost, '['), substr_count($printedHost, ']'), 'unbalanced brackets in the printed DSN host: ' . $printedHost);
        self::assertSame(trim($host, '[]'), (string) (\rcube_db::parse_dsn($dsn)['hostspec'] ?? ''), 'the printed DSN names another server than --host=' . $host . ': ' . self::masked($dsn));
    }

    // ------------------------------------------------------------------ grants: output

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /**
     * Without options the output IS the shipped template: comments untouched, statements identical
     * apart from the fresh password; the DSN uses the documented defaults.
     */
    #[DataProvider('drivers')]
    public function testDefaultsPrintTheShippedTemplateWithAFreshPassword(string $driver): void
    {
        [$code, $out] = self::tool(['grants', 'driver' => $driver]);
        self::assertSame(0, $code, $out);
        $lines = explode("\n", $out);
        self::assertMatchesRegularExpression('/^-- Generated by bin\/galbridge\.sh grants on \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC\. Keep this output private\.$/', $lines[0]);
        self::assertSame('-- Re-running it is safe: the account keeps its grants and gets the new password below.', $lines[1]);

        $password = self::passwordOf($out);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $password);
        $expected = [];
        foreach (self::templateLines($driver) as $line) {
            $expected[] = str_starts_with(ltrim($line), '--') ? $line : str_replace('CHANGE_ME_RANDOM_PASSWORD', $password, $line);
        }
        self::assertSame(rtrim(implode("\n", $expected)), self::body($out));

        self::assertSame($driver === 'mysql' ? "mysql://galbridge:{$password}@localhost/vmail" : "pgsql://galbridge:{$password}@127.0.0.1/vmail", self::dsnOf($out));
        self::assertStringContainsString("\n-- Put this line into plugins/galbridge/config.inc.php (owner root:<web server group>, mode 0640):\n", $out);
        self::assertStringNotContainsString('Remote database', $out, 'a local server needs no TLS hint');
        self::assertStringNotContainsString('REQUIRE SSL;', $out);
        foreach (self::statements($out) as $statement) {
            self::assertStringNotContainsString('CHANGE_ME_RANDOM_PASSWORD', $statement);
        }
        // the password is set on every run (CREATE ... IF NOT EXISTS keeps an old one): CREATE USER +
        // ALTER USER + DSN for MySQL, ALTER ROLE + DSN for PostgreSQL
        self::assertSame($driver === 'mysql' ? 3 : 2, substr_count($out, $password));
        self::assertMatchesRegularExpression($driver === 'mysql'
            ? "/^ALTER USER 'galbridge'@'localhost' IDENTIFIED BY '{$password}';$/m"
            : "/^ALTER ROLE galbridge WITH LOGIN PASSWORD '{$password}' CONNECTION LIMIT 25;$/m", $out);

        // personal data is never granted by default: the statement stays a comment
        self::assertDoesNotMatchRegularExpression('/^GRANT SELECT \([^)]*(telephone|mobile|birthday)/m', $out);
        self::assertMatchesRegularExpression('/^-- GRANT SELECT \(telephone, mobile, birthday\) ON /m', $out);
        self::assertMatchesRegularExpression('/^GRANT SELECT \(first_name, last_name\) ON /m', $out);

        // a fresh password on every run
        [, $again] = self::tool(['grants', 'driver' => $driver]);
        self::assertNotSame($password, self::passwordOf($again));
    }

    /**
     * Custom role, database and hosts are applied to SQL statements only: the GPL section 7
     * attribution header and every comment stay exactly as shipped.
     */
    #[DataProvider('drivers')]
    public function testCustomNamesAreAppliedToStatementsOnly(string $driver): void
    {
        $args = ['grants', 'driver' => $driver, 'role' => 'acme_gal', 'db' => 'maildb', 'host' => 'db.example.com'];
        if ($driver === 'mysql') {
            $args['account-host'] = '10.0.0.%';
        } else {
            $args['connection-limit'] = '40';
        }
        [$code, $out] = self::tool($args);
        self::assertSame(0, $code, $out);
        $password = self::passwordOf($out);
        $body = explode("\n", self::body($out));

        // the attribution header, verbatim, at the top of the template part
        self::assertSame([
            '-- Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski',
            '-- Original author: Marek Królikowski (TaKeN)',
            '-- Original project: https://github.com/takenek/galbridge',
            '-- SPDX-License-Identifier: GPL-3.0-or-later',
            '-- See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.',
        ], array_slice($body, 0, 5));
        // every comment of the template, unchanged and in order
        self::assertSame(self::comments(self::templateLines($driver)), array_values(array_diff(self::comments($body), [self::TLS_COMMENT])));

        $statements = self::statements($out);
        self::assertCount(count(self::statements(implode("\n", self::templateLines($driver)))) + ($driver === 'mysql' ? 1 : 0), $statements);
        foreach ($statements as $statement) {
            self::assertStringNotContainsString('CHANGE_ME_RANDOM_PASSWORD', $statement);
            if ($driver === 'mysql') {
                self::assertStringNotContainsString("'galbridge'@", $statement);
                self::assertStringNotContainsString('vmail.', $statement);
                if (str_starts_with($statement, 'GRANT ')) {
                    self::assertMatchesRegularExpression("/ ON maildb\\.[a-z_]+ TO 'acme_gal'@'10\\.0\\.0\\.%';$/", $statement);
                }
            } else {
                self::assertDoesNotMatchRegularExpression('/\bgalbridge\b/', $statement);
                self::assertStringNotContainsString('DATABASE vmail', $statement);
                if (str_starts_with($statement, 'GRANT ')) {
                    self::assertStringEndsWith(' TO acme_gal;', $statement);
                }
            }
        }
        if ($driver === 'mysql') {
            self::assertContains("CREATE USER IF NOT EXISTS 'acme_gal'@'10.0.0.%' IDENTIFIED BY '{$password}';", $statements);
            self::assertContains("ALTER USER 'acme_gal'@'10.0.0.%' IDENTIFIED BY '{$password}';", $statements);
            self::assertSame("ALTER USER 'acme_gal'@'10.0.0.%' REQUIRE SSL;", end($statements));
            self::assertSame("mysql://acme_gal:{$password}@db.example.com/maildb", self::dsnOf($out), 'the DSN names the database server, not the account host');
        } else {
            self::assertContains("IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'acme_gal') THEN", $statements);
            self::assertContains('CREATE ROLE acme_gal WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT;', $statements);
            self::assertContains("ALTER ROLE acme_gal WITH LOGIN PASSWORD '{$password}' CONNECTION LIMIT 40;", $statements);
            self::assertContains('GRANT CONNECT ON DATABASE maildb TO acme_gal;', $statements);
            self::assertSame("pgsql://acme_gal:{$password}@db.example.com/maildb", self::dsnOf($out));
        }
        self::assertStringContainsString("-- Remote database: add TLS verification to the DSN (pgsql: ?sslmode=verify-full&sslrootcert=...; mysql: ?ca=...&verify_server_cert=1).\n", $out);
    }

    /**
     * Statements the template ships commented out (the personal-data grant, the MariaDB account
     * limits) are meant to be uncommented by the administrator: in a generated file they must name
     * the generated role, account host and database, or uncommenting them grants to another account.
     */
    #[DataProvider('drivers')]
    public function testCommentedOutStatementsFollowTheRename(string $driver): void
    {
        [$code, $out] = self::tool(['grants', 'driver' => $driver, 'role' => 'acme_gal', 'db' => 'maildb', 'account-host' => '10.0.0.%']);
        self::assertSame(0, $code, $out);
        $commented = [];
        foreach (explode("\n", self::body($out)) as $line) {
            if (preg_match('/^-- ((?:GRANT|ALTER USER|ALTER ROLE) .*;)$/', $line, $m)) {
                $commented[] = $m[1];
            }
        }
        self::assertNotEmpty($commented, 'the template ships commented-out statements');
        foreach ($commented as $statement) {
            if ($driver === 'mysql') {
                self::assertStringNotContainsString("'galbridge'@'localhost'", $statement, 'uncommented, this targets another account: ' . $statement);
                self::assertStringNotContainsString('vmail.', $statement, 'uncommented, this targets another database: ' . $statement);
            } else {
                self::assertDoesNotMatchRegularExpression('/\bgalbridge\b/', $statement, 'uncommented, this targets another role: ' . $statement);
            }
        }
    }

    /**
     * MySQL: the account host (where Roundcube connects from) never changes the database server in
     * the DSN; any non-local account host gets REQUIRE SSL.
     *
     * @return array<string, array{string, bool}>
     */
    public static function accountHosts(): array
    {
        return [
            'localhost' => ['localhost', false],
            '127.0.0.1' => ['127.0.0.1', false],
            '::1' => ['::1', false],
            'upper-case LOCALHOST' => ['LOCALHOST', false],
            'any host %' => ['%', true],
            'subnet pattern' => ['10.0.0.%', true],
            'single-character wildcard' => ['10.0.0._', true],
            'host name' => ['roundcube.example.com', true],
            'IPv4 address' => ['192.0.2.10', true],
        ];
    }

    #[DataProvider('accountHosts')]
    public function testMysqlAccountHostAndTls(string $accountHost, bool $tls): void
    {
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'account-host' => $accountHost]);
        self::assertSame(0, $code, $out);
        $account = "'galbridge'@'" . strtolower($accountHost) . "'";
        foreach (self::statements($out) as $statement) {
            if (str_starts_with($statement, 'GRANT ') || str_starts_with($statement, 'CREATE USER') || str_starts_with($statement, 'ALTER USER')) {
                self::assertStringContainsString($account, $statement);
            }
        }
        self::assertSame($tls, str_contains($out, "\n" . self::TLS_COMMENT . "\nALTER USER {$account} REQUIRE SSL;\n"), $accountHost);
        self::assertSame($tls ? 1 : 0, substr_count($out, 'REQUIRE SSL;'));
        self::assertSame('mysql://galbridge:' . self::passwordOf($out) . '@localhost/vmail', self::dsnOf($out), 'the default database server stays localhost');
        self::assertStringNotContainsString('Remote database', $out);
    }

    public function testAMissingOrBrokenTemplateIsReported(): void
    {
        $dir = $this->base() . '/plugin';
        mkdir($dir . '/SQL', 0o700, true);
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql'], $dir);
        self::assertSame(2, $code);
        self::assertSame("grant template not found: {$dir}/SQL/grants.mysql.sql\n", $out);

        // a template without the password placeholder is not the shipped one
        file_put_contents($dir . '/SQL/grants.postgres.sql', "CREATE ROLE galbridge WITH LOGIN PASSWORD 'fixed';\n");
        [$code, $out] = self::tool(['grants', 'driver' => 'pgsql'], $dir);
        self::assertSame(2, $code);
        self::assertStringStartsWith('grant template not found', $out);
        self::assertStringNotContainsString('fixed', $out);
    }

    /**
     * bin/galbridge.sh grants: --name=value parsing, driver aliases, stdout only, exit codes; it
     * needs no Roundcube installation.
     */
    public function testGrantsThroughTheScript(): void
    {
        $env = ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'];
        [$code, $out, $err] = self::cli(['grants', '--driver=postgresql', '--role=acme_gal', '--db=maildb', '--connection-limit=40'], $env);
        self::assertSame(0, $code, $err);
        self::assertSame('', $err, 'stdout only');
        self::assertStringContainsString("ALTER ROLE acme_gal WITH LOGIN PASSWORD '" . self::passwordOf($out) . "' CONNECTION LIMIT 40;", $out);
        self::assertSame('pgsql://acme_gal:' . self::passwordOf($out) . '@127.0.0.1/maildb', self::dsnOf($out));

        [$code, $out] = self::cli(['grants', '--driver=mariadb', '--account-host=10.0.0.%'], $env);
        self::assertSame(0, $code);
        self::assertStringContainsString("CREATE USER IF NOT EXISTS 'galbridge'@'10.0.0.%' IDENTIFIED BY '", $out);
        self::assertStringContainsString("ALTER USER 'galbridge'@'10.0.0.%' REQUIRE SSL;", $out);

        [$code, $out] = self::cli(['grants', '--driver=pgsql', '--host=%'], $env);
        self::assertSame(1, $code);
        self::assertStringStartsWith('Usage: galbridge.sh grants', $out);

        [$code, $out] = self::cli(['grants'], $env);
        self::assertSame(1, $code);
        self::assertStringStartsWith('Usage: galbridge.sh grants', $out);
    }

    // ------------------------------------------------------------------ grants: applied

    /**
     * @return array<string, array{string}>
     */
    public static function serverEngines(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            if ($engine !== 'sqlite') {
                $out[$engine] = [$dsn];
            }
        }
        return $out === [] ? ['no server engine configured' => ['']] : $out;
    }

    /**
     * The generated output, run by the database administrator, creates a role that the plugin can
     * use with the PRINTED DSN and that can read nothing else; re-running a fresh output changes
     * the password, so the newly printed DSN works and the old one does not.
     */
    #[DataProvider('serverEngines')]
    public function testGeneratedGrantsCreateAWorkingLeastPrivilegeRole(string $dsn): void
    {
        [$engine, $db, $host] = $this->target($dsn);
        $role = 'gbt_cli_g' . getmypid();
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        $args = ['grants', 'driver' => $engine, 'role' => $role, 'db' => $db, 'host' => $host];
        if ($engine === 'pgsql') {
            $args['connection-limit'] = '7';
        }

        [$code, $first] = self::tool($args);
        self::assertSame(0, $code, $first);
        $this->apply($engine, $db, $role, $first);
        $printed = self::dsnOf($first);
        self::assertSame("{$engine}://{$role}:" . self::passwordOf($first) . "@{$host}/{$db}", $printed);

        $conn = new Connection(new Dsn($printed, Env::config($printed)), 3);
        self::assertContains($conn->flavour(), $engine === 'pgsql' ? ['pgsql'] : ['mariadb', 'mysql']);
        $columns = $conn->visibleColumns();
        foreach (self::TEMPLATE_COLUMNS as $table => $cols) {
            self::assertEqualsCanonicalizing($cols, $columns[$table], "{$table}: exactly the granted columns");
        }
        self::assertSame([], $columns['alias_domain'] ?? [], 'alias_domain is neither probed nor granted');
        self::assertNotSame('', $conn->probeFailure('SELECT password FROM mailbox WHERE 1 = 0'), 'mailbox.password must not be readable');
        self::assertNotSame('', $conn->probeFailure('SELECT * FROM mailbox WHERE 1 = 0'), 'SELECT * must be denied');
        self::assertNotSame('', $conn->probeFailure('SELECT telephone FROM mailbox WHERE 1 = 0'), 'personal data is not granted by default');
        self::assertCount(1, $conn->select('SELECT m.username FROM mailbox m WHERE m.username = ?', [self::JAN]));
        $conn->close();

        // write access is denied by the privileges themselves, not only by a read-only session
        self::assertContains(self::writeProbe($printed, $engine), ['42000', '42501'], 'UPDATE must fail with a privilege error');

        // the plugin works with exactly this role (default fields)
        $factory = Env::factory(Env::config($printed), self::JAN);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        self::assertSame(13, $book->list_records()->count);
        self::assertSame('Jan', $book->get_record('u_janDkowalskiAexampleDcom', true)['firstname'] ?? null);
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        foreach (['db_error', 'column_missing', 'field_unavailable', 'role_too_broad'] as $reason) {
            self::assertStringNotContainsString($reason, $log);
        }

        if ($engine === 'pgsql') {
            self::assertSame('7', self::adminQuery($engine, $db, "SELECT rolconnlimit FROM pg_roles WHERE rolname = '{$role}'"));
            $settings = self::adminQuery($engine, $db, "SELECT array_to_string(rolconfig, ',') FROM pg_roles WHERE rolname = '{$role}'");
            foreach (['default_transaction_read_only=on', 'statement_timeout=5s', 'idle_in_transaction_session_timeout=10s'] as $setting) {
                self::assertStringContainsString($setting, $settings);
            }
        }

        // re-run: a fresh password that really is applied (CREATE ... IF NOT EXISTS alone would keep the old one)
        unset($args['connection-limit']);
        [$code, $second] = self::tool($args);
        self::assertSame(0, $code, $second);
        self::assertNotSame(self::passwordOf($first), self::passwordOf($second));
        $this->apply($engine, $db, $role, $second);
        $_SESSION = [];
        $conn = new Connection(new Dsn(self::dsnOf($second), Env::config(self::dsnOf($second))), 3);
        self::assertNotSame('', $conn->flavour(), 'the newly printed DSN works');
        $conn->close();
        $_SESSION = [];
        $old = new Connection(new Dsn($printed, Env::config($printed)), 3);
        try {
            $old->flavour();
            self::fail('the old password still works after re-running the generated output');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
        self::assertStringNotContainsString(self::passwordOf($first), $old->lastError());
        $_SESSION = [];
        if ($engine === 'pgsql') {
            self::assertSame('25', self::adminQuery($engine, $db, "SELECT rolconnlimit FROM pg_roles WHERE rolname = '{$role}'"), 'the default limit is applied again');
        }

        // the comments of both runs are the shipped ones
        self::assertSame(self::comments(self::templateLines($engine)), self::comments(explode("\n", self::body($first))));
        self::assertSame(self::comments(explode("\n", self::body($first))), self::comments(explode("\n", self::body($second))));
    }

    /**
     * MySQL: the REQUIRE SSL statement for a remote account host is valid SQL and takes effect.
     */
    #[DataProvider('serverEngines')]
    public function testRemoteMysqlAccountRequiresTls(string $dsn): void
    {
        [$engine, $db] = $this->target($dsn);
        if ($engine !== 'mysql') {
            self::markTestSkipped('MySQL/MariaDB account hosts only');
        }
        $role = 'gbt_cli_s' . getmypid();
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'role' => $role, 'db' => $db, 'account-host' => '10.0.0.%']);
        self::assertSame(0, $code, $out);
        [$code, , $err] = self::adminScript($engine, $db, $out);
        self::assertSame(0, $code, 'applying the generated output failed: ' . $err);
        self::assertSame('ANY', self::adminQuery($engine, $db, "SELECT ssl_type FROM mysql.user WHERE User = '{$role}' AND Host = '10.0.0.%'"));
        self::assertSame('', self::adminQuery($engine, $db, "SELECT Host FROM mysql.user WHERE User = '{$role}' AND Host <> '10.0.0.%'"), 'no other account was created');
        self::assertSame('9', self::adminQuery($engine, $db, "SELECT COUNT(*) FROM mysql.columns_priv WHERE User = '{$role}' AND Host = '10.0.0.%' AND Db = '{$db}' AND Table_name = 'mailbox'"));
    }

    // ------------------------------------------------------------------ diag: the fixture role

    #[DataProvider('serverEngines')]
    public function testDiagPassesWithTheFixtureRole(string $dsn): void
    {
        [$engine] = $this->target($dsn);
        [$code, $out, $err] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringEndsWith("\nResult: OK\n", $out);
        self::assertStringNotContainsString('[FAIL]', $out);
        self::assertNoPhpErrors($err);
        self::assertStringNotContainsString('testonly-ro', $out . $err, 'diag never prints the password');

        foreach ([
            ['OK', 'plugin enabled'], ['OK', 'config file'], ['OK', 'enabled books', 'galbridge_domain'],
            ['OK', 'directory hosts', '127.0.0.1 (derived from imap_host)'], ['OK', 'imap_host 127.0.0.1', 'covered'],
            ['OK', 'connect'], ['OK', 'password column', 'not readable (SQLSTATE '], ['OK', 'write privileges', 'none on the vmail tables'],
            ['OK', 'secret columns', 'not visible'], ['OK', 'columns mailbox', 'ok'], ['OK', 'columns domain', 'ok'], ['OK', 'columns forwardings', 'ok'],
            ['OK', 'optional columns mailbox', 'ok'], ['OK', 'optional columns alias', 'ok'], ['OK', 'optional columns maillists', 'ok'],
            ['INFO', 'granted but unused', 'mailbox.telephone, mailbox.mobile, mailbox.birthday (not in galbridge_fields'],
            ['INFO', 'hosted domains', '4 active'], ['INFO', 'deliverable mailboxes', '11'],
            ['OK', 'home domain', 'example.com'], ['INFO', 'addresses', '4 (login + per-user aliases'],
            ['OK', 'galbridge_domain', '1 domain(s), groups: department (3 groups), search-only: no'],
            ['INFO', 'persons', '7 ('], ['INFO', 'alias accounts', '5 ('], ['INFO', 'mailing lists', '1 ('],
        ] as $line) {
            self::assertLine($out, ...$line);
        }
        if ($engine === 'pgsql') {
            // PostgreSQL 16+ has no lc_ctype setting: the ctype comes from pg_database
            self::assertLine($out, 'OK', 'ctype');
            self::assertLine($out, 'OK', 'schema public', 'no CREATE privilege');
            // the fixture role has no limit (load.sh: a PHPUnit process holds many connections)
            self::assertLine($out, 'INFO', 'connection limit', 'unlimited');
            self::assertStringNotContainsString('42704', $out);
        } else {
            self::assertMatchesRegularExpression('/\[INFO\] max_(statement|execution)_time /', $out);
        }
        foreach (['== Versions', '== Configuration', '== IMAP host binding', '== Core interplay', '== vmail database', '== User ' . self::JAN] as $section) {
            self::assertStringContainsString("\n{$section}\n", $out);
        }
    }

    /**
     * The optional-column check follows galbridge_fields: a column of an enabled field that is not
     * granted is a WARN (never a FAIL); a granted column of a field that is not enabled is INFO.
     */
    #[DataProvider('serverEngines')]
    public function testDiagOptionalColumnsFollowTheConfiguredFields(string $dsn): void
    {
        $roleDsn = $this->createRole($dsn, 'opt', self::TEMPLATE_COLUMNS);

        // the template role with the default fields: nothing to report
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'optional columns mailbox', 'ok');
        self::assertStringNotContainsString('granted but unused', $out);
        self::assertStringNotContainsString('[WARN] optional columns', $out);

        // phone / mobile / birthday enabled but not granted: WARN, still exit 0
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn, 'galbridge_fields' => Config::FIELDS]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'optional columns mailbox', 'missing or not granted: telephone, mobile, birthday (configured fields;');
        self::assertStringNotContainsString('[FAIL]', $out);

        // fewer fields than grants: INFO only
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn, 'galbridge_fields' => ['name', 'email']]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'INFO', 'granted but unused', 'mailbox.first_name, mailbox.last_name, mailbox.department (not in galbridge_fields; the grant can be dropped)');
        self::assertStringNotContainsString('optional columns mailbox', $out, 'no optional mailbox column is configured');

        // aliases and lists switched off: their tables are not checked at all
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn, 'galbridge_include_aliases' => false, 'galbridge_include_mailing_lists' => false]);
        self::assertSame(0, $code, $out);
        foreach (['alias', 'maillists', 'moderators', 'maillist_owners', 'forwardings'] as $table) {
            self::assertStringNotContainsString('optional columns ' . $table, $out);
        }
    }

    // ------------------------------------------------------------------ diag: roles that are wrong

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function writeGrants(): array
    {
        $out = [];
        foreach (self::serverEngines() as $engine => [$dsn]) {
            $out["{$engine}: column UPDATE on mailbox"] = [$dsn, 'UPDATE (name)', 'mailbox'];
            $out["{$engine}: table DELETE on forwardings"] = [$dsn, 'DELETE', 'forwardings'];
            $out["{$engine}: table INSERT on alias"] = [$dsn, 'INSERT', 'alias'];
        }
        return $out;
    }

    /**
     * The write tripwire reads the catalogs: a role that can modify vmail is a FAIL, although the
     * plugin's own session is read-only (a statement probe would always be denied there).
     */
    #[DataProvider('writeGrants')]
    public function testDiagFailsForAWriteCapableRole(string $dsn, string $privilege, string $table): void
    {
        $roleDsn = $this->createRole($dsn, 'w', self::TEMPLATE_COLUMNS, [[$privilege, $table]]);
        [$code, $out, $err] = $this->diag(['galbridge_db_dsn' => $roleDsn], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'write privileges', 'the role can modify vmail (');
        self::assertLine($out, 'OK', 'password column', 'not readable');
        self::assertSame(1, substr_count($out, '[FAIL]'), 'the write privilege is the only problem: ' . $out);
        self::assertStringEndsWith("\nResult: 1 problem(s) found\n", $out);
        self::assertStringContainsString('== User ' . self::JAN, $out, 'the remaining sections still run');
        self::assertNoPhpErrors($err);
    }

    /**
     * The required columns depend on the configuration (Schema): mailbox/domain.expired only with
     * galbridge_hide_expired, forwardings.domain only with galbridge_user_aliases. diag and the
     * runtime agree in every combination.
     */
    #[DataProvider('serverEngines')]
    public function testDiagRequiredColumnsFollowTheConfiguration(string $dsn): void
    {
        $columns = self::TEMPLATE_COLUMNS;
        $columns['mailbox'] = array_values(array_diff($columns['mailbox'], ['expired']));
        $columns['domain'] = array_values(array_diff($columns['domain'], ['expired']));
        $noExpired = $this->createRole($dsn, 'ne', $columns);

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noExpired, 'galbridge_hide_expired' => false], [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('[FAIL]', $out);
        self::assertLine($out, 'OK', 'columns mailbox', 'ok');
        self::assertLine($out, 'INFO', 'persons', '8 (', 'expired users are listed without hide_expired');
        $book = Env::factory(Env::config($noExpired, ['galbridge_hide_expired' => false]), self::JAN)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book, 'the runtime agrees');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noExpired], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'columns mailbox', 'missing or not granted: expired');
        self::assertLine($out, 'FAIL', 'columns domain', 'missing or not granted: expired');
        self::assertLine($out, 'FAIL', 'mailbox', 'not found, not active or not usable (status: column_missing)');
        self::assertNotInstanceOf(AddressBook::class, Env::factory(Env::config($noExpired), self::JAN)->create(BookDefinition::DOMAIN), 'the runtime agrees');

        $columns = self::TEMPLATE_COLUMNS;
        $columns['forwardings'] = array_values(array_diff($columns['forwardings'], ['domain']));
        $noForwardingDomain = $this->createRole($dsn, 'nf', $columns);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noForwardingDomain], [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, 'forwardings.domain is needed only for galbridge_user_aliases: ' . $out);

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noForwardingDomain, 'galbridge_user_aliases' => true], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'columns forwardings', 'missing or not granted: domain');
        self::assertLine($out, 'FAIL', 'mailbox', '(status: column_missing)');
    }

    /**
     * Every diag section runs on its own: a failing query in one section is a FAIL line and the
     * following sections (here: the --user report) still run.
     */
    #[DataProvider('serverEngines')]
    public function testDiagSectionsRunIndependently(string $dsn): void
    {
        $columns = self::TEMPLATE_COLUMNS;
        $columns['domain'] = array_values(array_diff($columns['domain'], ['backupmx']));
        $roleDsn = $this->createRole($dsn, 'nb', $columns);

        [$code, $out, $err] = $this->diag(['galbridge_db_dsn' => $roleDsn], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'columns domain', 'missing or not granted: backupmx');
        self::assertLine($out, 'FAIL', 'check aborted', 'query failed (SQLSTATE ');
        self::assertStringContainsString("\n== User " . self::JAN . "\n", $out);
        self::assertLine($out, 'FAIL', 'mailbox', '(status: column_missing)');
        self::assertNoPhpErrors($err);
    }

    #[DataProvider('serverEngines')]
    public function testDiagConnectFailureCarriesTheReason(string $dsn): void
    {
        [$engine] = $this->target($dsn);
        $down = (string) preg_replace('~@[^/]+/~', '@127.0.0.1:1/', $dsn, 1);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $down]);
        self::assertSame(2, $code, $out);
        self::assertMatchesRegularExpression('/^  \[FAIL\] connect +cannot connect: .*(refused|2002)/mi', $out);
        self::assertStringNotContainsString('errors log', $out, 'a CLI run never writes the Roundcube errors log');
        self::assertStringNotContainsString('secret columns', $out, 'nothing is probed without a connection');
        self::assertStringNotContainsString('testonly-ro', $out);

        $wrong = (string) preg_replace('~^([a-z]+://[^:]+):[^@]*@~', '$1:Wrong-Pw-7f3a@', $dsn);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $wrong]);
        self::assertSame(2, $code, $out);
        // the server's reason (in the server's language): SQLSTATE and the role it refused
        $role = (string) (\rcube_db::parse_dsn($dsn)['username'] ?? '');
        self::assertMatchesRegularExpression('/^  \[FAIL\] connect +cannot connect: .*' . ($engine === 'pgsql' ? 'SQLSTATE\[08006\]' : '\[1045\]') . '.*' . preg_quote($role, '/') . '/m', $out);
        self::assertStringNotContainsString('Wrong-Pw-7f3a', $out, 'the reason never contains the password');
    }

    // ------------------------------------------------------------------ diag: configuration

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function malformedDenyLists(): array
    {
        return [
            'hidden addresses: a string' => ['galbridge_hidden_addresses', 'postmaster@example.com'],
            'hidden addresses: invalid entry' => ['galbridge_hidden_addresses', ['postmaster@example.com', 'not an address']],
            'hidden local parts: wildcard' => ['galbridge_hidden_localparts', ['*']],
            'domain viewer exclusions: a string' => ['galbridge_domain_viewer_domains_exclude', 'example.com'],
            'invalid visibility column' => ['galbridge_visibility_column', 'gal-visible; DROP'],
        ];
    }

    #[DataProvider('malformedDenyLists')]
    public function testDiagFailsForAMalformedDenyList(string $option, mixed $value): void
    {
        $dsn = $this->anyServerDsn();
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, $option => $value], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertMatchesRegularExpression('/^  \[FAIL\] ' . preg_quote($option, '/') . ' +.*(disabled|fail closed)/m', $out);
        self::assertStringNotContainsString('[ OK ] galbridge_domain ', $out, 'the user gets no book');
        self::assertLine($out, 'WARN', 'books', 'none for this user');
    }

    public function testDiagRefusedDsnNeverPrintsThePassword(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        [$code, $out] = $this->diag(['galbridge_db_dsn' => 'mysql://vmail:Secret-Vmail-pw@127.0.0.1/vmail']);
        self::assertSame(2, $code, $out);
        self::assertMatchesRegularExpression('/^  \[FAIL\] galbridge_db_dsn /m', $out);
        self::assertStringNotContainsString('Secret-Vmail-pw', $out);
        self::assertStringNotContainsString('[ OK ] connect', $out);
    }

    /**
     * The configuration file holds the database credential and the visibility policy.
     */
    public function testDiagChecksTheConfigurationFile(): void
    {
        $dsn = $this->anyServerDsn();
        $dir = $this->writeConfig(['galbridge_db_dsn' => $dsn]);
        $file = $dir . '/galbridge.inc.php';

        chmod($file, 0o644);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'config file', $file . ' (mode 0644: readable by everyone - use 0640 root:<web server group>)');

        chmod($file, 0o660);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'config file', $file . ' (mode 0660)');
        self::assertLine($out, 'WARN', 'config file', 'writable by its group (mode 0660)');

        chmod($file, 0o640);
        chmod($dir, 0o777);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'config directory', $dir . ' is writable by group/others (mode 0777)');

        chmod($dir, 0o1777); // sticky: others cannot replace the file
        [$code, $out] = $this->runDiag($dir);
        self::assertStringNotContainsString('config directory', $out);
        chmod($dir, 0o700);

        [$code, $out] = $this->runDiag($dir);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'config file', $file . ' (mode 0640)');
        self::assertDoesNotMatchRegularExpression('/\[(WARN|FAIL)\] config /', $out);

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::assertTrue(chown($file, 65534));
            [$code, $out] = $this->runDiag($dir);
            self::assertLine($out, 'WARN', 'config file', 'owned by uid 65534 (expected root or the PHP user)');
            chown($file, 0);
        }

        unlink($file);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'config file', 'neither plugins/galbridge/config.inc.php nor config/galbridge.inc.php exists');
    }

    public function testDiagWarnsWhileTheWildcardIsInEffect(): void
    {
        $dsn = $this->anyServerDsn();
        $global = ['galbridge_db_dsn' => $dsn, 'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['*']];

        [$code, $out] = $this->diag($global + ['galbridge_global_allow_wildcard' => true], [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'global data domains', "'*' is in effect: every hosted domain is listed, including tenants added later");
        self::assertLine($out, 'OK', 'galbridge_global', 'all hosted domains except 1, groups: none, search-only: yes');

        [$code, $out] = $this->diag($global, [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'global data domains', "'*' without galbridge_global_allow_wildcard = true: the global book is empty");
        self::assertStringNotContainsString('[ OK ] galbridge_global ', $out);

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']]);
        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('global data domains', $out);
    }

    /**
     * galbridge_imap_hosts must cover the IMAP host Roundcube really puts into sessions (a fixed
     * imap_host) and the hosts stored for existing Roundcube users; otherwise every login on that
     * host gets host_mismatch and no directory.
     */
    public function testDiagChecksTheImapHostCoverage(): void
    {
        $dsn = $this->anyServerDsn();
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['imap.example.com']]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'OK', 'directory hosts', 'imap.example.com (galbridge_imap_hosts)');
        self::assertLine($out, 'FAIL', 'imap_host 127.0.0.1', 'not in galbridge_imap_hosts: logins on it get no directory');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['imap.example.com']], ['imap_host' => 'ssl://IMAP.Example.COM:993']);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'imap_host imap.example.com', 'covered');

        // several selectable hosts: the uncovered one is a WARN
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1']], ['imap_host' => ['127.0.0.1:143' => 'Local', 'ssl://imap.other.example:993' => 'Other']]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'imap_host 127.0.0.1', 'covered');
        self::assertLine($out, 'WARN', 'imap_host imap.other.example', 'not in galbridge_imap_hosts');

        // a templated host without galbridge_imap_hosts disables the plugin
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], ['imap_host' => 'ssl://imap.%d:993']);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'directory hosts', 'plugin disabled: imap_host is templated');

        // Roundcube users stored with another IMAP host
        $pdo = new \PDO('sqlite:' . $this->roundcubeDb());
        $pdo->exec("INSERT INTO users (username, mail_host, created) VALUES ('a@example.com', 'imap.old.example', '2024-01-01 00:00:00'),"
            . " ('b@example.com', 'imap.old.example', '2024-01-01 00:00:00'), ('jan.kowalski@example.com', '127.0.0.1', '2024-01-01 00:00:00')");
        $pdo = null;
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'users on imap.old.example', '2 Roundcube users on this IMAP host get no directory');
        self::assertStringNotContainsString('users on 127.0.0.1', $out);
        self::assertStringNotContainsString('a@example.com', $out, 'counts only, never user names');
    }

    /**
     * --user reports what the runtime really does: the effective grouping (department field,
     * search-only, galbridge_max_groups), the resolved 'auto' search-only mode and size warnings.
     */
    public function testDiagUserReportShowsTheEffectiveBehaviour(): void
    {
        $dsn = $this->anyServerDsn();
        $user = ['--user=' . self::JAN];

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_domain_searchonly' => 'auto', 'galbridge_searchonly_threshold' => 5], [], $user);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'galbridge_domain', 'groups: none, search-only: auto');
        self::assertLine($out, 'INFO', 'search-only auto', 'yes (more than 5 entries)');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_domain_searchonly' => 'auto'], [], $user);
        self::assertLine($out, 'INFO', 'search-only auto', 'no');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_max_groups' => 1], [], $user);
        self::assertLine($out, 'OK', 'galbridge_domain', 'groups: department (disabled: more than galbridge_max_groups groups)');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_fields' => ['name', 'email'], 'galbridge_domain_groups' => 'department'], [], $user);
        self::assertLine($out, 'OK', 'galbridge_domain', 'groups: none, search-only: no');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]], [], $user);
        self::assertLine($out, 'OK', 'galbridge_domain', '2 domain(s), groups: domain (2 groups), search-only: no');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_max_export' => 5], [], $user);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'export', 'more than galbridge_max_export entries: exporting the whole book is refused');
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_max_export' => 5, 'galbridge_domain_export' => false], [], $user);
        self::assertStringNotContainsString('galbridge_max_export', $out, 'no warning for a book that does not export');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--user=ghost@example.com']);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'mailbox', 'not found, not active or not usable (status: no_mailbox)');
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--user=not an address']);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'login', 'not a valid address');
    }

    // ------------------------------------------------------------------ helpers: tool and script

    private static function pluginDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Tool::run() in this process (no Roundcube instance), output captured.
     *
     * @param array<int|string, mixed> $args
     *
     * @return array{0: int, 1: string}
     */
    private static function tool(array $args, ?string $pluginDir = null): array
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $code = (new Tool(null, $pluginDir ?? self::pluginDir(), $out))->run($args);
        rewind($out);
        $text = (string) stream_get_contents($out);
        fclose($out);
        return [$code, $text];
    }

    /**
     * php bin/galbridge.sh <args> as a child process, from the Roundcube directory.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function cli(array $args, array $env = []): array
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            $vars[$k] = $v;
        }
        $cwd = defined('RCUBE_INSTALL_PATH') ? rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') : self::pluginDir();
        return self::exec([PHP_BINARY, '-d', 'display_errors=stderr', self::pluginDir() . '/bin/galbridge.sh', ...$args], null, $vars, $cwd);
    }

    /**
     * diag with the given plugin options (galbridge.inc.php) and core options (config.inc.php).
     *
     * @param array<string, mixed> $plugin
     * @param array<string, mixed> $core
     * @param list<string>         $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function diag(array $plugin, array $core = [], array $args = []): array
    {
        return $this->runDiag($this->writeConfig($plugin, $core), $args);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runDiag(string $configDir, array $args = []): array
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        return self::cli(['diag', ...$args], [
            'ROUNDCUBE_INSTALL_PATH' => rtrim((string) constant('RCUBE_INSTALL_PATH'), '/'),
            'ROUNDCUBE_CONFIG_DIR' => $configDir,
        ]);
    }

    /**
     * A Roundcube configuration directory as Roundcube reads it (ROUNDCUBE_CONFIG_DIR): defaults,
     * config.inc.php and the plugin options in galbridge.inc.php (the plugin directory itself has no
     * config.inc.php).
     *
     * @param array<string, mixed> $plugin
     * @param array<string, mixed> $core
     */
    private function writeConfig(array $plugin, array $core = []): string
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        if (is_file(self::pluginDir() . '/config.inc.php')) {
            self::markTestSkipped('the plugin directory has its own config.inc.php (it would take precedence)');
        }
        $base = $this->base();
        $dir = $base . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
            mkdir($base . '/logs', 0o700);
            mkdir($base . '/temp', 0o700);
            copy(rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') . '/config/defaults.inc.php', $dir . '/defaults.inc.php');
        }
        $core += [
            'db_dsnw' => 'sqlite:///' . $this->roundcubeDb() . '?mode=0600',
            'imap_host' => '127.0.0.1:143',
            'plugins' => ['galbridge'],
            'addressbook_sort_col' => 'name',
            'log_dir' => $base . '/logs/',
            'temp_dir' => $base . '/temp/',
            'des_key' => 'TESTONLY-cli-0123456789ab',
        ];
        file_put_contents($dir . '/config.inc.php', "<?php\n// GAL Bridge CliToolTest - TEST ONLY\n\$config = " . var_export($core, true) . ";\n");
        file_put_contents($dir . '/galbridge.inc.php', "<?php\n// GAL Bridge CliToolTest - TEST ONLY\n\$config = " . var_export($plugin, true) . ";\n");
        chmod($dir . '/config.inc.php', 0o640);
        chmod($dir . '/galbridge.inc.php', 0o640);
        return $dir;
    }

    /**
     * Roundcube's own database (SQLite, core schema), for the users.mail_host check.
     */
    private function roundcubeDb(): string
    {
        $file = $this->base() . '/roundcube.db';
        if (!is_file($file)) {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec((string) file_get_contents(rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') . '/SQL/sqlite.initial.sql'));
            $pdo = null;
            chmod($file, 0o600);
        }
        return $file;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = sys_get_temp_dir() . '/galbridge-cli-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($this->base, 0o700);
        }
        return $this->base;
    }

    private static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    // ------------------------------------------------------------------ helpers: output

    /**
     * The DSN of the "-- $config['galbridge_db_dsn'] = '...';" line the tool prints last (the
     * template has an example of its own in the comments).
     */
    private static function dsnOf(string $out): string
    {
        self::assertGreaterThan(0, preg_match_all("/^-- \\\$config\\['galbridge_db_dsn'\\] = '([^']*)';$/m", $out, $m), $out);
        return (string) end($m[1]);
    }

    private static function passwordOf(string $out): string
    {
        self::assertSame(1, preg_match('~^[a-z]+://[^:]+:([^@]+)@~', self::dsnOf($out), $m));
        return $m[1];
    }

    private static function masked(string $dsn): string
    {
        return (string) preg_replace('~^([a-z]+://[^:]+):[^@]*@~', '$1:***@', $dsn);
    }

    /**
     * The template part of the output: between the two header lines and the DSN trailer.
     */
    private static function body(string $out): string
    {
        $lines = explode("\n", $out);
        $end = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '-- Put this line into ')) {
                $end = $i;
            }
        }
        self::assertNotNull($end, $out);
        return rtrim(implode("\n", array_slice($lines, 2, $end - 2)));
    }

    /**
     * @return list<string>
     */
    private static function templateLines(string $driver): array
    {
        $file = self::pluginDir() . '/SQL/' . ($driver === 'mysql' ? 'grants.mysql.sql' : 'grants.postgres.sql');
        return preg_split('/\R/', rtrim((string) file_get_contents($file))) ?: [];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function comments(array $lines): array
    {
        // prose comments only: commented-out statements (meant to be uncommented) follow the rename
        return array_values(array_filter($lines, static fn (string $l): bool => str_starts_with(ltrim($l), '--')
            && preg_match('/^\s*--\s*(GRANT|REVOKE|ALTER USER|ALTER ROLE)\b/', $l) !== 1));
    }

    /**
     * Non-empty, non-comment lines of the template part of an output (or of a template).
     *
     * @return list<string>
     */
    private static function statements(string $text): array
    {
        $lines = str_contains($text, '-- Put this line into ') ? explode("\n", self::body($text)) : explode("\n", $text);
        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '--')));
    }

    /**
     * Assert one diag line: "  [LEVEL] what   value...".
     */
    private static function assertLine(string $out, string $level, string $what, string $contains = '', string $message = ''): void
    {
        $tag = $level === 'OK' ? ' OK ' : $level;
        $pattern = '/^  \[' . preg_quote($tag, '/') . '\] +' . preg_quote($what, '/') . ' +.*' . preg_quote($contains, '/') . '/m';
        self::assertMatchesRegularExpression($pattern, $out, ($message !== '' ? $message . "\n" : '') . "expected [{$level}] {$what} ... {$contains}");
    }

    private static function assertNoPhpErrors(string $stderr): void
    {
        self::assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error|Uncaught)/i', $stderr);
    }

    // ------------------------------------------------------------------ helpers: databases

    /**
     * Engine, database and host of a fixture DSN; refuses anything but a local *test* database.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function target(string $dsn): array
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        $engine = (string) $parsed['phptype'] === 'pgsql' ? 'pgsql' : 'mysql';
        $db = (string) ($parsed['database'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*test[a-z0-9_]*$/', $db) || !Dsn::isLoopback($parsed)) {
            self::markTestSkipped('refusing to create roles outside a local *test* database: ' . $db);
        }
        return [$engine, $db, (string) ($parsed['hostspec'] ?? 'localhost')];
    }

    private function anyServerDsn(): string
    {
        foreach (self::serverEngines() as [$dsn]) {
            if ($dsn !== '') {
                return $dsn;
            }
        }
        self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
    }

    /**
     * Apply a generated grants output as the database administrator. MySQL: also mirrored to the
     * account host '%' (CI connects over TCP from outside the database container, as load.sh does).
     */
    private function apply(string $engine, string $db, string $role, string $out): void
    {
        $scripts = [$out];
        if ($engine === 'mysql') {
            $scripts[] = str_replace("'{$role}'@'localhost'", "'{$role}'@'%'", $out);
        }
        foreach ($scripts as $script) {
            [$code, , $err] = self::adminScript($engine, $db, $script);
            self::assertSame(0, $code, 'applying the generated output failed: ' . $err);
        }
    }

    /**
     * SQLSTATE of an UPDATE through a plain connection of the role (no read-only session; on
     * PostgreSQL the role's default_transaction_read_only is switched off first).
     */
    private static function writeProbe(string $dsn, string $engine): string
    {
        $db = \rcube_db::factory((new Dsn($dsn, Env::config($dsn)))->effective(), '', false);
        $db->set_option('ignore_errors', true);
        $db->db_connect('w');
        self::assertTrue($db->is_connected());
        if ($engine === 'pgsql') {
            $db->query('SET default_transaction_read_only = off');
        }
        $res = $db->query('UPDATE mailbox SET name = name WHERE 1 = 0');
        $info = $db->error_info();
        $state = $res === false || $db->is_error($res) ? (string) ($info[0] ?? 'ERROR') : '';
        $db->closeConnection();
        return $state;
    }

    /**
     * A login role on the throw-away test database with column-level SELECT and optional extra
     * privileges ([privileges, table]). Returns its DSN (the role is dropped in tearDown).
     *
     * @param array<string, list<string>>        $columns
     * @param list<array{0: string, 1: string}> $extra
     */
    private function createRole(string $dsn, string $kind, array $columns, array $extra = []): string
    {
        [$engine, $db] = $this->target($dsn);
        if (self::adminCommand($engine) === null) {
            self::markTestSkipped("no administrator access to the {$engine} test server (set "
                . ($engine === 'pgsql' ? 'PSQL_ADMIN, e.g. "runuser -u postgres -- psql"' : 'MYSQL_ADMIN, e.g. "mariadb -u root"') . ' as for load.sh)');
        }
        $role = 'gbt_cli_' . $kind . getmypid();
        $password = 'p' . bin2hex(random_bytes(12));
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];

        if ($engine === 'mysql') {
            $to = "'{$role}'@'localhost', '{$role}'@'%'";
            $sql = "CREATE USER '{$role}'@'localhost' IDENTIFIED BY '{$password}'; CREATE USER '{$role}'@'%' IDENTIFIED BY '{$password}';";
            foreach ($columns as $table => $cols) {
                $sql .= ' GRANT SELECT (`' . implode('`, `', $cols) . "`) ON `{$db}`.`{$table}` TO {$to};";
            }
            foreach ($extra as [$privileges, $table]) {
                $sql .= " GRANT {$privileges} ON `{$db}`.`{$table}` TO {$to};";
            }
        } else {
            $sql = "CREATE ROLE {$role} LOGIN PASSWORD '{$password}' NOINHERIT; GRANT CONNECT ON DATABASE {$db} TO {$role}; GRANT USAGE ON SCHEMA public TO {$role};";
            foreach ($columns as $table => $cols) {
                $sql .= ' GRANT SELECT (' . implode(', ', $cols) . ") ON {$table} TO {$role};";
            }
            foreach ($extra as [$privileges, $table]) {
                $sql .= " GRANT {$privileges} ON {$table} TO {$role};";
            }
        }
        [$code, , $err] = self::adminScript($engine, $db, $sql);
        self::assertSame(0, $code, 'creating the test role failed: ' . $err);

        return (string) preg_replace('~^([a-z]+)://[^@]*@~i', '$1://' . $role . ':' . $password . '@', $dsn);
    }

    private static function dropRole(string $engine, string $db, string $role): void
    {
        if (self::adminCommand($engine) === null) {
            return;
        }
        if ($engine === 'mysql') {
            // every host part the account may have got (also from an aborted run)
            $hosts = array_unique(array_merge(['localhost', '%', '10.0.0.%'], array_filter(explode("\n", self::adminQueryAll($engine, $db, "SELECT Host FROM mysql.user WHERE User = '{$role}'")))));
            $sql = 'DROP USER IF EXISTS ' . implode(', ', array_map(static fn (string $h): string => "'{$role}'@'{$h}'", $hosts)) . ';';
        } else {
            $sql = "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN EXECUTE 'DROP OWNED BY {$role}'; EXECUTE 'DROP ROLE {$role}'; END IF; END \$\$;";
        }
        self::adminScript($engine, $db, $sql);
    }

    /**
     * The administrator command of tests/fixtures/vmail/load.sh that works here, or null.
     *
     * @return null|list<string>
     */
    private static function adminCommand(string $engine): ?array
    {
        if (array_key_exists($engine, self::$adminCommand)) {
            return self::$adminCommand[$engine];
        }
        $env = getenv($engine === 'pgsql' ? 'PSQL_ADMIN' : 'MYSQL_ADMIN');
        $candidates = is_string($env) && trim($env) !== '' ? [$env]
            : ($engine === 'pgsql' ? ['psql -U postgres -h 127.0.0.1 -w', 'runuser -u postgres -- psql'] : ['mariadb -u root', 'mysql -u root']);
        self::$adminCommand[$engine] = null;
        foreach ($candidates as $candidate) {
            $cmd = preg_split('/\s+/', trim($candidate)) ?: [];
            $probe = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-t', '-d', 'postgres', '-c', 'SELECT 1'] : [...$cmd, '-N', '-e', 'SELECT 1'];
            [$code, $out] = self::exec($probe);
            if ($code === 0 && trim($out) === '1') {
                return self::$adminCommand[$engine] = $cmd;
            }
        }
        return null;
    }

    /**
     * Run an SQL script (stdin) as the database administrator.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function adminScript(string $engine, string $db, string $sql): array
    {
        $cmd = self::adminCommand($engine);
        if ($cmd === null) {
            self::markTestSkipped("no administrator access to the {$engine} test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)");
        }
        $cmd = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-d', $db] : $cmd;
        return self::exec($cmd, $sql);
    }

    /**
     * One value (first row, first column) of an administrator query; '' for no row.
     */
    private static function adminQuery(string $engine, string $db, string $sql): string
    {
        return trim((string) strtok(self::adminQueryAll($engine, $db, $sql) . "\n", "\n"));
    }

    /**
     * The first column of every row of an administrator query, one per line.
     */
    private static function adminQueryAll(string $engine, string $db, string $sql): string
    {
        $cmd = self::adminCommand($engine) ?? [];
        $cmd = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-A', '-t', '-d', $db, '-c', $sql] : [...$cmd, '-N', '-B', '-e', $sql];
        [$code, $out, $err] = self::exec($cmd);
        self::assertSame(0, $code, $err);
        return trim($out);
    }

    /**
     * Run a command without a shell. Without $stdin, stdin is closed, so nothing can prompt.
     *
     * @param list<string>               $cmd
     * @param null|array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function exec(array $cmd, ?string $stdin = null, ?array $env = null, ?string $cwd = null): array
    {
        $env ??= getenv();
        $env['PGCONNECT_TIMEOUT'] = '5';
        $spec = [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return [127, '', 'cannot start ' . ($cmd[0] ?? '')];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }
}
