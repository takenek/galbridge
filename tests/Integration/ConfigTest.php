<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Configuration guard and normalisation (docs/ARCHITECTURE.md 3, 7, 13): stored user preferences
 * named galbridge_* never change an administrator option, values are clamped, the IMAP-host binding
 * decides whether the plugin may map a login to a tenant at all, and the field allowlist intersected
 * with the readable columns is the only source of what a record, coltypes and a search can touch.
 *
 * @license GPL-3.0-or-later
 */
final class ConfigTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

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

    // ------------------------------------------------------------------ user preference guard

    public function testUserPreferencesNeverOverrideAdministratorOptions(): void
    {
        $admin = [
            'galbridge_db_dsn' => 'pgsql://galbridge:admin-pw@127.0.0.1/vmail',
            'galbridge_global_book' => false,
            'galbridge_hidden_addresses' => ['postmaster@example.com'],
            'galbridge_imap_hosts' => ['127.0.0.1'],
            'galbridge_list_policy' => 'strict',
            'galbridge_fields' => ['name', 'email'],
            'galbridge_max_results' => 50,
            'galbridge_debug' => false,
            'imap_host' => 'tls://127.0.0.1:143',
        ];
        $prefs = [
            'galbridge_db_dsn' => 'pgsql://vmail:stolen@evil.example/vmail',
            'galbridge_global_book' => true,
            'galbridge_hidden_addresses' => [],
            'galbridge_imap_hosts' => ['evil.example'],
            'galbridge_list_policy' => 'all',
            'galbridge_fields' => Config::FIELDS,
            'galbridge_max_results' => 5000,
            'galbridge_debug' => true,
            'imap_host' => 'ssl://evil.example:993',
        ];
        $config = self::merged($admin, $prefs);

        self::assertSame($admin['galbridge_db_dsn'], $config->string('galbridge_db_dsn'));
        self::assertFalse($config->bool('galbridge_global_book'));
        self::assertSame(['postmaster@example.com'], $config->hiddenAddresses());
        self::assertSame(['127.0.0.1'], $config->imapHosts());
        self::assertSame('strict', $config->choice('galbridge_list_policy', Config::LIST_POLICIES));
        self::assertSame(['name', 'email'], $config->fields());
        self::assertSame(50, $config->int('galbridge_max_results', 10, 5000));
        self::assertFalse($config->bool('galbridge_debug'));
        self::assertSame('tls://127.0.0.1:143', $config->adminCore('imap_host'));
        self::assertSame(['127.0.0.1'], ViewerResolver::allowedImapHosts($config));
    }

    public function testPreferenceForAnOptionTheAdministratorDidNotSetFallsBackToTheDefault(): void
    {
        $prefs = [
            'galbridge_global_book' => true,
            'galbridge_global_data_domains' => ['*'],
            'galbridge_global_allow_wildcard' => true,
            'galbridge_list_policy' => 'all',
            'galbridge_domain_book' => false, // a user cannot switch a book off for the admin either
        ];
        $config = self::merged(['galbridge_db_dsn' => 'x'], $prefs);
        foreach (['galbridge_global_book', 'galbridge_global_data_domains', 'galbridge_global_allow_wildcard', 'galbridge_list_policy', 'galbridge_domain_book'] as $name) {
            self::assertSame(Config::DEFAULTS[$name], $config->get($name), $name);
        }
    }

    public function testPreferencesDoNotChangeTheFingerprint(): void
    {
        $admin = ['galbridge_db_dsn' => 'x', 'imap_host' => 'tls://127.0.0.1:143', 'galbridge_hidden_addresses' => ['postmaster@example.com']];
        $clean = self::merged($admin, []);
        $tampered = self::merged($admin, ['galbridge_hidden_addresses' => [], 'galbridge_global_book' => true, 'imap_host' => 'evil.example']);
        self::assertSame($clean->fingerprint(), $tampered->fingerprint());
        // an administrator change does change it (it binds the Viewer cache and stored searches)
        self::assertNotSame($clean->fingerprint(), self::merged(['galbridge_hidden_addresses' => []] + $admin, [])->fingerprint());
        self::assertNotSame($clean->fingerprint(), self::merged(['imap_host' => 'tls://127.0.0.2:143'] + $admin, [])->fingerprint());
    }

    /**
     * Without explicit administrator values the guard reads a FRESH rcube_config (defaults.inc.php,
     * config.inc.php and the plugin's own config file) - never the merged one.
     */
    public function testGuardReadsAFreshConfigurationWhenAdminValuesAreNotGiven(): void
    {
        if (is_file(RCUBE_PLUGINS_DIR . 'galbridge/config.inc.php') || is_file(RCUBE_CONFIG_DIR . 'galbridge.inc.php')) {
            self::markTestSkipped('the Roundcube tree has its own galbridge configuration');
        }
        $prefs = ['galbridge_global_book' => true, 'galbridge_imap_hosts' => ['evil.example'], 'galbridge_debug' => true];
        $rc = new \rcube_config();
        foreach ($prefs as $k => $v) {
            $rc->set($k, $v);
        }
        $config = new Config($rc, $prefs);
        self::assertFalse($config->bool('galbridge_global_book'));
        self::assertSame([], $config->imapHosts());
        self::assertFalse($config->bool('galbridge_debug'));
        self::assertSame((new \rcube_config())->get('imap_host'), $config->adminCore('imap_host'));
    }

    /**
     * End to end: preferences that try to enable a wildcard global book, show policy-restricted lists,
     * unhide an address and add fields change nothing the user sees.
     */
    #[DataProvider('engines')]
    public function testInjectedPreferencesCannotWidenTheDirectory(string $dsn): void
    {
        $admin = ['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143', 'galbridge_hidden_addresses' => ['postmaster@example.com']];
        $prefs = [
            'galbridge_global_book' => true,
            'galbridge_global_viewer_domains' => ['*'],
            'galbridge_global_data_domains' => ['*'],
            'galbridge_global_allow_wildcard' => true,
            'galbridge_global_include_home' => true,
            'galbridge_global_searchonly' => false,
            'galbridge_hidden_addresses' => [],
            'galbridge_list_policy' => 'all',
            'galbridge_fields' => Config::FIELDS,
            'galbridge_hide_expired' => false,
        ];
        $factory = Env::factory(self::merged($admin, $prefs), self::JAN);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::GLOBAL));

        $book = $factory->create(BookDefinition::DOMAIN);
        $book->set_pagesize(200);
        $emails = self::emails($book->list_records());
        self::assertNotContains('postmaster@example.com', $emails);
        self::assertNotContains('weird@example.com', $emails, 'list policy stays strict');
        self::assertNotContains('expired.user@example.com', $emails, 'hide_expired stays on');
        self::assertSame([], array_filter($emails, static fn (string $e): bool => !str_ends_with($e, '@example.com')));
        $jan = $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertIsArray($jan);
        self::assertArrayNotHasKey('phone:work', $jan);
        self::assertArrayNotHasKey('birthday', $jan);
    }

    // ------------------------------------------------------------------ clamping

    /**
     * @return array<string, array{string, mixed, int, int, int}>
     */
    public static function clampedIntegers(): array
    {
        return [
            'ttl below minimum' => ['galbridge_scope_ttl', 0, 30, 3600, 30],
            'ttl above maximum' => ['galbridge_scope_ttl', 999999, 30, 3600, 3600],
            'min length zero' => ['galbridge_min_search_length', 0, 1, 10, 1],
            'min length garbage -> default' => ['galbridge_min_search_length', 'abc', 1, 10, 2],
            'min length array -> default' => ['galbridge_min_search_length', [5], 1, 10, 2],
            'max results negative' => ['galbridge_max_results', -1, 10, 5000, 10],
            'numeric string' => ['galbridge_max_ids', '150', 1, 1000, 150],
        ];
    }

    #[DataProvider('clampedIntegers')]
    public function testIntegerOptionsAreClamped(string $name, mixed $value, int $min, int $max, int $expected): void
    {
        self::assertSame($expected, self::merged([$name => $value], [])->int($name, $min, $max));
    }

    public function testInvalidChoicesFallBackToTheSafeDefault(): void
    {
        $config = self::merged(['galbridge_list_policy' => 'everything', 'galbridge_domain_groups' => 'tenant'], []);
        self::assertSame('strict', $config->choice('galbridge_list_policy', Config::LIST_POLICIES));
        self::assertSame('auto', $config->choice('galbridge_domain_groups', Config::GROUPINGS));
        $options = array_column($config->problems(), 'option');
        self::assertContains('galbridge_list_policy', $options);
        self::assertContains('galbridge_domain_groups', $options);
        // case and blanks are tolerated, nothing else
        self::assertSame('all', self::merged(['galbridge_list_policy' => ' ALL '], [])->choice('galbridge_list_policy', Config::LIST_POLICIES));
    }

    public function testFieldAllowlistAlwaysHasNameAndEmailAndDropsUnknownFields(): void
    {
        $config = self::merged(['galbridge_fields' => ['password', 'Department', 'recovery_email', 'allow_nets', 'settings', ' phone ', 42]], []);
        self::assertSame(['name', 'email', 'department', 'phone'], $config->fields());
        self::assertNotSame([], $config->problems());
        self::assertSame(['name', 'email'], self::merged(['galbridge_fields' => 'department'], [])->fields(), 'a non-list value grants nothing extra');
    }

    // ------------------------------------------------------------------ IMAP-host binding

    /**
     * @return array<string, array{mixed, null|list<string>}>
     */
    public static function imapHosts(): array
    {
        return [
            'tls URI with port' => ['tls://127.0.0.1:143', ['127.0.0.1']],
            'ssl URI, upper case' => ['ssl://MAIL.Example.COM:993', ['mail.example.com']],
            'host:port' => ['localhost:143', ['localhost']],
            'bare host' => ['imap.example.com', ['imap.example.com']],
            'IDN host' => ['imap.bücher.example', ['imap.xn--bcher-kva.example']],
            'IPv6 literal' => ['tls://[::1]:143', ['::1']],
            'template %d' => ['ssl://imap.%d', null],
            'template %n' => ['imap.%n', null],
            'template %h' => ['%h', null],
            'template %s' => ['tls://%s:143', null],
            'user-typed (empty)' => ['', null],
            'blank' => ['   ', null],
            'list (user picks a server)' => [['imap.example.com'], null],
            'labelled list' => [['imap.example.com' => 'Example'], null],
            'null' => [null, null],
            'integer' => [143, null],
        ];
    }

    /**
     * @param null|list<string> $expected
     */
    #[DataProvider('imapHosts')]
    public function testAllowedImapHostIsDerivedOnlyFromAFixedAdministratorHost(mixed $imapHost, ?array $expected): void
    {
        self::assertSame($expected, ViewerResolver::allowedImapHosts(self::merged(['imap_host' => $imapHost], [])));
    }

    public function testLegacyDefaultHostIsHonouredOnlyWithoutImapHost(): void
    {
        self::assertSame(['mail.example.com'], ViewerResolver::allowedImapHosts(self::merged(['default_host' => 'tls://mail.example.com:143'], [])));
        self::assertNull(ViewerResolver::allowedImapHosts(self::merged(['default_host' => 'ssl://imap.%d'], [])));
        self::assertNull(ViewerResolver::allowedImapHosts(self::merged([], [])), 'no administrator host at all');
    }

    public function testExplicitImapHostsAreUsedEvenWithATemplatedImapHost(): void
    {
        $config = self::merged(['imap_host' => 'ssl://imap.%d', 'galbridge_imap_hosts' => ['mail.example.com', '127.0.0.1', '[::1]', 'IMAP.Example.ORG.', 'imap.bücher.example']], []);
        self::assertSame(['mail.example.com', '127.0.0.1', '::1', 'imap.example.org', 'imap.xn--bcher-kva.example'], ViewerResolver::allowedImapHosts($config));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidExplicitImapHosts(): array
    {
        return [
            'string instead of list' => ['127.0.0.1'],
            'scheme' => [['ssl://mail.example.com']],
            'port' => [['mail.example.com:993']],
            'template' => [['imap.%d']],
            'wildcard' => [['*']],
            'wildcard mixed' => [['127.0.0.1', '*']],
            'empty entry' => [['127.0.0.1', '']],
            'integer entry' => [[127001]],
            'whitespace in name' => [['mail example.com']],
            'path' => [['mail.example.com/x']],
            'nested list' => [[['127.0.0.1']]],
        ];
    }

    /**
     * One invalid entry disables the plugin (fail closed): it must not fall back to imap_host.
     */
    #[DataProvider('invalidExplicitImapHosts')]
    public function testInvalidExplicitImapHostsDisableThePlugin(mixed $hosts): void
    {
        $config = self::merged(['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://127.0.0.1:143', 'galbridge_imap_hosts' => $hosts], []);
        self::assertNull(ViewerResolver::allowedImapHosts($config));

        $connects = 0;
        $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects);
        self::assertSame([], $factory->books());
        self::assertSame('disabled', $factory->resolver()->status());
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
        self::assertSame(0, $connects, 'a disabled plugin never connects');
        self::assertContains('fail', array_column($config->problems(), 'level'));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function enginesWithSelectableImapHosts(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out[$engine . ': template'] = [$dsn, 'ssl://imap.%d'];
            $out[$engine . ': user-typed'] = [$dsn, ''];
            $out[$engine . ': list'] = [$dsn, ['127.0.0.1', 'imap.example.org']];
        }
        return $out;
    }

    /**
     * A user who logs in through an IMAP server of their own choosing as ceo@tenant-a must not get
     * tenant A's directory: a templated or user-selectable imap_host without galbridge_imap_hosts
     * disables the plugin.
     */
    #[DataProvider('enginesWithSelectableImapHosts')]
    public function testUserSelectableImapHostWithoutExplicitListDisablesThePlugin(string $dsn, mixed $imapHost): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => $imapHost], []);
        $connects = 0;
        $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects);
        self::assertSame([], $factory->books());
        self::assertSame('disabled', $factory->resolver()->status());
        foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
            self::assertInstanceOf(DeniedBook::class, $factory->create($id));
        }
        self::assertSame(0, $connects);
        self::assertArrayNotHasKey('galbridge', $_SESSION);
        self::assertStringContainsString('disabled', implode("\n", $GLOBALS['galbridge_test_log']));

        // the same server with an explicit list works
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => $imapHost, 'galbridge_imap_hosts' => ['127.0.0.1']], []);
        $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1');
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function hostPairs(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out[$engine . ': both match'] = [$dsn, '127.0.0.1', '127.0.0.1', 'ok'];
            $out[$engine . ': stored host as URI'] = [$dsn, 'tls://127.0.0.1:143', '127.0.0.1', 'ok'];
            $out[$engine . ': session host differs'] = [$dsn, '127.0.0.1', 'imap.evil.example', 'host_mismatch'];
            $out[$engine . ': stored host differs'] = [$dsn, 'imap.evil.example', '127.0.0.1', 'host_mismatch'];
            $out[$engine . ': loopback alias is a different host'] = [$dsn, 'localhost', 'localhost', 'host_mismatch'];
            $out[$engine . ': empty session host'] = [$dsn, '127.0.0.1', '', 'host_mismatch'];
        }
        return $out;
    }

    #[DataProvider('hostPairs')]
    public function testSessionAndStoredImapHostMustBothMatch(string $dsn, string $mailHost, string $storageHost, string $status): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143'], []);
        $connects = 0;
        $factory = self::factory($config, self::JAN, $mailHost, $storageHost, $connects);
        $books = $factory->books();
        self::assertSame($status, $factory->resolver()->status());
        if ($status === 'ok') {
            self::assertSame([BookDefinition::DOMAIN], array_keys($books));
            self::assertInstanceOf(AddressBook::class, $factory->create(BookDefinition::DOMAIN));
        } else {
            self::assertSame([], $books);
            self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
            self::assertSame(0, $connects);
        }
    }

    #[DataProvider('engines')]
    public function testExplicitHostListAcceptsEveryListedHost(string $dsn): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'ssl://imap.%d', 'galbridge_imap_hosts' => ['127.0.0.1', 'mail.example.com']], []);
        foreach ([['127.0.0.1', '127.0.0.1'], ['mail.example.com', 'MAIL.example.com'], ['ssl://mail.example.com:993', 'mail.example.com']] as [$mailHost, $storageHost]) {
            $factory = self::factory($config, self::JAN, $mailHost, $storageHost);
            self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()), $mailHost . ' / ' . $storageHost);
        }
        $factory = self::factory($config, self::JAN, 'mail.example.org', 'mail.example.org');
        self::assertSame([], $factory->books());
        self::assertSame('host_mismatch', $factory->resolver()->status());
    }

    // ------------------------------------------------------------------ field allowlist

    #[DataProvider('engines')]
    public function testMinimalFieldAllowlistLimitsRecordsColtypesAndSearch(string $dsn): void
    {
        $book = Env::factory(Env::config($dsn, ['galbridge_fields' => ['name', 'email']]), self::JAN)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertSame(['name', 'email'], array_keys($book->coltypes));
        self::assertSame(['work'], $book->coltypes['email']['subtypes']);
        self::assertFalse($book->groups, 'department groups need the department field');

        $jan = $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertSame(['ID', 'name', 'email:work', '_type'], array_keys((array) $jan));
        // anna has no display name and first/last name are not allowed: the local part is shown
        self::assertSame('anna.nowak', $book->get_record('u_annaDnowakAexampleDcom', true)['name'] ?? null);

        // fields outside the allowlist are no search oracle
        self::assertSame(0, $book->search(['department'], ['Księgowość'])->count);
        self::assertSame(0, $book->search('*', 'Księgowość')->count);
        self::assertSame(0, $book->search(['surname'], 'Nowak')->count);
        self::assertSame(0, $book->search(['firstname', 'surname'], ['Anna', 'Nowak'])->count);
        self::assertSame(0, $book->search('*', '765')->count, 'telephone is not allowed');
        foreach ($book->search('*', 'Zofia')->records as $r) {
            self::assertArrayNotHasKey('department', $r);
        }
    }

    #[DataProvider('engines')]
    public function testOptionalFieldsAppearOnlyWhenAllowed(string $dsn): void
    {
        $book = Env::factory(Env::config($dsn, ['galbridge_fields' => ['name', 'email', 'phone', 'mobile', 'birthday', 'surname']]), self::JAN)->create(BookDefinition::DOMAIN);
        self::assertSame(['work', 'mobile'], $book->coltypes['phone']['subtypes'] ?? null);
        self::assertTrue($book->coltypes['birthday']['nosearch'] ?? false);
        self::assertArrayNotHasKey('firstname', $book->coltypes);
        self::assertArrayNotHasKey('department', $book->coltypes);

        $jan = (array) $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertSame(['+48 22 123 45 67'], $jan['phone:work'] ?? null);
        self::assertSame(['+48 600 100 200'], $jan['phone:mobile'] ?? null);
        self::assertSame('1980-05-17', $jan['birthday'] ?? null);
        self::assertSame('Kowalski', $jan['surname'] ?? null);
        self::assertArrayNotHasKey('firstname', $jan);
        self::assertArrayNotHasKey('department', $jan);

        $postmaster = (array) $book->get_record('u_postmasterAexampleDcom', true);
        self::assertArrayNotHasKey('birthday', $postmaster, '0001-01-01 means "no birthday"');
        self::assertArrayNotHasKey('phone:work', $postmaster, 'empty values are omitted, never null');

        // birthday is a DATE column: never matched (nosearch)
        self::assertSame(0, $book->search(['birthday'], ['1980'])->count);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search(['phone'], '123 45')));
    }

    /**
     * A role without the optional columns is simulated by a Viewer whose column map lacks them (the
     * real-role variant is in DsnPolicyTest for MariaDB/PostgreSQL).
     */
    #[DataProvider('engines')]
    public function testFieldsWhoseColumnsAreNotReadableAreDropped(string $dsn): void
    {
        $config = Env::config($dsn, ['galbridge_fields' => Config::FIELDS, 'galbridge_domain_groups' => 'department']);
        $factory = self::factoryWithColumns($config, self::JAN, ['mailbox' => ['first_name', 'last_name', 'department', 'telephone', 'mobile', 'birthday']]);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertSame(['name', 'email'], array_keys($book->coltypes));
        self::assertFalse($book->groups);
        self::assertSame([], $book->list_groups());

        $book->set_pagesize(200);
        self::assertSame(13, $book->list_records()->count);
        $jan = (array) $book->get_record('u_janDkowalskiAexampleDcom', true);
        self::assertSame(['ID', 'name', 'email:work', '_type'], array_keys($jan));
        self::assertSame('anna.nowak', $book->get_record('u_annaDnowakAexampleDcom', true)['name'] ?? null);
        self::assertSame(0, $book->search(['department'], ['Sprzedaż'])->count);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search('*', 'Kowalski')));

        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertSame(1, substr_count($log, 'field_unavailable'), 'one WARN per request: ' . $log);
        self::assertStringNotContainsString('db_error', $log);
    }

    #[DataProvider('engines')]
    public function testUnreadableRequiredColumnDisablesTheBook(string $dsn): void
    {
        $config = Env::config($dsn);
        $factory = self::factoryWithColumns($config, self::JAN, ['mailbox' => ['enabledeliver']]);
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
        self::assertStringContainsString('column_missing', implode("\n", $GLOBALS['galbridge_test_log']));

        // mailbox.expired is required only while expired accounts are hidden
        $factory = self::factoryWithColumns($config, self::JAN, ['mailbox' => ['expired']]);
        self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
        $factory = self::factoryWithColumns(Env::config($dsn, ['galbridge_hide_expired' => false]), self::JAN, ['mailbox' => ['expired'], 'domain' => ['expired']]);
        self::assertInstanceOf(AddressBook::class, $factory->create(BookDefinition::DOMAIN));
    }

    // ------------------------------------------------------------------ core options a user controls

    #[DataProvider('engines')]
    public function testUserControlledCoreOptionsAreClampedOrWhitelisted(string $dsn): void
    {
        $book = Env::factory(Env::config($dsn), self::JAN)->create(BookDefinition::DOMAIN);
        // addressbook_sort_col comes from the user's preferences; core filters it through coltypes in
        // set_sort_order(), the book must not rely on that
        $book->sort_col = 'password; DROP TABLE mailbox';
        $book->sort_order = 'DESC; --';
        $book->set_pagesize(200);
        $hostile = $book->list_records();
        self::assertSame(13, $hostile->count);
        $book->set_sort_order('department', 'desc');
        $byDepartment = $book->list_records();
        self::assertSame(13, $byDepartment->count);

        // addressbook_pagesize: a huge page size on page 2 is capped to core max_pagesize
        $book->set_pagesize(1000000);
        $book->set_page(2);
        $rs = $book->list_records();
        self::assertSame([], $rs->records);
        self::assertSame(13, $rs->count);
        self::assertLessThanOrEqual(200, $book->page_size);

        // addressbook_search_mode outside 0..2
        $book->set_page(1);
        $book->set_pagesize(50);
        self::assertSame(['jan.kowalski@example.com'], self::emails($book->search('*', 'Kowalski', 0x7F)));
        self::assertStringNotContainsString('db_error', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Config as Roundcube builds it: the administrator values merged with the user's preferences
     * (preferences win in rcube_config, the guard must undo that).
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $prefs
     */
    private static function merged(array $admin, array $prefs): Config
    {
        \rcube::get_instance();
        $rc = new \rcube_config();
        foreach (array_merge($admin, $prefs) as $k => $v) {
            $rc->set($k, $v);
        }
        return new Config($rc, $prefs, $admin);
    }

    private static function factory(Config $config, string $login, string $mailHost, string $storageHost, int &$connects = 0): Factory
    {
        $_SESSION = [];
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            $connects++;
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, $mailHost, $storageHost);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
    }

    /**
     * A factory whose (session-cached) Viewer cannot see the given columns - what a role without
     * those grants would produce.
     *
     * @param array<string, list<string>> $hidden table => columns to remove
     */
    private static function factoryWithColumns(Config $config, string $login, array $hidden): Factory
    {
        self::assertNotNull(Env::factory($config, $login)->resolver()->resolve());
        $cache = $_SESSION['galbridge'] ?? null;
        self::assertIsArray($cache);
        foreach ($hidden as $table => $cols) {
            $cache['viewer']['columns'][$table] = array_values(array_diff($cache['viewer']['columns'][$table] ?? [], $cols));
        }
        $_SESSION['galbridge'] = $cache;

        $connection = null;
        $connect = static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
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
