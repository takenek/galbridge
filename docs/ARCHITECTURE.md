# GAL Bridge – Architecture

GAL Bridge (`galbridge`) gives Roundcube Webmail **1.7** (every 1.7.x release) a read-only **Global Address List**
built from the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and PostgreSQL
editions). It replaces the unmaintained *Roundcube-SQL-Global-Address-Books* plugin and was written
from scratch. It uses only the official plugin API: the `addressbooks_list` and `addressbook_get`
hooks, the `addressbook_export` hook (vCard export only) and an `rcube_addressbook` backend.

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
  autocomplete (contacts and groups), compose contact list, search and group expansion.
* **Tenant isolation by construction.** The visible scope comes only from the authenticated login
  and the administrator configuration. No request parameter (`_source`, `_gid`, `_cid`, `_search`,
  `_q`, `_headers`, `_page`) can widen it.
* **Least privilege, enforced.** A dedicated read-only role with column-level grants and a
  row-restricting view over `forwardings`. The plugin refuses to run with a role that can see
  `mailbox.password` or the `forwardings` table.
* Data minimisation: an administrator allowlist decides which fields are exposed. Fields outside it
  are never shown, searched, sorted, grouped on or exported.
* Never silently wrong: a recipient set (group expansion, compose-to-selected) and every export are
  either complete or refused. Neither is ever a truncated prefix.
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
galbridge.php                    rcube_plugin: hooks, book factory, autocomplete wiring, export refusal
lib/GalBridge/
  Config.php                     typed, clamped, normalised admin-only configuration
  Normalizer.php                 ONE normalisation for config lists, the login, IDs and row re-checks
  Log.php                        redacting logger, channel "galbridge", once-per-request reason codes
  Exception/GalBridgeException   base exception (reason code + internal message)
  Db/Dsn.php                     DSN policy: driver and parameter allowlists, explicit target, refused
                                 roles, TLS rule (host name verified), connect timeout
  Db/Connection.php              lazy rcube_db on galbridge_db_dsn: session limits, error handling,
                                 outage memo, schema/privilege probe (incl. the forbidden table)
  Directory/Viewer.php           the authenticated principal: login, home domain H, address set V
  Directory/ViewerResolver.php   IMAP-server binding (host and port) + resolution from vmail, cached
                                 in the session and confirmed before directory data is read
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
  Directory/RecordMapper.php     row -> Roundcube record (sanitised, no nulls, ASCII addresses)
  Directory/Repository.php       executes QueryBuilder output; per-request memo (groups, counts);
                                 complete-or-nothing sets; confirms a cached entitlement first
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

1. `init()` (tasks `mail|addressbook`, authenticated users only) registers `addressbooks_list` and
   `addressbook_get` (plus `addressbook_export` for `addressbook/export`, 15) and loads the
   localization. **It never touches the database.** Only for `mail/compose`, `mail/bounce` (the
   Redirect dialog, whose recipient fields core renders through the same
   `rcmail_sendmail::compose_headers()`) and `mail/autocomplete` (the only readers) it
   * appends the plugin's enabled source IDs to `autocomplete_addressbooks` (de-duplicated; a
     source the user is not entitled to resolves to a DeniedBook, costing nothing), and
   * raises the client-side `autocomplete_min_length` to `galbridge_min_search_length`. Roundcube's
     JS stops narrowing once a prefix returned nothing from every source, so the server minimum and
     the client minimum must agree.
2. The **Viewer** is resolved lazily, on the first hook call that needs it:
   * **IMAP-server binding** *(decision)*. `imap_host` (or the legacy `default_host`) is read from a
     fresh administrator `rcube_config` (user preferences ignored). If `galbridge_imap_hosts` is empty and `imap_host`
     is a single string without `%` placeholders, the allowed server is host and port of
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
     `include_host_config` (the effective `imap_host` then depends on the requested host name) or a
     set `ROUNDCUBE_INCLUDE_HOST_CONFIG` (which the core ignores for that option).
     Otherwise a user who logs in through an IMAP server of their own choosing as
     `ceo@tenant-a.example` would get tenant A's directory. What the plugin cannot check is that
     every listed server authenticates against this vmail database (SECURITY.md).
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
     stored session with the request's copy, so a key that is merely unset would return. A Viewer
     resolved in the same request needs no confirmation, and a request that only instantiates a
     book never runs one;
   * *(decision)* when the cached Viewer has expired and its re-resolution fails with `db_down` or
     `db_error`, the expired Viewer (same user, login, host and fingerprint) is kept as a **stale
     Viewer**. It is used only to keep listing the session's books, as DeniedBooks that show
     `galbridge.unavailable` (see DeniedBook below), never to read directory data. Without it the
     books would vanish silently after the TTL; a session without a cached Viewer (a login during
     the outage) still gets no books.
