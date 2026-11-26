# GAL Bridge – Architecture

GAL Bridge (`galbridge`) gives Roundcube Webmail **1.7** (every 1.7.x release) a read-only **Global Address List**
built from the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and PostgreSQL
editions). It replaces the unmaintained *Roundcube-SQL-Global-Address-Books* plugin and was written
from scratch. It uses only the official plugin API: the `addressbooks_list` and `addressbook_get`
hooks, the `addressbook_export` hook (vCard export only), the `contact_exists` hook (directory
entries are no "known contacts") and an `rcube_addressbook` backend.

Sections marked *(decision)* record why an alternative was rejected. They are the outcome of a
design review against the Roundcube 1.7.7 and iRedMail 1.8.11 sources (three independent reviews:
core integration, security, operability) and of the security audit of the 1.0.0 code. The decisions
that leave a risk are listed, with their owner and next review date, in the register of
[SECURITY.md](../SECURITY.md#accepted-risks-and-design-decisions); a decision is revisited whenever
the facts behind it change.

## 1. Goals and non-goals

Goals
* Correct on **every** Roundcube 1.7 address-book path: source listing, contact list with paging
  and totals, show, print, QR code, vCard export (all, selected, search), compose-to-selected and
  compose-to-group (`mailto`), copy to a personal book, quick, advanced and saved searches, mail
  autocomplete (contacts and groups), compose contact list, search and group expansion, the vCard
  attachment of the bundled `vcard_attachments` plugin (`vcard://<source>-<cid>-<email>`,
  `get_record($cid, true)`) and core's known-contact check `rcmail::contact_exists()` (a strict
  e-mail search on the books named by `collected_recipients` / `collected_senders`, from which the
  `contact_exists` hook removes the directory books – 15).
* **Tenant isolation by construction.** The visible scope comes only from the authenticated login
  and the administrator configuration. No request parameter (`_source`, `_gid`, `_cid`, `_search`,
  `_q`, `_headers`, `_page`) can widen it.
* **Least privilege, enforced.** A dedicated read-only role with column-level grants and a
  row-restricting view over `forwardings`. The plugin refuses to run with a role that can see
  `mailbox.password` or the `forwardings` table.
* Data minimisation: an administrator allowlist decides which fields are exposed. Fields outside it
  are never shown, searched, sorted, grouped on or exported.
* Never silently wrong: a recipient set (group expansion, compose-to-selected) and every export are
  either complete or refused. Neither is ever a truncated prefix, and a selection never silently
  lacks a selected entry. A row is offered everywhere (list, count, search, group, full set) or
  nowhere: SQL and the PHP re-check apply one address rule (5).
* Fail closed on configuration: a value of the wrong type never means "off" or "no restriction".

Non-goals (1.0)
* Writing to the directory, photos.
* The iRedMail OpenLDAP edition (Roundcube's native `ldap_public`, configured by iRedMail, covers it).
* mlmmj subscriber lists as groups (subscribers live on disk, not in SQL).
* More than one cross-domain book (one global book; see docs/MIGRATION.md for the old "support
  books").
* A request rate limiter (see 11).

## 2. Components

```
galbridge.php                    rcube_plugin: hooks, book factory, autocomplete wiring, export refusal,
                                 known-contact filter, the IMAP check of a renewed Viewer
lib/GalBridge/
  Config.php                     typed, clamped, normalised admin-only configuration; core options as
                                 Roundcube applies them; the ONE routine for the plugin's files
  Normalizer.php                 ONE normalisation for config lists, the login, IDs and row re-checks
  Log.php                        redacting logger, channel "galbridge", once-per-request reason codes
  Exception/GalBridgeException   base exception (reason code + internal message)
  Db/Dsn.php                     DSN policy: driver and parameter allowlists, explicit target in one
                                 grammar, refused roles, TLS rule (host name verified), connect
                                 timeout; the DSN for the driver rebuilt from the judged parts
  Db/Connection.php              lazy driver class on galbridge_db_dsn: read timeout (MariaDB/MySQL),
                                 session limits (fail closed), error handling, outage memo, the
                                 NUL/backslash backstop, schema/privilege probe (incl. the
                                 forbidden table)
  Directory/Viewer.php           the authenticated principal: login, home domain H, address set V
  Directory/ViewerResolver.php   IMAP-server binding (host and port) and transport rule + resolution
                                 from vmail, cached in the session (IMAP re-check when renewed) and
                                 confirmed before directory data is read
  Directory/Schema.php           the vmail columns required / optional for the effective configuration
                                 (one list for the resolver, the factory and diag)
  Directory/BookDefinition.php   one entitled book: id, name, data domains, grouping, limits, policy
  Directory/Entitlements.php     Viewer + Config -> entitled books (pure)
  Directory/IdCodec.php          contact and group IDs  <->  (kind, key)
  Directory/FieldMap.php         allowlist ∩ granted columns -> SELECT list, coltypes, search, sort
  Directory/Sql.php              predicate tree (and/or/not/eq/in/like/exists) rendered with params
  Directory/QueryBuilder.php     the ONLY producer of SQL that reads directory entries; root =
                                 And(scope, visibility, filter); built only by QueryBuilder::forBook()
                                 (malformed deny-lists refused); the Viewer lookup and diag's
                                 statistics are separate fixed statements
  Directory/AccessPolicy.php     iRedAPD accesspolicy as SQL predicates (alias accounts, mlmmj lists)
  Directory/SearchSpec.php       typed, serialisable search filter (what get_search_set returns)
  Directory/RecordMapper.php     row -> Roundcube record (sanitised, no nulls, canonical addresses;
                                 the fields a foreign entry does not carry: foreignWithheld())
  Directory/Repository.php       executes QueryBuilder output; per-request memo (groups, counts);
                                 complete-or-nothing sets; details in batches of DETAIL_BATCH;
                                 confirms a cached entitlement first
  Directory/LookupBudget.php     contacts one request may read one by one (galbridge_max_ids)
  AddressBook.php                final rcube_addressbook implementation (one instance per book)
  DeniedBook.php                 fail-closed empty book for stale/unentitled/disabled source IDs (and
                                 the session's books during a database outage)
  Cli/Tool.php                   bin/galbridge.sh: diag, grants
bin/galbridge.sh                 CLI wrapper (Roundcube clisetup); refuses to run as root
config.inc.php.dist              every option documented
localization/en_US.inc, pl_PL.inc
SQL/grants.mysql.sql, SQL/grants.postgres.sql   reference grant set (read by `galbridge.sh grants`):
                                 the role, its limits, the view galbridge_forwardings, column grants;
                                 the iRedMail 1.7.3 columns are separate statements: first_name and
                                 last_name granted, telephone/mobile/birthday commented out
```

No JavaScript, templates or plugin tables: Roundcube's own address-book UI renders everything. The
only database object is the view `galbridge_forwardings` in `vmail` (12).

## 3. Request flow

1. `init()` (tasks `mail|addressbook|settings`, authenticated users only) registers, in the
   `settings` task, `preferences_update` and nothing else (no configuration, texts or database:
   it drops `galbridge_*` keys from the preferences Roundcube saves, 15). In `mail` and `addressbook`
   it registers `addressbooks_list` and
   `addressbook_get` (plus `contact_exists` in the `mail` task and `addressbook_export` in the
   `addressbook` task, 15) and loads the localization. **It never touches the database.** Action
   names are compared in the canonical form of Roundcube's dispatcher (`Factory::canonicalAction()`:
   lower case, `-` for `_` – the dispatcher reaches the same handler for `EXPORT` and `export`).
   Only for `mail/compose`, `mail/bounce` (the
   Redirect dialog, whose recipient fields core renders through the same
   `rcmail_sendmail::headers_output()`, which calls `rcmail_action::autocomplete_init()` – the reader
   of both options) and `mail/autocomplete` (which reads `autocomplete_addressbooks` itself) it
   * appends the plugin's enabled source IDs to `autocomplete_addressbooks` (de-duplicated; a
     source the user is not entitled to resolves to a DeniedBook, costing nothing, and its refusal
     is logged at debug level only – the plugin put it there; nothing is wired while a
     configuration error disables every book), and
   * raises the client-side `autocomplete_min_length` to `galbridge_min_search_length`. Roundcube's
     JS stops narrowing once a prefix returned nothing from every source, so the server minimum and
     the client minimum must agree.
