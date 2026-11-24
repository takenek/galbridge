<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * DSN policy (docs/ARCHITECTURE.md 12) and least-privilege enforcement against real database roles:
 * driver allowlist, refused roles, the 'service' parameter, verified TLS for remote hosts, connect
 * timeout injection, loopback detection, and the schema/privilege probe (a role that can read
 * mailbox.password disables every book; a narrower role keeps working with fewer fields).
 *
 * The role tests create and drop extra roles ONLY on the throw-away galbridge_vmail_test databases,
 * through the administrator access tests/fixtures/vmail/load.sh uses (MYSQL_ADMIN / PSQL_ADMIN).
 *
 * @license GPL-3.0-or-later
 */
final class DsnPolicyTest extends TestCase
{
    private const PASSWORD = 'Sup3r-Secret-DSN-pw';

    /** @var array<string, null|list<string>> engine => working admin command (null = unavailable) */
    private static array $adminCommand = [];

    /** @var list<array{string, string, string}> roles to drop: [engine, db, role] */
    private array $roles = [];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
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
        $_SESSION = [];
    }

    // ------------------------------------------------------------------ drivers

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedDrivers(): array
    {
        return [
            'mysql' => ['mysql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', 'mysql'],
            'mysqli is mysql' => ['mysqli://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', 'mysql'],
            'pgsql' => ['pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', 'pgsql'],
            'upper-case scheme' => ['PGSQL://galbridge:' . self::PASSWORD . '@localhost/vmail', 'pgsql'],
        ];
    }

    #[DataProvider('acceptedDrivers')]
    public function testSupportedDriversAreAccepted(string $dsn, string $driver): void
    {
        self::assertSame($driver, (new Dsn($dsn, self::config()))->driver());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedDrivers(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'oracle' => ['oracle://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail'],
            'mssql' => ['mssql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail'],
            'sqlsrv' => ['sqlsrv://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail'],
            'pgsql look-alike' => ['pgsql2://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail'],
            'no scheme' => ['galbridge:' . self::PASSWORD . '@127.0.0.1/vmail'],
        ];
    }

    #[DataProvider('refusedDrivers')]
    public function testUnsupportedDriversAreRefused(string $dsn): void
    {
        self::assertRefused($dsn, self::config());
    }

    /**
     * SQLite is accepted only under the test bootstrap (GALBRIDGE_ALLOW_SQLITE === true). Checked in a
     * separate PHP process, because this process defines the constant.
     */
    public function testSqliteIsRefusedOutsideTheTestBootstrap(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'galbridge-dsn-');
        self::assertIsString($script);
        file_put_contents($script, <<<'PHP'
            <?php
            declare(strict_types=1);
            [$rc, $lib, $mode] = [$argv[1], $argv[2], $argv[3]];
            define('RCUBE_INSTALL_PATH', $rc);
            define('RCUBE_CONFIG_DIR', $rc . 'config/');
            define('RCUBE_PLUGINS_DIR', $rc . 'plugins/');
            define('RCUBE_LOCALIZATION_DIR', $rc . 'program/localization/');
            require_once $rc . 'vendor/autoload.php';
            require_once $rc . 'program/lib/Roundcube/bootstrap.php';
            require_once $lib;
            if ($mode === 'truthy') {
                define('GALBRIDGE_ALLOW_SQLITE', 'yes'); // only the boolean true counts
            }
            try {
                new GalBridge\Db\Dsn('sqlite:////tmp/galbridge-never-created.db', new GalBridge\Config(new rcube_config(), [], []));
                echo 'ACCEPTED';
            } catch (GalBridge\Exception\GalBridgeException $e) {
                echo 'REFUSED:' . $e->getReason();
            }
            PHP);
        try {
            foreach (['undefined', 'truthy'] as $mode) {
                $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', $script, RCUBE_INSTALL_PATH, dirname(__DIR__, 2) . '/lib/autoload.php', $mode];
                [$code, $out, $err] = self::exec($cmd);
                self::assertSame(0, $code, $err);
                self::assertSame('REFUSED:config_invalid', trim($out), "mode {$mode}: " . $err);
            }
        } finally {
            @unlink($script);
        }
    }

    public function testPostgresServiceParameterIsRefused(): void
    {
        // a pg_service.conf entry would move host, user and TLS settings out of sight of every check
        self::assertRefused('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?service=vmail', self::config());
        self::assertRefused('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?sslmode=verify-full&service=x', self::config());
    }

    // ------------------------------------------------------------------ refused roles

    /**
     * @return array<string, array{string}>
     */
    public static function refusedRoles(): array
    {
        $out = [];
        foreach (Config::DEFAULTS['galbridge_db_refused_users'] as $user) {
            $out[$user] = [$user];
        }
        $out['upper-case VMAIL'] = ['VMAIL'];
        $out['mixed-case Roundcube'] = ['RoundCube'];
        return $out;
    }

    #[DataProvider('refusedRoles')]
    public function testIredmailAndSuperuserRolesAreRefused(string $user): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            self::assertRefused($driver . '://' . $user . ':' . self::PASSWORD . '@127.0.0.1/vmail', self::config());
        }
    }

    public function testRoundcubeAndPasswordPluginUsersAreRefused(): void
    {
        $config = self::config();
        $rcDsn = 'mysql://rcmail_rw:rc-pw@localhost/roundcubemail';
        $pwDsn = 'pgsql://pw_changer:pw-pw@localhost/vmail';
        self::assertRefused('mysql://rcmail_rw:' . self::PASSWORD . '@127.0.0.1/vmail', $config, $rcDsn, $pwDsn);
        self::assertRefused('pgsql://RCMAIL_RW:' . self::PASSWORD . '@127.0.0.1/vmail', $config, $rcDsn, $pwDsn);
        self::assertRefused('pgsql://pw_changer:' . self::PASSWORD . '@127.0.0.1/vmail', $config, $rcDsn, $pwDsn);
        // the dedicated role is fine next to them
        self::assertSame('galbridge', (new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', $config, $rcDsn, $pwDsn))->user());
    }

    public function testRefusedUserListIsConfigurableAndStillRefusesTheRoundcubeUser(): void
    {
        $config = self::config(['galbridge_db_refused_users' => ['Legacy_GAL', '', 42]]);
        self::assertRefused('mysql://legacy_gal:' . self::PASSWORD . '@127.0.0.1/vmail', $config);
        self::assertRefused('mysql://rcmail_rw:' . self::PASSWORD . '@127.0.0.1/vmail', $config, 'mysql://rcmail_rw:x@localhost/rc');
    }

    public function testDsnWithoutUserIsRefused(): void
    {
        self::assertRefused('mysql://127.0.0.1/vmail', self::config());
        self::assertRefused('pgsql://:' . self::PASSWORD . '@127.0.0.1/vmail', self::config());
        // a user given as a DSN parameter is the user rcube_db connects with: still policy-checked
        self::assertRefused('mysql://127.0.0.1/vmail?username=vmail', self::config());
    }

    // ------------------------------------------------------------------ TLS for remote hosts

    /**
     * @return array<string, array{string}>
     */
    public static function remoteWithoutVerifiedTls(): array
    {
        $u = 'galbridge:' . self::PASSWORD;
        return [
            'pgsql plain' => ["pgsql://{$u}@db.example.net/vmail"],
            'pgsql sslmode=require (no verification)' => ["pgsql://{$u}@db.example.net/vmail?sslmode=require"],
            'pgsql sslmode=prefer' => ["pgsql://{$u}@db.example.net/vmail?sslmode=prefer"],
            'pgsql sslmode=allow' => ["pgsql://{$u}@db.example.net/vmail?sslmode=allow"],
            'pgsql sslmode=disable' => ["pgsql://{$u}@db.example.net/vmail?sslmode=disable"],
            'pgsql remote IPv4' => ["pgsql://{$u}@198.51.100.7:5432/vmail"],
            'pgsql remote IPv6' => ["pgsql://{$u}@[2001:db8::1]:5432/vmail"],
            'mysql plain' => ["mysql://{$u}@db.example.net/vmail"],
            'mysql private address' => ["mysql://{$u}@10.0.0.5/vmail"],
            'mysql ca without verification' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/certs/ca.pem"],
            'mysql verification without ca' => ["mysql://{$u}@db.example.net/vmail?verify_server_cert=1"],
            'mysql ca with verification off' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/certs/ca.pem&verify_server_cert=0"],
            'mysql ca with verification false' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/certs/ca.pem&verify_server_cert=false"],
            'host name that merely starts with localhost' => ["pgsql://{$u}@localhost.example.net/vmail"],
            'host name that merely starts with 127.0.0.1' => ["mysql://{$u}@127.0.0.1.nip.example/vmail"],
            'IPv4-mapped IPv6 loopback is not trusted' => ["pgsql://{$u}@[::ffff:7f00:1]/vmail"],
            'all-zero address' => ["mysql://{$u}@0.0.0.0/vmail"],
        ];
    }

    #[DataProvider('remoteWithoutVerifiedTls')]
    public function testRemoteHostWithoutVerifiedTlsIsRefused(string $dsn): void
    {
        self::assertRefused($dsn, self::config());
    }

    /**
     * rcube_db connects to 'hostspec' whenever it is set; a 'socket' (or a unix() protocol) next to
     * a remote host must not make the policy treat the connection as local.
     *
     * @return array<string, array{string}>
     */
    public static function remoteDisguisedAsLocal(): array
    {
        $u = 'galbridge:' . self::PASSWORD;
        return [
            'pgsql remote host with a socket parameter' => ["pgsql://{$u}@db.example.net/vmail?socket=/var/run/postgresql"],
            'mysql remote host with a socket parameter' => ["mysql://{$u}@db.example.net/vmail?socket=/run/mysqld/mysqld.sock"],
            'pgsql unix() protocol with a hostspec parameter' => ["pgsql://{$u}@unix(/var/run/postgresql)/vmail?hostspec=db.example.net"],
        ];
    }

    #[DataProvider('remoteDisguisedAsLocal')]
    public function testRemoteHostDisguisedAsLocalSocketIsRefused(string $dsn): void
    {
        $parsed = \rcube_db::parse_dsn($dsn);
        self::assertSame('db.example.net', $parsed['hostspec'] ?? null, 'precondition: rcube_db would connect to the remote host');
        self::assertRefused($dsn, self::config());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function remoteWithVerifiedTls(): array
    {
        $u = 'galbridge:' . self::PASSWORD;
        return [
            'pgsql verify-full' => ["pgsql://{$u}@db.example.net/vmail?sslmode=verify-full"],
            'pgsql verify-ca' => ["pgsql://{$u}@db.example.net/vmail?sslmode=verify-ca&sslrootcert=/etc/ssl/ca.pem"],
            'pgsql VERIFY-FULL' => ["pgsql://{$u}@db.example.net/vmail?sslmode=VERIFY-FULL"],
            'mysql ca + verify' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/certs/ca.pem&verify_server_cert=1"],
            'mysql capath + verify=true' => ["mysql://{$u}@db.example.net/vmail?capath=/etc/ssl/certs&verify_server_cert=true"],
        ];
    }

    #[DataProvider('remoteWithVerifiedTls')]
    public function testRemoteHostWithVerifiedTlsIsAccepted(string $dsn): void
    {
        $accepted = new Dsn($dsn, self::config());
        self::assertSame([], $accepted->warnings());
        self::assertStringContainsString('db.example.net', $accepted->describe());
    }

    public function testPlaintextRemoteNeedsExplicitOptInAndWarns(): void
    {
        $dsn = 'pgsql://galbridge:' . self::PASSWORD . '@db.example.net/vmail?sslmode=require';
        self::assertRefused($dsn, self::config(['galbridge_db_allow_plaintext' => false]));
        $accepted = new Dsn($dsn, self::config(['galbridge_db_allow_plaintext' => true]));
        self::assertNotSame([], $accepted->warnings());
        foreach ($accepted->warnings() as $w) {
            self::assertStringNotContainsString(self::PASSWORD, $w);
        }
        // the opt-in never relaxes the role checks
        self::assertRefused('pgsql://vmail:' . self::PASSWORD . '@db.example.net/vmail', self::config(['galbridge_db_allow_plaintext' => true]));
    }

    // ------------------------------------------------------------------ loopback

    /**
     * @return array<string, array{string, bool}>
     */
    public static function loopbackHosts(): array
    {
        return [
            'unix socket' => ['mysql://g:p@unix(/run/mysqld/mysqld.sock)/vmail', true],
            'pgsql socket directory' => ['pgsql://g:p@unix(/var/run/postgresql)/vmail', true],
            '127.0.0.1' => ['pgsql://g:p@127.0.0.1/vmail', true],
            '127.0.0.1 with port' => ['mysql://g:p@127.0.0.1:3307/vmail', true],
            '127.10.20.30' => ['pgsql://g:p@127.10.20.30/vmail', true],
            'localhost' => ['mysql://g:p@localhost/vmail', true],
            'LOCALHOST' => ['mysql://g:p@LOCALHOST/vmail', true],
            '[::1]' => ['pgsql://g:p@[::1]:5432/vmail', true],
            'no host (driver default: local)' => ['mysql://g:p@/vmail', true],
            '10.0.0.1' => ['pgsql://g:p@10.0.0.1/vmail', false],
            '128.0.0.1' => ['pgsql://g:p@128.0.0.1/vmail', false],
            '0.0.0.0' => ['mysql://g:p@0.0.0.0/vmail', false],
            'localhost.example.net' => ['mysql://g:p@localhost.example.net/vmail', false],
            '127.0.0.1.nip.example' => ['mysql://g:p@127.0.0.1.nip.example/vmail', false],
            '[2001:db8::1]' => ['pgsql://g:p@[2001:db8::1]/vmail', false],
            'db.example.net' => ['pgsql://g:p@db.example.net/vmail', false],
        ];
    }

    #[DataProvider('loopbackHosts')]
    public function testLoopbackDetection(string $dsn, bool $loopback): void
    {
        self::assertSame($loopback, Dsn::isLoopback(\rcube_db::parse_dsn($dsn)));
    }

    // ------------------------------------------------------------------ connect timeout

    public function testConnectTimeoutIsInjectedWhenMissing(): void
    {
        $d = new Dsn('mysql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', self::config());
        self::assertSame('2', (string) (\rcube_db::parse_dsn($d->effective())['timeout'] ?? ''));

        $d = new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?application_name=gal', self::config(['galbridge_db_connect_timeout' => 5]));
        $parsed = \rcube_db::parse_dsn($d->effective());
        self::assertSame('5', (string) ($parsed['timeout'] ?? ''));
        self::assertSame('gal', $parsed['application_name'] ?? null, 'existing parameters are kept');
        self::assertSame('vmail', $parsed['database'] ?? null);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function timeoutClamps(): array
    {
        return [
            'zero -> 1' => [0, '1'],
            'negative -> 1' => [-5, '1'],
            'huge -> 30' => [99999, '30'],
            'garbage -> default 2' => ['soon', '2'],
        ];
    }

    #[DataProvider('timeoutClamps')]
    public function testInjectedConnectTimeoutIsClamped(mixed $value, string $expected): void
    {
        $d = new Dsn('mysql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', self::config(['galbridge_db_connect_timeout' => $value]));
        self::assertSame($expected, (string) (\rcube_db::parse_dsn($d->effective())['timeout'] ?? ''));
    }

    public function testExplicitConnectTimeoutIsKept(): void
    {
        $d = new Dsn('mysql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?timeout=7', self::config());
        self::assertSame('7', (string) (\rcube_db::parse_dsn($d->effective())['timeout'] ?? ''));
    }

    /**
     * rcube_db keeps the FIRST occurrence of a DSN parameter, and treats timeout=0 as "no timeout":
     * an explicit zero must not survive (libpq waits forever, PDO MySQL up to default_socket_timeout).
     */
    /**
     * A timeout the administrator wrote into the DSN is validated, never overridden (rcube_db keeps
     * the first occurrence of a parameter): 0, negative, non-numeric or above 30 s is refused.
     */
    public function testZeroConnectTimeoutCannotDisableTheTimeout(): void
    {
        foreach (['0', '-1', 'abc', '31', ''] as $bad) {
            try {
                new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?timeout=' . $bad, self::config());
                self::fail('accepted timeout=' . $bad);
            } catch (GalBridgeException $e) {
                self::assertSame('config_invalid', $e->getReason());
                self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            }
        }
        $d = new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?timeout=7', self::config());
        self::assertSame('7', (string) (\rcube_db::parse_dsn($d->effective())['timeout'] ?? ''));
        self::assertSame(1, substr_count($d->effective(), 'timeout='), 'never a second timeout parameter');
    }

    public function testSqliteGetsNoTimeoutParameter(): void
    {
        $sqlite = Env::sqliteDsn();
        self::assertSame($sqlite, (new Dsn($sqlite, self::config()))->effective());
    }

    // ------------------------------------------------------------------ secrets never in messages

    public function testPasswordNeverAppearsInErrorsOrDescriptions(): void
    {
        $refused = [
            'pgsql://vmail:' . self::PASSWORD . '@127.0.0.1/vmail',
            'pgsql://galbridge:' . self::PASSWORD . '@db.example.net/vmail',
            'oracle://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail',
            'pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?service=x',
        ];
        foreach ($refused as $dsn) {
            try {
                new Dsn($dsn, self::config());
                self::fail('expected a refusal: ' . $dsn);
            } catch (GalBridgeException $e) {
                self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
                self::assertStringNotContainsString(self::PASSWORD, $e->getReason());
            }
        }
        $ok = new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1:5432/vmail', self::config());
        self::assertStringNotContainsString(self::PASSWORD, $ok->describe());
        self::assertStringContainsString('galbridge@127.0.0.1:5432/vmail', $ok->describe());
    }

    public function testRefusedDsnFailsClosedWithoutLoggingThePassword(): void
    {
        $dsn = 'pgsql://vmail:' . self::PASSWORD . '@127.0.0.1/vmail';
        $factory = Env::factory(Env::config($dsn), 'jan.kowalski@example.com');
        self::assertSame([], $factory->books());
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'a configuration error is not cached as "no entitlement"');
        self::assertStringNotContainsString(self::PASSWORD, implode("\n", $GLOBALS['galbridge_test_log'] ?? []));
    }

    /**
     * A refused DSN must leave its reason code in the log (config_invalid, docs/ARCHITECTURE.md 14),
     * not only a generic "status=db_error" - the administrator cannot tell a policy refusal from an
     * outage otherwise.
     */
    public function testRefusedDsnIsLoggedAsConfigInvalid(): void
    {
        $factory = Env::factory(Env::config('pgsql://vmail:' . self::PASSWORD . '@127.0.0.1/vmail'), 'jan.kowalski@example.com');
        $factory->create(BookDefinition::DOMAIN);
        $log = implode("\n", $GLOBALS['galbridge_test_log'] ?? []);
        self::assertStringContainsString('config_invalid', $log, 'log: ' . $log);
        self::assertStringNotContainsString(self::PASSWORD, $log);
    }

    // ------------------------------------------------------------------ real roles: privilege probe

    /**
     * @return array<string, array{string, string}>
     */
    public static function serverEnginesWithBroadGrant(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            if ($engine === 'sqlite') {
                continue;
            }
            $out[$engine . ': table-level SELECT'] = [$dsn, 'table'];
            $out[$engine . ': template columns + mailbox.password'] = [$dsn, 'password'];
            $out[$engine . ': template columns + mailbox.recovery_email'] = [$dsn, 'recovery_email'];
            $out[$engine . ': template columns + mailbox.allow_nets'] = [$dsn, 'allow_nets'];
        }
        if ($out === []) {
            $out['no server engine configured'] = ['', ''];
        }
        return $out;
    }

    #[DataProvider('serverEnginesWithBroadGrant')]
    public function testRoleThatCanReadSecretColumnsDisablesEveryBook(string $dsn, string $variant): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $grants = self::templateColumns();
        if ($variant !== 'table') {
            $grants['mailbox'][] = $variant;
        }
        $roleDsn = $this->createRole($dsn, 'broad', $variant === 'table' ? null : $grants);

        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['*'], 'galbridge_global_allow_wildcard' => true, 'galbridge_global_include_home' => true];
        $factory = Env::factory(Env::config($roleDsn, $options), 'jan.kowalski@example.com');
        self::assertSame([], $factory->books());
        self::assertSame('role_too_broad', $factory->resolver()->status());
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = $factory->create($id);
            self::assertInstanceOf(DeniedBook::class, $book, $id);
            self::assertSame(0, $book->search('*', 'jan')->count);
        }
        self::assertSame('role_too_broad', $_SESSION['galbridge']['status'] ?? null, 'the decision is cached like any other');
        self::assertStringContainsString('role_too_broad', implode("\n", $GLOBALS['galbridge_test_log']));
    }

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
     * A role without the optional iRedMail 1.7.3 columns (first_name, last_name, telephone, mobile,
     * birthday) and without department keeps working with fewer fields and one WARN; no SQL ever
     * names a column the role cannot read (that would be a db_error).
     */
    #[DataProvider('serverEngines')]
    public function testRoleWithoutOptionalColumnsKeepsWorkingWithFewerFields(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $roleDsn = $this->createRole($dsn, 'narrow', self::requiredColumns());
        $options = ['galbridge_fields' => Config::FIELDS, 'galbridge_domain_groups' => 'department', 'galbridge_user_aliases' => true];
        $factory = Env::factory(Env::config($roleDsn, $options), 'jan.kowalski@example.com');
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);

        self::assertSame(['name', 'email'], array_keys($book->coltypes));
        self::assertFalse($book->groups, 'department groups need the department column');
        self::assertSame([], $book->list_groups());

        $book->set_pagesize(200);
        $all = $book->list_records();
        self::assertSame(13, $all->count);
        $jan = $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertIsArray($jan);
        self::assertSame('Jan Kowalski', $jan['name']);
        foreach (['firstname', 'surname', 'department', 'phone:work', 'phone:mobile', 'birthday', 'organization'] as $key) {
            self::assertArrayNotHasKey($key, $jan);
        }
        self::assertSame(['jan@example.net', 'jk@example.com', 'sprzedaz@example.com'], $jan['email:other'] ?? null);
        // anna has no display name; first/last name are not readable: the local part is shown
        self::assertSame('anna.nowak', $book->get_record('u_annaDnowakAexampleDcom', true)['name'] ?? null);

        // searches on unavailable fields match nothing; available ones still work
        self::assertSame(0, $book->search(['department'], ['Sprzedaż'])->count);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search('*', 'Kowalski')));
        self::assertSame(0, $book->search('*', 'Księgowość')->count);

        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('field_unavailable', $log);
        self::assertStringNotContainsString('db_error', $log, 'no statement may name an ungranted column');
    }

    /**
     * Without mailbox.expired the expiry predicate cannot be evaluated: with galbridge_hide_expired the
     * book fails closed; without it the book works.
     */
    #[DataProvider('serverEngines')]
    public function testRoleWithoutARequiredColumnFailsClosed(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $columns = self::requiredColumns();
        $columns['mailbox'] = array_values(array_diff($columns['mailbox'], ['expired']));
        $roleDsn = $this->createRole($dsn, 'noexp', $columns);

        $factory = Env::factory(Env::config($roleDsn), 'jan.kowalski@example.com');
        self::assertSame([], $factory->books());
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(DeniedBook::class, $book);
        self::assertSame(0, $book->search('*', 'Kowalski')->count);

        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($roleDsn, ['galbridge_hide_expired' => false]), 'jan.kowalski@example.com');
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        $emails = self::emails($book->list_records());
        self::assertContains('expired.user@example.com', $emails, 'hide_expired off shows expired users');
        self::assertNotContains('disabled.user@example.com', $emails);
        self::assertStringNotContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    /**
     * The schema probe runs before the Viewer lookup, so a missing required column must be reported
     * as column_missing (docs/ARCHITECTURE.md 12/14) - not provoked as an SQL permission error
     * (db_error with SQLSTATE 42000/42501) by a statement that names the ungranted column.
     */
    #[DataProvider('serverEngines')]
    public function testMissingRequiredColumnIsReportedAsColumnMissing(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $columns = self::requiredColumns();
        $columns['mailbox'] = array_values(array_diff($columns['mailbox'], ['expired']));
        $roleDsn = $this->createRole($dsn, 'noexp', $columns);

        Env::factory(Env::config($roleDsn), 'jan.kowalski@example.com')->create(BookDefinition::DOMAIN);
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('column_missing', $log);
        self::assertStringNotContainsString('db_error', $log);
    }

    /**
     * forwardings.domain is required only for galbridge_user_aliases (the display aliases join their
     * domain): with the option a role without it is column_missing - never a db_error on the first
     * listing - and without the option the same role works.
     */
    #[DataProvider('serverEngines')]
    public function testForwardingsDomainIsRequiredOnlyForUserAliases(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $columns = self::requiredColumns();
        $columns['forwardings'] = array_values(array_diff($columns['forwardings'], ['domain']));
        $roleDsn = $this->createRole($dsn, 'nofd', $columns);

        $factory = Env::factory(Env::config($roleDsn, ['galbridge_user_aliases' => true]), 'jan.kowalski@example.com');
        self::assertSame([], $factory->books());
        self::assertSame('column_missing', $factory->resolver()->status());
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(DeniedBook::class, $book);
        self::assertSame(0, $book->list_records()->count);
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('column_missing: required vmail columns not readable: forwardings.domain', $log);
        self::assertStringNotContainsString('db_error', $log);

        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $book = Env::factory(Env::config($roleDsn), 'jan.kowalski@example.com')->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        self::assertSame(13, $book->list_records()->count);
        self::assertStringNotContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    /**
     * The probe covers exactly the tables GAL Bridge reads (alias_domain is not one of them).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testOnlyTheReadTablesAreProbed(string $dsn): void
    {
        self::assertNotContains('alias_domain', Connection::TABLES);
        $conn = new Connection(new Dsn($dsn, Env::config($dsn)), 3);
        $columns = $conn->visibleColumns();
        self::assertSame(Connection::TABLES, array_keys($columns));
        foreach (['mailbox', 'domain', 'forwardings'] as $table) {
            self::assertNotSame([], $columns[$table], $table);
        }
        $conn->close();
    }

    /**
     * Connection::lastError() keeps the sanitised reason of a failed connect for diag (a CLI run never
     * writes Roundcube's errors log): the server's refusal, never the password.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testConnectFailureReasonIsKeptWithoutThePassword(string $dsn): void
    {
        $variants = [];
        if (str_starts_with($dsn, 'sqlite:')) {
            $variants['unreadable file'] = ['sqlite:///' . sys_get_temp_dir() . '?mode=0600', '/unable to open/i'];
        } else {
            $variants['refused port'] = [(string) preg_replace('~@[^/]+/~', '@127.0.0.1:1/', $dsn, 1), '/refused|2002/i'];
            $user = (string) (\rcube_db::parse_dsn($dsn)['username'] ?? '');
            $variants['wrong password'] = [(string) preg_replace('~^([a-z]+://[^:]+):[^@]*@~', '$1:' . self::PASSWORD . '@', $dsn), '/' . preg_quote($user, '/') . '/'];
        }
        foreach ($variants as $label => [$bad, $reason]) {
            $_SESSION = [];
            $conn = new Connection(new Dsn($bad, Env::config($bad)), 3);
            self::assertSame('', $conn->lastError(), $label . ': nothing before connecting');
            try {
                $conn->flavour();
                self::fail($label . ': connected');
            } catch (GalBridgeException $e) {
                self::assertSame('db_down', $e->getReason());
            }
            self::assertMatchesRegularExpression($reason, $conn->lastError(), $label);
            self::assertStringNotContainsString(self::PASSWORD, $conn->lastError(), $label);
            self::assertStringNotContainsString('testonly-ro', $conn->lastError(), $label);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $conn->lastError(), $label . ': one log-safe line');
        }
        $_SESSION = [];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $options
     */
    private static function config(array $options = []): Config
    {
        $rc = new \rcube_config();
        foreach ($options as $k => $v) {
            $rc->set($k, $v);
        }
        return new Config($rc, [], $options);
    }

    private static function assertRefused(string $dsn, Config $config, ?string $rcDsn = null, ?string $pwDsn = null): void
    {
        try {
            $d = new Dsn($dsn, $config, $rcDsn, $pwDsn);
        } catch (GalBridgeException $e) {
            self::assertSame('config_invalid', $e->getReason());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            return;
        }
        self::fail('DSN accepted but must be refused: ' . str_replace(self::PASSWORD, '***', $dsn) . ' (driver ' . $d->driver() . ')');
    }

    /**
     * @return list<string>
     */
    private static function emails(\rcube_result_set $rs): array
    {
        $out = [];
        foreach ($rs->records as $r) {
            $out[] = (string) ($r['email:work'][0] ?? '');
        }
        return $out;
    }

    /**
     * Columns of SQL/grants.*.sql (the documented template).
     *
     * @return array<string, list<string>>
     */
    private static function templateColumns(): array
    {
        $cols = self::requiredColumns();
        array_push($cols['mailbox'], 'first_name', 'last_name', 'department', 'telephone', 'mobile', 'birthday');
        $cols['domain'][] = 'description';
        return $cols;
    }

    /**
     * The columns GAL Bridge cannot work without (persons, aliases, lists, accesspolicy). alias_domain
     * is not read (and not probed: Connection::TABLES).
     *
     * @return array<string, list<string>>
     */
    private static function requiredColumns(): array
    {
        return [
            'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver', 'expired'],
            'domain' => ['domain', 'active', 'backupmx', 'expired'],
            'forwardings' => ['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'],
            'alias' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
            'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
            'moderators' => ['address', 'moderator'],
            'maillist_owners' => ['address', 'owner'],
        ];
    }

    /**
     * Create a login role on the throw-away test database: table-level SELECT ($columns = null) or
     * column-level SELECT. Returns the DSN of the new role (dropped in tearDown).
     *
     * @param null|array<string, list<string>> $columns
     */
    private function createRole(string $dsn, string $kind, ?array $columns): string
    {
        $parsed = \rcube_db::parse_dsn($dsn);
        $engine = (string) $parsed['phptype'] === 'pgsql' ? 'pgsql' : 'mysql';
        $db = (string) ($parsed['database'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*test[a-z0-9_]*$/', $db) || !Dsn::isLoopback($parsed)) {
            self::markTestSkipped('refusing to create roles outside a local *test* database: ' . $db);
        }
        if (self::adminCommand($engine) === null) {
            self::markTestSkipped("no administrator access to the {$engine} test server (set "
                . ($engine === 'pgsql' ? 'PSQL_ADMIN, e.g. "runuser -u postgres -- psql"' : 'MYSQL_ADMIN, e.g. "mariadb -u root"') . ' as for load.sh)');
        }
        $role = 'gbt_' . $kind . '_' . getmypid();
        $password = 'p' . bin2hex(random_bytes(12));
        self::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];

        if ($engine === 'mysql') {
            $to = "'{$role}'@'localhost', '{$role}'@'%'";
            $sql = "CREATE USER '{$role}'@'localhost' IDENTIFIED BY '{$password}'; CREATE USER '{$role}'@'%' IDENTIFIED BY '{$password}';";
            if ($columns === null) {
                $sql .= " GRANT SELECT ON `{$db}`.* TO {$to};";
            } else {
                foreach ($columns as $table => $cols) {
                    $sql .= ' GRANT SELECT (`' . implode('`, `', $cols) . "`) ON `{$db}`.`{$table}` TO {$to};";
                }
            }
        } else {
            $sql = "CREATE ROLE {$role} LOGIN PASSWORD '{$password}' NOINHERIT; GRANT CONNECT ON DATABASE {$db} TO {$role}; GRANT USAGE ON SCHEMA public TO {$role};";
            if ($columns === null) {
                $sql .= " GRANT SELECT ON ALL TABLES IN SCHEMA public TO {$role};";
            } else {
                foreach ($columns as $table => $cols) {
                    $sql .= ' GRANT SELECT (' . implode(', ', $cols) . ") ON {$table} TO {$role};";
                }
            }
        }
        [$code, , $err] = self::admin($engine, $db, $sql);
        self::assertSame(0, $code, 'creating the test role failed: ' . $err);

        return (string) preg_replace('~^([a-z]+)://[^@]*@~i', '$1://' . $role . ':' . $password . '@', $dsn);
    }

    private static function dropRole(string $engine, string $db, string $role): void
    {
        if (self::adminCommand($engine) === null) {
            return;
        }
        $sql = $engine === 'mysql'
            ? "DROP USER IF EXISTS '{$role}'@'localhost', '{$role}'@'%';"
            : "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN EXECUTE 'DROP OWNED BY {$role}'; EXECUTE 'DROP ROLE {$role}'; END IF; END \$\$;";
        self::admin($engine, $db, $sql);
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
     * @return array{int, string, string}
     */
    private static function admin(string $engine, string $db, string $sql): array
    {
        $cmd = self::adminCommand($engine) ?? [];
        $cmd = $engine === 'pgsql'
            ? [...$cmd, '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-d', $db, '-c', $sql]
            : [...$cmd, '-e', $sql];
        return self::exec($cmd);
    }

    /**
     * Run a command without a shell; stdin is closed, so nothing can prompt for a password.
     *
     * @param list<string> $cmd
     *
     * @return array{int, string, string}
     */
    private static function exec(array $cmd): array
    {
        $env = getenv();
        $env['PGCONNECT_TIMEOUT'] = '5';
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return [127, '', 'cannot start ' . ($cmd[0] ?? '')];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }
}
