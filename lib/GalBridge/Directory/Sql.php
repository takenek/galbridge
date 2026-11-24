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
 * An immutable SQL predicate fragment with its bound parameters.
 *
 * Every fragment renders inside parentheses, so composing them can never change precedence
 * ("scope AND a OR b" cannot happen). Empty lists have a defined meaning: IN () is FALSE and
 * NOT IN () is TRUE - a clause is never silently omitted. Values are always bound ('?'); column
 * expressions come from code constants and are checked to contain no quotes, placeholders or
 * comment markers.
 */
final class Sql
{
    /**
     * @param list<scalar|null> $params
     */
    private function __construct(public readonly string $sql, public readonly array $params)
    {
    }

    /**
     * Trusted constant SQL (from this plugin's code) with '?' placeholders.
     *
     * @param list<scalar|null> $params
     */
    public static function raw(string $sql, array $params = []): self
    {
        if (substr_count($sql, '?') !== count($params)) {
            throw new \LogicException('placeholder count mismatch');
        }
        return new self('(' . $sql . ')', $params);
    }

    public static function true(): self
    {
        return new self('(1=1)', []);
    }

    public static function false(): self
    {
        return new self('(1=0)', []);
    }

    public static function and(self ...$parts): self
    {
        return self::join(' AND ', array_values($parts), self::true());
    }

    public static function or(self ...$parts): self
    {
        return self::join(' OR ', array_values($parts), self::false());
    }

    public static function not(self $part): self
    {
        return new self('(NOT ' . $part->sql . ')', $part->params);
    }

    public static function eq(string $expr, string|int $value): self
    {
        return new self('(' . self::expr($expr) . ' = ?)', [$value]);
    }

    /**
     * @param list<string|int> $values
     */
    public static function in(string $expr, array $values): self
    {
        if ($values === []) {
            return self::false();
        }
        return new self('(' . self::expr($expr) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . '))', array_values($values));
    }

    /**
     * @param list<string|int> $values
     */
    public static function notIn(string $expr, array $values): self
    {
        if ($values === []) {
            return self::true();
        }
        return new self('(' . self::expr($expr) . ' NOT IN (' . implode(', ', array_fill(0, count($values), '?')) . '))', array_values($values));
    }

    /**
     * Case-insensitive LIKE with '!' as the escape character (identical on MariaDB, PostgreSQL and
     * SQLite; avoids backslash differences between sql_mode / standard_conforming_strings).
     * $pattern must already be built with escapeLike().
     */
    public static function like(string $driver, string $expr, string $pattern): self
    {
        $op = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        return new self('(' . self::expr($expr) . ' ' . $op . " ? ESCAPE '!')", [$pattern]);
    }

    /**
     * EXISTS / IN sub-select written by this plugin.
     *
     * @param list<scalar|null> $params
     */
    public static function exists(string $subSelect, array $params = []): self
    {
        if (substr_count($subSelect, '?') !== count($params)) {
            throw new \LogicException('placeholder count mismatch');
        }
        return new self('(EXISTS (' . $subSelect . '))', $params);
    }

    public static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * @param list<self> $parts
     */
    private static function join(string $glue, array $parts, self $empty): self
    {
        if ($parts === []) {
            return $empty;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $sql = [];
        $params = [];
        foreach ($parts as $p) {
            $sql[] = $p->sql;
            array_push($params, ...$p->params);
        }
        return new self('(' . implode($glue, $sql) . ')', $params);
    }

    private static function expr(string $expr): string
    {
        if (preg_match("/['\"?;]|--|\\/\\*/", $expr)) {
            throw new \LogicException('unsafe column expression');
        }
        return $expr;
    }
}
