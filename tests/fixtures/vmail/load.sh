#!/usr/bin/env bash
#
# GAL Bridge - load the TEST-ONLY vmail fixture into a throw-away MariaDB/MySQL or PostgreSQL
# database (TEST ONLY - never point this at a production server).
#
#   load.sh mysql [db_name]      admin access: $MYSQL_ADMIN (default: "mariadb -u root")
#   load.sh pgsql [db_name]      admin access: $PSQL_ADMIN  (default: "psql -U postgres -h 127.0.0.1";
#                                local peer auth: PSQL_ADMIN="runuser -u postgres -- psql")
#
# What it does:
#   1. (re)creates the database (default galbridge_vmail_test) - it DROPS a database of that name,
#   2. loads the UPSTREAM iRedMail 1.8.11 schema (schema.mysql.sql / schema.pgsql.sql) and seed.sql,
#   3. (re)creates the read-only role from the plugin's OWN grant templates (SQL/grants.*.sql),
#      renamed to $GALBRIDGE_RO_USER with password $GALBRIDGE_RO_PASSWORD - so the test suite
#      proves that the documented grants are sufficient (and that nothing beyond them is needed).
#
# Safety (the script drops a database and a role, and creates an account whose password is
# published in this repository):
#   - the database name must contain "test"; the role name must contain "_ro" or "test" and must
#     not be the name of an iRedMail, Roundcube or database-server role;
#   - it refuses a server that has the databases of a mail server (vmail, roundcubemail, ...);
#     GALBRIDGE_LOAD_ON_MAIL_SERVER=1 overrides that for a deliberately shared test machine.
#
# Prints the DSN for GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN.
#
# @license GPL-3.0-or-later
set -euo pipefail

ENGINE="${1:?usage: load.sh mysql|pgsql [db_name]}"
DB="${2:-galbridge_vmail_test}"
RO_USER="${GALBRIDGE_RO_USER:-galbridge_ro}"
RO_PASS="${GALBRIDGE_RO_PASSWORD:-testonly-ro}"
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

not_a_mail_server() {
  # $1 = number of mail-server databases found on the target server
  [[ "$1" =~ ^[0-9]+$ ]] || die "cannot inspect the target server (administrator access failed)"
  if [ "$1" -gt 0 ] && [ "${GALBRIDGE_LOAD_ON_MAIL_SERVER:-}" != "1" ]; then
    die "the target server has the databases of a mail server ($MAIL_DATABASES): refusing to create a test account with a published password there (GALBRIDGE_LOAD_ON_MAIL_SERVER=1 overrides)"
  fi
}

grants() {
  # rename role/password/database in the documented template; the test role also gets the optional,
  # commented-out personal-data grants (the suites exercise every field) and no connection limit (a
  # PHPUnit process keeps dozens of connections open until garbage collection; a Roundcube request
  # opens at most one)
  sed -e "s/^-- GRANT /GRANT /" \
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

case "$ENGINE" in
  mysql)
    [ "${#RO_VERIFIER}" -eq 41 ] || die "openssl is needed to compute the password verifier of the test role"
    read -r -a ADMIN <<< "${MYSQL_ADMIN:-mariadb -u root}"
    not_a_mail_server "$("${ADMIN[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ($MAIL_DATABASES)" 2>/dev/null || true)"
    "${ADMIN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    "${ADMIN[@]}" -e "DROP USER IF EXISTS '$RO_USER'@'localhost'; DROP USER IF EXISTS '$RO_USER'@'%';"
    "${ADMIN[@]}" "$DB" < "$HERE/schema.mysql.sql"
    "${ADMIN[@]}" "$DB" < "$HERE/seed.sql"
    grants "$SQLDIR/grants.mysql.sql" | "${ADMIN[@]}"
    # CI connects over TCP from the runner (host '%'); mirror the grants there
    grants "$SQLDIR/grants.mysql.sql" | sed -e "s/'$RO_USER'@'localhost'/'$RO_USER'@'%'/g" | "${ADMIN[@]}"
    echo "GALBRIDGE_TEST_MYSQL_DSN=mysql://$RO_USER:$RO_PASS@127.0.0.1/$DB"
    ;;
  pgsql)
    read -r -a ADMIN <<< "${PSQL_ADMIN:-psql -U postgres -h 127.0.0.1}"
    not_a_mail_server "$("${ADMIN[@]}" -X -q -A -t -d postgres -c "SELECT COUNT(*) FROM pg_database WHERE datname IN ($MAIL_DATABASES)" 2>/dev/null || true)"
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB ENCODING 'UTF8' TEMPLATE template0"
    # the template CREATES the role and refuses an existing one: drop the test role of an earlier
    # run (it can only hold privileges in the database that was just dropped)
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" -c "DO \$\$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname = '$RO_USER') THEN EXECUTE 'DROP OWNED BY $RO_USER'; EXECUTE 'DROP ROLE $RO_USER'; END IF; END \$\$"
    # stdin instead of -f: the server-side user may not be able to read the repository directory
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" < "$HERE/schema.pgsql.sql" > /dev/null
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" < "$HERE/seed.sql" > /dev/null
    grants "$SQLDIR/grants.postgres.sql" | "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" > /dev/null
    echo "GALBRIDGE_TEST_PGSQL_DSN=pgsql://$RO_USER:$RO_PASS@127.0.0.1/$DB"
    ;;
  *) die "unknown engine $ENGINE" ;;
esac
