<?php

declare(strict_types=1);

/**
 * GAL Bridge - second verification pass (assessment of 1.0.1), F-03: the view galbridge_forwardings
 * is materialised and fenced on every engine - the server builds its rows before it applies any
 * condition of a query, so a condition of a query sent with the role's own password never runs on a
 * private forwarding row:
 *
 *  - MariaDB/MySQL: ALGORITHM = TEMPTABLE (never merged) with a LIMIT fence (no condition pushed
 *    into it - condition_pushdown_for_derived / derived_condition_pushdown);
 *  - PostgreSQL: security_barrier with an OFFSET 0 fence (no qual, leakproof or not, moved below it);
 *  - the statements of the plugin refer to it in uncorrelated semi-joins, computed once per statement;
 *  - diag fails, and the plugin logs a warning, for a view that is not fenced.
 *
 * The fixture is loaded from the templates (load.sh); a test that replaces the view puts the
 * fixture's back (DbAdmin::restoreFixtureView()).
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Entitlements;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\Schema;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\DbAdmin;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-aware')]
final class Remediation2ForwardingsViewTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private string $base = '';

    private string $restore = '';

    protected function tearDown(): void
    {
        Fixture::restore();
        if ($this->restore !== '') {
            DbAdmin::restoreFixtureView($this->restore);
            $this->restore = '';
        }
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
        $_SESSION = [];
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
     * A caller-side expression with a side effect (a session variable counter) runs as often as the
     * view has rows - never on a row the view leaves out. Regression guard on both engines.
     */
    #[DataProvider('serverEngines')]
    public function testCallerExpressionsSeeOnlyTheRowsOfTheView(string $dsn): void
    {
        [$engine] = DbAdmin::target($dsn);
        $conn = new Connection(new Dsn($dsn, Env::config($dsn)), 3);
        $rows = (int) ($conn->select('SELECT COUNT(*) AS n FROM `' . Schema::FORWARDINGS . '` f')[0]['n'] ?? -1);
        self::assertGreaterThan(0, $rows);
        if ($engine === 'mysql') {
            $conn->select('SELECT @gbt_n := 0 AS z');
            $conn->select('SELECT COUNT(*) AS n FROM `' . Schema::FORWARDINGS . "` f WHERE (@gbt_n := @gbt_n + 1) > 0 AND f.forwarding >= ''");
            $seen = (int) ($conn->select('SELECT @gbt_n AS n')[0]['n'] ?? -1);
        } else {
            $conn->select("SELECT set_config('gbt.n', '0', false) AS z");
            $conn->select('SELECT COUNT(*) AS n FROM `' . Schema::FORWARDINGS . "` f WHERE set_config('gbt.n', (current_setting('gbt.n')::int + 1)::text, false) IS NOT NULL AND f.forwarding >= ''");
            $seen = (int) ($conn->select("SELECT current_setting('gbt.n') AS n")[0]['n'] ?? -1);
        }
        self::assertSame($rows, $seen, 'the expression of the query ran on rows the view leaves out');
        $conn->close();
    }

    /**
     * MariaDB/MySQL: the view is never merged into a query and no condition of a query is pushed
     * into it - the plan reads the view's rows by the flag filter and applies the condition on the
     * materialised rows (a merged view, or TEMPTABLE without the fence, evaluates "forwarding = ..."
     * on the index of the table, private rows included). MariaDB: EXPLAIN FORMAT=JSON; MySQL:
     * EXPLAIN FORMAT=TREE, the one form all of 8.0 to 26.10 print alike (their JSON differs: format
     * version 1 with "materialized_from_subquery" up to 8.4, version 2 from 9.x on).
     */
    public function testTheMysqlViewIsMaterialisedAndFenced(): void
    {
        $dsn = Env::engines()['mysql'][0] ?? '';
        [$engine, $db] = DbAdmin::target($dsn);
        $view = DbAdmin::queryAll($engine, $db, "SELECT CONCAT_WS(',', IS_UPDATABLE, SECURITY_TYPE) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'galbridge_forwardings'");
        self::assertSame('NO,DEFINER', $view);
        $create = DbAdmin::queryAll($engine, $db, 'SHOW CREATE VIEW galbridge_forwardings');
        self::assertStringContainsString('ALGORITHM=TEMPTABLE', $create);
        self::assertMatchesRegularExpression('/ limit 18446744073709551615\s/i', $create . "\n");
        if (!self::mariadb($engine, $db)) {
            $plan = DbAdmin::queryAll($engine, $db, "EXPLAIN FORMAT=TREE SELECT 1 FROM galbridge_forwardings f WHERE f.forwarding = 'nobody@example.invalid'");
            $plan = str_replace('\n', "\n", $plan);
            // Filter on f above "Table scan on f" above "Materialize" above the flag filter on the table
            $depth = [];
            foreach (explode("\n", $plan) as $line) {
                foreach (['condition' => "-> Filter: (f.forwarding = 'nobody@example.invalid')", 'scan' => '-> Table scan on f ', 'materialise' => '-> Materialize', 'flags' => '-> Filter: ((forwardings.is_alias = 1)'] as $what => $node) {
                    if (!isset($depth[$what]) && str_starts_with(ltrim($line), $node)) {
                        $depth[$what] = strlen($line) - strlen(ltrim($line));
                    }
                }
            }
            self::assertSame(['condition', 'scan', 'materialise', 'flags'], array_keys($depth), 'every node of the fenced plan: ' . $plan);
            self::assertTrue($depth['condition'] < $depth['scan'] && $depth['scan'] < $depth['materialise'] && $depth['materialise'] < $depth['flags'], 'the condition of the query stays above the materialised view: ' . $plan);
            self::assertStringNotContainsString('forwardings.forwarding', $plan, 'a condition of the query reached the table: ' . $plan);
            self::assertDoesNotMatchRegularExpression('/(lookup|range scan|index scan) on forwardings/i', $plan, 'an index of the table answered a condition of the query: ' . $plan);
            return;
        }
        $plan = DbAdmin::queryAll($engine, $db, "EXPLAIN FORMAT=JSON SELECT 1 FROM galbridge_forwardings f WHERE f.forwarding = 'nobody@example.invalid'");
        $json = json_decode(str_replace(['\\n', '\\t'], ["\n", ' '], $plan), true);
        self::assertIsArray($json, $plan);
        $inner = json_encode($json) ?: '';
        self::assertStringContainsString('"materialized"', $inner, 'a derived table built by its own query');
        self::assertStringNotContainsString('index_condition', $inner, 'a condition of the query reached the table: ' . $plan);
        self::assertDoesNotMatchRegularExpression('/"attached_condition":"[^"]*forwardings\.forwarding = /', $inner, 'a condition of the query was pushed into the view: ' . $plan);
    }

    /**
     * PostgreSQL: not even a condition PostgreSQL treats as leakproof (equality on the indexed
     * forwarding column) is moved below the view: no index condition on the table, the filter
     * stays on the Subquery Scan (EXPLAIN ANALYZE could otherwise confirm a guessed private target).
     */
    public function testLeakproofConditionsStayAboveThePostgresView(): void
    {
        $dsn = Env::engines()['pgsql'][0] ?? '';
        [$engine, $db] = DbAdmin::target($dsn);
        $plan = DbAdmin::queryAll($engine, $db, "SET enable_seqscan = off; EXPLAIN (COSTS OFF) SELECT 1 FROM galbridge_forwardings f WHERE f.forwarding = 'nobody@example.invalid'");
        self::assertStringContainsString('Subquery Scan on f', $plan);
        self::assertMatchesRegularExpression('/Filter: \(\(f\.forwarding\)::text = /', $plan);
        self::assertStringNotContainsString('Index Cond: ((forwardings.forwarding', $plan, $plan);
        self::assertStringNotContainsString('idx_forwardings_forwarding', $plan, $plan);
        self::assertSame('{security_barrier=true}', DbAdmin::query($engine, $db, "SELECT reloptions FROM pg_class WHERE relname = 'galbridge_forwardings'"));
    }

    /**
     * The statements of the plugin build the view once per statement: the alias listing of a viewer
     * with the strict list policy (membersonly aliases: member test inside OR branches) refers to it
     * only in uncorrelated semi-joins - PostgreSQL: hashed sub-plans, no sub-plan run per row;
     * MariaDB (ANALYZE FORMAT=JSON) and MySQL (EXPLAIN ANALYZE, 8.0.18 and later): every
     * materialisation of the view runs once - MySQL also inside a subquery it runs per row, whose
     * derived table it builds once and keeps.
     */
    #[DataProvider('serverEngines')]
    public function testTheViewIsBuiltOncePerStatement(string $dsn): void
    {
        [$engine, $db] = DbAdmin::target($dsn);
        if (DbAdmin::command($engine) === null) {
            self::markTestSkipped("no administrator access to the {$engine} test server");
        }
        // more alias rows than the fixture has, as they come in a large domain
        $rows = [];
        $members = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = sprintf("('gbtbulk%03d@example.com', 'Bulk %03d', 'membersonly', 'example.com', 1)", $i, $i);
            $members[] = sprintf("('gbtbulk%03d@example.com', 'gbtmember%03d@example.com', 'example.com', 0, 1, 0, 0, 1)", $i, $i);
        }
        Fixture::alter($dsn, [
            'INSERT INTO alias (address, name, accesspolicy, domain, active) VALUES ' . implode(', ', $rows),
            'INSERT INTO forwardings (address, forwarding, domain, is_maillist, is_list, is_forwarding, is_alias, active) VALUES ' . implode(', ', $members),
        ], [
            "DELETE FROM forwardings WHERE address LIKE 'gbtbulk%'",
            "DELETE FROM alias WHERE address LIKE 'gbtbulk%'",
        ]);
        [$sql, $params] = self::aliasListing($dsn);
        $inline = (string) preg_replace_callback('/\?/', static function () use (&$params): string {
            $v = array_shift($params);
            return is_int($v) ? (string) $v : "'" . str_replace("'", "''", (string) $v) . "'";
        }, $sql);
        if ($engine === 'pgsql') {
            $plan = DbAdmin::queryAll($engine, $db, 'EXPLAIN (COSTS OFF) ' . str_replace('`', '"', $inline));
            // the member test of the membersonly branch (inside OR) is a hashed sub-plan: built once
            self::assertStringContainsString('hashed SubPlan', $plan, $plan);
            // a sub-plan that is not hashed runs for every row: none of them reads the view
            preg_match_all('/\((?<!hashed )SubPlan (\d+)\)/', $plan, $plain);
            $lines = explode("\n", $plan);
            foreach ($plain[1] as $n) {
                foreach ($lines as $i => $line) {
                    if (preg_match('/^(\s*)SubPlan ' . $n . '$/', $line, $h) !== 1) {
                        continue;
                    }
                    for ($j = $i + 1; $j < count($lines) && strlen($lines[$j]) - strlen(ltrim($lines[$j])) > strlen($h[1]); $j++) {
                        self::assertStringNotContainsString('forwardings', $lines[$j], 'a sub-plan that runs for every row reads the view: ' . $plan);
                    }
                }
            }
        } elseif (!self::mariadb($engine, $db)) {
            $plan = str_replace('\n', "\n", DbAdmin::queryAll($engine, $db, 'EXPLAIN ANALYZE ' . $inline));
            $loops = self::materialisationLoops($plan);
            self::assertNotSame([], $loops, 'the view is materialised: ' . $plan);
            foreach ($loops as $n) {
                self::assertSame(1, $n, 'the view was built more (or less) than once in one statement: ' . $plan);
            }
        } else {
            $plan = DbAdmin::queryAll($engine, $db, 'ANALYZE FORMAT=JSON ' . $inline);
            $json = json_decode(str_replace(['\\n', '\\t'], ["\n", ' '], $plan), true);
            self::assertIsArray($json, $plan);
            $loops = [];
            self::collectDerivedLoops($json, $loops);
            self::assertNotSame([], $loops, 'the view is materialised: ' . $plan);
            foreach ($loops as $n) {
                self::assertLessThanOrEqual(1, $n, 'the view was built more than once in one statement: ' . $plan);
            }
        }
    }

    /**
     * A view that is not fenced (MariaDB: merged; PostgreSQL: no OFFSET 0) is a FAIL of diag and a
     * warning of the plugin, which keeps working (a 1.0.1 installation until grants --grants-only).
     */
    #[DataProvider('serverEngines')]
    public function testAViewWithoutTheFenceIsReported(string $dsn): void
    {
        [$engine, $db] = DbAdmin::target($dsn);
        $body = 'SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active FROM ' . ($engine === 'mysql' ? $db . '.' : '') . 'forwardings WHERE is_alias = 1 OR is_list = 1 OR is_maillist = 1';
        $this->restore = $dsn;
        [$code, , $err] = DbAdmin::script($engine, $db, $engine === 'mysql'
            ? "CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW {$db}.galbridge_forwardings AS {$body};\n"
            : "CREATE OR REPLACE VIEW galbridge_forwardings WITH (security_barrier = true) AS {$body};\n");
        self::assertSame(0, $code, $err);

        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = Env::factory(Env::config($dsn), self::JAN);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()), 'the books keep working');
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('view_not_materialised: the view galbridge_forwardings is not the one of the grant set: ' . ($engine === 'mysql' ? 'it is merged into the queries of the role' : 'it is not fenced'), $log);

        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [$code, $out] = CliRun::diag($config);
        self::assertSame(2, $code, $out);
        if ($engine === 'mysql') {
            // MySQL has no information_schema.VIEWS.ALGORITHM: its text names what it can see (SHOW
            // CREATE VIEW adds the ALGORITHM line below)
            CliRun::assertLine($out, 'FAIL', 'view galbridge_forwardings', 'it is merged into the queries of the role (' . (self::mariadb($engine, $db) ? 'ALGORITHM=MERGE, ' : '') . 'updatable: YES), so their conditions run on private forwarding rows');
            CliRun::assertLine($out, 'FAIL', 'view galbridge_forwardings', 'it is not ALGORITHM=TEMPTABLE: the server may merge it into the queries of the role');
            CliRun::assertLine($out, 'FAIL', 'view galbridge_forwardings', 'it is not fenced (no LIMIT 18446744073709551615 at the end)');
        } else {
            CliRun::assertLine($out, 'FAIL', 'view galbridge_forwardings', 'it is not fenced: the planner may move conditions of a query into it (no OFFSET 0) - apply galbridge.sh grants --grants-only');
        }
        self::assertStringNotContainsString('no condition of the role sees a private row', $out);
    }

    /**
     * The SQL and parameters of the alias listing of the domain book for the viewer jan (strict
     * list policy - the default - with membersonly aliases).
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    private static function aliasListing(string $dsn): array
    {
        $cfg = Env::config($dsn);
        $conn = new Connection(new Dsn($dsn, $cfg), 3);
        $viewer = (new ViewerResolver($cfg, static fn (): Connection => $conn, 0, self::JAN, '', ''))->lookup(self::JAN);
        self::assertNotNull($viewer);
        $books = Entitlements::books($viewer, $cfg, static fn (string $l): string => $l);
        $book = $books[BookDefinition::DOMAIN] ?? null;
        self::assertNotNull($book);
        $queries = QueryBuilder::forBook($cfg, $conn->driver(), $book, $viewer, FieldMap::fromConfig($cfg, $viewer));
        $conn->close();
        return $queries->kindCountQuery(QueryBuilder::KIND_ALIAS, 1000);
    }

    private static function mariadb(string $engine, string $db): bool
    {
        return stripos(DbAdmin::query($engine, $db, 'SELECT VERSION()'), 'mariadb') !== false;
    }

    /**
     * The loops of every "Materialize" node of a MySQL EXPLAIN ANALYZE (tree) that builds the view:
     * its subtree reads the table forwardings and holds no other materialisation (an outer derived
     * table that contains the view is not the view).
     *
     * @return list<int>
     */
    private static function materialisationLoops(string $plan): array
    {
        $lines = explode("\n", $plan);
        $loops = [];
        foreach ($lines as $i => $line) {
            if (!str_starts_with(ltrim($line), '-> Materialize')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $subtree = [];
            for ($j = $i + 1; $j < count($lines) && strlen($lines[$j]) - strlen(ltrim($lines[$j])) > $indent; $j++) {
                $subtree[] = $lines[$j];
            }
            $text = implode("\n", $subtree);
            if (str_contains($text, ' on forwardings') && !str_contains($text, '-> Materialize')) {
                self::assertMatchesRegularExpression('/ loops=(\d+)\)/', $line, 'EXPLAIN ANALYZE gives the loops of the node: ' . $line);
                preg_match('/ loops=(\d+)\)/', $line, $m);
                $loops[] = (int) $m[1];
            }
        }
        return $loops;
    }

    /**
     * r_loops of every derived table built from the view (ANALYZE FORMAT=JSON of MariaDB).
     *
     * @param array<array-key, mixed> $node
     * @param list<int>               $loops
     */
    private static function collectDerivedLoops(array $node, array &$loops): void
    {
        if (isset($node['table']) && is_array($node['table']) && str_starts_with((string) ($node['table']['table_name'] ?? ''), '<derived')
            && isset($node['table']['materialized']) && str_contains((string) json_encode($node['table']['materialized']), '"forwardings"')) {
            $loops[] = (int) ($node['table']['r_loops'] ?? 0);
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                self::collectDerivedLoops($child, $loops);
            }
        }
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2view');
        }
        return $this->base;
    }
}