2. The **Viewer** is resolved lazily, on the first hook call that needs it:
   * **IMAP-server binding** *(decision)*. `imap_host` is read as Roundcube applies it
     (`Config::coreValue()`: Roundcube's own configuration object; for a name a user preference
     shadows – `imap_host` or the legacy `default_host` – the second reading of the files, 13). If
     `galbridge_imap_hosts` is empty and `imap_host` is a single string without `%` placeholders,
     and a second reading of the configuration files gives the same value (otherwise it comes from
     an environment-specific file, `include_once`/`require_once` or another plugin: no derivation,
     the plugin stays disabled without an explicit list), the allowed server is host and port of
     `parse_host_uri(imap_host)`, the host as `strtolower(idn_to_ascii(...))`
     (`tls://127.0.0.1:143` -> `127.0.0.1:143`).
     `parse_host()` is never called, because `%d`/`%n` come from the client's Host header. Explicit
     entries are host names or IP literals, optionally with a port (`host:port`, `[::1]:993`); an
     entry without a port covers every port of that host. The session's `storage_host` **and
     `storage_port`** and the user's stored `mail_host` must be covered - in the raw form the login
     wrote them (`ViewerResolver::canonicalHost()`: host name, IPv4, IPv6 in brackets, socket URI;
     `mail_host` also the URI an older Roundcube stored), never trimmed or parsed a second time:
     PHP connects with the raw value, taking the host up to its first colon, so a second parse could
     name another server than the one the session was authenticated by. An IMAP server on a local
     socket (`unix:///run/dovecot/imap.sock`; core connects with port -1) is an endpoint of its
     own: derived from an `imap_host` of that form or listed, compared as a whole with the path as
     written (`Config::unixEndpoint()`), and never subject to the host-without-port rule. A placeholder or array
     `imap_host` without an explicit list disables the plugin (diag: FAIL), and so does
     `include_host_config` in either reading (the effective `imap_host` then depends on the
     requested host name) or a set `ROUNDCUBE_INCLUDE_HOST_CONFIG` (which the core ignores for that
     option). A request whose configuration is not the one the administrator wrote
     (`Config::configUnreliable()`, 13: a plugin configuration file that does not load or is
     ignored, an unknown option or variable, or - with `include_host_config` - `galbridge_*`
     options that differ from the host-independent files) is refused before anything else
     (`config_invalid`, no database access).
     Otherwise a user who logs in through an IMAP server of their own choosing as
     `ceo@tenant-a.example` would get tenant A's directory. What the plugin cannot check is that
     every listed server authenticates against this vmail database (SECURITY.md).
   * **Transport** *(decision)*. On every request, before the cache and the database
     (`ViewerResolver::transportProblem()`): a session on a server that is not local (a socket,
     `localhost`, `127.0.0.0/8`, `::1`; host names are never resolved) must carry the TLS mode
     `ssl`/`imaps`, or `tls` on a Roundcube library of 1.7.7 or later (`RCUBE_VERSION`; earlier
     releases continue in plain text when the server offers no STARTTLS, and `1.7-git` names no
     release), PHP must have `openssl`, and `imap_conn_options` (the entry of that host when it has
     one, as `rcube_utils::parse_socket_options()` selects it) must not switch verification off.
     The IMAP login is the identity proof; over an unverified link anyone on the network could answer
     it. `galbridge_imap_allow_plaintext` accepts such a session with a warning; there is no
     fallback otherwise (`transport_unprotected`, not cached).
   * login `P` = `Normalizer::address(rcube_user::get_username())`;
   * `SELECT m.username, m.domain FROM mailbox m JOIN domain d ... WHERE m.username = ? AND m.active = 1
     AND d.active = 1 AND d.backupmx = 0 [AND m.expired > ? AND d.expired > ?]`. No row: no books.
     `H` = normalised `m.domain`, which must be the domain of `P` (the rule every directory row
     has to pass in RecordMapper; a row where they differ opens no directory);
   * `V` = `P` + the viewer's per-user alias addresses of deliverable domains (alias row active, its
     domain active, not a backup MX, not expired with `galbridge_hide_expired`); one more query,
     at most 200 rows – with more the login alone is used; `V` serves only accesspolicy;
   * the schema/privilege probe (12) runs on the same connection;
   * cached in `$_SESSION['galbridge']`, bound to user id, login, storage host and port and the
     configuration fingerprint, TTL `galbridge_scope_ttl` (default 300 s). A database outage is cached separately
     for 30 s (`$_SESSION['galbridge_down_until']`) and is never cached as "no entitlement"; neither is
     a refused DSN (`config_invalid`, logged once per request). Missing required columns
     (`Schema::required()`: the columns of the view `galbridge_forwardings` always, `expired` only
     with `galbridge_hide_expired`) give `column_missing` (logged with the column names) without
     running the viewer query;
   * *(decision)* **a cached entitlement is confirmed before directory data is read.** The cache
     saves the resolution (several statements) on every mail request, but an entitlement must not
     outlive the mailbox. `Repository` therefore calls `ViewerResolver::confirm()` before its first
     query of a request: one statement for the mailbox row with the predicates above. When it
     fails, the request reads nothing ("directory unavailable") and the session entry is
     overwritten with the decision "no mailbox". Overwritten, not unset – `rcube_session` merges the
     stored session with the request's copy, so a key that is merely unset would return. With the
     strict list policy (where `V` decides) `V` is read again in the same step: a removed alias makes
     the request read nothing (`viewer_changed`; its queries were built with the old set) and the
     entry gets the current set, keeping its time; an added alias is taken over without interrupting
     the request (its queries are narrower). A Viewer resolved in the same request needs no
     confirmation, and a request that only instantiates a book never runs one;
   * *(decision)* **the IMAP server confirms the credentials when the entry is renewed.** The role
     cannot see passwords or service flags, and address-book requests do not connect to IMAP, so an
     open session would keep the directory after a password change. Before the lookup of a renewal
     the resolver calls the check the plugin passes (`galbridge::imapCheck()`: Roundcube's open IMAP
     connection of the request, or `rcmail::storage_connect()` with the session's credentials):
     a refusal (`ViewerResolver::imapVerdict()`: NO with `AUTHENTICATIONFAILED`,
     `AUTHORIZATIONFAILED` or `EXPIRED`) overwrites the entry with `imap_refused`, which is final for
     the session (checked before the TTL and the fingerprint; no second attempt that fail2ban would
     count); any other failure is `imap_unavailable` with `imap_retry_at` (30 s) in the entry and
     never a refusal. Nothing new is kept in the session beyond the `galbridge` entry;
   * *(decision)* when the cached Viewer has expired and its re-resolution fails with `db_down` or
     `db_error`, or the IMAP server cannot be asked (`imap_unavailable`), the expired Viewer (same
     user, login, host and fingerprint) is kept as a **stale Viewer**. It is used only to keep listing the session's books, as DeniedBooks that show
     `galbridge.unavailable` (see DeniedBook below), never to read directory data. Without it the
     books would vanish silently after the TTL; a session without a cached Viewer (a login during
     the outage) still gets no books.
3. `addressbooks_list` appends one entry per entitled book with a non-empty data scope: `id`, `name`
   (HTML-escaped, core prints it raw), `readonly => true`, `groups` (the effective grouping,
   `Factory::effectiveGrouping()`), `autocomplete` (informational: Roundcube 1.7 does not read this
   key; autocomplete is wired through `autocomplete_addressbooks` (1) and enforced by the book in
   `search()` and `list_groups()`). No internals.
4. `addressbook_get` *(decision)*: for **our** source IDs it **always** sets an instance, whatever
   `writeable` says (core itself returns null for a writable request on a read-only book; without an
   instance core dies with error 700): `AddressBook` when entitled with a non-empty scope, otherwise
   `DeniedBook`. Foreign IDs are left untouched.
5. `AddressBook` builds SQL only through `QueryBuilder`. Every returned row is re-checked in PHP
   with the rule the SQL applied (canonical address, normalised domain in the data scope, not
   hidden). A violation – a defect or a row changed during the request – is dropped and logged with
   a non-reversible tag of the row (`row_invalid` WARN for an address outside the rule or a domain
   column it does not belong to, `row_out_of_scope` ERROR for a row outside the scope or hidden;
   both `row=<tag>`).

### DeniedBook
`readonly = true`, `ready = false`, `groups = false`, `searchonly = false`, no `deletable`;
`get_record()` → null; `search()`, `list_records()`, `count()` → `rcube_result_set(0)`;
`list_groups()` → `[]`; `get_group()` → null; `get_record_groups()` → `[]`; `get_name()` → a
localised non-empty label. It never connects to the database. During an outage (stale Viewer, 3)
the factory passes an `onUse` callback: the first `list_records()` or `search()` shows "The
directory is temporarily unavailable." - once per request for all books together
(`AddressBook::claimUnavailableNotice()`, shared with query failures); the sender-photo search
(`$required = 'photo'`, run for every opened message) never does.

## 4. Books *(decision)*

| Source ID | Viewers | Data scope | Default |
|---|---|---|---|
| `galbridge_domain` | `galbridge_domain_viewer_domains` (default `['*']`) minus `..._exclude` | `H`, or the organization containing `H` | on |
| `galbridge_global` | `galbridge_global_viewer_domains` minus `..._exclude` | `galbridge_global_data_domains` minus `..._exclude`, minus the viewer's domain-book scope unless `galbridge_global_include_home` | off |

* Source IDs never contain `-` (core splits `ID-source` at the last `-` in PHP and the first in JS)
  and avoid the reserved IDs `0 1 2 4 -1 sql`.
* `galbridge_organizations` = `['Acme' => ['acme.example', 'acme-de.example'], ...]`. Sets must be disjoint. A
  domain listed twice is a configuration error: the affected domains fall back to their own domain
  (fail closed) and diag reports FAIL. The key is the organization label (`organization` field).
* `*` in `galbridge_global_data_domains` (every hosted domain, including tenants added later) needs
  `galbridge_global_allow_wildcard = true` as well. diag warns while it is in effect (and when it is
  set without the second opt-in, which leaves the book empty).
* `searchonly`: `true`, `false` or `'auto'` (search-only when the data scope holds more than
  `galbridge_searchonly_threshold` entries - persons, alias accounts and lists - default 2000; one
  bounded COUNT, cached in `$_SESSION['galbridge_searchonly']` for the Viewer TTL, one entry per
  book; expired entries and those of another configuration fingerprint are dropped). Defaults:
  domain book `false`, global book `true`. A search-only book returns an empty set with core's
  "search only" notice when listed without a search or group, as `rcube_ldap` does.
* The global book uses **prefix** search mode whatever `addressbook_search_mode` says, matches
  e-mail addresses on the local part (or exactly in strict mode), never on a domain fragment,
  matches the `organization` field only when a search word equals the whole label (strict), and
  has a lower result cap. Otherwise a partial search for a domain or organization name returns a
  whole tenant.

## 5. Entities and visibility predicates

The predicates mirror what iRedMail treats as deliverable (Postfix `virtual_mailbox_maps`,
`virtual_mailbox_domains`, `transport_maps_maillist`).

* **Person**: `m.active = 1 AND m.enabledeliver = 1 AND d.active = 1 AND d.backupmx = 0`
  `AND canonical(m.username, m.domain)`
  [`AND m.expired > :now AND m.expired > LOCALTIMESTAMP AND d.expired > :now AND d.expired >
  LOCALTIMESTAMP` with `galbridge_hide_expired`, default on, `:now` bound from PHP in UTC]
  [`AND m.<galbridge_visibility_column> = 1`], minus `galbridge_hidden_addresses` /
  `galbridge_hidden_localparts`.
* *(decision)* **Canonical rows: SQL and PHP share one row rule.** `Normalizer::CANONICAL_ADDRESS`
  (local part `[a-z0-9_+=-]` in dot-atom form, at most 64 bytes; a host name of at least two labels
  of letters, digits and inner hyphens, at most 63 bytes each, the last starting with a letter or
  `xn--`; at most 254 bytes) is rendered for the engine by `QueryBuilder::canonicalAddressSql()` –
  PostgreSQL `octet_length = char_length`, `!~` the other characters (unanchored: a trailing newline
  is caught), `~` the ERE `QueryBuilder::ADDRESS_ERE`, the lengths and, with the domain column,
  `substr(address, strpos(address, '@') + 1) = domain`; MariaDB/MySQL the same with `LENGTH =
  CHAR_LENGTH` (keeps PCRE/ICU Unicode case folding of U+212A and U+017F out of the class),
  `[NOT] REGEXP` and `SUBSTRING_INDEX`; SQLite (tests) bound `GLOB` patterns – and is part of the
  root predicate of every kind (`canonical(f.address)` and `canonicalDomain(f.domain)` for the
  per-user aliases, whose domain column is the owner's domain). Every pattern is bound; the constant
  SQL holds no `?` and no backslash. `RecordMapper` applies `Normalizer::canonicalAddress()` to the
  same rows. The rule is a strict subset of `Normalizer::address()` and of core's
  `rcube_utils::check_email()` (unit test with a seeded corpus; the SQLite rendering and the ERE are
  compared with the PHP rule there, the engines with fixture rows of every class of character), so
  a row SQL offers is never dropped by PHP for its address – a drop means a defect or a row that
  changed between two statements, and then the full set is refused (`set_incomplete`). Before, the
  deny-lists in SQL compared `LOWER(column)` while PHP compared the NFC / lower-case / A-label form:
  a row in another spelling (NFD, a non-ASCII capital, a U-label domain column) escaped the hidden
  lists and exclusions in SQL, was counted, matched and grouped, and was dropped only by PHP (an
  existence oracle), and every row PHP rejected refused every full set of its scope. ASCII capitals
  follow the engine: MariaDB/MySQL (iRedMail's `utf8mb4_general_ci`) offer them – PHP lower-cases
  the address and the ID lookups of that collation find the row –, PostgreSQL and SQLite do not.
  Configuration lists, logins and IDs keep `Normalizer::address()`: a non-canonical ID decodes but
  finds no row. `diag` lists the rows no book offers (`entries not offered`).
* *(decision)* **Expiry by two clocks** (`QueryBuilder::unexpired()`, also for alias accounts,
  lists, alias domains and the viewer's own rows): the vmail `expired` columns are timestamps
  without a time zone, and which convention iRedAdmin writes them in is not something the plugin
  can rely on. An entry is visible only while it is later than UTC **and** later than the database
  server's clock in its session time zone (`LOCALTIMESTAMP`; SQLite in the tests: the local time of
  the process), so it disappears at its expiry under either convention – on a server east of UTC
  at the local expiry (1.0.1 compared with UTC only, which kept it visible for the offset), on a
  server west of UTC at the UTC instant. Both comparisons stay sargable.
* **Alias account** (`galbridge_include_aliases`, default on): `a.active = 1`, domain visible,
  `canonical(a.address, a.domain)`, `EXISTS` an active `forwardings` row with `is_list = 1`,
  accesspolicy (6).
* **Mailing list** (`galbridge_include_mailing_lists`, default on): `ml.active = 1`, domain visible,
  `canonical(ml.address, ml.domain)`, `EXISTS` an active `forwardings` row with `is_maillist = 1`,
  accesspolicy.
* **Per-user alias addresses** (`is_alias = 1`) are selected, displayed and searchable
  **only** with `galbridge_user_aliases` (default off). When off they are neither shown nor matched,
  so a search cannot reveal who owns an alias. When on, *(decision)* **one predicate decides both
  showing and matching** (`QueryBuilder::userAliasRoot()`): the alias row is active, its address is
  canonical and its domain column a canonical host name, its domain is in the book's scope, active,
  not a backup MX and (with `galbridge_hide_expired`) not expired, and the alias is on neither
  hidden list. Two predicates – one for the list of "other" addresses, one
  for the search – had drifted apart: a hidden alias was not shown but still found its owner. The
  viewer's own aliases are always loaded for accesspolicy (Viewer resolution).
* With `galbridge_hide_expired`, an alias account or list is listed only when its own `expired`
  column is readable and in the future; a role without that column gets no entries of that kind.
* Every forwardings / `moderators` / `maillist_owners` predicate is a semi-join (`EXISTS`/`IN`),
  never a JOIN, so rows never multiply (counts and paging stay exact). Display aliases are fetched
  by a second query keyed by the addresses of the current page (in batches of
  `Repository::DETAIL_BATCH` = 500 owners): *(decision)* the bound is **per owner** –
  `ROW_NUMBER() OVER (PARTITION BY f.forwarding ORDER BY f.address)` up to 21, the 21st row only
  telling that the list is cut (`aliases_capped`) – so an owner with thousands of aliases cannot take
  the aliases of any other entry of the page away (1.0.1 used up to four statements bounded by
  20 × owners rows, after which the rest of the page was shown without aliases). Window functions
  are a requirement: MariaDB 10.2, MySQL 8.0, PostgreSQL 8.4, SQLite 3.25 (diag FAIL on an older
  MariaDB/MySQL while `galbridge_user_aliases` is on).
* All of this is read from the **view `galbridge_forwardings`** (12), never from the table:
  forwarding targets (`is_forwarding`) are not in the view. Catch-all rows and external list
  members are never selected. The server builds the view as a whole for every statement (12), so
  the statements refer to it in uncorrelated semi-joins only – `a.address IN (SELECT f.address
  FROM galbridge_forwardings f WHERE ...)` (`Sql::inSelect()`), the member test of the membersonly
  policies included – and in the join of the per-user alias rows: computed once per statement,
  also inside the OR branches of `AccessPolicy`, where a correlated `EXISTS` became a sub-plan run
  for every outer row. The address columns are `NOT NULL`, so `IN` and `EXISTS` mean the same.

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

The table was compared with the iRedAPD sources (`plugins/sql_alias_access_policy.py` and
`plugins/sql_ml_access_policy.py`, release 6.4 and the main branch of 2026-11-24). In every branch
GAL Bridge is equal or stricter – it never shows an entry to a viewer whom iRedAPD would refuse:
iRedAPD also accepts senders of alias domains of the recipient domain (not applicable: a viewer's
login is a mailbox of a primary domain), counts inactive members of an alias account (GAL Bridge
requires an active member row), lets unknown policy values pass (hidden here) and, for mlmmj lists
with a member or moderator policy, leaves the decision to mlmmj, whose subscribers are not in SQL
(shown to moderators and owners only). `V` contains the addresses a user may send from under
iRedAPD's default sender check: the login and its own per-user aliases.

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
* Display sanitising (`Normalizer::displayText`, RecordMapper): NFC; C0/C1 controls, CR/LF, format
  characters (`\p{Cf}`: bidi, zero-width, soft hyphen, tag characters) and `\ " < >` removed;
  separators (`\p{Z}`) and blank-looking characters (U+2800, U+3164, U+115F, U+1160, U+FFA0)
  collapsed into one space; names capped at 128 characters. The display name, the first name and
  the last name are cut at the first at-sign (`@`, U+FF20, U+FE6B; `Normalizer::containsAt`): core
  builds recipient names from first and last name for `addressbook_name_listing` 1-3 and
  `contactlist_name_template`, so all three could impersonate another address. *(decision)* That
  check must see the text a user finally sees, so two notations that a later consumer **decodes**
  are neutralised first: `&` becomes U+FF06 (the recipient widget of the Elastic skin inserts names
  with `.html()` after escaping only `<` and `>`; `&#64;` or `&commat;` would be displayed as an
  at-sign and carried on into the recipient header) and `=?` and `?=` are split (an RFC 2047
  encoded word would be decoded by the mail clients of the recipients). The full-width ampersand
  keeps names such as "R&D" readable; searching with `&` still finds them, and so does the group
  lookup of the contact view (`get_record_groups()` compares the raw department key of a group in
  the notation of the record). *(decision)* **What core deletes later is deleted first.** A
  deletion joins the text on both sides of it: with a `contactlist_name_template` other than
  `{name}`, `rcube_addressbook::compose_search_name()` removes every empty bracket pair (`()`,
  `[]`, with the whitespace before it) from the composed name, so `=()?` would be `=?` again
  after the split. `displayText()` therefore removes empty pairs until none is left, and a text
  never starts with a closing or ends with an opening bracket (which would pair with a bracket of
  the template, `{name} ({department})`); only then are the notations split. Curly brackets become
  U+FF5B / U+FF5D (core fills a template placeholder by placeholder with `str_replace()`, so a
  `{department}` inside a name would be replaced as well and two texts joined). *(decision)* **The
  notations of Roundcube's suggestion list are neutralised too**: `ksearch_results_display()` puts
  each field into its template with `String.replace()`, the field being the replacement string, so
  `$&`, `` $` ``, `$'`, `$n` and `$<name>` would be expanded – `$` becomes U+FF04 –, and
  `ksearch_results_highlight()` turns `##…%%` into bold text without showing the markers – a run of
  `%` is split by blanks (`%%` never survives; a plain replacement would leave `%%` in `%%%`). A text
  never starts or ends with `=`, `?` or `%` (U+FF1D, U+FF1F, U+FF05), so two fields that a template
  puts next to each other (`{name}{department}`) cannot form `=?` / `?=` or `%%` either.
  `Normalizer::labelWord()` puts a search word into the same notation for group and organization
  labels. Every text that can reach a recipient name – name fields, department, phones, group
  labels and organization labels (`Config::organizations()`, a cut is reported) – is cut at an
  at-sign. *(decision)* Phones get no character allow-list: they are free text in iRedMail
  (extensions such as `wew. 12`), and an allow-list would drop legitimate values; they follow every
  rule above. An entry without a name – or with a name core takes for empty (`'0'`: `empty()` in
  `compose_list_name()`) – gets first and last name, else the local part of its address as the
  name, which goes the same way; with a canonical local part (letters, digits, `. _ + = -`) that
  name is never empty. Addresses are canonical (5): lower-case ASCII, no character a sink of core
  interprets (`&`, `#`, `?`, `%`, `{`, `}`, `/`, `$`, quotes – the suggestion list inserts the
  address as HTML, the contact view passes it to compose as a `mailto:` value), no non-ASCII
  look-alike, and they pass `rcube_utils::check_email()`.
* With `galbridge_mark_foreign_domains` (default on) a global-book entry outside the viewer's home
  domain(s) is shown as `(domain) Name` and carries **no** `firstname`/`surname`, so every listing
  mode of `compose_list_name()` falls back to the marked `name`. The marker is meant to be seen where
  recipients are chosen, so it is part of the recipient display name, the vCard `FN` and copies –
  and it comes first, because a recipient element shortens a long name with an ellipsis at its end.
  *(decision)* Two consequences are accepted with that position. (1) The marker protects the
  **name**: under a `contactlist_name_template` that shows another field of an entry before
  `{name}`, or has no `{name}`, text of the other domain would stand in front of the marker or be
  shown without one, so a foreign entry does not carry those fields there
  (`RecordMapper::textBeforeName()`: department, telephone numbers; nothing with the default
  template and with every template that starts with the name; diag WARN). *(decision)* **A field
  that is not shown is no criterion either**: `RecordMapper::foreignWithheld()` – first and last
  name always, department and phones under such a template – is the one list for the record and
  for `QueryBuilder` (Factory passes it to both). A search predicate on such a column applies to the
  viewer's home domains only (`m.domain IN (home)`); first and last name in a search of `name` also
  to an entry without a display name (`TRIM(m.name) = ''`), whose shown name they are; `required`
  fields likewise; a list sorted by first or last name orders a foreign entry by its name key
  (`CASE WHEN m.domain IN (home) THEN <key> ELSE <name key> END`, select-list parameters bound
  before those of the WHERE clause). Before, withholding was presentational only: a search or the
  sort order confirmed the values of fields the record did not carry. (2) Core sorts the
  autocomplete suggestions of all address books by name and the client pre-selects the first one.
  From Roundcube 1.7.6 it sorts with the ICU collator (`rcube_utils::asort()`), where `(` sorts
  before letters and digits, so marked entries are suggested before unmarked names; 1.7.0 – 1.7.5
  call PHP's `asort($keys, SORT_LOCALE_STRING)` under the C library locale of the user's language
  (`setlocale()` in `rcmail`), which usually ignores `(` at the first level – marked entries are
  interleaved by the domain inside the marker, and only the C locale puts them first. With
  `autocomplete_threads` the client appends the answer of each book in arrival order. A marker at
  the end would sort by name but can be cut off by the ellipsis – the reason it is not there. The
  order is documented (CONFIGURATION.md 3.3), described by diag for the core release in use
  (`Tool::coreSortsWithIcu()`: whether `rcube_utils::asort()` exists) while it applies, and
  avoidable with `galbridge_global_autocomplete = false`.

## 8. Groups *(decision)*

* `galbridge_domain_groups`: `auto` (default: `domain` when `H` belongs to an organization of more
  than one domain, else `department`), `department`, `domain`, `none`. `galbridge_global_groups`:
  `none` (default), `domain`, `department`. Grouping by department requires `department` in the
  effective fields (else forced to `none`, WARN `grouping_disabled`). **Search-only books (`true` or
  `'auto'`) have no groups at all**, so a group can never list a tenant without a search.
* Members are **persons only** (alias accounts and lists stay individual records), so counts are
  head counts and an expansion never sends to a list and its members twice.
* One aggregate query per request and book returns `ID`, label and member count (department key
  `LOWER(TRIM(department))`, label `MIN(TRIM(department))`, empty excluded), capped at
  `galbridge_max_groups` (50; above it the book has no groups). `get_group()` and a group `count()`
  without a filter are answered from this memo.
* *(decision)* **Department groups of the global book are per domain**: `GROUP BY m.domain,
  LOWER(TRIM(m.department))`, key `domain:department`, label `(domain) Department`; the member
  predicate is `m.domain = d AND LOWER(TRIM(m.department)) = k` for a domain in scope, and a key
  without `:` (a merged ID of 1.0.x) selects nothing. Equal department names of different tenants
  never form one group, so no tenant can add its accounts to a group whose name others use (a
  group expansion would send to them). A domain book keeps one group per department name over its
  domains: an organization is one trust unit declared by the administrator. The book-wide
  `galbridge_max_groups` stays the bound of the one aggregate statement – in the global book the
  number of department groups depends on every tenant's data (diag WARN recommends `domain`).
* `list_groups()` returns `virtual => true` groups, so the compose list renders drill-down rows
  without a COUNT per group. With a search term (autocomplete, compose search) it applies the
  minimum length and leaves out groups larger than the expansion cap, so a group that expansion
  would refuse is never offered.
* *(decision)* A department whose `LOWER(TRIM())` key holds a control character or a backslash is
  not offered as a group (`IdCodec::usableGroupKey()`, log `group_key_unusable`; its members stay in
  the book), and such a key is never decoded from a group ID: the group predicate binds the key, and
  no value with these characters is bound (12).
* Group IDs carry a book tag (`dd`/`dp` domain book, `gd`/`gp` global book) and the compact key
  encoding (9). `set_group()`: `0 '0' '' null false` clear the group; any other value is decoded,
  and an undecodable or foreign-book value installs a match-nothing predicate (and keeps
  `group_id`, so core's `get_group()` returns null). *(decision)* A well-formed ID is honoured only
  when the group is one of the book's aggregate: in a book that shows no groups (grouping `none`,
  search-only, more than `galbridge_max_groups`) it selects nothing. An ID that the book never
  offered would otherwise need a member count of its own – a second code path with its own limit
  arithmetic.

## 9. IDs *(decision)*

Core moves IDs through HTML ids, JS strings, comma lists, `ID-source` pairs and GET URLs (export of
selected contacts puts all of them into one URL). Safe charset `[A-Za-z0-9_]`; base64url is
rejected (`-`). Contact IDs are `<kind>_<enc>`: kinds `u` (person), `a` (alias account), `l`
(mailing list). `enc` keeps `[a-z0-9]` literally and escapes everything else with upper-case
markers: `.`→`D`, `@`→`A`, `-`→`H`, `_`→`U`, `+`→`P`, any other byte → `X` + two hex digits.
`jan.kowalski@firma.pl` → `u_janDkowalskiAfirmaDpl`. Decoding is strict and canonical (re-encoding
must give the input), never throws, accepts only strings up to 1 + 1 + 3·254 characters and a
plausible lower-case address; every lookup is scoped, and a miss and an out-of-scope ID give the
same answer (null or an empty set, the previous result cleared). Group IDs are
`<book><kind>_<enc>` (`d`/`g` for the domain or global book, `p` department, `d` domain) with the
same encoding of the group key (the department name in lower case, the domain, or
`domain:department` in the global book). Both kinds are **reversible**: they travel in GET URLs and
reach web server access logs, browser histories and proxy logs (SECURITY.md register GB-04).
*(decision)* Opaque IDs were rejected: up to `galbridge_max_ids` IDs travel in one GET URL
("export selected"), an ID must resolve without a mapping table or a session lookup, and Roundcube
sends quick searches and `_gid` as GET requests anyway, so the logs would still hold search words
and departments.

## 10. Search

* Fields map through FieldMap. `*` = all searchable effective fields. Quick search ignores unknown
  fields; advanced search (array value, AND semantics) with any unknown or `nosearch` field matches
  nothing. An unsupported `$required` field (core passes `photo` for the sender photo of every
  opened message) returns an empty set **without a query**. A supported one means "the record
  shows the field": a text column must be non-empty after trimming, the birthday a real date
  (`m.birthday >= '0002-01-01'`: year 0001 is iRedMail's "none", as in `RecordMapper`, and a date is
  never compared with text – PostgreSQL refuses that).
* Modes `$mode & 3`: partial `%w%`, prefix `w%` or a word start, strict = whole value. The input is
  NFC-normalised, whitespace (tab, newline, NEL, ...) collapsed, lower-cased, split into at most 5
  words; every word must match at least one field. Input with any other control character (C0,
  DEL, C1) or a backslash is not searched at all – it could never match displayed text, and bound
  into the statement it would make PDO's scanner and the server disagree (12).
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
* `galbridge_*_autocomplete` per book is enforced in `search()` and in `list_groups()` for
  `_action=autocomplete` (core accepts any `_source` there and also asks every source for groups).
* In the global book the `organization` field matches only a search word equal to the whole label
  (4).

## 11. Paging, counts, limits

* `set_page()` is clamped to 1..`AddressBook::MAX_PAGE` (1000001) and `set_pagesize()` to
  1..1000000, compared as floats first: an out-of-range request value is never cast to an int (PHP
  8.5 warns), and the largest offset stays below 10^9. Normal page sizes are those ≤
  `Limits::corePageCap` = max(core `max_pagesize` (default 200), `addressbook_pagesize` – or
  `pagesize` without it, as core's lists read it), bounded to 10–1000 (`Limits::HARD_PAGE_CAP`):
  core clamps `addressbook_pagesize` to `max_pagesize` only when a user saves the preference, and
  its lists use the configured value as it is. *(decision)* **A full-set request is recognised by
  core's own sizes**: page 1 with a page size of 9999 (search lists, group expansion) or 99999
  (exports), a search with a page size above `corePageCap`, and a group in the recipient actions
  (`mailto`, `group-expand`, whatever size core passes – `max_group_members` or 999). Any other
  page size above `corePageCap` is a **page** of `corePageCap` entries with the notice
  `pagetoolarge` – never an export or a group expansion (1.0.1 took every size above the cap on
  page 1 for a full set, so a browse page with `max_pagesize = 0` or an `addressbook_pagesize`
  above 1000 met the export policy or the group cap). The call sites are identical in core 1.7.0,
  1.7.5, 1.7.6 and 1.7.7. Full sets:
  * *(decision)* **a full set is read by one statement with `LIMIT cap + 1`**
    (`Repository::fullSet`), where `cap` is the limit of the operation. More than `cap` rows: the
    set is refused. Otherwise it is complete by construction – it depends neither on a count taken
    by an earlier statement nor on `galbridge_max_count`, which bounds counting only. (1.0.0
    compared a count bounded by `galbridge_max_count` with the limit of the operation; with
    `max_count <= max_export` a larger book was exported cut to `max_count` entries.) When a fetched
    row cannot be delivered (the row re-check drops it), the set is refused as well
    (`galbridge.incomplete`, log `set_incomplete`);
  * group, no filter (compose-to-group, group expansion, group export; `mailto` and `group-expand`
    are recipient sets whatever page size core passes, e.g. `max_group_members`): above
    min(`galbridge_max_group_members`, core `max_group_members` if set) → empty result, `set_error()`,
    a visible warning (`galbridge.grouptoolarge`), log; otherwise **all** members;
  * ID search (compose-to-selected, export of selected): more than `galbridge_max_ids` (200) IDs →
    empty + warning, never a prefix; *(decision)* the selection is read by one statement (`fullSet`
    with `galbridge_max_ids` + 1 rows), and in the actions `mailto` and `export`
    (`AddressBook::SELECTION_ACTIONS`) it is **complete or refused**: an ID that cannot be decoded,
    that finds no visible entry (gone, foreign tenant, forged) or whose row the re-check dropped
    refuses the whole selection (`incomplete`, log `set_incomplete missing=N dropped=N`; the export
    answers 204). Other actions (showing contacts by ID) keep what was found;
  * search lists: capped at `galbridge_max_results` (domain book, 500) / `galbridge_global_max_results`
    (global book, 100) with a "showing first N, refine your search" notice when truncated – in the
    `export` action the same search is complete or refused (`exporttoolarge`), because a file
    cannot carry that notice;
  * whole-book export (no filter, no group): refused above `galbridge_max_export` (2000) or when the
    book has `export => false` (global default).
  * *(decision)* a refusal during `addressbook/export` cannot be shown (the export is a download that
    ends with `sendExit()`, so queued messages are discarded). The notify callback records it
    instead, and the `addressbook_export` hook answers `204 No Content`: the browser saves no file
    (also not the contacts of other books in the same export) instead of an empty `contacts.vcf`.
    The same happens when the directory fails during an export (`unavailable`): a file without the
    directory's entries would look complete.
* `count()` = `COUNT` over the same root predicate (persons + aliases + lists, each unique by primary
  key), bounded by `galbridge_max_count` (10000) – for a search by the result cap of the book as
  well – and silent. A list page counts up to that bound plus one: at the bound the total is the
  bound and the user is told (`listcapped` "Only the first N directory entries can be browsed",
  for a search `toomanyresults`); offsets at or beyond the bound return an empty page with the same
  notice, and a page never reaches past it. 1.0.1 capped silently, and the pages of a search result
  were not bound by the result cap at all.
* *(decision)* **Contacts read one by one are bounded per request**: core's copy action calls
  `get_record()` for every ID of the request (one page and one details statement each).
  `LookupBudget` – one per request (Factory), shared by every book – allows `galbridge_max_ids`
  lookups that read the directory; beyond it `get_record()` / `get_record_groups()` return nothing
  with the notice `toomanyids` (once) and log `too_many_ids`. An undecodable ID costs nothing.
* *(decision)* **Full sets read their details in batches** of `Repository::DETAIL_BATCH` (500)
  addresses: core's `rcube_db` inlines bound values one after the other into the statement text,
  rewriting it each time, so one statement with the 100000 addresses of a full set at the upper
  bound of the limits would cost quadratic time and memory in PHP. diag warns when
  `galbridge_max_export` or the group cap exceeds 10000 (one request holds that many records).
* Sort keys per `sort_col` with fallbacks over present columns only: surname →
  `COALESCE(NULLIF(TRIM(last_name),''), NULLIF(TRIM(name),''), username)`, likewise firstname;
  name → `COALESCE(NULLIF(TRIM(name),''), username)`; address as tie-break. Core re-sorts search
  results by `addressbook_sort_col` in PHP: README recommends `addressbook_sort_col = 'name'` on
  iRedMail (open-source iRedAdmin fills only `name`); diag warns.
* *(decision)* **No rate limiter.** A `$_SESSION` counter is not atomic (parallel requests, which core
  itself sends for autocomplete, all pass) and turns read-only requests into session writers that
  can overwrite concurrent compose state. Resource control = result caps, minimum length, the
  connect timeout, the read timeout of the MariaDB/MySQL connection, the statement timeout of every
  plugin session (`galbridge_db_timeout`, on every engine; a server that refuses it is not used),
  the lookups and statement sizes of one request (above), the connection limit of the database
  account (set by the grant templates for both engines and reported by diag; the role's statement
  time there is a default for other sessions only – MySQL has none; a refusal at that limit fails
  only the request, 12), and per-client request-rate and concurrency limits on every Roundcube PHP
  request in the reverse proxy, the concurrency limit below the role's connection limit (INSTALLATION.md 1,
  CONFIGURATION.md 10; register entry GB-26 in SECURITY.md) – a limit keyed on the query-string `_action` alone
  misses the POST-body and cookie forms core also reads. Throttling the plugin's log lines per
  session was rejected for the same reason (session writes) and because it would hide repeated
  probing: a reason code is logged once per request (14). APCu or another shared store is optional
  and per PHP-FPM pool – no guarantee Roundcube gives a plugin.

## 12. Database access

* `galbridge_db_dsn` is mandatory; no fallback to Roundcube's connection (PostgreSQL cannot query
  across databases, and iRedMail's `roundcube` role can UPDATE `vmail.mailbox`).
* Dsn policy: drivers `mysql`, `mysqli`, `pgsql` (`sqlite` only under the test bootstrap).
  *(decision)* The DSN is judged **in the form the driver receives it**: `rcube_db::parse_dsn()`
  lets a query parameter fill a key the URL part left empty and passes values into the driver's
  connection string unquoted, so the policy first checks the raw parameters – names from a
  per-driver allow-list, `name=value` form, no repetition, values matching their pattern – and then
  the parsed target: an explicit host or a non-empty unix socket (never both, never neither: the
  client library would choose), a port of digits, a plain database name. The `service` parameter
  is refused, and for PostgreSQL the client-library environment that redirects a connection
  (`PGHOSTADDR`, `PGSERVICE`, `PGSERVICEFILE`; `PGPORT` without a port in the DSN).
* *(decision)* **The driver receives the judged DSN, rebuilt.** 1.0.1 judged the parsed DSN but
  handed the driver the configured text plus `?timeout=N`; where the core parser drops text
  silently, the two differed (a `?` before the database made the timeout part of the database name
  and left the driver without a connect timeout; a second port, text around brackets and the
  `unix+`/`tcp()` notations were silently changed). Now the raw text must follow one grammar –
  target `host`, `host:port`, `[IPv6]`, `[IPv6]:port` or `unix(/path[:port])`, `/`, the database
  name as the driver receives it, `?parameters` (`Dsn::checkForm()`, after the specific checks so
  their messages stay precise; a parameter the parser did not read is refused as well) – and
  `Dsn::canonical()` rebuilds the DSN from the judged parts (credentials and values
  percent-encoded, the parameters in the order written, the timeout last); `rcube_db::parse_dsn()`
  must read exactly those parts back, or the DSN is refused. The scheme is kept as written (the
  phptype the parser reports). Socket paths fit `sun_path` (107 bytes; PostgreSQL including
  `/.s.PGSQL.<port>`). MariaDB/MySQL `localhost` is the socket of `pdo_mysql.default_socket` (an
  absolute path that fits, else refused; `describe()` shows it, diag checks its directory), and a
  port next to it or inside `unix()` – ignored by the driver – is refused; `localhost` is not
  rewritten into `unix(…)` (pdo_mysql uses the same ini value). PostgreSQL `localhost` is looked up
  by the system resolver on every connection: accepted as local with a warning, so existing
  installations keep working.
* *(decision)* **The driver class is created directly** (`rcube_db_mysql` / `rcube_db_pgsql` /
  `rcube_db_sqlite` by a `match` on the judged driver), not through `rcube_db::factory()`, which
  derives the class from the DSN text and ends the PHP process on an unknown driver; creating and
  connecting run in a `try` that turns any `Throwable` (e.g. a PDO MySQL constant missing from a
  `pdo_mysql` without `mysqlnd`) into a memoised `db_down cause=driver_error` with the reason in
  `lastError()`.
  Refused roles (default): `vmail`, `vmailadmin`, `roundcube`,
  `sogo`, `iredadmin`, `iredapd`, `amavisd`, `fail2ban`, `root`, `postgres`, plus the `db_dsnw` user
  and the password plugin's DSN user. Loopback = unix socket, 127.0.0.0/8, `::1`, `localhost` (see
  above for what `localhost` means per driver); any other host needs TLS that verifies the host name (PostgreSQL `sslmode=verify-full`; MySQL
  `ca`/`capath` + `verify_server_cert=1`). `sslmode=verify-ca` checks the issuer only and needs
  `galbridge_db_allow_verify_ca`; anything weaker needs `galbridge_db_allow_plaintext` (both: log
  `dsn_warning`, diag WARN). A connect `timeout`
  (`galbridge_db_connect_timeout`, 2 s) is injected when the DSN has none; a `timeout` written into
  the DSN must be 1..30 s (rcube_db keeps the first occurrence, so it is validated, never appended).
  Note: in a web request `rcube_db::conn_create()` itself logs a failed connect with the driver
  text (role and host, never the password) to Roundcube's errors log, whatever `ignore_errors` says;
  in the CLI core writes it to stderr only, so `Connection::lastError()` keeps the sanitised text and
  diag prints it.
* *(decision)* **Read timeout (MariaDB/MySQL).** `PDO::ATTR_TIMEOUT` bounds the TCP connection only;
  mysqlnd waits for the greeting and every later answer up to `mysqlnd.net_read_timeout` (86400 s),
  and PDO has no attribute for it. `Connection` therefore sets that ini value to `readTimeout()` –
  max(`galbridge_db_timeout`, connect timeout) + 2 s, so the server's statement limit always ends
  first – while it creates and connects the driver, and restores it in `finally`: mysqlnd copies the
  value into the connection, so the plugin's connection keeps it and nothing else in the request is
  affected. A lower php.ini value is never raised. Without mysqlnd, or when `ini_set` is disabled or
  the value is fixed by `php_admin_value`, it logs `read_timeout_unavailable` /
  `read_timeout_not_applied` once and goes on; diag shows the value. A statement that fails because
  the connection is gone (MySQL 2006/2013/4031, SQLSTATE class 08 or 57P01–57P03, libpq's
  "server closed the connection" texts) closes the handle and is memoised like a failed connect
  (`db_down cause=lost`). libpq has no read timeout after connecting: PHP-FPM's
  `request_terminate_timeout` is the bound there (SECURITY.md).
* After connecting: read-only session and statement timeout (`galbridge_db_timeout`, 3 s;
  MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`). This is
  the statement bound of the plugin on every engine: the statement time the grant templates give
  the role is a default that any session may change (and MySQL has none). *(decision)* A server
  that refuses either setting is not used (fail closed): the connection is closed,
  `session_limit_refused` and `db_down cause=session_limit` are logged and the outage is memoised;
  `lastError()` names the statement for diag. diag reads the session's statement time after the
  plugin set it and fails when it is not `galbridge_db_timeout` (e.g. behind a pooler that resets
  session settings). The value is not clamped to the role default: that default is not a boundary,
  and clamping would silently override an explicit setting.
* *(decision)* **No NUL byte or backslash is ever bound.** `rcube_db` inlines bound values with
  `PDO::quote()` and PDO scans the finished text again for placeholders; its scanner treats a
  backslash as an escape where the server does not (PostgreSQL with PHP 8.1–8.3, MySQL without
  backslash escapes), and libpq ends a quoted value at a NUL byte. Every producer of values refuses
  them (search words: 10; group keys: 8; addresses, domains and local parts are validated), and
  `Connection::select()` refuses any string parameter with a NUL byte or a backslash before it
  sends the statement (`db_error`, log `unsafe_parameter`) – the same kind of backstop as the column
  expression check of `Sql`.
* *(decision)* **The view `galbridge_forwardings`.** iRedMail keeps four kinds of rows in one table:
  per-user aliases, members of alias accounts, mailing-list rows – and the private forwarding
  targets of every mailbox. Column grants cannot separate rows, so a role with `SELECT` on that
  table can read every target, although the plugin never selects one. The grant set therefore
  creates one view with the three kinds of rows the plugin needs (MariaDB/MySQL
  `SQL SECURITY DEFINER`, PostgreSQL `security_barrier`) and grants the role the view instead of
  the table; all statements of the plugin name the view (`Schema::FORWARDINGS`). It is the only
  object GAL Bridge adds to `vmail`: it stores nothing and is re-created by every run of the grant
  set. The alternative – keeping the table grant and correcting the documentation – would have left
  the defence in depth weaker than the threat model says.
* *(decision)* **The view is materialised and fenced** (second verification pass). A merged view
  hands the conditions of a query to the table: on MariaDB 11.8 a condition on `forwarding` became an
  index condition evaluated on every row, private ones included, before the view's flag filter;
  `ALGORITHM = TEMPTABLE` alone still let the server push it into the derived table
  (`condition_pushdown_for_derived`; MySQL 8.0.22+ `derived_condition_pushdown`), and PostgreSQL's
  `security_barrier` lets quals it calls leakproof (the equality on `forwarding`) below the barrier,
  where `EXPLAIN ANALYZE` counts confirm a guessed target. A `LIMIT` / `OFFSET` makes a derived table
  a fence on both engines: the server may not move a condition into it, because that could change
  its result. So: MariaDB/MySQL `ALGORITHM = TEMPTABLE` plus `LIMIT 18446744073709551615` (MySQL's
  "all rows"), PostgreSQL `security_barrier` plus `OFFSET 0` (`WITH ... AS MATERIALIZED` would need
  PostgreSQL 12 and break EL8's 10). The price is that every statement builds the view's rows (the
  alias and list rows, by the flag indexes) – hence the uncorrelated semi-joins (5). A
  helper table maintained by the mail server was the alternative; it would need triggers or jobs in
  `vmail`, which the plugin does not own. On PostgreSQL the grant set **drops and creates** the view
  in its transaction (a fresh object of the administrator: no grant, owner, rule or comment of an
  earlier view survives; a table of that name or a dependent object stops the transaction);
  MariaDB/MySQL's `CREATE OR REPLACE` replaces definer, algorithm and security type, and grants there
  are bound to names. MariaDB/MySQL also grant `SHOW VIEW` on the view: the role cannot read a view
  definition otherwise, and `diag` needs it to see the `LIMIT` fence (`EXPLAIN` still needs a
  privilege on the table).
* **Schema and privilege probe** (once per Viewer TTL, three statements): `information_schema.columns`
  for every relation of the schema / database (`Connection::visibleColumnsEverywhere()`; the Viewer
  keeps those of the six tables and the view GAL Bridge reads, `Connection::TABLES`, and of the
  table it must not read, `Connection::FORBIDDEN_TABLES`). MariaDB and PostgreSQL list only columns
  the role can access, so this yields "present and granted". Then `RoleScope::read()` – ONE catalog
  statement per engine on catalogs the role may read: PostgreSQL `pg_roles` (attributes),
  `pg_auth_members` (memberships), `pg_database` (owner, other databases it may connect to),
  `pg_shdepend` (owned objects; privileges on objects of other databases and on other databases;
  privileges granted to the role on objects of this database that are no relations, named by
  `pg_identify_object()`), `has_*_privilege()` on every relation and sequence (writes in the schema,
  privileges in other schemas, `PUBLIC` included), `has_schema_privilege()` for `CREATE` on every
  other schema, and `pg_class` / `pg_rewrite` / `pg_depend` / `pg_get_viewdef()` for
  the view object; MariaDB/MySQL `USER_PRIVILEGES`, `SCHEMA_` / `TABLE_` / `COLUMN_PRIVILEGES`,
  `SCHEMATA`, `APPLICABLE_ROLES` (MySQL from 8.0.19; older: "unknown", never a refusal) and
  `TABLES` / `VIEWS` (`ALGORITHM` on MariaDB only). Finally one bounded statement through the view:
  a row that is none of the three kinds (`ViewerResolver::OTHER_KIND`, a NULL flag included).
  *(decision)* **What the role can do beyond reading the directory is refused**: a secret column
  (`password`, `recovery_email`, `allow_nets`) in ANY relation, the `forwardings` table, a role
  attribute, a membership (a NOINHERIT role reaches its groups with `SET ROLE`), the ownership of
  the database, a global privilege, a privilege outside the directory, a privilege to change
  anything, a view with other rows: books become DeniedBooks, ERROR `role_too_broad` with the
  reasons; `diag` fails for each (one decision in `RoleScope::refusals()`). CREATE on the role's own
  schema or the database (and `PUBLIC`'s old CREATE on schema `public`) and owned objects are only
  logged (`role_can_create`), an unfenced view too (`view_not_materialised`):
  every PostgreSQL database created before version 15 grants CREATE to `PUBLIC`, and every 1.0.1
  view is unfenced – refusing them would take the books away from every such installation until the
  grant set is applied again, while what they open needs the role's password; `diag` fails for them.
  *(decision)* The same holds for a **MariaDB/MySQL server-wide test database**: a visible database
  named exactly `test` or starting with `test_` (compared as bytes) on which the account's own
  grants hold nothing (no `SCHEMA_PRIVILEGES` pattern of the account matches it, no table or column
  grant there) is what `mariadb-install-db` opens to every account (MariaDB 10.11 and later through
  `PUBLIC`, older servers through blank-user rows of `mysql.db`; RHEL and its rebuilds, FreeBSD, the
  official tarballs). `RoleScope` lists it apart (`serverTestDatabases`, not `outside`): a warning
  (`server_test_database`, once per renewal of the viewer cache) and a `diag` FAIL of its own
  ("server-wide test database", naming `mariadb-secure-installation` and the statements), never
  `role_too_broad` – otherwise every book would vanish on each such installation although the role
  holds nothing there. Any other visible database, and a test database the account holds a grant
  on, stay refusals.
  The required and optional columns come from `Schema` (one list for the resolver, the factory and
  diag); `Schema::GRANTABLE` is exactly what the templates grant (a unit test compares them), and
  `diag` names every other visible column.
* `ignore_errors` is on, so core never logs SQL with search values; errors are logged with the
  SQLSTATE only (driver text with `galbridge_debug`). The book calls `set_error()`, returns an empty
  result and shows "The directory is temporarily unavailable." at most once per request (core's
  read actions never call `get_error()`). A failed connection is memoised for the request. *(decision)*
  For 30 s in the session (log `db_down retry_in=30 cause=…`) only what spares the next requests a
  wait: a connection failure of an unreachable or slow server (the attempt took at least half of
  the connect timeout, or the error is one of `Connection::UNREACHABLE`: MySQL client errors
  2002/2003/2005/2006/2013, "connection refused", "timeout expired", an unknown host ...), a driver
  error while connecting, a refused session setting and a lost connection. A refusal the server
  answers at once is not memoised for the session: the connection limit (`db_busy`, also
  PostgreSQL's "remaining connection slots are reserved") and any other fast answer – an
  authentication error, a limit message in the server's `lc_messages` (`db_down cause=refused`,
  without `retry_in`) – so a user who holds the role's connections cannot take the directory away
  from other sessions for 30 s, whatever the server's language.
* Identifiers in backticks (rewritten for PostgreSQL by `rcube_db`), values always bound, no `?`
  or backtick inside literals, no `table_name()` for vmail tables, never `SELECT *`.

## 13. Configuration guard and normalisation

* Roundcube merges stored user preferences over the configuration. `GalBridge\Config` ignores every
  preference named `galbridge_*` and reads the administrator value from a **second reading** of the
  configuration files, as MIME Shield does: a fresh `rcube_config` plus the plugin's files.
  Core options a user controls (`addressbook_pagesize`, `addressbook_sort_col`,
  `addressbook_search_mode`, `contactlist_fields`, `autocomplete_addressbooks`) are clamped or
  whitelisted.
* *(decision)* **One routine for the plugin's files.** `Config::pluginFiles($resolver, $home,
  $name)` returns what `rcube_plugin::load_config()` loads: `$home/config.inc.php` when it exists
  (then nothing else), otherwise `<name>.inc.php` from every configuration directory – resolved by
  Roundcube's own configuration object, so its environment suffix (`config-<env>.inc.php`) applies,
  and with the plugins directory slashed like the core does it (`Config::pluginsDir()`: a
  `RCUBE_PLUGINS_DIR` without the trailing slash finds the same files). `Config::loadFiles()` merges
  them and returns the files that exist but did not load (an unreadable file is not handed to
  `rcube_config`, which would warn a second time). The second reading, the password plugin's DSN,
  the host-independent configuration and diag (loading, file checks, per-host files) all use it.
  `Config` is given the plugin's own directory (`rcube_plugin::$home`; diag passes the directory it
  works with), never derives it from `RCUBE_PLUGINS_DIR` when it is known.
* *(decision)* **The policy in effect is the one written down, or there is none.**
  `Config::configUnreliable()` – part of `booksDisabled()`, and the first check of
  `ViewerResolver::resolve()`, before any connection – is true for (a) a plugin configuration file
  that exists but did not load into the second reading (unreadable for the PHP user, no `$config`
  array: Roundcube logs error 527 and goes on without it) and a `galbridge.inc.php` of a
  configuration directory that Roundcube ignores because `plugins/galbridge/config.inc.php` exists
  (`Config::configFiles()` / `fileProblems()`; not evaluated with injected test values); (b) a
  `galbridge_*` key of the second reading or of Roundcube's object without the user's preferences,
  or a `ROUNDCUBE_GALBRIDGE_*` variable, that is no option of this version – the variable only in
  the upper-case spelling Roundcube reads (`Config::unknownOptions()`, `fail` problem with the
  closest option by `levenshtein()` ≤ 3; a name in `Config::RETIRED` is an `error` only); (c) an
  option that depends on the requested host name (`hostDependentOptions()`, below). Each of them
  would otherwise leave built-in defaults or the other file in force – for a deny-list "nothing
  hidden" – without a sign.
* *(decision)* **Core options as Roundcube applies them.** The second reading has no environment
  name and misses what a file pulled in with `include_once`/`require_once` set (it runs once per
  process). `Config::coreValue()` therefore reads `imap_host`, `include_host_config`,
  `imap_conn_options`, `des_key`, `db_dsnw` and `plugins` from Roundcube's own object, and from the
  second reading only for a name a stored preference shadows (also through the legacy name
  `default_host`). `Config::divergentOptions()` names (never prints) the options – every
  `galbridge_*` and `Config::CORE_OPTIONS` – the two readings disagree on: diag shows them, and a
  derived IMAP binding is refused when `imap_host` is one of them. `Config::hostConfigEnabled()` is
  true when either reading enables `include_host_config` or the variable is set (a preference cannot:
  the core picks the files before it knows the user). `Config::coreDsns()` collects `db_dsnw`,
  `db_dsnr` and `password_db_dsn` from both readings and the password plugin's file; `Dsn` refuses
  the user of every one.
* *(decision)* **No policy from a per-host file.** With `include_host_config` the core merges the
  file named by the request's `Host` header – the client's choice – before the plugin's file. The
  plugin reproduces the host-independent configuration (`Config::hostDependentOptions()`: a fresh
  `rcube_config` whose `galbridge_*` options are wiped, then Roundcube's `config.inc.php` – or
  `main.inc.php` and `db.inc.php` – and the plugin's files again) and compares every `galbridge_*`
  option of the request with it; a difference is a `fail` problem per option and
  `booksDisabled()`, and the resolver refuses the request before the database. Only the two book
  names may differ; an option set by a `ROUNDCUBE_<OPTION>` variable is the same for every host.
  Ignoring the per-host value instead could drop a restriction the administrator meant, so the
  books fail closed. Without `include_host_config` nothing is compared (and nothing extra is read).
* One `Normalizer` for config lists, the login, IDs and row re-checks: trim, NFC, lower case, split
  at the last `@`, IDNA to ASCII (non-ASCII input without ext-intl fails), trailing dot removed,
  validated. Lists must be arrays; `*` only as the sole element. A failed **allow**-list entry is
  dropped (log ERROR `config_invalid`, diag WARN); a failed **deny**-list entry disables the
  affected book (fail closed, diag FAIL). `QueryBuilder::forBook()` refuses a malformed deny-list or
  visibility column (`config_invalid`), so no caller can turn the fail-closed `null` into "no
  restriction".
* *(decision)* **Values are typed, never cast.** PHP reads the string `'false'` as true and an
  array as "not a string, so empty". A switch is therefore a boolean or the integer 1 / 0 (the
  `searchonly` options also `'auto'`), the visibility column a string, the lists arrays; anything
  else is a reported problem. An ambiguous switch, a visibility column of the wrong type and a
  malformed hidden list make `Config::booksDisabled()` true: `Entitlements` then offers no book and
  `QueryBuilder::forBook()` refuses to build one; the ambiguous switch itself is read as its
  restrictive value meanwhile (`Config::RESTRICTIVE`: off, except `hide_expired` and
  `mark_foreign_domains`) – before, `bool()` returned the default, which for `domain_book`,
  `domain_autocomplete`, `domain_export`, `global_autocomplete`, `include_aliases` and
  `include_mailing_lists` is the permissive value. A malformed domain deny-list disables the book
  it protects (above); a malformed `galbridge_db_refused_users` refuses every connection (below).
  Roundcube applies environment overrides (`ROUNDCUBE_<OPTION>`) on every read and converts the
  text `false` into the boolean true; for its switches `Config` reads the variable itself and
  accepts `1`, `0`, `true`, `false` (`auto`) only. diag shows the effective value of every switch
  (`Config::effectiveSwitch()`) and the overrides in effect. `galbridge_fields` of another type
  than a list is reported too and leaves name and e-mail. A number is a whole number – an integer,
  or a string of digits; a `ROUNDCUBE_<OPTION>` variable is judged by its text, because Roundcube
  makes a float of `-5` or `1e3` – within `Config::RANGES`, the single range every caller of
  `Config::int()` passes (a unit test compares the call sites) and that `validate()` checks:
  anything else is an `error` problem and the default, a value outside the range an `error`
  problem and the clamped value. `galbridge_db_refused_users` adds to the built-in names
  (`Config::refusedUsers()` never returns fewer); a value that is not a list of role names makes
  it return `null`, and `Dsn` then refuses every connection.
* Problems name the option and, for a rejected list entry, its **position** (`entry 3`,
  `organization 2: entries 1, 4`), never the entry: they are logged at ERROR level, and an entry of
  a hidden list is a person the administrator wanted hidden.
* The user-preference guard (`Config::get()`: a stored preference named `galbridge_*` never
  changes an administrator option) answers from a fresh `rcube_config`. For an option that no
  configuration file sets it asks that fresh configuration with the built-in default, so an
  environment override applies as it does without the preference; `rcube_config::all()` alone
  would miss it, because it applies the variables to the options of the files only.
* The configuration fingerprint binds the Viewer cache and stored search specs. It is an HMAC over
  the effective admin values – the DSN **without its password** (driver, user, host, port, socket,
  database and parameters as `rcube_db::parse_dsn()` reads them) –, `imap_host` as Roundcube
  applies it and whether `include_host_config` is in effect, keyed with the `des_key` Roundcube
  itself uses (`coreValue()`): the session store holds nothing derived from the database password,
  whatever `des_key` is, and any other change of the configuration still voids the cache at once
  (nothing cached depends on the password).
  Switches enter it as they are in effect, not as Roundcube converts an environment override
  (`true` for "true" and "false" alike).

## 14. Logging

Channel `galbridge` (file `<log_dir>/galbridge.log`; core appends `log_file_ext`). Normal levels:
reason codes (`denied_source`, `invalid_gid`, `invalid_id`, `group_too_large`, `too_many_ids`,
`export_disabled`, `export_too_large`, `set_incomplete`, `too_many_groups`, `role_too_broad`,
`column_missing`, `field_unavailable`, `grouping_disabled`, `row_invalid`, `row_out_of_scope`,
`viewer_revoked`, `too_many_aliases`, `aliases_capped`, `db_error` + SQLSTATE,
`db_down`, `config_invalid`, `dsn_warning`, `env_override` (names of the `ROUNDCUBE_GALBRIDGE_*`
variables in effect, once per session), `host_mismatch` with the server names `host=`,
`mail_host=`, `allowed=`), the numeric Roundcube user id, book ids, counts. Reason codes triggered by
request input or directory rows are logged at most once per request (`Log::once`) – a bound per
request, not per session: a loop of requests writes one line per reason code per request, limited
by the reverse proxy (11); `db_error` is logged per failed query. A line is `LEVEL operation: message key=value ...`; a value with a blank,
`=`, `"` or `\` is written in double quotes (escaped), a key is a code constant. Every value passes
`Log::sanitize()`: invalid UTF-8 redacts it as a whole, every control and format character (`Cc`,
`Cf`) and line separator becomes a blank, `Log::maskCredentials()` masks passwords (URL form with
blanks, `@`, `/` in the password or `/` in the user, `scheme(syntax)://`, `password=` /
`PGPASSWORD=` pairs, `"password":`; a text the patterns cannot handle is replaced as a whole), and
the value is cut to 300 characters; parameters that carry a secret are `#[\SensitiveParameter]`.
`config_invalid` names the position of a rejected list entry, never the entry. An autocomplete
request that names no source asks every book the plugin wired, so the refusal of an enabled book the
user is not entitled to is logged at debug level only there (`denied_source` stays INFO for a
request that names the book, `_source`, and for every other action). Search terms, addresses and names are never logged by the plugin;
`galbridge_debug` adds only the driver's error text, exception messages and schema hints (sanitised,
not filtered for personal data). Note: `rcube_db` inlines bound values on the client side, so
database server logs and driver error texts can contain search terms. The PostgreSQL grant set
switches statement logging off for the role (`log_statement`, `log_min_duration_statement`,
`log_min_error_statement` and the sampled logging of PostgreSQL 12 / 13 and later,
`log_transaction_sample_rate` and `log_min_duration_sample`); MariaDB/MySQL have no per-account
setting, diag reports the general query log (SECURITY.md, Logs). On PostgreSQL diag also warns
while an extension would log the role's statements (`pgaudit.log`, `auto_explain.log_min_duration`,
read with `current_setting(..., true)`), which the grant set does not change; the price of the
role's settings is that there is no statement audit trail of the role (SECURITY.md register GB-21).

*(decision)* **An ERROR or WARN line is never dropped silently.** `rcube::write_log()` returns false
when it does not write – with `per_user_logging` for a request without a user or a user without a
log directory, for a file that cannot be written – and Roundcube's own code ignores that. `Log`
then writes an ERROR or WARN line to Roundcube's `errors` log (`galbridge: ` + the line; with
`per_user_logging` that log falls back to the main `log_dir`), and to PHP's `error_log()` when that
fails too. INFO and DEBUG lines follow Roundcube's decision (per-user logging is the operator's
choice). Under `log_driver = 'syslog'` core gives every line of a log other than `errors` the
priority `LOG_INFO`; the level is the word in the line.

