<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-32: books that cover several domains.
 *  (a) the fields an entry of another domain does not carry (first and last name; department and
 *      phones under a name template that shows them before {name}) are no search or sort criteria
 *      for it either - withholding is not only presentational any more;
 *  (b) a department group of the global book is per domain ("(domain) Department"): equal
 *      department names of different tenants never form one group.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2MultiDomainBookTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private const GLOBAL = [
        'galbridge_global_book' => true,
        'galbridge_global_viewer_domains' => ['*'],
        'galbridge_global_data_domains' => ['example.org', 'xn--bcher-kva.example'],
        'galbridge_global_searchonly' => false,
        'galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'phone'],
    ];

    private mixed $template = null;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
        $this->template = \rcube::get_instance()->config->get('contactlist_name_template');
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
        if (GALBRIDGE_RC_LOADED) {
            \rcube::get_instance()->config->set('contactlist_name_template', $this->template);
        }
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testWithheldFieldsOfForeignEntriesAreNeitherMatchedNorSorted(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, first_name, last_name, domain, department, telephone, maildir) VALUES'
                . " ('withheld.one@example.org', 'Visible Name', 'Zebedeusz', 'Quarkowski', 'example.org', 'Secretdept', '+48 999', 'example.org/w/i/t/one'),"
                . " ('noname.two@example.org', '', 'Yolanda', 'Ypsilon', 'example.org', '', '', 'example.org/n/o/n/two'),"
                . " ('sortme.a@example.org', 'Aaa Sortperson', 'Zed', 'Zzz', 'example.org', '', '', 'example.org/s/o/r/a'),"
                . " ('sortme.b@example.org', 'Zzz Sortperson', 'Abe', 'Aaa', 'example.org', '', '', 'example.org/s/o/r/b')"],
            ["DELETE FROM mailbox WHERE username IN ('withheld.one@example.org', 'noname.two@example.org', 'sortme.a@example.org', 'sortme.b@example.org')"],
        );
        $book = self::book($dsn);
        foreach ([['*', 'Zebedeusz'], ['firstname', 'Zebedeusz'], ['name', 'Quarkowski'], ['surname', 'Quarkowski'], ['*', 'Quarkowski']] as [$field, $word]) {
            self::assertSame(0, $book->search($field, $word, 0, false)->count, "{$field}: {$word} is not shown, so it does not match");
        }
        self::assertSame(['withheld.one@example.org'], self::emails($book->search('*', 'Visible')), 'the shown name matches');
        // first and last name ARE the shown name of an entry without a display name
        self::assertSame(['noname.two@example.org'], self::emails($book->search('*', 'Yolanda')));
        self::assertSame('(example.org) Yolanda Ypsilon', $book->search('*', 'Yolanda')->records[0]['name'] ?? null);
        // the department is shown with the default template: a criterion as before
        self::assertSame(['withheld.one@example.org'], self::emails($book->search('department', 'Secretdept')));

        // sorted by surname: an entry of another domain by its name key (its surname is not shown)
        $book->set_sort_order('surname');
        self::assertSame(['sortme.a@example.org', 'sortme.b@example.org'], self::ordered($book->search('name', 'Sortperson')));
        $book->set_sort_order('name');
        self::assertSame(['sortme.a@example.org', 'sortme.b@example.org'], self::ordered($book->search('name', 'Sortperson')));

        // with the viewer's own domain in the book, its entries stay searchable by every field
        $home = self::book($dsn, ['galbridge_global_include_home' => true, 'galbridge_global_data_domains' => ['example.com', 'example.org']]);
        self::assertSame([self::JAN], self::emails($home->search('firstname', 'Jan')));
        self::assertSame(0, $home->search('firstname', 'Zebedeusz', 0, false)->count);
        $home->set_sort_order('surname');
        self::assertSame(['sortme.a@example.org', 'sortme.b@example.org'], self::ordered($home->search('name', 'Sortperson')));

        // a template that shows the department before the name withholds it - and its search
        \rcube::get_instance()->config->set('contactlist_name_template', '{department} {name}');
        $book = self::book($dsn);
        self::assertSame(0, $book->search('department', 'Secretdept', 0, false)->count);
        self::assertSame(0, $book->search('*', 'Secretdept', 0, false)->count);
        self::assertSame(1, $book->search('phone', '999', 0, false)->count, 'the phone is not before {name} here: shown, so a criterion');
        // the domain book of the entry's own domain is not affected
        $own = Env::factory(Env::config($dsn, self::GLOBAL), 'bob@example.org')->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $own);
        self::assertSame(['withheld.one@example.org'], self::emails($own->search('department', 'Secretdept')));
        self::assertSame(['withheld.one@example.org'], self::emails($own->search('firstname', 'Zebedeusz')));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testDepartmentGroupsOfTheGlobalBookNeverMixDomains(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, domain, department, maildir) VALUES'
                . " ('shared.one@example.org', 'Shared One', 'example.org', 'Shared Dept', 'example.org/s/h/a/one'),"
                . " ('shared.two@xn--bcher-kva.example', 'Shared Two', 'xn--bcher-kva.example', 'shared dept', 'x/s/h/a/two')"],
            ["DELETE FROM mailbox WHERE username IN ('shared.one@example.org', 'shared.two@xn--bcher-kva.example')"],
        );
        $options = ['galbridge_global_groups' => 'department'];
        $book = self::book($dsn, $options);
        $groups = [];
        foreach ($book->list_groups() as $g) {
            $groups[$g['name']] = $g['ID'];
        }
        self::assertArrayHasKey('(example.org) Shared Dept', $groups);
        self::assertArrayHasKey('(xn--bcher-kva.example) shared dept', $groups);
        self::assertArrayHasKey('(example.org) Sales', $groups);
        foreach ([['(example.org) Shared Dept', 'shared.one@example.org'], ['(xn--bcher-kva.example) shared dept', 'shared.two@xn--bcher-kva.example']] as [$label, $member]) {
            $expand = self::book($dsn, $options, 'group-expand');
            $expand->set_group($groups[$label]);
            $expand->set_pagesize(9999);
            self::assertSame([$member], self::emails($expand->list_records(null, 0, true)), $label . ': its own domain only');
            self::assertSame(1, $expand->count()->count, $label);
            self::assertSame([$groups[$label] => $label], $book->get_record_groups(IdCodec::contactId(IdCodec::PERSON, $member)));
        }
        // a group ID of the merged form of 1.0.x selects nothing
        $merged = IdCodec::groupId('g', IdCodec::GROUP_DEPARTMENT, 'shared dept');
        $expand = self::book($dsn, $options, 'group-expand');
        $expand->set_group($merged);
        $expand->set_pagesize(9999);
        self::assertSame([], $expand->list_records(null, 0, true)->records);
        // a forged per-domain ID of a domain outside the book selects nothing either
        $expand->set_group(IdCodec::groupId('g', IdCodec::GROUP_DEPARTMENT, 'example.com:sprzedaż'));
        self::assertSame([], $expand->list_records(null, 0, true)->records);

        // the domain book (an organization of several domains is one trust unit) keeps one group
        // per department name
        $org = ['galbridge_organizations' => ['Org' => ['example.org', 'xn--bcher-kva.example']], 'galbridge_domain_groups' => 'department'];
        $domain = Env::factory(Env::config($dsn, $org), 'bob@example.org')->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $domain);
        $labels = array_column($domain->list_groups(), 'name');
        self::assertSame(1, count(array_filter($labels, static fn (string $l): bool => strcasecmp($l, 'Shared Dept') === 0)), implode(', ', $labels));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $options
     */
    private static function book(string $dsn, array $options = [], string $action = ''): AddressBook
    {
        $book = Env::factory(Env::config($dsn, $options + self::GLOBAL), self::JAN, $action)->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $book, implode("\n", $GLOBALS['galbridge_test_log'] ?? []));
        return $book;
    }

    /**
     * @return list<string>
     */
    private static function emails(\rcube_result_set $rs): array
    {
        $out = self::ordered($rs);
        sort($out);
        return $out;
    }

    /**
     * @return list<string>
     */
    private static function ordered(\rcube_result_set $rs): array
    {
        $out = [];
        foreach ($rs->records as $r) {
            $out[] = (string) ($r['email:work'][0] ?? '');
        }
        return $out;
    }
}
