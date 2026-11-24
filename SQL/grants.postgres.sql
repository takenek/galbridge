-- Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
-- Original author: Marek Królikowski (TaKeN)
-- Original project: https://github.com/takenek/galbridge
-- SPDX-License-Identifier: GPL-3.0-or-later
-- See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
--
-- GAL Bridge - least-privilege, READ-ONLY role for the iRedMail 1.8 vmail database
-- (PostgreSQL edition). GAL Bridge creates no tables of its own; this file creates the role and
-- ONE view (galbridge_forwardings, see below).
--
-- Prefer "plugins/galbridge/bin/galbridge.sh grants --driver=pgsql": it prints this file with a
-- fresh random password (as a SCRAM-SHA-256 verifier - the statements never contain the password
-- itself), your role name and database, and the matching DSN line. To use the file by hand:
-- 1. Replace CHANGE_ME_RANDOM_PASSWORD with a long random password, e.g.
--      openssl rand -base64 33 | tr -d '/+=' | cut -c1-40
--    Written by hand it is in clear text in the statement; the settings at the top of the
--    script keep this transaction out of the server log whatever the server-wide logging settings
--    say (an audit extension such as pgaudit has settings of its own - check it, or use the
--    generated output).
-- 2. Run as the postgres superuser, connected to the vmail database:
--      su - postgres -c "psql -v ON_ERROR_STOP=1 -d vmail -f /path/to/grants.postgres.sql"
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
--
-- Everything runs in ONE transaction: after an error nothing at all is applied.

-- PostgreSQL 12 and later can log every statement of a SAMPLE of the transactions
-- (log_transaction_sample_rate). Whether a transaction is in the sample is decided when it starts,
-- so this has to be switched off - for this session - before BEGIN. Older servers do not know the
-- setting.
DO $$
BEGIN
    IF current_setting('server_version_num')::int >= 120000 THEN
        PERFORM set_config('log_transaction_sample_rate', '0', false);
    END IF;
END
$$;

BEGIN;

-- The statements of this transaction are not written to the server log - not as statements
-- (log_statement), not when one of them fails (log_min_error_statement), not as slow statements
-- and not as a sample of them (log_min_duration_sample, PostgreSQL 13 and later). Superuser
-- settings, valid until COMMIT / ROLLBACK only.
SET LOCAL log_statement = 'none';
SET LOCAL log_min_error_statement = 'panic';
SET LOCAL log_min_duration_statement = -1;
DO $$
BEGIN
    IF current_setting('server_version_num')::int >= 130000 THEN
        PERFORM set_config('log_min_duration_sample', '-1', true);
    END IF;
END
$$;

-- [role] Creates the role and STOPS when it already exists: the whole transaction is rolled back,
-- so no password, setting or grant of a role you did not mean - e.g. the one of another
-- component - is touched. For an existing role use "galbridge.sh grants --reset-password" (new
-- password) or "--grants-only" (password unchanged); they replace this block.
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'galbridge') THEN
        RAISE EXCEPTION 'role "galbridge" already exists: nothing was changed (see --reset-password and --grants-only of the grants tool)';
    END IF;
    CREATE ROLE galbridge WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT PASSWORD 'CHANGE_ME_RANDOM_PASSWORD';
END
$$;
-- [/role]

-- Role limits (size the connection limit to the PHP-FPM pm.max_children of Roundcube;
-- "galbridge.sh grants --connection-limit=N")
ALTER ROLE galbridge WITH CONNECTION LIMIT 25;
ALTER ROLE galbridge SET default_transaction_read_only = on;
ALTER ROLE galbridge SET statement_timeout = '5s';
ALTER ROLE galbridge SET idle_in_transaction_session_timeout = '10s';
-- Roundcube sends statements with the values inlined, so the statements of this role contain what
-- users search for. Keep them out of the server log, whatever the server-wide settings are:
ALTER ROLE galbridge SET log_statement = 'none';
ALTER ROLE galbridge SET log_min_duration_statement = -1;
ALTER ROLE galbridge SET log_min_error_statement = 'panic';
-- ... and out of the SAMPLES a server can log independently of the three settings above: a share
-- of the statements above a duration (log_min_duration_sample, PostgreSQL 13 and later) and all
-- statements of a share of the transactions (log_transaction_sample_rate, 12 and later). Older
-- servers do not know these settings.
DO $$
BEGIN
    IF current_setting('server_version_num')::int >= 130000 THEN
        ALTER ROLE galbridge SET log_min_duration_sample = -1;
    END IF;
    IF current_setting('server_version_num')::int >= 120000 THEN
        ALTER ROLE galbridge SET log_transaction_sample_rate = 0;
    END IF;
END
$$;

GRANT CONNECT ON DATABASE vmail TO galbridge;
GRANT USAGE ON SCHEMA public TO galbridge;

-- The table "forwardings" also holds the PRIVATE forwarding targets of every mailbox
-- (is_forwarding rows, often external addresses). The role gets no access to that table. It reads
-- this view, which has only the three kinds of rows GAL Bridge needs: per-user alias addresses
-- (is_alias), members of alias accounts (is_list; needed for the "membersonly" policies) and
-- mailing lists (is_maillist). The view runs with the rights of its owner and is a security
-- barrier (no function of the caller sees a row before the filter). It is re-created on every run.
-- Before an iRedMail upgrade that alters the forwardings table (PostgreSQL refuses to change the
-- type of a column a view uses) run  DROP VIEW galbridge_forwardings;  and afterwards
-- "galbridge.sh grants --grants-only".
CREATE OR REPLACE VIEW galbridge_forwardings WITH (security_barrier = true) AS
    SELECT address, forwarding, domain, is_alias, is_list, is_maillist, active
      FROM forwardings
     WHERE is_alias = 1 OR is_list = 1 OR is_maillist = 1;
REVOKE ALL ON galbridge_forwardings FROM PUBLIC;
-- the grants of GAL Bridge 1.0.0 gave the role the table itself: taken away (nothing happens for
-- a role that never had it)
REVOKE ALL ON forwardings FROM galbridge;

-- Required: persons, domains, per-user alias addresses and list/alias membership flags
GRANT SELECT (username, name, domain, active, enabledeliver, expired) ON mailbox TO galbridge;
GRANT SELECT (domain, active, backupmx, expired) ON domain TO galbridge;
GRANT SELECT (address, forwarding, domain, is_alias, is_list, is_maillist, active) ON galbridge_forwardings TO galbridge;

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

COMMIT;
