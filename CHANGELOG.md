# Changelog – GAL Bridge (`galbridge`)

All notable changes are documented here. The format follows *Keep a Changelog*; versions follow
semantic versioning.

## [Unreleased]

## [1.0.1] – 2026-11-24

Remediation of the security audit of 1.0.0 (full source review with Anthropic's Mythos 5.2; it
found no vulnerability of high or medium risk). The identifiers U-01 … U-11 (low risk) and I-01 …
I-17 (informational) are those of the audit report; every one of them is addressed below.

**Upgrading needs a step at the database** (README section 5): apply the output of
`bin/galbridge.sh grants --grants-only` to the existing role – with `--connection-limit=N` when
the limit had been sized, it is set again (default 25) – and, on MariaDB/MySQL, run once the
`REVOKE ... ON vmail.forwardings` statement that the output ends with as a comment (PostgreSQL
revokes the table access itself). 1.0.1 reads the forwardings through the view
`galbridge_forwardings` and refuses a role that can read the `forwardings` table; until both are
done the plugin offers no book (it fails closed, mail is not affected).
Configuration values of the wrong type, which 1.0.0 silently cast, now disable every book – run
`diag` after the upgrade.

### Security
- U-01: a per-user alias on a hidden list (`galbridge_hidden_addresses`,
  `galbridge_hidden_localparts`) was not shown but could still be searched for – whole or by a
  fragment – and returned its owner. Showing and matching of per-user aliases now use one
  predicate, which also requires the alias row to belong to an active, non-backup-MX, unexpired
  domain inside the book's scope: an alias of another domain is no longer shown with a visible
  person, and the viewer's own aliases of an inactive domain no longer count for the list policies.
- U-02: `galbridge_visibility_column` given as something that is not a string (e.g. an array) was
  read as "no column" and switched the per-person filter off without a sign. A value of the wrong
  type – `false` included; `''` is the only "no column" – is now a configuration error that
  disables every book. `diag` shows the column in use.
- U-03: switches written as strings (`'false'`, `'no'`, `'off'`) were cast to *true*, also through
  `ROUNDCUBE_GALBRIDGE_*` environment variables (Roundcube itself turns the text `false` into
  true). A switch must now be a boolean or the integer 1 / 0 (`searchonly` options: also `'auto'`);
  environment overrides are parsed by the plugin (`1`, `0`, `true`, `false`); anything else is
  reported and disables every book. A `galbridge_db_refused_users` that is not a list keeps the
  built-in list. `diag` prints the effective value of every switch and the overrides in effect.
- U-04: a display name could carry an HTML character reference (`&#64;`) or an RFC 2047 encoded
  word; Roundcube's recipient widget (Elastic skin) and the mail clients of recipients decode them,
  so a directory entry could be presented as another address. `&` is now replaced by the full-width
  ampersand and `=?` is split before the "looks like an address" check; the foreign-domain marker
  of the global book moved in front of the name – `(domain) Name` – so that a shortened name cannot
  hide it.
- U-05: a whole-book export and the member set of a group were sized with a count that is bounded
  by `galbridge_max_count`; with `galbridge_max_count` ≤ the limit of the operation a larger set was
  delivered cut, without a message. Full sets are now read by one statement up to the limit of the
  operation plus one row and are complete or refused; a set of which an entry could not be read is
  refused (`incomplete`); a group ID is honoured only for a group the book shows.
- U-06: the role could read every row of `vmail.forwardings`, including the private forwarding
  targets of all mailboxes, although the threat model said it could not. The grant set now creates
  the view `galbridge_forwardings` (alias, alias-member and list rows only; MariaDB/MySQL
  `SQL SECURITY DEFINER`, PostgreSQL `security_barrier`) and grants the view instead of the table;
  the plugin reads the view only and refuses a role that can read the table (`role_too_broad`).
- U-07: PostgreSQL `sslmode=verify-ca`, which does not check the host name of the server
  certificate, counted as verified TLS. A remote PostgreSQL now needs `sslmode=verify-full`;
  `verify-ca` is accepted only with the new option `galbridge_db_allow_verify_ca` (default off; log
  and `diag` warn).
