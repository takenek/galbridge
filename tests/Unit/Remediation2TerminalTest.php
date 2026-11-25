<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Cli\StderrFilter;
use GalBridge\Cli\Terminal;
use GalBridge\Config;
use GalBridge\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-12 (terminal output of the administration tool), without Roundcube:
 *
 *  - Terminal::clean(), through which every line of the tool goes: control, format and separator
 *    characters and every byte of invalid UTF-8 become visible escapes; ASCII, newlines and letters
 *    are unchanged - the SQL that "grants" prints is byte-identical;
 *  - the filter bin/galbridge.sh puts on standard error (what Roundcube and the database drivers
 *    write there) and the one-line form of PHP messages;
 *  - Log::maskCredentials(), the DSN mask shared by the log and that filter;
 *  - Config::unixEndpoint(): a local socket path of printable ASCII only, so a stored mail_host
 *    with any other byte is never "canonical" (and never printed by diag).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2TerminalTest extends TestCase
{
    /**
     * @return array<string, array{string, string}> text => what it must become
     */
    public static function unsafeCharacters(): array
    {
        return [
            'ESC of a CSI sequence' => ["a\x1b[31mred\x1b[0m", 'a\x1B[31mred\x1B[0m'],
            'clear screen' => ["\x1b[2J\x1b[H", '\x1B[2J\x1B[H'],
            'BEL' => ["bell\x07", 'bell\x07'],
            'CR (overwrites the line)' => ["[FAIL] x\r[ OK ] x", '[FAIL] x\x0D[ OK ] x'],
            'TAB' => ["a\tb", 'a\x09b'],
            'NUL' => ["a\x00b", 'a\x00b'],
            'DEL' => ["a\x7fb", 'a\x7Fb'],
            'C1 CSI, UTF-8' => ["a\u{9B}31mb", 'a\x9B31mb'],
            'NEL' => ["a\u{85}b", 'a\x85b'],
            'right-to-left override' => ["abc\u{202E}fed", 'abc\u{202E}fed'],
            'left-to-right isolate' => ["a\u{2066}b", 'a\u{2066}b'],
            'zero-width space' => ["a\u{200B}b", 'a\u{200B}b'],
            'line separator' => ["a\u{2028}b", 'a\u{2028}b'],
            'paragraph separator' => ["a\u{2029}b", 'a\u{2029}b'],
            'BOM' => ["\u{FEFF}a", '\u{FEFF}a'],
            'soft hyphen' => ["a\u{AD}b", 'a\xADb'],
            'Mongolian vowel separator' => ["a\u{180E}b", 'a\u{180E}b'],
        ];
    }

    #[DataProvider('unsafeCharacters')]
    public function testCleanEscapesControlFormatAndSeparatorCharacters(string $text, string $expected): void
    {
        self::assertSame($expected, Terminal::clean($text));
        self::assertSame(1, preg_match('/^[\x20-\x7E\n]*$/D', Terminal::clean($text)), 'nothing but printable ASCII is left of it');
    }

    public function testCleanKeepsNewlinesAsciiAndLetters(): void
    {
        foreach ([
            "  [ OK ] connect                            pgsql (7 ms)\n",
            "line one\nline two\n\n",
            'Zażółć gęślą jaźń - Królikowski',
            'xn--bcher-kva.example, münchen.example',
            'Ελληνικά, русский, 日本語, עברית, العربية',
            '😀 emoji',
            '',
        ] as $text) {
            self::assertSame($text, Terminal::clean($text));
        }
    }

    public function testCleanEscapesEveryByteOfInvalidUtf8(): void
    {
        self::assertSame('a\xFF\xFEb', Terminal::clean("a\xff\xfeb"));
        self::assertSame('raw C1 \x9B', Terminal::clean("raw C1 \x9b"));
        self::assertSame('cut \xC5', Terminal::clean("cut \xc5"), 'the first byte of a two-byte letter');
        self::assertSame('overlong \xC0\xAF', Terminal::clean("overlong \xc0\xaf"));
        self::assertSame('surrogate \xED\xA0\x80', Terminal::clean("surrogate \xed\xa0\x80"));
        self::assertSame('ok ł \xFF', Terminal::clean("ok ł \xff"), 'valid letters next to an invalid byte stay letters');
    }

    /**
     * Every line of "grants" goes through the same function: the templates (ASCII and the letters
     * of the attribution) must come out byte for byte.
     */
    public function testCleanLeavesTheGrantTemplatesUnchanged(): void
    {
        foreach (['grants.mysql.sql', 'grants.postgres.sql'] as $file) {
            $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/SQL/' . $file);
            self::assertNotSame('', $sql);
            self::assertSame($sql, Terminal::clean($sql), $file);
        }
    }

    public function testPhpMessagesAreOneSanitisedLine(): void
    {
        $line = Terminal::phpMessage(E_USER_WARNING, "bad key \x1b[2J\nResult: OK mysql://role:secret-value@db/vmail", '/srv/x.php', 7);
        self::assertStringStartsWith('PHP Warning: bad key ', $line);
        self::assertStringEndsWith(' in /srv/x.php on line 7', $line);
        self::assertStringNotContainsString("\x1b", $line);
        self::assertStringNotContainsString("\n", $line, 'one line: nothing can be appended after it');
        self::assertStringNotContainsString('secret-value', $line);
        self::assertStringContainsString('mysql://role:***@db/vmail', $line);
        self::assertStringStartsWith('PHP Deprecated: ', Terminal::phpMessage(E_DEPRECATED, 'x', '', 0));
        self::assertSame('PHP Notice: x', Terminal::phpMessage(E_NOTICE, 'x', '', 0));
        // format characters (soft hyphen, tag characters): the log sanitiser replaces every one of
        // them by a blank (second verification pass, F-29), so none reaches the line raw
        self::assertSame('PHP Notice: a b ', Terminal::phpMessage(E_NOTICE, "a\u{AD}b\u{E0041}", '', 0));
    }

    /**
     * bin/galbridge.sh appends this filter to STDERR while diag runs: what other code writes there -
     * rcube::raise_error() writes the text of a driver or server unchanged - arrives neutralised.
     */
    public function testTheStandardErrorFilterNeutralisesWhatOthersWrite(): void
    {
        if (!in_array(StderrFilter::NAME, stream_get_filters(), true)) {
            self::assertTrue(stream_filter_register(StderrFilter::NAME, StderrFilter::class));
        }
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        self::assertNotFalse(stream_filter_append($stream, StderrFilter::NAME, STREAM_FILTER_WRITE));
        fwrite($stream, "ERROR: SQLSTATE[HY000] [1045] \x1b[1A\x1b[2K[ OK ] faked \u{202E} pgsql://galbridge:secret-value@db/vmail\n");
        fwrite($stream, "plain line\n");
        rewind($stream);
        $text = (string) stream_get_contents($stream);
        fclose($stream);
        self::assertSame("ERROR: SQLSTATE[HY000] [1045] \\x1B[1A\\x1B[2K[ OK ] faked \\u{202E} pgsql://galbridge:***@db/vmail\nplain line\n", $text);
    }

    public function testMaskCredentialsIsWhatSanitizeMasks(): void
    {
        foreach ([
            'mysql://galbridge:p@ss@w0rd@db.example.com/vmail' => 'mysql://galbridge:***@db.example.com/vmail',
            'see pgsql://role:x@127.0.0.1:5432/vmail?sslmode=verify-full' => 'see pgsql://role:***@127.0.0.1:5432/vmail?sslmode=verify-full',
            'no dsn here' => 'no dsn here',
        ] as $in => $out) {
            self::assertSame($out, Log::maskCredentials($in));
            self::assertSame($out, Log::sanitize($in));
        }
    }

    /**
     * @return array<string, array{string, null|string}>
     */
    public static function socketPaths(): array
    {
        return [
            'Dovecot socket' => ['unix:///run/dovecot/imap.sock', 'unix:///run/dovecot/imap.sock'],
            'scheme in capitals' => ['UNIX:///var/run/dovecot/imap-login.sock', 'unix:///var/run/dovecot/imap-login.sock'],
            'punctuation' => ['unix:///srv/mail_1/imap-login.sock~', 'unix:///srv/mail_1/imap-login.sock~'],
            'raw C1 byte' => ["unix:///run/\x9b31m.sock", null],
            'UTF-8 C1' => ["unix:///run/\u{9B}31m.sock", null],
            'right-to-left override' => ["unix:///run/dovecot/\u{202E}kcos.pami", null],
            'zero-width space' => ["unix:///run/dove\u{200B}cot.sock", null],
            'letter outside ASCII' => ['unix:///run/dovecöt.sock', null],
            'blank' => ['unix:///run/dovecot/imap sock', null],
            'placeholder' => ['unix:///run/%d.sock', null],
            'ESC' => ["unix:///run/\x1b[2J.sock", null],
            'relative path' => ['unix://run/dovecot.sock', null],
            'no path' => ['unix:///', null],
        ];
    }

    #[DataProvider('socketPaths')]
    public function testUnixEndpointAcceptsPrintableAsciiOnly(string $value, ?string $expected): void
    {
        self::assertSame($expected, Config::unixEndpoint($value));
        // and a galbridge_imap_hosts entry follows the same rule
        self::assertSame($expected, Config::imapEndpoint($value));
    }
}
