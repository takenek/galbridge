<?php

declare(strict_types=1);

/**
 * GAL Bridge - a MySQL-protocol peer on the loopback interface that stops answering.
 *
 * For the read timeout of the plugin's MariaDB/MySQL connection (Connection::readTimeout()): a child
 * process listens on 127.0.0.1 (a free port), sends a protocol-10 greeting, accepts any handshake
 * response with an OK packet and answers every statement with an empty result - rcube_db_mysql
 * switches emulated prepares off, so a statement arrives as COM_STMT_PREPARE (answered with a
 * statement of no columns and no parameters) and COM_STMT_EXECUTE (answered with OK) - except a
 * statement that contains the marker: that one is never answered (the connection stays open, as
 * with a frozen server or a proxy whose backend died). No database, no credentials, no data; the
 * child ends when stop() is called or after DEADLINE seconds.
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Support;

final class FakeMysqlServer
{
    /** a statement containing this text is never answered */
    public const MARKER = 'GALBRIDGE_FAKE_HANG';

    private const DEADLINE = 40;

    /**
     * @param resource $process
     */
    private function __construct(private $process, private readonly string $dir, private readonly int $port)
    {
    }

    public static function start(): self
    {
        // a private directory with an unpredictable name (as Env::sqliteDsn()): the port file
        $dir = sys_get_temp_dir() . '/galbridge-fakemysql-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('cannot create a private directory for the fake server');
        }
        $portFile = $dir . '/port';
        $code = 'require ' . var_export(__FILE__, true) . '; ' . self::class . '::serve($argv[1]);';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, $portFile], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            rmdir($dir);
            throw new \RuntimeException('cannot start the fake server');
        }
        fclose($pipes[0]);
        $port = 0;
        for ($i = 0; $i < 100 && $port === 0; $i++) {
            usleep(50000);
            clearstatcache();
            $port = is_file($portFile) ? (int) file_get_contents($portFile) : 0;
        }
        $server = new self($process, $dir, $port);
        if ($port === 0) {
            $server->stop();
            throw new \RuntimeException('the fake server did not start');
        }
        return $server;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        @unlink($this->dir . '/port');
        @unlink($this->dir . '/port.tmp');
        @rmdir($this->dir);
    }

    /**
     * The child process (started by start()).
     */
    public static function serve(string $portFile): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            exit(1);
        }
        $name = (string) stream_socket_get_name($server, false);
        file_put_contents($portFile . '.tmp', substr($name, (int) strrpos($name, ':') + 1));
        rename($portFile . '.tmp', $portFile);
        $open = [];
        $until = time() + self::DEADLINE;
        // CLIENT_LONG_PASSWORD | CONNECT_WITH_DB | PROTOCOL_41 | TRANSACTIONS | SECURE_CONNECTION | PLUGIN_AUTH
        $caps = 0x1 | 0x8 | 0x200 | 0x2000 | 0x8000 | 0x80000;
        $ok = "\x00\x00\x00\x02\x00\x00\x00";
        while (time() < $until) {
            $client = @stream_socket_accept($server, 1);
            if ($client === false) {
                continue;
            }
            $open[] = $client;
            $greeting = "\x0a" . "8.0.99-galbridge-fake\0" . pack('V', 1) . 'abcdefgh' . "\0" . pack('v', $caps & 0xffff) . "\x21" . pack('v', 2)
                . pack('v', $caps >> 16) . chr(21) . str_repeat("\0", 10) . 'ijklmnopqrst' . "\0" . "mysql_native_password\0";
            fwrite($client, self::packet(0, $greeting));
            $response = self::read($client);
            if ($response === null) {
                continue;
            }
            fwrite($client, self::packet($response[0] + 1, $ok));
            $statement = 0;
            while (($command = self::read($client)) !== null) {
                [$sequence, $payload] = $command;
                if (str_contains($payload, self::MARKER) || $payload === "\x01") {
                    break; // never answered (or COM_QUIT)
                }
                $type = $payload[0] ?? '';
                if ($type === "\x19") {
                    continue; // COM_STMT_CLOSE has no answer
                }
                // COM_STMT_PREPARE: statement id, 0 columns, 0 parameters; anything else: OK
                fwrite($client, self::packet($sequence + 1, $type === "\x16" ? "\x00" . pack('V', ++$statement) . pack('v', 0) . pack('v', 0) . "\x00" . pack('v', 0) : $ok));
            }
        }
        unset($open);
    }

    private static function packet(int $sequence, string $payload): string
    {
        $length = strlen($payload);
        return chr($length & 255) . chr(($length >> 8) & 255) . chr(($length >> 16) & 255) . chr($sequence & 255) . $payload;
    }

    /**
     * @param resource $client
     *
     * @return null|array{0: int, 1: string} [sequence, payload]
     */
    private static function read($client): ?array
    {
        $header = fread($client, 4);
        if (!is_string($header) || strlen($header) < 4) {
            return null;
        }
        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $payload = '';
        while (strlen($payload) < $length) {
            $chunk = fread($client, $length - strlen($payload));
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }
            $payload .= $chunk;
        }
        return [ord($header[3]), $payload];
    }
}
