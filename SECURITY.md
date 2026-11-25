# Security policy – GAL Bridge

Maintainer: **[Marek Królikowski (TaKeN)](https://github.com/takenek)**.

Business name: **TaKeN.PL Usługi Informatyczne Marek Królikowski**.

## Reporting a vulnerability

Please report security problems **privately**, not in public issues or pull requests.

Use GitHub's private vulnerability reporting for this repository (a GitHub account is required):
<https://github.com/takenek/galbridge/security/advisories/new>. This form is the only private
channel: there is no e-mail address for security reports. The report is not public; only you, the
maintainer and anyone the maintainer adds to it can see it.

Include the affected version, configuration (redact secrets), steps to reproduce and impact. You
will receive an acknowledgement within 7 days. Please allow a reasonable time for a fix before
public disclosure. Never send real database credentials, password hashes, directory exports or
mailbox content.

If the form is unavailable or the maintainer has not answered within 7 days, open a public issue
that only says a private report is waiting or could not be sent. Do not include any details of the
problem in that issue.

Installed copies of this file may be outdated; the current policy is at
<https://github.com/takenek/galbridge/security/policy>.

## Supported versions

Only the latest release of GAL Bridge receives security fixes. A release supports the platform
named in its README section 1:

* **Roundcube 1.7.x** – every release of the 1.7 branch. Patch releases of that branch fix security
  problems of Roundcube itself: install them as soon as they are published; they need no new GAL
  Bridge release. The test suite runs against the oldest release (1.7.0) and the newest one, CI
  repeats it daily against the newest 1.7.x on Packagist while GitHub keeps its scheduled run
  enabled (GitHub disables a schedule after 60 days without activity; the maintainer checks it
  with every release and monthly, docs/RELEASING.md step 3), and a regression found there is fixed
  in a patch release of GAL Bridge. `diag` warns when the core is older than the newest 1.7.x this
  version knows and fails on another branch.
* **PHP 8.1 – 8.5**, the range Roundcube 1.7 runs on. GAL Bridge does not narrow it, because the
  PHP version is chosen by the Roundcube installation, not by a plugin – but run a PHP that still
  receives security fixes: an upstream branch within its support period (8.1 ended on 2025-12-31,
  8.2 ends on 2026-12-31), or the PHP packages of a distribution that maintains them. `diag` warns
  for a branch past its upstream support.
* The **MariaDB/MySQL and PostgreSQL editions of iRedMail 1.8.x**.

## Release signing key

Every release is an annotated Git tag signed with this SSH key (README 2.1 verifies it before
installing):

| | |
|---|---|
| Key | RSA, 8192 bits |
| Fingerprint | `SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU` |
| Principal and namespace | `galbridge-release`, `git` |
| Allowed-signers file | [`.github/release-signers`](.github/release-signers) (in the repository, not in the release archive) |

`git -c gpg.ssh.allowedSignersFile=.github/release-signers verify-tag vX.Y.Z` prints
`Good "git" signature for galbridge-release with RSA key SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`
for a release. The file comes from the same clone as the tag, so it proves nothing by itself:
compare the fingerprint in that line with this section as published on GitHub
(<https://github.com/takenek/galbridge/blob/main/SECURITY.md>) and with the SSH **signing** keys of the
maintainer's GitHub account, <https://api.github.com/users/takenek/ssh_signing_keys> (save the
`key` value of the list to a file and run `ssh-keygen -lf` on it; `https://github.com/takenek.keys`
lists authentication keys, not signing keys). Both sources are on GitHub: whoever took over the
maintainer's account could change them too. The maintainer therefore also publishes the fingerprint
on a channel outside GitHub (docs/RELEASING.md section 0; this section names it once it exists), and
when the key changes, compare with the `SECURITY.md` of a release you verified before as well. CI refuses a tag that a key of the file on `main` did
not sign (docs/RELEASING.md).

**Rotation**: the new key is added to `.github/release-signers` on `main` before it signs a tag; the
old key's line gets `valid-before="YYYYMMDD"`, so tags signed before that day keep verifying; the new
fingerprint replaces the old one here, in README 2.1 and docs/RELEASING.md section 3, and in the
exact-value exceptions of `.gitleaks.toml` and `.gitguardian.yaml`; the changelog of the next release
names the change.

## How directory data is protected

* **Scope from the login only.** The books a user gets and the domains they cover are a pure
  function of the authenticated user and the administrator configuration. The home domain comes
  from the vmail row of the login (`mailbox.domain`, which must be the domain of the login itself),
  never from the login string alone and never from request input. The session must come from a
  configured directory IMAP server – host and port – so a login through an IMAP server of the
  user's own choosing gets nothing; a directory IMAP server on another host must have been reached
  over TLS that verifies it (otherwise whoever sits on that network could answer the login). A
  cached entitlement is confirmed against vmail by every request that reads directory data: a
  mailbox that was disabled, expired or moved – and, with the strict list policy, an own alias that
  was removed – takes effect with the next request; when the cache is renewed the IMAP server
  confirms the session's credentials, so a changed password or a disabled IMAP service ends the
  session's directory.
* **Policy from the administrator's files only.** The options of the core come from Roundcube's own
  configuration object; a `galbridge_*` option that a per-host file of `include_host_config` (chosen
  by the client's `Host` header) sets disables every book; a derived IMAP binding needs a second
  reading of the files to agree with Roundcube on `imap_host`. The policy in effect must be the one
  the administrator wrote: a plugin configuration file that exists but does not load, a
  `galbridge.inc.php` that Roundcube ignores next to `plugins/galbridge/config.inc.php`, and an
  option or `ROUNDCUBE_GALBRIDGE_*` variable this version does not know (a typo, which would leave
  a hidden list empty) each disable every book. A stored user preference never changes an option,
  and the plugin removes such preferences when settings are saved.
* **Configuration is typed, not guessed.** No malformed value means "off" or "no restriction"; each
  is reported, and what it disables depends on what it protects: a switch that is not a boolean, a
  visibility column that is not a string or a malformed hidden list disables **every** book (and the
  switch itself is read as its restrictive value meanwhile); a malformed domain deny-list
  (`*_exclude`) disables the book it protects, for every user; a malformed list of refused database
  roles refuses every connection (the built-in roles cannot be removed from it). Environment
  overrides of switches are parsed by the plugin itself (Roundcube would read the text `false` as
  true). Numbers must be whole numbers in their range (reported, and the default or the bound is
  used).
* **One query builder.** Every statement that reads directory entries (lists, searches, counts,
  groups, record lookups) is produced by `QueryBuilder`, whose root predicate is
  `scope AND visibility AND hidden AND filter`, rendered from a fully parenthesised predicate tree
  with bound values – which Roundcube inlines into the statement text, so no value with a control
  character or a backslash is ever bound (such search input matches nothing, such a department is
  no group, and the connection refuses a NUL byte or a backslash before it sends anything). It is
  built through one constructor (`QueryBuilder::forBook`), which refuses a
  malformed deny-list or visibility column instead of reading it as "no restriction". An empty
  `IN ()` means FALSE and an empty `NOT IN ()` means TRUE; a clause is never omitted. **SQL and PHP
  apply one address rule** (canonical rows: lower-case ASCII letters, digits and `. _ + = -` in the
  local part, an ASCII host name that is the row's domain column – README 3.4): the root predicate
  of every kind renders it for the engine, so a row in another spelling (Unicode NFD, a non-ASCII
  capital, a U-label domain column) or with a character a sink of Roundcube would interpret is not
  offered at all – neither listed, counted, matched, grouped nor part of a full set – and cannot
  escape a hidden list or an exclusion. Every returned row is re-checked in PHP with the same rule
  (domain in the book's scope, not hidden); a violation – a defect or a row changed during the
  request – is dropped and logged with a non-reversible tag of the row (`row_invalid`,
  `row_out_of_scope row=…`), and `diag` lists the entries no book offers. Per-user aliases are **shown and matched under one
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
  targets of the mailboxes. The server builds the rows of the view before it applies any condition
  of a query – MariaDB/MySQL `ALGORITHM = TEMPTABLE` with a `LIMIT` fence, PostgreSQL
  `security_barrier` with an `OFFSET 0` fence – so not even a query sent with the role's own
  credentials evaluates a condition on a private row. The plugin refuses iRedMail's own roles, the
  Roundcube database user and the password plugin's user by name, and, from the catalogs the role
  can read (`RoleScope`), any role that can do more than read the directory (`role_too_broad`): one
  that can read a secret column (`password`, `recovery_email`, `allow_nets`) of any table or the
  `forwardings` table, is a member of another role, has a role attribute, owns the database, holds a
  global privilege or a privilege outside the directory, or may change anything – and a view that
  holds rows of another kind. The DSN is checked in the form the driver receives it (explicit
  target, known parameters only), and a remote database needs TLS that verifies the host name.
* **Data minimisation.** The field allowlist (`galbridge_fields`) intersected with the columns the
  role can read is the only source of the SELECT list, the searchable fields, the sort keys, the
  groups and the export. A field outside it cannot be shown or used as a search oracle; neither can
  a field an entry of another domain does not carry in the global book (first and last name, and
  department and phones under a name template that shows them before `{name}`): it is no search or
  sort criterion for that entry. A department group of the global book is per domain, so no tenant
  can join its accounts to a group of another tenant.
* **Never silently wrong.** A recipient set (group expansion, compose to selected contacts) and
  every export are complete or refused: the set is read with one statement up to the limit of the
  operation plus one row, a set of which an entry could not be delivered is refused – a selection
  with a malformed, gone or invisible contact as well – and an export that cannot be complete –
  including a search result above the list cap and an export during a database error – downloads
  nothing. A limit that only bounds counting never decides what a set holds; a list cut at its
  bound (`galbridge_max_count`, the result cap, the page bound) says so.
* **Names stay text.** Directory text is sanitised for the contexts that later decode it: besides
  control and format characters and `\ " < >`, the ampersand (HTML character references in
  Roundcube's recipient widget), the delimiters of an RFC 2047 encoded word, curly brackets (the
  placeholders of a name template), `$` and runs of `%` (the replacement patterns and highlight
  markers of Roundcube's suggestion list) are neutralised – also where Roundcube would re-join them
  by deleting empty brackets from a name it composes with a template, or where a template puts two
  fields side by side – and every text that can reach a recipient name (names, department, phones,
  group and organization labels) is cut at an at-sign. An address itself follows the canonical
  rule, so it holds nothing a sink of Roundcube interprets; the local part, shown where an entry has
  no name, is treated like a name. The foreign-domain marker of the global book
  precedes the name, and such an entry carries no other text that a name template would show in
  front of it.
* **Read-only, no endpoints.** No actions, no templates, no JavaScript. `init()` returns at once
  without an authenticated user; otherwise it only registers the `addressbooks_list` and
  `addressbook_get` hooks (plus, in the address book, `addressbook_export`, which answers
  `204 No Content` instead of an empty file when a book refused the export, and, in the mail task,
  `contact_exists`, which keeps the GAL Bridge books out of Roundcube's known-contact check – a
  directory entry never makes a sender trusted) and, for compose,
  Redirect-dialog (`bounce`) and autocomplete requests, adds the enabled GAL Bridge source IDs (those
  with autocomplete on) to `autocomplete_addressbooks` and raises `autocomplete_min_length`. It never
  touches the database; a source the user is not entitled to resolves to an inert `DeniedBook`. In
  the Settings task it registers `preferences_update` only, which drops `galbridge_*` keys from the
  preferences Roundcube saves, and does nothing else.

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
* The link between the webmail and a directory IMAP server on another host is protected by TLS
  that verifies the server – the plugin requires it from the session's TLS mode, the Roundcube
  release and `imap_conn_options` (`galbridge_imap_allow_plaintext` is the explicit exception) – and
  no other plugin rewrites the IMAP host, port, TLS mode or socket options of a connection
  (Roundcube's `storage_init` / `storage_connect` hooks): the plugin judges what the session and the
  configuration say, not what another plugin changes at connection time.
* With `include_host_config`, the directory policy lives in the host-independent files: the plugin
  compares every `galbridge_*` option of a request with them and disables every book on a
  difference (only the two book names may differ), because the client chooses the `Host` header.
* The vmail database is trusted for integrity; the plugin never writes to it.
* Roundcube's session storage is trusted. The resolved user (home domain, address set, column probe)
  is cached in `$_SESSION['galbridge']` for `galbridge_scope_ttl` seconds (and confirmed against
  vmail before directory data is read); the entry is bound to user, login, IMAP server and a keyed
  fingerprint of the configuration (HMAC with the `des_key` Roundcube itself uses). The fingerprint
  covers the DSN **without its password**, so the session store holds nothing derived from the
  database password, whatever `des_key` is; `diag` warns when `des_key` is Roundcube's published
  default, empty, not a string or shorter than 24 characters (Roundcube encrypts the IMAP password
  of every session with it). The entry also
  records an IMAP refusal of the session's credentials (for good, `imap_refused`) and the time of
  the next attempt while the IMAP server cannot be asked (`imap_retry_at`). The only other keys are
  `galbridge_down_until` (the 30-second outage memo), `galbridge_searchonly` (the `'auto'`
  search-only decision per book) and `galbridge_env_noted` (the names of the `ROUNDCUBE_GALBRIDGE_*`
  variables in effect, kept so that `env_override` is logged once per session – never their values).
  Whoever can write the session store can change them, and could equally take over the session. Search criteria that Roundcube keeps in the session are re-validated
  on every use and can never carry SQL.

See [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) for the full threat model and
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design.

## Hardening checklist for administrators

* HTTPS only, `use_https = true`, `session_samesite = 'Strict'` or `'Lax'`.
* **Keep Roundcube current**: install every 1.7.x release (above) and run `diag` afterwards.
* **Install a release, not a clone** (README 2.1): verify the signed tag against the release signing
  key above (compare its fingerprint) and unpack its archive. A git clone brings `tests/` – scripts
  that drop databases and reconfigure Dovecot – and the repository history onto the mail server;
  `diag` reports such a tree. Composer does not verify tag signatures: compare the commit it
  installed with the one of the verified tag (README 2.1).
* **Dedicated role, generated as root** (README 2.2): the database administrator runs what
  `grants` prints, so check the plugin files with the system's tools first (`find -L
  plugins/galbridge \( ! -user root -o -perm /022 \) -print` prints nothing, `namei -l` on the
  script shows root-owned directories without group/other write), then run
  `plugins/galbridge/bin/galbridge.sh grants --allow-root ... > /root/galbridge-grants.sql` as root
  under `umask 077` (`--allow-root` refuses code that root does not own alone; `grants` refuses to
  run as the web server user, and any non-root run refuses code another account can change).
  Review the output – the second line names role and form, `grep -n '\\'` finds no backslash, a
  `diff` against the template shows only role, host, database, limit, verifier and the `[role]`
  block – and apply it with the client commands off: `mysql --binary-mode <` or `runuser -u
  postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail <`. Never reuse `vmail`, `vmailadmin`,
  `roundcube` or another iRedMail role (the tool refuses those names), and never edit the grant
  templates inside the plugin directory (take a template from the verified release archive). `diag`
  must show the password column as not readable (refused by the server), the `forwardings` table as
  not readable, no write privileges, no membership, attribute or privilege outside, and the view as
  fenced. The personal-data grant (`telephone`, `mobile`, `birthday`) is commented out: uncomment it
  only for fields you expose; `diag` lists granted columns that no configured field uses (INFO),
  and every column beyond the grant set (WARN), so you can revoke them. The generated statements
  contain a verifier of the password, not the password; the DSN line with the password is on
  standard error, never in the file – keep it private. **Treat the verifier as a secret as well**
  (the output file, and server logs that record account statements): together with a recorded
  login handshake of the role on a link without TLS it allows a login (Logs).
* **Run `diag` as the PHP user, `grants` as root with `--allow-root`** – nothing else as root (the
  tool refuses root without `--allow-root`, and a process whose user it cannot determine): it loads
  PHP code from the plugin and the Roundcube directory. `grants` loads nothing but the plugin's own
  classes and templates, in the plugin directory with the plugin's `lib/` as include path.
  `--allow-root` passes only when all of that code – the plugin, Roundcube's `program/`, every
  plugin, `vendor/`, every configuration directory and every `include_path` directory – belongs to
  root and nobody else can write it, and only when root writes nowhere another account controls:
  with `log_driver = 'file'` the `log_dir` (PHP's `errors.log`) and the directory of an SQLite
  database of Roundcube must be root's alone, otherwise it is refused (iRedMail logs to syslog). Such
  a process works in Roundcube's `program/` directory with the canonical paths it checked, and loads
  a per-host file of `include_host_config` only when it lies in a checked configuration directory
  and belongs to root alone (any other is a WARN: check it with `diag` as the PHP user). Not
  covered: PHP's own configuration (`php.ini`, `auto_prepend_file`, `extension_dir`) and the start
  code of every enabled Roundcube plugin, which runs as root – the web server user stays the account
  to use.
* **Read `diag` with its scope in mind**: it runs in the command line PHP – the PHP version,
  extensions and `ROUNDCUBE_*` / `PG*` variables it reports are those of that process (it says so);
  check the PHP-FPM pool of Roundcube separately (`php-fpm -m`, `env[...]`, `clear_env`), and the
  log line `env_override` for the `ROUNDCUBE_GALBRIDGE_*` variables the web server applies. For
  automated checks use `diag --strict` (a WARN line gives exit code 2, like a FAIL); a run that
  aborts – also a PHP fatal error during Roundcube's start – ends with `Result: aborted` and exit
  code 2. Output and standard error of `diag` are neutralised (no terminal control sequence from a
  database server, an exception or a file name reaches the terminal raw).
* **Account limits** sized to the PHP-FPM pool, set by the grant set on both engines
  (`grants --connection-limit=`, default 25; `diag` shows the limit and warns when there is none):
  the connection limit is a limit the server enforces – PostgreSQL `CONNECTION LIMIT`, MariaDB/MySQL
  `MAX_USER_CONNECTIONS`. `statement_timeout`, `idle_in_transaction_session_timeout` and
  `default_transaction_read_only` (PostgreSQL) and MariaDB's `MAX_STATEMENT_TIME` are role
  DEFAULTS, which a session opened with the role's password may change; the role is read-only
  because it holds nothing but `SELECT`, and the statement time of the plugin's own sessions is
  `galbridge_db_timeout`, set on every connection on every engine (a server that refuses it, or the
  read-only mode, is not used; `diag` shows the session's value).
* **PostgreSQL: no CREATE for the role, no other database.** The grant set takes `CREATE` on schema
  `public` away from `PUBLIC` in the `vmail` database (the default of PostgreSQL 15 and later; the
  owners of tables there keep it by name) and resets the role's attributes in every form; `diag`
  fails while the role may create objects – in its own schema, schema `public`, any other schema or
  the database – or owns any, and the plugin refuses a role with `CREATE` on another schema (a
  privilege outside, like any other privilege granted to it in the database). Limit the role to the `vmail` database in
  `pg_hba.conf`, before iRedMail's catch-all line (README 2.2): `PUBLIC` holds `CONNECT` on the
  other databases (`diag` lists them as INFO).
* **Bound the PHP worker**: set PHP-FPM's `request_terminate_timeout` for the Roundcube pool (e.g.
  `60s`). On PostgreSQL it is the only bound against a database server or proxy that stops
  answering after the connection is set up (libpq has no read timeout), and so it is on
  MariaDB/MySQL with a `pdo_mysql` not built on `mysqlnd` (`diag` WARN `read timeout`); otherwise
  the plugin's MariaDB/MySQL connection gives up after its read timeout (README 3.9). Do not raise
  `mysqlnd.net_read_timeout` with `php_admin_value` for the pool: the plugin can then not lower it
  for its connection (log `read_timeout_not_applied`).
* **TLS for a remote database**: PostgreSQL `sslmode=verify-full` with `sslrootcert` (`verify-ca`
  does not check the host name and is refused unless `galbridge_db_allow_verify_ca` is set – only
  for a private CA that signs nothing but the database server), MySQL `ca=` with
  `verify_server_cert=1`. Server side: MariaDB `REQUIRE SSL` (added by `grants` for a non-local
  `--account-host`; `diag` warns about an account other hosts may use without it); PostgreSQL a
  `hostssl` line and a matching `hostnossl ... reject` line inserted
  **before** iRedMail's catch-all `host all all 0.0.0.0/0 md5` (README 2.2). Keep
  `galbridge_db_allow_plaintext = false` unless the link is a trusted private network.
* **One vmail database behind every directory IMAP server.** With more than one IMAP server, a
  templated `imap_host` or `include_host_config`, list the servers in `galbridge_imap_hosts`
  (`host:port`) and make sure each of them authenticates against this vmail database (README 3.9).
  Give the port: an entry without one covers every IMAP port of that host, including another IMAP
  service there with a user database of its own (`diag` warns for each such entry). The plugin
  cannot check which user database a listed server uses.
* **IMAP link**: keep the directory IMAP server on the same host (iRedMail's `tls://127.0.0.1:143`,
  or a socket); a server on another host needs `ssl://host:993`, or `tls://` on Roundcube 1.7.7 or
  later, with certificate verification left on in `imap_conn_options` – otherwise its sessions get
  no directory (`diag` FAIL "IMAP transport"). Keep `galbridge_imap_allow_plaintext = false` unless
  the link is a private network nobody else can reach, and run no plugin that rewrites IMAP host,
  port or TLS options at connection time.
* **Revoking a compromised account**: deactivate the mailbox (the directory ends with the next
  request) and change its password (the session keeps no directory when its cache is renewed, even
  after the mailbox is activated again); delete the account's Roundcube sessions to end webmail
  access itself (README 3.9).
* **Configuration file** `config/galbridge.inc.php` of the Roundcube directory (or
  `plugins/galbridge/config.inc.php`) owned by `root:<PHP-FPM group>`, mode `0640` (`diag` fails
  when others can access it and warns when its group can write it, when it is not owned by root –
  the PHP user included – or for a writable directory). Prefer `config/galbridge.inc.php`: it lies
  outside the plugin directory – which a web server rule must deny and which Composer re-creates –
  and survives a replacement of the plugin files; create it **before** a Composer install, because
  Roundcube reads it only while `plugins/galbridge/config.inc.php` does not exist. A configuration
  in the plugin directory stays supported: `diag` without `--url` warns about it, and with `--url`
  asks the web server for the configuration file in use (FAIL when it is served as text; then
  change the role's password with `grants --reset-password`). Keep **one** of the two files: when both exist, Roundcube reads only the one in the
  plugin directory, and GAL Bridge disables every book (`diag` FAIL) – as it does for a file that
  exists but cannot be loaded (not readable by the PHP user, no `$config` array), instead of running
  on built-in defaults. A `galbridge_*` option or `ROUNDCUBE_GALBRIDGE_*` variable this version does
  not know (a typo) disables every book as well (`diag` FAIL with the closest valid name). **With
  Composer use `config/galbridge.inc.php`**, created before `composer require`: Roundcube's plugin
  installer re-creates `plugins/galbridge/config.inc.php` at every install and update with the owner
  and umask of the user running Composer, and keeps a full copy of the plugin directory in
  `plugins/galbridge-<20 digits>` while it runs (left behind when an update fails) – `diag` fails for
  such a copy and warns for a plugin-directory configuration of a Composer installation; run `diag`
  after every `composer update`. Keep no other file with the DSN inside `plugins/galbridge/`. Write
  switches as booleans; check `ROUNDCUBE_GALBRIDGE_*` variables in the environment of the PHP process (`diag`
  lists those of the shell it runs in; the environment of a PHP-FPM pool – `env[...]` in the pool
  configuration – has to be checked there). Keep **every** `galbridge_*` option in this file as
  plain assignments: never in a per-host file of `include_host_config` (the client selects it with
  the `Host` header; such an option disables every book for that host name, `diag` names the file),
  never pulled in with `include_once`/`require_once` (a second reading does not see it; `diag`
  warns), and not in an environment-specific `config-<env>.inc.php` (`diag` warns).
* **Web server**: deny `plugins/galbridge/` and the backup copies Composer names
  `plugins/galbridge-<digits>` (README 2.6), in particular on iRedMail's nginx, whose `static.php`
  alias serves plugin files directly, and verify it with `diag --url=<webmail URL>` – which also asks
  for the configuration file in use (`config over HTTP`). The `.htaccess` of the plugin takes effect
  only on Apache 2.4 with `AllowOverride AuthConfig` (or `All`) for the directory.
* **Debug logging off in normal operation.** `galbridge_debug` (or `ROUNDCUBE_GALBRIDGE_DEBUG=1`)
  writes database driver texts and exception messages, which can contain search words and
  addresses, to the log; `diag` warns while it is on. Switch it on for a diagnosis only, and delete
  or restrict what it wrote.
* **Do not list `galbridge_*` options in `dont_override`.** The plugin ignores user preferences named
  `galbridge_*` (it reads the administrator's values from a second reading of the configuration
  files) and removes every such key whenever Roundcube saves a user's settings (it is loaded in the
  Settings task for that hook only), so `dont_override` adds no protection – and it does harm: on
  every settings save Roundcube stores the current value of each `dont_override` option in the
  user's preferences unless that value comes from the configuration files loaded for the Settings
  page. An option set through a `ROUNDCUBE_GALBRIDGE_*` variable is stored that way, and
  `galbridge_db_dsn` would put the database password into Roundcube's database and its backups.
  1.0.1 recommended such a list; `diag` now fails for `galbridge_db_dsn` in `dont_override`, warns
  for other `galbridge_*` names, and counts the Roundcube users whose stored preferences contain
  `galbridge_db_dsn` (FAIL) or other `galbridge_*` keys (WARN) – counts only, never a value. If
  `galbridge_db_dsn` was stored: remove the names from `dont_override`, give the role a new
  password (`grants --reset-password`) and put the new DSN in place, and treat backups and replicas
  of the Roundcube database as holding the old password; the stored copies themselves are dropped
  when each user next saves settings.
* **Leave loosening switches off when they are not needed.** `diag` warns while
  `galbridge_db_allow_plaintext` or `galbridge_db_allow_verify_ca` is true – also while the DSN names
  a local host (they apply the moment it changes) – and while `galbridge_global_allow_wildcard` is
  true without `'*'` data domains; it warns when the password plugin is enabled with its SQL driver
  but GAL Bridge finds no `password_db_dsn` (then add its database user to
  `galbridge_db_refused_users`), and shows the refused roles. The built-in refused roles cannot be
  removed; a malformed `galbridge_db_refused_users` refuses every database connection.
* **Per-client limits in the reverse proxy** – required when the webmail is reachable from the
  Internet. GAL Bridge bounds what one request and what the account can cost (result caps, the
  contacts one request reads, the parameters of one statement, timeouts, connection limit, and a
  refusal at the connection limit is not memoised for other sessions), but it has no rate limiter
  of its own (see the register below). The primary control is a **request-rate limit per client
  address on every request passed to Roundcube's PHP**, plus a **per-client concurrency limit**
  set well below the role's connection limit (`grants --connection-limit`, default 25), so one
  client cannot hold all connections of the role. For nginx:

  ```nginx
  # http {} context
  limit_req_zone  $binary_remote_addr zone=rc_php:10m rate=10r/s;
  limit_conn_zone $binary_remote_addr zone=rc_conn:10m;
  # in the location that passes Roundcube's PHP requests to PHP-FPM
  limit_req  zone=rc_php burst=40 nodelay;
  limit_conn rc_conn 8;
  ```

  Size the rate to normal use (Roundcube sends one autocomplete request per keystroke pause, and
  one per book with `autocomplete_threads`) and the concurrency limit to a fraction of the role's
  connection limit. Behind a load balancer or another proxy, key on the real client address
  (`set_real_ip_from` / `real_ip_header`), not on the proxy's. A tighter limit for autocomplete
  alone is optional, but cannot replace the limits above: a key such as
  `map $arg__action $key { autocomplete $binary_remote_addr; }` sees only the literal query
  parameter, while Roundcube takes `_action` from the query string, the POST body or a cookie (the
  cookie wins), URL-decodes it and drops every character outside `[A-Za-z0-9_.-]` – a request that
  spells it any other way passes such a limit.
* Run `plugins/galbridge/bin/galbridge.sh diag` after every Roundcube, iRedMail or plugin upgrade.

## Logs

* **The plugin's log.** The `galbridge` log (`<log_dir>/galbridge.log`, or the mail log with
  iRedMail's syslog setup) records the numeric Roundcube user id, book ids, counts, SQLSTATEs,
  reason codes, 12-character hashes of rejected group IDs and server names (`host_mismatch`) – never
  search terms, addresses or names at normal levels; a rejected entry of a configuration list is
  named by its position, never quoted (a hidden address is a person the administrator wanted
  hidden). Context values with blanks are written in double quotes, and passwords are masked in
  every form a DSN or a connection string can carry them. `galbridge_debug` adds only driver error texts,
  exception messages and schema hints; driver and exception texts are not filtered and may contain
  personal data: keep it off in normal operation (`diag` warns while it is on). Restrict access to
  `log_dir` and limit retention.
  * *Volume*: a reason code is written at most once per request, so a request with many bad rows or
    IDs writes one line; a loop of requests still writes up to one line per reason code per request
    – the reverse-proxy limits of the checklist bound it, the plugin has no rate limiter.
  * *Where the lines go*: with `per_user_logging` Roundcube writes the `galbridge` log into the
    user's own directory below `log_dir` when that directory exists, and refuses the line otherwise
    (no user yet, no directory). An ERROR or WARN line that Roundcube refuses – also when the file
    cannot be written – is written to Roundcube's `errors` log instead (prefixed `galbridge:`), and
    to PHP's error log when that fails as well; INFO and DEBUG lines follow Roundcube's decision.
    With `log_driver = 'syslog'` (iRedMail) every `galbridge` line has the syslog priority INFO –
    filter on the level word (`ERROR`, `WARN`) in the line, not on the priority.
* **Database server logs can contain search terms.** `rcube_db` inlines bound values into the SQL
  text on the client side, so the statements the server sees – and may log – carry the literal
  search words and addresses.
  * *PostgreSQL*: the grant set gives the role `log_statement = 'none'`,
    `log_min_duration_statement = -1` and `log_min_error_statement = 'panic'`, and switches off the
    sampled logging of newer servers (`log_min_duration_sample`, PostgreSQL 13+;
    `log_transaction_sample_rate`, 12+), so its statements stay out of the server log whatever the
    server-wide settings are (`diag`: "statement logging off for this role"; a WARN names the
    settings of a role created without them – apply `grants --grants-only`). What stays in the
    server log of the role is its connections (with `log_connections`) and error messages without
    the statement text – so there is **no statement audit trail** of the role: the privacy of the
    search terms was chosen over it (register GB-21). An audit or plan-logging extension (pgaudit's
    `pgaudit.log`, auto_explain's `auto_explain.log_min_duration`) has settings of its own, which
    the grant set does not change: `diag` warns while one of them would log the role's statements.
    Keep such a log only as a deliberate audit trail – it necessarily records the search terms –
    with restricted access and short retention, or switch it off for the role (`ALTER ROLE ... SET
    pgaudit.log = 'none'`, as a superuser). `pg_stat_statements` normalises the constants of the
    plugin's statements (no search term in its view), but keeps the text of utility statements:
    the grant set switches its `track_utility` off for its own transaction, so no `CREATE` / `ALTER
    ROLE` with a verifier stays there.
  * *MariaDB/MySQL*: there is no per-account setting. Keep the general query log off (`diag` warns
    while it is on) and restrict access to and retention of the slow query log.
* **Database server logs and the password of the role.** The generated grant statements carry a
  verifier of the password, never the password (README 2.2). This matters most on MariaDB, which
  writes account statements verbatim to its general query log, its binary log (and so to replicas
  and point-in-time backups) and, when they qualify, its slow query log. A password that was set
  with `IDENTIFIED BY '...'` by hand, or with the statements of GAL Bridge 1.0.0, may be in those
  logs: whether to change it is the operator's decision (`grants --reset-password`), based on who
  can read the logs and their backups. The DSN line with the clear text is written to standard
  error – the administrator's terminal – never into the SQL file, so a client that sends comments to
  the server (some GUI tools do) cannot put it into a server log either. **The verifier is a secret
  too**: the `mysql_native_password` hash (and the SCRAM verifier of PostgreSQL), together with a
  recorded login handshake of the role, allows a login. A handshake can be recorded only on a link
  without TLS: the plugin refuses a remote database host without verified TLS (unless
  `galbridge_db_allow_plaintext` is set, which `diag` warns about), `grants` adds `REQUIRE SSL` for a
  non-local MariaDB/MySQL account (`diag` checks it), and recording on the database host itself
  needs root there. Restrict who can read the grant output and the logs that keep account
  statements; after such a log was exposed, give the role a new password (`grants
  --reset-password`).
* **Web server access logs contain addresses and search words.** Contact IDs are a reversible
  encoding of the address (`u_janDkowalskiAexampleDcom`) and group IDs a reversible encoding of the
  department name or domain (`_gid`); both travel in GET URLs (show, print, QR code, export of
  selected contacts, a group's members), and Roundcube sends the quick search as a GET request. The
  same URLs end up in the browser history of the user's device and in the logs of every proxy or
  load balancer in front of the webmail. Apply the same retention and access rules to all of these
  logs as to the mail log.

## Accepted risks and design decisions

A register of the decisions that leave a risk, or that a reader of the code might take for an
oversight. Each entry names what was decided, why, what remains, what the administrator can do, who
owns the decision and when it is reviewed next; the IDs are those of the threats in
[docs/THREAT_MODEL.md](docs/THREAT_MODEL.md). The entries state the maintainer's position and
the reasons for it. Owner of all entries: the maintainer (Marek Królikowski). They are reviewed
with every minor release and at the latest on the date given.

| ID | Decision | Rationale | Remaining risk | Administrator action | Next review |
|---|---|---|---|---|---|
| GB-26 | **No request rate limiter** in the plugin (ARCHITECTURE 11). | A `$_SESSION` counter is not atomic: parallel requests, which Roundcube itself sends for autocomplete, all pass. It also turns read-only requests into session writers that can overwrite concurrent compose state. The plugin bounds each request (result caps, minimum search length, prefix search in the global book, at most `galbridge_max_ids` contacts read one by one per request – core's copy action –, details of full sets in statements of at most 500 addresses, the connect timeout, the read timeout of a MariaDB/MySQL connection and the statement timeout of every plugin session on all engines – a server that refuses it is not used) and the account (connection limit on both engines; the role's statement time is a default for other sessions, MySQL has none; checked by `diag`). A refusal at the connection limit, or any other answer the server gives at once, fails that request only: only an unreachable or slow server is memoised for 30 s. A request that reads the directory costs one statement to confirm the mailbox (and with the strict list policy one more for the own aliases); a renewal of the cached user (`galbridge_scope_ttl`) costs the user lookup, two catalog statements and one IMAP login in address-book requests. | An authenticated user can send many requests; a user can read the directory they are entitled to at any speed; a client that holds as many parallel requests as the role's connection limit makes other sessions' requests fail while it does (not for 30 s after). The plugin log is bounded per request (a reason code once per request), not per session: a loop of requests adds up to one line per reason code per request. | Per-client request-rate and concurrency limits on every Roundcube PHP request in the reverse proxy, the concurrency limit well below the role's connection limit (checklist – a limit keyed on the query-string `_action` alone is not enough); size the account's connection limit. | 2027-05-31 |
| GB-04 | **Reversible contact and group IDs** (`<kind>_<encoded address>`; group IDs encode the department name or domain; charset `[A-Za-z0-9_]`; ARCHITECTURE 9). | Core moves IDs through HTML ids, JavaScript strings, comma lists, `ID-source` pairs and GET URLs; a compact reversible ID allows a direct scoped lookup without a mapping table and keeps "export selected" URLs – up to `galbridge_max_ids` IDs in one GET request – short and stable. An ID authorises nothing: every lookup is scoped, and a miss and an out-of-scope ID give the same answer. Opaque IDs would not keep addresses out of the logs either: Roundcube sends searches and `_gid` as GET requests. | Addresses of directory entries a user opened, the departments and domains of opened groups and search words appear in the web server access log, the browser history of the user's device and the logs of proxies and load balancers in front of the webmail. | Retention and access control for those logs (Logs); shared devices are the user's concern. | 2027-05-31 |
| GB-12 | The resolved user is **cached in the session for `galbridge_scope_ttl`** (300 s). | Resolving the login on every request would cost several vmail statements on every mail request. The cache is bound to the user id, login, IMAP server and a keyed fingerprint of the configuration, so a configuration change applies on the next request; a database outage is never cached as "no entitlement"; every request that reads directory data confirms the mailbox with one statement (and, with the strict list policy, the own aliases with a second one), so losing the mailbox or an alias applies at once; when the entry is renewed the IMAP server confirms the session's credentials (the role cannot read passwords or service flags, and Dovecot's own checks decide). | A changed password or a disabled IMAP service and a changed column grant are seen after at most the cache time (the password case: when the cache is renewed, then for the rest of the session); a new own alias is taken over by the next reading request and used from the one after it. An IMAP server that cannot be asked makes the directory unavailable, never "refused". | Lower `galbridge_scope_ttl` (minimum 30) if that must apply faster; deactivate a compromised mailbox (immediate) and delete its Roundcube sessions. | 2027-05-31 |
| GB-11 | The directory is bound to **IMAP servers named by the administrator**; a templated, array or empty `imap_host`, `include_host_config`, or an `imap_host` that a second reading of the files does not confirm, disables the plugin unless `galbridge_imap_hosts` lists the servers. `parse_host()` is never called. A server on another host must be reached over verified TLS. | `%d`/`%n` and the per-host configuration come from the client's `Host` header; such a setup cannot tell a directory login from a login through a foreign IMAP server. Host and port of the session are compared, in the raw form the login wrote them (a value in any other form is refused). The IMAP login is the identity proof: over a link without verified TLS (STARTTLS before Roundcube 1.7.7 even falls back to plain text) whoever sits on the network could answer it. With `include_host_config` no `galbridge_*` option may depend on the host name. | The plugin cannot verify that a listed server authenticates against this vmail database, nor see a plugin that changes the IMAP connection at connection time (`storage_connect`); with `galbridge_imap_allow_plaintext` the link is trusted as it is. An entry without a port covers every IMAP port of its host (the stored `mail_host` of a user has no port and is compared by host): another IMAP service on that host with a user database of its own is trusted as well – `diag` warns for each such entry. | List only such servers, with their ports, reached over verified TLS (checklist); keep the directory policy in the plugin's file. | 2027-05-31 |
| GB-27 | **Accent folding differs between engines** (MariaDB folds, PostgreSQL does not). | Emulating one engine's collation on the other would need per-engine SQL that cannot use the same predicates; the difference is documented instead of hidden. Case, IDNA, Unicode composition and non-ASCII spellings are no scope or deny-list concern: only canonical rows (lower-case ASCII; on MariaDB/MySQL ASCII capitals, offered in lower case) are offered on any engine, by the same rule in SQL and PHP, so the hidden lists and exclusions always compare canonical ASCII. | Search convenience only; scope predicates do not depend on it. PostgreSQL does not offer an address written with capitals (MariaDB/MySQL do); a Turkish or Azerbaijani collation of a MariaDB/MySQL address column would let a capital `I` escape a hidden list in SQL (the row is then dropped by PHP; `diag` WARN `collation`). | Keep iRedMail's collation; correct the entries `diag` lists as not offered (README 10). | 2027-11-30 |
| GB-20 | **No per-user opt-out** in iRedMail's SQL schema; hiding uses deny-lists and an optional administrator-created `galbridge_visibility_column`. | The SQL editions have no "hide from address book" column; the plugin does not alter the vmail tables. | A person is listed until an administrator hides them. | Use the hidden lists or add the column (README 3.7). | 2027-11-30 |
| GB-14 | **Unknown `accesspolicy` values hide** the alias account or list (iRedAPD lets them pass); `membersonly` lists are shown to moderators and owners only. | Fail closed; mlmmj subscribers are not in SQL, so membership cannot be evaluated. Compared with the iRedAPD 6.4 sources (ARCHITECTURE 6): every branch is equal or stricter. | None for confidentiality; some users do not see a list they could post to. | `galbridge_list_policy = 'all'` is the explicit opt-out. | 2027-11-30 |
| GB-19 | The **`(domain)` marker of an entry of another domain stands first** in its name (`(domain) Name`). | A recipient field shortens a long name at its end; a marker placed there can be cut off, and the name is text of the other domain. At the start it is always seen – in the suggestion list, in the recipient field, in exports and copies. | Roundcube sorts the autocomplete suggestions of all address books by name and pre-selects the first one. From Roundcube 1.7.6 it sorts with the ICU collator (`rcube_utils::asort()`), where `(` comes before letters and digits, so entries of other domains are suggested **before** unmarked names, and a user who presses Enter without looking takes the first of them. Roundcube 1.7.0 – 1.7.5 sorts with the C library locale of the user's language, which usually ignores `(`: marked entries are then listed among the unmarked names by the domain in their marker (a domain that sorts early comes first), and only the C locale puts them first. With `autocomplete_threads` each book is sorted on its own and the browser lists the answers in the order they arrive. Under a `contactlist_name_template` that shows another field before `{name}`, entries of other domains are listed without that field (and are not found or sorted by it). | `galbridge_global_autocomplete = false` keeps the global book out of autocomplete (it stays in the address book and the contact list of the compose window); keep `{name}` first in `contactlist_name_template`. `diag` shows both while they apply. | 2027-11-30 |
| GB-13 | **One global book**; `'*'` data domains need `galbridge_global_allow_wildcard` as a second opt-in. | `'*'` exposes every tenant added later; a typo or copy of the old plugin's defaults must not do that. | With the opt-in, tenants added later are shown. | Prefer explicit data domains. | 2027-11-30 |
| GB-21 | Database errors are logged with the **SQLSTATE only**; connection failures still reach Roundcube's errors log with the PDO driver message (host and user name, never the password). `diag` prints the same message on its `connect` line (in the CLI core writes it to stderr only). The plugin's own log masks a password in every form a DSN or a connection string can carry it (a safety net: no code path logs one). | `ignore_errors` keeps core from logging SQL text with inlined search values; core logs connection errors itself. | Host and role name in Roundcube's errors log. | Restrict the Roundcube errors log like the mail log. | 2027-11-30 |
| GB-06 | **Search results and list totals are capped** (`galbridge_max_results` / `galbridge_global_max_results`, `galbridge_max_count`, the page bound of 1000) with a notice. | A list on a page is not a recipient set, and the notice says that it is cut; pages beyond the cap are empty. Recipient sets, selections and exports are never cut: they are complete or refused. | A user has to refine a broad search; a book larger than `galbridge_max_count` cannot be browsed to its end. | Raise the caps if users need longer lists; prefer search-only `'auto'` for large books. | 2027-11-30 |
| GB-23 | **One materialised, fenced view in `vmail`** (`galbridge_forwardings`), created by the grant set; PostgreSQL drops and re-creates it on every run. | The only way to keep the private forwarding targets out of what the role may select. The server builds its rows before any condition of a query applies (MariaDB/MySQL `ALGORITHM = TEMPTABLE` + `LIMIT 18446744073709551615`, PostgreSQL `security_barrier` + `OFFSET 0`), so even a query sent with the role's credentials evaluates nothing on a private row; the statements of the plugin refer to it in uncorrelated semi-joins only (built once per statement). Re-created afresh on PostgreSQL, no grant, owner, rule or comment of an earlier view survives. `diag` checks rows and object, the plugin refuses a view with other rows and warns for an unfenced one. | Every statement that reads the view builds it: the cost grows with the number of alias and list rows; the time a query takes reveals the size of the table, nothing about a single row. The suites read the fence from the server's plan on the tested versions (README section 1: MariaDB 10.5 to 13.1, MySQL 8.0 to 26.10, PostgreSQL 13 to 19; CI's job `databases` repeats a set of them); a server release outside that set could plan the view differently – `diag` checks its definition, not the plan. An iRedMail upgrade that alters the `forwardings` table has to drop the view first; on PostgreSQL a second GAL Bridge role on the same database loses its grant on the view with every run of another role's grant set. | `grants --grants-only` after such an upgrade, and for each GAL Bridge role; watch `diag --user` times on very large alias sets. | 2027-05-31 |
| GB-23 | **A MariaDB/MySQL server-wide test database is no refusal reason**: `test` or a name starting with `test_`, visible to the role and holding no grant of the account's own, is a warning (`server_test_database`) and a `diag` FAIL, not `role_too_broad`. | `mariadb-install-db` (RHEL and its rebuilds, FreeBSD, the official tarballs) creates `test` and grants every account all on `test` and `test_*` (MariaDB 10.11 and later through `PUBLIC`, older servers through blank-user rows of `mysql.db`); refusing the role for it would take every book away on each such installation although the role holds nothing of its own there – the same reasoning as for `CREATE` on schema `public` of PostgreSQL 14 and older (THREAT_MODEL GB-23). Any other database the role can see, and a test database it holds a privilege on, stay refusals. | While the database exists, whoever holds the role's password can create tables in it, store data there and use the server's resources; the exemption is by name, so a `test_*` database an administrator opens to every account is treated alike. | Remove it: `mariadb-secure-installation` ("Remove test database and access to it") or `DROP DATABASE test; DELETE FROM mysql.db WHERE Db IN ('test', 'test\\_%'); FLUSH PRIVILEGES;` as the database administrator; `diag` fails until then. | 2027-05-31 |
| GB-30 | **`grants` runs as root with `--allow-root`**; as any other user it refuses the web server's identity and code another account can change. | The database administrator runs its output: produced by a process of the web server user it could be read or changed by any other process of that user (ptrace, `/proc`); produced from code another account can change it says what that account wants. The output holds no client command (no backslash, no long-form mysql command; checked before printing), the DSN line is on standard error, and README 2.2 gives the check of the files with system tools, the review and the apply commands with client commands off. | The self-check of `galbridge.sh` is only as trustworthy as that file: a changed script can skip it – hence the check with `find` / `namei` before a root run. PHP's own configuration (`php.ini`, `auto_prepend_file`) is read before the script runs. | Check the files as README 2.2 shows, keep the plugin root's (also after Composer), review the output, apply it with `mysql --binary-mode` / `psql -X`. | 2027-05-31 |
| GB-30 | The grant tool prints a **`mysql_native_password` verifier** for MariaDB/MySQL (and a SCRAM-SHA-256 verifier for PostgreSQL). | MariaDB keeps account statements verbatim in its logs, so the statement must not hold the password; that verifier is the one both MariaDB and MySQL (up to 8.0) accept. The password is 160 random bits, so the verifier cannot be reversed by guessing. Generating `caching_sha2_password`, ed25519 or PARSEC verifiers was rejected: none of them is the default on MariaDB, and MySQL 8.4+ has `--clear-text-password`. | The method is deprecated in MySQL 8.x and not loaded from 8.4. A verifier counts as a secret: together with a recorded login handshake of the role it allows a login – possible only on a link without TLS, which the plugin refuses for a remote host unless `galbridge_db_allow_plaintext` is set (`diag` WARN), which `REQUIRE SSL` excludes for a non-local MariaDB/MySQL account (`diag` checks it), and which on the database host needs root. | Keep the grant output and the server logs that record account statements private; after an exposure `grants --reset-password`; MySQL 8.4+: `--clear-text-password`. | 2027-05-31 |
| GB-32 | **No committed `composer.lock`**; development dependencies only. | A plugin is installed into somebody else's application; each PHP version resolves the set it gets, and one lock cannot serve PHPUnit 10.5, 11.5 and 13.4 across PHP 8.1 – 8.5. Instead every CI job that resolves dependencies records the set (versions and source commits) and keeps its `composer.lock` as an artifact for 90 days; Composer is pinned by version and SHA-256; `composer audit` fails on advisories, malware-list matches and abandoned packages (the pinned 1.7.0 core of the compatibility legs is the only exception); PHPUnit's lower bounds exclude the releases with known advisories. | Two CI runs can test different dependency versions. Development dependencies execute in CI and in the disposable verification container of a release (installed from the lock of the release commit's CI run), never on the machine that signs. | None (development only). | 2027-05-31 |
| GB-32 | **The release checks run from the tagged commit**, and GitHub hosts the repository, the key file and the maintainer's signing keys. | GitHub runs the workflow of the pushed tag; checks pinned outside it would need a second CI system. The key file is taken from `main`, GitHub verifies the signature as well, the maintainer runs the same scripts before the push, and rulesets protect `main` and the release tags once the repository is public (docs/RELEASING.md section 0). | Whoever controls the maintainer's GitHub account can change the workflow, the key file and the fingerprint shown on GitHub together. | Compare the fingerprint of a release with a source outside GitHub (SECURITY.md "Release signing key") and, after a key change, with an earlier verified release. | 2027-05-31 |
| GB-24 | **PostgreSQL `localhost` is accepted as local, with a warning** (MariaDB/MySQL `localhost` is the unix socket of `pdo_mysql.default_socket` and needs no lookup). | libpq resolves the name through the system resolver on every connection; refusing it would break existing PostgreSQL installations that use it, while on a standard system `localhost` resolves from `/etc/hosts` to the loopback address. The DSN is otherwise judged and handed to the driver rebuilt from exactly the judged parts; `localhost` is kept as written, not resolved or rewritten by the plugin (README 3.9). | A manipulated `/etc/hosts` or `nsswitch.conf` of the web server could send the connection – without the TLS a remote host needs – elsewhere. | Write `127.0.0.1`, `[::1]` or `unix(/var/run/postgresql)`; `diag` and the log (`dsn_warning`) warn while `localhost` is used. | 2027-05-31 |
| GB-26 | **No client read timeout on PostgreSQL** (and with a `pdo_mysql` not built on `mysqlnd`) after the connection is set up. | libpq has none, Roundcube passes no keepalive settings to it, and neither would help against a frozen server whose kernel still acknowledges packets. The connect timeout covers the TCP connection and PostgreSQL's start-up; MariaDB/MySQL connections get a read timeout from the plugin. | A database server or proxy that stops answering mid-statement holds the PHP worker until the request is ended by PHP-FPM. | Set `request_terminate_timeout` for the Roundcube pool (checklist). | 2027-05-31 |
| GB-12 | **Expiry by two clocks**: an entry is hidden once its `expired` time has passed by UTC **or** by the database server's clock in its session time zone. | The `expired` columns have no time zone, and the plugin cannot rely on the convention of whatever wrote them; the earlier of the two moments is the safe one (ARCHITECTURE 5). | On a server east of UTC an entry written in UTC disappears up to the offset before its UTC expiry; west of UTC an entry written in local time disappears up to the offset early. Never late. | None; set the database server's time zone deliberately. | 2027-11-30 |
| GB-31 | **The configuration file may stay in the plugin directory** (`plugins/galbridge/config.inc.php`); `config/galbridge.inc.php` is recommended. | Roundcube reads a plugin's configuration from either place, existing installations and Composer's installer use the plugin directory, and refusing it would break them. The plugin directory is the one place a web server that serves plugin files would hand the database password out, so `diag` warns while the file lies there and `--url` was not given, and with `--url` it asks the web server for the configuration file in use (FAIL when it is served as text; nothing of the answer is printed). | A web server that serves PHP files of the plugin directory as text, without a deny rule, hands out the DSN; `.htaccess` protects only on Apache 2.4 with `AllowOverride AuthConfig` or `All`. | Use `config/galbridge.inc.php`, deny the plugin directory (README 2.6) and run `diag --url`; after a FAIL change the role's password. | 2027-05-31 |
| GB-22 | **Debug logging writes unfiltered driver and exception texts**; the log is bounded per request, and Roundcube may refuse a line. | The texts are what a diagnosis needs, and they cannot be filtered for personal data without losing that. Reason codes are written once per request; throttling per session would need session writes on read requests and would hide repeated probing. An ERROR or WARN line that Roundcube refuses (`per_user_logging` without a log directory of the user, an unwritable file) goes to its errors log instead. | With `galbridge_debug` on, search words and addresses can reach the log; a loop of requests writes up to one line per reason code per request; under syslog every line has the priority INFO. | Keep debug off (`diag` WARN), restrict and rotate `log_dir` or the mail log, limit request rates in the reverse proxy (checklist). | 2027-05-31 |
| GB-21 | **PostgreSQL: the role's statements are kept out of the server log** (grant set: `log_statement`, `log_min_error_statement`, `log_min_duration_statement` and the sampled logging off for the role). | `rcube_db` inlines the search terms into the statement text; a statement log of the role is a log of what users searched for. | No statement audit trail of the role on the database side: misuse of its credentials leaves connection lines (with `log_connections`) and error messages without the statement text. An audit extension (pgaudit, auto_explain) configured separately still logs the statements, search terms included. | Choose deliberately: keep the default, or enable an audit extension for the role with a restricted, short-lived log; `diag` warns while pgaudit or auto_explain would log the role's statements. | 2027-05-31 |
| GB-01 | **PHP 8.1 is still accepted** although its upstream security support has ended. | The PHP version belongs to the Roundcube installation (Roundcube 1.7 runs on 8.1), and distributions maintain 8.1 packages beyond the upstream date. `diag` warns. | An installation can run an unmaintained interpreter. | Use a maintained PHP (Supported versions). | 2026-12-31 |

## Security review

The code of 1.0.0 was reviewed with Anthropic's Mythos 5.2 on 2026-11-24: a full source review of
the plugin, its command line tool, grant templates, test harness, CI configuration and
documentation, with a check of dependency and platform advisories. It found no vulnerability of
high or medium risk; eleven low-risk findings and seventeen informational ones were all remediated
in 1.0.1 – a development version that was never tagged or published (neither was 1.0.0); the first
published release, 1.0.2, carries these fixes and those listed under 1.0.2 – they are listed, with
their identifiers, in [CHANGELOG.md](CHANGELOG.md), and
the changes to the code are covered by regression tests on all three database engines. The
remediation was verified against the audit report on 2026-11-25 (Anthropic's Claude Opus 5.5);
the fixes it completed are listed under 1.0.2 in the changelog.

The remediation was reviewed again before 1.0.1 was completed: seven areas of the change were read
independently, every finding was challenged and then reproduced by a test that fails on the code
before its fix, and the CI steps were run locally as an unprivileged user. That review found
further defects – in the first version of some fixes (names that Roundcube re-joins when it
composes them from a template, the safeguards of the command line tool against running as root)
and in code the audit had not flagged (a connect timeout appended to the database name, options of
the grant tool that silently fell back to defaults). All of them were fixed in 1.0.1 and are listed
in the changelog under "Fixed after the review of the remediation".

A second, independent assessment of 1.0.1 (Anthropic's Mythos 5.2, 2026-11-25: a full source review
of the plugin, the command line tool, the grant templates, CI and release configuration, the test
harness and the documentation, against the Roundcube 1.7.0 – 1.7.7 sources, with a check of
dependency and platform advisories) reported 36 findings – one of high risk (the IMAP binding,
already corrected by the verification above), one of medium risk (the provenance of the `grants`
output), 23 of low risk and 11 informational ones, among them inaccurate documentation and the
residual risks of the decisions in the register above. Every one of them was remediated on
2026-11-25 with Anthropic's Claude Opus 5.5 – in the code where code can help (fail-closed
checks, `diag` WARN and FAIL lines), otherwise in this document and the threat model – each with a
regression test that fails on the code before its fix, verified with the suites on all three
database engines; they are listed under 1.0.2, "Second verification pass", in
[CHANGELOG.md](CHANGELOG.md), and the accepted risks that remain are in the register above. What
lies outside the repository – the settings of the GitHub repository and of Packagist, and the
reverse proxy, web server and logs of each installation – is named there and in the checklist. An
automated review is not a certification: report anything it missed as described at the top of
this document.
