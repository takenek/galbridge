<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-27: diag claims no more than it checked.
 *
 *  (a) the configuration files Roundcube really loads - galbridge.inc.php from EVERY configuration
 *      directory, the core files - with the owner of their directories and the directories above;
 *      the plugin code recursively, also files the PHP user owns, with the directories above it and
 *      Roundcube's plugins/ directory; files inside the release directories;
 *  (b) it says that its environment lines describe the command line process, lists the core options
 *      that variables of this process override, and the web process logs the names of the
 *      ROUNDCUBE_GALBRIDGE_* variables in effect there (env_override, once per session);
 *  (c) a check that could not run is a WARN, --strict counts WARN lines as problems, and the summary
 *      says when the web access was not checked;
 *  (d) an exception or a PHP fatal error during Roundcube's start, or an exit() of other code, ends
 *      with "Result: aborted" and exit code 2 (core's handler ended a fatal error with 0 on PHP 8.4
 *      and 8.5), and an unexpected error while connecting is a FAIL line of its section.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2DiagTest extends TestCase
{
    private const NOBODY = 65534;

    private string $base = '';

    /** @var list<string> environment variables to unset in tearDown() */
    private array $env = [];

    protected function setUp(): void
    {
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        $_SESSION = [];
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

    // ------------------------------------------------------------------ (a) files

    /**
     * A directory of another account than root holds the plugin configuration: that account can
     * replace the file, whatever its own mode and owner say.
     */
    public function testTheConfigurationDirectoryMustBelongToRoot(): void
    {
        $dir = $this->configDirectory(['galbridge.inc.php' => 0o640]);
        if (CliRun::isRoot()) {
            chown($dir, self::NOBODY);
        }
        $owner = (int) fileowner($dir);
        $out = $this->configFile($dir);
        CliRun::assertLine($out, 'WARN', 'config directory', $dir . ' is owned by uid ' . $owner . ($owner === Tool::euid() ? ' (the user running this tool, i.e. the PHP user)' : '') . ': that account can replace the configuration files in it');
        if (!CliRun::isRoot()) {
            return;
        }
        // root's directory - but the directory above it belongs to another account
        chown($dir, 0);
        chown($this->base(), self::NOBODY);
        chmod($this->base(), 0o755);
        $out = $this->configFile($dir);
        self::assertDoesNotMatchRegularExpression('/\[WARN\] config directory +' . preg_quote($dir, '/') . ' is owned/', $out);
        CliRun::assertLine($out, 'WARN', 'config directory', $this->base() . ' (above ' . $dir . ', owner uid ' . self::NOBODY . ', mode 0755) is not root\'s alone');
        chown($this->base(), 0);
        $out = $this->configFile($dir);
        self::assertStringNotContainsString('config directory', $out, 'root\'s alone, and so is every directory above it');
    }

    /**
     * rcube_plugin::load_config() loads galbridge.inc.php from EVERY configuration directory
     * (RCUBE_CONFIG_PATH as well): each one is checked, not only the first.
     */
    public function testEveryConfigurationPathIsChecked(): void
    {
        $dir = $this->configDirectory(['galbridge.inc.php' => 0o644]);
        $out = $this->configFile($dir);
        CliRun::assertLine($out, 'FAIL', 'config file', $dir . '/galbridge.inc.php (mode 0644: readable by everyone');
        self::assertStringNotContainsString('neither plugins/galbridge/config.inc.php nor config/galbridge.inc.php exists', $out);
    }

    /**
     * The core files can set galbridge_* options, des_key and the plugins as well.
     */
    public function testTheCoreConfigurationFileIsChecked(): void
    {
        $dir = $this->configDirectory(['galbridge.inc.php' => 0o640, 'config.inc.php' => 0o660]);
        $out = $this->configFile($dir);
        CliRun::assertLine($out, 'WARN', 'core config file', $dir . '/config.inc.php is writable by group/others (mode 0660)');
        if (CliRun::isRoot()) {
            chmod($dir . '/config.inc.php', 0o640);
            chown($dir . '/config.inc.php', self::NOBODY);
            $out = $this->configFile($dir);
            CliRun::assertLine($out, 'WARN', 'core config file', $dir . '/config.inc.php is owned by uid ' . self::NOBODY . ': that account can change every option');
        } else {
            CliRun::assertLine($out, 'WARN', 'core config file', $dir . '/config.inc.php is owned by uid ' . Tool::euid() . ' (the user running this tool, i.e. the PHP user)');
        }
    }

    public function testPluginCodeIsCheckedRecursively(): void
    {
        $plugin = CliRun::releaseCopy($this->base(), 'galbridge');
        $deep = $plugin . '/lib/GalBridge/Db/Connection.php';
        if (CliRun::isRoot()) {
            $out = $this->installation($plugin);
            CliRun::assertLine($out, 'OK', 'plugin code', 'owned by root and writable by nobody else, with the directories above it');
            chmod($deep, 0o666);
            chown($plugin . '/localization/pl_PL.inc', self::NOBODY);
            $out = $this->installation($plugin);
            CliRun::assertLine($out, 'WARN', 'plugin code', "not root's alone: lib/GalBridge/Db/Connection.php (owner uid 0, mode 0666), localization/pl_PL.inc (owner uid " . self::NOBODY . ', mode 0600)');
            chmod($deep, 0o600);
            chown($plugin . '/localization/pl_PL.inc', 0);
            // the directory the plugin is reached through
            chmod($this->base(), 0o777);
            $out = $this->installation($plugin);
            CliRun::assertLine($out, 'WARN', 'plugin code', "not root's alone: " . $this->base() . ' (owner uid 0, mode 0777)');
            chmod($this->base(), 0o700);
            return;
        }
        // the PHP user OWNS the files: it can change them whatever the mode says (0444)
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugin, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                chmod($file->getPathname(), $file->isDir() ? 0o555 : 0o444);
            }
        }
        chmod($plugin, 0o555);
        $out = $this->installation($plugin);
        CliRun::assertLine($out, 'WARN', 'plugin code', 'this user (uid ' . Tool::euid() . ', the PHP user) can change it: . (owned by this user), galbridge.php (owned by this user)');
        self::assertMatchesRegularExpression('/\[WARN\] plugin code .*more\)/', $out, 'every file is counted');
    }

    /**
     * What a release puts INTO its directories is known as well: a grants output saved next to the
     * templates or an editor's copy of a class is named like a foreign file at the top level.
     */
    public function testFilesInsideReleaseDirectoriesAreCompared(): void
    {
        $plugin = CliRun::releaseCopy($this->base(), 'galbridge');
        CliRun::assertLine($this->installation($plugin), 'OK', 'plugin directory', 'only the files of a release');
        file_put_contents($plugin . '/SQL/grants.mysql.sql.orig', '-- test');
        file_put_contents($plugin . '/lib/GalBridge/notes.txt', 'test');
        mkdir($plugin . '/docs/old');
        file_put_contents($plugin . '/localization/en_US.inc~', '<?php');
        $out = $this->installation($plugin);
        CliRun::assertLine($out, 'WARN', 'plugin directory', 'files that are not part of the plugin: lib/GalBridge/notes.txt, SQL/grants.mysql.sql.orig, localization/en_US.inc~, docs/old/ - keep nothing else here (README 2.6)');
        self::assertStringNotContainsString('only the files of a release', $out);
    }

    /**
     * A huge tree below the plugin (or a link to /) must not stall diag - and what was not looked at
     * is said, not passed over.
     */
    public function testAHugeTreeIsReportedNotWalkedForever(): void
    {
        $plugin = CliRun::releaseCopy($this->base(), 'galbridge');
        mkdir($plugin . '/lib/GalBridge/Many');
        for ($i = 0; $i <= 5000; $i++) {
            touch($plugin . '/lib/GalBridge/Many/F' . $i . '.php');
        }
        $out = $this->installation($plugin);
        CliRun::assertLine($out, 'WARN', 'plugin directory', 'more than 5000 entries below lib/GalBridge/Many');
        CliRun::assertLine($out, 'WARN', 'plugin directory', 'files that are not part of the plugin: lib/GalBridge/Many/F0.php, ');
    }

    /**
     * Tool::RELEASE_TREE and what git exports agree: every tracked file of a release directory is
     * accepted. (A new file kind in bin/, SQL/, localization/ or docs/ needs a decision there.)
     */
    public function testTheReleaseTreeAcceptsWhatGitExports(): void
    {
        $dir = CliRun::pluginDir();
        if (!is_dir($dir . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        [$code, $out] = CliRun::exec(['git', '-C', $dir, 'ls-files', '-z', '--', ...array_keys(Tool::RELEASE_TREE)]);
        self::assertSame(0, $code);
        $files = array_filter(explode("\0", $out));
        self::assertGreaterThan(30, count($files));
        foreach ($files as $file) {
            [$top, $rel] = explode('/', $file, 2);
            self::assertMatchesRegularExpression(Tool::RELEASE_TREE[$top], $rel, $file . ' is part of a release but not accepted by Tool::RELEASE_TREE');
            for ($d = dirname($rel); $d !== '.'; $d = dirname($d)) {
                self::assertMatchesRegularExpression(Tool::RELEASE_TREE[$top], $d, dirname($file) . ' is part of a release but not accepted by Tool::RELEASE_TREE');
            }
        }
    }

    // ------------------------------------------------------------------ (b) environment

    public function testDiagDescribesTheProcessItRunsIn(): void
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()]);
        [, $out, $err] = CliRun::diag($config, [], ['ROUNDCUBE_SKIN' => 'galbridge-test-skin-value']);
        CliRun::assertLine($out, 'INFO', 'process', 'command line PHP (cli) as uid ' . Tool::euid() . ': the PHP version, extensions, php.ini settings and ROUNDCUBE_* / PG* variables reported here are those of this process');
        CliRun::assertLine($out, 'INFO', 'environment', 'no ROUNDCUBE_GALBRIDGE_* variable in this process - the PHP of the web server has its own environment and logs the names of the variables it applies (env_override)');
        CliRun::assertLine($out, 'INFO', 'environment', 'ROUNDCUBE_SKIN overrides skin for this process');
        self::assertStringNotContainsString('galbridge-test-skin-value', $out . $err, 'names only, never a value');
        if (CliRun::isRoot()) {
            CliRun::assertLine($out, 'INFO', 'environment', 'ROUNDCUBE_LOG_DRIVER overrides log_driver for this process (set by galbridge.sh for --allow-root');
        }
        // with an override of the plugin: its WARN, and no "no variable" line
        [, $out] = CliRun::diag($config, [], ['ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT' => '0']);
        CliRun::assertLine($out, 'WARN', 'environment', 'ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT overrides galbridge_domain_export');
        self::assertStringNotContainsString('no ROUNDCUBE_GALBRIDGE_* variable', $out);
    }

    public function testCoreEnvironmentOverridesAreListedByName(): void
    {
        foreach (['ROUNDCUBE_IMAP_HOST' => 'ssl://imap.example.com', 'ROUNDCUBE_GALBRIDGE_DEBUG' => '1', 'ROUNDCUBE_CONFIG_DIR' => '/x', 'ROUNDCUBE_INSTALL_PATH' => '/y', 'ROUNDCUBE_DES_KEY' => 'z'] as $name => $value) {
            $this->putenv($name, $value);
        }
        $names = Config::coreEnvOverrides();
        self::assertContains('imap_host', $names);
        self::assertContains('des_key', $names);
        foreach (['galbridge_debug', 'config_dir', 'install_path'] as $not) {
            self::assertNotContains($not, $names, 'the plugin\'s own (reported separately) and the two that select the installation');
        }
    }

    /**
     * The PHP of the web server has an environment of its own (PHP-FPM pool): the names of the
     * ROUNDCUBE_GALBRIDGE_* variables in effect there are logged once per session - and again when
     * the set changes - never their values.
     */
    public function testTheWebProcessLogsEnvironmentOverridesOncePerSession(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $this->bootPlugin();
        self::assertSame([], self::logged('env_override'), 'no override, no line');

        $this->putenv('ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT', '0');
        $this->bootPlugin();
        $this->bootPlugin();
        $lines = self::logged('env_override');
        self::assertCount(1, $lines, 'once per session');
        self::assertStringContainsString('INFO config: env_override: ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT override options of this plugin in the PHP of the web server (names only)', $lines[0]);

        $this->putenv('ROUNDCUBE_GALBRIDGE_DB_DSN', 'mysql://role:' . 'secret-value@db/vmail');
        $this->bootPlugin();
        $lines = self::logged('env_override');
        self::assertCount(2, $lines, 'again when the set changes');
        self::assertStringContainsString('ROUNDCUBE_GALBRIDGE_DB_DSN, ROUNDCUBE_GALBRIDGE_DOMAIN_EXPORT', $lines[1]);
        self::assertStringNotContainsString('secret-value', implode("\n", $GLOBALS['galbridge_test_log']));
    }

    // ------------------------------------------------------------------ (c) result

    public function testStrictTurnsWarningsIntoExitCode2(): void
    {
        $dsn = CliRun::serverDsn();
        if ($dsn === null) {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN (diag runs without FAIL)');
        }
        // a WARN of its own: the fixture role has no connection limit
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn], ['addressbook_sort_col' => 'surname']);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(0, $code, $out . $err);
        self::assertMatchesRegularExpression('/\nResult: OK with (\d+) warning\(s\) - read the WARN lines \(not checked: web access - run with --url\)\n$/', $out);
        $warnings = substr_count($out, '[WARN]');

        [$code, $out] = CliRun::diag($config, ['--strict']);
        self::assertSame(2, $code, $out);
        self::assertStringEndsWith("\nResult: {$warnings} warning(s) - --strict counts them as problems (not checked: web access - run with --url)\n", $out);
        self::assertStringNotContainsString('[FAIL]', $out);

        // --strict takes no value (a usage error: on standard error since the second verification
        // pass, F-28 - never in the report)
        [$code, $out, $err] = CliRun::diag($config, ['--strict=yes']);
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString("Usage: galbridge.sh diag [--user=EMAIL] [--url=URL] [--cafile=FILE] [--strict]\n--strict takes no value", $err);
    }

    public function testAnUncheckedRoundcubeDatabaseIsAWarning(): void
    {
        // Roundcube's database on a port where nothing listens
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()], ['db_dsnw' => 'mysql://roundcube:pw@127.0.0.1:1/roundcube']);
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'WARN', 'Roundcube users', 'not checked: the Roundcube database is not reachable from this process - the IMAP hosts stored for existing users (users.mail_host) are unknown');
        self::assertStringNotContainsString('[INFO] Roundcube users', $out);
        self::assertStringContainsString('== vmail database', $out, $err);
    }

    // ------------------------------------------------------------------ (d) the end of the program

    public function testAnErrorDuringTheStartOfRoundcubeIsNeverReportedAsOk(): void
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()], [],
            'throw new RuntimeException("galbridge-test: broken configuration \x1b[2J mysql://role:" . "secret-value@db/vmail");' . "\n");
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        self::assertSame("\nerror: RuntimeException: galbridge-test: broken configuration  [2J mysql://role:***@db/vmail\nResult: aborted\n", $out);
        self::assertStringNotContainsString('secret-value', $out . $err);
    }

    public function testAFatalErrorIsNeverReportedAsOk(): void
    {
        // a compile-time fatal error: the configuration is loaded more than once per run, and its
        // function is declared again (core's handler does not even look at this kind)
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()], [],
            "function galbridge_test_declared_twice(): void\n{\n}\n");
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        self::assertMatchesRegularExpression('/\nerror: PHP fatal error: Cannot redeclare (function )?galbridge_test_declared_twice\(\) .*\nResult: aborted\n$/', $out);

        // a fatal error of the engine: the memory limit
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()], [],
            'if (getenv("GALBRIDGE_TEST_EXHAUST") === "1") { ini_set("memory_limit", "24M"); $galbridgeTestFill = str_repeat("x", 64 * 1024 * 1024); }' . "\n");
        [$code, $out, $err] = CliRun::diag($config, [], ['GALBRIDGE_TEST_EXHAUST' => '1']);
        self::assertNotSame(0, $code, 'never the exit code of OK: ' . $out . $err);
        if (self::shutdownRunsAfterMemoryExhaustion()) {
            self::assertSame(2, $code, $out . $err);
            self::assertMatchesRegularExpression('/\nerror: PHP fatal error: Allowed memory size of \d+ bytes exhausted .*\nResult: aborted\n$/', $out);
        }
    }

    /**
     * An unexpected error while the vmail connection is prepared (here: the password plugin's
     * configuration, which the DSN policy reads, throws) is a FAIL line of that section: the other
     * sections, the counts and the summary are kept.
     */
    public function testAnUnexpectedErrorWhileConnectingKeepsTheCounts(): void
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $this->dsn()]);
        file_put_contents($config . '/password.inc.php', '<?php throw new RuntimeException("galbridge-test: unreadable password configuration");');
        chmod($config . '/password.inc.php', 0o640);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        self::assertMatchesRegularExpression('/\n== vmail database\n  \[FAIL\] check aborted +RuntimeException: galbridge-test: unreadable password configuration\n/', $out);
        self::assertMatchesRegularExpression('/\nResult: \d+ problem\(s\) found\n$/', $out);
        self::assertStringNotContainsString('Result: aborted', $out);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A configuration directory with the given files (name => mode) and, through RCUBE_CONFIG_PATH,
     * a Roundcube configuration that reads it.
     *
     * @param array<string, int> $files
     */
    private function configDirectory(array $files): string
    {
        CliRun::roundcube();
        $dir = $this->base() . '/conf';
        mkdir($dir, 0o755);
        foreach ($files as $name => $mode) {
            file_put_contents($dir . '/' . $name, "<?php\n\$config = [];\n");
            chmod($dir . '/' . $name, $mode);
        }
        return $dir;
    }

    /**
     * The configuration-file checks of diag for a Roundcube whose configuration paths are the given
     * directory and the core's own.
     */
    private function configFile(string $dir): string
    {
        clearstatcache();
        $this->putenv('RCUBE_CONFIG_PATH', $dir);
        $rc = \rcube::get_instance();
        $saved = $rc->config;
        $rc->config = new \rcube_config();
        try {
            [$tool, $out] = self::tool();
            (new \ReflectionMethod(Tool::class, 'configFile'))->invoke($tool, $rc);
        } finally {
            $rc->config = $saved;
        }
        return self::read($out);
    }

    /**
     * The installation checks of diag (without --url) for a plugin directory.
     */
    private function installation(string $plugin): string
    {
        clearstatcache();
        [$tool, $out] = self::tool($plugin);
        (new \ReflectionMethod(Tool::class, 'installation'))->invoke($tool, '');
        return self::read($out);
    }

    /**
     * @return array{0: Tool, 1: resource}
     */
    private static function tool(?string $plugin = null): array
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        return [new Tool(\rcube::get_instance(), $plugin ?? CliRun::pluginDir(), $out), $out];
    }

    /**
     * @param resource $out
     */
    private static function read($out): string
    {
        rewind($out);
        $text = (string) stream_get_contents($out);
        fclose($out);
        return $text;
    }

    /** a vmail DSN for runs whose vmail section does not matter (an unreachable one without servers) */
    private function dsn(): string
    {
        return CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
    }

    private function putenv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->env[] = $name;
    }

    /**
     * Does this PHP run shutdown functions after the memory limit was hit? (PHP 8.1 ends the process
     * at once with exit code 1 - also not the exit code of OK.)
     */
    private static function shutdownRunsAfterMemoryExhaustion(): bool
    {
        [, $out] = CliRun::exec([PHP_BINARY, '-r', 'register_shutdown_function(static function (): void { echo "shutdown"; }); ini_set("memory_limit", "24M"); $x = str_repeat("x", 64 * 1024 * 1024);']);
        return str_contains($out, 'shutdown');
    }

    /**
     * @return list<string> log lines with the reason code
     */
    private static function logged(string $code): array
    {
        return array_values(array_filter((array) $GLOBALS['galbridge_test_log'], static fn (mixed $l): bool => is_string($l) && str_contains($l, $code)));
    }

    /**
     * The plugin's init() in a logged-in webmail request of the current session (PluginHooksTest).
     */
    private function bootPlugin(): void
    {
        require_once CliRun::pluginDir() . '/galbridge.php';
        $dsn = Env::sqliteDsn();
        $admin = ['galbridge_db_dsn' => $dsn, 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143'];
        $config = new \rcube_config();
        foreach ($admin as $k => $v) {
            $config->set($k, $v);
        }
        $plugins = $this->base() . '/plugins';
        if (!is_dir($plugins . '/galbridge')) {
            mkdir($plugins . '/galbridge', 0o700, true);
            symlink(CliRun::pluginDir() . '/localization', $plugins . '/galbridge/localization');
        }
        $api = (new \ReflectionClass(\rcube_plugin_api::class))->newInstanceWithoutConstructor();
        $api->dir = $plugins . '/';
        $rc = (new \ReflectionClass(\rcmail::class))->newInstanceWithoutConstructor();
        $rc->config = $config;
        $rc->task = 'addressbook';
        $rc->action = 'show';
        $rc->plugins = $api;
        $user = (new \ReflectionClass(\rcube_user::class))->newInstanceWithoutConstructor();
        $user->ID = 7;
        $user->data = ['user_id' => 7, 'username' => CliRun::JAN, 'mail_host' => Env::IMAP_HOST, 'preferences' => serialize([])];
        $user->prefs = [];
        $rc->user = $user;
        $instance = new \ReflectionProperty(\rcube::class, 'instance');
        $previous = $instance->getValue();
        $instance->setValue(null, $rc);
        try {
            $plugin = new \galbridge($api);
            (new \ReflectionProperty(\galbridge::class, 'config'))->setValue($plugin, new Config($config, [], $admin));
            $plugin->init();
        } finally {
            $instance->setValue(null, $previous);
        }
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2diag');
        }
        return $this->base;
    }
}
