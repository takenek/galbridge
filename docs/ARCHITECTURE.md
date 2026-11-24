# GAL Bridge – Architecture

GAL Bridge (`galbridge`) gives Roundcube Webmail **1.7.7** a read-only **Global Address List**
built from the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and PostgreSQL
editions). It replaces the unmaintained *Roundcube-SQL-Global-Address-Books* plugin and was written
from scratch. It uses only the official plugin API: the `addressbooks_list` and `addressbook_get`
hooks and an `rcube_addressbook` backend.

Sections marked *(decision)* record why an alternative was rejected. They are the outcome of a
design review against the Roundcube 1.7.7 and iRedMail 1.8.11 sources (three independent reviews:
core integration, security, operability). Do not reopen them without new facts.

## 1. Goals and non-goals

Goals
* Correct on **every** Roundcube 1.7.7 address-book path: source listing, contact list with paging
  and totals, show, print, QR code, vCard export (all, selected, search), compose-to-selected and
  compose-to-group (`mailto`), copy to a personal book, quick, advanced and saved searches, mail
  autocomplete (contacts and groups), compose contact list, search and group expansion.
* **Tenant isolation by construction.** The visible scope comes only from the authenticated login
  and the administrator configuration. No request parameter (`_source`, `_gid`, `_cid`, `_search`,
  `_q`, `_headers`, `_page`) can widen it.
* **Least privilege, enforced.** A dedicated read-only role with column-level grants. The plugin
  refuses to run with a role that can see `mailbox.password`.
* Data minimisation: an administrator allowlist decides which fields are exposed. Fields outside it
  are never shown, searched, sorted, grouped on or exported.
* Never silently wrong: a recipient set (group expansion, compose-to-selected) is either complete or
  refused with a visible message. It is never a truncated prefix.

