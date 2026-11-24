<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may use which book with which data scope (docs/ARCHITECTURE.md 4, 5, 13): domain-book viewer
 * allow/deny lists, organizations (disjoint sets, overlaps fall back to the own domain), the global
 * book (viewers, data domains, exclusions, the double opt-in for '*', include_home), deny-lists that
 * fail closed when malformed, hidden addresses and local parts, the optional visibility column, and
 * the contract of the fail-closed DeniedBook for every source that is not usable.
 *
 * @license GPL-3.0-or-later
 */
final class EntitlementsTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';
    private const BOB = 'bob@example.org';
    private const KLAUS = 'klaus@xn--bcher-kva.example';

    private ?string $privateDb = null;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if ($this->privateDb !== null) {
            @unlink($this->privateDb);
            $this->privateDb = null;
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    // ------------------------------------------------------------------ domain book viewers

    #[DataProvider('engines')]
    public function testDomainBookViewerAllowList(string $dsn): void
    {
        $options = ['galbridge_domain_viewer_domains' => ['example.org', 'not a domain!']];
        self::assertDenied(Env::factory(Env::config($dsn, $options), self::JAN), BookDefinition::DOMAIN, 'domainbook');
        $bob = Env::factory(Env::config($dsn, $options), self::BOB);
        self::assertSame([BookDefinition::DOMAIN], array_keys($bob->books()));

        // the invalid entry was dropped and reported, not turned into a wildcard
        $config = Env::config($dsn, $options);
        Env::factory($config, self::BOB)->books();
        self::assertContains('galbridge_domain_viewer_domains', array_column($config->problems(), 'option'));

        // IDN in the configuration is normalised like the stored A-label
        $klaus = Env::factory(Env::config($dsn, ['galbridge_domain_viewer_domains' => ['BÜCHER.example.']]), self::KLAUS);
        self::assertSame([BookDefinition::DOMAIN], array_keys($klaus->books()));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function brokenAllowLists(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out["{$engine}: string"] = [$dsn, 'example.com'];
            $out["{$engine}: wildcard mixed with domains"] = [$dsn, ['*', 'example.com']];
            $out["{$engine}: empty"] = [$dsn, []];
        }
        return $out;
    }

    /**
     * A broken ALLOW-list grants nobody: it is never read as '*'.
     */
    #[DataProvider('brokenAllowLists')]
    public function testBrokenViewerAllowListGrantsNobody(string $dsn, mixed $value): void
    {
        foreach ([self::JAN, self::BOB] as $login) {
            self::assertDenied(Env::factory(Env::config($dsn, ['galbridge_domain_viewer_domains' => $value]), $login), BookDefinition::DOMAIN, 'domainbook');
        }
    }

    #[DataProvider('engines')]
    public function testDomainBookViewerExcludeList(string $dsn): void
    {
        $options = ['galbridge_domain_viewer_domains_exclude' => ['EXAMPLE.com']];
        self::assertDenied(Env::factory(Env::config($dsn, $options), self::JAN), BookDefinition::DOMAIN, 'domainbook');
        self::assertSame([BookDefinition::DOMAIN], array_keys(Env::factory(Env::config($dsn, $options), self::BOB)->books()));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function malformedDenyLists(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out["{$engine}: string"] = [$dsn, 'example.com'];
            $out["{$engine}: invalid entry"] = [$dsn, ['example.com', 'not a domain!']];
            $out["{$engine}: wildcard"] = [$dsn, ['*']];
            $out["{$engine}: nested list"] = [$dsn, [['example.com']]];
            $out["{$engine}: integer entry"] = [$dsn, [42]];
        }
        return $out;
    }

    /**
     * A malformed DENY-list disables the book it protects for everyone (fail closed): dropping the
     * bad entry would silently show the domain the administrator meant to exclude.
     */
    #[DataProvider('malformedDenyLists')]
    public function testMalformedViewerDenyListDisablesTheBook(string $dsn, mixed $value): void
    {
        foreach ([self::JAN, self::BOB, self::KLAUS] as $login) {
            $config = Env::config($dsn, ['galbridge_domain_viewer_domains_exclude' => $value]);
            self::assertDenied(Env::factory($config, $login), BookDefinition::DOMAIN, 'domainbook');
            self::assertContains('fail', array_column($config->problems(), 'level'));
        }
    }

    #[DataProvider('engines')]
    public function testDisabledDomainBookIsNeverListed(string $dsn): void
    {
        self::assertDenied(Env::factory(Env::config($dsn, ['galbridge_domain_book' => false]), self::JAN), BookDefinition::DOMAIN, 'domainbook');
    }

    // ------------------------------------------------------------------ organizations

    #[DataProvider('engines')]
    public function testOrganizationWidensTheDomainBookAndLabelsIt(string $dsn): void
    {
        $options = ['galbridge_organizations' => ['Acme Group' => ['example.com', 'EXAMPLE.org']], 'galbridge_fields' => ['name', 'email', 'organization']];
        foreach ([self::JAN, self::BOB] as $login) {
            $factory = Env::factory(Env::config($dsn, $options), $login);
            $books = $factory->books();
            self::assertSame('Acme Group', $books[BookDefinition::DOMAIN]->name ?? null, $login);
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(AddressBook::class, $book);
            self::assertSame('Acme Group', $book->get_name());
            self::assertArrayHasKey('organization', $book->coltypes);
            $book->set_pagesize(200);
            $rs = $book->list_records();
            self::assertSame(['example.com', 'example.org'], self::domains($rs), $login);
            foreach ($rs->records as $r) {
                self::assertSame('Acme Group', $r['organization'] ?? null, (string) $r['ID']);
            }
            // auto grouping becomes "by domain" inside an organization
            self::assertSame(['dd_exampleDcom', 'dd_exampleDorg'], array_column($book->list_groups(), 'ID'));
            self::assertContains('bob@example.org', self::emails($book->search(['organization'], 'acme')));
            self::assertSame(0, $book->search(['organization'], 'Beta')->count);
        }

        // a domain outside every organization keeps its own domain and the default label
        $klaus = Env::factory(Env::config($dsn, $options), self::KLAUS);
        self::assertSame('domainbook', $klaus->books()[BookDefinition::DOMAIN]->name ?? null);
        $book = $klaus->create(BookDefinition::DOMAIN);
        $book->set_pagesize(200);
        self::assertSame(['klaus@xn--bcher-kva.example'], self::emails($book->list_records()));
        self::assertArrayNotHasKey('organization', $book->get_record('u_klausAxnHHbcherHkvaDexample', true) ?? []);
    }

    #[DataProvider('engines')]
    public function testExplicitBookNameWinsOverTheOrganizationLabel(string $dsn): void
    {
        $factory = Env::factory(Env::config($dsn, ['galbridge_organizations' => ['Acme' => ['example.com', 'example.org']], 'galbridge_domain_book_name' => 'Firmowa książka']), self::JAN);
        self::assertSame('Firmowa książka', $factory->create(BookDefinition::DOMAIN)->get_name());
    }

    /**
     * A domain listed in two organizations is removed from both: its users fall back to their own
     * domain, and the other domains of each organization stay together (diag reports FAIL).
     */
    #[DataProvider('engines')]
    public function testOverlappingOrganizationsFallBackToTheOwnDomain(string $dsn): void
    {
        $orgs = ['galbridge_organizations' => ['Acme' => ['example.com', 'example.org'], 'Beta' => ['example.org', 'xn--bcher-kva.example']]];
        $expect = [
            self::BOB => ['domainbook', ['example.org']],
            self::JAN => ['Acme', ['example.com']],
            self::KLAUS => ['Beta', ['xn--bcher-kva.example']],
        ];
        foreach ($expect as $login => [$name, $domains]) {
            $config = Env::config($dsn, $orgs);
            $factory = Env::factory($config, $login);
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertSame($name, $book->get_name(), $login);
            $book->set_pagesize(200);
            self::assertSame($domains, self::domains($book->list_records()), $login);
            self::assertContains('fail', array_column($config->problems(), 'level'));
        }
        // the overlapping domain is not reachable through a group either
        $jan = Env::factory(Env::config($dsn, $orgs), self::JAN)->create(BookDefinition::DOMAIN);
        $jan->set_group('dd_exampleDorg');
        $jan->set_pagesize(9999);
        self::assertSame([], self::emails($jan->list_records()));
    }

    // ------------------------------------------------------------------ global book

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function global(array $extra): array
    {
        return $extra + ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_searchonly' => false];
    }

    #[DataProvider('engines')]
    public function testGlobalBookViewersAndDataDomains(string $dsn): void
    {
        $options = self::global(['galbridge_global_viewer_domains' => ['example.com'], 'galbridge_global_data_domains' => ['example.org']]);
        $jan = Env::factory(Env::config($dsn, $options), self::JAN);
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys($jan->books()));
        $book = $jan->create(BookDefinition::GLOBAL);
        self::assertSame('globalbook', $book->get_name());
        $book->set_pagesize(200);
        self::assertSame(['bob@example.org', 'orglist@example.org', 'orgteam@example.org'], self::emails($book->list_records()));
        // entries outside the viewer's home are marked (galbridge_mark_foreign_domains)
        self::assertSame('Bob Smith (example.org)', $book->get_record('u_bobAexampleDorg', true)['name'] ?? null);

        // bob is not a viewer of the global book
        self::assertDenied(Env::factory(Env::config($dsn, $options), self::BOB), BookDefinition::GLOBAL, 'globalbook');

        // viewer exclusion
        $options = self::global(['galbridge_global_viewer_domains_exclude' => ['example.org'], 'galbridge_global_data_domains' => ['example.com', 'xn--bcher-kva.example']]);
        self::assertDenied(Env::factory(Env::config($dsn, $options), self::BOB), BookDefinition::GLOBAL, 'globalbook');
        self::assertArrayHasKey(BookDefinition::GLOBAL, Env::factory(Env::config($dsn, $options), self::JAN)->books());
    }

    #[DataProvider('engines')]
    public function testWildcardDataDomainsNeedTheSecondOptIn(string $dsn): void
    {
        $options = self::global(['galbridge_global_data_domains' => ['*']]);
        self::assertDenied(Env::factory(Env::config($dsn, $options), self::JAN), BookDefinition::GLOBAL, 'globalbook');

        $options = self::global(['galbridge_global_data_domains' => ['*'], 'galbridge_global_allow_wildcard' => true, 'galbridge_global_data_domains_exclude' => ['example.org']]);
        $book = Env::factory(Env::config($dsn, $options), self::JAN)->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        // every hosted domain, minus the exclusion and the viewer's own domain-book scope
        self::assertSame(['klaus@xn--bcher-kva.example'], self::emails($book->list_records()));
        self::assertNull($book->get_record('u_bobAexampleDorg'));
        self::assertNull($book->get_record('u_janDkowalskiAexampleDcom'));
    }

    #[DataProvider('malformedDenyLists')]
    public function testMalformedGlobalDataExcludeDisablesTheGlobalBook(string $dsn, mixed $value): void
    {
        $options = self::global(['galbridge_global_data_domains' => ['*'], 'galbridge_global_allow_wildcard' => true, 'galbridge_global_data_domains_exclude' => $value]);
        $factory = Env::factory(Env::config($dsn, $options), self::JAN);
        self::assertDenied($factory, BookDefinition::GLOBAL, 'globalbook');
        self::assertInstanceOf(AddressBook::class, $factory->create(BookDefinition::DOMAIN), 'the domain book is not affected');
    }

    #[DataProvider('engines')]
    public function testIncludeHomeControlsDuplicatesOfTheDomainBook(string $dsn): void
    {
        $data = ['galbridge_global_data_domains' => ['example.com', 'example.org']];
        $book = Env::factory(Env::config($dsn, self::global($data)), self::JAN)->create(BookDefinition::GLOBAL);
        $book->set_pagesize(200);
        self::assertSame(['example.org'], self::domains($book->list_records()));

        $book = Env::factory(Env::config($dsn, self::global($data + ['galbridge_global_include_home' => true])), self::JAN)->create(BookDefinition::GLOBAL);
        $book->set_pagesize(200);
        self::assertSame(['example.com', 'example.org'], self::domains($book->list_records()));

        // the viewer's "home" is the whole organization
        $org = self::global(['galbridge_global_data_domains' => ['example.org', 'xn--bcher-kva.example'], 'galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]]);
        $book = Env::factory(Env::config($dsn, $org), self::JAN)->create(BookDefinition::GLOBAL);
        $book->set_pagesize(200);
        self::assertSame(['xn--bcher-kva.example'], self::domains($book->list_records()));
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function emptyGlobalScopes(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out["{$engine}: only the home domain"] = [$dsn, ['galbridge_global_data_domains' => ['example.com']]];
            $out["{$engine}: no data domains"] = [$dsn, ['galbridge_global_data_domains' => []]];
            $out["{$engine}: data domains all excluded"] = [$dsn, ['galbridge_global_data_domains' => ['example.org'], 'galbridge_global_data_domains_exclude' => ['example.org']]];
            $out["{$engine}: only invalid data domains"] = [$dsn, ['galbridge_global_data_domains' => ['not a domain!', '']]];
            $out["{$engine}: data domains not a list"] = [$dsn, ['galbridge_global_data_domains' => 'example.org']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('emptyGlobalScopes')]
    public function testEmptyScopeIsNotListedAndGivesADeniedBook(string $dsn, array $options): void
    {
        self::assertDenied(Env::factory(Env::config($dsn, self::global($options)), self::JAN), BookDefinition::GLOBAL, 'globalbook');
    }

    #[DataProvider('engines')]
    public function testGlobalBookIsSearchOnlyByDefault(string $dsn): void
    {
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $book = Env::factory(Env::config($dsn, $options), self::JAN)->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $book);
        $rs = $book->list_records();
        self::assertSame([], $rs->records);
        self::assertTrue($rs->searchonly);
        self::assertSame(0, $book->count()->count);
        self::assertSame([], $book->list_groups());
        self::assertSame(['bob@example.org'], self::emails($book->search('*', 'Bob')));
    }

    // ------------------------------------------------------------------ hidden addresses and local parts

    #[DataProvider('engines')]
    public function testHiddenAddressesAreInvisibleEverywhere(string $dsn): void
    {
        $options = ['galbridge_hidden_addresses' => ['POSTMASTER@Example.COM', 'team@example.com', 'jan.kowalski@example.com']];
        $book = Env::factory(Env::config($dsn, $options), 'zofia.zolkiewska@example.com')->create(BookDefinition::DOMAIN);
        $book->set_pagesize(200);
        $emails = self::emails($book->list_records());
        foreach (['u_postmasterAexampleDcom', 'a_teamAexampleDcom', 'u_janDkowalskiAexampleDcom'] as $id) {
            $address = IdCodec::decodeContact($id)['address'] ?? '';
            self::assertNotContains($address, $emails);
            self::assertNull($book->get_record($id), $id);
            self::assertSame([], self::emails($book->search('ID', $id)), $id);
            self::assertSame([], self::emails($book->search(['email'], $address, \rcube_addressbook::SEARCH_STRICT)), $id);
        }
        self::assertSame([], self::emails($book->search('*', 'Kowalski')));
        // hidden persons are not group members either
        $book->reset();
        $book->set_group('dp_sprzedaXC5XBC');
        $book->set_pagesize(9999);
        self::assertSame(['anna.nowak@example.com'], self::emails($book->list_records()));
        self::assertSame(1, $book->count()->count);
    }

    #[DataProvider('engines')]
    public function testHiddenLocalPartsApplyToEveryDomainAndKind(string $dsn): void
    {
        $options = self::global([
            'galbridge_global_data_domains' => ['*'], 'galbridge_global_allow_wildcard' => true, 'galbridge_global_include_home' => true,
            'galbridge_hidden_localparts' => ['Postmaster', 'newsletter', 'orgteam', 'bob'],
        ]);
        $book = Env::factory(Env::config($dsn, $options), self::JAN)->create(BookDefinition::GLOBAL);
        $book->set_pagesize(200);
        foreach (self::emails($book->list_records()) as $e) {
            self::assertNotContains(substr($e, 0, (int) strrpos($e, '@')), ['postmaster', 'newsletter', 'orgteam', 'bob'], $e);
        }
        self::assertNull($book->get_record('l_newsletterAexampleDcom'));
        self::assertNull($book->get_record('u_bobAexampleDorg'));
        self::assertSame([], self::emails($book->search(['email'], 'postmaster', \rcube_addressbook::SEARCH_PREFIX)));
        // a local part is matched exactly: 'bob' does not hide 'bob.smith' style names elsewhere
        self::assertNotNull($book->get_record('u_janDkowalskiAexampleDcom'));
    }

    /**
     * @return array<string, array{string, string, mixed}>
     */
    public static function malformedHiddenLists(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach ([
                'addresses: string' => ['galbridge_hidden_addresses', 'postmaster@example.com'],
                'addresses: invalid entry' => ['galbridge_hidden_addresses', ['postmaster@example.com', 'not an address']],
                'addresses: wildcard' => ['galbridge_hidden_addresses', ['*']],
                'addresses: wildcard mixed' => ['galbridge_hidden_addresses', ['postmaster@example.com', '*']],
                'addresses: integer' => ['galbridge_hidden_addresses', [42]],
                'localparts: string' => ['galbridge_hidden_localparts', 'postmaster'],
                'localparts: whitespace' => ['galbridge_hidden_localparts', ['post master']],
                'localparts: address' => ['galbridge_hidden_localparts', ['postmaster@example.com']],
                'localparts: wildcard' => ['galbridge_hidden_localparts', ['*']],
                'localparts: nested' => ['galbridge_hidden_localparts', [['postmaster']]],
            ] as $case => [$option, $value]) {
                $out["{$engine}: {$case}"] = [$dsn, $option, $value];
            }
        }
        return $out;
    }

    /**
     * The hidden lists protect every book: a malformed one disables ALL books (fail closed).
     */
    #[DataProvider('malformedHiddenLists')]
    public function testMalformedHiddenListDisablesEveryBook(string $dsn, string $option, mixed $value): void
    {
        $options = self::global(['galbridge_global_data_domains' => ['example.org'], $option => $value]);
        $config = Env::config($dsn, $options);
        $factory = Env::factory($config, self::JAN);
        self::assertSame([], $factory->books());
        self::assertDenied($factory, BookDefinition::DOMAIN, 'domainbook');
        self::assertDenied($factory, BookDefinition::GLOBAL, 'globalbook');
        self::assertContains('fail', array_column($config->problems(), 'level'));
    }

    // ------------------------------------------------------------------ visibility column

    /**
     * Configured but not readable (missing or not granted): no person is visible (fail closed).
     */
    #[DataProvider('engines')]
    public function testMissingVisibilityColumnHidesEveryPerson(string $dsn): void
    {
        $book = Env::factory(Env::config($dsn, ['galbridge_visibility_column' => 'gal_visible']), self::JAN)->create(BookDefinition::DOMAIN);
        $book->set_pagesize(200);
        foreach ($book->list_records()->records as $r) {
            self::assertStringStartsNotWith('u_', $r['ID']);
        }
        self::assertNull($book->get_record('u_janDkowalskiAexampleDcom'));
        self::assertSame([], self::emails($book->search(['name'], 'Kowalski')));
        self::assertSame([], $book->list_groups());
        $book->reset();
        $book->set_group('dp_it');
        $book->set_pagesize(9999);
        self::assertSame([], self::emails($book->list_records()));
        self::assertStringNotContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']), 'never queried');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidVisibilityColumns(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach (['gal-visible', 'Gal_Visible', 'x; DROP TABLE mailbox', '1abc', 'gal visible', str_repeat('a', 65), 'm.active'] as $name) {
                $out["{$engine}: {$name}"] = [$dsn, $name];
            }
        }
        return $out;
    }

    #[DataProvider('invalidVisibilityColumns')]
    public function testInvalidVisibilityColumnNameDisablesEveryBook(string $dsn, string $column): void
    {
        $factory = Env::factory(Env::config($dsn, ['galbridge_visibility_column' => $column]), self::JAN);
        self::assertSame([], $factory->books());
        self::assertDenied($factory, BookDefinition::DOMAIN, 'domainbook');
        self::assertStringNotContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    /**
     * An administrator-created visibility column (private SQLite copy of the fixture: the shared
     * server databases are not altered).
     */
    public function testVisibilityColumnHidesFlaggedPersons(): void
    {
        $this->privateDb = sys_get_temp_dir() . '/galbridge-visibility-' . getmypid() . '.sqlite';
        @unlink($this->privateDb);
        $pdo = new \PDO('sqlite:' . $this->privateDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dir = dirname(__DIR__) . '/fixtures/vmail/';
        $pdo->exec((string) file_get_contents($dir . 'schema.sqlite.sql'));
        $pdo->exec((string) file_get_contents($dir . 'seed.sql'));
        $pdo->exec('ALTER TABLE mailbox ADD COLUMN gal_visible INTEGER NOT NULL DEFAULT 1');
        $pdo->exec("UPDATE mailbox SET gal_visible = 0 WHERE username = 'anna.nowak@example.com'");
        $pdo = null;
        $dsn = 'sqlite:///' . $this->privateDb . '?mode=0600';

        $book = Env::factory(Env::config($dsn, ['galbridge_visibility_column' => 'gal_visible']), self::JAN)->create(BookDefinition::DOMAIN);
        $book->set_pagesize(200);
        $emails = self::emails($book->list_records());
        self::assertNotContains('anna.nowak@example.com', $emails);
        self::assertContains('jan.kowalski@example.com', $emails);
        self::assertNull($book->get_record('u_annaDnowakAexampleDcom'));
        self::assertSame([], self::emails($book->search('*', 'Nowak')));
        $book->reset();
        $book->set_group('dp_sprzedaXC5XBC');
        $book->set_pagesize(9999);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->list_records()));

        // without the option the column is ignored
        $book = Env::factory(Env::config($dsn), self::JAN)->create(BookDefinition::DOMAIN);
        self::assertNotNull($book->get_record('u_annaDnowakAexampleDcom'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The source is not listed and its instance is a DeniedBook that reveals nothing.
     */
    private static function assertDenied(\GalBridge\Factory $factory, string $id, string $label): void
    {
        self::assertArrayNotHasKey($id, $factory->books());
        $book = $factory->create($id);
        self::assertInstanceOf(DeniedBook::class, $book);
        self::assertTrue($book->readonly);
        self::assertFalse($book->ready);
        self::assertFalse($book->groups);
        self::assertSame($label, $book->get_name());
        self::assertSame(0, $book->list_records()->count);
        self::assertSame(0, $book->search('*', 'Kowalski')->count);
        self::assertSame(0, $book->search('ID', 'u_janDkowalskiAexampleDcom')->count);
        self::assertSame(0, $book->count()->count);
        self::assertNull($book->get_record('u_janDkowalskiAexampleDcom'));
        self::assertNull($book->get_record('u_janDkowalskiAexampleDcom', true));
        self::assertSame([], $book->list_groups());
        self::assertSame([], $book->list_groups('Sprz'));
        self::assertNull($book->get_group('dp_it'));
        self::assertSame([], $book->get_record_groups('u_janDkowalskiAexampleDcom'));
        self::assertNull($book->get_search_set());
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

    /**
     * @return list<string>
     */
    private static function domains(\rcube_result_set $rs): array
    {
        $d = [];
        foreach (self::emails($rs) as $e) {
            $d[substr($e, (int) strrpos($e, '@') + 1)] = true;
        }
        $d = array_keys($d);
        sort($d);
        return $d;
    }
}
