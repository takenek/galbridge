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

Only the latest release receives security fixes. A release supports the platform named in its
README section 1; for 1.0.x that is Roundcube **1.7.7** (other versions are not supported, `diag`
warns), PHP 8.1 – 8.5 on a branch with security support (`diag` warns otherwise) and the
MariaDB/MySQL and PostgreSQL editions of iRedMail 1.8.x.

## How directory data is protected

* **Scope from the login only.** The books a user gets and the domains they cover are a pure
  function of the authenticated user and the administrator configuration. The home domain comes
  from the vmail row of the login (`mailbox.domain`), never from the domain part of the login string
  and never from request input. The session must come from a configured directory IMAP host, so a
  login through an IMAP server of the user's own choosing gets nothing.
* **One query builder.** Every statement that reads directory entries (lists, searches, counts,
  groups, record lookups) is produced by `QueryBuilder`, whose root predicate is
  `scope AND visibility AND hidden AND filter`, rendered from a fully parenthesised predicate tree
  with bound values. It is built through one constructor (`QueryBuilder::forBook`), which refuses a
  malformed deny-list or visibility column instead of reading it as "no restriction". An empty
  `IN ()` means FALSE and an empty `NOT IN ()` means TRUE; a clause is never omitted. Every returned
  row is re-checked in PHP (domain in the book's scope, not hidden, valid address); a violation is
  dropped and logged (`row_out_of_scope`). The fixed statements of the user resolution (the
  viewer's own row and aliases) and the statistics of `diag` are separate and never list other
  users' entries.
* **Inert books for anything else.** A GAL Bridge source ID that the user may not use (not entitled,
  empty scope, plugin disabled, database unavailable, stale preference, crafted `_source`) gets a
  `DeniedBook`: not ready, no groups, every read empty, never connects to the database. During a
  database outage the books a session already had stay listed as such inert books that only show
  "The directory is temporarily unavailable.".
* **Least privilege, enforced.** A dedicated role with column-level `SELECT` grants on a read-only
  session. The plugin refuses iRedMail's own roles, the Roundcube database user and the password
  plugin's user by name, and any role that can see `mailbox.password`, `recovery_email` or
  `allow_nets` by probing `information_schema` (`role_too_broad`).
* **Data minimisation.** The field allowlist (`galbridge_fields`) intersected with the columns the
  role can read is the only source of the SELECT list, the searchable fields, the sort keys, the
  groups and the export. A field outside it cannot be shown or used as a search oracle.
* **Never silently wrong.** A recipient set (group expansion, compose to selected contacts) is
  complete or refused with a visible message.
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
* The configured directory IMAP hosts authenticate users against the same vmail database, so the
  Roundcube login equals `mailbox.username`.
* The vmail database is trusted for integrity; the plugin never writes to it.
* Roundcube's session storage is trusted. The resolved user (home domain, address set, column probe)
  is cached in `$_SESSION['galbridge']` for `galbridge_scope_ttl` seconds; the only other keys are
  `galbridge_down_until` (the 30-second outage memo) and `galbridge_searchonly` (the `'auto'`
  search-only decision per book). Whoever can write the session store can change them, and could
  equally take over the session. Search criteria that Roundcube keeps in the session are re-validated
  on every use and can never carry SQL.

See [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) for the full threat model and
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design.

## Hardening checklist for administrators

* HTTPS only, `use_https = true`, `session_samesite = 'Strict'` or `'Lax'`.
* **Dedicated role**: create it with `plugins/galbridge/bin/galbridge.sh grants` (README 2.2), never
  reuse `vmail`, `vmailadmin`, `roundcube` or another iRedMail role, and never edit the grant
  templates inside the plugin directory. `diag` must show the password column as not readable and
  no write privileges (read from the database catalogs). The personal-data grant (`telephone`,
  `mobile`, `birthday`) is commented out: uncomment it only for fields you expose; `diag` lists
  granted columns that no configured field uses (INFO), so you can revoke them.
* **Role limits** sized to the PHP-FPM pool: PostgreSQL `CONNECTION LIMIT`
  (`grants --connection-limit=`, default 25; `diag` shows it), `statement_timeout`,
  `idle_in_transaction_session_timeout` and `default_transaction_read_only` (set by the template);
  MariaDB `ALTER USER ... WITH MAX_STATEMENT_TIME 5 MAX_USER_CONNECTIONS 25` (commented out in the
  template and in the `grants` output: uncomment it). On PostgreSQL before 15 review `CREATE` on
  schema `public` (`diag` warns).
* **TLS for a remote database**: PostgreSQL `sslmode=verify-full` (or `verify-ca`) with
  `sslrootcert`, MySQL `ca=` with `verify_server_cert=1`. Server side: MariaDB `REQUIRE SSL` (added
  by `grants` for a non-local `--account-host`); PostgreSQL a `hostssl` line and a matching
  `hostnossl ... reject` line inserted **before** iRedMail's catch-all `host all all 0.0.0.0/0 md5`
  (README 2.2). Keep `galbridge_db_allow_plaintext = false` unless the link is a trusted private
  network.
* **Configuration file** `plugins/galbridge/config.inc.php` (or `config/galbridge.inc.php`) owned by
  `root:<PHP-FPM group>`, mode `0640` (`diag` fails when others can access it and warns when its
  group can write it, for another owner or a writable directory). Keep no other file with the DSN
  inside `plugins/galbridge/`.
* **Web server**: deny `plugins/galbridge/` (README 2.6), in particular on iRedMail's nginx, whose
  `static.php` alias serves plugin files directly.
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
* **Reverse-proxy rate limit on `_action=autocomplete`.** GAL Bridge has no rate limiter of its own
  (see the decisions below). Roundcube sends one autocomplete request per keystroke pause (and one
  per book with `autocomplete_threads`); limit them per client address, e.g. for nginx:

  ```nginx
  # http {} context
  map $arg__action $rc_autocomplete_key { autocomplete $binary_remote_addr; default ""; }
  limit_req_zone $rc_autocomplete_key zone=rc_autocomplete:10m rate=5r/s;
  # in the location that passes Roundcube's PHP requests to PHP-FPM
  limit_req zone=rc_autocomplete burst=20 nodelay;
  ```

  Requests with an empty key are not limited. Roundcube also accepts `_action` in a POST body, which
  `$arg__action` does not see: a coarser per-address limit on all Roundcube PHP requests covers that.
* **Log retention.** The `galbridge` log (`<log_dir>/galbridge.log`, or the mail log with iRedMail's
  syslog setup) records the numeric Roundcube user id, book ids, counts, SQLSTATEs, reason codes,
  12-character hashes of rejected group IDs and server names (`host_mismatch`) – never search terms,
  addresses or names at normal levels. `galbridge_debug` adds only driver error texts, exception
  messages and schema hints; driver and exception texts are not filtered and may contain personal
  data: keep it off in normal operation. Restrict access to `log_dir` and limit retention.
* **Database server logs contain search terms.** `rcube_db` inlines bound values into the SQL text
  on the client side, so the statements the server sees – and logs – carry the literal search words
  and addresses: the MariaDB/MySQL general and slow query logs, and on PostgreSQL every failed
  statement (including `statement_timeout` cancellations) at the default
  `log_min_error_statement = error`, plus whatever `log_statement` / `log_min_duration_statement`
  record. Keep the general log off, restrict access to and retention of the server logs; on
  PostgreSQL a superuser can stop the statement text of this role from being logged with
  `ALTER ROLE galbridge SET log_min_error_statement = 'panic';`.
* **Web server access logs contain addresses.** Contact IDs are a reversible encoding of the address
  (`u_janDkowalskiAexampleDcom`) and travel in GET URLs (show, print, QR code, export of selected
  contacts). Apply the same retention to access logs as to the mail log.
* Run `plugins/galbridge/bin/galbridge.sh diag` after every Roundcube, iRedMail or plugin upgrade.

## Design decisions and accepted risks

Decisions taken in the design review before 1.0.0 (recorded with their alternatives in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)). They are intentional; a later audit should treat them
as known and accepted, not as new findings, unless the stated rationale no longer holds.

| ID | Decision | Rationale | Administrator action |
|---|---|---|---|
| GB-26 | **No request rate limiter** (ARCHITECTURE 11). | A `$_SESSION` counter is not atomic: parallel requests, which Roundcube itself sends for autocomplete, all pass. It also turns read-only requests into session writers that can overwrite concurrent compose state. Resource control is: result caps, minimum search length, prefix search in the global book, statement and connect timeouts, the role's connection limit. | Reverse-proxy limit on `_action=autocomplete` (checklist above); size the role's connection limit. |
| GB-04 | **Reversible contact IDs** (`<kind>_<encoded address>`, charset `[A-Za-z0-9_]`; ARCHITECTURE 9). | Core moves IDs through HTML ids, JavaScript strings, comma lists, `ID-source` pairs and GET URLs; a compact reversible ID allows a direct scoped lookup and keeps "export selected" URLs short. An ID authorises nothing: every lookup is scoped, and a miss and an out-of-scope ID give the same answer. | Access-log retention (checklist). |
| GB-12 | The resolved user is **cached in the session for `galbridge_scope_ttl`** (300 s). | Resolving the login on every request would cost vmail queries on every mail request. The cache is bound to the user id, login, IMAP host and the configuration fingerprint, so a configuration change applies on the next request; a database outage is never cached as "no entitlement". | Lower `galbridge_scope_ttl` (minimum 30) if vmail changes must apply faster. |
| GB-11 | A **templated, array or empty `imap_host`** disables the plugin unless `galbridge_imap_hosts` lists the directory hosts. `parse_host()` is never called. | `%d`/`%n` come from the client's `Host` header; such a setup cannot tell a directory login from a login through a foreign IMAP server. | Set `galbridge_imap_hosts` (bare host names) for such setups. |
| GB-27 | **Accent folding differs between engines** (MariaDB folds, PostgreSQL does not). | Emulating one engine's collation on the other would need per-engine SQL that cannot use the same predicates; the difference is documented instead of hidden. Case, IDNA and whitespace are normalised identically on both. | None (README 10). |
| GB-20 | **No per-user opt-out** in iRedMail's SQL schema; hiding uses deny-lists and an optional administrator-created `galbridge_visibility_column`. | The SQL editions have no "hide from address book" column; the plugin does not alter the vmail schema. | Use the hidden lists or add the column (README 3.7). |
| GB-14 | **Unknown `accesspolicy` values hide** the alias account or list (iRedAPD lets them pass); `membersonly` lists are shown to moderators and owners only. | Fail closed; mlmmj subscribers are not in SQL, so membership cannot be evaluated. | `galbridge_list_policy = 'all'` is the explicit opt-out. |
| GB-13 | **One global book**; `'*'` data domains need `galbridge_global_allow_wildcard` as a second opt-in. | `'*'` exposes every tenant added later; a typo or copy of the old plugin's defaults must not do that. | Prefer explicit data domains. |
| GB-21 | Database errors are logged with the **SQLSTATE only**; connection failures still reach Roundcube's errors log with the PDO driver message (host and user name, never the password). `diag` prints the same message on its `connect` line (in the CLI core writes it to stderr only). | `ignore_errors` keeps core from logging SQL text with inlined search values; core logs connection errors itself. | Restrict the Roundcube errors log like the mail log. |
| GB-06 | **Search lists are capped** (`galbridge_max_results` / `galbridge_global_max_results`) with a notice; an export of a search result contains at most that many entries without a notice, and a refused export downloads nothing (`204 No Content`) without a message (a file download cannot show one). | A search list is not a recipient set; recipient sets are refused instead of being cut. | Raise the caps if users need larger exports. |
| GB-01 | Roundcube **1.7.7 only**. | The backend contract was verified against 1.7.7's call paths; other releases are untested. | Check for a GAL Bridge release before upgrading Roundcube (README 5). |
