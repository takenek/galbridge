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

use GalBridge\Exception\GalBridgeException;
use GalBridge\Log;

/**
 * Lazy, read-only connection to the iRedMail vmail database.
 *
 *  - connects on the first query only (the book is instantiated on every opened message);
 *  - 'ignore_errors' is set, so Roundcube never logs SQL text with inlined search values; errors are
 *    logged here with the SQLSTATE only (driver text with galbridge_debug);
 *  - after connecting: read-only session and a statement timeout (backstop to the role limits);
 *  - a failed connection is memoised for the request and for OUTAGE_SECONDS in the session, so a
 *    dead directory server does not block every webmail request;
 *  - vmail table names are never passed through rcube_db::table_name() (it would add db_prefix).
 */
final class Connection
{
    public const OUTAGE_SECONDS = 30;

    /** the vmail tables GAL Bridge may read (column probe) */
    public const TABLES = ['mailbox', 'domain', 'alias', 'forwardings', 'maillists', 'moderators', 'maillist_owners'];

    /** columns whose visibility means the role is too broad (fail closed) */
    public const SECRET_COLUMNS = ['password', 'recovery_email', 'allow_nets'];

    private ?\rcube_db $db = null;

    private bool $failed = false;

    private string $flavour = '';

    private string $lastError = '';

    /** @var null|\Closure(string): \rcube_db */
    private ?\Closure $factory;

    /**
     * @param null|\Closure(string): \rcube_db $factory rcube_db factory (tests)
     */
    public function __construct(private readonly Dsn $dsn, private readonly int $statementTimeout = 3, ?\Closure $factory = null)
    {
        $this->factory = $factory;
    }

    public function driver(): string
    {
        return $this->dsn->driver();
    }

    /**
     * 'mariadb', 'mysql', 'pgsql' or 'sqlite' (after connecting).
     */
    public function flavour(): string
    {
        $this->db();
        return $this->flavour;
    }

    /**
     * Run a SELECT and return all rows. $offset/$limit use rcube_db::limitquery().
     *
     * @param list<scalar|null> $params
     *
     * @return list<array<string, ?string>>
     *
     * @throws GalBridgeException 'db_down' | 'db_error'
     */
    public function select(string $sql, array $params = [], ?int $offset = null, ?int $limit = null): array
    {
        $db = $this->db();
        $res = $limit !== null ? $db->limitquery($sql, (int) $offset, $limit, ...$params) : $db->query($sql, ...$params);
        if ($res === false || $db->is_error($res)) {
            $info = $db->error_info();
            $state = is_array($info) ? (string) ($info[0] ?? '') : '';
            Log::error('db', 'db_error', ['sqlstate' => $state]);
            Log::debug('db', 'query failed', ['error' => is_array($info) ? (string) ($info[2] ?? '') : '']);
            throw new GalBridgeException('db_error', 'query failed (SQLSTATE ' . $state . ')');
        }
        $rows = [];
        while ($row = $db->fetch_assoc($res)) {
            $rows[] = array_map(static fn ($v) => $v === null ? null : (string) $v, $row);
        }
        return $rows;
    }

    /**
     * SQLSTATE of a statement that is expected to fail (diag privilege tripwires); '' if it succeeded.
     */
    public function probeFailure(string $sql): string
    {
        $db = $this->db();
        $res = $db->query($sql);
        if ($res === false || $db->is_error($res)) {
            $info = $db->error_info();
            return is_array($info) && ($info[0] ?? '') !== '' ? (string) $info[0] : 'ERROR';
        }
        return '';
    }

