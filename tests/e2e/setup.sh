#!/usr/bin/env bash
#
# GAL Bridge - E2E environment (TEST ONLY).
#
# Prepares a Roundcube instance with the plugin and starts the PHP built-in web server:
#
#   setup.sh <roundcube_dir> <php_binary> <vmail db: mysql|pgsql> <http_port>
#
#   - a run directory tests/e2e/run/<roundcube>-<php>-<db>/ (config, SQLite Roundcube database,
#     logs, temp) - ROUNDCUBE_CONFIG_DIR points there, the Roundcube tree is not modified apart
#     from the plugin link <roundcube_dir>/plugins/galbridge -> this repository;
#   - Roundcube talks IMAP to the Dovecot test server on 127.0.0.1:143 (tests/e2e/dovecot.sh);
#     imap_host is a single host without placeholders, so the plugin derives the allowed IMAP host
#     (127.0.0.1) itself - galbridge_imap_hosts stays empty, as in a default installation;
#   - the plugin reads the throw-away vmail fixture database (tests/fixtures/vmail/load.sh) through
#     the read-only role built from SQL/grants.*.sql:
#       GALBRIDGE_TEST_MYSQL_DSN (default mysql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test)
#       GALBRIDGE_TEST_PGSQL_DSN (default pgsql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test)
#   - plugin options go to ROUNDCUBE_CONFIG_DIR/galbridge.inc.php (Roundcube loads it when the
#     plugin directory has no config.inc.php); GALBRIDGE_EXTRA_CONFIG is appended to it verbatim.
#
# Everything is checked before anything is changed (no server stopped, no directory removed, no link
# written while a refusal is still possible):
#   - it refuses to run as root (exit 2): Roundcube runs with the caller's rights, on a test
#     configuration with the login rate limit off - run it as an unprivileged user, or set
#     GALBRIDGE_E2E_ALLOW_ROOT=1 in a disposable container;
#   - it refuses a Roundcube tree that is configured (config/config.inc.php: an installation, not an
#     unpacked release) unless GALBRIDGE_E2E_ON_CONFIGURED_TREE=1;
#   - plugins/galbridge is created when it is missing, kept when it already links to this checkout,
#     replaced when it is a dangling link; a link to anything else is refused unless
#     GALBRIDGE_E2E_REPLACE_LINK=1, and a directory is never replaced. <run>/plugin-link.state records
#     what was done ("created", "kept", "replaced <old target>"): the suites put it back at the end
#     (tests/e2e/harness.py) and stop only the web server this script started.
#
# Prints RUN=<run dir> and URL=<base url>.
#
# @license GPL-3.0-or-later
set -euo pipefail

[ $# -eq 4 ] || { echo "usage: $0 <roundcube_dir> <php_binary> <mysql|pgsql> <http_port>" >&2; exit 2; }
if [ "$(id -u)" -eq 0 ] && [ "${GALBRIDGE_E2E_ALLOW_ROOT:-}" != 1 ]; then
  echo "refusing to run Roundcube and Chromium as root (they run with the caller's rights); use an unprivileged user, or GALBRIDGE_E2E_ALLOW_ROOT=1 in a disposable container" >&2
  exit 2
fi
RC="$(realpath "$1")"; PHP="$2"; DB="$3"; PORT="$4"
PLUGIN="$(cd "$(dirname "$0")/../.." && pwd)"
[ -f "$RC/program/include/iniset.php" ] || { echo "not a Roundcube directory: $RC" >&2; exit 1; }
[ -d "$RC/vendor" ] || { echo "Roundcube without vendor/ (use a complete release): $RC" >&2; exit 1; }
[[ "$PORT" =~ ^[0-9]{2,5}$ ]] || { echo "invalid port $PORT" >&2; exit 1; }
command -v "$PHP" >/dev/null || { echo "PHP binary not found: $PHP" >&2; exit 1; }
if [ -e "$PLUGIN/config.inc.php" ]; then
  # it would take precedence over the run configuration written below
  echo "$PLUGIN/config.inc.php exists: move it away before running the E2E suite" >&2; exit 1
fi

case "$DB" in
  mysql) VMAIL_DSN="${GALBRIDGE_TEST_MYSQL_DSN:-mysql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test}" ;;
  pgsql) VMAIL_DSN="${GALBRIDGE_TEST_PGSQL_DSN:-pgsql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test}" ;;
  *) echo "unknown vmail database $DB (mysql|pgsql)" >&2; exit 1 ;;
esac
case "$VMAIL_DSN" in *"'"*|*\\*) echo "unsupported character in the DSN" >&2; exit 1 ;; esac

if [ -e "$RC/config/config.inc.php" ] && [ "${GALBRIDGE_E2E_ON_CONFIGURED_TREE:-}" != 1 ]; then
  echo "$RC is a configured Roundcube installation (config/config.inc.php): the suites link the plugin into it and serve it on a port - use an unpacked release tarball (GALBRIDGE_E2E_ON_CONFIGURED_TREE=1 overrides)" >&2
  exit 1
fi

