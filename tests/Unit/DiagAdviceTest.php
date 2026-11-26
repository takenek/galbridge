<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Cli\Tool;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.5: diag's advice on the connection limit of the role measures it against the server's
 * connections (README 2.2): the 1.0.3 advice gave iRedMail installations a limit of 100 against
 * max_connections of 151 (MariaDB) and 100 (PostgreSQL), which diag reported as OK.
 *
 * @license GPL-3.0-or-later
 */
final class DiagAdviceTest extends TestCase
{
    public function testTheConnectionLimitIsMeasuredAgainstTheServersConnections(): void
    {
        // the default and anything up to a quarter of the server: fine
        self::assertNull(Tool::connectionShareProblem(25, 97));
        self::assertNull(Tool::connectionShareProblem(25, 20));
        self::assertNull(Tool::connectionShareProblem(37, 151));
        self::assertNull(Tool::connectionShareProblem(250, 1024));
        // unknown server value: nothing to compare with
        self::assertNull(Tool::connectionShareProblem(1000, null));
        self::assertNull(Tool::connectionShareProblem(1000, 0));
        // iRedMail with the 1.0.3 advice
        $mariadb = Tool::connectionShareProblem(100, 151);
        self::assertIsString($mariadb);
        self::assertStringStartsWith('100 of the 151 connections the server has for ordinary roles (max_connections)', $mariadb);
        self::assertStringContainsString('galbridge.sh grants --grants-only --connection-limit=25', $mariadb);
        self::assertStringContainsString('README 2.2', $mariadb);
        self::assertStringStartsWith('100 of the 97 connections', (string) Tool::connectionShareProblem(100, 97));
        self::assertIsString(Tool::connectionShareProblem(38, 151));
    }
}
