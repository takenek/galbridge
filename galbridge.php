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
 * Read-only domain and global address books and groups for Roundcube 1.7.7, read from the SQL
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
    public $task = 'mail|addressbook';

    private rcmail $rc;

    private ?Config $config = null;

    private ?Factory $factory = null;

    private ?Connection $connection = null;

    #[\Override]
    public function init(): void
    {
        require_once __DIR__ . '/lib/autoload.php';

        $this->rc = rcmail::get_instance();
        if (empty($this->rc->user->ID)) {
            return; // login page etc.
        }

        $this->load_config();
        Log::setDebug($this->config()->bool('galbridge_debug'));
        $this->add_texts('localization/');

        $this->add_hook('addressbooks_list', [$this, 'addressbooks_list']);
        $this->add_hook('addressbook_get', [$this, 'addressbook_get']);

        // the only readers of these two options; never touch the database here
        if ($this->rc->task === 'mail' && in_array((string) $this->rc->action, ['compose', 'autocomplete'], true)) {
            $this->wireAutocomplete();
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
            'version' => '1.0.0',
            'license' => 'GPL-3.0-or-later',
            'uri' => 'https://github.com/takenek/galbridge',
        ];
    }

    public function config(): Config
    {
        if ($this->config === null) {
            $prefs = !empty($this->rc->user->ID) ? (array) $this->rc->user->get_prefs() : [];
            $this->config = new Config($this->rc->config, $prefs);
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

    // ------------------------------------------------------------------ internals

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
                    (string) $config->adminCore('db_dsnw', ''),
                    $config->passwordPluginDsn(),
                );
                foreach ($dsn->warnings() as $w) {
                    Log::once('warning', 'db', 'dsn_warning', $w);
                }
                $this->connection = new Connection($dsn, $config->int('galbridge_db_timeout', 1, 30));
            }
            return $this->connection;
        };
        $resolver = new ViewerResolver(
            $config,
            $connection,
            (int) $user->ID,
            (string) $user->get_username(),
            (string) ($user->data['mail_host'] ?? ''),
            (string) ($_SESSION['storage_host'] ?? ''),
        );
        $rc = $this->rc;
        $notify = static function (string $label, array $vars) use ($rc): void {
            if ($rc->output instanceof rcmail_output_html || $rc->output instanceof rcmail_output_json) {
                $rc->output->show_message('galbridge.' . $label, 'warning', $vars);
            }
        };
        return $this->factory = new Factory($config, $resolver, $connection, fn (string $l): string => $this->gettext($l), (string) $this->rc->action, $notify);
    }

    private function wireAutocomplete(): void
    {
        $config = $this->config();
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
