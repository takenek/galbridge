# GAL Bridge – Upgrade, uninstall and troubleshooting

The full text of sections 5 – 7 of the README: the upgrade of the plugin, of Roundcube and of
iRedMail with the notes of every earlier release, rollback and uninstall, and troubleshooting with
every log reason code. [README.md](../README.md) sections 5 – 7 have the short form.

Section numbers are shared by [README.md](../README.md) and the documents of `docs/`: sections 1 and
2 are in [INSTALLATION.md](INSTALLATION.md), 3, 4 and 10 in [CONFIGURATION.md](CONFIGURATION.md), 5
– 7 in [OPERATIONS.md](OPERATIONS.md), 8, 9 and 11 – 14 in the README. "Section 3.9" in this text is
section 3.9 of CONFIGURATION.md, "section 2.6" section 2.6 of INSTALLATION.md.

## 5. Upgrade

**The plugin.** Read [CHANGELOG.md](../CHANGELOG.md) first. Then, as root in the Roundcube directory:

*Release archive* – the first block of 2.1 and the fingerprint comparison for the new version, then
unpack it over the old files and check that nothing of the old release is left:

```sh
git -C /root/galbridge-src -c tar.umask=0022 archive --prefix=galbridge/ "v$v" | tar -x -C /opt/www/roundcubemail/plugins
mkdir -m 0700 /root/galbridge-check
git -C /root/galbridge-src archive "v$v" | tar -x -C /root/galbridge-check
diff -r /root/galbridge-check/ plugins/galbridge/ && echo identical   # "Only in plugins/galbridge/: X": a file the new release no longer has - delete it (not config.inc.php)
rm -rf /root/galbridge-check /root/galbridge-src
```

A configuration in `config/galbridge.inc.php` is not touched; one in `plugins/galbridge/config.inc.php`
stays as well (`tar -x` only adds and replaces), but better move it to `config/galbridge.inc.php`
(2.3).

*Composer* – in an interactive root shell (answer `yes` to the root question; an update asks no
activation question), or with `COMPOSER_ALLOW_SUPERUSER=1`, **never** from cron or a script without a
terminal: Composer then skips Roundcube's plugin installer, puts the new release into
`vendor/takenek/galbridge` and leaves `plugins/galbridge` as it was (2.1; `diag` warns, "composer
copy"):

```sh
composer update --prefer-dist --ignore-platform-req=ext-ldap takenek/galbridge   # the option for iRedMail's SQL editions (2.1)
```

Then compare the installed files with `git archive` of the verified tag of the new version and the
commit Composer names with the tag's – the commands of 2.1 (Composer does not check signatures, and
Packagist publishes no checksum). The installer re-creates a `plugins/galbridge/config.inc.php` with
the owner and umask of the user who ran Composer – restore `chown root:www-data` and `chmod 0640`,
or better move the configuration to `config/galbridge.inc.php` and delete the plugin-directory file
– and a failed update leaves a `plugins/galbridge-<20 digits>` copy with the password in it (delete
it; section 2.1).

Either way, then run `sudo -u www-data plugins/galbridge/bin/galbridge.sh diag` (without sudo:
`runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag`) from the Roundcube directory (2.7;
as root it is refused); its first line names the release that runs (`[ OK ] GAL Bridge X.Y.Z`).
There are no plugin tables, so there is no schema update; options added by a new release have
built-in defaults.

**From 1.0.7 to 1.0.8** – no plugin, configuration or database step: the README is now the short
form, and its full text is in docs/INSTALLATION.md, docs/CONFIGURATION.md and docs/OPERATIONS.md
under the same section numbers (CHANGELOG); two WARN lines of `diag` (`debug`, `entries not offered`) name
docs/CONFIGURATION.md instead of README 3.x. After a release-archive upgrade, the `diff` of this section lists no file of
1.0.7 that 1.0.8 no longer has.

**From 1.0.6 to 1.0.7** – no plugin, configuration or database step: 1.0.7 corrects the
documentation and one INFO line of `diag` (CHANGELOG). 2.6 now has commands for the per-client
limits; on a server that has them already they change nothing.

**From 1.0.5 to 1.0.6** – no plugin, configuration or database step: 1.0.6 corrects the
documentation (CHANGELOG). A cron or monitoring job built from the `diag --strict` example of 2.7
checked no web access – give it the `--url=` (and `--cafile=`) of 2.7, so that it notices a deny
rule of 2.6 lost in an iRedMail upgrade.

**From 1.0.4 to 1.0.5** – no plugin, configuration or database step. `diag` names the release that
runs (`GAL Bridge`), and its `connection limit` line is a WARN for a limit above 25 that takes more
than a quarter of the connections the server has for ordinary roles – such as the 100 the 1.0.3
advice gave iRedMail installations: lower it as shown below. INSTALLATION.md 2.1 installs nothing before
`verify-tag` and the fingerprint comparison agree; 2.2 has a command that inserts the `pg_hba.conf`
lines; the release-archive upgrade above checks for files of the old release.

**From 1.0.3 to 1.0.4** – no plugin or configuration step, but check an existing installation
against the corrected instructions (CHANGELOG):

* **Connection limit** (both editions; 2.2): on iRedMail the default 25 – MariaDB keeps
  `max_connections = 151` and PostgreSQL 100 on Debian and Ubuntu. The 1.0.3 instructions suggested
  the PHP-FPM pool (100). Read the limit from `diag` (`connection limit`, a WARN since 1.0.5) or with
  `mysql -Ne "SELECT max_user_connections FROM mysql.user WHERE user = 'galbridge'"` /
  `runuser -u postgres -- psql -XAtc "SELECT rolconnlimit FROM pg_roles WHERE rolname = 'galbridge'"`,
  and set it back to 25 with the grant set (its second line names the limit it applies):

  ```sh
  cd /opt/www/roundcubemail
  find -L plugins/galbridge \( ! -user root -o -perm /022 \) -print   # must print nothing
  umask 077
  plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql --grants-only > /root/galbridge-grants.sql   # PostgreSQL: --driver=pgsql
  # review it as in 2.2, then apply it as the database administrator:
  mysql --binary-mode < /root/galbridge-grants.sql                                          # MariaDB / MySQL
  runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql   # PostgreSQL
  shred -u /root/galbridge-grants.sql
  ```

* **PostgreSQL edition: the unix socket** (2.2) – add `local all galbridge reject` before iRedMail's
  `local all all md5`; without it the role can log in over the socket to every database, which
  `diag` cannot see. If `galbridge_db_dsn` uses the socket (`unix(/var/run/postgresql)`), first put
  `local vmail galbridge scram-sha-256` there (2.2) – otherwise the `reject` line ends the directory.
  The three `host` lines of 1.0.3 may stay where they are (a `host` line never matches a socket
  connection). Then check the file, reload and run the two test logins of 2.2:

  ```sh
  f=$(runuser -u postgres -- psql -XAtc 'SHOW hba_file')   # Debian 13 (iRedMail 1.8.11): /etc/postgresql/17/main/pg_hba.conf
  grep -qE '^local[[:space:]]+all[[:space:]]+galbridge[[:space:]]' "$f" || sed -i -E '0,/^local[[:space:]]+all[[:space:]]+all[[:space:]]/s//local      all    galbridge                                       reject          # no login over the unix socket\
  &/' "$f"
  ```

