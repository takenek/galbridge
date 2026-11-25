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

use GalBridge\Directory\Schema;
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
 *    dead directory server does not block every webmail request - but not a refusal because the
 *    connection limit of the role is reached (that is answered at once and ends with the peak);
 *  - vmail table names are never passed through rcube_db::table_name() (it would add db_prefix).
 */
final class Connection
{
    public const OUTAGE_SECONDS = 30;

    /**
     * A refusal because the connection limit of the role (or of the server) is reached: MySQL /
     * MariaDB 1040, 1203, 1226; PostgreSQL "too many connections for role" / "too many clients".
     */
    private const LIMIT_REACHED = '/\[(?:1040|1203|1226)\]|too many (?:connections|clients)/i';

    /** the vmail relations GAL Bridge may read (column probe); forwardings only through its view */
    public const TABLES = ['mailbox', 'domain', 'alias', Schema::FORWARDINGS, 'maillists', 'moderators', 'maillist_owners'];

    /**
     * Relations the role must not be able to read at all (probed as well): the forwardings table
     * holds the private forwarding targets of every mailbox.
     */
    public const FORBIDDEN_TABLES = [Schema::FORWARDINGS_TABLE];

    /** columns whose visibility means the role is too broad (fail closed) */
    public const SECRET_COLUMNS = ['password', 'recovery_email', 'allow_nets'];

    private ?\rcube_db $db = null;

    private bool $failed = false;

    private string $flavour = '';

    private string $lastError = '';

    /** @var list<string> session limits the server refused (diag) */
    private array $sessionWarnings = [];

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
     * Run a statement that the role is expected to be DENIED (diag privilege tripwires).
     *
     * @return string '' when the statement succeeded (the role has the privilege), 'denied' when the
     *                server refused it for a missing privilege, otherwise the SQLSTATE of another
     *                error - which proves nothing about the privilege
     */
    public function probe(string $sql): string
    {
        $db = $this->db();
        $res = $db->query($sql);
        if ($res !== false && !$db->is_error($res)) {
            return '';
        }
        $info = $db->error_info();
        $state = is_array($info) && ($info[0] ?? '') !== '' ? (string) $info[0] : 'ERROR';
        $code = is_array($info) ? (int) ($info[1] ?? 0) : 0;
        // PostgreSQL insufficient_privilege; MariaDB/MySQL database / table / column access denied
        $denied = $state === '42501' || ($this->driver() === 'mysql' && in_array($code, [1044, 1142, 1143], true));
        return $denied ? 'denied' : $state;
    }

    /**
     * probe() for a data-changing statement on MariaDB/MySQL. The plugin's session is read-only, so
     * the server would refuse every write before it looks at the privileges; the statement therefore
     * runs in a read-write transaction that is always rolled back (the callers also pass statements
     * that can match no row). Other engines: probe().
     */
    public function probeWrite(string $sql): string
    {
        if ($this->driver() !== 'mysql') {
            return $this->probe($sql);
        }
        $db = $this->db();
        $db->query('SET SESSION TRANSACTION READ WRITE');
        $db->startTransaction();
        try {
            return $this->probe($sql);
        } finally {
            $db->rollbackTransaction();
            $db->query('SET SESSION TRANSACTION READ ONLY');
        }
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
        $tables = [...self::TABLES, ...self::FORBIDDEN_TABLES];
        $out = array_fill_keys($tables, []);
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            foreach ($tables as $t) {
                foreach ($this->select('PRAGMA table_info(' . $t . ')') as $row) {
                    $out[$t][] = strtolower((string) $row['name']);
                }
            }
            return $out;
        }
        $in = implode(', ', array_fill(0, count($tables), '?'));
        $sql = $driver === 'pgsql'
            ? 'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (' . $in . ')'
            : 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $in . ')';
        foreach ($this->select($sql, $tables) as $row) {
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

    /**
     * Session limits (statement timeout, read-only mode) that the server refused when the
     * connection was set up - the statement names only. Empty before the first query.
     *
     * @return list<string>
     */
    public function sessionWarnings(): array
    {
        return $this->sessionWarnings;
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
            if (is_string($err) && preg_match(self::LIMIT_REACHED, $err) === 1) {
                // The connection limit is reached: the server answered at once, it is not down. Not
                // memoised for the session - the memo is there to spare requests the timeout of a
                // dead server, and here it would keep the directory away from this user for
                // OUTAGE_SECONDS after the peak (one user holding the limit would do that to all).
                Log::warning('db', 'db_busy: the connection limit of the database role is reached (galbridge.sh grants --connection-limit)');
                throw new GalBridgeException('db_down', 'the connection limit of the database role is reached');
            }
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
                $name = trim(strtok($sql, '=') ?: $sql);
                $this->sessionWarnings[] = $name;
                Log::warning('db', 'session limit not applied', ['statement' => $name]);
            }
        }
    }
}
