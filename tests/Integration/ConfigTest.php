<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\Schema;
use GalBridge\Directory\Viewer;
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
            // the derived binding names the PORT Roundcube connects to as well
            'tls URI with port' => ['tls://127.0.0.1:143', ['127.0.0.1:143']],
            'ssl URI, upper case' => ['ssl://MAIL.Example.COM:993', ['mail.example.com:993']],
            'ssl URI without a port' => ['ssl://mail.example.com', ['mail.example.com:993']],
            'imaps URI without a port' => ['imaps://mail.example.com', ['mail.example.com:993']],
            'non-standard port' => ['tls://mail.example.com:1143', ['mail.example.com:1143']],
            'host:port' => ['localhost:143', ['localhost:143']],
            'bare host' => ['imap.example.com', ['imap.example.com:143']],
            'IDN host' => ['imap.bücher.example', ['imap.xn--bcher-kva.example:143']],
            'IPv6 literal' => ['tls://[::1]:143', ['[::1]:143']],
            // a local socket (Roundcube opens it with port -1): the binding is the socket itself
            'local socket' => ['unix:///run/dovecot/imap.sock', ['unix:///run/dovecot/imap.sock']],
            'local socket, scheme in upper case, path as written' => ['UNIX:///Run/Dovecot/IMAP.sock', ['unix:///Run/Dovecot/IMAP.sock']],
            'local socket, relative path' => ['unix://run/dovecot/imap.sock', null],
            'local socket, no path' => ['unix:///', null],
            'local socket with whitespace' => ['unix:///run/dovecot/imap sock', null],
            'local socket with a placeholder' => ['unix:///run/%d/imap.sock', null],
            'ldapi is not an IMAP server' => ['ldapi:///run/slapd/ldapi', null],
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
        self::assertSame(['mail.example.com:143'], ViewerResolver::allowedImapHosts(self::merged(['default_host' => 'tls://mail.example.com:143'], [])));
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
            'port 0' => [['mail.example.com:0']],
            'port above 65535' => [['mail.example.com:65536']],
            'port that is not a number' => [['mail.example.com:imap']],
            'two ports' => [['mail.example.com:143:993']],
            'empty port' => [['mail.example.com:']],
            'IPv6 with a port but without brackets' => [['2001:db8::1:993x']],
            'bracketed host name' => [['[mail.example.com]:993']],
            'template' => [['imap.%d']],
            'wildcard' => [['*']],
            'wildcard mixed' => [['127.0.0.1', '*']],
            'empty entry' => [['127.0.0.1', '']],
            'integer entry' => [[127001]],
            'whitespace in name' => [['mail example.com']],
            'path' => [['mail.example.com/x']],
            'nested list' => [[['127.0.0.1']]],
            'local socket, relative path' => [['unix://run/dovecot/imap.sock']],
            'local socket, no path' => [['unix:///']],
            'local socket with whitespace' => [['unix:///run/dovecot/imap sock']],
            'local socket with a line break' => [["unix:///run/dovecot/imap.sock\n"]],
            'local socket with a placeholder' => [['unix:///run/%d/imap.sock']],
            'ldapi' => [['ldapi:///run/slapd/ldapi']],
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

    /**
     * host_mismatch tells the administrator what to put into galbridge_imap_hosts: the session host,
     * the stored host and the allowed hosts (server names, never the login), once per request.
     */
    #[DataProvider('engines')]
    public function testHostMismatchLogNamesTheHosts(string $dsn): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'galbridge_imap_hosts' => ['127.0.0.1', 'mail.example.com']], []);
        foreach ([1, 2] as $request) {
            self::assertSame([], self::factory($config, self::JAN, 'tls://127.0.0.1:143', 'IMAP.Evil.example')->books());
        }
        $lines = array_values(preg_grep('/host_mismatch/', $GLOBALS['galbridge_test_log']) ?: []);
        self::assertSame(['WARN viewer: host_mismatch: session IMAP host is not an allowed directory host host=imap.evil.example:143 mail_host=127.0.0.1 allowed=127.0.0.1 mail.example.com'], $lines, 'once per request');
        self::assertStringNotContainsString('kowalski', implode("\n", $GLOBALS['galbridge_test_log']), 'never the login');
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

    // ------------------------------------------------------------------ required and optional columns (Schema)

    /**
     * @return array<string, array{array<string, mixed>, array<string, list<string>>, array<string, list<string>>}>
     */
    public static function schemas(): array
    {
        $required = [
            'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver', 'expired'],
            'domain' => ['domain', 'active', 'backupmx', 'expired'],
            // the view over forwardings; "domain" always: the viewer's own aliases are checked
            // against the state of the domain their row belongs to
            Schema::FORWARDINGS => ['address', 'forwarding', 'domain', 'is_alias', 'active'],
        ];
        $optional = [
            'mailbox' => ['first_name', 'last_name', 'department'],
            'alias' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
            Schema::FORWARDINGS => ['is_list', 'is_maillist'],
            'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
            'moderators' => ['address', 'moderator'],
            'maillist_owners' => ['address', 'owner'],
        ];
        $noExpired = $required;
        $noExpired['mailbox'] = ['username', 'name', 'domain', 'active', 'enabledeliver'];
        $noExpired['domain'] = ['domain', 'active', 'backupmx'];
        return [
            'defaults' => [[], $required, $optional],
            'expired accounts shown' => [['galbridge_hide_expired' => false], $noExpired, ['alias' => ['address', 'name', 'accesspolicy', 'domain', 'active'], 'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active']] + $optional],
            'per-user aliases shown' => [['galbridge_user_aliases' => true], $required, $optional],
            'every field' => [['galbridge_fields' => Config::FIELDS], $required, ['mailbox' => ['first_name', 'last_name', 'department', 'telephone', 'mobile', 'birthday']] + $optional],
            'name and e-mail only' => [['galbridge_fields' => ['name', 'email', 'organization']], $required, ['mailbox' => []] + $optional],
            'persons only' => [['galbridge_include_aliases' => false, 'galbridge_include_mailing_lists' => false], $required, ['mailbox' => $optional['mailbox']]],
            'aliases only' => [['galbridge_include_mailing_lists' => false], $required, ['mailbox' => $optional['mailbox'], 'alias' => $optional['alias'], Schema::FORWARDINGS => ['is_list'], 'moderators' => $optional['moderators']]],
            'list policy all' => [['galbridge_list_policy' => 'all'], $required, array_diff_key($optional, ['moderators' => 1, 'maillist_owners' => 1])],
        ];
    }

    /**
     * One list of required and optional columns for the effective configuration, used alike by the
     * Viewer resolution, the book factory and diag.
     *
     * @param array<string, mixed>        $options
     * @param array<string, list<string>> $required
     * @param array<string, list<string>> $optional
     */
    #[DataProvider('schemas')]
    public function testSchemaFollowsTheConfiguration(array $options, array $required, array $optional): void
    {
        $config = Env::config(Env::sqliteDsn(), $options);
        self::assertSame($required, Schema::required($config));
        self::assertEqualsCanonicalizing(array_keys($optional), array_keys(Schema::optional($config)));
        foreach ($optional as $table => $cols) {
            self::assertEqualsCanonicalizing($cols, Schema::optional($config)[$table] ?? null, $table);
        }

        // every column the person query can read is required or optional (diag reports it)
        $all = [];
        foreach ([Schema::required($config), Schema::optional($config)] as $set) {
            foreach ($set as $table => $cols) {
                foreach ($cols as $c) {
                    $all[$table][] = $c;
                }
            }
        }
        $viewer = new Viewer(self::JAN, 'example.com', [self::JAN], $all);
        $fields = new FieldMap($config->fields(), $viewer, $config->bool('galbridge_user_aliases'), $config->organizations() !== []);
        self::assertSame([], array_diff($fields->personColumns(), $all['mailbox']));
        self::assertSame([], array_values(array_diff($fields->missing($config->fields()), ['organization'])), 'every configured column field is covered (organization needs no column)');

        // missingRequired() names table.column
        self::assertSame([], Schema::missingRequired($config, $all));
        $visible = $all;
        $visible['mailbox'] = array_values(array_diff($visible['mailbox'], ['enabledeliver']));
        unset($visible[Schema::FORWARDINGS]);
        $expected = ['mailbox.enabledeliver'];
        foreach ($required[Schema::FORWARDINGS] as $c) {
            $expected[] = Schema::FORWARDINGS . '.' . $c;
        }
        self::assertSame('galbridge_forwardings', Schema::FORWARDINGS, 'the plugin reads the restricted view, never the table');
        self::assertSame($expected, Schema::missingRequired($config, $visible));
    }

    /**
     * The runtime agrees with Schema (and so with diag): expired columns are needed only with
     * galbridge_hide_expired; a missing required column is reported as column_missing before any
     * statement could fail on it (db_error).
     */
    #[DataProvider('engines')]
    public function testRuntimeRequiresExactlyTheSchemaColumns(string $dsn): void
    {
        $expired = ['mailbox' => ['expired'], 'domain' => ['expired'], 'alias' => ['expired'], 'maillists' => ['expired']];
        $factory = self::factoryWithColumns(Env::config($dsn, ['galbridge_hide_expired' => false]), self::JAN, $expired);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book, 'expired columns are not needed when expired accounts are shown');
        $book->set_pagesize(200);
        self::assertContains('expired.user@example.com', self::emails($book->list_records()));

        foreach ([['galbridge_user_aliases' => true], []] as $options) {
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $factory = self::factoryWithColumns(Env::config($dsn, $options), self::JAN, [Schema::FORWARDINGS => ['domain']]);
            self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
            $log = implode("\n", $GLOBALS['galbridge_test_log']);
            self::assertStringContainsString('column_missing: required vmail columns not readable: galbridge_forwardings.domain (see bin/galbridge.sh diag)', $log);
            self::assertStringNotContainsString('db_error', $log);
        }

        // the optional list columns only switch entity kinds off
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $factory = self::factoryWithColumns(Env::config($dsn), self::JAN, [Schema::FORWARDINGS => ['is_list', 'is_maillist']]);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        self::assertSame(7, $book->list_records()->count, 'persons only');
        self::assertStringNotContainsString('column_missing', implode("\n", $GLOBALS['galbridge_test_log']));
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

    // ------------------------------------------------------------------ IMAP port (audit U-11)

    public function testExplicitImapHostsMayNameAPort(): void
    {
        $config = self::merged(['imap_host' => 'ssl://imap.%d', 'galbridge_imap_hosts' => ['mail.example.com:993', 'IMAP2.Example.com', '127.0.0.1:143', '[::1]:1143', '[2001:db8::7]']], []);
        self::assertSame(['mail.example.com:993', 'imap2.example.com', '127.0.0.1:143', '[::1]:1143', '2001:db8::7'], ViewerResolver::allowedImapHosts($config));
        self::assertSame([], array_column($config->problems(), 'option'));

        // an entry with a port covers that port only; an entry without one covers the host
        $allowed = ['mail.example.com:993', 'imap2.example.com', '[::1]:1143'];
        self::assertTrue(ViewerResolver::covers($allowed, 'mail.example.com', 993));
        self::assertFalse(ViewerResolver::covers($allowed, 'mail.example.com', 143), 'another service on the same host name');
        self::assertFalse(ViewerResolver::covers($allowed, 'mail.example.com', 0), 'unknown session port');
        self::assertTrue(ViewerResolver::covers($allowed, 'imap2.example.com', 143));
        self::assertTrue(ViewerResolver::covers($allowed, 'imap2.example.com', 10143));
        self::assertTrue(ViewerResolver::covers($allowed, '::1', 1143));
        self::assertFalse(ViewerResolver::covers($allowed, '::1', 143));
        self::assertFalse(ViewerResolver::covers($allowed, 'mail.example.com.evil.example', 993));
        self::assertFalse(ViewerResolver::covers($allowed, '', 993));
        // the stored mail_host of the Roundcube user has no port: its host must be listed
        self::assertTrue(ViewerResolver::covers($allowed, 'mail.example.com'));
        self::assertTrue(ViewerResolver::covers($allowed, '::1'));
        self::assertFalse(ViewerResolver::covers($allowed, 'mail.example.co'));
        self::assertFalse(ViewerResolver::covers($allowed, 'example.com'));
    }

    /**
     * Two IMAP services under ONE host name, on different ports and with different user databases,
     * must not be mistaken for each other: the port of the session is part of the binding - derived
     * from imap_host, or written into galbridge_imap_hosts.
     *
     * @return array<string, array{string, array<string, mixed>, int, string}>
     */
    public static function sessionPorts(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out[$engine . ': derived, the configured port'] = [$dsn, [], 143, 'ok'];
            $out[$engine . ': derived, another port of the host'] = [$dsn, [], 993, 'host_mismatch'];
            $out[$engine . ': derived, unknown port'] = [$dsn, [], 0, 'host_mismatch'];
            $out[$engine . ': listed with its port'] = [$dsn, ['galbridge_imap_hosts' => ['127.0.0.1:143']], 143, 'ok'];
            $out[$engine . ': listed with another port'] = [$dsn, ['galbridge_imap_hosts' => ['127.0.0.1:10143']], 143, 'host_mismatch'];
            $out[$engine . ': listed without a port'] = [$dsn, ['galbridge_imap_hosts' => ['127.0.0.1']], 10143, 'ok'];
            $out[$engine . ': two services, the other one'] = [$dsn, ['galbridge_imap_hosts' => ['127.0.0.1:143', 'imap.example.org:993']], 993, 'host_mismatch'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('sessionPorts')]
    public function testTheImapPortOfTheSessionIsPartOfTheBinding(string $dsn, array $options, int $port, string $status): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143'] + $options, []);
        $connects = 0;
        $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects, $port);
        $books = $factory->books();
        self::assertSame($status, $factory->resolver()->status());
        if ($status === 'ok') {
            self::assertSame([BookDefinition::DOMAIN], array_keys($books));
        } else {
            self::assertSame([], $books);
            self::assertInstanceOf(DeniedBook::class, $factory->create(BookDefinition::DOMAIN));
            self::assertSame(0, $connects, 'a session of another IMAP service never reaches the database');
            self::assertArrayNotHasKey('galbridge', $_SESSION);
        }
    }

    /**
     * The cached Viewer is bound to the port as well: a session that claims the cache of another
     * endpoint resolves again (and is refused).
     */
    #[DataProvider('engines')]
    public function testCachedViewerIsBoundToTheImapPort(string $dsn): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'galbridge_imap_hosts' => ['127.0.0.1']], []);
        $connect = static fn (): Connection => new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        $_SESSION = [];
        self::assertNotNull((new ViewerResolver($config, $connect, 1, self::JAN, '127.0.0.1', '127.0.0.1', 143))->resolve());
        self::assertSame(143, $_SESSION['galbridge']['port'] ?? null);
        $at = $_SESSION['galbridge']['at'];

        // the same endpoint: answered from the cache (no connection is opened)
        $none = static function (): Connection {
            throw new \LogicException('the cached Viewer must be used');
        };
        self::assertNotNull((new ViewerResolver($config, $none, 1, self::JAN, '127.0.0.1', '127.0.0.1', 143))->resolve());

        // another port: the cache entry does not apply
        $connects = 0;
        $counting = static function () use ($connect, &$connects): Connection {
            $connects++;
            return $connect();
        };
        self::assertNotNull((new ViewerResolver($config, $counting, 1, self::JAN, '127.0.0.1', '127.0.0.1', 993))->resolve());
        self::assertSame(1, $connects, 'resolved again for the other endpoint');
        self::assertSame(993, $_SESSION['galbridge']['port'] ?? null);
        self::assertGreaterThanOrEqual($at, $_SESSION['galbridge']['at']);
    }

    /**
     * With include_host_config Roundcube loads a configuration file chosen by the Host header of the
     * request, so "the administrator's imap_host" is no longer one fixed value: nothing is derived
     * from it, the directory servers have to be listed.
     */
    #[DataProvider('engines')]
    public function testHostDependentConfigurationNeedsAnExplicitHostList(string $dsn): void
    {
        foreach ([true, ['mail.example.com' => 'mail_example_com.inc.php']] as $include) {
            $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'include_host_config' => $include], []);
            self::assertNull(ViewerResolver::allowedImapHosts($config));
            self::assertStringContainsString('include_host_config', (string) ViewerResolver::bindingProblem($config));
            $connects = 0;
            $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects);
            self::assertSame([], $factory->books());
            self::assertSame('disabled', $factory->resolver()->status());
            self::assertSame(0, $connects);
            self::assertStringContainsString('include_host_config', implode("\n", $GLOBALS['galbridge_test_log']));

            $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'include_host_config' => $include, 'galbridge_imap_hosts' => ['127.0.0.1:143']], []);
            self::assertNull(ViewerResolver::bindingProblem($config));
            self::assertSame([BookDefinition::DOMAIN], array_keys(self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1')->books()));
        }
        foreach ([false, null, [], 0] as $off) {
            $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'include_host_config' => $off], []);
            self::assertSame(['127.0.0.1:143'], ViewerResolver::allowedImapHosts($config));
        }
        // the option is part of the fingerprint: switching it on invalidates cached Viewers
        self::assertNotSame(
            self::merged(['galbridge_db_dsn' => $dsn, 'include_host_config' => true], [])->fingerprint(),
            self::merged(['galbridge_db_dsn' => $dsn, 'include_host_config' => false], [])->fingerprint(),
        );
    }

    /**
     * Roundcube reads include_host_config from its files WITHOUT the ROUNDCUBE_INCLUDE_HOST_CONFIG
     * override, the configuration of the plugin with it: a variable that says "off" must not make
     * the plugin derive the binding while the core still picks files by the Host header (STAGE 6).
     */
    #[DataProvider('engines')]
    public function testIncludeHostConfigInTheEnvironmentNeedsAnExplicitHostList(string $dsn): void
    {
        foreach (['0', 'false', '', '1'] as $value) {
            putenv('ROUNDCUBE_INCLUDE_HOST_CONFIG=' . $value);
            try {
                $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'include_host_config' => false], []);
                self::assertNull(ViewerResolver::allowedImapHosts($config), $value);
                self::assertStringContainsString('ROUNDCUBE_INCLUDE_HOST_CONFIG', (string) ViewerResolver::bindingProblem($config));
                $connects = 0;
                self::assertSame([], self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects)->books());
                self::assertSame(0, $connects);
                // an explicit list does not depend on it
                $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'galbridge_imap_hosts' => ['127.0.0.1:143']], []);
                self::assertSame([BookDefinition::DOMAIN], array_keys(self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1')->books()));
            } finally {
                putenv('ROUNDCUBE_INCLUDE_HOST_CONFIG');
            }
        }
        self::assertSame(['127.0.0.1:143'], ViewerResolver::allowedImapHosts(self::merged(['imap_host' => 'tls://127.0.0.1:143'], [])));
    }

    /**
     * The binding compares the values Roundcube wrote at login exactly as written: the host of the
     * session and of the user record must be a host name, an IPv4 address, an IPv6 address in
     * brackets or a local socket. Anything else - a blank around it, a scheme, a user part, a colon,
     * a path - could be read as the allowed server here while PHP connected the login elsewhere
     * (the socket layer takes the host up to the first colon), so it is refused before the database
     * is asked (STAGE 6; reachable where the user types the server on Roundcube 1.7.0 - 1.7.5).
     *
     * @return array<string, array{string, string, string, int, string}>
     */
    public static function rawSessionHosts(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach ([
                'canonical' => ['127.0.0.1', '127.0.0.1', 143, 'ok'],
                'host name in upper case' => ['MAIL.example.com', 'mail.example.com', 143, 'ok'],
                'stored host as the configured URI' => ['tls://mail.example.com:143', 'mail.example.com', 143, 'ok'],
                'stored URI, scheme in upper case' => ['SSL://mail.example.com', 'mail.example.com', 143, 'ok'],
                'session host with a leading blank' => ['mail.example.com', ' mail.example.com', 143, 'host_mismatch'],
                'session host with a trailing line break' => ['mail.example.com', "mail.example.com\n", 143, 'host_mismatch'],
                'session host with a scheme' => ['mail.example.com', 'tls://mail.example.com', 143, 'host_mismatch'],
                'session host with a port' => ['mail.example.com', 'mail.example.com:10143', 143, 'host_mismatch'],
                'session host with a second host' => ['mail.example.com', 'imap.evil.example:143//mail.example.com', 143, 'host_mismatch'],
                'session host with a user part' => ['mail.example.com', 'x@mail.example.com', 143, 'host_mismatch'],
                'session host with a path' => ['mail.example.com', 'imap.evil.example/mail.example.com', 143, 'host_mismatch'],
                'session host ending in a dot' => ['mail.example.com', 'mail.example.com.', 143, 'host_mismatch'],
                'stored host with a blank' => [' mail.example.com', 'mail.example.com', 143, 'host_mismatch'],
                'stored URI with a path' => ['tls://imap.evil.example/x//mail.example.com', 'mail.example.com', 143, 'host_mismatch'],
                'stored URI with a user part' => ['ssl://x@mail.example.com:993', 'mail.example.com', 143, 'host_mismatch'],
                'stored URI with another scheme' => ['http://mail.example.com', 'mail.example.com', 143, 'host_mismatch'],
                'IPv6 in brackets' => ['[::1]', '[::1]', 143, 'ok'],
                'IPv6 without brackets' => ['::1', '::1', 143, 'host_mismatch'],
            ] as $case => [$mailHost, $storageHost, $port, $status]) {
                $out[$engine . ': ' . $case] = [$dsn, $mailHost, $storageHost, $port, $status];
            }
        }
        return $out;
    }

    #[DataProvider('rawSessionHosts')]
    public function testTheSessionHostIsComparedInTheFormRoundcubeWroteIt(string $dsn, string $mailHost, string $storageHost, int $port, string $status): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'ssl://imap.%d', 'galbridge_imap_hosts' => ['127.0.0.1', 'mail.example.com:143', '[::1]', '::1']], []);
        $connects = 0;
        $factory = self::factory($config, self::JAN, $mailHost, $storageHost, $connects, $port);
        $books = $factory->books();
        self::assertSame($status, $factory->resolver()->status());
        if ($status === 'ok') {
            self::assertSame([BookDefinition::DOMAIN], array_keys($books));
            return;
        }
        self::assertSame([], $books);
        self::assertSame(0, $connects, 'refused before the database is asked');
        self::assertArrayNotHasKey('galbridge', $_SESSION);
        // a value that is not canonical never reaches the log line: a marker stands in for it
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('not-canonical', $log);
        self::assertStringNotContainsString('evil', $log);
        self::assertStringNotContainsString('x@', $log);
    }

    public function testCanonicalHostAcceptsOnlyWhatALoginWrites(): void
    {
        foreach (['mail.example.com' => 'mail.example.com', 'IMAP.Example.COM' => 'imap.example.com', 'localhost' => 'localhost', '127.0.0.1' => '127.0.0.1',
            '[2001:DB8::7]' => '2001:db8::7', 'xn--bcher-kva.example' => 'xn--bcher-kva.example', 'unix:///run/dovecot/imap.sock' => 'unix:///run/dovecot/imap.sock'] as $raw => $canonical) {
            self::assertSame($canonical, ViewerResolver::canonicalHost($raw), $raw);
        }
        foreach (['', ' ', 'mail.example.com ', "\tmail.example.com", 'mail.example.com:143', 'ssl://mail.example.com', 'a@mail.example.com', 'mail.example.com/x',
            '.mail.example.com', 'mail.example.com.', '-mail.example.com', '::1', '[::1', '[mail.example.com]', '[::1]:143', ' unix:///run/dovecot/imap.sock',
            'unix:///run/dovecot/imap.sock ', 'mail.example.com%00', 'bücher.example', str_repeat('a', 254)] as $raw) {
            self::assertNull(ViewerResolver::canonicalHost($raw), var_export($raw, true));
        }
        // the stored mail_host of an older Roundcube may be the configured URI - that exact form only
        self::assertSame('mail.example.com', ViewerResolver::canonicalHost('imaps://mail.example.com:993', true));
        self::assertSame('::1', ViewerResolver::canonicalHost('tls://[::1]:143', true));
        self::assertNull(ViewerResolver::canonicalHost('imaps://mail.example.com:993'), 'never for the session host');
        self::assertNull(ViewerResolver::canonicalHost('imaps://mail.example.com:993:143', true));
        self::assertNull(ViewerResolver::canonicalHost('imaps:// mail.example.com', true));
    }

    /**
     * A bare IPv6 entry of galbridge_imap_hosts has colons that are not a port separator: its first
     * group is no host of it (STAGE 6).
     */
    public function testABareIpv6EntryIsNoPrefixOfAHost(): void
    {
        $allowed = ['2001:db8::7', 'mail.example.com:993'];
        self::assertTrue(ViewerResolver::covers($allowed, '2001:db8::7'));
        self::assertTrue(ViewerResolver::covers($allowed, '2001:db8::7', 143));
        self::assertFalse(ViewerResolver::covers($allowed, '2001'));
        self::assertFalse(ViewerResolver::covers($allowed, '2001', 143));
        self::assertFalse(ViewerResolver::covers($allowed, '2001:db8'));
        self::assertTrue(ViewerResolver::covers($allowed, 'mail.example.com'));
        self::assertFalse(ViewerResolver::covers($allowed, 'mail.example.com', 143));
    }

    // ------------------------------------------------------------------ switches (audit U-03)

    /**
     * @return array<string, array{mixed, null|bool}> value => what the switch reads as (null = ambiguous)
     */
    public static function switchValues(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'integer 1' => [1, true],
            'integer 0' => [0, false],
            // PHP would cast every one of these to TRUE - the first five say "off"
            'string false' => ['false', null],
            'string FALSE' => ['FALSE', null],
            'string no' => ['no', null],
            'string off' => ['off', null],
            'string 0' => ['0', null],
            'string true' => ['true', null],
            'string 1' => ['1', null],
            'empty string' => ['', null],
            'blank string' => [' ', null],
            'integer 2' => [2, null],
            'integer -1' => [-1, null],
            'float 1.0' => [1.0, null],
            'float 0.0' => [0.0, null],
            'empty array' => [[], null],
            'array' => [[true], null],
        ];
    }

    #[DataProvider('switchValues')]
    public function testSwitchesAreNeverCast(mixed $value, ?bool $expected): void
    {
        foreach (Config::BOOLEANS as $name) {
            $config = self::merged([$name => $value], []);
            if ($expected === null) {
                self::assertFalse($config->switchesValid(), $name);
                self::assertTrue($config->booksDisabled(), $name);
                self::assertSame(Config::DEFAULTS[$name], $config->bool($name), $name . ' reads as its (conservative) default');
                $problems = array_values(array_filter($config->problems(), static fn (array $p): bool => $p['option'] === $name));
                self::assertCount(1, $problems, $name . ': reported once');
                self::assertSame('fail', $problems[0]['level']);
                self::assertStringContainsString('must be the boolean true or false', $problems[0]['message']);
                self::assertStringContainsString('all books are disabled', $problems[0]['message']);
            } else {
                self::assertTrue($config->switchesValid(), $name);
                self::assertSame($expected, $config->bool($name), $name);
                self::assertSame([], $config->problems(), $name);
            }
        }
    }

    public function testEveryBooleanDefaultIsCoveredByTheSwitchList(): void
    {
        $switches = [...Config::BOOLEANS, ...Config::TRISTATES];
        foreach (Config::DEFAULTS as $name => $default) {
            self::assertSame(is_bool($default), in_array($name, $switches, true), $name);
        }
        self::assertSame($switches, array_values(array_unique($switches)));
        // null is "not set" for Roundcube: the built-in default applies
        $unset = self::merged(array_fill_keys($switches, null), []);
        self::assertTrue($unset->switchesValid());
        foreach (Config::BOOLEANS as $name) {
            self::assertSame(Config::DEFAULTS[$name], $unset->bool($name), $name);
        }
        // the defaults themselves are valid
        $config = self::merged([], []);
        self::assertTrue($config->switchesValid());
        self::assertFalse($config->booksDisabled());
        $config->validate();
        self::assertSame([], $config->problems());
    }

    /**
     * @return array<string, array{mixed, null|bool|string}>
     */
    public static function searchOnlyValues(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'integer 1' => [1, true],
            'integer 0' => [0, false],
            'auto' => ['auto', 'auto'],
            'AUTO with blanks' => [' AUTO ', 'auto'],
            'string false' => ['false', null],
            'string true' => ['true', null],
            'string yes' => ['yes', null],
            'empty string' => ['', null],
            'array' => [['auto'], null],
            'integer 2' => [2, null],
        ];
    }

    #[DataProvider('searchOnlyValues')]
    public function testSearchOnlyIsTrueFalseOrAuto(mixed $value, null|bool|string $expected): void
    {
        foreach (Config::TRISTATES as $name) {
            $config = self::merged([$name => $value], []);
            if ($expected === null) {
                self::assertFalse($config->switchesValid(), $name);
                self::assertTrue($config->searchonly($name), 'an ambiguous value reads as the restrictive one');
                self::assertContains('fail', array_column($config->problems(), 'level'));
                self::assertStringContainsString("must be true, false or 'auto'", $config->problems()[0]['message']);
            } else {
                self::assertTrue($config->switchesValid(), $name);
                self::assertSame($expected === 'auto' ? null : $expected, $config->searchonly($name));
                self::assertSame([], $config->problems());
            }
        }
    }

    /**
     * End to end: one ambiguous switch - whichever it is - and the user gets no book at all; no
     * Viewer is resolved and the database is never contacted.
     */
    #[DataProvider('engines')]
    public function testAnAmbiguousSwitchDisablesEveryBook(string $dsn): void
    {
        foreach ([
            'galbridge_global_book' => 'false',         // would have switched the global book ON
            'galbridge_db_allow_plaintext' => 'no',     // would have accepted a plaintext remote database
            'galbridge_user_aliases' => 'off',          // would have shown per-user aliases
            'galbridge_global_export' => '0 ',          // would have allowed bulk exports
            'galbridge_debug' => 'false',               // would have switched debug logging on
            'galbridge_hide_expired' => 'true',
            'galbridge_domain_searchonly' => 'false',
            'galbridge_domain_book' => [],
        ] as $name => $value) {
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143',
                'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org'], $name => $value], []);
            $connects = 0;
            $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects);
            self::assertSame([], $factory->books(), $name);
            foreach ([BookDefinition::DOMAIN, BookDefinition::GLOBAL] as $id) {
                $book = $factory->create($id);
                self::assertInstanceOf(DeniedBook::class, $book, $name);
                self::assertSame(0, $book->list_records()->count);
            }
            self::assertSame(0, $connects, $name . ': the database is never contacted');
            self::assertSame('unresolved', $factory->resolver()->status());
            self::assertStringContainsString('config_invalid', implode("\n", $GLOBALS['galbridge_test_log']), $name);
            self::assertStringContainsString('option=' . $name, implode("\n", $GLOBALS['galbridge_test_log']), $name);
            // the weaker behaviour is NOT in effect
            self::assertFalse($config->bool('galbridge_db_allow_plaintext'));
            self::assertFalse($config->bool('galbridge_debug'));
            self::assertFalse($config->bool('galbridge_user_aliases'));
        }
    }

    /**
     * Roundcube applies ROUNDCUBE_<OPTION> environment variables on every read and converts the text
     * "false" to the boolean TRUE. The plugin reads the variable itself: 1, 0, true and false mean
     * what they say, anything else fails closed.
     *
     * @return array<string, array{string, null|bool}>
     */
    public static function environmentSwitches(): array
    {
        return [
            'false' => ['false', false],
            'FALSE' => ['FALSE', false],
            '0' => ['0', false],
            'true' => ['true', true],
            'True with blanks' => [' True ', true],
            '1' => ['1', true],
            'no' => ['no', null],
            'off' => ['off', null],
            'f' => ['f', null],
            't' => ['t', null],
            'yes' => ['yes', null],
            'empty' => ['', null],
            '2' => ['2', null],
            'auto' => ['auto', null],
        ];
    }

    #[DataProvider('environmentSwitches')]
    public function testEnvironmentOverridesOfSwitchesAreReadStrictly(string $text, ?bool $expected): void
    {
        foreach (['galbridge_db_allow_plaintext', 'galbridge_global_book', 'galbridge_hide_expired'] as $name) {
            $var = 'ROUNDCUBE_' . strtoupper($name);
            putenv($var . '=' . $text);
            try {
                // whatever the file says, the variable decides - as in Roundcube
                foreach ([true, false] as $inFile) {
                    $config = self::merged([$name => $inFile], []);
                    self::assertContains($name, $config->envOverrides());
                    if ($expected === null) {
                        self::assertFalse($config->switchesValid(), "{$var}={$text}");
                        self::assertTrue($config->booksDisabled());
                        self::assertSame(Config::DEFAULTS[$name], $config->bool($name));
                        self::assertStringContainsString('environment variable ' . $var . ' must be 1, 0, true or false', $config->problems()[0]['message']);
                    } else {
                        self::assertTrue($config->switchesValid(), "{$var}={$text}");
                        self::assertSame($expected, $config->bool($name), "{$var}={$text}");
                        self::assertSame([], $config->problems());
                    }
                }
            } finally {
                putenv($var);
            }
            self::assertNotContains($name, self::merged([], [])->envOverrides());
        }
    }

    public function testEnvironmentOverrideOfSearchOnlyAcceptsAuto(): void
    {
        putenv('ROUNDCUBE_GALBRIDGE_DOMAIN_SEARCHONLY=auto');
        try {
            $config = self::merged(['galbridge_domain_searchonly' => false], []);
            self::assertNull($config->searchonly('galbridge_domain_searchonly'));
            self::assertTrue($config->switchesValid());
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_DOMAIN_SEARCHONLY');
        }
        putenv('ROUNDCUBE_GALBRIDGE_DOMAIN_SEARCHONLY=false');
        try {
            // core hands the plugin TRUE for this text
            self::assertFalse(self::merged([], [])->searchonly('galbridge_domain_searchonly'));
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_DOMAIN_SEARCHONLY');
        }
    }

    /**
     * The textual "false" in the ENVIRONMENT must not switch the global book on (the scenario of the
     * audit finding: a container deployment that sets ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK=false).
     */
    #[DataProvider('engines')]
    public function testEnvironmentFalseKeepsTheGlobalBookOff(string $dsn): void
    {
        $options = ['galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        putenv('ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK=false');
        try {
            $factory = Env::factory(Env::config($dsn, $options), self::JAN);
            self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK');
        }
        putenv('ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK=true');
        try {
            $factory = Env::factory(Env::config($dsn, $options), self::JAN);
            self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys($factory->books()));
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_GLOBAL_BOOK');
        }
    }

    // ------------------------------------------------------------------ pre-release review of 1.0.1

    /**
     * An option that the administrator sets through the environment ALONE (no line in a
     * configuration file - usual in container deployments) stays the administrator's value for a
     * user whose stored preferences carry a key of that name. rcube_config::all() applies the
     * ROUNDCUBE_* variables to the options of the files only, and the guard fell back to the
     * built-in default there: for that user the hidden lists were empty.
     */
    public function testThePreferenceGuardReadsOptionsSetThroughTheEnvironmentAlone(): void
    {
        $vars = [
            'ROUNDCUBE_GALBRIDGE_HIDDEN_ADDRESSES' => '["postmaster@example.com"]',
            'ROUNDCUBE_GALBRIDGE_HIDDEN_LOCALPARTS' => '["noreply"]',
            'ROUNDCUBE_GALBRIDGE_VISIBILITY_COLUMN' => 'galbridge_visible',
            'ROUNDCUBE_GALBRIDGE_LIST_POLICY' => 'public_only',
            'ROUNDCUBE_GALBRIDGE_MAX_RESULTS' => '25',
        ];
        $prefs = ['galbridge_hidden_addresses' => [], 'galbridge_hidden_localparts' => [], 'galbridge_visibility_column' => '', 'galbridge_list_policy' => 'all', 'galbridge_max_results' => 5000];
        foreach ($vars as $var => $text) {
            putenv($var . '=' . $text);
        }
        try {
            $clean = self::merged(['galbridge_db_dsn' => 'x'], []);
            $tampered = self::merged(['galbridge_db_dsn' => 'x'], $prefs);
            foreach (['without preferences' => $clean, 'with preferences of these names' => $tampered] as $label => $config) {
                self::assertSame(['postmaster@example.com'], $config->hiddenAddresses(), $label);
                self::assertSame(['noreply'], $config->hiddenLocalparts(), $label);
                self::assertSame('galbridge_visible', $config->visibilityColumn(), $label);
                self::assertSame('public_only', $config->choice('galbridge_list_policy', Config::LIST_POLICIES), $label);
                self::assertSame(25, $config->int('galbridge_max_results', 10, 5000), $label);
                self::assertFalse($config->booksDisabled(), $label);
                self::assertSame([], $config->problems(), $label);
            }
            self::assertSame($clean->fingerprint(), $tampered->fingerprint());
        } finally {
            foreach (array_keys($vars) as $var) {
                putenv($var);
            }
        }
        // without the variables: the built-in default, never the preference
        $config = self::merged(['galbridge_db_dsn' => 'x'], ['galbridge_hidden_addresses' => ['jan.kowalski@example.com'], 'galbridge_list_policy' => 'all']);
        self::assertSame([], $config->hiddenAddresses());
        self::assertSame(Config::DEFAULTS['galbridge_list_policy'], $config->choice('galbridge_list_policy', Config::LIST_POLICIES));
        // a malformed variable fails closed for that user as for everybody else
        putenv('ROUNDCUBE_GALBRIDGE_HIDDEN_ADDRESSES=postmaster@example.com');
        try {
            $config = self::merged(['galbridge_db_dsn' => 'x'], ['galbridge_hidden_addresses' => []]);
            self::assertNull($config->hiddenAddresses(), 'not JSON: malformed, never "nothing hidden"');
            self::assertTrue($config->booksDisabled());
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_HIDDEN_ADDRESSES');
        }
    }

    /**
     * The fingerprint follows the EFFECTIVE value of a switch. For one that is set through the
     * environment Roundcube hands out its own conversion of the text - true for "true" AND for
     * "false" - so the raw option value was the same for two different configurations, and the
     * session-cached Viewer outlived the change.
     */
    public function testTheFingerprintFollowsSwitchesSetThroughTheEnvironment(): void
    {
        $admin = ['galbridge_db_dsn' => 'x', 'imap_host' => 'tls://127.0.0.1:143'];
        $print = static function (string $var, ?string $text) use ($admin): string {
            putenv($text === null ? $var : $var . '=' . $text);
            try {
                return self::merged($admin, [])->fingerprint();
            } finally {
                putenv($var);
            }
        };
        $var = 'ROUNDCUBE_GALBRIDGE_HIDE_EXPIRED'; // default: true
        self::assertNotSame($print($var, 'true'), $print($var, 'false'));
        self::assertNotSame($print($var, null), $print($var, 'false'));
        self::assertSame($print($var, null), $print($var, 'true'), 'the same effective configuration');
        self::assertSame($print($var, '1'), $print($var, 'TRUE'));
        self::assertSame($print($var, '0'), $print($var, 'false'));
        self::assertNotSame($print($var, 'false'), $print($var, 'maybe'), 'ambiguous: every book disabled, another configuration');
        self::assertNotSame($print($var, 'true'), $print($var, 'maybe'));

        $var = 'ROUNDCUBE_GALBRIDGE_DOMAIN_SEARCHONLY'; // a tristate, default: false
        $prints = array_map(static fn (string $text): string => $print($var, $text), ['auto', 'true', 'false']);
        self::assertCount(3, array_unique($prints), 'auto, on and off are three configurations');
        self::assertSame($print($var, null), $prints[2]);

        // a switch of a configuration file: 1 and true are the same configuration, false another
        $file = static fn (mixed $value): string => self::merged(['galbridge_user_aliases' => $value] + $admin, [])->fingerprint();
        self::assertSame($file(true), $file(1));
        self::assertNotSame($file(true), $file(false));
        self::assertSame($file(false), self::merged($admin, [])->fingerprint(), 'the default');
    }

    /**
     * galbridge_fields that is not an array shows name and e-mail only - fewer fields, never more -
     * and says so, like every other option of the wrong type (a string of names is an easy mistake,
     * and an environment variable that is not JSON reaches the plugin as null).
     *
     * @return array<string, array{mixed, string}>
     */
    public static function fieldListsOfTheWrongType(): array
    {
        return [
            'a string of names' => ['name,email,department', 'string'],
            'one name' => ['department', 'string'],
            'an integer' => [42, 'int'],
            'true' => [true, 'bool'],
        ];
    }

    #[DataProvider('fieldListsOfTheWrongType')]
    public function testAFieldListOfTheWrongTypeIsReported(mixed $value, string $type): void
    {
        $config = self::merged(['galbridge_db_dsn' => 'x', 'galbridge_fields' => $value], []);
        self::assertSame(['name', 'email'], $config->fields());
        self::assertSame([['level' => 'error', 'option' => 'galbridge_fields', 'message' => "must be an array of field names ({$type} given): only name and email are shown"]], $config->problems());
        self::assertFalse($config->booksDisabled(), 'fewer fields is the safe reading: the books stay');
        self::assertNotSame([], preg_grep('/config_invalid: must be an array of field names/', $GLOBALS['galbridge_test_log']) ?: [], 'logged as well');
    }

    public function testAFieldListFromAnEnvironmentVariableThatIsNotJsonIsReported(): void
    {
        putenv('ROUNDCUBE_GALBRIDGE_FIELDS=name,email,phone');
        try {
            $config = self::merged(['galbridge_db_dsn' => 'x'], []);
            self::assertSame(['name', 'email'], $config->fields());
            self::assertSame(['must be an array of field names (null given): only name and email are shown'], array_column($config->problems(), 'message'));
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_FIELDS');
        }
        putenv('ROUNDCUBE_GALBRIDGE_FIELDS=["name","email","phone"]');
        try {
            $config = self::merged(['galbridge_db_dsn' => 'x'], []);
            self::assertSame(['name', 'email', 'phone'], $config->fields());
            self::assertSame([], $config->problems());
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_FIELDS');
        }
    }

    // ------------------------------------------------------------------ IMAP server on a local socket

    /**
     * galbridge_imap_hosts may name a local socket in Roundcube's notation. A socket has neither a
     * host name nor a port: it is covered by the entry of that very socket - the path as written -
     * and by nothing else; in particular "unix" is not the host part of anything.
     */
    public function testALocalSocketIsAnEndpointOfItsOwn(): void
    {
        $socket = 'unix:///run/dovecot/imap.sock';
        $config = self::merged(['imap_host' => 'ssl://imap.%d', 'galbridge_imap_hosts' => ['UNIX:///run/dovecot/imap.sock', 'mail.example.com:993', 'unix:///var/run/Other.sock']], []);
        $allowed = ViewerResolver::allowedImapHosts($config);
        self::assertSame([$socket, 'mail.example.com:993', 'unix:///var/run/Other.sock'], $allowed);
        self::assertSame([], $config->problems());

        self::assertSame($socket, ViewerResolver::normaliseHost($socket));
        self::assertSame($socket, ViewerResolver::normaliseHost(' Unix:///run/dovecot/imap.sock '));
        self::assertSame($socket, ViewerResolver::endpoint($socket, -1), 'no port is appended');

        // the session (port -1, as Roundcube stores it) and the stored mail_host (no port)
        self::assertTrue(ViewerResolver::covers($allowed, $socket, -1));
        self::assertTrue(ViewerResolver::covers($allowed, $socket));
        self::assertTrue(ViewerResolver::covers($allowed, 'unix:///var/run/Other.sock', -1));
        foreach ([
            'unix:///run/dovecot/other.sock', 'unix:///run/dovecot/IMAP.sock', 'unix:///run/dovecot/imap.sock2', 'unix:///run/dovecot',
            'unix:///var/run/other.sock', 'unix://run/dovecot/imap.sock', 'unix', 'unix:', 'unix:///', 'run', '/run/dovecot/imap.sock', '',
        ] as $other) {
            self::assertFalse(ViewerResolver::covers($allowed, ViewerResolver::normaliseHost($other), -1), $other);
            self::assertFalse(ViewerResolver::covers($allowed, ViewerResolver::normaliseHost($other), 993), $other . ' (port 993)');
            self::assertFalse(ViewerResolver::covers($allowed, ViewerResolver::normaliseHost($other)), $other . ' (as the stored mail_host)');
        }
        // the TCP entry next to it works as before, and a socket is no host of it
        self::assertTrue(ViewerResolver::covers($allowed, 'mail.example.com', 993));
        self::assertTrue(ViewerResolver::covers($allowed, 'mail.example.com'));
        self::assertFalse(ViewerResolver::covers($allowed, 'mail.example.com', 143));
        self::assertFalse(ViewerResolver::covers(['mail.example.com', 'unix.example.com:993'], $socket, -1));
        self::assertFalse(ViewerResolver::covers(['unix', 'unix:143'], $socket, -1));
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string, string, int, string}>
     */
    public static function socketSessions(): array
    {
        $socket = 'unix:///run/dovecot/imap.sock';
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            $out[$engine . ': derived from imap_host'] = [$dsn, [], $socket, $socket, -1, 'ok'];
            $out[$engine . ': listed'] = [$dsn, ['galbridge_imap_hosts' => [$socket]], $socket, $socket, -1, 'ok'];
            $out[$engine . ': derived, another socket in the session'] = [$dsn, [], $socket, 'unix:///run/dovecot/other.sock', -1, 'host_mismatch'];
            $out[$engine . ': derived, another socket stored for the user'] = [$dsn, [], 'unix:///run/dovecot/other.sock', $socket, -1, 'host_mismatch'];
            $out[$engine . ': derived, a TCP session'] = [$dsn, [], '127.0.0.1', '127.0.0.1', 143, 'host_mismatch'];
            $out[$engine . ': derived, a host called unix'] = [$dsn, [], 'unix', $socket, -1, 'host_mismatch'];
            $out[$engine . ': listed TCP endpoint only'] = [$dsn, ['galbridge_imap_hosts' => ['127.0.0.1:143']], $socket, $socket, -1, 'host_mismatch'];
        }
        return $out;
    }

    /**
     * A Roundcube that reaches its IMAP server through a local socket (imap_host = unix:///path)
     * gets its books: 1.0.0 bound such a session by the URI, and the port of the binding (-1 for a
     * socket) must not turn that installation off.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('socketSessions')]
    public function testASessionOnALocalSocketIsBoundToThatSocket(string $dsn, array $options, string $mailHost, string $storageHost, int $port, string $status): void
    {
        $config = self::merged(['galbridge_db_dsn' => $dsn, 'imap_host' => 'unix:///run/dovecot/imap.sock'] + $options, []);
        $connects = 0;
        $factory = self::factory($config, self::JAN, $mailHost, $storageHost, $connects, $port);
        $books = $factory->books();
        self::assertSame($status, $factory->resolver()->status());
        if ($status === 'ok') {
            self::assertSame([BookDefinition::DOMAIN], array_keys($books));
            self::assertSame('unix:///run/dovecot/imap.sock', $_SESSION['galbridge']['host'] ?? null);
            self::assertSame(-1, $_SESSION['galbridge']['port'] ?? null);
        } else {
            self::assertSame([], $books);
            self::assertSame(0, $connects, 'a session of another IMAP service never reaches the database');
            self::assertArrayNotHasKey('galbridge', $_SESSION);
        }
    }

    // ------------------------------------------------------------------ visibility column (audit U-02)

    /**
     * @return array<string, array{mixed, null|string}> value => column ('' = not used, null = refused)
     */
    public static function visibilityColumns(): array
    {
        return [
            'empty (not used)' => ['', ''],
            'unset' => [null, ''],
            'a column' => ['galbridge_visible', 'galbridge_visible'],
            'a column with blanks' => [' visible_in_gal ', 'visible_in_gal'],
            // every other TYPE used to read as "no column" and switched the filter off silently
            'array with the name' => [['galbridge_visible'], null],
            'empty array' => [[], null],
            'true' => [true, null],
            'false' => [false, null],
            'integer' => [1, null],
            'zero' => [0, null],
            'float' => [1.5, null],
            // invalid names
            'blank string' => ['   ', null],
            'upper case' => ['Visible', null],
            'leading digit' => ['1visible', null],
            'SQL' => ['visible; DROP TABLE mailbox', null],
            'backtick' => ['vis`ible', null],
            '65 characters' => [str_repeat('a', 65), null],
        ];
    }

    #[DataProvider('visibilityColumns')]
    public function testVisibilityColumnMustBeAStringNamingAColumn(mixed $value, ?string $expected): void
    {
        $config = self::merged(['galbridge_visibility_column' => $value], []);
        self::assertSame($expected, $config->visibilityColumn());
        self::assertSame($expected, $config->visibilityColumn(), 'memoised');
        if ($expected === null) {
            self::assertTrue($config->booksDisabled());
            $problems = $config->problems();
            self::assertCount(1, $problems, 'reported once');
            self::assertSame(['fail', 'galbridge_visibility_column'], [$problems[0]['level'], $problems[0]['option']]);
            self::assertStringContainsString('all books are disabled', $problems[0]['message']);
        } else {
            self::assertFalse($config->booksDisabled());
            self::assertSame([], $config->problems());
        }
    }

    /**
     * End to end, for the value of the audit finding: an ARRAY holding the column name. The person
     * who is flagged as hidden must not become visible - there is no book at all.
     */
    #[DataProvider('engines')]
    public function testVisibilityColumnOfTheWrongTypeNeverSwitchesTheFilterOff(string $dsn): void
    {
        foreach ([['galbridge_visible'], [], true, false, 1] as $value) {
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            $config = Env::config($dsn, ['galbridge_visibility_column' => $value]);
            $connects = 0;
            $factory = self::factory($config, self::JAN, '127.0.0.1', '127.0.0.1', $connects);
            self::assertSame([], $factory->books());
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(DeniedBook::class, $book);
            self::assertSame(0, $book->search('*', 'kowalski')->count);
            self::assertStringContainsString('config_invalid', implode("\n", $GLOBALS['galbridge_test_log']));
            self::assertStringContainsString('option=galbridge_visibility_column', implode("\n", $GLOBALS['galbridge_test_log']));
        }
    }

    // ------------------------------------------------------------------ other option types

    public function testRefusedUsersOfTheWrongTypeKeepTheBuiltInList(): void
    {
        foreach (['vmail', true, 0, 'vmail,roundcube'] as $value) {
            $config = self::merged(['galbridge_db_refused_users' => $value], []);
            self::assertSame(Config::DEFAULTS['galbridge_db_refused_users'], $config->refusedUsers());
            $problems = $config->problems();
            self::assertSame(['fail', 'galbridge_db_refused_users'], [$problems[0]['level'], $problems[0]['option']]);
            self::assertStringContainsString('the built-in list is used', $problems[0]['message']);
            // the safety net is still there
            try {
                new Dsn('pgsql://vmail:x@127.0.0.1/vmail', $config);
                self::fail('the vmail role was accepted');
            } catch (\GalBridge\Exception\GalBridgeException $e) {
                self::assertStringContainsString('refused database role', $e->getMessage());
            }
        }
        // entries that are not role names are reported, the others still apply
        $config = self::merged(['galbridge_db_refused_users' => ['Vmail ', 7, '', ['root'], 'backup']], []);
        self::assertSame(['vmail', 'backup'], $config->refusedUsers());
        self::assertSame(['error'], array_values(array_unique(array_column($config->problems(), 'level'))));
        // an administrator may still shorten or extend the list
        self::assertSame(['only_this'], self::merged(['galbridge_db_refused_users' => ['only_this']], [])->refusedUsers());
    }

    public function testNumbersAndStringsOfTheWrongTypeAreReported(): void
    {
        $config = self::merged(['galbridge_max_results' => 'many', 'galbridge_scope_ttl' => [60], 'galbridge_max_ids' => true,
            'galbridge_db_dsn' => ['mysql://x'], 'galbridge_domain_book_name' => 42, 'galbridge_max_export' => '300', 'galbridge_max_count' => 250.0], []);
        self::assertSame(500, $config->int('galbridge_max_results', 10, 5000), 'the default');
        self::assertSame(300, $config->int('galbridge_scope_ttl', 30, 3600));
        self::assertSame(200, $config->int('galbridge_max_ids', 1, 1000));
        self::assertSame(300, $config->int('galbridge_max_export', 1, 100000), 'a numeric string is a number');
        self::assertSame(250, $config->int('galbridge_max_count', 100, 1000000));
        self::assertSame('', $config->string('galbridge_db_dsn'), 'a DSN that is not a string is no DSN (fail closed)');
        self::assertSame('', $config->string('galbridge_domain_book_name'));
        $reported = array_column($config->problems(), 'level', 'option');
        self::assertSame(['galbridge_max_results' => 'error', 'galbridge_scope_ttl' => 'error', 'galbridge_max_ids' => 'error', 'galbridge_db_dsn' => 'error', 'galbridge_domain_book_name' => 'error'], $reported);

        // validate() finds them without any other accessor having run (diag)
        $fresh = self::merged(['galbridge_max_results' => 'many', 'galbridge_db_dsn' => ['mysql://x'], 'galbridge_global_book' => 'false'], []);
        $fresh->validate();
        self::assertEqualsCanonicalizing(['galbridge_max_results', 'galbridge_db_dsn', 'galbridge_global_book'], array_column($fresh->problems(), 'option'));
    }

    /**
     * An organization label that is a number once cleaned becomes an integer array key in PHP. It is
     * a label like any other: the book, its name and the organization field work.
     */
    #[DataProvider('engines')]
    public function testNumericOrganizationLabelsWork(string $dsn): void
    {
        // (a key PHP itself reads as an integer, e.g. '2024', is an unlabelled entry: "Organization N")
        $config = Env::config($dsn, ['galbridge_organizations' => [' 2024 ' => ['example.com', 'example.org']], 'galbridge_fields' => ['name', 'email', 'organization']]);
        $notices = [];
        $factory = Env::factory($config, self::JAN, '', $notices);
        $books = $factory->books();
        self::assertSame('2024', $books[BookDefinition::DOMAIN]->name ?? null);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        $all = $book->list_records();
        self::assertGreaterThan(13, $all->count, 'both domains of the organization');
        foreach ($all->records as $record) {
            self::assertSame('2024', $record['organization'] ?? null);
        }
        self::assertSame($all->count, $book->search('organization', '2024', 1)->count);
        self::assertSame(0, $book->search('organization', '2025', 1)->count);
        self::assertSame([], $notices);
        self::assertStringNotContainsString('unexpected error', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    public function testOrganizationLabelsThatAreEqualOnceCleanedAreNotMerged(): void
    {
        $config = self::merged(['galbridge_organizations' => ['Acme' => ['acme.example'], "Acme\u{200B}" => ['other.example'], ' 7 ' => ['seven.example'], "7\u{00A0}" => ['again.example']]], []);
        $orgs = $config->organizations();
        self::assertSame(['acme.example'], $orgs['Acme'] ?? null);
        self::assertSame(['seven.example'], $orgs['7'] ?? null);
        self::assertCount(2, $orgs, 'the later duplicates are ignored: their domains fall back to themselves');
        self::assertContains('organization ignored (duplicate label)', array_column($config->problems(), 'message'));
    }

    // ------------------------------------------------------------------ fingerprint (audit I-02)

    public function testFingerprintIsKeyedWithTheServerSecret(): void
    {
        $values = ['galbridge_db_dsn' => 'pgsql://galbridge:Secret-One@127.0.0.1/vmail', 'imap_host' => 'tls://127.0.0.1:143'];
        $a = self::merged($values + ['des_key' => 'server-key-AAAAAAAAAAAAAA'], []);
        $b = self::merged($values + ['des_key' => 'server-key-BBBBBBBBBBBBBB'], []);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a->fingerprint());
        self::assertSame($a->fingerprint(), self::merged($values + ['des_key' => 'server-key-AAAAAAAAAAAAAA'], [])->fingerprint(), 'stable');
        self::assertNotSame($a->fingerprint(), $b->fingerprint(), 'another server secret, another fingerprint');

        // without the key it cannot be recomputed from the configuration: not the unkeyed hash
        // (which let whoever reads the session store test candidates for the DSN password)
        $plain = [];
        foreach (array_keys(Config::DEFAULTS) as $name) {
            $plain[$name] = $a->get($name);
        }
        $plain['imap_host'] = $values['imap_host'];
        self::assertNotSame(substr(hash('sha256', serialize($plain)), 0, 32), $a->fingerprint());
        $plain['include_host_config'] = null;
        self::assertNotSame(substr(hash('sha256', serialize($plain)), 0, 32), $a->fingerprint());

        // every change of the configuration - the password included - still changes it at once
        $changed = self::merged(['galbridge_db_dsn' => 'pgsql://galbridge:Secret-Two@127.0.0.1/vmail', 'imap_host' => 'tls://127.0.0.1:143', 'des_key' => 'server-key-AAAAAAAAAAAAAA'], []);
        self::assertNotSame($a->fingerprint(), $changed->fingerprint());
        self::assertNotSame($a->fingerprint(), self::merged($values + ['des_key' => 'server-key-AAAAAAAAAAAAAA', 'galbridge_max_results' => 499], [])->fingerprint());
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

    private static function factory(Config $config, string $login, string $mailHost, string $storageHost, int &$connects = 0, int $port = Env::IMAP_PORT): Factory
    {
        $_SESSION = [];
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            $connects++;
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, $mailHost, $storageHost, $port);
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
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
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