* iRedMail's `config/config.inc.php` owned by root (2.4); the per-client limits in nginx (2.6 –
  `grep -rn 'limit_req\|limit_conn' /etc/nginx/` shows what is there); for a Composer installation
  no `vendor/takenek/galbridge` (`diag` WARN `composer copy`, section 7). `diag` prints a new OK line
  `stored IMAP hosts`.

**From 1.0.2 to 1.0.3** – no configuration or database step. `diag` has the option `--cafile=`
for a web server with a self-signed certificate (2.6), and a Composer installation on iRedMail's
SQL editions needs `--ignore-platform-req=ext-ldap` (2.1).

1.0.0 and 1.0.1 were development versions that were never tagged or published
([CHANGELOG.md](../CHANGELOG.md)): an installation of a published release (1.0.2 or later) needs only
the notes above. The parts below, and the way back to 1.0.0, apply only to a tree installed from a
repository commit before 1.0.2.

**From 1.0.1 to 1.0.2** – what behaves differently (run `diag` after the upgrade):

* **Apply the grant set again**, as root and with the options of your role (`--role`, `--db`,
  `--account-host`, `--connection-limit`), reviewed as section 2.2 describes:

  ```sh
  cd /opt/www/roundcubemail
  find -L plugins/galbridge \( ! -user root -o -perm /022 \) -print     # must print nothing
  umask 077
  plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql --grants-only > /root/galbridge-grants.sql
  mysql --binary-mode < /root/galbridge-grants.sql                                          # MariaDB / MySQL
  # PostgreSQL: --driver=pgsql, then
  runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql
  ```

  It re-creates the view `galbridge_forwardings` materialised and fenced (MariaDB/MySQL
  `ALGORITHM = TEMPTABLE` with a `LIMIT` fence and `SHOW VIEW` for `diag`; PostgreSQL dropped and
  created afresh with an `OFFSET 0` fence), and on PostgreSQL resets the role attributes, takes
  `CREATE` on schema `public` away from `PUBLIC` (the owners of tables there keep it) and keeps the
  statements out of `pg_stat_statements`. Until then the books keep working, the log has
  `view_not_materialised` (and `role_can_create` on a database of PostgreSQL 14 or older), and
  `diag` fails on the view and on `schema public`. On PostgreSQL a second GAL Bridge role on the same
  database loses its grant on the re-created view: apply its grant set as well.
* **`grants` runs as root with `--allow-root`**. `sudo -u www-data ... grants` – the documented 1.0.1
  way – is refused as soon as that user can read the configuration of Roundcube or of the plugin,
  and any non-root run is refused for code that another account owns or can write (a Composer
  installation run as the web server user: `chown -R root plugins/galbridge && chmod -R go-w
  plugins/galbridge` – the owner only, so that a `plugins/galbridge/config.inc.php` keeps its PHP
  group and mode 0640). The DSN line is on standard error, no longer in the SQL file; usage errors and
  refusals are on standard error too; a repeated option and `--account-host` with `--driver=pgsql`
  are usage errors (exit code 1). Scripts that read the DSN line from the output must read standard
  error.
* **A role that can do more than read the directory is refused** (log `role_too_broad`, every book
  denied; `diag` FAIL names the reason): a secret column of any table, a membership in another
  role, a role attribute, the ownership of the database, a global privilege other than `USAGE`, a
  privilege outside the directory database (PostgreSQL: also outside its schema – e.g. `CREATE` or
  `USAGE` on another schema, `EXECUTE` on a function), a privilege to change anything, or a view
  `galbridge_forwardings` with rows of another kind. Roles created by the grant set are not
  affected; for a hand-made role revoke what `diag` names.

* `diag --allow-root` is refused with `log_driver = 'file'` when `log_dir` is not root's alone, and
  when Roundcube's database is an SQLite file in a directory another account can write: run `diag`
  as the web server user, as documented (section 2.7), or log to syslog (iRedMail's setting). A
  process of root now works in Roundcube's `program/` directory and drops `include_path` entries
  that do not exist, and it no longer loads a per-host file of `include_host_config` that lies
  outside the configuration directories or is not root's alone (a WARN names it).
* `diag` reports more: WARN lines for configuration files and directories that belong to the web
  server user, for plugin code that user owns, for files inside the release directories and for an
  unreadable Roundcube database; INFO lines about the process and its environment. `Result: OK with
  N warning(s)` can appear where 1.0.1 printed `Result: OK`, and the summary names a web access that
  was not checked; the exit code stays 0 unless `--strict` is given (scripts that match whole lines
  may need an update).
* A `galbridge_imap_hosts` entry `unix:///path` whose path is not printable ASCII (or holds a blank
  or `%`) disables the plugin; a session or stored `mail_host` with such a socket path gets no
  directory (`host_mismatch`).
* **A directory IMAP server on another host needs verified TLS**: `imap_host` (or the server a user
  types) with `ssl://host:993`, or `tls://` on Roundcube 1.7.7 or later, PHP's `openssl` extension and
  certificate verification on. A session on such a server over plain text, over `tls://` with an
  older core or a `1.7-git` core, or with `verify_peer` off gets no books until that is fixed (log
  `imap_transport`, `diag` FAIL "IMAP transport"); `galbridge_imap_allow_plaintext = true` accepts it
  for a trusted private link. Loopback and sockets – the iRedMail default – are not affected.
* **With `include_host_config`**: move every `galbridge_*` option out of the per-host files into
  the plugin's configuration file (`config/galbridge.inc.php`, or `plugins/galbridge/config.inc.php`
  if that is the one in use – never both, section 2.3) – an option such a file sets disables every
  book for that host name (`diag` FAIL "per-host configuration" names the file and the options). The
  two book names may stay there.
