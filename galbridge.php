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
 * Read-only domain and global address books and groups for Roundcube 1.7, read from the SQL
 * (vmail) database of iRedMail 1.8 (MySQL/MariaDB and PostgreSQL editions).
 *
 * @author    Marek Królikowski (TaKeN)
 * @link      https://github.com/takenek
 * @license   GPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\Entitlements;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;

class galbridge extends rcube_plugin
{
    /**
     * settings: only to drop galbridge_* user preferences when settings are saved
     * (preferences_update) - nothing else runs there
     */
    public $task = 'mail|addressbook|settings';

    private rcmail $rc;

    private ?Config $config = null;

    private ?Factory $factory = null;

    private ?Connection $connection = null;

    /** the warnings of the DSN policy were written to the log in this request */
    private bool $dsnWarned = false;

    /** a GAL Bridge book refused the current export (a download cannot carry a message) */
    private bool $exportRefused = false;

    /**
     * Messages that mean "this export is not complete": limits, an unreadable entry and an
     * unavailable directory. An export is a download and cannot show any of them, so the
     * addressbook_export hook answers 204 instead of delivering a file that silently lacks entries.
     */
    private const EXPORT_REFUSALS = ['exportdisabled', 'exporttoolarge', 'grouptoolarge', 'toomanyids', 'incomplete', 'unavailable'];

    #[\Override]
    public function init(): void
    {
        require_once __DIR__ . '/lib/autoload.php';

        $this->rc = rcmail::get_instance();
        if (empty($this->rc->user->ID)) {
            return; // login page etc.
        }
        if ($this->rc->task === 'settings') {
            // no configuration, no texts, no database, no address book: only the preference guard
            $this->add_hook('preferences_update', [$this, 'preferences_update']);
            return;
        }

        // Roundcube logs error 527 for a plugin configuration file that does not load and goes on
        // without it; whether the policy in effect is the administrator's is decided by
        // Config::configUnreliable() (every book is disabled otherwise)
        $this->load_config();
        Log::setDebug($this->config()->bool('galbridge_debug'));
        $this->noteEnvironment();
        $this->add_texts('localization/');

        $this->add_hook('addressbooks_list', [$this, 'addressbooks_list']);
        $this->add_hook('addressbook_get', [$this, 'addressbook_get']);

        // the only readers of these two options: compose and the Redirect dialog render recipient
        // inputs through rcmail_sendmail::headers_output(), which calls
        // rcmail_action::autocomplete_init() (it reads autocomplete_addressbooks and
        // autocomplete_min_length), and mail/autocomplete reads autocomplete_addressbooks itself.
        // Never touch the database here. The action as it is now (core took it from the request's
        // _action; before the 'startup' and 'ready' hooks), in canonical form.
        if ($this->rc->task === 'mail' && in_array($this->action(), ['compose', 'bounce', 'autocomplete'], true)) {
            $this->wireAutocomplete();
        }
        if ($this->rc->task === 'mail') {
            // a directory entry is never a "known contact" (trusted sender, collected recipient)
            $this->add_hook('contact_exists', [$this, 'contact_exists']);
        }
        if ($this->rc->task === 'addressbook') {
            // for every action of the task: it acts only after a book refused an export of this
            // request, so it depends on no spelling of the action and on no plugin that changes it
            $this->add_hook('addressbook_export', [$this, 'addressbook_export']);
        }
    }

    /**
     * Plugin information shown in Roundcube's "About" page.
     *
     * @return array<string, string>
     */
    #[\Override]
    public static function info()
    {
        return [
            'name' => 'GAL Bridge',
            'vendor' => 'TaKeN.PL Usługi Informatyczne Marek Królikowski',
            'version' => '1.0.6',
            'license' => 'GPL-3.0-or-later',
            'uri' => 'https://github.com/takenek/galbridge',
        ];
    }

    public function config(): Config
    {
        if ($this->config === null) {
            $prefs = !empty($this->rc->user->ID) ? (array) $this->rc->user->get_prefs() : [];
            $this->config = new Config($this->rc->config, $prefs, null, $this->home);
        }
        return $this->config;
    }

    // ------------------------------------------------------------------ hooks

