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
 * Writes to the Roundcube log "galbridge" (rcube::write_log), one line per entry:
 * "LEVEL operation: message key=value ..."; an ERROR or WARN line that Roundcube refuses to write
 * there goes to its "errors" log instead (write()). Every message and context value is sanitised (sanitize(),
 * in this order): invalid UTF-8 redacts the whole value; control characters (C0, C1) and every format
 * character (Unicode Cf: bidi, zero-width, soft hyphen, tag characters ...) and the line separators
 * are replaced by a blank (log injection, display spoofing); passwords are masked (maskCredentials():
 * scheme://user:password@host - also with blanks in the password, a '/' in the user name and the old
 * scheme(syntax):// notation - and password=... / PGPASSWORD=... forms); the value is cut to 300
 * characters. A context value that contains a blank, '=', '"' or '\' is written in double quotes
 * (backslash and quote escaped), so a log parser cannot read a part of it as another key=value pair.
 * Normal levels are meant for reason codes, numeric ids and counts; search terms, addresses and names
 * belong in debug() only. Callers must never pass secrets (DSNs, passwords) on purpose; the sanitising
 * is a safety net, not a licence - parameters that carry one are marked #[\SensitiveParameter], so
 * they do not appear in a stack trace either.
 */
final class Log
{
    private const MAX_VALUE = 300;

    /** a URL scheme, also in the old notation "driver(syntax)" that rcube_db::parse_dsn() reads */
    private const SCHEME = '[a-z][a-z0-9+.\-]*(?:\([^)\s]*\))?://';

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
     * Log a reason code at most once per request: per-row conditions and repeated attempts inside one
     * request write one line, not one per row. A loop of requests still adds up to one line per
     * reason code per request - bounded by the request rate, which the plugin does not limit (a
     * reverse-proxy limit does, SECURITY.md). $level: error | warning | info.
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
     * The text with every password replaced by ***. Used by sanitize() and by the filter
     * bin/galbridge.sh puts on standard error, so both mask the same:
     *  - a URL / DSN scheme://user:password@host - the user may contain '/', the password anything
     *    (blanks, '@', '/', ':') up to the LAST '@' before the next scheme:// on the line, as
     *    rcube_db::parse_dsn() reads the password up to the last '@'; also scheme(syntax)://;
     *  - name=value pairs named pass, password, passwd or pwd - also with a prefix ending in '_' and
     *    PostgreSQL's PG (password=, PGPASSWORD=, db_pass= ...) - with a bare, a single- or a
     *    double-quoted value, and "password": "value".
     * It masks rather too much than too little (an '@' later on the line ends the masked part there).
     * A text the patterns cannot be applied to (PCRE limits) is replaced as a whole.
     */
    public static function maskCredentials(#[\SensitiveParameter] string $s): string
    {
        $masked = preg_replace(
            [
                '~(' . self::SCHEME . '[^:@\s]*):(?:(?!' . self::SCHEME . ').)*@~i',
                '~\b((?:[a-z0-9]+_)*(?:pg)?(?:pass(?:word|wd)?|pwd))(\s*=\s*)(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s;&,\'"]*)~i',
                '~(["\'])((?:[a-z0-9]+_)*(?:pg)?(?:pass(?:word|wd)?|pwd))\1(\s*:\s*)(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')~i',
            ],
            ['$1:***@', '$1$2***', '$1$2$1$3"***"'],
            $s,
        );
        return is_string($masked) ? $masked : '[REDACTED]';
    }

    /**
     * Sanitise a single value for the log (see the class comment for the order of the steps).
     */
    public static function sanitize(#[\SensitiveParameter] mixed $value): string
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
        // invalid UTF-8 / binary: nothing of the value survives
        if (!preg_match('//u', $s)) {
            return '[BINARY-REDACTED]';
        }
        // control characters (CR/LF/TAB/ESC ..., C1, NEL), every format character (bidi, zero-width,
        // soft hyphen, tag characters ...), line/paragraph separators -> log injection and display
        // spoofing protection. Before the masks: a tab or a line break inside a password is a blank
        // for them, never the end of what they mask.
        $s = (string) preg_replace('/\p{Cc}|\p{Cf}|' . Normalizer::INVISIBLE . '/u', ' ', $s);
        $s = self::maskCredentials($s);

        if (mb_strlen($s) > self::MAX_VALUE) {
            $s = mb_substr($s, 0, self::MAX_VALUE) . '...';
        }

        return $s;
    }

    /**
     * One context pair: the key (a code constant; any character outside [A-Za-z0-9_.-] becomes '_')
     * and the sanitised value, in double quotes when it contains a blank, '=', '"' or '\' (backslash
     * and quote escaped with a backslash), so that it cannot be read as further pairs.
     */
    private static function pair(string $key, mixed $value): string
    {
        $key = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '_', self::sanitize($key));
        $value = self::sanitize($value);
        if (preg_match('/[\s\p{Z}="\\\\]/u', $value) === 1) {
            $value = '"' . addcslashes($value, '"\\') . '"';
        }
        return $key . '=' . $value;
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
            $line .= ' ' . self::pair((string) $key, $value);
        }

        if (self::$sink !== null) {
            (self::$sink)($line);
            return;
        }

        if (!class_exists('rcube', false)) {
            error_log('galbridge: ' . $line);
            return;
        }
        if (\rcube::write_log('galbridge', $line) !== false || ($level !== 'ERROR' && $level !== 'WARN')) {
            return;
        }
        // Roundcube refused the line: per_user_logging without a log directory of this user (or
        // without a user), an unwritable file, a failing syslog. An ERROR or WARN line - a refused
        // role, an invalid configuration, a row out of scope - must not disappear without a trace:
        // Roundcube's errors log (which per_user_logging still writes to the main log directory),
        // and PHP's error log as the last resort. INFO and DEBUG lines follow Roundcube's decision.
        if (\rcube::write_log('errors', 'galbridge: ' . $line) === false) {
            error_log('galbridge: ' . $line);
        }
    }
}
