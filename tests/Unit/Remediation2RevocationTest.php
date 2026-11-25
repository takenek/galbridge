<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Directory\ViewerResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-17: when the cached Viewer is renewed the IMAP server confirms the
 * credentials of the session. ViewerResolver::imapVerdict() reads what Roundcube's IMAP client says
 * after a connection attempt: connected = accepted; NO with an RFC 5530 code that refuses the
 * credentials (AUTHENTICATIONFAILED, AUTHORIZATIONFAILED, EXPIRED) = refused; anything else -
 * unreachable, NO [UNAVAILABLE], BYE, a server without codes - cannot be told and is no revocation.
 *
 * Fails on 1.0.1 (no such check).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2RevocationTest extends TestCase
{
    /**
     * @return array<string, array{bool, null|int, null|string, null|bool}>
     */
    public static function answers(): array
    {
        return [
            'connected' => [true, 0, null, true],
            'connected, a stale code' => [true, -1, 'AUTHENTICATIONFAILED', true],
            'wrong password' => [false, -1, 'AUTHENTICATIONFAILED', false],
            'code in lower case' => [false, -1, 'authenticationfailed', false],
            'not allowed (disabled IMAP service)' => [false, -1, 'AUTHORIZATIONFAILED', false],
            'password expired' => [false, -1, 'EXPIRED', false],
            'temporary failure' => [false, -1, 'UNAVAILABLE', null],
            'NO without a code' => [false, -1, null, null],
            'another code' => [false, -1, 'ALERT', null],
            'BAD' => [false, -2, 'AUTHENTICATIONFAILED', null],
            'BYE' => [false, -3, null, null],
            'not connected at all' => [false, null, null, null],
            'connection error' => [false, -4, null, null],
        ];
    }

    #[DataProvider('answers')]
    public function testImapVerdictMapsTheServerAnswer(bool $connected, ?int $errornum, ?string $code, ?bool $expected): void
    {
        self::assertSame($expected, ViewerResolver::imapVerdict($connected, $errornum, $code));
    }
}
