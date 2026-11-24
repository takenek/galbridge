<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\IdCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contact and group IDs (docs/ARCHITECTURE.md section 9): Roundcube 1.7.7 moves them through HTML ids,
 * JS strings, comma lists, "ID-source" pairs and GET URLs, so they must stay inside [A-Za-z0-9_],
 * be reversible and canonical (one row = one ID), and decoding must reject everything else without
 * ever throwing.
 */
#[CoversClass(IdCodec::class)]
final class IdCodecTest extends TestCase
{
    // ------------------------------------------------------------------ encode / decode

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function encodeCases(): iterable
    {
        yield 'architecture example' => ['jan.kowalski@firma.pl', 'janDkowalskiAfirmaDpl'];
        yield 'lower case and digits are literal' => ['abcxyz0189', 'abcxyz0189'];
        yield 'dot' => ['.', 'D'];
        yield 'at' => ['@', 'A'];
        yield 'hyphen' => ['-', 'H'];
        yield 'underscore' => ['_', 'U'];
        yield 'plus' => ['+', 'P'];
        yield 'space' => [' ', 'X20'];
        yield 'upper case letters are escaped' => ['AZ', 'X41X5A'];
        yield 'marker letters themselves are escaped' => ['DAHUPX', 'X44X41X48X55X50X58'];
        yield 'comma' => [',', 'X2C'];
        yield 'slash and equals' => ['/=', 'X2FX3D'];
        yield 'tilde' => ['~', 'X7E'];
        yield 'two-byte utf-8' => ['ż', 'XC5XBC'];
        yield 'three-byte utf-8' => ['用', 'XE7X94XA8'];
        yield 'four-byte utf-8' => ["\u{1F600}", 'XF0X9FX98X80'];
        yield 'every escape at once' => ['a.b@c-d_e+f g', 'aDbAcHdUePfX20g'];
        yield 'plus address' => ['jan+news@firma.pl', 'janPnewsAfirmaDpl'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('encodeCases')]
    public function testEncode(string $plain, string $encoded): void
    {
        self::assertSame($encoded, IdCodec::encode($plain));
        if ($plain !== '') {
            self::assertSame($plain, IdCodec::decode($encoded));
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function roundTripValues(): iterable
    {
        yield 'ascii address' => ['jan.kowalski@firma.pl'];
        yield 'every escape' => ['a.b-c_d+e@sub-domain.example.com'];
        yield 'polish local part' => ['żaneta.ćwik@firma.pl'];
        yield 'german local part' => ['müller@example.de'];
        yield 'cjk local part' => ['用户@example.com'];
        yield 'emoji' => ["\u{1F600}@example.com"];
        yield 'idna a-label domain' => ['jan@xn--w-uga1v8h.pl'];
        yield 'department with space' => ['sales team'];
        yield 'department with punctuation' => ['r&d / it, (emea)'];
        yield 'upper case text' => ['Sales'];
        yield 'digits only' => ['1001'];
        yield 'single byte' => ['a'];
        yield 'printable ascii' => [implode('', array_map('chr', range(0x20, 0x7E)))];
        yield 'max key length' => [str_repeat('ż', 127) . 'a']; // 255 bytes
    }

    #[DataProvider('roundTripValues')]
    public function testRoundTrip(string $value): void
    {
        $enc = IdCodec::encode($value);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]+$/D', $enc, 'safe charset (no - , + / = _)');
        self::assertSame($value, IdCodec::decode($enc));
        self::assertSame($enc, IdCodec::encode((string) IdCodec::decode($enc)), 'stable');
    }

    public function testEncodingIsInjectiveOverEveryByte(): void
    {
        $seen = [];
        for ($b = 0; $b < 256; $b++) {
            $enc = IdCodec::encode(chr($b));
            self::assertMatchesRegularExpression('/^(?:[a-z0-9]|[DAHUP]|X[0-9A-F]{2})$/D', $enc, 'byte ' . $b);
            self::assertArrayNotHasKey($enc, $seen, 'byte ' . $b . ' collides with byte ' . ($seen[$enc] ?? -1));
            $seen[$enc] = $b;
        }
        self::assertCount(256, $seen);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonCanonicalEncodings(): iterable
    {
        // a byte encode() writes literally or with a marker, written as X + hex instead
        yield 'escaped lower-case letter' => ['jX61n'];
        yield 'escaped digit' => ['X31001'];
        yield 'escaped dot' => ['janX2Ekowalski'];
        yield 'escaped at' => ['janX40firma'];
        yield 'escaped hyphen' => ['aX2Db'];
        yield 'escaped underscore' => ['aX5Fb'];
        yield 'escaped plus' => ['aX2Bb'];
        // lower-case hex variants of a valid escape
        yield 'lower-case hex (both digits)' => ['Xc5Xbc'];
        yield 'lower-case hex (second digit)' => ['XC5Xbc'];
        yield 'lower-case hex (first digit)' => ['Xc5XBC'];
        yield 'lower-case hex in upper-case letter escape' => ['X4aan'];
        // malformed escapes
        yield 'truncated escape (one digit)' => ['abcX4'];
        yield 'truncated escape (no digits)' => ['abcX'];
        yield 'non-hex escape' => ['aXGGb'];
        yield 'escape prefix followed by one marker letter' => ['XD'];
        // upper-case letters that are not markers
        yield 'unknown marker B' => ['janBkowalski'];
        yield 'unknown marker Z' => ['Z'];
        yield 'unknown marker E' => ['E'];
    }

    #[DataProvider('nonCanonicalEncodings')]
    public function testDecodeRejectsNonCanonicalEncodings(string $enc): void
    {
        self::assertNull(IdCodec::decode($enc));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidDecodedValues(): iterable
    {
        yield 'empty' => [''];
        yield 'NUL' => ['aX00b'];
        yield 'TAB' => ['aX09b'];
        yield 'LF' => ['aX0Ab'];
        yield 'CR LF' => ['aX0DX0Ab'];
        yield 'ESC' => ['aX1Bb'];
        yield 'unit separator' => ['aX1Fb'];
        yield 'DEL' => ['aX7Fb'];
        yield 'lone continuation byte' => ['aX80b'];
        yield 'lone lead byte' => ['aXC5b'];
        yield 'latin-1 byte' => ['XFF'];
        yield 'overlong dot (C0 AE)' => ['XC0XAE'];
        yield 'overlong slash (E0 80 AF)' => ['XE0X80XAF'];
        yield 'utf-16 surrogate (ED A0 80)' => ['XEDXA0X80'];
        yield 'above U+10FFFF (F4 90 80 80)' => ['XF4X90X80X80'];
        yield 'too long (256 bytes)' => [str_repeat('a', 256)];
    }

    #[DataProvider('invalidDecodedValues')]
    public function testDecodeRejectsInvalidValues(string $enc): void
    {
        self::assertNull(IdCodec::decode($enc));
    }

    public function testDecodeKeyLengthBoundary(): void
    {
        self::assertSame(str_repeat('a', IdCodec::MAX_KEY_BYTES), IdCodec::decode(str_repeat('a', IdCodec::MAX_KEY_BYTES)));
        self::assertNull(IdCodec::decode(str_repeat('a', IdCodec::MAX_KEY_BYTES + 1)));
        self::assertNull(IdCodec::decode(str_repeat('XC5XBC', 127) . 'aa'), '256 bytes in multi-byte characters');
    }

    // ------------------------------------------------------------------ contact IDs

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function contactIds(): iterable
    {
        yield 'person' => [IdCodec::PERSON, 'jan.kowalski@firma.pl', 'u_janDkowalskiAfirmaDpl'];
        yield 'alias account' => [IdCodec::ALIAS, 'all-staff@firma.pl', 'a_allHstaffAfirmaDpl'];
        yield 'mailing list' => [IdCodec::MAILLIST, 'news_letter@firma.pl', 'l_newsUletterAfirmaDpl'];
        yield 'plus and digits' => [IdCodec::PERSON, 'jan+1@a1.example', 'u_janP1Aa1Dexample'];
        yield 'idn domain as a-label' => [IdCodec::PERSON, 'jan@xn--w-uga1v8h.pl', 'u_janAxnHHwHuga1v8hDpl'];
        yield 'non-ascii local part' => [IdCodec::PERSON, 'żaneta@firma.pl', 'u_XC5XBCanetaAfirmaDpl'];
        yield 'cjk local part' => [IdCodec::PERSON, '用户@example.com', 'u_XE7X94XA8XE6X88XB7AexampleDcom'];
        yield 'shortest' => [IdCodec::PERSON, 'a@b', 'u_aAb'];
    }

    #[DataProvider('contactIds')]
    public function testContactIdRoundTrip(string $kind, string $address, string $id): void
    {
        self::assertSame($id, IdCodec::contactId($kind, $address));
        self::assertSame(['kind' => $kind, 'address' => $address], IdCodec::decodeContact($id));
        // safe for every Roundcube transport: HTML id, JS string, comma list, ID-source, GET URL
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $id);
        self::assertSame(1, substr_count($id, '_'));
        self::assertStringNotContainsString('-', $id);
        self::assertSame($id, rawurlencode($id));
    }

    public function testLongestPlausibleAddressStaysWellBelowTheCap(): void
    {
        // 64-byte local part (all multi-byte) + 189-byte domain = 254 bytes, the RFC 5321 path limit
        $address = str_repeat('ż', 32) . '@' . str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 61);
        self::assertSame(254, strlen($address));
        $id = IdCodec::contactId(IdCodec::PERSON, $address);
        self::assertLessThanOrEqual(1 + 1 + 3 * 254, strlen($id));
        self::assertSame(['kind' => 'u', 'address' => $address], IdCodec::decodeContact($id));
    }

    public function testContactIdRejectsUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdCodec::contactId('x', 'jan@firma.pl');
    }

    public function testContactIdRejectsGroupKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdCodec::contactId(IdCodec::GROUP_DEPARTMENT, 'jan@firma.pl');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidContactIds(): iterable
    {
        // types: Roundcube's get_cids() returns an array _cid unvalidated, JSON may give ints
        yield 'null' => [null];
        yield 'int' => [42];
        yield 'zero' => [0];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [['u_janAfirmaDpl']];
        yield 'nested array' => [['u' => ['janAfirmaDpl']]];
        yield 'object' => [new \stdClass()];
        yield 'stringable object' => [new class () {
            public function __toString(): string
            {
                return 'u_janAfirmaDpl';
            }
        }];
        // shape
        yield 'empty' => [''];
        yield 'string zero' => ['0'];
        yield 'kind only' => ['u_'];
        yield 'no separator' => ['ujanAfirmaDpl'];
        yield 'unknown kind' => ['x_janAfirmaDpl'];
        yield 'upper-case kind' => ['U_janAfirmaDpl'];
        yield 'two-letter kind' => ['ua_janAfirmaDpl'];
        yield 'group id is not a contact id' => ['dp_sales'];
        yield 'second underscore' => ['u_jan_Afirma'];
        yield 'leading space' => [' u_janAfirmaDpl'];
        yield 'trailing space' => ['u_janAfirmaDpl '];
        yield 'trailing newline' => ["u_janAfirmaDpl\n"];
        yield 'NUL inside' => ["u_jan\0AfirmaDpl"];
        // characters Roundcube splits or mangles
        yield 'hyphen (ID-source split)' => ['u_janAfirmaDpl-galbridge_domain'];
        yield 'hyphen inside' => ['u_jan-kowalskiAfirmaDpl'];
        yield 'comma (list separator)' => ['u_janAfirmaDpl,u_ewaAfirmaDpl'];
        yield 'base64url' => ['u_amFuQGZpcm1hLnBs-_'];
        yield 'base64 padding' => ['u_amFuQGZpcm1hLnBs=='];
        yield 'base64 plus and slash' => ['u_amFu+GZp/m1hLnBs'];
        yield 'percent-encoded' => ['u_jan%40firma.pl'];
        yield 'raw address' => ['u_jan@firma.pl'];
        yield 'raw dot' => ['u_janAfirma.pl'];
        // decodes, but is not a canonical lower-case vmail address
        yield 'upper-case local part' => ['u_X4AanAfirmaDpl'];
        yield 'upper-case domain' => ['u_janAX46irmaDpl'];
        yield 'non-ascii domain (u-label)' => ['u_janAXC5XBCXC3XB3XC5X82wDpl'];
        yield 'trailing dot on domain' => ['u_janAfirmaDplD'];
        yield 'decomposed local part (not NFC)' => ['u_zXCCX87anetaAfirmaDpl'];
        yield 'no at sign' => ['u_janDkowalski'];
        yield 'empty local part' => ['u_AfirmaDpl'];
        yield 'empty domain' => ['u_janA'];
        yield 'two at signs' => ['u_janAewaAfirmaDpl'];
        yield 'space in local part' => ['u_janX20kAfirmaDpl'];
        yield 'quote in local part' => ['u_X22janX22AfirmaDpl'];
        yield 'angle brackets' => ['u_X3CjanAfirmaDplX3E'];
        yield 'comma in local part' => ['u_janX2CewaAfirmaDpl'];
        yield 'empty domain label' => ['u_janAfirmaDDpl'];
        yield 'local part over 64 bytes' => ['u_' . str_repeat('a', 65) . 'AfirmaDpl'];
        yield 'address over 254 bytes' => ['u_' . str_repeat('a', 64) . 'A' . str_repeat('a', 63) . 'D' . str_repeat('b', 63) . 'D' . str_repeat('c', 62)];
        // non-canonical / malformed escapes
        yield 'escaped literal' => ['u_jX61nAfirmaDpl'];
        yield 'escaped at sign' => ['u_janX40firmaDpl'];
        yield 'lower-case hex' => ['u_Xc5Xbcaneta' . 'AfirmaDpl'];
        yield 'truncated escape' => ['u_janAfirmaDplX4'];
        // overlong
        yield 'longer than the format allows' => ['u_' . str_repeat('a', 766)];
        yield 'longer than any address' => ['u_' . str_repeat('a', 700) . 'AfirmaDpl'];
    }

    #[DataProvider('invalidContactIds')]
    public function testDecodeContactRejects(mixed $id): void
    {
        self::assertNull(IdCodec::decodeContact($id));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function c0ControlCharacters(): iterable
    {
        foreach ([0x00, 0x01, 0x08, 0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x1B, 0x1F, 0x7F] as $b) {
            yield sprintf('U+%04X', $b) => [chr($b)];
        }
    }

    #[DataProvider('c0ControlCharacters')]
    public function testDecodeRejectsC0ControlCharacters(string $char): void
    {
        self::assertNull(IdCodec::decodeContact('u_' . IdCodec::encode('ja' . $char . 'n@firma.pl')));
        self::assertNull(IdCodec::decodeContact('u_' . IdCodec::encode('jan@firma.pl' . $char)));
        self::assertNull(IdCodec::decodeGroup('dp_' . IdCodec::encode('sa' . $char . 'les')));
        self::assertNull(IdCodec::decode(IdCodec::encode($char)));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function c1ControlCharacters(): iterable
    {
        foreach ([0x80, 0x85, 0x8D, 0x9B, 0x9F] as $cp) {
            yield sprintf('U+%04X', $cp) => [(string) mb_chr($cp, 'UTF-8')];
        }
    }

    /**
     * C1 controls (U+0080-U+009F, e.g. U+009B = 8-bit CSI) are control characters as much as C0: a
     * "plausible lower-case address" (section 9) never contains one. (Group keys are not asserted
     * here: they must round-trip whatever Repository derives from LOWER(TRIM(department)).)
     */
    #[DataProvider('c1ControlCharacters')]
    public function testDecodeContactRejectsC1ControlCharacters(string $char): void
    {
        self::assertNull(IdCodec::decodeContact('u_' . IdCodec::encode('ja' . $char . 'n@firma.pl')));
        self::assertNull(IdCodec::decodeContact('a_' . IdCodec::encode('jan@firma.pl' . $char)));
    }

    // ------------------------------------------------------------------ group IDs

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function groupIds(): iterable
    {
        yield 'domain book, department' => ['d', IdCodec::GROUP_DEPARTMENT, 'sales', 'dp_sales'];
        yield 'domain book, domain' => ['d', IdCodec::GROUP_DOMAIN, 'firma.pl', 'dd_firmaDpl'];
        yield 'global book, department' => ['g', IdCodec::GROUP_DEPARTMENT, 'it support', 'gp_itX20support'];
        yield 'global book, domain' => ['g', IdCodec::GROUP_DOMAIN, 'xn--w-uga1v8h.pl', 'gd_xnHHwHuga1v8hDpl'];
        yield 'non-ascii department' => ['d', IdCodec::GROUP_DEPARTMENT, 'księgowość', 'dp_ksiXC4X99gowoXC5X9BXC4X87'];
        yield 'punctuation' => ['d', IdCodec::GROUP_DEPARTMENT, 'r&d-1_2+3', 'dp_rX26dH1U2P3'];
        yield 'single character' => ['d', IdCodec::GROUP_DEPARTMENT, 'a', 'dp_a'];
        yield 'max key length' => ['d', IdCodec::GROUP_DEPARTMENT, str_repeat('k', 255), 'dp_' . str_repeat('k', 255)];
    }

    #[DataProvider('groupIds')]
    public function testGroupIdRoundTrip(string $book, string $kind, string $key, string $id): void
    {
        self::assertSame($id, IdCodec::groupId($book, $kind, $key));
        self::assertSame(['book' => $book, 'kind' => $kind, 'key' => $key], IdCodec::decodeGroup($id));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $id);
        self::assertStringNotContainsString('-', $id);
        self::assertNull(IdCodec::decodeContact($id), 'a group id is never a contact id');
    }

    public function testGroupIdCarriesTheBookTag(): void
    {
        $domainBook = IdCodec::decodeGroup(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'sales'));
        $globalBook = IdCodec::decodeGroup(IdCodec::groupId('g', IdCodec::GROUP_DEPARTMENT, 'sales'));
        self::assertNotNull($domainBook);
        self::assertNotNull($globalBook);
        self::assertSame('d', $domainBook['book']);
        self::assertSame('g', $globalBook['book']);
        self::assertSame($domainBook['key'], $globalBook['key']);
        self::assertNotSame(IdCodec::groupId('d', 'p', 'sales'), IdCodec::groupId('g', 'p', 'sales'));
        self::assertNotSame(IdCodec::groupId('d', 'p', 'firma.pl'), IdCodec::groupId('d', 'd', 'firma.pl'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidGroupKinds(): iterable
    {
        yield 'unknown book tag' => ['x', IdCodec::GROUP_DEPARTMENT];
        yield 'upper-case book tag' => ['D', IdCodec::GROUP_DEPARTMENT];
        yield 'empty book tag' => ['', IdCodec::GROUP_DEPARTMENT];
        yield 'unknown kind' => ['d', 'x'];
        yield 'contact kind' => ['d', IdCodec::PERSON];
        yield 'two-letter tag' => ['dd', IdCodec::GROUP_DEPARTMENT];
    }

    #[DataProvider('invalidGroupKinds')]
    public function testGroupIdRejectsUnknownBookOrKind(string $book, string $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdCodec::groupId($book, $kind, 'sales');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidGroupIds(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [7];
        yield 'zero (core clears the group with 0)' => [0];
        yield 'false' => [false];
        yield 'array' => [['dp_sales']];
        yield 'empty' => [''];
        yield 'string zero' => ['0'];
        yield 'no key' => ['dp_'];
        yield 'unknown book tag' => ['xp_sales'];
        yield 'upper-case book tag' => ['Dp_sales'];
        yield 'unknown kind' => ['dx_sales'];
        yield 'contact id' => ['u_janAfirmaDpl'];
        yield 'missing kind' => ['d_sales'];
        yield 'hyphen' => ['dp_sales-galbridge_domain'];
        yield 'comma' => ['dp_sales,dp_it'];
        yield 'base64url' => ['dp_c2FsZXM-'];
        yield 'trailing newline' => ["dp_sales\n"];
        yield 'leading space' => [' dp_sales'];
        yield 'second underscore' => ['dp_sa_les'];
        yield 'whitespace-only key' => ['dp_X20'];
        yield 'leading space in key' => ['dp_X20sales'];
        yield 'trailing space in key' => ['dp_salesX20'];
        yield 'tab in key' => ['dp_salesX09'];
        yield 'upper-case key' => ['dp_X53ales'];
        yield 'non-canonical escape' => ['dp_sX61les'];
        yield 'lower-case hex' => ['dp_ksiXc4X99'];
        yield 'invalid utf-8' => ['dp_XFF'];
        yield 'overlong utf-8' => ['dp_XC0XAE'];
        yield 'key over 255 bytes' => ['dp_' . str_repeat('k', 256)];
        yield 'longer than the format allows' => ['dp_' . str_repeat('k', 766)];
    }

    #[DataProvider('invalidGroupIds')]
    public function testDecodeGroupRejects(mixed $id): void
    {
        self::assertNull(IdCodec::decodeGroup($id));
    }

    /**
     * Department keys are LOWER(TRIM(department)) exactly as the database computed them. LOWER() does
     * not fold non-ASCII letters on SQLite or a PostgreSQL cluster with C/POSIX ctype, so such keys may
     * keep upper-case non-ASCII letters and must still decode (otherwise that group could never be
     * selected). ASCII upper case and edge spaces never come out of LOWER(TRIM()) and stay invalid.
     */
    public function testDecodeGroupAcceptsDatabaseLoweredNonAsciiKeys(): void
    {
        $key = "\u{017B}\u{00D3}\u{0141}w"; // 'ŻÓŁw' as LOWER() leaves it under a C ctype
        self::assertSame(['book' => 'd', 'kind' => 'p', 'key' => $key], IdCodec::decodeGroup(IdCodec::groupId('d', 'p', $key)));
    }

    // ------------------------------------------------------------------ ID lists

    public function testDecodeContactListFromCommaString(): void
    {
        $rejected = -1;
        $list = IdCodec::decodeContactList('u_janAfirmaDpl,a_allAfirmaDpl,l_newsAfirmaDpl', $rejected);
        self::assertSame([
            ['kind' => 'u', 'address' => 'jan@firma.pl'],
            ['kind' => 'a', 'address' => 'all@firma.pl'],
            ['kind' => 'l', 'address' => 'news@firma.pl'],
        ], $list);
        self::assertSame(0, $rejected);
    }

    public function testDecodeContactListFromArray(): void
    {
        $rejected = -1;
        $list = IdCodec::decodeContactList(['x' => 'u_janAfirmaDpl', 7 => 'u_ewaAfirmaDpl'], $rejected);
        self::assertSame([
            ['kind' => 'u', 'address' => 'jan@firma.pl'],
            ['kind' => 'u', 'address' => 'ewa@firma.pl'],
        ], $list);
        self::assertTrue(array_is_list($list));
        self::assertSame(0, $rejected);
    }

    public function testDecodeContactListTrimsItems(): void
    {
        $rejected = -1;
        self::assertSame(
            [['kind' => 'u', 'address' => 'jan@firma.pl'], ['kind' => 'u', 'address' => 'ewa@firma.pl']],
            IdCodec::decodeContactList(" u_janAfirmaDpl ,\tu_ewaAfirmaDpl\n", $rejected),
        );
        self::assertSame(0, $rejected);
        self::assertSame([['kind' => 'u', 'address' => 'jan@firma.pl']], IdCodec::decodeContactList(['  u_janAfirmaDpl  '], $rejected));
        self::assertSame(0, $rejected);
    }

    public function testDecodeContactListDeduplicatesKeepingFirstOccurrence(): void
    {
        $rejected = -1;
        $list = IdCodec::decodeContactList('u_ewaAfirmaDpl,u_janAfirmaDpl,u_ewaAfirmaDpl, u_janAfirmaDpl,u_ewaAfirmaDpl', $rejected);
        self::assertSame([
            ['kind' => 'u', 'address' => 'ewa@firma.pl'],
            ['kind' => 'u', 'address' => 'jan@firma.pl'],
        ], $list);
        self::assertSame(0, $rejected, 'duplicates are not rejections');
    }

    public function testDecodeContactListKeepsSameAddressOfDifferentKinds(): void
    {
        $list = IdCodec::decodeContactList(['u_infoAfirmaDpl', 'a_infoAfirmaDpl', 'l_infoAfirmaDpl']);
        self::assertSame([
            ['kind' => 'u', 'address' => 'info@firma.pl'],
            ['kind' => 'a', 'address' => 'info@firma.pl'],
            ['kind' => 'l', 'address' => 'info@firma.pl'],
        ], $list);
    }

    public function testDecodeContactListCountsRejectedEntries(): void
    {
        $rejected = -1;
        $list = IdCodec::decodeContactList('u_janAfirmaDpl,garbage,u_X4AanAfirmaDpl,,dp_sales,u_janAfirmaDpl', $rejected);
        self::assertSame([['kind' => 'u', 'address' => 'jan@firma.pl']], $list);
        self::assertSame(4, $rejected, 'garbage, non-canonical, empty item, group id');

        $list = IdCodec::decodeContactList(['u_janAfirmaDpl', 42, null, ['u_ewaAfirmaDpl'], true, 1.5, new \stdClass(), 'u_ewa-x'], $rejected);
        self::assertSame([['kind' => 'u', 'address' => 'jan@firma.pl']], $list);
        self::assertSame(7, $rejected, 'non-string items are rejected, never cast');
    }

    public function testDecodeContactListResetsTheRejectedCounter(): void
    {
        $rejected = 99;
        self::assertSame([], IdCodec::decodeContactList([], $rejected));
        self::assertSame(0, $rejected);

        $rejected = 99;
        IdCodec::decodeContactList('u_janAfirmaDpl', $rejected);
        self::assertSame(0, $rejected);

        $rejected = 99;
        IdCodec::decodeContactList('bad', $rejected);
        self::assertSame(1, $rejected);
    }

    public function testDecodeContactListOfAllInvalidEntriesIsEmpty(): void
    {
        $rejected = 0;
        self::assertSame([], IdCodec::decodeContactList('a,b,c', $rejected));
        self::assertSame(3, $rejected);
        self::assertSame([], IdCodec::decodeContactList(''));
        self::assertSame([], IdCodec::decodeContactList(','));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonListValues(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [5];
        yield 'float' => [1.0];
        yield 'bool' => [true];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('nonListValues')]
    public function testDecodeContactListOfNonListValueIsEmpty(mixed $value): void
    {
        self::assertSame([], IdCodec::decodeContactList($value));
    }

    public function testDecodeNeverThrowsOnRandomInput(): void
    {
        mt_srand(20261124);
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789DAHUPXBCEF_-,=+/ ';
        for ($i = 0; $i < 2000; $i++) {
            $len = mt_rand(0, 40);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            foreach (['', 'u_', 'a_', 'l_', 'dp_', 'gd_'] as $prefix) {
                $contact = IdCodec::decodeContact($prefix . $s);
                if ($contact !== null) {
                    // whatever decodes is canonical: it re-encodes to exactly the input
                    self::assertSame($prefix . $s, IdCodec::contactId($contact['kind'], $contact['address']));
                }
                $group = IdCodec::decodeGroup($prefix . $s);
                if ($group !== null) {
                    self::assertSame($prefix . $s, IdCodec::groupId($group['book'], $group['kind'], $group['key']));
                }
            }
            $plain = IdCodec::decode($s);
            if ($plain !== null) {
                self::assertSame($s, IdCodec::encode($plain));
            }
        }
        self::assertSame('u_aAb', IdCodec::contactId('u', 'a@b'));
    }
}
