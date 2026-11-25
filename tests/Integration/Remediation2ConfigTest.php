<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\DeniedBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\PluginBoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass: the configuration in effect is the one the administrator wrote, or no
 * book is offered.
 *
 *  F-07 a plugin configuration file that exists but does not load (unreadable, no $config array), a
 *       galbridge.inc.php that Roundcube ignores next to plugins/galbridge/config.inc.php, and a
 *       galbridge_* option or ROUNDCUBE_GALBRIDGE_* variable this version does not know each disable
 *       every book (log config_invalid) - before, the plugin ran on built-in defaults, i.e. without
 *       the hidden lists and exclusions the administrator meant;
 *  F-29 config_invalid names the position of a rejected list entry, never the entry (a hidden
 *       address is a person the administrator wanted hidden); an autocomplete request no longer logs
 *       denied_source for an enabled book the user is not entitled to (the plugin itself added it);
 *  F-30 the built-in refused database roles cannot be removed and a malformed list refuses every
 *       connection; numbers are whole numbers within their range or reported; the fingerprint kept
 *       in sessions does not depend on the database password.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
// (root-aware: the unreadable-file case has a branch of its own as root, run by CI's root step)
#[Group('root-aware')]
final class Remediation2ConfigTest extends TestCase
{
    private string $base = '';

    /** @var list<string> environment variables to unset in tearDown() */
    private array $env = [];

    private ?PluginBoot $boot = null;

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
        foreach ($this->env as $name) {
            putenv($name);
        }
        $this->env = [];
        if ($this->boot !== null) {
            $this->boot->restore();
            $this->boot = null;
        }
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
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

    // ------------------------------------------------------------------ F-07 unknown options

