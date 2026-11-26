# GAL Bridge – Migration from Roundcube-SQL-Global-Address-Books

GAL Bridge replaces the unmaintained *Roundcube-SQL-Global-Address-Books* plugin
(`plugins/sql_global_addressbooks`, version 2.5.0). It was written from scratch and shares no code,
configuration keys, source IDs or database objects with it. This document covers the switch on an
iRedMail 1.8.x server (MariaDB/MySQL or PostgreSQL edition).

## 0. Why the old plugin must go (verified defects)

The old plugin was analysed in depth against the Roundcube 1.6.9, 1.6.22 and 1.7.7 sources before GAL
Bridge was written (50 findings, 49 confirmed by independent verification). The most important ones,
several of them reproduced on real Roundcube classes and databases:

| Severity | Defect of `sql_global_addressbooks` 2.5.0 |
|---|---|
| critical | **Roundcube 1.7: fatal error on every request** (the backend declares `reset()` / `set_search_set()` without `: void`), including the login page |
| critical | **SQL injection on PostgreSQL** through the quick-search `_headers` parameter: column names reach `rcube_db::ilike()` unvalidated; on PostgreSQL `quote_identifier()` strips only `"`, and `query_parse()` later turns backticks into `"`, so a payload such as `` name` IS NULL) OR (1=1) OR (`name `` breaks out of the identifier. Reproduced on Roundcube 1.6.22 + PostgreSQL 17: any authenticated user lists every tenant and reads other tables of the Roundcube database through `UNION` (e.g. `users.preferences`), with a payload that survives core's comma splitting of `_headers` |
| high | any user lists any domain through `_gid`, and opens disabled or restricted books through `_source` (no authorization in `addressbook_get`) |
| medium | "Compose to selected contacts" puts the **whole book** into To: (the primary-key search never applies its filter) |
| medium | PostgreSQL template: the `vmail` password in plain text in the view definition, `dblink()` executable by PUBLIC, and an epoch-to-BIGINT conversion that fails on PostgreSQL ≥ 14 |
| medium | `iredmail+alias.my.sql` relies on `alias.goto`, removed in iRedMail 0.9.7; `postfixadmin.my.sql` has a wrong `DEFINER` |
| medium | `get_record()` ignores the book scope and returns the previous record on a miss; `count()` is always 1; no paging |

Treat an installation that ran the old plugin on PostgreSQL as potentially exposed: remove the view and
`dblink` (section 4) and rotate the `vmail` password. GAL Bridge never passes a request-supplied name
to SQL: search fields are mapped to constant columns of its own code (`FieldMap`), and every value is
bound.

## 1. What is different

| | `sql_global_addressbooks` | GAL Bridge |
|---|---|---|
| Data source | a VIEW `global_addressbook` in the **Roundcube** database, read through Roundcube's own connection; MySQL: `DEFINER = root` / `SQL SECURITY DEFINER` over `vmail.mailbox`; PostgreSQL: a `dblink` view whose definition contains `user=vmail password=...` | the `vmail` database directly, through `galbridge_db_dsn` and a dedicated read-only role with column-level grants (README 2.2). No `dblink` and no stored credentials; the only object GAL Bridge adds is one view **inside `vmail`**, `galbridge_forwardings`, which narrows what the role can read (alias and list rows of `forwardings`, never the private forwarding targets) – the opposite of the old view, which widened what Roundcube's role could read |
| Roundcube 1.7 | does not load: its backend declares `reset()` and `set_search_set()` without the `: void` that Roundcube 1.7 requires (fatal error) | every Roundcube 1.7.x release |
| Source IDs | `domain`, `global`, and the name of each support book | `galbridge_domain`, `galbridge_global` |
| Scope of the domain book | the domain part of the login string | `mailbox.domain` of the login's vmail row (which must be the login's own domain), or every domain of its organization (`galbridge_organizations`) |
| Who is listed | MySQL views: every row of `vmail.mailbox` (no `WHERE`, disabled accounts included); PostgreSQL view: `active = 1` | deliverable entries only (README 3.7), alias accounts and mlmmj lists filtered by `accesspolicy` (README 3.6) |
| Request parameters | `_source`, `_gid` and `_cid` were trusted (any book, any domain as a group, any ID) | the scope comes from the login and the configuration only (docs/THREAT_MODEL.md) |

## 2. Steps

