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

namespace GalBridge\Directory;

/**
 * How many directory contacts one request may still read one by one (get_record(),
 * get_record_groups()). Roundcube's copy action calls get_record() for every ID of the request, and
 * each call costs statements; galbridge_max_ids bounds an ID search, and this budget - one per
 * request, shared by every book of it (Factory) - bounds the same number of single lookups.
 */
final class LookupBudget
{
    private int $remaining;

    private bool $noticed = false;

    public function __construct(int $size)
    {
        $this->remaining = max(0, $size);
    }

    /**
     * Take one lookup: false when the budget is spent (the lookup must not run).
     */
    public function take(): bool
    {
        if ($this->remaining <= 0) {
            return false;
        }
        $this->remaining--;
        return true;
    }

    /**
     * True for the first refusal of the request only (its notice is shown once).
     */
    public function claimNotice(): bool
    {
        if ($this->noticed) {
            return false;
        }
        $this->noticed = true;
        return true;
    }
}
