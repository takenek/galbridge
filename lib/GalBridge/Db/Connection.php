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
 *  - the driver class of the judged driver is created directly (rcube_db_mysql / rcube_db_pgsql),
 *    never through the core's rcube_db::factory(), which ends the PHP process on a driver it does
 *    not know;
 *  - 'ignore_errors' is set, so Roundcube never logs SQL text with inlined search values; errors are
 *    logged here with the SQLSTATE only (driver text with galbridge_debug);
 *  - MariaDB/MySQL: the connection gets a read timeout (readTimeout()): the connect timeout covers
 *    the TCP connection only, and mysqlnd would otherwise wait up to its default of 86400 s for the
 *    greeting of a server that accepted the connection and for every later answer;
 *  - after connecting: read-only session and the plugin's statement timeout (galbridge_db_timeout -
 *    the statement bound of the plugin on every engine; the role default of the grant templates
 *    applies to other sessions of the role only). A server that refuses either setting is not
 *    used: fail closed;
 *  - a failed connection is memoised for the request; for OUTAGE_SECONDS in the session only when
 *    the server is unreachable or slow (unreachable(): the attempt took at least half of the
 *    connect timeout, or the error says the server cannot be reached), and likewise an error the
 *    driver raises while connecting, a refused session setting and a connection lost during a
 *    statement - so a dead directory server does not block every webmail request. A refusal the
 *    server answers at once is not memoised for the session: the connection limit of the role
 *    (db_busy - it ends with the peak, and one user holding the limit must not take the directory
 *    away from everybody for OUTAGE_SECONDS) and any other fast answer (authentication, a limit
 *    text in another language) - the next request asks again, which costs a fast answer;
 *  - a bound value never contains a NUL byte or a backslash (select(): the backstop of the rule
 *    every producer of values follows);
 *  - vmail table names are never passed through rcube_db::table_name() (it would add db_prefix).
 */
final class Connection
{
    public const OUTAGE_SECONDS = 30;

    /**
     * A refusal because the connection limit of the role (or of the server) is reached: MySQL /
     * MariaDB 1040, 1203, 1226; PostgreSQL "too many connections for role" / "too many clients".
     */
    private const LIMIT_REACHED = '/\[(?:1040|1203|1226)\]|too many (?:connections|clients)|remaining connection slots are reserved/i';

    /**
     * An error that means the server cannot be reached (memoised for OUTAGE_SECONDS): MySQL /
     * MariaDB client errors 2002 (socket / refused), 2003 (cannot connect), 2005 (unknown host),
     * 2006 / 2013 (gone away / lost while connecting); the texts of libpq and the resolver; SQLite
     * (tests) a database file that cannot be opened.
     */
    private const UNREACHABLE = '/\[(?:2002|2003|2005|2006|2013)\]|connection refused|timed out|timeout expired|could not translate host name|no route to host|network is unreachable|name or service not known|unable to open database file/i';

    /** libpq's texts for a lost connection, which pdo_pgsql reports as SQLSTATE HY000 (see lost()) */
    private const LOST_TEXT = '/no connection to the server|server closed the connection unexpectedly|terminating connection|could not receive data from server|SSL SYSCALL error/i';

    /** seconds the read timeout of a MariaDB/MySQL connection adds to the longer of the two timeouts */
    public const READ_MARGIN = 2;

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

    /** VERSION() of a MariaDB/MySQL server (after connecting), '' for the other engines */
    private string $version = '';

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
        foreach ($params as $value) {
            if (is_string($value) && preg_match('/[\x00\\\\]/', $value) === 1) {
                // rcube_db inlines every bound value into the statement text (PDO::quote()), and PDO
                // scans that text again for placeholders: a backslash moves the literal boundaries
                // PDO's scanner sees, and libpq ends a quoted value at a NUL byte. Every producer
                // of values refuses both (search words, group keys, addresses); this keeps the rule
                // for any future one. The statement is not sent.
                Log::error('db', 'unsafe_parameter: a bound value contains a NUL byte or a backslash (statement not sent)');
                throw new GalBridgeException('db_error', 'a bound value contains a NUL byte or a backslash');
            }
        }
        $db = $this->db();
        $res = $limit !== null ? $db->limitquery($sql, (int) $offset, $limit, ...$params) : $db->query($sql, ...$params);
        if ($res === false || $db->is_error($res)) {
            $info = $db->error_info();
            $state = is_array($info) ? (string) ($info[0] ?? '') : '';
            $code = is_array($info) ? (int) ($info[1] ?? 0) : 0;
            $text = is_array($info) ? (string) ($info[2] ?? '') : '';
            Log::debug('db', 'query failed', ['error' => $text]);
            if (self::lost($state, $code, $text)) {
                // the server stopped answering (read timeout) or went away: like a failed connect,
                // the next requests of this session do not wait for it again
                $this->close();
                $this->outage('lost');
                throw new GalBridgeException('db_down', 'the connection to the vmail database was lost');
            }
            Log::error('db', 'db_error', ['sqlstate' => $state]);
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
        return self::directoryColumns($this->driver() === 'sqlite' ? $this->sqliteColumns() : $this->catalogColumns([...self::TABLES, ...self::FORBIDDEN_TABLES]));
    }

