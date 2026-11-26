# GAL Bridge – Configuration

The full text of sections 3, 4 and 10 of the README: every option with its default and its effect,
what users see, and the known limitations. Every option is also documented in
[config.inc.php.dist](../config.inc.php.dist); [README.md](../README.md) section 3 has the short
form.

Section numbers are shared by [README.md](../README.md) and the documents of `docs/`: sections 1 and
2 are in [INSTALLATION.md](INSTALLATION.md), 3, 4 and 10 in [CONFIGURATION.md](CONFIGURATION.md), 5
– 7 in [OPERATIONS.md](OPERATIONS.md), 8, 9 and 11 – 14 in the README. "Section 3.9" in this text is
section 3.9 of CONFIGURATION.md, "section 2.6" section 2.6 of INSTALLATION.md.

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
matching follow one rule**: an alias counts only when its owner's domain belongs to the book and is
active (not a backup MX and, with `galbridge_hide_expired`, not expired) and when it is on neither
hidden list (3.7). The owner's domain is the `domain` column of the forwardings row, which iRedAdmin
fills with the owner's domain also for an address in an alias domain (`jan@example.net` of
`example.com`): such an address is shown wherever the owner's domain is in scope – the domain lists
and `*_exclude` options never name an alias domain; hide such an address with
`galbridge_hidden_addresses` or `galbridge_hidden_localparts` (3.7). A hidden alias is therefore not shown and does not find its owner either – not as a
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
(facility `LOG_MAIL`), the lines go to syslog under Roundcube's ident without the log name – on
Debian and Ubuntu into `/var/log/mail.log` (RHEL: `/var/log/maillog`), during a web request with the
session prefix, e.g. `roundcube: <1a2b3c4d> WARN limits: export_disabled: request refused user=1
book=galbridge_global`: `journalctl -t roundcube`, or `grep -E 'roundcube: (<[^>]+> )?(ERROR|WARN|INFO) '
/var/log/mail.log`. PHP's own messages of a webmail request – a warning, a fatal error, a parse
error of `config/galbridge.inc.php` (2.3) – go to the web server on iRedMail: its PHP-FPM sets no
`error_log`, so nginx writes them to `/var/log/nginx/error.log` as `FastCGI sent in stderr: "PHP
message: ..."` (`grep 'PHP message' /var/log/nginx/error.log`; `journalctl -t php-fpm` has only
the messages of the PHP-FPM master process). Line
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
limits of the reverse proxy bound it ([SECURITY.md](../SECURITY.md), checklist).

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
([SECURITY.md](../SECURITY.md#logs)).

## 4. Using it

GAL Bridge uses only the official plugin API: the `addressbooks_list` and `addressbook_get` hooks,
the `addressbook_export` hook (vCard export only), the `contact_exists` hook (a directory entry is
never a "known contact"), the `preferences_update` hook (in Settings only: no `galbridge_*` value is
ever stored as a user preference) and an `rcube_addressbook` backend. It changes nothing in
Roundcube core and has no JavaScript, templates, skins or database tables of its own: Roundcube's
address-book UI renders everything. The books therefore work on every Roundcube 1.7 address-book
path: source list, contact list with paging, show, print, QR code, vCard export (all, selected,
search), compose to selected contacts and to a group, copy to a personal address book, quick,
advanced and saved searches, mail autocomplete (contacts and groups; in compose and in the Redirect
dialog), the compose contact list and its search, the vCard attachment of the bundled
`vcard_attachments` plugin, and core's known-contact check (`rcmail::contact_exists()`, from which
the directory books are removed). What a user can see comes only from the authenticated login and
the administrator configuration, never from request parameters (`_source`, `_gid`, `_cid`,
`_search`, `_q`, `_page`).

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
  with `galbridge_*_export = false` or above `galbridge_max_export`; the whole of a **search-only**
  book whatever `galbridge_*_export` says (`galbridge_*_searchonly = true` – the global book's
  default – or `'auto'` above `galbridge_searchonly_threshold`; its search results, selections and
  groups stay exportable); a group above the expansion
  cap; more than `galbridge_max_ids` selected contacts; a **search result with more matches than
  the result cap** (`galbridge_max_results` / `galbridge_global_max_results` – on a page such a
  list is cut with a notice, a file could not say so); a set of which an entry could not be read –
  also a selection of contacts with one that is gone, not visible to the user or malformed; and any
  export while the directory fails (database outage or query error). The refusal is logged
  (`export_disabled`, `export_too_large`, `group_too_large`, `too_many_ids`, `set_incomplete`,
  `db_down` / `db_error`). No limit that only bounds counting (`galbridge_max_count`) decides what a
  file holds.

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
