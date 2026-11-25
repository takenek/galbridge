<?php

declare(strict_types=1);

/**
 * GAL Bridge - administrator access to the TEST-ONLY vmail databases of the suite.
 *
 * For the tests of the database role and the grant set (second remediation pass): the
 * administrator command of tests/fixtures/vmail/load.sh (MYSQL_ADMIN / PSQL_ADMIN or their
 * defaults), throw-away roles with column grants and extra privileges, and the restoration of the
 * fixture's view galbridge_forwardings - a PostgreSQL grant set drops and re-creates that view,
 * which takes its grant away from the fixture role. Only a local database whose name contains
 * "test" is ever touched; without administrator access the calling test is skipped.
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Support;

use GalBridge\Db\Dsn;
use GalBridge\Directory\Schema;
use PHPUnit\Framework\Assert;

final class DbAdmin
{
    /** the column grants of the grant templates (SQL/grants.*.sql, the personal-data grant included) */
    public const TEMPLATE_COLUMNS = Schema::GRANTABLE;

    /** @var array<string, null|list<string>> */
    private static array $command = [];

    /**
     * Engine, database and host of a fixture DSN; a skip for anything but a local *test* database.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function target(string $dsn): array
    {
        if ($dsn === '') {
            Assert::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        if (!defined('GALBRIDGE_RC_LOADED') || !GALBRIDGE_RC_LOADED) {
            Assert::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        $engine = (string) $parsed['phptype'] === 'pgsql' ? 'pgsql' : 'mysql';
        $db = (string) ($parsed['database'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*test[a-z0-9_]*$/D', $db) || !Dsn::isLoopback($parsed)) {
            Assert::markTestSkipped('refusing to change anything outside a local *test* database: ' . $db);
        }
        return [$engine, $db, (string) ($parsed['hostspec'] ?? 'localhost')];
    }

    /**
     * The working administrator command of load.sh, or null.
     *
     * @return null|list<string>
     */
    public static function command(string $engine): ?array
    {
        if (array_key_exists($engine, self::$command)) {
            return self::$command[$engine];
        }
        $env = getenv($engine === 'pgsql' ? 'PSQL_ADMIN' : 'MYSQL_ADMIN');
        $candidates = is_string($env) && trim($env) !== '' ? [$env]
            : ($engine === 'pgsql' ? ['psql -U postgres -h 127.0.0.1 -w', 'runuser -u postgres -- psql'] : ['mariadb -u root', 'mysql -u root']);
        self::$command[$engine] = null;
        foreach ($candidates as $candidate) {
            $cmd = preg_split('/\s+/', trim($candidate)) ?: [];
            $probe = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-t', '-d', 'postgres', '-c', 'SELECT 1'] : [...$cmd, '-N', '-e', 'SELECT 1'];
            [$code, $out] = CliRun::exec($probe);
            if ($code === 0 && trim($out) === '1') {
                return self::$command[$engine] = $cmd;
            }
        }
        return null;
    }

    /**
     * An SQL script on standard input as the administrator (a skip without access). MariaDB/MySQL:
     * with --binary-mode unless $binary is false (as README 2.2 tells the administrator).
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public static function script(string $engine, string $db, string $sql, bool $binary = true): array
    {
        $cmd = self::command($engine);
        if ($cmd === null) {
            Assert::markTestSkipped("no administrator access to the {$engine} test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)");
        }
        $cmd = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-d', $db] : [...$cmd, ...($binary ? ['--binary-mode'] : []), $db];
        return CliRun::exec($cmd, $sql);
    }

    /**
     * The first column of every row of an administrator query, one per line.
     */
    public static function queryAll(string $engine, string $db, string $sql): string
    {
        $cmd = self::command($engine);
        if ($cmd === null) {
            Assert::markTestSkipped("no administrator access to the {$engine} test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)");
        }
        $cmd = $engine === 'pgsql' ? [...$cmd, '-X', '-q', '-A', '-t', '-d', $db, '-c', $sql] : [...$cmd, $db, '-N', '-B', '-e', $sql];
        [$code, $out, $err] = CliRun::exec($cmd);
        Assert::assertSame(0, $code, $err);
        return trim($out);
    }

    /**
     * One value (first row, first column) of an administrator query; '' for no row.
     */
    public static function query(string $engine, string $db, string $sql): string
    {
        return trim((string) strtok(self::queryAll($engine, $db, $sql) . "\n", "\n"));
    }

    /**
     * A login role on the test database with column-level SELECT and extra statements (run after
     * the grants; "%r" stands for the role - on MariaDB/MySQL for both of its accounts). Returns
     * its DSN; drop it with dropRole() in tearDown.
     *
     * @param array<string, list<string>> $columns
     * @param list<string>                $extra
     */
    public static function createRole(string $dsn, string $role, array $columns, array $extra = []): string
    {
        [$engine, $db] = self::target($dsn);
        if (self::command($engine) === null) {
            Assert::markTestSkipped("no administrator access to the {$engine} test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)");
        }
        $password = 'p' . bin2hex(random_bytes(12));
        self::dropRole($engine, $db, $role);
        if ($engine === 'mysql') {
            $to = "'{$role}'@'localhost', '{$role}'@'%'";
            $sql = "CREATE USER '{$role}'@'localhost' IDENTIFIED BY '{$password}'; CREATE USER '{$role}'@'%' IDENTIFIED BY '{$password}';";
            foreach ($columns as $table => $cols) {
                $sql .= ' GRANT SELECT (`' . implode('`, `', $cols) . "`) ON `{$db}`.`{$table}` TO {$to};";
            }
        } else {
            $to = $role;
            $sql = "CREATE ROLE {$role} LOGIN PASSWORD '{$password}' NOINHERIT; GRANT CONNECT ON DATABASE {$db} TO {$role}; GRANT USAGE ON SCHEMA public TO {$role};";
            foreach ($columns as $table => $cols) {
                $sql .= ' GRANT SELECT (' . implode(', ', $cols) . ") ON {$table} TO {$role};";
            }
        }
        foreach ($extra as $statement) {
            $sql .= ' ' . str_replace('%r', $to, $statement) . ';';
        }
        [$code, , $err] = self::script($engine, $db, $sql, false);
        Assert::assertSame(0, $code, 'creating the test role failed: ' . $err);
        return (string) preg_replace('~^([a-z]+)://[^@]*@~i', '$1://' . $role . ':' . $password . '@', $dsn);
    }

    public static function dropRole(string $engine, string $db, string $role): void
    {
        if (self::command($engine) === null) {
            return;
        }
        if ($engine === 'mysql') {
            $hosts = array_unique(array_merge(['localhost', '%', '10.0.0.%'], array_filter(explode("\n", self::queryAll($engine, $db, "SELECT Host FROM mysql.user WHERE User = '{$role}'")))));
            $sql = 'DROP USER IF EXISTS ' . implode(', ', array_map(static fn (string $h): string => "'{$role}'@'{$h}'", $hosts)) . ';';
        } else {
            $sql = "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN EXECUTE 'DROP OWNED BY {$role}'; EXECUTE 'DROP ROLE {$role}'; END IF; END \$\$;";
        }
        self::script($engine, $db, $sql, false);
    }

    /**
     * The view galbridge_forwardings of the fixture exactly as load.sh creates it - from the
     * template - with the fixture role's grant on it. A PostgreSQL grant set applied for another
     * role drops and re-creates the view (and so takes the fixture role's grant away), and a test
     * that replaces the view puts it back with this.
     */
    public static function restoreFixtureView(string $dsn): void
    {
        if ($dsn === '') {
            return;
        }
        [$engine, $db] = self::target($dsn);
        if (self::command($engine) === null) {
            return;
        }
        $role = (string) (\rcube_db::parse_dsn($dsn)['username'] ?? '');
        $template = (string) file_get_contents(CliRun::pluginDir() . '/SQL/grants.' . ($engine === 'pgsql' ? 'postgres' : 'mysql') . '.sql');
        $statements = [];
        if ($engine === 'pgsql') {
            preg_match('/^DROP VIEW IF EXISTS .*?^REVOKE ALL ON galbridge_forwardings FROM PUBLIC;$/ms', $template, $m);
            $statements[] = $m[0] ?? '';
            $statements[] = "GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON galbridge_forwardings TO {$role};";
        } else {
            preg_match('/^CREATE OR REPLACE ALGORITHM .*?;$/ms', $template, $m);
            $statements[] = str_replace('vmail.', $db . '.', $m[0] ?? '');
            // every account load.sh created for the fixture role: 'localhost' and '127.0.0.1', and
            // '%' only where it was asked for (GALBRIDGE_LOAD_ANY_HOST=1, CI's service containers)
            $hosts = array_filter(explode("\n", self::queryAll($engine, $db, "SELECT Host FROM mysql.user WHERE User = '{$role}'")));
            Assert::assertNotEmpty($hosts, 'the fixture role has no account');
            foreach ($hosts as $host) {
                $account = "'{$role}'@'" . str_replace("'", "''", $host) . "'";
                $statements[] = "GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON {$db}.galbridge_forwardings TO {$account};";
                $statements[] = "GRANT SHOW VIEW ON {$db}.galbridge_forwardings TO {$account};";
            }
        }
        [$code, , $err] = self::script($engine, $db, implode("\n", $statements) . "\n");
        Assert::assertSame(0, $code, 'restoring the fixture view failed: ' . $err);
    }
}
