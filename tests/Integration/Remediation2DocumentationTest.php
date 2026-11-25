<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-35: what the documentation and the comments say is what the code does.
 *
 *  (b) an ambiguous switch was said to be read as "the conservative value of every switch", but it
 *      was read as its default - which for six switches (domain_book, domain_autocomplete,
 *      domain_export, global_autocomplete, include_aliases, include_mailing_lists) is the permissive
 *      one. Every book is disabled meanwhile; now the switch itself is read as its restrictive value
 *      as well (Config::RESTRICTIVE), and diag shows that value;
 *  (d) the comments and the architecture named rcmail_sendmail::compose_headers(), which no
 *      Roundcube 1.7 release has: recipient inputs are rendered by headers_output(), which calls
 *      rcmail_action::autocomplete_init();
 *  (f) the order of autocomplete suggestions ("(" before letters) holds only from Roundcube 1.7.6
 *      (rcube_utils::asort(), the ICU collator); before, the C library locale of the user's language
 *      decides. diag describes the order of the core it runs with.
 *
 * Every test fails on 1.0.1. The diag cases start bin/galbridge.sh (as root with --allow-root when the
 * suite runs as root, CliRun) against a server DSN.
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2DocumentationTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
    }

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    // ------------------------------------------------------------------ (b)

    public function testEverySwitchHasARestrictiveValue(): void
    {
        $expected = [
            'galbridge_db_allow_plaintext' => false,
            'galbridge_db_allow_verify_ca' => false,
            'galbridge_imap_allow_plaintext' => false,
            'galbridge_domain_book' => false,
            'galbridge_domain_autocomplete' => false,
            'galbridge_domain_export' => false,
            'galbridge_global_book' => false,
            'galbridge_global_allow_wildcard' => false,
            'galbridge_global_include_home' => false,
            'galbridge_global_autocomplete' => false,
            'galbridge_global_export' => false,
            'galbridge_user_aliases' => false,
            'galbridge_include_aliases' => false,
            'galbridge_include_mailing_lists' => false,
            'galbridge_hide_expired' => true,
            'galbridge_mark_foreign_domains' => true,
            'galbridge_debug' => false,
        ];
        self::assertSame(Config::BOOLEANS, array_keys(Config::RESTRICTIVE), 'one restrictive value per switch, in the order of BOOLEANS');
        self::assertSame($expected, Config::RESTRICTIVE);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function switches(): array
    {
        $out = [];
        foreach (Config::BOOLEANS as $name) {
            $out[$name] = [$name];
        }
        return $out;
    }

    #[DataProvider('switches')]
    public function testAnAmbiguousSwitchIsReadAsItsRestrictiveValue(string $name): void
    {
        foreach (['yes', 'false', [true], 2] as $value) {
            $cfg = Env::config(Env::sqliteDsn(), [$name => $value]);
            self::assertNull($cfg->effectiveSwitch($name), $name);
            self::assertTrue($cfg->booksDisabled(), $name . ': every book is disabled');
            self::assertSame(Config::RESTRICTIVE[$name], $cfg->bool($name), $name . ' = ' . var_export($value, true) . ' reads as its restrictive value');
        }
        // nothing changes for a valid value
        foreach ([true, false, 1, 0] as $value) {
            $cfg = Env::config(Env::sqliteDsn(), [$name => $value]);
            self::assertSame((bool) $value, $cfg->bool($name), $name);
        }
    }

    public function testTheSixPermissiveDefaultsAreNotReadWhileAmbiguous(): void
    {
        foreach (['galbridge_domain_book', 'galbridge_domain_autocomplete', 'galbridge_domain_export', 'galbridge_global_autocomplete', 'galbridge_include_aliases', 'galbridge_include_mailing_lists'] as $name) {
            self::assertTrue(Config::DEFAULTS[$name], $name . ' is on by default');
            self::assertFalse(Env::config(Env::sqliteDsn(), [$name => 'on'])->bool($name), $name . " = 'on' is not read as on");
        }
        // the environment override of a switch is parsed by the plugin: an ambiguous text as well
        putenv('ROUNDCUBE_GALBRIDGE_INCLUDE_ALIASES=yes');
        try {
            $cfg = Env::config(Env::sqliteDsn());
            self::assertNull($cfg->effectiveSwitch('galbridge_include_aliases'));
            self::assertFalse($cfg->bool('galbridge_include_aliases'));
            self::assertTrue($cfg->booksDisabled());
        } finally {
            putenv('ROUNDCUBE_GALBRIDGE_INCLUDE_ALIASES');
        }
    }

    public function testDiagShowsTheRestrictiveValueOfAnAmbiguousSwitch(): void
    {
        $dsn = $this->serverDsn();
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_domain_book' => 'yes']);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'FAIL', 'galbridge_domain_book', 'must be the boolean true or false');
        CliRun::assertLine($out, 'INFO', 'switches (books)', 'domain_book off (INVALID)');
        self::assertStringNotContainsString('domain_book on (INVALID)', $out);

        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_include_aliases' => 'on']);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(2, $code, $out . $err);
        CliRun::assertLine($out, 'INFO', 'switches (entries)', 'include_aliases off (INVALID)');
        self::assertSame(1, substr_count($out, '(INVALID)'), 'the other switches are shown as they are');
    }

    // ------------------------------------------------------------------ (f)

    public function testDiagDescribesTheSuggestionOrderOfThisCore(): void
    {
        $dsn = $this->serverDsn();
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']]);
        [$code, $out, $err] = CliRun::diag($config);
        self::assertSame(0, $code, $out . $err);
        CliRun::assertLine($out, 'INFO', 'autocomplete order', 'entries of other domains start with their "(domain)" marker and Roundcube sorts its suggestions by name');
        CliRun::assertLine($out, 'INFO', 'autocomplete order', 'galbridge_global_autocomplete = false keeps the global book out of autocomplete');
        // the child process runs the core of GALBRIDGE_RC, as this process does
        $icu = is_callable([\rcube_utils::class, 'asort']);
        self::assertSame($icu, Tool::coreSortsWithIcu());
        if (preg_match('/^\d+\.\d+\.\d+$/D', \RCUBE_VERSION) === 1) {
            // a release (a checkout from Packagist reports "1.7-git" and is judged by the method alone)
            self::assertSame(version_compare(\RCUBE_VERSION, '1.7.6', '>='), $icu, 'rcube_utils::asort() exists from Roundcube 1.7.6 on');
        }
        if ($icu) {
            CliRun::assertLine($out, 'INFO', 'autocomplete order', 'with the ICU collator (1.7.6 and later), where "(" comes before letters and digits: they are listed before unmarked names');
            self::assertStringNotContainsString('before 1.7.6', $out);
        } else {
            CliRun::assertLine($out, 'INFO', 'autocomplete order', 'with the C library locale of the user\'s language (before 1.7.6): "(" is usually ignored there');
            self::assertStringNotContainsString('ICU collator', $out);
        }
    }

    /**
     * What the version-dependent text rests on: before 1.7.6 mail/autocomplete sorts with PHP's
     * asort(SORT_LOCALE_STRING), from 1.7.6 with rcube_utils::asort().
     */
    public function testTheCoreSortsAsTheDocumentationSays(): void
    {
        $action = (string) file_get_contents(\RCUBE_INSTALL_PATH . 'program/actions/mail/autocomplete.php');
        if (Tool::coreSortsWithIcu()) {
            self::assertStringContainsString('rcube_utils::asort(', $action);
        } else {
            self::assertStringContainsString('SORT_LOCALE_STRING', $action);
            self::assertStringNotContainsString('rcube_utils::asort(', $action);
        }
    }

    // ------------------------------------------------------------------ (d)

    public function testTheCoreMethodsNamedInTheDocumentationExist(): void
    {
        self::assertTrue(method_exists(\rcmail_sendmail::class, 'headers_output'));
        self::assertTrue(method_exists(\rcmail_action::class, 'autocomplete_init'));
        self::assertFalse(method_exists(\rcmail_sendmail::class, 'compose_headers'), 'the method the old comments named');
        $root = CliRun::pluginDir();
        foreach (['galbridge.php', 'docs/ARCHITECTURE.md', 'tests/Integration/PluginHooksTest.php'] as $file) {
            $text = (string) file_get_contents($root . '/' . $file);
            self::assertStringNotContainsString('compose_headers', $text, $file);
            preg_match_all('/\b(rcmail_sendmail|rcmail_action|rcmail)::([a-z_]+)\(/', $text, $m, PREG_SET_ORDER);
            foreach ($m as [, $class, $method]) {
                self::assertTrue(method_exists($class, $method), $file . ' names ' . $class . '::' . $method . '(), which this core does not have');
            }
        }
        // the "autocomplete" key of a source entry is not read by the core (informational)
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\RCUBE_INSTALL_PATH . 'program', \FilesystemIterator::SKIP_DOTS));
        $readers = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')
                && preg_match('/\[\s*[\'"]autocomplete[\'"]\s*\]/', (string) file_get_contents($file->getPathname())) === 1) {
                $readers[] = $file->getPathname();
            }
        }
        self::assertSame([], $readers, 'Roundcube reads $source[\'autocomplete\']: update galbridge.php and ARCHITECTURE 3');
    }

    private function serverDsn(): string
    {
        $dsn = CliRun::serverDsn();
        if ($dsn === null) {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN (diag connects)');
        }
        return $dsn;
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2doc');
        }
        return $this->base;
    }
}
