<?php

declare(strict_types=1);

/**
 * GAL Bridge - integration test environment.
 *
 * Builds the production wiring (Config -> ViewerResolver -> Factory -> AddressBook) against the
 * TEST-ONLY vmail fixture on every available engine:
 *  - sqlite: always (a mirror of the iRedMail schema, loaded from tests/fixtures/vmail),
 *  - mysql / pgsql: when GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN are set (the role there
 *    is created from the plugin's own SQL/grants.*.sql templates).
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Support;

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;

final class Env
{
    public const IMAP_HOST = '127.0.0.1';

    /** the port of imap_host below: part of the derived IMAP binding */
    public const IMAP_PORT = 143;

    private static ?string $sqlite = null;

    /**
     * @return array<string, array{0: string}> engine => [dsn] (PHPUnit data provider format)
     */
    public static function engines(): array
    {
        $engines = ['sqlite' => [self::sqliteDsn()]];
        foreach (['mysql' => 'GALBRIDGE_TEST_MYSQL_DSN', 'pgsql' => 'GALBRIDGE_TEST_PGSQL_DSN'] as $name => $env) {
            $dsn = getenv($env);
            if (is_string($dsn) && $dsn !== '') {
                $engines[$name] = [$dsn];
            }
        }
        return $engines;
    }

    public static function sqliteDsn(): string
    {
        if (self::$sqlite === null) {
            // a private directory with an unpredictable name: mkdir() fails when the name exists, so
            // nobody else on the machine can plant a file or a link where the fixture is written
            $dir = sys_get_temp_dir() . '/galbridge-test-' . bin2hex(random_bytes(12));
            if (!mkdir($dir, 0o700)) {
                throw new \RuntimeException('cannot create a private directory for the SQLite fixture');
            }
            $file = $dir . '/vmail.sqlite';
            // loaded BEFORE rcube_db connects: the driver initialises an EMPTY file with Roundcube's schema
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $fixtures = dirname(__DIR__) . '/fixtures/vmail/';
            $pdo->exec((string) file_get_contents($fixtures . 'schema.sqlite.sql'));
            $pdo->exec((string) file_get_contents($fixtures . 'seed.sql'));
            $pdo = null;
            // the mirror, the files SQLite keeps next to it, and the private directory
            register_shutdown_function(static function () use ($file, $dir): void {
                foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                    @unlink($file . $suffix);
                }
                @rmdir($dir);
            });
            self::$sqlite = 'sqlite:///' . $file . '?mode=0600';
        }
        return self::$sqlite;
    }

    /**
     * @param array<string, mixed> $options plugin options (galbridge_*) and core options
     */
    public static function config(string $dsn, array $options = []): Config
    {
        \rcube::get_instance(); // rcube_db reads the core config through the singleton
        $values = ['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://' . self::IMAP_HOST . ':' . self::IMAP_PORT] + $options;
        $config = new \rcube_config();
        foreach ($values as $k => $v) {
            $config->set($k, $v);
        }
        return new Config($config, [], $values);
    }

    /**
     * @param list<array{string, array<string, string>}> $notices collects user-visible warnings
     */
    public static function factory(Config $config, string $login, string $action = '', ?array &$notices = null, int $userId = 1, string $host = self::IMAP_HOST, int $port = self::IMAP_PORT): Factory
    {
        $_SESSION = [];
        $notices = [];
        $connection = null;
        $connect = static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, $userId, $login, $host, $host, $port);
        $notify = static function (string $label, array $vars) use (&$notices): void {
            $notices[] = [$label, $vars];
        };
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l, $action, $notify);
    }
}
