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
 *    The allowed endpoints are galbridge_imap_hosts ("host" = any port, "host:port", or a local
 *    socket "unix:///path"), or - only when that is empty - the single imap_host of the
 *    ADMINISTRATOR configuration, WITH its port, when it has no placeholder and include_host_config
 *    is off (with it the effective imap_host depends on the Host header of the request, so an
 *    explicit list is required). The session's storage_host and storage_port and the user's stored
 *    mail_host must match: two IMAP services on one host name (different ports, different user
 *    databases) are told apart. A local socket has neither host name nor port: it matches the entry
 *    of that very socket (the path as written) and nothing else.
 * 2. The login is looked up in vmail with the same predicates iRedMail uses for logins; the home
 *    domain comes from that row.
 * 3. The result (and the schema/privilege probe) is cached in $_SESSION['galbridge'], bound to the
 *    user id, login, IMAP endpoint and configuration fingerprint, for galbridge_scope_ttl seconds.
 *    A database outage is never cached as "no entitlement".
 * 4. A cached Viewer is confirmed before a request READS the directory (confirm(): one primary-key
 *    query, at most once per request): a mailbox that was disabled, expired or moved to another
 *    domain loses the directory with its next request, not after the cache lifetime.
 */
final class ViewerResolver
{
    private const CACHE_VERSION = 2;

    /**
     * Per-user alias addresses of the viewer that are used for the list policies. A login with more
     * of them is evaluated with the login alone (fewer restricted lists, never more): the set goes
     * into the session and into the value lists of every list query.
     */
    public const MAX_ALIASES = 200;

    /** @var false|null|Viewer per-request memo (false = not resolved yet) */
    private Viewer|false|null $memo = false;

    /** the last good Viewer of this session after its TTL expired (advertising during an outage only) */
    private ?Viewer $stale = null;

    private string $status = 'unresolved';

    /** the Viewer came from the database in this request (nothing to confirm) */
    private bool $fresh = false;

    private ?bool $confirmed = null;

    /**
     * @param \Closure(): Connection $connection
     * @param int                    $storagePort the IMAP port of the session (0 = unknown: only
     *                                            "host" entries without a port can match)
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $connection,
        private readonly int $userId,
        private readonly string $username,
        private readonly string $mailHost,
        private readonly string $storageHost,
        private readonly int $storagePort = 0,
    ) {
    }

    /**
     * The allowed IMAP endpoints ("host" or "host:port" in lower case, or "unix:///path"), or null
     * when the plugin must stay disabled (bindingProblem() says why).
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
        if (self::bindingProblem($config) !== null) {
            return null;
        }
        $imapHost = trim((string) $config->adminCore('imap_host', $config->adminCore('default_host')));
        $parsed = \rcube_utils::parse_host_uri($imapHost, 143, 993);
        if (strtolower((string) $parsed[1]) === 'unix') {
            // a local socket (Roundcube connects with port -1): the binding is the socket itself
            $unix = Config::unixEndpoint($imapHost);
            return $unix === null ? null : [$unix];
        }
        $host = strtolower(\rcube_utils::idn_to_ascii(trim((string) $parsed[0], '[]')));
        $port = (int) $parsed[2];
        if ($host === '' || $port < 1) {
            return null;
        }
        // the port Roundcube connects to is part of the derived binding
        return [self::endpoint($host, $port)];
    }

    /**
     * Why no directory host can be derived from the core configuration (galbridge_imap_hosts is
     * empty or malformed), or null when one can.
     */
    public static function bindingProblem(Config $config): ?string
    {
        if ($config->imapHosts() === null) {
            return 'galbridge_imap_hosts is malformed';
        }
        if ($config->imapHosts() !== []) {
            return null;
        }
        if (!empty($config->adminCore('include_host_config'))) {
            // per-host configuration files are chosen by the Host header of the request
            return 'include_host_config is enabled, so imap_host can depend on the requested host name';
        }
        $imapHost = $config->adminCore('imap_host', $config->adminCore('default_host'));
        if (is_array($imapHost)) {
            return 'imap_host is an array (the user chooses the server)';
        }
        if (!is_string($imapHost) || trim($imapHost) === '') {
            return 'imap_host is empty (the user types the server)';
        }
        if (str_contains($imapHost, '%')) {
            return 'imap_host is templated (%d/%n/%s come from the request)';
        }
        return null;
    }

