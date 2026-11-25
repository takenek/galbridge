#!/usr/bin/env bash
#
# GAL Bridge - Dovecot 2.4 IMAP server for the E2E and browser suites (TEST ONLY).
#
#   dovecot.sh install    install the test configuration, (re)start Dovecot, verify an IMAP LOGIN
#   dovecot.sh status     show what is installed and try an IMAP LOGIN for every test user
#   dovecot.sh remove     remove everything "install" created and give Dovecot back the state it
#                         had before the first install (running with its own configuration, or not)
#
# What "install" creates (and "remove" deletes again), nothing else:
#   /etc/dovecot/conf.d/99-galbridge-e2e.conf   the test configuration (Dovecot 2.4 syntax)
#   /etc/dovecot/galbridge-e2e.passwd           passwd-file with the test users (SHA512-CRYPT)
#   /var/lib/galbridge-e2e/mail                 maildirs of the test users
#   /var/lib/galbridge-e2e/pre-install.state    whether Dovecot ran before the first install
#   system user and group "galbridge-e2e"       owner of the maildirs (mail_uid / mail_gid)
#
# The service is managed with systemctl where systemd runs (/run/systemd/system), otherwise directly:
# "dovecot" starts it and "doveadm stop" stops it (a container, the CI job "e2e").
#
# The configuration turns the local Dovecot into a test-only server: it listens on 127.0.0.1:143
# only (no IMAPS, no other protocol), TLS is off, cleartext LOGIN is allowed (loopback only), the
# system PAM passdb is skipped for every user (no system account can log in) and the only users are
# the vmail fixture users (tests/fixtures/vmail/seed.sql) with the password "testpass":
#   jan.kowalski@example.com, zofia.zolkiewska@example.com, lukasz.slowik@example.com,
#   anna.nowak@example.com, bob@example.org
#
# Never run this on a server that uses Dovecot for real mail. "install" refuses a machine that
# looks like a mail server (iRedMail, a vmail account or spool, Dovecot SQL/LDAP configuration,
# Postfix lookup tables); "remove" touches nothing - not even the Dovecot service - unless the
# test configuration of this script is installed.
#
# @license GPL-3.0-or-later
set -euo pipefail

CONF=/etc/dovecot/conf.d/99-galbridge-e2e.conf
PASSWD=/etc/dovecot/galbridge-e2e.passwd
DATA=/var/lib/galbridge-e2e
MAIL="$DATA/mail"
STATE="$DATA/pre-install.state"
ACCOUNT=galbridge-e2e
MARKER='# GAL Bridge E2E - TEST ONLY - managed by tests/e2e/dovecot.sh'
USERS=(jan.kowalski@example.com zofia.zolkiewska@example.com lukasz.slowik@example.com anna.nowak@example.com bob@example.org)
PASSWORD=testpass

die() { echo "dovecot.sh: $*" >&2; exit 1; }

require_root() { [ "$(id -u)" -eq 0 ] || die "must run as root"; }

# refuse to touch a file that this script did not create (a missing file is not in the way)
owned() { [ ! -e "$1" ] || head -n 1 "$1" | grep -qxF "$MARKER"; }

# is the file there AND ours?
ours() { [ -f "$1" ] && head -n 1 "$1" | grep -qxF "$MARKER"; }

# signs of a machine that handles real mail: the test configuration would turn its Dovecot into a
# cleartext, test-users-only server
mail_server_signs() {
  local signs=()
  [ -e /etc/iredmail-release ] && signs+=("/etc/iredmail-release")
  getent passwd vmail >/dev/null && signs+=("the account vmail")
  [ -d /var/vmail ] && signs+=("/var/vmail")
  compgen -G '/etc/dovecot/dovecot-*sql*' >/dev/null && signs+=("Dovecot SQL configuration")
  compgen -G '/etc/dovecot/dovecot-ldap*' >/dev/null && signs+=("Dovecot LDAP configuration")
  { [ -d /etc/postfix/mysql ] || [ -d /etc/postfix/pgsql ] || [ -d /etc/postfix/ldap ]; } && signs+=("Postfix lookup tables")
  [ "${#signs[@]}" -eq 0 ] || (IFS=,; echo "${signs[*]}")
}

