#!/usr/bin/env python3
"""
GAL Bridge - what the E2E and the browser suite share about the machine they run on (TEST ONLY).

  refuse_root()       Roundcube (PHP's built-in web server) and Chromium run with the rights of the
                      caller: as root a flaw of either would be root's. The suites refuse euid 0 with
                      exit code 2 unless GALBRIDGE_E2E_ALLOW_ROOT=1 says the machine is disposable.
  cleanup(run, rc, port)
                      stops the PHP web server of the run - only when the process named in its pid file
                      still is "php -S 127.0.0.1:<port>" serving <rc>/public_html, as tests/e2e/setup.sh
                      checks it - and puts the plugin link back as setup.sh found it
                      (run/plugin-link.state): a link setup.sh created is removed, a link it replaced on
                      request (GALBRIDGE_E2E_REPLACE_LINK=1) points to its old target again, a link that
                      was there already stays; nothing is touched when the link no longer points here.

@license GPL-3.0-or-later
"""
import contextlib
import os
import signal
import sys

PLUGIN = os.path.realpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
REFUSAL = ("refusing to run Roundcube and Chromium as root (they run with the caller's rights); "
           "use an unprivileged user, or GALBRIDGE_E2E_ALLOW_ROOT=1 in a disposable container")


def refuse_root():
    if os.geteuid() == 0 and os.environ.get('GALBRIDGE_E2E_ALLOW_ROOT') != '1':
        print(REFUSAL, file=sys.stderr)
        sys.exit(2)


def own_server(run, rc, port):
    """The pid of the PHP web server of this run, or None (never another process)."""
    try:
        with open(os.path.join(run, 'php-server.pid')) as f:
            pid = int(''.join(ch for ch in f.read() if ch.isdigit()) or '0')
        with open('/proc/%d/cmdline' % pid, 'rb') as f:
            cmd = f.read().replace(b'\0', b' ').decode('utf-8', 'replace')
    except (OSError, ValueError):
        return None
    if pid > 1 and (' -S 127.0.0.1:%d ' % port) in cmd and (os.path.realpath(rc) + '/public_html') in cmd:
        return pid
    return None


def cleanup(run, rc, port):
    pid = own_server(run, rc, port)
    if pid is not None:
        with contextlib.suppress(OSError):
            os.kill(pid, signal.SIGTERM)
    restore_link(run, rc)


def restore_link(run, rc):
    link = os.path.join(os.path.realpath(rc), 'plugins', 'galbridge')
    try:
        with open(os.path.join(run, 'plugin-link.state')) as f:
            state = f.read().strip()
    except OSError:
        return
    if not os.path.islink(link) or os.path.realpath(link) != PLUGIN:
        return          # changed by somebody else since: left as it is
    if state == 'created':
        os.unlink(link)
    elif state.startswith('replaced '):
        target = state[len('replaced '):]
        tmp = link + '.galbridge-e2e'
        os.symlink(target, tmp)
        os.replace(tmp, link)
