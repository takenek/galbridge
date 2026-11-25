<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Viewer;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Every Roundcube 1.7 core path that reads an address book, replayed exactly as
 * program/actions/** call the book: the same method order, page sizes (addressbook_pagesize,
 * autocomplete_max, 9999, 99999, count + 2, max_group_members ?: 999), reset() / set_group(0)
 * sequences, SEARCH_GROUPS in the mode and the required 'email' / 'photo' fields. Where core reads
 * request input, the real rcmail_action_contacts_index::get_cids() parses it; records go through the
 * real export (vCard) and copy (rcube_contacts) conversions.
 */
final class CorePathsTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private const SOURCE = BookDefinition::DOMAIN;

    private const JAN_BOOK = [
        'anna.nowak@example.com', 'board@example.com', 'everyone@example.com', 'forwarder@example.com',
        'internal@example.com', 'jan.kowalski@example.com', 'legacy@example.com', 'lukasz.slowik@example.com',
        'newsletter@example.com', 'pct_user@example.com', 'postmaster@example.com', 'team@example.com',
        'zofia.zolkiewska@example.com',
    ];

    /** example.com persons (the domain group of example.com with organizations) */
    private const PERSONS = [
        'anna.nowak@example.com', 'forwarder@example.com', 'jan.kowalski@example.com', 'lukasz.slowik@example.com',
        'pct_user@example.com', 'postmaster@example.com', 'zofia.zolkiewska@example.com',
    ];

    /**
     * contactlist_name_template values, by key (PHPUnit's process-isolation template would read a
     * "{name}" inside a data set as one of its own placeholders)
     */
    private const NAME_TEMPLATES = [
        'default' => '{name}',
        'name and address' => '{name} ({email})',
        'first and last name' => '{firstname} {surname}',
        'last and first name with address' => '{surname} {firstname} <{email}>',
        'name and department' => '{name} - {department}',
        'name and department in brackets' => '{name} ({department})',
        'name and department in square brackets' => '{name} [{department}]',
        // text of the entry before its name, and a template without {name}: a foreign entry would be
        // shown with text of its own domain before the "(domain)" marker, or without the marker
        'department in square brackets first' => '[{department}] {name}',
        'first and last name with department' => '{firstname} {surname} ({department})',
    ];

    /** the templates of NAME_TEMPLATES in which {department} stands before {name}, or without it */
    private const TEXT_BEFORE_NAME = ['department in square brackets first', 'first and last name with department'];

    /** @var array<string, mixed> */
    private array $savedGlobals = [];

    /** @var array<string, mixed> core options changed by a test */
    private array $savedCore = [];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube (GALBRIDGE_RC)');
        }
        Log::resetOnce();
        $GLOBALS['galbridge_test_log'] = [];
        $this->savedGlobals = ['get' => $_GET, 'post' => $_POST, 'request' => $_REQUEST];
        $_GET = $_POST = $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        if ($this->savedGlobals !== []) {
            $_GET = $this->savedGlobals['get'];
            $_POST = $this->savedGlobals['post'];
            $_REQUEST = $this->savedGlobals['request'];
        }
        if (GALBRIDGE_RC_LOADED) {
            foreach ($this->savedCore as $name => $value) {
                \rcube::get_instance()->config->set($name, $value);
            }
        }
        $this->savedCore = [];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Change a core option of the running Roundcube (restored in tearDown).
     */
    private function core(string $name, mixed $value): void
    {
        $config = \rcube::get_instance()->config;
        if (!array_key_exists($name, $this->savedCore)) {
            $this->savedCore[$name] = $config->get($name);
        }
        $config->set($name, $value);
    }

    private static function cfg(string $name, mixed $default = null): mixed
    {
        return \rcube::get_instance()->config->get($name, $default);
    }

    /**
     * rcmail::get_address_book(): the hook result plus core's set_sort_order(addressbook_sort_col).
     *
     * @param array<string, mixed>                       $options
     * @param list<array{string, array<string, string>}> $notices
     */
    private static function book(string $dsn, array $options = [], string $login = self::JAN, string $action = '', ?array &$notices = null, string $source = self::SOURCE): \rcube_addressbook
    {
        return self::sorted(Env::factory(Env::config($dsn, $options), $login, $action, $notices)->create($source));
    }

    private static function sorted(\rcube_addressbook $book): \rcube_addressbook
    {
        if ($sortCol = self::cfg('addressbook_sort_col')) {
            $book->set_sort_order($sortCol);
        }
        return $book;
    }

    /**
     * A factory with the Viewer from the session cache and a connection that counts connects.
     *
     * @param array<string, mixed> $options
     */
    private static function coldFactory(string $dsn, array $options, int &$connects, string $login = self::JAN): Factory
    {
        $config = Env::config($dsn, $options);
        Env::factory($config, $login)->resolver()->resolve();
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

    private static function id(string $address, string $kind = IdCodec::PERSON): string
    {
        return IdCodec::contactId($kind, $address);
    }

    private static function sprzedaz(): string
    {
        return IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż');
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
        $out = [];
        foreach (self::records($rs) as $record) {
            $out[] = (string) (\rcube_addressbook::get_col_values('email', $record, true)[0] ?? '');
        }
        sort($out);
        return $out;
    }

    /**
     * rcmail_action_contacts_index::contact_source() for an already resolved book.
     */
    private static function contactSource(\rcube_addressbook $contacts): \rcube_addressbook
    {
        $contacts->set_pagesize(self::cfg('addressbook_pagesize', self::cfg('pagesize', 50)));
        if (!empty($_GET['_page'])) {
            $contacts->set_page($_SESSION['page'] = intval($_GET['_page']));
        } else {
            $contacts->set_page($_SESSION['page'] ?? 1);
        }
        if ($group = \rcube_utils::get_input_string('_gid', \rcube_utils::INPUT_GP)) {
            $contacts->set_group($group);
        }
        // init_env: core reads readonly and coltypes
        self::assertTrue($contacts->readonly);
        self::assertIsArray($contacts->coltypes);
        return $contacts;
    }

    /**
     * rcmail_action_mail_list_contacts::compose_contact_groups() without the output calls.
     *
     * @return array<string, mixed> row id => row data
     */
    private static function composeContactGroups(\rcube_addressbook $abook, string $sourceId, ?string $search = null, int $searchMode = 0): array
    {
        $rows = [];
        foreach ($abook->list_groups($search, $searchMode) as $group) {
            $abook->reset();
            $abook->set_group($group['ID']);
            if (!empty($group['email'])) {
                foreach ((array) $group['email'] as $email) {
                    $rows['G' . $group['ID']] = format_email_recipient($email, $group['name']);
                }
            } elseif (!empty($group['virtual'])) {
                $rows['G' . $group['ID']] = ['ID' => $group['ID'], 'name' => $group['name'], 'virtual' => true];
            } elseif (($result = $abook->count()) && $result->count) {
                $rows['E' . $group['ID']] = ['name' => $group['name'], 'source' => $sourceId, 'count' => $result->count];
            }
        }
        $abook->reset();
        $abook->set_group(0);
        return $rows;
    }

    /**
     * rcmail_action_mail_autocomplete::run() for one source: contacts, then groups.
     *
     * @return array<string, array<string, mixed>> entry name => entry
     */
    private static function autocomplete(\rcube_addressbook $abook, string $abookId, string $search): array
    {
        $max = (int) self::cfg('autocomplete_max', 15);
        $mode = (int) self::cfg('addressbook_search_mode') | \rcube_addressbook::SEARCH_GROUPS;
        $searchLc = mb_strtolower($search);
        $contacts = [];

        $abook->set_pagesize($max);
        $result = $abook->search(self::cfg('contactlist_fields'), $search, $mode, true, true, 'email');
        self::assertLessThanOrEqual($max, count(self::records($result)));
        if ($result->count) {
            foreach ($result as $record) {
                $emails = (array) $abook->get_col_values('email', $record, true);
                foreach ($emails as $email) {
                    if (empty($email)) {
                        continue;
                    }
                    $name = \rcube_addressbook::compose_list_name($record);
                    $contact = format_email_recipient($email, $name);
                    if (count($emails) > 1 && !str_contains(mb_strtolower($contact), $searchLc)) {
                        continue;
                    }
                    if (empty($contacts[$contact])) {
                        $contacts[$contact] = [
                            'name' => $contact,
                            'type' => $record['_type'] ?? null,
                            'id' => $record['ID'],
                            'source' => $abookId,
                            'fields' => \rcube_addressbook::compose_search_fields($record, $email, $name),
                        ];
                        if (count($contacts) >= $max) {
                            break 2;
                        }
                    }
                }
            }
        }

        if ($abook->groups && count($contacts) < $max) {
            foreach ($abook->list_groups($search, $mode) as $group) {
                $abook->reset();
                $abook->set_group($group['ID']);
                $groupProp = $abook->get_group($group['ID']);
                if (!empty($groupProp['email'])) {
                    foreach ((array) $groupProp['email'] as $email) {
                        $index = format_email_recipient($email, $group['name']);
                        $contacts[$index] = ['name' => $index, 'email' => $email, 'type' => 'group', 'id' => $group['ID'], 'source' => $abookId];
                    }
                } elseif (($result = $abook->count()) && $result->count) {
                    $contacts[$group['name']] = ['name' => $group['name'] . ' (' . intval($result->count) . ')', 'type' => 'group', 'id' => $group['ID'], 'source' => $abookId];
                }
            }
        }
        return $contacts;
    }

    /**
     * rcmail_action_mail_group_expand::run(): the recipients that replace the group.
     *
     * @return list<string>
     */
    private static function groupExpand(\rcube_addressbook $abook, string $gid): array
    {
        $members = [];
        if ($gid) {
            $abook->set_group($gid);
            $abook->set_pagesize(9999);
            foreach ($abook->list_records(self::cfg('contactlist_fields')) as $record) {
                $email = ((array) $abook->get_col_values('email', $record, true))[0] ?? null;
                if (!empty($email)) {
                    $members[] = format_email_recipient($email, \rcube_addressbook::compose_list_name($record));
                }
            }
        }
        return array_values(array_unique($members));
    }

    /**
     * rcmail_action_contacts_mailto::run() for one book (selected contacts from _cid, group from _gid).
     *
     * @return list<string> the recipients core would put into To:
     */
    private static function mailto(\rcube_addressbook $contacts, int $maxGroupMembers = 0): array
    {
        $sources = [];
        foreach (\rcmail_action_contacts_index::get_cids() as $cid) {
            if ($contacts->ready) {
                $contacts->set_page(1);
                $contacts->set_pagesize(count($cid) + 2); // +2 to skip counting query
                $sources[] = $contacts->search($contacts->primary_key, $cid, 0, true, true, 'email');
            }
        }
        $mailto = [];
        if (!empty($_REQUEST['_gid']) && isset($_REQUEST['_source'])) {
            $groupId = \rcube_utils::get_input_string('_gid', \rcube_utils::INPUT_GP);
            $groupData = $contacts->get_group($groupId);
            if (!empty($groupData['email'])) {
                $mailto[] = format_email_recipient($groupData['email'][0], $groupData['name']);
            } elseif ($contacts->ready) {
                $contacts->set_group($groupId);
                $contacts->set_page(1);
                $contacts->set_pagesize($maxGroupMembers ?: 999);
                $sources[] = $contacts->list_records();
            }
        }
        foreach ($sources as $source) {
            foreach ($source as $rec) {
                $emails = \rcube_addressbook::get_col_values('email', $rec, true);
                if (!empty($emails)) {
                    $mailto[] = format_email_recipient($emails[0], $rec['name']);
                }
            }
        }
        sort($mailto);
        return $mailto;
    }

    /**
     * rcmail_action_contacts_export::prepare_for_export() and a parse of the produced vCard.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed> the vCard read back by rcube_vcard
     */
    private static function exportVcard(array $record, \rcube_addressbook $book): array
    {
        \rcmail_action_contacts_export::prepare_for_export($record, $book);
        self::assertIsString($record['vcard']);
        self::assertStringStartsWith('BEGIN:VCARD', $record['vcard']);
        self::assertStringNotContainsString('CATEGORIES', $record['vcard'], 'export_groups is off (no N+1 get_record_groups)');
        return (new \rcube_vcard($record['vcard']))->get_assoc();
    }

    /**
     * @param list<array{string, array<string, string>}> $notices
     *
     * @return list<string>
     */
    private static function labels(array $notices): array
    {
        return array_map(static fn (array $n): string => $n[0], $notices);
    }

    // ------------------------------------------------------------------ instance resolution

    #[DataProviderExternal(Env::class, 'engines')]
    public function testEveryOwnSourceIdResolvesToAnInstance(string $dsn): void
    {
        // the addressbook_get hook must ALWAYS set an instance for our IDs (core error 700 otherwise)
        $scenarios = [
            'entitled' => [[], self::JAN, AddressBook::class],
            'global disabled' => [[], self::JAN, DeniedBook::class, BookDefinition::GLOBAL],
            'no mailbox' => [[], 'ghost@example.com', DeniedBook::class],
            'inactive domain' => [[], 'carol@inactive.test', DeniedBook::class],
            'backup MX domain' => [[], 'dave@backup.test', DeniedBook::class],
            'expired domain' => [[], 'erin@expired.test', DeniedBook::class],
            'expired user' => [[], 'expired.user@example.com', DeniedBook::class],
            'not a viewer domain' => [['galbridge_domain_viewer_domains' => ['example.org']], self::JAN, DeniedBook::class],
            'login without @' => [[], 'jan', DeniedBook::class],
        ];
        foreach ($scenarios as $label => $s) {
            [$options, $login, $class] = $s;
            $book = Env::factory(Env::config($dsn, $options), $login)->create($s[3] ?? self::SOURCE);
            self::assertInstanceOf($class, $book, $label);
            self::assertTrue($book->readonly, $label . ': get_address_book($id, true) returns null for writers');
            $book->close();
            $book->close(); // request teardown: idempotent
        }
    }

    // ------------------------------------------------------------------ contacts UI

    #[DataProviderExternal(Env::class, 'engines')]
    public function testContactsListPath(string $dsn): void
    {
        // addressbook/list (contact_source + list_records(contactlist_fields) + get_group for the title)
        $contacts = self::contactSource(self::book($dsn));
        $result = $contacts->list_records(self::cfg('contactlist_fields'));
        self::assertSame(13, $result->count);
        self::assertSame(0, $result->first);
        self::assertSame(self::JAN_BOOK, self::addresses($result));
        self::assertFalse((bool) $result->searchonly);
        foreach ($result as $row) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $row['ID'], 'row ids go through html_identifier()');
            self::assertNotSame('', \rcube_addressbook::compose_list_name($row));
        }
        self::assertEmpty($contacts->group_id);

        // a group from the directory tree
        $_GET['_gid'] = self::sprzedaz();
        $contacts = self::contactSource(self::book($dsn));
        $result = $contacts->list_records(self::cfg('contactlist_fields'));
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($result));
        self::assertSame(2, $result->count);
        $groupData = ['ID' => $contacts->group_id] + array_intersect_key((array) $contacts->get_group($contacts->group_id), ['name' => 1, 'email' => 1]);
        self::assertSame(['ID' => self::sprzedaz(), 'name' => 'Sprzedaż'], $groupData);

        // a crafted _gid (another tenant's domain, the old plugin's group format): nothing, no title
        foreach (['example.org', IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.org'), IdCodec::groupId('g', IdCodec::GROUP_DEPARTMENT, 'sales')] as $gid) {
            $_GET['_gid'] = $gid;
            $contacts = self::contactSource(self::book($dsn));
            $result = $contacts->list_records(self::cfg('contactlist_fields'));
            self::assertSame(0, $result->count, $gid);
            self::assertSame([], self::records($result), $gid);
            self::assertNull($contacts->get_group($contacts->group_id), $gid);
        }
        unset($_GET['_gid']);

        // paging through the list (_page; addressbook_pagesize)
        $this->core('addressbook_pagesize', 5);
        $_GET['_page'] = '3';
        $contacts = self::contactSource(self::book($dsn));
        $result = $contacts->list_records(self::cfg('contactlist_fields'));
        self::assertSame(13, $result->count);
        self::assertSame(10, $result->first);
        self::assertCount(3, self::records($result));
        self::assertEquals(3, ceil($result->count / 5), 'pagecount');

        // the page is remembered in the session when _page is absent
        unset($_GET['_page']);
        $book = self::book($dsn);
        $_SESSION['page'] = 2; // after Env::factory(), which starts a new session
        $result = self::contactSource($book)->list_records(self::cfg('contactlist_fields'));
        self::assertSame(5, $result->first);

        // a search-only book: empty list with the "contactsearchonly" notice
        $result = self::contactSource(self::book($dsn, ['galbridge_domain_searchonly' => true]))->list_records(self::cfg('contactlist_fields'));
        self::assertTrue(!$result->count && $result->searchonly);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testDirectoryTreeGroups(string $dsn): void
    {
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $factory = Env::factory(Env::config($dsn, $options), self::JAN);
        $tree = [];
        foreach ($factory->books() as $id => $definition) {
            if ($factory->grouping($definition) === 'none') {
                continue; // addressbooks_list says 'groups' => false
            }
            foreach (self::sorted($factory->create($id))->list_groups() as $group) {
                // env.contactgroups['G' + source + gid] and rel / onclick JS strings
                self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $group['ID']);
                $tree['G' . $id . $group['ID']] = $group['name'];
            }
        }
        self::assertSame([
            'G' . self::SOURCE . IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'it') => 'IT',
            'G' . self::SOURCE . IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'księgowość') => 'Księgowość',
            'G' . self::SOURCE . self::sprzedaz() => 'Sprzedaż',
        ], $tree, 'the search-only global book has no groups');
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testShowPrintQrcodeAndPhotoByContactId(string $dsn): void
    {
        $jan = self::id(self::JAN);

        // addressbook/show?_cid=..&_source=..
        $_GET = ['_cid' => $jan, '_source' => self::SOURCE];
        $cids = \rcmail_action_contacts_index::get_cids();
        $source = (string) key($cids);
        $cid = $cids[$source][0] ?? null;
        self::assertSame([self::SOURCE => [$jan]], $cids);
        $contacts = self::contactSource(self::book($dsn, [], self::JAN, 'show', $n, $source));
        $contact = $contacts->get_record($cid, true);
        self::assertIsArray($contact);
        self::assertSame($jan, $contact['ID']);
        self::assertSame($contact, $contacts->get_result()?->first(), 'contact_photo() reads get_result()->first()');
        self::assertArrayNotHasKey('photo', $contact, 'no photo: core shows the placeholder');
        self::assertNotEmpty($contacts->list_groups());
        self::assertSame([self::sprzedaz() => 'Sprzedaż'], $contacts->get_record_groups($contact['ID']));

        // search mode: _source='' and _cid=ID-source (split at the last '-')
        $_GET = ['_cid' => $jan . '-' . self::SOURCE . ',' . self::id('anna.nowak@example.com') . '-' . self::SOURCE, '_source' => ''];
        self::assertSame([self::SOURCE => [$jan, self::id('anna.nowak@example.com')]], \rcmail_action_contacts_index::get_cids());
        // vcard_attachments: vcard://source-cid-email exploded on '-'
        self::assertSame([self::SOURCE, $jan, self::JAN], explode('-', self::SOURCE . '-' . $jan . '-' . self::JAN));

        // addressbook/print: the record groups are imploded
        $_GET = ['_cid' => $jan, '_source' => self::SOURCE];
        $contacts = self::contactSource(self::book($dsn));
        $contact = $contacts->get_record($cid, true);
        self::assertSame('Sprzedaż', implode(', ', $contacts->get_record_groups($contact['ID'])));

        // addressbook/qrcode without (or with a crafted) _cid: get_record(null|garbage, true) is null
        $_GET = ['_source' => self::SOURCE];
        $cids = \rcmail_action_contacts_index::get_cids();
        $cid = $cids ? ($cids[(string) key($cids)][0] ?? null) : null;
        self::assertNull(self::contactSource(self::book($dsn))->get_record($cid, true));
        $_GET = ['_cid' => 'u_x/../../etc', '_source' => self::SOURCE];
        self::assertSame([], \rcmail_action_contacts_index::get_cids(), 'core rejects the string form');
        // ... but an array _cid reaches the book unvalidated
        $_GET = ['_cid' => [self::SOURCE => [self::id('bob@example.org'), "u_x'; DROP TABLE mailbox; --"]]];
        $contacts = self::contactSource(self::book($dsn));
        foreach (\rcmail_action_contacts_index::get_cids()[self::SOURCE] as $crafted) {
            self::assertNull($contacts->get_record($crafted, true));
            self::assertNull($contacts->get_result());
        }

        // addressbook/photo?_cid=: the record has no photo
        $_GET = ['_cid' => $jan, '_source' => self::SOURCE];
        $record = self::contactSource(self::book($dsn))->get_record($jan, true);
        self::assertTrue(empty($record['photo']));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testSenderPhotoOfEveryOpenedMessageNeverQueries(string $dsn): void
    {
        // mail/show -> addressbook/photo?_email=<sender>: every listed source is searched with 'photo'
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $connects = 0;
        $factory = self::coldFactory($dsn, $options, $connects);
        foreach (['bob@example.org', self::JAN, 'unknown@example.net'] as $email) {
            foreach (array_keys($factory->books()) as $id) {
                $abook = self::sorted($factory->create($id));
                $result = $abook->search(['email'], $email, 1, true, true, 'photo');
                $record = null;
                foreach ($result as $record) {
                    if (!empty($record['photo'])) {
                        break 2;
                    }
                }
                self::assertNull($record, $id);
                self::assertSame(0, $result->count, $id);
            }
        }
        self::assertSame(0, $connects, 'the per-message photo request must not touch the directory');
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testQuickSearchSearchUpdateAndSearchExport(string $dsn): void
    {
        $mode = (int) self::cfg('addressbook_search_mode') | \rcube_addressbook::SEARCH_GROUPS;
        $afields = self::cfg('contactlist_fields');

        // addressbook/search?_q=kowal (default _headers: '*' among the defaults -> $fields = '*')
        foreach (['*', ['name', 'firstname', 'surname', 'email']] as $fields) {
            $source = self::book($dsn);
            if (is_array($fields)) {
                // the support check against the coltypes keys (quick search: at least one field)
                $cols = !empty($source->coltypes[0]) ? array_flip($source->coltypes) : $source->coltypes;
                self::assertCount(4, array_intersect($fields, array_keys($cols)));
            }
            $source->set_page(1);
            $source->set_pagesize(9999);
            $result = $source->search($fields, 'kowal', $mode, false);
            self::assertSame(1, $result->count);
            self::assertSame([self::JAN], self::addresses($source->list_records($afields)));
            $set = $source->get_search_set();
            self::assertNotNull($set);
            $_SESSION['contact_search']['req'] = [self::SOURCE => $set];

            // addressbook/list?_search=req (search_update) in a later request
            $sessionSet = unserialize(serialize($_SESSION['contact_search']['req']));
            foreach ($sessionSet as $s => $stored) {
                $later = self::book($dsn, [], self::JAN, '', $n, $s);
                $later->set_page(1);
                $later->set_pagesize(9999);
                $later->set_search_set($stored);
                $listed = $later->list_records(['name', 'email']);
                self::assertSame([self::JAN], self::addresses($listed));
                self::assertSame($stored, $later->get_search_set());

                // addressbook/export?_search=req
                $export = self::book($dsn, [], self::JAN, '', $n, $s);
                $export->set_page(1);
                $export->set_pagesize(99999);
                $export->set_search_set($stored);
                $records = self::records($export->list_records());
                self::assertCount(1, $records);
                self::assertSame([self::JAN], \rcube_addressbook::get_col_values('email', self::exportVcard($records[0], $export), true));
            }
        }

        // _headers with unknown fields only: core skips the source; a direct call matches nothing
        $source = self::book($dsn);
        self::assertArrayNotHasKey('password', $source->coltypes);
        $source->set_page(1);
        $source->set_pagesize(9999);
        self::assertSame(0, $source->search(['password'], 'x', $mode, false)->count);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testAdvancedSearchPath(string $dsn): void
    {
        $mode = (int) self::cfg('addressbook_search_mode') | \rcube_addressbook::SEARCH_GROUPS;
        $options = ['galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'birthday']];
        $cases = [
            [['_search_name' => 'jan', '_search_department' => 'sprzeda'], [self::JAN]],
            [['_search_surname' => 'nowak'], ['anna.nowak@example.com']],
            [['_search_firstname' => 'anna', '_search_department' => 'it'], []],
            [['_search_birthday' => '1980-05-17'], []],   // offered by core (coltypes key) but nosearch
            [['_search_phone' => '600'], null],           // not a coltypes key: core skips the source
        ];
        foreach ($cases as $i => [$post, $expected]) {
            $_POST = $post + ['_adv' => '1'];
            $fields = $search = [];
            foreach (array_keys($_POST) as $key) {
                $s = trim(\rcube_utils::get_input_string($key, \rcube_utils::INPUT_POST, true));
                if (strlen($s) && preg_match('/^_search_([a-zA-Z0-9_-]+)$/', $key, $m)) {
                    $search[] = $s;
                    $fields[] = $m[1];
                }
            }
            $source = self::book($dsn, $options);
            $cols = !empty($source->coltypes[0]) ? array_flip($source->coltypes) : $source->coltypes;
            $supported = count(array_filter($fields, static fn (string $f): bool => array_key_exists($f, $cols)));
            if ($supported < count($fields)) {
                self::assertNull($expected, 'case ' . $i . ': skipped by core');
                continue;
            }
            $source->set_page(1);
            $source->set_pagesize(9999);
            $count = $source->search($fields, $search, $mode, false)->count;
            self::assertSame(count((array) $expected), $count, 'case ' . $i);
            if ($count) {
                self::assertSame($expected, self::addresses($source->list_records(self::cfg('contactlist_fields'))), 'case ' . $i);
            }
        }
    }

    // ------------------------------------------------------------------ compose

    #[DataProviderExternal(Env::class, 'engines')]
    public function testAutocompleteContactsAndGroups(string $dsn): void
    {
        $book = self::book($dsn, [], self::JAN, 'autocomplete');
        self::assertSame(['Jan Kowalski <jan.kowalski@example.com>'], array_keys(self::autocomplete($book, self::SOURCE, 'kowal')));
        $entry = self::autocomplete(self::book($dsn, [], self::JAN, 'autocomplete'), self::SOURCE, 'Jan K')['Jan Kowalski <jan.kowalski@example.com>'] ?? null;
        self::assertSame(['name' => 'Jan Kowalski', 'email' => self::JAN], $entry['fields'] ?? null);
        self::assertSame(self::id(self::JAN), $entry['id'] ?? null);

        // groups: list_groups($search, $mode | SEARCH_GROUPS), then reset / set_group / get_group / count
        $entries = self::autocomplete(self::book($dsn, [], self::JAN, 'autocomplete'), self::SOURCE, 'sprz');
        self::assertSame(['Zespół sprzedaży <team@example.com>', 'Sprzedaż'], array_keys($entries));
        self::assertSame(['name' => 'Sprzedaż (2)', 'type' => 'group', 'id' => self::sprzedaz(), 'source' => self::SOURCE], $entries['Sprzedaż'], 'the group count ignores the preceding search filter');

        // the server-side minimum (core has none) and the result cap
        self::assertSame([], self::autocomplete(self::book($dsn, [], self::JAN, 'autocomplete'), self::SOURCE, 'k'));
        self::assertSame([], self::autocomplete(self::book($dsn, [], self::JAN, 'autocomplete'), self::SOURCE, 's'));
        $this->core('autocomplete_max', 4);
        $book = self::book($dsn, [], self::JAN, 'autocomplete');
        self::assertCount(4, self::autocomplete($book, self::SOURCE, 'example'));
        self::assertSame(4, $book->get_result()?->count, 'nocount: the count is the rows returned');

        // galbridge_domain_autocomplete = false is enforced in search() (core accepts any _source)
        $off = self::book($dsn, ['galbridge_domain_autocomplete' => false], self::JAN, 'autocomplete');
        self::assertSame(0, $off->search(self::cfg('contactlist_fields'), 'kowal', 4, true, true, 'email')->count);
        $this->core('autocomplete_max', 15);

        // the global book: prefix mode, every word must reach the minimum, never a domain fragment
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org', 'xn--bcher-kva.example']];
        $global = fn (string $q): array => array_keys(self::autocomplete(self::book($dsn, $options, self::JAN, 'autocomplete', $n, BookDefinition::GLOBAL), BookDefinition::GLOBAL, $q));
        // format_email_recipient() quotes the marked name: "(example.org) Bob Smith" <bob@example.org>
        self::assertSame(['"(example.org) Bob Smith" <bob@example.org>'], $global('bob'));
        self::assertSame(['"(example.org) Bob Smith" <bob@example.org>'], $global('smi'));
        self::assertSame([], $global('mith'), 'prefix mode in the global book');
        self::assertSame([], $global('example'), 'a domain fragment never lists a tenant');
        self::assertSame([], $global('bob s'), 'every word must reach the minimum in the global book');
        self::assertSame(['"(xn--bcher-kva.example) Klaus Müller" <klaus@xn--bcher-kva.example>'], $global('klaus'));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testGroupExpandReturnsAllMembersOrRefuses(string $dsn): void
    {
        $gid = self::sprzedaz();
        self::assertSame(
            ['Jan Kowalski <jan.kowalski@example.com>', 'Anna Nowak <anna.nowak@example.com>'],
            self::groupExpand(self::book($dsn, [], self::JAN, 'group-expand'), $gid),
            'all members, in the addressbook_sort_col (surname) order',
        );

        // all members, also beyond a normal page (domain groups via organizations)
        $orgs = ['galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]];
        $paged = self::book($dsn, $orgs);
        $paged->set_group(IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.com'));
        $paged->set_pagesize(5);
        $page = $paged->list_records();
        self::assertCount(5, self::records($page));
        self::assertSame(7, $page->count);
        $members = self::groupExpand(self::book($dsn, $orgs, self::JAN, 'group-expand'), IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.com'));
        self::assertCount(7, $members, 'never a truncated prefix');
        $expected = array_map(static fn (string $a): string => '<' . $a . '>', self::PERSONS);
        foreach ($expected as $address) {
            self::assertCount(1, array_filter($members, static fn (string $m): bool => str_contains($m, $address)), $address);
        }

        // a crafted gid expands to nobody
        foreach (['example.org', IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'example.org'), IdCodec::groupId('g', IdCodec::GROUP_DEPARTMENT, 'sprzedaż'), 'dp_sales'] as $bad) {
            self::assertSame([], self::groupExpand(self::book($dsn, [], self::JAN, 'group-expand'), $bad), $bad);
        }
        self::assertSame([], self::groupExpand(self::book($dsn, $orgs, self::JAN, 'group-expand'), IdCodec::groupId('d', IdCodec::GROUP_DOMAIN, 'xn--bcher-kva.example')), 'a domain outside the organization');

        // above the expansion cap: nothing, a visible warning and set_error()
        foreach ([['galbridge_max_group_members' => 1], ['max_group_members' => 1]] as $options) {
            $label = json_encode($options) ?: '';
            $book = self::book($dsn, $options, self::JAN, 'group-expand', $notices);
            self::assertSame([], self::groupExpand($book, $gid), $label);
            self::assertSame([['grouptoolarge', ['max' => '1']]], $notices, $label);
            self::assertSame(['type' => \rcube_addressbook::ERROR_SEARCH, 'message' => 'galbridge.grouptoolarge'], $book->get_error(), $label);
            // a group that fits is still complete
            $book = self::book($dsn, $options, self::JAN, 'group-expand', $notices);
            self::assertSame(['Łukasz Słowik <lukasz.slowik@example.com>'], self::groupExpand($book, IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'it')), $label);
            self::assertSame([], $notices, $label);
        }
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testComposeContactListPaths(string $dsn): void
    {
        $afields = self::cfg('contactlist_fields');
        $pageSize = self::cfg('addressbook_pagesize', self::cfg('pagesize', 50));

        // mail/list-contacts?_source=..: groups on page one, then reset + set_group(0), then the list
        $contacts = self::book($dsn);
        self::assertTrue($contacts->ready);
        $contacts->set_pagesize($pageSize);
        $contacts->set_page(max(1, $_GET['_page'] ?? 0));
        $rows = [];
        if ($contacts->groups && $contacts->list_page == 1) {
            $rows = self::composeContactGroups($contacts, self::SOURCE);
        }
        self::assertSame(['G' . IdCodec::groupId('d', 'p', 'it'), 'G' . IdCodec::groupId('d', 'p', 'księgowość'), 'G' . self::sprzedaz()], array_keys($rows));
        foreach ($rows as $row) {
            self::assertTrue($row['virtual']);
        }
        $result = $contacts->list_records($afields);
        self::assertSame(13, $result->count, 'set_group(0) after the group rows lists the whole book again');
        self::assertSame(self::JAN_BOOK, self::addresses($result));
        foreach ($result as $row) {
            foreach (array_keys(\rcube_addressbook::get_col_values('email', $row, true)) as $i) {
                // row id "source-ID-i": html_identifier() keeps [0-9a-z_-], the JS splits it at the dashes
                $rowId = self::SOURCE . '-' . $row['ID'] . '-' . $i;
                self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $rowId);
                self::assertSame([self::SOURCE, $row['ID'], (string) $i], explode('-', $rowId));
            }
        }

        // a group row clicked (_gid)
        $contacts = self::book($dsn);
        $contacts->set_pagesize($pageSize);
        $contacts->set_page(1);
        $contacts->set_group(self::sprzedaz());
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($contacts->list_records($afields)));

        // _page=abc: max(1, 'abc') is the string 'abc'
        $contacts = self::book($dsn);
        $contacts->set_pagesize($pageSize);
        $contacts->set_page(max(1, 'abc'));
        self::assertSame(1, $contacts->list_page);
        self::assertSame(13, $contacts->list_records($afields)->count);

        // page 2: no group rows, the next page of persons
        $contacts = self::book($dsn);
        $contacts->set_pagesize(5);
        $contacts->set_page(max(1, '2'));
        self::assertFalse($contacts->groups && $contacts->list_page == 1);
        $result = $contacts->list_records($afields);
        self::assertSame(13, $result->count);
        self::assertSame(5, $result->first);
        self::assertCount(5, self::records($result));
        self::assertEquals(3, ceil($result->count / 5));

        // a source the user may not use: not ready, so core lists nothing
        $denied = self::book($dsn, [], 'bob@example.org', '', $n, BookDefinition::GLOBAL);
        self::assertFalse($denied->ready);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testComposeSearchAndSearchModeList(string $dsn): void
    {
        $afields = self::cfg('contactlist_fields');
        $mode = (int) self::cfg('addressbook_search_mode');
        $searchMode = $mode | \rcube_addressbook::SEARCH_GROUPS;

        // mail/search-contacts?_q=sprz
        $searchSet = [];
        $rows = [];
        $records = [];
        $factory = Env::factory(Env::config($dsn), self::JAN);
        foreach (array_keys($factory->books()) as $id) {
            $source = self::sorted($factory->create($id));
            $source->set_page(1);
            $source->set_pagesize(9999);
            if ($source->groups) {
                $rows += self::composeContactGroups($source, $id, 'sprz', $searchMode);
            }
            $result = $source->search($afields, 'sprz', $searchMode, true, true, 'email');
            if (!$result->count) {
                continue;
            }
            foreach ($result as $row) {
                $row['sourceid'] = $id;
                $records[\rcube_addressbook::compose_contact_key($row, (string) self::cfg('addressbook_sort_col', 'name'))] = $row;
            }
            $searchSet[$id] = $source->get_search_set();
        }
        self::assertSame(['G' . self::sprzedaz()], array_keys($rows));
        self::assertSame(['team@example.com'], self::addresses($records));
        // what core keeps in the session between the two requests
        $session = unserialize(serialize([
            'contact_search' => ['composeaddr' => $searchSet],
            'contact_search_params' => ['id' => 'composeaddr', 'data' => [$afields, 'sprz'], 'scope' => null],
        ]));

        // mail/list-contacts?_search=composeaddr (later request): groups again, then the stored set
        $listed = [];
        $sparam = $session['contact_search_params']['id'] == 'composeaddr' ? $session['contact_search_params']['data'] : [];
        foreach ($session['contact_search']['composeaddr'] as $s => $set) {
            $contacts = self::book($dsn, [], self::JAN, '', $n, $s);
            $groupRows = [];
            if ($sparam[1] && $contacts->groups) {
                $groupRows = self::composeContactGroups($contacts, $s, $sparam[1], $mode);
            }
            self::assertSame(['G' . self::sprzedaz()], array_keys($groupRows));
            $contacts->set_page(1);
            $contacts->set_pagesize(9999);
            $contacts->set_search_set($set);
            $listed = array_merge($listed, self::records($contacts->list_records($afields)));
        }
        self::assertSame(['team@example.com'], self::addresses($listed));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testFullSetSearchListsAreCappedWithANotice(string $dsn): void
    {
        $afields = self::cfg('contactlist_fields');
        $mode = (int) self::cfg('addressbook_search_mode') | \rcube_addressbook::SEARCH_GROUPS;

        // addressbook/search: count first, then list_records with page size 9999
        $source = self::book($dsn, ['galbridge_max_results' => 10], self::JAN, 'search', $notices);
        $source->set_page(1);
        $source->set_pagesize(9999);
        self::assertGreaterThan(0, $source->search('*', 'example', $mode, false)->count);
        $records = self::records($source->list_records($afields));
        self::assertCount(10, $records, 'capped at galbridge_max_results');
        self::assertSame([['toomanyresults', ['max' => '10']]], $notices);

        // mail/search-contacts
        $source = self::book($dsn, ['galbridge_max_results' => 10], self::JAN, 'search-contacts', $notices);
        $source->set_page(1);
        $source->set_pagesize(9999);
        self::assertCount(10, self::records($source->search($afields, 'example', $mode, true, true, 'email')));
        self::assertSame(['toomanyresults'], self::labels($notices));

        // under the cap: everything, no notice
        $source = self::book($dsn, [], self::JAN, 'search', $notices);
        $source->set_page(1);
        $source->set_pagesize(9999);
        $source->search('*', 'example', $mode, false);
        self::assertSame(self::JAN_BOOK, self::addresses($source->list_records($afields)));
        self::assertSame([], $notices);

        // exactly the cap: no notice
        $source = self::book($dsn, ['galbridge_max_results' => 13], self::JAN, 'search', $notices);
        $source->set_page(1);
        $source->set_pagesize(9999);
        $source->search('*', 'example', $mode, false);
        self::assertCount(13, self::records($source->list_records($afields)));
        self::assertSame([], $notices);
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testMailtoSelectedContactsAndGroup(string $dsn): void
    {
        $jan = self::id(self::JAN);
        $anna = self::id('anna.nowak@example.com');

        // addressbook/mailto: POST _cid (comma list) + _source
        $_POST = ['_cid' => $jan . ',' . $anna, '_source' => self::SOURCE];
        $_REQUEST = $_POST;
        self::assertSame(['Anna Nowak <anna.nowak@example.com>', 'Jan Kowalski <jan.kowalski@example.com>'], self::mailto(self::book($dsn, [], self::JAN, 'mailto')));

        // from a search result: _source='' and ID-source pairs
        $_POST = ['_cid' => $jan . '-' . self::SOURCE, '_source' => ''];
        $_REQUEST = $_POST;
        self::assertSame(['Jan Kowalski <jan.kowalski@example.com>'], self::mailto(self::book($dsn, [], self::JAN, 'mailto')));

        // foreign / crafted IDs: never the book - and since the second verification pass (F-31) the
        // selection is refused as a whole, never cut to the in-scope IDs (a recipient set does not
        // lack a selected entry silently)
        $_POST = ['_cid' => [self::SOURCE => [self::id('bob@example.org'), '21981839', $anna]]];
        $_REQUEST = $_POST;
        self::assertSame([], self::mailto(self::book($dsn, [], self::JAN, 'mailto', $notices)));
        self::assertSame(['incomplete'], self::labels($notices));
        $_POST = ['_cid' => [self::SOURCE => ['21981839']]];
        $_REQUEST = $_POST;
        self::assertSame([], self::mailto(self::book($dsn, [], self::JAN, 'mailto', $notices)));
        self::assertSame(['incomplete'], self::labels($notices));

        // compose to a group (no group e-mail: list_records with max_group_members ?: 999)
        $_POST = ['_gid' => self::sprzedaz(), '_source' => self::SOURCE];
        $_REQUEST = $_POST;
        self::assertSame(['Anna Nowak <anna.nowak@example.com>', 'Jan Kowalski <jan.kowalski@example.com>'], self::mailto(self::book($dsn, [], self::JAN, 'mailto')));
        $_POST = ['_gid' => 'example.org', '_source' => self::SOURCE];
        $_REQUEST = $_POST;
        self::assertSame([], self::mailto(self::book($dsn, [], self::JAN, 'mailto')));
        $_POST = ['_gid' => self::sprzedaz(), '_source' => self::SOURCE];
        $_REQUEST = $_POST;
        self::assertSame([], self::mailto(self::book($dsn, ['galbridge_max_group_members' => 1], self::JAN, 'mailto', $notices)));
        self::assertSame(['grouptoolarge'], self::labels($notices));

        // a source the user may not use is not ready: core skips it
        $_POST = ['_cid' => $jan, '_source' => self::SOURCE];
        $_REQUEST = $_POST;
        self::assertSame([], self::mailto(self::book($dsn, [], 'bob@example.org', 'mailto', $n, BookDefinition::GLOBAL)));
    }

    /**
     * With core's max_group_members set, mailto asks for exactly that page size instead of 999, so the
     * request no longer looks like a full-set request. ARCHITECTURE 11 still applies: compose-to-group
     * above min(galbridge_max_group_members, max_group_members) is refused, never a truncated prefix.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testMailtoGroupWithCoreMaxGroupMembersIsCompleteOrRefused(string $dsn): void
    {
        $_POST = ['_gid' => self::sprzedaz(), '_source' => self::SOURCE];
        $_REQUEST = $_POST;

        // the group (2 members) fits max_group_members = 2: complete
        $book = self::book($dsn, ['max_group_members' => 2], self::JAN, 'mailto', $notices);
        self::assertSame(['Anna Nowak <anna.nowak@example.com>', 'Jan Kowalski <jan.kowalski@example.com>'], self::mailto($book, 2));
        self::assertSame([], $notices);

        // above it: refused with the visible warning - not the first member only
        $book = self::book($dsn, ['max_group_members' => 1], self::JAN, 'mailto', $notices);
        $recipients = self::mailto($book, 1);
        self::assertSame([], $recipients, 'compose-to-group returned a truncated prefix of the group');
        self::assertSame([['grouptoolarge', ['max' => '1']]], $notices);
    }

    // ------------------------------------------------------------------ export / copy

    #[DataProviderExternal(Env::class, 'engines')]
    public function testExportPaths(string $dsn): void
    {
        // addressbook/export?_source=.. (the whole directory): contact_source, page 1, 99999, nocount
        $contacts = self::contactSource(self::book($dsn, [], self::JAN, 'export', $notices));
        $contacts->set_page(1);
        $contacts->set_pagesize(99999);
        $result = $contacts->list_records(null, 0, true);
        self::assertSame(self::JAN_BOOK, self::addresses($result));
        self::assertSame([], $notices);
        foreach ($result as $row) {
            $vcard = self::exportVcard($row, $contacts);
            self::assertSame(\rcube_addressbook::get_col_values('email', $row, true), \rcube_addressbook::get_col_values('email', $vcard, true));
            self::assertSame($row['name'], $vcard['name'] ?? null);
        }

        // refused: export disabled for the book, or more entries than galbridge_max_export
        foreach ([[['galbridge_domain_export' => false], ['exportdisabled', []]], [['galbridge_max_export' => 5], ['exporttoolarge', ['max' => '5']]]] as [$options, $notice]) {
            $contacts = self::contactSource(self::book($dsn, $options, self::JAN, 'export', $notices));
            $contacts->set_page(1);
            $contacts->set_pagesize(99999);
            $result = $contacts->list_records(null, 0, true);
            self::assertSame([], self::records($result), json_encode($options) ?: '');
            self::assertSame([$notice], $notices);
            self::assertNotEmpty($contacts->get_error());
        }
        $contacts = self::contactSource(self::book($dsn, ['galbridge_max_export' => 13], self::JAN, 'export', $notices));
        $contacts->set_page(1);
        $contacts->set_pagesize(99999);
        self::assertCount(13, self::records($contacts->list_records(null, 0, true)), 'exactly galbridge_max_export');

        // the global book does not export by default
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org'], 'galbridge_global_searchonly' => false];
        $global = self::contactSource(self::book($dsn, $options, self::JAN, 'export', $notices, BookDefinition::GLOBAL));
        $global->set_page(1);
        $global->set_pagesize(99999);
        self::assertSame([], self::records($global->list_records(null, 0, true)));
        self::assertSame(['exportdisabled'], self::labels($notices));

        // a group (_gid): all members
        $_GET = ['_gid' => self::sprzedaz(), '_source' => self::SOURCE];
        $contacts = self::contactSource(self::book($dsn, [], self::JAN, 'export'));
        $contacts->set_page(1);
        $contacts->set_pagesize(99999);
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($contacts->list_records(null, 0, true)));

        // selected contacts (_cid in the GET URL): set_pagesize(count($ids)) + search('ID', $ids, 1, true, true)
        $exportSelected = static function (string $cids) use ($dsn, &$notices): array {
            $_GET = ['_cid' => $cids, '_source' => self::SOURCE];
            $records = [];
            $notices = [];
            foreach (\rcmail_action_contacts_index::get_cids() as $s => $ids) {
                $source = self::book($dsn, [], self::JAN, 'export', $n, $s);
                $source->set_page(1);
                $source->set_pagesize(count($ids));
                foreach ($source->search('ID', $ids, 1, true, true) as $record) {
                    self::exportVcard($record, $source);
                    $record['sourceid'] = $s;
                    $records[\rcube_addressbook::compose_contact_key($record, (string) self::cfg('addressbook_sort_col', 'name'))] = $record;
                }
                $notices = array_merge($notices, $n);
            }
            return $records;
        };
        $selected = self::id(self::JAN) . ',' . self::id('newsletter@example.com', IdCodec::MAILLIST);
        self::assertSame([self::JAN, 'newsletter@example.com'], self::addresses($exportSelected($selected)));
        self::assertSame([], $notices);
        self::assertLessThan(2048, strlen((string) $_GET['_cid']), 'IDs stay short enough for the export URL');
        // with an entry of another tenant among them: refused as a whole (second verification pass,
        // F-31) - the hook answers 204 instead of a file that silently lacks a selected entry
        self::assertSame([], $exportSelected($selected . ',' . self::id('bob@example.org')));
        self::assertSame(['incomplete'], self::labels($notices));
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testCopyToAPersonalBook(string $dsn): void
    {
        // addressbook/copy: get_record($id, true) per ID, then TARGET->insert() (rcube_contacts)
        $_POST = ['_cid' => implode(',', [self::id(self::JAN), self::id('postmaster@example.com'), self::id('team@example.com', IdCodec::ALIAS), self::id('bob@example.org')]), '_source' => self::SOURCE, '_to' => '0'];
        $convert = new \ReflectionMethod(\rcube_contacts::class, 'convert_save_data');
        $target = (new \ReflectionClass(\rcube_contacts::class))->newInstanceWithoutConstructor();
        $copied = [];
        foreach (\rcmail_action_contacts_index::get_cids() as $source => $cid) {
            $contacts = self::book($dsn, ['galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'phone', 'mobile', 'birthday']], self::JAN, 'copy', $n, $source);
            foreach ($cid as $id) {
                $record = $contacts->get_record($id, true);
                if (empty($record)) {
                    continue;
                }
                self::assertNotSame('group', $record['_type'] ?? null, 'directory entries are copied as contacts');
                $email = $contacts->get_col_values('email', $record, true);
                self::assertNotEmpty($email);
                // rcube_contacts::convert_save_data(): strlen() on every value - no nulls, scalar names, no 'vcard'
                $saved = $convert->invoke($target, $record);
                self::assertSame($email[0], $saved['email']);
                self::assertSame($record['name'], $saved['name']);
                self::assertStringContainsString('EMAIL;TYPE=work:' . $email[0], $saved['vcard']);
                $copied[] = $email[0];
            }
        }
        sort($copied);
        self::assertSame([self::JAN, 'postmaster@example.com', 'team@example.com'], $copied, 'the foreign ID is skipped');
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testWritePathsAreRefused(string $dsn): void
    {
        $contacts = self::book($dsn);
        // edit/save/delete/move/import/group-*: core checks readonly (and empty(deletable))
        self::assertTrue($contacts->readonly);
        self::assertTrue($contacts->readonly && empty(get_object_vars($contacts)['deletable'] ?? null));
        self::assertTrue($contacts->readonly || !$contacts->groups, 'group actions answer "sourceisreadonly"');
        self::assertFalse($contacts->undelete);
        // second line of defence: the base write methods do nothing
        self::assertFalse($contacts->insert(['name' => 'Mallory', 'email' => 'mallory@example.com']));
        self::assertFalse($contacts->update(self::id(self::JAN), ['name' => 'Mallory']));
        self::assertFalse($contacts->delete([self::id(self::JAN)]));
        self::assertFalse($contacts->delete_all());
        self::assertFalse($contacts->create_group('x'));
        self::assertFalse($contacts->delete_group(self::sprzedaz()));
        $newId = null;
        self::assertFalse($contacts->rename_group(self::sprzedaz(), 'x', $newId));
        self::assertEmpty($contacts->add_to_group(self::sprzedaz(), [self::id('postmaster@example.com')]));
        self::assertEmpty($contacts->remove_from_group(self::sprzedaz(), [self::id(self::JAN)]));
        // nothing changed
        $contacts->set_pagesize(50);
        $contacts->set_group(self::sprzedaz());
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($contacts->list_records()));
        $contacts->close();
    }

    // ------------------------------------------------------------------ list names (compose_list_name)

    /**
     * @return array<string, array{int, string}>
     */
    public static function listingModes(): array
    {
        $out = [];
        foreach ([0, 1, 2, 3] as $mode) {
            $out["addressbook_name_listing {$mode}"] = [$mode, 'default'];
        }
        $out['contactlist_name_template: name and address, mode 0'] = [0, 'name and address'];
        $out['contactlist_name_template: first and last name, mode 1'] = [1, 'first and last name'];
        $out['contactlist_name_template: last and first name with address, mode 3'] = [3, 'last and first name with address'];
        $out['contactlist_name_template: name and department, mode 2'] = [2, 'name and department'];
        $out['contactlist_name_template: name and department in brackets, mode 0'] = [0, 'name and department in brackets'];
        $out['contactlist_name_template: name and department in brackets, mode 1'] = [1, 'name and department in brackets'];
        $out['contactlist_name_template: name and department in square brackets, mode 0'] = [0, 'name and department in square brackets'];
        $out['contactlist_name_template: department in square brackets first, mode 0'] = [0, 'department in square brackets first'];
        $out['contactlist_name_template: department in square brackets first, mode 2'] = [2, 'department in square brackets first'];
        $out['contactlist_name_template: first and last name with department, mode 0'] = [0, 'first and last name with department'];
        $out['contactlist_name_template: first and last name with department, mode 1'] = [1, 'first and last name with department'];
        return $out;
    }

    /**
     * rcube_addressbook::compose_list_name() - used by autocomplete, group-expand, the compose
     * contact list and the address-book list - builds the name from first/last name in the user's
     * modes 1-3 and applies the administrator's contactlist_name_template. In every mode a name never
     * poses as another address (no at-sign or look-alike) and a foreign global-book entry is never
     * shown without its "(domain)" marker (blank padding cannot push it out of view either).
     *
     * Each mode runs in its own process: core caches the mode and the template in static variables.
     */
    #[RunInSeparateProcess]
    #[DataProvider('listingModes')]
    public function testListNamesKeepTheProtectionsInEveryListingMode(int $mode, string $templateKey): void
    {
        $template = self::NAME_TEMPLATES[$templateKey];
        \rcube::get_instance()->config->set('addressbook_name_listing', $mode);
        \rcube::get_instance()->config->set('contactlist_name_template', $template);
        $mapper = self::mapper(['example.com', 'example.org']);
        $records = [
            'home' => $mapper->person(['username' => self::JAN, 'domain' => 'example.com', 'name' => 'Jan Kowalski', 'first_name' => 'Jan', 'last_name' => 'Kowalski', 'department' => 'Sprzedaż']),
            'home without a display name' => $mapper->person(['username' => 'anna.nowak@example.com', 'domain' => 'example.com', 'name' => '', 'first_name' => 'Anna', 'last_name' => 'Nowak']),
            'home impersonating' => $mapper->person(['username' => 'zofia.zolkiewska@example.com', 'domain' => 'example.com', 'name' => "CEO\u{FF20}bank.example",
                'first_name' => 'ceo@bank.example', 'last_name' => "Smith\u{FE6B}evil.example"]),
            'foreign' => $mapper->person(['username' => 'bob@example.org', 'domain' => 'example.org', 'name' => 'Bob Smith', 'first_name' => 'Bob', 'last_name' => 'Smith', 'department' => 'Sales']),
            'foreign padded' => $mapper->person(['username' => 'bob.smith@example.org', 'domain' => 'example.org', 'name' => 'Bob' . str_repeat("\u{2800}", 200) . str_repeat("\u{3164}", 50),
                'first_name' => "Bob\u{FFA0}", 'last_name' => "Smith\u{00AD}\u{E0041}"]),
            'foreign impersonating' => $mapper->person(['username' => 'eve@example.org', 'domain' => 'example.org', 'name' => '', 'first_name' => "ceo\u{FF20}bank.example", 'last_name' => 'Smith']),
        ];
        $names = [];
        foreach ($records as $label => $record) {
            self::assertIsArray($record, $label);
            $email = $record['email:work'][0];
            $name = \rcube_addressbook::compose_list_name($record);
            $names[$label] = $name;
            self::assertDoesNotMatchRegularExpression('/[\x{FF20}\x{FE6B}]|\p{Cf}|[\x{2800}\x{3164}\x{115F}\x{1160}\x{FFA0}]/u', $name, $label);
            self::assertStringNotContainsString('@', str_replace($email, '', $name), "{$label}: an at-sign outside the entry's own address");
            if (str_starts_with($label, 'foreign')) {
                // either marked, or no name at all (the bare address is shown)
                self::assertTrue(str_starts_with($name, '(example.org) ') || !preg_match('/Bob|Smith|ceo|Sales/', $name), "{$label}: unmarked foreign name '{$name}'");
            }
            // the recipient core builds from it (autocomplete, group-expand)
            $recipient = format_email_recipient($email, $name);
            self::assertStringNotContainsString('@', str_replace($email, '', $recipient), "{$label}: {$recipient}");
        }

        // A foreign entry carries no text that this template shows before its name or without it: the
        // marker is part of the name. An entry of the viewer's own domain keeps every field.
        $withheld = RecordMapper::textBeforeName($template);
        self::assertSame(in_array($templateKey, self::TEXT_BEFORE_NAME, true) ? ['department'] : [], $withheld);
        self::assertSame($withheld === [], isset($records['foreign']['department']), 'the department of a foreign entry');
        self::assertSame('Sprzedaż', $records['home']['department'] ?? null);
        if ($withheld !== []) {
            self::assertStringNotContainsString('Sales', $names['foreign']);
        }

        // Names that become an RFC 2047 encoded-word - or an HTML character reference - only once
        // core has composed the list name: it DELETES empty bracket pairs (with the whitespace before
        // them) when a template is set, which joins the text on both sides; a text that starts with a
        // closing bracket pairs with the bracket of the template; and an entry without any name gets
        // the local part of its address - of the canonical alphabet (second verification pass, F-20:
        // '=' and '+' are part of it, '&', '#' and '?' are not).
        $word = '?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=';
        $hostile = [
            'deleted brackets' => $mapper->person(['username' => 'h1@example.com', 'domain' => 'example.com', 'name' => '=()' . $word, 'first_name' => '=[]' . $word,
                'last_name' => '= ()?utf-8?q?ceo=40bank.example?=', 'department' => '=([()])' . $word]),
            'bracket of the template' => $mapper->person(['username' => 'h2@example.com', 'domain' => 'example.com', 'name' => 'Jan =', 'first_name' => 'Jan', 'last_name' => '=',
                'department' => ')' . $word]),
            'square bracket of the template' => $mapper->person(['username' => 'h3@example.com', 'domain' => 'example.com', 'name' => $word, 'first_name' => $word, 'last_name' => 'x',
                'department' => 'Sales =[']),
            'foreign, deleted brackets' => $mapper->person(['username' => 'h4@example.org', 'domain' => 'example.org', 'name' => '=()' . $word, 'department' => ')' . $word]),
            'local part as the name: equals signs at the edges' => $mapper->person(['username' => '=utf-8=b=@example.com', 'domain' => 'example.com', 'name' => '']),
            'local part as the name: a name core takes for empty' => $mapper->person(['username' => '=q=ceo=40bank.example=@example.com', 'domain' => 'example.com', 'name' => '0']),
            'local part as the name, foreign' => $mapper->person(['username' => '=boss=@example.org', 'domain' => 'example.org', 'name' => '"', 'first_name' => '@x']),
            'list without a name' => $mapper->mailList('l', ['address' => '=all=@example.com', 'domain' => 'example.com', 'name' => '']),
        ];
        // an address outside the canonical alphabet is no entry at all (the SQL of the book does not
        // offer it either): '&', '#', '?' ... can never reach a name or a sink of core
        foreach ([
            'character reference' => $mapper->person(['username' => 'ceo&#64bank.example@example.com', 'domain' => 'example.com', 'name' => '']),
            'encoded-word' => $mapper->person(['username' => '=?UTF-8?B?Y2Vv?=@example.com', 'domain' => 'example.com', 'name' => '']),
            'character reference, foreign' => $mapper->person(['username' => 'boss&#64bank.example@example.org', 'domain' => 'example.org', 'name' => '"', 'first_name' => '@x']),
            'list, character reference' => $mapper->mailList('l', ['address' => 'all&#64bank.example@example.com', 'domain' => 'example.com', 'name' => '']),
        ] as $label => $record) {
            self::assertNull($record, $label);
        }
        self::assertStringContainsString('WARN scope: row_invalid', implode("\n", $GLOBALS['galbridge_test_log']));
        foreach ($hostile as $label => $record) {
            self::assertIsArray($record, $label);
            $email = $record['email:work'][0];
            $name = \rcube_addressbook::compose_list_name($record);
            // the entry's own address may appear in a template ({email}) and is what it is
            $text = str_replace($email, '', $name);
            $label .= ": {$name}";
            self::assertStringNotContainsString('=?', $text, $label);
            self::assertSame($text, \rcube_mime::decode_header($text, 'UTF-8'), $label . ' (decoded as a header)');
            self::assertStringNotContainsString('&', $text, $label);
            self::assertSame($text, html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $label . ' (decoded as HTML)');
            self::assertStringNotContainsString('@', $text, $label);
            $recipient = format_email_recipient($email, $name);
            self::assertSame(str_replace($email, '', $recipient), \rcube_mime::decode_header(str_replace($email, '', $recipient), 'UTF-8'), $label);
            if (str_ends_with($email, '@example.org')) {
                // marked - or no text of the entry at all (a template of first and last name only:
                // a foreign entry carries neither, the bare address is shown)
                self::assertTrue(str_contains($name, '(example.org) ') || trim($text, ' <>()[]-') === '', $label . ': an unmarked foreign name');
            }
        }

        if ($template === '{name}') {
            $expected = [
                0 => ['Jan Kowalski', 'Anna Nowak', 'CEO', '(example.org) Bob Smith', '(example.org) Bob', '(example.org) ceo Smith'],
                1 => ['Jan Kowalski', 'Anna Nowak', 'ceo Smith', '(example.org) Bob Smith', '(example.org) Bob', '(example.org) ceo Smith'],
                2 => ['Kowalski Jan', 'Nowak Anna', 'Smith ceo', '(example.org) Bob Smith', '(example.org) Bob', '(example.org) ceo Smith'],
                3 => ['Kowalski, Jan', 'Nowak, Anna', 'Smith, ceo', '(example.org) Bob Smith', '(example.org) Bob', '(example.org) ceo Smith'],
            ][$mode];
            self::assertSame($expected, array_values($names));

            // end to end: autocomplete of the global book from the database
            $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
            $book = self::book(Env::sqliteDsn(), $options, self::JAN, 'autocomplete', $n, BookDefinition::GLOBAL);
            self::assertSame(['"(example.org) Bob Smith" <bob@example.org>'], array_keys(self::autocomplete($book, BookDefinition::GLOBAL, 'bob')));
        }
    }

    /**
     * A RecordMapper for jan's global book (home domain example.com, foreign entries marked).
     *
     * @param list<string> $domains
     */
    private static function mapper(array $domains): RecordMapper
    {
        $book = new BookDefinition(
            id: BookDefinition::GLOBAL,
            tag: 'g',
            name: 'Global directory',
            allDomains: false,
            domains: $domains,
            excludeDomains: [],
            homeDomains: ['example.com'],
            grouping: 'none',
            searchonly: true,
            autocomplete: true,
            export: false,
            prefixOnly: true,
            everyWordMinLength: true,
            maxResults: 100,
            markForeign: true,
            orgLabels: [],
        );
        $viewer = new Viewer(self::JAN, 'example.com', [self::JAN], ['mailbox' => ['username', 'name', 'domain', 'first_name', 'last_name', 'department']]);
        return new RecordMapper($book, new FieldMap(['name', 'firstname', 'surname', 'email', 'department'], $viewer, false, false), [], []);
    }

    /**
     * @return array<string, array{mixed, list<string>}>
     */
    public static function nameTemplates(): array
    {
        return [
            'default' => ['{name}', []],
            'the example of defaults.inc.php' => ['{name} ({organization})', []],
            'department after the name' => ['{name} - {department}', []],
            'telephone after the name' => ['{name}, {phone}', []],
            'literal text before the name' => ['Contact: {name} ({department})', []],
            'address and organization before the name' => ['{email} {organization}: {name}', []],
            'department before the name' => ['[{department}] {name}', ['department']],
            'telephone before the name' => ['{phone} {name}', ['phone:work', 'phone:mobile']],
            'both before the name' => ['{department} / {phone} / {name} / {department}', ['department', 'phone:work', 'phone:mobile']],
            'no name: first and last name with department' => ['{firstname} {surname} ({department})', ['department']],
            'no name: department only' => ['{department}', ['department']],
            'no name: first and last name only' => ['{firstname} {surname}', []],
            'no placeholder' => ['Contact', []],
            'not a string' => [['{department}'], []],
            'null' => [null, []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('nameTemplates')]
    public function testTextThatATemplateShowsBeforeTheNameIsFound(mixed $template, array $expected): void
    {
        self::assertSame($expected, RecordMapper::textBeforeName($template));
    }

    // ------------------------------------------------------------------ page sizes above max_pagesize

    /**
     * Core lists with addressbook_pagesize as configured (it clamps the preference to max_pagesize
     * only when a user saves it): a normal page larger than max_pagesize must page - never take the
     * export or group-expansion policy. Export, mailto and group-expand stay full-set requests.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testPageSizeAboveMaxPagesizeStillPages(string $dsn): void
    {
        $sizes = ['max_pagesize' => 10, 'addressbook_pagesize' => 12];
        foreach ($sizes as $name => $value) {
            $this->core($name, $value);
        }
        $options = $sizes + ['galbridge_domain_export' => false, 'galbridge_max_group_members' => 1];

        // addressbook/list, page 1 and 2
        $contacts = self::contactSource(self::book($dsn, $options, self::JAN, 'list', $notices));
        self::assertSame(12, $contacts->page_size);
        $result = $contacts->list_records(self::cfg('contactlist_fields'));
        self::assertSame(13, $result->count);
        self::assertSame(0, $result->first);
        self::assertCount(12, self::records($result), 'a page, not the whole book');
        self::assertSame([], $notices, 'no export refusal while browsing');
        self::assertNull($contacts->get_error());
        self::assertEquals(2, ceil($result->count / 12), 'pagecount');
        $_GET['_page'] = '2';
        $contacts = self::contactSource(self::book($dsn, $options, self::JAN, 'list', $notices));
        $result = $contacts->list_records(self::cfg('contactlist_fields'));
        self::assertSame(12, $result->first);
        self::assertCount(1, self::records($result));
        unset($_GET['_page']);
        $_SESSION['page'] = 1;

        // browsing a group above galbridge_max_group_members: listed, not refused as an expansion
        $_GET['_gid'] = self::sprzedaz();
        $contacts = self::contactSource(self::book($dsn, $options, self::JAN, 'list', $notices));
        self::assertSame(['anna.nowak@example.com', self::JAN], self::addresses($contacts->list_records(self::cfg('contactlist_fields'))));
        self::assertSame([], $notices, 'viewing a group is not expanding it');
        unset($_GET['_gid']);

        // mail/list-contacts (compose): the same page size
        $contacts = self::book($dsn, $options, self::JAN, 'list-contacts', $notices);
        $contacts->set_pagesize(self::cfg('addressbook_pagesize'));
        $contacts->set_page(1);
        self::assertCount(12, self::records($contacts->list_records(self::cfg('contactlist_fields'))));
        self::assertSame([], $notices);

        // the export is still a whole-book request (refused here: export is off)
        $export = self::contactSource(self::book($dsn, $options, self::JAN, 'export', $notices));
        $export->set_page(1);
        $export->set_pagesize(99999);
        self::assertSame([], self::records($export->list_records(null, 0, true)));
        self::assertSame(['exportdisabled'], self::labels($notices));

        // mailto and group-expand are recipient sets by action, whatever page size they use
        $_POST = ['_gid' => self::sprzedaz(), '_source' => self::SOURCE];
        $_REQUEST = $_POST;
        self::assertSame([], self::mailto(self::book($dsn, $options, self::JAN, 'mailto', $notices), 12));
        self::assertSame(['grouptoolarge'], self::labels($notices));
        $_POST = $_REQUEST = [];
        $book = self::book($dsn, $options, self::JAN, 'group-expand', $notices);
        $book->set_group(self::sprzedaz());
        $book->set_pagesize(12);
        self::assertSame([], self::records($book->list_records()));
        self::assertSame(['grouptoolarge'], self::labels($notices));
    }

    // ------------------------------------------------------------------ autocomplete switch

    /**
     * galbridge_domain_autocomplete = false covers groups too: an autocomplete request naming the
     * book (core accepts any _source) gets neither contacts nor groups; other actions keep the groups.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAutocompleteSwitchAlsoHidesGroups(string $dsn): void
    {
        $off = ['galbridge_domain_autocomplete' => false];
        $mode = (int) self::cfg('addressbook_search_mode') | \rcube_addressbook::SEARCH_GROUPS;

        $book = self::book($dsn, $off, self::JAN, 'autocomplete');
        self::assertTrue($book->groups, 'the book still has groups');
        self::assertSame([], $book->list_groups('sprz', $mode));
        self::assertSame([], $book->list_groups());
        self::assertSame([], self::autocomplete(self::book($dsn, $off, self::JAN, 'autocomplete'), self::SOURCE, 'sprz'));
        self::assertSame([], self::autocomplete(self::book($dsn, $off, self::JAN, 'autocomplete'), self::SOURCE, 'kowal'));

        // the same book outside autocomplete: groups as usual
        self::assertSame([self::sprzedaz()], array_column(self::book($dsn, $off, self::JAN, 'search-contacts')->list_groups('sprz', $mode), 'ID'));
        self::assertCount(3, self::book($dsn, $off)->list_groups());

        // autocomplete on: the group is offered
        self::assertArrayHasKey('Sprzedaż', self::autocomplete(self::book($dsn, [], self::JAN, 'autocomplete'), self::SOURCE, 'sprz'));
    }
}
