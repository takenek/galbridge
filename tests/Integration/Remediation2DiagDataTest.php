<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, diag lines of the directory-set findings:
 *  - F-05 / F-16 / F-20 'entries not offered': the rows no book offers because they do not follow
 *    the address rule - every kind (persons, alias accounts, mailing lists, per-user aliases,
 *    hosted domains), counted, with JSON-escaped examples and the row tag of the log;
 *  - F-15 costly full-set limits, F-31 page sizes above the page bound and the window functions of
 *    the server, F-16 department groups of the global book.
 * The Tool runs in-process (its private sections through reflection, as Remediation2DiagOutputTest
 * does); every engine.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DiagDataTest extends TestCase
{
    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        Log::resetOnce();
        $GLOBALS['galbridge_test_log'] = [];
    }

    protected function tearDown(): void
    {
        Fixture::restore();
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testDiagListsEntriesThatAreNotOffered(string $dsn): void
    {
        $out = self::dataSection($dsn);
        CliRun::assertLine($out, 'OK', 'entries not offered', 'none: every active address and hosted domain follows the address rule');

        $person = "diag\u{0001}ctl@example.com";
        Fixture::alter(
            $dsn,
            [
                "INSERT INTO mailbox (username, name, domain, department, maildir) VALUES ('{$person}', 'Diag Person', 'example.com', '', 'example.com/d/i/a/diag1'),"
                    . " ('diag.zo\u{00EB}@example.com', 'Diag Person 2', 'example.com', '', 'example.com/d/i/a/diag2')",
                "INSERT INTO alias (address, name, accesspolicy, domain, created, expired, active) VALUES ('diag#alias@example.com', 'Diag Alias', 'public', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1)",
                'INSERT INTO maillists (address, domain, transport, accesspolicy, maxmsgsize, name, description, mlid, is_newsletter, settings, created, expired, active) VALUES'
                    . " ('diag?list@example.com', 'example.com', 'mlmmj:example.com/diag', 'public', 0, 'Diag List', NULL, '9f8d2e6c-5a7b-4c9d-2e3f-4a5b6c7d8e9f', 0, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 1)",
                "INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES ('diag{x}@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1)",
                "INSERT INTO domain (domain, description, transport, backupmx, settings, created, expired, active) VALUES ('diag_bad.example', 'Diag domain', 'dovecot', 0, '', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1)",
            ],
            [
                "DELETE FROM mailbox WHERE name LIKE 'Diag Person%'",
                "DELETE FROM alias WHERE name = 'Diag Alias'",
                "DELETE FROM maillists WHERE name = 'Diag List'",
                "DELETE FROM forwardings WHERE address = 'diag{x}@example.com'",
                "DELETE FROM domain WHERE description = 'Diag domain'",
            ],
        );
        $out = self::dataSection($dsn);
        CliRun::assertTerminalSafe($out, 'diag output');
        CliRun::assertLine($out, 'WARN', 'entries not offered', '6 (persons 2, alias accounts 1, mailing lists 1, per-user aliases 1, hosted domains 1)');
        // the examples as JSON strings - a control character and a non-ASCII letter escaped - with
        // the tag the log gives the row
        CliRun::assertLine($out, 'INFO', '  persons', '"diag\u0001ctl@example.com" row=' . Log::tag($person));
        CliRun::assertLine($out, 'INFO', '  persons', '"diag.zo\u00eb@example.com" row=' . Log::tag("diag.zo\u{00EB}@example.com"));
        CliRun::assertLine($out, 'INFO', '  alias accounts', '"diag#alias@example.com" row=' . Log::tag('diag#alias@example.com'));
        CliRun::assertLine($out, 'INFO', '  mailing lists', '"diag?list@example.com"');
        CliRun::assertLine($out, 'INFO', '  per-user aliases', '"diag{x}@example.com"');
        CliRun::assertLine($out, 'INFO', '  hosted domains', '"diag_bad.example"');
        self::assertStringNotContainsString("\u{0001}", $out);
    }

    /**
     * MariaDB/MySQL offer an address written with capitals (in lower case); PostgreSQL and SQLite
     * do not - diag says which on each engine.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDiagTellsWhatAnAddressWithCapitalsMeansOnThisEngine(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ["INSERT INTO mailbox (username, name, domain, department, maildir) VALUES ('Diag.Capital@example.com', 'Diag Capital', 'example.com', '', 'example.com/d/i/a/diagc')"],
            ["DELETE FROM mailbox WHERE name = 'Diag Capital'"],
        );
        $out = self::dataSection($dsn);
        if (str_starts_with($dsn, 'mysql:')) {
            CliRun::assertLine($out, 'OK', 'entries not offered');
            CliRun::assertLine($out, 'INFO', 'addresses with capitals', '1 offered in lower case');
        } else {
            CliRun::assertLine($out, 'WARN', 'entries not offered', '1 (persons 1)');
            CliRun::assertLine($out, 'INFO', '  persons', '"Diag.Capital@example.com"');
            self::assertStringNotContainsString('addresses with capitals', $out);
        }
    }

    public function testDiagWarnsAboutCostlyFullSetLimits(): void
    {
        $out = self::coreInterplay([]);
        self::assertStringNotContainsString('one request reads, maps and holds', $out, 'the defaults are not costly');
        $out = self::coreInterplay(['galbridge_max_export' => 50000, 'galbridge_max_group_members' => 20000, 'max_group_members' => 0]);
        CliRun::assertLine($out, 'WARN', 'galbridge_max_export', '50000: one request reads, maps and holds up to 50000 entries (whole-book export)');
        CliRun::assertLine($out, 'WARN', 'group expansion cap', '20000: one request reads, maps and holds up to 20000 entries (group expansion)');
    }

    public function testDiagWarnsAboutPageSizesAboveTheHardCap(): void
    {
        self::assertStringNotContainsString('[WARN] page size', self::coreInterplay(['max_pagesize' => 200, 'addressbook_pagesize' => 50]));
        $out = self::coreInterplay(['max_pagesize' => 0, 'addressbook_pagesize' => 1500]);
        CliRun::assertLine($out, 'WARN', 'page size', 'max_pagesize is 0 (no bound), addressbook_pagesize is 1500: the directory serves at most 1000 entries per page');
        CliRun::assertLine(self::coreInterplay(['max_pagesize' => 5000, 'addressbook_pagesize' => 50]), 'WARN', 'page size', 'max_pagesize is 5000');
    }

    public function testDiagWarnsAboutDepartmentGroupsOfTheGlobalBook(): void
    {
        $global = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        self::assertStringNotContainsString('galbridge_global_groups', self::coreInterplay($global));
        CliRun::assertLine(self::coreInterplay($global + ['galbridge_global_groups' => 'department']), 'WARN', 'galbridge_global_groups', "'department': the number of department groups of the global book depends on the data of every tenant");
        self::assertStringNotContainsString('galbridge_global_groups', self::coreInterplay($global + ['galbridge_global_groups' => 'domain']));
    }

    public function testTheWindowFunctionsOfTheServerAreJudgedByItsVersion(): void
    {
        foreach ([
            ['mariadb', '10.1.48-MariaDB', false], ['mariadb', '10.2.0-MariaDB-log', true], ['mariadb', '11.8.9-MariaDB-0+deb13u1', true],
            ['mariadb', '5.5.5-10.1.2-MariaDB', false], ['mariadb', '5.5.5-10.6.18-MariaDB', true], ['mysql', '5.7.44-log', false],
            ['mysql', '8.0.36', true], ['mysql', '8.4.3', true], ['mysql', '5.5.68-MariaDB', false], ['mysql', 'unknown', true],
        ] as [$flavour, $version, $supported]) {
            self::assertSame($supported, Tool::windowFunctionsSupported($flavour, $version), $flavour . ' ' . $version);
        }
        // the servers of this suite have them: no line
        foreach (Env::engines() as [$dsn]) {
            self::assertStringNotContainsString('server version', self::dataSection($dsn, ['galbridge_user_aliases' => true]));
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The vmail data section of diag (dataSanity()) against the engine.
     *
     * @param array<string, mixed> $options
     */
    private static function dataSection(string $dsn, array $options = []): string
    {
        $config = Env::config($dsn, $options);
        $conn = new Connection(new Dsn($dsn, $config), 3);
        $columns = Connection::directoryColumns($conn->visibleColumnsEverywhere());
        [$tool, $out] = self::tool();
        (new \ReflectionMethod(Tool::class, 'dataSanity'))->invoke($tool, $conn, $config, $columns);
        return self::read($out);
    }

    /**
     * The 'Core interplay' section of diag with these options.
     *
     * @param array<string, mixed> $options
     */
    private static function coreInterplay(array $options): string
    {
        $config = Env::config(Env::sqliteDsn(), $options);
        [$tool, $out] = self::tool();
        (new \ReflectionMethod(Tool::class, 'coreInterplay'))->invoke($tool, $config, \rcube::get_instance());
        return self::read($out);
    }

    /**
     * @return array{0: Tool, 1: resource}
     */
    private static function tool(): array
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        return [new Tool(\rcube::get_instance(), CliRun::pluginDir(), $out), $out];
    }

    /**
     * @param resource $out
     */
    private static function read($out): string
    {
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
