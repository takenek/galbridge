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
  compose contact list and its search, group expansion. For compose and autocomplete requests the
  enabled books are added to `autocomplete_addressbooks` and Roundcube's `autocomplete_min_length`
  is raised to `galbridge_min_search_length`.
- Search: quick and advanced (AND), partial, prefix and strict modes, at most 5 words, every word
  matching some field, a minimum length of 2 with shorter words narrowing as word prefixes, LIKE
  wildcards in the input taken literally, `nosearch` birthday.
- Limits with user-visible messages: `galbridge_max_results`, `galbridge_global_max_results`,
  `galbridge_max_ids`, `galbridge_max_group_members`, `galbridge_max_export`; bounded totals
  (`galbridge_max_count`); `galbridge_searchonly_threshold` for `'auto'` search-only books.
- `bin/galbridge.sh diag [--user=EMAIL]`: Roundcube and PHP versions, configuration file and
  problems, IMAP host binding, core interplay, old-plugin leftovers, DSN policy, connection,
  privilege tripwires, column probe, data sanity and indexes, and per user the entitled books with
  entry counts; never prints secrets or directory contents. `bin/galbridge.sh grants
  --driver=mysql|pgsql [--host=] [--db=] [--role=]`: the grant set with a fresh random password and
  the matching DSN line, on stdout only.
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
- GB-18/GB-19: source names escaped; display text sanitised (control, bidi and zero-width
  characters, CR/LF, `\ " < >` removed, length caps, names containing `@` cut); addresses canonical
  lower-case ASCII checked with `rcube_utils::check_email()`.
- GB-21/GB-22: `ignore_errors` on the plugin's connection, SQLSTATE-only error logging, a redacting
  logger with reason codes logged once per request and hashed IDs; search terms, addresses and names
  are never logged at normal levels.
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
