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

namespace GalBridge\Db;

use GalBridge\Config;
use GalBridge\Exception\GalBridgeException;

/**
 * Policy check of galbridge_db_dsn before anything connects.
 *
 *  - drivers mysql / mysqli / pgsql only (sqlite only when GALBRIDGE_ALLOW_SQLITE is defined by the
 *    test bootstrap); the PostgreSQL 'service' parameter is refused (it would move user and host
 *    out of sight of these checks);
 *  - the role must not be one of galbridge_db_refused_users (iRedMail's own, broader roles), the
 *    Roundcube db_dsnw user or the password plugin's DSN user;
 *  - a non-loopback host needs VERIFIED TLS (PostgreSQL sslmode=verify-ca|verify-full, MySQL
 *    ca/capath + verify_server_cert=1) unless galbridge_db_allow_plaintext is set;
 *  - a connect timeout is injected when the DSN has none (PDO otherwise waits up to
 *    default_socket_timeout, or forever with libpq, and a dead directory server would block every
 *    webmail request).
 *
 * The DSN never leaves this object except as the string handed to rcube_db::factory().
 */
final class Dsn
{
    /** @var array<string, mixed> */
    private array $parsed;

    private string $driver;

    private string $effective;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @throws GalBridgeException 'config_invalid' with an internal reason (never containing the password)
     */
    public function __construct(string $dsn, Config $config, ?string $roundcubeDsn = null, ?string $passwordPluginDsn = null)
    {
        if (trim($dsn) === '') {
            throw new GalBridgeException('config_invalid', 'galbridge_db_dsn is not configured');
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        $driver = strtolower((string) ($parsed['phptype'] ?? ''));
        $driver = $driver === 'mysqli' ? 'mysql' : $driver;
        $allowed = ['mysql', 'pgsql'];
        if (defined('GALBRIDGE_ALLOW_SQLITE') && constant('GALBRIDGE_ALLOW_SQLITE') === true) {
            $allowed[] = 'sqlite';
        }
        if (!in_array($driver, $allowed, true)) {
            throw new GalBridgeException('config_invalid', 'unsupported database driver (mysql, mysqli or pgsql)');
        }
        if (isset($parsed['service'])) {
            throw new GalBridgeException('config_invalid', "the PostgreSQL 'service' parameter is not supported");
        }

        if ($driver !== 'sqlite') {
            $user = strtolower((string) ($parsed['username'] ?? ''));
            if ($user === '') {
                throw new GalBridgeException('config_invalid', 'the DSN has no user name');
            }
            $refused = $config->refusedUsers();
            foreach ([$roundcubeDsn, $passwordPluginDsn] as $other) {
                if (is_string($other) && $other !== '') {
                    $u = strtolower((string) (\rcube_db::parse_dsn($other)['username'] ?? ''));
                    if ($u !== '') {
                        $refused[] = $u;
                    }
                }
            }
            if (in_array($user, $refused, true)) {
                throw new GalBridgeException('config_invalid', 'refused database role "' . $user . '": create the dedicated read-only role (bin/galbridge.sh grants)');
            }

            if (!self::isLoopback($parsed) && !self::verifiedTls($driver, $parsed)) {
                if (!$config->bool('galbridge_db_allow_plaintext')) {
                    throw new GalBridgeException('config_invalid', 'remote database host without verified TLS (pgsql: sslmode=verify-full; mysql: ca=...&verify_server_cert=1)');
                }
                $this->warnings[] = 'remote database host without verified TLS (galbridge_db_allow_plaintext)';
            }
        }

        if (empty($parsed['timeout']) && $driver !== 'sqlite') {
            $timeout = $config->int('galbridge_db_connect_timeout', 1, 30);
            $dsn .= (str_contains($dsn, '?') ? '&' : '?') . 'timeout=' . $timeout;
            $parsed['timeout'] = $timeout;
        }

        $this->parsed = $parsed;
        $this->driver = $driver;
        $this->effective = $dsn;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * The DSN string handed to rcube_db::factory() (with the injected connect timeout).
     */
    public function effective(): string
    {
        return $this->effective;
    }

    public function user(): string
    {
        return (string) ($this->parsed['username'] ?? '');
    }

    public function database(): string
    {
        return (string) ($this->parsed['database'] ?? '');
    }

    /**
     * Host description without credentials, for diag.
     */
    public function describe(): string
    {
        if ($this->driver === 'sqlite') {
            return 'sqlite (test only)';
        }
        $where = isset($this->parsed['socket']) ? 'unix(' . $this->parsed['socket'] . ')'
            : ((string) ($this->parsed['hostspec'] ?? 'localhost')) . (isset($this->parsed['port']) ? ':' . $this->parsed['port'] : '');
        return $this->driver . '://' . $this->user() . '@' . $where . '/' . $this->database();
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string, mixed> $parsed
     */
    public static function isLoopback(array $parsed): bool
    {
        if (($parsed['protocol'] ?? 'tcp') === 'unix' || !empty($parsed['socket'])) {
            return true;
        }
        $host = strtolower(trim((string) ($parsed['hostspec'] ?? 'localhost'), '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }

    /**
     * @param array<string, mixed> $parsed
     */
    public static function verifiedTls(string $driver, array $parsed): bool
    {
        if ($driver === 'pgsql') {
            return in_array(strtolower((string) ($parsed['sslmode'] ?? '')), ['verify-ca', 'verify-full'], true);
        }
        $verify = strtolower((string) ($parsed['verify_server_cert'] ?? ''));
        return (!empty($parsed['ca']) || !empty($parsed['capath'])) && in_array($verify, ['1', 'true', 'on', 'yes'], true);
    }
}
