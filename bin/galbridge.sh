#!/usr/bin/env php
<?php

/**
 * Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
 * Original author: Marek Królikowski (TaKeN)
 * Original project: https://github.com/takenek/galbridge
 * SPDX-License-Identifier: GPL-3.0-or-later
 * See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
 *
 * GAL Bridge - administration tool (diagnostics of the configuration and the vmail database,
 * least-privilege grant statements).
 *
 * Run from the Roundcube installation directory. diag as the PHP / web server user (it must be able
 * to read the plugin configuration); grants as root with --allow-root:
 *   sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
 *   sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
 *   (without sudo, as root: runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag ...)
 *   umask 077; plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql > /root/galbridge-grants.sql
 * The SQL of grants goes to standard output (the file, readable by root only), its DSN line - with
 * the password - to standard error (the terminal), never into the file.
 *
 * grants and the web server user: the database administrator runs what grants prints. Produced by a
 * process of the web server user, that output could be read or changed by any other process of
 * the same user (ptrace, /proc/<pid>/fd - a compromised web application), whoever owns the code. So
 * grants refuses to run as a user that can read the configuration of Roundcube or of the plugin
 * (the web server's files), and as any user other than root it refuses code and templates that an
 * account other than root and itself owns or that its group or others can write - the same check
 * as for --allow-root below, for the files it loads and prints and every directory above them.
 * Meant for a dedicated account (a clone of the database administrator, a CI job); the documented
 * way is root with --allow-root (README 2.2).
 *
 * NOT as root otherwise: the tool loads PHP code from the plugin directory and from the Roundcube
 * directory (and Roundcube's configuration files are PHP as well). Whoever can write there - on many
 * installations the web server user - would run code as root. Root is refused unless --allow-root
 * is given, and then only when all of that code belongs to root and nobody else can write it:
 * the plugin, and below the Roundcube directory everything in program/, plugins/ (Roundcube loads
 * every enabled plugin at start), vendor/, every configuration directory (ROUNDCUBE_CONFIG_DIR,
 * RCUBE_CONFIG_PATH) and every directory of PHP's include_path, together with the directories they
 * are reached through; a symbolic link to a path that does not exist is refused. Roundcube is handed
 * exactly the canonical paths that were checked (INSTALL_PATH, the configuration directories,
 * include_path - only absolute, existing, verified entries), and the process works in Roundcube's
 * program/ directory, so a relative lookup never reaches the administrator's working directory.
 * diag reads (runs) the per-host files of include_host_config to compare their options; as root it
 * reads only a file that lies inside one of the verified configuration directories and that itself
 * belongs to root and is writable by nobody else. Any other (an absolute or "../" name in
 * include_host_config leads anywhere) is named in a WARN line and not loaded: run diag as the web
 * server user to check it.
 * It writes only where nobody else can: Roundcube's log lines go to syslog, and PHP's own messages
 * go to syslog or - with log_driver 'file' - into a log_dir that root alone can write; a log_dir of
 * the web server user (which could hold a symbolic link in place of errors.log) is refused, and so
 * is an SQLite database of Roundcube in a directory another account can write. Not covered: PHP's
 * own configuration (php.ini, conf.d, auto_prepend_file, extension_dir), which is read before this
 * script runs, and the start code of every enabled Roundcube plugin, which runs as root (its files
 * are checked, its data directories are not). The web server user stays the account to use.
 * A process whose user cannot be determined (no posix extension and no /proc) is treated like root.
 *
 * grants loads nothing but the plugin's own classes and templates: it works in the plugin
 * directory with the plugin's lib/ as include_path, as root and as any other user.
 *
 * Everything diag prints is neutralised (GalBridge\Cli\Terminal), and so is what reaches standard
 * error while it runs - messages of Roundcube, of the database drivers and of PHP: no control,
 * bidi or separator character and no invalid UTF-8 reaches the terminal raw. An exception or a PHP
 * fatal error, also during Roundcube's start, ends with "Result: aborted" and exit code 2 (for
 * grants on standard error, never in the SQL). A repeated option is a usage error (exit code 1).
 *
 * @license GPL-3.0-or-later
 */

