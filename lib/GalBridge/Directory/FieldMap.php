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
 * Roundcube fields <-> vmail columns.
 *
 * Effective fields = galbridge_fields ∩ columns this role can see. A server without the iRedMail
 * 1.7.3 columns (first_name, last_name, mobile, telephone, birthday) keeps working with fewer
 * fields. The effective set is the single source for the SELECT list, coltypes, searchable fields,
 * sort keys, grouping and export, so nothing outside it can be shown or used as a search oracle.
 *
 * Column expressions returned here are constants of this class, never user input.
 */
final class FieldMap
{
    /** field => mailbox column (person) */
    private const PERSON_COLUMNS = [
        'name' => 'name',
        'firstname' => 'first_name',
        'surname' => 'last_name',
        'department' => 'department',
        'phone' => 'telephone',
        'mobile' => 'mobile',
        'birthday' => 'birthday',
    ];

    /** @var array<string, bool> */
    private array $effective = [];

    /**
     * @param list<string> $configured galbridge_fields (normalised by Config)
     */
    public function __construct(array $configured, Viewer $viewer, private readonly bool $userAliases, private readonly bool $hasOrganizations)
    {
        foreach ($configured as $field) {
            if ($field === 'email' || $field === 'name') {
                $this->effective[$field] = true;
            } elseif ($field === 'organization') {
                $this->effective[$field] = $hasOrganizations;
            } elseif (isset(self::PERSON_COLUMNS[$field])) {
                $this->effective[$field] = $viewer->hasColumn('mailbox', self::PERSON_COLUMNS[$field]);
            }
        }
    }

    public static function fromConfig(Config $config, Viewer $viewer): self
    {
        return new self($config->fields(), $viewer, $config->bool('galbridge_user_aliases'), $config->organizations() !== []);
    }

    public function has(string $field): bool
    {
        return $this->effective[$field] ?? false;
    }

    /**
     * Configured fields that are not available (missing or not granted columns).
     *
     * @param list<string> $configured
     *
     * @return list<string>
     */
    public function missing(array $configured): array
    {
        return array_values(array_filter($configured, fn (string $f): bool => !$this->has($f)));
    }

    public function userAliases(): bool
    {
        return $this->userAliases;
    }

    /**
     * Person columns for the details query (always username, name, domain).
     *
     * @return list<string> column names of `mailbox`
     */
    public function personColumns(): array
    {
        $cols = ['username', 'name', 'domain'];
        foreach (['firstname', 'surname', 'department', 'phone', 'mobile', 'birthday'] as $f) {
            if ($this->has($f)) {
                $cols[] = self::PERSON_COLUMNS[$f];
            }
        }
        return $cols;
    }

    /**
     * Roundcube coltypes (base keys; record keys use the subtype form email:work, phone:mobile).
     *
     * @param callable(string): string $label
     *
     * @return array<string, array<string, mixed>>
     */
    public function coltypes(callable $label): array
    {
        $types = ['name' => ['limit' => 1]];
        if ($this->has('firstname')) {
            $types['firstname'] = ['limit' => 1];
        }
        if ($this->has('surname')) {
            $types['surname'] = ['limit' => 1];
        }
        $types['email'] = ['subtypes' => $this->userAliases ? ['work', 'other'] : ['work']];
        $phone = [];
        if ($this->has('phone')) {
            $phone[] = 'work';
        }
        if ($this->has('mobile')) {
            $phone[] = 'mobile';
        }
        if ($phone !== []) {
            $types['phone'] = ['subtypes' => $phone];
        }
        if ($this->has('department')) {
            $types['department'] = ['limit' => 1];
        }
        if ($this->has('organization')) {
            $types['organization'] = ['limit' => 1];
        }
        if ($this->has('birthday')) {
            $types['birthday'] = ['type' => 'date', 'limit' => 1, 'nosearch' => true];
        }
        return $types;
    }

    /**
     * Searchable Roundcube fields (birthday is never searchable: DATE column).
     *
     * @return list<string>
     */
    public function searchableFields(): array
    {
        $fields = ['name', 'email'];
        foreach (['firstname', 'surname', 'department', 'organization'] as $f) {
            if ($this->has($f)) {
                $fields[] = $f;
            }
        }
        if ($this->has('phone') || $this->has('mobile')) {
            $fields[] = 'phone';
        }
        return $fields;
    }

    public function isSearchable(string $field): bool
    {
        return in_array($field, $this->searchableFields(), true);
    }

    /**
     * Text columns of `mailbox` (alias m) a search on $field matches. Empty = not a column search
     * (email and organization are handled by the query builder).
     *
     * @return list<string>
     */
    public function personSearchColumns(string $field): array
    {
        return match ($field) {
            'name' => array_values(array_filter([
                'm.name',
                $this->has('firstname') ? 'm.first_name' : null,
                $this->has('surname') ? 'm.last_name' : null,
            ])),
            'firstname' => $this->has('firstname') ? ['m.first_name'] : [],
            'surname' => $this->has('surname') ? ['m.last_name'] : [],
            'department' => $this->has('department') ? ['m.department'] : [],
            'phone' => array_values(array_filter([
                $this->has('phone') ? 'm.telephone' : null,
                $this->has('mobile') ? 'm.mobile' : null,
            ])),
            default => [],
        };
    }

    /**
     * Record fields that can be required (search $required) and the person column that must then
     * hold a shown value ('' = always present). QueryBuilder::required() renders it by column type:
     * a text column must be non-empty after trimming; 'birthday' is a DATE (iRedMail: NOT NULL
     * DEFAULT '0001-01-01') and must be a real date, year 0001 meaning none (as in RecordMapper) - a
     * date is never compared with text.
     */
    public function requiredColumn(string $field): ?string
    {
        return match ($field) {
            'email', 'name' => '',
            'firstname', 'surname', 'department', 'phone', 'mobile', 'birthday' => $this->has($field) ? self::PERSON_COLUMNS[$field] : null,
            default => null,
        };
    }

    /**
     * Sort key expression for persons (alias m), with fallbacks over available columns only.
     * Core asks for 'surname' by default (addressbook_sort_col), while open-source iRedAdmin fills
     * only mailbox.name, so empty values fall back to the display name and then the address.
     */
    public function personSortKey(string $sortCol): string
    {
        $name = "NULLIF(TRIM(m.name), '')";
        $parts = match ($sortCol) {
            'surname' => [$this->has('surname') ? "NULLIF(TRIM(m.last_name), '')" : null, $name],
            'firstname' => [$this->has('firstname') ? "NULLIF(TRIM(m.first_name), '')" : null, $name],
            default => [$name],
        };
        $parts = array_values(array_filter($parts));
        $parts[] = 'm.username';
        return 'LOWER(COALESCE(' . implode(', ', $parts) . '))';
    }

    /**
     * Sort key expression for alias accounts / mailing lists ($alias = a | ml).
     */
    public static function listSortKey(string $alias): string
    {
        return "LOWER(COALESCE(NULLIF(TRIM({$alias}.name), ''), {$alias}.address))";
    }
}
