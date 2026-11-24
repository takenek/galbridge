<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tenant isolation by construction (docs/ARCHITECTURE.md 1, 3, 5, 9): the scope comes only from the
 * login and the administrator configuration. jan@example.com never reaches an example.org row through
 * listing, search, contact or group IDs, record groups, stored search sets or autocomplete. Inactive,
 * relay-only and expired domains and inactive, undeliverable and expired users are invisible
 * everywhere. The Viewer cached in $_SESSION is bound to user, login, IMAP host, configuration
 * fingerprint and TTL, and a database outage is memoised without ever being cached as an entitlement.
 *
 * @license GPL-3.0-or-later
 */
final class IsolationTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    /** what jan@example.com sees in the domain book (seed.sql) */
    private const JAN_DOMAIN_BOOK = [
        'anna.nowak@example.com', 'board@example.com', 'everyone@example.com', 'forwarder@example.com',
        'internal@example.com', 'jan.kowalski@example.com', 'legacy@example.com', 'lukasz.slowik@example.com',
        'newsletter@example.com', 'pct_user@example.com', 'postmaster@example.com', 'team@example.com',
        'zofia.zolkiewska@example.com',
    ];

    /** never visible to anybody (seed.sql): inactive / relay-only / expired domains and accounts */
    private const INVISIBLE = [
        'u_carolAinactiveDtest', 'u_daveAbackupDtest', 'u_erinAexpiredDtest', 'u_disabledDuserAexampleDcom',
        'u_nodeliverAexampleDcom', 'u_expiredDuserAexampleDcom', 'a_ghostAinactiveDtest', 'a_oldaliasAexampleDcom',
        'a_expiredaliasAexampleDcom', 'a_emptyAexampleDcom', 'l_retiredAexampleDcom', 'l_brokenAexampleDcom',
    ];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    // ------------------------------------------------------------------ cross-tenant

    #[DataProvider('engines')]
    public function testDomainBookListsExactlyTheHomeTenant(string $dsn): void
    {
        $book = self::domainBook($dsn, self::JAN);
        $book->set_pagesize(200);
        $rs = $book->list_records();
        self::assertSame(self::JAN_DOMAIN_BOOK, self::emails($rs));
        self::assertSame(13, $rs->count);
        self::assertSame(13, $book->count()->count);

        // paging through the book never leaves the tenant either
        $seen = [];
        $book->set_pagesize(5);
        for ($page = 1; $page <= 4; $page++) {
            $book->set_page($page);
            array_push($seen, ...self::emails($book->list_records()));
        }
        sort($seen);
        self::assertSame(self::JAN_DOMAIN_BOOK, $seen);
    }

    /**
     * @return array<string, array{string, mixed, mixed, int}>
     */
    public static function crossTenantSearches(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach ([
                'name' => ['*', 'Bob', 0],
                'surname' => [['surname'], 'Smith', 0],
                'department of the other tenant' => ['*', 'Sales', 0],
                'domain fragment' => ['*', 'example.org', 0],
                'strict address' => [['email'], 'bob@example.org', \rcube_addressbook::SEARCH_STRICT],
                'prefix address' => [['email'], 'bob', \rcube_addressbook::SEARCH_PREFIX],
                'alias account' => ['*', 'orgteam', 0],
                'mailing list' => ['*', 'Org list', 0],
                'advanced' => [['name', 'email'], ['Bob', 'example.org'], 0],
                'IDN tenant' => ['*', 'Müller', 0],
            ] as $case => [$fields, $value, $mode]) {
                $out[$engine . ': ' . $case] = [$dsn, $fields, $value, $mode];
            }
        }
        return $out;
    }

    #[DataProvider('crossTenantSearches')]
    public function testOtherTenantsAreNeverFound(string $dsn, mixed $fields, mixed $value, int $mode): void
    {
        $book = self::domainBook($dsn, self::JAN);
        $rs = $book->search($fields, $value, $mode);
        self::assertSame([], self::outsideHome($rs));
        self::assertSame(0, $book->search($fields, $value, $mode, false)->count, 'count-only search');

        // compose search lists ask for the full set
        $book->set_pagesize(9999);
        self::assertSame([], self::outsideHome($book->search($fields, $value, $mode)));
    }

    #[DataProvider('engines')]
    public function testForeignAndInvisibleIdsResolveToNothing(string $dsn): void
    {
        $book = self::domainBook($dsn, self::JAN);
        $foreign = ['u_bobAexampleDorg', 'a_orgteamAexampleDorg', 'l_orglistAexampleDorg', 'u_klausAxnHHbcherHkvaDexample'];
        // INVISIBLE plus entries hidden from jan by their posting policy
        $policyHidden = ['a_weirdAexampleDcom', 'a_managersAexampleDcom', 'a_auditAexampleDcom', 'l_staffAexampleDcom', 'l_modsAexampleDcom'];
        foreach (array_merge($foreign, self::INVISIBLE, $policyHidden) as $id) {
            // the previous (visible) result must be cleared: a miss is indistinguishable from "out of scope"
            self::assertNotNull($book->get_record('u_janDkowalskiAexampleDcom'));
            self::assertNull($book->get_record($id), $id);
            self::assertNull($book->get_result(), $id);
            self::assertNull($book->get_record($id, true), $id);
            self::assertSame([], $book->get_record_groups($id), $id);
        }

        // kind confusion: a person addressed as an alias account / a list, and vice versa
        foreach (['a_janDkowalskiAexampleDcom', 'l_janDkowalskiAexampleDcom', 'u_teamAexampleDcom', 'l_teamAexampleDcom', 'a_newsletterAexampleDcom'] as $id) {
            self::assertNull($book->get_record($id), $id);
        }

        // compose-to-selected / export of selected: only the visible IDs survive
        $ids = implode(',', [...$foreign, 'u_janDkowalskiAexampleDcom', ...self::INVISIBLE]);
        $book->set_pagesize(9999);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search('ID', $ids, 0, true, true, 'email')));
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search($book->primary_key, explode(',', $ids))));
        self::assertSame(0, $book->search('ID', implode(',', $foreign))->count);
    }

    #[DataProvider('engines')]
    public function testForgedGroupsMatchNothing(string $dsn): void
    {
        $book = self::domainBook($dsn, self::JAN);
        $own = array_column($book->list_groups(), 'ID');
        self::assertSame(['dp_it', 'dp_ksiXC4X99gowoXC5X9BXC4X87', 'dp_sprzedaXC5XBC'], $own);

        $forged = [
            'dp_sales',                             // bob's department (example.org)
            'dp_vertrieb',                          // klaus' department
            'dd_exampleDorg',                       // a domain group in a department-grouped book
            'gp_it',                                // a global-book group id
            'dp_IT',                                // not canonical (keys are lower case)
            'dp_it,dp_sales',
            '../dp_it',
            'dp_' . IdCodec::encode("it' OR '1'='1"),
            '1',
        ];
        foreach ($forged as $gid) {
            $book->set_group($gid);
            foreach ([10, 9999] as $size) { // the contact list, and compose-to-group / group expansion
                $book->set_pagesize($size);
                self::assertSame([], self::emails($book->list_records()), $gid);
                self::assertSame(0, $book->count()->count, $gid);
            }
            self::assertNull($book->get_group($gid), $gid);
        }

        // sanity: the real group works and stays inside the tenant
        $book->set_group('dp_it');
        $book->set_pagesize(9999);
        self::assertSame(['lukasz.slowik@example.com'], self::emails($book->list_records()));
        $book->set_group(0);
        $book->set_pagesize(200);
        self::assertSame(13, $book->list_records()->count);
    }

    #[DataProvider('engines')]
    public function testOrganizationDomainGroupsCannotReachOtherDomains(string $dsn): void
    {
        $factory = Env::factory(Env::config($dsn, ['galbridge_organizations' => ['Acme' => ['example.com', 'xn--bcher-kva.example']]]), self::JAN);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertSame(['dd_exampleDcom', 'dd_xnHHbcherHkvaDexample'], array_column($book->list_groups(), 'ID'));
        foreach (['dd_exampleDorg', 'dd_inactiveDtest', 'dd_backupDtest', 'dd_expiredDtest', 'dp_sales'] as $gid) {
            $book->set_group($gid);
            $book->set_pagesize(9999);
            self::assertSame([], self::emails($book->list_records()), $gid);
        }
        $book->set_group('dd_xnHHbcherHkvaDexample');
        self::assertSame(['klaus@xn--bcher-kva.example'], self::emails($book->list_records()));
    }

    /**
     * Autocomplete as core runs it: every configured source, contactlist fields, required 'email',
     * no count; plus the compose group list with a search term.
     */
    #[DataProvider('engines')]
    public function testAutocompleteNeverCrossesTenants(string $dsn): void
    {
        $factory = Env::factory(Env::config($dsn), self::JAN, 'autocomplete');
        $book = $factory->create(BookDefinition::DOMAIN);
        foreach (['bo', 'bob', 'smi', 'org', 'sales', 'kla', 'mü'] as $term) {
            foreach ([0, \rcube_addressbook::SEARCH_PREFIX, \rcube_addressbook::SEARCH_STRICT] as $mode) {
                $book->set_pagesize(15);
                self::assertSame([], self::outsideHome($book->search(['name', 'firstname', 'surname', 'email'], $term, $mode, true, true, 'email')), $term);
                foreach ($book->list_groups($term, $mode) as $g) {
                    self::assertStringStartsWith('dp_', $g['ID']);
                    $book->reset(); // group expansion runs without the search filter
                    $book->set_group($g['ID']);
                    $book->set_pagesize(9999);
                    self::assertSame([], self::outsideHome($book->list_records()));
                    $book->set_group(null);
                }
            }
        }
        // a crafted _source for a disabled book is fail-closed
        $global = $factory->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(DeniedBook::class, $global);
        self::assertSame(0, $global->search(['name', 'email'], 'bob', 0, true, true, 'email')->count);
    }

    /**
     * Roundcube keeps get_search_set() in the session and replays it in later requests. A spec carries
     * no scope: replayed in another user's book it is evaluated in THAT user's scope.
     */
    #[DataProvider('engines')]
    public function testReplayedSearchSetIsEvaluatedInTheCurrentScope(string $dsn): void
    {
        $config = Env::config($dsn);
        $jan = Env::factory($config, self::JAN)->create(BookDefinition::DOMAIN);
        $jan->search('ID', 'u_janDkowalskiAexampleDcom,u_zofiaDzolkiewskaAexampleDcom');
        $idSpec = $jan->get_search_set();
        $jan->search('*', 'Kowalski');
        $quickSpec = $jan->get_search_set();

        $bob = Env::factory($config, 'bob@example.org')->create(BookDefinition::DOMAIN);
        foreach ([$idSpec, $quickSpec] as $spec) {
            $bob->set_search_set($spec);
            $bob->set_pagesize(9999);
            self::assertSame([], self::emails($bob->list_records()));
            self::assertSame(0, $bob->count()->count);
        }
        // a spec of another book or configuration, or a tampered one, matches nothing
        foreach ([
            ['book' => BookDefinition::GLOBAL] + (array) $quickSpec,
            ['fp' => 'x'] + (array) $quickSpec,
            ['type' => 'raw', 'sql' => '1=1'] + (array) $quickSpec,
            ['ids' => ['u_bobAexampleDorg', "u_x' OR 1=1"]] + (array) $idSpec,
            'SELECT * FROM mailbox',
        ] as $tampered) {
            $bob->set_search_set($tampered);
            self::assertSame([], self::emails($bob->list_records()));
        }
    }

    #[DataProvider('engines')]
    public function testIdnTenantIsIsolated(string $dsn): void
    {
        foreach (['klaus@bücher.example', 'KLAUS@xn--bcher-kva.example'] as $login) {
            $book = self::domainBook($dsn, $login);
            $book->set_pagesize(200);
            self::assertSame(['klaus@xn--bcher-kva.example'], self::emails($book->list_records()), $login);
            self::assertNull($book->get_record('u_janDkowalskiAexampleDcom'));
        }
    }

    // ------------------------------------------------------------------ invisible domains and users

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function everyDomain(array $extra = []): array
    {
        return $extra + [
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['*'],
            'galbridge_global_allow_wildcard' => true,
            'galbridge_global_include_home' => true,
            'galbridge_global_searchonly' => false,
            'galbridge_global_max_results' => 5000,
            'galbridge_list_policy' => 'all',
        ];
    }

    #[DataProvider('engines')]
    public function testInvisibleDomainsAndAccountsNeverAppear(string $dsn): void
    {
        $book = Env::factory(Env::config($dsn, self::everyDomain()), self::JAN)->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        $emails = self::emails($book->list_records());
        self::assertSame(['example.com', 'example.org', 'xn--bcher-kva.example'], self::domains($emails));
        foreach (self::INVISIBLE as $id) {
            $address = IdCodec::decodeContact($id)['address'] ?? '';
            self::assertNotContains($address, $emails, $id);
            self::assertNull($book->get_record($id), $id);
        }
        foreach (['carol', 'dave', 'erin', 'disabled', 'nodeliver', 'expired', 'ghost', 'retired', 'broken', 'oldalias', 'empty'] as $term) {
            foreach ($book->search('*', $term)->records as $r) {
                self::assertNotContains($r['ID'], self::INVISIBLE, $term);
            }
        }
        // a book whose data domains are all invisible is empty, not an error
        $book = Env::factory(Env::config($dsn, self::everyDomain(['galbridge_global_data_domains' => ['inactive.test', 'backup.test', 'expired.test']])), self::JAN)->create(BookDefinition::GLOBAL);
        $book->set_pagesize(200);
        self::assertSame([], self::emails($book->list_records()));
        self::assertSame(0, $book->count()->count);
        self::assertSame(0, $book->search('*', 'carol')->count);
    }

    #[DataProvider('engines')]
    public function testHideExpiredOffShowsExpiredButNeverInactiveEntries(string $dsn): void
    {
        $book = Env::factory(Env::config($dsn, self::everyDomain(['galbridge_hide_expired' => false])), self::JAN)->create(BookDefinition::GLOBAL);
        $book->set_pagesize(200);
        $emails = self::emails($book->list_records());
        foreach (['expired.user@example.com', 'erin@expired.test', 'expiredalias@example.com'] as $expired) {
            self::assertContains($expired, $emails);
        }
        foreach (['disabled.user@example.com', 'nodeliver@example.com', 'carol@inactive.test', 'dave@backup.test', 'ghost@inactive.test',
            'oldalias@example.com', 'retired@example.com', 'broken@example.com', 'empty@example.com'] as $never) {
            self::assertNotContains($never, $emails);
        }
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function loginsWithoutDirectory(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach ([
                'inactive domain' => ['carol@inactive.test', true],
                'relay-only domain' => ['dave@backup.test', true],
                'expired domain' => ['erin@expired.test', true],
                'inactive user' => ['disabled.user@example.com', true],
                'expired user' => ['expired.user@example.com', true],
                'no mailbox' => ['nobody@example.com', true],
                'alias address is not a login' => ['jk@example.com', true],
                'alias-domain login is not a mailbox' => ['jan.kowalski@example.net', true],
                'not an address' => ['jan.kowalski', false],
                'empty' => ['', false],
            ] as $case => [$login, $valid]) {
                $out[$engine . ': ' . $case] = [$dsn, $login, $valid];
            }
        }
        return $out;
    }

    #[DataProvider('loginsWithoutDirectory')]
    public function testLoginsWithoutAVisibleMailboxGetNoBooks(string $dsn, string $login, bool $valid): void
    {
        $factory = Env::factory(Env::config($dsn, self::everyDomain()), $login);
        self::assertSame([], $factory->books());
        self::assertSame($valid ? 'no_mailbox' : 'invalid_login', $factory->resolver()->status());
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = $factory->create($id);
            self::assertInstanceOf(DeniedBook::class, $book);
            self::assertSame(0, $book->search('*', 'Kowalski')->count);
        }
    }

    #[DataProvider('engines')]
    public function testExpiredDomainViewerIsResolvedOnlyWithHideExpiredOff(string $dsn): void
    {
        $factory = Env::factory(Env::config($dsn, ['galbridge_hide_expired' => false]), 'erin@expired.test');
        $book = $factory->create(BookDefinition::DOMAIN);
        $book->set_pagesize(200);
        self::assertSame(['erin@expired.test'], self::emails($book->list_records()));
    }

    // ------------------------------------------------------------------ Viewer cache in $_SESSION

    #[DataProvider('engines')]
    public function testViewerIsCachedInTheSessionAndBoundToItsContext(string $dsn): void
    {
        $config = Env::config($dsn);
        $connects = 0;
        $viewer = self::resolver($config, self::JAN, 1, Env::IMAP_HOST, $connects)->resolve();
        self::assertNotNull($viewer);
        self::assertSame(1, $connects);
        self::assertSame('example.com', $viewer->home);
        self::assertSame([self::JAN, 'jan@example.net', 'jk@example.com', 'sprzedaz@example.com'], [$viewer->addresses[0], ...self::sorted(array_slice($viewer->addresses, 1))]);

        $cache = $_SESSION['galbridge'] ?? null;
        self::assertIsArray($cache);
        self::assertSame(1, $cache['uid'] ?? null);
        self::assertSame(self::JAN, $cache['login'] ?? null);
        self::assertSame(Env::IMAP_HOST, $cache['host'] ?? null);
        self::assertSame($config->fingerprint(), $cache['fp'] ?? null);
        self::assertSame('ok', $cache['status'] ?? null);
        self::assertEqualsWithDelta(time(), $cache['at'] ?? 0, 5);
        self::assertStringNotContainsString('testonly', serialize($cache), 'no credentials in the session');

        // same context: answered from the session, no database round trip
        $connects = 0;
        self::assertEquals($viewer, self::resolver($config, self::JAN, 1, Env::IMAP_HOST, $connects)->resolve());
        self::assertSame(0, $connects);

        // another Roundcube user id in the same session
        $connects = 0;
        self::assertNotNull(self::resolver($config, self::JAN, 2, Env::IMAP_HOST, $connects)->resolve());
        self::assertSame(1, $connects);
        self::assertSame(2, $_SESSION['galbridge']['uid']);

        // another login: never the cached Viewer of somebody else
        $connects = 0;
        $bob = self::resolver($config, 'bob@example.org', 2, Env::IMAP_HOST, $connects)->resolve();
        self::assertSame(1, $connects);
        self::assertSame('example.org', $bob?->home);
    }

    #[DataProvider('engines')]
    public function testConfigurationChangeOrExpiredTtlInvalidatesTheCache(string $dsn): void
    {
        $config = Env::config($dsn);
        self::assertNotNull(self::resolver($config, self::JAN)->resolve());

        $connects = 0;
        self::assertNotNull(self::resolver(Env::config($dsn, ['galbridge_hidden_localparts' => ['postmaster']]), self::JAN, 1, Env::IMAP_HOST, $connects)->resolve());
        self::assertSame(1, $connects, 'a configuration change takes effect immediately');

        // older than galbridge_scope_ttl (default 300 s)
        self::assertNotNull(self::resolver($config, self::JAN)->resolve());
        $_SESSION['galbridge']['at'] = time() - 301;
        $connects = 0;
        self::assertNotNull(self::resolver($config, self::JAN, 1, Env::IMAP_HOST, $connects)->resolve());
        self::assertSame(1, $connects);

        // a longer TTL keeps it
        $long = Env::config($dsn, ['galbridge_scope_ttl' => 3600]);
        self::assertNotNull(self::resolver($long, self::JAN)->resolve());
        $_SESSION['galbridge']['at'] = time() - 301;
        $connects = 0;
        self::assertNotNull(self::resolver($long, self::JAN, 1, Env::IMAP_HOST, $connects)->resolve());
        self::assertSame(0, $connects);

        // a malformed entry or one of another cache version is not trusted
        foreach ([['at' => 'now'], ['v' => 0], ['status' => 42]] as $broken) {
            self::assertNotNull(self::resolver($config, self::JAN)->resolve());
            $_SESSION['galbridge'] = $broken + $_SESSION['galbridge'];
            $connects = 0;
            self::assertNotNull(self::resolver($config, self::JAN, 1, Env::IMAP_HOST, $connects)->resolve());
            self::assertSame(1, $connects, json_encode($broken) ?: '');
        }
    }

    #[DataProvider('engines')]
    public function testCachedViewerIsBoundToTheImapHost(string $dsn): void
    {
        $config = Env::config($dsn, ['galbridge_imap_hosts' => ['127.0.0.1', 'localhost']]);
        self::assertNotNull(self::resolver($config, self::JAN, 1, '127.0.0.1')->resolve());
        $connects = 0;
        self::assertNotNull(self::resolver($config, self::JAN, 1, 'localhost', $connects)->resolve());
        self::assertSame(1, $connects);
        self::assertSame('localhost', $_SESSION['galbridge']['host']);

        // a host outside the list is refused before the cache is even consulted
        $connects = 0;
        $resolver = self::resolver($config, self::JAN, 1, 'imap.evil.example', $connects);
        self::assertNull($resolver->resolve());
        self::assertSame('host_mismatch', $resolver->status());
        self::assertSame(0, $connects);
    }

    #[DataProvider('engines')]
    public function testNegativeDecisionsAreCachedToo(string $dsn): void
    {
        $config = Env::config($dsn);
        self::assertNull(self::resolver($config, 'nobody@example.com')->resolve());
        self::assertSame('no_mailbox', $_SESSION['galbridge']['status'] ?? null);
        $connects = 0;
        $resolver = self::resolver($config, 'nobody@example.com', 1, Env::IMAP_HOST, $connects);
        self::assertNull($resolver->resolve());
        self::assertSame('no_mailbox', $resolver->status());
        self::assertSame(0, $connects);
    }

    #[DataProvider('engines')]
    public function testOutageIsMemoisedAndNeverCachedAsNoEntitlement(string $dsn): void
    {
        $down = self::unreachable($dsn);
        $connects = 0;
        $factory = self::factory(Env::config($down), self::JAN, $connects);
        self::assertSame([], $factory->books());
        self::assertSame('db_down', $factory->resolver()->status());
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'an outage is not an entitlement decision');
        $until = (int) ($_SESSION['galbridge_down_until'] ?? 0);
        self::assertGreaterThan(time() + Connection::OUTAGE_SECONDS - 5, $until);
        self::assertLessThanOrEqual(time() + Connection::OUTAGE_SECONDS, $until);
        self::assertStringContainsString('db_down', implode("\n", $GLOBALS['galbridge_test_log']));
        self::assertStringNotContainsString('testonly-ro', implode("\n", $GLOBALS['galbridge_test_log']));

        // while memoised, even a healthy server is not contacted again (a dead directory must not
        // block every webmail request)
        $created = 0;
        $config = Env::config($dsn);
        $factory = self::factory($config, self::JAN, $connects, $created);
        self::assertSame([], $factory->books());
        self::assertSame('db_down', $factory->resolver()->status());
        self::assertSame(0, $created);

        // after the memo window the directory is back at once (nothing negative was cached)
        $_SESSION['galbridge_down_until'] = time() - 1;
        $factory = self::factory($config, self::JAN, $connects, $created);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
        self::assertSame(1, $created);
        self::assertArrayNotHasKey('galbridge_down_until', $_SESSION);
        self::assertSame('ok', $_SESSION['galbridge']['status'] ?? null);
    }

    /**
     * A query failure during the request (here: the outage is memoised after the Viewer was cached)
     * is shown to the user as "directory unavailable" - core's read actions never call get_error() -
     * at most once per request, however many methods and books fail.
     */
    #[DataProvider('engines')]
    public function testUnavailableNoticeIsShownOncePerRequest(string $dsn): void
    {
        $config = Env::config($dsn);
        self::assertNotNull(Env::factory($config, self::JAN)->resolver()->resolve()); // the Viewer is cached
        $_SESSION['galbridge_down_until'] = time() + Connection::OUTAGE_SECONDS;        // the server just failed
        self::resetUnavailableNotice();

        $notices = [];
        $factory = self::notifyingFactory($config, $notices);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()), 'the cached Viewer still lists the book');
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(50);
        self::assertSame(0, $book->list_records()->count);
        self::assertSame(0, $book->search('*', 'kowalski')->count);
        self::assertSame(0, $book->count()->count);
        self::assertSame([], $book->list_groups());
        self::assertNull($book->get_record('u_janDkowalskiAexampleDcom'));
        self::assertSame(['type' => \rcube_addressbook::ERROR_SEARCH, 'message' => 'galbridge.unavailable'], $book->get_error());
        // another instance in the same request (e.g. a second source of the same book)
        $again = $factory->create(BookDefinition::DOMAIN);
        $again->set_pagesize(50);
        self::assertSame(0, $again->list_records()->count);
        self::assertSame([['unavailable', []]], $notices, 'one notice per request');

        // the next request tells the user again
        self::resetUnavailableNotice();
        $notices = [];
        $book = self::notifyingFactory($config, $notices)->create(BookDefinition::DOMAIN);
        $book->set_pagesize(50);
        self::assertSame(0, $book->list_records()->count);
        self::assertSame([['unavailable', []]], $notices);
        self::assertStringNotContainsString('testonly-ro', implode("\n", $GLOBALS['galbridge_test_log']));
        self::resetUnavailableNotice();

        // a working directory shows nothing
        $_SESSION['galbridge_down_until'] = time() - 1;
        $notices = [];
        $book = self::notifyingFactory($config, $notices)->create(BookDefinition::DOMAIN);
        $book->set_pagesize(50);
        self::assertSame(13, $book->list_records()->count);
        self::assertSame([], $notices);
        self::assertNull($book->get_error());
    }

    /**
     * During an outage after the Viewer's TTL, the books of this session stay listed (from the
     * expired Viewer, without touching the database) as DeniedBooks that tell the user the directory
     * is unavailable - except for the sender-photo lookup of every opened message. Nothing is ever
     * read through the expired Viewer and nothing is cached.
     */
    #[DataProvider('engines')]
    public function testOutageAfterTheTtlKeepsTheBooksListedAsUnavailable(string $dsn): void
    {
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $config = Env::config($dsn, $options);
        self::assertNotNull(Env::factory($config, self::JAN)->resolver()->resolve());
        $_SESSION['galbridge']['at'] -= 3600;                                       // the TTL has expired
        $_SESSION['galbridge_down_until'] = time() + Connection::OUTAGE_SECONDS;    // and the server is down
        $expired = $_SESSION['galbridge'];

        $notices = [];
        $created = 0;
        $factory = self::notifyingFactory($config, $notices, 1, Env::IMAP_HOST, $created);
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys($factory->books()));
        self::assertSame('db_down', $factory->resolver()->status());
        self::assertNotNull($factory->resolver()->staleViewer());
        foreach ([BookDefinition::DOMAIN => 'domainbook', BookDefinition::GLOBAL => 'globalbook'] as $id => $name) {
            $notices = [];
            self::resetUnavailableNotice(); // one request per book
            $book = $factory->create($id);
            self::assertInstanceOf(DeniedBook::class, $book, $id);
            self::assertSame($name, $book->get_name());
            self::assertFalse($book->ready, 'core skips it in the compose list and mailto');

            // the sender photo of every opened message: never a notice
            self::assertSame(0, $book->search(['email'], 'bob@example.org', 1, true, true, 'photo')->count);
            self::assertSame(0, $book->search(['email'], 'bob@example.org', 1, true, true, ['photo'])->count);
            self::assertSame([], $notices, $id . ': no notice for the photo lookup');

            // a real read: the notice, once for this book
            self::assertSame(0, $book->search('*', 'jan')->count);
            self::assertSame(0, $book->list_records()->count);
            self::assertSame(0, $book->count()->count);
            self::assertNull($book->get_record('u_janDkowalskiAexampleDcom'));
            self::assertSame([], $book->list_groups());
            self::assertSame([['unavailable', []]], $notices, $id);
        }
        // both books in ONE request: one notice in total (shared with AddressBook::failed())
        self::resetUnavailableNotice();
        $notices = [];
        $factory->create(BookDefinition::DOMAIN)->search('*', 'jan');
        $factory->create(BookDefinition::GLOBAL)->search('*', 'jan');
        self::assertSame([['unavailable', []]], $notices, 'one notice per request for all books');
        self::assertSame(0, $created, 'the database was not contacted');
        self::assertSame($expired, $_SESSION['galbridge'], 'the expired Viewer is neither refreshed nor replaced');

        // no expired Viewer in this session: nothing to list, nothing to say
        $_SESSION = ['galbridge_down_until' => time() + Connection::OUTAGE_SECONDS];
        $notices = [];
        $factory = self::notifyingFactory($config, $notices);
        self::assertSame([], $factory->books());
        self::assertSame(0, $factory->create(BookDefinition::DOMAIN)->list_records()->count);
        self::assertSame([], $notices);

        // the expired Viewer is bound to its context: another user, another configuration, or an
        // entry that was not a positive decision is never listed
        $variants = [
            'another user' => [$expired, $config, 2],
            'another configuration' => [$expired, Env::config($dsn, ['galbridge_global_book' => false] + $options), 1],
            'a negative decision' => [['status' => 'no_mailbox', 'viewer' => null] + $expired, $config, 1],
        ];
        foreach ($variants as $label => [$cache, $cfg, $uid]) {
            $_SESSION = ['galbridge' => $cache, 'galbridge_down_until' => time() + Connection::OUTAGE_SECONDS];
            $factory = self::notifyingFactory($cfg, $notices, $uid);
            self::assertSame([], $factory->books(), $label);
            self::assertNull($factory->resolver()->staleViewer(), $label);
        }

        // not a database error (IMAP host not allowed): no stale listing either
        $_SESSION = ['galbridge' => $expired, 'galbridge_down_until' => time() + Connection::OUTAGE_SECONDS];
        $factory = self::notifyingFactory($config, $notices, 1, 'imap.evil.example');
        self::assertSame([], $factory->books());
        self::assertSame('host_mismatch', $factory->resolver()->status());

        // the server is back: the real books again
        $_SESSION = ['galbridge' => $expired];
        $factory = self::notifyingFactory($config, $notices);
        self::assertInstanceOf(AddressBook::class, $factory->create(BookDefinition::DOMAIN));
        self::assertSame('ok', $factory->resolver()->status());
        self::assertGreaterThan($expired['at'], $_SESSION['galbridge']['at'] ?? 0, 'refreshed');
    }

    /**
     * The same for a database that answers with errors (db_error, e.g. a broken table) instead of
     * refusing connections: the books stay listed from the expired Viewer and say "unavailable".
     */
    public function testQueryErrorsAfterTheTtlKeepTheBooksListedAsUnavailable(): void
    {
        $file = sys_get_temp_dir() . '/galbridge-dberror-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $dir = dirname(__DIR__) . '/fixtures/vmail/';
            $pdo->exec((string) file_get_contents($dir . 'schema.sqlite.sql'));
            $pdo->exec((string) file_get_contents($dir . 'seed.sql'));
            $pdo = null;
            $config = Env::config('sqlite:///' . $file . '?mode=0600');
            self::assertNotNull(Env::factory($config, self::JAN)->resolver()->resolve());
            $_SESSION['galbridge']['at'] -= 3600;
            $expired = $_SESSION['galbridge'];

            file_put_contents($file, str_repeat('not a database ', 512)); // every statement fails now
            self::resetUnavailableNotice();
            $notices = [];
            $factory = self::notifyingFactory($config, $notices);
            self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
            self::assertSame('db_error', $factory->resolver()->status());
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(DeniedBook::class, $book);
            self::assertSame(0, $book->search(['email'], self::JAN, 1, true, true, 'photo')->count);
            self::assertSame([], $notices);
            self::assertSame(0, $book->search('*', 'jan')->count);
            self::assertSame([['unavailable', []]], $notices);
            self::assertSame($expired, $_SESSION['galbridge'], 'a query error is not an entitlement decision');
            self::assertStringContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']));
        } finally {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------ organization search in the global book

    /**
     * The global book never lists a whole organization from a prefix of its name (as for domain
     * fragments of addresses): only the complete label matches there. The domain book keeps the
     * normal search modes.
     */
    #[DataProvider('engines')]
    public function testGlobalBookMatchesOnlyTheCompleteOrganizationLabel(string $dsn): void
    {
        $options = [
            'galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'organization'],
            'galbridge_organizations' => ['Example Group' => ['example.com'], 'Acme' => ['example.org'], 'Bücher Gruppe' => ['xn--bcher-kva.example']],
            'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org', 'xn--bcher-kva.example'],
        ];
        $global = Env::factory(Env::config($dsn, $options), self::JAN)->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $global);
        $global->set_pagesize(50);

        foreach (['ac', 'acm', 'Ac', 'cme'] as $prefix) {
            self::assertSame([], self::emails($global->search('*', $prefix)), $prefix);
            self::assertSame([], self::emails($global->search(['organization'], $prefix)), $prefix);
            self::assertSame([], self::emails($global->search(['organization'], [$prefix])), $prefix . ' (advanced)');
            foreach ([\rcube_addressbook::SEARCH_PREFIX, \rcube_addressbook::SEARCH_ALL] as $mode) {
                self::assertSame([], self::emails($global->search(['name', 'organization'], $prefix, $mode)), $prefix . ' mode ' . $mode);
            }
        }
        // the complete label lists the organization
        $acme = self::emails($global->search('*', 'acme'));
        self::assertContains('bob@example.org', $acme);
        self::assertSame(['example.org'], self::domains($acme));
        self::assertSame($acme, self::emails($global->search(['organization'], 'ACME')));
        // a label of several words: only as a whole (strict search)
        self::assertSame([], self::emails($global->search(['organization'], 'bücher')));
        self::assertSame(['klaus@xn--bcher-kva.example'], self::emails($global->search(['organization'], 'Bücher Gruppe', \rcube_addressbook::SEARCH_STRICT)));
        self::assertSame('Acme', $global->get_record('u_bobAexampleDorg', true)['organization'] ?? null);

        // the domain book: a partial organization search still works
        $domain = Env::factory(Env::config($dsn, $options), self::JAN)->create(BookDefinition::DOMAIN);
        $domain->set_pagesize(50);
        self::assertSame(self::JAN_DOMAIN_BOOK, self::emails($domain->search(['organization'], 'grou')));
    }

    // ------------------------------------------------------------------ helpers

    private static function domainBook(string $dsn, string $login): AddressBook
    {
        $book = Env::factory(Env::config($dsn), $login)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        return $book;
    }

    /**
     * A resolver on a session that is NOT reset (Env::factory starts a new session).
     */
    private static function resolver(Config $config, string $login, int $uid = 1, string $host = Env::IMAP_HOST, int &$connects = 0): ViewerResolver
    {
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            $connects++;
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        return new ViewerResolver($config, $connect, $uid, $login, $host, $host, Env::IMAP_PORT);
    }

    /**
     * A factory on the current session with the user-visible notices collected (and the rcube_db
     * instances created counted).
     *
     * @param list<array{string, array<string, string>}> $notices
     */
    private static function notifyingFactory(Config $config, ?array &$notices, int $uid = 1, string $host = Env::IMAP_HOST, int &$created = 0): Factory
    {
        $notices = [];
        $connection = null;
        $connect = static function () use ($config, &$connection, &$created): Connection {
            return $connection ??= new Connection(
                new Dsn($config->string('galbridge_db_dsn'), $config),
                3,
                static function (string $dsn) use (&$created): \rcube_db {
                    $created++;
                    return \rcube_db::factory($dsn, '', false);
                },
            );
        };
        $resolver = new ViewerResolver($config, $connect, $uid, self::JAN, $host, $host, Env::IMAP_PORT);
        $notify = static function (string $label, array $vars) use (&$notices): void {
            $notices[] = [$label, $vars];
        };
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l, 'list', $notify);
    }

    /**
     * AddressBook shows the "unavailable" notice once per request (a static flag): a new request.
     */
    private static function resetUnavailableNotice(): void
    {
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    /**
     * A factory on the current session that counts rcube_db instances created ($created).
     */
    private static function factory(Config $config, string $login, int &$connects = 0, int &$created = 0): Factory
    {
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects, &$created): Connection {
            $connects++;
            return $connection ??= new Connection(
                new Dsn($config->string('galbridge_db_dsn'), $config),
                3,
                static function (string $dsn) use (&$created): \rcube_db {
                    $created++;
                    return \rcube_db::factory($dsn, '', false);
                },
            );
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
    }

    /**
     * The same engine with a server that refuses connections.
     */
    private static function unreachable(string $dsn): string
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            return 'sqlite:///' . sys_get_temp_dir() . '?mode=0600'; // a directory: PDO cannot open it
        }
        return (string) preg_replace('~@[^/]+/~', '@127.0.0.1:1/', $dsn, 1);
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
     * Addresses of a result outside example.com.
     *
     * @return list<string>
     */
    private static function outsideHome(\rcube_result_set $rs): array
    {
        return array_values(array_filter(self::emails($rs), static fn (string $e): bool => !str_ends_with($e, '@example.com')));
    }

    /**
     * @param list<string> $emails
     *
     * @return list<string>
     */
    private static function domains(array $emails): array
    {
        $d = array_values(array_unique(array_map(static fn (string $e): string => substr($e, (int) strrpos($e, '@') + 1), $emails)));
        sort($d);
        return $d;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
