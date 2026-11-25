-- Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
-- Original author: Marek Królikowski (TaKeN)
-- Original project: https://github.com/takenek/galbridge
-- SPDX-License-Identifier: GPL-3.0-or-later
-- See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
--
-- GAL Bridge - least-privilege, READ-ONLY account for the iRedMail 1.8 vmail database
-- (MariaDB / MySQL edition). GAL Bridge creates no tables of its own; this file creates the
-- account and ONE view (vmail.galbridge_forwardings, see below).
--
-- Prefer "plugins/galbridge/bin/galbridge.sh grants --driver=mysql": it prints this file with a
-- fresh random password, your role name, account host and database, and the matching DSN line
-- (how the password is carried in the statements: see the note above the [role] block).
-- To use the file by hand:
-- 1. Choose a long random password and compute its verifier (the mysql_native_password hash) on
--    your own machine, without sending the password to the server:
--      PW="$(openssl rand -base64 33 | tr -d '/+=' | cut -c1-40)"; echo "password: $PW"
--      printf '*%s\n' "$(printf '%s' "$PW" | openssl dgst -sha1 -binary | openssl dgst -sha1 -hex | sed 's/^.* //' | tr a-f A-F)"
--    Replace CHANGE_ME_PASSWORD_VERIFIER below with the second line (41 characters, starting with
--    '*') and, if your database is not called "vmail", every "vmail." below.
-- 2. Use the same host part as your existing Roundcube account ("SHOW GRANTS FOR roundcube@...").
--    A remote Roundcube host: replace 'localhost' by its address and end the CREATE USER statement
--    with  REQUIRE SSL  (there, not later: a GRANT that fails stops the script).
-- 3. Run as the database administrator (root):   mysql < grants.mysql.sql
--    Never with --force: the first error must stop the script.
-- 4. Put the DSN (with the password, not the verifier) into plugins/galbridge/config.inc.php, e.g.
--      $config['galbridge_db_dsn'] = 'mysql://galbridge:PASSWORD@localhost/vmail';
--    and check it:  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
--
-- Column-level grants: the account can read ONLY the columns listed here - never mailbox.password,
-- recovery_email, allow_nets, settings or any other column. "SELECT *" is impossible by design.
-- Grants are additive. Drop what you do not use.

-- No statement of this file contains the password of the account: CREATE USER takes its verifier.
-- MariaDB writes account statements to its general query log, to its binary log and - when they
-- qualify - to its slow query log exactly as they were sent (verified on MariaDB 11.8), so a
-- password inside "IDENTIFIED BY '...'" would stay in those logs, on the replicas and in backups.
-- MySQL 8.4 and later do not load mysql_native_password by default: there CREATE USER stops with
-- an error and nothing is created - use "galbridge.sh grants --clear-text-password" (MySQL
-- rewrites passwords in its own logs; that output refuses to run on MariaDB).

-- [role] Creates the account and STOPS when it already exists (ERROR 1396): nothing below is then
-- applied to an account you did not mean - e.g. the one of another component. For an existing
-- account use "galbridge.sh grants --reset-password" (new password) or "--grants-only" (password
-- unchanged); they replace this block.
CREATE USER 'galbridge'@'localhost' IDENTIFIED WITH mysql_native_password AS 'CHANGE_ME_PASSWORD_VERIFIER';
-- [/role]

-- Account limits, active by default: at most 25 simultaneous connections (size it to the PHP-FPM
-- pm.max_children of Roundcube; "galbridge.sh grants --connection-limit=N") and, on MariaDB only,
-- at most 5 seconds per statement (MySQL has no per-account statement time and ignores that part;
-- the plugin also sets a session limit on both).
ALTER USER 'galbridge'@'localhost' WITH MAX_USER_CONNECTIONS 25 /*M!100101 MAX_STATEMENT_TIME 5 */;

-- vmail.forwardings also holds the PRIVATE forwarding targets of every mailbox (is_forwarding
-- rows, often external addresses). The account gets no access to that table. It reads this view,
-- which has only the three kinds of rows GAL Bridge needs: per-user alias addresses (is_alias),
-- members of alias accounts (is_list; needed for the "membersonly" policies) and mailing lists
-- (is_maillist). The view runs with the rights of its creator (SQL SECURITY DEFINER) and is
-- re-created on every run. Before an iRedMail upgrade that changes vmail.forwardings you may
-- DROP VIEW vmail.galbridge_forwardings and run "galbridge.sh grants --grants-only" afterwards.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW vmail.galbridge_forwardings AS
    SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active
      FROM vmail.forwardings
     WHERE is_alias = 1 OR is_list = 1 OR is_maillist = 1;

-- Required: persons, domains, per-user alias addresses and list/alias membership flags
GRANT SELECT (username, name, domain, active, enabledeliver, expired) ON vmail.mailbox TO 'galbridge'@'localhost';
GRANT SELECT (domain, active, backupmx, expired) ON vmail.domain TO 'galbridge'@'localhost';
GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON vmail.galbridge_forwardings TO 'galbridge'@'localhost';

-- Alias accounts and mlmmj mailing lists (galbridge_include_aliases / galbridge_include_mailing_lists)
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON vmail.alias TO 'galbridge'@'localhost';
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON vmail.maillists TO 'galbridge'@'localhost';
GRANT SELECT (address, moderator) ON vmail.moderators TO 'galbridge'@'localhost';
GRANT SELECT (address, owner) ON vmail.maillist_owners TO 'galbridge'@'localhost';

-- Optional fields (galbridge_fields). Grant only what you enable; the organization field needs no
-- column (it comes from galbridge_organizations).
GRANT SELECT (department) ON vmail.mailbox TO 'galbridge'@'localhost';
-- Columns added by iRedMail 1.7.3 (update/1.7.3/vmail.*). On a server upgraded without that update
-- these GRANTs fail ("Unknown column"): apply the update or leave them out - GAL Bridge then works with
-- fewer fields (bin/galbridge.sh diag lists them). First name and surname are default fields:
GRANT SELECT (first_name, last_name) ON vmail.mailbox TO 'galbridge'@'localhost';
-- Personal data, only if you enable the fields phone / mobile / birthday in galbridge_fields:
-- GRANT SELECT (telephone, mobile, birthday) ON vmail.mailbox TO 'galbridge'@'localhost';

-- An account created with the grants of GAL Bridge 1.0.0 could read the forwardings table itself;
-- GAL Bridge now refuses such an account (role_too_broad). Take that grant away once (the statement
-- fails for an account that never had it, so it is not run by default):
-- REVOKE SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON vmail.forwardings FROM 'galbridge'@'localhost';
