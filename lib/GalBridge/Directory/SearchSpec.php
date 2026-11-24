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
 * The search filter of an AddressBook - what get_search_set() returns and set_search_set() accepts.
 *
 * Roundcube serialises it into $_SESSION['contact_search'] and hands it back in later requests (list
 * pages, compose list, export). It therefore carries no SQL and no scope: only the typed criteria,
 * bound to the book and to the configuration fingerprint. The scope is re-applied every time the
 * spec is executed, and set_search_set() re-validates every criterion against the current field map,
 * so a removed field or a tightened rule takes effect for existing sessions immediately.
 */
final class SearchSpec
{
    public const VERSION = 1;

    public const NONE = 'none';         // matches nothing
    public const QUICK = 'quick';       // words, OR across $fields
    public const ADVANCED = 'advanced'; // field[i] matches value[i], AND
    public const IDS = 'ids';           // primary-key search

    /**
     * @param list<string>                               $fields
     * @param list<string>                               $values   QUICK: [term]; ADVANCED: one per field
     * @param list<string>                               $required record fields that must be non-empty
     * @param list<array{kind: string, address: string}> $ids
     */
    public function __construct(
        public readonly string $type,
        public readonly string $book,
        public readonly string $fingerprint,
        public readonly array $fields = [],
        public readonly array $values = [],
        public readonly int $mode = 0,
        public readonly array $required = [],
        public readonly array $ids = [],
    ) {
    }

    public static function none(string $book, string $fingerprint): self
    {
        return new self(self::NONE, $book, $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'galbridge' => self::VERSION,
            'type' => $this->type,
            'book' => $this->book,
            'fp' => $this->fingerprint,
            'fields' => $this->fields,
            'values' => $this->values,
            'mode' => $this->mode,
            'required' => $this->required,
            'ids' => array_map(static fn (array $i): string => IdCodec::contactId($i['kind'], $i['address']), $this->ids),
        ];
    }

    /**
     * Strict reconstruction; null for anything that toArray() of the same book and configuration
     * could not have produced.
     */
    public static function fromArray(mixed $a, string $book, string $fingerprint): ?self
    {
        if (!is_array($a) || ($a['galbridge'] ?? null) !== self::VERSION || ($a['book'] ?? null) !== $book || ($a['fp'] ?? null) !== $fingerprint) {
            return null;
        }
        $type = $a['type'] ?? null;
        if (!in_array($type, [self::NONE, self::QUICK, self::ADVANCED, self::IDS], true)) {
            return null;
        }
        foreach (['fields', 'values', 'required', 'ids'] as $k) {
            if (!is_array($a[$k] ?? null) || !array_is_list($a[$k])) {
                return null;
            }
            foreach ($a[$k] as $v) {
                if (!is_string($v)) {
                    return null;
                }
            }
        }
        if (!is_int($a['mode'] ?? null)) {
            return null;
        }
        $ids = [];
        foreach ($a['ids'] as $id) {
            $decoded = IdCodec::decodeContact($id);
            if ($decoded === null) {
                return null;
            }
            $ids[] = $decoded;
        }
        /** @var list<string> $fields */
        $fields = $a['fields'];
        /** @var list<string> $values */
        $values = $a['values'];
        /** @var list<string> $required */
        $required = $a['required'];
        return new self($type, $book, $fingerprint, $fields, $values, $a['mode'] & 3, $required, $ids);
    }
}