    /**
     * Columns of the vmail tables that this role can see. MariaDB/MySQL and PostgreSQL list in
     * information_schema only the columns the current role holds a privilege on, so the result means
     * "present AND granted". SQLite (tests) has no privileges and lists everything.
     *
     * @return array<string, list<string>> table => columns (lower case)
     */
    public function visibleColumns(): array
    {
        $out = array_fill_keys(self::TABLES, []);
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            foreach (self::TABLES as $t) {
                foreach ($this->select('PRAGMA table_info(' . $t . ')') as $row) {
                    $out[$t][] = strtolower((string) $row['name']);
                }
            }
            return $out;
        }
        $in = implode(', ', array_fill(0, count(self::TABLES), '?'));
        $sql = $driver === 'pgsql'
            ? 'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (' . $in . ')'
            : 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $in . ')';
        foreach ($this->select($sql, self::TABLES) as $row) {
            $t = strtolower((string) ($row['t'] ?? ''));
            if (isset($out[$t])) {
                $out[$t][] = strtolower((string) ($row['c'] ?? ''));
            }
        }
        return $out;
    }

    /**
     * The sanitised reason of the last failed connect ('' if none), for bin/galbridge.sh diag: in the
     * CLI Roundcube writes the driver message only to stderr, not to its errors log.
     */
    public function lastError(): string
    {
        return $this->lastError;
    }

    public function close(): void
    {
        if ($this->db !== null) {
            $this->db->closeConnection();
            $this->db = null;
        }
    }

    /**
     * @throws GalBridgeException 'db_down'
     */
    private function db(): \rcube_db
    {
        if ($this->db !== null) {
            return $this->db;
        }
        if ($this->failed || (int) ($_SESSION['galbridge_down_until'] ?? 0) > time()) {
            throw new GalBridgeException('db_down', 'vmail database unavailable (memoised)');
        }

        $db = $this->factory !== null ? ($this->factory)($this->dsn->effective()) : \rcube_db::factory($this->dsn->effective(), '', false);
        $db->set_option('ignore_errors', true);
        $db->db_connect('w');
        if (!$db->is_connected()) {
            // the driver text (role, host, reason - never the password); Log::sanitize() also masks a
            // DSN password should a driver ever echo one
            $err = $db->is_error();
            $this->lastError = Log::sanitize(is_string($err) ? $err : '');
            $this->failed = true;
            $_SESSION['galbridge_down_until'] = time() + self::OUTAGE_SECONDS;
            Log::error('db', 'db_down', ['retry_in' => self::OUTAGE_SECONDS]);
            throw new GalBridgeException('db_down', 'cannot connect to the vmail database');
        }
        if (isset($_SESSION['galbridge_down_until'])) {
            unset($_SESSION['galbridge_down_until']);
        }

        $this->db = $db;
        $this->configureSession($db);
        return $db;
    }

    /**
     * Read-only session and statement timeout. Failures are logged but not fatal: the role-level
     * settings from SQL/grants.*.sql are the primary control.
     */
    private function configureSession(\rcube_db $db): void
    {
        $seconds = max(1, $this->statementTimeout);
        $statements = [];
        switch ($this->driver()) {
            case 'pgsql':
                $this->flavour = 'pgsql';
                $statements = ['SET statement_timeout = ' . ($seconds * 1000), 'SET default_transaction_read_only = on'];
                break;
            case 'mysql':
                $version = '';
                $res = $db->query('SELECT VERSION() AS v');
                if ($res !== false && ($row = $db->fetch_assoc($res))) {
                    $version = (string) ($row['v'] ?? '');
                }
                $this->flavour = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
                $statements = [
                    $this->flavour === 'mariadb' ? 'SET SESSION max_statement_time = ' . $seconds : 'SET SESSION max_execution_time = ' . ($seconds * 1000),
                    'SET SESSION TRANSACTION READ ONLY',
                ];
                break;
            default:
                $this->flavour = 'sqlite';
                $statements = ['PRAGMA query_only = 1'];
        }
        foreach ($statements as $sql) {
            $res = $db->query($sql);
            if ($res === false || $db->is_error($res)) {
                Log::warning('db', 'session limit not applied', ['statement' => strtok($sql, '=') ?: $sql]);
            }
        }
    }
}
