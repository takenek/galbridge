<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-13: the DSN handed to the driver is the DSN the policy judged.
 *
 * 1.0.1 judged the parsed DSN but handed the driver the configured TEXT with "?timeout=N" appended.
 * Where the core parser drops or reinterprets text without a word, the two differed: a '?' before
 * the database turned the timeout into part of the database name (and the driver got no connect
 * timeout at all), a second port, text around an IPv6 address and the old unix+ / tcp() notations
 * were accepted and silently changed. Now:
 *
 *  - the target is written as host, host:port, [IPv6], [IPv6]:port or unix(/path), followed by the
 *    database name exactly as the driver receives it, then the parameters - anything else is
 *    refused;
 *  - the effective DSN is rebuilt from the judged parts, and the core parser must read exactly those
 *    parts back;
 *  - MySQL ignores a port next to "localhost" (its unix socket) or next to unix(): refused; MySQL
 *    "localhost" is the socket pdo_mysql.default_socket names, which diag shows;
 *  - unix socket paths fit into sun_path (107 bytes; PostgreSQL including ".s.PGSQL.<port>");
 *  - PostgreSQL "localhost" depends on the system resolver: accepted with a warning;
 *  - the driver class is created directly (the core factory ends the process on an unknown driver),
 *    and an error the driver raises while connecting is an outage like any other (memoised, its
 *    reason on diag's connect line).
 *
 * No database server is needed. The password below is a throw-away test value.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DsnTest extends TestCase
{
    private const PASSWORD = 'r2-dsn-pw';

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
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonCanonicalTargets(): array
    {
        $u = 'galbridge:' . self::PASSWORD;
        return [
            'a ? before the database (MySQL): the timeout became part of the database name' => ["mysql://{$u}@[::1]?ca=/vmail"],
            'a ? before the database (PostgreSQL)' => ["pgsql://{$u}@[::1]?sslrootcert=/vmail"],
            'a second port (the parser keeps the first)' => ["pgsql://{$u}@127.0.0.1:5432:6543/vmail"],
            'text after an IPv6 address' => ["mysql://{$u}@[::1]x:3306/vmail"],
            'text before an IPv6 address' => ["mysql://{$u}@x[::1]/vmail"],
            'the old unix+ notation' => ["mysql://{$u}@unix+%2Frun%2Fmysqld%2Fmysqld.sock/vmail"],
            'the tcp() notation' => ["mysql://{$u}@tcp(127.0.0.1:3306)/vmail"],
            'a percent-encoded host' => ["mysql://{$u}@%31%32%37.0.0.1/vmail"],
            'a raw + in a parameter value (read as a protocol separator)' => ["mysql://{$u}@127.0.0.1/vmail?ca=/etc/ssl/a+b.pem"],
        ];
    }

    #[DataProvider('nonCanonicalTargets')]
    public function testTheTargetMustBeWrittenInOneCanonicalForm(string $dsn): void
    {
        foreach ([self::config(), self::config(['galbridge_db_allow_plaintext' => true, 'galbridge_db_allow_verify_ca' => true])] as $config) {
            self::assertRefused($dsn, $config, 'DSN target must be written as host, host:port, [IPv6], [IPv6]:port or unix(/path/to/socket)');
        }
    }

    /**
     * The core parser decodes the database name and cuts it at ';' (#7034) without a word: the name
     * is accepted only in the form the driver receives it.
     */
    public function testTheDatabaseNameIsWrittenAsTheDriverReceivesIt(): void
    {
        $u = 'galbridge:' . self::PASSWORD;
        foreach (["mysql://{$u}@127.0.0.1/vmail%3Bhost=db.evil.example", "pgsql://{$u}@127.0.0.1/vmail;x", "mysql://{$u}@127.0.0.1/%76mail"] as $dsn) {
            self::assertRefused($dsn, self::config(), 'database name');
        }
        self::assertSame('vmail', (new Dsn("pgsql://{$u}@127.0.0.1/vmail", self::config()))->database());
    }

    public function testMysqlRefusesAPortItWouldIgnore(): void
    {
        $u = 'galbridge:' . self::PASSWORD;
        // "localhost" is the unix socket for mysqlnd and libmysqlclient: the port is not used
        self::assertRefused("mysql://{$u}@localhost:3307/vmail", self::config(), 'ignores the port');
        self::assertRefused("mysqli://{$u}@localhost:3306/vmail", self::config(), 'ignores the port');
        self::assertRefused("mysql://{$u}@unix(/run/mysqld/mysqld.sock:3306)/vmail", self::config(), 'ignores the port');
        // TCP on loopback with a port, and PostgreSQL (the port selects its socket file) keep working
        self::assertSame([], (new Dsn("mysql://{$u}@127.0.0.1:3307/vmail", self::config()))->warnings());
        $socket = new Dsn("pgsql://{$u}@unix(/var/run/postgresql:5433)/vmail", self::config());
        self::assertSame('/var/run/postgresql/.s.PGSQL.5433', $socket->socketPath());
        self::assertSame('pgsql://galbridge@unix(/var/run/postgresql:5433)/vmail', $socket->describe());
        self::assertFalse((new Dsn("pgsql://{$u}@localhost:5433/vmail", self::config()))->remote());
    }

    public function testOverlongSocketPathsAreRefused(): void
    {
        $u = 'galbridge:' . self::PASSWORD;
        // MySQL: the socket file itself (PHP would cut a longer path with a notice only)
        $fits = '/' . str_repeat('s', Dsn::SOCKET_PATH_MAX - 1);
        self::assertSame($fits, (new Dsn("mysql://{$u}@unix({$fits})/vmail", self::config()))->socketPath());
        self::assertRefused("mysql://{$u}@unix({$fits}s)/vmail", self::config(), 'unix socket path is too long');
        // PostgreSQL: the directory plus "/.s.PGSQL.<port>" (14 bytes for 5432, 15 for 15432)
        $dir = '/' . str_repeat('d', Dsn::SOCKET_PATH_MAX - 14 - 1);
        self::assertSame($dir . '/.s.PGSQL.5432', (new Dsn("pgsql://{$u}@unix({$dir})/vmail", self::config()))->socketPath());
        self::assertRefused("pgsql://{$u}@unix({$dir}d)/vmail", self::config(), 'unix socket path is too long');
        self::assertRefused("pgsql://{$u}@unix({$dir}:15432)/vmail", self::config(), 'unix socket path is too long');
        $shorter = substr($dir, 0, -1);
        self::assertSame($shorter . '/.s.PGSQL.15432', (new Dsn("pgsql://{$u}@unix({$shorter}:15432)/vmail", self::config()))->socketPath());
    }

    public function testPostgresLocalhostIsAcceptedWithAWarning(): void
    {
        $u = 'galbridge:' . self::PASSWORD;
        $dsn = new Dsn("pgsql://{$u}@localhost/vmail", self::config());
        self::assertCount(1, $dsn->warnings());
        self::assertStringContainsString('system resolver', $dsn->warnings()[0]);
        self::assertStringContainsString('127.0.0.1, [::1] or unix(/var/run/postgresql)', $dsn->warnings()[0]);
        self::assertFalse($dsn->remote(), 'still local: no TLS is required');
        self::assertSame([], (new Dsn("pgsql://{$u}@127.0.0.1/vmail", self::config()))->warnings());
        self::assertSame([], (new Dsn("pgsql://{$u}@unix(/var/run/postgresql)/vmail", self::config()))->warnings());
        if (extension_loaded('pdo_mysql')) {
            self::assertSame([], (new Dsn("mysql://{$u}@localhost/vmail", self::config()))->warnings(), 'MySQL: the socket, never a name lookup');
        }
    }

    /**
     * MySQL "localhost" is the socket of pdo_mysql.default_socket: describe() (diag's DSN line) shows
     * it, and a value that is not an absolute socket path that fits is refused.
     */
    public function testMysqlLocalhostNamesItsSocket(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('needs pdo_mysql');
        }
        $socket = (string) ini_get('pdo_mysql.default_socket');
        $dsn = new Dsn('mysql://galbridge:' . self::PASSWORD . '@localhost/vmail', self::config());
        self::assertSame('mysql://galbridge@localhost (socket ' . $socket . ')/vmail', $dsn->describe());
        self::assertSame($socket, $dsn->socketPath());
        self::assertStringNotContainsString(self::PASSWORD, $dsn->describe());

        // the setting can only be changed in php.ini (PHP_INI_SYSTEM): another PHP process
        $script = (string) tempnam(sys_get_temp_dir(), 'galbridge-r2dsn-');
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
            . "try {\n"
            . "    new GalBridge\\Db\\Dsn('mysql://galbridge:pw@localhost/vmail', GalBridge\\Tests\\Support\\Env::config('mysql://galbridge:pw@localhost/vmail'));\n"
            . "    echo 'ACCEPTED';\n"
            . "} catch (GalBridge\\Exception\\GalBridgeException \$e) {\n"
            . "    echo 'REFUSED:' . \$e->getMessage();\n"
            . "}\n");
        try {
            foreach (['mysqld.sock', '/' . str_repeat('s', Dsn::SOCKET_PATH_MAX), '/run/my sql/mysqld.sock'] as $value) {
                [$code, $out, $err] = CliRun::exec([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $value, $script]);
                self::assertSame(0, $code, $err);
                self::assertStringStartsWith('REFUSED:', $out, $value);
                self::assertStringContainsString('pdo_mysql.default_socket', $out, $value);
            }
            [, $out] = CliRun::exec([PHP_BINARY, '-d', 'pdo_mysql.default_socket=/run/mysqld/mysqld.sock', $script]);
            self::assertSame('ACCEPTED', $out);
        } finally {
            @unlink($script);
        }
    }

    /**
     * The DSN the driver receives is rebuilt from the judged parts: the core parser reads exactly
     * those parts back (plus the connect timeout), credentials are percent-encoded, and there is
     * exactly one timeout - also for passwords with '?', '@', '&', ';' or a blank, IPv6 addresses,
     * sockets, parameters and an explicit timeout.
     *
     * @return array<string, array{string, string}> DSN => the expected timeout
     */
    public static function acceptedDsns(): array
    {
        $u = 'galbridge:' . self::PASSWORD;
        return [
            'encoded password with ? @ & ; and a blank' => ['pgsql://galbridge:' . rawurlencode('p@ss?word=1&x;y z') . '@127.0.0.1/vmail', '2'],
            'raw ? & = in the password' => ['mysql://galbridge:a?b=c&d@127.0.0.1/vmail', '2'],
            'no password' => ['mysql://galbridge@127.0.0.1/vmail', '2'],
            'empty password' => ['mysql://galbridge:@127.0.0.1/vmail', '2'],
            'IPv6 with a port and a parameter' => ["mysql://{$u}@[::1]:3306/vmail?charset=utf8mb4", '2'],
            'a MySQL socket and an explicit timeout' => ["mysql://{$u}@unix(/run/mysqld/mysqld.sock)/vmail?timeout=7", '7'],
            'a PostgreSQL socket directory with a port' => ["pgsql://{$u}@unix(/var/run/postgresql:5433)/vmail?application_name=gal", '2'],
            'a timeout before other parameters' => ["mysql://{$u}@127.0.0.1/vmail?timeout=5&charset=utf8mb4", '5'],
            'remote MySQL with TLS, an encoded + in a path' => ["mysql://{$u}@db.example.net/vmail?ca=/etc/ssl/a%2Bb.pem&verify_server_cert=1&cipher=ECDHE-RSA-AES256-GCM-SHA384", '2'],
            'upper-case scheme, remote PostgreSQL with TLS' => ["PGSQL://{$u}@db.example.net:5432/galbridge_vmail_test?sslmode=verify-full&sslrootcert=/etc/ssl/ca.pem", '2'],
        ];
    }

    #[DataProvider('acceptedDsns')]
    public function testTheEffectiveDsnIsRebuiltFromTheJudgedParts(string $dsn, string $timeout): void
    {
        $accepted = new Dsn($dsn, self::config());
        $effective = $accepted->effective();
        $judged = \rcube_db::parse_dsn($dsn);
        $judged['timeout'] ??= $timeout;
        $reread = \rcube_db::parse_dsn($effective);
        ksort($judged);
        ksort($reread);
        self::assertSame($judged, $reread, 'the driver receives exactly the judged parts and the timeout');
        self::assertSame(1, substr_count($effective, 'timeout='));
        self::assertMatchesRegularExpression('/[?&]timeout=' . $timeout . '$/D', $effective, 'the timeout is the last parameter');
        self::assertSame((int) $timeout, $accepted->connectTimeout());
        // the canonical shape: encoded credentials, one target form, the database, the parameters
        self::assertMatchesRegularExpression(
            '#^[A-Za-z]+://[A-Za-z0-9%._~-]+(?::[A-Za-z0-9%._~-]*)?@(?:unix\(/[A-Za-z0-9_./+-]+(?::\d+)?\)|\[[0-9A-Fa-f:]+\](?::\d+)?|[A-Za-z0-9._-]+(?::\d+)?)/[A-Za-z0-9_%-]+\?(?:[a-z_]+=[A-Za-z0-9%._~-]*&)*timeout=\d+$#D',
            $effective,
        );
    }

    /**
     * An error the driver code raises while it connects - e.g. the PDO MySQL constant that the
     * symfony polyfill lacks for a pdo_mysql without mysqlnd on PHP < 8.4 - escaped Connection in
     * 1.0.1: not memoised, every request tried again, diag ended with "unexpected error". Now it is
     * an outage like any other.
     */
    public function testDriverErrorsWhileConnectingFailClosed(): void
    {
        $dsn = Env::sqliteDsn();
        $created = 0;
        $factory = static function (string $effective) use (&$created): \rcube_db {
            $created++;
            return new class ($effective) extends \rcube_db_sqlite {
                public function __construct(string $dsn)
                {
                    parent::__construct($dsn, '', false);
                }

                public function db_connect($mode, $force = false)
                {
                    throw new \Error('Undefined constant Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT');
                }
            };
        };
        $connection = new Connection(new Dsn($dsn, Env::config($dsn)), 3, $factory);
        try {
            $connection->select('SELECT 1');
            self::fail('connected');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
        self::assertArrayHasKey('galbridge_down_until', $_SESSION, 'memoised for the session');
        self::assertStringContainsString('Error: Undefined constant', $connection->lastError(), 'diag shows the reason on its connect line');
        self::assertMatchesRegularExpression('/db_down.*cause=driver_error/', self::log());
        try {
            $connection->select('SELECT 1');
            self::fail('tried again in the same request');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
        self::assertSame(1, $created, 'the driver was not created again');
    }

    /**
     * rcube_db::factory() takes the driver from the DSN text and ends the PHP process (not an
     * exception) on a driver it does not know. Production code creates the judged driver class
     * itself.
     */
    public function testProductionCodeNeverUsesTheCoreFactory(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/galbridge.php', $root . '/bin/galbridge.sh'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            $tokens = array_values(array_filter(
                \PhpToken::tokenize((string) file_get_contents($file)),
                static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
            ));
            foreach ($tokens as $i => $token) {
                if (strtolower(ltrim($token->text, '\\')) === 'rcube_db' && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON) && strtolower((string) ($tokens[$i + 2] ?? null)?->text) === 'factory') {
                    self::fail(substr($file, strlen($root) + 1) . ':' . $token->line . ' calls rcube_db::factory()');
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * The driver classes the plugin creates itself are the real ones: a refused port gives the
     * driver's own message (MySQL [2002], libpq "Connection refused").
     */
    public function testTheJudgedDriverClassIsUsed(): void
    {
        foreach (['mysql' => '/\[2002\]|refused/i', 'pgsql' => '/refused|could not connect/i'] as $driver => $reason) {
            if (!extension_loaded('pdo_' . $driver)) {
                continue;
            }
            $_SESSION = [];
            $dsn = $driver . '://galbridge:pw@127.0.0.1:1/vmail';
            $connection = new Connection(new Dsn($dsn, Env::config($dsn, ['galbridge_db_connect_timeout' => 1])), 1);
            try {
                $connection->select('SELECT 1');
                self::fail($driver . ': connected to port 1');
            } catch (GalBridgeException $e) {
                self::assertSame('db_down', $e->getReason());
            }
            self::assertMatchesRegularExpression($reason, $connection->lastError(), $driver);
            self::assertStringNotContainsString(':pw@', $connection->lastError());
        }
        $this->addToAssertionCount(1);
    }

    /**
     * diag checks the directory of the socket the driver uses (also MySQL "localhost"): writable by
     * every local account, another account could create the socket while the server is down and
     * receive the role's password.
     */
    public function testDiagChecksTheSocketDirectory(): void
    {
        $line = static function (string $dsn): string {
            $out = fopen('php://memory', 'w+');
            self::assertIsResource($out);
            $tool = new Tool(\rcube::get_instance(), CliRun::pluginDir(), $out);
            (new \ReflectionMethod(Tool::class, 'socketDirectory'))->invoke($tool, new Dsn($dsn, Env::config($dsn)));
            rewind($out);
            $text = (string) stream_get_contents($out);
            fclose($out);
            return $text;
        };
        $tmp = $line('mysql://galbridge:pw@unix(/tmp/galbridge-r2.sock)/vmail');
        if (((int) (@stat('/tmp')['mode'] ?? 0) & 0o002) !== 0) {
            CliRun::assertLine($tmp, 'WARN', 'socket directory', '/tmp is writable by every local account');
        }
        CliRun::assertLine($line('pgsql://galbridge:pw@unix(/usr)/vmail'), 'OK', 'socket directory', '/usr (not writable by other accounts)');
        CliRun::assertLine($line('mysql://galbridge:pw@unix(/nonexistent-galbridge-r2/mysqld.sock)/vmail'), 'WARN', 'socket directory', 'not checked');
        self::assertSame('', $line('mysql://galbridge:pw@127.0.0.1/vmail'), 'TCP: no line');
    }

    /**
     * galbridge.sh grants prints only DSNs the policy accepts: an IPv6 address with an embedded IPv4
     * part ([::ffff:10.0.0.5]) is a usage error there (Roundcube's parser cannot read it).
     */
    public function testTheGrantToolPrintsOnlyDsnsThePolicyAccepts(): void
    {
        $run = static function (array $args): array {
            $out = fopen('php://memory', 'w+');
            self::assertIsResource($out);
            $code = (new Tool(null, CliRun::pluginDir(), $out))->run($args);
            rewind($out);
            $text = (string) stream_get_contents($out);
            fclose($out);
            return [$code, $text];
        };
        [$code, $text] = $run(['grants', 'driver' => 'pgsql', 'host' => '[::ffff:10.0.0.5]']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('Usage:', $text);
        foreach (['mysql' => ['localhost', '127.0.0.1', '[::1]', 'db.example.net', '[2001:db8::5]'], 'pgsql' => ['127.0.0.1', '[::1]', 'db.example.net']] as $driver => $hosts) {
            foreach ($hosts as $host) {
                [$code, $text] = $run(['grants', 'driver' => $driver, 'host' => $host]);
                self::assertSame(0, $code, $text);
                self::assertSame(1, preg_match('~^-- \$config\[\'galbridge_db_dsn\'\] = \'([^\']+)\';~m', $text, $m), $driver . ' ' . $host);
                // a remote host additionally needs the TLS parameters the trailer names
                $config = self::config(['galbridge_db_allow_plaintext' => true]);
                self::assertSame($driver, (new Dsn($m[1], $config))->driver(), $driver . ' ' . $host);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function config(array $options = []): Config
    {
        return Env::config(Env::sqliteDsn(), $options);
    }

    private static function assertRefused(string $dsn, Config $config, string $reason): void
    {
        try {
            new Dsn($dsn, $config);
            self::fail('accepted: ' . str_replace(self::PASSWORD, '***', $dsn));
        } catch (GalBridgeException $e) {
            self::assertSame('config_invalid', $e->getReason());
            self::assertStringContainsString($reason, $e->getMessage(), str_replace(self::PASSWORD, '***', $dsn));
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    private static function log(): string
    {
        return implode("\n", $GLOBALS['galbridge_test_log'] ?? []);
    }
}
