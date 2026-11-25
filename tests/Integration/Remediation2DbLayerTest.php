<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Cli\Tool;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\FieldMap;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\QueryBuilder;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-26: the database layer behaves as documented.
 *
 *  (a)/(b) rcube_db inlines every bound value into the statement text (PDO::quote()) and PDO scans
 *          that text again: a backslash makes PDO's scanner and the server see different literals,
 *          and libpq ends a quoted value at a NUL byte (a pattern shorter than the length rules
 *          checked). Search words with a control character or a backslash are not searched, a
 *          department with one is not offered as a group (a forged group ID matches nothing), and
 *          Connection::select() never sends a value with a NUL byte or a backslash;
 *  (c)     a server that refuses the plugin's statement timeout or read-only mode is not used (1.0.1:
 *          a log warning, and on MySQL no statement bound at all); diag shows the statement time of
 *          the plugin's own session and compares it with galbridge_db_timeout;
 *  (d)     expiry is judged by the database clock (session time zone) AND by UTC, whichever comes
 *          first (1.0.1: UTC only);
 *  (e)     a required birthday means a real date ("m.birthday <> ''" failed on PostgreSQL and counted
 *          year 0001 as a birthday elsewhere).
 *
 * Runs on the SQLite mirror and, when configured, on MariaDB/MySQL and PostgreSQL.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2DbLayerTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';
    private const ANNA = 'anna.nowak@example.com';
    private const LUKASZ = 'lukasz.slowik@example.com';

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function serverEngines(): array
    {
        $out = array_filter(Env::engines(), static fn (string $engine): bool => $engine !== 'sqlite', ARRAY_FILTER_USE_KEY);
        return $out !== [] ? $out : ['no server engine configured' => ['']];
    }

    // ------------------------------------------------------------------ (a)/(b) bound values

    /**
     * @return array<string, array{string}>
     */
    public static function unsearchable(): array
    {
        return [
            'NUL (libpq would cut the pattern here)' => ["kowalski\x00"],
            'a C0 control' => ["kowa\x1flski"],
            'DEL' => ["kowalski\x7f"],
            'a C1 control' => ["kowalski\u{9b}"],
            'a trailing backslash' => ['kowalski\\'],
            'a backslash before an apostrophe and a second word with a colon' => ["kowalski\\' x:ab"],
        ];
    }

    #[DataProvider('engines')]
    public function testSearchWordsWithControlCharactersOrABackslashMatchNothing(string $dsn): void
    {
        $config = Env::config($dsn);
        $factory = Env::factory($config, self::JAN);
        $viewer = $factory->resolver()->resolve();
        self::assertNotNull($viewer);
        $queries = QueryBuilder::forBook($config, (new Dsn($dsn, $config))->driver(), $factory->books()[BookDefinition::DOMAIN], $viewer, FieldMap::fromConfig($config, $viewer));
        self::assertNotNull($queries->words('kowalski', 0), 'baseline');
        foreach (self::unsearchable() as $label => [$value]) {
            self::assertNull($queries->words($value, 0), $label);
            self::assertNull($queries->words($value, \rcube_addressbook::SEARCH_STRICT), $label);
        }
        // tab and newline are whitespace: collapsed into a blank, still searched
        self::assertNotNull($queries->words("jan\tkowalski\n", 0));

        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(50);
        self::assertGreaterThan(0, $book->search('*', 'kowalski')->count, 'the plain word finds the person');
        foreach (self::unsearchable() as $label => [$value]) {
            self::assertSame(0, $book->search('*', $value)->count, 'quick: ' . $label);
            self::assertSame(0, $book->search(['name', 'email'], [$value, ''])->count, 'advanced: ' . $label);
        }
        self::assertStringNotContainsString('db_error', self::log());
    }

    public function testABoundValueWithANulByteOrABackslashIsNeverSent(): void
    {
        $dsn = Env::sqliteDsn();
        /** @var \ArrayObject<int, string> $sent */
        $sent = new \ArrayObject();
        $factory = static fn (string $effective): \rcube_db => new class ($effective, $sent) extends \rcube_db_sqlite {
            /**
             * @param \ArrayObject<int, string> $sent
             */
            public function __construct(string $dsn, private readonly \ArrayObject $sent)
            {
                parent::__construct($dsn, '', false);
            }

            public function query($query, ...$params)
            {
                $this->sent[] = (string) $query;
                return parent::query($query, ...$params);
            }
        };
        $connection = new Connection(new Dsn($dsn, Env::config($dsn)), 3, $factory);
        foreach (["a\x00", 'a\\', "x\\' OR 1=1 --"] as $value) {
            try {
                $connection->select('SELECT 1 AS n WHERE ? = ?', [$value, 'b']);
                self::fail('a value with a NUL byte or a backslash was bound');
            } catch (GalBridgeException $e) {
                self::assertSame('db_error', $e->getReason());
            }
        }
        self::assertSame([], $sent->getArrayCopy(), 'nothing was sent (not even a connection was needed)');
        self::assertStringContainsString('unsafe_parameter', self::log());
        self::assertArrayNotHasKey('galbridge_down_until', $_SESSION, 'a refused value is no outage');
        // ordinary values (quotes, LIKE escapes, non-ASCII) are bound as before
        self::assertSame([['n' => '1']], $connection->select('SELECT 1 AS n WHERE ? = ?', ["o'brien !% żółw", "o'brien !% żółw"]));
        self::assertSame(['PRAGMA query_only = 1', 'SELECT 1 AS n WHERE ? = ?'], $sent->getArrayCopy());
    }

    #[DataProvider('engines')]
    public function testDepartmentGroupsWithUnusableKeysAreNotOffered(string $dsn): void
    {
        [$apply, $revert] = Fixture::bulkMailboxes(1, 'example.com', 'Placeholder', 'gbbs');
        $backslash = match (true) {
            str_starts_with($dsn, 'mysql') => "CONCAT('R', CHAR(92 USING utf8mb4), 'D')",
            str_starts_with($dsn, 'pgsql') => "'R' || CHR(92) || 'D'",
            default => "'R' || CHAR(92) || 'D'",
        };
        // never a backslash in the SQL text itself (MySQL would read it as an escape)
        Fixture::alter($dsn, [...$apply, "UPDATE mailbox SET department = {$backslash} WHERE username = 'gbbs001@example.com'"], $revert);

        $book = Env::factory(Env::config($dsn), self::JAN)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $groups = $book->list_groups();
        self::assertNotSame([], $groups, 'the other departments are offered');
        foreach ($groups as $group) {
            self::assertStringNotContainsString('\\', (string) $group['name']);
            self::assertStringNotContainsString('X5C', (string) $group['ID'], 'no group ID carries an encoded backslash');
        }
        self::assertStringContainsString('group_key_unusable', self::log());
        $book->set_pagesize(200);
        self::assertContains('gbbs001@example.com', self::emails($book->list_records()), 'the member stays in the book');

        // a forged group ID with the key: matches nothing, nothing is bound
        $book->set_group(IdCodec::groupId('d', IdCodec::GROUP_DEPARTMENT, 'r\\d'));
        self::assertSame(0, $book->list_records()->count);
        self::assertStringContainsString('invalid_gid', self::log());
        self::assertStringNotContainsString('db_error', self::log());
    }

    // ------------------------------------------------------------------ (c) session limits

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedSettings(): array
    {
        $out = [];
        foreach (Env::engines() as $engine => [$dsn]) {
            foreach (match ($engine) {
                'mysql' => ['statement timeout' => '_time =', 'read-only mode' => 'READ ONLY'],
                'pgsql' => ['statement timeout' => 'statement_timeout', 'read-only mode' => 'default_transaction_read_only'],
                default => ['read-only mode' => 'query_only'],
            } as $what => $needle) {
                $out[$engine . ': ' . $what] = [$dsn, $needle];
            }
        }
        return $out;
    }

    #[DataProvider('refusedSettings')]
    public function testRefusedSessionSettingsFailClosed(string $dsn, string $needle): void
    {
        $created = null;
        $factory = static function (string $effective) use ($dsn, $needle, &$created): \rcube_db {
            return $created = self::refusing($dsn, $effective, $needle);
        };
        $connection = new Connection(new Dsn($dsn, Env::config($dsn)), 3, $factory);
        try {
            $connection->select('SELECT 1 AS n');
            self::fail('the connection was used without its session limits');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
        self::assertArrayHasKey('galbridge_down_until', $_SESSION);
        self::assertStringContainsString('session_limit_refused', self::log());
        self::assertMatchesRegularExpression('/db_down.*cause=session_limit/', self::log());
        self::assertMatchesRegularExpression('/the server refused (SET|PRAGMA) /', $connection->lastError(), 'diag names the statement');
        self::assertInstanceOf(\rcube_db::class, $created);
        self::assertFalse($created->is_connected(), 'the connection was closed');
        self::assertSame([], $connection->sessionWarnings());
        try {
            $connection->select('SELECT 1 AS n');
            self::fail('retried in the same request');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason());
        }
    }

    /**
     * diag reads the statement time of the plugin's own session (after the plugin's SET) on every
     * engine and compares it with galbridge_db_timeout.
     */
    #[DataProvider('serverEngines')]
    public function testDiagShowsTheSessionStatementTime(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN');
        }
        foreach ([3, 7] as $seconds) {
            $out = fopen('php://memory', 'w+');
            self::assertIsResource($out);
            $tool = new Tool(\rcube::get_instance(), CliRun::pluginDir(), $out);
            $connection = new Connection(new Dsn($dsn, Env::config($dsn)), $seconds);
            (new \ReflectionMethod(Tool::class, 'privileges'))->invoke($tool, $connection);
            $connection->close();
            rewind($out);
            $text = (string) stream_get_contents($out);
            fclose($out);
            CliRun::assertLine($text, 'OK', 'statement time (this session)', '(galbridge_db_timeout)');
            self::assertMatchesRegularExpression(str_starts_with($dsn, 'pgsql') ? '/statement time \(this session\) +' . $seconds . 's /' : '/statement time \(this session\) +max_(statement|execution)_time=' . $seconds . '(\.0+|000)? /', $text);
            self::assertStringNotContainsString('session limit', $text);
        }
        $ms = new \ReflectionMethod(Tool::class, 'postgresMilliseconds');
        foreach (['3s' => 3000, '3000ms' => 3000, '1min' => 60000, '0' => 0, ' 30s ' => 30000, 'off' => null, '3 s' => 3000, '' => null] as $value => $expected) {
            self::assertSame($expected, $ms->invoke(null, (string) $value), (string) $value);
        }
    }

    // ------------------------------------------------------------------ (d) expiry

    /**
     * An entry that expires in 30 minutes UTC has already expired for a database server whose session
     * time zone is UTC+05:00 (the expired columns have no time zone): hidden there, shown at UTC+00:00.
     * 1.0.1 compared with UTC only and showed it in both.
     */
    #[DataProvider('serverEngines')]
    public function testExpiryIsJudgedByTheDatabaseClockAndByUtc(string $dsn): void
    {
        if ($dsn === '') {
            self::markTestSkipped('needs GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN (SQLite runs in the process time zone)');
        }
        $soon = gmdate('Y-m-d H:i:s', time() + 1800);
        Fixture::alter(
            $dsn,
            ["UPDATE mailbox SET expired = '{$soon}' WHERE username = '" . self::ANNA . "'"],
            ["UPDATE mailbox SET expired = '9999-12-31 00:00:00' WHERE username = '" . self::ANNA . "'"],
        );
        $config = Env::config($dsn);
        foreach (['+05:00' => false, '+00:00' => true] as $zone => $visible) {
            $_SESSION = [];
            $factory = self::zonedFactory($config, self::JAN, $dsn, $zone);
            $book = $factory->create(BookDefinition::DOMAIN);
            self::assertInstanceOf(AddressBook::class, $book, $zone . ' ' . self::log());
            $book->set_pagesize(200);
            self::assertSame($visible, in_array(self::ANNA, self::emails($book->list_records()), true), "session time zone {$zone}");
        }

        // the viewer's own mailbox: no directory once it has expired by either clock
        Fixture::alter(
            $dsn,
            ["UPDATE mailbox SET expired = '{$soon}' WHERE username = '" . self::JAN . "'"],
            ["UPDATE mailbox SET expired = '9999-12-31 00:00:00' WHERE username = '" . self::JAN . "'"],
        );
        foreach (['+05:00' => false, '+00:00' => true] as $zone => $resolves) {
            $_SESSION = [];
            $resolver = self::zonedFactory($config, self::JAN, $dsn, $zone)->resolver();
            self::assertSame($resolves, $resolver->resolve() !== null, "viewer, session time zone {$zone}");
            self::assertSame($resolves ? 'ok' : 'no_mailbox', $resolver->status());
        }
    }

    // ------------------------------------------------------------------ (e) required birthday

    #[DataProvider('engines')]
    public function testARequiredBirthdayMeansARealDate(string $dsn): void
    {
        $notices = [];
        $factory = Env::factory(Env::config($dsn, ['galbridge_fields' => ['name', 'email', 'surname', 'birthday']]), self::JAN, '', $notices);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_pagesize(200);
        $result = $book->search(['email'], 'example.com', 0, true, false, ['birthday']);
        self::assertSame([self::JAN, self::LUKASZ], self::emails($result), 'exactly the persons with a birthday (year 0001 is none)');
        foreach ($result->records as $record) {
            self::assertArrayHasKey('birthday', $record);
        }
        self::assertSame(2, $book->search(['email'], 'example.com', 0, false, false, ['birthday'])->count);
        // a required text field still means "something is shown"
        self::assertNotContains('postmaster@example.com', self::emails($book->search(['email'], 'example.com', 0, true, false, ['surname'])));
        self::assertStringNotContainsString('db_error', self::log());
        self::assertSame([], $notices, 'no "directory unavailable"');
    }

    // ------------------------------------------------------------------ helpers

    private static function refusing(string $dsn, string $effective, string $needle): \rcube_db
    {
        $message = '[1227] Access denied; you need (at least one of) the SUPER privilege(s) for this operation';
        if (str_starts_with($dsn, 'mysql')) {
            return new class ($effective, $needle, $message) extends \rcube_db_mysql {
                public function __construct(string $dsn, private readonly string $needle, private readonly string $message)
                {
                    parent::__construct($dsn, '', false);
                }

                public function query($query, ...$params)
                {
                    if (str_contains((string) $query, $this->needle)) {
                        $this->db_error = true;
                        $this->db_error_msg = $this->message;
                        return false;
                    }
                    return parent::query($query, ...$params);
                }
            };
        }
        if (str_starts_with($dsn, 'pgsql')) {
            return new class ($effective, $needle, $message) extends \rcube_db_pgsql {
                public function __construct(string $dsn, private readonly string $needle, private readonly string $message)
                {
                    parent::__construct($dsn, '', false);
                }

                public function query($query, ...$params)
                {
                    if (str_contains((string) $query, $this->needle)) {
                        $this->db_error = true;
                        $this->db_error_msg = $this->message;
                        return false;
                    }
                    return parent::query($query, ...$params);
                }
            };
        }
        return new class ($effective, $needle, $message) extends \rcube_db_sqlite {
            public function __construct(string $dsn, private readonly string $needle, private readonly string $message)
            {
                parent::__construct($dsn, '', false);
            }

            public function query($query, ...$params)
            {
                if (str_contains((string) $query, $this->needle)) {
                    $this->db_error = true;
                    $this->db_error_msg = $this->message;
                    return false;
                }
                return parent::query($query, ...$params);
            }
        };
    }

    /**
     * The production wiring with a connection whose session time zone is $zone.
     */
    private static function zonedFactory(Config $config, string $login, string $dsn, string $zone): Factory
    {
        $connection = null;
        $connect = static function () use ($config, $dsn, $zone, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3, static function (string $effective) use ($dsn, $zone): \rcube_db {
                if (str_starts_with($dsn, 'pgsql')) {
                    return new class ($effective, $zone) extends \rcube_db_pgsql {
                        public function __construct(string $dsn, private readonly string $zone)
                        {
                            parent::__construct($dsn, '', false);
                        }

                        protected function conn_configure($dsn, $dbh)
                        {
                            parent::conn_configure($dsn, $dbh);
                            $dbh->query("SET TIME ZONE INTERVAL '" . $this->zone . "' HOUR TO MINUTE");
                        }
                    };
                }
                return new class ($effective, $zone) extends \rcube_db_mysql {
                    public function __construct(string $dsn, private readonly string $zone)
                    {
                        parent::__construct($dsn, '', false);
                    }

                    protected function conn_configure($dsn, $dbh)
                    {
                        parent::conn_configure($dsn, $dbh);
                        $dbh->query("SET time_zone = '" . $this->zone . "'");
                    }
                };
            });
        };
        $resolver = new ViewerResolver($config, $connect, 1, $login, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l);
    }

    /**
     * @return list<string>
     */
    private static function emails(\rcube_result_set $rs): array
    {
        $out = [];
        foreach ($rs->records as $r) {
            $out[] = (string) ($r['email:work'][0] ?? '');
        }
        sort($out);
        return $out;
    }

    private static function log(): string
    {
        return implode("\n", $GLOBALS['galbridge_test_log'] ?? []);
    }
}
