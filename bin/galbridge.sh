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
 *   plugins/galbridge/bin/galbridge.sh grants --driver=mysql > /root/galbridge-grants.sql
 *
 * @license GPL-3.0-or-later
 */

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
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
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
define('INSTALL_PATH', $root . '/');
require_once INSTALL_PATH . 'program/include/clisetup.php';

$tool = new GalBridge\Cli\Tool(rcube::get_instance(), realpath(__DIR__ . '/..') ?: __DIR__ . '/..');
exit($tool->run($args));
