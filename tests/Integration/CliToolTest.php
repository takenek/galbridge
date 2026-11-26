<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Schema;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Tests\Support\DbAdmin;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * bin/galbridge.sh (GalBridge\Cli\Tool), the administrator tool the README recommends for creating
 * the read-only role and for checking an installation:
 *
 *  - grants: argument validation (odd roles, databases and hosts are refused, '%' is never a
 *    database server, the role of another component is never touched), the output (the shipped
 *    template with a fresh password - for PostgreSQL as a SCRAM verifier, never in clear text;
 *    renames only inside SQL statements, so the attribution header and the comments stay as
 *    shipped; the database server in the DSN is independent of the MySQL account host; a non-local
 *    account host gets REQUIRE SSL), and the output APPLIED to the throw-away MariaDB and
 *    PostgreSQL servers as a new role: the printed DSN works, only the granted columns are
 *    readable, SELECT *, UPDATE and the forwardings table are denied; a second default output
 *    stops at the existing role and changes nothing, --reset-password changes the password and
 *    --grants-only re-applies everything but the password;
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
#[Group('root-aware')]
final class CliToolTest extends TestCase
{
    /** diag: the switches of an installation that sets none of them (Config::DEFAULTS) */
    private const SWITCHES_BOOKS = 'domain_book on, domain_autocomplete on, domain_export on, domain_searchonly off, global_book off, global_autocomplete on, global_export off, global_searchonly on, global_include_home off, global_allow_wildcard off';

    private const SWITCHES_ENTRIES = 'user_aliases off, include_aliases on, include_mailing_lists on, hide_expired on, mark_foreign_domains on, list_policy strict';

    private const SWITCHES_CONTROLS = 'db_allow_plaintext off, db_allow_verify_ca off, imap_allow_plaintext off, debug off';

    /** what the release archive holds at the top level (.gitattributes excludes the rest) */
    private const RELEASE_ENTRIES = [
        '.htaccess', 'CHANGELOG.md', 'COPYRIGHT', 'LICENSE', 'README.md', 'SECURITY.md', 'SQL', 'bin', 'composer.json',
        'config.inc.php.dist', 'docs', 'galbridge.php', 'lib', 'localization',
    ];

    private const JAN = 'jan.kowalski@example.com';

