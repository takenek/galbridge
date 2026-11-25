<?php

declare(strict_types=1);

namespace GalBridge\Tests\Unit;

use GalBridge\Cli\Tool;
use GalBridge\Tests\Support\Workflow;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-21 / F-25 / F-34: what CI does with dependencies, skipped tests, root
 * and the end-to-end suites - checked on .github/workflows/ci.yml itself, so that the workflow and
 * the statements of its header, SECURITY.md (GB-32) and docs/RELEASING.md cannot drift apart.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2CiTest extends TestCase
{
    /**
     * Composer's policies: only the blocking of releases with ADVISORIES may be switched off, only in
     * job "tests" and only for "composer install"; nothing switches off the malware or the
     * abandoned-package policy, or all of them at once (COMPOSER_NO_BLOCKING did that in 1.0.1).
     */
    public function testNoStepSwitchesOffEveryComposerPolicy(): void
    {
        $text = Workflow::text();
        foreach (['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING', '--no-blocking', '--no-security-blocking', 'COMPOSER_POLICY_MALWARE_BLOCK',
            'COMPOSER_POLICY_ABANDONED_BLOCK', 'COMPOSER_SECURITY_BLOCKING_ABANDONED', 'COMPOSER_AUDIT_ABANDONED', '--abandoned', '--ignore-severity',
            '--no-audit', 'policy.malware', 'policy.abandoned', '"policy": false'] as $never) {
            self::assertStringNotContainsString($never, $text, "ci.yml must not use {$never}");
        }
        $where = [];
        foreach (Workflow::jobs() as $job => $block) {
            foreach (Workflow::steps($job) as $step) {
                if (str_contains(Workflow::code($step), 'COMPOSER_POLICY_ADVISORIES_BLOCK')) {
                    $where[] = $job;
                    self::assertStringContainsString("COMPOSER_POLICY_ADVISORIES_BLOCK: '0'", $step);
                    self::assertStringContainsString('run: composer install --no-interaction --no-progress', $step, 'only the install step');
                }
            }
            $env = substr($block, 0, (int) strpos($block, '    steps:'));
            self::assertStringNotContainsString('COMPOSER_POLICY', Workflow::code($env), "job {$job}: not for the whole job");
        }
        self::assertSame(['tests'], $where);
    }

    /**
     * Composer comes from getcomposer.org in a pinned version and runs only after its SHA-256 matched;
     * setup-php installs no tools (its "composer:v2" was whatever version the action found).
     */
    public function testComposerIsPinnedAndVerifiedBySha256(): void
    {
        $text = Workflow::text();
        self::assertSame(1, preg_match("/^  COMPOSER_VERSION: '(2\\.\\d+\\.\\d+)'$/m", $text, $version), 'COMPOSER_VERSION at workflow level');
        self::assertSame(1, preg_match('/^  COMPOSER_SHA256: [0-9a-f]{64}$/m', $text), 'COMPOSER_SHA256 at workflow level');
        self::assertStringNotContainsString('composer:v2', $text);
        self::assertSame(0, preg_match('/^\s+tools: (?!none$).*$/m', $text), 'setup-php installs no tools');
        $jobs = 0;
        foreach (array_keys(Workflow::jobs()) as $job) {
            $first = null;
            foreach (Workflow::steps($job) as $i => $step) {
                if (preg_match('/^\s+(?:run: )?composer [a-z]/m', Workflow::code($step)) === 1 && !str_contains($step, 'sha256sum --check')) {
                    $first = $i;
                    break;
                }
            }
            if ($first === null) {
                continue;
            }
            $jobs++;
            $install = Workflow::stepIndex($job, 'sha256sum --check --strict');
            self::assertNotNull($install, "job {$job} runs composer without the verified install");
            self::assertLessThan($first, $install, "job {$job}: Composer is verified before its first use");
            $step = Workflow::steps($job)[$install];
            self::assertStringContainsString('https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar', $step);
            self::assertStringContainsString('echo "$COMPOSER_SHA256  $RUNNER_TEMP/composer.phar" | sha256sum --check --strict -', $step);
            self::assertStringContainsString('composer --version | grep -F "Composer version $COMPOSER_VERSION "', $step);
        }
        self::assertSame(4, $jobs, 'tests, databases, phpstan and coding-style resolve dependencies');
    }

    public function testJobsRunOnAPinnedRunnerImage(): void
    {
        $text = Workflow::text();
        self::assertStringNotContainsString('ubuntu-latest', $text);
        foreach (Workflow::jobs() as $job => $block) {
            self::assertSame(1, preg_match('/^    runs-on: ubuntu-24\.04$/m', $block), "job {$job} runs on ubuntu-24.04");
            if (preg_match('/^    container:\n      image: (\S+)$/m', $block, $m) === 1) {
                self::assertMatchesRegularExpression('/^[a-z0-9.\/:-]+@sha256:[0-9a-f]{64}$/D', $m[1], "job {$job}: the container image is pinned by digest");
            }
            foreach (['image: mariadb', 'image: postgres'] as $service) {
                if (str_contains($block, $service)) {
                    self::assertMatchesRegularExpression('/' . preg_quote($service, '/') . ':[0-9.]+@sha256:[0-9a-f]{64}\n/', $block);
                }
            }
        }
        self::assertSame(0, preg_match('/uses: [^@\s]+@(?![0-9a-f]{40} # )/', $text), 'every action pinned to a full commit SHA with its tag');
    }

    /**
     * Every image of the workflow - service containers (also those a matrix names), the container of
     * job "e2e" - is pinned by digest, and one tag has one digest in the whole file: a refreshed pin
     * cannot leave an older digest of the same tag behind in another job.
     */
    public function testEveryImageIsPinnedByDigestOncePerTag(): void
    {
        $code = Workflow::code(Workflow::text());
        preg_match_all('/\b(?:mariadb|mysql|postgres|debian):[0-9][0-9a-z.-]*(?:@sha256:[0-9a-f]{64})?/', $code, $m);
        self::assertGreaterThanOrEqual(17, count($m[0]), 'the images of the jobs tests, databases and e2e');
        $digests = [];
        foreach ($m[0] as $reference) {
            self::assertMatchesRegularExpression('/^[a-z]+:[0-9][0-9a-z.-]*@sha256:[0-9a-f]{64}$/D', $reference, 'pinned by digest');
            [$tag, $digest] = explode('@', $reference);
            $digests[$tag][$digest] = true;
        }
        foreach ($digests as $tag => $seen) {
            self::assertCount(1, $seen, "one digest for {$tag}");
        }
        foreach (Workflow::jobs() as $job => $block) {
            preg_match_all('/^\s+image: (\S+)$/m', Workflow::code($block), $images);
            foreach ($images[1] as $image) {
                self::assertMatchesRegularExpression('/^(?:[a-z0-9.\/-]+:[0-9][0-9a-z.-]*@sha256:[0-9a-f]{64}|\$\{\{ matrix\.[a-z]+ \}\})$/D', $image, "job {$job}: a pinned image or a matrix value (checked above)");
            }
        }
    }

    /**
     * Job "databases": the supported server versions of README section 1 that job "tests" does not
     * run, each MariaDB/MySQL server paired with a PostgreSQL server (so that no database test has a
     * reason to skip), PHP 8.4 and the newest core as in job "phpstan", the fixture loaded as in job
     * "tests" with the client of the leg's image, and a check that the servers are what the leg names.
     */
    public function testTheDatabaseJobPairsEverySupportedServerFamily(): void
    {
        $block = Workflow::jobs()['databases'] ?? '';
        self::assertNotSame('', $block, 'job databases');
        $code = Workflow::code($block);
        $legs = self::databaseLegs();
        $pairs = array_map(static fn (array $leg): string => strstr($leg['server'], '@', true) . ' + ' . strstr($leg['postgres'], '@', true), $legs);
        self::assertSame(['mariadb:10.6 + postgres:13', 'mariadb:10.11 + postgres:14', 'mariadb:11.4 + postgres:15', 'mariadb:12.3 + postgres:16',
            'mysql:8.0 + postgres:18', 'mysql:8.4 + postgres:19', 'mysql:9.7 + postgres:17'], $pairs);
        foreach ($legs as $leg) {
            self::assertSame(1, preg_match('/^(mariadb|mysql):([0-9.]+)@/', $leg['server'], $server));
            self::assertSame(1, preg_match('/^postgres:([0-9]+)@/', $leg['postgres'], $postgres));
            self::assertSame($server[1], $leg['flavour'], 'the flavour is the image');
            self::assertSame("{$server[1]}-{$server[2]}-postgres-{$postgres[1]}", $leg['leg'], 'the leg names the flavour and both tags');
        }
        // PostgreSQL 17 is the image of job "tests" (one digest per tag: testEveryImageIsPinnedByDigestOncePerTag)
        self::assertStringContainsString('image: ' . $legs[6]['postgres'] . "\n", Workflow::jobs()['tests'] ?? '');

        foreach (['name: Database servers (${{ matrix.leg }})', 'runs-on: ubuntu-24.04', 'fail-fast: false', 'FLAVOUR: ${{ matrix.flavour }}',
            'LEG: ${{ matrix.leg }}', "      mysql:\n        image: \${{ matrix.server }}\n", "      postgres:\n        image: \${{ matrix.postgres }}\n",
            'MARIADB_ROOT_PASSWORD: ci-throwaway', 'MYSQL_ROOT_PASSWORD: ci-throwaway', 'POSTGRES_PASSWORD: ci-throwaway',
            "--health-cmd=\"\${{ matrix.flavour == 'mariadb' && 'healthcheck.sh --connect --innodb_initialized' || 'mysqladmin ping -h 127.0.0.1 --silent' }}\"",
            '--health-cmd="pg_isready -U postgres -h 127.0.0.1"', "php-version: '8.4'", 'run: composer require --dev --no-update --no-interaction "roundcube/roundcubemail:~1.7.0"',
            'name: composer-lock-databases-${{ matrix.leg }}', 'MYSQL_CID: ${{ job.services.mysql.id }}', 'POSTGRES_CID: ${{ job.services.postgres.id }}',
            'export MYSQL_ADMIN="docker exec -i $MYSQL_CID $FLAVOUR -u root -pci-throwaway"',
            'export PSQL_ADMIN="docker exec -i -e PGPASSWORD=ci-throwaway $POSTGRES_CID psql -U postgres"',
            '[[ "$LEG" =~ ^(mariadb|mysql)-([0-9]+\.[0-9]+)-postgres-([0-9]+)$ ]] || return 1', 'leg_matches || {',
            "tests/fixtures/vmail/load.sh mysql | tee \"\$out/mysql.txt\"\n", "tests/fixtures/vmail/load.sh pgsql | tee \"\$out/pgsql.txt\"\n",
            'test "$(wc -l < "$out/dsn.env")" -eq 2', 'run: vendor/bin/phpunit --fail-on-skipped --exclude-group root'] as $part) {
            self::assertStringContainsString($part, $code);
        }
        // the newest core is the one job "phpstan" analyses
        self::assertStringContainsString('"roundcube/roundcubemail:~1.7.0"', Workflow::code(Workflow::jobs()['phpstan'] ?? ''));
        // load.sh handles MySQL 8.4 and later itself: no wrapper rewrites the fixture's statements
        self::assertStringNotContainsString('MYADMIN_WRAP', Workflow::text());
        self::assertStringNotContainsString('--allow-skips', $code);
        self::assertStringNotContainsString('continue-on-error', $code);
        self::assertStringContainsString('job "databases"', Workflow::text(), 'the design notes describe the job');

        // README section 1 names every leg of the job, docs/RELEASING.md refreshes their pins
        $root = dirname(__DIR__, 2);
        $readme = (string) file_get_contents($root . '/README.md');
        $requirements = substr($readme, (int) strpos($readme, '## 1. Requirements'), (int) strpos($readme, '## 2. Installation') - (int) strpos($readme, '## 1. Requirements'));
        foreach ($legs as $leg) {
            preg_match('/^(mariadb|mysql):([0-9.]+)@/', $leg['server'], $server);
            preg_match('/^postgres:([0-9]+)@/', $leg['postgres'], $postgres);
            $label = ($server[1] === 'mariadb' ? 'MariaDB' : 'MySQL') . " {$server[2]} + PostgreSQL {$postgres[1]}";
            self::assertStringContainsString($label, $requirements, "README section 1 names the CI leg {$leg['leg']}");
        }
        $releasing = (string) file_get_contents($root . '/docs/RELEASING.md');
        self::assertStringContainsString('job `databases`', $releasing);
        self::assertStringContainsString('composer-lock-databases-<leg>', $releasing);
    }

    /**
     * The matrix entries of job "databases" in their order.
     *
     * @return list<array{leg: string, flavour: string, server: string, postgres: string}>
     */
    private static function databaseLegs(): array
    {
        $block = Workflow::code(Workflow::jobs()['databases'] ?? '');
        preg_match_all('/^          - leg: (\S+)\n            flavour: (\S+)\n            server: (\S+)\n            postgres: (\S+)$/m', $block, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m, 'job databases: matrix entries of leg, flavour, server and postgres');
        self::assertSame(count($m), substr_count($block, "\n          - leg: "), 'every entry in the expected layout');
        return array_map(static fn (array $e): array => ['leg' => $e[1], 'flavour' => $e[2], 'server' => $e[3], 'postgres' => $e[4]], $m);
    }

    /**
     * Every job that resolves dependencies audits them plainly (no "|| true", no JSON filter), writes
     * them to its summary with the source commit of each package, and keeps its composer.lock.
     */
    public function testEveryResolvedDependencySetIsAuditedRecordedAndKept(): void
    {
        $found = [];
        foreach (array_keys(Workflow::jobs()) as $job) {
            $install = Workflow::stepIndex($job, 'composer install');
            if ($install === null) {
                continue;
            }
            $found[] = $job;
            $steps = Workflow::steps($job);
            $audit = Workflow::stepIndex($job, 'composer audit');
            self::assertNotNull($audit, "job {$job} audits its dependencies");
            self::assertGreaterThan($install, $audit);
            self::assertStringContainsString('run: composer audit --no-interaction --format=plain', $steps[$audit]);
            self::assertStringNotContainsString('||', $steps[$audit]);
            $record = Workflow::stepIndex($job, 'GITHUB_STEP_SUMMARY');
            self::assertNotNull($record, "job {$job} records the resolved set");
            self::assertGreaterThan($install, $record);
            foreach (['jq -r', '.source.reference // .dist.reference', 'composer.lock', 'sha256sum composer.lock', 'ImageVersion', 'PHP_VERSION', 'composer --version'] as $part) {
                self::assertStringContainsString($part, $steps[$record], "job {$job}: the record has {$part}");
            }
            $upload = Workflow::stepIndex($job, 'actions/upload-artifact@');
            self::assertNotNull($upload, "job {$job} keeps its composer.lock");
            self::assertGreaterThan($install, $upload);
            self::assertMatchesRegularExpression('/uses: actions\/upload-artifact@[0-9a-f]{40} # v\d+\.\d+\.\d+\n/', $steps[$upload]);
            self::assertMatchesRegularExpression('/name: composer-lock-' . preg_quote($job, '/') . '[-a-z0-9.${}| \'=&]*\n/', $steps[$upload]);
            foreach (['path: composer.lock', 'retention-days: 90', 'if-no-files-found: error'] as $part) {
                self::assertStringContainsString($part, $steps[$upload]);
            }
        }
        self::assertSame(['tests', 'databases', 'phpstan', 'coding-style'], $found);
        self::assertStringNotContainsString('--format=json', Workflow::text(), 'no audit output is filtered');
    }

    /**
     * The pinned 1.7.0 core is the only audit exception: one runner-local ignore entry, written in the
     * 1.7.0 legs only, with the constraint 1.7.0.
     */
    public function testThePinnedOldCoreIsTheOnlyAuditException(): void
    {
        $code = Workflow::code(Workflow::text());
        self::assertSame(1, substr_count($code, 'policy.advisories.ignore'));
        $i = Workflow::stepIndex('tests', 'policy.advisories.ignore');
        self::assertNotNull($i);
        $step = Workflow::steps('tests')[$i];
        self::assertStringContainsString("if [ \"\$CORE\" = '1.7.0' ]; then\n            composer config --json --merge policy.advisories.ignore", $step);
        self::assertSame(1, preg_match("/policy\\.advisories\\.ignore '(\\{.*\\})'/", $step, $m));
        $entry = json_decode($m[1], true);
        self::assertIsArray($entry);
        self::assertSame(['roundcube/roundcubemail'], array_keys($entry));
        self::assertSame('1.7.0', $entry['roundcube/roundcubemail']['constraint'] ?? null);
        self::assertNotSame('', (string) ($entry['roundcube/roundcubemail']['reason'] ?? ''));
        self::assertArrayNotHasKey('on-audit', $entry['roundcube/roundcubemail']);
        self::assertSame(0, preg_match('/composer audit[^\n]*\|/', $code), 'no audit output is piped or ignored');
        // the committed manifest has no exception of its own (the 1.7.0 legs edit the working copy:
        // read the manifest of the checked-out commit where git can)
        $root = dirname(__DIR__, 2);
        $committed = is_dir($root . '/.git') ? shell_exec('git -C ' . escapeshellarg($root) . ' show HEAD:composer.json 2>/dev/null') : null;
        $manifest = json_decode(is_string($committed) && $committed !== '' ? $committed : (string) file_get_contents($root . '/composer.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayNotHasKey('policy', $manifest['config'] ?? []);
        self::assertArrayNotHasKey('audit', $manifest['config'] ?? []);
    }

    /**
     * A skipped test fails CI (job "tests" and every leg of job "databases"); the root-only tests run
     * as root in the PHP 8.4 legs.
     */
    public function testPhpunitFailsOnSkippedTestsInEveryLeg(): void
    {
        $code = Workflow::code(Workflow::jobs()['tests'] ?? '');
        preg_match_all('/^.*vendor\/bin\/phpunit.*$/m', Workflow::code(Workflow::text()), $calls);
        self::assertCount(3, $calls[0]);
        foreach ($calls[0] as $call) {
            self::assertStringContainsString('--fail-on-skipped', $call);
        }
        self::assertStringContainsString('run: vendor/bin/phpunit --fail-on-skipped --exclude-group root', $code);
        self::assertStringContainsString('run: vendor/bin/phpunit --fail-on-skipped --exclude-group root', Workflow::code(Workflow::jobs()['databases'] ?? ''));
        $root = Workflow::stepIndex('tests', '--group root-aware');
        self::assertNotNull($root);
        $step = Workflow::steps('tests')[$root];
        self::assertStringContainsString("if: matrix.php == '8.4'", $step);
        self::assertStringContainsString('sudo --preserve-env=GALBRIDGE_TEST_MYSQL_DSN,GALBRIDGE_TEST_PGSQL_DSN,MYSQL_ADMIN,PSQL_ADMIN', $step);
        self::assertStringContainsString('vendor/bin/phpunit --fail-on-skipped --group root-aware', $step);
        // PHPUnit 13 selects NOTHING for "--group a,b" (10.5 warns on a repeated --group): one group
        foreach ($calls[0] as $call) {
            self::assertStringNotContainsString(',root', $call);
            self::assertLessThanOrEqual(1, substr_count($call, '--group'));
        }
        foreach (['sudo chown -R root:root "$r"', 'sudo chmod -R go-w "$r"', 'env GALBRIDGE_RC="$rc"'] as $part) {
            self::assertStringContainsString($part, $step);
        }
        self::assertStringNotContainsString('skipped, not failed', Workflow::text());
    }

    /**
     * The end-to-end and browser suites run in CI for both editions, the oldest core and the newest one
     * this version knows, as an unprivileged user and without --allow-skips.
     */
    public function testE2eAndBrowserSuitesRunInCi(): void
    {
        $block = Workflow::jobs()['e2e'] ?? '';
        self::assertNotSame('', $block, 'job e2e');
        $code = Workflow::code($block);
        self::assertStringContainsString("core: ['1.7.0', '" . Tool::LATEST_KNOWN_ROUNDCUBE . "']", $code);
        self::assertStringContainsString('db: [mysql, pgsql]', $code);
        foreach (['1.7.0', Tool::LATEST_KNOWN_ROUNDCUBE] as $core) {
            self::assertMatchesRegularExpression('/^\s+' . preg_quote($core, '/') . '\) sum=[0-9a-f]{64} ;;$/m', $code, "a SHA-256 pin for Roundcube {$core}");
        }
        self::assertStringContainsString('sha256sum --check --strict', $code);
        foreach (['tests/e2e/test_e2e.py', 'tests/ui/test_ui.py'] as $suite) {
            self::assertMatchesRegularExpression('/runuser -u e2e -- env [^\n]*\\\\\n\s+python3 ' . preg_quote($suite, '/') . ' /', $code, "{$suite} runs as the user e2e");
        }
        self::assertStringNotContainsString('--allow-skips', $code);
        self::assertStringNotContainsString('GALBRIDGE_E2E_ALLOW_ROOT', $code);
        self::assertStringContainsString('dovecot.sh install', $code);
        self::assertStringContainsString('dovecot.sh remove', $code);
        // an init process reaps the daemons the job stops, and the Dovecot checks before the install
        // and after the removal ignore a zombie (the first CI run counted Dovecot's unreaped master)
        self::assertMatchesRegularExpression('/^    container:\n      image: debian:13-slim@sha256:[0-9a-f]{64}\n(?:      #[^\n]*\n)*      options: --init$/m', $block);
        self::assertStringNotContainsString('pgrep -x dovecot', $code);
        self::assertSame(2, substr_count($code, 'if pgrep -x -r D,I,R,S,T,t,W dovecot > /dev/null; then'), 'every run state but Z');
        self::assertStringContainsString("grep -q 'refusing to run Roundcube and Chromium as root'", $code);
        // the local matrix covers the same cores (OldPluginRegressionTest checks its entries)
        $matrix = (string) file_get_contents(dirname(__DIR__) . '/e2e/matrix.sh');
        self::assertStringContainsString('"' . Tool::LATEST_KNOWN_ROUNDCUBE . ' php8.4 mysql"', $matrix);
        self::assertStringContainsString('"1.7.0 php8.4 pgsql"', $matrix);
    }

    /**
     * Final review CI-1: the unprivileged user of job "e2e" gets the rows of the test database and
     * nothing else - on PostgreSQL through load.sh (GALBRIDGE_FIXTURE_USER, after the role exists),
     * never through pg_read_all_data / pg_write_all_data (every table of every database, pg_authid
     * included); nothing reloads the fixture after that step (the grants go with the database).
     */
    public function testTheE2eFixtureAccessIsScopedToTheTestDatabase(): void
    {
        $code = Workflow::code(Workflow::text());
        foreach (['pg_read_all_data', 'pg_write_all_data', 'pg_read_all_', 'pg_write_all_'] as $never) {
            self::assertStringNotContainsString($never, $code, "no job grants {$never}");
        }
        $i = Workflow::stepIndex('e2e', 'tests/fixtures/vmail/load.sh pgsql');
        self::assertNotNull($i, 'job e2e loads the PostgreSQL fixture');
        $step = Workflow::code(Workflow::steps('e2e')[$i]);
        $role = strpos($step, "runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 -c 'CREATE ROLE e2e LOGIN'\n");
        $load = strpos($step, 'PSQL_ADMIN="runuser -u postgres -- psql" GALBRIDGE_FIXTURE_USER=e2e tests/fixtures/vmail/load.sh pgsql' . "\n");
        self::assertIsInt($role, 'the role e2e is created on its own, with LOGIN and nothing else');
        self::assertIsInt($load, 'the PostgreSQL fixture is loaded with GALBRIDGE_FIXTURE_USER=e2e');
        self::assertLessThan($load, $role, 'the role exists before load.sh grants it the test database');
        self::assertSame(1, substr_count($step, 'load.sh pgsql'), 'one PostgreSQL load');
        self::assertSame(1, preg_match_all('/\bGRANT\b/', $step), 'the only GRANT is the MariaDB grant on the test database');
        self::assertStringContainsString("GRANT SELECT, INSERT, UPDATE, DELETE ON galbridge_vmail_test.* TO 'e2e'@'localhost';", $step);
        foreach (array_slice(Workflow::steps('e2e'), $i + 1) as $later) {
            self::assertStringNotContainsString('load.sh', Workflow::code($later), 'no later step reloads the fixture');
        }
        // the suites themselves never reload it either: they change rows through fixture_admin.py
        foreach (['tests/e2e/test_e2e.py', 'tests/ui/test_ui.py', 'tests/e2e/setup.sh', 'tests/e2e/fixture_admin.py'] as $file) {
            $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertDoesNotMatchRegularExpression('/^(?!\s*#)[^\n]*load\.sh (?:drop |mysql|pgsql)/m', $text, "{$file} runs load.sh");
            self::assertStringNotContainsString('pg_read_all_data', $text, $file);
        }
        foreach (['docs/TESTING.md', 'tests/e2e/test_e2e.py'] as $file) {
            $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertStringNotContainsString('pg_read_all_data, pg_write_all_data TO', $text, "{$file} tells to grant the predefined roles");
            self::assertStringContainsString('GALBRIDGE_FIXTURE_USER', $text, "{$file} describes the scoped access");
        }
    }

    /**
     * Final review CI-2: .gitleaks.toml excepts git-ignored local directories by path, and gitleaks
     * applies that exception to the history scan as well. Job "security" therefore fails, BEFORE the
     * history scan, when any commit of any branch or tag ever had a file in one of those directories -
     * with exactly the directories of the exception (no drift between the two).
     */
    public function testTheHistoryScanRunsOnlyAfterTheSkippedDirectoriesAreProvenUncommitted(): void
    {
        $check = Workflow::stepIndex('security', 'git log --all --format= --name-only --');
        $history = Workflow::stepIndex('security', '/gitleaks/gitleaks" git ');
        self::assertNotNull($check, 'job security checks the history of the excepted directories');
        self::assertNotNull($history, 'job security scans the history');
        self::assertLessThan($history, $check, 'the check runs before the history scan');
        $step = Workflow::code(Workflow::steps('security')[$check]);
        self::assertSame(1, preg_match('/committed="\$\(git log --all --format= --name-only -- ([^|)]+?) \| sed \'\/\^\$\/d\' \| sort -u\)"\n/', $step, $m), $step);
        self::assertStringContainsString("if [ -n \"\$committed\" ]; then\n", $step);
        self::assertStringContainsString('exit 1', $step);
        self::assertStringContainsString('excepts by path: the history scan below would not see a secret in them', $step, 'it says why');
        self::assertStringNotContainsString('continue-on-error', $step);
        $listed = explode(' ', $m[1]);
        sort($listed);

        $toml = (string) file_get_contents(dirname(__DIR__, 2) . '/.gitleaks.toml');
        preg_match_all("/^\\s+'''\\^\\(\\?:\\\\\\.\\/\\)\\?(.*\\/)''',$/m", $toml, $dirs);
        $excepted = array_map('stripslashes', $dirs[1]);
        sort($excepted);
        self::assertGreaterThanOrEqual(3, count($excepted), 'the directory entries of the path exception');
        self::assertSame($excepted, $listed, 'the step checks exactly the directories .gitleaks.toml excepts');
    }

    /**
     * load.sh creates the '%' account of the test role only when asked: CI's PHPUnit jobs ask (their
     * database service containers see the runner through the Docker bridge), the e2e container does
     * not.
     */
    public function testOnlyThePhpunitJobsAskForAnAnyHostTestAccount(): void
    {
        $jobs = Workflow::jobs();
        foreach (['tests', 'databases'] as $job) {
            $i = Workflow::stepIndex($job, 'tests/fixtures/vmail/load.sh mysql');
            self::assertNotNull($i, $job);
            self::assertStringContainsString("GALBRIDGE_LOAD_ANY_HOST: '1'", Workflow::steps($job)[$i]);
        }
        foreach ($jobs as $job => $block) {
            if (!in_array($job, ['tests', 'databases'], true)) {
                self::assertStringNotContainsString('GALBRIDGE_LOAD_ANY_HOST', Workflow::code($block), $job);
            }
        }
    }

    /**
     * The documentation relies on the daily run only together with the check that GitHub has not
     * disabled the schedule (it does after 60 days without activity).
     */
    public function testTheDailyRunIsNeverClaimedWithoutTheScheduleCheck(): void
    {
        $root = dirname(__DIR__, 2);
        $releasing = (string) file_get_contents($root . '/docs/RELEASING.md');
        self::assertStringContainsString('gh api repos/takenek/galbridge/actions/workflows/ci.yml --jq .state', $releasing);
        self::assertStringContainsString('gh workflow enable ci.yml', $releasing);
        foreach (['SECURITY.md', 'README.md', 'docs/ARCHITECTURE.md', 'docs/RELEASING.md', 'docs/TESTING.md'] as $doc) {
            $paragraphs = preg_split('/\n\s*\n/', (string) file_get_contents($root . '/' . $doc)) ?: [];
            foreach ($paragraphs as $p) {
                if (preg_match('/\bdaily\b/i', $p) === 1) {
                    self::assertMatchesRegularExpression('/schedul/i', $p, "{$doc}: a claim of a daily run without the schedule qualification:\n" . substr($p, 0, 300));
                }
            }
        }
    }
}
