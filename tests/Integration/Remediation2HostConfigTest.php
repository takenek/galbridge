<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-08: with include_host_config, Roundcube merges a configuration file
 * chosen by the Host header of the request - by the client. A galbridge_* option in such a file
 * (hidden lists, viewer lists, the global book ...) would be a directory policy the user picks by
 * sending another host name. Now every galbridge_* option of the request is compared with the
 * host-independent configuration (config.inc.php and the plugin's files): a difference disables every
 * book (fail closed, config_invalid naming the option); only the two book names may differ. diag
 * merges each file a request can select and names the options it sets, and checks its imap_host.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2HostConfigTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private string $base = '';

    /** @var list<string> environment variables to unset in tearDown() */
    private array $env = [];

    private mixed $httpHost = null;

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
        $this->httpHost = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name) {
            putenv($name);
        }
        $this->env = [];
        if ($this->httpHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->httpHost;
        }
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    /**
     * The global book switched on by a file only some host names select: no book at all for such a
     * request (never the wider policy of the client's choice).
     */
    #[DataProvider('engines')]
    public function testAPolicyOptionOnlyAPerHostFileSetsDisablesEveryBook(string $dsn): void
    {
        $independent = self::independent($dsn);
        $perHost = ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $config = self::config($independent + $perHost, $independent);
        self::assertSame(['galbridge_global_book', 'galbridge_global_viewer_domains', 'galbridge_global_data_domains'], $config->hostDependentOptions());
        self::assertTrue($config->booksDisabled());
        $fails = array_column(array_filter($config->problems(), static fn (array $p): bool => $p['level'] === 'fail'), 'option');
        self::assertContains('galbridge_global_book', $fails);
        $connects = 0;
        $factory = self::factory($config, $connects);
        self::assertSame([], $factory->books());
        self::assertSame('config_invalid', $factory->resolver()->status());
        self::assertSame(0, $connects, 'not even the database is asked (a per-host file could name another DSN)');
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('config_invalid: differs from the host-independent configuration', $log);
        self::assertStringContainsString('option=galbridge_global_book', $log);
        self::assertStringNotContainsString('example.org', $log, 'names, never values');
    }

    /**
     * The same policy in the host-independent files is the administrator's: accepted.
     */
    #[DataProvider('engines')]
    public function testTheSamePolicyFromTheHostIndependentFilesIsAccepted(string $dsn): void
    {
        $values = self::independent($dsn) + ['galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $config = self::config($values, $values);
        self::assertSame([], $config->hostDependentOptions());
        self::assertFalse($config->booksDisabled());
        self::assertSame([BookDefinition::DOMAIN, BookDefinition::GLOBAL], array_keys(self::factory($config)->books()));
    }

    public function testBookNamesMayDifferPerHost(): void
    {
        $independent = self::independent(Env::sqliteDsn());
        $config = self::config($independent + ['galbridge_domain_book_name' => 'Webmail A', 'galbridge_global_book_name' => 'All of A'], $independent);
        self::assertSame([], $config->hostDependentOptions());
        self::assertSame([BookDefinition::DOMAIN], array_keys(self::factory($config)->books()));
    }

    /**
     * Without include_host_config nothing depends on the host name: nothing is compared, normal
     * installations are not affected.
     */
    public function testWithoutIncludeHostConfigNothingIsCompared(): void
    {
        $independent = ['include_host_config' => false] + self::independent(Env::sqliteDsn());
        $config = self::config($independent + ['galbridge_hidden_addresses' => ['postmaster@example.com']], $independent);
        self::assertFalse($config->hostConfigEnabled());
        self::assertSame([], $config->hostDependentOptions());
        self::assertFalse($config->booksDisabled());
    }

    /**
     * ROUNDCUBE_INCLUDE_HOST_CONFIG set at all: what the core does cannot be told, so the comparison
     * runs as well.
     */
    public function testTheEnvironmentVariableAlsoTriggersTheComparison(): void
    {
        $this->putenv('ROUNDCUBE_INCLUDE_HOST_CONFIG', '0');
        $independent = ['include_host_config' => false] + self::independent(Env::sqliteDsn());
        $config = self::config($independent + ['galbridge_global_book' => true], $independent);
        self::assertTrue($config->hostConfigEnabled());
        self::assertSame(['galbridge_global_book'], $config->hostDependentOptions());
        self::assertTrue($config->booksDisabled());
    }

    /**
     * A per-host file that EMPTIES a hidden list: every book is disabled - never "shown without
     * hiding".
     */
    #[DataProvider('engines')]
    public function testAHiddenListFromAPerHostFileIsNotApplied(string $dsn): void
    {
        $independent = self::independent($dsn) + ['galbridge_hidden_addresses' => ['postmaster@example.com']];
        $config = self::config(['galbridge_hidden_addresses' => []] + $independent, $independent);
        self::assertSame(['galbridge_hidden_addresses'], $config->hostDependentOptions());
        self::assertSame([], self::factory($config)->books());
        $books = (new Factory($config, new ViewerResolver($config, self::connect($config), 1, self::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT), self::connect($config), static fn (string $l): string => $l))->books();
        self::assertSame([], $books);
    }

    /**
     * The real files: config.inc.php with include_host_config, a per-host file for one host name and
     * the plugin's own file. A request for that host name gets no books; another one is unaffected.
     */
    public function testThePerHostFileOfTheRequestIsFoundInTheRealFiles(): void
    {
        $dir = $this->base() . '/conf';
        mkdir($dir, 0o700);
        $main = ['include_host_config' => ['webmail.example' => 'webmail.inc.php'], 'imap_host' => 'tls://127.0.0.1:143'];
        file_put_contents($dir . '/config.inc.php', "<?php\n\$config = " . var_export($main, true) . ";\n");
        file_put_contents($dir . '/webmail.inc.php', "<?php\n\$config['galbridge_hidden_localparts'] = [];\n\$config['galbridge_domain_book_name'] = 'Webmail';\n");
        $home = $this->base() . '/galbridge';
        mkdir($home, 0o700);
        $plugin = ['galbridge_db_dsn' => Env::sqliteDsn(), 'galbridge_imap_hosts' => ['127.0.0.1:143'], 'galbridge_hidden_localparts' => ['postmaster']];
        file_put_contents($home . '/config.inc.php', "<?php\n\$config = " . var_export($plugin, true) . ";\n");
        $this->putenv('RCUBE_CONFIG_PATH', $dir);

        // the plugin file sets the list itself: the per-host file cannot change it
        $_SERVER['HTTP_HOST'] = 'webmail.example';
        self::assertSame([], self::request($home)->hostDependentOptions());

        // without it in the plugin file, the per-host value would be in effect for that host only
        unset($plugin['galbridge_hidden_localparts']);
        $main['galbridge_hidden_localparts'] = ['postmaster'];
        file_put_contents($home . '/config.inc.php', "<?php\n\$config = " . var_export($plugin, true) . ";\n");
        file_put_contents($dir . '/config.inc.php', "<?php\n\$config = " . var_export($main, true) . ";\n");
        $config = self::request($home);
        self::assertSame([], $config->hiddenLocalparts(), 'Roundcube applied the per-host file');
        self::assertSame(['galbridge_hidden_localparts'], $config->hostDependentOptions());
        self::assertTrue($config->booksDisabled());

        $_SERVER['HTTP_HOST'] = 'other.example';
        $config = self::request($home);
        self::assertSame(['postmaster'], $config->hiddenLocalparts());
        self::assertSame([], $config->hostDependentOptions());
        self::assertFalse($config->booksDisabled());
    }

    /**
     * diag merges every file a request can select and names the galbridge_* options it sets (never
     * their values), and checks its imap_host: covered by the directory servers, and the transport.
     */
    public function testDiagChecksEveryPerHostFile(): void
    {
        $dsn = CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
        $base = $this->base();
        $plugin = ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143'], 'galbridge_hidden_addresses' => ['postmaster@example.com']];
        $config = CliRun::roundcubeConfig($base, $plugin, ['include_host_config' => ['webmail.example' => 'webmail.inc.php', 'books.example' => 'books.inc.php']]);
        // galbridge.inc.php is the plugin's file here: a per-host file sets what that file does not
        file_put_contents($config . '/galbridge.inc.php', "<?php\n\$config = " . var_export(array_diff_key($plugin, ['galbridge_hidden_addresses' => 1]), true) . ";\n");
        file_put_contents($config . '/config.inc.php', "\$config['galbridge_hidden_addresses'] = ['postmaster@example.com'];\n", FILE_APPEND);
        file_put_contents($config . '/webmail.inc.php', "<?php\n\$config['galbridge_hidden_addresses'] = [];\n\$config['imap_host'] = 'imap.example.net:143';\n");
        file_put_contents($config . '/books.inc.php', "<?php\n\$config['galbridge_domain_book_name'] = 'Books';\n");
        foreach (['webmail.inc.php', 'books.inc.php'] as $f) {
            chmod($config . '/' . $f, 0o640);
        }
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        self::assertStringContainsString('== Per-host configuration', $out);
        CliRun::assertLine($out, 'INFO', 'per-host files', '2 file(s) a request can select (include_host_config lists them)');
        CliRun::assertLine($out, 'FAIL', 'per-host configuration', 'webmail.inc.php: sets galbridge_hidden_addresses - requests with this host name get no books');
        CliRun::assertLine($out, 'INFO', 'per-host configuration', 'books.inc.php: sets galbridge_domain_book_name (book names may differ per host)');
        CliRun::assertLine($out, 'WARN', 'imap_host imap.example.net', 'webmail.inc.php: not in galbridge_imap_hosts');
        CliRun::assertLine($out, 'FAIL', 'IMAP transport imap.example.net', 'webmail.inc.php: the IMAP connection is not encrypted (no ssl:// or tls:// scheme)');
        CliRun::assertLine($out, 'WARN', 'include_host_config', 'see "Per-host configuration" below');
        self::assertStringNotContainsString('postmaster@', $out);

        // include_host_config = true: <host name>.inc.php - any *.inc.php file of the directory
        CliRun::rmTree($base);
        mkdir($base, 0o700);
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143']], ['include_host_config' => true]);
        file_put_contents($config . '/webmail.example.inc.php', "<?php\n\$config['galbridge_global_book'] = true;\n");
        chmod($config . '/webmail.example.inc.php', 0o640);
        [$code, $out] = CliRun::diag($config);
        self::assertSame(2, $code, $out);
        CliRun::assertLine($out, 'INFO', 'per-host files', 'include_host_config is not a list: every *.inc.php file of the configuration directories, named by the Host header');
        CliRun::assertLine($out, 'FAIL', 'per-host configuration', 'webmail.example.inc.php: sets galbridge_global_book');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The host-independent part of a configuration with include_host_config.
     *
     * @return array<string, mixed>
     */
    private static function independent(string $dsn): array
    {
        return ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143'], 'imap_host' => 'tls://127.0.0.1:143', 'include_host_config' => true];
    }

    /**
     * Roundcube's object of a request holds $request; $independent stands for the host-independent
     * files (and the second reading).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $independent
     */
    private static function config(array $request, array $independent): Config
    {
        $rc = new \rcube_config();
        foreach ($request as $k => $v) {
            $rc->set($k, $v);
        }
        return new Config($rc, [], $independent);
    }

    /**
     * Roundcube's object as a request builds it ($_SERVER['HTTP_HOST'] selects the per-host file),
     * the plugin's file loaded like rcube_plugin::load_config().
     */
    private static function request(string $home): Config
    {
        $rc = new \rcube_config();
        Config::loadFiles($rc, Config::pluginFiles($rc, $home));
        return new Config($rc, [], null, $home);
    }

    /**
     * @return \Closure(): Connection
     */
    private static function connect(Config $config): \Closure
    {
        $connection = null;
        return static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
    }

    private static function factory(Config $config, int &$connects = 0): Factory
    {
        $_SESSION = [];
        $connect = self::connect($config);
        $counting = static function () use ($connect, &$connects): Connection {
            $connects++;
            return $connect();
        };
        return new Factory($config, new ViewerResolver($config, $counting, 1, self::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT), $counting, static fn (string $l): string => $l);
    }

    private function putenv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->env[] = $name;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2host');
        }
        return $this->base;
    }
}
