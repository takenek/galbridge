#!/usr/bin/env python3
"""
GAL Bridge - changes to the TEST-ONLY vmail fixture from the E2E and the browser suite.

Some behaviour can only be shown with rows the seed does not have (a mailbox that is disabled while
its session is open, display names that turn into an address once they are decoded). This module
runs such statements as the administrator of the throw-away fixture database - the access that
tests/fixtures/vmail/load.sh uses:

    MYSQL_ADMIN   default "mariadb -u root", then "mysql -u root"
    PSQL_ADMIN    default "psql -U postgres -h 127.0.0.1 -w", then "runuser -u postgres -- psql"

Only a local database whose name contains "test" is ever touched. Without administrator access the
calling case is skipped (Unavailable).

@license GPL-3.0-or-later
"""
import contextlib
import os
import re
import shlex
import subprocess


class Unavailable(Exception):
    """No administrator access to the fixture database: the case cannot run."""


_WORKING = {}


def target(dsn):
    """(engine, database) of a fixture DSN; refuses anything but a local *test* database."""
    m = re.match(r'^(mysql|pgsql)://[^@/]+@(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?/([A-Za-z0-9_]+)(?:\?.*)?$', dsn)
    if not m:
        raise Unavailable('not a local fixture DSN')
    engine, _, database = m.groups()
    if not re.match(r'^[a-z][a-z0-9_]*test[a-z0-9_]*$', database):
        raise Unavailable('refusing to change the database %r: its name does not contain "test"' % database)
    return engine, database


def _run(cmd, stdin=None):
    env = dict(os.environ, PGCONNECT_TIMEOUT='5')
    try:
        r = subprocess.run(cmd, input=stdin, capture_output=True, timeout=60, env=env)
    except (OSError, subprocess.TimeoutExpired) as e:
        return 127, '', str(e)
    return r.returncode, r.stdout.decode('utf-8', 'replace'), r.stderr.decode('utf-8', 'replace')


def _admin(engine):
    if engine in _WORKING:
        return _WORKING[engine]
    configured = os.environ.get('PSQL_ADMIN' if engine == 'pgsql' else 'MYSQL_ADMIN', '').strip()
    if configured:
        candidates = [shlex.split(configured)]
    elif engine == 'pgsql':
        candidates = [['psql', '-U', 'postgres', '-h', '127.0.0.1', '-w'], ['runuser', '-u', 'postgres', '--', 'psql']]
    else:
        candidates = [['mariadb', '-u', 'root'], ['mysql', '-u', 'root']]
    _WORKING[engine] = None
    for cmd in candidates:
        probe = cmd + (['-X', '-q', '-t', '-d', 'postgres', '-c', 'SELECT 1'] if engine == 'pgsql' else ['-N', '-e', 'SELECT 1'])
        code, out, _ = _run(probe)
        if code == 0 and out.strip() == '1':
            _WORKING[engine] = cmd
            break
    return _WORKING[engine]


def execute(dsn, statements):
    """Run SQL statements (portable, without a trailing semicolon) against the fixture database."""
    engine, database = target(dsn)
    cmd = _admin(engine)
    if cmd is None:
        raise Unavailable('no administrator access to the %s test server (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)' % engine)
    sql = ';\n'.join(statements) + ';\n'
    if engine == 'pgsql':
        code, _, err = _run(cmd + ['-X', '-q', '-v', 'ON_ERROR_STOP=1', '-d', database], stdin=sql.encode())
    else:
        code, _, err = _run(cmd + ['--default-character-set=utf8mb4', database], stdin=sql.encode())
    if code != 0:
        raise AssertionError('changing the fixture failed: %s' % err.strip()[-500:])


@contextlib.contextmanager
def altered(dsn, apply, revert):
    """Apply statements for the duration of the block; the revert statements always run afterwards."""
    target(dsn)
    if _admin(target(dsn)[0]) is None:
        raise Unavailable('no administrator access to the fixture database (MYSQL_ADMIN / PSQL_ADMIN as for load.sh)')
    try:
        execute(dsn, apply)
        yield
    finally:
        execute(dsn, revert)


# Display names that only become an address once somebody decodes them (audit U-04): an HTML
# character reference (Roundcube's recipient widget inserts names as HTML) and an RFC 2047
# encoded-word (decoded by the mail client of a recipient). TEST-ONLY rows of example.com.
SPOOF_ROWS = {
    'spoof.entity@example.com': 'ceo&#64;bank.example',
    'spoof.named@example.com': 'ceo&commat;bank.example',
    'spoof.word@example.com': '=?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=',
}
SPOOF_APPLY = [
    'INSERT INTO mailbox (username, name, domain, maildir) VALUES ' + ', '.join(
        "('%s', '%s', 'example.com', 'example.com/s/p/o/%s')" % (a, n, a.split('@')[0]) for a, n in SPOOF_ROWS.items()),
]
SPOOF_REVERT = ["DELETE FROM mailbox WHERE username LIKE 'spoof.%@example.com'"]