# The plugin link: decided here, written below (after the last refusal).
LINK="$RC/plugins/galbridge"
if [ -L "$LINK" ]; then
  OLD_TARGET="$(readlink "$LINK")"
  if [ ! -e "$LINK" ]; then
    LINK_STATE="replaced $OLD_TARGET"           # a dangling link
  elif [ "$(realpath "$LINK")" = "$(realpath "$PLUGIN")" ]; then
    LINK_STATE=kept
  elif [ "${GALBRIDGE_E2E_REPLACE_LINK:-}" = 1 ]; then
    LINK_STATE="replaced $OLD_TARGET"
  else
    echo "$LINK points to $(realpath "$LINK"), not to this checkout; not replaced (GALBRIDGE_E2E_REPLACE_LINK=1 replaces it, and the suites put it back afterwards)" >&2
    exit 1
  fi
elif [ -e "$LINK" ]; then
  echo "$LINK exists and is not a symbolic link; not replaced" >&2
  exit 1
else
  LINK_STATE=created
fi

RUN="$PLUGIN/tests/e2e/run/$(basename "$RC")-$(basename "$PHP")-$DB"

# Stop the web server of an earlier run of THIS run directory - and only that one: the process named
# in its pid file, when it still is a PHP built-in server for this port. Nothing else is ever killed;
# a port that somebody else uses makes the setup fail below instead.
stop_own_server() {
  local pidfile="$1" pid cmd
  [ -f "$pidfile" ] || return 0
  pid="$(tr -cd '0-9' < "$pidfile")"
  [ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 0
  cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline")"
  case "$cmd" in
    *" -S 127.0.0.1:$PORT "*"$RC/public_html"*) kill "$pid" 2>/dev/null || true ;;
  esac
}
for old in "$PLUGIN"/tests/e2e/run/*/php-server.pid; do stop_own_server "$old"; done
for _ in $(seq 1 25); do (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null || break; sleep 0.2; done
if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
  echo "port $PORT on 127.0.0.1 is in use by another program: choose another port" >&2; exit 1
fi

rm -rf "$RUN"; mkdir -p "$RUN"/{temp,logs}
chmod 0700 "$RUN"

# plugin link, as decided above (-n: never follow an existing link into the repository)
if [ "$LINK_STATE" != kept ]; then
  ln -sfn "$PLUGIN" "$RC/plugins/galbridge"
fi
printf '%s\n' "$LINK_STATE" > "$RUN/plugin-link.state"

DES_KEY="TESTONLY$(head -c 24 /dev/urandom | base64 | tr -dc A-Za-z0-9 | head -c 16)"
cat > "$RUN/config.inc.php" <<EOF
<?php
// GAL Bridge E2E - TEST ONLY (generated by tests/e2e/setup.sh)
\$config = [];
\$config['db_dsnw'] = 'sqlite:///$RUN/roundcube.db?mode=0600';
\$config['imap_host'] = '127.0.0.1:143';
\$config['smtp_host'] = '127.0.0.1:1';
\$config['support_url'] = '';
\$config['des_key'] = '$DES_KEY';
\$config['cipher_method'] = 'AES-256-CBC';
\$config['plugins'] = ['galbridge'];
\$config['skin'] = 'elastic';
\$config['language'] = 'en_US';
\$config['temp_dir'] = '$RUN/temp/';
\$config['log_dir'] = '$RUN/logs/';
\$config['log_driver'] = 'file';
\$config['session_lifetime'] = 60;
\$config['enable_installer'] = false;
\$config['enable_spellcheck'] = false;
\$config['login_rate_limit'] = 0;
\$config['create_default_folders'] = false;
EOF

cat > "$RUN/galbridge.inc.php" <<EOF
<?php
// GAL Bridge E2E - TEST ONLY plugin configuration (generated by tests/e2e/setup.sh)
\$config = [];
\$config['galbridge_db_dsn'] = '$VMAIL_DSN';
EOF
if [ -n "${GALBRIDGE_EXTRA_CONFIG:-}" ]; then printf '%s\n' "$GALBRIDGE_EXTRA_CONFIG" >> "$RUN/galbridge.inc.php"; fi

export ROUNDCUBE_CONFIG_DIR="$RUN/"
cp "$RC/config/defaults.inc.php" "$RUN/defaults.inc.php"
"$PHP" -l "$RUN/config.inc.php" > /dev/null
"$PHP" -l "$RUN/galbridge.inc.php" > /dev/null

# the SQLite driver initialises an empty database file with the core schema itself
cd "$RC"
"$PHP" -r 'define("INSTALL_PATH", getcwd()."/"); require "program/include/clisetup.php"; rcube::get_instance()->get_dbh()->query("SELECT 1");' > /dev/null
[ -s "$RUN/roundcube.db" ] || { echo "Roundcube database not initialised" >&2; exit 1; }

# web server. OPcache is off: the suites change galbridge.inc.php between requests and the
# cli-server SAPI would serve a cached copy.
nohup env ROUNDCUBE_CONFIG_DIR="$RUN/" "$PHP" -d variables_order=EGPCS -d display_errors=stderr -d opcache.enable=0 \
  -S "127.0.0.1:$PORT" -t "$RC/public_html" > "$RUN/php-server.log" 2>&1 < /dev/null &
echo $! > "$RUN/php-server.pid"
for _ in $(seq 1 50); do (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null && break; sleep 0.2; done
echo "RUN=$RUN"
echo "URL=http://127.0.0.1:$PORT/"
