<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Cli\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-10 (Composer): diag recognises an installation that Roundcube's plugin
 * installer manages - the Composer 1 and the Composer 2 form of vendor/composer/installed.json - and
 * nothing else.
 *
 * Fails on 1.0.1 (Tool::composerManaged() does not exist there).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ToolTest extends TestCase
{
    private string $base = '';

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            foreach (['composer2', 'composer1', 'other', 'invalid', 'missing', 'scalar'] as $case) {
                @unlink($this->base . '/' . $case . '/vendor/composer/installed.json');
                @rmdir($this->base . '/' . $case . '/vendor/composer');
                @rmdir($this->base . '/' . $case . '/vendor');
                @rmdir($this->base . '/' . $case);
            }
            @rmdir($this->base);
        }
    }

    public function testComposerManagedReadsBothInstalledJsonFormats(): void
    {
        $this->base = sys_get_temp_dir() . '/galbridge-r2tool-' . getmypid() . '-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->base, 0o700));
        $plugin = ['name' => 'takenek/galbridge', 'version' => '1.0.1', 'type' => 'roundcube-plugin'];
        $other = ['name' => 'roundcube/plugin-installer', 'version' => '0.3.8'];
        $cases = [
            'composer2' => [json_encode(['packages' => [$other, $plugin], 'dev' => false]), true],
            'composer1' => [json_encode([$other, $plugin]), true],
            'other' => [json_encode(['packages' => [$other, ['name' => 'takenek/galbridge-fork']]]), false],
            'invalid' => ['{"packages": [', false],
            'scalar' => ['"takenek/galbridge"', false],
            'missing' => [null, false],
        ];
        foreach ($cases as $case => [$json, $expected]) {
            $root = $this->base . '/' . $case;
            self::assertTrue(mkdir($root . '/vendor/composer', 0o700, true));
            if (is_string($json)) {
                self::assertNotFalse(file_put_contents($root . '/vendor/composer/installed.json', $json));
            }
            self::assertSame($expected, Tool::composerManaged($root), $case);
            self::assertSame($expected, Tool::composerManaged($root . '/'), $case . ' (with a trailing slash)');
        }
    }
}