Non-goals (1.0)
* Writing to the directory, photos.
* The iRedMail OpenLDAP edition (Roundcube's native `ldap_public`, configured by iRedMail, covers it).
* mlmmj subscriber lists as groups (subscribers live on disk, not in SQL).
* More than one cross-domain book (one global book; see docs/MIGRATION.md for the old "support
  books").
* A request rate limiter (see 11).

## 2. Components

```
galbridge.php                    rcube_plugin: hooks, book factory, autocomplete wiring
lib/GalBridge/
  Config.php                     typed, clamped, normalised admin-only configuration
  Normalizer.php                 ONE normalisation for config lists, the login, IDs and row re-checks
  Log.php                        redacting logger, channel "galbridge", once-per-request reason codes
  Exception/GalBridgeException   base exception (reason code + internal message)
  Db/Dsn.php                     DSN policy: driver allowlist, refused roles, TLS rule, connect timeout
  Db/Connection.php              lazy rcube_db on galbridge_db_dsn: session limits, error handling,
                                 outage memo, schema/privilege probe
  Directory/Viewer.php           the authenticated principal: login, home domain H, address set V
  Directory/ViewerResolver.php   IMAP-host binding + resolution from vmail, cached in the session
  Directory/BookDefinition.php   one entitled book: id, name, data domains, grouping, limits, policy
  Directory/Entitlements.php     Viewer + Config -> entitled books (pure)
  Directory/IdCodec.php          contact and group IDs  <->  (kind, key)
  Directory/FieldMap.php         allowlist ∩ granted columns -> SELECT list, coltypes, search, sort
  Directory/Sql.php              predicate tree (and/or/not/eq/in/like/exists) rendered with params
  Directory/QueryBuilder.php     the ONLY producer of directory SQL; root = And(scope, visibility, filter)
  Directory/AccessPolicy.php     iRedAPD accesspolicy as SQL predicates (alias accounts, mlmmj lists)
  Directory/SearchSpec.php       typed, serialisable search filter (what get_search_set returns)
  Directory/RecordMapper.php     row -> Roundcube record (sanitised, no nulls, ASCII addresses)
  Directory/Repository.php       executes QueryBuilder output; per-request memo (groups, counts)
  AddressBook.php                final rcube_addressbook implementation (one instance per book)
  DeniedBook.php                 fail-closed empty book for stale/unentitled/disabled source IDs
  Cli/Tool.php                   bin/galbridge.sh: diag, grants
bin/galbridge.sh                 CLI wrapper (Roundcube clisetup)
config.inc.php.dist              every option documented
localization/en_US.inc, pl_PL.inc
SQL/grants.mysql.sql, SQL/grants.postgres.sql   reference grant set (read by `galbridge.sh grants`)
```

No JavaScript, templates or plugin tables: Roundcube's own address-book UI renders everything.

## 3. Request flow

1. `init()` (tasks `mail|addressbook`, authenticated users only) registers `addressbooks_list` and
   `addressbook_get` and loads the localization. **It never touches the database.** Only for
   `mail/compose` and `mail/autocomplete` (the only readers) it
   * appends the plugin's enabled source IDs to `autocomplete_addressbooks` (de-duplicated; a
     source the user is not entitled to resolves to a DeniedBook, costing nothing), and
   * raises the client-side `autocomplete_min_length` to `galbridge_min_search_length`. Roundcube's
     JS stops narrowing once a prefix returned nothing from every source, so the server minimum and
     the client minimum must agree.
2. The **Viewer** is resolved lazily, on the first hook call that needs it:
   * **IMAP-host binding** *(decision)*. `imap_host` is read from a fresh administrator
     `rcube_config` (user preferences ignored). If `galbridge_imap_hosts` is empty and `imap_host`
     is a single string without `%` placeholders, the allowed host is
     `strtolower(idn_to_ascii(parse_host_uri(imap_host)[0]))` (`tls://127.0.0.1:143` -> `127.0.0.1`).
     `parse_host()` is never called, because `%d`/`%n` come from the client's Host header. Explicit
     entries must be bare host names or IP literals. Both `$_SESSION['storage_host']` and the
     user's stored `mail_host` must match. A placeholder or array `imap_host` without an explicit
     list disables the plugin (diag: FAIL). Otherwise a user who logs in through an IMAP server of
     their own choosing as `ceo@tenant-a.example` would get tenant A's directory.
   * login `P` = `Normalizer::address(rcube_user::get_username())`;
   * `SELECT m.username, m.domain FROM mailbox m JOIN domain d ... WHERE m.username = ? AND m.active = 1
     AND d.active = 1 AND d.backupmx = 0 [AND m.expired > ? AND d.expired > ?]`. No row: no books.
     `H` = normalised `m.domain` (never the domain part of the login string);
   * `V` = `P` + the viewer's active per-user alias addresses (one more query; used only for
     accesspolicy);
   * the schema/privilege probe (12) runs on the same connection;
   * cached in `$_SESSION['galbridge']`, bound to user id, login, storage host and the configuration
     fingerprint, TTL `galbridge_scope_ttl` (default 300 s). A database outage is cached separately
     for 30 s (`down_until`) and is never cached as "no entitlement".
3. `addressbooks_list` appends one entry per entitled book with a non-empty data scope: `id`, `name`
   (HTML-escaped, core prints it raw), `readonly => true`, `groups`, `autocomplete`. No internals.
4. `addressbook_get` *(decision)*: for **our** source IDs it **always** sets an instance, whatever
   `writeable` says (core itself returns null for a writable request on a read-only book; without an
   instance core dies with error 700): `AddressBook` when entitled with a non-empty scope, otherwise
   `DeniedBook`. Foreign IDs are left untouched.
5. `AddressBook` builds SQL only through `QueryBuilder`. Every returned row is re-checked in PHP
   (normalised domain in the data scope, not hidden, valid address). A violation is dropped and
   logged.

### DeniedBook
`readonly = true`, `ready = false`, `groups = false`, `searchonly = false`, no `deletable`;
`get_record()` → null; `search()`, `list_records()`, `count()` → `rcube_result_set(0)`;
`list_groups()` → `[]`; `get_group()` → null; `get_record_groups()` → `[]`; `get_name()` → a
localised non-empty label. It never connects to the database.

## 4. Books *(decision)*

| Source ID | Viewers | Data scope | Default |
|---|---|---|---|
| `galbridge_domain` | `galbridge_domain_viewer_domains` (default `['*']`) minus `..._exclude` | `H`, or the organization containing `H` | on |
| `galbridge_global` | `galbridge_global_viewer_domains` minus `..._exclude` | `galbridge_global_data_domains` minus `..._exclude`, minus the viewer's domain-book scope unless `galbridge_global_include_home` | off |

