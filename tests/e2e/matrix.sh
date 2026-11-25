#!/usr/bin/env bash
#
# GAL Bridge - E2E matrix (TEST ONLY). Runs tests/e2e/test_e2e.py sequentially (the Dovecot test
# mailboxes and the vmail databases are shared) for every Roundcube x PHP x vmail-database
# combination below that exists on this machine. Entries starting with / are Roundcube directories
# (e.g. an unpacked official release tarball), anything else is a version under rc_base_dir.
#
#   matrix.sh [rc_base_dir]     (default /opt/rcrun, containing 1.7.0 and 1.7.7 with vendor/)
#
# GAL Bridge supports the whole Roundcube 1.7 branch: the matrix covers its oldest release (1.7.0)
# and the newest one known to this release of the plugin (1.7.7). After a new 1.7.x release, add its
# directory and run the suite against it:  matrix.sh  with the entries below changed, or directly
#   python3 tests/e2e/test_e2e.py --rc /path/to/roundcubemail-1.7.N --php php8.4 --db mysql
#
# Requirements: tests/e2e/dovecot.sh install; tests/fixtures/vmail/load.sh mysql|pgsql. Run it as an
# unprivileged user (it refuses root, like the suites: GALBRIDGE_E2E_ALLOW_ROOT=1 in a disposable
# container only) with the fixture access of docs/TESTING.md section 2. A combination is green only
# when its run exits with 0 and reports "0 failed, 0 skipped": a skipped case proves nothing.
#
# @license GPL-3.0-or-later
set -u
if [ "$(id -u)" -eq 0 ] && [ "${GALBRIDGE_E2E_ALLOW_ROOT:-}" != 1 ]; then
  echo "refusing to run Roundcube and Chromium as root (they run with the caller's rights); use an unprivileged user, or GALBRIDGE_E2E_ALLOW_ROOT=1 in a disposable container" >&2
  exit 2
fi
BASE="${1:-/opt/rcrun}"
HERE="$(cd "$(dirname "$0")" && pwd)"
LOG="$HERE/run/matrix-$(date +%Y%m%d-%H%M%S)"; mkdir -p "$LOG"
# the oldest and the newest release of the supported branch; the supported PHP branches tested
# locally x both iRedMail SQL editions (CI covers 8.1 - 8.5 in PHPUnit)
runs=(
  # rc     php     vmail db
  "1.7.0 php8.1 mysql"
  "1.7.0 php8.1 pgsql"
  "1.7.0 php8.4 mysql"
  "1.7.0 php8.4 pgsql"
  "1.7.7 php8.1 mysql"
  "1.7.7 php8.1 pgsql"
  "1.7.7 php8.4 mysql"
  "1.7.7 php8.4 pgsql"
  "1.7.7 php8.5 mysql"
  "1.7.7 php8.5 pgsql"
)
total=0; bad=0
for r in "${runs[@]}"; do
  read -r ver php db <<< "$r"
  case "$ver" in /*) rc="$ver"; tag="release-$(basename "$ver" | sed 's/roundcubemail-//')" ;; *) rc="$BASE/$ver"; tag="$ver" ;; esac
  # a missing combination is a failure, never a silent skip
  [ -d "$rc" ] || { echo "MISSING $rc (not present)" | tee -a "$LOG/summary.txt"; total=$((total+1)); bad=$((bad+1)); continue; }
  command -v "$php" >/dev/null || { echo "MISSING $php (not installed)" | tee -a "$LOG/summary.txt"; total=$((total+1)); bad=$((bad+1)); continue; }
  name="$tag-$php-$db"
  code=0
  python3 "$HERE/test_e2e.py" --rc "$rc" --php "$php" --db "$db" --port 8181 > "$LOG/$name.log" 2>&1 || code=$?
  res=$(tail -1 "$LOG/$name.log")
  printf '%-24s %s\n' "$name" "$res" | tee -a "$LOG/summary.txt"
  total=$((total+1))
  case "$res" in *" 0 failed, 0 skipped"*) [ "$code" -eq 0 ] || bad=$((bad+1)) ;; *) bad=$((bad+1)) ;; esac
done
echo "MATRIX: $((total-bad))/$total combinations green (logs: $LOG)" | tee -a "$LOG/summary.txt"
[ "$total" -gt 0 ] && [ "$bad" -eq 0 ]