    public static function normaliseHost(string $host): string
    {
        $unix = Config::unixEndpoint(trim($host));
        if ($unix !== null) {
            return $unix; // a local socket: the path is compared as written
        }
        $parsed = \rcube_utils::parse_host_uri(trim($host), 143, 993);
        $h = is_array($parsed) ? (string) $parsed[0] : trim($host);
        return strtolower(\rcube_utils::idn_to_ascii(trim($h, '[]')));
    }

    /**
     * "host:port" in the notation of Config::imapEndpoint() (an IPv6 literal in brackets); a local
     * socket has no port and stays what it is.
     */
    public static function endpoint(string $host, int $port): string
    {
        if (str_starts_with($host, 'unix://')) {
            return $host;
        }
        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }

    /**
     * Does one of the allowed entries cover this (normalised) host - and, when $port is given, this
     * port? An entry without a port covers every port of its host. A local socket ("unix:///path")
     * is covered by the entry of that very socket only.
     *
     * @param list<string> $allowed
     */
    public static function covers(array $allowed, string $host, ?int $port = null): bool
    {
        if ($host === '') {
            return false;
        }
        $socket = str_starts_with($host, 'unix://');
        foreach ($allowed as $entry) {
            if ($socket || str_starts_with($entry, 'unix://')) {
                // neither a port nor a host part to compare: "unix" is not a prefix of anything here
                if ($entry === $host) {
                    return true;
                }
                continue;
            }
            if ($entry === $host || ($port !== null && $entry === self::endpoint($host, $port))) {
                return true;
            }
            // a host without a known port (the stored mail_host): the host part decides
            if ($port === null && str_starts_with($entry, (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':')) {
                return true;
            }
        }
        return false;
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
            Log::once('warning', 'viewer', 'plugin disabled', (self::bindingProblem($this->config) ?? 'no usable imap_host') . ': set galbridge_imap_hosts');
            return $this->memo = null;
        }
        $storage = self::normaliseHost($this->storageHost);
        $mailHost = self::normaliseHost($this->mailHost);
        if (!self::covers($allowed, $storage, $this->storagePort) || !self::covers($allowed, $mailHost)) {
            $this->status = 'host_mismatch';
            // server names, not personal data: what the administrator needs for galbridge_imap_hosts
            Log::once('warning', 'viewer', 'host_mismatch', 'session IMAP host is not an allowed directory host', [
                'host' => $storage === '' ? '' : self::endpoint($storage, $this->storagePort), 'mail_host' => $mailHost, 'allowed' => implode(' ', $allowed),
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
            && ($cache['port'] ?? null) === $this->storagePort
            && ($cache['fp'] ?? null) === $fingerprint
            && is_int($cache['at'] ?? null)
            && is_string($cache['status'] ?? null)) {
            $viewer = is_array($cache['viewer'] ?? null) ? Viewer::fromArray($cache['viewer']) : null;
            // an "ok" entry carries a usable Viewer of THIS login; one that does not is not an entry
            // this code wrote: it is neither trusted nor kept, the login is looked up again
            if ($cache['status'] !== 'ok' || ($viewer !== null && $viewer->login === $login)) {
                if (time() - $cache['at'] < $ttl) {
                    $this->status = $cache['status'];
                    return $this->memo = ($this->status === 'ok' ? $viewer : null);
                }
                $this->stale = $cache['status'] === 'ok' ? $viewer : null;
            }
        }

        try {
            $viewer = $this->lookup($login);
            $this->fresh = true;
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
            'port' => $this->storagePort,
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
     * Is the Viewer of this session still entitled to read the directory? A Viewer resolved in this
     * request is; a cached one is confirmed with one query for its mailbox row (same predicates as
     * the lookup, same home domain) - at most once per request. When the mailbox is gone, disabled,
     * expired or moved, the cache entry becomes "no mailbox" and the session has no Viewer.
     *
     * Called by the Repository before its first query, so requests that only instantiate a book
     * (the sender-photo lookup of every opened message) never pay for it.
     *
     * @throws GalBridgeException when the database cannot answer
     */
    public function confirm(): bool
    {
        if ($this->confirmed !== null) {
            return $this->confirmed;
        }
        $viewer = $this->memo;
        if (!$viewer instanceof Viewer) {
            return $this->confirmed = false;
        }
        if ($this->fresh) {
            return $this->confirmed = true;
        }
        if ($this->homeDomain(($this->connection)(), $viewer->login) === $viewer->home) {
            return $this->confirmed = true;
        }
        // The entry is OVERWRITTEN with the new decision, never unset: Roundcube merges the stored
        // session with the one of this request (rcube_session::fixvars()), so a key that is merely
        // unset comes back with the next request - and with it the revoked Viewer.
        $entry = $_SESSION['galbridge'] ?? null;
        $_SESSION['galbridge'] = is_array($entry) ? ['status' => 'no_mailbox', 'viewer' => null, 'at' => time()] + $entry : null;
        $this->memo = null;
        $this->status = 'no_mailbox';
        Log::once('info', 'viewer', 'viewer_revoked', 'the mailbox of this session is no longer usable: directory access ended');
        return $this->confirmed = false;
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
        foreach (Connection::FORBIDDEN_TABLES as $table) {
            if ($conn->driver() !== 'sqlite' && ($columns[$table] ?? []) !== []) {
                $this->status = 'role_too_broad';
                Log::error('db', 'role_too_broad: the configured database role can read the table "' . $table . '" (private forwarding targets of every mailbox); grant only the view ' . Schema::FORWARDINGS . ' (bin/galbridge.sh grants) and revoke the table grant');
                return null;
            }
        }

        $missing = Schema::missingRequired($this->config, $columns);
        if ($missing !== []) {
            $this->status = 'column_missing';
            Log::once('error', 'schema', 'column_missing', 'required vmail columns not readable: ' . implode(', ', $missing) . ' (see bin/galbridge.sh diag)');
            return null;
        }
        $home = $this->homeDomain($conn, $login);
        if ($home === null) {
            $this->status = 'no_mailbox';
            return null;
        }

        $this->status = 'ok';
        return new Viewer($login, $home, [$login, ...$this->aliases($conn, $login)], $columns);
    }

    /**
     * mailbox.domain of an active mailbox in an active, non-backup-MX (and, with
     * galbridge_hide_expired, unexpired) domain - the predicates iRedMail uses for logins. The
     * domain is the one of the login itself, or there is no home domain.
     *
     * @throws GalBridgeException
     */
    private function homeDomain(Connection $conn, string $login): ?string
    {
        $sql = 'SELECT m.username, m.`domain` FROM `mailbox` m JOIN `domain` d ON d.`domain` = m.`domain`'
            . ' WHERE m.username = ? AND m.active = 1 AND d.active = 1 AND d.backupmx = 0';
        $params = [$login];
        if ($this->config->bool('galbridge_hide_expired')) {
            $sql .= ' AND m.expired > ? AND d.expired > ?';
            $now = gmdate('Y-m-d H:i:s');
            $params[] = $now;
            $params[] = $now;
        }
        $rows = $conn->select($sql, $params);
        $home = isset($rows[0]) ? Normalizer::domain($rows[0]['domain'] ?? null) : null;
        if ($home !== null && !str_ends_with($login, '@' . $home)) {
            // the rule every directory row has to pass (RecordMapper): a mailbox whose domain column
            // is not the domain of its address belongs to no directory - it must not open the
            // directory of the domain that column names
            Log::once('warning', 'viewer', 'row_invalid', 'the mailbox row of the login has a domain column that does not match its address: no directory');
            return null;
        }
        return $home;
    }

    /**
     * The viewer's own per-user alias addresses, used ONLY to decide which restricted alias accounts
     * and lists the viewer may see. Bounded (MAX_ALIASES), and - exactly as for the aliases shown in
     * a book - only rows of an active (unexpired) non-backup-MX domain count: an alias the mail
     * system no longer serves must not keep a restricted list visible.
     *
     * @return list<string>
     *
     * @throws GalBridgeException
     */
    private function aliases(Connection $conn, string $login): array
    {
        $sql = 'SELECT f.address FROM `' . Schema::FORWARDINGS . '` f JOIN `domain` fd ON fd.`domain` = f.`domain`'
            . ' WHERE f.forwarding = ? AND f.is_alias = 1 AND f.active = 1 AND fd.active = 1 AND fd.backupmx = 0';
        $params = [$login];
        if ($this->config->bool('galbridge_hide_expired')) {
            $sql .= ' AND fd.expired > ?';
            $params[] = gmdate('Y-m-d H:i:s');
        }
        $rows = $conn->select($sql . ' ORDER BY f.address', $params, 0, self::MAX_ALIASES + 1);
        if (count($rows) > self::MAX_ALIASES) {
            Log::once('warning', 'viewer', 'too_many_aliases', 'more per-user aliases than the limit: list policies are evaluated with the login alone', ['max' => self::MAX_ALIASES]);
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $a = Normalizer::address($row['address'] ?? null);
            if ($a !== null && $a !== $login) {
                $out[] = $a;
            }
        }
        return array_values(array_unique($out));
    }
}
