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
 * One address book a Viewer is entitled to, with its data scope and policies. Immutable.
 *
 * The data scope is either an explicit domain list ($allDomains = false, $domains = the list) or
 * "every hosted domain except $excludeDomains" ($allDomains = true). Either way it is applied as the
 * first predicate of every query and re-checked in PHP for every row.
 */
final class BookDefinition
{
    public const DOMAIN = 'galbridge_domain';
    public const GLOBAL = 'galbridge_global';

    /**
     * @param list<string>          $domains        data domains (when !$allDomains)
     * @param list<string>          $excludeDomains excluded data domains (when $allDomains)
     * @param list<string>          $homeDomains    the viewer's domain-book scope (for "foreign" marking)
     * @param array<string, string> $orgLabels      domain => organization label
     * @param null|bool             $searchonly     null = auto (by the size of the scope)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tag,
        public readonly string $name,
        public readonly bool $allDomains,
        public readonly array $domains,
        public readonly array $excludeDomains,
        public readonly array $homeDomains,
        public readonly string $grouping,
        public readonly ?bool $searchonly,
        public readonly bool $autocomplete,
        public readonly bool $export,
        public readonly bool $prefixOnly,
        public readonly bool $everyWordMinLength,
        public readonly int $maxResults,
        public readonly bool $markForeign,
        public readonly array $orgLabels,
    ) {
    }

    public function isGlobal(): bool
    {
        return $this->id === self::GLOBAL;
    }

    /**
     * Is a (normalised) domain inside the data scope?
     */
    public function inScope(string $domain): bool
    {
        return $this->allDomains ? !in_array($domain, $this->excludeDomains, true) : in_array($domain, $this->domains, true);
    }

    public function isEmpty(): bool
    {
        return !$this->allDomains && $this->domains === [];
    }
}
