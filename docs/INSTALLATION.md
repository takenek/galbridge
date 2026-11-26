# GAL Bridge – Installation in detail

The full text of sections 1 and 2 of the README: every requirement and every installation step, with
the reasons behind it, the alternatives – Composer, other systems and web servers, a remote database
– the grant set and everything `diag` checks. [README.md](../README.md) section 2 has the short path
for a standard iRedMail server: its steps 2.1 – 2.7 are the steps of the same number here, with the
same commands.

Section numbers are shared by [README.md](../README.md) and the documents of `docs/`: sections 1 and
2 are in [INSTALLATION.md](INSTALLATION.md), 3, 4 and 10 in [CONFIGURATION.md](CONFIGURATION.md), 5
– 7 in [OPERATIONS.md](OPERATIONS.md), 8, 9 and 11 – 14 in the README. "Section 3.9" in this text is
section 3.9 of CONFIGURATION.md, "section 2.6" section 2.6 of INSTALLATION.md.

## 1. Requirements

| Component | Requirement |
|---|---|
| Roundcube | **1.7.x** – the whole 1.7 branch (`composer.json` `extra.roundcube`: `min-version` 1.7.0, `max-version` 1.7.999). The backend follows the address-book call paths of the 1.7 core (page sizes, search set replay, group expansion, ID transport, error 700 handling); the test suite runs against the oldest release (1.7.0) and the newest one (1.7.7 when this version was released – the Roundcube release that iRedMail 1.8.11 installs), and CI repeats it every day against the newest 1.7.x on Packagist. **Run the newest 1.7.x**: patch releases of the branch fix security problems of Roundcube itself and need no new GAL Bridge release (section 5). `diag` warns on a patch release older than the newest one it knows and on a core that reports `1.7-git` (installed from git or Packagist), and fails on any other branch. `min-version` is enforced only by the Composer plugin installer. Roundcube 1.6 and older are not supported; a new minor branch (1.8) needs a GAL Bridge release that names it |
| PHP | 8.1 – 8.5 (composer `php >=8.1 <8.6`) – the versions Roundcube 1.7 itself runs on; 8.3 or later recommended. The plugin does not narrow that range: use a PHP that still receives security fixes, from php.net or from your distribution. `diag` warns for a PHP branch past its upstream security support (8.1 since 2026-01-01, 8.2 from 2027-01-01) – on a distribution that maintains its own PHP packages read the warning as a reminder to check that support ([SECURITY.md](../SECURITY.md#supported-versions)) |
| PHP extensions | `pdo`, `mbstring`, and `pdo_mysql` (MariaDB/MySQL edition) or `pdo_pgsql` (PostgreSQL edition). Recommended: `intl` (IDN domains, Unicode NFC normalisation). Without `intl` a configured domain written with non-ASCII characters cannot be normalised: such an allow-list entry is dropped, and a deny-list containing one disables the book it protects (fail closed); `diag` warns when `intl` is missing |
| iRedMail | **1.8.x, MariaDB/MySQL or PostgreSQL edition** (the `vmail` schema of iRedMail 1.8.11). A server that never applied iRedMail's `update/1.7.3` (columns `first_name`, `last_name`, `mobile`, `telephone`, `birthday`) keeps working with fewer fields. The **OpenLDAP edition is out of scope**: there iRedMail configures Roundcube's native `ldap_public` address book |
| Database server | MariaDB **10.2** or later, MySQL **8.0** or later, or PostgreSQL – every platform of iRedMail 1.8 qualifies. **Tested** with this version (the full suites, docs/TESTING.md section 6): MariaDB 10.5, 10.6, 10.11, 11.4, 11.8, 12.3 and 13.1, MySQL 8.0, 8.4, 9.7 and 26.10, PostgreSQL 13, 14, 15, 16, 17, 18 and 19; older releases (MariaDB 10.2 – 10.4, PostgreSQL 12 and earlier, all past their end of life) were not. Every CI run repeats the suite against MariaDB 11.8 + PostgreSQL 17 (job `tests`) and against MariaDB 10.6 + PostgreSQL 13, MariaDB 10.11 + PostgreSQL 14, MariaDB 11.4 + PostgreSQL 15, MariaDB 12.3 + PostgreSQL 16, MySQL 8.0 + PostgreSQL 18, MySQL 8.4 + PostgreSQL 19 and MySQL 9.7 + PostgreSQL 17 (job `databases`). **MySQL 8.4 and later** do not load `mysql_native_password`: create the role with `grants --clear-text-password` (section 2.2). A server set up by `mariadb-install-db` (RHEL and its rebuilds, FreeBSD, the official tarballs) keeps a `test` database open to every account until `mariadb-secure-installation` removes it: the plugin works, `diag` fails until then (section 2.2). The per-user aliases (`galbridge_user_aliases`) are read with a window function (`ROW_NUMBER()`), which older MariaDB/MySQL servers lack: `diag` fails on such a server while the option is on |
| Database account | a dedicated read-only role on the `vmail` database (section 2.2) |
| Web server | the plugin serves nothing over HTTP; deny access to `plugins/galbridge/` (section 2.6) |

**Installation requirements** (decisions recorded in [docs/ARCHITECTURE.md](ARCHITECTURE.md);
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
  autocomplete is optional ([SECURITY.md](../SECURITY.md#hardening-checklist-for-administrators)). The
  plugin has no rate limiter of its own (section 10).
* Recommended: `$config['addressbook_sort_col'] = 'name';` (section 2.5).

## 2. Installation

All commands are run in the Roundcube installation directory: `/opt/www/roundcubemail` on iRedMail
(a symlink to the versioned directory, e.g. `/opt/www/roundcubemail-1.7.7`;
`/usr/local/www/roundcube` on FreeBSD), e.g.
`/var/www/roundcube` elsewhere. `www-data` below is the PHP-FPM / web server user: `www-data` on
Debian and Ubuntu, `nginx` on the RHEL family, `www` on FreeBSD and OpenBSD.

`sudo` is not part of iRedMail, and a Debian server installed with a root password does not have it.
`curl`, needed for the check of 2.6, comes with iRedMail only together with its optional netdata
component (on Debian 13 selected by default in the installer); on a server without it:
`apt install curl`. For `sudo` either `apt install sudo`, or write each `sudo -u www-data ...`
command of this document as `runuser -u www-data -- ...`
(util-linux, always installed), run as root from the same directory – e.g. `runuser -u www-data --
plugins/galbridge/bin/galbridge.sh diag`. `runuser` keeps root's
environment apart from `HOME`, `SHELL`, `USER` and `LOGNAME`, so run it from a plain root shell (no
`ROUNDCUBE_*` or `PG*` variables set). `su www-data -c ...` does not work: `www-data` has no login
shell.

**Checklist for a standard iRedMail 1.8 server** (Debian or Ubuntu, nginx, Roundcube in
`/opt/www/roundcubemail`, MariaDB/MySQL or PostgreSQL edition). Each step links to the subsection
with the exact commands and the reasons for them – read it before you run them:

1. [2.1](#21-install-the-plugin-files) – install the files as root: from the signed tag
   (`apt install git openssh-client`, `verify-tag`, compare the fingerprint, `git archive` into
   `plugins/galbridge`), or with Composer (`apt install composer git openssh-client` – git and
   `ssh-keygen` for the comparison with the signed tag; give `config/config.inc.php` to
   root and create an empty `config/galbridge.inc.php` first; `composer require` with
   `--ignore-platform-req=ext-ldap` in an interactive root shell, answer `n` when the installer asks
   whether to activate the plugin; compare the installed files with the signed tag).
2. [2.2](#22-create-the-dedicated-read-only-database-role) – check the plugin files with `find` and
   `namei`, generate the grant set with `grants --allow-root` for your edition (`--driver=mysql` or
   `--driver=pgsql`; the SQL into `/root/galbridge-grants.sql`, the DSN line into
   `/root/galbridge-dsn.txt`; on iRedMail the default connection limit 25), review it and apply it as
   the database administrator. **PostgreSQL edition:** the four `pg_hba.conf` lines, inserted
   **before** iRedMail's `local all all md5` line; check the file, reload PostgreSQL, and test that
   both `psql ... -d roundcubemail` logins of 2.2 are rejected.
3. [2.3](#23-configure-the-plugin) – `config/galbridge.inc.php` (owner root, group `www-data`, mode
   0640) from `config.inc.php.dist`, with the DSN line of `/root/galbridge-dsn.txt` – **without** its
   leading `-- `; `php -l` the file.
4. [2.4](#24-enable-the-plugin) – add `'galbridge'` to `$config['plugins']` and give
   `config/config.inc.php` to root (group `www-data`, mode 0640).
5. [2.5](#25-recommended-roundcube-settings) – uncomment iRedMail's
   `//$config['addressbook_sort_col'] = 'name';`.
6. [2.6](#26-web-server-keep-the-plugin-directory-unreachable) – the nginx deny rule in both Roundcube
   templates; for a webmail reachable from the Internet also the per-client limits; `nginx -t &&
   systemctl reload nginx`, then check with the two `curl` requests of 2.6 that the plugin source
   answers 403.
7. [2.7](#27-test-the-installation) – `diag`, `diag --user=<a login>` and `diag
   --url=https://<the host name of the certificate>/mail/ --cafile=/etc/ssl/certs/iRedMail.crt`: on a
   standard iRedMail server set up like this all three end with `Result: OK` – no FAIL, no WARN;
   look into any WARN. Then log in to the webmail and open the address book.

### 2.1 Install the plugin files

**Release archive** (from the signed tag)

```sh
apt install git openssh-client   # Debian/Ubuntu: a minimal server (and iRedMail) may have neither
v=1.0.8   # the release to install: https://github.com/takenek/galbridge/releases (only the latest is supported)
git clone --branch "v$v" https://github.com/takenek/galbridge.git /root/galbridge-src   # NOT below the web root
git -C /root/galbridge-src -c gpg.ssh.allowedSignersFile=/root/galbridge-src/.github/release-signers verify-tag "v$v"
```

Nothing is installed yet. The commands need `git` 2.34 or later and `ssh-keygen`, which git runs to check the SSH signature
(the first line installs both; without `ssh-keygen` `verify-tag` fails with `cannot run
ssh-keygen`).
`verify-tag` must succeed and print
`Good "git" signature for galbridge-release with RSA key SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`.
The allowed-signers file comes from the same clone as the tag, so it proves nothing by itself:
**compare the fingerprint** with SECURITY.md section "Release signing key" as GitHub shows it and
with the SSH signing keys of the maintainer's GitHub account – both commands must print the same
`SHA256:` value as `verify-tag` (they need only `python3`, which iRedMail has):

```sh
python3 -c 'import urllib.request as u; print([l for l in u.urlopen("https://raw.githubusercontent.com/takenek/galbridge/main/SECURITY.md").read().decode().splitlines() if l.startswith("| Fingerprint")][0])'
python3 -c 'import json, urllib.request as u; [print(k["key"]) for k in json.load(u.urlopen("https://api.github.com/users/takenek/ssh_signing_keys"))]' | ssh-keygen -lf -
```

The second command lists the account's signing key as `8192 SHA256:... no comment (RSA)`; on GitHub
its title is "MIME Shield release signing": the maintainer signs the releases of GAL Bridge and of
MIME Shield with the same key. If the tag does not
exist, `verify-tag` fails or the fingerprint differs, do not install (`rm -rf /root/galbridge-src`).
GitHub's "Source code" downloads of a release page are generated by GitHub and carry no signature: they are no
installation method. Only when `verify-tag` and both fingerprints agree, install the
files of the verified tag and remove the clone:

```sh
git -C /root/galbridge-src -c tar.umask=0022 archive --prefix=galbridge/ "v$v" | tar -x -C /opt/www/roundcubemail/plugins
rm -rf /root/galbridge-src
```

The directory **must** be named `plugins/galbridge`. `git archive` writes exactly what a release
contains: the plugin, its SQL templates and its documentation. `tar.umask=0022` matters: without
it git writes the archive with group-writable modes (0775 / 0664), and `tar` run by root restores
them as they are – `diag` would warn about a writable directory. Do **not** clone the repository
into `plugins/`: a clone also brings `tests/` – scripts that drop and create databases and
reconfigure a Dovecot server for the test suites – and the repository history, none of which belongs
on a mail server (`diag` warns about such a tree).

**Composer** (Roundcube's plugin installer)

Composer is not part of iRedMail or of a minimal Debian: `apt install composer` (Debian 13: Composer
2.8). On iRedMail run it as **root** – the Roundcube tree, `vendor/` included, belongs to root and
`composer.json` / `composer.lock` have mode 0000, so no other account can use Composer there – and in
an **interactive** shell: Composer warns `Do not run Composer as root/super user!` and asks
`Continue as root/super user [yes]?` – answer `yes`. Run as root without a terminal (a script,
`ssh host '...'`, cron, a configuration-management tool), Composer disables its plugins: Roundcube's
plugin installer then never runs, and `composer require` succeeds but puts the package into
`vendor/takenek/galbridge` instead of `plugins/galbridge` (check: `ls -d plugins/galbridge`); Composer
says so with `Composer plugins have been disabled for safety in this non-interactive session.` A
script sets `COMPOSER_ALLOW_SUPERUSER=1` – the installer then also activates the plugin (the default
answer of its question, below). The same holds for **every** later `composer update`, `composer
install` and `composer remove` in this tree: without a terminal (and without
`COMPOSER_ALLOW_SUPERUSER=1`) `update` and `install` put a second copy into `vendor/takenek/galbridge`
and leave `plugins/galbridge` as it was, and `remove` leaves `plugins/galbridge` in place (sections
5 to 7; `diag` warns about such a copy, "composer copy").

Before the first Composer run give `config/config.inc.php` to root (the ownership step of 2.4): the
plugin installer loads that file inside the root Composer process, and iRedMail leaves it to the PHP
user. Create the plugin's configuration **outside** the package – `config/galbridge.inc.php`, owner
root, group of the PHP user, mode `0640` (section 2.3; it stays empty until then, the DSN comes from
`grants`, 2.2) – then install the release:

```sh
cd /opt/www/roundcubemail
chown root:www-data config/config.inc.php && chmod 0640 config/config.inc.php   # iRedMail, as in 2.4
install -o root -g www-data -m 0640 /dev/null config/galbridge.inc.php        # filled in in 2.3
composer require --prefer-dist --ignore-platform-req=ext-ldap "takenek/galbridge:^1.0"   # a tagged release; never dev-main in production
```

`--ignore-platform-req=ext-ldap` is needed on iRedMail's MariaDB/MySQL and PostgreSQL editions (and
on any server without the PHP `ldap` extension): without it this `composer require` stops with
`pear/net_ldap2 ... requires ext-ldap * -> it is missing from your system` and changes nothing.
Roundcube's own `composer.lock` holds its LDAP libraries (`kolab/net_ldap3`, `pear/net_ldap2`),
which declare the PHP `ldap` extension, and iRedMail installs that extension only for its OpenLDAP
edition. The option exempts that one requirement – the LDAP libraries are not touched, and nothing of
GAL Bridge needs `ldap` (never `--ignore-platform-reqs`, which drops every requirement, the PHP
version included). Give it to every later `composer update` and `composer remove` as well – or
install the extension (`apt install php-ldap`) and leave it out.

The installer then asks `Do you want to activate the plugin galbridge? [Y|n]` (Enter means yes):
answer `n` and enable the plugin in 2.4, once 2.2 and 2.3 are done. Activated now, it runs with the
empty configuration file – every book disabled, and `config_invalid` in its log – until 2.3.

The package is listed on Packagist ([takenek/galbridge](https://packagist.org/packages/takenek/galbridge),
updated from GitHub at every push). Production installations take a tagged release by version
constraint – but
**Composer does not verify tag signatures**, and Packagist publishes no checksum of the archive it
installs: compare the installed files with a verified clone. Run the commands of the release archive
above – the first block, `verify-tag` and the fingerprint comparison, **not** the `git archive`
block – with `v` set to the version on the
`versions` line of `composer show takenek/galbridge` without the `*` and the leading `v`
(`versions : * vX.Y.Z` gives `v=X.Y.Z`; like every Composer command here, run it as root in an
interactive shell – without a terminal it disables its plugins and answers `Package
"takenek/galbridge" not found`), then, in the Roundcube directory:

```sh
mkdir -m 0700 /root/galbridge-check
git -C /root/galbridge-src archive "v$v" | tar -x -C /root/galbridge-check
diff -r /root/galbridge-check/ plugins/galbridge/ && echo identical   # must print only "identical"
git -C /root/galbridge-src rev-parse "v$v^{commit}"                  # must be the <commit> on the "source" line of: composer show takenek/galbridge
rm -rf /root/galbridge-check /root/galbridge-src
```

With the configuration in the plugin directory `diff` also reports `Only in plugins/galbridge/:
config.inc.php`; nothing else may differ. `composer show takenek/galbridge` names the source as
`source   : [git] https://github.com/takenek/galbridge.git <commit>`: the commit checks Packagist's
metadata, the `diff` checks the files (see [docs/RELEASING.md](RELEASING.md), section 2 for
installation, section 3 for the tag signature).
For deliberate testing of unreleased code only, add the repository as a Composer VCS repository and
pin an explicitly reviewed commit:

```sh
composer config repositories.galbridge vcs https://github.com/takenek/galbridge
composer require "takenek/galbridge:dev-main#<commit-sha>"
```

The installer (roundcube/plugin-installer) checks the Roundcube version against `min-version` /
`max-version`, asks whether to activate the plugin (above: answered yes, it has added `'galbridge'`
to `$config['plugins']` and nothing else – do the rest of 2.4 all the same) and copies `config.inc.php.dist` to
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
2.2; `diag` names them on its "plugin code" line). Run Composer as root (on iRedMail the only
account that can), or follow every `composer
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
clean – then generate the statements as root, with the one `grants` line of your edition:

```sh
cd /opt/www/roundcubemail
find -L plugins/galbridge \( ! -user root -o -perm /022 \) -print   # must print nothing (GNU find)
namei -l "$PWD/plugins/galbridge/bin/galbridge.sh"   # every line from / down: owner root, no "w" in the group or other columns (excepted: /tmp-style "t", and symbolic links such as roundcubemail -> roundcubemail-1.7.7, always lrwxrwxrwx)
umask 077     # the SQL file must be readable by root only
plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql > /root/galbridge-grants.sql 2> /root/galbridge-dsn.txt   # MariaDB/MySQL edition
# PostgreSQL edition: this line instead of the one above (both write the same two files)
# plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=pgsql > /root/galbridge-grants.sql 2> /root/galbridge-dsn.txt
```

**The connection limit** (`--connection-limit`, default 25) bounds the role's simultaneous
connections; a request opens at most one. Size it to **at most** the PHP-FPM pool of Roundcube
(`pm.max_children`) **and** to what the database server can spare. iRedMail runs Roundcube in a pool
of `pm.max_children = 100` (`/etc/php/8.4/fpm/pool.d/www.conf` on Debian 13), while both of its
database servers keep their small defaults on Debian and Ubuntu – MariaDB `max_connections = 151`,
PostgreSQL 100 (three of them reserved for superusers) –, shared with Roundcube's own connection of
every request, Dovecot, Postfix, iRedAPD, Amavisd and iRedAdmin. **Keep the default 25 on iRedMail**;
a larger limit only after raising `max_connections` (MariaDB: a file in `/etc/mysql/mariadb.conf.d/`,
PostgreSQL: `/etc/postgresql/17/main/postgresql.conf`; then restart the server) – otherwise logins of
those services can fail under load ("Too many connections", "too many clients already"). The current
value: `mysql -Ne 'SELECT @@max_connections'` or `runuser -u postgres -- psql -XAtc 'SHOW
max_connections'`. Change the limit later with `grants --grants-only --connection-limit=N` (below) –
and give every later `--grants-only` run the same `--connection-limit`, or the limit goes back to 25
(the second line of the output says which it applies).

The SQL goes into the first file; the DSN line with the password goes to standard error – here into
`/root/galbridge-dsn.txt`, readable by root only under the same `umask 077` (without `2>` it appears
on the terminal) – and never into the SQL file. It is printed as an SQL comment, after an empty line
and an instruction line: the line that starts with `-- $config['galbridge_db_dsn']` goes into the
configuration (2.3) **without the leading `-- `** – with it, `config/galbridge.inc.php` is no PHP
file that parses, and once the plugin is enabled every webmail request of a logged-in user stops with
a parse error. 2.3 puts it in place without showing it and `shred`s the file.
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

```sh
head -n 2 /root/galbridge-grants.sql
grep -n '\\' /root/galbridge-grants.sql
diff <(grep -v -e '^--' -e '^$' plugins/galbridge/SQL/grants.mysql.sql) <(grep -v -e '^--' -e '^$' /root/galbridge-grants.sql)
# PostgreSQL edition: grants.postgres.sql in place of grants.mysql.sql
```

What they print:

1. the second line names the role and the form you asked for (creates the role / NEW PASSWORD /
   re-applies ... to the EXISTING role – that form also names the connection limit it applies); the
   comments of the template further down keep the default of 25, the statements carry your
   `--connection-limit`;
2. `grep -n '\\' /root/galbridge-grants.sql` prints nothing: the output holds no backslash, so no
   psql meta-command and no short-form mysql command (`grants` refuses to print one, and any line
   that starts with a long-form command of the mysql client such as `system` or `source`);
3. the statements are those of the template apart from role, account host, database, connection
   limit, password verifier and the `[role]` block:
   `diff <(grep -v -e '^--' -e '^$' plugins/galbridge/SQL/grants.mysql.sql) <(grep -v -e '^--' -e '^$' /root/galbridge-grants.sql)`
   (`grants.postgres.sql` for PostgreSQL) shows nothing else – compare with the template of the
   verified release archive when the plugin files did not pass the check above. The account line
   of the difference carries the password **verifier** (`CREATE USER ... IDENTIFIED WITH
   mysql_native_password AS '*...'` or `CREATE ROLE ... PASSWORD 'SCRAM-SHA-256$...'`), which counts
   as a secret: run the
   `diff` where nobody watches the terminal, and do not paste its output anywhere. With the default
   options (`--role`, `--account-host`, `--db` and `--connection-limit` add the lines they change; a
   non-local `--account-host` also adds the line `ALTER USER ... REQUIRE SSL`, and
   `--clear-text-password` puts its two guard lines and the statement with the password itself in
   place of the account line), what the `diff` shows depends on the form that the second line names
   (item 1):
   * the **default** form (a new role – the first installation): one changed line, the account line,
     which carries the verifier where the template has its `CHANGE_ME_...` placeholder –
     MariaDB/MySQL the `CREATE USER` line (`1c1`), PostgreSQL the `CREATE ROLE` line;
   * the `--grants-only` form has no account line, and every difference lies in the `[role]` block.
     MariaDB/MySQL: the `diff` shows only the template's `CREATE USER` line as missing. PostgreSQL:
     the existence check is turned round – `IF NOT EXISTS ...` and `RAISE EXCEPTION 'role
     "galbridge" does not exist: ...'` in place of the template's `IF EXISTS ...` / `already exists`
     lines –, the template's `CREATE ROLE` line is missing, and one line is added, `ALTER ROLE
     galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;`
     (the attributes asserted again for the existing role; your `--role` in place of `galbridge`);
   * the `--reset-password` form: MariaDB/MySQL two lines in place of the template's `CREATE USER`
     line, `CREATE USER IF NOT EXISTS ...` and `ALTER USER ...`, both with the verifier; PostgreSQL
     the differences of `--grants-only`, except that the role is created (without a password) where
     `--grants-only` raises its exception, and a second added line sets the password, `ALTER ROLE
     galbridge WITH LOGIN PASSWORD 'SCRAM-SHA-256$...'`.

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

Size the connection limit as described after the `grants` commands above (at most the PHP-FPM pool
of Roundcube, `pm.max_children`, and what the database server can spare – on iRedMail the default
25): a request opens at most one directory
connection, and only when it actually reads the directory. A request beyond the limit gets "The
directory is temporarily unavailable." for that request only (log `db_busy`; the next request tries
again, section 3.9). `diag` shows the limit of the account on both engines and warns when it is
unlimited or, above 25, takes more than a quarter of the server's connections. Keep the per-client
concurrency limit of the reverse proxy well below it
([SECURITY.md](../SECURITY.md#hardening-checklist-for-administrators)), so that one client cannot hold
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

**PostgreSQL: keep the role to the vmail database** in `pg_hba.conf` – on Debian 13 (iRedMail
1.8.11) `/etc/postgresql/17/main/pg_hba.conf`; `runuser -u postgres -- psql -XAtc 'SHOW hba_file'`
names it on any system. iRedMail comments out the distribution's lines and ends the file with three
of its own (written with several spaces between the fields – find them with `grep -nE
'^(local|host)[[:space:]]+all[[:space:]]+all' /etc/postgresql/17/main/pg_hba.conf`, not by searching
for the text below):

```
local all     postgres   peer
local all     all                 md5
host  all     all     0.0.0.0/0   md5
```

`local all all md5` accepts a password login over the unix socket (`/var/run/postgresql`), the
catch-all `host` line one from every address `listen_addresses` lets in – both for **every**
database: `PUBLIC` holds `CONNECT` on the others (`roundcubemail`, `amavisd`, `iredadmin`, ...), where
on PostgreSQL 14 and older the role may create objects as well. PostgreSQL uses the **first** line
that matches the connection type, database and role: a `host` line never applies to a socket
connection, and a line after a matching one never applies. Insert these four lines directly
**before** iRedMail's `local all all md5` line (and so also before its catch-all):

```
local      all    galbridge                                       reject          # no login over the unix socket
host       vmail  galbridge  127.0.0.1/32                         scram-sha-256   # Roundcube on this host
host       all    galbridge  0.0.0.0/0                            reject          # no other database, no other address
host       all    galbridge  ::/0                                 reject
```

On iRedMail this finds the file and inserts them there (once; a file that already has a `local all
galbridge` line is left alone):

```sh
f=$(runuser -u postgres -- psql -XAtc 'SHOW hba_file')   # Debian 13 (iRedMail 1.8.11): /etc/postgresql/17/main/pg_hba.conf
grep -qE '^local[[:space:]]+all[[:space:]]+galbridge[[:space:]]' "$f" || sed -i -E '0,/^local[[:space:]]+all[[:space:]]+all[[:space:]]/s//local      all    galbridge                                       reject          # no login over the unix socket\
host       vmail  galbridge  127.0.0.1\/32                         scram-sha-256   # Roundcube on this host\
host       all    galbridge  0.0.0.0\/0                            reject          # no other database, no other address\
host       all    galbridge  ::\/0                                 reject\
&/' "$f"
grep -nvE '^[[:space:]]*(#|$)' "$f"   # your four lines directly above iRedMail's "local all all md5"
```

With the socket DSN `unix(/var/run/postgresql)` (config.inc.php.dist) instead of `127.0.0.1`, put
`local vmail galbridge scram-sha-256` on the line before the `local ... reject` line – **before**
you reload: the `reject` line alone refuses that DSN, too. A file with an
error is not loaded – PostgreSQL keeps the old rules and only writes a log line –, so check it, then
reload and test as root:

```sh
runuser -u postgres -- psql -X -c "SELECT line_number, type, database, user_name, address, auth_method, error FROM pg_hba_file_rules WHERE error IS NOT NULL OR 'galbridge' = ANY(user_name)"   # your four lines, no error
systemctl reload postgresql
psql -X -w -h 127.0.0.1 -U galbridge -d roundcubemail -c ''   # must fail: pg_hba.conf rejects connection for host "127.0.0.1", user "galbridge" ...
psql -X -w -U galbridge -d roundcubemail -c ''                 # must fail: pg_hba.conf rejects connection for host "[local]", user "galbridge" ...
```

Over TCP psql may print the refusal twice (`... SSL encryption`, then `... no encryption`: iRedMail's
PostgreSQL has `ssl = on`); both are the expected refusal. `fe_sendauth: no password supplied`
instead means that the `reject` line does not apply: it is not
loaded, or it sits below iRedMail's lines. `diag` cannot read `pg_hba.conf`: its INFO "other
databases" comes from the `CONNECT` privilege and stays after this change – the test above is the
check.

For a remote Roundcube host replace the `host vmail ... 127.0.0.1/32` line by these two (the server
must also listen on that interface, `listen_addresses`, and have `ssl = on`):

```
hostssl    vmail  galbridge  <address of the Roundcube host>/32  scram-sha-256
hostnossl  vmail  galbridge  0.0.0.0/0                           reject
```

### 2.3 Configure the plugin

```sh
cd /opt/www/roundcubemail
# also after a Composer install: it replaces the still empty config/galbridge.inc.php of 2.1
install -o root -g www-data -m 0640 plugins/galbridge/config.inc.php.dist config/galbridge.inc.php
```

In the copied file replace the line `$config['galbridge_db_dsn'] = '';` by the DSN line that
`grants` printed (2.2) – **without** its leading `-- ` – and leave everything else at its default.
From `/root/galbridge-dsn.txt` (2.2) the line goes in without showing the password (the password is
hexadecimal, `sed` needs no escaping; nothing is changed when the file holds no DSN line), and
`php -l` checks the result without printing it:

```sh
grep -q "^-- \$config\['galbridge_db_dsn'\] = '" /root/galbridge-dsn.txt && sed -i "s|^\$config\['galbridge_db_dsn'\] = '';\$|$(grep -o "\$config\['galbridge_db_dsn'\] = '[^']*';" /root/galbridge-dsn.txt)|" config/galbridge.inc.php
grep -c "^\$config\['galbridge_db_dsn'\] = '[a-z]*://" config/galbridge.inc.php   # must print 1 (0: no DSN line in the file - paste it by hand)
php -l config/galbridge.inc.php                                                  # must print "No syntax errors detected ..."
shred -u /root/galbridge-dsn.txt
```

A parse error in this file stops every webmail page of a logged-in user once the plugin is enabled,
not just the address books: run `php -l` after every edit.

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

In `config/config.inc.php` add `'galbridge'` to the plugin list – on iRedMail 1.8.11 the line
becomes:

```php
$config['plugins'] = array('managesieve', 'password', 'zipdownload', 'galbridge');
```

(a Composer install whose activation question was answered with yes has done this already; the rest
of this section still applies). On iRedMail this adds it – `sed` changes the list only when it has
the form above (a list written in another form: add `'galbridge'` by hand):

```sh
cd /opt/www/roundcubemail
grep -q "'galbridge'" config/config.inc.php || sed -i "s/^\(\$config\['plugins'\] = array('.*'\));\$/\1, 'galbridge');/" config/config.inc.php
grep -n "^\$config\['plugins'\]" config/config.inc.php   # the plugin list ends with 'galbridge'
```

Remove `sql_global_addressbooks` from the list if it is still there
([docs/MIGRATION.md](MIGRATION.md)). GAL Bridge adds its books to `autocomplete_addressbooks`
at runtime (only for compose, Redirect-dialog and autocomplete requests); you do not need to list
`galbridge_domain` or `galbridge_global` there yourself.

**iRedMail installs `config/config.inc.php` as the PHP user** (`www-data:www-data`, mode `0600`).
That file can set every option – `galbridge_*` options, `des_key`, the plugin list – so the web
application could rewrite the directory policy; `diag` warns (`core config file ... owned by uid
33`). Roundcube only reads it. Give it to root and let the PHP group read it:

```sh
chown root:www-data config/config.inc.php && chmod 0640 config/config.inc.php
php -l config/config.inc.php
```

### 2.5 Recommended Roundcube settings

```php
$config['addressbook_sort_col'] = 'name';
```

iRedMail ships this line commented out in `config/config.inc.php`
(`//$config['addressbook_sort_col'] = 'name';`): remove the leading `//` rather than adding a second
line:

```sh
sed -i "s|^//\(\$config\['addressbook_sort_col'\] = 'name';\)\$|\1|" config/config.inc.php
grep -n "addressbook_sort_col" config/config.inc.php   # one line, without the leading //
```

 Roundcube's default is `'surname'`, and open-source iRedAdmin fills only
`mailbox.name`. GAL Bridge sorts its own lists with fallbacks (surname, then name, then address), but
Roundcube re-sorts every search result in PHP by `addressbook_sort_col` (address-book search,
compose contact list and compose search); with empty last names the results then come out in
e-mail address order. `diag` warns for any value other than `'name'`. Users can change the sort
column in their preferences unless you add `addressbook_sort_col` to `$config['dont_override']`.
**Never put `galbridge_*` names into `dont_override`**: Roundcube then writes their current value
into the preferences of every user who saves settings – for an option set through an environment
variable the value itself, the DSN with the database password ([SECURITY.md](../SECURITY.md); `diag`
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
  `roundcube-subdomain.tmpl` (in `/etc/nginx/templates/` on Linux) contain a location –
  `^/mail/static.php/(.*)` in `roundcube.tmpl`, `^/static.php/(.*)` in `roundcube-subdomain.tmpl` –
  that `alias`es the request straight to the Roundcube directory. That bypasses
  the type filter of core `static.php`; the templates deny `plugins/*/config.inc.php*` and a few
  top-level directories, but not other plugin files. The PHP source of the plugin, the SQL templates
  and any file an administrator leaves in `plugins/galbridge/` can then be downloaded. Add this rule
  to both templates **before** every `static.php` location – directly below the line `# Block
  direct access to directories and files.` at the top of each template (nginx uses the first
  matching regular-expression location in the order they appear):

  ```nginx
  location ~ ^/(mail/)?(static\.php/)?plugins/galbridge(-[0-9]+)?/ { deny all; }
  ```

  On iRedMail this puts it there (once; a template that already has it is left alone):

  ```sh
  for t in /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl; do grep -q 'plugins/galbridge' "$t" || sed -i '/^# Block direct access to directories and files\.$/a location ~ ^/(mail/)?(static\\.php/)?plugins/galbridge(-[0-9]+)?/ { deny all; }' "$t"; done
  grep -n 'plugins/galbridge' /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl   # one line in each (line 5 when this command put it there; any line before the first static.php location is right)
  ```

  The commands use full paths and do not change the working directory: the `diag` commands below
  run from the Roundcube directory.

  The optional `-<digits>` also covers the backup copy of the plugin directory that Roundcube's
  plugin installer makes during `composer update` (`plugins/galbridge-<20 digits>`, with the
  configuration and its password in it; section 2.1). That copy contains the `.htaccess` as well,
  so Apache denies it too.

  Reload (`nginx -t && systemctl reload nginx`), wait a few seconds – until nginx's old worker
  processes are gone a request can still be answered with the previous configuration – and check
  that the plugin source is no longer served (for `roundcube-subdomain.tmpl` leave out the `/mail`
  prefix). Both requests must answer
  `403` (or `404`); a `200` means the rule is not effective:

  ```sh
  curl -s -o /dev/null -w '%{http_code}\n' https://mail.example.com/mail/static.php/plugins/galbridge/lib/autoload.php
  curl -s -o /dev/null -w '%{http_code}\n' https://mail.example.com/mail/plugins/galbridge/lib/autoload.php
  ```

  With the self-signed certificate that iRedMail installs (`/etc/ssl/certs/iRedMail.crt`, issued
  for the server's host name, until a trusted certificate replaces it) `curl` prints `000`: add
  `--cacert /etc/ssl/certs/iRedMail.crt` and use that host name in the address – never `127.0.0.1`
  or `localhost`, which the certificate does not name:

  ```sh
  curl -s -o /dev/null -w '%{http_code}\n' --cacert /etc/ssl/certs/iRedMail.crt https://mail.example.com/mail/static.php/plugins/galbridge/lib/autoload.php
  curl -s -o /dev/null -w '%{http_code}\n' --cacert /etc/ssl/certs/iRedMail.crt https://mail.example.com/mail/plugins/galbridge/lib/autoload.php
  ```

  The name (iRedMail: the server's
  `hostname -f`) is printed by `openssl x509 -in /etc/ssl/certs/iRedMail.crt -noout -subject
  -nameopt multiline | sed -n 's/^ *commonName *= *//p'`; when it does not resolve to this server on
  the server itself, add it to `/etc/hosts`.

  **iRedMail upgrades can replace these templates**: some of iRedMail's upgrade tutorials replace
  the whole content of `roundcube.tmpl` and `roundcube-subdomain.tmpl` (the one from 1.8.1 to 1.8.2
  did). After every iRedMail upgrade check that the rule is still there, put it back if not, and run
  `diag --url=` (below; section 5).

* `diag --url=` makes the same check from the server side for the usual paths (section 2.7):

  ```sh
  cd /opt/www/roundcubemail
  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/
  # iRedMail's self-signed certificate: name it, the certificate is still verified; the address
  # names the host name of the certificate (above), not 127.0.0.1 or localhost
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
* **Per-client limits** (section 1 – required when the webmail is reachable from the Internet,
  iRedMail's default; iRedMail sets none): the nginx lines are in the
  [hardening checklist of SECURITY.md](../SECURITY.md#hardening-checklist-for-administrators). On
  iRedMail the two `*_zone` lines go into a new file `/etc/nginx/conf-enabled/roundcube-limits.conf`
  (iRedMail's `nginx.conf` includes `conf-enabled/*.conf` inside `http {}`), and the `limit_req` /
  `limit_conn` lines at the top of the PHP location of both templates – `location ~
  ^/mail/(.*\.php)$` in `roundcube.tmpl`, `location ~ ^/(.*\.php)$` in `roundcube-subdomain.tmpl`
  (not at server level, where they would also count static files and iRedAdmin). This puts them
  there with the values of SECURITY.md, once: the zones are left alone when nginx already reads them
  from any file (`nginx -T` shows the whole configuration – the 1.0.2 and 1.0.3 checklist named no
  file for them), and a template that already has a `limit_req` or `limit_conn` line is left
  alone:

  ```sh
  f=/etc/nginx/conf-enabled/roundcube-limits.conf
  nginx -T 2>/dev/null | grep -qE 'zone=rc_(php|conn):' || printf '%s\n' 'limit_req_zone  $binary_remote_addr zone=rc_php:10m rate=10r/s;' 'limit_conn_zone $binary_remote_addr zone=rc_conn:10m;' > "$f"
  for t in /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl; do grep -q 'limit_req\|limit_conn' "$t" || sed -i '/^location ~ ^\/\(mail\/\)\{0,1\}(\.\*\\\.php)\$ {$/a\    limit_req  zone=rc_php burst=40 nodelay;\n    limit_conn rc_conn 8;' "$t"; done
  grep -n -A2 'php)\$ {$' /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl   # the two lines directly below the PHP location of each template
  ```

  Keep the `limit_conn` value well below the role's connection limit (2.2; 8 of the default 25).
  Then `nginx -t && systemctl reload nginx` – one reload for the deny rule above and the limits.
  Like the deny rule, the two template lines are lost when an iRedMail upgrade replaces the
  templates: run the block again. The limits are per client address: every user behind one NAT
  address (an office, a VPN exit) shares them, and nginx answers a request beyond them with 503 –
  SECURITY.md says how to size or exempt such networks.

### 2.7 Test the installation

```sh
cd /opt/www/roundcubemail
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/   # self-signed certificate: add --cafile=/etc/ssl/certs/iRedMail.crt and use the host name of the certificate, not 127.0.0.1 (2.6)
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --strict --url=https://mail.example.com/mail/   # automated checks: WARN = exit code 2 (same --cafile= as above)
```

For automated checks (cron, monitoring) give `--strict` the same `--url=` (and `--cafile=`) as the
third command: without `--url` it checks no web access – it prints `Result: OK (not checked: web
access - run with --url)` and exits 0 – so it would not notice the deny rule of 2.6 lost in an
iRedMail upgrade. `diag` checks the plugin and its installation; the general web server and
Roundcube items of the [hardening checklist of SECURITY.md](../SECURITY.md#hardening-checklist-for-administrators)
(HTTPS only, `use_https`, `session_samesite` – iRedMail 1.8.11 sets neither of the two options) are
not part of its checks.

Without `sudo`: `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag ...`, the same
options (section 2, introduction). `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh help`
lists every command and option (as root the tool prints only its refusal, below). Run it as the PHP / web server user from the Roundcube directory
(it must read the plugin configuration). If the plugin directory itself is a symlink, set the
Roundcube root explicitly:
`sudo -u www-data env ROUNDCUBE_INSTALL_PATH=/opt/www/roundcubemail plugins/galbridge/bin/galbridge.sh diag`
– iRedMail's symlinked Roundcube directory (`/opt/www/roundcubemail` → `roundcubemail-1.7.7`) needs
nothing of the kind.

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

* **Versions**: GAL Bridge – the release of the files in the plugin directory (OK line `GAL Bridge
  X.Y.Z`); Roundcube – OK for the newest 1.7.x release this version knows (and for later ones),
  WARN for an older patch release (update the **core**: the later releases fix security problems of
  Roundcube) and for a core that reports `1.7-git`, FAIL for any other branch; the PHP branch and
  its security support; the iRedMail release; an INFO `process` line saying that the PHP version,
  extensions, `php.ini` settings and `ROUNDCUBE_*` / `PG*` variables `diag` reports are those of the
  **command line** PHP it runs in – the web server's PHP (a PHP-FPM pool) has its own: compare
  `php-fpm -m` (on Debian and Ubuntu the binary carries its version: `php-fpm8.4 -m` on Debian 13)
  and the pool's `env[...]` / `clear_env` (Debian 13: `/etc/php/8.4/fpm/pool.d/`).
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
  (delete it; section 2.1); WARN `composer copy` for a second copy of the package in
  `vendor/takenek/galbridge`, which a Composer run as root without a terminal leaves while it does
  not change `plugins/galbridge` (section 7); whether the PHP user can change the plugin code (WARN): every directory and every
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
  the Roundcube database cannot be read from the command line; otherwise an OK `stored IMAP hosts`
  line with the number of accounts). For every server of `imap_host` an
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
  when the server **refuses** `SELECT password` for lack of privilege (any other error is a FAIL with its SQLSTATE: it proves nothing) –
  so on PostgreSQL every `diag` run leaves one line `galbridge@vmail ERROR:  permission denied for
  table mailbox` in the server log (without the statement: the role's `log_min_error_statement` is
  `panic`); that line is this probe, the plugin's web requests never send it; the
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
  account on both engines** (WARN when unlimited, and when a limit above the default 25 takes more
  than a quarter of the connections the server has for ordinary roles – `max_connections`, on
  PostgreSQL minus the reserved ones; section 2.2); whether the server log can record the statements of the role
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
  `mailbox(domain)` and – PostgreSQL only: MariaDB/MySQL show the indexes of a table only to a role
  with a privilege on it, and the plugin's role reads the view – `forwardings(forwarding)`.
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

Excerpt of a run on a standard iRedMail 1.8.11 server, PostgreSQL edition (installed as this section
describes):

```
== vmail database
  [ OK ] DSN                                pgsql://galbridge@127.0.0.1/vmail
  [ OK ] connect                            pgsql (6 ms)
  [ OK ] password column                    not readable (refused by the server)
  [ OK ] write privileges                   none (every relation and sequence of the schema, PUBLIC included)
  [ OK ] role attributes                    LOGIN only (no SUPERUSER, CREATEROLE, CREATEDB, REPLICATION, BYPASSRLS)
  [ OK ] database owner                     not the owner of the database
  [ OK ] owned objects                      none
  [ OK ] role memberships                   none
  [ OK ] privileges outside                 none outside the vmail schema and database
  [INFO] other databases                    the role may connect to amavisd, fail2ban, iredadmin, iredapd, postgres, roundcubemail (through PUBLIC as a rule): limit it to vmail in pg_hba.conf (README 2.2) - this tool cannot read pg_hba.conf, so the line stays once that is done; README 2.2 shows how to test it
  [INFO] account TLS                        enforced by pg_hba.conf (hostssl / hostnossl ... reject, README 2.2), which the role cannot read
  [ OK ] schema public                      no CREATE privilege (its schema, schema public, the other schemas and the database)
  [ OK ] statement time (this session)      3s (galbridge_db_timeout)
  [ OK ] ctype                              en_US.UTF-8
  [ OK ] connection limit                   25 (at most the PHP-FPM pm.max_children of Roundcube and what the server's max_connections can spare - README 2.2)
  [ OK ] statement logging                  off for this role (its statements contain search terms)
  [ OK ] secret columns                     not visible (in any relation of the database)
  [ OK ] table forwardings                  not readable by the role (it reads the view galbridge_forwardings, checked below)
  [ OK ] view galbridge_forwardings         holds alias, alias-member and list rows only (29 rows: built afresh for every statement that reads it)
  [ OK ] view galbridge_forwardings         a security barrier fenced with OFFSET 0, owned by the administrator: no condition of the role sees a private row

== User jan.kowalski@example.com
  [ OK ] home domain                        example.com
  [ OK ] galbridge_domain                   1 domain(s), groups: department (4 groups), search-only: no
  [INFO]   persons                          8 (0 ms)
```

`diag` never prints the database password, the value of an environment override or directory
contents (counts only – except the addresses of the `entries not offered`, which you need in order
to correct them). Everything it prints, and everything that reaches standard error while it
runs (messages of Roundcube, of the database drivers and of PHP), is neutralised: control, bidi,
zero-width and line-separator characters and invalid UTF-8 appear as visible escapes such as `\x1B`
or `\u{202E}`, so no value – a database server's answer, an exception text, a row of Roundcube's
`users` table, a file name – can hide or rewrite lines on your terminal. The last line sums up:
`Result: OK`, `Result: OK with N warning(s) - read the WARN lines`, with `--strict`
`Result: N warning(s) - --strict counts them as problems`, `Result: N problem(s) found` or
`Result: aborted`; without `--url` the first three end with "(not checked: web access - run with
--url)". Exit code 0 = no FAIL
(warnings do not change it unless `--strict` is given), 1 = usage error or refused as root, 2 =
problems found (with `--strict` also WARN lines), an unexpected error or a PHP fatal error
(`Result: aborted`).
