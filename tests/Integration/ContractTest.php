<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\Limits;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Viewer;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * The rcube_addressbook contract of Roundcube 1.7, method by method, against the production
 * wiring (Config -> ViewerResolver -> Factory -> AddressBook) on every engine (SQLite mirror,
 * MariaDB/MySQL and PostgreSQL with the role built from SQL/grants.*.sql).
 *
 * The viewer is jan.kowalski@example.com (tenant example.com, department "Sprzedaż"). The expected
 * visibility comes from the comments in tests/fixtures/vmail/seed.sql: 7 persons, 5 alias accounts
 * and 1 mailing list in his domain book; departments IT (1), Księgowość (1), Sprzedaż (2).
 */
final class ContractTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private const BOB = 'bob@example.org';

    /** jan's domain book (persons + alias accounts + lists), sorted */
    private const JAN_BOOK = [
        'anna.nowak@example.com',
        'board@example.com',
        'everyone@example.com',
        'forwarder@example.com',
        'internal@example.com',
        'jan.kowalski@example.com',
        'legacy@example.com',
        'lukasz.slowik@example.com',
        'newsletter@example.com',
        'pct_user@example.com',
        'postmaster@example.com',
        'team@example.com',
        'zofia.zolkiewska@example.com',
    ];

    /** persons of jan's book with a non-empty department */
    private const WITH_DEPARTMENT = ['anna.nowak@example.com', 'jan.kowalski@example.com', 'lukasz.slowik@example.com', 'zofia.zolkiewska@example.com'];

    /** values that must never leave the database (seed.sql canaries) */
    private const CANARIES = ['SECRET-', 'jan.private@gmail.test', 'private.destination@gmail.test', 'jan.home@outlook.test', '192.0.2.', 'E001', 'timezone:'];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube (GALBRIDGE_RC)');
        }
        Log::resetOnce();
        $GLOBALS['galbridge_test_log'] = [];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A book as rcmail::get_address_book() hands it out (including its set_sort_order() call).
     *
     * @param array<string, mixed>                       $options
     * @param list<array{string, array<string, string>}> $notices
     */
    private static function book(string $dsn, array $options = [], string $login = self::JAN, string $action = '', ?array &$notices = null, string $source = BookDefinition::DOMAIN): \rcube_addressbook
    {
        $book = Env::factory(Env::config($dsn, $options), $login, $action, $notices)->create($source);
        $book->set_sort_order((string) \rcube::get_instance()->config->get('addressbook_sort_col'));
        return $book;
    }

    /**
     * A factory whose Viewer comes from the session cache (warmed by a first request) and whose
     * connection counts connects: a path that never queries keeps $connects at 0.
     *
     * @param array<string, mixed> $options
     */
    private static function coldFactory(string $dsn, array $options, int &$connects, string $login = self::JAN): Factory
    {
        $config = Env::config($dsn, $options);
        Env::factory($config, $login)->resolver()->resolve(); // fills $_SESSION['galbridge']
        $connects = 0;
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3, static function (string $effective) use (&$connects): \rcube_db {
                $connects++;
                return \rcube_db::factory($effective, '', false);
            });
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function records(?\rcube_result_set $rs): array
    {
        $out = [];
        if ($rs !== null) {
            foreach ($rs as $record) {
                $out[] = $record;
            }
        }
        return $out;
    }

    /**
     * First e-mail address of every record (in result order, or sorted).
     *
     * @return list<string>
     */
    private static function addresses(?\rcube_result_set $rs, bool $sorted = true): array
    {
        $out = [];
        foreach (self::records($rs) as $record) {
            $out[] = (string) (\rcube_addressbook::get_col_values('email', $record, true)[0] ?? '');
        }
        if ($sorted) {
            sort($out);
        }
        return $out;
    }

    private static function id(string $address, string $kind = IdCodec::PERSON): string
    {
        return IdCodec::contactId($kind, $address);
    }

    private static function sprzedaz(string $tag = 'd'): string
    {
        return IdCodec::groupId($tag, IdCodec::GROUP_DEPARTMENT, 'sprzedaż');
    }

    /**
     * @param list<string> $list
     */
    private static function pos(array $list, string $address): int
    {
        $i = array_search($address, $list, true);
        self::assertIsInt($i, $address . ' missing from ' . implode(', ', $list));
        return $i;
    }

    private static function assertNoNulls(mixed $value, string $path): void
    {
        self::assertNotNull($value, 'null value at ' . $path);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::assertNoNulls($v, $path . '.' . $k);
            }
        }
    }

    /**
     * @param array<string, mixed>                $record
     * @param array<string, array<string, mixed>> $coltypes
     */
    private static function assertRecordShape(array $record, array $coltypes): void
    {
        $label = json_encode($record, JSON_UNESCAPED_UNICODE) ?: '?';
        self::assertIsString($record['ID'] ?? null, $label);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]{1,800}$/D', $record['ID'], $label);
        self::assertNotNull(IdCodec::decodeContact($record['ID']), $label);
        self::assertIsString($record['name'] ?? null, $label);
        self::assertNotSame('', $record['name'], $label);
        self::assertSame('person', $record['_type'] ?? null, $label);
        self::assertIsList($record['email:work'] ?? null, $label);
        self::assertNotEmpty($record['email:work'], $label);
        foreach ($record['email:work'] as $email) {
            self::assertIsString($email, $label);
            self::assertSame(strtolower($email), $email, $label);
            self::assertTrue(\rcube_utils::check_email($email, false), $label);
        }
        foreach (['vcard', 'photo', 'sourceid', 'groups', 'edit', 'email', 'readonly'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $record, $label);
        }
        foreach ($record as $key => $value) {
            $base = explode(':', (string) $key, 2)[0];
            if ($key === 'ID' || $key === '_type') {
                continue;
            }
            self::assertArrayHasKey($base, $coltypes, 'record key ' . $key . ' not declared in coltypes');
            if (str_contains((string) $key, ':')) {
                self::assertIsList($value, $label);
            } else {
                self::assertIsString($value, $label);
            }
        }
        self::assertNoNulls($record, (string) $record['ID']);
        foreach (self::CANARIES as $canary) {
            self::assertStringNotContainsString($canary, $label);
        }
    }

    // ------------------------------------------------------------------ source listing

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSourceListingInvariants(string $dsn): void
    {
        $options = [
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['example.org', 'xn--bcher-kva.example'],
        ];
        $factory = Env::factory(Env::config($dsn, $options), self::JAN);
        $books = $factory->books();
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys($books));

        foreach ($books as $id => $definition) {
            // core splits "ID-source" at the last '-' (PHP) and the first (JS); reserved IDs; 'G'/'S' prefixes
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $id);
            self::assertNotContains($id, ['0', '1', '2', '4', '-1', 'sql']);
            self::assertTrue(Factory::isOwnId($id));
            self::assertNotSame('', $definition->name);

            // every listed source resolves to a usable instance (a missing one is core error 700)
            $book = $factory->create($id);
            self::assertInstanceOf(AddressBook::class, $book);
            self::assertTrue($book->ready);
            self::assertTrue($book->readonly);
            self::assertSame('ID', $book->primary_key);
            self::assertFalse($book->undelete);
            self::assertFalse($book->export_groups, 'export would call get_record_groups() once per record');
            self::assertTrue(!property_exists($book, 'deletable') || empty($book->deletable), 'deletable bypasses the read-only check in delete/move');
            self::assertSame($definition->name, $book->get_name());
            self::assertSame($factory->grouping($definition) !== 'none', $book->groups);
            self::assertArrayHasKey('name', $book->coltypes);
            self::assertArrayHasKey('email', $book->coltypes);
            // the read-only base methods stay refused
            self::assertFalse($book->insert(['name' => 'x', 'email' => 'x@example.com']));
            self::assertFalse($book->update(self::id(self::JAN), ['name' => 'x']));
            self::assertFalse($book->delete([self::id(self::JAN)]));
            self::assertFalse($book->create_group('x'));
        }

        // the scope belongs to the login, never to a request parameter
        self::assertSame(['example.com'], $books[BookDefinition::DOMAIN]->domains);
        self::assertSame(['example.org', 'xn--bcher-kva.example'], $books[BookDefinition::GLOBAL]->domains);
        $bob = Env::factory(Env::config($dsn, $options), 'bob@example.org')->books();
        self::assertSame(['example.org'], $bob[BookDefinition::DOMAIN]->domains);
        self::assertSame(['xn--bcher-kva.example'], $bob[BookDefinition::GLOBAL]->domains, 'the home domain is left out of the global book');

        // core and foreign source IDs are never claimed by the plugin
        foreach (['0', '1', '2', '4', '-1', 'sql', 'domain', 'global', '', 'galbridge_domain-x', 'GALBRIDGE_DOMAIN'] as $foreign) {
            self::assertFalse(Factory::isOwnId($foreign), $foreign);
        }

        // no entitlement: nothing listed, yet every own ID still gets an instance (fail closed)
        $nobody = Env::factory(Env::config($dsn, $options), 'nobody@example.com');
        self::assertSame([], $nobody->books());
        self::assertInstanceOf(DeniedBook::class, $nobody->create(BookDefinition::DOMAIN));
        self::assertInstanceOf(DeniedBook::class, $nobody->create(BookDefinition::GLOBAL));
    }

    // ------------------------------------------------------------------ list_records / count

    #[DataProviderExternal(Env::class, 'engines')]
    public function testListRecordsPagingCountAndFirst(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_sort_order('name');
        $book->set_pagesize(50);
        $full = $book->list_records();
        self::assertSame(13, $full->count);
        self::assertSame(0, $full->first);
        $all = self::addresses($full, false);
        self::assertSame(self::JAN_BOOK, self::addresses($full));
        self::assertSame($full, $book->get_result());

        $book->set_pagesize(5);
        $seen = [];
        foreach ([1 => [0, 5], 2 => [5, 5], 3 => [10, 3]] as $page => [$first, $rows]) {
            $book->set_page($page);
            $rs = $book->list_records();
            self::assertSame(13, $rs->count, 'count is the total, not the page size');
            self::assertSame($first, $rs->first);
            self::assertSame(array_slice($all, $first, $rows), self::addresses($rs, false), 'page ' . $page);
            self::assertSame($rs, $book->get_result());
            $seen = array_merge($seen, self::addresses($rs, false));
        }
        self::assertSame($all, $seen, 'pages are disjoint and complete, in a stable order');

        // beyond the last page: no rows, the total stays
        $book->set_page(4);
        $rs = $book->list_records();
        self::assertSame([], self::records($rs));
        self::assertSame(13, $rs->count);
        self::assertSame(15, $rs->first);

        // $subset: the first / last N rows of the current page
        $book->set_page(1);
        self::assertSame(array_slice($all, 0, 2), self::addresses($book->list_records(null, 2), false));
        self::assertSame(array_slice($all, 3, 2), self::addresses($book->list_records(null, -2), false));
        self::assertSame(13, $book->list_records(null, 2)->count);

        // $nocount: the count is downgraded to the rows returned (page 1, as core uses it)
        $rs = $book->list_records(null, 0, true);
        self::assertSame(5, $rs->count);
        self::assertCount(5, self::records($rs));

        // $cols is advisory: the full display record comes back
        $rs = $book->list_records(['name', 'email']);
        self::assertSame(array_slice($all, 0, 5), self::addresses($rs, false));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testPageAndPageSizeAreClamped(string $dsn): void
    {
        $book = self::book($dsn);
        foreach ([0, -3, '0', 'abc', '', null, max(1, 'abc')] as $page) {
            $book->set_page($page);
            self::assertSame(1, $book->list_page, var_export($page, true));
        }
        $book->set_page('2');
        self::assertSame(2, $book->list_page);

        foreach ([0, -1, 'abc', null] as $size) {
            $book->set_pagesize($size);
            self::assertGreaterThanOrEqual(1, $book->page_size, var_export($size, true));
        }
        $book->set_page(1);
        $book->set_pagesize(0);
        $rs = $book->list_records();
        self::assertCount(1, self::records($rs));
        self::assertSame(13, $rs->count);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testCountHonoursScopeFilterGroupAndPage(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(5);
        $book->set_page(2);
        $rs = $book->count();
        self::assertInstanceOf(\rcube_result_set::class, $rs);
        self::assertSame(13, $rs->count);
        self::assertSame(5, $rs->first);
        self::assertSame([], $rs->records, 'count() returns no records');

        $book->set_page(1);
        $book->search('*', 'kowal', 0, false);
        self::assertSame(1, $book->count()->count);

        $book->reset();
        $book->set_group(self::sprzedaz());
        self::assertSame(2, $book->count()->count);
        $book->set_group(0);
        self::assertSame(13, $book->count()->count);
    }

    // ------------------------------------------------------------------ sorting

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSortColumnsWithEmptyFieldFallbacks(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);

        // core's default addressbook_sort_col; open-source iRedAdmin fills only mailbox.name
        $book->set_sort_order('surname');
        $surname = self::addresses($book->list_records(), false);
        self::assertSame(self::JAN_BOOK, self::sorted($surname));
        self::assertSame(0, self::pos($surname, 'pct_user@example.com'), 'no surname: falls back to the name "100% Wildcard_Test"');
        self::assertLessThan(self::pos($surname, 'anna.nowak@example.com'), self::pos($surname, 'jan.kowalski@example.com'), 'Kowalski < Nowak');
        self::assertLessThan(self::pos($surname, 'postmaster@example.com'), self::pos($surname, 'anna.nowak@example.com'), 'Nowak < Postmaster (name fallback)');
        self::assertLessThan(self::pos($surname, 'jan.kowalski@example.com'), self::pos($surname, 'forwarder@example.com'), '"Forward Only" (name fallback) < Kowalski');

        $book->set_sort_order('firstname');
        $firstname = self::addresses($book->list_records(), false);
        $order = ['anna.nowak@example.com', 'forwarder@example.com', 'jan.kowalski@example.com', 'postmaster@example.com', 'zofia.zolkiewska@example.com'];
        $positions = array_map(static fn (string $a): int => self::pos($firstname, $a), $order);
        $sortedPositions = $positions;
        sort($sortedPositions);
        self::assertSame($sortedPositions, $positions, 'Anna < Forward Only (name fallback) < Jan < Postmaster < Zofia');

        $book->set_sort_order('name');
        $name = self::addresses($book->list_records(), false);
        self::assertLessThan(self::pos($name, 'jan.kowalski@example.com'), self::pos($name, 'anna.nowak@example.com'));
        self::assertLessThan(self::pos($name, 'internal@example.com'), self::pos($name, 'everyone@example.com'), 'alias accounts sort by their name');
        self::assertLessThan(self::pos($name, 'postmaster@example.com'), self::pos($name, 'newsletter@example.com'), 'mailing lists sort by their name');

        // core never sets an order, but set_sort_order() accepts DESC
        $book->set_sort_order('name', 'DESC');
        self::assertSame(array_reverse($name), self::addresses($book->list_records(), false));

        // a coltypes key that is not a sort key falls back to the name order (no SQL error)
        $book->set_sort_order('department', 'ASC');
        self::assertSame($name, self::addresses($book->list_records(), false));
        self::assertEmpty($book->get_error());
        // a column outside coltypes is ignored by set_sort_order()
        $book->set_sort_order('password');
        self::assertSame($name, self::addresses($book->list_records(), false));
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    private static function sorted(array $list): array
    {
        sort($list);
        return $list;
    }

    // ------------------------------------------------------------------ search

    #[DataProviderExternal(Env::class, 'engines')]
    public function testQuickSearchFieldsWordsAndModes(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        $listFields = ['name', 'firstname', 'surname', 'email']; // core contactlist_fields
        $jan = ['jan.kowalski@example.com'];
        $anna = ['anna.nowak@example.com'];

        $cases = [
            // '*' = every searchable field (department included)
            ['*', 'kowalski', 0, $jan],
            ['*', 'księgow', 0, ['zofia.zolkiewska@example.com']],
            [$listFields, 'nowak', 0, $anna],
            [['email'], 'anna.nowak', 0, $anna],
            ['email', 'JAN.KOWALSKI@EXAMPLE.COM', 1, $jan],            // contact_exists(): a string field, strict
            // unknown fields are ignored in a quick search; none left = nothing
            [['name', 'password'], 'kowalski', 0, $jan],
            [['password', 'settings'], 'kowalski', 0, []],
            // several words: every word must match some field; a short word narrows as a word prefix
            [$listFields, 'jan k', 0, $jan],
            [$listFields, 'Jan Kowalski', 0, $jan],
            [$listFields, 'kowalski jan', 0, $jan],
            [$listFields, 'anna n', 0, $anna],
            [$listFields, 'jan x', 0, []],
            [$listFields, 'jan nowak', 0, []],
            // modes: partial %w%, prefix w% / word start, strict = whole value
            [$listFields, 'owa', 0, ['anna.nowak@example.com', 'jan.kowalski@example.com']],
            [$listFields, 'owa', 2, []],
            [$listFields, 'owa', 1, []],
            [$listFields, 'kow', 2, $jan],
            [$listFields, 'an', 2, $anna],
            [$listFields, 'an', 0, ['anna.nowak@example.com', 'jan.kowalski@example.com']],
            [$listFields, 'kowalski', 1, $jan],
            [$listFields, 'kowal', 1, []],
            [$listFields, 'jan kowalski', 1, $jan],
            [['email'], 'jan', 1, []],
            [['email'], 'jan.kowalski@example.com', 1, $jan],
            [['name'], 'sprz', 0, ['team@example.com']],                  // alias accounts match on their name
            ['*', 'example', 0, self::JAN_BOOK],                         // the domain book matches domain fragments
            ['*', 'example', 2, []],
        ];
        foreach ($cases as $i => [$fields, $value, $mode, $expected]) {
            $label = 'case ' . $i . ': ' . json_encode([$fields, $value, $mode], JSON_UNESCAPED_UNICODE);
            $rs = $book->search($fields, $value, $mode);
            self::assertSame($expected, self::addresses($rs), $label);
            self::assertSame(count($expected), $rs->count, $label);
            self::assertSame($rs, $book->get_result(), $label);
            // core always ORs SEARCH_GROUPS into the mode (autocomplete, contact and compose search)
            $grouped = $book->search($fields, $value, $mode | \rcube_addressbook::SEARCH_GROUPS);
            self::assertSame($expected, self::addresses($grouped), $label . ' | SEARCH_GROUPS');
        }
        self::assertEmpty($book->get_error());

        // the global book: prefix mode whatever the mode, local parts only, every word >= the minimum
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org', 'xn--bcher-kva.example']];
        $global = self::book($dsn, $options, self::JAN, '', $n, BookDefinition::GLOBAL);
        $global->set_pagesize(50);
        $bob = ['bob@example.org'];
        $cases = [
            ['*', 'smith', 0, $bob],
            ['*', 'mith', 0, []],
            ['*', 'bo', 0, $bob],
            ['*', 'bob s', 0, []],
            ['*', 'bob smith', 0, $bob],
            ['*', 'example', 0, []],
            ['*', 'example.org', 0, []],
            ['*', 'org', 0, ['orglist@example.org', 'orgteam@example.org']],
            [['email'], 'bob@example.org', 1, $bob],
            [['email'], 'bob', 1, []],
            [['email'], 'smith', 0, []],
        ];
        foreach ($cases as $i => [$fields, $value, $mode, $expected]) {
            self::assertSame($expected, self::addresses($global->search($fields, $value, $mode | \rcube_addressbook::SEARCH_GROUPS)), 'global case ' . $i);
        }
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSearchSelectAndNocount(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(3);

        // $select = false: a count-only result (contacts/search.php counts first)
        $rs = $book->search('*', 'example', 0, false);
        self::assertSame(13, $rs->count);
        self::assertSame([], $rs->records);
        // ... and the filter is kept for the following list_records()
        $page = $book->list_records();
        self::assertSame(13, $page->count);
        self::assertCount(3, self::records($page));

        // $nocount = true: the count is the number of rows returned
        $rs = $book->search('*', 'example', 0, true, true);
        self::assertSame(3, $rs->count);
        self::assertCount(3, self::records($rs));
        $rs = $book->search('*', 'example', 0, true, false);
        self::assertSame(13, $rs->count);
        self::assertCount(3, self::records($rs));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testAdvancedSearchIsAndAndRejectsUnsupportedFields(string $dsn): void
    {
        $book = self::book($dsn, ['galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'phone', 'mobile', 'birthday']]);
        $book->set_pagesize(50);
        $cases = [
            [['firstname', 'surname'], ['anna', 'nowak'], ['anna.nowak@example.com']],
            [['name', 'department'], ['jan', 'sprzeda'], ['jan.kowalski@example.com']],
            [['department'], ['sprzeda'], ['anna.nowak@example.com', 'jan.kowalski@example.com']],
            [['name', 'department'], ['anna', 'it'], []],                     // AND, not OR
            [['phone'], ['600'], ['jan.kowalski@example.com']],
            [['name', 'email'], ['jan', ''], ['jan.kowalski@example.com']],    // empty values are skipped
            [['name', 'email'], ['', ''], []],                                 // nothing to match: never everything
            [['name', 'notes'], ['jan', 'x'], []],                             // unsupported field = nothing
            [['name', 'organization'], ['jan', 'x'], []],                      // not an effective field here
            [['name', 'birthday'], ['jan', '1980-05-17'], []],                // nosearch field = nothing
            [['birthday'], ['1980-05-17'], []],
            [['name', 'password'], ['jan', 'x'], []],
        ];
        foreach ($cases as $i => [$fields, $values, $expected]) {
            $label = 'case ' . $i . ': ' . json_encode([$fields, $values]);
            $rs = $book->search($fields, $values, 0 | \rcube_addressbook::SEARCH_GROUPS, false);
            self::assertSame(count($expected), $rs->count, $label);
            self::assertSame($expected, self::addresses($book->list_records()), $label);
        }
        self::assertTrue($book->coltypes['birthday']['nosearch'] ?? false, 'the advanced form must not offer birthday');
        self::assertEmpty($book->get_error());
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testEmptyTooShortAndTooLongInputNeverQueries(string $dsn): void
    {
        $connects = 0;
        $book = self::coldFactory($dsn, [], $connects)->create(BookDefinition::DOMAIN);
        $book->set_pagesize(50);
        foreach (['', '   ', "\t\n", 'a', 'k', '%', str_repeat('a', 129), str_repeat('kowalski ', 20), 'a b c'] as $value) {
            // strict mode matches the whole value: 'a b c' is then one word of five characters
            foreach ($value === 'a b c' ? [0, 2] : [0, 1, 2] as $mode) {
                $rs = $book->search('*', $value, $mode | \rcube_addressbook::SEARCH_GROUPS);
                self::assertSame(0, $rs->count, json_encode([$value, $mode]) ?: '');
                self::assertSame([], self::records($rs));
            }
        }
        self::assertSame(0, $book->search(['name', 'email'], ['a', ''], 0)->count);
        self::assertSame(0, $book->search('*', null)->count);
        self::assertSame(0, $book->search('*', ['x' => ['y']])->count);
        self::assertSame(0, $book->search(null, 'kowalski')->count);
        self::assertSame(0, $book->search([], 'kowalski')->count);
        self::assertSame(0, $connects, 'empty, too short or too long input must not reach the database');

        // the documented limits themselves: 2 characters and 128 characters are searched
        self::assertSame(['anna.nowak@example.com', 'jan.kowalski@example.com'], self::addresses($book->search('*', 'an', 0)));
        self::assertSame(0, $book->search('*', str_repeat('a', 128), 0)->count);
        self::assertSame(1, $connects);
        self::assertEmpty($book->get_error());
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testLikeWildcardsInInputAreLiterals(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        $pct = ['pct_user@example.com'];
        // [input, partial result, prefix result]
        $cases = [
            ['%%', [], []],
            ['__', [], []],
            ['%a', [], []],
            ['a_n', [], []],             // an unescaped '_' would match "ann"
            ['n_w', [], []],             // ... and "now"
            ['jan_kowalski', [], []],    // ... and "jan.kowalski@"
            ['_test', $pct, []],         // "100% Wildcard_Test": literal, inside a word
            ['d_t', $pct, []],
            ['wildcard_t', $pct, $pct],
            ['100%', $pct, $pct],
            ['0% w', $pct, []],
            ['t_u', $pct, []],           // pct_user@...
            ['a!', [], []],              // the escape character itself
            ['!%', [], []],
            ['!!', [], []],
            ["o'b", [], []],
            ['a\\', [], []],
            ['\\%', [], []],
            ['?a', [], []],
        ];
        foreach ($cases as [$value, $partial, $prefix]) {
            self::assertSame($partial, self::addresses($book->search('*', $value, 0)), json_encode([$value, 'partial']) ?: '');
            self::assertSame($prefix, self::addresses($book->search('*', $value, 2)), json_encode([$value, 'prefix']) ?: '');
        }
        self::assertSame($pct, self::addresses($book->search('*', '100% Wildcard_Test', 1)));
        self::assertSame([], self::addresses($book->search('*', '100% Wildcard!Test', 1)));
        self::assertEmpty($book->get_error(), 'no input may produce an SQL error');
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testRequiredFields(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        self::assertSame(self::JAN_BOOK, self::addresses($book->search('*', 'example', 0, true, false, 'email')));
        self::assertSame(self::JAN_BOOK, self::addresses($book->search('*', 'example', 0, true, false, ['email', 'name'])));
        self::assertSame(self::WITH_DEPARTMENT, self::addresses($book->search('*', 'example', 0, true, false, 'department')));
        self::assertSame(self::WITH_DEPARTMENT, self::addresses($book->search('*', 'example', 0, true, false, ['email', 'firstname'])));
        self::assertSame([], self::addresses($book->search('*', 'example', 0, true, false, ['email', 'notes'])));
        self::assertSame([], self::addresses($book->search('ID', [self::id(self::JAN)], 0, true, true, 'photo')));

        // the sender photo of every opened message: search(['email'], sender, STRICT, ..., 'photo')
        $connects = 0;
        $cold = self::coldFactory($dsn, [], $connects)->create(BookDefinition::DOMAIN);
        $rs = $cold->search(['email'], self::JAN, \rcube_addressbook::SEARCH_STRICT, true, true, 'photo');
        self::assertSame(0, $rs->count);
        self::assertSame([], self::records($rs));
        self::assertSame(0, $connects, "an unsupported required field ('photo') returns empty without a query");
        self::assertEmpty($cold->get_error());
        // control: the same search requiring 'email' does query
        self::assertSame([self::JAN], self::addresses($cold->search(['email'], self::JAN, 1, true, true, 'email')));
        self::assertSame(1, $connects);
    }

    // ------------------------------------------------------------------ search set

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSearchSetRoundTripAndTampering(string $dsn): void
    {
        $book = self::book($dsn);
        self::assertNull($book->get_search_set(), 'no filter before a search');
        $book->set_pagesize(50);
        $book->search(['name', 'email'], 'nowak', 2 | \rcube_addressbook::SEARCH_GROUPS);
        $set = $book->get_search_set();
        self::assertIsArray($set);
        // stored in $_SESSION['contact_search'] by core: plain data, no SQL
        $stored = unserialize(serialize($set));
        self::assertSame($set, $stored);
        self::assertStringNotContainsStringIgnoringCase('select', (string) json_encode($set));
        self::assertStringNotContainsStringIgnoringCase('like', (string) json_encode($set));

        // a later request restores it (search_update, list-contacts, export)
        $later = self::book($dsn);
        $later->set_page(1);
        $later->set_pagesize(9999);
        $later->set_search_set($stored);
        self::assertSame(['anna.nowak@example.com'], self::addresses($later->list_records()));
        self::assertSame($set, $later->get_search_set(), 'set_search_set() + get_search_set() is idempotent');
        self::assertSame(1, $later->count()->count);

        // reset() forgets the filter
        $later->reset();
        self::assertNull($later->get_search_set());
        self::assertSame(13, $later->count()->count);

        // anything get_search_set() of this book and configuration could not have produced matches nothing
        $tampered = [
            'other book' => ['book' => BookDefinition::GLOBAL] + $set,
            'other config' => ['fp' => str_repeat('0', 32)] + $set,
            'other version' => ['galbridge' => 99] + $set,
            'unknown type' => ['type' => 'raw'] + $set,
            'unknown field' => ['fields' => ['password']] + $set,
            'non-list fields' => ['fields' => ['x' => 'name']] + $set,
            'non-string value' => ['values' => [['nowak']]] + $set,
            'string mode' => ['mode' => '2'] + $set,
            'foreign id' => ['type' => 'ids', 'ids' => [self::id('bob@example.org')]] + $set,
            'invalid id' => ['type' => 'ids', 'ids' => ['u_X']] + $set,
            'old plugin SQL' => '(`name` LIKE \'%\') OR 1=1',
            'null' => null,
            'empty array' => [],
            'object' => new \stdClass(),
        ];
        foreach ($tampered as $label => $value) {
            $b = self::book($dsn);
            $b->set_page(1);
            $b->set_pagesize(9999);
            $b->set_search_set($value);
            self::assertSame([], self::addresses($b->list_records()), $label);
            self::assertSame(0, $b->count()->count, $label);
        }

        // search() replaces the stored filter also when it returns early (too short, refused, unsupported)
        $early = self::book($dsn, ['galbridge_max_ids' => 1]);
        $early->set_pagesize(50);
        foreach ([['*', 'a', []], ['ID', [self::id(self::JAN), self::id('anna.nowak@example.com')], []], [['notes'], 'x', []], [['email'], self::JAN, 'photo']] as [$fields, $value, $required]) {
            $label = json_encode([$fields, $value, $required]) ?: '';
            self::assertSame([self::JAN], self::addresses($early->search('*', 'kowalski')), $label);
            self::assertSame(0, $early->search($fields, $value, 0, true, false, $required)->count, $label);
            self::assertSame([], self::addresses($early->list_records()), $label . ': never the previous filter, never the book');
            self::assertSame(0, $early->count()->count, $label);
        }

        // a stored set is bound to the configuration: removing a field takes effect immediately
        $dept = self::book($dsn);
        $dept->set_pagesize(50);
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($dept->search(['department'], 'sprzeda')));
        $deptSet = $dept->get_search_set();
        $noDept = self::book($dsn, ['galbridge_fields' => ['name', 'firstname', 'surname', 'email']]);
        $noDept->set_pagesize(9999);
        $noDept->set_search_set($deptSet);
        self::assertSame([], self::addresses($noDept->list_records()));

        // a set of another tenant's book: the scope is re-applied on execution
        $bob = self::book($dsn, [], 'bob@example.org');
        $bob->set_pagesize(50);
        $bob->search('*', 'example', 0);
        $bobSet = $bob->get_search_set();
        self::assertSame(['bob@example.org', 'orginternal@example.org', 'orglist@example.org', 'orgteam@example.org'], self::addresses($bob->get_result()));
        $mine = self::book($dsn);
        $mine->set_pagesize(9999);
        $mine->set_search_set($bobSet);
        self::assertSame(self::JAN_BOOK, self::addresses($mine->list_records()), 'only my scope, never bob\'s rows');
        $idSet = ['type' => 'ids', 'ids' => [self::id('bob@example.org'), self::id(self::JAN)], 'fields' => [], 'values' => []] + (array) $set;
        $mine->set_search_set($idSet);
        self::assertSame([self::JAN], self::addresses($mine->list_records()));
    }

    // ------------------------------------------------------------------ ID search

    #[DataProviderExternal(Env::class, 'engines')]
    public function testIdSearch(string $dsn): void
    {
        $jan = self::id(self::JAN);
        $anna = self::id('anna.nowak@example.com');
        $team = self::id('team@example.com', IdCodec::ALIAS);
        $book = self::book($dsn, [], self::JAN, '', $notices);

        $ok = static function (mixed $ids, array $expected, string $label) use ($book): void {
            $book->set_page(1);
            $book->set_pagesize(is_array($ids) ? count($ids) + 2 : 10);
            $rs = $book->search($book->primary_key, $ids, 0, true, true, 'email');
            self::assertSame($expected, self::addresses($rs), $label);
            self::assertSame(count($expected), $rs->count, $label);
            $rs = $book->search('ID', $ids, 1, true, true);
            self::assertSame($expected, self::addresses($rs), $label . ' (export form)');
        };

        $ok([$jan, $anna], ['anna.nowak@example.com', self::JAN], 'array form');
        $ok($jan . ',' . $anna, ['anna.nowak@example.com', self::JAN], 'comma list');
        $ok([$jan, $jan, ' ' . $jan], [self::JAN], 'de-duplicated');
        $ok([$team], ['team@example.com'], 'alias account');
        $ok([self::id('team@example.com')], [], 'wrong kind');
        $ok([$jan, self::id('bob@example.org'), self::id('klaus@xn--bcher-kva.example'), self::id('disabled.user@example.com'), self::id('managers@example.com', IdCodec::ALIAS)], [self::JAN], 'foreign and invisible IDs are dropped');
        $ok(['', '0', '21981839', 'u_', 'u_JAN', "u_jan'--", self::JAN, $jan . '-galbridge_domain', 'u_janDkowalskiAexampleDcomX4', null, 5, ['x'], 1.5], [], 'invalid IDs');
        $ok([$anna, 'garbage', $jan], ['anna.nowak@example.com', self::JAN], 'valid IDs survive invalid neighbours');
        $ok('', [], 'empty string is no ID, never everything');
        $ok([], [], 'no IDs, never everything');
        self::assertSame([], $notices);

        // more IDs than galbridge_max_ids: refused with a visible warning, never a prefix
        $tooMany = [];
        for ($i = 0; $i < 201; $i++) {
            $tooMany[] = self::id('user' . $i . '@example.com');
        }
        $tooMany[] = $jan;
        $book->set_page(1);
        $book->set_pagesize(count($tooMany) + 2);
        $rs = $book->search($book->primary_key, $tooMany, 0, true, true, 'email');
        self::assertSame(0, $rs->count);
        self::assertSame([], self::records($rs));
        self::assertSame([['toomanyids', ['max' => '200']]], $notices);
        self::assertNotEmpty($book->get_error());

        $small = self::book($dsn, ['galbridge_max_ids' => 2], self::JAN, '', $notices);
        $small->set_pagesize(5);
        self::assertSame(2, $small->search('ID', [$jan, $anna], 0, true, true)->count, 'exactly the limit is fine');
        self::assertSame([], $notices);
        self::assertSame(0, $small->search('ID', [$jan, $anna, $team], 0, true, true)->count);
        self::assertSame(0, $small->search('ID', implode(',', [$jan, $anna, $team]), 0, true, true)->count);
        self::assertSame(['toomanyids', ['max' => '2']], $notices[0] ?? null);
    }

    // ------------------------------------------------------------------ get_record

    #[DataProviderExternal(Env::class, 'engines')]
    public function testGetRecord(string $dsn): void
    {
        $book = self::book($dsn);
        $jan = self::id(self::JAN);

        $record = $book->get_record($jan, true);
        self::assertIsArray($record);
        self::assertSame($jan, $record['ID']);
        self::assertSame('Jan Kowalski', $record['name']);
        self::assertSame([self::JAN], $record['email:work']);
        $result = $book->get_result();
        self::assertInstanceOf(\rcube_result_set::class, $result, 'contact_photo() reads get_result()->first()');
        self::assertSame(1, $result->count);
        self::assertSame($record, $result->first());

        $rs = $book->get_record($jan);
        self::assertInstanceOf(\rcube_result_set::class, $rs);
        self::assertSame(1, $rs->count);
        self::assertSame($record, $rs->first());
        self::assertSame($rs, $book->get_result());

        $alias = $book->get_record(self::id('team@example.com', IdCodec::ALIAS), true);
        self::assertSame('Zespół sprzedaży', $alias['name'] ?? null);

        // a miss is null and clears get_result(): never the previous record
        self::assertNull($book->get_record(self::id('nobody@example.com'), true));
        self::assertNull($book->get_result());
        $book->get_record($jan, true);
        self::assertNull($book->get_record(self::id('nobody@example.com')));
        self::assertNull($book->get_result());
        $book->set_pagesize(50);
        $book->list_records();
        self::assertNull($book->get_record(self::id('bob@example.org'), true));
        self::assertNull($book->get_result(), 'the list result is cleared as well');

        // out of scope or invisible: same answer as a miss
        foreach (['bob@example.org', 'klaus@xn--bcher-kva.example', 'disabled.user@example.com', 'nodeliver@example.com', 'expired.user@example.com', 'carol@inactive.test', 'dave@backup.test'] as $address) {
            $book->get_record($jan, true);
            self::assertNull($book->get_record(self::id($address), true), $address);
            self::assertNull($book->get_result(), $address);
        }
        foreach (['managers@example.com', 'weird@example.com', 'oldalias@example.com'] as $address) {
            self::assertNull($book->get_record(self::id($address, IdCodec::ALIAS), true), $address);
        }
        self::assertNull($book->get_record(self::id('staff@example.com', IdCodec::MAILLIST), true), 'membersonly list jan cannot post to');

        // the group (contact_source() applies _gid on the show page too) does not narrow get_record()
        $book->set_group(self::sprzedaz());
        self::assertSame('Postmaster', $book->get_record(self::id('postmaster@example.com'), true)['name'] ?? null);
        self::assertNull($book->get_record(self::id('bob@example.org'), true));
        self::assertSame(self::sprzedaz(), $book->group_id, 'get_record() keeps the group');
        $book->set_group(0);

        // anything that is not one valid ID string (qrcode passes null)
        foreach ([null, '', 0, 1, '1', 1.5, true, false, [], [$jan], new \stdClass(), $jan . ',' . $jan, strtoupper($jan), ' ' . $jan . 'X'] as $id) {
            $book->get_record($jan, true);
            self::assertNull($book->get_record($id, true), var_export($id, true));
            self::assertNull($book->get_result(), var_export($id, true));
            self::assertNull($book->get_record($id), var_export($id, true));
        }
        self::assertEmpty($book->get_error());
    }

    // ------------------------------------------------------------------ groups

    #[DataProviderExternal(Env::class, 'engines')]
    public function testListGroupsAndGroupSearch(string $dsn): void
    {
        $book = self::book($dsn);
        self::assertTrue($book->groups);
        $groups = $book->list_groups();
        self::assertSame(['IT', 'Księgowość', 'Sprzedaż'], array_column($groups, 'name'));
        $ids = [
            IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'it'),
            IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'księgowość'),
            self::sprzedaz(),
        ];
        self::assertSame($ids, array_column($groups, 'ID'));
        foreach ($groups as $g) {
            self::assertTrue($g['virtual'], 'virtual groups render as drill-down rows without a COUNT');
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $g['ID']);
            self::assertArrayNotHasKey('email', $g);
        }
        self::assertSame($groups, $book->list_groups(null, 0));
        self::assertSame($groups, $book->list_groups('', 0));
        self::assertSame($groups, $book->list_groups('   ', 0));

        $names = static fn (?string $search, int $mode): array => array_column($book->list_groups($search, $mode), 'name');
        self::assertSame(['Sprzedaż'], $names('sprz', 0));
        self::assertSame(['Sprzedaż'], $names('rzed', 0));
        self::assertSame([], $names('rzed', 2));
        self::assertSame(['Sprzedaż'], $names('sprz', 2));
        self::assertSame(['Sprzedaż'], $names('SPRZ', 2 | \rcube_addressbook::SEARCH_GROUPS));
        self::assertSame(['Sprzedaż'], $names('sprzedaż', 1));
        self::assertSame([], $names('sprz', 1));
        self::assertSame(['Sprzedaż'], $names('sprz', \rcube_addressbook::SEARCH_GROUPS));
        self::assertSame(['IT'], $names('it', 1 | \rcube_addressbook::SEARCH_GROUPS));
        self::assertSame([], $names('s', 0), 'galbridge_min_search_length applies to group searches');
        self::assertSame([], $names('nosuchgroup', 0));
        self::assertSame([], $names(str_repeat('s', 200), 0));

        // a group whose expansion would be refused is never offered in a search
        $capped = self::book($dsn, ['galbridge_max_group_members' => 1]);
        self::assertSame([], array_column($capped->list_groups('sprz', 4), 'name'));
        self::assertSame(['IT'], array_column($capped->list_groups('it', 4), 'name'));
        self::assertCount(3, $capped->list_groups(), 'the directory tree still lists every group');
        $coreCapped = self::book($dsn, ['max_group_members' => 1]);
        self::assertSame([], array_column($coreCapped->list_groups('sprz', 4), 'name'), 'core max_group_members is honoured too');
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testGetGroupAndRecordGroups(string $dsn): void
    {
        $book = self::book($dsn);
        $gid = self::sprzedaz();
        $group = $book->get_group($gid);
        self::assertIsArray($group);
        self::assertSame($gid, $group['ID']);
        self::assertSame('Sprzedaż', $group['name']);
        if (array_key_exists('email', $group)) {
            self::assertIsList($group['email'], 'mailto uses $group[\'email\'][0]');
        }
        foreach ([null, 0, '0', '', [], self::sprzedaz('g'), 'dp_nonexistent', 'example.com', 'dd_exampleDcom', 'G' . $gid] as $bad) {
            self::assertNull($book->get_group($bad), var_export($bad, true));
        }

        $groups = $book->get_record_groups(self::id(self::JAN));
        self::assertSame([$gid => 'Sprzedaż'], $groups, 'format [gid => name] (export and print implode() the values)');
        self::assertSame('Sprzedaż', implode(', ', $groups));
        self::assertSame([IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'it') => 'IT'], $book->get_record_groups(self::id('lukasz.slowik@example.com')));
        self::assertSame([], $book->get_record_groups(self::id('postmaster@example.com')), 'no department, no group');
        self::assertSame([], $book->get_record_groups(self::id('team@example.com', IdCodec::ALIAS)), 'groups hold persons only');
        self::assertSame([], $book->get_record_groups(self::id('bob@example.org')));
        foreach ([null, '', 'x', [], 42] as $bad) {
            self::assertSame([], $book->get_record_groups($bad), var_export($bad, true));
        }

        // domain groups (an organization of several domains)
        $org = self::book($dsn, ['galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]]);
        $domainGid = IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.org');
        self::assertSame([IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.com'), $domainGid], array_column($org->list_groups(), 'ID'));
        self::assertSame([$domainGid => 'example.org'], $org->get_record_groups(self::id(self::BOB)));
        self::assertSame(['ID' => $domainGid, 'name' => 'example.org'], $org->get_group($domainGid));
        self::assertSame([], $org->get_record_groups(self::id('klaus@xn--bcher-kva.example')));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSetGroupResetsAndInvalidGroups(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        $gid = self::sprzedaz();

        $book->set_group($gid);
        self::assertSame($gid, $book->group_id);
        $rs = $book->list_records();
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($rs));
        self::assertSame(2, $rs->count);
        self::assertSame(2, $book->count()->count);

        foreach ([0, '0', '', null, false] as $reset) {
            $book->set_group($gid);
            $book->set_group($reset);
            self::assertEmpty($book->group_id, var_export($reset, true));
            self::assertSame(13, $book->list_records()->count, var_export($reset, true));
            self::assertSame(13, $book->count()->count, var_export($reset, true));
        }

        // undecodable, unknown, foreign-book or wrong-kind group IDs: nothing, never everything
        $invalid = [
            'example.org', 'example.com', 'dp_', 'dp_nonexistent', 'dp_sales', self::sprzedaz('g'), 'dd_exampleDcom',
            'dp_SPRZEDAZ', $gid . '-galbridge_domain', 'G' . $gid, 'E' . $gid, '1', 1, ['x'], 'dp_sprzedaXC5XBCX',
        ];
        foreach ($invalid as $bad) {
            $label = var_export($bad, true);
            $book->set_group($bad);
            self::assertSame([], self::records($book->list_records()), $label);
            self::assertSame(0, $book->list_records()->count, $label);
            self::assertSame(0, $book->count()->count, $label);
            self::assertNull($book->get_group($book->group_id), $label . ': core reads get_group($book->group_id) for the list title');
            $book->set_pagesize(9999);
            self::assertSame([], self::records($book->list_records()), $label . ' (full set)');
            $book->set_pagesize(50);
            self::assertSame([], self::addresses($book->search('*', 'kowalski')), $label . ' (search inside the group)');
            $book->reset();
        }
        self::assertEmpty($book->get_error());

        // set_group() is cheap: core calls it on every contacts request that carries _gid
        $connects = 0;
        $cold = self::coldFactory($dsn, [], $connects)->create(BookDefinition::DOMAIN);
        foreach (array_merge([$gid, 0, null], $invalid) as $value) {
            $cold->set_group($value);
        }
        self::assertSame(0, $connects);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testResetKeepsTheGroup(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        $gid = self::sprzedaz();
        $book->set_group($gid);
        self::assertSame([self::JAN], self::addresses($book->search('*', 'jan')), 'a search inside a group');
        self::assertSame([], self::addresses($book->search('*', 'zofia')));
        $book->reset();
        self::assertSame($gid, $book->group_id);
        self::assertNull($book->get_search_set());
        self::assertNull($book->get_result());
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($book->list_records()));
    }

    // ------------------------------------------------------------------ searchonly

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSearchOnlyBook(string $dsn): void
    {
        foreach ([['galbridge_domain_searchonly' => true], ['galbridge_domain_searchonly' => 'auto', 'galbridge_searchonly_threshold' => 5]] as $options) {
            $label = json_encode($options) ?: '';
            $book = self::book($dsn, $options);
            $book->set_pagesize(50);
            self::assertFalse($book->groups, $label . ': no groups, so a group can never list the directory');
            $rs = $book->list_records();
            self::assertSame(0, $rs->count, $label);
            self::assertSame([], self::records($rs), $label);
            self::assertTrue($rs->searchonly, $label . ': core shows "contactsearchonly"');
            self::assertSame(0, $book->count()->count, $label);
            self::assertSame([], $book->list_groups(), $label);
            self::assertSame([], $book->list_groups('sprz', 4), $label);
            $book->set_pagesize(99999);
            self::assertSame([], self::records($book->list_records(null, 0, true)), $label . ': no whole-book export either');
            $book->set_pagesize(50);
            $book->set_group(self::sprzedaz());
            self::assertSame([], self::records($book->list_records()), $label);
            $book->set_group(0);
            // a search works
            self::assertSame([self::JAN], self::addresses($book->search('*', 'kowalski')), $label);
            self::assertSame(1, $book->count()->count, $label);
            $book->reset();
            self::assertTrue($book->list_records()->searchonly, $label . ': back to search-only after reset()');
        }

        // 'auto' below the threshold lists normally
        $book = self::book($dsn, ['galbridge_domain_searchonly' => 'auto', 'galbridge_searchonly_threshold' => 100]);
        $book->set_pagesize(50);
        $rs = $book->list_records();
        self::assertSame(13, $rs->count);
        self::assertFalse((bool) $rs->searchonly);
    }

    // ------------------------------------------------------------------ DeniedBook

    #[DataProviderExternal(Env::class, 'engines')]
    public function testDeniedBookContract(string $dsn): void
    {
        $denied = [];
        $denied['direct'] = new DeniedBook('Domain directory');
        $denied['global book disabled'] = Env::factory(Env::config($dsn), self::JAN)->create(BookDefinition::GLOBAL);
        $denied['unknown login'] = Env::factory(Env::config($dsn), 'nobody@example.com')->create(BookDefinition::DOMAIN);
        $denied['disabled user'] = Env::factory(Env::config($dsn), 'disabled.user@example.com')->create(BookDefinition::DOMAIN);
        $denied['domain excluded'] = Env::factory(Env::config($dsn, ['galbridge_domain_viewer_domains_exclude' => ['example.com']]), self::JAN)->create(BookDefinition::DOMAIN);
        $denied['domain book off'] = Env::factory(Env::config($dsn, ['galbridge_domain_book' => false]), self::JAN)->create(BookDefinition::DOMAIN);
        $denied['foreign IMAP host'] = Env::factory(Env::config($dsn), self::JAN, '', $n, 1, 'imap.attacker.example')->create(BookDefinition::DOMAIN);
        $denied['malformed deny-list'] = Env::factory(Env::config($dsn, ['galbridge_hidden_addresses' => 'postmaster@example.com']), self::JAN)->create(BookDefinition::DOMAIN);

        foreach ($denied as $label => $book) {
            self::assertInstanceOf(DeniedBook::class, $book, $label);
            self::assertTrue($book->readonly, $label);
            self::assertFalse($book->ready, $label . ': compose list and mailto skip it');
            self::assertFalse($book->groups, $label);
            self::assertFalse((bool) $book->searchonly, $label);
            self::assertTrue(!property_exists($book, 'deletable') || empty($book->deletable), $label);
            self::assertIsString($book->get_name(), $label);
            self::assertNotSame('', $book->get_name(), $label);
            self::assertSame('ID', $book->primary_key, $label);

            $book->set_pagesize(9999);
            $book->set_page(1);
            $book->set_group(self::sprzedaz());
            $book->set_search_set(['anything']);
            $book->reset();
            foreach ([
                $book->list_records(),
                $book->list_records(null, 0, true),
                $book->search('*', 'kowalski'),
                $book->search('ID', [self::id(self::JAN)], 0, true, true, 'email'),
                $book->search(['email'], self::JAN, 1, true, true, 'photo'),
                $book->count(),
            ] as $rs) {
                self::assertInstanceOf(\rcube_result_set::class, $rs, $label);
                self::assertSame(0, $rs->count, $label);
                self::assertSame([], self::records($rs), $label);
            }
            self::assertNull($book->get_record(self::id(self::JAN), true), $label);
            self::assertNull($book->get_record(self::id(self::JAN)), $label);
            self::assertNull($book->get_result(), $label);
            self::assertNull($book->get_search_set(), $label);
            self::assertSame([], $book->list_groups(), $label);
            self::assertSame([], $book->list_groups('sprz', 4), $label);
            self::assertNull($book->get_group(self::sprzedaz()), $label);
            self::assertSame([], $book->get_record_groups(self::id(self::JAN)), $label);
            $book->close();
        }

        // it never connects to the database
        $connects = 0;
        $factory = self::coldFactory($dsn, [], $connects);
        $book = $factory->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(DeniedBook::class, $book);
        $book->search('*', 'kowalski');
        $book->list_records();
        $book->get_record(self::id(self::JAN), true);
        $book->list_groups();
        self::assertSame(0, $connects);
    }

    // ------------------------------------------------------------------ record shape

    #[DataProviderExternal(Env::class, 'engines')]
    public function testRecordShapeAndColtypes(string $dsn): void
    {
        $options = [
            'galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'phone', 'mobile', 'organization', 'birthday'],
            'galbridge_organizations' => ['Example Group' => ['example.com']],
        ];
        $book = self::book($dsn, $options);
        $coltypes = $book->coltypes;
        foreach (array_keys($coltypes) as $key) {
            self::assertStringNotContainsString(':', (string) $key, 'coltypes use base keys; records use the subtype form');
        }
        self::assertSame(['name', 'firstname', 'surname', 'email', 'phone', 'department', 'organization', 'birthday'], array_keys($coltypes));
        self::assertSame(['work'], $coltypes['email']['subtypes']);
        self::assertSame(['work', 'mobile'], $coltypes['phone']['subtypes']);
        self::assertSame('date', $coltypes['birthday']['type']);
        self::assertTrue($coltypes['birthday']['nosearch']);
        self::assertArrayNotHasKey('photo', $coltypes);
        self::assertArrayNotHasKey('vcard', $coltypes);

        $book->set_pagesize(50);
        $records = self::records($book->list_records());
        self::assertCount(13, $records);
        $byAddress = [];
        foreach ($records as $record) {
            self::assertRecordShape($record, $coltypes);
            $byAddress[$record['email:work'][0]] = $record;
        }

        $jan = $byAddress[self::JAN];
        self::assertSame('u_janDkowalskiAexampleDcom', $jan['ID']);
        self::assertSame('Jan Kowalski', $jan['name']);
        self::assertSame('Jan', $jan['firstname']);
        self::assertSame('Kowalski', $jan['surname']);
        self::assertSame('Sprzedaż', $jan['department']);
        self::assertSame(['+48 22 123 45 67'], $jan['phone:work']);
        self::assertSame(['+48 600 100 200'], $jan['phone:mobile']);
        self::assertSame('1980-05-17', $jan['birthday']);
        self::assertSame('Example Group', $jan['organization']);

        $postmaster = $byAddress['postmaster@example.com'];
        self::assertArrayNotHasKey('birthday', $postmaster, '0001-01-01 means "no birthday"');
        self::assertArrayNotHasKey('firstname', $postmaster, 'empty values are omitted, never null');
        self::assertArrayNotHasKey('phone:work', $postmaster);
        self::assertSame('Postmaster', $postmaster['name']);
        self::assertSame('Anna Nowak', $byAddress['anna.nowak@example.com']['name'], 'an empty mailbox.name is composed from first + last name');
        self::assertSame('100% Wildcard_Test', $byAddress['pct_user@example.com']['name']);
        self::assertSame('Newsletter firmy', $byAddress['newsletter@example.com']['name']);
        self::assertSame('l_newsletterAexampleDcom', $byAddress['newsletter@example.com']['ID']);
        self::assertSame('a_teamAexampleDcom', $byAddress['team@example.com']['ID']);
        self::assertSame(1, preg_match('/^u_pctUuserAexampleDcom$/', $byAddress['pct_user@example.com']['ID']));

        // the same shape from every reader: get_record and the ID search
        self::assertSame($jan, $book->get_record($jan['ID'], true));
        self::assertSame([$jan], self::records($book->search('ID', [$jan['ID']], 0, true, true)));

        // default fields: nothing outside the allowlist is ever emitted
        $default = self::book($dsn);
        $default->set_pagesize(50);
        foreach (self::records($default->list_records()) as $record) {
            self::assertRecordShape($record, $default->coltypes);
            foreach (['phone:work', 'phone:mobile', 'birthday', 'organization'] as $key) {
                self::assertArrayNotHasKey($key, $record);
            }
        }
    }

    // ------------------------------------------------------------------ DeniedBook notice

    /**
     * DeniedBook's onUse callback (the "directory unavailable" notice during an outage) runs once, on
     * the first read - never for the sender-photo lookup of every opened message, never for metadata
     * calls - and a DeniedBook without it stays silent.
     */
    public function testDeniedBookOnUseRunsOnceAndNeverForThePhotoLookup(): void
    {
        $used = 0;
        $book = new DeniedBook('Domain directory', static function () use (&$used): void {
            $used++;
        });
        $book->set_pagesize(50);
        self::assertSame(0, $book->search(['email'], self::JAN, 1, true, true, 'photo')->count);
        self::assertSame(0, $book->search(['email'], self::JAN, 1, true, true, ['email', 'photo'])->count);
        self::assertSame(0, $book->count()->count);
        self::assertNull($book->get_record(self::id(self::JAN), true));
        self::assertSame([], $book->list_groups('sprz', 4));
        self::assertNull($book->get_group(self::sprzedaz()));
        self::assertSame([], $book->get_record_groups(self::id(self::JAN)));
        self::assertSame('Domain directory', $book->get_name());
        self::assertSame(0, $used, 'no notice for the photo lookup or metadata');

        self::assertSame(0, $book->search('*', 'kowalski', 0, true, true, 'email')->count);
        self::assertSame(1, $used);
        self::assertSame(0, $book->list_records()->count);
        self::assertSame(0, $book->search('*', 'nowak')->count);
        self::assertSame(1, $used, 'once per book');

        $other = new DeniedBook('Global directory', static function () use (&$used): void {
            $used++;
        });
        self::assertSame(0, $other->list_records()->count);
        self::assertSame(2, $used, 'the first read of another book');

        $silent = new DeniedBook('Global directory');
        self::assertSame(0, $silent->list_records()->count);
        self::assertSame(0, $silent->search('*', 'jan')->count);
        self::assertSame(2, $used);
    }

    // ------------------------------------------------------------------ display names (RecordMapper)

    /**
     * @return array<string, array{array<string, string>, string, ?string, ?string}>
     */
    public static function impersonatingRows(): array
    {
        $jan = ['username' => self::JAN, 'domain' => 'example.com'];
        return [
            'ASCII at in the name' => [$jan + ['name' => 'ceo@bank.example', 'first_name' => 'Jan', 'last_name' => 'Kowalski'], 'ceo', 'Jan', 'Kowalski'],
            'FULLWIDTH COMMERCIAL AT in the name' => [$jan + ['name' => "CEO\u{FF20}bank.example", 'first_name' => 'Jan', 'last_name' => 'Kowalski'], 'CEO', 'Jan', 'Kowalski'],
            'SMALL COMMERCIAL AT in the name' => [$jan + ['name' => "CEO\u{FE6B}bank.example", 'first_name' => 'Jan', 'last_name' => 'Kowalski'], 'CEO', 'Jan', 'Kowalski'],
            'quoted address as the name' => [$jan + ['name' => '"ceo@bank.example" <ceo@bank.example>', 'first_name' => '', 'last_name' => ''], 'ceo', null, null],
            'name that is only an address part' => [$jan + ['name' => '@bank.example', 'first_name' => 'Jan', 'last_name' => 'Kowalski'], 'Jan Kowalski', 'Jan', 'Kowalski'],
            'ASCII at in the first name' => [$jan + ['name' => '', 'first_name' => 'ceo@bank.example', 'last_name' => 'Kowalski'], 'ceo Kowalski', 'ceo', 'Kowalski'],
            'FULLWIDTH COMMERCIAL AT in the surname' => [$jan + ['name' => 'Jan Kowalski', 'first_name' => 'Jan', 'last_name' => "Kowalski\u{FF20}evil.example"], 'Jan Kowalski', 'Jan', 'Kowalski'],
            'SMALL COMMERCIAL AT in the first name' => [$jan + ['name' => 'Jan Kowalski', 'first_name' => "\u{FE6B}evil.example", 'last_name' => 'Kowalski'], 'Jan Kowalski', null, 'Kowalski'],
            'every name is an address part' => [$jan + ['name' => '@bank.example', 'first_name' => "\u{FF20}x", 'last_name' => "\u{FE6B}y"], 'jan.kowalski', null, null],
            'blank padding in the name' => [$jan + ['name' => 'Jan' . str_repeat("\u{2800}", 100) . 'Kowalski' . str_repeat("\u{3164}", 100), 'first_name' => "Jan\u{FFA0}\u{FFA0}", 'last_name' => "\u{115F}Kowalski\u{1160}"], 'Jan Kowalski', 'Jan', 'Kowalski'],
            'format characters in the name' => [$jan + ['name' => "Jan\u{00AD}\u{E0041}\u{E0042} Kowalski\u{E007F}", 'first_name' => 'Jan', 'last_name' => 'Kowalski'], 'Jan Kowalski', 'Jan', 'Kowalski'],
        ];
    }

    /**
     * A display, first or last name that contains an at-sign (U+0040, U+FF20, U+FE6B) is cut there:
     * core builds recipients from first/last name too (addressbook_name_listing 1-3,
     * contactlist_name_template), so none of the three may pose as another address.
     *
     * @param array<string, string> $row
     */
    #[DataProvider('impersonatingRows')]
    public function testNamesNeverPoseAsAnAddress(array $row, string $name, ?string $firstname, ?string $surname): void
    {
        $record = self::mapper(BookDefinition::DOMAIN, ['example.com'])->person($row);
        self::assertIsArray($record);
        self::assertSame($name, $record['name']);
        self::assertSame($firstname, $record['firstname'] ?? null);
        self::assertSame($surname, $record['surname'] ?? null);
        foreach (['name', 'firstname', 'surname'] as $key) {
            self::assertDoesNotMatchRegularExpression('/[@\x{FF20}\x{FE6B}<>"\\\\]|\p{Cf}|[\x{2800}\x{3164}\x{115F}\x{1160}\x{FFA0}]|\s\s/u', (string) ($record[$key] ?? ''), $key);
        }
        self::assertSame([self::JAN], $record['email:work'], 'the address itself is untouched');
    }

    public function testAliasAndListNamesNeverPoseAsAnAddress(): void
    {
        $mapper = self::mapper(BookDefinition::GLOBAL, ['example.com', 'example.org']);
        $alias = $mapper->mailList(IdCodec::ALIAS, ['address' => 'team@example.com', 'name' => "Board\u{FF20}bank.example", 'domain' => 'example.com']);
        self::assertSame('Board', $alias['name'] ?? null);
        $list = $mapper->mailList(IdCodec::MAILLIST, ['address' => 'orgteam@example.org', 'name' => 'ceo@bank.example' . str_repeat("\u{2800}", 80), 'domain' => 'example.org']);
        self::assertSame('(example.org) ceo', $list['name'] ?? null);
        $unnamed = $mapper->mailList(IdCodec::MAILLIST, ['address' => 'orgteam@example.org', 'name' => "\u{FE6B}", 'domain' => 'example.org']);
        self::assertSame('(example.org) orgteam', $unnamed['name'] ?? null);
        foreach ([$alias, $list, $unnamed] as $record) {
            self::assertArrayNotHasKey('firstname', $record);
            self::assertArrayNotHasKey('surname', $record);
        }
    }

    /**
     * A foreign entry of the global book carries NO first/last name: whatever addressbook_name_listing
     * mode the user chose, core falls back to 'name', which holds the "(domain)" marker. Entries of
     * the viewer's own domains keep them.
     */
    public function testForeignGlobalEntriesCarryNoFirstOrLastName(): void
    {
        $mapper = self::mapper(BookDefinition::GLOBAL, ['example.com', 'example.org']);
        $bob = ['username' => 'bob@example.org', 'domain' => 'example.org', 'first_name' => 'Bob', 'last_name' => 'Smith'];

        $record = $mapper->person($bob + ['name' => 'Bob Smith']);
        self::assertSame('(example.org) Bob Smith', $record['name'] ?? null);
        self::assertArrayNotHasKey('firstname', (array) $record);
        self::assertArrayNotHasKey('surname', (array) $record);

        // composed from first + last name, then marked
        self::assertSame('(example.org) Bob Smith', $mapper->person($bob + ['name' => ''])['name'] ?? null);
        // padding cannot push the marker out of view
        foreach (["\u{2800}", "\u{3164}", "\u{FFA0}", "\u{00A0}", "\u{3000}", "\u{00AD}", "\u{E0020}"] as $pad) {
            self::assertSame('(example.org) Bob', $mapper->person($bob + ['name' => 'Bob' . str_repeat($pad, 200)])['name'] ?? null, sprintf('U+%04X', mb_ord($pad)));
        }
        // an at-sign in a foreign first name still cannot reach the composed name
        self::assertSame('(example.org) evil Smith', $mapper->person(['first_name' => "evil\u{FF20}bank.example"] + $bob + ['name' => ''])['name'] ?? null);

        // the viewer's own domain: not foreign, first/last name kept, no marker
        $jan = $mapper->person(['username' => self::JAN, 'domain' => 'example.com', 'name' => 'Jan Kowalski', 'first_name' => 'Jan', 'last_name' => 'Kowalski']);
        self::assertSame(['Jan Kowalski', 'Jan', 'Kowalski'], [$jan['name'] ?? null, $jan['firstname'] ?? null, $jan['surname'] ?? null]);

        // marking switched off: an ordinary entry
        $unmarked = self::mapper(BookDefinition::GLOBAL, ['example.com', 'example.org'], false)->person($bob + ['name' => 'Bob Smith']);
        self::assertSame(['Bob Smith', 'Bob', 'Smith'], [$unmarked['name'] ?? null, $unmarked['firstname'] ?? null, $unmarked['surname'] ?? null]);
    }

    /**
     * The same through the production wiring on every engine: bob@example.org from jan's global book.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testGlobalBookRecordsOfForeignDomainsAreMarkedOnly(string $dsn): void
    {
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.com', 'example.org'], 'galbridge_global_include_home' => true];
        $book = self::book($dsn, $options, self::JAN, '', $n, BookDefinition::GLOBAL);
        $book->set_pagesize(50);
        $bob = self::records($book->search('*', 'bob'));
        self::assertCount(1, $bob);
        self::assertSame('(example.org) Bob Smith', $bob[0]['name']);
        self::assertArrayNotHasKey('firstname', $bob[0]);
        self::assertArrayNotHasKey('surname', $bob[0]);
        self::assertSame($bob[0], $book->get_record($bob[0]['ID'], true), 'get_record gives the same shape');
        $jan = self::records($book->search('*', 'kowalski'));
        self::assertSame('Jan Kowalski', $jan[0]['name'] ?? null);
        self::assertSame('Jan', $jan[0]['firstname'] ?? null);
        self::assertSame('Kowalski', $jan[0]['surname'] ?? null);
        self::assertSame('(example.org) Org Team', self::records($book->search('*', 'org team'))[0]['name'] ?? null);
    }

    // ------------------------------------------------------------------ row re-check log codes

    /**
     * The PHP re-check of rows logs two different conditions under two codes: a malformed row
     * (row_invalid, WARN) can no longer hide the scope tripwire (row_out_of_scope, ERROR) of the
     * same request; each code is still logged once per request.
     */
    public function testInvalidAndOutOfScopeRowsAreLoggedSeparately(): void
    {
        $mapper = self::mapper(BookDefinition::DOMAIN, ['example.com'], true, ['postmaster@example.com'], ['hidden']);
        $invalid = [
            ['username' => 'jan.kowalski@example.org', 'domain' => 'example.com'],   // address outside its row domain
            ['username' => 'not an address', 'domain' => 'example.com'],
            ['username' => self::JAN, 'domain' => null],
        ];
        $outOfScope = [
            ['username' => self::BOB, 'domain' => 'example.org'],                    // another tenant
            ['username' => 'postmaster@example.com', 'domain' => 'example.com'],    // a hidden address
            ['username' => 'hidden@example.com', 'domain' => 'example.com'],        // a hidden local part
        ];
        foreach ([[$invalid, $outOfScope], [$outOfScope, $invalid]] as [$first, $second]) {
            Log::resetOnce();
            $GLOBALS['galbridge_test_log'] = [];
            foreach ([...$first, ...$second] as $row) {
                self::assertNull($mapper->person($row + ['name' => 'X']), (string) json_encode($row));
                self::assertNull($mapper->mailList(IdCodec::ALIAS, ['address' => $row['username'], 'domain' => $row['domain'], 'name' => 'X']));
            }
            $log = $GLOBALS['galbridge_test_log'];
            self::assertCount(2, $log, implode("\n", $log));
            self::assertCount(1, preg_grep('/^WARN scope: row_invalid: /', $log) ?: [], implode("\n", $log));
            self::assertCount(1, preg_grep('/^ERROR scope: row_out_of_scope: .* book=galbridge_domain/', $log) ?: [], implode("\n", $log));
            self::assertSame([], preg_grep('/row_dropped/', $log) ?: []);
        }

        // a valid row logs nothing
        Log::resetOnce();
        $GLOBALS['galbridge_test_log'] = [];
        self::assertIsArray($mapper->person(['username' => self::JAN, 'domain' => 'example.com', 'name' => 'Jan Kowalski']));
        self::assertSame([], $GLOBALS['galbridge_test_log']);
    }

    // ------------------------------------------------------------------ page size limits

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, int}>
     */
    public static function pageCaps(): array
    {
        return [
            'core defaults (max_pagesize 200, addressbook_pagesize 50)' => [[], [], 200],
            'addressbook_pagesize above max_pagesize' => [['max_pagesize' => 50, 'addressbook_pagesize' => 100], [], 100],
            'numeric string' => [['max_pagesize' => '50', 'addressbook_pagesize' => '120'], [], 120],
            'a page size the user saved before max_pagesize was lowered' => [['max_pagesize' => 50], ['addressbook_pagesize' => 150], 150],
            'max_pagesize above the hard cap' => [['max_pagesize' => 5000], [], Limits::HARD_PAGE_CAP],
            'addressbook_pagesize above the hard cap' => [['addressbook_pagesize' => 99999], [], Limits::HARD_PAGE_CAP],
            'invalid max_pagesize falls back to 200' => [['max_pagesize' => 'abc', 'addressbook_pagesize' => 20], [], 200],
            'zero max_pagesize falls back to 200' => [['max_pagesize' => 0], [], 200],
            'tiny values are raised to 10' => [['max_pagesize' => 3, 'addressbook_pagesize' => 2], [], 10],
        ];
    }

    /**
     * Limits::corePageCap is the largest page size Roundcube's own lists use: max(max_pagesize,
     * addressbook_pagesize), bounded by HARD_PAGE_CAP (core clamps addressbook_pagesize only when a
     * user saves the preference).
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $prefs
     */
    #[DataProvider('pageCaps')]
    public function testCorePageCapIsTheLargestCoreListSize(array $admin, array $prefs, int $expected): void
    {
        $rc = new \rcube_config();
        foreach (array_merge($admin, $prefs) as $k => $v) {
            $rc->set($k, $v);
        }
        self::assertSame($expected, Limits::fromConfig(new Config($rc, $prefs, $admin))->corePageCap);
    }

    /**
     * A normal page as large as addressbook_pagesize is a page, never a whole-book request: paging
     * information stays consistent with the page size core computes it from.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testPageSizeAboveMaxPagesizeIsAnOrdinaryPage(string $dsn): void
    {
        $options = ['max_pagesize' => 10, 'addressbook_pagesize' => 12, 'galbridge_domain_export' => false];
        $book = self::book($dsn, $options, self::JAN, 'list', $notices);
        $book->set_pagesize(12);
        self::assertSame(12, $book->page_size);
        $rs = $book->list_records();
        self::assertSame(13, $rs->count);
        self::assertSame(0, $rs->first);
        self::assertCount(12, self::records($rs));
        self::assertSame([], $notices, 'no export refusal for an ordinary page');
        self::assertNull($book->get_error());

        $book->set_page(2);
        $rs = $book->list_records();
        self::assertSame(12, $rs->first);
        self::assertCount(1, self::records($rs));

        // the export (99999) is still a whole-book request
        $export = self::book($dsn, $options, self::JAN, 'export', $notices);
        $export->set_pagesize(99999);
        self::assertSame([], self::records($export->list_records(null, 0, true)));
        self::assertSame(['exportdisabled'], array_column($notices, 0));
    }

    // ------------------------------------------------------------------ search-only 'auto' cache

    /**
     * The 'auto' decision is cached per book: listing one book never evicts the other's entry, a
     * cached decision is used without counting again, and expired entries or entries of another
     * configuration are dropped when the map is written.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testSearchOnlyAutoCacheHoldsEveryBook(string $dsn): void
    {
        $options = [
            'galbridge_domain_searchonly' => 'auto', 'galbridge_global_searchonly' => 'auto', 'galbridge_searchonly_threshold' => 100,
            'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org'],
        ];
        $config = Env::config($dsn, $options);
        $fp = $config->fingerprint();
        $factory = Env::factory($config, self::JAN); // a new session
        $listed = [];
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = $factory->create($id);
            $book->set_pagesize(50);
            $rs = $book->list_records();
            self::assertFalse((bool) $rs->searchonly, $id . ': below the threshold');
            $listed[$id] = $rs->count;
        }
        self::assertSame([BookDefinition::DOMAIN => 13, BookDefinition::GLOBAL => 3], $listed);
        $map = $_SESSION['galbridge_searchonly'] ?? null;
        self::assertIsArray($map);
        self::assertSame([BookDefinition::DOMAIN . ':' . $fp, BookDefinition::GLOBAL . ':' . $fp], array_keys($map), 'both books are cached');
        foreach ($map as $entry) {
            self::assertFalse($entry['v']);
            self::assertIsInt($entry['at']);
        }

        // a later request in the same session reads both decisions (proved by changing them)
        foreach (array_keys($map) as $key) {
            $_SESSION['galbridge_searchonly'][$key]['v'] = true;
        }
        $before = $_SESSION['galbridge_searchonly'];
        $later = self::sessionFactory($config);
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = $later->create($id);
            $book->set_pagesize(50);
            self::assertTrue((bool) $book->list_records()->searchonly, $id . ': the cached decision is used');
        }
        self::assertSame($before, $_SESSION['galbridge_searchonly'], 'nothing recomputed, the session is not rewritten');

        // expired entries and entries of another configuration are pruned on the next write
        unset($_SESSION['galbridge_searchonly'][BookDefinition::DOMAIN . ':' . $fp]);
        $_SESSION['galbridge_searchonly']['galbridge_domain:0123456789abcdef0123456789abcdef'] = ['v' => true, 'at' => time()];
        $_SESSION['galbridge_searchonly']['galbridge_old:' . $fp] = ['v' => true, 'at' => time() - 100000];
        $_SESSION['galbridge_searchonly']['garbage:' . $fp] = 'x';
        $book = self::sessionFactory($config)->create(BookDefinition::DOMAIN);
        $book->set_pagesize(50);
        self::assertSame(13, $book->list_records()->count);
        $map = $_SESSION['galbridge_searchonly'];
        self::assertEqualsCanonicalizing([BookDefinition::GLOBAL . ':' . $fp, BookDefinition::DOMAIN . ':' . $fp], array_keys($map));
        self::assertTrue($map[BookDefinition::GLOBAL . ':' . $fp]['v'], 'a still valid entry is kept');
        self::assertFalse($map[BookDefinition::DOMAIN . ':' . $fp]['v']);
    }

    // ------------------------------------------------------------------ helpers (display names, sessions)

    /**
     * A RecordMapper for a book of jan (home domain example.com) with the default fields.
     *
     * @param list<string> $domains
     * @param list<string> $hiddenAddresses
     * @param list<string> $hiddenLocalparts
     */
    private static function mapper(string $id, array $domains, bool $markForeign = true, array $hiddenAddresses = [], array $hiddenLocalparts = []): RecordMapper
    {
        $global = $id === BookDefinition::GLOBAL;
        $book = new BookDefinition(
            id: $id,
            tag: $global ? 'g' : 'd',
            name: 'Directory',
            allDomains: false,
            domains: $domains,
            excludeDomains: [],
            homeDomains: ['example.com'],
            grouping: 'none',
            searchonly: false,
            autocomplete: true,
            export: true,
            prefixOnly: $global,
            everyWordMinLength: $global,
            maxResults: 100,
            markForeign: $global && $markForeign,
            orgLabels: [],
        );
        $viewer = new Viewer(self::JAN, 'example.com', [self::JAN], ['mailbox' => ['username', 'name', 'domain', 'first_name', 'last_name', 'department']]);
        return new RecordMapper($book, new FieldMap(['name', 'firstname', 'surname', 'email', 'department'], $viewer, false, false), $hiddenAddresses, $hiddenLocalparts);
    }

    /**
     * A factory on the CURRENT session (a later request of the same user; Env::factory starts a new one).
     */
    private static function sessionFactory(Config $config, string $login = self::JAN): Factory
    {
        $connection = null;
        $connect = static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
    }
}