    /**
     * Columns of EVERY relation of the current schema (PostgreSQL) / database (MariaDB/MySQL) that
     * this role can see - the probe of a role that is too broad looks at all of them, not only at
     * the relations GAL Bridge reads (a secret column of another table, e.g. admin.password, is as
     * much a reason to refuse the role). SQLite (tests): visibleColumns().
     *
     * @return array<string, list<string>> table => columns (lower case)
     */
    public function visibleColumnsEverywhere(): array
    {
        return $this->driver() === 'sqlite' ? $this->visibleColumns() : $this->catalogColumns(null);
    }

    /**
     * The relations GAL Bridge reads or must not read (TABLES, FORBIDDEN_TABLES) of a column map,
     * each present (empty when not visible).
     *
     * @param array<string, list<string>> $columns
     *
     * @return array<string, list<string>>
     */
    public static function directoryColumns(array $columns): array
    {
        $out = [];
        foreach ([...self::TABLES, ...self::FORBIDDEN_TABLES] as $t) {
            $out[$t] = $columns[$t] ?? [];
        }
        return $out;
    }

    /**
     * VERSION() of the MariaDB/MySQL server ('' for PostgreSQL and SQLite).
     */
    public function serverVersion(): string
    {
        $this->db();
        return $this->version;
    }

