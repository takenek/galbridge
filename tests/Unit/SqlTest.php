<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\Sql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The predicate tree every directory query is built from (docs/ARCHITECTURE.md sections 2, 10, 12):
 * each fragment renders inside parentheses so composition can never change precedence, empty lists
 * have a defined meaning (IN () = FALSE, NOT IN () = TRUE), values are always bound, and column
 * expressions are refused when they contain quotes, placeholders or comment markers.
 */
#[CoversClass(Sql::class)]
final class SqlTest extends TestCase
{
    public function testConstants(): void
    {
        self::assertSame('(1=1)', Sql::true()->sql);
        self::assertSame([], Sql::true()->params);
        self::assertSame('(1=0)', Sql::false()->sql);
        self::assertSame([], Sql::false()->params);
    }

    public function testRawIsParenthesised(): void
    {
        $s = Sql::raw('m.active = 1 OR m.active = ?', [2]);
        self::assertSame('(m.active = 1 OR m.active = ?)', $s->sql);
        self::assertSame([2], $s->params);
        self::assertSame('(d.backupmx = 0)', Sql::raw('d.backupmx = 0')->sql);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<scalar|null>}>
     */
    public static function placeholderMismatches(): iterable
    {
        yield 'missing param' => ['a = ? AND b = ?', [1]];
        yield 'extra param' => ['a = ?', [1, 2]];
        yield 'params without placeholder' => ['a = 1', ['x']];
        yield 'placeholder without params' => ['a = ?', []];
    }

    /**
     * @param list<scalar|null> $params
     */
    #[DataProvider('placeholderMismatches')]
    public function testRawRejectsPlaceholderMismatch(string $sql, array $params): void
    {
        $this->expectException(\LogicException::class);
        Sql::raw($sql, $params);
    }

    /**
     * @param list<scalar|null> $params
     */
    #[DataProvider('placeholderMismatches')]
    public function testExistsRejectsPlaceholderMismatch(string $sql, array $params): void
    {
        $this->expectException(\LogicException::class);
        Sql::exists('SELECT 1 FROM `forwardings` f WHERE ' . $sql, $params);
    }

    public function testEq(): void
    {
        $s = Sql::eq('m.`domain`', 'firma.pl');
        self::assertSame('(m.`domain` = ?)', $s->sql);
        self::assertSame(['firma.pl'], $s->params);

        $i = Sql::eq('m.active', 1);
        self::assertSame('(m.active = ?)', $i->sql);
        self::assertSame([1], $i->params);
    }

    public function testValuesAreAlwaysBoundNeverInlined(): void
    {
        $hostile = "x' OR '1'='1 -- ; /* ? */";
        foreach ([
            Sql::eq('m.username', $hostile),
            Sql::in('m.username', [$hostile, 'b']),
            Sql::notIn('m.username', [$hostile]),
            Sql::like('mysql', 'm.name', $hostile),
            Sql::like('pgsql', 'm.name', $hostile),
        ] as $s) {
            self::assertStringNotContainsString($hostile, $s->sql);
            self::assertStringNotContainsString("'1'", $s->sql);
            self::assertContains($hostile, $s->params);
            self::assertSame(substr_count($s->sql, '?'), count($s->params), $s->sql);
        }
    }

    public function testIn(): void
    {
        $s = Sql::in('m.`domain`', ['a.pl', 'b.pl', 'c.pl']);
        self::assertSame('(m.`domain` IN (?, ?, ?))', $s->sql);
        self::assertSame(['a.pl', 'b.pl', 'c.pl'], $s->params);

        $one = Sql::in('m.active', [1]);
        self::assertSame('(m.active IN (?))', $one->sql);
        self::assertSame([1], $one->params);
    }

    public function testInReindexesParams(): void
    {
        /** @var list<string> $values a caller may hand over a filtered (non-list) array */
        $values = array_filter(['a.pl', '', 'b.pl']);
        $s = Sql::in('m.`domain`', $values);
        self::assertSame(['a.pl', 'b.pl'], $s->params);
        self::assertTrue(array_is_list($s->params));

        /** @var list<string> $keyed */
        $keyed = ['x' => 'a.pl', 'y' => 'b.pl'];
        self::assertSame(['a.pl', 'b.pl'], Sql::notIn('m.`domain`', $keyed)->params);
    }

