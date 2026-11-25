#!/usr/bin/env bash
#
# GAL Bridge - load the TEST-ONLY vmail fixture into a throw-away MariaDB/MySQL or PostgreSQL
# database (TEST ONLY - never point this at a production server).
#
#   load.sh mysql [db_name]      admin access: $MYSQL_ADMIN (default: "mariadb -u root")
#   load.sh pgsql [db_name]      admin access: $PSQL_ADMIN  (default: "psql -U postgres -h 127.0.0.1";
#                                local peer auth: PSQL_ADMIN="runuser -u postgres -- psql")
#   load.sh drop mysql|pgsql [db_name]
#                                removes what loading created: the database and the test role
#                                (MariaDB/MySQL: its accounts 'localhost', '127.0.0.1' and '%')
#
# What loading does:
#   1. (re)creates the database (default galbridge_vmail_test) - it DROPS a database of that name,
#   2. loads the UPSTREAM iRedMail 1.8.11 schema (schema.mysql.sql / schema.pgsql.sql) and seed.sql,
#   3. (re)creates the read-only role from the plugin's OWN grant templates (SQL/grants.*.sql),
#      renamed to $GALBRIDGE_RO_USER with password $GALBRIDGE_RO_PASSWORD - so the test suite
#      proves that the documented grants are sufficient (and that nothing beyond them is needed).
#      MariaDB/MySQL: the account 'localhost' of the template and a copy for '127.0.0.1' (TCP over
#      loopback, also with skip-name-resolve). The account statement carries the VERIFIER of the
#      password, as in the template - except on a MySQL server without an ACTIVE
#      mysql_native_password plugin (MySQL 8.4 and later by default; 9.x does not ship it), where
#      that statement cannot run: there the test role gets the clear-text form that "galbridge.sh
#      grants --clear-text-password" writes (IDENTIFIED BY '<password>'). An account for EVERY host
#      ('%') - with the password published here - is created only with GALBRIDGE_LOAD_ANY_HOST=1,
#      for a throw-away server that the clients reach from elsewhere (the CI service container sees
#      the runner through the Docker bridge); a reload without it removes such an account.
#   4. PostgreSQL, only with GALBRIDGE_FIXTURE_USER=<an existing role>: grants that role the rows of
#      the test database and nothing else - CONNECT on the database, USAGE on its schema public,
#      SELECT, INSERT, UPDATE, DELETE on all its tables and USAGE, SELECT, UPDATE on all its
#      sequences. This is the fixture access of the unprivileged user that runs the E2E and browser
#      suites (docs/TESTING.md section 2). The grants live in the database and go with it, so they
#      are made after every (re)load; the role itself is never created or dropped here.
#      (MariaDB/MySQL: a grant ON <db>.* outlives a reload - give it once, as section 2 shows.)
#
# Safety (the script drops a database and a role, and creates an account whose password is
# published in this repository):
#   - the database name must contain "test"; the role name must contain "_ro" or "test" and must
#     not be the name of an iRedMail, Roundcube or database-server role;
#   - GALBRIDGE_FIXTURE_USER must not be the test role, a superuser, a member of pg_read_all_data or
#     pg_write_all_data (that access is cluster-wide), a reserved name (public, pg_*, ...) or the
#     name of an iRedMail, Roundcube or database-server role;
#   - it refuses a server that has the databases of a mail server (vmail, roundcubemail, ...);
#     GALBRIDGE_LOAD_ON_MAIL_SERVER=1 overrides that for a deliberately shared test machine.
#
# Prints the DSN for GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN.
#
# @license GPL-3.0-or-later
set -euo pipefail

MODE=load
if [ "${1:-}" = drop ]; then MODE=drop; shift; fi
ENGINE="${1:?usage: load.sh [drop] mysql|pgsql [db_name]}"
DB="${2:-galbridge_vmail_test}"
RO_USER="${GALBRIDGE_RO_USER:-galbridge_ro}"
RO_PASS="${GALBRIDGE_RO_PASSWORD:-testonly-ro}"
# PostgreSQL: an existing role that gets the rows of the test database (step 4 above)
FIXTURE_USER="${GALBRIDGE_FIXTURE_USER:-}"
# the MariaDB/MySQL template takes the VERIFIER of the password (its mysql_native_password hash),
# never the password itself
RO_VERIFIER=""
if command -v openssl >/dev/null 2>&1; then
  RO_VERIFIER="*$(printf '%s' "$RO_PASS" | openssl dgst -sha1 -binary | openssl dgst -sha1 -hex | sed 's/^.* //' | tr 'a-f' 'A-F')"
