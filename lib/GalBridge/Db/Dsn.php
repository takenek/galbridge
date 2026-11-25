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
 *  - the DSN is checked in the form the DRIVER receives it: Roundcube joins host, port, database
 *    and the parameters into the driver's connection string without quoting, so a value with a
 *    separator or a blank would add settings this policy never saw. Only known parameters are
 *    accepted, each once and in the form name=value; host, port, database, socket and every
 *    parameter value must be made of harmless characters; a host or a unix socket is mandatory;
 *  - the target is written in exactly one of the forms host, host:port, [IPv6], [IPv6]:port or
 *    unix(/path/to/socket) (PostgreSQL also unix(/directory:port)), followed by /database and the
 *    parameters: anything the core parser would drop or reinterpret without a word (a second port,
 *    text around an IPv6 address, the old unix+ and tcp() notations, a '?' before the database, a
 *    suffix of the database name) is refused, so what diag shows is what the driver uses;
 *  - a unix socket path fits into sun_path (at most 107 bytes on Linux; for PostgreSQL including the
 *    socket file name ".s.PGSQL.<port>"): longer paths would be cut by PHP or refused by libpq;
 *  - MySQL/MariaDB: the host "localhost" is the unix socket that pdo_mysql.default_socket names
 *    (shown by describe()); a port next to it - or next to a unix() socket - would be ignored by the
 *    driver and is refused. PostgreSQL: "localhost" is looked up by the system resolver on every
 *    connection, so it is accepted as local with a warning (write 127.0.0.1, [::1] or a socket);
 *  - PostgreSQL: environment variables of the PHP process that would send libpq somewhere else than
 *    the DSN says (PGHOSTADDR, PGSERVICE, PGSERVICEFILE, and PGPORT when the DSN has no port) are
 *    refused;
 *  - the role must not be one of the refused roles (Config::refusedUsers(): iRedMail's own, broader
 *    roles, root and postgres - always - and the names galbridge_db_refused_users adds; a malformed
 *    list refuses every connection), nor the user of Roundcube's db_dsnw / db_dsnr or of the
 *    password plugin's DSN - as Roundcube's own
 *    configuration object, the second reading of the files and the password plugin's file have them
 *    (Config::coreDsns());
 *  - a non-loopback host needs TLS that verifies the server certificate AND its host name
 *    (PostgreSQL sslmode=verify-full, MySQL ca/capath + verify_server_cert=1). PostgreSQL
 *    sslmode=verify-ca checks the issuing CA only - any holder of a certificate of that CA could
 *    pose as the server - and is accepted only with galbridge_db_allow_verify_ca (warning).
 *    galbridge_db_allow_plaintext accepts a remote host without any of this (warning);
 *  - a connect timeout is injected when the DSN has none (without it the drivers wait for the
 *    connection up to their built-in default of 30 s - pdo_pgsql including the start-up of the
 *    session - and a dead directory server would hold every webmail request that long). The read
 *    timeout of the MariaDB/MySQL connection (mysqlnd.net_read_timeout: the server's greeting and
 *    every later answer) is set by Connection, see Connection::readTimeout().
 *
 * The DSN handed to the driver (effective()) is not the configured text: it is rebuilt from the
 * parts this policy judged (canonical()), and the core parser must read exactly those parts back
 * from it. It never leaves this object except as that string, handed to the driver class.
 */
final class Dsn
{
    /** query-string parameters this policy knows (value pattern), per driver */
    private const PARAMETERS = [
        'pgsql' => [
            'sslmode' => '/^(disable|allow|prefer|require|verify-ca|verify-full)$/iD',
            'sslrootcert' => self::PATH,
            'sslcert' => self::PATH,
            'sslkey' => self::PATH,
            'sslcrl' => self::PATH,
            'application_name' => '/^[A-Za-z0-9_.-]{1,63}$/D',
            'timeout' => '/^[A-Za-z0-9.+-]{0,16}$/D',
        ],
        'mysql' => [
            'ca' => self::PATH,
            'capath' => self::PATH,
            'cert' => self::PATH,
            'key' => self::PATH,
            'cipher' => '/^[A-Za-z0-9_:+!@.-]{1,255}$/D',
            'verify_server_cert' => '/^(0|1|true|false|on|off|yes|no)$/iD',
            'charset' => '/^[A-Za-z0-9_]{1,32}$/D',
            'timeout' => '/^[A-Za-z0-9.+-]{0,16}$/D',
        ],
    ];