- U-08: the MariaDB/MySQL grant set left the account without a connection limit (a commented-out
  line) and `--connection-limit` had no effect there. Both engines now get the limit (default 25,
  `--connection-limit`) and, where the server supports it, a statement time for the account;
  `diag` reports the limit on both engines and warns when the account is unlimited or when the
  server refused a session limit.
- U-09: `grants` printed statements that changed the password, settings and limits of whatever
  role was named, existing or not. The default output now only creates the role and stops, changing
  nothing, when it exists; `--reset-password` and `--grants-only` are the explicit forms for an
  existing role; names of iRedMail's, Roundcube's and the database server's own roles are refused.
- U-10: the generated statements contained the new password in clear text, which a database server
  keeps in its logs (PostgreSQL with `log_statement`; MariaDB verbatim in its general, binary and
  slow query logs – verified on MariaDB 11.8). The statements now carry a verifier of the password
  (PostgreSQL SCRAM-SHA-256, MariaDB/MySQL `mysql_native_password`); the clear text is in the DSN
  line of the output only; the PostgreSQL grant set switches statement logging off for its own
  transaction. MySQL 8.4+ (no `mysql_native_password`): `--clear-text-password`, whose output
  refuses to run on MariaDB. A password set with the statements of 1.0.0 may be in existing
  database logs: see SECURITY.md ("Logs") for the operator's decision.
