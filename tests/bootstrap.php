<?php

declare(strict_types=1);

/**
 * GAL Bridge - PHPUnit bootstrap.
 *
 * Unit tests need only lib/. Integration tests additionally load Roundcube's library (rcube_db,
 * rcube_addressbook, rcube_config ...) from the Roundcube 1.7.x installation given in the
 * environment variable GALBRIDGE_RC (e.g. GALBRIDGE_RC=/opt/rcrun/1.7.7). They always run against
 * the SQLite mirror of the vmail fixture, and additionally against the throw-away MariaDB/MySQL and
 * PostgreSQL databases named by GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN
 * (tests/fixtures/vmail/load.sh prints them).
 *
 * @license GPL-3.0-or-later
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../lib/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'GalBridge\\Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('GalBridge\\Tests\\'))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// the SQLite driver is accepted only here (the fixture mirror); production allows mysql/pgsql only
define('GALBRIDGE_ALLOW_SQLITE', true);

$rc = getenv('GALBRIDGE_RC');
if (is_string($rc) && $rc !== '' && is_file($rc . '/program/lib/Roundcube/bootstrap.php')) {
    define('GALBRIDGE_RC_LOADED', true);
    if (!defined('RCUBE_INSTALL_PATH')) {
        define('RCUBE_INSTALL_PATH', rtrim($rc, '/') . '/');
    }
    if (!defined('RCUBE_CONFIG_DIR')) {
        define('RCUBE_CONFIG_DIR', RCUBE_INSTALL_PATH . 'config/');
    }
    if (!defined('RCUBE_PLUGINS_DIR')) {
        define('RCUBE_PLUGINS_DIR', RCUBE_INSTALL_PATH . 'plugins/');
    }
    if (!defined('RCUBE_LOCALIZATION_DIR')) {
        define('RCUBE_LOCALIZATION_DIR', RCUBE_INSTALL_PATH . 'program/localization/');
    }
    require_once RCUBE_INSTALL_PATH . 'vendor/autoload.php';
    require_once RCUBE_INSTALL_PATH . 'program/lib/Roundcube/bootstrap.php';
} else {
    define('GALBRIDGE_RC_LOADED', false);
}

// keep test logs out of the Roundcube log
GalBridge\Log::setSink(static function (string $line): void {
    $GLOBALS['galbridge_test_log'][] = $line;
});
