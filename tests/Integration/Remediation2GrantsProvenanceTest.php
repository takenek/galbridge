<?php

declare(strict_types=1);

/**
 * GAL Bridge - second verification pass (assessment of 1.0.1), F-02: where the output of grants
 * comes from. The database administrator runs it, so:
 *
 *  - as any user other than root, grants refuses code and templates that an account other than
 *    root and that user owns, or that its group or others can write - the files it loads and
 *    prints and every directory above them (the check of --allow-root, with that user allowed);
 *  - it refuses to run as a user that can read the configuration of Roundcube or of the plugin -
 *    the web server user, whose other processes could read or change the output;
 *  - a dedicated account runs it from code nobody else can change;
 *  - as root, --allow-root keeps checking the code (regression);
 *  - diag names every plugin file the PHP user can change and says that grants refuses such a tree.
 *
 * Run as root, the suite runs the unprivileged cases as the account "nobody" (setpriv) from a copy
 * that root owns; run as another user, from a copy that user owns.
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Tests\Support\CliRun;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-aware')]
final class Remediation2GrantsProvenanceTest extends TestCase
{
    /** the account of the unprivileged runs when the suite runs as root */
    private const NOBODY = 65534;

    private const SETPRIV = '/usr/bin/setpriv';

    private string $base = '';

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    public function testNonRootGrantsRefusesCodeThatAnotherAccountCanChange(): void
    {
        $variants = [
            'a class of lib/' => static fn (string $p): string => self::chmodPlus($p . '/lib/GalBridge/Db/Connection.php', 0o020),
            'a template' => static fn (string $p): string => self::chmodPlus($p . '/SQL/grants.mysql.sql', 0o020),
            'the script' => static fn (string $p): string => self::chmodPlus($p . '/bin/galbridge.sh', 0o002),
            'a directory of lib/' => static fn (string $p): string => self::chmodPlus($p . '/lib/GalBridge/Directory', 0o020),
            'the directory above the plugin' => static fn (string $p): string => self::chmodPlus(dirname($p), 0o020),
        ];
        if (CliRun::isRoot()) {
            // owned by an account that is neither root nor the user of the run
            $variants['a class owned by another account'] = static function (string $p): string {
                chown($p . '/lib/GalBridge/Log.php', 12345);
                return realpath($p . '/lib/GalBridge/Log.php') ?: '';
            };
        }
        foreach ($variants as $what => $tamper) {
            $plugin = $this->plugin('p' . bin2hex(random_bytes(3)));
            $path = $tamper($plugin);
            [$code, $out, $err] = $this->unprivileged($plugin, ['grants', '--driver=mysql']);
            self::assertSame(1, $code, $what . ': ' . $err);
            self::assertSame('', $out, $what . ': no statement');
            self::assertStringStartsWith('galbridge.sh grants refused: ' . $path . ' (owner uid ', $err, $what);
            self::assertStringContainsString('is not owned by root or by this user (uid ' . $this->uid() . ') or is writable by its group or by others: the statements would come from code that another account can change', $err, $what);
        }
    }

    public function testNonRootGrantsRefusesTheIdentityOfTheWebServer(): void
    {
        // Roundcube's configuration, readable by the user of the run
        $rc = $this->base() . '/rc';
        mkdir($rc . '/config', 0o755, true);
        mkdir($rc . '/plugins', 0o755);
        file_put_contents($rc . '/config/config.inc.php', "<?php\n// GAL Bridge test - TEST ONLY\n\$config = [];\n");
        chmod($rc . '/config/config.inc.php', 0o644);
        $plugin = $this->plugin('galbridge', $rc . '/plugins');
        foreach ([['ROUNDCUBE_INSTALL_PATH' => $rc], ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube', 'ROUNDCUBE_CONFIG_DIR' => $rc . '/config']] as $env) {
            [$code, $out, $err] = $this->unprivileged($plugin, ['grants', '--driver=pgsql'], $env);
            self::assertSame(1, $code, $err);
            self::assertSame('', $out, 'no statement');
            self::assertSame('galbridge.sh grants refused: this user can read ' . realpath($rc . '/config/config.inc.php') . ", a configuration file of the web server.\n"
                . "Another process of the web server user could read or change the output while it is produced, and the database administrator runs it.\n"
                . "Run grants as root:  umask 077; plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql|pgsql ... > /root/galbridge-grants.sql  (README 2.2)\n", str_replace($rc . '/config/config.inc.php', (string) realpath($rc . '/config/config.inc.php'), $err));
        }
        // ... found through the path it was started by as well (<roundcube>/plugins/galbridge/bin)
        [$code, , $err] = $this->unprivileged($plugin, ['grants', '--driver=pgsql'], ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube']);
        self::assertSame(1, $code, $err);
        self::assertStringContainsString('this user can read', $err);

        // a file it cannot read is not the mark of the web server user
        chmod($rc . '/config/config.inc.php', 0o000);
        [$code, $out, $err] = $this->unprivileged($plugin, ['grants', '--driver=pgsql'], ['ROUNDCUBE_INSTALL_PATH' => $rc]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('CREATE VIEW galbridge_forwardings', $out);

        // the plugin's own configuration (the DSN) is one as well
        file_put_contents($plugin . '/config.inc.php', "<?php\n// GAL Bridge test - TEST ONLY\n\$config = [];\n");
        chmod($plugin . '/config.inc.php', 0o644);
        [$code, $out, $err] = $this->unprivileged($plugin, ['grants', '--driver=pgsql'], ['ROUNDCUBE_INSTALL_PATH' => $rc]);
        self::assertSame(1, $code, $err);
        self::assertSame('', $out);
        self::assertStringContainsString('this user can read ' . realpath($plugin . '/config.inc.php') . ', a configuration file of the web server', $err);
    }

    public function testNonRootGrantsRunsFromCleanCodeOfADedicatedAccount(): void
    {
        $plugin = $this->plugin('galbridge');
        [$code, $out, $err] = $this->unprivileged($plugin, ['grants', '--driver=mysql', '--role=acme_gal']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("CREATE USER 'acme_gal'@'localhost' IDENTIFIED WITH mysql_native_password AS ", $out);
        self::assertMatchesRegularExpression("/^-- \\\$config\\['galbridge_db_dsn'\\] = 'mysql:\\/\\/acme_gal:[0-9a-f]{40}@localhost\\/vmail';$/m", $err, 'the DSN line on standard error');
        self::assertStringNotContainsString('galbridge_db_dsn\'] = \'mysql://acme_gal', $out);
    }

    /**
     * Standard output of grants is the SQL file: a message of PHP itself - here the warning about a
     * directory below lib/ that the user cannot list - goes to standard error, also when PHP is told
     * to display messages (the command line PHP displays them on standard output by default).
     */
    public function testMessagesOfPhpNeverReachTheSqlStream(): void
    {
        $plugin = $this->plugin('galbridge');
        mkdir($plugin . '/lib/GalBridge/Unlisted', 0o300);
        chmod($plugin . '/lib/GalBridge/Unlisted', 0o300);
        [$code, $out, $err] = $this->unprivileged($plugin, ['grants', '--driver=pgsql'], ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'], ['-d', 'display_errors=1', '-d', 'log_errors=0']);
        chmod($plugin . '/lib/GalBridge/Unlisted', 0o700);
        self::assertSame(0, $code, $err);
        self::assertStringStartsWith('-- Generated by bin/galbridge.sh grants on ', $out);
        self::assertStringNotContainsString('scandir', $out);
        self::assertStringContainsString('scandir', $err, 'the message is there - on standard error');
    }

    /**
     * Regression of the root path: --allow-root runs from code root alone can change, and refuses
     * code another account owns.
     */
    #[Group('root')]
    public function testRootGrantsChecksTheCode(): void
    {
        if (!CliRun::isRoot()) {
            self::markTestSkipped('needs root (--allow-root is for root only)');
        }
        $plugin = $this->plugin('galbridge');
        [$code, $out, $err] = CliRun::script(['grants', '--driver=pgsql'], ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'], null, $plugin);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('CREATE VIEW galbridge_forwardings', $out);
        chown($plugin . '/lib/GalBridge/Db/Dsn.php', self::NOBODY);
        [$code, $out, $err] = CliRun::script(['grants', '--driver=pgsql'], ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'], null, $plugin);
        self::assertSame(1, $code, $err);
        self::assertSame('', $out);
        self::assertSame('galbridge.sh --allow-root refused: ' . realpath($plugin . '/lib/GalBridge/Db/Dsn.php') . ' (owner uid ' . self::NOBODY . ', mode 0644) is not owned by root or is writable by its group or by others.' . "\n", $err);
    }

    /**
     * diag names every plugin file the user can change - every class below lib/, not a fixed list -
     * and says that grants and --allow-root refuse such a tree.
     */
    public function testDiagNamesEveryChangeablePluginFile(): void
    {
        CliRun::roundcube();
        $plugin = $this->plugin('galbridge');
        self::chmodPlus($plugin . '/lib/GalBridge/Directory/Sql.php', 0o020);
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        (new \ReflectionMethod(Tool::class, 'pluginCode'))->invoke(new Tool(\rcube::get_instance(), $plugin, $out));
        rewind($out);
        $text = (string) stream_get_contents($out);
        CliRun::assertLine($text, 'WARN', 'plugin code', 'lib/GalBridge/Directory/Sql.php');
        CliRun::assertLine($text, 'WARN', 'plugin code', 'grants and --allow-root refuse to run from such a tree');
    }

    /**
     * A copy of the plugin as the release archive holds it, nobody else may write it: root's when
     * the suite runs as root (so that "nobody" can read it and root owns it), the user's otherwise.
     */
    private function plugin(string $name, ?string $parent = null): string
    {
        $parent ??= $this->base();
        $copy = CliRun::releaseCopy($parent, $name);
        if (CliRun::isRoot()) {
            self::openForReading($copy);
        }
        return $copy;
    }

    /**
     * A private base directory - readable by "nobody" when the suite runs as root (in a directory
     * every account can reach; owned by root, so it passes the check of the directories above).
     */
    private function base(): string
    {
        if ($this->base !== '') {
            return $this->base;
        }
        if (!CliRun::isRoot()) {
            return $this->base = CliRun::tempBase('galbridge-r2prov');
        }
        if (!is_executable(self::SETPRIV)) {
            self::markTestSkipped('needs setpriv to run the unprivileged cases as root');
        }
        $parent = sys_get_temp_dir();
        for ($dir = $parent; $dir !== dirname($dir); $dir = dirname($dir)) {
            if (((int) @fileperms($dir) & 0o001) === 0) {
                $parent = '/tmp'; // the suite's temporary directory is root's alone
                break;
            }
        }
        $this->base = $parent . '/galbridge-r2prov-' . getmypid() . '-' . bin2hex(random_bytes(6));
        if (!mkdir($this->base, 0o755)) {
            throw new \RuntimeException('cannot create a temporary directory');
        }
        chmod($this->base, 0o755);
        return $this->base;
    }

    /**
     * bin/galbridge.sh of $plugin as a user other than root: "nobody" when the suite runs as root,
     * the user of the run otherwise. The working directory is the plugin directory's parent.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     * @param list<string>          $phpArgs PHP options before the script
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function unprivileged(string $plugin, array $args, array $env = ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'], array $phpArgs = ['-d', 'display_errors=stderr']): array
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            $vars[$k] = $v;
        }
        unset($vars['ROUNDCUBE_CONFIG_DIR'], $vars['RCUBE_CONFIG_PATH']);
        foreach ($env as $k => $v) {
            $vars[$k] = $v;
        }
        $cmd = [PHP_BINARY, ...$phpArgs, $plugin . '/bin/galbridge.sh', ...$args];
        if (CliRun::isRoot()) {
            $cmd = [self::SETPRIV, '--reuid=' . self::NOBODY, '--regid=' . self::NOBODY, '--clear-groups', '--', ...$cmd];
        }
        return CliRun::exec($cmd, null, $vars, dirname($plugin));
    }

    private function uid(): int
    {
        return CliRun::isRoot() ? self::NOBODY : (int) Tool::euid();
    }

    /** add mode bits; returns the canonical path (as the refusal names it) */
    private static function chmodPlus(string $path, int $bits): string
    {
        clearstatcache();
        chmod($path, ((int) fileperms($path) & 0o7777) | $bits);
        return realpath($path) ?: $path;
    }

    /** directories 0755, files 0644 */
    private static function openForReading(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0o755);
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                self::openForReading($path . '/' . $entry);
            }
            return;
        }
        chmod($path, str_ends_with($path, '.sh') ? 0o755 : 0o644);
    }
}
