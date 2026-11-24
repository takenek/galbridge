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
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Exception;

/**
 * Base exception of the plugin.
 *
 * Every exception carries two independent messages:
 *  - a reason code (also a localization label without the "galbridge." prefix) that is safe to show
 *    to the end user and to write to the normal log,
 *  - an internal message for the administrator debug log (never shown to the user).
 */
class GalBridgeException extends \RuntimeException
{
    /**
     * @param string $reason   Reason code / localization label (plugin domain)
     * @param string $internal Detailed message for the debug log (must not contain secrets)
     */
    public function __construct(private readonly string $reason, string $internal = '', ?\Throwable $previous = null)
    {
        parent::__construct($internal !== '' ? $internal : $reason, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
