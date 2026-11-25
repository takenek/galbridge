# GAL Bridge – Threat model

This document lists what GAL Bridge protects, against whom, and how. The threats GB-01 to GB-28 come
from the security review of the design (before implementation) and were re-checked against the
1.0.0 code; GB-29 to GB-32 and the changes to the other entries come from the security audit of
1.0.0, whose findings were remediated in 1.0.1 (identifiers U-xx / I-xx in
[CHANGELOG.md](../CHANGELOG.md); 1.0.0 and 1.0.1 were development versions that were never tagged or
published), and from the two verification passes after it (CHANGELOG "Unreleased", F-xx). Each entry names the implemented controls (classes under
`lib/GalBridge/`) and the tests that exercise them (`tests/Integration/*Test`, `tests/Unit/*Test`,
`tests/e2e/`, `tests/ui/`). Where the implementation differs from the original proposal of the
review, the entry describes the implementation. Section references ("ARCHITECTURE 11") point to
[ARCHITECTURE.md](ARCHITECTURE.md).

## Assets

| Asset | Where |
|---|---|
| Directory data of every tenant: names, addresses, departments, phone numbers, birthdays, alias accounts, list addresses | iRedMail `vmail` database (`mailbox`, `domain`, `alias`, `maillists`, `forwardings`, `moderators`, `maillist_owners`) |
| Secrets in the same database: password hashes, recovery e-mail addresses, `allow_nets`, per-user settings, private forwarding targets | `vmail.mailbox`, `vmail.forwardings`. Not readable by the plugin's role: its grants are column-level, and of `forwardings` it gets a view with the alias and list rows only (`galbridge_forwardings`, materialised and fenced: the server builds its rows before any condition of a query applies), never the table with the forwarding targets. A role that can read a secret column of any table or that table – or that can do more than read the directory – is refused |
| Organisational structure: who is in which department, which tenants a provider hosts, who may post to which list | derived from the same tables |
| Credentials of the plugin's database role | `config/galbridge.inc.php` (recommended) or `plugins/galbridge/config.inc.php` |
| Integrity of recipient sets (group expansion, compose to selected contacts) | Roundcube compose |
| Availability of webmail (the directory is optional, mail is not) | PHP-FPM workers, the database server |
| Privacy of what users search for | requests, logs (plugin, Roundcube, web server, database server) |

## Trust boundaries and assumptions

* The operating system account running PHP, the Roundcube installation, its configuration and the
  administrator are trusted.
* Every logged-in user is potentially hostile towards other users and **other tenants** of the same
  iRedMail server. All request parameters are attacker-controlled: `_source`, `_gid`, `_cid` (string
  and array forms), `_search`, `_q`, `_headers`, `_search_<field>`, `_page`, `_action`.
* Directory text maintained by tenant administrators or users (display names, departments, list
  names) is attacker-controlled from the point of view of every other viewer.
* User preferences (`users.preferences`) can be written by the user for some keys and by anyone with
  database access to Roundcube's database; they are not trusted for administrator options.
* **Every** configured directory IMAP server (host and port) authenticates users against the same
  `vmail` database (the Roundcube login equals `mailbox.username`). The plugin cannot verify this:
  it is an obligation of the administrator, repeated by `diag` when more than one server is
  allowed. An IMAP server chosen by the client, or selected through the `Host` header
  (`include_host_config`), is not trusted – and neither is any other option a per-host
  configuration file selected by the `Host` header carries: the directory policy (`galbridge_*`)
  must not depend on it.
* The network between the webmail and a directory IMAP server on another host is untrusted: the
  IMAP login proves the identity only over TLS that authenticates the server. No other plugin is
  assumed to rewrite the IMAP host, port, TLS mode or socket options at connection time
  (`storage_init` / `storage_connect`).
* The `vmail` database is trusted for integrity; GAL Bridge only reads it. A database server on
  another host is reached over an untrusted network.
* Roundcube's session storage is trusted (it is server-side; whoever can write it can take over any
  session).

## Threats and mitigations

### GB-01 Loss of availability through fatal errors

*Threat.* The backend or the hooks fail on a Roundcube 1.7.x release / PHP 8.x and take the mail and
address-book tasks down (the old plugin declared `reset()` / `set_search_set()` without the `: void`
that 1.7 requires, and broke on unvalidated configuration).

*Controls.* `AddressBook` and `DeniedBook` repeat every signature of the 1.7 core, including `: void`;
`declare(strict_types=1)`, final classes and typed properties only. Every option is read through
typed, clamped `Config` getters with defaults. Both hook handlers catch `Throwable`, log the
exception class (`Log::exception`, message only with `galbridge_debug`) and degrade to an empty or
inert book; `addressbook_get` always sets an instance for our source IDs, so core's fatal error 700
cannot be triggered. The database driver class of the judged driver is created directly (never
through `rcube_db::factory()`, which ends the PHP process on a driver it does not know), and an error
the driver raises while it connects (e.g. a PDO MySQL constant missing from a `pdo_mysql` without
`mysqlnd` on Roundcube 1.7.6+) is an outage like any other: `db_down cause=driver_error`, memoised
for 30 s, its reason on `diag`'s `connect` line. composer requires `php >=8.1 <8.6`; PHPStan level 8 analyses the code against
a Roundcube 1.7.x tree. The whole 1.7 branch is supported, so that an installation never has to
hold back a security release of the core for the sake of the plugin.

*Tests.* CI runs the whole suite on PHP 8.1 – 8.5 against the newest Roundcube 1.7.x on the day of
the run, and on PHP 8.1 and 8.4 against the oldest release (1.7.0), with `failOnWarning`,
`failOnNotice` and `failOnDeprecation`; `CliToolTest::testDiagAcceptsEveryReleaseOfTheSupportedBranch`; `ContractTest` (the `rcube_addressbook` contract,
`testDeniedBookContract`), `CorePathsTest::testEveryOwnSourceIdResolvesToAnInstance`,
`PluginHooksTest::testAddressbookGetAlwaysAnswersOurIds`, `OldPluginRegressionTest`
(`testDefect01BackendDeclarationsAreCompatibleWithRoundcube17`,
`testDefect10NoDeprecationsAndNoDynamicProperties`), `Remediation2DsnTest`
(`testDriverErrorsWhileConnectingFailClosed`, `testProductionCodeNeverUsesTheCoreFactory`,
`testTheJudgedDriverClassIsUsed`).

### GB-02 Horizontal access by naming a book in `_source`

*Threat.* A user instantiates a directory book they are not entitled to by putting its ID into
`_source`, a `_cid` of the form `<id>-<source>`, or a stored preference (`default_addressbook`,
`collected_recipients`, `collected_senders`).

*Controls.* `addressbook_get` is the only factory (`Factory::isOwnId`; foreign IDs are left
untouched). For our IDs it returns an `AddressBook` only when the resolved user is entitled
(`Entitlements::books`, a pure function of the Viewer and the administrator configuration) and the
scope is non-empty; otherwise a `DeniedBook` (not ready, no groups, empty reads, `get_record()` null,
never connects). `addressbooks_list` advertises entitled books only. The per-book autocomplete flag
is enforced in `search()` and `list_groups()` for `_action=autocomplete`, because core accepts any
`_source` there and asks every source for groups as well.

*Tests.* `IsolationTest`, `CorePathsTest::testEveryOwnSourceIdResolvesToAnInstance`,
`ContractTest::testDeniedBookContract`, `EntitlementsTest::testEmptyScopeIsNotListedAndGivesADeniedBook`,
`PluginHooksTest` (`testAddressbookGetAlwaysAnswersOurIds`, `testCoreGetsADeniedBookForAStaleSource`,
`testForeignIdsAreLeftUntouched`), `OldPluginRegressionTest::testDefect02AddressbookGetHonoursEntitlementForEverySource`.

### GB-03 Cross-tenant enumeration through `_gid`

*Threat.* `set_group()` receives `_gid` from the request; the old plugin ran `WHERE domain = <gid>`
on any value, so `_gid=other-tenant.example` listed another tenant.

*Controls.* `set_group()` clears the group for `0 '0' '' null false`; any other value is decoded
strictly (`IdCodec::decodeGroup`: charset `[A-Za-z0-9_]`, canonical, key = `LOWER(TRIM())`), must
carry this book's tag (`d`/`g`) and grouping kind, and otherwise installs a match-nothing predicate
while keeping `group_id` (so core's `get_group()` returns null); logged once as `invalid_gid` with a
12-character hash of the value. The group predicate is ANDed with the root predicate (scope first);
a domain group key must be inside the book's scope (`QueryBuilder::group`).

*Tests.* `IsolationTest::testForgedGroupsMatchNothing`,
`IsolationTest::testOrganizationDomainGroupsCannotReachOtherDomains`,
`ContractTest::testSetGroupResetsAndInvalidGroups`, `IdCodecTest`,
`OldPluginRegressionTest::testDefect03GroupIdCannotEnumerateAnotherTenant`.

### GB-04 IDOR and stale data through contact IDs (`_cid`, `get_record()`)

*Threat.* Show, print, QR code, photo and copy call `get_record()` with request IDs, and so does the
vCard attachment of the bundled `vcard_attachments` plugin (`vcard://<source>-<cid>-<email>` in
compose, `get_record($cid, true)`); the old plugin looked IDs up globally, returned the previous
result on a miss, and derived IDs from the creation time and the password length.

*Controls.* Contact IDs are `<kind>_<enc>` (`u` person, `a` alias account, `l` list), charset
`[A-Za-z0-9_]`, a deterministic encoding of the normalised address that carries no secret
(`IdCodec`). Decoding is strict and canonical, never throws, accepts only strings and a plausible
lower-case address. Every lookup runs through the book's root predicate; a miss and an out-of-scope
ID give the same answer (null, previous result cleared). The array form of `_cid` is handled by the
same decoder.

*Tests.* `IsolationTest::testForeignAndInvisibleIdsResolveToNothing`, `ContractTest::testGetRecord`,
`CorePathsTest::testShowPrintQrcodeAndPhotoByContactId` (with the `vcard://` form of
`vcard_attachments`), `IdCodecTest`
(`testDecodeNeverThrowsOnRandomInput`, `testDecodeRejectsNonCanonicalEncodings`),
`OldPluginRegressionTest::testDefect04GetRecordIsScopedAndNeverReturnsThePreviousRecord`.

*Residual risk.* IDs are reversible: contact IDs encode the address, group IDs (`_gid`) the
department name or domain. They travel in GET URLs and so reach web server access logs, the browser
history of the user's device and the logs of proxies in front of the webmail (SECURITY.md register
GB-04; opaque IDs would need a mapping lookup for up to `galbridge_max_ids` IDs of one URL and would
not keep searches, which Roundcube sends as GET requests, out of those logs).

### GB-05 Compose to selected contacts puts the whole book into To:

*Threat.* `contacts/mailto.php` runs a primary-key search and turns every returned row into a
recipient; the old plugin ignored the ID criterion and returned the unfiltered book.

*Controls.* A primary-key search builds an ID-list `SearchSpec`; no valid ID gives a match-nothing
spec, never "no filter". More than `galbridge_max_ids` (200) IDs are refused with the visible
"At most N directory contacts ..." message and logged (`too_many_ids`); the set is never cut to a
prefix.

*Tests.* `ContractTest::testIdSearch`, `CorePathsTest::testMailtoSelectedContactsAndGroup`,
`OldPluginRegressionTest::testDefect05ComposeToSelectedSendsOnlyTheSelectedContacts`.

### GB-06 Unbounded queries, mass harvesting, wrong totals and silently incomplete sets

*Threat.* Core asks for page sizes of 999, 9999 and 99999 and passes `_page` unbounded; the old
plugin had no LIMIT and `count()` always returned 1. A recipient set or an export that is cut
without a word is taken for the whole.

