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

namespace GalBridge;

/**
 * Central, redacting logger.
 *
 * Writes to the Roundcube log "galbridge" (rcube::write_log). Every message and context value is
 * sanitised: control and bidi characters (log injection, display spoofing) are replaced, invalid UTF-8
 * is redacted and values are truncated. Normal levels are meant for reason codes, numeric ids and
 * counts; search terms, addresses and names belong in debug() only. Callers must never pass secrets
 * (DSNs, passwords) on purpose; the sanitising is a safety net, not a licence.
 */
final class Log
{
    private const MAX_VALUE = 300;

    /** @var null|callable(string): void */
    private static $sink;

    private static bool $debug = false;

    /**
     * Replace the output sink (tests).
     *
     * @param null|callable(string): void $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    public static function isDebug(): bool
    {
        return self::$debug;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $operation, string $message, array $context = []): void
    {
        self::write('ERROR', $operation, $message, $context);
    }

    /**
     * Unexpected exception messages may contain user data or infrastructure details. Keep the
     * exception type in normal logs; emit the sanitised message only with explicit debug logging.
     *
     * @param array<string, mixed> $context Fixed operational context, never exception-derived data
     */
    public static function exception(string $operation, \Throwable $exception, array $context = []): void
    {
        self::error($operation, 'unexpected error', ['exception' => get_class($exception)] + $context);
        self::debug($operation, $exception->getMessage(), ['exception' => get_class($exception)] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $operation, string $message, array $context = []): void
    {
        self::write('WARN', $operation, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $operation, string $message, array $context = []): void
    {
        self::write('INFO', $operation, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $operation, string $message, array $context = []): void
    {
        if (self::$debug) {
            self::write('DEBUG', $operation, $message, $context);
        }
    }

    /** @var array<string, true> reason codes already logged in this request */
    private static array $once = [];

    /**
     * Log a reason code at most once per request (crafted requests or per-row conditions must not be
     * able to flood the mail log). $level: error | warning | info.
     *
     * @param array<string, mixed> $context
     */
    public static function once(string $level, string $operation, string $code, string $message, array $context = []): void
    {
        if (isset(self::$once[$operation . ':' . $code])) {
            return;
        }
        self::$once[$operation . ':' . $code] = true;
        self::write(match ($level) {
            'error' => 'ERROR',
            'info' => 'INFO',
            default => 'WARN',
        }, $operation, $code . ': ' . $message, $context);
    }

    /**
     * Forget the once-per-request memo (tests, long-running CLI).
     */
    public static function resetOnce(): void
    {
        self::$once = [];
    }

    /**
     * Short, non-reversible tag of an identifier (e.g. a rejected contact ID) for normal log lines:
     * enough to correlate repeated attempts, useless for recovering the value.
     */
    public static function tag(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }

    /**
     * Sanitise a single value for the log.
     */
    public static function sanitize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                if (count($parts) >= 20) {
                    $parts[] = '...'; // only when something is really left out
                    break;
                }
                $parts[] = (is_int($k) ? '' : self::sanitize((string) $k) . '=') . self::sanitize(is_scalar($v) || $v === null ? $v : get_debug_type($v));
            }
            return '[' . implode(', ', $parts) . ']';
        }
        if (!is_scalar($value)) {
            return get_debug_type($value);
        }

        $s = (string) $value;
        // DSN credentials (scheme://user:password@host) - never log a password, even by accident;
        // rcube_db::parse_dsn() reads the password up to the LAST '@', so redact greedily
        $s = (string) preg_replace('~([a-z][a-z0-9+.-]*://[^:@/\s]*):\S*@~i', '$1:***@', $s);
        // invalid UTF-8 / binary
        if (!preg_match('//u', $s)) {
            $s = '[BINARY-REDACTED]';
        }
        // control characters (CR/LF/TAB/ESC ...), NEL, line/paragraph separators, bidi and zero-width
        // characters -> log injection and display spoofing protection
        $s = (string) preg_replace('/[\x00-\x1F\x7F]|[\x{0080}-\x{009F}]|' . Normalizer::INVISIBLE . '/u', ' ', $s);

        if (mb_strlen($s) > self::MAX_VALUE) {
            $s = mb_substr($s, 0, self::MAX_VALUE) . '...';
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $operation, string $message, array $context): void
    {
        $line = sprintf('%s %s: %s', $level, self::sanitize($operation), self::sanitize($message));

        if (!array_key_exists('user', $context) && class_exists('rcube', false)) {
            $rc = \rcube::get_instance();
            if (!empty($rc->user) && !empty($rc->user->ID)) {
                $context = ['user' => (int) $rc->user->ID] + $context;
            }
        }

        foreach ($context as $key => $value) {
            $line .= ' ' . self::sanitize((string) $key) . '=' . self::sanitize($value);
        }

        if (self::$sink !== null) {
            (self::$sink)($line);
            return;
        }

        if (class_exists('rcube', false)) {
            \rcube::write_log('galbridge', $line);
        } else {
            error_log('galbridge: ' . $line);
        }
    }
}