* **Core options are read as Roundcube applies them** (its own configuration object). An `imap_host`
  set through `include_once`/`require_once`, an environment-specific `config-<env>.inc.php` or
  another plugin, without `galbridge_imap_hosts`, now disables the plugin (`diag`: FAIL "directory
  hosts", WARN "configuration read again") until `galbridge_imap_hosts` is set or the configuration
  is written as plain assignments. A database user named by Roundcube's `db_dsnr` is refused as
  well. The configuration fingerprint changes once: every session resolves its user again (one
  lookup).
* **Revocation**: when the cached user is renewed (`galbridge_scope_ttl`), the IMAP server confirms
  the session's credentials (one IMAP login per session and period in address-book requests); a
  changed password or a disabled IMAP service ends that session's directory. With the strict list
  policy a removed own alias applies with the next request, at the cost of one bounded statement
  more per request that reads the directory.
* The hook `addressbook_export` is registered for every request of the address book (it acts only
  after a refused export), and the hook `contact_exists` in the mail task: a `collected_senders` or
  `collected_recipients` preference that names a GAL Bridge book no longer makes directory entries
  "known contacts". Action names are compared in Roundcube's canonical form (`EXPORT` is `export`).
* **Every book is disabled** (log `config_invalid`, `diag` FAIL naming the cause) – instead of the
  plugin running on built-in defaults or on the file you did not edit – when: a `galbridge_*`
  option or `ROUNDCUBE_GALBRIDGE_*` variable is not an option of this version (a typo: `diag`
  names the closest valid one); both `plugins/galbridge/config.inc.php` and a
  `config/galbridge.inc.php` exist (keep one); the plugin's configuration file exists but cannot be
  loaded (not readable by the PHP user, no `$config` array). Run `diag` before you switch over.
* **`galbridge_db_refused_users` only adds names** to the built-in ones (iRedMail's roles, `root`,
  `postgres`), and a value that is not a list of role names refuses every database connection: a
  configuration that removed a built-in name in order to connect with that role, or that has a
  malformed list, loses its books until it uses the dedicated role or a valid list.
* **Numbers are whole numbers**: a fraction (`2.5`), an exponent (`1e3`) or a boolean is reported
  and replaced by the default (1.0.1 cut `2.5` to 2), a value outside the range is reported and
  clamped (`diag` WARN).
* **Remove `galbridge_*` names from `dont_override`** (1.0.1's SECURITY.md recommended them): `diag`
  fails for `galbridge_db_dsn` there. If `diag` counts users with `galbridge_db_dsn` in their stored
  preferences, give the role a new password (`grants --reset-password`), put the new DSN in place
  and treat backups of the Roundcube database as holding the old one. The plugin is now loaded in
  the Settings task, for the hook that drops `galbridge_*` preferences only.
* **Composer installations**: move the configuration to `config/galbridge.inc.php` (section 2.1) and
  widen the nginx rule of section 2.6 to `plugins/galbridge(-[0-9]+)?/` (the installer's backup
  copy). `diag` fails for a leftover backup copy and warns for a plugin-directory configuration.
* `diag` warns while `galbridge_db_allow_plaintext`, `galbridge_db_allow_verify_ca` or (without
  `'*'`) `galbridge_global_allow_wildcard` is on, when the password plugin's DSN is unknown and for a
  short or non-string `des_key`; it shows the refused roles and the size of the policy lists.
* **Log lines**: a context value with a blank, `=`, `"` or `\` is now in double quotes
  (`allowed="127.0.0.1 mail.example.com"`) – adjust log parsers that split on blanks; `config_invalid`
  names the position of a rejected entry instead of the entry; an autocomplete request no longer
  logs `denied_source` for an enabled book the user is not entitled to (only with `galbridge_debug`).
  The configuration fingerprint no longer covers the database password: it changes once, and every
  session resolves its user again (one lookup).
* **DSN spellings that are refused now** (every book is disabled, `diag` FAIL says what to write):
  MariaDB/MySQL `localhost:PORT` (the driver used the socket and ignored the port: write
  `127.0.0.1:PORT`) or a port inside `unix(…)`, `localhost` when `pdo_mysql.default_socket` is not a
  usable socket path, a second port, text around an IPv6 address, the `unix+…` and `tcp(…)`
  notations, a raw `+` in a parameter value (`%2B`), a `?` before the database name, a database name
  with percent-encoding or a `;` suffix, and unix socket paths over 107 bytes. PostgreSQL
  `localhost` keeps working with a warning (log `dsn_warning`, `diag` WARN): write `127.0.0.1`.
* **A server that refuses the plugin's statement timeout or read-only mode** disables the books
  (log `session_limit_refused`, `diag` FAIL on `connect`) instead of a warning; `diag` shows the
  `statement time (this session)` and fails when it is not `galbridge_db_timeout`. Every supported
  server accepts both settings.
* **MariaDB/MySQL read timeout**: the plugin's connection gives up on a server that does not answer
  within the larger of `galbridge_db_connect_timeout` and `galbridge_db_timeout` plus 2 s (5 s by
  default); a connection lost during a statement is an outage for 30 s like a failed connect. Set
  PHP-FPM's `request_terminate_timeout` for the Roundcube pool as well (SECURITY.md) – on PostgreSQL
  it is the only bound after connecting.
* **Expiry** (`galbridge_hide_expired`) is judged by the database server's clock and by UTC, whichever
  comes first: on a server east of UTC an entry now disappears at its local expiry (1.0.1 showed it
  for up to the time-zone offset longer).
* **Search input with a control character or a backslash** matches nothing, and a department with
  one is no group (its members stay listed); a search that requires a birthday finds real dates only
  (PostgreSQL refused that statement before).
* **Only entries that follow the address rule are offered** (section 3.4): a mailbox, alias
  account, list or per-user alias whose address holds a character outside `a-z 0-9 . _ + = -` in its
  local part, a non-ASCII character, a leading, trailing or double dot, a single-label or
  non-letter top-level domain, `_` in a domain label, or a host that is not its `domain` column, is
  no longer listed, counted, found or grouped – before, it was counted and found but not shown, and
  one such row refused every export and group expansion of its scope. On **PostgreSQL** an address
  written with capitals is not offered either (MariaDB/MySQL offer it in lower case). iRedAdmin
  creates canonical addresses; `diag` lists the others (`entries not offered`, with examples). The
  log lines `row_invalid` and `row_out_of_scope` name the row by a tag (`row=…`), and
  `row_out_of_scope` no longer covers a failed e-mail check (that is `row_invalid`).
* **Selections are complete or refused**: compose to selected and export of selected with a contact
  that cannot be read (malformed ID, an entry that is gone or not visible to the user) are refused
  as a whole ("Not every entry …", the export answers 204) instead of leaving that contact out.
* **Copying directory contacts** to a personal address book copies at most `galbridge_max_ids`
  (200) per request; the rest is refused with "At most N directory contacts can be used at once".
* **List bounds tell the user**: a list total at `galbridge_max_count` shows "Only the first N
  directory entries can be browsed …", the pages of a search result stop at the result cap ("Only
  the first N directory matches …"), and a page size above the page bound (`max_pagesize` 0 or above
  1000, `addressbook_pagesize` above 1000) is a page of at most 1000 entries with "The directory shows
  at most N entries per page" – before, the first page of such a list was handled like an export
  (refused with `exportdisabled`, or the whole book). The page number is clamped.
* **Per-user aliases** (`galbridge_user_aliases`) are read with a window function: MariaDB 10.2 or
  MySQL 8.0 is required for them (`diag` FAIL on an older server). Every person of a page now gets
  its first 20 aliases; the log code `aliases_skipped` is gone.
* **Global book with `galbridge_global_groups = 'department'`**: a group is per domain, labelled
  `(domain) Department`; the group IDs change (a group selected before the upgrade selects nothing;
  `diag` WARN recommends `'domain'`). Entries of other domains are no longer found or sorted by a
  field they do not carry (first and last name; department and phones under a name template that
  shows them before `{name}`).
* **A refusal the database answers at once is not memoised** for 30 s any more (only an
  unreachable or slow server is): an authentication error or a limit text in the server's language
  is retried by the next request (log `db_down cause=refused`).
* **Display text**: `$` is shown as `＄`, a run of `%` gets blanks between its characters, and a
  `%` at either end of a text becomes `％` (Roundcube's suggestion list would read them as
  replacement patterns and highlight markers); an organization label is cut at an at-sign (`diag`
  WARN).
* **The configuration belongs in `config/galbridge.inc.php`** (section 2.3). A configuration in
  `plugins/galbridge/config.inc.php` keeps working, but `diag` without `--url` now warns about it
  (move it, or run `diag --url=`), and `diag --url=` asks the web server for the configuration file
  in use (`config over HTTP`: FAIL when it is served as text – deny it and change the role's
  password with `grants --reset-password`). `grants` names `config/galbridge.inc.php` in its DSN
  line.
* **More `diag` warnings**, none of which changes the exit code without `--strict`: a
  `galbridge_imap_hosts` entry without a port (it covers every IMAP port of the host: add the port),
  `galbridge_debug` on (also through `ROUNDCUBE_GALBRIDGE_DEBUG`), and on PostgreSQL a `pgaudit.log`
  or `auto_explain.log_min_duration` setting that would log the role's statements.
* **An ambiguous switch is read as its restrictive value** while it disables every book: `diag`
  shows it as `off (INVALID)` (`hide_expired`, `mark_foreign_domains` and `searchonly`: `on`)
  where it showed the default before. Nothing changes for a valid configuration.
* **Log lines Roundcube refuses**: with `per_user_logging`, ERROR and WARN lines of a request without
  a user log directory (and lines Roundcube cannot write) now appear in Roundcube's `errors` log,
  prefixed `galbridge:`.
* **`grants --port=N`** writes the port into the printed DSN (`host:port`); before, a server on
  another port needed the DSN edited by hand. With `--driver=mysql` it needs `--host=127.0.0.1` (or
  the server's address): with `localhost` PHP uses the unix socket and ignores the port.
* **A MariaDB/MySQL server-wide test database is no longer a refusal**: on a server set up by
  `mariadb-install-db` (RHEL and its rebuilds, FreeBSD, the official tarballs) the database `test`,
  open to every account, made the plugin refuse every role (`role_too_broad`, "privileges outside
  the directory (test)") – every book was gone. Now the books work, the log has
  `server_test_database` and `diag` fails on a line of its own until the database is removed
  (`mariadb-secure-installation`, section 2.2).

**From 1.0.0 to 1.0.1** – three steps, the second one is required:

1. Replace the plugin files (above).
2. **Apply the grant set to the existing role**, as the database administrator. 1.0.1 reads the
   forwardings through the view `galbridge_forwardings` and refuses a role that can read the
   `forwardings` table; until this step is done the plugin offers **no book** (log `column_missing`
   or `role_too_broad`, `diag` FAIL) – it fails closed, mail is not affected.

   ```sh
   cd /opt/www/roundcubemail
   find -L plugins/galbridge \( ! -user root -o -perm /022 \) -print     # must print nothing (section 2.2)
   umask 077
   plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=pgsql --grants-only > /root/galbridge-grants.sql
   runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql
   ```

   MariaDB/MySQL: `--driver=mysql` (with the `--account-host`, `--db` and `--role` you used before)
   and `mysql --binary-mode < /root/galbridge-grants.sql`; then run **once** the `REVOKE SELECT (...) ON
   vmail.forwardings FROM ...` statement that the output contains as a comment at its end (it names
   your account; MariaDB/MySQL cannot revoke "if granted", so it is not run automatically).
   PostgreSQL revokes the table access itself. The password and the DSN stay as they are. The
   output also applies the account limits (both engines) and, on PostgreSQL, the logging settings
   of the role. **The connection limit is set again**: add `--connection-limit=N` when you had
   sized it to your PHP-FPM pool – without the option it becomes 25 (the second line of the output
   names the value that will be set).
3. Check the configuration against what 1.0.1 no longer guesses (`diag` names every such problem):
   switches written as strings (`'false'`, `'no'`) and `ROUNDCUBE_GALBRIDGE_*` environment variables
   with other values than `1`/`0`/`true`/`false` disable every book; a remote PostgreSQL with
   `sslmode=verify-ca` needs `verify-full` or `galbridge_db_allow_verify_ca`; with Roundcube's
   `include_host_config`, or when Roundcube connects to IMAP on another port than the one in
   `imap_host`, set `galbridge_imap_hosts`; a DSN with parameters the plugin does not know is
   refused. Global-book entries of other domains are now shown as `(domain) Name` instead of
   `Name (domain)` – contacts that users copied to a personal address book keep the old form.

To go back to 1.0.0, restore its files and give the role the table grant it used:
`GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON
vmail.forwardings TO 'galbridge'@'localhost';` (PostgreSQL: `... ON forwardings TO galbridge;`).

**iRedMail upgrades.** Some of iRedMail's upgrade tutorials replace the whole content of
`/etc/nginx/templates/roundcube.tmpl` and `roundcube-subdomain.tmpl` (the one from 1.8.1 to 1.8.2
did): afterwards put the deny rule of 2.6 (and the per-client limits) back – the commands of 2.6
leave a template that still has them alone –, reload nginx and run
`diag --url=...` (2.6) – plain `diag` does not test the web server. Before a step that alters the
table `vmail.forwardings`, drop the view – MariaDB/MySQL `DROP VIEW IF EXISTS
vmail.galbridge_forwardings;`, PostgreSQL `runuser -u postgres -- psql -X -d vmail -c 'DROP VIEW IF
EXISTS galbridge_forwardings;'` – and apply `grants --allow-root --driver=mysql|pgsql --grants-only`
afterwards, as root, with the options the role was created with (`--role`, `--db`,
`--account-host`, `--connection-limit`: without it the limit becomes 25, as the second line of the
output says; 2.2); the books are unavailable in between. Wait a few seconds after `systemctl reload
nginx` before `diag --url` or the `curl` checks (2.6).

**Roundcube: install its security releases without waiting.** GAL Bridge supports every release of
the Roundcube 1.7 branch, and patch releases of that branch regularly fix security problems of
Roundcube itself: update the core as soon as a 1.7.x release is published – no new GAL Bridge
release is needed for it – and run `diag` afterwards. `diag` warns when the core is older than the
newest 1.7.x release this GAL Bridge version knows. Only a new minor branch (1.8) needs a GAL
Bridge release that names it.

**Roundcube on iRedMail.** iRedMail installs Roundcube into a versioned directory (e.g.
`/opt/www/roundcubemail-1.7.7`, reached through the symlink `/opt/www/roundcubemail`). Its upgrade
tutorials update that tree **in place**: they unpack the new release and run `./bin/installto.sh
/opt/www/roundcubemail` from it (it needs `rsync`, which iRedMail's PostgreSQL edition does not
install: `apt install rsync`; the directory keeps its old name). `installto.sh`
adds to `plugins/` without deleting anything and of `config/` replaces only `defaults.inc.php`, so
`plugins/galbridge`, `config/galbridge.inc.php` and the plugin list stay. It copies the files with
their owners (`rsync -a`), and every file of the release tarball belongs to uid 1000: unpacked as
root with the tutorial's `tar zxf`, Roundcube's code – its `plugins/` directory included – ends up
owned by uid 1000, which on Debian and Ubuntu is the first user account of the server: until the
files are given back to root, that account can change Roundcube's code and defaults.
`grants --allow-root` refuses to run (`.../plugins (owner uid 1000, mode 0755) is not owned by
root ...`), and `diag` warns about `config/defaults.inc.php` only – its hint to `chown` that one file
is not enough. Unpack the release with `tar --no-same-owner -xzf roundcubemail-1.7.N-complete.tar.gz`
instead; after an update from a tree unpacked with `tar zxf`, give those files back to root right
away – the files of the web server user (`temp/`, `logs/`, the password plugin's configuration) keep
their owner:

```sh
find /opt/www/roundcubemail/ ! -user root ! -user www-data -exec chown -h root:root {} +   # www-data: your PHP-FPM user (section 2; nginx on the RHEL family)
find /opt/www/roundcubemail/ ! -user root ! -user www-data -print                           # must print nothing
```

Then run `diag`; `grants --allow-root` runs again. `installto.sh` also resets iRedMail's mode 0000
of `README.md`, `CHANGELOG.md`, `SQL/` and `installer/` (iRedMail's own hardening; nginx denies
them anyway). **GAL Bridge installed with Composer:** `composer require` rewrites
`vendor/autoload.php`, so `installto.sh` no longer refreshes `vendor/`; the `update.sh` it starts runs
`composer update --no-dev` instead – without `--ignore-platform-req=ext-ldap`, so on iRedMail's SQL
editions it stops with the `ext-ldap` error of 2.1 ("ATTENTION: Update dependencies ..."),
`installto.sh` ends with `ERROR: Failed to execute command: ... bin/update.sh ...` (exit code 1) –
the files are copied and the database step has run, only the libraries are left – and the new core
runs with the libraries of the old release. Run it yourself, as root in the Roundcube directory and
in an interactive shell (answer `yes`; a script sets `COMPOSER_ALLOW_SUPERUSER=1`, 2.1):
`composer update --no-dev --prefer-dist --ignore-platform-req=ext-ldap` (it updates Roundcube's
libraries as `update.sh` would have done, and GAL Bridge within `^1.0`: compare the files with the
verified tag, 2.1, and run `diag`).

Only if you install a release into a new directory and switch the symlink yourself, copy the plugin
and its configuration into the new tree first, otherwise the directory silently disappears:

```sh
cp -a /opt/www/roundcubemail-OLD/plugins/galbridge /opt/www/roundcubemail-NEW/plugins/
# only if the configuration lives in config/galbridge.inc.php:
cp -a /opt/www/roundcubemail-OLD/config/galbridge.inc.php /opt/www/roundcubemail-NEW/config/
```

`cp -a` keeps owner and mode. Make sure `'galbridge'` and `addressbook_sort_col` are in the new
`config/config.inc.php`, then run `diag` in the new tree. The database role is independent of the
Roundcube tree.

**GAL Bridge installed with Composer** (`composer show takenek/galbridge` names it in the old
tree): the `composer.json` and `composer.lock` of a new tree come from the Roundcube release and do
not contain the package, so a later `composer update ... takenek/galbridge` (above) installs nothing
– Composer only warns `Package "takenek/galbridge" listed for update is not locked.` Either keep the
copied `plugins/galbridge` and upgrade it from now on with the release-archive commands above, or
copy only `config/galbridge.inc.php` (move a `plugins/galbridge/config.inc.php` there first, 2.3),
give the new `config/config.inc.php` to root (2.4) and run the `composer require` of 2.1 in the new
tree – as root in an interactive shell, answering `n` to the activation question – then compare the
files with the verified tag (2.1).

## 6. Rollback / uninstall

1. Remove `'galbridge'` from `$config['plugins']`. The books disappear at once; Roundcube and the
   personal address books keep working. GAL Bridge stores no tables and no user preferences (only
   four short-lived session keys: `galbridge`, the resolved user; `galbridge_down_until`, the
   outage memo; `galbridge_searchonly`, the `'auto'` search-only decisions; `galbridge_env_noted`,
   the names – never the values – of the `ROUNDCUBE_GALBRIDGE_*` variables in effect, so that
   `env_override` is logged once per session). If you added
   `galbridge_domain` or `galbridge_global` to `autocomplete_addressbooks` yourself, remove them:
   Roundcube stops with error 700 for an unknown source.
2. Drop the database role and the view (the only object GAL Bridge adds to `vmail`; it stores
   nothing).

   MariaDB / MySQL (use the host part you created; `SELECT user, host FROM mysql.user WHERE user =
   'galbridge';`):
   ```sql
   DROP USER 'galbridge'@'localhost';
   DROP VIEW IF EXISTS vmail.galbridge_forwardings;
   ```
   PostgreSQL, as `postgres` (`runuser -u postgres -- psql -X`, then):
   ```sql
   \c vmail
   DROP OWNED BY galbridge;   -- revokes its privileges in vmail, including CONNECT
   DROP ROLE galbridge;
   DROP VIEW IF EXISTS galbridge_forwardings;
   ```
   `DROP ROLE` alone fails while the role still holds privileges in `vmail`. Then – after `DROP
   ROLE`, never before: the `reject` lines keep the role away from the other databases – remove the
   four `pg_hba.conf` lines of 2.2 (iRedMail 1.8.11 on Debian 13:
   `/etc/postgresql/17/main/pg_hba.conf`) and `systemctl reload postgresql`. On a `vmail` database
   created by PostgreSQL 14 or older the grant set also took `CREATE` on schema `public` away from
   `PUBLIC` (2.2); uninstalling does not undo that – it is the default of PostgreSQL 15 and later;
   give it back only to a role that needs it (`GRANT CREATE ON SCHEMA public TO <role>`, never
   `PUBLIC`).
3. Remove the files. **Composer installation:** as root in the Roundcube directory, in an
   interactive shell (answer `yes` to `Continue as root/super user`) or with
   `COMPOSER_ALLOW_SUPERUSER=1` (2.1), `composer remove --ignore-platform-req=ext-ldap
   takenek/galbridge` (the option for iRedMail's SQL editions, 2.1): it deletes `plugins/galbridge`
   and takes `'galbridge'` out of `$config['plugins']`; `ls -d plugins/galbridge` must fail
   afterwards. Without a terminal Composer skips Roundcube's plugin installer: the package leaves
   `composer.json`, but `plugins/galbridge` (and an entry in the plugin list) stays – delete them by
   hand.
   A bare `rm -r` leaves the package in `composer.json` and `composer.lock`, and the next `composer
   install` or `composer update` – also the one Roundcube's `bin/update.sh` runs during a core
   upgrade – puts the plugin back. Then delete copies a failed update left behind, `rm -rf
   plugins/galbridge-[0-9]*` (they can hold the configuration with the password, 2.1). **Release
   archive:** as root in the Roundcube directory `rm -r plugins/galbridge`. In both cases remove
   `config/galbridge.inc.php` (if used), the deny line of 2.6 from both `roundcube.tmpl` and
   `roundcube-subdomain.tmpl`, and – if nothing else needs them – the per-client limits: the
   `limit_req` / `limit_conn` lines of both templates **together with**
   `/etc/nginx/conf-enabled/roundcube-limits.conf` – or whichever file holds the two `*_zone` lines,
   `grep -rln limit_req_zone /etc/nginx/` – (template lines without that file fail `nginx -t` with
   `zero size shared memory zone`); then `nginx -t && systemctl reload nginx`. Remove the log
   file `<log_dir>/galbridge.log` (with syslog, as on iRedMail, there is none). The changes of 2.4
   (`config/config.inc.php` owned by root) and 2.5 (`addressbook_sort_col`) do not depend on the
   plugin and can stay.

## 7. Troubleshooting

Start with `sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=<login>` (without sudo:
`runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag --user=<login>`, section 2) in the
Roundcube directory; it shows the cause of almost every "empty directory".

| Symptom | Cause / fix |
|---|---|
| No GAL Bridge book in the address book | `diag`: `galbridge_db_dsn` missing or refused (FAIL on the `galbridge_db_dsn` line with the reason), plugin disabled by the IMAP host binding (FAIL "directory hosts"; log `plugin disabled` – also with `include_host_config`), session server not a directory server (log `host_mismatch host=… mail_host=… allowed=…`: add the host, or the right port, to `galbridge_imap_hosts`; `diag` checks `imap_host` and the hosts of Roundcube's users), login not an active mailbox (`diag --user`: "not found"; log `denied_source status=no_mailbox`), role too broad (log `role_too_broad`: it can read a secret column or the `forwardings` table), a required column or the view `galbridge_forwardings` missing (log `column_missing` names it: run `grants --grants-only`, section 5), **a switch that is not a boolean or another fail-closed configuration error** (log `config_invalid … all books are disabled`, `diag` FAIL names the option) – also an unknown (misspelt) `galbridge_*` option or `ROUNDCUBE_GALBRIDGE_*` variable (`option=` names it, `diag` the closest valid name), a second configuration file next to `plugins/galbridge/config.inc.php` (`option=galbridge.inc.php`, text "is not read: Roundcube loads only ...") or a configuration file that cannot be loaded (`option=` names the file in use – `galbridge.inc.php` or `config.inc.php` – with "cannot be read by the PHP user" or "defines no $config array"; owner root, group of the PHP user, mode 0640, 2.3), a parse error in the configuration file (Roundcube itself stops: check it with `php -l`, 2.3), a malformed `galbridge_db_refused_users` (every connection refused), home domain not in the viewer list, a login during a database outage (the books appear once the database is back, 3.9) |
| Global book missing | `galbridge_global_viewer_domains` is empty by default; `'*'` data domains without `galbridge_global_allow_wildcard` (log `config_invalid`); its data scope minus the user's own domain(s) is empty |
| "The directory is temporarily unavailable." | log `db_down` (`cause=connect`: the server is unreachable or slow – in a web request Roundcube's errors log has the driver message, `diag` prints it on its `connect` line; `cause=session_limit`: the server refused the statement timeout or read-only mode, `diag` names the statement; `cause=driver_error` / `lost`; next attempt after 30 s; `cause=refused`: the server answered at once with a refusal – authentication, a limit text in the server's language –, `diag` prints the driver text, the next request tries again) or `db_busy` (the role's or the server's connection limit is reached – raise it, 2.2, and limit the concurrent requests per client in the proxy, SECURITY.md) or `db_error sqlstate=…`: `42000` (MariaDB/MySQL: access denied – grants), `42501` (PostgreSQL: permission denied – grants), `42S22` / `42703` (unknown column – iRedMail schema or the `galbridge_visibility_column` name), `70100` (MariaDB `max_statement_time`), `57014` (PostgreSQL `statement_timeout`), `HY000` (MySQL, includes `max_execution_time`) |
| Export downloads nothing | a refused export (section 4): log `export_disabled` (export switch off, or the whole of a search-only book – the global book by default), `export_too_large` (also a search result above the result cap), `group_too_large`, `too_many_ids`, `set_incomplete`, or `db_down` / `db_error` during the export |
| The directory disappears for one user in the middle of a session | the mailbox was disabled, expired, deleted or moved (log `viewer_revoked`), or – when the cached user was renewed – the IMAP server refused the session's credentials: password changed, IMAP service disabled (log `viewer_revoked`, `denied_source status=imap_refused`; a new login gets the directory again; section 3.9) |
| No book for sessions on a remote IMAP server | the link is not verified TLS (log `imap_transport` with the reason, `denied_source status=transport_unprotected`, `diag` FAIL "IMAP transport"): use `ssl://host:993` or `tls://` on Roundcube 1.7.7+, keep certificate verification on – or `galbridge_imap_allow_plaintext` for a trusted private link (3.9) |
| No book for requests under one host name only | `include_host_config`: a per-host file sets a `galbridge_*` option (log `config_invalid … differs from the host-independent configuration`, `option=` names it; `diag` "per-host configuration"); move it into the plugin's own file (2.3) |
| Autocomplete or search finds nothing | fewer than `galbridge_min_search_length` characters (global book: in every word); global book matches prefixes only, e-mail from the local part and organizations by their whole label; `galbridge_*_autocomplete = false`; search-only book opened without a search |
| Groups missing | search-only book; more than `galbridge_max_groups` (log `too_many_groups`); department groups without the `department` field (log `grouping_disabled`); `galbridge_*_groups = 'none'`; `diag --user` shows the effective grouping |
| A field (phone, last name, ...) missing | not in `galbridge_fields`, column not granted or missing (log `field_unavailable`; `diag` WARN; the shipped grants leave `telephone`, `mobile` and `birthday` commented out; iRedMail `update/1.7.3` adds `first_name`, `last_name`, `mobile`, `telephone`, `birthday`); `organization` needs `galbridge_organizations`; global-book entries of other domains carry no first and last name (3.3) |
| An alias account or list missing | its `accesspolicy` does not allow the user to post (3.6); policy value unknown (hidden); no active member / list row in `forwardings`; `diag --user` shows the counts |
| Search results in e-mail order | `addressbook_sort_col` is not `'name'` (2.5) |
| An entry is missing from the directory | its address or domain does not follow the address rule (3.4): `diag` lists it under `entries not offered` (with the `row=` tag of the log); on PostgreSQL also an address written with capitals; otherwise the hiding rules (3.7) or the list policy (3.6) |
| "The directory shows at most N entries per page." | `max_pagesize` is 0 or above 1000, or `addressbook_pagesize` / `pagesize` above 1000 (`diag` WARN `page size`; 3.8) |
| Accented names found on MariaDB but not on PostgreSQL | engine difference, see 10 |
| Error 700 "Addressbook source (...) not found" | a source listed in `autocomplete_addressbooks` that no longer exists (e.g. `domain` / `global` of the old plugin, or `galbridge_*` after uninstalling) |
| A hidden address, an excluded domain or an organization seems to have no effect | `diag`: INFO `policy lists` shows how many entries are in effect – 0 means the option did not take effect (a misspelt option name or the other configuration file is a FAIL above it) |
| `diag`: FAIL `composer backup` / WARN `config file … installed by Composer` | an interrupted `composer update` left `plugins/galbridge-<digits>` with your configuration: delete it; move the configuration to `config/galbridge.inc.php` (2.1) |
| `diag`: WARN `web access … lies in the plugin directory` / FAIL `config over HTTP … is SERVED as text` | the configuration file with the database password is `plugins/galbridge/config.inc.php` and `diag` ran without `--url` – move it to `config/galbridge.inc.php` (2.3) or run `diag --url=`; a FAIL means the web server hands the file out: deny it (2.6) and give the role a new password (`grants --reset-password`, 2.2) |
| `diag`: WARN `directory hosts … has no port` | a `galbridge_imap_hosts` entry without a port covers every IMAP port of that host: write `host:port` unless every IMAP service there uses this vmail database (3.9) |
| `diag`: WARN `debug` / `statement logging … an extension can record` | `galbridge_debug` is on – switch it off after the diagnosis (3.10); on PostgreSQL `pgaudit.log` or `auto_explain.log_min_duration` logs the role's statements with the search terms – keep it only as a deliberate audit trail (SECURITY.md, Logs) |
| ERROR/WARN lines of GAL Bridge in Roundcube's `errors` log (`galbridge: …`) | Roundcube refused them for the `galbridge` log: `per_user_logging` without a log directory of the user, or the file cannot be written (3.10) |
| `diag`: FAIL `dont_override` / `stored preferences` | remove `galbridge_*` names from `dont_override`; with `galbridge_db_dsn` stored in preferences give the role a new password (`grants --reset-password`, SECURITY.md) |
| `grants` refused: "this user can read ..., a configuration file of the web server" | `grants` ran as the web server user (or as an account that can read Roundcube's or the plugin's configuration): run it as root with `--allow-root` (section 2.2) |
| `grants` / `--allow-root` refused: "... is not owned by root ... or is writable by its group or by others" | a file or directory of the plugin, or a directory above it, belongs to another account or is group/other-writable (a Composer run as the web server user, an archive extracted without `tar.umask=0022`): `chown -R root plugins/galbridge && chmod -R go-w plugins/galbridge` (the owner only: a `plugins/galbridge/config.inc.php` keeps its PHP group and mode 0640), fix the named directory, and check with `find` (section 2.2) |
| `grants`: "the statements built from ... contain a backslash / the client command ..." | the template in `SQL/` was changed: nothing was printed – reinstall the plugin from the verified release archive (section 2.1) |
| `diag`: FAIL on a `view galbridge_forwardings` line, `schema public`, `role memberships`, `privileges outside`, `role attributes`, `global privileges`, `owned objects` | apply `grants --grants-only` as root (section 5); a membership, a privilege outside the directory, a global privilege or an owned object of a hand-made role has to be revoked or dropped by hand – the line names it |
| `diag`: FAIL `server-wide test database` (MariaDB/MySQL; log `server_test_database`) | the server has the `test` database of `mariadb-install-db`, open to every account (RHEL, FreeBSD, official tarballs): run `mariadb-secure-installation` ("Remove test database and access to it"), or as the database administrator `DROP DATABASE test; DELETE FROM mysql.db WHERE Db IN ('test', 'test\\_%'); FLUSH PRIVILEGES;` (and drop other `test_*` databases nobody needs). The books keep working meanwhile (section 2.2) |
| `diag`: FAIL `connect` "Access denied" or "Connection refused" with a DSN that `grants` printed for a server on another port | the DSN names the default port: generate it again with `--port=N` (MariaDB/MySQL with `--host=127.0.0.1`, section 2.2) or add `:N` to the host of the DSN |
| Composer: `pear/net_ldap2 ... requires ext-ldap * -> it is missing from your system` | iRedMail's SQL editions: add `--ignore-platform-req=ext-ldap` to every `composer require`, `update` and `remove` (2.1) |
| Composer: `Version check failed! takenek/galbridge requires Roundcube version >= ...` | the core is older than 1.7.0: upgrade Roundcube to the newest 1.7.x first (section 1, docs/MIGRATION.md) |
| Composer succeeded, but there is no `plugins/galbridge` (the package is in `vendor/takenek/galbridge`) | Composer ran as root without a terminal and disabled its plugins (it printed `Composer plugins have been disabled for safety in this non-interactive session.`): remove the package the same way (`composer remove --ignore-platform-req=ext-ldap takenek/galbridge`, without a terminal), then run the `composer require` of 2.1 again in an interactive root shell, or with `COMPOSER_ALLOW_SUPERUSER=1` |
| `composer remove` succeeded, but `plugins/galbridge` is still there | Composer ran as root without a terminal (2.1): the package is out of `composer.json` and `composer.lock` – delete the directory (`rm -r plugins/galbridge`) and take `'galbridge'` out of `$config['plugins']` (section 6) |
| `diag`: `composer copy` – `vendor/takenek/galbridge` exists next to `plugins/galbridge` | a `composer update` or `install` ran as root without a terminal: it installed into `vendor/` and did not change `plugins/galbridge` (the plugin keeps running the classes of `plugins/galbridge`, its autoloader comes first). Remove the copy the same way – `composer remove --ignore-platform-req=ext-ldap takenek/galbridge` without a terminal, which leaves `plugins/galbridge` in place –, move a configuration kept in `plugins/galbridge/config.inc.php` to `config/galbridge.inc.php` (2.3: `composer require` empties `plugins/galbridge` before it unpacks the release, the file and its password would be lost), then run the `composer require` of 2.1 again in an interactive root shell and compare the files with the verified tag (2.1) |
| Details | `<log_dir>/galbridge.log`, or with syslog (iRedMail) the mail log: `/var/log/mail.log` on Debian/Ubuntu (`/var/log/maillog` on RHEL), ident `roundcube`, without the word `galbridge` – `journalctl -t roundcube` (3.10); reason codes below |

**Log reason codes** (`LEVEL operation: code: message key=value`):

| Code | Level | Meaning |
|---|---|---|
| `config_invalid` | ERROR | an option is invalid (`option=` names it; a rejected list entry by its position, never the entry), the option or variable is unknown, or a plugin configuration file is ignored or cannot be loaded (`option=` names the file); fail-closed cases disable the protected book or every book |
| `dsn_warning` | WARN | the DSN was accepted with a warning: a remote database without verified TLS (`galbridge_db_allow_plaintext`), `sslmode=verify-ca` (`galbridge_db_allow_verify_ca`), a `PGSSLMODE` / `PGREQUIRESSL` / `PGOPTIONS` variable that applies as well, or the PostgreSQL host `localhost` (looked up by the system resolver: write `127.0.0.1`, `[::1]` or a socket) |
| `env_override` | INFO | `ROUNDCUBE_GALBRIDGE_*` variables override options of the plugin in the PHP of the web server (their names, never the values; once per session and again when the set changes) – compare with what `diag` reports for the shell |
| `plugin disabled` / `host_mismatch` | WARN | IMAP host binding (3.9); `host_mismatch` names `host=`, `mail_host=` and `allowed=` |
| `imap_transport` | WARN | the session's directory IMAP server is not local and the link is not verified TLS (the reason, `host=`): no book – or, with `galbridge_imap_allow_plaintext`, accepted with this warning (3.9) |
| `denied_source` | INFO | a GAL Bridge book was requested that the user cannot use (an autocomplete request that names no book logs an enabled book the user is not entitled to only with `galbridge_debug`: the plugin itself added it to the autocomplete sources); `status=` gives the reason: `no_mailbox`, `disabled`, `host_mismatch`, `transport_unprotected`, `imap_refused`, `imap_unavailable`, `viewer_changed`, `invalid_login`, `role_too_broad`, `column_missing`, `config_invalid`, `db_down`, `db_error`, or `ok` (not entitled by the viewer lists or the data scope) |
| `role_too_broad` | ERROR | the role can do more than read the directory – it can read `password`, `recovery_email` or `allow_nets` (of any table) or the table `forwardings` (the private forwarding targets), is a member of another role, has a role attribute, owns the database, holds a global privilege or a privilege outside the directory, or may change something (the reasons are in the line) – or the view `galbridge_forwardings` holds rows of another kind: every book is denied (section 2.2; `diag` names every reason) |
| `view_not_materialised` | WARN | the view `galbridge_forwardings` is not the one of the grant set (not fenced, merged, not a security barrier, another owner – the reasons are in the line): the books keep working; apply `grants --grants-only` (section 5) |
| `role_can_create` | WARN | the role may create objects in the vmail database (PostgreSQL 14 and older: `CREATE` on schema `public` for `PUBLIC`) or owns some: the books keep working; apply `grants --grants-only`, which revokes it (section 2.2) |
| `server_test_database` | WARN | MariaDB/MySQL: the role can see a server-wide test database (`test`, `test_*`) that the server opens to every account (`mariadb-install-db`) and on which it holds nothing of its own: the books keep working; remove it with `mariadb-secure-installation` (section 2.2). `diag` fails until then |
| `column_missing` | ERROR | required vmail columns are missing or not granted (named in the line; every book is denied). Required are the columns of the view `galbridge_forwardings` and, with `galbridge_hide_expired`, `mailbox.expired` and `domain.expired`; `diag` checks the same list |
| `viewer_revoked` | INFO | the mailbox of a session with a cached entitlement is no longer usable, or the IMAP server refused the session's credentials when the cached user was renewed: the directory was taken away (in the second case for the rest of the session) |
| `viewer_changed` | INFO | an own alias of the session's user was removed (strict list policy): that request read nothing, the next one uses the current aliases |
| `imap_unavailable` | WARN | the IMAP server could not confirm the session's credentials when the cached user was renewed (unreachable, temporary failure): the directory is unavailable, asked again after 30 s |
| `too_many_aliases` | WARN | a user has more own aliases than are used for the list policies (200): the policies are evaluated with the login alone |
| `aliases_capped` | WARN | more per-user aliases than are shown for one person (20; the first ones in address order are shown, for every person of the page) |
| `field_unavailable` | WARN | configured fields whose columns are missing or not granted |
| `db_error` | ERROR | a query failed (`sqlstate=`); the driver text only with `galbridge_debug` |
| `unsafe_parameter` | ERROR | a value with a NUL byte or a backslash was about to be bound: the statement was not sent (every producer of values refuses them; please report it) |
| `db_down` | ERROR | the directory is unavailable and the session does not try again for 30 s (`retry_in=30`); `cause=connect` (the server is unreachable or slow: the attempt took at least half of the connect timeout, or connection refused / unknown host / timeout), `driver_error` (the driver raised an error while connecting; `diag` names it), `session_limit` (see `session_limit_refused`) or `lost` (the connection was lost during a statement: server restart, read timeout). Without `retry_in`, `cause=refused`: the server answered at once with a refusal (authentication, a limit text in another language) – this request only |
| `db_busy` | WARN | the connection limit of the role (or of the server: PostgreSQL "too many clients", "remaining connection slots are reserved") is reached: this request gets "directory unavailable", the next one tries again (raise `--connection-limit`, 2.2) |
| `session_limit_refused` | ERROR | the server refused the plugin's statement timeout or read-only mode (`statement=` names it): the connection is not used, the books are unavailable for 30 s (`db_down cause=session_limit`) |
| `read_timeout_unavailable`, `read_timeout_not_applied` | WARN | the MariaDB/MySQL connection gets no read timeout: `pdo_mysql` is not built on `mysqlnd`, or `ini_set` is disabled / `mysqlnd.net_read_timeout` is fixed by `php_admin_value` (`wanted=`, `current=`) – set `mysqlnd.net_read_timeout` to at most `wanted` in the php.ini of the web server, and bound the worker with `request_terminate_timeout` |
| `grouping_disabled`, `too_many_groups` | WARN | groups switched off (3.5) |
| `group_key_unusable` | WARN | a department with a control character or a backslash is not offered as a group (its members stay in the book; 3.5) |
| `group_too_large`, `too_many_ids`, `export_disabled`, `export_too_large` | WARN | a request was refused by a limit (3.8); `too_many_ids` also when one request reads more contacts one by one than `galbridge_max_ids` (copy) |
| `set_incomplete` | WARN | a recipient set or export was refused because an entry of it could not be delivered: `missing=` selected IDs that are malformed or find no visible entry (compose to selected, export of selected), `dropped=` rows the re-check dropped (see `row_invalid` / `row_out_of_scope`; an entry that changed between two statements of the request, or a defect) |
| `search_set_stale` | WARN | the export of a search was refused: its stored criteria belong to another configuration (search again) |
| `invalid_gid`, `invalid_id` | WARN | an undecodable or foreign group or contact ID in a request (stale link or crafted request; `tag=` correlates repeats without revealing the value) |
| `row_invalid` | WARN | a database row whose address does not follow the address rule (3.4), does not belong to the row's `domain` column or fails Roundcube's e-mail check was dropped by the re-check (`row=` a non-reversible tag of the raw address, the tag `diag` prints next to the `entries not offered`); the SQL already leaves such rows out, so in a book this means a row changed during the request or a defect; for the mailbox row of a login: that login gets no directory |
| `row_out_of_scope` | ERROR | a row outside the book's scope or on a hidden list reached PHP and was dropped (`row=` tag); the SQL should already exclude it – please report it |
| `unexpected error` | ERROR | an unexpected exception (`exception=` class; message only with `galbridge_debug`) |
