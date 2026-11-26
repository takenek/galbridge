# Changelog – GAL Bridge (`galbridge`)

All notable changes are documented here. The format follows *Keep a Changelog*; versions follow
semantic versioning.

## [Unreleased]

## [1.0.6] – 2026-11-26

1.0.5 was installed once more on two fresh Debian 13 servers with the unattended installer of
iRedMail 1.8.11 (Roundcube 1.7.7, PHP 8.4), by administrators who knew nothing but the documentation:
the MariaDB edition from the signed tag, the PostgreSQL edition with Composer from Packagist – both
servers without `git`, `ssh-keygen`, `sudo`, `curl` or Composer – and its documentation was reviewed
against the iRedMail 1.8.11 and Roundcube 1.7.7 sources and the plugin code (docs/TESTING.md
section 6). The plugin worked on both editions: every `diag` form ended with `Result: OK` and no
WARN, the browser checks passed, nothing private reached a response. The review found places where
the documentation said something other than what happens. 1.0.6 corrects them and changes no code:
no plugin, configuration or database step is needed for the upgrade.

### Fixed (documentation)
- **README 2.6 left the shell in `/etc/nginx/templates`**: its command that inserts the deny rule
  started with `cd /etc/nginx/templates`, and the `diag` commands after it (the `--url` block of 2.6
  and every command of 2.7) call the tool by its relative path – pasted in order, they failed with
  `command not found`. The command now uses full paths, and the `diag` blocks of 2.6 and 2.7 start
  with `cd /opt/www/roundcubemail`.
- **README 2.2 had both `grants` lines in one block**: pasted as a whole, the PostgreSQL line ran last
  and overwrote the MariaDB grant set and DSN of the line before it. One line runs; the other
  edition's line is a comment.
- **README 2.2 described the review `diff` of the `--grants-only` output for MariaDB/MySQL only**:
  on PostgreSQL it also shows the existence check turned round (`IF NOT EXISTS` / `does not exist`)
  and the added `ALTER ROLE ... NOINHERIT;` line of that form, not just the missing `CREATE ROLE`.
- **README 2.7 and SECURITY.md offered `diag --strict` without `--url` for automated checks**: that
  form checks no web access (`Result: OK (not checked: web access - run with --url)`, exit code 0),
  so a cron job built from it never noticed a deny rule lost in an iRedMail upgrade. The automated
  form now has the `--url=` of the webmail; README 5, "From 1.0.5 to 1.0.6", says to add it.

### Documentation
- README 2.1: `composer show takenek/galbridge` (the version and commit for the comparison with the
  signed tag) runs in an interactive root shell as well – without a terminal it answers `Package
  "takenek/galbridge" not found`.
- README 7, `composer copy`: move a configuration kept in `plugins/galbridge/config.inc.php` before
  the `composer require` that repairs the installation – it empties `plugins/galbridge` first.
- README 5 and docs/ARCHITECTURE.md: a Composer installation copied into a new Roundcube tree is not
  in that tree's `composer.json`, so `composer update ... takenek/galbridge` installs nothing there
  (`listed for update is not locked`): upgrade it by release archive, or run `composer require` in
  the new tree.
- SECURITY.md hardening checklist: the HTTPS item names the two Roundcube options (`use_https`,
  `session_samesite`), says that iRedMail 1.8.11 sets neither and that `diag` does not check them;
  README 2.7 says what `diag` covers.

### CI
- The `debian:13-slim` image of the job `e2e` pinned to its current digest, and Composer 2.11.2 (was
  2.10.3) with its published SHA-256 (docs/RELEASING.md step 3).

## [1.0.5] – 2026-11-26

1.0.4 was installed and upgraded once more on real iRedMail 1.8.11 servers, from the published tag
and from Packagist: a fresh installation by README section 2, and upgrades from 1.0.3 by section 5,
through Composer and through the signed tag (docs/TESTING.md section 6). All three worked; the
upgraded installations showed what an administrator who had followed the 1.0.3 instructions still
needs. No configuration or database step is needed for this upgrade.

### Fixed
- **`diag` reported a dangerous connection limit as OK.** The 1.0.3 instructions sized the role's
  connection limit to the PHP-FPM pool – 100 on iRedMail – and `diag` 1.0.4 printed
  `[ OK ] connection limit 100` against MariaDB's `max_connections = 151` and PostgreSQL's 100,
  which 1.0.4 itself calls a risk to the mail services. The line now compares the limit with the
  connections the server has for ordinary roles (`max_connections`, on PostgreSQL minus
  `superuser_reserved_connections` and `reserved_connections`) and is a WARN for a limit above the
  default 25 that takes more than a quarter of them, with the command that lowers it.
- **The upgrade note "From 1.0.3 to 1.0.4" could end the directory**: on PostgreSQL it told
  administrators to add `local all galbridge reject`, which also refuses the unix-socket DSN that
  config.inc.php.dist of 1.0.3 offered. It now says to add `local vmail galbridge scram-sha-256`
  first in that case, that the three `host` lines of 1.0.3 may stay, and gives the commands – the
  `pg_hba.conf` line, and the `grants --grants-only` run that sets a 1.0.3 limit of 100 back to 25.
- **README 2.1 installed the files before the signature was checked**: the release-archive block
  went on to `git archive | tar -x` and `rm -rf` after `verify-tag` – pasted as a whole, a failed
  check did not stop it, and the fingerprint comparison came after the block. The block now ends
  with `verify-tag`; the files are unpacked in a second block, after both fingerprints agree.

### Added
- `diag` names the release of the files in the plugin directory (`[ OK ] GAL Bridge X.Y.Z`): after an
  update the administrator sees which code runs.

### Documentation
- README 5: the release-archive upgrade compares the result with the verified tag (`diff -r`), so a
  file a release no longer has is found; the Composer update is one command, run in an interactive
  root shell (it asks no activation question); the `diag` command with its `runuser` form; README 6
  finds the file of the per-client limits whatever its name.
- README 2.2: a command that inserts the four `pg_hba.conf` lines before iRedMail's `local all all
  md5` (once), that the socket line must be in place before the reload when the DSN uses the
  socket, that psql may print the TCP refusal twice (SSL, then no encryption), and what the review
  `diff` shows for the `--grants-only` form; 2.6: any line before the first `static.php` location is
  right for the deny rule; 2.7 lists `composer copy`, the version line and the new connection-limit
  WARN.

## [1.0.4] – 2026-11-26

A second installation on real iRedMail 1.8.11 servers – two fresh Debian 13 systems, the MariaDB and
the PostgreSQL edition, Roundcube 1.7.7, PHP 8.4 – this time by administrators who knew nothing but
the documentation (Composer from Packagist on one, the signed tag on the other), then the corrected
documentation once more on two further fresh servers without `git`, `ssh-keygen`, `sudo`, `curl` or
Composer, through a Roundcube core update the way iRedMail does it and through the removal; and a
review of every installation, upgrade and removal step against the iRedMail 1.8.11 installer and
the Roundcube 1.7.7 sources (docs/TESTING.md section 6). The directory itself worked on both
editions throughout; the documentation had gaps a first installation runs into – one of them
weakened the database isolation on the PostgreSQL edition – and two Composer situations could leave
an old plugin running unnoticed. No configuration or database step is needed for the upgrade; README
section 5, "From 1.0.3 to 1.0.4", lists what to check on an existing installation.

### Security
- **PostgreSQL: the `pg_hba.conf` lines of README 2.2 did not keep the role to the `vmail` database
  over the unix socket.** iRedMail's `pg_hba.conf` ends with `local all all md5` and the catch-all
  `host all all 0.0.0.0/0 md5`; the three documented lines were `host` lines inserted before the
  catch-all, so the role could still log in with its password over `/var/run/postgresql` to every
  database where `PUBLIC` holds `CONNECT` (`roundcubemail`, `amavisd`, `iredadmin`, ...). README 2.2
  now gives four lines – `local all galbridge reject` first – to insert before `local all all md5`,
  the file's location on Debian 13, how to check it (`pg_hba_file_rules`), reload it and test that
  both kinds of connection are rejected; `diag`'s INFO "other databases" says that it cannot read
  `pg_hba.conf`. The grant template, config.inc.php.dist (also for the socket DSN) and SECURITY.md
  say the same.
- **The connection limit on iRedMail.** README 2.2, `diag` and the grant templates told
  administrators to size `--connection-limit` to the PHP-FPM pool, which iRedMail sets to 100 –
  while on Debian and Ubuntu its MariaDB keeps `max_connections = 151` and its PostgreSQL 100, shared
  by every service of the server: a role allowed 100 connections could take the slots Dovecot,
  Postfix and Roundcube itself need. The advice now names both bounds – at most the pool and what the
  server can spare: on iRedMail the default 25, more only after raising `max_connections` – next to
  the `grants` command, in `diag`'s `connection limit` line and in both templates; later
  `--grants-only` runs repeat the limit.

### Fixed
- **A second copy of the plugin below `vendor/`**: a `composer update` or `install` run as root
  without a terminal skips Roundcube's plugin installer and installs the package into
  `vendor/takenek/galbridge` – `plugins/galbridge` stays as it was, and Roundcube's Composer
  autoloader, which is registered first, then mapped `GalBridge\` to that copy: after such an
  "update" the plugin could run the classes of the new release with the rest of the old one. The
  plugin's own autoloader is now registered first, so it always runs the classes of its directory,
  and `diag` warns about such a copy (`composer copy`); README 2.1, 5, 6 and 7 say that every
  Composer command in the Roundcube tree needs an interactive root shell or
  `COMPOSER_ALLOW_SUPERUSER=1`, and how to recover.
- **Composer as root without a terminal installed nothing into `plugins/`**: Composer 2.4 and later
  disable their plugins for such a run, so Roundcube's plugin installer never ran and the package
  landed in `vendor/takenek/galbridge` with exit code 0. README 2.1 says to run Composer as root in an
  interactive shell (on iRedMail the only account that can: the tree belongs to root and
  `composer.json` has mode 0000), to answer its root question, or to set `COMPOSER_ALLOW_SUPERUSER=1`
  in a script, and how to check the result; a troubleshooting row covers the case.
- The Composer path of README 2.1 assumed a `composer` command (iRedMail installs none:
  `apt install composer`), showed the command that fails on iRedMail's SQL editions first, gave no
  guidance for the installer's question `Do you want to activate the plugin galbridge? [Y|n]`
  (answer `n`: yes enables the plugin with the still empty configuration), and said "then skip 2.4",
  although 2.4 also gives iRedMail's `config/config.inc.php` to root – which now happens before the
  first Composer run, as the plugin installer loads that file inside the root process.
- **The Composer check verified metadata only**: comparing the source reference of `composer show`
  with the tag commit proves nothing about the files of a `--prefer-dist` install (Packagist
  publishes no checksum of the archive). README 2.1 now compares the installed files with `git
  archive` of the verified tag (`diff -r`), and quotes the source line as `composer show` prints it
  (`https://github.com/takenek/galbridge.git`).
