-- Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
-- Original author: Marek Królikowski (TaKeN)
-- Original project: https://github.com/takenek/galbridge
-- SPDX-License-Identifier: GPL-3.0-or-later
-- See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
--
-- GAL Bridge - least-privilege, READ-ONLY role for the iRedMail 1.8 vmail database
-- (PostgreSQL edition). GAL Bridge creates no tables of its own.
--
-- 1. Replace CHANGE_ME_RANDOM_PASSWORD with a long random password, e.g.
--      openssl rand -base64 33 | tr -d '/+=' | cut -c1-40
-- 2. Run as the postgres superuser, connected to the vmail database:
--      su - postgres -c "psql -d vmail -f /path/to/grants.postgres.sql"
--    (iRedMail's pg_hba.conf ends with "host all all 0.0.0.0/0 md5": password logins are accepted from
--    every IPv4 address that listen_addresses lets in. For a remote Roundcube host insert
--    "hostssl vmail galbridge <ip>/32 scram-sha-256" and "hostnossl vmail galbridge 0.0.0.0/0 reject"
--    BEFORE that line - pg_hba.conf uses the first matching line.)
-- 3. Put the DSN into plugins/galbridge/config.inc.php, e.g.
--      $config['galbridge_db_dsn'] = 'pgsql://galbridge:PASSWORD@127.0.0.1/vmail';
--    and check it:  sudo -u www-data plugins/galbridge/bin/galbridge.sh diag
--
-- Column-level grants: the role can read ONLY the columns listed here - never mailbox.password,
-- recovery_email, allow_nets, settings or any other column. "SELECT *" is impossible by design.
-- A NEW role is required: column grants cannot narrow an existing table-level grant (e.g. of the
-- iRedMail "vmail" or "roundcube" roles).

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'galbridge') THEN
        CREATE ROLE galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT;
    END IF;
END
$$;
-- (re)set the password and the connection limit, so re-running a freshly generated file always
-- matches its DSN (size the limit to the PHP-FPM pm.max_children of Roundcube)
ALTER ROLE galbridge WITH LOGIN PASSWORD 'CHANGE_ME_RANDOM_PASSWORD' CONNECTION LIMIT 25;
ALTER ROLE galbridge SET default_transaction_read_only = on;
ALTER ROLE galbridge SET statement_timeout = '5s';
ALTER ROLE galbridge SET idle_in_transaction_session_timeout = '10s';

GRANT CONNECT ON DATABASE vmail TO galbridge;
GRANT USAGE ON SCHEMA public TO galbridge;

-- Required: persons, domains, per-user alias addresses and list/alias membership flags
GRANT SELECT (username, name, domain, active, enabledeliver, expired) ON mailbox TO galbridge;
GRANT SELECT (domain, active, backupmx, expired) ON domain TO galbridge;
GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON forwardings TO galbridge;

-- Alias accounts and mlmmj mailing lists (galbridge_include_aliases / galbridge_include_mailing_lists)
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON alias TO galbridge;
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON maillists TO galbridge;
GRANT SELECT (address, moderator) ON moderators TO galbridge;
GRANT SELECT (address, owner) ON maillist_owners TO galbridge;

-- Optional fields (galbridge_fields). Grant only what you enable; the organization field needs no
-- column (it comes from galbridge_organizations).
GRANT SELECT (department) ON mailbox TO galbridge;
-- Columns added by iRedMail 1.7.3 (update/1.7.3/vmail.*). On a server upgraded without that update
-- these GRANTs fail (column does not exist): apply the update or leave them out - GAL Bridge then works
-- with fewer fields (bin/galbridge.sh diag lists them). First name and surname are default fields:
GRANT SELECT (first_name, last_name) ON mailbox TO galbridge;
-- Personal data, only if you enable the fields phone / mobile / birthday in galbridge_fields:
-- GRANT SELECT (telephone, mobile, birthday) ON mailbox TO galbridge;