imap_login() {
  # raw IMAP: greeting, LOGIN, LOGOUT (python3 is required by the E2E suite anyway)
  python3 - "$1" "$PASSWORD" <<'PY'
import socket, sys
user, password = sys.argv[1], sys.argv[2]
s = socket.create_connection(('127.0.0.1', 143), timeout=40)
f = s.makefile('rwb')
greeting = f.readline().decode()
if not greeting.startswith('* OK'):
    sys.exit('no IMAP greeting: %r' % greeting)
f.write(('a1 LOGIN "%s" "%s"\r\n' % (user, password)).encode()); f.flush()
while True:
    line = f.readline().decode()
    if not line:
        sys.exit('connection closed')
    if line.startswith('a1 '):
        break
f.write(b'a2 LOGOUT\r\n'); f.flush()
s.close()
if not line.startswith('a1 OK'):
    sys.exit('LOGIN %s failed: %s' % (user, line.strip()))
print('IMAP LOGIN %s: OK' % user)
PY
}

# the Dovecot service, with or without systemd
with_systemd() { [ -d /run/systemd/system ]; }
running() {
  if with_systemd; then
    systemctl is-active --quiet dovecot
  else
    local base pid
    base="$(doveconf -h base_dir 2>/dev/null || echo /run/dovecot)"
    pid="$(cat "$base/master.pid" 2>/dev/null || true)"
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
  fi
}
service_start() { if with_systemd; then systemctl start dovecot; else dovecot; fi; }
service_stop() {
  if with_systemd; then
    systemctl stop dovecot 2>/dev/null || true
  elif running; then
    doveadm stop 2>/dev/null || true
    for _ in $(seq 1 50); do running || return 0; sleep 0.2; done
  fi
}
service_restart() { if with_systemd; then systemctl restart dovecot; else service_stop; dovecot; fi; }

wait_port() {
  for _ in $(seq 1 50); do
    if (exec 3<>/dev/tcp/127.0.0.1/143) 2>/dev/null; then return 0; fi
    sleep 0.2
  done
  die "Dovecot does not listen on 127.0.0.1:143"
}

write_conf() {
  cat > "$1" <<EOF
$MARKER
# Do not edit: remove with "tests/e2e/dovecot.sh remove". Never use this on a real mail server.

# IMAP on 127.0.0.1:143 only
protocols {
  imap = yes
}
listen = 127.0.0.1
service imap-login {
  inet_listener imap {
    listen = 127.0.0.1
    port = 143
  }
  inet_listener imaps {
    port = 0
  }
}

# test only: no TLS, cleartext LOGIN (loopback only, see listen above)
ssl = no
auth_allow_cleartext = yes
auth_mechanisms = plain login

# only the fixture users: the system PAM passdb is skipped for everyone
passdb pam {
  username_filter = !*
}
passdb galbridge-e2e {
  driver = passwd-file
  passwd_file_path = $PASSWD
}
userdb galbridge-e2e {
  driver = passwd-file
  passwd_file_path = $PASSWD
}

mail_uid = $ACCOUNT
mail_gid = $ACCOUNT
mail_driver = maildir
mail_home = $MAIL/%{user | domain}/%{user | username}
mail_path = ~/Maildir
mail_inbox_path =
EOF
}

