<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.0.4: what a second installation on real iRedMail 1.8.11 servers - by administrators who knew
 * nothing but the documentation - showed the documentation had to say. Each assertion pins a step
 * whose absence made the installation fail, weaker or misleading (CHANGELOG 1.0.4).
 *
 * @license GPL-3.0-or-later
 */
final class InstallationDocsTest extends TestCase
{
    private static function read(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    }

    private static function flat(string $file): string
    {
        return (string) preg_replace('/\s+/', ' ', self::read($file));
    }

    /**
     * @return string the text of one README section, from its heading to the next one of that level
     */
    private static function section(string $heading): string
    {
        $level = strspn($heading, '#');
        $out = [];
        $inside = false;
        $fence = false;
        foreach (explode("\n", self::read('README.md')) as $line) {
            if (str_starts_with($line, '```')) {
                $fence = !$fence;
            }
            if (!$fence && preg_match('/^(#{1,6}) /', $line, $m) === 1) {
                if ($inside && strlen($m[1]) <= $level) {
                    break;
                }
                if (str_starts_with($line, $heading)) {
                    $inside = true;
                }
            }
            if ($inside) {
                $out[] = $line;
            }
        }
        self::assertNotSame([], $out, $heading);
        return implode("\n", $out);
    }

    /**
     * iRedMail's pg_hba.conf accepts password logins over the unix socket for every database
     * ("local all all md5"): host lines alone never apply to a socket connection.
     */
    public function testThePgHbaLinesAlsoRefuseTheSocketAndGoBeforeIredmailsLocalLine(): void
    {
        $s = self::section('### 2.2 ');
        self::assertSame(1, preg_match('/```\n(local +all +galbridge +reject[^\n]*)\n(host +vmail +galbridge +127\.0\.0\.1\/32 +scram-sha-256[^\n]*)\n(host +all +galbridge +0\.0\.0\.0\/0 +reject[^\n]*)\n(host +all +galbridge +::\/0 +reject)\n```/', $s), 'the four lines, the socket line first');
        self::assertStringContainsString("**before** iRedMail's `local all all md5` line", $s);
        self::assertStringContainsString('/etc/postgresql/17/main/pg_hba.conf', $s);
        self::assertStringContainsString('pg_hba_file_rules', $s);
        self::assertStringContainsString('systemctl reload postgresql', $s);
        foreach (['-h 127.0.0.1 -U galbridge -d roundcubemail', '-U galbridge -d roundcubemail'] as $test) {
            self::assertStringContainsString("psql -X -w {$test} -c ''", $s, 'both connection kinds are tested');
        }
        self::assertStringContainsString('local vmail galbridge scram-sha-256', $s, 'the socket DSN variant');
        foreach (['config.inc.php.dist', 'SQL/grants.postgres.sql', 'SECURITY.md'] as $file) {
            self::assertStringContainsString('local all all md5', self::flat($file), $file);
        }
        self::assertStringContainsString('this tool cannot read pg_hba.conf', self::read('lib/GalBridge/Cli/Tool.php'));
    }

    /**
     * iRedMail's PostgreSQL keeps max_connections = 100 for every service, its PHP-FPM pool has
     * 100 children: the connection limit names both bounds, next to the command.
     */
    public function testTheConnectionLimitAdviceNamesTheDatabaseServersBound(): void
    {
        $s = (string) preg_replace('/\s+/', ' ', self::section('### 2.2 '));
        self::assertStringContainsString('**Keep the default 25 on iRedMail**', $s);
        self::assertStringContainsString('MariaDB `max_connections = 151`', $s);
        self::assertStringNotContainsString('--connection-limit=100 >', $s, 'neither of iRedMail\'s database servers has room for 100 by default');
        self::assertStringContainsString('2> /root/galbridge-dsn.txt # MariaDB/MySQL edition', $s, 'the DSN line goes into the file 2.3 reads');
    }

