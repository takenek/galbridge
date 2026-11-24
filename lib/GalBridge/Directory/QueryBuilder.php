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
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * The ONLY producer of directory SQL.
 *
 * Every statement's WHERE clause is And(scope, visibility, hidden, filter), rendered fully
 * parenthesised (Sql). There is no API to build a query without the scope. Rows from
 * forwardings / moderators / maillist_owners are only ever used in semi-joins (EXISTS / IN), so
 * result rows are unique by primary key and counts and paging are exact.
 *
 * Search input is turned into predicates here (never into SQL text): words are normalised,
 * LIKE-escaped and bound.
 */
final class QueryBuilder
{
    public const KIND_PERSON = IdCodec::PERSON;
    public const KIND_ALIAS = IdCodec::ALIAS;
    public const KIND_LIST = IdCodec::MAILLIST;

    private const MAX_WORDS = 5;

    /**
     * @param list<string> $hiddenAddresses
     * @param list<string> $hiddenLocalparts
     */
    public function __construct(
        private readonly string $driver,
        private readonly BookDefinition $book,
        private readonly Viewer $viewer,
        private readonly FieldMap $fields,
        private readonly bool $hideExpired,
        private readonly string $now,
        private readonly array $hiddenAddresses,
        private readonly array $hiddenLocalparts,
        private readonly string $visibilityColumn,
        private readonly bool $includeAliases,
        private readonly bool $includeLists,
        private readonly string $listPolicy,
        private readonly int $minLength,
        private readonly int $maxLength,
    ) {
    }

    /**
     * The one way to build the query builder of a book from the configuration. Malformed deny-lists
     * or visibility column are refused (they must disable the books, never mean "no restriction").
     *
     * @throws GalBridgeException 'config_invalid'
     */
    public static function forBook(Config $config, string $driver, BookDefinition $book, Viewer $viewer, FieldMap $fields): self
    {
        $hidden = $config->hiddenAddresses();
        $localparts = $config->hiddenLocalparts();
        $visibility = $config->visibilityColumn();
        if ($hidden === null || $localparts === null || $visibility === null) {
            throw new GalBridgeException('config_invalid', 'malformed deny-list or visibility column');
        }
        return new self(
            driver: $driver,
            book: $book,
            viewer: $viewer,
            fields: $fields,
            hideExpired: $config->bool('galbridge_hide_expired'),
            now: gmdate('Y-m-d H:i:s'),
            hiddenAddresses: $hidden,
            hiddenLocalparts: $localparts,
            visibilityColumn: $visibility,
            includeAliases: $config->bool('galbridge_include_aliases'),
            includeLists: $config->bool('galbridge_include_mailing_lists'),
            listPolicy: $config->choice('galbridge_list_policy', Config::LIST_POLICIES),
            minLength: $config->int('galbridge_min_search_length', 1, 10),
            maxLength: $config->int('galbridge_max_search_length', 16, 255),
        );
    }

    public function book(): BookDefinition
    {
        return $this->book;
    }

    /**
     * @return list<string>
     */
    public function hiddenAddresses(): array
    {
        return $this->hiddenAddresses;
    }

    /**
     * @return list<string>
     */
    public function hiddenLocalparts(): array
    {
        return $this->hiddenLocalparts;
    }

