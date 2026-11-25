<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\Limits;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Repository;
use GalBridge\Log;
use GalBridge\Normalizer;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-05 / F-16 / F-20: the SQL of every book offers only canonical rows -
 * the rule the PHP re-check applies (Normalizer::CANONICAL_ADDRESS). A row in another spelling
 * (NFD, a non-ASCII capital, a U-label domain column, a character outside the allow-list) is
 * offered nowhere: not listed, counted, matched, grouped or part of a full set. Before, such rows
 * escaped the SQL deny-lists and were counted and matched while PHP dropped them (an existence
 * oracle), and one of them refused every full set of its scope. A row the re-check still drops
 * (a defect, a race) is refused as before - and named in the log.
 *
 * Throw-away rows only (Support\Fixture); every engine.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2CanonicalRowsTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    /** the domain book of example.com as jan sees it: 7 persons, 5 alias accounts, 1 list */
    private const BOOK_SIZE = 13;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
        self::newRequest();
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
    }

    /**
     * The NFD spelling and a spelling with a non-ASCII capital of a hidden address escaped the SQL
     * hidden list (it compares LOWER(column) with the NFC form); a mailbox whose domain column holds
     * the U-label of an excluded domain escaped the exclusion. They were counted, found and part of
     * the group aggregate; now none of them is offered at all.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testHiddenEntriesInAnotherSpellingAreNeitherCountedNorMatched(string $dsn): void
    {
        $nfd = "ka\u{0308}rin.hidden@example.com";
        $capital = "k\u{00C4}rin.hidden@example.com";
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, domain, department, maildir) VALUES'
                . " ('{$nfd}', 'Hiddenkarin One', 'example.com', 'Sprzedaż', 'example.com/k/a/r/karin1'),"
                . " ('{$capital}', 'Hiddenkarin Two', 'example.com', 'Sprzedaż', 'example.com/k/a/r/karin2')"],
            ["DELETE FROM mailbox WHERE username LIKE '%rin.hidden@example.com'"],
        );
        $options = ['galbridge_hidden_addresses' => ["k\u{00E4}rin.hidden@example.com"]];
        $book = self::book($dsn, $options);
        self::assertSame(0, $book->search('*', 'Hiddenkarin', 0, false)->count, 'not counted');
        self::assertSame([], self::emails($book->search('*', 'Hiddenkarin')), 'not listed');
        foreach ([[1, "k\u{00E4}rin.hidden@example.com"], [2, "k\u{00E4}rin"], [0, 'rin.hidden']] as [$mode, $word]) {
            self::assertSame(0, $book->search('email', $word, $mode, false)->count, "not matched by {$word}");
        }
        // the group aggregate counts what a group delivers, and the group is complete
        $sales = IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż');
        $notices = [];
        $mailto = self::book($dsn, $options, 'mailto', $notices);
        $mailto->set_group($sales);
        self::assertSame(2, $mailto->count()->count);
        self::assertSame(['anna.nowak@example.com', self::JAN], self::emails(self::fullSet($mailto)));
        self::assertSame([], $notices);

        // a domain column holding the U-label of an excluded A-label domain (global book)
        Fixture::alter(
            $dsn,
            ["INSERT INTO domain (domain, description, transport, backupmx, settings, created, expired, active) VALUES ('b\u{00FC}cher.example', 'U-label', 'dovecot', 0, '', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1)",
                "INSERT INTO mailbox (username, name, domain, department, maildir) VALUES ('ulabel@b\u{00FC}cher.example', 'Ulabelrow Person', 'b\u{00FC}cher.example', '', 'x/u/l/a/ulabel')"],
            ["DELETE FROM mailbox WHERE username LIKE 'ulabel@%'", "DELETE FROM domain WHERE description = 'U-label'"],
        );
        $global = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['*'], 'galbridge_global_allow_wildcard' => true,
            'galbridge_global_data_domains_exclude' => ['xn--bcher-kva.example'], 'galbridge_global_searchonly' => false];
        $book = self::book($dsn, $global, '', $notices, BookDefinition::GLOBAL);
        self::assertSame(0, $book->search('*', 'Ulabelrow', 0, false)->count, 'not counted');
        self::assertSame([], self::emails($book->search('*', 'Ulabelrow')));
        self::assertSame(1, $book->search('*', 'Bob', 0, false)->count, 'the book itself works');
        self::assertStringNotContainsString('row_out_of_scope', self::log(), 'nothing was dropped by PHP: SQL left the rows out');
    }

    /**
     * A per-user alias in another spelling (NFD) of a hidden address escaped the hidden list of the
     * alias rows: a search for a fragment of it found its owner.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testPerUserAliasInAnotherSpellingFindsNoOwner(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ['INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES'
                . " ('ale\u{0301}ias.secret@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),"
                . " ('Capital.Alias@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1)"],
            ["DELETE FROM forwardings WHERE address LIKE '%ias.secret@example.com' OR address = 'Capital.Alias@example.com'"],
        );
        $book = self::book($dsn, ['galbridge_user_aliases' => true, 'galbridge_hidden_addresses' => ["al\u{00E9}ias.secret@example.com"]]);
        self::assertSame(0, $book->search('email', 'ias.secret', 0, false)->count, 'the owner is not found by the alias');
        self::assertSame([], self::emails($book->search('email', 'ias.secret')));
        $record = $book->get_record(IdCodec::contactId(IdCodec::PERSON, self::JAN), true);
        self::assertIsArray($record);
        $other = $record['email:other'] ?? [];
        self::assertIsArray($other);
        self::assertNotContains("al\u{00E9}ias.secret@example.com", $other, 'not shown');
        // an alias written with capitals: shown and found on MariaDB/MySQL (in lower case), on
        // neither on PostgreSQL / SQLite - but the two always agree
        $mysql = str_starts_with($dsn, 'mysql:');
        self::assertSame($mysql, in_array('capital.alias@example.com', $other, true));
        self::assertSame($mysql ? 1 : 0, $book->search('email', 'capital.alias', 0, false)->count);
    }

    /**
     * Rows that only the PHP re-check rejected - a double dot in the local part (Roundcube's e-mail
     * check refuses it), an address that does not belong to its domain column - refused every full
     * set of their scope ('incomplete') and were counted in lists and groups. They are not offered
     * now, so exports and group expansions are complete and every count is what is delivered.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testARowOnlyThePhpCheckRejectedNoLongerBlocksFullSets(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, domain, department, maildir) VALUES'
                . " ('double..dot@example.com', 'Double Dot', 'example.com', 'Sprzedaż', 'example.com/d/o/u/double'),"
                . " ('other.domain@example.org', 'Other Domain', 'example.com', 'Sprzedaż', 'example.com/o/t/h/other')"],
            ["DELETE FROM mailbox WHERE username IN ('double..dot@example.com', 'other.domain@example.org')"],
        );
        $notices = [];
        $book = self::book($dsn, [], 'export', $notices);
        self::assertCount(self::BOOK_SIZE, self::fullSet($book)->records, 'the whole book is exported');
        self::assertSame([], $notices);

        $sales = IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż');
        foreach ([['mailto', 999], ['group-expand', 9999], ['export', 99999]] as [$action, $size]) {
            self::newRequest();
            $book = self::book($dsn, [], $action, $notices);
            $book->set_group($sales);
            self::assertSame(2, $book->count()->count, $action . ': the group count');
            $book->set_page(1);
            $book->set_pagesize($size);
            self::assertSame(['anna.nowak@example.com', self::JAN], self::emails($book->list_records(null, 0, true)), $action);
            self::assertSame([], $notices, $action);
        }
        $book = self::book($dsn);
        $book->set_pagesize(200);
        $list = $book->list_records();
        self::assertSame(count($list->records), $list->count, 'the list total is what is listed');
        self::assertStringNotContainsString('set_incomplete', self::log());
        self::assertStringNotContainsString('row_invalid', self::log());
    }

    /**
     * A row the re-check drops although SQL offered it - here because the mapper has a hidden list
     * the SQL does not (a defect) - still refuses the full set, and the log names the row by a
     * non-reversible tag (the tag diag prints next to such an entry).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAGenuineDisagreementIsStillRefused(string $dsn): void
    {
        $config = Env::config($dsn);
        $factory = Env::factory($config, self::JAN);
        $viewer = $factory->resolver()->resolve();
        self::assertNotNull($viewer);
        $book = $factory->books()[BookDefinition::DOMAIN] ?? null;
        self::assertInstanceOf(BookDefinition::class, $book);
        $connection = new Connection(new Dsn($dsn, $config), 3);
        $fields = FieldMap::fromConfig($config, $viewer);
        $queries = QueryBuilder::forBook($config, $connection->driver(), $book, $viewer, $fields);
        $victim = 'anna.nowak@example.com';
        $mapper = new RecordMapper($book, $fields, [$victim], []);
        $repository = new Repository($connection, $queries, $mapper, $fields, 'none', 50);
        $notices = [];
        $notify = static function (string $label, array $vars) use (&$notices): void {
            $notices[] = [$label, $vars];
        };
        $export = new AddressBook($book, $repository, $queries, $fields, Limits::fromConfig($config), $config->fingerprint(), 'export', static fn (string $l): string => $l, $notify);
        self::assertSame([], self::fullSet($export)->records);
        self::assertSame([['incomplete', []]], $notices);
        self::assertMatchesRegularExpression('/WARN limits: set_incomplete: request refused book=galbridge_domain dropped=1/', self::log());
        self::assertStringContainsString('ERROR scope: row_out_of_scope: row outside the book scope or on a hidden list dropped (please report) book=galbridge_domain row=' . Log::tag($victim), self::log());
        self::assertStringNotContainsString($victim, self::log(), 'the tag, never the address');
    }

    /**
     * The cross-engine guard for the renderings of the rule: in a fixture domain, one throw-away
     * mailbox per class of character; the set a book lists (and finds) is exactly the rows whose raw
     * address the PHP rule accepts - on MariaDB/MySQL in its lower-case form (that collation finds a
     * row written with capitals by its ID), on PostgreSQL and SQLite as written.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testEveryEngineOffersExactlyTheCanonicalRows(string $dsn): void
    {
        $locals = [
            'canonq.plain', 'canonq_under', 'canonq+plus', 'canonq=equals=', 'canonq-dash', 'canonq.' . str_repeat('x', 57),
            'Canonq.Capital', "canonq.\u{00E9}", "canonq.\u{212A}", 'canonq&amp', 'canonq#hash', 'canonq?q', 'canonq%pct', 'canonq{x}',
            'canonq/slash', 'canonq$dollar', 'canonq!bang', 'canonq*star', 'canonq^hat', 'canonq|bar', 'canonq`tick', 'canonq~tilde',
            "canonq''quote", 'canonq..double', 'canonq.trailing.', '.canonq.leading', 'canonq.' . str_repeat('y', 58),
        ];
        $rows = [];
        $expected = [];
        $mysql = str_starts_with($dsn, 'mysql:');
        foreach ($locals as $i => $local) {
            $address = $local . '@example.com';
            $rows[] = sprintf("('%s', 'Canonq Row %02d', 'example.com', '', 'example.com/c/a/n/canonq%02d')", $address, $i, $i);
            $raw = str_replace("''", "'", $address);
            $canonical = Normalizer::canonicalAddress($raw);
            if ($canonical !== null && ($mysql || $canonical === $raw)) {
                $expected[] = $canonical;
            }
        }
        Fixture::alter($dsn, ['INSERT INTO mailbox (username, name, domain, department, maildir) VALUES ' . implode(', ', $rows)], ["DELETE FROM mailbox WHERE name LIKE 'Canonq Row %'"]);
        sort($expected);
        self::assertGreaterThanOrEqual(6, count($expected));

        $book = self::book($dsn);
        $book->set_pagesize(200);
        $listed = array_values(array_filter(self::emails($book->list_records()), static fn (string $a): bool => stripos($a, 'canonq') !== false));
        self::assertSame($expected, $listed, 'listed');
        self::assertSame($expected, self::emails($book->search('name', 'Canonq Row')), 'found');
        self::assertSame(count($expected), $book->search('name', 'Canonq Row', 0, false)->count, 'counted');
        foreach ($expected as $address) {
            self::assertIsArray($book->get_record(IdCodec::contactId(IdCodec::PERSON, $address), true), $address . ' by its ID');
        }
        self::assertStringNotContainsString('row_invalid', self::log());
        self::assertStringNotContainsString('row_out_of_scope', self::log());
    }

    // ------------------------------------------------------------------ helpers

    private static function newRequest(): void
    {
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    /**
     * @param array<string, mixed>                         $options
     * @param null|list<array{string, array<string, string>}> $notices
     */
    private static function book(string $dsn, array $options = [], string $action = '', ?array &$notices = null, string $id = BookDefinition::DOMAIN): AddressBook
    {
        $book = Env::factory(Env::config($dsn, $options), self::JAN, $action, $notices)->create($id);
        self::assertInstanceOf(AddressBook::class, $book, self::log());
        return $book;
    }

    private static function fullSet(AddressBook $book): \rcube_result_set
    {
        $book->set_page(1);
        $book->set_pagesize(99999);
        return $book->list_records(null, 0, true);
    }

    /**
     * @return list<string>
     */
    private static function emails(\rcube_result_set $rs): array
    {
        $out = [];
        foreach ($rs->records as $r) {
            $out[] = (string) ($r['email:work'][0] ?? '');
        }
        sort($out);
        return $out;
    }

    private static function log(): string
    {
        return implode("\n", $GLOBALS['galbridge_test_log'] ?? []);
    }
}
