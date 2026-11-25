<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-19: Roundcube's suggestion list (app.js ksearch_results_display())
 * fills its template with String.replace(), the directory text being the REPLACEMENT string - "$&",
 * "$`", "$'", "$1" and "$<name>" would be expanded - and ksearch_results_highlight() turns "##...%%"
 * into bold text without showing the markers. Display text therefore carries no ASCII '$', no "%%"
 * and no '%' at either end (two fields of a name template could join there).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DisplayTextTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function replacementPatterns(): array
    {
        return [
            'whole match' => ['Jan $& Kowalski'],
            'before the match' => ['Jan $` Kowalski'],
            'after the match' => ["Jan \$' Kowalski"],
            'group' => ['Jan $1$2 Kowalski'],
            'named group' => ['Jan $<x> Kowalski'],
            'escaped dollar' => ['Jan $$ Kowalski'],
            'at the edges' => ['$Jan$'],
        ];
    }

    #[DataProvider('replacementPatterns')]
    public function testJavascriptReplacementPatternsAreNeutralised(string $text): void
    {
        $shown = Normalizer::displayText($text);
        self::assertStringNotContainsString('$', $shown);
        self::assertStringContainsString(Normalizer::DOLLAR, $shown, 'still readable');
        self::assertStringContainsString('Jan', $shown);
    }

    public function testHighlightMarkersCannotBeFormed(): void
    {
        for ($n = 1; $n <= 6; $n++) {
            $run = str_repeat('%', $n);
            foreach (["a{$run}b", "##x{$run}", "{$run}x", "x{$run}", "50{$run} off", $run] as $text) {
                $shown = Normalizer::displayText($text);
                $label = json_encode($text) . ' -> ' . json_encode($shown);
                self::assertStringNotContainsString('%%', $shown, $label);
                self::assertDoesNotMatchRegularExpression('/^%|%$/', $shown, $label . ': a % at either end');
            }
        }
        self::assertSame('50% off', Normalizer::displayText('50% off'), 'a single % inside stays');
        self::assertSame('a% % %b', Normalizer::displayText('a%%%b'));
        self::assertSame("100\u{FF05}", Normalizer::displayText('100%'));
        // two texts a template puts next to each other ("{name}{department}") cannot form "%%"
        self::assertStringNotContainsString('%%', Normalizer::displayText('Jan%') . Normalizer::displayText('%Sales'));
        // and the at-sign cut keeps the rule at its new end
        self::assertSame("Jan\u{FF05}", Normalizer::cutAtSign(Normalizer::displayText('Jan%@bank.example')));
    }

    /**
     * Group and organization labels are display text; a search word is put into the same notation
     * (the characters inside a text - a word may stand anywhere in a label).
     */
    public function testLabelWordMirrorsDisplayText(): void
    {
        foreach (['a$b', 'r&d', 'x{y}z', 'a%%b', 'a%%%b', 'p$1q', 'x=?y?=z'] as $word) {
            self::assertSame(Normalizer::displayText($word), Normalizer::labelWord($word), $word);
            self::assertStringContainsString(Normalizer::labelWord($word), Normalizer::displayText('Team ' . $word . ' North'), $word);
        }
    }
}