    /**
     * Neither sudo nor curl is part of iRedMail; runuser (util-linux) always is.
     */
    public function testEveryDiagCommandHasAFormWithoutSudo(): void
    {
        $intro = (string) preg_replace('/\s+/', ' ', self::section('## 2. Installation'));
        self::assertStringContainsString('`runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag`', $intro);
        self::assertStringContainsString('`apt install curl`', $intro);
        self::assertStringContainsString('`apt install sudo`', $intro);
        foreach (['### 2.7 ', '## 7. Troubleshooting'] as $heading) {
            self::assertStringContainsString('runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag', (string) preg_replace('/\s+/', ' ', self::section($heading)), $heading);
        }
        foreach (['config.inc.php.dist', 'docs/MIGRATION.md', 'SQL/grants.mysql.sql', 'SQL/grants.postgres.sql', 'bin/galbridge.sh', 'SECURITY.md'] as $file) {
            self::assertStringContainsString('runuser -u www-data --', self::read($file), $file);
        }
    }

    /**
     * Composer is not installed by iRedMail, only root can run it there, and a root run without a
     * terminal disables the plugin installer.
     */
    public function testTheComposerPathCoversWhatIredmailNeeds(): void
    {
        $s = (string) preg_replace('/\s+/', ' ', self::section('### 2.1 '));
        foreach (['apt install composer', 'Continue as root/super user [yes]?', 'COMPOSER_ALLOW_SUPERUSER=1', 'vendor/takenek/galbridge',
            'ls -d plugins/galbridge', 'Do you want to activate the plugin galbridge? [Y|n]', 'diff -r /root/galbridge-check/ plugins/galbridge/',
            'source : [git] https://github.com/takenek/galbridge.git <commit>', 'chown root:www-data config/config.inc.php && chmod 0640 config/config.inc.php'] as $text) {
            self::assertStringContainsString($text, $s);
        }
        self::assertStringNotContainsString('then skip 2.4', $s);
        // 1.0.5: the release archive is unpacked only after verify-tag and the fingerprint comparison
        $raw = self::section('### 2.1 ');
        self::assertLessThan(strpos($raw, 'archive --prefix=galbridge/ "v$v"'), strpos($raw, 'ssh-keygen -lf -'));
        self::assertSame(1, preg_match('/```sh\n[^`]*verify-tag "v\$v"\n```/', $raw), 'the first block ends with verify-tag');
        self::assertSame(1, preg_match('/```sh\n[^`]*\ncomposer require --prefer-dist --ignore-platform-req=ext-ldap "takenek\/galbridge:\^1\.0"/', self::section('### 2.1 ')), 'the command that works on iRedMail comes first');
        // the order: config.inc.php to root before Composer loads it as root
        $block = self::section('### 2.1 ');
        self::assertLessThan(strpos($block, 'composer require --prefer-dist --ignore-platform-req'), strpos($block, 'chown root:www-data config/config.inc.php'));
        $remove = (string) preg_replace('/\s+/', ' ', self::section('## 6. '));
        self::assertStringContainsString('composer remove --ignore-platform-req=ext-ldap takenek/galbridge', $remove);
        self::assertStringContainsString('`ls -d plugins/galbridge` must fail', $remove);
        self::assertStringContainsString('COMPOSER_ALLOW_SUPERUSER=1', $remove, 'remove, too, needs a terminal or the variable');
    }

    /**
     * grants prints the DSN as an SQL comment: pasted with "-- " the configuration does not parse.
     */
    public function testTheDsnLineIsPastedWithoutTheCommentMarker(): void
    {
        self::assertStringContainsString('**without the leading `-- `**', self::flat('README.md'));
        self::assertStringContainsString('php -l config/galbridge.inc.php', self::section('### 2.3 '));
        self::assertStringContainsString('WITHOUT the leading "-- "', self::flat('config.inc.php.dist'));
        // the tool's own format, which the documentation describes
        self::assertStringContainsString("\$this->err(\"-- \\\$config['galbridge_db_dsn'] = '{\$dsn}';\\n\");", self::read('lib/GalBridge/Cli/Tool.php'));
    }