## 15. Hooks, actions, CLI

| Hook | Purpose |
|---|---|
| `addressbooks_list` | advertise entitled books |
| `addressbook_get` | instantiate `AddressBook` or `DeniedBook` for our IDs |
| `addressbook_export` | registered for every request of the `addressbook` task: `204 No Content` when a book refused the export of this request (11) – it depends on no spelling of the action and on no plugin that changes it after `init()` |
| `contact_exists` | registered in the `mail` task: removes the GAL Bridge books from the sources of `rcmail::contact_exists()` – a `collected_senders` / `collected_recipients` preference that names one (core saves them unchecked) would make every directory address a known contact; no database access |
| `preferences_update` | registered in the `settings` task only (the only hook there): removes every `galbridge_*` key from the preferences `rcube_user::save_prefs()` stores and from the stored ones it merges them with. Roundcube writes the value of each `dont_override` option into the preferences on a settings save – for an option set through a `ROUNDCUBE_GALBRIDGE_*` variable the value itself, `galbridge_db_dsn` with the password; copies saved earlier disappear at the next save |

No actions, templates or JavaScript. CLI (`Cli/Tool.php`):

* *(decision)* **The tool does not run as root.** It loads PHP from the plugin directory and from
  the Roundcube tree, and Roundcube's configuration files are PHP too; started by root it would run
  whatever the owner of one of those files wrote. `bin/galbridge.sh` therefore stops for uid 0
  unless `--allow-root` is given, and then only when everything it goes on to load belongs to root
  and is not writable by group or others: the plugin (`galbridge.php`, `bin/`, `lib/`, `SQL/`,
  `localization/`, its `config.inc.php`) and, below the Roundcube directory, every directory and
  every `*.php`, `*.inc`, `*.sql` and `*.sh` file of `program/`, `plugins/`, `vendor/` and the
  configuration directory – Roundcube's command line start (`rcmail::get_instance()`) loads every
  enabled plugin, classes of `vendor/` on demand and the localization index, and
  `Config::passwordPluginDsn()` loads the configuration of the password plugin. Symbolic links are
  followed (a plugin is often installed as one), and the directories above those trees – and
  above the target of every link – must belong to root as well (a directory with the sticky bit
  passes there, never as a directory that holds code). Every directory of `include_path` that the
  process keeps – absolute, existing entries only; PHP refuses an empty `include_path`, so
  `program/lib` stands in when none is left – is checked the same way. What was checked is what
  Roundcube gets: the root directory, the configuration directories and the `include_path` entries
  are canonicalised (`realpath()`) once, `INSTALL_PATH` and `RCMAIL_CONFIG_DIR` are defined with
  them before `iniset.php` runs (it keeps constants that exist) and `RCUBE_CONFIG_PATH` is rewritten,
  and the process `chdir()`s into the verified `program/` directory – PHP's last fallback for a
  relative file name (`include`, and the `fopen(..., use_include_path)` of `rcube_autoload()`) is
  the working directory, whatever `include_path` says. Before `clisetup.php`, the script loads
  `iniset.php`, builds one `rcube_config` and reads where it pointed PHP's `error_log`
  (`rcube_config::load()` derives it from the raw `log_driver` / `log_dir` on every configuration
  object, including the ones `Config::adminConfig()`, `Config::passwordPluginDsn()`,
  `Config::hostDependentOptions()` and the per-host check of `diag` create, and
  ignores `ROUNDCUBE_LOG_DRIVER`): a file destination must lie in a directory that root alone can
  write (owner 0, no group/other write - a sticky `/tmp` is no such directory - and safe directories
  above it) and not be a symbolic link, and an SQLite `db_dsnw` / `db_dsnr` likewise; otherwise the
  run is refused. Roundcube's own log lines go to syslog (`ROUNDCUBE_LOG_DRIVER`). Not covered:
  PHP's configuration (read before the script) and the start code of enabled plugins, which runs
  as root. The user of the process comes from `posix_geteuid()` or, without the posix extension –
  a package of its own on several platforms and no requirement of the plugin – from
  `/proc/self/status`; a user that cannot be determined is treated like root, never like
  "not root". `grants` needs no Roundcube at all.
