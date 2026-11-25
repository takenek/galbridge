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
 *  - PostgreSQL: environment variables of the PHP process that would send libpq somewhere else than
 *    the DSN says (PGHOSTADDR, PGSERVICE, PGSERVICEFILE, and PGPORT when the DSN has no port) are
 *    refused;
 *  - the role must not be one of galbridge_db_refused_users (iRedMail's own, broader roles), the
 *    Roundcube db_dsnw user or the password plugin's DSN user;
 *  - a non-loopback host needs TLS that verifies the server certificate AND its host name
 *    (PostgreSQL sslmode=verify-full, MySQL ca/capath + verify_server_cert=1). PostgreSQL
 *    sslmode=verify-ca checks the issuing CA only - any holder of a certificate of that CA could
 *    pose as the server - and is accepted only with galbridge_db_allow_verify_ca (warning).
 *    galbridge_db_allow_plaintext accepts a remote host without any of this (warning);
 *  - a connect timeout is injected when the DSN has none (PDO otherwise waits up to
 *    default_socket_timeout, or forever with libpq, and a dead directory server would block every
 *    webmail request).
 *
 * The DSN never leaves this object except as the string handed to rcube_db::factory().
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

    /** libpq environment variables that redirect a connection whatever the DSN says */
    private const LIBPQ_REDIRECTS = ['PGHOSTADDR', 'PGSERVICE', 'PGSERVICEFILE'];

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
        if (preg_match('~^[A-Za-z]+://~', $dsn) !== 1) {
            // rcube_db::parse_dsn() still reads the old "driver(syntax)://" notation, while
            // rcube_db::factory() takes everything before ':' as the driver - and ends the PHP process
            // on an unknown one. Only the form both read alike is accepted.
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
        if ($driver !== 'sqlite') {
            // before the core parser sees it: it reads "name=value" pairs without checking their form
            self::checkParameters($driver, $dsn);
        }
        $parsed = \rcube_db::parse_dsn($dsn);
        if (isset($parsed['service'])) {
            throw new GalBridgeException('config_invalid', "the PostgreSQL 'service' parameter is not supported");
        }

        if ($driver !== 'sqlite') {
            // a socket next to a host name: which one the driver uses differs (PostgreSQL prefers the
            // host, MySQL uses TCP for a non-localhost host), so refuse the ambiguity
            $hasHost = (string) ($parsed['hostspec'] ?? '') !== '';
            $hasSocket = (string) ($parsed['socket'] ?? '') !== '';
            if ($hasHost && ($hasSocket || ($parsed['protocol'] ?? 'tcp') === 'unix')) {
                throw new GalBridgeException('config_invalid', 'the DSN names both a host and a socket');
            }
            if (!$hasHost && !$hasSocket) {
                // without one the client library picks the target itself (libpq: PGHOST, a default socket)
                throw new GalBridgeException('config_invalid', 'the DSN must name a host or a unix socket, e.g. pgsql://role:password@127.0.0.1/vmail');
            }
            self::checkTarget($driver, $parsed);

            $user = strtolower((string) ($parsed['username'] ?? ''));
            if ($user === '') {
                throw new GalBridgeException('config_invalid', 'the DSN has no user name');
            }
            if (preg_match('/[\s\x00-\x1F\x7F;\'"\\\\]/', $user)) {
                throw new GalBridgeException('config_invalid', 'the DSN user name contains a blank, quote, backslash or semicolon');
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

        if ($driver !== 'sqlite') {
            if (array_key_exists('timeout', $parsed)) {
                // rcube_db never lets a later parameter overwrite an earlier one: validate, never append
                $t = (string) $parsed['timeout'];
                if (!ctype_digit($t) || (int) $t < 1 || (int) $t > 30) {
                    throw new GalBridgeException('config_invalid', 'the DSN timeout must be 1..30 seconds');
                }
            } else {
                $timeout = $config->int('galbridge_db_connect_timeout', 1, 30);
                // rcube_db::parse_dsn() ends the credentials at the LAST '@', and only a '?' after it
                // starts the parameters: a '?' in the password is part of the password. Appended with
                // '&' there, the timeout would become part of the database name.
                $at = strrpos($dsn, '@');
                $target = $at === false ? $dsn : substr($dsn, $at + 1);
                $dsn .= (str_contains($target, '?') ? '&' : '?') . 'timeout=' . $timeout;
                $parsed['timeout'] = $timeout;
            }
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
    public static function verifiedTls(string $driver, array $parsed): bool
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
     * @throws GalBridgeException
     */
    private static function checkParameters(string $driver, string $dsn): void
    {
        // the password ends at the LAST '@' (rcube_db::parse_dsn); the first '?' after it starts the parameters
        $at = strrpos($dsn, '@');
        $tail = $at === false ? $dsn : substr($dsn, $at + 1);
        $q = strpos($tail, '?');
        if ($q === false) {
            return;
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
            $seen[$name] = true;
            if (preg_match($pattern, rawurldecode($parts[1])) !== 1) {
                throw new GalBridgeException('config_invalid', 'invalid value of the DSN parameter "' . $name . '"');
            }
        }
    }

    /**
     * Host, port, socket and database exactly as the driver's connection string receives them.
     *
     * @param array<string, mixed> $parsed
     *
     * @throws GalBridgeException
     */
    private static function checkTarget(string $driver, array $parsed): void
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
        if (is_string($socket) && $socket !== '' && preg_match(self::PATH, $socket) !== 1) {
            throw new GalBridgeException('config_invalid', 'the DSN socket must be an absolute path without blanks or separators');
        }
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', (string) ($parsed['database'] ?? ''))) {
            throw new GalBridgeException('config_invalid', 'the DSN database name is missing or contains characters other than letters, digits, _ $ -');
        }
    }
}
