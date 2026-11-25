# GAL Bridge – Threat model

This document lists what GAL Bridge protects, against whom, and how. The threats GB-01 to GB-28 come
from the security review of the design (before implementation) and were re-checked against the
1.0.0 code; GB-29 to GB-32 and the changes to the other entries come from the security audit of
1.0.0, whose findings were remediated in 1.0.1 (identifiers U-xx / I-xx in
[CHANGELOG.md](../CHANGELOG.md)). Each entry names the implemented controls (classes under
`lib/GalBridge/`) and the tests that exercise them (`tests/Integration/*Test`, `tests/Unit/*Test`,
`tests/e2e/`, `tests/ui/`). Where the implementation differs from the original proposal of the
review, the entry describes the implementation. Section references ("ARCHITECTURE 11") point to
[ARCHITECTURE.md](ARCHITECTURE.md).

## Assets

| Asset | Where |
|---|---|
| Directory data of every tenant: names, addresses, departments, phone numbers, birthdays, alias accounts, list addresses | iRedMail `vmail` database (`mailbox`, `domain`, `alias`, `maillists`, `forwardings`, `moderators`, `maillist_owners`) |
| Secrets in the same database: password hashes, recovery e-mail addresses, `allow_nets`, per-user settings, private forwarding targets | `vmail.mailbox`, `vmail.forwardings`. Not readable by the plugin's role: its grants are column-level, and of `forwardings` it gets a view with the alias and list rows only (`galbridge_forwardings`), never the table with the forwarding targets. A role that can read a secret column or that table is refused |
| Organisational structure: who is in which department, which tenants a provider hosts, who may post to which list | derived from the same tables |
| Credentials of the plugin's database role | `plugins/galbridge/config.inc.php` or `config/galbridge.inc.php` |
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
  (`include_host_config`), is not trusted.
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
cannot be triggered. composer requires `php >=8.1 <8.6`; PHPStan level 8 analyses the code against
a Roundcube 1.7.x tree. The whole 1.7 branch is supported, so that an installation never has to
hold back a security release of the core for the sake of the plugin.

*Tests.* CI runs the whole suite on PHP 8.1 – 8.5 against the newest Roundcube 1.7.x on the day of
the run, and on PHP 8.1 and 8.4 against the oldest release (1.7.0), with `failOnWarning`,
`failOnNotice` and `failOnDeprecation`; `CliToolTest::testDiagAcceptsEveryReleaseOfTheSupportedBranch`; `ContractTest` (the `rcube_addressbook` contract,
`testDeniedBookContract`), `CorePathsTest::testEveryOwnSourceIdResolvesToAnInstance`,
`PluginHooksTest::testAddressbookGetAlwaysAnswersOurIds`, `OldPluginRegressionTest`
(`testDefect01BackendDeclarationsAreCompatibleWithRoundcube17`,
`testDefect10NoDeprecationsAndNoDynamicProperties`).

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

*Threat.* Show, print, QR code, photo and copy call `get_record()` with request IDs; the old plugin
looked IDs up globally, returned the previous result on a miss, and derived IDs from the creation
time and the password length.

*Controls.* Contact IDs are `<kind>_<enc>` (`u` person, `a` alias account, `l` list), charset
`[A-Za-z0-9_]`, a deterministic encoding of the normalised address that carries no secret
(`IdCodec`). Decoding is strict and canonical, never throws, accepts only strings and a plausible
lower-case address. Every lookup runs through the book's root predicate; a miss and an out-of-scope
ID give the same answer (null, previous result cleared). The array form of `_cid` is handled by the
same decoder.

*Tests.* `IsolationTest::testForeignAndInvisibleIdsResolveToNothing`, `ContractTest::testGetRecord`,
`CorePathsTest::testShowPrintQrcodeAndPhotoByContactId`, `IdCodecTest`
(`testDecodeNeverThrowsOnRandomInput`, `testDecodeRejectsNonCanonicalEncodings`),
`OldPluginRegressionTest::testDefect04GetRecordIsScopedAndNeverReturnsThePreviousRecord`.

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

*Controls.* `set_page()` ≥ 1; the page size is capped at max(core `max_pagesize`,
`addressbook_pagesize`), clamped to 10–1000 (`Limits::corePageCap`), so the page size Roundcube's own
lists use is never mistaken for a full-set request. A larger size on page 1 is a full-set request
handled explicitly (ARCHITECTURE 11): the set is read by **one statement up to the limit of the
operation plus one row** (`Repository::fullSet`) and is returned completely or refused – a group
above the expansion cap, a whole book above `galbridge_max_export` (and only when the book's export
policy allows it), an ID search above `galbridge_max_ids`. The bound of counting
(`galbridge_max_count`) never takes part in that decision, whatever the relation between the
limits. A set of which a fetched row could not be delivered is refused as well (`set_incomplete`).
A group ID is honoured only for a group of the book's own aggregate: a book that shows no groups
selects nothing by one. A search *list* is capped at `galbridge_max_results` /
`galbridge_global_max_results` with a notice; in an export the same result is complete or refused.
A refused export – also one that fails because of a database error – downloads nothing (`204 No
Content` from the `addressbook_export` hook) instead of an empty or shortened file.
`count()` is a `COUNT` over the same root predicate inside a `LIMIT` subquery bounded by
`galbridge_max_count`; list offsets beyond it return an empty page. All membership predicates are
semi-joins (`EXISTS` / `IN`), so rows are unique by primary key and totals and paging are exact; the
order is stable (sort key, address, kind).

