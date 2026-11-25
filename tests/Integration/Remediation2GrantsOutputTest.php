<?php

declare(strict_types=1);

/**
 * GAL Bridge - second verification pass (assessment of 1.0.1), F-28: what grants writes where.
 *
 *  - the SQL goes to standard output, the DSN line (the password) to standard error only - also in
 *    the clear-text form, whose statement is the one place of the password in the SQL;
 *  - usage errors, refusals and template errors go to standard error, never into a redirected SQL
 *    file; a repeated option and --account-host for PostgreSQL are usage errors;
 *  - no output of any form holds a backslash or a line a database client runs itself;
 *  - the clear-text form stops on MariaDB before the statement with the password whatever the client
 *    does with comments (a statement only MySQL 8 can run);
 *  - the PostgreSQL output switches off pg_stat_statements' tracking of utility statements for its
 *    transaction;
 *  - diag warns about a MariaDB/MySQL account that other hosts may use without TLS, without ever
 *    printing the account statement (it holds the password hash).
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\DbAdmin;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-aware')]
final class Remediation2GrantsOutputTest extends TestCase
{
    private const ENV = ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'];

    private string $base = '';

    /** @var list<array{0: string, 1: string, 2: string}> engine, database, role */
    private array $roles = [];

    protected function tearDown(): void
    {
        foreach ($this->roles as [$engine, $db, $role]) {
            DbAdmin::dropRole($engine, $db, $role);
        }
        $this->roles = [];
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    /**
     * @return array<string, array{string, array<string, true>}>
     */
    public static function forms(): array
    {
        return [
            'mysql' => ['mysql', []],
            'mysql --reset-password' => ['mysql', ['reset-password' => true]],
            'mysql --clear-text-password' => ['mysql', ['clear-text-password' => true]],
            'mysql --clear-text-password --reset-password' => ['mysql', ['clear-text-password' => true, 'reset-password' => true]],
            'pgsql' => ['pgsql', []],
            'pgsql --reset-password' => ['pgsql', ['reset-password' => true]],
        ];
    }

    /**
     * @param array<string, true> $mode
     */
    #[DataProvider('forms')]
    public function testTheDsnLineIsNeverInTheSqlStream(string $driver, array $mode): void
    {
        [$code, $sql, $errors] = self::tool(['grants', 'driver' => $driver, 'role' => 'acme_gal', 'db' => 'maildb', 'host' => 'db.example.com'] + $mode);
        self::assertSame(0, $code, $errors);
        // exactly one DSN line, on the error stream, with the TLS reminder of a remote database
        self::assertSame(1, preg_match_all("/^-- \\\$config\\['galbridge_db_dsn'\\] = '([a-z]+:\\/\\/acme_gal:([0-9a-f]{40})@db\\.example\\.com\\/maildb)';$/m", $errors, $m), $errors);
        $password = $m[2][0];
        self::assertStringContainsString('-- Remote database: add TLS verification to the DSN', $errors);
        self::assertStringStartsWith("\n-- Put this line into config/galbridge.inc.php", $errors);
        // the SQL stream: no DSN line, no TLS reminder - and no password, but in the statement of the
        // clear-text form, which exists for MySQL 8.4 only
        self::assertDoesNotMatchRegularExpression("/^-- \\\$config\\['galbridge_db_dsn'\\] = '[a-z]+:\\/\\/acme_gal:/m", $sql);
        self::assertStringNotContainsString('Put this line into', $sql);
        self::assertStringNotContainsString('Remote database', $sql);
        if (isset($mode['clear-text-password'])) {
            self::assertSame(isset($mode['reset-password']) ? 2 : 1, substr_count($sql, "IDENTIFIED BY '{$password}'"));
            self::assertSame(isset($mode['reset-password']) ? 2 : 1, substr_count($sql, $password));
        } else {
            self::assertStringNotContainsString($password, $sql);
            // the verifier in the SQL belongs to the password of the DSN line
            if ($driver === 'mysql') {
                self::assertStringContainsString("IDENTIFIED WITH mysql_native_password AS '" . Tool::mysqlVerifier($password) . "'", $sql);
            } else {
                self::assertSame(1, preg_match("/PASSWORD '(SCRAM-SHA-256\\$4096:([A-Za-z0-9+\\/=]+)\\$[A-Za-z0-9+\\/=]+:[A-Za-z0-9+\\/=]+)'/", $sql, $v));
                self::assertSame($v[1], Tool::scramVerifier($password, (string) base64_decode($v[2], true)));
            }
        }
        self::assertNull(Tool::clientCommand($sql), 'nothing a client runs itself');
    }

    /**
     * Every form, both drivers, with names and hosts of every kind: no backslash anywhere and no
     * line that starts with a command of the mysql client (README 2.2: grep for a backslash).
     */
    public function testNoOutputContainsAClientCommand(): void
    {
        foreach (['mysql' => ['', 'localhost', '10.0.0.%', 'devmail.example.com'], 'pgsql' => ['']] as $driver => $accountHosts) {
            foreach ([[], ['reset-password' => true], ['grants-only' => true], ['clear-text-password' => true]] as $mode) {
                if ($driver === 'pgsql' && isset($mode['clear-text-password'])) {
                    continue;
                }
                foreach ($accountHosts as $accountHost) {
                    foreach ([['role' => 'galbridge', 'db' => 'vmail'], ['role' => 'use', 'db' => 'source'], ['role' => 'system_gal', 'db' => 'delimiter']] as $names) {
                        $args = ['grants', 'driver' => $driver] + $names + $mode + ($accountHost !== '' ? ['account-host' => $accountHost] : []);
                        [$code, $sql] = self::tool($args);
                        self::assertSame(0, $code, json_encode($args) . $sql);
                        self::assertStringNotContainsString('\\', $sql, json_encode($args));
                        self::assertNull(Tool::clientCommand($sql), json_encode($args));
                    }
                }
            }
        }
    }

    /**
     * A template that would put a client command into the output is not printed at all.
     */
    public function testATemplateWithAClientCommandIsNotPrinted(): void
    {
        foreach (["\\! id\n", "system id\n", "  SOURCE /tmp/x.sql\n"] as $injected) {
            $plugin = CliRun::releaseCopy($this->base(), 'plugin-' . bin2hex(random_bytes(3)));
            $file = $plugin . '/SQL/grants.mysql.sql';
            file_put_contents($file, str_replace('-- [/role]', "-- [/role]\n" . $injected, (string) file_get_contents($file)));
            [$code, $sql, $errors] = self::tool(['grants', 'driver' => 'mysql'], $plugin);
            self::assertSame(2, $code, $errors);
            self::assertSame('', $sql, 'nothing reaches the SQL stream');
            self::assertStringStartsWith('grants: the statements built from ' . $file . ' contain ', $errors);
            self::assertStringContainsString('nothing was printed', $errors);
        }
    }

    public function testUsageAndRefusalsGoToStandardError(): void
    {
        $plugin = CliRun::releaseCopy($this->base(), 'galbridge');
        $usage = Tool::usage('grants');
        foreach ([
            [['grants', '--driver=mysql', '--frobnicate'], 1, $usage . "\nunknown option --frobnicate\n"],
            [['grants', '--driver=mysql', '--role'], 1, $usage . "\n--role needs a value: --role=...\n"],
            [['grants', '--driver=mysql', '--role=a_gal', '--role=b_gal'], 1, $usage . "\n--role is given twice: give every option once\n"],
            [['grants', '--driver=pgsql', '--account-host=10.0.0.5'], 1, $usage . "\n--account-host is for MariaDB/MySQL accounts: PostgreSQL limits the client addresses of a role in pg_hba.conf (README 2.2)\n"],
            [['grants', '--driver=pgsql', '--role=vmail'], 1, "grants: 'vmail' is a role of another component or of the database server (galbridge_db_refused_users): choose a dedicated role name, e.g. --role=galbridge\n"],
            [['grants', '--driver=sqlite'], 1, $usage . "\n"],
        ] as [$args, $exit, $message]) {
            [$code, $out, $err] = CliRun::script($args, self::ENV, null, $plugin);
            self::assertSame($exit, $code, implode(' ', $args) . ': ' . $err);
            self::assertSame('', $out, implode(' ', $args) . ': nothing on standard output');
            self::assertSame($message, $err);
        }
        // an unknown command: the help text as an error; help on request on standard output
        [$code, $out, $err] = self::tool(['frobnicate']);
        self::assertSame([1, ''], [$code, $out]);
        self::assertStringContainsString('GAL Bridge administration tool', $err);
        [$code, $out, $err] = self::tool(['help']);
        self::assertSame([0, ''], [$code, $err]);
        self::assertStringContainsString('The one exception is grants: run it as root with --allow-root', $out);
        self::assertStringContainsString('Do not run this tool as root', $out);

        // a missing template: exit code 2, on standard error
        unlink($plugin . '/SQL/grants.postgres.sql');
        [$code, $out, $err] = CliRun::script(['grants', '--driver=pgsql'], self::ENV, null, $plugin);
        self::assertSame(2, $code, $err);
        self::assertSame('', $out);
        self::assertSame('grant template not found: ' . (realpath($plugin) ?: $plugin) . "/SQL/grants.postgres.sql\n", $err);

        // the same in this process: the error stream is a stream of its own when one is given
        [$code, $sql, $errors] = self::tool(['grants', 'driver' => 'mysql', 'conection-limit' => '5']);
        self::assertSame([1, ''], [$code, $sql]);
        self::assertSame($usage . "\nunknown option --conection-limit\n", $errors);
    }

    /**
     * A tool that is given one stream only (the callers of 1.0.x, the CLI tests) keeps one
     * transcript: errors go there too.
     */
    public function testOneStreamKeepsOneTranscript(): void
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertSame(0, (new Tool(null, CliRun::pluginDir(), $out))->run(['grants', 'driver' => 'pgsql']));
        rewind($out);
        $text = (string) stream_get_contents($out);
        self::assertStringContainsString('CREATE VIEW galbridge_forwardings', $text);
        self::assertMatchesRegularExpression("/^-- \\\$config\\['galbridge_db_dsn'\\] = 'pgsql:\\/\\/galbridge:[0-9a-f]{40}@127\\.0\\.0\\.1\\/vmail';$/m", $text);
    }

    /**
     * --clear-text-password is for MySQL 8.4 and later. On MariaDB, which keeps account statements
     * verbatim in its logs, the output stops before the statement with the password - also when the
     * client sends no comment at all (the MariaDB-only guard is one): the statement after it uses a
     * setting MySQL 8 has and MariaDB does not. On a MySQL test server both forms run and create
     * the account (the guard is no obstacle there).
     */
    public function testClearTextOutputStopsOnMariaDbWhateverTheClientDoesWithComments(): void
    {
        $dsn = Env::engines()['mysql'][0] ?? null;
        if (!is_string($dsn)) {
            self::markTestSkipped('needs the MariaDB test server');
        }
        [$engine, $db, $host] = DbAdmin::target($dsn);
        $mariadb = stripos(DbAdmin::query($engine, $db, 'SELECT VERSION()'), 'mariadb') !== false;
        $role = 'gbt_r2_ct' . getmypid();
        DbAdmin::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        [$code, $sql, $errors] = self::tool(['grants', 'driver' => 'mysql', 'role' => $role, 'db' => $db, 'host' => $host, 'clear-text-password' => true]);
        self::assertSame(0, $code, $errors);
        $guard = array_search('SELECT @@SESSION.information_schema_stats_expiry AS galbridge_mysql_8_only;', explode("\n", $sql), true);
        $create = array_search("CREATE USER '{$role}'@'localhost' IDENTIFIED BY '" . self::passwordOf($errors) . "';", explode("\n", $sql), true);
        self::assertIsInt($guard);
        self::assertIsInt($create);
        self::assertSame($guard + 1, $create, 'right before the statement with the password');
        $stripped = (string) preg_replace(['~/\*.*?\*/~s', '/^\s*--.*$/m'], '', $sql);
        self::assertStringNotContainsString('/*M!', $stripped);
        if (!$mariadb) {
            foreach (['as it is' => $sql, 'without comments' => $stripped] as $form => $script) {
                DbAdmin::dropRole($engine, $db, $role);
                [$code, , $err] = DbAdmin::script($engine, $db, $script);
                self::assertSame(0, $code, $form . ': ' . $err);
                self::assertSame('1', DbAdmin::query($engine, $db, "SELECT COUNT(*) FROM mysql.user WHERE User = '{$role}'"), $form);
            }
            return;
        }

        // (a) as it is: the MariaDB-only comment stops it
        [$code, , $err] = DbAdmin::script($engine, $db, $sql);
        self::assertNotSame(0, $code, 'the clear-text output ran on MariaDB');
        self::assertStringContainsString('this output is for MySQL', $err);
        // (b) through a client that drops every comment
        [$code, , $err] = DbAdmin::script($engine, $db, $stripped);
        self::assertNotSame(0, $code, 'the clear-text output without comments ran on MariaDB');
        self::assertStringContainsString('Unknown system variable', $err);
        self::assertSame('0', DbAdmin::query($engine, $db, "SELECT COUNT(*) FROM mysql.user WHERE User = '{$role}'"), 'nothing was created');
    }

    /**
     * The PostgreSQL output switches off pg_stat_statements' tracking of utility statements (CREATE /
     * ALTER ROLE ... PASSWORD) for its transaction - a placeholder setting when the module is not
     * loaded. Applied inside a transaction that is rolled back: nothing of the fixture changes.
     */
    public function testThePostgresOutputKeepsItsStatementsOutOfPgStatStatements(): void
    {
        $dsn = Env::engines()['pgsql'][0] ?? null;
        if (!is_string($dsn)) {
            self::markTestSkipped('needs the PostgreSQL test server');
        }
        [$engine, $db] = DbAdmin::target($dsn);
        $role = (string) (\rcube_db::parse_dsn($dsn)['username'] ?? '');
        [$code, $sql, $errors] = self::tool(['grants', 'driver' => 'pgsql', 'role' => $role, 'db' => $db, 'grants-only' => true]);
        self::assertSame(0, $code, $errors);
        $body = self::insideTransaction($sql);
        [$code, $out, $err] = DbAdmin::script($engine, $db, "BEGIN;\n{$body}\nSELECT 'gbt:' || current_setting('pg_stat_statements.track_utility', true) || ':' || current_setting('search_path');\nROLLBACK;\n");
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('gbt:off:public', $out);
        $loaded = DbAdmin::query($engine, $db, "SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_stat_statements'");
        if ($loaded !== '0') {
            $verifier = DbAdmin::query($engine, $db, 'SELECT COUNT(*) FROM pg_stat_statements WHERE query LIKE ' . "'%PASSWORD%SCRAM%'");
            self::assertSame('0', $verifier, 'pg_stat_statements kept a role statement with a verifier');
        }
    }

    /**
     * diag: a MariaDB/MySQL account that other hosts may use (here '%') without REQUIRE SSL is a
     * WARN; the account statement, which holds the password hash, is never printed.
     */
    public function testDiagWarnsAboutAnAccountOtherHostsMayUseWithoutTls(): void
    {
        $dsn = Env::engines()['mysql'][0] ?? null;
        if (!is_string($dsn)) {
            self::markTestSkipped('needs the MariaDB test server');
        }
        [$engine, $db] = DbAdmin::target($dsn);
        $role = 'gbt_r2_tls' . getmypid();
        DbAdmin::dropRole($engine, $db, $role);
        $this->roles[] = [$engine, $db, $role];
        $password = 'p' . bin2hex(random_bytes(12));
        // only the account of any host: also a local connection uses it
        $sql = "CREATE USER '{$role}'@'%' IDENTIFIED BY '{$password}';";
        foreach (DbAdmin::TEMPLATE_COLUMNS as $table => $cols) {
            $sql .= ' GRANT SELECT (`' . implode('`, `', $cols) . "`) ON `{$db}`.`{$table}` TO '{$role}'@'%';";
        }
        [$code, , $err] = DbAdmin::script($engine, $db, $sql, false);
        self::assertSame(0, $code, $err);
        $roleDsn = (string) preg_replace('~^([a-z]+)://[^@]*@~i', '$1://' . $role . ':' . $password . '@', $dsn);
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $roleDsn]);
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'WARN', 'account TLS', $role . '@% accepts connections from other hosts without REQUIRE SSL');
        $verifier = Tool::mysqlVerifier($password);
        self::assertStringNotContainsString(substr($verifier, 1, 20), $out . $err, 'the password hash is never printed');
        self::assertStringNotContainsString($password, $out . $err);
        self::assertStringNotContainsString('IDENTIFIED', $out);

        // the fixture role connects as its local account
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out] = CliRun::diag($config);
        self::assertMatchesRegularExpression('/^  \[( OK |WARN)\] account TLS +' . preg_quote((string) (\rcube_db::parse_dsn($dsn)['username'] ?? ''), '/') . '@/m', $out);
    }

    public function testDiagNamesWherePostgresqlKeepsTheTlsRule(): void
    {
        $dsn = Env::engines()['pgsql'][0] ?? null;
        if (!is_string($dsn)) {
            self::markTestSkipped('needs the PostgreSQL test server');
        }
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out] = CliRun::diag($config);
        CliRun::assertLine($out, 'INFO', 'account TLS', 'enforced by pg_hba.conf (hostssl / hostnossl ... reject, README 2.2), which the role cannot read');
    }

    /**
     * Tool::run() in this process with separate output and error streams.
     *
     * @param array<int|string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, standard output, standard error
     */
    private static function tool(array $args, ?string $pluginDir = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Tool(null, $pluginDir ?? CliRun::pluginDir(), $out, $err))->run($args);
        rewind($out);
        rewind($err);
        $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);
        return $result;
    }

    private static function passwordOf(string $errors): string
    {
        self::assertSame(1, preg_match("/^-- \\\$config\\['galbridge_db_dsn'\\] = '[a-z]+:\\/\\/[^:]+:([0-9a-f]{40})@/m", $errors, $m), $errors);
        return $m[1];
    }

    /**
     * The statements of a PostgreSQL output between its BEGIN and COMMIT (the setting before BEGIN
     * is for a session of its own).
     */
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
            $this->base = CliRun::tempBase('galbridge-r2out');
        }
        return $this->base;
    }
}
