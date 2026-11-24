# GAL Bridge – Testing

All numbers below are from the verification run of 2026-11-24 for release 1.0.1 (branch `main`,
after the remediation of the security audit of 1.0.0).

## 1. Test environment (actually used)

| Component | Versions |
|---|---|
| Roundcube | the official release tarballs `roundcubemail-<version>-complete` of **every 1.7 release, 1.7.0 – 1.7.7** (for 1.7.7: GPG signature of "Roundcube Developers" verified; SHA-256 `cc9358a2…dd3fb`, identical to iRedMail 1.8.11's `pkgs.sha256`); CI: 1.7.0 and the newest 1.7.x from Packagist |
| iRedMail schema | upstream `samples/iredmail/iredmail.mysql` and `iredmail.pgsql` of iRedMail **1.8.11** (vendored as `tests/fixtures/vmail/schema.*.sql`), plus a SQLite mirror |
| PHP | 8.1.34, 8.4.28, 8.5.13 (Debian 13 + packages.sury.org); for the local run of the CI steps also 8.2.36 and 8.3.37; CI: 8.1 – 8.5 |
| Databases | MariaDB 11.8.9, PostgreSQL 17.12, SQLite 3 (fixture mirror) |
| IMAP | Dovecot 2.4.1 (test users from the fixture, `tests/e2e/dovecot.sh`) |
| Browser | Chromium 156 (headless) + Selenium 4.31 |
| Static analysis | PHPStan 2.3 **level 8** against the 1.7.7 tree, PHP-CS-Fixer 3.95, ShellCheck (test scripts), the tokenizer checks of `SourceGuardTest`, gitleaks (CI: 8.30.1; locally the Debian package 8.16 and the rule file of 8.30.1, see section 7) |
| Test runners | PHPUnit 10.5.67 (PHP 8.1), 11.5.58 (PHP 8.2 / 8.3), 13.4.8 (PHP 8.4 / 8.5) – each PHP version resolves its own dependency set |

The database role used by every database test is created from the plugin's own `SQL/grants.*.sql`
(`tests/fixtures/vmail/load.sh`, which also enables the commented-out personal-data grant so that
every field is exercised), so the suites also prove that the documented grants are sufficient – and
that they deny `mailbox.password`, `recovery_email`, `SELECT *`, `UPDATE` and the whole
`forwardings` table (checked on both engines). The fixtures are TEST-ONLY data; `tests/fixtures/vmail/README.txt` describes their origin
(upstream iRedMail 1.8.11 schema), the canary values and the throw-away role.

## 2. Suites and how to run them

| Suite | Command | What it covers |
|---|---|---|
| Fixtures | `tests/fixtures/vmail/load.sh mysql` · `PSQL_ADMIN="runuser -u postgres -- psql" tests/fixtures/vmail/load.sh pgsql` | throw-away vmail databases + the read-only role; prints the DSNs |
| PHPUnit (unit + integration) | `GALBRIDGE_RC=/opt/rcrun/1.7.7 GALBRIDGE_TEST_MYSQL_DSN=… GALBRIDGE_TEST_PGSQL_DSN=… vendor/bin/phpunit` (any 1.7.x tree: `/opt/rcrun/1.7.0` for the oldest release) | see section 3; every database test runs on SQLite, MariaDB and PostgreSQL. Tests that need rows the seed does not have (a mailbox disabled during its session, more entries than a limit, names that decode into an address) change the throw-away fixture through `tests/Support/Fixture.php` – the administrator access of `load.sh` – and undo it; without that access they are skipped on the two servers |
| CLI (`diag`, `grants`) | part of the PHPUnit run: `vendor/bin/phpunit tests/Integration/CliToolTest.php` (same environment; the MariaDB/PostgreSQL cases use the administrator access of `load.sh`: `MYSQL_ADMIN` / `PSQL_ADMIN` or their defaults) | `grants`: argument validation (also the refused role names and the modes), the output (a verifier of a fresh password in the statements, the password in the DSN line only, renames inside SQL statements only, DSN host independent of the MySQL account host, `REQUIRE SSL` for a non-local account host) and the output **applied** to the throw-away servers as a new role – the printed DSN works, only the granted columns and the view are readable, `SELECT *`, `UPDATE` and the `forwardings` table are denied, the server stores exactly the printed verifier, a second default output stops at the existing role and changes nothing, `--reset-password` replaces the password, `--grants-only` keeps it and moves a role with the grants of 1.0.0 to the view, the clear-text variant refuses to run on MariaDB; `diag` run like an administrator runs it (`bin/galbridge.sh` with `ROUNDCUBE_CONFIG_DIR`) against the fixture role and temporary roles that are too broad (write privileges – also through a database role –, the `forwardings` table) or too narrow (missing columns, no view), options of the wrong type and environment overrides, malformed deny-lists, unsafe configuration files, the `'*'` wildcard, uncovered IMAP hosts and ports, `include_host_config`, `--url` against a local web server, an invalid `--user` and an unreachable server, with the exit codes; the script's refusal to run as root. Temporary roles exist only on the test databases and are dropped in `tearDown` |
| E2E | `python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql\|pgsql` | 37 cases against a real Roundcube over HTTP: Dovecot login, every address-book path, attacks, live configuration changes, a leak observer over every response, log checks; cases 31 – 35 are the audit regressions seen through Roundcube itself (hidden aliases, fail-closed configuration and IMAP port binding, complete-or-refused exports, revocation through Roundcube's own session handler, names that decode into an address). The cases that change the fixture use `tests/e2e/fixture_admin.py` (administrator access of `load.sh`) and are skipped without it |
| E2E matrix | `tests/e2e/matrix.sh [rc_base_dir]` | the E2E suite, run sequentially (the Dovecot test mailboxes and the vmail databases are shared) for Roundcube **1.7.0** × PHP 8.1 / 8.4 and Roundcube **1.7.7** × PHP 8.1 / 8.4 / 8.5, each × MariaDB / PostgreSQL – ten combinations; logs and `summary.txt` in `tests/e2e/run/matrix-<timestamp>/`. A missing Roundcube tree or PHP binary counts as a failed combination (never a silent skip); the exit code is non-zero unless every combination is green |
| Browser UI | `python3 tests/ui/test_ui.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql\|pgsql` | 9 cases in headless Chromium (address book, contact view, compose autocomplete, tenant isolation, a directory name with a character reference or an encoded word in the recipient widget of the Elastic skin, no JS errors) |
| PHPStan | `php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist` | level 8, `lib/`, `galbridge.php`, `bin/galbridge.sh` |
| Code style | `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php` | |
| Secret scan | CI job `security` (`gitleaks git` and `gitleaks dir` with `.gitleaks.toml`, after a control that a planted key is reported) | the whole history and the tree; the allow-list names the TEST-ONLY fixture and harness files and three throw-away values |
| Dovecot (TEST ONLY) | `tests/e2e/dovecot.sh install\|status\|remove` | installs/removes exactly one config file and the test passwd file |

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

The unchanged 1.0.0 code was run first as a reference: 1799 tests, OK, on Roundcube 1.7.0 – 1.7.7
(PHP 8.4), 1.7.0 and 1.7.7 (PHP 8.1) and 1.7.7 (PHP 8.5) – the plugin already worked on the whole
branch; 1.0.1 declares and tests it.

### Unit suite (no Roundcube needed)

| Class | Tests | Focus |
|---|---|---|
| IdCodecTest | 28 methods | every escape and byte value, canonical-only decoding, Roundcube separators, base64url rejection, invalid/overlong UTF-8, controls, group IDs with book tags, a seeded "never throws" check |
| NormalizerTest / NormalizerWithoutIntlTest | 36 / 1 | domain and address normalisation incl. IDN, invalid UTF-8 fails (never "repaired"), C1/bidi/zero-width rejection, list semantics (`*`, strict deny-lists, numeric strings stay strings), display text incl. the notations that are decoded later (`&`, `=?`, `?=`) and what Roundcube deletes from a composed name (empty brackets, also across the brackets of a name template; bounded for values of any length) |
| SourceGuardTest | 5 methods (one case per production file) | static security checks with the PHP tokenizer: no process execution, dynamic code, deserialisation, file writes, request superglobals or weak randomness in production code; SQL execution, network access and `$_SESSION` only in the named files; the data files (`localization/*.inc`, `config.inc.php.dist`) hold assignments of literals only; a self-test plants every forbidden construct |
| SqlTest | 30 | parenthesisation, `IN ()` = FALSE, `NOT IN ()` = TRUE, placeholder counting, unsafe expressions (quotes, `;`, `--`, `/*`, `#`), LIKE escaping, ILIKE on PostgreSQL |
| SearchSpecTest | 17 | round trip, tampered / foreign-book / foreign-fingerprint / wrong-version specs rejected |
| LogTest | 27 | log injection (CR/LF, C1, bidi, CSI), DSN password redaction incl. `@` and `/` in passwords, truncation, once-per-request codes |

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
| CliToolTest | `bin/galbridge.sh grants` and `diag` (section 2): generated grants applied to MariaDB and PostgreSQL in all three forms, strict options, `diag` against correct, too broad and too narrow roles and broken configurations, a release directory under another name, the web check with its control request, the root refusal (also without the posix extension) and what `--allow-root` checks. The tests of the root refusal and of `--allow-root` need root and are skipped elsewhere – CI runs unprivileged, so they run in a local run as root only |
| AuditRegressionTest | one test per finding of the security audit of 1.0.0 that needs the whole stack, on three engines: hidden and out-of-scope aliases, names that decode into an address, complete-or-refused sets, revocation of a cached entitlement, expiry of list kinds, bounded alias sets, IDN spellings in allow- and deny-lists |
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
all were corrected before the release:

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

### Review of the remediation before the release

Before the release the whole change was reviewed once more – seven independent readers, one per
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

## 7. Not available / not tested

* MySQL 8 (iRedMail's MySQL edition installs MariaDB): the MySQL-specific statement timeout
  (`max_execution_time`), the account limit syntax on MySQL and `grants --clear-text-password`
  (MySQL 8.4+; its refusal on MariaDB is tested) are implemented from the MySQL documentation but
  untested. That MySQL rewrites passwords in its logs is its documented behaviour, not verified here.
* The scanner of the CI job `security` (gitleaks 8.30.1) was not run before the push of 1.0.1:
  the Debian package is 8.16, which knows only the older `[allowlist]` table and does not read the
  `[[allowlists]]` tables of `.gitleaks.toml`. What was checked instead is listed in section 3 –
  8.16 with the same exceptions in its own syntax, and the rules of 8.30.1 with the committed
  configuration evaluated by a script. Neither is the scanner the job runs: its result on GitHub is
  the one that counts. The job `release` (tag checks) runs with the first tag; its shell steps were
  syntax-checked and linted.
* A `pdo_mysql` that is not built on `mysqlnd`: host-name verification with `verify_server_cert=1`
  was verified for `mysqlnd` only (`diag` warns for other builds).
* A live iRedMail nginx configuration (the `static.php` deny rule in README 2.6 comes from the
  iRedMail 1.8.11 templates; `diag --url` and the `curl` check of the README verify an
  installation).
* PostgreSQL clusters with `C`/`POSIX` ctype (non-ASCII case folding; diag warns).