    /**
     * Entity kinds this book contains (persons always; aliases/lists when enabled and readable).
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = [self::KIND_PERSON];
        if ($this->includeAliases && $this->hasAll('forwardings', ['is_list']) && $this->hasAll('alias', ['address', 'name', 'accesspolicy', 'domain', 'active'])) {
            $kinds[] = self::KIND_ALIAS;
        }
        if ($this->includeLists && $this->hasAll('forwardings', ['is_maillist']) && $this->hasAll('maillists', ['address', 'name', 'accesspolicy', 'domain', 'active'])) {
            $kinds[] = self::KIND_LIST;
        }
        return $kinds;
    }

    // ------------------------------------------------------------------ root predicates

    public function root(string $kind): Sql
    {
        return match ($kind) {
            self::KIND_PERSON => $this->personRoot(),
            self::KIND_ALIAS => $this->aliasRoot(),
            self::KIND_LIST => $this->listRoot(),
            default => Sql::false(),
        };
    }

    public function from(string $kind): string
    {
        return match ($kind) {
            self::KIND_PERSON => '`mailbox` m JOIN `domain` d ON d.`domain` = m.`domain`',
            self::KIND_ALIAS => '`alias` a JOIN `domain` d ON d.`domain` = a.`domain`',
            default => '`maillists` ml JOIN `domain` d ON d.`domain` = ml.`domain`',
        };
    }

    private function personRoot(): Sql
    {
        $parts = [
            $this->scope('m'),
            Sql::raw('m.active = 1 AND m.enabledeliver = 1 AND d.active = 1 AND d.backupmx = 0'),
        ];
        if ($this->hideExpired) {
            $parts[] = Sql::raw('m.expired > ? AND d.expired > ?', [$this->now, $this->now]);
        }
        if ($this->visibilityColumn !== '') {
            // configured but not readable: fail closed
            $parts[] = $this->viewer->hasColumn('mailbox', $this->visibilityColumn)
                ? Sql::raw('m.`' . $this->visibilityColumn . '` = 1')
                : Sql::false();
        }
        $parts[] = $this->hidden('m.username');
        return Sql::and(...$parts);
    }

    private function aliasRoot(): Sql
    {
        $parts = [
            $this->scope('a'),
            Sql::raw('a.active = 1 AND d.active = 1 AND d.backupmx = 0'),
        ];
        if ($this->hideExpired) {
            $parts[] = $this->viewer->hasColumn('alias', 'expired')
                ? Sql::raw('a.expired > ? AND d.expired > ?', [$this->now, $this->now])
                : Sql::raw('d.expired > ?', [$this->now]);
        }
        $parts[] = Sql::exists('SELECT 1 FROM `forwardings` f WHERE f.address = a.address AND f.is_list = 1 AND f.active = 1');
        $parts[] = (new AccessPolicy($this->listPolicy, $this->viewer))->alias();
        $parts[] = $this->hidden('a.address');
        return Sql::and(...$parts);
    }

    private function listRoot(): Sql
    {
        $parts = [
            $this->scope('ml'),
            Sql::raw('ml.active = 1 AND d.active = 1 AND d.backupmx = 0'),
        ];
        if ($this->hideExpired) {
            $parts[] = $this->viewer->hasColumn('maillists', 'expired')
                ? Sql::raw('ml.expired > ? AND d.expired > ?', [$this->now, $this->now])
                : Sql::raw('d.expired > ?', [$this->now]);
        }
        $parts[] = Sql::exists('SELECT 1 FROM `forwardings` f WHERE f.address = ml.address AND f.is_maillist = 1 AND f.active = 1');
        $parts[] = (new AccessPolicy($this->listPolicy, $this->viewer))->mailingList();
        $parts[] = $this->hidden('ml.address');
        return Sql::and(...$parts);
    }

    private function scope(string $t): Sql
    {
        return $this->book->allDomains
            ? Sql::notIn($t . '.`domain`', $this->book->excludeDomains)
            : Sql::in($t . '.`domain`', $this->book->domains);
    }

    private function hidden(string $addressColumn): Sql
    {
        $parts = [Sql::notIn($addressColumn, $this->hiddenAddresses)];
        foreach ($this->hiddenLocalparts as $lp) {
            $parts[] = Sql::not(Sql::like($this->driver, $addressColumn, Sql::escapeLike($lp) . '@%'));
        }
        return Sql::and(...$parts);
    }

    // ------------------------------------------------------------------ filters

    /**
     * Predicate of a search spec for one entity kind. A spec that can never match returns FALSE.
     */
    public function filter(?SearchSpec $spec, string $kind): Sql
    {
        if ($spec === null) {
            return Sql::true();
        }
        $required = $this->required($spec->required, $kind);
        return match ($spec->type) {
            SearchSpec::IDS => Sql::and($this->ids($spec->ids, $kind), $required),
            SearchSpec::QUICK => Sql::and($this->quick($spec->fields, $spec->values[0] ?? '', $spec->mode, $kind), $required),
            SearchSpec::ADVANCED => Sql::and($this->advanced($spec->fields, $spec->values, $spec->mode, $kind), $required),
            default => Sql::false(),
        };
    }

