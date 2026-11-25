<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
 * Original author: Marek Królikowski (TaKeN)
 * Original project: https://github.com/takenek/galbridge
 * SPDX-License-Identifier: GPL-3.0-or-later
 * See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
 *
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge;

/**
 * Typed access to the plugin configuration (config.inc.php) with safe defaults.
 *
 * Every option documented in config.inc.php.dist has its default here. Values are validated,
 * normalised and clamped, so a broken configuration cannot widen what users see:
 *  - an invalid entry in an ALLOW-list is dropped (ERROR, reported by diag),
 *  - an invalid DENY-list (or an overlap of organizations) disables what it protects (fail closed),
 *  - '*' is honoured only as the sole element of a list,
 *  - a switch must be the boolean true or false (or the integer 1 or 0); anything else - a string
 *    such as 'false', an array - is never cast: it is reported, disables every book (fail closed)
 *    and is read as its restrictive value (RESTRICTIVE),
 *  - the visibility column must be a string: another type never means "no column",
 *  - a number must be a whole number (an integer, or a string of digits) within the range of RANGES:
 *    another value is reported and replaced by the default, one outside the range is reported and
 *    clamped,
 *  - the built-in refused database roles cannot be removed, and a malformed galbridge_db_refused_users
 *    refuses every database connection,
 *  - the configuration must be the one the administrator wrote (configUnreliable()): a plugin
 *    configuration file that exists but does not load, a second plugin configuration file that
 *    Roundcube ignores, a galbridge_* option or ROUNDCUBE_GALBRIDGE_* variable this version does not
 *    know (a typo leaves the protecting default in force) and, with include_host_config, an option
 *    that depends on the requested host name each disable every book.
 *
 * Problems name the option and, for a rejected list entry, its position - never the entry: a
 * rejected entry of a hidden list is a person the administrator wanted hidden.
 *
 * Roundcube applies environment overrides (ROUNDCUBE_<OPTION>) on every read and turns the text
 * "false" into the boolean true. For switches this class therefore reads the variable itself and
 * accepts only 1, 0, true and false (case-insensitive); any other spelling fails closed as well.
 *
 * Roundcube merges stored user preferences over the configuration (rcube_config::merge). No
 * galbridge_* option is a user preference: a stored preference with such a name is ignored and the
 * administrator's value is read from a SECOND reading of the configuration files (a fresh
 * rcube_config plus the plugin's own files, loaded by pluginFiles()/loadFiles() exactly as
 * rcube_plugin::load_config() does it).
 *
 * That second reading is not always what Roundcube applies: a file that the configuration pulls in
 * with include_once/require_once runs once per process (the second reading misses its values), and
 * an environment-specific file ("config-<env>.inc.php") is chosen by an environment name that only
 * Roundcube's own object knows. So the options of the core (imap_host, des_key, db_dsnw ...) are read
 * from Roundcube's own configuration object (coreValue()), the second reading only for a name that
 * a stored preference shadows; divergentOptions() names what the two readings disagree on (diag),
 * and a derived IMAP binding is refused when they disagree on imap_host (ViewerResolver).
 *
 * With include_host_config Roundcube also loads a file chosen by the Host header of the request -
 * by the client. No galbridge_* option (except the two book names) may depend on it:
 * hostDependentOptions() compares the options of the request with the host-independent files and
 * disables every book when one differs.
 */