fi
HERE="$(cd "$(dirname "$0")" && pwd)"
SQLDIR="$(cd "$HERE/../../../SQL" && pwd)"
# databases that exist on an iRedMail server (and on most servers that run Roundcube)
MAIL_DATABASES="'vmail', 'iredadmin', 'iredapd', 'amavisd', 'roundcubemail', 'roundcube', 'sogo'"

die() { echo "load.sh: $*" >&2; exit 1; }

[[ "$DB" =~ ^[a-z][a-z0-9_]{0,40}$ ]] || die "invalid db name"
[[ "$DB" == *test* ]] || die "the database name must contain \"test\" (this script DROPS the database): $DB"
[[ "$RO_USER" =~ ^[a-z][a-z0-9_]{0,30}$ ]] || die "invalid role name"
[[ "$RO_USER" == *_ro* || "$RO_USER" == *test* ]] || die "the role name must contain \"_ro\" or \"test\" (this script DROPS the role): $RO_USER"
case "$RO_USER" in
  galbridge|vmail|vmailadmin|roundcube|sogo|iredadmin|iredapd|amavisd|fail2ban|root|postgres|mysql|mariadb|pg_*)
    die "refusing the role name of another component: $RO_USER" ;;
esac
[[ "$RO_PASS" =~ ^[A-Za-z0-9._-]{8,64}$ ]] || die "invalid test password"
if [ -n "$FIXTURE_USER" ]; then
  [[ "$FIXTURE_USER" =~ ^[a-z][a-z0-9_]{0,30}$ ]] || die "invalid role name in GALBRIDGE_FIXTURE_USER"
  [ "$FIXTURE_USER" != "$RO_USER" ] || die "GALBRIDGE_FIXTURE_USER must not be the read-only test role $RO_USER (the suites prove that it can change nothing)"
  # names PostgreSQL reserves (GRANT ... TO public grants to every role), administrators and the
  # roles of other components
  case "$FIXTURE_USER" in
    public|none|user|current_user|current_role|session_user|pg_*|galbridge|vmail|vmailadmin|roundcube|sogo|iredadmin|iredapd|amavisd|fail2ban|root|postgres|mysql|mariadb)
      die "refusing GALBRIDGE_FIXTURE_USER=$FIXTURE_USER: a reserved name, an administrator or the role of another component" ;;
  esac
fi

not_a_mail_server() {
  # $1 = number of mail-server databases found on the target server
  [[ "$1" =~ ^[0-9]+$ ]] || die "cannot inspect the target server (administrator access failed)"
  if [ "$1" -gt 0 ] && [ "${GALBRIDGE_LOAD_ON_MAIL_SERVER:-}" != "1" ]; then
    die "the target server has the databases of a mail server ($MAIL_DATABASES): refusing to create a test account with a published password there (GALBRIDGE_LOAD_ON_MAIL_SERVER=1 overrides)"
  fi
}

# MariaDB/MySQL: how the account statement carries the password (load:mysql decides)
AUTH_FORM=verifier

grants() {
  # rename role/password/database in the documented template; the test role also gets the optional,
  # commented-out personal-data grants (the suites exercise every field) and no connection limit (a
  # PHPUnit process keeps dozens of connections open until garbage collection; a Roundcube request
  # opens at most one)
  local form=()
  if [ "$AUTH_FORM" = clear-text ]; then
    # the account statement of "grants --clear-text-password" (Tool::roleStatements), for the
    # published test password - before the verifier placeholder is filled in below
    form=(-e "s/IDENTIFIED WITH mysql_native_password AS 'CHANGE_ME_PASSWORD_VERIFIER'/IDENTIFIED BY '$RO_PASS'/")
  fi
  sed ${form[@]+"${form[@]}"} \
      -e "s/^-- GRANT /GRANT /" \
      -e "s/CONNECTION LIMIT 25/CONNECTION LIMIT -1/" \
      -e "s/MAX_USER_CONNECTIONS 25/MAX_USER_CONNECTIONS 0/" \
      -e "s/CHANGE_ME_RANDOM_PASSWORD/$RO_PASS/g" \
      -e "s/CHANGE_ME_PASSWORD_VERIFIER/$RO_VERIFIER/g" \
      -e "s/'galbridge'@/'$RO_USER'@/g" \
      -e "s/\bgalbridge\b/$RO_USER/g" \
      -e "s/\bvmail\./$DB./g" \
      -e "s/DATABASE vmail /DATABASE $DB /g" \
      "$1"
}

