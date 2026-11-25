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

use GalBridge\Db\Connection;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Entitlements;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\Limits;
use GalBridge\Directory\LookupBudget;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Repository;
use GalBridge\Directory\Schema;
use GalBridge\Directory\Viewer;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;

/**
 * Builds the books of one request: entitlement (from the resolved Viewer) and the AddressBook /
 * DeniedBook instances. Shared by the plugin and the integration tests, so both exercise the same
 * wiring.
 *
 * The action of the request decides a few things (canonicalAction()): 'autocomplete' (the
 * autocomplete switches), 'export' (complete-or-refused search exports, the refusal of an export of
 * a stale search, the 204 answer of a refused export), 'mailto' and 'group-expand' (a group is a full
 * set), and - in galbridge::init() - 'compose', 'bounce' and 'autocomplete' (the autocomplete
 * wiring). Every one of them is compared in the canonical form, never in the spelling of the request.
 */
final class Factory
{
    /** @var null|array<string, BookDefinition> */
    private ?array $books = null;

    /** books listed from the expired Viewer during a database outage (they answer "unavailable") */
    private bool $unavailable = false;

    /** @var callable(string): string */
    private $label;

    /** the action of the request in canonical form (canonicalAction()) */
    private readonly string $action;

    /** contacts the books of this request may still read one by one (galbridge_max_ids) */
    private ?LookupBudget $budget = null;

    /**
     * @param \Closure(): Connection                              $connection  lazy connection
     * @param callable(string): string                            $label       localized text
     * @param string                                              $action      the action of the request
     *                                                                         (any spelling)
     * @param null|\Closure(string, array<string, string>): void $notify      user-visible warning
     * @param bool                                                $sourceNamed the request names its
     *                                                                         address book itself
     *                                                                         (Roundcube's _source)
     */
    public function __construct(
        private readonly Config $config,
        private readonly ViewerResolver $resolver,
        private readonly \Closure $connection,
        callable $label,
        string $action = '',
        private readonly ?\Closure $notify = null,
        private readonly bool $sourceNamed = false,
    ) {
        $this->label = $label;
        $this->action = self::canonicalAction($action);
    }

    /**
     * An action name in the form Roundcube's dispatcher resolves it: it looks the handler up as the
     * class rcmail_action_<task>_<action with '-' as '_'>, and PHP class names ignore case - so
     * "EXPORT", "Group_Expand" and "group-expand" may all reach the same handler. Lower case, '-'
     * for '_'.
     */
    public static function canonicalAction(string $action): string
    {
        return strtolower(str_replace('_', '-', $action));
    }

    public function resolver(): ViewerResolver
    {
        return $this->resolver;
    }

    public static function isOwnId(string $id): bool
    {
        return $id === BookDefinition::DOMAIN || $id === BookDefinition::GLOBAL;
    }

    /**
     * Entitled books of the current user with a non-empty scope.
     *
     * @return array<string, BookDefinition>
     */
    public function books(): array
    {
        if ($this->books !== null) {
            return $this->books;
        }
        $viewer = $this->viewer();
        if ($viewer === null && Entitlements::enabledIds($this->config) !== []) {
            // database outage: keep listing the books this session had, answering "unavailable",
            // instead of letting them disappear silently (computed without touching the database)
            $viewer = $this->resolver->staleViewer();
            $this->unavailable = $viewer !== null;
        }
        return $this->books = $viewer === null ? [] : Entitlements::books($viewer, $this->config, $this->label);
    }

    /**
     * The instance for one of our source IDs: an AddressBook when entitled and usable, a DeniedBook
     * otherwise (never null - core would die with error 700).
     */
    public function create(string $id): \rcube_addressbook
    {
        $book = $this->books()[$id] ?? null;
        $instance = null;
        if ($book !== null && $this->unavailable) {
            $notify = $this->notify;
            return new DeniedBook($book->name, $notify === null ? null : static function () use ($notify): void {
                if (AddressBook::claimUnavailableNotice()) {
                    $notify('unavailable', []);
                }
            });
        }
        if ($book !== null) {
            try {
                $instance = $this->addressBook($book);
            } catch (GalBridgeException $e) {
                Log::once('error', 'book', $e->getReason(), 'book not available', ['book' => $id]);
            }
        }
        if ($instance === null) {
            if ($this->wiredRefusal($book, $id)) {
                // Roundcube's autocomplete asks every book the plugin itself added to
                // autocomplete_addressbooks (every enabled book, galbridge::wireAutocomplete()): a user
                // who is not entitled to one is no denied request - at normal level it would drown
                // the deliberate ones in noise
                Log::debug('book', 'denied_source: enabled book not entitled (autocomplete)', ['book' => $id]);
            } elseif (!in_array($this->resolver->status(), ['unresolved', 'ok'], true) || $book === null) {
                Log::once('info', 'book', 'denied_source', 'source not available for this user', ['book' => $id, 'status' => $this->resolver->status()]);
            }
            $instance = new DeniedBook(($this->label)($id === BookDefinition::DOMAIN ? 'domainbook' : 'globalbook'));
        }
        return $instance;
    }

