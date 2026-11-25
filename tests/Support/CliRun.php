<?php

declare(strict_types=1);

/**
 * GAL Bridge - helpers for tests that run bin/galbridge.sh as a child process.
 *
 * Shared by the CLI test files of the second remediation pass (and meant for later ones), so they
 * do not copy the private helpers of CliToolTest: a child process with a clean, given environment,
 * the script itself (with --allow-root added when the suite runs as root, as CliToolTest does), a
 * private temporary directory, a Roundcube configuration directory as Roundcube reads it, Roundcube's
 * own SQLite database (core schema), a copy of the plugin as the release archive holds it, and the
 * line assertion of diag.
 *
 * Every directory is created with mode 0700 below sys_get_temp_dir() with an unpredictable name and
 * removed by rmTree() (call it in tearDown()).
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Support;

use GalBridge\Cli\Tool;
use PHPUnit\Framework\Assert;

final class CliRun
{
    /** what the release archive holds at the top level (.gitattributes excludes the rest) */
    public const RELEASE_ENTRIES = [
        '.htaccess', 'CHANGELOG.md', 'COPYRIGHT', 'LICENSE', 'README.md', 'SECURITY.md', 'SQL', 'bin', 'composer.json',
        'config.inc.php.dist', 'docs', 'galbridge.php', 'lib', 'localization',
    ];

    /** the local test login of the vmail fixture (tests/fixtures/vmail/seed.sql) */
    public const JAN = 'jan.kowalski@example.com';

    public static function pluginDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** as the script decides it: also without the posix extension */
    public static function isRoot(): bool
    {
        return Tool::euid() === 0;
    }

    /** the Roundcube tree of the suite (GALBRIDGE_RC), or a skip */
    public static function roundcube(): string
    {
        if (!defined('GALBRIDGE_RC_LOADED') || !GALBRIDGE_RC_LOADED) {
            Assert::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        return rtrim((string) constant('RCUBE_INSTALL_PATH'), '/');
    }

    /**
     * A command as a child process; stdin is /dev/null unless given.
     *
     * @param list<string>               $cmd
     * @param null|array<string, string> $env the whole environment (null = this process's)
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public static function exec(array $cmd, ?string $stdin = null, ?array $env = null, ?string $cwd = null): array
    {
        $env ??= getenv();
        $env['PGCONNECT_TIMEOUT'] = '5';
        $spec = [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return [127, '', 'cannot start ' . ($cmd[0] ?? '')];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    /**
     * php bin/galbridge.sh <args>. As root --allow-root is added (the script refuses root without
     * it) unless $args holds '--no-allow-root'.
     *
     * @param list<string>          $args
     * @param array<string, string> $env     added to (or replacing in) this process's environment
     * @param list<string>          $phpArgs PHP options before the script (default: display_errors=stderr)
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public static function script(array $args, array $env = [], ?string $cwd = null, ?string $pluginDir = null, array $phpArgs = ['-d', 'display_errors=stderr']): array
    {
        if (self::isRoot() && !in_array('--no-allow-root', $args, true)) {
            $args[] = '--allow-root';
        }
        $args = array_values(array_diff($args, ['--no-allow-root']));
        $vars = getenv();
        foreach ($env as $k => $v) {
            $vars[$k] = $v;
        }
        return self::exec([PHP_BINARY, ...$phpArgs, ($pluginDir ?? self::pluginDir()) . '/bin/galbridge.sh', ...$args], null, $vars, $cwd ?? self::pluginDir());
    }

    /**
     * diag with a configuration directory written by roundcubeConfig().
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public static function diag(string $configDir, array $args = [], array $env = [], ?string $pluginDir = null, ?string $cwd = null): array
    {
        $rc = self::roundcube();
        return self::script(['diag', ...$args], ['ROUNDCUBE_INSTALL_PATH' => $rc, 'ROUNDCUBE_CONFIG_DIR' => $configDir] + $env, $cwd, $pluginDir);
    }

    /**
     * A private directory (mode 0700, owner: the user of the test run).
     */
    public static function tempBase(string $prefix = 'galbridge-r2'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('cannot create a private temporary directory');
        }
        return $dir;
    }

    public static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * A Roundcube configuration directory <base>/config as Roundcube reads it (ROUNDCUBE_CONFIG_DIR):
     * defaults.inc.php of the core, config.inc.php with the core options and galbridge.inc.php with
     * the plugin options (both mode 0640). Logs, temp and Roundcube's SQLite database lie in <base>.
     *
     * @param array<string, mixed> $plugin
     * @param array<string, mixed> $core
     * @param string               $coreCode PHP code appended to config.inc.php (runs on every load)
     */
    public static function roundcubeConfig(string $base, array $plugin, array $core = [], string $coreCode = ''): string
    {
        $rc = self::roundcube();
        if (is_file(self::pluginDir() . '/config.inc.php')) {
            Assert::markTestSkipped('the plugin directory has its own config.inc.php (it would take precedence)');
        }
        $dir = $base . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
            copy($rc . '/config/defaults.inc.php', $dir . '/defaults.inc.php');
            chmod($dir . '/defaults.inc.php', 0o644);
        }
        foreach (['logs', 'temp'] as $sub) {
            if (!is_dir($base . '/' . $sub)) {
                mkdir($base . '/' . $sub, 0o700);
            }
        }
        $core += [
            'db_dsnw' => 'sqlite:///' . self::roundcubeDb($base) . '?mode=0600',
            'imap_host' => '127.0.0.1:143',
            'plugins' => ['galbridge'],
            'addressbook_sort_col' => 'name',
            'log_dir' => $base . '/logs/',
            'temp_dir' => $base . '/temp/',
            'des_key' => 'TESTONLY-cli-0123456789ab',
        ];
        file_put_contents($dir . '/config.inc.php', "<?php\n// GAL Bridge test - TEST ONLY\n\$config = " . var_export($core, true) . ";\n" . $coreCode);
        file_put_contents($dir . '/galbridge.inc.php', "<?php\n// GAL Bridge test - TEST ONLY\n\$config = " . var_export($plugin, true) . ";\n");
        chmod($dir . '/config.inc.php', 0o640);
        chmod($dir . '/galbridge.inc.php', 0o640);
        return $dir;
    }

    /**
     * Roundcube's own database (SQLite, core schema) in <base>/roundcube.db.
     */
    public static function roundcubeDb(string $base): string
    {
        $file = $base . '/roundcube.db';
        if (!is_file($file)) {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec((string) file_get_contents(self::roundcube() . '/SQL/sqlite.initial.sql'));
            $pdo = null;
            chmod($file, 0o600);
        }
        return $file;
    }

    /**
     * A copy of this plugin as the release archive holds it, <base>/<name> (directories 0700, files
     * 0600, owner: the user of the test run).
     */
    public static function releaseCopy(string $base, string $name): string
    {
        $target = $base . '/' . $name;
        mkdir($target, 0o700);
        foreach (self::RELEASE_ENTRIES as $entry) {
            self::copyTree(self::pluginDir() . '/' . $entry, $target . '/' . $entry);
        }
        return $target;
    }

    /**
     * The DSN of a local throw-away vmail database on a server (MariaDB first), or null.
     */
    public static function serverDsn(): ?string
    {
        foreach (['GALBRIDGE_TEST_MYSQL_DSN', 'GALBRIDGE_TEST_PGSQL_DSN'] as $env) {
            $dsn = getenv($env);
            if (is_string($dsn) && $dsn !== '') {
                return $dsn;
            }
        }
        return null;
    }

    /**
     * Assert one diag line: "  [LEVEL] what   value...".
     */
    public static function assertLine(string $out, string $level, string $what, string $contains = '', string $message = ''): void
    {
        $tag = $level === 'OK' ? ' OK ' : $level;
        $pattern = '/^  \[' . preg_quote($tag, '/') . '\] +' . preg_quote($what, '/') . ' +.*' . preg_quote($contains, '/') . '/m';
        Assert::assertMatchesRegularExpression($pattern, $out, ($message !== '' ? $message . "\n" : '') . "expected [{$level}] {$what} ... {$contains}");
    }

    /**
     * No byte that could steer a terminal: C0 controls other than the newline, DEL, the C1 controls
     * (raw or UTF-8), bidi and zero-width characters, separators, and only valid UTF-8.
     */
    public static function assertTerminalSafe(string $text, string $message = ''): void
    {
        Assert::assertSame(1, preg_match('//u', $text), $message . ': invalid UTF-8');
        Assert::assertSame(0, preg_match('/[\x00-\x09\x0B-\x1F\x7F]|[\x{80}-\x{9F}]|[\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', $text), $message . ': a raw control, bidi or separator character');
    }

    private static function copyTree(string $from, string $to): void
    {
        if (is_dir($from)) {
            mkdir($to, 0o700);
            foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
                self::copyTree($from . '/' . $entry, $to . '/' . $entry);
            }
            return;
        }
        Assert::assertTrue(copy($from, $to), $from);
        chmod($to, 0o600);
    }
}
