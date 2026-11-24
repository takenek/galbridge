<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The single normalisation for configuration lists, the login, decoded IDs and row re-checks
 * (docs/ARCHITECTURE.md section 13): trim, NFC, lower case, split at the last '@', IDNA to ASCII,
 * trailing dot removed, validated. Every method returns null (never a different value) for input
 * that cannot be normalised, so allow-lists can drop it and deny-lists can fail closed.
 *
 * With ext-intl (IDNA and NFC); NormalizerWithoutIntlTest covers an installation without it.
 */
#[CoversClass(Normalizer::class)]
#[RequiresPhpExtension('intl')]
final class NormalizerTest extends TestCase
{
    // ------------------------------------------------------------------ domain()

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function validDomains(): iterable
    {
        yield 'plain' => ['example.com', 'example.com'];
        yield 'upper case' => ['Example.COM', 'example.com'];
        yield 'trimmed' => ["  example.com \t\n", 'example.com'];
        yield 'trailing dot (FQDN)' => ['example.com.', 'example.com'];
        yield 'trailing dot and space' => [' example.com. ', 'example.com'];
        yield 'subdomain' => ['mail.a.example.com', 'mail.a.example.com'];
        yield 'hyphen inside a label' => ['my-firm.example', 'my-firm.example'];
        yield 'digits' => ['a1.example', 'a1.example'];
        yield 'single label' => ['localhost', 'localhost'];
        yield 'idn polish' => ['żółw.pl', 'xn--w-uga1v8h.pl'];
        yield 'idn upper case' => ['ŻÓŁW.PL', 'xn--w-uga1v8h.pl'];
        yield 'idn decomposed (NFD)' => ["z\u{0307}o\u{0301}\u{0142}w.pl", 'xn--w-uga1v8h.pl'];
        yield 'idn german' => ['bücher.example', 'xn--bcher-kva.example'];
        yield 'idn sharp s is kept (non-transitional)' => ['faß.de', 'xn--fa-hia.de'];
        yield 'idn japanese' => ['例え.テスト', 'xn--r8jz45g.xn--zckzah'];
        yield 'idn ideographic full stop' => ["example\u{3002}com", 'example.com'];
        yield 'a-label kept' => ['xn--w-uga1v8h.pl', 'xn--w-uga1v8h.pl'];
        yield 'a-label upper case' => ['XN--W-UGA1V8H.PL', 'xn--w-uga1v8h.pl'];
        yield 'label of 63' => [str_repeat('a', 63) . '.pl', str_repeat('a', 63) . '.pl'];
        yield 'domain of 253' => [self::longDomain(253), self::longDomain(253)];
    }

