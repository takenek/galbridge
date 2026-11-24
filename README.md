# GAL Bridge (`galbridge`) – Global Address List for Roundcube 1.7.7

[![CI](https://github.com/takenek/galbridge/actions/workflows/ci.yml/badge.svg)](https://github.com/takenek/galbridge/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/takenek/galbridge.svg)](https://packagist.org/packages/takenek/galbridge)
[![License](https://img.shields.io/packagist/l/takenek/galbridge.svg)](LICENSE)

GAL Bridge gives Roundcube Webmail **1.7.7** a read-only **Global Address List** read directly from
the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and PostgreSQL editions). It uses
only the official plugin API: the `addressbooks_list` and `addressbook_get` hooks and an
`rcube_addressbook` backend. It changes nothing in Roundcube core and has no JavaScript, templates,
skins or database tables of its own: Roundcube's address-book UI renders everything.

* a **domain directory** for every user: the people, alias accounts and mailing lists of the
  user's own domain, or of every domain of the user's organization (`galbridge_organizations`),
* an optional **global directory** across an explicit set of domains (off by default),
* **groups** by department or by domain, usable as recipients: an expansion is either complete or
  refused with a visible message, never a truncated prefix,
* **iRedAPD-aware** alias accounts and mlmmj mailing lists: an entry is shown only to users who may
  post to it (`accesspolicy`),
* every Roundcube 1.7.7 address-book path: source list, contact list with paging, show, print,
  QR code, vCard export (all, selected, search), compose to selected contacts and to a group, copy
  to a personal address book, quick, advanced and saved searches, mail autocomplete (contacts and
  groups), the compose contact list and its search,
* **tenant isolation by construction**: what a user can see comes only from the authenticated login
  and the administrator configuration, never from request parameters (`_source`, `_gid`, `_cid`,
  `_search`, `_q`, `_page`),
* **least privilege, enforced**: a dedicated read-only database role with column-level grants; the
  plugin refuses iRedMail's own roles and any role that can see `mailbox.password`,
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
| Roundcube | **1.7.7** only (`composer.json` `extra.roundcube`: `min-version` 1.7.7, `max-version` 1.7.999). Why: the backend was designed and verified against the address-book call paths of the 1.7.7 core (page sizes, search set replay, group expansion, ID transport, error 700 handling) and is tested on that release only; 1.7.7 is also the Roundcube release that iRedMail 1.8.11 installs. The plugin loads on other 1.7.x releases, but they are not supported and `diag` warns. `min-version` is enforced only by the Composer plugin installer, and a core installed from git or Packagist reports `1.7-git`, which `diag` flags as well. Roundcube 1.6 and older are not supported |
| PHP | 8.1 – 8.5 (composer `php >=8.1 <8.6`); 8.3 or later recommended. `diag` warns for a PHP branch past its security support (8.1 since 2026-01-01) |
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
  configured. With a templated (`%d`, `%n`, `%s`), array or empty `imap_host`, list the directory's
  IMAP hosts in `galbridge_imap_hosts`; otherwise the plugin stays disabled (section 3.9).
* **Verified TLS** for a `vmail` database on another host (section 3.9).
* Recommended: `$config['addressbook_sort_col'] = 'name';` (section 2.5) and a reverse-proxy rate
  limit on `_action=autocomplete` ([SECURITY.md](SECURITY.md#hardening-checklist-for-administrators)).

## 2. Installation

All commands are run in the Roundcube installation directory: `/opt/www/roundcubemail` on iRedMail
(a symlink to `/opt/www/roundcubemail-1.7.7`; `/usr/local/www/roundcube` on FreeBSD), e.g.
`/var/www/roundcube` elsewhere. `www-data` below is the PHP-FPM / web server user: `www-data` on
Debian and Ubuntu, `nginx` on the RHEL family, `www` on FreeBSD and OpenBSD.

### 2.1 Install the plugin files

**git / tarball**

```sh
cd /opt/www/roundcubemail/plugins
git clone https://github.com/takenek/galbridge.git galbridge
git -C galbridge checkout v1.0.0     # a signed release tag, see docs/RELEASING.md section 3
```

The directory **must** be named `plugins/galbridge`.

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
the password plugin's `password_db_dsn`. It also probes the columns the role can see and refuses a
role that can read `mailbox.password`, `recovery_email` or `allow_nets` (log `role_too_broad`).

**Generate the statements** (recommended; nothing is written to disk by the tool):

```sh
cd /opt/www/roundcubemail
umask 077
plugins/galbridge/bin/galbridge.sh grants --driver=mysql > /root/galbridge-grants.sql   # MariaDB/MySQL edition
plugins/galbridge/bin/galbridge.sh grants --driver=pgsql > /root/galbridge-grants.sql   # PostgreSQL edition
```

Options: `--host=` (MySQL: the host part of the account, default `localhost` – use the same host
part as your existing Roundcube account; PostgreSQL: the host written into the DSN), `--db=`
(default `vmail`), `--role=` (default `galbridge`). The output is the grant set of
`SQL/grants.mysql.sql` or `SQL/grants.postgres.sql` with a fresh random password (40 hexadecimal
characters) and, at the end as SQL comments, the matching `$config['galbridge_db_dsn']` line (plus a
TLS reminder for a non-local host). Keep the output private. Apply it as the database administrator:

```sh
mysql < /root/galbridge-grants.sql                                                  # MariaDB / MySQL, as root
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql   # PostgreSQL
```

Copy the DSN line into the plugin configuration (2.3), then delete the file
(`shred -u /root/galbridge-grants.sql`).

**Never edit the templates inside the plugin directory.** iRedMail's nginx serves the files under
`plugins/` through its `static.php` alias (section 2.6): a password written into
`plugins/galbridge/SQL/grants.mysql.sql` would be downloadable. To use a template by hand, copy it
first, e.g. `install -m 0600 plugins/galbridge/SQL/grants.mysql.sql /root/galbridge-grants.sql`, and
replace `CHANGE_ME_RANDOM_PASSWORD` (and `vmail.` if your database has another name) in the copy.

What the role gets:

* **column-level `SELECT` only**, on the columns GAL Bridge reads from `mailbox`, `domain`,
  `forwardings`, `alias`, `maillists`, `moderators` and `maillist_owners`. Never `password`,
  `recovery_email`, `allow_nets`, `settings`, quota, storage or transport columns or admin flags;
  `SELECT *` is impossible by design. The grants keep secrets unreadable; which fields users see is
  decided by `galbridge_fields` (section 3.4). The optional block (`department`, `telephone`,
  `mobile`, `birthday`) can be dropped for fields you do not enable: a configured field whose
  column is not granted simply disappears (log `field_unavailable`, `diag` WARN);
* **PostgreSQL**: a new role (`NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT CONNECTION
  LIMIT 25`) with the role defaults `default_transaction_read_only = on`, `statement_timeout = '5s'`
  and `idle_in_transaction_session_timeout = '10s'`. A new role is required: column grants cannot
  narrow an existing table-level grant;
* **MariaDB**: enable the per-account limits that the template contains as a comment
  (`ALTER USER ... WITH MAX_STATEMENT_TIME 5 MAX_USER_CONNECTIONS 25`; MySQL 8 has no per-user
  statement time). Independently of the role, the plugin sets a read-only session and a statement
  timeout on every connection (`galbridge_db_timeout`).

Size the connection limit to the PHP-FPM pool of Roundcube (`pm.max_children`): a request opens at
most one directory connection, and only when it actually reads the directory. Grants are additive;
re-running the statements is harmless.

A **remote Roundcube host**: MySQL – generate with `--host=<address of the Roundcube host>` and add
`ALTER USER 'galbridge'@'<address>' REQUIRE SSL;`; PostgreSQL – add a line such as
`hostssl vmail galbridge <address>/32 scram-sha-256` to `pg_hba.conf`. The DSN must then verify the
server certificate (section 3.9).

On **PostgreSQL before 15**, `PUBLIC` may still create objects in schema `public`; `diag` warns when
the role can. Consider `REVOKE CREATE ON SCHEMA public FROM PUBLIC;` in `vmail` (the PostgreSQL 15
default) after checking that no other application role relies on it.

### 2.3 Configure the plugin

```sh
cp plugins/galbridge/config.inc.php.dist plugins/galbridge/config.inc.php   # not needed after a Composer install
chown root:www-data plugins/galbridge/config.inc.php
chmod 0640 plugins/galbridge/config.inc.php
```

The file holds the database password: owner root, group = the PHP-FPM user, mode `0640`. `diag`
fails when it is accessible by others. Instead of `plugins/galbridge/config.inc.php` you may use
`config/galbridge.inc.php`, which Roundcube reads **only** when the plugin directory contains no
`config.inc.php`. Both locations are denied by iRedMail's nginx templates. Never keep the DSN in any
other file inside `plugins/galbridge/` (a backup such as `config.old.php` would be downloadable,
section 2.6).

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

### 2.4 Enable the plugin

In `config/config.inc.php` (iRedMail's default list is `['managesieve', 'password', 'zipdownload']`):

```php
$config['plugins'] = [ /* ... */ 'galbridge'];
```

Remove `sql_global_addressbooks` from the list if it is still there
([docs/MIGRATION.md](docs/MIGRATION.md)). GAL Bridge adds its books to `autocomplete_addressbooks`
at runtime (only for compose and autocomplete requests); you do not need to list `galbridge_domain`
or `galbridge_global` there yourself.

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

* Release archives built with `git archive` leave out `tests/` and developer tooling
  (`.gitattributes`).

### 2.7 Test the installation

```sh
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
```

Run it as the PHP / web server user from the Roundcube directory (it must read the plugin
configuration). If the plugin directory is a symlink, set the Roundcube root explicitly:
`sudo -u www-data env ROUNDCUBE_INSTALL_PATH=/opt/www/roundcubemail plugins/galbridge/bin/galbridge.sh diag`.

`diag` reports `[ OK ]`, `[INFO]`, `[WARN]` and `[FAIL]` lines for: the Roundcube version (only 1.7.7
is supported) and the PHP branch; whether the plugin is enabled; the configuration file (location
and mode) and every configuration problem (FAIL for the fail-closed cases such as a malformed
deny-list or overlapping organizations, WARN for dropped allow-list entries); the IMAP host binding
derived from `imap_host`; the interplay with core options (`addressbook_sort_col`, source-ID
collisions with `ldap_public`, the group-expansion cap, the autocomplete minimum); leftovers of
`sql_global_addressbooks`; the DSN policy (refused role, TLS rule) and the PDO extension; the
connection; privilege tripwires (`SELECT password ...` and an `UPDATE` of `mailbox` must both be
denied; on PostgreSQL also `CREATE` in schema `public`, `statement_timeout` and `lc_ctype`); the
column probe (secret columns visible, required columns missing, optional columns missing with the
iRedMail update that adds them); and data sanity (hosted domains, deliverable mailboxes, addresses
with upper-case letters, mailboxes without a name, the indexes on `mailbox(domain)` and
`forwardings(forwarding)`). With `--user=` it also shows that user's home domain, the size of the
address set used for list policies, the entitled books with their data scope, grouping and
search-only mode, and the number of persons, alias accounts and mailing lists in each book with the
time each count took. `--user` does not evaluate the IMAP host binding of a real session.

`diag` never prints the database password or directory contents (counts only). Exit code 0 = no
problem found (warnings do not fail), 1 = usage error, 2 = problems found.

## 3. Configuration overview

Every option, its default and its effect are documented in `config.inc.php.dist`. Numeric options
are clamped to the ranges shown; list options must be PHP arrays, and `'*'` is accepted only as the
sole element. Domains and addresses in lists are normalised once (trim, Unicode NFC, lower case,
IDNA to ASCII, trailing dot removed). An invalid **allow-list** entry is dropped (logged,
`diag` WARN); an invalid **deny-list** disables what it protects (fail closed, `diag` FAIL). A
configuration change takes effect with the next request.

### 3.1 Books

| Source ID | Who sees it | Data scope | Default |
|---|---|---|---|
| `galbridge_domain` | users whose home domain is in `galbridge_domain_viewer_domains` and not in `..._exclude` | the user's home domain, or every domain of its organization | on |
| `galbridge_global` | users whose home domain is in `galbridge_global_viewer_domains` and not in `..._exclude` | `galbridge_global_data_domains` minus `..._exclude`, minus the user's domain-book scope unless `galbridge_global_include_home` | off |

The **home domain** is `mailbox.domain` of the vmail row whose `username` equals the Roundcube
login (an active mailbox in an active, non-backup-MX domain; with `galbridge_hide_expired` neither
of them may be expired); it is never taken from the domain part of the login string. A
login without such a row gets no books. Books are read-only.

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
autocomplete disabled returns nothing.

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
| `galbridge_mark_foreign_domains` | `true` | show global-book entries outside the user's home domain(s) as `Name (domain)` |

Search in the global book always uses **prefix** matching, whatever `addressbook_search_mode` says,
every search word must reach the minimum length, and e-mail addresses match from the start of the
local part (or exactly in strict mode), never on a domain fragment, so searching a domain name does
not return a whole tenant. Its result cap is `galbridge_global_max_results` (100). A global book
whose data scope is empty for a user is not listed. The user's home domain (or organization) is
subtracted even when that user has no domain book (domain book disabled or viewer excluded): set
`galbridge_global_include_home = true` in that case if the global book should show it.

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
| `organization` | organization | the label from `galbridge_organizations` (requires that option) |
| `birthday` | birthday (never searchable; `0001-01-01` = empty) | `mailbox.birthday` |

The effective fields are the allowlist **and** the columns the role can read; nothing outside them
is shown, searched, sorted, grouped on or exported. `galbridge_user_aliases` (default `false`):
per-user alias addresses (`forwardings.is_alias`) are shown and matched by e-mail search only when
enabled; when disabled they are neither shown nor matched, so a search cannot reveal who owns an
alias. Display text is sanitised (Unicode NFC; control, bidi and zero-width characters, CR/LF and
`\ " < >` removed; names capped at 128 characters; a name containing `@` is cut at the `@`);
addresses are lower-case ASCII and must pass Roundcube's e-mail check.

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
  no groups.
* **Expansion** (compose to group, picking a group in autocomplete, group export) returns all members
  or, above min(`galbridge_max_group_members`, core `max_group_members` when set), nothing plus the
  message "This group has more than N members ...". Autocomplete offers only groups that expansion
  would accept.

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
| `galbridge_hide_expired` | `true` | hide mailboxes and domains (and alias accounts and lists, where the column is granted) whose `expired` date has passed |
| `galbridge_hidden_addresses` | `[]` | addresses never shown (persons, alias accounts, lists, per-user aliases) |
| `galbridge_hidden_localparts` | `[]` | local parts hidden in **every** domain, e.g. `['postmaster', 'noreply']` |
| `galbridge_visibility_column` | `''` | name of an integer column you add to `vmail.mailbox`; persons are shown only when it is `1` |

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
column name disables every book. iRedAdmin does not manage such a column.

### 3.8 Limits

| Option | Default | Range | Meaning |
|---|---|---|---|
| `galbridge_min_search_length` | 2 | 1–10 | no query unless at least one search word is this long (global book: every word); shorter words narrow as word prefixes ("Jan K"). In compose, Roundcube's `autocomplete_min_length` is raised to this value |
| `galbridge_max_search_length` | 128 | 16–255 | longer input is not searched |
| `galbridge_max_results` | 500 | 10–5000 | rows of a domain-book search list; more show "Only the first N directory matches are shown" |
| `galbridge_global_max_results` | 100 | 10–5000 | the same for the global book |
| `galbridge_max_ids` | 200 | 1–1000 | contacts used at once (compose to selected, export of selected); more are refused, never cut |
| `galbridge_max_group_members` | 500 | 1–100000 | group expansion cap (lowered further by core `max_group_members` when set) |
| `galbridge_max_groups` | 50 | 1–500 | above it a book shows no groups |
| `galbridge_max_export` | 2000 | 1–100000 | whole-book export is refused above it |
| `galbridge_max_count` | 10000 | 100–1000000 | totals are counted up to it; list pages beyond it are empty |
| `galbridge_searchonly_threshold` | 2000 | 1–1000000 | the `'auto'` search-only limit |

A search splits the input into at most 5 words; every word must match at least one searchable field.

### 3.9 Database connection and IMAP host binding

| Option | Default | Meaning |
|---|---|---|
| `galbridge_db_dsn` | `''` | **mandatory**; drivers `mysql`, `mysqli` or `pgsql` |
| `galbridge_db_connect_timeout` | 2 | seconds (1–30), added to the DSN as `timeout` unless it has one |
| `galbridge_db_timeout` | 3 | seconds (1–30): statement timeout set on every connection (MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`), together with a read-only session |
| `galbridge_db_allow_plaintext` | `false` | accept a remote host without verified TLS (`diag` WARN) |
| `galbridge_db_refused_users` | iRedMail's roles, `root`, `postgres` | role names the plugin refuses (plus the `db_dsnw` and `password_db_dsn` users) |
| `galbridge_imap_hosts` | `[]` | IMAP hosts whose logins may use the directory (bare host names or IP addresses) |
| `galbridge_scope_ttl` | 300 | seconds (30–3600) the resolved user (home domain, address set, column probe) is cached in the session |

**TLS rule.** A unix socket, `127.0.0.0/8`, `::1` and `localhost` count as local. Any other host
needs a verified TLS connection: PostgreSQL `sslmode=verify-ca` or `verify-full` (e.g.
`pgsql://galbridge:PASSWORD@db.example.com/vmail?sslmode=verify-full&sslrootcert=/etc/ssl/certs/db-ca.pem`),
MySQL `ca` or `capath` together with `verify_server_cert=1` (e.g.
`mysql://galbridge:PASSWORD@db.example.com/vmail?ca=/etc/ssl/certs/db-ca.pem&verify_server_cert=1`).
The PostgreSQL `service` parameter is refused. The database server must offer TLS for this
(PostgreSQL `ssl = on` with a `hostssl` line in `pg_hba.conf`; MariaDB `ssl_cert`/`ssl_key` and
`REQUIRE SSL` for the account); check it before moving the database to another host.

**Outages.** When the database cannot be reached, the books answer "The directory is temporarily
unavailable." and the session does not try again for 30 seconds (log `db_down`); Roundcube's errors
log has the driver message. A database outage is never cached as "no entitlement".

**IMAP host binding.** A user who logs in through an IMAP server of their own choosing as
`ceo@tenant-a.example` must not get tenant A's directory. The plugin therefore accepts only sessions
whose IMAP host (`$_SESSION['storage_host']`) and stored user host (`mail_host`) are both directory
hosts. With `galbridge_imap_hosts` empty, the directory host is derived from the administrator's
`imap_host` when it is a single string without `%` placeholders: its host part, lower case, IDNA
(`tls://127.0.0.1:143` → `127.0.0.1`). User preferences and the client's `Host` header play no part.
A templated (`%d`, `%n`, `%s`), array or empty `imap_host` without an explicit list disables the
plugin (`diag` FAIL, log `plugin disabled`). With several IMAP servers that authenticate against the
same vmail database, list them:

```php
$config['galbridge_imap_hosts'] = ['imap1.example.com', 'imap2.example.com'];   // no scheme, port or %
```

### 3.10 Logging

The plugin logs to the Roundcube log `galbridge` (`<log_dir>/galbridge`). With
`log_driver = 'syslog'`, which iRedMail configures (facility `LOG_MAIL`), the lines go to syslog under
Roundcube's ident without the log name. Line format: `LEVEL operation: message key=value ...`, with
the numeric Roundcube user id (`user=`) added automatically. Normal levels carry reason codes,
book ids, counts and SQLSTATEs – never search terms, addresses or names. A reason code triggered by
request input (`invalid_id`, `invalid_gid`, `denied_source`, the limits) is logged at most once per
request. `galbridge_debug = true` (default `false`) adds the database driver's error
text and the messages of unexpected exceptions (sanitised, but they may contain personal data or
infrastructure details); keep it off in normal operation.

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
  member count); the contacts pane lists the books and their groups. Picking a group inserts every
  member.
* **Messages** a user can see:

| Message | Cause |
|---|---|
| The directory is temporarily unavailable. | database error or outage (log `db_error` / `db_down`) |
| This group has more than N members and cannot be added as recipients. ... | group expansion above the cap (3.5) |
| Only the first N directory matches are shown. Refine your search. | search list cap (3.8) |
| At most N directory contacts can be used at once. | `galbridge_max_ids` |
| Exporting this directory is not allowed. | `galbridge_*_export = false` |
| The directory has more than N entries and cannot be exported at once. ... | `galbridge_max_export` |

## 5. Upgrade

**The plugin.** git: `git -C plugins/galbridge fetch --tags && git -C plugins/galbridge checkout
vX.Y.Z` (verify the tag first, docs/RELEASING.md section 3); Composer: `composer update
takenek/galbridge`. Read [CHANGELOG.md](CHANGELOG.md), then run `bin/galbridge.sh diag`. There are no
plugin tables, so there is no schema update; options added by a new release have built-in defaults.

**Roundcube on iRedMail.** iRedMail installs Roundcube into a versioned directory
(`/opt/www/roundcubemail-1.7.7`, reached through the symlink `/opt/www/roundcubemail`), and its
upgrade procedure creates a new tree. The plugin and its configuration live inside the tree, so copy
them into the new one before switching the symlink, otherwise the directory silently disappears:

```sh
cp -a /opt/www/roundcubemail-1.7.7/plugins/galbridge /opt/www/roundcubemail-NEW/plugins/
# only if the configuration lives in config/galbridge.inc.php:
cp -a /opt/www/roundcubemail-1.7.7/config/galbridge.inc.php /opt/www/roundcubemail-NEW/config/
```

`cp -a` keeps owner and mode. Make sure `'galbridge'` and `addressbook_sort_col` are in the new
`config/config.inc.php`, then run `diag` in the new tree. GAL Bridge 1.0.x supports Roundcube 1.7.7
only: on any other version `diag` warns; check for a GAL Bridge release that supports the new core
before upgrading Roundcube. The database role is independent of the Roundcube tree.

## 6. Rollback / uninstall

1. Remove `'galbridge'` from `$config['plugins']`. The books disappear at once; Roundcube and the
   personal address books keep working. GAL Bridge stores no tables and no user preferences (only
   two short-lived session keys, `galbridge` and `galbridge_down_until`). If you added
   `galbridge_domain` or `galbridge_global` to `autocomplete_addressbooks` yourself, remove them:
   Roundcube stops with error 700 for an unknown source.
2. Drop the database role.

   MariaDB / MySQL (use the host part you created; `SELECT user, host FROM mysql.user WHERE user =
   'galbridge';`):
   ```sql
   DROP USER 'galbridge'@'localhost';
   ```
   PostgreSQL, as `postgres`:
   ```sql
   \c vmail
   DROP OWNED BY galbridge;   -- revokes its privileges in vmail, including CONNECT
   DROP ROLE galbridge;
   ```
   `DROP ROLE` alone fails while the role still holds privileges in `vmail`. Remove its
   `pg_hba.conf` line, if you added one.
3. Remove `plugins/galbridge`, `config/galbridge.inc.php` (if used), the nginx rule of 2.6 and the
   `galbridge` log file.

## 7. Troubleshooting

Start with `sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=<login>`; it shows the
cause of almost every "empty directory".

| Symptom | Cause / fix |
|---|---|
| No GAL Bridge book in the address book | `diag`: `galbridge_db_dsn` missing or refused (FAIL on the `galbridge_db_dsn` line with the reason), plugin disabled by the IMAP host binding (FAIL "directory hosts"; log `plugin disabled`), session host not a directory host (log `host_mismatch`: add it to `galbridge_imap_hosts`), login not an active mailbox (`diag --user`: "not found"; log `denied_source status=no_mailbox`), role too broad (log `role_too_broad`), a required column missing (log `column_missing`), a malformed deny-list (log `config_invalid`, `diag` FAIL), home domain not in the viewer list |
| Global book missing | `galbridge_global_viewer_domains` is empty by default; `'*'` data domains without `galbridge_global_allow_wildcard` (log `config_invalid`); its data scope minus the user's own domain(s) is empty |
| "The directory is temporarily unavailable." | log `db_down` (connection; Roundcube's errors log has the driver message; next attempt after 30 s) or `db_error sqlstate=…`: `42000` (MariaDB/MySQL: access denied – grants), `42501` (PostgreSQL: permission denied – grants), `42S22` / `42703` (unknown column – iRedMail schema or the `galbridge_visibility_column` name), `70100` (MariaDB `max_statement_time`), `57014` (PostgreSQL `statement_timeout`), `HY000` (MySQL, includes `max_execution_time`) |
| Autocomplete or search finds nothing | fewer than `galbridge_min_search_length` characters (global book: in every word); global book matches prefixes only and e-mail from the local part; `galbridge_*_autocomplete = false`; search-only book opened without a search |
| Groups missing | search-only book; more than `galbridge_max_groups` (log `too_many_groups`); department groups without the `department` field (log `grouping_disabled`); `galbridge_*_groups = 'none'` |
| A field (phone, last name, ...) missing | not in `galbridge_fields`, column not granted or missing (log `field_unavailable`; iRedMail `update/1.7.3` adds `first_name`, `last_name`, `mobile`, `telephone`, `birthday`); `organization` needs `galbridge_organizations` |
| An alias account or list missing | its `accesspolicy` does not allow the user to post (3.6); policy value unknown (hidden); no active member / list row in `forwardings`; `diag --user` shows the counts |
| Search results in e-mail order | `addressbook_sort_col` is not `'name'` (2.5) |
| Accented names found on MariaDB but not on PostgreSQL | engine difference, see 10 |
| Error 700 "Addressbook source (...) not found" | a source listed in `autocomplete_addressbooks` that no longer exists (e.g. `domain` / `global` of the old plugin, or `galbridge_*` after uninstalling) |
| Details | `<log_dir>/galbridge` or the mail log (syslog); reason codes below |

**Log reason codes** (`LEVEL operation: code: message key=value`):

| Code | Level | Meaning |
|---|---|---|
| `config_invalid` | ERROR | an option is invalid (`option=` names it); fail-closed cases disable the protected book or every book |
| `dsn_warning` | WARN | remote database accepted without verified TLS (`galbridge_db_allow_plaintext`) |
| `plugin disabled` / `host_mismatch` | WARN | IMAP host binding (3.9) |
| `denied_source` | INFO | a GAL Bridge book was requested that the user cannot use; `status=` gives the reason: `no_mailbox`, `disabled`, `host_mismatch`, `invalid_login`, `role_too_broad`, `db_down`, `db_error`, or `ok` (not entitled by the viewer lists or the data scope) |
| `role_too_broad` | ERROR | the role can read `password`, `recovery_email` or `allow_nets`: every book is denied |
| `column_missing` | ERROR | a required vmail column is missing or not granted (the book is denied) |
| `field_unavailable` | WARN | configured fields whose columns are missing or not granted |
| `db_error` | ERROR | a query failed (`sqlstate=`); the driver text only with `galbridge_debug` |
| `db_down` | ERROR | connection failed (`retry_in=30`) |
| `session limit not applied` | WARN | the server refused the per-connection statement timeout or read-only setting (the role limits still apply) |
| `grouping_disabled`, `too_many_groups` | WARN | groups switched off (3.5) |
| `group_too_large`, `too_many_ids`, `export_disabled`, `export_too_large` | WARN | a request was refused by a limit (3.8) |
| `invalid_gid`, `invalid_id` | WARN | an undecodable or foreign group or contact ID in a request (stale link or crafted request; `tag=` correlates repeats without revealing the value) |
| `row_dropped` | WARN / ERROR | a database row failed the PHP re-check (invalid address or domain; ERROR = outside the book's scope, which the SQL should already exclude – please report it) |
| `unexpected error` | ERROR | an unexpected exception (`exception=` class; message only with `galbridge_debug`) |

## 8. Security

See [SECURITY.md](SECURITY.md) and [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md). In short: the
visible scope of every query is derived from the authenticated login, resolved in vmail, and the
administrator configuration only; every statement is built by one query builder whose root predicate
is the scope, and every returned row is re-checked in PHP. Unentitled, stale or crafted source IDs get
an inert book that never touches the database. The plugin reads with a dedicated column-restricted,
read-only role and refuses broader ones. It is read-only and adds no endpoints. A compromised web
server, Roundcube installation or administrator is out of scope.

## 9. Compatibility

Supported: Roundcube 1.7.7, PHP 8.1–8.5, iRedMail 1.8.x MariaDB/MySQL and PostgreSQL editions. The
tested versions and the exact results are listed in [docs/TESTING.md](docs/TESTING.md). The
integration tests run against the upstream iRedMail 1.8.11 `vmail` schema on MariaDB and PostgreSQL
(and a SQLite mirror of it, tests only) and connect with a role built from the shipped
`SQL/grants.*.sql`, so they also prove that the documented grants are sufficient.

GAL Bridge has no templates, JavaScript or CSS, so it does not depend on the skin. It coexists with
Roundcube's personal address books, collected addresses and LDAP books; an `ldap_public` entry named
`galbridge_domain` or `galbridge_global` would shadow a GAL Bridge book (`diag` FAIL).

## 10. Known limitations

* **Accent folding differs between the engines.** MariaDB's `utf8mb4_general_ci` compares case- and
  accent-insensitively ("zolkiewska" finds "Żółkiewska"); PostgreSQL's `ILIKE` folds case only, and
  for non-ASCII letters only when the database's `lc_ctype` is not `C`/`POSIX` (`diag` warns).
* **Roundcube re-sorts search results** by `addressbook_sort_col` in PHP; the plugin cannot change
  that (2.5).
* **mlmmj subscribers are not in SQL** (they live on disk): `membersonly` lists are shown to their
  moderators and owners only, and lists are never groups or expanded.
* **No per-user opt-out column** in iRedMail's SQL editions (the LDAP edition has one): use
  `galbridge_hidden_addresses`, `galbridge_hidden_localparts` or an administrator-created
  `galbridge_visibility_column` (persons only).
* **Caps instead of partial results**: group expansion above the cap, more than `galbridge_max_ids`
  selected contacts and a whole-book export above `galbridge_max_export` are refused; search lists
  stop at `galbridge_max_results` / `galbridge_global_max_results` with a notice. An export of a
  search result is a file download, so the notice cannot be shown there: it contains at most that
  many entries.
* **No rate limiter, by design** (a `$_SESSION` counter is not atomic and turns read-only requests
  into session writers): use result caps, timeouts, the role's connection limit and a reverse-proxy
  limit (SECURITY.md).
* One cross-domain (global) book; read-only (no editing, no photos).
* The Roundcube login must equal `mailbox.username`; a login under an alias domain gets no books.
* Changes in vmail that affect the user's own scope (home domain, own aliases) apply after
  `galbridge_scope_ttl` (300 s); directory content is read live on every request.
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

The design was reviewed before implementation in three independent reviews – Roundcube core
integration, security and operability – against the Roundcube 1.7.7 and iRedMail 1.8.11 sources.
Every accepted finding is recorded as a decision in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) or
as a threat (GB-01 to GB-28) with its control and tests in
[docs/THREAT_MODEL.md](docs/THREAT_MODEL.md). No independent audit of the 1.0.0 code has been
published. A review is not a certification: report anything it missed as described in
[SECURITY.md](SECURITY.md).
