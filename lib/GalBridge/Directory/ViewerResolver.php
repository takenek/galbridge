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
use GalBridge\Db\RoleScope;
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
 *    socket "unix:///path"), or - only when that is empty - the single imap_host Roundcube applies
 *    (Config::coreValue()), WITH its port, when it has no placeholder, include_host_config is off
 *    (with it the effective imap_host depends on the Host header of the request, so an explicit
 *    list is required) and a second reading of the configuration files has the same imap_host
 *    (otherwise it comes from an environment-specific file, include_once/require_once or another
 *    plugin, and an explicit list is required as well). The session's storage_host and
 *    storage_port and the user's stored mail_host must match: two IMAP services on one host name
 *    (different ports, different user databases) are told apart. A local socket has neither host
 *    name nor port: it matches the entry of that very socket (the path as written) and nothing else.
 * 2. Transport: a session with an IMAP server that is not local (a socket, localhost, 127.0.0.0/8,
 *    ::1) must have reached it over TLS that authenticates the server (transportProblem()):
 *    otherwise whoever sits on the network between webmail and IMAP server could answer the login
 *    for any mailbox. galbridge_imap_allow_plaintext accepts it anyway (a warning is logged).
 * 3. The login is looked up in vmail with the same predicates iRedMail uses for logins; the home
 *    domain comes from that row.
 * 4. The result (and the schema/privilege probe) is cached in $_SESSION['galbridge'], bound to the
 *    user id, login, IMAP endpoint and configuration fingerprint, for galbridge_scope_ttl seconds.
 *    A database outage is never cached as "no entitlement". When the entry is renewed, the IMAP
 *    server confirms the credentials of the session first (the plugin passes a check that uses
 *    Roundcube's own connection): a password that was changed or an IMAP service that was disabled
 *    ends the directory of that session for good ("imap_refused"); an IMAP server that cannot be
 *    asked makes the directory unavailable for Connection::OUTAGE_SECONDS, never "refused".
 * 5. A cached Viewer is confirmed before a request READS the directory (confirm(): one primary-key
 *    query, at most once per request): a mailbox that was disabled, expired or moved to another
 *    domain loses the directory with its next request, not after the cache lifetime. Where the list
 *    policy depends on the viewer's own aliases (strict), they are read again as well: a removed
 *    alias applies with the next request.
 */
final class ViewerResolver
{
    private const CACHE_VERSION = 2;

    /**
     * 1 for a row of the view galbridge_forwardings (alias f) that is none of the three kinds of the
     * grant set - also when a flag is NULL - and 0 otherwise (portable: no IS NOT TRUE).
     */
    public const OTHER_KIND_FLAG = 'CASE WHEN f.is_alias = 1 OR f.is_list = 1 OR f.is_maillist = 1 THEN 0 ELSE 1 END';

    /** the condition of such a row */
    public const OTHER_KIND = '(' . self::OTHER_KIND_FLAG . ') = 1';

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

    /** IMAP response codes (RFC 5530) that say the credentials themselves are refused */
    private const IMAP_REFUSALS = ['AUTHENTICATIONFAILED', 'AUTHORIZATIONFAILED', 'EXPIRED'];

    /** rcube_imap_generic::ERROR_NO - a literal, so the verdict needs no Roundcube */
    private const IMAP_ERROR_NO = -1;

    /** storage_ssl values of a session that connected over TLS (rcmail::login()) */
    private const TLS_MODES = ['ssl', 'imaps', 'tls'];

    /** the first Roundcube release whose STARTTLS fails when the server does not offer it */
    private const STARTTLS_FIXED = '1.7.7';

    /**
     * @param \Closure(): Connection  $connection
     * @param int                     $storagePort the IMAP port of the session (0 = unknown: only
     *                                             "host" entries without a port can match)
     * @param string                  $storageSsl  the TLS mode of the session ($_SESSION['storage_ssl']:
     *                                             'ssl', 'imaps', 'tls'; '' = not encrypted)
     * @param null|\Closure(): ?bool $imapCheck   asked when the cache entry is renewed: does the IMAP
     *                                             server accept the credentials of this session? true,
     *                                             false (refused) or null (cannot be told); null =
     *                                             no such check (tests, diag)
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $connection,
        private readonly int $userId,
        private readonly string $username,
        private readonly string $mailHost,
        private readonly string $storageHost,
        private readonly int $storagePort = 0,
        private readonly string $storageSsl = '',
        private readonly ?\Closure $imapCheck = null,
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
        // the imap_host Roundcube applies (bindingProblem() made sure a second reading agrees)
        $imapHost = trim((string) $config->coreValue('imap_host'));
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
        if (getenv('ROUNDCUBE_INCLUDE_HOST_CONFIG') !== false) {
            // Roundcube reads include_host_config from its files WITHOUT the environment override,
            // while the configuration as read here applies it: with the variable set, what the core
            // does with the option cannot be told from here - so nothing is derived (fail closed)
            return 'ROUNDCUBE_INCLUDE_HOST_CONFIG is set, and Roundcube itself ignores that variable: whether imap_host depends on the requested host name cannot be told';
        }
        if ($config->hostConfigEnabled()) {
            // per-host configuration files are chosen by the Host header of the request (Roundcube's
            // own object or the second reading of the files enables it)
            return 'include_host_config is enabled, so imap_host can depend on the requested host name';
        }
        $imapHost = $config->coreValue('imap_host');
        if ($imapHost !== $config->adminCore('imap_host', $config->adminCore('default_host'))) {
            // Roundcube's own object and a second reading of the files disagree: the value comes
            // from something that is not read the same way every time (fail closed)
            return 'imap_host as Roundcube uses it differs from the configuration files read again (an environment-specific file, include_once/require_once or another plugin), so the directory servers cannot be derived from it';
        }
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

    /**
     * A host of the ADMINISTRATOR's configuration (imap_host) in the notation of the binding. Never
     * for the values of a session or of the users table: those are checked with canonicalHost().
     */
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
     * The IMAP host of the SESSION ($_SESSION['storage_host']) or of the user record (users.mail_host)
     * in the notation of the binding - or null when the raw value is not in the one form Roundcube
     * writes after a regular login: a host name (letters, digits, '.', '-', '_'), an IPv4 address, an
     * IPv6 address in brackets, or a local socket "unix:///path". The value is NEVER trimmed or parsed
     * again: PHP's socket layer connects to "host:port" taking the host up to the FIRST colon, so a
     * value that a second parse reads differently (a blank, a scheme, a user part, a colon, a path)
     * can name the allowed server for this check while the session was authenticated elsewhere. Such
     * values reach the session where the user types the server and Roundcube does not check its form
     * (1.7.0 - 1.7.5), or through an imap_host template and an unusual Host header.
     *
     * users.mail_host of an older Roundcube may hold the configured URI ("ssl://host:993"); with
     * $storedUri that exact form is accepted as well (scheme ssl, tls, imap or imaps, the host as
     * above, an optional port, which the stored host is compared without).
     */
    public static function canonicalHost(string $raw, bool $storedUri = false): ?string
    {
        $unix = Config::unixEndpoint($raw);
        if ($unix !== null) {
            return $unix; // whitespace and placeholders are refused by unixEndpoint(); no trim()
        }
        if ($storedUri && preg_match('~^(?:ssl|tls|imaps?)://(\[[0-9A-Fa-f:.]{2,45}\]|[A-Za-z0-9_.-]{1,253})(?::[0-9]{1,5})?$~Di', $raw, $m) === 1) {
            $raw = $m[1];
        }
        if (preg_match('/^\[([0-9A-Fa-f:.]{2,45})\]$/D', $raw, $m) === 1) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? strtolower($m[1]) : null;
        }
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $raw;
        }
        // a host name: no colon (an IPv6 address without brackets cannot be connected to either),
        // no blank, no scheme, no user part, no path; never starting or ending with '.' or '-'
        if (preg_match('/^[A-Za-z0-9_](?:[A-Za-z0-9_.-]{0,251}[A-Za-z0-9_])?$/D', $raw) === 1) {
            return strtolower($raw);
        }
        return null;
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
            [$entryHost, $entryPort] = self::splitEntry($entry);
            if ($entryHost !== $host) {
                continue;
            }
            // an entry without a port covers every port; a host without a known port (the stored
            // mail_host) is decided by the host part; otherwise the port has to be the entry's
            if ($entryPort === null || $port === null || $entryPort === $port) {
                return true;
            }
        }
        return false;
    }

    /**
     * Host and port of a TCP entry of the allowed list ("host", "host:port", "[v6]:port" or a bare
     * IPv6 address - whose colons are not a port separator). diag warns for an entry without a port
     * (it covers every port of its host).
     *
     * @return array{0: string, 1: null|int}
     */
    public static function splitEntry(string $entry): array
    {
        if (preg_match('/^\[([^\]]+)\](?::([0-9]{1,5}))?$/D', $entry, $m) === 1) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : null];
        }
        if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return [$entry, null];
        }
        if (preg_match('/^([^:]+):([0-9]{1,5})$/D', $entry, $m) === 1) {
            return [$m[1], (int) $m[2]];
        }
        return [$entry, null];
    }

    /**
     * Is this endpoint (canonical, from canonicalHost()) on this machine: a local socket, localhost,
     * 127.0.0.0/8 or ::1? Host names are never resolved.
     */
    public static function isLocalEndpoint(string $canonicalHost): bool
    {
        if (str_starts_with($canonicalHost, 'unix://') || $canonicalHost === 'localhost' || $canonicalHost === '::1') {
            return true;
        }
        return filter_var($canonicalHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($canonicalHost, '127.');
    }

    /**
     * Why the IMAP connection of a session cannot be trusted to have reached the server it names
     * (the login was answered by that server, not by whoever sits on the network in between), or
     * null when it can - pure, no Roundcube needed:
     *  - a local endpoint (isLocalEndpoint()) needs nothing;
     *  - otherwise TLS is required: storage_ssl 'ssl' or 'imaps' (TLS from the first byte), or 'tls'
     *    (STARTTLS) only on a Roundcube library of 1.7.7 or later - older ones go on in plain text
     *    when the server does not offer STARTTLS; a version without a patch level ("1.7-git") does
     *    not count as 1.7.7;
     *  - PHP's openssl extension (without it Roundcube connects without TLS, whatever the session says);
     *  - certificate verification on: imap_conn_options (the per-host entry for this host, selected as
     *    rcube_utils::parse_socket_options() does) must not set verify_peer or verify_peer_name to a
     *    false value, nor allow_self_signed.
     *
     * @param string $rawHost     the session host as Roundcube passes it to the connection (the key
     *                            of per-host socket options)
     * @param string $ssl         storage_ssl of the session ('' = not encrypted)
     * @param mixed  $connOptions imap_conn_options as Roundcube applies it
     */
    public static function transportProblem(string $canonicalHost, string $rawHost, string $ssl, mixed $connOptions, string $coreVersion, bool $openssl): ?string
    {
        if (self::isLocalEndpoint($canonicalHost)) {
            return null;
        }
        $ssl = strtolower($ssl);
        if (!in_array($ssl, self::TLS_MODES, true)) {
            return 'the IMAP connection is not encrypted (no ssl:// or tls:// scheme)';
        }
        if (!$openssl) {
            return 'PHP has no openssl extension, so Roundcube connects to the IMAP server without TLS';
        }
        if ($ssl === 'tls' && (preg_match('/^\d+\.\d+\.\d+$/D', $coreVersion) !== 1 || version_compare($coreVersion, self::STARTTLS_FIXED, '<'))) {
            return 'STARTTLS (tls://) on Roundcube ' . ($coreVersion === '' ? '(version unknown)' : Log::sanitize($coreVersion)) . ' goes on in plain text when the server does not offer STARTTLS: use ssl://host:993 or Roundcube ' . self::STARTTLS_FIXED . ' or later';
        }
        $options = $connOptions;
        if (is_array($options) && $rawHost !== '') {
            // rcube_utils::parse_socket_options(): the options of this host replace the whole list
            $url = parse_url($rawHost);
            $key = is_array($url) && isset($url['host']) ? $url['host'] : $rawHost;
            if (array_key_exists($key, $options)) {
                $options = $options[$key];
            }
        }
        $tls = is_array($options) && is_array($options['ssl'] ?? null) ? $options['ssl'] : [];
        if ((array_key_exists('verify_peer', $tls) && !$tls['verify_peer'])
            || (array_key_exists('verify_peer_name', $tls) && !$tls['verify_peer_name'])
            || !empty($tls['allow_self_signed'])) {
            return 'imap_conn_options switch the verification of the IMAP server certificate off (verify_peer, verify_peer_name or allow_self_signed)';
        }
        return null;
    }

    /**
     * The release of Roundcube's library (the IMAP client is part of it), or '' when unknown.
     */
    public static function coreVersion(): string
    {
        foreach (['RCUBE_VERSION', 'RCMAIL_VERSION'] as $name) {
            if (defined($name)) {
                return (string) constant($name);
            }
        }
        return '';
    }

    /**
     * What an attempt to connect to the IMAP server says about the credentials of the session:
     * true = accepted (connected), false = refused - the server answered NO with one of the RFC 5530
     * codes AUTHENTICATIONFAILED, AUTHORIZATIONFAILED or EXPIRED (a changed password, a disabled IMAP
     * service: Dovecot's own checks) - and null for everything else (unreachable, NO [UNAVAILABLE],
     * BYE, a server without response codes): that is no revocation. Pure, no Roundcube needed.
     */
    public static function imapVerdict(bool $connected, ?int $errornum, ?string $resultcode): ?bool
    {
        if ($connected) {
            return true;
        }
        if ($errornum === self::IMAP_ERROR_NO && $resultcode !== null && in_array(strtoupper($resultcode), self::IMAP_REFUSALS, true)) {
            return false;
        }
        return null;
    }

    /**
     * 'ok', 'disabled' (IMAP host policy), 'host_mismatch', 'transport_unprotected', 'imap_refused',
     * 'imap_unavailable', 'no_mailbox', 'viewer_changed', 'db_down', 'db_error', 'config_invalid',
     * 'column_missing', 'role_too_broad', 'invalid_login' or 'unresolved'.
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

        if ($this->config->configUnreliable()) {
            // the configuration of this request is not the one the administrator wrote (reported
            // per file / option by Config): a plugin configuration file that does not load or is
            // ignored, an unknown option, or (include_host_config) an option that depends on the
            // Host header - no book, and no connection to whatever DSN or IMAP host list is in effect
            $this->status = 'config_invalid';
            return $this->memo = null;
        }
        $allowed = self::allowedImapHosts($this->config);
        if ($allowed === null) {
            $this->status = 'disabled';
            Log::once('warning', 'viewer', 'plugin disabled', (self::bindingProblem($this->config) ?? 'no usable imap_host') . ': set galbridge_imap_hosts');
            return $this->memo = null;
        }
        // the RAW values, checked for their canonical form - never trimmed or parsed again
        $storage = self::canonicalHost($this->storageHost);
        $mailHost = self::canonicalHost($this->mailHost, true);
        if ($storage === null || $mailHost === null || !self::covers($allowed, $storage, $this->storagePort) || !self::covers($allowed, $mailHost)) {
            $this->status = 'host_mismatch';
            // server names, not personal data: what the administrator needs for galbridge_imap_hosts.
            // A value that is not canonical is not written to the log (it could carry anything).
            Log::once('warning', 'viewer', 'host_mismatch', 'session IMAP host is not an allowed directory host', [
                'host' => $storage === null ? ($this->storageHost === '' ? '' : 'not-canonical') : self::endpoint($storage, $this->storagePort),
                'mail_host' => $mailHost ?? ($this->mailHost === '' ? '' : 'not-canonical'), 'allowed' => implode(' ', $allowed),
            ]);
            return $this->memo = null;
        }

        // the link to a server that is not local must be protected: checked on every request, before
        // the cache - the session's TLS mode and the socket options are not part of the cache key
        $transport = self::transportProblem($storage, $this->storageHost, $this->storageSsl, $this->config->coreValue('imap_conn_options'), self::coreVersion(), extension_loaded('openssl'));
        if ($transport !== null) {
            if (!$this->config->bool('galbridge_imap_allow_plaintext')) {
                $this->status = 'transport_unprotected';
                Log::once('warning', 'viewer', 'imap_transport', $transport . ': no directory for sessions on this server (or set galbridge_imap_allow_plaintext for a trusted private link)', ['host' => self::endpoint($storage, $this->storagePort)]);
                return $this->memo = null;
            }
            Log::once('warning', 'viewer', 'imap_transport', $transport . ': accepted because galbridge_imap_allow_plaintext is set', ['host' => self::endpoint($storage, $this->storagePort)]);
        }

        $login = Normalizer::address($this->username);
        if ($login === null) {
            $this->status = 'invalid_login';
            return $this->memo = null;
        }

        $fingerprint = $this->config->fingerprint();
        $ttl = $this->config->int('galbridge_scope_ttl', 30, 3600);
        $cache = $_SESSION['galbridge'] ?? null;
        // the entry of THIS session (user, login, IMAP endpoint) - whatever the configuration
        $entry = is_array($cache)
            && ($cache['v'] ?? null) === self::CACHE_VERSION
            && ($cache['uid'] ?? null) === $this->userId
            && ($cache['login'] ?? null) === $login
            && ($cache['host'] ?? null) === $storage
            && ($cache['port'] ?? null) === $this->storagePort
            && is_string($cache['status'] ?? null) ? $cache : null;
        if ($entry !== null && $entry['status'] === 'imap_refused') {
            // the IMAP server refused the credentials of this session once: for good (no new attempt,
            // which fail2ban would count; a new login starts a new session)
            $this->status = 'imap_refused';
            return $this->memo = null;
        }
        if ($entry !== null && ($entry['fp'] ?? null) === $fingerprint && is_int($entry['at'] ?? null)) {
            $viewer = is_array($entry['viewer'] ?? null) ? Viewer::fromArray($entry['viewer']) : null;
            // an "ok" entry carries a usable Viewer of THIS login; one that does not is not an entry
            // this code wrote: it is neither trusted nor kept, the login is looked up again
            if ($entry['status'] !== 'ok' || ($viewer !== null && $viewer->login === $login)) {
                if (time() - $entry['at'] < $ttl) {
                    $this->status = $entry['status'];
                    return $this->memo = ($this->status === 'ok' ? $viewer : null);
                }
                $this->stale = $entry['status'] === 'ok' ? $viewer : null;
            }
        }

        // renewal: the IMAP server confirms the credentials of the session first
        if ($this->imapCheck !== null && !$this->imapConfirmed($entry, $login, $storage, $fingerprint)) {
            return $this->memo = null;
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
     * Ask the IMAP server (the $imapCheck of the plugin) whether it still accepts the credentials of
     * this session - only when the cache entry is renewed. false = no directory in this request:
     * 'imap_refused' (written into the session entry: for good), or 'imap_unavailable' (nothing is
     * asked again before 'imap_retry_at').
     *
     * @param null|array<array-key, mixed> $entry the session entry of this user/login/endpoint
     */
    private function imapConfirmed(?array $entry, string $login, string $storage, string $fingerprint): bool
    {
        $retryAt = $entry['imap_retry_at'] ?? null;
        if (is_int($retryAt) && $retryAt > time()) {
            $this->status = 'imap_unavailable';
            return false;
        }
        try {
            $verdict = $this->imapCheck === null ? true : ($this->imapCheck)();
        } catch (\Throwable) {
            $verdict = null;
        }
        if ($verdict === true) {
            return true;
        }
        $binding = ['v' => self::CACHE_VERSION, 'uid' => $this->userId, 'login' => $login, 'host' => $storage, 'port' => $this->storagePort];
        if ($verdict === false) {
            // OVERWRITTEN, never unset (see confirm()): a key that is merely unset comes back with the
            // next request through Roundcube's session merge
            $_SESSION['galbridge'] = $binding + ['fp' => $fingerprint, 'at' => time(), 'status' => 'imap_refused', 'viewer' => null];
            $this->status = 'imap_refused';
            $this->stale = null;
            Log::once('info', 'viewer', 'viewer_revoked', 'the IMAP server no longer accepts the credentials of this session (changed password, disabled IMAP service): directory access ended for this session');
            return false;
        }
        // cannot be told (unreachable, temporary failure): no revocation - the entry stays as it is
        // (an expired one keeps the books listed as "unavailable"), the next attempt waits
        $_SESSION['galbridge'] = ['imap_retry_at' => time() + Connection::OUTAGE_SECONDS]
            + ($entry ?? $binding + ['fp' => $fingerprint, 'at' => 0, 'status' => 'imap_unavailable', 'viewer' => null]);
        $this->status = 'imap_unavailable';
        Log::once('warning', 'viewer', 'imap_unavailable', 'the IMAP server could not confirm the credentials of this session: the directory is unavailable for now (tried again after ' . Connection::OUTAGE_SECONDS . ' s)');
        return false;
    }

    /**
     * The expired Viewer of this session (same user, login, host and configuration), available only
     * after resolve() failed with a database error or the IMAP server could not be asked: the books
     * can stay listed (answering "directory unavailable") instead of silently disappearing. Never
     * used to read directory data.
     */
    public function staleViewer(): ?Viewer
    {
        return in_array($this->status, ['db_down', 'db_error', 'imap_unavailable'], true) ? $this->stale : null;
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
        $conn = ($this->connection)();
        if ($this->homeDomain($conn, $viewer->login) !== $viewer->home) {
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
        if (!$this->aliasesDecide()) {
            return $this->confirmed = true;
        }
        // the own aliases decide which restricted lists and alias accounts are visible: read again
        $now = [$viewer->login, ...$this->aliases($conn, $viewer->login)];
        if ($now === $viewer->addresses) {
            return $this->confirmed = true;
        }
        $renewed = new Viewer($viewer->login, $viewer->home, $now, $viewer->columns);
        $entry = $_SESSION['galbridge'] ?? null;
        if (is_array($entry)) {
            // the session entry keeps its time: the cache lifetime is not extended
            $_SESSION['galbridge'] = ['viewer' => $renewed->toArray()] + $entry;
        }
        if (array_diff($viewer->addresses, $now) === []) {
            // only additions: the queries of this request were built with fewer addresses - narrower,
            // never wider - so it may go on; the next request uses the new set
            return $this->confirmed = true;
        }
        // an alias was removed: the queries of this request were built with it, so this request reads
        // nothing (the user sees "temporarily unavailable" once); the next one builds them without it
        $this->memo = null;
        $this->status = 'viewer_changed';
        Log::once('info', 'viewer', 'viewer_changed', 'an own alias of this session was removed: this request reads nothing, the next one uses the current aliases');
        return $this->confirmed = false;
    }

    /**
     * Does the viewer's set of own aliases change what a book shows? With the strict list policy
     * the alias accounts and the restricted lists are decided by it.
     */
    private function aliasesDecide(): bool
    {
        return $this->config->choice('galbridge_list_policy', Config::LIST_POLICIES) === 'strict'
            && ($this->config->bool('galbridge_include_aliases') || $this->config->bool('galbridge_include_mailing_lists'));
    }

    /**
     * Look a (normalised) login up in vmail: probe, home domain, address set. No IMAP-host binding
     * and no caching - resolve() adds both; bin/galbridge.sh diag --user uses this directly.
     *
     * The probe refuses a role that can do more than read the directory (role_too_broad, RoleScope):
     * a secret column or the forwardings table, a role attribute, a membership, the database, a
     * global privilege, a privilege outside the directory or one to change anything - and a view
     * galbridge_forwardings that holds a row of another kind than the three of the grant set. What
     * the role may create or owns, and a server-wide test database (MariaDB/MySQL "test", "test_*"
     * open to every account), only give a warning (diag fails).
     *
     * @throws GalBridgeException
     */
    public function lookup(string $login): ?Viewer
    {
        $conn = ($this->connection)();
        // one catalog statement for the columns of every relation; the Viewer keeps those of the
        // relations GAL Bridge reads (the session stays small)
        $all = $conn->visibleColumnsEverywhere();
        $columns = Connection::directoryColumns($all);

        if ($conn->driver() !== 'sqlite') {
            if (array_intersect(Connection::SECRET_COLUMNS, $columns['mailbox'] ?? []) !== []) {
                $this->status = 'role_too_broad';
                Log::error('db', 'role_too_broad: the configured database role can read secret vmail columns (e.g. mailbox.password); use the dedicated role from bin/galbridge.sh grants');
                return null;
            }
            foreach (Connection::FORBIDDEN_TABLES as $table) {
                if (($columns[$table] ?? []) !== []) {
                    $this->status = 'role_too_broad';
                    Log::error('db', 'role_too_broad: the configured database role can read the table "' . $table . '" (private forwarding targets of every mailbox); grant only the view ' . Schema::FORWARDINGS . ' (bin/galbridge.sh grants) and revoke the table grant');
                    return null;
                }
            }
            $scope = RoleScope::read($conn);
            $reasons = $scope->refusals($all);
            if ($reasons !== []) {
                $this->status = 'role_too_broad';
                Log::error('db', 'role_too_broad: the configured database role can do more than read the directory - ' . implode('; ', $reasons) . ' (bin/galbridge.sh diag names every reason)');
                return null;
            }
            if ($scope->viewProblems !== []) {
                // the books keep working (a 1.0.1 view until grants --grants-only is applied again):
                // what the plugin reads is filtered by its own statements, and the content check below
                // refuses a view with other rows; the warning is for the holder of the password
                Log::once('warning', 'db', 'view_not_materialised', 'the view ' . Schema::FORWARDINGS . ' is not the one of the grant set: ' . implode('; ', $scope->viewProblems) . ' - apply bin/galbridge.sh grants --grants-only');
            }
            if ($scope->canCreate || $scope->owned > 0) {
                Log::once('warning', 'db', 'role_can_create', 'the configured database role ' . ($scope->canCreate ? 'may create objects in the vmail database (CREATE on schema public: PostgreSQL 14 and older)' : 'owns ' . $scope->owned . ' object(s)') . ' - apply bin/galbridge.sh grants --grants-only (README 2.2); diag fails until then');
            }
            if ($scope->serverTestDatabases !== []) {
                // a server default open to every account (mariadb-install-db): not a privilege of this
                // role, so no refusal - but whoever holds its password can use those databases
                Log::once('warning', 'db', 'server_test_database', 'the configured database role can see the server-wide test database(s) ' . RoleScope::names($scope->serverTestDatabases) . ', which the server opens to every account (mariadb-install-db) - remove them: mariadb-secure-installation, or as the administrator DROP DATABASE test; DELETE FROM mysql.db WHERE Db IN (\'test\', \'test\\\\_%\'); FLUSH PRIVILEGES; (README 2.2); diag fails until then');
            }
        }

        $missing = Schema::missingRequired($this->config, $columns);
        if ($missing !== []) {
            $this->status = 'column_missing';
            Log::once('error', 'schema', 'column_missing', 'required vmail columns not readable: ' . implode(', ', $missing) . ' (see bin/galbridge.sh diag)');
            return null;
        }
        if (self::viewHoldsOtherRows($conn, $columns)) {
            $this->status = 'role_too_broad';
            Log::error('db', 'role_too_broad: the view ' . Schema::FORWARDINGS . ' holds rows of another kind than alias, alias-member and list rows (private forwarding targets): re-create it from the grant set (bin/galbridge.sh grants --grants-only)');
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
     * Does the view galbridge_forwardings hold a row that is none of the three kinds of the grant
     * set (an alias, an alias member, a list) - a view of that name created by hand without the
     * filter, or replaced, would hand the role the private forwarding targets? Asked through the
     * view itself, on every engine, when the three flags are readable (a NULL flag counts as
     * "another kind" too). One bounded statement per renewal of the viewer cache.
     *
     * @param array<string, list<string>> $columns
     *
     * @throws GalBridgeException
     */
    public static function viewHoldsOtherRows(Connection $conn, array $columns): bool
    {
        if (array_diff(['is_alias', 'is_list', 'is_maillist'], $columns[Schema::FORWARDINGS] ?? []) !== []) {
            return false; // not readable: reported as a missing column where it is needed
        }
        return $conn->select('SELECT 1 AS x FROM `' . Schema::FORWARDINGS . '` f WHERE ' . self::OTHER_KIND, [], 0, 1) !== [];
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
            // the database clock and UTC, whichever comes first (QueryBuilder::unexpired())
            $unexpired = QueryBuilder::unexpired($conn->driver(), gmdate('Y-m-d H:i:s'), 'm.expired', 'd.expired');
            $sql .= ' AND ' . $unexpired->sql;
            array_push($params, ...$unexpired->params);
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
            $unexpired = QueryBuilder::unexpired($conn->driver(), gmdate('Y-m-d H:i:s'), 'fd.expired');
            $sql .= ' AND ' . $unexpired->sql;
            array_push($params, ...$unexpired->params);
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
