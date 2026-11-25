<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Schema;
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
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
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
        // a valid list (second verification pass, F-30: an entry that is not a role name refuses every
        // connection) ADDS names: the built-in ones stay refused, the dedicated role connects
        $config = self::config(['galbridge_db_refused_users' => ['Legacy_GAL']]);
        self::assertRefused('mysql://legacy_gal:' . self::PASSWORD . '@127.0.0.1/vmail', $config);
        self::assertRefused('mysql://vmail:' . self::PASSWORD . '@127.0.0.1/vmail', $config);
        self::assertRefused('mysql://rcmail_rw:' . self::PASSWORD . '@127.0.0.1/vmail', $config, 'mysql://rcmail_rw:x@localhost/rc');
        self::assertSame('galbridge', (new Dsn('mysql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', $config))->user());
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
            'pgsql verify-full with a CA file' => ["pgsql://{$u}@db.example.net/vmail?sslmode=verify-full&sslrootcert=/etc/ssl/ca.pem"],
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

    /**
     * sslmode=verify-ca checks that the certificate comes from the trusted CA, but not that it was
     * issued for the host the plugin connects to: with a shared or public CA any holder of one of
     * its certificates on the network path could pose as the database server. It is not "verified
     * TLS"; an administrator whose CA issues certificates for this server only can opt in.
     */
    public function testVerifyCaIsNotVerifiedTls(): void
    {
        $dsn = 'pgsql://galbridge:' . self::PASSWORD . '@db.example.net/vmail?sslmode=verify-ca&sslrootcert=/etc/ssl/ca.pem';
        self::assertFalse(Dsn::verifiedTls('pgsql', \rcube_db::parse_dsn($dsn)));
        self::assertTrue(Dsn::verifiedTls('pgsql', \rcube_db::parse_dsn(str_replace('verify-ca', 'verify-full', $dsn))));
        try {
            new Dsn($dsn, self::config());
            self::fail('sslmode=verify-ca was accepted for a remote host');
        } catch (GalBridgeException $e) {
            self::assertSame('config_invalid', $e->getReason());
            self::assertStringContainsString('verify-ca does not verify the host name', $e->getMessage());
            self::assertStringContainsString('verify-full', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
        foreach (['verify-ca', 'VERIFY-CA', 'Verify-Ca'] as $mode) {
            self::assertRefused(str_replace('verify-ca', $mode, $dsn), self::config());
        }

        // the explicit opt-in: accepted, and it never goes unnoticed (log through the plugin, diag)
        $accepted = new Dsn($dsn, self::config(['galbridge_db_allow_verify_ca' => true]));
        self::assertCount(1, $accepted->warnings());
        self::assertStringContainsString('verify-ca does not verify the host name', $accepted->warnings()[0]);
        self::assertStringContainsString('galbridge_db_allow_verify_ca', $accepted->warnings()[0]);
        self::assertTrue($accepted->remote());

        // the opt-in is exactly that: it accepts no weaker mode and no plaintext
        foreach (['require', 'prefer', 'allow', 'disable'] as $mode) {
            self::assertRefused(str_replace('verify-ca', $mode, $dsn), self::config(['galbridge_db_allow_verify_ca' => true]));
        }
        self::assertRefused('pgsql://galbridge:' . self::PASSWORD . '@db.example.net/vmail', self::config(['galbridge_db_allow_verify_ca' => true]));
        self::assertRefused('mysql://galbridge:' . self::PASSWORD . '@db.example.net/vmail?ca=/etc/ssl/ca.pem', self::config(['galbridge_db_allow_verify_ca' => true]));
        // a string is not an opt-in
        self::assertRefused($dsn, self::config(['galbridge_db_allow_verify_ca' => 'true']));
        // verify-full needs no opt-in and gives no warning; a local server needs neither
        self::assertSame([], (new Dsn(str_replace('verify-ca', 'verify-full', $dsn), self::config()))->warnings());
        self::assertSame([], (new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail?sslmode=verify-ca', self::config()))->warnings());
        self::assertFalse((new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', self::config()))->remote());
    }

    // ------------------------------------------------------------------ the DSN as the driver sees it (audit I-01)

    /**
     * Roundcube joins host, port, database and the parameters into the driver's connection string
     * WITHOUT quoting ("host=...;port=...;dbname=..."; PDO turns ';' into blanks for libpq), and lets
     * a parameter fill any setting the DSN did not give. Everything that could make the driver read
     * another target or another TLS mode than the one this policy judged is refused.
     *
     * @return array<string, array{string, string}> DSN => part of the reason
     */
    public static function malformedDsns(): array
    {
        $u = 'galbridge:' . self::PASSWORD;
        return [
            // port
            'port with a second setting' => ["pgsql://{$u}@127.0.0.1:5432%20host=db.evil.example/vmail", 'port'],
            'port that is not a number' => ["mysql://{$u}@127.0.0.1:mysql/vmail", 'port'],
            'port 0' => ["pgsql://{$u}@127.0.0.1:0/vmail", 'port'],
            'port above 65535' => ["mysql://{$u}@127.0.0.1:70000/vmail", 'port'],
            // host
            'host with a blank (two libpq settings)' => ["pgsql://{$u}@127.0.0.1%20sslmode=disable/vmail", 'host'],
            'host with a semicolon' => ["mysql://{$u}@127.0.0.1%3Bhost=db.evil.example/vmail", 'host'],
            'host with a quote' => ["pgsql://{$u}@127.0.0.1'x/vmail", 'host'],
            // STAGE 6: spellings the driver reads differently from the policy
            'localhost in upper case (MySQL: another socket)' => ["mysql://{$u}@LOCALHOST/vmail", 'lower case'],
            'localhost in mixed case (PostgreSQL)' => ["pgsql://{$u}@LocalHost/vmail", 'lower case'],
            'old driver(syntax) notation' => ["mysql(mysqli)://{$u}@127.0.0.1/vmail", 'must start with'],
            'driver without //' => ["pgsql:{$u}@127.0.0.1/vmail", 'must start with'],
            // database
            'database with a second setting' => ["pgsql://{$u}@127.0.0.1/vmail%20host=db.evil.example", 'database'],
            'database with a quote' => ["mysql://{$u}@127.0.0.1/vmail'", 'database'],
            'no database' => ["pgsql://{$u}@127.0.0.1", 'database'],
            // parameters: unknown names (a parameter fills any setting the DSN did not give)
            'parameter hostspec' => ["pgsql://{$u}@unix(/var/run/postgresql)/vmail?hostspec=db.evil.example", 'hostspec'],
            'parameter port' => ["pgsql://{$u}@127.0.0.1/vmail?port=6432", 'port'],
            'parameter socket' => ["mysql://{$u}@127.0.0.1/vmail?socket=/tmp/evil.sock", 'socket'],
            'parameter protocol' => ["mysql://{$u}@127.0.0.1/vmail?protocol=unix", 'protocol'],
            'parameter username' => ["mysql://{$u}@127.0.0.1/vmail?username=root", 'username'],
            'libpq hostaddr' => ["pgsql://{$u}@127.0.0.1/vmail?hostaddr=198.51.100.7", 'hostaddr'],
            'libpq options' => ["pgsql://{$u}@127.0.0.1/vmail?options=-c%20default_transaction_read_only%3Doff", 'options'],
            'libpq sslcompression' => ["pgsql://{$u}@127.0.0.1/vmail?sslcompression=1", 'sslcompression'],
            'a MySQL parameter for PostgreSQL' => ["pgsql://{$u}@127.0.0.1/vmail?verify_server_cert=1", 'verify_server_cert'],
            'a PostgreSQL parameter for MySQL' => ["mysql://{$u}@127.0.0.1/vmail?sslmode=verify-full", 'sslmode'],
            'unknown parameter' => ["mysql://{$u}@127.0.0.1/vmail?new_link=true", 'new_link'],
            // parameters: form
            'parameter without a value' => ["pgsql://{$u}@127.0.0.1/vmail?sslmode", 'name=value'],
            'empty parameter' => ["pgsql://{$u}@127.0.0.1/vmail?sslmode=verify-full&", 'name=value'],
            'value with a raw =' => ["pgsql://{$u}@127.0.0.1/vmail?sslrootcert=/etc/ssl/a=b.pem", 'name=value'],
            'parameter name in upper case' => ["pgsql://{$u}@127.0.0.1/vmail?SSLMODE=verify-full", 'name=value'],
            'the same parameter twice (the first one wins in the core)' => ["pgsql://{$u}@db.example.net/vmail?sslmode=disable&sslmode=verify-full", 'twice'],
            'a second setting behind a raw =' => ["pgsql://{$u}@db.example.net/vmail?sslmode=verify-full%20sslmode=disable", 'name=value'],
            // parameters: values (a complete second setting, its = percent-encoded so that the core
            // parser would hand it to the driver in one piece)
            'sslmode with a second setting' => ["pgsql://{$u}@db.example.net/vmail?sslmode=verify-full%20sslmode%3Ddisable", 'sslmode'],
            'unknown sslmode' => ["pgsql://{$u}@127.0.0.1/vmail?sslmode=maybe", 'sslmode'],
            'certificate path with a blank' => ["pgsql://{$u}@db.example.net/vmail?sslmode=verify-full&sslrootcert=/etc/ssl/ca.pem%20host%3Ddb.evil.example", 'sslrootcert'],
            'relative certificate path' => ["mysql://{$u}@db.example.net/vmail?ca=ca.pem&verify_server_cert=1", '"ca"'],
            'certificate path with a semicolon' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/ca.pem%3Bhost%3Devil&verify_server_cert=1", '"ca"'],
            'application name with a blank' => ["pgsql://{$u}@127.0.0.1/vmail?application_name=gal%20host%3Ddb.evil.example", 'application_name'],
            'charset with a semicolon' => ["mysql://{$u}@127.0.0.1/vmail?charset=utf8mb4%3Bhost%3Ddb.evil.example", 'charset'],
            'verify_server_cert that is no switch' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/ca.pem&verify_server_cert=maybe", 'verify_server_cert'],
            // user
            'user with a blank' => ['pgsql://gal%20bridge:' . self::PASSWORD . '@127.0.0.1/vmail', 'user name'],
            // no target at all: the client library would choose one itself
            'no host and no socket (PostgreSQL)' => ["pgsql://{$u}@/vmail", 'host or a unix socket'],
            'no host and no socket (MySQL)' => ["mysql://{$u}@/vmail", 'host or a unix socket'],
            'empty unix socket' => ["pgsql://{$u}@unix()/vmail", 'host or a unix socket'],
            'relative unix socket' => ["mysql://{$u}@unix(mysqld.sock)/vmail", 'socket'],
        ];
    }

    #[DataProvider('malformedDsns')]
    public function testDsnIsJudgedInTheFormTheDriverReceives(string $dsn, string $reason): void
    {
        foreach ([self::config(), self::config(['galbridge_db_allow_plaintext' => true, 'galbridge_db_allow_verify_ca' => true])] as $config) {
            try {
                new Dsn($dsn, $config);
                self::fail('accepted: ' . str_replace(self::PASSWORD, '***', $dsn));
            } catch (GalBridgeException $e) {
                self::assertSame('config_invalid', $e->getReason());
                self::assertStringContainsString($reason, $e->getMessage());
                self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            }
        }
    }

    public function testOrdinaryDsnsAreStillAccepted(): void
    {
        $u = 'galbridge:' . self::PASSWORD;
        foreach ([
            "mysql://{$u}@127.0.0.1/vmail",
            "mysql://{$u}@localhost/vmail",
            "mysqli://{$u}@127.0.0.1:3306/vmail",
            "mysql://{$u}@unix(/run/mysqld/mysqld.sock)/vmail",
            "mysql://{$u}@[::1]:3306/vmail",
            "mysql://{$u}@127.0.0.1/vmail?charset=utf8mb4&timeout=5",
            "mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/certs/vmail-db-ca.pem&verify_server_cert=1&cert=/etc/roundcube/galbridge-client.pem&key=/etc/roundcube/galbridge-client.key",
            "mysql://{$u}@db.example.net/vmail?capath=/etc/ssl/certs&verify_server_cert=1&cipher=ECDHE-RSA-AES256-GCM-SHA384",
            "pgsql://{$u}@127.0.0.1/vmail",
            "pgsql://{$u}@127.0.0.1:5432/galbridge_vmail_test",
            "pgsql://{$u}@unix(/var/run/postgresql)/vmail",
            "pgsql://{$u}@127.0.0.1/vmail?application_name=galbridge",
            "pgsql://{$u}@db.example.com/vmail?sslmode=verify-full&sslrootcert=/etc/ssl/certs/vmail-db-ca.pem&sslcert=/etc/roundcube/galbridge-client.crt&sslkey=/etc/roundcube/galbridge-client.key",
            // a password may contain anything (it never reaches the connection string): '?', '=', '&', ';', '@'
            'pgsql://galbridge:' . rawurlencode('p@ss?word=1&x;y z') . '@127.0.0.1/vmail',
            'mysql://galbridge:a?b=c&d@127.0.0.1/vmail',
            // (a database name that the core parser cuts at ';' - "vmail%3Bhost=..." - was accepted
            // here until the second verification pass, F-13: text the parser drops is refused now,
            // see Remediation2DsnTest)
        ] as $dsn) {
            $accepted = new Dsn($dsn, self::config());
            self::assertSame('galbridge', $accepted->user(), $dsn);
            self::assertSame([], $accepted->warnings(), $dsn);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $accepted->database(), $dsn);

            // The DSN that is handed to the driver names the SAME target and credentials as the one
            // that was judged, plus the connect timeout as a parameter - also when the password
            // holds a '?' (the timeout must not be appended to the database name with '&').
            $judged = \rcube_db::parse_dsn($dsn);
            $effective = \rcube_db::parse_dsn($accepted->effective());
            self::assertIsArray($judged, $dsn);
            self::assertIsArray($effective, $dsn);
            foreach (['phptype', 'username', 'password', 'protocol', 'hostspec', 'port', 'socket', 'database'] as $part) {
                self::assertSame($judged[$part] ?? null, $effective[$part] ?? null, "{$dsn}: {$part}");
            }
            self::assertSame((string) ($judged['timeout'] ?? Config::DEFAULTS['galbridge_db_connect_timeout']), (string) ($effective['timeout'] ?? ''), $dsn . ': timeout');
            unset($judged['timeout'], $effective['timeout']);
            self::assertSame($judged, $effective, $dsn . ': nothing else changed');
        }
    }

    /**
     * libpq reads settings that the DSN does not give from the environment of the PHP process. A
     * variable that redirects the connection whatever the DSN says is refused; the port only when
     * the DSN names none.
     */
    public function testLibpqEnvironmentThatRedirectsTheConnectionIsRefused(): void
    {
        $dsn = 'pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail';
        foreach (['PGHOSTADDR' => '198.51.100.7', 'PGSERVICE' => 'other', 'PGSERVICEFILE' => '/tmp/pg_service.conf', 'PGPORT' => '6432'] as $var => $value) {
            putenv($var . '=' . $value);
            try {
                new Dsn($dsn, self::config());
                self::fail($var . ' in the environment was accepted');
            } catch (GalBridgeException $e) {
                self::assertSame('config_invalid', $e->getReason());
                self::assertStringContainsString($var, $e->getMessage());
                self::assertStringNotContainsString($value, $e->getMessage(), 'the value is not repeated');
            } finally {
                putenv($var);
            }
        }
        // PGPORT cannot change a port that the DSN names; MySQL does not use libpq
        putenv('PGPORT=6432');
        try {
            self::assertSame([], (new Dsn('pgsql://galbridge:' . self::PASSWORD . '@127.0.0.1:5432/vmail', self::config()))->warnings());
            self::assertSame([], (new Dsn('mysql://galbridge:' . self::PASSWORD . '@127.0.0.1/vmail', self::config()))->warnings());
        } finally {
            putenv('PGPORT');
        }
        // settings that libpq adds to the connection are reported (log and diag)
        putenv('PGOPTIONS=-c statement_timeout=0');
        try {
            $warnings = (new Dsn($dsn, self::config()))->warnings();
            self::assertCount(1, $warnings);
            self::assertStringContainsString('PGOPTIONS', $warnings[0]);
            self::assertStringNotContainsString('statement_timeout', $warnings[0]);
        } finally {
            putenv('PGOPTIONS');
        }
        self::assertSame([], (new Dsn($dsn, self::config()))->warnings());
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
     * The domain column of the forwardings view is always required (the viewer's own aliases count
     * only while the domain their row belongs to is deliverable): a role without it is
     * column_missing - never a db_error on the first statement that names the column.
     */
    #[DataProvider('serverEngines')]
    public function testForwardingsDomainIsAlwaysRequired(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $columns = self::requiredColumns();
        $columns[Schema::FORWARDINGS] = array_values(array_diff($columns[Schema::FORWARDINGS], ['domain']));
        $roleDsn = $this->createRole($dsn, 'nofd', $columns);

        foreach ([['galbridge_user_aliases' => true], []] as $options) {
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $factory = Env::factory(Env::config($roleDsn, $options), 'jan.kowalski@example.com');
            self::assertSame([], $factory->books());
            self::assertSame('column_missing', $factory->resolver()->status());
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(DeniedBook::class, $book);
            self::assertSame(0, $book->list_records()->count);
            $log = implode("\n", $GLOBALS['galbridge_test_log']);
            self::assertStringContainsString('column_missing: required vmail columns not readable: galbridge_forwardings.domain', $log);
            self::assertStringNotContainsString('db_error', $log);
        }
    }

    // ------------------------------------------------------------------ the forwardings table (audit U-06)

    /**
     * vmail.forwardings also holds the private forwarding targets of every mailbox. The role built
     * from the grant templates cannot read that table at all - it reads a view without those rows.
     */
    #[DataProvider('serverEngines')]
    public function testTheTemplateRoleCannotReadPrivateForwardingTargets(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $conn = new Connection(new Dsn($dsn, Env::config($dsn)), 3);
        // the table itself: refused by the server, for every column
        foreach (['SELECT forwarding FROM `forwardings` WHERE 1 = 0', 'SELECT address FROM `forwardings` WHERE 1 = 0', 'SELECT COUNT(*) FROM `forwardings`'] as $sql) {
            self::assertSame('denied', $conn->probe($sql), $sql);
        }
        self::assertSame([], $conn->visibleColumns()[Schema::FORWARDINGS_TABLE], 'no column of the table is visible to the role');
        self::assertEqualsCanonicalizing(['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'], $conn->visibleColumns()[Schema::FORWARDINGS]);

        // the view: the rows the plugin needs, and no row that only forwards a mailbox - neither the
        // external targets (canaries of the fixture) nor the mailbox-to-itself rows
        $rows = $conn->select('SELECT f.address, f.forwarding, f.is_alias, f.is_list, f.is_maillist FROM `' . Schema::FORWARDINGS . '` f');
        self::assertNotSame([], $rows);
        $forwardings = array_column($rows, 'forwarding');
        foreach (['jan.home@outlook.test', 'private.destination@gmail.test'] as $canary) {
            self::assertNotContains($canary, $forwardings, 'a private forwarding target is readable through the view');
        }
        foreach ($rows as $row) {
            self::assertSame(1, max((int) $row['is_alias'], (int) $row['is_list'], (int) $row['is_maillist']), 'a row of another kind: ' . $row['address']);
        }
        self::assertContains('jk@example.com', array_column($rows, 'address'), 'per-user aliases are in the view');
        self::assertContains('newsletter@example.com', array_column($rows, 'address'), 'mailing lists are in the view');

        // read-only in every respect: the view cannot be used to change the table - refused for the
        // privilege, or (second verification pass, F-03: a fenced view is no updatable view at all)
        // by PostgreSQL as not updatable (55000) before it looks at the privilege; MariaDB/MySQL
        // refuse the privilege (1142) first
        foreach (['UPDATE `' . Schema::FORWARDINGS . '` SET active = active WHERE 1 = 0', 'DELETE FROM `' . Schema::FORWARDINGS . '` WHERE 1 = 0'] as $sql) {
            self::assertContains($conn->probeWrite($sql), str_starts_with($dsn, 'pgsql') ? ['denied', '55000'] : ['denied'], $sql);
        }
        $conn->close();
    }

    /**
     * A role that CAN read the forwardings table - the grants of GAL Bridge 1.0.0, or a broader role
     * - is refused like one that can read password hashes: every book is denied.
     *
     * @return array<string, array{string, string}>
     */
    public static function serverEnginesWithForwardingsTable(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            if ($engine === 'sqlite') {
                continue;
            }
            $out[$engine . ': the 1.0.0 column grant on the table'] = [$dsn, 'columns'];
            $out[$engine . ': the table next to the view'] = [$dsn, 'both'];
            $out[$engine . ': one column of the table'] = [$dsn, 'one'];
        }
        return $out === [] ? ['no server engine configured' => ['', '']] : $out;
    }

    #[DataProvider('serverEnginesWithForwardingsTable')]
    public function testRoleThatCanReadTheForwardingsTableIsRefused(string $dsn, string $variant): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $grants = self::requiredColumns();
        $view = $grants[Schema::FORWARDINGS];
        if ($variant === 'columns') {
            unset($grants[Schema::FORWARDINGS]);
            $grants[Schema::FORWARDINGS_TABLE] = $view;
        } elseif ($variant === 'both') {
            $grants[Schema::FORWARDINGS_TABLE] = $view;
        } else {
            $grants[Schema::FORWARDINGS_TABLE] = ['forwarding'];
        }
        $roleDsn = $this->createRole($dsn, 'fwtab', $grants);

        $factory = Env::factory(Env::config($roleDsn), 'jan.kowalski@example.com');
        self::assertSame([], $factory->books());
        self::assertSame('role_too_broad', $factory->resolver()->status());
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(DeniedBook::class, $book);
        self::assertSame(0, $book->search('*', 'jan')->count);
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('role_too_broad: the configured database role can read the table "forwardings"', $log);
        self::assertStringContainsString('galbridge_forwardings', $log, 'the log names the view to grant instead');
        self::assertStringNotContainsString('db_error', $log);
    }

    /**
     * The probe covers exactly the tables GAL Bridge reads (alias_domain is not one of them).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testOnlyTheReadTablesAreProbed(string $dsn): void
    {
        self::assertNotContains('alias_domain', Connection::TABLES);
        self::assertNotContains(Schema::FORWARDINGS_TABLE, Connection::TABLES, 'forwardings is read through its view only');
        self::assertSame([Schema::FORWARDINGS_TABLE], Connection::FORBIDDEN_TABLES);
        $conn = new Connection(new Dsn($dsn, Env::config($dsn)), 3);
        $columns = $conn->visibleColumns();
        // the relations the plugin reads, plus the table whose visibility means "role too broad"
        self::assertSame([...Connection::TABLES, ...Connection::FORBIDDEN_TABLES], array_keys($columns));
        foreach (['mailbox', 'domain', Schema::FORWARDINGS] as $table) {
            self::assertNotSame([], $columns[$table], $table);
        }
        if ($conn->driver() !== 'sqlite') {
            self::assertSame([], $columns[Schema::FORWARDINGS_TABLE], 'the role of the grant templates sees nothing of the table');
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
            // the view of the grant templates - never the forwardings table itself
            Schema::FORWARDINGS => ['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'],
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