3. `addressbooks_list` appends one entry per entitled book with a non-empty data scope: `id`, `name`
   (HTML-escaped, core prints it raw), `readonly => true`, `groups` (the effective grouping,
   `Factory::effectiveGrouping()`), `autocomplete`. No internals.
4. `addressbook_get` *(decision)*: for **our** source IDs it **always** sets an instance, whatever
   `writeable` says (core itself returns null for a writable request on a read-only book; without an
   instance core dies with error 700): `AddressBook` when entitled with a non-empty scope, otherwise
   `DeniedBook`. Foreign IDs are left untouched.
5. `AddressBook` builds SQL only through `QueryBuilder`. Every returned row is re-checked in PHP
   (normalised domain in the data scope, not hidden, valid address). A violation is dropped and
   logged (`row_invalid` WARN for a malformed row, `row_out_of_scope` ERROR for a row outside the
   scope).

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
* `galbridge_organizations` = `['Acme' => ['acme.pl', 'acme.com'], ...]`. Sets must be disjoint. A
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
  [`AND m.expired > :now AND d.expired > :now` with `galbridge_hide_expired`, default on, `:now`
  bound from PHP in UTC] [`AND m.<galbridge_visibility_column> = 1`], minus
  `galbridge_hidden_addresses` / `galbridge_hidden_localparts`.
* **Alias account** (`galbridge_include_aliases`, default on): `a.active = 1`, domain visible,
  `EXISTS` an active `forwardings` row with `is_list = 1`, accesspolicy (6).
* **Mailing list** (`galbridge_include_mailing_lists`, default on): `ml.active = 1`, domain visible,
  `EXISTS` an active `forwardings` row with `is_maillist = 1`, accesspolicy.
* **Per-user alias addresses** (`is_alias = 1`) are selected, displayed and searchable
  **only** with `galbridge_user_aliases` (default off). When off they are neither shown nor matched,
  so a search cannot reveal who owns an alias. When on, *(decision)* **one predicate decides both
  showing and matching** (`QueryBuilder::userAliasRoot()`): the alias row is active, its domain is
  in the book's scope, active, not a backup MX and (with `galbridge_hide_expired`) not expired, and
  the alias is on neither hidden list. Two predicates – one for the list of "other" addresses, one
  for the search – had drifted apart: a hidden alias was not shown but still found its owner. The
  viewer's own aliases are always loaded for accesspolicy (Viewer resolution).
* With `galbridge_hide_expired`, an alias account or list is listed only when its own `expired`
  column is readable and in the future; a role without that column gets no entries of that kind.
* Every forwardings / `moderators` / `maillist_owners` predicate is a semi-join (`EXISTS`/`IN`),
  never a JOIN, so rows never multiply (counts and paging stay exact). Display aliases are fetched
  by a second query keyed by the addresses of the current page: at most 20 per person, with
  bounded statements (an owner with thousands of aliases cannot take the aliases of the other
  entries of the page away).
* All of this is read from the **view `galbridge_forwardings`** (12), never from the table:
  forwarding targets (`is_forwarding`) are not in the view. Catch-all rows and external list
  members are never selected.

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
  the template, `{name} ({department})`); only then are the notations split. Texts are treated
  field by field – a template that joins two fields with nothing between them is outside what
  that can cover. An entry without a name gets the local part of its address as the name; that
  is directory text as well (an address may hold `&`, `#`, `=`, `?`) and goes the same way.
  Addresses are lower-case ASCII (A-labels, no homographs) and must pass
  `rcube_utils::check_email()`.
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
  template and with every template that starts with the name; diag WARN). (2) Core sorts the
  autocomplete suggestions of all address books by name with the ICU collator
  (`rcube_utils::asort()`) and the client pre-selects the first one; `(` sorts before letters and
  digits, so marked entries are suggested before unmarked names. A marker at the end would sort by
  name but can be cut off by the ellipsis – the reason it is not there. The order is documented
  (README 3.3), shown by diag while it applies, and avoidable with
  `galbridge_global_autocomplete = false`.

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
* `list_groups()` returns `virtual => true` groups, so the compose list renders drill-down rows
  without a COUNT per group. With a search term (autocomplete, compose search) it applies the
  minimum length and leaves out groups larger than the expansion cap, so a group that expansion
  would refuse is never offered.
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
* `galbridge_*_autocomplete` per book is enforced in `search()` and in `list_groups()` for
  `_action=autocomplete` (core accepts any `_source` there and also asks every source for groups).