*Tests.* `ContractTest` (`testListRecordsPagingCountAndFirst`, `testPageAndPageSizeAreClamped`,
`testCountHonoursScopeFilterGroupAndPage`), `CorePathsTest`
(`testFullSetSearchListsAreCappedWithANotice`, `testGroupExpandReturnsAllMembersOrRefuses`,
`testMailtoGroupWithCoreMaxGroupMembersIsCompleteOrRefused`, `testExportPaths`),
`AuditRegressionTest` (`testExportsAndRecipientSetsDoNotDependOnTheCountBound`,
`testAFullSetThatLacksARowIsRefused`), `PluginHooksTest` (`testRefusedExportAnswersNoContent`,
`testAFailingDirectoryRefusesTheExport`), E2E case 33,
`OldPluginRegressionTest::testDefect06CountIsTheRealTotalAndListsArePaged`.

*Residual risk.* An export is a file download: when it is refused, no message can be shown with
it (README 4 and 10).

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

*Tests.* `SqlTest` (`testCompositionCannotChangePrecedence`, `testValuesAreAlwaysBoundNeverInlined`,
`testEscapedLikePatternMatchesOnlyTheLiteral`, the unsafe-expression cases),
`ContractTest::testLikeWildcardsInInputAreLiterals`, `ContractTest::testQuickSearchFieldsWordsAndModes`,
all integration tests on MariaDB 11.8, PostgreSQL 17 and SQLite.

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
without a query. Per-user aliases are matched only with `galbridge_user_aliases`. Secret columns are
not granted, and a role that can see them is refused (`role_too_broad`), so even a coding mistake
fails with a permission error instead of leaking.

*Tests.* `ContractTest::testAdvancedSearchIsAndAndRejectsUnsupportedFields`,
`ContractTest::testRequiredFields`, `ConfigTest::testMinimalFieldAllowlistLimitsRecordsColtypesAndSearch`,
`DsnPolicyTest::testRoleThatCanReadSecretColumnsDisablesEveryBook`, `LeakageTest`
(`testSearchingForACanaryFindsNothing`, `testUserAliasesOffAreNeitherShownNorSearchable`).

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
(`include_host_config`), lead to the same confusion.

*Controls.* IMAP-server binding (`ViewerResolver::allowedImapHosts`): the allowed servers are
`galbridge_imap_hosts` – entries `host`, `host:port` or a local socket `unix:///path` – or, only
when that list is empty, host and port of the administrator's `imap_host` (read from a fresh
configuration, never a user preference) when it is a single string without `%`; `parse_host()` is
never called. With `include_host_config` - or a `ROUNDCUBE_INCLUDE_HOST_CONFIG` variable, which
Roundcube itself ignores for that option - nothing is derived: the list must be explicit. Both the
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
non-backup-MX domain; with `galbridge_hide_expired` neither of them may be expired) and must be
the domain of the login itself – a row where the two differ opens no directory; no row means no
books.

*Tests.* `ConfigTest` (`testAllowedImapHostIsDerivedOnlyFromAFixedAdministratorHost`,
`testUserSelectableImapHostWithoutExplicitListDisablesThePlugin`,
`testSessionAndStoredImapHostMustBothMatch`, `testExplicitImapHostsMayNameAPort`,
`testTheImapPortOfTheSessionIsPartOfTheBinding`, `testCachedViewerIsBoundToTheImapPort`,
`testHostDependentConfigurationNeedsAnExplicitHostList`, `testALocalSocketIsAnEndpointOfItsOwn`,
`testASessionOnALocalSocketIsBoundToThatSocket`, `testIncludeHostConfigInTheEnvironmentNeedsAnExplicitHostList`,
`testTheSessionHostIsComparedInTheFormRoundcubeWroteIt`, `testCanonicalHostAcceptsOnlyWhatALoginWrites`,
`testABareIpv6EntryIsNoPrefixOfAHost`),
`IsolationTest::testLoginsWithoutAVisibleMailboxGetNoBooks`,
`IsolationTest::testCachedViewerIsBoundToTheImapHost`,
`AuditRegressionTest::testADisabledMailboxLosesTheDirectoryWithItsNextRequest` (the row whose
domain column was changed), `CliToolTest` (`testDiagWarnsAboutHostDependentConfiguration`,
`testDiagAcceptsAnImapServerOnALocalSocket`), E2E case 32.

