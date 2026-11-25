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
 *    references, RFC 2047 encoded-words, template placeholders and the notations of Roundcube's
 *    suggestion list); a name that contains '@' is cut there (it could impersonate another
 *    address); an address follows the canonical rule (Normalizer::CANONICAL_ADDRESS: lower-case
 *    ASCII letters, digits and . _ + = - in the local part, an ASCII host name - nothing a sink of
 *    Roundcube interprets, no non-ASCII look-alike), the rule the SQL of the book applies to every
 *    row (QueryBuilder::canonicalAddressSql()), and it passes rcube_utils::check_email();
 *  - an entry of the global book from outside the viewer's home domains is marked "(domain) Name":
 *    the marker comes FIRST, so the ellipsis of a narrow recipient field can never cut it off. Such
 *    an entry carries no first / last name (every listing mode then uses the marked name) and none
 *    of the free-text fields that the administrator's contactlist_name_template would show before
 *    the name or without it (textBeforeName()); foreignWithheld() names these fields, and the SQL of
 *    the book neither matches nor sorts such an entry by them (QueryBuilder);
 *  - the row is re-checked against the address rule, the book's scope and the hidden lists (defence
 *    in depth: SQL offers only rows that pass, so a drop here means a defect or a row that changed
 *    between two statements); a violation is dropped and logged with a non-reversible tag of the
 *    row (row_invalid / row_out_of_scope row=...). Per-user alias addresses are subject to the same
 *    checks: an alias on a hidden list is never shown (the Repository drops alias rows that belong
 *    to a domain outside the book's scope before they get here).
 */
final class RecordMapper
{
    /**
     * Fields that carry free text of the entry's own domain and that a name template of Roundcube
     * can show: placeholder of the template => keys of the record ("{phone}" stands for every
     * phone:* value). The name is not listed: it carries the "(domain)" marker.
     */
    private const TEMPLATE_TEXT = ['department' => ['department'], 'phone' => ['phone:work', 'phone:mobile']];

    /** @var null|list<string> fields a foreign entry does not carry (foreignWithheld()) */
    private ?array $withheld;

    /**
     * @param list<string>      $hiddenAddresses
     * @param list<string>      $hiddenLocalparts
     * @param null|list<string> $foreignWithheld  foreignWithheld() of the name template (Factory
     *                                            passes it, the same list as the query builder
     *                                            gets); null = computed from Roundcube's template
     */
    public function __construct(
        private readonly BookDefinition $book,
        private readonly FieldMap $fields,
        private readonly array $hiddenAddresses,
        private readonly array $hiddenLocalparts,
        ?array $foreignWithheld = null,
    ) {
        $this->withheld = $foreignWithheld;
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
        if (self::coreEmpty($name)) {
            $name = $this->safeName(trim($first . ' ' . $last));
        }
        if (self::coreEmpty($name)) {
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
                // the rule of every address of the directory (the SQL of the alias rows applies it too)
                $a = Normalizer::canonicalAddress($alias);
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
            // department and phones reach recipient names too (a contactlist_name_template such as
            // "{name} ({department})"): the same anti-impersonation rule as for the name
            $dept = $this->safeName(Normalizer::displayText($row['department'] ?? ''));
            if ($dept !== '') {
                $record['department'] = $dept;
            }
        }
        if ($this->fields->has('phone') && !in_array('phone', $withheld, true)) {
            $phone = $this->safeName(Normalizer::displayText($row['telephone'] ?? '', 64));
            if ($phone !== '') {
                $record['phone:work'] = [$phone];
            }
        }
        if ($this->fields->has('mobile') && !in_array('mobile', $withheld, true)) {
            $mobile = $this->safeName(Normalizer::displayText($row['mobile'] ?? '', 64));
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
            'name' => $this->foreign(!self::coreEmpty($name) ? $name : $this->localName($local), $domain),
            'email:work' => [$address],
            '_type' => 'person',
        ];
        $this->organization($record, $domain);
        return $record;
    }

    /**
     * The address of a row, re-checked (the SQL of the book applies the same rules, so a row that
     * fails here is a defect or changed between two statements of the request):
     *  - row_invalid (WARN): the address does not follow the canonical rule, does not belong to the
     *    domain column, or fails Roundcube's e-mail check (a tripwire: the rule is stricter);
     *  - row_out_of_scope (ERROR): a domain outside the book's scope or an address on a hidden list.
     * Both name the row by a non-reversible tag (Log::tag() of the raw value - bin/galbridge.sh diag
     * prints the same tag next to the entries it does not offer).
     */
    private function checkedAddress(?string $username, ?string $rowDomain): ?string
    {
        $address = Normalizer::canonicalAddress($username);
        $domain = Normalizer::domain($rowDomain);
        $tag = ['book' => $this->book->id, 'row' => Log::tag((string) $username)];
        if ($address === null || $domain === null || !str_ends_with($address, '@' . $domain) || !$this->validEmail($address)) {
            Log::once('warning', 'scope', 'row_invalid', 'a directory row whose address does not follow the address rule or does not belong to its domain column dropped', $tag);
            return null;
        }
        if (!$this->book->inScope($domain) || $this->isHidden($address)) {
            // the SQL predicates should have excluded it: a collation / composition problem
            Log::once('error', 'scope', 'row_out_of_scope', 'row outside the book scope or on a hidden list dropped (please report)', $tag);
            return null;
        }
        return $address;
    }

    /**
     * Does Roundcube treat this text as no name? Its name composition takes '0' for empty as well
     * (the empty() and trim() fallbacks of rcube_addressbook::compose_list_name() and
     * compose_display_name()): a name '0' would let the bare address become the recipient text.
     */
    private static function coreEmpty(string $text): bool
    {
        return trim($text) === '' || $text === '0';
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
        // a display name that looks like an address could impersonate another recipient
        return Normalizer::cutAtSign($name);
    }

    /**
     * The name of an entry that has none: the local part of its address, treated like any name of
     * the directory. The canonical rule leaves only letters, digits and . _ + = - in it, all of
     * which survive displayText() ('=' at either end becomes U+FF1D), so the name is never empty;
     * a local part '0' leaves core to show the address itself - the canonical, delivered address.
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
     * The fields (galbridge_fields names) an entry of another domain does not carry in a book that
     * marks foreign entries, under the given contactlist_name_template: first and last name always
     * (every listing mode then uses the marked name), department and the phones when the template
     * shows them before {name} or has no {name} (textBeforeName()). The ONE list for the record and
     * for the SQL of the book, which neither matches nor sorts a foreign entry by these fields - a
     * field that is not shown is no search criterion either.
     *
     * @return list<string>
     */
    public static function foreignWithheld(mixed $template): array
    {
        $keys = self::textBeforeName($template);
        $fields = ['firstname', 'surname'];
        foreach (['department' => 'department', 'phone:work' => 'phone', 'phone:mobile' => 'mobile'] as $key => $field) {
            if (in_array($key, $keys, true)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Roundcube's contactlist_name_template (what core composes list names with).
     */
    public static function nameTemplate(): mixed
    {
        return class_exists('rcube') ? \rcube::get_instance()->config->get('contactlist_name_template', '{name}') : '{name}';
    }

    /**
     * What a foreign entry does not carry in this installation (foreignWithheld()).
     *
     * @return list<string>
     */
    private function withheldFromForeign(): array
    {
        return $this->withheld ??= self::foreignWithheld(self::nameTemplate());
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
