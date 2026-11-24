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

namespace GalBridge;

/**
 * Fail-closed book for one of our source IDs that the user may not use (not entitled, empty scope,
 * plugin disabled, directory unavailable, stale preference, crafted _source).
 *
 * Returning an instance avoids Roundcube's fatal error 700 ("Addressbook source not found") while
 * revealing nothing: it is not ready, has no groups, every read is empty and get_record() is null.
 * It never connects to the database.
 */
final class DeniedBook extends \rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly = true;
    public $groups = false;
    public $export_groups = false;
    public $ready = false;
    public $undelete = false;
    public $searchonly = false;

    public function __construct(private readonly string $name)
    {
        $this->coltypes = ['name' => ['limit' => 1], 'email' => ['subtypes' => ['work']]];
    }

    #[\Override]
    public function get_name()
    {
        return $this->name;
    }

    #[\Override]
    public function set_search_set($filter): void
    {
    }

    #[\Override]
    public function get_search_set()
    {
        return null;
    }

    #[\Override]
    public function reset(): void
    {
    }

    /**
     * @param null|string|list<string> $cols
     */
    #[\Override]
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        return new \rcube_result_set(0, 0);
    }

    #[\Override]
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        return new \rcube_result_set(0, 0);
    }

    #[\Override]
    public function count()
    {
        return new \rcube_result_set(0, 0);
    }

    #[\Override]
    public function get_result()
    {
        return null;
    }

    /**
     * @return null|array<string, mixed>|\rcube_result_set
     */
    #[\Override]
    public function get_record($id, $assoc = false)
    {
        return null;
    }

    /**
     * @return list<array{ID: string, name: string, virtual: bool}>
     */
    #[\Override]
    public function list_groups($search = null, $mode = 0)
    {
        return [];
    }

    /**
     * @return null|array{ID: string, name: string}
     */
    #[\Override]
    public function get_group($group_id)
    {
        return null;
    }

    /**
     * @return array<string, string> group id => name
     */
    #[\Override]
    public function get_record_groups($id)
    {
        return [];
    }
}
