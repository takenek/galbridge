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
 * The single normalisation used for configuration lists, the login, decoded IDs and the PHP
 * re-check of database rows.
 *
 * iRedMail stores addresses and domains in lower case IDNA-ASCII (A-labels). MariaDB compares them
 * case-insensitively (utf8mb4_general_ci, PAD SPACE), PostgreSQL case-sensitively. Normalising
 * every value the same way before it is compared or bound makes allow- and deny-lists behave
 * identically on both engines.
 *
 * Every method returns null for input that cannot be normalised. Callers decide whether that drops
 * the value (allow-lists) or disables a feature (deny-lists).
 */
final class Normalizer
{
    /** RFC 5321 path limit */
    public const MAX_ADDRESS = 254;

    public const MAX_DOMAIN = 253;

    /**
     * Invisible / direction-changing characters (bidi controls incl. U+061C ARABIC LETTER MARK,
     * zero-width characters, line/paragraph separators, word joiner and invisible operators, BOM),
     * as a regular-expression alternative (u modifier).
     */
    public const INVISIBLE = '[\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]';

    /**
     * Normalise a domain: trim, NFC, lower case, IDNA to ASCII, trailing dot removed, validated.
     */
    public static function domain(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null; // invalid UTF-8 must fail, never be "repaired" into another value
        }
        $d = trim(self::nfc($value));
        $d = rtrim($d, '.');
        if ($d === '' || preg_match('/[\s\x00-\x1F\x7F@\/\\\\:]/u', $d)) {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $d)) {
            $ascii = self::idnToAscii(mb_strtolower($d));
            if ($ascii === null) {
                return null;
            }
            $d = $ascii;
        }
        $d = strtolower($d);
        if (strlen($d) > self::MAX_DOMAIN) {
            return null;
        }
        foreach (explode('.', $d) as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/', $label)) {
                return null;
            }
        }
        return $d;
    }

    /**
     * Normalise an e-mail address: split at the last '@', lower-case local part, normalised domain.
     */
    public static function address(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null; // invalid UTF-8 must fail, never be "repaired" into another value
        }
        $a = trim(self::nfc($value));
        $at = strrpos($a, '@');
        if ($at === false || $at === 0) {
            return null;
        }
        $local = mb_strtolower(substr($a, 0, $at));
        $domain = self::domain(substr($a, $at + 1));
        if ($domain === null || !self::localPart($local)) {
            return null;
        }
        $address = $local . '@' . $domain;
        return strlen($address) <= self::MAX_ADDRESS ? $address : null;
    }

    /**
     * A plausible local part as stored by iRedMail (no whitespace, separators, quotes or brackets).
     */
    public static function localPart(string $local): bool
    {
        return $local !== ''
            && strlen($local) <= 64
            && preg_match('//u', $local) === 1
            && !preg_match('/[\s\x00-\x1F\x7F,;:<>()\[\]"\'\\\\@]|[\x{0080}-\x{009F}]|' . self::INVISIBLE . '/u', $local);
    }

    /**
     * Normalise a list option. Returns:
     *  - ['*'] for the wildcard (only accepted as the sole element),
     *  - the list of normalised values otherwise,
     *  - null when the option is malformed (not an array, '*' mixed with values, or an entry that
     *    cannot be normalised and $strict is set).
     * With $strict = false invalid entries are dropped and reported through $invalid.
     *
     * @param callable(mixed): ?string $normalise
     * @param list<string>             $invalid
     *
     * @return null|list<string>
     */
    public static function list(mixed $value, callable $normalise, bool $strict, array &$invalid = []): ?array
    {
        $invalid = [];
        if (!is_array($value)) {
            return null;
        }
        $values = array_values($value);
        if (in_array('*', $values, true)) {
            return count($values) === 1 ? ['*'] : null;
        }
        $out = [];
        $seen = [];
        foreach ($values as $v) {
            $n = $normalise($v);
            if ($n === null) {
                $invalid[] = is_scalar($v) ? (string) $v : get_debug_type($v);
                if ($strict) {
                    return null;
                }
                continue;
            }
            // a separate seen-map: array_keys() would turn numeric strings ('1001') into ints
            if (!isset($seen[$n])) {
                $seen[$n] = true;
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Parent domains of a domain, the domain itself included:
     * a.b.example.com -> [a.b.example.com, b.example.com, example.com].
     *
     * @return list<string>
     */
    public static function parentDomains(string $domain): array
    {
        $labels = explode('.', $domain);
        $out = [];
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $out[] = implode('.', array_slice($labels, $i));
        }
        return $out === [] ? [$domain] : $out;
    }

    /**
     * Text from the directory for display: NFC, control / bidi / zero-width characters, CR/LF and the
     * characters \ " < > removed (they would break or spoof a recipient header), collapsed
     * whitespace, length-capped.
     */
    public static function displayText(mixed $value, int $max = 128): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = (string) $value;
        if (!preg_match('//u', $s)) {
            return '';
        }
        $s = self::nfc($s);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]|[\x{0080}-\x{009F}]|' . self::INVISIBLE . '|[\\\\"<>]/u', ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if (mb_strlen($s) > $max) {
            $s = rtrim(mb_substr($s, 0, $max));
        }
        return $s;
    }

    /**
     * Unicode NFC when ext-intl is available (the input unchanged otherwise).
     */
    public static function nfc(string $value): string
    {
        if (class_exists('Normalizer', false) && preg_match('/[^\x00-\x7F]/', $value)) {
            $n = \Normalizer::normalize($value, \Normalizer::FORM_C);
            return is_string($n) ? $n : $value;
        }
        return $value;
    }

    private static function idnToAscii(string $domain): ?string
    {
        if (!function_exists('idn_to_ascii')) {
            return null; // fail: non-ASCII input cannot be normalised without ext-intl
        }
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        return is_string($ascii) && $ascii !== '' ? $ascii : null;
    }
}
