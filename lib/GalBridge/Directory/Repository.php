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
 */
final class Repository
{
    /** @var null|list<array{id: string, kind: string, key: string, label: string, count: int}> */
    private ?array $groups = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryBuilder $queries,
        private readonly RecordMapper $mapper,
        private readonly FieldMap $fields,
        private readonly string $grouping,
        private readonly int $maxGroups,
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
        [$sql, $params] = $this->queries->pageQuery($spec, $group, $sortCol, $order);
        $rows = $this->connection->select($sql, $params, $offset, $limit);
        return $this->records($rows);
    }

    /**
     * @param null|false|array{kind: string, key: string} $group
     *
     * @throws GalBridgeException
     */
    public function count(?SearchSpec $spec, null|false|array $group, int $bound): int
    {
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
        $groups = [];
        foreach ($rows as $row) {
            // the department key is used exactly as SQL computed it (LOWER(TRIM(department))), so the
            // group predicate matches on every engine and collation; PHP never re-lowercases it
            $key = $kind === IdCodec::GROUP_DOMAIN ? Normalizer::domain($row['gkey'] ?? null) : (string) ($row['gkey'] ?? '');
            $label = Normalizer::displayText($row['label'] ?? '', 64);
            if ($key === null || $key === '' || $label === '' || strlen($key) > IdCodec::MAX_KEY_BYTES) {
                continue;
            }
            if ($kind === IdCodec::GROUP_DOMAIN && !$this->queries->book()->inScope($key)) {
                continue;
            }
            $groups[] = [
                'id' => IdCodec::groupId($this->queries->book()->tag, $kind, $key),
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
     * Map (kind, address) rows to full records, preserving their order.
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
        if (!empty($byKind[QueryBuilder::KIND_PERSON])) {
            $addresses = $byKind[QueryBuilder::KIND_PERSON];
            $aliases = [];
            if ($this->fields->userAliases()) {
                [$sql, $params] = $this->queries->userAliasesQuery($addresses);
                foreach ($this->connection->select($sql, $params) as $r) {
                    $aliases[(string) $r['forwarding']][] = (string) $r['address'];
                }
            }
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
            if (!empty($byKind[$kind])) {
                [$sql, $params] = $this->queries->listDetailsQuery($kind, $byKind[$kind]);
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
