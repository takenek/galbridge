<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-06: the options of the core are read as Roundcube applies them.
 *
 * GAL Bridge used to read imap_host, include_host_config, des_key, db_dsnw and password_db_dsn from
 * a SECOND reading of the configuration files (a fresh rcube_config), which misses what a file pulled
 * in with include_once/require_once set, has no environment name and built the plugin's path without
 * the slash Roundcube adds. Now:
 *  - core options come from Roundcube's own object (Config::coreValue()); the second reading only for
 *    a name a stored preference shadows (also through the legacy name default_host);
 *  - a derived IMAP binding is refused when the two readings disagree on imap_host;
 *  - include_host_config counts when any reading enables it (a preference cannot);
 *  - the fingerprint is keyed with the des_key Roundcube uses;
 *  - the database users of Roundcube and of the password plugin are refused from every reading;
 *  - the plugin's files are found like Roundcube finds them (slash, environment suffix);
 *  - diag names (never prints) the options a second reading loses, and environment files.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2CoreConfigTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private string $base = '';

    /** @var list<string> environment variables to unset in tearDown() */
    private array $env = [];

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
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name) {
            putenv($name);
        }
        $this->env = [];
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
     * Roundcube applies one imap_host, a second reading of the files gives another (a file included
     * once, an environment-specific file, another plugin): no directory server is derived from either.
     */
    #[DataProvider('engines')]
    public function testNoBindingIsDerivedWhenRoundcubesImapHostDiffersFromTheReread(string $dsn): void
    {
        $config = self::config(['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143'], ['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.2:143']);
        self::assertStringContainsString('differs from the configuration files read again', (string) ViewerResolver::bindingProblem($config));
        self::assertNull(ViewerResolver::allowedImapHosts($config));
        $connects = 0;
        $factory = self::factory($config, $connects);
        self::assertSame([], $factory->books());
        self::assertSame('disabled', $factory->resolver()->status());
        self::assertSame(0, $connects);
        self::assertStringContainsString('plugin disabled', implode("\n", $GLOBALS['galbridge_test_log']));
        self::assertContains('imap_host', $config->divergentOptions());

        // an explicit list does not depend on imap_host
        $listed = ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143']];
        $config = self::config($listed + ['imap_host' => 'tls://127.0.0.1:143'], $listed + ['imap_host' => 'tls://127.0.0.2:143']);
        self::assertSame([BookDefinition::DOMAIN], array_keys(self::factory($config)->books()));

        // both readings agree: derived as before
        $same = ['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://127.0.0.1:143'];
        self::assertSame(['127.0.0.1:143'], ViewerResolver::allowedImapHosts(self::config($same, $same)));
    }

    /**
     * A stored preference named imap_host (or default_host, which Roundcube maps to imap_host) is no
     * administrator value: the second reading is used for it.
     */
    public function testAUserPreferenceNamedImapHostFallsBackToTheReread(): void
    {
        $admin = ['imap_host' => 'tls://127.0.0.1:143'];
        foreach (['imap_host', 'default_host'] as $name) {
            $prefs = [$name => 'ssl://imap.evil.example:993'];
            // Roundcube merged the preference: its object holds the user's value
            $config = self::config(['imap_host' => 'ssl://imap.evil.example:993'], $admin, $prefs);
            self::assertSame('tls://127.0.0.1:143', $config->coreValue('imap_host'), $name);
            self::assertNull(ViewerResolver::bindingProblem($config), $name);
            self::assertSame(['127.0.0.1:143'], ViewerResolver::allowedImapHosts($config), $name);
            self::assertNotContains('imap_host', $config->divergentOptions(), 'a shadowed name is not compared');
        }
        // without a preference the value of Roundcube's own object counts
        self::assertSame('tls://127.0.0.9:143', self::config(['imap_host' => 'tls://127.0.0.9:143'], $admin)->coreValue('imap_host'));
    }

    /**
     * include_host_config: Roundcube's own object or the second reading - either one makes an
     * explicit host list necessary. A preference cannot (the core picks the files before it knows the
     * user), neither on nor off.
     */
    public function testIncludeHostConfigCountsWhenEitherReadingEnablesIt(): void
    {
        $base = ['imap_host' => 'tls://127.0.0.1:143'];
        foreach ([[true, false], [false, true], [['webmail.example' => 'webmail.inc.php'], false]] as [$roundcube, $again]) {
            $config = self::config($base + ['include_host_config' => $roundcube], $base + ['include_host_config' => $again]);
            self::assertTrue($config->hostConfigEnabled());
            self::assertStringContainsString('include_host_config is enabled', (string) ViewerResolver::bindingProblem($config));
            self::assertNull(ViewerResolver::allowedImapHosts($config));
        }
        $off = $base + ['include_host_config' => false];
        $config = self::config($base + ['include_host_config' => true], $off, ['include_host_config' => true]);
        self::assertFalse($config->hostConfigEnabled(), 'a preference of that name');
        self::assertSame(['127.0.0.1:143'], ViewerResolver::allowedImapHosts($config));
        self::assertTrue(self::config($off, $base + ['include_host_config' => true], ['include_host_config' => false])->hostConfigEnabled(), 'nor switch it off');
    }

    /**
     * The fingerprint in sessions is an HMAC keyed with the des_key Roundcube itself uses - not the one
     * of a second reading, which may be the published default when the real key comes from a file
     * included once or an environment-specific file. A preference named des_key changes nothing.
     */
    public function testTheFingerprintIsKeyedWithTheDesKeyRoundcubeUses(): void
    {
        $admin = ['galbridge_db_dsn' => 'x', 'imap_host' => 'tls://127.0.0.1:143', 'des_key' => 'rcmail-!24ByteDESkey*Str'];
        $a = self::config(['des_key' => 'server-key-AAAAAAAAAAAAAA'] + $admin, $admin);
        $b = self::config(['des_key' => 'server-key-BBBBBBBBBBBBBB'] + $admin, $admin);
        self::assertNotSame($a->fingerprint(), $b->fingerprint());
        // a stored preference named des_key: Roundcube's object holds it, the second reading is used
        $real = ['des_key' => 'server-key-AAAAAAAAAAAAAA'] + $admin;
        $pref = self::config(['des_key' => 'user-chosen-key-XXXXXXXX'] + $admin, $real, ['des_key' => 'user-chosen-key-XXXXXXXX']);
        self::assertSame($a->fingerprint(), $pref->fingerprint());
    }

    /**
     * Roundcube's database users (db_dsnw, db_dsnr) and the password plugin's (password_db_dsn) are
     * refused as GAL Bridge's role whichever reading of the configuration names them - nothing is
     * connected to decide that.
     */
    public function testRoundcubesOwnDatabaseUsersAreRefusedFromEveryReading(): void
    {
        $values = [
            'db_dsnw' => 'pgsql://rc_writer:x@127.0.0.1/roundcube',
            'db_dsnr' => 'pgsql://rc_reader:x@127.0.0.1/roundcube',
            'password_db_dsn' => 'mysql://pw_changer:x@127.0.0.1/vmail',
        ];
        $plain = ['imap_host' => 'tls://127.0.0.1:143'];
        foreach ([[$plain + $values, $plain], [$plain, $plain + $values]] as $i => [$roundcube, $again]) {
            $config = self::config($roundcube, $again);
            foreach (['rc_writer', 'rc_reader', 'pw_changer'] as $role) {
                try {
                    new Dsn('pgsql://' . $role . ':pw@127.0.0.1/vmail', $config);
                    self::fail($role . ' was accepted (' . ($i === 0 ? "Roundcube's object" : 'second reading') . ')');
                } catch (GalBridgeException $e) {
                    self::assertSame('config_invalid', $e->getReason());
                    self::assertStringContainsString('refused database role "' . $role . '"', $e->getMessage());
                }
            }
            // another role is fine
            self::assertSame('pgsql', (new Dsn('pgsql://galbridge:pw@127.0.0.1/vmail', $config))->driver());
        }
        self::assertSame(array_values(array_unique(array_values($values))), array_values(array_intersect($values, self::config($plain + $values, $plain)->coreDsns())));
    }

    /**
     * The password plugin's own file (password.inc.php from a configuration directory, as
     * rcube_plugin::load_config() finds it when plugins/password has no config.inc.php) names a user
     * that is refused as well.
     */
    public function testThePasswordPluginsOwnFileIsRead(): void
    {
        $dir = $this->base() . '/conf';
        mkdir($dir, 0o700);
        file_put_contents($dir . '/password.inc.php', "<?php\n\$config['password_db_dsn'] = 'mysql://pw_file_user:x@127.0.0.1/vmail';\n");
        $this->putenv('RCUBE_CONFIG_PATH', $dir);
        $rc = new \rcube_config();
        $config = new Config($rc, [], ['imap_host' => 'tls://127.0.0.1:143']);
        self::assertSame('mysql://pw_file_user:x@127.0.0.1/vmail', $config->passwordPluginDsn());
        $this->expectExceptionMessage('refused database role "pw_file_user"');
        new Dsn('pgsql://pw_file_user:pw@127.0.0.1/vmail', $config);
    }

    /**
     * RCUBE_PLUGINS_DIR may be defined without the trailing slash: Roundcube adds it
     * (slashify()), and so does GAL Bridge - its own file was missed before.
     */
    public function testThePluginsDirectoryIsSlashedLikeRoundcube(): void
    {
        foreach (['/srv/roundcube/plugins', '/srv/roundcube/plugins/', '/srv/roundcube/plugins//'] as $defined) {
            $code = 'require ' . var_export(CliRun::pluginDir() . '/lib/autoload.php', true) . '; define("RCUBE_PLUGINS_DIR", ' . var_export($defined, true) . '); echo GalBridge\Config::pluginsDir();';
            [$exit, $out, $err] = CliRun::exec([PHP_BINARY, '-n', '-r', $code]);
            self::assertSame(0, $exit, $err);
            self::assertSame('/srv/roundcube/plugins/', $out, $defined);
        }
        // the plugin's own file is found whether or not its directory is written with a slash
        $home = $this->base() . '/galbridge';
        mkdir($home, 0o700);
        file_put_contents($home . '/config.inc.php', "<?php\n\$config['galbridge_max_results'] = 77;\n");
        $resolver = new \rcube_config();
        foreach ([$home, $home . '/'] as $dir) {
            self::assertSame([$home . '/config.inc.php'], Config::pluginFiles($resolver, $dir));
            self::assertSame(77, (new Config(new \rcube_config(), ['galbridge_max_results' => 5000], null, $dir))->get('galbridge_max_results'), 'the second reading loads it');
        }
    }

    /**
     * Roundcube with an environment name ("test") loads config-test.inc.php INSTEAD of config.inc.php
     * for the plugin as well: the plugin's files are resolved by Roundcube's own object, so its
     * environment applies to the second reading.
     */
    public function testThePluginFilesFollowRoundcubesEnvironment(): void
    {
        $home = $this->base() . '/galbridge';
        mkdir($home, 0o700);
        file_put_contents($home . '/config.inc.php', "<?php\n\$config['galbridge_max_results'] = 77;\n");
        file_put_contents($home . '/config-test.inc.php', "<?php\n\$config['galbridge_max_results'] = 88;\n");
        self::assertSame([$home . '/config-test.inc.php'], Config::pluginFiles(new \rcube_config('test'), $home));
        self::assertSame([$home . '/config.inc.php'], Config::pluginFiles(new \rcube_config(), $home));
        $config = new Config(new \rcube_config('test'), ['galbridge_max_results' => 5000], null, $home);
        self::assertSame(88, $config->get('galbridge_max_results'), 'a preference falls back to the file Roundcube loads');
    }

    /**
     * A value set in a file that config.inc.php pulls in with include_once: Roundcube's object has it,
     * a second reading in the same process does not. Decisions use Roundcube's value; the derived
     * binding is refused; the names are reported, never the values.
     */
    public function testAFileIncludedOnceDoesNotChangeTheDecisions(): void
    {
        $dir = $this->base() . '/conf';
        mkdir($dir, 0o700);
        file_put_contents($dir . '/config.inc.php', "<?php\n\$config['imap_host'] = 'tls://127.0.0.1:143';\ninclude_once __DIR__ . '/shared.php';\n");
        file_put_contents($dir . '/shared.php', "<?php\n\$config['imap_host'] = 'tls://127.0.0.9:143';\n\$config['galbridge_hidden_addresses'] = ['postmaster@example.com'];\n");
        $this->putenv('RCUBE_CONFIG_PATH', $dir);
        $home = $this->base() . '/galbridge';
        mkdir($home, 0o700);
        $rc = new \rcube_config();
        $config = new Config($rc, [], null, $home);

        self::assertSame('tls://127.0.0.9:143', $config->coreValue('imap_host'));
        self::assertSame(['postmaster@example.com'], $config->hiddenAddresses());
        self::assertStringContainsString('differs from the configuration files read again', (string) ViewerResolver::bindingProblem($config));
        self::assertNull(ViewerResolver::allowedImapHosts($config), 'never the 127.0.0.1 of the second reading');
        $names = $config->divergentOptions();
        self::assertContains('imap_host', $names);
        self::assertContains('galbridge_hidden_addresses', $names);
        self::assertStringNotContainsString('postmaster@', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    /**
     * diag names the options a second reading of the files loses - never their values - and the
     * environment-specific files Roundcube would use instead of the plain ones.
     */
    public function testDiagNamesWhatASecondReadingLoses(): void
    {
        $dsn = CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
        $base = $this->base();
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], [], "include_once __DIR__ . '/shared.php';\n");
        file_put_contents($config . '/shared.php', "<?php\n\$config['galbridge_hidden_addresses'] = ['postmaster@example.com'];\n");
        chmod($config . '/shared.php', 0o640);
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'WARN', 'include_once', $config . '/config.inc.php pulls in a file with include_once/require_once', $err);
        if (!CliRun::isRoot()) {
            // as root galbridge.sh reads the configuration once before Roundcube starts, so Roundcube's
            // object of the tool lacks the value as well - the line above covers that case
            CliRun::assertLine($out, 'WARN', 'configuration read again', 'differs for galbridge_hidden_addresses - set in a file that the configuration includes with include_once/require_once', $err);
        }
        self::assertStringNotContainsString('postmaster@', $out);
        self::assertStringNotContainsString('environment file', $out);

        file_put_contents($config . '/config-staging.inc.php', "<?php\n\$config = [];\n");
        chmod($config . '/config-staging.inc.php', 0o640);
        [, $out] = CliRun::diag($config);
        CliRun::assertLine($out, 'WARN', 'environment file', $config . '/config-staging.inc.php: Roundcube uses such a file INSTEAD of the plain one');

        // a plain configuration: no such line
        CliRun::rmTree($base);
        mkdir($base, 0o700);
        [, $out] = CliRun::diag(CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn]));
        self::assertStringNotContainsString('configuration read again', $out);
        self::assertStringNotContainsString('include_once', $out);
        self::assertStringContainsString('== IMAP host binding', $out);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Config with Roundcube's object holding $roundcube and the second reading $again.
     *
     * @param array<string, mixed> $roundcube
     * @param array<string, mixed> $again
     * @param array<string, mixed> $prefs
     */
    private static function config(array $roundcube, array $again, array $prefs = []): Config
    {
        $rc = new \rcube_config();
        foreach ($roundcube as $k => $v) {
            $rc->set($k, $v);
        }
        return new Config($rc, $prefs, $again);
    }

    private static function factory(Config $config, int &$connects = 0): Factory
    {
        $_SESSION = [];
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            $connects++;
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $resolver = new ViewerResolver($config, $connect, 1, self::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
    }

    private function putenv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->env[] = $name;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2core');
        }
        return $this->base;
    }
}