* In the global book the `organization` field matches only a search word equal to the whole label
  (4).

## 11. Paging, counts, limits

* `set_page()` ≥ 1. Normal page sizes are those ≤ `Limits::corePageCap` = max(core `max_pagesize`
  (default 200), `addressbook_pagesize`), bounded to 10–1000 (`Limits::HARD_PAGE_CAP`): core clamps
  `addressbook_pagesize` to `max_pagesize` only when a user saves the preference, and its lists use
  the configured value as it is. A larger size on page 1 is a **full-set request** (core uses
  999/9999/99999 for group expansion, compose-to-group, export and search lists):
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
    empty + warning, never a prefix;
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
  key), bounded by `galbridge_max_count` (10000). Listing offsets beyond it return an empty page.
* Sort keys per `sort_col` with fallbacks over present columns only: surname →
  `COALESCE(NULLIF(TRIM(last_name),''), NULLIF(TRIM(name),''), username)`, likewise firstname;
  name → `COALESCE(NULLIF(TRIM(name),''), username)`; address as tie-break. Core re-sorts search
  results by `addressbook_sort_col` in PHP: README recommends `addressbook_sort_col = 'name'` on
  iRedMail (open-source iRedAdmin fills only `name`); diag warns.
* *(decision)* **No rate limiter.** A `$_SESSION` counter is not atomic (parallel requests, which core
  itself sends for autocomplete, all pass) and turns read-only requests into session writers that
  can overwrite concurrent compose state. Resource control = result caps, minimum length, statement
  and connect timeouts, the limits of the database account – connections and statement time, set
  by the grant templates for both engines and reported by diag – and a reverse-proxy limit on
  `_action=autocomplete` (README; register entry GB-26 in SECURITY.md).

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
  Refused roles (default): `vmail`, `vmailadmin`, `roundcube`,
  `sogo`, `iredadmin`, `iredapd`, `amavisd`, `fail2ban`, `root`, `postgres`, plus the `db_dsnw` user
  and the password plugin's DSN user. Loopback = unix socket, 127.0.0.0/8, `::1`, `localhost`; any
  other host needs TLS that verifies the host name (PostgreSQL `sslmode=verify-full`; MySQL
  `ca`/`capath` + `verify_server_cert=1`). `sslmode=verify-ca` checks the issuer only and needs
  `galbridge_db_allow_verify_ca`; anything weaker needs `galbridge_db_allow_plaintext` (both: log
  `dsn_warning`, diag WARN). A connect `timeout`
  (`galbridge_db_connect_timeout`, 2 s) is injected when the DSN has none; a `timeout` written into
  the DSN must be 1..30 s (rcube_db keeps the first occurrence, so it is validated, never appended).
  Note: in a web request `rcube_db::conn_create()` itself logs a failed connect with the driver
  text (role and host, never the password) to Roundcube's errors log, whatever `ignore_errors` says;
  in the CLI core writes it to stderr only, so `Connection::lastError()` keeps the sanitised text and
  diag prints it.
