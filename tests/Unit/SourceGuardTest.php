<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Static security checks of the production code (what a release archive executes), run with the
 * PHP tokenizer - no Roundcube, no database, on every supported PHP version.
 *
 * The plugin deliberately has no process execution, no dynamic code, no deserialisation, no file
 * writes and no request handling of its own; SQL is executed in one class and the session is used
 * in four files. These properties were verified by the security audit of 1.0.0. The tests below
 * keep them: a change that needs one of the listed constructs has to add the file to the list here,
 * which makes the change visible in review instead of slipping in.
 *
 * @license GPL-3.0-or-later
 */
final class SourceGuardTest extends TestCase
{
    private const CLI = 'lib/GalBridge/Cli/Tool.php';
    private const SCRIPT = 'bin/galbridge.sh';

    /**
     * Functions that production code may call only in the listed files ([] = nowhere).
     *
     * @var array<string, list<string>>
     */
    private const FUNCTIONS = [
        // process execution, dynamic code, deserialisation, variable injection
        'exec' => [], 'shell_exec' => [], 'system' => [], 'passthru' => [], 'proc_open' => [], 'popen' => [], 'pcntl_exec' => [],
        'pcntl_fork' => [], 'unserialize' => [], 'create_function' => [], 'assert' => [], 'extract' => [], 'parse_str' => [],
        'call_user_func' => [], 'call_user_func_array' => [], 'forward_static_call' => [], 'forward_static_call_array' => [],
        'func_get_args' => [], 'get_defined_vars' => [], 'compact' => [],
        // runtime environment
        // (bin/galbridge.sh --allow-root: logs of a root process go to syslog, never into log_dir, and
        // the working directory is taken off include_path)
        'putenv' => [self::SCRIPT], 'ini_set' => [self::SCRIPT], 'ini_alter' => [], 'set_include_path' => [self::SCRIPT], 'dl' => [], 'mail' => [], 'mb_send_mail' => [],
        // (diag --url turns the warning of a failed request into its own WARN line)
        'set_error_handler' => [self::CLI], 'restore_error_handler' => [self::CLI],
        'set_exception_handler' => [], 'register_shutdown_function' => [], 'spl_autoload_register' => ['lib/autoload.php'],
        'getenv' => ['lib/GalBridge/Config.php', 'lib/GalBridge/Db/Dsn.php', 'lib/GalBridge/Directory/ViewerResolver.php', self::SCRIPT],
        'define' => [self::SCRIPT],
        // network: only "diag --url" (an address given by the operator on the command line)
        'curl_init' => [], 'curl_exec' => [], 'curl_multi_exec' => [], 'fsockopen' => [], 'pfsockopen' => [], 'stream_socket_client' => [],
        'stream_socket_server' => [], 'socket_create' => [], 'get_headers' => [], 'gethostbyname' => [], 'dns_get_record' => [],
        'stream_context_create' => [self::CLI],
        // files: the command line tool reads its SQL templates and looks at the installation, and the
        // script reads /proc/self/status (the user of the process) and lists the directories whose
        // owner it checks before anything is loaded as root; nothing writes
        'fopen' => [self::CLI], 'file_get_contents' => [self::CLI, self::SCRIPT], 'scandir' => [self::CLI, self::SCRIPT],
        'fwrite' => [self::CLI, self::SCRIPT],
        'file_put_contents' => [], 'fputs' => [], 'unlink' => [], 'rename' => [], 'mkdir' => [], 'rmdir' => [], 'chmod' => [], 'chown' => [],
        'chgrp' => [], 'touch' => [], 'copy' => [], 'tempnam' => [], 'tmpfile' => [], 'readfile' => [], 'file' => [], 'glob' => [],
        'opendir' => [], 'symlink' => [], 'link' => [], 'move_uploaded_file' => [], 'parse_ini_file' => [], 'highlight_file' => [],
        'show_source' => [], 'fpassthru' => [],
        // output and diagnostics
        'header' => [], 'setcookie' => [], 'setrawcookie' => [], 'var_dump' => [], 'print_r' => [], 'var_export' => [], 'debug_zval_refcount' => [],
        'debug_print_backtrace' => [], 'phpinfo' => [], 'error_log' => ['lib/GalBridge/Log.php'], 'trigger_error' => [],
        // weak randomness and hashes (the generator of "grants" uses random_bytes / random_int)
        // (sha1: the mysql_native_password verifier that "grants" prints instead of the password)
        'rand' => [], 'mt_rand' => [], 'srand' => [], 'mt_srand' => [], 'uniqid' => [], 'lcg_value' => [], 'md5' => [], 'sha1' => [self::CLI], 'crc32' => [],
    ];

