-- Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
-- Original author: Marek Królikowski (TaKeN)
-- Original project: https://github.com/takenek/galbridge
-- SPDX-License-Identifier: GPL-3.0-or-later
-- See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
--
-- GAL Bridge - least-privilege, READ-ONLY account for the iRedMail 1.8 vmail database
-- (MariaDB / MySQL edition). GAL Bridge creates no tables of its own.
--
-- 1. Replace CHANGE_ME_RANDOM_PASSWORD with a long random password, e.g.
--      openssl rand -base64 33 | tr -d '/+=' | cut -c1-40
--    and, if your database is not called "vmail", every "vmail." below.
-- 2. Use the same host part as your existing Roundcube account ("SHOW GRANTS FOR roundcube@...").
--    A remote Roundcube host: replace 'localhost' by its address and add  REQUIRE SSL.
-- 3. Run as the database administrator:   mysql < grants.mysql.sql
-- 4. Put the DSN into plugins/galbridge/config.inc.php, e.g.
--      $config['galbridge_db_dsn'] = 'mysql://galbridge:PASSWORD@localhost/vmail';
--    and check it:  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
--
-- Column-level grants: the account can read ONLY the columns listed here - never mailbox.password,
-- recovery_email, allow_nets, settings or any other column. "SELECT *" is impossible by design.
-- Grants are additive; re-running this file is harmless. Drop what you do not use.

CREATE USER IF NOT EXISTS 'galbridge'@'localhost' IDENTIFIED BY 'CHANGE_ME_RANDOM_PASSWORD';

-- MariaDB only (MySQL 8 has no per-user statement time; the plugin also sets a session limit):
-- ALTER USER 'galbridge'@'localhost' WITH MAX_STATEMENT_TIME 5 MAX_USER_CONNECTIONS 25;

-- Required: persons, domains, per-user alias addresses and list/alias membership flags
GRANT SELECT (username, name, domain, active, enabledeliver, expired) ON vmail.mailbox TO 'galbridge'@'localhost';
GRANT SELECT (domain, active, backupmx, expired) ON vmail.domain TO 'galbridge'@'localhost';
GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON vmail.forwardings TO 'galbridge'@'localhost';

-- Alias accounts and mlmmj mailing lists (galbridge_include_aliases / galbridge_include_mailing_lists)
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON vmail.alias TO 'galbridge'@'localhost';
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON vmail.maillists TO 'galbridge'@'localhost';
GRANT SELECT (address, moderator) ON vmail.moderators TO 'galbridge'@'localhost';
GRANT SELECT (address, owner) ON vmail.maillist_owners TO 'galbridge'@'localhost';

-- Optional fields (galbridge_fields). Grant only what you enable; the organization field needs no
-- column (it comes from galbridge_organizations).
GRANT SELECT (department) ON vmail.mailbox TO 'galbridge'@'localhost';
-- Columns added by iRedMail 1.7.3 (update/1.7.3/vmail.*): first name, surname, phones, birthday.
-- On a server upgraded without that update these GRANTs fail ("Unknown column"): apply the update or
-- leave them out - GAL Bridge then works with fewer fields (bin/galbridge.sh diag lists them).
GRANT SELECT (first_name, last_name, telephone, mobile, birthday) ON vmail.mailbox TO 'galbridge'@'localhost';
