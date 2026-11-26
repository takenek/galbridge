<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Workflow;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-22 / F-23 (and I-07 of the audit of 1.0.0): which key may sign a
 * release, how a tag and its archive are verified - before the push by the maintainer and on the tag
 * by CI, with the same scripts - and that the published instructions name a working reporting
 * channel and the version they belong to.
 *
 * The signing tests use throw-away keys made here; the maintainer's key is never used (git runs
 * without any user or system configuration).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ReleaseTest extends TestCase
{
    private const SIGNERS = '.github/release-signers';
    private const LINE = '~^galbridge-release namespaces="git"(?:,valid-before="\d{8}")? (ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com) ([A-Za-z0-9+/]+={0,2})$~D';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            CliRun::rmTree($this->dir);
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $file): string
    {
        return (string) file_get_contents(self::root() . '/' . $file);
    }

    /**
     * @return list<array{type: string, blob: string}>
     */
    private static function releaseKeys(): array
    {
        $keys = [];
        foreach (explode("\n", self::read(self::SIGNERS)) as $line) {
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }
            self::assertSame(1, preg_match(self::LINE, $line, $m), 'not an allowed-signers line with the principal galbridge-release: ' . substr($line, 0, 60));
            $blob = base64_decode($m[2], true);
            self::assertIsString($blob);
            $keys[] = ['type' => $m[1], 'blob' => $blob];
        }
        return $keys;
    }

    /** the fingerprint as ssh-keygen -l prints it */
    private static function fingerprint(string $blob): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * @return list<string> the length-prefixed fields of an SSH wire-format blob
     */
    private static function fields(string $blob): array
    {
        $out = [];
        for ($at = 0; $at + 4 <= strlen($blob);) {
            $n = unpack('N', substr($blob, $at, 4))[1] ?? 0;
            $out[] = substr($blob, $at + 4, (int) $n);
            $at += 4 + (int) $n;
        }
        return $out;
    }

    public function testReleaseSignersFileHoldsOnlyWellFormedKeys(): void
    {
        self::assertFileExists(self::root() . '/' . self::SIGNERS);
        $keys = self::releaseKeys();
        self::assertNotEmpty($keys, 'at least one release key');
        foreach ($keys as $key) {
            $fields = self::fields($key['blob']);
            self::assertSame($key['type'], $fields[0] ?? null, 'the key blob carries the declared type');
            if ($key['type'] === 'ssh-rsa') {
                $modulus = ltrim($fields[2] ?? '', "\0");
                self::assertGreaterThanOrEqual(3072, strlen($modulus) * 8, 'an RSA release key has at least 3072 bits');
            }
        }
        self::assertStringNotContainsString('@', implode("\n", array_filter(explode("\n", self::read(self::SIGNERS)), static fn (string $l): bool => !str_starts_with($l, '#'))), 'no e-mail principal, no key comment');
    }

    /**
     * Administrators compare the fingerprint from the documentation (and the maintainer's GitHub
     * signing keys), never from the clone alone; the secret scanners know it is public.
     */
    public function testTheFingerprintOfEveryReleaseKeyIsPublished(): void
    {
        $keygen = self::keygen();
        foreach (self::releaseKeys() as $key) {
            $fp = self::fingerprint($key['blob']);
            if ($keygen !== null) {
                [$code, $out] = CliRun::exec([$keygen, '-l', '-f', '/dev/stdin'], $key['type'] . ' ' . base64_encode($key['blob']) . "\n");
                self::assertSame(0, $code);
                self::assertStringContainsString(' ' . $fp . ' ', $out, 'the fingerprint computed here is the one ssh-keygen prints');
            }
            foreach (['SECURITY.md', 'README.md', 'docs/INSTALLATION.md', 'docs/RELEASING.md', self::SIGNERS] as $doc) {
                self::assertStringContainsString($fp, self::read($doc), "{$doc} publishes {$fp}");
            }
            self::assertStringContainsString("'''^" . substr($fp, 7) . "$'''", self::read('.gitleaks.toml'));
            self::assertStringContainsString('match: ' . substr($fp, 7), self::read('.gitguardian.yaml'));
        }
        self::assertStringContainsString('https://api.github.com/users/takenek/ssh_signing_keys', self::read('SECURITY.md'));
        self::assertStringNotContainsString('github.com/<user>.keys', self::read('docs/RELEASING.md'), '.keys lists authentication keys, not signing keys');
    }

    public function testVerifyTagAcceptsOnlyATagSignedByAReleaseKey(): void
    {
        $git = self::git();
        $keygen = self::keygen();
        if ($git === null || $keygen === null) {
            self::markTestSkipped('needs git 2.34 or later and ssh-keygen (SSH signatures)');
        }
        $this->dir = CliRun::tempBase('galbridge-r2-tag');
        $d = $this->dir;
        $env = self::gitEnv($d);
        foreach (['k1', 'k2'] as $k) {
            self::assertSame(0, CliRun::exec([$keygen, '-q', '-t', 'ed25519', '-N', '', '-C', '', '-f', "{$d}/{$k}"], null, $env)[0]);
        }
        $repo = "{$d}/repo";
        mkdir("{$repo}/tests/release", 0o700, true);
        mkdir("{$repo}/.github", 0o700);
        copy(self::root() . '/tests/release/verify-tag.sh', "{$repo}/tests/release/verify-tag.sh");
        chmod("{$repo}/tests/release/verify-tag.sh", 0o755);
        $pub = static fn (string $k): string => implode(' ', array_slice(explode(' ', trim((string) file_get_contents("{$d}/{$k}.pub"))), 0, 2));
        file_put_contents("{$repo}/.github/release-signers", '# throw-away key of the test' . "\n" . 'galbridge-release namespaces="git" ' . $pub('k1') . "\n");
        $run = static function (array $args) use ($repo, $env): array {
            return CliRun::exec($args, null, $env, $repo);
        };
        $g = static fn (string ...$a): array => $run([$git, ...$a]);
        self::assertSame(0, $g('init', '-q')[0]);
        self::assertSame(0, $g('add', '-A')[0]);
        self::assertSame(0, $g('commit', '-q', '-m', 'one')[0]);
        $first = trim($g('rev-parse', 'HEAD')[1]);
        $sign = static fn (string $key, string $tag, string $msg = 'GAL Bridge'): array => $g('-c', 'gpg.format=ssh', '-c', "user.signingkey={$d}/{$key}", 'tag', '-s', $tag, '-m', $msg);
        self::assertSame(0, $sign('k1', 'v1.0.0')[0]);
        $verify = static fn (string ...$a): array => $run(['bash', 'tests/release/verify-tag.sh', ...$a]);

        [$code, $out, $err] = $verify('v1.0.0');
        self::assertSame(0, $code, $err);
        self::assertMatchesRegularExpression('~^Good "git" signature for galbridge-release with ED25519 key SHA256:[A-Za-z0-9+/]{43}\nv1\.0\.0 -> commit ' . $first . '\n$~D', $out);
        self::assertSame(0, $verify('v1.0.0', '--commit', $first)[0]);

        $refused = static function (array $args, string $why) use ($verify): void {
            [$code, $out, $err] = $verify(...$args);
            self::assertSame(1, $code, implode(' ', $args) . ': ' . $out);
            self::assertStringContainsString($why, $err);
            self::assertStringNotContainsString('Good', $out);
        };
        // another key, also one that is "good" for ssh-keygen
        self::assertSame(0, $sign('k2', 'v1.0.1')[0]);
        $refused(['v1.0.1'], 'was not made by a key of');
        // a lightweight and an unsigned annotated tag
        self::assertSame(0, $g('tag', 'v1.0.2')[0]);
        $refused(['v1.0.2'], 'is a lightweight tag');
        self::assertSame(0, $g('tag', '-a', 'v1.0.3', '-m', 'unsigned')[0]);
        $refused(['v1.0.3'], 'carries no SSH signature');
        // the signed tag object of v1.0.0 pushed under another version
        self::assertSame(0, $g('update-ref', 'refs/tags/v1.0.4', trim($g('rev-parse', 'refs/tags/v1.0.0')[1]))[0]);
        $refused(['v1.0.4'], 'was made for another name (tag v1.0.0)');
        // a name that is not a release, a missing tag
        $refused(['release-1'], 'is not a release tag name');
        $refused(['v9.9.9'], 'there is no tag v9.9.9');

        // the commit CI tested, and main
        file_put_contents("{$repo}/b", "b\n");
        $g('add', 'b');
        self::assertSame(0, $g('commit', '-q', '-m', 'two')[0]);
        $second = trim($g('rev-parse', 'HEAD')[1]);
        $refused(['v1.0.0', '--commit', $second], 'points to ' . $first . ', not to ' . $second);
        $refused(['v1.0.0', '--require-main'], 'refs/remotes/origin/main is missing');
        self::assertSame(0, $g('update-ref', 'refs/remotes/origin/main', $second)[0]);
        [$code, , $err] = $verify('v1.0.0', '--commit', $first, '--require-main');
        self::assertSame(0, $code, 'main on a descendant of the tagged commit: ' . $err);
        self::assertSame(0, $g('checkout', '-q', '-b', 'side', $first)[0]);
        file_put_contents("{$repo}/c", "c\n");
        $g('add', 'c');
        $g('commit', '-q', '-m', 'side');
        self::assertSame(0, $sign('k1', 'v1.1.0')[0]);
        $refused(['v1.1.0', '--require-main'], 'is not part of main');

        // the key list CI passes (main's copy), a rotated key and a foreign principal
        $other = "{$d}/signers-other";
        file_put_contents($other, 'galbridge-release namespaces="git" ' . $pub('k2') . "\n");
        $refused(['v1.0.0', '--signers', $other], 'was not made by a key of');
        self::assertSame(0, $verify('v1.0.1', '--signers', $other)[0]);
        file_put_contents($other, 'galbridge-release namespaces="git",valid-before="20000101" ' . $pub('k1') . "\n");
        $refused(['v1.0.0', '--signers', $other], 'expired');
        file_put_contents($other, 'galbridge-release namespaces="git",valid-before="21000101" ' . $pub('k1') . "\n");
        self::assertSame(0, $verify('v1.0.0', '--signers', $other)[0], 'a tag signed before valid-before keeps verifying');
        file_put_contents($other, 'someone-else namespaces="git" ' . $pub('k1') . "\n");
        $refused(['v1.0.0', '--signers', $other], 'lists no key for the principal galbridge-release');
        file_put_contents($other, 'galbridge-release namespaces="file" ' . $pub('k1') . "\n");
        $refused(['v1.0.0', '--signers', $other], 'was not made by a key of');
    }

    /**
     * The archive is checked against an allow-list of full paths (one list, the one CI uses on the
     * tag): the release built from this working tree passes, anything else is named.
     */
    public function testReleaseArchiveHoldsOnlyAllowListedPaths(): void
    {
        $git = self::git(false);
        if ($git === null) {
            self::markTestSkipped('needs git');
        }
        $this->dir = CliRun::tempBase('galbridge-r2-archive');
        $repo = $this->dir . '/repo';
        mkdir($repo, 0o700);
        $env = self::gitEnv($this->dir);
        [$code, $list] = CliRun::exec([$git, '-C', self::root(), 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
        self::assertSame(0, $code);
        foreach (array_filter(explode("\0", $list)) as $file) {
            if (!is_file(self::root() . '/' . $file)) {
                continue;
            }
            @mkdir(dirname("{$repo}/{$file}"), 0o700, true);
            copy(self::root() . '/' . $file, "{$repo}/{$file}");
        }
        $g = static fn (string ...$a): array => CliRun::exec([$git, ...$a], null, $env, $repo);
        $check = static fn (): array => CliRun::exec([PHP_BINARY, self::root() . '/tests/release/check-archive.php', 'HEAD'], null, $env, $repo);
        $g('init', '-q');
        $g('add', '-A');
        self::assertSame(0, $g('commit', '-q', '-m', 'tree')[0]);
        [$code, $out, $err] = $check();
        self::assertSame(0, $code, $err);
        self::assertMatchesRegularExpression('/^check-archive: HEAD: \d+ entries, all on the allow-list, nothing missing\n$/D', $out);

        mkdir("{$repo}/lib/GalBridge/Extra", 0o700);
        foreach (['notes.txt', 'lib/x.txt', 'docs/notes.txt', 'lib/GalBridge/lower.php', 'lib/GalBridge/Extra/notes.inc', 'SQL/x.php'] as $f) {
            file_put_contents("{$repo}/{$f}", "x\n");
        }
        symlink('/etc/hostname', "{$repo}/lib/GalBridge/Link.php");
        unlink("{$repo}/COPYRIGHT");
        $g('add', '-A');
        self::assertSame(0, $g('commit', '-q', '-m', 'more')[0]);
        [$code, $out, $err] = $check();
        self::assertSame(1, $code, $out);
        foreach (['not part of a release: notes.txt', 'not part of a release: lib/x.txt', 'not part of a release: docs/notes.txt',
            'not part of a release: lib/GalBridge/lower.php', 'not part of a release: lib/GalBridge/Extra/notes.inc',
            'not part of a release: SQL/x.php', 'not a regular file or directory (type 2): lib/GalBridge/Link.php',
            'missing in the archive: COPYRIGHT'] as $line) {
            self::assertStringContainsString($line, $err);
        }
        self::assertSame(2, CliRun::exec([PHP_BINARY, self::root() . '/tests/release/check-archive.php'], null, $env, $repo)[0], 'usage');
    }

    /**
     * The release job: the tag is verified with the pinned keys of main, by the same scripts the
     * maintainer runs, without a fetch of its own (no credentials on a private repository).
     */
    public function testReleaseJobUsesThePinnedKeysAndNeedsNoCredentials(): void
    {
        $code = Workflow::code(Workflow::jobs()['release'] ?? '');
        foreach (['fetch-depth: 0', 'persist-credentials: false', 'git show refs/remotes/origin/main:.github/release-signers',
            'tests/release/verify-tag.sh "$TAG" --commit "$(git rev-parse HEAD)" --require-main --signers', 'php tests/release/check-archive.php "refs/tags/$TAG"',
            "test \"\$(jq -r '.verification.verified' \"\$RUNNER_TEMP/tag.json\")\" = true", 'git hash-object -t tag'] as $part) {
            self::assertStringContainsString($part, $code);
        }
        $all = Workflow::code(Workflow::text());
        self::assertStringNotContainsString('git fetch', $all, 'no fetch with credentials of its own');
        self::assertStringNotContainsString('git push', $all);
        self::assertStringNotContainsString('contents: write', $all);
        self::assertTrue(is_executable(self::root() . '/tests/release/verify-tag.sh'));
        self::assertStringStartsWith("<?php\n", self::read('tests/release/check-archive.php'), 'run with php (no shebang)');
    }

    public function testSecurityPolicyNamesAWorkingPrivateChannel(): void
    {
        $policy = self::read('SECURITY.md');
        self::assertStringContainsString('https://github.com/takenek/galbridge/security/advisories/new', $policy);
        self::assertStringContainsString('https://github.com/takenek/galbridge/security/policy', $policy);
        self::assertStringContainsString('there is no e-mail address for security reports', $policy);
        self::assertMatchesRegularExpression('/open a public issue\s+that only says a private report is waiting/', $policy);
        foreach (['repository profile', 'S/MIME'] as $gone) {
            self::assertStringNotContainsString($gone, $policy);
        }
        self::assertStringContainsString('## Release signing key', $policy);
    }

    /**
     * README 2.1 and its full text, docs/INSTALLATION.md 2.1, install the version of the code they
     * ship with - one v= line each (RELEASING step 4 bumps both); 1.0.0 and 1.0.1 never got a tag.
     */
    public function testInstallationInstructionsNameTheCurrentVersion(): void
    {
        self::assertSame(1, preg_match("/'version' => '(\\d+\\.\\d+\\.\\d+)'/", self::read('galbridge.php'), $m));
        $version = $m[1];
        foreach (['README.md', 'docs/INSTALLATION.md'] as $file) {
            self::assertSame(1, preg_match('/^### 2\.1 .*?(?=^### 2\.2 )/ms', self::read($file), $s), $file);
            $install = $s[0];
            self::assertSame(1, preg_match_all('/^v=(\d+\.\d+\.\d+)\s/m', $install, $v), "exactly one v= line in {$file} 2.1");
            self::assertSame($version, $v[1][0], "{$file} 2.1 installs the version of galbridge.php");
            self::assertSame(0, preg_match('/\bv\d+\.\d+\.\d+\b/', $install), "no other version written out in {$file} 2.1");
            self::assertSame(1, preg_match_all('/^v=/m', self::read($file)), "{$file}: the v= line of 2.1 is the only one");
            foreach (['clone --branch "v$v"', '-c gpg.ssh.allowedSignersFile=/root/galbridge-src/.github/release-signers verify-tag "v$v"', 'archive --prefix=galbridge/ "v$v"'] as $cmd) {
                self::assertStringContainsString($cmd, $install, $file);
            }
            self::assertStringContainsString('Composer does not verify tag signatures', $install, $file);
            self::assertStringNotContainsString('--no-same-permissions', $install, "{$file}: no instruction for GitHub's unsigned source archive");
        }
        self::assertStringContainsString('carry no signature: they are no', $install);
        $changelog = self::read('CHANGELOG.md');
        self::assertStringContainsString("## [{$version}]", $changelog);
        foreach (['1.0.0', '1.0.1'] as $unpublished) {
            self::assertSame(1, preg_match('/^## \[' . preg_quote($unpublished, '/') . '\][^\n]*$/m', $changelog, $h));
            self::assertStringContainsString('Not published', $h[0], "{$unpublished} was never tagged");
        }
    }

    /**
     * The Packagist and License badges of the README are files of this repository: GitHub's image
     * proxy (camo) gave up on img.shields.io after about four seconds with "504 Error Fetching
     * Resource", so both badges showed as broken images on github.com and packagist.org (which
     * renders the README through GitHub). raw.githubusercontent.com is not proxied. packagist.org
     * shows those images through rawcdn.githack.com, which caches a URL for a year (immutable): the
     * version badge therefore has the version in its file name: every release adds a new file and
     * keeps the old ones, which the READMEs of earlier releases link (docs/RELEASING.md step 4). The
     * licence never changes.
     */
    public function testTheReadmeBadgesAreRepositoryFilesThatNameTheCurrentRelease(): void
    {
        self::assertSame(1, preg_match("/'version' => '(\\d+\\.\\d+\\.\\d+)'/", self::read('galbridge.php'), $m));
        $version = $m[1];
        $license = json_decode(self::read('composer.json'), true, 512, JSON_THROW_ON_ERROR)['license'];
        self::assertIsString($license);
        $readme = self::read('README.md');
        $raw = 'https://raw.githubusercontent.com/takenek/galbridge/main/.github/badges/';
        self::assertStringContainsString("[![Packagist]({$raw}packagist-v{$version}.svg)](https://packagist.org/packages/takenek/galbridge)", $readme);
        $files = array_values(array_diff((array) scandir(self::root() . '/.github/badges'), ['.', '..']));
        self::assertContains('license.svg', $files);
        self::assertContains("packagist-v{$version}.svg", $files, 'the version badge of this release');
        // the badges of earlier releases stay (their READMEs link them through main), each naming its own version
        foreach ($files as $file) {
            if ($file === 'license.svg') {
                continue;
            }
            self::assertSame(1, preg_match('/^packagist-v(\d+\.\d+\.\d+)\.svg$/D', (string) $file, $f), (string) $file . ': no other file in .github/badges');
            self::assertStringContainsString("aria-label=\"packagist: v{$f[1]}\"", self::read('.github/badges/' . $file), (string) $file);
        }
        self::assertStringContainsString("[![License]({$raw}license.svg)](LICENSE)", $readme);
        self::assertStringNotContainsString('img.shields.io', $readme, 'GitHub\'s image proxy does not reliably fetch shields.io');
        $badges = [
            "packagist-v{$version}.svg" => ['packagist', 'v' . $version, '#007ec6'],
            'license.svg' => ['license', $license, '#ea7233'],
        ];
        foreach ($badges as $file => [$label, $value, $colour]) {
            $svg = self::read('.github/badges/' . $file);
            self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" ', $svg, $file);
            self::assertLessThan(4096, strlen($svg), $file);
            self::assertStringContainsString("aria-label=\"{$label}: {$value}\"", $svg, $file);
            self::assertStringContainsString("<title>{$label}: {$value}</title>", $svg, $file);
            self::assertSame(3, substr_count($svg, ">{$label}</text>"), $file);
            self::assertSame(3, substr_count($svg, ">{$value}</text>"), $file . ' names ' . $value);
            self::assertStringContainsString("fill=\"{$colour}\"", $svg, $file);
            self::assertSame(0, preg_match('/<script|<foreignObject|href|\son[a-z]+=|url\((?!#)/i', $svg), $file . ': a plain image');
        }
    }

    /**
     * docs/RELEASING.md describes the procedure the scripts and CI implement.
     */
    public function testTheReleaseProcedureUsesTheScriptsAndADisposableVerification(): void
    {
        $doc = self::read('docs/RELEASING.md');
        foreach (['tests/release/verify-tag.sh vX.Y.Z --commit "$(git rev-parse HEAD)"', 'php tests/release/check-archive.php vX.Y.Z',
            'gh run download', 'composer-lock-tests-php8.4-1.7.0', '--fail-on-skipped --exclude-group root', '## 0. First publication',
            'https://github.com/takenek/galbridge/security/advisories/new', 'gpg.format ssh'] as $part) {
            self::assertStringContainsString($part, $doc);
        }
        self::assertDoesNotMatchRegularExpression('/PHPUnit suite\s+\*\*as root\*\*/', $doc, 'no PHPUnit run as root on the signing machine');
        self::assertStringNotContainsString('# GPG key', $doc);
    }

    private static function keygen(): ?string
    {
        foreach (['/usr/bin/ssh-keygen', '/bin/ssh-keygen', '/usr/local/bin/ssh-keygen'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    private static function git(bool $ssh = true): ?string
    {
        foreach (['/usr/bin/git', '/bin/git', '/usr/local/bin/git'] as $p) {
            if (is_executable($p)) {
                [$code, $out] = CliRun::exec([$p, '--version']);
                if ($code === 0 && preg_match('/(\d+)\.(\d+)/', $out, $m) === 1 && (!$ssh || (int) $m[1] > 2 || ((int) $m[1] === 2 && (int) $m[2] >= 34))) {
                    return $p;
                }
            }
        }
        return null;
    }

    /**
     * git without any user or system configuration (no signing key, no gpgsign of the machine).
     *
     * @return array<string, string>
     */
    private static function gitEnv(string $home): array
    {
        return [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $home,
            'TMPDIR' => $home,
            'LC_ALL' => 'C',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_AUTHOR_NAME' => 'test',
            'GIT_AUTHOR_EMAIL' => 'test@example.invalid',
            'GIT_COMMITTER_NAME' => 'test',
            'GIT_COMMITTER_EMAIL' => 'test@example.invalid',
        ];
    }
}
