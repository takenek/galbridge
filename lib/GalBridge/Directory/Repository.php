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

use GalBridge\Db\Connection;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * Executes QueryBuilder statements and maps the rows. Keeps a per-request memo of the book's groups
 * (one aggregate query answers list_groups, get_group and the group counts core asks for).
 *
 * Before its first statement it asks the guard whether the session may still read the directory
 * (ViewerResolver::confirm()): a cached entitlement is confirmed by every request that reads
 * directory data, but never by a request that only instantiates the book.
 */
final class Repository
{
    /** per-user alias addresses shown for one person ("other" e-mail addresses) */
    public const ALIASES_PER_PERSON = 20;

    /**
     * Addresses per details / alias statement: a full set (an export, a group expansion) of up to
     * galbridge_max_export or galbridge_max_group_members entries is read in statements of this many
     * addresses, so no statement carries an unbounded list of parameters - rcube_db inlines them one
     * after the other, at a cost that grows with the square of their number.
     */
    public const DETAIL_BATCH = 500;

    /** @var null|list<array{id: string, kind: string, key: string, label: string, count: int}> */
    private ?array $groups = null;

    private bool $confirmed = false;

    /**
     * @param null|\Closure(): bool $guard may this session (still) read the directory?
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly QueryBuilder $queries,
        private readonly RecordMapper $mapper,
        private readonly FieldMap $fields,
        private readonly string $grouping,
        private readonly int $maxGroups,
        private readonly ?\Closure $guard = null,
    ) {
    }

    public function grouping(): string
    {
        return $this->grouping;
    }

    /**
     * One page of records in the book's sort order.
     *
     * @param null|false|array{kind: string, key: string} $group
     *
     * @return list<array<string, mixed>>
     *
     * @throws GalBridgeException
     */
    public function page(?SearchSpec $spec, null|false|array $group, string $sortCol, string $order, int $offset, int $limit): array
    {
        $this->guard();
        [$sql, $params] = $this->queries->pageQuery($spec, $group, $sortCol, $order);
        $rows = $this->connection->select($sql, $params, $offset, $limit);
        return $this->records($rows);
    }

    /**
     * A COMPLETE set of at most $cap records (a recipient set, an export), or null when the set is
     * larger. ONE statement fetches up to $cap + 1 rows, so the answer depends neither on a bounded
     * count nor on a count taken by an earlier statement (rows added in between cannot be cut off).
     *
     * @param null|false|array{kind: string, key: string} $group
     *
     * @return null|array{0: list<array<string, mixed>>, 1: int} the records, and how many fetched rows
     *                                                          could not be delivered (dropped by the
     *                                                          row re-check or gone before their
     *                                                          details were read): 0 = complete
     *
     * @throws GalBridgeException
     */
    public function fullSet(?SearchSpec $spec, null|false|array $group, string $sortCol, string $order, int $cap): ?array
    {
        $this->guard();
        [$sql, $params] = $this->queries->pageQuery($spec, $group, $sortCol, $order);
        $rows = $this->connection->select($sql, $params, 0, $cap + 1);
        if (count($rows) > $cap) {
            return null;
        }
        $records = $this->records($rows);
        return [$records, count($rows) - count($records)];
    }

