# GAL Bridge – Testing

All numbers below are from the verification run of 2026-11-24 (branch `main`).

## 1. Test environment (actually used)

| Component | Versions |
|---|---|
| Roundcube | **1.7.7** official release tarball `roundcubemail-1.7.7-complete` (GPG signature of "Roundcube Developers" verified; SHA-256 `cc9358a2…dd3fb`, identical to iRedMail 1.8.11's `pkgs.sha256`) |
| iRedMail schema | upstream `samples/iredmail/iredmail.mysql` and `iredmail.pgsql` of iRedMail **1.8.11** (vendored as `tests/fixtures/vmail/schema.*.sql`), plus a SQLite mirror |
| PHP | 8.1.34, 8.4.28, 8.5.13 (Debian 13 + packages.sury.org); CI: 8.1 – 8.5 |
| Databases | MariaDB 11.8.9, PostgreSQL 17.12, SQLite 3 (fixture mirror) |
| IMAP | Dovecot 2.4.1 (test users from the fixture, `tests/e2e/dovecot.sh`) |
| Browser | Chromium 156 (headless) + Selenium 4.31 |
| Static analysis | PHPStan 2.3 **level 8** against the 1.7.7 tree, PHP-CS-Fixer 3.95 |
| Test runners | PHPUnit 10.5 (PHP 8.1), 13.4 (PHP 8.4 / 8.5) – each PHP version resolves its own dependency set |

The database role used by every database test is created from the plugin's own `SQL/grants.*.sql`
(`tests/fixtures/vmail/load.sh`), so the suites also prove that the documented grants are sufficient
– and that they deny `mailbox.password`, `recovery_email`, `SELECT *` and `UPDATE` (checked on both
engines).

## 2. Suites and how to run them

| Suite | Command | What it covers |
|---|---|---|
| Fixtures | `tests/fixtures/vmail/load.sh mysql` · `PSQL_ADMIN="runuser -u postgres -- psql" tests/fixtures/vmail/load.sh pgsql` | throw-away vmail databases + the read-only role; prints the DSNs |
| PHPUnit (unit + integration) | `GALBRIDGE_RC=/opt/rcrun/1.7.7 GALBRIDGE_TEST_MYSQL_DSN=… GALBRIDGE_TEST_PGSQL_DSN=… vendor/bin/phpunit` | see section 3; every database test runs on SQLite, MariaDB and PostgreSQL |
| E2E | `python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql\|pgsql` | 32 cases against a real Roundcube over HTTP: Dovecot login, every address-book path, attacks, live configuration changes, a leak observer over every response, log checks |
| E2E matrix | `tests/e2e/matrix.sh` | the E2E suite for PHP × database combinations |
| Browser UI | `python3 tests/ui/test_ui.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql\|pgsql` | 8 cases in headless Chromium (address book, contact view, compose autocomplete, tenant isolation, no JS errors) |
| PHPStan | `php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist` | level 8, `lib/`, `galbridge.php`, `bin/galbridge.sh` |
| Code style | `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php` | |
| Dovecot (TEST ONLY) | `tests/e2e/dovecot.sh install\|status\|remove` | installs/removes exactly one config file and the test passwd file |

## 3. Results of the final run

| Run | Result |
|---|---|
| PHPUnit, PHP 8.4, all engines | **1555 tests, 24 713 assertions, OK** (Unit 723 / Integration 832) |
| PHPUnit, PHP 8.1 (PHPUnit 10.5) | 1555 tests, OK |
| PHPUnit, PHP 8.5 (PHPUnit 13.4) | 1555 tests, OK |
| PHPUnit, random order (seeds 1, 42, 777) | OK each time |
| E2E MariaDB × PHP 8.1 / 8.4 / 8.5 | 32/32 each |
| E2E PostgreSQL × PHP 8.1 / 8.4 / 8.5 | 32/32 each |
| Browser UI, MariaDB and PostgreSQL | 8/8 each |
| PHPStan level 8 | no errors |
| PHP-CS-Fixer | 0 files to fix |

### Unit suite (no Roundcube needed)

| Class | Tests | Focus |
|---|---|---|
| IdCodecTest | 28 methods | every escape and byte value, canonical-only decoding, Roundcube separators, base64url rejection, invalid/overlong UTF-8, controls, group IDs with book tags, a seeded "never throws" check |
| NormalizerTest / NormalizerWithoutIntlTest | 26 / 1 | domain and address normalisation incl. IDN, invalid UTF-8 fails (never "repaired"), C1/bidi/zero-width rejection, list semantics (`*`, strict deny-lists, numeric strings stay strings), display text |
| SqlTest | 30 | parenthesisation, `IN ()` = FALSE, `NOT IN ()` = TRUE, placeholder counting, unsafe expressions (quotes, `;`, `--`, `/*`, `#`), LIKE escaping, ILIKE on PostgreSQL |
| SearchSpecTest | 17 | round trip, tampered / foreign-book / foreign-fingerprint / wrong-version specs rejected |
| LogTest | 27 | log injection (CR/LF, C1, bidi, CSI), DSN password redaction incl. `@` and `/` in passwords, truncation, once-per-request codes |

### Integration suite (Roundcube 1.7.7 library, three engines)

| Class | Focus |
|---|---|
| ContractTest | the `rcube_addressbook` contract: paging, counts, sort fallbacks, all search modes and forms, search-set replay, ID search, `get_record` (never stale), groups, search-only books, DeniedBook, record shape and coltypes |
| CorePathsTest | replays `program/actions/**` call sequences with core's own code where possible (`get_cids()`, vCard export, copy via `rcube_contacts::convert_save_data()`), incl. compose-to-group with `max_group_members` (complete or refused) |
| OldPluginRegressionTest | one test per verified defect of the old plugin (13), plus a docs-vs-code check of documented defaults |
| IsolationTest | tenant isolation on every path, invisible inactive/backup-MX/expired entries, Viewer cache binding, outage memo |
| AccessPolicyTest | the iRedAPD policy matrix for six viewers under `strict`, `public_only`, `all`; persons-only groups |
| EntitlementsTest | viewer lists, deny-lists failing closed, organizations (incl. overlaps), global book data/viewer lists, wildcard opt-in, `include_home` |
| ConfigTest | configuration guard (user preferences named `galbridge_*` ignored), clamps, `validate()` |
| DsnPolicyTest | drivers, refused roles (incl. Roundcube's and the password plugin's), `service`, host+socket ambiguity, verified TLS for remote hosts, connect timeout injection and validation, `config_invalid` / `column_missing` logging, role-too-broad detection with a real over-privileged role |
| PluginHooksTest | the real `galbridge` plugin class: `addressbooks_list` entries (escaped names, readonly), `addressbook_get` always an instance for our IDs, autocomplete wiring |
| LeakageTest | canaries (password hashes, recovery addresses, private forwarding targets, `allow_nets`) never in any record, coltypes, search set or log line |

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

## 5. Verification of the old plugin

Before GAL Bridge was written, the old plugin (`sql_global_addressbooks` 2.5.0) was analysed and its
key defects reproduced with a harness on the real Roundcube 1.6.9 / 1.6.22 / 1.7.7 classes (PHP 8.0 –
8.5, SQLite) and on PostgreSQL 17: the fatal error on 1.7, the cross-tenant `_gid` / `_source` access,
compose-to-selected putting the whole book into To:, and an SQL injection on PostgreSQL through
`_headers` with UNION reads of other tables (docs/MIGRATION.md section 0).

## 6. Not available / not tested

* MySQL 8 (iRedMail's MySQL edition installs MariaDB): the MySQL-specific statement timeout
  (`max_execution_time`) is implemented but untested.
* A live iRedMail nginx configuration (the `static.php` deny rule in README 2.6 comes from the
  iRedMail 1.8.11 templates; README gives a `curl` check).
* PostgreSQL clusters with `C`/`POSIX` ctype (non-ASCII case folding; diag warns).