do_install() {
  require_root
  command -v dovecot >/dev/null || die "Dovecot is not installed (package dovecot-imapd)"
  case "$(dovecot --version)" in 2.4.*) ;; *) die "Dovecot 2.4 required, found $(dovecot --version)";; esac
  local signs
  signs="$(mail_server_signs)"
  [ -z "$signs" ] || die "this machine looks like a mail server ($signs): refusing to reconfigure its Dovecot for tests"

  owned "$CONF" || die "$CONF exists and was not created by this script"
  owned "$PASSWD" || die "$PASSWD exists and was not created by this script"

  if ! getent passwd "$ACCOUNT" >/dev/null; then
    useradd --system --user-group --no-create-home --home-dir "$DATA" --shell /usr/sbin/nologin "$ACCOUNT"
  fi
  install -d -m 0755 -o root -g root "$DATA"
  install -d -m 0750 -o "$ACCOUNT" -g "$ACCOUNT" "$MAIL"
  # the state before the FIRST install only: a repeated install must not record its own server
  if ! ours "$CONF" && [ ! -e "$STATE" ]; then
    local before=no
    if running; then before=yes; fi
    (umask 077; printf 'active=%s\n' "$before" > "$STATE")
  fi

  local tmp
  tmp="$(mktemp)"
  {
    echo "$MARKER"
    for u in "${USERS[@]}"; do
      printf '%s:%s::::::\n' "$u" "$(doveadm pw -s SHA512-CRYPT -p "$PASSWORD")"
    done
  } > "$tmp"
  install -m 0640 -o root -g dovecot "$tmp" "$PASSWD"
  write_conf "$tmp"
  install -m 0644 -o root -g root "$tmp" "$CONF"
  rm -f "$tmp"

  if ! doveconf -n > /dev/null; then
    rm -f "$CONF"
    die "doveconf rejected the configuration (removed again)"
  fi
  service_restart
  wait_port
  for u in "${USERS[@]}"; do imap_login "$u"; done
  echo "Dovecot test server ready on 127.0.0.1:143 (users: ${USERS[*]}; password: $PASSWORD)"
}

do_status() {
  for f in "$CONF" "$PASSWD" "$MAIL"; do
    if [ -e "$f" ]; then echo "present: $f"; else echo "missing: $f"; fi
  done
  getent passwd "$ACCOUNT" >/dev/null && echo "present: user $ACCOUNT" || echo "missing: user $ACCOUNT"
  if running; then echo "dovecot: running"; else echo "dovecot: not running"; fi
  if [ -f "$STATE" ]; then echo "before the first install: $(cat "$STATE")"; fi
  local rc=0
  for u in "${USERS[@]}"; do imap_login "$u" || rc=1; done
  return $rc
}

do_remove() {
  require_root
  owned "$CONF" || die "$CONF was not created by this script; not removed"
  owned "$PASSWD" || die "$PASSWD was not created by this script; not removed"
  if ours "$CONF"; then
    # only a Dovecot that runs OUR test configuration is stopped; it is started again - now with
    # its own configuration - only when it ran before the first install. A Dovecot that this script
    # never configured is left alone.
    service_stop
    rm -f "$CONF"
    if [ "$(cat "$STATE" 2>/dev/null)" = 'active=yes' ]; then
      service_start
      echo "removed the Dovecot test configuration; Dovecot runs again with its own configuration, as before the install"
    elif [ -f "$STATE" ]; then
      echo "removed the Dovecot test configuration; Dovecot stopped, as before the install"
    else
      echo "removed the Dovecot test configuration; Dovecot stopped (no state was recorded before the install)"
    fi
  else
    echo "the Dovecot test configuration is not installed: the Dovecot service was not touched"
  fi
  if ours "$PASSWD"; then rm -f "$PASSWD"; fi
  # the maildirs and the account exist only when this script created them (the account name and
  # the directory are specific to it)
  if [ -d "$MAIL" ] && [ "$(stat -c %U "$MAIL" 2>/dev/null)" = "$ACCOUNT" ]; then rm -rf "$MAIL"; fi
  rm -f "$STATE"
  rmdir "$DATA" 2>/dev/null || true
  if getent passwd "$ACCOUNT" >/dev/null; then userdel "$ACCOUNT"; fi
  if getent group "$ACCOUNT" >/dev/null; then groupdel "$ACCOUNT"; fi
  echo "test users and maildirs removed (if there were any)"
}

case "${1:-}" in
  install) do_install ;;
  status) do_status ;;
  remove) do_remove ;;
  *) echo "usage: $0 install|status|remove" >&2; exit 2 ;;
esac