/**
 * The effective user id of this process, or null when it cannot be determined. The posix extension
 * is a package of its own on several platforms (php-process, php-posix) and no requirement of the
 * plugin; on Linux the kernel tells as well.
 */
function galbridge_cli_euid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    $status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
    // "Uid:" real, effective, saved, filesystem
    if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)\s/m', $status, $m) === 1) {
        return (int) $m[1];
    }
    return null;
}

/**
 * A text for root's terminal: every byte outside printable ASCII becomes \xNN. The refusals below
 * name files found in directories that another account can write - by definition of the refusal -
 * so such a name must not be able to steer the terminal. (Runs before the plugin's classes may be
 * loaded: no autoloader here.)
 */
function galbridge_cli_printable(string $s): string
{
    return (string) preg_replace_callback('/[^\x20-\x7E]/', static fn (array $m): string => sprintf('\\x%02X', ord($m[0])), $s);
}

/**
 * Refuse --allow-root, or grants as another user (exit code 1): $unsafe names the path and why,
 * $owners the accounts that may own it.
 */
function galbridge_cli_refuse(string $unsafe, string $what = '--allow-root', string $owners = 'root', string $why = ''): never
{
    fwrite(STDERR, 'galbridge.sh ' . $what . ' refused: ' . galbridge_cli_printable($unsafe) . ' is not owned by ' . $owners . ' or is writable by its group or by others' . $why . ".\n");
    exit(1);
}

/**
 * The first of the given paths that another account could have written (an owner not in $owners,
 * or a group/other write bit), or null. Used for --allow-root ($owners = [0]) and for grants as
 * another user (root and that user).
 *
 * @param list<string> $paths  files and directories; missing ones are skipped
 * @param list<int>    $owners the user ids that may own them
 */
function galbridge_cli_unsafe_path(array $paths, array $owners = [0]): ?string
{
    foreach ($paths as $path) {
        if (!file_exists($path)) {
            if (is_link($path)) {
                // a link to nothing: whoever can create its target later decides what is loaded
                return $path . ' (symbolic link to a path that does not exist)';
            }
            continue;
        }
        $real = realpath($path);
        $stat = $real === false ? false : @stat($real);
        if ($real === false || $stat === false) {
            return $path . ' (cannot be checked)';
        }
        if (!in_array($stat['uid'], $owners, true) || ($stat['mode'] & 0o022) !== 0) {
            return $real . ' (owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) . ')';
        }
    }
    return null;
}

/**
 * The first directory ABOVE one of the given paths that another account controls (an owner not in
 * $owners, or a group/other write bit), or null: whoever can write a directory can replace what is
 * reached through it. A directory with the sticky bit (/tmp) lets others add entries but not
 * replace the ones of its owners, so it passes here - and only here, never as a directory that
 * holds code.
 *
 * @param list<string> $paths
 * @param list<int>    $owners the user ids that may own them
 */
function galbridge_cli_unsafe_ancestor(array $paths, array $owners = [0]): ?string
{
    foreach ($paths as $path) {
        $real = realpath($path);
        if ($real === false) {
            continue;
        }
        for ($dir = dirname($real); ; $dir = dirname($dir)) {
            $stat = @stat($dir);
            if ($stat === false) {
                return $dir . ' (cannot be checked)';
            }
            if (!in_array($stat['uid'], $owners, true) || (($stat['mode'] & 0o022) !== 0 && ($stat['mode'] & 0o1000) === 0)) {
                return $dir . ' (owner uid ' . $stat['uid'] . ', mode ' . sprintf('%04o', $stat['mode'] & 0o7777) . ')';
            }
            if ($dir === dirname($dir)) {
                break;
            }
        }
    }
    return null;
}

/**
 * Where a process of root writes while Roundcube runs, checked before Roundcube starts (after its
 * iniset.php is loaded, so its classes are available): every rcube_config object - Roundcube's own
 * and the ones the plugin creates to read the configuration afresh - points PHP's error_log at
 * <log_dir>/errors.log when log_driver is 'file' (rcube_config::load() reads the option as written,
 * ROUNDCUBE_LOG_DRIVER does not change that), and Roundcube opens an SQLite database (and creates
 * its journal) in the directory of the file. Each of them must be a directory that root alone can
 * write - a log_dir of the web server user could hold a symbolic link named errors.log pointing
 * anywhere. Returns the reason to refuse, or null. The configuration files read here are the ones
 * checked above.
 */