* *(decision)* **`grants` as root from verified code** (second verification pass). Its output is run
  by the database administrator; generated by a process of the web server user (1.0.1's
  `sudo -u www-data ... grants`) it could be read or changed by any other process of that user
  (`ptrace` is open between processes of one user on many systems, `/proc/<pid>/fd` always),
  whoever owns the code. So the documented way is root with `--allow-root`, whose code check now also
  serves `grants`: the script, `lib/`, `SQL/`, `localization/`, `galbridge.php`, the configuration and
  every directory above them must be root's alone (`galbridge_cli_plugin_problem()`, the one routine
  for both). As another user, before any class is loaded, `grants` refuses when the process can read a
  configuration file of Roundcube or of the plugin (`galbridge_cli_web_identity()`: the candidate
  Roundcube directories, the configuration directories of the environment, the plugin directory and
  the usual installation places) and when an account other than root and itself owns, or group or
  others can write, anything it loads or prints (the same routine with the owners `[0, euid]`). For
  every user `grants` works in the plugin directory with the plugin's `lib/` as include path. The
  output holds no client command (`Tool::clientCommand()`: no backslash, no line starting with a
  long-form mysql command; checked before anything is printed), and INSTALLATION.md 2.2 adds what code cannot
  enforce: a check of the files with system tools before a root run, the review, `mysql
  --binary-mode` / `psql -X`. The self-check is only as trustworthy as the script itself – that is
  what the system-tool check is for.
