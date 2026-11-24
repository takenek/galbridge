# GAL Bridge – Threat model

This document lists what GAL Bridge protects, against whom, and how. The threats GB-01 to GB-28 come
from the security review of the design (before implementation) and were re-checked against the
1.0.0 code; each entry names the implemented controls (classes under `lib/GalBridge/`) and the tests
that exercise them (`tests/Integration/*Test`, `tests/Unit/*Test`, `tests/e2e/`). Where the
implementation differs from the original proposal of the review, the entry describes the
implementation. Section references ("ARCHITECTURE 11") point to
[ARCHITECTURE.md](ARCHITECTURE.md).

## Assets

| Asset | Where |
|---|---|
| Directory data of every tenant: names, addresses, departments, phone numbers, birthdays, alias accounts, list addresses | iRedMail `vmail` database (`mailbox`, `domain`, `alias`, `maillists`, `forwardings`, `moderators`, `maillist_owners`) |
| Secrets in the same database: password hashes, recovery e-mail addresses, `allow_nets`, per-user settings, private forwarding targets | `vmail.mailbox`, `vmail.forwardings` (never readable by the plugin's role) |
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
* The configured directory IMAP hosts authenticate users against the same `vmail` database (the
  Roundcube login equals `mailbox.username`). An IMAP server chosen by the client is not trusted.
* The `vmail` database is trusted for integrity; GAL Bridge only reads it. A database server on
  another host is reached over an untrusted network.
* Roundcube's session storage is trusted (it is server-side; whoever can write it can take over any
  session).

## Threats and mitigations

### GB-01 Loss of availability through fatal errors

*Threat.* The backend or the hooks fail on Roundcube 1.7.7 / PHP 8.x and take the mail and
address-book tasks down (the old plugin declared `reset()` / `set_search_set()` without the `: void`
that 1.7 requires, and broke on unvalidated configuration).

*Controls.* `AddressBook` and `DeniedBook` repeat every 1.7.7 signature, including `: void`;
`declare(strict_types=1)`, final classes and typed properties only. Every option is read through
typed, clamped `Config` getters with defaults. Both hook handlers catch `Throwable`, log the
exception class (`Log::exception`, message only with `galbridge_debug`) and degrade to an empty or
inert book; `addressbook_get` always sets an instance for our source IDs, so core's fatal error 700
cannot be triggered. composer requires `php >=8.1 <8.6`; PHPStan level 8 analyses the code against
the Roundcube 1.7.7 tree.

