#!/usr/bin/env php
<?php

/**
 * Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
 * Original author: Marek Królikowski (TaKeN)
 * Original project: https://github.com/takenek/galbridge
 * SPDX-License-Identifier: GPL-3.0-or-later
 * See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
 *
 * GAL Bridge - administration tool (diagnostics of the configuration and the vmail database,
 * least-privilege grant statements).
 *
 * Run from the Roundcube installation directory as the PHP / web server user (it must be able to
 * read the plugin configuration), e.g.:
 *   sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
 *   sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
 *   umask 077; sudo -u www-data plugins/galbridge/bin/galbridge.sh grants --driver=mysql > /root/galbridge-grants.sql
 * (in the last line the shell of the administrator writes the file - readable by root only, it holds
 * the DSN with the password - while the tool itself runs unprivileged).
 *
 * NOT as root: the tool loads PHP code from the plugin directory and from the Roundcube directory
 * (and Roundcube's configuration files are PHP as well). Whoever can write there - on many
 * installations the web server user - would run code as root. Root is refused unless --allow-root
 * is given, and then only when all of that code belongs to root and nobody else can write it:
 * the plugin, and below the Roundcube directory everything in program/, plugins/ (Roundcube loads
 * every enabled plugin at start), vendor/ and the configuration directory, together with the
 * directories they are reached through. A process whose user cannot be determined (no posix
 * extension and no /proc) is treated like root.
 *
 * @license GPL-3.0-or-later
 */

/**
 * The effective user id of this process, or null when it cannot be determined. The posix extension
 * is a package of its own on several platforms (php-process, php-posix) and no requirement of the
 * plugin; on Linux the kernel tells as well.
 */
function galbridge_cli_euid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    $status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
    // "Uid:" real, effective, saved, filesystem
    if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)\s/m', $status, $m) === 1) {
        return (int) $m[1];
    }
    return null;
}

/**
 * The first of the given paths that an account other than root could have written (owner or
 * group/other write bit), or null. Used for --allow-root only.
 *
 * @param list<string> $paths files and directories; missing ones are skipped
 */
function galbridge_cli_unsafe_path(array $paths): ?string
{
    foreach ($paths as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $real = realpath($path);
        $stat = $real === false ? false : @stat($real);
        if ($real === false || $stat === false) {
            return $path . ' (cannot be checked)';
        }
        if ($stat['uid'] !== 0 || ($stat['mode'] & 0o022) !== 0) {
            return $real . ' (owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) . ')';
        }
    }
    return null;
}

/**
 * The first directory ABOVE one of the given paths that an account other than root controls, or
 * null: whoever can write a directory can replace what is reached through it. A directory with the
 * sticky bit (/tmp) lets others add entries but not replace the ones of root, so it passes here -
 * and only here, never as a directory that holds code.
 *
 * @param list<string> $paths
 */
function galbridge_cli_unsafe_ancestor(array $paths): ?string
{
    foreach ($paths as $path) {
        $real = realpath($path);
        if ($real === false) {
            continue;
        }
        for ($dir = dirname($real); ; $dir = dirname($dir)) {
            $stat = @stat($dir);
            if ($stat === false) {
                return $dir . ' (cannot be checked)';
            }
            if ($stat['uid'] !== 0 || (($stat['mode'] & 0o022) !== 0 && ($stat['mode'] & 0o1000) === 0)) {
                return $dir . ' (owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) . ')';
            }
            if ($dir === dirname($dir)) {
                break;
            }
        }
    }
    return null;
}

/**
 * A directory, every directory below it and every file PHP could load from it or this tool prints
 * as SQL (*.php, *.inc, *.sql, *.sh). Symbolic links are followed - a plugin is often installed as
 * one - and every directory is visited once.
 *
 * @param array<string, true> $seen  directories already visited (by their real path)
 * @param list<string>        $links what was reached through a symbolic link: its target lies
 *                                   somewhere else, so the directories above the target count too
 *
 * @return list<string>
 */
function galbridge_cli_code_paths(string $dir, array &$seen = [], array &$links = []): array
{
    $paths = [$dir];
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || isset($seen[$real])) {
        return $paths;
    }
    $seen[$real] = true;
    foreach (scandir($real) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $real . '/' . $entry;
        $code = is_dir($path) || preg_match('/\.(php|inc|sql|sh)$/D', $entry) === 1;
        if ($code && is_link($path)) {
            $links[] = $path;
        }
        if (is_dir($path)) {
            foreach (galbridge_cli_code_paths($path, $seen, $links) as $below) {
                $paths[] = $below;
            }
        } elseif ($code) {
            $paths[] = $path;
        }
    }
    return $paths;
}