* Source IDs never contain `-` (core splits `ID-source` at the last `-` in PHP and the first in JS)
  and avoid the reserved IDs `0 1 2 4 -1 sql`.
* `galbridge_organizations` = `['Acme' => ['acme.pl', 'acme.com'], ...]`. Sets must be disjoint. A
  domain listed twice is a configuration error: the affected domains fall back to their own domain
  (fail closed) and diag reports FAIL. The key is the organization label (`organization` field).
* `*` in `galbridge_global_data_domains` (every hosted domain, including tenants added later) needs
  `galbridge_global_allow_wildcard = true` as well. diag warns about it.
* `searchonly`: `true`, `false` or `'auto'` (search-only when the data scope holds more than
  `galbridge_searchonly_threshold` persons, default 2000, counted once per Viewer TTL). Defaults:
  domain book `false`, global book `true`. A search-only book returns an empty set with core's
  "search only" notice when listed without a search or group, as `rcube_ldap` does.
* The global book uses **prefix** search mode whatever `addressbook_search_mode` says, matches
  e-mail addresses on the local part (or exactly in strict mode), never on a domain fragment, and
  has a lower result cap. Otherwise a partial search for a domain name returns a whole tenant.

## 5. Entities and visibility predicates

The predicates mirror what iRedMail treats as deliverable (Postfix `virtual_mailbox_maps`,
`virtual_mailbox_domains`, `transport_maps_maillist`).

* **Person**: `m.active = 1 AND m.enabledeliver = 1 AND d.active = 1 AND d.backupmx = 0`
  [`AND m.expired > :now AND d.expired > :now` with `galbridge_hide_expired`, default on, `:now`
  bound from PHP in UTC] [`AND m.<galbridge_visibility_column> = 1`], minus
  `galbridge_hidden_addresses` / `galbridge_hidden_localparts`.
* **Alias account** (`galbridge_include_aliases`, default on): `a.active = 1`, domain visible,
  `EXISTS` an active `forwardings` row with `is_list = 1`, accesspolicy (6).
* **Mailing list** (`galbridge_include_mailing_lists`, default on): `ml.active = 1`, domain visible,
  `EXISTS` an active `forwardings` row with `is_maillist = 1`, accesspolicy.
* **Per-user alias addresses** (`forwardings.is_alias = 1`) are selected, displayed and searchable
  **only** with `galbridge_user_aliases` (default off). When off they are neither shown nor matched,
  so a search cannot reveal who owns an alias. The viewer's own aliases are always loaded for
  accesspolicy (Viewer resolution).
* Every `forwardings` / `moderators` / `maillist_owners` predicate is a semi-join (`EXISTS`/`IN`),
  never a JOIN, so rows never multiply (counts and paging stay exact). Display aliases are fetched
  by a second query keyed by the addresses of the current page.
* Forwarding targets (`is_forwarding`), catch-all rows and external list members are never selected.

## 6. accesspolicy (alias accounts and mlmmj lists)

iRedAPD enforces who may post to an alias or list. GAL Bridge shows it only to viewers who could
post to it. Values are compared as `LOWER(TRIM())`, `allowedonly` = `moderatorsonly`. `V` = viewer
address set.

| policy | alias account | mlmmj list |
|---|---|---|
| `''`, `public` | everyone in the book | same |
| `domain` | `a.domain = H` | same |
| `subdomain` | `a.domain` ∈ parents(`H`) | same |
| `membersonly` | an active `is_list` member in `V` | moderator/owner in `V` (subscribers are not in SQL) |
| `moderatorsonly` | moderator in `V`, or `*@H` | moderator/owner in `V` |
| `membersandmoderatorsonly` | member or moderator | moderator/owner in `V` |
| anything else | hidden (fail closed) | hidden |

`galbridge_list_policy`: `strict` (the table, default), `public_only`, `all` (explicit opt-in).
*(decision)* No alias-domain clause for `domain`: `H` is always a primary domain, so such a clause
could only match through a tenant-made `alias_domain` row.

## 7. Fields *(decision)*

`galbridge_fields` is the allowlist. Effective fields = allowlist ∩ columns the role can see
(schema probe, 12): a server without the iRedMail 1.7.3 columns keeps working with fewer fields and
one WARN. FieldMap drives the SELECT list, `coltypes`, searchable fields, sort keys, grouping and
vCard export.

