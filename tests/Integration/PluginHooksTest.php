<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real galbridge plugin class on Roundcube 1.7.7's plugin API (docs/ARCHITECTURE.md 3, 15):
 * init() registers only addressbooks_list and addressbook_get and never touches the database; the
 * autocomplete options are wired for mail/compose and mail/autocomplete only; addressbooks_list
 * appends safe, HTML-escaped, read-only entries; addressbook_get ALWAYS answers our IDs with an
 * instance (core dies with error 700 otherwise) and never touches foreign IDs. Core's own
 * rcmail::get_address_sources() / get_address_book() are driven through the plugin as well.
 *
 * The rcmail singleton, user and output are built without a full rcmail startup (the MIME Shield
 * pattern); the previous singleton is restored afterwards.
 *
 * @license GPL-3.0-or-later
 */
final class PluginHooksTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private const ALLOWED_ENTRY_KEYS = ['id', 'name', 'readonly', 'groups', 'autocomplete', 'undelete', 'hidden', 'class_name'];

    private mixed $previousInstance = null;

    private string $pluginsDir = '';

    private \rcube_plugin_api $api;

    private \rcmail $rc;

    /** @var object{messages: list<array{0: string, 1: string, 2: mixed}>} */
    private object $output;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7.7 (GALBRIDGE_RC)');
        }
        require_once dirname(__DIR__, 2) . '/galbridge.php';
        $this->previousInstance = (new \ReflectionProperty(\rcube::class, 'instance'))->getValue();
        // plugins/galbridge with the plugin's localization only (no local config.inc.php can leak in)
        $this->pluginsDir = sys_get_temp_dir() . '/galbridge-plugins-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->pluginsDir . '/galbridge', 0700, true);
        symlink(dirname(__DIR__, 2) . '/localization', $this->pluginsDir . '/galbridge/localization');
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
    }

    protected function tearDown(): void
    {
        if ($this->pluginsDir !== '') {
            (new \ReflectionProperty(\rcube::class, 'instance'))->setValue(null, $this->previousInstance);
            @unlink($this->pluginsDir . '/galbridge/localization');
            @rmdir($this->pluginsDir . '/galbridge');
            @rmdir($this->pluginsDir);
        }
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

    // ------------------------------------------------------------------ init

    public function testInfoCarriesTheProjectAttribution(): void
    {
        $info = \galbridge::info();
        self::assertSame('GAL Bridge', $info['name'] ?? null);
        self::assertSame('TaKeN.PL Usługi Informatyczne Marek Królikowski', $info['vendor'] ?? null);
        self::assertSame('GPL-3.0-or-later', $info['license'] ?? null);
        self::assertSame('https://github.com/takenek/galbridge', $info['uri'] ?? null);
        self::assertSame('mail|addressbook', (new \ReflectionClass(\galbridge::class))->getDefaultProperties()['task'] ?? null);
    }

    public function testAnonymousRequestsRegisterNothing(): void
    {
        foreach ([null, 0] as $userId) {
            $this->boot(Env::sqliteDsn(), [], action: 'compose', userId: $userId);
            self::assertSame([], $this->api->handlers);
            self::assertSame(['sql'], $this->rc->config->get('autocomplete_addressbooks'));
            self::assertSame(1, $this->rc->config->get('autocomplete_min_length'));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requests(): array
    {
        return [
            'mail/show (sender photo of every message)' => ['mail', 'show'],
            'mail/list' => ['mail', 'list'],
            'mail/compose' => ['mail', 'compose'],
            'mail/autocomplete' => ['mail', 'autocomplete'],
            'mail/group-expand' => ['mail', 'group-expand'],
            'addressbook index' => ['addressbook', ''],
            'addressbook/photo' => ['addressbook', 'photo'],
            'addressbook/search' => ['addressbook', 'search'],
        ];
    }

    /**
     * init() runs on every request of the two tasks: it must never connect (a refused DSN would be
     * logged, an unreachable one memoised).
     */
    #[DataProvider('requests')]
    public function testInitRegistersTwoHooksAndNeverTouchesTheDatabase(string $task, string $action): void
    {
        $plugin = $this->boot('pgsql://galbridge_ro:testonly-ro@127.0.0.1:1/galbridge_vmail_test', ['galbridge_global_book' => true], task: $task, action: $action);
        self::assertSame(['addressbooks_list', 'addressbook_get'], array_keys($this->api->handlers));
        foreach ($this->api->handlers as $handlers) {
            self::assertCount(1, $handlers);
            self::assertSame($plugin, $handlers[0][0]);
        }
        self::assertArrayNotHasKey('galbridge', $_SESSION);
        self::assertArrayNotHasKey('galbridge_down_until', $_SESSION);
        self::assertSame([], $GLOBALS['galbridge_test_log']);
        foreach (['factory', 'connection'] as $prop) {
            self::assertNull((new \ReflectionProperty(\galbridge::class, $prop))->getValue($plugin), $prop);
        }
    }

    #[DataProvider('requests')]
    public function testAutocompleteIsWiredOnlyForComposeAndAutocomplete(string $task, string $action): void
    {
        $this->boot(Env::sqliteDsn(), [], task: $task, action: $action);
        $wired = $task === 'mail' && in_array($action, ['compose', 'autocomplete'], true);
        self::assertSame($wired ? ['sql', BookDefinition::DOMAIN] : ['sql'], $this->rc->config->get('autocomplete_addressbooks'));
        self::assertSame($wired ? 2 : 1, $this->rc->config->get('autocomplete_min_length'));
    }

    public function testAutocompleteWiringDetails(): void
    {
        $dsn = Env::sqliteDsn();
        // the user's own list is kept, our IDs are appended once
        $this->boot($dsn, ['galbridge_global_book' => true], action: 'compose', prefs: ['autocomplete_addressbooks' => ['sql', 'ldap_x', BookDefinition::DOMAIN]]);
        self::assertSame(['sql', 'ldap_x', BookDefinition::DOMAIN, BookDefinition::GLOBAL], $this->rc->config->get('autocomplete_addressbooks'));

        $this->boot($dsn, ['galbridge_global_book' => true, 'galbridge_global_autocomplete' => false], action: 'autocomplete');
        self::assertSame(['sql', BookDefinition::DOMAIN], $this->rc->config->get('autocomplete_addressbooks'));

        // nothing to offer: nothing changes, not even the minimum length
        $this->boot($dsn, ['galbridge_domain_autocomplete' => false], action: 'compose');
        self::assertSame(['sql'], $this->rc->config->get('autocomplete_addressbooks'));
        self::assertSame(1, $this->rc->config->get('autocomplete_min_length'));
        $this->boot($dsn, ['galbridge_domain_book' => false], action: 'compose');
        self::assertSame(['sql'], $this->rc->config->get('autocomplete_addressbooks'));

        // the client minimum is raised to the server minimum, never lowered, and clamped
        $this->boot($dsn, ['galbridge_min_search_length' => 4], action: 'compose');
        self::assertSame(4, $this->rc->config->get('autocomplete_min_length'));
        $this->boot($dsn, ['galbridge_min_search_length' => 3], action: 'compose', prefs: ['autocomplete_min_length' => 5]);
        self::assertSame(5, $this->rc->config->get('autocomplete_min_length'));
        $this->boot($dsn, ['galbridge_min_search_length' => 50], action: 'compose');
        self::assertSame(10, $this->rc->config->get('autocomplete_min_length'));
    }

    // ------------------------------------------------------------------ addressbooks_list

    #[DataProvider('engines')]
    public function testAddressbooksListAppendsSafeReadOnlyEntries(string $dsn): void
    {
        $this->boot($dsn, [
            'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org'],
            'galbridge_global_autocomplete' => false,
        ]);
        $personal = ['id' => '0', 'name' => 'Personal Addresses', 'groups' => true, 'readonly' => false, 'undelete' => false];
        $p = $this->api->exec_hook('addressbooks_list', ['sources' => ['0' => $personal]]);

        self::assertSame(['0', BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_map('strval', array_keys($p['sources'])), 'appended, never prepended');
        self::assertSame($personal, $p['sources']['0']);

        $domain = $p['sources'][BookDefinition::DOMAIN];
        self::assertSame(BookDefinition::DOMAIN, $domain['id']);
        self::assertSame('Domain directory', $domain['name']);
        self::assertTrue($domain['readonly']);
        self::assertTrue($domain['groups'], 'department groups');
        self::assertTrue($domain['autocomplete']);

        $global = $p['sources'][BookDefinition::GLOBAL];
        self::assertSame('Global directory', $global['name']);
        self::assertTrue($global['readonly']);
        self::assertFalse($global['groups'], 'a search-only book has no groups');
        self::assertFalse($global['autocomplete']);

        foreach ([$domain, $global] as $entry) {
            self::assertSame([], array_diff(array_keys($entry), self::ALLOWED_ENTRY_KEYS), 'no internals in env.address_sources');
            self::assertFalse(!empty($entry['deletable']));
            self::assertFalse(!empty($entry['undelete']));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $entry['id'], 'no "-" (core splits ID-source at it)');
            self::assertNotContains($entry['id'], ['0', '1', '2', '4', '-1', 'sql']);
        }
        $json = (string) json_encode($p['sources']);
        foreach (['testonly-ro', 'galbridge_ro', '127.0.0.1', 'example.com', 'example.org', 'dsn'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function bookNames(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out["{$engine}: markup in the configured name"] = [$dsn, ['galbridge_domain_book_name' => '<img src=x onerror=alert(1)> & "Co"'],
                '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;Co&quot;'];
            $out["{$engine}: ampersand in an organization label"] = [$dsn, ['galbridge_organizations' => ['Smith & Sons' => ['example.com', 'example.org']]],
                'Smith &amp; Sons'];
        }
        return $out;
    }

    /**
     * Core prints source names raw (contacts/index.php, mail/compose.php): the entry carries HTML.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('bookNames')]
    public function testAddressbooksListEscapesTheName(string $dsn, array $options, string $expected): void
    {
        $this->boot($dsn, $options);
        $p = $this->api->exec_hook('addressbooks_list', ['sources' => []]);
        self::assertSame($expected, $p['sources'][BookDefinition::DOMAIN]['name'] ?? null);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function unusable(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out["{$engine}: IMAP host not allowed"] = [$dsn, 'host', []];
            $out["{$engine}: no mailbox"] = [$dsn, 'login', []];
            $out["{$engine}: directory down"] = [$dsn, 'down', []];
            $out["{$engine}: refused DSN"] = [$dsn, 'refused', []];
            $out["{$engine}: malformed deny-list"] = [$dsn, 'ok', ['galbridge_hidden_addresses' => 'x']];
            $out["{$engine}: books switched off"] = [$dsn, 'ok', ['galbridge_domain_book' => false, 'galbridge_global_book' => false]];
        }
        return $out;
    }

    /**
     * Nothing is listed, nothing throws, and both of our IDs still resolve to a DeniedBook.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('unusable')]
    public function testUnusableDirectoryListsNothingButStillAnswersOurIds(string $dsn, string $case, array $options): void
    {
        $login = $case === 'login' ? 'nobody@example.com' : self::JAN;
        $host = $case === 'host' ? 'imap.evil.example' : Env::IMAP_HOST;
        if ($case === 'down') {
            $dsn = str_starts_with($dsn, 'sqlite:') ? 'sqlite:///' . sys_get_temp_dir() : (string) preg_replace('~@[^/]+/~', '@127.0.0.1:1/', $dsn, 1);
        } elseif ($case === 'refused') {
            $dsn = 'pgsql://vmail:secret-pw@127.0.0.1/vmail';
        }
        $this->boot($dsn, $options + ['galbridge_global_book' => true, 'galbridge_global_data_domains' => ['example.org'], 'galbridge_global_viewer_domains' => ['*']], login: $login, host: $host);

        $p = $this->api->exec_hook('addressbooks_list', ['sources' => []]);
        self::assertSame([], $p['sources']);
        foreach ([BookDefinition::DOMAIN => 'Domain directory', BookDefinition::GLOBAL => 'Global directory'] as $id => $label) {
            foreach ([false, true] as $writeable) {
                $got = $this->api->exec_hook('addressbook_get', ['id' => $id, 'writeable' => $writeable]);
                self::assertInstanceOf(DeniedBook::class, $got['instance'] ?? null, "{$id} writeable=" . var_export($writeable, true));
                self::assertSame($label, $got['instance']->get_name());
                self::assertSame(0, $got['instance']->search('*', 'Kowalski')->count);
            }
        }
        self::assertStringNotContainsString('secret-pw', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    // ------------------------------------------------------------------ addressbook_get

    #[DataProvider('engines')]
    public function testAddressbookGetAlwaysAnswersOurIds(string $dsn): void
    {
        $this->boot($dsn);
        foreach ([false, true] as $writeable) {
            // entitled: the real book, also when core asks for a writable one (core then returns null)
            $got = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => $writeable]);
            self::assertInstanceOf(AddressBook::class, $got['instance'] ?? null);
            self::assertTrue($got['instance']->readonly);
            // not entitled (global book disabled): fail-closed instance, never null
            $got = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::GLOBAL, 'writeable' => $writeable]);
            self::assertInstanceOf(DeniedBook::class, $got['instance'] ?? null);
            self::assertSame('Global directory', $got['instance']->get_name());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignIds(): array
    {
        $out = [];
        foreach (['0', 'sql', '1', '2', '4', '-1', '', 'other_plugin', 'galbridge', 'galbridge_domain-0', 'galbridge_domain2', 'GALBRIDGE_DOMAIN', ' galbridge_domain', 'galbridge_global ', 'galbridge_domain,galbridge_global'] as $id) {
            $out[var_export($id, true)] = [$id];
        }
        return $out;
    }

    #[DataProvider('foreignIds')]
    public function testForeignIdsAreLeftUntouched(string $id): void
    {
        $theirs = new DeniedBook('theirs');
        $other = static function (array $p) use ($theirs): array {
            if ($p['id'] === 'other_plugin') {
                $p['instance'] = $theirs;
            }
            return $p;
        };
        $this->boot(Env::sqliteDsn(), before: static function (\rcube_plugin_api $api) use ($other): void {
            $api->register_hook('addressbook_get', $other); // another plugin, loaded first
        });
        $got = $this->api->exec_hook('addressbook_get', ['id' => $id, 'writeable' => false]);
        if ($id === 'other_plugin') {
            self::assertSame($theirs, $got['instance']);
        } else {
            self::assertArrayNotHasKey('instance', $got);
        }
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'a foreign ID never resolves the viewer');
    }

    /**
     * Core's own address-book paths through the plugin: no error 700, read-only books are never
     * offered as writable targets, and core's "null for a writable request" rule applies.
     */
    #[DataProvider('engines')]
    public function testCoreAddressBookPathsWorkThroughThePlugin(string $dsn): void
    {
        $this->boot($dsn, ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']], action: 'compose');
        // guard: core exits the process (error 700) when no instance is returned
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL, 'galbridge_unknown'] as $id) {
            $got = $this->api->exec_hook('addressbook_get', ['id' => $id, 'writeable' => false]);
            if (!str_starts_with($id, 'galbridge_unknown')) {
                self::assertInstanceOf(\rcube_addressbook::class, $got['instance'] ?? null, $id);
            }
        }

        // core's own books first (personal, collected recipients/senders), ours appended
        $ids = array_map('strval', array_keys($this->rc->get_address_sources()));
        self::assertSame('0', $ids[0]);
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_slice($ids, -2));
        $writable = array_map('strval', array_keys($this->rc->get_address_sources(true)));
        self::assertContains('0', $writable);
        self::assertNotContains(BookDefinition::DOMAIN, $writable);
        self::assertNotContains(BookDefinition::GLOBAL, $writable);

        self::assertNull($this->rc->get_address_book(BookDefinition::DOMAIN, true));
        $book = $this->rc->get_address_book(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertSame(BookDefinition::DOMAIN, $this->rc->get_address_book_id($book));
        self::assertSame('surname', $book->sort_col, 'core applied addressbook_sort_col');
        $book->set_pagesize(200);
        self::assertSame(13, $book->list_records()->count);

        $global = $this->rc->get_address_book(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $global);
        self::assertSame(['bob@example.org'], array_map(static fn (array $r): string => $r['email:work'][0], $global->search('*', 'Bob')->records));
    }

    #[DataProvider('engines')]
    public function testCoreGetsADeniedBookForAStaleSource(string $dsn): void
    {
        // e.g. a saved default_addressbook / autocomplete source after the administrator disabled the book
        $this->boot($dsn, [], action: 'autocomplete');
        $book = $this->rc->get_address_book(BookDefinition::GLOBAL);
        self::assertInstanceOf(DeniedBook::class, $book);
        self::assertSame(0, $book->search(['name', 'email'], 'bob', 0, true, true, 'email')->count);
        self::assertNull($this->rc->get_address_book(BookDefinition::GLOBAL, true));
    }

    #[DataProvider('engines')]
    public function testRefusalsAreShownToTheUser(string $dsn): void
    {
        $this->boot($dsn, ['galbridge_max_group_members' => 1], action: 'group-expand');
        $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_group('dp_sprzedaXC5XBC'); // two members
        $book->set_pagesize(9999);
        self::assertSame([], $book->list_records()->records, 'complete or refused, never a prefix');
        self::assertSame([['galbridge.grouptoolarge', 'warning', ['max' => '1']]], $this->output->messages);
        self::assertNotEmpty($book->get_error());
    }

    // ------------------------------------------------------------------ preference guard, end to end

    #[DataProvider('engines')]
    public function testStoredGalbridgePreferencesAreIgnoredByThePlugin(string $dsn): void
    {
        if (is_file(RCUBE_PLUGINS_DIR . 'galbridge/config.inc.php') || is_file(RCUBE_CONFIG_DIR . 'galbridge.inc.php')) {
            self::markTestSkipped('the Roundcube tree has its own galbridge configuration');
        }
        $prefs = [
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['*'],
            'galbridge_global_allow_wildcard' => true,
            'galbridge_domain_book' => false,
            'galbridge_debug' => true,
            'galbridge_list_policy' => 'all',
        ];
        // no injected Config: the plugin builds it from rcmail's merged configuration and the prefs
        $this->boot($dsn, ['galbridge_imap_hosts' => [Env::IMAP_HOST]], prefs: $prefs, injectConfig: false);
        self::assertFalse(Log::isDebug());
        $p = $this->api->exec_hook('addressbooks_list', ['sources' => []]);
        self::assertSame([BookDefinition::DOMAIN], array_keys($p['sources']));
        self::assertInstanceOf(DeniedBook::class, $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::GLOBAL, 'writeable' => false])['instance']);
        $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertNull($book->get_record('a_weirdAexampleDcom'), 'list policy stays strict');

        // a preference cannot add the user's own IMAP server to the allowed hosts
        $this->boot($dsn, [], host: 'imap.evil.example', prefs: ['galbridge_imap_hosts' => ['imap.evil.example']] + $prefs, injectConfig: false);
        self::assertSame([], $this->api->exec_hook('addressbooks_list', ['sources' => []])['sources']);
    }

    // ------------------------------------------------------------------ harness

    /**
     * Build rcmail (user, output, configuration) without startup, construct the plugin on a fresh
     * plugin API and run init().
     *
     * @param array<string, mixed>                   $options plugin and core options (administrator)
     * @param array<string, mixed>                   $prefs   stored user preferences (merged over $options)
     * @param null|\Closure(\rcube_plugin_api): void $before  runs before the plugin is initialised
     */
    private function boot(string $dsn, array $options = [], string $login = self::JAN, string $task = 'mail', string $action = 'show',
        array $prefs = [], string $host = Env::IMAP_HOST, ?int $userId = 7, bool $injectConfig = true, ?\Closure $before = null): \galbridge
    {
        $_SESSION = ['storage_host' => $host];
        $admin = $options + ['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143'];
        $config = new \rcube_config();
        foreach (array_merge($admin, $prefs) as $k => $v) {
            $config->set($k, $v);
        }

        $this->api = (new \ReflectionClass(\rcube_plugin_api::class))->newInstanceWithoutConstructor();
        $this->api->dir = $this->pluginsDir . '/';

        $this->output = new class () extends \rcmail_output_json {
            /** @var list<array{0: string, 1: string, 2: mixed}> */
            public array $messages = [];

            public function __construct()
            {
            }

            /**
             * @param string                    $message
             * @param string                    $type
             * @param null|array<string, mixed> $vars
             * @param bool                      $override
             * @param int                       $timeout
             */
            #[\Override]
            public function show_message($message, $type = 'notice', $vars = null, $override = true, $timeout = 0): void
            {
                $this->messages[] = [(string) $message, (string) $type, $vars];
            }
        };

        $this->rc = (new \ReflectionClass(\rcmail::class))->newInstanceWithoutConstructor();
        $this->rc->config = $config;
        $this->rc->task = $task;
        $this->rc->action = $action;
        $this->rc->plugins = $this->api;
        $this->rc->output = $this->output;
        if ($userId !== null) {
            $user = (new \ReflectionClass(\rcube_user::class))->newInstanceWithoutConstructor();
            $user->ID = $userId;
            $user->data = ['user_id' => $userId, 'username' => $login, 'mail_host' => $host, 'preferences' => serialize($prefs)];
            $user->prefs = $prefs;
            $this->rc->user = $user;
        }
        (new \ReflectionProperty(\rcube::class, 'instance'))->setValue(null, $this->rc);

        if ($before !== null) {
            $before($this->api);
        }
        $plugin = new \galbridge($this->api);
        if ($injectConfig) {
            (new \ReflectionProperty(\galbridge::class, 'config'))->setValue($plugin, new Config($config, $prefs, $admin));
        }
        $plugin->init();
        return $plugin;
    }
}