**Order with the Roundcube 1.7 upgrade.** GAL Bridge needs Roundcube 1.7: on 1.6 the Composer
installer refuses it (`Version check failed!`) and `diag` fails (`not supported`). On 1.7 the old
plugin stops every request, the login page included (section 0). On a server that still runs
Roundcube 1.6 (iRedMail 1.8.0 and older) do steps 1 and 2 with the release archive (README 2.1, not
Composer); then, in one maintenance window, remove `'sql_global_addressbooks'` from
`$config['plugins']`, upgrade Roundcube to the newest 1.7.x (README section 5, "Roundcube on
iRedMail") and do step 3. If Roundcube already runs 1.7 with the old plugin still listed, the webmail
is down: take it out of the list at once, then continue with step 2.

1. **Inventory.** Keep a copy of `plugins/sql_global_addressbooks/config.inc.php` and note which
   books were in use and for which domains.
2. **Install GAL Bridge without enabling it yet**: README sections 2.1 (files; with Composer answer
   `n` when the installer asks whether to activate the plugin), 2.2 (database role), 2.3
   (configuration file and permissions), the ownership of `config/config.inc.php` from 2.4 (iRedMail
   installs it as the PHP user, mode 0600), 2.5 (`addressbook_sort_col`) and 2.6 (web server rule).
   Translate the old options with the table in section 3.
3. **Switch** in one edit of `config/config.inc.php`: replace `'sql_global_addressbooks'` with
   `'galbridge'` in `$config['plugins']`. If you ever added the old source IDs (`domain`, `global`
   or a support-book name) to `autocomplete_addressbooks` yourself, remove them: Roundcube stops with
   error 700 for a source that no longer exists. (The old plugin added them at runtime only, so
   normally there is nothing to remove.) GAL Bridge adds its own IDs at runtime.
4. **Verify**: `sudo -u www-data plugins/galbridge/bin/galbridge.sh diag` (without sudo:
   `runuser -u www-data -- plugins/galbridge/bin/galbridge.sh diag`), `diag --user=<login>` for one
   user of each kind of book, and `diag --url=https://mail.example.com/mail/` for the web server rule
   (with iRedMail's self-signed certificate add `--cafile=/etc/ssl/certs/iRedMail.crt` and use the
   host name of the certificate, README 2.6). `diag` warns while `sql_global_addressbooks` is still enabled
   and while the old `_sql_*` book options are still set in the main configuration.
5. **Remove the old database objects** (section 4) and, on PostgreSQL, rotate the `vmail` password.
6. **Remove the old plugin**: `rm -r plugins/sql_global_addressbooks` (its `config.inc.php` goes with
   it).

There is no way back to the old plugin on Roundcube 1.7 (section 1, row "Roundcube 1.7": the old
plugin does not load on 1.7), so do not keep the old view "just in case".

## 3. Configuration key map

| Old option | Old default | GAL Bridge | Notes |
|---|---|---|---|
| `_sql_domainbook_name` | `'Domain Address Book'` in the shipped `.dist` (`NULL` = off) | `galbridge_domain_book` (`true`/`false`) and `galbridge_domain_book_name` | empty name = the organization label, otherwise "Domain directory" (localised) |
| `_sql_db_read_allowed` | `['*']` | `galbridge_domain_viewer_domains` | same default |
| `_sql_db_read_hidden` | `[]` | `galbridge_domain_viewer_domains_exclude` | a deny-list: a malformed entry disables the book (fail closed) |
| `_sql_globalbook_name` | unset (off) | `galbridge_global_book = true` and `galbridge_global_book_name` | |
| `_sql_globalbook_gp` | `true` (groups by domain) | `galbridge_global_groups = 'domain'` **and** `galbridge_global_searchonly = false` | GAL Bridge defaults to no groups; search-only books have no groups |
| `_sql_gb_read_allowed` | `['*']` | `galbridge_global_viewer_domains` | **the default is now `[]` (nobody)**: write `['*']` explicitly to keep the old behaviour |
| `_sql_gb_read_hidden` | `[]` | `galbridge_global_viewer_domains_exclude` | deny-list |
| `_sql_gb_data_allowed` | `['*']` | `galbridge_global_data_domains` | the default is now `[]`; `['*']` (every hosted domain, including tenants added later) also needs `galbridge_global_allow_wildcard = true` |
| `_sql_gb_data_hidden` | `[]` | `galbridge_global_data_domains_exclude` | deny-list |
| (the old global book also listed the viewer's own domain) | | `galbridge_global_include_home = true` | GAL Bridge subtracts the viewer's domain-book scope by default, so nobody appears twice |
| `_sql_supportbook_list` (and `_sql_supportbook`, which the old code also read) | `[]` | the global book, see section 6 | GAL Bridge 1.0 has one cross-domain book |
| the VIEW `global_addressbook` | | `galbridge_db_dsn` | README 2.2, 2.3 |

New options without an old equivalent are documented in `config.inc.php.dist` and README section 3
(fields, organizations, lists and `accesspolicy`, hidden entries, limits, IMAP host binding).

## 4. Removing the old database objects

**MariaDB / MySQL edition.** The view lives in the Roundcube database (`roundcubemail` on iRedMail)
and gave Roundcube's `roundcube` role read access to `vmail.mailbox` with root's rights (its ID
column was even computed from the length of the password hash). As root:

```sql
SHOW FULL TABLES IN roundcubemail WHERE Table_type = 'VIEW';
DROP VIEW IF EXISTS roundcubemail.global_addressbook;
```

No password was stored in it. If you followed other instructions and put the `vmail` credentials
into any Roundcube configuration, rotate that password as described below for PostgreSQL.

**PostgreSQL edition.** The view definition contains the `vmail` role's password in plain text
(`dblink('host=... user=vmail password=...')`). View definitions are readable through `pg_views` by
every role that can connect to the database, and the `dblink` connection let the `roundcube` role
query `vmail` with `vmail`'s rights, password hashes included. As `postgres`:

```sh
runuser -u postgres -- psql -d roundcubemail -c "SELECT viewname, viewowner FROM pg_views WHERE viewname = 'global_addressbook';"
runuser -u postgres -- psql -d roundcubemail -c "DROP VIEW IF EXISTS global_addressbook;"
# remove the extension unless something else in this database uses it (without CASCADE the
# command refuses while other objects depend on it)
runuser -u postgres -- psql -d roundcubemail -c "DROP EXTENSION IF EXISTS dblink;"
```

Then **treat the `vmail` password as disclosed and rotate it**:

1. Save the old password into a private file, e.g. `install -m 0600 /dev/null /root/old-vmail-pw`,
   then write the password into it (one line).
2. Find every file that contains it:
   `grep -rlF --exclude=old-vmail-pw -f /root/old-vmail-pw /etc /opt /root /var/lib/postgresql/.pgpass 2>/dev/null`.
   On iRedMail this typically lists the Postfix lookup tables (`/etc/postfix/pgsql/*.cf`), the
   Dovecot SQL configuration, iRedAPD's `settings.py`, `/var/lib/postgresql/.pgpass` (written by the
   installer for the `vmail` role), iRedMail's record `/root/.iredmail/kv/sql_user_vmail`, and the
   installer's `config` and `iRedMail.tips` in the directory where iRedMail was unpacked. The same
   text was mailed to the postmaster as the details of the installation: delete that message or treat
   it as outdated.
3. Set a new random password (`ALTER ROLE vmail WITH PASSWORD '<new>';` as `postgres`), replace it
   in every file found, restart or reload the affected services (Postfix, Dovecot, iRedAPD, and any
   other service from the list), and check that mail delivery and logins work.
4. `shred -u /root/old-vmail-pw`.

## 5. Behaviour changes users will notice

* **New book names and IDs**: "Domain directory" / "Global directory" (Polish: "Katalog domeny" /
  "Katalog globalny"), or the names you configure. Contacts copied earlier into personal address
  books are independent copies and stay as they are.
* **Only deliverable entries**: disabled mailboxes (`active = 0`), mailboxes with
  `enabledeliver = 0`, expired accounts and the accounts of inactive, expired or backup-MX domains
  disappear (the old MySQL views listed every mailbox).
* **Alias accounts and mlmmj lists appear** as entries, but only for users who may post to them
  (README 3.6). Per-user alias addresses are no longer shown unless `galbridge_user_aliases = true`.
* **More fields**: first name, last name and department are shown when they are filled in vmail
  (open-source iRedAdmin fills only the display name); phone, mobile, organization and birthday are
  opt-in (phone, mobile and birthday also need the commented-out grant of README 2.2).
* **Department groups** in the domain book (or domain groups for organizations with several
  domains); group expansion is complete or refused above the cap, never cut.
* **Search starts at two characters** (`galbridge_min_search_length`); compose autocomplete follows.
* **The global book** is search-only, uses prefix search, shows at most 100 matches, has no groups
  and cannot be exported as a whole by default; entries outside the user's own domain are shown as
  `(domain) Name` (also in the recipient fields), without a separate first and last name.
* **Organizations**: users of any domain of an organization see the colleagues of all its domains in
  their domain book.
* **Exports** of the whole domain book are limited to `galbridge_max_export` (2000) entries. A
  refused export downloads nothing; as a file download it cannot show a message (README 4).
* **Directory outages** show "The directory is temporarily unavailable." instead of an empty book
  (README 3.9).

## 6. Recipe: a "support book" with the global book

The old support book showed a fixed set of domains to every user **except** the users of those
domains. With GAL Bridge:

```php
$config['galbridge_global_book'] = true;
$config['galbridge_global_book_name'] = 'Support Contacts';
$config['galbridge_global_data_domains'] = ['support.example', 'helpdesk.example'];
$config['galbridge_global_viewer_domains'] = ['*'];
$config['galbridge_global_viewer_domains_exclude'] = ['support.example', 'helpdesk.example'];
$config['galbridge_global_searchonly'] = false;   // a small book can be browsed without a search
```

The support staff see their own colleagues in the domain book (or, with
`galbridge_organizations = ['Support' => ['support.example', 'helpdesk.example']]`, across both
domains). An installation that used both a global book and a support book has to choose one of them
for GAL Bridge 1.0; the other domains can only be reached through the domain book's organizations.
