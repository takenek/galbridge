<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Factory;
use GalBridge\Log;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use GalBridge\Tests\Support\PluginBoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-17: an open session loses the directory when its access is revoked.
 *
 *  - When the cached Viewer is renewed (galbridge_scope_ttl), the IMAP server confirms the
 *    credentials of the session (Roundcube's own connection: the one of the request, or
 *    rcmail::storage_connect()). A refusal - changed password, disabled IMAP service - ends the
 *    directory for the rest of the session ("imap_refused", never asked again); a server that cannot
 *    be asked makes it unavailable for a while ("imap_unavailable"), never refused.
 *  - With the strict list policy the viewer's own aliases are read again with the mailbox
 *    confirmation: a removed alias applies with the next request (that request reads nothing).
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2RevocationTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private ?PluginBoot $boot = null;

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        \rcube::get_instance();
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        Log::setDebug(false);
        self::newRequest();
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $this->boot?->restore();
        $this->boot = null;
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return Env::engines();
    }

    #[DataProvider('engines')]
    public function testAnImapRefusalAtRenewalEndsTheDirectoryForTheSession(string $dsn): void
    {
        $config = Env::config($dsn);
        $asked = 0;
        $connects = 0;
        self::assertNotNull(self::resolver($config, static fn (): ?bool => true, $asked, $connects)->resolve());
        self::assertSame(1, $asked);

        self::age();
        $resolver = self::resolver($config, static fn (): ?bool => false, $asked, $connects);
        self::assertNull($resolver->resolve());
        self::assertSame('imap_refused', $resolver->status());
        self::assertSame(2, $asked);
        self::assertSame('imap_refused', $_SESSION['galbridge']['status'] ?? null);
        self::assertArrayHasKey('viewer', $_SESSION['galbridge']);
        self::assertNull($_SESSION['galbridge']['viewer']);
        self::assertCount(1, preg_grep('/viewer_revoked: the IMAP server no longer accepts the credentials of this session/', $GLOBALS['galbridge_test_log']) ?: []);
        self::assertNull($resolver->staleViewer(), 'no book stays listed');

        // for good in this session: another configuration, an expired entry, an IMAP server that
        // would say yes - nothing is asked, nothing connects
        $connects = 0;
        $_SESSION['galbridge']['at'] -= 7200;
        $resolver = self::resolver(Env::config($dsn, ['galbridge_max_results' => 77]), static fn (): ?bool => true, $asked, $connects);
        self::assertNull($resolver->resolve());
        self::assertSame('imap_refused', $resolver->status());
        self::assertSame(2, $asked);
        self::assertSame(0, $connects);

        // a new login is a new session
        $_SESSION = [];
        self::assertNotNull(self::resolver($config, static fn (): ?bool => true, $asked)->resolve());
    }

    #[DataProvider('engines')]
    public function testAnUnreachableImapServerIsNoRevocation(string $dsn): void
    {
        $config = Env::config($dsn);
        $asked = 0;
        self::assertNotNull(self::resolver($config, static fn (): ?bool => true, $asked)->resolve());
        self::age();
        $resolver = self::resolver($config, static fn (): ?bool => null, $asked);
        self::assertNull($resolver->resolve());
        self::assertSame('imap_unavailable', $resolver->status());
        self::assertSame('ok', $_SESSION['galbridge']['status'] ?? null, 'the entry is kept, never "refused"');
        self::assertIsInt($_SESSION['galbridge']['imap_retry_at'] ?? null);
        self::assertNotNull($resolver->staleViewer(), 'the books stay listed as unavailable');
        self::assertCount(1, preg_grep('/imap_unavailable/', $GLOBALS['galbridge_test_log']) ?: []);

        // the books of this session answer "unavailable"
        $notices = [];
        $factory = self::factory($config, static fn (): ?bool => true, $asked, $notices);
        self::assertSame([BookDefinition::DOMAIN], array_keys($factory->books()));
        self::assertSame(2, $asked, 'nothing is asked before the retry time');

        // after it, the server is asked again
        $_SESSION['galbridge']['imap_retry_at'] = time() - 1;
        self::assertNotNull(self::resolver($config, static fn (): ?bool => true, $asked)->resolve());
        self::assertSame(3, $asked);
        self::assertArrayNotHasKey('imap_retry_at', $_SESSION['galbridge']);

        // a check that fails unexpectedly is no revocation either; neither is the first request of a session
        $_SESSION = [];
        $resolver = self::resolver($config, static function (): ?bool {
            throw new \RuntimeException('connection reset');
        }, $asked);
        self::assertNull($resolver->resolve());
        self::assertSame('imap_unavailable', $resolver->status());
        self::assertSame('imap_unavailable', $_SESSION['galbridge']['status'] ?? null);
        self::assertNull($resolver->staleViewer());
    }

    #[DataProvider('engines')]
    public function testTheImapServerIsAskedOnlyWhenTheCacheIsRenewed(string $dsn): void
    {
        $config = Env::config($dsn);
        $asked = 0;
        self::assertNotNull(self::resolver($config, static fn (): ?bool => true, $asked)->resolve());
        self::assertSame(1, $asked);
        foreach ([1, 2, 3] as $request) {
            self::assertNotNull(self::resolver($config, static fn (): ?bool => false, $asked)->resolve(), 'cache hit ' . $request);
        }
        self::assertSame(1, $asked, 'a cached Viewer inside its lifetime asks nothing');
        self::age();
        self::assertNotNull(self::resolver($config, static fn (): ?bool => true, $asked)->resolve());
        self::assertSame(2, $asked);
    }

    /**
     * A per-user alias of the viewer opens a restricted (members only) alias account; the alias is
     * disabled while the session is open: the next request reads nothing, the one after does not show
     * the restricted entry - without waiting for galbridge_scope_ttl.
     */
    #[DataProvider('engines')]
    public function testRemovingAnOwnAliasTakesEffectWithTheNextRequest(string $dsn): void
    {
        Fixture::alter($dsn,
            ["INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_list, active) VALUES ('audit@example.com', 'jk@example.com', 'example.com', 'example.com', 1, 1)"],
            ["DELETE FROM forwardings WHERE address = 'audit@example.com' AND forwarding = 'jk@example.com'"]);
        $config = Env::config($dsn);
        self::assertContains('audit@example.com', self::emails(Env::factory($config, self::JAN)->create(BookDefinition::DOMAIN)), 'visible through the alias jk@');

        Fixture::alter($dsn,
            ["UPDATE forwardings SET active = 0 WHERE address = 'jk@example.com' AND forwarding = 'jan.kowalski@example.com' AND is_alias = 1"],
            ["UPDATE forwardings SET active = 1 WHERE address = 'jk@example.com' AND forwarding = 'jan.kowalski@example.com' AND is_alias = 1"]);
        self::newRequest();
        $asked = 0;
        $notices = [];
        $factory = self::factory($config, static fn (): ?bool => true, $asked, $notices);
        $book = $factory->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        self::assertSame([], self::emails($book), 'this request reads nothing');
        self::assertSame('viewer_changed', $factory->resolver()->status());
        self::assertSame([['unavailable', []]], $notices);
        self::assertCount(1, preg_grep('/viewer_changed/', $GLOBALS['galbridge_test_log']) ?: []);
        self::assertNotContains('jk@example.com', $_SESSION['galbridge']['viewer']['addresses'] ?? ['jk@example.com']);
        self::assertSame('ok', $_SESSION['galbridge']['status'] ?? null);
        self::assertSame(0, $asked, 'inside the cache lifetime');

        self::newRequest();
        $book = self::factory($config, static fn (): ?bool => true, $asked, $notices)->create(BookDefinition::DOMAIN);
        $emails = self::emails($book);
        self::assertNotContains('audit@example.com', $emails);
        self::assertContains('board@example.com', $emails, 'the login itself is still a member there');
        self::assertSame([], $notices);
    }

    /**
     * A NEW own alias does not interrupt a request (the queries of the request are narrower, never
     * wider); the session entry takes it over at once.
     */
    #[DataProvider('engines')]
    public function testAnAddedOwnAliasDoesNotInterruptTheRequest(string $dsn): void
    {
        $config = Env::config($dsn);
        self::assertNotNull(Env::factory($config, self::JAN)->resolver()->resolve());
        $at = $_SESSION['galbridge']['at'];
        Fixture::alter($dsn,
            ["INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES ('jan.new@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1)"],
            ["DELETE FROM forwardings WHERE address = 'jan.new@example.com' AND forwarding = 'jan.kowalski@example.com'"]);
        self::newRequest();
        $asked = 0;
        $notices = [];
        $factory = self::factory($config, static fn (): ?bool => true, $asked, $notices);
        self::assertNotSame([], self::emails($factory->create(BookDefinition::DOMAIN)));
        self::assertSame('ok', $factory->resolver()->status());
        self::assertSame([], $notices);
        self::assertContains('jan.new@example.com', $_SESSION['galbridge']['viewer']['addresses'] ?? []);
        self::assertSame($at, $_SESSION['galbridge']['at'], 'the cache lifetime is not extended');
    }

    /**
     * Where the list policy does not depend on the viewer's aliases, nothing more is read.
     */
    #[DataProvider('engines')]
    public function testTheAliasesAreReadAgainOnlyWhereTheyDecide(string $dsn): void
    {
        $config = Env::config($dsn, ['galbridge_list_policy' => 'public_only']);
        self::assertNotNull(Env::factory($config, self::JAN)->resolver()->resolve());
        Fixture::alter($dsn,
            ["UPDATE forwardings SET active = 0 WHERE address = 'jk@example.com' AND forwarding = 'jan.kowalski@example.com' AND is_alias = 1"],
            ["UPDATE forwardings SET active = 1 WHERE address = 'jk@example.com' AND forwarding = 'jan.kowalski@example.com' AND is_alias = 1"]);
        self::newRequest();
        $asked = 0;
        $factory = self::factory($config, static fn (): ?bool => true, $asked);
        self::assertNotSame([], self::emails($factory->create(BookDefinition::DOMAIN)));
        self::assertSame('ok', $factory->resolver()->status());
        self::assertContains('jk@example.com', $_SESSION['galbridge']['viewer']['addresses'] ?? [], 'not read again');
    }

    /**
     * The plugin hands the resolver a check that uses Roundcube's own IMAP connection: the open
     * connection of the request, or rcmail::storage_connect() with the credentials of the session.
     */
    public function testThePluginAsksRoundcubesImapConnection(): void
    {
        $this->boot = new PluginBoot();
        $admin = ['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143', 'des_key' => 'TESTONLY-r2-0123456789ab'];

        $this->boot->boot($admin, 'addressbook', 'list', [], self::storage(true));
        $check = (new \ReflectionProperty(ViewerResolver::class, 'imapCheck'))->getValue($this->boot->resolver());
        self::assertInstanceOf(\Closure::class, $check);
        self::assertTrue($check());
        self::assertTrue(\galbridge::imapCheck($this->boot->rc));

        // not connected yet: Roundcube connects with the session's credentials
        foreach ([['AUTHENTICATIONFAILED', false], ['UNAVAILABLE', null], [null, null]] as [$code, $verdict]) {
            $storage = self::storage(false, $code);
            $this->boot->boot($admin, 'addressbook', 'list', ['username' => self::JAN, 'password' => '', 'storage_ssl' => 'tls'], $storage);
            self::assertSame($verdict, \galbridge::imapCheck($this->boot->rc), var_export($code, true));
            self::assertSame([Env::IMAP_HOST, self::JAN, Env::IMAP_PORT, 'tls'], $storage->attempt, 'the session values');
        }

        // end to end: an expired entry and a refused login - no book, the user sees Roundcube's own
        // storage error, the session entry says "refused"
        $this->boot->boot($admin, 'addressbook', 'list', ['username' => self::JAN, 'password' => '', 'storage_ssl' => false], self::storage(true));
        self::assertNotNull($this->boot->resolver()->resolve());
        $entry = $_SESSION['galbridge'];
        $entry['at'] -= 7200;
        $this->boot->boot($admin, 'addressbook', 'list', ['galbridge' => $entry, 'username' => self::JAN, 'password' => '', 'storage_ssl' => false], self::storage(false, 'AUTHENTICATIONFAILED'));
        self::assertSame([], $this->boot->api->exec_hook('addressbooks_list', ['sources' => []])['sources']);
        self::assertSame('imap_refused', $_SESSION['galbridge']['status'] ?? null);
        self::assertContains(['storageerror', 'error'], $this->boot->messages);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A storage object of Roundcube: connected, or refusing the next connection with an IMAP code.
     */
    private static function storage(bool $connected, ?string $code = null): \rcube_imap
    {
        return new class ($connected, $code) extends \rcube_imap {
            /** @var list<mixed> host, user, port, ssl of the last connection attempt */
            public array $attempt = [];

            public function __construct(private bool $open, private readonly ?string $code)
            {
                $this->conn = new \rcube_imap_generic();
            }

            #[\Override]
            public function is_connected()
            {
                return $this->open;
            }

            /**
             * @param string $host
             * @param string $user
             * @param string $pass
             * @param int    $port
             * @param string $use_ssl
             */
            #[\Override]
            public function connect($host, $user, $pass, $port = 143, $use_ssl = null)
            {
                $this->attempt = [$host, $user, $port, $use_ssl];
                $this->conn->errornum = $this->code === null ? \rcube_imap_generic::ERROR_BAD : \rcube_imap_generic::ERROR_NO;
                $this->conn->resultcode = $this->code;
                return false;
            }
        };
    }

    /**
     * A resolver of a request that finds $_SESSION as an earlier request left it.
     *
     * @param \Closure(): ?bool $check
     */
    private static function resolver(Config $config, \Closure $check, int &$asked, int &$connects = 0): ViewerResolver
    {
        $connection = null;
        $connect = static function () use ($config, &$connection, &$connects): Connection {
            $connects++;
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $counting = static function () use ($check, &$asked): ?bool {
            $asked++;
            return $check();
        };
        return new ViewerResolver($config, $connect, 1, self::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT, '', $counting);
    }

    /**
     * @param \Closure(): ?bool                               $check
     * @param null|list<array{string, array<string, string>}> $notices
     */
    private static function factory(Config $config, \Closure $check, int &$asked, ?array &$notices = null): Factory
    {
        $notices = [];
        $connection = null;
        $connect = static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
        $counting = static function () use ($check, &$asked): ?bool {
            $asked++;
            return $check();
        };
        $resolver = new ViewerResolver($config, $connect, 1, self::JAN, Env::IMAP_HOST, Env::IMAP_HOST, Env::IMAP_PORT, '', $counting);
        $notify = static function (string $label, array $vars) use (&$notices): void {
            $notices[] = [$label, $vars];
        };
        return new Factory($config, $resolver, $connect, static fn (string $l): string => $l, 'list', $notify);
    }

    /** the session entry is older than any galbridge_scope_ttl */
    private static function age(): void
    {
        self::assertIsArray($_SESSION['galbridge'] ?? null);
        $_SESSION['galbridge']['at'] -= 3601;
    }

    private static function newRequest(): void
    {
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    /**
     * @return list<string>
     */
    private static function emails(\rcube_addressbook $book): array
    {
        $book->set_page(1);
        $book->set_pagesize(200);
        $out = [];
        foreach ($book->list_records()->records as $r) {
            $out[] = (string) ($r['email:work'][0] ?? '');
        }
        sort($out);
        return $out;
    }
}
