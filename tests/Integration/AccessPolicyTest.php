<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * iRedAPD accesspolicy of alias accounts and mlmmj lists (docs/ARCHITECTURE.md 6): an alias or list
 * is shown only to viewers who may post to it. The expected sets are the ones documented in
 * tests/fixtures/vmail/seed.sql, for every galbridge_list_policy and on every engine. A hidden entry
 * is unreachable by ID and by search as well, not only absent from the listing.
 *
 * @license GPL-3.0-or-later
 */
final class AccessPolicyTest extends TestCase
{
    /**
     * viewer => [alias accounts, mailing lists] (local parts) in the domain book.
     *
     * jan: member of board, moderator of legacy; zofia: moderator of board (membersonly: members
     * only) and of the staff list; lukasz: member of board and managers, owner of mods; anna: member
     * of audit, moderator of managers; postmaster: member of internal/legacy/weird, owner of staff.
     */
    private const STRICT = [
        'jan.kowalski@example.com' => [['board', 'everyone', 'internal', 'legacy', 'team'], ['newsletter']],
        'zofia.zolkiewska@example.com' => [['everyone', 'internal', 'team'], ['newsletter', 'staff']],
        'lukasz.slowik@example.com' => [['board', 'everyone', 'internal', 'team'], ['mods', 'newsletter']],
        'anna.nowak@example.com' => [['audit', 'everyone', 'internal', 'managers', 'team'], ['newsletter']],
        'postmaster@example.com' => [['everyone', 'internal', 'team'], ['newsletter', 'staff']],
        'bob@example.org' => [['orginternal', 'orgteam'], ['orglist']],
    ];

    private const PUBLIC_ONLY = [
        'jan.kowalski@example.com' => [['everyone', 'team'], ['newsletter']],
        'zofia.zolkiewska@example.com' => [['everyone', 'team'], ['newsletter']],
        'lukasz.slowik@example.com' => [['everyone', 'team'], ['newsletter']],
        'bob@example.org' => [['orgteam'], ['orglist']],
    ];