*Tests.* CI runs the whole suite on PHP 8.1 – 8.5 against Roundcube 1.7.7 with `failOnWarning`,
`failOnNotice` and `failOnDeprecation`; `ContractTest` (the `rcube_addressbook` contract,
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
is enforced in `search()` for `_action=autocomplete`, because core accepts any `_source` there.

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

### GB-06 Unbounded queries, mass harvesting and wrong totals

*Threat.* Core asks for page sizes of 999, 9999 and 99999 and passes `_page` unbounded; the old
plugin had no LIMIT and `count()` always returned 1.

*Controls.* `set_page()` ≥ 1; the page size is capped at core `max_pagesize` (clamped to 10–1000).
A larger size on page 1 is a full-set request handled explicitly (ARCHITECTURE 11): a group without
a filter is counted first and returned completely or refused above the expansion cap; an ID search is
limited by `galbridge_max_ids`; a search list is capped at `galbridge_max_results` /
`galbridge_global_max_results` with a notice; a whole-book export follows the book's export policy
and `galbridge_max_export`. `count()` is a `COUNT` over the same root predicate inside a `LIMIT`
subquery bounded by `galbridge_max_count`; list offsets beyond it return an empty page. All
membership predicates are semi-joins (`EXISTS` / `IN`), so rows are unique by primary key and totals
and paging are exact; the order is stable (sort key, address, kind).

*Tests.* `ContractTest` (`testListRecordsPagingCountAndFirst`, `testPageAndPageSizeAreClamped`,
`testCountHonoursScopeFilterGroupAndPage`), `CorePathsTest`
(`testFullSetSearchListsAreCappedWithANotice`, `testGroupExpandReturnsAllMembersOrRefuses`,
`testMailtoGroupWithCoreMaxGroupMembersIsCompleteOrRefused`, `testExportPaths`),
`OldPluginRegressionTest::testDefect06CountIsTheRealTotalAndListsArePaged`.

*Residual risk.* An export of a search result is a file download: when it is capped, the notice
cannot be shown (README 10).

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
the same value in compose, so Roundcube's JavaScript does not stop asking after an empty answer.
Search-only books (global default; `'auto'` above `galbridge_searchonly_threshold`) list nothing
without a search and have no groups. The global book forces prefix search and matches e-mail
addresses from the local part, never on a domain fragment.

*Tests.* `ContractTest::testEmptyTooShortAndTooLongInputNeverQueries`,
`ContractTest::testSearchOnlyBook`, `CorePathsTest::testAutocompleteContactsAndGroups`,
`EntitlementsTest::testGlobalBookIsSearchOnlyByDefault`, `PluginHooksTest::testAutocompleteWiringDetails`.

### GB-11 Wrong tenant derived from an untrusted login

*Threat.* With a user-chosen or templated IMAP host (`%d` from the client's `Host` header), an
attacker authenticates against their own IMAP server as `ceo@tenant-a.example`; the old plugin took
the scope from the domain part of the login string.

*Controls.* IMAP-host binding (`ViewerResolver::allowedImapHosts`): the allowed hosts are
`galbridge_imap_hosts`, or – only when that list is empty – the host part of the administrator's
`imap_host` (read from a fresh configuration, never a user preference) when it is a single string
without `%`; `parse_host()` is never called. Both `$_SESSION['storage_host']` and the user's stored
`mail_host` must match. Otherwise the plugin stays disabled for the session. The home domain is
`mailbox.domain` of the login's row (an active mailbox in an active, non-backup-MX domain; with
`galbridge_hide_expired` neither of them may be expired), never the domain part of the login string;
no row means no books.

*Tests.* `ConfigTest` (`testAllowedImapHostIsDerivedOnlyFromAFixedAdministratorHost`,
`testUserSelectableImapHostWithoutExplicitListDisablesThePlugin`,
`testSessionAndStoredImapHostMustBothMatch`), `IsolationTest::testLoginsWithoutAVisibleMailboxGetNoBooks`,
`IsolationTest::testCachedViewerIsBoundToTheImapHost`.

*Residual risk.* A login under an alias domain gets no books (the login must equal
`mailbox.username`).

### GB-12 Stale entitlements

*Threat.* A disabled or moved account, or a tightened configuration, keeps directory access for the
life of the session.

*Controls.* The resolved Viewer is cached in `$_SESSION['galbridge']`, bound to the user id, login,
IMAP host and the configuration fingerprint (a hash of every effective `galbridge_*` value and
`imap_host`), for `galbridge_scope_ttl` (300 s, 30–3600). A configuration change applies on the next
request; a database outage is cached separately for 30 s and never as "no entitlement". Directory
content is never cached: every list and search re-applies all visibility predicates.

*Tests.* `IsolationTest` (`testViewerIsCachedInTheSessionAndBoundToItsContext`,
`testConfigurationChangeOrExpiredTtlInvalidatesTheCache`, `testNegativeDecisionsAreCachedToo`,
`testOutageIsMemoisedAndNeverCachedAsNoEntitlement`), `ConfigTest::testPreferencesDoNotChangeTheFingerprint`.

*Residual risk.* A change of the user's own home domain or aliases in vmail applies after the TTL.

### GB-13 Global-book over-exposure in multi-tenant hosting

*Threat.* Cross-customer harvesting and disclosure of the list of hosted customers (the old plugin
defaulted to `['*']` lists and grouped the global book by domain).

*Controls.* Only the domain book is on by default. The global book is off; its viewer list is empty
by default; its data list is explicit, and `'*'` needs `galbridge_global_allow_wildcard` as a
second opt-in (`diag` warns). Deny-lists are applied after allow-lists and disable the book when
malformed. The viewer's own domain-book scope is subtracted unless `galbridge_global_include_home`.
Defaults: no groups (and no groups at all while search-only), search-only, prefix search, every word
at the minimum length, result cap 100, no whole-book export, foreign entries marked `Name (domain)`.
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
are shown only to viewers who may post to them under iRedAPD's `accesspolicy` (`AccessPolicy`);
unknown policy values are hidden (fail closed). Forwarding targets, catch-all rows and external list
members are never selected; per-user aliases are shown only with `galbridge_user_aliases`. Every
`forwardings` / `moderators` / `maillist_owners` predicate is a semi-join.

*Tests.* `AccessPolicyTest` (`testListingShowsExactlyThePostableAliasesAndLists`,
`testHiddenEntriesAreUnreachableByIdAndSearch`, `testGlobalBookAppliesThePolicyOfTheViewer`,
`testGroupsContainPersonsOnly`), `LeakageTest::testNoOutputEverContainsACanary`,
`ContractTest::testListGroupsAndGroupSearch`.

### GB-15 Cache poisoning across users or tenants

*Threat.* A shared cache keyed by search term or book would hand tenant A's results to tenant B;
packed caches use `unserialize()`.

*Controls.* By construction: GAL Bridge uses no Roundcube cache (`get_cache`, `get_cache_shared`),
no APCu and no `unserialize()`; results are memoised only inside one request (groups and counts in
`Repository`). The only cross-request state is the Viewer in the user's own session, bound to the
user id (GB-12).

*Tests.* `IsolationTest::testViewerIsCachedInTheSessionAndBoundToItsContext`; the absence of cache
APIs is a property of the source.

### GB-16 Administrator options overridden by user preferences

*Threat.* Roundcube merges stored user preferences over the configuration, so a `galbridge_*`
preference (saved by a plugin or written into the database) could widen the directory.

*Controls.* `Config::get()` ignores every preference named `galbridge_*` and returns the
administrator's value from a fresh `rcube_config` (main configuration plus the plugin
configuration) or the built-in default. Core options the plugin needs from the administrator
(`imap_host`, `db_dsnw`, `password_db_dsn`) are read the same way. SECURITY.md recommends listing
the security-relevant options in `dont_override` as well.