final class Config
{
    public const DEFAULTS = [
        // vmail database
        'galbridge_db_dsn' => '',
        'galbridge_db_connect_timeout' => 2,
        'galbridge_db_timeout' => 3,
        'galbridge_db_allow_plaintext' => false,
        'galbridge_db_allow_verify_ca' => false,
        'galbridge_db_refused_users' => ['vmail', 'vmailadmin', 'roundcube', 'sogo', 'iredadmin', 'iredapd', 'amavisd', 'fail2ban', 'root', 'postgres'],
        // who is who
        'galbridge_imap_hosts' => [],
        'galbridge_imap_allow_plaintext' => false,
        'galbridge_scope_ttl' => 300,
        // domain book
        'galbridge_domain_book' => true,
        'galbridge_domain_book_name' => '',
        'galbridge_domain_viewer_domains' => ['*'],
        'galbridge_domain_viewer_domains_exclude' => [],
        'galbridge_domain_groups' => 'auto',
        'galbridge_domain_searchonly' => false,
        'galbridge_domain_autocomplete' => true,
        'galbridge_domain_export' => true,
        'galbridge_organizations' => [],
        // global book
        'galbridge_global_book' => false,
        'galbridge_global_book_name' => '',
        'galbridge_global_viewer_domains' => [],
        'galbridge_global_viewer_domains_exclude' => [],
        'galbridge_global_data_domains' => [],
        'galbridge_global_data_domains_exclude' => [],
        'galbridge_global_allow_wildcard' => false,
        'galbridge_global_include_home' => false,
        'galbridge_global_groups' => 'none',
        'galbridge_global_searchonly' => true,
        'galbridge_global_autocomplete' => true,
        'galbridge_global_export' => false,
        'galbridge_searchonly_threshold' => 2000,
        // content
        'galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department'],
        'galbridge_user_aliases' => false,
        'galbridge_include_aliases' => true,
        'galbridge_include_mailing_lists' => true,
        'galbridge_list_policy' => 'strict',
        'galbridge_hide_expired' => true,
        'galbridge_hidden_addresses' => [],
        'galbridge_hidden_localparts' => [],
        'galbridge_visibility_column' => '',
        'galbridge_mark_foreign_domains' => true,
        // limits
        'galbridge_min_search_length' => 2,
        'galbridge_max_search_length' => 128,
        'galbridge_max_results' => 500,
        'galbridge_global_max_results' => 100,
        'galbridge_max_ids' => 200,
        'galbridge_max_group_members' => 500,
        'galbridge_max_groups' => 50,
        'galbridge_max_export' => 2000,
        'galbridge_max_count' => 10000,
        // logging
        'galbridge_debug' => false,
    ];

    /** fields known to FieldMap (galbridge_fields allowlist) */
    public const FIELDS = ['name', 'firstname', 'surname', 'email', 'department', 'phone', 'mobile', 'organization', 'birthday'];

    public const GROUPINGS = ['auto', 'department', 'domain', 'none'];

    public const LIST_POLICIES = ['strict', 'public_only', 'all'];

    /** switches: the boolean true or false only (or the integers 1 / 0) */
    public const BOOLEANS = [
        'galbridge_db_allow_plaintext', 'galbridge_db_allow_verify_ca', 'galbridge_imap_allow_plaintext',
        'galbridge_domain_book', 'galbridge_domain_autocomplete', 'galbridge_domain_export',
        'galbridge_global_book', 'galbridge_global_allow_wildcard', 'galbridge_global_include_home',
        'galbridge_global_autocomplete', 'galbridge_global_export',
        'galbridge_user_aliases', 'galbridge_include_aliases', 'galbridge_include_mailing_lists',
        'galbridge_hide_expired', 'galbridge_mark_foreign_domains', 'galbridge_debug',
    ];

    /**
     * What every switch is read as while its value is ambiguous (bool()): the value that shows and
     * permits the least - off, except the two switches that hide or mark entries when on. Every book
     * is disabled meanwhile anyway (booksDisabled()); this is the second line, so that no code path
     * that consults a switch on its own ever reads an ambiguous value as permission. One entry per
     * name of BOOLEANS (Remediation2DocumentationTest).
     */
    public const RESTRICTIVE = [
        'galbridge_db_allow_plaintext' => false,
        'galbridge_db_allow_verify_ca' => false,
        'galbridge_imap_allow_plaintext' => false,
        'galbridge_domain_book' => false,
        'galbridge_domain_autocomplete' => false,
        'galbridge_domain_export' => false,
        'galbridge_global_book' => false,
        'galbridge_global_allow_wildcard' => false,
        'galbridge_global_include_home' => false,
        'galbridge_global_autocomplete' => false,
        'galbridge_global_export' => false,
        'galbridge_user_aliases' => false,
        'galbridge_include_aliases' => false,
        'galbridge_include_mailing_lists' => false,
        'galbridge_hide_expired' => true,
        'galbridge_mark_foreign_domains' => true,
        'galbridge_debug' => false,
    ];

    /** switches that also accept the string 'auto' */
    public const TRISTATES = ['galbridge_domain_searchonly', 'galbridge_global_searchonly'];

    /**
     * The range of every numeric option (README 3.8 / 3.9): int() reports a value outside it and
     * clamps it. Every caller of int() passes exactly this range (Remediation2ConfigRangesTest).
     */
    public const RANGES = [
        'galbridge_db_connect_timeout' => [1, 30],
        'galbridge_db_timeout' => [1, 30],
        'galbridge_scope_ttl' => [30, 3600],
        'galbridge_searchonly_threshold' => [1, 1000000],
        'galbridge_min_search_length' => [1, 10],
        'galbridge_max_search_length' => [16, 255],
        'galbridge_max_results' => [10, 5000],
        'galbridge_global_max_results' => [10, 5000],
        'galbridge_max_ids' => [1, 1000],
        'galbridge_max_group_members' => [1, 100000],
        'galbridge_max_groups' => [1, 500],
        'galbridge_max_export' => [1, 100000],
        'galbridge_max_count' => [100, 1000000],
    ];

    /**
     * galbridge_* options that a former release knew and this one does not (name => what to do): set,
     * they are reported (error) instead of being treated as an unknown option, which disables every
     * book. A release that removes an option adds it here.
     *
     * @var array<string, string>
     */
    public const RETIRED = [];

    /**
     * Options of Roundcube itself that this plugin reads (coreValue()) and whose two readings
     * divergentOptions() compares, next to every galbridge_* option.
     */
    public const CORE_OPTIONS = ['imap_host', 'include_host_config', 'imap_conn_options', 'des_key', 'db_dsnw', 'db_dsnr', 'password_db_dsn', 'plugins'];

    /**
     * The only galbridge_* options that may differ per requested host name (include_host_config):
     * labels, no policy.
     */
    public const HOST_COSMETIC = ['galbridge_domain_book_name', 'galbridge_global_book_name'];

    private const ADMIN_ONLY_PREFIX = 'galbridge_';

    /** rcube_config::$legacy_props for the options read here: a preference of the old name sets the new one */
    private const LEGACY_NAMES = ['imap_host' => 'default_host'];

    /** marks an option no host-independent file sets (a string: Roundcube's environment parsing stays string-typed) */
    private const UNSET = "\0galbridge:unset\0";

    /** @var null|array<string, mixed> */
    private ?array $adminValues = null;

    /** the administrator values were injected (tests): they stand for every second reading */
    private readonly bool $injected;

    /** a configuration without user preferences, for options that no configuration file sets */
    private ?\rcube_config $adminConfig = null;

    /** @var list<string> plugin configuration files that exist but did not load into $adminConfig */
    private array $unloaded = [];

    /** @var list<array{level: string, option: string, message: string}> */
    private array $problems = [];

    /** @var array<string, mixed> memo of normalised values */
    private array $memo = [];

    /**
     * @param array<string, mixed>      $userPrefs the logged-in user's stored preferences
     * @param null|array<string, mixed> $admin     the values of the configuration files as the
     *                                             administrator wrote them (tests); null = read them
     *                                             again lazily. Injected, they stand for every second
     *                                             reading of this class: the one without preferences
     *                                             and the host-independent one (hostDependentOptions())
     * @param null|string               $pluginDir the directory of this plugin (rcube_plugin::$home);
     *                                             null = <plugins directory>/galbridge
     */
    public function __construct(private readonly \rcube_config $config, private readonly array $userPrefs = [], ?array $admin = null, private readonly ?string $pluginDir = null)
    {
        $this->adminValues = $admin;
        $this->injected = $admin !== null;
    }

    /**
     * The directory Roundcube loads plugins from, with exactly one trailing slash - what the core
     * builds the directory of a plugin from (rcube_plugin_api: slashify(RCUBE_PLUGINS_DIR)), so a
     * RCUBE_PLUGINS_DIR defined without the slash finds the same files. '' when unknown.
     */
    public static function pluginsDir(): string
    {
        $dir = defined('RCUBE_PLUGINS_DIR') ? (string) constant('RCUBE_PLUGINS_DIR') : (defined('INSTALL_PATH') ? rtrim((string) constant('INSTALL_PATH'), '/') . '/plugins' : '');
        return $dir === '' ? '' : rtrim($dir, '/') . '/';
    }

    /**
     * The directory of this plugin.
     */
    public function pluginDir(): string
    {
        return $this->pluginDir ?? self::pluginsDir() . 'galbridge';
    }

    /**
     * THE plugin-file routine (one place for the core rule, used by the second reading, the
     * password plugin's DSN, hostDependentOptions() and diag): the configuration files
     * rcube_plugin::load_config() loads for the plugin $name whose directory is $home -
     * "$home/config.inc.php" when that file exists (then nothing else), otherwise "<name>.inc.php"
     * from every configuration directory (RCUBE_CONFIG_PATH, config/), in Roundcube's order. The
     * paths are resolved by $resolver - Roundcube's configuration object of this process - so its
     * environment suffix ("config-<env>.inc.php") applies exactly as in the core.
     *
     * @return list<string> existing files, absolute paths
     */
    public static function pluginFiles(\rcube_config $resolver, string $home, string $name = 'galbridge'): array
    {
        $local = rtrim($home, '/') . '/config.inc.php';
        if (is_file($local)) {
            return array_values(array_filter($resolver->resolve_paths($local), 'is_file'));
        }
        return array_values(array_filter($resolver->resolve_paths($name . '.inc.php'), 'is_file'));
    }

    /**
     * Load files (from pluginFiles()) into a configuration object the way rcube_config does
     * (merged over what it holds). Returns the files that exist but did not load: unreadable, or
     * without a $config array. An unreadable file is not handed to rcube_config at all (it would
     * raise its PHP warning a second time - Roundcube raised it when it loaded the plugin).
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function loadFiles(\rcube_config $into, array $files): array
    {
        $failed = [];
        foreach ($files as $file) {
            if (!is_readable($file) || !$into->load_from_file($file)) {
                $failed[] = $file;
            }
        }
        return $failed;
    }

    /**
     * The plugin's configuration files as Roundcube treats them (rcube_plugin::load_config()):
     * 'read' - the files it loads (pluginFiles()); 'ignored' - galbridge.inc.php files of the
     * configuration directories that it does NOT load because plugins/galbridge/config.inc.php
     * exists; 'failed' - files of 'read' that exist but do not load (unreadable for this PHP user,
     * or without a $config array: Roundcube logs error 527 and goes on without them). Paths only.
     *
     * @return array{read: list<string>, ignored: list<string>, failed: list<string>}
     */
    public function configFiles(): array
    {
        if (!array_key_exists('config_files', $this->memo)) {
            $read = self::pluginFiles($this->config, $this->pluginDir());
            $ignored = [];
            if (is_file(rtrim($this->pluginDir(), '/') . '/config.inc.php')) {
                $ignored = array_values(array_diff(array_filter($this->config->resolve_paths('galbridge.inc.php'), 'is_file'), $read));
            }
            // the second reading loads exactly these files and keeps the ones that did not load
            $this->adminConfig();
            $this->memo['config_files'] = ['read' => $read, 'ignored' => $ignored, 'failed' => array_values(array_intersect($read, $this->unloaded))];
        }
        /** @var array{read: list<string>, ignored: list<string>, failed: list<string>} */
        return $this->memo['config_files'];
    }

    /**
     * Problems of the plugin's configuration files (configFiles()), each reported (fail): a file
     * Roundcube cannot load, and a galbridge.inc.php that it ignores next to
     * plugins/galbridge/config.inc.php. Both mean that the policy in effect is not the one the
     * administrator wrote - built-in defaults, or the other file: every book is disabled
     * (configUnreliable()). Not evaluated with injected administrator values (tests).
     *
     * @return list<string> the files concerned
     */
    public function fileProblems(): array
    {
        if (!array_key_exists('file_problems', $this->memo)) {
            $out = [];
            if (!$this->injected) {
                $files = $this->configFiles();
                foreach ($files['failed'] as $file) {
                    $out[] = $file;
                    $this->problem('fail', Log::sanitize(basename($file)), Log::sanitize($file) . (is_readable($file)
                        ? ' defines no $config array'
                        : ' cannot be read by the PHP user') . ': Roundcube skips it (error 527) and the plugin would run on built-in defaults - all books are disabled');
                }
                foreach ($files['ignored'] as $file) {
                    $out[] = $file;
                    $this->problem('fail', Log::sanitize(basename($file)), Log::sanitize($file) . ' is not read: Roundcube loads only ' . Log::sanitize(rtrim($this->pluginDir(), '/') . '/config.inc.php')
                        . ' when it exists - keep one of the two files - all books are disabled');
                }
            }
            $this->memo['file_problems'] = $out;
        }
        /** @var list<string> */
        return $this->memo['file_problems'];
    }

    /**
     * galbridge_* options and ROUNDCUBE_GALBRIDGE_* variables this version does not know - a typo
     * leaves the default of the intended option in force, for a deny-list "nothing hidden". Each is
     * reported (fail, with the closest valid name) and disables every book (configUnreliable()).
     * Looked at: the second reading of the configuration files and Roundcube's own configuration
     * object without the user's stored preferences (a user can never trigger this), and the
     * variables of the process; a variable counts only in the spelling Roundcube reads (upper case).
     * Names of RETIRED options are reported as errors only.
     *
     * @return list<string> the unknown names (sanitised)
     */
    public function unknownOptions(): array
    {
        if (!array_key_exists('unknown', $this->memo)) {
            $names = [];
            $own = array_diff_key($this->config->all(), $this->userPrefs);
            foreach ([...array_keys($this->adminValues()), ...array_keys($own)] as $key) {
                $key = (string) $key;
                if (str_starts_with($key, self::ADMIN_ONLY_PREFIX) && !array_key_exists($key, self::DEFAULTS)) {
                    $names[$key] = $key;
                }
            }
            foreach (array_keys(getenv()) as $var) {
                $var = (string) $var;
                if (stripos($var, 'ROUNDCUBE_' . self::ADMIN_ONLY_PREFIX) !== 0) {
                    continue;
                }
                $option = strtolower(substr($var, strlen('ROUNDCUBE_')));
                if (!array_key_exists($option, self::DEFAULTS) || $var !== 'ROUNDCUBE_' . strtoupper($option)) {
                    $names[$var] = $option;
                }
            }
            $out = [];
            foreach ($names as $name => $option) {
                $name = (string) $name;
                $variable = $name !== $option;
                $retired = self::retired($option);
                if ($retired !== null) {
                    $this->problem('error', Log::sanitize($name), 'is no longer an option of GAL Bridge (ignored): ' . $retired);
                    continue;
                }
                $out[] = Log::sanitize($name);
                if ($variable && array_key_exists($option, self::DEFAULTS)) {
                    $hint = ' (Roundcube reads only the spelling ROUNDCUBE_' . strtoupper($option) . ')';
                } else {
                    $near = self::nearestOption($option);
                    $hint = $near === null ? '' : ' (did you mean ' . ($variable ? 'ROUNDCUBE_' . strtoupper($near) : $near) . '?)';
                }
                $this->problem('fail', Log::sanitize($name), 'unknown ' . ($variable ? 'environment variable' : 'option') . $hint . ': it changes nothing, so the option you meant keeps its default - all books are disabled');
            }
            $this->memo['unknown'] = $out;
        }
        /** @var list<string> */
        return $this->memo['unknown'];
    }

    /**
     * Is the configuration of this request NOT the one the administrator wrote down? A plugin
     * configuration file that does not load or is ignored (fileProblems()), an unknown option or
     * variable (unknownOptions()), or - with include_host_config - an option that depends on the
     * requested host name (hostDependentOptions()). Every book is disabled, and nothing connects
     * (ViewerResolver).
     */
    public function configUnreliable(): bool
    {
        // every check runs, so that each problem is reported
        $files = $this->fileProblems() !== [];
        $unknown = $this->unknownOptions() !== [];
        return $this->hostDependentOptions() !== [] || $files || $unknown;
    }

    /**
     * The hint for a retired option, or null.
     */
    private static function retired(string $name): ?string
    {
        /** @var array<string, string> $retired */
        $retired = self::RETIRED;
        return $retired[$name] ?? null;
    }

    /**
     * The valid option closest to a misspelt one (at most three edits away), or null.
     */
    private static function nearestOption(string $name): ?string
    {
        if (strlen($name) > 255) {
            return null;
        }
        $best = null;
        $distance = 4;
        foreach (array_keys(self::DEFAULTS) as $option) {
            $d = levenshtein($name, $option);
            if ($d < $distance) {
                [$best, $distance] = [$option, $d];
            }
        }
        return $best;
    }

    /**
     * An option of Roundcube as Roundcube itself applies it in this process: read from its own
     * configuration object (every file it loaded - an environment-specific one, files pulled in
     * with include_once/require_once - and its ROUNDCUBE_<OPTION> variables). Only when a stored
     * preference of the user shadows the name (directly, or through its legacy name: default_host
     * for imap_host) the value comes from the second reading of the files, which never sees a
     * preference.
     */
    public function coreValue(string $name, mixed $default = null): mixed
    {
        if ($this->shadowed($name)) {
            $legacy = self::LEGACY_NAMES[$name] ?? null;
            return $this->adminCore($name, $legacy === null ? $default : $this->adminCore($legacy, $default));
        }
        return $this->config->get($name, $default);
    }

    /**
     * May Roundcube load a configuration file chosen by the Host header of the request? true when
     * any source says so: Roundcube's own object, the second reading of the files, or a
     * ROUNDCUBE_INCLUDE_HOST_CONFIG variable at all (Roundcube reads the option from its files
     * WITHOUT that variable, so with it set what the core does cannot be told). A preference cannot
     * change it: the core chooses the files before it knows the user.
     */
    public function hostConfigEnabled(): bool
    {
        return !empty($this->coreValue('include_host_config'))
            || !empty($this->adminCore('include_host_config'))
            || getenv('ROUNDCUBE_INCLUDE_HOST_CONFIG') !== false;
    }

    /**
     * The names (never the values) of the options - every galbridge_* option and CORE_OPTIONS - that
     * Roundcube's own configuration object and the second reading of the files disagree on: values
     * set in a file that the configuration includes only once (include_once/require_once), in an
     * environment-specific file, by a ROUNDCUBE_<OPTION> variable the second reading does not see,
     * or by another plugin. A name that a stored preference shadows cannot be compared (the second
     * reading is what is used for it) and is left out.
     *
     * @return list<string>
     */
    public function divergentOptions(): array
    {
        if (!array_key_exists('divergent', $this->memo)) {
            $out = [];
            foreach ([...array_keys(self::DEFAULTS), ...self::CORE_OPTIONS] as $name) {
                if ($this->shadowed($name)) {
                    continue;
                }
                $default = self::DEFAULTS[$name] ?? null;
                if ($this->config->get($name, $default) !== $this->readAgain($name, $default)) {
                    $out[] = $name;
                }
            }
            $this->memo['divergent'] = $out;
        }
        /** @var list<string> */
        return $this->memo['divergent'];
    }

    /**
     * Every DSN whose user GAL Bridge must never use: Roundcube's database (db_dsnw, db_dsnr) and the
     * password plugin's (password_db_dsn), from BOTH readings of the configuration and from the
     * password plugin's own file - a user refused by any reading is refused.
     *
     * @return list<string>
     */
    public function coreDsns(): array
    {
        $out = [];
        foreach (['db_dsnw', 'db_dsnr', 'password_db_dsn'] as $name) {
            $out[] = $this->config->get($name);
            $out[] = $this->readAgain($name, null);
        }
        $out[] = $this->passwordPluginDsn();
        return array_values(array_unique(array_filter($out, static fn (mixed $v): bool => is_string($v) && $v !== '')));
    }

    /**
     * galbridge_* options whose value in this request is not the one of the host-independent files
     * (only with include_host_config, see hostConfigEnabled()): set in a per-host configuration file -
     * chosen by the Host header, i.e. by the client - or in a file that the configuration includes
     * only once (the host-independent files are read a second time). Each is reported (fail) and
     * disables every book (booksDisabled()). The two book names (HOST_COSMETIC) may differ, and an
     * option set through a ROUNDCUBE_<OPTION> variable is the same for every host.
     *
     * @return list<string>
     */
    public function hostDependentOptions(): array
    {
        if (!array_key_exists('host_dependent', $this->memo)) {
            $out = [];
            if ($this->hostConfigEnabled()) {
                $base = $this->injected ? null : $this->hostIndependentConfig();
                $admin = $this->injected ? $this->adminValues() : [];
                foreach (self::DEFAULTS as $name => $default) {
                    if (in_array($name, self::HOST_COSMETIC, true) || getenv('ROUNDCUBE_' . strtoupper($name)) !== false) {
                        continue;
                    }
                    if ($base === null) {
                        $independent = array_key_exists($name, $admin) ? $admin[$name] : $default;
                    } else {
                        $independent = $base->get($name, $default);
                        $independent = $independent === self::UNSET ? $default : $independent;
                    }
                    if ($this->get($name) !== $independent) {
                        $out[] = $name;
                    }
                }
            }
            foreach ($out as $name) {
                $this->problem('fail', $name, 'differs from the host-independent configuration: it is set in a per-host configuration file (include_host_config - the Host header of the request, chosen by the client, selects that file) or in a file the configuration includes only once (include_once/require_once); keep every galbridge_* option in plugins/galbridge/config.inc.php - all books are disabled');
            }
            $this->memo['host_dependent'] = $out;
        }
        /** @var list<string> */
        return $this->memo['host_dependent'];
    }

    /**
     * Does a stored preference of the user shadow this option (directly, or through its legacy name)?
     */
    private function shadowed(string $name): bool
    {
        $legacy = self::LEGACY_NAMES[$name] ?? null;
        return array_key_exists($name, $this->userPrefs) || ($legacy !== null && array_key_exists($legacy, $this->userPrefs));
    }

    /**
     * An option as the second reading of the configuration files has it (a ROUNDCUBE_<OPTION>
     * variable applied, as Roundcube's own get() does).
     */
    private function readAgain(string $name, mixed $default): mixed
    {
        $admin = $this->adminValues();
        return array_key_exists($name, $admin) ? $admin[$name] : $this->adminConfig()->get($name, $default);
    }

    /**
     * The configuration without any per-host file: a fresh rcube_config whose galbridge_* options are
     * wiped (the constructor loads the per-host file of this request as well), then the
     * host-independent files of Roundcube's own object again - config.inc.php (main.inc.php and
     * db.inc.php when it has none, as rcube_config::load()) and the plugin's files.
     */
    private function hostIndependentConfig(): \rcube_config
    {
        $base = new \rcube_config();
        foreach (array_keys(self::DEFAULTS) as $name) {
            $base->set($name, self::UNSET);
        }
        $main = array_values(array_filter($this->config->resolve_paths('config.inc.php'), 'is_file'));
        if ($main === [] || self::loadFiles($base, $main) === $main) {
            foreach (['main.inc.php', 'db.inc.php'] as $old) {
                self::loadFiles($base, array_values(array_filter($this->config->resolve_paths($old), 'is_file')));
            }
        }
        self::loadFiles($base, self::pluginFiles($this->config, $this->pluginDir()));
        return $base;
    }

    public function get(string $name): mixed
    {
        // a (stale or injected) user preference must never change an administrator option
        if (str_starts_with($name, self::ADMIN_ONLY_PREFIX) && array_key_exists($name, $this->userPrefs)) {
            $admin = $this->adminValues();
            if (array_key_exists($name, $admin)) {
                return $admin[$name];
            }
            // In no configuration file: the built-in default - or the ROUNDCUBE_<OPTION> environment
            // variable. rcube_config::all() applies those variables to the options of the files only,
            // so an option that is set through the environment alone is read like below: the same
            // call, on a configuration that never saw the user's preferences.
            return $this->adminConfig()->get($name, self::DEFAULTS[$name] ?? null);
        }
        return $this->config->get($name, self::DEFAULTS[$name] ?? null);
    }

    /**
     * An option as the SECOND reading of the configuration files has it (never a user preference).
     * For a decision use coreValue() - what Roundcube applies; this reading serves the names a
     * preference shadows and the comparison of divergentOptions().
     */
    public function adminCore(string $name, mixed $default = null): mixed
    {
        $admin = $this->adminValues();
        return array_key_exists($name, $admin) ? $admin[$name] : $default;
    }

    /**
     * A switch. An ambiguous value (see the class comment) is reported, disables every book
     * (booksDisabled()) and is read as its restrictive value (RESTRICTIVE) - never as its default,
     * which for some switches (domain_book, include_aliases ...) is the permissive one.
     */
    public function bool(string $name): bool
    {
        $v = $this->switchValue($name, false);
        return is_bool($v) ? $v : (self::RESTRICTIVE[$name] ?? false);
    }

    /**
     * galbridge_*_searchonly: true, false or null for 'auto'. An ambiguous value reads as true (the
     * restrictive value) and disables every book.
     */
    public function searchonly(string $name): ?bool
    {
        $v = $this->switchValue($name, true);
        if (is_bool($v)) {
            return $v;
        }
        return $v === 'auto' ? null : true;
    }

    /**
     * A switch as it is in effect: true, false, 'auto' (galbridge_*_searchonly only) - or null when
     * its value is ambiguous (reported; every book is disabled).
     */
    public function effectiveSwitch(string $name): bool|string|null
    {
        return $this->switchValue($name, in_array($name, self::TRISTATES, true));
    }

    /**
     * Is every switch unambiguous? false = a configuration error that disables every book.
     */
    public function switchesValid(): bool
    {
        $ok = true;
        foreach (self::BOOLEANS as $name) {
            $ok = $this->switchValue($name, false) !== null && $ok;
        }
        foreach (self::TRISTATES as $name) {
            $ok = $this->switchValue($name, true) !== null && $ok;
        }
        return $ok;
    }

    /**
     * Configuration errors that must disable EVERY book, never mean "no restriction": an ambiguous
     * switch, a malformed hidden list, an invalid visibility column, or a configuration that is not
     * the one the administrator wrote (configUnreliable(): a plugin configuration file that does not
     * load or is ignored, an unknown option, an option that depends on the requested host name).
     */
    public function booksDisabled(): bool
    {
        $switches = $this->switchesValid();
        $unreliable = $this->configUnreliable();
        return !$switches
            || $unreliable
            || $this->hiddenAddresses() === null
            || $this->hiddenLocalparts() === null
            || $this->visibilityColumn() === null;
    }

    /**
     * galbridge_* options currently overridden by a ROUNDCUBE_<OPTION> environment variable (names
     * only - a value may be the DSN).
     *
     * @return list<string>
     */
    public function envOverrides(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            if (getenv('ROUNDCUBE_' . strtoupper($name)) !== false) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Other options of Roundcube that a ROUNDCUBE_<OPTION> variable of THIS process overrides
     * (rcube_config::get() and all() apply every such variable): the option names, lower case,
     * sorted - never a value. Not listed: the galbridge_* options (envOverrides()) and the two
     * variables that select the installation and its configuration directory, which are no options.
     *
     * @return list<string>
     */
    public static function coreEnvOverrides(): array
    {
        $out = [];
        foreach (array_keys(getenv()) as $var) {
            $var = (string) $var;
            if (preg_match('/^ROUNDCUBE_([A-Z0-9_]+)$/D', $var, $m) !== 1 || str_starts_with($m[1], 'GALBRIDGE_') || in_array($m[1], ['INSTALL_PATH', 'CONFIG_DIR'], true)) {
                continue;
            }
            $out[] = strtolower($m[1]);
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * A numeric option: a whole number - an integer, or a string of digits (optionally signed,
     * blanks around it) - within [$min, $max] (the range of RANGES). Any other value - a fraction,
     * 1e3, INF, a boolean, an array, other text - is reported and the default is used; a whole
     * number outside the range is reported and clamped. A ROUNDCUBE_<OPTION> variable is judged by
     * its text (Roundcube's own conversion turns "-5" or "1e3" into a float).
     */
    public function int(string $name, int $min, int $max): int
    {
        $default = self::DEFAULTS[$name] ?? $min;
        $default = max($min, min($max, is_int($default) ? $default : $min));
        $env = getenv('ROUNDCUBE_' . strtoupper($name));
        $v = is_string($env) ? $env : $this->get($name);
        if ($v === null) {
            return $default;
        }
        if (is_string($v) && preg_match('/^\s*-?[0-9]{1,18}\s*$/D', $v) === 1) {
            $v = (int) trim($v);
        }
        if (!is_int($v)) {
            $this->problem('error', $name, 'not a whole number (' . get_debug_type($v) . ' given): the default ' . $default . ' is used');
            return $default;
        }
        if ($v < $min || $v > $max) {
            $clamped = max($min, min($max, $v));
            $this->problem('error', $name, 'outside ' . $min . '..' . $max . ': ' . $clamped . ' is used');
            return $clamped;
        }
        return $v;
    }

    public function string(string $name): string
    {
        $v = $this->get($name);
        if ($v !== null && !is_string($v)) {
            $this->problem('error', $name, 'must be a string (' . get_debug_type($v) . ' given), ignored');
            return '';
        }
        return is_string($v) ? trim($v) : '';
    }

    /**
     * true / false, 'auto' (only with $allowAuto), or null when the value is ambiguous.
     */
    private function switchValue(string $name, bool $allowAuto): bool|string|null
    {
        $key = 'switch:' . $name;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $expected = $allowAuto ? "must be true, false or 'auto'" : 'must be the boolean true or false';
        $env = getenv('ROUNDCUBE_' . strtoupper($name));
        if (is_string($env)) {
            // the raw text: Roundcube's own conversion turns "false" into true
            $text = strtolower(trim($env));
            $value = match (true) {
                $text === '1', $text === 'true' => true,
                $text === '0', $text === 'false' => false,
                $allowAuto && $text === 'auto' => 'auto',
                default => null,
            };
            if ($value === null) {
                $this->problem('fail', $name, 'environment variable ROUNDCUBE_' . strtoupper($name) . ' '
                    . ($allowAuto ? 'must be 1, 0, true, false or auto' : 'must be 1, 0, true or false') . ': all books are disabled');
            }
            return $this->memo[$key] = $value;
        }
        $raw = $this->get($name);
        $value = match (true) {
            is_bool($raw) => $raw,
            $raw === 1 => true,
            $raw === 0 => false,
            $allowAuto && is_string($raw) && strtolower(trim($raw)) === 'auto' => 'auto',
            default => null,
        };
        if ($value === null) {
            $this->problem('fail', $name, $expected . ' (' . get_debug_type($raw) . ' given): all books are disabled');
        }
        return $this->memo[$key] = $value;
    }

    /**
     * One of $allowed (case-insensitive), else the default.
     *
     * @param list<string> $allowed
     */
    public function choice(string $name, array $allowed): string
    {
        $v = strtolower($this->string($name));
        if (in_array($v, $allowed, true)) {
            return $v;
        }
        $this->problem('error', $name, 'invalid value, using the default');
        $default = self::DEFAULTS[$name] ?? '';
        return is_string($default) ? $default : '';
    }

    /**
     * Why a list option is malformed, in words that never quote an entry (an entry of a hidden list
     * is a person the administrator wanted hidden): not a list, '*' where it is not allowed, or the
     * positions (1-based) of the entries that cannot be normalised.
     *
     * @param callable(mixed): ?string $normalise
     */
    private static function listProblem(mixed $raw, callable $normalise): string
    {
        if (!is_array($raw)) {
            return 'not a list (' . get_debug_type($raw) . ' given)';
        }
        $values = array_values($raw);
        if (in_array('*', $values, true)) {
            return count($values) === 1 ? "'*' is not allowed here" : "'*' mixed with other entries";
        }
        return self::positions(self::rejectedPositions($values, $normalise)) . ' cannot be normalised';
    }

    /**
     * The positions (1-based, in the order written) of the entries that do not normalise.
     *
     * @param array<array-key, mixed>  $values
     * @param callable(mixed): ?string $normalise
     *
     * @return list<int>
     */
    private static function rejectedPositions(array $values, callable $normalise): array
    {
        $out = [];
        foreach (array_values($values) as $i => $v) {
            if ($normalise($v) === null) {
                $out[] = $i + 1;
            }
        }
        return $out;
    }

    /**
     * "entry 3" / "entries 2, 5" (at most ten positions, then the number of the others).
     *
     * @param list<int> $positions
     */
    private static function positions(array $positions): string
    {
        $shown = implode(', ', array_slice($positions, 0, 10)) . (count($positions) > 10 ? ' and ' . (count($positions) - 10) . ' more' : '');
        return (count($positions) === 1 ? 'entry ' : 'entries ') . $shown;
    }

    /**
     * Normalised domain allow-list (invalid entries dropped). ['*'] = wildcard.
     *
     * @return list<string>
     */
    public function domainAllowList(string $name): array
    {
        return $this->memo[$name] ??= (function () use ($name): array {
            $invalid = [];
            $list = Normalizer::list($this->get($name), [Normalizer::class, 'domain'], false, $invalid);
            if ($list === null) {
                $this->problem('error', $name, 'must be an array of domains, or [\'*\'] alone; treated as empty');
                return [];
            }
            if ($invalid !== []) {
                $positions = self::rejectedPositions((array) $this->get($name), [Normalizer::class, 'domain']);
                $this->problem('error', $name, self::positions($positions) . ' dropped (cannot be normalised)');
            }
            return $list;
        })();
    }

    /**
     * Normalised domain deny-list. null = malformed: the caller must fail closed.
     *
     * @return null|list<string>
     */
    public function domainDenyList(string $name): ?array
    {
        if (!array_key_exists($name, $this->memo)) {
            $raw = $this->get($name);
            $list = Normalizer::list($raw, [Normalizer::class, 'domain'], true);
            if ($list === null) {
                $this->problem('fail', $name, 'malformed deny-list (' . self::listProblem($raw, [Normalizer::class, 'domain']) . '): the protected book is disabled');
            } elseif ($list === ['*']) {
                $list = null;
                $this->problem('fail', $name, '\'*\' is not allowed in a deny-list: the protected book is disabled');
            }
            $this->memo[$name] = $list;
        }
        return $this->memo[$name];
    }

    /**
     * galbridge_hidden_addresses (deny-list). null = malformed: every book fails closed.
     *
     * @return null|list<string>
     */
    public function hiddenAddresses(): ?array
    {
        if (!array_key_exists('hidden_addresses', $this->memo)) {
            $raw = $this->get('galbridge_hidden_addresses');
            $list = Normalizer::list($raw, [Normalizer::class, 'address'], true);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_hidden_addresses', 'malformed deny-list (' . self::listProblem($raw, [Normalizer::class, 'address']) . '): all books are disabled');
                $list = null;
            }
            $this->memo['hidden_addresses'] = $list;
        }
        return $this->memo['hidden_addresses'];
    }

    /**
     * galbridge_hidden_localparts (deny-list of local parts, e.g. 'postmaster'). null = malformed.
     *
     * @return null|list<string>
     */
    public function hiddenLocalparts(): ?array
    {
        if (!array_key_exists('hidden_localparts', $this->memo)) {
            $normalise = static function (mixed $v): ?string {
                if (!is_string($v)) {
                    return null;
                }
                $v = mb_strtolower(trim(Normalizer::nfc($v)));
                return Normalizer::localPart($v) ? $v : null;
            };
            $raw = $this->get('galbridge_hidden_localparts');
            $list = Normalizer::list($raw, $normalise, true);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_hidden_localparts', 'malformed deny-list (' . self::listProblem($raw, $normalise) . '): all books are disabled');
                $list = null;
            }
            $this->memo['hidden_localparts'] = $list;
        }
        return $this->memo['hidden_localparts'];
    }

    /**
     * galbridge_organizations as label => domains. Domains listed in more than one organization are
     * removed from all of them (they fall back to their own domain: fail closed), and reported.
     *
     * @return array<array-key, list<string>> label => domains (cast the key to string)
     */
    public function organizations(): array
    {
        if (!array_key_exists('organizations', $this->memo)) {
            $raw = $this->get('galbridge_organizations');
            $orgs = [];
            if (!is_array($raw)) {
                $this->problem('error', 'galbridge_organizations', 'must be an array of label => list of domains');
                $raw = [];
            }
            $seen = [];
            $position = 0;
            foreach ($raw as $label => $domains) {
                // named by its position: a label is text of the administrator, like an entry
                $position++;
                $org = 'organization ' . $position;
                $label = Normalizer::displayText(is_int($label) ? 'Organization ' . ($label + 1) : $label, 64);
                // a label reaches recipient names like any text of an entry (a contactlist_name_template
                // with {organization}): it never looks like an address either
                $cut = Normalizer::cutAtSign($label);
                if ($cut !== $label) {
                    $this->problem('error', 'galbridge_organizations', $org . ': label cut at the at-sign (a label must not look like an e-mail address)');
                    $label = $cut;
                }
                $invalid = [];
                $list = Normalizer::list($domains, [Normalizer::class, 'domain'], false, $invalid);
                if ($list === null || $list === ['*'] || $label === '') {
                    $this->problem('error', 'galbridge_organizations', $org . ' ignored (needs a label and a list of domains)');
                    continue;
                }
                if ($invalid !== []) {
                    $this->problem('error', 'galbridge_organizations', $org . ': ' . self::positions(self::rejectedPositions((array) $domains, [Normalizer::class, 'domain'])) . ' dropped (cannot be normalised)');
                }
                if (array_key_exists($label, $orgs)) {
                    // two keys that are the same label once cleaned: the later one is not merged
                    $this->problem('error', 'galbridge_organizations', $org . ' ignored (duplicate label)');
                    continue;
                }
                foreach ($list as $d) {
                    $seen[$d] = ($seen[$d] ?? 0) + 1;
                }
                $orgs[$label] = $list;
            }
            $dupes = array_map('strval', array_keys(array_filter($seen, static fn (int $n): bool => $n > 1)));
            if ($dupes !== []) {
                $this->problem('fail', 'galbridge_organizations', 'domains listed in more than one organization fall back to their own domain: ' . implode(', ', $dupes));
                foreach ($orgs as $label => $list) {
                    $orgs[$label] = array_values(array_diff($list, $dupes));
                }
            }
            $this->memo['organizations'] = array_filter($orgs, static fn (array $l): bool => $l !== []);
        }
        // NOTE: PHP turns a key such as '2024' into an int: consumers cast the label to string
        /** @var array<array-key, list<string>> */
        return $this->memo['organizations'];
    }

    /**
     * Effective field allowlist ('name' and 'email' are always included).
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->memo['fields'] ??= (function (): array {
            $raw = $this->get('galbridge_fields');
            $out = ['name' => true, 'email' => true];
            if (!is_array($raw)) {
                // fewer fields, never more - but said aloud, like every other option of the wrong type
                $this->problem('error', 'galbridge_fields', 'must be an array of field names (' . get_debug_type($raw) . ' given): only name and email are shown');
            }
            $unknown = [];
            foreach (array_values(is_array($raw) ? $raw : []) as $i => $f) {
                $f = is_string($f) ? strtolower(trim($f)) : '';
                if (in_array($f, self::FIELDS, true)) {
                    $out[$f] = true;
                } else {
                    $unknown[] = $i + 1;
                }
            }
            if ($unknown !== []) {
                $this->problem('error', 'galbridge_fields', self::positions($unknown) . ' dropped (not one of ' . implode(', ', self::FIELDS) . ')');
            }
            return array_values(array_intersect(self::FIELDS, array_keys($out)));
        })();
    }

    /**
     * Optional admin-created boolean visibility column of vmail.mailbox ('' = none).
     * Returns null when the option is not a string or not a valid column name (fail closed).
     */
    public function visibilityColumn(): ?string
    {
        if (!array_key_exists('visibility_column', $this->memo)) {
            // the raw value: a wrong type (an array, a number, a boolean) must never read as "no column"
            $raw = $this->get('galbridge_visibility_column');
            if ($raw === null || $raw === '') {
                $column = '';
            } elseif (!is_string($raw)) {
                $this->problem('fail', 'galbridge_visibility_column', 'must be a column name (a string, ' . get_debug_type($raw) . " given; '' = no column): all books are disabled");
                $column = null;
            } elseif (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', trim($raw))) {
                $this->problem('fail', 'galbridge_visibility_column', 'invalid column name: all books are disabled');
                $column = null;
            } else {
                $column = trim($raw);
            }
            $this->memo['visibility_column'] = $column;
        }
        return $this->memo['visibility_column'];
    }

    /**
     * Allowed IMAP endpoints: "host" (any port of that host) or "host:port" (IPv6 with a port:
     * "[::1]:993"), lower case - or a local socket, "unix:///run/dovecot/imap.sock" (the path as
     * written). Empty = derive from imap_host. null = malformed (fail closed).
     *
     * @return null|list<string>
     */
    public function imapHosts(): ?array
    {
        if (!array_key_exists('imap_hosts', $this->memo)) {
            $invalid = [];
            $list = Normalizer::list($this->get('galbridge_imap_hosts'), [self::class, 'imapEndpoint'], true, $invalid);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_imap_hosts', 'entries must be host names or IP addresses, optionally with a :port, or unix:///path for a local socket (a path of printable ASCII without blanks; no other scheme, no %placeholders): the plugin is disabled');
                $list = null;
            }
            $this->memo['imap_hosts'] = $list;
        }
        return $this->memo['imap_hosts'];
    }

    /**
     * Normalise one galbridge_imap_hosts entry: "host", "host:port" ("[v6]:port") or a local socket
     * ("unix:///path"); null = invalid.
     */
    public static function imapEndpoint(mixed $v): ?string
    {
        if (is_string($v) && ($unix = self::unixEndpoint($v)) !== null) {
            return $unix;
        }
        if (!is_string($v) || $v === '' || preg_match('~[%/\s]|://~', $v)) {
            return null;
        }
        $v = strtolower(trim($v));
        $port = null;
        if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/D', $v, $m)) {
            $host = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $m[1] : null;
            $port = $m[2] ?? null;
        } elseif (filter_var($v, FILTER_VALIDATE_IP) !== false) {
            $host = $v; // a bare IPv4 or IPv6 literal
        } elseif (preg_match('/^([^:]+):([0-9]{1,5})$/D', $v, $m)) {
            $host = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $m[1] : Normalizer::domain($m[1]);
            $port = $m[2];
        } else {
            $host = str_contains($v, ':') ? null : Normalizer::domain($v);
        }
        if ($host === null) {
            return null;
        }
        if ($port === null) {
            return $host;
        }
        $p = (int) $port;
        if ($p < 1 || $p > 65535) {
            return null;
        }
        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $p;
    }

    /**
     * An IMAP server on a local socket in Roundcube's notation, "unix:///absolute/path": the scheme
     * in lower case, the path exactly as written (file names are case-sensitive). The path is
     * printable ASCII without blanks and without '%' (no %placeholder), at most 4095 bytes: a socket
     * path of Dovecot needs nothing else, and a stored mail_host with any other byte - a control
     * character, a C1 or bidi character, invalid UTF-8 - is then never "canonical" (it is counted,
     * never printed, and gets no directory). null = not such a URI. A socket has no port and no host
     * name: it is matched as a whole or not at all.
     */
    public static function unixEndpoint(string $v): ?string
    {
        return preg_match('~^unix://(/[\x21-\x24\x26-\x7E]{1,4095})$~Di', $v, $m) === 1 ? 'unix://' . $m[1] : null;
    }

    /**
     * Refused database roles (lower case): the built-in names - iRedMail's own, broader roles, root
     * and postgres, which no configuration can remove - and the names galbridge_db_refused_users
     * adds. null = the option is malformed (not a list, or an entry that is not a role name): reported
     * (fail), and the DSN policy refuses every connection (a safety net must not shrink because of a
     * typo, nor be guessed).
     *
     * @return null|list<string>
     */
    public function refusedUsers(): ?array
    {
        if (!array_key_exists('refused_users', $this->memo)) {
            $raw = $this->get('galbridge_db_refused_users');
            $isName = static fn (mixed $u): ?string => is_string($u) && trim($u) !== '' ? strtolower(trim($u)) : null;
            $out = null;
            if (!is_array($raw)) {
                $this->problem('fail', 'galbridge_db_refused_users', 'must be a list of role names (' . get_debug_type($raw) . ' given): no database connection');
            } elseif (($bad = self::rejectedPositions($raw, $isName)) !== []) {
                $this->problem('fail', 'galbridge_db_refused_users', 'must be a list of role names (' . self::positions($bad) . (count($bad) === 1 ? ' is not' : ' are not') . '): no database connection');
            } else {
                $out = self::DEFAULTS['galbridge_db_refused_users'];
                foreach ($raw as $u) {
                    $out[] = (string) $isName($u);
                }
                $out = array_values(array_unique($out));
            }
            $this->memo['refused_users'] = $out;
        }
        /** @var null|list<string> */
        return $this->memo['refused_users'];
    }

    /**
     * Run every validating accessor once, so problems() is complete (diag calls this first; at
     * runtime each accessor validates lazily when it is first needed).
     */
    public function validate(): void
    {
        $this->organizations();
        $this->fields();
        $this->hiddenAddresses();
        $this->hiddenLocalparts();
        $this->visibilityColumn();
        $this->imapHosts();
        $this->refusedUsers();
        $this->switchesValid();
        $this->configUnreliable();
        foreach (self::RANGES as $name => [$min, $max]) {
            $this->int($name, $min, $max);
        }
        foreach (['galbridge_db_dsn', 'galbridge_domain_book_name', 'galbridge_global_book_name'] as $name) {
            $this->string($name);
        }
        foreach (['galbridge_domain_viewer_domains', 'galbridge_global_viewer_domains', 'galbridge_global_data_domains'] as $allow) {
            $this->domainAllowList($allow);
        }
        foreach (['galbridge_domain_viewer_domains_exclude', 'galbridge_global_viewer_domains_exclude', 'galbridge_global_data_domains_exclude'] as $deny) {
            $this->domainDenyList($deny);
        }
        $this->choice('galbridge_domain_groups', self::GROUPINGS);
        $this->choice('galbridge_global_groups', self::GROUPINGS);
        $this->choice('galbridge_list_policy', self::LIST_POLICIES);
    }

    /**
     * The DSN of Roundcube's password plugin (its user is refused for GAL Bridge), as the password
     * plugin itself has it: the configuration files with its own file merged over them
     * (pluginFiles(): plugins/password/config.inc.php, or password.inc.php from the configuration
     * directories) - iRedMail writes it into plugins/password/config.inc.php, which the main
     * configuration does not include. Read whether or not the plugin is enabled. coreDsns() adds
     * the password_db_dsn of both readings of the main configuration.
     */
    public function passwordPluginDsn(): ?string
    {
        $dsn = $this->passwordPluginConfig()['dsn'];
        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    /**
     * The driver of Roundcube's password plugin as the plugin itself has it (see passwordPluginDsn();
     * the plugin's default is 'sql').
     */
    public function passwordPluginDriver(): string
    {
        $driver = $this->passwordPluginConfig()['driver'];
        return is_string($driver) && $driver !== '' ? strtolower($driver) : 'sql';
    }

    /**
     * @return array{dsn: mixed, driver: mixed}
     */
    private function passwordPluginConfig(): array
    {
        if (!array_key_exists('password_plugin', $this->memo)) {
            $fresh = new \rcube_config();
            self::loadFiles($fresh, self::pluginFiles($this->config, self::pluginsDir() . 'password', 'password'));
            $this->memo['password_plugin'] = ['dsn' => $fresh->get('password_db_dsn'), 'driver' => $fresh->get('password_driver')];
        }
        /** @var array{dsn: mixed, driver: mixed} */
        return $this->memo['password_plugin'];
    }

    /**
     * Keyed hash (HMAC with des_key) of the effective administrator configuration of this plugin.
     * Binds the cached Viewer and stored search specs, so a configuration change takes effect
     * immediately. The DSN enters it WITHOUT its password (dsnIdentity()): the value is kept in the
     * session store, and nothing in it may help to test guesses of the database password - whatever
     * des_key is. Nothing cached depends on the password (home domain, address set and column probe
     * depend on the role and the data).
     */
    public function fingerprint(): string
    {
        if (!isset($this->memo['fingerprint'])) {
            $values = [];
            foreach (array_keys(self::DEFAULTS) as $name) {
                $values[$name] = $this->get($name);
            }
            $values['galbridge_db_dsn'] = self::dsnIdentity($this->get('galbridge_db_dsn'));
            // Switches as they are in EFFECT: for one that is set through the environment Roundcube
            // hands out its own conversion of the text ("false" becomes true), which is the same for
            // different effective values - a change of the variable would not change the fingerprint.
            foreach ([...self::BOOLEANS, ...self::TRISTATES] as $name) {
                $values[$name] = $this->effectiveSwitch($name);
            }
            // the core options as Roundcube applies them (the binding is derived from imap_host)
            $values['imap_host'] = $this->coreValue('imap_host');
            $values['include_host_config'] = $this->hostConfigEnabled();
            // Keyed with Roundcube's server secret - the des_key Roundcube itself uses: the value is
            // stored in the session (Viewer cache, search specs), and without a key the configuration
            // (role, host, lists) could be confirmed from it offline.
            $key = $this->coreValue('des_key');
            $this->memo['fingerprint'] = substr(hash_hmac('sha256', serialize($values), 'galbridge-fingerprint:' . (is_string($key) ? $key : '')), 0, 32);
        }
        return $this->memo['fingerprint'];
    }

    /**
     * What a DSN connects to, without its password: driver, user, host, port, socket, database and
     * parameters as rcube_db::parse_dsn() reads them, every key that names a password removed. A
     * value that is not a string is represented by its type, one that cannot be parsed by a marker.
     *
     * @return array<array-key, mixed>|string
     */
    private static function dsnIdentity(#[\SensitiveParameter] mixed $dsn): array|string
    {
        if (!is_string($dsn)) {
            return get_debug_type($dsn);
        }
        try {
            $parsed = \rcube_db::parse_dsn(trim($dsn));
        } catch (\Throwable) {
            return 'unparsable';
        }
        foreach (array_keys($parsed) as $key) {
            if (preg_match('/pass/i', (string) $key) === 1) {
                unset($parsed[$key]);
            }
        }
        ksort($parsed);
        return $parsed;
    }

    /**
     * Problems found while reading the configuration (for diag and the log).
     *
     * @return list<array{level: string, option: string, message: string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    private function problem(string $level, string $option, string $message): void
    {
        foreach ($this->problems as $p) {
            if ($p['option'] === $option && $p['message'] === $message) {
                return;
            }
        }
        $this->problems[] = ['level' => $level, 'option' => $option, 'message' => $message];
        Log::error('config', 'config_invalid: ' . $message, ['option' => $option]);
    }

    /**
     * Configuration as written by the administrator (the second reading, without user preferences).
     *
     * @return array<string, mixed>
     */
    private function adminValues(): array
    {
        if ($this->adminValues === null) {
            $this->adminValues = $this->adminConfig()->all();
        }
        return $this->adminValues;
    }

    /**
     * The second reading of the configuration files: a fresh rcube_config (defaults.inc.php,
     * config.inc.php and - with include_host_config - the per-host file of this request) and the
     * plugin's own files resolved by Roundcube's object (pluginFiles()), without the stored
     * preferences of any user. It has no environment name and misses what include_once/require_once
     * pulled in before (see the class comment).
     */
    private function adminConfig(): \rcube_config
    {
        if ($this->adminConfig === null) {
            $fresh = new \rcube_config();
            $this->unloaded = self::loadFiles($fresh, self::pluginFiles($this->config, $this->pluginDir()));
            $this->adminConfig = $fresh;
        }
        return $this->adminConfig;
    }
}