    private const PATH = '~^/[A-Za-z0-9_./+-]{1,1024}$~D';

    /**
     * The longest unix socket path a client can use: sizeof(sockaddr_un.sun_path) on Linux (108)
     * minus the terminating NUL. PHP cuts a longer MySQL socket path (with a notice only), libpq
     * refuses a longer PostgreSQL one.
     */
    public const SOCKET_PATH_MAX = 107;

    /** the target forms of the DSN (raw text between the last '@' and the database) */
    private const TARGET_FORMS = [
        '/^[A-Za-z0-9._-]{1,255}(?::[0-9]{1,5})?$/D',
        '/^\[[0-9A-Fa-f:]{2,39}\](?::[0-9]{1,5})?$/D',
        '~^unix\(/[A-Za-z0-9_./+-]{1,1024}(?::[0-9]{1,5})?\)$~D',
    ];

    private const TARGET_MESSAGE = 'the DSN target must be written as host, host:port, [IPv6], [IPv6]:port or unix(/path/to/socket), followed by /database and then ?parameters (percent-encode a "+" in a parameter value as %2B)';

    /** the pattern of a database name as the driver receives it (also written exactly so) */
    private const DATABASE = '/^[A-Za-z0-9_$-]{1,64}$/D';

    /** libpq environment variables that redirect a connection whatever the DSN says */
    private const LIBPQ_REDIRECTS = ['PGHOSTADDR', 'PGSERVICE', 'PGSERVICEFILE'];

    /** @var array<string, mixed> */
    private array $parsed;

    private string $driver;

    private string $effective;