*Tests.* `ConfigTest` (`testUserPreferencesNeverOverrideAdministratorOptions`,
`testInjectedPreferencesCannotWidenTheDirectory`, `testGuardReadsAFreshConfigurationWhenAdminValuesAreNotGiven`),
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
sanitised (`Normalizer::displayText`: control, bidi and zero-width characters and `\ " < >`
removed). GAL Bridge renders no HTML of its own.

*Tests.* `ContractTest::testSourceListingInvariants`, `ContractTest::testRecordShapeAndColtypes`,
`NormalizerTest::testDisplayText`, `IdCodecTest`, `PluginHooksTest::testAddressbooksListEscapesTheName`,
the end-to-end suite
(`tests/e2e/`), which asserts on Roundcube's real responses.

### GB-19 Recipient or header injection and display spoofing from directory data

*Threat.* `format_email_recipient()` does not strip CR/LF; names with bidi overrides, quotes,
backslashes or embedded addresses can impersonate another recipient; IDN homographs.

*Controls.* `RecordMapper`: names NFC-normalised, control, bidi and zero-width characters, CR/LF and
`\ " < >` removed, capped at 128 characters; a name containing `@` is cut at the `@`; addresses are
canonical lower-case ASCII (A-labels, no homographs) and must pass `rcube_utils::check_email()`;
rows that fail are dropped (`row_dropped`). In the global book, entries outside the viewer's home
domain(s) are shown as `Name (domain)` with the ASCII domain.

*Tests.* `NormalizerTest::testDisplayTextOutputIsSafeForRecipientHeaders`,
`NormalizerTest::testDisplayTextRemovesInvisibleCharacters`, `LeakageTest`,
`ContractTest::testRecordShapeAndColtypes`.

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
"The directory is temporarily unavailable." and an empty result (`set_error()`).

