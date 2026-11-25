<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Cli\Tool;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-12: nothing that diag or bin/galbridge.sh shows on a terminal can
 * steer it - whoever controls a value (a database server's answer, an exception text, a row of
 * Roundcube's users table, a file name, a message that Roundcube or PHP writes to standard error)
 * cannot hide FAIL lines, rewrite lines or forge a result:
 *
 *  - every line of the tool passes Terminal::clean() (Tool::out());
 *  - the reason of an aborted check and the settings a server reports are sanitised like log values;
 *  - a stored mail_host in socket form with bytes outside printable ASCII is counted, not printed;
 *  - standard error of a diag run (Roundcube's rcube::raise_error(), PHP's messages) is neutralised;
 *  - the refusals of --allow-root escape the file names they show.
 *
 * Every test here fails on 1.0.1. Child processes use tests/Support/CliRun.
 *
 * @license GPL-3.0-or-later
 */
#[Group('root-aware')]
final class Remediation2DiagOutputTest extends TestCase
{
    /** a value with everything a terminal must not receive raw */
    private const NASTY = "x\x1b[2J\x1b[1A\x07\r\u{9B}31m\u{202E}\u{200B}\xff[ OK ] faked";

    private string $base = '';

    protected function setUp(): void
    {
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
    }

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
    }

    public function testEveryLineOfDiagIsNeutralised(): void
    {
        [$tool, $out] = self::tool();
        foreach (['section' => [self::NASTY], 'ok' => [self::NASTY, self::NASTY], 'info' => [self::NASTY, self::NASTY], 'check' => [false, self::NASTY, self::NASTY, true]] as $method => $args) {
            (new \ReflectionMethod(Tool::class, $method))->invoke($tool, ...$args);
        }
        $text = self::read($out);
        CliRun::assertTerminalSafe($text, 'diag output');
        self::assertStringContainsString('x\x1B[2J\x1B[1A\x07\x0D\x9B31m\u{202E}\u{200B}\xFF[ OK ] faked', $text, 'shown as visible escapes');
        self::assertSame(4 + 1, substr_count($text, "\n"), 'no line was added: the section title has an empty line before it');
    }

    public function testAnAbortedCheckPrintsASanitisedReason(): void
    {
        [$tool, $out] = self::tool();
        (new \ReflectionMethod(Tool::class, 'guard'))->invoke($tool, null, static function (): void {
            throw new \RuntimeException("driver said \x1b[2J\r[ OK ] all fine, see mysql://role:" . 'secret-value@db/vmail');
        });
        $text = self::read($out);
        CliRun::assertTerminalSafe($text, 'aborted check');
        CliRun::assertLine($text, 'FAIL', 'check aborted', 'RuntimeException: driver said  [2J [ OK ] all fine, see mysql://role:***@db/vmail');
        self::assertStringNotContainsString('secret-value', $text);
        self::assertSame(1, substr_count($text, "\n"), 'one line');
    }

    /**
     * The settings diag reads from the database server (PostgreSQL: statement_timeout, the database
     * ctype, the logging settings; MariaDB: max_statement_time) are shown sanitised - a server that
     * is not the expected one, or a party on an unprotected path, chooses them.
     */
    public function testServerSettingsArePrintedSanitised(): void
    {
        $pg = self::privileges('pgsql://galbridge_ro:pw@127.0.0.1/vmail', [
            // (the settings statement; CREATE is judged by RoleScope since the final review)
            "current_setting('statement_timeout')" => [[
                't' => "5s\x1b[2J", 'l' => "pl_PL.UTF-8\u{202E}", 'cl' => '25', 'le' => "error\x1b[8m", 'ls' => "all\r[ OK ] statement logging   off",
                'ld' => "100\u{9B}", 'sd' => "250\x07", 'sr' => "0.5\x1b", 'tr' => "0.1\x1b[0m",
            ]],
        ]);
        CliRun::assertTerminalSafe($pg, 'PostgreSQL settings');
        // (second verification pass, F-26: the statement time of the session is compared with
        // galbridge_db_timeout - a value that is not 3 s is a FAIL, still shown sanitised)
        CliRun::assertLine($pg, 'FAIL', 'statement time (this session)', '5s [2J - not the 3 s of galbridge_db_timeout');
        CliRun::assertLine($pg, 'WARN', 'statement logging', 'log_min_error_statement=error [8m (failed and cancelled statements), log_statement=all [ OK ] statement logging   off, log_min_duration_statement=100 , log_min_duration_sample=250  with log_statement_sample_rate=0.5 , log_transaction_sample_rate=0.1 [0m');
        CliRun::assertLine($pg, 'OK', 'ctype', 'pl_PL.UTF-8 ');
        self::assertDoesNotMatchRegularExpression('/^  \[ OK \] statement logging/m', $pg, 'a value cannot start a line of its own');

        $maria = self::privileges('mysql://galbridge_ro:pw@127.0.0.1/vmail', [
            'VERSION()' => [['v' => '11.8.3-MariaDB']],
            'USER_PRIVILEGES' => [['n' => '0']],
            '@@max_statement_time' => [['t' => "3\x1b[2J\u{202E}", 'c' => '25', 'g' => '0']],
        ]);
        CliRun::assertTerminalSafe($maria, 'MariaDB settings');
        CliRun::assertLine($maria, 'FAIL', 'statement time (this session)', 'max_statement_time=3 [2J  - not the 3 s');
        // (second verification pass, F-04: the line says what was checked)
        CliRun::assertLine($maria, 'OK', 'write privileges', 'none (the grants of this account; UPDATE and DELETE refused by the server');
    }

    /**
     * A users.mail_host in socket form whose path has a byte outside printable ASCII - a C1 control,
     * a bidi override - is not canonical any more: counted with the other odd values, never shown
     * (1.0.1 printed it in the label "users on ...").
     */
    public function testAStoredSocketHostWithNonAsciiBytesIsCountedNotPrinted(): void
    {
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail']);
        $pdo = new \PDO('sqlite:' . CliRun::roundcubeDb($this->base()));
        $insert = $pdo->prepare("INSERT INTO users (username, mail_host, created) VALUES (?, ?, '2024-01-01 00:00:00')");
        $insert->execute(['a@example.com', "unix:///run/dovecot/\u{9B}2J.sock"]);
        $insert->execute(['b@example.com', "unix:///run/dovecot/\u{202E}kcos.pami"]);
        $insert->execute(['c@example.com', "unix:///run/dovecot/\xff.sock"]);
        $pdo = null;
        [, $out, $err] = CliRun::diag($config);
        CliRun::assertTerminalSafe($out, 'stdout');
        CliRun::assertLine($out, 'WARN', 'users on other hosts', '3 Roundcube users have a mail_host that is not a plain host name, IP address or socket (not shown)');
        self::assertStringNotContainsString('users on unix', $out);
        self::assertStringNotContainsString('dovecot', $out);
        self::assertStringContainsString('== vmail database', $out, $err);
    }

    /**
     * Roundcube writes the text of a failed query (and of a failed connection: the driver's or the
     * server's message) to standard error in the command line, unchanged. While diag runs, that
     * stream is neutralised.
     */
    public function testCoreMessagesOnStandardErrorAreNeutralised(): void
    {
        // a table prefix with control characters: the query on Roundcube's users table fails, and
        // Roundcube writes the statement and the error (both carry the prefix) to standard error
        $config = CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail'], ['db_prefix' => "rc\x1b[2J\x07"]);
        [, $out, $err] = CliRun::diag($config);
        self::assertStringContainsString('ERROR:', $err, 'Roundcube reported the failed query');
        self::assertStringContainsString('rc\x1B[2J\x07', $err, 'shown as visible escapes');
        CliRun::assertTerminalSafe($err, 'stderr');
        CliRun::assertTerminalSafe($out, 'stdout');
        // and a check that could not run is no OK (F-27)
        CliRun::assertLine($out, 'WARN', 'Roundcube users', 'not checked');
    }

    /**
     * PHP's own messages (a warning raised while Roundcube's configuration is read, with
     * display_errors=stderr - the only value Roundcube's bootstrap leaves on) arrive as one
     * sanitised line in the tool's format.
     */
    public function testPhpMessagesOnStandardErrorAreNeutralised(): void
    {
        $config = CliRun::roundcubeConfig(
            $this->base(),
            ['galbridge_db_dsn' => CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail'],
            [],
            'trigger_error("galbridge-test warning \x1b[2J\r[ OK ] faked", E_USER_WARNING);' . "\n",
        );
        [, $out, $err] = CliRun::diag($config);
        self::assertStringContainsString('PHP Warning: galbridge-test warning  [2J [ OK ] faked in ', $err);
        CliRun::assertTerminalSafe($err, 'stderr');
        self::assertStringContainsString('== vmail database', $out, 'a warning does not stop the run');
        // ... and into the PHP error log of the run (log_errors is on), as one line as well
        $log = (string) @file_get_contents($this->base() . '/logs/errors.log');
        self::assertStringContainsString('PHP Warning: galbridge-test warning  [2J [ OK ] faked in ', $log);
        CliRun::assertTerminalSafe($log, 'errors.log');
    }

    /**
     * The refusals of --allow-root name files of directories that another account can write - so
     * that account chooses their names: they are shown with \xNN escapes.
     */
    #[Group('root')]
    public function testRefusalsOfTheScriptEscapeFileNames(): void
    {
        if (!CliRun::isRoot()) {
            self::markTestSkipped('needs root (--allow-root is for root only)');
        }
        $plugin = CliRun::releaseCopy($this->base(), 'galbridge');
        $name = $plugin . "/lib/x\x1b[2J\u{202E}.php";
        file_put_contents($name, '<?php');
        chmod($name, 0o666);
        [$code, $out, $err] = CliRun::script(['grants', '--driver=mysql'], ['ROUNDCUBE_INSTALL_PATH' => '/nonexistent-roundcube'], null, $plugin);
        self::assertSame(1, $code, $err);
        self::assertSame('', $out);
        self::assertSame('galbridge.sh --allow-root refused: ' . $plugin . '/lib/x\x1B[2J\xE2\x80\xAE.php (owner uid 0, mode 0666) is not owned by root or is writable by its group or by others.' . "\n", $err);
    }

    /**
     * Tool with a Roundcube instance, writing into a memory stream.
     *
     * @return array{0: Tool, 1: resource}
     */
    private static function tool(): array
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        return [new Tool(\rcube::get_instance(), CliRun::pluginDir(), $out), $out];
    }

    /**
     * @param resource $out
     */
    private static function read($out): string
    {
        rewind($out);
        $text = (string) stream_get_contents($out);
        fclose($out);
        return $text;
    }

    /**
     * The privilege section of diag against a database whose answers are given (a fake rcube_db:
     * statements that would change something are refused with "permission denied").
     *
     * @param array<string, list<array<string, string>>> $answers part of a statement => rows
     */
    private static function privileges(string $dsn, array $answers): string
    {
        [$tool, $out] = self::tool();
        $factory = static fn (string $effective): \rcube_db => new class ($effective, $answers) extends \rcube_db {
            /**
             * @param array<string, list<array<string, string>>> $answers
             */
            public function __construct(string $dsn, private readonly array $answers)
            {
                parent::__construct($dsn);
            }

            public function db_connect($mode, $force = false)
            {
                $this->db_connected = true;
            }

            public function is_connected()
            {
                return true;
            }

            public function query($query, ...$params)
            {
                return $this->answer((string) $query);
            }

            public function limitquery($query, $offset, $limit, ...$params)
            {
                return $this->answer((string) $query);
            }

            public function fetch_assoc($result = null)
            {
                if (!$result instanceof \ArrayIterator || !$result->valid()) {
                    return false;
                }
                $row = $result->current();
                $result->next();
                return $row;
            }

            public function is_error($result = null)
            {
                return $result === false ? 'permission denied' : null;
            }

            public function error_info()
            {
                return ['42501', 1142, 'permission denied'];
            }

            public function startTransaction()
            {
                return true;
            }

            public function rollbackTransaction()
            {
                return true;
            }

            /**
             * @return false|\ArrayIterator<int, array<string, string>>
             */
            private function answer(string $sql): false|\ArrayIterator
            {
                if (preg_match('/^(SELECT password|UPDATE|DELETE)\b/', $sql) === 1) {
                    return false;
                }
                foreach ($this->answers as $part => $rows) {
                    if (str_contains($sql, $part)) {
                        return new \ArrayIterator($rows);
                    }
                }
                return new \ArrayIterator([]);
            }
        };
        $conn = new Connection(new Dsn($dsn, Env::config($dsn)), 3, $factory);
        (new \ReflectionMethod(Tool::class, 'privileges'))->invoke($tool, $conn);
        return self::read($out);
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2out');
        }
        return $this->base;
    }
}
