# Security policy – GAL Bridge

Maintainer: **[Marek Królikowski (TaKeN)](https://github.com/takenek)**.

Business name: **TaKeN.PL Usługi Informatyczne Marek Królikowski**.

## Reporting a vulnerability

Please report security problems **privately**, not in public issues:

* GitHub: use "Report a vulnerability" (private security advisory) on the repository
  `takenek/galbridge`, or
* e-mail the maintainer (address in the repository profile), preferably S/MIME-encrypted.

Include the affected version, configuration (redact secrets), steps to reproduce and impact. You
will receive an acknowledgement within 7 days. Please allow a reasonable time for a fix before
public disclosure. Never send real database credentials, password hashes, directory exports or
mailbox content.

## Supported versions

Only the latest release of GAL Bridge receives security fixes. A release supports the platform
named in its README section 1:

* **Roundcube 1.7.x** – every release of the 1.7 branch. Patch releases of that branch fix security
  problems of Roundcube itself: install them as soon as they are published; they need no new GAL
  Bridge release. The test suite runs against the oldest release (1.7.0) and the newest one, CI
  repeats it daily against the newest 1.7.x on Packagist, and a regression found there is fixed in
  a patch release of GAL Bridge. `diag` warns when the core is older than the newest 1.7.x this
  version knows and fails on another branch.
* **PHP 8.1 – 8.5**, the range Roundcube 1.7 runs on. GAL Bridge does not narrow it, because the
  PHP version is chosen by the Roundcube installation, not by a plugin – but run a PHP that still
  receives security fixes: an upstream branch within its support period (8.1 ended on 2025-12-31,
  8.2 ends on 2026-12-31), or the PHP packages of a distribution that maintains them. `diag` warns
  for a branch past its upstream support.
* The **MariaDB/MySQL and PostgreSQL editions of iRedMail 1.8.x**.

## How directory data is protected

* **Scope from the login only.** The books a user gets and the domains they cover are a pure
  function of the authenticated user and the administrator configuration. The home domain comes
  from the vmail row of the login (`mailbox.domain`, which must be the domain of the login itself),
  never from the login string alone and never from request input. The session must come from a
  configured directory IMAP server – host and port – so a login through an IMAP server of the
  user's own choosing gets nothing. A cached entitlement is confirmed against vmail by every
  request that reads directory data: a mailbox that was disabled, expired or moved loses the
  directory with its next request.
* **Configuration is typed, not guessed.** A switch that is not a boolean, a visibility column that
  is not a string, a malformed deny-list or hidden list never means "off" or "no restriction": it is
  reported and disables every book. Environment overrides of switches are parsed by the plugin
  itself (Roundcube would read the text `false` as true).
* **One query builder.** Every statement that reads directory entries (lists, searches, counts,
  groups, record lookups) is produced by `QueryBuilder`, whose root predicate is
  `scope AND visibility AND hidden AND filter`, rendered from a fully parenthesised predicate tree
  with bound values. It is built through one constructor (`QueryBuilder::forBook`), which refuses a
  malformed deny-list or visibility column instead of reading it as "no restriction". An empty
  `IN ()` means FALSE and an empty `NOT IN ()` means TRUE; a clause is never omitted. Every returned
  row is re-checked in PHP (domain in the book's scope, not hidden, valid address); a violation is
  dropped and logged (`row_out_of_scope`). Per-user aliases are **shown and matched under one
  predicate** (alias row of a domain in the book's scope, active, not a backup MX, not expired, on
  no hidden list), so a search can never find an owner through an alias the book does not show.
  The fixed statements of the user resolution (the viewer's own row and aliases) and the statistics
  of `diag` are separate and never list other users' entries.
* **Inert books for anything else.** A GAL Bridge source ID that the user may not use (not entitled,
  empty scope, plugin disabled, database unavailable, stale preference, crafted `_source`) gets a
  `DeniedBook`: not ready, no groups, every read empty, never connects to the database. During a
  database outage the books a session already had stay listed as such inert books that only show
  "The directory is temporarily unavailable.".
* **Least privilege, enforced.** A dedicated role with column-level `SELECT` grants on a read-only
  session. It reads the forwardings through the view `galbridge_forwardings` (alias and list rows
  only) and has no access to the `forwardings` table, whose other rows are the private forwarding
  targets of the mailboxes. The plugin refuses iRedMail's own roles, the Roundcube database user
  and the password plugin's user by name, and any role that can see `mailbox.password`,
  `recovery_email` or `allow_nets` or the `forwardings` table, by probing `information_schema`
  (`role_too_broad`). The DSN is checked in the form the driver receives it (explicit target, known
  parameters only), and a remote database needs TLS that verifies the host name.
* **Data minimisation.** The field allowlist (`galbridge_fields`) intersected with the columns the
  role can read is the only source of the SELECT list, the searchable fields, the sort keys, the
  groups and the export. A field outside it cannot be shown or used as a search oracle.
* **Never silently wrong.** A recipient set (group expansion, compose to selected contacts) and
  every export are complete or refused: the set is read with one statement up to the limit of the
  operation plus one row, a set of which an entry could not be delivered is refused, and an export
  that cannot be complete – including a search result above the list cap and an export during a
  database error – downloads nothing. A limit that only bounds counting never decides what a set
  holds.
* **Names stay text.** Directory text is sanitised for the contexts that later decode it: besides
  control and format characters and `\ " < >`, the ampersand (HTML character references in
  Roundcube's recipient widget) and the delimiters of an RFC 2047 encoded word are neutralised –
  also where Roundcube would re-join them by deleting empty brackets from a name it composes with a
  template – and a name that looks like an address is cut. The local part of an address, shown
  where an entry has no name, is treated the same way. The foreign-domain marker of the global book
  precedes the name, and such an entry carries no other text that a name template would show in
  front of it.
* **Read-only, no endpoints.** No actions, no templates, no JavaScript. `init()` returns at once
  without an authenticated user; otherwise it only registers the `addressbooks_list` and
  `addressbook_get` hooks (plus `addressbook_export` for the vCard export, which answers
  `204 No Content` instead of an empty file when a book refused the export) and, for compose,
  Redirect-dialog (`bounce`) and autocomplete requests, adds the enabled GAL Bridge source IDs (those
  with autocomplete on) to `autocomplete_addressbooks` and raises `autocomplete_min_length`. It never
  touches the database; a source the user is not entitled to resolves to an inert `DeniedBook`.

## Important security assumptions

* The server, the PHP process account, the Roundcube installation, its configuration and the
  administrator are trusted. Anyone who can run code as the PHP user can read the database password
  in the plugin configuration and use the role.
* Every logged-in user is potentially hostile towards other users and other tenants. Every request
  parameter (`_source`, `_gid`, `_cid`, `_search`, `_q`, `_headers`, `_page`, `_action`) is
  attacker-controlled.
* Directory content written by tenant administrators or users (display names, departments, list
  names) is attacker-controlled for every other viewer: it is sanitised before display.
* **Every** configured directory IMAP server (`galbridge_imap_hosts`, or the one derived from
  `imap_host`) authenticates users against the same vmail database, so the Roundcube login equals
  `mailbox.username`. The plugin binds a session to host and port but cannot verify what is behind
  them; `diag` reminds the administrator when more than one server is allowed and when
  `include_host_config` is enabled.
* The vmail database is trusted for integrity; the plugin never writes to it.
* Roundcube's session storage is trusted. The resolved user (home domain, address set, column probe)
  is cached in `$_SESSION['galbridge']` for `galbridge_scope_ttl` seconds (and confirmed against
  vmail before directory data is read); the entry is bound to user, login, IMAP server and a keyed
  fingerprint of the configuration (HMAC with Roundcube's `des_key`, so the session store holds
  nothing that could be used to test guesses of the database password). The only other keys are
  `galbridge_down_until` (the 30-second outage memo) and `galbridge_searchonly` (the `'auto'`
  search-only decision per book). Whoever can write the session store can change them, and could
  equally take over the session. Search criteria that Roundcube keeps in the session are re-validated
  on every use and can never carry SQL.

See [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) for the full threat model and
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design.

## Hardening checklist for administrators

* HTTPS only, `use_https = true`, `session_samesite = 'Strict'` or `'Lax'`.
* **Keep Roundcube current**: install every 1.7.x release (above) and run `diag` afterwards.
* **Install a release, not a clone** (README 2.1): verify the signed tag and unpack its archive. A
  git clone brings `tests/` – scripts that drop databases and reconfigure Dovecot – and the
  repository history onto the mail server; `diag` reports such a tree.
* **Dedicated role**: create it with `plugins/galbridge/bin/galbridge.sh grants` (README 2.2), never
  reuse `vmail`, `vmailadmin`, `roundcube` or another iRedMail role (the tool refuses those names),
  and never edit the grant templates inside the plugin directory. `diag` must show the password
  column as not readable (refused by the server), the `forwardings` table as not readable and no
  write privileges. The personal-data grant (`telephone`, `mobile`, `birthday`) is commented out:
  uncomment it only for fields you expose; `diag` lists granted columns that no configured field
  uses (INFO), so you can revoke them. The generated statements contain a verifier of the password,
  not the password; still keep the output private (its DSN line has the password) and delete it
  after use.
* **Run the command line tool as the PHP user, never as root** (it refuses root, and a process
  whose user it cannot determine): it loads PHP code from the plugin and the Roundcube directory.
  `--allow-root` passes only when all of that code – the plugin, Roundcube's `program/`, every
  plugin, `vendor/` and the configuration directory – belongs to root and nobody else can write it.
* **Account limits** sized to the PHP-FPM pool, set by the grant set on both engines
  (`grants --connection-limit=`, default 25; `diag` shows the limit and warns when there is none):
  PostgreSQL `CONNECTION LIMIT`, `statement_timeout`, `idle_in_transaction_session_timeout` and
  `default_transaction_read_only`; MariaDB/MySQL `MAX_USER_CONNECTIONS` and, on MariaDB,
  `MAX_STATEMENT_TIME`. On PostgreSQL before 15 review `CREATE` on schema `public` (`diag` warns).
* **TLS for a remote database**: PostgreSQL `sslmode=verify-full` with `sslrootcert` (`verify-ca`
  does not check the host name and is refused unless `galbridge_db_allow_verify_ca` is set – only
  for a private CA that signs nothing but the database server), MySQL `ca=` with
  `verify_server_cert=1`. Server side: MariaDB `REQUIRE SSL` (added by `grants` for a non-local
  `--account-host`); PostgreSQL a `hostssl` line and a matching `hostnossl ... reject` line inserted
  **before** iRedMail's catch-all `host all all 0.0.0.0/0 md5` (README 2.2). Keep
  `galbridge_db_allow_plaintext = false` unless the link is a trusted private network.
* **One vmail database behind every directory IMAP server.** With more than one IMAP server, a
  templated `imap_host` or `include_host_config`, list the servers in `galbridge_imap_hosts`
  (`host:port`) and make sure each of them authenticates against this vmail database (README 3.9).
* **Configuration file** `plugins/galbridge/config.inc.php` (or `config/galbridge.inc.php`) owned by
  `root:<PHP-FPM group>`, mode `0640` (`diag` fails when others can access it and warns when its
  group can write it, when it is not owned by root – the PHP user included – or for a writable
  directory). Keep no other file with the DSN inside `plugins/galbridge/`. Write switches as
  booleans; check `ROUNDCUBE_GALBRIDGE_*` variables in the environment of the PHP process (`diag`
  lists them).
* **Web server**: deny `plugins/galbridge/` (README 2.6), in particular on iRedMail's nginx, whose
  `static.php` alias serves plugin files directly, and verify it with `diag --url=<webmail URL>`.
* **`dont_override`** as defence in depth: the plugin already ignores user preferences named
  `galbridge_*` and reads the administrator's values from a fresh configuration; additionally list
  the security-relevant options in Roundcube's `$config['dont_override']`, e.g. `galbridge_db_dsn`,
  `galbridge_imap_hosts`, `galbridge_domain_viewer_domains`,
  `galbridge_domain_viewer_domains_exclude`, `galbridge_organizations`, `galbridge_global_book`,
  `galbridge_global_viewer_domains`, `galbridge_global_viewer_domains_exclude`,
  `galbridge_global_data_domains`, `galbridge_global_data_domains_exclude`,
  `galbridge_global_allow_wildcard`, `galbridge_fields`, `galbridge_user_aliases`,
  `galbridge_list_policy`, `galbridge_hidden_addresses`, `galbridge_hidden_localparts`,
  `galbridge_visibility_column`, `galbridge_debug`.
* **Reverse-proxy rate limit on `_action=autocomplete`** – required when the webmail is reachable
  from the Internet. GAL Bridge bounds what one request and what the account can cost (result caps,
  timeouts, connection limit), but it has no rate limiter of its own (see the register below).
  Roundcube sends one autocomplete request per keystroke pause (and one per book with
  `autocomplete_threads`); limit them per client address, e.g. for nginx:

  ```nginx
  # http {} context
  map $arg__action $rc_autocomplete_key { autocomplete $binary_remote_addr; default ""; }
  limit_req_zone $rc_autocomplete_key zone=rc_autocomplete:10m rate=5r/s;
  # in the location that passes Roundcube's PHP requests to PHP-FPM
  limit_req zone=rc_autocomplete burst=20 nodelay;
  ```

  Requests with an empty key are not limited. Roundcube also accepts `_action` in a POST body, which
  `$arg__action` does not see: a coarser per-address limit on all Roundcube PHP requests covers that.
* Run `plugins/galbridge/bin/galbridge.sh diag` after every Roundcube, iRedMail or plugin upgrade.

## Logs

* **The plugin's log.** The `galbridge` log (`<log_dir>/galbridge.log`, or the mail log with
  iRedMail's syslog setup) records the numeric Roundcube user id, book ids, counts, SQLSTATEs,
  reason codes, 12-character hashes of rejected group IDs and server names (`host_mismatch`) – never
  search terms, addresses or names at normal levels. `galbridge_debug` adds only driver error texts,
  exception messages and schema hints; driver and exception texts are not filtered and may contain
  personal data: keep it off in normal operation. Restrict access to `log_dir` and limit retention.
* **Database server logs can contain search terms.** `rcube_db` inlines bound values into the SQL
  text on the client side, so the statements the server sees – and may log – carry the literal
  search words and addresses.
  * *PostgreSQL*: the grant set gives the role `log_statement = 'none'`,
    `log_min_duration_statement = -1` and `log_min_error_statement = 'panic'`, and switches off the
    sampled logging of newer servers (`log_min_duration_sample`, PostgreSQL 13+;
    `log_transaction_sample_rate`, 12+), so its statements stay out of the server log whatever the
    server-wide settings are (`diag`: "statement logging off for this role"; a WARN names the
    settings of a role created without them – apply `grants --grants-only`). An audit extension
    such as pgaudit has settings of its own.
  * *MariaDB/MySQL*: there is no per-account setting. Keep the general query log off (`diag` warns
    while it is on) and restrict access to and retention of the slow query log.
* **Database server logs and the password of the role.** The generated grant statements carry a
  verifier of the password, never the password (README 2.2). This matters most on MariaDB, which
  writes account statements verbatim to its general query log, its binary log (and so to replicas
  and point-in-time backups) and, when they qualify, its slow query log. A password that was set
  with `IDENTIFIED BY '...'` by hand, or with the statements of GAL Bridge 1.0.0, may be in those
  logs: whether to change it is the operator's decision (`grants --reset-password`), based on who
  can read the logs and their backups.
* **Web server access logs contain addresses and search words.** Contact IDs are a reversible
  encoding of the address (`u_janDkowalskiAexampleDcom`) and travel in GET URLs (show, print, QR
  code, export of selected contacts), and Roundcube sends the quick search as a GET request. Apply
  the same retention and access rules to the access logs of the webmail as to the mail log.

## Accepted risks and design decisions

A register of the decisions that leave a risk, or that a reader of the code might take for an
oversight. Each entry names what was decided, why, what remains, what the administrator can do, who
owns the decision and when it is reviewed next; the IDs are those of the threats in
[docs/THREAT_MODEL.md](docs/THREAT_MODEL.md). The entries state the maintainer's position and
the reasons for it. Owner of all entries: the maintainer (Marek Królikowski). They are reviewed
with every minor release and at the latest on the date given.

| ID | Decision | Rationale | Remaining risk | Administrator action | Next review |
|---|---|---|---|---|---|
| GB-26 | **No request rate limiter** in the plugin (ARCHITECTURE 11). | A `$_SESSION` counter is not atomic: parallel requests, which Roundcube itself sends for autocomplete, all pass. It also turns read-only requests into session writers that can overwrite concurrent compose state. The plugin bounds each request (result caps, minimum search length, prefix search in the global book, statement and connect timeouts) and the account (connection limit and statement time on both engines, checked by `diag`). | An authenticated user can send many requests; a user can read the directory they are entitled to at any speed. | Reverse-proxy limit on `_action=autocomplete` (checklist); size the account's connection limit. | 2027-05-31 |
| GB-04 | **Reversible contact IDs** (`<kind>_<encoded address>`, charset `[A-Za-z0-9_]`; ARCHITECTURE 9). | Core moves IDs through HTML ids, JavaScript strings, comma lists, `ID-source` pairs and GET URLs; a compact reversible ID allows a direct scoped lookup and keeps "export selected" URLs short. An ID authorises nothing: every lookup is scoped, and a miss and an out-of-scope ID give the same answer. Opaque IDs would not keep addresses out of the access log either: Roundcube sends searches as GET requests. | Addresses of directory entries a user opened appear in the web server access log. | Access-log retention and access control (Logs). | 2027-05-31 |
| GB-12 | The resolved user is **cached in the session for `galbridge_scope_ttl`** (300 s). | Resolving the login on every request would cost several vmail statements on every mail request. The cache is bound to the user id, login, IMAP server and a keyed fingerprint of the configuration, so a configuration change applies on the next request; a database outage is never cached as "no entitlement"; and every request that reads directory data confirms the mailbox with one statement, so revocation is immediate. | A user's new own alias, or a changed column grant, is seen after at most the cache time. | Lower `galbridge_scope_ttl` (minimum 30) if that must apply faster. | 2027-05-31 |
| GB-11 | The directory is bound to **IMAP servers named by the administrator**; a templated, array or empty `imap_host`, or `include_host_config`, disables the plugin unless `galbridge_imap_hosts` lists the servers. `parse_host()` is never called. | `%d`/`%n` and the per-host configuration come from the client's `Host` header; such a setup cannot tell a directory login from a login through a foreign IMAP server. Host and port of the session are compared. | The plugin cannot verify that a listed server authenticates against this vmail database. | List only such servers, with their ports (checklist). | 2027-05-31 |
| GB-27 | **Accent folding differs between engines** (MariaDB folds, PostgreSQL does not). | Emulating one engine's collation on the other would need per-engine SQL that cannot use the same predicates; the difference is documented instead of hidden. Case, IDNA and whitespace are normalised identically on both. | Search convenience only; scope predicates do not depend on it. | None (README 10). | 2027-11-30 |
| GB-20 | **No per-user opt-out** in iRedMail's SQL schema; hiding uses deny-lists and an optional administrator-created `galbridge_visibility_column`. | The SQL editions have no "hide from address book" column; the plugin does not alter the vmail tables. | A person is listed until an administrator hides them. | Use the hidden lists or add the column (README 3.7). | 2027-11-30 |
| GB-14 | **Unknown `accesspolicy` values hide** the alias account or list (iRedAPD lets them pass); `membersonly` lists are shown to moderators and owners only. | Fail closed; mlmmj subscribers are not in SQL, so membership cannot be evaluated. Compared with the iRedAPD 6.4 sources (ARCHITECTURE 6): every branch is equal or stricter. | None for confidentiality; some users do not see a list they could post to. | `galbridge_list_policy = 'all'` is the explicit opt-out. | 2027-11-30 |
| GB-19 | The **`(domain)` marker of an entry of another domain stands first** in its name (`(domain) Name`). | A recipient field shortens a long name at its end; a marker placed there can be cut off, and the name is text of the other domain. At the start it is always seen – in the suggestion list, in the recipient field, in exports and copies. | Roundcube sorts the autocomplete suggestions of all address books by name and pre-selects the first one; `(` sorts before letters and digits, so entries of other domains are suggested **before** unmarked names, and a user who presses Enter without looking takes the first of them. Under a `contactlist_name_template` that shows another field before `{name}`, entries of other domains are listed without that field. | `galbridge_global_autocomplete = false` keeps the global book out of autocomplete (it stays in the address book and the contact list of the compose window); keep `{name}` first in `contactlist_name_template`. `diag` shows both while they apply. | 2027-11-30 |
| GB-13 | **One global book**; `'*'` data domains need `galbridge_global_allow_wildcard` as a second opt-in. | `'*'` exposes every tenant added later; a typo or copy of the old plugin's defaults must not do that. | With the opt-in, tenants added later are shown. | Prefer explicit data domains. | 2027-11-30 |
| GB-21 | Database errors are logged with the **SQLSTATE only**; connection failures still reach Roundcube's errors log with the PDO driver message (host and user name, never the password). `diag` prints the same message on its `connect` line (in the CLI core writes it to stderr only). | `ignore_errors` keeps core from logging SQL text with inlined search values; core logs connection errors itself. | Host and role name in Roundcube's errors log. | Restrict the Roundcube errors log like the mail log. | 2027-11-30 |
| GB-06 | **Search lists are capped** (`galbridge_max_results` / `galbridge_global_max_results`) with a notice. | A search list on a page is not a recipient set, and the notice says that it is cut. Recipient sets and exports are never cut: they are complete or refused. | A user has to refine a broad search. | Raise the caps if users need longer lists. | 2027-11-30 |
| GB-23 | **One view in `vmail`** (`galbridge_forwardings`), created by the grant set. | The only way to keep the private forwarding targets out of reach of the role; the view stores nothing and is re-created by the grant set. | An iRedMail upgrade that alters the `forwardings` table has to drop the view first (the templates say so). | `grants --grants-only` after such an upgrade. | 2027-05-31 |
| GB-30 | The grant tool prints a **`mysql_native_password` verifier** for MariaDB/MySQL. | MariaDB keeps account statements verbatim in its logs, so the statement must not hold the password; that verifier is the one both MariaDB and MySQL (up to 8.0) accept. The password is 160 random bits. | The method is deprecated in MySQL 8.x and not loaded from 8.4; a verifier in a log plus a captured handshake would allow a login. | MySQL 8.4+: `--clear-text-password`; protect server logs. | 2027-05-31 |
| GB-32 | **No committed `composer.lock`**; development dependencies only. | A plugin is installed into somebody else's application; each PHP version resolves the set it gets. CI records the resolved set of every run and audits it; PHPUnit's lower bounds exclude the releases with known advisories. | Two CI runs can test different dependency versions. | None (development only). | 2027-05-31 |
| GB-01 | **PHP 8.1 is still accepted** although its upstream security support has ended. | The PHP version belongs to the Roundcube installation (Roundcube 1.7 runs on 8.1), and distributions maintain 8.1 packages beyond the upstream date. `diag` warns. | An installation can run an unmaintained interpreter. | Use a maintained PHP (Supported versions). | 2026-12-31 |

## Security review

The code of 1.0.0 was reviewed with Anthropic's Mythos 5.2 on 2026-11-24: a full source review of
the plugin, its command line tool, grant templates, test harness, CI configuration and
documentation, with a check of dependency and platform advisories. It found no vulnerability of
high or medium risk; eleven low-risk findings and seventeen informational ones were all remediated
in release 1.0.1 – they are listed, with their identifiers, in [CHANGELOG.md](CHANGELOG.md), and
the changes to the code are covered by regression tests on all three database engines.

The remediation was reviewed again before 1.0.1 was published: seven areas of the change were read
independently, every finding was challenged and then reproduced by a test that fails on the code
before its fix, and the CI steps were run locally as an unprivileged user. That review found
further defects – in the first version of some fixes (names that Roundcube re-joins when it
composes them from a template, the safeguards of the command line tool against running as root)
and in code the audit had not flagged (a connect timeout appended to the database name, options of
the grant tool that silently fell back to defaults). All of them were fixed before the release and
are listed in the changelog under "Fixed after the review of the remediation". An automated review
is not a certification: report anything it missed as described at the top of this document.