*Residual risk.* A login under an alias domain gets no books (the login must equal
`mailbox.username`). The plugin compares host and port but cannot verify that a listed server
authenticates against this vmail database (assumption above; SECURITY.md register GB-11).

### GB-12 Stale entitlements

*Threat.* A disabled or moved account, or a tightened configuration, keeps directory access for the
life of the session – or for the life of a cache entry.

*Controls.* The resolved Viewer is cached in `$_SESSION['galbridge']`, bound to the user id, login,
IMAP server and the configuration fingerprint (an HMAC, keyed with Roundcube's `des_key`, over
every effective `galbridge_*` value, `imap_host` and `include_host_config`), for
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
`AuditRegressionTest` (`testADisabledMailboxLosesTheDirectoryWithItsNextRequest`,
`testTheConfirmationRunsOncePerReadingRequestOnly`,
`testAViewerWithoutAddressesMatchesNoRestrictedList`), E2E case 34 (through Roundcube's own session
handler).

*Residual risk.* A new or removed own alias of the viewer (it can make a restricted list visible,
or keep it visible after the alias was taken away) and a changed column grant apply after the TTL;
only the mailbox itself is confirmed on every reading request.

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
`(domain) Name`.
The domain book itself can be restricted per viewer domain (`galbridge_domain_viewer_domains`,
`..._exclude`).

*Tests.* `EntitlementsTest` (`testGlobalBookViewersAndDataDomains`, `testWildcardDataDomainsNeedTheSecondOptIn`,
`testIncludeHomeControlsDuplicatesOfTheDomainBook`, `testMalformedGlobalDataExcludeDisablesTheGlobalBook`,
`testDomainBookViewerExcludeList`), `IsolationTest::testDomainBookListsExactlyTheHomeTenant`,
`IsolationTest::testOtherTenantsAreNeverFound`, `ConfigTest::testInjectedPreferencesCannotWidenTheDirectory`.

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
This bounds what the role may READ; it is no barrier against inference by someone who holds the
role's credentials: conditions of their own queries are evaluated on the rows behind the view
(MariaDB/MySQL merge the view into the query; PostgreSQL's `security_barrier` lets leakproof
operators and `EXPLAIN ANALYZE` counts through), which reveals single facts about those rows. The
credentials therefore stay a secret (GB-23), and `diag` checks that the view holds no other
rows; the PostgreSQL grant set makes the administrator who runs it the owner of the view, so a
view of that name created earlier by another role is not taken over with its owner.
Catch-all rows and external list members are never selected. Per-user aliases are shown only with
`galbridge_user_aliases`, and then **shown and matched under one predicate**
(`QueryBuilder::userAliasRoot`): the alias row belongs to a domain in the book's scope that is
active, not a backup MX and (with `galbridge_hide_expired`) not expired, and the alias is on
neither hidden list. A hidden or out-of-scope alias is therefore not shown and cannot be used to
find its owner. The aliases shown per person (20) and the viewer's own aliases used for the list
policies (200, of deliverable domains only) are bounded. With `galbridge_hide_expired`, alias
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
`testRoleThatCanReadTheForwardingsTableIsRefused`), E2E case 31.

### GB-15 Cache poisoning across users or tenants

*Threat.* A shared cache keyed by search term or book would hand tenant A's results to tenant B;
packed caches use `unserialize()`.

*Controls.* By construction: GAL Bridge uses no Roundcube cache (`get_cache`, `get_cache_shared`),
no APCu and no `unserialize()`; results are memoised only inside one request (groups and counts in
`Repository`). The only cross-request state lives in the user's own session: the Viewer
(`$_SESSION['galbridge']`, bound to the user id, GB-12), the 30-second outage memo
(`galbridge_down_until`) and the `'auto'` search-only decision per book (`galbridge_searchonly`,
bound to the book id and the configuration fingerprint; a boolean and a timestamp).

*Tests.* `IsolationTest::testViewerIsCachedInTheSessionAndBoundToItsContext`; the absence of cache
APIs is a property of the source.

### GB-16 Administrator options overridden by user preferences

*Threat.* Roundcube merges stored user preferences over the configuration, so a `galbridge_*`
preference (saved by a plugin or written into the database) could widen the directory.

*Controls.* `Config::get()` ignores every preference named `galbridge_*` and returns the
administrator's value from a fresh `rcube_config` (main configuration plus the plugin
configuration) or the built-in default. An option that no configuration file sets is read through
that fresh configuration as well, so a `ROUNDCUBE_<OPTION>` environment variable – the only place
such an option may be set in a container deployment – applies exactly as it does for a user without
the preference (`rcube_config::all()` applies those variables to the options of the files only). Core options the plugin needs from the administrator
(`imap_host`, `db_dsnw`, `password_db_dsn`) are read the same way. SECURITY.md recommends listing
the security-relevant options in `dont_override` as well.