*Tests.* `DsnPolicyTest::testPasswordNeverAppearsInErrorsOrDescriptions`,
`DsnPolicyTest::testRefusedDsnFailsClosedWithoutLoggingThePassword`,
`LeakageTest::testDebugLogNeverContainsSecrets`.

*Residual risk.* Connection failures are logged by Roundcube core with the PDO driver message (host
and user name). Database server logs contain the literal search terms, because `rcube_db` inlines
bound values on the client side (SECURITY.md).

### GB-22 Log injection and personal data in plugin logs

*Threat.* Request values and directory strings may contain CR/LF, bidi characters or huge values;
logging them forges lines and stores personal data in shared logs.

*Controls.* `Log::sanitize()` replaces control, bidi and zero-width characters, redacts invalid
UTF-8, truncates values to 300 characters and masks DSN passwords. Normal levels carry reason codes,
the numeric user id, book ids, counts and SQLSTATEs only; rejected group IDs are logged as a
12-character hash (`Log::tag`). Request-triggered reason codes are logged at most once per request
(`Log::once`), so a crafted request loop cannot flood the mail log.

*Tests.* `LogTest` (`testLogInjectionStaysOneLine`, `testBidiSpoofingIsNeutralised`,
`testOnceCannotBeFlooded`, `testDsnPasswordIsRedacted`, `testExceptionDetailsRequireDebugLogging`),
`LeakageTest::testNormalLogLinesNeverContainSearchTerms`.

### GB-23 Over-privileged or exposed database credentials

*Threat.* With iRedMail's `vmail` role (SELECT on every column) or `roundcube` role (UPDATE on
`vmail.mailbox`) any plugin bug could read password hashes or modify vmail; the old plugin's
PostgreSQL view embedded the `vmail` password in a `dblink` connection string.

