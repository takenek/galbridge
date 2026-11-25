<?php

declare(strict_types=1);

/**
 * GAL Bridge - check what a release archive holds (the maintainer before tagging and before pushing
 * a tag, the CI job "release" on the tag, and Remediation2ReleaseTest on every push).
 *
 *   php tests/release/check-archive.php <ref>        (HEAD, vX.Y.Z, ...; run inside the repository)
 *
 * The archive is the one GitHub and Packagist serve: "git archive <ref>", which honours export-ignore
 * in .gitattributes. Every entry must be a regular file or a directory whose FULL path is on the
 * allow-list below (one list for the whole release, the same rules as Tool::RELEASE_TREE), and every
 * file of REQUIRED must be there. Exit 0 = the plugin and nothing else; 1 = the offending or missing
 * paths are printed; 2 = usage or git error. It needs PHP and git only (the tar stream is read here).
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Release;

/** every path of a release: top-level files, then the release directories (with a trailing slash) */
const ALLOWED = '~^(?:'
    . '\.htaccess|CHANGELOG\.md|COPYRIGHT|LICENSE|README\.md|SECURITY\.md|composer\.json|config\.inc\.php\.dist|galbridge\.php'
    . '|bin/|bin/galbridge\.sh'
    . '|lib/|lib/autoload\.php|lib/GalBridge/(?:[A-Z][A-Za-z]*/)*|lib/GalBridge/(?:[A-Z][A-Za-z]*/)*[A-Z][A-Za-z]*\.php'
    . '|SQL/|SQL/grants\.(?:mysql|postgres)\.sql'
    . '|localization/|localization/[a-z]{2}_[A-Z]{2}\.inc'
    . '|docs/|docs/[A-Z][A-Z_]*\.md'
    . ')$~D';

const REQUIRED = [
    '.htaccess', 'CHANGELOG.md', 'COPYRIGHT', 'LICENSE', 'README.md', 'SECURITY.md', 'composer.json', 'config.inc.php.dist',
    'galbridge.php', 'bin/galbridge.sh', 'lib/autoload.php', 'SQL/grants.mysql.sql', 'SQL/grants.postgres.sql',
    'localization/en_US.inc', 'docs/ARCHITECTURE.md', 'docs/MIGRATION.md', 'docs/RELEASING.md', 'docs/TESTING.md',
    'docs/THREAT_MODEL.md',
];

/**
 * The entries of a tar stream: path => type ('file', 'dir' or the tar type flag of anything else).
 *
 * @return array<string, string>
 */
function entries(string $tar): array
{
    $out = [];
    $longName = null;
    $at = 0;
    $length = strlen($tar);
    while ($at + 512 <= $length) {
        $block = substr($tar, $at, 512);
        if (trim($block, "\0") === '') {
            break;
        }
        $size = (int) octdec(trim(substr($block, 124, 12), " \0"));
        $flag = $block[156];
        $data = substr($tar, $at + 512, $size);
        $at += 512 + (int) (ceil($size / 512) * 512);
        if ($flag === 'g') {
            continue;                       // pax global header (git: the commit id)
        }
        if ($flag === 'x') {                // pax extended header: a path longer than 100 bytes
            foreach (explode("\n", $data) as $record) {
                if (preg_match('/^\d+ path=(.*)$/sD', $record, $m) === 1) {
                    $longName = $m[1];
                }
            }
            continue;
        }
        $name = rtrim(substr($block, 0, 100), "\0");
        $prefix = rtrim(substr($block, 345, 155), "\0");
        if (substr($block, 257, 5) === 'ustar' && $prefix !== '') {
            $name = $prefix . '/' . $name;
        }
        if ($longName !== null) {
            $name = $longName;
            $longName = null;
        }
        $out[$name] = match ($flag) {
            '0', "\0" => 'file',
            '5' => 'dir',
            default => 'type ' . $flag,
        };
    }
    return $out;
}

/**
 * @param array<string, string> $entries
 *
 * @return list<string> the problems, one line each
 */
function problems(array $entries): array
{
    $problems = [];
    foreach ($entries as $path => $type) {
        if ($type !== 'file' && $type !== 'dir') {
            $problems[] = "not a regular file or directory ({$type}): {$path}";
        } elseif (preg_match(ALLOWED, $path) !== 1 || ($type === 'dir') !== str_ends_with($path, '/')) {
            $problems[] = "not part of a release: {$path}";
        }
    }
    foreach (REQUIRED as $file) {
        if (($entries[$file] ?? null) !== 'file') {
            $problems[] = "missing in the archive: {$file}";
        }
    }
    return $problems;
}

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $ref = $argv[1] ?? '';
    if (count($argv) !== 2 || $ref === '' || $ref[0] === '-') {
        fwrite(STDERR, "usage: php tests/release/check-archive.php <ref>\n");
        return 2;
    }
    $proc = proc_open(['git', 'archive', '--format=tar', $ref], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "check-archive: cannot run git\n");
        return 2;
    }
    $tar = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $tar === '') {
        fwrite(STDERR, 'check-archive: git archive ' . $ref . ' failed: ' . trim($err) . "\n");
        return 2;
    }
    $entries = entries($tar);
    $problems = problems($entries);
    if ($problems !== []) {
        fwrite(STDERR, implode("\n", $problems) . "\ncheck-archive: the archive of {$ref} holds more or less than the plugin (.gitattributes export-ignore, docs/RELEASING.md step 5)\n");
        return 1;
    }
    echo "check-archive: {$ref}: " . count($entries) . " entries, all on the allow-list, nothing missing\n";
    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main(array_values(array_map('strval', $_SERVER['argv'] ?? []))));
}