    /** columns granted by SQL/grants.*.sql (the optional personal-data grant stays commented out) */
    private const TEMPLATE_COLUMNS = [
        'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver', 'expired', 'department', 'first_name', 'last_name'],
        'domain' => ['domain', 'active', 'backupmx', 'expired'],
        // the view the templates create over the forwardings table (the table itself is never granted)
        Schema::FORWARDINGS => ['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'],
        'alias' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'moderators' => ['address', 'moderator'],
        'maillist_owners' => ['address', 'owner'],
    ];

    /** the line diag adds for a non-local MySQL account host */
    /** what the grant templates hold where the generator puts the verifier of the password */
    private const PLACEHOLDERS = ['mysql' => 'CHANGE_ME_PASSWORD_VERIFIER', 'pgsql' => 'CHANGE_ME_RANDOM_PASSWORD'];

    private const TLS_COMMENT = '-- Roundcube connects from another host: require TLS for this account';

    /** what the fake Roundcube core of the --allow-root test prints when it is loaded */
    private const FAKE_CORE = 'galbridge-test: fake core loaded';

    /** @var array<string, null|list<string>> engine => working admin command (null = unavailable) */
    private static array $adminCommand = [];

    /** a PostgreSQL grant set was applied: it re-created the view without the fixture role's grant */
    private static bool $viewRecreated = false;

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
        if (self::$viewRecreated) {
            // second verification pass (F-04): the PostgreSQL grant set drops and re-creates the
            // view - a fresh object without the grants of other roles, the fixture role among them
            self::$viewRecreated = false;
            DbAdmin::restoreFixtureView((string) getenv('GALBRIDGE_TEST_PGSQL_DSN'));
        }
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
        foreach (['diag [--user=EMAIL]', '[--url=URL]', 'grants --driver=mysql|pgsql', '[--host=H]', 'database server written into the DSN', '[--port=N]', 'written into the DSN as host:port', '(use --host=127.0.0.1)', '[--account-host=A]', 'patterns like 10.0.0.% allowed', '--connection-limit=25',
            '[--reset-password]', '[--grants-only]', 'stop when a role of that name exists', 'Do not run this tool as root'] as $text) {
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
            'mysql: connection limit 0' => [['driver' => 'mysql', 'connection-limit' => '0']],
            'both modes for an existing role' => [['driver' => 'pgsql', 'reset-password' => true, 'grants-only' => true]],
            'reset-password with a value' => [['driver' => 'mysql', 'reset-password' => 'yes']],
            'clear-text-password for PostgreSQL' => [['driver' => 'pgsql', 'clear-text-password' => true]],
            'clear-text-password with a value' => [['driver' => 'mysql', 'clear-text-password' => 'yes']],
            'clear-text-password without a password to write' => [['driver' => 'mysql', 'grants-only' => true, 'clear-text-password' => true]],
            'grants-only with a value' => [['driver' => 'pgsql', 'grants-only' => '1']],
            // written "--role acme" instead of "--role=acme": the option arrives without a value and
            // the name as a stray word - the default role must not be used instead
            'role without a value' => [['driver' => 'pgsql', 'reset-password' => true, 'role' => true, 1 => 'acme_gal']],
            'account host without a value' => [['driver' => 'mysql', 'account-host' => true, 1 => '10.0.0.5']],
            'host without a value' => [['driver' => 'mysql', 'host' => true]],
            'database without a value' => [['driver' => 'mysql', 'db' => true]],
            'driver without a value' => [['driver' => true]],
            'connection limit without a value' => [['driver' => 'mysql', 'connection-limit' => true]],
            // second verification pass (database compatibility): --port is digits only, 1-65535
            'port 0' => [['driver' => 'pgsql', 'port' => '0']],
            'port above 65535' => [['driver' => 'pgsql', 'port' => '65536']],
            'port with six digits' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '003306']],
            'port with a sign' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '+3306']],
            'negative port' => [['driver' => 'pgsql', 'port' => '-1']],
            'port with a letter' => [['driver' => 'pgsql', 'port' => '5432a']],
            'port with a blank' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => ' 3306']],
            'port with a line break' => [['driver' => 'pgsql', 'port' => "5432\n"]],
            'port as a service name' => [['driver' => 'pgsql', 'port' => 'postgresql']],
            'empty port' => [['driver' => 'pgsql', 'port' => '']],
            'port without a value' => [['driver' => 'pgsql', 'port' => true]],
            'a misspelled option' => [['driver' => 'mysql', 'grants-only' => true, 'conection-limit' => '200']],
            'an option of another command' => [['driver' => 'mysql', 'user' => 'jan.kowalski@example.com']],
            'a stray word' => [['driver' => 'mysql', 1 => 'vmail']],
        ];
    }

    /**
     * The same through the script, as an administrator types it - and for diag, whose --user or
     * --url would otherwise be dropped without a word.
     */
    public function testOptionsWithoutEqualsSignOrWithAnUnknownNameAreRefused(): void
    {
        $env = ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'];
        foreach ([
            [['grants', '--driver=pgsql', '--reset-password', '--role', 'acme_gal'], '--role needs a value: --role=...'],
            [['grants', '--driver=mysql', '--grants-only', '--conection-limit=200'], 'unknown option --conection-limit'],
            [['grants', '--driver=mysql', '--account-host', '10.0.0.5'], '--account-host needs a value: --account-host=...'],
            [['grants', '--driver=mysql', 'vmail'], "unexpected argument 'vmail': options are written --name=value"],
            [['grants', '--driver=pgsql', '--port', '5433'], '--port needs a value: --port=...'],
            [['grants', '--driver=mysql', '--host=127.0.0.1', '--port=3307', '--port=3308'], '--port is given twice: give every option once'],
        ] as [$args, $problem]) {
            // second verification pass (F-28): on standard error - never into a redirected SQL file
            [$code, $out, $err] = self::cli($args, $env);
            self::assertSame(1, $code, $err);
            self::assertSame('', $out);
            self::assertSame("Usage: galbridge.sh grants --driver=mysql|pgsql [--host=H] [--port=N] [--account-host=A (mysql only)] [--db=vmail] [--role=galbridge] [--connection-limit=25] [--reset-password | --grants-only] [--clear-text-password (mysql only)]\n{$problem}\n", $err);
        }
        if (GALBRIDGE_RC_LOADED) {
            $dir = $this->writeConfig(['galbridge_db_dsn' => $this->anyServerDsn()]);
            foreach ([[['--user', self::JAN], '--user needs a value: --user=...'], [['--usr=' . self::JAN], 'unknown option --usr'], [['--url'], '--url needs a value: --url=...'], [['now'], "unexpected argument 'now'"]] as [$args, $problem]) {
                [$code, $out, $err] = $this->runDiag($dir, $args);
                self::assertSame(1, $code, $out);
                // (after the messages of Roundcube's start, which loads the configured plugins)
                self::assertStringContainsString("Usage: galbridge.sh diag [--user=EMAIL] [--url=URL] [--cafile=FILE] [--strict]\n{$problem}", $err);
                self::assertStringNotContainsString('== Versions', $out, 'nothing is checked with half of the request');
            }
        }
    }

    /**
     * The account host is inserted with the role, and the database is renamed in the same pass: a
     * host name that contains the text "vmail." - devmail.example.com - is not renamed with it.
     */
    public function testAnAccountHostThatContainsTheDatabaseNameIsNotRenamed(): void
    {
        foreach (['devmail.example.com', 'vmail.example.com', 'srvmail.lan'] as $accountHost) {
            [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'db' => 'maildb', 'account-host' => $accountHost]);
            self::assertSame(0, $code, $out);
            $account = "'galbridge'@'{$accountHost}'";
            self::assertStringContainsString("CREATE USER {$account} IDENTIFIED WITH mysql_native_password AS ", $out);
            self::assertStringContainsString("ALTER USER {$account} REQUIRE SSL;", $out);
            // every statement names that account and that database, nothing else
            preg_match_all("/'galbridge'@'[^']*'/", $out, $accounts);
            self::assertSame([$account], array_values(array_unique($accounts[0])), $accountHost);
            self::assertStringContainsString(' ON maildb.mailbox TO ' . $account, $out);
            self::assertDoesNotMatchRegularExpression('/\bON vmail\./', implode("\n", self::statements($out)));
        }
    }

    /**
     * The comments of an output describe ITS statements: the sentence of the template - "creates the
     * account and stops when it already exists" - and the paragraph "no statement of this file
     * contains the password" do not stand above statements that do something else.
     */
    public function testTheCommentsOfEveryModeDescribeItsStatements(): void
    {
        $stops = ['mysql' => 'Creates the account and STOPS when it already exists (ERROR 1396): nothing below is then', 'pgsql' => 'Creates the role and STOPS when it already exists: the whole transaction is rolled back'];
        $note = 'No statement of this file contains the password of the account';
        foreach (['mysql' => 'account', 'pgsql' => 'role'] as $driver => $what) {
            [, $create] = self::tool(['grants', 'driver' => $driver]);
            self::assertStringContainsString('-- [role] ' . $stops[$driver], $create);
            [, $reset] = self::tool(['grants', 'driver' => $driver, 'reset-password' => true]);
            self::assertStringContainsString("-- [role] (--reset-password) Creates the {$what} when it does not exist and gives it a NEW password in either case.", $reset);
            [, $only] = self::tool(['grants', 'driver' => $driver, 'grants-only' => true]);
            self::assertStringContainsString("-- [role] (--grants-only) The {$what} is neither created nor given a password: it has to exist.", $only);
            foreach ([$reset, $only] as $out) {
                self::assertStringNotContainsString($stops[$driver], $out);
                self::assertSame(1, preg_match_all('/^-- \[role\]/m', $out), 'one marker line');
                self::assertSame(1, preg_match_all('/^-- \[\/role\]/m', $out));
            }
        }
        // the header of the template: the password is carried as its verifier (see the note) - true
        // for every verifier form, unchanged
        $header = "-- It is this file with a fresh random password (as its verifier: see the note above the [role]\n-- block), your role name, account host and database;";
        [, $create] = self::tool(['grants', 'driver' => 'mysql']);
        self::assertStringContainsString($note, $create);
        self::assertStringContainsString($header, $create);
        [, $reset] = self::tool(['grants', 'driver' => 'mysql', 'reset-password' => true]);
        self::assertStringContainsString($header, $reset);
        foreach ([['clear-text-password' => true], ['clear-text-password' => true, 'reset-password' => true]] as $mode) {
            [$code, $clear] = self::tool(['grants', 'driver' => 'mysql'] + $mode);
            self::assertSame(0, $code, $clear);
            self::assertStringNotContainsString($note, $clear, 'this output does contain the password');
            // (final review, CLI-2) nor does its header say otherwise or point at the note left out
            self::assertStringNotContainsString('as its verifier', $clear);
            self::assertStringNotContainsString('see the note above', $clear);
            self::assertStringContainsString("-- It is this file with a fresh random password (in clear text, in the statements of the [role]\n-- block below - keep this output private and delete it after use), your role name, account host and\n-- database; the matching DSN line goes to the terminal\n-- (standard error), never into the file.", $clear);
            self::assertStringNotContainsString('CREATE USER takes its verifier', $clear);
            self::assertStringNotContainsString('qualify - to its slow query log exactly as they were sent', $clear, 'the whole paragraph is left out');
            self::assertStringContainsString("IDENTIFIED BY '" . self::passwordOf($clear) . "'", $clear);
            self::assertStringContainsString('the next statements contain the password itself', $clear);
            // the paragraph only: what follows it is still there
            self::assertStringContainsString('-- Account limits, active by default', $clear);
        }
        [, $clear] = self::tool(['grants', 'driver' => 'mysql', 'clear-text-password' => true]);
        self::assertStringContainsString('-- [role] (--clear-text-password) Creates the account and STOPS when it already exists (ERROR 1396).', $clear);
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
     * The statements set the password, the limits and (PostgreSQL) the session defaults of the NAMED
     * role. For the role of another component that would cut that component off from the database,
     * so such a name is refused outright - in every mode - and nothing is printed that could be run.
     *
     * @return array<string, array{string, string}>
     */
    public static function rolesOfOtherComponents(): array
    {
        $out = [];
        foreach ([...Config::DEFAULTS['galbridge_db_refused_users'], 'public', 'mysql', 'pg_monitor', 'pg_read_all_data'] as $role) {
            foreach (['mysql', 'pgsql'] as $driver) {
                $out["{$driver}: {$role}"] = [$driver, $role];
            }
        }
        return $out;
    }

    #[DataProvider('rolesOfOtherComponents')]
    public function testRolesOfOtherComponentsAreNeverTouched(string $driver, string $role): void
    {
        foreach ([[], ['reset-password' => true], ['grants-only' => true]] as $mode) {
            [$code, $out] = self::tool(['grants', 'driver' => $driver, 'role' => $role] + $mode);
            self::assertSame(1, $code, $out);
            self::assertSame("grants: '{$role}' is a role of another component or of the database server (galbridge_db_refused_users): choose a dedicated role name, e.g. --role=galbridge\n", $out);
            foreach (['GRANT', 'ALTER', 'CREATE', 'PASSWORD', 'IDENTIFIED', 'galbridge_db_dsn', '://'] as $never) {
                self::assertStringNotContainsString($never, $out, 'nothing that could be run or pasted');
            }
        }
        // names that merely resemble them are ordinary role names
        [$code] = self::tool(['grants', 'driver' => $driver, 'role' => $role . '_gal']);
        self::assertSame(str_starts_with($role, 'pg_') ? 1 : 0, $code, 'only the reserved pg_ prefix is refused as a prefix');
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
        foreach (['pgsql', 'mysql'] as $driver) {
            [$code, $out] = self::tool(['grants', 'driver' => $driver, 'connection-limit' => $limit]);
            self::assertSame(1, $code, 'accepted --connection-limit=' . $limit . ' and printed: ' . (preg_match('/(CONNECTION LIMIT|MAX_USER_CONNECTIONS) \d+/', $out, $m) ? $m[0] : $out));
            self::assertStringNotContainsString('CONNECTION LIMIT', $out);
            self::assertStringNotContainsString('MAX_USER_CONNECTIONS', $out);
        }
    }

    /**
     * The connection limit of the account is ACTIVE for both engines (it used to be a commented-out
     * line for MariaDB/MySQL, and --connection-limit had no effect there).
     */
    public function testConnectionLimitIsAppliedForBothEngines(): void
    {
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql']);
        self::assertSame(0, $code, $out);
        self::assertContains("ALTER USER 'galbridge'@'localhost' WITH MAX_USER_CONNECTIONS 25 /*M!100101 MAX_STATEMENT_TIME 5 */;", self::statements($out));
        [, $out] = self::tool(['grants', 'driver' => 'mysql', 'connection-limit' => '120', 'account-host' => '10.0.0.%']);
        self::assertContains("ALTER USER 'galbridge'@'10.0.0.%' WITH MAX_USER_CONNECTIONS 120 /*M!100101 MAX_STATEMENT_TIME 5 */;", self::statements($out));
        self::assertStringNotContainsString('MAX_USER_CONNECTIONS 25', implode("\n", self::statements($out)));

        [, $out] = self::tool(['grants', 'driver' => 'pgsql']);
        self::assertContains('ALTER ROLE galbridge WITH CONNECTION LIMIT 25;', self::statements($out));
        [, $out] = self::tool(['grants', 'driver' => 'pgsql', 'connection-limit' => '120']);
        self::assertContains('ALTER ROLE galbridge WITH CONNECTION LIMIT 120;', self::statements($out));
        // no commented-out limit is left that somebody would have to remember to uncomment
        foreach (['mysql', 'pgsql'] as $driver) {
            self::assertDoesNotMatchRegularExpression('/^\s*--.*(MAX_USER_CONNECTIONS|CONNECTION LIMIT) \d/m', implode("\n", self::templateLines($driver)));
        }
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

    /**
     * --port is written into the printed DSN as host:port ([IPv6]:port), so that a server on
     * another port than the driver's default is reached with the DSN as printed (second
     * verification pass, database compatibility: without it the DSN named the default port, and
     * the plugin connected to another server or to none).
     *
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function ports(): array
    {
        return [
            'mysql: 127.0.0.1' => ['mysql', ['host' => '127.0.0.1', 'port' => '3307'], '127.0.0.1:3307'],
            'mysql: IPv6 loopback' => ['mysql', ['host' => '[::1]', 'port' => '3307'], '[::1]:3307'],
            'mysql: remote host' => ['mysql', ['host' => 'db.example.com', 'port' => '3306'], 'db.example.com:3306'],
            'mysql: highest port' => ['mysql', ['host' => '127.0.0.1', 'port' => '65535'], '127.0.0.1:65535'],
            'pgsql: default host' => ['pgsql', ['port' => '5433'], '127.0.0.1:5433'],
            // PostgreSQL uses the port on localhost too (TCP, or the socket .s.PGSQL.<port>)
            'pgsql: localhost' => ['pgsql', ['host' => 'localhost', 'port' => '5433'], 'localhost:5433'],
            'pgsql: IPv6 remote host' => ['pgsql', ['host' => '[2001:db8::5]', 'port' => '6432'], '[2001:db8::5]:6432'],
            'pgsql: lowest port' => ['pgsql', ['port' => '1'], '127.0.0.1:1'],
        ];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('ports')]
    public function testThePortIsWrittenIntoTheDsn(string $driver, array $server, string $target): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs rcube_db::parse_dsn (GALBRIDGE_RC)');
        }
        [$code, $out] = self::tool(['grants', 'driver' => $driver] + $server);
        self::assertSame(0, $code, $out);
        $password = self::passwordOf($out);
        self::assertSame("{$driver}://galbridge:{$password}@{$target}/vmail", self::dsnOf($out));
        $parsed = \rcube_db::parse_dsn(self::dsnOf($out));
        self::assertSame((int) $server['port'], (int) ($parsed['port'] ?? 0));
        self::assertSame(trim($server['host'] ?? '127.0.0.1', '[]'), (string) ($parsed['hostspec'] ?? ''));
        if (Dsn::isLoopback($parsed)) {
            // the plugin takes the printed line as it is (the DSN rules of the plugin: Dsn)
            $dsn = new Dsn(self::dsnOf($out), Env::config(self::dsnOf($out)));
            self::assertStringContainsString(':' . $server['port'] . '/vmail', $dsn->describe());
        }
        // the statements are those of an output without --port
        [, $plain] = self::tool(['grants', 'driver' => $driver] + array_diff_key($server, ['port' => true]));
        $secrets = "/SCRAM-SHA-256\\$[^']*|\\*[0-9A-F]{40}/";
        self::assertSame(preg_replace($secrets, '', self::body($out)), preg_replace($secrets, '', self::body($plain)));
    }

    /**
     * MariaDB/MySQL on localhost: PHP's driver connects through the unix socket and ignores a port,
     * so --port is refused with the host localhost (also the default host of --driver=mysql) - a
     * printed DSN with a port that is never used would reach another server, and the plugin refuses
     * such a DSN anyway.
     */
    public function testAPortWithTheMysqlHostLocalhostIsRefused(): void
    {
        foreach ([['port' => '3307'], ['host' => 'localhost', 'port' => '3307'], ['host' => 'LOCALHOST', 'port' => '3306']] as $server) {
            [$code, $out, $err] = self::toolStreams(['grants', 'driver' => 'mysql'] + $server);
            self::assertSame(1, $code, $out . $err);
            self::assertSame('', $out, 'nothing on standard output');
            self::assertSame(Tool::usage('grants') . "\n--port with the host localhost: PHP connects to a MariaDB/MySQL server on localhost through the unix socket and ignores the port - use --host=127.0.0.1 (or the address of the server) with --port\n", $err);
        }
        // the same host without --port, and PostgreSQL with it, are fine
        self::assertSame(0, self::tool(['grants', 'driver' => 'mysql', 'host' => 'localhost'])[0]);
        self::assertSame(0, self::tool(['grants', 'driver' => 'pgsql', 'host' => 'localhost', 'port' => '5433'])[0]);
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
        // what the statements do to an EXISTING role of that name is said up front: nothing
        self::assertSame("-- It creates the role 'galbridge'. When a role of that name already exists it stops and changes nothing (see --reset-password, --grants-only).", $lines[1]);

        $password = self::passwordOf($out);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $password);
        // what the statements carry: a VERIFIER of the password, never the password - the
        // mysql_native_password hash for MySQL/MariaDB, a SCRAM-SHA-256 verifier for PostgreSQL
        $secret = self::secretOf($out, $driver);
        $expected = [];
        foreach (self::templateLines($driver) as $line) {
            $expected[] = str_starts_with(ltrim($line), '--') ? $line : str_replace(self::PLACEHOLDERS[$driver], $secret, $line);
        }
        self::assertSame(rtrim(implode("\n", $expected)), self::body($out));

        self::assertSame($driver === 'mysql' ? "mysql://galbridge:{$password}@localhost/vmail" : "pgsql://galbridge:{$password}@127.0.0.1/vmail", self::dsnOf($out));
        self::assertStringContainsString("\n-- Put this line into config/galbridge.inc.php (or plugins/galbridge/config.inc.php if you keep the configuration there; owner root:<web server group>, mode 0640) WITHOUT its leading \"-- \", then check the file with php -l:\n", $out);
        self::assertStringNotContainsString('Remote database', $out, 'a local server needs no TLS hint');
        self::assertStringNotContainsString('REQUIRE SSL;', $out);
        foreach (self::statements($out) as $statement) {
            self::assertStringNotContainsString('CHANGE_ME_', $statement);
        }
        // the password: in the DSN line and NOWHERE else - a database server that keeps account
        // statements in a log (MariaDB: general, slow and binary log) never receives it.
        // No statement ever CHANGES the password of a role that exists (see --reset-password)
        self::assertSame(1, substr_count($out, $password));
        if ($driver === 'mysql') {
            self::assertContains("CREATE USER 'galbridge'@'localhost' IDENTIFIED WITH mysql_native_password AS '{$secret}';", self::statements($out));
            self::assertStringNotContainsString('IDENTIFIED BY', implode("\n", self::statements($out)));
            self::assertStringNotContainsString('sql_log_off', implode("\n", self::statements($out)), 'nothing secret is left to keep out of a log');
        } else {
            self::assertContains("CREATE ROLE galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT PASSWORD '{$secret}';", self::statements($out));
            // ONE transaction - after an error nothing is applied. Before it only the session setting
            // that has to be in effect when a transaction starts (whether it is in the logged sample)
            $statements = self::statements($out);
            $begin = array_search('BEGIN;', $statements, true);
            self::assertSame(['DO $$', 'BEGIN', "IF current_setting('server_version_num')::int >= 120000 THEN", "PERFORM set_config('log_transaction_sample_rate', '0', false);", 'END IF;', 'END', '$$;'],
                array_slice($statements, 0, (int) $begin));
            self::assertSame('COMMIT;', $statements[count($statements) - 1]);
            self::assertSame(1, count(array_keys($statements, 'BEGIN;', true)) + count(array_keys($statements, 'COMMIT;', true)) - 1, 'one BEGIN, one COMMIT');
            // every statement that names the role or carries the verifier lies inside it
            foreach (array_slice($statements, 0, (int) $begin) as $outside) {
                self::assertStringNotContainsString('galbridge', $outside);
                self::assertStringNotContainsString($secret, $outside);
            }
        }
        self::assertStringNotContainsString('ALTER USER \'galbridge\'@\'localhost\' IDENTIFIED', $out);
        self::assertDoesNotMatchRegularExpression('/ALTER ROLE galbridge WITH[^;]*PASSWORD/', $out);
        self::assertStringNotContainsString('IF NOT EXISTS', implode("\n", self::statements($out)), 'an existing account is an error, not something to reuse');

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
        $args = ['grants', 'driver' => $driver, 'role' => 'acme_gal', 'db' => 'maildb', 'host' => 'db.example.com', 'connection-limit' => '40'];
        if ($driver === 'mysql') {
            $args['account-host'] = '10.0.0.%';
        }
        [$code, $out] = self::tool($args);
        self::assertSame(0, $code, $out);
        $password = self::passwordOf($out);
        $secret = self::secretOf($out, $driver);
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
            self::assertStringNotContainsString('CHANGE_ME_', $statement);
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
            self::assertContains("CREATE USER 'acme_gal'@'10.0.0.%' IDENTIFIED WITH mysql_native_password AS '" . Tool::mysqlVerifier($password) . "';", $statements);
            self::assertContains("ALTER USER 'acme_gal'@'10.0.0.%' WITH MAX_USER_CONNECTIONS 40 /*M!100101 MAX_STATEMENT_TIME 5 */;", $statements);
            // second verification pass (F-03): materialised and fenced
            self::assertContains('CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER VIEW maildb.galbridge_forwardings AS', $statements);
            self::assertContains('LIMIT 18446744073709551615;', $statements);
            self::assertContains('FROM maildb.forwardings', $statements);
            self::assertContains("GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON maildb.galbridge_forwardings TO 'acme_gal'@'10.0.0.%';", $statements);
            // STAGE 6: right after the account is created, not at the end - a later statement that
            // fails stops the script, and the account must not be left without the TLS requirement
            $create = array_search("CREATE USER 'acme_gal'@'10.0.0.%' IDENTIFIED WITH mysql_native_password AS '" . Tool::mysqlVerifier($password) . "';", $statements, true);
            self::assertIsInt($create);
            self::assertSame("ALTER USER 'acme_gal'@'10.0.0.%' REQUIRE SSL;", $statements[$create + 1] ?? null);
            self::assertSame("mysql://acme_gal:{$password}@db.example.com/maildb", self::dsnOf($out), 'the DSN names the database server, not the account host');
        } else {
            self::assertContains("IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'acme_gal') THEN", $statements);
            self::assertContains("CREATE ROLE acme_gal WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT PASSWORD '{$secret}';", $statements);
            self::assertContains('ALTER ROLE acme_gal WITH CONNECTION LIMIT 40;', $statements);
            self::assertContains('GRANT CONNECT ON DATABASE maildb TO acme_gal;', $statements);
            // the view keeps its name whatever the role is called; the role gets the view, not the table
            // second verification pass (F-03, F-04): a fresh, fenced view
            self::assertContains('DROP VIEW IF EXISTS galbridge_forwardings;', $statements);
            self::assertContains('CREATE VIEW galbridge_forwardings WITH (security_barrier = true) AS', $statements);
            self::assertContains('OFFSET 0;', $statements);
            self::assertContains('REVOKE CREATE, TEMPORARY ON DATABASE maildb FROM acme_gal;', $statements);
            self::assertContains('GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON galbridge_forwardings TO acme_gal;', $statements);
            self::assertContains('REVOKE ALL ON forwardings FROM acme_gal;', $statements);
            self::assertSame("pgsql://acme_gal:{$password}@db.example.com/maildb", self::dsnOf($out));
        }
        // the forwardings table itself is granted to nobody
        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/^GRANT .* ON (?:maildb\.)?forwardings /', $statement);
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
        // (--account-host is for MariaDB/MySQL only - second verification pass, F-28)
        [$code, $out] = self::tool(['grants', 'driver' => $driver, 'role' => 'acme_gal', 'db' => 'maildb'] + ($driver === 'mysql' ? ['account-host' => '10.0.0.%'] : []));
        self::assertSame(0, $code, $out);
        $commented = [];
        foreach (explode("\n", self::body($out)) as $line) {
            if (preg_match('/^-- ((?:GRANT|REVOKE|ALTER USER|ALTER ROLE) .*;)$/', $line, $m)) {
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

        // (final review, CLI-2) a MariaDB/MySQL template whose header sentence about the verifier is
        // not the shipped one: --clear-text-password cannot make it true, so nothing is printed
        $shipped = (string) file_get_contents(self::pluginDir() . '/SQL/grants.mysql.sql');
        file_put_contents($dir . '/SQL/grants.mysql.sql', str_replace('(as its verifier: see the note above the [role]', '(as its hash: see the note above the [role]', $shipped));
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'clear-text-password' => true], $dir);
        self::assertSame(2, $code, $out);
        self::assertSame("grant template not found: {$dir}/SQL/grants.mysql.sql\n", $out);
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql'], $dir);
        self::assertSame(0, $code, 'the verifier form does not depend on that sentence');
    }

    /**
     * bin/galbridge.sh grants: --name=value parsing, driver aliases, exit codes; it needs no
     * Roundcube installation. Second verification pass (F-02, F-28): it runs from a copy that only
     * root and the user of the run can change (a checkout with umask 002 would be refused), the SQL
     * is on standard output and the DSN line - the password - on standard error only.
     */
    public function testGrantsThroughTheScript(): void
    {
        $env = ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'];
        $plugin = $this->releaseCopy('galbridge');
        [$code, $out, $err] = self::cli(['grants', '--driver=postgresql', '--role=acme_gal', '--db=maildb', '--connection-limit=40'], $env, $plugin);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('ALTER ROLE acme_gal WITH CONNECTION LIMIT 40;', $out);
        self::assertStringContainsString("PASSWORD '" . self::secretOf($out . $err, 'pgsql') . "';", $out);
        self::assertSame('pgsql://acme_gal:' . self::passwordOf($err) . '@127.0.0.1/maildb', self::dsnOf($err));
        self::assertDoesNotMatchRegularExpression("/^-- \\\$config\\['galbridge_db_dsn'\\] = 'pgsql:\\/\\/acme_gal:/m", $out, 'the DSN line is not in the SQL');
        self::assertStringNotContainsString(self::passwordOf($err), $out);

        [$code, $out, $err] = self::cli(['grants', '--driver=mariadb', '--account-host=10.0.0.%'], $env, $plugin);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("CREATE USER 'galbridge'@'10.0.0.%' IDENTIFIED WITH mysql_native_password AS '" . self::secretOf($out . $err, 'mysql') . "';", $out);
        self::assertStringContainsString("ALTER USER 'galbridge'@'10.0.0.%' REQUIRE SSL;", $out);

        // the modes for an existing role are plain flags
        [$code, $out, $err] = self::cli(['grants', '--driver=mysql', '--reset-password'], $env, $plugin);
        self::assertSame(0, $code, $err);
        $verifier = Tool::mysqlVerifier(self::passwordOf($err));
        self::assertStringContainsString("CREATE USER IF NOT EXISTS 'galbridge'@'localhost' IDENTIFIED WITH mysql_native_password AS '{$verifier}';\nALTER USER 'galbridge'@'localhost' IDENTIFIED WITH mysql_native_password AS '{$verifier}';", $out);
        self::assertSame(1, substr_count($out . $err, self::passwordOf($err)), 'the password is in the DSN line only');
        [$code, $out, $err] = self::cli(['grants', '--driver=mysql', '--clear-text-password'], $env, $plugin);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("IDENTIFIED BY '" . self::passwordOf($err) . "';", $out);
        [$code, $out, $err] = self::cli(['grants', '--driver=pgsql', '--grants-only'], $env, $plugin);
        self::assertSame(0, $code, $err);
        self::assertSame('', $err, 'no DSN line');
        self::assertStringNotContainsString('-- Put this line into', $out);
        self::assertDoesNotMatchRegularExpression("/^-- \\\$config\\['galbridge_db_dsn'\\]/m", $out, 'no DSN line (the template has an example of its own in its comments)');
        [$code, $out, $err] = self::cli(['grants', '--driver=pgsql', '--role=vmail'], $env, $plugin);
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringStartsWith("grants: 'vmail' is a role of another component", $err);

        [$code, $out, $err] = self::cli(['grants', '--driver=pgsql', '--host=%'], $env, $plugin);
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringStartsWith('Usage: galbridge.sh grants', $err);

        [$code, $out, $err] = self::cli(['grants'], $env, $plugin);
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringStartsWith('Usage: galbridge.sh grants', $err);
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
     * The MariaDB/MySQL server only, for the cases that exist on that engine alone (second
     * verification pass, F-34: no PostgreSQL case that can only be skipped - CI fails on skips).
     *
     * @return array<string, array{string}>
     */
    public static function mysqlServer(): array
    {
        $dsn = Env::engines()['mysql'][0] ?? null;
        return is_string($dsn) ? ['mysql' => [$dsn]] : ['no MySQL/MariaDB server configured' => ['']];
    }

    /**
     * --clear-text-password exists for MySQL 8.4 and later only (mysql_native_password is not loaded
     * there; MySQL rewrites passwords in its own logs). MariaDB keeps account statements verbatim in
     * its general, slow and binary logs, so that output must never get as far as the password on
     * MariaDB: its first statement stops the script there. On a MySQL test server the same output
     * creates the account, and the printed DSN works.
     */
    public function testClearTextPasswordOutputRefusesToRunOnMariaDb(): void
    {
        foreach ([[], ['reset-password' => true]] as $mode) {
            [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'clear-text-password' => true] + $mode);
            self::assertSame(0, $code, $out);
            $password = self::passwordOf($out);
            $statements = self::statements($out);
            $guard = "/*M! SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'this output is for MySQL: for MariaDB run grants without --clear-text-password' */;";
            $create = $mode === [] ? "CREATE USER 'galbridge'@'localhost' IDENTIFIED BY '{$password}';" : "CREATE USER IF NOT EXISTS 'galbridge'@'localhost' IDENTIFIED BY '{$password}';";
            self::assertContains($guard, $statements);
            self::assertContains($create, $statements);
            self::assertLessThan(array_search($create, $statements, true), array_search($guard, $statements, true), 'the guard comes before the password');
            self::assertStringContainsString('-- (--clear-text-password) FOR MYSQL 8.4 OR LATER ONLY', $out);
            self::assertStringNotContainsString('mysql_native_password', implode("\n", $statements));
            self::assertStringNotContainsString('CHANGE_ME_', implode("\n", $statements));
        }

        // applied to MariaDB: the guard stops the script, the account is not created and the
        // statement with the password is never sent
        $dsn = Env::engines()['mysql'][0] ?? null;
        if (!is_string($dsn)) {
            self::markTestSkipped('needs the MariaDB test server');
        }
        [$engine, $db] = $this->target($dsn);
        $role = 'gbt_cli_c' . getmypid();
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'role' => $role, 'db' => $db, 'clear-text-password' => true] + $this->server($dsn));
        self::assertSame(0, $code, $out);
        if (stripos(self::adminQuery($engine, $db, 'SELECT VERSION()'), 'mariadb') === false) {
            // MySQL (8.0.3 and later know the setting of the guard): the account is created with the
            // password of the printed DSN, which works
            $this->apply($engine, $db, $role, $out);
            self::assertSame('1', self::adminQuery($engine, $db, "SELECT COUNT(*) FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"));
            $_SESSION = [];
            $conn = new Connection(new Dsn(self::dsnOf($out), Env::config(self::dsnOf($out))), 3);
            self::assertSame('mysql', $conn->flavour());
            self::assertCount(1, $conn->select('SELECT m.username FROM mailbox m WHERE m.username = ?', [self::JAN]));
            $conn->close();
            return;
        }
        [$code, , $err] = self::adminScript($engine, $db, $out);
        self::assertNotSame(0, $code, 'the clear-text output ran on MariaDB');
        self::assertStringContainsString('this output is for MySQL', $err);
        self::assertSame('0', self::adminQuery($engine, $db, "SELECT COUNT(*) FROM mysql.user WHERE User = '{$role}'"), 'nothing was created');
    }

    /**
     * The generated output, run by the database administrator, creates a role that the plugin can
     * use with the PRINTED DSN and that can read nothing else. A second default output stops at the
     * existing role and changes nothing; --reset-password changes the password (the newly printed
     * DSN works, the old one does not); --grants-only re-applies everything but the password.
     */
    #[DataProvider('serverEngines')]
    public function testGeneratedGrantsCreateAWorkingLeastPrivilegeRole(string $dsn): void
    {
        [$engine, $db] = $this->target($dsn);
        $role = 'gbt_cli_g' . getmypid();
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        // MySQL 8.4 and later without mysql_native_password: the form README 2.2 gives for them
        $verifier = self::verifierApplies($engine, $db);
        $clearText = $verifier ? [] : ['clear-text-password' => true];
        $args = ['grants', 'driver' => $engine, 'role' => $role, 'db' => $db, 'connection-limit' => '7'] + $this->server($dsn);

        [$code, $first] = self::tool($args + $clearText);
        self::assertSame(0, $code, $first);
        $this->apply($engine, $db, $role, $first);
        $printed = self::dsnOf($first);
        self::assertSame("{$engine}://{$role}:" . self::passwordOf($first) . '@' . $this->serverTarget($dsn) . "/{$db}", $printed);

        $conn = new Connection(new Dsn($printed, Env::config($printed)), 3);
        self::assertContains($conn->flavour(), $engine === 'pgsql' ? ['pgsql'] : ['mariadb', 'mysql']);
        self::assertSame([], $conn->sessionWarnings(), 'statement timeout and read-only mode were accepted');
        $columns = $conn->visibleColumns();
        foreach (self::TEMPLATE_COLUMNS as $table => $cols) {
            self::assertEqualsCanonicalizing($cols, $columns[$table], "{$table}: exactly the granted columns");
        }
        self::assertSame([], $columns['alias_domain'] ?? [], 'alias_domain is neither probed nor granted');
        self::assertSame('denied', $conn->probe('SELECT password FROM mailbox WHERE 1 = 0'), 'mailbox.password must not be readable');
        self::assertSame('denied', $conn->probe('SELECT * FROM mailbox WHERE 1 = 0'), 'SELECT * must be denied');
        self::assertSame('denied', $conn->probe('SELECT telephone FROM mailbox WHERE 1 = 0'), 'personal data is not granted by default');
        self::assertSame('denied', $conn->probe('SELECT forwarding FROM forwardings WHERE 1 = 0'), 'the forwardings table (private forwarding targets) must not be readable');
        self::assertSame([], $columns[Schema::FORWARDINGS_TABLE]);
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

        // limits and settings of the role - for BOTH engines
        if ($engine === 'pgsql') {
            self::assertSame('7', self::adminQuery($engine, $db, "SELECT rolconnlimit FROM pg_roles WHERE rolname = '{$role}'"));
            $settings = self::adminQuery($engine, $db, "SELECT array_to_string(rolconfig, ',') FROM pg_roles WHERE rolname = '{$role}'");
            foreach (['default_transaction_read_only=on', 'statement_timeout=5s', 'idle_in_transaction_session_timeout=10s',
                'log_statement=none', 'log_min_duration_statement=-1', 'log_min_error_statement=panic',
                // sampled logging (PostgreSQL 13 / 12 and later) is switched off for the role as well
                'log_min_duration_sample=-1', 'log_transaction_sample_rate=0'] as $setting) {
                self::assertStringContainsString($setting, $settings);
            }
            // the server stores (and the statement carried) a SCRAM verifier - never the password
            self::assertSame(self::secretOf($first, 'pgsql'), self::adminQuery($engine, $db, "SELECT rolpassword FROM pg_authid WHERE rolname = '{$role}'"));
            self::assertSame('v', self::adminQuery($engine, $db, "SELECT relkind FROM pg_class WHERE relname = 'galbridge_forwardings'"));
            self::assertSame('f', self::adminQuery($engine, $db, "SELECT has_table_privilege('{$role}', 'forwardings', 'SELECT') OR has_any_column_privilege('{$role}', 'forwardings', 'SELECT')"));
        } else {
            self::assertSame('7', self::adminQuery($engine, $db, "SELECT max_user_connections FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"));
            if ($verifier) {
                // the server stores (and the statement carried) the verifier - never the password
                self::assertSame(self::secretOf($first, 'mysql'), self::adminQuery($engine, $db, "SELECT authentication_string FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"));
            } else {
                // the password itself, in the one statement of the clear-text form, after the guard
                $statements = self::statements($first);
                $create = array_search("CREATE USER '{$role}'@'localhost' IDENTIFIED BY '" . self::passwordOf($first) . "';", $statements, true);
                self::assertIsInt($create, $first);
                self::assertSame('SELECT @@SESSION.information_schema_stats_expiry AS galbridge_mysql_8_only;', $statements[$create - 1] ?? null);
                self::assertStringNotContainsString('mysql_native_password', implode("\n", $statements));
            }
            if (stripos(self::adminQuery($engine, $db, 'SELECT VERSION()'), 'mariadb') !== false) {
                self::assertSame('5.000000', self::adminQuery($engine, $db, "SELECT max_statement_time FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"));
            }
            self::assertSame('0', self::adminQuery($engine, $db, "SELECT COUNT(*) FROM mysql.tables_priv WHERE User = '{$role}' AND Db = '{$db}' AND Table_name = 'forwardings'"), 'no grant on the forwardings table');
            self::assertSame('DEFINER', self::adminQuery($engine, $db, "SELECT SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'galbridge_forwardings'"));
        }
        // the comments of the run are the shipped ones (the clear-text form replaces the [role]
        // block and the paragraph about the verifier: testTheCommentsOfEveryModeDescribeItsStatements)
        if ($verifier) {
            self::assertSame(self::comments(self::templateLines($engine)), self::comments(explode("\n", self::body($first))));
        }

        // ---- a second DEFAULT output: the role exists, so it stops and changes NOTHING
        unset($args['connection-limit']);
        [$code, $again] = self::tool($args + $clearText);
        self::assertSame(0, $code, $again);
        [$code, , $err] = self::adminScript($engine, $db, $again);
        self::assertNotSame(0, $code, 'the default output was applied to an existing role');
        self::assertMatchesRegularExpression($engine === 'pgsql' ? '/already exists: nothing was changed/' : '/1396/', $err);
        $_SESSION = [];
        $conn = new Connection(new Dsn($printed, Env::config($printed)), 3);
        self::assertNotSame('', $conn->flavour(), 'the password of the existing role is untouched');
        $conn->close();
        self::assertSame('7', self::adminQuery($engine, $db, $engine === 'pgsql'
            ? "SELECT rolconnlimit FROM pg_roles WHERE rolname = '{$role}'"
            : "SELECT max_user_connections FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"), 'and so are its limits');

        // ---- --reset-password: a fresh password that really is applied to the existing role
        [$code, $second] = self::tool($args + ['reset-password' => true] + $clearText);
        self::assertSame(0, $code, $second);
        self::assertStringContainsString('NEW PASSWORD, also when the role already exists', explode("\n", $second)[1]);
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
            self::fail('the old password still works after --reset-password');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
        self::assertStringNotContainsString(self::passwordOf($first), $old->lastError());
        $_SESSION = [];
        self::assertSame('25', self::adminQuery($engine, $db, $engine === 'pgsql'
            ? "SELECT rolconnlimit FROM pg_roles WHERE rolname = '{$role}'"
            : "SELECT max_user_connections FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"), 'the default limit is applied again');

        // ---- --grants-only: everything but the password; no password or DSN in the output
        [$code, $third] = self::tool($args + ['grants-only' => true, 'connection-limit' => '9']);
        self::assertSame(0, $code, $third);
        self::assertStringContainsString('its password is not changed', explode("\n", $third)[1]);
        foreach (['galbridge_db_dsn', 'IDENTIFIED BY', "PASSWORD '", 'SCRAM-SHA-256', 'CREATE USER', 'CREATE ROLE', '://' . $role] as $never) {
            self::assertStringNotContainsString($never, implode("\n", self::statementsOfAnyOutput($third)), '--grants-only: ' . $never);
        }
        self::assertStringNotContainsString('-- Put this line into', $third);
        self::assertDoesNotMatchRegularExpression("/^-- \\\$config\\['galbridge_db_dsn'\\]/m", $third);
        [$code, , $err] = self::adminScript($engine, $db, $third);
        self::assertSame(0, $code, 'applying --grants-only failed: ' . $err);
        $_SESSION = [];
        $conn = new Connection(new Dsn(self::dsnOf($second), Env::config(self::dsnOf($second))), 3);
        self::assertNotSame('', $conn->flavour(), 'the password is unchanged');
        self::assertSame('denied', $conn->probe('SELECT forwarding FROM forwardings WHERE 1 = 0'));
        $conn->close();
        self::assertSame('9', self::adminQuery($engine, $db, $engine === 'pgsql'
            ? "SELECT rolconnlimit FROM pg_roles WHERE rolname = '{$role}'"
            : "SELECT max_user_connections FROM mysql.user WHERE User = '{$role}' AND Host = 'localhost'"));

        // --grants-only for a role that does not exist creates nothing
        $ghost = 'gbt_cli_x' . getmypid();
        self::dropRole($engine, $db, $ghost);
        $this->roles[] = [$engine, $db, $ghost];
        [, $none] = self::tool(['grants', 'driver' => $engine, 'role' => $ghost, 'db' => $db, 'grants-only' => true]);
        [$code] = self::adminScript($engine, $db, $none);
        self::assertNotSame(0, $code);
        self::assertSame($engine === 'pgsql' ? '0' : '0', self::adminQuery($engine, $db, $engine === 'pgsql'
            ? "SELECT COUNT(*) FROM pg_roles WHERE rolname = '{$ghost}'"
            : "SELECT COUNT(*) FROM mysql.user WHERE User = '{$ghost}'"));
    }

    /**
     * The grants of GAL Bridge 1.0.0 gave the role the forwardings table. Applying the current
     * output with --grants-only to such a role takes that away on PostgreSQL; on MariaDB/MySQL the
     * output names the REVOKE statement to run once.
     */
    #[DataProvider('serverEngines')]
    public function testGrantsOnlyMovesARoleOfTheOldGrantsToTheView(string $dsn): void
    {
        [$engine, $db] = $this->target($dsn);
        $legacy = self::TEMPLATE_COLUMNS;
        $legacy[Schema::FORWARDINGS_TABLE] = $legacy[Schema::FORWARDINGS];
        unset($legacy[Schema::FORWARDINGS]);
        $roleDsn = $this->createRole($dsn, 'old', $legacy);
        $role = (string) (\rcube_db::parse_dsn($roleDsn)['username'] ?? '');

        // as it is, the plugin refuses the role and diag says what to do
        $factory = Env::factory(Env::config($roleDsn), self::JAN);
        self::assertSame([], $factory->books());
        self::assertSame('role_too_broad', $factory->resolver()->status());
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'table forwardings', 'READABLE: the role can read every row, including the private forwarding targets');
        self::assertLine($out, 'FAIL', 'columns ' . Schema::FORWARDINGS, 'missing or not granted');

        [$code, $grants] = self::tool(['grants', 'driver' => $engine, 'role' => $role, 'db' => $db, 'grants-only' => true] + $this->server($dsn));
        self::assertSame(0, $code, $grants);
        $this->apply($engine, $db, $role, $grants);
        if ($engine === 'mysql') {
            // MariaDB/MySQL cannot revoke "if granted": the statement is in the output, commented out
            self::assertMatchesRegularExpression("/^-- (REVOKE SELECT \\([a-z_, ]+\\) ON {$db}\\.forwardings FROM '{$role}'@'localhost';)$/m", $grants, 'the REVOKE names this role and database');
            preg_match("/^-- (REVOKE SELECT \\([a-z_, ]+\\) ON {$db}\\.forwardings FROM '{$role}'@'localhost';)$/m", $grants, $m);
            foreach ([$m[1], str_replace("'@'localhost'", "'@'%'", $m[1])] as $revoke) {
                [$code, , $err] = self::adminScript($engine, $db, $revoke);
                self::assertSame(0, $code, $err);
            }
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($roleDsn), self::JAN);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()), implode("\n", $GLOBALS['galbridge_test_log']));
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        self::assertSame(13, $book->list_records()->count);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'table forwardings', 'not readable');
    }

    /**
     * MySQL: the REQUIRE SSL statement for a remote account host is valid SQL and takes effect.
     */
    #[DataProvider('mysqlServer')]
    public function testRemoteMysqlAccountRequiresTls(string $dsn): void
    {
        [$engine, $db] = $this->target($dsn);
        if ($engine !== 'mysql') {
            self::markTestSkipped('MySQL/MariaDB account hosts only');
        }
        $role = 'gbt_cli_s' . getmypid();
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        $clearText = self::verifierApplies($engine, $db) ? [] : ['clear-text-password' => true];
        [$code, $out] = self::tool(['grants', 'driver' => 'mysql', 'role' => $role, 'db' => $db, 'account-host' => '10.0.0.%'] + $clearText);
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
        // exit code 0; the summary never hides WARN lines behind a bare "OK" (STAGE 6, I-03), and it
        // says what was not checked (second pass, F-27: no --url here)
        self::assertMatchesRegularExpression('/\nResult: OK( with \d+ warning\(s\) - read the WARN lines)? \(not checked: web access - run with --url\)\n$/', $out);
        self::assertSame(substr_count($out, '[WARN]') === 0, str_ends_with($out, "\nResult: OK (not checked: web access - run with --url)\n"));
        self::assertStringNotContainsString('[FAIL]', $out);
        self::assertNoPhpErrors($err);
        self::assertStringNotContainsString('testonly-ro', $out . $err, 'diag never prints the password');

        foreach ([
            ['OK', 'plugin enabled'], ['OK', 'config file'], ['OK', 'enabled books', 'galbridge_domain'],
            ['OK', 'directory hosts', '127.0.0.1:143 (derived from imap_host)'], ['OK', 'imap_host 127.0.0.1', 'covered'],
            ['OK', 'connect'], ['OK', 'password column', 'not readable (refused by the server)'], ['OK', 'write privileges', 'none ('],
            // second verification pass (F-03, F-04): what the role holds beyond the directory, and the view
            ['OK', 'role memberships', 'none'], ['OK', 'privileges outside', 'none outside the vmail'],
            ['OK', 'view galbridge_forwardings', 'holds alias, alias-member and list rows only'], ['OK', 'view galbridge_forwardings', 'no condition of the role sees a private row'],
            ['OK', 'secret columns', 'not visible'], ['OK', 'table forwardings', 'not readable by the role (it reads the view galbridge_forwardings'],
            ['OK', 'columns mailbox', 'ok'], ['OK', 'columns domain', 'ok'], ['OK', 'columns galbridge_forwardings', 'ok'],
            ['INFO', 'switches (books)', self::SWITCHES_BOOKS], ['INFO', 'switches (entries)', self::SWITCHES_ENTRIES], ['INFO', 'switches (controls)', self::SWITCHES_CONTROLS],
            ['INFO', 'viewer cache', '300 s (galbridge_scope_ttl)'], ['INFO', 'visibility column', 'not used'],
            ['INFO', 'web access', 'not checked: run diag --url='],
            ['OK', 'optional columns mailbox', 'ok'], ['OK', 'optional columns alias', 'ok'], ['OK', 'optional columns maillists', 'ok'],
            ['INFO', 'granted but unused', 'mailbox.telephone, mailbox.mobile, mailbox.birthday (not in galbridge_fields'],
            ['INFO', 'hosted domains', '4 active'], ['INFO', 'deliverable mailboxes', '11'],
            ['OK', 'home domain', 'example.com'], ['INFO', 'addresses', '4 (login + per-user aliases of deliverable domains'],
            ['OK', 'galbridge_domain', '1 domain(s), groups: department (3 groups), search-only: no'],
            ['INFO', 'persons', '7 ('], ['INFO', 'alias accounts', '5 ('], ['INFO', 'mailing lists', '1 ('],
        ] as $line) {
            self::assertLine($out, ...$line);
        }
        if ($engine === 'pgsql') {
            // PostgreSQL 16+ has no lc_ctype setting: the ctype comes from pg_database
            self::assertLine($out, 'OK', 'ctype');
            self::assertLine($out, 'OK', 'schema public', 'no CREATE privilege');
            self::assertStringNotContainsString('42704', $out);
            // the role of the grant template keeps its statements (search terms) out of the server log
            self::assertLine($out, 'OK', 'statement logging', 'off for this role');
        } else {
            // second verification pass (F-26): the statement time of the plugin's own session,
            // compared with galbridge_db_timeout (was an INFO line with the variable's name)
            self::assertMatchesRegularExpression('/\[ OK \] statement time \(this session\) +max_(statement|execution)_time=3(\.0+|000)? \(galbridge_db_timeout\)/', $out);
            self::assertMatchesRegularExpression('/\[( OK |WARN)\] statement logging +general query log /', $out);
            // database compatibility: no server-wide test database (mariadb-install-db) is open
            self::assertLine($out, 'OK', 'server-wide test database', 'none (no "test" or "test_*" database open to every account)');
        }
        self::assertSame($engine !== 'pgsql', str_contains($out, 'server-wide test database'), 'a MariaDB/MySQL line only');
        // the fixture role has no limit (load.sh: a PHPUnit process holds many connections): diag says
        // so for BOTH engines - it used to be silent for MariaDB/MySQL
        self::assertLine($out, 'WARN', 'connection limit', 'unlimited');
        self::assertStringNotContainsString('session limit', $out, 'the statement timeout and read-only mode of the session are in effect');
        foreach (['== Versions', '== Configuration', '== Installation', '== IMAP host binding', '== Core interplay', '== vmail database', '== User ' . self::JAN] as $section) {
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
        foreach (['alias', 'maillists', 'moderators', 'maillist_owners', Schema::FORWARDINGS] as $table) {
            self::assertStringNotContainsString('optional columns ' . $table, $out);
        }

        // hide_expired needs the expired column of alias accounts and lists: without it the kind is
        // LEFT OUT (as the WARN says) - it is not listed unfiltered
        $columns = self::TEMPLATE_COLUMNS;
        $columns['alias'] = array_values(array_diff($columns['alias'], ['expired']));
        $noAliasExpiry = $this->createRole($dsn, 'ae', $columns);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noAliasExpiry], [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'optional columns alias', 'missing or not granted: expired (alias accounts / mailing lists are left out)');
        self::assertStringNotContainsString('alias accounts ', (string) preg_replace('/^.*== User /s', '', $out), 'no alias account is counted for the user');
        self::assertLine($out, 'INFO', 'mailing lists', '1 (');
        // without hide_expired the column is not needed: alias accounts are back
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noAliasExpiry, 'galbridge_hide_expired' => false], [], ['--user=' . self::JAN]);
        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('[WARN] optional columns', $out);
        self::assertMatchesRegularExpression('/^  \[INFO\]   alias accounts +[1-9]\d* \(/m', $out);
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
            // through the view the plugin reads: a simple view is updatable
            $out["{$engine}: DELETE on the forwardings view"] = [$dsn, 'DELETE', Schema::FORWARDINGS];
            $out["{$engine}: table UPDATE on domain"] = [$dsn, 'UPDATE', 'domain'];
            $out["{$engine}: table INSERT on alias"] = [$dsn, 'INSERT', 'alias'];
        }
        return $out;
    }

    /**
     * The write tripwire reads the catalogs - and on MariaDB/MySQL also asks the server with
     * statements that can change nothing: a role that can modify vmail is a FAIL, although the
     * plugin's own session is read-only.
     */
    #[DataProvider('writeGrants')]
    public function testDiagFailsForAWriteCapableRole(string $dsn, string $privilege, string $table): void
    {
        $roleDsn = $this->createRole($dsn, 'w', self::TEMPLATE_COLUMNS, [[$privilege, $table]]);
        [$code, $out, $err] = $this->diag(['galbridge_db_dsn' => $roleDsn], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'write privileges', 'the role can modify vmail (');
        self::assertLine($out, 'OK', 'password column', 'not readable');
        // second verification pass (F-04): the plugin refuses such a role, and --user says so
        self::assertLine($out, 'FAIL', 'mailbox', 'status: role_too_broad');
        self::assertSame(2, substr_count($out, '[FAIL]'), 'the write privilege is the only problem: ' . $out);
        self::assertStringEndsWith("\nResult: 2 problem(s) found\n", $out);
        self::assertStringContainsString('== User ' . self::JAN, $out, 'the remaining sections still run');
        self::assertNoPhpErrors($err);
    }

    /**
     * The required columns depend on the configuration (Schema): mailbox/domain.expired only with
     * galbridge_hide_expired. diag and the runtime agree in every combination.
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

        // the domain column of the forwardings view is always required
        $columns = self::TEMPLATE_COLUMNS;
        $columns[Schema::FORWARDINGS] = array_values(array_diff($columns[Schema::FORWARDINGS], ['domain']));
        $noForwardingDomain = $this->createRole($dsn, 'nf', $columns);
        foreach ([[], ['galbridge_user_aliases' => true]] as $options) {
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $noForwardingDomain] + $options, [], ['--user=' . self::JAN]);
            self::assertSame(2, $code, $out);
            self::assertLine($out, 'FAIL', 'columns galbridge_forwardings', 'missing or not granted: domain');
            self::assertLine($out, 'FAIL', 'mailbox', '(status: column_missing)');
        }

        // a role without the view at all: the hint names the way out
        $columns = self::TEMPLATE_COLUMNS;
        unset($columns[Schema::FORWARDINGS]);
        $noView = $this->createRole($dsn, 'nv', $columns);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $noView]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'columns galbridge_forwardings', 'missing or not granted: address, forwarding, domain, is_alias, active (the view of the grant template: run galbridge.sh grants, --grants-only for an existing role)');
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
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        [$code, $out] = $this->diag(['galbridge_db_dsn' => 'mysql://vmail:Secret-Vmail-pw@127.0.0.1/vmail']);
        self::assertSame(2, $code, $out);
        self::assertMatchesRegularExpression('/^  \[FAIL\] galbridge_db_dsn /m', $out);
        self::assertStringNotContainsString('Secret-Vmail-pw', $out);
        self::assertStringNotContainsString('[ OK ] connect', $out);
    }

    /**
     * The configuration file holds the database credential and the visibility policy. As root the
     * script itself refuses a configuration that another account could have written (it is PHP code
     * the tool would run): those cases never reach diag.
     */
    public function testDiagChecksTheConfigurationFile(): void
    {
        $dsn = $this->anyServerDsn();
        $dir = $this->writeConfig(['galbridge_db_dsn' => $dsn]);
        $file = $dir . '/galbridge.inc.php';
        $refused = static function (array $run) use ($file, $dir): void {
            self::assertSame(1, $run[0], $run[1]);
            self::assertSame('', $run[1], 'nothing was checked');
            self::assertMatchesRegularExpression('~^galbridge\.sh --allow-root refused: (' . preg_quote($file, '~') . '|' . preg_quote($dir, '~') . ') \(owner uid \d+, mode \d{4}\) is not owned by root or is writable by its group or by others\.$~', trim($run[2]));
        };

        chmod($file, 0o644);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'config file', $file . ' (mode 0644: readable by everyone - use 0640 root:<web server group>)');

        chmod($file, 0o660);
        if (self::isRoot()) {
            $refused($this->runDiag($dir));
        } else {
            [$code, $out] = $this->runDiag($dir);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'OK', 'config file', $file . ' (mode 0660)');
            self::assertLine($out, 'WARN', 'config file', 'writable by its group (mode 0660)');
        }

        chmod($file, 0o640);
        chmod($dir, 0o777);
        if (self::isRoot()) {
            $refused($this->runDiag($dir));
        } else {
            [$code, $out] = $this->runDiag($dir);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'WARN', 'config directory', $dir . ' is writable by group/others (mode 0777)');
            chmod($dir, 0o1777); // sticky: others cannot replace the file
            [$code, $out] = $this->runDiag($dir);
            // (second pass, F-27: the directory still belongs to the PHP user - its own WARN line)
            self::assertStringNotContainsString('is writable by group/others', $out);
        }
        chmod($dir, 0o700);

        [$code, $out] = $this->runDiag($dir);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'config file', $file . ' (mode 0640)');
        self::assertDoesNotMatchRegularExpression('/\[FAIL\] config /', $out);
        if (self::isRoot()) {
            self::assertDoesNotMatchRegularExpression('/\[WARN\] config /', $out, 'owned by root, mode 0640: nothing to say');
            // a file of another account is refused before anything is loaded
            self::assertTrue(chown($file, 65534));
            $refused($this->runDiag($dir));
            chown($file, 0);
        } else {
            // the PHP user owns the file: it can rewrite the directory policy whatever the mode says
            self::assertLine($out, 'WARN', 'config file', 'owned by uid ' . Tool::euid() . ' (the user running this tool, i.e. the PHP user: the web server process can change the directory policy) - expected root');
        }

        unlink($file);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'config file', 'neither plugins/galbridge/config.inc.php nor config/galbridge.inc.php exists');
    }

    /**
     * The tool loads PHP code from the plugin and the Roundcube directory (and Roundcube's
     * configuration is PHP as well). As root it does not run at all unless --allow-root is given -
     * and then only when all of that belongs to root.
     */
    #[Group('root')]
    public function testTheScriptRefusesToRunAsRoot(): void
    {
        if (!self::isRoot()) {
            self::markTestSkipped('needs root (the refusal applies to root only)');
        }
        $env = ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'];
        foreach ([['diag'], ['help'], []] as $args) {
            [$code, $out, $err] = self::cli([...$args, '--no-allow-root'], $env);
            self::assertSame(1, $code);
            self::assertSame('', $out, 'no statement, no password, no check');
            self::assertStringStartsWith('galbridge.sh must not run as root: it loads PHP code from the plugin and the Roundcube directory.', $err);
            self::assertStringContainsString('sudo -u www-data', $err);
            // 1.0.4: iRedMail does not install sudo - the message names the form without it
            self::assertStringContainsString('runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag', $err);
        }
        // grants is meant to run as root - with --allow-root, which checks the code first (second
        // verification pass, F-02): the refusal says how
        [$code, $out, $err] = self::cli(['grants', '--driver=mysql', '--no-allow-root'], $env);
        self::assertSame(1, $code);
        self::assertSame('', $out, 'no statement, no password');
        self::assertStringStartsWith('galbridge.sh grants runs as root only with --allow-root, which first checks that root alone can change the code it loads and prints:', $err);
        self::assertStringContainsString('grants --allow-root --driver=', $err);
        // --allow-root with root-owned code works (this checkout); with a configuration that somebody
        // else could have written it does not (testDiagChecksTheConfigurationFile)
        [$code, $out] = self::cli(['grants', '--driver=mysql'], $env);
        self::assertSame(0, $code);
        self::assertStringContainsString('CREATE USER', $out);
    }

    /**
     * The posix extension is a package of its own on several platforms (php-process, php-posix) and
     * no requirement of the plugin. Without it the script must still know its user - on Linux from
     * the kernel - instead of taking "cannot tell" for "not root" and loading code as root.
     */
    public function testTheScriptKnowsItsUserWithoutThePosixExtension(): void
    {
        // php -n: no configuration file, so no extension that is loaded as a module
        $bare = [PHP_BINARY, '-n', '-d', 'display_errors=stderr'];
        [$code, $has] = self::exec([...$bare, '-r', 'echo function_exists("posix_geteuid") ? "posix" : "none";']);
        if ($code !== 0 || $has !== 'none') {
            self::markTestSkipped('this PHP has the posix extension compiled in');
        }
        if (!is_readable('/proc/self/status')) {
            self::markTestSkipped('needs /proc (Linux)');
        }
        [$code, $uid, $err] = self::exec([...$bare, '-r', 'require $argv[1]; var_export(GalBridge\\Cli\\Tool::euid());', self::pluginDir() . '/lib/autoload.php']);
        self::assertSame(0, $code, $err);
        // (the parent asks the posix extension when it has one: an independent second source)
        self::assertSame((string) Tool::euid(), $uid, 'the user of the process, read from /proc/self/status');

        $script = self::pluginDir() . '/bin/galbridge.sh';
        [$code, $out, $err] = self::exec([...$bare, $script, 'grants', '--driver=mysql'], null, ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'] + getenv());
        if (self::isRoot()) {
            self::assertSame(1, $code, $out);
            self::assertSame('', $out, 'no statement, no password');
            self::assertStringStartsWith('galbridge.sh grants runs as root only with --allow-root', $err);
        } else {
            self::assertSame(0, $code, $err);
            self::assertStringContainsString('CREATE USER', $out);
            // (the DSN line is on standard error since the second verification pass - F-28)
            self::assertStringNotContainsString('refused', $err);
            self::assertStringNotContainsString('as root', $err);
        }
    }

    /**
     * --allow-root passes only when EVERYTHING the tool then loads as root belongs to root and can be
     * written by nobody else: Roundcube's start loads every enabled plugin, classes of vendor/ on
     * demand and the localization index, and the plugin reads the configuration of the password
     * plugin - not only the files of GAL Bridge and of the core library.
     */
    #[Group('root')]
    public function testAllowRootChecksEverythingThatIsLoadedAsRoot(): void
    {
        if (!self::isRoot()) {
            self::markTestSkipped('needs root (--allow-root is for root only)');
        }
        $www = $this->base() . '/www';
        $rc = $www . '/roundcube';
        $files = [
            // the checks passed when one of these files is loaded (a process of root loads iniset.php
            // first, to check where PHP writes its messages - second pass): it reports the environment
            // and ends the program, which the script reports as "Result: aborted" (exit code 2)
            'program/include/iniset.php' => '<?php echo "' . self::FAKE_CORE . '\n", get_include_path(), "\n", getenv("ROUNDCUBE_LOG_DRIVER"), "\n"; exit(42);',
            'program/include/clisetup.php' => '<?php echo "' . self::FAKE_CORE . '\n", get_include_path(), "\n", getenv("ROUNDCUBE_LOG_DRIVER"), "\n"; exit(42);',
            'program/lib/Roundcube/bootstrap.php' => '<?php',
            'program/localization/index.inc' => '<?php',
            'plugins/foo/foo.php' => '<?php',
            'plugins/foo/lib/deep/more.php' => '<?php',
            'plugins/password/config.inc.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'vendor/composer/autoload_real.php' => '<?php',
            'vendor/pear/net_smtp/Net/SMTP.php' => '<?php',
            'config/config.inc.php' => '<?php',
            'config/password.inc.php' => '<?php',
        ];
        foreach ($files as $file => $content) {
            if (!is_dir(dirname($rc . '/' . $file))) {
                mkdir(dirname($rc . '/' . $file), 0o755, true);
            }
            file_put_contents($rc . '/' . $file, $content);
            chmod($rc . '/' . $file, 0o644);
        }
        // the plugin as a release, installed through a symbolic link - and another plugin likewise
        $plugin = $this->releaseCopy('galbridge-1.0.1');
        symlink($plugin, $rc . '/plugins/galbridge');
        mkdir($this->base() . '/elsewhere/bar', 0o755, true);
        file_put_contents($this->base() . '/elsewhere/bar/bar.php', '<?php');
        symlink($this->base() . '/elsewhere/bar', $rc . '/plugins/bar');

        $run = fn (): array => self::exec([PHP_BINARY, '-d', 'display_errors=stderr', $plugin . '/bin/galbridge.sh', 'diag', '--allow-root'], null,
            ['ROUNDCUBE_INSTALL_PATH' => $rc, 'ROUNDCUBE_CONFIG_DIR' => $rc . '/config'] + getenv(), $rc);
        [$code, $out, $err] = $run();
        self::assertCoreLoaded([$code, $out, $err], 'everything belongs to root');
        // STAGE 6: no relative include_path entry (the working directory of root), logs to syslog
        [, $includePath, $logDriver] = explode("\n", $out) + ['', '', ''];
        self::assertNotSame('', $includePath);
        foreach (explode(PATH_SEPARATOR, $includePath) as $entry) {
            self::assertStringStartsWith('/', $entry, $includePath);
        }
        self::assertSame('syslog', $logDriver, 'root never writes into log_dir, which the web server user owns');

        $nobody = 65534;
        foreach ([
            'a file of another plugin' => [$rc . '/plugins/foo/foo.php', 0o666, null],
            'a file deep inside a plugin' => [$rc . '/plugins/foo/lib/deep/more.php', 0o664, null],
            'a directory inside a plugin' => [$rc . '/plugins/foo/lib/deep', 0o777, null],
            'the configuration of the password plugin' => [$rc . '/plugins/password/config.inc.php', 0o400, $nobody],
            'password.inc.php in the configuration directory' => [$rc . '/config/password.inc.php', 0o640, $nobody],
            'a file of Composer' => [$rc . '/vendor/composer/autoload_real.php', 0o664, null],
            'a class of a package' => [$rc . '/vendor/pear/net_smtp/Net/SMTP.php', 0o644, $nobody],
            'the localization index' => [$rc . '/program/localization/index.inc', 0o666, null],
            'a plugin behind a symbolic link' => [$this->base() . '/elsewhere/bar/bar.php', 0o666, null],
            'the directory above the target of that link' => [$this->base() . '/elsewhere', 0o777, null],
            'the main file of this plugin' => [$plugin . '/galbridge.php', 0o666, null],
            'a localization file of this plugin' => [$plugin . '/localization/en_US.inc', 0o600, $nobody],
            'the directory above the installation' => [$www, 0o777, null],
        ] as $what => [$path, $mode, $owner]) {
            $before = [fileperms($path) & 0o7777, (int) fileowner($path)];
            chmod($path, $mode);
            if ($owner !== null) {
                chown($path, $owner);
            }
            clearstatcache();
            try {
                [$code, $out, $err] = $run();
                self::assertSame(1, $code, $what . ': ' . $err);
                self::assertSame('', $out, $what);
                self::assertStringStartsWith('galbridge.sh --allow-root refused: ' . $path . ' (owner uid ' . ($owner ?? 0) . ', mode ' . sprintf('%04o', $mode) . ') is not owned by root or is writable', $err, $what);
            } finally {
                chown($path, $before[1]);
                chmod($path, $before[0]);
                clearstatcache();
            }
        }
        // a directory with the sticky bit above the installation (/tmp) lets nobody replace what
        // belongs to root: accepted there - but never as a directory that holds code
        chmod($www, 0o1777);
        clearstatcache();
        self::assertCoreLoaded($run());
        chmod($www, 0o755);
        chmod($rc . '/plugins', 0o1777);
        clearstatcache();
        [$code, , $err] = $run();
        self::assertSame(1, $code);
        self::assertStringContainsString($rc . '/plugins (owner uid 0, mode 1777)', $err);
        chmod($rc . '/plugins', 0o755);
        clearstatcache();
        self::assertCoreLoaded($run());

        // STAGE 6: a symbolic link to nothing - whoever creates its target later decides what root loads
        symlink($this->base() . '/not-yet', $rc . '/plugins/later');
        [$code, , $err] = $run();
        self::assertSame(1, $code, $err);
        self::assertStringContainsString($rc . '/plugins/later (symbolic link to a path that does not exist)', $err);
        unlink($rc . '/plugins/later');

        // STAGE 6: the configuration directories as Roundcube finds them - RCUBE_CONFIG_PATH as well,
        // and ROUNDCUBE_CONFIG_DIR "0" means the default directory (iniset.php reads it with ?:)
        mkdir($this->base() . '/conf-extra', 0o755);
        file_put_contents($this->base() . '/conf-extra/config.inc.php', '<?php');
        chmod($this->base() . '/conf-extra/config.inc.php', 0o666);
        $env = ['ROUNDCUBE_INSTALL_PATH' => $rc, 'ROUNDCUBE_CONFIG_DIR' => $rc . '/config'] + getenv();
        $script = [PHP_BINARY, '-d', 'display_errors=stderr', $plugin . '/bin/galbridge.sh', 'diag', '--allow-root'];
        [$code, , $err] = self::exec($script, null, ['RCUBE_CONFIG_PATH' => $this->base() . '/conf-extra'] + $env, $rc);
        self::assertSame(1, $code, $err);
        self::assertStringContainsString($this->base() . '/conf-extra/config.inc.php (owner uid 0, mode 0666)', $err);
        [$code, , $err] = self::exec($script, null, ['RCUBE_CONFIG_PATH' => $this->base() . '/conf-none'] + $env, $rc);
        self::assertSame(1, $code, $err);
        self::assertStringContainsString('conf-none (configuration directory that does not exist)', $err);
        chmod($rc . '/config/config.inc.php', 0o666);
        clearstatcache();
        [$code, , $err] = self::exec($script, null, ['ROUNDCUBE_CONFIG_DIR' => '0'] + $env, $rc);
        self::assertSame(1, $code, $err);
        self::assertStringContainsString($rc . '/config/config.inc.php (owner uid 0, mode 0666)', $err);
        chmod($rc . '/config/config.inc.php', 0o644);
        clearstatcache();
        self::assertCoreLoaded($run());
    }

    // ------------------------------------------------------------------ diag: audit regressions

    /**
     * A switch that is not a boolean (U-03) and a visibility column that is not a string (U-02) are
     * FAIL lines that say every book is disabled; the effective switches are always shown.
     *
     * @return array<string, array{string, mixed}>
     */
    public static function ambiguousOptions(): array
    {
        return [
            'string false for the global book' => ['galbridge_global_book', 'false'],
            'string no for the plaintext exception' => ['galbridge_db_allow_plaintext', 'no'],
            'string for debug' => ['galbridge_debug', 'off'],
            'array for the visibility column' => ['galbridge_visibility_column', ['galbridge_visible']],
            'true for the visibility column' => ['galbridge_visibility_column', true],
            'string for search-only' => ['galbridge_domain_searchonly', 'false'],
        ];
    }

    #[DataProvider('ambiguousOptions')]
    public function testDiagFailsForAnOptionOfTheWrongType(string $option, mixed $value): void
    {
        $dsn = $this->anyServerDsn();
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, $option => $value], [], ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        self::assertMatchesRegularExpression('/^  \[FAIL\] ' . preg_quote($option, '/') . ' +must be .*: all books are disabled$/m', $out);
        self::assertStringNotContainsString('[ OK ] galbridge_domain ', $out, 'the user gets no book');
        self::assertLine($out, 'WARN', 'books', 'none for this user');
        if ($option !== 'galbridge_visibility_column') {
            self::assertLine($out, 'FAIL', 'enabled books', 'none: a switch has an ambiguous value');
            // what the switch is read as meanwhile is its restrictive value - and diag shows it,
            // marked: off for the three booleans, on (search-only) for the tristate
            $short = substr($option, strlen('galbridge_'));
            self::assertMatchesRegularExpression('/^  \[INFO\] switches \((books|entries|controls)\) .*\b' . preg_quote($short, '/')
                . ' ' . ($option === 'galbridge_domain_searchonly' ? 'on' : 'off') . ' \(INVALID\)(,|$)/m', $out);
            self::assertSame(1, substr_count($out, '(INVALID)'), 'the other switches are shown as they are');
            self::assertLine($out, 'INFO', 'switches (entries)', self::SWITCHES_ENTRIES);
        }
    }

    public function testDiagShowsEffectiveSwitchesAndEnvironmentOverrides(): void
    {
        $dsn = $this->anyServerDsn();
        $dir = $this->writeConfig(['galbridge_db_dsn' => $dsn, 'galbridge_user_aliases' => true, 'galbridge_list_policy' => 'public_only', 'galbridge_scope_ttl' => 60]);
        [$code, $out] = $this->runDiag($dir);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'INFO', 'switches (books)', self::SWITCHES_BOOKS);
        self::assertLine($out, 'INFO', 'switches (entries)', 'user_aliases on, include_aliases on, include_mailing_lists on, hide_expired on, mark_foreign_domains on, list_policy public_only');
        self::assertLine($out, 'INFO', 'switches (controls)', self::SWITCHES_CONTROLS);
        self::assertLine($out, 'INFO', 'viewer cache', '60 s (galbridge_scope_ttl)');
        self::assertStringNotContainsString('[WARN] environment', $out);
        // EVERY switch of the configuration is shown, under the name of its option
        foreach ([...Config::BOOLEANS, ...Config::TRISTATES] as $switch) {
            self::assertMatchesRegularExpression('/^  \[INFO\] switches \((books|entries|controls)\) .*\b' . preg_quote(substr($switch, strlen('galbridge_')), '/') . ' (on|off|auto)(,|$)/m', $out, $switch);
        }
        self::assertStringNotContainsString('[WARN] switches', $out);

        // "false" in the environment means false (Roundcube itself would read it as true) ...
        $env = ['ROUNDCUBE_INSTALL_PATH' => rtrim((string) constant('RCUBE_INSTALL_PATH'), '/'), 'ROUNDCUBE_CONFIG_DIR' => $dir];
        [$code, $out] = self::cli(['diag'], $env + ['ROUNDCUBE_GALBRIDGE_USER_ALIASES' => 'false']);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'environment', 'ROUNDCUBE_GALBRIDGE_USER_ALIASES overrides galbridge_user_aliases');
        self::assertLine($out, 'INFO', 'switches (entries)', 'user_aliases off, ');
        // the same for the switches that only narrow what is shown: how the text was read is visible
        [$code, $out] = self::cli(['diag'], $env + ['ROUNDCUBE_GALBRIDGE_INCLUDE_MAILING_LISTS' => 'false', 'ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT' => '0', 'ROUNDCUBE_GALBRIDGE_DOMAIN_SEARCHONLY' => 'auto']);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'INFO', 'switches (entries)', 'include_mailing_lists off, ');
        self::assertLine($out, 'INFO', 'switches (books)', 'domain_export off, domain_searchonly auto, ');
        // ... and anything else is refused
        [$code, $out] = self::cli(['diag'], $env + ['ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK' => 'off']);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'galbridge_global_book', 'environment variable ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK must be 1, 0, true or false: all books are disabled');
        // the value of an override is never printed (it may be the DSN)
        [$code, $out] = self::cli(['diag'], $env + ['ROUNDCUBE_GALBRIDGE_DB_DSN' => 'mysql://vmail:Env-Secret-pw@127.0.0.1/vmail']);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'WARN', 'environment', 'ROUNDCUBE_GALBRIDGE_DB_DSN overrides galbridge_db_dsn');
        self::assertStringNotContainsString('Env-Secret-pw', $out);
    }

    /**
     * The connection limit of the account is reported for both engines: the role of the grant
     * template has one (25), a role without one is a WARN.
     */
    #[DataProvider('serverEngines')]
    public function testDiagReportsTheConnectionLimitOfTheAccount(string $dsn): void
    {
        [$engine, $db] = $this->target($dsn);
        $role = 'gbt_cli_l' . getmypid();
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        $clearText = self::verifierApplies($engine, $db) ? [] : ['clear-text-password' => true];
        [$code, $grants] = self::tool(['grants', 'driver' => $engine, 'role' => $role, 'db' => $db] + $this->server($dsn) + $clearText);
        self::assertSame(0, $code, $grants);
        $this->apply($engine, $db, $role, $grants);

        [$code, $out] = $this->diag(['galbridge_db_dsn' => self::dsnOf($grants)]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'connection limit', '25 (at most the PHP-FPM pm.max_children of Roundcube and what the server\'s max_connections can spare - README 2.2)');
        self::assertLine($out, 'OK', 'table forwardings', 'not readable');
        self::assertStringNotContainsString('[WARN] connection limit', $out);
        if ($engine === 'pgsql') {
            self::assertLine($out, 'OK', 'statement logging', 'off for this role');
        }

        // a role created by hand without a limit
        $bare = $this->createRole($dsn, 'nl', self::TEMPLATE_COLUMNS);
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $bare]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'connection limit', 'unlimited');
        if ($engine === 'pgsql') {
            self::assertLine($out, 'WARN', 'statement logging', 'the server log can record statements of this role with the search terms');

            // A server can also log a SAMPLE of the statements, or of whole transactions, whatever
            // log_statement and log_min_duration_statement say: diag sees that for the role too.
            foreach ([
                'log_min_duration_sample = 0' => 'log_min_duration_sample=0 with log_statement_sample_rate=1',
                'log_transaction_sample_rate = 0.5' => 'log_transaction_sample_rate=0.5',
            ] as $setting => $reported) {
                self::assertSame(0, self::adminScript($engine, $db, "ALTER ROLE {$role} SET {$setting};")[0]);
                [$code, $out] = $this->diag(['galbridge_db_dsn' => self::dsnOf($grants)]);
                self::assertSame(0, $code, $out);
                self::assertLine($out, 'WARN', 'statement logging', $reported);
                self::assertSame(0, self::adminScript($engine, $db, 'ALTER ROLE ' . $role . ' RESET ' . explode(' ', $setting)[0] . ';')[0]);
            }
            // the grant set puts the settings back (--grants-only: the password stays)
            [$code, $again] = self::tool(['grants', 'driver' => $engine, 'role' => $role, 'db' => $db, 'grants-only' => true] + $this->server($dsn));
            self::assertSame(0, $code, $again);
            $this->apply($engine, $db, $role, $again);
            [$code, $out] = $this->diag(['galbridge_db_dsn' => self::dsnOf($grants)]);
            self::assertLine($out, 'OK', 'statement logging', 'off for this role');
        }
    }

    /**
     * MariaDB/MySQL: a write privilege that reaches the account through a ROLE is not among the
     * account's own grants. diag asks the server itself, with statements that can change nothing.
     * Both flavours, each with its own role syntax (second verification pass, database
     * compatibility: MySQL was skipped):
     *  - the role granted but not active in a new session (no default role): the write statements
     *    are refused, so 'write privileges' is OK - and 'role memberships' FAILS, because SET ROLE
     *    reaches the privilege at any time (information_schema.APPLICABLE_ROLES lists the role);
     *  - the role active by default: 'write privileges' FAILS as well (UPDATE on alias).
     * The plugin refuses the account in both cases (role_too_broad: a membership).
     */
    #[DataProvider('mysqlServer')]
    public function testDiagFindsWritePrivilegesGrantedThroughARole(string $dsn): void
    {
        [$engine, $db] = $this->target($dsn);
        if ($engine !== 'mysql') {
            self::markTestSkipped('MariaDB/MySQL roles (PostgreSQL: has_table_privilege() covers inherited privileges)');
        }
        $mariadb = stripos(self::adminQuery($engine, $db, 'SELECT VERSION()'), 'mariadb') !== false;
        $roleDsn = $this->createRole($dsn, 'wr', self::TEMPLATE_COLUMNS);
        $account = (string) (\rcube_db::parse_dsn($roleDsn)['username'] ?? '');
        $group = 'gbt_cli_grp' . getmypid();
        $accounts = "'{$account}'@'localhost', '{$account}'@'%'";
        self::adminScript($engine, $db, "DROP ROLE IF EXISTS {$group};");
        try {
            [$code, , $err] = self::adminScript($engine, $db, "CREATE ROLE {$group}; GRANT UPDATE ON `{$db}`.`alias` TO {$group}; GRANT {$group} TO {$accounts};");
            self::assertSame(0, $code, $err);

            // granted, not active: no write statement gets through, the membership is a FAIL
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn]);
            self::assertSame(2, $code, $out);
            self::assertLine($out, 'OK', 'write privileges', 'none (');
            self::assertLine($out, 'FAIL', 'role memberships', $group . ' - SET ROLE reaches their privileges');
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $_SESSION = [];
            $factory = Env::factory(Env::config($roleDsn), self::JAN);
            self::assertSame([], $factory->books());
            self::assertStringContainsString('role_too_broad: the configured database role can do more than read the directory - it is a member of the role(s) ' . $group, implode("\n", $GLOBALS['galbridge_test_log']));

            // active by default (MariaDB: SET DEFAULT ROLE r FOR a; MySQL: SET DEFAULT ROLE ALL TO a)
            [$code, , $err] = self::adminScript($engine, $db, $mariadb
                ? "SET DEFAULT ROLE {$group} FOR '{$account}'@'localhost'; SET DEFAULT ROLE {$group} FOR '{$account}'@'%';"
                : "SET DEFAULT ROLE ALL TO {$accounts};");
            self::assertSame(0, $code, $err);
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $roleDsn]);
            self::assertSame(2, $code, $out);
            self::assertLine($out, 'FAIL', 'write privileges', 'the role can modify vmail (UPDATE on alias)');
            self::assertLine($out, 'FAIL', 'role memberships', $group);
        } finally {
            self::adminScript($engine, $db, "DROP ROLE IF EXISTS {$group};");
        }
    }

    /**
     * --user is validated before anything of it is printed: the section title shows the normalised
     * login or says that the value is no address.
     */
    public function testDiagNeverEchoesAnInvalidUserArgument(): void
    {
        $dsn = $this->anyServerDsn();
        foreach (["evil\x1b[31m@x", 'not an address', "a@b\nResult: OK", str_repeat('x', 300)] as $user) {
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--user=' . $user]);
            self::assertSame(2, $code, $out);
            self::assertStringContainsString("\n== User (not a valid address)\n", $out);
            self::assertLine($out, 'FAIL', 'login', 'not a valid address');
            self::assertStringNotContainsString("\x1b", $out);
            self::assertStringNotContainsString('evil', $out);
            self::assertSame(1, substr_count($out, 'Result: '), 'the argument cannot forge a result line');
        }
        [, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--user=  JAN.Kowalski@EXAMPLE.com ']);
        self::assertStringContainsString("\n== User " . self::JAN . "\n", $out, 'the normalised login');
    }

    /**
     * The Roundcube branch is supported as a whole: every 1.7.x core is accepted; an older patch
     * release is a WARN that asks for the CORE to be updated (never a reason to hold it back).
     */
    public function testDiagAcceptsEveryReleaseOfTheSupportedBranch(): void
    {
        $dsn = $this->anyServerDsn();
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        self::assertSame(0, $code, $out);
        // the release of the tree the suite runs against (the library alone defines no RCMAIL_VERSION)
        self::assertSame(1, preg_match("/define\\('RCMAIL_VERSION', '([^']+)'\\)/", (string) file_get_contents(rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') . '/program/include/iniset.php'), $m));
        $version = $m[1];
        self::assertSame('1.7', Tool::SUPPORTED_BRANCH);
        if (preg_match('/^1\.7\.\d+$/', $version) !== 1) {
            self::assertLine($out, 'WARN', 'Roundcube', '1.7-git');
        } elseif (version_compare($version, Tool::LATEST_KNOWN_ROUNDCUBE, '<')) {
            self::assertLine($out, 'WARN', 'Roundcube', $version . ' (supported, but older than ' . Tool::LATEST_KNOWN_ROUNDCUBE . ': later 1.7.x releases fix security problems of Roundcube itself - update the core');
        } else {
            self::assertLine($out, 'OK', 'Roundcube', $version);
        }
        self::assertStringNotContainsString('[FAIL] Roundcube', $out);
    }

    /**
     * include_host_config makes the effective imap_host depend on the Host header: nothing is
     * derived from it (U-11), and diag cannot see the per-host files.
     */
    public function testDiagWarnsAboutHostDependentConfiguration(): void
    {
        $dsn = $this->anyServerDsn();
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], ['include_host_config' => true]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'WARN', 'include_host_config', 'enabled (or ROUNDCUBE_INCLUDE_HOST_CONFIG is set): Roundcube merges a configuration file chosen by the Host header of the request');
        self::assertLine($out, 'FAIL', 'directory hosts', 'plugin disabled: include_host_config is enabled');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143']], ['include_host_config' => true]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'directory hosts', '127.0.0.1:143 (galbridge_imap_hosts)');
        self::assertLine($out, 'OK', 'imap_host 127.0.0.1', 'covered');

        // a listed port that is not the one Roundcube connects to covers nothing
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:993']]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'imap_host 127.0.0.1', 'not in galbridge_imap_hosts');

        // several servers: nothing can verify that all of them use this vmail database
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1', 'imap2.example.com:993']]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'directory hosts', 'several or user-chosen IMAP servers: EVERY listed server must authenticate against this vmail database');
    }

    /**
     * diag --url asks the web server for files of the plugin directory. Against a server that hands
     * them out it is a FAIL; against one that answers 404 it is OK; an unusable URL is refused.
     */
    public function testDiagChecksWhetherTheWebServerServesThePluginDirectory(): void
    {
        $dsn = $this->anyServerDsn();
        foreach (['ftp://mail.example.com/', 'https://mail.example.com/?x=1', 'mail.example.com', 'https://user:pw@mail.example.com/', "https://mail.example.com/\n"] as $bad) {
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=' . $bad]);
            self::assertSame(2, $code, $out);
            self::assertLine($out, 'FAIL', 'web access', '--url must be the http(s) address of the webmail');
        }

        // a web server that serves everything below its document root, plugin files included
        $root = $this->base() . '/htdocs';
        mkdir($root . '/plugins/galbridge/SQL', 0o700, true);
        file_put_contents($root . '/plugins/galbridge/composer.json', '{}');
        file_put_contents($root . '/plugins/galbridge/SQL/grants.mysql.sql', '-- test');
        // ... and answers like Roundcube at its address: every page of the webmail creates this object
        file_put_contents($root . '/index.html', '<html><head><script>var rcmail = new rcube_webmail();</script></head><body></body></html>');
        [$server, $port] = self::webServer($root);
        try {
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=http://127.0.0.1:' . $port . '/']);
            self::assertSame(2, $code, $out);
            self::assertLine($out, 'OK', 'web access', 'http://127.0.0.1:' . $port . '/ is the webmail');
            self::assertLine($out, 'FAIL', 'web access', 'plugins/galbridge/composer.json is SERVED (HTTP 200): deny the plugin directory in the web server (README 2.6)');
            self::assertLine($out, 'FAIL', 'web access', 'plugins/galbridge/SQL/grants.mysql.sql is SERVED (HTTP 200)');
            self::assertLine($out, 'OK', 'web access', 'static.php/plugins/galbridge/composer.json is not served (HTTP 404)');

            // the same server without the files: nothing is served
            unlink($root . '/plugins/galbridge/composer.json');
            unlink($root . '/plugins/galbridge/SQL/grants.mysql.sql');
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=http://127.0.0.1:' . $port]);
            self::assertSame(0, $code, $out);
            self::assertSame(5, substr_count($out, '[ OK ] web access'), 'the webmail and four paths that are not served');
            self::assertStringNotContainsString('not checked: run diag --url', $out);

            // An address that is NOT the webmail - a wrong prefix, another virtual host - answers 404
            // for everything as well: diag says that its "not served" lines then mean nothing.
            mkdir($root . '/other');
            foreach (['other/', 'nowhere/'] as $prefix) {
                [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=http://127.0.0.1:' . $port . '/' . $prefix]);
                self::assertSame(0, $code, $out);
                self::assertLine($out, 'WARN', 'web access', 'http://127.0.0.1:' . $port . '/' . $prefix . ' does not answer like the Roundcube webmail (HTTP 404): check the address');
                self::assertStringNotContainsString('is the webmail', $out);
                // ... and no path gets an [ OK ] from such an address (STAGE 6, I-03)
                self::assertLine($out, 'WARN', 'web access', 'plugins/galbridge/composer.json: HTTP 404 - inconclusive');
                self::assertStringNotContainsString('is not served', $out);
            }
            // a page that is not Roundcube's
            file_put_contents($root . '/other/index.html', '<html><body>It works!</body></html>');
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=http://127.0.0.1:' . $port . '/other/']);
            self::assertLine($out, 'WARN', 'web access', '/other/ does not answer like the Roundcube webmail (HTTP 200)');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        // nobody answers: a WARN, never a false "not served"
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=http://127.0.0.1:1/']);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'web access', 'plugins/galbridge/composer.json: no answer (');
        self::assertLine($out, 'WARN', 'web access', 'http://127.0.0.1:1/ does not answer like the Roundcube webmail (no answer: ');
        self::assertStringNotContainsString('[ OK ] web access', $out);
        // (the reason without PHP's "fopen(<url>): Failed to open stream:" prefix)
        self::assertStringNotContainsString('Failed to open stream', $out);
    }

    /**
     * 1.0.3 (real iRedMail installation): iRedMail serves the webmail with a self-signed certificate
     * until a trusted one replaces it. diag --url said "no answer (... operation failed)" for every
     * request; it now names the certificate as the reason, says once what to do, and --cafile=
     * verifies the server against the given certificate - verification itself stays on.
     */
    public function testDiagUrlNamesAnUntrustedCertificateAndVerifiesAgainstACafile(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('needs the openssl extension');
        }
        $dsn = $this->anyServerDsn();
        $dir = $this->base() . '/tls';
        mkdir($dir, 0o700);
        // like iRedMail's certificate: self-signed, issued for the host name (CN, no subjectAltName)
        [$cert, $key] = self::selfSigned($dir, 'server', 'localhost');
        [$other] = self::selfSigned($dir, 'other', 'localhost');
        [$server, $port] = self::tlsServer($dir, $cert, $key);
        try {
            $url = 'https://localhost:' . $port . '/';

            // PHP's default CAs do not know the certificate
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=' . $url]);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'WARN', 'web access', $url . ' does not answer like the Roundcube webmail (no answer: TLS: the certificate of the web server was not verified (certificate verify failed))');
            self::assertLine($out, 'WARN', 'web access', 'plugins/galbridge/composer.json: no answer (TLS: the certificate of the web server was not verified');
            self::assertLine($out, 'WARN', 'web access', 'TLS certificate not trusted by this PHP: for a self-signed certificate - iRedMail installs one, /etc/ssl/certs/iRedMail.crt');
            self::assertSame(1, substr_count($out, 'TLS certificate not trusted'), 'the remedy is said once');
            self::assertStringNotContainsString('[ OK ] web access', $out);
            self::assertStringNotContainsString('operation failed', $out);

            // --cafile: the same server, verified against its certificate
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=' . $url, '--cafile=' . $cert]);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'OK', 'web access', $url . ' is the webmail');
            self::assertLine($out, 'INFO', 'web access', 'the certificate of the web server was verified against --cafile=' . $cert);
            self::assertLine($out, 'OK', 'web access', 'static.php/plugins/galbridge/lib/autoload.php is not served (HTTP 404)');
            self::assertSame(5, substr_count($out, '[ OK ] web access'), 'the webmail and four paths that are not served');
            self::assertStringNotContainsString('TLS certificate not', $out);

            // a certificate that does not sign the server's: still not verified
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=' . $url, '--cafile=' . $other]);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'WARN', 'web access', 'TLS certificate not verified against --cafile=' . $other . ': the web server presents a certificate that the file does not hold or sign');
            self::assertStringNotContainsString('[ OK ] web access', $out);

            // the right certificate, but the address does not use its host name
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], [], ['--url=https://127.0.0.1:' . $port . '/', '--cafile=' . $cert]);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'WARN', 'web access', 'no answer (TLS: the certificate of the web server was not verified (Peer certificate CN=');
            self::assertStringContainsString('did not match expected CN=', $out);
            self::assertStringNotContainsString('[ OK ] web access', $out);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        // usage errors: nothing is checked with half of the request
        file_put_contents($dir . '/key-and-cert.pem', (string) file_get_contents($key) . (string) file_get_contents($cert));
        file_put_contents($dir . '/not-a-certificate.txt', 'hello');
        $config = $this->writeConfig(['galbridge_db_dsn' => $dsn]);
        foreach ([
            [['--cafile=' . $cert], '--cafile needs --url=https://...'],
            [['--url=http://mail.example.com/mail/', '--cafile=' . $cert], '--cafile applies to an https:// address only'],
            [['--url=https://mail.example.com/mail/', '--cafile=tls/server.crt'], '--cafile must be an absolute path'],
            [['--url=https://mail.example.com/mail/', '--cafile=' . $dir . '/missing.crt'], '--cafile: no readable file ' . $dir . '/missing.crt'],
            [['--url=https://mail.example.com/mail/', '--cafile=' . $dir . '/key-and-cert.pem'], 'holds a private key - give the certificate only'],
            [['--url=https://mail.example.com/mail/', '--cafile=' . $dir . '/not-a-certificate.txt'], 'holds no PEM certificate'],
            [['--url=https://mail.example.com/mail/', '--cafile'], '--cafile needs a value: --cafile=...'],
        ] as [$args, $problem]) {
            [$code, $out, $err] = $this->runDiag($config, $args);
            self::assertSame(1, $code, $out . $err);
            self::assertStringContainsString("Usage: galbridge.sh diag [--user=EMAIL] [--url=URL] [--cafile=FILE] [--strict]\n", $err);
            self::assertStringContainsString($problem, $err);
            self::assertStringNotContainsString('== Versions', $out, 'nothing is checked with half of the request');
        }
    }

    /**
     * A self-signed certificate for $cn without subjectAltName - the form of iRedMail's
     * /etc/ssl/certs/iRedMail.crt - and its key, as PEM files below $dir.
     *
     * @return array{0: string, 1: string} certificate file, key file
     */
    private static function selfSigned(string $dir, string $name, string $cn): array
    {
        $config = ['digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $key = openssl_pkey_new($config);
        self::assertNotFalse($key, 'openssl_pkey_new');
        $csr = openssl_csr_new(['commonName' => $cn, 'organizationName' => 'GAL Bridge test'], $key, $config);
        self::assertNotFalse($csr, 'openssl_csr_new');
        $x509 = openssl_csr_sign($csr, null, $key, 2, $config, random_int(1, PHP_INT_MAX));
        self::assertNotFalse($x509, 'openssl_csr_sign');
        self::assertTrue(openssl_x509_export_to_file($x509, $dir . '/' . $name . '.crt'));
        self::assertTrue(openssl_pkey_export_to_file($key, $dir . '/' . $name . '.key'));
        return [$dir . '/' . $name . '.crt', $dir . '/' . $name . '.key'];
    }

    /**
     * A local HTTPS server (PHP's built-in web server has no TLS): "/" answers like the Roundcube
     * webmail, everything else 404. A client that refuses the certificate ends its handshake; the
     * server goes on with the next connection.
     *
     * @return array{0: resource, 1: int} the process and its port
     */
    private static function tlsServer(string $dir, string $cert, string $key): array
    {
        $script = $dir . '/tls-server.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $port, $cert, $key] = $argv;
            $ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
            $server = @stream_socket_server('tls://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
            if ($server === false) {
                exit(1);
            }
            while (true) {
                $c = @stream_socket_accept($server, 30);
                if ($c === false) {
                    continue;
                }
                $request = (string) fgets($c);
                while (($line = fgets($c)) !== false && trim($line) !== '') {
                }
                $path = explode(' ', $request)[1] ?? '/';
                [$status, $body] = $path === '/'
                    ? ['200 OK', '<html><head><script>var rcmail = new rcube_webmail();</script></head><body></body></html>']
                    : ['404 Not Found', 'not found'];
                fwrite($c, "HTTP/1.1 {$status}\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                fclose($c);
            }
            PHP);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 45000);
            $proc = proc_open([PHP_BINARY, $script, (string) $port, $cert, $key], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (is_resource($socket)) {
                    fclose($socket);
                    return [$proc, $port];
                }
                usleep(100000);
                if (!(proc_get_status($proc)['running'] ?? false)) {
                    break;
                }
            }
            proc_terminate($proc);
            proc_close($proc);
        }
        self::markTestSkipped('cannot start a local TLS server');
    }

    /**
     * What lies in the plugin directory: this checkout is a git clone (tests/, CI and tooling files)
     * and diag says that such a tree has no place on a mail server.
     */
    public function testDiagReportsAGitCloneAndForeignFiles(): void
    {
        $dsn = $this->anyServerDsn();
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'plugin directory', 'a git clone (tests');
        self::assertStringContainsString('tests/ holds scripts that reset databases and Dovecot', $out);
        if (self::isRoot()) {
            // second pass (F-27): as root the rule of --allow-root applies - and is reported
            self::assertLine($out, 'OK', 'plugin code', 'owned by root and writable by nobody else');
        } else {
            self::assertMatchesRegularExpression('/\[( OK |WARN)\] plugin code /', $out);
        }
    }

    /**
     * An installation as the release archive gives it, in a directory that is NOT called
     * "galbridge" (the target of a symlink, e.g. plugins/galbridge -> /opt/galbridge-1.0.1; __DIR__
     * resolves it): diag finds only the files of a release there, names what was left behind, and
     * asks the web server for the path Roundcube serves the plugin under - plugins/galbridge/ - not
     * for the name of the directory on disk, which would answer 404 and report "not served" for
     * files that are served.
     */
    public function testDiagJudgesAReleaseDirectoryUnderAnotherName(): void
    {
        $dsn = $this->anyServerDsn();
        $config = $this->writeConfig(['galbridge_db_dsn' => $dsn]);
        $copy = $this->releaseCopy('galbridge-1.0.1');

        [$code, $out, $err] = $this->runDiag($config, [], $copy);
        self::assertSame(0, $code, $out . $err);
        self::assertNoPhpErrors($err);
        self::assertLine($out, 'OK', 'plugin directory', 'only the files of a release');
        self::assertStringNotContainsString('a git clone', $out);
        self::assertStringNotContainsString('files that are not part of the plugin', $out);

        $root = $this->base() . '/htdocs-release';
        mkdir($root . '/plugins/galbridge', 0o700, true);
        file_put_contents($root . '/plugins/galbridge/composer.json', '{}');
        [$server, $port] = self::webServer($root);
        try {
            [$code, $out] = $this->runDiag($config, ['--url=http://127.0.0.1:' . $port . '/'], $copy);
            self::assertSame(2, $code, $out);
            self::assertLine($out, 'FAIL', 'web access', 'plugins/galbridge/composer.json is SERVED (HTTP 200)');
            // (this test server is no webmail, so a 404 is inconclusive - STAGE 6, I-03)
            self::assertLine($out, 'WARN', 'web access', 'static.php/plugins/galbridge/lib/autoload.php: HTTP 404 - inconclusive');
            self::assertStringNotContainsString('galbridge-1.0.1', $out, 'the web path is the name of the plugin, not of the directory');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        // what an administrator left behind is named (names only), and a clone is told apart
        file_put_contents($copy . '/grants.sql', '-- test');
        file_put_contents($copy . '/config.inc.php.bak', '<?php');
        [$code, $out] = $this->runDiag($config, [], $copy);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'plugin directory', 'files that are not part of the plugin: config.inc.php.bak, grants.sql - keep nothing else here (README 2.6)');
        self::assertStringNotContainsString('only the files of a release', $out);
        self::assertStringNotContainsString('a git clone', $out);
    }

    /**
     * The lists that describe a release agree: what git exports (.gitattributes) is what the test
     * above installs - and diag accepts exactly that in a plugin directory. A new top-level file
     * needs a decision in all three places.
     */
    public function testTheReleaseEntriesAreWhatGitExports(): void
    {
        $dir = self::pluginDir();
        if (!is_dir($dir . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        [$code, $out] = self::exec(['git', '-C', $dir, 'ls-files', '-z']);
        if ($code !== 0 || $out === '') {
            self::markTestSkipped('git cannot read this checkout');
        }
        $top = [];
        foreach (explode("\0", rtrim($out, "\0")) as $file) {
            $top[explode('/', $file, 2)[0]] = true;
        }
        $exported = [];
        foreach (array_keys($top) as $entry) {
            [$code, $attr] = self::exec(['git', '-C', $dir, 'check-attr', 'export-ignore', '--', (string) $entry]);
            self::assertSame(0, $code, $attr);
            if (!str_ends_with(trim($attr), ': export-ignore: set')) {
                $exported[] = (string) $entry;
            }
        }
        sort($exported, SORT_STRING);
        self::assertSame(self::RELEASE_ENTRIES, $exported, 'tracked top-level entries without export-ignore (.gitattributes) and CliToolTest::RELEASE_ENTRIES differ');
    }

    /**
     * A consequence of the "(domain)" marker standing first: Roundcube sorts its suggestions by name
     * and pre-selects the first one. diag says so where it applies, and names the switch.
     */
    public function testDiagTellsThatMarkedEntriesAreSuggestedFirst(): void
    {
        $dsn = $this->anyServerDsn();
        $global = ['galbridge_db_dsn' => $dsn, 'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        [$code, $out] = $this->diag($global);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'INFO', 'autocomplete order', 'entries of other domains start with their "(domain)" marker and Roundcube sorts its suggestions by name');
        self::assertLine($out, 'INFO', 'autocomplete order', 'galbridge_global_autocomplete = false keeps the global book out of autocomplete');
        foreach (['galbridge_global_autocomplete', 'galbridge_mark_foreign_domains', 'galbridge_global_book'] as $switch) {
            [$code, $out] = $this->diag([$switch => false] + $global);
            self::assertSame(0, $code, $out);
            self::assertStringNotContainsString('autocomplete order', $out, $switch . ' = false');
        }
    }

    /**
     * The "(domain)" marker is part of the name of a foreign entry. A contactlist_name_template that
     * shows other text of the entry before {name} - or has no {name} - makes the plugin withhold
     * those fields from foreign entries; diag says so (the administrator would otherwise wonder
     * where the department went).
     */
    public function testDiagTellsWhichFieldsTheNameTemplateTakesFromForeignEntries(): void
    {
        $dsn = $this->anyServerDsn();
        $global = ['galbridge_db_dsn' => $dsn, 'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        [$code, $out] = $this->diag($global, ['contactlist_name_template' => '[{department}] {name}']);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'contactlist_name_template', 'shows department before {name} (or has no {name}): entries of other domains are listed WITHOUT these fields');
        [$code, $out] = $this->diag($global, ['contactlist_name_template' => '{firstname} {surname} {phone}']);
        self::assertLine($out, 'WARN', 'contactlist_name_template', 'shows phone:work, phone:mobile before {name}');

        foreach ([[$global, '{name} ({department})'], [$global, '{name}'], [['galbridge_global_book' => false] + $global, '[{department}] {name}'], [['galbridge_mark_foreign_domains' => false] + $global, '{department}']] as [$plugin, $template]) {
            [$code, $out] = $this->diag($plugin, ['contactlist_name_template' => $template]);
            self::assertSame(0, $code, $out);
            self::assertStringNotContainsString('contactlist_name_template', $out, $template);
        }
    }

    /**
     * An IMAP server on a local socket (imap_host = unix:///path, which Roundcube opens with port
     * -1) is a directory host like any other: derived from imap_host, or listed.
     */
    public function testDiagAcceptsAnImapServerOnALocalSocket(): void
    {
        $dsn = $this->anyServerDsn();
        $socket = 'unix:///run/dovecot/imap.sock';
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], ['imap_host' => $socket]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'directory hosts', $socket . ' (derived from imap_host)');
        self::assertLine($out, 'OK', 'imap_host ' . $socket, 'covered');

        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => [$socket, '127.0.0.1:143']], ['imap_host' => $socket]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'directory hosts', $socket . ', 127.0.0.1:143 (galbridge_imap_hosts)');
        self::assertLine($out, 'OK', 'imap_host ' . $socket, 'covered');

        // another socket is another server
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['unix:///run/dovecot/other.sock']], ['imap_host' => $socket]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'imap_host ' . $socket, 'not in galbridge_imap_hosts');

        // not a socket Roundcube could open: refused, with the accepted forms
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['unix://run/dovecot/imap.sock']]);
        self::assertSame(2, $code, $out);
        self::assertLine($out, 'FAIL', 'galbridge_imap_hosts', 'or unix:///path for a local socket');
        self::assertLine($out, 'FAIL', 'directory hosts', 'plugin disabled: galbridge_imap_hosts is malformed - set galbridge_imap_hosts to the IMAP servers of this vmail database (host, host:port or unix:///path)');
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

        // 1.0.4: every stored host covered - the check that ran says so instead of printing nothing
        $pdo = new \PDO('sqlite:' . $this->roundcubeDb());
        $pdo->exec("INSERT INTO users (username, mail_host, created) VALUES ('k@example.com', '127.0.0.1', '2024-01-01 00:00:00'), ('l@example.com', '', '2024-01-01 00:00:00')");
        $pdo = null;
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'OK', 'stored IMAP hosts', ' 1 account(s) with a stored IMAP host, all covered by the directory hosts');
        self::assertStringNotContainsString('k@example.com', $out, 'counts only, never user names');

        // Roundcube users stored with another IMAP host
        $pdo = new \PDO('sqlite:' . $this->roundcubeDb());
        $pdo->exec("INSERT INTO users (username, mail_host, created) VALUES ('a@example.com', 'imap.old.example', '2024-01-01 00:00:00'),"
            . " ('b@example.com', 'imap.old.example', '2024-01-01 00:00:00'), ('jan.kowalski@example.com', '127.0.0.1', '2024-01-01 00:00:00')");
        $pdo = null;
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'users on imap.old.example', '2 Roundcube users on this IMAP host get no directory');
        self::assertStringNotContainsString('users on 127.0.0.1', $out);
        self::assertStringNotContainsString('stored IMAP hosts', $out, 'no OK line next to a WARN of the same check');
        self::assertStringNotContainsString('a@example.com', $out, 'counts only, never user names');

        // a stored host that is not in the form of a regular login is counted, never printed - it may
        // hold control characters (STAGE 6: judged like the runtime binding, the raw value)
        $pdo = new \PDO('sqlite:' . $this->roundcubeDb());
        $pdo->exec("INSERT INTO users (username, mail_host, created) VALUES ('c@example.com', 'imap.evil.example:143/x', '2024-01-01 00:00:00'),"
            . " ('d@example.com', ' 127.0.0.1', '2024-01-01 00:00:00'), ('e@example.com', 'tls://127.0.0.1:143', '2024-01-01 00:00:00')");
        $pdo->exec('INSERT INTO users (username, mail_host, created) VALUES (\'f@example.com\', \'127.0.0.1' . "\x1b[2J" . '\', \'2024-01-01 00:00:00\')');
        $pdo = null;
        [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        self::assertSame(0, $code, $out);
        self::assertLine($out, 'WARN', 'users on other hosts', '3 Roundcube users have a mail_host that is not a plain host name');
        self::assertStringNotContainsString('evil', $out);
        self::assertStringNotContainsString("\x1b", $out, 'no control character of the database reaches the terminal');
        self::assertStringNotContainsString('users on 127.0.0.1', $out, 'the configured URI of an older Roundcube is the same host');
    }

    /**
     * STAGE 6 (I-03, closing): an unexpected error outside the guarded diag sections ends with exit
     * code 2 and a sanitised message - never with the exit code of "OK".
     */
    public function testAnUnexpectedErrorIsNeverReportedAsOk(): void
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $failing = new class () extends \rcube_config {
            public function __construct()
            {
                // nothing loaded here: the error has to happen inside the tool
            }

            public function load_from_file($file)
            {
                throw new \RuntimeException('broken with mysql://role:' . 'secret-value@db/vmail');
            }

            // the tool resolves the plugin's files before it loads one (Config::pluginFiles())
            public function resolve_paths($file, $use_env = true)
            {
                throw new \RuntimeException('broken with mysql://role:' . 'secret-value@db/vmail');
            }
        };
        $rc = \rcube::get_instance();
        $saved = $rc->config;
        $rc->config = $failing;
        try {
            $code = (new Tool($rc, self::pluginDir(), $out))->run(['diag']);
        } finally {
            $rc->config = $saved;
        }
        rewind($out);
        $text = (string) stream_get_contents($out);
        self::assertSame(2, $code, $text);
        self::assertStringContainsString('Result: aborted', $text);
        self::assertStringContainsString('RuntimeException', $text);
        self::assertStringNotContainsString('secret-value', $text, 'the message is sanitised (DSN password)');
    }

    /**
     * STAGE 6 (I-02, closing): the fingerprint in sessions is an HMAC keyed with des_key - worth
     * nothing with Roundcube's published default or without a key, so diag says so.
     */
    public function testDiagWarnsAboutAPublicOrMissingDesKey(): void
    {
        $dsn = $this->anyServerDsn();
        foreach (['rcmail-!24ByteDESkey*Str' => "is Roundcube's published default", '' => 'is not set'] as $key => $text) {
            [$code, $out] = $this->diag(['galbridge_db_dsn' => $dsn], ['des_key' => $key]);
            self::assertSame(0, $code, $out);
            self::assertLine($out, 'WARN', 'des_key', $text);
        }
        [, $out] = $this->diag(['galbridge_db_dsn' => $dsn]);
        // (second pass, F-27: a core configuration file of the PHP user is a WARN that names des_key)
        self::assertDoesNotMatchRegularExpression('/^  \[(WARN|FAIL)\] des_key /m', $out, 'a key of its own: no line');
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
     * The tool with standard output and standard error apart.
     *
     * @param array<int|string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function toolStreams(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Tool(null, self::pluginDir(), $out, $err))->run($args);
        rewind($out);
        rewind($err);
        $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);
        return $result;
    }

    /**
     * php bin/galbridge.sh <args> as a child process, from the Roundcube directory.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     * @param null|string           $pluginDir another plugin directory than this checkout (releaseCopy())
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function cli(array $args, array $env = [], ?string $pluginDir = null): array
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            $vars[$k] = $v;
        }
        $cwd = defined('RCUBE_INSTALL_PATH') ? rtrim((string) constant('RCUBE_INSTALL_PATH'), '/') : self::pluginDir();
        if (self::isRoot() && !in_array('--no-allow-root', $args, true)) {
            // the script refuses root unless told otherwise (and then checks who owns the code);
            // a test machine or container runs the suite as root
            $args[] = '--allow-root';
        }
        $args = array_values(array_diff($args, ['--no-allow-root']));
        return self::exec([PHP_BINARY, '-d', 'display_errors=stderr', ($pluginDir ?? self::pluginDir()) . '/bin/galbridge.sh', ...$args], null, $vars, $cwd);
    }

    private static function isRoot(): bool
    {
        // as the script decides it: also without the posix extension
        return Tool::euid() === 0;
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
    private function runDiag(string $configDir, array $args = [], ?string $pluginDir = null): array
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        return self::cli(['diag', ...$args], [
            'ROUNDCUBE_INSTALL_PATH' => rtrim((string) constant('RCUBE_INSTALL_PATH'), '/'),
            'ROUNDCUBE_CONFIG_DIR' => $configDir,
        ], $pluginDir);
    }

    /**
     * A copy of this plugin as the release archive holds it, in a directory of the given name below
     * the directory of this test (owner: the user of the test run, nobody else may write).
     */
    private function releaseCopy(string $name): string
    {
        $target = $this->base() . '/' . $name;
        mkdir($target, 0o700);
        foreach (self::RELEASE_ENTRIES as $entry) {
            self::copyTree(self::pluginDir() . '/' . $entry, $target . '/' . $entry);
        }
        return $target;
    }

    private static function copyTree(string $from, string $to): void
    {
        if (is_dir($from)) {
            mkdir($to, 0o700);
            foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
                self::copyTree($from . '/' . $entry, $to . '/' . $entry);
            }
            return;
        }
        self::assertTrue(copy($from, $to), $from);
        chmod($to, 0o600);
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
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
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

    /**
     * What the statements of an output carry instead of the placeholder of the template: a VERIFIER
     * of the password - the mysql_native_password hash (MySQL/MariaDB) or a SCRAM-SHA-256 verifier
     * (PostgreSQL). It is checked against the password of the printed DSN, so the DSN and the role
     * always belong together, and the password itself must be in the DSN line only.
     */
    private static function secretOf(string $out, string $driver): string
    {
        $password = self::passwordOf($out);
        $withoutDsn = (string) preg_replace('/^-- \\$config.*$/m', '', $out);
        self::assertStringNotContainsString($password, $withoutDsn, 'the clear text is in the DSN line only');
        if ($driver === 'mysql') {
            self::assertGreaterThanOrEqual(1, preg_match_all("/IDENTIFIED WITH mysql_native_password AS '(\\*[0-9A-F]{40})'/", $out, $m), 'a mysql_native_password verifier: ' . $out);
            self::assertSame([Tool::mysqlVerifier($password)], array_values(array_unique($m[1])), 'the verifier belongs to the password of the DSN line');
            // the documented way to compute it by hand (SQL/grants.mysql.sql) gives the same value
            self::assertSame('*' . strtoupper(sha1(sha1($password, true))), $m[1][0]);
            return $m[1][0];
        }
        self::assertSame(1, preg_match_all("/PASSWORD '(SCRAM-SHA-256\\$4096:([A-Za-z0-9+\\/=]+)\\$[A-Za-z0-9+\\/=]+:[A-Za-z0-9+\\/=]+)'/", $out, $m), 'exactly one SCRAM verifier: ' . $out);
        $verifier = $m[1][0];
        $salt = (string) base64_decode($m[2][0], true);
        self::assertSame(16, strlen($salt), 'a 128-bit random salt');
        self::assertSame($verifier, Tool::scramVerifier($password, $salt), 'the verifier belongs to the password of the DSN line');
        return $verifier;
    }

    /**
     * Non-comment lines of an output that has no DSN trailer (--grants-only).
     *
     * @return list<string>
     */
    private static function statementsOfAnyOutput(string $out): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '--')));
    }

    /**
     * PHP's built-in web server on a free loopback port, serving $root.
     *
     * @return array{0: resource, 1: int}
     */
    private static function webServer(string $root): array
    {
        // A router: PHP's built-in server answers a path that does not exist with the index page of
        // the nearest directory above it (HTTP 200) - no web server in front of Roundcube does that.
        // Files are served, a directory answers with its own index.html, everything else is 404.
        $router = dirname($root) . '/router-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($router, <<<'PHP'
            <?php
            $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $file = $_SERVER['DOCUMENT_ROOT'] . $path;
            if (str_contains($path, '..')) {
                http_response_code(400);
                return true;
            }
            if (is_file($file)) {
                return false;
            }
            if (is_dir($file) && is_file(rtrim($file, '/') . '/index.html')) {
                header('Content-Type: text/html');
                readfile(rtrim($file, '/') . '/index.html');
                return true;
            }
            http_response_code(404);
            echo 'not found';
            return true;
            PHP);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 45000);
            $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (is_resource($socket)) {
                    fclose($socket);
                    return [$proc, $port];
                }
                usleep(100000);
                if (!(proc_get_status($proc)['running'] ?? false)) {
                    break;
                }
            }
            proc_terminate($proc);
            proc_close($proc);
        }
        self::markTestSkipped('cannot start a local web server');
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

    /**
     * The fake core of testAllowRootChecksEverythingThatIsLoadedAsRoot was loaded - the checks of
     * --allow-root passed - and ended the program, which the script reports as aborted.
     *
     * @param array{0: int, 1: string, 2: string} $run
     */
    private static function assertCoreLoaded(array $run, string $message = ''): void
    {
        self::assertSame(2, $run[0], $message . ': ' . $run[2]);
        self::assertStringStartsWith(self::FAKE_CORE . "\n", $run[1], $message);
        self::assertStringEndsWith("\nResult: aborted\n", $run[1], $message);
    }

    private static function assertNoPhpErrors(string $stderr): void
    {
        self::assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error|Uncaught)/i', $stderr);
    }

    // ------------------------------------------------------------------ helpers: databases

    /**
     * Engine, database, host and port ('' for the driver's default) of a fixture DSN; refuses
     * anything but a local *test* database.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function target(string $dsn): array
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        $engine = (string) $parsed['phptype'] === 'pgsql' ? 'pgsql' : 'mysql';
        $db = (string) ($parsed['database'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*test[a-z0-9_]*$/', $db) || !Dsn::isLoopback($parsed)) {
            self::markTestSkipped('refusing to create roles outside a local *test* database: ' . $db);
        }
        $port = (string) ($parsed['port'] ?? '');
        return [$engine, $db, (string) ($parsed['hostspec'] ?? 'localhost'), $port === '0' ? '' : $port];
    }

    /**
     * The grants options that make the printed DSN name the test server: --host and, when the test
     * DSN names one, --port (a test server on another port than the driver's default - CI service
     * containers, a second local server - is reached only through it).
     *
     * @return array<string, string>
     */
    private function server(string $dsn): array
    {
        [, , $host, $port] = $this->target($dsn);
        return ['host' => $host] + ($port !== '' ? ['port' => $port] : []);
    }

    /**
     * host[:port] of the test server as grants prints it into the DSN.
     */
    private function serverTarget(string $dsn): string
    {
        [, , $host, $port] = $this->target($dsn);
        return $host . ($port !== '' ? ':' . $port : '');
    }

    /**
     * Whether a MariaDB/MySQL grants output in the verifier form can be applied to the test server:
     * MariaDB, and MySQL with an ACTIVE mysql_native_password plugin (MySQL 8.4 and later do not
     * load it by default, 9.x does not ship it). Elsewhere the tests apply the
     * --clear-text-password form, as README 2.2 tells the administrator. PostgreSQL: always.
     */
    private static function verifierApplies(string $engine, string $db): bool
    {
        if ($engine !== 'mysql') {
            return true;
        }
        if (self::adminCommand($engine) === null) {
            self::markTestSkipped('no administrator access to the mysql test server (MYSQL_ADMIN as for load.sh)');
        }
        return self::adminQuery($engine, $db, "SELECT COUNT(*) FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'mysql_native_password' AND PLUGIN_STATUS = 'ACTIVE'") === '1';
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
            if ($engine !== 'pgsql') {
                // UTF-8 statements stay UTF-8 whatever the locale of the client (load.sh, DbAdmin)
                $cmd[] = '--default-character-set=utf8mb4';
            }
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
        if ($engine === 'pgsql' && str_contains($sql, 'CREATE VIEW galbridge_forwardings')) {
            self::$viewRecreated = true;
        }
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
