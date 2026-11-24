<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * One regression test per verified defect of the replaced plugin "Roundcube-SQL-Global-Address-Books"
 * (sql_global_addressbooks 2.5.0: sql_global_addressbooks.php, sql_global_backend.php, SQL/*.sql).
 * Each test reproduces the situation that broke the old plugin and asserts GAL Bridge's correct
 * behaviour, on every engine.
 */
final class OldPluginRegressionTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private const BOB = 'bob@example.org';

    private const JAN_BOOK_IDS = [
        'a_boardAexampleDcom', 'a_everyoneAexampleDcom', 'a_internalAexampleDcom', 'a_legacyAexampleDcom',
        'a_teamAexampleDcom', 'l_newsletterAexampleDcom', 'u_annaDnowakAexampleDcom', 'u_forwarderAexampleDcom',
        'u_janDkowalskiAexampleDcom', 'u_lukaszDslowikAexampleDcom', 'u_pctUuserAexampleDcom', 'u_postmasterAexampleDcom',
        'u_zofiaDzolkiewskaAexampleDcom',
    ];

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
     * @param array<string, mixed>                       $options
     * @param list<array{string, array<string, string>}> $notices
     */
    private static function book(string $dsn, array $options = [], string $login = self::JAN, string $source = BookDefinition::DOMAIN, string $action = '', ?array &$notices = null): \rcube_addressbook
    {
        $book = Env::factory(Env::config($dsn, $options), $login, $action, $notices)->create($source);
        $book->set_sort_order((string) \rcube::get_instance()->config->get('addressbook_sort_col'));
        return $book;
    }

    /**
     * @param null|iterable<array<string, mixed>> $rs
     *
     * @return list<array<string, mixed>>
     */
    private static function records(?iterable $rs): array
    {
        $out = [];
        foreach ($rs ?? [] as $record) {
            $out[] = $record;
        }
        return $out;
    }

    /**
     * @param null|iterable<array<string, mixed>> $rs
     *
     * @return list<string>
     */
    private static function addresses(?iterable $rs): array
    {
        $out = array_map(static fn (array $r): string => (string) (\rcube_addressbook::get_col_values('email', $r, true)[0] ?? ''), self::records($rs));
        sort($out);
        return $out;
    }

    private static function id(string $address, string $kind = IdCodec::PERSON): string
    {
        return IdCodec::contactId($kind, $address);
    }

    /**
     * Run $fn and return every PHP warning, notice and deprecation it raised.
     *
     * @return list<string>
     */
    private static function phpErrors(callable $fn): array
    {
        $errors = [];
        set_error_handler(static function (int $no, string $message, string $file, int $line) use (&$errors): bool {
            $errors[] = $message . ' at ' . basename($file) . ':' . $line;
            return true;
        }, E_ALL);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $errors;
    }

    /**
     * The global book configured as the old "global" book: every hosted domain.
     *
     * @return array<string, mixed>
     */
    private static function wildcardGlobal(): array
    {
        return [
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['*'],
            'galbridge_global_allow_wildcard' => true,
            'galbridge_global_searchonly' => false,
        ];
    }

    // ------------------------------------------------------------------ 1

    /**
     * Defect 1: sql_global_backend declared reset() and set_search_set() without ': void'. Roundcube
     * 1.7 declares both abstract methods ': void', so loading the old backend is a fatal error.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect01BackendDeclarationsAreCompatibleWithRoundcube17(string $dsn): void
    {
        foreach (['reset', 'set_search_set'] as $method) {
            self::assertSame('void', (string) (new \ReflectionMethod(\rcube_addressbook::class, $method))->getReturnType(), 'the 1.7 contract');
        }
        foreach ([AddressBook::class, DeniedBook::class] as $class) {
            self::assertTrue(is_subclass_of($class, \rcube_addressbook::class));
            self::assertFalse((new \ReflectionClass($class))->isAbstract(), $class . ' implements every abstract method');
            foreach (['reset', 'set_search_set'] as $method) {
                self::assertSame('void', (string) (new \ReflectionMethod($class, $method))->getReturnType(), $class . '::' . $method);
            }
            // every override keeps the parent's untyped parameters, defaults and by-reference flags
            foreach ((new \ReflectionClass($class))->getMethods() as $m) {
                if ($m->getDeclaringClass()->getName() !== $class || !method_exists(\rcube_addressbook::class, $m->getName())) {
                    continue;
                }
                $parent = new \ReflectionMethod(\rcube_addressbook::class, $m->getName());
                $label = $class . '::' . $m->getName();
                self::assertSame($parent->getNumberOfParameters(), $m->getNumberOfParameters(), $label);
                foreach ($m->getParameters() as $i => $param) {
                    $p = $parent->getParameters()[$i];
                    self::assertSame($p->getName(), $param->getName(), $label);
                    self::assertFalse($param->hasType() && !$p->hasType(), $label . ': a typed parameter is an incompatible declaration');
                    self::assertSame($p->isOptional(), $param->isOptional(), $label);
                    self::assertSame($p->isPassedByReference(), $param->isPassedByReference(), $label);
                    if ($p->isDefaultValueAvailable()) {
                        self::assertSame($p->getDefaultValue(), $param->getDefaultValue(), $label);
                    }
                }
                if ($parent->hasReturnType()) {
                    self::assertSame((string) $parent->getReturnType(), (string) $m->getReturnType(), $label);
                }
            }
        }
        // and the book works in the 1.7 runtime
        $book = self::book($dsn);
        $book->reset();
        $book->set_search_set(null);
        $book->set_pagesize(50);
        $book->reset();
        self::assertSame(13, $book->list_records()->count);
    }

    // ------------------------------------------------------------------ 2

    /**
     * Defect 2: addressbook_get instantiated a full book for 'global', 'domain' or any support-book ID
     * whatever the user's entitlement, so a crafted _source opened books the user was not given.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect02AddressbookGetHonoursEntitlementForEverySource(string $dsn): void
    {
        // the global book disabled: a crafted _source=galbridge_global gets an empty DeniedBook
        $book = self::book($dsn, [], self::JAN, BookDefinition::GLOBAL);
        self::assertInstanceOf(DeniedBook::class, $book);
        $book->set_pagesize(50);
        self::assertSame([], self::addresses($book->list_records()));
        self::assertSame([], self::addresses($book->search('*', 'bob')));
        self::assertNull($book->get_record(self::id(self::BOB), true));

        // the global book only for example.com viewers: bob is refused, jan entitled
        $options = ['galbridge_global_viewer_domains' => ['example.com']] + self::wildcardGlobal();
        self::assertInstanceOf(AddressBook::class, self::book($dsn, $options, self::JAN, BookDefinition::GLOBAL));
        $bobs = self::book($dsn, $options, self::BOB, BookDefinition::GLOBAL);
        self::assertInstanceOf(DeniedBook::class, $bobs);
        self::assertSame(0, $bobs->search('*', 'jan')->count);
        self::assertArrayNotHasKey(BookDefinition::GLOBAL, Env::factory(Env::config($dsn, $options), self::BOB)->books());

        // the domain book only for example.com viewers
        $options = ['galbridge_domain_viewer_domains' => ['example.com']];
        self::assertInstanceOf(AddressBook::class, self::book($dsn, $options, self::JAN));
        self::assertInstanceOf(DeniedBook::class, self::book($dsn, $options, self::BOB));

        // the old IDs are not ours: core resolves them (or reports them) itself
        foreach (['global', 'domain', 'Support Contacts', 'webdevguru.co.uk'] as $old) {
            self::assertFalse(Factory::isOwnId($old), $old);
        }
    }

    // ------------------------------------------------------------------ 3

    /**
     * Defect 3: set_group() stored the raw _gid and list_records() ran "WHERE domain = <gid>" on any
     * book, so _gid=<other tenant's domain> listed that tenant (cross-tenant enumeration).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect03GroupIdCannotEnumerateAnotherTenant(string $dsn): void
    {
        $crafted = [
            'example.org',                                                  // the old plugin's gid format
            'xn--bcher-kva.example',
            IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.org'),     // a well-formed domain group
            IdCodec::groupId('g', IdCodec::GROUP_DOMAIN, 'example.org'),     // ... of the global book
            IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sales'),       // bob's department
        ];
        foreach ([[], ['galbridge_organizations' => ['Acme' => ['example.com', 'xn--bcher-kva.example']]]] as $options) {
            foreach ($crafted as $gid) {
                $label = $gid . ' ' . json_encode($options);
                $book = self::book($dsn, $options, self::JAN, BookDefinition::DOMAIN, 'group-expand');
                $book->set_group($gid);
                $book->set_pagesize(9999);
                self::assertSame([], self::addresses($book->list_records()), $label);
                self::assertSame(0, $book->count()->count, $label);
                self::assertNull($book->get_group($gid), $label);
                self::assertNotContains('bob@example.org', self::addresses($book->search('*', 'bob')), $label);
            }
        }

        // with domain groups, only domains of the viewer's organization are groups
        $book = self::book($dsn, ['galbridge_organizations' => ['Acme' => ['example.com', 'xn--bcher-kva.example']]]);
        self::assertSame(['example.com', 'xn--bcher-kva.example'], array_column($book->list_groups(), 'name'));
        $book->set_group(IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'xn--bcher-kva.example'));
        $book->set_pagesize(9999);
        self::assertSame(['klaus@xn--bcher-kva.example'], self::addresses($book->list_records()), 'control: an in-scope domain group');
    }

    // ------------------------------------------------------------------ 4

    /**
     * Defect 4: get_record() ran "SELECT * FROM global_addressbook WHERE ID = ?" without any scope and,
     * on a miss, returned the PREVIOUS result (its $this->result was never cleared).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect04GetRecordIsScopedAndNeverReturnsThePreviousRecord(string $dsn): void
    {
        $book = self::book($dsn);
        self::assertSame(self::JAN, $book->get_record(self::id(self::JAN), true)['email:work'][0] ?? null);

        foreach ([self::id(self::BOB), self::id('klaus@xn--bcher-kva.example'), self::id('orgteam@example.org', IdCodec::ALIAS), '21981839', '1', self::id('nobody@example.com')] as $id) {
            self::assertNull($book->get_record($id, true), $id . ': another tenant or a miss');
            self::assertNull($book->get_result(), $id . ': the previous record is not returned');
            self::assertNull($book->get_record($id), $id);
            $book->get_record(self::id(self::JAN), true);
        }
    }

    // ------------------------------------------------------------------ 5

    /**
     * Defect 5: a primary-key search built "c.ID IN (...)" and then skipped set_search_set(), so
     * compose-to-selected (mailto) ran an unfiltered list_records() and put the whole book into To:.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect05ComposeToSelectedSendsOnlyTheSelectedContacts(string $dsn): void
    {
        $mailto = static function (\rcube_addressbook $contacts, mixed $cid): array {
            $contacts->set_page(1);
            $contacts->set_pagesize((is_array($cid) ? count($cid) : 1) + 2);
            $out = [];
            foreach ($contacts->search($contacts->primary_key, $cid, 0, true, true, 'email') as $rec) {
                $out[] = format_email_recipient(\rcube_addressbook::get_col_values('email', $rec, true)[0], $rec['name']);
            }
            return $out;
        };
        self::assertSame(['Anna Nowak <anna.nowak@example.com>'], $mailto(self::book($dsn), [self::id('anna.nowak@example.com')]));
        self::assertSame([], $mailto(self::book($dsn), ['21981839']), 'an old integer ID');
        self::assertSame([], $mailto(self::book($dsn), [self::id(self::BOB)]), 'another tenant');
        self::assertSame([], $mailto(self::book($dsn), ''), 'nothing selected');
        self::assertSame([], $mailto(self::book($dsn), []), 'nothing selected');
        // even with a huge page size the ID filter holds
        $book = self::book($dsn);
        $book->set_pagesize(9999);
        self::assertSame(['anna.nowak@example.com'], self::addresses($book->search('ID', self::id('anna.nowak@example.com'), 0, true, true)));
    }

    // ------------------------------------------------------------------ 6

    /**
     * Defect 6: count() always returned 1 and list_records() had no LIMIT (every request loaded the
     * whole view; paging and totals were wrong).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect06CountIsTheRealTotalAndListsArePaged(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(5);
        self::assertSame(13, $book->count()->count);
        $page = $book->list_records();
        self::assertSame(13, $page->count);
        self::assertCount(5, self::records($page), 'one page, not the whole view');
        $book->set_page(3);
        self::assertCount(3, self::records($book->list_records()));
        $book->set_page(1);
        $book->search('*', 'kowal', 0, false);
        self::assertSame(1, $book->count()->count);
        $book->reset();
        $book->set_group(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż'));
        self::assertSame(2, $book->count()->count);
        // counts are bounded by galbridge_max_count (minimum 100) and stay exact below the bound
        $capped = self::book($dsn, ['galbridge_max_count' => 100]);
        $capped->set_pagesize(5);
        self::assertSame(13, $capped->count()->count);
        self::assertSame(13, $capped->list_records()->count);
    }

    // ------------------------------------------------------------------ 7

    /**
     * Defect 7: search() ignored the mode (its parameter was named $strict, $mode and $AS were
     * undefined), so strict/prefix searches behaved like partial ones and raised warnings.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect07SearchModesAreHonouredWithoutUndefinedVariables(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        $results = [];
        $errors = self::phpErrors(static function () use ($book, &$results): void {
            foreach (['*', ['name'], ['name', 'firstname', 'surname', 'email'], 'email'] as $fields) {
                foreach ([0, 1, 2, 4, 5, 6] as $mode) {
                    $results[json_encode([$fields, $mode])] = self::addresses($book->search($fields, 'owa', $mode));
                }
            }
            $results['strict whole'] = self::addresses($book->search(['name'], 'kowalski', 1));
            $results['strict part'] = self::addresses($book->search(['name'], 'kowal', 1));
            $results['prefix word'] = self::addresses($book->search(['name'], 'kowal', 2));
        });
        self::assertSame([], $errors);
        self::assertSame(['anna.nowak@example.com', self::JAN], $results[json_encode(['*', 0])]);
        self::assertSame(['anna.nowak@example.com', self::JAN], $results[json_encode([['name'], 4])]);
        self::assertSame([], $results[json_encode(['*', 2])], 'prefix');
        self::assertSame([], $results[json_encode(['*', 6])], 'prefix | SEARCH_GROUPS');
        self::assertSame([], $results[json_encode(['*', 1])], 'strict');
        self::assertSame([], $results[json_encode([['name'], 5])], 'strict | SEARCH_GROUPS');
        self::assertSame([self::JAN], $results['strict whole']);
        self::assertSame([], $results['strict part']);
        self::assertSame([self::JAN], $results['prefix word']);
    }

    // ------------------------------------------------------------------ 8

    /**
     * Defect 8: the default global book (data_allowed ['*'], nothing hidden, no filter) called
     * count() on an undefined $x: a TypeError on PHP 8, i.e. the global book never listed.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect08DefaultGlobalBookWorksOnPhp8(string $dsn): void
    {
        $errors = self::phpErrors(function () use ($dsn): void {
            $book = self::book($dsn, self::wildcardGlobal(), self::JAN, BookDefinition::GLOBAL);
            self::assertInstanceOf(AddressBook::class, $book);
            $book->set_pagesize(50);
            $rs = $book->list_records();
            // every hosted domain except the viewer's own (galbridge_global_include_home = false)
            self::assertSame(['bob@example.org', 'klaus@xn--bcher-kva.example', 'orglist@example.org', 'orgteam@example.org'], self::addresses($rs));
            self::assertSame(4, $rs->count);
            self::assertSame(['bob@example.org'], self::addresses($book->search('*', 'bob')));
            self::assertSame(1, $book->count()->count);
        });
        self::assertSame([], $errors);

        // without the explicit wildcard opt-in, '*' is not "every tenant": no book, still no error
        $options = ['galbridge_global_allow_wildcard' => false] + self::wildcardGlobal();
        $factory = Env::factory(Env::config($dsn, $options), self::JAN);
        self::assertArrayNotHasKey(BookDefinition::GLOBAL, $factory->books());
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::GLOBAL));
        // the minimal configuration (global book on, no data domains): not listed, no error
        $factory = Env::factory(Env::config($dsn, ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*']]), self::JAN);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::GLOBAL));
    }

    // ------------------------------------------------------------------ 9

    /**
     * Defect 9: support books - address_sources() read '_sql_supportbook_list' while init() and
     * addressbook_get read '_sql_supportbook', and list_records() called array_shift() on an
     * assignment expression (an Error on PHP 8). Listed books did not open, opened books were not
     * listed. GAL Bridge replaces them with the global book whose listing and instance agree.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect09SupportStyleBookWorksAndListingAgreesWithTheInstance(string $dsn): void
    {
        // "support contacts of example.org, shown to everybody except example.org itself"
        $options = [
            'galbridge_global_book' => true,
            'galbridge_global_book_name' => 'Support Contacts',
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_viewer_domains_exclude' => ['example.org'],
            'galbridge_global_data_domains' => ['example.org'],
            // the old keys mean nothing to GAL Bridge
            '_sql_supportbook_list' => [['Support Contacts', 'example.com']],
            '_sql_supportbook' => [['Support Contacts', 'example.com']],
        ];
        foreach ([self::JAN => true, 'zofia.zolkiewska@example.com' => true, self::BOB => false, 'klaus@xn--bcher-kva.example' => true] as $login => $entitled) {
            $factory = Env::factory(Env::config($dsn, $options), $login);
            $listed = array_key_exists(BookDefinition::GLOBAL, $factory->books());
            $instance = $factory->create(BookDefinition::GLOBAL);
            self::assertSame($entitled, $listed, $login);
            self::assertSame($listed, $instance instanceof AddressBook, $login . ': listed <=> usable');
            self::assertSame($entitled ? [BookDefinition::DOMAIN, BookDefinition::GLOBAL] : [BookDefinition::DOMAIN], array_keys($factory->books()), $login . ': no extra source from the old keys');
            if ($entitled) {
                self::assertSame('Support Contacts', $instance->get_name());
                $instance->set_pagesize(50);
                self::assertSame(['bob@example.org'], self::addresses($instance->search('*', 'bob')), $login);
                self::assertSame(['bob@example.org'], self::addresses($instance->search(['name'], 'smith')), $login);
            } else {
                self::assertSame(0, $instance->search('*', 'bob')->count, $login);
            }
            foreach (array_keys($factory->books()) as $id) {
                self::assertTrue(Factory::isOwnId($id), $id);
            }
        }
    }

    // ------------------------------------------------------------------ 10

    /**
     * Defect 10: PHP 8.1/8.2 deprecations - explode() on a NULL e-mail (GROUP_CONCAT of a user without
     * aliases) and the dynamic property $this->cache in set_group().
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect10NoDeprecationsAndNoDynamicProperties(string $dsn): void
    {
        $books = ['AddressBook' => self::book($dsn), 'DeniedBook' => self::book($dsn, [], 'nobody@example.com')];
        foreach ($books as $label => $book) {
            $class = new \ReflectionClass($book);
            self::assertSame([], $class->getAttributes(\AllowDynamicProperties::class), $label . ': PHP 8.2 must be able to flag dynamic properties');
            $before = array_keys(get_mangled_object_vars($book));
            $errors = self::phpErrors(static function () use ($book): void {
                $book->set_page(null);
                $book->set_pagesize(null);
                $book->set_sort_order(null, null);
                $book->set_group(null);
                $book->set_group(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż'));
                $book->set_group(0);
                $book->set_search_set(null);
                $book->get_search_set();
                $book->reset();
                $book->list_records(null, null, null);
                $book->count();
                $book->search(null, null, null, null, null, null);
                $book->search('*', null);
                $book->search(['email'], null);
                $book->search('ID', null);
                $book->search('ID', [null]);
                $book->search(['name', 'email'], [null, null]);
                $book->get_record(null);
                $book->get_record(null, null);
                $book->list_groups(null, null);
                $book->get_group(null);
                $book->get_record_groups(null);
                $book->get_result();
                $book->get_name();
                $book->close();
            });
            self::assertSame([], $errors, $label);
            self::assertSame($before, array_keys(get_mangled_object_vars($book)), $label . ': no property was added at run time');
        }

        // every record has its address as a list, also for users without aliases (no explode(null))
        $book = self::book($dsn, ['galbridge_user_aliases' => true]);
        $book->set_pagesize(50);
        $errors = self::phpErrors(static function () use ($book): void {
            foreach ($book->list_records() as $record) {
                self::assertIsList($record['email:work']);
                self::assertNotEmpty($record['email:work']);
                foreach ($record as $value) {
                    self::assertNotNull($value);
                }
            }
        });
        self::assertSame([], $errors);
        $jan = $book->get_record(self::id(self::JAN), true);
        self::assertSame(['jan@example.net', 'jk@example.com', 'sprzedaz@example.com'], $jan['email:other'] ?? null);
        self::assertArrayNotHasKey('email:other', (array) $book->get_record(self::id('postmaster@example.com'), true));
    }

    // ------------------------------------------------------------------ 11

    /**
     * Defect 11: MySQL-only SQL (backticks hand-written into the view and queries), a DEFINER=root view
     * and a PostgreSQL dblink view with the vmail password embedded, read through Roundcube's own
     * connection; IDs were SUBSTR(UNIX_TIMESTAMP(created) - CHAR_LENGTH(password), -8): unstable,
     * colliding and derived from the password hash.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect11PortableSqlLeastPrivilegeAndStableIds(string $dsn): void
    {
        // the same book, with the same IDs, on SQLite, MariaDB/MySQL and PostgreSQL
        $first = self::book($dsn);
        $first->set_pagesize(50);
        $ids = array_column(self::records($first->list_records()), 'ID');
        sort($ids);
        self::assertSame(self::JAN_BOOK_IDS, $ids);
        // stable across requests, derived from the address only (never a hash, timestamp or row id)
        $second = self::book($dsn);
        $second->set_pagesize(50);
        $again = array_column(self::records($second->list_records()), 'ID');
        sort($again);
        self::assertSame($ids, $again);
        foreach (self::records($second->list_records()) as $record) {
            self::assertSame($record['email:work'][0], IdCodec::decodeContact($record['ID'])['address'] ?? null);
        }

        // no dblink, no embedded credentials, no view that WIDENS access (the old plugin's view handed
        // every row and column of vmail.mailbox to Roundcube's own role, with root's rights): a
        // dedicated column-level role
        foreach (['mysql', 'postgres'] as $flavour) {
            // the statements only (the comments explain what is NOT granted)
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/SQL/grants.' . $flavour . '.sql'));
            foreach (['DEFINER =', 'DEFINER=', 'dblink', 'global_addressbook', 'GRANT ALL', 'SELECT *', 'password)', ' password,', 'recovery_email', 'allow_nets'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $sql, $flavour . ': ' . $forbidden);
            }
            // exactly ONE view, and it narrows: seven columns of forwardings, three kinds of rows -
            // never the private forwarding targets - and the role gets the view INSTEAD of the table
            self::assertSame(1, preg_match_all('/\bVIEW\s+((?:vmail\.)?[a-z_]+)/', $sql, $views), $flavour);
            self::assertSame($flavour === 'mysql' ? 'vmail.galbridge_forwardings' : 'galbridge_forwardings', $views[1][0]);
            self::assertMatchesRegularExpression('/VIEW\s+\S+(?: WITH \(security_barrier = true\))? AS\s+SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active\s+FROM (?:vmail\.)?forwardings\s+WHERE is_alias = 1 OR is_list = 1 OR is_maillist = 1;/', $sql, $flavour);
            self::assertDoesNotMatchRegularExpression('/\bGRANT\b[^;]*\bON (?:vmail\.)?forwardings\b/', $sql, $flavour . ': no grant on the forwardings table');
            self::assertMatchesRegularExpression('/GRANT SELECT \([a-z_, ]+\) ON (?:vmail\.)?galbridge_forwardings TO /', $sql, $flavour);
            self::assertStringNotContainsString('is_forwarding', $sql, $flavour);
            // no credential: a placeholder where the verifier (MySQL/MariaDB) or the password goes
            self::assertGreaterThanOrEqual(1, preg_match_all("/(?:IDENTIFIED BY|IDENTIFIED WITH [a-z_0-9]+ AS|PASSWORD) '([^']*)'/", $sql, $m), $flavour);
            self::assertSame([$flavour === 'mysql' ? 'CHANGE_ME_PASSWORD_VERIFIER' : 'CHANGE_ME_RANDOM_PASSWORD'], array_values(array_unique($m[1])), $flavour . ': only a placeholder');
            if ($flavour === 'mysql') {
                // MariaDB keeps account statements verbatim in its logs: the template has no statement
                // that could take the password itself
                self::assertDoesNotMatchRegularExpression("/^[^-\n][^\n]*IDENTIFIED BY '/m", $sql);
            }
            self::assertMatchesRegularExpression('/GRANT SELECT \(username, name, domain, active, enabledeliver, expired\) ON (?:vmail\.)?mailbox/', $sql);
            // the iRedMail 1.7.3 columns are a separate statement, so a server without them can skip it;
            // the personal-data columns are not granted unless the administrator enables those fields
            self::assertMatchesRegularExpression('/GRANT SELECT \(first_name, last_name\) ON (?:vmail\.)?mailbox/', $sql);
            self::assertStringNotContainsString('telephone', $sql, $flavour . ': phone/mobile/birthday are commented out by default');
        }

        // iRedMail's own broad roles (the old PostgreSQL template told admins to use "vmail") and a
        // missing DSN (the old plugin used Roundcube's connection) are refused
        $config = Env::config($dsn);
        foreach (['mysql://vmail:x@127.0.0.1/vmail', 'pgsql://vmailadmin:x@127.0.0.1/vmail', 'mysql://root:x@localhost/vmail', 'pgsql://roundcube:x@127.0.0.1/vmail', ''] as $refused) {
            try {
                new Dsn($refused, $config);
                self::fail('accepted: ' . $refused);
            } catch (GalBridgeException $e) {
                self::assertSame('config_invalid', $e->getReason());
            }
        }

        // the test role itself was built from SQL/grants.*.sql: it cannot see the secret columns
        $connection = new Connection(new Dsn($dsn, $config), 3);
        $columns = $connection->visibleColumns();
        if ($connection->driver() !== 'sqlite') {
            foreach (Connection::SECRET_COLUMNS as $secret) {
                self::assertNotContains($secret, $columns['mailbox'], $secret);
            }
            self::assertNotContains('settings', $columns['mailbox']);
        }
        self::assertContains('username', $columns['mailbox']);
        $connection->close();
    }

    // ------------------------------------------------------------------ 12

    /**
     * Defect 12: advanced search interpolated any field name as a column ("department", "phone" ...
     * did not exist in the view: SQL errors), and $required, $select and $nocount were ignored (the
     * sender-photo request listed the whole book for every opened message).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect12UnknownFieldsRequiredSelectAndNocount(string $dsn): void
    {
        $book = self::book($dsn);
        $book->set_pagesize(50);
        foreach ([['name', 'nosuchcolumn'], ['notes'], ['name`; DROP TABLE mailbox; --'], ["name' OR '1'='1"], ['phone']] as $fields) {
            // advanced search (AND): any unknown field matches nothing
            self::assertSame(0, $book->search($fields, array_fill(0, count($fields), 'jan'), 0, false)->count, json_encode($fields) ?: '');
            self::assertSame([], self::addresses($book->list_records()), json_encode($fields) ?: '');
            // quick search (OR): unknown fields are ignored, never interpolated
            $expected = in_array('name', $fields, true) ? [self::JAN] : [];
            self::assertSame($expected, self::addresses($book->search($fields, 'jan')), json_encode($fields) ?: '');
        }
        self::assertEmpty($book->get_error(), 'no SQL error');

        // $select = false: count only
        $rs = $book->search('*', 'example', 0, false);
        self::assertSame(13, $rs->count);
        self::assertSame([], $rs->records);
        // $nocount: the count of the returned rows
        $book->set_pagesize(3);
        $rs = $book->search('*', 'example', 0, true, true);
        self::assertSame(3, $rs->count);
        self::assertCount(3, self::records($rs));
        // $required
        $book->set_pagesize(50);
        self::assertSame(0, $book->search(['email'], self::JAN, 1, true, true, 'photo')->count);
        self::assertSame(['anna.nowak@example.com', self::JAN, 'lukasz.slowik@example.com', 'zofia.zolkiewska@example.com'], self::addresses($book->search('*', 'example', 0, true, false, 'department')));
        self::assertSame([self::JAN], self::addresses($book->search(['email'], self::JAN, 1, true, true, 'email')));
    }

    // ------------------------------------------------------------------ 13

    /**
     * Defect 13: no runnable tests (a PHPUnit_Framework_TestCase class that never ran), no packaging
     * metadata, and documentation that contradicted the code (README "secure through obscurity";
     * SQL/README told admins to use the vmail role; MIT in README, a bit.ly license link in the code).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testDefect13RunnableTestsPackagingAndDocsMatchTheCode(string $dsn): void
    {
        $root = dirname(__DIR__, 2);

        // packaging and the test harness this very test runs in
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('roundcube-plugin', $composer['type']);
        self::assertSame('GPL-3.0-or-later', $composer['license']);
        self::assertSame('https://github.com/takenek/galbridge', $composer['homepage']);
        self::assertSame('TaKeN.PL Usługi Informatyczne Marek Królikowski', $composer['authors'][0]['name']);
        // the whole 1.7 branch is supported: from its first release up to (not including) 1.8
        self::assertSame('1.7.0', $composer['extra']['roundcube']['min-version']);
        self::assertSame('1.7.999', $composer['extra']['roundcube']['max-version']);
        self::assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);
        $phpunit = (string) file_get_contents($root . '/phpunit.xml.dist');
        self::assertStringContainsString('<directory>tests/Integration</directory>', $phpunit);
        self::assertStringContainsString('failOnDeprecation="true"', $phpunit);
        self::assertFileExists($root . '/LICENSE');
        self::assertStringContainsString('GNU GENERAL PUBLIC LICENSE', (string) file_get_contents($root . '/LICENSE'));

        // the plugin's own metadata agrees with the packaging
        require_once $root . '/galbridge.php';
        $info = \galbridge::info();
        self::assertSame($composer['license'], $info['license']);
        self::assertSame($composer['homepage'], $info['uri']);
        self::assertSame($composer['authors'][0]['name'], $info['vendor']);

        // documented defaults (docs/ARCHITECTURE.md) are the code's defaults
        $doc = (string) file_get_contents($root . '/docs/ARCHITECTURE.md');
        $documented = [
            'galbridge_max_ids' => ['/`galbridge_max_ids` \((\d+)\)/', 200],
            'galbridge_max_results' => ['/`galbridge_max_results` \(domain book, (\d+)\)/', 500],
            'galbridge_global_max_results' => ['/`galbridge_global_max_results`\s+\(global book, (\d+)\)/', 100],
            'galbridge_max_export' => ['/`galbridge_max_export` \((\d+)\)/', 2000],
            'galbridge_max_count' => ['/`galbridge_max_count` \((\d+)\)/', 10000],
            'galbridge_max_groups' => ['/`galbridge_max_groups` \((\d+);/', 50],
            'galbridge_min_search_length' => ['/`galbridge_min_search_length` \(default (\d+)/', 2],
            'galbridge_max_search_length' => ['/`galbridge_max_search_length` (\d+)/', 128],
            'galbridge_searchonly_threshold' => ['/`galbridge_searchonly_threshold` entries - persons, alias accounts and lists - default (\d+)/', 2000],
            'galbridge_scope_ttl' => ['/`galbridge_scope_ttl` \(default (\d+) s\)/', 300],
            'galbridge_db_connect_timeout' => ['/`galbridge_db_connect_timeout`, (\d+) s/', 2],
            'galbridge_db_timeout' => ['/`galbridge_db_timeout`, (\d+) s/', 3],
        ];
        foreach ($documented as $option => [$pattern, $value]) {
            self::assertSame(1, preg_match($pattern, $doc, $m), $option . ' is documented');
            self::assertSame($value, (int) $m[1], $option . ' (doc)');
            self::assertSame($value, Config::DEFAULTS[$option], $option . ' (code)');
        }
        self::assertSame(1, preg_match('/Refused roles \(default\):((?:\s+`[a-z0-9]+`,)+)\s+plus/', $doc, $m));
        preg_match_all('/`([a-z0-9]+)`/', $m[1], $roles);
        self::assertSame($roles[1], Config::DEFAULTS['galbridge_db_refused_users']);
        preg_match_all('/^\| `([a-z]+)` \| `[a-z]+`/m', $doc, $fields);
        self::assertSame(Config::FIELDS, $fields[1], 'the fields table of ARCHITECTURE 7');
        self::assertStringContainsString('`' . BookDefinition::DOMAIN . '`', $doc);
        self::assertStringContainsString('`' . BookDefinition::GLOBAL . '`', $doc);
        // the documented DeniedBook contract
        $denied = new DeniedBook('x');
        self::assertTrue($denied->readonly);
        self::assertFalse($denied->ready);
        self::assertFalse($denied->groups);
        self::assertFalse($denied->searchonly);
        self::assertStringContainsString('`readonly = true`, `ready = false`, `groups = false`, `searchonly = false`', $doc);

        // and the suite really runs against this engine with the fixture role
        $book = self::book($dsn);
        $book->set_pagesize(50);
        self::assertSame(13, $book->list_records()->count);
    }

    /**
     * tests/e2e/matrix.sh covers the oldest and the newest known release of the supported Roundcube
     * branch, the supported PHP branches and both iRedMail SQL editions, and a combination that cannot
     * run is a failure, never a silent skip (run here on a copy of the script with no Roundcube
     * present: every combination is MISSING and the matrix fails).
     */
    public function testE2eMatrixCountsMissingCombinationsAsFailures(): void
    {
        $script = dirname(__DIR__) . '/e2e/matrix.sh';
        $text = (string) file_get_contents($script);
        self::assertSame(1, preg_match('/^runs=\((.*?)^\)/ms', $text, $m));
        preg_match_all('/^\s*"([^"]+)"/m', $m[1], $entries);
        $expected = [];
        foreach (['1.7.0' => ['php8.1', 'php8.4'], Tool::LATEST_KNOWN_ROUNDCUBE => ['php8.1', 'php8.4', 'php8.5']] as $core => $phps) {
            foreach ($phps as $php) {
                foreach (['mysql', 'pgsql'] as $db) {
                    $expected[] = "{$core} {$php} {$db}";
                }
            }
        }
        self::assertEqualsCanonicalizing($expected, $entries[1]);

        $dir = sys_get_temp_dir() . '/galbridge-matrix-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        try {
            copy($script, $dir . '/matrix.sh');
            $proc = proc_open(['bash', $dir . '/matrix.sh', $dir . '/no-roundcube-here'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            self::assertNotSame(0, $code, $out);
            self::assertSame(4, substr_count($out, 'MISSING ' . $dir . '/no-roundcube-here/1.7.0 (not present)'), $out);
            self::assertSame(6, substr_count($out, 'MISSING ' . $dir . '/no-roundcube-here/' . Tool::LATEST_KNOWN_ROUNDCUBE . ' (not present)'), $out);
            self::assertStringContainsString('MATRIX: 0/10 combinations green', $out);
        } finally {
            self::rmTree($dir);
        }
    }

    private static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * The E2E setup replaces the plugin link atomically (a concurrent run must never create a
     * galbridge -> galbridge link inside the repository); the fixture directory documents itself.
     */
    public function testE2eSetupAndFixtureDocumentation(): void
    {
        $root = dirname(__DIR__, 2);
        $setup = (string) file_get_contents($root . '/tests/e2e/setup.sh');
        self::assertStringContainsString('ln -sfn "$PLUGIN" "$RC/plugins/galbridge"', $setup);
        self::assertStringNotContainsString('rm -f "$RC/plugins/galbridge"', $setup);
        self::assertDoesNotMatchRegularExpression('/\bln -s "\$PLUGIN"/', $setup, 'never ln -s without -n onto an existing link');
        self::assertFileDoesNotExist($root . '/galbridge', 'no self-referential link in the repository');

        self::assertStringContainsString('tests/fixtures/vmail/README.txt', (string) file_get_contents($root . '/.gitignore'));
        $readme = (string) file_get_contents($root . '/tests/fixtures/vmail/README.txt');
        foreach (['TEST-ONLY', 'TEST DATA', 'CANARIES', 'testonly-ro', 'load.sh', 'iRedMail 1.8.11'] as $text) {
            self::assertStringContainsString($text, $readme);
        }

        // the test role gets the commented-out personal-data grants (the suites exercise every field)
        $load = (string) file_get_contents($root . '/tests/fixtures/vmail/load.sh');
        self::assertStringContainsString('s/^-- GRANT /GRANT /', $load);
        foreach (['grants.mysql.sql', 'grants.postgres.sql'] as $template) {
            preg_match_all('/^-- (GRANT .*)$/m', (string) file_get_contents($root . '/SQL/' . $template), $m);
            self::assertCount(1, $m[1], $template);
            self::assertStringContainsString('(telephone, mobile, birthday)', $m[1][0], $template);
        }
    }
}
