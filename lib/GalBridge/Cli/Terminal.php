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

namespace GalBridge\Cli;

use GalBridge\Log;
use GalBridge\Normalizer;

/**
 * What the administration tool writes to a terminal.
 *
 * Text that reaches the terminal of an administrator must not be able to move the cursor, rewrite
 * or hide lines, switch the terminal into another mode or reorder what is shown: values read from a
 * database server, exception texts, file names and messages of other code are never trusted to be
 * free of such characters. clean() keeps every line visible as text: control characters (C0 except
 * the newline, DEL, C1), format characters (bidi controls, zero-width characters, BOM, soft hyphen),
 * line and paragraph separators and every byte of an invalid UTF-8 sequence become visible escapes
 * such as \x1B or \u{202E}. ASCII text, newlines and letters stay as they are, so ordinary output
 * (and the SQL that "grants" prints) is byte-identical.
 *
 * Pure functions: no I/O, no state.
 */
final class Terminal
{
    /**
     * One valid UTF-8 sequence (RFC 3629, no overlong forms, no surrogates) - or, in group 1, a single
     * byte that does not start one. Byte-level on purpose (no /u): invalid input must be described,
     * not rejected as a whole.
     */
    private const SEQUENCE = '/[\x20-\x7E\n]+'
        . '|[\x00-\x7F]'
        . '|[\xC2-\xDF][\x80-\xBF]'
        . '|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
        . '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}'
        . '|([\x80-\xFF])/s';

    /** characters a terminal must never receive raw (one UTF-8 character, u modifier) */
    private const UNSAFE = '/^(?:[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|' . Normalizer::INVISIBLE . ')$/u';

    /**
     * The text with every character that could steer or deceive a terminal replaced by a visible
     * escape: \xNN for a byte of invalid UTF-8 and for a character below U+0100 (TAB, CR, ESC, DEL,
     * the C1 controls), \u{XXXX} above (U+202E, U+200B, U+2028, U+FEFF ...).
     */
    public static function clean(#[\SensitiveParameter] string $s): string
    {
        if (preg_match('/^[\x20-\x7E\n]*$/D', $s) === 1) {
            return $s; // the usual case: printable ASCII and newlines only
        }
        return (string) preg_replace_callback(self::SEQUENCE, static function (array $m): string {
            if (isset($m[1])) {
                return sprintf('\\x%02X', ord($m[1]));
            }
            $text = $m[0];
            if (strlen($text) > 1 && preg_match('/^[\x20-\x7E\n]+$/D', $text) === 1) {
                return $text;
            }
            if (strlen($text) === 1) {
                $byte = ord($text);
                return ($byte >= 0x20 && $byte !== 0x7F) || $text === "\n" ? $text : sprintf('\\x%02X', $byte);
            }
            if (preg_match(self::UNSAFE, $text) !== 1) {
                return $text;
            }
            $cp = self::codePoint($text);
            return $cp < 0x100 ? sprintf('\\x%02X', $cp) : sprintf('\\u{%04X}', $cp);
        }, $s);
    }

    /**
     * A PHP message (warning, notice, deprecation ...) as one line of the tool's own format, for the
     * error handler of bin/galbridge.sh: the message and the file are sanitised like log values, so
     * neither can carry a terminal control or a line break into the log or the terminal, and a DSN
     * password a message might quote is masked.
     */
    public static function phpMessage(int $type, string $message, string $file, int $line): string
    {
        $level = match ($type) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            E_USER_ERROR => 'Fatal error',
            E_RECOVERABLE_ERROR => 'Recoverable fatal error',
            default => 'Error',
        };
        // clean() as well: the line may go to the error log, which PHP writes to the terminal itself
        // when no destination is set (before Roundcube's configuration is read)
        return self::clean('PHP ' . $level . ': ' . Log::sanitize($message) . ($file !== '' ? ' in ' . Log::sanitize($file) . ' on line ' . $line : ''));
    }

    /**
     * The code point of one valid UTF-8 character.
     */
    private static function codePoint(string $char): int
    {
        $bytes = array_values(unpack('C*', $char) ?: []);
        $n = count($bytes);
        if ($n === 0) {
            return 0;
        }
        $first = (int) $bytes[0];
        $cp = match ($n) {
            1 => $first,
            2 => $first & 0x1F,
            3 => $first & 0x0F,
            default => $first & 0x07,
        };
        for ($i = 1; $i < $n; $i++) {
            $cp = ($cp << 6) | ((int) $bytes[$i] & 0x3F);
        }
        return $cp;
    }
}