$galbridgeUid = galbridge_cli_euid();
// a user that cannot be determined is treated like root: refused, or checked with --allow-root
$galbridgeRoot = $galbridgeUid === null || $galbridgeUid === 0;
$galbridgePlugin = dirname(__DIR__);
if ($galbridgeRoot) {
    if (!in_array('--allow-root', array_slice($argv, 1), true)) {
        fwrite(STDERR, ($galbridgeUid === null
            ? "galbridge.sh cannot tell which user it runs as (PHP has no posix extension and /proc is not available) and treats that like root.\n"
              . "It must not run as root: it loads PHP code from the plugin and the Roundcube directory.\n"
            : "galbridge.sh must not run as root: it loads PHP code from the plugin and the Roundcube directory.\n")
            . "Run it as the web server user, e.g.  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag\n"
            . "(--allow-root overrides this when all of that code is owned by root and writable by nobody else).\n");
        exit(1);
    }
    $seen = [];
    $links = [];
    $unsafe = galbridge_cli_unsafe_path([__FILE__, $galbridgePlugin, $galbridgePlugin . '/galbridge.php', $galbridgePlugin . '/config.inc.php',
        ...galbridge_cli_code_paths($galbridgePlugin . '/bin', $seen, $links), ...galbridge_cli_code_paths($galbridgePlugin . '/lib', $seen, $links),
        ...galbridge_cli_code_paths($galbridgePlugin . '/SQL', $seen, $links), ...galbridge_cli_code_paths($galbridgePlugin . '/localization', $seen, $links)])
        ?? galbridge_cli_unsafe_ancestor([$galbridgePlugin, $galbridgePlugin . '/galbridge.php', $galbridgePlugin . '/config.inc.php',
            $galbridgePlugin . '/bin', $galbridgePlugin . '/lib', $galbridgePlugin . '/SQL', $galbridgePlugin . '/localization', ...$links]);
    if ($unsafe !== null) {
        fwrite(STDERR, "galbridge.sh --allow-root refused: {$unsafe} is not owned by root or is writable by its group or by others.\n");
        exit(1);
    }
}

// Roundcube root: $ROUNDCUBE_INSTALL_PATH, or <root>/plugins/galbridge/bin as invoked (a symlinked
// plugin directory is resolved by __DIR__, so the invocation path is tried first)
$candidates = [];
if (is_string(getenv('ROUNDCUBE_INSTALL_PATH')) && getenv('ROUNDCUBE_INSTALL_PATH') !== '') {
    $candidates[] = rtrim((string) getenv('ROUNDCUBE_INSTALL_PATH'), '/');
}
$invoked = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($invoked !== '') {
    if ($invoked[0] !== '/') {
        $invoked = getcwd() . '/' . $invoked;
    }
    $candidates[] = dirname($invoked, 4);
}
$candidates[] = dirname(__DIR__, 3);
$root = null;
foreach ($candidates as $c) {
    if (is_file($c . '/program/include/clisetup.php')) {
        $root = $c;
        break;
    }
}

require_once __DIR__ . '/../lib/autoload.php';
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    // /s and \z: a value is everything after '=', a line break included - it is then refused by the
    // validation of the option instead of being cut off or dropped
    if (preg_match('/^--([a-z-]+)=(.*)\z/s', $arg, $m)) {
        $args[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)\z/', $arg, $m)) {
        $args[$m[1]] = true;
    } else {
        $args[] = $arg;
    }
}

// 'grants' needs no Roundcube (it only prints SQL); everything else does
if (($args[0] ?? '') === 'grants') {
    exit((new GalBridge\Cli\Tool(null, realpath(__DIR__ . '/..') ?: __DIR__ . '/..'))->run($args));
}

if ($root === null) {
    fwrite(STDERR, "Roundcube not found: install the plugin as <roundcube>/plugins/galbridge or set ROUNDCUBE_INSTALL_PATH\n");
    exit(1);
}
if ($galbridgeRoot) {
    // Roundcube's code and its configuration (PHP files) are loaded next. Its start loads every
    // enabled plugin (plugins/<name>/<name>.php and what that includes), classes of vendor/ on
    // demand, the localization index, and the plugin reads the configuration of the password
    // plugin: everything PHP could load from these directories has to belong to root.
    $configDir = is_string(getenv('ROUNDCUBE_CONFIG_DIR')) && getenv('ROUNDCUBE_CONFIG_DIR') !== '' ? rtrim((string) getenv('ROUNDCUBE_CONFIG_DIR'), '/') : $root . '/config';
    $seen = [];
    $links = [];
    $unsafe = galbridge_cli_unsafe_path([$root, ...galbridge_cli_code_paths($root . '/program', $seen, $links), ...galbridge_cli_code_paths($root . '/plugins', $seen, $links),
        ...galbridge_cli_code_paths($root . '/vendor', $seen, $links), ...galbridge_cli_code_paths($configDir, $seen, $links)])
        ?? galbridge_cli_unsafe_ancestor([$root, $root . '/program', $root . '/plugins', $root . '/vendor', $configDir, ...$links]);
    if ($unsafe !== null) {
        fwrite(STDERR, "galbridge.sh --allow-root refused: {$unsafe} is not owned by root or is writable by its group or by others.\n");
        exit(1);
    }
}
define('INSTALL_PATH', $root . '/');
require_once INSTALL_PATH . 'program/include/clisetup.php';

$tool = new GalBridge\Cli\Tool(rcube::get_instance(), realpath(__DIR__ . '/..') ?: __DIR__ . '/..');
exit($tool->run($args));
