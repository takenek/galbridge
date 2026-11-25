<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\Limits;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-31: complete-or-refuse and the bounds of lists.
 *  (a) compose to selected and export of selected are complete or refused: a selected ID that is
 *      malformed or finds no entry refuses the selection instead of cutting it silently;
 *  (c) a full set is recognised by core's page sizes (9999, 99999) and the recipient actions - a
 *      browse page above the page bound is a page with a notice, never an export or an expansion;
 *  (d) list totals at galbridge_max_count and search results at the result cap tell the user, and
 *      pages beyond them are empty;
 *  (e) every person of a page gets its per-user aliases (a bound per owner, not per statement);
 *  (f) the page number is clamped (PHP 8.5 warns about an out-of-range float-to-int cast).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2CompletenessTest extends TestCase
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
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testComposeAndExportOfSelectedContactsAreCompleteOrRefused(string $dsn): void
    {
        $jan = self::id(self::JAN);
        $anna = self::id('anna.nowak@example.com');
        // compose to selected (core: set_pagesize(count + 2), search(primary key, IDs, 0, true, true, 'email'))
        foreach ([
            'a disabled mailbox and a malformed ID' => [$jan, $anna, self::id('disabled.user@example.com'), 'u_forged!'],
            'an entry of another tenant' => [$jan, self::id('bob@example.org')],
            'only malformed IDs' => ['21981839', 'u_'],
        ] as $case => $ids) {
            $notices = [];
            $book = self::book($dsn, 'mailto', $notices);
            $book->set_pagesize(count($ids) + 2);
            self::assertSame([], self::emails($book->search($book->primary_key, $ids, 0, true, true, 'email')), $case);
            self::assertSame([['incomplete', []]], $notices, $case);
            self::assertStringContainsString('set_incomplete', self::log(), $case);
            Log::resetOnce();
        }
        // export of selected (core: set_pagesize(count), search('ID', IDs, 1, true, true))
        $notices = [];
        $book = self::book($dsn, 'export', $notices);
        $book->set_pagesize(2);
        self::assertSame([], self::emails($book->search('ID', [$jan, self::id('bob@example.org')], 1, true, true)));
        self::assertSame([['incomplete', []]], $notices);
        self::assertMatchesRegularExpression('/set_incomplete: request refused book=galbridge_domain missing=1 dropped=0/', self::log());

        // a complete selection: every entry, no message
        foreach (['mailto', 'export'] as $action) {
            $notices = [];
            $book = self::book($dsn, $action, $notices);
            $book->set_pagesize(4);
            self::assertSame(['anna.nowak@example.com', self::JAN], self::emails($book->search('ID', [$jan, $anna], 1, true, true, 'email')), $action);
            self::assertSame([], $notices, $action);
        }
        // other actions (showing contacts by their IDs) keep what was found
        $notices = [];
        $book = self::book($dsn, '', $notices);
        self::assertSame([self::JAN], self::emails($book->search('ID', [$jan, self::id('bob@example.org'), 'u_forged!'], 1, true, true)));
        self::assertSame([], $notices);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testABrowsePageAboveTheCoreCapIsAPageNotAnExport(string $dsn): void
    {
        $options = ['max_pagesize' => 0, 'addressbook_pagesize' => 1500, 'galbridge_domain_export' => false, 'galbridge_max_group_members' => 1];
        $notices = [];
        $book = self::book($dsn, 'list', $notices, $options);
        $book->set_page(1);
        $book->set_pagesize(1500);
        $page = $book->list_records();
        self::assertCount(self::BOOK_SIZE, $page->records, 'a page of the list');
        self::assertSame([['pagetoolarge', ['max' => (string) Limits::HARD_PAGE_CAP]]], $notices, 'never exportdisabled');
        // a group page above the bound is a page as well - not a group expansion (group cap 1)
        $notices = [];
        $book->set_group(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż'));
        self::assertSame(['anna.nowak@example.com', self::JAN], self::emails($book->list_records()));
        self::assertSame(['pagetoolarge'], array_column($notices, 0));

        // core's export size is still the whole book under the export policy
        $notices = [];
        $export = self::book($dsn, 'export', $notices, $options);
        $export->set_pagesize(99999);
        self::assertSame([], self::emails($export->list_records(null, 0, true)));
        self::assertSame([['exportdisabled', []]], $notices);

        // the page size core's lists use is addressbook_pagesize, else pagesize
        $limits = Limits::fromConfig(Env::config($dsn, ['max_pagesize' => 200, 'addressbook_pagesize' => null, 'pagesize' => 300]));
        self::assertSame(300, $limits->corePageCap);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testListTotalsAtTheCountBoundTellTheUser(string $dsn): void
    {
        [$apply, $revert] = Fixture::bulkMailboxes(120);
        Fixture::alter($dsn, $apply, $revert);
        $notices = [];
        $book = self::book($dsn, 'list', $notices, ['galbridge_max_count' => 100]);
        $book->set_pagesize(50);
        $book->set_page(2);
        $page = $book->list_records();
        self::assertCount(50, $page->records);
        self::assertSame(100, $page->count, 'the total is capped');
        self::assertSame([['listcapped', ['max' => '100']]], $notices, 'and the user is told');
        $notices = [];
        $book->set_page(3);
        self::assertSame([], $book->list_records()->records, 'beyond the bound: empty');
        self::assertSame([['listcapped', ['max' => '100']]], $notices);
        $notices = [];
        self::assertSame(100, $book->count()->count, 'count() is bounded and silent');
        self::assertSame([], $notices);
        // a book within the bound: no message
        $small = self::book($dsn, 'list', $notices, ['galbridge_max_count' => 1000]);
        $small->set_pagesize(50);
        self::assertSame(self::BOOK_SIZE + 120, $small->list_records()->count);
        self::assertSame([], $notices);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testFilteredPagesStopAtTheResultCap(string $dsn): void
    {
        [$apply, $revert] = Fixture::bulkMailboxes(120);
        Fixture::alter($dsn, $apply, $revert);
        $notices = [];
        $book = self::book($dsn, 'list', $notices, ['galbridge_max_results' => 10]);
        self::assertSame(10, $book->search('name', 'Bulk User', 0, false)->count, 'the count of a search stops at the result cap');
        $book->set_pagesize(5);
        $seen = [];
        foreach ([1 => 5, 2 => 5, 3 => 0] as $pageNo => $expected) {
            $notices = [];
            $book->set_page($pageNo);
            $page = $book->list_records();
            self::assertCount($expected, $page->records, "page {$pageNo}");
            self::assertSame([['toomanyresults', ['max' => '10']]], $notices, "page {$pageNo}");
            array_push($seen, ...self::emails($page));
        }
        self::assertCount(10, array_unique($seen), 'the first 10 matches, each once');
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testEveryPersonOfAPageGetsItsAliases(string $dsn): void
    {
        $rows = [];
        foreach (['postmaster', 'anna.nowak', 'lukasz.slowik', 'zofia.zolkiewska'] as $owner) {
            for ($i = 1; $i <= 201; $i++) {
                $rows[] = sprintf("('many%03d.%s@example.com', '%s@example.com', 'example.com', 'example.com', 1, 1)", $i, str_replace('.', '', $owner), $owner);
            }
        }
        $rows[] = "('many.single@example.com', 'pct_user@example.com', 'example.com', 'example.com', 1, 1)";
        Fixture::alter(
            $dsn,
            ['INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES ' . implode(', ', $rows)],
            ["DELETE FROM forwardings WHERE address LIKE 'many%@example.com'"],
        );
        $book = self::book($dsn, 'list', $notices, ['galbridge_user_aliases' => true]);
        $book->set_pagesize(self::BOOK_SIZE);
        $other = [];
        foreach ($book->list_records()->records as $record) {
            $other[(string) $record['email:work'][0]] = $record['email:other'] ?? [];
        }
        foreach (['postmaster', 'anna.nowak', 'lukasz.slowik', 'zofia.zolkiewska'] as $owner) {
            self::assertCount(20, $other[$owner . '@example.com'] ?? [], $owner);
        }
        self::assertSame(['many.single@example.com'], $other['pct_user@example.com'] ?? null, 'the entry after them keeps its alias');
        self::assertCount(3, $other[self::JAN] ?? []);
        self::assertStringContainsString('aliases_capped', self::log());
        self::assertStringNotContainsString('aliases_skipped', self::log());
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testAnOutOfRangePageNumberIsClamped(string $dsn): void
    {
        $book = self::book($dsn, 'list');
        set_error_handler(static function (int $no, string $message): bool {
            throw new \ErrorException($message, 0, $no);
        });
        try {
            foreach ([PHP_INT_MAX, '1e30', 1e300, -5, 'x'] as $page) {
                $book->set_page($page);
                self::assertGreaterThanOrEqual(1, $book->list_page);
                self::assertLessThanOrEqual(AddressBook::MAX_PAGE, $book->list_page);
                $book->set_pagesize(PHP_INT_MAX);
                self::assertSame(Limits::HARD_PAGE_CAP >= $book->page_size, true);
                $book->set_pagesize(50);
                $result = $book->list_records();
                self::assertLessThanOrEqual(AddressBook::MAX_PAGE * 50, $result->first);
                $book->count();
            }
            self::assertSame(AddressBook::MAX_PAGE, (function (): int {
                $this->set_page(PHP_INT_MAX);
                return $this->list_page;
            })->call($book));
            self::assertSame([], $book->list_records()->records, 'far beyond every list: empty');
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------------------ helpers

    private static function id(string $address): string
    {
        return IdCodec::contactId(IdCodec::PERSON, $address);
    }

    /**
     * @param null|list<array{string, array<string, string>}> $notices
     * @param array<string, mixed>                         $options
     */
    private static function book(string $dsn, string $action = '', ?array &$notices = null, array $options = []): AddressBook
    {
        $book = Env::factory(Env::config($dsn, $options), self::JAN, $action, $notices)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book, self::log());
        return $book;
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