- U-11: the IMAP binding compared host names only, and with Roundcube's `include_host_config` the
  bound host depended on the request's `Host` header. The binding now includes the port
  (`galbridge_imap_hosts` accepts `host:port`; the derived binding takes host and port from
  `imap_host`; the session's `storage_port` is compared and the cache is bound to it), and
  `include_host_config` requires an explicit `galbridge_imap_hosts`. `diag` warns about several
  servers and about `include_host_config`.
- I-01: the DSN is validated in the form the driver receives it – explicit host or unix socket,
  numeric port, allow-listed parameters without repetition – and PostgreSQL client-library
  environment variables that would redirect the connection are refused.
- I-02: the configuration fingerprint stored in the session is now an HMAC keyed with Roundcube's
  `des_key` (it no longer allows offline tests of guesses of the database password) and covers
  `include_host_config`.
- I-03: `diag` – the password-column check passes only when the server refuses the statement for
  lack of privilege; on MariaDB/MySQL write privileges are also asked from the server (privileges
  through a database role are found); a configuration file owned by the PHP user is a WARN; the
  `--user` argument is validated before anything of it is printed.
- I-04: `diag --url=<webmail URL>` asks the web server whether it serves files of the plugin
  directory; `diag` reports a git clone and files that are not part of a release; the documented
  installation is the archive of a verified tag.
- I-05: `bin/galbridge.sh` refuses to run as root (`--allow-root` only for root-owned code and
  configuration); the documentation runs `grants` as the PHP user.
- I-06: the test scripts refuse anything but a `*test*` database and test role names and a machine
  that looks like a mail server (`tests/fixtures/vmail/load.sh`), never touch a Dovecot they did
  not configure (`tests/e2e/dovecot.sh`), stop only their own web server (`tests/e2e/setup.sh`) and
  use a private temporary directory.
- I-07: PHPUnit lower bounds exclude the releases with advisories (`^10.5.62 || ^11.5.50 ||
  ^13.4.4`); CI scans history and tree for secrets (gitleaks, verified by checksum), pins the
  service images by digest, records the resolved dependency set of every run and checks release
  tags (annotated, signature verified, versions consistent, archive content); static security
  checks of the production code run with the test suite (`SourceGuardTest`).
- I-08: the PHP version policy is documented (SECURITY.md "Supported versions"); `diag` keeps
  warning for a PHP branch past its upstream security support.
- I-09: **every Roundcube 1.7.x release is supported** (`min-version` 1.7.0), tested against the
  oldest and the newest release and daily in CI against the newest 1.7.x; the documentation tells
  administrators to install security releases of Roundcube without waiting for the plugin.
- I-10: a cached entitlement is confirmed against vmail by every request that reads directory
  data: a disabled, expired, deleted or moved mailbox loses the directory with its next request
  instead of after `galbridge_scope_ttl`. The mailbox row of a login must name the login's own
  domain.
- I-11: the PostgreSQL grant set switches statement logging off for the role (its statements
  contain the search terms); `diag` reports whether the server log can record them (both engines);
  SECURITY.md has a section on logs.
- I-12: with `galbridge_hide_expired`, alias accounts and lists whose `expired` column the role
  cannot read are left out instead of being listed unfiltered (as the `diag` message already said).
- I-13: bounded alias sets – at most 200 own aliases of a viewer count for the list policies (more:
  the login alone), at most 20 aliases are shown per person, read with bounded statements.
- I-14: organization labels are handled as strings throughout (a label that only looks like a
  number after cleaning no longer raises a type error); list predicates are well-formed for an
  empty address set, and a session entry without a usable viewer is resolved again.
- I-15: the export of a search result with more matches than the list cap, and any export while the
  directory fails, is refused (`204 No Content`) instead of downloading a file that silently lacks
  entries.
- I-16: wording addressed to future audits was removed from SECURITY.md, docs/ARCHITECTURE.md and
  the CI workflow; SECURITY.md now has a register of accepted risks and design decisions with
  rationale, remaining risk, owner and review date.
- I-17: working documents of security audits are git-ignored and stay outside the repository.
- Hardening found while fixing the above: option values and command line arguments are matched
  with anchors that do not accept a trailing line break; PostgreSQL statements of the grant set run
  in one transaction that is rolled back on the first error.

### Fixed after the review of the remediation
The changes above were reviewed again before the release – an independent review of the diff and a
run of the CI steps as an unprivileged user with the Roundcube package of Packagist. What it found
was fixed before 1.0.1 was published:

- The split of `=?` (U-04) could be undone by Roundcube itself: with a `contactlist_name_template`
  other than `{name}` core deletes empty bracket pairs from a composed name, so a name written as
  `=()?UTF-8?B?...?=` became an encoded word again in autocomplete, group expansion and the contact
  lists. Display text now loses empty `()` / `[]` pairs first (until none is left), never starts
  with a closing or ends with an opening bracket, and `?=` is split like `=?`.
- An entry without any name was shown with the local part of its address as it is; an address may
  hold `&`, `#`, `=` and `?`, so the notations of U-04 could reach a recipient name that way. The
  fallback is now treated like every other name.
- Under a `contactlist_name_template` that shows another field of an entry before `{name}`, or has
  no `{name}`, an entry of another domain was shown with text of that domain in front of its
  `(domain)` marker, or without the marker. Such entries no longer carry those fields (department,
  telephone numbers) under such a template; `diag` names them (WARN). Templates that start with the
  name are not affected.
- A consequence of the marker standing first is now documented and shown by `diag`: Roundcube sorts
  autocomplete suggestions by name and pre-selects the first, so entries of other domains are
  suggested before unmarked names; `galbridge_global_autocomplete = false` keeps the global book
  out of autocomplete (README 3.3, SECURITY.md register).
- The contact view no longer showed the department group of a person whose department contains `&`
  (the group key is the raw value, the record carries display text).
- The user-preference guard returned the built-in default for an option that the administrator sets
  through the environment alone (`ROUNDCUBE_GALBRIDGE_*`, no line in a configuration file): for a
  user whose stored preferences carry a key of that name, hidden lists or the visibility column
  were not applied. The guard now reads such an option like every other request does.
- The configuration fingerprint did not change when a switch that is set through the environment
  changed between `true` and `false` (Roundcube converts both texts to true), so a cached viewer
  outlived the change by up to `galbridge_scope_ttl`. It now covers the switches as they are in
  effect.
- `galbridge_fields` that is not a list showed name and e-mail only without saying why; it is now
  reported (log, `diag`).
- `diag` printed 8 of the 18 switches although the documentation promised all of them: it now
  prints every switch under the name of its option, in three lines; a value that could not be read
  is marked `INVALID`.
- A Roundcube that reaches its IMAP server through a local socket (`imap_host = unix:///path`) had
  its books under 1.0.0 and lost them with the port binding of U-11, with no way to list the
  socket. The binding now accepts a local socket – derived from `imap_host` or listed in
  `galbridge_imap_hosts` – and matches it as a whole.
- `diag --url` asked the web server for the name of the plugin directory on disk; for an
  installation through a symlink it asked for the wrong path and reported "not served". It asks
  for `plugins/galbridge/` now, and first makes sure that the address answers like the Roundcube
  webmail (WARN otherwise: at a wrong address – iRedMail installs with and without `/mail/` –
  every path is "not served").
- The refusal to run as root depended on the posix extension of PHP, which is a package of its
  own on several platforms: without it the tool ran as root without a word. The user is now also
  read from `/proc`, and a process whose user cannot be determined is treated like root.
- `--allow-root` checked the owner of GAL Bridge's own files and of Roundcube's core library, but
  not of everything that is then loaded as root: the main file of every enabled plugin, the
  configuration of the password plugin, `vendor/`, the localization index. It now checks every
  directory and PHP file of the plugin and of Roundcube's `program/`, `plugins/`, `vendor/` and
  configuration directory (symbolic links followed), and the directories above them.
- `grants` silently used the default for an option written without `=` (`--role acme`) and ignored
  misspelled options – the statements were then for the role `galbridge`, with `--reset-password`
  a new password for a role nobody had named. Options are strict now; `diag` likewise.
- `grants --db=...` renamed the text `vmail.` also inside the account host it had just inserted
  (`--account-host=devmail.example.com`). Role, account host and database are inserted in one pass.
- The outputs of `--reset-password`, `--grants-only` and `--clear-text-password` kept comments of
  the template that describe other statements ("creates the account and stops when it exists",
  "no statement of this file contains the password"). Each output describes its own statements;
  the second line of `--grants-only` names the connection limit that will be set.
- PostgreSQL 12 / 13 and later can log a sample of the transactions or of the statements above a
  duration, whatever `log_statement` says. The grant set switches both off for the role and for
  its own transaction, and `diag` reads them.
- A database password that contains `?` (and a DSN without parameters) made the connect timeout
  part of the database name: the connection failed while `diag` showed the DSN as accepted.
- Only the first warning of the DSN policy reached the log of the web process (a second one, such
  as a libpq environment variable of that process, was dropped).
- Tests and CI: a test whose fixture change is skipped (no administrator access to the test
  servers) is no longer reported as a failure by PHPUnit 10 and 11; the private directory of the
  SQLite mirror is removed again; the suite runs without the posix extension; CI switches off
  Composer's own blocking of releases that have an advisory (the pinned 1.7.0 would stop
  installing, and an older release would silently replace a newest one – `composer audit` is what
  reports advisories); the secret scan ignores the three throw-away test values as whole values
  instead of stop words, which match as a part of any secret.
