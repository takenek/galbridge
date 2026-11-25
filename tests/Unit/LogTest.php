<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The redacting logger (docs/ARCHITECTURE.md section 14): one line per entry whatever the input
 * (log injection), no control / bidi / zero-width characters (display spoofing), no DSN password,
 * no invalid UTF-8, bounded values, every reason code at most once per request.
 */
#[CoversClass(Log::class)]
final class LogTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    private mixed $savedUser = null;

    protected function setUp(): void
    {
        $this->lines = [];
        Log::setSink(function (string $line): void {
            $this->lines[] = $line;
        });
        Log::setDebug(false);
        Log::resetOnce();
        if (GALBRIDGE_RC_LOADED) {
            // exact-line assertions: no Roundcube user is logged in during unit tests
            $this->savedUser = \rcube::get_instance()->user;
            \rcube::get_instance()->user = null;
        }
    }

    protected function tearDown(): void
    {
        Log::setDebug(false);
        Log::resetOnce();
        // restore the bootstrap sink
        Log::setSink(static function (string $line): void {
            $GLOBALS['galbridge_test_log'][] = $line;
        });
        if (GALBRIDGE_RC_LOADED) {
            \rcube::get_instance()->user = $this->savedUser;
        }
    }

    // ------------------------------------------------------------------ sanitize(): types

    public function testScalars(): void
    {
        self::assertSame('true', Log::sanitize(true));
        self::assertSame('false', Log::sanitize(false));
        self::assertSame('null', Log::sanitize(null));
        self::assertSame('42', Log::sanitize(42));
        self::assertSame('-7', Log::sanitize(-7));
        self::assertSame('1.5', Log::sanitize(1.5));
        self::assertSame('', Log::sanitize(''));
        self::assertSame('plain text', Log::sanitize('plain text'));
        self::assertSame('Zażółć gęślą jaźń', Log::sanitize('Zażółć gęślą jaźń'));
        self::assertSame('stdClass', Log::sanitize(new \stdClass()));
        self::assertSame('Closure', Log::sanitize(static fn () => 1));
    }

    public function testArrays(): void
    {
        self::assertSame('[]', Log::sanitize([]));
        self::assertSame('[a, b]', Log::sanitize(['a', 'b']));
        self::assertSame('[k=v, n=1, t=true, z=null]', Log::sanitize(['k' => 'v', 'n' => 1, 't' => true, 'z' => null]));
        self::assertSame('[nested=array, obj=stdClass]', Log::sanitize(['nested' => ['secret' => 'x'], 'obj' => new \stdClass()]));
        self::assertSame('[ke y=line break]', Log::sanitize(["ke\ny" => "line\nbreak"]));
        self::assertSame('[dsn=mysql://u:***@h/d]', Log::sanitize(['dsn' => 'mysql://u:pw@h/d']));
        self::assertSame('[bin=[BINARY-REDACTED]]', Log::sanitize(['bin' => "\xFF\xFE"]));

        self::assertSame('[' . implode(', ', range(1, 20)) . ', ...]', Log::sanitize(range(1, 50)));
        self::assertSame('[' . implode(', ', range(1, 20)) . ', ...]', Log::sanitize(range(1, 21)));
        self::assertSame('[' . implode(', ', range(1, 19)) . ']', Log::sanitize(range(1, 19)));
    }

    /**
     * '...' says "values were left out"; an array of exactly 20 values is logged completely and must
     * not look truncated.
     */
    public function testArrayTruncationMarkerOnlyWhenSomethingWasLeftOut(): void
    {
        self::assertSame('[' . implode(', ', range(1, 20)) . ']', Log::sanitize(range(1, 20)));
    }

    // ------------------------------------------------------------------ sanitize(): DSN passwords

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function dsnCases(): iterable
    {
        yield 'mysql' => ['mysql://galbridge:s3cret@localhost/vmail', 'mysql://galbridge:***@localhost/vmail'];
        yield 'pgsql test dsn' => ['pgsql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test', 'pgsql://galbridge_ro:***@127.0.0.1/galbridge_vmail_test'];
        yield 'mysqli with port and options' => ['mysqli://u:p4ss@127.0.0.1:3306/vmail?timeout=2', 'mysqli://u:***@127.0.0.1:3306/vmail?timeout=2'];
        yield 'upper-case scheme' => ['PGSQL://User:Secret@db.example/vmail', 'PGSQL://User:***@db.example/vmail'];
        yield 'empty user' => ['mysql://:secret@localhost/vmail', 'mysql://:***@localhost/vmail'];
        yield 'empty password' => ['mysql://u:@localhost/vmail', 'mysql://u:***@localhost/vmail'];
        yield 'colon in password' => ['mysql://u:pa:ss@localhost/vmail', 'mysql://u:***@localhost/vmail'];
        yield 'percent-encoded password' => ['mysql://u:p%40ss%2Fx@localhost/vmail', 'mysql://u:***@localhost/vmail'];
        yield 'inside a message' => ['connect failed (mysql://u:pw@localhost/vmail), retry', 'connect failed (mysql://u:***@localhost/vmail), retry'];
        yield 'two dsns' => ['a=mysql://u:one@h1/d b=pgsql://v:two@h2/d', 'a=mysql://u:***@h1/d b=pgsql://v:***@h2/d'];
        yield 'no password' => ['mysql://galbridge@localhost/vmail', 'mysql://galbridge@localhost/vmail'];
        yield 'no credentials' => ['sqlite:////var/lib/galbridge/x.db?mode=0600', 'sqlite:////var/lib/galbridge/x.db?mode=0600'];
        yield 'plain url' => ['https://github.com/takenek/galbridge', 'https://github.com/takenek/galbridge'];
        yield 'address is not a dsn' => ['jan.kowalski@firma.pl', 'jan.kowalski@firma.pl'];
    }

    #[DataProvider('dsnCases')]
    public function testDsnPasswordIsRedacted(string $in, string $expected): void
    {
        self::assertSame($expected, Log::sanitize($in));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function dsnPasswordsWithSeparators(): iterable
    {
        // rcube_db::parse_dsn() takes the password up to the LAST '@' and does not require encoding,
        // so these are working galbridge_db_dsn values
        yield 'at sign in password' => ['mysql://galbridge:p@ssword@localhost/vmail', 'p@ssword', 'ssword'];
        yield 'slash in password' => ['mysql://galbridge:pass/word@localhost/vmail', 'pass/word', 'word@'];
        yield 'at sign and slash' => ['pgsql://galbridge:p@ss/w:rd@127.0.0.1/vmail', 'p@ss/w:rd', 'ss/w:rd'];
    }

    /**
     * "never log a password, even by accident" must also hold for the DSNs Roundcube itself accepts.
     */
    #[DataProvider('dsnPasswordsWithSeparators')]
    public function testDsnPasswordWithSeparatorsIsRedacted(string $dsn, string $password, string $fragment): void
    {
        $out = Log::sanitize('connect failed: ' . $dsn);
        self::assertStringNotContainsString($password, $out);
        self::assertStringNotContainsString($fragment, $out);
        self::assertStringContainsString('***', $out);
    }

    // ------------------------------------------------------------------ sanitize(): characters

    public function testInvalidUtf8IsRedacted(): void
    {
        self::assertSame('[BINARY-REDACTED]', Log::sanitize("\xFF\xFE\x00raw"));
        self::assertSame('[BINARY-REDACTED]', Log::sanitize("Jan\xC5"));
        self::assertSame('[BINARY-REDACTED]', Log::sanitize("\xC0\xAE"), 'overlong encoding');
        self::assertSame('[BINARY-REDACTED]', Log::sanitize("\xED\xA0\x80"), 'surrogate');
        self::assertSame('[BINARY-REDACTED]', Log::sanitize(random_bytes(64) . "\xC3"));
        self::assertSame('[BINARY-REDACTED]', Log::sanitize("mysql://u:secret@h/d \xFF"), 'nothing of the value survives');
    }

    public function testControlCharactersReplaced(): void
    {
        $out = Log::sanitize("a\rb\nc\td\x1b[31me\x00f\x7fg\x0Bh\x0Ci");
        self::assertSame('a b c d [31me f g h i', $out);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $out);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invisibleCharacters(): iterable
    {
        $set = [
            // line breaks outside ASCII
            0x0085, 0x2028, 0x2029,
            // C1 controls (U+009B is the 8-bit CSI terminal escape)
            0x0080, 0x008D, 0x009B, 0x009F,
            // bidi controls (Unicode Bidi_Control)
            0x061C, 0x200E, 0x200F, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2066, 0x2067, 0x2068, 0x2069,
            // zero-width
            0x200B, 0x200C, 0x200D, 0x2060, 0xFEFF,
        ];
        foreach ($set as $cp) {
            yield sprintf('U+%04X', $cp) => [$cp];
        }
    }

    #[DataProvider('invisibleCharacters')]
    public function testInvisibleCharactersReplaced(int $codePoint): void
    {
        $char = (string) mb_chr($codePoint, 'UTF-8');
        self::assertSame('user a b', Log::sanitize('user a' . $char . 'b'));
    }

    public function testBidiSpoofingIsNeutralised(): void
    {
        // "denied_source for evil" displayed reversed would read as an innocent line
        $out = Log::sanitize("book=\u{202E}niamod_egdirblag\u{202C} ok");
        self::assertSame('book= niamod_egdirblag  ok', $out);
    }

    public function testTruncation(): void
    {
        $long = str_repeat('word ', 200);
        $out = Log::sanitize($long);
        self::assertSame(303, mb_strlen($out));
        self::assertSame(substr($long, 0, 300) . '...', $out);

        // multi-byte: counted in characters, never cut inside a UTF-8 sequence
        $out = Log::sanitize(str_repeat('ż', 400));
        self::assertSame(str_repeat('ż', 300) . '...', $out);
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));

        $exact = str_repeat('ab ', 100);
        self::assertSame($exact, Log::sanitize($exact), 'exactly 300 characters are kept');
        self::assertSame(str_repeat('x', 300) . '...', Log::sanitize(str_repeat('x', 301)));
    }

    public function testTruncationAppliesToEachArrayValue(): void
    {
        $out = Log::sanitize(['q' => str_repeat('a', 1000)]);
        self::assertSame('[q=' . str_repeat('a', 300) . '...]', $out);
    }

    // ------------------------------------------------------------------ writers

    public function testLevelsAndContext(): void
    {
        Log::error('db', 'query failed', ['sqlstate' => '42000', 'ok' => false, 'n' => null]);
        Log::warning('groups', 'too many groups', ['book' => 'galbridge_domain', 'count' => 51]);
        Log::info('scope', 'viewer resolved', ['user' => 12]);
        self::assertSame([
            'ERROR db: query failed sqlstate=42000 ok=false n=null',
            'WARN groups: too many groups book=galbridge_domain count=51',
            'INFO scope: viewer resolved user=12',
        ], $this->lines);
    }

    public function testLogInjectionStaysOneLine(): void
    {
        Log::error("search\nINFO forged", "failed\nERROR galbridge: FAKE ENTRY\r\nmore", [
            "ke\ny" => "va\r\nlue\nFAKE ENTRY",
            'user' => 7,
        ]);
        self::assertCount(1, $this->lines);
        $line = $this->lines[0];
        self::assertStringNotContainsString("\n", $line);
        self::assertStringNotContainsString("\r", $line);
        // a context value with blanks is quoted and a key is a code constant (second verification
        // pass, F-29): the forged text cannot pose as further key=value pairs
        self::assertSame('ERROR search INFO forged: failed ERROR galbridge: FAKE ENTRY  more ke_y="va  lue FAKE ENTRY" user=7', $line);
    }

    public function testContextValuesAreSanitized(): void
    {
        Log::warning('db', 'connect failed', [
            'dsn' => 'pgsql://galbridge:hunter2@db.example/vmail',
            'raw' => "\xFF\xFE",
            'term' => "jan\u{202E}x",
            'list' => ['a', "b\nc"],
        ]);
        self::assertSame('WARN db: connect failed dsn=pgsql://galbridge:***@db.example/vmail raw=[BINARY-REDACTED] term="jan x" list="[a, b c]"', $this->lines[0]);
        self::assertStringNotContainsString('hunter2', $this->lines[0]);
    }

    public function testDebugOnlyWhenEnabled(): void
    {
        self::assertFalse(Log::isDebug());
        Log::debug('search', 'hidden', ['term' => 'jan']);
        self::assertSame([], $this->lines);

        Log::setDebug(true);
        self::assertTrue(Log::isDebug());
        Log::debug('search', 'shown', ['term' => "jan\nkowalski"]);
        self::assertSame(['DEBUG search: shown term="jan kowalski"'], $this->lines);

        Log::setDebug(false);
        Log::debug('search', 'hidden again');
        self::assertCount(1, $this->lines);
    }

    public function testExceptionDetailsRequireDebugLogging(): void
    {
        $e = new \RuntimeException('SQLSTATE[28000] access denied for galbridge@localhost (dsn mysql://galbridge:hunter2@localhost/vmail)');
        Log::exception('db', $e, ['book' => 'galbridge_domain']);
        self::assertSame(['ERROR db: unexpected error exception=RuntimeException book=galbridge_domain'], $this->lines);

        Log::setDebug(true);
        Log::exception('db', $e, ['book' => 'galbridge_domain']);
        self::assertCount(3, $this->lines);
        self::assertSame('ERROR db: unexpected error exception=RuntimeException book=galbridge_domain', $this->lines[1]);
        self::assertStringStartsWith('DEBUG db: SQLSTATE[28000] access denied', $this->lines[2]);
        self::assertStringContainsString('mysql://galbridge:***@localhost/vmail', $this->lines[2]);
        self::assertStringNotContainsString('hunter2', $this->lines[2]);
        self::assertStringEndsWith('exception=RuntimeException book=galbridge_domain', $this->lines[2]);
    }

    // ------------------------------------------------------------------ once()

    public function testOnceLogsAReasonCodeOncePerRequest(): void
    {
        Log::once('warning', 'records', 'invalid_id', 'invalid contact id', ['book' => 'galbridge_domain']);
        Log::once('warning', 'records', 'invalid_id', 'invalid contact id', ['book' => 'galbridge_domain']);
        Log::once('error', 'records', 'invalid_id', 'a different message', ['book' => 'galbridge_global']);
        self::assertSame(['WARN records: invalid_id: invalid contact id book=galbridge_domain'], $this->lines);
    }

    public function testOnceKeysOnOperationAndCode(): void
    {
        Log::once('warning', 'records', 'invalid_id', 'm1');
        Log::once('warning', 'groups', 'invalid_id', 'm2');      // other operation
        Log::once('warning', 'records', 'too_many_ids', 'm3');   // other code
        Log::once('warning', 'records', 'invalid_id', 'm4');     // repeat
        self::assertSame([
            'WARN records: invalid_id: m1',
            'WARN groups: invalid_id: m2',
            'WARN records: too_many_ids: m3',
        ], $this->lines);
    }

    public function testOnceLevels(): void
    {
        Log::once('error', 'db', 'db_down', 'm');
        Log::once('warning', 'db', 'db_slow', 'm');
        Log::once('info', 'book', 'denied_source', 'm');
        self::assertSame([
            'ERROR db: db_down: m',
            'WARN db: db_slow: m',
            'INFO book: denied_source: m',
        ], $this->lines);
    }

    public function testOnceCannotBeFlooded(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            Log::once('warning', 'scope', 'row_dropped', 'row outside the book scope dropped', ['n' => $i]);
        }
        self::assertSame(['WARN scope: row_dropped: row outside the book scope dropped n=0'], $this->lines);
    }

    public function testOnceIsSanitized(): void
    {
        Log::once('warning', "op\nINFO x", "code\r\nERROR y", "msg\nWARN z", ['k' => "v\nw"]);
        self::assertCount(1, $this->lines);
        self::assertSame('WARN op INFO x: code  ERROR y: msg WARN z k="v w"', $this->lines[0]);
    }

    public function testResetOnce(): void
    {
        Log::once('error', 'db', 'db_error', 'first');
        Log::once('error', 'db', 'db_error', 'suppressed');
        Log::resetOnce();
        Log::once('error', 'db', 'db_error', 'after reset');
        self::assertSame(['ERROR db: db_error: first', 'ERROR db: db_error: after reset'], $this->lines);
    }

    public function testOnceDoesNotDependOnDebug(): void
    {
        Log::setDebug(true);
        Log::once('info', 'book', 'denied_source', 'm');
        Log::setDebug(false);
        Log::once('info', 'book', 'denied_source', 'm');
        self::assertSame(['INFO book: denied_source: m'], $this->lines);
    }

    // ------------------------------------------------------------------ tag()

    public function testTag(): void
    {
        $id = 'u_janDkowalskiAfirmaDpl';
        $tag = Log::tag($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', $tag);
        self::assertSame($tag, Log::tag($id), 'deterministic: repeated attempts correlate');
        self::assertSame(substr(hash('sha256', $id), 0, 12), $tag);
        self::assertNotSame($tag, Log::tag('u_ewaAfirmaDpl'));
        self::assertNotSame($tag, Log::tag($id . ' '));
        self::assertStringNotContainsString('jan', $tag);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', Log::tag(''));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', Log::tag("\xFF\n" . str_repeat('x', 10000)));
    }

    public function testTagIsSafeInALogLine(): void
    {
        Log::once('warning', 'groups', 'invalid_gid', 'invalid or foreign group id', ['tag' => Log::tag("dp_x\nERROR forged")]);
        self::assertCount(1, $this->lines);
        self::assertMatchesRegularExpression('/^WARN groups: invalid_gid: invalid or foreign group id tag=[0-9a-f]{12}$/D', $this->lines[0]);
    }

    // ------------------------------------------------------------------ outputs

    public function testUserContextAddedFromRoundcube(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube (GALBRIDGE_RC)');
        }
        $rc = \rcube::get_instance();
        $user = new \stdClass();
        $user->ID = 42;
        $rc->user = $user;

        Log::error('db', 'failed', ['book' => 'x']);
        Log::error('db', 'explicit', ['user' => 7]);
        $rc->user = null;
        Log::error('db', 'anonymous');

        self::assertSame([
            'ERROR db: failed user=42 book=x',
            'ERROR db: explicit user=7',
            'ERROR db: anonymous',
        ], $this->lines);
    }

    public function testSinkNullFallsBackWithoutCrashingTheCaller(): void
    {
        // without a sink the line goes to Roundcube's log (file driver) or, without Roundcube, to error_log
        $dir = sys_get_temp_dir() . '/galbridge-logtest-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        $oldErrorLog = ini_set('error_log', $dir . '/php-error.log');
        $saved = null;
        if (GALBRIDGE_RC_LOADED) {
            $cfg = \rcube::get_instance()->config;
            $saved = [$cfg->get('log_driver'), $cfg->get('log_dir'), $cfg->get('per_user_logging')];
            $cfg->set('log_driver', 'file');
            $cfg->set('log_dir', $dir);
            $cfg->set('per_user_logging', false);
        }
        try {
            Log::setSink(null);
            Log::error('db', "fallback\nline", ['dsn' => 'mysql://u:hunter2@h/d']);
        } finally {
            ini_set('error_log', $oldErrorLog === false ? '' : $oldErrorLog);
            if ($saved !== null) {
                $cfg = \rcube::get_instance()->config;
                $cfg->set('log_driver', $saved[0]);
                $cfg->set('log_dir', $saved[1]);
                $cfg->set('per_user_logging', $saved[2]);
            }
        }
        $written = '';
        foreach (glob($dir . '/*') ?: [] as $f) {
            $written .= (string) file_get_contents($f);
            unlink($f);
        }
        rmdir($dir);
        self::assertStringContainsString('ERROR db: fallback line dsn=mysql://u:***@h/d', $written);
        self::assertStringNotContainsString("fallback\nline", $written);
        self::assertStringNotContainsString('hunter2', $written);
        self::assertSame([], $this->lines, 'the replaced sink is no longer called');
    }
}
