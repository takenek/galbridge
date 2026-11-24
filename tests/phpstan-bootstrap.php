<?php

declare(strict_types=1);

/**
 * GAL Bridge - PHPStan bootstrap.
 *
 * Defines the global constants that Roundcube defines at runtime (iniset.php / bootstrap.php /
 * clisetup.php) so PHPStan can resolve them. Roundcube classes themselves are discovered through
 * scanDirectories in phpstan.neon.dist. Nothing here is executed in production.
 *
 * The Roundcube installation used for analysis is taken from the environment variable GALBRIDGE_RC
 * (default /opt/rcrun/1.7.7, a tree of the supported 1.7 branch); see phpstan.neon.dist.
 *
 * @license GPL-3.0-or-later
 */

$rc = getenv('GALBRIDGE_RC');
$rc = is_string($rc) && $rc !== '' ? rtrim($rc, '/') . '/' : '/opt/rcrun/1.7.7/';

foreach ([
    'INSTALL_PATH' => $rc,
    'RCUBE_INSTALL_PATH' => $rc,
    'RCUBE_CONFIG_DIR' => $rc . 'config/',
    'RCUBE_PLUGINS_DIR' => $rc . 'plugins/',
    'RCUBE_LOCALIZATION_DIR' => $rc . 'program/localization/',
    'RCMAIL_VERSION' => '1.7.7',
    'RCUBE_VERSION' => '1.7.7',
    'RCUBE_CHARSET' => 'UTF-8',
    'JS_OBJECT_NAME' => 'rcmail',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

// Do NOT require lib/autoload.php here: plugin classes extend Roundcube classes (rcube_addressbook)
// that are only known statically; PHPStan discovers the analysed files itself.