- Documentation: the archive installation of README 2.1 left the files group-writable when root
  extracts the archive (`tar.umask=0022`); the upgrade command sets the connection limit again and
  says so; docs/MIGRATION.md showed the old form of the marker; a cross-reference in
  `config.inc.php.dist` pointed to the wrong section; docs/TESTING.md described the verification of
  the secret scan more broadly than what had been run; the register of SECURITY.md states the
  maintainer's position without addressing its readers.
- PHPStan failed against the Roundcube package of Packagist, which reports `1.7-git` (all earlier
  runs used release archives): the version check of `diag` no longer depends on the tree that is
  analysed.

### Changed
- Global-book entries of other domains are displayed as `(domain) Name` (was `Name (domain)`);
  names containing `&` show the full-width ampersand; empty bracket pairs are removed from names.
- `bin/galbridge.sh grants`: new options `--reset-password`, `--grants-only` and
  `--clear-text-password`; `--connection-limit` applies to both engines; the second line of the
  output says what the statements do to an existing role.
- `bin/galbridge.sh diag`: new section "Installation", option `--url`, lines for every switch
  (`switches (books)`, `(entries)`, `(controls)`), environment overrides, the viewer cache, the
  connection limit (both engines), statement logging, the `forwardings` table, the suggestion order
  of marked entries and a name template that takes fields from them; Roundcube 1.7.x is OK, an
  older patch release a WARN.
- New option `galbridge_db_allow_verify_ca` (default `false`); `galbridge_imap_hosts` accepts
  `host:port` and `unix:///path`.
- New message `incomplete` (English and Polish).
- Tests: regression tests for every code finding on SQLite, MariaDB and PostgreSQL
  (`AuditRegressionTest`, `ConfigTest`, `DsnPolicyTest`, `CliToolTest`, `PluginHooksTest`,
  `NormalizerTest`), E2E cases 31–35 and a browser test of the recipient widget; the E2E matrix
  covers Roundcube 1.7.0 and 1.7.7.

