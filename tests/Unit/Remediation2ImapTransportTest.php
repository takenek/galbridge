<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\ViewerResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-18: the identity binding trusts the IMAP login only over a protected
 * link. ViewerResolver::transportProblem() decides it without Roundcube: a local endpoint needs
 * nothing; any other needs TLS from the first byte (ssl, imaps) or STARTTLS on a Roundcube library
 * that aborts when the server does not offer it (1.7.7 and later; "1.7-git" does not count), PHP's
 * openssl extension, and certificate verification that imap_conn_options do not switch off (the
 * per-host entry selected as Roundcube selects it).
 *
 * Fails on 1.0.1 (no such check).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2ImapTransportTest extends TestCase
{
    private const REMOTE = 'mail.example.net';

    /**
     * @return array<string, array{string, string, string, mixed, string, bool, bool}> host, raw host, ssl, options, version, openssl, accepted
     */
    public static function transports(): array
    {
        $r = self::REMOTE;
        return [
            // nothing in between: no requirement
            'socket, plain' => ['unix:///run/dovecot/imap.sock', 'unix:///run/dovecot/imap.sock', '', null, '1.7.0', false, true],
            'localhost, plain' => ['localhost', 'localhost', '', null, '1.7.0', true, true],
            '127.0.0.1, plain' => ['127.0.0.1', '127.0.0.1', '', null, '1.7.0', true, true],
            '127.1.2.3, plain' => ['127.1.2.3', '127.1.2.3', '', null, '1.7.0', true, true],
            '::1, plain' => ['::1', '[::1]', '', null, '1.7.0', true, true],
            'loopback, verification off' => ['127.0.0.1', '127.0.0.1', 'ssl', ['ssl' => ['verify_peer' => false]], '1.7.7', true, true],
            // over the network
            'remote, plain' => [$r, $r, '', null, '1.7.7', true, false],
            'remote IP, plain' => ['192.0.2.10', '192.0.2.10', '', null, '1.7.7', true, false],
            'remote IPv6, plain' => ['2001:db8::7', '[2001:db8::7]', '', null, '1.7.7', true, false],
            'IPv4 that only starts like loopback' => ['1.27.0.0', '1.27.0.0', '', null, '1.7.7', true, false],
            'remote, ssl' => [$r, $r, 'ssl', null, '1.7.0', true, true],
            'remote, imaps' => [$r, $r, 'imaps', null, '1.7.0', true, true],
            'remote, an unknown mode' => [$r, $r, 'starttls', null, '1.7.7', true, false],
            'remote, tls on 1.7.7' => [$r, $r, 'tls', null, '1.7.7', true, true],
            'remote, tls on 1.7.8' => [$r, $r, 'tls', null, '1.7.8', true, true],
            'remote, tls on 1.8.0' => [$r, $r, 'tls', null, '1.8.0', true, true],
            'remote, tls on 1.7.6' => [$r, $r, 'tls', null, '1.7.6', true, false],
            'remote, tls on 1.7.0' => [$r, $r, 'tls', null, '1.7.0', true, false],
            'remote, tls on 1.7-git' => [$r, $r, 'tls', null, '1.7-git', true, false],
            'remote, tls on an unknown version' => [$r, $r, 'tls', null, '', true, false],
            'remote, ssl without openssl' => [$r, $r, 'ssl', null, '1.7.7', false, false],
            // certificate verification
            'verify_peer false' => [$r, $r, 'ssl', ['ssl' => ['verify_peer' => false]], '1.7.7', true, false],
            'verify_peer_name 0' => [$r, $r, 'ssl', ['ssl' => ['verify_peer_name' => 0]], '1.7.7', true, false],
            'allow_self_signed' => [$r, $r, 'ssl', ['ssl' => ['allow_self_signed' => true]], '1.7.7', true, false],
            'verify_peer true, a CA file' => [$r, $r, 'ssl', ['ssl' => ['verify_peer' => true, 'cafile' => '/etc/ssl/certs/ca.pem']], '1.7.7', true, true],
            'options of another host' => [$r, $r, 'ssl', ['imap.example.org' => ['ssl' => ['verify_peer' => false]]], '1.7.7', true, true],
            'options of this host' => [$r, $r, 'ssl', [$r => ['ssl' => ['verify_peer' => false]]], '1.7.7', true, false],
            'options of this host replace the general ones' => [$r, $r, 'ssl', [$r => ['ssl' => ['cafile' => '/etc/ssl/certs/ca.pem']], 'ssl' => ['verify_peer' => false]], '1.7.7', true, true],
            'tls on 1.7.7, verification off' => [$r, $r, 'tls', ['ssl' => ['verify_peer' => false]], '1.7.7', true, false],
            'options that are no array' => [$r, $r, 'ssl', 'nonsense', '1.7.7', true, true],
        ];
    }

    #[DataProvider('transports')]
    public function testTransportProblemMatrix(string $host, string $raw, string $ssl, mixed $options, string $version, bool $openssl, bool $accepted): void
    {
        $problem = ViewerResolver::transportProblem($host, $raw, $ssl, $options, $version, $openssl);
        if ($accepted) {
            self::assertNull($problem);
            return;
        }
        self::assertIsString($problem);
        self::assertNotSame('', $problem);
    }

    public function testTheReasonSaysWhatToChange(): void
    {
        self::assertStringContainsString('not encrypted', (string) ViewerResolver::transportProblem(self::REMOTE, self::REMOTE, '', null, '1.7.7', true));
        self::assertStringContainsString('use ssl://host:993 or Roundcube 1.7.7 or later', (string) ViewerResolver::transportProblem(self::REMOTE, self::REMOTE, 'tls', null, '1.7.6', true));
        self::assertStringContainsString('openssl', (string) ViewerResolver::transportProblem(self::REMOTE, self::REMOTE, 'ssl', null, '1.7.7', false));
        self::assertStringContainsString('verification', (string) ViewerResolver::transportProblem(self::REMOTE, self::REMOTE, 'ssl', ['ssl' => ['verify_peer' => false]], '1.7.7', true));
    }

    /**
     * The new switch is a documented option with its built-in default (and every option of the
     * configuration template has exactly the default of Config::DEFAULTS).
     */
    public function testTheConfigurationTemplateDocumentsTheSwitch(): void
    {
        $config = (static function (string $file): array {
            $config = [];
            include $file;
            return $config;
        })(dirname(__DIR__, 2) . '/config.inc.php.dist');
        self::assertFalse(\GalBridge\Config::DEFAULTS['galbridge_imap_allow_plaintext']);
        self::assertContains('galbridge_imap_allow_plaintext', \GalBridge\Config::BOOLEANS);
        foreach (\GalBridge\Config::DEFAULTS as $name => $default) {
            self::assertArrayHasKey($name, $config);
            self::assertSame($default, $config[$name], $name);
        }
        self::assertSame([], array_values(array_diff(array_keys($config), array_keys(\GalBridge\Config::DEFAULTS))));
    }

    public function testLocalEndpoints(): void
    {
        foreach (['unix:///run/dovecot/imap.sock', 'localhost', '127.0.0.1', '127.255.255.254', '::1'] as $local) {
            self::assertTrue(ViewerResolver::isLocalEndpoint($local), $local);
        }
        foreach (['mail.example.net', 'localhost.example.net', '128.0.0.1', '::2', '::ffff:7f00:1', 'unix', ''] as $remote) {
            self::assertFalse(ViewerResolver::isLocalEndpoint($remote), $remote);
        }
    }
}