*Controls.* `set_page()` is clamped to 1..1000001 and `set_pagesize()` to 1..1000000 without an
out-of-range float-to-int cast; the page size is capped at max(core `max_pagesize`,
`addressbook_pagesize` or `pagesize`), clamped to 10–1000 (`Limits::corePageCap`). A **full set** is
recognised by core's own sizes – page 1 with 9999 (search lists, group expansion) or 99999 (exports),
a search above `corePageCap`, a group in the recipient actions `mailto` / `group-expand` – and any
other size above the cap is a page of `corePageCap` entries with a notice (`pagetoolarge`), never an
export or a group expansion. A full set is handled explicitly (ARCHITECTURE 11): it is read by
**one statement up to the limit of the operation plus one row** (`Repository::fullSet`) and is
returned completely or refused – a group above the expansion cap, a whole book above
`galbridge_max_export` (and only when the book's export policy allows it), an ID search above
`galbridge_max_ids`. A **selection** by IDs in compose and export (`mailto`, `export`) is complete or
refused as well: an ID that cannot be decoded, that finds no visible entry or whose row the
re-check drops refuses it (`set_incomplete missing=N dropped=N`). The bound of counting
(`galbridge_max_count`) never takes part in that decision, whatever the relation between the
limits. A set of which a fetched row could not be delivered is refused as well (`set_incomplete`) – since
the SQL offers only rows that pass the PHP re-check (canonical rows, GB-27), a drop means a row that
changed during the request or a defect, never a data-quality problem of one entry that would block
the full sets of its whole scope. A group ID is honoured only for a group of the book's own
aggregate: a book that shows no groups selects nothing by one. A search *list* is capped at
`galbridge_max_results` / `galbridge_global_max_results` with a notice, and so are the pages of a
search result (offsets beyond the cap are empty, the total stops at it); in an export the same
result is complete or refused.
A refused export – also one that fails because of a database error – downloads nothing (`204 No
Content` from the `addressbook_export` hook) instead of an empty or shortened file.
`count()` is a `COUNT` over the same root predicate inside a `LIMIT` subquery bounded by
`galbridge_max_count`; a list page counts up to the bound plus one and tells the user when the
total is capped (`listcapped`), and list offsets beyond it return an empty page with that notice.
Core's copy action reads contacts one by one: at most `galbridge_max_ids` per request
(`LookupBudget`). The details of a full set are read in statements of at most 500 addresses. The
per-user aliases of a page are bounded per owner (`ROW_NUMBER()`), so every entry of a page gets its
aliases. All membership predicates are
semi-joins (`EXISTS` / `IN`), so rows are unique by primary key and totals and paging are exact; the
order is stable (sort key, address, kind).

*Tests.* `ContractTest` (`testListRecordsPagingCountAndFirst`, `testPageAndPageSizeAreClamped`,
`testCountHonoursScopeFilterGroupAndPage`), `CorePathsTest`
(`testFullSetSearchListsAreCappedWithANotice`, `testGroupExpandReturnsAllMembersOrRefuses`,
`testMailtoGroupWithCoreMaxGroupMembersIsCompleteOrRefused`, `testExportPaths`),
`AuditRegressionTest` (`testExportsAndRecipientSetsDoNotDependOnTheCountBound`,
`testAFullSetThatLacksARowIsRefused`), `PluginHooksTest` (`testRefusedExportAnswersNoContent`,
`testAFailingDirectoryRefusesTheExport`), E2E case 33,
`OldPluginRegressionTest::testDefect06CountIsTheRealTotalAndListsArePaged`,
`Remediation2CompletenessTest` (selections complete or refused, browse pages above the cap, list
and result caps with notices, aliases of every entry of a page, the page clamp),
`Remediation2CanonicalRowsTest` (`testARowOnlyThePhpCheckRejectedNoLongerBlocksFullSets`,
`testAGenuineDisagreementIsStillRefused`), `Remediation2ResourceLimitsTest`
(`testCopyReadsAtMostMaxIdsContactsPerRequest`, `testFullSetDetailStatementsAreBatched`).

*Residual risk.* An export is a file download: when it is refused, no message can be shown with
it (README 4 and 10). A book larger than `galbridge_max_count` cannot be browsed to its end (the
user is told to search).

### GB-07 SQL injection and engine-specific SQL errors

*Threat.* Field names (`_headers`, `_search_<field>`), values (`_q`, `_search`), sort columns and
group/ID parameters reach SQL; the old plugin quoted request-chosen identifiers and stored raw SQL.

*Controls.* `QueryBuilder` is the only producer of directory SQL. Predicates are an immutable tree
(`Sql`) rendered fully parenthesised, `IN ()` = FALSE and `NOT IN ()` = TRUE, values always bound,
column expressions are code constants.
Request field names are mapped through `FieldMap` and never become identifiers. LIKE patterns escape
`! % _` with `ESCAPE '!'` (identical on MariaDB, PostgreSQL and SQLite); PostgreSQL uses `ILIKE`.
(Column expressions passed to `Sql::eq/in/notIn/like` are rejected when they contain quotes, `?`,
`;`, `--` or `/*`.)
`mode & 3`; at most 5 search words; `galbridge_max_search_length`; sort column whitelisted (`name`,
`firstname`, `surname`, anything else = `name`). Identifiers in backticks are rewritten for
PostgreSQL by `rcube_db`; no `?` or backtick ever appears inside a literal.
"Bound" in Roundcube means inlined: `rcube_db` quotes every value with `PDO::quote()` into the
statement text, and PDO scans that text again for placeholders. Its scanner treats a backslash in a
literal as an escape where the server does not (PostgreSQL with PHP 8.1–8.3; MySQL without
backslash escapes), so a value with a backslash could move the literal boundaries PDO sees, and
libpq ends a quoted value at a NUL byte (a pattern shorter than the length rules checked). No such
value is ever bound: `QueryBuilder::words()` does not search input with a control character (C0,
DEL, C1) or a backslash – the directory never displays them –, `IdCodec::usableGroupKey()` keeps
such department keys out of the groups and out of decoded group IDs (`group_key_unusable`), and
`Connection::select()` refuses any value with a NUL byte or a backslash before it sends the
statement (`unsafe_parameter`, the backstop of the rule every producer follows; addresses, domains
and local parts are validated without them). A required birthday is compared as a date
(`>= '0002-01-01'`, year 0001 meaning "none"), never with text.

*Tests.* `SqlTest` (`testCompositionCannotChangePrecedence`, `testValuesAreAlwaysBoundNeverInlined`,
`testEscapedLikePatternMatchesOnlyTheLiteral`, the unsafe-expression cases),
`ContractTest::testLikeWildcardsInInputAreLiterals`, `ContractTest::testQuickSearchFieldsWordsAndModes`,
`Remediation2DbLayerTest` (unit: `testGroupKeysWithControlCharactersOrABackslashAreRefused`;
integration: `testSearchWordsWithControlCharactersOrABackslashMatchNothing`,
`testABoundValueWithANulByteOrABackslashIsNeverSent`, `testDepartmentGroupsWithUnusableKeysAreNotOffered`,
`testARequiredBirthdayMeansARealDate`), all integration tests on the tested MariaDB, MySQL and
PostgreSQL versions (README section 1) and SQLite.

### GB-08 SQL injection through search state replayed from the session

*Threat.* Core stores `get_search_set()` in `$_SESSION['contact_search']` and hands it back to
`set_search_set()` on later list, compose and export requests (core's own SQL book keeps SQL text
there).

*Controls.* The search set is a typed, versioned `SearchSpec` (type, book, configuration
fingerprint, fields, values, mode, required fields, IDs) that never contains SQL or scope.
`SearchSpec::fromArray` accepts only what `toArray()` of the same book and configuration could have
produced, re-decodes IDs, and anything else becomes a match-nothing spec. Every criterion is
re-validated against the current `FieldMap` and search rules when it is executed, and the scope is
re-applied on every execution, so a configuration change also invalidates stored specs.

*Tests.* `SearchSpecTest` (`testTamperedSpecIsRejected`, `testForeignBookIsRejected`,
`testForeignFingerprintIsRejected`), `ContractTest::testSearchSetRoundTripAndTampering`,
`IsolationTest::testReplayedSearchSetIsEvaluatedInTheCurrentScope`,
`CorePathsTest::testQuickSearchSearchUpdateAndSearchExport`.

### GB-09 Search oracle on columns that are not displayed

*Threat.* With attacker-chosen field names, prefix searches on a hidden column (recovery e-mail,
mobile, birthday, password hash) would reveal its content character by character.

*Controls.* One allowlist feeds the SELECT list, `coltypes`, the searchable fields and the sort keys
(`FieldMap`): effective fields = `galbridge_fields` ∩ columns the role can read. `*` expands to the
searchable effective fields only; quick search drops unknown fields; an advanced search with any
unknown or `nosearch` field matches nothing; an unsupported `$required` field returns an empty set
without a query. Per-user aliases are matched only with `galbridge_user_aliases`. In a book that
marks entries of other domains, a field such an entry does not carry (first and last name, and
department and phones under a name template that shows them before `{name}`) is no search, required
or sort criterion for it (`RecordMapper::foreignWithheld()`, QueryBuilder `foreignGuard()` /
`personSortKey()`). Secret columns are not granted, and a role that can see them is refused
(`role_too_broad`), so even a coding mistake fails with a permission error instead of leaking.

*Tests.* `ContractTest::testAdvancedSearchIsAndAndRejectsUnsupportedFields`,
`ContractTest::testRequiredFields`, `ConfigTest::testMinimalFieldAllowlistLimitsRecordsColtypesAndSearch`,
`DsnPolicyTest::testRoleThatCanReadSecretColumnsDisablesEveryBook`, `LeakageTest`
(`testSearchingForACanaryFindsNothing`, `testUserAliasesOffAreNeitherShownNorSearchable`),
`Remediation2MultiDomainBookTest::testWithheldFieldsOfForeignEntriesAreNeitherMatchedNorSorted`.

### GB-10 Directory enumeration with wildcard, empty or one-character searches

*Threat.* Roundcube's server accepts any non-empty autocomplete term; `%` and `_` act as wildcards
unless escaped; a book without `searchonly` can be listed completely.

*Controls.* `galbridge_min_search_length` (2) is enforced on the server: no query unless at least
one word reaches it (global book: every word); shorter words narrow only as word prefixes; at most 5
words; wildcards in the input are literals. The client-side `autocomplete_min_length` is raised to
the same value in compose and in the Redirect dialog (`mail/bounce`), so Roundcube's JavaScript does
not stop asking after an empty answer. Search-only books (global default; `'auto'` above
`galbridge_searchonly_threshold`) list nothing without a search and have no groups. The global book
forces prefix search, matches e-mail addresses from the local part, never on a domain fragment, and
the `organization` field only by its complete label, so a two-letter word cannot list a whole
organization.

*Tests.* `ContractTest::testEmptyTooShortAndTooLongInputNeverQueries`,
`ContractTest::testSearchOnlyBook`, `CorePathsTest::testAutocompleteContactsAndGroups`,
`EntitlementsTest::testGlobalBookIsSearchOnlyByDefault`, `PluginHooksTest::testAutocompleteWiringDetails`.

### GB-11 Wrong tenant derived from an untrusted login

*Threat.* With a user-chosen or templated IMAP host (`%d` from the client's `Host` header), an
attacker authenticates against their own IMAP server as `ceo@tenant-a.example`; the old plugin took
the scope from the domain part of the login string. Two IMAP services on one host name (other
ports) with different user databases, or a per-host configuration selected by the `Host` header
(`include_host_config`), lead to the same confusion. So does an attacker on the network between the
webmail and a remote IMAP server, who answers the login for any mailbox (STARTTLS on Roundcube
before 1.7.7 goes on in plain text when the server does not offer it), and a per-host file that
carries a wider directory policy for one host name, which the client selects with the `Host`
header.

*Controls.* IMAP-server binding (`ViewerResolver::allowedImapHosts`): the allowed servers are
`galbridge_imap_hosts` – entries `host`, `host:port` or a local socket `unix:///path` (a path of
printable ASCII without blanks and `%`; a session or stored host whose socket path has other bytes
is not canonical and is refused) – or, only
when that list is empty, host and port of `imap_host` as Roundcube applies it
(`Config::coreValue()`: Roundcube's own configuration object; a user preference named `imap_host`
or `default_host` is never used – the second reading of the files answers for such a name) when it
is a single string without `%` and a second reading of the configuration files has the same value
(otherwise it comes from an environment-specific file, `include_once`/`require_once` or another
plugin, and nothing is derived); `parse_host()` is never called. With `include_host_config` -
enabled by Roundcube's object or by the second reading (`Config::hostConfigEnabled()`), or a
`ROUNDCUBE_INCLUDE_HOST_CONFIG` variable, which Roundcube itself ignores for that option - nothing
is derived: the list must be explicit, and every `galbridge_*` option of a request is compared with
the host-independent files (`config.inc.php` and the plugin's files, `Config::hostDependentOptions()`):
one that differs – set by a per-host file – disables every book and the request is refused before
the database (`config_invalid`; the two book names may differ). **Transport**
(`ViewerResolver::transportProblem()`, on every request before the cache and the database): a
session on an IMAP server that is not local (socket, `localhost`, `127.0.0.0/8`, `::1`; host names
are never resolved) needs the TLS mode `ssl`/`imaps`, or `tls` on a Roundcube library of 1.7.7 or
later (a version without a patch level does not count), PHP's `openssl` extension and certificate
verification not switched off in `imap_conn_options` (the per-host entry selected as
`rcube_utils::parse_socket_options()` does); otherwise `transport_unprotected` (log
`imap_transport`), unless `galbridge_imap_allow_plaintext` accepts it with a warning. Both the
session's IMAP server (`$_SESSION['storage_host']` **and** `storage_port`) and the user's stored
`mail_host` must be covered, compared in the RAW form Roundcube wrote them
(`ViewerResolver::canonicalHost()`): a host name, an IPv4 address, an IPv6 address in brackets or a
socket URI – the stored `mail_host` may also be the configured URI of an older Roundcube – and
nothing is trimmed or parsed a second time. PHP connects with the raw value (the host up to the
first colon), so a value that a second parse would read differently (a blank, a scheme, a user
part, a colon, a path) could name an allowed server here while the session was authenticated by
another one; Roundcube 1.7.0 – 1.7.5 put a typed server into the session unchecked, and an
`imap_host` template with an unusual `Host` header can do so as well. Such a value is
`host_mismatch`, it is never logged, and `diag` counts the users that have one. The cached Viewer is
bound to host and port. Otherwise the plugin
stays disabled for the session. A local socket (Roundcube opens it with port -1) has neither host
name nor port: it is covered by the entry of that very socket – the path as written – and by
nothing else; the rule that lets a host entry cover a host without a known port never applies to
it, so a host called `unix` is not "the host part" of a socket URI. The home domain is `mailbox.domain` of the login's row (an active mailbox in an active,
non-backup-MX domain; with `galbridge_hide_expired` neither of them may be expired – by the
database clock in its session time zone or by UTC, whichever comes first, as for every directory
row, `QueryBuilder::unexpired()`) and must be the domain of the login itself – a row where the two
differ opens no directory; no row means no books.

*Tests.* `ConfigTest` (`testAllowedImapHostIsDerivedOnlyFromAFixedAdministratorHost`,
`testUserSelectableImapHostWithoutExplicitListDisablesThePlugin`,
`testSessionAndStoredImapHostMustBothMatch`, `testExplicitImapHostsMayNameAPort`,
`testTheImapPortOfTheSessionIsPartOfTheBinding`, `testCachedViewerIsBoundToTheImapPort`,
`testHostDependentConfigurationNeedsAnExplicitHostList`, `testALocalSocketIsAnEndpointOfItsOwn`,
`testASessionOnALocalSocketIsBoundToThatSocket`, `testIncludeHostConfigInTheEnvironmentNeedsAnExplicitHostList`,
`testTheSessionHostIsComparedInTheFormRoundcubeWroteIt`, `testCanonicalHostAcceptsOnlyWhatALoginWrites`,
`testABareIpv6EntryIsNoPrefixOfAHost`),
`Remediation2CoreConfigTest` (`testNoBindingIsDerivedWhenRoundcubesImapHostDiffersFromTheReread`,
`testAUserPreferenceNamedImapHostFallsBackToTheReread`, `testIncludeHostConfigCountsWhenEitherReadingEnablesIt`,
`testAFileIncludedOnceDoesNotChangeTheDecisions`), `Remediation2HostConfigTest` (every test),
`Remediation2ImapTransportTest` (every test; the unit test of the same name holds the matrix),
`IsolationTest::testLoginsWithoutAVisibleMailboxGetNoBooks`,
`IsolationTest::testCachedViewerIsBoundToTheImapHost`,
`AuditRegressionTest::testADisabledMailboxLosesTheDirectoryWithItsNextRequest` (the row whose
domain column was changed), `CliToolTest` (`testDiagWarnsAboutHostDependentConfiguration`,
`testDiagAcceptsAnImapServerOnALocalSocket`), E2E case 32.

*Residual risk.* A login under an alias domain gets no books (the login must equal
`mailbox.username`). The plugin compares host and port but cannot verify that a listed server
authenticates against this vmail database, nor see a plugin that rewrites the IMAP connection
(assumptions above; SECURITY.md register GB-11); with `galbridge_imap_allow_plaintext` the link is
trusted as it is. An entry of `galbridge_imap_hosts` without a port covers every IMAP port of its
host (the stored `mail_host` carries no port and is compared by host), so another IMAP service there
with a user database of its own is trusted too: `diag` warns for every such entry
(`Remediation2ResidualRiskTest::testDiagWarnsAboutAnImapHostEntryWithoutAPort`); refusing such
entries would break valid installations.

### GB-12 Stale entitlements

*Threat.* A disabled or moved account, an account whose password was changed or whose IMAP service
was disabled (after a compromise), a removed own alias, or a tightened configuration, keeps
directory access for the life of the session – or for the life of a cache entry.

*Controls.* The resolved Viewer is cached in `$_SESSION['galbridge']`, bound to the user id, login,
IMAP server and the configuration fingerprint (an HMAC, keyed with the `des_key` Roundcube itself
uses, over every effective `galbridge_*` value – the DSN without its password, so nothing in the
session store is derived from the database password whatever `des_key` is –, `imap_host` as
Roundcube applies it and whether `include_host_config` is in effect by any reading), for
`galbridge_scope_ttl` (300 s, 30–3600). Switches enter the fingerprint as they are in effect: for
one that is set through the environment Roundcube hands out its own conversion of the text, which
is `true` for "true" and for "false" alike. A configuration change applies on the next request; a
database outage is cached separately for 30 s and never as "no entitlement". **A cached
entitlement is confirmed before it is used to read**: the first statement of every request that
reads directory data is one lookup of the mailbox row with the predicates of the full resolution
(`ViewerResolver::confirm`, called by `Repository` before its first query). When the mailbox is
gone, disabled, expired or no longer in its domain, that request reads nothing and the session
entry is overwritten with "no mailbox" – overwritten, not unset: Roundcube merges the stored
session with the one of the request, a key that is only unset would come back. Requests that merely
instantiate a book (the sender-photo lookup of every opened message) do not pay for the statement.
With the strict list policy the viewer's own aliases are read in the same step: a removed alias
makes that request read nothing (`viewer_changed`) and the session entry is rewritten with the
current set (an added one is taken over without interrupting the request); the entry keeps its
time. **When the entry is renewed, the IMAP server confirms the credentials of the session** – the
check the plugin passes uses Roundcube's own connection (`rcmail::storage_connect()`, or the open
connection of the request), so Dovecot's own predicates decide (password, service flags), which the
role cannot read: a refusal (`AUTHENTICATIONFAILED`, `AUTHORIZATIONFAILED`, `EXPIRED`;
`ViewerResolver::imapVerdict()`) overwrites the entry with `imap_refused`, which holds for the rest
of the session whatever the configuration or the cache time (no second login attempt); a server
that cannot be asked gives `imap_unavailable` and a retry time, never a refusal.
A session entry that claims an entitlement but carries no usable Viewer is ignored and resolved
again. When the re-resolution of an expired Viewer fails with a database error, the expired Viewer
is used only to keep listing the session's books as inert DeniedBooks that answer "The directory is
temporarily unavailable."; it never reads directory data and is never written back. Directory
content is never cached: every list and search re-applies all visibility predicates.

*Tests.* `IsolationTest` (`testViewerIsCachedInTheSessionAndBoundToItsContext`,
`testConfigurationChangeOrExpiredTtlInvalidatesTheCache`, `testNegativeDecisionsAreCachedToo`,
`testOutageIsMemoisedAndNeverCachedAsNoEntitlement`), `ConfigTest`
(`testPreferencesDoNotChangeTheFingerprint`, `testFingerprintIsKeyedWithTheServerSecret`,
`testTheFingerprintFollowsSwitchesSetThroughTheEnvironment`),
`Remediation2RevocationTest` (every test; the unit test of the same name maps the IMAP answers),
`Remediation2CoreConfigTest::testTheFingerprintIsKeyedWithTheDesKeyRoundcubeUses`,
`Remediation2ConfigTest::testTheFingerprintDoesNotDependOnTheDatabasePassword`,
`Remediation2DiagConfigTest::testDiagWarnsAboutAShortOrNonStringDesKey`,
`AuditRegressionTest` (`testADisabledMailboxLosesTheDirectoryWithItsNextRequest`,
`testTheConfirmationRunsOncePerReadingRequestOnly`,
`testAViewerWithoutAddressesMatchesNoRestrictedList`), E2E case 34 (through Roundcube's own session
handler).

*Residual risk.* A changed password or a disabled IMAP service and a changed column grant (with the
role checks) apply when the entry is renewed (at most the TTL); a new own alias of the viewer is
taken over by the next reading request and used from the one after it (strict list policy);
deactivating the mailbox is the immediate control. While the IMAP server cannot be asked, the
directory of a renewing session is unavailable.

### GB-13 Global-book over-exposure in multi-tenant hosting

*Threat.* Cross-customer harvesting and disclosure of the list of hosted customers (the old plugin
defaulted to `['*']` lists and grouped the global book by domain).

*Controls.* Only the domain book is on by default. The global book is off; its viewer list is empty
by default; its data list is explicit, and `'*'` needs `galbridge_global_allow_wildcard` as a
second opt-in (`diag` warns while it is in effect). Deny-lists are applied after allow-lists and
disable the book when malformed. The viewer's own domain-book scope is subtracted unless
`galbridge_global_include_home`. Defaults: no groups (and no groups at all while search-only),
search-only, prefix search, every word at the minimum length, the `organization` field matched by
its complete label only, result cap 100, no whole-book export, foreign entries marked
`(domain) Name`. With department groups (`galbridge_global_groups = 'department'`) a group is per
domain (key `domain:department`, label `(domain) Department`): **group membership never crosses
tenants**, so a tenant that names a department like another tenant's cannot add its accounts to the
recipients of that group; `diag` warns that the number of such groups depends on every tenant's
data (above `galbridge_max_groups` the book shows none).
The domain book itself can be restricted per viewer domain (`galbridge_domain_viewer_domains`,
`..._exclude`).

*Tests.* `EntitlementsTest` (`testGlobalBookViewersAndDataDomains`, `testWildcardDataDomainsNeedTheSecondOptIn`,
`testIncludeHomeControlsDuplicatesOfTheDomainBook`, `testMalformedGlobalDataExcludeDisablesTheGlobalBook`,
`testDomainBookViewerExcludeList`), `IsolationTest::testDomainBookListsExactlyTheHomeTenant`,
`IsolationTest::testOtherTenantsAreNeverFound`, `ConfigTest::testInjectedPreferencesCannotWidenTheDirectory`,
`Remediation2MultiDomainBookTest::testDepartmentGroupsOfTheGlobalBookNeverMixDomains`,
`Remediation2DiagDataTest::testDiagWarnsAboutDepartmentGroupsOfTheGlobalBook`.

### GB-14 Disclosure of list membership, external addresses or private forwarding targets

*Threat.* `forwardings` holds alias members (`is_list`), per-user aliases (`is_alias`) and private
forwarding targets (`is_forwarding`); groups expand into recipients.

*Controls.* Groups are department or domain groups of **persons only**; alias accounts and mailing
lists are individual entries whose members are never listed or expanded. Alias accounts and lists
are shown only to viewers who may post to them under iRedAPD's `accesspolicy` (`AccessPolicy`;
compared with the iRedAPD sources, ARCHITECTURE 6); unknown policy values are hidden (fail closed).
The plugin does not read the `forwardings` table at all: every statement names the view
`galbridge_forwardings`, which holds the alias, alias-member and list rows only, and the role has no
grant on the table – the role cannot select a row of a private forwarding, not merely does not.
The view is also a barrier against inference by someone who holds the role's credentials: the
server builds its rows before it applies any condition of a query. MariaDB/MySQL: `ALGORITHM =
TEMPTABLE` keeps it from being merged into the query (a merged view evaluated a caller's condition
on every row of the table, on MariaDB 11.8 as an index condition before the flag filter), and the
`LIMIT 18446744073709551615` fence keeps the server from pushing a condition into it
(`condition_pushdown_for_derived`, MySQL `derived_condition_pushdown` – `TEMPTABLE` alone still did).
PostgreSQL: `security_barrier` plus an `OFFSET 0` fence – the planner moves no qual into a subquery
with `OFFSET`, also none it treats as leakproof, which `security_barrier` alone let through (an
index condition on `forwarding` whose row counts `EXPLAIN ANALYZE` shows). The statements of the
plugin refer to the view in uncorrelated semi-joins only (`Sql::inSelect()`: a hashed sub-plan or a
materialised sub-query, built once per statement, also inside the OR branches of the list policies).
`diag` checks the view's rows and the object (MariaDB/MySQL: not merged, `SQL SECURITY DEFINER`,
and with `SHOW VIEW` the definition: `ALGORITHM=TEMPTABLE`, the fence, the table `forwardings` alone;
PostgreSQL: owned by a superuser or the owner of the table, `security_barrier`, not
`security_invoker`, `OFFSET 0`, no object but the table in its rule); the plugin refuses a view with
rows of another kind (`role_too_broad`, on every engine) and logs `view_not_materialised` for an
unfenced one. The PostgreSQL grant set drops the view and creates it afresh in its transaction, so
no grant, owner, rule or comment of a view of that name created earlier by another role survives,
and a table of that name stops it.
Catch-all rows and external list members are never selected. Per-user aliases are shown only with
`galbridge_user_aliases`, and then **shown and matched under one predicate**
(`QueryBuilder::userAliasRoot`): the alias row belongs to a domain in the book's scope that is
active, not a backup MX and (with `galbridge_hide_expired`) not expired, its address and domain
column follow the canonical rule, and the alias is on neither hidden list. A hidden or out-of-scope
alias – also one written in another spelling of a hidden address – is therefore not shown and
cannot be used to find its owner. The aliases shown per person (20, bounded per owner with
`ROW_NUMBER()`, so every person of a page gets its own) and the viewer's own aliases used for the
list policies (200, of deliverable domains only) are bounded. With `galbridge_hide_expired`, alias
accounts and lists whose `expired` column the role cannot read are left out instead of being listed
unfiltered. Every forwardings / `moderators` / `maillist_owners` predicate is a semi-join.