    /**
     * Can this spec match anything at all (decided without SQL)? Used to skip queries.
     */
    public function canMatch(SearchSpec $spec): bool
    {
        if ($spec->type === SearchSpec::NONE) {
            return false;
        }
        foreach ($spec->required as $f) {
            if ($this->fields->requiredColumn($f) === null) {
                return false;
            }
        }
        return match ($spec->type) {
            SearchSpec::IDS => $spec->ids !== [],
            SearchSpec::QUICK => $this->quickFields($spec->fields) !== [] && $this->words($spec->values[0] ?? '', $spec->mode) !== null,
            SearchSpec::ADVANCED => $this->advancedValid($spec->fields, $spec->values, $spec->mode),
            default => false,
        };
    }

    /**
     * @param list<array{kind: string, address: string}> $ids
     */
    private function ids(array $ids, string $kind): Sql
    {
        $addresses = [];
        foreach ($ids as $id) {
            if ($id['kind'] === $kind) {
                $addresses[] = $id['address'];
            }
        }
        return Sql::in($this->addressColumn($kind), $addresses);
    }

    /**
     * @param list<string> $required
     */
    private function required(array $required, string $kind): Sql
    {
        $parts = [];
        foreach ($required as $f) {
            $col = $this->fields->requiredColumn($f);
            if ($col === null) {
                return Sql::false();
            }
            if ($col === '') {
                continue;
            }
            $parts[] = $kind === self::KIND_PERSON ? Sql::raw("m.{$col} <> ''") : Sql::false();
        }
        return Sql::and(...$parts);
    }

    /**
     * @param list<string> $fields
     */
    private function quick(array $fields, string $value, int $mode, string $kind): Sql
    {
        $fields = $this->quickFields($fields);
        $words = $this->words($value, $mode);
        if ($fields === [] || $words === null) {
            return Sql::false();
        }
        $perWord = [];
        foreach ($words as [$word, $wordMode]) {
            $any = [];
            foreach ($fields as $field) {
                $any[] = $this->fieldMatch($field, $word, $wordMode, $kind);
            }
            $perWord[] = Sql::or(...$any);
        }
        return Sql::and(...$perWord);
    }

    /**
     * @param list<string> $fields
     * @param list<string> $values
     */
    private function advanced(array $fields, array $values, int $mode, string $kind): Sql
    {
        if (!$this->advancedValid($fields, $values, $mode)) {
            return Sql::false();
        }
        $parts = [];
        foreach ($fields as $i => $field) {
            $words = $this->words((string) ($values[$i] ?? ''), $mode);
            if ($words === null) {
                continue; // empty value: skipped (advancedValid ensures at least one is set)
            }
            foreach ($words as [$word, $wordMode]) {
                $parts[] = $this->fieldMatch($field, $word, $wordMode, $kind);
            }
        }
        return Sql::and(...$parts);
    }