    /**
     * Methods of other objects: SQL is executed by Db\Connection alone (and the tool asks Roundcube's
     * own database for the stored IMAP hosts).
     *
     * @var array<string, list<string>>
     */
    private const METHODS = [
        'query' => ['lib/GalBridge/Db/Connection.php', self::CLI],
        'limitquery' => ['lib/GalBridge/Db/Connection.php'],
        'exec' => [], 'prepare' => [], 'multi_query' => [], 'real_query' => [],
        'db_connect' => ['lib/GalBridge/Db/Connection.php'],
        'sendexit' => ['galbridge.php'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const VARIABLES = [
        '$_GET' => [], '$_POST' => [], '$_REQUEST' => [], '$_COOKIE' => [], '$_FILES' => [], '$_ENV' => [], '$GLOBALS' => [],
        '$_SERVER' => [self::SCRIPT],
        '$_SESSION' => ['galbridge.php', 'lib/GalBridge/AddressBook.php', 'lib/GalBridge/Db/Connection.php', 'lib/GalBridge/Directory/ViewerResolver.php'],
    ];

    /** classes production code never instantiates (Roundcube's rcube_db is the only database access) */
    private const CLASSES = ['pdo', 'mysqli', 'sqlite3', 'reflectionclass', 'reflectionmethod', 'reflectionproperty', 'reflectionfunction', 'reflectionobject'];

    /**
     * @return array<string, array{string}>
     */
    public static function productionFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = ['galbridge.php', self::SCRIPT];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $out[$file] = [$file];
        }
        return $out;
    }

    public function testEveryFileUnderLibIsPhp(): void
    {
        foreach (array_keys(self::productionFiles()) as $file) {
            if ($file !== self::SCRIPT) {
                self::assertStringEndsWith('.php', $file, 'a file that is not PHP code in the production tree');
            }
        }
        self::assertGreaterThan(20, count(self::productionFiles()));
    }

    #[DataProvider('productionFiles')]
    public function testNoDangerousConstruct(string $file): void
    {
        self::assertSame([], self::scan($file, self::tokens($file)), 'constructs that production code must not use (or not in this file)');
    }

