<?php

declare(strict_types=1);

/**
 * GAL Bridge - second verification pass (assessment of 1.0.1): the grant templates and the pure
 * helpers of the role checks (F-02, F-03, F-04, F-28).
 *
 *  - the templates hold nothing a database client would run itself (no backslash, no long-form
 *    mysql command) and their headers show the trusted commands (grants as root with
 *    --allow-root, mysql --binary-mode, psql -X reading standard input);
 *  - the view galbridge_forwardings is materialised and fenced on both engines, and on PostgreSQL
 *    dropped and created afresh inside the transaction;
 *  - the PostgreSQL grant set takes CREATE on schema public from PUBLIC, keeps its statements out of
 *    pg_stat_statements, and asserts the role attributes;
 *  - Schema::GRANTABLE is exactly what the templates grant;
 *  - Tool::parseArguments(), Tool::clientCommand(), Tool::requiresTls(),
 *    Tool::viewDefinitionProblems() and RoleScope::refusals().
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Cli\Tool;
use GalBridge\Db\RoleScope;
use GalBridge\Directory\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Remediation2GrantTemplatesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function templates(): array
    {
        return ['mysql' => ['mysql'], 'postgres' => ['postgres']];
    }

    #[DataProvider('templates')]
    public function testTemplatesHoldNoClientCommand(string $flavour): void
    {
        $text = self::template($flavour);
        // psql runs a backslash command anywhere on a line, mysql has a short form of each command
        self::assertStringNotContainsString('\\', $text);
        self::assertNull(Tool::clientCommand($text));
    }

    #[DataProvider('templates')]
    public function testTemplateHeadersShowTheTrustedCommands(string $flavour): void
    {
        $header = self::header($flavour);
        $driver = $flavour === 'mysql' ? 'mysql' : 'pgsql';
        self::assertStringContainsString("umask 077; plugins/galbridge/bin/galbridge.sh grants --allow-root --driver={$driver} > /root/galbridge-grants.sql", $header);
        self::assertStringContainsString('verified release archive (README 2.1)', $header, 'the template-only path takes the file from the verified archive');
        self::assertStringContainsString('standard error', $header, 'the DSN line is not in the file');
        self::assertDoesNotMatchRegularExpression('/sudo -u www-data\S* plugins\/galbridge\/bin\/galbridge\.sh grants/', $header, 'grants is never run as the web server user');
        if ($flavour === 'mysql') {
            self::assertStringContainsString('mysql --binary-mode < /root/galbridge-grants.sql', $header);
            self::assertStringNotContainsString('mysql < ', $header);
        } else {
            // root's shell reads the file: the postgres account needs no access to it
            self::assertStringContainsString('runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql', $header);
            self::assertStringContainsString('install -m 0600 grants.postgres.sql /root/galbridge-grants.sql', $header);
            self::assertDoesNotMatchRegularExpression('/psql[^\n]* -f /', $header);
            self::assertStringContainsString('pg_stat_statements', $header);
            self::assertStringContainsString('pgaudit', $header);
        }
    }

    public function testTheViewIsFencedOnBothEngines(): void
    {
        $mysql = self::statements('mysql');
        self::assertMatchesRegularExpression('/^CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER VIEW vmail\.galbridge_forwardings AS\n(?:.*\n){3}\s+LIMIT 18446744073709551615;$/m', $mysql);
        self::assertStringContainsString("GRANT SHOW VIEW ON vmail.galbridge_forwardings TO 'galbridge'@'localhost';", $mysql, 'diag reads the definition');
        // no other privilege than SELECT and that one
        preg_match_all('/^GRANT ([A-Z ]+?) (?:\(|ON )/m', $mysql, $m);
        self::assertSame(['SELECT', 'SHOW VIEW'], array_values(array_unique($m[1])));

        $pg = self::statements('postgres');
        self::assertMatchesRegularExpression('/^DROP VIEW IF EXISTS galbridge_forwardings;\nCREATE VIEW galbridge_forwardings WITH \(security_barrier = true\) AS\n(?:.*\n){3}\s+OFFSET 0;\nREVOKE ALL ON galbridge_forwardings FROM PUBLIC;$/m', $pg);
        self::assertStringNotContainsString('CREATE OR REPLACE VIEW', $pg);
        self::assertStringNotContainsString('ALTER VIEW', $pg, 'a fresh object needs no new owner');
    }

    public function testThePostgresGrantSetChangesNothingOutsideItsTransaction(): void
    {
        $pg = self::statements('postgres');
        $begin = strpos($pg, "\nBEGIN;\n");
        $commit = strrpos($pg, "\nCOMMIT;");
        self::assertIsInt($begin);
        self::assertIsInt($commit);
        $inside = substr($pg, $begin, $commit - $begin);
        foreach ([
            'SET LOCAL search_path = public;',
            'SET LOCAL pg_stat_statements.track_utility = off;',
            "CREATE ROLE galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT PASSWORD 'CHANGE_ME_RANDOM_PASSWORD';",
            'REVOKE CREATE ON SCHEMA public FROM PUBLIC;',
            'REVOKE CREATE ON SCHEMA public FROM galbridge;',
            'REVOKE CREATE, TEMPORARY ON DATABASE vmail FROM galbridge;',
            "EXECUTE format('GRANT CREATE ON SCHEMA public TO %I', owner_name);",
            'DROP VIEW IF EXISTS galbridge_forwardings;',
        ] as $statement) {
            self::assertStringContainsString($statement, $inside);
        }
        // the settings that keep the role statements out of logs and pg_stat_statements come first
        $role = (int) strpos($inside, 'CREATE ROLE');
        self::assertLessThan($role, (int) strpos($inside, 'SET LOCAL pg_stat_statements.track_utility = off;'));
        self::assertLessThan($role, (int) strpos($inside, 'SET LOCAL log_statement'));
        // the owners of tables keep CREATE before PUBLIC loses it
        self::assertLessThan((int) strpos($inside, 'REVOKE CREATE ON SCHEMA public FROM PUBLIC;'), (int) strpos($inside, "EXECUTE format('GRANT CREATE"));
    }

    /**
     * Schema::GRANTABLE is the union of the column lists of every GRANT of both templates, the
     * commented-out personal-data grant included - diag names any other column the role sees.
     */
    public function testGrantableIsExactlyWhatTheTemplatesGrant(): void
    {
        $granted = [];
        foreach (['mysql', 'postgres'] as $flavour) {
            preg_match_all('/^(?:-- )?GRANT SELECT \(([a-z_, ]+)\) ON (?:vmail\.)?([a-z_]+) TO /m', self::template($flavour), $m, PREG_SET_ORDER);
            foreach ($m as [, $cols, $table]) {
                foreach (explode(', ', $cols) as $col) {
                    $granted[$table][$col] = true;
                }
            }
        }
        $expected = array_map(static fn (array $c): array => array_keys($c), $granted);
        self::assertEqualsCanonicalizing(array_keys($expected), array_keys(Schema::GRANTABLE));
        foreach (Schema::GRANTABLE as $table => $cols) {
            self::assertEqualsCanonicalizing($expected[$table], $cols, $table);
        }
    }

    public function testARepeatedOptionIsAUsageError(): void
    {
        self::assertSame([['grants', 'driver' => 'mysql', 'role' => 'a_gal', 'grants-only' => true, 'db' => ''], null], Tool::parseArguments(['grants', '--driver=mysql', '--role=a_gal', '--grants-only', '--db=']));
        foreach ([['grants', '--role=a', '--role=b'], ['grants', '--grants-only', '--grants-only'], ['grants', '--role=a', '--role'], ['diag', '--user=a@b.c', '--user=a@b.c']] as $argv) {
            [, $problem] = Tool::parseArguments($argv);
            self::assertIsString($problem, implode(' ', $argv));
            self::assertMatchesRegularExpression('/^--[a-z-]+ is given twice: give every option once$/', $problem);
        }
        // a value is everything after '=', a line break included (refused later by the option's check)
        self::assertSame("a\nb", Tool::parseArguments(['grants', "--role=a\nb"])[0]['role']);
    }

    /**
     * @return array<string, array{string, null|string}>
     */
    public static function clientCommandSamples(): array
    {
        return [
            'statements' => ["GRANT SELECT (a) ON t TO 'x'@'y';\nDO \$\$\nBEGIN\n    IF x THEN\n    END IF;\nEND\n\$\$;\nUSER_ID();", null],
            'a comment that names a command' => ["-- system rm -rf /\n-- source /etc/passwd\nSELECT 1;", null],
            'a backslash in a comment' => ["-- printf '%s\\n'\nSELECT 1;", 'a backslash'],
            'psql meta-command' => ["SELECT 1;\n\\! id", 'a backslash'],
            'mysql system' => ["SELECT 1;\nsystem id", 'the client command "system" (line 2)'],
            'mysql source, indented and upper case' => ["SELECT 1;\n  SOURCE /tmp/x.sql", 'the client command "source" (line 2)'],
            'mysql delimiter' => ["delimiter //\nSELECT 1//", 'the client command "delimiter" (line 1)'],
            'mysql use' => ["use mysql;\nSELECT 1;", 'the client command "use" (line 1)'],
            'mysql ?' => ['? contents', 'the client command "?" (line 1)'],
        ];
    }

    #[DataProvider('clientCommandSamples')]
    public function testClientCommandsAreFound(string $sql, ?string $expected): void
    {
        self::assertSame($expected, Tool::clientCommand($sql));
    }

    public function testTheTlsRequirementIsReadFromTheAccountStatement(): void
    {
        // placeholder hashes: the statement is never printed, quoted strings never count
        foreach ([
            "CREATE USER `gal`@`%` IDENTIFIED BY PASSWORD '*0000000000000000000000000000000000000000' REQUIRE SSL" => true,
            "CREATE USER 'gal'@'%' IDENTIFIED WITH 'mysql_native_password' AS '*0000000000000000000000000000000000000000' REQUIRE X509 PASSWORD EXPIRE DEFAULT" => true,
            "GRANT USAGE ON *.* TO `gal`@`10.0.0.%` REQUIRE ISSUER '/CN=ca' SUBJECT '/CN=gal'" => true,
            "CREATE USER `gal`@`%` IDENTIFIED BY PASSWORD '*0000000000000000000000000000000000000000' REQUIRE CIPHER 'AES256-SHA'" => true,
            "CREATE USER 'gal'@'%' IDENTIFIED WITH 'mysql_native_password' AS '*0000000000000000000000000000000000000000' REQUIRE NONE PASSWORD EXPIRE DEFAULT" => false,
            "CREATE USER `gal`@`%` IDENTIFIED BY PASSWORD '*0000000000000000000000000000000000000000' WITH MAX_USER_CONNECTIONS 25" => false,
            // the text inside a quoted string or an identifier is no clause
            "CREATE USER `gal REQUIRE SSL`@`%` IDENTIFIED VIA ed25519 USING 'x REQUIRE SSL x'" => false,
            '' => false,
        ] as $statement => $requires) {
            self::assertSame($requires, Tool::requiresTls($statement), $statement);
        }
    }

    public function testTheMysqlViewDefinitionIsJudged(): void
    {
        $good = 'CREATE ALGORITHM=TEMPTABLE DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `galbridge_forwardings` AS select `forwardings`.`address` AS `address`,`forwardings`.`forwarding` AS `forwarding` from `forwardings` where `forwardings`.`is_alias` = 1 or `forwardings`.`is_list` = 1 or `forwardings`.`is_maillist` = 1 limit 18446744073709551615';
        self::assertSame([], Tool::viewDefinitionProblems($good));
        // MySQL names the database as well
        self::assertSame([], Tool::viewDefinitionProblems(str_replace('from `forwardings`', 'from `vmail`.`forwardings`', $good)));
        $merged = str_replace('ALGORITHM=TEMPTABLE', 'ALGORITHM=UNDEFINED', $good);
        self::assertSame(['it is not ALGORITHM=TEMPTABLE: the server may merge it into the queries of the role'], Tool::viewDefinitionProblems($merged));
        $unfenced = str_replace(' limit 18446744073709551615', '', $good);
        self::assertSame(['it is not fenced (no LIMIT 18446744073709551615 at the end): the server may push conditions of the role into it'], Tool::viewDefinitionProblems($unfenced));
        $joined = str_replace('from `forwardings`', 'from `forwardings` join `mailbox` on 1 = 1', $good);
        self::assertSame(['it reads forwardings, mailbox, not the table forwardings alone'], Tool::viewDefinitionProblems($joined));
    }

    public function testTheRefusalsOfARoleNameEveryReason(): void
    {
        $columns = [Schema::FORWARDINGS => Schema::GRANTABLE[Schema::FORWARDINGS], 'mailbox' => Schema::GRANTABLE['mailbox']];
        self::assertSame([], (new RoleScope())->refusals($columns), 'the role of the grant templates');
        self::assertSame([], (new RoleScope(memberships: null, canCreate: true, owned: 3, viewProblems: ['x'], connectable: ['postgres']))->refusals($columns), 'diag-only findings and a server that cannot tell');

        $broad = new RoleScope(
            attributes: ['CREATEDB', 'BYPASSRLS'],
            memberships: ['vmailadmin'],
            databaseOwner: true,
            global: ['PROCESS'],
            outside: ['roundcubemail', 'other.t'],
            changes: ['alias:INSERT'],
        );
        self::assertSame([
            'it can read secret columns (admin.password)',
            'it can read the table "forwardings" (private forwarding targets of every mailbox) instead of the view galbridge_forwardings',
            'it has the role attribute(s) CREATEDB, BYPASSRLS',
            'it is a member of the role(s) vmailadmin (SET ROLE reaches their privileges)',
            'it owns the database',
            'it holds global privileges (PROCESS)',
            'it holds privileges outside the directory (roundcubemail, other.t)',
            'it can change or administer objects (alias:INSERT)',
        ], $broad->refusals($columns + ['admin' => ['username', 'password'], Schema::FORWARDINGS_TABLE => ['forwarding']]));
        // catalog names are sanitised and capped
        self::assertSame(['it is a member of the role(s) a b, r0, r1, r2, r3, r4, r5, r6, ... (2 more) (SET ROLE reaches their privileges)'],
            (new RoleScope(memberships: ["a\x1bb", 'r0', 'r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7', 'r8']))->refusals([]));
    }

    private static function template(string $flavour): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/SQL/grants.' . $flavour . '.sql');
    }

    /** the comment block at the top of a template */
    private static function header(string $flavour): string
    {
        $out = [];
        foreach (explode("\n", self::template($flavour)) as $line) {
            if (!str_starts_with($line, '--')) {
                break;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /** the template without its comment lines */
    private static function statements(string $flavour): string
    {
        return implode("\n", array_filter(explode("\n", self::template($flavour)), static fn (string $l): bool => !str_starts_with(ltrim($l), '--') && trim($l) !== ''));
    }
}