function galbridge_cli_write_problem(): ?string
{
    ini_set('error_log', 'syslog');
    $probe = new rcube_config();
    $dest = (string) ini_get('error_log');
    ini_set('error_log', 'syslog');
    $unsafe = static function (string $file): ?string {
        $dir = realpath(dirname($file));
        if ($dir === false) {
            return dirname($file) . ' cannot be checked';
        }
        // owner root, no write bit for group or others - a sticky /tmp is no such directory
        $why = galbridge_cli_unsafe_path([$dir]) ?? galbridge_cli_unsafe_ancestor([$dir]);
        if ($why !== null) {
            return $why . " is not root's alone";
        }
        return is_link($file) ? $file . ' is a symbolic link' : null;
    };
    if ($dest !== 'syslog' && ($why = $unsafe($dest)) !== null) {
        return 'PHP writes its messages to ' . $dest . " while Roundcube runs (log_driver 'file'), and " . $why
            . " - set log_driver = 'syslog' (iRedMail's setting) or run the tool as the web server user";
    }
    foreach (['db_dsnw', 'db_dsnr'] as $option) {
        $dsn = $probe->get($option);
        $parsed = is_string($dsn) && $dsn !== '' ? rcube_db::parse_dsn($dsn) : [];
        $file = (string) ($parsed['database'] ?? '');
        if (stripos((string) ($parsed['phptype'] ?? ''), 'sqlite') === 0 && str_starts_with($file, '/') && ($why = $unsafe($file)) !== null) {
            return "Roundcube's database ({$option}) is the SQLite file " . $file . ', and ' . $why
                . ' (SQLite writes the file and its journal there as root) - run the tool as the web server user';
        }
    }
    return null;
}

/**
 * A directory, every directory below it and every file PHP could load from it or this tool prints
 * as SQL (*.php, *.inc, *.sql, *.sh). Symbolic links are followed - a plugin is often installed as
 * one - and every directory is visited once.
 *
 * @param array<string, true> $seen  directories already visited (by their real path)
 * @param list<string>        $links what was reached through a symbolic link: its target lies
 *                                   somewhere else, so the directories above the target count too
 *
 * @return list<string>
 */
function galbridge_cli_code_paths(string $dir, array &$seen = [], array &$links = []): array
{
    $paths = [$dir];
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || isset($seen[$real])) {
        return $paths;
    }
    $seen[$real] = true;
    foreach (scandir($real) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $real . '/' . $entry;
        if (is_link($path) && !file_exists($path)) {
            $paths[] = $path; // a dangling link: refused by galbridge_cli_unsafe_path()
            continue;
        }
        $code = is_dir($path) || preg_match('/\.(php|inc|sql|sh)$/D', $entry) === 1;
        if ($code && is_link($path)) {
            $links[] = $path;
        }
        if (is_dir($path)) {
            foreach (galbridge_cli_code_paths($path, $seen, $links) as $below) {
                $paths[] = $below;
            }
        } elseif ($code) {
            $paths[] = $path;
        }
    }
    return $paths;
}

/**
 * The first file or directory of the plugin - this script, galbridge.php, its configuration, every
 * file PHP could load or grants prints below bin/, lib/, SQL/ and localization/, those directories
 * and every directory above them and above the target of a symbolic link - that an account not in
 * $owners owns or that its group or others can write; null when there is none.
 *
 * @param list<int> $owners
 */
function galbridge_cli_plugin_problem(string $plugin, array $owners): ?string
{
    $seen = [];
    $links = [];
    return galbridge_cli_unsafe_path([__FILE__, $plugin, $plugin . '/galbridge.php', $plugin . '/config.inc.php',
        ...galbridge_cli_code_paths($plugin . '/bin', $seen, $links), ...galbridge_cli_code_paths($plugin . '/lib', $seen, $links),
        ...galbridge_cli_code_paths($plugin . '/SQL', $seen, $links), ...galbridge_cli_code_paths($plugin . '/localization', $seen, $links)], $owners)
        ?? galbridge_cli_unsafe_ancestor([$plugin, $plugin . '/galbridge.php', $plugin . '/config.inc.php',
            $plugin . '/bin', $plugin . '/lib', $plugin . '/SQL', $plugin . '/localization', ...$links], $owners);
}

