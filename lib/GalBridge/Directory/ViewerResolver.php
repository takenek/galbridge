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

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * Resolves the Viewer of the current session.
 *
 * 1. IMAP-host binding. A Roundcube that connects users to an IMAP server chosen by the client
 *    (a user-typed host, or an imap_host template with %d/%n, which come from the client's Host
 *    header) must never map such a login to a directory tenant: a user who logs in through their
 *    own IMAP server as ceo@tenant-a.example would otherwise get tenant A's directory.
 *    The allowed hosts are galbridge_imap_hosts, or - only when that is empty - the single
 *    imap_host of the ADMINISTRATOR configuration when it has no placeholder. Both the session's
 *    storage_host and the user's stored mail_host must match.
 * 2. The login is looked up in vmail with the same predicates iRedMail uses for logins; the home
 *    domain comes from that row.
 * 3. The result (and the schema/privilege probe) is cached in $_SESSION['galbridge'], bound to the
 *    user id, login, IMAP host and configuration fingerprint, for galbridge_scope_ttl seconds.
 *    A database outage is never cached as "no entitlement".
 */
final class ViewerResolver
{
    private const CACHE_VERSION = 1;

    /** @var false|null|Viewer per-request memo (false = not resolved yet) */
    private Viewer|false|null $memo = false;

    /** the last good Viewer of this session after its TTL expired (advertising during an outage only) */
    private ?Viewer $stale = null;

    private string $status = 'unresolved';

    /**
     * @param \Closure(): Connection $connection
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $connection,
        private readonly int $userId,
        private readonly string $username,
        private readonly string $mailHost,
        private readonly string $storageHost,
    ) {
    }

    /**
     * The allowed IMAP hosts (lower case), or null when the plugin must stay disabled.
     *
     * @return null|list<string>
     */
    public static function allowedImapHosts(Config $config): ?array
    {
        $explicit = $config->imapHosts();
        if ($explicit === null) {
            return null;
        }
        if ($explicit !== []) {
            return $explicit;
        }
        $imapHost = $config->adminCore('imap_host', $config->adminCore('default_host'));
        if (!is_string($imapHost) || trim($imapHost) === '' || str_contains($imapHost, '%')) {
            return null; // array, empty (user-typed) or templated host: explicit list required
        }
        $parsed = \rcube_utils::parse_host_uri(trim($imapHost), 143, 993);
        $host = is_array($parsed) ? (string) $parsed[0] : '';
        $host = strtolower(\rcube_utils::idn_to_ascii(trim($host, '[]')));
        return $host === '' ? null : [$host];
    }

    public static function normaliseHost(string $host): string
    {
        $parsed = \rcube_utils::parse_host_uri(trim($host), 143, 993);
        $h = is_array($parsed) ? (string) $parsed[0] : trim($host);
        return strtolower(\rcube_utils::idn_to_ascii(trim($h, '[]')));
    }

    /**
     * 'ok', 'disabled' (IMAP host policy), 'host_mismatch', 'no_mailbox', 'db_down', 'db_error',
     * 'config_invalid', 'column_missing', 'role_too_broad', 'invalid_login' or 'unresolved'.
     */
    public function status(): string
    {
        return $this->status;
    }

