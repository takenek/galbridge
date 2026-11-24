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
 * Which books a Viewer may use: a pure function of the Viewer and the administrator configuration.
 * Request parameters play no part in it.
 */
final class Entitlements
{
    /**
     * Source IDs the configuration enables at all (before any Viewer is known).
     *
     * @return list<string>
     */
    public static function enabledIds(Config $config): array
    {
        $ids = [];
        if ($config->bool('galbridge_domain_book')) {
            $ids[] = BookDefinition::DOMAIN;
        }
        if ($config->bool('galbridge_global_book')) {
            $ids[] = BookDefinition::GLOBAL;
        }
        return $ids;
    }

    /**
     * @param callable(string): string $label localized default names ('domainbook', 'globalbook')
     *
     * @return array<string, BookDefinition> entitled books with a non-empty data scope, by source ID
     */
    public static function books(Viewer $viewer, Config $config, callable $label): array
    {
        $books = [];
        // global deny-lists that protect every book: malformed = no books at all (fail closed)
        if ($config->hiddenAddresses() === null || $config->hiddenLocalparts() === null || $config->visibilityColumn() === null) {
            return [];
        }

        $orgs = $config->organizations();
        $orgLabels = [];
        $home = [$viewer->home];
        $homeOrg = null;
        foreach ($orgs as $orgLabel => $domains) {
            foreach ($domains as $d) {
                $orgLabels[$d] = $orgLabel;
            }
            if (in_array($viewer->home, $domains, true)) {
                $home = $domains;
                $homeOrg = $orgLabel;
            }
        }

        if ($config->bool('galbridge_domain_book') && self::viewerAllowed($viewer->home, $config, 'galbridge_domain_viewer_domains')) {
            $grouping = $config->choice('galbridge_domain_groups', Config::GROUPINGS);
            if ($grouping === 'auto') {
                $grouping = count($home) > 1 ? 'domain' : 'department';
            }
            $name = $config->string('galbridge_domain_book_name');
            $books[BookDefinition::DOMAIN] = new BookDefinition(
                id: BookDefinition::DOMAIN,
                tag: 'd',
                name: $name !== '' ? $name : ($homeOrg ?? $label('domainbook')),
                allDomains: false,
                domains: $home,
                excludeDomains: [],
                homeDomains: $home,
                grouping: $grouping,
                searchonly: self::searchonly($config->get('galbridge_domain_searchonly')),
                autocomplete: $config->bool('galbridge_domain_autocomplete'),
                export: $config->bool('galbridge_domain_export'),
                prefixOnly: false,
                everyWordMinLength: false,
                maxResults: $config->int('galbridge_max_results', 10, 5000),
                markForeign: false,
                orgLabels: $orgLabels,
            );
        }

        if ($config->bool('galbridge_global_book') && self::viewerAllowed($viewer->home, $config, 'galbridge_global_viewer_domains')) {
            $data = $config->domainAllowList('galbridge_global_data_domains');
            $dataExclude = $config->domainDenyList('galbridge_global_data_domains_exclude');
            if ($dataExclude !== null) {
                $minusHome = $config->bool('galbridge_global_include_home') ? [] : $home;
                $all = $data === ['*'];
                if ($all && !$config->bool('galbridge_global_allow_wildcard')) {
                    $all = false;
                    $data = [];
                }
                $grouping = $config->choice('galbridge_global_groups', Config::GROUPINGS);
                $name = $config->string('galbridge_global_book_name');
                $book = new BookDefinition(
                    id: BookDefinition::GLOBAL,
                    tag: 'g',
                    name: $name !== '' ? $name : $label('globalbook'),
                    allDomains: $all,
                    domains: $all ? [] : array_values(array_diff($data, $dataExclude, $minusHome)),
                    excludeDomains: $all ? array_values(array_unique(array_merge($dataExclude, $minusHome))) : [],
                    homeDomains: $home,
                    grouping: $grouping === 'auto' ? 'none' : $grouping,
                    searchonly: self::searchonly($config->get('galbridge_global_searchonly')),
                    autocomplete: $config->bool('galbridge_global_autocomplete'),
                    export: $config->bool('galbridge_global_export'),
                    prefixOnly: true,
                    everyWordMinLength: true,
                    maxResults: $config->int('galbridge_global_max_results', 10, 5000),
                    markForeign: $config->bool('galbridge_mark_foreign_domains'),
                    orgLabels: $orgLabels,
                );
                if (!$book->isEmpty()) {
                    $books[BookDefinition::GLOBAL] = $book;
                }
            }
        }

        return $books;
    }

    private static function viewerAllowed(string $home, Config $config, string $option): bool
    {
        $exclude = $config->domainDenyList($option . '_exclude');
        if ($exclude === null || in_array($home, $exclude, true)) {
            return false;
        }
        $allow = $config->domainAllowList($option);
        return $allow === ['*'] || in_array($home, $allow, true);
    }

    private static function searchonly(mixed $value): ?bool
    {
        if (is_string($value) && strtolower(trim($value)) === 'auto') {
            return null;
        }
        return (bool) $value;
    }
}