/**
 * A configuration file of Roundcube or of the plugin that this process can read, or null: the
 * mark of the web server user (Roundcube reads them as that user; root runs grants with
 * --allow-root instead). Looked for in the Roundcube directories the script would use, the
 * configuration directories of the environment, the plugin directory and the places where
 * iRedMail and the distributions install Roundcube.
 *
 * @param list<string> $roots candidate Roundcube directories
 */
function galbridge_cli_web_identity(string $plugin, array $roots): ?string
{
    $dirs = [];
    foreach ([...$roots, '/opt/www/roundcubemail', '/var/www/roundcubemail', '/var/www/html/roundcubemail', '/usr/share/roundcubemail', '/srv/www/roundcubemail'] as $root) {
        $dirs[] = rtrim($root, '/') . '/config';
    }
    $env = (string) (getenv('ROUNDCUBE_CONFIG_DIR') ?: '');
    if ($env !== '') {
        $dirs[] = rtrim($env, '/');
    }
    foreach (explode(PATH_SEPARATOR, (string) (getenv('RCUBE_CONFIG_PATH') ?: '')) as $p) {
        if ($p !== '' && $p[0] === '/') {
            $dirs[] = rtrim($p, '/');
        }
    }
    // the configuration directories of the Debian / Fedora packages of Roundcube
    array_push($dirs, '/etc/roundcube', '/etc/roundcubemail');
    $files = [$plugin . '/config.inc.php'];
    foreach (array_unique($dirs) as $dir) {
        array_push($files, $dir . '/config.inc.php', $dir . '/galbridge.inc.php');
    }
    foreach ($files as $file) {
        if (is_file($file) && is_readable($file)) {
            return $file;
        }
    }
    return null;
}

$galbridgeUid = galbridge_cli_euid();
// a user that cannot be determined is treated like root: refused, or checked with --allow-root
$galbridgeRoot = $galbridgeUid === null || $galbridgeUid === 0;
$galbridgePlugin = dirname(__DIR__);
// the command, before any class is loaded: the first word that is not an option
$galbridgeCommand = '';
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with((string) $arg, '--')) {
        $galbridgeCommand = (string) $arg;
        break;
    }
}
if ($galbridgeCommand === 'grants') {
    // standard output is the SQL file: a message of PHP itself goes to standard error (the command
    // line PHP displays it on standard output by default)
    ini_set('display_errors', 'stderr');
}
if ($galbridgeRoot) {
    if (!in_array('--allow-root', array_slice($argv, 1), true)) {
        fwrite(STDERR, ($galbridgeUid === null
            ? "galbridge.sh cannot tell which user it runs as (PHP has no posix extension and /proc is not available) and treats that like root.\n"
            : '')
            . ($galbridgeCommand === 'grants'
                ? "galbridge.sh grants runs as root only with --allow-root, which first checks that root alone can change the code it loads and prints:\n"
                  . "  umask 077; plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql|pgsql ... > /root/galbridge-grants.sql\n"
                : "galbridge.sh must not run as root: it loads PHP code from the plugin and the Roundcube directory.\n"
                  . "Run it as the web server user, e.g.  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag\n"
                  . "(without sudo:  runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag)\n"
                  . "(--allow-root overrides this when all of that code is owned by root and writable by nobody else).\n"));
        exit(1);
    }
    $unsafe = galbridge_cli_plugin_problem($galbridgePlugin, [0]);
    if ($unsafe !== null) {
        galbridge_cli_refuse($unsafe);
    }
}

// Roundcube root: $ROUNDCUBE_INSTALL_PATH, or <root>/plugins/galbridge/bin as invoked (a symlinked
// plugin directory is resolved by __DIR__, so the invocation path is tried first)
$candidates = [];
if (is_string(getenv('ROUNDCUBE_INSTALL_PATH')) && getenv('ROUNDCUBE_INSTALL_PATH') !== '') {
    $candidates[] = rtrim((string) getenv('ROUNDCUBE_INSTALL_PATH'), '/');
}
$invoked = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($invoked !== '') {
    if ($invoked[0] !== '/') {
        $invoked = getcwd() . '/' . $invoked;
    }
    $candidates[] = dirname($invoked, 4);
}
$candidates[] = dirname(__DIR__, 3);
$root = null;
foreach ($candidates as $c) {
    if (is_file($c . '/program/include/clisetup.php')) {
        $root = $c;
        break;
    }
}

