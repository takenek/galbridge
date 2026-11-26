<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
 * Original author: Marek Królikowski (TaKeN)
 * Original project: https://github.com/takenek/galbridge
 * SPDX-License-Identifier: GPL-3.0-or-later
 * See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
 *
 * GAL Bridge - Global Address List for Roundcube
 *
 * PSR-4 autoloader for the GalBridge\ namespace (lib/GalBridge). galbridge.php and bin/galbridge.sh
 * load it whichever way the plugin was installed. It is registered FIRST (prepend): Roundcube's
 * Composer autoloader may map GalBridge\ to a second copy of the package below vendor/ (a Composer
 * run as root without a terminal skips Roundcube's plugin installer and installs it there - diag
 * warns, "composer copy"), and the classes that run must be the ones of this directory.
 *
 * @license GPL-3.0-or-later
 */

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'GalBridge\\', 10) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 10));
    if (!preg_match('~^[A-Za-z0-9_/]+$~', $rel)) {
        return;
    }
    $file = __DIR__ . '/GalBridge/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);
