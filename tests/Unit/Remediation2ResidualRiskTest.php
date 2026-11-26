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
 * Second remediation pass, F-36: the residual risks of documented decisions are in the register,
 * and no document claims more than the code does. Lexical checks:
 *
 *  - the register of SECURITY.md has rows for the configuration file in the plugin directory
 *    (GB-31), debug texts and log volume (GB-22) and the PostgreSQL trade-off between the privacy of
 *    search terms and a statement audit trail (GB-21); GB-04 names group IDs and GB-30 the verifier
 *    as a secret; every row is complete and names a threat of docs/THREAT_MODEL.md;
 *  - "a crafted request loop cannot flood the mail log" is gone (the bound is one line per reason
 *    code per request);
 *  - the unverifiable "Both locations are denied by iRedMail's nginx templates" is gone, and
 *    config/galbridge.inc.php is the recommended place; the .htaccess precondition is exact;
 *  - the Logs section names browser history and proxies, per_user_logging and the syslog priority.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ResidualRiskTest extends TestCase
{
    public function testTheRegisterHoldsTheResidualRisks(): void
    {
        $rows = self::registerRows();
        self::assertArrayHasKey('GB-31', $rows);
        self::assertArrayHasKey('GB-22', $rows);
        self::assertStringContainsString('plugins/galbridge/config.inc.php', implode(' ', $rows['GB-31']));
        self::assertStringContainsString('diag', implode(' ', $rows['GB-31']));
        self::assertStringContainsString('galbridge_debug', implode(' ', $rows['GB-22']));
        self::assertStringContainsString('once per request', implode(' ', $rows['GB-22']));
        self::assertMatchesRegularExpression('/pgaudit.*audit trail|audit trail.*pgaudit/', implode(' ', $rows['GB-21']));
        self::assertStringContainsString('group IDs', implode(' ', $rows['GB-04']));
        self::assertStringContainsString('browser history', implode(' ', $rows['GB-04']));
        self::assertMatchesRegularExpression('/verifier counts as a secret/', implode(' ', $rows['GB-30']));
        self::assertStringContainsString('without a port', implode(' ', $rows['GB-11']));
        self::assertStringContainsString('one line per reason code per request', implode(' ', $rows['GB-26']));
        self::assertStringContainsString('1.7.6', implode(' ', $rows['GB-19']));
    }

    public function testEveryRegisterRowIsCompleteAndNamesAThreat(): void
    {
        $threat = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/THREAT_MODEL.md');
        preg_match_all('/^### (GB-\d\d) /m', $threat, $m);
        $threats = $m[1];
        $security = (string) file_get_contents(dirname(__DIR__, 2) . '/SECURITY.md');
        preg_match_all('/^\| GB-.*$/m', $security, $lines);
        self::assertGreaterThan(20, count($lines[0]));
        foreach ($lines[0] as $line) {
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            self::assertCount(6, $cells, $line);
            self::assertContains($cells[0], $threats, $cells[0] . ' is a threat of docs/THREAT_MODEL.md');
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/D', $cells[5], $cells[0] . ': next review date');
            foreach ($cells as $cell) {
                self::assertNotSame('', $cell, $line);
            }
        }
    }

    public function testNoDocumentSaysTheLogCannotBeFlooded(): void
    {
        self::assertStringNotContainsString('a crafted request loop cannot flood the mail log', self::flat('docs/THREAT_MODEL.md'));
        self::assertStringNotContainsString('must not be able to flood the mail log', self::flat('lib/GalBridge/Log.php'));
        foreach (['docs/THREAT_MODEL.md', 'SECURITY.md', 'docs/CONFIGURATION.md'] as $file) {
            self::assertStringContainsString('one line per reason code per request', self::flat($file), $file);
        }
    }

    public function testTheConfigurationDirectoryIsRecommended(): void
    {
        $readme = self::flat('docs/INSTALLATION.md');
        self::assertStringNotContainsString('Both locations are denied by iRedMail', $readme . ' ' . self::flat('README.md'));
        self::assertStringContainsString('Use `config/galbridge.inc.php`.', $readme);
        self::assertStringContainsString('install -o root -g www-data -m 0640 plugins/galbridge/config.inc.php.dist config/galbridge.inc.php', $readme);
        self::assertStringContainsString('config over HTTP', $readme);
        self::assertStringContainsString('<roundcube>/config/galbridge.inc.php (recommended)', self::flat('config.inc.php.dist'));
        self::assertStringNotContainsString('denied by iRedMail\'s templates', self::flat('docs/THREAT_MODEL.md'));
        self::assertStringContainsString('-- Put this line into config/galbridge.inc.php', (string) file_get_contents(dirname(__DIR__, 2) . '/lib/GalBridge/Cli/Tool.php'));
    }

    public function testTheHtaccessPreconditionIsExact(): void
    {
        foreach (['.htaccess', 'docs/INSTALLATION.md', 'SECURITY.md'] as $file) {
            self::assertStringContainsString('AllowOverride AuthConfig', self::flat($file), $file);
        }
    }

    public function testTheLogsSectionNamesWhereDataEndsUp(): void
    {
        $security = self::flat('SECURITY.md');
        $logs = substr($security, (int) strpos($security, '## Logs'), (int) strpos($security, '## Accepted risks') - (int) strpos($security, '## Logs'));
        foreach (['browser history', 'proxy', 'per_user_logging', 'priority INFO', 'errors', 'pgaudit', 'auto_explain', 'log_connections', 'verifier is a secret', 'diag` warns while it is on'] as $needle) {
            self::assertStringContainsString($needle, $logs, $needle);
        }
        self::assertStringContainsString('Debug logging off in normal operation', $security);
    }

    /**
     * @return array<string, list<string>> the cells of each register row, rows of one ID joined
     */
    private static function registerRows(): array
    {
        $security = (string) file_get_contents(dirname(__DIR__, 2) . '/SECURITY.md');
        preg_match_all('/^\| (GB-\d\d) \|(.*)$/m', $security, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as [, $id, $rest]) {
            $rows[$id] = [...($rows[$id] ?? []), $rest];
        }
        return $rows;
    }

    private static function flat(string $file): string
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        // comment markers of PHP and of the .dist file (and of .htaccess); Markdown headings stay
        $text = (string) preg_replace($file === '.htaccess' ? '~(^|\n)\s*#\s?~' : '~\n\s*(//|\*(?!\*))\s?~', "\n", $text);
        return (string) preg_replace(['/\*\*/', '/\s+/'], ['', ' '], $text);
    }
}
