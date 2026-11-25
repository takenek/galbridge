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
 * Every statement's WHERE clause is And(scope, visibility, canonical address, hidden, filter),
 * rendered fully parenthesised (Sql). There is no API to build a query without the scope. The
 * canonical-address predicate (canonicalAddressSql()) is the address rule of the PHP re-check
 * (Normalizer::CANONICAL_ADDRESS) in the engine's SQL, so SQL never offers a row that PHP drops:
 * counts, matches, groups and full sets are made of the rows that are delivered. Rows from
 * forwardings / moderators / maillist_owners are only ever used in semi-joins (EXISTS / IN), so
 * result rows are unique by primary key and counts and paging are exact.
 *
 * "forwardings" is never the iRedMail table itself but the restricted view Schema::FORWARDINGS
 * (created by the grant templates): it has no row of a private mailbox forwarding, and the server
 * builds its rows before it applies any condition of a statement (MariaDB/MySQL ALGORITHM =
 * TEMPTABLE with a LIMIT fence, PostgreSQL security_barrier with an OFFSET 0 fence; README 2.2), so
 * no condition of the role ever sees a private row. Because the view is built as a whole, the
 * statements refer to it only in UNCORRELATED semi-joins (Sql::inSelect(): computed once per
 * statement, also inside the OR branches of the list policies) and in the joins of the per-user
 * alias rows; never in a correlated EXISTS, which could build it again for every outer row.
 *
 * Search input is turned into predicates here (never into SQL text): words are normalised,
 * LIKE-escaped and bound. rcube_db inlines bound values into the statement text and PDO scans that
 * text again, so no value with a control character or a backslash is ever bound (words(), group
 * keys: IdCodec::usableGroupKey(); Connection::select() refuses a NUL byte or a backslash).
 *
 * Expiry (galbridge_hide_expired) is judged by the database server's clock in its session time zone
 * AND by UTC, whichever comes first (unexpired()): the vmail "expired" columns are timestamps without
 * a time zone, and an entry must not stay visible after its expiry whichever of the two conventions
 * wrote it.
 */
final class QueryBuilder
{
    public const KIND_PERSON = IdCodec::PERSON;
    public const KIND_ALIAS = IdCodec::ALIAS;
    public const KIND_LIST = IdCodec::MAILLIST;

    private const MAX_WORDS = 5;

    /** per-user alias rows f joined with the domain fd of the alias ADDRESS */
    private const USER_ALIAS_FROM = '`' . Schema::FORWARDINGS . '` f JOIN `domain` fd ON fd.`domain` = f.`domain`';

    /**
     * Normalizer::CANONICAL_ADDRESS as a POSIX extended regular expression that MariaDB (PCRE),
     * MySQL 8 (ICU) and PostgreSQL (ARE) read alike: no backslash ("[.]" for a dot), no '?'
     * (rcube_db takes every '?' of a statement for a placeholder: "{0,1}" instead), no lazy or
     * possessive forms. The 64-byte local part and the 254-byte total are separate conditions.
     */
    public const ADDRESS_ERE = '^[a-z0-9_+=-]+([.][a-z0-9_+=-]+)*@' . self::HOST_ERE . '$';

    /** Normalizer::CANONICAL_DOMAIN in the same notation as ADDRESS_ERE */
    public const DOMAIN_ERE = '^' . self::HOST_ERE . '$';

    private const HOST_ERE = '([a-z0-9]([a-z0-9-]{0,61}[a-z0-9]){0,1}[.])+([a-z][a-z0-9]{0,62}|xn--[a-z0-9]([a-z0-9-]{0,57}[a-z0-9]){0,1})';

    /** a character outside the canonical alphabet of an address / of a host name */
    public const ADDRESS_OTHER_CHAR = '[^a-z0-9._+=@-]';

    public const DOMAIN_OTHER_CHAR = '[^a-z0-9.-]';

    /** the characters a host name label may end with (SQLite rendering, rtrim()) */
    private const LABEL_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789-';

    /** person columns of a field that a foreign entry may not carry (RecordMapper::foreignWithheld()) */
    private const WITHHELD_COLUMNS = ['m.first_name' => 'firstname', 'm.last_name' => 'surname', 'm.department' => 'department', 'm.telephone' => 'phone', 'm.mobile' => 'mobile'];