case "$MODE:$ENGINE" in
  drop:mysql)
    read -r -a ADMIN <<< "${MYSQL_ADMIN:-mariadb -u root}"
    not_a_mail_server "$("${ADMIN[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ($MAIL_DATABASES)" 2>/dev/null || true)"
    "${ADMIN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; DROP USER IF EXISTS '$RO_USER'@'localhost', '$RO_USER'@'127.0.0.1', '$RO_USER'@'%';"
    echo "load.sh: dropped the database $DB and the role $RO_USER"
    ;;
  drop:pgsql)
    read -r -a ADMIN <<< "${PSQL_ADMIN:-psql -U postgres -h 127.0.0.1}"
    not_a_mail_server "$("${ADMIN[@]}" -X -q -A -t -d postgres -c "SELECT COUNT(*) FROM pg_database WHERE datname IN ($MAIL_DATABASES)" 2>/dev/null || true)"
    # the role holds privileges in its test database only: they go with the database
    "${ADMIN[@]}" -X -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $DB" -c "DROP ROLE IF EXISTS $RO_USER"
    echo "load.sh: dropped the database $DB and the role $RO_USER"
    ;;
  load:mysql)
    [ "${#RO_VERIFIER}" -eq 41 ] || die "openssl is needed to compute the password verifier of the test role"
    read -r -a ADMIN <<< "${MYSQL_ADMIN:-mariadb -u root}"
    not_a_mail_server "$("${ADMIN[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ($MAIL_DATABASES)" 2>/dev/null || true)"
    # MySQL 8.4 and later do not load mysql_native_password unless the administrator enables it (9.x
    # does not ship it): CREATE USER ... IDENTIFIED WITH mysql_native_password AS '<verifier>' fails
    # there, as it does for an administrator who runs the default grants output (README 2.2 tells
    # them to use --clear-text-password). MariaDB, MySQL up to 8.0 and a MySQL server with the
    # plugin active keep the verifier form of the template.
    case "$("${ADMIN[@]}" -N -B -e "SELECT CONCAT(VERSION() LIKE '%MariaDB%', ':', (SELECT COUNT(*) FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'mysql_native_password' AND PLUGIN_STATUS = 'ACTIVE'))" 2>/dev/null || true)" in
      0:0) AUTH_FORM=clear-text
           echo "load.sh: MySQL without an active mysql_native_password plugin - the test role gets the clear-text form of grants --clear-text-password" >&2 ;;
      1:*|0:1) ;;
      *) die "cannot read the authentication plugins of the target server (administrator access failed)" ;;
    esac
    "${ADMIN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    "${ADMIN[@]}" -e "DROP USER IF EXISTS '$RO_USER'@'localhost', '$RO_USER'@'127.0.0.1', '$RO_USER'@'%';"
    "${ADMIN[@]}" "$DB" < "$HERE/schema.mysql.sql"
    "${ADMIN[@]}" "$DB" < "$HERE/seed.sql"
    # applied as README 2.2 tells the administrator: client commands off (--binary-mode)
    grants "$SQLDIR/grants.mysql.sql" | "${ADMIN[@]}" --binary-mode
    # the DSN below connects over TCP to 127.0.0.1: with skip-name-resolve the server matches that
    # address, not 'localhost' - mirror the grants there
    grants "$SQLDIR/grants.mysql.sql" | sed -e "s/'$RO_USER'@'localhost'/'$RO_USER'@'127.0.0.1'/g" | "${ADMIN[@]}" --binary-mode
    if [ "${GALBRIDGE_LOAD_ANY_HOST:-}" = 1 ]; then
      echo "load.sh: WARNING - an account for every host ('$RO_USER'@'%') with the test password: only on a throw-away server" >&2
      grants "$SQLDIR/grants.mysql.sql" | sed -e "s/'$RO_USER'@'localhost'/'$RO_USER'@'%'/g" | "${ADMIN[@]}" --binary-mode
    fi
    if [ -n "$FIXTURE_USER" ]; then
      echo "load.sh: GALBRIDGE_FIXTURE_USER applies to PostgreSQL only - on MariaDB/MySQL grant SELECT, INSERT, UPDATE, DELETE ON $DB.* once (docs/TESTING.md section 2)" >&2
    fi
    echo "GALBRIDGE_TEST_MYSQL_DSN=mysql://$RO_USER:$RO_PASS@127.0.0.1/$DB"
    ;;
  load:pgsql)
    read -r -a ADMIN <<< "${PSQL_ADMIN:-psql -U postgres -h 127.0.0.1}"
    not_a_mail_server "$("${ADMIN[@]}" -X -q -A -t -d postgres -c "SELECT COUNT(*) FROM pg_database WHERE datname IN ($MAIL_DATABASES)" 2>/dev/null || true)"
    if [ -n "$FIXTURE_USER" ]; then
      # checked before anything is dropped: the role exists and holds no access beyond what it gets
      # here (pg_read_all_data / pg_write_all_data reach every table of every database)
      case "$("${ADMIN[@]}" -X -q -A -t -d postgres -c "SELECT CASE WHEN r.rolsuper THEN 'superuser' WHEN EXISTS (SELECT 1 FROM pg_roles p WHERE p.rolname IN ('pg_read_all_data', 'pg_write_all_data') AND pg_has_role(r.oid, p.oid, 'MEMBER')) THEN 'all-data' ELSE 'ok' END FROM pg_roles r WHERE r.rolname = '$FIXTURE_USER'" 2>/dev/null || true)" in
        ok) ;;
        superuser) die "GALBRIDGE_FIXTURE_USER=$FIXTURE_USER is a superuser: name an ordinary role" ;;
        all-data) die "GALBRIDGE_FIXTURE_USER=$FIXTURE_USER is a member of pg_read_all_data or pg_write_all_data (every table of every database): revoke that first" ;;
        *) die "the role GALBRIDGE_FIXTURE_USER=$FIXTURE_USER does not exist or cannot be checked: create it first (CREATE ROLE $FIXTURE_USER LOGIN) - load.sh never creates it" ;;
      esac
    fi
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB ENCODING 'UTF8' TEMPLATE template0"
    # the template CREATES the role and refuses an existing one: drop the test role of an earlier
    # run (it can only hold privileges in the database that was just dropped)
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" -c "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = '$RO_USER') THEN EXECUTE 'DROP OWNED BY $RO_USER'; EXECUTE 'DROP ROLE $RO_USER'; END IF; END \$\$"
    # stdin instead of -f: the server-side user may not be able to read the repository directory
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" < "$HERE/schema.pgsql.sql" > /dev/null
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" < "$HERE/seed.sql" > /dev/null
    # (the grant set drops and re-creates the view galbridge_forwardings: a NOTICE on the first run)
    grants "$SQLDIR/grants.postgres.sql" | "${ADMIN[@]}" -X -q -v ON_ERROR_STOP=1 -d "$DB" > /dev/null
    if [ -n "$FIXTURE_USER" ]; then
      # after the grant set (it re-creates the view): the rows of this database, nothing else
      "${ADMIN[@]}" -X -q -v ON_ERROR_STOP=1 -d "$DB" \
        -c "GRANT CONNECT ON DATABASE $DB TO $FIXTURE_USER" \
        -c "GRANT USAGE ON SCHEMA public TO $FIXTURE_USER" \
        -c "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO $FIXTURE_USER" \
        -c "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO $FIXTURE_USER"
      echo "load.sh: $FIXTURE_USER may read and change the rows of $DB (its tables and sequences; no other database)" >&2
    fi
    echo "GALBRIDGE_TEST_PGSQL_DSN=pgsql://$RO_USER:$RO_PASS@127.0.0.1/$DB"
    ;;
  *) die "unknown engine $ENGINE" ;;
esac
