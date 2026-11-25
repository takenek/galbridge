<?php

declare(strict_types=1);

/**
 * GAL Bridge - second verification pass (assessment of 1.0.1), F-04: the role and the view are
 * checked as widely as the documentation promises.
 *
 *  - the PostgreSQL grant set drops and re-creates the view (no grant, owner or option of a view
 *    that existed before survives), stops at a table of that name, takes CREATE on schema public
 *    away from PUBLIC (the owners of tables keep it) and resets the role attributes in every form;
 *  - the plugin refuses a role that reaches more than the directory (role_too_broad): a secret
 *    column of any table, a membership, a role attribute, the ownership of the database, a global
 *    privilege, a privilege outside the directory, a privilege to change anything - and a view that
 *    holds rows of another kind; diag fails for each;
 *  - diag checks the view object, every relation for write privileges, CREATE on schema public,
 *    owned objects, and names columns GAL Bridge never reads;
 *  - (database compatibility) a MariaDB/MySQL server-wide test database open to every account -
 *    "test", "test_*" of mariadb-install-db - is no refusal: the plugin warns, diag fails.
 *
 * The PostgreSQL template cases run as the administrator in one session that is rolled back; the
 * other cases use throw-away roles (DbAdmin) and restore what they change.
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Db\RoleScope;
use GalBridge\Directory\Schema;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\DbAdmin;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-aware')]
final class Remediation2RoleScopeTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private string $base = '';

    /** @var list<array{0: string, 1: string, 2: string}> engine, database, role */
    private array $roles = [];

    /** @var list<array{0: string, 1: string, 2: string}> engine, database, statement run in tearDown */
    private array $cleanup = [];

    private string $restoreView = '';

    protected function tearDown(): void
    {
        Fixture::restore();
        foreach (array_reverse($this->cleanup) as [$engine, $db, $sql]) {
            DbAdmin::script($engine, $db, $sql, false);
        }
        $this->cleanup = [];
        foreach ($this->roles as [$engine, $db, $role]) {
            DbAdmin::dropRole($engine, $db, $role);
        }
        $this->roles = [];
        if ($this->restoreView !== '') {
            DbAdmin::restoreFixtureView($this->restoreView);
            $this->restoreView = '';
        }
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
        $_SESSION = [];
    }

    // ------------------------------------------------------------------ the PostgreSQL grant set

    /**
     * A view of that name that another role created and granted on: the grant set leaves nothing of
     * it - not the grant, not the owner - and the new view is a fenced security barrier.
     */
    public function testThePostgresGrantSetReplacesAForeignViewCompletely(): void
    {
        $checks = $this->pgSession(
            'CREATE ROLE gbt_r2_x; CREATE ROLE gbt_r2_y; GRANT SELECT ON forwardings TO gbt_r2_x; GRANT CREATE ON SCHEMA public TO gbt_r2_x;'
            . ' DROP VIEW galbridge_forwardings; SET ROLE gbt_r2_x;'
            . ' CREATE VIEW galbridge_forwardings AS SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active FROM forwardings;'
            . ' GRANT SELECT ON galbridge_forwardings TO gbt_r2_y; RESET ROLE;',
            ['grants-only' => true],
            "SELECT 'gbt:' || has_table_privilege('gbt_r2_y', 'galbridge_forwardings', 'SELECT') || ':' || pg_get_userbyid(relowner) || ':' || array_to_string(reloptions, ',') || ':' || (pg_get_viewdef(oid) LIKE '%OFFSET 0%') FROM pg_class WHERE relname = 'galbridge_forwardings';",
        );
        self::assertStringContainsString('gbt:false:postgres:security_barrier=true:true', $checks);
    }

    /**
     * PUBLIC loses CREATE on schema public (the default of PostgreSQL 15 and later, simulated here for
     * a database created by an older server); a role that owns tables there keeps it by name, the
     * plugin's role has none.
     */
    public function testThePostgresGrantSetRevokesCreateOnSchemaPublic(): void
    {
        $role = $this->fixtureRole('pgsql');
        $checks = $this->pgSession(
            'GRANT CREATE ON SCHEMA public TO PUBLIC; CREATE ROLE gbt_r2_owner; CREATE TABLE gbt_r2_owned (x int); ALTER TABLE gbt_r2_owned OWNER TO gbt_r2_owner;',
            ['grants-only' => true],
            "SELECT 'gbt:' || has_schema_privilege('public', 'public', 'CREATE') || ':' || has_schema_privilege('{$role}', 'public', 'CREATE') || ':' || has_schema_privilege('gbt_r2_owner', 'public', 'CREATE') || ':' || has_database_privilege('{$role}', current_database(), 'CREATE');",
        );
        self::assertStringContainsString('gbt:false:false:true:false', $checks);
    }

    /**
     * --grants-only and --reset-password reset attributes somebody gave an existing role.
     */
    public function testEveryFormResetsTheRoleAttributes(): void
    {
        $role = $this->fixtureRole('pgsql');
        foreach ([['grants-only' => true], ['reset-password' => true]] as $mode) {
            $checks = $this->pgSession(
                "ALTER ROLE {$role} CREATEDB BYPASSRLS INHERIT;",
                $mode,
                "SELECT 'gbt:' || rolcreatedb || ':' || rolbypassrls || ':' || rolinherit || ':' || rolsuper FROM pg_roles WHERE rolname = '{$role}';",
            );
            self::assertStringContainsString('gbt:false:false:false:false', $checks, json_encode($mode) ?: '');
        }
    }

    /**
     * A TABLE of the view's name stops the grant set: nothing is applied (one transaction).
     */
    public function testTheGrantSetStopsAtATableOfTheViewName(): void
    {
        $dsn = Env::engines()['pgsql'][0] ?? '';
        [$engine, $db] = DbAdmin::target($dsn);
        $role = $this->fixtureRole('pgsql');
        [, $sql] = self::tool(['grants', 'driver' => 'pgsql', 'role' => $role, 'db' => $db, 'grants-only' => true]);
        [$code, , $err] = DbAdmin::script($engine, $db, "BEGIN;\nDROP VIEW galbridge_forwardings;\nCREATE TABLE galbridge_forwardings (x int);\n" . self::insideTransaction($sql) . "\nROLLBACK;\n");
        self::assertNotSame(0, $code);
        self::assertStringContainsString('galbridge_forwardings', $err);
        self::assertSame('v', DbAdmin::query($engine, $db, "SELECT relkind FROM pg_class WHERE relname = 'galbridge_forwardings'"), 'nothing changed');
    }

    // ------------------------------------------------------------------ the plugin and diag refuse

    /**
     * @return array<string, array{string, string}>
     */
    public static function broadRoles(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            if ($engine === 'sqlite') {
                continue;
            }
            $out[$engine . ': a secret column of another table'] = [$dsn, 'secret'];
            $out[$engine . ': a membership'] = [$dsn, 'member'];
            $out[$engine . ': a privilege outside the directory'] = [$dsn, 'outside'];
            $out[$engine . ': a privilege to change a table'] = [$dsn, 'write'];
            if ($engine === 'pgsql') {
                $out['pgsql: a role attribute'] = [$dsn, 'attribute'];
                $out['pgsql: the owner of the database'] = [$dsn, 'owner'];
                // (final review, PRODUCT-1) privileges in the vmail database that are no relation
                $out['pgsql: CREATE on another schema'] = [$dsn, 'create'];
                $out['pgsql: EXECUTE on a function of another schema'] = [$dsn, 'function'];
                $out['pgsql: USAGE on another schema alone'] = [$dsn, 'usage'];
                // a shared object other than a database: a tablespace (every version) and, on
                // PostgreSQL 15+, a configuration parameter as well
                $out['pgsql: a privilege on a tablespace or parameter'] = [$dsn, 'shared'];
            } else {
                $out['mysql: a global privilege'] = [$dsn, 'global'];
            }
        }
        return $out === [] ? ['no server engine configured' => ['', '']] : $out;
    }

    #[DataProvider('broadRoles')]
    public function testThePluginRefusesARoleThatReachesMoreThanTheDirectory(string $dsn, string $variant): void
    {
        [$engine, $db] = DbAdmin::target($dsn);
        $role = 'gbt_r2_b' . substr($variant, 0, 3) . getmypid();
        $group = 'gbt_r2_g' . getmypid();
        $this->roles[] = [$engine, $db, $role];
        $mysql = $engine === 'mysql';
        $extra = match ($variant) {
            'secret' => [$mysql ? "GRANT SELECT (username, password) ON `{$db}`.`admin` TO %r" : 'GRANT SELECT (username, password) ON admin TO %r'],
            'member' => $mysql ? ["CREATE ROLE {$group}", "GRANT {$group} TO %r"] : ["CREATE ROLE {$group}", "GRANT {$group} TO %r"],
            'outside' => $mysql ? ['GRANT SELECT ON `gbt_r2_other_test`.* TO %r'] : ['CREATE SCHEMA gbt_r2_other', 'CREATE TABLE gbt_r2_other.t (x int)', 'GRANT USAGE ON SCHEMA gbt_r2_other TO %r', 'GRANT SELECT ON gbt_r2_other.t TO %r'],
            'write' => [$mysql ? "GRANT INSERT ON `{$db}`.`used_quota` TO %r" : 'GRANT INSERT ON used_quota TO %r'],
            'attribute' => ['ALTER ROLE %r CREATEDB'],
            'owner' => ["ALTER DATABASE {$db} OWNER TO %r"],
            'global' => ['GRANT PROCESS ON *.* TO %r'],
            'create' => ['CREATE SCHEMA gbt_r1_create', 'GRANT CREATE ON SCHEMA gbt_r1_create TO %r'],
            // the scenario of the review: EXECUTE taken from PUBLIC and granted to the role by name
            'function' => ['CREATE SCHEMA gbt_r1_reporting', "CREATE FUNCTION gbt_r1_reporting.purge() RETURNS integer LANGUAGE sql AS 'SELECT 1'",
                'REVOKE EXECUTE ON FUNCTION gbt_r1_reporting.purge() FROM PUBLIC', 'GRANT EXECUTE ON FUNCTION gbt_r1_reporting.purge() TO %r'],
            'usage' => ['CREATE SCHEMA gbt_r1_usage', 'GRANT USAGE ON SCHEMA gbt_r1_usage TO %r'],
            'shared' => self::pgVersion($engine, $db) >= 150000
                ? ['GRANT CREATE ON TABLESPACE pg_default TO %r', 'GRANT ALTER SYSTEM ON PARAMETER archive_command TO %r']
                : ['GRANT CREATE ON TABLESPACE pg_default TO %r'],
            default => [],
        };
        $schema = ['create' => 'gbt_r1_create', 'function' => 'gbt_r1_reporting', 'usage' => 'gbt_r1_usage'][$variant] ?? null;
        if ($variant === 'member') {
            $this->cleanup[] = [$engine, $db, $mysql ? "DROP ROLE IF EXISTS {$group};" : "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = '{$group}') THEN EXECUTE 'DROP ROLE {$group}'; END IF; END \$\$;"];
        } elseif ($variant === 'outside' && !$mysql) {
            $this->cleanup[] = [$engine, $db, 'DROP SCHEMA IF EXISTS gbt_r2_other CASCADE;'];
        } elseif ($schema !== null) {
            $this->cleanup[] = [$engine, $db, "DROP SCHEMA IF EXISTS {$schema} CASCADE;"];
            // a schema left over by an aborted run
            DbAdmin::script($engine, $db, "DROP SCHEMA IF EXISTS {$schema} CASCADE;", false);
        } elseif ($variant === 'owner') {
            // (before the role is dropped: a role that owns a database cannot be)
            $this->cleanup[] = [$engine, $db, "ALTER DATABASE {$db} OWNER TO postgres;"];
        }
        if ($variant === 'member') {
            // a group role left over by an aborted run
            DbAdmin::script($engine, $db, $this->cleanup[0][2], false);
        }
        // (tearDown runs the clean-up statements before it drops the role: the database goes back
        // to its owner first - a role that owns a database cannot be dropped)
        $roleDsn = DbAdmin::createRole($dsn, $role, Schema::GRANTABLE, $extra);

        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($roleDsn), self::JAN);
        self::assertSame([], $factory->books(), $variant);
        self::assertSame('role_too_broad', $factory->resolver()->status(), $variant);
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        $reason = match ($variant) {
            'secret' => 'it can read secret columns (admin.password)',
            'member' => 'it is a member of the role(s) ' . $group,
            'outside' => 'it holds privileges outside the directory (' . ($mysql ? 'gbt_r2_other_test' : 'gbt_r2_other.t'),
            'write' => 'it can change or administer objects (' . ($mysql ? 'used_quota:INSERT' : 'used_quota'),
            'attribute' => 'it has the role attribute(s) CREATEDB',
            'owner' => 'it owns the database',
            'global' => 'it holds global privileges (PROCESS)',
            'create' => 'it holds privileges outside the directory (schema gbt_r1_create)',
            'function' => 'it holds privileges outside the directory (function gbt_r1_reporting.purge())',
            'usage' => 'it holds privileges outside the directory (schema gbt_r1_usage)',
            'shared' => self::pgVersion($engine, $db) >= 150000
                ? 'it holds privileges outside the directory (parameter ACL archive_command, tablespace pg_default)'
                : 'it holds privileges outside the directory (tablespace pg_default)',
            default => '?',
        };
        self::assertStringContainsString('role_too_broad: the configured database role can do more than read the directory - ' . $reason, $log);

        [$code, $out] = $this->diag($roleDsn, ['--user=' . self::JAN]);
        self::assertSame(2, $code, $out);
        CliRun::assertLine($out, 'FAIL', 'mailbox', 'status: role_too_broad');
        [$what, $text] = match ($variant) {
            'secret' => ['secret columns', 'VISIBLE (admin.password)'],
            'member' => ['role memberships', $group],
            'outside' => ['privileges outside', $mysql ? 'gbt_r2_other_test' : 'gbt_r2_other.t'],
            'write' => ['write privileges', 'used_quota'],
            'attribute' => ['role attributes', 'CREATEDB'],
            'owner' => ['database owner', 'the role OWNS the vmail database'],
            'global' => ['global privileges', 'PROCESS'],
            'create' => ['privileges outside', 'schema gbt_r1_create - the plugin refuses the role'],
            'function' => ['privileges outside', 'function gbt_r1_reporting.purge() - the plugin refuses the role'],
            'usage' => ['privileges outside', 'schema gbt_r1_usage - the plugin refuses the role'],
            'shared' => ['privileges outside', 'tablespace pg_default - the plugin refuses the role'],
            default => ['?', '?'],
        };
        CliRun::assertLine($out, 'FAIL', $what, $text);
        // the CREATE line of diag looks at every schema: its OK says so and is true
        if ($variant === 'create') {
            CliRun::assertLine($out, 'FAIL', 'schema public', 'the role may create objects in another schema of the vmail database (CREATE on schema gbt_r1_create)');
            self::assertStringNotContainsString('no CREATE privilege', $out);
        } elseif (!$mysql && in_array($variant, ['outside', 'function', 'usage'], true)) {
            CliRun::assertLine($out, 'OK', 'schema public', 'no CREATE privilege (its schema, schema public, the other schemas and the database)');
        }
    }

    /**
     * A view galbridge_forwardings with rows of another kind (created by hand without the filter)
     * is refused by the plugin on every engine, and diag fails.
     */
    #[DataProvider('engines')]
    public function testThePluginRefusesAViewWithOtherRows(string $dsn): void
    {
        $body = 'SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active FROM forwardings';
        if (str_starts_with($dsn, 'sqlite:')) {
            Fixture::alter($dsn, ['DROP VIEW galbridge_forwardings', 'CREATE VIEW galbridge_forwardings AS ' . $body], [
                'DROP VIEW galbridge_forwardings',
                'CREATE VIEW galbridge_forwardings AS ' . $body . ' WHERE is_alias = 1 OR is_list = 1 OR is_maillist = 1',
            ]);
        } else {
            [$engine, $db] = DbAdmin::target($dsn);
            $this->restoreView = $dsn;
            [$code, , $err] = DbAdmin::script($engine, $db, $engine === 'mysql'
                ? "CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER VIEW {$db}.galbridge_forwardings AS {$body} LIMIT 18446744073709551615;\n"
                : "CREATE OR REPLACE VIEW galbridge_forwardings WITH (security_barrier = true) AS {$body} OFFSET 0;\n");
            self::assertSame(0, $code, $err);
        }
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($dsn), self::JAN);
        self::assertSame([], $factory->books());
        self::assertSame('role_too_broad', $factory->resolver()->status());
        self::assertStringContainsString('role_too_broad: the view galbridge_forwardings holds rows of another kind than alias, alias-member and list rows', implode("\n", $GLOBALS['galbridge_test_log']));
        if (!str_starts_with($dsn, 'sqlite:')) {
            [$code, $out] = $this->diag($dsn);
            self::assertSame(2, $code, $out);
            CliRun::assertLine($out, 'FAIL', 'view galbridge_forwardings', 'row(s) of another kind - private forwarding targets; the plugin refuses it');
        }
    }

    /**
     * diag checks the PostgreSQL view object: an option taken away, another owner.
     */
    public function testDiagChecksThePostgresViewObject(): void
    {
        $dsn = Env::engines()['pgsql'][0] ?? '';
        [$engine, $db] = DbAdmin::target($dsn);
        $this->restoreView = $dsn;
        $owner = 'gbt_r2_vo' . getmypid();
        $this->roles[] = [$engine, $db, $owner];
        foreach ([
            ['ALTER VIEW galbridge_forwardings SET (security_barrier = false);', 'it is no security barrier (security_barrier)'],
            ["CREATE ROLE {$owner}; GRANT SELECT ON forwardings TO {$owner}; ALTER VIEW galbridge_forwardings OWNER TO {$owner};", 'it is owned by ' . $owner . ', neither a superuser nor the owner of the table forwardings: that role can change the view at any time'],
        ] as [$change, $problem]) {
            DbAdmin::restoreFixtureView($dsn);
            [$code, , $err] = DbAdmin::script($engine, $db, $change . "\n", false);
            self::assertSame(0, $code, $err);
            [$code, $out] = $this->diag($dsn);
            self::assertSame(2, $code, $out);
            CliRun::assertLine($out, 'FAIL', 'view galbridge_forwardings', $problem);
        }
    }

    /**
     * CREATE on schema public (a database of PostgreSQL 14 or older) is a FAIL of diag; the plugin
     * logs a warning and keeps working (the grant set revokes it).
     */
    public function testCreateOnSchemaPublicFailsDiagAndWarnsThePlugin(): void
    {
        $dsn = Env::engines()['pgsql'][0] ?? '';
        Fixture::alter($dsn, ['GRANT CREATE ON SCHEMA public TO PUBLIC'], ['REVOKE CREATE ON SCHEMA public FROM PUBLIC']);
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($dsn), self::JAN);
        self::assertNotSame([], $factory->books(), 'the books keep working');
        self::assertStringContainsString('role_can_create: the configured database role may create objects in the vmail database', implode("\n", $GLOBALS['galbridge_test_log']));
        [$code, $out] = $this->diag($dsn);
        self::assertSame(2, $code, $out);
        CliRun::assertLine($out, 'FAIL', 'schema public', 'the role may create objects in the vmail database');

        // (final review, PRODUCT-1) a role whose own schema is another one: that CREATE of PUBLIC on
        // schema public is still the old default - can_create, a warning - and no privilege outside;
        // CREATE on schema public granted to the role by name is one, like on any other schema
        [$engine, $db] = DbAdmin::target($dsn);
        $role = 'gbt_r1_home' . getmypid();
        $this->roles[] = [$engine, $db, $role];
        $this->cleanup[] = [$engine, $db, 'DROP SCHEMA IF EXISTS gbt_r1_home CASCADE;'];
        DbAdmin::script($engine, $db, 'DROP SCHEMA IF EXISTS gbt_r1_home CASCADE;', false);
        $roleDsn = DbAdmin::createRole($dsn, $role, Schema::GRANTABLE, ['CREATE SCHEMA gbt_r1_home', 'GRANT USAGE ON SCHEMA gbt_r1_home TO %r', 'ALTER ROLE %r SET search_path = gbt_r1_home, public']);
        $scope = RoleScope::read(new Connection(new Dsn($roleDsn, Env::config($roleDsn)), 3));
        self::assertTrue($scope->canCreate, 'CREATE on schema public through PUBLIC only');
        self::assertSame([], $scope->createSchemas);
        DbAdmin::script($engine, $db, "GRANT CREATE ON SCHEMA public TO {$role};", false);
        $scope = RoleScope::read(new Connection(new Dsn($roleDsn, Env::config($roleDsn)), 3));
        self::assertFalse($scope->canCreate, 'neither its own schema nor the database');
        self::assertSame(['schema public'], $scope->createSchemas);
        self::assertContains('schema public', $scope->outside);
        self::assertContains('it holds privileges outside the directory (' . RoleScope::names($scope->outside) . ')', $scope->refusals([]));
    }

    /**
     * mariadb-install-db (RHEL and its rebuilds, FreeBSD, the official tarballs) creates the database
     * "test" and grants EVERY account all on "test" and "test_*" - MariaDB 10.11 and later through
     * PUBLIC, older servers (and MySQL, which keeps such rows) through blank-user rows of mysql.db,
     * done here the same way. A database of that name on which the role holds nothing of its own is
     * a server default, not a privilege of the role: the books keep working, the plugin logs
     * server_test_database, diag fails with the fix. A test database the role holds a privilege on
     * itself, and any other database open to every account, stay role_too_broad.
     */
    #[DataProvider('mysqlServer')]
    public function testAServerWideTestDatabaseIsNoRefusalButDiagFails(string $dsn): void
    {
        [$engine, $db] = DbAdmin::target($dsn);
        [$open, $own, $other] = ['test_galbridge_open', 'test_galbridge_own', 'gbt_r2_open_db'];
        $cleanup = "DROP DATABASE IF EXISTS {$open}; DROP DATABASE IF EXISTS {$own}; DROP DATABASE IF EXISTS {$other};"
            . " DELETE FROM mysql.db WHERE Db IN ('{$open}', '{$own}', '{$other}'); FLUSH PRIVILEGES;";
        $this->cleanup[] = [$engine, $db, $cleanup];
        DbAdmin::script($engine, $db, $cleanup, false); // what an aborted run left behind
        $version = DbAdmin::query($engine, $db, 'SELECT VERSION()');
        $public = stripos($version, 'mariadb') !== false && version_compare((string) preg_replace('/[^0-9.].*$/s', '', $version), '10.11', '>=');
        $toEveryAccount = static fn (string $database): string => $public
            ? "CREATE DATABASE {$database}; GRANT ALL PRIVILEGES ON `{$database}`.* TO PUBLIC;"
            : "CREATE DATABASE {$database}; INSERT INTO mysql.db (Host, Db, User, Select_priv, Insert_priv, Update_priv, Delete_priv, Create_priv, Drop_priv)"
                . " VALUES ('%', '{$database}', '', 'Y', 'Y', 'Y', 'Y', 'Y', 'Y'); FLUSH PRIVILEGES;";

        // the server default: the fixture role works, with a warning; diag fails and names the fix
        [$code, , $err] = DbAdmin::script($engine, $db, $toEveryAccount($open), false);
        self::assertSame(0, $code, $err);
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($dsn), self::JAN);
        self::assertNotSame([], $factory->books(), 'the books keep working: ' . implode("\n", $GLOBALS['galbridge_test_log']));
        // (a server that still has the "test" database of mariadb-install-db lists it as well)
        self::assertMatchesRegularExpression('/server_test_database: the configured database role can see the server-wide test database\\(s\\) (test, )?' . $open . ', which the server opens to every account/', implode("\n", $GLOBALS['galbridge_test_log']));
        [$code, $out] = $this->diag($dsn);
        self::assertSame(2, $code, $out);
        CliRun::assertLine($out, 'FAIL', 'server-wide test database', $open . ' - open to every account of the server');
        CliRun::assertLine($out, 'FAIL', 'server-wide test database', 'mariadb-secure-installation ("Remove test database and access to it") or as the administrator: DROP DATABASE test; DELETE FROM mysql.db WHERE Db IN (\'test\', \'test\\\\_%\'); FLUSH PRIVILEGES;');
        CliRun::assertLine($out, 'OK', 'privileges outside', 'none outside the vmail database');
        self::assertSame(1, substr_count($out, '[FAIL]'), $out);

        // a test database the role holds a privilege on itself: refused
        $role = 'gbt_r2_tst' . getmypid();
        $this->roles[] = [$engine, $db, $role];
        [$code, , $err] = DbAdmin::script($engine, $db, "CREATE DATABASE {$own};", false);
        self::assertSame(0, $code, $err);
        $roleDsn = DbAdmin::createRole($dsn, $role, Schema::GRANTABLE, ["GRANT SELECT ON `{$own}`.* TO %r"]);
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $_SESSION = [];
        $factory = Env::factory(Env::config($roleDsn), self::JAN);
        self::assertSame([], $factory->books());
        self::assertSame('role_too_broad', $factory->resolver()->status());
        self::assertStringContainsString('role_too_broad: the configured database role can do more than read the directory - it holds privileges outside the directory (' . $own . ')', implode("\n", $GLOBALS['galbridge_test_log']));
        [$code, $out] = $this->diag($roleDsn);
        self::assertSame(2, $code, $out);
        CliRun::assertLine($out, 'FAIL', 'privileges outside', $own . ' - the plugin refuses the role');
        CliRun::assertLine($out, 'FAIL', 'server-wide test database', $open . ' - ');

        // any other database open to every account: refused
        [$code, , $err] = DbAdmin::script($engine, $db, $toEveryAccount($other), false);
        self::assertSame(0, $code, $err);
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $_SESSION = [];
        $factory = Env::factory(Env::config($dsn), self::JAN);
        self::assertSame([], $factory->books());
        self::assertStringContainsString('role_too_broad: the configured database role can do more than read the directory - it holds privileges outside the directory (' . $other . ')', implode("\n", $GLOBALS['galbridge_test_log']));
        [$code, $out] = $this->diag($dsn);
        self::assertSame(2, $code, $out);
        CliRun::assertLine($out, 'FAIL', 'privileges outside', $other . ' - the plugin refuses the role');
    }

    /**
     * Write privileges are looked for on every relation of the schema (1.0.1: eight names only),
     * objects the role owns are a FAIL, and columns GAL Bridge never reads are named.
     */
    #[DataProvider('serverEngines')]
    public function testDiagLooksAtEveryRelation(string $dsn): void
    {
        [$engine, $db] = DbAdmin::target($dsn);
        $role = 'gbt_r2_every' . getmypid();
        $this->roles[] = [$engine, $db, $role];
        $columns = Schema::GRANTABLE;
        $columns['mailbox'][] = 'maildir';
        $roleDsn = DbAdmin::createRole($dsn, $role, $columns);
        [, $out] = $this->diag($roleDsn);
        CliRun::assertLine($out, 'WARN', 'columns beyond the grant set', 'mailbox.maildir - GAL Bridge never reads them');
        if ($engine === 'pgsql') {
            CliRun::assertLine($out, 'OK', 'owned objects', 'none');
            CliRun::assertLine($out, 'OK', 'role attributes', 'LOGIN only');
            CliRun::assertLine($out, 'INFO', 'other databases', 'the role may connect to ');
            DbAdmin::script($engine, $db, "GRANT INSERT ON used_quota TO {$role}; CREATE TABLE gbt_r2_t{$role} (x int); ALTER TABLE gbt_r2_t{$role} OWNER TO {$role};", false);
            $this->cleanup[] = [$engine, $db, "DROP TABLE IF EXISTS gbt_r2_t{$role};"];
            [$code, $out] = $this->diag($roleDsn);
            self::assertSame(2, $code, $out);
            CliRun::assertLine($out, 'FAIL', 'write privileges', 'the role can modify vmail (gbt_r2_t' . $role . ', used_quota)');
            CliRun::assertLine($out, 'FAIL', 'owned objects', '1 object(s) owned by the role');
        } else {
            CliRun::assertLine($out, 'OK', 'global privileges', 'none (USAGE only)');
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    /**
     * The MariaDB/MySQL server only (no PostgreSQL case that could only be skipped: CI fails on
     * skips).
     *
     * @return array<string, array{string}>
     */
    public static function mysqlServer(): array
    {
        $dsn = Env::engines()['mysql'][0] ?? null;
        return is_string($dsn) ? ['mysql' => [$dsn]] : ['no MySQL/MariaDB server configured' => ['']];
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
     * One administrator session on the PostgreSQL fixture, rolled back: $setup, the statements of a
     * grants output for the fixture role (between its BEGIN and COMMIT), $checks. Returns the
     * standard output.
     *
     * @param array<string, true> $mode
     */
    private function pgSession(string $setup, array $mode, string $checks): string
    {
        $dsn = Env::engines()['pgsql'][0] ?? '';
        [$engine, $db] = DbAdmin::target($dsn);
        [$code, $sql, $errors] = self::tool(['grants', 'driver' => 'pgsql', 'role' => $this->fixtureRole('pgsql'), 'db' => $db] + $mode);
        self::assertSame(0, $code, $errors);
        [$code, $out, $err] = DbAdmin::script($engine, $db, "BEGIN;\n{$setup}\n" . self::insideTransaction($sql) . "\n{$checks}\nROLLBACK;\n");
        self::assertSame(0, $code, $err);
        return $out;
    }

    private function fixtureRole(string $engine): string
    {
        $dsn = Env::engines()[$engine][0] ?? '';
        DbAdmin::target($dsn);
        return (string) (\rcube_db::parse_dsn($dsn)['username'] ?? '');
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function diag(string $dsn, array $args = []): array
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        return CliRun::diag($config, $args);
    }

    /**
     * @param array<int|string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function tool(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Tool(null, CliRun::pluginDir(), $out, $err))->run($args);
        rewind($out);
        rewind($err);
        $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);
        return $result;
    }

    /**
     * server_version_num of a PostgreSQL test server (e.g. 170012).
     */
    private static function pgVersion(string $engine, string $db): int
    {
        return $engine === 'pgsql' ? (int) DbAdmin::query($engine, $db, 'SHOW server_version_num') : 0;
    }

    private static function insideTransaction(string $sql): string
    {
        $begin = strpos($sql, "\nBEGIN;\n");
        $commit = strrpos($sql, "\nCOMMIT;");
        self::assertIsInt($begin);
        self::assertIsInt($commit);
        return substr($sql, $begin + 8, $commit - $begin - 8);
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2scope');
        }
        return $this->base;
    }
}