    /**
     * @param null|list<string> $tables null = every relation of the schema / database
     *
     * @return array<string, list<string>>
     */
    private function catalogColumns(?array $tables): array
    {
        $pg = $this->driver() === 'pgsql';
        $sql = $pg
            ? 'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = current_schema()'
            : 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()';
        if ($tables !== null) {
            $sql .= ($pg ? ' AND table_name IN (' : ' AND TABLE_NAME IN (') . implode(', ', array_fill(0, count($tables), '?')) . ')';
        }
        $out = [];
        foreach ($this->select($sql, $tables ?? []) as $row) {
            $out[strtolower((string) ($row['t'] ?? ''))][] = strtolower((string) ($row['c'] ?? ''));
        }
        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function sqliteColumns(): array
    {
        $out = [];
        foreach ([...self::TABLES, ...self::FORBIDDEN_TABLES] as $t) {
            foreach ($this->select('PRAGMA table_info(' . $t . ')') as $row) {
                $out[$t][] = strtolower((string) $row['name']);
            }
        }
        return $out;
    }

    /**
     * The sanitised reason of the last failed connect ('' if none) - the driver's text, an error the
     * driver raised while connecting, or the session setting the server refused - for
     * bin/galbridge.sh diag: in the CLI Roundcube writes the driver message only to stderr, not to
     * its errors log.
     */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Session limits the server refused. Always empty: since the second verification pass a refused
     * statement timeout or read-only mode is not a warning any more - the connection is not used
     * (db_down, lastError() names the statement). Kept for callers of 1.0.x.
     *
     * @return list<string>
     */
    public function sessionWarnings(): array
    {
        return [];
    }

    /**
     * The statement timeout in seconds that every session of the plugin sets (galbridge_db_timeout).
     */
    public function statementTimeout(): int
    {
        return max(1, $this->statementTimeout);
    }

    /**
     * The read timeout in seconds of the plugin's MariaDB/MySQL connection (mysqlnd.net_read_timeout
     * while it is created): the longer of the statement timeout and the connect timeout plus
     * READ_MARGIN, so that the server always ends a statement before the client gives up on it.
     * 0 for the other drivers (libpq has no read timeout after connecting).
     */
    public function readTimeout(): int
    {
        return $this->driver() === 'mysql' ? max($this->statementTimeout(), $this->dsn->connectTimeout()) + self::READ_MARGIN : 0;
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

        $restore = $this->openReadTimeout();
        try {
            $started = microtime(true);
            try {
                $db = $this->factory !== null ? ($this->factory)($this->dsn->effective()) : $this->driverClass($this->dsn->effective());
                $db->set_option('ignore_errors', true);
                $db->db_connect('w');
                $connected = $db->is_connected();
            } catch (\Throwable $e) {
                // An error raised by the driver code itself while it connects (e.g. a PDO MySQL
                // constant a pdo_mysql without mysqlnd lacks): the hooks would fail closed anyway,
                // but the outage is memoised like any other, and diag names the reason.
                $this->lastError = Log::sanitize(get_class($e) . ': ' . $e->getMessage());
                $this->outage('driver_error');
                throw new GalBridgeException('db_down', 'the database driver failed while connecting');
            }
            if (!$connected) {
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
                if ($this->unreachable(is_string($err) ? $err : '', microtime(true) - $started)) {
                    $this->outage('connect');
                    throw new GalBridgeException('db_down', 'cannot connect to the vmail database');
                }
                // a fast answer of a server that is there (authentication, a limit text in the
                // server's language, ...): this request fails, the next one asks again - memoising
                // it would let one user's load take the directory away from other sessions
                Log::error('db', 'db_down', ['cause' => 'refused']);
                throw new GalBridgeException('db_down', 'the vmail database refused the connection');
            }
            $this->configureSession($db);
        } finally {
            $this->closeReadTimeout($restore);
        }
        if (isset($_SESSION['galbridge_down_until'])) {
            unset($_SESSION['galbridge_down_until']);
        }

        $this->db = $db;
        return $db;
    }

    /**
     * The driver class of the judged driver (Dsn allows these three only; sqlite under the test
     * bootstrap). Never by a name built from the DSN.
     */
    private function driverClass(string $dsn): \rcube_db
    {
        return match ($this->driver()) {
            'mysql' => new \rcube_db_mysql($dsn, '', false),
            'pgsql' => new \rcube_db_pgsql($dsn, '', false),
            default => new \rcube_db_sqlite($dsn, '', false),
        };
    }

    /**
     * Is a failed connect attempt one of an unreachable or slow server, worth sparing the next
     * requests of the session (OUTAGE_SECONDS)? It is when the attempt took at least half of the
     * connect timeout (a server that does not answer), or when the error says the server cannot be
     * reached (UNREACHABLE).
     */
    private function unreachable(string $error, float $elapsed): bool
    {
        $timeout = $this->dsn->connectTimeout();
        return ($timeout > 0 && $elapsed >= $timeout / 2) || preg_match(self::UNREACHABLE, $error) === 1;
    }

    /**
     * The request fails, and the session does not try again for OUTAGE_SECONDS.
     */
    private function outage(string $cause): void
    {
        $this->failed = true;
        $_SESSION['galbridge_down_until'] = time() + self::OUTAGE_SECONDS;
        Log::error('db', 'db_down', ['retry_in' => self::OUTAGE_SECONDS, 'cause' => $cause]);
    }

    /**
     * Did the statement fail because the connection is gone (read timeout, server restart, a proxy
     * that dropped it)? MySQL / MariaDB 2006 "server has gone away", 2013 "lost connection", 4031
     * "disconnected by the server because of inactivity"; SQLSTATE class 08 (connection exception)
     * and 57P01-57P03 (the server is shutting down); libpq's texts under HY000. A statement timeout
     * of the server (57014, 70100, MySQL 3024) is none: the connection stays usable.
     */
    private static function lost(string $state, int $code, string $text): bool
    {
        return ($state === 'HY000' && (in_array($code, [2006, 2013, 4031], true) || preg_match(self::LOST_TEXT, $text) === 1))
            || str_starts_with($state, '08')
            || in_array($state, ['57P01', '57P02', '57P03'], true);
    }

    /**
     * MariaDB/MySQL: lower mysqlnd.net_read_timeout to readTimeout() while the connection is created.
     * mysqlnd copies the value into the connection, so the plugin's connection keeps it after the
     * php.ini value is restored (closeReadTimeout()), and nothing else in the request is affected.
     * A lower value of the administrator is kept.
     *
     * @return null|string the php.ini value to restore, null when nothing was changed
     */
    private function openReadTimeout(): ?string
    {
        $want = $this->readTimeout();
        if ($want === 0) {
            return null;
        }
        $current = ini_get('mysqlnd.net_read_timeout');
        if ($current === false) {
            Log::once('warning', 'db', 'read_timeout_unavailable', 'pdo_mysql is not built on mysqlnd: the directory connection has no read timeout (bound the PHP worker with request_terminate_timeout, SECURITY.md)');
            return null;
        }
        if ((int) $current > 0 && (int) $current <= $want) {
            return null;
        }
        if (!function_exists('ini_set') || ini_set('mysqlnd.net_read_timeout', (string) $want) === false) {
            Log::once('warning', 'db', 'read_timeout_not_applied', 'the read timeout of the directory connection could not be set (ini_set disabled, or mysqlnd.net_read_timeout fixed by php_admin_value): set mysqlnd.net_read_timeout to at most the value below', ['wanted' => $want, 'current' => (int) $current]);
            return null;
        }
        return $current;
    }

    private function closeReadTimeout(?string $restore): void
    {
        if ($restore !== null) {
            ini_set('mysqlnd.net_read_timeout', $restore);
        }
    }

    /**
     * Read-only session and the plugin's statement timeout. A server that refuses either is not
     * used (fail closed): the plugin never reads the directory without its statement bound - on
     * MySQL there is no account-level statement time at all, and a role default is only a default
     * that any session may change.
     *
     * @throws GalBridgeException 'db_down'
     */
    private function configureSession(\rcube_db $db): void
    {
        $seconds = $this->statementTimeout();
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
                $this->version = $version;
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
                $db->closeConnection();
                $this->lastError = 'the server refused ' . $name . ': the plugin needs its statement timeout and read-only mode';
                Log::error('db', 'session_limit_refused', ['statement' => $name]);
                $this->outage('session_limit');
                throw new GalBridgeException('db_down', 'the server refused a session limit of the plugin (' . $name . ')');
            }
        }
    }
}