*Tests.* `AccessPolicyTest` (`testListingShowsExactlyThePostableAliasesAndLists`,
`testHiddenEntriesAreUnreachableByIdAndSearch`, `testGlobalBookAppliesThePolicyOfTheViewer`,
`testGroupsContainPersonsOnly`), `LeakageTest::testNoOutputEverContainsACanary`,
`ContractTest::testListGroupsAndGroupSearch`, `AuditRegressionTest`
(`testHiddenUserAliasIsNeitherShownNorMatched`, `testAliasRowsOutsideTheBookAreNeitherShownNorMatched`,
`testExpiredListKindsAreLeftOutWhenTheirExpiryIsNotReadable`, `testAliasSetsAreBounded`,
`testThePluginReadsTheForwardingsViewOnly`), `DsnPolicyTest`
(`testTheTemplateRoleCannotReadPrivateForwardingTargets`,
`testRoleThatCanReadTheForwardingsTableIsRefused`), `Remediation2ForwardingsViewTest` (caller
expressions see only the view's rows; the MariaDB and the MySQL view are neither merged nor reached
by a pushed condition - MariaDB's JSON plan, MySQL's `EXPLAIN FORMAT=TREE`; PostgreSQL leakproof
conditions stay above the view; the view is built once per statement - MySQL: `EXPLAIN ANALYZE`; an
unfenced view is a `diag` FAIL and a log warning), `Remediation2RoleScopeTest`
(`testThePluginRefusesAViewWithOtherRows`, `testDiagChecksThePostgresViewObject`,
`testThePostgresGrantSetReplacesAForeignViewCompletely`, `testTheGrantSetStopsAtATableOfTheViewName`),
`Remediation2GrantTemplatesTest::testTheViewIsFencedOnBothEngines`, E2E case 31.

*Residual risk.* Building the view costs time on every statement that reads it, growing with the
alias and list rows of the table; that time reveals the size of the table, nothing about a row.
The suites read the fence from the server's own plan on the tested versions (README section 1:
MariaDB 10.5 to 13.1, MySQL 8.0 to 26.10, PostgreSQL 13 to 19; CI's job `databases` repeats a set of
them); a server release outside that set could plan the view differently, and `diag` checks the
view's definition, not the plan.

### GB-15 Cache poisoning across users or tenants

*Threat.* A shared cache keyed by search term or book would hand tenant A's results to tenant B;
packed caches use `unserialize()`.

*Controls.* By construction: GAL Bridge uses no Roundcube cache (`get_cache`, `get_cache_shared`),
no APCu and no `unserialize()`; results are memoised only inside one request (groups and counts in
`Repository`). The only cross-request state lives in the user's own session: the Viewer
(`$_SESSION['galbridge']`, bound to the user id, GB-12), the 30-second outage memo
(`galbridge_down_until`), the `'auto'` search-only decision per book (`galbridge_searchonly`,
bound to the book id and the configuration fingerprint; a boolean and a timestamp) and
`galbridge_env_noted` (the names of the `ROUNDCUBE_GALBRIDGE_*` variables in effect, kept so that
`env_override` is logged once per session; never their values – it decides nothing but that log
line).

*Tests.* `IsolationTest::testViewerIsCachedInTheSessionAndBoundToItsContext`; the absence of cache
APIs is a property of the source.

### GB-16 Administrator options overridden by user preferences

*Threat.* Roundcube merges stored user preferences over the configuration, so a `galbridge_*`
preference (saved by a plugin or written into the database) could widen the directory.