*Tests.* `ConfigTest` (`testUserPreferencesNeverOverrideAdministratorOptions`,
`testInjectedPreferencesCannotWidenTheDirectory`, `testGuardReadsAFreshConfigurationWhenAdminValuesAreNotGiven`,
`testThePreferenceGuardReadsOptionsSetThroughTheEnvironmentAlone`),
`PluginHooksTest::testStoredGalbridgePreferencesAreIgnoredByThePlugin`.

### GB-17 Core options a user controls are used unsafely

*Threat.* Users control `addressbook_pagesize`, `addressbook_sort_col`, `addressbook_search_mode`,
`contactlist_fields`, `autocomplete_addressbooks` and the `default_addressbook` /
`collected_*` source preferences.

*Controls.* Page sizes are capped (GB-06), the sort column is whitelisted, search mode is masked to
`& 3` (and forced to prefix in the global book), field lists go through `FieldMap`. A GAL Bridge ID
in a source preference goes through `addressbook_get` entitlement (GB-02). The books are read-only:
core's `get_address_book($id, true)` returns null for them, and the inherited mutators of
`rcube_addressbook` return false, so `collected_recipients` pointing at a GAL Bridge book writes
nothing.

*Tests.* `ConfigTest::testUserControlledCoreOptionsAreClampedOrWhitelisted`,
`ContractTest::testSortColumnsWithEmptyFieldFallbacks`, `CorePathsTest::testWritePathsAreRefused`.

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
at the at-sign like the names, and so are the labels of department groups. An entry without any name gets the local part of its
address as the name; an address may hold `&`, `#`, `=` and `?`, so that fallback is treated like
every other text. Addresses are canonical lower-case ASCII (A-labels, no homographs) and must pass
`rcube_utils::check_email()`; rows that fail are dropped (`row_invalid`, or `row_out_of_scope` for
a row outside the book's scope). In the global book, entries outside the viewer's home domain(s)
are shown as `(domain) Name` with the ASCII domain – the marker first, because a recipient field
shortens a long name at its end – and carry no first or last name, so every listing mode shows the
marker. The marker is part of the name, so nothing of such an entry may stand before the name:
under a `contactlist_name_template` that shows another field of the entry before `{name}`, or has
no `{name}`, foreign entries do not carry that field (department, telephone numbers;
`RecordMapper::textBeforeName`), and `diag` names it.

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
`compose_search_name()` for six templates), `NormalizerTest`
(`testCurlyBracketsCannotFormAPlaceholderOfTheNameTemplate`, `testCutAtSignKeepsWhatStandsBeforeIt`), `CliToolTest`
(`testDiagTellsWhichFieldsTheNameTemplateTakesFromForeignEntries`,
`testDiagTellsThatMarkedEntriesAreSuggestedFirst`), E2E case 35, browser case U8 (the recipient
widget and the suggestion list of the Elastic skin in Chromium).

*Residual risk.* Roundcube sorts the autocomplete suggestions of all address books by name (ICU
collation) and pre-selects the first one; a name that starts with the marker sorts before letters
and digits, so entries of other domains are suggested **before** unmarked names (SECURITY.md
register GB-19; `galbridge_global_autocomplete = false` keeps the global book out of
autocomplete). Texts are treated field by field; what a template joins is covered by the rules
for brackets, placeholders and the edges of a text above, not by a check of the composed name.

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

*Tests.* `DsnPolicyTest::testPasswordNeverAppearsInErrorsOrDescriptions`,
`DsnPolicyTest::testRefusedDsnFailsClosedWithoutLoggingThePassword`,
`LeakageTest::testDebugLogNeverContainsSecrets`.

*Residual risk.* Connection failures are logged by Roundcube core with the PDO driver message (host
and user name; in the CLI only on stderr, and `diag` prints it). Database server logs contain the
literal search terms, because `rcube_db` inlines bound values on the client side (SECURITY.md).

### GB-22 Log injection and personal data in plugin logs

*Threat.* Request values and directory strings may contain CR/LF, bidi characters or huge values;
logging them forges lines and stores personal data in shared logs.

*Controls.* `Log::sanitize()` replaces control, bidi and zero-width characters, redacts invalid
UTF-8, truncates values to 300 characters and masks DSN passwords. Normal levels carry reason codes,
the numeric user id, book ids, counts and SQLSTATEs only; rejected group IDs are logged as a
12-character hash (`Log::tag`). Reason codes triggered by request input or directory rows are
logged at most once per request (`Log::once`, keyed by operation and code; the malformed-row WARN
`row_invalid` and the scope tripwire ERROR `row_out_of_scope` have separate codes, so one cannot
hide the other), so a crafted request loop cannot flood the mail log. `galbridge_debug` adds only
driver error texts, exception messages and schema hints.