| field | coltypes key | record key | source | default |
|---|---|---|---|---|
| `name` | `name` | `name` | `mailbox.name`, else first + last (if allowed), else local part | always |
| `firstname` | `firstname` | `firstname` | `first_name` | yes |
| `surname` | `surname` | `surname` | `last_name` | yes |
| `email` | `email` (subtypes `work`, + `other` with user aliases) | `email:work`, `email:other` | `username` (+ aliases) | always |
| `department` | `department` | `department` | `department` | yes |
| `phone` | `phone` (subtype `work`) | `phone:work` | `telephone` | no |
| `mobile` | `phone` (subtype `mobile`) | `phone:mobile` | `mobile` | no |
| `organization` | `organization` | `organization` | organization label (4) | no |
| `birthday` | `birthday` (`type` date, `nosearch`) | `birthday` | `birthday` (`0001-01-01` = empty) | no |

* Records always have `ID`, `name` and `_type = 'person'` (also for alias accounts and lists; core
  gives `group` other meanings). No value is ever null, no `vcard` key.
* `birthday` is never matched with LIKE (a DATE column; PostgreSQL has no `date ~~*`).
* Never readable, whatever the configuration: `password`, `recovery_email`, `allow_nets`, `settings`,
  storage, quota and transport columns, admin flags, other `enable*` flags, `rank`, timestamps other
  than `expired`. `enabledeliver`, `active` and `expired` are read in predicates only.
* Display sanitising (RecordMapper): NFC; control, bidi and zero-width characters, CR/LF, `\ " < >`
  removed; names capped at 128 characters; a name containing `@` is replaced by the local part;
  addresses are lower-case ASCII (A-labels, no homographs) and must pass `rcube_utils::check_email()`.
  With `galbridge_mark_foreign_domains` (default on) a global-book entry outside the viewer's home
  domain is shown as `Name (domain)`.

## 8. Groups *(decision)*

* `galbridge_domain_groups`: `auto` (default: `domain` when `H` is in an organization, else
  `department`), `department`, `domain`, `none`. `galbridge_global_groups`: `none` (default),
  `domain`, `department`. Grouping by department requires `department` in the effective fields
  (else forced to `none`, ERROR).
* Members are **persons only** (alias accounts and lists stay individual records), so counts are
  head counts and an expansion never sends to a list and its members twice.
* One aggregate query per request and book returns `ID`, label and member count (department key
  `LOWER(TRIM(department))`, label `MIN(TRIM(department))`, empty excluded), capped at
  `galbridge_max_groups` (50; above it the book has no groups). `get_group()` and a group `count()`
  without a filter are answered from this memo.
* `list_groups()` returns `virtual => true` groups, so the compose list renders drill-down rows
  without a COUNT per group. With a search term (autocomplete, compose search) it applies the
  minimum length and leaves out groups larger than the expansion cap, so a group that expansion
  would refuse is never offered. A search-only book returns no groups for an empty search.
