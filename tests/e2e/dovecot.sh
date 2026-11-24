#!/usr/bin/env bash
#
# GAL Bridge - Dovecot 2.4 IMAP server for the E2E and browser suites (TEST ONLY).
#
#   dovecot.sh install    install the test configuration, (re)start Dovecot, verify an IMAP LOGIN
#   dovecot.sh status     show what is installed and try an IMAP LOGIN for every test user
#   dovecot.sh remove     remove everything "install" created and stop Dovecot
#
# What "install" creates (and "remove" deletes again), nothing else:
#   /etc/dovecot/conf.d/99-galbridge-e2e.conf   the test configuration (Dovecot 2.4 syntax)
#   /etc/dovecot/galbridge-e2e.passwd           passwd-file with the test users (SHA512-CRYPT)
#   /var/lib/galbridge-e2e/mail                 maildirs of the test users
#   system user and group "galbridge-e2e"       owner of the maildirs (mail_uid / mail_gid)
#
# The configuration turns the local Dovecot into a test-only server: it listens on 127.0.0.1:143
# only (no IMAPS, no other protocol), TLS is off, cleartext LOGIN is allowed (loopback only), the
# system PAM passdb is skipped for every user (no system account can log in) and the only users are
# the vmail fixture users (tests/fixtures/vmail/seed.sql) with the password "testpass":
#   jan.kowalski@example.com, zofia.zolkiewska@example.com, lukasz.slowik@example.com,
#   anna.nowak@example.com, bob@example.org
#
# Never run this on a server that uses Dovecot for real mail.
#
# @license GPL-3.0-or-later
set -euo pipefail

CONF=/etc/dovecot/conf.d/99-galbridge-e2e.conf
PASSWD=/etc/dovecot/galbridge-e2e.passwd
DATA=/var/lib/galbridge-e2e
MAIL="$DATA/mail"
ACCOUNT=galbridge-e2e
MARKER='# GAL Bridge E2E - TEST ONLY - managed by tests/e2e/dovecot.sh'
USERS=(jan.kowalski@example.com zofia.zolkiewska@example.com lukasz.slowik@example.com anna.nowak@example.com bob@example.org)
PASSWORD=testpass

die() { echo "dovecot.sh: $*" >&2; exit 1; }

require_root() { [ "$(id -u)" -eq 0 ] || die "must run as root"; }

# refuse to touch a file that this script did not create
owned() { [ ! -e "$1" ] || head -n 1 "$1" | grep -qxF "$MARKER"; }

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
  owned "$CONF" || die "$CONF exists and was not created by this script"
  owned "$PASSWD" || die "$PASSWD exists and was not created by this script"

  if ! getent passwd "$ACCOUNT" >/dev/null; then
    useradd --system --user-group --no-create-home --home-dir "$DATA" --shell /usr/sbin/nologin "$ACCOUNT"
  fi
  install -d -m 0755 -o root -g root "$DATA"
  install -d -m 0750 -o "$ACCOUNT" -g "$ACCOUNT" "$MAIL"

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
  systemctl restart dovecot
  wait_port
  for u in "${USERS[@]}"; do imap_login "$u"; done
  echo "Dovecot test server ready on 127.0.0.1:143 (users: ${USERS[*]}; password: $PASSWORD)"
}

do_status() {
  for f in "$CONF" "$PASSWD" "$MAIL"; do
    if [ -e "$f" ]; then echo "present: $f"; else echo "missing: $f"; fi
  done
  getent passwd "$ACCOUNT" >/dev/null && echo "present: user $ACCOUNT" || echo "missing: user $ACCOUNT"
  echo "dovecot: $(systemctl is-active dovecot 2>/dev/null || true)"
  local rc=0
  for u in "${USERS[@]}"; do imap_login "$u" || rc=1; done
  return $rc
}

do_remove() {
  require_root
  owned "$CONF" || die "$CONF was not created by this script; not removed"
  owned "$PASSWD" || die "$PASSWD was not created by this script; not removed"
  # without the test configuration the distribution default would listen on every interface
  systemctl stop dovecot 2>/dev/null || true
  rm -f "$CONF" "$PASSWD"
  rm -rf "$MAIL"
  rmdir "$DATA" 2>/dev/null || true
  if getent passwd "$ACCOUNT" >/dev/null; then userdel "$ACCOUNT"; fi
  if getent group "$ACCOUNT" >/dev/null; then groupdel "$ACCOUNT"; fi
  echo "removed the Dovecot test configuration, users and maildirs; Dovecot stopped"
}

case "${1:-}" in
  install) do_install ;;
  status) do_status ;;
  remove) do_remove ;;
  *) echo "usage: $0 install|status|remove" >&2; exit 2 ;;
esac
