<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\DbAdmin;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-25: the test harness and the machine it runs on. tests/e2e/setup.sh
 * checks everything before it changes anything and never redirects a plugin link that points
 * elsewhere or touches a configured Roundcube tree; the suites refuse root and stop only their own
 * web server; load.sh creates an account for every host only on request and can drop what it made.
 * The scripts run as child processes (argument arrays, no shell); the database cases use the
 * administrator access of load.sh (MYSQL_ADMIN / PSQL_ADMIN) on a database and role of their own,
 * so the shared fixture is never reloaded.
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2HarnessTest extends TestCase
{
    private const DB = 'galbridge_r2h_test';
    private const ROLE = 'gbt_r2h_ro';

    /** an existing PostgreSQL role that load.sh grants the rows of the test database (GALBRIDGE_FIXTURE_USER) */
    private const FIXTURE_USER = 'gbt_r2h_rw';

    private ?string $base = null;

    /** @var list<array{0: string, 1: string}> engine, database to drop in tearDown */
    private array $loaded = [];

    /** whether FIXTURE_USER was created (dropped in tearDown, after its database) */
    private bool $fixtureUser = false;

    protected function tearDown(): void
    {
        foreach ($this->loaded as [$engine]) {
            self::load(['drop', $engine, self::DB]);
        }
        if ($this->fixtureUser) {
            DbAdmin::dropRole('pgsql', 'postgres', self::FIXTURE_USER);
        }
        if ($this->base !== null) {
            foreach (glob(self::plugin() . '/tests/e2e/run/' . basename($this->base) . '*') ?: [] as $run) {
                CliRun::rmTree($run);
            }
            CliRun::rmTree($this->base);
        }
    }

    private static function plugin(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * A fake Roundcube release tree (what setup.sh checks before it starts anything).
     */
    private function tree(): string
    {
        $this->base = CliRun::tempBase('galbridge-r2h');
        $rc = $this->base . '/rc';
        foreach (['program/include', 'vendor', 'config', 'plugins', 'public_html'] as $d) {
            mkdir("{$rc}/{$d}", 0o700, true);
        }
        file_put_contents("{$rc}/program/include/iniset.php", "<?php\n");
        file_put_contents("{$rc}/config/defaults.inc.php", "<?php\n\$config = [];\n");
        return $rc;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runSetup(string $rc, array $env = []): array
    {
        if (is_file(self::plugin() . '/config.inc.php')) {
            self::markTestSkipped('the plugin directory has its own config.inc.php (setup.sh refuses it)');
        }
        $port = (string) self::freePort();
        return CliRun::exec([self::plugin() . '/tests/e2e/setup.sh', $rc, PHP_BINARY, 'mysql', $port], null, $env + getenv());
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, $errstr);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** the root refusal is the first check: as root these cases opt in, so the later checks are reached */
    private static function rootOptIn(): array
    {
        return CliRun::isRoot() ? ['GALBRIDGE_E2E_ALLOW_ROOT' => '1'] : [];
    }

    private function assertNothingCreated(string $rc): void
    {
        self::assertSame([], glob(self::plugin() . '/tests/e2e/run/' . basename(dirname($rc)) . '*') ?: [], 'no run directory');
        self::assertSame([], glob(self::plugin() . '/tests/e2e/run/rc-*-mysql') ?: [], 'no run directory of this tree');
    }

    public function testSetupRefusesToReplaceALinkToAnotherDirectory(): void
    {
        $rc = $this->tree();
        $elsewhere = dirname($rc) . '/another-plugin';
        mkdir($elsewhere, 0o700);
        symlink($elsewhere, "{$rc}/plugins/galbridge");
        [$code, $out, $err] = self::runSetup($rc, self::rootOptIn());
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('points to ' . realpath($elsewhere) . ', not to this checkout; not replaced', $err);
        self::assertSame($elsewhere, readlink("{$rc}/plugins/galbridge"), 'the link is unchanged');
        self::assertStringNotContainsString('RUN=', $out);
        $this->assertNothingCreated($rc);

        // a directory is never replaced; a dangling link is (and recorded as replaced)
        unlink("{$rc}/plugins/galbridge");
        mkdir("{$rc}/plugins/galbridge", 0o700);
        [$code, , $err] = self::runSetup($rc, self::rootOptIn());
        self::assertSame(1, $code);
        self::assertStringContainsString('exists and is not a symbolic link; not replaced', $err);
        $this->assertNothingCreated($rc);
    }

    public function testSetupRefusesAConfiguredRoundcubeTree(): void
    {
        $rc = $this->tree();
        file_put_contents("{$rc}/config/config.inc.php", "<?php\n\$config = [];\n");
        [$code, $out, $err] = self::runSetup($rc, self::rootOptIn());
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('is a configured Roundcube installation (config/config.inc.php)', $err);
        self::assertFalse(is_link("{$rc}/plugins/galbridge") || file_exists("{$rc}/plugins/galbridge"), 'no plugin link');
        $this->assertNothingCreated($rc);
    }

    /**
     * Roundcube and Chromium run with the caller's rights: every entry point refuses root (exit 2)
     * before it starts or changes anything - unless the machine is declared disposable.
     */
    #[Group('root')]
    public function testSetupRefusesRootWithoutOptIn(): void
    {
        if (!CliRun::isRoot()) {
            self::markTestSkipped('needs root (the refusal applies to root only)');
        }
        $rc = $this->tree();
        $env = getenv();
        unset($env['GALBRIDGE_E2E_ALLOW_ROOT']);
        $port = (string) self::freePort();
        $refusal = "refusing to run Roundcube and Chromium as root (they run with the caller's rights); use an unprivileged user, or GALBRIDGE_E2E_ALLOW_ROOT=1 in a disposable container";
        foreach ([
            [self::plugin() . '/tests/e2e/setup.sh', $rc, PHP_BINARY, 'mysql', $port],
            ['python3', self::plugin() . '/tests/e2e/test_e2e.py', '--rc', $rc, '--php', PHP_BINARY, '--db', 'mysql', '--port', $port],
            ['python3', self::plugin() . '/tests/ui/test_ui.py', '--rc', $rc, '--php', PHP_BINARY, '--db', 'mysql', '--port', $port],
            ['bash', self::plugin() . '/tests/e2e/matrix.sh', dirname($rc) . '/none'],
        ] as $cmd) {
            [$code, $out, $err] = CliRun::exec($cmd, null, $env);
            self::assertSame(2, $code, implode(' ', $cmd) . ': ' . $out . $err);
            self::assertStringContainsString($refusal, $err);
            self::assertFalse(is_link("{$rc}/plugins/galbridge"), 'nothing was linked');
            $this->assertNothingCreated($rc);
        }
    }

    /**
     * The suites stop the PHP web server of their run only when the process named in its pid file
     * really is that server, and put the plugin link back as setup.sh found it.
     */
    public function testTheSuitesStopOnlyTheirOwnServerAndPutTheLinkBack(): void
    {
        $rc = $this->tree();
        $run = dirname($rc) . '/run';
        mkdir($run, 0o700);
        $port = self::freePort();
        $harness = static function (string $call) use ($run, $rc, $port): array {
            return CliRun::exec(['python3', '-c', 'import sys; sys.path.insert(0, sys.argv[1]); import harness; ' . $call,
                self::plugin() . '/tests/e2e', $run, $rc, (string) $port]);
        };
        $spawn = static function (array $cmd): array {
            $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            self::assertIsResource($proc);
            return [$proc, (int) proc_get_status($proc)['pid']];
        };
        // a process that is not the server of this run (another port) is never signalled
        [$decoy, $decoyPid] = $spawn([PHP_BINARY, '-S', '127.0.0.1:' . self::freePort(), '-t', $rc . '/public_html']);
        [$server, $serverPid] = $spawn([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $rc . '/public_html']);
        try {
            usleep(300000);
            file_put_contents("{$run}/php-server.pid", $decoyPid . "\n");
            self::assertSame(0, $harness('harness.cleanup(sys.argv[2], sys.argv[3], int(sys.argv[4]))')[0]);
            usleep(200000);
            self::assertTrue(proc_get_status($decoy)['running'], 'a process of another port is not stopped');
            file_put_contents("{$run}/php-server.pid", $serverPid . "\n");
            self::assertSame(0, $harness('harness.cleanup(sys.argv[2], sys.argv[3], int(sys.argv[4]))')[0]);
            for ($i = 0; $i < 50 && proc_get_status($server)['running']; $i++) {
                usleep(100000);
            }
            self::assertFalse(proc_get_status($server)['running'], 'the server of this run is stopped');
        } finally {
            proc_terminate($decoy);
            proc_terminate($server);
            proc_close($decoy);
            proc_close($server);
        }

        $link = "{$rc}/plugins/galbridge";
        $restore = static fn (): array => $harness('harness.restore_link(sys.argv[2], sys.argv[3])');
        // created by setup.sh: removed
        symlink(self::plugin(), $link);
        file_put_contents("{$run}/plugin-link.state", "created\n");
        self::assertSame(0, $restore()[0]);
        self::assertFalse(is_link($link));
        // there before: kept
        symlink(self::plugin(), $link);
        file_put_contents("{$run}/plugin-link.state", "kept\n");
        $restore();
        self::assertTrue(is_link($link));
        // replaced on request: points to its old target again
        file_put_contents("{$run}/plugin-link.state", "replaced /opt/somewhere/else\n");
        $restore();
        self::assertSame('/opt/somewhere/else', readlink($link));
        // changed by somebody else in the meantime: untouched
        file_put_contents("{$run}/plugin-link.state", "created\n");
        $restore();
        self::assertSame('/opt/somewhere/else', readlink($link));
    }

    /**
     * A skipped case fails the suites and the matrix unless a partial run is asked for.
     */
    public function testSkippedCasesFailTheSuites(): void
    {
        $e2e = (string) file_get_contents(self::plugin() . '/tests/e2e/test_e2e.py');
        $ui = (string) file_get_contents(self::plugin() . '/tests/ui/test_ui.py');
        self::assertStringContainsString('return 1 if failed or (skipped and not args.allow_skips) else 0', $e2e);
        self::assertStringContainsString('sys.exit(1 if bad or (skipped and not C.args.allow_skips) else 0)', $ui);
        self::assertStringContainsString('UI (%s): %d passed, %d failed, %d skipped', $ui);
        self::assertStringContainsString('Service(executable_path=drv)', $ui);
        self::assertStringNotContainsString('service=None', $ui);
        self::assertStringNotContainsString("'--headless=new', '--no-sandbox'", $ui, 'the sandbox stays on unless asked for');
        self::assertStringContainsString('*" 0 failed, 0 skipped"*', (string) file_get_contents(self::plugin() . '/tests/e2e/matrix.sh'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverEngines(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            if ($engine !== 'sqlite') {
                $out[$engine] = [$engine];
            }
        }
        return $out === [] ? ['no server engine configured' => ['']] : $out;
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function load(array $args, array $env = []): array
    {
        // (never inherited: CI sets GALBRIDGE_LOAD_ANY_HOST and GALBRIDGE_FIXTURE_USER for its fixture steps only)
        return CliRun::exec([self::plugin() . '/tests/fixtures/vmail/load.sh', ...$args], null,
            $env + ['GALBRIDGE_RO_USER' => self::ROLE, 'GALBRIDGE_LOAD_ANY_HOST' => '', 'GALBRIDGE_FIXTURE_USER' => ''] + getenv());
    }

    private function loadOwn(string $engine, array $env = []): void
    {
        if (DbAdmin::command($engine) === null) {
            self::markTestSkipped("no administrator access to the {$engine} test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)");
        }
        $this->loaded[] = [$engine, self::DB];
        [$code, $out, $err] = self::load([$engine, self::DB], $env);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString(self::DB, $out);
    }

    private static function accounts(): string
    {
        return DbAdmin::queryAll('mysql', 'mysql', "SELECT Host FROM mysql.user WHERE User = '" . self::ROLE . "' ORDER BY Host");
    }

    public function testLoadCreatesNoAnyHostAccountUnlessAsked(): void
    {
        if (!isset(Env::engines()['mysql'])) {
            self::markTestSkipped('needs the MariaDB test server (GALBRIDGE_TEST_MYSQL_DSN)');
        }
        $this->loadOwn('mysql');
        self::assertSame("127.0.0.1\nlocalhost", self::accounts(), 'loopback accounts only');

        $env = ['GALBRIDGE_LOAD_ANY_HOST' => '1'];
        [$code, , $err] = self::load(['mysql', self::DB], $env);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("WARNING - an account for every host ('" . self::ROLE . "'@'%') with the test password: only on a throw-away server", $err);
        self::assertSame("%\n127.0.0.1\nlocalhost", self::accounts());

        // a reload without the variable removes the '%' account again
        [$code, , $err] = self::load(['mysql', self::DB]);
        self::assertSame(0, $code, $err);
        self::assertSame("127.0.0.1\nlocalhost", self::accounts());
    }

    /**
     * load.sh creates the test role with an account statement the server can run (database
     * compatibility): the verifier of the template (mysql_native_password) on MariaDB and on MySQL
     * with that plugin active; on MySQL without it (8.4 and later by default, 9.x has none) - where
     * the template's statement stops with "Plugin 'mysql_native_password' is not loaded" - the
     * clear-text form of grants --clear-text-password. Either way the role logs in with the test
     * password.
     */
    public function testLoadCreatesTheTestRoleWithAFormTheServerAccepts(): void
    {
        $fixture = Env::engines()['mysql'][0] ?? null;
        if (!is_string($fixture)) {
            self::markTestSkipped('needs the MariaDB/MySQL test server (GALBRIDGE_TEST_MYSQL_DSN)');
        }
        if (DbAdmin::command('mysql') === null) {
            self::markTestSkipped('no administrator access to the mysql test server (MYSQL_ADMIN as for load.sh)');
        }
        $verifierForm = DbAdmin::query('mysql', 'mysql', "SELECT VERSION() LIKE '%MariaDB%' OR (SELECT COUNT(*) FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'mysql_native_password' AND PLUGIN_STATUS = 'ACTIVE') = 1") === '1';
        $password = getenv('GALBRIDGE_RO_PASSWORD');
        $password = is_string($password) && $password !== '' ? $password : 'testonly-ro';
        $this->loaded[] = ['mysql', self::DB];
        // the role is reached the way the fixture role is: in CI the tests connect from the Docker
        // bridge, so load.sh gave the fixture role its '%' account - the own role needs it as well
        $fixtureUser = (string) preg_replace('~^mysql://([^:@/]+).*$~', '$1', $fixture);
        $anyHost = DbAdmin::query('mysql', 'mysql', "SELECT COUNT(*) FROM mysql.user WHERE User = '" . addslashes($fixtureUser) . "' AND Host = '%'") === '1';
        [$code, $out, $err] = self::load(['mysql', self::DB], $anyHost ? ['GALBRIDGE_LOAD_ANY_HOST' => '1'] : []);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString(self::DB, $out);
        $plugins = DbAdmin::queryAll('mysql', 'mysql', "SELECT DISTINCT plugin FROM mysql.user WHERE User = '" . self::ROLE . "'");
        if ($verifierForm) {
            self::assertSame('mysql_native_password', $plugins);
            self::assertSame(Tool::mysqlVerifier($password), DbAdmin::queryAll('mysql', 'mysql', "SELECT DISTINCT authentication_string FROM mysql.user WHERE User = '" . self::ROLE . "'"));
            self::assertStringNotContainsString('clear-text form', $err);
        } else {
            self::assertNotSame('mysql_native_password', $plugins);
            self::assertStringContainsString('the test role gets the clear-text form of grants --clear-text-password', $err);
        }
        $dsn = (string) preg_replace('~^mysql://[^@]*@([^/]*)/.*$~', 'mysql://' . self::ROLE . ':' . $password . '@$1/' . self::DB, $fixture);
        $conn = new Connection(new Dsn($dsn, Env::config($dsn)), 3);
        self::assertCount(1, $conn->select('SELECT m.username FROM mailbox m WHERE m.username = ?', ['jan.kowalski@example.com']));
        $conn->close();
        $_SESSION = [];
    }

    /**
     * Fixture::alter() for an engine this run has no server of: a clean skip, no PHP warning (it
     * read the scheme of an empty DSN).
     */
    public function testTheFixtureHelperSkipsAnEngineWithoutADsn(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs rcube_db::parse_dsn (GALBRIDGE_RC)');
        }
        try {
            Fixture::alter('', ['SELECT 1'], ['SELECT 1']);
            self::fail('no skip');
        } catch (SkippedTest $skipped) {
            self::assertSame('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN', $skipped->getMessage());
        }
        Fixture::restore();
    }

    #[DataProvider('serverEngines')]
    public function testLoadDropRemovesDatabaseAndRole(string $engine): void
    {
        if ($engine === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        $this->loadOwn($engine);
        [$code, $out, $err] = self::load(['drop', $engine, self::DB]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('dropped the database ' . self::DB . ' and the role ' . self::ROLE, $out);
        if ($engine === 'mysql') {
            self::assertSame('0', DbAdmin::query('mysql', 'mysql', "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . self::DB . "'"));
            self::assertSame('', self::accounts());
        } else {
            self::assertSame('0', DbAdmin::query('pgsql', 'postgres', "SELECT COUNT(*) FROM pg_database WHERE datname = '" . self::DB . "'"));
            self::assertSame('0', DbAdmin::query('pgsql', 'postgres', "SELECT COUNT(*) FROM pg_roles WHERE rolname = '" . self::ROLE . "'"));
        }
        // the same name checks as loading: never another database or a role of another component
        [$code, , $err] = self::load(['drop', $engine, 'vmail']);
        self::assertSame(1, $code);
        self::assertStringContainsString('the database name must contain "test"', $err);
        [$code, , $err] = CliRun::exec([self::plugin() . '/tests/fixtures/vmail/load.sh', 'drop', $engine, self::DB], null, ['GALBRIDGE_RO_USER' => 'roundcube'] + getenv());
        self::assertSame(1, $code);
        self::assertStringContainsString('must contain "_ro" or "test"', $err);
    }

    /**
     * The name checks need no server (they run first); the grants need a PostgreSQL server - that
     * case exists only where one is configured, so a run without one skips nothing.
     *
     * @return array<string, array{string}>
     */
    public static function fixtureUserCases(): array
    {
        $cases = ['names load.sh refuses' => ['names']];
        if (isset(Env::engines()['pgsql'])) {
            $cases['pgsql'] = ['pgsql'];
        }
        return $cases;
    }

    /**
     * Final review CI-1: the fixture access of the unprivileged user that runs the E2E and browser
     * suites is the rows of the test database - not pg_read_all_data / pg_write_all_data, which reach
     * every table of every database of the cluster (pg_authid included). load.sh grants an EXISTING
     * role (GALBRIDGE_FIXTURE_USER) CONNECT on the test database, USAGE on its schema public and
     * SELECT, INSERT, UPDATE, DELETE / USAGE, SELECT, UPDATE on its tables and sequences after every
     * load, and nothing else; it refuses a bad, reserved or administrator name before it asks the
     * server, and a missing role or one with cluster-wide access before it drops anything.
     */
    #[DataProvider('fixtureUserCases')]
    public function testTheFixtureUserGetsTheRowsOfTheTestDatabaseOnly(string $case): void
    {
        if ($case === 'names') {
            // an administrator command that cannot work: a name that passed the checks would end in
            // "cannot inspect the target server" instead
            foreach (['PUBLIC' => 'invalid role name in GALBRIDGE_FIXTURE_USER', 'e2e;drop' => 'invalid role name in GALBRIDGE_FIXTURE_USER',
                'a-b' => 'invalid role name in GALBRIDGE_FIXTURE_USER', self::ROLE => 'must not be the read-only test role ' . self::ROLE,
                'public' => 'refusing GALBRIDGE_FIXTURE_USER=public: a reserved name', 'current_user' => 'refusing GALBRIDGE_FIXTURE_USER=current_user',
                'none' => 'refusing GALBRIDGE_FIXTURE_USER=none', 'postgres' => 'refusing GALBRIDGE_FIXTURE_USER=postgres',
                'pg_write_all_data' => 'refusing GALBRIDGE_FIXTURE_USER=pg_write_all_data', 'roundcube' => 'refusing GALBRIDGE_FIXTURE_USER=roundcube',
                'vmail' => 'refusing GALBRIDGE_FIXTURE_USER=vmail'] as $name => $message) {
                [$code, $out, $err] = self::load(['pgsql', self::DB], ['GALBRIDGE_FIXTURE_USER' => $name, 'PSQL_ADMIN' => 'false']);
                self::assertSame(1, $code, $name . ': ' . $out . $err);
                self::assertStringContainsString($message, $err, $name);
                self::assertStringNotContainsString('cannot inspect the target server', $err, "{$name}: refused before the server is asked");
            }
            return;
        }

        if (DbAdmin::command('pgsql') === null) {
            self::markTestSkipped('no administrator access to the pgsql test server (PSQL_ADMIN as for load.sh)');
        }
        $user = self::FIXTURE_USER;
        DbAdmin::dropRole('pgsql', 'postgres', $user);
        $this->fixtureUser = true;
        [$code, , $err] = DbAdmin::script('pgsql', 'postgres', "CREATE ROLE {$user} LOGIN;");
        self::assertSame(0, $code, $err);

        $this->loaded[] = ['pgsql', self::DB];
        [$code, $out, $err] = self::load(['pgsql', self::DB], ['GALBRIDGE_FIXTURE_USER' => $user]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString(self::DB, $out);
        self::assertStringContainsString("load.sh: {$user} may read and change the rows of " . self::DB, $err);

        // exactly the scoped privileges: every table and view of schema public with SELECT, INSERT,
        // UPDATE, DELETE, every sequence with USAGE, SELECT, UPDATE, USAGE on the schema, CONNECT on
        // the database - and no membership (no predefined role), no ACL entry anywhere else
        $acl = static fn (string $sql): string => DbAdmin::queryAll('pgsql', self::DB, $sql);
        $relations = $acl("SELECT c.relname || ' ' || CASE WHEN c.relkind = 'S' THEN 'USAGE,SELECT,UPDATE' ELSE 'SELECT,INSERT,UPDATE,DELETE' END FROM pg_class c"
            . " JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S') ORDER BY 1");
        foreach (['mailbox SELECT,INSERT,UPDATE,DELETE', 'galbridge_forwardings SELECT,INSERT,UPDATE,DELETE', 'forwardings_id_seq USAGE,SELECT,UPDATE'] as $line) {
            self::assertContains($line, explode("\n", $relations), 'the tables, the view re-created by the grant set and the sequences');
        }
        self::assertSame($relations, $acl("SELECT c.relname || ' ' || string_agg(a.privilege_type, ',' ORDER BY strpos('USAGE,SELECT,INSERT,UPDATE,DELETE,', a.privilege_type || ','))"
            . ' FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace CROSS JOIN LATERAL aclexplode(c.relacl) a'
            . " WHERE a.grantee = '{$user}'::regrole AND n.nspname = 'public' GROUP BY c.relname ORDER BY 1"));
        self::assertSame('0', $acl('SELECT count(*) FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE a.grantee = '{$user}'::regrole AND n.nspname <> 'public'"));
        self::assertSame('public USAGE', $acl("SELECT string_agg(n.nspname || ' ' || a.privilege_type, ',') FROM pg_namespace n CROSS JOIN LATERAL aclexplode(n.nspacl) a"
            . " WHERE a.grantee = '{$user}'::regrole"));
        $connect = "SELECT string_agg(d.datname || ' ' || a.privilege_type, ',') FROM pg_database d CROSS JOIN LATERAL aclexplode(d.datacl) a WHERE a.grantee = '{$user}'::regrole";
        self::assertSame(self::DB . ' CONNECT', $acl($connect));
        self::assertSame('0', $acl("SELECT count(*) FROM pg_auth_members WHERE member = '{$user}'::regrole"), 'no membership in any role');
        self::assertSame('0', $acl("SELECT count(*) FROM pg_shdepend s WHERE s.refobjid = '{$user}'::regrole AND s.deptype = 'a'"
            . ' AND NOT (s.dbid = (SELECT oid FROM pg_database WHERE datname = current_database())'
            . " OR (s.dbid = 0 AND s.classid = 'pg_database'::regclass AND s.objid = (SELECT oid FROM pg_database WHERE datname = current_database())))"),
            'no privilege outside the test database');

        // what the suites do through it works; anything beyond the rows of this database is denied
        // (server messages in English whatever lc_messages the cluster has)
        $as = "SET lc_messages = 'C';\nSET ROLE {$user};\n";
        [$code, , $err] = DbAdmin::script('pgsql', self::DB, "BEGIN;\n{$as}"
            . "INSERT INTO mailbox (username, name, domain, maildir) VALUES ('r2h.probe@example.com', 'p', 'example.com', 'example.com/r2h');\n"
            . "UPDATE mailbox SET name = 'q' WHERE username = 'r2h.probe@example.com';\nDELETE FROM mailbox WHERE username = 'r2h.probe@example.com';\n"
            . "SELECT nextval('forwardings_id_seq');\nSELECT count(*) FROM galbridge_forwardings;\nROLLBACK;\n");
        self::assertSame(0, $code, 'the fixture user changes rows of the test database: ' . $err);
        [$code, , $err] = DbAdmin::script('pgsql', self::DB, "CREATE SCHEMA gbt_r2h_other;\nCREATE TABLE gbt_r2h_other.t (x int);\n");
        self::assertSame(0, $code, $err);
        foreach (['SELECT count(*) FROM gbt_r2h_other.t' => 'permission denied for schema gbt_r2h_other', 'TRUNCATE mailbox' => 'permission denied for table mailbox',
            'CREATE TABLE gbt_r2h_new (x int)' => 'permission denied for schema public'] as $sql => $denied) {
            [$code, , $err] = DbAdmin::script('pgsql', self::DB, "{$as}{$sql};\n");
            self::assertNotSame(0, $code, $sql);
            self::assertStringContainsString($denied, $err, $sql);
        }
        // the shared fixture database of this run is another database the role was not granted
        $shared = (string) preg_replace('~^.*/([a-z0-9_]+)(?:\?.*)?$~D', '$1', Env::engines()['pgsql'][0]);
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*test[a-z0-9_]*$/D', $shared);
        self::assertNotSame(self::DB, $shared);
        [$code, , $err] = DbAdmin::script('pgsql', $shared, "{$as}SELECT count(*) FROM mailbox;\n");
        self::assertNotSame(0, $code, 'another database');
        self::assertStringContainsString('permission denied for table mailbox', $err, 'the table of another database is not readable');

        // refused before anything is dropped: a role that does not exist, a role with cluster-wide access
        [$code, , $err] = self::load(['pgsql', self::DB], ['GALBRIDGE_FIXTURE_USER' => 'gbt_r2h_none']);
        self::assertSame(1, $code);
        self::assertStringContainsString('the role GALBRIDGE_FIXTURE_USER=gbt_r2h_none does not exist or cannot be checked: create it first', $err);
        if (DbAdmin::query('pgsql', 'postgres', "SELECT count(*) FROM pg_roles WHERE rolname = 'pg_read_all_data'") === '1') {
            [$code, , $err] = DbAdmin::script('pgsql', 'postgres', "GRANT pg_read_all_data TO {$user};");
            self::assertSame(0, $code, $err);
            [$code, , $err] = self::load(['pgsql', self::DB], ['GALBRIDGE_FIXTURE_USER' => $user]);
            self::assertSame(1, $code);
            self::assertStringContainsString("GALBRIDGE_FIXTURE_USER={$user} is a member of pg_read_all_data or pg_write_all_data", $err);
            DbAdmin::script('pgsql', 'postgres', "REVOKE pg_read_all_data FROM {$user};");
        }
        self::assertSame('1', $acl("SELECT count(*) FROM pg_namespace WHERE nspname = 'gbt_r2h_other'"), 'nothing was dropped by a refused run');
        self::assertSame(self::DB . ' CONNECT', $acl($connect), 'nothing was dropped by a refused run');

        // the grants live in the database: a reload without the variable leaves the role nothing
        [$code, , $err] = self::load(['pgsql', self::DB]);
        self::assertSame(0, $code, $err);
        self::assertSame('0', $acl("SELECT count(*) FROM pg_shdepend s WHERE s.refobjid = '{$user}'::regrole AND s.deptype = 'a'"));
    }
}
