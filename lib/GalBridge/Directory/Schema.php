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
 *
 * The iRedMail table "forwardings" is never read directly. It also holds the private forwarding
 * targets of mailboxes (is_forwarding rows), which the plugin's role must not be able to read, so
 * the grant templates create the view FORWARDINGS over the three row kinds GAL Bridge needs -
 * per-user aliases (is_alias), alias-account members (is_list) and mailing lists (is_maillist) -
 * and grant only that view. A role that can read the table itself is refused (role_too_broad).
 */
final class Schema
{
    /** the restricted view over vmail.forwardings (SQL/grants.*.sql) */
    public const FORWARDINGS = 'galbridge_forwardings';

    /** the iRedMail table behind it: must NOT be readable by the plugin's role */
    public const FORWARDINGS_TABLE = 'forwardings';

    /**
     * Every column the grant templates give the role (SQL/grants.*.sql, the commented-out
     * personal-data grant included) - and so every column a statement of GAL Bridge may read
     * (with the configured galbridge_visibility_column). diag names any other column the role can
     * see; a unit test keeps this list equal to the templates.
     */
    public const GRANTABLE = [
        'mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver', 'expired', 'department', 'first_name', 'last_name', 'telephone', 'mobile', 'birthday'],
        'domain' => ['domain', 'active', 'backupmx', 'expired'],
        self::FORWARDINGS => ['address', 'forwarding', 'domain', 'is_alias', 'is_list', 'is_maillist', 'active'],
        'alias' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'maillists' => ['address', 'name', 'accesspolicy', 'domain', 'active', 'expired'],
        'moderators' => ['address', 'moderator'],
        'maillist_owners' => ['address', 'owner'],
    ];

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
            // the viewer's per-user aliases (accesspolicy) are always loaded, with the state of the
            // domain each alias row belongs to
            self::FORWARDINGS => ['address', 'forwarding', 'domain', 'is_alias', 'active'],
        ];
        if ($config->bool('galbridge_hide_expired')) {
            $req['mailbox'][] = 'expired';
            $req['domain'][] = 'expired';
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
            $opt[self::FORWARDINGS] = ['is_list'];
        }
        if ($config->bool('galbridge_include_mailing_lists')) {
            $opt['maillists'] = ['address', 'name', 'accesspolicy', 'domain', 'active', ...$expired];
            $opt[self::FORWARDINGS] = array_values(array_unique([...($opt[self::FORWARDINGS] ?? []), 'is_list', 'is_maillist']));
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
