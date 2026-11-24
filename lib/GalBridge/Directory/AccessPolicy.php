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

use GalBridge\Normalizer;

/**
 * iRedAPD posting policies (alias.accesspolicy / maillists.accesspolicy) as SQL predicates.
 *
 * A list or alias is shown only to viewers who may post to it; a GAL entry that would bounce is a
 * usability problem and restricted lists reveal organisational structure. Unknown policy values are
 * hidden (fail closed - deliberately stricter than iRedAPD, which lets them pass).
 *
 *  policy                     alias account                      mlmmj list
 *  '' / public                everyone in the book               everyone in the book
 *  domain                     alias domain = H                   same
 *  subdomain                  alias domain in parents(H)         same
 *  membersonly                active is_list member in V         moderator / owner in V
 *  moderatorsonly             moderator in V, or *@H             moderator / owner in V
 *  membersandmoderatorsonly   member or moderator                moderator / owner in V
 *  allowedonly (legacy)       = moderatorsonly                   = moderatorsonly
 */
final class AccessPolicy
{
    public const STRICT = 'strict';
    public const PUBLIC_ONLY = 'public_only';
    public const ALL = 'all';

    private const POLICY = 'LOWER(TRIM(%s.accesspolicy))';

    /**
     * @param string $mode one of the constants
     */
    public function __construct(private readonly string $mode, private readonly Viewer $viewer)
    {
    }

    public function alias(): Sql
    {
        return $this->predicate('a', true);
    }

    public function mailingList(): Sql
    {
        return $this->predicate('ml', false);
    }

    private function predicate(string $t, bool $isAlias): Sql
    {
        if ($this->mode === self::ALL) {
            return Sql::true();
        }
        $policy = sprintf(self::POLICY, $t);
        $public = Sql::raw($policy . " IN ('', 'public')");
        if ($this->mode === self::PUBLIC_ONLY) {
            return $public;
        }

        $h = $this->viewer->home;
        $v = $this->viewer->addresses;
        $branches = [
            $public,
            Sql::and(Sql::raw($policy . " = 'domain'"), Sql::eq($t . '.`domain`', $h)),
            Sql::and(Sql::raw($policy . " = 'subdomain'"), Sql::in($t . '.`domain`', Normalizer::parentDomains($h))),
        ];

        $hasModerators = $this->viewer->hasColumn('moderators', 'moderator') && $this->viewer->hasColumn('moderators', 'address');
        if ($isAlias) {
            $branches[] = Sql::and(
                Sql::raw($policy . " IN ('membersonly', 'membersandmoderatorsonly')"),
                $this->membersExist($v),
            );
            if ($hasModerators) {
                $branches[] = Sql::and(
                    Sql::raw($policy . " IN ('moderatorsonly', 'allowedonly', 'membersandmoderatorsonly')"),
                    Sql::exists(
                        'SELECT 1 FROM `moderators` mo WHERE mo.address = a.address AND (mo.moderator IN (' . self::marks($v) . ') OR mo.moderator = ?)',
                        [...$v, '*@' . $h],
                    ),
                );
            }
        } else {
            $managers = [];
            if ($hasModerators) {
                $managers[] = Sql::exists('SELECT 1 FROM `moderators` mo WHERE mo.address = ml.address AND mo.moderator IN (' . self::marks($v) . ')', $v);
            }
            if ($this->viewer->hasColumn('maillist_owners', 'owner') && $this->viewer->hasColumn('maillist_owners', 'address')) {
                $managers[] = Sql::exists('SELECT 1 FROM `maillist_owners` mw WHERE mw.address = ml.address AND mw.owner IN (' . self::marks($v) . ')', $v);
            }
            $branches[] = Sql::and(
                Sql::raw($policy . " IN ('membersonly', 'moderatorsonly', 'allowedonly', 'membersandmoderatorsonly')"),
                Sql::or(...$managers),
            );
        }

        return Sql::or(...$branches);
    }

    /**
     * @param list<string> $v
     */
    private function membersExist(array $v): Sql
    {
        return Sql::exists(
            'SELECT 1 FROM `forwardings` fm WHERE fm.address = a.address AND fm.is_list = 1 AND fm.active = 1 AND fm.forwarding IN (' . self::marks($v) . ')',
            $v,
        );
    }

    /**
     * @param list<string> $values
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