* *(decision)* **Nothing reaches the terminal raw, and the end of a run is always visible.**
  `Tool::out()` passes every line through `Terminal::clean()` (C0 controls except the newline, DEL,
  C1, `\p{Cf}`, line/paragraph separators and every byte of invalid UTF-8 become `\xNN` /
  `\u{XXXX}`; ASCII, newlines and letters stay byte-identical, so the `grants` output is unchanged);
  values a database server returns and exception texts are `Log::sanitize()`d first (readable,
  masked, capped). For `diag` the script also puts `Cli\StderrFilter` (`Terminal::clean()` of
  `Log::maskCredentials()`) on `STDERR` – `rcube::raise_error()` writes driver, server and query
  texts there unchanged in the command line – and installs an error handler that writes PHP's
  messages as one sanitised line (`Terminal::phpMessage()`) to the error log (when `log_errors`) and
  to standard error (when `display_errors` is `stderr`, the only value Roundcube's bootstrap keeps),
  with PHP's own display switched off; the refusals of `--allow-root` pass
  `galbridge_cli_printable()` (no autoloader yet). A shutdown function registered before Roundcube
  is loaded – so before core's `rcmail_error_handler()`, whose bare `exit` turns a fatal error into
  exit code 0 on PHP 8.4/8.5 – reports a fatal error, or an `exit()` of other code while the tool
  runs, as `Result: aborted` and ends with `exit(2)`, which stops the later shutdown functions;
  exceptions during Roundcube's start and in the tool are caught around it the same way. (PHP 8.1
  ends a run that exceeds the memory limit itself, with exit code 1.)
