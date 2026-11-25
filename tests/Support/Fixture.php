<?php

declare(strict_types=1);

/**
 * GAL Bridge - changes to the TEST-ONLY vmail fixture for a single test.
 *
 * Some behaviour can only be shown with rows the seed does not have (a mailbox that is disabled
 * while a session is open, more entries than a limit, an alias row of another domain). This helper
 * runs such statements as the administrator of the throw-away fixture database and remembers the
 * statements that undo them:
 *  - sqlite: the mirror file of Env::sqliteDsn(), through its own PDO handle (the plugin's
 *    connection is read-only);
 *  - mysql / pgsql: the administrator access of tests/fixtures/vmail/load.sh (MYSQL_ADMIN /
 *    PSQL_ADMIN or their defaults). Without it the calling test is skipped.
 *
 * Only a local database whose name contains "test" is ever touched.
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Support;

use GalBridge\Db\Dsn;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedTest;

final class Fixture
{
    /** @var array<string, null|list<string>> engine => working admin command (null = unavailable) */
    private static array $admin = [];

    /** @var list<array{0: string, 1: list<string>}> [dsn, statements] to run in restore(), last first */
    private static array $undo = [];

    /**
     * Run $apply now and $revert in restore() (call restore() in tearDown).
     *
     * @param list<string> $apply  portable SQL statements (no trailing semicolon)
     * @param list<string> $revert statements that undo them
     */
    public static function alter(string $dsn, array $apply, array $revert): void
    {
        try {
            self::run($dsn, $apply);
        } catch (SkippedTest $skipped) {
            // Nothing was changed, so there is nothing to undo: restore() must not run into the same
            // skip again - thrown from tearDown() it is a FAILURE for PHPUnit 10 and 11.
            throw $skipped;
        } catch (\Throwable $failed) {
            // possibly applied in part
            self::$undo[] = [$dsn, $revert];
            throw $failed;
        }
        self::$undo[] = [$dsn, $revert];
    }

    /**
     * Undo every alter() of the test, in reverse order.
     */
    public static function restore(): void
    {
        while (($entry = array_pop(self::$undo)) !== null) {
            try {
                self::run($entry[0], $entry[1]);
            } catch (SkippedTest) {
                // the administrator access went away in the meantime: nothing this method can undo
            }
        }
    }

    /**
     * N throw-away mailboxes bulkNNN@$domain (three digits, four from 1000 on; deliverable) and the
     * statements that remove them.
     *
     * @return array{0: list<string>, 1: list<string>} [apply, revert]
     */
    public static function bulkMailboxes(int $count, string $domain = 'example.com', string $department = 'Bulk', string $prefix = 'bulk'): array
    {
        // numbered with at least three digits - more for 1000 and up, so that the pattern of the
        // revert statement matches every row it added
        $width = max(3, strlen((string) $count));
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $n = str_pad((string) $i, $width, '0', STR_PAD_LEFT);
            $rows[] = sprintf("('%s%s@%s', 'Bulk User %s', '%s', '%s', '%s/b/u/l/%s%s')", $prefix, $n, $domain, $n, $domain, $department, $domain, $prefix, $n);
        }
        return [
            ['INSERT INTO mailbox (username, name, domain, department, maildir) VALUES ' . implode(', ', $rows)],
            ["DELETE FROM mailbox WHERE username LIKE '{$prefix}" . str_repeat('_', $width) . "@{$domain}'"],
        ];
    }

    /**
     * @param list<string> $statements
     */
    private static function run(string $dsn, array $statements): void
    {
        if ($statements === []) {
            return;
        }
        if (str_starts_with($dsn, 'sqlite:')) {
            $file = (string) preg_replace('~^sqlite:///(.*?)(\?.*)?$~', '$1', $dsn);
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $pdo = null;
            return;
        }
        if ($dsn === '') {
            // the engine of the test is not configured in this run (e.g. a MySQL-only run)
            Assert::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        $engine = (string) ($parsed['phptype'] ?? '') === 'pgsql' ? 'pgsql' : 'mysql';
        $db = (string) ($parsed['database'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*test[a-z0-9_]*$/D', $db) || !Dsn::isLoopback($parsed)) {
            Assert::markTestSkipped('refusing to change anything but a local *test* database: ' . $db);
        }
        $cmd = self::admin($engine);
        if ($cmd === null) {
            Assert::markTestSkipped("no administrator access to the {$engine} test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)");
        }
        $sql = implode('; ', $statements) . ';';
        $cmd = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-d', $db, '-c', $sql] : [...$cmd, $db, '-e', $sql];
        [$code, , $err] = self::exec($cmd);
        Assert::assertSame(0, $code, 'changing the fixture failed: ' . $err);
    }

    /**
     * @return null|list<string>
     */
    private static function admin(string $engine): ?array
    {
        if (array_key_exists($engine, self::$admin)) {
            return self::$admin[$engine];
        }
        $env = getenv($engine === 'pgsql' ? 'PSQL_ADMIN' : 'MYSQL_ADMIN');
        $candidates = is_string($env) && trim($env) !== '' ? [$env]
            : ($engine === 'pgsql' ? ['psql -U postgres -h 127.0.0.1 -w', 'runuser -u postgres -- psql'] : ['mariadb -u root', 'mysql -u root']);
        self::$admin[$engine] = null;
        foreach ($candidates as $candidate) {
            $cmd = preg_split('/\s+/', trim($candidate)) ?: [];
            $probe = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-t', '-d', 'postgres', '-c', 'SELECT 1'] : [...$cmd, '-N', '-e', 'SELECT 1'];
            [$code, $out] = self::exec($probe);
            if ($code === 0 && trim($out) === '1') {
                return self::$admin[$engine] = $cmd;
            }
        }
        return null;
    }

    /**
     * Run a command without a shell; stdin is closed, so nothing can prompt.
     *
     * @param list<string> $cmd
     *
     * @return array{0: int, 1: string, 2: string}
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
