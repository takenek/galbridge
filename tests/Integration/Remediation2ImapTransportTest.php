<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Config;
use GalBridge\Db\Connection;
use GalBridge\Db\Dsn;
use GalBridge\Directory\ViewerResolver;
use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\PluginBoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-18: a session whose IMAP server is not local gets the directory only
 * when it reached the server over TLS that authenticates it - whoever sits on the network between
 * webmail and IMAP server could otherwise answer the login for any mailbox (STARTTLS on Roundcube
 * before 1.7.7 even goes on in plain text when the server does not offer it). The check runs on every
 * request before the cache, the database and the login; galbridge_imap_allow_plaintext accepts such a
 * session for a trusted private link (logged). diag judges every configured server. Loopback and
 * sockets (the iRedMail default) are unchanged.
 *
 * Every test fails on 1.0.1.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ImapTransportTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    private const REMOTE = 'mail.example.net';

    private string $base = '';

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
    }

    protected function tearDown(): void
    {
        $this->boot?->restore();
        $this->boot = null;
        if ($this->base !== '') {
            CliRun::rmTree($this->base);
            $this->base = '';
        }
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
    public function testARemoteSessionWithoutTlsGetsNoDirectory(string $dsn): void
    {
        $connects = 0;
        $resolver = self::resolver(self::config($dsn), self::REMOTE, 993, '', $connects);
        self::assertNull($resolver->resolve());
        self::assertSame('transport_unprotected', $resolver->status());
        self::assertSame(0, $connects, 'refused before the database');
        self::assertArrayNotHasKey('galbridge', $_SESSION, 'never cached');
        $lines = array_values(preg_grep('/imap_transport/', $GLOBALS['galbridge_test_log']) ?: []);
        self::assertCount(1, $lines);
        self::assertStringStartsWith('WARN viewer: imap_transport: the IMAP connection is not encrypted', $lines[0]);
        self::assertStringEndsWith('host=mail.example.net:993', $lines[0]);
        self::assertStringNotContainsString('kowalski', implode("\n", $GLOBALS['galbridge_test_log']), 'never the login');
    }

    #[DataProvider('engines')]
    public function testARemoteSessionWithImplicitTlsIsAccepted(string $dsn): void
    {
        foreach (['ssl', 'imaps'] as $ssl) {
            $_SESSION = [];
            $resolver = self::resolver(self::config($dsn), self::REMOTE, 993, $ssl);
            self::assertNotNull($resolver->resolve(), $ssl);
            self::assertSame('ok', $resolver->status());
        }
        self::assertSame([], preg_grep('/imap_transport/', $GLOBALS['galbridge_test_log']) ?: []);
    }

    /**
     * STARTTLS counts only on a Roundcube library that ends the connection when the server does not
     * offer it (1.7.7 and later).
     */
    #[DataProvider('engines')]
    public function testStartTlsCountsFromRoundcube177On(string $dsn): void
    {
        $resolver = self::resolver(self::config($dsn), self::REMOTE, 993, 'tls');
        $version = ViewerResolver::coreVersion();
        $fixed = preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1 && version_compare($version, '1.7.7', '>=');
        self::assertSame($fixed ? 'ok' : 'transport_unprotected', ($resolver->resolve() !== null ? 'ok' : $resolver->status()), 'Roundcube ' . $version);
    }

    #[DataProvider('engines')]
    public function testDisabledCertificateVerificationIsRefused(string $dsn): void
    {
        $resolver = self::resolver(self::config($dsn, ['imap_conn_options' => ['ssl' => ['verify_peer' => false]]]), self::REMOTE, 993, 'ssl');
        self::assertNull($resolver->resolve());
        self::assertSame('transport_unprotected', $resolver->status());
        self::assertStringContainsString('verification', implode("\n", $GLOBALS['galbridge_test_log']));

        // options for another host do not apply to this one
        $_SESSION = [];
        $resolver = self::resolver(self::config($dsn, ['imap_conn_options' => ['imap.example.org' => ['ssl' => ['verify_peer' => false]]]]), self::REMOTE, 993, 'ssl');
        self::assertNotNull($resolver->resolve());
    }

    #[DataProvider('engines')]
    public function testTheOptOutAcceptsAndLogs(string $dsn): void
    {
        $resolver = self::resolver(self::config($dsn, ['galbridge_imap_allow_plaintext' => true]), self::REMOTE, 993, '');
        self::assertNotNull($resolver->resolve());
        self::assertSame('ok', $resolver->status());
        $lines = array_values(preg_grep('/imap_transport/', $GLOBALS['galbridge_test_log']) ?: []);
        self::assertCount(1, $lines);
        self::assertStringContainsString('accepted because galbridge_imap_allow_plaintext is set', $lines[0]);
        self::assertStringStartsWith('WARN ', $lines[0]);
    }

    /**
     * The iRedMail default - loopback, or a local socket - needs no TLS.
     */
    #[DataProvider('engines')]
    public function testLoopbackAndSocketsNeedNoTls(string $dsn): void
    {
        $resolver = self::resolver(self::config($dsn), '127.0.0.1', 143, '');
        self::assertNotNull($resolver->resolve());
        $_SESSION = [];
        $socket = 'unix:///run/dovecot/imap.sock';
        $config = Env::config($dsn, ['galbridge_imap_hosts' => [$socket]]);
        $resolver = new ViewerResolver($config, self::connect($config), 1, self::JAN, $socket, $socket, -1, '');
        self::assertNotNull($resolver->resolve());
        self::assertSame([], preg_grep('/imap_transport/', $GLOBALS['galbridge_test_log']) ?: []);
    }

    /**
     * The TLS mode of the session is not part of the cache key: the check runs on every request,
     * before a cached Viewer is used.
     */
    #[DataProvider('engines')]
    public function testTheTransportIsCheckedBeforeTheCache(string $dsn): void
    {
        $config = self::config($dsn);
        self::assertNotNull(self::resolver($config, self::REMOTE, 993, 'ssl')->resolve());
        self::assertIsArray($_SESSION['galbridge'] ?? null);
        $resolver = self::resolver($config, self::REMOTE, 993, '');
        self::assertNull($resolver->resolve());
        self::assertSame('transport_unprotected', $resolver->status());
    }

    /**
     * The plugin hands the TLS mode of the session ($_SESSION['storage_ssl']) to the resolver.
     */
    public function testThePluginPassesTheTlsModeOfTheSession(): void
    {
        $this->boot = new PluginBoot();
        $admin = ['galbridge_db_dsn' => Env::sqliteDsn(), 'imap_host' => 'tls://' . Env::IMAP_HOST . ':143'];
        foreach ([['ssl', 'ssl'], ['TLS', 'tls'], [false, ''], [null, '']] as [$stored, $expected]) {
            $this->boot->boot($admin, 'addressbook', 'list', ['storage_ssl' => $stored]);
            $property = new \ReflectionProperty(ViewerResolver::class, 'storageSsl');
            self::assertSame($expected, $property->getValue($this->boot->resolver()), var_export($stored, true));
        }
    }

    /**
     * diag judges the transport of every configured server with the scheme Roundcube connects with.
     */
    public function testDiagJudgesTheTransportOfEveryServer(): void
    {
        $dsn = CliRun::serverDsn() ?? 'mysql://galbridge_ro:pw@127.0.0.1:1/vmail';
        $version = ViewerResolver::coreVersion();
        $fixed = preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1 && version_compare($version, '1.7.7', '>=');
        $cases = [
            ['127.0.0.1:143', [], 'OK', 'local server (no network in between)'],
            ['ssl://imap.example.net:993', [], 'OK', 'TLS that verifies the server'],
            ['imap.example.net:143', [], 'FAIL', 'the IMAP connection is not encrypted'],
            ['imap.example.net:143', ['galbridge_imap_allow_plaintext' => true], 'WARN', 'accepted because galbridge_imap_allow_plaintext is set'],
            ['tls://imap.example.net:143', [], $fixed ? 'OK' : 'FAIL', $fixed ? 'TLS that verifies the server' : 'STARTTLS (tls://) on Roundcube'],
            // rcmail::login() reads the scheme case-sensitively: "SSL://" connects without TLS
            ['SSL://imap.example.net:993', [], 'FAIL', 'the IMAP connection is not encrypted'],
        ];
        foreach ($cases as [$imapHost, $plugin, $level, $text]) {
            $base = $this->base();
            [, $out, $err] = CliRun::diag(CliRun::roundcubeConfig($base, ['galbridge_db_dsn' => $dsn] + $plugin, ['imap_host' => $imapHost]));
            $n = str_contains($imapHost, '127.0.0.1') ? '127.0.0.1' : 'imap.example.net';
            CliRun::assertLine($out, $level, 'IMAP transport ' . $n, $text, $imapHost . "\n" . $err);
            CliRun::rmTree($base);
            $this->base = '';
        }
        // verification switched off for the server
        [, $out] = CliRun::diag(CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn], ['imap_host' => 'ssl://imap.example.net:993', 'imap_conn_options' => ['ssl' => ['verify_peer' => false]]]));
        CliRun::assertLine($out, 'FAIL', 'IMAP transport imap.example.net', 'switch the verification of the IMAP server certificate off');
        CliRun::assertLine($out, 'INFO', 'switches (controls)', 'imap_allow_plaintext off');
        CliRun::rmTree($this->base);
        $this->base = '';
        // users choose the server: said, not judged
        [, $out] = CliRun::diag(CliRun::roundcubeConfig($this->base(), ['galbridge_db_dsn' => $dsn, 'galbridge_imap_hosts' => ['127.0.0.1:143']], ['imap_host' => '']));
        CliRun::assertLine($out, 'INFO', 'IMAP transport', 'imap_host is empty or a template, so users choose the server and its scheme');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $options
     */
    private static function config(string $dsn, array $options = []): Config
    {
        return Env::config($dsn, ['galbridge_imap_hosts' => [self::REMOTE . ':993', '127.0.0.1:143']] + $options);
    }

    private static function resolver(Config $config, string $host, int $port, string $ssl, int &$connects = 0): ViewerResolver
    {
        $connect = self::connect($config);
        $counting = static function () use ($connect, &$connects): Connection {
            $connects++;
            return $connect();
        };
        return new ViewerResolver($config, $counting, 1, self::JAN, $host, $host, $port, $ssl);
    }

    /**
     * @return \Closure(): Connection
     */
    private static function connect(Config $config): \Closure
    {
        $connection = null;
        return static function () use ($config, &$connection): Connection {
            return $connection ??= new Connection(new Dsn($config->string('galbridge_db_dsn'), $config), 3);
        };
    }

    private function base(): string
    {
        if ($this->base === '') {
            $this->base = CliRun::tempBase('galbridge-r2tls');
        }
        return $this->base;
    }
}