## [1.0.0] – 2026-11-24

First release: a read-only Global Address List for Roundcube 1.7.7, read from the SQL `vmail`
database of iRedMail 1.8.x (MariaDB/MySQL and PostgreSQL editions). GAL Bridge was written from
scratch as the successor of *Roundcube-SQL-Global-Address-Books* (`sql_global_addressbooks` 2.5.0,
which does not load on Roundcube 1.7) and shares no code, configuration keys, source IDs or database
objects with it; see `docs/MIGRATION.md` for the option map, the removal of the old
`global_addressbook` VIEW and, on PostgreSQL, the rotation of the `vmail` password stored in that
view. The plugin keeps no tables of its own; it needs a dedicated read-only database role
(`bin/galbridge.sh grants`, README section 2.2).

### Added
- Domain book `galbridge_domain` (on by default): the people, alias accounts and mlmmj lists of the
  user's home domain – `mailbox.domain` of the login's vmail row – or of every domain of its
  organization (`galbridge_organizations`, disjoint sets); viewer allow- and deny-lists
  (`galbridge_domain_viewer_domains`, `..._exclude`); optional name, search-only mode, autocomplete
  and export switches.
- Global book `galbridge_global` (off by default): explicit viewer and data domain lists with
  deny-lists (`galbridge_global_viewer_domains`, `galbridge_global_data_domains` and their
  `_exclude` lists); `'*'` data domains only together with `galbridge_global_allow_wildcard`; the
  viewer's own domain-book scope is subtracted unless `galbridge_global_include_home`. Defaults:
  search-only, prefix search, at most 100 matches (`galbridge_global_max_results`), no groups, no
  whole-book export, entries outside the viewer's home domain shown as `Name (domain)`
  (`galbridge_mark_foreign_domains`).
- Groups by department or by domain (`galbridge_domain_groups` = `auto`, `galbridge_global_groups`),
  persons only, as `virtual` drill-down groups built by one aggregate query per request
  (`galbridge_max_groups`); expansion is complete or refused above
  min(`galbridge_max_group_members`, core `max_group_members`), and autocomplete offers only groups
  that expansion would accept.
- Alias accounts and mlmmj mailing lists as individual entries, shown only to users who may post to
  them under iRedAPD's `accesspolicy` (`galbridge_list_policy`: `strict`, `public_only`, `all`;
  `galbridge_include_aliases`, `galbridge_include_mailing_lists`).
- Field allowlist `galbridge_fields` (default name, first name, last name, e-mail, department; phone,
  mobile, organization and birthday opt-in) intersected with the columns the role can read; per-user
  alias addresses only with `galbridge_user_aliases`.
- Hiding: `galbridge_hide_expired`, `galbridge_hidden_addresses`, `galbridge_hidden_localparts` and an
  optional administrator-created `galbridge_visibility_column`.
- Every Roundcube 1.7.7 address-book path: source list, list with paging and totals, show, print,
  QR code, vCard export (all, selected, search result), compose to selected contacts and to a group,
  copy to a personal book, quick, advanced and saved searches, mail autocomplete with groups, the
  compose contact list and its search, group expansion. For compose, Redirect-dialog (`bounce`) and
  autocomplete requests the enabled books are added to `autocomplete_addressbooks` and Roundcube's
  `autocomplete_min_length` is raised to `galbridge_min_search_length`. A refused vCard export
  answers `204 No Content` (`addressbook_export` hook) instead of downloading an empty file.
- Search: quick and advanced (AND), partial, prefix and strict modes, at most 5 words, every word
  matching some field, a minimum length of 2 with shorter words narrowing as word prefixes, LIKE
  wildcards in the input taken literally, `nosearch` birthday.
- Limits with user-visible messages: `galbridge_max_results`, `galbridge_global_max_results`,
  `galbridge_max_ids`, `galbridge_max_group_members`, `galbridge_max_export`; bounded totals
  (`galbridge_max_count`); `galbridge_searchonly_threshold` for `'auto'` search-only books.
