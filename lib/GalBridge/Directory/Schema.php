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
 * The vmail columns GAL Bridge reads, for the effective configuration - the single list used by the
 * Viewer resolution, the book factory and bin/galbridge.sh diag.
 *
 *  - required: without them no book can work (column_missing, fail closed);
 *  - optional: a configured field or entity kind that is silently left out when the column is not
 *    there or not granted (field_unavailable / entity disabled).
 */
final class Schema
{
    /** field => mailbox column */
    private const FIELD_COLUMNS = [
        'firstname' => 'first_name',
        'surname' => 'last_name',
        'department' => 'department',
        'phone' => 'telephone',
        'mobile' => 'mobile',
        'birthday' => 'birthday',
    ];

    /**
     * @return array<string, list<string>> table => columns
     */
    public static function required(Config $config): array
    {
        $req = [
            'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver'],
            'domain' => ['domain', 'active', 'backupmx'],
            // the viewer's per-user aliases (accesspolicy) are always loaded
            'forwardings' => ['address', 'forwarding', 'is_alias', 'active'],
        ];
        if ($config->bool('galbridge_hide_expired')) {
            $req['mailbox'][] = 'expired';
            $req['domain'][] = 'expired';
        }
        if ($config->bool('galbridge_user_aliases')) {
            $req['forwardings'][] = 'domain'; // display aliases join their domain
        }
        return $req;
    }

    /**
     * Columns of configured optional fields and entity kinds.
     *
     * @return array<string, list<string>> table => columns
     */
    public static function optional(Config $config): array
    {
        $opt = ['mailbox' => []];
        foreach ($config->fields() as $field) {
            if (isset(self::FIELD_COLUMNS[$field])) {
                $opt['mailbox'][] = self::FIELD_COLUMNS[$field];
            }
        }
        $expired = $config->bool('galbridge_hide_expired') ? ['expired'] : [];
        if ($config->bool('galbridge_include_aliases')) {
            $opt['alias'] = ['address', 'name', 'accesspolicy', 'domain', 'active', ...$expired];
            $opt['forwardings'] = ['is_list'];
        }
        if ($config->bool('galbridge_include_mailing_lists')) {
            $opt['maillists'] = ['address', 'name', 'accesspolicy', 'domain', 'active', ...$expired];
            $opt['forwardings'] = array_values(array_unique([...($opt['forwardings'] ?? []), 'is_list', 'is_maillist']));
        }
        if (($config->bool('galbridge_include_aliases') || $config->bool('galbridge_include_mailing_lists'))
            && $config->choice('galbridge_list_policy', Config::LIST_POLICIES) === AccessPolicy::STRICT) {
            $opt['moderators'] = ['address', 'moderator'];
            if ($config->bool('galbridge_include_mailing_lists')) {
                $opt['maillist_owners'] = ['address', 'owner'];
            }
        }
        return $opt;
    }

    /**
     * Required columns that the probe did not see.
     *
     * @param array<string, list<string>> $visible
     *
     * @return list<string> "table.column"
     */
    public static function missingRequired(Config $config, array $visible): array
    {
        $missing = [];
        foreach (self::required($config) as $table => $cols) {
            foreach (array_diff($cols, $visible[$table] ?? []) as $col) {
                $missing[] = $table . '.' . $col;
            }
        }
        return $missing;
    }
}