    /**
     * @param list<string> $hiddenAddresses
     * @param list<string> $hiddenLocalparts
     * @param list<string> $foreignWithheld  fields an entry outside the viewer's home domains does not
     *                                       carry in this book (RecordMapper::foreignWithheld(); empty
     *                                       when the book does not mark foreign entries)
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
        private readonly array $foreignWithheld = [],
    ) {
    }

    /**
     * The one way to build the query builder of a book from the configuration. Malformed deny-lists
     * or visibility column are refused (they must disable the books, never mean "no restriction").
     *
     * @param null|list<string> $foreignWithheld RecordMapper::foreignWithheld() (Factory passes the
     *                                           list it gives the mapper); null = computed from
     *                                           Roundcube's name template when the book marks
     *                                           foreign entries
     *
     * @throws GalBridgeException 'config_invalid'
     */
    public static function forBook(Config $config, string $driver, BookDefinition $book, Viewer $viewer, FieldMap $fields, ?array $foreignWithheld = null): self
    {
        $hidden = $config->hiddenAddresses();
        $localparts = $config->hiddenLocalparts();
        $visibility = $config->visibilityColumn();
        if ($hidden === null || $localparts === null || $visibility === null) {
            throw new GalBridgeException('config_invalid', 'malformed deny-list or visibility column');
        }
        if ($config->booksDisabled()) {
            throw new GalBridgeException('config_invalid', 'ambiguous switch: every book is disabled');
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
            foreignWithheld: $book->markForeign ? ($foreignWithheld ?? RecordMapper::foreignWithheld(RecordMapper::nameTemplate())) : [],
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
     * With galbridge_hide_expired the "expired" column of the kind is needed as well: without it an
     * expired alias account or list could not be told from a valid one, so the whole kind is left
     * out (fail closed) instead of being listed unfiltered.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = [self::KIND_PERSON];
        $columns = ['address', 'name', 'accesspolicy', 'domain', 'active', ...($this->hideExpired ? ['expired'] : [])];
        if ($this->includeAliases && $this->hasAll(Schema::FORWARDINGS, ['address', 'is_list', 'active']) && $this->hasAll('alias', $columns)) {
            $kinds[] = self::KIND_ALIAS;
        }
        if ($this->includeLists && $this->hasAll(Schema::FORWARDINGS, ['address', 'is_maillist', 'active']) && $this->hasAll('maillists', $columns)) {
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
            self::canonicalAddressSql($this->driver, 'm.username', 'm.`domain`'),
        ];
        if ($this->hideExpired) {
            $parts[] = self::unexpired($this->driver, $this->now, 'm.expired', 'd.expired');
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
            self::canonicalAddressSql($this->driver, 'a.address', 'a.`domain`'),
        ];
        if ($this->hideExpired) {
            // kinds() lists alias accounts only when a.expired is readable
            $parts[] = self::unexpired($this->driver, $this->now, 'a.expired', 'd.expired');
        }
        $parts[] = Sql::inSelect('a.address', 'SELECT f.address FROM `' . Schema::FORWARDINGS . '` f WHERE f.is_list = 1 AND f.active = 1');
        $parts[] = (new AccessPolicy($this->listPolicy, $this->viewer))->alias();
        $parts[] = $this->hidden('a.address');
        return Sql::and(...$parts);
    }

    private function listRoot(): Sql
    {
        $parts = [
            $this->scope('ml'),
            Sql::raw('ml.active = 1 AND d.active = 1 AND d.backupmx = 0'),
            self::canonicalAddressSql($this->driver, 'ml.address', 'ml.`domain`'),
        ];
        if ($this->hideExpired) {
            // kinds() lists mailing lists only when ml.expired is readable
            $parts[] = self::unexpired($this->driver, $this->now, 'ml.expired', 'd.expired');
        }
        $parts[] = Sql::inSelect('ml.address', 'SELECT f.address FROM `' . Schema::FORWARDINGS . '` f WHERE f.is_maillist = 1 AND f.active = 1');
        $parts[] = (new AccessPolicy($this->listPolicy, $this->viewer))->mailingList();
        $parts[] = $this->hidden('ml.address');
        return Sql::and(...$parts);
    }

    /**
     * The canonical-row rule (Normalizer::CANONICAL_ADDRESS) for an address column, rendered for the
     * engine; with $domainColumn also "the address belongs to its domain column" (the suffix test of
     * RecordMapper). A row this predicate keeps passes the PHP re-check (canonicalAddress(), the
     * domain and suffix test), so SQL and PHP decide alike: a row that does not follow the rule is
     * neither listed, counted, matched, grouped nor part of a full set - instead of being counted by
     * SQL and dropped by PHP (an existence oracle for hidden entries, and a refused full set).
     *
     *  - PostgreSQL: ASCII only (octet_length = char_length), no character outside the alphabet
     *    (unanchored, so a trailing newline is caught as well), the ERE, the lengths; case-sensitive
     *    like the engine - an address written with capitals is not offered (its ID would not find
     *    the row either);
     *  - MariaDB/MySQL: the same with REGEXP; the ASCII test comes first in meaning - it keeps
     *    Unicode case folding (U+212A KELVIN SIGN, U+017F LONG S onto k / s) out of the class. Under
     *    iRedMail's case-insensitive collation ASCII capitals pass: PHP lower-cases them, and the
     *    ID lookups of the same collation find the row;
     *  - SQLite (the test mirror; no REGEXP): GLOB patterns, case-sensitive.
     *
     * Every pattern is bound; the constant SQL holds no '?' and no backslash. A NULL column makes the
     * predicate NULL, i.e. the row is not offered.
     */
    public static function canonicalAddressSql(string $driver, string $column, ?string $domainColumn = null): Sql
    {
        $c = $column;
        if ($driver === 'pgsql') {
            $parts = [
                Sql::raw("octet_length({$c}) = char_length({$c})"),
                Sql::raw("{$c} !~ ?", [self::ADDRESS_OTHER_CHAR]),
                Sql::raw("{$c} ~ ?", [self::ADDRESS_ERE]),
                Sql::raw("length({$c}) <= 254 AND strpos({$c}, '@') <= 65"),
            ];
            if ($domainColumn !== null) {
                $d = $domainColumn;
                $parts[] = Sql::raw("substr({$c}, strpos({$c}, '@') + 1) = {$d} AND octet_length({$d}) = char_length({$d})");
            }
            return Sql::and(...$parts);
        }
        if ($driver === 'mysql') {
            $parts = [
                Sql::raw("LENGTH({$c}) = CHAR_LENGTH({$c})"),
                Sql::raw("{$c} NOT REGEXP ?", [self::ADDRESS_OTHER_CHAR]),
                Sql::raw("{$c} REGEXP ?", [self::ADDRESS_ERE]),
                Sql::raw("LENGTH({$c}) <= 254 AND LOCATE('@', {$c}) <= 65"),
            ];
            if ($domainColumn !== null) {
                $d = $domainColumn;
                $parts[] = Sql::raw("SUBSTRING_INDEX({$c}, '@', -1) = {$d} AND LENGTH({$d}) = CHAR_LENGTH({$d})");
            }
            return Sql::and(...$parts);
        }
        // SQLite: the rule as GLOB patterns (each a necessary condition; together exactly the rule)
        $host = "substr({$c}, instr({$c}, '@') + 1)";
        $parts = [
            Sql::raw("length(CAST({$c} AS BLOB)) = length({$c}) AND length({$c}) <= 254 AND instr({$c}, '@') <= 65"),
            self::globs($c, [
                '*' . self::ADDRESS_OTHER_CHAR . '*' => false,
                '*@*' => true,
                '@*' => false,          // an empty local part
                '*@' => false,          // an empty host
                '*@*@*' => false,
                '.*' => false,          // dots of the local part: not first, not last, never two
                '*.@*' => false,
                '*..*' => false,
                '*@.*' => false,        // labels of the host: never empty ...
                '*.' => false,
                '*@-*' => false,        // ... never starting or ending with a hyphen
                '*@*.-*' => false,
                '*@*-.*' => false,
                '*-' => false,
                '*@*' . str_repeat('[a-z0-9-]', 64) . '*' => false, // ... at most 63 characters
            ]),
            self::globs($host, ['*' . self::DOMAIN_OTHER_CHAR . '*' => false, '*.*' => true]),
            self::sqliteTopLabel($host),
        ];
        if ($domainColumn !== null) {
            $d = $domainColumn;
            $parts[] = Sql::raw("{$host} = {$d} AND length(CAST({$d} AS BLOB)) = length({$d})");
        }
        return Sql::and(...$parts);
    }

    /**
     * The host name rule (Normalizer::CANONICAL_DOMAIN) for a domain column, rendered for the engine
     * like canonicalAddressSql().
     */
    public static function canonicalDomainSql(string $driver, string $column): Sql
    {
        $c = $column;
        if ($driver === 'pgsql') {
            return Sql::and(
                Sql::raw("octet_length({$c}) = char_length({$c})"),
                Sql::raw("{$c} !~ ?", [self::DOMAIN_OTHER_CHAR]),
                Sql::raw("{$c} ~ ?", [self::DOMAIN_ERE]),
                Sql::raw("length({$c}) <= 253"),
            );
        }
        if ($driver === 'mysql') {
            return Sql::and(
                Sql::raw("LENGTH({$c}) = CHAR_LENGTH({$c})"),
                Sql::raw("{$c} NOT REGEXP ?", [self::DOMAIN_OTHER_CHAR]),
                Sql::raw("{$c} REGEXP ?", [self::DOMAIN_ERE]),
                Sql::raw("LENGTH({$c}) <= 253"),
            );
        }
        return Sql::and(
            Sql::raw("length(CAST({$c} AS BLOB)) = length({$c}) AND length({$c}) <= 253"),
            self::globs($c, [
                '*' . self::DOMAIN_OTHER_CHAR . '*' => false,
                '*.*' => true,
                '.*' => false,
                '*.' => false,
                '*..*' => false,
                '-*' => false,
                '*.-*' => false,
                '*-.*' => false,
                '*-' => false,
                '*' . str_repeat('[a-z0-9-]', 64) . '*' => false,
            ]),
            self::sqliteTopLabel($c),
        );
    }

    /**
     * SQLite: "$expr GLOB p" (true) / "$expr NOT GLOB p" (false) for every pattern, bound.
     *
     * @param array<string, bool> $patterns
     */
    private static function globs(string $expr, array $patterns): Sql
    {
        $parts = [];
        foreach ($patterns as $pattern => $match) {
            $parts[] = Sql::raw($expr . ($match ? ' GLOB ?' : ' NOT GLOB ?'), [(string) $pattern]);
        }
        return Sql::and(...$parts);
    }

    /**
     * SQLite: the last label of a host name starts with a letter and has letters and digits only,
     * or is an A-label (xn--). rtrim() with the label characters leaves the host up to its last
     * dot, so the substring behind that is the last label.
     */
    private static function sqliteTopLabel(string $host): Sql
    {
        $top = "substr({$host}, length(rtrim({$host}, ?)) + 1)";
        return Sql::raw(
            "(({$top} GLOB ? AND {$top} NOT GLOB ?) OR {$top} GLOB ?)",
            [self::LABEL_CHARS, '[a-z]*', self::LABEL_CHARS, '*[^a-z0-9]*', self::LABEL_CHARS, 'xn--[a-z0-9]*'],
        );
    }

    /**
     * "Not expired" for each column: later than $utcNow (UTC, bound) AND later than the database
     * server's clock in its session time zone (LOCALTIMESTAMP; SQLite, tests only: the local time of
     * the process). A timestamp written in UTC or in the server's local time is hidden from its
     * moment on, whichever comes first. Both comparisons can use an index on the column.
     */
    public static function unexpired(string $driver, string $utcNow, string ...$columns): Sql
    {
        $clock = $driver === 'sqlite' ? "datetime('now', 'localtime')" : 'LOCALTIMESTAMP';
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = Sql::raw($column . ' > ? AND ' . $column . ' > ' . $clock, [$utcNow]);
        }
        return Sql::and(...$parts);
    }