    /**
     * The commands of section 5 run in the Roundcube directory, as the PHP user; iRedMail updates
     * Roundcube in place with installto.sh, which copies the owner of the release tarball.
     */
    public function testTheUpgradeSectionMatchesIredmailsProcedure(): void
    {
        $s = (string) preg_replace('/\s+/', ' ', self::section('## 5. Upgrade'));
        self::assertStringNotContainsString('run `bin/galbridge.sh diag`', $s);
        self::assertStringContainsString('`sudo -u www-data plugins/galbridge/bin/galbridge.sh diag` (without sudo: `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag`) from the Roundcube directory', $s);
        // 1.0.5: the release-archive upgrade checks for files of the old release; the 1.0.3 limit and the socket DSN
        self::assertStringContainsString('diff -r /root/galbridge-check/ plugins/galbridge/ && echo identical # "Only in plugins/galbridge/: X"', $s);
        self::assertStringContainsString('composer update --prefer-dist --ignore-platform-req=ext-ldap takenek/galbridge', $s);
        self::assertStringContainsString('first put `local vmail galbridge scram-sha-256` there', $s);
        self::assertStringContainsString('grants --allow-root --driver=mysql --grants-only > /root/galbridge-grants.sql', $s);
        foreach (['./bin/installto.sh /opt/www/roundcubemail', 'tar --no-same-owner -xzf', 'composer update --no-dev --prefer-dist --ignore-platform-req=ext-ldap', 'roundcube.tmpl'] as $text) {
            self::assertStringContainsString($text, $s);
        }
        self::assertStringNotContainsString('its upgrade procedure creates a new tree', $s);
    }

    /**
     * The certificate of iRedMail names the host, never 127.0.0.1; Debian's FPM binary carries its
     * version; iRedMail ships addressbook_sort_col commented out.
     */
    public function testIredmailSpecificsAreSpelledOut(): void
    {
        self::assertStringContainsString('openssl x509 -in /etc/ssl/certs/iRedMail.crt -noout -subject', self::flat('README.md'));
        self::assertStringContainsString('php-fpm8.4 -m', self::flat('README.md'));
        self::assertStringContainsString("'.' . PHP_MINOR_VERSION . ' -m)", self::read('lib/GalBridge/Cli/Tool.php'));
        self::assertStringContainsString("(`//\$config['addressbook_sort_col'] = 'name';`)", self::flat('README.md'));
        self::assertStringContainsString("\$config['plugins'] = array('managesieve', 'password', 'zipdownload', 'galbridge');", self::read('README.md'));
        self::assertStringContainsString('namei -l "$PWD/plugins/galbridge/bin/galbridge.sh"', self::read('README.md'));
    }

    /**
     * 1.0.6: what a review of the 1.0.5 documentation and two more installations on real iRedMail
     * 1.8.11 servers showed. Pasted in their order, the code blocks of section 2 work: no block leaves
     * the shell in another directory than the Roundcube directory (the 2.6 nginx block of 1.0.5 did,
     * and the diag commands after it failed), and only one grants line of 2.2 runs.
     */
    public function testTheBlocksOfSection2RunInTheirOrder(): void
    {
        $s = self::section('## 2. Installation');
        self::assertGreaterThan(5, (int) preg_match_all('/^ *```sh\n(.*?)^ *```/ms', $s, $blocks));
        $withTool = 0;
        foreach ($blocks[1] as $block) {
            preg_match_all('/^ *cd (\S+)/m', $block, $cd);
            foreach ($cd[1] as $dir) {
                self::assertSame('/opt/www/roundcubemail', $dir, "a block of section 2 changes into {$dir}:\n{$block}");
            }
            if (preg_match('/^ *(sudo -u www-data |runuser -u www-data -- )?plugins\/galbridge\/bin\/galbridge\.sh /m', $block) === 1) {
                $withTool++;
                self::assertSame(1, preg_match('/\A *cd \/opt\/www\/roundcubemail\n/', $block), "a block that runs the tool starts in the Roundcube directory:\n{$block}");
            }
        }
        self::assertGreaterThanOrEqual(3, $withTool, '2.2, the diag --url block of 2.6 and 2.7');
        self::assertStringContainsString('for t in /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl; do', self::section('### 2.6 '));

        $grants = self::section('### 2.2 ');
        self::assertSame(1, preg_match_all('/^plugins\/galbridge\/bin\/galbridge\.sh grants --allow-root --driver=(mysql|pgsql) >/m', $grants), 'one runnable grants line');
        self::assertSame(1, preg_match('/^# plugins\/galbridge\/bin\/galbridge\.sh grants --allow-root --driver=pgsql > \/root\/galbridge-grants\.sql 2> \/root\/galbridge-dsn\.txt$/m', $grants), 'the other edition as a comment');
    }