    public function testEmptyInIsFalseAndEmptyNotInIsTrue(): void
    {
        // a clause is never silently omitted: an empty scope matches nothing, an empty deny-list hides nothing
        self::assertSame('(1=0)', Sql::in('m.`domain`', [])->sql);
        self::assertSame([], Sql::in('m.`domain`', [])->params);
        self::assertSame('(1=1)', Sql::notIn('m.`domain`', [])->sql);
        self::assertSame([], Sql::notIn('m.`domain`', [])->params);

        $scope = Sql::and(Sql::in('m.`domain`', []), Sql::raw('m.active = 1'));
        self::assertSame('((1=0) AND (m.active = 1))', $scope->sql);
    }

    public function testNotIn(): void
    {
        $s = Sql::notIn('m.username', ['ceo@firma.pl', 'cfo@firma.pl']);
        self::assertSame('(m.username NOT IN (?, ?))', $s->sql);
        self::assertSame(['ceo@firma.pl', 'cfo@firma.pl'], $s->params);
    }

    public function testNot(): void
    {
        $s = Sql::not(Sql::eq('a', 1));
        self::assertSame('(NOT (a = ?))', $s->sql);
        self::assertSame([1], $s->params);
        self::assertSame('(NOT (NOT (a = ?)))', Sql::not($s)->sql);
        self::assertSame('(NOT (1=0))', Sql::not(Sql::in('a', []))->sql, 'NOT of an empty IN is TRUE');
    }

    public function testEmptyAndIsTrueAndEmptyOrIsFalse(): void
    {
        self::assertSame('(1=1)', Sql::and()->sql);
        self::assertSame('(1=0)', Sql::or()->sql);
        self::assertSame([], Sql::and()->params);
        self::assertSame([], Sql::or()->params);
    }

    public function testSingleOperandIsReturnedAsIs(): void
    {
        $eq = Sql::eq('a', 1);
        self::assertSame($eq, Sql::and($eq));
        self::assertSame($eq, Sql::or($eq));
    }

    public function testAndOrJoinAndConcatenateParamsInOrder(): void
    {
        $and = Sql::and(Sql::eq('a', 1), Sql::eq('b', 'x'), Sql::in('c', [2, 3]));
        self::assertSame('((a = ?) AND (b = ?) AND (c IN (?, ?)))', $and->sql);
        self::assertSame([1, 'x', 2, 3], $and->params);

        $or = Sql::or(Sql::eq('a', 1), Sql::raw('b > ?', [5]));
        self::assertSame('((a = ?) OR (b > ?))', $or->sql);
        self::assertSame([1, 5], $or->params);
    }

    public function testCompositionCannotChangePrecedence(): void
    {
        // "scope AND a OR b" must be impossible: OR inside AND stays grouped
        $scope = Sql::eq('m.`domain`', 'firma.pl');
        $filter = Sql::or(Sql::like('mysql', 'm.name', '%jan%'), Sql::like('mysql', 'm.username', '%jan%'));
        $root = Sql::and($scope, $filter);
        self::assertSame("((m.`domain` = ?) AND ((m.name LIKE ? ESCAPE '!') OR (m.username LIKE ? ESCAPE '!')))", $root->sql);
        self::assertSame(['firma.pl', '%jan%', '%jan%'], $root->params);

        // a raw fragment with a bare OR is wrapped as well
        $raw = Sql::raw('a = 1 OR b = 1');
        self::assertSame('((m.`domain` = ?) AND (a = 1 OR b = 1))', Sql::and($scope, $raw)->sql);

        // deep nesting: params follow the textual order of the placeholders
        $deep = Sql::and(Sql::or(Sql::eq('a', 1), Sql::and(Sql::eq('b', 2), Sql::not(Sql::eq('c', 3)))), Sql::notIn('d', [4, 5]));
        self::assertSame('(((a = ?) OR ((b = ?) AND (NOT (c = ?)))) AND (d NOT IN (?, ?)))', $deep->sql);
        self::assertSame([1, 2, 3, 4, 5], $deep->params);
    }

