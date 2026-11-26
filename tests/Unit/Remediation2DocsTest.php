<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass: documentation that was wrong in a way that hurts.
 *
 *  F-09 SECURITY.md and the threat model recommended listing galbridge_* options - galbridge_db_dsn
 *       first - in Roundcube's dont_override; with an option set through the environment Roundcube
 *       then stores it, the database password included, in user preferences. No document may
 *       recommend it any more;
 *  F-10 the README said that "composer update keeps plugins/galbridge/config.inc.php" - the plugin
 *       installer re-creates it with the umask of the user running Composer and keeps a full copy of
 *       the plugin directory next to it; the recommended nginx rule did not cover that copy.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DocsTest extends TestCase
{
    private const DOCUMENTS = ['README.md', 'docs/INSTALLATION.md', 'docs/CONFIGURATION.md', 'docs/OPERATIONS.md', 'SECURITY.md', 'CHANGELOG.md', 'config.inc.php.dist',
        'docs/THREAT_MODEL.md', 'docs/ARCHITECTURE.md', 'docs/RELEASING.md'];

    public function testNoDocumentRecommendsGalbridgeOptionsInDontOverride(): void
    {
        foreach (self::DOCUMENTS as $file) {
            $text = self::read($file);
            // a clause that tells to list / add / put galbridge_* options into dont_override must say
            // the opposite (not, never, remove ...) - CHANGELOG entries describe the old advice
            $flat = (string) preg_replace(['/\s+/', '/\be\.g\./i'], [' ', 'eg'], $text);
            foreach (preg_split('/(?<=[.;!?])\s+/', $flat) ?: [] as $clause) {
                if (preg_match('/\b(list|add|put)\b[^.;]*galbridge_[^.;]*dont_override|\b(list|add|put)\b[^.;]*dont_override[^.;]*galbridge_/i', $clause) === 1) {
                    self::assertMatchesRegularExpression('/\b(not|never|no longer|remove|withdrawn|recommended)\b/i', $clause, $file . ': ' . $clause);
                }
            }
        }
        $security = self::read('SECURITY.md');
        self::assertStringNotContainsString('additionally list', $security);
        self::assertStringContainsString('**Do not list `galbridge_*` options in `dont_override`.**', $security);
        self::assertStringNotContainsString('recommends listing the security-relevant options in `dont_override`', self::read('docs/THREAT_MODEL.md'));
        self::assertStringContainsString('Never add galbridge_* options to dont_override', self::read('config.inc.php.dist'));
    }

    public function testNoDocumentSaysThatComposerKeepsTheConfiguration(): void
    {
        // (CHANGELOG.md quotes the old sentence in the entry that corrects it)
        foreach (array_diff(self::DOCUMENTS, ['CHANGELOG.md']) as $file) {
            $flat = (string) preg_replace('/\s+/', ' ', self::read($file));
            self::assertDoesNotMatchRegularExpression('/composer update`? keeps/i', $flat, $file);
        }
        $readme = (string) preg_replace('/\s+/', ' ', self::read('docs/INSTALLATION.md'));
        self::assertStringContainsString('**Keep the configuration in `config/galbridge.inc.php` for a Composer installation:**', $readme);
        self::assertStringContainsString('composer require --prefer-dist', $readme);
        self::assertStringContainsString('composer require --prefer-dist', self::read('docs/RELEASING.md'));
    }

    public function testTheNginxRuleCoversTheBackupCopyOfTheInstaller(): void
    {
        $readme = self::read('README.md');
        self::assertStringContainsString('location ~ ^/(mail/)?(static\.php/)?plugins/galbridge(-[0-9]+)?/ { deny all; }', $readme);
        self::assertStringNotContainsString('plugins/galbridge/ { deny all; }', $readme);
        // and it matches what the installer creates, but no other plugin
        $rule = '~^/(mail/)?(static\.php/)?plugins/galbridge(-[0-9]+)?/~';
        foreach (['/mail/plugins/galbridge/config.inc.php', '/static.php/plugins/galbridge-01234567890123456789/config.inc.php', '/plugins/galbridge-01234567890123456789/lib/autoload.php'] as $path) {
            self::assertMatchesRegularExpression($rule, $path);
        }
        self::assertDoesNotMatchRegularExpression($rule, '/mail/plugins/galbridgeX/x');
        self::assertDoesNotMatchRegularExpression($rule, '/mail/plugins/password/config.inc.php');
    }

    private static function read(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        self::assertIsString($text, $file);
        return $text;
    }
}
