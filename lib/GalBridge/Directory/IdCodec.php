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

use GalBridge\Normalizer;

/**
 * Contact and group identifiers.
 *
 * Roundcube 1.7 moves IDs through HTML ids, JavaScript strings, comma-separated lists, "ID-source"
 * pairs (split at the last '-' in PHP, at the first in JavaScript) and GET URLs (export of the
 * selected contacts joins all of them). The only safe character set is [A-Za-z0-9_]: no '-', ',',
 * '+', '/' or '=' - base64url is NOT safe.
 *
 * Format: "<prefix>_<enc>". enc keeps [a-z0-9] literally and escapes every other byte with an
 * upper-case marker (free, because vmail addresses are lower case): '.' D, '@' A, '-' H, '_' U,
 * '+' P, any other byte X + two upper-case hex digits. jan.kowalski@firma.pl becomes
 * u_janDkowalskiAfirmaDpl - reversible (direct primary-key lookup), compact and stable.
 *
 * Decoding is strict and canonical (re-encoding must reproduce the input, so one row has exactly
 * one ID on every engine) and never throws. Decoding does NOT authorise anything: every decoded key
 * is looked up inside the book's scope.
 */
final class IdCodec
{
    public const PERSON = 'u';
    public const ALIAS = 'a';
    public const MAILLIST = 'l';

    /** group kinds; the first letter of a group prefix is the book tag (d = domain book, g = global) */
    public const GROUP_DEPARTMENT = 'p';
    public const GROUP_DOMAIN = 'd';

    public const MAX_KEY_BYTES = 255;

    private const CONTACT_KINDS = [self::PERSON, self::ALIAS, self::MAILLIST];
    private const GROUP_KINDS = [self::GROUP_DEPARTMENT, self::GROUP_DOMAIN];
    private const BOOK_TAGS = ['d', 'g'];

    private const ESCAPES = ['.' => 'D', '@' => 'A', '-' => 'H', '_' => 'U', '+' => 'P'];

    public static function contactId(string $kind, string $address): string
    {
        if (!in_array($kind, self::CONTACT_KINDS, true)) {
            throw new \InvalidArgumentException('unknown contact kind');
        }
        return $kind . '_' . self::encode($address);
    }

    /**
     * @return null|array{kind: string, address: string}
     */
    public static function decodeContact(mixed $id): ?array
    {
        if (!is_string($id) || !preg_match('/^([ual])_([A-Za-z0-9]{3,765})$/D', $id, $m)) {
            return null;
        }
        $address = self::decode($m[2]);
        if ($address === null || Normalizer::address($address) !== $address) {
            return null; // not a canonical, lower-case vmail address
        }
        return ['kind' => $m[1], 'address' => $address];
    }

    public static function groupId(string $bookTag, string $kind, string $key): string
    {
        if (!in_array($bookTag, self::BOOK_TAGS, true) || !in_array($kind, self::GROUP_KINDS, true)) {
            throw new \InvalidArgumentException('unknown group kind');
        }
        return $bookTag . $kind . '_' . self::encode($key);
    }

    /**
     * @return null|array{book: string, kind: string, key: string}
     */
    public static function decodeGroup(mixed $id): ?array
    {
        if (!is_string($id) || !preg_match('/^([dg])([pd])_([A-Za-z0-9]{1,765})$/D', $id, $m)) {
            return null;
        }
        $key = self::decode($m[3]);
        // group keys are LOWER(TRIM(value)) as computed by the database: never edge spaces or ASCII
        // upper case (non-ASCII letters may stay upper case where LOWER() does not fold them)
        if ($key === null || trim($key) === '' || $key !== trim($key, ' ') || preg_match('/[A-Z]/', $key) || !self::usableGroupKey($key)) {
            return null;
        }
        return ['book' => $m[1], 'kind' => $m[2], 'key' => $key];
    }

    /**
     * A group key that may be bound into a statement: valid UTF-8 without a control character (C0,
     * DEL, C1) or a backslash. rcube_db inlines bound values and PDO scans the statement text again,
     * so such a value is never bound (a department with one is not offered as a group, and a
     * forged group ID with one matches nothing).
     */
    public static function usableGroupKey(string $key): bool
    {
        return preg_match('//u', $key) === 1 && preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}\\\\]/u', $key) !== 1;
    }

    /**
     * Split Roundcube's ID argument (a comma-separated string or an array, as passed to search()
     * for a primary-key search) into de-duplicated decoded IDs. Invalid entries are counted.
     *
     * @return list<array{kind: string, address: string}>
     */
    public static function decodeContactList(mixed $value, int &$rejected = 0): array
    {
        $rejected = 0;
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $out = [];
        foreach ($items as $item) {
            $decoded = self::decodeContact(is_string($item) ? trim($item) : $item);
            if ($decoded === null) {
                $rejected++;
                continue;
            }
            $out[$decoded['kind'] . ':' . $decoded['address']] = $decoded;
        }
        return array_values($out);
    }

    public static function encode(string $value): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if (($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9')) {
                $out .= $c;
            } elseif (isset(self::ESCAPES[$c])) {
                $out .= self::ESCAPES[$c];
            } else {
                $out .= 'X' . strtoupper(bin2hex($c));
            }
        }
        return $out;
    }

    /**
     * Strict inverse of encode(); null for anything that encode() could not have produced.
     */
    public static function decode(string $enc): ?string
    {
        static $unescape = null;
        $unescape ??= array_flip(self::ESCAPES);
        $out = '';
        $len = strlen($enc);
        for ($i = 0; $i < $len; $i++) {
            $c = $enc[$i];
            if (($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9')) {
                $out .= $c;
            } elseif (isset($unescape[$c])) {
                $out .= $unescape[$c];
            } elseif ($c === 'X' && $i + 2 < $len && preg_match('/^[0-9A-F]{2}$/', substr($enc, $i + 1, 2))) {
                $out .= chr((int) hexdec(substr($enc, $i + 1, 2)));
                $i += 2;
            } else {
                return null;
            }
        }
        if ($out === '' || strlen($out) > self::MAX_KEY_BYTES || !preg_match('//u', $out)
            || preg_match('/[\x00-\x1F\x7F]/', $out) || self::encode($out) !== $enc) {
            return null;
        }
        return $out;
    }
}