    /**
     * 1.0.6: what the documentation says a command prints is what it prints - the review diff of the
     * PostgreSQL --grants-only output (1.0.5 named only the missing CREATE ROLE line), diag --strict
     * without --url, composer show without a terminal; and two Composer paths that lose the
     * configuration or install nothing.
     */
    public function testTheDocumentedOutputsMatchTheTools(): void
    {
        $grants = (string) preg_replace('/\s+/', ' ', self::section('### 2.2 '));
        self::assertStringNotContainsString("shows only the template's `CREATE USER` / `CREATE ROLE` line as missing", $grants);
        self::assertStringContainsString("MariaDB/MySQL: the `diff` shows only the template's `CREATE USER` line as missing", $grants);
        self::assertStringContainsString('`ALTER ROLE galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;`', $grants);
        self::assertStringContainsString('"galbridge" does not exist: ...\'`', $grants);
        $tool = self::read('lib/GalBridge/Cli/Tool.php');
        self::assertStringContainsString("\$exists = \"IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{\$role}') THEN\";", $tool);
        self::assertStringContainsString('does not exist: nothing was changed (create it without --grants-only)', $tool);
        self::assertStringContainsString('"ALTER ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;"', $tool);
        self::assertStringContainsString('CREATE ROLE galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT PASSWORD', self::read('SQL/grants.postgres.sql'));

        $test = self::section('### 2.7 ');
        self::assertSame(1, preg_match('/^sudo -u www-data plugins\/galbridge\/bin\/galbridge\.sh diag --strict --url=https:\/\/mail\.example\.com\/mail\//m', $test), 'the automated check includes the web check');
        self::assertStringContainsString('Result: OK (not checked: web access - run with --url)', (string) preg_replace('/\s+/', ' ', $test));
        self::assertStringContainsString("' (not checked: web access - run with --url)'", $tool);
        self::assertStringContainsString('`diag --strict` with the `--url=` of the webmail', self::flat('SECURITY.md'));
        self::assertStringContainsString("`\$config['use_https'] = true;`", self::flat('SECURITY.md'));

        self::assertStringContainsString('without a terminal it disables its plugins and answers `Package "takenek/galbridge" not found`', (string) preg_replace('/\s+/', ' ', self::section('### 2.1 ')));
        self::assertStringContainsString('`composer require` empties `plugins/galbridge` before it unpacks the release', self::flat('README.md'));
        $upgrade = (string) preg_replace('/\s+/', ' ', self::section('## 5. Upgrade'));
        self::assertStringContainsString('`Package "takenek/galbridge" listed for update is not locked.`', $upgrade);
    }

    /**
     * The README's checklist links only to headings that exist (GitHub's anchor slugs).
     */
    public function testTheChecklistLinksResolve(): void
    {
        $readme = self::read('README.md');
        preg_match_all('/^#{1,6} (.+)$/m', $readme, $h);
        $slugs = [];
        foreach ($h[1] as $title) {
            $slug = strtolower(trim($title));
            $slug = (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', $slug);
            $slugs[] = str_replace(' ', '-', $slug);
        }
        self::assertGreaterThan(0, (int) preg_match_all('/\]\(#([^)]+)\)/', $readme, $links));
        foreach ($links[1] as $anchor) {
            self::assertContains($anchor, $slugs, "README link #{$anchor}");
        }
        self::assertGreaterThanOrEqual(7, count($links[1]));
    }
}