    public function testEveryFragmentIsBalancedAndParenthesised(): void
    {
        $fragments = [
            Sql::true(), Sql::false(), Sql::raw('x = ?', [1]), Sql::eq('a', 1), Sql::in('a', [1, 2]), Sql::in('a', []),
            Sql::notIn('a', [1]), Sql::notIn('a', []), Sql::not(Sql::eq('a', 1)), Sql::and(), Sql::or(),
            Sql::and(Sql::eq('a', 1), Sql::eq('b', 2)), Sql::or(Sql::eq('a', 1), Sql::eq('b', 2)),
            Sql::like('mysql', 'a', 'x'), Sql::like('pgsql', 'a', 'x'), Sql::exists('SELECT 1 FROM t WHERE t.a = ?', [1]),
        ];
        foreach ($fragments as $s) {
            self::assertStringStartsWith('(', $s->sql);
            self::assertStringEndsWith(')', $s->sql);
            self::assertTrue(self::outerParenthesesEnclose($s->sql), $s->sql);
            self::assertSame(substr_count($s->sql, '?'), count($s->params), $s->sql);
            self::assertTrue(array_is_list($s->params));
        }
    }

    public function testExists(): void
    {
        $s = Sql::exists('SELECT 1 FROM `forwardings` f WHERE f.address = ml.address AND f.active = ?', [1]);
        self::assertSame('(EXISTS (SELECT 1 FROM `forwardings` f WHERE f.address = ml.address AND f.active = ?))', $s->sql);
        self::assertSame([1], $s->params);
        self::assertSame([], Sql::exists('SELECT 1')->params);
    }

    // ------------------------------------------------------------------ LIKE

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function likeDrivers(): iterable
    {
        yield 'pgsql uses ILIKE' => ['pgsql', 'ILIKE'];
        yield 'mysql' => ['mysql', 'LIKE'];
        yield 'sqlite' => ['sqlite', 'LIKE'];
    }