- `bin/galbridge.sh diag [--user=EMAIL]`: Roundcube and PHP versions, configuration file (mode,
  group write, owner, directory) and problems, IMAP host binding and its coverage of `imap_host` and
  of the hosts of Roundcube's users, core interplay, old-plugin leftovers, DSN policy, connection
  (with the driver's reason on failure), the password-column tripwire, write privileges from the
  database catalogs, column probe for the effective configuration, data sanity and indexes, and per
  user the entitled books with the effective grouping, the search-only resolution, entry counts and
  size warnings; every section runs on its own; never prints secrets or directory contents.
  `bin/galbridge.sh grants --driver=mysql|pgsql [--host=] [--account-host=] [--db=] [--role=]
  [--connection-limit=]`: the grant set with a fresh random password and the matching DSN line, on
  stdout only; re-running an output is safe (it resets the password).
- Reference grant sets `SQL/grants.mysql.sql` and `SQL/grants.postgres.sql`; `config.inc.php.dist`
  documenting every option; English and Polish translations.
- Documentation: README, SECURITY.md, `docs/ARCHITECTURE.md`, `docs/THREAT_MODEL.md`,
  `docs/MIGRATION.md`, `docs/TESTING.md`, `docs/RELEASING.md`.
- Tests: PHPUnit unit and integration suites against Roundcube 1.7.7 on a SQLite mirror, MariaDB and
  PostgreSQL loaded with the upstream iRedMail 1.8.11 `vmail` schema and a TEST-ONLY fixture, using a
  role built from the shipped grant templates; an end-to-end suite that drives a real Roundcube 1.7.7
  over HTTP for both editions; browser tests of Roundcube's own UI with the plugin (headless
  Chromium). CI (GitHub Actions, actions pinned to commit SHAs, read-only token):
  PHPUnit on PHP 8.1 – 8.5 with MariaDB 11.8 and PostgreSQL 17, `php -l`, `composer validate
  --strict`, `composer audit`, PHPStan level 8, PHP-CS-Fixer. Release archives exclude tests and
  developer tooling (`.gitattributes`).

### Security
Controls for the threats GB-01 to GB-28 of the design review (details and tests in
`docs/THREAT_MODEL.md`, decisions in `SECURITY.md`).
- GB-02/GB-03/GB-04/GB-05: tenant isolation by construction. Books and scopes come only from the
  authenticated user and the administrator configuration; `addressbook_get` always returns an
  instance for our IDs – an `AddressBook` when entitled, otherwise an inert `DeniedBook` that never
  connects; group IDs and contact IDs (`[A-Za-z0-9_]`, strict canonical decoding) are looked up only
  inside the book's scope, and a forged value matches nothing; a primary-key search without a valid
  ID matches nothing and more than `galbridge_max_ids` IDs are refused.
- GB-06/GB-07/GB-10: one query builder (`QueryBuilder`) with a parenthesised predicate tree whose
  root is the scope, bound values, defined empty lists, LIKE escaping with `ESCAPE '!'`, semi-joins
  only, bounded counts and explicit full-set rules for Roundcube's 999/9999/99999 page sizes; every
  returned row is re-checked in PHP.
- GB-08: the search set is a typed, versioned `SearchSpec` bound to the book and the configuration
  fingerprint, re-validated on every replay from the session; it never contains SQL.
- GB-09/GB-20: one field allowlist feeds the SELECT list, search, sort, groups and export; secret
  columns are never granted, and a role that can see `mailbox.password`, `recovery_email` or
  `allow_nets` disables every book (`role_too_broad`).
- GB-11/GB-12: IMAP-host binding (the session's and the stored IMAP host must be directory hosts;
  a templated, array or empty `imap_host` needs `galbridge_imap_hosts`, `parse_host()` is never
  used); the home domain is taken from vmail; the resolved user is cached in the session bound to
  user id, login, IMAP host and the configuration fingerprint for `galbridge_scope_ttl`; outages are
  never cached as "no entitlement".
- GB-13/GB-14: global book off by default with an empty viewer list and a second opt-in for `'*'`;
  persons-only groups; `accesspolicy`-aware lists with unknown policies hidden; forwarding targets,
  catch-all rows and external members never selected.
- GB-16/GB-17: `galbridge_*` user preferences are ignored (administrator values from a fresh
  configuration); user-controlled core options are clamped or whitelisted.
