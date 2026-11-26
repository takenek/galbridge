# GAL Bridge – Testing

Sections 1 – 5 give the numbers of the verification run of 2026-11-24 for 1.0.1 (branch `main`,
after the remediation of the security audit of 1.0.0; 1.0.1 is a development version that was never
tagged or published); section 6 adds the verification passes after it.

## 1. Test environment (actually used)

| Component | Versions |
|---|---|
| Roundcube | the official release tarballs `roundcubemail-<version>-complete` of **every 1.7 release, 1.7.0 – 1.7.7** (for 1.7.7: GPG signature of "Roundcube Developers" verified; SHA-256 `cc9358a2…dd3fb`, identical to iRedMail 1.8.11's `pkgs.sha256`); CI: 1.7.0 and the newest 1.7.x from Packagist |
| iRedMail schema | upstream `samples/iredmail/iredmail.mysql` and `iredmail.pgsql` of iRedMail **1.8.11** (vendored as `tests/fixtures/vmail/schema.*.sql`), plus a SQLite mirror |
| PHP | 8.1.34, 8.4.28, 8.5.13 (Debian 13 + packages.sury.org); for the local run of the CI steps also 8.2.36 and 8.3.37; CI: 8.1 – 8.5 |
| Databases | MariaDB 11.8.9, PostgreSQL 17.12, SQLite 3 (fixture mirror); for the database matrix (sections 2 and 6) also MariaDB 10.5.29, 10.6.28, 10.11.20, 11.4.14, 12.3.4 and 13.1.2 and MySQL 8.0.46, 8.4.13, 9.7.4 and 26.10.0 (release tarballs of the vendors, default configuration – MySQL 8.4 and later without `mysql_native_password` –, without the `test` database and the anonymous accounts of `mariadb-install-db`), PostgreSQL 13.23, 14.25, 15.20, 16.16, 18.7 and 19.0 (PGDG packages, default configuration); CI: MariaDB 11.8 + PostgreSQL 17 (job `tests`) and the seven pairs of job `databases` (README section 1), Docker images pinned by digest |
| IMAP | Dovecot 2.4.1 (test users from the fixture, `tests/e2e/dovecot.sh`) |
| Browser | Chromium 156 (headless) + Selenium 4.31 |
| Static analysis | PHPStan 2.3 **level 8** against the 1.7.7 tree, PHP-CS-Fixer 3.95, ShellCheck (test scripts), the tokenizer checks of `SourceGuardTest` (a lexical regression check, section 3), gitleaks (CI: 8.30.1; locally the engine of the Debian package 8.16 with the rule file of 8.30.1, see sections 6 and 7) |
| Test runners | PHPUnit 10.5.67 (PHP 8.1), 11.5.58 (PHP 8.2 / 8.3), 13.4.8 (PHP 8.4 / 8.5) – each PHP version resolves its own dependency set; CI keeps the `composer.lock` of every job as an artifact, and the verification of a release installs from the lock of its CI run in a disposable container (docs/RELEASING.md step 2) |

The database role used by every database test is created from the plugin's own `SQL/grants.*.sql`
(`tests/fixtures/vmail/load.sh`, which also enables the commented-out personal-data grant so that
every field is exercised), so the suites also prove that the documented grants are sufficient – and
that they deny `mailbox.password`, `recovery_email`, `SELECT *`, `UPDATE` and the whole
`forwardings` table (checked on both engines). The fixtures are TEST-ONLY data; `tests/fixtures/vmail/README.txt` describes their origin
(upstream iRedMail 1.8.11 schema), the canary values and the throw-away role.

## 2. Suites and how to run them

| Suite | Command | What it covers |
|---|---|---|
| Fixtures (as root: the administrator's step) | `tests/fixtures/vmail/load.sh mysql` · `PSQL_ADMIN="runuser -u postgres -- psql" tests/fixtures/vmail/load.sh pgsql` · `load.sh drop mysql\|pgsql [db]` | throw-away vmail databases + the read-only role; prints the DSNs. MariaDB/MySQL accounts `localhost` and `127.0.0.1`; an account for every host (`'%'`, with the published test password) only with `GALBRIDGE_LOAD_ANY_HOST=1` (CI's service containers); `drop` removes the database and the role |
| PHPUnit (unit + integration) | `GALBRIDGE_RC=/opt/rcrun/1.7.7 GALBRIDGE_TEST_MYSQL_DSN=… GALBRIDGE_TEST_PGSQL_DSN=… vendor/bin/phpunit --fail-on-skipped --exclude-group root` as an unprivileged user, and `--group root-aware` as root from a root-owned copy (CI does both; a skipped test fails the run) (any 1.7.x tree: `/opt/rcrun/1.7.0` for the oldest release) | see section 3; every database test runs on SQLite, MariaDB and PostgreSQL. Tests that need rows the seed does not have (a mailbox disabled during its session, more entries than a limit, names that decode into an address) change the throw-away fixture through `tests/Support/Fixture.php` – the administrator access of `load.sh` – and undo it; without that access they are skipped on the two servers, which fails the run with `--fail-on-skipped` (CI) |
| CLI (`diag`, `grants`) | part of the PHPUnit run: `vendor/bin/phpunit tests/Integration/CliToolTest.php` (same environment; the MariaDB/PostgreSQL cases use the administrator access of `load.sh`: `MYSQL_ADMIN` / `PSQL_ADMIN` or their defaults) | `grants`: argument validation (also the refused role names and the modes), the output (a verifier of a fresh password in the statements, the password in the DSN line only, renames inside SQL statements only, DSN host independent of the MySQL account host, `REQUIRE SSL` for a non-local account host) and the output **applied** to the throw-away servers as a new role – the printed DSN works, only the granted columns and the view are readable, `SELECT *`, `UPDATE` and the `forwardings` table are denied, the server stores exactly the printed verifier, a second default output stops at the existing role and changes nothing, `--reset-password` replaces the password, `--grants-only` keeps it and moves a role with the grants of 1.0.0 to the view, the clear-text variant refuses to run on MariaDB (and creates a working account on MySQL); `diag` run like an administrator runs it (`bin/galbridge.sh` with `ROUNDCUBE_CONFIG_DIR`) against the fixture role and temporary roles that are too broad (write privileges – also through a database role –, the `forwardings` table) or too narrow (missing columns, no view), options of the wrong type and environment overrides, malformed deny-lists, unsafe configuration files, the `'*'` wildcard, uncovered IMAP hosts and ports, `include_host_config`, `--url` against a local web server, an invalid `--user` and an unreachable server, with the exit codes; the script's refusal to run as root. Temporary roles exist only on the test databases and are dropped in `tearDown`. The second verification pass adds `Remediation2AllowRootTest`, `Remediation2DiagOutputTest` and `Remediation2DiagTest` (same environment; child processes through `tests/Support/CliRun.php`), `diag` cases in `Remediation2CoreConfigTest`, `Remediation2HostConfigTest`, `Remediation2ImapTransportTest`, `Remediation2DocumentationTest` and `Remediation2ResidualRiskTest` (the configuration file over HTTP against a local web server, a PostgreSQL role with audit settings created and dropped through `tests/Support/DbAdmin.php`), and for `grants` and the role checks `Remediation2GrantsProvenanceTest` (run as root, its unprivileged cases run as the account `nobody` through `setpriv`, from a copy root owns in a directory every account can reach), `Remediation2GrantsOutputTest`, `Remediation2ForwardingsViewTest` and `Remediation2RoleScopeTest` (administrator access through `tests/Support/DbAdmin.php`; the PostgreSQL template cases run in one administrator session that is rolled back). A PostgreSQL grant set re-creates the view and takes its grant away from the fixture role: `CliToolTest` and these tests put it back (`DbAdmin::restoreFixtureView()`) |
| E2E | `python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql\|pgsql` | 37 cases against a real Roundcube over HTTP: Dovecot login, every address-book path, attacks, live configuration changes, a leak observer over every response, log checks; cases 31 – 35 are the audit regressions seen through Roundcube itself (hidden aliases, fail-closed configuration and IMAP port binding, complete-or-refused exports, revocation through Roundcube's own session handler, names that decode into an address). The cases that change the fixture use `tests/e2e/fixture_admin.py` (administrator access of `load.sh`) and fail the run without it (a skipped case fails unless `--allow-skips` is given). Run as an unprivileged user: it refuses root (exit 2) unless `GALBRIDGE_E2E_ALLOW_ROOT=1` |
| E2E matrix | `tests/e2e/matrix.sh [rc_base_dir]` | the E2E suite, run sequentially (the Dovecot test mailboxes and the vmail databases are shared) for Roundcube **1.7.0** × PHP 8.1 / 8.4 and Roundcube **1.7.7** × PHP 8.1 / 8.4 / 8.5, each × MariaDB / PostgreSQL – ten combinations; logs and `summary.txt` in `tests/e2e/run/matrix-<timestamp>/`. A missing Roundcube tree or PHP binary counts as a failed combination (never a silent skip), and a combination is green only with exit code 0 and "0 failed, 0 skipped"; the exit code is non-zero unless every combination is green; refuses root like the suites |
| Browser UI | `python3 tests/ui/test_ui.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql\|pgsql` | 9 cases in headless Chromium (address book, contact view, compose autocomplete, tenant isolation, a directory name with a character reference or an encoded word in the recipient widget of the Elastic skin, no JS errors). Run as an unprivileged user (it refuses root unless `GALBRIDGE_E2E_ALLOW_ROOT=1`); Chromium keeps its sandbox unless it runs as root or `GALBRIDGE_UI_NO_SANDBOX=1` (a container whose seccomp profile blocks it); Chromium and chromedriver are taken from `PATH` (or `CHROMEDRIVER`), never downloaded; a skipped case fails the run unless `--allow-skips` |
| PHPStan | `php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist` | level 8, `lib/`, `galbridge.php`, `bin/galbridge.sh` |
| Code style | `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php` | |
| Secret scan | CI job `security` (`gitleaks git` and `gitleaks dir` with `.gitleaks.toml`, after controls: a planted key is reported, a DSN with a password is reported by the rule `galbridge-dsn-password`, a new file of the fixture directory is reported while the one excepted file is not; and after a check that no commit of any branch or tag ever had a file in the git-ignored directories `.gitleaks.toml` excepts by path, since gitleaks applies that exception to the history as well) | the whole history and the tree; the exceptions: one named TEST-ONLY file (`tests/fixtures/vmail/seed.sql`, canary values), the three throw-away values, the `CHANGE_ME_*` placeholders, the public fingerprint of the release key, git-ignored local directories; a rule of its own reports database DSNs with a password of 16 or more characters |
| Dovecot (TEST ONLY, as root) | `tests/e2e/dovecot.sh install\|status\|remove` | installs/removes exactly one config file and the test passwd file; records whether Dovecot ran before the first install and restores that state on `remove`; systemd or, without it (containers), `dovecot` / `doveadm stop` |
| Release scripts | `tests/release/verify-tag.sh vX.Y.Z [--commit <rev>] [--require-main] [--signers <file>]` · `php tests/release/check-archive.php <ref>` | the release tag (an annotated tag object of that name, SSH-signed by a key of `.github/release-signers`, of the given commit, part of `main`) and its archive (allow-list of full paths); run by the maintainer before the push and by the CI job `release` (docs/RELEASING.md) |

**What CI covers** (`.github/workflows/ci.yml`): the unprivileged PHPUnit run with
`--fail-on-skipped --exclude-group root` on PHP 8.1 – 8.5 (newest 1.7.x) and 8.1 / 8.4 (1.7.0), the
group `root-aware` as root in the PHP 8.4 legs (every test of the group `root` is also in
`root-aware`: PHPUnit 13 selects nothing for `--group root,root-aware`, and PHPUnit 10.5 warns on a
repeated `--group`), the job `databases` – the same unprivileged run (PHP 8.4, newest 1.7.x) against
seven pinned pairs of a MariaDB or MySQL server and a PostgreSQL server (README section 1; both
engines in every leg, so `--fail-on-skipped` holds; a step checks that the servers report the
flavour and the versions the leg names) –, and the job `e2e` – the E2E and browser suites for
Roundcube 1.7.0 and the newest known 1.7.x × MariaDB and PostgreSQL in a Debian 13 container, as an
unprivileged user, with skipped cases counted as failures. The full ten-combination matrix of `tests/e2e/matrix.sh` (also PHP 8.1 and
8.5) stays a local run.

**Running the E2E and browser suites** – as an unprivileged user, ideally in a disposable container
or virtual machine (Debian 13: `php8.4-cli php8.4-{curl,intl,mbstring,mysql,pgsql,sqlite3,xml}
mariadb-server postgresql-17 dovecot-imapd chromium chromium-driver python3-requests
python3-selenium`, as the job `e2e` installs them). Root does the administrator's steps once:
`load.sh mysql|pgsql`, `dovecot.sh install`, and the least-privilege fixture access of that user –
rows of the test database, nothing else:

```sh
tests/fixtures/vmail/load.sh mysql
mariadb -e "CREATE USER 'tester'@'localhost' IDENTIFIED VIA unix_socket; GRANT SELECT, INSERT, UPDATE, DELETE ON galbridge_vmail_test.* TO 'tester'@'localhost';"
runuser -u postgres -- psql -X -c 'CREATE ROLE tester LOGIN'
PSQL_ADMIN="runuser -u postgres -- psql" GALBRIDGE_FIXTURE_USER=tester tests/fixtures/vmail/load.sh pgsql
```

MariaDB keeps a grant on the database across a reload. A PostgreSQL grant lives in the database and
goes with it, so `load.sh` makes it itself: with `GALBRIDGE_FIXTURE_USER` it grants that existing
role (it never creates or drops one) `CONNECT` on the test database, `USAGE` on its schema `public`,
`SELECT, INSERT, UPDATE, DELETE` on all its tables and `USAGE, SELECT, UPDATE` on all its sequences –
after every load, so give the variable to every PostgreSQL reload. It refuses the read-only test
role, a superuser, a member of `pg_read_all_data` or `pg_write_all_data` (those reach every table of
every database of the cluster, `pg_authid` included – revoke such a membership first) and reserved or
administrator names (`public`, `pg_*`, `postgres`, ...).

The user then runs the suites with `MYSQL_ADMIN=mariadb PSQL_ADMIN=psql` (both connect through the
local socket as that user) on a Roundcube tree it owns (`setup.sh` links the plugin into
`plugins/galbridge`). Overrides, each for a deliberate case only: `GALBRIDGE_E2E_ALLOW_ROOT=1` (root
in a disposable container), `GALBRIDGE_UI_NO_SANDBOX=1` (Chromium without its sandbox, for a container
that blocks it), `GALBRIDGE_E2E_REPLACE_LINK=1` (replace a `plugins/galbridge` link that points
elsewhere; put back afterwards), `GALBRIDGE_E2E_ON_CONFIGURED_TREE=1` (a Roundcube tree with
`config/config.inc.php`), `--allow-skips` (a partial run). Afterwards `dovecot.sh remove` gives
Dovecot back its earlier state and `load.sh drop mysql|pgsql` removes the databases and roles.

**Running the suite against other database servers** (the database matrix of section 6; the job
`databases` does the same with Docker service containers). Take throw-away servers that clients
reach over TCP on the loopback address – `load.sh` refuses a server with the databases of a mail
server – and remove the `test` database and the anonymous accounts that `mariadb-install-db` creates
(`mariadb-secure-installation`; with that database `diag` reports the FAIL `server-wide test
database` by design, and the `diag` tests fail). Pair each MariaDB or MySQL server with a PostgreSQL
server and run one pair at a time (every run loads the same database and role names). For each pair,
as root for the administrator's steps:

```sh
export MYSQL_ADMIN="mysql -u root -S /path/to/mysqld.sock"   # the server's own client: mariadb for MariaDB
export PSQL_ADMIN="runuser -u postgres -- psql -p 5433"
tests/fixtures/vmail/load.sh mysql && tests/fixtures/vmail/load.sh pgsql
GALBRIDGE_RC=/opt/rcrun/1.7.7 \
GALBRIDGE_TEST_MYSQL_DSN=mysql://galbridge_ro:testonly-ro@127.0.0.1:3307/galbridge_vmail_test \
GALBRIDGE_TEST_PGSQL_DSN=pgsql://galbridge_ro:testonly-ro@127.0.0.1:5433/galbridge_vmail_test \
vendor/bin/phpunit --fail-on-skipped --exclude-group root
```

`load.sh` prints the DSNs without a port: add the port of each server, as above (every test that
derives a DSN or a grants output keeps it, `grants --port`). On MySQL 8.4 and later without an active
`mysql_native_password` plugin `load.sh` creates the test role with the form of `grants
--clear-text-password` and says so on stderr; no other step changes per server. A run with one
engine alone skips the tests of the other one (eleven PostgreSQL-only tests without a PostgreSQL
DSN) and fails with `--fail-on-skipped`: leave the flag off only for such a deliberately partial run
and check that every skip names the missing server.

## 3. Results of the final run

| Run | Result |
|---|---|
| PHPUnit as root, PHP 8.4, Roundcube 1.7.7 (release tarball), all engines | **2228 tests, 39 961 assertions, OK** (2 intentional skips: `REQUIRE SSL` exists for MySQL accounts only; the write-privilege-through-a-role case uses MariaDB roles) |
| The same with Roundcube 1.7.0, 1.7.1, 1.7.2, 1.7.3, 1.7.4, 1.7.5 and 1.7.6 | 2228 tests, OK, each |
| PHPUnit as root, PHP 8.1 (PHPUnit 10.5), Roundcube 1.7.0 and 1.7.7 | 2228 tests, OK |
| PHPUnit as root, PHP 8.5 (PHPUnit 13.4), Roundcube 1.7.0 and 1.7.7 | 2228 tests, OK |
| PHPUnit, random order (seeds 20261124, 424242) | OK each time |
| **The CI job steps run locally as an unprivileged user**, each leg from a fresh copy of the tree (`composer install` with the newest Roundcube 1.7.x from Packagist, which reports `1.7-git`; fixture loaded through TCP administrator access; MariaDB with `skip-name-resolve`, so that the role is matched through its `'%'` account as in the CI container), PHP 8.1 (PHPUnit 10.5.67), 8.2 and 8.3 (PHPUnit 11.5.58), 8.4 and 8.5 (PHPUnit 13.4.8) | 2228 tests, OK, each (4 skips: the two above and the two tests that need root) |
| The same with Roundcube 1.7.0 from Packagist, PHP 8.1 and 8.4 | 2228 tests, OK |
| PHPStan, PHP-CS-Fixer and `php -l` in that environment (PHP 8.4) | clean |
| `AuditRegressionTest` without administrator access to the test servers, PHPUnit 10.5 and 11.5 | 16 tests skipped, none failed |
| E2E Roundcube 1.7.0 × PHP 8.1 / 8.4 × MariaDB / PostgreSQL (`matrix.sh`) | 37/37 each |
| E2E Roundcube 1.7.7 × PHP 8.1 / 8.4 / 8.5 × MariaDB / PostgreSQL (`matrix.sh`) | 37/37 each |
| Browser UI, Roundcube 1.7.0 and 1.7.7, MariaDB and PostgreSQL | 9/9 each |
| PHPStan level 8, against the release tarball of 1.7.7 and against the Packagist core (`1.7-git`) | no errors |
| PHP-CS-Fixer | 0 files to fix |
| `php -l` (PHP 8.1 – 8.5), ShellCheck, `composer validate --strict` | clean |
| `diag --url` against the running Roundcube 1.7.0 and 1.7.7 instances of the E2E environment | the address is recognised as the webmail; the plugin files that PHP's built-in web server hands out are reported as served |
| gitleaks 8.16 (Debian package; history and tree) with the exceptions of `.gitleaks.toml` written in the `[allowlist]` syntax that release reads | no leaks; the planted control is reported |
| The 222 default rules of gitleaks 8.30.1 with `.gitleaks.toml` as committed (`[[allowlists]]`), evaluated outside the scanner by a script that follows its detection logic (keywords, entropy, global and rule allow-lists, stop words) | tree and history: no finding; a planted key outside the allow-listed paths is reported |

(The "2 intentional skips" and "4 skips" above belong to the runs of 1.0.1. Since the second
verification pass CI fails on any skipped test, the two MariaDB-only cases no longer have a
PostgreSQL case, and the root-only tests run in CI's root step: a complete run has no skip.)

The unchanged 1.0.0 code was run first as a reference: 1799 tests, OK, on Roundcube 1.7.0 – 1.7.7
(PHP 8.4), 1.7.0 and 1.7.7 (PHP 8.1) and 1.7.7 (PHP 8.5) – the plugin already worked on the whole
branch; 1.0.1 declares and tests it.

### Unit suite (no Roundcube needed)

| Class | Tests | Focus |
|---|---|---|
| IdCodecTest | 28 methods | every escape and byte value, canonical-only decoding, Roundcube separators, base64url rejection, invalid/overlong UTF-8, controls, group IDs with book tags, a seeded "never throws" check |
| NormalizerTest / NormalizerWithoutIntlTest | 36 / 1 | domain and address normalisation incl. IDN, invalid UTF-8 fails (never "repaired"), C1/bidi/zero-width rejection, list semantics (`*`, strict deny-lists, numeric strings stay strings), display text incl. the notations that are decoded later (`&`, `=?`, `?=`) and what Roundcube deletes from a composed name (empty brackets, also across the brackets of a name template; bounded for values of any length) |
| SourceGuardTest | 5 methods (one case per production file) | a lexical regression check with the PHP tokenizer: it reports direct uses of process execution, dynamic code, deserialisation, file writes, request superglobals or weak randomness in production code, and SQL execution, network access and `$_SESSION` outside the named files (indirect forms – callables built at run time, variable calls – are not proven absent: review and PHPStan cover them); the data files (`localization/*.inc`, `config.inc.php.dist`) hold assignments of literals only; a self-test plants every forbidden construct |
| SqlTest | 30 | parenthesisation, `IN ()` = FALSE, `NOT IN ()` = TRUE, placeholder counting, unsafe expressions (quotes, `;`, `--`, `/*`, `#`), LIKE escaping, ILIKE on PostgreSQL |
| SearchSpecTest | 17 | round trip, tampered / foreign-book / foreign-fingerprint / wrong-version specs rejected |
| LogTest | 27 | log injection (CR/LF, C1, bidi, CSI), DSN password redaction incl. `@` and `/` in passwords, truncation, once-per-request codes |
| Remediation2TerminalTest | 8 methods | second verification pass, F-12: `Terminal::clean()` turns C0 controls (TAB, CR, ESC ...), DEL, C1, bidi, zero-width, separator characters and every byte of invalid UTF-8 into visible escapes and leaves ASCII, newlines, letters and the grant templates byte-identical; the filter `bin/galbridge.sh` puts on standard error; PHP messages as one sanitised line; `Log::maskCredentials()`; a socket path of printable ASCII only (`Config::unixEndpoint()`) |
| Remediation2ImapTransportTest | 4 methods (30 matrix cases) | second verification pass, F-18: `ViewerResolver::transportProblem()` – local endpoints need nothing; elsewhere `ssl`/`imaps`, or `tls` on 1.7.7 and later only (not 1.7.6, 1.7.0, `1.7-git` or an unknown version), `openssl`, certificate verification not switched off (per-host `imap_conn_options` selected as Roundcube does); local endpoints; the switch is documented in `config.inc.php.dist` with its default (every option of the template has the default of `Config::DEFAULTS`) |
| Remediation2RevocationTest | 1 method (13 cases) | second verification pass, F-17: `ViewerResolver::imapVerdict()` – connected = accepted, `NO` with `AUTHENTICATIONFAILED` / `AUTHORIZATIONFAILED` / `EXPIRED` = refused, anything else (UNAVAILABLE, no code, BAD, BYE, no connection) = cannot be told |
| Remediation2LogTest | 7 methods (38 cases) | second verification pass, F-29: the password mask in every form (user with `/`, password with a blank or a tab, `scheme(syntax)://`, PDO and libpq `password=`, quoted values, `PGPASSWORD=`, `db_pass=`, JSON, two DSNs, `@` and `/` in the password; also through the `STDERR` filter), text without a password unchanged, a text the patterns cannot handle redacted as a whole; context values with a blank, `=`, `"` or `\` quoted, keys as code constants; every format character (`Cf`) replaced; a control character cannot split the mask; the parameters that carry a secret are `#[\SensitiveParameter]` |
| Remediation2ConfigRangesTest | 3 methods | second verification pass, F-30: every `Config::int()` call passes the range of `Config::RANGES`, every integer default has a range that contains it, README documents the same ranges |
| Remediation2ToolTest | 1 method | second verification pass, F-10: `Tool::composerManaged()` reads both forms of `vendor/composer/installed.json` (Composer 1 list, Composer 2 object) and nothing else (other package, invalid JSON, missing file) |
| Remediation2DocsTest | 3 methods | second verification pass, F-09 / F-10: no document recommends `galbridge_*` options in `dont_override`; no document says that Composer keeps `plugins/galbridge/config.inc.php`; the nginx rule covers the installer's backup copy |
| Remediation2GrantTemplatesTest | 10 methods (20 cases) | second verification pass, F-02 / F-03 / F-04 / F-28: the templates hold no backslash and no mysql client command, and their headers show the trusted commands (`grants --allow-root`, `mysql --binary-mode <`, `runuser -u postgres -- psql -X ... <`, the template of the verified archive); the view fenced on both engines (`TEMPTABLE` + `LIMIT`, `security_barrier` + `OFFSET 0`, PostgreSQL `DROP VIEW IF EXISTS` + `CREATE VIEW`); the PostgreSQL transaction (`search_path`, `pg_stat_statements.track_utility`, `NOBYPASSRLS`, CREATE revoked from `PUBLIC` after the owners got it by name); `Schema::GRANTABLE` equals the templates; `Tool::parseArguments()` (a repeated option), `Tool::clientCommand()`, `Tool::requiresTls()` (placeholder hashes), `Tool::viewDefinitionProblems()`, `RoleScope::refusals()` |
| Remediation2DbLayerTest | 4 methods (12 cases) | second verification pass, F-26: a group key with a C0 or C1 control, DEL, a backslash or invalid UTF-8 is never decoded from a group ID (ordinary keys, also non-ASCII, still are); `QueryBuilder::unexpired()` compares with the bound UTC time AND the database clock (`LOCALTIMESTAMP`, SQLite `datetime('now', 'localtime')`) per column |
| Remediation2CanonicalAddressTest | 7 methods (81 cases) | second verification pass, F-05 / F-16 / F-20: the address rule `Normalizer::canonicalAddress()` / `canonicalDomain()` (accepted forms, every excluded ASCII character, non-ASCII letters and look-alikes, dots, lengths, labels, top-level labels, a trailing newline); every canonical address of a seeded corpus of 120000 strings passes `rcube_utils::check_email()` and is its own `Normalizer::address()`; the SQLite rendering (evaluated by SQLite) and the ERE of MariaDB/MySQL/PostgreSQL (with PCRE) decide exactly like the PHP rule; a name Roundcube takes for empty (`0`) falls back |
| Remediation2DisplayTextTest | 3 methods (9 cases) | second verification pass, F-19: no ASCII `$` in display text (JavaScript replacement patterns), no `%%` and no `%` at either end (highlight markers of the suggestion list, also across two fields), `labelWord()` in the same notation |
| Remediation2CiTest | 13 methods | second verification pass, F-21 / F-25 / F-34, on `.github/workflows/ci.yml` itself: no switch that turns off every Composer policy (only the advisory blocking, only for `composer install` in job `tests`), Composer pinned by version and SHA-256 and verified before its first use, `ubuntu-24.04` and digests everywhere – every image, also those a matrix names, with one digest per tag –, every resolved set audited plainly, recorded (source commits) and kept as an artifact, the 1.7.0 core the only audit exception, `--fail-on-skipped` on every PHPUnit call and one root call with `--group root-aware`, the job `databases` (the seven server pairs, the leg named after flavour and tags, PostgreSQL 17 as in job `tests`, health commands, the fixture loaded as in job `tests` without a wrapper, the leg check, PHP 8.4 and the newest core; README section 1 names every leg, docs/RELEASING.md its pins), the job `e2e` (both cores, both editions, unprivileged, no `--allow-skips`; final review CI-1: its fixture access is the rows of the test database – the role `e2e` created first, then `load.sh pgsql` with `GALBRIDGE_FIXTURE_USER=e2e`, no `pg_read_all_data` / `pg_write_all_data` anywhere, no later reload), the any-host test account in the jobs `tests` and `databases` only, no "daily" claim without the schedule check; final review CI-2: job `security` checks, before the history scan, that no commit ever had a file in the directories `.gitleaks.toml` excepts by path – exactly those directories |
| Remediation2ReleaseTest | 9 methods | second verification pass, F-22 / F-23: `.github/release-signers` (principal, namespace, key type inside the blob, RSA ≥ 3072 bits, no e-mail or comment) and its fingerprint (as `ssh-keygen -l` prints it) in SECURITY.md, README.md, RELEASING.md and the scanner exceptions; `tests/release/verify-tag.sh` against throw-away ed25519 keys (good tag; another key; lightweight, unsigned and renamed tags; another commit; `--require-main` without and with `origin/main`, a side branch; `valid-before`, another principal or namespace in the key file); `check-archive.php` on a copy of this tree and with planted files, a symbolic link and a missing file; the release job (no `git fetch`, the key file of `main`); the reporting channel; README 2.1 installs the version of `galbridge.php`, 1.0.0 and 1.0.1 marked as not published; RELEASING describes the scripts and the disposable verification; the README badges are repository files (`raw.githubusercontent.com`, no `img.shields.io`), the version badge named and labelled with the version of `galbridge.php`, the licence badge with that of `composer.json`, both plain images |
| DiagAdviceTest | 1 method | 1.0.5: `Tool::connectionShareProblem()` – the default 25 and a quarter of the server's connections are fine, an unknown server value is no warning, iRedMail's 100 against 151 (MariaDB) and 97 (PostgreSQL) is a WARN with the remedy |
| InstallationDocsTest | 12 methods | 1.0.4, the README-only installation on real iRedMail 1.8.11: the four `pg_hba.conf` lines (the socket `reject` first, before iRedMail's `local all all md5`, with location, check, reload and both test logins; also in config.inc.php.dist, the PostgreSQL template, SECURITY.md and `diag`'s INFO); the connection limit names both bounds; every `diag` command has a `runuser` form (README, dist, templates, MIGRATION, SECURITY, the tool's root refusal); the Composer path (Composer installed, interactive root run, the activation question, `config.inc.php` to root first, the working command first, the file comparison, `composer remove`); the DSN line without `-- `; section 5 (installto.sh, `tar --no-same-owner`, Composer after a core update); the certificate name, `php-fpm8.4`, iRedMail's commented `addressbook_sort_col` and plugin line, `namei` on the absolute path; the checklist links resolve; 1.0.6: the blocks of section 2 run in their order from the Roundcube directory, one runnable `grants` line, the documented outputs match the tools (the review `diff` of `--grants-only` on PostgreSQL, `diag --strict` with `--url`, `composer show` without a terminal); 1.0.7: the review `diff` described for each form, `curl` with iRedMail's netdata, the limits block of 2.6 run on iRedMail's two templates with the values of SECURITY.md |
| Remediation2RepositoryHygieneTest | 6 methods | second verification pass, F-24: copies of the configuration, `galbridge.inc.php`, `config/`, grants outputs and `.env.*` are git-ignored, the templates and fixtures are not; the scanner exceptions name existing files (directory patterns only for git-ignored local directories); the rule `galbridge-dsn-password` reports 500 generated `grants` passwords (entropy above its threshold) and not the throw-away values; no file of the repository holds what it would report; `.gitguardian.yaml` names the same files and values; the controls of job `security` |
| Remediation2FinalReviewDocsTest | 4 methods | final review DOCS-1 – DOCS-4, lexical: no document tells to give `plugins/galbridge` to `root:root` (the owner-only command in README 2.1, 5 and 7 and the CHANGELOG, and the refusals of `bin/galbridge.sh` compare the owner and the write bits only); no document sends options or the DSN into the plugin-directory file alone (README 5, CHANGELOG and the manual steps of both grant templates name `config/galbridge.inc.php`, or the plugin-directory file if that is the one in use – never both); every `$_SESSION['galbridge…']` key of `galbridge.php` and `lib/` is named in SECURITY.md, README 6 (with the right count) and THREAT_MODEL GB-15; the register row GB-24 describes the rebuilt DSN |
| Remediation2DocumentationTest | 9 methods | second verification pass, F-35, lexical: the summaries of fail-closed configuration name what each error disables (every book; the book a deny-list protects; every connection for the refused roles); no "conservative value"; the password plugin's DSN files; the action name as request input (`asciiwords()`, before the `startup` and `ready` hooks); every description of the suggestion order names Roundcube 1.7.6 and 1.7.0 – 1.7.5; the inventory names `vcard_attachments` and `rcmail::contact_exists()`; no `compose_headers`, the `autocomplete` key described as informational; example domains are reserved names; no document says that a new own alias waits for `galbridge_scope_ttl` |
| Remediation2ResidualRiskTest | 6 methods | second verification pass, F-36, lexical: the register of SECURITY.md has the rows GB-31 (configuration file), GB-22 (debug texts, log volume) and GB-21 (PostgreSQL statement logging vs. an audit trail), and GB-04 / GB-11 / GB-19 / GB-26 / GB-30 name group IDs and browser history, the port rule, the core release, the log volume and the verifier as a secret; every register row has six filled cells, a review date and a threat of the threat model; no "a request loop cannot flood the log"; `config/galbridge.inc.php` recommended (README, `.dist`, the `grants` DSN line), the unverifiable iRedMail claim gone; the `.htaccess` precondition; the Logs section names browser history, proxies, `per_user_logging`, the syslog priority, pgaudit / auto_explain and the verifier |
| Remediation2SourceGuardTest | 4 methods | second verification pass, F-34: every PHP file of the release (git, `export-ignore` of directories included; a walker self-test with planted files) is scanned by `SourceGuardTest`; string literals naming a restricted function are reported (`array_map('system', ...)`, `$f = 'exec'`) unless listed with their reason; every `root` test is also `root-aware`; the MariaDB-only cases of `CliToolTest` get no case of another engine |
### Integration suite (Roundcube 1.7 library, three engines)

| Class | Focus |
|---|---|
| ContractTest | the `rcube_addressbook` contract: paging, counts, sort fallbacks, all search modes and forms, search-set replay, ID search, `get_record` (never stale), groups, search-only books, DeniedBook, record shape and coltypes |
| CorePathsTest | replays `program/actions/**` call sequences with core's own code where possible (`get_cids()`, vCard export, copy via `rcube_contacts::convert_save_data()`), incl. compose-to-group with `max_group_members` (complete or refused); list names as `rcube_addressbook::compose_list_name()` composes them in the four listing modes and with nine `contactlist_name_template` values, each in a process of its own (no at-sign, no encoded word, no character reference, no foreign entry without its marker) |
| OldPluginRegressionTest | one test per verified defect of the old plugin (13), plus a docs-vs-code check of documented defaults |
| IsolationTest | tenant isolation on every path, invisible inactive/backup-MX/expired entries, Viewer cache binding, outage memo |
| AccessPolicyTest | the iRedAPD policy matrix for six viewers under `strict`, `public_only`, `all`; persons-only groups |
| EntitlementsTest | viewer lists, deny-lists failing closed, organizations (incl. overlaps), global book data/viewer lists, wildcard opt-in, `include_home` |
| ConfigTest | configuration guard (user preferences named `galbridge_*` ignored, also for options set through the environment alone), clamps, `validate()`; switches are never cast, environment overrides are read strictly, the visibility column must be a string, a field list of the wrong type is reported; the IMAP binding with ports, `include_host_config` and local sockets; the keyed fingerprint (it follows the effective value of a switch) |
| DsnPolicyTest | drivers, refused roles (incl. Roundcube's and the password plugin's), `service`, host+socket ambiguity, the DSN judged in the form the driver receives it (allow-listed parameters, explicit target, client-library environment), TLS with host-name verification for remote hosts (`verify-ca` only by opt-in), connect timeout injection and validation, `config_invalid` / `column_missing` logging, role-too-broad detection with real over-privileged roles (secret columns, the `forwardings` table) |
| PluginHooksTest | the real `galbridge` plugin class: `addressbooks_list` entries (escaped names, readonly), `addressbook_get` always an instance for our IDs, autocomplete wiring, refused exports (`204`) incl. capped search results and a failing directory |
| CliToolTest | `bin/galbridge.sh grants` and `diag` (section 2): generated grants applied to MariaDB and PostgreSQL in all three forms, strict options, `diag` against correct, too broad and too narrow roles and broken configurations, a release directory under another name, the web check with its control request, the root refusal (also without the posix extension) and what `--allow-root` checks. The tests of the root refusal and of `--allow-root` need root (group `root`): CI runs them in the root step of its PHP 8.4 legs, from a root-owned copy (the class is `root-aware`); the MariaDB/MySQL-only cases (`REQUIRE SSL` for a remote account, write privileges through a role – MariaDB or MySQL role syntax, with and without a default role) take the MariaDB/MySQL server alone (no PostgreSQL case that could only be skipped). Every grants output that a test applies or connects with keeps the port of the test DSN (`--port`), and on MySQL without an active `mysql_native_password` plugin (8.4 and later by default) the applied outputs are the `--clear-text-password` form; `--port` itself: written as `host:port` / `[IPv6]:port`, digits 1–65535, refused with the MariaDB/MySQL host `localhost` |
| Remediation2AllowRootTest | second verification pass, F-11, **root only** (groups `root` and `root-aware`: CI's root step): with `log_driver = 'file'` a `log_dir` of another account (a link in place of `errors.log`) or a sticky world-writable one is refused and nothing is written through it, syslog and a `log_dir` of root work; an SQLite database of Roundcube in a directory of another account is refused; no file of the working directory is executed (an unknown database driver makes Roundcube's autoloader ask for one); Roundcube gets the canonical `INSTALL_PATH`, `RCMAIL_CONFIG_DIR` and `RCUBE_CONFIG_PATH` and works in `program/`; `include_path` keeps absolute, existing, checked entries only; (final review, CLI-1) a per-host file of `include_host_config` outside the configuration directory (absolute and `../` names), group-writable or owned by another account is never run and named in a WARN line, a file of root alone in the directory is loaded |
| Remediation2DiagOutputTest | second verification pass, F-12: every line of `diag` neutralised, the reason of an aborted check and the settings a server reports sanitised (a fake `rcube_db` returns them), a stored socket `mail_host` with bytes outside ASCII counted and not printed, Roundcube's and PHP's messages on standard error neutralised (and in `errors.log` as one line), the refusals of `--allow-root` escape file names (root only) |
| Remediation2DiagTest | second verification pass, F-27: every configuration file Roundcube loads (all configuration paths, core files) with the owner of its directory and of the directories above; the plugin code recursively (files the PHP user owns; as root the `--allow-root` rule; the directories above); files inside the release directories (`Tool::RELEASE_TREE` against what git exports); the `process` and `environment` lines, core overrides by name; `env_override` once per session in the web process; a WARN for an unchecked Roundcube database; `--strict`; `Result: aborted` and exit code 2 for an exception and for PHP fatal errors during Roundcube's start; an unexpected error while connecting keeps the counts |
| Remediation2CoreConfigTest | second verification pass, F-06: no binding derived when Roundcube's `imap_host` and a second reading differ; a preference named `imap_host` / `default_host` falls back to the second reading; `include_host_config` counts in either reading (never through a preference); the fingerprint keyed with Roundcube's `des_key`; Roundcube's and the password plugin's database users refused from every reading (and from `password.inc.php`); the plugins directory slashed like Roundcube (child process) and the plugin's files resolved with Roundcube's environment (`config-test.inc.php`); a file pulled in with `include_once` (real files through `RCUBE_CONFIG_PATH`); `diag` WARN "configuration read again" (not as root: the script's probe has run the file), "include_once" and "environment file", names only |
| Remediation2HostConfigTest | second verification pass, F-08: with `include_host_config`, a `galbridge_*` option of a request that differs from the host-independent files disables every book before any database connection (the global book, an emptied hidden list), the same policy in the host-independent files works, book names may differ, nothing is compared without it, `ROUNDCUBE_INCLUDE_HOST_CONFIG` triggers it; the real files with `$_SERVER['HTTP_HOST']` selecting a per-host file; `diag` "Per-host configuration" for listed files and for `true` (any `*.inc.php`), with the per-host `imap_host` checked |
| Remediation2ImapTransportTest | second verification pass, F-18: a session on a remote IMAP server without TLS gets no directory (no database access, not cached, log `imap_transport` with the endpoint); `ssl`/`imaps` accepted, `tls` from Roundcube 1.7.7 on (asserted for the running tree), verification switched off refused (per host), the opt-out accepts with a WARN, loopback and sockets need nothing, the check runs before the cache; the plugin passes `storage_ssl`; `diag` "IMAP transport" for local, `ssl://`, plain, opt-out, `tls://`, `SSL://` (no TLS for Roundcube), verification off and a user-chosen server |
| Remediation2RevocationTest | second verification pass, F-17: an IMAP refusal at renewal ends the directory for the session (never asked again, no database access), an unreachable IMAP server is no revocation (books listed as unavailable, retried after 30 s, an exception counts as unreachable), the IMAP server is asked only on renewal; a removed own alias applies with the next request (one request reads nothing), an added one does not interrupt, aliases are read again only where they decide; the plugin's check uses Roundcube's open connection or `rcmail::storage_connect()` with the session's values, end to end with Roundcube's storage error (`tests/Support/PluginBoot.php`) |
| Remediation2ConfigTest | second verification pass, F-07 / F-29 / F-30: an unknown option (with the closest name; also only in Roundcube's own object, never from a user preference) or variable (also in the wrong case), a plugin configuration file without `$config` or unreadable (as root in a child process without the file-permission capabilities, `setpriv`), a second configuration file that Roundcube ignores – each disables every book before any connection; the real plugin with an unloadable file and the DSN from the environment offers no book, and compose wires none; problems name positions, never entries (hidden lists, allow- and deny-lists, organizations, fields); an autocomplete request logs no `denied_source` for an enabled book the user is not entitled to (debug only; INFO with `_source` and for other actions); the built-in refused roles cannot be removed and a malformed list refuses every connection; numbers are whole numbers in their range (also from the environment, judged by the text); the fingerprint does not depend on the database password |
| Remediation2DiagConfigTest | second verification pass, F-07 / F-09 / F-10 / F-30, `diag` (child processes and the sections in this process): FAIL for an unknown option and variable, for both configuration files and for one without `$config` (the DSN from the environment), `enabled books` "none" with the reason, INFO `policy lists`; FAIL / WARN for `galbridge_*` names in `dont_override`; users with `galbridge_*` keys in their stored preferences counted in Roundcube's SQLite database (FAIL for the DSN, no value printed); FAIL for a Composer backup copy next to the plugin (other names ignored), WARN for a plugin-directory configuration of a Composer installation (and the hint on the world-readable FAIL), none for `config/galbridge.inc.php`; WARN for loosening switches while on, for an unknown password-plugin DSN (not for another driver), for a short, non-string or default `des_key`; INFO `refused roles` |
| Remediation2PreferencesTest | second verification pass, F-09: the plugin is loaded in the Settings task with `preferences_update` only and touches neither configuration nor database there; the hook drops every `galbridge_*` key from what is saved and from the stored preferences; `rcube_user::save_prefs()` against Roundcube's SQLite database stores no DSN that `dont_override` would add and drops a copy stored before |
| Remediation2CoreAssumptionsTest | second verification pass, F-33: `Factory::canonicalAction()`; search-export cap and stale-search refusal for `EXPORT` / `Export` (and the 204 path of the plugin), group expansion as a full set for `GROUP-EXPAND`, `group_expand`, `Mailto`, the autocomplete switch and wiring for every spelling; `contact_exists` removes the GAL Bridge books (also through core's `rcmail::contact_exists()`); the export hook for every action of the address book; Roundcube's action aliases reach no decision action |
| Remediation2DsnTest | second verification pass, F-13 (no database server needed): the DSN target in one canonical form (a `?` before the database, a second port, text around an IPv6 address, `unix+`, `tcp()`, a percent-encoded host, a raw `+` in a value are refused), the database name as the driver receives it, MySQL ports the driver would ignore (`localhost:PORT`, inside `unix()`), socket paths up to 107 bytes (PostgreSQL with `.s.PGSQL.<port>`), PostgreSQL `localhost` accepted with a warning, MySQL `localhost` named with its `pdo_mysql.default_socket` (unusable values refused, child processes with `-d`), the effective DSN rebuilt from the judged parts (re-parsed equal, encoded credentials, one timeout last), a driver error while connecting memoised as `db_down cause=driver_error`, no `rcube_db::factory()` in production code, the real driver classes, diag's `socket directory` check, `grants` printing only DSNs the policy accepts |
| Remediation2DbTimeoutTest | second verification pass, F-14 (loopback peers of the test, no database): a listener that never sends the greeting, and `tests/Support/FakeMysqlServer.php` (a child process that completes the MySQL handshake and never answers one statement) – both end within the read timeout of 3 s instead of `mysqlnd.net_read_timeout` (a safety net of 20 s in the test, 86400 s by default), the php.ini value is restored after connecting and a lower one is never raised; `readTimeout()` from both timeouts; a lost connection (MySQL 2006/2013/4031, SQLSTATE 08, 57P01, libpq's text) memoised as `db_down cause=lost`, a statement timeout or privilege error not; diag's `read timeout` line |
| Remediation2DbLayerTest | second verification pass, F-26, three engines: search words with a control character or a backslash match nothing (quick and advanced, no `db_error`); no value with a NUL byte or a backslash is sent (`unsafe_parameter`); a department with a backslash (written with `CHAR(92)`/`CHR(92)`) is no group and a forged group ID with it selects nothing; a refused statement timeout or read-only mode fails closed (`session_limit_refused`, the connection closed, memoised) on SQLite, MariaDB and PostgreSQL; diag's `statement time (this session)` for 3 and 7 s; expiry judged by the database clock and UTC (a session time zone of +05:00 hides an entry and a viewer that expire in 30 min UTC, +00:00 shows them; MariaDB and PostgreSQL); a required birthday means a real date |
| Remediation2GrantsProvenanceTest | second verification pass, F-02, `root-aware` (the unprivileged cases as `nobody` when run as root): a non-root `grants` refuses a group-writable class, template, script, directory of `lib/` and directory above the plugin and – as root – a class another account owns; it refuses to run as a user that can read Roundcube's or the plugin's configuration (found through `ROUNDCUBE_INSTALL_PATH`, `ROUNDCUBE_CONFIG_DIR` and the start path), not for an unreadable one; a clean copy of a dedicated account works (SQL on standard output, DSN on standard error); a message of PHP itself (an unlistable directory below `lib/`, `display_errors=1`) goes to standard error, never into the SQL stream; `--allow-root` keeps checking (**root only**); `diag` names every changeable plugin file and says that `grants` refuses such a tree |
| Remediation2GrantsOutputTest | second verification pass, F-28: the DSN line on standard error only, in every form (the verifier belongs to its password; the clear-text form has the password in its statement only); no output of any form, name or account host holds a backslash or a client command, and a template with one is not printed (exit 2); usage errors, refusals, a repeated option, `--account-host` for PostgreSQL and a missing template on standard error with empty standard output; one stream keeps one transcript; on MariaDB the clear-text output stops as it is and without comments ("Unknown system variable", no account created), on MySQL both forms create the account; the PostgreSQL output's `pg_stat_statements.track_utility` and `search_path` in a rolled-back session; `diag` WARN "account TLS" for an account of host `%` without `REQUIRE SSL` (no hash printed), the PostgreSQL INFO |
| Remediation2ForwardingsViewTest | second verification pass, F-03: a caller expression with a side effect runs exactly as often as the view has rows (both servers); MariaDB/MySQL: `information_schema.VIEWS`, `SHOW CREATE VIEW` and the plan – MariaDB `EXPLAIN FORMAT=JSON` without an index condition or a pushed condition on `forwarding`, MySQL `EXPLAIN FORMAT=TREE` with the condition on `f` above the materialised view and no access to the table by `forwarding`; PostgreSQL: a leakproof equality stays above the view (no index condition, Filter on the Subquery Scan); the alias listing of the strict policy over 150 more alias rows builds the view once per statement (PostgreSQL: hashed sub-plan, no per-row sub-plan reads it; MariaDB: `ANALYZE` r_loops 1 for each derived table; MySQL: `EXPLAIN ANALYZE` loops=1 for each materialisation of the view); an unfenced view is a `diag` FAIL and a `view_not_materialised` warning, the books keep working |
| Remediation2RoleScopeTest | second verification pass, F-04: the PostgreSQL grant set replaces a view another role created and granted on (no grant, administrator owner, fenced barrier), revokes CREATE on schema `public` from `PUBLIC` while a table owner keeps it, resets `CREATEDB` / `BYPASSRLS` / `INHERIT` in the `--grants-only` and `--reset-password` forms, stops at a table of the view's name (all in rolled-back sessions); the plugin refuses (`role_too_broad`, the reason in the log) and `diag` fails for a secret column of another table, a membership, a privilege outside (final review, PRODUCT-1 – PostgreSQL also `CREATE` on another schema, also a FAIL of the CREATE line, `EXECUTE` on a function of another schema and `USAGE` on another schema alone), a write privilege, a role attribute, the database owner and a global privilege; a view with other rows on all three engines; `diag` checks the PostgreSQL view object (no barrier, another owner), CREATE on schema `public` (FAIL, the plugin warns; for a role whose own schema is another one `PUBLIC`'s CREATE on `public` stays that warning, a grant to the role itself is a privilege outside), every relation, owned objects and columns beyond the grant set; a MariaDB/MySQL server-wide test database (`test_galbridge_open`, open to every account through `PUBLIC` on MariaDB 10.11 and later, through a blank-user row of `mysql.db` elsewhere) keeps the books and is a `diag` FAIL with a warning, a `test_*` database granted to the role and any other open database are refusals |
| Remediation2DocumentationTest | second verification pass, F-35 (`root-aware`; the `diag` cases as child processes against a server DSN): `Config::RESTRICTIVE` has one value per switch (off, except `hide_expired` and `mark_foreign_domains`), an ambiguous switch – a text, a list, `2`, an environment variable – is read as that value while every book is disabled (the six switches with a permissive default are no longer read as on), `diag` shows `off (INVALID)`; the `autocomplete order` line of `diag` describes the core it runs with (ICU collator from 1.7.6, the C library locale before – the 1.7.0 and newest legs cover both) and the core really sorts that way; the core methods the documentation names exist (`rcmail_sendmail::headers_output()`, `rcmail_action::autocomplete_init()`), and no core file reads the `autocomplete` key of a source |
| Remediation2ResidualRiskTest | second verification pass, F-36 (`root-aware`; child processes, a local web server that hands out every file as text, a Roundcube directory of links with a real `config/`): WARN for a `galbridge_imap_hosts` entry without a port (not for `host:port`, a socket or a derived binding; exit code unchanged); WARN while `galbridge_debug` is on (also `ROUNDCUBE_GALBRIDGE_DEBUG=1`, none for an ambiguous value); WARN instead of INFO for an unchecked configuration in the plugin directory (`--strict` counts it); `config over HTTP` FAIL for `plugins/galbridge/config.inc.php` and for `config/galbridge.inc.php` served as text (no part of the answer printed), INFO for an answer without the text, OK for 404, WARN at an address that is not the webmail, INFO for a configuration outside the Roundcube directory; the `grants` DSN line names `config/galbridge.inc.php`; with `per_user_logging` and no user directory an ERROR / WARN line reaches Roundcube's `errors` log, an INFO line does not, and a user with a directory gets the line there only; PostgreSQL: WARN for `pgaudit.log` / `auto_explain.log_min_duration` set for a throw-away role (OK without them) |
| Remediation2CanonicalRowsTest | second verification pass, F-05 / F-16 / F-20, three engines: hidden entries in another spelling (NFD, a non-ASCII capital) and a U-label domain column of an excluded domain are neither counted, listed, matched nor grouped; a per-user alias in another spelling finds no owner (and one with capitals follows the engine); rows only the PHP check rejected (a double dot, a foreign domain column) no longer block exports and group expansions; a genuine disagreement is still refused, the log names the row by its tag; every engine offers exactly the canonical rows of 27 throw-away mailboxes, one per class of character |
| Remediation2DiagDataTest | second verification pass, `diag` lines of F-05 / F-15 / F-16 / F-31: `entries not offered` for every kind with JSON-escaped examples and row tags (terminal-safe), what an address with capitals means on each engine, WARNs for costly full-set limits, page sizes above the bound and global department groups, the window-function version rule |
| Remediation2ResourceLimitsTest | second verification pass, F-15: a fast refusal (a limit text in another language, PostgreSQL's reserved slots, an authentication error) is not memoised for the session, an unreachable, timed-out or slow server is; core's copy action reads at most `galbridge_max_ids` contacts per request across the books of the request; a whole-book export of 1213 entries is complete with no statement carrying more than one batch of addresses (SQLite, a recording driver) |
| Remediation2CompletenessTest | second verification pass, F-31, three engines: compose to selected and export of selected with a malformed, gone or foreign ID are refused (`set_incomplete missing=`); a browse page above the page bound is a page with `pagetoolarge`, not an export or a group expansion; `pagesize` as core's fallback; list totals at `galbridge_max_count` and search pages at the result cap with their notices; every person of a page gets its aliases (four owners with 201 aliases each); out-of-range page numbers clamped without a PHP warning |
| Remediation2MultiDomainBookTest | second verification pass, F-32, three engines: fields a foreign entry does not carry are neither matched nor sorted by (first and last name only when it has no display name; department under `{department} {name}`), home entries keep them; department groups of the global book per domain (labels, expansion, `get_record_groups()`, merged IDs of 1.0.x select nothing), an organization's domain book keeps one group per name |
| Remediation2NameTemplateTest | second verification pass, F-19 (completed), three engines: every text field of 20 throw-away entries with payloads of every class (template placeholders, at-sign and look-alikes, encoded-word delimiters, `$` patterns, `%` runs) under eight templates with every placeholder – as list and as search name – stays inert; organization labels cut at the at-sign (reported) |
| AuditRegressionTest | one test per finding of the security audit of 1.0.0 that needs the whole stack, on three engines: hidden and out-of-scope aliases, names that decode into an address, complete-or-refused sets, revocation of a cached entitlement, expiry of list kinds, bounded alias sets, IDN spellings in allow- and deny-lists |
| LeakageTest | canaries (password hashes, recovery addresses, private forwarding targets, `allow_nets`) never in any record, coltypes, search set or log line |
| Remediation2HarnessTest | second verification pass, F-25, `root-aware`: `tests/e2e/setup.sh` refuses a plugin link to another directory and a directory in its place, and a configured Roundcube tree – before it creates anything; as root, `setup.sh`, `test_e2e.py`, `test_ui.py` and `matrix.sh` refuse without `GALBRIDGE_E2E_ALLOW_ROOT=1` (**root only**); `tests/e2e/harness.py` stops only the PHP web server of its run (not one of another port) and puts the plugin link back (created, kept, replaced, changed by somebody else); a skipped case fails the suites; `load.sh` creates the `'%'` account only with `GALBRIDGE_LOAD_ANY_HOST=1` and removes it on a reload without it (MariaDB), `load.sh drop` removes database and role on both servers and keeps the name checks (own database and role, never the shared fixture); `load.sh` creates the MariaDB/MySQL test role with the verifier where the server can apply it and with the clear-text form on MySQL without `mysql_native_password`, and the role logs in either way; `Fixture::alter()` for an engine without a DSN is a clean skip (no PHP warning); final review CI-1: `load.sh pgsql` with `GALBRIDGE_FIXTURE_USER` grants an existing role exactly `CONNECT` on the test database, `USAGE` on its schema `public` and `SELECT, INSERT, UPDATE, DELETE` / `USAGE, SELECT, UPDATE` on all its tables, the view and its sequences (ACLs, memberships and `pg_shdepend` compared), the role changes rows there, and is denied another schema, `TRUNCATE`, `CREATE` and the table of another database; a reload without the variable leaves it nothing; bad, reserved and administrator names are refused before the server is asked, a missing role and a member of `pg_read_all_data` before anything is dropped (the PostgreSQL case exists only with a PostgreSQL DSN) |
## 4. Bugs found by the test suites (all fixed)

The suites were written by independent agents from the design document, without editing the plugin;
every failing test was a real defect, fixed in the code (never by weakening the test):

| Severity | Bug | Fix |
|---|---|---|
| high | `Normalizer::list()` turned numeric strings into ints (`array_keys`); a numeric `galbridge_hidden_localparts` entry (`'1001'`) raised a TypeError and emptied the book | separate seen-map, values stay strings |
| medium | compose-to-group returned a truncated prefix when core `max_group_members` ≤ `max_pagesize` | `mailto` / `group-expand` are always full-set requests (complete or refused) |
| medium | a `socket=` parameter next to a remote host passed the TLS rule | the host decides; host + socket is refused |
| medium | `diag` read configuration problems before any accessor had run | `Config::validate()` |
| medium | invalid UTF-8 in a deny-list entry became a different address (`?` substitution), failing open | invalid UTF-8 is rejected first |
| low | refused DSN / missing columns logged only as `db_error` | `config_invalid` and `column_missing` statuses and log lines |
| low | `timeout=0` in the DSN disabled the connect timeout | DSN timeout validated (1..30 s) |
| low | C1 controls, U+061C, U+2060 not stripped by the sanitisers; `#` accepted in column expressions; DSN redaction stopped at the first `@` | sanitiser classes unified (`Normalizer::INVISIBLE`), `#` rejected, greedy redaction |
| low | `get_record_groups()` reported groups for alias accounts and lists | persons only |
| low | department group keys recomputed in PHP could differ from SQL `LOWER()` | keys used exactly as SQL computed them |
| low | the password plugin's DSN user was not read from `plugins/password/config.inc.php` | `Config::passwordPluginDsn()` |
| low | grant templates granted unused columns (`domain.description`, `alias_domain`) and made the 1.7.3 columns mandatory | removed; 1.7.3 columns in a separate statement |

### Independent review of the implementation

Before the release an independent review of the 1.0.0 code (security, Roundcube integration,
operability, code quality, documentation) reported 41 confirmed findings. The fixes are listed in the
1.0.0 entry of [CHANGELOG.md](../CHANGELOG.md) ("Fixed after the independent code review"); the
missing CLI coverage is closed by `CliToolTest`. Among the findings: `diag` stopped on PostgreSQL
16+ (`lc_ctype`) and its write-privilege check could never fire, refused exports downloaded an empty
file, "The directory is temporarily unavailable." was never shown, and the anti-impersonation rules
did not cover first and last names.

The tests written for these fixes found five more defects, all fixed:

| Severity | Bug | Fix |
|---|---|---|
| low | `grants --connection-limit=abc` (or `-5`, empty) silently became 25 | a malformed value is a usage error |
| low | `grants --host=2001:db8::5` printed a DSN that names another host | IPv6 literals only in brackets |
| low | `grants` left the commented-out optional statements on the default role / database | statements are renamed also when commented out; prose comments stay |
| low | exporting a whole search-only book (`'auto'` above the threshold, the global book) still produced an empty file | refused like every other export (204) |
| low | an outage request touching both books showed the warning twice | one "unavailable" notice per request for all books |

A random-order run also showed that the fixture role inherited the template's PostgreSQL
`CONNECTION LIMIT 25` - too low for one PHPUnit process that keeps connections until garbage
collection (a Roundcube request opens at most one). `load.sh` lifts the limit for the test role only.

## 5. Verification of the old plugin

Before GAL Bridge was written, the old plugin (`sql_global_addressbooks` 2.5.0) was analysed and its
key defects reproduced with a harness on the real Roundcube 1.6.9 / 1.6.22 / 1.7.7 classes (PHP 8.0 –
8.5, SQLite) and on PostgreSQL 17: the fatal error on 1.7, the cross-tenant `_gid` / `_source` access,
compose-to-selected putting the whole book into To:, and an SQL injection on PostgreSQL through
`_headers` with UNION reads of other tables (docs/MIGRATION.md section 0).

## 6. Security audit of 1.0.0 and its remediation (1.0.1)

A full source review of the 1.0.0 code (with Anthropic's Mythos 5.2, 2026-11-24) reported eleven
low-risk and seventeen informational findings; all are remediated in 1.0.1 (CHANGELOG). Every change
to the code has a regression test that fails on the 1.0.0 behaviour (sections 2 and 3); where a
finding concerned something that only a real stack shows, the test runs there: Roundcube's own
session handler (E2E case 34), Roundcube's JavaScript in a browser (UI case U8), real database
servers with real roles (`CliToolTest`, `DsnPolicyTest`).

The tests and the verification on real servers found defects in the first version of the fixes;
all were corrected in 1.0.1:

| Severity | Bug | Fix |
|---|---|---|
| medium | revoking a cached entitlement used `unset($_SESSION[...])`; Roundcube merges the stored session with the request's copy, so the entry – and the directory – came back with the next request (found by E2E case 34, invisible with a plain `$_SESSION` array) | the entry is overwritten with the decision "no mailbox" |
| medium | the generated MariaDB/MySQL statements switched the general query log off for their session, but MariaDB also writes `CREATE USER ... IDENTIFIED BY` verbatim to its binary log and, when it qualifies, to its slow query log (found on a throw-away MariaDB 11.8 with those logs on) | the statements carry the `mysql_native_password` verifier; the clear-text form exists only for MySQL 8.4+ and stops on MariaDB |
| low | the aliases of a page of persons were fetched with one limit for the page: one person with hundreds of aliases left the others without any | at most 20 per person; owners the limit did not reach are asked for again |
| low | a session entry with status "ok" but an unusable viewer gave no directory until the cache expired, instead of being resolved again | such an entry is ignored |
| low | a mailbox row whose `domain` column differs from the domain of its address opened the directory of the column's domain | the row must name the login's own domain |
| low | option and argument patterns anchored with `$` accepted a trailing line break | `/D` on every validating pattern; the option parser keeps the whole value |
| low | `diag --url` asked the web server for the name of the plugin directory on disk; for an installation through a symlink (or a checkout under another name) it asked for the wrong path and reported "not served" (found when the suite ran in a checkout that is not called `galbridge`) | the web path uses the name of the plugin |
| low | PHPStan, run against the Packagist core (`RCMAIL_VERSION` = `1.7-git`), treated the version check of `diag` as dead code and failed; all earlier runs used release tarballs (found by running the CI steps locally) | the version is read through a method with a declared return type |

### Review of the remediation (1.0.1)

Before 1.0.1 was completed the whole change was reviewed once more – seven independent readers, one per
area (configuration, sessions and sets, queries and records, database connection, command line
tool and SQL, CI and tests, documentation) – and the CI steps were run locally as an unprivileged
user. Every finding was first reproduced: each one has a test that **fails on the code before its
fix** (checked by running the new tests against that tree) and passes after it.

| Severity | Finding | Fix |
|---|---|---|
| medium | the split of `=?` could be undone by Roundcube: with a `contactlist_name_template` it deletes empty bracket pairs from a composed name, so `=()?UTF-8?B?...?=` became an encoded word again | empty `()` / `[]` are removed first, to a fixed point; no text starts with a closing or ends with an opening bracket; `?=` is split as well |
| medium | the root refusal of `bin/galbridge.sh` depended on the posix extension: without it root was not recognised | the user is also read from `/proc`; an unknown user counts as root |
| medium | `--allow-root` did not check everything that is then loaded as root (every enabled plugin, the password plugin's configuration, `vendor/`) | every directory and PHP file of the plugin and of Roundcube's `program/`, `plugins/`, `vendor/` and configuration directory, symbolic links followed, and the directories above |
| medium | a database password containing `?` made the connect timeout part of the database name (connection failed, `diag` showed the DSN as accepted) | the separator is chosen from the part after the credentials, as the core parser reads it |
| low | a name template that shows another field before `{name}` (or has no `{name}`) put text of a foreign domain in front of the `(domain)` marker, or showed it without one (found by the tests written for the first row) | foreign entries do not carry those fields under such a template; `diag` WARN |
| low | the local part of an address, shown where an entry has no name, was not treated as display text | it is |
| low | the contact view did not find the department group of a person whose department contains `&` | the group key is compared in the notation of the record |
| low | the preference guard returned the built-in default for an option that is set through the environment alone | such an option is read through a configuration without user preferences |
| low | the configuration fingerprint was the same for a switch set to `true` and to `false` through the environment | it covers the switches as they are in effect |
| low | `galbridge_fields` of the wrong type was reduced to name and e-mail without a message | reported |
| low | `diag` printed 8 of 18 switches | all, under the names of their options |
| low | the marker-first name sorts entries of other domains to the top of the autocomplete suggestions | documented decision (SECURITY.md register), `diag` note, switch to keep the global book out of autocomplete |
| low | an `imap_host` on a local socket (`unix:///path`) had its books under 1.0.0 and lost them with the port binding | a socket is an endpoint of its own, derived or listed |
| low | `grants` used the default for an option written without `=` and ignored misspelled options | strict options, for `diag` as well |
| low | `grants --db` also renamed `vmail.` inside the account host | one pass over each statement |
| low | the outputs of `--reset-password`, `--grants-only` and `--clear-text-password` kept comments that describe other statements | each output describes its own |
| low | PostgreSQL's sampled statement logging (12 / 13 and later) was neither switched off for the role nor seen by `diag` | both |
| low | `diag --url` reported "not served" for any address that is not the webmail | a control request first; WARN when the address does not answer like Roundcube |
| low | only the first warning of the DSN policy reached the log | every warning on a line of its own, once per request |
| low | documentation: the documented archive installation left files group-writable; the upgrade command reset a sized connection limit without saying so; two stale cross-references; the secret-scan verification was described more broadly than what had been run | corrected (README 2.1 and 5, CHANGELOG, MIGRATION, this document) |

### Verification of the remediation (2026-11-25, changelog 1.0.2)

The remediation of 1.0.1 was verified against the audit report in the code and with the suites;
where a fix was incomplete it was completed (CHANGELOG, 1.0.2). Each completing fix has a
test that **fails on the code of 1.0.1** (the new tests run against `git archive 062c3b0`: 16 tests,
66 failing cases - among them eight forms of a session host that 1.0.1 bound to the allowed server,
on every engine) and passes after it. Environment as in section 1 (PHP 8.1.34 / 8.4.28 / 8.5.13
with `intl`, MariaDB 11.8.9, PostgreSQL 17.12, Dovecot 2.4.1, Chromium 156, release tarballs of
Roundcube 1.7.0 and 1.7.7).

| Run | Result |
|---|---|
| PHPUnit as root, PHP 8.4, 8.1 and 8.5 × Roundcube 1.7.0 and 1.7.7, all engines | **2307 tests, OK** in each of the six runs (40 853 assertions; 40 923 on PHP 8.5); 2 intentional skips |
| PHPUnit, random order (seed 6061) | OK |
| E2E (`matrix.sh`): Roundcube 1.7.0 × PHP 8.1 / 8.4 and 1.7.7 × PHP 8.1 / 8.4 / 8.5, × MariaDB / PostgreSQL | 10/10 combinations, 37/37 each |
| Browser UI, Roundcube 1.7.0 and 1.7.7, MariaDB and PostgreSQL | 9/9 each |
| PHPStan level 8 (Roundcube 1.7.7 release tarball), PHP-CS-Fixer, `php -l` (PHP 8.1, 8.5), ShellCheck (test scripts and every `run` step of the workflow) | clean |
| `bin/galbridge.sh diag --allow-root` on a root-owned Roundcube 1.7.7 tree whose `logs/` (owned by another user) holds a link to a file outside it | 1.0.1 created that file as root. The first verification sent Roundcube's log lines to syslog, but every `rcube_config` object still pointed PHP's `error_log` at `logs/errors.log`, so a PHP warning while the plugin read the configuration again was written through the link; since the second verification pass the run is refused with `log_driver = 'file'` unless `log_dir` is root's alone (`Remediation2AllowRootTest`) |

Not run in this verification: the CI emulation as an unprivileged user (the new CI steps were
checked with ShellCheck and a local run of their `jq` filter; their first real run is the next CI
run), MySQL 8.x, the pinned gitleaks release (the anchored path patterns were checked with a regular
expression engine).

### Second verification pass: CI, release and test harness (2026-11-25)

F-21 – F-25 and F-34 of the second assessment change CI, the release procedure and the test
harness, not the plugin. Each change has a test that fails on the tree before it (the new tests run
against a copy with every changed file at its earlier state: 33 of 35 fail; the two that pass are
guards - every PHP file of the release scanned, no string literal naming a restricted function).

| Run | Result |
|---|---|
| PHPUnit as root, PHP 8.4 × Roundcube 1.7.7 and PHP 8.1 × 1.7.0, SQLite + MariaDB 11.8 + PostgreSQL 17 | **2924 tests, OK** each, **no skip** (the two MariaDB-only cases have no PostgreSQL case any more) |
| PHPUnit, PHP 8.5 × 1.7.7, random order | OK with `--fail-on-skipped` (seed 1795615704); the group `root-aware` alone, as CI's root step selects it: 304 tests, OK, no skip |
| E2E (`matrix.sh`, as root with `GALBRIDGE_E2E_ALLOW_ROOT=1` on the test machine): Roundcube 1.7.0 × PHP 8.1 / 8.4 and 1.7.7 × PHP 8.1 / 8.4 / 8.5, × MariaDB / PostgreSQL | 10/10 combinations, 37 passed, 0 failed, **0 skipped** each (the first run found case 25 still expecting the silent drop of foreign IDs that F-31 replaced by a refusal - corrected); no plugin link and no web server left behind |
| Browser UI, Roundcube 1.7.0 and 1.7.7, MariaDB and PostgreSQL | 9/9, 0 skipped, each |
| `tests/e2e/dovecot.sh` install → remove → install on a systemd host where Dovecot was stopped | state `active=no` recorded; `remove` left it stopped; reinstalled for the final verification |
| `tests/release/verify-tag.sh` and `check-archive.php` | throw-away keys and repositories in `Remediation2ReleaseTest`; `check-archive.php HEAD`: 54 entries, all allowed |
| ShellCheck: the test scripts and every `run` step of the workflow | clean |
| Secret scan: the gitleaks 8.16 engine (Debian) with the 222 rules of 8.30.1 and the rule `galbridge-dsn-password`, tree and history, without any rule-level allowlist; then the rule allowlists of 8.30.1 and the committed exceptions applied by a script (8.16 applies allow-list regexes to the whole match, 8.30 to the secret) | the DSN rule: no finding; the generic rule: two lines, unchanged since the last CI run in which the pinned 8.30.1 reported nothing; the excepted fixture file is the only path exception left |

Not run here: the E2E and browser suites as an unprivileged user with the least-privilege fixture
access (creating those accounts on this machine was not done; the CI job `e2e` runs exactly that),
gitleaks 8.30.1 itself, and the GitHub-only parts of CI (section 7).

### Second verification pass: documentation and accepted risks – final run (2026-11-25)

F-35 and F-36 – the inaccurate documentation and the residual risks of the register – end the
second pass; with them the whole tree of the pass was run once more. The 47 new tests
(`Remediation2DocumentationTest` and `Remediation2ResidualRiskTest`, unit and integration) all fail
on a copy of the tree before these two findings (the code of that state, the current tests).

| Run | Result |
|---|---|
| PHPUnit as root, PHP 8.4 × Roundcube 1.7.7 and PHP 8.1 × 1.7.0, SQLite + MariaDB 11.8 + PostgreSQL 17 | **2971 tests, OK** each, no skip |
| PHPUnit, PHP 8.5 × 1.7.7, random order, `--fail-on-skipped` | OK (seed 1795618465) |
| The group `root-aware` alone, as CI's root step selects it (PHP 8.4 × 1.7.7) | 336 tests, OK, no skip |
| PHPStan level 8, PHP-CS-Fixer, `php -l` (PHP 8.1 and 8.5) | clean |

### Database compatibility – the tested server series (2026-11-25)

The suites were run against the MariaDB, MySQL and PostgreSQL series that README section 1 lists as
tested (versions in section 1, one MariaDB or MySQL server and one PostgreSQL server per run, loaded
and run as section 2 describes under "Running the suite against other database servers"; PHP 8.4 ×
Roundcube 1.7.7). The first run, on the tree before the changelog group "Database compatibility", failed on
every server: a printed DSN without the port of a non-default server, MySQL's plan formats in the
view tests, the account statement of the test role on MySQL 8.4 and later, and – on a server set
up by `mariadb-install-db` – every book refused because of its `test` database. The fixes and the
CI job `databases` are listed there; each has a test that fails on the tree before it.

| Run | Result |
|---|---|
| Before the fixes, the pairs below and MySQL alone | MariaDB pairs 4 – 5 failures each; MySQL 6 – 7 failures and a PHP warning each |
| PostgreSQL 13.23 + MariaDB 10.5.29, 14.25 + 10.6.28, 15.20 + 10.11.20, 16.16 + 11.4.14, 18.7 + 12.3.4, 19.0 + 13.1.2 (as root) | **2996 tests, OK, no skip**, each |
| MySQL 8.0.46, 8.4.13, 9.7.4 and 26.10.0, each without a PostgreSQL server (as root) | 2618 tests, OK, each; 11 skips – exactly the PostgreSQL-only tests, which have no server in such a run |
| The seven legs of the CI job `databases`, emulated: MariaDB 10.6.28 + PostgreSQL 13.23, 10.11.20 + 14.25, 11.4.14 + 15.20, 12.3.4 + 16.16, MySQL 8.0.46 + 18.7, 8.4.13 + 19.0, 9.7.4 + 17.12; the fixture loaded as the job loads it (`GALBRIDGE_LOAD_ANY_HOST=1`) and the role's loopback accounts dropped, so that the suite reaches it through its `'%'` account as from a service container; `--fail-on-skipped --exclude-group root` | **2986 tests, OK, no skip**, each; on MySQL 8.4 and 9.7 `load.sh` created the test role in the clear-text form |
| MariaDB 11.4.14 with the `test` database of `mariadb-install-db` re-created (`GRANT ALL ON test.*` and `` `test\_%`.* `` to `PUBLIC`), then removed with the statements `diag` names | every plugin test passes, no `role_too_broad` (before the fix: 324 failures, every book refused); the `diag` tests fail on the new FAIL `server-wide test database`, as decided |
| By hand, MySQL 8.0.46 and 9.7.4 | the grant set's view is `ALGORITHM=TEMPTABLE` with the `LIMIT` fence; `EXPLAIN FORMAT=TREE` shows the condition on `f.forwarding` above the scan of the materialised view, never on `forwardings` |
| PostgreSQL 13.23 and 14.25 | `PUBLIC` holds `CREATE` on schema `public` by default there; after `load.sh` the grant set had revoked it in the test database |
| SQLite + MariaDB 11.8.9 + PostgreSQL 17.12, PHP 8.4 × 1.7.7 and PHP 8.1 × 1.7.0 | **2996 tests, OK**, each, no skip; PHPStan level 8, PHP-CS-Fixer, `php -l` clean |
| `.github/workflows/ci.yml` | parsed as YAML; ShellCheck on every `run` step (49) clean; `Remediation2CiTest` (11 tests) passes, 6 of them fail on the workflow before the job `databases` |

Not run here: the CI job `databases` itself (Docker and GitHub; section 7).

### Final review – CI, harness and documentation (2026-11-25)

The final review's findings CI-1, CI-2 and DOCS-1 – DOCS-4 change the test harness, CI and the
documents, not the plugin. The eight new test cases (`Remediation2CiTest` 2, `Remediation2HarnessTest`
2, `Remediation2FinalReviewDocsTest` 4) all fail on a copy of the tree before them.

| Run | Result |
|---|---|
| SQLite + MariaDB 11.8.9 + PostgreSQL 17.12, PHP 8.4 × 1.7.7 and PHP 8.1 × 1.7.0 | **3008 tests, OK**, each, no skip; PHPStan level 8, PHP-CS-Fixer, `php -l` clean |
| The database matrix of section 6 (PostgreSQL 13, 14, 15, 16, 18, 19 each with a MariaDB server; MySQL 8.0, 8.4, 9.7, 26.10 alone) | the six pairs **3008 tests, OK**, no skip; MySQL 2626 tests, OK, each, with the same 11 PostgreSQL-only skips as before |
| E2E and browser suites, Roundcube 1.7.7 × PHP 8.4 × PostgreSQL 17 (as root with `GALBRIDGE_E2E_ALLOW_ROOT=1`); the fixture changes through a throw-away login role that `load.sh` had granted the test database (`GALBRIDGE_FIXTURE_USER`; `PSQL_ADMIN="psql -h 127.0.0.1 -U <role> -w"`) | E2E **37 passed, 0 failed, 0 skipped**; browser **9/9**, 0 skipped; the role read 15 mailboxes of the test database and was refused `pg_authid`; after a reload without the variable the same role made case 35 fail with "permission denied for table mailbox"; the role was dropped and the fixture reloaded as before |
| `.github/workflows/ci.yml` and `load.sh` | YAML parses; ShellCheck on every `run` step (50) and on `load.sh` clean; the new history check prints nothing for this repository and names a file force-added under `tmp/` and removed again in a throw-away clone |
| gitleaks 8.16 on the changed files | no finding with `.gitleaks.toml` or the default rules; a planted DSN with a 40-digit password is reported |

Not run here: the CI jobs `e2e` and `security` themselves (GitHub; section 7).

### Release verification of 1.0.2 (2026-11-25)

The tree of the 1.0.2 commit, after the last change of the final review (the PostgreSQL role check
also refuses a privilege on a tablespace or – PostgreSQL 15+ – on a configuration parameter; its new
test case fails on the tree before it). PHP 8.1.34, 8.4.28 and 8.5.13; Roundcube 1.7.0, 1.7.5, 1.7.6
and 1.7.7; MariaDB 11.8.9, PostgreSQL 17.12, Dovecot 2.4.1, Chromium 156; PHPStan 2.3.7, PHPUnit 13.4.

| Run | Result |
|---|---|
| `php -l` (8.1, 8.5), PHP-CS-Fixer, PHPStan level 8 | clean |
| PHPUnit, SQLite + MariaDB 11.8.9 + PostgreSQL 17.12, `--fail-on-skipped`: PHP 8.4 × 1.7.7, 8.1 × 1.7.0, 8.5 × 1.7.0, 8.1 × 1.7.7, 8.4 × 1.7.0, 8.4 × 1.7.5, 8.4 × 1.7.6, and in random order PHP 8.5 × 1.7.7 (seed 1795629340) and 8.1 × 1.7.0 (seed 1795629253) | **3009 tests, OK**, each, no skip |
| The database matrix of section 6 (PostgreSQL 13, 14, 15, 16, 18, 19 each with MariaDB 10.5, 10.6, 10.11, 11.4, 12.3, 13.1; MySQL 8.0, 8.4, 9.7, 26.10 alone) | the six pairs **3009 tests, OK**, no skip; MySQL 2626 tests, OK, each, with the 11 PostgreSQL-only skips of a run without a PostgreSQL server |
| `tests/e2e/matrix.sh` (Roundcube 1.7.0 × PHP 8.1/8.4, 1.7.7 × PHP 8.1/8.4/8.5, each × MariaDB and PostgreSQL; as root with `GALBRIDGE_E2E_ALLOW_ROOT=1`) | **10/10 combinations, 37 passed, 0 failed, 0 skipped** each |
| `tests/ui/test_ui.py`: 1.7.0 × PHP 8.1 × MariaDB, 1.7.0 × 8.4 × PostgreSQL, 1.7.7 × 8.4 × MariaDB, 1.7.7 × 8.5 × PostgreSQL | **9/9**, 0 skipped, each |

This run took place on the development machine, not in the disposable container that section 1 of
docs/RELEASING.md asks for; the CI run of the release commit repeats the suites on GitHub's runners.

### CI runs of 1.0.2 (2026-11-25)

The first GitHub runs of the jobs added in 1.0.2 found four problems of the CI environment, none of
the plugin; each was fixed in the test harness or the workflow and the next run proved it:

| Run | Found | Fix |
|---|---|---|
| 1 | the `mysql` client of the MySQL images and the client in the Debian container of job `e2e` have no UTF-8 locale and stored the UTF-8 fixture as latin1; three tests assumed a root run (CI runs PHPUnit as the runner user); one test read the runner-local `composer.json` edit of the 1.7.0 legs; Dovecot's master stayed a zombie in the container (PID 1 reaped nothing) and counted as running | `--default-character-set=utf8mb4` in `load.sh` and every administrator helper of the tests; the tests take the non-root case into account; the committed manifest is read with `git show`; job `e2e` runs its container with `--init` and ignores a zombie |
| 2, 3 | the root step of the PHP 8.4 legs: `--allow-root` refused the copy below `/opt` (mode 0777 on the runners), then the entry `/usr/share/php/<version>` (0777) of the runner's `include_path` | the copy lies below `/srv`, and an extra ini file of the step keeps only `.` in `include_path` - the refusals show that the checks work |
| 4 (commit `126a58e`) | – | **every job green**: PHPUnit on PHP 8.1 – 8.5 (newest 1.7.x and 1.7.0) with the root step, the seven server pairs of job `databases`, job `e2e` (four legs, unprivileged, PostgreSQL access scoped to the test database), PHPStan, PHP-CS-Fixer, the secret scan with its controls |

### Real installation: Debian 13 + iRedMail 1.8.11 (2026-11-25, changelog 1.0.3)

Two disposable Debian 13 (trixie) systems (systemd-nspawn containers on a private network, no
inbound access), each set up with the unattended installer of **iRedMail 1.8.11**: the **MariaDB
edition** and the **PostgreSQL edition**, both with Roundcube **1.7.7** (`/opt/www/roundcubemail`),
PHP **8.4.28** (PHP-FPM), nginx, Dovecot, Postfix, MariaDB 11.8 or PostgreSQL 17, Amavisd,
iRedAPD and iRedAdmin 2.8.1, exactly as iRedMail configures them. The fictional directory of
`tests/fixtures/vmail/seed.sql` was loaded into the real `vmail` database (rows iRedMail had
created – the first domain, `postmaster` – kept), one user was added with iRedMail's own
`tools/create_mail_user_SQL.sh`, and the login users got passwords with `doveadm pw`. The plugin was
then installed by following README section 2 command by command:

| Step | MariaDB edition | PostgreSQL edition |
|---|---|---|
| 2.1 install | release archive of the signed tag `v1.0.2`: `verify-tag` printed the documented "Good" line and fingerprint; every file root's, nothing group/other-writable | `composer require` from **Packagist**: `takenek/galbridge` v1.0.2, source reference = the tag commit; installer enabled the plugin |
| 2.2 role | `grants --allow-root --driver=mysql`, the three review steps, `mysql --binary-mode` | `grants --allow-root --driver=pgsql`, `psql -X -v ON_ERROR_STOP=1`; the three `pg_hba.conf` lines inserted before iRedMail's catch-all `host all all 0.0.0.0/0 md5` – a connection of `galbridge` to `roundcubemail` over TCP is rejected (the unix socket was not tested: iRedMail's `local all all md5` still let it in – found and fixed in 1.0.4, below) |
| 2.6 nginx | before the rule `static.php/plugins/galbridge/lib/autoload.php` and `static.php/plugins/galbridge/SQL/grants.mysql.sql` answered **200** (PHP source and SQL template downloadable); after it 403, the webmail and core assets unchanged | the same |
| 2.7 `diag` | OK; one WARN `core config file` (iRedMail's `www-data`, 0600), OK after `chown root:www-data` + `chmod 0640`; `--user` (jan): 8 persons, 5 alias accounts, 1 mailing list, 4 department groups; `--strict` OK | OK without warnings, same counts |
| browser, headless Chromium through the real nginx / PHP-FPM / Dovecot (login, read-only directory with its groups, contact frame, group members, quick search, autocomplete, group expansion in compose, tenant B, IDN tenant, no JavaScript error) | 10 of 10 | 10 of 10 |
| the same with the global book (`['*']` viewers, two data domains) and `galbridge_user_aliases` | 10 of 10, foreign entry shown as `(example.org) Bob Smith` | 10 of 10 |
| vCard export of both books and autocomplete over HTTPS, searched for the fixture canaries (hashes, recovery address, private forwarding targets, `allow_nets`) | 14 vCards (global export off: 204), no canary | the same |
| logs | Roundcube (syslog): logins only, no message of the plugin or of PHP | the same |

What the run found – none of it in the directory code; fixed in 1.0.3:

* `composer require` stops on both SQL editions with `pear/net_ldap2 v2.3.0 requires ext-ldap`
  (Roundcube's `composer.lock`; iRedMail installs `php-ldap` for its OpenLDAP edition only):
  README 2.1 and 5 add `--ignore-platform-req=ext-ldap`, which was then used for the run above;
* `verify-tag` needs `ssh-keygen` (`openssh-client`), which a minimal Debian lacks: README 2.1;
* `diag --url` against iRedMail's self-signed certificate reported "no answer (... operation
  failed)" for every request: a TLS reason, one WARN with the remedy and `--cafile=`; with
  `--cafile=/etc/ssl/certs/iRedMail.crt` PHP verifies the certificate (issued for the host name);
* the `core config file` WARN for iRedMail's `config.inc.php` now gives the remedy (README 2.4).

The host of the run boots with `ipv6.disable=1`; there Debian's `dovecot-core` and `nginx`
packages fail in their maintainer scripts (they start the services with their default `::`
listeners) before iRedMail configures them. That is a property of the host, not of iRedMail or the
plugin: the run installed the packages with service starts held back (`policy-rc.d`) and booted the
systems afterwards, as the installer asks.

### Release verification of 1.0.3 (2026-11-25)

The tree of the 1.0.3 commit, in a **disposable Debian 13 container** (systemd-nspawn, no signing
key, no SSH agent, no GitHub or Packagist credentials; docs/RELEASING.md step 2), as the
unprivileged user `tester` unless noted: PHP 8.4.28, Roundcube 1.7.0 and 1.7.7 (release tarballs,
SHA-256 as in CI), MariaDB 11.8.9, PostgreSQL 17.12, Dovecot 2.4.1, Chromium 156, PHPUnit 13.4.8,
PHPStan 2.3, PHP-CS-Fixer 3.95. The development dependencies were resolved afresh in the container
(the CI run of the release commit is linked from the notes of the GitHub release); PHP 8.1 – 8.3 and 8.5 are covered by CI.

| Run | Result |
|---|---|
| PHPUnit, SQLite + MariaDB + PostgreSQL, `--fail-on-skipped --exclude-group root`: 1.7.7 and 1.7.0 | **2999 tests, OK**, each, no skip (the tree of 1.0.2 lists 2998 in the same environment: the new test of `diag --cafile`) |
| PHPUnit `--group root-aware` as root, from a root-owned copy over a root-owned 1.7.7 tree | **367 tests, OK** – after `Remediation2ConfigTest` also drops the inheritable DAC capabilities: systemd-nspawn gives root a full inheritable set, which `setpriv --bounding-set` alone left in effect at `exec` (a property of the container, not of the plugin; the test failed there before) |
| PHPStan level 8 (1.7.7 tree), PHP-CS-Fixer, `php -l`, `composer validate --strict`, `composer audit` | clean |
| `tests/e2e/test_e2e.py` and `tests/ui/test_ui.py`: Roundcube 1.7.0 and 1.7.7 × MariaDB and PostgreSQL | E2E **37 passed, 0 failed, 0 skipped**, UI **9/9**, each of the four |
| the changed `diag --url` on the real iRedMail server above (MariaDB edition) | without `--cafile`: the TLS reason and one WARN with the remedy; with `--cafile=/etc/ssl/certs/iRedMail.crt`: the webmail recognised and every path `not served (HTTP 403)`, `Result: OK`; with iRedMail's original nginx template (no deny rule): `[FAIL] web access static.php/plugins/galbridge/composer.json is SERVED (HTTP 200)` and the same for `lib/autoload.php` |

### Real installation by the documentation alone: Debian 13 + iRedMail 1.8.11 (2026-11-26, changelog 1.0.4)

Four more disposable Debian 13 systems (systemd-nspawn, private network), each with a fresh,
unattended **iRedMail 1.8.11** – Roundcube 1.7.7, PHP 8.4.28 PHP-FPM, nginx, MariaDB 11.8 or
PostgreSQL 17 – and the fictional directory of `tests/fixtures/vmail/seed.sql` in the real `vmail`
database, as in the run above. This time each installation was made by an administrator who knew
nothing but the documentation (an independent agent that read README.md, SECURITY.md, docs/ and
config.inc.php.dist, never `tests/` or `lib/`), and reported every step that failed, needed a guess or
was hard to follow; a second agent then verified the result, a third (second round) went through
README sections 5 and 6.

| Round | MariaDB edition | PostgreSQL edition |
|---|---|---|
| 1 – README of 1.0.3 | Composer from Packagist (1.0.3), Composer installed as the docs did not say | signed tag v1.0.3 on a server without `git` and `ssh-keygen` |
| 2 – README of 1.0.4 (release candidate: the locally signed tag v1.0.4; its archive served to Composer as a package repository in place of Packagist) | Composer, on a server without `git`, `ssh-keygen`, `sudo`, `curl` or Composer | signed tag, on a server without the same tools |

Both rounds, both editions: `diag`, `diag --user=jan.kowalski@example.com`, `--strict` and
`--url=https://<certificate host name>/mail/ --cafile=/etc/ssl/certs/iRedMail.crt` end with
`Result: OK` (round 2: no WARN line at all); headless Chromium through the real nginx / PHP-FPM /
Dovecot (the cases of the run above) 10 of 10 with the domain book and 10 of 10 with the global book
and `galbridge_user_aliases`; vCard export and autocomplete without a canary; the whole of the
search-only global book is refused (204) also with `galbridge_global_export = true`; no PHP message
in any log; the role holds column-level `SELECT` only. Round 2 in addition: the files Composer
installed are identical to `git archive` of the tag (`diff -r`); on PostgreSQL both test logins of
README 2.2 (TCP and unix socket, to `roundcubemail`) are rejected by `pg_hba.conf`; the per-client
limits of 2.6 answer a burst with 503 and leave static files alone.

What round 1 found in the documentation – fixed in 1.0.4 (CHANGELOG): iRedMail's `local all all md5`
let the role log in over the unix socket to every database although the three documented `host`
lines were in place; a root `composer require` without a terminal installs into
`vendor/takenek/galbridge`; no step installed Composer; the activation question and "skip 2.4"; the
DSN line of `grants` pasted with its `-- `; `sudo` and `curl` missing; the connection limit sized to
the pool of 100 against database servers that keep `max_connections` at 151 (MariaDB) and 100
(PostgreSQL); `diag --url` with `127.0.0.1` against the certificate; `php-fpm -m`; section 5
describing a new Roundcube tree. Round 2 found only wording and order (folded into 1.0.4 before
the tag: the DSN line goes into a file by default, the commit check before the `rm`, the
certificate name command, the nginx insertion command, `rsync`, where PHP's messages are) and
these, also in 1.0.4:

* README 5 checked against the real procedure – `installto.sh` from a tree unpacked with `tar zxf`
  keeps the plugin, its configuration and the plugin list, but gives 2639 (Composer installation) or
  3673 files to uid 1000, `plugins/` included; `grants --allow-root` then refuses exactly as documented,
  the documented `find ... chown` makes the tree root's again (`grants` and `diag` OK, browser 10/10),
  and `tar --no-same-owner` leaves no file to uid 1000; on the Composer installation `update.sh` stops
  with the `ext-ldap` error and the documented `composer update --no-dev --prefer-dist
  --ignore-platform-req=ext-ldap` completes it; an iRedMail template replaced by the original turns
  `diag --url` into `[FAIL] ... is SERVED (HTTP 200)` until the rule is back; the view dropped before a
  `forwardings` change and the grant set applied again;
* Composer without a terminal after an interactive installation: `composer update` installs a
  second copy into `vendor/takenek/galbridge`, points Composer's autoloader for `GalBridge\` at it and
  leaves `plugins/galbridge` alone; `composer remove` leaves `plugins/galbridge` in place. 1.0.4
  registers the plugin's autoloader first and `diag` warns about the copy ("composer copy"); the
  recovery of README 7 (`composer remove` without a terminal, then the interactive `composer require`)
  was run on the real server: the copy is gone, `plugins/galbridge` is the release again and the
  autoloader points there;
* README 6 on both editions: no role, no view, no privilege row, `pg_hba.conf` byte-identical to the
  copy taken before the installation, both nginx templates identical to iRedMail's, `nginx -t` OK,
  the webmail and the personal address books work.

### Release verification of 1.0.4 (2026-11-26)

The tree of the release commit in the disposable development container of section 6 (Debian 13,
PHP 8.4.28, Roundcube 1.7.0 and 1.7.7 release tarballs, MariaDB 11.8.9, PostgreSQL 17.12, Dovecot
2.4.1, Chromium 156), as the unprivileged user `tester` unless noted:

| Run | Result |
|---|---|
| PHPUnit, SQLite + MariaDB + PostgreSQL, `--fail-on-skipped --exclude-group root`: 1.7.7 and 1.7.0 | **3010 tests, OK**, each (1.0.3: 2999; new: `InstallationDocsTest`, the badge test, the `composer copy` and autoloader tests of `Remediation2DiagConfigTest`) |
| PHPUnit `--group root-aware` and `--group root` as root, from root-owned copies over a root-owned 1.7.7 tree | **369 tests, OK**; **11 tests, OK** (the root refusal names `runuser`) |
| PHPStan level 8 (1.7.7 tree), PHP-CS-Fixer, `php -l`, `composer validate --strict` | clean |
| `tests/e2e/test_e2e.py` and `tests/ui/test_ui.py`, Roundcube 1.7.7 × MariaDB and PostgreSQL | E2E **37 passed, 0 failed, 0 skipped**, UI **9/9**, each |
| `tests/release/check-archive.php v1.0.4`, `tests/release/verify-tag.sh v1.0.4` | 58 entries, all on the allow-list, nothing missing; good signature of `galbridge-release` |

### CI and post-release verification of 1.0.4 (2026-11-26)

CI run of the release commit `baa01d8`: <https://github.com/takenek/galbridge/actions/runs/45758442867>
(21 jobs green, the release checks wait for the tag); CI run of the tag `v1.0.4`, including the
release checks: <https://github.com/takenek/galbridge/actions/runs/45758902380> (22 jobs green).
Packagist listed `v1.0.4` at `baa01d8` (source and dist) minutes after the tag; github.com and
packagist.org show the badges `packagist v1.0.4` and `license GPL-3.0-or-later` (packagist.org
through `rawcdn.githack.com`, which serves `image/svg+xml`).

Then, from the published sources only, on three real iRedMail 1.8.11 servers of this section:

| Server | What | Result |
|---|---|---|
| MariaDB edition with 1.0.3 from Packagist | README 5, Composer: `composer update` in an interactive root shell | `Upgrading takenek/galbridge (v1.0.3 => v1.0.4)`, no activation question, no `vendor/takenek/galbridge`, no backup copy; the files identical to `git archive` of the verified tag, the commit that of the tag; Roundcube's Settings > About lists GAL Bridge 1.0.4; `diag` all forms `Result: OK`; browser 10/10 |
| PostgreSQL edition with 1.0.3 from the tag | README 5, release archive, then "From 1.0.3 to 1.0.4" | tag verified, files identical to the tag (no file of 1.0.3 left); with the three `host` lines of 1.0.3 the socket login of the role to `roundcubemail` succeeded, with `local all galbridge reject` added it is refused (TCP refused before and after); `diag` `Result: OK`; browser 10/10 |
| PostgreSQL edition, plugin removed before | README 2 from scratch, signed tag from GitHub | every step as written (the four `pg_hba.conf` lines, both test logins refused, the 2.3 commands, the nginx rule 200 → 403); `diag` `Result: OK` without WARN; browser 10/10 |

What it found – in 1.0.5: both upgraded installations had the connection limit 100 of the 1.0.3
advice, and `diag` 1.0.4 called it OK; the upgrade note did not warn installations with the socket
DSN about the `reject` line and gave no commands; the release-archive block of 2.1 unpacked the
files before the fingerprint comparison; no `diag` line named the plugin release.

### Release verification of 1.0.5 (2026-11-26)

The tree of the release commit in the development container of section 6, as for 1.0.4:

| Run | Result |
|---|---|
| PHPUnit, SQLite + MariaDB + PostgreSQL, `--fail-on-skipped --exclude-group root`: 1.7.7 and 1.7.0 | **3011 tests, OK**, each (new: `DiagAdviceTest`; `testDiagReportsTheConnectionLimitOfTheAccount` gives a role 1000 connections – WARN on MariaDB 11.8 with 151 and PostgreSQL 17 with 100 – and checks the `GAL Bridge` line) |
| PHPUnit `--group root-aware` and `--group root` as root, from root-owned copies over a root-owned 1.7.7 tree | **369 tests, OK**; **11 tests, OK** |
| PHPStan level 8, PHP-CS-Fixer, `php -l`, `composer validate --strict` | clean |
| `tests/e2e/test_e2e.py` and `tests/ui/test_ui.py`, Roundcube 1.7.7 × MariaDB and PostgreSQL | E2E **37 passed, 0 failed, 0 skipped**, UI **9/9**, each |
| the two `pg_hba.conf` commands of README 2.2 and 5, the nginx command of 2.6, the 2.3 DSN commands | run as printed on copies of iRedMail's files: the lines in the right place, a second run changes nothing, a missing DSN file changes nothing |

### CI and post-release verification of 1.0.5 (2026-11-26)

CI run of the release commit `a7881d0`: <https://github.com/takenek/galbridge/actions/runs/45761746657>
(21 jobs green, the release checks wait for the tag); CI run of the tag `v1.0.5`, including the
release checks: <https://github.com/takenek/galbridge/actions/runs/45762346946> (22 jobs green).
Packagist listed `v1.0.5` at `a7881d0`; github.com and packagist.org show `packagist v1.0.5` and
`license GPL-3.0-or-later`. From the published sources, on the real iRedMail 1.8.11 servers above:

| Server | What | Result |
|---|---|---|
| MariaDB edition with 1.0.4 from Packagist | README 5, Composer, interactive root shell | `Upgrading takenek/galbridge (v1.0.4 => v1.0.5)`, no `vendor/takenek/galbridge`; `diag`: `[ OK ] GAL Bridge 1.0.5`; with `--grants-only --connection-limit=100` `[WARN] connection limit 100 of the 151 connections the server has for ordinary roles (max_connections) ...`, with the default again `[ OK ] connection limit 25`; browser 10/10 |
| PostgreSQL edition with 1.0.4 from the tag | README 5, release archive with the `diff -r` check | tag and fingerprint verified, `identical`; `diag`: `GAL Bridge 1.0.5`, limit 100 a WARN (`100 of the 97 connections ...`: 100 minus 3 reserved), 25 OK; browser 10/10 |

### Installation by the documentation of 1.0.5, and its review (2026-11-26, changelog 1.0.6)

Two more fresh, disposable Debian **13.8** systems (systemd-nspawn, private network), each set up
with the unattended installer of **iRedMail 1.8.11** – Roundcube 1.7.7, PHP 8.4.27 PHP-FPM, nginx
1.26.3, Dovecot 2.4.1, MariaDB 11.8.9 or PostgreSQL 17.12 –, without `git`, `ssh-keygen`, `sudo`,
`curl` or Composer, and the fictional directory of `tests/fixtures/vmail/seed.sql` in the real
`vmail` database (as in the runs above). The **published** release 1.0.5 was installed by an
administrator who knew nothing but the documentation (an independent agent that read README.md,
SECURITY.md, CHANGELOG.md, config.inc.php.dist and docs/, never docs/TESTING.md, `tests/` or `lib/`);
a second agent then checked the end state of each server:

| | MariaDB edition | PostgreSQL edition |
|---|---|---|
| 2.1 | signed tag `v1.0.5` from GitHub: `verify-tag` printed the documented line, both fingerprint commands the same `SHA256:` value; `git archive` | Composer from **Packagist** (`v1.0.5`, source `a7881d0`; Debian's Composer 2.8.8, interactive root shell, the activation question answered `n`); the installed files compared with `git archive` of the verified tag: `identical` |
| 2.2 – 2.6 | every command as written: the review `diff` showed only the account line; the deny rule landed on line 5 of both templates, `static.php/plugins/galbridge/lib/autoload.php` 200 before it, 403 after it; the per-client limits answer a burst with 503 | the same, and the four `pg_hba.conf` lines directly above iRedMail's `local all all md5`; both test logins of 2.2 (TCP and unix socket, to `roundcubemail`) rejected |
| `diag`, `--user`, `--url --cafile`, `--strict` | `Result: OK`, no WARN line; `[ OK ] GAL Bridge 1.0.5` | the same |
| end state (second agent) | the plugin directory identical to `git archive v1.0.5`, root's, nothing group/other-writable; `config/galbridge.inc.php` root:www-data 0640, the only configuration file; column-level `SELECT` only, connection limit 25, the fenced view; no grant file, DSN file or clone left; no PHP message in any log | the same |
| browser, headless Chromium through the real nginx / PHP-FPM / Dovecot – the domain book | 10 of 10 | 10 of 10 |
| the same with the global book (`['*']` viewers, two data domains) and `galbridge_user_aliases` | 10 of 10 | 10 of 10 |
| vCard export of both books and autocomplete, searched for the fixture canaries | 14 vCards, the search-only global book 204, no canary | the same |

The archive Packagist serves for `v1.0.5` (GitHub's zipball of `a7881d0`) holds exactly the files of
`git archive v1.0.5`; github.com and packagist.org showed the badges `packagist v1.0.5`, `license
GPL-3.0-or-later` and the CI badge (HTTP 200, `image/svg+xml`).

In parallel four reviewers (independent agents with the iRedMail 1.8.11 and Roundcube 1.7.7 sources
and the plugin code) went through README 1 – 2 for the MariaDB and for the PostgreSQL edition
(running the documented commands on copies of iRedMail's files and the `grants` output of the
static PHP 8.4), the documentation against the code (every CLI option and quoted `diag` line, the 51
option defaults of `config.inc.php.dist`, the localisation labels, the versions) and the lifecycle
sections with every link and anchor (checked by a script); each finding was re-checked by one or two
further agents. What the installations and the review found – all in the documentation, fixed in
1.0.6 (CHANGELOG), the code unchanged:

* the nginx command of 2.6 changed into `/etc/nginx/templates`, so the `diag` commands after it
  (2.6 `--url`, 2.7) failed with `command not found` when pasted in order;
* 2.2 had both `grants` lines in one block – pasted as a whole, the PostgreSQL line overwrote the
  MariaDB grant set and DSN;
* the review `diff` of the `--grants-only` output was described for MariaDB/MySQL only (on
  PostgreSQL it also shows the existence check turned round and the `ALTER ROLE ... NOINHERIT;`
  line – reproduced with both drivers);
* `diag --strict`, offered for automated checks, checks no web access without `--url`;
* `composer show` without a terminal answers `Package "takenek/galbridge" not found` (run on the
  PostgreSQL server); the repair of a `composer copy` empties `plugins/galbridge` (Composer's
  `ArchiveDownloader`); a Composer installation copied into a new Roundcube tree is not in its
  `composer.json`; the HTTPS item of the SECURITY.md checklist did not say what it means on iRedMail.

### Release verification of 1.0.6 (2026-11-26)

The tree of the release commit in a disposable development container (Debian 13.8, PHP 8.4.27,
Roundcube 1.7.0 and 1.7.7 release tarballs with the SHA-256 of CI, MariaDB 11.8.9, PostgreSQL 17.12,
Dovecot 2.4.1, Chromium 156, PHPUnit 13.4.8, PHPStan 2.3.7, PHP-CS-Fixer 3.95), as the unprivileged
user `tester` unless noted:

| Run | Result |
|---|---|
| PHPUnit, SQLite + MariaDB + PostgreSQL, `--fail-on-skipped --exclude-group root`: 1.7.7 and 1.7.0 | **3013 tests, OK**, each (1.0.5: 3011; new: the two 1.0.6 tests of `InstallationDocsTest`, which fail on the documentation of 1.0.5) |
| PHPUnit `--group root-aware` and `--group root` as root, from root-owned copies over a root-owned 1.7.7 tree | **369 tests, OK**; **11 tests, OK** |
| PHPStan level 8, PHP-CS-Fixer, `php -l`, `composer validate --strict`, `tests/release/check-archive.php HEAD` | clean; 58 entries, all on the allow-list |
| `tests/e2e/test_e2e.py` and `tests/ui/test_ui.py`, Roundcube 1.7.7 and 1.7.0 × MariaDB and PostgreSQL | E2E **37 passed, 0 failed, 0 skipped**, UI **9/9**, each of the four |
| the changed commands of README 2.2 (`grants --grants-only` and the review `diff`, both drivers) and 2.6 (the nginx command with full paths, on copies of iRedMail's templates) | as the README now describes them |

### CI and post-release verification of 1.0.6 (2026-11-26)

CI run of the release commit `71226a6`: <https://github.com/takenek/galbridge/actions/runs/45769311312>
(21 jobs green – with Composer 2.11.2 and the refreshed `debian:13-slim` digest –, the release checks
wait for the tag); CI run of the tag `v1.0.6`, including the release checks:
<https://github.com/takenek/galbridge/actions/runs/45770716453> (22 jobs green). Before the tag, the
PHPUnit suite ran once more in the development container from the dependency sets of the CI run of
the release commit (`composer-lock-tests-php8.4-newest` and `-1.7.0`, `composer install`, `composer
audit`: no advisory): **3013 tests, OK** against 1.7.7 and against 1.7.0. The tag was verified with
`tests/release/verify-tag.sh` and `check-archive.php` before the push; the GitHub release is
immutable. Packagist listed `v1.0.6` at `71226a6` (source and dist); github.com and packagist.org
show the badges `CI passing`, `packagist v1.0.6` and `license GPL-3.0-or-later` (checked in headless
Chromium: every badge image loaded). From the published sources, on the two iRedMail 1.8.11 servers
of the 1.0.6 run above:

| Server | What | Result |
|---|---|---|
| MariaDB edition with 1.0.5 from the tag | README 5, release archive | tag and both fingerprints verified, the `diff -r` check `identical`; `diag`, `--user` and `--strict --url --cafile`: `[ OK ] GAL Bridge 1.0.6`, `Result: OK`, no WARN; browser 10/10, no canary |
| PostgreSQL edition with 1.0.5 from Packagist | README 5, Composer in an interactive root shell | `Upgrading takenek/galbridge (v1.0.5 => v1.0.6)`, no `vendor/takenek/galbridge`, no backup copy; `composer show` names `71226a6`, the commit of the verified tag; the files identical to `git archive v1.0.6`; `diag` as above; browser 10/10, no canary |

The CI run of the commit that recorded this (`9238fc1`, documentation only) failed once in case U7
of the browser suite, on both Roundcube 1.7.7 legs: the test clicked the directory book while
Roundcube was still fetching the first contact list, and `rcmail.command()` ignores every command
while the interface is busy (app.js of 1.7.7: "do nothing if interface is locked by another
command"), so the click was lost (U7 took 10 s instead of 1.1 – 1.6 s). The re-run of the two jobs
was green: <https://github.com/takenek/galbridge/actions/runs/45771700168>. `tests/ui/test_ui.py` now
waits until the address book is no longer busy before it clicks a source (9/9 locally on 1.7.7 and
1.7.0 × MariaDB and PostgreSQL); the test harness is not part of a release.

### Fresh installation of 1.0.6 by the documentation, and a fourth review (2026-11-26)

Two more new, disposable Debian **13.8** systems (systemd-nspawn, private network), each set up with
the unattended installer of **iRedMail 1.8.11** and its **default** components (netdata included) –
Roundcube 1.7.7, PHP 8.4.28 PHP-FPM, nginx 1.26.3, Dovecot 2.4.1, MariaDB 11.8.9 or PostgreSQL
17.12 –, without `git`, `ssh-keygen`, `sudo` or Composer (`curl` came with netdata), and the
fictional directory of `tests/fixtures/vmail/seed.sql` in the real `vmail` database. The published
release 1.0.6 was installed **afresh** (the runs above upgraded 1.0.5) by an administrator who knew
nothing but the documentation published with `v1.0.6` (an independent agent that read README.md,
SECURITY.md, CHANGELOG.md, config.inc.php.dist and docs/, never docs/TESTING.md, `tests/` or
`lib/`); a second agent then checked the end state of each server and ran the browser checks:

| | MariaDB edition | PostgreSQL edition |
|---|---|---|
| 2.1 | Composer from **Packagist** (`v1.0.6`, source `71226a6`; Debian's Composer 2.8.8, interactive root shell, the activation question answered `n`); the clone of the tag verified (`verify-tag` printed the documented line, both fingerprint commands the same `SHA256:` value) and the installed files compared with its `git archive`: `identical` | signed tag `v1.0.6` from GitHub: `verify-tag` and both fingerprint commands as documented; `git archive` |
| 2.2 – 2.6 | every command as written: the deny rule on line 5 of both templates, both `curl` requests of 2.6 `403`; the per-client limits at the top of both PHP locations | the same, and the four `pg_hba.conf` lines directly above iRedMail's `local all all md5`; both test logins of 2.2 rejected |
| `diag`, `--user`, `--url --cafile`, `--strict --url --cafile` | `Result: OK`, no WARN or FAIL line; `[ OK ] GAL Bridge 1.0.6` | the same |
| end state (second agent) | the plugin directory identical to `git archive v1.0.6`, root's, nothing group/other-writable; `config/galbridge.inc.php` and `config/config.inc.php` root:www-data 0640; column-level `SELECT` only, connection limit 25, statement time 5 s, the fenced view; no grant file, DSN file, clone or check directory left; no PHP message in any log | the same, and the role attributes and defaults of the grant set |
| browser, headless Chromium 156 through the real nginx / PHP-FPM / Dovecot – the domain book | 10 of 10 | 10 of 10 |
| the same with the global book (`['*']` viewers, two data domains) and `galbridge_user_aliases`, set as README 3.3 describes (`diag`: `Result: OK`, no WARN) | 10 of 10 | 10 of 10 |
| vCard export of both books and autocomplete, searched for the fixture canaries | 14 vCards, the search-only global book 204, no canary | the same |

In parallel four reviewers (independent agents with the iRedMail 1.8.11 and Roundcube 1.7.7 sources
and the plugin code) went through README 1 – 2 for the MariaDB and for the PostgreSQL edition
(running `grants` of both drivers in all three forms, with the static PHP 8.4, on root-owned copies),
the documentation against the code (the 51 options and their defaults and ranges, the log codes, the
quoted `diag` lines, the CLI options, the versions) and the lifecycle sections, the 61 links and
anchors (checked by a script) and the publication (badges, Packagist metadata, the immutable release,
`.gitattributes`); each finding was re-checked by two further agents. No step failed and no code
changed; two sentences of README section 2 were corrected (CHANGELOG 1.0.7):

* item 3 of the grant review of 2.2 described the `diff` of the default form – the one a first
  installation applies; MariaDB/MySQL `1c1`, the `CREATE USER` line with the verifier – only in
  general terms, and its sentence on the `--grants-only` form read as if it applied to every output:
  the docs-only administrator of the MariaDB server expected the `CREATE USER` line "missing". The
  item now names what the `diff` shows for each form (reproduced with both drivers: default `1c1` /
  `26c26`, `--grants-only` `1d0` / the turned-round check with the added `ALTER ROLE`,
  `--reset-password` `CREATE USER IF NOT EXISTS` + `ALTER USER` / the same plus the password line);
* `curl` is installed by iRedMail together with its netdata component, which the installer selects
  by default – the README said it was not part of iRedMail.

A further claim – that the comment of `galbridge_domain_searchonly` in `config.inc.php.dist` lets an
`'auto'` book keep its groups below the threshold – was refuted by both re-checks: the comment says
nothing about that case, and README 3.1 states that search-only books (`true` or `'auto'`) have no
groups. A further independent agent reviewed the change itself: it reproduced every form of the
new item 3 with both drivers and the netdata dependency in the iRedMail source; its remarks are
taken in – a non-local `--account-host` adds the `REQUIRE SSL` line, `--clear-text-password` replaces
the account line, iRedMail selects netdata by default on Debian 13 (not on every platform), and the
method count of the `InstallationDocsTest` row of section 3 was out of date since 1.0.6.

The documentation change was verified in a disposable development container (Debian 13.8, PHP
8.4.28, the Roundcube 1.7.0 and 1.7.7 release tarballs with the SHA-256 of CI, MariaDB 11.8.9,
PostgreSQL 17.12, PHPUnit 13.4.8):

| Run | Result |
|---|---|
| PHPUnit, SQLite + MariaDB + PostgreSQL, `--fail-on-skipped --exclude-group root`, as the unprivileged user `tester`: 1.7.7 and 1.7.0 | **3014 tests, OK**, each (new: `InstallationDocsTest::testTheReviewDiffIsDescribedForEveryFormAndCurlForNetdata`). The first run, in a container without `python3`, failed once in `Remediation2HarnessTest` (exit code 127 of the Python harness); with `python3`, which CI has, both runs passed |
| PHPUnit `--group root-aware` as root, from a root-owned copy over the root-owned 1.7.7 tree | **369 tests, OK** |
| PHPStan level 8, PHP-CS-Fixer, `composer validate --strict` | clean |

### Installation by the documentation of 1.0.7, and a fifth review (2026-11-26, changelog 1.0.7)

Two more new, disposable Debian **13.8** systems (systemd-nspawn, private network), each set up with
the unattended installer of **iRedMail 1.8.11** and its default components (netdata included) –
Roundcube 1.7.7, PHP 8.4.28 PHP-FPM, nginx 1.26.3, Dovecot 2.4.1, MariaDB 11.8.9 or PostgreSQL
17.12 –, without `git`, `ssh-keygen`, `sudo` or Composer, and the fictional directory of
`tests/fixtures/vmail/seed.sql` in the real `vmail` database. The published release 1.0.6 – the code
of 1.0.7 apart from one INFO line of `diag` – was installed afresh, each edition with the method the
run above did not use, by an administrator who knew nothing but the documentation of `main` at
`0065f44` (README.md, SECURITY.md, CHANGELOG.md, config.inc.php.dist and docs/, never
docs/TESTING.md, `tests/` or `lib/`); a second agent then checked the end state of each server and
ran the browser checks:

| | MariaDB edition | PostgreSQL edition |
|---|---|---|
| 2.1 | signed tag `v1.0.6` from GitHub (git 2.47.3): `verify-tag` printed the documented line, both fingerprint commands the same `SHA256:` value; `git archive` | Composer from **Packagist** (`v1.0.6`, source `71226a6`; Debian's Composer 2.8.8, interactive root shell, the activation question answered `n`); the clone of the tag verified and the installed files compared with its `git archive`: `identical` |
| 2.2 – 2.6 | every command as written: the review `diff` showed `1c1`, the account line of the default form, as item 3 now says; the deny rule on line 5 of both templates, `static.php/plugins/galbridge/lib/autoload.php` 200 before it, both `curl` requests of 2.6 `403` after it | the same, and the four `pg_hba.conf` lines directly above iRedMail's `local all all md5`; both test logins of 2.2 rejected |
| `diag`, `--user`, `--url --cafile`, `--strict --url --cafile` | `Result: OK`, no WARN or FAIL line; `[ OK ] GAL Bridge 1.0.6` | the same |
| end state (second agent) | the plugin directory identical to `git archive v1.0.6` (SHA-256 of every file), root's, nothing group/other-writable; `config/galbridge.inc.php` and `config/config.inc.php` root:www-data 0640; column-level `SELECT` only, connection limit 25, statement time 5 s, the fenced view; no grant file, DSN file, clone or check directory left; no PHP message in any log | the same, and the role attributes and defaults of the grant set |
| browser, headless Chromium 156 through the real nginx / PHP-FPM / Dovecot – the domain book (14 entries, derived from the vmail rows and README 3.6/3.7) | 10 of 10 | 10 of 10 |
| the same with the global book (`['*']` viewers, two data domains) and `galbridge_user_aliases` (`diag`: `Result: OK`, no WARN) | 10 of 10 | 10 of 10 |
| vCard export of both books and autocomplete, searched for the fixture canaries | 14 vCards, the search-only global book 204, no canary | the same |

In parallel two reviewers (independent agents with the iRedMail 1.8.11 and Roundcube 1.7.7 sources
and the plugin code) went through the documentation once as an administrator who reads README 1, 2,
3.3 and 5 – 7 for the first time (both editions; item 3 of the grant review reproduced with both
drivers in all three forms, with a non-local `--account-host` and with `--clear-text-password`; the
commands of 2.2, 2.3 and 2.6 on copies of iRedMail's files) and once for the publication: the
Packagist metadata of every version (source references = tag commits; the archive of `v1.0.6`
byte-identical to `git archive v1.0.6`), the GitHub releases (immutable, signed tags verified), the
three badges on github.com and packagist.org (200, `image/svg+xml`), the 51 relative links and
anchors, the external URLs and the version references a bump changes. Neither found a problem.

Two changes came out of the run:

* the end-state check of the PostgreSQL server found the INFO line `mailboxes without a name 1
  (shown with their address)`, while the mailbox it counted appeared as "Anna Nowak" – first and
  last name, as README 3.4 says; without them the directory shows the local part, never the
  address. Both re-checks confirmed it against `RecordMapper::person()`; `diag` now says what the
  directory does (CHANGELOG 1.0.7, `CliToolTest` pins the text);
* the per-client limits of 2.6 were the one required change of an nginx file without commands: both
  administrators – as in the two runs before – wrote their own `sed`. README 2.6 has a block now; on
  both servers, after the limits the administrators had added were removed, it put the two
  `*_zone` lines into `/etc/nginx/conf-enabled/roundcube-limits.conf` and the two lines directly
  below the PHP location of each template, a second run changed nothing, `nginx -t` passed, the
  `curl` requests of 2.6 answered `403`, 70 parallel requests to `/mail/?_task=login` were answered
  with 503 beyond the limits (29 and 45 of 70), `diag --strict --url --cafile` ended with `Result:
  OK`, and the browser checks passed (10 of 10). A review of the change found that its first form
  decided by the file alone: on a server with the zones in another file – the 1.0.2 and 1.0.3
  checklist named none – it declared them a second time, which `nginx -t` refuses (reproduced with
  nginx 1.26.3). The block now asks `nginx -T` whether nginx already reads the zones and leaves a
  template with `limit_req` / `limit_conn` lines alone; on both servers, with the zones in the
  `http {}` block of `nginx.conf`, it created no file, added the template lines and `nginx -t`
  passed, and the runs above were repeated with the same results. `InstallationDocsTest` runs the
  block on the two templates exactly as iRedMail installs them, in these three cases.

### Release verification of 1.0.7 (2026-11-26)

The tree of the release commit in a disposable development container (Debian 13.8, PHP 8.4.28,
Roundcube 1.7.0 and 1.7.7 release tarballs with the SHA-256 of CI, MariaDB 11.8.9, PostgreSQL 17.12,
Dovecot 2.4.1, Chromium 156, PHPUnit 13.4.8, PHPStan 2.3.7, PHP-CS-Fixer 3.95), as the unprivileged
user `tester` unless noted:

| Run | Result |
|---|---|
| PHPUnit, SQLite + MariaDB + PostgreSQL, `--fail-on-skipped --exclude-group root`: 1.7.7 and 1.7.0 | **3015 tests, OK**, each (1.0.6: 3013; new: the two `InstallationDocsTest` methods of the README 2.2 / curl correction and of the limits block, each failing on the documentation before it) |
| PHPUnit `--group root-aware` and `--group root` as root, from root-owned copies over a root-owned 1.7.7 tree | **369 tests, OK**; **11 tests, OK** |
| PHPStan level 8, PHP-CS-Fixer, `php -l` (108 files), `composer validate --strict`, `tests/release/check-archive.php HEAD` | clean; 58 entries, all on the allow-list |
| `tests/e2e/test_e2e.py` and `tests/ui/test_ui.py`, Roundcube 1.7.7 and 1.7.0 × MariaDB and PostgreSQL | E2E **37 passed, 0 failed, 0 skipped**, UI **9/9**, each of the four |
| the limits block of README 2.6 on both iRedMail 1.8.11 servers of the run above, and as GitHub renders it (`gh api markdown`: the copied text is the tested block) | as the README describes it (above) |

The pins of docs/RELEASING.md step 3 were checked on the day: no newer Roundcube 1.7.x or iRedMail
release, the scheduled CI run `active`, every image digest of `.github/workflows/ci.yml` still the
current one of its tag, Composer 2.11.2 kept (2.11.3 is younger than 14 days), gitleaks 8.30.1 the
newest release.

### CI and post-release verification of 1.0.7 (2026-11-26)

CI run of the release commit `56a5087`: <https://github.com/takenek/galbridge/actions/runs/45792771015>
(21 jobs green, the release checks wait for the tag); CI run of the tag `v1.0.7`, including the
release checks: <https://github.com/takenek/galbridge/actions/runs/45794084661> (22 jobs green).
Before the tag, the PHPUnit suite ran once more in the development container from the dependency
sets of the CI run of the release commit (`composer-lock-tests-php8.4-newest` and `-1.7.0`,
`composer install`, `composer audit`: no advisory): **3015 tests, OK** against 1.7.7 and against
1.7.0. The tag was verified with `tests/release/verify-tag.sh` and `check-archive.php` before the
push; the GitHub release is immutable and GitHub reports the tag signature as verified. Packagist
listed `v1.0.7` at `56a5087` (source and dist) within a minute, and its archive (GitHub's zipball of
the commit) holds exactly the files of `git archive v1.0.7`; github.com and packagist.org show the
badges `CI passing`, `packagist v1.0.7` and `license GPL-3.0-or-later` (HTTP 200, `image/svg+xml`,
and in headless Chromium every badge image loaded). From the published sources, on the two iRedMail
1.8.11 servers of the 1.0.7 run above:

| Server | What | Result |
|---|---|---|
| MariaDB edition with 1.0.6 from the tag | README 5, release archive | tag and both fingerprints verified, the `diff -r` check `identical`, nothing group/other-writable; `diag`, `--user` and `--strict --url --cafile`: `[ OK ] GAL Bridge 1.0.7`, `Result: OK`, no WARN, the corrected INFO line `mailboxes without a name`; browser 10/10 (global book), no canary |
| PostgreSQL edition with 1.0.6 from Packagist | README 5, Composer in an interactive root shell | `Upgrading takenek/galbridge (v1.0.6 => v1.0.7)`, no `vendor/takenek/galbridge`, no backup copy; `composer show` names `56a5087`, the commit of the verified tag; the files identical to `git archive v1.0.7`; `diag` as above; browser 10/10, no canary |

## 7. Not available / not tested

* Database servers outside the matrix of section 6: MariaDB 10.2 – 10.4, MySQL 8.0 before 8.0.19
  (no `information_schema.APPLICABLE_ROLES`: `diag` WARNs that it could not check the role
  memberships), PostgreSQL 12 and earlier, and MySQL 8.4 or later with `mysql_native_password`
  loaded by the administrator (the verifier form is tested on MariaDB and MySQL 8.0, the clear-text
  form on MySQL 8.4, 9.7 and 26.10). The CI job `databases` ran green on GitHub with the 1.0.2 commits (section 6, "CI runs of 1.0.2").
* PostgreSQL before 15 (CREATE on schema `public` for `PUBLIC`): the grant set's `REVOKE` ran
  against the real default of PostgreSQL 13 and 14 (database matrix); the reaction of `diag` and of
  the plugin to the privilege is tested by granting it, on every version; `pg_stat_statements` is
  not loaded in the test clusters (its `track_utility` setting is checked as the placeholder it is
  then). The read timeout and the lost-connection memo are
  tested against a loopback MySQL-protocol peer (`tests/Support/FakeMysqlServer.php`), which is
  the client side that matters (mysqlnd); a `pdo_mysql` built on libmysqlclient is not tested. That MySQL rewrites passwords in its logs is its documented behaviour, not verified here.
* The scanner of the CI job `security` (gitleaks 8.30.1) is not run locally (no download): the
  Debian package is 8.16, which does not read the `[[allowlists]]` tables of `.gitleaks.toml` nor the
  rule-level allowlists of the 8.30.1 rule file. Section 6 lists what was run instead – the engine of
  8.16 with the 222 rules of 8.30.1 and the rule `galbridge-dsn-password`, without any rule-level
  allowlist (so it reports more than 8.30.1 would) – and its result. The controls of the job
  (planted key, DSN with a password, new fixture file) prove the configuration on GitHub with every
  run.
* The CI steps that need GitHub: the job `release` runs with the first tag (its scripts,
  `tests/release/verify-tag.sh` and `check-archive.php`, are tested by `Remediation2ReleaseTest`; the
  GitHub API part - the tag object rebuilt from `verification.payload` and `signature` when the
  checkout stored a reference - and the `jq` filter were linted, not run); the job `e2e` (Debian 13
  container), the root step of the PHPUnit job and the artifacts of the resolved sets were checked
  with ShellCheck and by `Remediation2CiTest`; they ran green on GitHub with the 1.0.2 commits, and
  so did the pins they use (`actions/upload-artifact`, the `debian:13-slim` digest, Composer 2.10.3
  with its SHA-256, the Roundcube tarball SHA-256s) - section 6, "CI runs of 1.0.2".
* A `pdo_mysql` that is not built on `mysqlnd`: host-name verification with `verify_server_cert=1`
  was verified for `mysqlnd` only (`diag` warns for other builds).
* iRedMail on platforms other than Debian 13 (the RHEL family, Ubuntu, FreeBSD, OpenBSD): the
  real installation of section 6 ran on Debian 13 only (the nginx templates and the `static.php`
  deny rule are the same on Linux); the OpenLDAP edition is out of scope (README section 1).
* PostgreSQL clusters with `C`/`POSIX` ctype (non-ASCII case folding; diag warns).
* pgaudit and auto_explain are not installed in the test cluster: `diag`'s warning is tested with
  `pgaudit.log` and `auto_explain.log_min_duration` set for a throw-away role as the placeholders they
  are without the extensions (what `current_setting(..., true)` returns is the same).
* The order of autocomplete suggestions on Roundcube 1.7.0 – 1.7.5 depends on the C library locale
  of the user's language: the suite checks that those releases sort with `asort(...,
  SORT_LOCALE_STRING)` and 1.7.6+ with `rcube_utils::asort()`, and that `diag` describes the release
  it runs with; the effect of a locale (`(` ignored at the first level in `pl_PL.utf8`, first in the C
  locale) was checked by hand with PHP 8.4 on glibc 2.41, not by a test.
* `per_user_logging` with a user log directory is tested with a directory of the test; Roundcube's
  `write_log` hook (a plugin that aborts a line) is not.
