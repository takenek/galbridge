<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Tests\Support\CliRun;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-11: bin/galbridge.sh diag --allow-root. A process of root
 *
 *  - never lets PHP write its messages through a log_dir another account controls (every
 *    rcube_config object points PHP's error_log at <log_dir>/errors.log; switching to syslog after
 *    Roundcube's start was not enough): with log_driver 'file' the log_dir must be root's alone,
 *    otherwise the run is refused - and so is an SQLite database of Roundcube in a directory of
 *    another account;
 *  - never loads code from the working directory (PHP looks there last for a relative file name,
 *    whatever include_path says - Roundcube's autoloader asks for one), because it works in
 *    Roundcube's verified program/ directory;
 *  - hands Roundcube the canonical paths it checked (INSTALL_PATH, RCMAIL_CONFIG_DIR,
 *    RCUBE_CONFIG_PATH), not a spelling with a symbolic link another account could swap;
 *  - checks every directory of include_path it keeps (absolute and existing entries only).
 *
 * Every test needs root (CI runs unprivileged: they run in the local root run of docs/RELEASING.md)
 * and fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
// (root-aware as well: the root step of CI runs the group root-aware, which holds every root test -
// PHPUnit 13 selects nothing for "--group root,root-aware")
#[Group('root')]
#[Group('root-aware')]
final class Remediation2AllowRootTest extends TestCase
{
    private const NOBODY = 65534;

    /** what the fake core prints when it is loaded (the checks passed) */
    private const FAKE = '<?php echo json_encode(["cwd" => getcwd(), "install" => INSTALL_PATH, "config" => defined("RCMAIL_CONFIG_DIR") ? RCMAIL_CONFIG_DIR : "undefined",'
        . ' "path" => getenv("RCUBE_CONFIG_PATH"), "include" => get_include_path()]), "\n"; exit(42);';

    private string $base = '';

    protected function setUp(): void
    {
        if (!CliRun::isRoot()) {
            self::markTestSkipped('needs root (--allow-root is for root only)');
        }
    }

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    public function testPhpMessagesOfARootProcessNeverGoThroughLogDir(): void
    {
        $base = $this->base();
        // the log directory of the web server user, with a link where PHP writes its messages
        mkdir($base . '/wlogs', 0o755);
        chown($base . '/wlogs', self::NOBODY);
        symlink($base . '/target', $base . '/wlogs/errors.log');
        // a warning on every load of the configuration: PHP logs it wherever error_log points
        $warn = 'trigger_error("galbridge-test warning", E_USER_WARNING);' . "\n";
        $dsn = CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';

        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], ['log_dir' => $base . '/wlogs/'], $warn);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(1, $code, $out . $err);
        self::assertSame('', $out, 'nothing was checked');
        self::assertStringContainsString('galbridge.sh --allow-root refused: PHP writes its messages to ' . $base . '/wlogs/errors.log while Roundcube runs'
            . " (log_driver 'file'), and " . $base . '/wlogs (owner uid ' . self::NOBODY . ', mode 0755) is not root\'s alone'
            . " - set log_driver = 'syslog' (iRedMail's setting) or run the tool as the web server user.", $err);
        clearstatcache();
        self::assertFileDoesNotExist($base . '/target', 'root wrote through the link of another account');

        // a world-writable directory with the sticky bit is no directory of root either
        mkdir($base . '/sticky', 0o777);
        chmod($base . '/sticky', 0o1777);
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], ['log_dir' => $base . '/sticky/'], $warn);
        [$code, , $err] = CliRun::diag($config);
        self::assertSame(1, $code, $err);
        self::assertStringContainsString($base . '/sticky (owner uid 0, mode 1777) is not root\'s alone', $err);

        // iRedMail's setting: syslog - the run goes ahead, and nothing is written into log_dir
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], ['log_dir' => $base . '/wlogs/', 'log_driver' => 'syslog'], $warn);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertContains($code, [0, 2], $out . $err);
        self::assertStringContainsString('== vmail database', $out);
        clearstatcache();
        self::assertFileDoesNotExist($base . '/target');
        self::assertSame(['errors.log'], array_values(array_diff(scandir($base . '/wlogs') ?: [], ['.', '..'])));

        // a log_dir of root alone: the run goes ahead, PHP's messages land there
        mkdir($base . '/rlogs', 0o755);
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], ['log_dir' => $base . '/rlogs/'], $warn);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertContains($code, [0, 2], $out . $err);
        self::assertStringContainsString('galbridge-test warning', (string) file_get_contents($base . '/rlogs/errors.log'));
        clearstatcache();
        self::assertFileDoesNotExist($base . '/target');
    }

    /**
     * Roundcube's SQLite database is opened - and its journal created - as root: only in a directory
     * of root alone.
     */
    public function testAnSqliteDatabaseOfRoundcubeIsOpenedOnlyInADirectoryOfRoot(): void
    {
        $base = $this->base();
        mkdir($base . '/wdb', 0o755);
        copy(CliRun::roundcubeDb($base), $base . '/wdb/roundcube.db');
        chown($base . '/wdb', self::NOBODY);
        chown($base . '/wdb/roundcube.db', self::NOBODY);
        $dsn = CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], ['db_dsnw' => 'sqlite:///' . $base . '/wdb/roundcube.db?mode=0646']);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(1, $code, $out . $err);
        self::assertSame('', $out);
        self::assertStringContainsString("galbridge.sh --allow-root refused: Roundcube's database (db_dsnw) is the SQLite file " . $base . '/wdb/roundcube.db, and '
            . $base . '/wdb (owner uid ' . self::NOBODY . ', mode 0755) is not root\'s alone', $err);
        self::assertSame(['roundcube.db'], array_values(array_diff(scandir($base . '/wdb') ?: [], ['.', '..'])), 'no journal of root');

        // in a directory of root (the default of the other tests) it is used
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn], ['db_dsnw' => 'sqlite:///' . CliRun::roundcubeDb($base) . '?mode=0600']);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertContains($code, [0, 2], $out . $err);
        self::assertStringNotContainsString('Roundcube users', $out, 'the users table was read');
    }

    /**
     * Final review, CLI-1: diag compares the options of every per-host file of include_host_config,
     * and loading such a file runs it. A process of root loads only a file that the root check of
     * galbridge.sh covered - inside a verified configuration directory, owned by root and writable
     * by nobody else (that check looks at *.php, *.inc, *.sql and *.sh files only); any other is
     * named in a WARN line and never run.
     */
    public function testARootProcessRunsOnlyThePerHostFilesTheRootCheckCovered(): void
    {
        $base = $this->base();
        $dsn = CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143']], ['include_host_config' => [
            'abs.example' => $base . '/vhosts/abs.inc.php',
            'rel.example' => '../vhosts/rel.inc.php',
            'gw.example' => 'groupw.host',
            'nb.example' => 'nobody.host',
            'ok.example' => 'ok.inc.php',
        ]]);
        mkdir($base . '/vhosts', 0o755);
        // each file leaves a marker when it is run
        $files = ['abs' => $base . '/vhosts/abs.inc.php', 'rel' => $base . '/vhosts/rel.inc.php', 'groupw' => $config . '/groupw.host', 'nobody' => $config . '/nobody.host', 'ok' => $config . '/ok.inc.php'];
        foreach ($files as $name => $file) {
            file_put_contents($file, "<?php\nfile_put_contents('{$base}/ran-{$name}', 'executed');\n\$config['galbridge_domain_book_name'] = 'Books';\n");
            chmod($file, 0o644);
        }
        chmod($files['groupw'], 0o664);
        chown($files['nobody'], self::NOBODY);

        [$code, $out, $err] = CliRun::diag($config);
        self::assertContains($code, [0, 2], $out . $err);
        self::assertStringContainsString('== Per-host configuration', $out);
        clearstatcache();
        foreach (['abs', 'rel', 'groupw', 'nobody'] as $name) {
            self::assertFileDoesNotExist($base . '/ran-' . $name, $name . ': root ran a per-host file the root check did not cover');
        }
        CliRun::assertLine($out, 'INFO', 'per-host files', '5 file(s) a request can select');
        $outside = 'it lies outside the configuration directories that galbridge.sh checked for --allow-root: run diag as the web server user to check it';
        CliRun::assertLine($out, 'WARN', 'per-host configuration', $files['abs'] . ': not loaded as root - ' . $outside);
        CliRun::assertLine($out, 'WARN', 'per-host configuration', realpath($files['rel']) . ': not loaded as root - ' . $outside);
        $real = (string) realpath($config);
        CliRun::assertLine($out, 'WARN', 'per-host configuration', $real . '/groupw.host: not loaded as root - it is not owned by root or is writable by its group or by others (owner uid 0, mode 0664): run diag as the web server user to check it');
        CliRun::assertLine($out, 'WARN', 'per-host configuration', $real . '/nobody.host: not loaded as root - it is not owned by root or is writable by its group or by others (owner uid ' . self::NOBODY . ', mode 0644): run diag as the web server user to check it');
        // a file of root alone in the configuration directory is loaded as before
        self::assertFileExists($base . '/ran-ok');
        CliRun::assertLine($out, 'INFO', 'per-host configuration', 'ok.inc.php: sets galbridge_domain_book_name (book names may differ per host)');
        self::assertDoesNotMatchRegularExpression('/^  \[ OK \] per-host configuration/m', $out, 'nothing of the skipped files counts as checked');
    }

    public function testARootProcessNeverLoadsCodeFromTheWorkingDirectory(): void
    {
        $base = $this->base();
        // a database driver Roundcube does not have: its autoloader asks PHP for
        // Roundcube/db/foo.php, and PHP looks into the working directory last
        $config = CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail'], ['db_dsnw' => 'foo://rc:pw@127.0.0.1/roundcube']);
        mkdir($base . '/cwd/Roundcube/db', 0o755, true);
        file_put_contents($base . '/cwd/Roundcube/db/foo.php', '<?php echo "galbridge-test: planted code ran\n"; exit(42);');
        [$code, $out, $err] = CliRun::diag($config, [], [], null, $base . '/cwd');
        self::assertStringNotContainsString('planted code ran', $out . $err, 'root executed a file of the working directory');
        self::assertSame(2, $code, $out . $err);
        // Roundcube ends the program on the unknown driver: never mistaken for a result
        self::assertStringContainsString('Unsupported database driver: foo', $err);
        self::assertStringEndsWith("\nResult: aborted\n", $out);
    }

    public function testARootProcessHandsRoundcubeTheCanonicalPathsItChecked(): void
    {
        $rc = $this->fakeTree();
        $base = $this->base();
        // the installation reached through a link in a directory of another account: that account
        // could point the link elsewhere between the check and the load
        mkdir($base . '/foreign', 0o755);
        symlink($rc, $base . '/foreign/rc');
        chown($base . '/foreign', self::NOBODY);
        mkdir($base . '/cwd', 0o755);
        [$code, $out, $err] = $this->runFake($base . '/foreign/rc', ['ROUNDCUBE_CONFIG_DIR' => '../www/roundcube/config', 'RCUBE_CONFIG_PATH' => 'extra'], $base . '/cwd');
        $seen = $this->fakeOutput($code, $out, $err);
        $real = (string) realpath($rc);
        self::assertSame($real . '/', $seen['install'], 'INSTALL_PATH is the canonical path that was checked');
        self::assertSame($real . '/config', $seen['config'], 'RCMAIL_CONFIG_DIR is defined, canonical - not read from the environment again');
        self::assertSame($real . '/extra', $seen['path'], 'RCUBE_CONFIG_PATH holds the canonical directories');
        self::assertSame($real . '/program', $seen['cwd'], 'the process works in the verified program/ directory');

        // without RCUBE_CONFIG_PATH the variable stays unset
        [$code, $out, $err] = $this->runFake($rc, [], $base . '/cwd');
        $seen = $this->fakeOutput($code, $out, $err);
        self::assertFalse($seen['path']);
        self::assertSame($real . '/config', $seen['config']);
    }

    public function testIncludePathEntriesOfARootProcessAreChecked(): void
    {
        $rc = $this->fakeTree();
        $base = $this->base();
        mkdir($base . '/inc/Net', 0o755, true);
        file_put_contents($base . '/inc/Net/Foo.php', '<?php');
        chmod($base . '/inc/Net/Foo.php', 0o644);
        $php = ['-d', 'display_errors=stderr', '-d', 'include_path=.:' . $base . '/inc:' . $base . '/missing:relative/dir'];

        chmod($base . '/inc', 0o777);
        [$code, $out, $err] = $this->runFake($rc, [], $rc, $php);
        self::assertSame(1, $code, $out . $err);
        self::assertSame('galbridge.sh --allow-root refused: ' . $base . '/inc (owner uid 0, mode 0777) is not owned by root or is writable by its group or by others.' . "\n", $err);
        chmod($base . '/inc', 0o755);

        chmod($base . '/inc/Net/Foo.php', 0o666);
        [$code, , $err] = $this->runFake($rc, [], $rc, $php);
        self::assertSame(1, $code, $err);
        self::assertStringContainsString($base . '/inc/Net/Foo.php (owner uid 0, mode 0666)', $err);
        chmod($base . '/inc/Net/Foo.php', 0o644);

        [$code, $out, $err] = $this->runFake($rc, [], $rc, $php);
        $seen = $this->fakeOutput($code, $out, $err);
        self::assertSame([(string) realpath($base . '/inc')], explode(PATH_SEPARATOR, (string) $seen['include']), 'only the absolute, existing, checked entry is kept');

        // nothing left: Roundcube's own library stands in (PHP refuses an empty include_path and
        // would keep ".")
        [$code, $out, $err] = $this->runFake($rc, [], $rc, ['-d', 'display_errors=stderr', '-d', 'include_path=.:relative']);
        $seen = $this->fakeOutput($code, $out, $err);
        self::assertSame((string) realpath($rc) . '/program/lib', $seen['include']);
    }

    /**
     * A Roundcube tree of root whose core prints what it was handed and ends; this plugin is
     * installed into it as a release copy.
     */
    private function fakeTree(): string
    {
        $rc = $this->base() . '/www/roundcube';
        foreach ([
            'program/include/iniset.php' => self::FAKE,
            'program/include/clisetup.php' => self::FAKE,
            'program/lib/Roundcube/bootstrap.php' => '<?php',
            'plugins/foo/foo.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'config/config.inc.php' => '<?php',
            'extra/config.inc.php' => '<?php',
        ] as $file => $content) {
            if (!is_dir(dirname($rc . '/' . $file))) {
                mkdir(dirname($rc . '/' . $file), 0o755, true);
            }
            file_put_contents($rc . '/' . $file, $content);
            chmod($rc . '/' . $file, 0o644);
        }
        chmod($this->base() . '/www', 0o755);
        symlink(CliRun::releaseCopy($this->base(), 'galbridge-release'), $rc . '/plugins/galbridge');
        return $rc;
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $php
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runFake(string $rc, array $env, string $cwd, array $php = ['-d', 'display_errors=stderr']): array
    {
        $vars = getenv();
        unset($vars['RCUBE_CONFIG_PATH'], $vars['ROUNDCUBE_CONFIG_DIR']);
        return CliRun::exec([PHP_BINARY, ...$php, $rc . '/plugins/galbridge/bin/galbridge.sh', 'diag', '--allow-root'], null, ['ROUNDCUBE_INSTALL_PATH' => $rc] + $env + $vars, $cwd);
    }

    /**
     * What the fake core printed - it was loaded, so the checks passed - and the program was then
     * reported as ended early.
     *
     * @return array{cwd: string, install: string, config: string, path: false|string, include: string}
     */
    private function fakeOutput(int $code, string $out, string $err): array
    {
        self::assertSame(2, $code, $out . $err);
        self::assertStringEndsWith("\nResult: aborted\n", $out);
        $seen = json_decode(strtok($out, "\n") ?: '', true);
        self::assertIsArray($seen, $out);
        /** @var array{cwd: string, install: string, config: string, path: false|string, include: string} $seen */
        return $seen;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2root');
        }
        return $this->base;
    }
}