    #[DataProvider('likeDrivers')]
    public function testLikeOperatorPerDriver(string $driver, string $op): void
    {
        $s = Sql::like($driver, 'm.name', '%jan%');
        self::assertSame("(m.name {$op} ? ESCAPE '!')", $s->sql);
        self::assertSame(['%jan%'], $s->params);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function likeEscapes(): iterable
    {
        yield 'plain' => ['jan', 'jan'];
        yield 'percent' => ['50%', '50!%'];
        yield 'underscore' => ['jan_k', 'jan!_k'];
        yield 'escape char itself' => ['hi!', 'hi!!'];
        yield 'escape before percent' => ['!%', '!!!%'];
        yield 'all three' => ['a%b_c!d', 'a!%b!_c!!d'];
        yield 'repeated' => ['%%__!!', '!%!%!_!_!!!!'];
        yield 'backslash is not special' => ['a\\%', 'a\\!%'];
        yield 'quotes are bound, not escaped' => ["o'brien", "o'brien"];
        yield 'brackets are not special' => ['[a-z]', '[a-z]'];
        yield 'non-ascii' => ['żółw_%', 'żółw!_!%'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('likeEscapes')]
    public function testEscapeLike(string $in, string $expected): void
    {
        self::assertSame($expected, Sql::escapeLike($in));
    }

    public function testEscapedLikePatternMatchesOnlyTheLiteral(): void
    {
        // emulate LIKE ... ESCAPE '!' to prove the escaping is complete: wildcards in user input are literal
        foreach (['50%', 'a_b', 'x!y', '%', '_', '!', '!%_', 'jan'] as $literal) {
            $pattern = '%' . Sql::escapeLike($literal) . '%';
            self::assertTrue(self::likeMatches($pattern, 'prefix' . $literal . 'suffix'), $literal);
            self::assertFalse(self::likeMatches(Sql::escapeLike($literal), $literal . 'x'), $literal . ' (no wildcard)');
        }
        self::assertFalse(self::likeMatches('%' . Sql::escapeLike('50%') . '%', '500'));
        self::assertFalse(self::likeMatches('%' . Sql::escapeLike('a_b') . '%', 'axb'));
        self::assertTrue(self::likeMatches('%' . Sql::escapeLike('a_b') . '%', 'xa_bx'));
    }

    // ------------------------------------------------------------------ column expressions

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function safeExpressions(): iterable
    {
        yield 'column' => ['username'];
        yield 'qualified' => ['m.username'];
        yield 'backticks' => ['m.`domain`'];
        yield 'function' => ['LOWER(TRIM(m.department))'];
        yield 'arithmetic and comparison operators' => ['(a.x * 2) <> m.y / 3'];
    }

    #[DataProvider('safeExpressions')]
    public function testSafeExpressionsAreAccepted(string $expr): void
    {
        $s = Sql::eq($expr, 'x');
        self::assertSame('(' . $expr . ' = ?)', $s->sql);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeExpressions(): iterable
    {
        yield 'single quote' => ["m.name = 'x'"];
        yield 'empty string literal' => ["COALESCE(NULLIF(TRIM(m.name),''), m.username)"];
        yield 'double quote' => ['"name"'];
        yield 'placeholder' => ['m.name = ? OR 1'];
        yield 'statement separator' => ['m.name; DROP TABLE mailbox'];
        yield 'line comment' => ['m.name -- '];
        yield 'block comment' => ['m.name /* x */'];
        yield 'block comment open only' => ['m.name /*'];
    }

    #[DataProvider('unsafeExpressions')]
    public function testUnsafeExpressionInEqIsRejected(string $expr): void
    {
        $this->expectException(\LogicException::class);
        Sql::eq($expr, 'x');
    }

    #[DataProvider('unsafeExpressions')]
    public function testUnsafeExpressionInInIsRejected(string $expr): void
    {
        $this->expectException(\LogicException::class);
        Sql::in($expr, ['x']);
    }

    #[DataProvider('unsafeExpressions')]
    public function testUnsafeExpressionInNotInIsRejected(string $expr): void
    {
        $this->expectException(\LogicException::class);
        Sql::notIn($expr, ['x']);
    }

    #[DataProvider('unsafeExpressions')]
    public function testUnsafeExpressionInLikeIsRejected(string $expr): void
    {
        $this->expectException(\LogicException::class);
        Sql::like('pgsql', $expr, 'x');
    }

    /**
     * '#' starts a comment on MariaDB/MySQL just like '-- ': the expression check must refuse it, or a
     * future column constant such as "m.name # ..." would comment out the rest of the predicate.
     */
    public function testMysqlHashCommentIsRejected(): void
    {
        $expr = "m.name # \n OR 1=1";
        $accepted = [];
        foreach ([
            'eq' => static fn () => Sql::eq($expr, 'x'),
            'in' => static fn () => Sql::in($expr, ['x']),
            'notIn' => static fn () => Sql::notIn($expr, ['x']),
            'like' => static fn () => Sql::like('mysql', $expr, 'x'),
        ] as $factory => $build) {
            try {
                $build();
                $accepted[] = $factory;
            } catch (\LogicException) {
                // refused, as it should be
            }
        }
        self::assertSame([], $accepted, 'factories that accepted a MySQL # comment in a column expression');
    }

    // ------------------------------------------------------------------ immutability

    public function testFragmentsAreImmutable(): void
    {
        $s = Sql::eq('a', 1);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line deliberate write to a readonly property */
        $s->sql = '(1=1)';
    }

    public function testParamsAreImmutable(): void
    {
        $s = Sql::eq('a', 1);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line deliberate write to a readonly property */
        $s->params = [];
    }

    public function testConstructorIsPrivate(): void
    {
        $ctor = (new \ReflectionClass(Sql::class))->getConstructor();
        self::assertNotNull($ctor);
        self::assertTrue($ctor->isPrivate(), 'fragments can only be built through the validating factories');
        self::assertTrue((new \ReflectionClass(Sql::class))->isFinal());
    }

    public function testCompositionDoesNotMutateOperands(): void
    {
        $a = Sql::eq('a', 1);
        $b = Sql::eq('b', 2);
        Sql::and($a, $b);
        Sql::or($a, $b);
        Sql::not($a);
        self::assertSame('(a = ?)', $a->sql);
        self::assertSame([1], $a->params);
        self::assertSame('(b = ?)', $b->sql);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * True when the first '(' is closed by the last ')' (the whole fragment is one group).
     */
    private static function outerParenthesesEnclose(string $sql): bool
    {
        $depth = 0;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0 && $i !== $len - 1) {
                    return false;
                }
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /**
     * Reference implementation of SQL "value LIKE pattern ESCAPE '!'" (case-sensitive; enough here).
     */
    private static function likeMatches(string $pattern, string $value): bool
    {
        $regex = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '!') {
                if ($i + 1 >= $len) {
                    throw new \LogicException('dangling escape character in ' . $pattern);
                }
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        return preg_match('/^' . $regex . '$/sD', $value) === 1;
    }
}
