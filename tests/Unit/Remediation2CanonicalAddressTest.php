<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\RecordMapper;
use GalBridge\Directory\Viewer;
use GalBridge\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-05 / F-16 / F-20: one address rule for the directory
 * (Normalizer::CANONICAL_ADDRESS) - an allow-list of lower-case ASCII letters, digits and . _ + = -
 * in the local part and an ASCII host name - that the PHP re-check and the SQL of every book apply
 * alike (QueryBuilder::canonicalAddressSql()). Here: the rule itself, that it is a strict subset of
 * Roundcube's e-mail check and of Normalizer::address(), that the SQLite rendering and the ERE of
 * MariaDB/MySQL/PostgreSQL decide exactly like it (the engines themselves:
 * Integration/Remediation2CanonicalRowsTest), and the name core takes for empty.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2CanonicalAddressTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            'plain' => ['jan.kowalski@example.com', 'jan.kowalski@example.com'],
            'every character of the local part' => ['a_b+c=d-e.f9@example.com', 'a_b+c=d-e.f9@example.com'],
            'equals signs at the edges' => ['=a=@example.com', '=a=@example.com'],
            'one character' => ['a@b.cd', 'a@b.cd'],
            'digits in labels' => ['a@1.2b.c3', 'a@1.2b.c3'],
            'hyphen inside a label' => ['a@b-c.d-e.fg', 'a@b-c.d-e.fg'],
            'A-label host' => ['klaus@xn--bcher-kva.example', 'klaus@xn--bcher-kva.example'],
            'A-label top-level label' => ['a@b.xn--p1ai', 'a@b.xn--p1ai'],
            'shortest A-label top-level label' => ['a@b.xn--a', 'a@b.xn--a'],
            'local part of 64 bytes' => [str_repeat('a', 64) . '@b.cd', str_repeat('a', 64) . '@b.cd'],
            'label of 63 bytes' => ['a@' . str_repeat('b', 63) . '.cd', 'a@' . str_repeat('b', 63) . '.cd'],
            'address of 254 bytes' => [self::long(254), self::long(254)],
            'ASCII capitals: the lower-case form' => ['Jan.Kowalski@Example.COM', 'jan.kowalski@example.com'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejected(): array
    {
        $out = [];
        foreach (str_split("&#?%{}/\$!*^|`~'\"(),:;<>[]\\ @") as $c) {
            $out['local part with ' . json_encode($c)] = ['a' . $c . 'b@example.com'];
        }
        return $out + [
            'non-ASCII letter' => ["zo\u{00EB}@example.com"],
            'NFD spelling' => ["zoe\u{0308}@example.com"],
            'KELVIN SIGN (folds to k)' => ["\u{212A}@example.com"],
            'LONG S (folds to s)' => ["\u{017F}@example.com"],
            'zero-width space' => ["a\u{200B}b@example.com"],
            'soft hyphen' => ["a\u{00AD}b@example.com"],
            'fullwidth at-sign' => ["a\u{FF20}b@example.com"],
            'U-label host' => ["klaus@b\u{00FC}cher.example"],
            'leading dot' => ['.a@example.com'],
            'trailing dot' => ['a.@example.com'],
            'double dot' => ['a..b@example.com'],
            'empty local part' => ['@example.com'],
            'no at-sign' => ['example.com'],
            'two at-signs' => ['a@b@example.com'],
            'local part of 65 bytes' => [str_repeat('a', 65) . '@b.cd'],
            'address of 255 bytes' => [self::long(255)],
            'label of 64 bytes' => ['a@' . str_repeat('b', 64) . '.cd'],
            'single-label host' => ['a@localhost'],
            'underscore in a label' => ['a@b_c.example'],
            'leading hyphen of a label' => ['a@-b.example'],
            'trailing hyphen of a label' => ['a@b-.example'],
            'hyphen at the end' => ['a@b.example-'],
            'empty label' => ['a@b..example'],
            'leading dot of the host' => ['a@.example.com'],
            'trailing dot of the host' => ['a@example.com.'],
            'all-digit top-level label' => ['a@b.123'],
            'top-level label starting with a digit' => ['a@b.1com'],
            'hyphenated top-level label' => ['a@b.c-om'],
            'IP literal' => ['a@[192.0.2.1]'],
            'trailing newline' => ["a@example.com\n"],
            'blank' => [' a@example.com'],
            'NUL' => ["a\0@example.com"],
            'invalid UTF-8' => ["a\xC3@example.com"],
            'not a string' => [42],
            'null' => [null],
        ];
    }

    #[DataProvider('accepted')]
    public function testTheRuleAcceptsCanonicalAddresses(string $value, string $expected): void
    {
        self::assertSame($expected, Normalizer::canonicalAddress($value));
        self::assertSame(substr($expected, (int) strrpos($expected, '@') + 1), Normalizer::canonicalDomain(substr($value, (int) strrpos($value, '@') + 1)));
    }

    #[DataProvider('rejected')]
    public function testTheRuleRejectsEverythingElse(mixed $value): void
    {
        self::assertNull(Normalizer::canonicalAddress($value));
    }

    public function testTheDomainRuleIsTheHostPartOfTheAddressRule(): void
    {
        foreach (['example.com', 'xn--bcher-kva.example', 'b.xn--a', str_repeat('b', 63) . '.cd'] as $domain) {
            self::assertSame($domain, Normalizer::canonicalDomain($domain));
        }
        foreach (['localhost', 'b_c.example', '-b.example', 'b..example', 'example.com.', 'b.123', "b\u{00FC}cher.example", "example.com\n", str_repeat('b.', 126) . 'cde'] as $domain) {
            self::assertNull(Normalizer::canonicalDomain($domain), json_encode($domain) ?: '');
        }
    }

    /**
     * Every address the rule accepts passes Roundcube's e-mail check and is its own
     * Normalizer::address() - a row the SQL offers can never be dropped by those checks (a full set
     * containing it is never refused for it). A seeded corpus over the characters that matter.
     */
    public function testTheRuleIsASubsetOfCoreCheckAndNormalizer(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC): rcube_utils::check_email()');
        }
        mt_srand(20261125);
        $alphabet = str_split('ab0x-n._+=@');
        $accepted = 0;
        foreach (self::corpus($alphabet, 120000) as $value) {
            $canonical = Normalizer::canonicalAddress($value);
            if ($canonical === null) {
                continue;
            }
            $accepted++;
            self::assertTrue(\rcube_utils::check_email($canonical, false), $canonical);
            self::assertSame($canonical, Normalizer::address($canonical), $canonical);
        }
        self::assertGreaterThan(500, $accepted, 'the corpus holds canonical addresses');
    }

    /**
     * The SQLite rendering (the test mirror) decides exactly like the PHP rule: boundary cases and a
     * seeded corpus, evaluated by SQLite itself.
     */
    public function testTheSqliteRenderingMatchesThePhpRule(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $address = QueryBuilder::canonicalAddressSql('sqlite', 'v');
        $domain = QueryBuilder::canonicalDomainSql('sqlite', 'v');
        $withDomain = QueryBuilder::canonicalAddressSql('sqlite', 'v', 'd');
        $statement = $pdo->prepare('SELECT CASE WHEN ' . $address->sql . ' THEN 1 ELSE 0 END, CASE WHEN ' . $domain->sql . ' THEN 1 ELSE 0 END,'
            . ' CASE WHEN ' . $withDomain->sql . ' THEN 1 ELSE 0 END FROM (SELECT ? AS v, ? AS d) x');
        mt_srand(4711);
        $values = [...array_column(self::accepted(), 0), ...array_filter(array_column(self::rejected(), 0), 'is_string'), ...self::corpus(str_split('ab0x-n._+=@AZ'), 30000)];
        foreach ($values as $value) {
            $host = substr($value, (int) strrpos($value, '@') + 1);
            $statement->execute([...$address->params, ...$domain->params, ...$withDomain->params, $value, $host]);
            [$sqlAddress, $sqlDomain, $sqlWithDomain] = array_map('intval', (array) $statement->fetch(\PDO::FETCH_NUM));
            $label = (string) json_encode($value);
            // SQLite compares case-sensitively: a value with capitals is not canonical there
            self::assertSame(Normalizer::canonicalAddress($value) === $value ? 1 : 0, $sqlAddress, $label);
            self::assertSame(Normalizer::canonicalDomain($value) === $value ? 1 : 0, $sqlDomain, $label);
            self::assertSame($sqlAddress, $sqlWithDomain, $label . ' (with its own host as domain column)');
        }
    }

    /**
     * The ERE that MariaDB/MySQL (REGEXP) and PostgreSQL (~) evaluate, combined with the conditions
     * the renderings put next to it (ASCII, no character outside the alphabet, the lengths),
     * decides exactly like the PHP rule - evaluated here with PCRE, which reads this ERE alike.
     */
    public function testTheEreFormMatchesThePhpRule(): void
    {
        self::assertStringNotContainsString('?', QueryBuilder::ADDRESS_ERE, 'rcube_db takes every ? for a placeholder');
        self::assertStringNotContainsString('\\', QueryBuilder::ADDRESS_ERE, 'no backslash is ever bound (Connection::select())');
        mt_srand(815);
        $values = [...array_column(self::accepted(), 0), ...array_filter(array_column(self::rejected(), 0), 'is_string'), ...self::corpus(str_split('ab0x-n._+=@'), 60000)];
        foreach ($values as $value) {
            $rendered = strlen($value) === mb_strlen($value, '8bit') && preg_match('/^[\x00-\x7F]*$/', $value) === 1
                && preg_match('/' . QueryBuilder::ADDRESS_OTHER_CHAR . '/', $value) !== 1
                && preg_match('/' . QueryBuilder::ADDRESS_ERE . '/D', $value) === 1
                && strlen($value) <= 254 && (int) strpos($value, '@') + 1 <= 65;
            self::assertSame(Normalizer::canonicalAddress($value) === $value, $rendered, (string) json_encode($value));
            $domain = preg_match('/^[\x00-\x7F]*$/', $value) === 1 && preg_match('/' . QueryBuilder::DOMAIN_OTHER_CHAR . '/', $value) !== 1
                && preg_match('/' . QueryBuilder::DOMAIN_ERE . '/D', $value) === 1 && strlen($value) <= 253;
            self::assertSame(Normalizer::canonicalDomain($value) === $value, $domain, (string) json_encode($value));
        }
    }

    /**
     * Roundcube takes a name '0' for empty (compose_list_name(): empty()): such a name falls back to
     * first and last name or to the local part, so the bare address never becomes the recipient text.
     */
    public function testANameThatCoreTreatsAsEmptyFallsBack(): void
    {
        $book = new BookDefinition(BookDefinition::DOMAIN, 'd', 'Domain', false, ['example.com'], [], ['example.com'], 'none', false, true, true, false, false, 100, false, []);
        $viewer = new Viewer('jan.kowalski@example.com', 'example.com', ['jan.kowalski@example.com'], ['mailbox' => ['username', 'name', 'domain', 'first_name', 'last_name']]);
        $mapper = new RecordMapper($book, new FieldMap(['name', 'email', 'firstname', 'surname'], $viewer, false, false), [], []);
        self::assertSame('Jan Kowalski', $mapper->person(['username' => 'jan@example.com', 'domain' => 'example.com', 'name' => '0', 'first_name' => 'Jan', 'last_name' => 'Kowalski'])['name'] ?? null);
        self::assertSame('anna.nowak', $mapper->person(['username' => 'anna.nowak@example.com', 'domain' => 'example.com', 'name' => '0', 'first_name' => '0', 'last_name' => ''])['name'] ?? null);
        self::assertSame("\u{FF1D}x\u{FF1D}", $mapper->person(['username' => '=x=@example.com', 'domain' => 'example.com', 'name' => ' '])['name'] ?? null, 'an edge = is full-width');
        self::assertSame('team', $mapper->mailList('a', ['address' => 'team@example.com', 'domain' => 'example.com', 'name' => '0'])['name'] ?? null);
        \GalBridge\Log::resetOnce();
        $GLOBALS['galbridge_test_log'] = [];
        self::assertNull($mapper->person(['username' => 'a&b@example.com', 'domain' => 'example.com', 'name' => 'X']), 'not canonical: no record');
        // the log names the row by a non-reversible tag, never by its address
        $log = implode("\n", $GLOBALS['galbridge_test_log']);
        self::assertStringContainsString('WARN scope: row_invalid: ', $log);
        self::assertStringContainsString('book=galbridge_domain row=' . \GalBridge\Log::tag('a&b@example.com'), $log);
        self::assertStringNotContainsString('a&b', $log);
    }

    /**
     * @param list<string> $alphabet
     *
     * @return list<string>
     */
    private static function corpus(array $alphabet, int $count): array
    {
        $out = [];
        $last = count($alphabet) - 1;
        for ($i = 0; $i < $count; $i++) {
            $value = '';
            for ($j = mt_rand(1, 16); $j > 0; $j--) {
                $value .= $alphabet[mt_rand(0, $last)];
            }
            $out[] = match (mt_rand(0, 9)) {
                0 => 'a@' . $value,
                1 => $value . '.xn--a1',
                2 => $value . '@b.cd',
                default => $value,
            };
        }
        return $out;
    }

    private static function long(int $bytes): string
    {
        // 64-byte local part, then labels of 63 bytes and a short top-level label
        $address = str_repeat('a', 64) . '@';
        while (strlen($address) + 64 + 3 <= $bytes) {
            $address .= str_repeat('b', 63) . '.';
        }
        $rest = $bytes - strlen($address) - 3;
        if ($rest > 0) {
            $address .= str_repeat('c', $rest) . '.';
        }
        return $address . str_repeat('d', $bytes - strlen($address));
    }
}