    public function addressbooks_list(array $p): array
    {
        try {
            $factory = $this->factory();
            if ($factory !== null) {
                foreach ($factory->books() as $id => $book) {
                    $p['sources'][$id] = [
                        'id' => $id,
                        'name' => rcube::Q($book->name),      // core prints source names raw
                        'readonly' => true,
                        'groups' => $factory->grouping($book) !== 'none',
                        // informational: Roundcube 1.7 does not read this key - autocomplete is
                        // wired through autocomplete_addressbooks (wireAutocomplete()) and
                        // enforced by the book itself (search(), list_groups())
                        'autocomplete' => $book->autocomplete,
                        'undelete' => false,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::exception('hook', $e);
        }
        return $p;
    }

    public function addressbook_get(array $p): array
    {
        $id = (string) ($p['id'] ?? '');
        if (!Factory::isOwnId($id)) {
            return $p;
        }
        // ALWAYS an instance for our IDs (writeable or not): without one core dies with error 700;
        // core itself returns null to callers that asked for a writable book
        try {
            $factory = $this->factory();
            $p['instance'] = $factory !== null ? $factory->create($id) : null;
        } catch (\Throwable $e) {
            Log::exception('hook', $e);
            $p['instance'] = null;
        }
        if (!$p['instance'] instanceof rcube_addressbook) {
            $p['instance'] = new GalBridge\DeniedBook($this->gettext($id === BookDefinition::DOMAIN ? 'domainbook' : 'globalbook'));
        }
        return $p;
    }

    /**
     * A refused export must not download an empty contacts.vcf: answer 204 No Content, so the browser
     * stays on the page and saves nothing (an export is a download - no message can be shown).
     */
    public function addressbook_export(array $p): array
    {
        if ($this->exportRefused && $this->rc->output instanceof rcube_output) {
            $this->rc->output->sendExit('', ['HTTP/1.1 204 No Content']);
        }
        return $p;
    }

    /**
     * No galbridge_* option is a user preference (Config ignores such preferences). Roundcube stores
     * the current value of every option listed in dont_override in the preferences of a user who
     * saves settings - for an option set through a ROUNDCUBE_GALBRIDGE_* variable that is the value,
     * the DSN with the database password included. Every galbridge_* key is removed from what is saved
     * and from the stored preferences it is merged with ('old'), so copies saved before disappear at
     * the user's next save as well. Registered in the Settings task only (where dont_override is
     * written); it touches nothing else.
     */
    public function preferences_update(array $p): array
    {
        foreach (['prefs', 'old'] as $part) {
            if (is_array($p[$part] ?? null)) {
                foreach (array_keys($p[$part]) as $key) {
                    if (is_string($key) && str_starts_with($key, 'galbridge_')) {
                        unset($p[$part][$key]);
                    }
                }
            }
        }
        return $p;
    }

    /**
     * rcmail::contact_exists() decides whether an address is a "known contact" (remote content of
     * trusted senders, collected recipients). Its sources include the collected_senders and
     * collected_recipients preferences without checking that they name a writable book - a
     * preference that names a GAL Bridge book would make every directory address a trusted sender.
     * The GAL Bridge books are removed from the sources (the order of the others is kept); nothing
     * else changes, and nothing touches the database.
     */
    public function contact_exists(array $p): array
    {
        if (is_array($p['sources'] ?? null)) {
            $p['sources'] = array_values(array_filter($p['sources'], static fn (mixed $s): bool => !Factory::isOwnId(is_scalar($s) ? (string) $s : '')));
        }
        return $p;
    }

    // ------------------------------------------------------------------ internals

    /**
     * The environment of the web server's PHP (a PHP-FPM pool: env[...], clear_env) is not the one
     * bin/galbridge.sh diag sees from a shell. The names of the ROUNDCUBE_GALBRIDGE_* variables that
     * override options here are logged once per session - and again when the set changes - so the
     * administrator can compare; never their values (one may be the DSN).
     */
    private function noteEnvironment(): void
    {
        $names = $this->config()->envOverrides();
        $noted = implode(',', $names);
        if ($names === []) {
            unset($_SESSION['galbridge_env_noted']);
            return;
        }
        if (($_SESSION['galbridge_env_noted'] ?? null) === $noted) {
            return;
        }
        $_SESSION['galbridge_env_noted'] = $noted;
        Log::info('config', 'env_override: ' . implode(', ', array_map(static fn (string $n): string => 'ROUNDCUBE_' . strtoupper($n), $names))
            . ' override options of this plugin in the PHP of the web server (names only)');
    }

    /**
     * The action of the request as it is NOW (another plugin may change it in the 'ready' hook, after
     * init()), in the canonical form of Roundcube's dispatcher (Factory::canonicalAction()).
     */
    private function action(): string
    {
        return Factory::canonicalAction((string) $this->rc->action);
    }

    private function factory(): ?Factory
    {
        if ($this->factory !== null) {
            return $this->factory;
        }
        $user = $this->rc->user;
        if (!$user instanceof rcube_user || empty($user->ID)) {
            return null;
        }
        $config = $this->config();
        $connection = function () use ($config): Connection {
            if ($this->connection === null) {
                $dsn = new Dsn(
                    $config->string('galbridge_db_dsn'),
                    $config,
                    (string) $config->coreValue('db_dsnw', ''),
                    $config->passwordPluginDsn(),
                );
                if (!$this->dsnWarned) {
                    // EVERY warning has to reach the log of the web process (diag runs in another
                    // environment) - each on a line of its own, once per request. Log::once() would
                    // write the first one only: it knows a reason code, not its text.
                    $this->dsnWarned = true;
                    foreach ($dsn->warnings() as $w) {
                        Log::warning('db', 'dsn_warning: ' . $w);
                    }
                }
                $this->connection = new Connection($dsn, $config->int('galbridge_db_timeout', 1, 30));
            }
            return $this->connection;
        };
        $rc = $this->rc;
        $ssl = $_SESSION['storage_ssl'] ?? null;
        $resolver = new ViewerResolver(
            $config,
            $connection,
            (int) $user->ID,
            (string) $user->get_username(),
            (string) ($user->data['mail_host'] ?? ''),
            (string) ($_SESSION['storage_host'] ?? ''),
            (int) ($_SESSION['storage_port'] ?? 0),
            is_string($ssl) ? strtolower($ssl) : '',
            // asked when the cached Viewer is renewed: Roundcube's own IMAP connection with the
            // credentials of the session (the one of this request when it is open already)
            static fn (): ?bool => self::imapCheck($rc),
        );
        $notify = function (string $label, array $vars) use ($rc): void {
            if ($this->action() === 'export' && in_array($label, self::EXPORT_REFUSALS, true)) {
                $this->exportRefused = true;
                return;
            }
            if ($rc->output instanceof rcmail_output_html || $rc->output instanceof rcmail_output_json) {
                $rc->output->show_message('galbridge.' . $label, 'warning', $vars);
            }
        };
        $sourceNamed = rcube_utils::get_input_string('_source', rcube_utils::INPUT_GPC) !== '';
        return $this->factory = new Factory($config, $resolver, $connection, fn (string $l): string => $this->gettext($l), $this->action(), $notify, $sourceNamed);
    }

    /**
     * Does the IMAP server still accept the credentials of this session? true when Roundcube's IMAP
     * connection of this request is open (it logged in with them) or opens now
     * (rcmail::storage_connect(): the session's host, port, TLS mode, user and password - a password
     * the user changed through the password plugin is the session's new one); false when the server
     * refuses them (ViewerResolver::imapVerdict()); null when that cannot be told.
     */
    public static function imapCheck(rcmail $rc): ?bool
    {
        try {
            $storage = $rc->get_storage();
            if ($storage->is_connected()) {
                return true;
            }
            $connected = $rc->storage_connect();
            $conn = $storage instanceof rcube_imap ? $storage->conn : null;
            return ViewerResolver::imapVerdict($connected, $conn instanceof rcube_imap_generic ? $conn->errornum : null, $conn instanceof rcube_imap_generic ? $conn->resultcode : null);
        } catch (\Throwable $e) {
            Log::exception('viewer', $e);
            return null;
        }
    }

    private function wireAutocomplete(): void
    {
        $config = $this->config();
        if ($config->booksDisabled()) {
            // a configuration error disables every book: no source that cannot exist is wired
            return;
        }
        $ids = [];
        foreach (Entitlements::enabledIds($config) as $id) {
            if ($config->bool($id === BookDefinition::DOMAIN ? 'galbridge_domain_autocomplete' : 'galbridge_global_autocomplete')) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        $sources = (array) $this->rc->config->get('autocomplete_addressbooks', ['sql']);
        $this->rc->config->set('autocomplete_addressbooks', array_values(array_unique(array_merge($sources, $ids))));

        // Roundcube's JS stops narrowing once a prefix returned nothing from every source: the client
        // minimum must not be shorter than the server minimum
        $min = $config->int('galbridge_min_search_length', 1, 10);
        if ((int) $this->rc->config->get('autocomplete_min_length', 1) < $min) {
            $this->rc->config->set('autocomplete_min_length', $min);
        }
    }
}