* Arguments are strict (`Tool::argumentProblem()`): options are `--name=value`; an option without
  its value, an unknown option and a stray word are usage errors. A misspelled `--role` must not
  silently leave the default role in the statements.
* `bin/galbridge.sh diag [--user=<login>] [--url=<webmail URL>] [--strict]`. Every section runs on
  its own (an exception becomes a FAIL line and the next section runs; the vmail connection is such
  a section too). A check that could not run is a WARN; the summary counts WARN lines and names a
  web access that was not checked; `--strict` gives exit code 2 for WARN lines as well. Checks:
  versions (the whole 1.7 branch is supported; WARN for a patch release older than the newest
  known one, FAIL for another branch) and an INFO line that the PHP and environment facts are
  those of the command line process; configuration problems, environment overrides (names only;
  INFO when there is none, and INFO for every other `ROUNDCUBE_*` variable of this process,
  `Config::coreEnvOverrides()` - the web process logs its own `ROUNDCUBE_GALBRIDGE_*` names once
  per session, `env_override`), the effective value of
  every switch (three lines, each switch under the name of its option; an ambiguous one with the
  restrictive value it is read as, `Config::RESTRICTIVE`, and `(INVALID)`), a note on the
  suggestion order of the core release in use (`Tool::coreSortsWithIcu()`) while the global book
  marks foreign entries, every configuration file Roundcube loads for the plugin
  (`rcube_config::resolve_paths('galbridge.inc.php')`, or the plugin's own `config.inc.php`) and the
  core `defaults.inc.php` / `config.inc.php` (mode, group write, owner – root is expected, the PHP
  user is a WARN – and the owner and write bits of their directories and of the directories above)
  and a WARN while `'*'` global data domains are in effect; the plugin's configuration files as
  Roundcube treats them (`Config::configFiles()`: each file read, and an ignored `galbridge.inc.php`
  marked "NOT read", with the same mode checks; FAIL from `Config` for a file that does not load or
  is ignored), unknown options and variables (FAIL with the closest name), `galbridge_*` names in
  `dont_override` (FAIL for `galbridge_db_dsn`, WARN otherwise) and the users with `galbridge_*`
  keys in their stored preferences (two `COUNT(*)` statements on Roundcube's `users` table with a
  `LIKE` on the serialised key: FAIL for `galbridge_db_dsn`, WARN otherwise, WARN "not checked"
  without the database; nothing is unserialised or printed), loosening switches while they are on
  (`db_allow_plaintext`, `db_allow_verify_ca`, `global_allow_wildcard` without `'*'`), debug
  logging while it is on (`galbridge_debug`, also through its environment variable), an enabled
  password plugin with the SQL driver whose DSN is unknown, a `des_key` that is the published
  default, empty, not a string or shorter than 24 characters (WARN each), the refused roles and the
  size of the hidden and excluded lists (`refused roles`, `policy lists`, INFO), and "none" with the
  reason on `enabled books` while a configuration error disables every book; for a Composer
  installation (`Tool::composerManaged()`: the package in `vendor/composer/installed.json`, Composer
  1 or 2 form) a WARN for a configuration in `plugins/galbridge/config.inc.php`, which the installer
  re-creates at every update; the installation (a git clone, foreign
  files – also inside the release directories, `Tool::RELEASE_TREE` –, plugin code the PHP user
  can change: every code file and directory, recursively, owned or writable by that user, the
  directories above the plugin and Roundcube's `plugins/`; as root the rule of `--allow-root` – a
  FAIL for every `<plugin dir>-<20 digits>` directory next to the plugin (the backup an interrupted
  Composer update leaves, with the configuration in it; names only) –
  and, with `--url`, whether the web server serves plugin files and – `config over HTTP` – the
  configuration file in use (`Tool::configOverHttp()`: `plugins/galbridge/config.inc.php`, or
  `config/<file>` when the file lies in the `config/` directory of the Roundcube tree, each also
  through `static.php`; FAIL when a 2xx answer carries `$config[` and `galbridge_`, INFO for a 2xx
  answer without them, OK / WARN "inconclusive" for 401/403/404/410 after the control request; no
  part of an answer is printed); without `--url` a WARN instead of the INFO while the configuration
  lies in the plugin directory); the IMAP-server derivation and its coverage of
  `imap_host` and of the `mail_host` values in Roundcube's `users` table, the `IMAP transport` of
  every server of `imap_host` (`ViewerResolver::transportProblem()` with the entry's scheme as
  `rcmail::login()` reads it), `include_host_config`, a reminder when several servers are
  allowed and a WARN for every explicit TCP entry without a port (`ViewerResolver::splitEntry()`:
  it covers every port of its host); with `include_host_config` the section "Per-host configuration": every file a request can
  select (the listed files, or every `*.inc.php` of the configuration directories when it is
  `true`) merged over a fresh configuration with the plugin's files and compared with the same
  without it (FAIL for a `galbridge_*` option, INFO for a book name, coverage and transport of a
  per-host `imap_host`; as root only a file inside a checked configuration directory that belongs to
  root alone is loaded – loading runs it –, any other is a WARN and never run); the options a second reading of the files loses (`Config::divergentOptions()`,
  WARN "configuration read again"), configuration files that use `include_once`/`require_once`
  (a token scan, WARN – as root the script's pre-start probe has already run them, so the
  comparison cannot see it) and environment-specific files (WARN); core interplay (the sort column, a name
  template that takes fields from foreign entries, full-set limits above `Tool::COSTLY_FULL_SET`
  (10000), page sizes above `Limits::HARD_PAGE_CAP`, department groups of the global book); old-plugin leftovers; DSN policy and the
  endpoint the driver uses (`Dsn::describe()`, with the socket of MariaDB/MySQL `localhost`) and the
  permissions of its socket directory; the read timeout of the connection; connection (with the
  sanitised reason on failure: the driver's text, a driver error, or the refused session setting); the
  password-column tripwire, which counts only when the server refuses the statement for lack of
  privilege; **write privileges from the catalogs** (`RoleScope`: PostgreSQL `has_table_privilege` /
  `has_any_column_privilege` / `has_sequence_privilege` on every relation and sequence of the schema,
  MariaDB/MySQL `information_schema` USER/SCHEMA/TABLE/COLUMN_PRIVILEGES of the account; the line
  says what was checked) and, on MariaDB/MySQL, from the server itself: `Connection::probeWrite()` runs statements that
  can change nothing (`... WHERE 1 = 0`) in a read-write transaction that is rolled back, so a
  privilege held through a database role is seen too (the session is read-only otherwise, which is
  why a plain statement probe would always be denied); every other reason of `RoleScope::refusals()`
  as a FAIL of its own (role attributes, database owner, global privileges, role memberships,
  privileges outside), owned objects (FAIL) and a MariaDB/MySQL server-wide test database (FAIL,
  section 12); the other databases a PostgreSQL role may connect
  to (INFO); the server-side TLS of a MariaDB/MySQL account other hosts may use (`SHOW CREATE USER
  CURRENT_USER()` or `SHOW GRANTS`, judged by `Tool::requiresTls()` and never printed;
  `require_secure_transport`); the connection limit of the account on both
  engines; whether the server log can record the role's statements (on PostgreSQL also through
  `pgaudit.log` or `auto_explain.log_min_duration`, a WARN of its own); the statement time of the
  plugin's session compared with `galbridge_db_timeout` (all engines); PostgreSQL CREATE on a
  schema or the database (FAIL, from `RoleScope`) and ctype from `pg_database.datctype` (the `lc_ctype` setting no
  longer exists in PostgreSQL 16); the column probe from `Schema` (a secret column in any relation
  and a readable `forwardings` table are FAIL; optional columns only for the configured fields, INFO
  for granted but unused ones, WARN for columns beyond `Schema::GRANTABLE`; the visibility column in
  use); the view `galbridge_forwardings` (its rows; the object from `RoleScope`; on MariaDB/MySQL its
  definition from `SHOW CREATE VIEW`, judged by `Tool::viewDefinitionProblems()`); indexes and data
  sanity, with the **entries not offered** (the rows of every kind – persons, alias accounts, lists,
  per-user aliases, hosted domains – that fail the canonical rule, counted with the same SQL
  renderings the books use, negated as `CASE WHEN rule THEN 1 ELSE 0 END = 0` so a NULL counts as
  not offered; up to 10 examples per kind as JSON strings with the log's `row=` tag – the one place
  where diag prints directory content, because the administrator has to correct those rows), on
  MariaDB/MySQL the addresses with capitals it offers and a Turkish/Azerbaijani collation of an
  address column, and the window functions of the server (`Tool::windowFunctionsSupported()`); per user (the argument is validated
  before any of it is printed) the entitled books with the
  effective grouping (`Factory::effectiveGrouping()` plus the group count against
  `galbridge_max_groups`), the `'auto'` search-only resolution, timed per-kind counts
  (`QueryBuilder::kindCountQuery()`) and WARNs above `galbridge_max_count` / `galbridge_max_export`.
* `bin/galbridge.sh grants --driver=mysql|pgsql [--host=...] [--port=N] [--account-host=...] [--db=vmail]
  [--role=galbridge] [--connection-limit=25] [--reset-password | --grants-only]
  [--clear-text-password]` prints the grant set for a fresh random password and
  the matching DSN. `--host` is the database server in the DSN and `--port` its port (`host:port`,
  `[IPv6]:port`; digits only, 1-65535; refused with the MariaDB/MySQL host `localhost`, which PHP
  reaches through the unix socket whatever the port - the plugin refuses such a DSN as well);
  `--account-host` the MySQL account host part (patterns allowed; a non-local one adds `REQUIRE SSL`). The role, database and account
  host are renamed in SQL statements only - including the commented-out ones meant to be uncommented
  (the optional grant, the REVOKE for accounts of 1.0.0) - so the attribution header and prose
  comments stay intact; an IPv6 `--host` must be in brackets, and a malformed `--connection-limit`
  is a usage error. It never writes into the plugin directory.
  *(decision)* **Two streams.** Standard output carries the SQL and nothing else; the DSN line (the
  password), the TLS reminder, usage errors, refusals, template errors and an aborted run go to
  standard error (`Tool`'s `$errors` stream; a caller that gives one stream only keeps one
  transcript). A redirected SQL file therefore never holds the password or an error text, and a
  client that sends comments to the server cannot log the DSN. Arguments are parsed by
  `Tool::parseArguments()` for the script and the tests alike: a repeated option is a usage error,
  and `--account-host` with `--driver=pgsql` too (it would be ignored). PostgreSQL forms other than
  the default assert the role attributes (`ALTER ROLE ... NOSUPERUSER ... NOBYPASSRLS NOINHERIT`).
  *(decision)* **Three forms instead of "re-running is safe".** 1.0.0 printed statements that set
  the password of whatever role was named, existing or not. Now the default output only creates
  the role and stops when it exists (PostgreSQL: one transaction that raises an exception;
  MariaDB/MySQL: `CREATE USER` without `IF NOT EXISTS` as the first statement);
  `--reset-password` and `--grants-only` are the explicit forms for an existing role, and role names
  of iRedMail, Roundcube and the database server are refused.
  *(decision)* **The statements carry a verifier of the password**: PostgreSQL a SCRAM-SHA-256
  verifier, MariaDB/MySQL the `mysql_native_password` hash (`IDENTIFIED WITH mysql_native_password
  AS`). A database server keeps account statements – PostgreSQL in its log with `log_statement`,
  MariaDB verbatim in its general query log, its binary log and, when they qualify, its slow query
  log (verified on MariaDB 11.8) – so a password inside a statement outlives the script in logs,
  replicas and backups; `SET sql_log_off` covers only the first of the three MariaDB logs. The
  PostgreSQL template additionally sets `log_statement`, `log_min_error_statement` and
  `log_min_duration_statement` for its transaction, which protects the by-hand use of the template.
  MySQL 8.4+ does not load `mysql_native_password`: `--clear-text-password` prints the statement
  with the password (MySQL rewrites passwords in its own logs), preceded by a MariaDB-only
  executable comment that stops the script on MariaDB and, for a client that drops comments, by a
  statement MariaDB cannot run (`SELECT @@SESSION.information_schema_stats_expiry`, a setting of
  MySQL 8.0.3 and later). The PostgreSQL template also switches `pg_stat_statements.track_utility`
  off for its transaction, revokes `CREATE` on schema `public` from `PUBLIC` (after granting it by
  name to the roles that own tables or functions there) and explicit `CREATE` / `TEMPORARY` on the
  database from the role, and sets `search_path = public` for its transaction.

## 16. Operations

* *(decision)* Version policy: **the whole Roundcube 1.7 branch is supported** (`min-version` 1.7.0,
  `max-version` 1.7.999 for the Composer installer). Supporting a single patch release made the
  plugin a reason to delay security releases of the core. The suite runs against the oldest release
  and the newest one, CI against the newest 1.7.x daily while GitHub keeps the scheduled run enabled
  (checked with every release, RELEASING step 3); diag warns on a core older than the newest
  release this version knows (`Tool::LATEST_KNOWN_ROUNDCUBE`, raised with every GAL Bridge release)
  and fails on another branch.
* Upgrade of Roundcube: iRedMail updates its versioned tree in place with `bin/installto.sh`
  (`plugins/galbridge` and its configuration stay; the copied files keep the owner uid 1000 of the
  release tarball unless it is unpacked with `tar --no-same-owner` – give them back to root; with a
  Composer installation run `composer update --no-dev --ignore-platform-req=ext-ldap` yourself);
  a new tree instead needs `plugins/galbridge` and its configuration copied – a Composer
  installation then upgrades by release archive, or runs `composer require` in the new tree
  (OPERATIONS.md 5); run diag.
* Upgrade of the plugin from 1.0.0: apply `grants --grants-only` (the view, the limits; MariaDB/MySQL
  also the printed `REVOKE` for the table) – until then the plugin offers no book (OPERATIONS.md 5). From
  1.0.1: apply it again as root (the fenced view, the role attributes, CREATE on schema `public`) –
  until then the books keep working with log warnings and `diag` FAILs.
* Uninstall: remove from `$config['plugins']`; a Composer installation with `composer remove`, run
  like the installation (interactive root shell or `COMPOSER_ALLOW_SUPERUSER=1`; without a terminal
  Composer skips Roundcube's installer and `plugins/galbridge` stays; a bare `rm -r` leaves the
  package in `composer.json` / `composer.lock`, OPERATIONS.md 6); MariaDB `DROP USER 'galbridge'@'localhost'` and
  `DROP VIEW vmail.galbridge_forwardings`; PostgreSQL `\c vmail` + `DROP OWNED BY galbridge;
  DROP ROLE galbridge; DROP VIEW galbridge_forwardings;`. The plugin keeps no tables and no user
  preferences.
