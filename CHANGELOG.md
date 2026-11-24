# Changelog – GAL Bridge (`galbridge`)

All notable changes are documented here. The format follows *Keep a Changelog*; versions follow
semantic versioning.

## [Unreleased]

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