    /**
     * A DENY-list compares in lower case on every engine (the configured values are normalised to
     * lower case): PostgreSQL and SQLite compare text case-sensitively, so a row written with
     * capitals would otherwise escape an exclusion or a hidden list in SQL - dropped only later in
     * PHP, but counted and matched by searches. An ALLOW-list keeps the plain column: a row in
     * another spelling is left out there (fail closed) and the index on the column stays usable.
     */
    private function scope(string $t): Sql
    {
        return $this->book->allDomains
            ? Sql::notIn('LOWER(' . $t . '.`domain`)', $this->book->excludeDomains)
            : Sql::in($t . '.`domain`', $this->book->domains);
    }

    private function hidden(string $addressColumn): Sql
    {
        // (LIKE is ILIKE on PostgreSQL, see Sql::like(), and case-insensitive on MariaDB/MySQL)
        $parts = [Sql::notIn('LOWER(' . $addressColumn . ')', $this->hiddenAddresses)];
        foreach ($this->hiddenLocalparts as $lp) {
            $parts[] = Sql::not(Sql::like($this->driver, $addressColumn, Sql::escapeLike($lp) . '@%'));
        }
        return Sql::and(...$parts);
    }

    /**
     * The ONE predicate for per-user alias addresses (USER_ALIAS_FROM). An alias is shown next to its
     * owner and matched by e-mail searches under exactly the same conditions: an active alias row
     * that belongs to an active (and unexpired) non-backup-MX domain inside the data scope of the
     * book, and whose address is on neither hidden list. A hidden alias - or a row of a domain this
     * book does not cover - is therefore neither listed nor usable as a search oracle for its owner.
     *
     * "Belongs to" is forwardings.domain: iRedAdmin stores the owner's primary domain there, also
     * for an address in an alias domain (jan@example.net of example.com), which has no row in the
     * domain table of its own - so the alias address follows the canonical rule without the suffix
     * test, and forwardings.domain the host name rule.
     */
    private function userAliasRoot(): Sql
    {
        $parts = [
            Sql::raw('f.is_alias = 1 AND f.active = 1 AND fd.active = 1 AND fd.backupmx = 0'),
            self::canonicalAddressSql($this->driver, 'f.address'),
            self::canonicalDomainSql($this->driver, 'f.`domain`'),
        ];
        if ($this->hideExpired) {
            $parts[] = self::unexpired($this->driver, $this->now, 'fd.expired');
        }
        $parts[] = $this->scope('f');
        $parts[] = $this->hidden('f.address');
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
            if ($kind !== self::KIND_PERSON) {
                $parts[] = Sql::false();
            } elseif ($col === 'birthday') {
                // a DATE (iRedMail: NOT NULL DEFAULT '0001-01-01'): year 0001 means "no birthday"
                // (RecordMapper), and a date is never compared with text (PostgreSQL refuses that)
                $parts[] = Sql::raw("m.birthday >= '0002-01-01'");
            } else {
                // a text column: shown only when something is left after trimming - and only for an
                // entry that carries the field (foreignGuard())
                $parts[] = $this->foreignGuard($f, false, Sql::raw("TRIM(m.{$col}) <> ''"));
            }
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
     * searched (empty, too long, too short, invalid UTF-8, or with a control character or a
     * backslash). Whitespace (tab, newline) is collapsed into a blank first; any other control
     * character (C0, DEL, C1) or a backslash can never match the text a book shows
     * (Normalizer::displayText() removes them), and bound into the statement it would make PDO's
     * scanner and the server see different literals (a backslash) or let libpq cut the pattern
     * short (a NUL byte).
     *
     * @return null|list<array{0: string, 1: int}>
     */
    public function words(string $value, int $mode): ?array
    {
        $value = (string) preg_replace('/\s+/u', ' ', Normalizer::nfc($value));
        // checked before trim(), which would drop a NUL byte at either end without a word
        if (preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}\\\\]/u', $value) === 1) {
            return null;
        }
        $value = mb_strtolower(trim($value));
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
            $any = [];
            foreach ($this->fields->personSearchColumns($field) as $col) {
                $withheld = self::WITHHELD_COLUMNS[$col] ?? null;
                $match = $this->textMatch($col, $word, $mode);
                // first and last name under 'name' are the shown name of an entry without a display name
                $any[] = $withheld === null ? $match : $this->foreignGuard($withheld, $field === 'name', $match);
            }
            return Sql::or(...$any);
        }
        $t = $kind === self::KIND_ALIAS ? 'a' : 'ml';
        return $field === 'name' ? $this->textMatch($t . '.name', $word, $mode) : Sql::false();
    }

    /**
     * A predicate on a field that an entry of another domain does not carry in this book
     * (foreignWithheld): it applies to the entries of the viewer's home domains only - a field that
     * is not shown is no search criterion either, so a search cannot tell its value. With
     * $asShownName (first and last name in a search of 'name'), also to an entry without a display
     * name: its first and last name are its shown name ("(domain) First Last").
     */
    private function foreignGuard(string $field, bool $asShownName, Sql $predicate): Sql
    {
        if (!in_array($field, $this->foreignWithheld, true)) {
            return $predicate;
        }
        $home = Sql::in('m.`domain`', $this->book->homeDomains);
        if ($asShownName && ($field === 'firstname' || $field === 'surname')) {
            return Sql::and(Sql::or($home, Sql::raw("TRIM(m.name) = ''")), $predicate);
        }
        return Sql::and($home, $predicate);
    }

    /**
     * Sort key of persons: for a book that withholds first / last name from foreign entries, such an
     * entry is sorted by its name key instead (a field that is not shown does not order the list).
     */
    private function personSortKey(string $sortCol): Sql
    {
        $key = $this->fields->personSortKey($sortCol);
        $field = ['firstname' => 'firstname', 'surname' => 'surname'][$sortCol] ?? null;
        if ($field === null || !in_array($field, $this->foreignWithheld, true)) {
            return Sql::raw($key);
        }
        $home = Sql::in('m.`domain`', $this->book->homeDomains);
        return Sql::raw('CASE WHEN ' . $home->sql . ' THEN ' . $key . ' ELSE ' . $this->fields->personSortKey('name') . ' END', $home->params);
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
            // the same visibility predicate as for showing the alias: a search must never match
            // an alias that is not displayed
            $alias = Sql::and($this->userAliasRoot(), Sql::like($this->driver, 'f.address', $pattern));
            $parts[] = Sql::raw('m.username IN (SELECT f.forwarding FROM ' . self::USER_ALIAS_FROM . ' WHERE ' . $alias->sql . ')', $alias->params);
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
        // labels went through Normalizer::displayText(): compare the word in the same notation
        $word = Normalizer::labelWord($word);
        foreach ($this->book->orgLabels as $domain => $label) {
            $domain = (string) $domain;
            $l = mb_strtolower((string) $label);
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
        if (!$this->fields->has('department')) {
            return Sql::false();
        }
        if ($this->book->id === BookDefinition::GLOBAL) {
            // "domain:department" (groupsQuery()): a group of the global book never crosses tenants;
            // an ID of the merged form of 1.0.x (no ':') selects nothing
            $parts = explode(':', $group['key'], 2);
            if (count($parts) !== 2 || !$this->book->inScope($parts[0])) {
                return Sql::false();
            }
            return Sql::and(Sql::eq('m.`domain`', $parts[0]), Sql::eq('LOWER(TRIM(m.department))', $parts[1]));
        }
        return Sql::eq('LOWER(TRIM(m.department))', $group['key']);
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
            $sortkey = $kind === self::KIND_PERSON ? $this->personSortKey($sortCol) : Sql::raw(FieldMap::listSortKey($kind === self::KIND_ALIAS ? 'a' : 'ml'));
            $selects[] = "SELECT '{$kind}' AS kind, " . $this->addressColumn($kind) . ' AS address, ' . $sortkey->sql . ' AS sortkey FROM ' . $this->from($kind) . ' WHERE ' . $where->sql;
            // the parameters in the order of the statement: the select list before the WHERE clause
            array_push($params, ...$sortkey->params, ...$where->params);
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
     * Per-user alias addresses of the given (already visible) persons, under userAliasRoot(): at
     * most $perOwner rows for each owner, the first ones in address order (ROW_NUMBER() per owner -
     * a window function: MariaDB 10.2, MySQL 8.0, PostgreSQL 8.4, SQLite 3.25), ordered by owner and
     * address. The bound is per owner, so no owner's rows can push another owner's out.
     *
     * @param list<string> $usernames
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function userAliasesQuery(array $usernames, int $perOwner): array
    {
        $where = Sql::and($this->userAliasRoot(), Sql::in('f.forwarding', $usernames));
        return [
            'SELECT ua.forwarding, ua.address, ua.`domain` FROM (SELECT f.forwarding, f.address, f.`domain`,'
                . ' ROW_NUMBER() OVER (PARTITION BY f.forwarding ORDER BY f.address) AS rn FROM ' . self::USER_ALIAS_FROM
                . ' WHERE ' . $where->sql . ') ua WHERE ua.rn <= ' . max(1, $perOwner) . ' ORDER BY ua.forwarding, ua.address',
            $where->params,
        ];
    }

    /**
     * Groups of this book with member counts (persons only), one aggregate query.
     *
     * @return null|array{0: string, 1: list<scalar|null>} null when the book has no groups
     */
    public function groupsQuery(string $grouping): ?array
    {
        $root = $this->personRoot();
        if ($grouping === 'department' && $this->fields->has('department') && $this->book->id === BookDefinition::GLOBAL) {
            // the global book: one group per domain and department - equal department names of
            // different tenants never form one group (Repository::groups() keys it "domain:department")
            $sql = 'SELECT m.`domain` AS gdomain, LOWER(TRIM(m.department)) AS gkey, MIN(TRIM(m.department)) AS label, COUNT(m.username) AS n FROM '
                . $this->from(self::KIND_PERSON) . ' WHERE ' . $root->sql . " AND TRIM(m.department) <> ''"
                . ' GROUP BY m.`domain`, LOWER(TRIM(m.department)) ORDER BY gdomain, gkey';
            return [$sql, $root->params];
        }
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
