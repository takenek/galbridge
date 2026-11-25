<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Tests\Integration\CliToolTest;
use GalBridge\Tests\Support\CliRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-34: what a green CI run proves. SourceGuardTest is a lexical
 * regression check - it reports direct uses of the functions, operators and superglobals it lists -
 * so it has to see EVERY PHP file of a release and string literals that name such a function (a
 * callable built from a literal, array_map('system', ...)). Indirect forms (callables built at run
 * time, variable calls) are not proven absent: review and PHPStan cover them. The tests that need
 * root are grouped so that CI runs them as root, and no case exists only to be skipped.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2SourceGuardTest extends TestCase
{
    /**
     * String literals that equal the name of a function SourceGuardTest restricts, and why each is
     * not a callable: extend this list (with the reason) when production code needs another one.
     */
    private const ALLOWED_LITERALS = [
        'galbridge.php' => ['mail'],                            // the name of Roundcube's mail task
        'bin/galbridge.sh' => ['error_log'],                    // the ini setting of PHP's error log
        'lib/GalBridge/Db/Connection.php' => ['ini_set'],       // function_exists('ini_set')
        'lib/GalBridge/Cli/Tool.php' => ['ini_set', 'system'],  // function_exists(); a mysql client command name
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The PHP files of the release a checkout makes: tracked and new files without export-ignore
     * (.gitattributes) whose extension is a PHP one or that start as PHP code.
     *
     * @return list<string>
     */
    public static function releasePhpFiles(string $dir): array
    {
        [$code, $out, $err] = CliRun::exec(['git', '-C', $dir, 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
        self::assertSame(0, $code, $err);
        $files = array_values(array_filter(explode("\0", $out), static fn (string $f): bool => $f !== '' && is_file($dir . '/' . $f)));
        // export-ignore on a directory (/tests) is not inherited by the files in it - git archive
        // leaves out the whole directory - so every path and each of its directories is asked
        $paths = [];
        foreach ($files as $file) {
            for ($p = $file; $p !== '.' && $p !== ''; $p = dirname($p)) {
                $paths[$p] = true;
            }
        }
        [$code, $attrs, $err] = CliRun::exec(['git', '-C', $dir, 'check-attr', '-z', '--stdin', 'export-ignore'], implode("\0", array_keys($paths)) . "\0");
        self::assertSame(0, $code, $err);
        $ignored = [];
        $parts = explode("\0", $attrs);
        for ($i = 0; $i + 2 < count($parts); $i += 3) {
            if ($parts[$i + 2] === 'set') {
                $ignored[$parts[$i]] = true;
            }
        }
        $php = [];
        foreach ($files as $file) {
            for ($p = $file; $p !== '.' && $p !== ''; $p = dirname($p)) {
                if (isset($ignored[$p])) {
                    continue 2;
                }
            }
            $head = (string) file_get_contents($dir . '/' . $file, false, null, 0, 512);
            $shebang = str_starts_with($head, '#!') && (str_contains(strtok($head, "\n") ?: '', 'php') || str_starts_with((string) substr($head, (int) strpos($head, "\n") + 1), '<?php'));
            if (preg_match('/\.(php\d?|inc|phtml|phar|pht)$/iD', $file) === 1 || str_starts_with($head, '<?php') || $shebang) {
                $php[] = $file;
            }
        }
        sort($php);
        return $php;
    }

    public function testEveryPhpFileOfTheReleaseIsScanned(): void
    {
        [$code] = CliRun::exec(['git', '-C', self::root(), 'rev-parse', '--git-dir']);
        if ($code !== 0) {
            self::markTestSkipped('needs git and a git checkout');
        }
        $scanned = array_merge(array_keys(SourceGuardTest::productionFiles()), array_keys(SourceGuardTest::dataFiles()));
        $release = self::releasePhpFiles(self::root());
        self::assertContains('galbridge.php', $release);
        self::assertContains('bin/galbridge.sh', $release);
        self::assertContains('config.inc.php.dist', $release);
        self::assertSame([], array_values(array_diff($release, $scanned)), 'PHP files of the release that SourceGuardTest does not scan');

        // the walker itself: a PHP file outside lib/ and a PHP file under another name are seen,
        // files with export-ignore are not
        $dir = CliRun::tempBase('galbridge-r2-guard');
        try {
            foreach (['lib/A.php' => "<?php\n", 'extra.php' => "<?php\n", 'SQL/x.sql' => "<?php echo 1;\n", 'SQL/grants.mysql.sql' => "GRANT\n",
                'bin/tool' => "#!/usr/bin/env php\n<?php\n", 'README.md' => "```php\n<?php\n```\n", 'tests/T.php' => "<?php\n",
                '.gitattributes' => "/tests export-ignore\n"] as $f => $content) {
                @mkdir(dirname("{$dir}/{$f}"), 0o700, true);
                file_put_contents("{$dir}/{$f}", $content);
            }
            self::assertSame(0, CliRun::exec(['git', '-C', $dir, 'init', '-q'])[0]);
            self::assertSame(['SQL/x.sql', 'bin/tool', 'extra.php', 'lib/A.php'], self::releasePhpFiles($dir));
        } finally {
            CliRun::rmTree($dir);
        }
    }

    /**
     * @return list<string> "file:line 'name'" for string literals naming a restricted function
     */
    private static function literals(string $file, string $code): array
    {
        $restricted = (new \ReflectionClassConstant(SourceGuardTest::class, 'FUNCTIONS'))->getValue();
        self::assertIsArray($restricted);
        $found = [];
        foreach (\PhpToken::tokenize($code, TOKEN_PARSE) as $t) {
            if ($t->is(T_CONSTANT_ENCAPSED_STRING)) {
                $name = strtolower(ltrim(substr($t->text, 1, -1), '\\'));
                if (isset($restricted[$name]) && !in_array($name, self::ALLOWED_LITERALS[$file] ?? [], true)) {
                    $found[] = "{$file}:{$t->line} '{$name}'";
                }
            }
        }
        return $found;
    }

    public function testNoStringLiteralNamesARestrictedFunction(): void
    {
        $found = [];
        foreach (array_keys(SourceGuardTest::productionFiles()) as $file) {
            $found = [...$found, ...self::literals($file, (string) file_get_contents(self::root() . '/' . $file))];
        }
        self::assertSame([], $found, 'a string literal names a function SourceGuardTest restricts (a callable?): use the function directly where it is allowed, or add the literal to ALLOWED_LITERALS with its reason');
        $sample = "<?php\n\$out = array_map('system', \$x);\n\$f = 'exec';\n\$g = \"\\\\shell_exec\";\nusort(\$a, 'strcmp');\n";
        self::assertSame(["sample.php:2 'system'", "sample.php:3 'exec'", "sample.php:4 'shell_exec'"], self::literals('sample.php', $sample));
        self::assertSame([], self::literals('galbridge.php', "<?php\n\$task = 'mail';\n"), 'an allowed literal');
    }

    /**
     * Every test of the group "root" is also in "root-aware" (CI's root step runs that one group),
     * and the root-only tests of CliToolTest are grouped.
     */
    public function testRootTestsAreGroupedSoThatCiRunsThemAsRoot(): void
    {
        $groups = static function (\ReflectionClass|\ReflectionMethod $r): array {
            return array_map(static fn (\ReflectionAttribute $a): string => (string) ($a->getArguments()[0] ?? ''), $r->getAttributes(Group::class));
        };
        $class = new \ReflectionClass(CliToolTest::class);
        self::assertContains('root-aware', $groups($class));
        foreach (['testTheScriptRefusesToRunAsRoot', 'testAllowRootChecksEverythingThatIsLoadedAsRoot'] as $method) {
            self::assertContains('root', $groups($class->getMethod($method)), "CliToolTest::{$method} carries the group root");
        }
        $roots = 0;
        foreach (glob(self::root() . '/tests/{Unit,Integration}/*Test.php', GLOB_BRACE) ?: [] as $path) {
            $name = 'GalBridge\\Tests\\' . basename(dirname($path)) . '\\' . basename($path, '.php');
            if (!class_exists($name)) {
                continue;
            }
            $r = new \ReflectionClass($name);
            $classGroups = $groups($r);
            foreach ($r->getMethods() as $m) {
                if (!str_starts_with($m->getName(), 'test')) {
                    continue;
                }
                $all = [...$classGroups, ...$groups($m)];
                if (in_array('root', $all, true)) {
                    $roots++;
                    self::assertContains('root-aware', $all, "{$name}::{$m->getName()} is in the group root but not in root-aware: CI's root step would not run it");
                }
            }
        }
        self::assertGreaterThanOrEqual(10, $roots);
    }

    /**
     * A case that can only be skipped on an engine fails CI (--fail-on-skipped): the MariaDB-only
     * tests take their DSN from a provider without PostgreSQL or SQLite cases.
     */
    public function testEngineSpecificCasesAreNotSkippedOnOtherEngines(): void
    {
        $class = new \ReflectionClass(CliToolTest::class);
        foreach (['testRemoteMysqlAccountRequiresTls', 'testDiagFindsWritePrivilegesGrantedThroughARole'] as $method) {
            $attrs = $class->getMethod($method)->getAttributes(DataProvider::class);
            self::assertCount(1, $attrs);
            $provider = (string) ($attrs[0]->getArguments()[0] ?? '');
            $cases = $class->getMethod($provider)->invoke(null);
            self::assertIsArray($cases);
            foreach (array_keys($cases) as $key) {
                self::assertNotContains($key, ['pgsql', 'sqlite'], "{$method} gets a case of another engine from {$provider}");
            }
        }
    }
}
