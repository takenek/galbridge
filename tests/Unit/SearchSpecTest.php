<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\IdCodec;
use GalBridge\Directory\SearchSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The serialisable search filter (docs/ARCHITECTURE.md section 10). Roundcube keeps what
 * get_search_set() returns in $_SESSION['contact_search'] and hands it back to set_search_set() in
 * later requests, for any book. fromArray() therefore accepts only what toArray() of the SAME book and
 * configuration fingerprint produces: no SQL, no scope, typed criteria only.
 */
#[CoversClass(SearchSpec::class)]
final class SearchSpecTest extends TestCase
{
    private const BOOK = 'galbridge_domain';
    private const FP = 'a3f1c9d2e4b5';

    /** marker for "remove this key" in tamperedFields() */
    private const MISSING = "\0missing";

    /**
     * @return iterable<string, array{0: SearchSpec}>
     */
    public static function specs(): iterable
    {
        yield 'none' => [SearchSpec::none(self::BOOK, self::FP)];
        yield 'quick, all modes 0' => [new SearchSpec(SearchSpec::QUICK, self::BOOK, self::FP, ['name', 'email'], ['jan kow'], 0)];
        yield 'quick strict' => [new SearchSpec(SearchSpec::QUICK, self::BOOK, self::FP, ['*'], ['jan@firma.pl'], 1)];
        yield 'quick prefix with required' => [new SearchSpec(SearchSpec::QUICK, self::BOOK, self::FP, ['name'], ['ja'], 2, ['email'])];
        yield 'quick non-ascii term' => [new SearchSpec(SearchSpec::QUICK, self::BOOK, self::FP, ['name'], ['Żaneta Ćwik "x" <y>'], 0)];
        yield 'advanced' => [new SearchSpec(SearchSpec::ADVANCED, self::BOOK, self::FP, ['firstname', 'surname', 'department'], ['jan', 'kowalski', ''], 0)];
        yield 'advanced prefix' => [new SearchSpec(SearchSpec::ADVANCED, self::BOOK, self::FP, ['email'], ['jan'], 2, ['email', 'name'])];
        yield 'ids, every kind' => [new SearchSpec(SearchSpec::IDS, self::BOOK, self::FP, ids: [
            ['kind' => IdCodec::PERSON, 'address' => 'jan.kowalski@firma.pl'],
            ['kind' => IdCodec::ALIAS, 'address' => 'all-staff@firma.pl'],
            ['kind' => IdCodec::MAILLIST, 'address' => 'news_letter@firma.pl'],
            ['kind' => IdCodec::PERSON, 'address' => 'żaneta@firma.pl'],
        ])];
        yield 'ids with required' => [new SearchSpec(SearchSpec::IDS, self::BOOK, self::FP, required: ['email'], ids: [['kind' => 'u', 'address' => 'jan@xn--w-uga1v8h.pl']])];
        yield 'ids empty' => [new SearchSpec(SearchSpec::IDS, self::BOOK, self::FP)];
        yield 'global book' => [new SearchSpec(SearchSpec::QUICK, 'galbridge_global', self::FP, ['name'], ['ewa'], 2)];
    }

    #[DataProvider('specs')]
    public function testRoundTrip(SearchSpec $spec): void
    {
        $back = SearchSpec::fromArray($spec->toArray(), $spec->book, $spec->fingerprint);
        self::assertEquals($spec, $back);
        self::assertNotNull($back);
        self::assertSame($spec->toArray(), $back->toArray());
    }

    #[DataProvider('specs')]
    public function testRoundTripThroughTheSession(SearchSpec $spec): void
    {
        // $_SESSION is serialised by PHP; the array must survive it without any object
        $stored = unserialize(serialize($spec->toArray()), ['allowed_classes' => false]);
        self::assertEquals($spec, SearchSpec::fromArray($stored, $spec->book, $spec->fingerprint));

        $json = json_decode((string) json_encode($spec->toArray()), true);
        self::assertEquals($spec, SearchSpec::fromArray($json, $spec->book, $spec->fingerprint));
    }