*Tests.* `LogTest` (`testLogInjectionStaysOneLine`, `testBidiSpoofingIsNeutralised`,
`testOnceCannotBeFlooded`, `testDsnPasswordIsRedacted`, `testExceptionDetailsRequireDebugLogging`),
`LeakageTest::testNormalLogLinesNeverContainSearchTerms`.

### GB-23 Over-privileged or exposed database credentials

*Threat.* With iRedMail's `vmail` role (SELECT on every column) or `roundcube` role (UPDATE on
`vmail.mailbox`) any plugin bug could read password hashes or modify vmail; the old plugin's
PostgreSQL view embedded the `vmail` password in a `dblink` connection string. A role that can read
the whole `forwardings` table can read where every mailbox forwards its mail.

*Controls.* A mandatory, separate `galbridge_db_dsn`. `Dsn` refuses the roles in
`galbridge_db_refused_users` (iRedMail's roles, `root`, `postgres`; a value that is not a list
leaves the built-in list in force), the `db_dsnw` user and the `password_db_dsn` user.
`ViewerResolver` probes `information_schema.columns` and denies every book when `mailbox.password`,
`recovery_email` or `allow_nets` is visible, or when the role can read the `forwardings` table
(`role_too_broad`). The grant templates create a new role with column-level `SELECT` only and the
view `galbridge_forwardings` (MariaDB/MySQL `SQL SECURITY DEFINER`, PostgreSQL `security_barrier`;
alias, alias-member and list rows) instead of a grant on the table; on both engines the account is
limited (connections, statement time; PostgreSQL also `NOINHERIT` and read-only transactions); the
personal-data columns (`telephone`, `mobile`, `birthday`) are a commented-out grant. The view is
the only object GAL Bridge adds to `vmail`; no `dblink`, no stored credentials. `diag` accepts the
password-column check only when the server refuses the statement for lack of privilege, checks that
the `forwardings` table is not readable, and reads the role's write privileges from the catalogs
(PostgreSQL `has_table_privilege` / `has_any_column_privilege`, MariaDB/MySQL `information_schema`
privileges) – and, on MariaDB/MySQL, also asks the server with statements that can change nothing,
which finds privileges granted through a database role as well.

*Tests.* `DsnPolicyTest` (`testIredmailAndSuperuserRolesAreRefused`,
`testRoundcubeAndPasswordPluginUsersAreRefused`, `testRoleThatCanReadSecretColumnsDisablesEveryBook`,
`testRoleWithoutARequiredColumnFailsClosed`, `testTheTemplateRoleCannotReadPrivateForwardingTargets`,
`testRoleThatCanReadTheForwardingsTableIsRefused`), `ConfigTest::testRefusedUsersOfTheWrongTypeKeepTheBuiltInList`,
`OldPluginRegressionTest::testDefect11PortableSqlLeastPrivilegeAndStableIds`, `CliToolTest`
(`testGeneratedGrantsCreateAWorkingLeastPrivilegeRole`, `testGrantsOnlyMovesARoleOfTheOldGrantsToTheView`,
`testDiagFailsForAWriteCapableRole`, `testDiagFindsWritePrivilegesGrantedThroughARole`); CI and the
local integration databases connect with a role created from the shipped templates
(`tests/fixtures/vmail/load.sh`, with the commented-out personal-data grant enabled).

*Residual risk.* The password is in the plugin configuration file; protect it with `0640
root:<PHP group>` and the web-server deny rule (README 2.3, 2.6). The credentials and how they are
created: GB-30.

### GB-24 Eavesdropping on or tampering with traffic to a remote database

*Threat.* The role's password and the directory cross a network; a DSN that is checked in one
reading and used in another, or a client library that takes its target from the environment,
connects somewhere else or without the protection the check assumed.

*Controls.* `Dsn` judges the DSN in the form the driver receives it: an explicit host or unix
socket, a port of digits, a plain database name, and only parameters of a per-driver allow-list,
each once and matching its pattern; the PostgreSQL `service` parameter and the client-library
variables that redirect a connection (`PGHOSTADDR`, `PGSERVICE`, `PGSERVICEFILE`; `PGPORT` for a DSN
without a port) are refused. A non-local host (anything but a unix socket, `127.0.0.0/8`, `::1`,
`localhost`) is accepted only with TLS that verifies the **host name**: PostgreSQL
`sslmode=verify-full`, MySQL `ca` or `capath` with `verify_server_cert=1`. `sslmode=verify-ca`
(issuer only) needs `galbridge_db_allow_verify_ca`, anything weaker
`galbridge_db_allow_plaintext`; both are logged as `dsn_warning` and shown by `diag` (WARN), which
also warns when `pdo_mysql` is not built on `mysqlnd`.