- **The DSN line of `grants` is printed as an SQL comment**: pasted with its leading `-- `, the
  configuration file did not parse and every webmail request of a logged-in user stopped. README 2.2
  and 2.3, config.inc.php.dist and now the instruction line of `grants` itself say to drop it; the
  2.2 commands write the line into a file only root can read, and 2.3 puts it into the copied
  configuration without showing the password (and changes nothing when the file holds no DSN line),
  then `php -l`.
- `sudo` (and `curl`) are not part of iRedMail and missing on a Debian server installed with a root
  password: section 2 gives `runuser -u www-data -- ...` for every `diag` command, and so do the
  troubleshooting section, config.inc.php.dist, the grant templates, docs/MIGRATION.md and the
  message of `bin/galbridge.sh` when it is run as root.
- `diag --url` must name the host name of the certificate – with iRedMail's self-signed certificate
  `https://127.0.0.1/mail/` gave eight WARN lines: README 2.6 and 2.7 say how to find the name.
- Section 5 described iRedMail's Roundcube upgrade as a new tree; iRedMail's tutorials update the
  tree in place with `bin/installto.sh`, which keeps the plugin but copies the files with the owner
  of the release tarball (uid 1000) – after that `grants --allow-root` refuses to run. README 5 now
  says to unpack the release with `tar --no-same-owner`, how to give such files back to root, that a
  Composer installation needs `composer update --no-dev --ignore-platform-req=ext-ldap` afterwards
  (Roundcube's own `update.sh` runs it without the option and stops), and what an iRedMail upgrade
  can undo (the nginx templates, the `forwardings` view). The `diag` command of section 5 had a path
  relative to the plugin directory.
- Uninstalling a Composer installation with `rm -r` left the package in `composer.json`, and the next
  `composer update` – also the one of a Roundcube core upgrade – put the plugin back: README 6 uses
  `composer remove`.
- `php-fpm -m` (README 2.7, SECURITY.md and `diag`'s `process` line): on Debian and Ubuntu the binary
  carries its version (`php-fpm8.4`).
- Three messages – the `config_invalid` of a per-host option, `diag`'s "per-host configuration" and
  "environment file" lines – told administrators who keep `config/galbridge.inc.php` to use
  `plugins/galbridge/config.inc.php`; with both files GAL Bridge disables every book. They now name
  both locations of the plugin's configuration file (never both), and the `config_invalid` text is
  short enough for the log, which cut it before the remedy.
- `diag` printed nothing when the IMAP hosts stored for Roundcube's users were all covered; it now
  says so (`[ OK ] stored IMAP hosts`).
- README 3.10 sent administrators to `journalctl -t php-fpm` for PHP errors; on iRedMail PHP's
  messages of a webmail request – a parse error of the configuration included – are in
  `/var/log/nginx/error.log`.

### Documentation
- README section 2 starts with a checklist for a standard iRedMail server that links every step to
  its subsection and says what a correct installation ends with (`Result: OK`, no WARN);
  `git`/`openssh-client` are installed in the first line of the release-archive commands; the fingerprint comparison has two commands that need only `python3` (iRedMail has no
  `jq`), and README and SECURITY.md say that the key is titled "MIME Shield release signing" on
  GitHub (the maintainer signs both projects with it).
- `namei -l` in README 2.2 and SECURITY.md takes the absolute path, so it shows every directory from
  `/` down; the review step names the verifier in the `diff` output as a secret.
- README 2.4 shows iRedMail's plugin line literally; 2.5 says that iRedMail ships
  `addressbook_sort_col` commented out; 2.6 names the `static.php` location of each template, where
  the deny rule goes (with a command that inserts it once into both templates), to wait a moment
  after `systemctl reload nginx` before checking, that iRedMail upgrades can replace the templates,
  and where the per-client limits of SECURITY.md go on iRedMail – which, per client address, all
  users behind one NAT address share (SECURITY.md says how to size or exempt such networks).
- README 3.4 says which domain a per-user alias belongs to (its owner's, also for an address in an
  alias domain); sections 4 and 7 and config.inc.php.dist say that the whole of a search-only book –
  the global book by default – is never exported, whatever `galbridge_*_export` says; 3.10 and 7
  say where the lines are on iRedMail (`/var/log/mail.log`, `journalctl -t roundcube`).
- The summary lines of `diag` are quoted as the tool prints them, and the example run is that of a
  standard iRedMail installation; `forwardings(forwarding)` is checked on PostgreSQL only (the
  MariaDB/MySQL role cannot see the indexes of that table); on PostgreSQL every `diag` run leaves one
  `permission denied for table mailbox` line in the server log – the password-column probe.
- docs/MIGRATION.md: the order with the Roundcube 1.7 upgrade, the ownership step of 2.4, `diag
  --url`, a broken cross-reference, and every place iRedMail keeps the `vmail` password.
- Section 5 says that 1.0.0 and 1.0.1 were never published, and compares the files of a Composer
  update with the verified tag (so does SECURITY.md); section 6 names every line it removes (the
  deny rule, the limits together with their zone file, the four `pg_hba.conf` lines after `DROP
  ROLE`); the troubleshooting table has rows for Composer's messages; docs/ARCHITECTURE.md and
  docs/RELEASING.md follow the corrected procedures.
- The Packagist and License badges of the README showed as broken images on GitHub and on
  Packagist: GitHub's image proxy gave up on `img.shields.io` after about four seconds ("504 Error
  Fetching Resource"). Both badges are now files of the repository (`.github/badges/`, the same
  images shields.io draws), linked through `raw.githubusercontent.com`, which GitHub does not proxy.
  packagist.org shows them through a CDN that caches a URL for a year, so the version badge carries
  the version in its file name: every release adds one and keeps the earlier ones, which the READMEs
  of earlier releases link (docs/RELEASING.md step 4; a test checks it). The repository description on
  GitHub names Roundcube 1.7.x and MariaDB/MySQL or PostgreSQL.

## [1.0.3] – 2026-11-25

The plugin was installed on real iRedMail 1.8.11 servers – Debian 13, Roundcube 1.7.7, PHP 8.4
PHP-FPM, nginx, Dovecot, the MariaDB edition and the PostgreSQL edition – by following README
section 2 command by command, once from the signed tag and once with Composer from Packagist, and
then used through the real webmail (docs/TESTING.md section 6, "Real installation"). The books, the
groups, search, autocomplete, group expansion, the tenant isolation, the global book and the role
checks worked on both editions as documented. What did not work as the documentation said is fixed
here; no configuration or database step is needed for the upgrade.

### Fixed
- `composer require takenek/galbridge` stopped on iRedMail's MariaDB/MySQL and PostgreSQL editions
  with `pear/net_ldap2 v2.3.0 requires ext-ldap`: Roundcube's own `composer.lock` holds its LDAP
  libraries, and iRedMail installs the PHP `ldap` extension only for its OpenLDAP edition. README
  2.1 and 5 give the command with `--ignore-platform-req=ext-ldap` (that one requirement only) or
  the extension as the alternative.
- `diag --url` against the self-signed certificate that iRedMail installs answered every request
  with "no answer (... Failed to open stream: operation failed)" – PHP's last message, which does not
  name the cause – and offered no way to check such a server. The reason now says that the
  certificate was not verified (`certificate verify failed`, or the name mismatch), one WARN line
  explains what to do, and the new option **`--cafile=FILE`** (absolute path, `https://` only, a
  PEM certificate and no private key) verifies the server against that certificate, e.g.
  `--cafile=/etc/ssl/certs/iRedMail.crt` – verification itself is never switched off. A failed
  request without a TLS cause no longer repeats PHP's `fopen(...)` prefix.
- The `core config file` WARN for a Roundcube `config.inc.php` that the PHP user owns – iRedMail
  installs it as `www-data`, mode `0600` – now gives the remedy (`chown root:<PHP group>`, `chmod
  0640`); README 2.4 says so as well.

### Documentation
- README 2.1: the release-archive commands need `git` 2.34+ and `ssh-keygen` (Debian/Ubuntu:
  `openssh-client`), without which `verify-tag` fails with `cannot run ssh-keygen`; the package is
  listed on Packagist.
- README 2.6: `curl --cacert /etc/ssl/certs/iRedMail.crt` and `diag --url ... --cafile=` for
  iRedMail's self-signed certificate; the deny rule was confirmed on the live templates (without it
  `static.php/plugins/galbridge/lib/autoload.php` and the SQL templates answered `200`).
- docs/TESTING.md: the real-installation run and its results.

## [1.0.2] – 2026-11-25

