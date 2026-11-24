# GAL Bridge (`galbridge`) – Global Address List for Roundcube 1.7

[![CI](https://github.com/takenek/galbridge/actions/workflows/ci.yml/badge.svg)](https://github.com/takenek/galbridge/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/takenek/galbridge.svg)](https://packagist.org/packages/takenek/galbridge)
[![License](https://img.shields.io/packagist/l/takenek/galbridge.svg)](LICENSE)

GAL Bridge gives Roundcube Webmail **1.7** (every 1.7.x release) a read-only **Global Address
List** read directly from the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and
PostgreSQL editions). It uses
only the official plugin API: the `addressbooks_list` and `addressbook_get` hooks, the
`addressbook_export` hook (vCard export only) and an `rcube_addressbook` backend. It changes nothing
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
  groups; in compose and in the Redirect dialog), the compose contact list and its search,
* **tenant isolation by construction**: what a user can see comes only from the authenticated login
  and the administrator configuration, never from request parameters (`_source`, `_gid`, `_cid`,
  `_search`, `_q`, `_page`),
* **least privilege, enforced**: a dedicated read-only database role with column-level grants that
  reads the forwardings through a row-restricting view; the plugin refuses iRedMail's own roles and
  any role that can see `mailbox.password` or the `forwardings` table,
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
* **Verified TLS** for a `vmail` database on another host (section 3.9).
* **The plugin directory is not served by the web server** (section 2.6; `diag --url=` checks it),
  and the plugin is installed from a release, not as a git clone (section 2.1).
* **A request rate limit** on `_action=autocomplete` in the reverse proxy when the webmail is
  reachable from the Internet ([SECURITY.md](SECURITY.md#hardening-checklist-for-administrators)):
  the plugin has no rate limiter of its own (section 10).
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
git clone --branch v1.0.1 https://github.com/takenek/galbridge.git /root/galbridge-src   # NOT below the web root
git -C /root/galbridge-src verify-tag v1.0.1          # must succeed: docs/RELEASING.md section 3
git -C /root/galbridge-src -c tar.umask=0022 archive --prefix=galbridge/ v1.0.1 | tar -x -C /opt/www/roundcubemail/plugins
rm -rf /root/galbridge-src
```

The directory **must** be named `plugins/galbridge`. `git archive` writes exactly what a release
contains: the plugin, its SQL templates and its documentation. `tar.umask=0022` matters: without
it git writes the archive with group-writable modes (0775 / 0664), and `tar` run by root restores
them as they are – `diag` would warn about a writable directory. An archive that was made
otherwise (the "Source code" download of the release page) is extracted with
`tar -x --no-same-permissions`, or followed by `chmod -R go-w plugins/galbridge`. Do **not** clone the repository into
`plugins/`: a clone also brings `tests/` – scripts that drop and create databases and reconfigure a
Dovecot server for the test suites – and the repository history, none of which belongs on a mail
server (`diag` warns about such a tree). If the tag does not exist or `verify-tag` fails, do not
install.

**Composer** (Roundcube's plugin installer)

```sh
cd /opt/www/roundcubemail
composer require "takenek/galbridge:^1.0"    # a tagged release; never dev-main in production
```

Production installations use a signed, tagged release by version constraint; see
[docs/RELEASING.md](docs/RELEASING.md) (section 2 for installation, section 3 for verifying the tag
signature). For deliberate testing of unreleased code only, add the repository as a Composer VCS
repository and pin an explicitly reviewed commit:

```sh
composer config repositories.galbridge vcs https://github.com/takenek/galbridge
composer require "takenek/galbridge:dev-main#<commit-sha>"
```

The installer (roundcube/plugin-installer) checks the Roundcube version against `min-version` /
`max-version`, asks whether to enable the plugin (then skip 2.4) and copies `config.inc.php.dist` to
`plugins/galbridge/config.inc.php` unless that file or `config/galbridge.inc.php` already exists.
The copy is created with the umask of the user running Composer: set its owner and mode as in 2.3
**before** you put the database password into it. GAL Bridge has no database tables, so nothing is
initialised. `composer update` keeps `plugins/galbridge/config.inc.php`.

### 2.2 Create the dedicated read-only database role

GAL Bridge reads the `vmail` database with its **own** role. Do not reuse `vmail` (it can read every
column, password hashes included), `vmailadmin`, `roundcube` (it can UPDATE `vmail.mailbox`),
`sogo`, `iredadmin`, `iredapd`, `amavisd`, `fail2ban`, `root` or `postgres`. The plugin refuses
these role names (`galbridge_db_refused_users`), the user of Roundcube's `db_dsnw` and the user of
the password plugin's `password_db_dsn`. It also probes what the role can see and refuses a role
that can read `mailbox.password`, `recovery_email` or `allow_nets`, or the `forwardings` **table**
(log `role_too_broad`).

**Generate the statements** (recommended; nothing is written to disk by the tool). The tool does not
run as root – it loads PHP code from the plugin directory – so run it as the PHP user; the root
shell only stores its output:

```sh
cd /opt/www/roundcubemail
umask 077     # the output holds the DSN with the password: the file must be readable by root only
sudo -u www-data plugins/galbridge/bin/galbridge.sh grants --driver=mysql > /root/galbridge-grants.sql   # MariaDB/MySQL edition
sudo -u www-data plugins/galbridge/bin/galbridge.sh grants --driver=pgsql > /root/galbridge-grants.sql   # PostgreSQL edition
```

| Option | Default | Meaning |
|---|---|---|
| `--host=` | `localhost` (MySQL), `127.0.0.1` (PostgreSQL) | the **database server** written into the printed DSN: a host name or an IP address, an IPv6 address in brackets (`[2001:db8::5]`); patterns such as `%` are refused |
| `--account-host=` | `localhost` | MariaDB/MySQL only: the host part of the account, i.e. where Roundcube connects **from**. Use the same host part as your existing Roundcube account (`SHOW GRANTS FOR 'roundcube'@'localhost';`); patterns such as `10.0.0.%` are accepted. Any value other than `localhost`, `127.0.0.1` or `::1` adds `ALTER USER ... REQUIRE SSL` |
| `--db=` | `vmail` | the vmail database |
| `--role=` | `galbridge` | the role name (lower-case letters, digits and `_`, starting with a letter). Names of roles that belong to iRedMail, Roundcube or the database server (the `galbridge_db_refused_users` list, `mysql`, `mariadb`, `public`, `pg_*`, ...) are refused: the statements would change that role |
| `--connection-limit=` | `25` | the connection limit of the account, for **both** engines (1–10000): PostgreSQL `CONNECTION LIMIT`, MariaDB/MySQL `MAX_USER_CONNECTIONS` |
| `--reset-password` | – | give the role a **new password**, also when it already exists (see below) |
| `--grants-only` | – | re-apply limits, view and grants to the **existing** role; the password is not changed and no DSN is printed |
| `--clear-text-password` | – | MariaDB/MySQL driver only, for **MySQL 8.4 or later**: write the password itself into the statement (see "No password in the statements" below) |

The output is the grant set of `SQL/grants.mysql.sql` or `SQL/grants.postgres.sql` for a fresh
random password (40 hexadecimal characters) and, at the end as SQL comments, the matching
`$config['galbridge_db_dsn']` line (plus a TLS reminder when `--host` is not local). Role, account
host, database and connection limit are applied to the SQL statements; the comments, including the
attribution header, stay as shipped. Before you uncomment the commented-out personal-data grant,
check that it names your role, account host and database. Keep the output private. Apply it as the
database administrator:

```sh
mysql < /root/galbridge-grants.sql                                                  # MariaDB / MySQL, as root (never with --force)
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql   # PostgreSQL
```

Copy the DSN line into the plugin configuration (2.3), then delete the file
(`shred -u /root/galbridge-grants.sql`).

**Three forms of the output – what happens to an existing role.** The second line of every output
says which one it is:

* *default*: the statements **create** the role. When a role of that name already exists they stop
  (PostgreSQL: the whole transaction is rolled back; MariaDB/MySQL: `ERROR 1396` at the first
  statement) and change nothing – a mistyped `--role` cannot take over the role of another
  component, and re-running an old output cannot change a password behind your back;
* `--reset-password`: creates the role if necessary and sets a **new password**: put the printed
  DSN line into the configuration in the same step, the old password stops working at once;
* `--grants-only`: limits, view and grants are applied again to the existing role, its password
  stays. Use it after an upgrade of GAL Bridge (section 5), to change the connection limit, or
  after you uncommented the personal-data grant.

Grants are additive: no form removes a column grant. To remove one you no longer need (`diag` lists
such columns as "granted but unused"), revoke it by hand, e.g.
`REVOKE SELECT (telephone, mobile, birthday) ON vmail.mailbox FROM 'galbridge'@'localhost';`
(PostgreSQL: `REVOKE SELECT (telephone, mobile, birthday) ON mailbox FROM galbridge;`).

**No password in the statements.** The statements carry a *verifier* of the password, never the
password: a SCRAM-SHA-256 verifier for PostgreSQL, the `mysql_native_password` hash for
MariaDB/MySQL. The clear text exists in the DSN line of the output only. That matters because
database servers keep account statements: PostgreSQL in its log when `log_statement` is `ddl` or
`all`, MariaDB in its general query log, its binary log (and therefore on replicas and in
point-in-time backups) and, when the statement qualifies, its slow query log – verbatim, with a
password that is written into `IDENTIFIED BY '...'`. The PostgreSQL output additionally switches
statement logging off for its own transaction. *MySQL 8.4 and later* no longer load
`mysql_native_password`: there the default output stops at `CREATE USER` with "plugin is not
loaded" and creates nothing – generate it with `--clear-text-password` instead. MySQL rewrites
passwords in its own logs; that output begins with a statement that makes it stop on MariaDB.

**Never edit the templates inside the plugin directory.** iRedMail's nginx serves the files under
`plugins/` through its `static.php` alias (section 2.6): a credential written into
`plugins/galbridge/SQL/grants.mysql.sql` would be downloadable. To use a template by hand, copy it
first, e.g. `install -m 0600 plugins/galbridge/SQL/grants.mysql.sql /root/galbridge-grants.sql`, and
follow the steps in its header (PostgreSQL: replace `CHANGE_ME_RANDOM_PASSWORD`; MariaDB/MySQL:
compute the verifier of your password with the two `openssl` commands given there and replace
`CHANGE_ME_PASSWORD_VERIFIER`; and `vmail.` if your database has another name).

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
  configured is a `diag` INFO line;
* **the view `galbridge_forwardings` instead of the table `forwardings`.** That table also holds the
  private forwarding targets of every mailbox (often external addresses). The grant set creates one
  view with only the three kinds of rows the plugin needs – per-user alias addresses, members of
  alias accounts and mailing-list rows – and grants the role the view, **not** the table: even with
  the role's credentials nobody can read where a mailbox forwards its mail. It is the only object
  GAL Bridge adds to `vmail`; it stores nothing (MariaDB/MySQL: `SQL SECURITY DEFINER`; PostgreSQL:
  `security_barrier`) and is re-created by every run of the grant set. Before an iRedMail upgrade
  that alters the `forwardings` table, drop the view and run `grants --grants-only` afterwards
  (the templates say how);
* **limits of the account on both engines**: at most 25 simultaneous connections
  (`--connection-limit`) and a statement time of 5 seconds for the role itself (PostgreSQL
  `statement_timeout`, MariaDB `MAX_STATEMENT_TIME`; MySQL has no per-account statement time).
  Independently of the role, the plugin sets a read-only session and a statement timeout on every
  connection (`galbridge_db_timeout`);
* **PostgreSQL**: a new role (`NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT`) with
  the role defaults `default_transaction_read_only = on`, `statement_timeout = '5s'`,
  `idle_in_transaction_session_timeout = '10s'` and – because Roundcube sends statements with the
  search terms inlined – `log_statement = 'none'`, `log_min_duration_statement = -1` and
  `log_min_error_statement = 'panic'`, and, where the server knows them, `log_min_duration_sample
  = -1` (PostgreSQL 13+) and `log_transaction_sample_rate = 0` (12+), so the statements of this
  role stay out of the server log – also out of a logged sample – whatever the server-wide settings
  are. A new role is required: column grants cannot narrow an existing table-level grant.

Size the connection limit to the PHP-FPM pool of Roundcube (`pm.max_children`, e.g.
`--connection-limit=100` for a pool of 100 children): a request opens at most one directory
connection, and only when it actually reads the directory. A request beyond the limit fails like an
outage (log `db_down`, section 3.9). `diag` shows the limit of the account on both engines and warns
when it is unlimited.

**Roundcube and the vmail database on different hosts.**

* MariaDB/MySQL: `grants --driver=mysql --host=<database server> --account-host=<address of the
  Roundcube host>`; the output adds `REQUIRE SSL` for the account, and the server needs TLS
  (`ssl_cert` / `ssl_key`).
* PostgreSQL: `grants --driver=pgsql --host=<database server>`. iRedMail's `pg_hba.conf` ends with
  the catch-all line `host all all 0.0.0.0/0 md5`, and PostgreSQL uses the **first** matching line:
  a line appended after the catch-all never applies. Insert these lines **before** it, then reload
  PostgreSQL (it must also listen on that interface, `listen_addresses`, and have `ssl = on`):

  ```
  hostssl    vmail  galbridge  <address of the Roundcube host>/32  scram-sha-256
  hostnossl  vmail  galbridge  0.0.0.0/0                           reject
  ```
* In both cases add certificate verification to the printed DSN (section 3.9); the DSN host name
  must match the server certificate.

On **PostgreSQL before 15**, `PUBLIC` may still create objects in schema `public`; `diag` warns when
the role can. Consider `REVOKE CREATE ON SCHEMA public FROM PUBLIC;` in `vmail` (the PostgreSQL 15
default) after checking that no other application role relies on it.

### 2.3 Configure the plugin

```sh
cp plugins/galbridge/config.inc.php.dist plugins/galbridge/config.inc.php   # not needed after a Composer install
chown root:www-data plugins/galbridge/config.inc.php
chmod 0640 plugins/galbridge/config.inc.php
```

The file holds the database password and the directory policy: owner root, group = the PHP-FPM
user, mode `0640`. `diag` fails when others can access it, and warns when its group can write it,
when it is not owned by root – also when it belongs to the PHP user itself, because the web server
process could then rewrite the directory policy – or when its directory is writable by group or
others (without the sticky bit). Instead of
`plugins/galbridge/config.inc.php` you may use `config/galbridge.inc.php`, which Roundcube reads
**only** when the plugin directory contains no `config.inc.php`. Both locations are denied by
iRedMail's nginx templates. Never keep the DSN in any other file inside `plugins/galbridge/` (a
backup such as `config.old.php` would be downloadable, section 2.6).

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
`galbridge_*` options are administrator-only: a stored user preference of the same name is ignored.

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
* Apache 2.4: the plugin ships a `.htaccess` with `Require all denied` (effective only with
  `AllowOverride`).
* **iRedMail nginx**: the templates `roundcube.tmpl` (webmail under `/mail/`) and
  `roundcube-subdomain.tmpl` (in `/etc/nginx/templates/` on Linux) contain a location for
  `^/static.php/(.*)` that `alias`es the request straight to the Roundcube directory. That bypasses
  the type filter of core `static.php`; the templates deny `plugins/*/config.inc.php*` and a few
  top-level directories, but not other plugin files. The PHP source of the plugin, the SQL templates
  and any file an administrator leaves in `plugins/galbridge/` can then be downloaded. Add this rule
  to both templates **before** the `static.php` location (nginx uses the first matching
  regular-expression location in the order they appear):

  ```nginx
  location ~ ^/(mail/)?(static\.php/)?plugins/galbridge/ { deny all; }
  ```

  Reload (`nginx -t && systemctl reload nginx`) and check that the plugin source is no longer
  served (for `roundcube-subdomain.tmpl` leave out the `/mail` prefix). Both requests must answer
  `403` (or `404`); a `200` means the rule is not effective:

  ```sh
  curl -s -o /dev/null -w '%{http_code}\n' https://mail.example.com/mail/static.php/plugins/galbridge/lib/autoload.php
  curl -s -o /dev/null -w '%{http_code}\n' https://mail.example.com/mail/plugins/galbridge/lib/autoload.php
  ```

* `diag --url=` makes the same check from the server side for the usual paths (section 2.7):

  ```sh
  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/
  ```

  A `[FAIL] web access ... is SERVED` line means that the rule above is missing or not effective.
* Keep nothing but the plugin in `plugins/galbridge/`: release archives built with `git archive`
  leave out `tests/` and developer tooling (`.gitattributes`), a git clone does not (section 2.1).
  `diag` lists a clone and files that are not part of a release.

### 2.7 Test the installation

```sh
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/
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
reads the configuration of the password plugin), `vendor/` and the configuration directory, with
the directories they are reached through – on a usual installation, where some of that belongs to
the web server user, the answer is to run the tool as that user. The user is determined with the
posix extension of PHP or, where that is not installed, from `/proc`; when neither tells, the tool
treats the process like root. Do not pass a foreign environment to it (`sudo -E`,
`ROUNDCUBE_INSTALL_PATH` from an untrusted source).

Options are written `--name=value`. An option without its value (`--role acme`), an unknown or
misspelled option and a stray word are usage errors: nothing is printed or checked with half of
what was asked for.

`diag` reports `[ OK ]`, `[INFO]`, `[WARN]` and `[FAIL]` lines in sections. Every check runs on its
own: a check that fails unexpectedly prints `[FAIL] check aborted` with the reason, and the
following checks still run.

* **Versions**: Roundcube – OK for the newest 1.7.x release this version knows (and for later ones),
  WARN for an older patch release (update the **core**: the later releases fix security problems of
  Roundcube) and for a core that reports `1.7-git`, FAIL for any other branch; the PHP branch and
  its security support; the iRedMail release.
* **Configuration**: whether the plugin is enabled; the configuration file – location and mode (FAIL
  when others can access it), WARN when its group can write it, when it is not owned by root
  (including the PHP user running `diag`), or when its directory is writable by group or others;
  every configuration problem (FAIL for the fail-closed cases such as a switch that is not a
  boolean, a visibility column that is not a string, a malformed deny-list or overlapping
  organizations, WARN for dropped allow-list entries); a WARN for every `ROUNDCUBE_GALBRIDGE_*`
  environment variable that overrides an option (the name only, never the value); a WARN while `'*'`
  global data domains are in effect (and when `'*'` is set without
  `galbridge_global_allow_wildcard`, which leaves the global book empty); the enabled books; the
  **effective value of every switch** – three `switches (...)` lines, each switch under the name of
  its option without the `galbridge_` prefix, `on`, `off` or `auto`; one whose value is ambiguous is
  shown with the conservative value it is read as and marked `(INVALID)` – and of the viewer cache
  time; a note on the order of autocomplete suggestions while the global book marks entries of other
  domains (section 3.3); leftovers of `sql_global_addressbooks`.
* **Installation**: whether the plugin directory holds only the files of a release (WARN for a git
  clone with `tests/` and for files that do not belong there); whether the PHP user can write the
  plugin code (WARN); and, with `--url=`, whether the web server serves files of the plugin
  directory (FAIL when it does; without `--url` an INFO line says that this was not checked). The
  address is checked first: when it does not answer like the Roundcube webmail – a wrong path
  prefix, another virtual host – a WARN says that "not served" means nothing there.
* **IMAP host binding**: the directory servers (from `galbridge_imap_hosts` or derived from
  `imap_host`, each as `host`, `host:port` or `unix:///path`) and whether they cover the servers
  Roundcube really
  puts into sessions: the hosts of `imap_host` (FAIL when its only host is not covered; WARN for
  each uncovered entry when it lists several) and the `mail_host` values stored in Roundcube's
  `users` table (WARN with the number of users who would get no directory). A WARN when more than
  one server is allowed (every one of them must authenticate against this vmail database) and when
  Roundcube's `include_host_config` is enabled (the per-host configuration files are not checked;
  without an explicit `galbridge_imap_hosts` the plugin is disabled, FAIL).
* **Core interplay**: `addressbook_sort_col`; a `contactlist_name_template` that shows text of an
  entry before `{name}` or without it (WARN with the fields that entries of other domains then do
  not carry, section 2.5); source-ID collisions with `ldap_public`, the group-expansion cap, the
  autocomplete minimum.
* **vmail database**: the DSN policy (refused role, TLS rule, DSN syntax), the PDO and `intl`
  extensions (WARN when `pdo_mysql` is not built on `mysqlnd` and the database is remote); the
  connection (on failure a FAIL with the driver's reason, e.g. `Access denied for user ...`, never
  the password) and a WARN when the server refused the statement timeout or the read-only mode of
  the session; the password-column tripwire – OK only when the server **refuses** `SELECT password`
  for lack of privilege (any other error is a FAIL with its SQLSTATE: it proves nothing); the
  **write privileges**, read from the database catalogs (PostgreSQL `has_table_privilege` /
  `has_any_column_privilege`, MariaDB/MySQL the `information_schema` user, schema, table and column
  privileges) and, on MariaDB/MySQL, also asked from the server with statements that can change
  nothing (so a privilege that reaches the account through a database role is found as well): any
  privilege to modify a vmail table is a FAIL; the **connection limit of the account on both
  engines** (WARN when unlimited); whether the server log can record the statements of the role
  with their search terms (PostgreSQL: the role's logging settings; MariaDB/MySQL: the general query
  log); on PostgreSQL also `CREATE` in schema `public`, `statement_timeout` and the database ctype
  (`pg_database.datctype`, WARN for `C`/`POSIX`); on MariaDB/MySQL the server's statement time
  limit; the column probe for the effective configuration (secret columns visible, the
  `forwardings` table readable and required columns missing are FAIL, columns of configured
  optional fields, alias accounts and lists missing are WARN, granted columns of fields that are not
  configured are INFO; the visibility column in use, or "not used"); and data sanity (hosted
  domains, deliverable mailboxes, addresses with upper-case letters, mailboxes without a name, the
  indexes on `mailbox(domain)` and `forwardings(forwarding)`).
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
  [ OK ] write privileges                   none on the vmail tables
  [ OK ] ctype                              pl_PL.UTF-8
  [ OK ] connection limit                   25 (size it to the PHP-FPM pm.max_children of Roundcube)
  [ OK ] statement logging                  off for this role (its statements contain search terms)
  [ OK ] secret columns                     not visible
  [ OK ] table forwardings                  not readable (the role reads the view galbridge_forwardings: alias and list rows only)
  [INFO] granted but unused                 mailbox.telephone, mailbox.mobile, mailbox.birthday (not in galbridge_fields; the grant can be dropped)

== User jan.kowalski@example.com
  [ OK ] galbridge_domain                   1 domain(s), groups: department (3 groups), search-only: no
  [INFO]   persons                          7 (0 ms)
```

`diag` never prints the database password, the value of an environment override or directory
contents (counts only). Exit code 0 = no problem found (warnings do not fail), 1 = usage error or
refused as root, 2 = problems found.

## 3. Configuration overview

Every option, its default and its effect are documented in `config.inc.php.dist`. Numeric options
are clamped to the ranges shown; list options must be PHP arrays, and `'*'` is accepted only as the
sole element. Domains and addresses in lists are normalised once (trim, Unicode NFC, lower case,
IDNA to ASCII, trailing dot removed), so a domain means the same in every spelling. An invalid
**allow-list** entry is dropped (log ERROR `config_invalid`, `diag` WARN); an invalid **deny-list**
disables what it protects (fail closed, `diag` FAIL). A configuration change takes effect with the
next request.

**Types are checked, never guessed.** A switch must be `true` or `false` (or `1` / `0`; the
`searchonly` options also `'auto'`), `galbridge_visibility_column` must be a string. A value of
another type – the string `'false'`, an array where a name is expected – would be read by PHP as
"on" or as "no filter"; GAL Bridge reports it instead and disables **every** book until it is
corrected. `diag` shows the effective value of each switch.

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
| `galbridge_global_groups` | `'none'` | `none`, `department` or `domain`; groups exist only with `galbridge_global_searchonly = false` |
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
(`addressbook_name_listing`) falls back to the marked display name. The marker comes first: a
recipient field shortens a long name at its end, which must not hide where the entry comes from.
The marker is part of the display name: it also appears in the recipient fields of a message, in
exported vCards (`FN`) and in contacts copied to a personal address book.

Two consequences of the marker standing first:

* **Order of suggestions.** Roundcube sorts the autocomplete suggestions of all address books by
  name and pre-selects the first one. A name that starts with `(` sorts before letters and digits,
  so entries of other domains are listed **before** unmarked names, and a user who presses Enter
  right after typing gets the first of them. The marker is visible in the suggestion and in the
  recipient field. Where that order is not wanted, set `galbridge_global_autocomplete = false`: the
  global book then stays out of autocomplete and remains available in the address book and in the
  contact list of the compose window. `diag` mentions this while it applies.
* **Name templates.** The marker protects the name. With a `contactlist_name_template` that shows
  other text of an entry before `{name}` or without it, entries of other domains carry no department
  and no telephone numbers (section 2.5).

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
whole and not by a fragment. At most 20 aliases are shown per person.

Display text is sanitised: Unicode NFC; control characters, CR/LF, format characters (bidi
controls, zero-width characters, soft hyphen, tag characters) and `\ " < >` removed; Unicode spaces
and characters that render blank (U+2800, U+3164, U+115F, U+1160, U+FFA0) collapsed into one space,
so padding cannot push text out of view; names capped at 128 characters. Two notations that a later
reader would **decode** are neutralised as well, so that the text checked by the plugin is the text
a user finally sees: `&` becomes the full-width ampersand `＆` (U+FF06) – Roundcube's recipient
widget inserts names as HTML, where `&#64;` would be displayed as an at-sign – and `=?` and `?=`,
the delimiters of an RFC 2047 encoded word that the mail clients of the recipients would decode,
are split (`= ?`, `? =`). What Roundcube deletes later is deleted first: with a
`contactlist_name_template` it removes empty bracket pairs (`()`, `[]`) from a composed name, which
would join `=()?` to `=?` again; for the same reason a text never starts with a closing or ends
with an opening bracket (it would form such a pair with a bracket of the template). A display,
first or last name containing an at-sign – `@` or its look-alikes `＠` (U+FF20) and `﹫` (U+FE6B) –
is cut there, so it cannot pose as an address. An entry without any name is shown with the local
part of its address, treated like every other text. Addresses are lower-case ASCII and must pass
Roundcube's e-mail check.

### 3.5 Groups

* `galbridge_domain_groups = 'auto'` groups by **domain** when the user's organization has more than
  one domain, otherwise by **department**. `galbridge_global_groups` defaults to `'none'`.
* Department groups need the `department` field (and its grant); otherwise the book has no groups
  (log `grouping_disabled`). Departments are grouped case-insensitively (`LOWER(TRIM(department))`),
  empty departments form no group.
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
inactive or backup-MX domains, forwarding targets, catch-all rows and external list members.

| Option | Default | Meaning |
|---|---|---|
| `galbridge_hide_expired` | `true` | hide mailboxes, domains, alias accounts and lists whose `expired` date has passed. The expiry has to be readable: when the role cannot read the `expired` column of alias accounts or of lists, that kind of entry is left out (`diag` WARN), never listed unfiltered |
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
| `galbridge_max_results` | 500 | 10–5000 | rows of a domain-book search list; more show "Only the first N directory matches are shown". The **export** of a search result with more matches is refused (section 4) |
| `galbridge_global_max_results` | 100 | 10–5000 | the same for the global book |
| `galbridge_max_ids` | 200 | 1–1000 | contacts used at once (compose to selected, export of selected); more are refused, never cut |
| `galbridge_max_group_members` | 500 | 1–100000 | group expansion cap (lowered further by core `max_group_members` when set) |
| `galbridge_max_groups` | 50 | 1–500 | above it a book shows no groups |
| `galbridge_max_export` | 2000 | 1–100000 | whole-book export is refused above it |
| `galbridge_max_count` | 10000 | 100–1000000 | list totals are counted up to it; list pages beyond it are empty. It bounds counting only – what an export or a recipient set holds is decided by the limit of that operation, whatever the relation between the values |
| `galbridge_searchonly_threshold` | 2000 | 1–1000000 | the `'auto'` search-only limit |

A search splits the input into at most 5 words; every word must match at least one searchable field.

### 3.9 Database connection and IMAP host binding

| Option | Default | Meaning |
|---|---|---|
| `galbridge_db_dsn` | `''` | **mandatory**; drivers `mysql`, `mysqli` or `pgsql` |
| `galbridge_db_connect_timeout` | 2 | seconds (1–30), added to the DSN as `timeout` unless it has one |
| `galbridge_db_timeout` | 3 | seconds (1–30): statement timeout set on every connection (MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`), together with a read-only session |
| `galbridge_db_allow_plaintext` | `false` | accept a remote host without verified TLS (`diag` WARN) |
| `galbridge_db_allow_verify_ca` | `false` | accept PostgreSQL `sslmode=verify-ca` for a remote host (`diag` WARN; see the TLS rule) |
| `galbridge_db_refused_users` | iRedMail's roles, `root`, `postgres` | role names the plugin refuses (plus the `db_dsnw` and `password_db_dsn` users). Must be a list: any other value is reported and the built-in list is used |
| `galbridge_imap_hosts` | `[]` | IMAP servers whose logins may use the directory: `host` or `host:port` (host names or IP addresses), or `unix:///path` for a local socket |
| `galbridge_scope_ttl` | 300 | seconds (30–3600) the resolved user (home domain, address set, column probe) is cached in the session; revocation does not wait for it (see below) |

**The DSN names its target itself.** Before anything connects, the plugin checks the DSN in the
form the database driver will receive it: a host or a unix socket (a DSN without either would let
the client library choose), a port of digits only, a database name, and only known parameters –
PostgreSQL `sslmode`, `sslrootcert`, `sslcert`, `sslkey`, `sslcrl`, `application_name`, `timeout`;
MariaDB/MySQL `ca`, `capath`, `cert`, `key`, `cipher`, `verify_server_cert`, `charset`, `timeout` –
each once and well-formed. The PostgreSQL `service` parameter is refused, and so are the environment
variables of the PostgreSQL client library that would move the connection elsewhere (`PGHOSTADDR`,
`PGSERVICE`, `PGSERVICEFILE`, and `PGPORT` for a DSN without a port); `PGSSLMODE`, `PGREQUIRESSL`
and `PGOPTIONS` give a warning. A violation disables every book (`diag` FAIL with the reason).

**TLS rule.** A unix socket, `127.0.0.0/8`, `::1` and `localhost` count as local. Any other host
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

**Outages.** When the database cannot be reached (also when the role's connection limit is
reached), the session does not try again for 30 seconds (log `db_down`; in a web request Roundcube's
errors log has the driver message, and `diag` prints it on its `connect` line). A database outage is
never cached as "no entitlement". What users see:

* a session that already had its books keeps them in the address-book list, also after
  `galbridge_scope_ttl` has expired (they are listed from the expired session cache while the
  configuration is unchanged, and read nothing); opening or searching such a book gives an empty
  result and the message "The directory is temporarily unavailable." (at most once per request,
  for all books together);
* a session that has not resolved its books yet (e.g. a login during the outage) gets no GAL Bridge
  books until the database is back;
* a query that fails on a working connection (log `db_error sqlstate=…`, e.g. a missing grant or a
  statement timeout) shows the same message.

**IMAP host binding.** A user who logs in through an IMAP server of their own choosing as
`ceo@tenant-a.example` must not get tenant A's directory. The plugin therefore accepts only sessions
whose IMAP server – host **and port** (`$_SESSION['storage_host']`, `storage_port`) – and stored
user host (`mail_host`) are covered by the directory servers. With `galbridge_imap_hosts` empty, the
directory server is derived from the administrator's `imap_host` when it is a single string without
`%` placeholders: its host part, lower case, IDNA, with its port (`tls://127.0.0.1:143` →
`127.0.0.1:143`). User preferences and the client's `Host` header play no part. A templated (`%d`,
`%n`, `%s`), array or empty `imap_host` without an explicit list disables the plugin (`diag` FAIL,
log `plugin disabled`). So does Roundcube's `include_host_config`: with it the effective
`imap_host` depends on the requested host name, so nothing is derived from it – list the servers.
With several IMAP servers that authenticate against the same vmail database, list them:

```php
$config['galbridge_imap_hosts'] = ['imap1.example.com:993', 'imap2.example.com:993'];   // no scheme, no %
```

An entry without a port allows every port of that host; give the port whenever another IMAP
service – a test instance, a proxy in front of another user database – listens on the same host.
An IMAP server on a local socket (`imap_host = 'unix:///run/dovecot/imap.sock'`, which Roundcube
opens without a port) is derived or listed as that URI. A socket has neither host name nor port: it
is matched as a whole, the path exactly as written.
**Every listed server must authenticate against this vmail database**: the plugin cannot verify
that, and a login from another user database that happens to equal a mailbox name here would get
that mailbox's directory (`diag` reminds you when more than one server is listed).

`diag` checks that the directory servers cover `imap_host` and the `mail_host` of the existing
Roundcube users. A session outside them gets no books and is logged once per request as
`host_mismatch` with `host=` (session IMAP server), `mail_host=` (stored user host) and `allowed=`
(the directory servers).

**The viewer cache and revocation.** The resolved user is cached in the session for
`galbridge_scope_ttl`: a new alias or a changed column grant shows up after at most that time.
Losing the directory does not wait for it: every request that reads directory data first confirms
with one small statement that the mailbox of the session is still active, unexpired and in its
domain. A mailbox that was disabled, expired, deleted or moved – or whose domain was – gets "The
directory is temporarily unavailable." on that request and no book afterwards (log
`viewer_revoked`). Requests that only instantiate a book (the sender-photo lookup of every opened
message) do not pay for the statement.

### 3.10 Logging

The plugin logs to the Roundcube log `galbridge`: the file `<log_dir>/galbridge.log` (Roundcube
appends `log_file_ext`, default `.log`). With `log_driver = 'syslog'`, which iRedMail configures
(facility `LOG_MAIL`), the lines go to syslog under Roundcube's ident without the log name. Line
format: `LEVEL operation: message key=value ...`, with the numeric Roundcube user id (`user=`) added
automatically. Normal levels carry reason codes, book ids, counts and SQLSTATEs – never search
terms, addresses or names. Reason codes triggered by request input or by directory rows
(`invalid_id`, `invalid_gid`, `denied_source`, the limits, `row_invalid`, `row_out_of_scope`,
`host_mismatch`, ...) are logged at most once per request; `db_error` is logged for every failed
query. `galbridge_debug = true` (default `false`) adds only the database driver's error text, the
messages of unexpected exceptions and schema hints (an entity left out because a column is not
readable). The plugin never logs search terms or addresses itself, but driver and exception texts
are not filtered for personal data (a driver error may quote part of a statement, which contains
the inlined search values): keep it off in normal operation.

The **database server** keeps logs of its own, and Roundcube sends statements with the values
inlined, so a statement of the plugin contains what a user searched for. The PostgreSQL grant set
switches statement logging off for the role; MariaDB/MySQL have no such per-account setting – keep
the general query log off or restricted. `diag` shows what applies
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
  (global book: every word); "Jan K" finds Jan Kowalski.
* **Compose**: autocomplete includes the books (and matching groups, shown by Roundcube with their
  member count), also in the recipient fields of the Redirect dialog; the contacts pane lists the
  books and their groups. Picking a group inserts every member.
* **Messages** a user can see:

| Message | Cause |
|---|---|
| The directory is temporarily unavailable. | database outage or failed query (log `db_down` / `db_error`; section 3.9) |
| This group has more than N members and cannot be added as recipients. ... | group expansion above the cap (3.5) |
| Only the first N directory matches are shown. Refine your search. | search list cap (3.8) |
| At most N directory contacts can be used at once. | more than `galbridge_max_ids` selected contacts (compose to selected) |
| Not every entry of this selection could be read from the directory, so it was not used. ... | a recipient set or export that would have lacked an entry (a row that fails the plugin's checks, log `set_incomplete`): complete or nothing |

* **An export is complete or it is refused – and a refused export downloads nothing.** An export
  is a file download, so no message can be shown with it. When GAL Bridge refuses one, the server
  answers `204 No Content`: the browser stays on the page and saves no file (not even the contacts
  of other address books selected together with directory contacts). Refused are: the whole book
  with `galbridge_*_export = false` or above `galbridge_max_export`; a group above the expansion
  cap; more than `galbridge_max_ids` selected contacts; a **search result with more matches than
  the result cap** (`galbridge_max_results` / `galbridge_global_max_results` – on a page such a
  list is cut with a notice, a file could not say so); a set of which an entry could not be read;
  and any export while the directory fails (database outage or query error). The refusal is logged
  (`export_disabled`, `export_too_large`, `group_too_large`, `too_many_ids`, `set_incomplete`,
  `db_down` / `db_error`). No limit that only bounds counting (`galbridge_max_count`) decides what a
  file holds.

## 5. Upgrade

**The plugin.** Replace the plugin files with the new release exactly as for an installation
(section 2.1: verify the tag, then `git archive ... | tar -x`; keep your `config.inc.php`) or run
`composer update takenek/galbridge`. Read [CHANGELOG.md](CHANGELOG.md), then run
`bin/galbridge.sh diag`. There are no plugin tables, so there is no schema update; options added by
a new release have built-in defaults.

**From 1.0.0 to 1.0.1** – three steps, the second one is required:

1. Replace the plugin files (above).
2. **Apply the grant set to the existing role**, as the database administrator. 1.0.1 reads the
   forwardings through the view `galbridge_forwardings` and refuses a role that can read the
   `forwardings` table; until this step is done the plugin offers **no book** (log `column_missing`
   or `role_too_broad`, `diag` FAIL) – it fails closed, mail is not affected.

   ```sh
   cd /opt/www/roundcubemail
   umask 077
   sudo -u www-data plugins/galbridge/bin/galbridge.sh grants --driver=pgsql --grants-only > /root/galbridge-grants.sql
   runuser -u postgres -- psql -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql
   ```

   MariaDB/MySQL: `--driver=mysql` (with the `--account-host`, `--db` and `--role` you used before)
   and `mysql < /root/galbridge-grants.sql`; then run **once** the `REVOKE SELECT (...) ON
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
   three short-lived session keys: `galbridge`, the resolved user; `galbridge_down_until`, the
   outage memo; `galbridge_searchonly`, the `'auto'` search-only decisions). If you added
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
| No GAL Bridge book in the address book | `diag`: `galbridge_db_dsn` missing or refused (FAIL on the `galbridge_db_dsn` line with the reason), plugin disabled by the IMAP host binding (FAIL "directory hosts"; log `plugin disabled` – also with `include_host_config`), session server not a directory server (log `host_mismatch host=… mail_host=… allowed=…`: add the host, or the right port, to `galbridge_imap_hosts`; `diag` checks `imap_host` and the hosts of Roundcube's users), login not an active mailbox (`diag --user`: "not found"; log `denied_source status=no_mailbox`), role too broad (log `role_too_broad`: it can read a secret column or the `forwardings` table), a required column or the view `galbridge_forwardings` missing (log `column_missing` names it: run `grants --grants-only`, section 5), **a switch that is not a boolean or another fail-closed configuration error** (log `config_invalid … all books are disabled`, `diag` FAIL names the option), home domain not in the viewer list, a login during a database outage (the books appear once the database is back, 3.9) |
| Global book missing | `galbridge_global_viewer_domains` is empty by default; `'*'` data domains without `galbridge_global_allow_wildcard` (log `config_invalid`); its data scope minus the user's own domain(s) is empty |
| "The directory is temporarily unavailable." | log `db_down` (connection failed or the role's connection limit reached – raise it, 2.2; in a web request Roundcube's errors log has the driver message, `diag` prints it on its `connect` line; next attempt after 30 s) or `db_error sqlstate=…`: `42000` (MariaDB/MySQL: access denied – grants), `42501` (PostgreSQL: permission denied – grants), `42S22` / `42703` (unknown column – iRedMail schema or the `galbridge_visibility_column` name), `70100` (MariaDB `max_statement_time`), `57014` (PostgreSQL `statement_timeout`), `HY000` (MySQL, includes `max_execution_time`) |
| Export downloads nothing | a refused export (section 4): log `export_disabled`, `export_too_large` (also a search result above the result cap), `group_too_large`, `too_many_ids`, `set_incomplete`, or `db_down` / `db_error` during the export |
| The directory disappears for one user in the middle of a session | the mailbox was disabled, expired, deleted or moved (log `viewer_revoked`; section 3.9) |
| Autocomplete or search finds nothing | fewer than `galbridge_min_search_length` characters (global book: in every word); global book matches prefixes only, e-mail from the local part and organizations by their whole label; `galbridge_*_autocomplete = false`; search-only book opened without a search |
| Groups missing | search-only book; more than `galbridge_max_groups` (log `too_many_groups`); department groups without the `department` field (log `grouping_disabled`); `galbridge_*_groups = 'none'`; `diag --user` shows the effective grouping |
| A field (phone, last name, ...) missing | not in `galbridge_fields`, column not granted or missing (log `field_unavailable`; `diag` WARN; the shipped grants leave `telephone`, `mobile` and `birthday` commented out; iRedMail `update/1.7.3` adds `first_name`, `last_name`, `mobile`, `telephone`, `birthday`); `organization` needs `galbridge_organizations`; global-book entries of other domains carry no first and last name (3.3) |
| An alias account or list missing | its `accesspolicy` does not allow the user to post (3.6); policy value unknown (hidden); no active member / list row in `forwardings`; `diag --user` shows the counts |
| Search results in e-mail order | `addressbook_sort_col` is not `'name'` (2.5) |
| Accented names found on MariaDB but not on PostgreSQL | engine difference, see 10 |
| Error 700 "Addressbook source (...) not found" | a source listed in `autocomplete_addressbooks` that no longer exists (e.g. `domain` / `global` of the old plugin, or `galbridge_*` after uninstalling) |
| Details | `<log_dir>/galbridge.log` or the mail log (syslog); reason codes below |

**Log reason codes** (`LEVEL operation: code: message key=value`):

| Code | Level | Meaning |
|---|---|---|
| `config_invalid` | ERROR | an option is invalid (`option=` names it); fail-closed cases disable the protected book or every book |
| `dsn_warning` | WARN | remote database accepted without verified TLS (`galbridge_db_allow_plaintext`) |
| `plugin disabled` / `host_mismatch` | WARN | IMAP host binding (3.9); `host_mismatch` names `host=`, `mail_host=` and `allowed=` |
| `denied_source` | INFO | a GAL Bridge book was requested that the user cannot use; `status=` gives the reason: `no_mailbox`, `disabled`, `host_mismatch`, `invalid_login`, `role_too_broad`, `column_missing`, `config_invalid`, `db_down`, `db_error`, or `ok` (not entitled by the viewer lists or the data scope) |
| `role_too_broad` | ERROR | the role can read `password`, `recovery_email` or `allow_nets`, or the table `forwardings` (the private forwarding targets): every book is denied |
| `column_missing` | ERROR | required vmail columns are missing or not granted (named in the line; every book is denied). Required are the columns of the view `galbridge_forwardings` and, with `galbridge_hide_expired`, `mailbox.expired` and `domain.expired`; `diag` checks the same list |
| `viewer_revoked` | INFO | the mailbox of a session with a cached entitlement is no longer usable: the directory was taken away with that request |
| `too_many_aliases` | WARN | a user has more own aliases than are used for the list policies (200): the policies are evaluated with the login alone |
| `aliases_capped`, `aliases_skipped` | WARN | more per-user aliases than are shown for one person (20), or so many on one page that some entries are shown without aliases |
| `field_unavailable` | WARN | configured fields whose columns are missing or not granted |
| `db_error` | ERROR | a query failed (`sqlstate=`); the driver text only with `galbridge_debug` |
| `db_down` | ERROR | connection failed, including a reached connection limit (`retry_in=30`) |
| `session limit not applied` | WARN | the server refused the per-connection statement timeout or read-only setting (the role limits still apply) |
| `grouping_disabled`, `too_many_groups` | WARN | groups switched off (3.5) |
| `group_too_large`, `too_many_ids`, `export_disabled`, `export_too_large` | WARN | a request was refused by a limit (3.8) |
| `set_incomplete` | WARN | a recipient set or export was refused because an entry of it could not be delivered (`dropped=`; see `row_invalid`) |
| `invalid_gid`, `invalid_id` | WARN | an undecodable or foreign group or contact ID in a request (stale link or crafted request; `tag=` correlates repeats without revealing the value) |
| `row_invalid` | WARN | a database row with an invalid address, or whose address does not belong to the row's `domain` column, was dropped (vmail data quality); for the mailbox row of a login: that login gets no directory |
| `row_out_of_scope` | ERROR | a row outside the book's scope (or hidden, or failing the e-mail check) reached PHP and was dropped; the SQL should already exclude it – please report it |
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
integration tests run against the upstream iRedMail 1.8.11 `vmail` schema on MariaDB and PostgreSQL
(and a SQLite mirror of it, tests only) and connect with a role built from the shipped
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
* **mlmmj subscribers are not in SQL** (they live on disk): `membersonly` lists are shown to their
  moderators and owners only, and lists are never groups or expanded.
* **No per-user opt-out column** in iRedMail's SQL editions (the LDAP edition has one): use
  `galbridge_hidden_addresses`, `galbridge_hidden_localparts` or an administrator-created
  `galbridge_visibility_column` (persons only).
* **Caps instead of partial results**: group expansion above the cap, more than `galbridge_max_ids`
  selected contacts, a whole-book export above `galbridge_max_export` and the export of a search
  result above the result cap are refused; search *lists* stop at `galbridge_max_results` /
  `galbridge_global_max_results` with a notice. An export is a file download, so no message can be
  shown with it: a refused export downloads nothing (`204 No Content`, section 4).
* **No rate limiter, by design** (a `$_SESSION` counter is not atomic and turns read-only requests
  into session writers): the plugin bounds every request (result caps, timeouts) and the account
  (connection limit, statement time), but it does not slow down a user who sends many requests.
  Limit `_action=autocomplete` in the reverse proxy (SECURITY.md).
* **MySQL 8.4 and later** do not load `mysql_native_password`, whose verifier the grant tool
  prints: use `grants --clear-text-password` there (section 2.2). MariaDB and MySQL up to 8.0 need
  nothing.
* One cross-domain (global) book; read-only (no editing, no photos).
* The Roundcube login must equal `mailbox.username`; a login under an alias domain gets no books.
* A new own alias of a user (it can make a restricted list visible) applies after
  `galbridge_scope_ttl` (300 s); losing the mailbox takes the directory away with the next request
  (3.9), and directory content is read live on every request.
* Search-only books have no groups; totals stop at `galbridge_max_count`.
* Contact IDs are a reversible encoding of the address (`u_janDkowalskiAexampleDcom`); they appear in
  GET URLs and therefore in web server access logs (SECURITY.md).

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
platform advisory check, remediation of every accepted finding in release 1.0.1; see
[CHANGELOG.md](CHANGELOG.md) and [SECURITY.md](SECURITY.md)). The remediation was then reviewed
once more before the release – area by area, each finding reproduced by a test that fails on the
code before its fix, and the CI steps run as an unprivileged user; what that found is fixed in
1.0.1 as well (changelog, "Fixed after the review of the remediation"). An automated review is not
a certification: report anything it missed as described in SECURITY.md.

Before that, the design was reviewed prior to implementation in three independent reviews –
Roundcube core integration, security and operability – against the Roundcube 1.7.7 and iRedMail
1.8.11 sources, and the implementation of 1.0.0 was reviewed before its release; the accepted
findings are recorded as decisions in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), as threats
(GB-01 to GB-28) with their controls and tests in [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) and
in the 1.0.0 entry of the changelog.
