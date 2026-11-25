<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real galbridge plugin class on Roundcube 1.7's plugin API (docs/ARCHITECTURE.md 3, 15):
 * init() registers only addressbooks_list and addressbook_get (plus contact_exists in the mail task
 * and addressbook_export in the addressbook task) and never touches the database; the autocomplete options are wired for
 * mail/compose, mail/bounce (the Redirect dialog) and mail/autocomplete only; addressbooks_list
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

    /** @var object{messages: list<array{0: string, 1: string, 2: mixed}>, exits: list<array{0: string, 1: list<string>}>} */
    private object $output;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
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
        // settings: for the preference guard only (second verification pass, F-09; Remediation2PreferencesTest)
        self::assertSame('mail|addressbook|settings', (new \ReflectionClass(\galbridge::class))->getDefaultProperties()['task'] ?? null);
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
            'mail/bounce (the Redirect dialog)' => ['mail', 'bounce'],
            'mail/autocomplete' => ['mail', 'autocomplete'],
            'mail/group-expand' => ['mail', 'group-expand'],
            'addressbook index' => ['addressbook', ''],
            'addressbook/photo' => ['addressbook', 'photo'],
            'addressbook/search' => ['addressbook', 'search'],
        ];
    }

    /**
     * init() runs on every request of the two tasks: it must never connect (a refused DSN would be
     * logged, an unreachable one memoised). Besides the two address book hooks it registers
     * contact_exists in the mail task and addressbook_export in the addressbook task, for every
     * action (second verification pass, F-33).
     */
    #[DataProvider('requests')]
    public function testInitRegistersTwoHooksAndNeverTouchesTheDatabase(string $task, string $action): void
    {
        $plugin = $this->boot('pgsql://galbridge_ro:testonly-ro@127.0.0.1:1/galbridge_vmail_test', ['galbridge_global_book' => true], task: $task, action: $action);
        self::assertSame(['addressbooks_list', 'addressbook_get', $task === 'mail' ? 'contact_exists' : 'addressbook_export'], array_keys($this->api->handlers));
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
        // mail/bounce renders the same recipient inputs as compose (rcmail_sendmail::headers_output)
        $wired = $task === 'mail' && in_array($action, ['compose', 'bounce', 'autocomplete'], true);
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

    /**
     * The Redirect (bounce) dialog renders recipient inputs through rcmail_sendmail::headers_output()
     * like compose: the client minimum and the autocomplete sources are wired there as well.
     */
    public function testRedirectDialogGetsTheAutocompleteWiring(): void
    {
        $this->boot(Env::sqliteDsn(), ['galbridge_global_book' => true, 'galbridge_min_search_length' => 3, 'autocomplete_threads' => 2], action: 'bounce');
        self::assertSame(['sql', BookDefinition::DOMAIN, BookDefinition::GLOBAL], $this->rc->config->get('autocomplete_addressbooks'));
        self::assertSame(3, $this->rc->config->get('autocomplete_min_length'));
        self::assertSame(['addressbooks_list', 'addressbook_get', 'contact_exists'], array_keys($this->api->handlers));
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'wiring never touches the database');
    }

    /**
     * addressbook_export (a refused export must not download an empty file) is registered for every
     * action of the addressbook task - it acts only after a book refused an export of the request,
     * so no spelling of the action and no plugin that changes it in the 'ready' hook can skip it
     * (second verification pass, F-33) - and never in the mail task.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function exportHookRequests(): array
    {
        return [
            'addressbook/export' => ['addressbook', 'export', true],
            'mail/export' => ['mail', 'export', false],
            'addressbook/import' => ['addressbook', 'import', true],
            'addressbook/list' => ['addressbook', 'list', true],
            'addressbook/qrcode' => ['addressbook', 'qrcode', true],
            'addressbook/EXPORT' => ['addressbook', 'EXPORT', true],
        ];
    }

    #[DataProvider('exportHookRequests')]
    public function testExportHookIsRegisteredOnlyForTheExport(string $task, string $action, bool $registered): void
    {
        $plugin = $this->boot('pgsql://galbridge_ro:testonly-ro@127.0.0.1:1/galbridge_vmail_test', [], task: $task, action: $action);
        self::assertSame($registered ? ['addressbooks_list', 'addressbook_get', 'addressbook_export'] : ['addressbooks_list', 'addressbook_get', 'contact_exists'], array_keys($this->api->handlers));
        if ($registered) {
            self::assertSame([$plugin, 'addressbook_export'], $this->api->handlers['addressbook_export'][0]);
        }
        self::assertSame([], $GLOBALS['galbridge_test_log'], 'never touches the database');
        self::assertArrayNotHasKey('galbridge_down_until', $_SESSION);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedExports(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out["{$engine}: export disabled"] = [$dsn, ['galbridge_domain_export' => false], 'book'];
            $out["{$engine}: more entries than galbridge_max_export"] = [$dsn, ['galbridge_max_export' => 5], 'book'];
            $out["{$engine}: a group above the expansion cap"] = [$dsn, ['galbridge_max_group_members' => 1], 'group'];
            $out["{$engine}: more selected contacts than galbridge_max_ids"] = [$dsn, ['galbridge_max_ids' => 1], 'selected'];
            // a search result with more matches than the list cap: on a page it is cut with a notice,
            // in a download it would be a file that silently lacks entries
            $out["{$engine}: more search matches than galbridge_max_results"] = [$dsn, ['galbridge_max_results' => 10], 'search'];
        }
        return $out;
    }

    /**
     * contacts/export is a download: a warning queued for the page is never shown and core would save
     * an empty contacts.vcf. A refusal is recorded instead, and addressbook_export ends the request
     * with 204 No Content - the browser stays on the page and saves nothing.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('refusedExports')]
    public function testRefusedExportAnswersNoContent(string $dsn, array $options, string $what): void
    {
        $this->boot($dsn, $options, task: 'addressbook', action: 'export');
        $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
        self::assertInstanceOf(AddressBook::class, $book);
        $result = self::coreExport($book, $what);
        self::assertSame([], $result->records, 'complete or nothing');
        self::assertNotEmpty($book->get_error());
        self::assertSame([], $this->output->messages, 'nothing is queued for a page that is never rendered');

        try {
            $this->api->exec_hook('addressbook_export', ['result' => $result]);
            self::fail('the refused export went on to send a file');
        } catch (\LogicException $e) {
            self::assertSame('sendExit', $e->getMessage());
        }
        self::assertSame([['', ['HTTP/1.1 204 No Content']]], $this->output->exits);
    }

    /**
     * An export that is not refused is left alone (core sends the vCards).
     */
    #[DataProvider('engines')]
    public function testAllowedExportIsNotIntercepted(string $dsn): void
    {
        foreach (['book' => 13, 'group' => 2, 'selected' => 2, 'search' => 13] as $what => $count) {
            $this->boot($dsn, [], task: 'addressbook', action: 'export');
            $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
            self::assertInstanceOf(AddressBook::class, $book);
            $result = self::coreExport($book, $what);
            self::assertCount($count, $result->records, $what);
            $p = $this->api->exec_hook('addressbook_export', ['result' => $result]);
            self::assertSame($result, $p['result'], $what);
            self::assertEmpty($p['abort'] ?? null, $what);
            self::assertSame([], $this->output->exits, $what);
            self::assertSame([], $this->output->messages, $what);
        }

        // a refusal in another action is still shown (only a download cannot carry a message)
        $this->boot($dsn, ['galbridge_domain_export' => false], task: 'addressbook', action: 'list');
        $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
        self::coreExport($book, 'book');
        self::assertSame([['galbridge.exportdisabled', 'warning', []]], $this->output->messages);

        // the same capped search outside an export: a LIST may be cut, with the notice on the page
        $this->boot($dsn, ['galbridge_max_results' => 10], task: 'addressbook', action: 'search');
        $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
        self::assertCount(10, self::coreExport($book, 'search')->records);
        self::assertSame([['galbridge.toomanyresults', 'warning', ['max' => '10']]], $this->output->messages);
        self::assertSame([], $this->output->exits);
    }

    /**
     * A database that fails during an export must not produce a contacts.vcf without the directory
     * entries (nobody would know that it is incomplete): the export answers 204 like every refusal.
     */
    public function testAFailingDirectoryRefusesTheExport(): void
    {
        $file = sys_get_temp_dir() . '/galbridge-export-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $dir = dirname(__DIR__) . '/fixtures/vmail/';
            $pdo->exec((string) file_get_contents($dir . 'schema.sqlite.sql'));
            $pdo->exec((string) file_get_contents($dir . 'seed.sql'));
            $pdo = null;
            $dsn = 'sqlite:///' . $file . '?mode=0600';

            foreach (['book', 'group', 'search'] as $what) {
                $this->boot($dsn, [], task: 'addressbook', action: 'export');
                $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
                self::assertInstanceOf(AddressBook::class, $book);
                if ($what === 'group') {
                    self::assertNotNull($book->get_group(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż')));
                }
                $pdo = new \PDO('sqlite:' . $file);
                $pdo->exec('ALTER TABLE mailbox RENAME TO mailbox_gone'); // every directory statement fails now
                $pdo = null;
                try {
                    $result = self::coreExport($book, $what);
                    self::assertSame([], $result->records, $what);
                    self::assertSame([], $this->output->messages, 'a download cannot show a message');
                    try {
                        $this->api->exec_hook('addressbook_export', ['result' => $result]);
                        self::fail($what . ': the export of a failing directory went on to send a file');
                    } catch (\LogicException $e) {
                        self::assertSame('sendExit', $e->getMessage());
                    }
                    self::assertSame([['', ['HTTP/1.1 204 No Content']]], $this->output->exits, $what);
                } finally {
                    $pdo = new \PDO('sqlite:' . $file);
                    $pdo->exec('ALTER TABLE mailbox_gone RENAME TO mailbox');
                    $pdo = null;
                }
            }
            self::assertStringContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']));

            // outside an export the user is told instead
            $this->boot($dsn, [], task: 'addressbook', action: 'list');
            $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->exec('ALTER TABLE mailbox RENAME TO mailbox_gone');
            $pdo = null;
            self::assertSame([], self::coreExport($book, 'book')->records);
            self::assertSame([['galbridge.unavailable', 'warning', []]], $this->output->messages);
            self::assertSame([], $this->output->exits);
        } finally {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($file . $suffix);
            }
        }
    }

    /**
     * During a database outage after the Viewer's TTL the books stay in the address-book list and
     * tell the user "The directory is temporarily unavailable." on use (never for the sender photo).
     */
    #[DataProvider('engines')]
    public function testOutageKeepsTheBooksListedAndTellsTheUser(string $dsn): void
    {
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $this->boot($dsn, $options);
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys($this->api->exec_hook('addressbooks_list', ['sources' => []])['sources']));
        $session = $_SESSION;
        $session['galbridge']['at'] -= 3600;
        $session['galbridge_down_until'] = time() + 30;

        $this->boot($dsn, $options, task: 'addressbook', action: 'search');
        $_SESSION = $session;
        $sources = $this->api->exec_hook('addressbooks_list', ['sources' => []])['sources'];
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys($sources), 'still listed');
        self::assertSame(['Domain directory', 'Global directory'], array_column($sources, 'name'));
        foreach ([BookDefinition::DOMAIN => 'Domain directory', BookDefinition::GLOBAL => 'Global directory'] as $id => $name) {
            $this->output->messages = [];
            (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false); // one request per book
            $book = $this->api->exec_hook('addressbook_get', ['id' => $id, 'writeable' => false])['instance'];
            self::assertInstanceOf(DeniedBook::class, $book, $id);
            self::assertSame($name, $book->get_name());
            self::assertSame(0, $book->search(['email'], 'bob@example.org', 1, true, true, 'photo')->count);
            self::assertSame([], $this->output->messages, $id . ': never for the sender photo');
            self::assertSame(0, $book->search('*', 'jan')->count);
            self::assertSame(0, $book->list_records()->count);
            self::assertSame([['galbridge.unavailable', 'warning', []]], $this->output->messages, $id);
        }
        // both books in ONE request (e.g. the compose recipient search): a single notice in total
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
        $this->output->messages = [];
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            $this->api->exec_hook('addressbook_get', ['id' => $id, 'writeable' => false])['instance']->search('*', 'jan');
        }
        self::assertSame([['galbridge.unavailable', 'warning', []]], $this->output->messages, 'one notice per request for all books');
        self::assertSame($session['galbridge'], $_SESSION['galbridge'], 'the expired Viewer is not cached again');
    }

    /**
     * core's contacts/export calls for the whole book, a group (_gid) or selected contacts (_cid).
     */
    private static function coreExport(\rcube_addressbook $book, string $what): \rcube_result_set
    {
        if ($what === 'selected') {
            $ids = [IdCodec::contactId(IdCodec::PERSON, self::JAN), IdCodec::contactId(IdCodec::PERSON, 'anna.nowak@example.com')];
            $book->set_page(1);
            $book->set_pagesize(count($ids));
            return $book->search('ID', $ids, 1, true, true);
        }
        if ($what === 'group') {
            $book->set_group(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż'));
        }
        if ($what === 'search') {
            // contacts/export with _search: the search set of the session is replayed, then page 1 / 99999
            $book->search('email', 'example', 0, false);
            $book->set_search_set($book->get_search_set());
        }
        $book->set_page(1);
        $book->set_pagesize(99999);
        return $book->list_records(null, 0, true);
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
            // an organization label is display text (it is also the "organization" field of every
            // record): its ampersand is the full-width form, which needs no escaping
            $out["{$engine}: ampersand in an organization label"] = [$dsn, ['galbridge_organizations' => ['Smith & Sons' => ['example.com', 'example.org']]],
                "Smith \u{FF06} Sons"];
            $out["{$engine}: markup in an organization label"] = [$dsn, ['galbridge_organizations' => ['<b>Smith</b> &amp; "Sons"' => ['example.com', 'example.org']]],
                "b Smith /b \u{FF06}amp; Sons"];
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

    /**
     * Every warning of the DSN policy reaches the log of the web process - diag runs with the
     * environment of a shell and would not see what the PHP process inherits. Each one has a line
     * of its own (a joined line would be cut at the length limit of a log line), once per request.
     */
    public function testEveryWarningOfTheDsnPolicyIsLogged(): void
    {
        $dsn = getenv('GALBRIDGE_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_PGSQL_DSN (the libpq environment applies to PostgreSQL only)');
        }
        putenv('PGOPTIONS=-c application_name=galbridge-test');
        putenv('PGSSLMODE=prefer');
        try {
            $this->boot($dsn, [], task: 'addressbook', action: 'list');
            $book = $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance'];
            self::assertInstanceOf(AddressBook::class, $book, 'the connection works with both settings');
            $lines = array_values(preg_grep('/dsn_warning/', $GLOBALS['galbridge_test_log']) ?: []);
            self::assertCount(2, $lines, implode("\n", $GLOBALS['galbridge_test_log']));
            self::assertStringStartsWith('WARN db: dsn_warning: the environment variable PGOPTIONS of the PHP process also applies to this connection', $lines[0]);
            self::assertStringStartsWith('WARN db: dsn_warning: the environment variable PGSSLMODE of the PHP process also applies to this connection', $lines[1]);
            // another book of the same request uses the same connection: nothing is written twice
            self::assertInstanceOf(AddressBook::class, $this->api->exec_hook('addressbook_get', ['id' => BookDefinition::DOMAIN, 'writeable' => false])['instance']);
            self::assertCount(2, preg_grep('/dsn_warning/', $GLOBALS['galbridge_test_log']) ?: []);
        } finally {
            putenv('PGOPTIONS');
            putenv('PGSSLMODE');
        }
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
        // a new request: the once-per-request "unavailable" notice may be shown again
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
        $_SESSION = ['storage_host' => $host, 'storage_port' => Env::IMAP_PORT];
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

            /** @var list<array{0: string, 1: list<string>}> */
            public array $exits = [];

            /**
             * rcube_output::sendExit() sends headers and the body and ends the request: recorded here,
             * and the request is ended with an exception.
             *
             * @param string       $body
             * @param list<string> $headers
             */
            #[\Override]
            public function sendExit($body = '', $headers = [])
            {
                $this->exits[] = [(string) $body, (array) $headers];
                throw new \LogicException('sendExit');
            }
        };

        $this->rc = (new \ReflectionClass(\rcmail::class))->newInstanceWithoutConstructor();
        $this->rc->config = $config;
        $this->rc->task = $task;
        $this->rc->action = $action;
        $this->rc->plugins = $this->api;
        $this->rc->output = $this->output;
        // the IMAP connection of the request is open (as in a mail request): the IMAP check of a
        // renewed Viewer (second verification pass, F-17) asks nothing more - Remediation2RevocationTest
        // covers the other answers
        $this->rc->storage = new class () extends \rcube_imap {
            public function __construct()
            {
            }

            #[\Override]
            public function is_connected()
            {
                return true;
            }
        };
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