Verification of the remediation in 1.0.1 against the audit report (2026-11-25, Anthropic's Claude
Opus 5.5): the fixes were checked in the code and by the suites on all three engines; where a fix
was incomplete or brought a new risk, it is completed here. The fixes of this first verification
need no configuration or database step; the second verification pass below names the changes that
can (its "Upgrading" items).

### Security
- U-11 (completed): the IMAP binding compared the session's `storage_host` and the user's
  `mail_host` after trimming and parsing them again, while PHP connects with the raw value (host up
  to the first colon). On Roundcube 1.7.0 – 1.7.5, whose login form does not check a typed server,
  and with an `imap_host` template plus an unusual `Host` header, a session authenticated by another
  IMAP server could pass the binding. The raw values must now be a host name, an IPv4 address, an
  IPv6 address in brackets or a local socket (the stored `mail_host` may also be the configured URI
  of an older Roundcube); anything else is `host_mismatch`, and such a value is never written to
  the log or printed by `diag` (which counts those users). A `ROUNDCUBE_INCLUDE_HOST_CONFIG`
  variable - which Roundcube itself ignores - no longer lets the binding be derived from
  `imap_host`; a bare IPv6 entry of `galbridge_imap_hosts` is no prefix of a host.
- U-04 (completed): Roundcube fills a `contactlist_name_template` placeholder by placeholder, so a
  `{department}` inside a name was filled as well and two texts could form an encoded-word; curly
  brackets become their full-width forms, and a text never starts or ends with `=` or `?` (fields
  placed next to each other by a template). Department and phone numbers, which a template can put
  into recipient names, and department group labels follow the rule "never looks like an address"
  (cut at the at-sign).
- U-06 (completed): the documentation claimed that nobody could read forwarding targets even with
  the role's credentials; the view limits what the role may select, not inference through
  conditions evaluated on the rows behind it (MariaDB/MySQL merge views, PostgreSQL leakproof
  operators) - corrected. The PostgreSQL grant set makes the administrator who runs it the owner of
  the view; `diag` checks that the view holds no other rows than alias, alias-member and list rows.
  (Superseded in the second verification pass, below: the view is now materialised and fenced, so
  no condition reaches the rows behind it any more (F-03), and the PostgreSQL grant set drops and
  re-creates it (F-04).)
- U-08 (completed): with the account's connection limit active on both engines, a refusal because
  that limit is reached was memoised like an outage (no directory for 30 s in that session); it now
  fails only the request (log `db_busy`). The documentation no longer calls the role's statement
  time a hard limit: it is the role's default, which every session (the plugin's too) may change.
- U-10/U-09 (completed): for a non-local account host the `REQUIRE SSL` statement follows the
  account statements instead of ending the script, so a later statement that fails (an optional
  `GRANT` of a column an older schema lacks) cannot leave the account without it.
- I-01 (completed): a DSN host `localhost` in another spelling (mysqlnd then uses its built-in
  socket path, not `pdo_mysql.default_socket`) and the old `driver(syntax)://` notation (which ends
  the PHP process in Roundcube's database factory) are refused.
- I-02 (completed): `diag` warns when `des_key` - the key of the configuration fingerprint kept in
  sessions - is Roundcube's published default or empty.
- I-03 (completed): `diag` ends with "OK with N warning(s)" when there are WARN lines, gives no
  `[ OK ]` line for a `--url` that did not answer like the webmail, and ends an unexpected error with
  exit code 2.
- I-05 (completed): with `--allow-root`, the configuration directories of `RCUBE_CONFIG_PATH` (and
  `ROUNDCUBE_CONFIG_DIR` read as Roundcube reads it) are checked, a symbolic link to a path that does
  not exist is refused, the relative entries are taken off `include_path`, and Roundcube's log lines
  of a root process go to syslog instead of `log_dir` (which the web server user owns and could
  point at any file). README 2.2: generate the grant set from code the web server cannot change and
  read it before the database administrator runs it. (Neither kept the working directory or PHP's
  own error log out of reach: completed in the second verification pass below, F-11.)
- I-06 (completed): no fixed temporary file name in a test and in the release procedure.
- I-07 (completed in the repository): Composer's blocking of releases with advisories is switched
  off for the PHPUnit job only; the 1.7.0 legs and the coding-style job audit their dependencies as
  well (the 1.7.0 legs all but that core); the release job also requires the tagged commit to be
  part of `main`; the exceptions of the secret scanner are anchored at the top of the repository.
  The signed release tag and pinning the release key remain a step of the maintainers. (Completed in
  the second verification pass, below: the key is pinned in `.github/release-signers` and verified by
  CI (F-22), the policy switch is narrowed to advisories (F-21), and the secret scanner excepts named
  files and exact values only (F-24).)
- I-10 (completed): the documentation and `diag` said that only a NEW own alias waits for
  `galbridge_scope_ttl`; a removed one does too (restricted lists stay visible until then) - only the
  mailbox itself is confirmed on every reading request.
- I-15 (completed): the export of a search whose stored criteria this configuration can no longer
  apply is refused (`incomplete`, no file) instead of delivering a file without the book's entries.
- R-001 (completed): on PostgreSQL (and SQLite) a row written with capitals escaped the hidden lists
  and the excluded domains in SQL - dropped later, but counted and matched by searches. The
  deny-lists compare in lower case on every engine.

### Second verification pass (assessment of 1.0.1)

A second, independent assessment of 1.0.1 (2026-11-25; 36 findings, F-01 … F-36) found the gaps
below; each is closed here (remediation with Anthropic's Claude Opus 5.5), with a test that fails on
the code before the fix. The entries are grouped by area – `diag`, identity binding, configuration,
database layer, database role and grants, directory sets and text, development and release,
documentation and accepted risks, and (found afterwards by a run against every supported database
server) database compatibility, followed by the findings of a final review of the whole change –
and each group ends with what an upgrade has to consider.

- F-11 / I-05 (completed): the root process of `diag --allow-root` now works in Roundcube's verified
  `program/` directory – PHP looks into the working directory last for a relative file name
  whatever `include_path` says, and Roundcube's autoloader asks for one (an unknown database driver
  was enough to execute a file of the administrator's working directory as root) –, keeps only the
  absolute `include_path` entries that exist and checks them like `program/`, and hands Roundcube
  the canonical paths it checked (`INSTALL_PATH`, `RCMAIL_CONFIG_DIR`, `RCUBE_CONFIG_PATH`) instead
  of a spelling with a symbolic link that another account could swap. It is refused when PHP's
  error log is a file in a `log_dir` that is not root's alone, or a symbolic link (every
  `rcube_config` object – Roundcube's and the ones the plugin creates to read the configuration
  again – points `error_log` at `<log_dir>/errors.log`, so switching to syslog after Roundcube's
  start was not enough: a PHP warning was still written through a link of the web server user), and
  when Roundcube's database is an SQLite file in a directory another account can write. iRedMail
  (`log_driver = 'syslog'`) is not affected; with `log_driver = 'file'` and a `log_dir` of the web
  server user run the tool as that user (as documented). The documentation names what the checks
  do not cover: PHP's own configuration and the start code of every enabled Roundcube plugin.
- F-12: `diag` neutralises every line it prints (`GalBridge\Cli\Terminal`): control, bidi,
  zero-width and separator characters and invalid UTF-8 appear as visible escapes such as `\x1B`;
  the settings a database server reports, the iRedMail release line, `imap_host` and the reason of
  an aborted check are sanitised like log values (a DSN password masked). While `diag` runs, what
  Roundcube, the database drivers and PHP write to standard error is neutralised as well (a filter
  on `STDERR`; PHP's messages are written by the tool's own handler as one sanitised line, to the
  error log and – with `display_errors = stderr` – to standard error), and the refusals of
  `--allow-root` escape the file names they show. A local socket in `galbridge_imap_hosts` must be
  printable ASCII without blanks and `%` (another entry disables the plugin); a session or stored
  `mail_host` in socket form with other bytes is `host_mismatch`, and `diag` counts such users
  without printing the value.
- F-27: `diag` checks every configuration file Roundcube loads for the plugin (`galbridge.inc.php`
  from every configuration directory, `RCUBE_CONFIG_PATH` included) and the core files
  `defaults.inc.php` and `config.inc.php`, with the owner of their directories and the directories
  above (WARN); the plugin code recursively – a file the PHP user owns counts as changeable whatever
  its mode says –, the directories above the plugin and Roundcube's `plugins/` directory, as root
  with the rule of `--allow-root` (an explicit OK or WARN line instead of none), and a WARN when the
  user cannot be determined; files inside `bin/`, `lib/`, `SQL/`, `localization/` and `docs/` that a
  release does not hold. It says that the PHP version, extensions and environment it reports are
  those of the command line process (INFO `process`), says when no `ROUNDCUBE_GALBRIDGE_*` variable
  is set there and lists the core options that `ROUNDCUBE_*` variables of the process override
  (names only); the web process logs the names of the `ROUNDCUBE_GALBRIDGE_*` variables in effect
  there once per session (INFO `env_override`). An unchecked Roundcube database is a WARN (was
  INFO); the summary says when the web access was not checked; `diag --strict` counts WARN lines as
  problems (exit code 2). An exception or a PHP fatal error during Roundcube's start – or an `exit()`
  of other code while the tool runs – ends with `Result: aborted` and exit code 2 (core's
  fatal-error handler ended such a run with exit code 0 on PHP 8.4 and 8.5); an unexpected error
  while connecting to the vmail database is a `[FAIL] check aborted` line of its section, with the
  counts and the summary kept.
- Upgrading (`diag`): the new WARN lines can turn `Result: OK` into `Result: OK with N warning(s)` on
  installations whose files or directories belong to the web server user (true findings; the exit
  code stays 0 without `--strict`), the output has new INFO lines, and the summary line can carry
  "(not checked: web access - run with --url)": scripts that match whole lines may need an update.
- F-01 (verified): the IMAP binding compares the session's host and port and the stored `mail_host`
  in the raw form Roundcube wrote them (U-11 above); re-checked against Roundcube 1.7.0 – 1.7.7, no
  further change. Its tests now run their sessions on non-local servers over TLS (F-18).
- F-06: the options of the core – `imap_host`, `include_host_config`, `imap_conn_options`,
  `des_key`, `db_dsnw`, the plugin list – are read as Roundcube applies them (its own configuration
  object: an environment-specific `config-<env>.inc.php` and files pulled in with
  `include_once`/`require_once` included), no longer from a second reading of the files; that second
  reading is used only for a name that a stored preference shadows (also the legacy `default_host`).
  No IMAP binding is derived when the two readings disagree on `imap_host`; `include_host_config`
  counts when either reading enables it; the configuration fingerprint is keyed with the `des_key`
  Roundcube uses; the database users of Roundcube (`db_dsnw`, now also `db_dsnr`) and of the password
  plugin are refused from every reading. The plugin's file is found with a `RCUBE_PLUGINS_DIR`
  defined without the trailing slash, and with Roundcube's environment suffix
  (`Config::pluginFiles()`, one routine for every reader). `diag` names (never prints) the options a
  second reading loses (WARN "configuration read again"), configuration files that use
  `include_once`/`require_once` and environment-specific files.
- F-08: with Roundcube's `include_host_config`, the Host header of the request – the client's choice –
  selects a configuration file; a `galbridge_*` option such a file sets (hidden lists, viewer lists,
  the global book, the DSN …) used to be applied for whoever sent that host name. Every `galbridge_*`
  option of a request is now compared with the host-independent configuration (`config.inc.php` and
  the plugin's file); a difference disables every book for that request before the database is
  asked (log `config_invalid` naming the option; the two book names may differ). `diag` merges every
  file a request can select and names the options it sets (FAIL), and checks a per-host
  `imap_host`.
- F-17: an open session kept the directory after its password was changed or its IMAP service was
  disabled (the role cannot see either, and address-book requests do not connect to IMAP). When the
  cached user is renewed (`galbridge_scope_ttl`) the IMAP server now confirms the session's
  credentials through Roundcube's own connection: a refusal ends the directory for the rest of the
  session (log `viewer_revoked`, status `imap_refused`), an IMAP server that cannot be asked makes it
  unavailable for 30 s (`imap_unavailable`), never "refused". With the strict list policy the user's
  own aliases are read again with the mailbox confirmation, so a removed alias applies with the next
  request (log `viewer_changed`). README 3.9 documents what applies when, and how to end a
  compromised account's access at once.
- F-18: the identity binding trusts the IMAP login; over an unprotected link to a remote IMAP server
  anyone on that network could answer it (`tls://` on Roundcube 1.7.0 – 1.7.6 even continues in
  plain text when the server offers no STARTTLS). A session on an IMAP server that is not local
  (socket, `localhost`, `127.0.0.0/8`, `::1`) now gets the directory only over TLS that verifies the
  server – `ssl`/`imaps`, or `tls` on Roundcube 1.7.7 or later, PHP's `openssl`, no
  `imap_conn_options` that switch verification off – checked on every request (log
  `imap_transport`, status `transport_unprotected`); the new switch `galbridge_imap_allow_plaintext`
  (default `false`) accepts it for a trusted private link, with a warning. `diag` judges the
  transport of every configured server ("IMAP transport").
- F-33: the decisions that depend on the action (autocomplete switches, complete-or-refused exports,
  the refusal of a stale search export, the 204 answer of a refused export, groups as full sets) now
  compare it in the canonical form of Roundcube's dispatcher (`EXPORT` and `group_expand` reach the
  same handlers as `export` and `group-expand`), and the export hook is registered for every request
  of the address book (it acts only after a refusal). The new `contact_exists` hook removes the GAL
  Bridge books from Roundcube's known-contact check: a `collected_senders` or `collected_recipients`
  preference that names one (Roundcube saves them unchecked) no longer makes every directory address
  a trusted sender. The threat model no longer says that `init()` reads a resolved action name.
- Upgrading (identity binding): a directory IMAP server on another host needs verified TLS (`ssl://`,
  or `tls://` on Roundcube 1.7.7+; certificate verification on) or `galbridge_imap_allow_plaintext =
  true` – until then its sessions get no books (`diag` FAIL "IMAP transport"); loopback and sockets
  (the iRedMail default) are not affected. With `include_host_config`, move every `galbridge_*`
  option out of the per-host files into the plugin's configuration file (`config/galbridge.inc.php`,
  or `plugins/galbridge/config.inc.php` if that is the one in use – never both; `diag` names them). An
  `imap_host` set through `include_once`, an environment-specific file or another plugin, without
  `galbridge_imap_hosts`, disables the plugin until the list is set or the configuration is
  flattened. The fingerprint changes once (every session resolves its user again). Address-book
  requests make one IMAP login per session and `galbridge_scope_ttl` when Roundcube has not
  connected yet, and requests that read the directory one bounded alias statement more (strict list
  policy).
