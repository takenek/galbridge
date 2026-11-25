<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Workflow;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-24: the secret scanner excepts named files and exact values only, it
 * knows database DSNs with a password, and git ignores the copies of the configuration and the grant
 * outputs an administrator or an editor leaves in a checkout.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2RepositoryHygieneTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function git(): string
    {
        foreach (['/usr/bin/git', '/bin/git', '/usr/local/bin/git'] as $p) {
            if (is_executable($p)) {
                [$code] = CliRun::exec([$p, '-C', self::root(), 'rev-parse', '--git-dir']);
                if ($code === 0) {
                    return $p;
                }
            }
        }
        self::markTestSkipped('needs git and a git checkout');
    }

    /**
     * @return list<string> tracked and new (not ignored) files of the working tree
     */
    private static function files(): array
    {
        [$code, $out] = CliRun::exec([self::git(), '-C', self::root(), 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
        self::assertSame(0, $code);
        return array_values(array_filter(explode("\0", $out), static fn (string $f): bool => $f !== '' && is_file(self::root() . '/' . $f)));
    }

    private static function ignored(string $path): bool
    {
        [$code] = CliRun::exec([self::git(), '-C', self::root(), 'check-ignore', '--no-index', '-q', $path]);
        self::assertContains($code, [0, 1], 'git check-ignore failed for ' . $path);
        return $code === 0;
    }

    /**
     * The [[allowlists]] tables of .gitleaks.toml: description => [paths, regexes].
     *
     * @return array<string, array{paths: list<string>, regexes: list<string>}>
     */
    private static function allowlists(): array
    {
        $toml = (string) file_get_contents(self::root() . '/.gitleaks.toml');
        $out = [];
        foreach (preg_split('/^\[\[allowlists\]\]\n/m', $toml) ?: [] as $i => $block) {
            if ($i === 0) {
                continue;
            }
            $block = (string) preg_replace('/^\[.*\z/ms', '', $block);
            self::assertSame(1, preg_match('/^description = "([^"]+)"$/m', $block, $d));
            $entry = ['paths' => [], 'regexes' => []];
            foreach (['paths', 'regexes'] as $key) {
                if (preg_match('/^' . $key . ' = \[\n(.*?)^\]/ms', $block, $m) === 1) {
                    preg_match_all("/^\\s+'''(.*)''',$/m", $m[1], $items);
                    $entry[$key] = $items[1];
                }
            }
            $out[$d[1]] = $entry;
        }
        return $out;
    }

    /**
     * @return array{regex: string, entropy: float}
     */
    private static function dsnRule(): array
    {
        $toml = (string) file_get_contents(self::root() . '/.gitleaks.toml');
        self::assertSame(1, preg_match('/^\[\[rules\]\]\nid = "galbridge-dsn-password"\n(.*?)(?=^\[|\z)/ms', $toml, $m), 'the rule galbridge-dsn-password');
        self::assertSame(1, preg_match("/^regex = '''(.*)'''$/m", $m[1], $r));
        self::assertSame(1, preg_match('/^entropy = ([0-9.]+)$/m', $m[1], $e));
        self::assertSame(1, preg_match('/^secretGroup = 1$/m', $m[1]));
        // an RE2 literal that PCRE reads the same way (no RE2-only syntax in it)
        return ['regex' => '~' . str_replace('~', '\~', $r[1]) . '~', 'entropy' => (float) $e[1]];
    }

    /** Shannon entropy in bits per character, as gitleaks computes it for a secret */
    private static function entropy(string $s): float
    {
        $h = 0.0;
        $n = strlen($s);
        foreach (count_chars($s, 1) as $count) {
            $p = $count / $n;
            $h -= $p * log($p, 2);
        }
        return $h;
    }

    private static function excusedValue(string $secret): bool
    {
        foreach (self::allowlists() as $list) {
            foreach ($list['regexes'] as $re) {
                if (preg_match('~' . str_replace('~', '\~', $re) . '~', $secret) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    public function testCopiesOfTheConfigurationAndGrantOutputsAreIgnored(): void
    {
        self::git();
        foreach (['config.inc.php', 'config.inc.php.bak', 'config.inc.php.orig', 'config.inc.php~', 'config.inc.php.2026-11-25', 'galbridge.inc.php',
            'galbridge.inc.php.old', 'config/galbridge.inc.php', 'galbridge-grants.sql', '.env', '.env.local', 'lib/GalBridge/Config.php.orig',
            'lib/GalBridge/Config.php~', 'SQL/grants.mysql.sql.bak'] as $copy) {
            self::assertTrue(self::ignored($copy), "{$copy} must be git-ignored");
        }
        foreach (['config.inc.php.dist', 'SQL/grants.mysql.sql', 'SQL/grants.postgres.sql', 'tests/fixtures/vmail/seed.sql',
            'tests/fixtures/vmail/schema.mysql.sql', 'galbridge.php', 'composer.json'] as $tracked) {
            self::assertFalse(self::ignored($tracked), "{$tracked} must stay tracked");
        }
        [$code, $out] = CliRun::exec([self::git(), '-C', self::root(), 'ls-files', '-ci', '--exclude-standard']);
        self::assertSame(0, $code);
        self::assertSame('', $out, 'no tracked file matches an ignore pattern');
    }

    /**
     * The exceptions name files that exist (anchored at the top of the repository), never a
     * directory of test data; directory patterns are allowed only for git-ignored local directories.
     */
    public function testSecretScannerExceptionsNameFilesNotDirectories(): void
    {
        $lists = self::allowlists();
        self::assertNotEmpty($lists);
        $tracked = self::files();
        $runDirs = 'git-ignored local run directories and tool caches';
        self::assertArrayHasKey($runDirs, $lists);
        foreach ($lists as $description => $list) {
            foreach ($list['paths'] as $path) {
                self::assertStringStartsWith('^(?:\./)?', $path, "{$description}: anchored at the top of the repository");
                if (str_ends_with($path, '/')) {
                    self::assertSame($runDirs, $description, "a directory pattern outside the run-directory group: {$path}");
                    $dir = stripslashes(substr($path, strlen('^(?:\./)?')));
                    self::assertTrue(self::ignored($dir), "{$dir} is not git-ignored");
                    continue;
                }
                self::assertStringEndsWith('$', $path, "{$description}: a whole file name: {$path}");
                $matched = array_values(array_filter($tracked, static fn (string $f): bool => preg_match('~' . $path . '~', $f) === 1));
                self::assertNotEmpty($matched, "{$path} names no file of the repository");
                self::assertCount(1, $matched, "{$path} names one file");
            }
        }
        $toml = (string) file_get_contents(self::root() . '/.gitleaks.toml');
        self::assertStringNotContainsString("tests/fixtures/vmail/'''", $toml, 'no exception for the whole fixture directory');
        self::assertStringNotContainsString('setup\.sh', $toml);
        self::assertStringNotContainsString('rc\.py', $toml);
    }

    public function testSecretScannerReportsADatabasePasswordInADsn(): void
    {
        ['regex' => $re, 'entropy' => $min] = self::dsnRule();
        self::assertGreaterThanOrEqual(3.0, $min);
        $lowest = 8.0;
        for ($i = 0; $i < 500; $i++) {
            // the password "bin/galbridge.sh grants" generates
            $password = bin2hex(random_bytes(20));
            self::assertSame(1, preg_match($re, "\$config['galbridge_db_dsn'] = 'mysql://galbridge:{$password}@127.0.0.1/vmail';", $m));
            self::assertSame($password, $m[1]);
            $lowest = min($lowest, self::entropy($m[1]));
            self::assertFalse(self::excusedValue($m[1]));
        }
        self::assertGreaterThanOrEqual($min, $lowest, 'every generated password is above the entropy threshold');
        foreach (['pgsql://galbridge_ro:' . bin2hex(random_bytes(20)) . '@db.example/vmail', 'postgresql://u:' . bin2hex(random_bytes(12)) . '@h/d',
            'MYSQL://user:' . bin2hex(random_bytes(10)) . '@[::1]/vmail'] as $dsn) {
            self::assertSame(1, preg_match($re, $dsn), $dsn);
        }
        // the throw-away values are too short, the placeholder is excused by value
        foreach (['mysql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test', 'mysql://galbridge:secret@h/d'] as $dsn) {
            self::assertSame(0, preg_match($re, $dsn), $dsn);
        }
        self::assertSame(1, preg_match($re, 'mysql://galbridge:CHANGE_ME_RANDOM_PASSWORD@127.0.0.1/vmail', $m));
        self::assertTrue(self::excusedValue($m[1]));
        foreach (['ci-throwaway', 'testonly-ro', 'testpass', 'Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU'] as $value) {
            self::assertTrue(self::excusedValue($value), $value);
        }
        foreach (['testpass-and-more', 'xtestpass', 'CHANGE_ME_' . "\n" . 'x'] as $value) {
            self::assertFalse(self::excusedValue($value), 'excused by the whole value only: ' . $value);
        }
    }

    /**
     * A guard: no file of the repository holds what the rule would report, so the rule stays free of
     * false positives (tests use short or excused values).
     */
    public function testNoTrackedFileHoldsADsnPasswordTheRuleWouldReport(): void
    {
        ['regex' => $re, 'entropy' => $min] = self::dsnRule();
        $excepted = [];
        foreach (self::allowlists() as $list) {
            $excepted = [...$excepted, ...$list['paths']];
        }
        $found = [];
        foreach (self::files() as $file) {
            foreach ($excepted as $path) {
                if (preg_match('~' . $path . '~', $file) === 1) {
                    continue 2;
                }
            }
            $text = (string) file_get_contents(self::root() . '/' . $file);
            if (preg_match_all($re, $text, $all, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($all[1] as [$secret, $offset]) {
                    if (self::entropy($secret) >= $min && !self::excusedValue($secret)) {
                        $found[] = $file . ':' . (substr_count(substr($text, 0, $offset), "\n") + 1);
                    }
                }
            }
        }
        self::assertSame([], $found, 'lines the DSN rule of .gitleaks.toml reports');
    }

    public function testGitguardianAndGitleaksExceptTheSameFilesAndValues(): void
    {
        $yaml = (string) file_get_contents(self::root() . '/.gitguardian.yaml');
        self::assertSame(1, preg_match('/^  ignored_paths:\n((?:    - .*\n)+)/m', $yaml, $p));
        preg_match_all("/^    - '([^']+)'$/m", $p[1], $paths);
        self::assertSame(1, preg_match('/^  ignored_matches:\n((?:    .*\n?)+)/m', $yaml, $v));
        preg_match_all('/^      match: (\S+)$/m', $v[1], $values);

        $gitleaksFiles = [];
        $gitleaksValues = [];
        foreach (self::allowlists() as $description => $list) {
            if ($description === 'git-ignored local run directories and tool caches') {
                continue;
            }
            foreach ($list['paths'] as $path) {
                $gitleaksFiles[] = stripslashes(substr($path, strlen('^(?:\./)?'), -1));
            }
            foreach ($list['regexes'] as $re) {
                if (preg_match('/^\^\(\?:([a-z|-]+)\)\$$/D', $re, $m) === 1) {
                    $gitleaksValues = [...$gitleaksValues, ...explode('|', $m[1])];
                } elseif (preg_match('/^\^([A-Za-z0-9+\/]+)\$$/D', $re, $m) === 1) {
                    $gitleaksValues[] = $m[1];
                } else {
                    self::assertSame('^CHANGE_ME_[A-Z_]+$', $re, 'only the placeholder pattern is not an exact value');
                }
            }
        }
        sort($gitleaksFiles);
        sort($paths[1]);
        self::assertSame($gitleaksFiles, $paths[1], 'the same named files');
        self::assertSame(['tests/fixtures/vmail/seed.sql'], $gitleaksFiles);
        sort($gitleaksValues);
        sort($values[1]);
        self::assertSame($gitleaksValues, $values[1], 'the same exact values');
    }

    /**
     * The controls of job "security" prove the DSN rule and that a new fixture file is scanned, before
     * the scans whose silence they make meaningful.
     */
    public function testTheSecurityJobProvesTheExceptionsAreNarrow(): void
    {
        $dsn = Workflow::stepIndex('security', 'galbridge-dsn-password');
        $paths = Workflow::stepIndex('security', 'new-fixture.sql');
        $history = Workflow::stepIndex('security', 'gitleaks" git ');
        self::assertNotNull($dsn);
        self::assertNotNull($paths);
        self::assertNotNull($history);
        self::assertLessThan($history, $dsn);
        self::assertLessThan($history, $paths);
        $steps = Workflow::steps('security');
        self::assertStringContainsString("jq -e 'any(.[]; .RuleID == \"galbridge-dsn-password\")'", $steps[$dsn]);
        self::assertStringContainsString("test \"\$files\" = 'tests/fixtures/vmail/new-fixture.sql'", $steps[$paths]);
        self::assertStringContainsString('cp "$c/tests/fixtures/vmail/new-fixture.sql" "$c/tests/fixtures/vmail/seed.sql"', $steps[$paths]);
    }
}
