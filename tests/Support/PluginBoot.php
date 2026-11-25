<?php

declare(strict_types=1);

/**
 * GAL Bridge - the real galbridge plugin class in a logged-in webmail request, without a full
 * rcmail startup (the harness of PluginHooksTest, shared by the test files of the second remediation
 * pass that need the plugin's own wiring).
 *
 * boot() builds rcmail (configuration, user, task, action, output, optionally a storage object),
 * a plugin API whose plugin directory holds galbridge/localization only, constructs the plugin and
 * runs init(). restore() puts the previous rcube singleton back and removes the directory (call it
 * in tearDown()).
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Tests\Support;

use GalBridge\Config;

final class PluginBoot
{
    public \rcube_plugin_api $api;

    public \rcmail $rc;

    public \galbridge $plugin;

    /** @var list<array{0: string, 1: string}> messages shown to the user (label, type) */
    public array $messages = [];

    private mixed $previous;

    private string $dir = '';

    public function __construct()
    {
        require_once dirname(__DIR__, 2) . '/galbridge.php';
        $this->previous = (new \ReflectionProperty(\rcube::class, 'instance'))->getValue();
    }

    /**
     * @param array<string, mixed> $admin   plugin and core options (administrator)
     * @param array<string, mixed> $session $_SESSION of the request (storage_host ... by default)
     * @param null|\rcube_storage  $storage the storage object of the request (null: an IMAP connection
     *                                      that is open already, as in a mail request - the IMAP
     *                                      check of a renewed Viewer asks nothing more)
     * @param bool                 $inject  inject a Config built from $admin (true), or let the plugin
     *                                      build its own from Roundcube's configuration and its
     *                                      directory home() (false: the production path)
     */
    public function boot(array $admin, string $task = 'mail', string $action = 'show', array $session = [], ?\rcube_storage $storage = null, string $login = CliRun::JAN, bool $inject = true): \galbridge
    {
        $this->home();
        $_SESSION = $session + ['storage_host' => Env::IMAP_HOST, 'storage_port' => Env::IMAP_PORT];
        $config = new \rcube_config();
        foreach ($admin as $k => $v) {
            $config->set($k, $v);
        }
        $this->api = (new \ReflectionClass(\rcube_plugin_api::class))->newInstanceWithoutConstructor();
        $this->api->dir = $this->dir . '/';
        $messages = &$this->messages;
        $messages = [];
        $output = new class ($messages) extends \rcmail_output_json {
            /** @var list<array{0: string, 1: string}> */
            private array $shown;

            /**
             * @param list<array{0: string, 1: string}> $shown
             */
            public function __construct(array &$shown)
            {
                $this->shown = &$shown;
            }

            /**
             * @param string                    $message
             * @param string                    $type
             * @param null|array<string, mixed> $vars
             * @param bool                      $override
             * @param int                       $timeout
             */
            #[\Override]
            public function show_message($message, $type = 'notice', $vars = null, $override = true, $timeout = 0): void
            {
                $this->shown[] = [(string) $message, (string) $type];
            }
        };
        $this->rc = (new \ReflectionClass(\rcmail::class))->newInstanceWithoutConstructor();
        $this->rc->config = $config;
        $this->rc->task = $task;
        $this->rc->action = $action;
        $this->rc->plugins = $this->api;
        $this->rc->output = $output;
        $this->rc->storage = $storage ?? self::connectedStorage();
        $user = (new \ReflectionClass(\rcube_user::class))->newInstanceWithoutConstructor();
        $user->ID = 7;
        $user->data = ['user_id' => 7, 'username' => $login, 'mail_host' => Env::IMAP_HOST, 'preferences' => serialize([])];
        $user->prefs = [];
        $this->rc->user = $user;
        (new \ReflectionProperty(\rcube::class, 'instance'))->setValue(null, $this->rc);

        $this->plugin = new \galbridge($this->api);
        if ($inject) {
            (new \ReflectionProperty(\galbridge::class, 'config'))->setValue($this->plugin, new Config($config, [], $admin));
        }
        $this->plugin->init();
        return $this->plugin;
    }

    /**
     * The plugin's directory of the request (rcube_plugin::$home): a private directory with the
     * localization; a test may put a config.inc.php there before boot().
     */
    public function home(): string
    {
        if ($this->dir === '') {
            $this->dir = CliRun::tempBase('galbridge-r2plugins');
            mkdir($this->dir . '/galbridge', 0o700);
            symlink(dirname(__DIR__, 2) . '/localization', $this->dir . '/galbridge/localization');
        }
        return $this->dir . '/galbridge';
    }

    /**
     * The ViewerResolver the plugin builds for this request (its factory, created on first use).
     */
    public function resolver(): \GalBridge\Directory\ViewerResolver
    {
        $factory = (new \ReflectionMethod(\galbridge::class, 'factory'))->invoke($this->plugin);
        if (!$factory instanceof \GalBridge\Factory) {
            throw new \LogicException('the plugin built no factory');
        }
        return $factory->resolver();
    }

    /**
     * Roundcube's IMAP storage with the connection of the request open.
     */
    public static function connectedStorage(): \rcube_imap
    {
        return new class () extends \rcube_imap {
            public function __construct()
            {
            }

            #[\Override]
            public function is_connected()
            {
                return true;
            }
        };
    }

    public function restore(): void
    {
        (new \ReflectionProperty(\rcube::class, 'instance'))->setValue(null, $this->previous);
        if ($this->dir !== '') {
            CliRun::rmTree($this->dir);
            $this->dir = '';
        }
    }
}