    /** 'all': every active, deliverable, unexpired entry - never empty/oldalias/expiredalias/retired/broken */
    private const ALL = [
        'jan.kowalski@example.com' => [['audit', 'board', 'everyone', 'internal', 'legacy', 'managers', 'team', 'weird'], ['mods', 'newsletter', 'staff']],
        'zofia.zolkiewska@example.com' => [['audit', 'board', 'everyone', 'internal', 'legacy', 'managers', 'team', 'weird'], ['mods', 'newsletter', 'staff']],
        'lukasz.slowik@example.com' => [['audit', 'board', 'everyone', 'internal', 'legacy', 'managers', 'team', 'weird'], ['mods', 'newsletter', 'staff']],
        'bob@example.org' => [['orginternal', 'orgteam'], ['orglist']],
    ];

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
    }

    /**
     * @return array<string, array{string, string, string, list<string>, list<string>}>
     */
    public static function matrix(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach (['strict' => self::STRICT, 'public_only' => self::PUBLIC_ONLY, 'all' => self::ALL] as $policy => $table) {
                foreach ($table as $viewer => [$aliases, $lists]) {
                    $out["{$engine}: {$policy}: {$viewer}"] = [$dsn, $policy, $viewer, $aliases, $lists];
                }
            }
        }
        return $out;
    }

    /**
     * @param list<string> $aliases
     * @param list<string> $lists
     */
    #[DataProvider('matrix')]
    public function testListingShowsExactlyThePostableAliasesAndLists(string $dsn, string $policy, string $viewer, array $aliases, array $lists): void
    {
        $book = self::book($dsn, $viewer, ['galbridge_list_policy' => $policy]);
        $book->set_pagesize(200);
        $rs = $book->list_records();
        self::assertSame($aliases, self::localParts($rs, IdCodec::ALIAS), 'alias accounts');
        self::assertSame($lists, self::localParts($rs, IdCodec::MAILLIST), 'mailing lists');
        self::assertSame(count($rs->records), $book->count()->count, 'count() uses the same predicate');
    }

    /**
     * @param list<string> $aliases
     * @param list<string> $lists
     */
    #[DataProvider('matrix')]
    public function testHiddenEntriesAreUnreachableByIdAndSearch(string $dsn, string $policy, string $viewer, array $aliases, array $lists): void
    {
        $book = self::book($dsn, $viewer, ['galbridge_list_policy' => $policy]);
        $domain = substr($viewer, (int) strrpos($viewer, '@') + 1);
        $candidates = $domain === 'example.com'
            ? [IdCodec::ALIAS => ['team', 'everyone', 'board', 'managers', 'audit', 'internal', 'legacy', 'weird', 'empty', 'oldalias', 'expiredalias'],
                IdCodec::MAILLIST => ['newsletter', 'staff', 'mods', 'retired', 'broken']]
            : [IdCodec::ALIAS => ['orgteam', 'orginternal'], IdCodec::MAILLIST => ['orglist']];

        foreach ($candidates as $kind => $locals) {
            $visible = $kind === IdCodec::ALIAS ? $aliases : $lists;
            foreach ($locals as $local) {
                $address = $local . '@' . $domain;
                $id = IdCodec::contactId($kind, $address);
                $record = $book->get_record($id, true);
                $found = self::emails($book->search(['email'], $address, \rcube_addressbook::SEARCH_STRICT));
                $byIds = self::emails($book->search('ID', $id));
                if (in_array($local, $visible, true)) {
                    self::assertIsArray($record, $address);
                    self::assertSame($id, $record['ID']);
                    self::assertSame([$address], $found, $address);
                    self::assertSame([$address], $byIds, $address);
                } else {
                    self::assertNull($record, $address);
                    self::assertSame([], $found, $address);
                    self::assertSame([], $byIds, $address);
                }
            }
        }
    }

    /**
     * @return array<string, array{string, string, string, list<string>, list<string>}>
     */
    public static function crossTenantMatrix(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            // 'domain' compares with the VIEWER's home domain; *@H moderators match the viewer's domain
            $out["{$engine}: jan reads example.org"] = [$dsn, 'jan.kowalski@example.com', 'example.org', ['orgteam'], ['orglist']];
            $out["{$engine}: bob reads example.com"] = [$dsn, 'bob@example.org', 'example.com', ['audit', 'everyone', 'team'], ['newsletter']];
        }
        return $out;
    }

    /**
     * @param list<string> $aliases
     * @param list<string> $lists
     */
    #[DataProvider('crossTenantMatrix')]
    public function testGlobalBookAppliesThePolicyOfTheViewer(string $dsn, string $viewer, string $data, array $aliases, array $lists): void
    {
        $factory = Env::factory(Env::config($dsn, [
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => [$data],
            'galbridge_global_searchonly' => false,
        ]), $viewer);
        $book = $factory->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        $rs = $book->list_records();
        self::assertSame($aliases, self::localParts($rs, IdCodec::ALIAS));
        self::assertSame($lists, self::localParts($rs, IdCodec::MAILLIST));
        foreach ($rs->records as $r) {
            self::assertStringEndsWith('@' . $data, $r['email:work'][0]);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    #[DataProvider('engines')]
    public function testUnknownListPolicyValueFallsBackToStrict(string $dsn): void
    {
        foreach (['everything', 'ALL;', ['all'], 1] as $value) {
            $book = self::book($dsn, 'jan.kowalski@example.com', ['galbridge_list_policy' => $value]);
            $book->set_pagesize(200);
            $rs = $book->list_records();
            self::assertSame(self::STRICT['jan.kowalski@example.com'][0], self::localParts($rs, IdCodec::ALIAS), json_encode($value) ?: '');
            self::assertSame(self::STRICT['jan.kowalski@example.com'][1], self::localParts($rs, IdCodec::MAILLIST));
        }
    }

    #[DataProvider('engines')]
    public function testAliasesAndListsCanBeSwitchedOff(string $dsn): void
    {
        $book = self::book($dsn, 'jan.kowalski@example.com', ['galbridge_include_aliases' => false, 'galbridge_list_policy' => 'all']);
        $book->set_pagesize(200);
        $rs = $book->list_records();
        self::assertSame([], self::localParts($rs, IdCodec::ALIAS));
        self::assertSame(['mods', 'newsletter', 'staff'], self::localParts($rs, IdCodec::MAILLIST));
        self::assertNull($book->get_record('a_teamAexampleDcom'));
        self::assertSame([], self::emails($book->search('*', 'team')));

        $book = self::book($dsn, 'jan.kowalski@example.com', ['galbridge_include_mailing_lists' => false]);
        $book->set_pagesize(200);
        $rs = $book->list_records();
        self::assertSame(self::STRICT['jan.kowalski@example.com'][0], self::localParts($rs, IdCodec::ALIAS));
        self::assertSame([], self::localParts($rs, IdCodec::MAILLIST));
        self::assertNull($book->get_record('l_newsletterAexampleDcom'));
    }

    /**
     * Aliases and lists are individual records: never members of a group (an expansion would send to
     * a list and to its members twice), and never visible through a group of another book.
     */
    #[DataProvider('engines')]
    public function testGroupsContainPersonsOnly(string $dsn): void
    {
        $book = self::book($dsn, 'jan.kowalski@example.com', ['galbridge_list_policy' => 'all', 'galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]]);
        self::assertNotSame([], $book->list_groups());
        foreach ($book->list_groups() as $g) {
            $book->set_group($g['ID']);
            $book->set_pagesize(9999);
            foreach ($book->list_records()->records as $r) {
                self::assertStringStartsWith('u_', $r['ID'], $g['ID']);
            }
        }
    }

    /**
     * get_record_groups() feeds the "Groups" of a contact's detail view; it must agree with the group
     * listing above, in which alias accounts and lists are never members.
     */
    #[DataProvider('engines')]
    public function testAliasesAndListsReportNoGroupMembership(string $dsn): void
    {
        $book = self::book($dsn, 'jan.kowalski@example.com', ['galbridge_organizations' => ['Acme' => ['example.com', 'example.org']]]);
        self::assertSame(['dd_exampleDcom' => 'example.com'], $book->get_record_groups('u_janDkowalskiAexampleDcom'), 'sanity: persons are members');
        foreach (['a_teamAexampleDcom', 'a_everyoneAexampleDcom', 'l_newsletterAexampleDcom', 'a_orgteamAexampleDorg', 'l_orglistAexampleDorg'] as $id) {
            self::assertNotNull($book->get_record($id), $id);
            self::assertSame([], $book->get_record_groups($id), $id);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $options
     */
    private static function book(string $dsn, string $viewer, array $options = []): AddressBook
    {
        $book = Env::factory(Env::config($dsn, $options), $viewer)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        return $book;
    }

    /**
     * Sorted local parts of the records of one kind.
     *
     * @return list<string>
     */
    private static function localParts(\rcube_result_set $rs, string $kind): array
    {
        $out = [];
        foreach ($rs->records as $r) {
            $decoded = IdCodec::decodeContact($r['ID']);
            self::assertNotNull($decoded, (string) $r['ID']);
            self::assertSame($decoded['address'], $r['email:work'][0] ?? null);
            if ($decoded['kind'] === $kind) {
                $out[] = substr($decoded['address'], 0, (int) strrpos($decoded['address'], '@'));
            }
        }
        sort($out);
        return $out;
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
