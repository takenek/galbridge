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
--    (iRedMail's pg_hba.conf already allows md5/scram password logins over the local socket and
--    127.0.0.1; a remote Roundcube host needs a "hostssl vmail galbridge <ip>/32 scram-sha-256" line.)
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
        CREATE ROLE galbridge WITH LOGIN PASSWORD 'CHANGE_ME_RANDOM_PASSWORD'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT CONNECTION LIMIT 25;
    END IF;
END
$$;
ALTER ROLE galbridge SET default_transaction_read_only = on;
ALTER ROLE galbridge SET statement_timeout = '5s';
ALTER ROLE galbridge SET idle_in_transaction_session_timeout = '10s';

GRANT CONNECT ON DATABASE vmail TO galbridge;
GRANT USAGE ON SCHEMA public TO galbridge;

-- Required: persons, domains, alias domains, per-user alias addresses and list/alias membership flags
GRANT SELECT (username, name, first_name, last_name, domain, active, enabledeliver, expired) ON mailbox TO galbridge;
GRANT SELECT (domain, active, backupmx, expired) ON domain TO galbridge;
GRANT SELECT (alias_domain, target_domain, active) ON alias_domain TO galbridge;
GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON forwardings TO galbridge;

-- Alias accounts and mlmmj mailing lists (galbridge_include_aliases / galbridge_include_mailing_lists)
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON alias TO galbridge;
GRANT SELECT (address, name, accesspolicy, domain, active, expired) ON maillists TO galbridge;
GRANT SELECT (address, moderator) ON moderators TO galbridge;
GRANT SELECT (address, owner) ON maillist_owners TO galbridge;

-- Optional fields (galbridge_fields): department, phone (telephone), mobile, birthday,
-- organization (domain.description). Grant only what you enable.
GRANT SELECT (department, telephone, mobile, birthday) ON mailbox TO galbridge;
GRANT SELECT (description) ON domain TO galbridge;
