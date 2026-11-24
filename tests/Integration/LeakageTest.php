<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Leakage canaries (docs/ARCHITECTURE.md 5, 7, 12, 14): with the widest configuration the plugin
 * allows, no record, coltypes entry, group, stored search set, book definition or log line ever
 * contains a password hash, the recovery address, allow_nets, a private forwarding target, an
 * external list member, settings, transports or the DSN password; a search for such a value finds
 * nothing (no oracle). Normal log lines never carry search terms (only with galbridge_debug).
 * Per-user alias addresses are neither shown nor matched unless galbridge_user_aliases is on.
 *
 * @license GPL-3.0-or-later
 */
final class LeakageTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    /** values in seed.sql that must never leave the database through GAL Bridge */
    private const CANARIES = [
        'SECRET-',                       // mailbox.password / admin.password hashes
        '{SSHA512}',
        'jan.private@gmail.test',        // mailbox.recovery_email
        'gmail.test',
        'private.destination@gmail.test', // forward-only target (is_forwarding)
        'jan.home@outlook.test',         // per-user forwarding target
        'outlook.test',
        '192.0.2.',                      // mailbox.allow_nets, domain transport relay host
        'partner@external.test',         // external alias member
        'external.test',
        'timezone:',                     // mailbox.settings
        'default_user_quota',            // domain.settings
        'example.com/j/a/n',             // maildir
        'relay:',                        // domain.transport
        'mlmmj:',                        // maillists.transport
        '0b4f8a2e-1c3d',                 // maillists.mlid
        'Comiesięczny',                  // maillists.description
        'testonly-ro',                   // the test DSN password
    ];

    /** search terms used by the flows below; normal log lines must not contain them */
    private const TERMS = ['Kowalski', 'zolkiew', 'Żółkiewska', 'Słowik', 'canaryterm'];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Log::setDebug(false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    /**
     * @return array<string, mixed>
     */
    private static function widest(): array
    {
        return [
            'galbridge_fields' => Config::FIELDS,
            'galbridge_user_aliases' => true,
            'galbridge_list_policy' => 'all',
            'galbridge_hide_expired' => false,
            'galbridge_organizations' => ['Acme' => ['example.com', 'example.org']],
            'galbridge_domain_groups' => 'department',
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['*'],
            'galbridge_global_allow_wildcard' => true,
            'galbridge_global_include_home' => true,
            'galbridge_global_searchonly' => false,
            'galbridge_global_groups' => 'domain',
            'galbridge_global_export' => true,
            'galbridge_global_max_results' => 5000,
        ];
    }

    #[DataProvider('engines')]
    public function testNoOutputEverContainsACanary(string $dsn): void
    {
        $factory = Env::factory(Env::config($dsn, self::widest()), self::JAN);
        $dump = [$factory->books()];
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = self::book($factory, $id);
            $dump[] = [$book->get_name(), $book->coltypes, $book->primary_key];

            $book->set_pagesize(200);
            $all = $book->list_records();
            self::assertGreaterThan(10, count($all->records), 'the widest configuration shows a lot');
            $dump[] = $all->records;
            foreach ($all->records as $r) {
                $dump[] = $book->get_record($r['ID'], true);
                $dump[] = $book->get_record_groups($r['ID']);
            }

            // full-set requests: export, compose-to-group / group expansion
            $book->set_pagesize(99999);
            $dump[] = $book->list_records()->records;
            $groups = $book->list_groups();
            self::assertNotSame([], $groups);
            $dump[] = $groups;
            foreach ($groups as $g) {
                $book->reset();
                $book->set_group($g['ID']);
                $dump[] = $book->get_group($g['ID']);
                $dump[] = $book->list_records()->records;
            }
            $book->set_group(0);

            foreach (['a', 'jan', 'example', 'Sprzedaż', '+48', '19'] as $term) {
                foreach ([0, \rcube_addressbook::SEARCH_PREFIX] as $mode) {
                    $book->set_pagesize(9999);
                    $dump[] = $book->search('*', $term, $mode)->records;
                    $dump[] = $book->get_search_set();
                }
            }
        }
        self::assertNoCanary(serialize($dump));
        self::assertNoCanary(implode("\n", $GLOBALS['galbridge_test_log']));
    }

    /**
     * @return array<string, array{string, mixed, mixed, int}>
     */
    public static function canarySearches(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach ([
                'recovery address' => [['email'], 'jan.private@gmail.test', \rcube_addressbook::SEARCH_STRICT],
                'recovery domain' => ['*', 'gmail', 0],
                'forward-only target' => ['*', 'private.destination', 0],
                'forwarding copy target' => [['email'], 'jan.home', \rcube_addressbook::SEARCH_PREFIX],
                'forwarding domain' => ['*', 'outlook', 0],
                'allow_nets' => ['*', '192.0.2', 0],
                'password hash' => ['*', 'SECRET', 0],
                'hash scheme' => ['*', 'SSHA512', 0],
                'external member' => ['*', 'partner', 0],
                'external domain' => [['email'], 'external.test', 0],
                'employee id' => ['*', 'E001', 0],
                'settings' => ['*', 'timezone', 0],
                'maildir' => ['*', 'j/a/n', 0],
                'list transport' => ['*', 'mlmmj', 0],
                'list description' => ['*', 'Comiesięczny', 0],
                'catch-all row' => [['email'], 'example.com', \rcube_addressbook::SEARCH_STRICT],
                'advanced on email' => [['email'], ['jan.private@gmail.test'], 0],
            ] as $case => [$fields, $value, $mode]) {
                $out["{$engine}: {$case}"] = [$dsn, $fields, $value, $mode];
            }
        }
        return $out;
    }

    /**
     * Non-directory columns and rows are no search oracle: a search for their values finds nothing,
     * in either book, in any mode.
     */
    #[DataProvider('canarySearches')]
    public function testSearchingForACanaryFindsNothing(string $dsn, mixed $fields, mixed $value, int $mode): void
    {
        $factory = Env::factory(Env::config($dsn, self::widest()), self::JAN);
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = self::book($factory, $id);
            $book->set_pagesize(9999);
            self::assertSame([], self::emails($book->search($fields, $value, $mode)), $id);
            self::assertSame(0, $book->search($fields, $value, $mode, false)->count, $id);
        }
    }

    #[DataProvider('engines')]
    public function testNormalLogLinesNeverContainSearchTerms(string $dsn): void
    {
        $this->exerciseLoggingPaths($dsn);
        $log = $GLOBALS['galbridge_test_log'];
        self::assertNotSame([], $log, 'the flows must have produced log lines');
        foreach ($log as $line) {
            foreach (self::TERMS as $term) {
                self::assertStringNotContainsStringIgnoringCase($term, $line);
            }
            self::assertStringNotContainsString('@example.', $line, 'no addresses in normal log lines: ' . $line);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $line, 'no control characters');
        }
        self::assertNoCanary(implode("\n", $log));
    }

    #[DataProvider('engines')]
    public function testDebugLogNeverContainsSecrets(string $dsn): void
    {
        Log::setDebug(true);
        $this->exerciseLoggingPaths($dsn);
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertNoCanary($log);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F\x7F]/', $log);
    }

    // ------------------------------------------------------------------ per-user alias addresses

    #[DataProvider('engines')]
    public function testUserAliasesOffAreNeitherShownNorSearchable(string $dsn): void
    {
        $book = self::book(Env::factory(Env::config($dsn), 'zofia.zolkiewska@example.com'), BookDefinition::DOMAIN);
        self::assertSame(['work'], $book->coltypes['email']['subtypes']);
        $jan = (array) $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertSame(['jan.kowalski@example.com'], $jan['email:work'] ?? null);
        self::assertArrayNotHasKey('email:other', $jan);

        // a search cannot reveal who owns an alias address
        $book->set_pagesize(9999);
        foreach ([
            [['email'], 'sprzedaz@example.com', \rcube_addressbook::SEARCH_STRICT],
            [['email'], 'jk@example.com', \rcube_addressbook::SEARCH_STRICT],
            [['email'], 'jan@example.net', \rcube_addressbook::SEARCH_STRICT],
            [['email'], 'jk', \rcube_addressbook::SEARCH_PREFIX],
            ['*', 'example.net', 0],
            ['*', 'sprzedaz@', 0],
        ] as [$fields, $value, $mode]) {
            self::assertNotContains('jan.kowalski@example.com', self::emails($book->search($fields, $value, $mode)), (string) $value);
        }
        // an alias address is not a contact of its own
        self::assertNull($book->get_record(IdCodec::contactId(IdCodec::PERSON, 'sprzedaz@example.com')));
        self::assertNull($book->get_record(IdCodec::contactId(IdCodec::PERSON, 'jk@example.com')));
    }

    #[DataProvider('engines')]
    public function testUserAliasesOnShowOnlyActiveAliasesOfVisiblePersons(string $dsn): void
    {
        $book = self::book(Env::factory(Env::config($dsn, ['galbridge_user_aliases' => true]), 'zofia.zolkiewska@example.com'), BookDefinition::DOMAIN);
        self::assertSame(['work', 'other'], $book->coltypes['email']['subtypes']);
        $jan = (array) $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertSame(['jan@example.net', 'jk@example.com', 'sprzedaz@example.com'], $jan['email:other'] ?? null);

        $book->set_pagesize(9999);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search(['email'], 'sprzedaz@example.com', \rcube_addressbook::SEARCH_STRICT)));
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search(['email'], 'jk', \rcube_addressbook::SEARCH_PREFIX)));

        // inactive alias rows, aliases of inactive users and of other tenants stay invisible
        $zofia = (array) $book->get_record('u_zofiaDzolkiewskaAexampleDcom', true);
        self::assertArrayNotHasKey('email:other', $zofia);
        foreach (['zz.inactive-alias@example.com', 'old.disabled@example.com', 'bob.smith@example.org'] as $address) {
            self::assertSame([], self::emails($book->search(['email'], $address, \rcube_addressbook::SEARCH_STRICT)), $address);
        }
        // aliases are extra addresses of a person, never contacts of their own
        self::assertNull($book->get_record(IdCodec::contactId(IdCodec::PERSON, 'sprzedaz@example.com')));
        $book->reset();
        $book->set_pagesize(200);
        self::assertSame(12, $book->list_records()->count, 'aliases never multiply rows (zofia sees 12 entries)');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Error and refusal paths that write log lines, fed with values that contain search terms.
     */
    private function exerciseLoggingPaths(string $dsn): void
    {
        $options = self::widest() + ['galbridge_max_group_members' => 1, 'galbridge_max_ids' => 5];
        $options['galbridge_global_export'] = false;
        $factory = Env::factory(Env::config($dsn, $options), self::JAN, 'autocomplete');
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $book = self::book($factory, $id);
            foreach (self::TERMS as $term) {
                $book->search('*', $term);
                $book->search(['email', 'name'], [$term, $term]);
                $book->search('*', $term . "\0" . $term);              // NUL byte inside a term
                $book->search('*', str_repeat($term, 40));              // longer than the maximum
                $book->search(['nosuchfield'], [$term]);
                $book->get_record('u_' . $term . 'XZZ');                 // invalid contact ID
                $book->get_record('u_' . strtolower($term) . 'AexampleDcom'); // valid, unknown
                $book->set_group('dp_' . $term . 'XZZ');                 // invalid group ID
                $book->list_records();
                $book->set_group(null);
                $book->set_search_set(['galbridge' => 1, 'type' => 'quick', 'book' => $id, 'fp' => 'x', 'values' => [$term]]);
                $book->list_records();
            }
            // more IDs than galbridge_max_ids
            $ids = [];
            foreach (range(1, 6) as $i) {
                $ids[] = IdCodec::contactId(IdCodec::PERSON, 'canaryterm' . $i . '@example.com');
            }
            $book->search('ID', implode(',', $ids));
            // a group larger than galbridge_max_group_members (compose-to-group)
            $book->reset();
            foreach ($book->list_groups() as $g) {
                $book->set_group($g['ID']);
                $book->set_pagesize(9999);
                $book->list_records();
            }
            // whole-book export
            $book->set_group(0);
            $book->set_pagesize(99999);
            $book->list_records();
        }
        // the plugin's own refusal reasons were exercised
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        foreach (['invalid_id', 'invalid_gid', 'too_many_ids', 'group_too_large', 'export_disabled'] as $code) {
            self::assertStringContainsString($code, $log);
        }
    }

    private static function book(Factory $factory, string $id): AddressBook
    {
        $book = $factory->create($id);
        self::assertInstanceOf(AddressBook::class, $book, $id);
        return $book;
    }

    private static function assertNoCanary(string $haystack): void
    {
        foreach (self::CANARIES as $canary) {
            self::assertStringNotContainsStringIgnoringCase($canary, $haystack, 'canary leaked: ' . $canary);
        }
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
}
