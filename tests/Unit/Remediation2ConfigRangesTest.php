<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Config;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-30 (numbers): every numeric option has ONE range (Config::RANGES) - the
 * one every caller of Config::int() passes, the one validate() (and so diag) checks, and the one the
 * README documents. Before, validate() checked PHP_INT_MIN..PHP_INT_MAX, so diag never reported a
 * value outside the range the code then clamped to.
 *
 * Every test fails on 1.0.1 (Config::RANGES does not exist there).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ConfigRangesTest extends TestCase
{
    public function testEveryIntCallUsesTheDeclaredRange(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/galbridge.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        $calls = 0;
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all("/->int\\(\\s*'([a-z_]+)'\\s*,\\s*(-?\\d+)\\s*,\\s*(-?\\d+)\\s*\\)/", $code, $m, PREG_SET_ORDER);
            foreach ($m as [, $name, $min, $max]) {
                $calls++;
                self::assertArrayHasKey($name, Config::RANGES, $file . ': ' . $name . ' has no declared range');
                self::assertSame(Config::RANGES[$name], [(int) $min, (int) $max], $file . ': ' . $name);
            }
            // a call with a computed range would escape the check above
            self::assertSame(0, preg_match("/->int\\(\\s*'[a-z_]+'\\s*,\\s*[^-0-9\\s]/", $code), $file . ': int() with a range that is not a literal');
        }
        self::assertGreaterThanOrEqual(15, $calls, 'the scan found the callers');
    }

    public function testEveryIntegerDefaultHasARangeThatContainsIt(): void
    {
        $ints = array_keys(array_filter(Config::DEFAULTS, 'is_int'));
        self::assertEqualsCanonicalizing($ints, array_keys(Config::RANGES));
        foreach (Config::RANGES as $name => [$min, $max]) {
            $default = Config::DEFAULTS[$name];
            self::assertIsInt($default);
            self::assertLessThan($max, $min, $name);
            self::assertGreaterThanOrEqual($min, $default, $name);
            self::assertLessThanOrEqual($max, $default, $name);
        }
    }

    /**
     * docs/CONFIGURATION.md 3.8 (README 3.8 until 1.0.7) documents the range of the limits (and 3.9
     * the three others) exactly as declared.
     */
    public function testTheReadmeDocumentsTheDeclaredRanges(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/CONFIGURATION.md');
        foreach (Config::RANGES as $name => [$min, $max]) {
            $range = $min . '–' . $max;
            $row = preg_match('/^\| `' . preg_quote($name, '/') . '` \|[^\n]*\b' . preg_quote($range, '/') . '\b/mu', $readme) === 1;
            self::assertTrue($row, 'docs/CONFIGURATION.md: ' . $name . ' ' . $range);
        }
    }
}