* Group IDs carry a book tag (`dd`/`dp` domain book, `gd`/`gp` global book) and the compact key
  encoding (9). `set_group()`: `0 '0' '' null false` clear the group; any other value is decoded,
  and an undecodable or foreign-book value installs a match-nothing predicate (and keeps
  `group_id`, so core's `get_group()` returns null).

## 9. IDs *(decision)*

Core moves IDs through HTML ids, JS strings, comma lists, `ID-source` pairs and GET URLs (export of
selected contacts puts all of them into one URL). Safe charset `[A-Za-z0-9_]`; base64url is
rejected (`-`). Contact IDs are `<kind>_<enc>`: kinds `u` (person), `a` (alias account), `l`
(mailing list). `enc` keeps `[a-z0-9]` literally and escapes everything else with upper-case
markers: `.`→`D`, `@`→`A`, `-`→`H`, `_`→`U`, `+`→`P`, any other byte → `X` + two hex digits.
`jan.kowalski@firma.pl` → `u_janDkowalskiAfirmaDpl`. Decoding is strict and canonical (re-encoding
must give the input), never throws, accepts only strings up to 1 + 1 + 3·254 characters and a
plausible lower-case address; every lookup is scoped, and a miss and an out-of-scope ID give the
same answer (null or an empty set, the previous result cleared).

## 10. Search

* Fields map through FieldMap. `*` = all searchable effective fields. Quick search ignores unknown
  fields; advanced search (array value, AND semantics) with any unknown or `nosearch` field matches
  nothing. An unsupported `$required` field (core passes `photo` for the sender photo of every
  opened message) returns an empty set **without a query**.
* Modes `$mode & 3`: partial `%w%`, prefix `w%` or a word start, strict = whole value. The input is
  NFC-normalised, lower-cased, split into at most 5 words; every word must match at least one field.
  `galbridge_min_search_length` (default 2, one value for all books): no query unless at least one
  word reaches it (global book: every word). Shorter words stay as word-prefix conditions, so
  "Jan K" works. `galbridge_max_search_length` 128.
* LIKE patterns escape `! % _` with `ESCAPE '!'` (identical on MariaDB, PostgreSQL, SQLite);
  PostgreSQL uses `ILIKE`. Accent folding differs between engines (MariaDB `utf8mb4_general_ci`
  folds, PostgreSQL does not): documented, not hidden.
* `search()` always replaces the stored spec, also on early returns (with a match-nothing spec).
  `get_search_set()` returns a `SearchSpec` array (version, book id, config fingerprint, fields,
  values, mode, required, IDs) and never SQL. `set_search_set()` re-validates every criterion
  against the current FieldMap and rules; anything else matches nothing. Scope is re-applied on
  every execution.
* `galbridge_autocomplete` per book is enforced in `search()` for `_action=autocomplete` (core
  accepts any `_source` there).

## 11. Paging, counts, limits

* `set_page()` ≥ 1. Normal page sizes are those ≤ core `max_pagesize` (default 200); Roundcube's own
  lists never ask for more. A larger size on page 1 is a **full-set request** (core uses
  999/9999/99999 for group expansion, compose-to-group, export and search lists):
  * group, no filter (compose-to-group, group expansion, group export): COUNT first; above
    min(`galbridge_max_group_members`, core `max_group_members` if set) → empty result, `set_error()`,
    a visible warning (`galbridge.grouptoolarge`), log; otherwise **all** members;
  * ID search (compose-to-selected, export of selected): more than `galbridge_max_ids` (200) IDs →
    empty + warning, never a prefix;
  * search lists: capped at `galbridge_max_results` (500 domain, 100 global) with a "showing first
    N, refine your search" notice when truncated;
  * whole-book export (no filter, no group): refused above `galbridge_max_export` (2000) or when the
    book has `export => false` (global default).
* `count()` = `COUNT` over the same root predicate (persons + aliases + lists, each unique by primary
  key), bounded by `galbridge_max_count` (10000). Listing offsets beyond it return an empty page.
* Sort keys per `sort_col` with fallbacks over present columns only: surname →
  `COALESCE(NULLIF(TRIM(last_name),''), NULLIF(TRIM(name),''), username)`, likewise firstname;
  name → `COALESCE(NULLIF(TRIM(name),''), username)`; address as tie-break. Core re-sorts search
  results by `addressbook_sort_col` in PHP: README recommends `addressbook_sort_col = 'name'` on
  iRedMail (open-source iRedAdmin fills only `name`); diag warns.
* *(decision)* **No rate limiter.** A `$_SESSION` counter is not atomic (parallel requests, which core
  itself sends for autocomplete, all pass) and turns read-only requests into session writers that
  can overwrite concurrent compose state. Resource control = result caps, minimum length, statement
  and connect timeouts, role connection limit, and a reverse-proxy limit on `_action=autocomplete`
  (README).

## 12. Database access

* `galbridge_db_dsn` is mandatory; no fallback to Roundcube's connection (PostgreSQL cannot query
  across databases, and iRedMail's `roundcube` role can UPDATE `vmail.mailbox`).
* Dsn policy: drivers `mysql`, `mysqli`, `pgsql` (`sqlite` only under the test bootstrap); the
  `service` parameter is refused. Refused roles (default): `vmail`, `vmailadmin`, `roundcube`,
  `sogo`, `iredadmin`, `iredapd`, `amavisd`, `fail2ban`, `root`, `postgres`, plus the `db_dsnw` user
  and the password plugin's DSN user. Loopback = unix socket, 127.0.0.0/8, `::1`, `localhost`; any
  other host needs verified TLS (PostgreSQL `sslmode=verify-ca|verify-full`; MySQL `ca`/`capath` +
  `verify_server_cert=1`) unless `galbridge_db_allow_plaintext` (diag WARN). A connect `timeout`
  (`galbridge_db_connect_timeout`, 2 s) is injected when the DSN has none.
* After connecting: read-only session and statement timeout (`galbridge_db_timeout`, 3 s;
  MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`), as a
  backstop to the role-level limits in the grant templates.
* **Schema and privilege probe** (once per Viewer TTL): `information_schema.columns` for the eight
  tables. MariaDB and PostgreSQL list only columns the role can access, so this yields "present and
  granted". If `mailbox.password`, `recovery_email` or `allow_nets` is visible, the role is too
  broad: books become DeniedBooks, ERROR `role_too_broad`.
* `ignore_errors` is on, so core never logs SQL with search values; errors are logged with the
  SQLSTATE only (driver text with `galbridge_debug`). The book calls `set_error()` and returns an
  empty result. A connection failure is memoised for the request and for 30 s in the session.
* Identifiers in backticks (rewritten for PostgreSQL by `rcube_db`), values always bound, no `?`
  or backtick inside literals, no `table_name()` for vmail tables, never `SELECT *`.

## 13. Configuration guard and normalisation

* Roundcube merges stored user preferences over the configuration. `GalBridge\Config` ignores every
  preference named `galbridge_*` and reads the administrator value from a fresh `rcube_config`, as
  MIME Shield does. Core options a user controls (`addressbook_pagesize`, `addressbook_sort_col`,
  `addressbook_search_mode`, `contactlist_fields`, `autocomplete_addressbooks`) are clamped or
  whitelisted.
* One `Normalizer` for config lists, the login, IDs and row re-checks: trim, NFC, lower case, split
  at the last `@`, IDNA to ASCII (non-ASCII input without ext-intl fails), trailing dot removed,
  validated. Lists must be arrays; `*` only as the sole element. A failed **allow**-list entry is
  dropped (ERROR); a failed **deny**-list entry disables the affected book (fail closed, diag FAIL).
* The configuration fingerprint (hash of the effective admin values) binds the Viewer cache and
  stored search specs.

## 14. Logging

Channel `galbridge`. Normal levels: reason codes (`denied_source`, `invalid_gid`, `invalid_id`,
`group_too_large`, `role_too_broad`, `column_missing`, `db_error` + SQLSTATE, `db_down`,
`config_invalid`), the numeric Roundcube user id, book ids, counts. Each reason code is logged at
most once per request. Search terms, addresses and names only with `galbridge_debug`, sanitised.
Note: `rcube_db` inlines bound values on the client side, so database server logs (slow/general
log, `log_min_error_statement`) can contain search terms (SECURITY.md).

## 15. Hooks, actions, CLI

| Hook | Purpose |
|---|---|
| `addressbooks_list` | advertise entitled books |
| `addressbook_get` | instantiate `AddressBook` or `DeniedBook` for our IDs |

No actions, templates or JavaScript. CLI: `bin/galbridge.sh diag [--user=<login>]` (configuration,
IMAP-host derivation, DSN policy, connection and limits, privilege tripwires, column probe, indexes,
data sanity, core interplay, old-plugin leftovers, per-user entitlement and timed sample search) and
`bin/galbridge.sh grants --driver=mysql|pgsql [--host=...] [--db=vmail]` (prints the grant set with a
fresh random password and the matching DSN; never writes into the plugin directory).

## 16. Operations

* Version policy: the plugin runs on any 1.7.x core but is tested and supported on 1.7.7 only;
  diag warns on any other version (`min-version` 1.7.7 for the Composer installer).
* Upgrade of Roundcube (iRedMail installs versioned trees): copy `plugins/galbridge` and its
  `config.inc.php` into the new tree, run diag.
* Uninstall: remove from `$config['plugins']`; MariaDB `DROP USER 'galbridge'@'localhost'`;
  PostgreSQL `\c vmail` + `DROP OWNED BY galbridge; DROP ROLE galbridge;`. The plugin keeps no
  tables and no user preferences.