if ($galbridgeCommand === 'grants') {
    if (!$galbridgeRoot) {
        // The database administrator runs the output: it must come from code that only root and
        // this user can change, and not from a process of the web server user (README 2.2).
        $unsafe = galbridge_cli_plugin_problem($galbridgePlugin, [0, (int) $galbridgeUid]);
        if ($unsafe !== null) {
            galbridge_cli_refuse($unsafe, 'grants', 'root or by this user (uid ' . (int) $galbridgeUid . ')', ': the statements would come from code that another account can change - make root the owner (README 2.2)');
        }
        $readable = galbridge_cli_web_identity($galbridgePlugin, $candidates);
        if ($readable !== null) {
            fwrite(STDERR, 'galbridge.sh grants refused: this user can read ' . galbridge_cli_printable($readable) . ", a configuration file of the web server.\n"
                . "Another process of the web server user could read or change the output while it is produced, and the database administrator runs it.\n"
                . "Run grants as root:  umask 077; plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql|pgsql ... > /root/galbridge-grants.sql  (README 2.2)\n");
            exit(1);
        }
    }
    // only the plugin's own classes and templates: nothing is looked up along PHP's include_path
    // or in the working directory of the administrator
    if (set_include_path($galbridgePlugin . '/lib') === false || !chdir($galbridgePlugin)) {
        fwrite(STDERR, "galbridge.sh grants refused: the plugin directory cannot be used as the working directory.\n");
        exit(1);
    }
}

require_once __DIR__ . '/../lib/autoload.php';
[$args, $galbridgeProblem] = GalBridge\Cli\Tool::parseArguments(array_values(array_slice($argv, 1)));
if ($galbridgeProblem !== null) {
    fwrite(STDERR, GalBridge\Cli\Tool::usage(is_string($args[0] ?? null) ? $args[0] : '') . "\n" . GalBridge\Cli\Terminal::clean($galbridgeProblem) . "\n");
    exit(1);
}

// From here on the end of the program is never mistaken for a result: a PHP fatal error, or an
// exit() of other code while the tool runs (Roundcube's start ends the program on some errors),
// prints "Result: aborted" and ends with exit code 2. Registered before Roundcube is loaded, this
// runs before Roundcube's own shutdown handler, whose bare exit() would turn a fatal error into exit
// code 0 (PHP 8.4 and later), and its exit(2) stops that handler.
$galbridgeRun = new stdClass();
$galbridgeRun->active = false;
// where "Result: aborted" goes: the report of diag (standard output), standard error for grants
$galbridgeRun->stream = $galbridgeCommand === 'grants' ? STDERR : STDOUT;
register_shutdown_function(static function () use ($galbridgeRun): void {
    $error = error_get_last();
    $fatal = is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$fatal && $galbridgeRun->active !== true) {
        return;
    }
    $galbridgeRun->active = false;
    fwrite($galbridgeRun->stream, "\nerror: " . ($fatal
        ? GalBridge\Cli\Terminal::clean('PHP fatal error: ' . GalBridge\Log::sanitize($error['message']))
        : 'the program was ended before the tool finished (see the messages on standard error)') . "\nResult: aborted\n");
    exit(2);
});

// 'grants' needs no Roundcube (it only prints SQL); everything else does. Its SQL goes to standard
// output, everything else it says (the DSN line, refusals, errors) to standard error.
if (($args[0] ?? '') === 'grants') {
    $galbridgeRun->active = true;
    try {
        $code = (new GalBridge\Cli\Tool(null, realpath(__DIR__ . '/..') ?: __DIR__ . '/..', STDOUT, STDERR))->run($args);
    } catch (Throwable $e) {
        $galbridgeRun->active = false;
        fwrite(STDERR, "\nerror: " . GalBridge\Cli\Terminal::clean(GalBridge\Log::sanitize(get_class($e) . ': ' . $e->getMessage())) . "\nResult: aborted\n");
        exit(2);
    }
    $galbridgeRun->active = false;
    exit($code);
}

