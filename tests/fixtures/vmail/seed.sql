-- GAL Bridge - TEST-ONLY vmail seed data (portable: MariaDB/MySQL, PostgreSQL, SQLite).
-- Loaded after the schema (schema.mysql.sql / schema.pgsql.sql = iRedMail 1.8.11 upstream,
-- schema.sqlite.sql = SQLite mirror). Every address, hash and phone number is fictional.
-- SECRET-* values and the private forwarding targets are canaries: tests assert that they
-- never appear in plugin output, SQL column lists or logs.
--
-- @license GPL-3.0-or-later
-- Domains:
--   example.com            active tenant A (has alias domain example.net)
--   example.org            active tenant B (cross-tenant isolation)
--   xn--bcher-kva.example  IDN tenant "bücher.example" stored as A-label
--   inactive.test          domain.active = 0
--   backup.test            domain.backupmx = 1 (relay only, never in GAL)
--   expired.test           active but domain.expired in the past
INSERT INTO domain (domain, description, transport, backupmx, settings, created, expired, active) VALUES
 ('example.com', 'Example Sp. z o.o.', 'dovecot', 0, 'default_user_quota:1024;', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('example.org', 'Example Org', 'dovecot', 0, '', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('xn--bcher-kva.example', 'Bücher GmbH', 'dovecot', 0, '', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('inactive.test', 'Inactive Ltd', 'dovecot', 0, '', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 0),
 ('backup.test', 'Backup MX only', 'relay:[192.0.2.10]:25', 1, '', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('expired.test', 'Expired Inc', 'dovecot', 0, '', '2024-01-01 10:00:00', '2020-01-01 00:00:00', 1);

INSERT INTO alias_domain (alias_domain, target_domain, created, active) VALUES
 ('example.net', 'example.com', '2024-01-01 10:00:00', 1);

INSERT INTO domain_admins (username, domain, created) VALUES
 ('postmaster@example.com', 'ALL', '2024-01-01 10:00:00');

INSERT INTO admin (username, password, name, created) VALUES
 ('standalone-admin@example.com', '{SSHA512}SECRET-ADMIN-HASH', 'Standalone Admin', '2024-01-01 10:00:00');

-- Mail users. password / recovery_email / allow_nets / settings hold canary
-- values that must never appear in plugin output or SQL column lists.
INSERT INTO mailbox (username, password, name, language, first_name, last_name, mobile, telephone,
                     recovery_email, birthday, maildir, quota, domain, department, employeeid,
                     isadmin, isglobaladmin, enabledeliver, allow_nets, settings, created, expired, active) VALUES
 ('postmaster@example.com', '{SSHA512}SECRET-HASH-1', 'Postmaster', 'en_US', '', '', '', '',
  '', '0001-01-01', 'example.com/p/o/s/postmaster', 1024, 'example.com', '', '',
  0, 1, 1, NULL, 'timezone:Europe/Warsaw;', '2024-01-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('jan.kowalski@example.com', '{SSHA512}SECRET-HASH-2', 'Jan Kowalski', 'pl_PL', 'Jan', 'Kowalski', '+48 600 100 200', '+48 22 123 45 67',
  'jan.private@gmail.test', '1980-05-17', 'example.com/j/a/n/jan.kowalski', 2048, 'example.com', 'Sprzedaż', 'E001',
  0, 0, 1, '192.0.2.0/24', '', '2024-01-02 10:00:00', '9999-12-31 00:00:00', 1),
 ('zofia.zolkiewska@example.com', '{SSHA512}SECRET-HASH-3', 'Zofia Żółkiewska', 'pl_PL', 'Zofia', 'Żółkiewska', '', '+48 22 765 43 21',
  '', '0001-01-01', 'example.com/z/o/f/zofia.zolkiewska', 1024, 'example.com', 'Księgowość', 'E002',
  0, 0, 1, NULL, '', '2024-01-03 10:00:00', '9999-12-31 00:00:00', 1),
 ('anna.nowak@example.com', '{SSHA512}SECRET-HASH-4', '', 'pl_PL', 'Anna', 'Nowak', '+48 601 222 333', '',
  '', '0001-01-01', 'example.com/a/n/n/anna.nowak', 1024, 'example.com', 'Sprzedaż', '',
  0, 0, 1, NULL, '', '2024-01-04 10:00:00', '9999-12-31 00:00:00', 1),
 ('lukasz.slowik@example.com', '{SSHA512}SECRET-HASH-5', 'Łukasz Słowik', 'pl_PL', 'Łukasz', 'Słowik', '', '',
  '', '1975-12-31', 'example.com/l/u/k/lukasz.slowik', 1024, 'example.com', 'IT', 'E005',
  1, 0, 1, NULL, '', '2024-01-05 10:00:00', '9999-12-31 00:00:00', 1),
 ('disabled.user@example.com', '{SSHA512}SECRET-HASH-6', 'Disabled User', 'en_US', 'Disabled', 'User', '', '',
  '', '0001-01-01', 'example.com/d/i/s/disabled.user', 1024, 'example.com', 'IT', '',
  0, 0, 1, NULL, '', '2024-01-06 10:00:00', '9999-12-31 00:00:00', 0),
 ('nodeliver@example.com', '{SSHA512}SECRET-HASH-7', 'No Deliver', 'en_US', '', '', '', '',
  '', '0001-01-01', 'example.com/n/o/d/nodeliver', 1024, 'example.com', '', '',
  0, 0, 0, NULL, '', '2024-01-07 10:00:00', '9999-12-31 00:00:00', 1),
 ('expired.user@example.com', '{SSHA512}SECRET-HASH-8', 'Expired User', 'en_US', '', '', '', '',
  '', '0001-01-01', 'example.com/e/x/p/expired.user', 1024, 'example.com', '', '',
  0, 0, 1, NULL, '', '2024-01-08 10:00:00', '2020-01-01 00:00:00', 1),
 ('forwarder@example.com', '{SSHA512}SECRET-HASH-9', 'Forward Only', 'en_US', '', '', '', '',
  '', '0001-01-01', 'example.com/f/o/r/forwarder', 1024, 'example.com', '', '',
  0, 0, 1, NULL, '', '2024-01-09 10:00:00', '9999-12-31 00:00:00', 1),
 ('pct_user@example.com', '{SSHA512}SECRET-HASH-10', '100% Wildcard_Test', 'en_US', '', '', '', '',
  '', '0001-01-01', 'example.com/p/c/t/pct_user', 1024, 'example.com', '', '',
  0, 0, 1, NULL, '', '2024-01-10 10:00:00', '9999-12-31 00:00:00', 1),
 ('bob@example.org', '{SSHA512}SECRET-HASH-11', 'Bob Smith', 'en_US', 'Bob', 'Smith', '', '+1 555 0100',
  '', '1990-02-28', 'example.org/b/o/b/bob', 1024, 'example.org', 'Sales', 'B001',
  0, 0, 1, NULL, '', '2024-01-11 10:00:00', '9999-12-31 00:00:00', 1),
 ('klaus@xn--bcher-kva.example', '{SSHA512}SECRET-HASH-12', 'Klaus Müller', 'de_DE', 'Klaus', 'Müller', '', '+49 30 1234567',
  '', '0001-01-01', 'xn--bcher-kva.example/k/l/a/klaus', 1024, 'xn--bcher-kva.example', 'Vertrieb', '',
  0, 0, 1, NULL, '', '2024-01-12 10:00:00', '9999-12-31 00:00:00', 1),
 ('carol@inactive.test', '{SSHA512}SECRET-HASH-13', 'Carol Inactive-Domain', 'en_US', '', '', '', '',
  '', '0001-01-01', 'inactive.test/c/a/r/carol', 1024, 'inactive.test', '', '',
  0, 0, 1, NULL, '', '2024-01-13 10:00:00', '9999-12-31 00:00:00', 1),
 ('dave@backup.test', '{SSHA512}SECRET-HASH-14', 'Dave BackupMX', 'en_US', '', '', '', '',
  '', '0001-01-01', 'backup.test/d/a/v/dave', 1024, 'backup.test', '', '',
  0, 0, 1, NULL, '', '2024-01-14 10:00:00', '9999-12-31 00:00:00', 1),
 ('erin@expired.test', '{SSHA512}SECRET-HASH-15', 'Erin Expired-Domain', 'en_US', '', '', '', '',
  '', '0001-01-01', 'expired.test/e/r/i/erin', 1024, 'expired.test', '', '',
  0, 0, 1, NULL, '', '2024-01-15 10:00:00', '9999-12-31 00:00:00', 1);

-- Self rows (iRedAdmin libs/sqllib/user.py:585-592; tools/create_mail_user_SQL.sh:125).
-- Disabled user: iRedAdmin sets forwardings.active=0 WHERE address=user OR forwarding=user
-- (libs/sqllib/user.py:660-665).
INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_forwarding, active) VALUES
 ('postmaster@example.com', 'postmaster@example.com', 'example.com', 'example.com', 1, 1),
 ('jan.kowalski@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),
 ('zofia.zolkiewska@example.com', 'zofia.zolkiewska@example.com', 'example.com', 'example.com', 1, 1),
 ('anna.nowak@example.com', 'anna.nowak@example.com', 'example.com', 'example.com', 1, 1),
 ('lukasz.slowik@example.com', 'lukasz.slowik@example.com', 'example.com', 'example.com', 1, 1),
 ('disabled.user@example.com', 'disabled.user@example.com', 'example.com', 'example.com', 1, 0),
 ('nodeliver@example.com', 'nodeliver@example.com', 'example.com', 'example.com', 1, 1),
 ('expired.user@example.com', 'expired.user@example.com', 'example.com', 'example.com', 1, 1),
 ('pct_user@example.com', 'pct_user@example.com', 'example.com', 'example.com', 1, 1),
 ('bob@example.org', 'bob@example.org', 'example.org', 'example.org', 1, 1),
 ('klaus@xn--bcher-kva.example', 'klaus@xn--bcher-kva.example', 'xn--bcher-kva.example', 'xn--bcher-kva.example', 1, 1),
 ('carol@inactive.test', 'carol@inactive.test', 'inactive.test', 'inactive.test', 1, 1),
 ('dave@backup.test', 'dave@backup.test', 'backup.test', 'backup.test', 1, 1),
 ('erin@expired.test', 'erin@expired.test', 'expired.test', 'expired.test', 1, 1),
 -- forward-only user: no self row, external destination is PRIVATE (must never leak)
 ('forwarder@example.com', 'private.destination@gmail.test', 'example.com', 'gmail.test', 1, 1),
 -- per-user mail forwarding with copy kept (self row above) -> external address is PRIVATE
 ('jan.kowalski@example.com', 'jan.home@outlook.test', 'example.com', 'outlook.test', 1, 1);

-- Per-user alias addresses (docs: howto/user.alias.address.md:37-55).
INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_alias, active) VALUES
 ('jk@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),
 ('sprzedaz@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),
 -- alias address in the alias domain; forwardings.domain is the owner's (target) domain
 ('jan@example.net', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),
 -- alias row disabled together with its user
 ('old.disabled@example.com', 'disabled.user@example.com', 'example.com', 'example.com', 1, 0),
 -- alias row explicitly disabled while the user stays active
 ('zz.inactive-alias@example.com', 'zofia.zolkiewska@example.com', 'example.com', 'example.com', 1, 0),
 ('bob.smith@example.org', 'bob@example.org', 'example.org', 'example.org', 1, 1);

-- Per-domain catch-all (docs: howto/sql.create.catch-all.md:18-19); address has no '@'.
INSERT INTO forwardings (address, forwarding, domain, dest_domain, active) VALUES
 ('example.com', 'postmaster@example.com', 'example.com', 'example.com', 1);

-- Mail alias accounts (docs: howto/sql.create.mail.alias.md:31-49, 77-84).
INSERT INTO alias (address, name, accesspolicy, domain, created, expired, active) VALUES
 ('team@example.com', 'Zespół sprzedaży', 'public', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('everyone@example.com', 'Everyone', '', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('board@example.com', 'Zarząd', 'membersonly', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('managers@example.com', 'Managers', 'moderatorsonly', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('audit@example.com', 'Audit', 'membersandmoderatorsonly', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('internal@example.com', 'Internal only', 'domain', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('legacy@example.com', 'Legacy policy', 'allowedonly', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('weird@example.com', 'Unknown policy', 'somethingnew', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('empty@example.com', 'No members (undeliverable)', 'public', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('oldalias@example.com', 'Disabled alias', 'public', 'example.com', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 0),
 ('expiredalias@example.com', 'Expired alias', 'public', 'example.com', '2024-02-01 10:00:00', '2020-01-01 00:00:00', 1),
 ('orgteam@example.org', 'Org Team', 'public', 'example.org', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('orginternal@example.org', 'Org Internal', 'domain', 'example.org', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('ghost@inactive.test', 'Alias in inactive domain', 'public', 'inactive.test', '2024-02-01 10:00:00', '9999-12-31 00:00:00', 1);

INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_list, active) VALUES
 ('team@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),
 ('team@example.com', 'anna.nowak@example.com', 'example.com', 'example.com', 1, 1),
 ('team@example.com', 'partner@external.test', 'example.com', 'external.test', 1, 1),
 ('team@example.com', 'disabled.user@example.com', 'example.com', 'example.com', 1, 0),
 ('everyone@example.com', 'team@example.com', 'example.com', 'example.com', 1, 1),
 ('everyone@example.com', 'zofia.zolkiewska@example.com', 'example.com', 'example.com', 1, 1),
 ('board@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com', 1, 1),
 ('board@example.com', 'lukasz.slowik@example.com', 'example.com', 'example.com', 1, 1),
 ('managers@example.com', 'lukasz.slowik@example.com', 'example.com', 'example.com', 1, 1),
 ('audit@example.com', 'anna.nowak@example.com', 'example.com', 'example.com', 1, 1),
 ('internal@example.com', 'postmaster@example.com', 'example.com', 'example.com', 1, 1),
 ('legacy@example.com', 'postmaster@example.com', 'example.com', 'example.com', 1, 1),
 ('weird@example.com', 'postmaster@example.com', 'example.com', 'example.com', 1, 1),
 ('oldalias@example.com', 'postmaster@example.com', 'example.com', 'example.com', 1, 1),
 ('expiredalias@example.com', 'postmaster@example.com', 'example.com', 'example.com', 1, 1),
 ('orgteam@example.org', 'bob@example.org', 'example.org', 'example.org', 1, 1),
 ('orginternal@example.org', 'bob@example.org', 'example.org', 'example.org', 1, 1),
 ('ghost@inactive.test', 'carol@inactive.test', 'inactive.test', 'inactive.test', 1, 1);

INSERT INTO moderators (address, moderator, domain, dest_domain) VALUES
 ('board@example.com', 'zofia.zolkiewska@example.com', 'example.com', 'example.com'),
 ('managers@example.com', 'anna.nowak@example.com', 'example.com', 'example.com'),
 ('audit@example.com', '*@example.org', 'example.com', 'example.org'),
 ('legacy@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com'),
 ('staff@example.com', 'zofia.zolkiewska@example.com', 'example.com', 'example.com');

-- mlmmj mailing lists (mlmmjadmin backends/bk_iredmail_sql.py:360-387).
-- Subscribers live in the mlmmj spool on disk, NOT in SQL.
INSERT INTO maillists (address, domain, transport, accesspolicy, maxmsgsize, name, description, mlid,
                       is_newsletter, settings, created, expired, active) VALUES
 ('newsletter@example.com', 'example.com', 'mlmmj:example.com/newsletter', 'public', 0, 'Newsletter firmy',
  'Comiesięczny newsletter', '0b4f8a2e-1c3d-4e5f-8a9b-0c1d2e3f4a5b', 1, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('staff@example.com', 'example.com', 'mlmmj:example.com/staff', 'membersonly', 0, 'Staff discussion',
  NULL, '1c5a9b3f-2d4e-4f6a-9b0c-1d2e3f4a5b6c', 0, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('mods@example.com', 'example.com', 'mlmmj:example.com/mods', 'moderatorsonly', 0, 'Announcements',
  NULL, '2d6b0c4a-3e5f-4a7b-0c1d-2e3f4a5b6c7d', 0, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('retired@example.com', 'example.com', 'mlmmj:example.com/retired', 'public', 0, 'Retired list',
  NULL, '3e7c1d5b-4f6a-4b8c-1d2e-3f4a5b6c7d8e', 0, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 0),
 ('broken@example.com', 'example.com', 'mlmmj:example.com/broken', 'public', 0, 'List without forwardings row',
  NULL, '4f8d2e6c-5a7b-4c9d-2e3f-4a5b6c7d8e9f', 0, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 1),
 ('orglist@example.org', 'example.org', 'mlmmj:example.org/orglist', 'public', 0, 'Org list',
  NULL, '5a9e3f7d-6b8c-4d0e-3f4a-5b6c7d8e9f0a', 0, NULL, '2024-03-01 10:00:00', '9999-12-31 00:00:00', 1);

INSERT INTO forwardings (address, forwarding, domain, dest_domain, is_maillist, active) VALUES
 ('newsletter@example.com', 'newsletter@example.com', 'example.com', 'example.com', 1, 1),
 ('staff@example.com', 'staff@example.com', 'example.com', 'example.com', 1, 1),
 ('mods@example.com', 'mods@example.com', 'example.com', 'example.com', 1, 1),
 ('retired@example.com', 'retired@example.com', 'example.com', 'example.com', 1, 1),
 ('orglist@example.org', 'orglist@example.org', 'example.org', 'example.org', 1, 1);

INSERT INTO maillist_owners (address, owner, domain, dest_domain) VALUES
 ('newsletter@example.com', 'jan.kowalski@example.com', 'example.com', 'example.com'),
 ('staff@example.com', 'postmaster@example.com', 'example.com', 'example.com'),
 ('mods@example.com', 'lukasz.slowik@example.com', 'example.com', 'example.com');

-- Expected results with hide_expired=ON, :now='2026-11-24 12:00:00' (validated with sqlite3 3.46.1):
--  example.com persons: pct_user, forwarder, jan.kowalski, anna.nowak, postmaster, lukasz.slowik,
--    zofia.zolkiewska (7; excluded: disabled.user, nodeliver, expired.user). Sort order shown is SQLite (byte-wise non-ASCII).
--  visible domains: example.com, example.org, xn--bcher-kva.example
--  jan aliases: jan@example.net, jk@example.com, sprzedaz@example.com; departments: IT(1), Księgowość(1), Sprzedaż(2)
--  alias contacts for jan: board, everyone, internal, legacy, team (+orgteam if example.org in scope);
--    never: weird, empty, oldalias, expiredalias, ghost, managers, audit
--  lists for jan: newsletter; for zofia: newsletter, staff (moderator); for lukasz: newsletter, mods (owner);
--    never: retired, broken
--  team@example.com members: anna.nowak, jan.kowalski (+ external partner@external.test as non-person)
--  never selected anywhere: SECRET-*, jan.private@gmail.test, private.destination@gmail.test,
--    jan.home@outlook.test, 192.0.2.0/24, catch-all row 'example.com'
