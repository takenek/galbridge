<?php

declare(strict_types=1);

/**
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Unit;

use GalBridge\Cli\Terminal;
use GalBridge\Cli\Tool;
use GalBridge\Db\Dsn;
use GalBridge\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-29 (logging): the password mask covers every form a DSN or a password
 * can take in a message - not only scheme://user:password@host without blanks -, every format
 * character is replaced, a context value with blanks cannot pose as further key=value pairs, and the
 * parameters that carry a secret are hidden from stack traces.
 *
 * Every test fails on 1.0.1 except testTextWithoutAPasswordIsUnchanged, the guard against a mask that
 * takes too much. Values are synthetic.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2LogTest extends TestCase
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
        Log::resetOnce();
        if (GALBRIDGE_RC_LOADED) {
            // exact lines: no Roundcube user is logged in
            $this->savedUser = \rcube::get_instance()->user;
            \rcube::get_instance()->user = null;
        }
    }

    protected function tearDown(): void
    {
        Log::resetOnce();
        Log::setSink(static function (string $line): void {
            $GLOBALS['galbridge_test_log'][] = $line;
        });
        if (GALBRIDGE_RC_LOADED) {
            \rcube::get_instance()->user = $this->savedUser;
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: list<string>}> text, the secret, what must stay
     */
    public static function secrets(): iterable
    {
        yield 'user with a slash' => ['connect failed: mysql://ro/le:Zq-secret-1@db.example/vmail', 'Zq-secret-1', ['mysql://ro/le:***@db.example/vmail']];
        yield 'password with a blank' => ['connect failed: pgsql://galbridge:Zq secret 2@127.0.0.1/vmail', 'secret 2', ['pgsql://galbridge:***@127.0.0.1/vmail']];
        yield 'password with a tab' => ["dsn mysql://galbridge:Zq\tsecret3@db/vmail", 'secret3', ['mysql://galbridge:***@db/vmail']];
        yield 'scheme(syntax) notation' => ['mysql(mysqli)://galbridge:Zq-secret-4@db/vmail', 'Zq-secret-4', ['mysql(mysqli)://galbridge:***@db/vmail']];
        yield 'PDO name=value' => ['pgsql:host=db;dbname=vmail;user=galbridge;password=Zq-pw5', 'Zq-pw5', ['user=galbridge;password=***']];
        yield 'libpq conninfo, quoted' => ["host=db user=galbridge password='Zq secret 6' dbname=vmail", 'secret 6', ['user=galbridge password=*** dbname=vmail']];
        yield 'libpq conninfo, double-quoted' => ['host=db password = "Zq \\" secret 7" dbname=vmail', 'secret 7', ['host=db password = *** dbname=vmail']];
        yield 'PGPASSWORD' => ['env PGPASSWORD=Zq-pw8 psql', 'Zq-pw8', ['PGPASSWORD=*** psql']];
        yield 'db_pass, upper case' => ['DB_PASS=Zq-secret-9&next=1', 'Zq-secret-9', ['DB_PASS=***&next=1']];
        yield 'JSON' => ['{"user":"galbridge","password":"Zq-pw10"}', 'Zq-pw10', ['"user":"galbridge","password":"***"']];
        yield 'two DSNs' => ['a=mysql://u:Zq-one@h1/d b=pgsql://v:Zq-two@h2/d', 'Zq-', ['a=mysql://u:***@h1/d b=pgsql://v:***@h2/d']];
        yield 'at sign and slash in the password' => ['mysql://galbridge:p@ss/w:rd Zq@127.0.0.1/vmail', 'ss/w', ['mysql://galbridge:***@127.0.0.1/vmail']];
    }

    #[DataProvider('secrets')]
    public function testDsnPasswordMaskCoversEveryForm(string $text, string $secret, array $kept): void
    {
        foreach ([Log::sanitize($text), Log::maskCredentials($text)] as $out) {
            self::assertStringNotContainsString($secret, $out);
            foreach ($kept as $part) {
                self::assertStringContainsString($part, $out);
            }
        }
        // the filter of bin/galbridge.sh on standard error uses the same mask
        self::assertStringNotContainsString($secret, Terminal::clean(Log::maskCredentials($text)));
    }

    /**
     * What has no password stays as it is.
     */
    public function testTextWithoutAPasswordIsUnchanged(): void
    {
        foreach ([
            'https://github.com/takenek/galbridge', 'mysql://galbridge@localhost/vmail', 'sqlite:////var/lib/x.db?mode=0600',
            'jan.kowalski@example.com', 'password column not readable', 'passphrase=kept', 'compass=north',
        ] as $text) {
            self::assertSame($text, Log::sanitize($text), $text);
        }
    }

    /**
     * A text the patterns cannot be applied to (a PCRE limit) is never returned unmasked.
     */
    public function testTextThePatternsCannotHandleIsRedactedAsAWhole(): void
    {
        $jit = (string) ini_get('pcre.jit');
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            self::assertSame('[REDACTED]', Log::maskCredentials('mysql://galbridge:Zq-secret-11@db/vmail ' . str_repeat('x', 200)));
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
    }

    public function testContextValuesWithBlanksOrEqualsAreQuoted(): void
    {
        Log::warning('x', 'y', ['allowed' => 'a b', 'k' => 'v=w', 'q' => 'say "hi"', 'b' => 'c:\\d', 'nb' => "a\u{00A0}b", 'n' => 'plain', 'e' => '', 'i' => 7]);
        self::assertSame(['WARN x: y allowed="a b" k="v=w" q="say \\"hi\\"" b="c:\\\\d" nb="a' . "\u{00A0}" . 'b" n=plain e= i=7'], $this->lines);
        // a key is a code constant: anything else in it cannot start a new pair either
        $this->lines = [];
        Log::info('x', 'y', ['bad key=1' => 'v']);
        self::assertSame(['INFO x: y bad_key_1=v'], $this->lines);
    }

    /**
     * Every format character (Unicode Cf) - not only the bidi and zero-width ones - is replaced.
     *
     * @return iterable<string, array{int}>
     */
    public static function formatCharacters(): iterable
    {
        foreach ([0x00AD, 0x0600, 0x070F, 0x110BD, 0xFFF9, 0xFFFB, 0x1D173, 0xE0001, 0xE0041, 0xE007F] as $cp) {
            yield sprintf('U+%04X', $cp) => [$cp];
        }
    }

    #[DataProvider('formatCharacters')]
    public function testEveryFormatCharacterIsReplaced(int $codePoint): void
    {
        self::assertSame('a b', Log::sanitize('a' . mb_chr($codePoint, 'UTF-8') . 'b'));
    }

    /**
     * A control character inside a password is replaced BEFORE the masks run, so it cannot end the
     * masked part early.
     */
    public function testControlCharactersCannotSplitTheMask(): void
    {
        $out = Log::sanitize("mysql://galbridge:Zq\npw-12\r\x1b@db/vmail");
        self::assertStringNotContainsString('pw-12', $out);
        self::assertSame('mysql://galbridge:***@db/vmail', $out);
    }

    /**
     * Parameters that carry a password or a DSN do not appear in a stack trace
     * (zend.exception_ignore_args may be off).
     *
     * @return iterable<string, array{0: class-string, 1: string, 2: list<string>}>
     */
    public static function sensitiveParameters(): iterable
    {
        yield 'Log::sanitize' => [Log::class, 'sanitize', ['value']];
        yield 'Log::maskCredentials' => [Log::class, 'maskCredentials', ['s']];
        yield 'Dsn::__construct' => [Dsn::class, '__construct', ['dsn', 'roundcubeDsn', 'passwordPluginDsn']];
        yield 'Dsn::checkParameters' => [Dsn::class, 'checkParameters', ['dsn']];
        yield 'Dsn::checkTarget' => [Dsn::class, 'checkTarget', ['parsed']];
        yield 'Dsn::isLoopback' => [Dsn::class, 'isLoopback', ['parsed']];
        yield 'Dsn::verifiedTls' => [Dsn::class, 'verifiedTls', ['parsed']];
        yield 'Tool::roleStatements' => [Tool::class, 'roleStatements', ['secret']];
        yield 'Tool::mysqlVerifier' => [Tool::class, 'mysqlVerifier', ['password']];
        yield 'Tool::scramVerifier' => [Tool::class, 'scramVerifier', ['password']];
        yield 'Tool::out (the DSN line of grants)' => [Tool::class, 'out', ['s']];
        yield 'Terminal::clean (the DSN line of grants)' => [Terminal::class, 'clean', ['s']];
    }

    /**
     * @param class-string $class
     * @param list<string> $names
     */
    #[DataProvider('sensitiveParameters')]
    public function testSecretParametersAreMarkedSensitive(string $class, string $method, array $names): void
    {
        $marked = [];
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
            if ($parameter->getAttributes('SensitiveParameter') !== []) {
                $marked[] = $parameter->getName();
            }
        }
        self::assertSame($names, array_values(array_intersect($names, $marked)), $class . '::' . $method);
    }
}