- GB-18/GB-19: source names escaped; display text sanitised (control and format characters, CR/LF,
  `\ " < >` removed, blank-looking characters collapsed, length caps; display, first and last names
  containing an at-sign or its look-alikes cut there; foreign global-book entries without first and
  last name, so the `(domain)` marker shows in every listing mode); addresses canonical lower-case
  ASCII checked with `rcube_utils::check_email()`.
- GB-21/GB-22: `ignore_errors` on the plugin's connection, SQLSTATE-only error logging, the generic
  "directory unavailable" message, a redacting logger with request-triggered reason codes logged
  once per request and hashed IDs; search terms, addresses and names are never logged by the
  plugin.
- GB-23/GB-24: a mandatory dedicated DSN; iRedMail's roles, `root`, `postgres`, the Roundcube
  database user and the password plugin's user are refused; verified TLS required for a remote
  database (PostgreSQL `verify-ca`/`verify-full`, MySQL `ca` + `verify_server_cert`), the PostgreSQL
  `service` parameter refused; column-level, read-only grant templates; no views, `dblink` or
  `DEFINER` objects.
- GB-25/GB-26/GB-28: read-only books, read-only session and statement timeout per connection, an
  injected connect timeout, a lazy connection, a 30-second outage memo, export policies per book,
  and an `init()` that never touches the database. Decision: no request rate limiter (a session
  counter is not atomic); a reverse-proxy limit on `_action=autocomplete` is recommended.
- GB-27: one normaliser (NFC, lower case, IDNA to ASCII) for configuration lists, the login, IDs and
  the row re-check; invalid allow-list entries are dropped, invalid deny-lists disable what they
  protect.

### Fixed after the independent code review
An independent review of the implementation reported 41 confirmed findings before the release. The
fixes, all part of 1.0.0:
- **Display spoofing (security).** The anti-impersonation rule also covers first and last names
  (Roundcube builds recipient names from them for "List names as" and `contactlist_name_template`)
  and recognises the at-sign look-alikes U+FF20 and U+FE6B. Display text also loses format
  characters (`\p{Cf}`: soft hyphen, tag characters, ...), and Unicode separators and blank-looking
  characters (U+2800, U+3164, U+115F, U+1160, U+FFA0) collapse into one space. Global-book entries
  of other domains carry no first and last name, so the `(domain)` marker shows in every listing
  mode.
- **Global book (security).** The `organization` field matches only the complete organization
  label: a two-letter search no longer lists a whole organization.
- **Fail-closed construction.** `QueryBuilder::forBook()` is the one way to build a book's query
  builder (plugin and `diag`) and refuses a malformed deny-list or visibility column instead of
  reading it as "no restriction". `Directory\Schema` is the one list of required and optional vmail
  columns for the user resolution, the book factory and `diag`; required columns depend on the
  configuration (`expired` with `galbridge_hide_expired`, `forwardings.domain` with
  `galbridge_user_aliases` – a missing `forwardings.domain` used to break every listing with a bare
  `db_error`).
- **Outages.** "The directory is temporarily unavailable." is now really shown (once per request for
  all books together)
  when a query fails – core's read actions never read `get_error()`. During a database outage the
  books a session already had stay listed from the expired session cache and answer with that
  message instead of disappearing silently; a session without resolved books (a new login) still
  gets none until the database is back.
- **Export.** A refused export (whole book not allowed or above `galbridge_max_export`, the whole of
  a search-only book, a group above the expansion cap, more than `galbridge_max_ids` selected
  contacts) answers `204 No Content`
  through the `addressbook_export` hook instead of downloading an empty `contacts.vcf` with a
  message that could never be shown.
- **Paging.** Full-set detection uses max(`max_pagesize`, `addressbook_pagesize`), bounded to 1000:
  an ordinary list page with a larger `addressbook_pagesize` was treated as a whole-book export or a
  group expansion.