*Tests.* `DsnPolicyTest` (`testRemoteHostWithoutVerifiedTlsIsRefused`,
`testRemoteHostDisguisedAsLocalSocketIsRefused`, `testRemoteHostWithVerifiedTlsIsAccepted`,
`testVerifyCaIsNotVerifiedTls`, `testDsnIsJudgedInTheFormTheDriverReceives`,
`testOrdinaryDsnsAreStillAccepted`, `testLibpqEnvironmentThatRedirectsTheConnectionIsRefused`,
`testPlaintextRemoteNeedsExplicitOptInAndWarns`, `testPostgresServiceParameterIsRefused`,
`testLoopbackDetection`).

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
injected into the DSN (`galbridge_db_connect_timeout`, 2 s); a statement timeout and read-only
session per connection (`galbridge_db_timeout`, 3 s) as a backstop to the limits of the account; a
failed connection memoised for the request and for 30 s in the session; **limits of the account on
both engines**, set by the grant templates and sized with `grants --connection-limit` (PostgreSQL
`CONNECTION LIMIT` and `statement_timeout`; MariaDB/MySQL `MAX_USER_CONNECTIONS` and, on MariaDB,
`MAX_STATEMENT_TIME`), which `diag` reports (WARN when the account is unlimited or when the server
refused a session limit); bounded alias sets (GB-14).

*Tests.* `CorePathsTest::testSenderPhotoOfEveryOpenedMessageNeverQueries`,
`PluginHooksTest::testInitRegistersTwoHooksAndNeverTouchesTheDatabase`,
`ContractTest::testEmptyTooShortAndTooLongInputNeverQueries`,
`IsolationTest::testOutageIsMemoisedAndNeverCachedAsNoEntitlement`,
`DsnPolicyTest::testConnectTimeoutIsInjectedWhenMissing`, `DsnPolicyTest::testInjectedConnectTimeoutIsClamped`,
`CliToolTest` (`testConnectionLimitIsAppliedForBothEngines`, `testDiagReportsTheConnectionLimitOfTheAccount`,
`testGeneratedGrantsCreateAWorkingLeastPrivilegeRole`), `AuditRegressionTest::testAliasSetsAreBounded`.

*Residual risk.* The plugin does not slow down a user who sends many requests: request volume is
limited by the reverse proxy and the connection limit of the account (SECURITY.md, checklist and
register GB-26).

### GB-27 Scope or deny-list bypass through case, IDN or collation differences

*Threat.* MariaDB's `utf8mb4_general_ci` compares case-insensitively with PAD SPACE, PostgreSQL
`varchar` case-sensitively; a deny-list entry in mixed case or as a U-label would hide a tenant on
one engine and expose it on the other.

*Controls.* One `Normalizer` for configuration lists, the login, decoded IDs and the PHP row re-check
(trim, NFC, lower case, split at the last `@`, IDNA to ASCII – failing without `intl` for non-ASCII
input – trailing dot removed, validated). An invalid allow-list entry is dropped; an invalid
deny-list disables what it protects. SQL binds normalised values; the PHP re-check compares
normalised row domains with the scope, which absorbs collation variants. The deny-lists - hidden
addresses and excluded domains - compare `LOWER()` of the column in SQL on every engine, so a row
written with capitals is neither counted nor matched by a search on PostgreSQL either (the PHP
re-check alone would drop it only after the count); allow-lists compare the plain column (a row in
another spelling is left out - fail closed). Department groups use `LOWER(TRIM())` on both engines.
`diag` reports mailboxes with upper-case letters.

*Tests.* `NormalizerTest`, `NormalizerWithoutIntlTest::testAsciiWorksAndIdnFailsClosedWithoutIntl`,
`IsolationTest::testIdnTenantIsIsolated`, `EntitlementsTest` (`testMalformedViewerDenyListDisablesTheBook`,
`testMalformedHiddenListDisablesEveryBook`, `testOverlappingOrganizationsFallBackToTheOwnDomain`),
`AuditRegressionTest::testHiddenListsAlsoMatchRowsWrittenWithCapitals`.

*Residual risk.* Accent folding still differs (README 10). A domain column that holds a U-label
(iRedMail stores the ASCII form) is not excluded in SQL; the PHP re-check drops such rows.

### GB-28 Bulk export or printing, and processing before authentication

*Threat.* `contacts/export.php` exports a whole book with page size 99999; plugin `init()` runs
before Roundcube's CSRF and authentication checks.

