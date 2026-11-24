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
use PHPUnit\Framework\TestCase;

/**
 * ext-intl is only suggested (composer.json), so the plugin must also behave on PHP without it
 * (docs/ARCHITECTURE.md section 13): ASCII input normalises as usual, non-ASCII DOMAINS cannot be
 * converted to A-labels and therefore fail (null) instead of being compared in a form vmail never
 * stores. The code runs in a child PHP started with -n (no ini files) and only mbstring loaded.
 */
#[CoversClass(Normalizer::class)]
final class NormalizerWithoutIntlTest extends TestCase
{
    public function testAsciiWorksAndIdnFailsClosedWithoutIntl(): void
    {
        $r = self::runWithoutIntl(<<<'PHP'
            use GalBridge\Normalizer;
            use GalBridge\Directory\IdCodec;
            echo json_encode([
                'intl' => function_exists('idn_to_ascii') || class_exists('Normalizer', false),
                'domain_ascii' => Normalizer::domain(' Example.COM. '),
                'domain_alabel' => Normalizer::domain('XN--W-UGA1V8H.pl'),
                'domain_idn' => Normalizer::domain('żółw.pl'),
                'domain_idn_upper' => Normalizer::domain('ŻÓŁW.PL'),
                'address_ascii' => Normalizer::address('Jan.Kowalski@Firma.PL'),
                'address_idn' => Normalizer::address('jan@żółw.pl'),
                'address_alabel' => Normalizer::address('jan@xn--w-uga1v8h.pl'),
                'list_strict' => Normalizer::list(['firma.pl', 'żółw.pl'], [Normalizer::class, 'domain'], true),
                'list_lenient' => Normalizer::list(['firma.pl', 'żółw.pl'], [Normalizer::class, 'domain'], false),
                'nfc' => bin2hex(Normalizer::nfc("e\u{0301}")),
                'display' => Normalizer::displayText("Jan\u{202E} \"K\" <x>\r\n"),
                'id' => IdCodec::decodeContact('u_janAxnHHwHuga1v8hDpl'),
            ]);
            PHP);

        self::assertFalse($r['intl'], 'the child really runs without ext-intl');
        self::assertSame('example.com', $r['domain_ascii']);
        self::assertSame('xn--w-uga1v8h.pl', $r['domain_alabel']);
        self::assertNull($r['domain_idn'], 'a U-label cannot be converted without intl: fail, never a guess');
        self::assertNull($r['domain_idn_upper']);
        self::assertSame('jan.kowalski@firma.pl', $r['address_ascii']);
        self::assertNull($r['address_idn']);
        self::assertSame('jan@xn--w-uga1v8h.pl', $r['address_alabel']);
        self::assertNull($r['list_strict'], 'a deny-list with an IDN entry fails closed');
        self::assertSame(['firma.pl'], $r['list_lenient'], 'an allow-list drops the IDN entry');
        self::assertSame(bin2hex("e\u{0301}"), $r['nfc'], 'without intl NFC is a no-op');
        self::assertSame('Jan K x', $r['display'], 'stripping does not depend on intl');
        self::assertSame(['kind' => 'u', 'address' => 'jan@xn--w-uga1v8h.pl'], $r['id']);
    }

    /**
     * Run $code (with lib/autoload.php loaded) in "php -n -d extension=mbstring" and decode its JSON
     * output. Skips only when such a child cannot be set up (no proc_open, intl compiled in
     * statically, mbstring unavailable); once it can, any failure of $code fails the test.
     *
     * @return array<string, mixed>
     */
    private static function runWithoutIntl(string $code): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled');
        }
        $env = self::child("echo json_encode(['intl' => function_exists('idn_to_ascii') || class_exists('Normalizer', false), 'mb' => function_exists('mb_strtolower')]);");
        $probe = json_decode($env[0], true);
        if (!is_array($probe) || ($probe['mb'] ?? false) !== true) {
            self::markTestSkipped('cannot start a child PHP with mbstring only: ' . trim(substr($env[1], 0, 200)));
        }
        if (($probe['intl'] ?? true) !== false) {
            self::markTestSkipped('ext-intl cannot be disabled in this PHP build');
        }

        [$out, $err, $status] = self::child($code);
        $result = json_decode($out, true);
        if ($status !== 0 || !is_array($result)) {
            self::fail('child PHP without intl failed (exit ' . $status . '): ' . $out . ' ' . $err);
        }
        return $result;
    }

    /**
     * @return array{0: string, 1: string, 2: int} stdout, stderr, exit status
     */
    private static function child(string $code): array
    {
        $file = tempnam(sys_get_temp_dir(), 'galbridge-nointl-');
        self::assertIsString($file);
        $autoload = dirname(__DIR__, 2) . '/lib/autoload.php';
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoload, true) . ";\n" . $code . "\n");
        try {
            $proc = proc_open(
                [PHP_BINARY, '-n', '-d', 'extension=mbstring', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                self::markTestSkipped('cannot start a child PHP');
            }
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);
        } finally {
            unlink($file);
        }
        return [$out, $err, $status];
    }
}