* After connecting: read-only session and statement timeout (`galbridge_db_timeout`, 3 s;
  MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`), as a
  backstop to the role-level limits in the grant templates.
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
* **Schema and privilege probe** (once per Viewer TTL): `information_schema.columns` for the six
  tables and the view GAL Bridge reads (`Connection::TABLES`) and for the table it must not read
  (`Connection::FORBIDDEN_TABLES`). MariaDB and PostgreSQL list only columns the role
  can access, so this yields "present and granted". If `mailbox.password`, `recovery_email` or
  `allow_nets` is visible, or any column of the `forwardings` table, the role is too broad: books
  become DeniedBooks, ERROR `role_too_broad`.
  The required and optional columns come from `Schema` (one list for the resolver, the factory and
  diag).
* `ignore_errors` is on, so core never logs SQL with search values; errors are logged with the
  SQLSTATE only (driver text with `galbridge_debug`). The book calls `set_error()`, returns an empty
  result and shows "The directory is temporarily unavailable." at most once per request (core's
  read actions never call `get_error()`). A connection failure is memoised for the request and for
  30 s in the session.
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
  dropped (log ERROR `config_invalid`, diag WARN); a failed **deny**-list entry disables the
  affected book (fail closed, diag FAIL). `QueryBuilder::forBook()` refuses a malformed deny-list or
  visibility column (`config_invalid`), so no caller can turn the fail-closed `null` into "no
  restriction".
* *(decision)* **Values are typed, never cast.** PHP reads the string `'false'` as true and an
  array as "not a string, so empty". A switch is therefore a boolean or the integer 1 / 0 (the
  `searchonly` options also `'auto'`), the visibility column a string, the lists arrays; anything
  else is a reported problem, and where the option protects something `Config::booksDisabled()` is
  true: `Entitlements` then offers no book and `QueryBuilder::forBook()` refuses to build one.
  Roundcube applies environment overrides (`ROUNDCUBE_<OPTION>`) on every read and converts the
  text `false` into the boolean true; for its switches `Config` reads the variable itself and
  accepts `1`, `0`, `true`, `false` (`auto`) only. diag shows the effective value of every switch
  (`Config::effectiveSwitch()`) and the overrides in effect. `galbridge_fields` of another type
  than a list is reported too and leaves name and e-mail.
* The user-preference guard (`Config::get()`: a stored preference named `galbridge_*` never
  changes an administrator option) answers from a fresh `rcube_config`. For an option that no
  configuration file sets it asks that fresh configuration with the built-in default, so an
  environment override applies as it does without the preference; `rcube_config::all()` alone
  would miss it, because it applies the variables to the options of the files only.
* The configuration fingerprint binds the Viewer cache and stored search specs. It is an HMAC over
  the effective admin values (the DSN among them), `imap_host` and `include_host_config`, keyed
  with Roundcube's `des_key`: the session store holds nothing that would let somebody test guesses
  of the database password, and any change of the configuration still voids the cache at once.
  Switches enter it as they are in effect, not as Roundcube converts an environment override
  (`true` for "true" and "false" alike).

## 14. Logging

Channel `galbridge` (file `<log_dir>/galbridge.log`; core appends `log_file_ext`). Normal levels:
reason codes (`denied_source`, `invalid_gid`, `invalid_id`, `group_too_large`, `too_many_ids`,
`export_disabled`, `export_too_large`, `set_incomplete`, `too_many_groups`, `role_too_broad`,
`column_missing`, `field_unavailable`, `grouping_disabled`, `row_invalid`, `row_out_of_scope`,
`viewer_revoked`, `too_many_aliases`, `aliases_capped`, `aliases_skipped`, `db_error` + SQLSTATE,
`db_down`, `config_invalid`, `dsn_warning`, `host_mismatch` with the server names `host=`,
`mail_host=`, `allowed=`), the numeric Roundcube user id, book ids, counts. Reason codes triggered by
request input or directory rows are logged at most once per request (`Log::once`); `db_error` is
logged per failed query. Search terms, addresses and names are never logged by the plugin;
`galbridge_debug` adds only the driver's error text, exception messages and schema hints (sanitised,
not filtered for personal data). Note: `rcube_db` inlines bound values on the client side, so
database server logs and driver error texts can contain search terms. The PostgreSQL grant set
switches statement logging off for the role (`log_statement`, `log_min_duration_statement`,
`log_min_error_statement` and the sampled logging of PostgreSQL 12 / 13 and later,
`log_transaction_sample_rate` and `log_min_duration_sample`); MariaDB/MySQL have no per-account
setting, diag reports the general query log (SECURITY.md, Logs).

## 15. Hooks, actions, CLI

| Hook | Purpose |
|---|---|
| `addressbooks_list` | advertise entitled books |
| `addressbook_get` | instantiate `AddressBook` or `DeniedBook` for our IDs |
| `addressbook_export` | registered for `addressbook/export` only: `204 No Content` when a book refused the export (11) |

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
  passes there, never as a directory that holds code). The user of the process comes from `posix_geteuid()` or, without the posix extension –
  a package of its own on several platforms and no requirement of the plugin – from
  `/proc/self/status`; a user that cannot be determined is treated like root, never like
  "not root". `grants` needs no Roundcube at all and runs as any unprivileged user.
* Arguments are strict (`Tool::argumentProblem()`): options are `--name=value`; an option without
  its value, an unknown option and a stray word are usage errors. A misspelled `--role` must not
  silently leave the default role in the statements.
* `bin/galbridge.sh diag [--user=<login>] [--url=<webmail URL>]`. Every section runs on its own (an
  exception becomes a FAIL line and the next section runs). Checks: versions (the whole 1.7 branch
  is supported; WARN for a patch release older than the newest known one, FAIL for another
  branch); configuration problems, environment overrides (names only), the effective value of
  every switch (three lines, each switch under the name of its option; an ambiguous one with the
  conservative value it is read as and `(INVALID)`), a note on the suggestion order while the
  global book marks foreign entries, the configuration file (mode, group write, owner – root is expected, the PHP user
  is a WARN – and parent directory) and a WARN while `'*'` global data domains are in effect; the
  installation (a git clone, foreign files, plugin code writable by the PHP user and, with `--url`,
  whether the web server serves plugin files); the IMAP-server derivation and its coverage of
  `imap_host` and of the `mail_host` values in Roundcube's `users` table, `include_host_config` and
  a reminder when several servers are allowed; core interplay (the sort column, and a name
  template that takes fields from foreign entries); old-plugin leftovers; DSN policy;
  connection (with the sanitised driver reason on failure) and refused session limits; the
  password-column tripwire, which counts only when the server refuses the statement for lack of
  privilege; **write privileges from the catalogs** (PostgreSQL `has_table_privilege` /
  `has_any_column_privilege`, MariaDB/MySQL `information_schema` USER/SCHEMA/TABLE/COLUMN_PRIVILEGES)
  and, on MariaDB/MySQL, from the server itself: `Connection::probeWrite()` runs statements that
  can change nothing (`... WHERE 1 = 0`) in a read-write transaction that is rolled back, so a
  privilege held through a database role is seen too (the session is read-only otherwise, which is
  why a plain statement probe would always be denied); the connection limit of the account on both
  engines; whether the server log can record the role's statements; PostgreSQL schema `public`,
  `statement_timeout` and ctype from `pg_database.datctype` (the `lc_ctype` setting no longer
  exists in PostgreSQL 16); the column probe from `Schema` (the `forwardings` table must not be
  readable; optional columns only for the configured fields, INFO for granted but unused ones;
  the visibility column in use); indexes and data sanity; per user (the argument is validated
  before any of it is printed) the entitled books with the
  effective grouping (`Factory::effectiveGrouping()` plus the group count against
  `galbridge_max_groups`), the `'auto'` search-only resolution, timed per-kind counts
  (`QueryBuilder::kindCountQuery()`) and WARNs above `galbridge_max_count` / `galbridge_max_export`.
* `bin/galbridge.sh grants --driver=mysql|pgsql [--host=...] [--account-host=...] [--db=vmail]
  [--role=galbridge] [--connection-limit=25] [--reset-password | --grants-only]
  [--clear-text-password]` prints the grant set for a fresh random password and
  the matching DSN. `--host` is the database server in the DSN; `--account-host` the MySQL account
  host part (patterns allowed; a non-local one adds `REQUIRE SSL`). The role, database and account
  host are renamed in SQL statements only - including the commented-out ones meant to be uncommented
  (the optional grant, the REVOKE for accounts of 1.0.0) - so the attribution header and prose
  comments stay intact; an IPv6 `--host` must be in brackets, and a malformed `--connection-limit`
  is a usage error. It never writes into the plugin directory.
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
  executable comment that stops the script on MariaDB.

## 16. Operations

* *(decision)* Version policy: **the whole Roundcube 1.7 branch is supported** (`min-version` 1.7.0,
  `max-version` 1.7.999 for the Composer installer). Supporting a single patch release made the
  plugin a reason to delay security releases of the core. The suite runs against the oldest release
  and the newest one, CI against the newest 1.7.x daily; diag warns on a core older than the newest
  release this version knows (`Tool::LATEST_KNOWN_ROUNDCUBE`, raised with every GAL Bridge release)
  and fails on another branch.
* Upgrade of Roundcube (iRedMail installs versioned trees): copy `plugins/galbridge` and its
  `config.inc.php` into the new tree, run diag.
* Upgrade of the plugin from 1.0.0: apply `grants --grants-only` (the view, the limits; MariaDB/MySQL
  also the printed `REVOKE` for the table) – until then the plugin offers no book (README 5).
* Uninstall: remove from `$config['plugins']`; MariaDB `DROP USER 'galbridge'@'localhost'` and
  `DROP VIEW vmail.galbridge_forwardings`; PostgreSQL `\c vmail` + `DROP OWNED BY galbridge;
  DROP ROLE galbridge; DROP VIEW galbridge_forwardings;`. The plugin keeps no tables and no user
  preferences.
