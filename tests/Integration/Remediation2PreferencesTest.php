<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\Log;
use GalBridge\Tests\Support\CliRun;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\PluginBoot;
use PHPUnit\Framework\TestCase;

/**
 * Second remediation pass, F-09: Roundcube writes the current value of every option listed in
 * dont_override into the preferences of a user who saves settings - for an option set through a
 * ROUNDCUBE_GALBRIDGE_* variable that is its value, galbridge_db_dsn with the database password
 * included (SECURITY.md recommended listing galbridge_* options there). The plugin is now loaded in
 * the Settings task for one hook only, preferences_update, which removes every galbridge_* key from
 * what is saved and from the stored preferences it is merged with: nothing of the kind is stored,
 * and copies saved before disappear at the next save.
 *
 * Every test fails on 1.0.1 (the plugin is not loaded in the Settings task and has no such hook).
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2PreferencesTest extends TestCase
{
    /** what Roundcube would store for dont_override (synthetic) */
    private const DSN = 'mysql://galbridge:Zq-stored-pw@127.0.0.1/vmail';

    private ?PluginBoot $boot = null;

    private string $base = '';

    protected function setUp(): void
    {
        CliRun::roundcube();
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
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

    public function testThePluginIsLoadedInSettingsOnlyToDropItsPreferences(): void
    {
        self::assertSame('mail|addressbook|settings', (new \ReflectionClass(\galbridge::class))->getDefaultProperties()['task'] ?? null);
        $this->boot = new PluginBoot();
        $plugin = $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn(), 'galbridge_global_book' => true], 'settings', 'preferences');
        self::assertSame(['preferences_update'], array_keys($this->boot->api->handlers));
        self::assertSame([[$plugin, 'preferences_update']], $this->boot->api->handlers['preferences_update']);
        // the mail and address book tasks are unchanged
        $this->boot->boot(['galbridge_db_dsn' => Env::sqliteDsn()], 'mail', 'show');
        self::assertArrayNotHasKey('preferences_update', $this->boot->api->handlers);
    }

    public function testPreferencesUpdateDropsEveryGalbridgeKey(): void
    {
        $this->boot = new PluginBoot();
        $plugin = $this->boot->boot([], 'settings', 'save-prefs');
        $p = $plugin->preferences_update([
            'userid' => 7,
            'prefs' => ['galbridge_db_dsn' => self::DSN, 'language' => 'en_US', 'galbridge_global_book' => true],
            'old' => ['galbridge_hidden_addresses' => [], 'timezone' => 'auto', 0 => 'kept'],
        ]);
        self::assertSame(['userid' => 7, 'prefs' => ['language' => 'en_US'], 'old' => ['timezone' => 'auto', 0 => 'kept']], $p);
        // what Roundcube hands every handler, merged as rcube_plugin_api does it
        $merged = $this->boot->api->exec_hook('preferences_update', ['userid' => 7, 'prefs' => ['galbridge_db_dsn' => self::DSN], 'old' => ['galbridge_debug' => true, 'skin' => 'elastic']]);
        self::assertSame([], $merged['prefs']);
        self::assertSame(['skin' => 'elastic'], $merged['old']);
        self::assertSame($p, $plugin->preferences_update($p), 'nothing else is touched');
    }

    /**
     * rcube_user::save_prefs() as Roundcube's Settings save calls it, against Roundcube's own
     * database: the DSN that dont_override would add is not stored, and a copy stored before is gone.
     */
    public function testRoundcubeStoresNoGalbridgeValueWhenSettingsAreSaved(): void
    {
        $this->boot = new PluginBoot();
        $this->boot->boot(['dont_override' => ['galbridge_db_dsn']], 'settings', 'save-prefs');
        $this->base = CliRun::tempBase('galbridge-r2prefs');
        $file = CliRun::roundcubeDb($this->base);
        $pdo = new \PDO('sqlite:' . $file);
        $pdo->exec("INSERT INTO users (user_id, username, mail_host, created, preferences) VALUES (7, 'jan@example.com', '127.0.0.1', '2026-01-01 00:00:00', "
            . $pdo->quote(serialize(['galbridge_db_dsn' => self::DSN, 'timezone' => 'Europe/Warsaw'])) . ')');

        $user = (new \ReflectionClass(\rcube_user::class))->newInstanceWithoutConstructor();
        $user->ID = 7;
        $user->data = ['user_id' => 7, 'username' => 'jan@example.com', 'mail_host' => '127.0.0.1'];
        $user->prefs = ['galbridge_db_dsn' => self::DSN, 'timezone' => 'Europe/Warsaw'];
        $db = \rcube_db::factory('sqlite:///' . $file . '?mode=0600');
        $db->set_debug(false);
        (new \ReflectionProperty(\rcube_user::class, 'db'))->setValue($user, $db);
        (new \ReflectionProperty(\rcube_user::class, 'rc'))->setValue($user, $this->boot->rc);
        $this->boot->rc->user = $user;

        // what prefs_save hands over for a dont_override option set through the environment
        self::assertTrue($user->save_prefs(['language' => 'en_US', 'galbridge_db_dsn' => self::DSN], true));
        $stored = (string) $pdo->query('SELECT preferences FROM users WHERE user_id = 7')->fetchColumn();
        self::assertStringNotContainsString('galbridge_', $stored);
        self::assertStringNotContainsString('Zq-stored-pw', $stored);
        self::assertSame(['timezone' => 'Europe/Warsaw'], unserialize($stored, ['allowed_classes' => false]));
        $db->closeConnection();
        $pdo = null;
    }

    /**
     * In the Settings task the plugin reads no configuration, loads no texts and never connects: an
     * unusable configuration changes nothing there.
     */
    public function testTheSettingsTaskTouchesNeitherConfigNorDatabase(): void
    {
        $this->boot = new PluginBoot();
        $plugin = $this->boot->boot(['galbridge_db_dsn' => 'pgsql://galbridge_ro:testonly-ro@127.0.0.1:1/galbridge_vmail_test', 'galbridge_hiden_localparts' => [], 'galbridge_global_book' => 'false'], 'settings', 'preferences', [], null, CliRun::JAN, false);
        self::assertSame([], $GLOBALS['galbridge_test_log']);
        foreach (['config', 'factory', 'connection'] as $prop) {
            self::assertNull((new \ReflectionProperty(\galbridge::class, $prop))->getValue($plugin), $prop);
        }
        self::assertArrayNotHasKey('galbridge', $_SESSION);
        self::assertSame([], (new \ReflectionProperty(\rcube_plugin::class, 'loaded_config'))->getValue($plugin), 'no configuration file loaded');
    }
}
