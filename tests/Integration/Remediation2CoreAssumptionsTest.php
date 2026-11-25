<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\PluginBoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-33: what the plugin assumes about the core.
 *
 *  (a) Roundcube's dispatcher finds the handler of "EXPORT", "Group_Expand" or "group-expand" alike
 *      (class names ignore case, '-' becomes '_'). Every decision of the plugin that depends on the
 *      action - the autocomplete switches, complete-or-refused exports, the refusal of a stale search
 *      export, the 204 answer of a refused export, groups as full sets - uses the canonical form,
 *      and the export hook no longer depends on the action at init time.
 *  (b) rcmail::contact_exists() adds the collected_senders / collected_recipients preferences to its
 *      sources without checking that they are writable books: a GAL Bridge book is removed there, so
 *      a directory entry never makes a sender "known" (remote content, receipts).
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2CoreAssumptionsTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    /** the decisions of the plugin that depend on the action (canonical form) */
    private const DECISIONS = ['compose', 'bounce', 'autocomplete', 'export', 'mailto', 'group-expand'];

    private ?PluginBoot $boot = null;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        \rcube::get_instance();
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        $this->boot?->restore();
        $this->boot = null;
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    public function testCanonicalAction(): void
    {
        foreach (['export' => 'export', 'Export' => 'export', 'EXPORT' => 'export', 'group_expand' => 'group-expand', 'Group-Expand' => 'group-expand',
            'GROUP_EXPAND' => 'group-expand', 'AutoComplete' => 'autocomplete', 'compose' => 'compose', '' => ''] as $action => $canonical) {
            self::assertSame($canonical, Factory::canonicalAction((string) $action), (string) $action);
        }
    }

    /**
     * A search export above galbridge_max_results and the export of a stale search are refused
     * whatever the spelling of the action.
     */
    #[DataProvider('engines')]
    public function testExportDecisionsHoldForEverySpelling(string $dsn): void
    {
        foreach (['export', 'EXPORT', 'Export'] as $action) {
            $notices = [];
            // (galbridge_max_results is at least 10; the fixture book has more entries with "example")
            $book = self::book($dsn, ['galbridge_max_results' => 10], $action, $notices);
            $book->search('*', 'example');
            // (the search page itself tells that its total is capped - a request of its own in core)
            $notices = [];
            $book->set_search_set($book->get_search_set());
            self::assertSame([], self::fullSet($book)->records, $action);
            self::assertSame([['exporttoolarge', ['max' => '10']]], $notices, $action);

            $notices = [];
            $book = self::book($dsn, [], $action, $notices);
            $book->search('*', 'Kowalski');
            $stored = $book->get_search_set();
            self::assertIsArray($stored);
            $book->set_search_set(['fp' => 'another-configuration'] + $stored);
            self::assertSame([], self::fullSet($book)->records, $action);
            self::assertSame(['incomplete'], array_column($notices, 0), $action . ': refused like a stale export');
        }
        // the plugin's own notifier: a refusal of an export of any spelling is a 204, never a message
        $this->boot = new PluginBoot();
        $this->boot->boot(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143', 'galbridge_domain_export' => false], 'addressbook', 'EXPORT');
        $book = $this->boot->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertSame([], self::fullSet($book)->records);
        self::assertSame([], $this->boot->messages, 'no message: the export hook answers 204');
        self::assertTrue((new \ReflectionProperty(\galbridge::class, 'exportRefused'))->getValue($this->boot->plugin));
    }

    /**
     * Group expansion in any spelling is a full set: all members or a refusal, whatever page size
     * is asked for.
     */
    #[DataProvider('engines')]
    public function testGroupExpandSpellingsAreFullSets(string $dsn): void
    {
        $sales = IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż');
        foreach (['group-expand', 'GROUP-EXPAND', 'group_expand', 'Mailto'] as $action) {
            $notices = [];
            $book = self::book($dsn, ['galbridge_max_group_members' => 1], $action, $notices);
            $book->set_group($sales);
            $book->set_page(1);
            $book->set_pagesize(5);
            self::assertSame([], $book->list_records()->records, $action);
            self::assertSame([['grouptoolarge', ['max' => '1']]], $notices, $action);
        }
        // an ordinary list of the group is a page, not a full set
        $notices = [];
        $book = self::book($dsn, ['galbridge_max_group_members' => 1], 'list', $notices);
        $book->set_group($sales);
        $book->set_page(1);
        $book->set_pagesize(5);
        self::assertNotSame([], $book->list_records()->records);
        self::assertSame([], $notices);
    }

    #[DataProvider('engines')]
    public function testTheAutocompleteSwitchHoldsForEverySpelling(string $dsn): void
    {
        foreach (['autocomplete', 'AutoComplete', 'AUTOCOMPLETE'] as $action) {
            $book = self::book($dsn, ['galbridge_domain_autocomplete' => false], $action);
            self::assertSame(0, $book->search(['name', 'email'], 'kowal', 0, true)->count, $action);
            self::assertSame([], $book->list_groups(), $action);
        }
        self::assertNotSame(0, self::book($dsn, ['galbridge_domain_autocomplete' => false], 'search')->search(['name', 'email'], 'kowal', 0, true)->count);
    }

    /**
     * The autocomplete wiring at init time follows the canonical action as well.
     */
    public function testTheAutocompleteWiringFollowsTheCanonicalAction(): void
    {
        $this->boot = new PluginBoot();
        foreach (['Compose', 'BOUNCE', 'autoComplete'] as $action) {
            $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143'], 'mail', $action);
            self::assertSame(['sql', BookDefinition::DOMAIN], $this->boot->rc->config->get('autocomplete_addressbooks'), $action);
        }
    }

    /**
     * rcmail::contact_exists() never consults a GAL Bridge book: the hook is registered in the mail
     * task and removes the books of the plugin from the sources (the order of the others is kept).
     */
    public function testOwnBooksAreNeverAKnownContactSource(): void
    {
        $this->boot = new PluginBoot();
        $plugin = $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143', 'galbridge_global_book' => true], 'mail', 'show');
        self::assertSame([$plugin, 'contact_exists'], $this->boot->api->handlers['contact_exists'][0] ?? null);
        $result = $this->boot->api->exec_hook('contact_exists', ['email' => 'x@example.com', 'type' => 8, 'sources' => [BookDefinition::GLOBAL, '0', BookDefinition::DOMAIN, 'ldap_x', 1]]);
        self::assertSame(['0', 'ldap_x', 1], $result['sources']);
        self::assertSame('x@example.com', $result['email']);
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'no database access');

        // through core's own method: a collected_senders preference that names the domain book finds
        // nothing there - jan.kowalski is in the directory, not a known sender
        $this->boot->rc->config->set('collected_senders', BookDefinition::DOMAIN);
        self::assertFalse($this->boot->rc->contact_exists(self::JAN, \rcube_addressbook::TYPE_TRUSTED_SENDER));
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'the directory was not even asked');
    }

    /**
     * The addressbook_export hook is registered for the addressbook task whatever the action at init
     * time (another plugin may change it later, or it may be spelled differently).
     */
    public function testTheExportHookDoesNotDependOnTheInitAction(): void
    {
        $this->boot = new PluginBoot();
        foreach (['export', 'EXPORT', 'list', ''] as $action) {
            $plugin = $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143'], 'addressbook', $action);
            self::assertSame([$plugin, 'addressbook_export'], $this->boot->api->handlers['addressbook_export'][0] ?? null, $action);
        }
        // and it acts only after a refusal: nothing happens for an export that was not refused
        $p = ['result' => 'unchanged'];
        self::assertSame($p, $plugin->addressbook_export($p));
    }

    /**
     * Roundcube maps some deprecated action names to others (rcmail_action_*_index::$aliases) without
     * changing the action the plugin sees: none of them leads to an action the plugin decides on.
     */
    public function testCoreAliasesNeverReachADecisionAction(): void
    {
        foreach ([\rcmail_action_mail_index::class, \rcmail_action_contacts_index::class] as $class) {
            self::assertTrue(class_exists($class), $class);
            $aliases = (new \ReflectionClass($class))->getStaticPropertyValue('aliases');
            self::assertIsArray($aliases);
            foreach ($aliases as $from => $to) {
                self::assertNotContains(Factory::canonicalAction((string) $to), self::DECISIONS, $class . ': ' . $from);
                self::assertNotContains(Factory::canonicalAction((string) $from), self::DECISIONS, $class . ': ' . $from);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed>                            $options
     * @param null|list<array{string, array<string, string>}> $notices
     */
    private static function book(string $dsn, array $options, string $action, ?array &$notices = null): AddressBook
    {
        $book = Env::factory(Env::config($dsn, $options), self::JAN, $action, $notices)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book, implode("\n", $GLOBALS['galbridge_test_log']));
        return $book;
    }

    /**
     * Core's full-set request: page 1 with a page size of 99999 (export, mailto, group-expand).
     */
    private static function fullSet(\rcube_addressbook $book): \rcube_result_set
    {
        $book->set_page(1);
        $book->set_pagesize(99999);
        return $book->list_records(null, 0, true);
    }
}