    #[DataProvider('validDomains')]
    public function testDomain(string $in, string $expected): void
    {
        self::assertSame($expected, Normalizer::domain($in));
        self::assertSame($expected, Normalizer::domain($expected), 'idempotent');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidDomains(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [123];
        yield 'array' => [['example.com']];
        yield 'object' => [new \stdClass()];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'dot' => ['.'];
        yield 'dots' => ['...'];
        yield 'leading dot' => ['.example.com'];
        yield 'empty label' => ['example..com'];
        yield 'space inside' => ['exa mple.com'];
        yield 'tab inside' => ["exa\tmple.com"];
        yield 'newline inside' => ["example.com\nevil.test"];
        yield 'NUL' => ["example.com\0.evil"];
        yield 'DEL' => ["exa\x7Fmple.com"];
        yield 'no-break space' => ["exa\u{00A0}mple.com"];
        yield 'address' => ['jan@example.com'];
        yield 'at sign' => ['@example.com'];
        yield 'url' => ['https://example.com'];
        yield 'path' => ['example.com/x'];
        yield 'backslash' => ['example.com\\x'];
        yield 'port' => ['example.com:25'];
        yield 'ip literal' => ['[127.0.0.1]'];
        yield 'ipv6' => ['::1'];
        yield 'wildcard' => ['*'];
        yield 'wildcard label' => ['*.example.com'];
        yield 'leading hyphen' => ['-example.com'];
        yield 'trailing hyphen' => ['example-.com'];
        yield 'exclamation mark' => ['exam!ple.com'];
        yield 'quote' => ['"example.com"'];
        yield 'comma list' => ['a.pl,b.pl'];
        yield 'semicolon' => ['a.pl;b.pl'];
        yield 'label of 64' => [str_repeat('a', 64) . '.pl'];
        yield 'domain of 254' => [self::longDomain(254)];
        yield 'idn label longer than 63 as a-label' => [str_repeat('ą', 60) . '.pl'];
        yield 'idn right-to-left override' => ["a\u{202E}b.pl"];
        yield 'idn C1 control' => ["a\u{0080}b.pl"];
        yield 'invalid utf-8' => ["\xFFexample.com"];
        yield 'invalid utf-8 in the middle' => ["exa\xC3mple.com"];
    }

    #[DataProvider('invalidDomains')]
    public function testDomainRejects(mixed $in): void
    {
        self::assertNull(Normalizer::domain($in));
    }

    // ------------------------------------------------------------------ address()

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function validAddresses(): iterable
    {
        yield 'plain' => ['jan@firma.pl', 'jan@firma.pl'];
        yield 'mixed case' => ['Jan.Kowalski@Firma.PL', 'jan.kowalski@firma.pl'];
        yield 'trimmed' => ["  jan@firma.pl\r\n", 'jan@firma.pl'];
        yield 'trailing dot on domain' => ['jan@firma.pl.', 'jan@firma.pl'];
        yield 'plus tag' => ['jan+news@firma.pl', 'jan+news@firma.pl'];
        yield 'hyphen underscore dot' => ['j_a-n.k@firma.pl', 'j_a-n.k@firma.pl'];
        yield 'digits only local part' => ['1001@firma.pl', '1001@firma.pl'];
        yield 'idn domain' => ['jan@żółw.pl', 'jan@xn--w-uga1v8h.pl'];
        yield 'idn domain upper case' => ['JAN@ŻÓŁW.PL', 'jan@xn--w-uga1v8h.pl'];
        yield 'non-ascii local part lower-cased' => ['ŻANETA@firma.pl', 'żaneta@firma.pl'];
        yield 'non-ascii local part NFC' => ["z\u{0307}aneta@firma.pl", 'żaneta@firma.pl'];
        yield 'cjk local part' => ['用户@example.com', '用户@example.com'];
        yield 'single-label domain' => ['root@localhost', 'root@localhost'];
        yield 'local part of 64' => [str_repeat('a', 64) . '@firma.pl', str_repeat('a', 64) . '@firma.pl'];
        yield 'address of 254' => [str_repeat('a', 64) . '@' . self::longDomain(189), str_repeat('a', 64) . '@' . self::longDomain(189)];
    }

    #[DataProvider('validAddresses')]
    public function testAddress(string $in, string $expected): void
    {
        self::assertSame($expected, Normalizer::address($in));
        self::assertSame($expected, Normalizer::address($expected), 'idempotent');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [1001];
        yield 'array' => [['jan@firma.pl']];
        yield 'empty' => [''];
        yield 'no at sign' => ['jan'];
        yield 'domain only' => ['firma.pl'];
        yield 'empty local part' => ['@firma.pl'];
        yield 'empty domain' => ['jan@'];
        yield 'bad domain' => ['jan@firma..pl'];
        yield 'domain with leading dot' => ['jan@.pl'];
        yield 'two at signs' => ['jan@ewa@firma.pl'];
        yield 'quoted local part with at sign' => ['"jan@x"@firma.pl'];
        yield 'quoted local part' => ['"jan kowalski"@firma.pl'];
        yield 'display name form' => ['Jan <jan@firma.pl>'];
        yield 'space in local part' => ['ja n@firma.pl'];
        yield 'space before at' => ['jan @firma.pl'];
        yield 'tab in local part' => ["ja\tn@firma.pl"];
        yield 'newline in local part' => ["ja\nn@firma.pl"];
        yield 'header injection' => ["jan@firma.pl\nBcc: x@evil.test"];
        yield 'NUL' => ["jan\0@firma.pl"];
        yield 'comma list' => ['jan@firma.pl,ewa@firma.pl'];
        yield 'comma in local part' => ['jan,ewa@firma.pl'];
        yield 'semicolon' => ['jan;ewa@firma.pl'];
        yield 'colon' => ['jan:ewa@firma.pl'];
        yield 'angle brackets' => ['<jan>@firma.pl'];
        yield 'parentheses' => ['(jan)@firma.pl'];
        yield 'square brackets' => ['[jan]@firma.pl'];
        yield 'apostrophe' => ["o'brien@firma.pl"];
        yield 'backslash' => ['ja\\n@firma.pl'];
        yield 'ip literal domain' => ['jan@[127.0.0.1]'];
        yield 'url domain' => ['jan@firma.pl/x'];
        yield 'no-break space' => ["ja\u{00A0}n@firma.pl"];
        yield 'next line (U+0085)' => ["ja\u{0085}n@firma.pl"];
        yield 'ideographic space' => ["ja\u{3000}n@firma.pl"];
        yield 'local part of 65' => [str_repeat('a', 65) . '@firma.pl'];
        yield 'address of 255' => [str_repeat('a', 64) . '@' . self::longDomain(190)];
    }

    #[DataProvider('invalidAddresses')]
    public function testAddressRejects(mixed $in): void
    {
        self::assertNull(Normalizer::address($in));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidUtf8Addresses(): iterable
    {
        yield 'latin-1 byte in local part' => ["\xBFaneta@firma.pl"];   // 'ż' in ISO-8859-2
        yield 'lone lead byte in local part' => ["jan\xC5@firma.pl"];
        yield 'overlong encoding in local part' => ["ja\xC0\xAEn@firma.pl"];
        yield 'invalid byte in domain' => ["jan@\xFFfirma.pl"];
    }

    /**
     * Input that is not valid UTF-8 cannot be normalised and must give null. It must never be turned
     * into a DIFFERENT valid address (mb_strtolower() replaces invalid bytes with '?'): a deny-list
     * entry from a config file saved in ISO-8859-2 must fail closed, not silently hide '?aneta@...'.
     */
    #[DataProvider('invalidUtf8Addresses')]
    public function testAddressRejectsInvalidUtf8(string $in): void
    {
        self::assertNull(Normalizer::address($in));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function c1Controls(): iterable
    {
        foreach ([0x80, 0x8D, 0x9B, 0x9F] as $cp) {
            yield sprintf('U+%04X', $cp) => [(string) mb_chr($cp, 'UTF-8')];
        }
    }

    /**
     * localPart() excludes C0 controls and DEL; the C1 controls (U+0080-U+009F) are control characters
     * too and never part of a plausible iRedMail local part.
     */
    #[DataProvider('c1Controls')]
    public function testLocalPartRejectsC1Controls(string $char): void
    {
        self::assertFalse(Normalizer::localPart('ja' . $char . 'n'));
        self::assertNull(Normalizer::address('ja' . $char . 'n@firma.pl'));
    }

    public function testAddressSplitsAtTheLastAtSign(): void
    {
        // the domain part is everything after the LAST '@'; the local part may then not contain '@'
        self::assertNull(Normalizer::address('victim@tenant-a.example@tenant-b.example'));
        self::assertSame('jan@tenant-b.example', Normalizer::address('JAN@TENANT-B.EXAMPLE'));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function localParts(): iterable
    {
        yield 'plain' => ['jan', true];
        yield 'dots plus hyphen underscore' => ['j.a+n-k_w', true];
        yield 'digits' => ['007', true];
        yield 'non-ascii' => ['żaneta', true];
        yield 'max length' => [str_repeat('a', 64), true];
        yield 'max length in bytes' => [str_repeat('ż', 32), true];
        yield 'empty' => ['', false];
        yield 'too long' => [str_repeat('a', 65), false];
        yield 'too long in bytes' => [str_repeat('ż', 32) . 'a', false];
        yield 'space' => ['ja n', false];
        yield 'tab' => ["ja\tn", false];
        yield 'DEL' => ["ja\x7Fn", false];
        yield 'NUL' => ["ja\0n", false];
        yield 'at sign' => ['ja@n', false];
        yield 'comma' => ['ja,n', false];
        yield 'semicolon' => ['ja;n', false];
        yield 'colon' => ['ja:n', false];
        yield 'less than' => ['ja<n', false];
        yield 'greater than' => ['ja>n', false];
        yield 'parenthesis' => ['ja(n', false];
        yield 'bracket' => ['ja]n', false];
        yield 'double quote' => ['ja"n', false];
        yield 'single quote' => ["ja'n", false];
        yield 'backslash' => ['ja\\n', false];
        yield 'invalid utf-8' => ["ja\xFFn", false];
        yield 'no-break space' => ["ja\u{00A0}n", false];
    }

    #[DataProvider('localParts')]
    public function testLocalPart(string $local, bool $valid): void
    {
        self::assertSame($valid, Normalizer::localPart($local));
    }

    // ------------------------------------------------------------------ list()

    public function testListWildcardAlone(): void
    {
        $invalid = ['stale'];
        self::assertSame(['*'], Normalizer::list(['*'], [Normalizer::class, 'domain'], false, $invalid));
        self::assertSame([], $invalid);
        self::assertSame(['*'], Normalizer::list(['*'], [Normalizer::class, 'domain'], true));
        self::assertSame(['*'], Normalizer::list(['all' => '*'], [Normalizer::class, 'domain'], true), 'keys are ignored');
    }

    /**
     * @return iterable<string, array{0: array<mixed>}>
     */
    public static function mixedWildcards(): iterable
    {
        yield 'wildcard first' => [['*', 'example.com']];
        yield 'wildcard last' => [['example.com', '*']];
        yield 'wildcard twice' => [['*', '*']];
        yield 'wildcard with an invalid entry' => [['*', 'not a domain']];
    }

    /**
     * '*' is accepted only as the sole element; mixed with anything it makes the option malformed,
     * in strict and in lenient mode alike (it must never be read as "everything").
     *
     * @param array<mixed> $value
     */
    #[DataProvider('mixedWildcards')]
    public function testListWildcardMixedIsMalformed(array $value): void
    {
        self::assertNull(Normalizer::list($value, [Normalizer::class, 'domain'], false));
        self::assertNull(Normalizer::list($value, [Normalizer::class, 'domain'], true));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonArrays(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['example.com'];
        yield 'comma string' => ['a.pl, b.pl'];
        yield 'wildcard string' => ['*'];
        yield 'int' => [1];
        yield 'true' => [true];
        yield 'object' => [new \ArrayObject(['example.com'])];
    }

    #[DataProvider('nonArrays')]
    public function testListOfNonArrayIsMalformed(mixed $value): void
    {
        $invalid = ['stale'];
        self::assertNull(Normalizer::list($value, [Normalizer::class, 'domain'], false, $invalid));
        self::assertSame([], $invalid);
        self::assertNull(Normalizer::list($value, [Normalizer::class, 'domain'], true));
    }

    public function testListEmpty(): void
    {
        self::assertSame([], Normalizer::list([], [Normalizer::class, 'domain'], false));
        self::assertSame([], Normalizer::list([], [Normalizer::class, 'domain'], true));
    }

    public function testListNormalisesDeduplicatesAndKeepsOrder(): void
    {
        $invalid = ['stale'];
        $list = Normalizer::list(
            ['b' => 'Beta.example', 'alpha.example', 'BETA.EXAMPLE.', ' alpha.example ', 'żółw.pl', 'xn--w-uga1v8h.pl'],
            [Normalizer::class, 'domain'],
            true,
            $invalid,
        );
        self::assertSame(['beta.example', 'alpha.example', 'xn--w-uga1v8h.pl'], $list);
        self::assertSame([], $invalid);
    }

    public function testLenientListDropsAndReportsInvalidEntries(): void
    {
        $invalid = [];
        $list = Normalizer::list(
            ['a.example', 'not a domain', 42, null, ['x'], true, new \stdClass(), 'b.example', ''],
            [Normalizer::class, 'domain'],
            false,
            $invalid,
        );
        self::assertSame(['a.example', 'b.example'], $list);
        self::assertSame(['not a domain', '42', 'null', 'array', '1', 'stdClass', ''], $invalid);
    }

    public function testStrictListIsMalformedOnTheFirstInvalidEntry(): void
    {
        $invalid = [];
        self::assertNull(Normalizer::list(['a.example', 'bad domain', 'b.example', 'also bad'], [Normalizer::class, 'domain'], true, $invalid));
        self::assertContains('bad domain', $invalid);
        self::assertNotContains('a.example', $invalid);

        // a deny-list with one unusable entry must not silently shrink to the usable ones
        self::assertNull(Normalizer::list(['ceo@firma.pl', 'cfo@'], [Normalizer::class, 'address'], true));
        self::assertNull(Normalizer::list([null], [Normalizer::class, 'address'], true));
    }

    public function testListResetsTheInvalidReport(): void
    {
        $invalid = ['left over'];
        Normalizer::list(['a.example'], [Normalizer::class, 'domain'], false, $invalid);
        self::assertSame([], $invalid);
    }

    public function testListUsesTheGivenNormaliser(): void
    {
        $seen = [];
        $normalise = static function (mixed $v) use (&$seen): ?string {
            $seen[] = $v;
            return is_string($v) && $v !== 'drop' ? strtoupper($v) : null;
        };
        self::assertSame(['A', 'B'], Normalizer::list(['a', 'drop', 'b', 'A'], $normalise, false));
        self::assertSame(['a', 'drop', 'b', 'A'], $seen, 'every raw value is passed once');
    }

    /**
     * Local parts such as employee numbers ('1001') are numeric strings. They must come back as the
     * strings they are: an int would make Sql::escapeLike() (strict_types) throw a TypeError for
     * galbridge_hidden_localparts, and would be bound as a number (varchar = integer on PostgreSQL).
     */
    public function testListKeepsNumericStringsAsStrings(): void
    {
        $localPart = static fn (mixed $v): ?string => is_string($v) && Normalizer::localPart($v) ? $v : null;
        self::assertSame(['1001', '007', 'postmaster', '0'], Normalizer::list(['1001', '007', 'postmaster', '0'], $localPart, true));
        self::assertSame(['123', '127.0.0.1'], Normalizer::list(['123', '127.0.0.1'], [Normalizer::class, 'domain'], false));
    }

    // ------------------------------------------------------------------ parentDomains()

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function parentDomainCases(): iterable
    {
        yield 'deep' => ['a.b.example.com', ['a.b.example.com', 'b.example.com', 'example.com']];
        yield 'subdomain' => ['sales.firma.pl', ['sales.firma.pl', 'firma.pl']];
        yield 'registrable domain only' => ['firma.pl', ['firma.pl']];
        yield 'single label' => ['localhost', ['localhost']];
        yield 'unicode labels (string based)' => ['dział.żółw.pl', ['dział.żółw.pl', 'żółw.pl']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('parentDomainCases')]
    public function testParentDomains(string $domain, array $expected): void
    {
        self::assertSame($expected, Normalizer::parentDomains($domain));
    }

    public function testParentDomainsNeverIncludeTheTopLevelDomain(): void
    {
        foreach (['a.b.c.d.example.com', 'x.co.uk', 'firma.pl'] as $domain) {
            $parents = Normalizer::parentDomains($domain);
            self::assertSame($domain, $parents[0]);
            foreach ($parents as $p) {
                self::assertStringContainsString('.', $p, $domain);
            }
        }
    }

    // ------------------------------------------------------------------ displayText()

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function displayTexts(): iterable
    {
        yield 'plain' => ['Jan Kowalski', 'Jan Kowalski'];
        yield 'polish' => ['Żaneta Ćwik-Łęcka', 'Żaneta Ćwik-Łęcka'];
        yield 'trimmed' => ['  Jan  ', 'Jan'];
        yield 'collapsed whitespace' => ["Jan \t  Kowalski", 'Jan Kowalski'];
        yield 'CR LF' => ["Jan\r\nBcc: evil@example.test", 'Jan Bcc: evil@example.test'];
        yield 'other C0 controls' => ["a\x00b\x07c\x1Bd\x1Fe", 'a b c d e'];
        yield 'DEL' => ["a\x7Fb", 'a b'];
        yield 'quotes and brackets' => ['Jan "The Boss" <ceo@evil.test>', 'Jan The Boss ceo@evil.test'];
        yield 'backslash' => ['Dom\\ain\\user', 'Dom ain user'];
        yield 'header breaking' => ['"Jan" <jan@firma.pl>, "Eve" <eve@evil.test>', 'Jan jan@firma.pl , Eve eve@evil.test'];
        yield 'html stays text' => ['<b>Jan</b> & Co', 'b Jan /b & Co'];
        yield 'no-break space collapsed' => ["Jan\u{00A0}Kowalski", 'Jan Kowalski'];
        yield 'NFC' => ["Z\u{0307}aneta", 'Żaneta'];
        yield 'int' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];
        yield 'null' => [null, ''];
        yield 'array' => [['Jan'], ''];
        yield 'object' => [new \stdClass(), ''];
        yield 'empty' => ['', ''];
        yield 'only removable characters' => ["\r\n\"<>\\\u{200B}\u{202E}", ''];
        yield 'invalid utf-8' => ["Jan\xFFKowalski", ''];
        yield 'overlong utf-8' => ["\xC0\xAE", ''];
    }

    #[DataProvider('displayTexts')]
    public function testDisplayText(mixed $in, string $expected): void
    {
        self::assertSame($expected, Normalizer::displayText($in));
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invisibleCharacters(): iterable
    {
        $set = [
            // C1 controls
            0x0080, 0x0085, 0x009B, 0x009F,
            // zero-width
            0x200B, 0x200C, 0x200D, 0x2060, 0xFEFF,
            // bidi controls (Unicode Bidi_Control)
            0x061C, 0x200E, 0x200F, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2066, 0x2067, 0x2068, 0x2069,
            // line and paragraph separators
            0x2028, 0x2029,
        ];
        foreach ($set as $cp) {
            yield sprintf('U+%04X', $cp) => [$cp];
        }
    }

    /**
     * Control, bidi and zero-width characters are removed from every displayed text: they would spoof
     * a recipient ("evil\u{202E}lp.amrif" displayed as "evil firma.pl") or hide characters.
     */
    #[DataProvider('invisibleCharacters')]
    public function testDisplayTextRemovesInvisibleCharacters(int $codePoint): void
    {
        $char = (string) mb_chr($codePoint, 'UTF-8');
        $out = Normalizer::displayText('Jan' . $char . 'Kowalski' . $char);
        self::assertStringNotContainsString($char, $out);
        self::assertSame('JanKowalski', str_replace(' ', '', $out), 'visible text is kept');
    }

    public function testDisplayTextIsCappedInCharacters(): void
    {
        self::assertSame(str_repeat('a', 128), Normalizer::displayText(str_repeat('a', 500)));
        self::assertSame(str_repeat('a', 128), Normalizer::displayText(str_repeat('a', 128)));
        self::assertSame(str_repeat('a', 64), Normalizer::displayText(str_repeat('a', 65), 64));

        // multi-byte: counted in characters, never cut inside a UTF-8 sequence
        $out = Normalizer::displayText(str_repeat('ż', 300));
        self::assertSame(128, mb_strlen($out));
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertSame(str_repeat('ż', 128), $out);
    }

    public function testDisplayTextCapIsAppliedAfterStripping(): void
    {
        // invisible padding does not push visible characters out, and no trailing space is left at the cut
        self::assertSame(str_repeat('a', 10), Normalizer::displayText(str_repeat("\u{200B}", 500) . str_repeat('a', 10)));
        self::assertSame(str_repeat('a', 127), Normalizer::displayText(str_repeat('a', 127) . ' ' . str_repeat('b', 50)));

        // NFC first: 100 decomposed 'é' (200 code points) are 100 characters
        self::assertSame(str_repeat('é', 100), Normalizer::displayText(str_repeat("e\u{0301}", 100)));
    }

    public function testDisplayTextOutputIsSafeForRecipientHeaders(): void
    {
        $hostile = "Eve\u{202E}lp.amrif@evil\r\n\"Admin\" <admin@firma.pl>\\\u{200B}\x1B[31m\u{FEFF}";
        $out = Normalizer::displayText($hostile);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F\\\\"<>]/', $out);
        self::assertDoesNotMatchRegularExpression('/[\x{0080}-\x{009F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', $out);
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertSame($out, Normalizer::displayText($out), 'idempotent');
    }

    // ------------------------------------------------------------------ nfc()

    public function testNfc(): void
    {
        self::assertSame('ascii only', Normalizer::nfc('ascii only'));
        self::assertSame('é', Normalizer::nfc("e\u{0301}"));
        self::assertSame('Żółw', Normalizer::nfc("Z\u{0307}o\u{0301}\u{0142}w"));
        self::assertSame("\u{AC00}", Normalizer::nfc("\u{1100}\u{1161}"), 'hangul jamo compose');
        self::assertSame('żółw', Normalizer::nfc('żółw'), 'already NFC');
        self::assertSame("ab\xFF", Normalizer::nfc("ab\xFF"), 'invalid UTF-8 is returned unchanged (callers validate)');
        self::assertSame('', Normalizer::nfc(''));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A syntactically valid domain of exactly $length characters made of labels of at most 63.
     */
    private static function longDomain(int $length): string
    {
        $labels = [];
        $remaining = $length;
        $i = 0;
        while ($remaining > 0) {
            $take = min(63, $remaining);
            if ($remaining - $take === 1) {
                $take--; // never leave a lone '.' with no room for a label
            }
            $labels[] = str_repeat(chr(ord('a') + $i++ % 26), $take);
            $remaining -= $take + 1;
        }
        $domain = implode('.', $labels);
        if (strlen($domain) !== $length) {
            throw new \LogicException('longDomain(' . $length . ') produced ' . strlen($domain));
        }
        return $domain;
    }
}