    /**
     * The lists above would be worthless if the scanner did not see a construct: every kind is
     * planted once in a sample and must be reported by the same scanner.
     */
    public function testTheScannerSeesWhatItForbids(): void
    {
        $sample = <<<'PHP'
            <?php
            eval('1;');
            echo `id`;
            include $file;
            require_once $file;
            $x = unserialize($data);
            $db->exec($sql);
            $rows = $db->query($sql);
            $pdo = new \PDO($dsn);
            $a = $_GET['a'];
            $s = $_SESSION['s'];
            $b = new $class();
            $c = $this->$method();
            $d = $this->{$name}();
            $e = $$name;
            \system('id');
            file_put_contents($path, $data);
            fopen($path, 'r');
            exit(1);
            ?>
            text
            PHP;
        $tokens = array_values(array_filter(\PhpToken::tokenize($sample, TOKEN_PARSE), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $found = array_map(static fn (string $f): string => (string) preg_replace('/^sample\.php:\d+ /', '', $f), self::scan('sample.php', $tokens));
        foreach ([
            'eval', 'echo', 'the shell execution operator', 'include', 'require_once', 'unserialize()', '->exec()', '->query()', 'new \PDO',
            '$_GET', '$_SESSION', 'a class or variable named by a variable', 'a method named by a variable', 'a member named by an expression',
            '\system()', 'file_put_contents()', 'fopen()', 'exit', 'text outside PHP tags',
        ] as $kind) {
            self::assertContains($kind, $found, "the scanner does not report '{$kind}'");
        }
        self::assertSame(2, count(array_keys($found, 'a class or variable named by a variable', true)), 'new $class and $$name');

        // and it does not report what is allowed: a class constant named like a keyword, a method
        // of the same name as a forbidden function when it is DECLARED, a closure that is called
        $clean = <<<'PHP'
            <?php
            final class Sample
            {
                public const GLOBAL = 'g';
                public function exec(string $s): string { return self::GLOBAL . $s; }
                public function run(\Closure $c): mixed { return $c() ?? ($this->run)(); }
            }
            PHP;
        $tokens = array_values(array_filter(\PhpToken::tokenize($clean, TOKEN_PARSE), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        self::assertSame([], self::scan('sample.php', $tokens));
    }

    /**
     * @param list<\PhpToken> $tokens significant tokens
     *
     * @return list<string> "file:line construct"
     */
    private static function scan(string $file, array $tokens): array
    {
        $found = [];
        foreach ($tokens as $i => $t) {
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $at = $file . ':' . $t->line;

            if ($t->is([T_EVAL, T_ECHO, T_PRINT, T_GLOBAL, T_GOTO, T_HALT_COMPILER, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE])) {
                $found[] = "{$at} " . strtolower($t->text);
            } elseif ($t->is(T_REQUIRE_ONCE) && !in_array($file, ['galbridge.php', self::SCRIPT, 'lib/autoload.php'], true)) {
                $found[] = "{$at} require_once";
            } elseif ($t->is(T_EXIT) && $file !== self::SCRIPT) {
                $found[] = "{$at} exit";
            } elseif ($t->is('`')) {
                $found[] = "{$at} the shell execution operator";
            } elseif ($t->is(T_INLINE_HTML) && !($file === self::SCRIPT && $t->line === 1 && str_starts_with($t->text, '#!'))) {
                $found[] = "{$at} text outside PHP tags";
            } elseif ($t->is(T_VARIABLE)) {
                if (isset(self::VARIABLES[$t->text]) && !in_array($file, self::VARIABLES[$t->text], true)) {
                    $found[] = "{$at} {$t->text}";
                }
                if ($prev !== null && $prev->is([T_NEW, '$'])) {
                    $found[] = "{$at} a class or variable named by a variable";
                }
                if ($prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) && $next !== null && $next->is('(')) {
                    $found[] = "{$at} a method named by a variable";
                }
            } elseif ($t->is('{') && $prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                $found[] = "{$at} a member named by an expression";
            } elseif ($t->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
                $name = strtolower(ltrim($t->text, '\\'));
                if ($prev !== null && $prev->is(T_NEW)) {
                    if (in_array($name, self::CLASSES, true)) {
                        $found[] = "{$at} new {$t->text}";
                    }
                } elseif ($next !== null && $next->is('(') && ($prev === null || !$prev->is([T_FUNCTION, T_FN]))) {
                    $method = $prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]);
                    $allowed = $method ? (self::METHODS[$name] ?? null) : (self::FUNCTIONS[$name] ?? null);
                    if ($allowed !== null && !in_array($file, $allowed, true)) {
                        $found[] = "{$at} " . ($method ? '->' : '') . "{$t->text}()";
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Roundcube include()s the localization files and the configuration template is copied to a
     * file that is include()d: both hold assignments of literals and nothing else.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function dataFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = ['config.inc.php.dist' => ['config.inc.php.dist', ['$config']]];
        foreach (glob($root . '/localization/*.inc') ?: [] as $file) {
            $out['localization/' . basename($file)] = ['localization/' . basename($file), ['$labels', '$messages']];
        }
        return $out;
    }

    /**
     * @param list<string> $variables
     */
    #[DataProvider('dataFiles')]
    public function testDataFilesHoldOnlyLiterals(string $file, array $variables): void
    {
        $found = [];
        foreach (self::tokens($file) as $t) {
            $ok = $t->is([T_OPEN_TAG, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, '[', ']', '=', ',', ';', '-'])
                || ($t->is(T_VARIABLE) && in_array($t->text, $variables, true))
                || ($t->is(T_STRING) && in_array(strtolower($t->text), ['true', 'false', 'null'], true));
            if (!$ok) {
                $found[] = $file . ':' . $t->line . ' ' . $t->getTokenName() . ' ' . substr($t->text, 0, 40);
            }
        }
        self::assertSame([], $found, 'a data file holds something else than assignments of literals');
        self::assertGreaterThan(10, count(self::tokens($file)));
    }

    /**
     * The files of the plugin directory are never meant to be served: the shipped .htaccess denies
     * everything (Apache); for other web servers README 2.6 gives the rule and "diag --url" checks it.
     */
    public function testThePluginDirectoryDeniesWebAccess(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
        $rules = array_values(array_filter(array_map('trim', explode("\n", $htaccess)), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
        self::assertSame(['Require all denied'], $rules);
    }

    /**
     * Significant tokens of a repository file.
     *
     * @return list<\PhpToken>
     */
    private static function tokens(string $file): array
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        self::assertNotSame('', $code, $file);
        // TOKEN_PARSE: identifiers that are keywords elsewhere (BookDefinition::GLOBAL) are names
        return array_values(array_filter(\PhpToken::tokenize($code, TOKEN_PARSE), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
    }
}
