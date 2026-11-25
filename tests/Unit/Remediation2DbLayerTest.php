<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\IdCodec;
use GalBridge\Directory\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-26 (the parts that need no Roundcube):
 *
 *  - rcube_db inlines bound values into the statement text and PDO scans that text again: a group
 *    key with a control character or a backslash is never decoded from a group ID (a forged ID
 *    matches nothing), so it is never bound;
 *  - expiry is judged by the database clock in its session time zone AND by UTC, whichever comes
 *    first (1.0.1: UTC only - on a database server east of UTC an entry expired in local time stayed
 *    visible for the offset).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DbLayerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unusableKeys(): array
    {
        return [
            'NUL' => ["r\x00d"],
            'unit separator' => ["r\x1fd"],
            'DEL' => ["r\x7fd"],
            'C1 next line' => ["r\u{85}d"],
            'C1 CSI' => ["r\u{9b}d"],
            'a backslash' => ['r\\d'],
            'a trailing backslash' => ['r\\'],
            'invalid UTF-8' => ["\xff"],
        ];
    }

    #[DataProvider('unusableKeys')]
    public function testGroupKeysWithControlCharactersOrABackslashAreRefused(string $key): void
    {
        self::assertFalse(IdCodec::usableGroupKey($key));
        self::assertNull(IdCodec::decodeGroup(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, $key)), 'a forged group ID never yields the key');
    }

    public function testOrdinaryGroupKeysStillDecode(): void
    {
        foreach (['sales', 'r&d', 'księgowość', 'it - support', "o'brien team", 'vertrieb/ost'] as $key) {
            self::assertTrue(IdCodec::usableGroupKey($key), $key);
            self::assertSame($key, IdCodec::decodeGroup(IdCodec::groupId('g', IdCodec::GROUP_DEPARTMENT, $key))['key'] ?? null, $key);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function clocks(): array
    {
        return [
            'MariaDB/MySQL' => ['mysql', 'LOCALTIMESTAMP'],
            'PostgreSQL' => ['pgsql', 'LOCALTIMESTAMP'],
            'SQLite (tests)' => ['sqlite', "datetime('now', 'localtime')"],
        ];
    }

    #[DataProvider('clocks')]
    public function testUnexpiredPredicateUsesTheDatabaseClockAndUtc(string $driver, string $clock): void
    {
        $now = '2026-01-01 00:00:00';
        $sql = QueryBuilder::unexpired($driver, $now, 'm.expired', 'd.expired');
        self::assertSame("((m.expired > ? AND m.expired > {$clock}) AND (d.expired > ? AND d.expired > {$clock}))", $sql->sql);
        self::assertSame([$now, $now], $sql->params, 'the UTC time is bound once per column');

        $one = QueryBuilder::unexpired($driver, $now, 'fd.expired');
        self::assertSame("(fd.expired > ? AND fd.expired > {$clock})", $one->sql);
        self::assertSame([$now], $one->params);
    }
}
