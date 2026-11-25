# GAL Bridge (`galbridge`) – Global Address List for Roundcube 1.7

[![CI](https://github.com/takenek/galbridge/actions/workflows/ci.yml/badge.svg)](https://github.com/takenek/galbridge/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/takenek/galbridge.svg)](https://packagist.org/packages/takenek/galbridge)
[![License](https://img.shields.io/packagist/l/takenek/galbridge.svg)](LICENSE)

GAL Bridge gives Roundcube Webmail **1.7** (every 1.7.x release) a read-only **Global Address
List** read directly from the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and
PostgreSQL editions). It uses
only the official plugin API: the `addressbooks_list` and `addressbook_get` hooks, the
`addressbook_export` hook (vCard export only), the `contact_exists` hook (a directory entry is never
a "known contact"), the `preferences_update` hook (in Settings only: no `galbridge_*` value is ever
stored as a user preference) and an `rcube_addressbook` backend. It changes nothing
in Roundcube core and has no JavaScript, templates, skins or database tables of its own: Roundcube's
address-book UI renders everything.

* a **domain directory** for every user: the people, alias accounts and mailing lists of the
  user's own domain, or of every domain of the user's organization (`galbridge_organizations`),
* an optional **global directory** across an explicit set of domains (off by default),
* **groups** by department or by domain, usable as recipients: an expansion is either complete or
  refused with a visible message, never a truncated prefix,
* **iRedAPD-aware** alias accounts and mlmmj mailing lists: an entry is shown only to users who may
  post to it (`accesspolicy`),
* every Roundcube 1.7 address-book path: source list, contact list with paging, show, print,
  QR code, vCard export (all, selected, search), compose to selected contacts and to a group, copy
  to a personal address book, quick, advanced and saved searches, mail autocomplete (contacts and
  groups; in compose and in the Redirect dialog), the compose contact list and its search, the
  vCard attachment of the bundled `vcard_attachments` plugin, and core's known-contact check
  (`rcmail::contact_exists()`, from which the directory books are removed),
* **tenant isolation by construction**: what a user can see comes only from the authenticated login
  and the administrator configuration, never from request parameters (`_source`, `_gid`, `_cid`,
  `_search`, `_q`, `_page`),
* **least privilege, enforced**: a dedicated read-only database role with column-level grants that
  reads the forwardings through a row-restricting, materialised view; the plugin refuses iRedMail's
  own roles and any role that can do more than read the directory (a secret column of any table, the
  `forwardings` table, a membership, a role attribute, a global privilege, a privilege outside the
  directory or one to change it),
* data minimisation through a field allowlist, English and Polish translations, a diagnostics and
  grant tool (`bin/galbridge.sh diag`, `bin/galbridge.sh grants`).

GAL Bridge replaces the unmaintained *Roundcube-SQL-Global-Address-Books* plugin
(`sql_global_addressbooks`). It was written from scratch; see [docs/MIGRATION.md](docs/MIGRATION.md).

Created and maintained by **[Marek Królikowski (TaKeN)](https://github.com/takenek)**,
operating as **TaKeN.PL Usługi Informatyczne Marek Królikowski**.

Documentation: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) ·
[docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) · [SECURITY.md](SECURITY.md) ·
[docs/MIGRATION.md](docs/MIGRATION.md) · [docs/TESTING.md](docs/TESTING.md) ·
[docs/RELEASING.md](docs/RELEASING.md) · [CHANGELOG.md](CHANGELOG.md)

---

## 1. Requirements

| Component | Requirement |
|---|---|
| Roundcube | **1.7.x** – the whole 1.7 branch (`composer.json` `extra.roundcube`: `min-version` 1.7.0, `max-version` 1.7.999). The backend follows the address-book call paths of the 1.7 core (page sizes, search set replay, group expansion, ID transport, error 700 handling); the test suite runs against the oldest release (1.7.0) and the newest one (1.7.7 when this version was released – the Roundcube release that iRedMail 1.8.11 installs), and CI repeats it every day against the newest 1.7.x on Packagist. **Run the newest 1.7.x**: patch releases of the branch fix security problems of Roundcube itself and need no new GAL Bridge release (section 5). `diag` warns on a patch release older than the newest one it knows and on a core that reports `1.7-git` (installed from git or Packagist), and fails on any other branch. `min-version` is enforced only by the Composer plugin installer. Roundcube 1.6 and older are not supported; a new minor branch (1.8) needs a GAL Bridge release that names it |
| PHP | 8.1 – 8.5 (composer `php >=8.1 <8.6`) – the versions Roundcube 1.7 itself runs on; 8.3 or later recommended. The plugin does not narrow that range: use a PHP that still receives security fixes, from php.net or from your distribution. `diag` warns for a PHP branch past its upstream security support (8.1 since 2026-01-01, 8.2 from 2027-01-01) – on a distribution that maintains its own PHP packages read the warning as a reminder to check that support ([SECURITY.md](SECURITY.md#supported-versions)) |
| PHP extensions | `pdo`, `mbstring`, and `pdo_mysql` (MariaDB/MySQL edition) or `pdo_pgsql` (PostgreSQL edition). Recommended: `intl` (IDN domains, Unicode NFC normalisation). Without `intl` a configured domain written with non-ASCII characters cannot be normalised: such an allow-list entry is dropped, and a deny-list containing one disables the book it protects (fail closed); `diag` warns when `intl` is missing |
| iRedMail | **1.8.x, MariaDB/MySQL or PostgreSQL edition** (the `vmail` schema of iRedMail 1.8.11). A server that never applied iRedMail's `update/1.7.3` (columns `first_name`, `last_name`, `mobile`, `telephone`, `birthday`) keeps working with fewer fields. The **OpenLDAP edition is out of scope**: there iRedMail configures Roundcube's native `ldap_public` address book |
| Database server | MariaDB **10.2** or later, MySQL **8.0** or later, or PostgreSQL – every platform of iRedMail 1.8 qualifies. **Tested** with this version (the full suites, docs/TESTING.md section 6): MariaDB 10.5, 10.6, 10.11, 11.4, 11.8, 12.3 and 13.1, MySQL 8.0, 8.4, 9.7 and 26.10, PostgreSQL 13, 14, 15, 16, 17, 18 and 19; older releases (MariaDB 10.2 – 10.4, PostgreSQL 12 and earlier, all past their end of life) were not. Every CI run repeats the suite against MariaDB 11.8 + PostgreSQL 17 (job `tests`) and against MariaDB 10.6 + PostgreSQL 13, MariaDB 10.11 + PostgreSQL 14, MariaDB 11.4 + PostgreSQL 15, MariaDB 12.3 + PostgreSQL 16, MySQL 8.0 + PostgreSQL 18, MySQL 8.4 + PostgreSQL 19 and MySQL 9.7 + PostgreSQL 17 (job `databases`). **MySQL 8.4 and later** do not load `mysql_native_password`: create the role with `grants --clear-text-password` (section 2.2). A server set up by `mariadb-install-db` (RHEL and its rebuilds, FreeBSD, the official tarballs) keeps a `test` database open to every account until `mariadb-secure-installation` removes it: the plugin works, `diag` fails until then (section 2.2). The per-user aliases (`galbridge_user_aliases`) are read with a window function (`ROW_NUMBER()`), which older MariaDB/MySQL servers lack: `diag` fails on such a server while the option is on |
| Database account | a dedicated read-only role on the `vmail` database (section 2.2) |
| Web server | the plugin serves nothing over HTTP; deny access to `plugins/galbridge/` (section 2.6) |

**Installation requirements** (decisions recorded in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md);
`diag` checks the role, the IMAP host binding, the TLS rule and `addressbook_sort_col`):

* **A dedicated read-only role** with its own DSN in `galbridge_db_dsn`. There is no fallback to
  Roundcube's own database connection: PostgreSQL cannot query across databases, and iRedMail's
  `roundcube` role can UPDATE `vmail.mailbox` (for the password plugin).
* **A fixed IMAP server.** With iRedMail's `imap_host = 'tls://127.0.0.1:143'` nothing has to be
  configured. With a templated (`%d`, `%n`, `%s`), array or empty `imap_host`, or with Roundcube's
  `include_host_config`, list the directory's IMAP servers (`host` or `host:port`) in
  `galbridge_imap_hosts`; otherwise the plugin stays disabled (section 3.9). **Every** listed server
  must authenticate against this `vmail` database.
* **Verified TLS** for a `vmail` database on another host, and for a directory **IMAP server on
  another host** – `ssl://`, or `tls://` on Roundcube 1.7.7 or later, with certificate verification
  (section 3.9).
* **The directory policy in the plugin's own file**: every `galbridge_*` option in
  `config/galbridge.inc.php` (or `plugins/galbridge/config.inc.php`) as plain assignments – not in a
  per-host file of `include_host_config`, which the client selects with the `Host` header, and not
  pulled in with `include_once` (section 2.3).
* **The plugin directory is not served by the web server** (section 2.6; `diag --url=` checks it),
  and the plugin is installed from a release, not as a git clone (section 2.1).
* **The plugin code belongs to root** and nobody else can write it – also after a Composer run
  (section 2.1): the grant statements are generated as root from that code (`grants --allow-root`,
  section 2.2), which refuses anything else, as `grants` refuses to run as the web server user.
* **Per-client limits in the reverse proxy** when the webmail is reachable from the Internet: a
  request-rate limit and a concurrency limit per client address on **every** request passed to
  Roundcube's PHP, the concurrency limit well below the role's connection limit; a tighter limit for
  autocomplete is optional ([SECURITY.md](SECURITY.md#hardening-checklist-for-administrators)). The
  plugin has no rate limiter of its own (section 10).
* Recommended: `$config['addressbook_sort_col'] = 'name';` (section 2.5).

## 2. Installation

All commands are run in the Roundcube installation directory: `/opt/www/roundcubemail` on iRedMail
(a symlink to the versioned directory, e.g. `/opt/www/roundcubemail-1.7.7`;
`/usr/local/www/roundcube` on FreeBSD), e.g.
`/var/www/roundcube` elsewhere. `www-data` below is the PHP-FPM / web server user: `www-data` on
Debian and Ubuntu, `nginx` on the RHEL family, `www` on FreeBSD and OpenBSD.

### 2.1 Install the plugin files

**Release archive** (from the signed tag)

```sh
v=1.0.3   # the release to install: https://github.com/takenek/galbridge/releases (only the latest is supported)
git clone --branch "v$v" https://github.com/takenek/galbridge.git /root/galbridge-src   # NOT below the web root
git -C /root/galbridge-src -c gpg.ssh.allowedSignersFile=/root/galbridge-src/.github/release-signers verify-tag "v$v"
git -C /root/galbridge-src -c tar.umask=0022 archive --prefix=galbridge/ "v$v" | tar -x -C /opt/www/roundcubemail/plugins
rm -rf /root/galbridge-src
```

The commands need `git` 2.34 or later and `ssh-keygen`, which git runs to check the SSH signature
(Debian/Ubuntu: `apt install git openssh-client`; a minimal server may have neither – without
`ssh-keygen` `verify-tag` fails with `cannot run ssh-keygen`).
`verify-tag` must succeed and print
`Good "git" signature for galbridge-release with RSA key SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`.
The allowed-signers file comes from the same clone as the tag, so it proves nothing by itself:
**compare the fingerprint** with SECURITY.md section "Release signing key" as GitHub shows it and
with the SSH signing keys of the maintainer's GitHub account (how: that section). If the tag does not
exist, `verify-tag` fails or the fingerprint differs, do not install. GitHub's "Source code"
downloads of a release page are generated by GitHub and carry no signature: they are no
installation method.

The directory **must** be named `plugins/galbridge`. `git archive` writes exactly what a release
contains: the plugin, its SQL templates and its documentation. `tar.umask=0022` matters: without
it git writes the archive with group-writable modes (0775 / 0664), and `tar` run by root restores
them as they are – `diag` would warn about a writable directory. Do **not** clone the repository
into `plugins/`: a clone also brings `tests/` – scripts that drop and create databases and
reconfigure a Dovecot server for the test suites – and the repository history, none of which belongs
on a mail server (`diag` warns about such a tree).

**Composer** (Roundcube's plugin installer)

Create the configuration **outside** the package first – `config/galbridge.inc.php`, owner root,
group of the PHP user, mode `0640` (section 2.3) – then install from the release archive:

```sh
cd /opt/www/roundcubemail
install -o root -g www-data -m 0640 /dev/null config/galbridge.inc.php   # fill it in as in 2.3
composer require --prefer-dist "takenek/galbridge:^1.0"    # a tagged release; never dev-main in production
```

**iRedMail (MariaDB/MySQL and PostgreSQL editions):** this `composer require` stops with
`pear/net_ldap2 ... requires ext-ldap * -> it is missing from your system` and changes nothing.
Roundcube's own `composer.lock` holds its LDAP libraries (`kolab/net_ldap3`, `pear/net_ldap2`),
which declare the PHP `ldap` extension, and iRedMail installs that extension only for its OpenLDAP
edition. Exempt that one requirement – the LDAP libraries are not touched, and nothing of GAL Bridge
needs `ldap` – with `--ignore-platform-req=ext-ldap` (never `--ignore-platform-reqs`, which drops
every requirement, the PHP version included), on `composer require` and on every later
`composer update`; installing the extension (`apt install php-ldap`) works as well:

```sh
composer require --prefer-dist --ignore-platform-req=ext-ldap "takenek/galbridge:^1.0"
```

The package is listed on Packagist ([takenek/galbridge](https://packagist.org/packages/takenek/galbridge),
updated from GitHub at every push). Production installations take a tagged release by version
constraint – but
**Composer does not verify tag signatures**: check what it installed against a verified clone (the
first three commands of the release archive above, without the archive step). `composer show
takenek/galbridge` must name the source `https://github.com/takenek/galbridge` and, as its source
reference, the commit that `git -C /root/galbridge-src rev-parse "v$v^{commit}"` prints; then remove
the clone (see [docs/RELEASING.md](docs/RELEASING.md), section 2 for installation, section 3 for the
tag signature).
For deliberate testing of unreleased code only, add the repository as a Composer VCS repository and
pin an explicitly reviewed commit:

```sh
composer config repositories.galbridge vcs https://github.com/takenek/galbridge
composer require "takenek/galbridge:dev-main#<commit-sha>"
```

The installer (roundcube/plugin-installer) checks the Roundcube version against `min-version` /
`max-version`, asks whether to enable the plugin (then skip 2.4) and copies `config.inc.php.dist` to
`plugins/galbridge/config.inc.php` unless that file or `config/galbridge.inc.php` already exists –
with `config/galbridge.inc.php` in place it creates nothing in the plugin directory. GAL Bridge has
no database tables, so nothing is initialised. **Keep the configuration in
`config/galbridge.inc.php` for a Composer installation:** at **every** `composer update` the
installer copies the whole plugin directory to `plugins/galbridge-<20 digits>`, replaces the
package and then re-creates `plugins/galbridge/config.inc.php` (when there is one) from that copy
as a **new file** –
owned by the user running Composer, with that user's umask (typically readable by every local
account; under a strict umask unreadable for the PHP user) – and removes the copy only when the
update succeeds; a failed update leaves the copy, configuration and password included, next to the
plugin directory. `diag` fails for such a copy and warns for a configuration in the plugin directory
of a Composer installation; `--prefer-dist` installs the release archive (a source install would
put a git clone with `tests/` into `plugins/galbridge`).

**The plugin code belongs to root** – whichever way it is installed. Composer writes the package with
the owner and umask of the account that runs it; files of the web server user are files a compromised
web application can change, and both `grants` and `--allow-root` refuse to run from them (section
2.2; `diag` names them on its "plugin code" line). Run Composer as root, or follow every `composer
require` / `composer update` with `chown -R root plugins/galbridge && chmod -R go-w
plugins/galbridge` (the web server needs read access only). It changes the owner only: a
configuration kept in `plugins/galbridge/config.inc.php` keeps its group – the PHP group, e.g.
`www-data` – and mode 0640 (section 2.3); `grants` and `--allow-root` check the owner and the write
bits, never the group.

### 2.2 Create the dedicated read-only database role

GAL Bridge reads the `vmail` database with its **own** role. Do not reuse `vmail` (it can read every
column, password hashes included), `vmailadmin`, `roundcube` (it can UPDATE `vmail.mailbox`),
`sogo`, `iredadmin`, `iredapd`, `amavisd`, `fail2ban`, `root` or `postgres`. The plugin refuses
these role names (`galbridge_db_refused_users`), the user of Roundcube's `db_dsnw` and the user of
the password plugin's `password_db_dsn`. It also probes what the role can reach, in the catalogs the
role itself can read, and refuses (log `role_too_broad`) a role that can do more than read the
directory: one that can read a secret column (`password`, `recovery_email`, `allow_nets`) of **any**
table or the `forwardings` **table**, is a member of another role, has a role attribute (PostgreSQL
`SUPERUSER`, `CREATEROLE`, `CREATEDB`, `REPLICATION`, `BYPASSRLS`), owns the database, holds a
global privilege other than `USAGE` (MariaDB/MySQL) or a privilege outside the directory database
(PostgreSQL: also outside its schema – a relation or sequence of another schema, `CREATE` on
another schema, and any other privilege granted to the role in the database, e.g. on another
schema, a function or a foreign server; its `USAGE` on its own schema is the one expected; and a
privilege on a tablespace or – PostgreSQL 15+ – a configuration parameter), or may
change anything there – and a view
`galbridge_forwardings` that holds a row of another kind than the three of the grant set. `diag`
names each reason. One exception on MariaDB/MySQL: a **server-wide test database** – `test` or a
name starting with `test_`, which `mariadb-install-db` (RHEL and its rebuilds, FreeBSD, the official
tarballs; not Debian/Ubuntu or the Docker images) opens to **every** account – is no refusal when
the role holds no grant of its own there. The books keep working, the log has `server_test_database`
and `diag` fails ("server-wide test database") until it is removed: `mariadb-secure-installation`
("Remove test database and access to it"), or as the database administrator
`DROP DATABASE test; DELETE FROM mysql.db WHERE Db IN ('test', 'test\\_%'); FLUSH PRIVILEGES;`.
Any other database open to every account, and a test database the role holds a privilege on, stay
refusals.

**Generate the statements as root** (recommended; nothing is written to disk by the tool). The
database administrator runs what `grants` prints, so it must come from code that no other account
can change, made by a process that no other account can watch. Check the plugin files first with the
system's tools – not with the plugin's own code, which a changed tree could make report itself as
clean – then generate the statements as root:

```sh
cd /opt/www/roundcubemail
find -L plugins/galbridge \( ! -user root -o -perm /022 \) -print   # must print nothing (GNU find)
namei -l plugins/galbridge/bin/galbridge.sh    # every line: owner root, no "w" in the group or other columns (/tmp-style "t" excepted)
umask 077     # the SQL file must be readable by root only
plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql > /root/galbridge-grants.sql   # MariaDB/MySQL edition
plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=pgsql > /root/galbridge-grants.sql   # PostgreSQL edition
```

The SQL goes into the file; the DSN line with the password appears on the terminal (standard
error) and never in the file. Copy it into the configuration (2.3) – or keep it in a file only root
can read, `2> /root/galbridge-dsn.txt` under the same `umask 077`, and `shred -u` that file after use.
Keep both files outside the plugin directory and outside any git checkout of the plugin (above:
`/root`): some web servers serve the plugin directory (2.6), and a file in a checkout is one `git add`
away from a commit (the repository's `.gitignore` covers `/*.sql` and copies of the configuration as
a safety net).
`--allow-root` refuses the run when an account other than root owns, or group or others can write,
anything it loads or prints – the script, `lib/`, `SQL/`, `localization/`, `galbridge.php`, the
plugin's configuration and every directory above them (a symbolic link to nothing as well). `grants`
needs neither Roundcube nor the database: it loads nothing but the plugin's own classes and
templates, with the plugin's `lib/` as include path and the plugin directory as working directory.

Run as any user other than root, `grants` **refuses** to run when that user can read a configuration
file of Roundcube or of the plugin (`config/config.inc.php`, `config/galbridge.inc.php`,
`plugins/galbridge/config.inc.php`, the configuration directories of the environment and the usual
installation places): that is the web server user, and any other process of that user – a
compromised web application – could read or change the output while it is produced (`ptrace`,
`/proc/<pid>/fd`). It also refuses code and templates that an account other than root and itself owns
or that group or others can write, with every directory above them. A non-root run is meant for a
dedicated account without access to the web server's files – a database administrator's copy of the
verified release archive (2.1), a CI job.

| Option | Default | Meaning |
|---|---|---|
| `--host=` | `localhost` (MySQL), `127.0.0.1` (PostgreSQL) | the **database server** written into the printed DSN: a host name or an IP address, an IPv6 address in brackets (`[2001:db8::5]`, without an embedded IPv4 part); patterns such as `%` are refused. MariaDB/MySQL `localhost` is the server's unix socket as PHP's `pdo_mysql.default_socket` names it (section 3.9) |
| `--port=` | the driver's default (3306 / 5432) | the **port** of the database server, written into the printed DSN as `host:port` (`[2001:db8::5]:5432`): digits only, 1–65535. MariaDB/MySQL: refused with the host `localhost` (also the default of `--host`) – PHP connects to `localhost` through the unix socket and ignores the port; use `--host=127.0.0.1` (or the server's address) with `--port` |
| `--account-host=` | `localhost` | **MariaDB/MySQL only** (refused with `--driver=pgsql`: PostgreSQL limits the client addresses of a role in `pg_hba.conf`, below): the host part of the account, i.e. where Roundcube connects **from**. Use the same host part as your existing Roundcube account (`SHOW GRANTS FOR 'roundcube'@'localhost';`); patterns such as `10.0.0.%` are accepted. Any value other than `localhost`, `127.0.0.1` or `::1` adds `ALTER USER ... REQUIRE SSL` |
| `--db=` | `vmail` | the vmail database |
| `--role=` | `galbridge` | the role name (lower-case letters, digits and `_`, starting with a letter). Names of roles that belong to iRedMail, Roundcube or the database server (the `galbridge_db_refused_users` list, `mysql`, `mariadb`, `public`, `pg_*`, ...) are refused: the statements would change that role |
| `--connection-limit=` | `25` | the connection limit of the account, for **both** engines (1–10000): PostgreSQL `CONNECTION LIMIT`, MariaDB/MySQL `MAX_USER_CONNECTIONS` |
| `--reset-password` | – | give the role a **new password**, also when it already exists (see below) |
| `--grants-only` | – | re-apply attributes, limits, view and grants to the **existing** role; the password is not changed and no DSN is printed |
| `--clear-text-password` | – | MariaDB/MySQL driver only, for **MySQL 8.4 or later**: write the password itself into the statement (see "No password in the statements" below) |

Every option is given once (a repeated option is a usage error, as is a misspelt one or one without
`=`); usage errors and refusals go to standard error and end with exit code 1, and so does every
message of PHP itself – never into the SQL file.

The output is the grant set of `SQL/grants.mysql.sql` or `SQL/grants.postgres.sql` for a fresh
random password (40 hexadecimal characters). Role, account host, database and connection limit are
applied to the SQL statements; the comments, including the attribution header, stay as shipped.
Before you uncomment the commented-out personal-data grant, check that it names your role, account
host and database. Keep the output private.

**Review the output before you apply it** – it is a script for the database administrator:

1. the second line names the role and the form you asked for (creates the role / NEW PASSWORD /
   re-applies ... to the EXISTING role);
2. `grep -n '\\' /root/galbridge-grants.sql` prints nothing: the output holds no backslash, so no
   psql meta-command and no short-form mysql command (`grants` refuses to print one, and any line
   that starts with a long-form command of the mysql client such as `system` or `source`);
3. the statements are those of the template apart from role, account host, database, connection
   limit, password verifier and the `[role]` block:
   `diff <(grep -v -e '^--' -e '^$' plugins/galbridge/SQL/grants.mysql.sql) <(grep -v -e '^--' -e '^$' /root/galbridge-grants.sql)`
   (`grants.postgres.sql` for PostgreSQL) shows nothing else – compare with the template of the
   verified release archive when the plugin files did not pass the check above.

Apply it as the database administrator, with the client commands switched off:

```sh
mysql --binary-mode < /root/galbridge-grants.sql                                          # MariaDB / MySQL, as root (never with --force)
runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql   # PostgreSQL
```

`--binary-mode` turns off every command of the mysql client but `charset` and `delimiter` for a
piped script (both MariaDB's and Oracle's client have it); `-X` keeps `~/.psqlrc` out, and root's
shell reads the file, so the postgres account needs no access to it. Then delete the file
(`shred -u /root/galbridge-grants.sql`).

**Three forms of the output – what happens to an existing role.** The second line of every output
says which one it is:

* *default*: the statements **create** the role. When a role of that name already exists they stop
  (PostgreSQL: the whole transaction is rolled back; MariaDB/MySQL: `ERROR 1396` at the first
  statement) and change nothing – a mistyped `--role` cannot take over the role of another
  component, and re-running an old output cannot change a password behind your back;
* `--reset-password`: creates the role if necessary and sets a **new password**: put the printed
  DSN line into the configuration in the same step, the old password stops working at once;
* `--grants-only`: attributes (PostgreSQL: `NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION
  NOBYPASSRLS NOINHERIT`, also in the `--reset-password` form), limits, view and grants are applied
  again to the existing role, its password stays. Use it after an upgrade of GAL Bridge (section 5),
  to change the connection limit, or after you uncommented the personal-data grant.

Grants are additive: no form removes a column grant or a membership. To remove a column grant you no
longer need (`diag` lists such columns as "granted but unused"), revoke it by hand, e.g.
`REVOKE SELECT (telephone, mobile, birthday) ON vmail.mailbox FROM 'galbridge'@'localhost';`
(PostgreSQL: `REVOKE SELECT (telephone, mobile, birthday) ON mailbox FROM galbridge;`). `diag` also
names every column the role can see beyond the grant set ("columns beyond the grant set", WARN) and
fails for everything the plugin refuses (write privileges on any relation, memberships, attributes,
privileges outside, the ownership of the database) and for objects the role owns or may create.

**No password in the statements.** The statements carry a *verifier* of the password, never the
password: a SCRAM-SHA-256 verifier for PostgreSQL, the `mysql_native_password` hash for
MariaDB/MySQL. The clear text exists in the DSN line on standard error only. That matters because
database servers keep account statements: PostgreSQL in its log when `log_statement` is `ddl` or
`all`, MariaDB in its general query log, its binary log (and therefore on replicas and in
point-in-time backups) and, when the statement qualifies, its slow query log – verbatim, with a
password that is written into `IDENTIFIED BY '...'`. The PostgreSQL output additionally switches
statement logging off for its own transaction, and `pg_stat_statements.track_utility` as well (the
module would otherwise keep the text of `CREATE ROLE` / `ALTER ROLE`; without the module the setting
is a placeholder); an audit extension such as pgaudit has settings of its own. *MySQL 8.4 and later*
no longer load `mysql_native_password`: there the default output stops at `CREATE USER` with "plugin
is not loaded" and creates nothing – generate it with `--clear-text-password` instead. That SQL
output holds the password itself (its header says so): keep the file private and delete it after
use. MySQL rewrites passwords in its own logs; on MariaDB that output stops before the statement
with the password – at a MariaDB-only `SIGNAL`, and, should a client drop that comment, at the next
statement, which only MySQL 8 can run (`SELECT @@SESSION.information_schema_stats_expiry`).

**Never edit the templates inside the plugin directory.** iRedMail's nginx serves the files under
`plugins/` through its `static.php` alias (section 2.6): a credential written into
`plugins/galbridge/SQL/grants.mysql.sql` would be downloadable. To use a template by hand, take it
from the verified release archive (2.1), copy it where only root can read it, e.g.
`install -m 0600 grants.postgres.sql /root/galbridge-grants.sql`, and follow the steps in its header
(PostgreSQL: replace `CHANGE_ME_RANDOM_PASSWORD`; MariaDB/MySQL: compute the verifier of your
password with the two `openssl` commands given there and replace `CHANGE_ME_PASSWORD_VERIFIER`; and
`vmail.` if your database has another name). Review and apply it as above.

What the role gets:

* **column-level `SELECT` only**, on the columns GAL Bridge reads from `mailbox`, `domain`,
  `alias`, `maillists`, `moderators`, `maillist_owners` and the view `galbridge_forwardings`. Never
  `password`, `recovery_email`, `allow_nets`, `settings`, quota, storage or transport columns or
  admin flags; `SELECT *` is impossible by design. The grants keep secrets unreadable; which fields
  users see is decided by `galbridge_fields` (section 3.4). Of the optional columns, `department`,
  `first_name` and `last_name` (default fields) are granted; the personal-data columns `telephone`,
  `mobile` and `birthday` are a **commented-out** statement – uncomment it only when you enable the
  fields `phone`, `mobile` or `birthday`. A configured field whose column is not granted simply
  disappears (log `field_unavailable`, `diag` WARN); a granted column of a field that is not
  configured is a `diag` INFO line. MariaDB/MySQL: also `SHOW VIEW` on the view, so that `diag` can
  compare its definition with the template – the server shows the definition, nothing more (`EXPLAIN`
  of a query on the view still needs a privilege on the table);
* **the view `galbridge_forwardings` instead of the table `forwardings`.** That table also holds the
  private forwarding targets of every mailbox (often external addresses). The grant set creates one
  view with only the three kinds of rows the plugin needs – per-user alias addresses, members of
  alias accounts and mailing-list rows – and grants the role the view, **not** the table. The server
  builds the rows of the view before it applies any condition of a query: on MariaDB/MySQL the view
  is `ALGORITHM = TEMPTABLE` (never merged into a query) with a `LIMIT 18446744073709551615` fence (no
  condition of a query is pushed into it), on PostgreSQL a `security_barrier` view with an
  `OFFSET 0` fence (no condition is moved below it, not even one PostgreSQL treats as leakproof). So
  even a query sent with the role's own credentials never evaluates a condition on a private
  forwarding row; what such a query can still observe is the time it takes to build the view, which
  depends on the size of the table only. It is the only object GAL Bridge adds to `vmail`; it stores
  nothing (MariaDB/MySQL: `SQL SECURITY DEFINER`; `CREATE OR REPLACE` also replaces the definer, the
  algorithm and the security type of a view of that name). On PostgreSQL every run of the grant set
  **drops it and creates it afresh**, owned by the administrator who runs it: no grant, owner, rule or
  comment of a view that existed before survives – also not the grant of a second GAL Bridge role on
  the same database, so apply the grant set of each role again after another one. A **table** of
  that name, or another object that depends on the view, makes `DROP VIEW` fail, and the transaction
  changes nothing. `diag` checks the view: its rows (only the three kinds – the plugin refuses
  others), and the object – MariaDB/MySQL: not merged, `SQL SECURITY DEFINER`, the definition with
  `ALGORITHM=TEMPTABLE`, the `LIMIT` fence and the table `forwardings` alone; PostgreSQL: a view owned
  by a superuser or by the owner of `forwardings`, `security_barrier`, not `security_invoker`, fenced
  with `OFFSET 0`, using no object but the table. The plugin logs `view_not_materialised` for a view
  that is not fenced (a 1.0.1 view, until `grants --grants-only`) and keeps working. Before an
  iRedMail upgrade that alters the `forwardings` table, drop the view and run `grants --grants-only`
  afterwards (the templates say how);
* **limits of the account on both engines**: at most 25 simultaneous connections
  (`--connection-limit`), a hard limit the server enforces, and a statement time of 5 seconds as
  the role's DEFAULT (PostgreSQL `statement_timeout`, MariaDB `MAX_STATEMENT_TIME`; MySQL has no
  per-account statement time). A default is no limit: a session may change it, and so it may change
  the role defaults `default_transaction_read_only` and `idle_in_transaction_session_timeout`
  (PostgreSQL) – the role is read-only because it holds nothing but `SELECT`. The plugin sets a
  read-only session and its own statement timeout on every connection (`galbridge_db_timeout`,
  1–30 s, default 3), which is what bounds its statements on every engine; the role default applies
  to any other session opened with these credentials until it changes it. A server that refuses the
  plugin's statement timeout or read-only mode disables the books (log `session_limit_refused`,
  `diag` FAIL on its `connect` line): the plugin never reads the directory without its statement
  bound;
* **PostgreSQL**: a new role (`NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
  NOINHERIT`, asserted again by `--grants-only` and `--reset-password`) with the role defaults
  `default_transaction_read_only = on`, `statement_timeout = '5s'`,
  `idle_in_transaction_session_timeout = '10s'` and – because Roundcube sends statements with the
  search terms inlined – `log_statement = 'none'`, `log_min_duration_statement = -1` and
  `log_min_error_statement = 'panic'`, and, where the server knows them, `log_min_duration_sample
  = -1` (PostgreSQL 13+) and `log_transaction_sample_rate = 0` (12+), so the statements of this
  role stay out of the server log – also out of a logged sample – whatever the server-wide settings
  are. A new role is required: column grants cannot narrow an existing table-level grant;
* **PostgreSQL: no object of its own.** The grant set takes `CREATE` on schema `public` away from
  `PUBLIC` for the **whole** `vmail` database – the default of PostgreSQL 15 and later; a database
  created by PostgreSQL 14 or older keeps the old grant after an upgrade, and there every role,
  this one included, could create a function or an operator that a statement of GAL Bridge would
  pick up. A role that owns tables or functions in `public` keeps `CREATE` there, granted to it by
  name; to give it back to another role, `GRANT CREATE ON SCHEMA public TO <that role>` (never to
  `PUBLIC`). It also revokes explicit `CREATE` and `TEMPORARY` on the database from the role;
  temporary tables stay allowed through `PUBLIC` (they belong to one session and vanish with it).
  `diag` fails while the role may create objects (`schema public`: its own schema, schema `public`,
  every other schema and the database) or owns any (`owned objects`); the plugin logs
  `role_can_create` and keeps working until the grant set is applied again – `CREATE` on another
  schema (or on schema `public` granted to the role by name, when its own schema is another one) is
  a privilege outside, which the plugin refuses.

Size the connection limit to the PHP-FPM pool of Roundcube (`pm.max_children`, e.g.
`--connection-limit=100` for a pool of 100 children): a request opens at most one directory
connection, and only when it actually reads the directory. A request beyond the limit gets "The
directory is temporarily unavailable." for that request only (log `db_busy`; the next request tries
again, section 3.9). `diag` shows the limit of the account on both engines and warns when it is
unlimited. Keep the per-client concurrency limit of the reverse proxy well below it
([SECURITY.md](SECURITY.md#hardening-checklist-for-administrators)), so that one client cannot hold
all connections of the role.

**Roundcube and the vmail database on different hosts.**

* MariaDB/MySQL: `grants --driver=mysql --host=<database server> --account-host=<address of the
  Roundcube host>`; the output adds `REQUIRE SSL` for the account, and the server needs TLS
  (`ssl_cert` / `ssl_key`). `diag` warns about an account whose host part admits other hosts
  (`%`, a pattern, a name) without `REQUIRE SSL` and without `require_secure_transport` ("account
  TLS"); it reads the account statement and never prints it (it holds the password hash).
* PostgreSQL: `grants --driver=pgsql --host=<database server>`; the TLS rule lives in
  `pg_hba.conf` (below), which the role cannot read.
* In both cases add certificate verification to the printed DSN (section 3.9); the DSN host name
  must match the server certificate.

**PostgreSQL: keep the role to the vmail database** in `pg_hba.conf`. iRedMail's `pg_hba.conf` ends
with the catch-all line `host all all 0.0.0.0/0 md5`, which accepts a password login from every
address `listen_addresses` lets in, for **every** database – `PUBLIC` holds `CONNECT` on the others
(`roundcubemail`, `amavisd`, `iredadmin`, ...), where on PostgreSQL 14 and older it may create objects
as well. PostgreSQL uses the **first** matching line: a line appended after the catch-all never
applies. Insert these lines **before** it, then reload PostgreSQL (`diag` lists the other databases
the role may connect to as INFO "other databases"):

```
host       vmail  galbridge  127.0.0.1/32                         scram-sha-256   # Roundcube on this host
host       all    galbridge  0.0.0.0/0                            reject          # no other database, no other address
host       all    galbridge  ::/0                                 reject
```

For a remote Roundcube host replace the first line by these two (the server must also listen on
that interface, `listen_addresses`, and have `ssl = on`):

```
hostssl    vmail  galbridge  <address of the Roundcube host>/32  scram-sha-256
hostnossl  vmail  galbridge  0.0.0.0/0                           reject
```

### 2.3 Configure the plugin

```sh
cd /opt/www/roundcubemail
# after a Composer install that already created config/galbridge.inc.php (section 2.1): fill that file in instead
install -o root -g www-data -m 0640 plugins/galbridge/config.inc.php.dist config/galbridge.inc.php
```

The file holds the database password and the directory policy: owner root, group = the PHP-FPM
user, mode `0640`. `diag` fails when others can access it, and warns when its group can write it,
when it is not owned by root – also when it belongs to the PHP user itself, because the web server
process could then rewrite the directory policy – or when its directory is writable by group or
others (without the sticky bit).

**Use `config/galbridge.inc.php`.** Roundcube reads a plugin's configuration either from
`plugins/galbridge/config.inc.php` or – **only** when the plugin directory contains no
`config.inc.php` – from `config/galbridge.inc.php`. The second lies outside the plugin directory:
outside what a web server rule has to deny (section 2.6), outside what Composer re-creates
(section 2.1) and outside what you replace when you install a new release. A configuration in
`plugins/galbridge/config.inc.php` keeps working; `diag` without `--url` then warns, and `diag
--url` asks the web server for that file (section 2.6). To move it: `install -o root -g www-data -m
0640 plugins/galbridge/config.inc.php config/galbridge.inc.php && rm
plugins/galbridge/config.inc.php`. **Keep exactly one of the two:** when both exist, GAL Bridge
disables every book (log `config_invalid` naming `galbridge.inc.php`, `diag` FAIL) rather than run
on the file you did not edit; so it does for a file that exists but cannot be loaded – not readable
by the PHP user, or without a `$config` array (Roundcube itself only logs error 527 and goes on
with the built-in defaults). Whether your web server hands either file out is not something this
documentation can promise for every template: check it with `diag --url` (section 2.6). Never keep
the DSN in any other file inside `plugins/galbridge/` (a backup such as `config.old.php` would be
downloadable, section 2.6).

Minimum setting:

```php
<?php
// MariaDB/MySQL edition
$config['galbridge_db_dsn'] = 'mysql://galbridge:PASSWORD@localhost/vmail';
// PostgreSQL edition
// $config['galbridge_db_dsn'] = 'pgsql://galbridge:PASSWORD@127.0.0.1/vmail';
```

With iRedMail's defaults nothing else is required: every user gets the domain directory of their
own domain. Every option is documented in `config.inc.php.dist`; section 3 gives an overview. All
`galbridge_*` options are administrator-only: a stored user preference of the same name is ignored
(and removed when the user saves settings). A `galbridge_*` option that this version does not know –
usually a typo, which would leave the option you meant at its default – disables every book (log
`config_invalid`, `diag` FAIL with the closest valid name).

**Keep the directory policy in this one file.** Set every `galbridge_*` option there with plain
assignments (`include`/`require` of another file is fine, `include_once`/`require_once` is not: a
second reading of the configuration in the same PHP process – which the plugin makes for an option
that a stored preference shadows – does not see what such a file sets; `diag` warns and names the
options a second reading loses). With Roundcube's `include_host_config` the configuration file of
the requested host name is merged as well, and the `Host` header is chosen by the client: a
`galbridge_*` option that such a per-host file sets or changes – anything but the two book names –
disables **every** book for requests with that host name (log `config_invalid` naming the option),
because otherwise a user could pick the wider policy by sending another host name. `diag` merges
every file a request can select and names the options it sets (section 2.7).

**Write switches as booleans.** A switch is `true` or `false` (the integers `1` and `0` are
accepted); the two `searchonly` options also take `'auto'`. A string such as `'false'`, `'no'` or
`'off'` – which PHP would read as *true* – is not interpreted at all: it is reported (`diag` FAIL,
log `config_invalid`) and **every book is disabled** until it is corrected. The same applies to
environment overrides (`ROUNDCUBE_GALBRIDGE_...`, section 3): there the value must be exactly `1`,
`0`, `true` or `false`.

### 2.4 Enable the plugin

In `config/config.inc.php` (iRedMail's default list is `['managesieve', 'password', 'zipdownload']`):

```php
$config['plugins'] = [ /* ... */ 'galbridge'];
```

Remove `sql_global_addressbooks` from the list if it is still there
([docs/MIGRATION.md](docs/MIGRATION.md)). GAL Bridge adds its books to `autocomplete_addressbooks`
at runtime (only for compose, Redirect-dialog and autocomplete requests); you do not need to list
`galbridge_domain` or `galbridge_global` there yourself.

**iRedMail installs `config/config.inc.php` as the PHP user** (`www-data:www-data`, mode `0600`).
That file can set every option – `galbridge_*` options, `des_key`, the plugin list – so the web
application could rewrite the directory policy; `diag` warns (`core config file ... owned by uid
33`). Roundcube only reads it. Give it to root and let the PHP group read it:

```sh
chown root:www-data config/config.inc.php && chmod 0640 config/config.inc.php
```

### 2.5 Recommended Roundcube settings

```php
$config['addressbook_sort_col'] = 'name';
```

Roundcube's default is `'surname'`, iRedMail does not change it, and open-source iRedAdmin fills only
`mailbox.name`. GAL Bridge sorts its own lists with fallbacks (surname, then name, then address), but
Roundcube re-sorts every search result in PHP by `addressbook_sort_col` (address-book search,
compose contact list and compose search); with empty last names the results then come out in
e-mail address order. `diag` warns for any value other than `'name'`. Users can change the sort
column in their preferences unless you add `addressbook_sort_col` to `$config['dont_override']`.
**Never put `galbridge_*` names into `dont_override`**: Roundcube then writes their current value
into the preferences of every user who saves settings – for an option set through an environment
variable the value itself, the DSN with the database password ([SECURITY.md](SECURITY.md); `diag`
fails for `galbridge_db_dsn` there).

Keep `{name}` first in `contactlist_name_template` (Roundcube's default is `'{name}'`) when the
global book is in use. The `(domain)` marker of an entry of another domain is part of its name
(section 3.3). A template that shows other text of an entry before the name – `[{department}] {name}`
– or has no `{name}` at all would put text of the other domain in front of the marker, or show it
without one. GAL Bridge therefore withholds those fields (department, telephone numbers) from
entries of other domains under such a template; `diag` names them (WARN). Fields after the name
(`{name} ({department})`) are not affected.

### 2.6 Web server: keep the plugin directory unreachable

GAL Bridge serves nothing over HTTP; Roundcube reads it from disk. Nothing under
`plugins/galbridge/` has to be reachable from a browser.

* With Roundcube's `public_html` as the document root, plugin files are reachable only through core
  `static.php`, which delivers static asset types only.
* Apache 2.4: the plugin ships a `.htaccess` with `Require all denied`, effective only where the
  directory allows it (`AllowOverride AuthConfig` or `All`); otherwise deny `plugins/galbridge/` in
  the server configuration.
* **iRedMail nginx**: the templates `roundcube.tmpl` (webmail under `/mail/`) and
  `roundcube-subdomain.tmpl` (in `/etc/nginx/templates/` on Linux) contain a location for
  `^/static.php/(.*)` that `alias`es the request straight to the Roundcube directory. That bypasses
  the type filter of core `static.php`; the templates deny `plugins/*/config.inc.php*` and a few
  top-level directories, but not other plugin files. The PHP source of the plugin, the SQL templates
  and any file an administrator leaves in `plugins/galbridge/` can then be downloaded. Add this rule
  to both templates **before** the `static.php` location (nginx uses the first matching
  regular-expression location in the order they appear):

  ```nginx
  location ~ ^/(mail/)?(static\.php/)?plugins/galbridge(-[0-9]+)?/ { deny all; }
  ```

  The optional `-<digits>` also covers the backup copy of the plugin directory that Roundcube's
  plugin installer makes during `composer update` (`plugins/galbridge-<20 digits>`, with the
  configuration and its password in it; section 2.1). That copy contains the `.htaccess` as well,
  so Apache denies it too.

  Reload (`nginx -t && systemctl reload nginx`) and check that the plugin source is no longer
  served (for `roundcube-subdomain.tmpl` leave out the `/mail` prefix). Both requests must answer
  `403` (or `404`); a `200` means the rule is not effective:

  ```sh
  curl -s -o /dev/null -w '%{http_code}\n' https://mail.example.com/mail/static.php/plugins/galbridge/lib/autoload.php
  curl -s -o /dev/null -w '%{http_code}\n' https://mail.example.com/mail/plugins/galbridge/lib/autoload.php
  ```

  With the self-signed certificate that iRedMail installs (`/etc/ssl/certs/iRedMail.crt`, issued
  for the server's host name, until a trusted certificate replaces it) `curl` prints `000`: add
  `--cacert /etc/ssl/certs/iRedMail.crt` and use that host name in the address.

* `diag --url=` makes the same check from the server side for the usual paths (section 2.7):

  ```sh
  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/
  # iRedMail's self-signed certificate: name it, the certificate is still verified
  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/ --cafile=/etc/ssl/certs/iRedMail.crt
  ```

  `--cafile=` (an absolute path, `https://` addresses only) names the PEM certificate(s) the
  certificate of the web server is verified against instead of PHP's default CAs; verification is
  never switched off. A certificate this PHP does not trust is a `[WARN] web access TLS
  certificate not trusted` line (every request then has "no answer").

  A `[FAIL] web access ... is SERVED` line means that the rule above is missing or not effective.
  It also asks for the **configuration file in use** – `plugins/galbridge/config.inc.php`, or
  `config/galbridge.inc.php` of the Roundcube directory, each also through `static.php` – on lines
  `config over HTTP`: `[FAIL] ... is SERVED as text` means that the database password can be
  downloaded (deny it, then give the role a new password with `grants --reset-password`, section
  2.2); an answer without the text of the file (PHP ran it) is an INFO. Nothing of an answer is
  printed. A configuration file outside the Roundcube directory has no web address of the webmail.
  Without `--url`, `diag` warns while the configuration lies in the plugin directory.
* Keep nothing but the plugin in `plugins/galbridge/`: release archives built with `git archive`
  leave out `tests/` and developer tooling (`.gitattributes`), a git clone does not (section 2.1).
  `diag` lists a clone and files that are not part of a release.

### 2.7 Test the installation

```sh
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/   # self-signed certificate: add --cafile=/etc/ssl/certs/iRedMail.crt (2.6)
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --strict      # automated checks: WARN = exit code 2
```

Run it as the PHP / web server user from the Roundcube directory (it must read the plugin
configuration). If the plugin directory is a symlink, set the Roundcube root explicitly:
`sudo -u www-data env ROUNDCUBE_INSTALL_PATH=/opt/www/roundcubemail plugins/galbridge/bin/galbridge.sh diag`.

**The tool does not run as root.** It loads PHP code from the plugin directory and from the
Roundcube tree (and Roundcube's configuration files are PHP as well): started by root, anybody who
can write one of those files would run code as root. It therefore stops with a message unless
`--allow-root` is given, and with `--allow-root` it still refuses unless **everything it then
loads** is owned by root and writable by nobody else: the plugin, and below the Roundcube directory
all of `program/`, `plugins/` (Roundcube loads every enabled plugin when it starts, and the tool
reads the configuration of the password plugin), `vendor/`, every configuration directory
(`ROUNDCUBE_CONFIG_DIR`, `RCUBE_CONFIG_PATH`) and every directory of PHP's `include_path`, with the
directories they are reached through – on a usual installation, where some of that belongs to the
web server user, the answer is to run the tool as that user. A process of root then hands Roundcube
exactly the canonical paths it checked (a symbolic link on the way is resolved once), keeps only
the absolute `include_path` entries that exist (each checked like `program/`), and works in
Roundcube's `program/` directory, so a relative file name never reaches your working directory.
`diag` reads – and so runs – the per-host files of `include_host_config` to compare their options
(section "Per-host configuration" below); as root it reads only a file that lies in one of the
checked configuration directories and itself belongs to root and is writable by nobody else, and
names any other in a WARN line without loading it (an absolute or `../` name in
`include_host_config` can lead anywhere) – run `diag` as the web server user to check such a file. It
writes only where nobody else can: Roundcube's log lines go to syslog, and PHP's own messages go to
syslog or – with `log_driver = 'file'` – into a `log_dir` that root alone can write. **With
`log_driver = 'file'` and a `log_dir` of the web server user, `--allow-root` is refused** (that user
could put a symbolic link in place of `errors.log`; iRedMail logs to syslog), and so is a Roundcube
database in an SQLite file whose directory another account can write. Not covered by these checks:
PHP's own configuration (`php.ini`, `conf.d`, `auto_prepend_file`, `extension_dir`), which PHP reads
before the tool starts, and the start code of every enabled Roundcube plugin, which runs as root
(its files are checked, its data directories are not). The user is determined with the posix
extension of PHP or, where that is not installed, from `/proc`; when neither tells, the tool treats
the process like root. Do not pass a foreign environment to it (`sudo -E`,
`ROUNDCUBE_INSTALL_PATH` from an untrusted source).

Options are written `--name=value`. An option without its value (`--role acme`), an unknown or
misspelled option and a stray word are usage errors: nothing is printed or checked with half of
what was asked for.

`diag` reports `[ OK ]`, `[INFO]`, `[WARN]` and `[FAIL]` lines in sections. Every check runs on its
own: a check that fails unexpectedly prints `[FAIL] check aborted` with the sanitised reason (a DSN
password masked), and the following checks still run. An error that stops the tool itself – an
exception or a PHP fatal error, also while Roundcube starts (a broken configuration file, a plugin),
or another program part ending the process – ends with `Result: aborted` and exit code 2. A check
that could not run (the Roundcube database, the plugin code) is a WARN, never silence or an INFO.

* **Versions**: Roundcube – OK for the newest 1.7.x release this version knows (and for later ones),
  WARN for an older patch release (update the **core**: the later releases fix security problems of
  Roundcube) and for a core that reports `1.7-git`, FAIL for any other branch; the PHP branch and
  its security support; the iRedMail release; an INFO `process` line saying that the PHP version,
  extensions, `php.ini` settings and `ROUNDCUBE_*` / `PG*` variables `diag` reports are those of the
  **command line** PHP it runs in – the web server's PHP (a PHP-FPM pool) has its own: compare
  `php-fpm -m` and the pool's `env[...]` / `clear_env`.
* **Configuration**: whether the plugin is enabled; every configuration file Roundcube loads for the
  plugin – `plugins/galbridge/config.inc.php` when it exists, otherwise `galbridge.inc.php` from
  **every** configuration directory (`RCUBE_CONFIG_PATH` included) – with location and mode (FAIL
  when others can access it), WARN when its group can write it or when it is not owned by root
  (including the PHP user running `diag`); a `galbridge.inc.php` that Roundcube does not read
  because `plugins/galbridge/config.inc.php` exists is listed as "NOT read" with the same checks
  (and FAIL under its own name: every book is disabled), and a file that cannot be loaded (not
  readable, no `$config` array) is a FAIL; for a Composer installation a WARN when the configuration
  is `plugins/galbridge/config.inc.php`, which the installer re-creates at every update (section
  2.1); the core files `defaults.inc.php` and `config.inc.php`
  (they can set `galbridge_*` options, `des_key` and the plugin list as well: WARN when not owned by
  root or writable by group/others); for each of their directories a WARN when it is writable by
  group or others, when it is not owned by root, and for the first directory above it that is not
  root's alone (whoever can write a directory can replace what is in it);
  every configuration problem (FAIL for the fail-closed cases such as a switch that is not a
  boolean, a visibility column that is not a string, a malformed deny-list, a malformed
  `galbridge_db_refused_users`, overlapping organizations, and a `galbridge_*` option or
  `ROUNDCUBE_GALBRIDGE_*` variable this version does not know – with the closest valid name; WARN
  for dropped allow-list entries and for a number that is not a whole number or lies outside its
  range; a rejected list entry is named by its position, never shown); FAIL when `dont_override`
  lists `galbridge_db_dsn` and WARN for other `galbridge_*` names there, and the Roundcube users
  whose stored preferences contain `galbridge_db_dsn` (FAIL) or other `galbridge_*` keys (WARN) are
  counted (WARN "not checked" when Roundcube's database cannot be read); a WARN while
  `galbridge_db_allow_plaintext` or `galbridge_db_allow_verify_ca` is on – also unused – and while
  `galbridge_global_allow_wildcard` is on without `'*'` data domains, when the password plugin is
  enabled with its SQL driver but no `password_db_dsn` is found, while `galbridge_debug` is on (also
  through `ROUNDCUBE_GALBRIDGE_DEBUG`: the log then receives driver and exception texts with personal
  data, section 3.10), and for a `des_key` that is
  Roundcube's published default, empty, not a string or shorter than 24 characters; INFO lines with
  the refused database roles (built-in and added) and the size of the hidden and excluded lists and
  of the organizations (`policy lists`); a WARN for every `ROUNDCUBE_GALBRIDGE_*`
  environment variable that overrides an option (the name only, never the value), and an INFO line
  when there is none – both describe the environment of the shell that runs `diag`: a PHP-FPM pool
  has its own (`env[...]` in the pool configuration, `clear_env`), and the web process logs the
  names of the variables it applies once per session (log `env_override`, section 7); an INFO line
  for every other `ROUNDCUBE_*` variable of this process that overrides a core option (names only:
  `diag` judges `imap_host`, `des_key` and the plugin list through them); a WARN `configuration
  read again` naming the options (never their values) on which a second reading of the
  configuration files disagrees with Roundcube's own object – set in a file pulled in with
  `include_once`/`require_once`, by a variable or by another plugin; GAL Bridge reads the core
  options from Roundcube's object, but derives no directory server from an `imap_host` that differs
  and reads the second reading for an option that a stored preference shadows –, a WARN
  `include_once` naming a configuration file that uses `include_once`/`require_once` (as root
  `galbridge.sh` reads the configuration once before Roundcube starts, so then `diag` itself misses
  what such a file sets), and a WARN `environment file` for every `config-<env>.inc.php` (also
  `defaults-`, `main-`, `db-`, `galbridge-`, and `config-<env>.inc.php` in the plugin directory) –
  Roundcube uses such a file **instead of** the plain one when it runs with that environment name,
  `diag` runs without one; a WARN while `'*'`
  global data domains are in effect (and when `'*'` is set without
  `galbridge_global_allow_wildcard`, which leaves the global book empty); the enabled books (FAIL
  "none" with the reason while a configuration error disables every book); the
  **effective value of every switch** – three `switches (...)` lines, each switch under the name of
  its option without the `galbridge_` prefix, `on`, `off` or `auto`; one whose value is ambiguous is
  shown with the restrictive value it is read as (`off`; `hide_expired`, `mark_foreign_domains` and
  the `searchonly` options `on`) and marked `(INVALID)` – and of the viewer cache time; a note on the
  order of autocomplete suggestions while the global book marks entries of other domains, for the
  Roundcube release in use (section 3.3); leftovers of `sql_global_addressbooks`.
* **Installation**: whether the plugin directory holds only the files of a release (WARN for a git
  clone with `tests/` and for files that do not belong there – also inside `bin/`, `lib/`, `SQL/`,
  `localization/` and `docs/`, e.g. a grants output saved next to the templates or an editor's copy
  of a class); FAIL for every `plugins/galbridge-<20 digits>` directory next to it – the backup an
  interrupted `composer update` leaves, with the configuration and the database password in it
  (delete it; section 2.1); whether the PHP user can change the plugin code (WARN): every directory and every
  `*.php`, `*.inc`, `*.sql` and `*.sh` file below `bin/`, `lib/`, `SQL/` and `localization/`,
  `galbridge.php`, the plugin directory, the directories above it and Roundcube's `plugins/`
  directory (where a symbolically linked plugin can be replaced) – a file the PHP user **owns**
  counts as changeable whatever its mode says; run as root (`--allow-root`) the line applies the
  rule of `--allow-root` (owned by root, writable by nobody else) and says so – `grants` and
  `--allow-root` refuse to run from a tree the line names (section 2.2); when the user cannot
  be determined, a WARN says that this was not checked; and, with `--url=`, whether the web server
  serves files of the plugin directory (FAIL when it does) and the configuration file in use
  (`config over HTTP`: FAIL when it is served as text, section 2.6); without `--url` an INFO line
  and the summary say that this was not checked – a WARN while the configuration file lies in the
  plugin directory. The address is checked first: when it does not answer like the Roundcube
  webmail – a wrong path prefix, another virtual host – a WARN says that "not served" means nothing
  there.
* **IMAP host binding**: the directory servers (from `galbridge_imap_hosts` or derived from
  `imap_host` as Roundcube applies it, each as `host`, `host:port` or `unix:///path`) and whether
  they cover the servers Roundcube really
  puts into sessions: the hosts of `imap_host` (FAIL when its only host is not covered; WARN for
  each uncovered entry when it lists several) and the `mail_host` values stored in Roundcube's
  `users` table (WARN with the number of users who would get no directory; WARN "not checked" when
  the Roundcube database cannot be read from the command line). For every server of `imap_host` an
  `IMAP transport` line: OK for a local server or for TLS that verifies the server, FAIL for a server
  on another host reached without it – no `ssl://` or `tls://` scheme (written in lower case:
  Roundcube reads `SSL://` as no TLS), `tls://` on Roundcube older than 1.7.7 or reporting
  `1.7-git`, no `openssl` extension, or `imap_conn_options` that switch certificate verification off
  (WARN instead while `galbridge_imap_allow_plaintext` is set); with an empty or templated
  `imap_host` an INFO line says that users choose the scheme. A WARN when more than one server is
  allowed (every one of them must authenticate against this vmail database), for every entry of
  `galbridge_imap_hosts` without a port (it covers every IMAP port of that host) and when Roundcube's
  `include_host_config` is enabled or `ROUNDCUBE_INCLUDE_HOST_CONFIG` is set (without an explicit
  `galbridge_imap_hosts` the plugin is disabled, FAIL).
* **Per-host configuration** (only with `include_host_config` or `ROUNDCUBE_INCLUDE_HOST_CONFIG`):
  every file a request can select – the files `include_host_config` lists, or, when it is `true`,
  every `*.inc.php` file of the configuration directories (the `Host` header names the file) – is
  merged over the configuration exactly as Roundcube does it, followed by the plugin's own file:
  FAIL for a file that sets or changes a `galbridge_*` option (names only; requests with that host
  name get no books), INFO for one that changes only a book name, and for a per-host `imap_host` its
  coverage (WARN) and its `IMAP transport` line. Run as root (`--allow-root`), a file is merged only
  when it lies in a configuration directory the root check covered and belongs to root alone; any
  other is a WARN that names it and says to run `diag` as the web server user (loading a file runs
  it).
* **Core interplay**: `addressbook_sort_col`; a `contactlist_name_template` that shows text of an
  entry before `{name}` or without it (WARN with the fields that entries of other domains then do
  not carry, section 2.5); source-ID collisions with `ldap_public`, the group-expansion cap, the
  autocomplete minimum; WARN `galbridge_max_export` / `group expansion cap` above 10000 (one request
  reads, maps and holds that many entries – section 3.8); WARN `page size` when `max_pagesize` is
  0 or above 1000, or `addressbook_pagesize` / `pagesize` is above 1000 (the directory serves at
  most 1000 entries per page – section 3.8); WARN `galbridge_global_groups` for `'department'` in
  the global book (the number of its groups depends on the data of every tenant; above
  `galbridge_max_groups` it shows none – section 3.5).
* **vmail database**: the DSN policy (refused role, TLS rule, DSN syntax) and the `DSN` line with
  the endpoint the driver really uses (for MariaDB/MySQL `localhost` the socket of
  `pdo_mysql.default_socket`, e.g. `localhost (socket /var/run/mysqld/mysqld.sock)`), a WARN
  `socket directory` when the directory of that socket is writable by every local account; the PDO
  and `intl` extensions (WARN when `pdo_mysql` is not built on `mysqlnd` and the database is
  remote); the `read timeout` of the plugin's MariaDB/MySQL connection (WARN where none can be set:
  `pdo_mysql` without `mysqlnd`, `ini_set` disabled; INFO for PostgreSQL, which has none after
  connecting – section 3.9); the connection (on failure a FAIL with the reason: the driver's text,
  e.g. `Access denied for user ...`, never the password, an error the driver raised, or the
  statement timeout / read-only mode the server refused); the password-column tripwire – OK only
  when the server **refuses** `SELECT password` for lack of privilege (any other error is a FAIL with its SQLSTATE: it proves nothing); the
  **write privileges**, read from the database catalogs (PostgreSQL `has_table_privilege` /
  `has_any_column_privilege` / `has_sequence_privilege` on **every** relation and sequence of the
  schema, `PUBLIC` included; MariaDB/MySQL the `information_schema` user, schema, table and column
  privileges of the account) and, on MariaDB/MySQL, also asked from the server with statements that
  can change nothing (so a privilege that reaches the account through a database role or `PUBLIC` is
  found as well): any privilege to modify vmail is a FAIL, and the line says what was checked; what
  the role holds beyond the directory – each a FAIL, because the plugin refuses such a role
  (section 2.2): `role attributes` (PostgreSQL), `database owner` (PostgreSQL), `global privileges`
  (MariaDB/MySQL, other than `USAGE`), `role memberships` (a NOINHERIT role reaches the privileges of
  a role it is a member of with `SET ROLE`; WARN "not checked" on MySQL before 8.0.19, which cannot
  tell), `privileges outside` the vmail database (PostgreSQL: also outside its schema); `owned
  objects` (PostgreSQL, FAIL); `server-wide test database` (MariaDB/MySQL, FAIL: `test` / `test_*`
  open to every account – the plugin works and logs `server_test_database`, section 2.2); the other databases a PostgreSQL role may connect to (INFO: limit it
  in `pg_hba.conf`); `account TLS` – a MariaDB/MySQL account whose host part admits other hosts
  without `REQUIRE SSL` and without `require_secure_transport` is a WARN (the account statement is
  read, never printed), PostgreSQL an INFO that `pg_hba.conf` decides; the **connection limit of the
  account on both engines** (WARN when unlimited); whether the server log can record the statements of the role
  with their search terms (PostgreSQL: the role's logging settings, and a separate WARN while
  `pgaudit.log` or `auto_explain.log_min_duration` would log them – settings of those extensions,
  which the grant set does not change; MariaDB/MySQL: the general query log); the `statement time (this session)` – the plugin's own session as the server reports it
  after the plugin set it (PostgreSQL `statement_timeout`, MariaDB `max_statement_time`, MySQL
  `max_execution_time`), FAIL when it is not `galbridge_db_timeout` (e.g. a connection pooler that
  resets session settings); on PostgreSQL also `CREATE` in schema `public` or on the database (FAIL:
  the grant set revokes it) and the database ctype (`pg_database.datctype`, WARN for `C`/`POSIX`);
  the column probe for the effective configuration (a secret column visible in **any** relation of
  the database, the `forwardings` table readable and required columns missing are FAIL, columns of
  configured optional fields, alias accounts and lists missing are WARN, granted columns of fields
  that are not configured are INFO, any other column the role can see – one the grant set never
  gives – is a WARN `columns beyond the grant set`; the visibility column in use, or "not used");
  the view `galbridge_forwardings`: its rows (only alias, alias-member and list rows – FAIL
  otherwise, the plugin refuses it) and the object (FAIL for a view that is not fenced, not owned by
  the administrator, not a security barrier on PostgreSQL, merged or not `SQL SECURITY DEFINER` on
  MariaDB/MySQL, or – read with `SHOW CREATE VIEW` – without `ALGORITHM=TEMPTABLE`, the `LIMIT` fence
  or with another table; WARN when the definition is not visible to the role, i.e. before the grant
  set of this release is applied); and data sanity: hosted domains, deliverable mailboxes,
  **`entries not offered`** – the persons, alias accounts, mailing lists, per-user aliases and
  hosted domains whose address or domain does not follow the address rule of the directory
  (section 3.4), which no book lists, counts, finds or groups: WARN with the count per kind and up
  to 10 examples per kind on INFO lines, each written as a JSON string (a control or non-ASCII
  character as `\uXXXX`) with the `row=` tag the log gives such a row; on MariaDB/MySQL an INFO
  `addresses with capitals` (offered there in lower case, not on PostgreSQL) and a WARN `collation`
  for a Turkish or Azerbaijani collation of an address column (its `LOWER()` maps `I` to a dotless
  `i`); a FAIL `server version` on a MariaDB older than 10.2 or a MySQL older than 8.0 while
  `galbridge_user_aliases` is on (no window functions); mailboxes without a name; the indexes on
  `mailbox(domain)` and `forwardings(forwarding)`.
* **User** (with `--user=`; the value is validated before anything of it is printed): the home
  domain, the size of the address set used for list policies,
  and for each entitled book its data scope, the **effective** grouping (`none` for search-only
  books, without the `department` field or above `galbridge_max_groups`, otherwise with the number
  of groups), the search-only mode (for `'auto'` also how it resolves against
  `galbridge_searchonly_threshold`), the number of persons, alias accounts and mailing lists with
  the time each count took, and a WARN when a browsable book holds more than `galbridge_max_count`
  entries (list totals are capped and later pages unreachable – consider `'auto'`) or, with
  whole-book export on, more than `galbridge_max_export` entries (the export is refused). `--user`
  does not evaluate the IMAP host binding of a real session.

Excerpt of a run on the PostgreSQL edition:

```
== vmail database
  [ OK ] connect                            pgsql (7 ms)
  [ OK ] password column                    not readable (refused by the server)
  [ OK ] write privileges                   none (every relation and sequence of the schema, PUBLIC included)
  [ OK ] role attributes                    LOGIN only (no SUPERUSER, CREATEROLE, CREATEDB, REPLICATION, BYPASSRLS)
  [ OK ] database owner                     not the owner of the database
  [ OK ] owned objects                      none
  [ OK ] role memberships                   none
  [ OK ] privileges outside                 none outside the vmail schema and database
  [INFO] other databases                    the role may connect to postgres, roundcubemail (through PUBLIC as a rule): limit it to vmail in pg_hba.conf (README 2.2)
  [INFO] account TLS                        enforced by pg_hba.conf (hostssl / hostnossl ... reject, README 2.2), which the role cannot read
  [ OK ] schema public                      no CREATE privilege (its schema, schema public, the other schemas and the database)
  [ OK ] ctype                              pl_PL.UTF-8
  [ OK ] connection limit                   25 (size it to the PHP-FPM pm.max_children of Roundcube)
  [ OK ] statement logging                  off for this role (its statements contain search terms)
  [ OK ] secret columns                     not visible (in any relation of the database)
  [ OK ] table forwardings                  not readable by the role (it reads the view galbridge_forwardings, checked below)
  [INFO] granted but unused                 mailbox.telephone, mailbox.mobile, mailbox.birthday (not in galbridge_fields; the grant can be dropped)
  [ OK ] view galbridge_forwardings         holds alias, alias-member and list rows only (29 rows: built afresh for every statement that reads it)
  [ OK ] view galbridge_forwardings         a security barrier fenced with OFFSET 0, owned by the administrator: no condition of the role sees a private row

== User jan.kowalski@example.com
  [ OK ] galbridge_domain                   1 domain(s), groups: department (3 groups), search-only: no
  [INFO]   persons                          7 (0 ms)
```

`diag` never prints the database password, the value of an environment override or directory
contents (counts only – except the addresses of the `entries not offered`, which you need in order
to correct them). Everything it prints, and everything that reaches standard error while it
runs (messages of Roundcube, of the database drivers and of PHP), is neutralised: control, bidi,
zero-width and line-separator characters and invalid UTF-8 appear as visible escapes such as `\x1B`
or `\u{202E}`, so no value – a database server's answer, an exception text, a row of Roundcube's
`users` table, a file name – can hide or rewrite lines on your terminal. The last line sums up:
`Result: OK`, `Result: OK with N warning(s)`, `Result: N problem(s) found` or `Result: aborted`;
without `--url` it adds "(not checked: web access – run with --url)". Exit code 0 = no FAIL
(warnings do not change it unless `--strict` is given), 1 = usage error or refused as root, 2 =
problems found (with `--strict` also WARN lines), an unexpected error or a PHP fatal error
(`Result: aborted`).

## 3. Configuration overview

Every option, its default and its effect are documented in `config.inc.php.dist`. A numeric option
is a whole number – an integer, or a string of digits (an environment variable is judged by its
text) – within the range shown: any other value (`2.5`, `1e3`, `true`) is reported and the default
is used, a value outside the range is reported and clamped (log `config_invalid`, `diag` WARN).
List options must be PHP arrays, and `'*'` is accepted only as the sole element. Domains and addresses in lists are normalised once (trim, Unicode NFC, lower case,
IDNA to ASCII, trailing dot removed), so a domain means the same in every spelling. An invalid
**allow-list** entry is dropped (log ERROR `config_invalid`, `diag` WARN); an invalid **deny-list**
disables what it protects (fail closed, `diag` FAIL): a malformed hidden list every book, a
malformed domain exclusion (`*_exclude`) the book it belongs to, for every user. A configuration
change takes effect with the next request.

**Unknown options.** A `galbridge_*` option – in the plugin's file, in Roundcube's own configuration
or as a `ROUNDCUBE_GALBRIDGE_*` variable – that this version does not know is almost always a typo
(`galbridge_hidden_adresses`), and the option meant would silently keep its default: for a hidden
list, nothing hidden. It **disables every book** (log `config_invalid` naming it); `diag` names the
closest valid option. A variable counts only in the spelling Roundcube reads (upper case). A stored
user preference never triggers this.

**Types are checked, never guessed.** A switch must be `true` or `false` (or `1` / `0`; the
`searchonly` options also `'auto'`), `galbridge_visibility_column` must be a string. A value of
another type – the string `'false'`, an array where a name is expected – would be read by PHP as
"on" or as "no filter"; GAL Bridge reports it instead and disables **every** book until it is
corrected (meanwhile an ambiguous switch is read as its restrictive value: off, or on for
`hide_expired`, `mark_foreign_domains` and `searchonly`). `diag` shows the effective value of each
switch.

**Environment overrides.** Roundcube reads `ROUNDCUBE_<OPTION IN UPPER CASE>` from the environment
of the PHP process before the configuration files (common in containers) and would turn the text
`false` into the boolean *true*. For its switches GAL Bridge therefore reads the variable itself:
`1`, `0`, `true` and `false` mean what they say (`auto` for the `searchonly` options), anything else
disables every book. `diag` lists the overrides in effect by name.

### 3.1 Books

| Source ID | Who sees it | Data scope | Default |
|---|---|---|---|
| `galbridge_domain` | users whose home domain is in `galbridge_domain_viewer_domains` and not in `..._exclude` | the user's home domain, or every domain of its organization | on |
| `galbridge_global` | users whose home domain is in `galbridge_global_viewer_domains` and not in `..._exclude` | `galbridge_global_data_domains` minus `..._exclude`, minus the user's domain-book scope unless `galbridge_global_include_home` | off |

The **home domain** is `mailbox.domain` of the vmail row whose `username` equals the Roundcube
login (an active mailbox in an active, non-backup-MX domain; with `galbridge_hide_expired` neither
of them may be expired). The login string alone never decides it – there has to be such a row, and
the row's `domain` column has to be the domain of the login; a row where the two differ (damaged
data) opens no directory. A login without a usable row gets no books. Books are read-only.

| Option (domain book) | Default | Meaning |
|---|---|---|
| `galbridge_domain_book` | `true` | enable the domain book |
| `galbridge_domain_book_name` | `''` | name; empty = the organization label when the user's domain belongs to one, otherwise "Domain directory" (localised) |
| `galbridge_domain_viewer_domains` | `['*']` | home domains whose users get the book |
| `galbridge_domain_viewer_domains_exclude` | `[]` | home domains whose users do not get it (deny-list) |
| `galbridge_domain_groups` | `'auto'` | `auto`, `department`, `domain` or `none` (3.5) |
| `galbridge_domain_searchonly` | `false` | `true`, `false` or `'auto'` (see below) |
| `galbridge_domain_autocomplete` | `true` | offer the book in mail autocomplete |
| `galbridge_domain_export` | `true` | allow the export of the whole book (up to `galbridge_max_export`) |

A **search-only** book lists nothing until the user searches (Roundcube then shows "You have to use
a search form to see contacts from the selected addressbook."), as Roundcube's LDAP books do.
`'auto'` makes a book search-only when its scope holds more than `galbridge_searchonly_threshold`
(default 2000) entries. Search-only books (`true` or `'auto'`) have no groups.

The autocomplete option is enforced on the server: an autocomplete request naming a book with
autocomplete disabled returns no contacts and no groups.

### 3.2 Organizations

```php
$config['galbridge_organizations'] = [
    'Acme' => ['acme.example', 'acme-group.example'],
    'Firma' => ['firma.example'],
];
```

Users of any domain of an organization share one domain book that covers all of its domains. The
key is the organization label: the default name of that book and the value of the `organization`
field. The sets must be disjoint: a domain listed in two organizations is removed from both and its
users fall back to their own domain (fail closed, `diag` FAIL).

### 3.3 Global book

| Option | Default | Meaning |
|---|---|---|
| `galbridge_global_book` | `false` | enable the global book |
| `galbridge_global_book_name` | `''` | name; empty = "Global directory" (localised) |
| `galbridge_global_viewer_domains` | `[]` | home domains whose users get the book (`['*']` = every user); empty = nobody |
| `galbridge_global_viewer_domains_exclude` | `[]` | deny-list of viewer domains |
| `galbridge_global_data_domains` | `[]` | domains the book shows; `['*']` = every hosted domain, **including tenants added later**, and needs `galbridge_global_allow_wildcard = true` as well |
| `galbridge_global_data_domains_exclude` | `[]` | deny-list of data domains |
| `galbridge_global_allow_wildcard` | `false` | second opt-in for `'*'` data domains (`diag` warns while it is used) |
| `galbridge_global_include_home` | `false` | also show the user's own domain-book scope (by default every address belongs to one visible book only) |
| `galbridge_global_groups` | `'none'` | `none`, `department` (one group per domain and department, labelled `(domain) Department` – section 3.5) or `domain`; groups exist only with `galbridge_global_searchonly = false` |
| `galbridge_global_searchonly` | `true` | `true`, `false` or `'auto'` |
| `galbridge_global_autocomplete` | `true` | offer the book in mail autocomplete |
| `galbridge_global_export` | `false` | allow the export of the whole book |
| `galbridge_mark_foreign_domains` | `true` | show global-book entries outside the user's home domain(s) as `(domain) Name` (see below) |

Search in the global book always uses **prefix** matching, whatever `addressbook_search_mode` says,
every search word must reach the minimum length, e-mail addresses match from the start of the
local part (or exactly in strict mode), never on a domain fragment, and the `organization` field
matches only the complete organization label, so searching a domain or organization name does not
return a whole tenant. Its result cap is `galbridge_global_max_results` (100). A global book
whose data scope is empty for a user is not listed. The user's home domain (or organization) is
subtracted even when that user has no domain book (domain book disabled or viewer excluded): set
`galbridge_global_include_home = true` in that case if the global book should show it.

With `galbridge_mark_foreign_domains`, an entry of another domain is shown as `(domain) Name` (ASCII
domain) and carries no first and last name, so every "List names as" mode of Roundcube
(`addressbook_name_listing`) falls back to the marked display name. A field such an entry does not
carry is no search or sort criterion for it either, so a search cannot confirm its value: a search
of the name, the first name or the last name finds it by its first or last name only when it has no
display name (they are then its shown name, `(domain) First Last`), and a list sorted by first or
last name orders it by its display name. The marker comes first: a
recipient field shortens a long name at its end, which must not hide where the entry comes from.
The marker is part of the display name: it also appears in the recipient fields of a message, in
exported vCards (`FN`) and in contacts copied to a personal address book.

Two consequences of the marker standing first:

* **Order of suggestions.** Roundcube sorts the autocomplete suggestions of all address books by
  name and pre-selects the first one. How it sorts depends on its release:
  * **1.7.6 and later** use the ICU collator (`rcube_utils::asort()`), where a name that starts with
    `(` sorts before letters and digits: entries of other domains are listed **before** unmarked
    names, and a user who presses Enter right after typing gets the first of them;
  * **1.7.0 – 1.7.5** use PHP's `asort()` with the C library locale of the user's language, which
    usually ignores `(` at the first level: a marked entry is listed among the unmarked names by the
    domain in its marker (`(beta.example) Zofia` near names starting with "beta"), so an entry of a
    domain whose name sorts early can still come first; only the C locale (no locale of that
    language installed) puts every marked entry first.

  With `autocomplete_threads` each book answers in a request of its own and the browser appends the
  answers in the order they arrive, so the books are not merged into one sorted list. The marker is
  visible in the suggestion and in the recipient field. Where that order is not wanted, set
  `galbridge_global_autocomplete = false`: the global book then stays out of autocomplete and
  remains available in the address book and in the contact list of the compose window. `diag`
  describes the order of the Roundcube release it runs with while this applies.
* **Name templates.** The marker protects the name. With a `contactlist_name_template` that shows
  other text of an entry before `{name}` or without it, entries of other domains carry no department
  and no telephone numbers (section 2.5) – and are not found by them either.

Example – a partner directory for one company:

```php
$config['galbridge_global_book'] = true;
$config['galbridge_global_book_name'] = 'Partner directory';
$config['galbridge_global_viewer_domains'] = ['acme.example'];
$config['galbridge_global_data_domains'] = ['partner-a.example', 'partner-b.example'];
```

### 3.4 Fields

`galbridge_fields` is the allowlist of the fields users can see, search and export. Default:
`['name', 'firstname', 'surname', 'email', 'department']`.

| Field | Shown as | vmail source |
|---|---|---|
| `name` (always) | display name | `mailbox.name`, else first + last name (when allowed), else the local part |
| `email` (always) | e-mail (work) | `mailbox.username`; per-user aliases as further addresses with `galbridge_user_aliases` |
| `firstname` | first name | `mailbox.first_name` |
| `surname` | last name | `mailbox.last_name` |
| `department` | department | `mailbox.department` |
| `phone` | phone (work) | `mailbox.telephone` |
| `mobile` | phone (mobile) | `mailbox.mobile` |
| `organization` | organization | the label from `galbridge_organizations` (requires that option); in the global book a search word matches it only when it equals the whole label (a label of several words: strict search only) |
| `birthday` | birthday (never searchable; `0001-01-01` = empty) | `mailbox.birthday` |

The effective fields are the allowlist **and** the columns the role can read; nothing outside them
is shown, searched, sorted, grouped on or exported.

`galbridge_user_aliases` (default `false`): per-user alias addresses (`is_alias` rows of the
forwardings) are shown and matched by e-mail search only when enabled; when disabled they are
neither shown nor matched, so a search cannot reveal who owns an alias. When enabled, **showing and
matching follow one rule**: an alias counts only when its domain belongs to the book and is active
(not a backup MX and, with `galbridge_hide_expired`, not expired) and when it is on neither hidden
list (3.7). A hidden alias is therefore not shown and does not find its owner either – not as a
whole and not by a fragment. At most 20 aliases are shown per person (the first ones in address
order) – for every person of a page: the bound is per person, so one person with thousands of
aliases takes nobody else's away (log `aliases_capped` when a list is cut).

Display text is sanitised: Unicode NFC; control characters, CR/LF, format characters (bidi
controls, zero-width characters, soft hyphen, tag characters) and `\ " < >` removed; Unicode spaces
and characters that render blank (U+2800, U+3164, U+115F, U+1160, U+FFA0) collapsed into one space,
so padding cannot push text out of view; names capped at 128 characters. Two notations that a later
reader would **decode** are neutralised as well, so that the text checked by the plugin is the text
a user finally sees: `&` becomes the full-width ampersand `＆` (U+FF06) – Roundcube's recipient
widget inserts names as HTML, where `&#64;` would be displayed as an at-sign – and `=?` and `?=`,
the delimiters of an RFC 2047 encoded word that the mail clients of the recipients would decode,
are split (`= ?`, `? =`). Curly brackets become `｛` `｝` (U+FF5B, U+FF5D): core fills a
`contactlist_name_template` placeholder by placeholder, so a `{department}` inside a name would be
replaced as well. Two notations of Roundcube's suggestion list are neutralised too: `$` becomes
`＄` (U+FF04) – the list puts the text into its template as the replacement string of JavaScript's
`String.replace()`, which would expand `$&`, `` $` ``, `$'`, `$1` or `$<x>` – and a run of `%` is
split by blanks (`%%` never survives) – the list turns `##…%%` into bold text without showing the
markers. What Roundcube deletes later is deleted first: with a
`contactlist_name_template` it removes empty bracket pairs (`()`, `[]`) from a composed name, which
would join `=()?` to `=?` again; for the same reason a text never starts with a closing or ends
with an opening bracket (it would form such a pair with a bracket of the template), and never
starts or ends with `=`, `?` or `%` (they become `＝`, `？`, `％`: a template may put two fields
next to each other). Every text that core can put into a recipient name – display, first and last
name, department, both phones, group labels and organization labels – is cut at an at-sign (`@` or
its look-alikes `＠` U+FF20 and `﹫` U+FE6B), so it cannot pose as an address. Phone numbers are
free text in iRedMail (extensions such as `wew. 12`): they follow every one of these rules, but no
character allow-list, which would drop legitimate values. An entry without any name – or with a
name Roundcube takes for empty, such as `0` – is shown with its first and last name, else with the
local part of its address, treated like every other text.

**The address rule.** An entry is offered only when its address is *canonical*: a local part of
lower-case ASCII letters, digits and `.` `_` `+` `=` `-` in dot-atom form (no leading, trailing or
double dot, at most 64 bytes), a host name of at least two labels (letters, digits and inner hyphens,
at most 63 bytes each) whose last label starts with a letter or is an A-label (`xn--…`), at most 254
bytes in all, and – for persons, alias accounts and lists – a host name that is the entry's
`domain` column. Per-user aliases follow the same rule (their `domain` column, the owner's domain,
follows the host name rule). The rule leaves out every character that has a meaning in a part of
Roundcube that shows or uses an address (`&` `#` `?` `%` `{` `}` `/` `$` `!` `*` `^` `|` `` ` ``
`~`, quotes) and every non-ASCII character (no look-alikes); it is stricter than Roundcube's e-mail
check. iRedAdmin creates such addresses. The SQL of every book applies the rule to every row, and
the plugin's re-check of each row applies the same rule, so an entry is offered everywhere – list,
count, search, group, export, recipient set – or nowhere: an entry outside the rule is not listed,
not counted, not found and not part of a group, and it cannot make an export or a group expansion
fail. `diag` lists such entries (`entries not offered`, section 2.7). Letters are compared the way
the database does: on MariaDB/MySQL (iRedMail's case-insensitive collation) an address written with
capitals is offered in lower case; PostgreSQL compares case-sensitively and does not offer it (its
ID would not find the row either).

### 3.5 Groups

* `galbridge_domain_groups = 'auto'` groups by **domain** when the user's organization has more than
  one domain, otherwise by **department**. `galbridge_global_groups` defaults to `'none'`.
* Department groups need the `department` field (and its grant); otherwise the book has no groups
  (log `grouping_disabled`). Departments are grouped case-insensitively (`LOWER(TRIM(department))`),
  empty departments form no group. In the **global book** a department group is per domain and
  labelled `(domain) Department`: equal department names of different tenants never form one group,
  so no tenant can add its accounts to a group whose name other tenants use (group IDs of 1.0.x
  select nothing – section 5). A domain book keeps one group per department name over all its
  domains (an organization is one trust unit). The number of department groups of the global book
  depends on the data of every tenant in it – above `galbridge_max_groups` it shows none (fail
  closed; `diag` WARN): prefer `'domain'` there. A group label is display text like a department
  (3.4). A department with a control character or a backslash is not
  offered as a group (log `group_key_unusable`; its members stay in the book), and a group ID that
  carries such a key selects nothing: no such value is ever bound into a statement (3.9).
* Members are **persons only**: alias accounts and lists stay individual entries, so the count is a
  head count and nobody receives a message twice.
* One aggregate query per request returns all groups with their member counts. A book with more than
  `galbridge_max_groups` (50) groups shows no groups (log `too_many_groups`). Search-only books have
  no groups. A group ID in a request is honoured only for a group the book shows: in a book without
  groups it selects nothing.
* **Expansion** (compose to group, picking a group in autocomplete, group export) returns all members
  or, above min(`galbridge_max_group_members`, core `max_group_members` when set), nothing plus the
  message "This group has more than N members ..." (a refused group export downloads nothing,
  section 4). The member set is read with one statement up to the cap plus one row, so the answer
  never depends on `galbridge_max_count`; a set of which an entry could not be read is refused as
  well ("Not every entry of this selection could be read ..."). Autocomplete offers only groups that
  expansion would accept.

### 3.6 Alias accounts, mailing lists and posting policies

`galbridge_include_aliases` (default `true`) shows iRedMail alias accounts that have at least one
active member; `galbridge_include_mailing_lists` (default `true`) shows active mlmmj lists. Both
appear as ordinary entries with their address; their members are never listed or expanded. They
are shown only to users who could post to them under iRedAPD's `accesspolicy` (V = the user's login
and own per-user aliases, H = the home domain):

| `accesspolicy` | alias account | mlmmj list |
|---|---|---|
| `''`, `public` | everyone who sees the book | same |
| `domain` | users of the same domain (H) | same |
| `subdomain` | users of the domain or of a subdomain of it | same |
| `membersonly` | an active member in V | a moderator or owner in V (subscribers are not in SQL) |
| `moderatorsonly`, `allowedonly` | a moderator in V, or a `*@H` moderator entry | a moderator or owner in V |
| `membersandmoderatorsonly` | a member or a moderator | a moderator or owner in V |
| anything else | hidden (fail closed) | hidden |

`galbridge_list_policy`: `'strict'` (the table, default), `'public_only'` (only `''`/`public`
entries) or `'all'` (every active alias account and list; explicit opt-in).

### 3.7 Hiding entries

Never shown: inactive mailboxes (`active = 0`) or mailboxes with `enabledeliver = 0`, entries of
inactive or backup-MX domains, forwarding targets, catch-all rows, external list members, and
entries whose address does not follow the address rule (3.4). The hidden lists and the exclusions
therefore act on canonical rows only, compared in lower case on every engine: an entry written in
another spelling (Unicode NFD, a non-ASCII capital, a U-label domain column) is not offered at all,
so it can neither escape a hidden list nor be counted or found.

| Option | Default | Meaning |
|---|---|---|
| `galbridge_hide_expired` | `true` | hide mailboxes, domains, alias accounts and lists whose `expired` date has passed – by the database server's clock in its session time zone (on a standard iRedMail server the time zone of the server) **or** by UTC, whichever comes first: the `expired` columns have no time zone, and an entry is hidden from its expiry on whichever of the two conventions wrote it. The expiry has to be readable: when the role cannot read the `expired` column of alias accounts or of lists, that kind of entry is left out (`diag` WARN), never listed unfiltered |
| `galbridge_hidden_addresses` | `[]` | addresses never shown **and never matched** (persons, alias accounts, lists, per-user aliases) |
| `galbridge_hidden_localparts` | `[]` | local parts hidden in **every** domain, e.g. `['postmaster', 'noreply']` (per-user aliases with such a local part included) |
| `galbridge_visibility_column` | `''` | name (a string) of an integer column you add to `vmail.mailbox`; persons are shown only when it is `1`. `''` is the only way to say "no column" |

The two hidden lists are deny-lists: a malformed entry disables every book (fail closed). iRedMail's
SQL editions have no per-user "hide from the address book" flag; `galbridge_visibility_column` is the
extension point for one. Example (grant the new column to the role as well):

```sql
-- MariaDB/MySQL
ALTER TABLE vmail.mailbox ADD COLUMN galbridge_visible TINYINT(1) NOT NULL DEFAULT 1;
GRANT SELECT (galbridge_visible) ON vmail.mailbox TO 'galbridge'@'localhost';
-- PostgreSQL (connected to vmail)
ALTER TABLE mailbox ADD COLUMN galbridge_visible INT2 NOT NULL DEFAULT 1;
GRANT SELECT (galbridge_visible) ON mailbox TO galbridge;
```

A configured column the role cannot read hides every person (fail closed; `diag` FAIL); an invalid
column name – or a value that is not a string, such as an array, a number or a boolean – disables
every book instead of switching the filter off. `diag` shows the column in use. iRedAdmin does not
manage such a column.

### 3.8 Limits

| Option | Default | Range | Meaning |
|---|---|---|---|
| `galbridge_min_search_length` | 2 | 1–10 | no query unless at least one search word is this long (global book: every word); shorter words narrow as word prefixes ("Jan K"). In compose and in the Redirect dialog, Roundcube's `autocomplete_min_length` is raised to this value |
| `galbridge_max_search_length` | 128 | 16–255 | longer input is not searched |
| `galbridge_max_results` | 500 | 10–5000 | rows of a domain-book search result; more show "Only the first N directory matches are shown" – in the search list and on the pages of a search result (pages beyond the cap are empty, the count stops at it). The **export** of a search result with more matches is refused (section 4) |
| `galbridge_global_max_results` | 100 | 10–5000 | the same for the global book |
| `galbridge_max_ids` | 200 | 1–1000 | contacts used at once (compose to selected, export of selected); more are refused, never cut. A selection is complete or refused: when one of the selected contacts cannot be read (malformed ID, an entry that is gone or not visible to the user) compose and export refuse the whole selection ("Not every entry …"). It also bounds the contacts read one by one in one request – copying directory contacts to a personal address book: beyond it the rest is refused with "At most N directory contacts can be used at once" |
| `galbridge_max_group_members` | 500 | 1–100000 | group expansion cap (lowered further by core `max_group_members` when set). Above 10000 one request reads, maps and holds that many entries: `diag` warns – keep it lower or give the PHP of the web server the memory and time for it |
| `galbridge_max_groups` | 50 | 1–500 | above it a book shows no groups |
| `galbridge_max_export` | 2000 | 1–100000 | whole-book export is refused above it; above 10000 `diag` warns, as for the group cap |
| `galbridge_max_count` | 10000 | 100–1000000 | list totals are counted up to it; at the bound users see "Only the first N directory entries can be browsed. Use the search to find the others.", and list pages beyond it are empty. It bounds counting only – what an export or a recipient set holds is decided by the limit of that operation, whatever the relation between the values |
| `galbridge_searchonly_threshold` | 2000 | 1–1000000 | the `'auto'` search-only limit |

A search splits the input into at most 5 words; every word must match at least one searchable field.

**Page sizes.** A list page has at most the larger of Roundcube's `max_pagesize` and
`addressbook_pagesize` (or `pagesize` without it) entries, and never more than 1000: a larger page
size is served as a page of that bound with the message "The directory shows at most N entries per
page." – never as an export or a group expansion. Roundcube's full sets are recognised by the page
sizes core uses for them (9999 for search lists and group expansion, 99999 for exports) and by the
recipient actions (compose to a group, group expansion); `diag` warns about page sizes above 1000.
The page number is bounded as well (pages beyond every list are empty). A full set reads its
details in statements of at most 500 addresses each, so no statement carries an unbounded list of
parameters.

Every statement that reads the view `galbridge_forwardings` builds its rows afresh – the alias,
alias-member and mailing-list rows of the whole `forwardings` table – before any condition applies
(the fence of section 2.2): an index on `forwardings.forwarding` does not shorten that part. The
statements of GAL Bridge refer to the view only in uncorrelated semi-joins (computed once per
statement, also inside the OR branches of the list policies) and in the join of the per-user alias
rows, so the cost grows with the number of alias and list rows, not with their product. On typical
iRedMail sizes that is a fraction of a millisecond; `diag` shows the number of rows of the view on
its `view galbridge_forwardings` line, and `diag --user=` the time of each count.

### 3.9 Database connection and IMAP host binding

| Option | Default | Meaning |
|---|---|---|
| `galbridge_db_dsn` | `''` | **mandatory**; drivers `mysql`, `mysqli` or `pgsql` |
| `galbridge_db_connect_timeout` | 2 | seconds (1–30), added to the DSN as `timeout` unless it has one; it bounds the TCP connection. With `galbridge_db_timeout` it also sets the **read timeout** of the MariaDB/MySQL connection: the larger of the two plus 2 s (`mysqlnd.net_read_timeout` while the plugin's own connection is created; a lower php.ini value is kept) |
| `galbridge_db_timeout` | 3 | seconds (1–30): statement timeout set on every connection (MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`), together with a read-only session; a server that refuses either disables the books (log `session_limit_refused`). It also feeds the read timeout (see `galbridge_db_connect_timeout`) |
| `galbridge_db_allow_plaintext` | `false` | accept a remote host without verified TLS (`diag` WARN) |
| `galbridge_db_allow_verify_ca` | `false` | accept PostgreSQL `sslmode=verify-ca` for a remote host (`diag` WARN; see the TLS rule) |
| `galbridge_db_refused_users` | iRedMail's roles, `root`, `postgres` | role names the plugin refuses in addition to the built-in ones (iRedMail's roles, `root`, `postgres` – always refused; the option cannot remove one) and to the users of Roundcube's `db_dsnw` / `db_dsnr` and of the password plugin's `password_db_dsn`, as Roundcube's own configuration, a second reading of the files and the password plugin's file name them. A value that is not a list of role names refuses **every** database connection (`diag` FAIL naming the position); `diag` shows the refused roles |
| `galbridge_imap_hosts` | `[]` | IMAP servers whose logins may use the directory: `host` or `host:port` (host names or IP addresses), or `unix:///path` for a local socket (the path in printable ASCII, no blanks, no `%`) |
| `galbridge_imap_allow_plaintext` | `false` | accept a session whose directory IMAP server is on another host although the link is not verified TLS (`diag` and the log WARN; see "IMAP transport") |
| `galbridge_scope_ttl` | 300 | seconds (30–3600) the resolved user (home domain, address set, column probe) is cached in the session; when it is renewed the IMAP server confirms the session's credentials; revocation of the mailbox or of an own alias does not wait for it (see below) |

**The DSN names its target itself.** Before anything connects, the plugin checks the DSN in the
form the database driver will receive it: a host or a unix socket (a DSN without either would let
the client library choose), a port of digits only, a database name, and only known parameters –
PostgreSQL `sslmode`, `sslrootcert`, `sslcert`, `sslkey`, `sslcrl`, `application_name`, `timeout`;
MariaDB/MySQL `ca`, `capath`, `cert`, `key`, `cipher`, `verify_server_cert`, `charset`, `timeout` –
each once and well-formed. The target is written in exactly one of the forms `host`, `host:port`,
`[IPv6]`, `[IPv6]:port` or `unix(/path/to/socket)` (PostgreSQL: the socket directory, optionally
`unix(/var/run/postgresql:5433)` for another port), followed by `/database` – written as it is,
without percent-encoding – and then `?parameters` (percent-encode a `+` in a value as `%2B`).
Anything Roundcube's DSN parser would drop or read differently without a word is refused: a second
port, text around an IPv6 address, the old `unix+…` and `tcp(…)` notations, a `?` before the
database name, a suffix of the database name. The DSN handed to the driver is **rebuilt from the
checked parts** (with the connect timeout as the last parameter), and the parser must read exactly
those parts back from it, so the driver connects to what was checked and what `diag` shows. A unix
socket path must fit the operating system's limit of 107 bytes (for PostgreSQL including the socket
file name `.s.PGSQL.<port>` that the client library appends). For MariaDB/MySQL the host
`localhost` **is the unix socket** that `pdo_mysql.default_socket` of PHP names (`diag` shows it on
its `DSN` line) and takes no port – write `127.0.0.1:PORT` for TCP; a port next to `localhost` or
inside `unix(…)` would be ignored by the driver and is refused. The PostgreSQL `service` parameter
is refused, and so are the environment
variables of the PostgreSQL client library that would move the connection elsewhere (`PGHOSTADDR`,
`PGSERVICE`, `PGSERVICEFILE`, and `PGPORT` for a DSN without a port); `PGSSLMODE`, `PGREQUIRESSL`
and `PGOPTIONS` give a warning. A violation disables every book (`diag` FAIL with the reason).

**TLS rule.** A unix socket, `127.0.0.0/8` and `::1` count as local, and so does `localhost`: for
MariaDB/MySQL it is the unix socket (see above); for PostgreSQL the client library looks the name up
through the system resolver on every connection, so it is accepted as local **with a warning**
(`diag` WARN, log `dsn_warning`) – write `127.0.0.1`, `[::1]` or `unix(/var/run/postgresql)`. Any
other host
needs a verified TLS connection: PostgreSQL `sslmode=verify-full` (e.g.
`pgsql://galbridge:PASSWORD@db.example.com/vmail?sslmode=verify-full&sslrootcert=/etc/ssl/certs/db-ca.pem`),
MySQL `ca` or `capath` together with `verify_server_cert=1` (e.g.
`mysql://galbridge:PASSWORD@db.example.com/vmail?ca=/etc/ssl/certs/db-ca.pem&verify_server_cert=1`).
PostgreSQL `sslmode=require` encrypts without authenticating the server and is refused.
`sslmode=verify-ca` checks the issuing CA but **not the host name** of the certificate: anybody
holding a certificate of that CA could pose as the database server, so it is accepted only with
`galbridge_db_allow_verify_ca = true` (for a private CA that signs nothing but this server; `diag`
and the log warn while it is used). The database server must offer TLS for this
(PostgreSQL `ssl = on` and a `hostssl` line placed before iRedMail's catch-all line in
`pg_hba.conf`; MariaDB `ssl_cert`/`ssl_key` and `REQUIRE SSL` for the account; section 2.2); check it
before moving the database to another host.

**Outages.** When the database cannot be reached, the session does not try again for 30 seconds
(log `db_down` with `retry_in=30` and `cause=`: `connect` – the server is unreachable or slow: the
attempt took at least half of `galbridge_db_connect_timeout`, or the error says the server cannot be
reached (connection refused, unknown host, timeout); in a web request Roundcube's errors log has the
driver message, and `diag` prints it on its `connect` line –, `driver_error` – the database driver
raised an error while connecting –, `session_limit` – the server refused the plugin's statement
timeout or read-only mode –, or `lost` – the connection was lost during a statement: a server
restart, or a MariaDB/MySQL server that stopped answering within the read timeout). A refusal the
server answers at once is **not** memoised for the session: the role's or the server's connection
limit (log `db_busy`, also PostgreSQL's "remaining connection slots are reserved") and any other
fast answer – an authentication error, a limit message in the server's language (log `db_down
cause=refused`, without `retry_in`): that request gets the message below, the next one tries again.
So one user who holds the role's connections cannot take the directory away from other sessions
for 30 seconds. A database outage is never cached as "no entitlement". What users see:

* a session that already had its books keeps them in the address-book list, also after
  `galbridge_scope_ttl` has expired (they are listed from the expired session cache while the
  configuration is unchanged, and read nothing); opening or searching such a book gives an empty
  result and the message "The directory is temporarily unavailable." (at most once per request,
  for all books together);
* a session that has not resolved its books yet (e.g. a login during the outage) gets no GAL Bridge
  books until the database is back;
* a query that fails on a working connection (log `db_error sqlstate=…`, e.g. a missing grant or a
  statement timeout) shows the same message.

**Bound values and the statement text.** Roundcube's database layer inlines every bound value into
the statement text (quoted by the driver), and PDO scans that text again for placeholders: a
backslash in a value can make PDO's scanner and the server see different string boundaries, and the
PostgreSQL client library ends a quoted value at a NUL byte. No such value is ever bound: search input
with a control character or a backslash is not searched (it matches nothing – the directory never
shows these characters), department groups with one are not offered (3.5), and the connection
refuses any value with a NUL byte or a backslash before it sends a statement (log
`unsafe_parameter`).

**Workers that wait.** The connect timeout bounds the TCP connection. On MariaDB/MySQL the plugin
also gives its connection a read timeout – the larger of `galbridge_db_connect_timeout` and
`galbridge_db_timeout` plus 2 s, set as `mysqlnd.net_read_timeout` while the connection is created
and restored right after (mysqlnd keeps the value in the connection; a lower php.ini value is kept)
– so a server or proxy that accepted the connection and then stays silent no longer holds the PHP
worker for mysqlnd's default of 86400 s. Where it cannot be set (`pdo_mysql` not built on `mysqlnd`:
log `read_timeout_unavailable`; `ini_set` disabled or the value fixed with `php_admin_value`: log
`read_timeout_not_applied`) and on PostgreSQL – libpq has no read timeout after connecting – the
bound is the request timeout of PHP-FPM (`request_terminate_timeout`, SECURITY.md).

**IMAP host binding.** A user who logs in through an IMAP server of their own choosing as
`ceo@tenant-a.example` must not get tenant A's directory. The plugin therefore accepts only sessions
whose IMAP server – host **and port** (`$_SESSION['storage_host']`, `storage_port`) – and stored
user host (`mail_host`) are covered by the directory servers. With `galbridge_imap_hosts` empty, the
directory server is derived from `imap_host` – as Roundcube itself applies it, read from its own
configuration object – when it is a single string without `%` placeholders: its host part, lower
case, IDNA, with its port (`tls://127.0.0.1:143` → `127.0.0.1:143`). User preferences (also one
named `imap_host` or `default_host`) and the client's `Host` header play no part. A templated
(`%d`, `%n`, `%s`), array or empty `imap_host` without an explicit list disables the plugin (`diag`
FAIL, log `plugin disabled`). So does Roundcube's `include_host_config` (when Roundcube's object or a
second reading of the files enables it): with it the effective `imap_host` depends on the requested
host name, so nothing is derived from it – list the servers (likewise when the variable
`ROUNDCUBE_INCLUDE_HOST_CONFIG` is set: Roundcube ignores it for this option, so what the core
really does cannot be told). Nothing is derived either when a second reading of the configuration
files gives another `imap_host` than Roundcube applies – an environment-specific file
(`config-<env>.inc.php`), a file pulled in with `include_once`/`require_once`, another plugin that
sets it: such a value is not one fixed administrator value (log `plugin disabled`; `diag` names the
options a second reading loses). With `include_host_config` every `galbridge_*` option – the list
`galbridge_imap_hosts` included – must come from the host-independent files: one that a per-host
file sets disables every book for that host name (section 2.3). The session's host and the stored user host
are compared exactly as Roundcube wrote them at login: a host name, an IPv4 address, an IPv6
address in brackets or a socket URI (the stored host may also be the configured URI of an older
Roundcube, `ssl://imap.example.com:993`). Any other form – a blank, a scheme, a user part, a colon,
a path – is `host_mismatch`: PHP would have connected that login somewhere else than the text reads
(Roundcube before 1.7.6 accepts a typed server unchecked). With several IMAP servers that
authenticate against the same vmail database, list them:

```php
$config['galbridge_imap_hosts'] = ['imap1.example.com:993', 'imap2.example.com:993'];   // no scheme, no %
```

An entry without a port allows every port of that host; give the port whenever another IMAP
service – a test instance, a proxy in front of another user database – listens on the same host
(`diag` warns for every entry without a port). The host stored for a Roundcube user
(`users.mail_host`) carries no port and is compared by host alone.
An IMAP server on a local socket (`imap_host = 'unix:///run/dovecot/imap.sock'`, which Roundcube
opens without a port) is derived or listed as that URI. A socket has neither host name nor port: it
is matched as a whole, the path exactly as written. The path must be printable ASCII without blanks
and without `%`: an entry with another character is refused (the plugin is disabled), and a session
or a stored `mail_host` with such a path does not match (`host_mismatch`; `diag` counts those users
without printing the value).
**Every listed server must authenticate against this vmail database**: the plugin cannot verify
that, and a login from another user database that happens to equal a mailbox name here would get
that mailbox's directory (`diag` reminds you when more than one server is listed).

`diag` checks that the directory servers cover `imap_host` and the `mail_host` of the existing
Roundcube users (users with a `mail_host` in another form are counted, never printed). A session
outside them gets no books and is logged once per request as `host_mismatch` with `host=` (session
IMAP server), `mail_host=` (stored user host) and `allowed=` (the directory servers); a value in
another form is logged as `not-canonical`.

**IMAP transport.** The binding trusts the IMAP login of the session: whoever can answer that login
can claim any mailbox. On a directory IMAP server that is not local – anything but a socket,
`localhost`, `127.0.0.0/8` and `::1` – a session therefore gets the directory only when it reached
the server over TLS that authenticates it: the session's TLS mode (`storage_ssl`, which Roundcube
derives from the scheme of `imap_host` or of the typed server) is `ssl`/`imaps` (TLS from the first
byte, `ssl://host:993`), or `tls` (STARTTLS) on a Roundcube library of 1.7.7 or later – older
releases, and a core that reports `1.7-git`, go on **in plain text** when the server does not offer
STARTTLS –; PHP has the `openssl` extension (without it Roundcube connects in plain text whatever
the scheme says); and `imap_conn_options` (the entry for that host, if it has one) do not set
`verify_peer` or `verify_peer_name` to false or `allow_self_signed` to true. Otherwise the session
gets no books (log `imap_transport` with the reason and `host=`, `denied_source
status=transport_unprotected`); this is checked on every request, before the cache and the
database. `galbridge_imap_allow_plaintext = true` accepts such a session for a link nobody else can
reach (log and `diag`: WARN). Write the scheme in lower case: Roundcube reads `SSL://` as no TLS.
The iRedMail default (`tls://127.0.0.1:143`) is local and needs nothing.

**The viewer cache and revocation.** The resolved user is cached in the session for
`galbridge_scope_ttl`. Losing the directory does not wait for it: every request that reads
directory data first confirms with one small statement that the mailbox of the session is still
active, unexpired and in its domain. A mailbox that was disabled, expired, deleted or moved – or
whose domain was – gets "The directory is temporarily unavailable." on that request and no book
afterwards (log `viewer_revoked`). With the strict list policy (the default), where the user's own
aliases decide which restricted alias accounts and lists they see, the aliases are read again in
the same step: a **removed** own alias applies with the next request (that request reads nothing
and shows the same message once; log `viewer_changed`). Requests that only instantiate a book (the
sender-photo lookup of every opened message) do not pay for these statements.

When the cached entry is renewed (after `galbridge_scope_ttl`), the **IMAP server confirms the
credentials of the session** before anything is read: Roundcube's IMAP connection of the request
when it is open, otherwise Roundcube connects with the session's host, port, TLS mode, login and
password (at most one IMAP login per session and period, in a request in which Roundcube has not
connected to IMAP yet – typically one of the address book). A refusal of
the credentials (Dovecot's answer for a changed password or a disabled IMAP service:
`AUTHENTICATIONFAILED`, `AUTHORIZATIONFAILED`, `EXPIRED`) ends the directory for the rest of that
session – it is not asked again (log `viewer_revoked`, status `imap_refused`; the user sees
Roundcube's own storage error once); a new login starts a new session. A user who changes their own
password in Roundcube (password plugin) is not affected: Roundcube keeps the new one in the
session. An IMAP server that cannot be asked (unreachable, a temporary failure, no answer code) is
no revocation: the directory is unavailable and asked again after 30 seconds (log
`imap_unavailable`). A **new** own alias (strict list policy) is taken over by the request that
reads the aliases again – that request still uses the old set, which is narrower – and applies
from the next request on. A changed column grant and the role checks (`role_too_broad`,
`column_missing`) apply when the entry is renewed.

**Ending a compromised account's directory access at once:** deactivate the mailbox (the next
request that reads the directory loses it) and change its password (the directory stays ended at
the next renewal, also after the mailbox is activated again – even in a session that is still
open); to end the Roundcube sessions themselves, delete them from Roundcube's session storage (the
`session` table with the default `session_storage = 'db'`).

### 3.10 Logging

The plugin logs to the Roundcube log `galbridge`: the file `<log_dir>/galbridge.log` (Roundcube
appends `log_file_ext`, default `.log`). With `log_driver = 'syslog'`, which iRedMail configures
(facility `LOG_MAIL`), the lines go to syslog under Roundcube's ident without the log name. Line
format: `LEVEL operation: message key=value ...`, with the numeric Roundcube user id (`user=`) added
automatically; a value that contains a blank, `=`, `"` or `\` is written in double quotes
(`allowed="127.0.0.1 mail.example.com"`, with `\"` and `\\` inside), so a log parser can split the
line safely. Normal levels carry reason codes, book ids, counts and SQLSTATEs – never search
terms, addresses or names; `config_invalid` names the option and the position of a rejected list
entry (`entry 2 cannot be normalised`), never the entry itself. Control and format characters are
replaced, and a password is masked in every form a DSN or a connection string can carry it. Reason codes triggered by request input or by directory rows
(`invalid_id`, `invalid_gid`, `denied_source`, the limits, `row_invalid`, `row_out_of_scope`,
`host_mismatch`, ...) are logged at most once per request; `db_error` is logged for every failed
query. GAL Bridge adds every enabled book to `autocomplete_addressbooks`, so Roundcube's autocomplete
asks a book the user may not be entitled to: that refusal is logged only with `galbridge_debug`
(`denied_source` stays at normal level for a request that names the book itself and for every
other action). `galbridge_debug = true` (default `false`) adds only the database driver's error text, the
messages of unexpected exceptions and schema hints (an entity left out because a column is not
readable). The plugin never logs search terms or addresses itself, but driver and exception texts
are not filtered for personal data (a driver error may quote part of a statement, which contains
the inlined search values): keep it off in normal operation – `diag` warns while it is on (also
through `ROUNDCUBE_GALBRIDGE_DEBUG`).

"At most once per request" bounds the lines of one request, not of a session: a loop of requests
writes up to one line per reason code per request. The plugin has no rate limiter; the per-client
limits of the reverse proxy bound it ([SECURITY.md](SECURITY.md), checklist).

With Roundcube's `per_user_logging` the `galbridge` log is written into the user's own directory
below `log_dir` when that directory exists, and Roundcube refuses the line otherwise (before the
login, or for a user without a directory). An **ERROR or WARN line that Roundcube refuses** – also
because the file cannot be written – is written to Roundcube's `errors` log instead, prefixed
`galbridge:` (and to PHP's error log if that fails too), so a refused role or an invalid
configuration never disappears without a trace; INFO and DEBUG lines follow Roundcube's decision.
Under `log_driver = 'syslog'` Roundcube gives every line of the `galbridge` log the priority INFO
(only its `errors` log gets ERR): filter on the level word in the line (`ERROR`, `WARN`).

The **database server** keeps logs of its own, and Roundcube sends statements with the values
inlined, so a statement of the plugin contains what a user searched for. The PostgreSQL grant set
switches statement logging off for the role – the price is that there is no statement audit trail
of the role (connections with `log_connections` and error messages without the statement text
remain); an audit extension such as pgaudit or auto_explain has settings of its own, and `diag`
warns while one of them would log the role's statements. MariaDB/MySQL have no such per-account
setting – keep the general query log off or restricted. `diag` shows what applies
([SECURITY.md](SECURITY.md#logs)).

## 4. Using it

* **Address book**: the books appear in the source list (default names "Domain directory" and
  "Global directory", in Polish "Katalog domeny" and "Katalog globalny"). They are read-only:
  contacts can be opened, printed, shown as QR code, exported as vCard, copied to a personal address
  book and used for "compose to selected". Groups appear below a book when grouping is enabled;
  selecting one lists its members, and a group can be used as recipient or exported.
* **Search**: quick and advanced search use the effective fields (3.4). The domain book follows
  Roundcube's `addressbook_search_mode` (partial matching by default); the global book always uses
  prefix matching (3.3). At least one word must have `galbridge_min_search_length` characters
  (global book: every word); "Jan K" finds Jan Kowalski. Input with a control character or a
  backslash is not searched (it matches nothing; 3.9).
* **Compose**: autocomplete includes the books (and matching groups, shown by Roundcube with their
  member count), also in the recipient fields of the Redirect dialog; the contacts pane lists the
  books and their groups. Picking a group inserts every member.
* **Messages** a user can see:

| Message | Cause |
|---|---|
| The directory is temporarily unavailable. | database outage or failed query (log `db_down` / `db_error`; section 3.9) |
| This group has more than N members and cannot be added as recipients. ... | group expansion above the cap (3.5) |
| Only the first N directory matches are shown. Refine your search. | search result cap – the search list and the pages of a search result (3.8) |
| Only the first N directory entries can be browsed. Use the search to find the others. | a list total at `galbridge_max_count` (3.8) |
| The directory shows at most N entries per page. Ask your administrator to lower the page size. | a page size above the page bound (3.8; `diag` WARN `page size`) |
| At most N directory contacts can be used at once. | more than `galbridge_max_ids` selected contacts (compose to selected, export of selected), or more contacts read one by one in one request (copy to a personal address book: the rest is not copied) |
| Not every entry of this selection could be read from the directory, so it was not used. ... | compose to selected or export of selected with a contact that cannot be read (malformed ID, gone or not visible to the user – log `set_incomplete missing=N`), or a group, export or recipient set of which an entry changed or disappeared between the statements of the request, or a defect (`set_incomplete dropped=N` with `row_invalid` / `row_out_of_scope row=…`): complete or nothing. Entries outside the address rule never cause it – they are not offered (3.4) |

* **An export is complete or it is refused – and a refused export downloads nothing.** An export
  is a file download, so no message can be shown with it. When GAL Bridge refuses one, the server
  answers `204 No Content`: the browser stays on the page and saves no file (not even the contacts
  of other address books selected together with directory contacts). Refused are: the whole book
  with `galbridge_*_export = false` or above `galbridge_max_export`; a group above the expansion
  cap; more than `galbridge_max_ids` selected contacts; a **search result with more matches than
  the result cap** (`galbridge_max_results` / `galbridge_global_max_results` – on a page such a
  list is cut with a notice, a file could not say so); a set of which an entry could not be read –
  also a selection of contacts with one that is gone, not visible to the user or malformed; and any
  export while the directory fails (database outage or query error). The refusal is logged
  (`export_disabled`, `export_too_large`, `group_too_large`, `too_many_ids`, `set_incomplete`,
  `db_down` / `db_error`). No limit that only bounds counting (`galbridge_max_count`) decides what a
  file holds.

## 5. Upgrade

**The plugin.** Replace the plugin files with the new release exactly as for an installation
(section 2.1: verify the tag against the release key and compare its fingerprint, then
`git archive ... | tar -x`; a configuration in `config/galbridge.inc.php` is not touched – one in
`plugins/galbridge/config.inc.php` must be kept, or better moved there first, section 2.3) or run
`composer update --prefer-dist takenek/galbridge` (iRedMail's SQL editions: add
`--ignore-platform-req=ext-ldap`, section 2.1; and compare the commit it installed with the
verified tag, section 2.1: Composer does not check signatures). Read [CHANGELOG.md](CHANGELOG.md), then run
`bin/galbridge.sh diag`. After a Composer update `diag` is not optional: the installer re-creates a
`plugins/galbridge/config.inc.php` with the owner and umask of the user who ran Composer – restore
`chown root:www-data` and `chmod 0640`, or better move the configuration to
`config/galbridge.inc.php` and delete the plugin-directory file – and a failed update leaves a
`plugins/galbridge-<20 digits>` copy with the password in it (delete it; section 2.1). There are no plugin tables, so there is no schema update; options added by
a new release have built-in defaults.

**From 1.0.2 to 1.0.3** – no configuration or database step. `diag` has the option `--cafile=`
for a web server with a self-signed certificate (2.6), and a Composer installation on iRedMail's
SQL editions needs `--ignore-platform-req=ext-ldap` (2.1).

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

**Roundcube: install its security releases without waiting.** GAL Bridge supports every release of
the Roundcube 1.7 branch, and patch releases of that branch regularly fix security problems of
Roundcube itself: update the core as soon as a 1.7.x release is published – no new GAL Bridge
release is needed for it – and run `diag` afterwards. `diag` warns when the core is older than the
newest 1.7.x release this GAL Bridge version knows. Only a new minor branch (1.8) needs a GAL
Bridge release that names it.

**Roundcube on iRedMail.** iRedMail installs Roundcube into a versioned directory
(e.g. `/opt/www/roundcubemail-1.7.7`, reached through the symlink `/opt/www/roundcubemail`), and its
upgrade procedure creates a new tree. The plugin and its configuration live inside the tree, so copy
them into the new one before switching the symlink, otherwise the directory silently disappears:

```sh
cp -a /opt/www/roundcubemail-OLD/plugins/galbridge /opt/www/roundcubemail-NEW/plugins/
# only if the configuration lives in config/galbridge.inc.php:
cp -a /opt/www/roundcubemail-OLD/config/galbridge.inc.php /opt/www/roundcubemail-NEW/config/
```

`cp -a` keeps owner and mode. Make sure `'galbridge'` and `addressbook_sort_col` are in the new
`config/config.inc.php`, then run `diag` in the new tree. The database role is independent of the
Roundcube tree.

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
   PostgreSQL, as `postgres`:
   ```sql
   \c vmail
   DROP OWNED BY galbridge;   -- revokes its privileges in vmail, including CONNECT
   DROP ROLE galbridge;
   DROP VIEW IF EXISTS galbridge_forwardings;
   ```
   `DROP ROLE` alone fails while the role still holds privileges in `vmail`. Remove its
   `pg_hba.conf` lines, if you added them.
3. Remove `plugins/galbridge`, `config/galbridge.inc.php` (if used), the nginx rule of 2.6 and the
   log file `<log_dir>/galbridge.log`.

## 7. Troubleshooting

Start with `sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=<login>`; it shows the
cause of almost every "empty directory".

| Symptom | Cause / fix |
|---|---|
| No GAL Bridge book in the address book | `diag`: `galbridge_db_dsn` missing or refused (FAIL on the `galbridge_db_dsn` line with the reason), plugin disabled by the IMAP host binding (FAIL "directory hosts"; log `plugin disabled` – also with `include_host_config`), session server not a directory server (log `host_mismatch host=… mail_host=… allowed=…`: add the host, or the right port, to `galbridge_imap_hosts`; `diag` checks `imap_host` and the hosts of Roundcube's users), login not an active mailbox (`diag --user`: "not found"; log `denied_source status=no_mailbox`), role too broad (log `role_too_broad`: it can read a secret column or the `forwardings` table), a required column or the view `galbridge_forwardings` missing (log `column_missing` names it: run `grants --grants-only`, section 5), **a switch that is not a boolean or another fail-closed configuration error** (log `config_invalid … all books are disabled`, `diag` FAIL names the option) – also an unknown (misspelt) `galbridge_*` option or `ROUNDCUBE_GALBRIDGE_*` variable (`option=` names it, `diag` the closest valid name), a second configuration file next to `plugins/galbridge/config.inc.php` (`option=galbridge.inc.php`) or a configuration file that cannot be loaded (`option=config.inc.php`: not readable by the PHP user, no `$config` array; 2.3), a malformed `galbridge_db_refused_users` (every connection refused), home domain not in the viewer list, a login during a database outage (the books appear once the database is back, 3.9) |
| Global book missing | `galbridge_global_viewer_domains` is empty by default; `'*'` data domains without `galbridge_global_allow_wildcard` (log `config_invalid`); its data scope minus the user's own domain(s) is empty |
| "The directory is temporarily unavailable." | log `db_down` (`cause=connect`: the server is unreachable or slow – in a web request Roundcube's errors log has the driver message, `diag` prints it on its `connect` line; `cause=session_limit`: the server refused the statement timeout or read-only mode, `diag` names the statement; `cause=driver_error` / `lost`; next attempt after 30 s; `cause=refused`: the server answered at once with a refusal – authentication, a limit text in the server's language –, `diag` prints the driver text, the next request tries again) or `db_busy` (the role's or the server's connection limit is reached – raise it, 2.2, and limit the concurrent requests per client in the proxy, SECURITY.md) or `db_error sqlstate=…`: `42000` (MariaDB/MySQL: access denied – grants), `42501` (PostgreSQL: permission denied – grants), `42S22` / `42703` (unknown column – iRedMail schema or the `galbridge_visibility_column` name), `70100` (MariaDB `max_statement_time`), `57014` (PostgreSQL `statement_timeout`), `HY000` (MySQL, includes `max_execution_time`) |
| Export downloads nothing | a refused export (section 4): log `export_disabled`, `export_too_large` (also a search result above the result cap), `group_too_large`, `too_many_ids`, `set_incomplete`, or `db_down` / `db_error` during the export |
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
| Details | `<log_dir>/galbridge.log` or the mail log (syslog); reason codes below |

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

## 8. Security

See [SECURITY.md](SECURITY.md) and [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md). In short: the
visible scope of every query is derived from the authenticated login, resolved in vmail, and the
administrator configuration only; every directory statement is built by one query builder whose root
predicate is the scope, and every returned row is re-checked in PHP. Unentitled, stale or crafted
source IDs get an inert book that never touches the database. The plugin reads with a dedicated
column-restricted, read-only role and refuses broader ones. It is read-only and adds no endpoints.
A compromised web server, Roundcube installation or administrator is out of scope.

## 9. Compatibility

Supported: Roundcube 1.7.x, PHP 8.1–8.5, iRedMail 1.8.x MariaDB/MySQL and PostgreSQL editions. The
tested versions and the exact results are listed in [docs/TESTING.md](docs/TESTING.md). The
integration tests run against the upstream iRedMail 1.8.11 `vmail` schema on MariaDB, MySQL and
PostgreSQL (the server versions of section 1) and a SQLite mirror of it (tests only) and connect with a role built from the shipped
`SQL/grants.*.sql` (with the commented-out personal-data grant enabled, so that every field is
exercised), so they also prove that the documented grants are sufficient.

GAL Bridge has no templates, JavaScript or CSS, so it does not depend on the skin. It coexists with
Roundcube's personal address books, collected addresses and LDAP books; an `ldap_public` entry named
`galbridge_domain` or `galbridge_global` would shadow a GAL Bridge book (`diag` FAIL).

## 10. Known limitations

* **Accent folding differs between the engines.** MariaDB's `utf8mb4_general_ci` compares case- and
  accent-insensitively ("zolkiewska" finds "Żółkiewska"); PostgreSQL's `ILIKE` folds case only, and
  for non-ASCII letters only when the database's ctype (`LC_CTYPE`, `pg_database.datctype`) is not
  `C`/`POSIX` (`diag` warns).
* **Roundcube re-sorts search results** by `addressbook_sort_col` in PHP; the plugin cannot change
  that (2.5).
* **Only canonical addresses are offered** (3.4): internationalised local parts (SMTPUTF8, e.g.
  `zoë@…`), local parts with characters outside `a-z 0-9 . _ + = -`, and hosts that are not a
  multi-label ASCII host name are not in the directory – `diag` lists them. **On PostgreSQL** an
  address written with capitals is not offered either; MariaDB/MySQL offer it in lower case. The
  hidden lists and exclusions compare in lower case; on MariaDB/MySQL that assumes iRedMail's
  `utf8mb4_general_ci` – a Turkish or Azerbaijani collation lower-cases `I` to a dotless `i`
  (`diag` WARN `collation`).
* **mlmmj subscribers are not in SQL** (they live on disk): `membersonly` lists are shown to their
  moderators and owners only, and lists are never groups or expanded.
* **No per-user opt-out column** in iRedMail's SQL editions (the LDAP edition has one): use
  `galbridge_hidden_addresses`, `galbridge_hidden_localparts` or an administrator-created
  `galbridge_visibility_column` (persons only).
* **Caps instead of partial results**: group expansion above the cap, more than `galbridge_max_ids`
  selected contacts, a selection with a contact that cannot be read, a whole-book export above
  `galbridge_max_export` and the export of a search result above the result cap are refused; search
  results stop at `galbridge_max_results` / `galbridge_global_max_results` and list totals at
  `galbridge_max_count`, with a notice; a copy to a personal address book stops after
  `galbridge_max_ids` contacts with a notice. An export is a file download, so no message can be
  shown with it: a refused export downloads nothing (`204 No Content`, section 4).
* **Page sizes above 1000** are served as pages of the bound with a notice (3.8): a list cannot be
  read in one page of more than 1000 entries.
* **No rate limiter, by design** (a `$_SESSION` counter is not atomic and turns read-only requests
  into session writers): the plugin bounds every request (result caps, the lookups of one request,
  the parameters of one statement, timeouts), the account's connection limit and the statement time
  of every plugin session, and a refusal at the connection limit is not memoised for other sessions,
  but it does not slow down a user who sends many requests. Configure per-client limits in the
  reverse proxy – a request-rate limit and a concurrency limit per client address on every request
  passed to Roundcube's PHP, the concurrency limit well below the role's connection limit
  (SECURITY.md); a limit keyed on the `_action` of the query string alone is not enough, since
  Roundcube also takes `_action` from the POST body or a cookie. The plugin log is bounded per
  request (each reason code once), so a loop of requests writes one line per request as well.
* **No read timeout on PostgreSQL after connecting**: libpq has none, and Roundcube passes no
  keepalive settings to it. A PostgreSQL server (or proxy) that accepts a statement and then stops
  answering holds the PHP worker until PHP-FPM's `request_terminate_timeout` ends the request – set
  it for the Roundcube pool (SECURITY.md). MariaDB/MySQL connections have a read timeout (3.9).
* **MySQL 8.4 and later** do not load `mysql_native_password`, whose verifier the grant tool
  prints: use `grants --clear-text-password` there (section 2.2) – its statement holds the password,
  which MySQL rewrites in its logs, and it stops on MariaDB before that statement whatever the client
  does with comments. MariaDB and MySQL up to 8.0 need nothing. The suites ran on MySQL 8.0, 8.4,
  9.7 and 26.10 (section 1; CI repeats 8.0, 8.4 and 9.7): the view fence (`TEMPTABLE` with `LIMIT`,
  read from the server's own plan, the view built once per statement), the catalogs the role checks
  (`information_schema.VIEWS`, `APPLICABLE_ROLES`; MySQL roles with and without a default role),
  `SHOW CREATE VIEW` with column-level grants, the account limit, the statement time and the
  `--clear-text-password` output with its MySQL-only guard statement, applied (docs/TESTING.md
  section 6). That MySQL rewrites the password in its logs is its documented behaviour, not
  verified here.
* **The view is built for every statement** that reads it (section 3.8): the price of the fence that
  keeps every condition of a query away from private forwarding rows. On a `forwardings` table with
  very many alias and list rows, watch the times `diag --user=` reports.
* **PostgreSQL: `grants` re-creates the view**, so a second GAL Bridge role on the same `vmail`
  database loses its grant on the view until its own grant set is applied again (section 2.2).
* **What the role cannot see is not checked**: on MariaDB/MySQL a privilege granted to `PUBLIC`
  (MariaDB 10.11 and later) does not appear in the catalogs of the role – `diag` asks the server
  with statements that can change nothing for the relations GAL Bridge reads and lists every column
  the role can see; the plugin does not. `pg_hba.conf` (which databases and addresses a PostgreSQL
  role may use, TLS) is invisible to the role: `diag` lists the databases it may connect to.
* One cross-domain (global) book; read-only (no editing, no photos).
* The Roundcube login must equal `mailbox.username`; a login under an alias domain gets no books.
* A changed password or a disabled IMAP service applies when the cached user is renewed, after at
  most `galbridge_scope_ttl` (300 s; the IMAP server is asked then), and so does a changed column
  grant; losing the mailbox or a removed own alias (strict list policy) takes effect with the next
  request that reads the directory, a new own alias with the request after that (3.9); directory
  content is read live on every request.
* Search-only books have no groups; totals stop at `galbridge_max_count` (with a notice).
* In the global book with `galbridge_global_groups = 'department'` a group label shows the domain
  and the department of the entries of another domain, also when a name template withholds their
  department from the record (3.3) – the label carries the domain marker first.
* Contact IDs are a reversible encoding of the address (`u_janDkowalskiAexampleDcom`), and group IDs
  of the department name or domain; they appear in GET URLs and therefore in web server access logs,
  in the browser history and in the logs of proxies in front of the webmail (SECURITY.md, "Logs").
* **The plugin cannot verify which user database a directory IMAP server uses** (3.9): list only
  servers of this vmail database, with their ports (`diag` warns for an entry without one).
* **No statement audit trail of the PostgreSQL role**: its statements carry the search terms and are
  kept out of the server log by the grant set (SECURITY.md, "Logs"); an audit extension, if you
  enable one for the role, records those terms.
* **`galbridge_debug` writes unfiltered driver and exception texts** (3.10): keep it off in normal
  operation (`diag` warns while it is on).

## 11. Migrating from Roundcube-SQL-Global-Address-Books

See [docs/MIGRATION.md](docs/MIGRATION.md): the `_sql_*` → `galbridge_*` option map, the removal of
the old `global_addressbook` VIEW (and on PostgreSQL of the `dblink` view that stores the `vmail`
password in plain text, with a rotation of that password), the behaviour changes users will notice,
and how to express a "support book" with the global book.

## 12. Author

**GAL Bridge** was created and is maintained by **[Marek Królikowski (TaKeN)](https://github.com/takenek)**.

Business name: **TaKeN.PL Usługi Informatyczne Marek Królikowski**.

Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski.

Project repository: [github.com/takenek/galbridge](https://github.com/takenek/galbridge)

## 13. License

GPL-3.0-or-later (same as Roundcube; see [LICENSE](LICENSE)). Roundcube's plugin exception allows
plugins under other licenses, GPL-3.0-or-later was chosen for consistency with the ecosystem.

See [COPYRIGHT](COPYRIGHT) for the copyright holder, author attribution and narrowly scoped
additional terms under GPLv3 section 7(b) and 7(c). These preserve source attribution and require
modified versions to identify their changes; they do not prohibit forks, renaming, commercial use
or charging for distribution. Earlier releases retain the terms under which they were received.

## 14. Security review

The code was reviewed with Anthropic's Mythos 5.2 on 2026-11-24 (full source review, dependency and
platform advisory check, remediation of every accepted finding in 1.0.1 – a development version that
was never tagged or published, like 1.0.0; see [CHANGELOG.md](CHANGELOG.md) and
[SECURITY.md](SECURITY.md)). The remediation was then reviewed once more – area by area, each finding
reproduced by a test that fails on the code before its fix, and the CI steps run as an unprivileged
user; what that found is fixed in 1.0.1 as well (changelog, "Fixed after the review of the
remediation"). On 2026-11-25 the
remediation was verified against the audit report with Anthropic's Claude Opus 5.5 – in the code and
with the suites on all three engines – and where a fix was incomplete it was completed (changelog,
1.0.2).

A second, independent assessment of 1.0.1 (Anthropic's Mythos 5.2, 2026-11-25; full source review,
Roundcube 1.7.0 – 1.7.7 core integration, CI and release, dependency and platform advisories)
reported 36 findings (1 high, 1 medium, 23 low, 11 informational). All of them were remediated on
2026-11-25 with **Anthropic's Claude Opus 5.5** – plugin, command line tool, grant templates, CI,
release procedure, test harness and documentation, each fix with a regression test that fails on the
code before it, the suites run on SQLite, MariaDB and PostgreSQL with Roundcube 1.7.0 and 1.7.7; see
[CHANGELOG.md](CHANGELOG.md) (1.0.2, "Second verification pass") and the register of accepted
risks in [SECURITY.md](SECURITY.md#accepted-risks-and-design-decisions). An automated review is not
a certification: report anything it missed as described in SECURITY.md.

Before that, the design was reviewed prior to implementation in three independent reviews –
Roundcube core integration, security and operability – against the Roundcube 1.7.7 and iRedMail
1.8.11 sources, and the implementation of 1.0.0 was reviewed before its release; the accepted
findings are recorded as decisions in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), as threats
(GB-01 to GB-32) with their controls and tests in [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) and
in the 1.0.0 entry of the changelog.