    public function testAMisspeltOptionDisablesEveryBook(): void
    {
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn(), 'galbridge_hidden_adresses' => ['ceo@example.com']]);
        self::assertTrue($config->booksDisabled());
        self::assertTrue($config->configUnreliable());
        self::assertSame(['galbridge_hidden_adresses'], $config->unknownOptions());
        $problem = self::problem($config, 'galbridge_hidden_adresses');
        self::assertSame('fail', $problem['level']);
        self::assertStringContainsString('unknown option (did you mean galbridge_hidden_addresses?)', $problem['message']);
        self::assertStringContainsString('all books are disabled', $problem['message']);
        self::assertStringContainsString('config_invalid', implode("\n", $GLOBALS['galbridge_test_log']));
        // without a close name there is no suggestion
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn(), 'galbridge_something_else_entirely' => 1]);
        self::assertStringNotContainsString('did you mean', self::problem($config, 'galbridge_something_else_entirely')['message']);
        // and every book is refused before anything connects
        self::assertSame([], Env::factory($config, CliRun::JAN)->books());
        self::assertSame('config_invalid', self::resolve($config));
    }

    /**
     * Roundcube's own configuration object is looked at as well (a file the second reading misses);
     * a stored preference of the user never is - a user cannot disable the directory.
     */
    public function testUnknownOptionsComeFromTheAdministratorOnly(): void
    {
        \rcube::get_instance();
        $rc = new \rcube_config();
        $rc->set('galbridge_db_dsn', Env::sqliteDsn());
        $rc->set('galbridge_max_reslts', 50);
        $rc->set('galbridge_pref_typo', true);
        $config = new Config($rc, ['galbridge_pref_typo' => true], ['galbridge_db_dsn' => Env::sqliteDsn()]);
        self::assertSame(['galbridge_max_reslts'], $config->unknownOptions());
        self::assertStringContainsString('did you mean galbridge_max_results?', self::problem($config, 'galbridge_max_reslts')['message']);
    }

    public function testAMisspeltEnvironmentVariableDisablesEveryBook(): void
    {
        $this->putenv('ROUNDCUBE_GALBRIDGE_HIDEN_LOCALPARTS', '[]');
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn()]);
        self::assertTrue($config->booksDisabled());
        $problem = self::problem($config, 'ROUNDCUBE_GALBRIDGE_HIDEN_LOCALPARTS');
        self::assertSame('fail', $problem['level']);
        self::assertStringContainsString('unknown environment variable (did you mean ROUNDCUBE_GALBRIDGE_HIDDEN_LOCALPARTS?)', $problem['message']);
        putenv('ROUNDCUBE_GALBRIDGE_HIDEN_LOCALPARTS');

        // Roundcube reads ROUNDCUBE_<OPTION IN UPPER CASE> only: another spelling changes nothing
        $this->putenv('ROUNDCUBE_galbridge_debug', '1');
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn()]);
        self::assertTrue($config->booksDisabled());
        self::assertStringContainsString('Roundcube reads only the spelling ROUNDCUBE_GALBRIDGE_DEBUG', self::problem($config, 'ROUNDCUBE_galbridge_debug')['message']);
        putenv('ROUNDCUBE_galbridge_debug');

        // a known variable is no problem
        $this->putenv('ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT', '0');
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn()]);
        self::assertSame([], $config->unknownOptions());
        self::assertFalse($config->booksDisabled());
    }

    public function testAKnownOptionIsNoProblem(): void
    {
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn()] + Config::DEFAULTS);
        $config->validate();
        self::assertSame([], $config->unknownOptions());
        self::assertFalse($config->booksDisabled());
        self::assertSame([], $config->problems());
        // every option the shipped template sets is one of this version
        $dist = (string) file_get_contents(CliRun::pluginDir() . '/config.inc.php.dist');
        preg_match_all("/^\\\$config\\['(galbridge_[a-z_]+)'\\]/m", $dist, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $name) {
            self::assertArrayHasKey($name, Config::DEFAULTS, $name);
        }
    }

    // ------------------------------------------------------------------ F-07 configuration files

    public function testAPluginConfigFileWithoutConfigArrayDisablesEveryBook(): void
    {
        $dir = $this->pluginDir("<?php\n// TEST ONLY\n\$settings = ['galbridge_hidden_addresses' => ['ceo@example.com']];\n");
        $config = new Config(new \rcube_config(), [], null, $dir);
        self::assertTrue($config->booksDisabled());
        self::assertSame([$dir . '/config.inc.php'], $config->fileProblems());
        self::assertSame(['read' => [$dir . '/config.inc.php'], 'ignored' => [], 'failed' => [$dir . '/config.inc.php']], $config->configFiles());
        $problem = self::problem($config, 'config.inc.php');
        self::assertSame('fail', $problem['level']);
        self::assertStringContainsString($dir . '/config.inc.php defines no $config array', $problem['message']);
        self::assertStringContainsString('option=config.inc.php', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    public function testAnUnreadablePluginConfigFileDisablesEveryBook(): void
    {
        $dir = $this->pluginDir("<?php\n\$config = ['galbridge_hidden_localparts' => ['postmaster']];\n");
        chmod($dir . '/config.inc.php', 0o000);
        if (CliRun::isRoot()) {
            // root reads every file: the same check in a child process of root without the
            // capabilities that override file permissions (util-linux setpriv) - dropped from the
            // bounding set and from the inheritable set, which a container manager such as
            // systemd-nspawn fills (root regains an inheritable capability at exec)
            if (!is_executable('/usr/bin/setpriv')) {
                self::markTestSkipped('root reads every file, and setpriv is not available');
            }
            $script = $this->base() . '/unreadable.php';
            file_put_contents($script, "<?php\nrequire " . var_export(CliRun::pluginDir() . '/tests/bootstrap.php', true) . ";\n"
                . "\$c = new GalBridge\\Config(new rcube_config(), [], null, \$argv[1]);\n"
                . "echo json_encode(['disabled' => \$c->booksDisabled(), 'problems' => \$c->problems()]);\n");
            [$code, $out, $err] = CliRun::exec(['/usr/bin/setpriv', '--inh-caps=-dac_override,-dac_read_search', '--bounding-set=-dac_override,-dac_read_search', PHP_BINARY, $script, $dir]);
            self::assertSame(0, $code, $out . $err);
            // valid JSON alone: no PHP warning was printed (the file is not handed to rcube_config)
            $result = json_decode($out, true);
            self::assertIsArray($result, $out . $err);
            self::assertTrue($result['disabled'] ?? null);
            self::assertStringContainsString('cannot be read by the PHP user', (string) json_encode($result['problems'] ?? null));
            return;
        }
        $config = new Config(new \rcube_config(), [], null, $dir);
        // no PHP warning either: the file is not handed to rcube_config (PHPUnit fails on a warning)
        self::assertTrue($config->booksDisabled());
        self::assertStringContainsString('cannot be read by the PHP user', self::problem($config, 'config.inc.php')['message']);
    }

    /**
     * rcube_plugin::load_config() reads plugins/galbridge/config.inc.php when it exists and then no
     * galbridge.inc.php of any configuration directory: an administrator who edits the ignored file
     * changes nothing.
     */
    public function testASecondConfigFileDisablesEveryBook(): void
    {
        $dir = $this->pluginDir("<?php\n\$config = ['galbridge_db_dsn' => " . var_export(Env::sqliteDsn(), true) . "];\n");
        $etc = $this->base() . '/etc';
        mkdir($etc, 0o700);
        file_put_contents($etc . '/galbridge.inc.php', "<?php\n\$config = ['galbridge_hidden_localparts' => ['postmaster']];\n");
        $this->putenv('RCUBE_CONFIG_PATH', $etc);
        $config = new Config(new \rcube_config(), [], null, $dir);
        self::assertTrue($config->booksDisabled());
        self::assertSame([$etc . '/galbridge.inc.php'], $config->configFiles()['ignored']);
        $problem = self::problem($config, 'galbridge.inc.php');
        self::assertSame('fail', $problem['level']);
        self::assertStringContainsString($etc . '/galbridge.inc.php is not read: Roundcube loads only ' . $dir . '/config.inc.php when it exists', $problem['message']);
        self::assertNull($config->adminCore('galbridge_hidden_localparts'), 'indeed not read');

        // one file - either of the two - is fine
        unlink($etc . '/galbridge.inc.php');
        $config = new Config(new \rcube_config(), [], null, $dir);
        self::assertSame([], $config->fileProblems());
        self::assertFalse($config->booksDisabled());
        self::assertSame(Env::sqliteDsn(), $config->adminCore('galbridge_db_dsn'), 'the plugin directory of the caller is read');
    }

    /**
     * The production path: the plugin builds its Config from Roundcube's configuration and its own
     * directory. A config.inc.php without $config - the DSN comes from the environment - gives no
     * book and no connection; config_invalid names the file (Roundcube itself raises its error 527,
     * which a command line process writes to standard error - silenced here).
     */
    #[DataProvider('engines')]
    public function testThePluginOffersNoBookWhenItsConfigFileCannotBeLoaded(string $dsn): void
    {
        $this->boot = new PluginBoot();
        $home = $this->boot->home();
        file_put_contents($home . '/config.inc.php', "<?php\n// TEST ONLY\n\$cfg = ['galbridge_hidden_localparts' => ['postmaster']];\n");
        $this->putenv('ROUNDCUBE_GALBRIDGE_DB_DSN', $dsn);
        if (!defined('ROUNDCUBE_STDERR_DISABLE')) {
            define('ROUNDCUBE_STDERR_DISABLE', true);
        }
        $core = ['imap_host' => 'tls://' . Env::IMAP_HOST . ':' . Env::IMAP_PORT];
        $plugin = $this->boot->boot($core, 'addressbook', 'list', [], null, CliRun::JAN, false);
        $sources = $plugin->addressbooks_list(['sources' => []])['sources'];
        self::assertSame([], array_values(array_filter(array_keys($sources), static fn (int|string $id): bool => Factory::isOwnId((string) $id))));
        self::assertSame('config_invalid', $this->boot->resolver()->status());
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('config_invalid', $log);
        self::assertStringContainsString('option=config.inc.php', $log);

        // the same plugin with a loadable file offers the domain book
        file_put_contents($home . '/config.inc.php', "<?php\n// TEST ONLY\n\$config = ['galbridge_hidden_localparts' => ['postmaster'], 'galbridge_imap_hosts' => ['127.0.0.1:143']];\n");
        $plugin = $this->boot->boot($core, 'addressbook', 'list', [], null, CliRun::JAN, false);
        self::assertArrayHasKey(BookDefinition::DOMAIN, $plugin->addressbooks_list(['sources' => []])['sources']);
    }

    /**
     * Compose does not wire a book into autocomplete when a configuration error disables every book.
     */
    public function testNoAutocompleteWiringWhileEveryBookIsDisabled(): void
    {
        $this->boot = new PluginBoot();
        $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://127.0.0.1:143', 'galbridge_hiden_localparts' => []], 'mail', 'compose');
        self::assertSame(['sql'], (array) $this->boot->rc->config->get('autocomplete_addressbooks', ['sql']));
        $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://127.0.0.1:143'], 'mail', 'compose');
        self::assertSame(['sql', BookDefinition::DOMAIN], $this->boot->rc->config->get('autocomplete_addressbooks'));
    }

    // ------------------------------------------------------------------ F-29 problems without entries

    public function testConfigProblemsNamePositionsNotEntries(): void
    {
        $config = self::config([
            'galbridge_db_dsn' => Env::sqliteDsn(),
            'galbridge_hidden_addresses' => ['ceo@example.com', 'Hidden Person Name'],
            'galbridge_hidden_localparts' => ['postmaster', 'not a local part'],
            'galbridge_global_viewer_domains' => ['example.com', 'bad domain!'],
            'galbridge_global_data_domains_exclude' => ['example.org', 'bad exclusion!', 'another bad!'],
            'galbridge_organizations' => ['Acme' => ['example.com', 'bad org domain!']],
            'galbridge_fields' => ['name', 'secretfield'],
        ]);
        $config->validate();
        $messages = array_column($config->problems(), 'message', 'option');
        self::assertSame('malformed deny-list (entry 2 cannot be normalised): all books are disabled', $messages['galbridge_hidden_addresses'] ?? null);
        self::assertSame('malformed deny-list (entry 2 cannot be normalised): all books are disabled', $messages['galbridge_hidden_localparts'] ?? null);
        self::assertSame('entry 2 dropped (cannot be normalised)', $messages['galbridge_global_viewer_domains'] ?? null);
        self::assertSame('malformed deny-list (entries 2, 3 cannot be normalised): the protected book is disabled', $messages['galbridge_global_data_domains_exclude'] ?? null);
        self::assertSame('organization 1: entry 2 dropped (cannot be normalised)', $messages['galbridge_organizations'] ?? null);
        self::assertStringStartsWith('entry 2 dropped (not one of ', $messages['galbridge_fields'] ?? '');
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        foreach (['Hidden Person Name', 'not a local part', 'bad domain', 'bad exclusion', 'another bad', 'bad org domain', 'secretfield'] as $entry) {
            self::assertStringNotContainsString($entry, $log);
            self::assertStringNotContainsString($entry, implode("\n", array_column($config->problems(), 'message')));
        }
        // the other forms of a malformed list
        $config = self::config(['galbridge_db_dsn' => Env::sqliteDsn(), 'galbridge_hidden_addresses' => 'ceo@example.com', 'galbridge_hidden_localparts' => ['*', 'postmaster']]);
        $config->validate();
        $messages = array_column($config->problems(), 'message', 'option');
        self::assertSame('malformed deny-list (not a list (string given)): all books are disabled', $messages['galbridge_hidden_addresses'] ?? null);
        self::assertSame("malformed deny-list ('*' mixed with other entries): all books are disabled", $messages['galbridge_hidden_localparts'] ?? null);
    }

    // ------------------------------------------------------------------ F-29 denied_source

    /**
     * Roundcube's autocomplete asks every book the plugin added to autocomplete_addressbooks - every
     * enabled book. A user who is not entitled to the global book is no denied request there (debug
     * only); a request that names the book itself, and every other action, still logs it.
     */
    #[DataProvider('engines')]
    public function testAutocompleteOfABookTheUserIsNotEntitledToIsNoDenial(string $dsn): void
    {
        $config = Env::config($dsn, ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['example.org'], 'galbridge_global_data_domains' => ['example.org']]);
        $denials = static fn (): array => array_values(array_filter((array) $GLOBALS['galbridge_test_log'], static fn (mixed $l): bool => is_string($l) && str_contains($l, 'denied_source')));

        self::assertInstanceOf(DeniedBook::class, self::factory($config, 'autocomplete')->create(BookDefinition::GLOBAL));
        self::assertSame([], $denials());

        // with galbridge_debug the reason is there
        Log::setDebug(true);
        self::factory($config, 'autocomplete')->create(BookDefinition::GLOBAL);
        self::assertCount(1, $denials());
        self::assertStringStartsWith('DEBUG book: denied_source', $denials()[0]);
        Log::setDebug(false);

        foreach ([['autocomplete', true], ['list', false], ['search', false]] as [$action, $named]) {
            $GLOBALS['galbridge_test_log'] = [];
            Log::resetOnce();
            self::factory($config, $action, $named)->create(BookDefinition::GLOBAL);
            self::assertCount(1, $denials(), $action);
            self::assertStringStartsWith('INFO book: denied_source: source not available for this user', $denials()[0]);
            self::assertStringContainsString('status=ok', $denials()[0]);
        }
    }

    // ------------------------------------------------------------------ F-30 refused roles

    public function testBuiltInRoleNamesCannotBeRemoved(): void
    {
        $config = self::config(['galbridge_db_refused_users' => ['Only_This']]);
        self::assertSame([...Config::DEFAULTS['galbridge_db_refused_users'], 'only_this'], $config->refusedUsers());
        foreach (['vmail', 'vmailadmin', 'root', 'postgres', 'only_this'] as $role) {
            self::assertRefused('pgsql://' . $role . ':pw-Zq@127.0.0.1/vmail', $config, 'refused database role');
        }
        self::assertSame('galbridge', (new Dsn('pgsql://galbridge:pw-Zq@127.0.0.1/vmail', $config))->user());
        // an empty list adds nothing and removes nothing
        $empty = self::config(['galbridge_db_refused_users' => []]);
        self::assertSame(Config::DEFAULTS['galbridge_db_refused_users'], $empty->refusedUsers());
        self::assertSame([], $empty->problems());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedRoleLists(): array
    {
        return [
            'a number among names' => [['legacy_gal', 42]],
            'a nested list' => [[['root']]],
            'a blank entry' => [['legacy_gal', ' ']],
            'a string' => ['vmail,legacy_gal'],
            'a boolean' => [true],
        ];
    }

    #[DataProvider('malformedRoleLists')]
    public function testAMalformedRefusedRoleListRefusesEveryConnection(mixed $value): void
    {
        $config = self::config(['galbridge_db_refused_users' => $value]);
        self::assertNull($config->refusedUsers());
        self::assertRefused('pgsql://galbridge:pw-Zq@127.0.0.1/vmail', $config, 'galbridge_db_refused_users is malformed');
        $problem = self::problem($config, 'galbridge_db_refused_users');
        self::assertSame('fail', $problem['level']);
        self::assertStringContainsString('no database connection', $problem['message']);
        if (is_array($value)) {
            self::assertStringContainsString('entry ' . (array_key_last($value) + 1) . ' is not', $problem['message']);
        }
    }

    // ------------------------------------------------------------------ F-30 numbers

    /**
     * @return array<string, array{mixed}>
     */
    public static function notWholeNumbers(): array
    {
        return [
            'a float' => [250.0], 'a fraction' => ['2.5'], 'an exponent' => ['1e3'], 'INF' => [INF], 'NAN' => [NAN],
            'a boolean' => [true], 'text after digits' => ['12abc'], 'a plus sign' => ['+500'], 'an array' => [[500]],
        ];
    }

    #[DataProvider('notWholeNumbers')]
    public function testNumbersMustBeWholeNumbers(mixed $value): void
    {
        $config = self::config(['galbridge_max_count' => $value]);
        self::assertSame(10000, $config->int('galbridge_max_count', 100, 1000000));
        $problem = self::problem($config, 'galbridge_max_count');
        self::assertSame('error', $problem['level']);
        self::assertStringContainsString('not a whole number', $problem['message']);
        self::assertStringContainsString('the default 10000 is used', $problem['message']);
    }

    public function testNumbersOutsideTheirRangeAreReported(): void
    {
        $config = self::config(['galbridge_max_export' => '300', 'galbridge_max_ids' => ' 150 ', 'galbridge_min_search_length' => 0, 'galbridge_max_results' => 999999]);
        self::assertSame(300, $config->int('galbridge_max_export', 1, 100000), 'a string of digits');
        self::assertSame(150, $config->int('galbridge_max_ids', 1, 1000), 'blanks around it');
        self::assertSame(1, $config->int('galbridge_min_search_length', 1, 10));
        self::assertSame([['level' => 'error', 'option' => 'galbridge_min_search_length', 'message' => 'outside 1..10: 1 is used']], $config->problems());
        // validate() checks the declared ranges (diag)
        $config->validate();
        self::assertSame('outside 10..5000: 5000 is used', self::problem($config, 'galbridge_max_results')['message']);
        self::assertCount(2, $config->problems());

        // a variable is judged by its text (Roundcube makes a float of "-5" and "1e3")
        $this->putenv('ROUNDCUBE_GALBRIDGE_MAX_IDS', '-5');
        $this->putenv('ROUNDCUBE_GALBRIDGE_MAX_GROUPS', '1e3');
        $this->putenv('ROUNDCUBE_GALBRIDGE_MAX_EXPORT', '700');
        $config = self::config([]);
        self::assertSame(1, $config->int('galbridge_max_ids', 1, 1000));
        self::assertSame('outside 1..1000: 1 is used', self::problem($config, 'galbridge_max_ids')['message']);
        self::assertSame(50, $config->int('galbridge_max_groups', 1, 500));
        self::assertSame('not a whole number (string given): the default 50 is used', self::problem($config, 'galbridge_max_groups')['message']);
        self::assertSame(700, $config->int('galbridge_max_export', 1, 100000));
    }

    // ------------------------------------------------------------------ F-30 fingerprint

    public function testTheFingerprintDoesNotDependOnTheDatabasePassword(): void
    {
        $fp = static fn (mixed $dsn): string => self::config(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143', 'des_key' => 'TESTONLY-fingerprint-key0'])->fingerprint();
        $a = $fp('pgsql://galbridge:Zq-one@127.0.0.1/vmail?sslmode=disable');
        self::assertSame($a, $fp('pgsql://galbridge:Zq-two@127.0.0.1/vmail?sslmode=disable'), 'another password');
        self::assertSame($a, $fp(' pgsql://galbridge:Zq-two@127.0.0.1/vmail?sslmode=disable '), 'blanks around the DSN');
        foreach ([
            'another role' => 'pgsql://galbridge2:Zq-one@127.0.0.1/vmail?sslmode=disable',
            'another host' => 'pgsql://galbridge:Zq-one@127.0.0.2/vmail?sslmode=disable',
            'another port' => 'pgsql://galbridge:Zq-one@127.0.0.1:5433/vmail?sslmode=disable',
            'another database' => 'pgsql://galbridge:Zq-one@127.0.0.1/vmail2?sslmode=disable',
            'another parameter' => 'pgsql://galbridge:Zq-one@127.0.0.1/vmail?sslmode=verify-full',
            'another driver' => 'mysql://galbridge:Zq-one@127.0.0.1/vmail?sslmode=disable',
        ] as $what => $dsn) {
            self::assertNotSame($a, $fp($dsn), $what);
        }
        self::assertNotSame($fp(['x']), $fp(''), 'a value that is not a string');
        self::assertSame($fp(['x']), $fp(['y']), 'represented by its type');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A configuration whose administrator values are $admin (injected: the second reading).
     *
     * @param array<string, mixed> $admin
     */
    private static function config(array $admin): Config
    {
        \rcube::get_instance();
        $rc = new \rcube_config();
        foreach ($admin as $k => $v) {
            $rc->set($k, $v);
        }
        return new Config($rc, [], $admin);
    }

    /**
     * @return array{level: string, option: string, message: string}
     */
    private static function problem(Config $config, string $option): array
    {
        $config->validate();
        foreach ($config->problems() as $p) {
            if ($p['option'] === $option) {
                return $p;
            }
        }
        self::fail('no problem reported for ' . $option . ': ' . json_encode($config->problems()));
    }

    private static function resolve(Config $config): string
    {
        $resolver = new ViewerResolver($config, static fn (): Connection => throw new \LogicException('no connection may be made'), 1, CliRun::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        self::assertNull($resolver->resolve());
        return $resolver->status();
    }

    private static function factory(Config $config, string $action, bool $sourceNamed = false): Factory
    {
        $_SESSION = [];
        $connection = null;
        $connect = static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, 1, CliRun::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l, $action, null, $sourceNamed);
    }

    private static function assertRefused(string $dsn, Config $config, string $reason): void
    {
        try {
            new Dsn($dsn, $config);
            self::fail('accepted: ' . preg_replace('~:[^:@]*@~', ':***@', $dsn));
        } catch (GalBridgeException $e) {
            self::assertSame('config_invalid', $e->getReason());
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * A private plugin directory holding config.inc.php with the given code.
     */
    private function pluginDir(string $code): string
    {
        $dir = $this->base() . '/plugins/galbridge';
        mkdir($dir, 0o700, true);
        file_put_contents($dir . '/config.inc.php', $code);
        return $dir;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2config');
        }
        return $this->base;
    }

    private function putenv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->env[] = $name;
    }
}
