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

use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\Limits;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\Repository;
use GalBridge\Directory\SearchSpec;

/**
 * A read-only directory address book (one instance per book and request).
 *
 * Contract notes (Roundcube 1.7):
 *  - reset() and set_search_set() must declare ': void' (abstract parent); parameters stay untyped;
 *  - primary_key 'ID'; a search on 'ID' / primary_key is a strict ID search (compose-to-selected);
 *  - reset() clears filter and result but keeps the group; set_group(0|''|null) clears the group;
 *  - every method fails closed: on any error an empty result (or null / []) and set_error();
 *  - a recipient set or an export is complete or refused - never a truncated prefix (core asks for
 *    page sizes of 999/9999/99999 for group expansion, export and search lists). "Complete" is
 *    decided by the statement that fetches the set (Repository::fullSet(): the cap plus one row),
 *    never by a bounded count; a search LIST that is only looked at is capped with a notice.
 */
final class AddressBook extends \rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly = true;
    public $groups = false;
    public $export_groups = false;
    public $ready = true;
    public $undelete = false;
    public $searchonly = false;

    private ?SearchSpec $filter = null;

    /** @var null|false|array{kind: string, key: string} null = no group, false = match nothing */
    private null|false|array $groupKey = null;

    private ?\rcube_result_set $result = null;

    private int $requestedSize = 10;

    private ?bool $searchonlyResolved = null;

    /** @var null|\Closure(string, array<string, string>): void */
    private ?\Closure $notify;

    /** the "directory unavailable" notice is shown at most once per request (all books together) */
    private static bool $unavailableShown = false;

    /**
     * Claim the once-per-request "directory unavailable" notice; true for the first caller only
     * (shared by AddressBook and the DeniedBooks listed during an outage).
     */
    public static function claimUnavailableNotice(): bool
    {
        if (self::$unavailableShown) {
            return false;
        }
        self::$unavailableShown = true;
        return true;
    }

    /**
     * @param callable(string): string                         $label
     * @param null|\Closure(string, array<string, string>): void $notify user-visible warning (label, vars)
     */
    public function __construct(
        private readonly BookDefinition $book,
        private readonly Repository $repository,
        private readonly QueryBuilder $queries,
        FieldMap $fields,
        private readonly Limits $limits,
        private readonly string $fingerprint,
        private readonly string $action,
        callable $label,
        ?\Closure $notify = null,
    ) {
        $this->coltypes = $fields->coltypes($label);
        $this->groups = $repository->grouping() !== 'none';
        $this->notify = $notify;
    }

    #[\Override]
    public function get_name()
    {
        return $this->book->name;
    }

    #[\Override]
    public function set_search_set($filter): void
    {
        $this->filter = SearchSpec::fromArray($filter, $this->book->id, $this->fingerprint)
            ?? SearchSpec::none($this->book->id, $this->fingerprint);
        $this->result = null;
    }

    #[\Override]
    public function get_search_set()
    {
        return $this->filter?->toArray();
    }

    #[\Override]
    public function reset(): void
    {
        $this->filter = null;
        $this->result = null;
    }

    #[\Override]
    public function set_page($page): void
    {
        $this->list_page = max(1, (int) $page);
    }

    #[\Override]
    public function set_pagesize($size): void
    {
        $this->requestedSize = max(1, (int) $size);
        $this->page_size = min($this->requestedSize, $this->limits->corePageCap);
    }

    /**
     * @param null|string|list<string> $cols
     */
    #[\Override]
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        try {
            return $this->result = $this->listRecords((int) $subset, (bool) $nocount);
        } catch (\Throwable $e) {
            return $this->result = $this->failed($e);
        }
    }

    #[\Override]
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        try {
            $this->filter = $this->buildSpec($fields, $value, (int) $mode, $required);
            if (!$this->queries->canMatch($this->filter)) {
                return $this->result = new \rcube_result_set(0, 0);
            }
            return $select ? $this->list_records(null, 0, $nocount) : $this->count();
        } catch (\Throwable $e) {
            $this->filter = SearchSpec::none($this->book->id, $this->fingerprint);
            return $this->result = $this->failed($e);
        }
    }

    #[\Override]
    public function count()
    {
        $first = ($this->list_page - 1) * $this->page_size;
        try {
            return new \rcube_result_set($this->total(), $first);
        } catch (\Throwable $e) {
            $this->failed($e);
            return new \rcube_result_set(0, $first);
        }
    }

    #[\Override]
    public function get_result()
    {
        return $this->result;
    }

    /**
     * @return null|array<string, mixed>|\rcube_result_set
     */
    #[\Override]
    public function get_record($id, $assoc = false)
    {
        $this->result = null;
        try {
            $record = $this->fetchRecord($id);
        } catch (\Throwable $e) {
            $this->failed($e);
            return null;
        }
        if ($record === null) {
            return null;
        }
        $this->result = new \rcube_result_set(1, 0);
        $this->result->add($record);
        return $assoc ? $record : $this->result;
    }

    #[\Override]
    public function set_group($group_id): void
    {
        if (empty($group_id)) {
            $this->group_id = null;
            $this->groupKey = null;
            return;
        }
        $this->group_id = is_scalar($group_id) ? (string) $group_id : '';
        $decoded = IdCodec::decodeGroup($group_id);
        $kind = $this->repository->grouping() === 'domain' ? IdCodec::GROUP_DOMAIN : IdCodec::GROUP_DEPARTMENT;
        if ($decoded === null || !$this->groups || $decoded['book'] !== $this->book->tag || $decoded['kind'] !== $kind) {
            Log::once('warning', 'groups', 'invalid_gid', 'invalid or foreign group id', ['book' => $this->book->id, 'tag' => Log::tag($this->group_id)]);
            $this->groupKey = false;
            return;
        }
        $this->groupKey = ['kind' => $decoded['kind'], 'key' => $decoded['key']];
    }

    /**
     * @return list<array{ID: string, name: string, virtual: bool}>
     */
    #[\Override]
    public function list_groups($search = null, $mode = 0)
    {
        if (!$this->groups || ($this->action === 'autocomplete' && !$this->book->autocomplete)) {
            return []; // the per-book autocomplete switch covers groups as well
        }
        try {
            $groups = $this->repository->groups();
        } catch (\Throwable $e) {
            $this->failed($e);
            return [];
        }
        $search = is_scalar($search) ? trim((string) $search) : '';
        $out = [];
        if ($search === '') {
            if ($this->isSearchOnly()) {
                return [];
            }
            foreach ($groups as $g) {
                $out[] = ['ID' => $g['id'], 'name' => $g['label'], 'virtual' => true];
            }
            return $out;
        }
        $words = $this->queries->words($search, (int) $mode);
        if ($words === null) {
            return [];
        }
        foreach ($groups as $g) {
            if ($g['count'] > $this->limits->groupCap) {
                continue; // never offer a group whose expansion would be refused
            }
            $label = mb_strtolower($g['label']);
            $hit = true;
            foreach ($words as [$word, $wordMode]) {
                $word = Normalizer::labelWord($word); // labels are display text
                $hit = $hit && match ($wordMode) {
                    self::SEARCH_STRICT => $label === $word,
                    self::SEARCH_PREFIX => str_starts_with($label, $word) || str_contains($label, ' ' . $word),
                    default => str_contains($label, $word),
                };
            }
            if ($hit) {
                $out[] = ['ID' => $g['id'], 'name' => $g['label'], 'virtual' => true];
            }
        }
        return $out;
    }

    /**
     * @return null|array{ID: string, name: string}
     */
    #[\Override]
    public function get_group($group_id)
    {
        if (!$this->groups || !is_string($group_id) || $group_id === '') {
            return null;
        }
        try {
            $g = $this->repository->group($group_id);
        } catch (\Throwable $e) {
            $this->failed($e);
            return null;
        }
        return $g === null ? null : ['ID' => $g['id'], 'name' => $g['label']];
    }

    /**
     * @return array<string, string> group id => name
     */
    #[\Override]
    public function get_record_groups($id)
    {
        if (!$this->groups) {
            return [];
        }
        $decoded = IdCodec::decodeContact($id);
        if ($decoded === null || $decoded['kind'] !== IdCodec::PERSON) {
            return []; // group members are persons only
        }
        try {
            $record = $this->fetchRecord($id);
            if ($record === null) {
                return [];
            }
            $address = (string) ($record['email:work'][0] ?? '');
            $domainGrouping = $this->repository->grouping() === 'domain';
            $key = $domainGrouping
                ? substr($address, (int) strrpos($address, '@') + 1)
                : mb_strtolower(trim((string) ($record['department'] ?? '')));
            if ($key === '') {
                return []; // no department: member of no department group
            }
            foreach ($this->repository->groups() as $g) {
                // A department key is the value as SQL computed it (LOWER(TRIM(department))), the
                // record carries display text ('&' as U+FF06, collapsed whitespace ...): the key is
                // compared in that notation, case-insensitively in PHP as well
                if ($domainGrouping ? $g['key'] === $key : mb_strtolower(Normalizer::displayText($g['key'])) === $key) {
                    return [$g['id'] => $g['label']];
                }
            }
        } catch (\Throwable $e) {
            $this->failed($e);
        }
        return [];
    }

    // ------------------------------------------------------------------ internals

    private function listRecords(int $subset, bool $nocount): \rcube_result_set
    {
        $first = ($this->list_page - 1) * $this->page_size;
        if ($this->groupKey === false || ($this->filter !== null && !$this->queries->canMatch($this->filter))) {
            return new \rcube_result_set(0, $first);
        }
        if ($this->groupKey !== null && $this->selectedGroup() === null) {
            // a well-formed id that is not one of this book's groups (also when the book shows no
            // groups because it has more than galbridge_max_groups): nothing, like any unknown id
            return new \rcube_result_set(0, $first);
        }
        // a full-set request: core asks for 999/9999/99999 rows; compose-to-group and group expansion
        // are recipient sets whatever page size they use (core passes max_group_members for mailto)
        $fullSet = $this->list_page === 1 && (
            $this->requestedSize > $this->limits->corePageCap
            || ($this->groupKey !== null && $this->filter === null && in_array($this->action, ['mailto', 'group-expand'], true))
        );
        if ($this->filter === null && $this->groupKey === null && $this->isSearchOnly()) {
            if ($fullSet) {
                // the whole of a search-only book (export): refused explicitly, never an empty file
                return $this->refuse('exportdisabled', [], 'export_disabled');
            }
            $empty = new \rcube_result_set(0, 0);
            $empty->searchonly = true;
            return $empty;
        }

        if ($this->filter !== null && $this->filter->type === SearchSpec::IDS) {
            // search() already refused more than galbridge_max_ids IDs
            $records = $this->page(0, $this->limits->maxIds);
            return $this->resultSet($records, count($records), 0, $subset);
        }

        if ($fullSet && $this->groupKey !== null && $this->filter === null) {
            // compose-to-group, group expansion, group export: all members or nothing
            $cap = $this->limits->groupCap;
            $set = $this->groupCount() > $cap ? null : $this->fullSet($cap);
            return $set === null
                ? $this->refuse('grouptoolarge', ['max' => (string) $cap], 'group_too_large')
                : $this->complete($set, $subset);
        }

        if ($fullSet && $this->filter !== null && $this->action === 'export') {
            // export of a search result: a download cannot show the "first N matches" notice, so it
            // is complete or refused like every other export
            $cap = $this->book->maxResults;
            $set = $this->repository->count($this->filter, $this->groupKey, $cap + 1) > $cap ? null : $this->fullSet($cap);
            return $set === null
                ? $this->refuse('exporttoolarge', ['max' => (string) $cap], 'export_too_large')
                : $this->complete($set, $subset);
        }

        if ($fullSet && $this->filter !== null) {
            // search lists: capped, with a notice when truncated
            $cap = $this->book->maxResults;
            $records = $this->page(0, $cap + 1);
            if (count($records) > $cap) {
                $records = array_slice($records, 0, $cap);
                $this->notice('toomanyresults', ['max' => (string) $cap]);
            }
            return $this->resultSet($records, count($records), 0, $subset);
        }

        if ($fullSet) {
            // the whole book (export): an explicit policy instead of an alphabetical prefix
            if (!$this->book->export) {
                return $this->refuse('exportdisabled', [], 'export_disabled');
            }
            // counted up to the limit of THIS operation plus one (galbridge_max_count bounds list
            // totals only), then fetched by one statement that would notice a larger set
            $cap = $this->limits->maxExport;
            $set = $this->repository->count(null, null, $cap + 1) > $cap ? null : $this->fullSet($cap);
            return $set === null
                ? $this->refuse('exporttoolarge', ['max' => (string) $cap], 'export_too_large')
                : $this->complete($set, $subset);
        }

        if ($first >= $this->limits->maxCount) {
            return new \rcube_result_set(0, $first);
        }
        $records = $this->page($first, $this->page_size);
        $total = $nocount ? $first + count($records) : $this->total();
        return $this->resultSet($records, $total, $first, $subset);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function page(int $offset, int $limit): array
    {
        return $this->repository->page($this->filter, $this->groupKey, $this->sortColumn(), (string) $this->sort_order, $offset, $limit);
    }

    /**
     * The complete current set (filter and group as selected) of at most $cap records, or null.
     *
     * @return null|array{0: list<array<string, mixed>>, 1: int}
     */
    private function fullSet(int $cap): ?array
    {
        return $this->repository->fullSet($this->filter, $this->groupKey, $this->sortColumn(), (string) $this->sort_order, $cap);
    }

    /**
     * The result of a full-set request: every record, or a refusal when one of the fetched rows
     * could not be delivered (the set would silently lack an entry).
     *
     * @param array{0: list<array<string, mixed>>, 1: int} $set
     */
    private function complete(array $set, int $subset): \rcube_result_set
    {
        [$records, $dropped] = $set;
        if ($dropped > 0) {
            return $this->refuse('incomplete', [], 'set_incomplete', ['dropped' => $dropped]);
        }
        return $this->resultSet($records, count($records), 0, $subset);
    }

    /**
     * List total for display, bounded by galbridge_max_count (never used to decide whether a
     * recipient set or an export is complete).
     */
    private function total(): int
    {
        if ($this->groupKey === false || ($this->filter !== null && !$this->queries->canMatch($this->filter))) {
            return 0;
        }
        if ($this->groupKey !== null && $this->selectedGroup() === null) {
            return 0;
        }
        if ($this->filter === null && $this->groupKey === null && $this->isSearchOnly()) {
            return 0;
        }
        if ($this->filter === null && $this->groupKey !== null) {
            return $this->groupCount();
        }
        return $this->repository->count($this->filter, $this->groupKey, $this->limits->maxCount);
    }

    /**
     * The group selected with set_group() when it is one of the groups this book offers (the
     * request's aggregate), else null: a group id is honoured only for a listed group.
     *
     * @return null|array{id: string, kind: string, key: string, label: string, count: int}
     */
    private function selectedGroup(): ?array
    {
        return is_array($this->groupKey) && is_string($this->group_id) ? $this->repository->group($this->group_id) : null;
    }

    /**
     * Members of the selected group: the exact COUNT of the group aggregate (not a bounded count).
     */
    private function groupCount(): int
    {
        return $this->selectedGroup()['count'] ?? 0;
    }

    private function isSearchOnly(): bool
    {
        if ($this->book->searchonly !== null) {
            return $this->book->searchonly;
        }
        if ($this->searchonlyResolved === null) {
            // decided once per Viewer TTL (cached next to the Viewer, bound to the fingerprint)
            $cacheKey = $this->book->id . ':' . $this->fingerprint;
            $cached = $_SESSION['galbridge_searchonly'][$cacheKey] ?? null;
            if (is_array($cached) && is_bool($cached['v'] ?? null) && is_int($cached['at'] ?? null)
                && time() - $cached['at'] < $this->limits->scopeTtl) {
                return $this->searchonlyResolved = $cached['v'];
            }
            $threshold = $this->limits->searchonlyThreshold;
            $this->searchonlyResolved = $this->repository->count(null, null, $threshold + 1) > $threshold;
            // one entry per book; expired entries (and those of another configuration) are dropped
            $map = is_array($_SESSION['galbridge_searchonly'] ?? null) ? $_SESSION['galbridge_searchonly'] : [];
            $map = array_filter($map, fn ($e, $k): bool => is_array($e) && is_int($e['at'] ?? null)
                && time() - $e['at'] < $this->limits->scopeTtl && str_ends_with((string) $k, ':' . $this->fingerprint), ARRAY_FILTER_USE_BOTH);
            $map[$cacheKey] = ['v' => $this->searchonlyResolved, 'at' => time()];
            $_SESSION['galbridge_searchonly'] = $map;
        }
        return $this->searchonlyResolved;
    }

    private function sortColumn(): string
    {
        return in_array($this->sort_col, ['name', 'firstname', 'surname'], true) ? (string) $this->sort_col : 'name';
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function resultSet(array $records, int $total, int $first, int $subset): \rcube_result_set
    {
        if ($subset > 0) {
            $records = array_slice($records, 0, $subset);
        } elseif ($subset < 0) {
            $records = array_slice($records, $subset);
        }
        $rs = new \rcube_result_set(max($total, count($records)), $first);
        foreach ($records as $r) {
            $rs->add($r);
        }
        return $rs;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function fetchRecord(mixed $id): ?array
    {
        $decoded = IdCodec::decodeContact($id);
        if ($decoded === null) {
            if ($id !== null && $id !== '') {
                Log::once('warning', 'records', 'invalid_id', 'invalid contact id', ['book' => $this->book->id]);
            }
            return null;
        }
        $spec = new SearchSpec(SearchSpec::IDS, $this->book->id, $this->fingerprint, ids: [$decoded]);
        $records = $this->repository->page($spec, null, 'name', 'ASC', 0, 1);
        return $records[0] ?? null;
    }

    private function buildSpec(mixed $fields, mixed $value, int $mode, mixed $required): SearchSpec
    {
        $none = SearchSpec::none($this->book->id, $this->fingerprint);
        if ($this->action === 'autocomplete' && !$this->book->autocomplete) {
            return $none;
        }
        $req = [];
        foreach (is_array($required) ? $required : (is_string($required) && $required !== '' ? [$required] : []) as $r) {
            if (is_string($r) && $r !== '') {
                $req[] = $r;
            }
        }

        if ($fields === 'ID' || $fields === $this->primary_key) {
            $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
            if (count($items) > $this->limits->maxIds) {
                $this->refuse('toomanyids', ['max' => (string) $this->limits->maxIds], 'too_many_ids');
                return $none;
            }
            $rejected = 0;
            $ids = IdCodec::decodeContactList($items, $rejected);
            if ($rejected > 0) {
                Log::once('warning', 'records', 'invalid_id', 'invalid contact ids in an ID search', ['book' => $this->book->id, 'count' => $rejected]);
            }
            return $ids === [] ? $none : new SearchSpec(SearchSpec::IDS, $this->book->id, $this->fingerprint, required: $req, ids: $ids);
        }

        $fieldList = [];
        foreach (is_array($fields) ? $fields : [$fields] as $f) {
            if (is_string($f) && $f !== '') {
                $fieldList[] = $f;
            }
        }
        if ($fieldList === []) {
            return $none;
        }

        if (is_array($value)) {
            $values = [];
            foreach ($fieldList as $i => $f) {
                $v = $value[$i] ?? '';
                $values[] = is_scalar($v) ? (string) $v : '';
            }
            return new SearchSpec(SearchSpec::ADVANCED, $this->book->id, $this->fingerprint, $fieldList, $values, $mode & 3, $req);
        }

        if (!is_scalar($value)) {
            return $none;
        }
        return new SearchSpec(SearchSpec::QUICK, $this->book->id, $this->fingerprint, $fieldList, [(string) $value], $mode & 3, $req);
    }

    /**
     * @param array<string, string>     $vars    variables of the user-visible message
     * @param array<string, int|string> $context further values for the log line only
     */
    private function refuse(string $label, array $vars, string $code, array $context = []): \rcube_result_set
    {
        Log::once('warning', 'limits', $code, 'request refused', ['book' => $this->book->id] + $vars + $context);
        $this->set_error(self::ERROR_SEARCH, 'galbridge.' . $label);
        $this->notice($label, $vars);
        return new \rcube_result_set(0, 0);
    }

    /**
     * @param array<string, string> $vars
     */
    private function notice(string $label, array $vars): void
    {
        if ($this->notify !== null) {
            ($this->notify)($label, $vars);
        }
    }

    private function failed(\Throwable $e): \rcube_result_set
    {
        if (!$e instanceof Exception\GalBridgeException) {
            Log::exception('book', $e, ['book' => $this->book->id]);
        }
        $this->set_error(self::ERROR_SEARCH, 'galbridge.unavailable');
        // core's read actions never call get_error(): tell the user once per request
        if (self::claimUnavailableNotice()) {
            $this->notice('unavailable', []);
        }
        return new \rcube_result_set(0, 0);
    }
}