- F-07: a plugin configuration file that exists but cannot be loaded (not readable by the PHP user,
  no `$config` array – Roundcube only logs error 527 and goes on), a `config/galbridge.inc.php` that
  Roundcube ignores because `plugins/galbridge/config.inc.php` exists, and a `galbridge_*` option or
  `ROUNDCUBE_GALBRIDGE_*` variable this version does not know (a typo left the option meant at its
  default – for a hidden list, nothing hidden) now disable every book before anything connects (log
  `config_invalid` naming the file or the option); compose wires no book into autocomplete then.
  `diag` fails for each – with the closest valid option name –, lists an ignored file as "NOT read"
  with the same mode checks, says "none" with the reason on the `enabled books` line and shows the
  size of the hidden and excluded lists and of the organizations (INFO `policy lists`). `Config` is
  given the plugin's own directory (`rcube_plugin::$home`, or the one `diag` works with).
- F-09: SECURITY.md (and the threat model) recommended listing `galbridge_*` options – first of all
  `galbridge_db_dsn` – in Roundcube's `dont_override`; with an option set through an environment
  variable Roundcube then stored its value, the DSN with the database password, in the preferences
  of every user who saved settings. The advice is withdrawn (SECURITY.md now says not to), the
  plugin is loaded in the Settings task for one hook only, `preferences_update`, which removes every
  `galbridge_*` key from the preferences Roundcube saves and from the stored ones (copies saved
  before disappear at the user's next save), and `diag` fails for `galbridge_db_dsn` in
  `dont_override` (WARN for other `galbridge_*` names) and counts the users whose stored
  preferences hold `galbridge_db_dsn` (FAIL) or other `galbridge_*` keys (WARN) – counts only.
- F-10: the README said that `composer update` keeps `plugins/galbridge/config.inc.php`; Roundcube's
  plugin installer re-creates it at every update as a new file with the owner and umask of the user
  running Composer (typically readable by every local account) and keeps a full copy of the plugin
  directory, configuration included, in `plugins/galbridge-<20 digits>` until the update succeeds.
  Composer installations are now told to keep the configuration in `config/galbridge.inc.php`
  (created before `composer require --prefer-dist`); `diag` fails for a leftover installer copy and
  warns for a plugin-directory configuration of a Composer installation (also on its "readable by
  everyone" FAIL); the nginx rule of README 2.6 covers the copy (`plugins/galbridge(-[0-9]+)?/`).
- F-29: the log mask of passwords covers `password=` / `PGPASSWORD=` / `db_pass=` pairs (bare or
  quoted) and `"password": "…"`, passwords with blanks, tabs, `@` or `/`, user names with a slash and
  the old `driver(syntax)://` notation, and replaces a text it cannot handle as a whole; it runs
  after control characters are replaced, and every format character (Unicode `Cf`, e.g. the soft
  hyphen and tag characters) is replaced now. Parameters that carry a password or a DSN are marked
  `#[\SensitiveParameter]`. A context value with a blank, `=`, `"` or `\` is written in double
  quotes (`allowed="127.0.0.1 mail.example.com"`), a key is a code constant. `config_invalid` names
  the position of a rejected list entry instead of the entry – a rejected hidden address no longer
  reaches the log. An autocomplete request that names no book no longer logs `denied_source` for an
  enabled book the user is not entitled to (the plugin added it to the autocomplete sources itself;
  debug only); with `_source`, and for every other action, it still does. No present code path
  logged a DSN: the mask is a safety net.
- F-30: the built-in refused database roles can no longer be removed (`galbridge_db_refused_users`
  adds names), and a value that is not a list of role names refuses every database connection (it
  shrank or fell back before). Numeric options accept whole numbers only – an integer or a string of
  digits, an environment variable judged by its text – and report a value outside their range
  (`Config::RANGES`, the one range of every caller; `diag` used to check nothing). The configuration
  fingerprint kept in sessions no longer covers the database password, so the session store holds
  nothing derived from it whatever `des_key` is (SECURITY.md, the architecture and the threat model
  claimed that unconditionally). `diag` warns while `galbridge_db_allow_plaintext` or
  `galbridge_db_allow_verify_ca` is on – also unused –, while `galbridge_global_allow_wildcard` is on
  without `'*'`, when the password plugin is enabled with its SQL driver but its DSN is unknown, and
  for a `des_key` that is not a string or shorter than 24 characters (besides the published default
  and an empty one); it shows the refused roles.
- Upgrading (configuration): every book is disabled until fixed – `diag` names the cause – for an
  unknown `galbridge_*` option or `ROUNDCUBE_GALBRIDGE_*` variable, for both configuration files
  present, and for a configuration file that cannot be loaded; a configuration that removed a
  built-in refused role to connect with it, or that has a malformed refused-roles list, loses the
  database connection. A fractional number (`2.5`) now gives the default instead of its integer
  part. Remove `galbridge_*` names from `dont_override`; if `diag` counts stored copies of the DSN,
  give the role a new password (`grants --reset-password`). Composer installations move the
  configuration to `config/galbridge.inc.php` and widen the nginx rule. Log parsers that split on
  blanks must accept quoted values. The fingerprint changes once (every session resolves its user
  again). The plugin is now instantiated in the Settings task (one hook, nothing else).
- F-13 (completed): the DSN handed to the driver is rebuilt from the parts the policy checked and
  must read back to exactly those parts – 1.0.1 passed the configured text with `?timeout=N`
  appended, so a `?` before the database name turned the timeout into part of that name and left the
  driver without a connect timeout. The DSN is written in one grammar (`host`, `host:port`,
  `[IPv6]`, `[IPv6]:port` or `unix(/path)`, then `/database` as the driver receives it, then the
  parameters); refused: a second port, text around an IPv6 address, the `unix+…` and `tcp(…)`
  notations, a raw `+` in a parameter value (misleadingly reported as "no host" before), a
  percent-encoded or `;`-suffixed database name, a parameter the parser does not read, MariaDB/MySQL
  `localhost:PORT` or a port inside `unix(…)` (the driver ignores it and uses the socket), MySQL
  `localhost` without a usable `pdo_mysql.default_socket`, and unix socket paths over 107 bytes
  (PostgreSQL including `.s.PGSQL.<port>`). MySQL `localhost` is shown with its socket on `diag`'s
  `DSN` line, and `diag` warns when the socket's directory is writable by every local account.
  PostgreSQL `localhost` – looked up by the system resolver on every connection – is accepted with a
  warning (log `dsn_warning`, `diag` WARN; SECURITY.md register). The driver class is created
  directly instead of through `rcube_db::factory()` (which ends the PHP process on a driver it does
  not know), and an error the driver raises while connecting – e.g. a PDO MySQL constant missing
  from a `pdo_mysql` without `mysqlnd` on Roundcube 1.7.6+ – is a memoised outage
  (`db_down cause=driver_error`) with its reason on `diag`'s `connect` line instead of an unmemoised
  exception. `grants --host=` no longer accepts an IPv6 address with an embedded IPv4 part, whose DSN
  the plugin would refuse. The docblocks named wrong driver defaults.
- F-14: the MariaDB/MySQL connection gets a read timeout – the larger of
  `galbridge_db_connect_timeout` and `galbridge_db_timeout` plus 2 s, set as
  `mysqlnd.net_read_timeout` while the plugin's connection is created and restored right after; a
  lower php.ini value is kept. Before, a server or proxy that accepted the connection and stayed
  silent held the PHP worker for mysqlnd's default of 86400 s (the connect timeout covers the TCP
  connection only). A connection lost during a statement (read timeout, server restart) is an
  outage for 30 s like a failed connect (`db_down cause=lost`). `diag` shows the read timeout and
  warns where none can be set (`pdo_mysql` without `mysqlnd`, `ini_set` disabled; log
  `read_timeout_unavailable` / `read_timeout_not_applied`). PostgreSQL has no read timeout after
  connecting: SECURITY.md now asks for PHP-FPM's `request_terminate_timeout`.
- F-26: no value with a control character or a backslash is bound any more – Roundcube inlines bound
  values and PDO scans the statement again (a backslash moved the literal boundaries PDO saw;
  libpq cut a value at a NUL byte, shorter than the length rules checked): such search input is not
  searched (it matches nothing), a department with one is not offered as a group (log
  `group_key_unusable`) and a forged group ID with one selects nothing, and the connection refuses a
  NUL byte or a backslash before it sends a statement (log `unsafe_parameter`). A server that
  refuses the plugin's statement timeout or read-only mode disables the books (log
  `session_limit_refused`, `db_down cause=session_limit`) instead of a warning – on MySQL nothing
  bounded the statements then; `diag` shows the `statement time (this session)` and fails when it is
  not `galbridge_db_timeout`. Expiry is judged by the database clock in its session time zone and by
  UTC, whichever comes first (UTC only before: on a server east of UTC an entry expired in local
  time stayed visible for the offset). A required birthday means a real date (`m.birthday <> ''`
  failed on PostgreSQL and counted year 0001 elsewhere); a required text field must be non-empty
  after trimming. The documentation, `diag` and the code no longer call the session limits a
  "backstop to the role limits": `galbridge_db_timeout` is the statement bound of the plugin on
  every engine, the role's statement time a default for other sessions (MySQL has none).
- Upgrading (database layer): newly refused DSN spellings (see F-13) disable every book until the DSN
  is corrected – `diag` says what to write (MariaDB/MySQL `localhost:PORT` becomes `127.0.0.1:PORT`);
  PostgreSQL `localhost` only warns. A server that refuses the session settings disables the books
  (every supported server accepts them). Searches with control characters or a backslash return
  nothing; departments with them are no groups. On database servers east of UTC expired entries
  disappear at their local expiry. Set `request_terminate_timeout` for the Roundcube PHP-FPM pool.
  Log parsers: `db_down` carries `cause=`, `session limit not applied` is replaced by
  `session_limit_refused`.
- F-02: the output of `grants` is run by the database administrator, but 1.0.1 documented producing
  it as the web server user (`sudo -u www-data ... grants`), and outside `--allow-root` nothing checked
  who could change the code and templates it loads and prints. `grants` is now run as root with
  `--allow-root`, whose code check covers it (the script, `lib/`, `SQL/`, `localization/`,
  `galbridge.php`, the configuration and every directory above them must be root's alone). As any
  other user it refuses, before it loads a class, to run when that user can read a configuration
  file of Roundcube or of the plugin – the web server user, whose other processes could read or
  change the output while it is produced (`ptrace`, `/proc`) – and refuses code and templates that an
  account other than root and itself owns or that group or others can write. It works in the plugin
  directory with the plugin's `lib/` as include path. The output holds no backslash and no line that
  starts with a long-form mysql client command (checked before anything is printed; the templates
  had a backslash in a comment), and README 2.2 adds what code cannot enforce: a check of the plugin
  files with `find` / `namei` before a root run (a changed script could skip its own check), a review
  of the output (role line, `grep` for a backslash, a `diff` against the template) and applying it with
  the client commands off (`mysql --binary-mode <`, `runuser -u postgres -- psql -X ... <`). A
  Composer installation must leave the code to root (README 2.1). `diag`'s "plugin code" line says
  that `grants` and `--allow-root` refuse such a tree.
- F-03: the MariaDB/MySQL view `galbridge_forwardings` was merged into the queries of the role (a
  caller's condition was evaluated on private forwarding rows – on MariaDB 11.8 as an index condition
  before the flag filter), and PostgreSQL's `security_barrier` let leakproof conditions through. The
  view is now materialised and fenced on every engine: MariaDB/MySQL `ALGORITHM = TEMPTABLE` with
  `LIMIT 18446744073709551615` (`TEMPTABLE` alone still let the server push conditions into it),
  PostgreSQL `security_barrier` with `OFFSET 0` – the server builds its rows before any condition of a
  query applies, so not even a query sent with the role's credentials sees a private row. The plugin's
  statements refer to the view in uncorrelated semi-joins only (built once per statement, also in the
  OR branches of the list policies). `diag` checks the view object (and, through `SHOW VIEW`, which the
  MariaDB/MySQL grant set now gives, its definition) and fails for an unfenced view; the plugin logs
  `view_not_materialised` and keeps working until the grant set is applied again.
- F-04: the role checks covered eight relations and three columns of `mailbox`. The plugin now refuses
  (`role_too_broad`, the reasons in the log) a role that can do more than read the directory: a secret
  column of **any** table, the `forwardings` table, a membership in another role, a role attribute,
  the ownership of the database, a global privilege, a privilege outside the directory database
  (PostgreSQL: also outside its schema – a relation or sequence of another schema and, completed by
  the final review below, `CREATE` on another schema and any other privilege granted to the role in
  the database) or a privilege to change anything – read in one catalog
  statement per engine (`RoleScope`) – and a view `galbridge_forwardings` that holds rows of another
  kind (on every engine). `diag` fails for each, checks the view object (PostgreSQL: kind, owner,
  options, fence, dependencies), writes on every relation and sequence, CREATE on the schema or the
  database (FAIL, was a WARN) and owned objects, lists the other databases a PostgreSQL role may
  connect to and names every column beyond the grant set. The PostgreSQL grant set drops and
  re-creates the view (no grant, owner, rule or comment of an earlier view survives; a table of that
  name stops it), takes `CREATE` on schema `public` from `PUBLIC` (the default of PostgreSQL 15; table
  owners keep it by name) and explicit `CREATE` / `TEMPORARY` on the database from the role, asserts
  the role attributes (`NOBYPASSRLS` included) in every form and works with `search_path = public`.
  The documentation no longer calls role defaults (`statement_timeout`,
  `default_transaction_read_only`) limits, and gives the `pg_hba.conf` lines that keep the role to the
  vmail database.
- F-28: `grants` printed the DSN line with the password into the SQL stream (a client that keeps
  comments would send it to the server and its logs); it is now on standard error, and so are usage
  errors, refusals, template errors, an aborted run and PHP's own messages – a redirected SQL file
  holds none of them. A
  repeated option and `--account-host` with `--driver=pgsql` (silently ignored before) are usage
  errors. The clear-text form for MySQL 8.4+ stops on MariaDB before the statement with the password
  also through a client that drops comments: a statement only MySQL 8 can run
  (`SELECT @@SESSION.information_schema_stats_expiry`) follows the MariaDB-only `SIGNAL` comment. The
  PostgreSQL grant set keeps its statements out of `pg_stat_statements` (`track_utility` off for its
  transaction), and the manual PostgreSQL path reads a root-only copy of the template through
  standard input (`runuser -u postgres -- psql -X ... < /root/galbridge-grants.sql`) instead of a
  file the postgres account had to read. `diag` warns about a MariaDB/MySQL account other hosts may
  use without `REQUIRE SSL` (the account statement is read, never printed).
- Upgrading (database role and grants): apply the grant set again – as root, `plugins/galbridge/bin/
  galbridge.sh grants --allow-root --driver=... --grants-only` with the `--role`, `--db`,
  `--account-host` and `--connection-limit` you used, reviewed and applied as README 2.2 describes
  (`mysql --binary-mode` / `psql -X`). Until then the books keep working; the log warns
  (`view_not_materialised`, on PostgreSQL 14 databases `role_can_create`) and `diag` fails on the
  view and on `schema public`. `sudo -u www-data ... grants` is refused; a Composer installation made
  as the web server user needs `chown -R root plugins/galbridge && chmod -R go-w
  plugins/galbridge` first (the owner only: a `plugins/galbridge/config.inc.php` keeps its PHP group
  and mode 0640). Scripts that read the DSN line from the output must read standard error.
  A hand-made role with a membership, an attribute, a global or foreign privilege, a write privilege
  or a secret column of another table loses its books (`diag` names why); roles created by the grant
  set are not affected. On PostgreSQL the re-created view drops the grant of any second GAL Bridge
  role on the same database: apply its grant set as well.
- F-05 / F-16 / F-20: SQL and the PHP re-check now apply ONE address rule – only canonical rows are
  offered. The deny-lists in SQL compared `LOWER(column)` while PHP compared the NFC / lower-case /
  A-label form, so a hidden address in another spelling (Unicode NFD, a non-ASCII capital) and an
  entry whose domain column held the U-label of an excluded domain escaped the hidden lists and
  exclusions in SQL: counted, matched by searches (also through a per-user alias) and part of the
  group aggregate, dropped only by PHP – an existence oracle. Rows that PHP rejected for other
  reasons (Roundcube's e-mail check: a double dot, `_` in a domain label, a single-label domain; an
  address that does not belong to its domain column) refused every export and group expansion of
  their whole scope, and the log did not name them. The local part of an address was checked by a
  deny-list looser than names and reached Roundcube's sinks unchanged (`&` in the HTML of the
  suggestion list, `?`, `&`, `#` in the `mailto:` value of the contact view, non-ASCII look-alikes),
  and a name `0` – empty for Roundcube – let the bare address become the recipient text. Now
  `Normalizer::CANONICAL_ADDRESS` (local part `a-z 0-9 . _ + = -` in dot-atom form, at most 64 bytes;
  an ASCII host name of at least two labels with a letter-led or `xn--` top-level label; at most 254
  bytes) is rendered in the root predicate of every kind for each engine
  (`QueryBuilder::canonicalAddressSql()` / `canonicalDomainSql()`: PostgreSQL `~`, MariaDB/MySQL
  `REGEXP`, both behind an ASCII-only test, SQLite `GLOB`; all patterns bound) and applied by
  `RecordMapper` to the same rows, so an entry is offered everywhere or nowhere – it is a strict
  subset of Roundcube's e-mail check (seeded corpus test; the renderings compared with the PHP rule
  and on every engine). The hidden lists and exclusions therefore compare canonical ASCII. A row the
  re-check still drops (a defect, a row changed during the request) refuses the full set as before,
  and the log names it by a non-reversible tag (`row_invalid` for an address or domain-column
  failure, including the e-mail check; `row_out_of_scope` for scope and hidden lists; both
  `row=<tag>`). A name Roundcube takes for empty falls back to first and last name or the local part.
  `diag` replaces its "lower-case addresses" line with `entries not offered`: per kind (persons, alias
  accounts, mailing lists, per-user aliases, hosted domains) the rows outside the rule, with up to 10
  JSON-escaped examples and their `row=` tags; on MariaDB/MySQL also the addresses with capitals it
  offers and a WARN for a Turkish/Azerbaijani collation of an address column.
- F-15: what one authenticated user can make the directory spend. (a) Any connection failure that is
  not the English "too many connections" was memoised for 30 s in the session – also PostgreSQL's
  "remaining connection slots are reserved" and every connection error of a server with another
  `lc_messages`, so a user holding the role's connections took the directory away from other
  sessions. Now only an unreachable or slow server is memoised (the attempt took at least half of the
  connect timeout, or a known "cannot reach" error); any other answer the server gives at once fails
  that request only (`db_busy`, or `db_down cause=refused` without `retry_in`). (b) Core's copy action
  calls `get_record()` for every ID of the request, without a bound: at most `galbridge_max_ids`
  contacts are now read one by one per request (`LookupBudget`, shared by the books of the request),
  the rest is refused with the "too many contacts" message. (c) The details and aliases of a full set
  went into one statement with every address, which `rcube_db` binds client-side at a quadratic
  cost; they are read in batches of 500 (`Repository::DETAIL_BATCH`), and `diag` warns above 10000
  for `galbridge_max_export` and the group cap. (d) The documentation presented a limit on
  `_action=autocomplete` in the query string as the required control; it now requires per-client
  request-rate and concurrency limits on every Roundcube PHP request (the concurrency limit below the
  role's connection limit) and says why the action key alone misses requests (POST body, cookie,
  URL-decoding and character stripping by Roundcube).
- F-19 (completed): Roundcube's suggestion list fills its template with `String.replace()` using
  the directory text as the replacement string (`$&`, `` $` ``, `$'`, `$1`, `$<x>` were expanded) and
  shows `##…%%` as bold text without the markers. `$` now becomes `＄` (U+FF04), runs of `%` are split
  by blanks, and a `%` at either end of a text becomes `％`, so two fields of a name template cannot
  form `%%`; group- and organization-label search uses the same notation. Organization labels are cut
  at an at-sign like every other text that reaches recipient names (reported as a configuration
  problem). Phone numbers keep their free text under every display rule (no allow-list – decided and
  documented). The template matrix test covers every text field, payloads of every class and
  templates with every placeholder.
- F-31: complete-or-refuse and list bounds. Compose to selected and export of selected silently
  dropped a selected ID that was malformed or found no visible entry; such a selection is now refused
  as a whole (`incomplete`, log `set_incomplete missing=N dropped=N`; the export answers 204). A full
  set is recognised by core's own page sizes (9999, 99999) and the recipient actions of a group: a
  browse page above the page bound (`max_pagesize` 0 or above 1000, a large `addressbook_pagesize`)
  was handled like an export or a group expansion and is now a page of at most 1000 entries with the
  new message "The directory shows at most N entries per page"; `pagesize` is honoured where
  `addressbook_pagesize` is not set; `diag` warns. List totals at `galbridge_max_count` now tell the
  user ("Only the first N directory entries can be browsed …", new message), and the pages of a search
  result stop at the result cap with its notice. The per-user aliases of a page are bounded per owner
  (`ROW_NUMBER()`), so a few owners with very many aliases no longer leave other entries of the page
  without theirs (`aliases_skipped` is gone); window functions are now required (MariaDB 10.2, MySQL
  8.0; `diag` FAIL while `galbridge_user_aliases` is on with an older server). The page number is
  clamped (PHP 8.5 warned about an out-of-range float-to-int cast). The export of a stale stored search
  was already refused (STAGE 6).
- F-32: in a book of several domains, the fields an entry of another domain does not carry (first and
  last name; department and phones under a name template that shows them before `{name}`) remained
  search and sort criteria, so their values could be confirmed; they are now neither matched nor
  sorted by for such an entry (first and last name only when it has no display name – they are then
  its shown name), from one list for record and SQL (`RecordMapper::foreignWithheld()`). Department
  groups of the global book merged equal department names of different tenants – any tenant could
  add accounts to another tenant's group; they are now per domain, labelled `(domain) Department`
  (key `domain:department`; group IDs of 1.0.x select nothing), and `diag` warns about
  `galbridge_global_groups = 'department'`. Domain books of an organization keep one group per
  department name.
- Upgrading (directory sets and text): no configuration or database step. Entries whose address does
  not follow the address rule – characters outside `a-z 0-9 . _ + = -` in the local part, non-ASCII,
  odd dots, single-label or non-letter top-level domains, `_` in a domain label, an address that is
  not in its domain column, and on PostgreSQL addresses written with capitals – disappear from every
  book: run `diag` and correct the entries it lists under `entries not offered`. Compose to selected
  and export of selected are refused when a selected contact cannot be read; copying to a personal
  address book stops after `galbridge_max_ids` contacts; page sizes above 1000 give pages of 1000
  with a message. With `galbridge_user_aliases` the server must be MariaDB 10.2 / MySQL 8.0 or newer.
  Global-book department group IDs change. Log parsers: `row_invalid` / `row_out_of_scope` carry
  `row=`, `set_incomplete` may carry `missing=`, `db_down` may come with `cause=refused` and without
  `retry_in`, `aliases_skipped` no longer appears. Reverse proxies: configure the per-client limits of
  SECURITY.md.
- F-21: CI no longer switches off Composer's malware and abandoned-package blocking
  (`COMPOSER_NO_BLOCKING` did that along with the advisory blocking): only the blocking of releases
  with advisories is off, only for `composer install` in the PHPUnit job, and the pinned 1.7.0 core
  of the compatibility legs is the only audit exception (a runner-local `policy.advisories.ignore`
  entry with the constraint 1.7.0). Every leg runs a plain `composer audit`, which fails on
  advisories, malware-list matches, abandoned packages and unreachable advisory sources - the 1.7.0
  legs had filtered the JSON output for advisories only. Composer is downloaded in a pinned version
  and checked against its SHA-256 (setup-php installs no tools), every job runs on `ubuntu-24.04`,
  and every job that resolves dependencies records them with the source commit of each package, the
  runner image, PHP and Composer versions, and keeps its `composer.lock` as a 90-day artifact - the
  workflow comments, SECURITY.md (GB-32) and the threat model had claimed that for jobs that did not
  do it. The release verification runs in a disposable container from the lock of the release
  commit's CI run, as an unprivileged user - never as root on the machine that signs (1.0.1's
  release procedure asked for exactly that). The documentation relies on the daily run only together
  with a check that GitHub has not disabled the schedule (docs/RELEASING.md step 3).
- F-22 / I-07 (completed): release tags must be signed with an SSH key listed in
  `.github/release-signers` (principal `galbridge-release`; RSA 8192, fingerprint
  `SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`, published in SECURITY.md, README 2.1 and
  docs/RELEASING.md). The CI job `release` accepted the signature of ANY GitHub account; it now
  verifies the tag with `git verify-tag` against the key file of `main` (`tests/release/verify-tag.sh`:
  an annotated tag object of that very name - a signed tag of another version pushed under this name
  is refused -, the commit CI tested, a commit of `main`), puts the tag object in place from GitHub's
  API when the checkout stored only a reference, and needs no fetch of its own (the old step's
  `git fetch` had no credentials on a private repository). The archive is checked against an
  allow-list of full paths (`tests/release/check-archive.php`, also on every push by the test suite)
  instead of two diverging deny-lists. The maintainer runs both scripts before pushing the tag, because
  Packagist publishes on push. README 2.1 gives a verify command that works for SSH signatures
  (1.0.1's failed without `gpg.ssh.allowedSignersFile`), asks for the fingerprint comparison, drops
  GitHub's unsigned "Source code" archive as an alternative and says that Composer does not verify
  signatures (with the check of the installed commit against a verified tag).
- F-23: SECURITY.md named two private reporting channels that did not work - GitHub's private
  vulnerability reporting (unavailable while the repository is private) and an e-mail address that is
  published nowhere. GitHub's private vulnerability reporting is now the only private channel, with a
  fallback that discloses nothing (a public issue that only says a report is waiting) and the URL of
  the current policy. docs/RELEASING.md has an ordered first-publication checklist (section 0): the
  signed tag pushed while the repository is private, the repository made public, the Packagist
  submission immediately after (vendor name checked first), then private vulnerability reporting,
  secret scanning with push protection, rulesets for `main` and `refs/tags/v*`, the allowed actions
  and two-factor authentication. 1.0.0 and 1.0.1 were never tagged: their sections below are marked
  as not published, and the documentation no longer calls them published releases. README 2.1
  installs the version of `galbridge.php` (one `v=` line; the version bump changes both).
- F-24: the secret scanner excepted every current and future file below `tests/fixtures/vmail/` and
  three whole harness files; it now excepts one named file - `tests/fixtures/vmail/seed.sql`, whose
  canary values are meant to look like secrets - besides exact values (the throw-away passwords, the
  `CHANGE_ME_*` placeholders, the public fingerprint of the release key) and git-ignored local
  directories. A new rule reports a database DSN with a password (the `grants` DSN line, a copied
  configuration), and job `security` proves with controls that the rule works and that a new file of
  the fixture directory is scanned. `.gitguardian.yaml` names the same exceptions. `.gitignore` also
  covers copies of the configuration file (`config.inc.php.*`, `config.inc.php~`, `*.bak`, `*.orig`),
  `galbridge.inc.php`, `config/`, grants output saved at the top of a checkout (`/*.sql`) and `.env.*`.
- F-25: the end-to-end and browser harness refuses to run Roundcube and Chromium as root (exit 2;
  `GALBRIDGE_E2E_ALLOW_ROOT=1` for a disposable container), keeps Chromium's sandbox unless
  `GALBRIDGE_UI_NO_SANDBOX=1` says otherwise, and never lets Selenium download a driver.
  `tests/e2e/setup.sh` checks everything before it changes anything, never redirects a plugin link
  that points elsewhere (unless `GALBRIDGE_E2E_REPLACE_LINK=1`, put back afterwards) and refuses a
  configured Roundcube tree (`GALBRIDGE_E2E_ON_CONFIGURED_TREE=1`); the suites stop only the web
  server they started and remove only a link they created. `tests/fixtures/vmail/load.sh` creates an
  account for every host with the published test password only with `GALBRIDGE_LOAD_ANY_HOST=1`
  (otherwise `localhost` and `127.0.0.1`) and has a `drop` mode; `tests/e2e/dovecot.sh` restores the
  Dovecot service state it found and works without systemd.
- F-34: CI fails on any skipped test (`--fail-on-skipped`); the two MariaDB-only cases of
  `CliToolTest` no longer get a PostgreSQL case that could only be skipped. The refusal of
  `bin/galbridge.sh` to run as root and the checks of `--allow-root` run as root in CI, from a
  root-owned copy of the checkout (the group `root-aware`, which holds every `root` test - PHPUnit 13
  selects nothing for `--group root,root-aware`). The end-to-end and browser suites run in CI (job
  `e2e`: Debian 13 container, Roundcube 1.7.0 and the newest known 1.7.x from release tarballs
  verified by SHA-256, MariaDB and PostgreSQL, Dovecot 2.4, Chromium, as an unprivileged user) and,
  like `tests/e2e/matrix.sh`, count a skipped case as a failure (`--allow-skips` for a partial local
  run); the first strict run brought E2E case 25 in line with the complete-or-refuse rule of F-31 (a
  selection with IDs of another tenant is refused as a whole). The source guard covers every PHP file
  of a release and string literals that name a restricted function (`Remediation2SourceGuardTest`);
  the workflow, the threat model and docs/TESTING.md call it a lexical regression check, not a proof.
- Upgrading (development and release): installations are not affected. Contributors: a local run of
  the E2E or browser suites as root needs `GALBRIDGE_E2E_ALLOW_ROOT=1`; a MariaDB that relied on the
  test role's `'%'` account (skip-name-resolve) gets the `'127.0.0.1'` account instead; new tests must
  not skip in the full CI environment (root-only tests carry the groups `root` and `root-aware`), and
  a DSN password of 16 or more characters in a test is reported by the secret scan (use short or
  `CHANGE_ME_*` values). Releases must be signed with the SSH key of `.github/release-signers`; a
  GPG-signed tag fails the release job.
- F-35: an ambiguous switch was read as its built-in default while it disables every book – the
  permissive value for `domain_book`, `domain_autocomplete`, `domain_export`, `global_autocomplete`,
  `include_aliases` and `include_mailing_lists`, although the documentation called it "the
  conservative value". It is now read as its restrictive value (`Config::RESTRICTIVE`: off, except
  `hide_expired` and `mark_foreign_domains`), and `diag` shows that value (`off (INVALID)`). `diag`
  describes the order of autocomplete suggestions for the core release in use: the ICU collator,
  which lists entries with the `(domain)` marker first, sorts only from Roundcube 1.7.6; 1.7.0 –
  1.7.5 sort with the C library locale of the user's language, which usually interleaves them by
  the domain in the marker. Documentation and comments corrected: which configuration errors
  disable every book (an ambiguous switch, the visibility column, the hidden lists), which only the
  book they protect (a domain deny-list) and which refuse every connection (the refused roles); the
  password plugin's DSN is read from its own file as well; the core method that renders recipient
  inputs is `rcmail_sendmail::headers_output()` (there is no `compose_headers()`); `init()` reads the
  action as core took it from the request, before the `startup` and `ready` hooks; the inventory of
  core paths includes the vCard attachment of `vcard_attachments` and `rcmail::contact_exists()`;
  the `autocomplete` key of a source entry is informational (Roundcube 1.7 does not read it);
  examples use reserved domain names. Since F-17 a new own alias is taken over with the next reading
  request – the documentation and `diag`'s `viewer cache` line still said it waited for
  `galbridge_scope_ttl`.
- F-36: the residual risks of documented decisions are now visible where code can see them. `diag`
  warns for a `galbridge_imap_hosts` entry without a port (it covers every IMAP port of its host),
  while `galbridge_debug` is on (also through `ROUNDCUBE_GALBRIDGE_DEBUG`), and – instead of an INFO –
  while the configuration file lies in the plugin directory and `--url` was not given; with `--url`
  it asks the web server for the configuration file in use (`config over HTTP`: FAIL when it is
  served as text, nothing of the answer printed); on PostgreSQL it reports statement logging by
  `pgaudit.log` or `auto_explain.log_min_duration`, which the grant set does not switch off. An
  ERROR or WARN line that Roundcube refuses to write to the `galbridge` log (`per_user_logging`
  without a log directory of the user, an unwritable file) goes to Roundcube's `errors` log (and to
  PHP's error log as the last resort) instead of disappearing. `grants` and the documentation
  recommend `config/galbridge.inc.php`; the unverifiable claim that iRedMail's nginx templates deny
  both configuration locations is replaced by the `diag --url` check, and the `.htaccess`
  precondition is exact (Apache 2.4, `AllowOverride AuthConfig` or `All`). The threat model no
  longer says that a request loop cannot flood the log (the bound is one line per reason code per
  request). The register of accepted risks gains GB-31 (the configuration file in the plugin
  directory), GB-22 (debug texts, log volume, refused log lines) and GB-21 (no statement audit trail
  of the PostgreSQL role), and documents group IDs, browser history and proxy logs (GB-04), the port
  rule (GB-11), the log volume of a request loop (GB-26), the order of suggestions by core release
  (GB-19) and the role's verifier as a secret, with the conditions under which a login handshake
  can be recorded (GB-30).
- Upgrading (documentation and accepted risks): no configuration or database step. `diag` without
  `--url` warns while the configuration is `plugins/galbridge/config.inc.php` – move it to
  `config/galbridge.inc.php` (README 2.3) or run `diag --url=`; a `config over HTTP` FAIL means the
  web server hands out the database password: deny it and run `grants --reset-password`. Further new
  WARN lines (an IMAP host entry without a port, debug on, a PostgreSQL audit extension) keep exit
  code 0 unless `--strict` is given. With `per_user_logging`, GAL Bridge's ERROR and WARN lines of
  requests without a user log directory now appear in Roundcube's `errors` log.
- Database compatibility (found by running the suites against MariaDB 10.5 – 13.1, MySQL 8.0, 8.4,
  9.7 and 26.10 and PostgreSQL 13 – 19): `grants --port=N` writes the port into the printed DSN
  (`host:port`, `[IPv6]:port`; digits only, 1–65535; a repeated option is a usage error) – before, a
  server on another port needed the printed DSN edited by hand, and the DSN as printed reached the
  default server or none. With `--driver=mysql` it is refused together with the host `localhost`
  (also the default host), which PHP reaches through the unix socket whatever the port: the message
  says to use `--host=127.0.0.1`.
- Database compatibility: on a MariaDB/MySQL server set up by `mariadb-install-db` (RHEL and its
  rebuilds, FreeBSD, the official tarballs) every account can see the database `test` (and `test_*`)
  – MariaDB 10.11 and later grant it to `PUBLIC`, older servers through blank-user rows of
  `mysql.db` – and the plugin refused every role there (`role_too_broad`, "privileges outside the
  directory (test)"): every book was gone. Such a database, on which the role holds no grant of its
  own, is now a warning (`server_test_database`) and a `diag` FAIL of its own ("server-wide test
  database", naming `mariadb-secure-installation` and the statements that remove it); any other
  database the role can see, and a test database it holds a privilege on, stay refusals (SECURITY.md
  register GB-23, THREAT_MODEL GB-23).
- Database compatibility (test suite): every test that derives a DSN or a grants output from the test
  DSN keeps its port; on MySQL without an active `mysql_native_password` plugin (8.4 and later by
  default, 9.x has none) the tests apply the `--clear-text-password` form and
  `tests/fixtures/vmail/load.sh` creates the test role with it (the verifier statement of the
  template cannot run there); MySQL variants of the view checks (`EXPLAIN FORMAT=TREE` and
  `EXPLAIN ANALYZE` instead of MariaDB's JSON), of the unfenced-view `diag` text (MySQL has no
  `VIEWS.ALGORITHM`), of the clear-text guard tests (the output runs on MySQL) and of the role test
  (MySQL roles, with and without a default role: without one `diag` fails on `role memberships`, the
  write statements are refused; with one it fails on `write privileges` as well). A test whose engine
  has no server in a run is skipped without a PHP warning.
- Database compatibility (CI and documentation): a new CI job `databases` runs the PHPUnit suite
  (PHP 8.4, the newest Roundcube 1.7.x, failing on any skipped test) against seven pairs of a
  MariaDB or MySQL server and a PostgreSQL server – MariaDB 10.6, 10.11, 11.4 and 12.3, MySQL 8.0,
  8.4 and 9.7, PostgreSQL 13 – 19 – in Docker service containers pinned by digest (one digest per
  tag in the whole workflow, checked by `Remediation2CiTest`); a step checks that each leg's servers
  report the flavour and the versions the leg names, and the fixture is loaded as in job `tests`.
  README section 1 names the server versions tested with this version (MariaDB 10.5, 10.6, 10.11,
  11.4, 11.8, 12.3 and 13.1, MySQL 8.0, 8.4, 9.7 and 26.10, PostgreSQL 13 – 19) and the CI legs; the
  statements that MySQL was not tested (README 10, SECURITY.md register GB-23, THREAT_MODEL GB-14,
  docs/TESTING.md section 7) now say what the suites verified there; docs/TESTING.md describes how
  to run the database matrix, docs/RELEASING.md how to refresh the digest of every CI image.
- Upgrading (database compatibility): no step. Scripts that called `grants` with a host of their own
  and a non-default port can pass `--port` instead of editing the DSN. A server with the
  `mariadb-install-db` test database gets its books back and a `diag` FAIL until the database is
  removed (`mariadb-secure-installation`).
- Final review (PRODUCT-1): the PostgreSQL role check (`RoleScope`) did not look at privileges of the
  role in the directory database that are not on a relation: `CREATE` on another schema – or `USAGE`
  on it, `EXECUTE` on a function, a foreign server or wrapper, a large object, default privileges –
  left a hand-made role reported as clean, although README, SECURITY.md and the class documentation
  promised its refusal (MariaDB/MySQL refused the same already). They are now privileges outside
  (`role_too_broad`, named by `pg_identify_object()`, in the same words whatever the server's
  language): `CREATE` on every other schema (also through `PUBLIC`), and every privilege of the
  role recorded in `pg_shdepend` for this database that is not on a relation and not its `USAGE` on
  its own schema; likewise a privilege on (or the ownership of) a tablespace or – PostgreSQL 15+ – a
  configuration parameter (`GRANT SET` / `ALTER SYSTEM ON PARAMETER`), which the check of other
  databases did not cover. The old default of PostgreSQL 14 and older – `CREATE` of `PUBLIC` on schema
  `public` – stays a warning (`role_can_create`) and a `diag` FAIL, also when the role's own schema
  is another one; granted to the role itself it is a privilege outside. `diag`'s CREATE line
  (`schema public`) now judges the role's schema, schema `public`, every other schema and the
  database, so its OK text ("no CREATE privilege (its schema, schema public, the other schemas and
  the database)") is true. The roles of the grant sets of 1.0.0, 1.0.1 and this release hold none of
  these (verified on PostgreSQL 13 – 19).
- Final review (CLI-1): `diag --allow-root` loaded – and so ran as root – every per-host file of
  `include_host_config`, also one named by an absolute or `../` path outside the configuration
  directories, or one another account could write (the root check of `galbridge.sh` covers the
  configuration directories and their `*.php`, `*.inc`, `*.sql` and `*.sh` files only). As root a
  per-host file is now loaded only when it lies in one of the configuration directories that check
  verified and is owned by root and writable by nobody else; any other is named in a WARN line ("not
  loaded as root - ...: run diag as the web server user to check it") and never run. Runs as another
  user are unchanged.
- Final review (CLI-2): the header of the `--clear-text-password` output still said that the file
  carries the password as its verifier and pointed at a note this output leaves out. It now says
  that the password stands in clear text in the statements of the `[role]` block and that the output
  must be kept private and deleted after use (a MariaDB/MySQL template whose header sentence is not
  the shipped one is refused for that form); the documentation of the tool class and
  `config.inc.php.dist` name this exception to "the SQL output never holds the password".
- Final review (CI-1): the "least-privilege" PostgreSQL fixture access of the unprivileged E2E user
  (job `e2e`, docs/TESTING.md section 2) was `pg_read_all_data` / `pg_write_all_data` – every table
  of every database of the cluster, `pg_authid` included. `tests/fixtures/vmail/load.sh` now grants
  an existing role named by `GALBRIDGE_FIXTURE_USER` the rows of the test database only (`CONNECT`
  on it, `USAGE` on its schema `public`, `SELECT, INSERT, UPDATE, DELETE` on its tables, `USAGE,
  SELECT, UPDATE` on its sequences) after every PostgreSQL load; it refuses a bad or reserved name,
  the test role, a superuser and a member of those predefined roles. Job `e2e` creates the role
  `e2e` and loads the fixture with it; the E2E helper probes its access on the fixture database.
- Final review (CI-2): `.gitleaks.toml` excepts the git-ignored local directories (`tests/e2e/run/`,
  `tmp/`, `vendor/`) by path, and gitleaks applies that exception to the history scan as well: a
  secret committed there and removed again passed the CI scans. Job `security` now fails before the
  history scan when any commit of any branch or tag ever had a file in those directories (the list
  is checked against the exception).
- Final review (DOCS-1 – DOCS-4): the documents told to give the plugin directory recursively to
  owner and group `root:root`, which also takes the PHP group away from a configuration kept in
  `plugins/galbridge/config.inc.php` (mode 0640: unreadable, every book disabled); they now say
  `chown -R root plugins/galbridge && chmod -R go-w plugins/galbridge` (the owner only – the refusals
  check the owner and the write bits, never the group). The `include_host_config` upgrade step and
  the manual steps of both grant templates name the plugin's configuration file
  (`config/galbridge.inc.php`, or `plugins/galbridge/config.inc.php` if that is the one in use –
  never both) instead of creating a second file. The session inventories of SECURITY.md, README 6
  and the threat model (GB-15) name `galbridge_env_noted` (the names of the `ROUNDCUBE_GALBRIDGE_*`
  variables in effect, never their values), and the register row GB-24 says that the DSN is handed
  to the driver rebuilt from the judged parts, not "exactly as written".
- Upgrading (final review): no configuration or database step for a role made by a grant set. A
  hand-made PostgreSQL role with such a privilege loses its books until the privilege is revoked
  (`diag` names it under `privileges outside`). `diag --allow-root` with `include_host_config` can
  show WARN lines for per-host files it no longer loads: run `diag` as the web server user to check
  them. Development: `load.sh` takes the E2E user's PostgreSQL access from `GALBRIDGE_FIXTURE_USER`
  on every PostgreSQL load (docs/TESTING.md section 2); it refuses a role that is still a member of
  `pg_read_all_data` or `pg_write_all_data` (revoke that membership first).

## [1.0.1] – 2026-11-24 – Not published (no tag was created; superseded by 1.0.2, the first tagged release)

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
was fixed before 1.0.1 was completed:

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

## [1.0.0] – 2026-11-24 – Not published (no tag was created; superseded by 1.0.2, the first tagged release)

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