*Controls.* A mandatory, separate `galbridge_db_dsn`. `Dsn` refuses the roles in
`galbridge_db_refused_users` (iRedMail's roles, `root`, `postgres`), the `db_dsnw` user and the
`password_db_dsn` user. `ViewerResolver` probes `information_schema.columns` and denies every book
when `mailbox.password`, `recovery_email` or `allow_nets` is visible (`role_too_broad`). The grant
templates create a new role with column-level `SELECT` only (PostgreSQL: `NOINHERIT`, read-only
transactions, statement timeout, connection limit); `bin/galbridge.sh grants` prints them with a
fresh password and never writes into the plugin directory. No views, `dblink` or `DEFINER`
objects. `diag` checks that `SELECT password` and an `UPDATE` of `mailbox` are both denied.

*Tests.* `DsnPolicyTest` (`testIredmailAndSuperuserRolesAreRefused`,
`testRoundcubeAndPasswordPluginUsersAreRefused`, `testRoleThatCanReadSecretColumnsDisablesEveryBook`,
`testRoleWithoutARequiredColumnFailsClosed`),
`OldPluginRegressionTest::testDefect11PortableSqlLeastPrivilegeAndStableIds`; CI and the local
integration databases connect with a
role created from the shipped templates (`tests/fixtures/vmail/load.sh`).

*Residual risk.* The password is in the plugin configuration file; protect it with `0640
root:<PHP group>` and the web-server deny rule (README 2.3, 2.6).

### GB-24 Eavesdropping on or tampering with traffic to a remote database

*Controls.* `Dsn` accepts a non-local host (anything but a unix socket, `127.0.0.0/8`, `::1`,
`localhost`) only with verified TLS – PostgreSQL `sslmode=verify-ca|verify-full`, MySQL `ca` or
`capath` with `verify_server_cert=1` – unless `galbridge_db_allow_plaintext` is set (logged as
`dsn_warning`, `diag` WARN). The PostgreSQL `service` parameter, which would move host and user out
of sight of these checks, is refused.

*Tests.* `DsnPolicyTest` (`testRemoteHostWithoutVerifiedTlsIsRefused`,
`testRemoteHostDisguisedAsLocalSocketIsRefused`, `testRemoteHostWithVerifiedTlsIsAccepted`,
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
session per connection (`galbridge_db_timeout`, 3 s) as a backstop to the role limits; a failed
connection memoised for the request and for 30 s in the session.

*Tests.* `CorePathsTest::testSenderPhotoOfEveryOpenedMessageNeverQueries`,
`PluginHooksTest::testInitRegistersTwoHooksAndNeverTouchesTheDatabase`,
`ContractTest::testEmptyTooShortAndTooLongInputNeverQueries`,
`IsolationTest::testOutageIsMemoisedAndNeverCachedAsNoEntitlement`,
`DsnPolicyTest::testConnectTimeoutIsInjectedWhenMissing`, `DsnPolicyTest::testInjectedConnectTimeoutIsClamped`.

*Residual risk.* Request volume is limited only by the reverse proxy and the role's connection
limit (SECURITY.md).

### GB-27 Scope or deny-list bypass through case, IDN or collation differences

*Threat.* MariaDB's `utf8mb4_general_ci` compares case-insensitively with PAD SPACE, PostgreSQL
`varchar` case-sensitively; a deny-list entry in mixed case or as a U-label would hide a tenant on
one engine and expose it on the other.

*Controls.* One `Normalizer` for configuration lists, the login, decoded IDs and the PHP row re-check
(trim, NFC, lower case, split at the last `@`, IDNA to ASCII – failing without `intl` for non-ASCII
input – trailing dot removed, validated). An invalid allow-list entry is dropped; an invalid
deny-list disables what it protects. SQL binds normalised values; the PHP re-check compares
normalised row domains with the scope, which absorbs collation variants. Department groups use
`LOWER(TRIM())` on both engines. `diag` reports mailboxes with upper-case letters.

*Tests.* `NormalizerTest`, `NormalizerWithoutIntlTest::testAsciiWorksAndIdnFailsClosedWithoutIntl`,
`IsolationTest::testIdnTenantIsIsolated`, `EntitlementsTest` (`testMalformedViewerDenyListDisablesTheBook`,
`testMalformedHiddenListDisablesEveryBook`, `testOverlappingOrganizationsFallBackToTheOwnDomain`).

*Residual risk.* Accent folding still differs (README 10).

### GB-28 Bulk export or printing, and processing before authentication

*Threat.* `contacts/export.php` exports a whole book with page size 99999; plugin `init()` runs
before Roundcube's CSRF and authentication checks.

*Controls.* A whole-book export is allowed only when the book's export option is on (domain book
`true`, global book `false`) and only up to `galbridge_max_export`; a search-only book exports
nothing without a search. Exports contain the effective fields only. `init()` returns without an
authenticated user, never touches the database, registers no actions and reads only core's resolved
action name; the plugin is limited to the `mail` and `addressbook` tasks.

*Tests.* `CorePathsTest::testExportPaths`, `PluginHooksTest` (`testAnonymousRequestsRegisterNothing`,
`testInitRegistersTwoHooksAndNeverTouchesTheDatabase`, `testAutocompleteIsWiredOnlyForComposeAndAutocomplete`).

## What GAL Bridge does NOT protect against

* A compromised web server, PHP process, Roundcube installation or administrator: whoever runs code
  as the PHP user can read the role's password and query everything the role may read.
* A compromised session store (it can change the cached Viewer or simply take over a session).
* Disclosure through the directory itself to legitimate viewers: everything a book shows is, by
  design, visible to every user entitled to that book. Choose books, fields and lists accordingly.
* The content of database server logs, Roundcube's errors log (connection messages) and web server
  access logs (contact IDs encode addresses); see SECURITY.md for the mitigations.
* Request floods beyond the result caps and timeouts, without a reverse-proxy limit.
* Changes in vmail that affect the viewer's own scope within `galbridge_scope_ttl`.
* Engine-specific accent folding (MariaDB folds, PostgreSQL does not).
* Users of the OpenLDAP edition of iRedMail (not supported).
