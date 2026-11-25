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
        yield 'html stays text' => ['<b>Jan</b> & Co', "b Jan /b \u{FF06} Co"];
        yield 'ampersand becomes its full-width form' => ['R&D', "R\u{FF06}D"];
        yield 'encoded-word delimiters are split' => ['x =?UTF-8?Q?x?= y', 'x = ?UTF-8?Q?x? = y'];
        yield 'a text never starts or ends with = or ? (fields next to each other in a name template)' => ['=?UTF-8?Q?x?=', "\u{FF1D} ?UTF-8?Q?x? \u{FF1D}"];
        yield 'a question at the end' => ['Why?', "Why\u{FF1F}"];
        yield 'question and equals signs alone stay' => ['Why? a=b', 'Why? a=b'];
        yield 'empty round brackets are removed' => ['Jan () Kowalski', 'Jan Kowalski'];
        yield 'empty square brackets are removed' => ['Jan[] Kowalski', 'Jan Kowalski'];
        yield 'nested empty brackets are removed' => ['Jan ([()]) Kowalski', 'Jan Kowalski'];
        yield 'brackets around removed characters keep a space (core does not delete those)' => ['Jan ("") Kowalski', 'Jan ( ) Kowalski'];
        yield 'brackets with content stay' => ['Jan Kowalski (IT) [PL]', 'Jan Kowalski (IT) [PL]'];
        yield 'a telephone number keeps its brackets' => ['(22) 123-45-67', '(22) 123-45-67'];
        yield 'a closing bracket at the start is dropped' => [') ] Jan', 'Jan'];
        yield 'an opening bracket at the end is dropped' => ['Jan ( [', 'Jan'];
        yield 'only brackets' => [')(', ''];
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

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function formatCharacters(): iterable
    {
        // \p{Cf} beyond the explicit INVISIBLE list: soft hyphen, Arabic/Syriac format marks, invisible
        // operators, interlinear annotation, Kaithi number sign, tag characters (incl. CANCEL TAG)
        foreach ([0x00AD, 0x0600, 0x06DD, 0x070F, 0x08E2, 0x2061, 0x2064, 0xFFF9, 0xFFFB, 0x110BD, 0xE0001, 0xE0041, 0xE005A, 0xE007F] as $cp) {
            yield sprintf('U+%04X', $cp) => [$cp];
        }
    }

    /**
     * Format characters (\p{Cf}) are invisible in a recipient bubble: removed, never kept.
     */
    #[DataProvider('formatCharacters')]
    public function testDisplayTextRemovesFormatCharacters(int $codePoint): void
    {
        $char = (string) mb_chr($codePoint, 'UTF-8');
        self::assertSame(1, preg_match('/^\p{Cf}$/u', $char), 'the fixture is a format character');
        $out = Normalizer::displayText('Jan' . $char . 'Kowalski' . str_repeat($char, 20));
        self::assertStringNotContainsString($char, $out);
        self::assertSame('Jan Kowalski', $out);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function blankCharacters(): iterable
    {
        $set = [
            // render blank without being whitespace: Braille blank, Hangul fillers
            0x2800, 0x3164, 0x115F, 0x1160, 0xFFA0,
            // \p{Z}: space and line separators beyond ASCII
            0x00A0, 0x1680, 0x2000, 0x2003, 0x2007, 0x2009, 0x200A, 0x202F, 0x205F, 0x3000,
        ];
        foreach ($set as $cp) {
            yield sprintf('U+%04X', $cp) => [$cp];
        }
    }

    /**
     * Blank look-alikes and separators collapse like a space: padding a display name with them cannot
     * push a suffix such as the foreign-domain marker out of view.
     */
    #[DataProvider('blankCharacters')]
    public function testDisplayTextCollapsesBlankLookAlikes(int $codePoint): void
    {
        $char = (string) mb_chr($codePoint, 'UTF-8');
        self::assertSame('Jan Kowalski', Normalizer::displayText(str_repeat($char, 5) . 'Jan' . str_repeat($char, 60) . 'Kowalski' . str_repeat($char, 200)));
        self::assertSame('Bob', Normalizer::displayText('Bob' . str_repeat($char, 300)), 'trailing padding is trimmed');
        // the padding never counts towards the length cap
        self::assertSame('Jan ' . str_repeat('x', 20), Normalizer::displayText('Jan' . str_repeat($char, 500) . str_repeat('x', 20)));
    }

    public function testDisplayTextMixedPaddingIsOneSpace(): void
    {
        $padding = "\u{2800}\u{3164}\u{00AD}\u{115F}\u{1160}\u{FFA0}\u{00A0}\u{3000}\u{E0020}\u{200B}\t ";
        $out = Normalizer::displayText('Bob' . str_repeat($padding, 30) . 'Smith' . str_repeat($padding, 30));
        self::assertSame('Bob Smith', $out);
        self::assertSame($out, Normalizer::displayText($out), 'idempotent');
    }

    // ------------------------------------------------------------------ containsAt()

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function atSigns(): iterable
    {
        yield 'ASCII at' => ['ceo@bank.example', true];
        yield 'FULLWIDTH COMMERCIAL AT U+FF20' => ["ceo\u{FF20}bank.example", true];
        yield 'SMALL COMMERCIAL AT U+FE6B' => ["ceo\u{FE6B}bank.example", true];
        yield 'at sign alone' => ['@', true];
        yield 'leading fullwidth at' => ["\u{FF20}", true];
        yield 'plain name' => ['Jan Kowalski', false];
        yield 'empty' => ['', false];
        yield 'circled latin A is not an at sign' => ["\u{24B6}cme", false];
        yield 'copyright sign' => ["\u{00A9} Acme", false];
        yield 'domain without at' => ['bank.example', false];
    }

    #[DataProvider('atSigns')]
    public function testContainsAtRecognisesEveryAtSign(string $value, bool $expected): void
    {
        self::assertSame($expected, Normalizer::containsAt($value));
    }

    /**
     * The look-alikes survive displayText (they are ordinary symbols), so the anti-impersonation rule
     * must recognise them after sanitising.
     */
    public function testAtLookAlikesSurviveDisplayTextAndAreRecognised(): void
    {
        foreach (["\u{FF20}", "\u{FE6B}"] as $at) {
            $text = Normalizer::displayText('ceo' . $at . 'bank.example');
            self::assertStringContainsString($at, $text);
            self::assertTrue(Normalizer::containsAt($text));
        }
    }

    /**
     * Notations that a LATER consumer decodes: an HTML character reference (Roundcube's recipient
     * widget inserts names as HTML after escaping only the angle brackets) and an RFC 2047
     * encoded-word (decoded by the mail clients of the recipients). Each would turn into an at-sign
     * - or any other text - after the anti-impersonation check has run.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function decodedNotations(): iterable
    {
        yield 'numeric character reference' => ['ceo&#64;bank.example'];
        yield 'numeric reference without semicolon' => ['ceo&#64bank.example'];
        yield 'hexadecimal character reference' => ['ceo&#x40;bank.example'];
        yield 'upper-case hexadecimal reference' => ['ceo&#X40;bank.example'];
        yield 'zero-padded reference' => ['ceo&#0000064;bank.example'];
        yield 'named character reference' => ['ceo&commat;bank.example'];
        yield 'full-width at as a reference' => ['ceo&#xFF20;bank.example'];
        yield 'angle brackets as references' => ['&lt;img src=x&gt;'];
        yield 'double-encoded reference' => ['ceo&amp;#64;bank.example'];
        yield 'reference split by a removed character' => ["ceo&\u{200B}#64;bank.example"];
        yield 'reference split by a control character' => ["ceo&\x00#64;bank.example"];
        yield 'base64 encoded-word' => ['=?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?='];
        yield 'quoted-printable encoded-word' => ['=?utf-8?q?ceo=40bank.example?='];
        yield 'encoded-word inside a name' => ['Jan =?UTF-8?B?Y2VvQGJhbms=?= Kowalski'];
        yield 'doubled opener' => ['==??UTF-8?B?Y2VvQGJhbms=?='];
        yield 'opener split by a removed character' => ["=\u{200B}?UTF-8?B?Y2VvQGJhbms=?="];
        yield 'opener split by a quote' => ['="?UTF-8?B?Y2VvQGJhbms=?='];
        yield 'both notations' => ['=?UTF-8?Q?a?= &#64; &commat;'];
        // what Roundcube deletes when it composes a name from contactlist_name_template
        yield 'opener split by empty round brackets' => ['=()?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?='];
        yield 'opener split by empty square brackets' => ['=[]?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?='];
        yield 'opener split by a space and empty brackets' => ['= ()?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?='];
        yield 'opener split by nested empty brackets' => ['=([()])?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?='];
        yield 'opener split by brackets around a removed character' => ["=(\u{200B})?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?="];
        yield 'opener split by angle brackets' => ['=<>?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?='];
        yield 'closer split by empty brackets' => ['=?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?()='];
        yield 'both delimiters split by empty brackets' => ['Jan =[]?utf-8?q?ceo=40bank.example?[]= Kowalski'];
        yield 'reference split by empty brackets' => ['ceo&()#64;bank.example'];
    }

    #[DataProvider('decodedNotations')]
    public function testDisplayTextNeutralisesNotationsThatAreDecodedLater(string $hostile): void
    {
        $out = Normalizer::displayText($hostile);
        self::assertStringNotContainsString('&', $out, 'no character reference can start');
        self::assertStringNotContainsString('=?', $out, 'no encoded-word can start');
        self::assertSame($out, Normalizer::displayText($out), 'idempotent');
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        // what is left is literal text: decoding it as HTML or as a header changes nothing
        self::assertSame($out, html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // (Roundcube's own header decoder is asked in ContractTest, where the core is loaded)
        self::assertDoesNotMatchRegularExpression('/=\\?[^?\\s]+\\?[bBqQ]\\?[^?\\s]*\\?=/', $out, 'no RFC 2047 encoded-word is left');
        self::assertFalse(Normalizer::containsAt(html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'no at-sign appears after decoding');
        self::assertStringNotContainsString('?=', $out, 'no encoded-word can end');

        // ... and it stays that way in a name that core composes from a template: alone, in brackets
        // of the template, and next to a text that ends where an encoded-word would start
        foreach (['{a}', '{a} ({b})', '{b} ({a})', '[{a}] {b}', '{b} [{a}]', '{a} - {b}', '({a})', '{b} <{a}>'] as $template) {
            foreach (['', 'Sales', Normalizer::displayText('Jan ='), Normalizer::displayText('=')] as $other) {
                $composed = self::composedByCore($template, ['a' => $out, 'b' => $other]);
                $label = "{$template} with '{$other}': {$composed}";
                self::assertStringNotContainsString('=?', $composed, $label);
                self::assertDoesNotMatchRegularExpression('/=\\?[^?\\s]+\\?[bBqQ]\\?[^?\\s]*\\?=/', $composed, $label);
                self::assertStringNotContainsString('&', $composed, $label);
            }
        }
    }

    /**
     * What rcube_addressbook::compose_search_name() (Roundcube 1.7) does with a template once the
     * field values are in: it collapses whitespace, DELETES empty bracket pairs together with the
     * whitespace before them, and trims. (The real function is exercised in CorePathsTest.)
     *
     * @param array<string, string> $values
     */
    private static function composedByCore(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        $template = (string) preg_replace('/\\s+/u', ' ', $template);
        $template = (string) preg_replace('/\\s*(<>|\\(\\)|\\[\\])/u', '', $template);
        return trim($template, '/ ');
    }

    /**
     * Two texts that are harmless each, joined by a template: the first ends where an encoded-word
     * starts, the second begins with the bracket that closes the template's own - core would delete
     * that pair and join them.
     */
    public function testTextsCannotFormAnEncodedWordAcrossTheBracketsOfATemplate(): void
    {
        $tail = '?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=';
        foreach ([[')', '{a} ({b})'], [']', '{a} [{b}]'], [') ', '{a} ({b})'], ['))', '{a} ({b})'], [' ])', '{a} ({b})']] as [$closer, $template]) {
            foreach (['Jan =', '=', 'x =('] as $head) {
                $a = Normalizer::displayText($head);
                $b = Normalizer::displayText($closer . $tail);
                $composed = self::composedByCore($template, ['a' => $a, 'b' => $b]);
                self::assertStringNotContainsString('=?', $composed, "'{$head}' + '{$closer}{$tail}' in {$template}: {$composed}");
            }
        }
        // the rule behind it: a text never ends with an opening bracket (also where the length cap
        // cut it) and never starts with a closing one
        foreach (['Jan =(', 'Jan =[', 'Jan = ( [', str_repeat('a', 127) . '(x)', str_repeat('a', 126) . ' [x]'] as $text) {
            self::assertDoesNotMatchRegularExpression('/[\s(\[]$/', Normalizer::displayText($text), $text);
        }
        self::assertSame(str_repeat('a', 127), Normalizer::displayText(str_repeat('a', 127) . '(x)'));
        foreach ([') Jan', '] Jan', ' )] Jan', '()) Jan'] as $text) {
            self::assertSame('Jan', Normalizer::displayText($text), $text);
        }
    }

    /**
     * The loop that removes nested empty brackets is bounded: a value far longer than a column of
     * the iRedMail schema (megabytes of nested brackets) is cut before it, not worked through.
     */
    public function testNestedBracketsOfAnyLengthAreHandledInBoundedTime(): void
    {
        $start = hrtime(true);
        self::assertSame('', Normalizer::displayText(str_repeat('(', 500000) . str_repeat(')', 500000)));
        self::assertSame('', Normalizer::displayText(str_repeat('([', 250000) . str_repeat('])', 250000)));
        self::assertSame('Jan', Normalizer::displayText('Jan ' . str_repeat('(', 900) . str_repeat(')', 900)));
        self::assertSame('Jan = ?x', Normalizer::displayText('Jan =' . str_repeat('[(', 400) . str_repeat(')]', 400) . '?x'));
        $out = Normalizer::displayText(str_repeat('a()', 1000000));
        self::assertSame(str_repeat('a', 128), $out);
        self::assertLessThan(10.0, (hrtime(true) - $start) / 1e9, 'megabytes of nested brackets must not take minutes');
    }

    public function testLabelWordsUseTheNotationOfDisplayText(): void
    {
        // (a text that starts or ends with '=' or '?' is display text of its own: those characters
        // are only changed at the edges of a whole text, never in a word of it)
        foreach (['R&D', 'a=?b', 'a?=b', 'x =?UTF-8?Q?x?= y', '{dept}', 'a}b{'] as $word) {
            self::assertSame(Normalizer::displayText($word), Normalizer::labelWord($word), $word);
        }
    }

    /**
     * STAGE 6: a "{placeholder}" inside directory text would be filled by core's name template with
     * another field (it replaces placeholder by placeholder) - curly brackets never survive.
     */
    public function testCurlyBracketsCannotFormAPlaceholderOfTheNameTemplate(): void
    {
        self::assertSame("Jan \u{FF5B}department\u{FF5D}", Normalizer::displayText('Jan {department}'));
        self::assertSame(Normalizer::LEFT_BRACE . Normalizer::RIGHT_BRACE, Normalizer::displayText('{}'));
        $a = Normalizer::displayText('={department}=');
        $b = Normalizer::displayText('?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?');
        foreach (['{name} ({department})', '{name}{department}', '{name} {department}'] as $template) {
            $composed = self::composedByCore($template, ['name' => $a, 'department' => $b]);
            self::assertStringNotContainsString('=?', $composed, $template);
        }
        // three fields next to each other without a blank: no delimiter at either boundary
        $fields = ['name' => Normalizer::displayText('x='), 'department' => Normalizer::displayText('?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ?'), 'phone' => Normalizer::displayText('=1')];
        $composed = self::composedByCore('{name}{department}{phone}', $fields);
        self::assertStringNotContainsString('=?', $composed);
        self::assertStringNotContainsString('?=', $composed);
        self::assertSame("x\u{FF1D}", $fields['name']);
        self::assertSame("\u{FF1F}UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ\u{FF1F}", $fields['department']);
        self::assertSame('=1', Normalizer::cutAtSign('=1'), 'without an at-sign nothing is cut (displayText() did the edges)');
        self::assertSame("x\u{FF1D}", Normalizer::cutAtSign('x=@bank.example'), 'the new end follows the same rule');
    }

    public function testCutAtSignKeepsWhatStandsBeforeIt(): void
    {
        self::assertSame('Jan', Normalizer::cutAtSign('Jan'));
        self::assertSame('ceo', Normalizer::cutAtSign('ceo@bank.example'));
        self::assertSame('IT', Normalizer::cutAtSign("IT (\u{FF20}HQ)"));
        self::assertSame('+48', Normalizer::cutAtSign("+48 \u{FE6B}x"));
        self::assertSame('', Normalizer::cutAtSign('@x'));
    }

    public function testAmpersandStaysReadable(): void
    {
        self::assertSame("Johnson \u{FF06} Johnson", Normalizer::displayText('Johnson & Johnson'));
        self::assertSame("AT\u{FF06}T", Normalizer::displayText('AT&T'));
        self::assertSame(Normalizer::AMPERSAND, "\u{FF06}");
        // a search word typed with '&' is compared with labels in the same notation
        self::assertSame("r\u{FF06}d", Normalizer::labelWord('r&d'));
        self::assertSame('sales', Normalizer::labelWord('sales'));
        self::assertSame(mb_strtolower(Normalizer::displayText('R&D Lab')), Normalizer::labelWord('r&d lab'));
    }

    public function testDisplayTextOutputIsSafeForRecipientHeaders(): void
    {
        $hostile = "Eve\u{202E}lp.amrif@evil\r\n\"Admin\" <admin@firma.pl>\\\u{200B}\x1B[31m\u{FEFF}";
        $out = Normalizer::displayText($hostile);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F\\\\"<>&]/', $out);
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
