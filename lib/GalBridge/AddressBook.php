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
use GalBridge\Directory\LookupBudget;
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
 *  - a recipient set or an export is complete or refused - never a truncated prefix. A full set is
 *    recognised by the page sizes core uses for one (9999 for search lists and group expansion,
 *    99999 for exports - CORE_FULL_SET_SIZES), by a search with a page size above the page bound,
 *    and by the recipient actions of a group (compose to a group, group expansion: core passes
 *    max_group_members or 999 there). "Complete" is decided by the statement that fetches the set
 *    (Repository::fullSet(): the cap plus one row), never by a bounded count; a search LIST that is
 *    only looked at is capped with a notice. A selection by IDs (compose to selected, export of
 *    selected) is complete or refused as well: an ID that is malformed or finds no entry refuses it;
 *  - a list page is bounded: a page size above the page bound is served as a page of the bound
 *    (notice pagetoolarge), never as an export; a list total is capped at galbridge_max_count
 *    (notice listcapped), a search result at the result cap of the book (notice toomanyresults),
 *    and pages beyond them are empty; the page number is clamped (MAX_PAGE);
 *  - contacts read one by one (get_record(), get_record_groups() - core's copy action calls it for
 *    every ID of the request) are bounded per request by galbridge_max_ids (LookupBudget).
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

    /** the page sizes core asks for when it wants a whole set (search lists / group expansion, exports) */
    public const CORE_FULL_SET_SIZES = [9999, 99999];

    /** the actions that use a set selected by IDs as a whole: compose to selected, export of selected */
    public const SELECTION_ACTIONS = ['mailto', 'export'];

    /**
     * The highest page number honoured: with the largest page the offset stays below 10^9 (no
     * float on any platform), and every offset beyond galbridge_max_count is an empty page anyway.
     */
    public const MAX_PAGE = 1000001;

    /** the largest page size honoured (core asks for 99999 at most) */
    public const MAX_PAGE_SIZE = 1000000;

    private ?SearchSpec $filter = null;

    /** IDs of the current ID search that could not be decoded (buildSpec()) */
    private int $rejectedIds = 0;

    private readonly LookupBudget $budget;

    /** set_search_set() got a stored search that cannot be applied any more (see listRecords()) */
    private bool $staleSearchSet = false;

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
        ?LookupBudget $budget = null,
    ) {
        $this->coltypes = $fields->coltypes($label);
        $this->groups = $repository->grouping() !== 'none';
        $this->notify = $notify;
        // one budget per request, shared by the books of the request (Factory); a book on its own
        // gets one of its own
        $this->budget = $budget ?? new LookupBudget($limits->maxIds);
    }

    #[\Override]
    public function get_name()
    {
        return $this->book->name;
    }

    #[\Override]
    public function set_search_set($filter): void
    {
        $spec = SearchSpec::fromArray($filter, $this->book->id, $this->fingerprint);
        // a search stored with another configuration (fingerprint) or by another version
        $this->staleSearchSet = $spec === null && $filter !== null && $filter !== [];
        $this->filter = $spec ?? SearchSpec::none($this->book->id, $this->fingerprint);
        $this->rejectedIds = 0;
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
        $this->staleSearchSet = false;
        $this->rejectedIds = 0;
        $this->result = null;
    }

    /**
     * The page number, clamped to 1..MAX_PAGE (compared as a float first, so that no value of the
     * request is ever cast to an int out of range - PHP 8.5 warns about that).
     */
    #[\Override]
    public function set_page($page): void
    {
        $this->list_page = self::clamp($page, self::MAX_PAGE);
    }

    #[\Override]
    public function set_pagesize($size): void
    {
        $this->requestedSize = self::clamp($size, self::MAX_PAGE_SIZE);
        $this->page_size = min($this->requestedSize, $this->limits->corePageCap);
    }

    /**
     * A positive whole number from a request value: 1 when it is no number, at most $max.
     */
    private static function clamp(mixed $value, int $max): int
    {
        $n = is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : 1.0);
        if (is_nan($n) || $n < 1.0) {
            return 1;
        }
        return $n >= (float) $max ? $max : (int) $n;
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
            $this->staleSearchSet = false;
            $this->filter = $this->buildSpec($fields, $value, (int) $mode, $required);
            if (!$this->queries->canMatch($this->filter)) {
                if ($this->rejectedIds > 0 && in_array($this->action, self::SELECTION_ACTIONS, true)) {
                    // a selection none of whose IDs could be read: refused, never an empty set
                    return $this->result = $this->refuse('incomplete', [], 'set_incomplete', ['missing' => $this->rejectedIds, 'dropped' => 0]);
                }
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
            $domain = substr($address, (int) strrpos($address, '@') + 1);
            $domainGrouping = $this->repository->grouping() === 'domain';
            // the department groups of the global book are per domain ("domain:department")
            $perDomain = !$domainGrouping && $this->book->id === BookDefinition::GLOBAL;
            $key = $domainGrouping ? $domain : mb_strtolower(trim((string) ($record['department'] ?? '')));
            if ($key === '') {
                return []; // no department: member of no department group
            }
            foreach ($this->repository->groups() as $g) {
                if ($domainGrouping) {
                    $hit = $g['key'] === $key;
                } else {
                    // A department key is the value as SQL computed it (LOWER(TRIM(department))), the
                    // record carries display text ('&' as U+FF06, collapsed whitespace, cut at an
                    // at-sign ...): the key is compared in that notation, case-insensitively in PHP as
                    // well - in the global book together with the domain of the entry
                    [$groupDomain, $department] = $perDomain ? array_pad(explode(':', $g['key'], 2), 2, '') : [$domain, $g['key']];
                    $hit = $groupDomain === $domain && mb_strtolower(Normalizer::cutAtSign(Normalizer::displayText($department))) === $key;
                }
                if ($hit) {
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
        if ($this->staleSearchSet && $this->action === 'export') {
            // the export of a search whose stored criteria cannot be applied any more (configuration
            // changed since, another node): a file without the entries of this book would look
            // complete - refused like every other incomplete export
            return $this->refuse('incomplete', [], 'search_set_stale');
        }
        if ($this->groupKey === false || ($this->filter !== null && !$this->queries->canMatch($this->filter))) {
            return new \rcube_result_set(0, $first);
        }
        if ($this->groupKey !== null && $this->selectedGroup() === null) {
            // a well-formed id that is not one of this book's groups (also when the book shows no
            // groups because it has more than galbridge_max_groups): nothing, like any unknown id
            return new \rcube_result_set(0, $first);
        }
        // a full-set request: the page sizes core uses for one (9999: search lists, group expansion;
        // 99999: exports), a search with a page size above the page bound, and compose-to-group and
        // group expansion, which are recipient sets whatever page size they use (core passes
        // max_group_members or 999 for mailto). Any other page size above the bound is a page.
        $fullSet = $this->list_page === 1 && (
            in_array($this->requestedSize, self::CORE_FULL_SET_SIZES, true)
            || ($this->filter !== null && $this->requestedSize > $this->limits->corePageCap)
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
            return $this->selection($subset);
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

        // a page of a list: never more than the page bound, never beyond the bound of the list total
        if ($this->requestedSize > $this->limits->corePageCap) {
            $this->notice('pagetoolarge', ['max' => (string) $this->limits->corePageCap]);
        }
        [$cap, $capLabel] = $this->listCap();
        if ($first >= $cap) {
            $this->notice($capLabel, ['max' => (string) $cap]);
            return new \rcube_result_set(0, $first);
        }
        $records = $this->page($first, min($this->page_size, $cap - $first));
        if ($nocount) {
            return $this->resultSet($records, $first + count($records), $first, $subset);
        }
        [$total, $capped] = $this->boundedTotal();
        if ($capped) {
            $this->notice($capLabel, ['max' => (string) $cap]);
        }
        return $this->resultSet($records, $total, $first, $subset);
    }

    /**
     * The bound of the list a page belongs to, and the notice that tells a user about it: the
     * result cap of the book for a search (toomanyresults), galbridge_max_count otherwise
     * (listcapped). A search result is never longer than the bound of a list total either.
     *
     * @return array{0: int, 1: string}
     */
    private function listCap(): array
    {
        if ($this->filter !== null) {
            return [min($this->book->maxResults, $this->limits->maxCount), 'toomanyresults'];
        }
        return [$this->limits->maxCount, 'listcapped'];
    }

    /**
     * A set selected by IDs (compose to selected, export of selected, a contact by its ID): ONE
     * statement for at most galbridge_max_ids rows (search() refused more IDs). In the recipient and
     * export actions the selection is complete or refused: an ID that could not be decoded, that
     * finds no entry this user may see (gone, foreign, forged) or whose row the re-check dropped
     * refuses it (set_incomplete missing=N dropped=N) - a recipient set or a file never lacks a
     * selected entry silently. Other actions show what was found.
     */
    private function selection(int $subset): \rcube_result_set
    {
        $requested = $this->filter !== null ? count($this->filter->ids) : 0;
        $set = $this->fullSet($this->limits->maxIds);
        if ($set === null) {
            // more rows than IDs: cannot happen (one row per ID) - never a silently cut set
            return $this->refuse('toomanyids', ['max' => (string) $this->limits->maxIds], 'too_many_ids');
        }
        [$records, $dropped] = $set;
        $missing = $this->rejectedIds + $requested - count($records);
        if (in_array($this->action, self::SELECTION_ACTIONS, true) && ($dropped > 0 || $missing > 0)) {
            return $this->refuse('incomplete', [], 'set_incomplete', ['missing' => $missing, 'dropped' => $dropped]);
        }
        return $this->resultSet($records, count($records), 0, $subset);
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
     * List total for display (count()), bounded like the list (listCap()) and silent; never used to
     * decide whether a recipient set or an export is complete.
     */
    private function total(): int
    {
        return $this->boundedTotal()[0];
    }

    /**
     * The list total bounded by listCap(), and whether the bound cut it (counted up to the bound
     * plus one; a group without a filter: the exact count of the group aggregate).
     *
     * @return array{0: int, 1: bool}
     */
    private function boundedTotal(): array
    {
        if ($this->groupKey === false || ($this->filter !== null && !$this->queries->canMatch($this->filter))) {
            return [0, false];
        }
        if ($this->groupKey !== null && $this->selectedGroup() === null) {
            return [0, false];
        }
        if ($this->filter === null && $this->groupKey === null && $this->isSearchOnly()) {
            return [0, false];
        }
        [$cap] = $this->listCap();
        $n = $this->filter === null && $this->groupKey !== null
            ? $this->groupCount()
            : $this->repository->count($this->filter, $this->groupKey, $cap + 1);
        return [min($n, $cap), $n > $cap];
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
     * One contact by its ID (get_record(), get_record_groups()). Each lookup that would read the
     * directory takes one unit of the request's LookupBudget (galbridge_max_ids): core's copy action
     * reads every contact of the request this way, and beyond the budget the rest is refused with
     * the message of too many IDs. An ID that cannot be decoded costs nothing.
     *
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
        if (!$this->budget->take()) {
            $max = (string) $this->limits->maxIds;
            Log::once('warning', 'limits', 'too_many_ids', 'request refused: more contacts read one by one than galbridge_max_ids', ['book' => $this->book->id, 'max' => $max]);
            $this->set_error(self::ERROR_SEARCH, 'galbridge.toomanyids');
            if ($this->budget->claimNotice()) {
                $this->notice('toomanyids', ['max' => $max]);
            }
            return null;
        }
        $spec = new SearchSpec(SearchSpec::IDS, $this->book->id, $this->fingerprint, ids: [$decoded]);
        $records = $this->repository->page($spec, null, 'name', 'ASC', 0, 1);
        return $records[0] ?? null;
    }

    private function buildSpec(mixed $fields, mixed $value, int $mode, mixed $required): SearchSpec
    {
        $this->rejectedIds = 0;
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
            // a selection with an ID that cannot be decoded is incomplete (selection())
            $this->rejectedIds = $rejected;
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