    public function resolve(): ?Viewer
    {
        if ($this->memo !== false) {
            return $this->memo;
        }

        $allowed = self::allowedImapHosts($this->config);
        if ($allowed === null) {
            $this->status = 'disabled';
            Log::warning('viewer', 'plugin disabled: imap_host is templated, user-selectable or galbridge_imap_hosts is invalid; set galbridge_imap_hosts');
            return $this->memo = null;
        }
        $storage = self::normaliseHost($this->storageHost);
        $mailHost = self::normaliseHost($this->mailHost);
        if (!in_array($storage, $allowed, true) || !in_array($mailHost, $allowed, true)) {
            $this->status = 'host_mismatch';
            // server names, not personal data: what the administrator needs for galbridge_imap_hosts
            Log::once('warning', 'viewer', 'host_mismatch', 'session IMAP host is not an allowed directory host', [
                'host' => $storage, 'mail_host' => $mailHost, 'allowed' => implode(' ', $allowed),
            ]);
            return $this->memo = null;
        }

        $login = Normalizer::address($this->username);
        if ($login === null) {
            $this->status = 'invalid_login';
            return $this->memo = null;
        }

        $fingerprint = $this->config->fingerprint();
        $ttl = $this->config->int('galbridge_scope_ttl', 30, 3600);
        $cache = $_SESSION['galbridge'] ?? null;
        if (is_array($cache)
            && ($cache['v'] ?? null) === self::CACHE_VERSION
            && ($cache['uid'] ?? null) === $this->userId
            && ($cache['login'] ?? null) === $login
            && ($cache['host'] ?? null) === $storage
            && ($cache['fp'] ?? null) === $fingerprint
            && is_int($cache['at'] ?? null)
            && is_string($cache['status'] ?? null)) {
            $viewer = is_array($cache['viewer'] ?? null) ? Viewer::fromArray($cache['viewer']) : null;
            if (time() - $cache['at'] < $ttl) {
                $this->status = $cache['status'];
                return $this->memo = ($this->status === 'ok' ? $viewer : null);
            }
            $this->stale = $cache['status'] === 'ok' ? $viewer : null;
        }

        try {
            $viewer = $this->lookup($login);
        } catch (GalBridgeException $e) {
            $this->status = match ($e->getReason()) {
                'db_down' => 'db_down',
                'config_invalid' => 'config_invalid',
                default => 'db_error',
            };
            if ($this->status === 'config_invalid') {
                // Dsn messages never contain the password (the role name at most)
                Log::once('error', 'config', 'config_invalid', $e->getMessage());
            }
            return $this->memo = null; // never cached as an entitlement decision
        }

        $_SESSION['galbridge'] = [
            'v' => self::CACHE_VERSION,
            'uid' => $this->userId,
            'login' => $login,
            'host' => $storage,
            'fp' => $fingerprint,
            'at' => time(),
            'status' => $this->status,
            'viewer' => $viewer?->toArray(),
        ];
        return $this->memo = $viewer;
    }

    /**
     * The expired Viewer of this session (same user, login, host and configuration), available only
     * after resolve() failed with a database error: the books can stay listed (answering "directory
     * unavailable") instead of silently disappearing. Never used to read directory data.
     */
    public function staleViewer(): ?Viewer
    {
        return in_array($this->status, ['db_down', 'db_error'], true) ? $this->stale : null;
    }

    /**
     * Look a (normalised) login up in vmail: probe, home domain, address set. No IMAP-host binding
     * and no caching - resolve() adds both; bin/galbridge.sh diag --user uses this directly.
     *
     * @throws GalBridgeException
     */
    public function lookup(string $login): ?Viewer
    {
        $conn = ($this->connection)();
        $columns = $conn->visibleColumns();

        if ($conn->driver() !== 'sqlite' && array_intersect(Connection::SECRET_COLUMNS, $columns['mailbox'] ?? []) !== []) {
            $this->status = 'role_too_broad';
            Log::error('db', 'role_too_broad: the configured database role can read secret vmail columns (e.g. mailbox.password); use the dedicated role from bin/galbridge.sh grants');
            return null;
        }

        $missing = Schema::missingRequired($this->config, $columns);
        if ($missing !== []) {
            $this->status = 'column_missing';
            Log::once('error', 'schema', 'column_missing', 'required vmail columns not readable: ' . implode(', ', $missing) . ' (see bin/galbridge.sh diag)');
            return null;
        }
        $hideExpired = $this->config->bool('galbridge_hide_expired');

        $sql = 'SELECT m.username, m.`domain` FROM `mailbox` m JOIN `domain` d ON d.`domain` = m.`domain`'
            . ' WHERE m.username = ? AND m.active = 1 AND d.active = 1 AND d.backupmx = 0';
        $params = [$login];
        if ($hideExpired) {
            $sql .= ' AND m.expired > ? AND d.expired > ?';
            $now = gmdate('Y-m-d H:i:s');
            $params[] = $now;
            $params[] = $now;
        }
        $rows = $conn->select($sql, $params);
        $home = isset($rows[0]) ? Normalizer::domain($rows[0]['domain'] ?? null) : null;
        if ($home === null) {
            $this->status = 'no_mailbox';
            return null;
        }

        $addresses = [$login];
        foreach ($conn->select('SELECT f.address FROM `forwardings` f WHERE f.forwarding = ? AND f.is_alias = 1 AND f.active = 1', [$login]) as $row) {
            $a = Normalizer::address($row['address'] ?? null);
            if ($a !== null) {
                $addresses[] = $a;
            }
        }

        $this->status = 'ok';
        return new Viewer($login, $home, array_values(array_unique($addresses)), $columns);
    }
}
