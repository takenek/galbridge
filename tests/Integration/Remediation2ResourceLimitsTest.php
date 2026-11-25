<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Directory\Repository;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Exception\GalBridgeException;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-15: what one authenticated user can make the directory spend.
 *  (a) only an unreachable or slow server is memoised as an outage for the session: a refusal the
 *      server answers at once - the connection limit in any wording, an authentication error, a
 *      limit text in the server's language - fails the request only, so one user's load cannot
 *      take the directory away from other sessions for 30 s;
 *  (b) core's copy action reads every contact of the request one by one (get_record()): at most
 *      galbridge_max_ids per request, shared by the books of the request;
 *  (c) a full set reads its details in statements of at most Repository::DETAIL_BATCH addresses -
 *      no statement with an unbounded parameter list (rcube_db inlines parameters one by one).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ResourceLimitsTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
    }

    public function testAnUnrecognisedFastRefusalIsNotMemoisedForTheSession(): void
    {
        foreach ([
            // a role limit in the language of the server (lc_messages): SQLSTATE 08006, no number
            'PostgreSQL role limit, German' => ['SQLSTATE[08006] [7] FATAL:  zu viele Verbindungen für Rolle »galbridge«', 'refused'],
            'PostgreSQL server-wide limit' => ['SQLSTATE[08006] [7] FATAL:  remaining connection slots are reserved for roles with the SUPERUSER attribute', 'busy'],
            'authentication' => ["SQLSTATE[HY000] [1045] Access denied for user 'galbridge'@'localhost' (using password: YES)", 'refused'],
            'a dead server' => ['SQLSTATE[HY000] [2002] Connection refused', 'memoised'],
            'an unknown host' => ['SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db.invalid failed: Name or service not known', 'memoised'],
            'PostgreSQL timeout' => ['SQLSTATE[08006] [7] connection to server at "192.0.2.1", port 5432 failed: timeout expired', 'memoised'],
        ] as $case => [$message, $expected]) {
            $_SESSION = [];
            $GLOBALS['galbridge_test_log'] = [];
            $created = 0;
            $connection = self::failingConnection($message, 0.0, $created);
            self::assertDown($connection, $case);
            self::assertSame($expected === 'memoised', isset($_SESSION['galbridge_down_until']), $case . ': memoised for the session');
            $log = implode("\n", $GLOBALS['galbridge_test_log']);
            match ($expected) {
                'busy' => self::assertStringContainsString('db_busy', $log, $case),
                'refused' => self::assertMatchesRegularExpression('/ERROR db: db_down cause=refused$/m', $log, $case),
                default => self::assertMatchesRegularExpression('/db_down retry_in=30 cause=connect/', $log, $case),
            };
            // the same request does not try again
            self::assertDown($connection, $case . ' (again)');
            self::assertSame(1, $created, $case . ': one attempt per request');
        }

        // a failure that took at least half of the connect timeout: a server that does not answer
        $_SESSION = [];
        $created = 0;
        self::assertDown(self::failingConnection('SQLSTATE[HY000] [9999] something unexpected', 0.6, $created), 'slow');
        self::assertArrayHasKey('galbridge_down_until', $_SESSION, 'a slow failure is memoised');
        // the same text answered at once is not
        $_SESSION = [];
        self::assertDown(self::failingConnection('SQLSTATE[HY000] [9999] something unexpected', 0.0, $created), 'fast');
        self::assertArrayNotHasKey('galbridge_down_until', $_SESSION);
    }

    /**
     * Core's copy action calls get_record() for every ID of the request. Beyond galbridge_max_ids
     * the rest is refused with the message of too many IDs (once); an ID that cannot be decoded
     * costs nothing; the budget is the request's, whichever book reads.
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testCopyReadsAtMostMaxIdsContactsPerRequest(string $dsn): void
    {
        $notices = [];
        $options = ['galbridge_max_ids' => 3, 'galbridge_global_book' => true, 'galbridge_global_viewer_domains' => ['*'], 'galbridge_global_data_domains' => ['example.org']];
        $factory = Env::factory(Env::config($dsn, $options), self::JAN, 'copy', $notices);
        $domain = $factory->create(BookDefinition::DOMAIN);
        $global = $factory->create(BookDefinition::GLOBAL);
        self::assertInstanceOf(AddressBook::class, $domain);
        self::assertInstanceOf(AddressBook::class, $global);

        self::assertNull($domain->get_record('u_forged!', true), 'undecodable: no lookup, no budget');
        $read = [];
        foreach (['anna.nowak@example.com', 'postmaster@example.com'] as $address) {
            $read[] = $domain->get_record(IdCodec::contactId(IdCodec::PERSON, $address), true);
        }
        $read[] = $global->get_record(IdCodec::contactId(IdCodec::PERSON, 'bob@example.org'), true);
        foreach ($read as $i => $record) {
            self::assertIsArray($record, 'lookup ' . ($i + 1));
        }
        self::assertSame([], $notices);
        // the fourth and fifth lookup of the request - in either book
        self::assertNull($domain->get_record(IdCodec::contactId(IdCodec::PERSON, self::JAN), true));
        self::assertNull($global->get_record(IdCodec::contactId(IdCodec::PERSON, 'bob@example.org'), true));
        self::assertSame([], $domain->get_record_groups(IdCodec::contactId(IdCodec::PERSON, self::JAN)));
        self::assertSame([['toomanyids', ['max' => '3']]], $notices, 'one message');
        self::assertSame(1, substr_count(implode("\n", $GLOBALS['galbridge_test_log']), 'too_many_ids'));
        self::assertNotEmpty($domain->get_error());

        // a new request has its budget again
        $factory = Env::factory(Env::config($dsn, $options), self::JAN, 'copy', $notices);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertIsArray($book->get_record(IdCodec::contactId(IdCodec::PERSON, self::JAN), true));
    }

    /**
     * A whole-book export of 1213 entries with per-user aliases: complete, and no statement carries
     * more parameters than one batch of addresses and the predicates of the book.
     */
    public function testFullSetDetailStatementsAreBatched(): void
    {
        $dsn = Env::sqliteDsn();
        [$apply, $revert] = Fixture::bulkMailboxes(1200);
        Fixture::alter($dsn, $apply, $revert);
        $config = Env::config($dsn, ['galbridge_max_export' => 2000, 'galbridge_user_aliases' => true, 'galbridge_max_count' => 5000]);
        $counts = new \ArrayObject();
        $notices = [];
        $book = self::factory($config, $counts, 'export', $notices)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $book->set_page(1);
        $book->set_pagesize(99999);
        $result = $book->list_records(null, 0, true);
        self::assertCount(1213, $result->records, 'the complete book');
        self::assertSame([], $notices);
        $max = max($counts->getArrayCopy());
        self::assertLessThanOrEqual(Repository::DETAIL_BATCH + 100, $max, 'the largest parameter list of a statement');
        self::assertGreaterThanOrEqual(Repository::DETAIL_BATCH, $max, 'a batch is full');
    }

    // ------------------------------------------------------------------ helpers

    private static function assertDown(Connection $connection, string $case): void
    {
        try {
            $connection->select('SELECT 1');
            self::fail($case . ': connected');
        } catch (GalBridgeException $e) {
            self::assertSame('db_down', $e->getReason(), $case);
        }
    }

    /**
     * A MariaDB connection (connect timeout 1 s) whose driver fails with $message after $seconds.
     */
    private static function failingConnection(string $message, float $seconds, int &$created): Connection
    {
        $dsn = 'mysql://galbridge:pw@127.0.0.1/vmail';
        $config = Env::config($dsn, ['galbridge_db_connect_timeout' => 1]);
        return new Connection(new Dsn($dsn, $config), 3, static function (string $effective) use ($message, $seconds, &$created): \rcube_db {
            $created++;
            return new class ($effective, $message, $seconds) extends \rcube_db {
                public function __construct(string $dsn, private readonly string $message, private readonly float $seconds)
                {
                    parent::__construct($dsn);
                }

                public function db_connect($mode, $force = false)
                {
                    if ($this->seconds > 0) {
                        usleep((int) ($this->seconds * 1000000));
                    }
                    $this->db_error = true;
                    $this->db_error_msg = $this->message;
                }
            };
        });
    }

    /**
     * The production wiring with a connection whose driver records the parameter count of every
     * statement.
     *
     * @param \ArrayObject<int, int>                         $counts
     * @param list<array{string, array<string, string>}> $notices
     */
    private static function factory(Config $config, \ArrayObject $counts, string $action, array &$notices): Factory
    {
        $notices = [];
        $connection = null;
        $connect = static function () use ($config, $counts, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3, static fn (string $effective): \rcube_db => new class ($effective, $counts) extends \rcube_db_sqlite {
                /**
                 * @param \ArrayObject<int, int> $counts
                 */
                public function __construct(string $dsn, private readonly \ArrayObject $counts)
                {
                    parent::__construct($dsn, '', false);
                }

                public function query($query, ...$params)
                {
                    $this->counts->append(count($params));
                    return parent::query($query, ...$params);
                }

                public function limitquery($query, $offset, $limit, ...$params)
                {
                    $this->counts->append(count($params));
                    return parent::limitquery($query, $offset, $limit, ...$params);
                }
            });
        };
        $resolver = new ViewerResolver($config, $connect, 1, self::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT);
        $notify = static function (string $label, array $vars) use (&$notices): void {
            $notices[] = [$label, $vars];
        };
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l, $action, $notify);
    }
}