- **Autocomplete.** The Redirect dialog (`mail/bounce`) is wired like compose
  (`autocomplete_min_length`, `autocomplete_addressbooks`); `galbridge_*_autocomplete = false` also
  hides the book's groups from autocomplete; the `'auto'` search-only decision is cached per book
  (two `'auto'` books evicted each other's entry).
- **Logging.** The malformed-row WARN and the scope tripwire ERROR have separate codes
  (`row_invalid`, `row_out_of_scope`; both shared `row_dropped`, so the first one hid the other);
  `host_mismatch` names the session host, the stored host and the allowed hosts, once per request.
- **`diag`.** Every section runs on its own. The PostgreSQL ctype is read from
  `pg_database.datctype` (`current_setting('lc_ctype')` stopped `diag` on PostgreSQL 16 and later
  before the column checks). Write privileges are read from the catalogs (the `UPDATE` probe ran
  inside the plugin's own read-only session and could never report a write-capable role). Optional
  columns are checked for the configured fields only, with an INFO for granted but unused ones. A
  WARN while `'*'` global data domains are in effect (it printed OK). `imap_host` and the
  `mail_host` values of Roundcube's users are checked against the directory hosts. `--user` shows
  the effective grouping with its group count, the `'auto'` search-only resolution and WARNs above
  `galbridge_max_count` / `galbridge_max_export`. The configuration file check also covers group
  write, the owner and the parent directory. A failed connection prints the driver's reason (in the
  CLI Roundcube writes it to stderr only, not to its errors log). The PostgreSQL role's connection
  limit is shown.
- **`grants`.** `--host` is the database server written into the DSN and the new `--account-host`
  the MariaDB/MySQL host part of the account (patterns such as `10.0.0.%`; a non-local one adds
  `REQUIRE SSL`) – the account host used to end up in the DSN. New `--connection-limit`
  (PostgreSQL, default 25; a malformed value is a usage error). Names are renamed in SQL statements
  only, including the commented-out optional ones, so the attribution header and the prose comments
  stay intact; host patterns and unbracketed IPv6 literals are refused for the DSN host.
- **Grant templates.** The statements (re)set the password (on PostgreSQL also the connection
  limit), so re-running a generated output is safe and its DSN always works. `telephone`, `mobile`
  and `birthday` are a commented-out grant (personal data outside the default fields); `first_name`
  and `last_name` stay granted.
- **Code quality.** Dead code removed (`BookDefinition::isGlobal()`, the unused `alias_domain`
  column probe); the `GalBridgeException` docblock corrected (reason codes are not localization
  labels).
- **Tests.** New `tests/Integration/CliToolTest.php` for `bin/galbridge.sh` (the `grants` output
  applied to MariaDB and PostgreSQL, `diag` against good, too broad and too narrow roles and broken
  configurations); `tests/e2e/matrix.sh` runs PHP 8.1, 8.4 and 8.5 × MariaDB and PostgreSQL and
  counts a missing combination as a failure; `tests/e2e/setup.sh` replaces the plugin link atomically
  (`ln -sfn`); `tests/fixtures/vmail/README.txt` documents the TEST-ONLY fixtures; `load.sh` enables
  the commented-out personal-data grant for the test role.
- **Documentation.** Aligned with the code: the log file is `<log_dir>/galbridge.log`; three session
  keys (`galbridge`, `galbridge_down_until`, `galbridge_searchonly`); `galbridge_debug` adds driver
  error texts, exception messages and schema hints only; the once-per-request rule applies to
  request-triggered codes; the `pg_hba.conf` lines for a remote Roundcube host must precede
  iRedMail's catch-all `host all all 0.0.0.0/0 md5`; four contradictions in `config.inc.php.dist`
  (`'auto'` groups, groups of search-only books, allow-list problems as WARN in `diag`, the
  commented-out MariaDB limits).

### Fixed (compared with `sql_global_addressbooks` 2.5.0)
- Fatal error on Roundcube 1.7: `reset()` and `set_search_set()` were declared without `: void`.
- Any user could instantiate any book through `_source`, list any domain through `_gid`
  (`WHERE domain = <gid>`) and open any contact through `_cid`; `get_record()` returned the previous
  result on a miss.
- "Compose to selected contacts" put the whole book into To: (the primary-key criterion was skipped).
- Queries had no LIMIT and `count()` always returned 1.
- Request-chosen column names were quoted into SQL, the search set held raw SQL, and the search
  code used undefined variables (`$mode`, `$AS`).
- Contact IDs were computed from the creation time and the length of the password hash (collisions,
  password-derived).
- The MySQL views listed disabled accounts and ran with root's rights (`DEFINER = root`); the
  PostgreSQL view stored the `vmail` password in plain text.
- `rename_group()` reported success on a read-only book.
- Once enabled, the global book showed every hosted domain as a group to every user by default.