    /**
     * @param list<string> $fields
     * @param list<string> $values
     */
    private function advancedValid(array $fields, array $values, int $mode): bool
    {
        if ($fields === [] || count($fields) !== count($values)) {
            return false;
        }
        $any = false;
        foreach ($fields as $i => $field) {
            if (!$this->fields->isSearchable($field)) {
                return false; // an unsupported or nosearch field matches nothing
            }
            if (trim((string) $values[$i]) !== '') {
                if ($this->words((string) $values[$i], $mode) === null) {
                    return false;
                }
                $any = true;
            }
        }
        return $any;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private function quickFields(array $fields): array
    {
        if ($fields === ['*'] || in_array('*', $fields, true)) {
            return $this->fields->searchableFields();
        }
        return array_values(array_filter(array_unique($fields), fn (string $f): bool => $this->fields->isSearchable($f)));
    }

    /**
     * Normalised search words with their effective match mode, or null when the input must not be
     * searched (empty, too long, too short).
     *
     * @return null|list<array{0: string, 1: int}>
     */
    public function words(string $value, int $mode): ?array
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Normalizer::nfc($value))));
        if ($value === '' || mb_strlen($value) > $this->maxLength || !preg_match('//u', $value)) {
            return null;
        }
        $mode &= 3;
        if ($this->book->prefixOnly && $mode !== \rcube_addressbook::SEARCH_STRICT) {
            $mode = \rcube_addressbook::SEARCH_PREFIX;
        }
        $raw = $mode === \rcube_addressbook::SEARCH_STRICT ? [$value] : explode(' ', $value);
        $raw = array_slice(array_values(array_filter($raw, static fn (string $w): bool => $w !== '')), 0, self::MAX_WORDS);

        $long = 0;
        foreach ($raw as $w) {
            if (mb_strlen($w) >= $this->minLength) {
                $long++;
            }
        }
        if ($long === 0 || ($this->book->everyWordMinLength && $long !== count($raw))) {
            return null;
        }
        $out = [];
        foreach ($raw as $w) {
            // a short word only narrows as a word prefix ("Jan K"), it never scans as a substring
            $wordMode = ($mode === 0 && mb_strlen($w) < $this->minLength) ? \rcube_addressbook::SEARCH_PREFIX : $mode;
            $out[] = [$w, $wordMode];
        }
        return $out;
    }

    private function fieldMatch(string $field, string $word, int $mode, string $kind): Sql
    {
        if ($field === 'organization') {
            return $this->organizationMatch($word, $mode, $kind);
        }
        if ($field === 'email') {
            return $this->emailMatch($word, $mode, $kind);
        }
        if ($kind === self::KIND_PERSON) {
            $cols = $this->fields->personSearchColumns($field);
        } else {
            $t = $kind === self::KIND_ALIAS ? 'a' : 'ml';
            $cols = $field === 'name' ? [$t . '.name'] : [];
        }
        $any = [];
        foreach ($cols as $col) {
            $any[] = $this->textMatch($col, $word, $mode);
        }
        return Sql::or(...$any);
    }

    private function textMatch(string $col, string $word, int $mode): Sql
    {
        $w = Sql::escapeLike($word);
        return match ($mode) {
            \rcube_addressbook::SEARCH_STRICT => Sql::like($this->driver, $col, $w),
            \rcube_addressbook::SEARCH_PREFIX => Sql::or(Sql::like($this->driver, $col, $w . '%'), Sql::like($this->driver, $col, '% ' . $w . '%')),
            default => Sql::like($this->driver, $col, '%' . $w . '%'),
        };
    }

    private function emailMatch(string $word, int $mode, string $kind): Sql
    {
        $col = $this->addressColumn($kind);
        $w = Sql::escapeLike($word);
        $pattern = match ($mode) {
            \rcube_addressbook::SEARCH_STRICT => $w,
            \rcube_addressbook::SEARCH_PREFIX => $w . '%',          // the local part, never a domain fragment
            default => '%' . $w . '%',
        };
        $parts = [Sql::like($this->driver, $col, $pattern)];
        if ($kind === self::KIND_PERSON && $this->fields->userAliases()) {
            $parts[] = Sql::raw(
                'm.username IN (SELECT f.forwarding FROM `forwardings` f WHERE f.is_alias = 1 AND f.active = 1 AND f.address ' . ($this->driver === 'pgsql' ? 'ILIKE' : 'LIKE') . " ? ESCAPE '!')",
                [$pattern],
            );
        }
        return Sql::or(...$parts);
    }

    private function organizationMatch(string $word, int $mode, string $kind): Sql
    {
        $domains = [];
        // the global book never lists a whole organization from a prefix of its name (as for domain
        // fragments of addresses): only the complete label matches there
        if ($this->book->prefixOnly) {
            $mode = \rcube_addressbook::SEARCH_STRICT;
        }
        foreach ($this->book->orgLabels as $domain => $label) {
            $l = mb_strtolower($label);
            $hit = match ($mode) {
                \rcube_addressbook::SEARCH_STRICT => $l === $word,
                \rcube_addressbook::SEARCH_PREFIX => str_starts_with($l, $word) || str_contains($l, ' ' . $word),
                default => str_contains($l, $word),
            };
            if ($hit) {
                $domains[] = $domain;
            }
        }
        $t = match ($kind) {
            self::KIND_PERSON => 'm',
            self::KIND_ALIAS => 'a',
            default => 'ml',
        };
        return Sql::in($t . '.`domain`', $domains);
    }

    /**
     * Group predicate (members are persons only). $group = null: no group; false: match nothing.
     *
     * @param null|false|array{kind: string, key: string} $group
     */
    public function group(null|false|array $group, string $kind): Sql
    {
        if ($group === null) {
            return Sql::true();
        }
        if ($group === false || $kind !== self::KIND_PERSON) {
            return Sql::false();
        }
        if ($group['kind'] === IdCodec::GROUP_DOMAIN) {
            return $this->book->inScope($group['key']) ? Sql::eq('m.`domain`', $group['key']) : Sql::false();
        }
        return $this->fields->has('department') ? Sql::eq('LOWER(TRIM(m.department))', $group['key']) : Sql::false();
    }

    // ------------------------------------------------------------------ statements

    /**
     * Paged list: one row per record (kind, address, sortkey), stable order.
     *
     * @param null|false|array{kind: string, key: string} $group
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function pageQuery(?SearchSpec $spec, null|false|array $group, string $sortCol, string $order): array
    {
        $selects = [];
        $params = [];
        foreach ($this->kinds() as $kind) {
            $where = Sql::and($this->root($kind), $this->group($group, $kind), $this->filter($spec, $kind));
            $sortkey = $kind === self::KIND_PERSON ? $this->fields->personSortKey($sortCol) : FieldMap::listSortKey($kind === self::KIND_ALIAS ? 'a' : 'ml');
            $selects[] = "SELECT '{$kind}' AS kind, " . $this->addressColumn($kind) . ' AS address, ' . $sortkey . ' AS sortkey FROM ' . $this->from($kind) . ' WHERE ' . $where->sql;
            array_push($params, ...$where->params);
        }
        $dir = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        return [implode(' UNION ALL ', $selects) . " ORDER BY sortkey {$dir}, address {$dir}, kind", $params];
    }

    /**
     * COUNT over the same predicates, bounded (the inner LIMIT caps the work).
     *
     * @param null|false|array{kind: string, key: string} $group
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function countQuery(?SearchSpec $spec, null|false|array $group, int $bound): array
    {
        $selects = [];
        $params = [];
        foreach ($this->kinds() as $kind) {
            $where = Sql::and($this->root($kind), $this->group($group, $kind), $this->filter($spec, $kind));
            $selects[] = 'SELECT ' . $this->addressColumn($kind) . ' AS address FROM ' . $this->from($kind) . ' WHERE ' . $where->sql;
            array_push($params, ...$where->params);
        }
        return ['SELECT COUNT(*) AS n FROM (' . implode(' UNION ALL ', $selects) . ' LIMIT ' . max(1, $bound) . ') x', $params];
    }

    /**
     * COUNT of one entity kind of the book (no filter, no group), bounded - for diag --user.
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function kindCountQuery(string $kind, int $bound): array
    {
        $where = $this->root($kind);
        return ['SELECT COUNT(*) AS n FROM (SELECT ' . $this->addressColumn($kind) . ' AS address FROM ' . $this->from($kind)
            . ' WHERE ' . $where->sql . ' LIMIT ' . max(1, $bound) . ') x', $where->params];
    }

    /**
     * Full person rows for the given addresses (re-applying the root predicate).
     *
     * @param list<string> $addresses
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function personDetailsQuery(array $addresses): array
    {
        $cols = array_map(static fn (string $c): string => $c === 'domain' ? 'm.`domain`' : 'm.' . $c, $this->fields->personColumns());
        $where = Sql::and($this->personRoot(), Sql::in('m.username', $addresses));
        return ['SELECT ' . implode(', ', $cols) . ' FROM ' . $this->from(self::KIND_PERSON) . ' WHERE ' . $where->sql, $where->params];
    }

    /**
     * Alias account or mailing list rows for the given addresses (re-applying the root predicate).
     *
     * @param list<string> $addresses
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function listDetailsQuery(string $kind, array $addresses): array
    {
        $t = $kind === self::KIND_ALIAS ? 'a' : 'ml';
        $where = Sql::and($this->root($kind), Sql::in($t . '.address', $addresses));
        return ["SELECT {$t}.address, {$t}.name, {$t}.`domain` FROM " . $this->from($kind) . ' WHERE ' . $where->sql, $where->params];
    }

    /**
     * Per-user alias addresses of the given (already visible) persons.
     *
     * @param list<string> $usernames
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function userAliasesQuery(array $usernames): array
    {
        $where = Sql::and(Sql::raw('f.is_alias = 1 AND f.active = 1 AND fd.active = 1 AND fd.backupmx = 0'), Sql::in('f.forwarding', $usernames));
        return ['SELECT f.forwarding, f.address FROM `forwardings` f JOIN `domain` fd ON fd.`domain` = f.`domain` WHERE ' . $where->sql . ' ORDER BY f.address', $where->params];
    }

    /**
     * Groups of this book with member counts (persons only), one aggregate query.
     *
     * @return null|array{0: string, 1: list<scalar|null>} null when the book has no groups
     */
    public function groupsQuery(string $grouping): ?array
    {
        $root = $this->personRoot();
        if ($grouping === 'department' && $this->fields->has('department')) {
            $sql = 'SELECT LOWER(TRIM(m.department)) AS gkey, MIN(TRIM(m.department)) AS label, COUNT(m.username) AS n FROM '
                . $this->from(self::KIND_PERSON) . ' WHERE ' . $root->sql . " AND TRIM(m.department) <> ''"
                . ' GROUP BY LOWER(TRIM(m.department)) ORDER BY gkey';
            return [$sql, $root->params];
        }
        if ($grouping === 'domain') {
            $sql = 'SELECT m.`domain` AS gkey, MIN(m.`domain`) AS label, COUNT(m.username) AS n FROM '
                . $this->from(self::KIND_PERSON) . ' WHERE ' . $root->sql . ' GROUP BY m.`domain` ORDER BY gkey';
            return [$sql, $root->params];
        }
        return null;
    }

    private function addressColumn(string $kind): string
    {
        return match ($kind) {
            self::KIND_PERSON => 'm.username',
            self::KIND_ALIAS => 'a.address',
            default => 'ml.address',
        };
    }

    /**
     * @param list<string> $cols
     */
    private function hasAll(string $table, array $cols): bool
    {
        foreach ($cols as $c) {
            if (!$this->viewer->hasColumn($table, $c)) {
                Log::debug('schema', 'entity disabled: column not readable', ['table' => $table, 'column' => $c]);
                return false;
            }
        }
        return true;
    }
}
