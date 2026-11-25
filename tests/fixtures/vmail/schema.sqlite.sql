-- Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
-- Original author: Marek Królikowski (TaKeN)
-- Original project: https://github.com/takenek/galbridge
-- SPDX-License-Identifier: GPL-3.0-or-later
-- See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.

-- GAL Bridge (galbridge) - SQLite test fixture mirroring the iRedMail 1.8.11
-- "vmail" schema (samples/iredmail/iredmail.mysql / iredmail.pgsql).
-- Column names, NOT NULL/DEFAULT values and unique keys follow the MySQL
-- edition; booleans are INTEGER 0/1 (TINYINT(1) / INT2 upstream); DATETIME and
-- DATE are ISO-8601 TEXT ('YYYY-MM-DD HH:MM:SS' / 'YYYY-MM-DD').
-- Secret / private columns (password, recovery_email, allow_nets, settings,
-- maildir, ...) are present ON PURPOSE so tests can assert they never leak.
-- No COLLATE NOCASE: comparisons are case-sensitive like PostgreSQL; iRedMail
-- stores addresses and domains in lower case.
-- IMPORTANT: load this into the database file BEFORE rcube_db_sqlite connects;
-- rcube_db_sqlite::conn_configure() imports Roundcube's own SQL/sqlite.initial.sql
-- into any EMPTY database file (program/lib/Roundcube/db/sqlite.php:53-54).

PRAGMA foreign_keys = OFF;

CREATE TABLE IF NOT EXISTS admin (
    username TEXT NOT NULL DEFAULT '',
    password TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL DEFAULT '',
    language TEXT NOT NULL DEFAULT '',
    passwordlastchange TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    settings TEXT,
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    expired TEXT NOT NULL DEFAULT '9999-12-31 00:00:00',
    active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (username)
);

CREATE TABLE IF NOT EXISTS alias (
    address TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL DEFAULT '',
    accesspolicy TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    expired TEXT NOT NULL DEFAULT '9999-12-31 00:00:00',
    active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (address)
);
CREATE INDEX IF NOT EXISTS idx_alias_domain ON alias (domain);
CREATE INDEX IF NOT EXISTS idx_alias_expired ON alias (expired);
CREATE INDEX IF NOT EXISTS idx_alias_active ON alias (active);

CREATE TABLE IF NOT EXISTS moderators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address TEXT NOT NULL DEFAULT '',
    moderator TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    dest_domain TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_moderators_address_moderator ON moderators (address, moderator);
CREATE INDEX IF NOT EXISTS idx_moderators_moderator ON moderators (moderator);
CREATE INDEX IF NOT EXISTS idx_moderators_domain ON moderators (domain);

CREATE TABLE IF NOT EXISTS maillist_owners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address TEXT NOT NULL DEFAULT '',
    owner TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    dest_domain TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_maillist_owners_address_owner ON maillist_owners (address, owner);
CREATE INDEX IF NOT EXISTS idx_maillist_owners_owner ON maillist_owners (owner);

-- address -> forwarding. Row kinds (exactly one flag set, or none for catch-all):
--   is_forwarding=1 : address = mailbox.username, forwarding = destination
--                     (self row address=forwarding exists for every user)
--   is_alias=1      : address = per-user alias address, forwarding = mailbox.username
--   is_list=1       : address = alias.address (alias account), forwarding = member
--   is_maillist=1   : address = maillists.address, forwarding = same address
--   all flags 0 and address without '@' : per-domain catch-all
CREATE TABLE IF NOT EXISTS forwardings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address TEXT NOT NULL DEFAULT '',
    forwarding TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    dest_domain TEXT NOT NULL DEFAULT '',
    is_maillist INTEGER NOT NULL DEFAULT 0,
    is_list INTEGER NOT NULL DEFAULT 0,
    is_forwarding INTEGER NOT NULL DEFAULT 0,
    is_alias INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_forwardings_address_forwarding ON forwardings (address, forwarding);
