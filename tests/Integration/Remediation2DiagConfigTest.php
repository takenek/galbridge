<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass: what diag says about the configuration, the secrets around it and the
 * files Composer leaves.
 *
 *  F-07 FAIL for an unknown galbridge_* option or ROUNDCUBE_GALBRIDGE_* variable (with the closest
 *       name), for a second plugin configuration file that Roundcube ignores and for one that does
 *       not load; the "enabled books" line says that every book is disabled; INFO "policy lists" with
 *       the size of the hidden and excluded lists;
 *  F-09 FAIL for galbridge_db_dsn in dont_override (Roundcube writes the DSN into user preferences),
 *       WARN for other galbridge_* names, and the users with galbridge_* keys in their stored
 *       preferences are counted (FAIL for the DSN) - counts only, never a value;
 *  F-10 FAIL for a backup copy of the plugin directory that an interrupted Composer update left,
 *       WARN for a configuration inside the plugin directory of a Composer installation;
 *  F-30 WARN for TLS-loosening switches and the wildcard opt-in while they are on - also unused - for
 *       an enabled password plugin whose DSN is unknown and for a short or non-string des_key; INFO
 *       "refused roles".
 *
 * Every test fails on 1.0.1. Tests that start bin/galbridge.sh run it as root with --allow-root when
 * the suite runs as root (CliRun).
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2DiagConfigTest extends TestCase
{
    /** a value that must never be printed (synthetic) */
    private const PLACEHOLDER = 'mysql://placeholder_role:Zq-not-printed@db.example/vmail';

    private string $base = '';

    protected function setUp(): void
    {
        CliRun::roundcube();
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
    }

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    // ------------------------------------------------------------------ F-07

    public function testDiagFailsForAMisspeltOption(): void
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn(), 'galbridge_global_data_domain_exclude' => []]);
        [$code, $out, $err] = CliRun::diag($config, [], ['ROUNDCUBE_GALBRIDGE_DOMAN_BOOK' => '1']);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'FAIL', 'galbridge_global_data_domain_exclude', 'unknown option (did you mean galbridge_global_data_domains_exclude?)');
        CliRun::assertLine($out, 'FAIL', 'ROUNDCUBE_GALBRIDGE_DOMAN_BOOK', 'unknown environment variable (did you mean ROUNDCUBE_GALBRIDGE_DOMAIN_BOOK?)');
        CliRun::assertLine($out, 'FAIL', 'enabled books', 'none: a configuration error disables every book (see the FAIL lines above)');
        self::assertMatchesRegularExpression('/^Result: \d+ problem\(s\) found$/m', $out);
    }

    /**
     * plugins/galbridge/config.inc.php and config/galbridge.inc.php: Roundcube reads the first only.
     */
    public function testDiagFailsWhenBothConfigFilesExist(): void
    {
        $plugin = CliRun::releaseCopy($this->plugins(), 'galbridge');
        file_put_contents($plugin . '/config.inc.php', "<?php\n// TEST ONLY\n\$config = " . var_export(['galbridge_db_dsn' => $this->dsn()], true) . ";\n");
        chmod($plugin . '/config.inc.php', 0o640);
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_hidden_localparts' => ['postmaster']]);
        [$code, $out, $err] = CliRun::diag($config, [], [], $plugin);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'OK', 'config file', $plugin . '/config.inc.php (mode 0640)');
        CliRun::assertLine($out, 'OK', 'config file', $config . '/galbridge.inc.php, NOT read by Roundcube (mode 0640)');
        CliRun::assertLine($out, 'FAIL', 'galbridge.inc.php', $config . '/galbridge.inc.php is not read: Roundcube loads only ' . $plugin . '/config.inc.php when it exists - keep one of the two files - all books are disabled');
        CliRun::assertLine($out, 'INFO', 'policy lists', 'hidden_addresses 0, hidden_localparts 0,');
    }

    public function testDiagFailsForAPluginConfigFileThatDefinesNothing(): void
    {
        $plugin = CliRun::releaseCopy($this->plugins(), 'galbridge');
        file_put_contents($plugin . '/config.inc.php', "<?php\n// TEST ONLY\n\$settings = ['galbridge_hidden_localparts' => ['postmaster']];\n");
        chmod($plugin . '/config.inc.php', 0o640);
        $config = CliRun::roundcubeConfig($this->base(), []);
        unlink($config . '/galbridge.inc.php');
        // the DSN from the environment: the plugin would run on built-in defaults
        [$code, $out, $err] = CliRun::diag($config, [], ['ROUNDCUBE_GALBRIDGE_DB_DSN' => $this->dsn()], $plugin);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'FAIL', 'config.inc.php', $plugin . '/config.inc.php defines no $config array: Roundcube skips it (error 527) and the plugin would run on built-in defaults - all books are disabled');
        CliRun::assertLine($out, 'FAIL', 'enabled books', 'none: a configuration error disables every book');
    }

    public function testDiagShowsTheSizeOfThePolicyLists(): void
    {
        $out = $this->configuration([
            'galbridge_hidden_addresses' => ['ceo@example.com', 'board@example.com'],
            'galbridge_global_data_domains_exclude' => ['example.org'],
            'galbridge_organizations' => ['Acme' => ['example.com', 'example.net']],
        ]);
        CliRun::assertLine($out, 'INFO', 'policy lists', 'hidden_addresses 2, hidden_localparts 0, domain_viewer_domains_exclude 0, global_viewer_domains_exclude 0, global_data_domains_exclude 1, organizations 1');
        self::assertStringNotContainsString('ceo@', $out, 'counts, never an entry');
        // a list that does not take effect is shown as such
        $out = $this->configuration(['galbridge_hidden_localparts' => 'postmaster']);
        CliRun::assertLine($out, 'INFO', 'policy lists', 'hidden_localparts INVALID');
    }

    // ------------------------------------------------------------------ F-09

    public function testDiagFailsForTheDsnInDontOverride(): void
    {
        $out = $this->configuration([], ['dont_override' => ['addressbook_sort_col', 'galbridge_db_dsn', 'galbridge_hidden_addresses']]);
        CliRun::assertLine($out, 'FAIL', 'dont_override', 'lists galbridge_db_dsn: Roundcube then writes the DSN with the database password into the preferences of every user who saves settings');
        CliRun::assertLine($out, 'WARN', 'dont_override', 'lists galbridge_hidden_addresses: Roundcube stores such an option in the preferences of every user who saves settings');
        $out = $this->configuration([], ['dont_override' => ['addressbook_sort_col']]);
        self::assertStringNotContainsString('dont_override', $out);
    }

    public function testDiagCountsStoredGalbridgePreferences(): void
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()]);
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'OK', 'stored preferences', 'no galbridge_* key in the stored preferences of any user', $err);

        $pdo = new \PDO('sqlite:' . CliRun::roundcubeDb($this->base()));
        $insert = $pdo->prepare("INSERT INTO users (username, mail_host, created, preferences) VALUES (?, '127.0.0.1', '2026-01-01 00:00:00', ?)");
        $insert->execute(['one@example.com', serialize(['galbridge_db_dsn' => self::PLACEHOLDER, 'language' => 'en_US'])]);
        $insert->execute(['two@example.com', serialize(['galbridge_hidden_addresses' => [], 'timezone' => 'auto'])]);
        $insert->execute(['three@example.com', serialize(['timezone' => 'auto', 'signature' => 'galbridge was here'])]);
        $pdo = null;
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'FAIL', 'stored preferences', '1 Roundcube user(s) have galbridge_db_dsn in their stored preferences (written by Roundcube for dont_override): set a new role password');
        CliRun::assertLine($out, 'WARN', 'stored preferences', '1 Roundcube user(s) have other galbridge_* keys in their stored preferences: ignored by GAL Bridge');
        self::assertStringNotContainsString('Zq-not-printed', $out . $err);
        self::assertStringNotContainsString('placeholder_role', $out . $err);
        self::assertStringNotContainsString('one@example.com', $out, 'no user is named');
    }

    // ------------------------------------------------------------------ F-10

    public function testDiagFailsForAComposerBackupNextToThePluginDirectory(): void
    {
        $plugin = CliRun::releaseCopy($this->plugins(), 'galbridge');
        $backup = $this->base() . '/plugins/galbridge-01234567890123456789';
        mkdir($backup, 0o700);
        file_put_contents($backup . '/config.inc.php', "<?php\n\$config = ['galbridge_db_dsn' => " . var_export(self::PLACEHOLDER, true) . "];\n");
        // not the name of a backup of the installer
        mkdir($this->base() . '/plugins/galbridge-old', 0o700);
        mkdir($this->base() . '/plugins/galbridge-0123456789012345678', 0o700);
        $out = $this->installation($plugin);
        CliRun::assertLine($out, 'FAIL', 'composer backup', $backup . ' was left by an interrupted Composer update: it holds a copy of the plugin configuration (the database password) outside the directory your web server rule denies - delete it');
        self::assertSame(1, substr_count($out, 'composer backup'));
        self::assertStringNotContainsString('Zq-not-printed', $out);
        rmdir($this->base() . '/plugins/galbridge-old');
        CliRun::rmTree($backup);
        self::assertStringNotContainsString('composer backup', $this->installation($plugin));
    }

    public function testDiagWarnsWhenComposerManagesThePluginAndItsConfigLivesInside(): void
    {
        $plugin = CliRun::releaseCopy($this->plugins(), 'galbridge');
        mkdir($this->base() . '/vendor/composer', 0o700, true);
        file_put_contents($this->base() . '/vendor/composer/installed.json', (string) json_encode(['packages' => [['name' => 'takenek/galbridge', 'version' => '1.0.1']]]));
        file_put_contents($plugin . '/config.inc.php', "<?php\n\$config = [];\n");
        chmod($plugin . '/config.inc.php', 0o640);
        $etc = $this->base() . '/etc';
        mkdir($etc, 0o700);
        $out = $this->configFile($plugin, $etc);
        CliRun::assertLine($out, 'WARN', 'config file', 'the plugin is installed by Composer and its configuration is plugins/galbridge/config.inc.php: Composer re-creates that file at every install and update with the owner and umask of the user running Composer');
        CliRun::assertLine($out, 'WARN', 'config file', 'move the configuration to config/galbridge.inc.php (owner root, group of the PHP user, mode 0640)');
        // a world-readable copy says why as well
        chmod($plugin . '/config.inc.php', 0o644);
        CliRun::assertLine($this->configFile($plugin, $etc), 'FAIL', 'config file', 'readable by everyone - use 0640 root:<web server group> - Composer re-creates plugins/galbridge/config.inc.php at every install and update');
    }

    public function testNoComposerWarningForAConfigurationOutsideThePluginDirectory(): void
    {
        $plugin = CliRun::releaseCopy($this->plugins(), 'galbridge');
        mkdir($this->base() . '/vendor/composer', 0o700, true);
        file_put_contents($this->base() . '/vendor/composer/installed.json', (string) json_encode([['name' => 'takenek/galbridge']]));
        $etc = $this->base() . '/etc';
        mkdir($etc, 0o700);
        file_put_contents($etc . '/galbridge.inc.php', "<?php\n\$config = [];\n");
        chmod($etc . '/galbridge.inc.php', 0o640);
        $out = $this->configFile($plugin, $etc);
        CliRun::assertLine($out, 'OK', 'config file', $etc . '/galbridge.inc.php (mode 0640)');
        self::assertStringNotContainsString('Composer', $out);
    }

    // ------------------------------------------------------------------ F-30

    public function testDiagWarnsAboutDormantLooseningSwitches(): void
    {
        $out = $this->configuration(['galbridge_db_allow_plaintext' => true, 'galbridge_db_allow_verify_ca' => true, 'galbridge_global_allow_wildcard' => true, 'galbridge_global_data_domains' => ['example.org']]);
        CliRun::assertLine($out, 'WARN', 'db_allow_plaintext', 'on: a remote database host is accepted without verified TLS as soon as the DSN names one');
        CliRun::assertLine($out, 'WARN', 'db_allow_verify_ca', 'on: PostgreSQL sslmode=verify-ca (no host name check) is accepted');
        CliRun::assertLine($out, 'WARN', 'global_allow_wildcard', "on while galbridge_global_data_domains is not ['*']");
        $out = $this->configuration(['galbridge_global_allow_wildcard' => true, 'galbridge_global_data_domains' => ['*']]);
        self::assertDoesNotMatchRegularExpression('/\[WARN\] global_allow_wildcard/', $out, 'in use: the WARN about the wildcard in effect applies');
        self::assertStringNotContainsString('[WARN] db_allow', $this->configuration([]));
    }

    public function testDiagWarnsWhenThePasswordPluginDsnIsUnknown(): void
    {
        $out = $this->configuration([], ['plugins' => ['galbridge', 'password']]);
        CliRun::assertLine($out, 'WARN', 'password plugin', 'enabled with the sql driver, but no password_db_dsn was found');
        self::assertDoesNotMatchRegularExpression('/\[WARN\] password plugin/', $this->configuration([], ['plugins' => ['galbridge', 'password'], 'password_db_dsn' => 'mysql://pwchanger:x@127.0.0.1/vmail']));
        self::assertDoesNotMatchRegularExpression('/\[WARN\] password plugin/', $this->configuration([], ['plugins' => ['galbridge', 'password'], 'password_driver' => 'ldap']));
        self::assertDoesNotMatchRegularExpression('/\[WARN\] password plugin/', $this->configuration([], ['plugins' => ['galbridge']]));
    }

    public function testDiagWarnsAboutAShortOrNonStringDesKey(): void
    {
        CliRun::assertLine($this->configuration([], ['des_key' => 'short']), 'WARN', 'des_key', 'is shorter than 24 characters: set a random 24-character des_key');
        CliRun::assertLine($this->configuration([], ['des_key' => 123456789012345678]), 'WARN', 'des_key', 'is not a string (int)');
        CliRun::assertLine($this->configuration([], ['des_key' => 'rcmail-!24ByteDESkey*Str']), 'WARN', 'des_key', "is Roundcube's published default");
        self::assertStringNotContainsString('des_key', $this->configuration([], ['des_key' => 'TESTONLY-a-random-24-chr']));
    }

    public function testDiagShowsTheRefusedRoles(): void
    {
        $out = $this->configuration(['galbridge_db_refused_users' => ['Legacy_GAL']]);
        CliRun::assertLine($out, 'INFO', 'refused roles', 'built in: ' . implode(', ', Config::DEFAULTS['galbridge_db_refused_users']) . '; added: legacy_gal - plus the users of db_dsnw, db_dsnr');
        $out = $this->configuration(['galbridge_db_refused_users' => ['legacy_gal', 42]]);
        CliRun::assertLine($out, 'FAIL', 'galbridge_db_refused_users', 'must be a list of role names (entry 2 is not): no database connection');
        self::assertStringNotContainsString('refused roles', $out);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The Configuration section of diag in this process, as diag() builds it: Roundcube's
     * configuration from a private directory (RCUBE_CONFIG_PATH) with config.inc.php ($core) and
     * galbridge.inc.php ($plugin), the plugin's files loaded like rcube_plugin::load_config().
     *
     * @param array<string, mixed> $plugin
     * @param array<string, mixed> $core
     */
    private function configuration(array $plugin, array $core = []): string
    {
        $dir = CliRun::tempBase('galbridge-r2diagcfg');
        try {
            $core += ['plugins' => ['galbridge'], 'des_key' => 'TESTONLY-cli-0123456789ab', 'imap_host' => '127.0.0.1:143', 'log_dir' => $dir . '/'];
            file_put_contents($dir . '/config.inc.php', "<?php\n\$config = " . var_export($core, true) . ";\n");
            file_put_contents($dir . '/galbridge.inc.php', "<?php\n\$config = " . var_export(['galbridge_db_dsn' => $this->dsn()] + $plugin, true) . ";\n");
            return $this->withConfig($dir, function (\rcube $rc, Tool $tool): void {
                $pluginDir = $this->base() . '/no-plugin-dir';
                Config::loadFiles($rc->config, Config::pluginFiles($rc->config, $pluginDir));
                $cfg = new Config($rc->config, [], null, $pluginDir);
                $cfg->validate();
                (new \ReflectionMethod(Tool::class, 'configuration'))->invoke($tool, $cfg, $rc);
            }, CliRun::pluginDir());
        } finally {
            CliRun::rmTree($dir);
        }
    }

    /**
     * The configuration-file checks of diag in this process (configuration directory $etc).
     */
    private function configFile(string $plugin, string $etc): string
    {
        return $this->withConfig($etc, static function (\rcube $rc, Tool $tool): void {
            (new \ReflectionMethod(Tool::class, 'configFile'))->invoke($tool, $rc);
        }, $plugin);
    }

    private function installation(string $plugin): string
    {
        clearstatcache();
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        (new \ReflectionMethod(Tool::class, 'installation'))->invoke(new Tool(\rcube::get_instance(), $plugin, $out), '');
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /**
     * Run $check with Roundcube's configuration object read from $dir (restored afterwards, with PHP's
     * error_log setting, which rcube_config changes).
     *
     * @param \Closure(\rcube, Tool): void $check
     */
    private function withConfig(string $dir, \Closure $check, string $pluginDir): string
    {
        clearstatcache();
        putenv('RCUBE_CONFIG_PATH=' . $dir);
        $rc = \rcube::get_instance();
        $saved = $rc->config;
        $errorLog = (string) ini_get('error_log');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        try {
            $rc->config = new \rcube_config();
            $check($rc, new Tool($rc, $pluginDir, $out));
        } finally {
            $rc->config = $saved;
            putenv('RCUBE_CONFIG_PATH');
            ini_set('error_log', $errorLog);
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private function dsn(): string
    {
        return CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
    }

    /** <base>/plugins: where Roundcube's plugin installer puts the plugin */
    private function plugins(): string
    {
        if (!is_dir($this->base() . '/plugins')) {
            mkdir($this->base() . '/plugins', 0o700);
        }
        return $this->base() . '/plugins';
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2diagconfig');
        }
        return $this->base;
    }
}
