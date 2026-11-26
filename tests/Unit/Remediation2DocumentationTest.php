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
 * Second remediation pass, F-35: security statements of the documentation and the comments that
 * said more (or something else) than the code does. Lexical checks of the corrected texts, with
 * whitespace and Markdown emphasis collapsed:
 *
 *  (a) "a malformed deny-list disables every book" - a domain deny-list disables the book it
 *      protects, a malformed list of refused roles refuses every connection;
 *  (b) "the conservative value of every switch" - the restrictive value (Config::RESTRICTIVE);
 *  (c) the user of the password plugin's DSN is read from the plugin's own file as well;
 *  (e) the action name init() sees is request input (asciiwords()), not "resolved";
 *  (f) "( sorts before letters and digits" holds from Roundcube 1.7.6 only;
 *  (g) the inventory of core paths names vcard_attachments and rcmail::contact_exists();
 *  (i) example domains are reserved names;
 *  and the claim, left over after F-17, that a NEW own alias waits for galbridge_scope_ttl.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DocumentationTest extends TestCase
{
    private const DOCS = ['docs/CONFIGURATION.md', 'SECURITY.md', 'config.inc.php.dist', 'docs/THREAT_MODEL.md', 'docs/ARCHITECTURE.md'];

    public function testDenyListSummariesDistinguishTheProtectedBook(): void
    {
        foreach (['SECURITY.md', 'config.inc.php.dist', 'docs/THREAT_MODEL.md'] as $file) {
            $text = self::flat($file);
            self::assertStringContainsString('the book it protects', $text, $file);
            self::assertMatchesRegularExpression('/refuses every (database )?connection/', $text, $file . ': the refused roles');
        }
        self::assertStringNotContainsString('a malformed deny-list or hidden list never means', self::flat('SECURITY.md'));
        self::assertStringNotContainsString('where the option protects something, every book is disabled', self::flat('docs/THREAT_MODEL.md'));
        self::assertStringNotContainsString('where the option protects something `Config::booksDisabled()` is true', self::flat('docs/ARCHITECTURE.md'));
        self::assertStringNotContainsString('given as something else where it protects something', self::flat('config.inc.php.dist'));
    }

    public function testAmbiguousSwitchWordingIsRestrictive(): void
    {
        foreach (['lib/GalBridge/Config.php', 'lib/GalBridge/Cli/Tool.php', 'README.md', 'docs/INSTALLATION.md', 'docs/CONFIGURATION.md', 'docs/OPERATIONS.md', 'docs/ARCHITECTURE.md'] as $file) {
            self::assertStringNotContainsString('conservative value', self::flat($file), $file);
        }
        self::assertStringContainsString('restrictive value it is read as', self::flat('docs/INSTALLATION.md'));
        self::assertStringContainsString('Config::RESTRICTIVE', self::flat('docs/ARCHITECTURE.md'));
        self::assertStringContainsString('Config::RESTRICTIVE', self::flat('docs/THREAT_MODEL.md'));
    }

    public function testPasswordPluginDsnScopeIsDescribed(): void
    {
        $dist = self::flat('config.inc.php.dist');
        self::assertStringContainsString('plugins/password/config.inc.php', $dist);
        self::assertStringContainsString('config/password.inc.php', $dist);
        self::assertStringNotContainsString('when that option is set in Roundcube\'s main configuration', $dist);
    }

    public function testTheActionNameIsDescribedAsRequestInput(): void
    {
        $threat = self::flat('docs/THREAT_MODEL.md');
        self::assertStringNotContainsString('resolved action name', $threat);
        self::assertStringContainsString('asciiwords()', $threat);
        self::assertStringContainsString('before the `startup` and `ready` hooks', $threat);
    }

    public function testTheSuggestionOrderNamesTheCoreRelease(): void
    {
        foreach (self::DOCS as $file) {
            $text = self::flat($file);
            $found = preg_match_all('/before letters and digits/', $text, $m, PREG_OFFSET_CAPTURE);
            self::assertGreaterThan(0, $found, $file . ' describes the order of suggestions');
            foreach ($m[0] as [, $offset]) {
                $around = substr($text, max(0, $offset - 400), 800);
                self::assertStringContainsString('1.7.6', $around, $file . ': the order holds from Roundcube 1.7.6');
            }
            self::assertMatchesRegularExpression('/1\.7\.0 (–|-) 1\.7\.5/', $text, $file . ': and the releases before');
        }
        self::assertStringNotContainsString('by name (ICU collation)', self::flat('docs/THREAT_MODEL.md'));
    }

    public function testTheInventoryNamesEveryCorePath(): void
    {
        foreach (['docs/CONFIGURATION.md', 'docs/ARCHITECTURE.md'] as $file) {
            $text = self::flat($file);
            self::assertStringContainsString('vcard_attachments', $text, $file);
            self::assertStringContainsString('rcmail::contact_exists()', $text, $file);
        }
        $threat = self::flat('docs/THREAT_MODEL.md');
        self::assertMatchesRegularExpression('/### GB-04 [^#]*vcard_attachments/', $threat);
        self::assertMatchesRegularExpression('/### GB-17 [^#]*contact_exists/', $threat);
    }

    public function testTheCoreMethodThatRendersRecipientInputsIsNamedRight(): void
    {
        foreach (['galbridge.php', 'docs/ARCHITECTURE.md', 'tests/Integration/PluginHooksTest.php'] as $file) {
            self::assertStringNotContainsString('compose_headers', self::flat($file), $file);
        }
        self::assertStringContainsString('rcmail_sendmail::headers_output()', self::flat('galbridge.php'));
        self::assertStringContainsString('rcmail_action::autocomplete_init()', self::flat('docs/ARCHITECTURE.md'));
        // the "autocomplete" key of a source entry is described as what it is
        self::assertStringContainsString('Roundcube 1.7 does not read this key', self::flat('galbridge.php'));
        self::assertStringContainsString('Roundcube 1.7 does not read this key', self::flat('docs/ARCHITECTURE.md'));
    }

    public function testExamplesUseReservedDomainNames(): void
    {
        $files = [...self::DOCS, 'CHANGELOG.md', 'docs/MIGRATION.md', 'docs/TESTING.md', 'docs/RELEASING.md'];
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(acme\.(pl|com|de)|example\.co\.uk|example\.ie)\b/', self::flat($file), $file . ': a domain anybody can register');
        }
    }

    /**
     * F-17 reads the own aliases again with every reading request (strict list policy): a new alias
     * is taken over at once. No document may still say that it waits for the cache.
     */
    public function testANewOwnAliasIsNotSaidToWaitForTheCache(): void
    {
        $pattern = '/new (or removed )?own alias(es)?[^.;]{0,90}\b(apply|applies|are seen|is seen|within)\b[^.;]{0,40}(renewed|galbridge_scope_ttl|cache time|at most)/i';
        foreach ([...self::DOCS, 'lib/GalBridge/Cli/Tool.php'] as $file) {
            self::assertDoesNotMatchRegularExpression($pattern, self::flat($file), $file);
        }
        self::assertStringNotContainsString('and a new own alias, a changed column grant and the role checks apply', self::flat('lib/GalBridge/Cli/Tool.php'));
    }

    /**
     * The file with runs of whitespace (and the line-comment markers of the .dist file) collapsed
     * to one blank and Markdown emphasis removed.
     */
    private static function flat(string $file): string
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $text = (string) preg_replace('~\n\s*(//|\*(?!\*))\s?~', "\n", $text);
        return (string) preg_replace(['/\*\*/', '/\s+/'], ['', ' '], $text);
    }
}
