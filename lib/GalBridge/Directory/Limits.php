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

use GalBridge\Config;

/**
 * Effective, clamped limits of one request.
 */
final class Limits
{
    /** absolute upper bound of a normal page (DoS bound, independent of core settings) */
    public const HARD_PAGE_CAP = 1000;

    public function __construct(
        /**
         * largest page size Roundcube's own lists use: max(core max_pagesize, addressbook_pagesize),
         * bounded by HARD_PAGE_CAP (core clamps addressbook_pagesize only when a user saves it)
         */
        public readonly int $corePageCap,
        public readonly int $maxIds,
        /** members of one group expansion: min(galbridge_max_group_members, core max_group_members) */
        public readonly int $groupCap,
        public readonly int $maxGroups,
        public readonly int $maxExport,
        public readonly int $maxCount,
        public readonly int $searchonlyThreshold,
        /** galbridge_scope_ttl: lifetime of per-session decisions (Viewer, search-only 'auto') */
        public readonly int $scopeTtl = 300,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $corePageCap = $config->get('max_pagesize');
        $corePageCap = is_numeric($corePageCap) && (int) $corePageCap > 0 ? (int) $corePageCap : 200;
        $listSize = $config->get('addressbook_pagesize');
        if (is_numeric($listSize) && (int) $listSize > $corePageCap) {
            $corePageCap = (int) $listSize;
        }
        $groupCap = $config->int('galbridge_max_group_members', 1, 100000);
        $coreGroup = $config->get('max_group_members');
        if (is_numeric($coreGroup) && (int) $coreGroup > 0) {
            $groupCap = min($groupCap, (int) $coreGroup);
        }
        return new self(
            corePageCap: max(10, min($corePageCap, self::HARD_PAGE_CAP)),
            maxIds: $config->int('galbridge_max_ids', 1, 1000),
            groupCap: $groupCap,
            maxGroups: $config->int('galbridge_max_groups', 1, 500),
            maxExport: $config->int('galbridge_max_export', 1, 100000),
            maxCount: $config->int('galbridge_max_count', 100, 1000000),
            searchonlyThreshold: $config->int('galbridge_searchonly_threshold', 1, 1000000),
            scopeTtl: $config->int('galbridge_scope_ttl', 30, 3600),
        );
    }
}