CREATE INDEX IF NOT EXISTS idx_forwardings_forwarding ON forwardings (forwarding);
CREATE INDEX IF NOT EXISTS idx_forwardings_domain ON forwardings (domain);
CREATE INDEX IF NOT EXISTS idx_forwardings_is_maillist ON forwardings (is_maillist);
CREATE INDEX IF NOT EXISTS idx_forwardings_is_list ON forwardings (is_list);
CREATE INDEX IF NOT EXISTS idx_forwardings_is_alias ON forwardings (is_alias);

-- The view the plugin reads instead of the table (mirror of SQL/grants.*.sql): no private
-- forwarding target (is_forwarding rows) is reachable through it. (The fence of the server
-- templates - TEMPTABLE with LIMIT, security_barrier with OFFSET 0 - keeps conditions of a query sent
-- with the role's credentials away from the table; SQLite has no roles, so the mirror needs none.)
CREATE VIEW IF NOT EXISTS galbridge_forwardings AS
    SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active
      FROM forwardings
     WHERE is_alias = 1 OR is_list = 1 OR is_maillist = 1;

CREATE TABLE IF NOT EXISTS domain (
    domain TEXT NOT NULL DEFAULT '',
    description TEXT,
    disclaimer TEXT,
    aliases INTEGER NOT NULL DEFAULT 0,
    mailboxes INTEGER NOT NULL DEFAULT 0,
    maillists INTEGER NOT NULL DEFAULT 0,
    maxquota INTEGER NOT NULL DEFAULT 0,
    quota INTEGER NOT NULL DEFAULT 0,
    transport TEXT NOT NULL DEFAULT 'dovecot',
    backupmx INTEGER NOT NULL DEFAULT 0,
    settings TEXT,
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    expired TEXT NOT NULL DEFAULT '9999-12-31 00:00:00',
    active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (domain)
);
CREATE INDEX IF NOT EXISTS idx_domain_backupmx ON domain (backupmx);
CREATE INDEX IF NOT EXISTS idx_domain_expired ON domain (expired);
CREATE INDEX IF NOT EXISTS idx_domain_active ON domain (active);

CREATE TABLE IF NOT EXISTS alias_domain (
    alias_domain TEXT NOT NULL,
    target_domain TEXT NOT NULL,
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (alias_domain)
);
CREATE INDEX IF NOT EXISTS idx_alias_domain_target_domain ON alias_domain (target_domain);

CREATE TABLE IF NOT EXISTS domain_admins (
    username TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    expired TEXT NOT NULL DEFAULT '9999-12-31 00:00:00',
    active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (username, domain)
);

CREATE TABLE IF NOT EXISTS mailbox (
    username TEXT NOT NULL DEFAULT '',
    password TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL DEFAULT '',
    language TEXT NOT NULL DEFAULT '',
    first_name TEXT NOT NULL DEFAULT '',
    last_name TEXT NOT NULL DEFAULT '',
    mobile TEXT NOT NULL DEFAULT '',
    telephone TEXT NOT NULL DEFAULT '',
    recovery_email TEXT NOT NULL DEFAULT '',
    birthday TEXT NOT NULL DEFAULT '0001-01-01',
    mailboxformat TEXT NOT NULL DEFAULT 'maildir',
    mailboxfolder TEXT NOT NULL DEFAULT 'Maildir',
    storagebasedirectory TEXT NOT NULL DEFAULT '',
    storagenode TEXT NOT NULL DEFAULT '',
    maildir TEXT NOT NULL DEFAULT '',
    quota INTEGER NOT NULL DEFAULT 0,
    domain TEXT NOT NULL DEFAULT '',
    transport TEXT NOT NULL DEFAULT '',
    department TEXT NOT NULL DEFAULT '',
    "rank" TEXT NOT NULL DEFAULT 'normal',
    employeeid TEXT NOT NULL DEFAULT '',
    isadmin INTEGER NOT NULL DEFAULT 0,
    isglobaladmin INTEGER NOT NULL DEFAULT 0,
    enablesmtp INTEGER NOT NULL DEFAULT 1,
    enablesmtpsecured INTEGER NOT NULL DEFAULT 1,
    enablepop3 INTEGER NOT NULL DEFAULT 1,
    enablepop3secured INTEGER NOT NULL DEFAULT 1,
    enablepop3tls INTEGER NOT NULL DEFAULT 1,
    enableimap INTEGER NOT NULL DEFAULT 1,
    enableimapsecured INTEGER NOT NULL DEFAULT 1,
    enableimaptls INTEGER NOT NULL DEFAULT 1,
    enabledeliver INTEGER NOT NULL DEFAULT 1,
    enablelda INTEGER NOT NULL DEFAULT 1,
    enablemanagesieve INTEGER NOT NULL DEFAULT 1,
    enablemanagesievesecured INTEGER NOT NULL DEFAULT 1,
    enablesieve INTEGER NOT NULL DEFAULT 1,
    enablesievesecured INTEGER NOT NULL DEFAULT 1,
    enablesievetls INTEGER NOT NULL DEFAULT 1,
    enableinternal INTEGER NOT NULL DEFAULT 1,
    enabledoveadm INTEGER NOT NULL DEFAULT 1,
    "enablelib-storage" INTEGER NOT NULL DEFAULT 1,
    "enablequota-status" INTEGER NOT NULL DEFAULT 1,
    "enableindexer-worker" INTEGER NOT NULL DEFAULT 1,
    enablelmtp INTEGER NOT NULL DEFAULT 1,
    enabledsync INTEGER NOT NULL DEFAULT 1,
    enablesogo INTEGER NOT NULL DEFAULT 1,
    enablesogowebmail TEXT NOT NULL DEFAULT 'y',
    enablesogocalendar TEXT NOT NULL DEFAULT 'y',
    enablesogoactivesync TEXT NOT NULL DEFAULT 'y',
    allow_nets TEXT DEFAULT NULL,
    disclaimer TEXT,
    settings TEXT,
    passwordlastchange TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    expired TEXT NOT NULL DEFAULT '9999-12-31 00:00:00',
    active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (username)
);
CREATE INDEX IF NOT EXISTS idx_mailbox_domain ON mailbox (domain);
CREATE INDEX IF NOT EXISTS idx_mailbox_department ON mailbox (department);
CREATE INDEX IF NOT EXISTS idx_mailbox_employeeid ON mailbox (employeeid);
CREATE INDEX IF NOT EXISTS idx_mailbox_enabledeliver ON mailbox (enabledeliver);
CREATE INDEX IF NOT EXISTS idx_mailbox_expired ON mailbox (expired);
CREATE INDEX IF NOT EXISTS idx_mailbox_active ON mailbox (active);

CREATE TABLE IF NOT EXISTS maillists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address TEXT NOT NULL DEFAULT '',
    domain TEXT NOT NULL DEFAULT '',
    transport TEXT NOT NULL DEFAULT '',
    accesspolicy TEXT NOT NULL DEFAULT '',
    maxmsgsize INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL DEFAULT '',
    description TEXT,
    mlid TEXT NOT NULL DEFAULT '',
    is_newsletter INTEGER NOT NULL DEFAULT 0,
    settings TEXT,
    created TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    modified TEXT NOT NULL DEFAULT '1970-01-01 01:01:01',
    expired TEXT NOT NULL DEFAULT '9999-12-31 00:00:00',
    active INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_maillists_address ON maillists (address);
CREATE UNIQUE INDEX IF NOT EXISTS idx_maillists_mlid ON maillists (mlid);
CREATE INDEX IF NOT EXISTS idx_maillists_domain ON maillists (domain);
CREATE INDEX IF NOT EXISTS idx_maillists_active ON maillists (active);

