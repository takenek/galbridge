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
 * Final review of the second verification pass: documentation that would break an installation or
 * describes the code wrongly. Lexical checks, with whitespace and Markdown emphasis collapsed:
 *
 *  DOCS-1 `chown -R root:root plugins/galbridge` also took the PHP group away from a configuration
 *         kept in plugins/galbridge/config.inc.php (root:<PHP group>, mode 0640): unreadable, every
 *         book disabled. The refusals of bin/galbridge.sh check the owner and the write bits only,
 *         so the documents tell to change the owner only;
 *  DOCS-2 the include_host_config upgrade step and the manual steps of the grant templates sent
 *         options into plugins/galbridge/config.inc.php - next to a config/galbridge.inc.php that
 *         is a second configuration file, which disables every book;
 *  DOCS-3 the inventories of the plugin's session keys (SECURITY.md, README 6, threat model GB-15)
 *         name every key the code uses (galbridge_env_noted was missing);
 *  DOCS-4 the register row GB-24 said the DSN is handed to the driver "exactly as written" - it is
 *         rebuilt from the judged parts (Dsn::effective()).
 *
 * Every test fails on the tree before the final review.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2FinalReviewDocsTest extends TestCase
{
    /** the documents an administrator follows, and the texts that ship with the plugin */
    private const DOCUMENTS = ['README.md', 'docs/INSTALLATION.md', 'docs/CONFIGURATION.md', 'docs/OPERATIONS.md', 'SECURITY.md', 'CHANGELOG.md', 'config.inc.php.dist', 'docs/THREAT_MODEL.md', 'docs/ARCHITECTURE.md',
        'docs/RELEASING.md', 'docs/TESTING.md', 'docs/MIGRATION.md', 'SQL/grants.mysql.sql', 'SQL/grants.postgres.sql', 'bin/galbridge.sh',
        'tests/fixtures/vmail/README.txt'];

    public function testNoDocumentTellsToGiveThePluginDirectoryToTheRootGroup(): void
    {
        foreach (self::DOCUMENTS as $file) {
            $flat = self::flat($file);
            self::assertDoesNotMatchRegularExpression('~chown\s+(?:-[A-Za-z]+\s+)*root:root\s+[^\s`]*plugins/galbridge\b~', $flat, "{$file}: chown of the plugin to root:root");
            self::assertDoesNotMatchRegularExpression('~chown\s+-R\s+root:\S*\s+[^\s`]*plugins/galbridge\b~', $flat, "{$file}: a recursive chown of the plugin that sets a group");
        }
        // the full texts of README 2.1, 5 and 7 (docs/ since 1.0.8)
        $readme = self::flat('docs/INSTALLATION.md') . ' ' . self::flat('docs/OPERATIONS.md');
        self::assertSame(3, substr_count($readme, '`chown -R root plugins/galbridge && chmod -R go-w plugins/galbridge`'), 'sections 2.1, 5 and 7');
        self::assertStringContainsString('It changes the owner only: a configuration kept in `plugins/galbridge/config.inc.php` keeps its group – the PHP group, e.g. `www-data` – and mode 0640', $readme);
        self::assertStringContainsString('`chown -R root plugins/galbridge && chmod -R go-w plugins/galbridge` first (the owner only: a `plugins/galbridge/config.inc.php` keeps its PHP group and mode 0640)', self::flat('CHANGELOG.md'));
        // that is enough for the refusals: they compare the owner and the group/other write bits, never the group
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/galbridge.sh');
        self::assertSame(2, substr_count($script, "in_array(\$stat['uid'], \$owners, true)"));
        self::assertStringNotContainsString("\$stat['gid']", $script);
    }

    public function testOptionsAndTheDsnGoIntoThePluginsOneConfigurationFile(): void
    {
        foreach (self::DOCUMENTS as $file) {
            self::assertDoesNotMatchRegularExpression('~\binto `?plugins/galbridge/config\.inc\.php\b~', self::flat($file), "{$file}: options sent into the plugin-directory file");
        }
        $either = "into the plugin's configuration file (`config/galbridge.inc.php`, or `plugins/galbridge/config.inc.php` if that is the one in use – never both";
        self::assertStringContainsString($either, self::flat('docs/OPERATIONS.md'));
        self::assertStringContainsString($either, self::flat('CHANGELOG.md'));
        self::assertStringContainsString("-- 4. Put the DSN (with the password, not the verifier) into the plugin's configuration file, -- config/galbridge.inc.php (README 2.3; or plugins/galbridge/config.inc.php if that is the one -- in use - never both), e.g.", self::flat('SQL/grants.mysql.sql'));
        self::assertStringContainsString("-- 3. Put the DSN into the plugin's configuration file, config/galbridge.inc.php (README 2.3; or -- plugins/galbridge/config.inc.php if that is the one in use - never both), e.g.", self::flat('SQL/grants.postgres.sql'));
    }

    public function testEverySessionKeyOfThePluginIsInTheInventories(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/galbridge.php'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        $keys = [];
        foreach ($files as $file) {
            preg_match_all("/\\\$_SESSION\\['(galbridge[a-z0-9_]*)'\\]/", (string) file_get_contents($file), $m);
            $keys = [...$keys, ...$m[1]];
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        self::assertSame(['galbridge', 'galbridge_down_until', 'galbridge_env_noted', 'galbridge_searchonly'], $keys, 'the session keys of the plugin (extend the inventories with a new one)');

        $security = self::flat('SECURITY.md');
        $assumption = self::between($security, "* Roundcube's session storage is trusted.", 'See [docs/THREAT_MODEL.md]');
        $readme = self::between(self::flat('docs/OPERATIONS.md'), '## 6. Rollback / uninstall', ' 2. ');
        $threat = self::between(self::flat('docs/THREAT_MODEL.md'), '### GB-15 ', '*Tests.*');
        self::assertStringContainsString("`\$_SESSION['galbridge']`", $assumption);
        self::assertStringContainsString("`\$_SESSION['galbridge']`", $threat);
        self::assertStringContainsString('`galbridge`, the resolved user', $readme);
        foreach (array_diff($keys, ['galbridge']) as $key) {
            foreach (['SECURITY.md (important security assumptions)' => $assumption, 'docs/OPERATIONS.md 6' => $readme, 'THREAT_MODEL GB-15' => $threat] as $where => $text) {
                self::assertStringContainsString("`{$key}`", $text, "{$where} names the session key {$key}");
            }
        }
        $words = [3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'];
        self::assertStringContainsString('only ' . $words[count($keys)] . ' short-lived session keys', $readme);
        // what the new key holds - never the values of the variables
        self::assertStringContainsString('`galbridge_env_noted` (the names of the `ROUNDCUBE_GALBRIDGE_*` variables in effect, kept so that `env_override` is logged once per session – never their values)', $assumption);
    }

    public function testTheRegisterDescribesTheDsnTheDriverGets(): void
    {
        $security = self::flat('SECURITY.md');
        self::assertStringNotContainsString('handed to the driver exactly as written', $security);
        self::assertStringContainsString('The DSN is otherwise judged and handed to the driver rebuilt from exactly the judged parts; `localhost` is kept as written, not resolved or rewritten by the plugin (docs/CONFIGURATION.md 3.9).', $security);
        // and that is what docs/CONFIGURATION.md 3.9 and the code say
        self::assertStringContainsString('The DSN handed to the driver is rebuilt from the', self::flat('docs/CONFIGURATION.md'));
        self::assertStringContainsString('rebuilt from the judged parts', self::flat('lib/GalBridge/Db/Dsn.php'));
    }

    /** the text from the first occurrence of $from to the next $to after it */
    private static function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertIsInt($start, "missing: {$from}");
        $end = strpos($text, $to, $start + strlen($from));
        return substr($text, $start, $end === false ? null : $end - $start);
    }

    /**
     * The file with runs of whitespace collapsed to one blank and Markdown emphasis removed.
     */
    private static function flat(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        self::assertIsString($text, $file);
        return (string) preg_replace(['/\*\*/', '/\s+/'], ['', ' '], $text);
    }
}
