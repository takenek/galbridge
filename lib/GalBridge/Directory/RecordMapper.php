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

use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * Database row -> Roundcube contact record.
 *
 *  - every record has ID, a scalar name, at least one e-mail (email:work) and _type 'person' (also
 *    alias accounts and lists: core gives 'group' other meanings); no value is ever null and there is
 *    no 'vcard' key (copy and export would trust it);
 *  - only effective fields are emitted;
 *  - display text is sanitised (Normalizer::displayText, which also neutralises HTML character
 *    references and RFC 2047 encoded-words); a name that contains '@' is cut there (it could
 *    impersonate another address); addresses are canonical lower-case ASCII (no IDN homographs) and
 *    must pass rcube_utils::check_email();
 *  - an entry of the global book from outside the viewer's home domains is marked "(domain) Name":
 *    the marker comes FIRST, so the ellipsis of a narrow recipient field can never cut it off. Such
 *    an entry carries no first / last name (every listing mode then uses the marked name) and none
 *    of the free-text fields that the administrator's contactlist_name_template would show before
 *    the name or without it (textBeforeName());
 *  - the row is re-checked against the book's scope and the hidden lists (defence in depth); a
 *    violation is dropped and logged. Per-user alias addresses are subject to the same checks: an
 *    alias on a hidden list is never shown (the Repository drops alias rows that belong to a domain
 *    outside the book's scope before they get here).
 */
final class RecordMapper
{
    /**
     * Fields that carry free text of the entry's own domain and that a name template of Roundcube
     * can show: placeholder of the template => keys of the record ("{phone}" stands for every
     * phone:* value). The name is not listed: it carries the "(domain)" marker.
     */
    private const TEMPLATE_TEXT = ['department' => ['department'], 'phone' => ['phone:work', 'phone:mobile']];

    /** @var null|list<string> record keys a foreign entry does not carry (withheldFromForeign()) */
    private ?array $withheld = null;

    /**
     * @param list<string> $hiddenAddresses
     * @param list<string> $hiddenLocalparts
     */
    public function __construct(
        private readonly BookDefinition $book,
        private readonly FieldMap $fields,
        private readonly array $hiddenAddresses,
        private readonly array $hiddenLocalparts,
    ) {
    }

    /**
     * @param array<string, ?string> $row
     * @param list<string>           $aliases
     *
     * @return null|array<string, mixed>
     */
    public function person(array $row, array $aliases = []): ?array
    {
        $address = $this->checkedAddress($row['username'] ?? null, $row['domain'] ?? null);
        if ($address === null) {
            return null;
        }
        [$local, $domain] = explode('@', $address, 2);

        // first/last names reach recipient headers too (core builds the name from them for the user
        // preferences addressbook_name_listing 1-3 and for contactlist_name_template): the same
        // anti-impersonation rule as for the display name
        $first = $this->fields->has('firstname') ? $this->safeName(Normalizer::displayText($row['first_name'] ?? '')) : '';
        $last = $this->fields->has('surname') ? $this->safeName(Normalizer::displayText($row['last_name'] ?? '')) : '';
        $isForeign = $this->isForeign($domain);
        $name = $this->safeName(Normalizer::displayText($row['name'] ?? ''));
        if ($name === '') {
            $name = $this->safeName(trim($first . ' ' . $last));
        }
        if ($name === '') {
            $name = $this->localName($local);
        }

        $record = [
            'ID' => IdCodec::contactId(IdCodec::PERSON, $address),
            'name' => $this->foreign($name, $domain),
            'email:work' => [$address],
            '_type' => 'person',
        ];
        // a foreign entry of the global book carries no first/last name, so every listing mode falls
        // back to 'name', which holds the "(domain)" marker
        if ($first !== '' && !$isForeign) {
            $record['firstname'] = $first;
        }
        if ($last !== '' && !$isForeign) {
            $record['surname'] = $last;
        }
        if ($this->fields->userAliases()) {
            $other = [];
            foreach ($aliases as $alias) {
                $a = Normalizer::address($alias);
                if ($a !== null && $a !== $address && $this->validEmail($a) && !$this->isHidden($a)) {
                    $other[] = $a;
                }
            }
            if ($other !== []) {
                $record['email:other'] = array_values(array_unique($other));
            }
        }
        // text of another domain that the name template would show before the marker, or without it
        $withheld = $isForeign ? $this->withheldFromForeign() : [];
        if ($this->fields->has('department') && !in_array('department', $withheld, true)) {
            $dept = Normalizer::displayText($row['department'] ?? '');
            if ($dept !== '') {
                $record['department'] = $dept;
            }
        }
        if ($this->fields->has('phone') && !in_array('phone:work', $withheld, true)) {
            $phone = Normalizer::displayText($row['telephone'] ?? '', 64);
            if ($phone !== '') {
                $record['phone:work'] = [$phone];
            }
        }
        if ($this->fields->has('mobile') && !in_array('phone:mobile', $withheld, true)) {
            $mobile = Normalizer::displayText($row['mobile'] ?? '', 64);
            if ($mobile !== '') {
                $record['phone:mobile'] = [$mobile];
            }
        }
        if ($this->fields->has('birthday')) {
            $bday = (string) ($row['birthday'] ?? '');
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $bday, $m) && $m[1] !== '0001' && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $record['birthday'] = $m[1] . '-' . $m[2] . '-' . $m[3];
            }
        }
        $this->organization($record, $domain);
        return $record;
    }

    /**
     * Alias account ($kind 'a') or mailing list ('l').
     *
     * @param array<string, ?string> $row
     *
     * @return null|array<string, mixed>
     */
    public function mailList(string $kind, array $row): ?array
    {
        $address = $this->checkedAddress($row['address'] ?? null, $row['domain'] ?? null);
        if ($address === null) {
            return null;
        }
        [$local, $domain] = explode('@', $address, 2);
        $name = $this->safeName(Normalizer::displayText($row['name'] ?? ''));
        $record = [
            'ID' => IdCodec::contactId($kind, $address),
            'name' => $this->foreign($name !== '' ? $name : $this->localName($local), $domain),
            'email:work' => [$address],
            '_type' => 'person',
        ];
        $this->organization($record, $domain);
        return $record;
    }

    private function checkedAddress(?string $username, ?string $rowDomain): ?string
    {
        $address = Normalizer::address($username);
        $domain = Normalizer::domain($rowDomain);
        if ($address === null || $domain === null || !str_ends_with($address, '@' . $domain)) {
            Log::once('warning', 'scope', 'row_invalid', 'invalid address or a domain that does not match it in a directory row');
            return null;
        }
        if (!$this->book->inScope($domain) || $this->isHidden($address) || !$this->validEmail($address)) {
            // the SQL predicates should have excluded it: a collation / composition problem
            Log::once('error', 'scope', 'row_out_of_scope', 'row outside the book scope dropped (please report)', ['book' => $this->book->id]);
            return null;
        }
        return $address;
    }

    private function isHidden(string $address): bool
    {
        if (in_array($address, $this->hiddenAddresses, true)) {
            return true;
        }
        $local = substr($address, 0, (int) strrpos($address, '@'));
        return in_array($local, $this->hiddenLocalparts, true);
    }

    private function validEmail(string $address): bool
    {
        return !class_exists('rcube_utils') || \rcube_utils::check_email($address, false);
    }

    private function safeName(string $name): string
    {
        if (Normalizer::containsAt($name)) {
            // a display name that looks like an address could impersonate another recipient
            $cut = preg_split('/[@\x{FF20}\x{FE6B}]/u', $name, 2);
            // what is left never ends with an opening bracket either (Normalizer::displayText())
            $name = (string) preg_replace('/[\s(\[]+$/u', '', is_array($cut) ? (string) $cut[0] : '');
        }
        return $name;
    }

    /**
     * The name of an entry that has none: the local part of its address. That is text from the
     * directory like any name - an address may contain '&', '#', '=' and '?', so without the same
     * treatment "ceo&#64bank.example@..." or an encoded-word would reach the recipient name here.
     */
    private function localName(string $local): string
    {
        return $this->safeName(Normalizer::displayText($local));
    }

    /**
     * Record keys of free text that would stand BEFORE the name in a list name composed from the
     * given contactlist_name_template - or in place of it, when the template has no {name} at all.
     * Roundcube builds the name shown in autocomplete, group expansion and the contact lists from
     * that template; the "(domain)" marker of a foreign entry is part of its name, so any text of
     * the other domain in front of it could push the marker out of sight, and without {name} the
     * entry would be shown with that text and no marker.
     *
     * @return list<string>
     */
    public static function textBeforeName(mixed $template): array
    {
        if (!is_string($template) || preg_match_all('/\{([a-z]+)\}/', $template, $m) < 1) {
            return [];
        }
        $keys = [];
        foreach ($m[1] as $placeholder) {
            if ($placeholder === 'name') {
                break;
            }
            foreach (self::TEMPLATE_TEXT[$placeholder] ?? [] as $key) {
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }

    /**
     * What a foreign entry does not carry in this installation (see textBeforeName()): nothing with
     * the default template "{name}" and with every template that starts with the name.
     *
     * @return list<string>
     */
    private function withheldFromForeign(): array
    {
        return $this->withheld ??= self::textBeforeName(
            class_exists('rcube') ? \rcube::get_instance()->config->get('contactlist_name_template', '{name}') : '{name}'
        );
    }

    private function isForeign(string $domain): bool
    {
        return $this->book->markForeign && !in_array($domain, $this->book->homeDomains, true);
    }

    private function foreign(string $name, string $domain): string
    {
        return $this->isForeign($domain) ? '(' . $domain . ') ' . $name : $name;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function organization(array &$record, string $domain): void
    {
        if ($this->fields->has('organization') && isset($this->book->orgLabels[$domain])) {
            $record['organization'] = $this->book->orgLabels[$domain];
        }
    }
}
