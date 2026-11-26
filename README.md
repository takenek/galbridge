# GAL Bridge (`galbridge`) – Global Address List for Roundcube 1.7

[![CI](https://github.com/takenek/galbridge/actions/workflows/ci.yml/badge.svg)](https://github.com/takenek/galbridge/actions/workflows/ci.yml)
[![Packagist](https://raw.githubusercontent.com/takenek/galbridge/main/.github/badges/packagist-v1.0.8.svg)](https://packagist.org/packages/takenek/galbridge)
[![License](https://raw.githubusercontent.com/takenek/galbridge/main/.github/badges/license.svg)](LICENSE)

GAL Bridge gives Roundcube Webmail **1.7** (every 1.7.x release) a read-only **Global Address
List** read directly from the SQL `vmail` database of **iRedMail 1.8.x** (MariaDB/MySQL and
PostgreSQL editions): the people, alias accounts and mailing lists of the user's domain appear in
the address book, in compose autocomplete and in the compose contact list.

* a **domain directory** for every user – the user's own domain, or every domain of the user's
  organization – and an optional **global directory** across an explicit set of domains,
* **groups** by department or by domain, usable as recipients: an expansion is complete or refused
  with a visible message, never a truncated prefix,
* **iRedAPD-aware** alias accounts and mailing lists: an entry is shown only to users who may post
  to it,
* **tenant isolation by construction**: what a user can see comes only from the authenticated login
  and the administrator configuration, never from request parameters,
* **least privilege, enforced**: a dedicated read-only database role with column-level grants; the
  plugin refuses iRedMail's own roles and any role that can do more than read the directory,
* a diagnostics and grant tool (`bin/galbridge.sh diag`, `bin/galbridge.sh grants`), English and
  Polish translations.

It uses only Roundcube's plugin API and changes nothing in Roundcube core; it has no JavaScript,
templates, skins or database tables of its own. GAL Bridge replaces the unmaintained
*Roundcube-SQL-Global-Address-Books* plugin (`sql_global_addressbooks`); it was written from
scratch – see [docs/MIGRATION.md](docs/MIGRATION.md).

**To install it on an iRedMail server, go to [section 2](#2-installation)**: seven steps, each a
block of commands with the result to expect.

Created and maintained by **[Marek Królikowski (TaKeN)](https://github.com/takenek)**,
operating as **TaKeN.PL Usługi Informatyczne Marek Królikowski**.

| Document | What it holds |
|---|---|
| this README | requirements, the installation on a standard iRedMail server, short notes on configuration, upgrade, uninstall and troubleshooting |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | sections 1 and 2 in full: every requirement and installation step with its reasons – Composer, other systems and web servers, a remote database, the grant set, everything `diag` checks |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | sections 3, 4 and 10 in full: every option with its default, what users see, the known limitations |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | sections 5 – 7 in full: upgrade (and the notes of every earlier release), rollback and uninstall, troubleshooting and the log reason codes |
| [SECURITY.md](SECURITY.md) | supported versions, reporting a vulnerability, the release signing key, the hardening checklist, accepted risks |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) · [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) | design decisions; threats and their controls |
| [docs/MIGRATION.md](docs/MIGRATION.md) | from `sql_global_addressbooks` |
| [docs/TESTING.md](docs/TESTING.md) · [docs/RELEASING.md](docs/RELEASING.md) · [CHANGELOG.md](CHANGELOG.md) | the test suites and their results, the release procedure, the changes of every release |

Section numbers are shared by this README and the documents of `docs/`: README section 2.6 is the
short form of section 2.6 of docs/INSTALLATION.md, and a reference such as "section 3.9" means
section 3.9 of docs/CONFIGURATION.md.

---

## 1. Requirements

| Component | Requirement |
|---|---|
| Roundcube | **1.7.x** – the whole 1.7 branch (tested: 1.7.0 and 1.7.7, the release iRedMail 1.8.11 installs). Run the newest 1.7.x: its patch releases fix security problems of Roundcube itself and need no new GAL Bridge release. Roundcube 1.6 and older are not supported |
| PHP | 8.1 – 8.5, the versions Roundcube 1.7 itself runs on; 8.3 or later recommended |
| PHP extensions | `pdo`, `mbstring`, and `pdo_mysql` (MariaDB/MySQL edition) or `pdo_pgsql` (PostgreSQL edition); recommended: `intl` (IDN domains, Unicode normalisation) |
| iRedMail | **1.8.x, MariaDB/MySQL or PostgreSQL edition**. The OpenLDAP edition is out of scope: there iRedMail configures Roundcube's native LDAP address book |
| Database server | MariaDB 10.2 or later, MySQL 8.0 or later, or PostgreSQL – every platform of iRedMail 1.8 qualifies. **MySQL 8.4 and later**: create the role with `grants --clear-text-password` (2.2) |
| Database account | a dedicated read-only role on the `vmail` database (2.2) |
| Web server | must not serve `plugins/galbridge/` (2.6); per-client request limits when the webmail is reachable from the Internet (2.6) |

On a standard iRedMail server section 2 meets every installation requirement. The tested database
server versions, CI, and the requirements of other set-ups – a templated or remote IMAP server, a
database on another host, Roundcube's `include_host_config`:
[docs/INSTALLATION.md, section 1](docs/INSTALLATION.md#1-requirements).

## 2. Installation

The steps for a **standard iRedMail 1.8 server**: Debian or Ubuntu, nginx, Roundcube in
`/opt/www/roundcubemail` (iRedMail's symlink to the versioned directory, e.g.
`/opt/www/roundcubemail-1.7.7`), MariaDB/MySQL or PostgreSQL edition. Run them in order, as root,
from a plain root shell (no `ROUNDCUBE_*` or `PG*` variables set). `www-data` is the PHP-FPM user of
Debian and Ubuntu. Replace `mail.example.com` by the host name in your webmail's certificate and
`jan.kowalski@example.com` by a login of your server. Each step ends with a link to its full text in
[docs/INSTALLATION.md](docs/INSTALLATION.md) – the reasons, the alternatives (Composer, other systems
and web servers, a remote database) and every case that is not the standard one.

`sudo` is not part of iRedMail, and a Debian server installed with a root password does not have it.
`curl`, needed for the check of 2.6, comes with iRedMail only together with its optional netdata
component (on Debian 13 selected by default in the installer); on a server without it:
`apt install curl`. For `sudo` either `apt install sudo`, or write each `sudo -u www-data ...`
command as `runuser -u www-data -- ...` (util-linux, always installed), run as root from the same
directory – e.g. `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag`.

### 2.1 Install the plugin files

Install a release from its signed tag:

```sh
apt install git openssh-client   # Debian/Ubuntu: a minimal server (and iRedMail) may have neither
v=1.0.8   # the release to install: https://github.com/takenek/galbridge/releases (only the latest is supported)
git clone --branch "v$v" https://github.com/takenek/galbridge.git /root/galbridge-src   # NOT below the web root
git -C /root/galbridge-src -c gpg.ssh.allowedSignersFile=/root/galbridge-src/.github/release-signers verify-tag "v$v"
```

Nothing is installed yet. `verify-tag` must print
`Good "git" signature for galbridge-release with RSA key SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`.
The allowed-signers file comes from the same clone as the tag, so it proves nothing by itself:
**compare the fingerprint** with SECURITY.md as GitHub shows it and with the SSH signing keys of the
maintainer's GitHub account – both commands must print the same `SHA256:` value as `verify-tag`:

```sh
python3 -c 'import urllib.request as u; print([l for l in u.urlopen("https://raw.githubusercontent.com/takenek/galbridge/main/SECURITY.md").read().decode().splitlines() if l.startswith("| Fingerprint")][0])'
python3 -c 'import json, urllib.request as u; [print(k["key"]) for k in json.load(u.urlopen("https://api.github.com/users/takenek/ssh_signing_keys"))]' | ssh-keygen -lf -
```

The second one prints `8192 SHA256:... no comment (RSA)` (on GitHub the key is titled "MIME Shield
release signing": the maintainer signs both projects with it). If the tag does not exist,
`verify-tag` fails or a fingerprint differs, do not install (`rm -rf /root/galbridge-src`). Only when
all three agree, install the files of the verified tag and remove the clone:

```sh
git -C /root/galbridge-src -c tar.umask=0022 archive --prefix=galbridge/ "v$v" | tar -x -C /opt/www/roundcubemail/plugins
rm -rf /root/galbridge-src
```

The directory must be named `plugins/galbridge`. Never put a git clone into `plugins/`: it brings
`tests/`, scripts that drop databases. GitHub's "Source code" downloads of a release page carry no
signature: they are no installation method.

**With Composer** instead: on iRedMail give `config/config.inc.php` to root and create an empty
`config/galbridge.inc.php` first, run `composer require` as root in an interactive shell with
`--ignore-platform-req=ext-ldap`, answer `n` when it asks whether to activate the plugin, and then
compare the installed files with the verified tag – Composer does not verify tag signatures. The
commands are in the details.

Details: [docs/INSTALLATION.md, 2.1](docs/INSTALLATION.md#21-install-the-plugin-files)

### 2.2 Create the dedicated read-only database role

GAL Bridge reads the `vmail` database with its **own** read-only role; it refuses iRedMail's roles
(`vmail`, `roundcube`, ...) and any role that can do more than read the directory. The database
administrator runs what `grants` prints, so check the plugin files with the system's tools first,
then generate the statements as root, with the one `grants` line of your edition:

```sh
cd /opt/www/roundcubemail
find -L plugins/galbridge \( ! -user root -o -perm /022 \) -print   # must print nothing (GNU find)
namei -l "$PWD/plugins/galbridge/bin/galbridge.sh"   # every line from / down: owner root, no "w" in the group or other columns (excepted: /tmp-style "t", and symbolic links such as roundcubemail -> roundcubemail-1.7.7, always lrwxrwxrwx)
umask 077     # the SQL file must be readable by root only
plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=mysql > /root/galbridge-grants.sql 2> /root/galbridge-dsn.txt   # MariaDB/MySQL edition
# PostgreSQL edition: this line instead of the one above (both write the same two files)
# plugins/galbridge/bin/galbridge.sh grants --allow-root --driver=pgsql > /root/galbridge-grants.sql 2> /root/galbridge-dsn.txt
```

If `find` prints anything, or a line of `namei` is not owned by root or has a `w` in the group or
other column, stop: the plugin code must belong to root and be writable by nobody else –
`chown -R root plugins/galbridge && chmod -R go-w plugins/galbridge`, and for files that a Roundcube
upgrade left to another account docs/OPERATIONS.md section 5. `grants` prints nothing on the terminal: the SQL goes into
`/root/galbridge-grants.sql`, the DSN line with the password into `/root/galbridge-dsn.txt` (2.3 puts
it in place) – both readable by root only; a refusal goes into `/root/galbridge-dsn.txt` instead and
leaves the SQL file empty. **Keep the default connection limit of 25 on iRedMail**: MariaDB keeps
`max_connections = 151`, PostgreSQL 100, shared with every mail service. **MySQL 8.4 and later**
(which do not load `mysql_native_password`): add `--clear-text-password` to the `grants` line.

**Review the output before you apply it** – it is a script for the database administrator:

```sh
head -n 2 /root/galbridge-grants.sql
grep -n '\\' /root/galbridge-grants.sql
diff <(grep -v -e '^--' -e '^$' plugins/galbridge/SQL/grants.mysql.sql) <(grep -v -e '^--' -e '^$' /root/galbridge-grants.sql)
# PostgreSQL edition: grants.postgres.sql in place of grants.mysql.sql
```

1. The second line says
   `-- It creates the role 'galbridge'. When a role of that name already exists it stops and changes nothing (see --reset-password, --grants-only).`
2. `grep` prints nothing: the output holds no backslash, so no command of the database client.
3. The `diff` shows one changed line, the account line, which carries the verifier where the template
   has its `CHANGE_ME_...` placeholder – MariaDB/MySQL the `CREATE USER` line (`1c1`), PostgreSQL the
   `CREATE ROLE` line. The verifier counts as a secret: run the `diff` where nobody watches the
   terminal, and do not paste its output anywhere.

Apply it as the database administrator, with the line of your edition:

```sh
mysql --binary-mode < /root/galbridge-grants.sql                                          # MariaDB / MySQL, as root (never with --force)
runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d vmail < /root/galbridge-grants.sql   # PostgreSQL
```

It must end without an error. `ERROR 1396` (MariaDB/MySQL) or `role "galbridge" already exists`
(PostgreSQL) means that a role of that name exists and nothing was changed – see the details before
you go on. Then delete the file: `shred -u /root/galbridge-grants.sql`.

**PostgreSQL edition: keep the role to the vmail database.** iRedMail's `pg_hba.conf` accepts a
password login for **every** database – `local all all md5` over the unix socket, a catch-all `host`
line over TCP – and `PUBLIC` may connect to `roundcubemail`, `amavisd`, `iredadmin`, ... Insert
these four lines directly **before** iRedMail's `local all all md5` line:

```
local      all    galbridge                                       reject          # no login over the unix socket
host       vmail  galbridge  127.0.0.1/32                         scram-sha-256   # Roundcube on this host
host       all    galbridge  0.0.0.0/0                            reject          # no other database, no other address
host       all    galbridge  ::/0                                 reject
```

This finds the file and inserts them there (once; a file that already has a `local all galbridge`
line is left alone):

```sh
f=$(runuser -u postgres -- psql -XAtc 'SHOW hba_file')   # Debian 13 (iRedMail 1.8.11): /etc/postgresql/17/main/pg_hba.conf
grep -qE '^local[[:space:]]+all[[:space:]]+galbridge[[:space:]]' "$f" || sed -i -E '0,/^local[[:space:]]+all[[:space:]]+all[[:space:]]/s//local      all    galbridge                                       reject          # no login over the unix socket\
host       vmail  galbridge  127.0.0.1\/32                         scram-sha-256   # Roundcube on this host\
host       all    galbridge  0.0.0.0\/0                            reject          # no other database, no other address\
host       all    galbridge  ::\/0                                 reject\
&/' "$f"
grep -nvE '^[[:space:]]*(#|$)' "$f"   # your four lines directly above iRedMail's "local all all md5"
```

A file with an error is not loaded – PostgreSQL keeps the old rules and only writes a log line –, so
check it, then reload and test as root:

```sh
runuser -u postgres -- psql -X -c "SELECT line_number, type, database, user_name, address, auth_method, error FROM pg_hba_file_rules WHERE error IS NOT NULL OR 'galbridge' = ANY(user_name)"   # your four lines, no error
systemctl reload postgresql
psql -X -w -h 127.0.0.1 -U galbridge -d roundcubemail -c ''   # must fail: pg_hba.conf rejects connection for host "127.0.0.1", user "galbridge" ...
psql -X -w -U galbridge -d roundcubemail -c ''                 # must fail: pg_hba.conf rejects connection for host "[local]", user "galbridge" ...
```

Over TCP psql may print the refusal twice (`... SSL encryption`, then `... no encryption`); both are
the expected refusal. `fe_sendauth: no password supplied` instead means that the `reject` line does
not apply: it is not loaded, or it sits below iRedMail's lines.

Details: [docs/INSTALLATION.md, 2.2](docs/INSTALLATION.md#22-create-the-dedicated-read-only-database-role)

### 2.3 Configure the plugin

```sh
cd /opt/www/roundcubemail
# also after a Composer install: it replaces the still empty config/galbridge.inc.php of 2.1
install -o root -g www-data -m 0640 plugins/galbridge/config.inc.php.dist config/galbridge.inc.php
```

In the copied file the line `$config['galbridge_db_dsn'] = '';` is replaced by the DSN line of
`/root/galbridge-dsn.txt` – **without the leading `-- `**: with it the file does not parse, and once
the plugin is enabled every webmail page of a logged-in user stops with a parse error. These commands
do it without showing the password:

```sh
grep -q "^-- \$config\['galbridge_db_dsn'\] = '" /root/galbridge-dsn.txt && sed -i "s|^\$config\['galbridge_db_dsn'\] = '';\$|$(grep -o "\$config\['galbridge_db_dsn'\] = '[^']*';" /root/galbridge-dsn.txt)|" config/galbridge.inc.php
grep -c "^\$config\['galbridge_db_dsn'\] = '[a-z]*://" config/galbridge.inc.php   # must print 1 (0: no DSN line in the file - paste it by hand)
php -l config/galbridge.inc.php                                                  # must print "No syntax errors detected ..."
shred -u /root/galbridge-dsn.txt
```

Leave everything else at its default: with iRedMail's defaults every user gets the domain directory
of their own domain (the options: section 3). The file holds the database password and the
directory policy: keep the owner root, the group `www-data` and the mode `0640` that `install` gave
it, and run `php -l config/galbridge.inc.php` after every edit.

Details: [docs/INSTALLATION.md, 2.3](docs/INSTALLATION.md#23-configure-the-plugin)

### 2.4 Enable the plugin

Add `'galbridge'` to the plugin list of `config/config.inc.php` – on iRedMail 1.8.11 the line becomes
`$config['plugins'] = array('managesieve', 'password', 'zipdownload', 'galbridge');`. iRedMail
installs that file as the PHP user (`www-data:www-data`, mode `0600`), so the web application could
rewrite the directory policy: give it to root and let the PHP group read it.

```sh
cd /opt/www/roundcubemail
grep -q "'galbridge'" config/config.inc.php || sed -i "s/^\(\$config\['plugins'\] = array('.*'\));\$/\1, 'galbridge');/" config/config.inc.php
grep -n "^\$config\['plugins'\]" config/config.inc.php   # the plugin list ends with 'galbridge'
chown root:www-data config/config.inc.php && chmod 0640 config/config.inc.php
php -l config/config.inc.php
```

When the list is written in another form, `sed` changes nothing: add `'galbridge'` by hand. Remove
`sql_global_addressbooks` from the list if it is still there ([docs/MIGRATION.md](docs/MIGRATION.md)).
`php -l` must print `No syntax errors detected ...`.

Details: [docs/INSTALLATION.md, 2.4](docs/INSTALLATION.md#24-enable-the-plugin)

### 2.5 Recommended Roundcube settings

iRedMail ships `//$config['addressbook_sort_col'] = 'name';` commented out in
`config/config.inc.php`; remove the leading `//` (rather than adding a second line):

```sh
sed -i "s|^//\(\$config\['addressbook_sort_col'\] = 'name';\)\$|\1|" config/config.inc.php
grep -n "addressbook_sort_col" config/config.inc.php   # one line, without the leading //
```

Otherwise search results come out in e-mail address order (Roundcube re-sorts them by last name,
which open-source iRedAdmin leaves empty), and `diag` warns.

Details: [docs/INSTALLATION.md, 2.5](docs/INSTALLATION.md#25-recommended-roundcube-settings)

### 2.6 Web server: keep the plugin directory unreachable

iRedMail's nginx templates `roundcube.tmpl` (webmail under `/mail/`) and `roundcube-subdomain.tmpl`
pass `static.php/...` requests straight to the Roundcube directory: without this rule, before every
`static.php` location of both templates, the plugin's files can be downloaded:

```nginx
location ~ ^/(mail/)?(static\.php/)?plugins/galbridge(-[0-9]+)?/ { deny all; }
```

This puts it directly below the line `# Block direct access to directories and files.` of each
template (once; a template that already has it is left alone):

```sh
for t in /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl; do grep -q 'plugins/galbridge' "$t" || sed -i '/^# Block direct access to directories and files\.$/a location ~ ^/(mail/)?(static\\.php/)?plugins/galbridge(-[0-9]+)?/ { deny all; }' "$t"; done
grep -n 'plugins/galbridge' /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl   # one line in each (line 5 when this command put it there; any line before the first static.php location is right)
```

**Per-client limits** – required when the webmail is reachable from the Internet (iRedMail's
default; iRedMail sets none, and the plugin has no rate limiter of its own). This puts the two
`*_zone` lines into `/etc/nginx/conf-enabled/roundcube-limits.conf` and a request-rate and a
concurrency limit per client address at the top of the PHP location of both templates, with the
values of the [SECURITY.md checklist](SECURITY.md#hardening-checklist-for-administrators) (once:
zones that nginx already reads and a template with `limit_req` or `limit_conn` lines are left
alone):

```sh
f=/etc/nginx/conf-enabled/roundcube-limits.conf
nginx -T 2>/dev/null | grep -qE 'zone=rc_(php|conn):' || printf '%s\n' 'limit_req_zone  $binary_remote_addr zone=rc_php:10m rate=10r/s;' 'limit_conn_zone $binary_remote_addr zone=rc_conn:10m;' > "$f"
for t in /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl; do grep -q 'limit_req\|limit_conn' "$t" || sed -i '/^location ~ ^\/\(mail\/\)\{0,1\}(\.\*\\\.php)\$ {$/a\    limit_req  zone=rc_php burst=40 nodelay;\n    limit_conn rc_conn 8;' "$t"; done
grep -n -A2 'php)\$ {$' /etc/nginx/templates/roundcube.tmpl /etc/nginx/templates/roundcube-subdomain.tmpl   # the two lines directly below the PHP location of each template
```

The concurrency limit (8) stays well below the role's connection limit (25). The limits are per
client address: every user behind one NAT address (an office, a VPN exit) shares them, and nginx
answers a request beyond them with 503 – SECURITY.md says how to size or exempt such networks.

Reload once for both changes, then wait a few seconds – until nginx's old worker processes are gone
a request can still be answered with the previous configuration:

```sh
nginx -t && systemctl reload nginx
```

Check that the plugin source is no longer served. Both requests must answer `403` (or `404`); a
`200` means the rule is not effective:

```sh
curl -s -o /dev/null -w '%{http_code}\n' --cacert /etc/ssl/certs/iRedMail.crt https://mail.example.com/mail/static.php/plugins/galbridge/lib/autoload.php
curl -s -o /dev/null -w '%{http_code}\n' --cacert /etc/ssl/certs/iRedMail.crt https://mail.example.com/mail/plugins/galbridge/lib/autoload.php
```

`000` means that `curl` got no answer it could verify: use the host name of the certificate, never
`127.0.0.1` or `localhost`, which the certificate does not name. iRedMail's self-signed
`/etc/ssl/certs/iRedMail.crt` is issued for the server's host name (`hostname -f`); this prints it:
`openssl x509 -in /etc/ssl/certs/iRedMail.crt -noout -subject -nameopt multiline | sed -n 's/^ *commonName *= *//p'`.
When that name does not resolve to this server on the server itself, add it to `/etc/hosts`. Once a
trusted certificate has replaced the self-signed one, leave out `--cacert` (and `--cafile=` in 2.7).
For `roundcube-subdomain.tmpl` (the webmail on a host name of its own) leave out the `/mail` prefix,
here and in 2.7.

An iRedMail upgrade can replace both templates: run the blocks of this step again after every
iRedMail upgrade (section 5).

Details: [docs/INSTALLATION.md, 2.6](docs/INSTALLATION.md#26-web-server-keep-the-plugin-directory-unreachable)

### 2.7 Test the installation

`diag` runs as the PHP user from the Roundcube directory (as root it refuses to run):

```sh
cd /opt/www/roundcubemail
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --url=https://mail.example.com/mail/ --cafile=/etc/ssl/certs/iRedMail.crt
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --strict --url=https://mail.example.com/mail/   # automated checks: WARN = exit code 2 (same --cafile= as above)
```

Without `sudo`: `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag ...`, the same
options.

On a standard iRedMail server set up like this, the first two end with
`Result: OK (not checked: web access - run with --url)` and the others with `Result: OK` – no FAIL,
no WARN; look into any WARN: each line says what to do. Among the first lines `[ OK ] GAL Bridge`
names the release of 2.1; `--user` shows the home domain and the books of that login with their
counts. A cron or monitoring job runs the `--strict` form, with the `--url=` (and `--cafile=`):
without `--url` it checks no web access. `diag` does not check the general web server and Roundcube
items of the [SECURITY.md hardening checklist](SECURITY.md#hardening-checklist-for-administrators)
(HTTPS only, `use_https`, `session_samesite`).

Then log in to the webmail and open the address book: the source list shows "Domain directory"
("Katalog domeny" in Polish) with the people of your domain.

Details: [docs/INSTALLATION.md, 2.7](docs/INSTALLATION.md#27-test-the-installation)

## 3. Configuration overview

With iRedMail's defaults nothing but the DSN of 2.3 is needed: every user gets the domain directory
of their own domain. Every option, its default and its effect are documented in
[`config.inc.php.dist`](config.inc.php.dist). Set options in `config/galbridge.inc.php`, run
`php -l config/galbridge.inc.php` after every edit and check the result with `diag` (2.7); a change
takes effect with the next request.

These mistakes disable **every** book until they are corrected (`diag` FAIL and the log line
`config_invalid` name the cause):

* a switch written as a string – `'false'`, `'no'` or `'off'`, which PHP would read as true: write
  `true` or `false` (the two `searchonly` options also take `'auto'`);
* a `galbridge_*` option this version does not know, usually a typo (`diag` names the closest valid
  one);
* a `galbridge_*` option other than the two book names in a per-host file of Roundcube's
  `include_host_config` (for requests with that host name): keep every `galbridge_*` option in the
  plugin's own file, as plain assignments;
* a second configuration file: `config/galbridge.inc.php` **and** `plugins/galbridge/config.inc.php`
  – keep one.

Never put `galbridge_*` names into `$config['dont_override']`: Roundcube then writes their current
value into the preferences of every user who saves settings (`diag` fails for `galbridge_db_dsn`
there).

Example – a partner directory for one company (the global book, 3.3):

```php
$config['galbridge_global_book'] = true;
$config['galbridge_global_book_name'] = 'Partner directory';
$config['galbridge_global_viewer_domains'] = ['acme.example'];
$config['galbridge_global_data_domains'] = ['partner-a.example', 'partner-b.example'];
```

The options in detail, with their defaults and reasons:

* [3.1 Books](docs/CONFIGURATION.md#31-books) – the domain book (on) and the global book (off), who sees them, search-only books
* [3.2 Organizations](docs/CONFIGURATION.md#32-organizations) – several domains that share one domain book
* [3.3 Global book](docs/CONFIGURATION.md#33-global-book) – a directory across an explicit set of domains, the `(domain)` marker
* [3.4 Fields](docs/CONFIGURATION.md#34-fields) – the field allowlist, per-user aliases, display-text sanitising, the address rule
* [3.5 Groups](docs/CONFIGURATION.md#35-groups) – by department or by domain; an expansion is complete or refused
* [3.6 Alias accounts, mailing lists and posting policies](docs/CONFIGURATION.md#36-alias-accounts-mailing-lists-and-posting-policies) – iRedAPD's `accesspolicy`
* [3.7 Hiding entries](docs/CONFIGURATION.md#37-hiding-entries) – hidden addresses and local parts, expiry, a visibility column
* [3.8 Limits](docs/CONFIGURATION.md#38-limits) – search lengths, result, export and group caps, page sizes
* [3.9 Database connection and IMAP host binding](docs/CONFIGURATION.md#39-database-connection-and-imap-host-binding) – DSN rules, TLS for a remote database, `galbridge_imap_hosts`, revocation
* [3.10 Logging](docs/CONFIGURATION.md#310-logging) – where the lines go, what they hold, `galbridge_debug`

## 4. Using it

* **Address book**: the books appear in the source list – "Domain directory" and "Global directory"
  (in Polish "Katalog domeny" and "Katalog globalny"). They are read-only: contacts can be opened,
  printed, shown as QR code, exported as vCard, copied to a personal address book and used for
  "compose to selected"; groups appear below a book when grouping is enabled.
* **Search**: at least one word of `galbridge_min_search_length` (2) characters – in the global book
  every word, matched as a prefix.
* **Compose**: autocomplete includes the books and matching groups, also in the Redirect dialog;
  picking a group inserts every member.
* **Complete or refused**: a group above the expansion cap and too many selected contacts are refused
  with a message, never cut; a refused export downloads nothing.

The messages users can see, their causes and the export rules: [docs/CONFIGURATION.md, section 4](docs/CONFIGURATION.md#4-using-it).

## 5. Upgrade

Read [CHANGELOG.md](CHANGELOG.md) first: it says whether a release needs more than new files. Then,
as root:

*Release archive* – the first block of 2.1 and the fingerprint comparison, with `v` set to the new
version; then unpack it over the old files and check that nothing of the old release is left:

```sh
cd /opt/www/roundcubemail
git -C /root/galbridge-src -c tar.umask=0022 archive --prefix=galbridge/ "v$v" | tar -x -C /opt/www/roundcubemail/plugins
mkdir -m 0700 /root/galbridge-check
git -C /root/galbridge-src archive "v$v" | tar -x -C /root/galbridge-check
diff -r /root/galbridge-check/ plugins/galbridge/ && echo identical   # "Only in plugins/galbridge/: X": a file the new release no longer has - delete it (not config.inc.php)
rm -rf /root/galbridge-check /root/galbridge-src
```

*Composer* – in an interactive root shell (answer `yes` to the root question), **never** from cron
or a script without a terminal: Composer then skips Roundcube's plugin installer, puts the new
release into `vendor/takenek/galbridge` and leaves `plugins/galbridge` as it was:

```sh
composer update --prefer-dist --ignore-platform-req=ext-ldap takenek/galbridge   # the option for iRedMail's SQL editions (2.1)
```

Then compare the installed files with `git archive` of the verified tag of the new version – the
commands of [docs/INSTALLATION.md, 2.1](docs/INSTALLATION.md#21-install-the-plugin-files) (Composer
does not check signatures).

Either way, then run `sudo -u www-data plugins/galbridge/bin/galbridge.sh diag` (without sudo:
`runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag`) from the Roundcube directory
(2.7; as root it is refused); its first line names the release that runs (`[ OK ] GAL Bridge X.Y.Z`).
There are no plugin tables, so there is no schema update; options added by a new release have
built-in defaults.

**Re-apply the grant set** when the CHANGELOG or a `diag` FAIL says so (`grants --grants-only`), and
after an iRedMail step that altered `vmail.forwardings` (below) – as root, with the options the role
was created with (without `--connection-limit` the limit goes back to 25; the second line of the
output names the limit it applies):

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

On MariaDB/MySQL a role that still holds the table grant on `forwardings` of 1.0.0 needs a `REVOKE`
once: the output names it as a comment at its end ([docs/OPERATIONS.md, section 5](docs/OPERATIONS.md#5-upgrade)).
What the review `diff` of 2.2 shows for this form:
[docs/INSTALLATION.md, 2.2](docs/INSTALLATION.md#22-create-the-dedicated-read-only-database-role).

**From 1.0.7 to 1.0.8** – no plugin, configuration or database step: the README is now the short
form, and its full text is in docs/INSTALLATION.md, docs/CONFIGURATION.md and docs/OPERATIONS.md
under the same section numbers (CHANGELOG); two WARN lines of `diag` (`debug`, `entries not offered`) name
docs/CONFIGURATION.md instead of README 3.x. After a release-archive upgrade, the `diff` of this section lists no file of
1.0.7 that 1.0.8 no longer has. The notes of earlier releases:
[docs/OPERATIONS.md, section 5](docs/OPERATIONS.md#5-upgrade).

**iRedMail upgrades** can replace `/etc/nginx/templates/roundcube.tmpl` and
`roundcube-subdomain.tmpl` (the one from 1.8.1 to 1.8.2 did): afterwards run the blocks of 2.6
again – they leave a template that still has the lines alone –, reload nginx and run the
`diag --url=` command of 2.7; plain `diag` does not test the web server. An iRedMail step that alters
the table `vmail.forwardings` needs the view of the grant set dropped before and the grant set
re-applied afterwards (`grants --grants-only`): see
[docs/OPERATIONS.md, section 5](docs/OPERATIONS.md#5-upgrade).

**Roundcube**: install every 1.7.x release as soon as it is published – no new GAL Bridge release is
needed – and run `diag` afterwards. iRedMail's upgrade tutorial unpacks the release with `tar zxf`,
which gives Roundcube's code, `plugins/` included, to uid 1000 (on Debian and Ubuntu the first user
account of the server): unpack it with `tar --no-same-owner -xzf roundcubemail-1.7.N-complete.tar.gz`
instead, or give those files back to root right after the update (the commands are in section 5 of
docs/OPERATIONS.md).

## 6. Rollback / uninstall

To switch the directory off, remove `'galbridge'` from `$config['plugins']`: the books disappear at
once; Roundcube and the personal address books keep working (GAL Bridge stores no tables and no user
preferences). If you added `galbridge_domain` or `galbridge_global` to `autocomplete_addressbooks`
yourself, remove them as well – Roundcube stops with error 700 for an unknown source.

To remove it completely – the database role and the view `galbridge_forwardings`, on PostgreSQL the
four `pg_hba.conf` lines of 2.2 (after `DROP ROLE`, never before), the files,
`config/galbridge.inc.php`, the deny rule of 2.6 and the per-client limits (the template lines
together with their `*_zone` file) – follow
[docs/OPERATIONS.md, section 6](docs/OPERATIONS.md#6-rollback--uninstall).

## 7. Troubleshooting

Start with `diag --user=<login>` in the Roundcube directory: it shows the cause of almost every
"empty directory", and each FAIL or WARN line says what to do.

```sh
cd /opt/www/roundcubemail
sudo -u www-data plugins/galbridge/bin/galbridge.sh diag --user=jan.kowalski@example.com
```

Without sudo: `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag --user=<login>`. On
iRedMail the log lines of GAL Bridge go to syslog under Roundcube's name – `journalctl -t roundcube`,
or `grep -E 'roundcube: (<[^>]+> )?(ERROR|WARN|INFO) ' /var/log/mail.log` – and PHP's own messages
(such as a parse error of `config/galbridge.inc.php`) to nginx: `grep 'PHP message' /var/log/nginx/error.log`.

Every symptom with its cause and fix, and every log reason code:
[docs/OPERATIONS.md, section 7](docs/OPERATIONS.md#7-troubleshooting).

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

What users and administrators notice most:

* accent folding differs between the engines: MariaDB finds "Żółkiewska" for "zolkiewska",
  PostgreSQL does not;
* Roundcube re-sorts search results by `addressbook_sort_col` (2.5);
* only canonical ASCII addresses are offered (`diag` lists the others), on PostgreSQL none written
  with capitals;
* mlmmj subscribers are not in SQL: `membersonly` lists are shown to their moderators and owners only;
* iRedMail's SQL editions have no per-user opt-out column: hide entries with the hidden lists (3.7);
* caps instead of partial results – a refused export downloads nothing (section 4);
* no rate limiter of its own: the per-client limits of 2.6 bound the requests;
* the Roundcube login must equal `mailbox.username`; a login under an alias domain gets no books;
* contact IDs are a reversible encoding of the address and appear in URLs and web server access logs.

Each with its reason, and the rest: [docs/CONFIGURATION.md, section 10](docs/CONFIGURATION.md#10-known-limitations).

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
