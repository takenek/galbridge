<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\DbAdmin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-36: the residual risks of documented decisions, made visible where
 * code can see them.
 *
 *  (a) a galbridge_imap_hosts entry without a port covers every port of its host (also another
 *      IMAP service there with a user database of its own): diag warns;
 *  (b) the configuration file - the database password - in the plugin directory: without --url diag
 *      warns (it was an INFO); with --url it asks the web server for the configuration file in use
 *      (FAIL when it is served as text, nothing of the answer printed); grants recommends
 *      config/galbridge.inc.php;
 *  (e) diag warns while galbridge_debug is on (also through ROUNDCUBE_GALBRIDGE_DEBUG); an ERROR or
 *      WARN line that Roundcube refuses to write to the galbridge log (per_user_logging without a
 *      log directory of the user) goes to Roundcube's errors log instead of disappearing;
 *  (f) on PostgreSQL diag reports statement logging by an extension (pgaudit, auto_explain) for the
 *      role, which the grant template does not switch off.
 *
 * Every test fails on 1.0.1. The diag cases start bin/galbridge.sh (as root with --allow-root when the
 * suite runs as root, CliRun) against a server DSN.
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2ResidualRiskTest extends TestCase
{
    /** a synthetic DSN of a configuration file a web server hands out (never printed) */
    private const SERVED_DSN = 'mysql://served_role:Zq-web-pw@db.example/vmail';

    private const AUDIT_ROLE = 'galbridge_r2audit';

    private string $base = '';

    /** @var list<resource> */
    private array $servers = [];

    private bool $auditRole = false;

    /** @var null|array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed} */
    private ?array $logConfig = null;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        $this->servers = [];
        if ($this->auditRole) {
            DbAdmin::dropRole('pgsql', DbAdmin::target((string) getenv('GALBRIDGE_TEST_PGSQL_DSN'))[1], self::AUDIT_ROLE);
            $this->auditRole = false;
        }
        if ($this->logConfig !== null) {
            $config = \rcube::get_instance()->config;
            [$perUser, $driver, $dir, $ext, $user] = $this->logConfig;
            \rcube::get_instance()->user = $user;
            $config->set('per_user_logging', $perUser);
            $config->set('log_driver', $driver);
            $config->set('log_dir', $dir);
            $config->set('log_file_ext', $ext);
            unset($_SESSION['username']);
            $this->logConfig = null;
        }
        Log::setSink(static function (string $line): void {
            $GLOBALS['galbridge_test_log'][] = $line;
        });
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    // ------------------------------------------------------------------ (a)

    public function testDiagWarnsAboutAnImapHostEntryWithoutAPort(): void
    {
        $dsn = $this->serverDsn();
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1']]);
        [$code, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'WARN', 'directory hosts', '127.0.0.1 has no port: it covers every IMAP port of that host - write host:port');
        self::assertSame(1, substr_count($out, 'has no port'), $out);
        $withoutPort = $code;

        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143']]);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertStringNotContainsString('has no port', $out . $err);
        self::assertSame(0, $code, $out . $err);
        self::assertSame($code, $withoutPort, 'a warning, never a problem: the exit code is the same');

        // a socket has no port to give, and a derived binding always has one
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['unix:///run/dovecot/imap.sock', '127.0.0.1:143']]);
        [, $out] = CliRun::diag($config);
        self::assertStringNotContainsString('has no port', $out);
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out] = CliRun::diag($config);
        CliRun::assertLine($out, 'OK', 'directory hosts', '127.0.0.1:143 (derived from imap_host)');
        self::assertStringNotContainsString('has no port', $out);
    }

    public function testTheEntrySplitterIsWhatTheBindingUses(): void
    {
        self::assertSame(['127.0.0.1', null], ViewerResolver::splitEntry('127.0.0.1'));
        self::assertSame(['mail.example.com', 993], ViewerResolver::splitEntry('mail.example.com:993'));
        self::assertSame(['::1', null], ViewerResolver::splitEntry('::1'));
        self::assertSame(['::1', 143], ViewerResolver::splitEntry('[::1]:143'));
        // what the WARN says: an entry without a port covers every port, one with a port that one only
        self::assertTrue(ViewerResolver::covers(['127.0.0.1'], '127.0.0.1', 10143));
        self::assertFalse(ViewerResolver::covers(['127.0.0.1:143'], '127.0.0.1', 10143));
    }

    // ------------------------------------------------------------------ (e)

    public function testDiagWarnsWhileDebugLoggingIsOn(): void
    {
        $dsn = $this->serverDsn();
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_debug' => true]);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(0, $code, $out . $err);
        CliRun::assertLine($out, 'WARN', 'debug', 'on (galbridge_debug): the log receives database driver error texts and exception messages, which can contain search words and addresses');

        // the variable that container deployments use, parsed by the plugin itself
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out] = CliRun::diag($config, [], ['ROUNDCUBE_GALBRIDGE_DEBUG' => '1']);
        CliRun::assertLine($out, 'WARN', 'debug', 'on (galbridge_debug)');

        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_debug' => false]);
        [, $out] = CliRun::diag($config);
        self::assertDoesNotMatchRegularExpression('/^  \[WARN\] debug /m', $out);
        // an ambiguous value is read as off (and disables every book: a FAIL line says so)
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_debug' => 'yes']);
        [, $out] = CliRun::diag($config);
        self::assertDoesNotMatchRegularExpression('/^  \[WARN\] debug /m', $out);
        CliRun::assertLine($out, 'INFO', 'switches (controls)', 'debug off (INVALID)');
    }

    public function testErrorAndWarningLinesSurvivePerUserLogging(): void
    {
        $dir = $this->base() . '/logs';
        mkdir($dir, 0o700);
        $this->perUserLogging($dir);

        // no user (or no log directory of the user): Roundcube refuses every galbridge line
        Log::error('viewer', 'role_too_broad: the configured database role can do more than read the directory');
        Log::warning('config', 'config_invalid: galbridge_x', ['option' => 'galbridge_x']);
        Log::info('access', 'denied_source', ['source' => 'galbridge_global']);
        clearstatcache();
        self::assertFileDoesNotExist($dir . '/galbridge.log');
        $errors = (string) @file_get_contents($dir . '/errors.log');
        self::assertStringContainsString('galbridge: ERROR viewer: role_too_broad: the configured database role', $errors);
        self::assertStringContainsString('galbridge: WARN config: config_invalid: galbridge_x option=galbridge_x', $errors);
        self::assertStringNotContainsString('denied_source', $errors, 'INFO lines follow Roundcube\'s decision');

        // a user with a log directory of their own: the galbridge log is written there, nothing else
        mkdir($dir . '/jan', 0o700);
        $_SESSION['username'] = 'jan';
        unlink($dir . '/errors.log');
        Log::error('viewer', 'role_too_broad: second');
        clearstatcache();
        self::assertStringContainsString('ERROR viewer: role_too_broad: second', (string) file_get_contents($dir . '/jan/galbridge.log'));
        self::assertFileDoesNotExist($dir . '/errors.log', 'no copy of a line Roundcube wrote');
    }

    // ------------------------------------------------------------------ (b)

    public function testDiagWarnsWhenTheConfigurationInThePluginDirectoryWasNotCheckedOverHttp(): void
    {
        $dsn = $this->serverDsn();
        [$plugin, $config] = $this->pluginWithConfig($dsn);
        [$code, $out, $err] = CliRun::diag($config, [], [], $plugin);
        CliRun::assertLine($out, 'WARN', 'web access', 'not checked, and the configuration file with the database password and the directory policy lies in the plugin directory: run diag --url=https://<webmail address>/ (README 2.6) or move it to config/galbridge.inc.php');
        self::assertStringNotContainsString('[INFO] web access', $out);
        self::assertMatchesRegularExpression('/^Result: OK with \d+ warning\(s\) - read the WARN lines \(not checked: web access - run with --url\)$/m', $out, $err);
        self::assertSame(0, $code, 'a warning: the exit code stays 0');
        [$code, $out] = CliRun::diag($config, ['--strict'], [], $plugin);
        self::assertSame(2, $code, '--strict counts it');

        // the configuration in the configuration directory: the note stays a note
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out] = CliRun::diag($config);
        CliRun::assertLine($out, 'INFO', 'web access', 'not checked: run diag --url=https://<webmail address>/');
        self::assertStringNotContainsString('lies in the plugin directory', $out);
    }

    public function testDiagFailsWhenTheWebServerHandsOutTheConfigurationFile(): void
    {
        $dsn = $this->serverDsn();
        [$plugin, $config] = $this->pluginWithConfig($dsn);
        $root = $this->webRoot();
        mkdir($root . '/plugins/galbridge', 0o700, true);
        // what a server that maps the plugin directory as static files would hand out
        file_put_contents($root . '/plugins/galbridge/config.inc.php', "<?php\n// TEST ONLY\n\$config['galbridge_db_dsn'] = '" . self::SERVED_DSN . "';\n");
        $port = $this->textServer($root);
        $url = '--url=http://127.0.0.1:' . $port . '/';

        [$code, $out, $err] = CliRun::diag($config, [$url], [], $plugin);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'FAIL', 'config over HTTP', 'plugins/galbridge/config.inc.php is SERVED as text: the database password can be downloaded - deny it in the web server (README 2.6)');
        CliRun::assertLine($out, 'OK', 'config over HTTP', 'static.php/plugins/galbridge/config.inc.php is not served (HTTP 404)');
        foreach (['Zq-web-pw', 'served_role', 'db.example'] as $secret) {
            self::assertStringNotContainsString($secret, $out . $err, 'nothing of the answer is printed');
        }
        self::assertSame(5, substr_count($out, '[ OK ] web access'), 'the webmail and the four plugin paths: the probe has a label of its own');

        // an answer without the text of the file (PHP ran it, or another page)
        file_put_contents($root . '/plugins/galbridge/config.inc.php', '');
        [$code, $out] = CliRun::diag($config, [$url], [], $plugin);
        CliRun::assertLine($out, 'INFO', 'config over HTTP', 'plugins/galbridge/config.inc.php answered HTTP 200 without the text of the file');
        self::assertStringNotContainsString('[FAIL] config over HTTP', $out);

        // not served at all
        unlink($root . '/plugins/galbridge/config.inc.php');
        [$code, $out, $err] = CliRun::diag($config, [$url], [], $plugin);
        CliRun::assertLine($out, 'OK', 'config over HTTP', 'plugins/galbridge/config.inc.php is not served (HTTP 404)');
        self::assertStringNotContainsString('lies in the plugin directory', $out, 'checked: no WARN for an unchecked configuration');

        // an address that is not the webmail proves nothing
        [, $out] = CliRun::diag($config, ['--url=http://127.0.0.1:' . $port . '/nowhere/'], [], $plugin);
        CliRun::assertLine($out, 'WARN', 'config over HTTP', 'plugins/galbridge/config.inc.php: HTTP 404 - inconclusive');
    }

    /**
     * config/galbridge.inc.php of the Roundcube directory is asked for under its web path (and
     * through static.php); a file outside the Roundcube directory has none.
     */
    public function testDiagAsksForTheConfigurationInTheRoundcubeConfigDirectory(): void
    {
        $dsn = $this->serverDsn();
        // a Roundcube directory of its own: links to the core, a real config/ directory
        $rc = $this->base() . '/rc';
        mkdir($rc, 0o700);
        $core = realpath(CliRun::roundcube());
        self::assertIsString($core);
        foreach (scandir($core) ?: [] as $entry) {
            if (!in_array($entry, ['.', '..', 'config', 'logs', 'temp'], true)) {
                symlink($core . '/' . $entry, $rc . '/' . $entry);
            }
        }
        $config = CliRun::roundcubeConfig($rc, ['galbridge_db_dsn' => $dsn]);
        $root = $this->webRoot();
        mkdir($root . '/config', 0o700, true);
        file_put_contents($root . '/config/galbridge.inc.php', "<?php\n// TEST ONLY\n\$config['galbridge_db_dsn'] = '" . self::SERVED_DSN . "';\n");
        $port = $this->textServer($root);
        [$code, $out, $err] = CliRun::script(['diag', '--url=http://127.0.0.1:' . $port . '/'], ['ROUNDCUBE_INSTALL_PATH' => $rc, 'ROUNDCUBE_CONFIG_DIR' => $config]);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'FAIL', 'config over HTTP', 'config/galbridge.inc.php is SERVED as text');
        CliRun::assertLine($out, 'OK', 'config over HTTP', 'static.php/config/galbridge.inc.php is not served (HTTP 404)');
        self::assertStringNotContainsString('Zq-web-pw', $out . $err);

        // the configuration directory of the other tests lies outside the Roundcube directory
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out] = CliRun::diag($config, ['--url=http://127.0.0.1:' . $port . '/']);
        CliRun::assertLine($out, 'INFO', 'config over HTTP', $config . '/galbridge.inc.php lies outside the plugin and the config directory of Roundcube');
    }

    public function testTheDsnLineRecommendsTheConfigurationDirectory(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        self::assertSame(0, (new Tool(null, CliRun::pluginDir(), $out, $err))->run(['grants', 'driver' => 'mysql']));
        rewind($err);
        $errors = (string) stream_get_contents($err);
        self::assertStringStartsWith("\n-- Put this line into config/galbridge.inc.php (or plugins/galbridge/config.inc.php if you keep the configuration there; owner root:<web server group>, mode 0640):\n", $errors);
    }

    // ------------------------------------------------------------------ (f)

    public function testDiagWarnsWhenAnAuditExtensionCanLogTheStatementsOfTheRole(): void
    {
        $fixture = (string) getenv('GALBRIDGE_TEST_PGSQL_DSN');
        if ($fixture === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_PGSQL_DSN');
        }
        [, $db] = DbAdmin::target($fixture);
        $this->auditRole = true;
        // the statement settings of the grant template, so that only the extensions remain
        $dsn = DbAdmin::createRole($fixture, self::AUDIT_ROLE, DbAdmin::TEMPLATE_COLUMNS, [
            "ALTER ROLE %r SET log_statement = 'none'", 'ALTER ROLE %r SET log_min_duration_statement = -1', "ALTER ROLE %r SET log_min_error_statement = 'panic'",
        ]);
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn]);
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'OK', 'statement logging', 'off for this role (its statements contain search terms)');
        self::assertStringNotContainsString('pgaudit', $out, $err);

        [$code, , $error] = DbAdmin::script('pgsql', $db, 'ALTER ROLE ' . self::AUDIT_ROLE . " SET pgaudit.log = 'read'; ALTER ROLE " . self::AUDIT_ROLE . ' SET auto_explain.log_min_duration = 0;', false);
        if ($code !== 0) {
            self::markTestSkipped('the server refuses the settings of pgaudit / auto_explain for a role: ' . $error);
        }
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertLine($out, 'WARN', 'statement logging', 'an extension can record statements of this role with the search terms: pgaudit.log=read, auto_explain.log_min_duration=0 - the grant template does not change these settings');
        self::assertStringNotContainsString('[ OK ] statement logging', $out, $err);
        self::assertStringNotContainsString('the grant template switches this off', $out, 'the core settings are off');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Point Roundcube's file log at $dir with per_user_logging, and Log at Roundcube (no test sink).
     */
    private function perUserLogging(string $dir): void
    {
        $rc = \rcube::get_instance();
        $config = $rc->config;
        $this->logConfig = [$config->get('per_user_logging'), $config->get('log_driver'), $config->get('log_dir'), $config->get('log_file_ext'), $rc->user];
        $config->set('per_user_logging', true);
        $config->set('log_driver', 'file');
        $config->set('log_dir', $dir);
        $config->set('log_file_ext', '.log');
        $rc->user = null;
        unset($_SESSION['username']);
        Log::setSink(null);
    }

    /**
     * A release copy of the plugin with its configuration in plugins/galbridge/config.inc.php, and a
     * configuration directory without galbridge.inc.php.
     *
     * @return array{0: string, 1: string}
     */
    private function pluginWithConfig(string $dsn): array
    {
        mkdir($this->base() . '/plugins', 0o700);
        $plugin = CliRun::releaseCopy($this->base() . '/plugins', 'galbridge');
        file_put_contents($plugin . '/config.inc.php', "<?php\n// TEST ONLY\n\$config = " . var_export(['galbridge_db_dsn' => $dsn], true) . ";\n");
        chmod($plugin . '/config.inc.php', 0o640);
        $config = CliRun::roundcubeConfig($this->base(), []);
        unlink($config . '/galbridge.inc.php');
        return [$plugin, $config];
    }

    /**
     * The document root of a test web server that answers like the webmail at its address.
     */
    private function webRoot(): string
    {
        $root = $this->base() . '/htdocs';
        mkdir($root, 0o700);
        file_put_contents($root . '/index.html', '<html><head><script>var rcmail = new rcube_webmail();</script></head><body></body></html>');
        return $root;
    }

    /**
     * PHP's built-in web server on a free loopback port that hands out EVERY existing file as text -
     * a PHP file too, as a server that maps a directory as static files does. A directory answers
     * with its index.html, anything else is 404.
     */
    private function textServer(string $root): int
    {
        $router = $this->base() . '/router-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($router, <<<'PHP'
            <?php
            $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $file = $_SERVER['DOCUMENT_ROOT'] . $path;
            if (str_contains($path, '..')) {
                http_response_code(400);
                return true;
            }
            if (is_file($file)) {
                header('Content-Type: text/plain');
                readfile($file);
                return true;
            }
            if (is_dir($file) && is_file(rtrim($file, '/') . '/index.html')) {
                header('Content-Type: text/html');
                readfile(rtrim($file, '/') . '/index.html');
                return true;
            }
            http_response_code(404);
            echo 'not found';
            return true;
            PHP);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 45000);
            $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (is_resource($socket)) {
                    fclose($socket);
                    $this->servers[] = $proc;
                    return $port;
                }
                usleep(100000);
                if (!(proc_get_status($proc)['running'] ?? false)) {
                    break;
                }
            }
            proc_terminate($proc);
            proc_close($proc);
        }
        self::fail('cannot start a local web server');
    }

    private function serverDsn(): string
    {
        $dsn = CliRun::serverDsn();
        if ($dsn === null) {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN (diag connects)');
        }
        return $dsn;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2risk');
        }
        return $this->base;
    }
}