    /** MySQL/MariaDB "localhost": the socket pdo_mysql.default_socket names ('' otherwise) */
    private string $defaultSocket = '';

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @throws GalBridgeException 'config_invalid' with an internal reason (never containing the password)
     */
    public function __construct(#[\SensitiveParameter] string $dsn, Config $config, #[\SensitiveParameter] ?string $roundcubeDsn = null, #[\SensitiveParameter] ?string $passwordPluginDsn = null)
    {
        if (trim($dsn) === '') {
            throw new GalBridgeException('config_invalid', 'galbridge_db_dsn is not configured');
        }
        if (preg_match('~^[A-Za-z]+://~', $dsn) !== 1) {
            // rcube_db::parse_dsn() still reads the old "driver(syntax)://" notation, while the core's
            // factory takes everything before ':' as the driver (and ends the PHP process on an
            // unknown one). Only the plain scheme, which every reader takes alike, is accepted.
            throw new GalBridgeException('config_invalid', 'the DSN must start with mysql://, mysqli:// or pgsql://');
        }
        $scheme = strtolower((string) preg_replace('~[(:].*$~s', '', $dsn));
        $driver = $scheme === 'mysqli' ? 'mysql' : $scheme;
        $allowed = ['mysql', 'pgsql'];
        if (defined('GALBRIDGE_ALLOW_SQLITE') && constant('GALBRIDGE_ALLOW_SQLITE') === true) {
            $allowed[] = 'sqlite';
        }
        if (!in_array($driver, $allowed, true)) {
            throw new GalBridgeException('config_invalid', 'unsupported database driver (mysql, mysqli or pgsql)');
        }
        $seen = [];
        if ($driver !== 'sqlite') {
            // before the core parser sees it: it reads "name=value" pairs without checking their form
            $seen = self::checkParameters($driver, $dsn);
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        if (isset($parsed['service'])) {
            throw new GalBridgeException('config_invalid', "the PostgreSQL 'service' parameter is not supported");
        }

        if ($driver !== 'sqlite') {
            $protocol = (string) ($parsed['protocol'] ?? 'tcp');
            if ($protocol !== 'tcp' && $protocol !== 'unix') {
                // the core parser took text before the database for a protocol name (a raw '+' or
                // another notation than unix(...)): the host it would use is not the one written
                throw new GalBridgeException('config_invalid', self::TARGET_MESSAGE);
            }
            // a socket next to a host name: which one the driver uses differs (PostgreSQL prefers the
            // host, MySQL uses TCP for a non-localhost host), so refuse the ambiguity
            $hasHost = (string) ($parsed['hostspec'] ?? '') !== '';
            $hasSocket = (string) ($parsed['socket'] ?? '') !== '';
            if ($hasHost && ($hasSocket || $protocol === 'unix')) {
                throw new GalBridgeException('config_invalid', 'the DSN names both a host and a socket');
            }
            if (!$hasHost && !$hasSocket) {
                // without one the client library picks the target itself (libpq: PGHOST, a default socket)
                throw new GalBridgeException('config_invalid', 'the DSN must name a host or a unix socket, e.g. pgsql://role:password@127.0.0.1/vmail');
            }
            self::checkTarget($driver, $parsed);
            // the specific checks above name the part that is wrong; this one makes sure that nothing
            // else was written that the core parser drops or reads differently
            self::checkForm($dsn);
            foreach ($seen as $name => $value) {
                if (!array_key_exists($name, $parsed) || (string) $parsed[$name] !== $value) {
                    throw new GalBridgeException('config_invalid', 'the DSN parameter "' . $name . '" is not read by Roundcube\'s parser: parameters follow the database name');
                }
            }
            if ($driver === 'mysql' && ($parsed['hostspec'] ?? '') === 'localhost') {
                $this->defaultSocket = self::defaultSocket();
            }

            $user = strtolower((string) ($parsed['username'] ?? ''));
            if ($user === '') {
                throw new GalBridgeException('config_invalid', 'the DSN has no user name');
            }
            if (preg_match('/[\s\x00-\x1F\x7F;\'"\\\\]/', $user)) {
                throw new GalBridgeException('config_invalid', 'the DSN user name contains a blank, quote, backslash or semicolon');
            }
            $refused = $config->refusedUsers();
            if ($refused === null) {
                // a malformed list of refused roles (reported by Config): never guessed, never shortened
                throw new GalBridgeException('config_invalid', 'galbridge_db_refused_users is malformed: no database connection (bin/galbridge.sh diag names the entry)');
            }
            // the given ones and every reading of the configuration (Config::coreDsns()): a user that
            // any of them names is refused
            foreach ([$roundcubeDsn, $passwordPluginDsn, ...$config->coreDsns()] as $other) {
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

            if (!self::isLoopback($parsed)) {
                $caOnly = $driver === 'pgsql' && strtolower((string) ($parsed['sslmode'] ?? '')) === 'verify-ca';
                if ($caOnly && $config->bool('galbridge_db_allow_verify_ca')) {
                    $this->warnings[] = 'sslmode=verify-ca does not verify the host name of the database server (galbridge_db_allow_verify_ca): use verify-full';
                } elseif (!self::verifiedTls($driver, $parsed)) {
                    if (!$config->bool('galbridge_db_allow_plaintext')) {
                        throw new GalBridgeException('config_invalid', $caOnly
                            ? 'sslmode=verify-ca does not verify the host name of the database server: use sslmode=verify-full (or galbridge_db_allow_verify_ca for a CA that issues certificates for this server only)'
                            : 'remote database host without verified TLS (pgsql: sslmode=verify-full; mysql: ca=...&verify_server_cert=1)');
                    }
                    $this->warnings[] = 'remote database host without verified TLS (galbridge_db_allow_plaintext)';
                }
            }

            if ($driver === 'pgsql' && ($parsed['hostspec'] ?? '') === 'localhost') {
                // libpq resolves the name (nsswitch, /etc/hosts, DNS) on every connection; the policy
                // treats it as local by its name only
                $this->warnings[] = 'pgsql host localhost is looked up by the system resolver on every connection (and only then treated as local): write 127.0.0.1, [::1] or unix(/var/run/postgresql)';
            }

            if ($driver === 'pgsql') {
                foreach (self::LIBPQ_REDIRECTS as $var) {
                    if (getenv($var) !== false) {
                        throw new GalBridgeException('config_invalid', 'the environment variable ' . $var . ' of the PHP process would redirect the PostgreSQL connection: unset it');
                    }
                }
                if (getenv('PGPORT') !== false && !isset($parsed['port'])) {
                    throw new GalBridgeException('config_invalid', 'the environment variable PGPORT of the PHP process would choose the PostgreSQL port: unset it or put the port into the DSN');
                }
                foreach (['PGOPTIONS', 'PGSSLMODE', 'PGREQUIRESSL'] as $var) {
                    if (getenv($var) !== false && !($var === 'PGSSLMODE' && isset($parsed['sslmode']))) {
                        $this->warnings[] = 'the environment variable ' . $var . ' of the PHP process also applies to this connection';
                    }
                }
            }
        }

        $effective = $dsn;
        if ($driver !== 'sqlite') {
            if (array_key_exists('timeout', $parsed)) {
                // a timeout the administrator wrote is validated, never overridden
                $t = (string) $parsed['timeout'];
                if (!ctype_digit($t) || (int) $t < 1 || (int) $t > 30) {
                    throw new GalBridgeException('config_invalid', 'the DSN timeout must be 1..30 seconds');
                }
            } else {
                $parsed['timeout'] = (string) $config->int('galbridge_db_connect_timeout', 1, 30);
            }
            // The driver receives the judged parts - rebuilt, never the configured text with a
            // suffix - and the core parser has to read exactly those parts back from it.
            // (the scheme as written: letters only, checked above, and the phptype the parser reports)
            $effective = self::canonical(substr($dsn, 0, (int) strpos($dsn, '://')), $driver, $parsed);
            $reread = \rcube_db::parse_dsn($effective);
            $judged = $parsed;
            ksort($reread);
            ksort($judged);
            if ($reread !== $judged) {
                throw new GalBridgeException('config_invalid', 'the DSN cannot be passed to the driver unchanged (write it in the form shown in config.inc.php.dist)');
            }
        }

        $this->parsed = $parsed;
        $this->driver = $driver;
        $this->effective = $effective;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * The DSN string handed to the driver class: rebuilt from the judged parts, with the connect
     * timeout as the last parameter (SQLite, tests only: the configured text).
     */
    public function effective(): string
    {
        return $this->effective;
    }

    /**
     * The connect timeout in seconds that the driver receives (PDO::ATTR_TIMEOUT); 0 for SQLite.
     */
    public function connectTimeout(): int
    {
        return $this->driver === 'sqlite' ? 0 : (int) ($this->parsed['timeout'] ?? 0);
    }

    /**
     * The unix socket file the driver connects to, or null for TCP: MySQL/MariaDB a unix() socket or,
     * for "localhost", pdo_mysql.default_socket; PostgreSQL the socket file in the unix() directory.
     */
    public function socketPath(): ?string
    {
        if ($this->driver === 'mysql') {
            $socket = (string) ($this->parsed['socket'] ?? '');
            return $socket !== '' ? $socket : ($this->defaultSocket !== '' ? $this->defaultSocket : null);
        }
        if ($this->driver === 'pgsql' && (string) ($this->parsed['socket'] ?? '') !== '') {
            return rtrim((string) $this->parsed['socket'], '/') . '/.s.PGSQL.' . (string) ($this->parsed['port'] ?? '5432');
        }
        return null;
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
     * Is the database reached over the network (anything but a unix socket or a loopback address)?
     */
    public function remote(): bool
    {
        return $this->driver !== 'sqlite' && !self::isLoopback($this->parsed);
    }

    /**
     * Host description without credentials, for diag.
     */
    public function describe(): string
    {
        if ($this->driver === 'sqlite') {
            return 'sqlite (test only)';
        }
        if (isset($this->parsed['socket'])) {
            $where = 'unix(' . $this->parsed['socket'] . (isset($this->parsed['port']) ? ':' . $this->parsed['port'] : '') . ')';
        } else {
            $host = (string) ($this->parsed['hostspec'] ?? '');
            $where = (str_contains($host, ':') ? '[' . $host . ']' : $host) . (isset($this->parsed['port']) ? ':' . $this->parsed['port'] : '');
            if ($this->defaultSocket !== '') {
                // the endpoint the driver really uses
                $where .= ' (socket ' . $this->defaultSocket . ')';
            }
        }
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
    public static function isLoopback(#[\SensitiveParameter] array $parsed): bool
    {
        $hostspec = (string) ($parsed['hostspec'] ?? '');
        if ($hostspec === '' && (($parsed['protocol'] ?? 'tcp') === 'unix' || !empty($parsed['socket']))) {
            return true;
        }
        // a host name always decides (a socket parameter next to it must not make it "local")
        $host = strtolower(trim($hostspec !== '' ? $hostspec : 'localhost', '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }

    /**
     * TLS that authenticates the server: its certificate chain AND its host name.
     *
     * @param array<string, mixed> $parsed
     */
    public static function verifiedTls(string $driver, #[\SensitiveParameter] array $parsed): bool
    {
        if ($driver === 'pgsql') {
            // verify-ca stops at the chain: not "verified" (see the class comment)
            return strtolower((string) ($parsed['sslmode'] ?? '')) === 'verify-full';
        }
        $verify = strtolower((string) ($parsed['verify_server_cert'] ?? ''));
        return (!empty($parsed['ca']) || !empty($parsed['capath'])) && in_array($verify, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * The query string of the DSN ("?name=value&..."): known names only, each once, in the form
     * name=value. The core parser splits at every '=' and '&' and lets a parameter fill any setting
     * the DSN did not give (?hostspec=..., ?port=..., ?socket=...), so anything else is refused.
     *
     * @return array<string, string> the parameters seen (name => decoded value)
     *
     * @throws GalBridgeException
     */
    private static function checkParameters(string $driver, #[\SensitiveParameter] string $dsn): array
    {
        // the password ends at the LAST '@' (rcube_db::parse_dsn); the first '?' after it starts the parameters
        $at = strrpos($dsn, '@');
        $tail = $at === false ? $dsn : substr($dsn, $at + 1);
        $q = strpos($tail, '?');
        if ($q === false) {
            return [];
        }
        $seen = [];
        foreach (explode('&', substr($tail, $q + 1)) as $pair) {
            $parts = explode('=', $pair);
            $name = $parts[0];
            if (count($parts) !== 2 || !preg_match('/^[a-z_]{1,32}$/D', $name)) {
                throw new GalBridgeException('config_invalid', 'malformed DSN parameter (expected name=value; percent-encode = and & inside a value)');
            }
            if ($name === 'service') {
                throw new GalBridgeException('config_invalid', "the PostgreSQL 'service' parameter is not supported");
            }
            $pattern = self::PARAMETERS[$driver][$name] ?? null;
            if ($pattern === null) {
                throw new GalBridgeException('config_invalid', 'unknown DSN parameter "' . $name . '" (allowed: ' . implode(', ', array_keys(self::PARAMETERS[$driver])) . ')');
            }
            if (isset($seen[$name])) {
                throw new GalBridgeException('config_invalid', 'the DSN parameter "' . $name . '" is given twice');
            }
            $seen[$name] = rawurldecode($parts[1]);
            if (preg_match($pattern, $seen[$name]) !== 1) {
                throw new GalBridgeException('config_invalid', 'invalid value of the DSN parameter "' . $name . '"');
            }
        }
        return $seen;
    }

    /**
     * Host, port, socket and database exactly as the driver's connection string receives them.
     *
     * @param array<string, mixed> $parsed
     *
     * @throws GalBridgeException
     */
    private static function checkTarget(string $driver, #[\SensitiveParameter] array $parsed): void
    {
        $host = (string) ($parsed['hostspec'] ?? '');
        if ($host !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $host)) {
            throw new GalBridgeException('config_invalid', 'the DSN host contains characters that are not part of a host name or an IP address');
        }
        if ($host !== 'localhost' && strtolower($host) === 'localhost') {
            // pdo_mysql uses pdo_mysql.default_socket for "localhost" only, while mysqlnd treats any
            // spelling as local and then takes its built-in socket path (/tmp/mysql.sock): the policy
            // would judge one endpoint and the driver use another
            throw new GalBridgeException('config_invalid', 'write the DSN host localhost in lower case (client libraries treat other spellings differently)');
        }
        if (isset($parsed['port'])) {
            $port = (string) $parsed['port'];
            if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new GalBridgeException('config_invalid', 'the DSN port must be a number (1..65535)');
            }
        }
        $socket = $parsed['socket'] ?? null;
        if (is_string($socket) && $socket !== '') {
            if (preg_match(self::PATH, $socket) !== 1) {
                throw new GalBridgeException('config_invalid', 'the DSN socket must be an absolute path without blanks or separators');
            }
            // PostgreSQL: the DSN names the directory, libpq adds "/.s.PGSQL.<port>" (as written)
            $file = $driver === 'pgsql' ? $socket . '/.s.PGSQL.' . (string) ($parsed['port'] ?? '5432') : $socket;
            if (strlen($file) > self::SOCKET_PATH_MAX) {
                throw new GalBridgeException('config_invalid', 'the unix socket path is too long (at most ' . self::SOCKET_PATH_MAX . ' bytes including the PostgreSQL socket file name)');
            }
            if ($driver === 'mysql' && isset($parsed['port'])) {
                throw new GalBridgeException('config_invalid', 'MySQL ignores the port of a unix socket: remove it from unix(...)');
            }
        }
        if ($driver === 'mysql' && $host === 'localhost' && isset($parsed['port'])) {
            // mysqlnd and libmysqlclient connect to "localhost" through the unix socket
            throw new GalBridgeException('config_invalid', 'MySQL connects to localhost through its unix socket and ignores the port: write 127.0.0.1:PORT, or unix(/path/to/socket) for the socket');
        }
        if (!preg_match(self::DATABASE, (string) ($parsed['database'] ?? ''))) {
            throw new GalBridgeException('config_invalid', 'the DSN database name is missing or contains characters other than letters, digits, _ $ -');
        }
    }

    /**
     * The raw text after the credentials: one of TARGET_FORMS, then "/" and the database name
     * written as the driver receives it, then nothing or "?" and the parameters (checkParameters()).
     *
     * @throws GalBridgeException
     */
    private static function checkForm(#[\SensitiveParameter] string $dsn): void
    {
        $rest = substr($dsn, (int) strpos($dsn, '://') + 3);
        $at = strrpos($rest, '@');
        $tail = $at === false ? $rest : substr($rest, $at + 1);
        $end = str_starts_with($tail, 'unix(') ? strpos($tail, ')') : strpos($tail, '/');
        if ($end === false) {
            throw new GalBridgeException('config_invalid', self::TARGET_MESSAGE);
        }
        if (str_starts_with($tail, 'unix(')) {
            $end++;
        }
        $target = substr($tail, 0, $end);
        $matched = false;
        foreach (self::TARGET_FORMS as $form) {
            $matched = $matched || preg_match($form, $target) === 1;
        }
        if (!$matched) {
            throw new GalBridgeException('config_invalid', self::TARGET_MESSAGE);
        }
        $after = substr($tail, $end);
        $q = strpos($after, '?');
        $database = $q === false ? $after : substr($after, 0, $q);
        if (!str_starts_with($database, '/') || preg_match(self::DATABASE, substr($database, 1)) !== 1) {
            // the core parser decodes the name and cuts it at ';' (#7034) without a word
            throw new GalBridgeException('config_invalid', 'write the DSN database name as it is (letters, digits, _ $ -; no percent-encoding, nothing between it and ?parameters)');
        }
    }

    /**
     * pdo_mysql.default_socket: the socket pdo_mysql uses for the host "localhost".
     *
     * @throws GalBridgeException
     */
    private static function defaultSocket(): string
    {
        $socket = ini_get('pdo_mysql.default_socket');
        if (!is_string($socket) || preg_match(self::PATH, $socket) !== 1 || strlen($socket) > self::SOCKET_PATH_MAX) {
            throw new GalBridgeException('config_invalid', 'the MySQL host localhost means the socket in pdo_mysql.default_socket, which is not set or not usable here: write unix(/run/mysqld/mysqld.sock) or 127.0.0.1');
        }
        return $socket;
    }

    /**
     * The DSN for the driver, built from the judged parts: the scheme as written, the credentials
     * percent-encoded, the target in its canonical form, the database, and the known parameters
     * (percent-encoded, in the order written) with the connect timeout last.
     *
     * @param array<string, mixed> $parsed
     */
    private static function canonical(string $scheme, string $driver, #[\SensitiveParameter] array $parsed): string
    {
        $dsn = $scheme . '://' . rawurlencode((string) ($parsed['username'] ?? ''));
        if (array_key_exists('password', $parsed)) {
            $dsn .= ':' . rawurlencode((string) $parsed['password']);
        }
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        if (isset($parsed['socket'])) {
            $dsn .= '@unix(' . $parsed['socket'] . $port . ')';
        } else {
            $host = (string) ($parsed['hostspec'] ?? '');
            $dsn .= '@' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . $port;
        }
        $dsn .= '/' . rawurlencode((string) ($parsed['database'] ?? ''));
        $params = [];
        foreach ($parsed as $name => $value) {
            if ($name !== 'timeout' && isset(self::PARAMETERS[$driver][$name])) {
                $params[] = $name . '=' . rawurlencode((string) $value);
            }
        }
        $params[] = 'timeout=' . rawurlencode((string) ($parsed['timeout'] ?? ''));
        return $dsn . '?' . implode('&', $params);
    }
}