*Controls.* A whole-book export is allowed only when the book's export option is on (domain book
`true`, global book `false`) and only up to `galbridge_max_export`; a search-only book exports
nothing without a search. Every export is complete or refused (GB-06), and a refused export
downloads nothing (`204 No Content`). Exports contain the effective fields only. `init()` returns without an authenticated user, never touches the
database, registers no actions and reads only core's resolved action name (it adds the
`addressbook_export` hook for `addressbook/export` only); the plugin is limited to the `mail` and
`addressbook` tasks.

*Tests.* `CorePathsTest::testExportPaths`, `PluginHooksTest` (`testAnonymousRequestsRegisterNothing`,
`testInitRegistersTwoHooksAndNeverTouchesTheDatabase`, `testAutocompleteIsWiredOnlyForComposeAndAutocomplete`).

### GB-29 Configuration values that fail open

*Threat.* PHP casts: the string `'false'` is true, an array where a column name is expected becomes
"no column". Roundcube's environment overrides (`ROUNDCUBE_<OPTION>`) turn the text `false` into
the boolean true before a plugin sees it. A slip of the administrator then switches a restriction
off, or a feature on, without any sign.

*Controls.* `Config` never casts. A switch is a boolean or the integer 1 / 0 (the `searchonly`
options also `'auto'`); `galbridge_visibility_column` is a string; the hidden lists, deny-lists and
`galbridge_db_refused_users` are lists. Anything else is a reported problem – and where the option
protects something, **every book is disabled** (`Config::booksDisabled`, enforced by `Entitlements`
and again by `QueryBuilder::forBook`). For its switches the plugin reads the environment variable
itself and accepts only `1`, `0`, `true`, `false` (`auto`). `galbridge_fields` that is not a list
shows name and e-mail only – fewer fields, never more – and is reported like the others. `diag`
prints every problem, the overrides in effect (names only) and the effective value of **every**
switch under the name of its option (one that is ambiguous is marked `INVALID`).

*Tests.* `ConfigTest` (`testSwitchesAreNeverCast`, `testEveryBooleanDefaultIsCoveredByTheSwitchList`,
`testSearchOnlyIsTrueFalseOrAuto`, `testAnAmbiguousSwitchDisablesEveryBook`,
`testEnvironmentOverridesOfSwitchesAreReadStrictly`, `testEnvironmentFalseKeepsTheGlobalBookOff`,
`testVisibilityColumnMustBeAStringNamingAColumn`,
`testVisibilityColumnOfTheWrongTypeNeverSwitchesTheFilterOff`,
`testRefusedUsersOfTheWrongTypeKeepTheBuiltInList`, `testNumbersAndStringsOfTheWrongTypeAreReported`,
`testAFieldListOfTheWrongTypeIsReported`, `testAFieldListFromAnEnvironmentVariableThatIsNotJsonIsReported`),
`CliToolTest` (`testDiagFailsForAnOptionOfTheWrongType`,
`testDiagShowsEffectiveSwitchesAndEnvironmentOverrides`), E2E case 32.

### GB-30 Administration tooling: privileged execution, the wrong role, credentials in database logs

*Threat.* `bin/galbridge.sh` is run by an administrator, typically with `sudo`: it loads PHP from
the plugin directory and from the Roundcube tree, so whoever can write those files runs code as the
caller. Its `grants` output is executed by the database administrator: a mistyped role name changes
the password and settings of another component's role; a password inside a statement stays in the
logs of the database server (PostgreSQL `log_statement`; MariaDB writes account statements verbatim
to its general, binary and – when they qualify – slow query logs).

*Controls.* The script refuses to run as root; with `--allow-root` it still refuses unless
everything it then loads belongs to root and is not writable by group or others – the plugin and,
below the Roundcube directory, every directory and PHP file of `program/`, `plugins/` (Roundcube's
start loads every enabled plugin; the tool reads the configuration of the password plugin),
`vendor/` and every configuration directory as Roundcube finds them (`ROUNDCUBE_CONFIG_DIR`, read
like the core does, and each entry of `RCUBE_CONFIG_PATH`; one that does not exist is refused),
symbolic links followed, with the directories above them; a symbolic link to a path that does not
exist is refused (its target could be created later). Such a root process keeps only absolute
`include_path` entries (never the working directory) and logs to syslog: `log_dir` belongs to the
web server user, who could otherwise point a log file at any file root would then write to. The
SQL of `grants` is executed with database administrator rights, so README 2.2 has it generated
only from code the web server cannot change (root-owned plugin files, or the verified release
archive, which `grants` needs nothing else of) and read before it is applied.
The user of the process is taken from the posix extension or, where PHP has none, from
`/proc`; a user that cannot be determined counts as root, so a missing extension never switches
the refusal off. Arguments are strict: an option without its value (`--role acme`), an unknown
option and a stray word are usage errors, so a typing mistake cannot leave the default role in the
statements. `grants` refuses the names of iRedMail's, Roundcube's and the database server's
own roles; its default output only **creates** the role and stops, changing nothing, when a role of
that name exists (PostgreSQL: one transaction); changing the password of an existing role
(`--reset-password`) and re-applying grants without touching the password (`--grants-only`) are
separate, explicit forms, named in the second line of the output. The statements carry a
**verifier** of the password (PostgreSQL SCRAM-SHA-256, MariaDB/MySQL `mysql_native_password`), the
clear text exists in the DSN line only; the PostgreSQL output also switches statement logging off
for its transaction – including the sampled logging of newer servers, the transaction sample
before `BEGIN`, where it is decided – and for the role. The clear-text form for MySQL 8.4+
(`--clear-text-password`) begins with a statement that stops it on MariaDB. The role name, the
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
`testDiagNeverEchoesAnInvalidUserArgument`, `testDiagRefusedDsnNeverPrintsThePassword`).