    #[DataProvider('specs')]
    public function testToArrayIsPlainData(SearchSpec $spec): void
    {
        $a = $spec->toArray();
        self::assertSame(['galbridge', 'type', 'book', 'fp', 'fields', 'values', 'mode', 'required', 'ids'], array_keys($a));
        array_walk_recursive($a, static function (mixed $v): void {
            self::assertTrue(is_string($v) || is_int($v), 'only strings and ints, got ' . get_debug_type($v));
        });
        foreach (['fields', 'values', 'required', 'ids'] as $k) {
            self::assertIsArray($a[$k]);
            self::assertTrue(array_is_list($a[$k]), $k);
        }
    }

    public function testToArrayShape(): void
    {
        $spec = new SearchSpec(SearchSpec::IDS, self::BOOK, self::FP, ['x'], ['y'], 2, ['email'], [
            ['kind' => 'u', 'address' => 'jan.kowalski@firma.pl'],
            ['kind' => 'l', 'address' => 'news@firma.pl'],
        ]);
        self::assertSame([
            'galbridge' => SearchSpec::VERSION,
            'type' => 'ids',
            'book' => self::BOOK,
            'fp' => self::FP,
            'fields' => ['x'],
            'values' => ['y'],
            'mode' => 2,
            'required' => ['email'],
            'ids' => ['u_janDkowalskiAfirmaDpl', 'l_newsAfirmaDpl'],
        ], $spec->toArray());
        self::assertSame(1, SearchSpec::VERSION);
    }

    public function testNone(): void
    {
        $none = SearchSpec::none('galbridge_global', 'fp2');
        self::assertSame(SearchSpec::NONE, $none->type);
        self::assertSame('galbridge_global', $none->book);
        self::assertSame('fp2', $none->fingerprint);
        self::assertSame([], $none->fields);
        self::assertSame([], $none->values);
        self::assertSame(0, $none->mode);
        self::assertSame([], $none->required);
        self::assertSame([], $none->ids);
    }

    public function testTypesAreDistinct(): void
    {
        $types = [SearchSpec::NONE, SearchSpec::QUICK, SearchSpec::ADVANCED, SearchSpec::IDS];
        self::assertSame($types, array_values(array_unique($types)));
    }

    public function testIsImmutable(): void
    {
        $spec = SearchSpec::none(self::BOOK, self::FP);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line deliberate write to a readonly property */
        $spec->book = 'galbridge_global';
    }

    // ------------------------------------------------------------------ binding

    public function testForeignBookIsRejected(): void
    {
        // Roundcube restores the last search for whatever _source the next request names
        $a = (new SearchSpec(SearchSpec::QUICK, 'galbridge_global', self::FP, ['name'], ['jan'], 0))->toArray();
        self::assertNull(SearchSpec::fromArray($a, 'galbridge_domain', self::FP));
        self::assertNull(SearchSpec::fromArray($a, 'GALBRIDGE_GLOBAL', self::FP));
        self::assertNull(SearchSpec::fromArray($a, 'galbridge_global ', self::FP));
        self::assertNull(SearchSpec::fromArray($a, '', self::FP));
        self::assertNotNull(SearchSpec::fromArray($a, 'galbridge_global', self::FP));
    }

