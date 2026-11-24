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
 * The authenticated principal, as resolved from the vmail database (never from request input).
 *
 *  - login:     normalised Roundcube login (= mailbox.username)
 *  - home:      mailbox.domain of that row (H) - NOT the domain part of the login string
 *  - addresses: login + its active per-user alias addresses (V), used for accesspolicy only
 *  - columns:   vmail columns this role can see (schema/privilege probe)
 */
final class Viewer
{
    /**
     * @param list<string>                $addresses
     * @param array<string, list<string>> $columns
     */
    public function __construct(
        public readonly string $login,
        public readonly string $home,
        public readonly array $addresses,
        public readonly array $columns,
    ) {
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns[$table] ?? [], true);
    }

    /**
     * @return array{login: string, home: string, addresses: list<string>, columns: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return ['login' => $this->login, 'home' => $this->home, 'addresses' => $this->addresses, 'columns' => $this->columns];
    }

    /**
     * @param array<mixed> $a
     */
    public static function fromArray(array $a): ?self
    {
        if (!is_string($a['login'] ?? null) || !is_string($a['home'] ?? null) || !is_array($a['addresses'] ?? null) || !is_array($a['columns'] ?? null)) {
            return null;
        }
        $addresses = array_values(array_filter($a['addresses'], 'is_string'));
        $columns = [];
        foreach ($a['columns'] as $t => $cols) {
            if (is_string($t) && is_array($cols)) {
                $columns[$t] = array_values(array_filter($cols, 'is_string'));
            }
        }
        return new self($a['login'], $a['home'], $addresses, $columns);
    }
}