*Residual risk.* The verifier of MariaDB/MySQL is the one of `mysql_native_password` (SECURITY.md
register GB-30). A password that was set with the statements of 1.0.0 may be in existing database
logs (SECURITY.md, Logs).

### GB-31 Plugin files served by the web server; development files on a mail server

*Threat.* The plugin directory lies below the web root of some setups (iRedMail's nginx aliases
`static.php` to the Roundcube directory): its source, SQL templates and anything an administrator
leaves there can be downloaded. A git clone as installation method also brings `tests/` – scripts
that drop databases and reconfigure Dovecot – and, if they were ever committed, working documents
of a security audit.

*Controls.* The directory holds no secret of the installation when the documented layout is used
(the configuration file is denied by iRedMail's templates and may live in `config/`); `.htaccess`
denies everything on Apache; README 2.6 gives the nginx rule. `diag` reports a git clone and files
that are not part of a release, warns when the PHP user can write the plugin code, and with
`--url=` asks the web server itself whether it serves plugin files (FAIL when it does) – under the
web path of the plugin, `plugins/galbridge/`, whatever its directory is called on disk, and after
a control request: an address that does not answer like the Roundcube webmail gets a WARN, because
"not served" at a wrong address says nothing. The
documented installation is the archive of a verified, signed tag; release archives leave out
`tests/` and tooling (`.gitattributes`), and audit documents are git-ignored. The test scripts
refuse anything but a `*test*` database and test role names, refuse a machine that looks like a
mail server and never stop a Dovecot they did not configure.

*Tests.* `SourceGuardTest::testThePluginDirectoryDeniesWebAccess`, `CliToolTest`
(`testDiagChecksWhetherTheWebServerServesThePluginDirectory`, `testDiagReportsAGitCloneAndForeignFiles`,
`testDiagJudgesAReleaseDirectoryUnderAnotherName`, `testTheReleaseEntriesAreWhatGitExports`);
the CI job `release` checks the content of the release archive.

### GB-32 Supply chain of development and release

*Threat.* A compromised or vulnerable development dependency, CI action or container image; a
secret committed by mistake; a release that is not what the maintainer tagged.

*Controls.* The plugin has no runtime dependency besides Roundcube itself. CI: actions pinned to
commit SHAs, service images pinned by digest, a read-only token and no secrets; `composer audit` on
every resolved dependency set, whose versions each run records; lower bounds of PHPUnit that exclude
the releases with known advisories; a secret scan of the history and the tree (gitleaks, verified
by its checksum, with a control that proves the scanner works); static checks of the production
code in the test suite (`SourceGuardTest`: no process execution, dynamic code, deserialisation or
file writes; SQL execution in one class). Releases are signed, annotated tags; CI refuses a tag
whose signature GitHub does not verify or whose version differs from the code and the changelog
(docs/RELEASING.md).

*Tests.* `SourceGuardTest`; the CI jobs `security` and `release`.

*Residual risk.* No committed lockfile (SECURITY.md register GB-32).

## What GAL Bridge does NOT protect against

* A compromised web server, PHP process, Roundcube installation or administrator: whoever runs code
  as the PHP user can read the role's password and query everything the role may read.
* A compromised session store (it can change the cached Viewer or simply take over a session).
* Disclosure through the directory itself to legitimate viewers: everything a book shows is, by
  design, visible to every user entitled to that book. Choose books, fields and lists accordingly.
* The content of database server logs on MariaDB/MySQL (search terms in the general and slow query
  logs), Roundcube's errors log (connection messages) and web server access logs (contact IDs
  encode addresses, searches are GET requests); see SECURITY.md for the mitigations.
* Request floods beyond the result caps and timeouts, without a reverse-proxy limit.
* An IMAP server that the administrator lists as a directory server although it authenticates
  against another user database.
* A new or removed own alias of a viewer, or a changed column grant, within `galbridge_scope_ttl`.
* Engine-specific accent folding (MariaDB folds, PostgreSQL does not).
* Users of the OpenLDAP edition of iRedMail (not supported).