    /**
     * Is this refusal the consequence of the plugin's own autocomplete wiring? An autocomplete request
     * that names no address book (_source) asks every source of autocomplete_addressbooks, where the
     * plugin puts every enabled book; the user's entitlement was resolved (status ok) and does not
     * cover this enabled book.
     */
    private function wiredRefusal(?BookDefinition $book, string $id): bool
    {
        return $book === null && !$this->sourceNamed && $this->action === 'autocomplete'
            && $this->resolver->status() === 'ok' && in_array($id, Entitlements::enabledIds($this->config), true);
    }

    /**
     * Effective grouping: department grouping needs the department field; search-only books (and
     * 'auto' search-only) have no groups, so a group can never list a tenant without a search.
     */
    public function grouping(BookDefinition $book): string
    {
        $viewer = $this->viewer() ?? $this->resolver->staleViewer();
        return self::effectiveGrouping($book, $viewer === null ? null : FieldMap::fromConfig($this->config, $viewer));
    }

    /**
     * The grouping a book really has (also used by bin/galbridge.sh diag --user).
     */
    public static function effectiveGrouping(BookDefinition $book, ?FieldMap $fields): string
    {
        if ($book->grouping === 'none' || $book->searchonly !== false) {
            return 'none';
        }
        if ($book->grouping === 'department' && ($fields === null || !$fields->has('department'))) {
            Log::once('warning', 'groups', 'grouping_disabled', 'department groups need the department field', ['book' => $book->id]);
            return 'none';
        }
        return $book->grouping;
    }

    private function viewer(): ?Viewer
    {
        return Entitlements::enabledIds($this->config) === [] ? null : $this->resolver->resolve();
    }

    /**
     * @throws GalBridgeException
     */
    private function addressBook(BookDefinition $book): ?AddressBook
    {
        $viewer = $this->viewer();
        if ($viewer === null) {
            return null;
        }
        $config = $this->config;
        $missing = Schema::missingRequired($config, $viewer->columns);
        if ($missing !== []) {
            Log::once('error', 'schema', 'column_missing', 'required vmail columns not readable: ' . implode(', ', $missing) . ' (see bin/galbridge.sh diag)');
            return null;
        }

        $fields = FieldMap::fromConfig($config, $viewer);
        $missingFields = $fields->missing($config->fields());
        if ($missingFields !== []) {
            Log::once('warning', 'schema', 'field_unavailable', 'configured fields unavailable (missing or not granted columns): ' . implode(', ', $missingFields));
        }

        $connection = ($this->connection)();
        // the fields an entry of another domain does not carry: ONE list for the records and the SQL
        // (a field that is not shown is neither matched nor sorted by)
        $withheld = $book->markForeign ? RecordMapper::foreignWithheld(RecordMapper::nameTemplate()) : [];
        $queries = QueryBuilder::forBook($config, $connection->driver(), $book, $viewer, $fields, $withheld);
        $limits = Limits::fromConfig($config);
        // ONE budget for every book of the request: core's copy action reads each contact of the
        // request with get_record(), whichever book it belongs to
        $this->budget ??= new LookupBudget($limits->maxIds);
        $resolver = $this->resolver;
        $repository = new Repository(
            $connection,
            $queries,
            new RecordMapper($book, $fields, $queries->hiddenAddresses(), $queries->hiddenLocalparts(), $withheld),
            $fields,
            $this->grouping($book),
            $limits->maxGroups,
            // a cached entitlement is confirmed by the first statement that reads directory data
            static fn (): bool => $resolver->confirm(),
        );

        return new AddressBook($book, $repository, $queries, $fields, $limits, $config->fingerprint(), $this->action, $this->label, $this->notify, $this->budget);
    }
}