    public function testForeignFingerprintIsRejected(): void
    {
        // a changed configuration invalidates every stored search
        $a = (new SearchSpec(SearchSpec::QUICK, self::BOOK, 'old-config', ['name'], ['jan'], 0))->toArray();
        self::assertNull(SearchSpec::fromArray($a, self::BOOK, 'new-config'));
        self::assertNull(SearchSpec::fromArray($a, self::BOOK, ''));
        self::assertNull(SearchSpec::fromArray($a, self::BOOK, 'OLD-CONFIG'));
        self::assertNotNull(SearchSpec::fromArray($a, self::BOOK, 'old-config'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonArrays(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'serialized array' => [serialize(self::valid())];
        yield 'json string' => [json_encode(self::valid())];
        yield 'int' => [1];
        yield 'stdClass' => [(object) self::valid()];
        yield 'ArrayObject' => [new \ArrayObject(self::valid())];
        // what core's own rcube_contacts stores (SQL WHERE clause) or another plugin's spec
        yield 'rcube_contacts filter' => ["(name ILIKE '%jan%')"];
    }

    #[DataProvider('nonArrays')]
    public function testNonArrayIsRejected(mixed $value): void
    {
        self::assertNull(SearchSpec::fromArray($value, self::BOOK, self::FP));
    }

    public function testEmptyArrayIsRejected(): void
    {
        self::assertNull(SearchSpec::fromArray([], self::BOOK, self::FP));
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function tamperedFields(): iterable
    {
        // version
        foreach (['missing' => self::MISSING, '0' => 0, '2' => 2, 'string 1' => '1', 'float 1.0' => 1.0, 'true' => true, 'null' => null] as $label => $v) {
            yield 'version ' . $label => ['galbridge', $v];
        }
        // binding
        yield 'book missing' => ['book', self::MISSING];
        yield 'book null' => ['book', null];
        yield 'book array' => ['book', [self::BOOK]];
        yield 'fp missing' => ['fp', self::MISSING];
        yield 'fp int' => ['fp', 0];
        // type
        foreach (['missing' => self::MISSING, 'unknown' => 'sql', 'empty' => '', 'upper case' => 'QUICK', 'int' => 1, 'null' => null, 'array' => ['quick'], 'all' => 'all'] as $label => $v) {
            yield 'type ' . $label => ['type', $v];
        }
        // list fields
        foreach (['fields', 'values', 'required', 'ids'] as $k) {
            yield $k . ' missing' => [$k, self::MISSING];
            yield $k . ' null' => [$k, null];
            yield $k . ' string' => [$k, 'name'];
            yield $k . ' map' => [$k, ['a' => 'name']];
            yield $k . ' sparse list' => [$k, [1 => 'name']];
            yield $k . ' int item' => [$k, [1]];
            yield $k . ' null item' => [$k, [null]];
            yield $k . ' bool item' => [$k, [true]];
            yield $k . ' float item' => [$k, [1.5]];
            yield $k . ' nested array item' => [$k, [['name']]];
            yield $k . ' object item' => [$k, [new \stdClass()]];
        }
        // mode
        foreach (['missing' => self::MISSING, 'string' => '1', 'float' => 1.0, 'null' => null, 'true' => true, 'array' => [1]] as $label => $v) {
            yield 'mode ' . $label => ['mode', $v];
        }
    }

    #[DataProvider('tamperedFields')]
    public function testTamperedSpecIsRejected(string $key, mixed $value): void
    {
        $a = self::valid();
        self::assertNotNull(SearchSpec::fromArray($a, self::BOOK, self::FP), 'baseline is valid');
        if ($value === self::MISSING) {
            unset($a[$key]);
        } else {
            $a[$key] = $value;
        }
        self::assertNull(SearchSpec::fromArray($a, self::BOOK, self::FP));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['nonsense'];
        yield 'hyphen' => ['u_janAfirmaDpl-galbridge_domain'];
        yield 'comma list in one item' => ['u_janAfirmaDpl,u_ewaAfirmaDpl'];
        yield 'unknown kind' => ['x_janAfirmaDpl'];
        yield 'group id' => ['dp_sales'];
        yield 'raw address' => ['jan@firma.pl'];
        yield 'non-canonical upper case' => ['u_X4AanAfirmaDpl'];
        yield 'non-canonical escape' => ['u_jX61nAfirmaDpl'];
        yield 'base64url' => ['u_amFuQGZpcm1hLnBs'];
        yield 'decoded array form' => [['kind' => 'u', 'address' => 'jan@firma.pl']];
    }

    /**
     * One bad ID invalidates the whole spec: dropping it would silently turn "these 3 contacts" into
     * "these 2 contacts" (compose-to-selected, export of selected).
     */
    #[DataProvider('invalidIds')]
    public function testInvalidIdRejectsTheWholeSpec(mixed $id): void
    {
        $a = self::valid();
        $a['type'] = SearchSpec::IDS;
        $a['ids'] = ['u_janAfirmaDpl', $id, 'u_ewaAfirmaDpl'];
        self::assertNull(SearchSpec::fromArray($a, self::BOOK, self::FP));
    }

    public function testIdsAreDecoded(): void
    {
        $a = self::valid();
        $a['type'] = SearchSpec::IDS;
        $a['ids'] = ['u_janAfirmaDpl', 'a_allHstaffAfirmaDpl', 'l_newsAfirmaDpl'];
        $spec = SearchSpec::fromArray($a, self::BOOK, self::FP);
        self::assertNotNull($spec);
        self::assertSame([
            ['kind' => 'u', 'address' => 'jan@firma.pl'],
            ['kind' => 'a', 'address' => 'all-staff@firma.pl'],
            ['kind' => 'l', 'address' => 'news@firma.pl'],
        ], $spec->ids);
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function modes(): iterable
    {
        yield 'SEARCH_ALL' => [0, 0];
        yield 'SEARCH_STRICT' => [1, 1];
        yield 'SEARCH_PREFIX' => [2, 2];
        yield 'strict|prefix' => [3, 3];
        yield 'SEARCH_GROUPS alone' => [4, 0];
        yield 'SEARCH_GROUPS|SEARCH_STRICT' => [5, 1];
        yield 'SEARCH_GROUPS|SEARCH_PREFIX' => [6, 2];
        yield 'all low bits' => [7, 3];
        yield 'high bits' => [0x100 | 2, 2];
        yield 'negative' => [-1, 3];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX, 3];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN, 0];
    }

    #[DataProvider('modes')]
    public function testModeIsMaskedToTheMatchBits(int $mode, int $expected): void
    {
        $a = self::valid();
        $a['mode'] = $mode;
        $spec = SearchSpec::fromArray($a, self::BOOK, self::FP);
        self::assertNotNull($spec);
        self::assertSame($expected, $spec->mode);
        self::assertGreaterThanOrEqual(0, $spec->mode);
        self::assertLessThanOrEqual(3, $spec->mode);
    }

    public function testCriteriaAreReturnedVerbatim(): void
    {
        // fromArray() validates the shape only; the current FieldMap re-validates the criteria
        $a = self::valid();
        $a['type'] = SearchSpec::ADVANCED;
        $a['fields'] = ['name', 'password', 'vcard'];
        $a['values'] = ["' OR 1=1 --", '%', ''];
        $a['required'] = ['photo'];
        $spec = SearchSpec::fromArray($a, self::BOOK, self::FP);
        self::assertNotNull($spec);
        self::assertSame(SearchSpec::ADVANCED, $spec->type);
        self::assertSame(['name', 'password', 'vcard'], $spec->fields);
        self::assertSame(["' OR 1=1 --", '%', ''], $spec->values);
        self::assertSame(['photo'], $spec->required);
        self::assertSame(self::BOOK, $spec->book);
        self::assertSame(self::FP, $spec->fingerprint);
    }

    public function testUnknownExtraKeysDoNotAddState(): void
    {
        $spec = new SearchSpec(SearchSpec::QUICK, self::BOOK, self::FP, ['name'], ['jan'], 0);
        $a = $spec->toArray() + ['sql' => '1=1', 'scope' => ['*'], 'where' => 'TRUE'];
        $back = SearchSpec::fromArray($a, self::BOOK, self::FP);
        self::assertThat($back, self::logicalOr(self::isNull(), self::equalTo($spec)));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return (new SearchSpec(SearchSpec::QUICK, self::BOOK, self::FP, ['name', 'email'], ['jan'], 0, ['email'], [
            ['kind' => 'u', 'address' => 'jan@firma.pl'],
        ]))->toArray();
    }
}