    /**
     * @param null|false|array{kind: string, key: string} $group
     *
     * @throws GalBridgeException
     */
    public function count(?SearchSpec $spec, null|false|array $group, int $bound): int
    {
        $this->guard();
        [$sql, $params] = $this->queries->countQuery($spec, $group, $bound);
        $rows = $this->connection->select($sql, $params);
        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * Groups with member counts (persons only), memoised for the request. Empty when the book has no
     * grouping or more than galbridge_max_groups groups.
     *
     * @return list<array{id: string, kind: string, key: string, label: string, count: int}>
     *
     * @throws GalBridgeException
     */
    public function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }
        $this->guard();
        $query = $this->queries->groupsQuery($this->grouping);
        if ($query === null) {
            return $this->groups = [];
        }
        $kind = $this->grouping === 'domain' ? IdCodec::GROUP_DOMAIN : IdCodec::GROUP_DEPARTMENT;
        $rows = $this->connection->select($query[0], $query[1], 0, $this->maxGroups + 1);
        if (count($rows) > $this->maxGroups) {
            Log::once('warning', 'groups', 'too_many_groups', 'more groups than galbridge_max_groups: groups disabled for this book', ['book' => $this->queries->book()->id]);
            return $this->groups = [];
        }
        $book = $this->queries->book();
        // the global book groups departments per domain (QueryBuilder::groupsQuery()): the key is
        // "domain:department", the label carries the domain first
        $perDomain = $kind === IdCodec::GROUP_DEPARTMENT && $book->id === BookDefinition::GLOBAL;
        $groups = [];
        foreach ($rows as $row) {
            // the department key is used exactly as SQL computed it (LOWER(TRIM(department))), so the
            // group predicate matches on every engine and collation; PHP never re-lowercases it
            $key = $kind === IdCodec::GROUP_DOMAIN ? Normalizer::domain($row['gkey'] ?? null) : (string) ($row['gkey'] ?? '');
            // a label is display text of the directory like a department value of a record
            $label = Normalizer::cutAtSign(Normalizer::displayText($row['label'] ?? '', 64));
            if ($perDomain && $key !== null && $key !== '' && $label !== '') {
                $domain = Normalizer::domain($row['gdomain'] ?? null);
                if ($domain === null || !$book->inScope($domain)) {
                    continue;
                }
                $key = $domain . ':' . $key;
                $label = '(' . $domain . ') ' . $label;
            }
            if ($key === null || $key === '' || $label === '' || strlen($key) > IdCodec::MAX_KEY_BYTES) {
                if ($key !== null && strlen($key) > IdCodec::MAX_KEY_BYTES) {
                    Log::debug('groups', 'a department group key is too long to be offered', ['book' => $book->id]);
                }
                continue;
            }
            if (!IdCodec::usableGroupKey($key)) {
                // its key would be bound into the member statement (see IdCodec::usableGroupKey());
                // the members stay in the book, they are only not offered as this group
                Log::once('warning', 'groups', 'group_key_unusable', 'a department with a control character or a backslash is not offered as a group', ['book' => $book->id]);
                continue;
            }
            if ($kind === IdCodec::GROUP_DOMAIN && !$book->inScope($key)) {
                continue;
            }
            $groups[] = [
                'id' => IdCodec::groupId($book->tag, $kind, $key),
                'kind' => $kind,
                'key' => $key,
                'label' => $label,
                'count' => (int) ($row['n'] ?? 0),
            ];
        }
        return $this->groups = $groups;
    }

    /**
     * @return null|array{id: string, kind: string, key: string, label: string, count: int}
     *
     * @throws GalBridgeException
     */
    public function group(string $id): ?array
    {
        foreach ($this->groups() as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        return null;
    }

    /**
     * @throws GalBridgeException 'no_mailbox' when the session may no longer read the directory
     */
    private function guard(): void
    {
        if ($this->confirmed || $this->guard === null) {
            return;
        }
        if (!($this->guard)()) {
            throw new GalBridgeException('no_mailbox', 'the mailbox of this session is no longer usable');
        }
        $this->confirmed = true;
    }

    /**
     * The per-user aliases shown for the given persons (at most DETAIL_BATCH): at most
     * ALIASES_PER_PERSON per person, the first ones in address order, read by ONE statement whose
     * bound is per owner (QueryBuilder::userAliasesQuery(): ROW_NUMBER() per owner), so a person
     * with thousands of aliases can take nobody else's aliases away. The statement returns one row
     * more than is shown for an owner who has more; that row only tells that the list is cut.
     *
     * @param list<string> $owners mailbox addresses
     *
     * @return array<string, list<string>> owner => alias addresses
     *
     * @throws GalBridgeException
     */
    private function userAliases(array $owners): array
    {
        $book = $this->queries->book();
        $owners = array_values(array_unique($owners));
        if ($owners === []) {
            return [];
        }
        [$sql, $params] = $this->queries->userAliasesQuery($owners, self::ALIASES_PER_PERSON + 1);
        $rows = $this->connection->select($sql, $params, 0, (self::ALIASES_PER_PERSON + 1) * count($owners));
        $aliases = [];
        $seen = [];
        $capped = false;
        foreach ($rows as $r) {
            $owner = (string) $r['forwarding'];
            $seen[$owner] = ($seen[$owner] ?? 0) + 1;
            if ($seen[$owner] > self::ALIASES_PER_PERSON) {
                $capped = true;
                continue;
            }
            // re-check of the row's domain against the book (defence in depth, as for records)
            $domain = Normalizer::domain($r['domain'] ?? null);
            if ($domain === null || !$book->inScope($domain)) {
                Log::once('error', 'scope', 'row_out_of_scope', 'row outside the book scope or on a hidden list dropped (please report)', ['book' => $book->id, 'row' => Log::tag((string) $r['address'])]);
                continue;
            }
            $aliases[$owner][] = (string) $r['address'];
        }
        if ($capped) {
            Log::once('warning', 'records', 'aliases_capped', 'more per-user aliases than shown', ['book' => $book->id, 'max' => self::ALIASES_PER_PERSON]);
        }
        return $aliases;
    }

    /**
     * Map (kind, address) rows to full records, preserving their order. The details (and the
     * per-user aliases) are read in statements of at most DETAIL_BATCH addresses each.
     *
     * @param list<array<string, ?string>> $rows
     *
     * @return list<array<string, mixed>>
     *
     * @throws GalBridgeException
     */
    private function records(array $rows): array
    {
        $byKind = [];
        foreach ($rows as $row) {
            $kind = (string) ($row['kind'] ?? '');
            $address = (string) ($row['address'] ?? '');
            if ($address !== '') {
                $byKind[$kind][] = $address;
            }
        }

        $details = [];
        foreach (array_chunk($byKind[QueryBuilder::KIND_PERSON] ?? [], self::DETAIL_BATCH) as $addresses) {
            $aliases = $this->fields->userAliases() ? $this->userAliases($addresses) : [];
            [$sql, $params] = $this->queries->personDetailsQuery($addresses);
            foreach ($this->connection->select($sql, $params) as $r) {
                $u = (string) $r['username'];
                $rec = $this->mapper->person($r, $aliases[$u] ?? []);
                if ($rec !== null) {
                    $details[QueryBuilder::KIND_PERSON . ':' . $u] = $rec;
                }
            }
        }
        foreach ([QueryBuilder::KIND_ALIAS, QueryBuilder::KIND_LIST] as $kind) {
            foreach (array_chunk($byKind[$kind] ?? [], self::DETAIL_BATCH) as $addresses) {
                [$sql, $params] = $this->queries->listDetailsQuery($kind, $addresses);
                foreach ($this->connection->select($sql, $params) as $r) {
                    $rec = $this->mapper->mailList($kind, $r);
                    if ($rec !== null) {
                        $details[$kind . ':' . (string) $r['address']] = $rec;
                    }
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['kind'] ?? '') . ':' . (string) ($row['address'] ?? '');
            if (isset($details[$key])) {
                $out[] = $details[$key];
            }
        }
        return $out;
    }
}