if ($root === null) {
    fwrite(STDERR, "Roundcube not found: install the plugin as <roundcube>/plugins/galbridge or set ROUNDCUBE_INSTALL_PATH\n");
    exit(1);
}
if ($galbridgeRoot) {
    // Roundcube's code and its configuration (PHP files) are loaded next. Its start loads every
    // enabled plugin (plugins/<name>/<name>.php and what that includes), classes of vendor/ and of
    // include_path on demand, the localization index, and the plugin reads the configuration of the
    // password plugin: everything PHP could load from these directories has to belong to root.
    // Everything is checked - and then handed to Roundcube - by its canonical path: a symbolic link
    // on the way (in ROUNDCUBE_INSTALL_PATH, the invocation path or a configuration directory) is
    // resolved once, so what is loaded is what was checked, and a relative path cannot depend on
    // the working directory later.
    $real = realpath($root);
    if ($real === false) {
        galbridge_cli_refuse($root . ' (cannot be checked)');
    }
    $root = $real;
    // The configuration directories exactly as Roundcube finds them: ROUNDCUBE_CONFIG_DIR when it
    // is not empty and not "0" (iniset.php reads it with ?:), and every entry of RCUBE_CONFIG_PATH
    // (rcube_config: absolute, or relative to the Roundcube directory). A relative
    // ROUNDCUBE_CONFIG_DIR means the working directory, as it would for Roundcube.
    $configDirs = [(string) (getenv('ROUNDCUBE_CONFIG_DIR') ?: $root . '/config')];
    $configPath = (string) (getenv('RCUBE_CONFIG_PATH') ?: '');
    foreach (explode(PATH_SEPARATOR, $configPath) as $p) {
        if ($p !== '') {
            $configDirs[] = $p[0] === '/' ? $p : $root . '/' . $p;
        }
    }
    $unsafe = null;
    $canonical = [];
    foreach ($configDirs as $dir) {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            // a configuration directory that does not exist yet is created by somebody later: refused
            $unsafe ??= $dir . ' (configuration directory that does not exist)';
            continue;
        }
        $canonical[] = $real;
    }
    // Class files are looked up along include_path (Roundcube's autoloader asks PHP for a relative
    // file name). Only absolute entries that exist are kept, and each is checked like program/;
    // PHP's default "." - the working directory of the administrator - is dropped.
    $includes = [];
    foreach (explode(PATH_SEPARATOR, (string) get_include_path()) as $p) {
        $real = $p !== '' && $p[0] === '/' ? realpath($p) : false;
        if ($real !== false && is_dir($real) && !in_array($real, $includes, true)) {
            $includes[] = $real;
        }
    }
    $seen = [];
    $links = [];
    $codePaths = [$root, ...galbridge_cli_code_paths($root . '/program', $seen, $links), ...galbridge_cli_code_paths($root . '/plugins', $seen, $links),
        ...galbridge_cli_code_paths($root . '/vendor', $seen, $links)];
    foreach ([...$canonical, ...$includes] as $dir) {
        array_push($codePaths, ...galbridge_cli_code_paths($dir, $seen, $links));
    }
    $unsafe ??= galbridge_cli_unsafe_path($codePaths)
        ?? galbridge_cli_unsafe_ancestor([$root, $root . '/program', $root . '/plugins', $root . '/vendor', ...$canonical, ...$includes, ...$links]);
    if ($unsafe !== null) {
        galbridge_cli_refuse($unsafe);
    }
    // Roundcube gets exactly what was checked: iniset.php keeps an INSTALL_PATH and an
    // RCMAIL_CONFIG_DIR that are already defined (instead of reading the environment again), and
    // rcube_config reads RCUBE_CONFIG_PATH from the environment.
    // (PHP refuses an empty include_path and would keep the old one: Roundcube's own library, which
    // iniset.php puts first anyway, stands in when no entry is left)
    if (set_include_path(implode(PATH_SEPARATOR, $includes !== [] ? $includes : [$root . '/program/lib'])) === false) {
        galbridge_cli_refuse('include_path (cannot be set)');
    }
    define('RCMAIL_CONFIG_DIR', $canonical[0]);
    putenv($configPath !== '' ? 'RCUBE_CONFIG_PATH=' . implode(PATH_SEPARATOR, array_slice($canonical, 1)) : 'RCUBE_CONFIG_PATH');
    // The working directory is the last place PHP looks for a relative file name (include, and the
    // fopen() of Roundcube's autoloader), whatever include_path says: the process works in program/,
    // whose every directory and file was checked above. (Relative log_dir / temp_dir values are
    // still found: rcube_config tries "../<value>" as it does for the public_html document root.)
    if (!chdir($root . '/program')) {
        galbridge_cli_refuse($root . '/program (cannot be used as the working directory)');
    }
    // Roundcube's log lines go to syslog (the variable is read by every rcube_config::get(), from
    // the first log line on); PHP's own messages are checked in galbridge_cli_write_problem()
    putenv('ROUNDCUBE_LOG_DRIVER=syslog');
}
define('INSTALL_PATH', $root . '/');

