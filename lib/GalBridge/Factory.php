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
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Repository;
use GalBridge\Directory\Viewer;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;

/**
 * Builds the books of one request: entitlement (from the resolved Viewer) and the AddressBook /
 * DeniedBook instances. Shared by the plugin and the integration tests, so both exercise the same
 * wiring.
 */
final class Factory
{
    /** @var null|array<string, BookDefinition> */
    private ?array $books = null;

    /** @var callable(string): string */
    private $label;

    /**
     * @param \Closure(): Connection                              $connection lazy connection
     * @param callable(string): string                            $label      localized text
     * @param null|\Closure(string, array<string, string>): void $notify     user-visible warning
     */
    public function __construct(
        private readonly Config $config,
        private readonly ViewerResolver $resolver,
        private readonly \Closure $connection,
        callable $label,
        private readonly string $action = '',
        private readonly ?\Closure $notify = null,
    ) {
        $this->label = $label;
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
        if ($book !== null) {
            try {
                $instance = $this->addressBook($book);
            } catch (GalBridgeException $e) {
                Log::once('error', 'book', $e->getReason(), 'book not available', ['book' => $id]);
            }
        }
        if ($instance === null) {
            if (!in_array($this->resolver->status(), ['unresolved', 'ok'], true) || $book === null) {
                Log::once('info', 'book', 'denied_source', 'source not available for this user', ['book' => $id, 'status' => $this->resolver->status()]);
            }
            $instance = new DeniedBook(($this->label)($id === BookDefinition::DOMAIN ? 'domainbook' : 'globalbook'));
        }
        return $instance;
    }

    /**
     * Effective grouping: department grouping needs the department field; search-only books (and
     * 'auto' search-only) have no groups, so a group can never list a tenant without a search.
     */
    public function grouping(BookDefinition $book): string
    {
        if ($book->grouping === 'none' || $book->searchonly !== false) {
            return 'none';
        }
        if ($book->grouping === 'department') {
            $viewer = $this->viewer();
            if ($viewer === null || !FieldMap::fromConfig($this->config, $viewer)->has('department')) {
                Log::once('warning', 'groups', 'grouping_disabled', 'department groups need the department field', ['book' => $book->id]);
                return 'none';
            }
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
        $hideExpired = $config->bool('galbridge_hide_expired');
        $required = ['mailbox' => ['username', 'name', 'domain', 'active', 'enabledeliver'], 'domain' => ['domain', 'active', 'backupmx']];
        if ($hideExpired) {
            $required['mailbox'][] = 'expired';
            $required['domain'][] = 'expired';
        }
        foreach ($required as $table => $cols) {
            foreach ($cols as $col) {
                if (!$viewer->hasColumn($table, $col)) {
                    Log::once('error', 'schema', 'column_missing', 'required vmail column not readable: ' . $table . '.' . $col . ' (see bin/galbridge.sh diag)');
                    return null;
                }
            }
        }

        $fields = FieldMap::fromConfig($config, $viewer);
        $missing = $fields->missing($config->fields());
        if ($missing !== []) {
            Log::once('warning', 'schema', 'field_unavailable', 'configured fields unavailable (missing or not granted columns): ' . implode(', ', $missing));
        }

        $connection = ($this->connection)();
        $hidden = $config->hiddenAddresses() ?? [];
        $localparts = $config->hiddenLocalparts() ?? [];
        $queries = new QueryBuilder(
            driver: $connection->driver(),
            book: $book,
            viewer: $viewer,
            fields: $fields,
            hideExpired: $hideExpired,
            now: gmdate('Y-m-d H:i:s'),
            hiddenAddresses: $hidden,
            hiddenLocalparts: $localparts,
            visibilityColumn: (string) $config->visibilityColumn(),
            includeAliases: $config->bool('galbridge_include_aliases'),
            includeLists: $config->bool('galbridge_include_mailing_lists'),
            listPolicy: $config->choice('galbridge_list_policy', Config::LIST_POLICIES),
            minLength: $config->int('galbridge_min_search_length', 1, 10),
            maxLength: $config->int('galbridge_max_search_length', 16, 255),
        );
        $limits = Limits::fromConfig($config);
        $repository = new Repository(
            $connection,
            $queries,
            new RecordMapper($book, $fields, $hidden, $localparts),
            $fields,
            $this->grouping($book),
            $limits->maxGroups,
        );

        return new AddressBook($book, $repository, $queries, $fields, $limits, $config->fingerprint(), $this->action, $this->label, $this->notify);
    }
}
