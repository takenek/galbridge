<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\AccessPolicy;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\Repository;
use GalBridge\Directory\Schema;
use GalBridge\Directory\Viewer;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Normalizer;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * One regression test per finding of the security audit of 1.0.0 (IDs U-xx / I-xx of that audit,
 * listed in CHANGELOG.md) that needs the whole stack: the production wiring against the fixture on
 * every engine, where necessary with rows the seed does not have (added and removed again through
 * Support\Fixture). The configuration, DSN and command line findings are covered where those
 * components are tested (ConfigTest, DsnPolicyTest, CliToolTest, NormalizerTest, PluginHooksTest).
 *
 * @license GPL-3.0-or-later
 */
final class AuditRegressionTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';
    private const ANNA = 'anna.nowak@example.com';

    /** the domain book of example.com as jan sees it: 7 persons, 5 alias accounts, 1 list */
    private const BOOK_SIZE = 13;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
        self::newRequest();
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
    }

    // ------------------------------------------------------------------ U-01: hidden per-user aliases

    /**
     * A per-user alias on a hidden list was not shown but still MATCHED: searching for it - whole,
     * by prefix or by a fragment - returned its owner, i.e. told who receives a hidden (e.g.
     * functional) address. Showing and matching now share one predicate.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testHiddenUserAliasIsNeitherShownNorMatched(string $dsn): void
    {
        // [search word, mode]: 1 = strict, 2 = prefix, 0 = partial
        $needles = [['jk@example.com', 1], ['jk@ex', 2], ['jk@example.co', 0], ['sprzedaz@example.com', 1], ['sprzedaz@', 2], ['rzedaz@', 0]];

        // the precondition - and what the test would look like if it could not see the leak: with
        // aliases shown and nothing hidden, every one of these words finds jan
        $book = self::book($dsn, ['galbridge_user_aliases' => true]);
        self::assertSame(['jan@example.net', 'jk@example.com', 'sprzedaz@example.com'], self::other($book, self::JAN));
        foreach ($needles as [$word, $mode]) {
            self::assertSame([self::JAN], self::emails($book->search('email', $word, $mode)), "visible alias: '{$word}' mode {$mode}");
        }

        $hidden = ['galbridge_user_aliases' => true, 'galbridge_hidden_addresses' => ['jk@example.com'], 'galbridge_hidden_localparts' => ['sprzedaz']];
        foreach (['', 'autocomplete', 'search', 'export'] as $action) {
            $book = self::book($dsn, $hidden, self::ANNA, $action);
            // not shown ...
            self::assertSame(['jan@example.net'], self::other($book, self::JAN), $action);
            // ... and not matched: in no mode, in no kind of search, as a list or as a count
            foreach ($needles as [$word, $mode]) {
                $label = "hidden alias: '{$word}' mode {$mode} action '{$action}'";
                self::assertSame([], self::emails($book->search('email', $word, $mode)), $label);
                self::assertSame(0, $book->search('email', $word, $mode, false)->count, $label . ' (count)');
                self::assertSame(0, $book->count()->count, $label . ' (count of the stored search)');
                self::assertNotContains(self::JAN, self::emails($book->search('*', $word, $mode)), $label . ' (all fields)');
                self::assertNotContains(self::JAN, self::emails($book->search(['email'], [$word], $mode)), $label . ' (advanced)');
                self::assertNotContains(self::JAN, self::emails($book->search(['name', 'email'], ['Jan', $word], $mode)), $label . ' (advanced, two fields)');
            }
            // the alias that is not hidden still works, and so does the address itself
            self::assertSame([self::JAN], self::emails($book->search('email', 'jan@example.net', 1)), $action);
            self::assertSame([self::JAN], self::emails($book->search('email', self::JAN, 1)), $action);
        }
        // a hidden alias is no reason to hide its owner
        $book = self::book($dsn, $hidden);
        $book->set_pagesize(200);
        self::assertContains(self::JAN, self::emails($book->list_records()));
        self::assertStringNotContainsString('db_error', self::log());
    }

    /**
     * The same predicate decides where an alias ROW belongs: a row of a domain the book does not
     * cover, or of a domain that is inactive, expired or a backup MX, is neither shown nor matched
     * (it used to be shown whenever its owner was visible, and matched whatever its domain).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAliasRowsOutsideTheBookAreNeitherShownNorMatched(string $dsn): void
    {
        $rows = ['jan.ext@example.org' => 'example.org', 'jan.ghost@inactive.test' => 'inactive.test', 'jan.exp@expired.test' => 'expired.test', 'jan.bk@backup.test' => 'backup.test'];
        $values = [];
        foreach ($rows as $address => $domain) {
            $values[] = "('{$address}', '" . self::JAN . "', '{$domain}', 'example.com', 1, 1)";
        }
        Fixture::alter(
            $dsn,
            ['INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES ' . implode(', ', $values)],
            ["DELETE FROM forwardings WHERE address IN ('" . implode("', '", array_keys($rows)) . "')"],
        );
        $own = ['jan@example.net', 'jk@example.com', 'sprzedaz@example.com'];

        // the domain book of example.com: none of the four rows belongs to it
        $book = self::book($dsn, ['galbridge_user_aliases' => true], self::ANNA);
        self::assertSame($own, self::other($book, self::JAN));
        foreach (array_keys($rows) as $address) {
            self::assertSame([], self::emails($book->search('email', $address, 1)), $address);
            self::assertSame([], self::emails($book->search('email', substr($address, 0, 6), 2)), $address . ' (prefix)');
        }

        // an organization that covers example.org: that row is inside the book now - shown and found
        $org = ['galbridge_user_aliases' => true, 'galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]];
        $book = self::book($dsn, $org, self::ANNA);
        self::assertSame(['jan.ext@example.org', ...$own], self::other($book, self::JAN));
        self::assertSame([self::JAN], self::emails($book->search('email', 'jan.ext@example.org', 1)));

        // a domain that is covered but inactive, a backup MX or expired contributes nothing
        $all = ['galbridge_user_aliases' => true, 'galbridge_organizations' => ['Acme' => ['example.com', 'inactive.test', 'backup.test', 'expired.test']]];
        $book = self::book($dsn, $all, self::ANNA);
        self::assertSame($own, self::other($book, self::JAN));
        foreach (['jan.ghost@inactive.test', 'jan.bk@backup.test', 'jan.exp@expired.test'] as $address) {
            self::assertSame([], self::emails($book->search('email', $address, 1)), $address);
        }
        // ... the expired one only while expired entries are hidden
        $book = self::book($dsn, $all + ['galbridge_hide_expired' => false], self::ANNA);
        self::assertSame(['jan.exp@expired.test', ...$own], self::other($book, self::JAN));
        self::assertSame([self::JAN], self::emails($book->search('email', 'jan.exp@expired.test', 1)));

        // the viewer's OWN aliases, used for the list policies only: the same domain conditions
        // (an alias of a domain the mail system no longer serves must not keep a restricted list
        // visible), but not the scope of a book
        $viewer = Env::factory(Env::config($dsn), self::JAN)->resolver()->resolve();
        self::assertNotNull($viewer);
        self::assertEqualsCanonicalizing([self::JAN, 'jan.ext@example.org', ...$own], $viewer->addresses);
        $viewer = Env::factory(Env::config($dsn, ['galbridge_hide_expired' => false]), self::JAN)->resolver()->resolve();
        self::assertNotNull($viewer);
        self::assertEqualsCanonicalizing([self::JAN, 'jan.ext@example.org', 'jan.exp@expired.test', ...$own], $viewer->addresses);
        self::assertStringNotContainsString('db_error', self::log());
    }

    // ------------------------------------------------------------------ U-04: display names

    /**
     * Names that only become an address once somebody decodes them: an HTML character reference
     * (Roundcube's recipient widget inserts names as HTML) and an RFC 2047 encoded-word (decoded by
     * the mail clients of the recipients). Records carry neither notation.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testNamesCannotBecomeAnAddressWhenDecodedLater(string $dsn): void
    {
        $spoofs = [
            'spoof1@example.com' => ['ceo&#64;bank.example', '', '', 'R&D'],
            'spoof2@example.com' => ['=?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=', '', '', 'R&D'],
            'spoof3@example.com' => ['', 'ceo&commat;bank', '=?utf-8?q?ceo=40bank.example?=', 'R&D'],
            // split by what Roundcube deletes when it composes a name from contactlist_name_template
            'spoof4@example.com' => ['=()?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=', '=[]?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=', '= ()?utf-8?q?ceo=40bank.example?[]=', 'R&D'],
        ];
        $values = [];
        foreach ($spoofs as $address => [$name, $first, $last, $department]) {
            $values[] = "('{$address}', '{$name}', '{$first}', '{$last}', 'example.com', '{$department}', 'example.com/s/p/o/spoof')";
        }
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, first_name, last_name, domain, department, maildir) VALUES ' . implode(', ', $values)],
            ["DELETE FROM mailbox WHERE username LIKE 'spoof_@example.com'"],
        );
        $book = self::book($dsn, ['galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department']]);
        foreach (array_keys($spoofs) as $address) {
            $record = self::record($book, $address);
            self::assertIsArray($record, $address);
            foreach (['name', 'firstname', 'surname', 'department'] as $field) {
                $text = (string) ($record[$field] ?? '');
                $label = "{$address} {$field}: {$text}";
                self::assertStringNotContainsString('&', $text, $label);
                self::assertStringNotContainsString('=?', $text, $label);
                // what the browser and the mail client of a recipient make of it: the same text
                self::assertSame($text, html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $label);
                self::assertSame($text, \rcube_mime::decode_header($text, 'UTF-8'), $label);
                self::assertFalse(Normalizer::containsAt(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $label);
                // ... and the same once core has deleted the empty bracket pairs of a composed name
                // (rcube_addressbook::compose_search_name(), used when a name template is set)
                $composed = (string) preg_replace('/\s*(<>|\(\)|\[\])/u', '', $text);
                self::assertStringNotContainsString('=?', $composed, $label);
                self::assertSame($composed, \rcube_mime::decode_header($composed, 'UTF-8'), $label);
            }
            // the recipient string core builds from the record carries the real address only
            $recipient = format_email_recipient($address, (string) $record['name']);
            self::assertSame(1, substr_count($recipient, '@'), $recipient);
        }
        self::assertSame("ceo\u{FF06}#64;bank.example", self::record($book, 'spoof1@example.com')['name'] ?? null);

        // the department keeps its meaning ("R&D"), and a search typed with '&' still finds its group
        self::assertSame("R\u{FF06}D", self::record($book, 'spoof1@example.com')['department'] ?? null);
        $groups = $book->list_groups('r&d');
        self::assertSame(["R\u{FF06}D"], array_column($groups, 'name'));
        self::assertSame(['spoof1@example.com', 'spoof2@example.com', 'spoof3@example.com', 'spoof4@example.com'], self::emails($book->search('department', 'R&D', 1)));
        // ... and the contact view finds the group of a person of that department (the group key is
        // the raw value, the record carries display text)
        $id = (string) (self::record($book, 'spoof1@example.com')['ID'] ?? '');
        self::assertSame([$groups[0]['ID'] => "R\u{FF06}D"], $book->get_record_groups($id));
        // a person of a department without such characters, and one without a department
        self::assertSame(['Sprzedaż'], array_values($book->get_record_groups((string) (self::record($book, self::JAN)['ID'] ?? ''))));
    }

    /**
     * STAGE 6 (U-04, closing): core fills a contactlist_name_template one placeholder after the other
     * (rcube_addressbook::compose_search_name(), str_replace()), so a "{department}" INSIDE the name
     * was replaced by the department as well - two texts that pass every check one by one were joined
     * into an encoded-word. And department and phones reach recipient names through such a template,
     * so the rule "a name never looks like an address" covers them too.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testFieldsCannotBeJoinedOrPoseAsAnAddressThroughTheNameTemplate(string $dsn): void
    {
        $tail = '?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?';
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, first_name, last_name, domain, department, telephone, mobile, maildir) VALUES'
                . " ('braces1@example.com', '={department}=', '', '', 'example.com', '{$tail}', '', '', 'example.com/b/r/a/braces1'),"
                . " ('braces2@example.com', 'Jan {telephone}{mobile}', '', '', 'example.com', 'ceo@bank.example', '+48 ceo@bank', 'x', 'example.com/b/r/a/braces2'),"
                . " ('braces3@example.com', 'x=', '', '', 'example.com', '?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ?', '=1', '', 'example.com/b/r/a/braces3')"],
            ["DELETE FROM mailbox WHERE username LIKE 'braces_@example.com'"],
        );
        $book = self::book($dsn, ['galbridge_fields' => ['name', 'email', 'department', 'phone', 'mobile']]);
        foreach (['braces1@example.com', 'braces2@example.com', 'braces3@example.com'] as $address) {
            $record = self::record($book, $address);
            self::assertIsArray($record, $address);
            foreach (['name', 'department'] as $field) {
                self::assertDoesNotMatchRegularExpression('/[{}]/', (string) ($record[$field] ?? ''), "{$address} {$field}");
            }
            foreach (['{name} ({department})', '{name} {department}', '{name}{department}', '{department} {name}', '{name} {phone}', '{name}{department}{phone}'] as $template) {
                // exactly what core composes for the list name, the autocomplete entry and the
                // recipient taken from it
                $composed = \rcube_addressbook::compose_search_name($record, null, (string) $record['name'], $template);
                $label = "{$address} / {$template}: {$composed}";
                self::assertStringNotContainsString('=?', $composed, $label);
                self::assertStringNotContainsString('?=', $composed, $label);
                self::assertSame($composed, \rcube_mime::decode_header($composed, 'UTF-8'), $label);
                self::assertFalse(Normalizer::containsAt($composed), $label);
                self::assertSame(1, substr_count(format_email_recipient($address, $composed), '@'), $label);
            }
        }
        // the department keeps what stands before the at-sign; the curly brackets stay readable
        self::assertSame('ceo', self::record($book, 'braces2@example.com')['department'] ?? null);
        self::assertSame("\u{FF1D}{$this->fullwidth('{department}')}\u{FF1D}", self::record($book, 'braces1@example.com')['name'] ?? null);
        self::assertSame(['+48 ceo'], self::record($book, 'braces2@example.com')['phone:work'] ?? null);
    }

    /**
     * STAGE 6 (I-15, closing): the export of a search whose stored criteria this configuration can no
     * longer apply (another fingerprint, another node) is refused - not a file without the entries
     * of the book. Other actions simply show nothing; a new search works again.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAnExportOfAStaleSearchIsRefused(string $dsn): void
    {
        $notices = [];
        $book = self::book($dsn, [], self::JAN, '', $notices);
        $book->search('*', 'Kowalski');
        $stored = $book->get_search_set();
        self::assertIsArray($stored);
        $stale = ['fp' => 'another-configuration'] + $stored;

        $notices = [];
        $export = self::book($dsn, [], self::JAN, 'export', $notices);
        $export->set_search_set($stored);
        $export->set_pagesize(99999);
        self::assertContains(self::JAN, self::emails($export->list_records()), 'a valid stored search is exported');
        self::assertSame([], $notices);

        $export->set_search_set($stale);
        self::assertSame([], self::emails($export->list_records()));
        self::assertSame(['incomplete'], array_column($notices, 0), 'refused: the hook answers 204, no file');

        // another action: nothing, without a refusal; a fresh search in the same book works again
        $notices = [];
        $list = self::book($dsn, [], self::JAN, 'list', $notices);
        $list->set_search_set($stale);
        self::assertSame([], self::emails($list->list_records()));
        self::assertSame([], $notices);
        $export->search('*', 'Kowalski');
        self::assertContains(self::JAN, self::emails($export->list_records()));
    }

    /**
     * STAGE 6 (R-001, closing): PostgreSQL and SQLite compare text case-sensitively. A row written
     * with capitals escaped the hidden lists in SQL - dropped later in PHP, but still counted and
     * matched by a search (an oracle for a hidden entry). The deny-lists compare in lower case.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testHiddenListsAlsoMatchRowsWrittenWithCapitals(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ["INSERT INTO mailbox (username, name, first_name, last_name, domain, department, maildir) VALUES ('Hidden.Capitals@example.com', 'Capitalshidden', '', '', 'example.com', '', 'example.com/h/i/d/hidden')"],
            ["DELETE FROM mailbox WHERE username = 'Hidden.Capitals@example.com'"],
        );
        $count = static fn (AddressBook $book): int => $book->search('*', 'Capitalshidden', 0, false)->count;
        // the control: without a list the entry is found where the engine compares addresses
        // case-insensitively (MariaDB/MySQL: its ID finds the row); PostgreSQL and SQLite do not
        // offer an address written with capitals at all (second verification pass, F-05)
        self::assertSame(str_starts_with($dsn, 'mysql:') ? 1 : 0, $count(self::book($dsn)));
        foreach (['galbridge_hidden_addresses' => ['hidden.capitals@example.com'], 'galbridge_hidden_localparts' => ['hidden.capitals']] as $option => $list) {
            $book = self::book($dsn, [$option => $list]);
            self::assertSame(0, $count($book), $option . ': not counted');
            self::assertSame([], self::emails($book->search('*', 'Capitalshidden')), $option);
            self::assertSame([], self::emails($book->search('email', 'hidden.capitals', 2)), $option . ': not matched by its address');
        }
    }

    private function fullwidth(string $text): string
    {
        return str_replace(['{', '}'], [Normalizer::LEFT_BRACE, Normalizer::RIGHT_BRACE], $text);
    }

    /**
     * The "(domain)" marker of a foreign entry in the global book comes first: a recipient field
     * shortens long names with an ellipsis at the END, which could hide a marker placed there.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testTheForeignDomainMarkerCannotBePushedOutOfSight(string $dsn): void
    {
        $long = str_repeat('Very Long Name ', 16); // 240 characters: fits the column, exceeds the cap of a name
        Fixture::alter(
            $dsn,
            ["INSERT INTO mailbox (username, name, domain, maildir) VALUES ('longname@example.org', '{$long}', 'example.org', 'example.org/l/o/n/longname')"],
            ["DELETE FROM mailbox WHERE username = 'longname@example.org'"],
        );
        $options = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org'], 'galbridge_global_searchonly' => false];
        $book = self::book($dsn, $options, self::JAN, '', $notices, BookDefinition::GLOBAL);
        $name = (string) (self::record($book, 'longname@example.org')['name'] ?? '');
        self::assertStringStartsWith('(example.org) Very Long Name', $name);
        self::assertSame(128, mb_strlen($name) - mb_strlen('(example.org) '), 'the name itself is capped, the marker is never cut');
        self::assertSame('(example.org) Bob Smith', self::record($book, 'bob@example.org')['name'] ?? null);
        // an entry of the viewer's own domain(s) carries no marker
        $home = self::book($dsn, ['galbridge_global_include_home' => true, 'galbridge_global_data_domains' => ['example.com', 'example.org']] + $options, self::JAN, '', $notices, BookDefinition::GLOBAL);
        self::assertSame('Jan Kowalski', self::record($home, self::JAN)['name'] ?? null);
    }

    // ------------------------------------------------------------------ U-05 / I-15: complete or refused

    /**
     * The export of a whole book compared a count BOUNDED by galbridge_max_count with
     * galbridge_max_export: with max_count <= max_export a larger book was never refused and the
     * file held exactly max_count entries, without any message. The same in the fallback path of a
     * group. Both now fetch the limit of the operation plus one row.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testExportsAndRecipientSetsDoNotDependOnTheCountBound(string $dsn): void
    {
        [$apply, $revert] = Fixture::bulkMailboxes(120);
        Fixture::alter($dsn, $apply, $revert);
        $size = self::BOOK_SIZE + 120;

        // ---- whole-book export: max_count (100) <= max_export (200) < the book would be 133 entries
        $limits = ['galbridge_max_count' => 100, 'galbridge_max_export' => 200];
        $book = self::book($dsn, $limits, self::JAN, 'export', $notices);
        $result = self::fullSet($book);
        self::assertCount($size, $result->records, 'the complete book - not the first galbridge_max_count entries');
        self::assertSame($size, $result->count);
        self::assertSame([], $notices);
        self::assertCount($size, array_unique(self::emails($result)));

        // exactly at the limit: complete; one above: refused - never cut
        foreach ([[$size, $size, []], [$size - 1, 0, [['exporttoolarge', ['max' => (string) ($size - 1)]]]], [100, 0, [['exporttoolarge', ['max' => '100']]]]] as [$max, $expected, $expectedNotices]) {
            self::newRequest();
            $book = self::book($dsn, ['galbridge_max_count' => 100, 'galbridge_max_export' => $max], self::JAN, 'export', $notices);
            self::assertCount($expected, self::fullSet($book)->records, "galbridge_max_export = {$max}");
            self::assertSame($expectedNotices, $notices, "galbridge_max_export = {$max}");
        }
        // (list totals are what galbridge_max_count bounds - and nothing else)
        $book = self::book($dsn, $limits);
        $book->set_pagesize(50);
        self::assertSame(100, $book->list_records()->count);

        // ---- a group: 120 members, more than galbridge_max_count
        $bulk = IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'bulk');
        foreach (['mailto', 'group-expand', 'export'] as $action) {
            self::newRequest();
            $book = self::book($dsn, ['galbridge_max_count' => 100, 'galbridge_max_group_members' => 500], self::JAN, $action, $notices);
            $book->set_group($bulk);
            self::assertCount(120, self::fullSet($book)->records, $action);
            self::assertSame([], $notices, $action);
        }
        foreach ([[120, 120], [119, 0]] as [$cap, $expected]) {
            self::newRequest();
            $book = self::book($dsn, ['galbridge_max_count' => 100, 'galbridge_max_group_members' => $cap], self::JAN, 'mailto', $notices);
            $book->set_group($bulk);
            self::assertCount($expected, self::fullSet($book)->records, "galbridge_max_group_members = {$cap}");
            self::assertSame($expected === 0 ? [['grouptoolarge', ['max' => (string) $cap]]] : [], $notices);
        }

        // ---- the fallback path: the book shows NO groups (more than galbridge_max_groups). A group id
        // that is nevertheless sent used to be honoured, with a member count bounded by
        // galbridge_max_count - 100 of 120 members, silently. It is no group of this book now.
        foreach (['mailto', 'group-expand', 'export', 'list'] as $action) {
            self::newRequest();
            $book = self::book($dsn, ['galbridge_max_count' => 100, 'galbridge_max_group_members' => 500, 'galbridge_max_groups' => 1], self::JAN, $action, $notices);
            self::assertSame([], $book->list_groups(), 'the book has more groups than galbridge_max_groups');
            $book->set_group($bulk);
            self::assertNull($book->get_group($bulk));
            self::assertSame([], self::fullSet($book)->records, $action . ': never a truncated member list');
            self::assertSame(0, $book->count()->count, $action);
            $book->set_pagesize(50);
            self::assertSame([], $book->list_records()->records, $action);
        }

        // ---- export of a search result (I-15): complete or refused, like every export; a search
        // LIST on a page is cut with a notice
        foreach ([['export', 119, 0, 'exporttoolarge'], ['export', 120, 120, null], ['search', 119, 119, 'toomanyresults'], ['search', 120, 120, null]] as [$action, $cap, $expected, $notice]) {
            self::newRequest();
            $book = self::book($dsn, ['galbridge_max_results' => $cap], self::JAN, $action, $notices);
            $book->search('name', 'Bulk User', 0, false);
            $book->set_search_set($book->get_search_set());
            self::assertCount($expected, self::fullSet($book)->records, "{$action}, galbridge_max_results = {$cap}");
            self::assertSame($notice === null ? [] : [[$notice, ['max' => (string) $cap]]], $notices, "{$action}, galbridge_max_results = {$cap}");
        }
        self::assertStringNotContainsString('db_error', self::log());
    }

    /**
     * A full set that lacked a row the statement returned - here a row whose address does not belong
     * to its domain column, which the row re-check drops - was refused, for every user of the scope
     * and as long as the row existed. Since the second verification pass (F-16) the SQL of the book
     * applies the same rule as the re-check: such a row is not offered at all (not listed, counted
     * or grouped), so full sets are complete. A row the re-check still drops (a defect, a race) is
     * refused as before: Remediation2CanonicalRowsTest::testAGenuineDisagreementIsStillRefused.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAFullSetThatLacksARowIsRefused(string $dsn): void
    {
        Fixture::alter(
            $dsn,
            ["INSERT INTO mailbox (username, name, domain, department, maildir) VALUES ('odd.row@example.org', 'Odd Row', 'example.com', 'Sprzedaż', 'example.org/o/d/d/odd.row')"],
            ["DELETE FROM mailbox WHERE username = 'odd.row@example.org'"],
        );
        // an ordinary list does not show the row - SQL left it out, nothing was dropped
        $book = self::book($dsn);
        $book->set_pagesize(200);
        $listed = $book->list_records();
        self::assertCount(self::BOOK_SIZE, $listed->records);
        self::assertSame(self::BOOK_SIZE, $listed->count, 'the total counts what is listed');
        self::assertStringNotContainsString('row_invalid', self::log());

        $sales = IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sprzedaż');
        foreach ([['export', null, self::BOOK_SIZE], ['export', $sales, 2], ['mailto', $sales, 2], ['group-expand', $sales, 2]] as [$action, $group, $size]) {
            self::newRequest();
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $book = self::book($dsn, [], self::JAN, $action, $notices);
            if ($group !== null) {
                $book->set_group($group);
                self::assertSame($size, $book->get_group($group) === null ? -1 : $book->count()->count, $action . ': the group count is what is delivered');
            }
            self::assertCount($size, self::fullSet($book)->records, $action . ': complete');
            self::assertSame([], $notices, $action);
            self::assertStringNotContainsString('set_incomplete', self::log(), $action);
        }
    }

    // ------------------------------------------------------------------ I-10: a cached entitlement is confirmed

    /**
     * The resolved user is cached in the session for galbridge_scope_ttl. A mailbox that is disabled
     * (or expired, or moved) in that time kept the directory until the cache ran out; now the first
     * statement of every request that reads the directory confirms the mailbox.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testADisabledMailboxLosesTheDirectoryWithItsNextRequest(string $dsn): void
    {
        $config = Env::config($dsn);
        $session = self::session($config, self::ANNA);
        self::assertSame('ok', $session['status'] ?? null);

        foreach ([
            'disabled' => ["UPDATE mailbox SET active = 0 WHERE username = '" . self::ANNA . "'", "UPDATE mailbox SET active = 1 WHERE username = '" . self::ANNA . "'"],
            'expired' => ["UPDATE mailbox SET expired = '2020-01-01 00:00:00' WHERE username = '" . self::ANNA . "'", "UPDATE mailbox SET expired = '9999-12-31 00:00:00' WHERE username = '" . self::ANNA . "'"],
            'moved to another domain' => ["UPDATE mailbox SET domain = 'example.org' WHERE username = '" . self::ANNA . "'", "UPDATE mailbox SET domain = 'example.com' WHERE username = '" . self::ANNA . "'"],
            'domain disabled' => ["UPDATE domain SET active = 0 WHERE domain = 'example.com'", "UPDATE domain SET active = 1 WHERE domain = 'example.com'"],
        ] as $what => [$apply, $revert]) {
            // a request with a WARM cache (well inside the TTL): the book is listed and works
            $_SESSION = ['galbridge' => $session];
            self::newRequest();
            $factory = self::warmFactory($config, self::ANNA, $notices);
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(AddressBook::class, $book, $what);
            $book->set_pagesize(200);
            self::assertCount(self::BOOK_SIZE, $book->list_records()->records, $what);
            self::assertSame($session, $_SESSION['galbridge'], 'answered from the cache');

            Fixture::alter($dsn, [$apply], [$revert]);
            // the NEXT request, still with the warm cache
            $_SESSION = ['galbridge' => $session];
            self::newRequest();
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $factory = self::warmFactory($config, self::ANNA, $notices);
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(AddressBook::class, $book, 'the cache still lists the book ...');
            $book->set_pagesize(200);
            self::assertSame([], $book->list_records()->records, $what . ': ... but nothing is read through it');
            self::assertSame(0, $book->search('*', 'jan')->count, $what);
            self::assertSame(0, $book->count()->count, $what);
            self::assertNull($book->get_record(IdCodec::contactId(IdCodec::PERSON, self::JAN), true), $what);
            self::assertSame([], $book->list_groups(), $what);
            self::assertSame([['unavailable', []]], $notices, $what);
            // the entry is overwritten with the new decision (a key that is merely unset would come
            // back: Roundcube merges the stored session with the one of the request)
            self::assertSame('no_mailbox', $_SESSION['galbridge']['status'] ?? null, $what);
            self::assertNull($_SESSION['galbridge']['viewer'] ?? null, $what . ': no Viewer is left in the session');
            self::assertSame('no_mailbox', $factory->resolver()->status(), $what);
            self::assertStringContainsString('viewer_revoked', self::log(), $what);

            // and from then on there is no book at all - also when the session handler restores
            // every key the request did not overwrite (what Roundcube's rcube_session does)
            $stored = $_SESSION;
            foreach ([$stored, $stored + ['galbridge' => $session]] as $next) {
                $_SESSION = $next;
                self::newRequest();
                $connects = 0;
                $factory = self::warmFactory($config, self::ANNA, $notices, $connects);
                self::assertSame([], $factory->books(), $what);
                self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN), $what);
                self::assertSame('no_mailbox', $factory->resolver()->status(), $what);
                self::assertSame(0, $connects, $what . ': the decision is cached like every other');
            }

            Fixture::restore();
        }
        self::assertStringNotContainsString('db_error', self::log());
    }

    /**
     * The confirmation costs one statement per request - and nothing for a request that only
     * instantiates the book (the sender-photo lookup of every opened message) or that resolved the
     * user itself.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testTheConfirmationRunsOncePerReadingRequestOnly(string $dsn): void
    {
        $config = Env::config($dsn);
        $session = self::session($config, self::JAN);

        // warm cache, book instantiated, sender-photo search: no connection at all
        $_SESSION = ['galbridge' => $session];
        $connects = 0;
        $factory = self::warmFactory($config, self::JAN, $notices, $connects);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertSame(0, $book->search(['email'], self::JAN, 1, true, true, 'photo')->count);
        self::assertSame(0, $connects, 'nothing was read: nothing to confirm');

        // a resolver that confirms: true for a mailbox that is still there, and only one statement
        $resolver = $factory->resolver();
        self::assertTrue($resolver->confirm());
        self::assertTrue($resolver->confirm());
        self::assertSame($session, $_SESSION['galbridge'], 'a confirmed entry is not rewritten (its age keeps counting)');

        // a Viewer resolved in THIS request needs no confirmation
        $_SESSION = [];
        $fresh = Env::factory($config, self::JAN);
        self::assertNotNull($fresh->resolver()->resolve());
        Fixture::alter($dsn, ["UPDATE mailbox SET active = 0 WHERE username = '" . self::JAN . "'"], ["UPDATE mailbox SET active = 1 WHERE username = '" . self::JAN . "'"]);
        self::assertTrue($fresh->resolver()->confirm(), 'decided by the lookup of this request');

        // a resolver without a Viewer confirms nothing
        $_SESSION = [];
        $none = Env::factory($config, 'nobody@example.com');
        self::assertNull($none->resolver()->resolve());
        self::assertFalse($none->resolver()->confirm());
    }

    // ------------------------------------------------------------------ I-12: expiry of alias accounts and lists

    /**
     * With galbridge_hide_expired, an alias account or list whose "expired" column the role cannot
     * read was listed anyway (only the domain was checked) - also when it had expired. Without the
     * column the kind is left out.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testExpiredListKindsAreLeftOutWhenTheirExpiryIsNotReadable(string $dsn): void
    {
        $kinds = static fn (AddressBook $book): array => array_count_values(array_map(static fn (array $r): string => (string) $r['ID'][0], self::all($book)));

        $book = self::bookWithoutColumns($dsn, [], []);
        self::assertSame(['u' => 7, 'a' => 5, 'l' => 1], $kinds($book), 'the precondition');

        $book = self::bookWithoutColumns($dsn, [], ['alias' => ['expired']]);
        self::assertSame(['u' => 7, 'l' => 1], $kinds($book), 'alias accounts are left out');
        self::assertNull($book->get_record(IdCodec::contactId(IdCodec::ALIAS, 'team@example.com'), true));
        self::assertSame([], self::emails($book->search('email', 'team@example.com', 1)));

        $book = self::bookWithoutColumns($dsn, [], ['maillists' => ['expired']]);
        self::assertSame(['u' => 7, 'a' => 5], $kinds($book), 'mailing lists are left out');

        // the expired alias account of the fixture is hidden while the column is readable ...
        $book = self::bookWithoutColumns($dsn, [], []);
        self::assertNull($book->get_record(IdCodec::contactId(IdCodec::ALIAS, 'expiredalias@example.com'), true));
        // ... and the option that makes the column unnecessary shows it on purpose
        $book = self::bookWithoutColumns($dsn, ['galbridge_hide_expired' => false, 'galbridge_list_policy' => 'all'], ['alias' => ['expired'], 'maillists' => ['expired']]);
        self::assertNotNull($book->get_record(IdCodec::contactId(IdCodec::ALIAS, 'expiredalias@example.com'), true));
        self::assertStringNotContainsString('db_error', self::log());
    }

    // ------------------------------------------------------------------ I-13: bounded alias sets

    /**
     * The viewer's own aliases go into the session and into the value lists of every list query;
     * the aliases shown for the persons of a page were fetched without any limit.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAliasSetsAreBounded(string $dsn): void
    {
        $max = ViewerResolver::MAX_ALIASES;
        $rows = [];
        for ($i = 1; $i <= $max + 1; $i++) {
            $rows[] = sprintf("('anna.a%03d@example.com', '%s', 'example.com', 'example.com', 1, 1)", $i, self::ANNA);
        }
        Fixture::alter(
            $dsn,
            ['INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES ' . implode(', ', $rows)],
            ["DELETE FROM forwardings WHERE address LIKE 'anna.a___@example.com'"],
        );

        // one alias too many: the list policies are evaluated with the login alone (fewer restricted
        // lists, never more), and the administrator is told
        $factory = Env::factory(Env::config($dsn), self::ANNA);
        $viewer = $factory->resolver()->resolve();
        self::assertNotNull($viewer);
        self::assertSame([self::ANNA], $viewer->addresses);
        self::assertStringContainsString('too_many_aliases', self::log());
        self::assertInstanceOf(AddressBook::class, $factory->create(BookDefinition::DOMAIN), 'the directory itself stays usable');

        // exactly the limit: all of them count
        Fixture::alter($dsn, [sprintf("DELETE FROM forwardings WHERE address = 'anna.a%03d@example.com'", $max + 1)], []);
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $viewer = Env::factory(Env::config($dsn), self::ANNA)->resolver()->resolve();
        self::assertNotNull($viewer);
        self::assertCount($max + 1, $viewer->addresses, 'the login and ' . $max . ' aliases');
        self::assertStringNotContainsString('too_many_aliases', self::log());

        // shown next to a person: a fixed number per person, whoever looks
        $book = self::book($dsn, ['galbridge_user_aliases' => true], self::JAN);
        $anna = self::other($book, self::ANNA);
        self::assertCount(Repository::ALIASES_PER_PERSON, $anna);
        self::assertSame('anna.a001@example.com', $anna[0], 'the first ones in address order');
        self::assertSame('anna.a020@example.com', $anna[Repository::ALIASES_PER_PERSON - 1]);
        self::assertStringContainsString('aliases_capped', self::log());
        // ... and the other persons of the same page still get theirs: the statement is cut by
        // anna's rows (she sorts first), the others are asked for again
        $page = [];
        foreach (self::all($book) as $r) {
            $page[(string) ($r['email:work'][0] ?? '')] = $r['email:other'] ?? [];
        }
        self::assertCount(Repository::ALIASES_PER_PERSON, $page[self::ANNA]);
        $jan = $page[self::JAN];
        sort($jan);
        self::assertSame(['jan@example.net', 'jk@example.com', 'sprzedaz@example.com'], $jan);
        self::assertStringNotContainsString('aliases_skipped', self::log());
        // matching is not affected by what is shown
        self::assertSame([self::ANNA], self::emails($book->search('email', sprintf('anna.a%03d@example.com', $max), 1)));
        self::assertStringNotContainsString('db_error', self::log());
    }

    // ------------------------------------------------------------------ I-14: edge values

    /**
     * A viewer without any address (only possible through a damaged session entry) used to raise a
     * placeholder mismatch inside the list predicates. The predicates are well-formed for it, and
     * such a session entry is not accepted in the first place.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testAViewerWithoutAddressesMatchesNoRestrictedList(string $dsn): void
    {
        $connection = new Connection(new Dsn($dsn, Env::config($dsn)), 3);
        $columns = $connection->visibleColumns();
        $nobody = new Viewer(self::JAN, 'example.com', [], $columns);
        foreach ([AccessPolicy::STRICT, AccessPolicy::PUBLIC_ONLY, AccessPolicy::ALL] as $mode) {
            foreach (['alias' => 'a', 'mailingList' => 'ml'] as $method => $alias) {
                $predicate = (new AccessPolicy($mode, $nobody))->{$method}();
                self::assertSame(substr_count($predicate->sql, '?'), count($predicate->params), "{$mode} {$method}");
                $table = $alias === 'a' ? 'alias' : 'maillists';
                $rows = $connection->select("SELECT {$alias}.address FROM `{$table}` {$alias} WHERE " . $predicate->sql, $predicate->params);
                $addresses = array_column($rows, 'address');
                if ($mode === AccessPolicy::STRICT) {
                    // public and domain-wide entries only: nothing that needs a member, moderator or owner
                    self::assertNotContains('board@example.com', $addresses);
                    self::assertNotContains('staff@example.com', $addresses);
                    self::assertNotContains('mods@example.com', $addresses);
                }
            }
        }
        $connection->close();

        // a session entry whose address set does not hold the login is not ours: resolved again
        $config = Env::config($dsn);
        $session = self::session($config, self::JAN);
        foreach ([[], ['somebody.else@example.com'], 'not a list'] as $addresses) {
            $_SESSION = ['galbridge' => $session];
            $_SESSION['galbridge']['viewer']['addresses'] = $addresses;
            $connects = 0;
            $factory = self::warmFactory($config, self::JAN, $notices, $connects);
            $viewer = $factory->resolver()->resolve();
            self::assertNotNull($viewer);
            self::assertContains(self::JAN, $viewer->addresses);
            self::assertGreaterThan(0, $connects, 'looked up again instead of trusting the entry');
        }
        self::assertStringNotContainsString('unexpected error', self::log());
    }

    // ------------------------------------------------------------------ internationalised domains (with ext-intl)

    /**
     * A domain outside ASCII may be written in an allow- or deny-list in any of its spellings - the
     * U-label, upper case, a decomposed (NFD) form or the A-label. Every spelling means the same
     * domain; above all, a deny-list entry is never ignored because of its spelling.
     * (Without ext-intl a U-label cannot be converted and the list is refused as a whole:
     * NormalizerWithoutIntlTest.)
     */
    #[RequiresPhpExtension('intl')]
    #[DataProviderExternal(Env::class, 'engines')]
    public function testInternationalDomainsMeanTheSameInEverySpelling(string $dsn): void
    {
        $klaus = 'klaus@xn--bcher-kva.example';
        $spellings = ['xn--bcher-kva.example', 'bücher.example', 'BÜCHER.Example', "bu\u{0308}cher.example", ' Bücher.example. '];
        $global = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_searchonly' => false];

        foreach ($spellings as $spelling) {
            $label = 'spelling ' . json_encode($spelling);

            // allow-list of the global book
            $book = self::book($dsn, $global + ['galbridge_global_data_domains' => [$spelling]], self::JAN, '', $notices, BookDefinition::GLOBAL);
            self::assertSame([$klaus], self::emails(self::page($book)), $label . ' in galbridge_global_data_domains');

            // deny-list of the global book: the domain is taken out, whatever the allow-list says
            foreach ([['example.org', 'xn--bcher-kva.example'], ['*']] as $allow) {
                $options = $global + ['galbridge_global_data_domains' => $allow, 'galbridge_global_data_domains_exclude' => [$spelling], 'galbridge_global_allow_wildcard' => true];
                $book = self::book($dsn, $options, self::JAN, '', $notices, BookDefinition::GLOBAL);
                $emails = self::emails(self::page($book));
                self::assertNotContains($klaus, $emails, $label . ' in galbridge_global_data_domains_exclude');
                self::assertContains('bob@example.org', $emails, $label);
                self::assertSame([], self::emails($book->search('email', $klaus, 1)), $label);
            }

            // deny-list of viewers: the users of that domain get no book, everybody else keeps theirs
            foreach (['galbridge_domain_viewer_domains_exclude', 'galbridge_global_viewer_domains_exclude'] as $option) {
                $config = Env::config($dsn, $global + ['galbridge_global_data_domains' => ['example.org'], $option => [$spelling]]);
                $id = $option === 'galbridge_domain_viewer_domains_exclude' ? BookDefinition::DOMAIN : BookDefinition::GLOBAL;
                self::assertArrayNotHasKey($id, Env::factory($config, $klaus)->books(), $label . ' in ' . $option);
                self::assertArrayHasKey($id, Env::factory($config, self::JAN)->books(), $label . ' in ' . $option);
            }

            // an organization that names the domain
            $config = Env::config($dsn, ['galbridge_organizations' => ['Acme' => ['example.com', $spelling]]]);
            $book = Env::factory($config, self::JAN)->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(AddressBook::class, $book, $label);
            self::assertContains($klaus, self::emails(self::page($book)), $label . ' in galbridge_organizations');
        }
        self::assertStringNotContainsString('config_invalid', self::log());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A new request: the once-per-request "unavailable" notice may be shown again.
     */
    private static function newRequest(): void
    {
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    /**
     * @param array<string, mixed>                         $options
     * @param null|list<array{string, array<string, string>}> $notices
     */
    private static function book(string $dsn, array $options = [], string $login = self::JAN, string $action = '', ?array &$notices = null, string $id = BookDefinition::DOMAIN): AddressBook
    {
        $book = Env::factory(Env::config($dsn, $options), $login, $action, $notices)->create($id);
        self::assertInstanceOf(AddressBook::class, $book, self::log());
        return $book;
    }

    /**
     * The session cache entry of a login (as an earlier request left it).
     *
     * @return array<string, mixed>
     */
    private static function session(Config $config, string $login): array
    {
        self::assertNotNull(Env::factory($config, $login)->resolver()->resolve());
        $session = $_SESSION['galbridge'] ?? null;
        self::assertIsArray($session);
        return $session;
    }

    /**
     * A factory for a request that finds $_SESSION as it is (Env::factory() starts a new session).
     *
     * @param null|list<array{string, array<string, string>}> $notices
     */
    private static function warmFactory(Config $config, string $login, ?array &$notices = null, int &$connects = 0): Factory
    {
        $notices = [];
        $connection = null;
        // $connects counts connections that were really opened (the Connection object itself is lazy)
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3, static function (string $dsn) use (&$connects): \rcube_db {
                $connects++;
                return \rcube_db::factory($dsn, '', false);
            });
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        $notify = static function (string $label, array $vars) use (&$notices): void {
            $notices[] = [$label, $vars];
        };
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l, '', $notify);
    }

    /**
     * The domain book for a role that cannot read the given columns (what narrower grants give).
     *
     * @param array<string, mixed>        $options
     * @param array<string, list<string>> $hidden
     */
    private static function bookWithoutColumns(string $dsn, array $options, array $hidden): AddressBook
    {
        $config = Env::config($dsn, $options);
        $session = self::session($config, self::JAN);
        foreach ($hidden as $table => $columns) {
            $session['viewer']['columns'][$table] = array_values(array_diff($session['viewer']['columns'][$table] ?? [], $columns));
        }
        $_SESSION = ['galbridge' => $session];
        $book = self::warmFactory($config, self::JAN)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book, self::log());
        return $book;
    }

    /**
     * Core's full-set request: page 1 with a page size of 99999 (export, mailto, group-expand).
     */
    private static function fullSet(AddressBook $book): \rcube_result_set
    {
        $book->set_page(1);
        $book->set_pagesize(99999);
        return $book->list_records(null, 0, true);
    }

    private static function page(AddressBook $book): \rcube_result_set
    {
        $book->set_page(1);
        $book->set_pagesize(200);
        return $book->list_records();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function all(AddressBook $book): array
    {
        $book->set_page(1);
        $book->set_pagesize(200);
        return array_values($book->list_records()->records);
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function record(AddressBook $book, string $address): ?array
    {
        $record = $book->get_record(IdCodec::contactId(IdCodec::PERSON, $address), true);
        return is_array($record) ? $record : null;
    }

    /**
     * The per-user aliases shown for a person, sorted (their order in a record is the collation
     * order of the server).
     *
     * @return list<string>
     */
    private static function other(AddressBook $book, string $address): array
    {
        $other = self::record($book, $address)['email:other'] ?? [];
        self::assertIsArray($other);
        sort($other);
        return array_values($other);
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

    private static function log(): string
    {
        return implode("\n", $GLOBALS['galbridge_test_log'] ?? []);
    }

    /** the view name is what every statement of the plugin reads (kept in one assertion) */
    public function testThePluginReadsTheForwardingsViewOnly(): void
    {
        self::assertSame('galbridge_forwardings', Schema::FORWARDINGS);
        foreach (['Directory/QueryBuilder.php', 'Directory/AccessPolicy.php', 'Directory/ViewerResolver.php', 'Directory/Repository.php', 'AddressBook.php', 'Factory.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/GalBridge/' . $file);
            self::assertDoesNotMatchRegularExpression('/`forwardings`|FROM forwardings|JOIN forwardings/', $source, $file . ' names the forwardings table');
        }
    }
}