// Standard error is neutralised from here on: Roundcube writes the text of database drivers and
// servers there unchanged (rcube::raise_error() in the command line). Not for "grants" above, whose
// output holds no foreign text.
stream_filter_register(GalBridge\Cli\StderrFilter::NAME, GalBridge\Cli\StderrFilter::class);
stream_filter_append(STDERR, GalBridge\Cli\StderrFilter::NAME, STREAM_FILTER_WRITE);
// PHP's own messages are written by this handler, sanitised and on one line, to the destinations
// PHP would use: the error log when log_errors is on (Roundcube switches it on), and standard error
// when display_errors is "stderr" (the only value Roundcube's bootstrap leaves on). PHP itself
// displays nothing any more, so no message reaches the terminal raw; a fatal error is reported by
// the shutdown handler above.
$galbridgeDisplay = strtolower(trim((string) ini_get('display_errors'))) === 'stderr';
ini_set('display_errors', '0');
set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use ($galbridgeDisplay, $galbridgeRun): bool {
    if ((error_reporting() & $type) === 0) {
        return false; // silenced with @ (or not reported): PHP keeps it for error_get_last() only
    }
    $text = GalBridge\Cli\Terminal::phpMessage($type, $message, $file, $line);
    if (filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN)) {
        error_log($text);
    }
    if ($galbridgeDisplay) {
        fwrite(STDERR, $text . "\n");
    }
    if ($type === E_USER_ERROR || $type === E_RECOVERABLE_ERROR) {
        // fatal for PHP as well: the program ends here, never with the exit code of "OK"
        $galbridgeRun->active = false;
        fwrite(STDOUT, "\nerror: " . GalBridge\Cli\Terminal::clean($text) . "\nResult: aborted\n");
        exit(2);
    }
    return true;
});

$galbridgeRun->active = true;
try {
    if ($galbridgeRoot) {
        require_once INSTALL_PATH . 'program/include/iniset.php';
        $problem = galbridge_cli_write_problem();
        if ($problem !== null) {
            $galbridgeRun->active = false;
            fwrite(STDERR, 'galbridge.sh --allow-root refused: ' . galbridge_cli_printable($problem) . ".\n");
            exit(1);
        }
    }
    require_once INSTALL_PATH . 'program/include/clisetup.php';
    if ($galbridgeRoot) {
        // Roundcube's configuration pointed PHP's error_log at the log_dir checked above; syslog is
        // the destination of the rest of the run (until the plugin reads the configuration afresh,
        // which points it at the same checked log_dir again)
        ini_set('error_log', 'syslog');
    }
    $tool = new GalBridge\Cli\Tool(rcube::get_instance(), realpath(__DIR__ . '/..') ?: __DIR__ . '/..');
    $code = $tool->run($args);
} catch (Throwable $e) {
    // an error during Roundcube's start (a configuration file, a plugin) or in the tool itself
    $galbridgeRun->active = false;
    fwrite(STDOUT, "\nerror: " . GalBridge\Cli\Terminal::clean(GalBridge\Log::sanitize(get_class($e) . ': ' . $e->getMessage())) . "\nResult: aborted\n");
    exit(2);
}
$galbridgeRun->active = false;
exit($code);