*Controls.* `Config::get()` ignores every preference named `galbridge_*` and returns the
administrator's value from a second reading of the configuration files (a fresh `rcube_config` plus
the plugin's files, found by `Config::pluginFiles()` exactly as `rcube_plugin::load_config()` finds
them, with Roundcube's environment suffix) or the built-in default. An option that no configuration
file sets is read through that second reading as well, so a `ROUNDCUBE_<OPTION>` environment
variable – the only place such an option may be set in a container deployment – applies exactly as
it does for a user without the preference (`rcube_config::all()` applies those variables to the
options of the files only). The second reading misses what `include_once`/`require_once` pulled in
before and has no environment name, so core options (`imap_host`, `include_host_config`,
`imap_conn_options`, `des_key`, `db_dsnw`) are read from Roundcube's own object
(`Config::coreValue()`) and from the second reading only when a preference shadows the name;
`Config::divergentOptions()` names what the two disagree on (`diag`), a derived binding needs them
to agree on `imap_host`, and the database users of every reading (`db_dsnw`, `db_dsnr`,
`password_db_dsn`, the password plugin's file) are refused (`Config::coreDsns()`). A user
preference cannot trigger the unknown-option rule of GB-29 either (only the administrator's files,
Roundcube's object without the preferences and the environment are looked at).

The reverse direction – configuration written **into** preferences – is closed as well. Roundcube
stores the current value of every `dont_override` option in the preferences of a user who saves
settings unless the value comes from the files loaded for the Settings page; an option set through
a `ROUNDCUBE_GALBRIDGE_*` variable is stored that way, `galbridge_db_dsn` with the database
password. 1.0.1 recommended listing `galbridge_*` options in `dont_override`; the advice is
withdrawn (SECURITY.md says not to, `diag` fails for `galbridge_db_dsn` in `dont_override` and
warns for other `galbridge_*` names), and the plugin is loaded in the Settings task for one hook
only: `galbridge::preferences_update()` removes every `galbridge_*` key from what is saved and from
the stored preferences it is merged with, so nothing of the kind is stored and copies saved before
disappear at the user's next save. `diag` counts the users whose stored preferences hold
`galbridge_db_dsn` (FAIL: rotate the role password) or other `galbridge_*` keys (WARN) – counts
only, never a value.

*Tests.* `ConfigTest` (`testUserPreferencesNeverOverrideAdministratorOptions`,
`testInjectedPreferencesCannotWidenTheDirectory`, `testGuardReadsAFreshConfigurationWhenAdminValuesAreNotGiven`,
`testThePreferenceGuardReadsOptionsSetThroughTheEnvironmentAlone`),
`PluginHooksTest::testStoredGalbridgePreferencesAreIgnoredByThePlugin`, `Remediation2CoreConfigTest`
(every test), `Remediation2PreferencesTest` (every test; `testRoundcubeStoresNoGalbridgeValueWhenSettingsAreSaved`
runs `rcube_user::save_prefs()` against Roundcube's database), `Remediation2ConfigTest::testUnknownOptionsComeFromTheAdministratorOnly`,
`Remediation2DiagConfigTest` (`testDiagFailsForTheDsnInDontOverride`, `testDiagCountsStoredGalbridgePreferences`),
`Remediation2DocsTest::testNoDocumentRecommendsGalbridgeOptionsInDontOverride`.

*Residual risk.* A DSN that Roundcube stored before the upgrade stays in backups and replicas of the
Roundcube database (and in a user's preferences until that user saves settings): rotating the role
password is the operator's step (SECURITY.md).

### GB-17 Core options a user controls are used unsafely

*Threat.* Users control `addressbook_pagesize`, `addressbook_sort_col`, `addressbook_search_mode`,
`contactlist_fields`, `autocomplete_addressbooks` and the `default_addressbook` /
`collected_*` source preferences.

*Controls.* Page sizes are capped (GB-06), the sort column is whitelisted, search mode is masked to
`& 3` (and forced to prefix in the global book), field lists go through `FieldMap`. A GAL Bridge ID
in a source preference goes through `addressbook_get` entitlement (GB-02). The books are read-only:
core's `get_address_book($id, true)` returns null for them, and the inherited mutators of
`rcube_addressbook` return false, so `collected_recipients` pointing at a GAL Bridge book writes
nothing. Roundcube saves `collected_senders` / `collected_recipients` without comparing them with the
writable books it offers, and `rcmail::contact_exists()` searches those sources without a
writability check: a preference naming a GAL Bridge book would make every directory address a
"known contact" (trusted sender: remote content, receipts). The `contact_exists` hook therefore
removes the GAL Bridge books from its sources (registered in the mail task, no database access).

*Tests.* `ConfigTest::testUserControlledCoreOptionsAreClampedOrWhitelisted`,
`ContractTest::testSortColumnsWithEmptyFieldFallbacks`, `CorePathsTest::testWritePathsAreRefused`,
`Remediation2CoreAssumptionsTest::testOwnBooksAreNeverAKnownContactSource`.

### GB-18 Stored XSS through source names, group IDs or contact IDs

*Threat.* Core prints the source name raw in the directory list and puts group and contact IDs into
HTML attributes and JavaScript strings without escaping.

*Controls.* Source names are escaped with `rcube::Q()` in `addressbooks_list`. Source IDs are
constants without `-`; contact and group IDs use `[A-Za-z0-9_]` only, so they are inert in HTML and
JavaScript. Contact and group names are passed unescaped because core escapes them, and they are
sanitised (`Normalizer::displayText`: control and format characters - bidi, zero-width, soft hyphen,
tag characters - and `\ " < >` removed). GAL Bridge renders no HTML of its own.

*Tests.* `ContractTest::testSourceListingInvariants`, `ContractTest::testRecordShapeAndColtypes`,
`NormalizerTest::testDisplayText`, `IdCodecTest`, `PluginHooksTest::testAddressbooksListEscapesTheName`,
the end-to-end suite
(`tests/e2e/`), which asserts on Roundcube's real responses.

### GB-19 Recipient or header injection and display spoofing from directory data

*Threat.* `format_email_recipient()` does not strip CR/LF; names with bidi overrides, quotes,
backslashes or embedded addresses can impersonate another recipient; IDN homographs.

*Controls.* `RecordMapper`: names NFC-normalised; control and format characters (`\p{Cf}`: bidi,
zero-width, soft hyphen, tag characters), CR/LF and `\ " < >` removed; separators and blank-looking
characters (U+2800, U+3164, U+115F, U+1160, U+FFA0) collapsed, so padding cannot push a marker out
of view; capped at 128 characters. The display name **and** the first and last names (core builds
recipient names from them for `addressbook_name_listing` 1-3 and `contactlist_name_template`) are
cut at the first at-sign, including the look-alikes U+FF20 and U+FE6B. That check is only as good
as the text it sees, so the two notations a later reader **decodes** are neutralised before it:
`&` becomes U+FF06 (the recipient widget of the Elastic skin inserts names as HTML after escaping
only `<` and `>`, so `&#64;` or `&commat;` would be displayed as an at-sign and travel on into the
recipient header), and `=?` and `?=`, the delimiters of an RFC 2047 encoded word that the mail
clients of the recipients would decode into arbitrary text, are split. A later **deletion** joins
the text on both sides of it, so what Roundcube deletes is deleted before the split: with a
`contactlist_name_template` other than `{name}`, `rcube_addressbook::compose_search_name()` removes
every empty bracket pair (`()`, `[]`, with the whitespace before it) from the composed name –
`=()?` would be `=?` again. Empty pairs are therefore removed until none is left, and a text never
starts with a closing or ends with an opening bracket (it would form such a pair with a bracket of
the template, `{name} ({department})`). The template is filled placeholder by placeholder
(`str_replace()`), so a `{department}` INSIDE a name would be filled as well and join two texts
that pass every check one by one: curly brackets become U+FF5B / U+FF5D. A template may also put
two fields next to each other without a blank (`{name}{department}`), so a text never starts or
ends with `=` or `?` (they become U+FF1D / U+FF1F there): no delimiter can be formed at a boundary.
Department and telephone numbers reach recipient names through such a template too: they are cut
at the at-sign like the names, and so are the labels of department groups and of organizations
(`{organization}`; a cut label is reported). Roundcube's suggestion list interprets two notations
of its own: it fills its template with `String.replace()`, the field being the replacement string
(`$&`, `` $` ``, `$'`, `$n`, `$<name>` would be expanded), and turns `##…%%` into bold text – `$`
becomes U+FF04, a run of `%` is split by blanks and a `%` at either end of a text becomes U+FF05.
Phones get these rules, not a character allow-list (free text in iRedMail, e.g. extensions). An
entry without any name – or with a name core takes for empty, such as `0` – gets its first and last
name, else the local part of its address as the name, treated like every other text. **Addresses
follow one allow-list rule** (`Normalizer::CANONICAL_ADDRESS`: lower-case ASCII letters, digits and
`. _ + = -` in the local part in dot-atom form, an ASCII host name of at least two labels), applied
by the SQL of every book and by the PHP re-check alike: no character that a sink of core interprets
(`&` – the suggestion list inserts the address as HTML –, `?` and `#` – the contact view passes it
to compose as a `mailto:` value –, `%`, `{`, `}`, `/`, `$`, quotes) and no non-ASCII look-alike
(U+212A, which `mb_strtolower()` folds to `k`, included) can reach a record; such entries are not
offered at all, `diag` lists them, and the row re-check drops a row that changed during the
request (`row_invalid` / `row_out_of_scope`, with a row tag). In the global book, entries outside the viewer's home domain(s)
are shown as `(domain) Name` with the ASCII domain – the marker first, because a recipient field
shortens a long name at its end – and carry no first or last name, so every listing mode shows the
marker. The marker is part of the name, so nothing of such an entry may stand before the name:
under a `contactlist_name_template` that shows another field of the entry before `{name}`, or has
no `{name}`, foreign entries do not carry that field (department, telephone numbers;
`RecordMapper::textBeforeName`), and `diag` names it. A field a foreign entry does not carry is no
search or sort criterion for it either (`RecordMapper::foreignWithheld()`, the one list for the
record and the SQL): withholding is not only presentational – a search or the sort order cannot
confirm the value. In a book of several tenants (the global book) a department group is per domain
and labelled `(domain) Department`: group membership never crosses tenants, so no tenant can add
its accounts to the recipients of another tenant's group.

*Tests.* `NormalizerTest` (`testDisplayTextOutputIsSafeForRecipientHeaders`,
`testDisplayTextRemovesInvisibleCharacters`, `testDisplayTextNeutralisesNotationsThatAreDecodedLater`,
`testAmpersandStaysReadable`, `testTextsCannotFormAnEncodedWordAcrossTheBracketsOfATemplate`),
`LeakageTest`, `ContractTest::testRecordShapeAndColtypes`, `CorePathsTest`
(`testListNamesKeepTheProtectionsInEveryListingMode` – the names as Roundcube's own
`compose_list_name()` composes them, for the four listing modes and nine templates, each in a
process of its own; `testTextThatATemplateShowsBeforeTheNameIsFound`),
`AuditRegressionTest` (`testNamesCannotBecomeAnAddressWhenDecodedLater`,
`testTheForeignDomainMarkerCannotBePushedOutOfSight`,
`testFieldsCannotBeJoinedOrPoseAsAnAddressThroughTheNameTemplate` – core's own
`compose_search_name()` for six templates), `Remediation2DisplayTextTest` (replacement patterns,
highlight markers, label words), `Remediation2NameTemplateTest` (every text field × payloads of every
class × templates with every placeholder; organization labels), `Remediation2CanonicalAddressTest`
(the address rule, a subset of core's check; a name core takes for empty),
`Remediation2CanonicalRowsTest::testEveryEngineOffersExactlyTheCanonicalRows`,
`Remediation2MultiDomainBookTest` (withheld fields neither matched nor sorted; department groups of
the global book per domain), `NormalizerTest`
(`testCurlyBracketsCannotFormAPlaceholderOfTheNameTemplate`, `testCutAtSignKeepsWhatStandsBeforeIt`), `CliToolTest`
(`testDiagTellsWhichFieldsTheNameTemplateTakesFromForeignEntries`,
`testDiagTellsThatMarkedEntriesAreSuggestedFirst`),
`Remediation2DocumentationTest::testDiagDescribesTheSuggestionOrderOfThisCore` (both core branches
in the 1.7.0 and newest legs), E2E case 35, browser case U8 (the recipient
widget and the suggestion list of the Elastic skin in Chromium).

*Residual risk.* Roundcube sorts the autocomplete suggestions of all address books by name and
pre-selects the first one. From Roundcube 1.7.6 it uses the ICU collator, where a name that starts
with the marker sorts before letters and digits, so entries of other domains are suggested
**before** unmarked names; 1.7.0 – 1.7.5 sort with the C library locale of the user's language,
which usually ignores `(` – marked entries are interleaved by the domain inside the marker, and
only the C locale puts them first; with `autocomplete_threads` the answers of the books are
appended in arrival order (SECURITY.md register GB-19; `diag` describes the order of the core in
use; `galbridge_global_autocomplete = false` keeps the global book out of autocomplete). Texts are treated field by field; what a template joins is covered by the rules
for brackets, placeholders and the edges of a text above, not by a check of the composed name.
Internationalised (SMTPUTF8) addresses are not offered (README 10). A department group label of the
global book shows the department of another domain's entries with the domain marker first, also
when a name template withholds the department from the record.

### GB-20 Over-exposure of personal data

*Threat.* `mailbox` holds phones, birthdays, employee IDs, recovery addresses, quotas and admin
flags; the old MySQL views also showed disabled accounts.

*Controls.* Allowlist `galbridge_fields` (default name, first name, last name, e-mail, department;
phone, mobile, organization and birthday are opt-in) intersected with the granted columns. Never
readable by the role: `password`, `recovery_email`, `allow_nets`, `settings`, storage, quota and
transport columns, admin flags, `rank`, `employeeid`, timestamps other than `expired`; `active`,
`enabledeliver` and `expired` are used in predicates only. Persons must be active, deliverable, in an
active non-backup-MX domain and (with `galbridge_hide_expired`) not expired. Administrator
deny-lists and the optional visibility column hide further entries. No photos, no `vcard` key in
records.

*Tests.* `ConfigTest` (`testOptionalFieldsAppearOnlyWhenAllowed`,
`testFieldsWhoseColumnsAreNotReadableAreDropped`), `IsolationTest::testInvisibleDomainsAndAccountsNeverAppear`,
`IsolationTest::testHideExpiredOffShowsExpiredButNeverInactiveEntries`, `EntitlementsTest`
(`testHiddenAddressesAreInvisibleEverywhere`, `testHiddenLocalPartsApplyToEveryDomainAndKind`,
`testVisibilityColumnHidesFlaggedPersons`, `testMissingVisibilityColumnHidesEveryPerson`),
`LeakageTest::testNoOutputEverContainsACanary`; the
integration role is built from `SQL/grants.*.sql`, so a query touching an ungranted column fails.

### GB-21 Information leakage through errors and logs

*Threat.* `rcube_db` logs failing SQL with the inlined search values unless `ignore_errors` is set;
error pages could reveal schema details.

*Controls.* The plugin's own `rcube_db` instance has `ignore_errors` on; a failed query is logged as
`db_error` with the SQLSTATE only (driver text with `galbridge_debug`); the user sees the generic
"The directory is temporarily unavailable." (at most once per request; core's read actions never
read `get_error()`, so the book sends the message itself) and an empty result. During a connection
outage the session's books stay listed and give the same message (GB-12); a session without a
resolved Viewer gets no books.

The parameters that carry a password or a DSN (`Dsn::__construct()` and its checks,
`Log::sanitize()`, `Log::maskCredentials()`, the verifier and statement builders of the grant tool,
`Terminal::clean()` and the output of the tool) are marked `#[\SensitiveParameter]`, so a stack
trace does not show them even where `zend.exception_ignore_args` is off.

*Tests.* `DsnPolicyTest::testPasswordNeverAppearsInErrorsOrDescriptions`,
`DsnPolicyTest::testRefusedDsnFailsClosedWithoutLoggingThePassword`,
`LeakageTest::testDebugLogNeverContainsSecrets`, `Remediation2LogTest::testSecretParametersAreMarkedSensitive`.

*Residual risk.* Connection failures are logged by Roundcube core with the PDO driver message (host
and user name; in the CLI only on stderr, and `diag` prints it). Database server logs contain the
literal search terms, because `rcube_db` inlines bound values on the client side (SECURITY.md). On
PostgreSQL the grant set keeps the role's statements out of the server log, so there is **no
statement audit trail** of the role: misuse of its credentials leaves connection lines (with
`log_connections`) and error messages without the statement text – the privacy of the search terms
was chosen over the trail (SECURITY.md register GB-21). An audit or plan-logging extension (pgaudit,
auto_explain) has settings of its own, which the grant set does not change: `diag` warns while
`pgaudit.log` or `auto_explain.log_min_duration` would log the role's statements
(`Remediation2ResidualRiskTest::testDiagWarnsWhenAnAuditExtensionCanLogTheStatementsOfTheRole`).

### GB-22 Log injection and personal data in plugin logs

*Threat.* Request values and directory strings may contain CR/LF, bidi characters or huge values;
logging them forges lines and stores personal data in shared logs.

*Controls.* `Log::sanitize()` redacts a value with invalid UTF-8 as a whole, replaces every control
character (C0, C1) and every format character (Unicode `Cf`: bidi, zero-width, soft hyphen, tag
characters ...) and the line separators by a blank, then masks passwords (`Log::maskCredentials()`,
which the `STDERR` filter of the command line tool shares): `scheme://user:password@host` also with
blanks, `@`, `/` or `:` in the password (up to the last `@` before the next `scheme://` of the
line), a `/` in the user name and the old `scheme(syntax)://` notation, and `password=` /
`PGPASSWORD=` / `db_pass=` pairs with a bare or quoted value and `"password": "..."`; a text the
patterns cannot be applied to is replaced as a whole; then it truncates to 300 characters. A context
value with a blank, `=`, `"` or `\` is written in double quotes (escaped), and a key is a code
constant, so a value cannot pose as further `key=value` pairs. `config_invalid` names the option and
the position of a rejected list entry, never the entry (an entry of a hidden list is a person the
administrator wanted hidden). Normal levels carry reason codes,
the numeric user id, book ids, counts and SQLSTATEs only; rejected group IDs are logged as a
12-character hash (`Log::tag`). The plugin adds every enabled book to `autocomplete_addressbooks`
(it cannot know the entitlement without the database), so an autocomplete request that names no
source logs the refusal of an enabled book the user is not entitled to at debug level only; a
request that names the book itself (`_source`), and every other action, logs `denied_source` as
before. Reason codes triggered by request input or directory rows are
logged at most once per request (`Log::once`, keyed by operation and code; the malformed-row WARN
`row_invalid` and the scope tripwire ERROR `row_out_of_scope` have separate codes, so one cannot
hide the other): one request writes at most one line per reason code, whatever it sends or reads. A
loop of requests still writes up to one line per reason code per request – bounded by the request
rate, which the reverse proxy limits (GB-26). `galbridge_debug` adds only driver error texts,
exception messages and schema hints; `diag` warns while it is on. An ERROR or WARN line that
Roundcube refuses to write to the `galbridge` log (`per_user_logging` without a log directory of the
user, an unwritable file; `rcube::write_log()` returns false) is written to Roundcube's `errors` log
instead, and to PHP's error log when that fails too – a refused role or an invalid configuration
never disappears without a trace.

*Tests.* `LogTest` (`testLogInjectionStaysOneLine`, `testBidiSpoofingIsNeutralised`,
`testOnceCannotBeFlooded`, `testDsnPasswordIsRedacted`, `testExceptionDetailsRequireDebugLogging`),
`LeakageTest::testNormalLogLinesNeverContainSearchTerms`, `Remediation2LogTest`
(`testDsnPasswordMaskCoversEveryForm`, `testContextValuesWithBlanksOrEqualsAreQuoted`,
`testEveryFormatCharacterIsReplaced`, `testControlCharactersCannotSplitTheMask`,
`testTextThePatternsCannotHandleIsRedactedAsAWhole`), `Remediation2ConfigTest`
(`testConfigProblemsNamePositionsNotEntries`, `testAutocompleteOfABookTheUserIsNotEntitledToIsNoDenial`),
`Remediation2ResidualRiskTest` (`testErrorAndWarningLinesSurvivePerUserLogging`,
`testDiagWarnsWhileDebugLoggingIsOn`).

*Residual risk.* With `galbridge_debug` on, driver and exception texts – which can quote search
words and addresses – reach the log unfiltered (they are what a diagnosis needs). The log is bounded
per request, not per session: throttling per session would need session writes on read requests
and would hide repeated probing. Under `log_driver = 'syslog'` Roundcube gives every `galbridge`
line the priority INFO; the level is the word in the line.

### GB-23 Over-privileged or exposed database credentials

*Threat.* With iRedMail's `vmail` role (SELECT on every column) or `roundcube` role (UPDATE on
`vmail.mailbox`) any plugin bug could read password hashes or modify vmail; the old plugin's
PostgreSQL view embedded the `vmail` password in a `dblink` connection string. A role that can read
the whole `forwardings` table can read where every mailbox forwards its mail.

*Controls.* A mandatory, separate `galbridge_db_dsn`. `Dsn` refuses the built-in roles (iRedMail's
roles, `root`, `postgres` – always: `galbridge_db_refused_users` adds names and cannot remove one)
and the names that option adds; a value that is not a list, or a list with an entry that is not a
role name, refuses every connection (`config_invalid`; `diag` FAIL with the position). It also
refuses the users of `db_dsnw`, `db_dsnr` and the password plugin's DSN; `diag` warns when the
password plugin is enabled with its SQL driver and no `password_db_dsn` is found, and shows the
refused roles.
`ViewerResolver::lookup()` probes the catalogs the role can read and denies every book
(`role_too_broad`, the reasons in the log) when the role can do more than read the directory:
a secret column (`password`, `recovery_email`, `allow_nets`) visible in **any** relation of the
directory schema / database (`Connection::visibleColumnsEverywhere()`), the `forwardings` table
readable, and – `RoleScope`, one catalog statement per engine – a role attribute (PostgreSQL
`SUPERUSER`, `CREATEROLE`, `CREATEDB`, `REPLICATION`, `BYPASSRLS`), a membership in another role (a
`NOINHERIT` role reaches the privileges of its groups with `SET ROLE`, which `information_schema`
does not show; MariaDB/MySQL `APPLICABLE_ROLES`), the ownership of the database, a global privilege
other than `USAGE`, a privilege outside the directory database (PostgreSQL `pg_shdepend`: objects
of other databases and other databases themselves; relations and sequences of another schema,
`PUBLIC` included; `CREATE` on another schema, `PUBLIC` included – except the old default of
`PUBLIC` on schema `public`, which counts like `CREATE` on the role's own schema; every other
privilege granted to the role on an object of the directory database that is no relation – another
schema, a function, a type, a foreign server or wrapper, a large object, default privileges –, from
`pg_shdepend`, named by `pg_identify_object()`; the role's `USAGE` on its own schema is the one
expected entry; a privilege on or the ownership of a tablespace or – PostgreSQL 15+ – a
configuration parameter (`GRANT SET` / `ALTER SYSTEM ON PARAMETER`); MariaDB/MySQL: schema, table and column privileges elsewhere and every other database the account
can see), or a privilege to change anything (PostgreSQL: every relation and sequence of the schema,
`PUBLIC` included; MariaDB/MySQL: every grant other than `SELECT` and the `SHOW VIEW` of the grant
set) – and a view `galbridge_forwardings` with a row of another kind (GB-14). CREATE on the role's
own schema or the database and objects the role owns are logged (`role_can_create`), not refused: a database of
PostgreSQL 14 or older gives every role CREATE on schema `public`. For the same reason a
MariaDB/MySQL server-wide test database – `test` or `test_*`, which `mariadb-install-db` opens to
every account (MariaDB 10.11 and later through `PUBLIC`, older servers through blank-user rows of
`mysql.db`) – on which the account holds no grant of its own is logged (`server_test_database`) and
is a `diag` FAIL ("server-wide test database", with the fix), not a refusal; any other database
the account can see, and a test database it holds a privilege on, stay refusals. The grant templates create a
new role with column-level `SELECT` only (MariaDB/MySQL also `SHOW VIEW` on the view, for `diag`) and
the view `galbridge_forwardings` instead of a grant on the table; on both engines the account is
limited (a connection limit the server enforces; a default statement time on PostgreSQL and
MariaDB, which any session may change – the plugin's own sessions set `galbridge_db_timeout`,
GB-26 –, none on MySQL; PostgreSQL also `NOINHERIT` and read-only transactions by default – a
default as well: the role is read-only because it holds only `SELECT`). The PostgreSQL grant set
asserts `NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT` in every form,
takes `CREATE` on schema `public` from `PUBLIC` (the owners of tables there keep it by name) and
explicit `CREATE` / `TEMPORARY` on the database from the role, and names its objects through
`search_path = public`. The personal-data columns (`telephone`, `mobile`, `birthday`) are a
commented-out grant. The view is the only object GAL Bridge adds to `vmail`; no `dblink`, no stored
credentials. `diag` accepts the password-column check only when the server refuses the statement
for lack of privilege, checks that the `forwardings` table is not readable, reads the role's write
privileges from the catalogs (PostgreSQL on every relation and sequence of the schema, MariaDB/MySQL
`information_schema` privileges) – and, on MariaDB/MySQL, also asks the server with statements that
can change nothing, which finds privileges granted through a database role or `PUBLIC` as well –
fails for every reason the plugin refuses, for CREATE (`schema public`) and owned objects, names
every column beyond the grant set (WARN), lists the other databases a PostgreSQL role may connect to
(INFO) and warns about a MariaDB/MySQL account other hosts may use without `REQUIRE SSL`.

*Tests.* `DsnPolicyTest` (`testIredmailAndSuperuserRolesAreRefused`,
`testRoundcubeAndPasswordPluginUsersAreRefused`, `testRoleThatCanReadSecretColumnsDisablesEveryBook`,
`testRoleWithoutARequiredColumnFailsClosed`, `testTheTemplateRoleCannotReadPrivateForwardingTargets`,
`testRoleThatCanReadTheForwardingsTableIsRefused`, `testRefusedUserListIsConfigurableAndStillRefusesTheRoundcubeUser`),
`ConfigTest::testAMalformedRefusedUsersListRefusesEveryConnection`, `Remediation2ConfigTest`
(`testBuiltInRoleNamesCannotBeRemoved`, `testAMalformedRefusedRoleListRefusesEveryConnection`),
`Remediation2DiagConfigTest` (`testDiagShowsTheRefusedRoles`, `testDiagWarnsWhenThePasswordPluginDsnIsUnknown`),
`OldPluginRegressionTest::testDefect11PortableSqlLeastPrivilegeAndStableIds`, `CliToolTest`
(`testGeneratedGrantsCreateAWorkingLeastPrivilegeRole`, `testGrantsOnlyMovesARoleOfTheOldGrantsToTheView`,
`testDiagFailsForAWriteCapableRole`, `testDiagFindsWritePrivilegesGrantedThroughARole`),
`Remediation2RoleScopeTest` (the plugin and `diag` refuse a secret column of another table, a
membership, a privilege outside – on PostgreSQL also `CREATE` or `USAGE` on another schema and
`EXECUTE` on one of its functions –, a write privilege, a role attribute, the ownership of the
database and a global privilege; the grant set revokes CREATE on schema `public` and resets the attributes;
`diag` looks at every relation, owned objects and columns beyond the grant set; CREATE on schema
`public` is a `diag` FAIL and a log warning;
`testAServerWideTestDatabaseIsNoRefusalButDiagFails`: an open `test_*` database is a warning and a
`diag` FAIL, one the role holds a grant on and any other open database are refusals),
`Remediation2GrantsOutputTest`
(`testDiagWarnsAboutAnAccountOtherHostsMayUseWithoutTls`), `Remediation2GrantTemplatesTest`
(`testGrantableIsExactlyWhatTheTemplatesGrant`, `testTheRefusalsOfARoleNameEveryReason`); CI and the
local integration databases connect with a role created from the shipped templates
(`tests/fixtures/vmail/load.sh`, with the commented-out personal-data grant enabled).

*Residual risk.* The password is in the plugin configuration file; protect it with `0640
root:<PHP group>` and the web-server deny rule (README 2.3, 2.6). Roundcube's plugin installer
re-creates `plugins/galbridge/config.inc.php` at every Composer install and update with the owner
and umask of the user running Composer and keeps a full copy of the plugin directory next to it
while it runs: Composer installations keep the configuration in `config/galbridge.inc.php`; `diag`
fails for a leftover installer backup (`plugins/galbridge-<20 digits>`) and warns for a
plugin-directory configuration of a Composer installation, and the nginx rule of README 2.6 covers
the backup name (`Remediation2DiagConfigTest`: `testDiagFailsForAComposerBackupNextToThePluginDirectory`,
`testDiagWarnsWhenComposerManagesThePluginAndItsConfigLivesInside`; `Remediation2ToolTest`). The
credentials and how they are created: GB-30. The probe sees what the catalogs show the role: on
MariaDB/MySQL a privilege granted to `PUBLIC` (10.11 and later) is not among them (`diag` asks the
server for the relations GAL Bridge reads and lists every column the role can see), and
`pg_hba.conf` – which databases and addresses a PostgreSQL role may use – is invisible to the role
(README 2.2 gives the lines; `diag` lists the databases it may connect to). MySQL before 8.0.19
cannot show memberships (`diag` WARN "not checked"). A server-wide test database of
`mariadb-install-db` (`test`, `test_*`) is open to every account, the plugin's role included: whoever
holds the password can create tables there, store data and use the server's resources until the
database is removed – the role is not refused for it (that would take every book away on every
RHEL, FreeBSD or tarball installation that kept the default), the log warns and `diag` fails until
`mariadb-secure-installation` removes it (SECURITY.md, register GB-23). The exemption is by name: a
database an administrator creates as `test_<something>` and opens to every account is treated the
same way.

### GB-24 Eavesdropping on or tampering with traffic to a remote database

*Threat.* The role's password and the directory cross a network; a DSN that is checked in one
reading and used in another, or a client library that takes its target from the environment,
connects somewhere else or without the protection the check assumed.

*Controls.* `Dsn` judges the DSN in the form the driver receives it: an explicit host or unix
socket, a port of digits, a plain database name, and only parameters of a per-driver allow-list,
each once and matching its pattern; the PostgreSQL `service` parameter and the client-library
variables that redirect a connection (`PGHOSTADDR`, `PGSERVICE`, `PGSERVICEFILE`; `PGPORT` for a DSN
without a port) are refused. The raw text must be written in one canonical grammar (target `host`,
`host:port`, `[IPv6]`, `[IPv6]:port` or `unix(/path)`, the database name as the driver receives it,
then the parameters), so nothing the core parser drops or reinterprets is accepted (a second port,
text around brackets, `unix+…`, `tcp(…)`, a `?` before the database, a database suffix); the DSN
handed to the driver is **rebuilt** from the judged parts, and the core parser must read exactly
those parts back (no more "configured text plus `?timeout=`"). Unix socket paths fit `sun_path`
(107 bytes, PostgreSQL including `.s.PGSQL.<port>`). MariaDB/MySQL `localhost` is the socket of
`pdo_mysql.default_socket` (shown by `describe()`/`diag`; refused when it is not a usable absolute
path), and a port next to it or inside `unix(…)` – which the driver would ignore – is refused; any
other spelling of `localhost` is refused. `diag` warns when the directory of the socket in use is
writable by every local account. A non-local host (anything but a unix socket, `127.0.0.0/8`, `::1`,
`localhost`) is accepted only with TLS that verifies the **host name**: PostgreSQL
`sslmode=verify-full`, MySQL `ca` or `capath` with `verify_server_cert=1`. `sslmode=verify-ca`
(issuer only) needs `galbridge_db_allow_verify_ca`, anything weaker
`galbridge_db_allow_plaintext`; both are logged as `dsn_warning` and shown by `diag` (WARN), which
also warns when `pdo_mysql` is not built on `mysqlnd`. PostgreSQL `localhost` is resolved by the
system resolver on every connection: accepted as local with a `dsn_warning` (SECURITY.md register).

*Tests.* `DsnPolicyTest` (`testRemoteHostWithoutVerifiedTlsIsRefused`,
`testRemoteHostDisguisedAsLocalSocketIsRefused`, `testRemoteHostWithVerifiedTlsIsAccepted`,
`testVerifyCaIsNotVerifiedTls`, `testDsnIsJudgedInTheFormTheDriverReceives`,
`testOrdinaryDsnsAreStillAccepted`, `testLibpqEnvironmentThatRedirectsTheConnectionIsRefused`,
`testPlaintextRemoteNeedsExplicitOptInAndWarns`, `testPostgresServiceParameterIsRefused`,
`testLoopbackDetection`), `Remediation2DsnTest` (`testTheTargetMustBeWrittenInOneCanonicalForm`,
`testTheDatabaseNameIsWrittenAsTheDriverReceivesIt`, `testMysqlRefusesAPortItWouldIgnore`,
`testOverlongSocketPathsAreRefused`, `testPostgresLocalhostIsAcceptedWithAWarning`,
`testMysqlLocalhostNamesItsSocket`, `testTheEffectiveDsnIsRebuiltFromTheJudgedParts`,
`testDiagChecksTheSocketDirectory`, `testTheGrantToolPrintsOnlyDsnsThePolicyAccepts`).

*Residual risk.* PostgreSQL `localhost` depends on `/etc/hosts` and `nsswitch.conf` of the web
server (warning only, so that existing installations keep working); a socket in a directory others
can write is a `diag` WARN, not a refusal (the plugin cannot know whether the server keeps it
bound).

### GB-25 Write attempts through a read-only book

*Threat.* Create, import, copy-to, group management or collected recipients aimed at a directory
book; the old plugin's `rename_group()` returned a truthy value.

*Controls.* `readonly = true`; the mutators are those of `rcube_addressbook`, which return false;
`addressbook_get` returns an instance even for writable requests (core then returns null itself
instead of dying with error 700). The role has no write grants and the session is set read-only.

*Tests.* `CorePathsTest::testWritePathsAreRefused`, `CorePathsTest::testCopyToAPersonalBook`,
`PluginHooksTest::testAddressbookGetAlwaysAnswersOurIds`.

### GB-26 Denial of service against the database or PHP workers

*Threat.* Keystroke floods, slow `LIKE` scans, one count per group, a dead database blocking every
webmail request, connection exhaustion.

*Controls.* No request rate limiter (decision, ARCHITECTURE 11 and SECURITY.md); instead: minimum
search length and word limits; prefix search in the global book; result, ID, group, export and
count caps; one aggregate group query per request and book, `virtual` groups (no count per group in
the compose list); the scope predicate first (indexed `domain`); a lazy connection opened only by a
query (never in `init()`; the sender-photo lookup of every opened message runs no directory query,
and the Viewer is resolved at most once per `galbridge_scope_ttl`); a connect timeout
injected into the DSN (`galbridge_db_connect_timeout`, 2 s; it bounds the TCP connection); on
MariaDB/MySQL a **read timeout** of the plugin's connection (the larger of the two timeouts plus
2 s, `mysqlnd.net_read_timeout` while the connection is created, restored right after; never raised
above a lower php.ini value), so a server or proxy that accepted the connection and stays silent no
longer holds the worker for mysqlnd's 86400 s; the plugin's statement timeout and read-only session
on every connection (`galbridge_db_timeout`, 3 s) – the statement bound of the plugin on every
engine (the role default of the templates applies to other sessions; MySQL has none) – and a server
that refuses either is not used (`session_limit_refused`, fail closed); a failed connection memoised
for the request, and for 30 s in the session only when the server is unreachable or slow (the
attempt took at least half of the connect timeout, or the error says it cannot be reached), as are
a driver error while connecting, a refused session setting and a connection lost during a
statement – a refusal the server answers at once (the connection limit of the role or the server,
an authentication error, a limit text in the server's language) fails that request only, so one
user who holds the role's connections cannot take the directory away from other sessions for 30 s;
at most `galbridge_max_ids` contacts read one by one per request (core's copy action calls
`get_record()` for every ID; `LookupBudget`, one per request); the details and aliases of a full set
read in statements of at most 500 addresses (`Repository::DETAIL_BATCH` – rcube_db inlines bound
values one by one, at a cost that grows with the square of their number), and `diag` warns when
`galbridge_max_export` or the group cap exceeds 10000; **limits of the account on both engines**,
set by the grant templates and sized with `grants --connection-limit` (PostgreSQL `CONNECTION
LIMIT` and the default `statement_timeout`; MariaDB/MySQL `MAX_USER_CONNECTIONS` and, on MariaDB,
the default `MAX_STATEMENT_TIME`), which `diag` reports (WARN when the account is unlimited) next
to the statement time of the plugin's session (FAIL when it is not `galbridge_db_timeout`) and the
read timeout; bounded alias sets (GB-14), bounded per owner (`ROW_NUMBER()`). The costs a user
causes besides the statements of a request: one statement per reading request to confirm the
mailbox (with the strict list policy one more for the own aliases), and on every renewal of the
cached user (`galbridge_scope_ttl`) the user lookup, two catalog statements (`RoleScope`, the view
check) and, in address-book requests, one IMAP login.

*Tests.* `CorePathsTest::testSenderPhotoOfEveryOpenedMessageNeverQueries`,
`PluginHooksTest::testInitRegistersTwoHooksAndNeverTouchesTheDatabase`,
`ContractTest::testEmptyTooShortAndTooLongInputNeverQueries`,
`IsolationTest::testOutageIsMemoisedAndNeverCachedAsNoEntitlement`,
`DsnPolicyTest::testConnectTimeoutIsInjectedWhenMissing`, `DsnPolicyTest::testInjectedConnectTimeoutIsClamped`,
`CliToolTest` (`testConnectionLimitIsAppliedForBothEngines`, `testDiagReportsTheConnectionLimitOfTheAccount`,
`testGeneratedGrantsCreateAWorkingLeastPrivilegeRole`), `AuditRegressionTest::testAliasSetsAreBounded`,
`Remediation2DbTimeoutTest` (`testASilentServerFailsWithinTheReadTimeout`,
`testAServerThatStopsAnsweringAfterTheHandshakeFailsWithinTheReadTimeout`, `testTheReadTimeoutIsNeverRaised`,
`testALostConnectionIsMemoisedLikeAnOutage`, `testDiagShowsTheReadTimeout`), `Remediation2DbLayerTest`
(`testRefusedSessionSettingsFailClosed`, `testDiagShowsTheSessionStatementTime`),
`IsolationTest::testAConnectionLimitRefusalIsNotMemoisedAsAnOutage`, `Remediation2ResourceLimitsTest`
(`testAnUnrecognisedFastRefusalIsNotMemoisedForTheSession`, `testCopyReadsAtMostMaxIdsContactsPerRequest`,
`testFullSetDetailStatementsAreBatched`), `Remediation2DiagDataTest::testDiagWarnsAboutCostlyFullSetLimits`.

*Residual risk.* The plugin does not slow down a user who sends many requests: request volume is
limited by the reverse proxy – per-client request-rate and concurrency limits on every Roundcube PHP
request, the concurrency limit below the role's connection limit; a limit keyed on the query-string
`_action` alone misses the POST-body and cookie forms Roundcube reads – and the connection limit of
the account (SECURITY.md, checklist and register GB-26). A client that holds as many parallel
requests as the role's connection limit makes the requests of other sessions fail while it does. The
plugin log grows with the request rate as well (one line per reason code per request, GB-22). PostgreSQL has no client read timeout after connecting (libpq has none and
Roundcube passes no keepalive settings), nor has a `pdo_mysql` without `mysqlnd`: a server that
stops answering mid-statement holds the worker until PHP-FPM's `request_terminate_timeout`
(SECURITY.md checklist; register).

### GB-27 Scope or deny-list bypass through case, IDN or collation differences

*Threat.* MariaDB's `utf8mb4_general_ci` compares case-insensitively with PAD SPACE, PostgreSQL
`varchar` case-sensitively; a deny-list entry in mixed case or as a U-label would hide a tenant on
one engine and expose it on the other.

*Controls.* One `Normalizer` for configuration lists, the login and decoded IDs (trim, NFC, lower
case, split at the last `@`, IDNA to ASCII – failing without `intl` for non-ASCII input – trailing
dot removed, validated). An invalid allow-list entry is dropped; an invalid deny-list disables what
it protects. SQL binds normalised values. **Rows are canonical or not offered**: the root predicate
of every kind applies `Normalizer::CANONICAL_ADDRESS` (lower-case ASCII local part of `a-z 0-9 . _ +
= -`, ASCII host name equal to the domain column; per-user aliases: the address and a canonical
domain column) in the engine's own SQL (ASCII by `octet_length` / `LENGTH` = character length, the
alphabet, the ERE or SQLite `GLOB`), and the PHP re-check applies the same rule – so a row written in
NFD, with a non-ASCII capital, with a U-label domain column or with any character outside the
alphabet is neither counted, matched, grouped nor delivered on any engine, instead of escaping the
deny-lists in SQL and being dropped only by PHP after it was counted. The deny-lists - hidden
addresses and excluded domains - then compare canonical ASCII: `LOWER()` of the column in SQL on
every engine (MariaDB/MySQL offer rows with ASCII capitals, lower-cased by PHP; PostgreSQL and SQLite
do not offer them); allow-lists compare the plain column (a row in another spelling is left out -
fail closed). Department groups use `LOWER(TRIM())` on both engines. `diag` lists every row that is
not offered (`entries not offered`: persons, alias accounts, lists, per-user aliases, hosted
domains, with examples and the row tag of the log), and on MariaDB/MySQL the addresses with capitals
it offers and a Turkish/Azerbaijani collation of an address column.

*Tests.* `NormalizerTest`, `NormalizerWithoutIntlTest::testAsciiWorksAndIdnFailsClosedWithoutIntl`,
`IsolationTest::testIdnTenantIsIsolated`, `EntitlementsTest` (`testMalformedViewerDenyListDisablesTheBook`,
`testMalformedHiddenListDisablesEveryBook`, `testOverlappingOrganizationsFallBackToTheOwnDomain`),
`AuditRegressionTest::testHiddenListsAlsoMatchRowsWrittenWithCapitals`, `Remediation2CanonicalRowsTest`
(`testHiddenEntriesInAnotherSpellingAreNeitherCountedNorMatched`,
`testPerUserAliasInAnotherSpellingFindsNoOwner`, `testEveryEngineOffersExactlyTheCanonicalRows`),
`Remediation2CanonicalAddressTest` (the SQLite rendering and the ERE against the PHP rule),
`Remediation2DiagDataTest` (`testDiagListsEntriesThatAreNotOffered`,
`testDiagTellsWhatAnAddressWithCapitalsMeansOnThisEngine`).

*Residual risk.* Accent folding still differs (README 10) – a search convenience, no scope
question. PostgreSQL does not offer an address written with capitals (MariaDB/MySQL do); entries
outside the address rule (internationalised local parts, for instance) are not in the directory at
all (`diag` lists them). On MariaDB/MySQL the lower-case comparison of the deny-lists assumes
iRedMail's collation: under a Turkish or Azerbaijani collation `LOWER('I')` is a dotless `i`, so an
address written with a capital `I` escapes a hidden list in SQL and is dropped by PHP (`diag`
WARN).

### GB-28 Bulk export or printing, and processing before authentication

*Threat.* `contacts/export.php` exports a whole book with page size 99999; plugin `init()` runs
before Roundcube's CSRF and authentication checks.

*Controls.* A whole-book export is allowed only when the book's export option is on (domain book
`true`, global book `false`) and only up to `galbridge_max_export`; a search-only book exports
nothing without a search. Every export is complete or refused (GB-06), and a refused export
downloads nothing (`204 No Content`). Exports contain the effective fields only. `init()` returns without an authenticated user, never touches the
database and registers no actions; the plugin is limited to the `mail` and `addressbook` tasks.
Roundcube's dispatcher reaches the same handler for `export`, `EXPORT` and – with `-`/`_` –
`group-expand`/`group_expand` (PHP class names ignore case). `init()` sees the action as core took
it from the request – `_action` from the query string, the POST body or a cookie, with every
character other than ASCII letters, digits, `_`, `-` and `.` removed (`asciiwords()`) – before the
`startup` and `ready` hooks, where another plugin may still change it, and before core maps its
deprecated action aliases: every decision that depends on the action
(the autocomplete wiring and switches, complete-or-refused exports, the refusal of a stale search
export, the 204 answer, groups as full sets for `mailto` and `group-expand`) compares it in that
canonical form (`Factory::canonicalAction()`, read when the decision is made), and the
`addressbook_export` hook is registered for every request of the `addressbook` task – it acts only
after a book refused an export of the request.

*Tests.* `CorePathsTest::testExportPaths`, `PluginHooksTest` (`testAnonymousRequestsRegisterNothing`,
`testInitRegistersTwoHooksAndNeverTouchesTheDatabase`, `testAutocompleteIsWiredOnlyForComposeAndAutocomplete`,
`testExportHookIsRegisteredOnlyForTheExport`), `Remediation2CoreAssumptionsTest` (every test).

### GB-29 Configuration values that fail open

*Threat.* PHP casts: the string `'false'` is true, an array where a column name is expected becomes
"no column". Roundcube's environment overrides (`ROUNDCUBE_<OPTION>`) turn the text `false` into
the boolean true before a plugin sees it. A slip of the administrator then switches a restriction
off, or a feature on, without any sign. So does a configuration that is not the one written down: a
misspelt option name (`galbridge_hidden_adresses`) or variable leaves the option meant at its
default – for a deny-list "nothing hidden" –, and a plugin configuration file that exists but does
not load (unreadable for the PHP user, no `$config` array) or that Roundcube ignores (a
`galbridge.inc.php` next to `plugins/galbridge/config.inc.php`) leaves the built-in defaults or the
other file in force.

*Controls.* `Config` never casts. A switch is a boolean or the integer 1 / 0 (the `searchonly`
options also `'auto'`); `galbridge_visibility_column` is a string; the hidden lists, deny-lists and
`galbridge_db_refused_users` are lists. Anything else is a reported problem, and what it disables
follows what the option protects: an ambiguous switch, a visibility column of the wrong type or a
malformed hidden list disables **every book** (`Config::booksDisabled`, enforced by `Entitlements`
and again by `QueryBuilder::forBook`), and the ambiguous switch itself is read as its restrictive
value meanwhile (`Config::RESTRICTIVE`: off, except `hide_expired` and `mark_foreign_domains`; the
`searchonly` options read as on), so no code path that consults a switch on its own reads it as
permission; a malformed domain deny-list (`*_exclude`) disables **the book it protects**, for every
user (`Config::domainDenyList()` returns null, `Entitlements` drops the book); a malformed
`galbridge_db_refused_users` refuses **every database connection** (`Dsn`, `config_invalid`). For its switches the plugin reads the environment variable
itself and accepts only `1`, `0`, `true`, `false` (`auto`). `galbridge_fields` that is not a list
shows name and e-mail only – fewer fields, never more – and is reported like the others. A number
is a whole number (an integer or a string of digits; a variable is judged by its text) within the
range of `Config::RANGES`, the one every caller uses: another value is reported and the default is
used, a value outside the range is reported and clamped (`diag` WARN). The built-in refused roles
are a floor (GB-23). `Config::configUnreliable()` disables every book – and `ViewerResolver`
connects to nothing – for a plugin configuration file that does not load or is ignored
(`Config::configFiles()` / `fileProblems()`, from the files the second reading loads), for a
`galbridge_*` option of the administrator's files or of Roundcube's object, or a
`ROUNDCUBE_GALBRIDGE_*` variable, that this version does not know (`Config::unknownOptions()`, with
the closest valid name; `Config::RETIRED` lists removed options, which are reported only) and, with
`include_host_config`, for an option that depends on the host name (GB-11); compose wires no book
into autocomplete then. `diag` prints every problem, the overrides in effect (names only), the
effective value of **every** switch under the name of its option (one that is ambiguous is marked
`INVALID`), the size of the hidden and excluded lists (`policy lists`) and "none" with the reason
on the `enabled books` line while a configuration error disables every book.
The same rule holds for the database session: a server that refuses the plugin's statement timeout
or read-only mode (a refused `SET`) is not used – `session_limit_refused`, `db_down
cause=session_limit` – instead of reading on with a warning (1.0.1), which on MySQL left no
statement bound at all (GB-26).

*Tests.* `Remediation2DbLayerTest::testRefusedSessionSettingsFailClosed`, `ConfigTest` (`testSwitchesAreNeverCast`, `testEveryBooleanDefaultIsCoveredByTheSwitchList`,
`testSearchOnlyIsTrueFalseOrAuto`, `testAnAmbiguousSwitchDisablesEveryBook`,
`testEnvironmentOverridesOfSwitchesAreReadStrictly`, `testEnvironmentFalseKeepsTheGlobalBookOff`,
`testVisibilityColumnMustBeAStringNamingAColumn`,
`testVisibilityColumnOfTheWrongTypeNeverSwitchesTheFilterOff`,
`testAMalformedRefusedUsersListRefusesEveryConnection`, `testNumbersAndStringsOfTheWrongTypeAreReported`,
`testAFieldListOfTheWrongTypeIsReported`, `testAFieldListFromAnEnvironmentVariableThatIsNotJsonIsReported`),
`Remediation2DocumentationTest` (`testEverySwitchHasARestrictiveValue`,
`testAnAmbiguousSwitchIsReadAsItsRestrictiveValue`, `testTheSixPermissiveDefaultsAreNotReadWhileAmbiguous`,
`testDiagShowsTheRestrictiveValueOfAnAmbiguousSwitch`),
`CliToolTest` (`testDiagFailsForAnOptionOfTheWrongType`,
`testDiagShowsEffectiveSwitchesAndEnvironmentOverrides`), E2E case 32, `Remediation2ConfigTest`
(`testAMisspeltOptionDisablesEveryBook`, `testAMisspeltEnvironmentVariableDisablesEveryBook`,
`testAKnownOptionIsNoProblem`, `testAPluginConfigFileWithoutConfigArrayDisablesEveryBook`,
`testAnUnreadablePluginConfigFileDisablesEveryBook`, `testASecondConfigFileDisablesEveryBook`,
`testThePluginOffersNoBookWhenItsConfigFileCannotBeLoaded`, `testNoAutocompleteWiringWhileEveryBookIsDisabled`,
`testNumbersMustBeWholeNumbers`, `testNumbersOutsideTheirRangeAreReported`), `Remediation2ConfigRangesTest`,
`Remediation2DiagConfigTest` (`testDiagFailsForAMisspeltOption`, `testDiagFailsWhenBothConfigFilesExist`,
`testDiagFailsForAPluginConfigFileThatDefinesNothing`, `testDiagShowsTheSizeOfThePolicyLists`,
`testDiagWarnsAboutDormantLooseningSwitches`).

*Residual risk.* A value of the right type that the administrator did not mean (a wrong but valid
domain) is not detectable; `diag` shows the effective switches and the size of the lists so that
it can be noticed.

### GB-30 Administration tooling: privileged execution, the wrong role, credentials in database logs

*Threat.* `bin/galbridge.sh` is run by an administrator, typically with `sudo`: it loads PHP from
the plugin directory and from the Roundcube tree, so whoever can write those files runs code as the
caller; a process of root that writes a file through a directory another account controls (a log
file replaced by a symbolic link) writes where that account chose. Its `grants` output is executed
by the database administrator: produced by a process of the web server user (the 1.0.1 way,
`sudo -u www-data ... grants`), it could be read or changed by any other process of that user –
`ptrace`, `/proc/<pid>/fd` – whoever owns the code; produced from code or templates another account
can change, it says what that account wants; a line a database client runs itself (psql `\!`, mysql
`system`) reaches the operating-system account of the client; the DSN line with the password, in
the SQL stream, reaches the server and its logs through a client that sends comments; a mistyped
role name changes the password and settings of another component's role; a password inside a
statement stays in the logs of the database server (PostgreSQL `log_statement` and
`pg_stat_statements`; MariaDB writes account statements verbatim to its general, binary and – when
they qualify – slow query logs). What `diag` prints comes partly from parties the
administrator does not control – a database server (or a party on an unprotected path), a row of
Roundcube's `users` table, the text of an exception or of a driver message, a file name – and a
terminal executes control sequences: hidden FAIL lines or forged OK lines would mislead the
administrator. And a `diag` that claims more than it checked – an `OK` over a check that could not
run, the environment of the shell presented as the web server's, an exit code 0 after a crash – is
a false assurance.

*Controls.* The script refuses to run as root; with `--allow-root` it still refuses unless
everything it then loads belongs to root and is not writable by group or others – the plugin and,
below the Roundcube directory, every directory and PHP file of `program/`, `plugins/` (Roundcube's
start loads every enabled plugin; the tool reads the configuration of the password plugin),
`vendor/` and every configuration directory as Roundcube finds them (`ROUNDCUBE_CONFIG_DIR`, read
like the core does, and each entry of `RCUBE_CONFIG_PATH`; one that does not exist is refused),
every directory of `include_path` that it keeps (absolute, existing entries only), symbolic links
followed, with the directories above them; a symbolic link to a path that does not exist is
refused (its target could be created later). Such a root process hands Roundcube the canonical
paths it checked (`INSTALL_PATH`, `RCMAIL_CONFIG_DIR`, `RCUBE_CONFIG_PATH`), so a link on the way
cannot be swapped between the check and the load, and works in Roundcube's verified `program/`
directory: PHP looks into the working directory last for a relative file name whatever
`include_path` says (Roundcube's autoloader asks for one), so leaving `.` out of `include_path`
alone did not keep the administrator's working directory out. The per-host files of
`include_host_config`, which `diag` loads (runs) to compare their options, are loaded by a root
process only when they lie in a checked configuration directory and belong to root alone; any other
– an absolute or `../` name leads anywhere – is a WARN that names it and is never run. It writes
only where root alone can: Roundcube's log lines go to syslog, and PHP's own messages – every `rcube_config` object, also the
ones the plugin creates, points `error_log` at `<log_dir>/errors.log` when `log_driver` is `file` –
to syslog or into a `log_dir` that root alone can write; a `log_dir` of another account (who could
put a symbolic link in place of `errors.log`) and an SQLite database of Roundcube in a directory of
another account are refused before Roundcube starts. Everything `diag` prints passes
`Terminal::clean()` (control, bidi, zero-width and separator characters and invalid UTF-8 become
visible escapes); values from a database server and exception texts are sanitised like log values
first; while `diag` runs, standard error – where Roundcube writes the text of drivers, servers and
failed queries unchanged – passes a stream filter that does the same and masks DSN passwords, and
PHP's messages are written by the tool's handler as one sanitised line; the refusals of the script
escape file names. A stored `mail_host` that is not in one of the canonical forms (a socket path
must be printable ASCII) is counted, never printed. `diag` checks what it claims: every
configuration file Roundcube loads and the directories above them, the plugin code recursively, a
check that could not run is a WARN, the environment lines say that they describe the command line
process (the web process logs its own overrides, `env_override`), `--strict` turns WARN lines into
exit code 2, and an exception or a PHP fatal error – also during Roundcube's start, where core's
own handler ended it with exit code 0 – ends with `Result: aborted` and exit code 2. The
SQL of `grants` is executed with database administrator rights, so `grants` is meant for root with
`--allow-root` (the code check above, for the plugin's files: the script, `lib/`, `SQL/`,
`localization/`, `galbridge.php`, its configuration and every directory above them). Run as any
other user it refuses, before it loads a class, when that user can read a configuration file of
Roundcube or of the plugin (the Roundcube directories it would use, `ROUNDCUBE_CONFIG_DIR`,
`RCUBE_CONFIG_PATH`, the plugin directory and the usual installation places – the web server's
identity), and refuses code that an account other than root and that user owns or that its group
or others can write (`galbridge_cli_plugin_problem()` with the allowed owners `[0, euid]`). It works
in the plugin directory with the plugin's `lib/` as include path. Its SQL holds no backslash and no
line that starts with a long-form mysql client command (`Tool::clientCommand()`, checked before
anything is printed – the templates hold none); README 2.2 has the plugin files checked with system
tools (`find`, `namei`) before a root run, the output reviewed (role line, no backslash, a `diff`
against the template) and applied with the client commands off (`mysql --binary-mode`, `psql -X`
reading standard input). Standard output carries only the SQL; the DSN line, usage errors,
refusals and template errors go to standard error, never into the redirected file.
The user of the process is taken from the posix extension or, where PHP has none, from
`/proc`; a user that cannot be determined counts as root, so a missing extension never switches
the refusal off. Arguments are strict: an option without its value (`--role acme`), an unknown
option, a repeated option, `--account-host` for PostgreSQL (it would be ignored: `pg_hba.conf`
decides) and a stray word are usage errors, so a typing mistake cannot leave the default role in
the statements. `grants` refuses the names of iRedMail's, Roundcube's and the database server's
own roles; its default output only **creates** the role and stops, changing nothing, when a role of
that name exists (PostgreSQL: one transaction); changing the password of an existing role
(`--reset-password`) and re-applying grants without touching the password (`--grants-only`) are
separate, explicit forms, named in the second line of the output. The statements carry a
**verifier** of the password (PostgreSQL SCRAM-SHA-256, MariaDB/MySQL `mysql_native_password`), the
clear text exists in the DSN line on standard error only; the PostgreSQL output also switches
statement logging off for its transaction – including the sampled logging of newer servers, the
transaction sample before `BEGIN`, where it is decided – and for the role, and
`pg_stat_statements.track_utility` for its transaction. The clear-text form for MySQL 8.4+
(`--clear-text-password`) stops on MariaDB before the statement with the password: at a MariaDB-only
`SIGNAL` comment and, for a client that drops comments, at a statement MariaDB cannot run
(`SELECT @@SESSION.information_schema_stats_expiry`). The role name, the
account host and the database are inserted in ONE pass over a statement, so a host name that
contains `vmail.` is not renamed with the database; the comments of every output describe its own
statements. Options are validated with anchored patterns; `diag` validates `--user` before
printing anything of it and never prints a password or the value of an environment override.

*Tests.* `CliToolTest` (`testTheScriptRefusesToRunAsRoot`,
`testTheScriptKnowsItsUserWithoutThePosixExtension`, `testAllowRootChecksEverythingThatIsLoadedAsRoot`,
`testDiagChecksTheConfigurationFile`, `testRolesOfOtherComponentsAreNeverTouched`,
`testInvalidGrantArgumentsAreRefused`, `testOptionsWithoutEqualsSignOrWithAnUnknownNameAreRefused`,
`testAnAccountHostThatContainsTheDatabaseNameIsNotRenamed`, `testTheCommentsOfEveryModeDescribeItsStatements`,
`testDefaultsPrintTheShippedTemplateWithAFreshPassword`,
`testGeneratedGrantsCreateAWorkingLeastPrivilegeRole` – applied to MariaDB and PostgreSQL: create,
refused second run, reset, grants-only –, `testClearTextPasswordOutputRefusesToRunOnMariaDb`,
`testDiagNeverEchoesAnInvalidUserArgument`, `testDiagRefusedDsnNeverPrintsThePassword`,
`testGrantsThroughTheScript`); `Remediation2GrantsProvenanceTest` (a non-root `grants` refuses code
another account can change and the identity of the web server, runs from clean code of a dedicated
account; root keeps checking; `diag` names every changeable file), `Remediation2GrantsOutputTest`
(the DSN line never in the SQL stream, usage errors and refusals on standard error, a repeated
option and `--account-host` for PostgreSQL refused, no client command in any output or template,
the clear-text form stops on MariaDB also without comments, `pg_stat_statements`),
`Remediation2GrantTemplatesTest` (template headers, client commands, `requiresTls()`);
`Remediation2AllowRootTest` (log_dir, SQLite database, working directory, canonical paths,
include_path, per-host files outside the configuration directories or not root's alone never run –
root only), `Remediation2DiagOutputTest` and `Remediation2TerminalTest` (terminal
neutralisation, standard error, socket paths), `Remediation2DiagTest` (configuration files,
recursive code check, release directories, process and environment lines, `env_override`,
`--strict`, the aborted ending). The tests that need root (the refusal, `--allow-root`, the root
branches of the `root-aware` classes) run as root in CI, from a root-owned copy of the checkout
(PHPUnit job, PHP 8.4 legs); the unprivileged run fails on any skipped test.

*Residual risk.* The verifier of MariaDB/MySQL is the one of `mysql_native_password` (SECURITY.md
register GB-30). Both verifiers count as secrets: together with a recorded login handshake of the
role – possible only on a link without TLS, which the plugin refuses for a remote host unless
`galbridge_db_allow_plaintext` is set (`diag` WARN), which `REQUIRE SSL` excludes for a non-local
MariaDB/MySQL account (`diag` checks it), and which on the database host needs root – the
`mysql_native_password` hash or the SCRAM verifier allows a login; a log that keeps account
statements is therefore as sensitive as the DSN (after an exposure: `grants --reset-password`). A
password that was set with the statements of 1.0.0 may be in existing database logs (SECURITY.md,
Logs). With `--allow-root`, PHP's own configuration (`php.ini`, `conf.d`,
`auto_prepend_file`, `extension_dir`) is read before the script runs and is not checked, and the
start code of every enabled Roundcube plugin runs as root (its files are checked, its data
directories are not): the web server user remains the account to run `diag` as. The self-check
of `galbridge.sh` (for `grants` as for `--allow-root`) is only as trustworthy as that file – a
changed script can skip it, hence the check with `find` / `namei` that README 2.2 puts before a
root run. PHP 8.1 ends a
run that exceeds the memory limit itself, with exit code 1 and its own message, before any handler
runs (not 0, but no `Result: aborted` line).

### GB-31 Plugin files served by the web server; development files on a mail server

*Threat.* The plugin directory lies below the web root of some setups (iRedMail's nginx aliases
`static.php` to the Roundcube directory): its source, SQL templates and anything an administrator
leaves there can be downloaded. A git clone as installation method also brings `tests/` – scripts
that drop databases and reconfigure Dovecot – and, if they were ever committed, working documents
of a security audit.

*Controls.* The directory holds no secret of the installation when the documented layout is used:
the configuration file belongs in `config/galbridge.inc.php` (README 2.3), outside the plugin
directory. A configuration in `plugins/galbridge/config.inc.php` stays supported (Roundcube reads
it, Composer's installer creates it); then `diag` without `--url` warns that the file with the
database password lies there unchecked, and `diag --url=` asks the web server for the configuration
file in use – `plugins/galbridge/config.inc.php` or `config/galbridge.inc.php` of the Roundcube
directory, each also through `static.php` – and fails when it is served as text (recognised by its
content; nothing of the answer is printed). `.htaccess` denies everything on Apache 2.4 where the
directory allows it (`AllowOverride AuthConfig` or `All`); README 2.6 gives the nginx rule. `diag` reports a git clone and files
that are not part of a release (also inside `bin/`, `lib/`, `SQL/`, `localization/` and `docs/`),
warns when the PHP user can change the plugin code (every code file and directory, recursively -
also files it owns, whatever their mode - and the directories above the plugin and Roundcube's
`plugins/`), and with
`--url=` asks the web server itself whether it serves plugin files (FAIL when it does) – under the
web path of the plugin, `plugins/galbridge/`, whatever its directory is called on disk, and after
a control request: an address that does not answer like the Roundcube webmail gets a WARN, because
"not served" at a wrong address says nothing. The
documented installation is the archive of a verified, signed tag; release archives leave out
`tests/` and tooling (`.gitattributes`), and audit documents are git-ignored. The test scripts
refuse anything but a `*test*` database and test role names, refuse a machine that looks like a
mail server and never stop a Dovecot they did not configure; they refuse to run Roundcube and
Chromium as root (and keep Chromium's sandbox), never redirect a plugin link that points elsewhere
or touch a configured Roundcube tree, stop only the web server they started, create a test database
account for every host only on request (`load.sh`, which can also drop what it created) and give
Dovecot back the service state they found (tests/e2e/setup.sh, harness.py, dovecot.sh).

*Tests.* `SourceGuardTest::testThePluginDirectoryDeniesWebAccess`, `CliToolTest`
(`testDiagChecksWhetherTheWebServerServesThePluginDirectory`, `testDiagReportsAGitCloneAndForeignFiles`,
`testDiagJudgesAReleaseDirectoryUnderAnotherName`, `testTheReleaseEntriesAreWhatGitExports`),
`Remediation2ResidualRiskTest` (`testDiagWarnsWhenTheConfigurationInThePluginDirectoryWasNotCheckedOverHttp`,
`testDiagFailsWhenTheWebServerHandsOutTheConfigurationFile`,
`testDiagAsksForTheConfigurationInTheRoundcubeConfigDirectory`,
`testTheDsnLineRecommendsTheConfigurationDirectory`),
`Remediation2ReleaseTest::testReleaseArchiveHoldsOnlyAllowListedPaths` (the allow-list of
`tests/release/check-archive.php`, which the CI job `release` applies to the tag),
`Remediation2HarnessTest` (the harness refusals, the link and server cleanup, `load.sh`).

*Residual risk.* Whether a web server hands out a file is decided by its configuration, which the
plugin cannot see: with the configuration in the plugin directory and neither a deny rule nor a
`diag --url` run, the database password depends on the web server alone (SECURITY.md register
GB-31). `diag --url` checks the usual paths from the server side, not every alias a configuration
may define.

### GB-32 Supply chain of development and release

*Threat.* A compromised or vulnerable development dependency, CI action, container image or tool
download; development code executed where releases are signed; a secret committed by mistake; a
release that is not what the maintainer tagged (a tag signed by another key, of another commit or
version, an archive with other files); a green CI run that proves less than it seems (skipped tests,
a check that cannot fail).

*Controls.* The plugin has no runtime dependency besides Roundcube itself. CI: actions pinned to
commit SHAs, every job on the pinned runner image `ubuntu-24.04`, service and container images
pinned by digest, Composer downloaded in a pinned version and checked against its SHA-256, the
Roundcube tarballs of the job `e2e` checked against their SHA-256, a read-only token and no secrets.
Every job that resolves dependencies audits them with Composer's defaults (advisories, malware lists,
abandoned packages, unreachable advisory sources fail it; the pinned 1.7.0 core of the compatibility
legs is the only exception), records the resolved set with source commits and keeps its
`composer.lock` as an artifact; only the blocking of releases with advisories is switched off, only
for `composer install` in the PHPUnit job. Lower bounds of PHPUnit exclude the releases with known
advisories. The release verification runs in a disposable container from the CI lock of the release
commit, as an unprivileged user; signing, `verify-tag` and the push happen in a clean clone where
Composer and PHPUnit never run. A secret scan of the history and the tree (gitleaks, verified by its
checksum) with controls that prove it reports a planted key, a DSN with a password (a rule of its own)
and a new file of the fixture directory; the exceptions are one named fixture file, exact values and
the git-ignored local directories, which a step before the history scan proves no commit ever had a
file in (gitleaks applies a path exception to the history as well). PHPUnit fails on any skipped
test; the root-only tests run as root in CI; the suite also runs against pinned pairs of the
supported database servers (job `databases`); the end-to-end and browser suites run in CI (job
`e2e`, as an unprivileged user whose fixture access is the rows of the test database).
`SourceGuardTest` is a lexical regression check: it reports direct uses of the listed functions,
operators and superglobals, and (`Remediation2SourceGuardTest`) string literals naming those
functions, in every PHP file of a release; indirect forms (callables built at run time, variable
calls) are not proven absent – review and PHPStan cover them. Releases are annotated tags signed
with an SSH key listed in `.github/release-signers`; CI refuses a tag that a key of that file on
`main` did not sign, that GitHub does not verify, whose tag object was made for another name, whose
commit is not part of `main` or whose version differs from the code and the changelog, and checks
its archive against an allow-list of full paths; the maintainer runs the same scripts before the
push (docs/RELEASING.md).

*Tests.* `SourceGuardTest`, `Remediation2SourceGuardTest`; `Remediation2CiTest` (the workflow
itself: policies, pins and one digest per image tag, audit, record and artifact of every resolved
set, `--fail-on-skipped`, the root step, the jobs `databases` and `e2e` with its scoped fixture
access, the history check of the directories the scanner skips; `Remediation2HarnessTest` proves
the PostgreSQL grants of `load.sh`); `Remediation2ReleaseTest`
(`verify-tag.sh` against throw-away keys: another key, a renamed or unsigned tag, another commit,
main; the archive allow-list; the published fingerprint and reporting channel); `Remediation2RepositoryHygieneTest` (scanner exceptions, the DSN
rule, `.gitignore`); the CI jobs `security`, `databases`, `e2e` and `release`.

*Residual risk.* No committed lockfile (SECURITY.md register GB-32): two CI runs can test different
dependency versions; development dependencies execute in CI and in the disposable verification
container, never on the machine that signs. The workflow runs from the tagged commit, so a writer can
change the workflow and the key file together: the job catches mistakes, and the independent check
is the administrator's comparison of the key fingerprint (README 2.1, SECURITY.md "Release signing
key"). Composer does not verify tag signatures (README 2.1 shows the comparison of the installed
commit). Branch and tag rulesets exist only once the repository is public (docs/RELEASING.md
section 0).

## What GAL Bridge does NOT protect against

* A compromised web server, PHP process, Roundcube installation or administrator: whoever runs code
  as the PHP user can read the role's password and query everything the role may read.
* A compromised session store (it can change the cached Viewer or simply take over a session).
* Disclosure through the directory itself to legitimate viewers: everything a book shows is, by
  design, visible to every user entitled to that book. Choose books, fields and lists accordingly.
* The content of database server logs on MariaDB/MySQL (search terms in the general and slow query
  logs), Roundcube's errors log (connection messages) and web server access logs, browser histories
  and proxy logs (contact and group IDs encode addresses, department names and domains, searches
  are GET requests); see SECURITY.md for the mitigations.
* Personal data in the plugin log while `galbridge_debug` is on (driver and exception texts are not
  filtered; `diag` warns).
* A statement audit trail of the PostgreSQL role: its statements are kept out of the server log
  for the privacy of the search terms (an audit extension enabled for the role records them).
* Request floods beyond the result caps and timeouts, without a reverse-proxy limit – also the log
  lines they cause (one per reason code per request).
* An IMAP server that the administrator lists as a directory server although it authenticates
  against another user database (also through an entry without a port, which covers every IMAP
  port of its host; `diag` warns).
* A web server that hands out the configuration file in the plugin directory as text (`diag --url`
  fails then; `config/galbridge.inc.php` is the recommended place).
* A changed column grant, a changed password or a disabled IMAP service within
  `galbridge_scope_ttl` (deactivating the mailbox applies with the next request).
* Engine-specific accent folding (MariaDB folds, PostgreSQL does not).
* Users of the OpenLDAP edition of iRedMail (not supported).
