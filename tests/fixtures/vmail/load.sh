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
#   1. (re)creates the database (default galbridge_vmail_test),
#   2. loads the UPSTREAM iRedMail 1.8.11 schema (schema.mysql.sql / schema.pgsql.sql) and seed.sql,
#   3. creates the read-only role from the plugin's OWN grant templates (SQL/grants.*.sql), renamed
#      to $GALBRIDGE_RO_USER with password $GALBRIDGE_RO_PASSWORD - so the test suite proves that
#      the documented grants are sufficient (and that nothing beyond them is needed).
#
# Prints the DSN for GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN.
#
# @license GPL-3.0-or-later
set -euo pipefail

ENGINE="${1:?usage: load.sh mysql|pgsql [db_name]}"
DB="${2:-galbridge_vmail_test}"
RO_USER="${GALBRIDGE_RO_USER:-galbridge_ro}"
RO_PASS="${GALBRIDGE_RO_PASSWORD:-testonly-ro}"
HERE="$(cd "$(dirname "$0")" && pwd)"
SQLDIR="$(cd "$HERE/../../../SQL" && pwd)"

[[ "$DB" =~ ^[a-z][a-z0-9_]{0,40}$ ]] || { echo "invalid db name" >&2; exit 1; }
[[ "$RO_USER" =~ ^[a-z][a-z0-9_]{0,30}$ ]] || { echo "invalid role name" >&2; exit 1; }
[[ "$RO_PASS" =~ ^[A-Za-z0-9._-]{8,64}$ ]] || { echo "invalid test password" >&2; exit 1; }

grants() {
  # rename role/password/database in the documented template; the test role also gets the optional,
  # commented-out personal-data grants (the suites exercise every field) and no connection limit (a
  # PHPUnit process keeps dozens of connections open until garbage collection; a Roundcube request
  # opens at most one)
  sed -e "s/^-- GRANT /GRANT /" \
      -e "s/CONNECTION LIMIT 25/CONNECTION LIMIT -1/" \
      -e "s/CHANGE_ME_RANDOM_PASSWORD/$RO_PASS/g" \
      -e "s/'galbridge'@/'$RO_USER'@/g" \
      -e "s/\bgalbridge\b/$RO_USER/g" \
      -e "s/\bvmail\./$DB./g" \
      -e "s/DATABASE vmail /DATABASE $DB /g" \
      "$1"
}

case "$ENGINE" in
  mysql)
    read -r -a ADMIN <<< "${MYSQL_ADMIN:-mariadb -u root}"
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
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB ENCODING 'UTF8' TEMPLATE template0"
    # stdin instead of -f: the server-side user may not be able to read the repository directory
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" < "$HERE/schema.pgsql.sql" > /dev/null
    "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" < "$HERE/seed.sql" > /dev/null
    grants "$SQLDIR/grants.postgres.sql" | "${ADMIN[@]}" -q -v ON_ERROR_STOP=1 -d "$DB" > /dev/null
    echo "GALBRIDGE_TEST_PGSQL_DSN=pgsql://$RO_USER:$RO_PASS@127.0.0.1/$DB"
    ;;
  *) echo "unknown engine $ENGINE" >&2; exit 1 ;;
esac
