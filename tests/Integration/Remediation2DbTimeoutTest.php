<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\FakeMysqlServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-14: the connect timeout (PDO::ATTR_TIMEOUT) bounds the TCP connection
 * only. With mysqlnd the server's greeting and every later answer wait for mysqlnd.net_read_timeout
 * (86400 s by default), so a server or proxy that accepted the connection and then stays silent
 * held the PHP worker for a day. The plugin now gives its MariaDB/MySQL connection a read timeout
 * (the longer of galbridge_db_timeout and galbridge_db_connect_timeout, plus 2 s), never raises a
 * lower php.ini value, and treats a connection lost during a statement like an outage (memoised for
 * the session).
 *
 * The servers here are loopback peers of the test itself (no database is needed): a listener that
 * never answers, and tests/Support/FakeMysqlServer, which completes the handshake and then stops
 * answering one statement. The tests that fail on 1.0.1 take about 20 s there (the safety net of
 * setUp()) instead of the expected 3 s.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DbTimeoutTest extends TestCase
{
    private const SAFETY_NET = '20';

    private string|false $readTimeout = false;

    private ?FakeMysqlServer $fake = null;

    /** @var list<string> PHP warnings / notices of the client library (expected while it fails) */
    private array $messages = [];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
        $this->readTimeout = ini_get('mysqlnd.net_read_timeout');
        if ($this->readTimeout !== false) {
            // a test that fails waits this long, not a day
            ini_set('mysqlnd.net_read_timeout', self::SAFETY_NET);
        }
        $this->messages = [];
        set_error_handler(function (int $type, string $message): bool {
            $this->messages[] = $message;
            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $this->fake?->stop();
        $this->fake = null;
        if ($this->readTimeout !== false) {
            ini_set('mysqlnd.net_read_timeout', $this->readTimeout);
        }
        $_SESSION = [];
    }

    public function testASilentServerFailsWithinTheReadTimeout(): void
    {
        self::needMysqlnd();
        // the kernel completes the TCP handshake (backlog) - nobody ever sends the greeting
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        try {
            $connection = self::connection(self::port($server), 1, 1);
            self::assertSame(3, $connection->readTimeout(), 'max(1, 1) + 2');
            $started = microtime(true);
            try {
                $connection->select('SELECT 1');
                self::fail('connected to a server that never answered');
            } catch (GalBridgeException $e) {
                self::assertSame('db_down', $e->getReason());
            }
            $elapsed = microtime(true) - $started;
            self::assertLessThan(6.0, $elapsed, 'bounded by the read timeout of 3 s, not by mysqlnd.net_read_timeout (' . self::SAFETY_NET . ' s here, 86400 s by default)');
            self::assertArrayHasKey('galbridge_down_until', $_SESSION, 'memoised like any failed connect');
            self::assertStringContainsString('db_down', self::log());
            self::assertSame(self::SAFETY_NET, ini_get('mysqlnd.net_read_timeout'), 'the php.ini value is restored after connecting');
        } finally {
            fclose($server);
        }
    }

    public function testAServerThatStopsAnsweringAfterTheHandshakeFailsWithinTheReadTimeout(): void
    {
        self::needMysqlnd();
        $this->fake = FakeMysqlServer::start();
        $connection = self::connection($this->fake->port(), 1, 1);
        self::assertSame('mysql', $connection->flavour(), 'connected (the fake server answers the session set-up)');
        self::assertSame(self::SAFETY_NET, ini_get('mysqlnd.net_read_timeout'), 'restored right after connecting');

        $started = microtime(true);
        try {
            $connection->select("SELECT 1 /* '" . FakeMysqlServer::MARKER . "' */");
            self::fail('a statement that was never answered returned');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason(), 'a lost connection is an outage, not a query error');
        }
        $elapsed = microtime(true) - $started;
        // mysqlnd copied the read timeout into the connection when it was created: restoring the
        // php.ini value afterwards does not weaken it
        self::assertLessThan(6.0, $elapsed, 'bounded by the read timeout of the connection (3 s)');
        self::assertArrayHasKey('galbridge_down_until', $_SESSION);
        self::assertMatchesRegularExpression('/db_down.*cause=lost/', self::log());

        // the same request does not try again
        $started = microtime(true);
        try {
            $connection->select('SELECT 1');
            self::fail('retried after the connection was lost');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testTheReadTimeoutIsNeverRaised(): void
    {
        self::needMysqlnd();
        // the administrator's php.ini value (2 s) is lower than the plugin's (10 + 2 s): kept
        ini_set('mysqlnd.net_read_timeout', '2');
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        try {
            $connection = self::connection(self::port($server), 1, 10);
            self::assertSame(12, $connection->readTimeout());
            $started = microtime(true);
            try {
                $connection->select('SELECT 1');
                self::fail('connected to a server that never answered');
            } catch (GalBridgeException $e) {
                self::assertSame('db_down', $e->getReason());
            }
            self::assertLessThan(8.0, microtime(true) - $started, 'the lower php.ini value (2 s) applied, not the plugin\'s 12 s');
            self::assertSame('2', ini_get('mysqlnd.net_read_timeout'));
            self::assertStringNotContainsString('read_timeout', self::log());
        } finally {
            fclose($server);
        }
    }

    /**
     * The read timeout follows both options; the server-side statement limit always ends first. Other
     * drivers have none (libpq has no read timeout after connecting).
     */
    public function testTheReadTimeoutFollowsBothTimeouts(): void
    {
        $dsn = 'mysql://galbridge:pw@127.0.0.1:1/vmail';
        self::assertSame(5, (new Connection(new Dsn($dsn, Env::config($dsn)), 3))->readTimeout(), 'defaults: max(3, 2) + 2');
        self::assertSame(32, (new Connection(new Dsn($dsn, Env::config($dsn, ['galbridge_db_connect_timeout' => 1])), 30))->readTimeout());
        self::assertSame(32, (new Connection(new Dsn($dsn, Env::config($dsn, ['galbridge_db_connect_timeout' => 30])), 1))->readTimeout());
        self::assertSame(9, (new Connection(new Dsn('mysql://galbridge:pw@127.0.0.1:1/vmail?timeout=7', Env::config($dsn)), 3))->readTimeout(), 'an explicit DSN timeout counts');
        $pg = 'pgsql://galbridge:pw@127.0.0.1:1/vmail';
        self::assertSame(0, (new Connection(new Dsn($pg, Env::config($pg)), 3))->readTimeout());
        self::assertSame(0, (new Connection(new Dsn(Env::sqliteDsn(), Env::config(Env::sqliteDsn())), 3))->readTimeout());
        self::assertSame(2, (new Dsn($dsn, Env::config($dsn)))->connectTimeout());
    }

    /**
     * A statement that fails because the connection is gone (read timeout, server restart, a proxy
     * that dropped it) is treated like a failed connect: memoised for the session. Any other failed
     * statement - the server's own statement timeout included - stays a query error of the request.
     *
     * @return array<string, array{array{string, int, string}, bool}>
     */
    public static function failedStatements(): array
    {
        return [
            'MySQL: server has gone away (read timeout)' => [['HY000', 2006, 'MySQL server has gone away'], true],
            'MySQL: lost connection during query' => [['HY000', 2013, 'Lost connection to MySQL server during query'], true],
            'MySQL 8: disconnected for inactivity' => [['HY000', 4031, 'The client was disconnected by the server because of inactivity.'], true],
            'SQLSTATE class 08' => [['08006', 7, 'no connection to the server'], true],
            'pdo_pgsql: libpq lost the connection' => [['HY000', 7, "server closed the connection unexpectedly\n\tThis probably means the server terminated abnormally"], true],
            'PostgreSQL: administrator shutdown' => [['57P01', 7, 'FATAL:  terminating connection due to administrator command'], true],
            'PostgreSQL: statement timeout' => [['57014', 7, 'ERROR:  canceling statement due to statement timeout'], false],
            'MariaDB: statement timeout' => [['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'], false],
            'a privilege error' => [['42501', 7, 'permission denied for table mailbox'], false],
        ];
    }

    /**
     * @param array{string, int, string} $info
     */
    #[DataProvider('failedStatements')]
    public function testALostConnectionIsMemoisedLikeAnOutage(array $info, bool $lost): void
    {
        $dsn = Env::sqliteDsn();
        $factory = static fn (string $effective): \rcube_db => new class ($effective, $info) extends \rcube_db_sqlite {
            private bool $failed = false;

            /**
             * @param array{string, int, string} $info
             */
            public function __construct(string $dsn, private readonly array $info)
            {
                parent::__construct($dsn, '', false);
            }

            public function query($query, ...$params)
            {
                if (str_contains((string) $query, 'galbridge_marker')) {
                    $this->failed = true;
                    $this->db_error = true;
                    $this->db_error_msg = $this->info[2];
                    return false;
                }
                return parent::query($query, ...$params);
            }

            public function error_info()
            {
                return $this->failed ? $this->info : parent::error_info();
            }
        };
        $connection = new Connection(new Dsn($dsn, Env::config($dsn)), 3, $factory);
        self::assertSame([['n' => '1']], $connection->select('SELECT 1 AS n'));
        try {
            $connection->select('SELECT 1 AS galbridge_marker');
            self::fail('the failed statement returned rows');
        } catch (GalBridgeException $e) {
            self::assertSame($lost ? 'db_down' : 'db_error', $e->getReason());
        }
        self::assertSame($lost, isset($_SESSION['galbridge_down_until']));
        if ($lost) {
            self::assertMatchesRegularExpression('/db_down.*cause=lost/', self::log());
            try {
                $connection->select('SELECT 1 AS n');
                self::fail('the lost connection was used again');
            } catch (GalBridgeException $e) {
                self::assertSame('db_down', $e->getReason());
            }
        } else {
            self::assertStringContainsString('db_error', self::log());
            self::assertSame([['n' => '1']], $connection->select('SELECT 1 AS n'), 'the connection stays usable');
        }
    }

    /**
     * diag shows the read timeout the plugin's connection gets (MariaDB/MySQL), and that PostgreSQL
     * has none after connecting.
     */
    public function testDiagShowsTheReadTimeout(): void
    {
        $mysql = 'mysql://galbridge:pw@127.0.0.1:1/vmail';
        $text = self::readTimeoutLine($mysql, 3);
        if (extension_loaded('mysqlnd')) {
            CliRun::assertLine($text, 'OK', 'read timeout', '5 s for the plugin\'s MariaDB/MySQL connection');
            CliRun::assertLine($text, 'OK', 'read timeout', 'mysqlnd.net_read_timeout of this PHP: ' . self::SAFETY_NET . ' s');
            ini_set('mysqlnd.net_read_timeout', '4');
            CliRun::assertLine(self::readTimeoutLine($mysql, 3), 'OK', 'read timeout', '4 s (mysqlnd.net_read_timeout of this PHP is not above the plugin\'s 5 s and is kept)');
        } else {
            CliRun::assertLine($text, 'WARN', 'read timeout', 'pdo_mysql is not built on mysqlnd');
        }
        $pg = 'pgsql://galbridge:pw@127.0.0.1:1/vmail';
        CliRun::assertLine(self::readTimeoutLine($pg, 3), 'INFO', 'read timeout', 'none after connecting (libpq has none)');
    }

    private static function readTimeoutLine(string $dsn, int $statementTimeout): string
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $tool = new Tool(\rcube::get_instance(), CliRun::pluginDir(), $out);
        $parsed = new Dsn($dsn, Env::config($dsn));
        (new \ReflectionMethod(Tool::class, 'readTimeout'))->invoke($tool, $parsed, new Connection($parsed, $statementTimeout));
        rewind($out);
        $text = (string) stream_get_contents($out);
        fclose($out);
        return $text;
    }

    private static function connection(int $port, int $connectTimeout, int $statementTimeout): Connection
    {
        $dsn = 'mysql://galbridge:pw@127.0.0.1:' . $port . '/vmail';
        $config = Env::config($dsn, ['galbridge_db_connect_timeout' => $connectTimeout, 'galbridge_db_timeout' => $statementTimeout]);
        return new Connection(new Dsn($dsn, $config), $statementTimeout);
    }

    /**
     * @param resource $server
     */
    private static function port($server): int
    {
        $name = (string) stream_socket_get_name($server, false);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function needMysqlnd(): void
    {
        if (!extension_loaded('pdo_mysql') || !extension_loaded('mysqlnd')) {
            self::markTestSkipped('needs pdo_mysql built on mysqlnd');
        }
    }

    private static function log(): string
    {
        return implode("\n", $GLOBALS['galbridge_test_log'] ?? []);
    }
}
