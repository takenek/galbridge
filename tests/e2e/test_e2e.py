#!/usr/bin/env python3
"""
GAL Bridge - end-to-end suite (TEST ONLY).

Drives a real Roundcube 1.7.7 + GAL Bridge instance over HTTP (tests/e2e/rc.py) exactly like the
browser does, with the vmail fixture (tests/fixtures/vmail/seed.sql) in MariaDB/MySQL or PostgreSQL
and the Dovecot test server (tests/e2e/dovecot.sh) as IMAP backend. Every assertion is made on
Roundcube's own responses: env.address_sources / env.contactgroups of the address book page, the
"this.add_contact_row(...)" / "ksearch_query_results(...)" / "replace_group_recipients(...)"
commands of the JSON responses, the vCard downloads and the "To" field of the compose page.

    python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql --port 8181

The environment is (re)created by tests/e2e/setup.sh at the start (--no-setup reuses a running
one). Requirements: Dovecot test server (tests/e2e/dovecot.sh install), the throw-away vmail
databases (tests/fixtures/vmail/load.sh), python3-requests.

Output: one "PASS|FAIL|SKIP <case>" line per case and "E2E: N passed, M failed, K skipped".
Exit code 1 when any case failed. --only <regex> runs a subset (cases marked "always" still run).

@license GPL-3.0-or-later
"""
import argparse
import contextlib
import email.utils
import imaplib
import os
import re
import subprocess
import sys
import time
import traceback

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import requests  # noqa: E402

from rc import Roundcube, parse_vcards  # noqa: E402

PLUGIN = os.path.abspath(os.path.join(HERE, '..', '..'))
IMAP_PASS = 'testpass'
SRC = 'galbridge_domain'
GLOBAL = 'galbridge_global'

# =========================================================================== fixture model
# (tests/fixtures/vmail/seed.sql; visibility rules: docs/ARCHITECTURE.md sections 4-8)

JAN = 'jan.kowalski@example.com'
ZOFIA = 'zofia.zolkiewska@example.com'
ANNA = 'anna.nowak@example.com'
LUKASZ = 'lukasz.slowik@example.com'
BOB = 'bob@example.org'
USERS = [JAN, ZOFIA, ANNA, LUKASZ, BOB]

ESCAPES = {'.': 'D', '@': 'A', '-': 'H', '_': 'U', '+': 'P'}


def enc(value):
    """docs/ARCHITECTURE.md section 9 (IdCodec::encode)."""
    out = []
    for b in value.encode('utf-8'):
        c = chr(b)
        if 'a' <= c <= 'z' or '0' <= c <= '9':
            out.append(c)
        elif c in ESCAPES:
            out.append(ESCAPES[c])
        else:
            out.append('X%02X' % b)
    return ''.join(out)


def cid(address, kind='u'):
    return '%s_%s' % (kind, enc(address))


def gid(key, tag='d', kind='p'):
    return '%s%s_%s' % (tag, kind, enc(key))


COM_PERSONS = {
    'postmaster@example.com': 'Postmaster',
    JAN: 'Jan Kowalski',
    ZOFIA: 'Zofia Żółkiewska',
    ANNA: 'Anna Nowak',          # mailbox.name is empty: first + last name
    LUKASZ: 'Łukasz Słowik',
    'forwarder@example.com': 'Forward Only',
    'pct_user@example.com': '100% Wildcard_Test',
}
COM_ALIASES = {
    'team@example.com': 'Zespół sprzedaży', 'everyone@example.com': 'Everyone', 'board@example.com': 'Zarząd',
    'managers@example.com': 'Managers', 'audit@example.com': 'Audit', 'internal@example.com': 'Internal only',
    'legacy@example.com': 'Legacy policy',
}
COM_LISTS = {'newsletter@example.com': 'Newsletter firmy', 'staff@example.com': 'Staff discussion', 'mods@example.com': 'Announcements'}


def view(aliases, lists, persons=None):
    """expected contact IDs -> (address, display name)."""
    out = {}
    for a, n in (persons if persons is not None else COM_PERSONS).items():
        out[cid(a)] = (a, n)
    for a in aliases:
        out[cid(a, 'a')] = (a, COM_ALIASES.get(a) or ORG_NAMES[a])
    for a in lists:
        out[cid(a, 'l')] = (a, COM_LISTS.get(a) or ORG_NAMES[a])
    return out


ORG_NAMES = {'orgteam@example.org': 'Org Team', 'orginternal@example.org': 'Org Internal', 'orglist@example.org': 'Org list'}

# accesspolicy (ARCHITECTURE.md section 6) applied to the fixture
EXPECTED = {
    JAN: view(['board@example.com', 'everyone@example.com', 'internal@example.com', 'legacy@example.com', 'team@example.com'],
              ['newsletter@example.com']),
    ANNA: view(['audit@example.com', 'everyone@example.com', 'internal@example.com', 'managers@example.com', 'team@example.com'],
               ['newsletter@example.com']),
    ZOFIA: view(['everyone@example.com', 'internal@example.com', 'team@example.com'], ['newsletter@example.com', 'staff@example.com']),
    LUKASZ: view(['board@example.com', 'everyone@example.com', 'internal@example.com', 'team@example.com'],
                 ['mods@example.com', 'newsletter@example.com']),
    BOB: view(['orginternal@example.org', 'orgteam@example.org'], ['orglist@example.org'], {BOB: 'Bob Smith'}),
}

# department groups (persons only; LOWER(TRIM(department)) keys)
COM_GROUPS = {'IT': [LUKASZ], 'Księgowość': [ZOFIA], 'Sprzedaż': [JAN, ANNA]}
ORG_GROUPS = {'Sales': [BOB]}
G_SALES_COM = gid('sprzedaż')
G_IT = gid('it')
G_SALES_ORG = gid('sales')

# never in any book: inactive / not delivering / expired persons, other states of aliases and lists
NEVER = [cid(a) for a in ('disabled.user@example.com', 'nodeliver@example.com', 'expired.user@example.com',
                          'klaus@xn--bcher-kva.example', 'carol@inactive.test', 'dave@backup.test', 'erin@expired.test')] + \
        [cid(a, 'a') for a in ('weird@example.com', 'empty@example.com', 'oldalias@example.com', 'expiredalias@example.com',
                               'ghost@inactive.test')] + \
        [cid(a, 'l') for a in ('retired@example.com', 'broken@example.com')]

# values that must never reach any HTTP response (secret columns, private forwarding targets,
# external list members, fields outside the default allowlist, per-user aliases with
# galbridge_user_aliases off)
CANARIES = ['SECRET-', '{SSHA512}', 'jan.private@gmail.test', 'private.destination@gmail.test', 'jan.home@outlook.test',
            'partner@external.test', '192.0.2.0/24', '600 100 200', '123 45 67', '765 43 21', '601 222 333', '1980-05-17',
            'jk@example.com', 'sprzedaz@example.com', 'jan@example.net', 'bob.smith@example.org']

# =========================================================================== runner


class Skip(Exception):
    pass


CASES = []


def case(name, always=False):
    def deco(fn):
        CASES.append((name, fn, always))
        return fn
    return deco


def check(cond, msg):
    if not cond:
        raise AssertionError(msg)


def eq(a, b, msg=''):
    if a != b:
        raise AssertionError('%s: expected %r, got %r' % (msg, b, a))


class Env:
    def __init__(self, args):
        self.args = args
        self.rc_dir = os.path.realpath(args.rc)
        self.run = os.path.join(PLUGIN, 'tests', 'e2e', 'run',
                                '%s-%s-%s' % (os.path.basename(self.rc_dir), os.path.basename(args.php), args.db))
        self.base = 'http://127.0.0.1:%d/' % args.port
        self.u = {}
        self.leaks = []
        self.log_windows = {}   # log file -> byte ranges written by cases that provoke errors on purpose


E = None  # type: Env


def leak_observer(user, r):
    try:
        text = r.text
    except Exception:  # noqa: BLE001
        return
    sent = (r.request.url or '') + ' ' + (r.request.body if isinstance(r.request.body, str) else
                                          (r.request.body or b'').decode('utf-8', 'replace'))
    sent = requests.utils.unquote(sent.replace('+', ' '))
    for c in CANARIES:
        if c in text and c not in sent:
            E.leaks.append('%s: %r in %s' % (user, c, r.request.url))


def session(user):
    if user not in E.u:
        E.u[user] = Roundcube(E.base, user).login()
    return E.u[user]


def wait_http(timeout=20):
    end = time.time() + timeout
    while time.time() < end:
        try:
            if requests.get(E.base + '?_task=login', timeout=3).status_code == 200:
                return
        except requests.RequestException:
            pass
        time.sleep(0.3)
    raise AssertionError('Roundcube did not come up on %s' % E.base)


@contextlib.contextmanager
def plugin_config(extra):
    """Temporarily append PHP lines to ROUNDCUBE_CONFIG_DIR/galbridge.inc.php (read on every request)."""
    path = os.path.join(E.run, 'galbridge.inc.php')
    with open(path) as f:
        base = f.read()
    try:
        with open(path, 'w') as f:
            f.write(base + '\n' + extra + '\n')
        yield
    finally:
        with open(path, 'w') as f:
            f.write(base)


def log_path(name):
    return os.path.join(E.run, 'logs', name + '.log')


def log_size(name):
    return os.path.getsize(log_path(name)) if os.path.exists(log_path(name)) else 0


def log_part(name, start, end):
    if not os.path.exists(log_path(name)):
        return ''
    with open(log_path(name), 'rb') as f:
        f.seek(start)
        return f.read(end - start).decode('utf-8', 'replace')


@contextlib.contextmanager
def expected_log(*codes, core_errors=None):
    """The block must write each reason code to galbridge.log. What it writes to galbridge.log (and,
    with core_errors, to Roundcube's errors.log) is exempt from case 91; core_errors(text) checks it."""
    names = ['galbridge'] + (['errors'] if core_errors else [])
    start = {n: log_size(n) for n in names}
    try:
        yield
    finally:
        for n in names:
            E.log_windows.setdefault(n, []).append((start[n], log_size(n)))
    text = log_part('galbridge', start['galbridge'], log_size('galbridge'))
    for c in codes:
        check(c in text, 'reason code %r not logged; log part: %r' % (c, text[-800:]))
    if core_errors:
        core_errors(log_part('errors', start['errors'], log_size('errors')))


def without_windows(name):
    """Content of a log file without the parts provoked on purpose (expected_log)."""
    if not os.path.exists(log_path(name)):
        return ''
    with open(log_path(name), 'rb') as f:
        raw = f.read()
    for start, end in sorted(E.log_windows.get(name, []), reverse=True):
        raw = raw[:start] + raw[end:]
    return raw.decode('utf-8', 'replace')


def sources_of(user, s=None):
    return (Roundcube.env((s or session(user)).addressbook_page(), 'address_sources') or {})


def rows(resp):
    return Roundcube.contact_rows(resp)


def row_ids(resp, strip_source=False):
    ids = [r['id'] for r in rows(resp)]
    if strip_source:
        ids = [i[:-len('-' + SRC)] if i.endswith('-' + SRC) else i for i in ids]
    return ids


def no_app_error(resp_or_text, what):
    text = resp_or_text if isinstance(resp_or_text, str) else str(resp_or_text)
    check('Application Error' not in text and 'SERVICE CURRENTLY NOT AVAILABLE' not in text,
          '%s: Roundcube error page/message: %s' % (what, text[:300]))


def list_book(user, source=SRC, **kw):
    code, resp = session(user).list_contacts(source, **kw)
    eq(code, 200, 'HTTP status of addressbook/list %s' % source)
    no_app_error(resp, 'list %s' % source)
    return resp


def ac_results(user, search, source=''):
    code, resp = session(user).autocomplete(search, source=source)
    eq(code, 200, 'HTTP status of mail/autocomplete')
    res = Roundcube.autocomplete_results(resp)
    check(res is not None, 'no ksearch_query_results in %r' % resp)
    return res


def ac_gal(user, search, source=''):
    return [r for r in ac_results(user, search, source) if r.get('source') in (SRC, GLOBAL)]


def search_ids(user, q, **kw):
    code, resp = session(user).quick_search(q, **kw)
    eq(code, 200, 'HTTP status of addressbook/search')
    no_app_error(resp, 'search %r' % q)
    return sorted(i for i in row_ids(resp) if i.endswith('-' + SRC) or i.endswith('-' + GLOBAL)), resp


def export_cards(user, **params):
    r = session(user).export(**params)
    if r.status_code == 204:
        # a GAL Bridge book refused the export: no download at all (never an empty contacts.vcf)
        eq(r.text, '', 'body of a refused export %r' % params)
        return [], ''
    eq(r.status_code, 200, 'HTTP status of export %r' % params)
    check(r.headers.get('content-type', '').startswith('text/vcard'), 'export content type %r' % r.headers.get('content-type'))
    return parse_vcards(r.text), r.text


def export_refused(user, what, **params):
    r = session(user).export(**params)
    eq(r.status_code, 204, 'refused export answers 204 No Content (%s)' % what)
    eq(r.text, '', 'refused export has no body (%s)' % what)
    check('attachment' not in r.headers.get('content-disposition', ''), 'refused export offers a download (%s)' % what)


def group_expand(user, gid_, source=SRC):
    code, resp = session(user).ajax('mail', 'group-expand', {'_source': source, '_gid': gid_})
    eq(code, 200, 'HTTP status of group-expand')
    no_app_error(resp, 'group-expand')
    calls = Roundcube.command_args(resp, 'replace_group_recipients')
    return (calls[0][1] if calls else None), resp


def recipients(value):
    return sorted(a.lower() for _, a in email.utils.getaddresses([value or '']) if a)


# =========================================================================== environment

@case('00 environment: setup.sh, Roundcube up, Dovecot accepts the test users', always=True)
def c00():
    a = E.args
    if not a.no_setup:
        r = subprocess.run([os.path.join(HERE, 'setup.sh'), a.rc, a.php, a.db, str(a.port)], capture_output=True, timeout=180)
        if r.returncode != 0:
            raise AssertionError('setup.sh failed: %s %s' % (r.stdout.decode()[-2000:], r.stderr.decode()[-2000:]))
    wait_http()
    for user in USERS:
        m = imaplib.IMAP4('127.0.0.1', 143)
        try:
            m.login(user, IMAP_PASS)
        except imaplib.IMAP4.error as e:
            raise AssertionError('Dovecot rejects %s (run tests/e2e/dovecot.sh install): %s' % (user, e))
        finally:
            with contextlib.suppress(Exception):
                m.logout()


@case('01 all five test users log in through Roundcube (IMAP 127.0.0.1:143)', always=True)
def c01():
    for user in USERS:
        session(user)


# =========================================================================== directory features

@case('02 address book page lists "Domain directory" as a read-only source with groups; the global book is absent')
def c02():
    page = session(JAN).addressbook_page()
    no_app_error(page, 'address book page')
    env = Roundcube.env_all(page)
    sources = env.get('address_sources') or {}
    check(SRC in sources, 'galbridge_domain not in env.address_sources: %r' % list(sources))
    check(GLOBAL not in sources, 'disabled global book advertised')
    entry = sources[SRC]
    eq(entry.get('id'), SRC, 'source id')
    eq(entry.get('name'), 'Domain directory', 'source name')
    eq(entry.get('readonly'), True, 'readonly flag')
    eq(entry.get('groups'), True, 'groups flag')
    eq(entry.get('autocomplete'), True, 'autocomplete flag')
    check(not entry.get('deletable'), 'directory marked deletable')
    extra = set(entry) - {'id', 'name', 'readonly', 'groups', 'autocomplete', 'undelete'}
    check(not extra, 'unexpected keys in the source entry (sent to the browser): %r' % extra)
    check(re.search(r'<li[^>]*class="addressbook readonly"[^>]*>\s*<a[^>]*rel="galbridge_domain"[^>]*>Domain directory</a>', page),
          'directory list item (class "addressbook readonly") not rendered')
    groups = {v['name']: v for v in (env.get('contactgroups') or {}).values() if v.get('source') == SRC}
    eq(sorted(groups), sorted(COM_GROUPS), 'department groups of example.com')
    for name, g in groups.items():
        eq(g['id'], gid(name.lower()), 'group id of %s (ARCHITECTURE.md section 9 encoding)' % name)
        check(re.fullmatch(r'[A-Za-z0-9_]+', g['id']), 'unsafe group id %r' % g['id'])
    check(not any(v.get('source') == GLOBAL for v in (env.get('contactgroups') or {}).values()), 'global groups listed')


@case('03 contact list: every user sees exactly the persons, alias accounts and lists the accesspolicy allows')
def c03():
    for user, expected in EXPECTED.items():
        resp = list_book(user, page=1)
        got = {r['id']: r for r in rows(resp)}
        eq(sorted(got), sorted(expected), 'contact IDs listed for %s' % user)
        for i, r in got.items():
            addr, name = expected[i]
            eq(r['data'].get('email'), addr, 'e-mail of %s' % i)
            eq(r['data'].get('name'), name, 'name of %s' % i)
            eq(r['cols'].get('name'), name.replace('&', '&amp;').replace('<', '&lt;'), 'list column of %s' % i)
            eq(r['classes'], 'person', 'row class of %s' % i)
            check(re.fullmatch(r'[ual]_[A-Za-z0-9]+', i), 'unsafe contact id %r' % i)
        bad = [i for i in NEVER if i in got]
        check(not bad, 'never-visible entries listed for %s: %r' % (user, bad))
        env = resp.get('env') or {}
        eq(env.get('readonly'), True, 'env.readonly')
        eq(env.get('pagecount'), 1, 'env.pagecount')
        rc_ = Roundcube.command_args(resp, 'set_rowcount')
        check(rc_ and str(len(expected)) in rc_[0][0], 'row count text %r does not show %d' % (rc_, len(expected)))


@case('04 groups: department groups list exactly their members (persons only), per tenant')
def c04():
    for name, members in COM_GROUPS.items():
        resp = list_book(JAN, gid=gid(name.lower()))
        eq(sorted(row_ids(resp)), sorted(cid(m) for m in members), 'members of %s' % name)
        prop = Roundcube.command_args(resp, 'set_group_prop')
        check(prop and prop[0][0] and prop[0][0].get('name') == name, 'set_group_prop for %s: %r' % (name, prop))
    page = session(BOB).addressbook_page()
    groups = {v['name']: v['id'] for v in (Roundcube.env(page, 'contactgroups') or {}).values() if v.get('source') == SRC}
    eq(groups, {'Sales': G_SALES_ORG}, 'groups of example.org')
    eq(row_ids(list_book(BOB, gid=G_SALES_ORG)), [cid(BOB)], 'members of Sales')


@case('05 paging and totals: page size 5, sort by name, three pages, beyond the end empty, bad _page clamped')
def c05():
    s = session(JAN)
    r = s.post({'_task': 'settings', '_action': 'save-prefs'},
               {'_section': 'addressbook', '_addressbook_pagesize': '5', '_addressbook_sort_col': 'name', '_framed': '1'})
    eq(r.status_code, 200, 'save-prefs status')
    try:
        seen = []
        for page, n in ((1, 5), (2, 5), (3, 3)):
            resp = list_book(JAN, page=page)
            ids = row_ids(resp)
            eq(len(ids), n, 'rows on page %d' % page)
            eq((resp.get('env') or {}).get('pagecount'), 3, 'pagecount on page %d' % page)
            txt = Roundcube.command_args(resp, 'set_rowcount')[0][0]
            check('13' in txt, 'row count text %r does not show the total 13' % txt)
            seen += ids
        eq(sorted(seen), sorted(EXPECTED[JAN]), 'union of the pages')
        eq(len(seen), len(set(seen)), 'a contact appears on two pages')
        names = [EXPECTED[JAN][i][1] for i in seen]
        check(names[0] == '100% Wildcard_Test' and names[-1] in ('Zofia Żółkiewska', 'Łukasz Słowik', 'Zespół sprzedaży', 'Zarząd'),
              'sorted by name: %r' % names)
        eq(row_ids(list_book(JAN, page=4)), [], 'rows beyond the last page')
        eq(row_ids(list_book(JAN, page=-3)), seen[:5], 'rows for _page=-3 (clamped to page 1)')
        # a user-chosen sort column outside name/firstname/surname must not become an ORDER BY
        s.post({'_task': 'settings', '_action': 'save-prefs'},
               {'_section': 'addressbook', '_addressbook_pagesize': '50', '_addressbook_sort_col': 'password', '_framed': '1'})
        eq(sorted(row_ids(list_book(JAN, page=1))), sorted(EXPECTED[JAN]), 'list with addressbook_sort_col = password')
    finally:
        s.post({'_task': 'settings', '_action': 'save-prefs'},
               {'_section': 'addressbook', '_addressbook_pagesize': '50', '_framed': '1'})
    eq(len(row_ids(list_book(JAN, page=1))), 13, 'rows after restoring the page size')


@case('06 quick search: name, accented name, e-mail; LIKE wildcards and SQL metacharacters are data')
def c06():
    def ids(q, user=JAN, **kw):
        return search_ids(user, q, **kw)[0]
    eq(ids('kow'), [cid(JAN) + '-' + SRC], 'search "kow"')
    eq(ids('Żółkiewska'), [cid(ZOFIA) + '-' + SRC], 'search "Żółkiewska"')
    eq(ids('nowak'), [cid(ANNA) + '-' + SRC], 'search "nowak" (name from first/last name)')
    eq(ids('pct_user'), [cid('pct_user@example.com') + '-' + SRC], 'search "pct_user"')
    eq(ids('Zesp', scope=SRC), [cid('team@example.com', 'a') + '-' + SRC], 'search "Zesp" scoped to the directory')
    eq(ids('%%'), [], 'search "%%" (LIKE wildcard must be escaped)')
    eq(ids("x' OR '1'='1"), [], 'search with SQL metacharacters')
    eq(ids('jk'), [], 'search for a per-user alias local part (galbridge_user_aliases off)')
    eq(ids('bob'), [], 'search for a person of another tenant')
    eq(ids('xn--bcher'), [], 'search for a person of another domain')
    found, resp = search_ids(JAN, 'example.com')
    eq(len(found), 13, 'search "example.com" matches every e-mail address of the tenant')
    check((resp.get('env') or {}).get('search_request'), 'no env.search_request after a search')
    # _headers is user input: only allowlisted fields are searched
    eq(ids('Sprzeda', headers='department'), sorted([cid(JAN) + '-' + SRC, cid(ANNA) + '-' + SRC]), 'search on department')
    eq(ids('SECRET', headers='password'), [], 'search on mailbox.password')
    eq(ids('SECRET', headers='password,name'), [], 'search on mailbox.password and name')
    eq(ids('192.0.2', headers='allow_nets,name,email'), [], 'search on mailbox.allow_nets')
    eq(ids('gmail', headers='recovery_email,email'), [], 'search on mailbox.recovery_email')
    eq(ids('600', headers='phone'), [], 'search on a field outside galbridge_fields')
    eq(ids('outlook', headers='*'), [], 'search for a private forwarding target')


@case('07 advanced search: AND of allowed fields; a field outside the allowlist matches nothing')
def c07():
    s = session(JAN)
    form = s.get(_task='addressbook', _action='search', _form=1, _framed=1).text
    no_app_error(form, 'advanced search form')

    def adv(fields):
        data = {'_adv': '1'}
        data.update({'_search_' + k: v for k, v in fields.items()})
        code, resp = s.ajax('addressbook', 'search', data, method='POST')
        eq(code, 200, 'advanced search status')
        no_app_error(resp, 'advanced search')
        return sorted(i for i in row_ids(resp) if i.endswith('-' + SRC))
    eq(adv({'email': 'zofia'}), [cid(ZOFIA) + '-' + SRC], 'email=zofia')
    eq(adv({'surname': 'kowalski', 'firstname': 'jan'}), [cid(JAN) + '-' + SRC], 'surname=kowalski AND firstname=jan')
    eq(adv({'surname': 'kowalski', 'firstname': 'anna'}), [], 'surname=kowalski AND firstname=anna')
    eq(adv({'department': 'Księgowość'}), [cid(ZOFIA) + '-' + SRC], 'department=Księgowość')
    eq(adv({'phone': '600'}), [], 'phone (not in galbridge_fields)')
    eq(adv({'password': 'SECRET'}), [], 'password')
    eq(adv({'email': 'jan', 'password': 'SECRET'}), [], 'email AND password')


@case('08 mail autocomplete: colleagues and department groups; minimum length; aliases and other tenants never match')
def c08():
    def names(search, user=JAN, source=''):
        return sorted((r['name'], r.get('type'), r.get('id')) for r in ac_gal(user, search, source))
    eq(names('kow'), [('Jan Kowalski <%s>' % JAN, 'person', cid(JAN))], 'autocomplete "kow"')
    eq(names('nowak'), [('Anna Nowak <%s>' % ANNA, 'person', cid(ANNA))], 'autocomplete "nowak"')
    eq(names('zof'), [('Zofia Żółkiewska <%s>' % ZOFIA, 'person', cid(ZOFIA))], 'autocomplete "zof"')
    eq(names('Słow'), [('Łukasz Słowik <%s>' % LUKASZ, 'person', cid(LUKASZ))], 'autocomplete "Słow"')
    eq(names('newsl'), [('Newsletter firmy <newsletter@example.com>', 'person', cid('newsletter@example.com', 'l'))],
       'autocomplete "newsl" (mailing list visible to jan)')
    eq(names('staff'), [], 'autocomplete "staff" (list jan may not post to)')
    eq(names('staff', user=ZOFIA), [('Staff discussion <staff@example.com>', 'person', cid('staff@example.com', 'l'))],
       'autocomplete "staff" for its moderator')
    got = names('sprz')
    eq(got, sorted([('Sprzedaż (2)', 'group', G_SALES_COM),
                    ('Zespół sprzedaży <team@example.com>', 'person', cid('team@example.com', 'a'))]),
       'autocomplete "sprz" (department group with head count + alias account)')
    for r in ac_gal(JAN, 'kow'):
        eq(r['source'], SRC, 'source of an autocomplete entry')
        eq(r['fields'].get('email'), JAN, 'fields.email')
    eq(names('k'), [], 'autocomplete below galbridge_min_search_length')
    eq(names('jk@example.com'), [], 'autocomplete with a per-user alias address')
    eq(names('kow', user=BOB), [], 'autocomplete of tenant A names by tenant B')
    eq(names('kow', source=SRC), names('kow'), 'autocomplete with _source=galbridge_domain')
    eq(names('x' * 200), [], 'autocomplete with an over-long term')
    env = Roundcube.env_all(session(JAN).compose())
    eq(env.get('autocomplete_min_length'), 2, 'client-side autocomplete_min_length raised to the server minimum')


@case('09 compose contact list and compose search show the directory with its groups')
def c09():
    s = session(JAN)
    code, resp = s.ajax('mail', 'list-contacts', {'_source': SRC, '_page': '1'})
    eq(code, 200, 'list-contacts status')
    no_app_error(resp, 'list-contacts')
    cmds = Roundcube.commands(resp)
    group_rows = [a[0] for n, a in cmds if n == 'add_contact_row' and str(a[0]).startswith('G')]
    contact_rows_ = [a[0] for n, a in cmds if n == 'add_contact_row' and not str(a[0]).startswith('G')]
    eq(sorted(group_rows), sorted('G' + gid(k.lower()) for k in COM_GROUPS), 'group rows in the compose list')
    eq(len(contact_rows_), 13, 'contact rows in the compose list')
    data = (resp.get('env') or {}).get('contactdata') or {}
    check(any('jan.kowalski@example.com' in str(v) for v in data.values()), 'env.contactdata lacks jan: %r' % list(data)[:5])
    code, resp = s.ajax('mail', 'search-contacts', {'_q': 'kow'})
    eq(code, 200, 'search-contacts status')
    no_app_error(resp, 'search-contacts')
    data = (resp.get('env') or {}).get('contactdata') or {}
    vals = ' '.join(str(v) for v in data.values())
    check(JAN in vals and ANNA not in vals, 'compose search "kow" contactdata: %r' % data)


@case('10 contact show, print and QR code (search-mode "ID-source" form too)')
def c10():
    s = session(JAN)
    for params in ({'_source': SRC, '_cid': cid(JAN)}, {'_source': '', '_cid': cid(JAN) + '-' + SRC}):
        r = s.get(_task='addressbook', _action='show', **params)
        eq(r.status_code, 200, 'show status')
        no_app_error(r.text, 'show')
        env = Roundcube.env_all(r.text)
        eq(env.get('cid'), cid(JAN), 'env.cid of the shown contact (%r)' % params)
        eq(env.get('readonly'), True, 'env.readonly of the shown contact')
        for needle in ('Jan', 'Kowalski', JAN, 'Sprzedaż'):
            check(needle in r.text, 'show page lacks %r' % needle)
        checked = re.findall(r'<input name="_gid\[\]"[^>]*value="([^"]+)"[^>]*checked', r.text)
        eq(checked, [G_SALES_COM], 'checked group of the contact')
        check(all('disabled' in m for m in re.findall(r'<input name="_gid\[\]"[^>]*>', r.text)), 'group checkboxes not disabled')
    r = s.get(_task='addressbook', _action='print', _source=SRC, _cid=cid(ZOFIA))
    eq(r.status_code, 200, 'print status')
    check('Żółkiewska' in r.text and ZOFIA in r.text and 'Księgowość' in r.text, 'print page content')
    r = s.get(_task='addressbook', _action='qrcode', _source=SRC, _cid=cid(JAN))
    eq(r.status_code, 200, 'qrcode status')
    check(r.headers.get('content-type', '').startswith('image/'), 'qrcode content type %r' % r.headers.get('content-type'))


@case('11 vCard export: whole book, group, search result and selected contacts')
def c11():
    cards, text = export_cards(JAN, _source=SRC)
    eq(sorted(c['emails'][0] for c in cards), sorted(a for a, _ in EXPECTED[JAN].values()), 'e-mails of the book export')
    eq(sorted(c['fn'] for c in cards), sorted(n for _, n in EXPECTED[JAN].values()), 'FN of the book export')
    check(all(len(c['emails']) == 1 for c in cards), 'more than one e-mail in a card (aliases are off)')
    for prop in ('TEL', 'BDAY', 'PHOTO'):
        check(not re.search(r'^%s[;:]' % prop, text, re.M), '%s exported although not in galbridge_fields' % prop)
    jan = [c for c in cards if c['emails'] == [JAN]][0]
    check('N:Kowalski;Jan;;;' in jan['lines'] and any(ln.startswith('X-DEPARTMENT:Sprzedaż') for ln in jan['lines']),
          'jan card: %r' % jan['lines'])
    cards, _ = export_cards(JAN, _source=SRC, _gid=G_SALES_COM)
    eq(sorted(c['emails'][0] for c in cards), sorted([JAN, ANNA]), 'group export')
    _, resp = search_ids(JAN, 'nowak')
    cards, _ = export_cards(JAN, _search=resp['env']['search_request'])
    eq([c['emails'] for c in cards], [[ANNA]], 'search export')
    cards, _ = export_cards(JAN, _source=SRC, _cid='%s,%s' % (cid(ZOFIA), cid('team@example.com', 'a')))
    eq(sorted(c['emails'][0] for c in cards), sorted([ZOFIA, 'team@example.com']), 'selected export')
    cards, _ = export_cards(JAN, _source='', _cid='%s-%s' % (cid(LUKASZ), SRC))
    eq([c['emails'] for c in cards], [[LUKASZ]], 'selected export in search mode (ID-source)')


@case('12 compose to selected contacts and to a group (mailto): exactly the selected recipients')
def c12():
    s = session(JAN)
    resp, to = s.mailto({'_cid': cid(ZOFIA), '_source': SRC})
    eq(to, 'Zofia Żółkiewska <%s>' % ZOFIA, 'To for one selected contact')
    resp, to = s.mailto({'_cid': ','.join([cid(ANNA), cid('newsletter@example.com', 'l'), cid(LUKASZ)]), '_source': SRC})
    eq(recipients(to), sorted([ANNA, 'newsletter@example.com', LUKASZ]), 'To for three selected contacts')
    resp, to = s.mailto({'_gid': G_SALES_COM, '_source': SRC})
    eq(recipients(to), sorted([JAN, ANNA]), 'To for compose-to-group')
    resp, to = s.mailto({'_cid': '%s-%s' % (cid(ZOFIA), SRC), '_source': ''})
    eq(recipients(to), [ZOFIA], 'To for a selected contact in search mode (ID-source)')


@case('13 group expansion in compose: all members, never a list')
def c13():
    value, _ = group_expand(JAN, G_SALES_COM)
    eq(recipients(value), sorted([JAN, ANNA]), 'expansion of Sprzedaż')
    check('Jan Kowalski <%s>' % JAN in value and 'Anna Nowak <%s>' % ANNA in value, 'display names in %r' % value)
    value, _ = group_expand(JAN, G_IT)
    eq(recipients(value), [LUKASZ], 'expansion of IT')


@case('14 copy from the directory to the personal address book works; out-of-scope IDs are not copied')
def c14():
    s = session(LUKASZ)
    code, resp = s.ajax('addressbook', 'copy', {'_cid': cid(ZOFIA), '_source': SRC, '_to': '0'}, method='POST')
    check(any('copied 1' in m for m, _ in Roundcube.resp_messages(resp)), 'copy message: %r' % Roundcube.resp_messages(resp))
    code, resp = s.ajax('addressbook', 'copy', {'_cid': cid(BOB), '_source': SRC, '_to': '0'}, method='POST')
    check(not any('copied 1' in m for m, _ in Roundcube.resp_messages(resp)), 'foreign contact copied: %r' % resp)
    resp = list_book(LUKASZ, source='0')
    eq(sorted(r['data'].get('email') for r in rows(resp)), [ZOFIA], 'personal book after the copies')


@case('15 opening a message: the sender-photo request answers without error and without a photo')
def c15():
    m = imaplib.IMAP4('127.0.0.1', 143)
    m.login(JAN, IMAP_PASS)
    m.select('INBOX')
    if (m.search(None, 'ALL')[1][0] or b'').strip():
        m.store('1:*', '+FLAGS', '\\Deleted')
        m.expunge()
    msg = ('From: Zofia Zolkiewska <%s>\r\nTo: Jan Kowalski <%s>\r\nSubject: GAL Bridge E2E photo\r\n'
           'Date: Tue, 24 Nov 2026 10:00:00 +0100\r\nMessage-ID: <e2e-photo@example.com>\r\n\r\nHello\r\n' % (ZOFIA, JAN))
    typ, data = m.append('INBOX', None, None, msg.encode())
    m.logout()
    eq(typ, 'OK', 'IMAP APPEND')
    mm = re.search(rb'APPENDUID \d+ (\d+)', data[0] or b'')
    check(mm, 'no APPENDUID in %r' % data)
    s = session(JAN)
    page = s.get(_task='mail', _action='show', _uid=mm.group(1).decode(), _mbox='INBOX').text
    no_app_error(page, 'message view')
    photo = re.search(r'src="([^"]*_action=photo[^"]*)"', page)
    check(photo, 'no contact photo <img> in the message view')
    url = requests.compat.urljoin(E.base, photo.group(1).replace('&amp;', '&'))
    check('_email=' in url, 'photo URL without _email: %s' % url)
    r = s.s.get(url)
    check(r.status_code in (200, 204), 'sender photo request answered %d' % r.status_code)
    check(r.status_code == 204 or r.headers.get('content-type', '').startswith('image/'), 'photo response: %r' % r.headers)
    r = s.get(_task='addressbook', _action='photo', _email=ZOFIA)
    eq((r.status_code, r.headers.get('content-type')), (200, 'image/gif'), 'photo by e-mail without _error (blank image)')
    r = s.get(_task='addressbook', _action='photo', _source=SRC, _cid=cid(ZOFIA))
    eq(r.status_code, 200, 'photo by contact ID')


@case('16 search mode: a stored search re-runs for paging, the compose list, a saved search; a new configuration voids it')
def c16():
    s = session(ANNA)
    found, resp = search_ids(ANNA, 'example.com')
    eq(len(found), len(EXPECTED[ANNA]), 'search "example.com" for anna')
    req = resp['env']['search_request']
    code, r = s.ajax('addressbook', 'list', {'_search': req, '_page': '1'})
    no_app_error(r, 'list in search mode')
    eq(sorted(i for i in row_ids(r) if i.endswith('-' + SRC)), found, 'list in search mode (set_search_set round trip)')
    code, r = s.ajax('addressbook', 'search-create', {'_search': req, '_name': 'E2E all'}, method='POST')
    saved = Roundcube.command_args(r, 'insert_saved_search')
    check(saved, 'saved search not created: %r' % r)
    code, r = s.ajax('addressbook', 'search', {'_sid': saved[0][1]})
    no_app_error(r, 'saved search')
    eq(sorted(i for i in row_ids(r) if i.endswith('-' + SRC)), found, 'saved search re-run')
    code, r = s.ajax('mail', 'search-contacts', {'_q': 'nowak'})
    creq = (r.get('env') or {}).get('search_request')
    check(creq, 'no search_request from search-contacts')
    code, r = s.ajax('mail', 'list-contacts', {'_search': creq, '_page': '1'})
    no_app_error(r, 'compose list in search mode')
    data = (r.get('env') or {}).get('contactdata') or {}
    vals = ' '.join(str(v) for v in data.values())
    check(ANNA in vals and JAN not in vals, 'compose list in search mode: %r' % data)
    _, resp = search_ids(ANNA, 'kow')
    req = resp['env']['search_request']
    eq([c['emails'] for c in export_cards(ANNA, _search=req)[0]], [[JAN]], 'search export before the configuration change')
    with plugin_config("$config['galbridge_max_results'] = 499;"):
        eq(export_cards(ANNA, _search=req)[0], [], 'a search stored under another configuration matches nothing')


# =========================================================================== attacks

@case('20 the disabled global book (_source=galbridge_global) returns nothing on every path, never error 700')
def c20():
    s = session(JAN)
    resp = list_book(JAN, source=GLOBAL)
    eq(row_ids(resp), [], 'list of the disabled book')
    r = s.get(_task='addressbook', _source=GLOBAL)
    no_app_error(r.text, 'address book page with _source=galbridge_global')
    eq(Roundcube.env(r.text, 'source'), '0', 'the page falls back to the default book')
    r = s.get(_task='addressbook', _action='show', _source=GLOBAL, _cid=cid(JAN))
    no_app_error(r.text, 'show in the disabled book')
    check(Roundcube.env(r.text, 'cid') is None and JAN not in r.text, 'contact shown through the disabled book')
    cards, _ = export_cards(JAN, _source=GLOBAL)
    eq(cards, [], 'export of the disabled book')
    cards, _ = export_cards(JAN, _source=GLOBAL, _cid=cid(JAN))
    eq(cards, [], 'selected export through the disabled book')
    eq(ac_gal(JAN, 'kow', source=GLOBAL), [], 'autocomplete with _source=galbridge_global')
    value, _ = group_expand(JAN, G_SALES_COM, source=GLOBAL)
    eq(value or '', '', 'group expansion through the disabled book')
    resp, to = s.mailto({'_cid': cid(JAN), '_source': GLOBAL})
    eq(to, None, 'compose-to-selected through the disabled book')
    resp, to = s.mailto({'_gid': G_SALES_COM, '_source': GLOBAL})
    eq(to, None, 'compose-to-group through the disabled book')
    eq(search_ids(JAN, 'kow', scope=GLOBAL)[0], [], 'search scoped to the disabled book')
    code, resp = s.ajax('mail', 'list-contacts', {'_source': GLOBAL})
    no_app_error(resp, 'compose list of the disabled book')
    eq([a for n, a in Roundcube.commands(resp) if n == 'add_contact_row'], [], 'compose list of the disabled book')


@case('21 foreign, crafted and malformed group IDs (_gid) select nothing')
def c21():
    s = session(JAN)
    for g in (G_SALES_ORG, gid('sprzedaż', tag='g'), gid('example.com', kind='d'), gid('Sprzedaż'), 'dp_', 'dp_sprzedaXc5Xbc',
              'dp_it,dp_sprzedaXC5XBC', "dp_it' OR 1=1", 'sql', '-1'):
        resp = list_book(JAN, gid=g)
        eq(row_ids(resp), [], 'list with _gid=%r' % g)
        value, _ = group_expand(JAN, g)
        eq(value or '', '', 'group expansion of %r' % g)
        resp, to = s.mailto({'_gid': g, '_source': SRC})
        eq(to, None, 'compose-to-group with %r' % g)
        cards, _ = export_cards(JAN, _source=SRC, _gid=g)
        eq(cards, [], 'group export with %r' % g)
    code, resp = s.ajax('addressbook', 'list', [('_source', SRC), ('_gid[]', G_SALES_COM)])
    no_app_error(resp, 'list with an array _gid')
    check(len(row_ids(resp)) in (0, 13), 'array _gid: %r' % row_ids(resp))


@case('22 contact IDs of another tenant, hidden entries and crafted IDs (_cid) reveal nothing')
def c22():
    s = session(JAN)
    bad = [cid(BOB), cid('orgteam@example.org', 'a'), cid('orglist@example.org', 'l'), cid('disabled.user@example.com'),
           cid('weird@example.com', 'a'), cid('retired@example.com', 'l'), cid(JAN, 'a'), cid('team@example.com'),
           cid('staff@example.com', 'l'), cid('managers@example.com', 'a'), 'u_JanDkowalskiAexampleDcom',
           'u_janDkowalskiAexampleDcomX', 'u_jan', 'u_' + 'a' * 800, 'x_janDkowalskiAexampleDcom', '0', '1']
    for c in bad:
        r = s.get(_task='addressbook', _action='show', _source=SRC, _cid=c)
        no_app_error(r.text, 'show %s' % c)
        check(Roundcube.env(r.text, 'cid') is None, 'show %s returned a contact' % c)
        r = s.get(_task='addressbook', _action='qrcode', _source=SRC, _cid=c)
        check(r.status_code == 404 or not r.headers.get('content-type', '').startswith('image/'), 'qrcode for %s' % c)
        r = s.get(_task='addressbook', _action='print', _source=SRC, _cid=c)
        for leak in (BOB, 'Bob Smith', 'orgteam', 'disabled.user', 'Unknown policy', 'staff@example.com'):
            check(leak not in r.text, 'print %s shows %r' % (c, leak))
    joined = ','.join(c for c in bad if re.fullmatch(r'[A-Za-z0-9_]+', c) and len(c) < 100)
    cards, _ = export_cards(JAN, _source=SRC, _cid=joined)
    eq(cards, [], 'export of foreign/hidden/crafted IDs')
    cards, _ = export_cards(JAN, _source=SRC, _cid='%s,%s' % (cid(BOB), cid(ANNA)))
    eq([c['emails'] for c in cards], [[ANNA]], 'export of one foreign and one own ID')
    resp, to = s.mailto({'_cid': joined, '_source': SRC})
    eq(to, None, 'compose-to-selected with foreign/hidden/crafted IDs')
    check(any('No contacts found' in m for m, _ in Roundcube.resp_messages(resp)), 'mailto message: %r' % resp)
    resp, to = s.mailto({'_cid': '%s,%s' % (cid(BOB), cid(ANNA)), '_source': SRC})
    eq(recipients(to), [ANNA], 'compose-to-selected with one foreign and one own ID')
    resp, to = s.mailto({'_cid': '%s-%s' % (cid(BOB), SRC), '_source': ''})
    eq(to, None, 'compose-to-selected with a foreign ID in search mode (ID-source)')


@case('23 the array form of _cid (_cid[source][]=...) cannot widen the scope')
def c23():
    s = session(JAN)
    resp, to = s.mailto([('_cid[%s][]' % SRC, cid(BOB)), ('_cid[%s][]' % SRC, cid(ANNA))])
    eq(recipients(to), [ANNA], 'mailto with the array form')
    resp, to = s.mailto([('_cid[%s][]' % SRC, cid(BOB))])
    eq(to, None, 'mailto with the array form, foreign ID only')
    resp, to = s.mailto([('_cid[%s][]' % GLOBAL, cid(JAN))])
    eq(to, None, 'mailto with the array form and the disabled book')
    r = s.export(**{'_cid[%s][]' % SRC: [cid(BOB), cid('orglist@example.org', 'l'), cid(ZOFIA)]})
    eq([c['emails'] for c in parse_vcards(r.text)], [[ZOFIA]], 'export with the array form')
    r = s.get(**{'_task': 'addressbook', '_action': 'show', '_cid[%s][]' % SRC: cid(BOB)})
    no_app_error(r.text, 'show with the array form')
    check(Roundcube.env(r.text, 'cid') is None and BOB not in r.text, 'show with the array form returned bob')
    code, resp = s.ajax('addressbook', 'copy', [('_cid[%s][]' % SRC, cid(BOB)), ('_to', '0')], method='POST')
    check(not any('copied 1' in m for m, _ in Roundcube.resp_messages(resp)), 'array-form copy of a foreign contact: %r' % resp)


@case('24 write paths on the read-only directory: refused or redirected, never error 700, nothing changes')
def c24():
    s = session(ZOFIA)
    before = sorted(row_ids(list_book(ZOFIA)))
    personal = sorted(row_ids(list_book(ZOFIA, source='0')))
    for action in ('add', 'edit', 'import'):
        r = s.get(_task='addressbook', _action=action, _source=SRC, _cid=cid(ZOFIA), _framed=1)
        eq(r.status_code, 200, '%s status' % action)
        no_app_error(r.text, action)
    r = s.get(_task='addressbook', _action='edit', _source=SRC, _cid=cid(ZOFIA), _framed=1)
    check(any('read only' in m for m, _ in Roundcube.messages(r.text)), 'edit: no read-only notice')
    for data in ({'_source': SRC, '_cid': cid(ZOFIA), '_name': 'Hacked', '_email[]': 'hacked@example.com'},
                 {'_source': SRC, '_name': 'New', '_firstname': 'New', '_email[]': 'new@example.com'}):
        r = s.post({'_task': 'addressbook', '_action': 'save', '_framed': '1'}, data)
        eq(r.status_code, 200, 'save status')
        no_app_error(r.text, 'save')
    for action, data in [('delete', {'_cid': cid(ZOFIA), '_source': SRC}),
                         ('group-create', {'_source': SRC, '_name': 'evil'}),
                         ('group-rename', {'_source': SRC, '_gid': G_IT, '_name': 'evil'}),
                         ('group-delete', {'_source': SRC, '_gid': G_IT}),
                         ('group-addmembers', {'_source': SRC, '_gid': G_IT, '_cid': cid(ZOFIA)}),
                         ('group-delmembers', {'_source': SRC, '_gid': G_IT, '_cid': cid(LUKASZ)}),
                         ('move', {'_cid': cid(ANNA), '_source': SRC, '_to': '0'}),
                         ('copy', {'_cid': cid(ANNA), '_source': '0', '_to': SRC}),
                         ('copy', {'_cid': cid(ANNA), '_source': SRC, '_to': SRC}),
                         ('undo', {'_source': SRC})]:
        code, resp = s.ajax('addressbook', action, data, method='POST')
        eq(code, 200, '%s status' % action)
        no_app_error(resp, '%s %r' % (action, data))
    eq(sorted(row_ids(list_book(ZOFIA))), before, 'directory after the write attempts')
    eq(sorted(row_ids(list_book(ZOFIA, source='0'))), personal, 'personal book after the write attempts (move must not copy)')
    page = s.addressbook_page(_action='add', _framed=1)
    selects = ' '.join(re.findall(r'<select[^>]*name="_source".*?</select>', page, re.S))
    check('galbridge' not in selects, 'the directory is offered as a target for new contacts: %s' % selects)


@case('25 tenant B (bob@example.org) sees only example.org and cannot reach tenant A by ID, group or search')
def c25():
    s = session(BOB)
    eq(sorted(row_ids(list_book(BOB))), sorted(EXPECTED[BOB]), 'contacts listed for bob')
    for c in (cid(JAN), cid('team@example.com', 'a'), cid('newsletter@example.com', 'l')):
        r = s.get(_task='addressbook', _action='show', _source=SRC, _cid=c)
        check(Roundcube.env(r.text, 'cid') is None, 'bob sees %s' % c)
    eq(row_ids(list_book(BOB, gid=G_SALES_COM)), [], 'bob lists the Sprzedaż group of example.com')
    value, _ = group_expand(BOB, G_SALES_COM)
    eq(value or '', '', 'bob expands the Sprzedaż group of example.com')
    eq(search_ids(BOB, 'Kowalski')[0], [], 'bob searches for Kowalski')
    eq(search_ids(BOB, 'example.com')[0], [], 'bob searches for the other domain')
    eq(ac_gal(BOB, 'zof'), [], 'bob autocompletes zof')
    eq(ac_gal(BOB, 'sprz'), [], 'bob autocompletes the department of example.com')
    cards, _ = export_cards(BOB, _source=SRC)
    eq(sorted(c['emails'][0] for c in cards), sorted(a for a, _ in EXPECTED[BOB].values()), 'export for bob')
    resp, to = s.mailto({'_cid': ','.join([cid(JAN), cid(ZOFIA), cid(BOB)]), '_source': SRC})
    eq(recipients(to), [BOB], 'bob composes to tenant A IDs')
    eq(sorted(row_ids(list_book(JAN))), sorted(EXPECTED[JAN]), 'tenant A unchanged for jan')


@case('26 limits: oversized groups and ID sets are refused with a visible warning, never truncated')
def c26():
    s = session(JAN)
    with plugin_config("$config['galbridge_max_group_members'] = 1;\n$config['galbridge_max_ids'] = 2;\n"
                       "$config['galbridge_max_export'] = 5;\n$config['galbridge_max_results'] = 10;"):
        value, resp = group_expand(JAN, G_SALES_COM)
        eq(value or '', '', 'expansion of a group above galbridge_max_group_members')
        msgs = [m for m, _ in Roundcube.resp_messages(resp)]
        check(any('more than 1 members' in m for m in msgs), 'no group-too-large warning: %r' % msgs)
        value, _ = group_expand(JAN, G_IT)
        eq(recipients(value), [LUKASZ], 'expansion of a group within the limit')
        resp, to = s.mailto({'_gid': G_SALES_COM, '_source': SRC})
        eq(to, None, 'compose-to-group above the limit')
        check(any('more than 1 members' in m for m, _ in Roundcube.resp_messages(resp)), 'mailto group warning: %r' % resp)
        eq([r for r in ac_gal(JAN, 'sprz') if r.get('type') == 'group'], [], 'autocomplete offers a group that expansion would refuse')
        resp, to = s.mailto({'_cid': ','.join([cid(ANNA), cid(ZOFIA), cid(LUKASZ)]), '_source': SRC})
        eq(to, None, 'compose-to-selected above galbridge_max_ids')
        check(any('At most 2' in m for m, _ in Roundcube.resp_messages(resp)), 'no too-many-IDs warning: %r' % resp)
        resp, to = s.mailto({'_cid': ','.join([cid(ANNA), cid(ZOFIA)]), '_source': SRC})
        eq(recipients(to), sorted([ANNA, ZOFIA]), 'compose-to-selected within galbridge_max_ids')
        export_refused(JAN, 'whole-book export above galbridge_max_export (no alphabetical prefix)', _source=SRC)
        export_refused(JAN, 'group export above galbridge_max_group_members', _source=SRC, _gid=G_SALES_COM)
        found, resp = search_ids(JAN, 'example.com')
        eq(len(found), 10, 'search list capped at galbridge_max_results')
        check(any('first 10' in m for m, _ in Roundcube.resp_messages(resp)), 'no truncation notice: %r' % Roundcube.resp_messages(resp))
    with plugin_config("$config['galbridge_domain_export'] = false;"):
        export_refused(JAN, 'whole-book export with galbridge_domain_export = false', _source=SRC)
    eq(len(export_cards(JAN, _source=SRC)[0]), 13, 'export after restoring the configuration')


@case('27 global book (live configuration): search-only, prefix search, foreign entries marked, no export')
def c27():
    with plugin_config("$config['galbridge_global_book'] = true;\n"
                       "$config['galbridge_global_viewer_domains'] = ['example.com'];\n"
                       "$config['galbridge_global_data_domains'] = ['example.org', 'xn--bcher-kva.example'];"):
        page = session(JAN).addressbook_page()
        sources = Roundcube.env(page, 'address_sources') or {}
        check(GLOBAL in sources, 'global book not advertised to an entitled viewer')
        eq((sources[GLOBAL].get('name'), sources[GLOBAL].get('readonly')), ('Global directory', True), 'global source entry')
        resp = list_book(JAN, source=GLOBAL)
        eq(row_ids(resp), [], 'search-only book listed without a search')
        check(any('search' in m.lower() for m, _ in Roundcube.resp_messages(resp)), 'no search-only notice: %r' % resp)
        found = search_ids(JAN, 'smith', scope=GLOBAL)[0]
        eq(found, [cid(BOB) + '-' + GLOBAL], 'global search "smith"')
        eq(search_ids(JAN, 'mith', scope=GLOBAL)[0], [], 'global search is prefix-only')
        eq(search_ids(JAN, 'example', scope=GLOBAL)[0], [], 'global search never matches a domain fragment')
        eq(search_ids(JAN, 'kowalski', scope=GLOBAL)[0], [], 'home domain excluded from the global book')
        # Roundcube quotes a display name with parentheses: "Bob Smith (example.org)" <bob@example.org>
        got = [(r['fields'].get('name'), r['fields'].get('email'), recipients(r['name']), r['source']) for r in ac_gal(JAN, 'smith')]
        eq(got, [('Bob Smith (example.org)', BOB, [BOB], GLOBAL)], 'autocomplete from the global book (foreign domain marked)')
        export_refused(JAN, 'export of the global book (galbridge_global_export off)', _source=GLOBAL)
        sources = Roundcube.env(session(BOB).addressbook_page(), 'address_sources') or {}
        check(GLOBAL not in sources, 'global book advertised to a viewer outside galbridge_global_viewer_domains')
        eq(search_ids(BOB, 'kowalski', scope=GLOBAL)[0], [], 'non-entitled viewer searches the global book')
        eq(ac_gal(BOB, 'kowalski', source=GLOBAL), [], 'non-entitled viewer autocompletes from the global book')
    check(GLOBAL not in (Roundcube.env(session(JAN).addressbook_page(), 'address_sources') or {}), 'global book still advertised')


def denied(user, s, what):
    """The directory is neither advertised nor readable for this session (fail closed, no error 700)."""
    check(SRC not in sources_of(user, s), '%s: directory advertised' % what)
    code, resp = s.list_contacts(SRC)
    eq(code, 200, '%s: list status' % what)
    no_app_error(resp, what)
    eq(row_ids(resp), [], '%s: rows' % what)
    eq([r for r in (Roundcube.autocomplete_results(s.autocomplete('kow')[1]) or []) if r.get('source') == SRC], [],
       '%s: autocomplete' % what)
    r = s.get(_task='addressbook', _action='show', _source=SRC, _cid=cid(JAN))
    no_app_error(r.text, what + ' show')
    check(Roundcube.env(r.text, 'cid') is None, '%s: contact shown' % what)


@case('28a configuration guard (live configuration): IMAP host binding and malformed lists fail closed')
def c28a():
    with plugin_config("$config['galbridge_imap_hosts'] = ['imap.example.net'];"), expected_log('host_mismatch'):
        denied(JAN, session(JAN), 'session IMAP host not in galbridge_imap_hosts')
    with plugin_config("$config['galbridge_imap_hosts'] = ['127.0.0.1'];"):
        eq(sorted(row_ids(list_book(JAN))), sorted(EXPECTED[JAN]), 'explicit galbridge_imap_hosts matching the IMAP host')
    with plugin_config("$config['galbridge_imap_hosts'] = ['%d'];"), expected_log('config_invalid'):
        denied(JAN, session(JAN), 'placeholder in galbridge_imap_hosts')
    with plugin_config("$config['galbridge_domain_viewer_domains_exclude'] = 'example.com';"), expected_log('config_invalid'):
        denied(JAN, session(JAN), 'malformed deny-list (string instead of array)')
        denied(BOB, session(BOB), 'malformed deny-list, other tenant')
    with plugin_config("$config['galbridge_domain_viewer_domains_exclude'] = ['example.com'];"):
        denied(JAN, session(JAN), 'viewer domain excluded')
        eq(sorted(row_ids(list_book(BOB))), sorted(EXPECTED[BOB]), 'viewer of a domain that is not excluded')


@case('28b a refused database role (vmail) or a missing DSN fails closed and is logged as config_invalid')
def c28b():
    dsn = 'mysql://vmail:x@127.0.0.1/galbridge_vmail_test' if E.args.db == 'mysql' else 'pgsql://vmail:x@127.0.0.1/galbridge_vmail_test'
    failures = []
    for value, what in ((dsn, 'refused database role (vmail)'), ('', 'empty galbridge_db_dsn')):
        try:
            with plugin_config("$config['galbridge_db_dsn'] = '%s';" % value), expected_log('config_invalid'):
                denied(JAN, Roundcube(E.base, JAN).login(), what)
        except AssertionError as e:
            failures.append('%s: %s' % (what, str(e)[:300]))
    check(not failures, '\n'.join(failures))


@case('28c a database outage fails closed, is logged as db_down and never shows the DSN')
def c28c():
    with open(os.path.join(E.run, 'galbridge.inc.php')) as f:
        m = re.search(r"galbridge_db_dsn'\] = '([^']+)'", f.read())
    check(m, 'cannot read the DSN of the run configuration')
    bad_dsn = re.sub(r'(://[^:/@]+:)[^@]+@', r'\1wrong-password@', m.group(1))
    check('wrong-password' in bad_dsn, 'cannot build the outage DSN')
    def core_errors(text):
        # rcube_db::conn_create() always logs the driver's connect error (role and host); never the password
        check('wrong-password' not in text and 'testonly-ro' not in text, 'password in errors.log: %r' % text)
    with plugin_config("$config['galbridge_db_dsn'] = '%s';" % bad_dsn), expected_log('db_down', core_errors=core_errors):
        s = Roundcube(E.base, ZOFIA).login()   # the outage is memoised in this session only
        denied(ZOFIA, s, 'database outage (wrong password)')
        check('wrong-password' not in s.addressbook_page(), 'DSN shown to the user')
    eq(sorted(row_ids(list_book(JAN))), sorted(EXPECTED[JAN]), 'directory after restoring the configuration')


@case('29 content switches (live configuration): hidden addresses and local parts, aliases, lists, list policy')
def c29():
    with plugin_config("$config['galbridge_hidden_addresses'] = ['jan.kowalski@example.com'];\n"
                       "$config['galbridge_hidden_localparts'] = ['postmaster'];"):
        expected = sorted(i for i in EXPECTED[ANNA] if i not in (cid(JAN), cid('postmaster@example.com')))
        eq(sorted(row_ids(list_book(ANNA))), expected, 'list without the hidden entries')
        eq(search_ids(ANNA, 'kow')[0], [], 'search for a hidden person')
        eq(ac_gal(ANNA, 'postm'), [], 'autocomplete of a hidden local part')
        r = session(ANNA).get(_task='addressbook', _action='show', _source=SRC, _cid=cid(JAN))
        check(Roundcube.env(r.text, 'cid') is None, 'hidden person shown by ID')
        eq(row_ids(list_book(ANNA, gid=G_SALES_COM)), [cid(ANNA)], 'group without the hidden person')
        value, _ = group_expand(ANNA, G_SALES_COM)
        eq(recipients(value), [ANNA], 'group expansion without the hidden person')
        got = [(r['name'], r['type']) for r in ac_gal(ANNA, 'sprz') if r.get('type') == 'group']
        eq(got, [('Sprzedaż (1)', 'group')], 'group head count without the hidden person')
        eq(sorted(c['emails'][0] for c in export_cards(ANNA, _source=SRC)[0]), sorted(EXPECTED[ANNA][i][0] for i in expected),
           'export without the hidden entries')
    with plugin_config("$config['galbridge_include_aliases'] = false;\n$config['galbridge_include_mailing_lists'] = false;"):
        eq(sorted(row_ids(list_book(JAN))), sorted(i for i in EXPECTED[JAN] if i.startswith('u_')), 'persons only')
        eq(ac_gal(JAN, 'newsl'), [], 'autocomplete of a list with lists off')
    with plugin_config("$config['galbridge_list_policy'] = 'public_only';"):
        eq(sorted(row_ids(list_book(JAN))),
           sorted([i for i in EXPECTED[JAN] if i.startswith('u_')] + [cid('team@example.com', 'a'), cid('everyone@example.com', 'a'),
                                                                     cid('newsletter@example.com', 'l')]),
           'list policy public_only')


@case('30 organizations (live configuration): one book across the domains of an organization, grouped by domain')
def c30():
    with plugin_config("$config['galbridge_organizations'] = ['Example Group' => ['example.com', 'example.org']];"):
        sources = sources_of(JAN)
        eq(sources.get(SRC, {}).get('name'), 'Example Group', 'book name = organization label')
        got = set(row_ids(list_book(JAN)))
        must = set(EXPECTED[JAN]) | {cid(BOB), cid('orgteam@example.org', 'a'), cid('orglist@example.org', 'l')}
        check(must <= got, 'missing for jan: %r' % sorted(must - got))
        never = {cid('orginternal@example.org', 'a'), cid('klaus@xn--bcher-kva.example')} | set(NEVER)
        check(not (got & never), 'jan sees %r' % sorted(got & never))
        got_b = set(row_ids(list_book(BOB)))
        check({cid(a) for a in COM_PERSONS} | {cid(BOB), cid('team@example.com', 'a'), cid('newsletter@example.com', 'l')} <= got_b,
              'bob does not see the organization: %r' % sorted(got_b))
        never_b = {cid(a, 'a') for a in ('internal@example.com', 'board@example.com', 'legacy@example.com', 'managers@example.com')} | \
                  {cid('staff@example.com', 'l'), cid('mods@example.com', 'l')}
        check(not (got_b & never_b), 'bob sees restricted entries of example.com: %r' % sorted(got_b & never_b))
        groups = {v['name']: v['id'] for v in (Roundcube.env(session(JAN).addressbook_page(), 'contactgroups') or {}).values()
                  if v.get('source') == SRC}
        eq(sorted(groups), ['example.com', 'example.org'], 'groups by domain')
        eq(groups['example.org'], gid('example.org', kind='d'), 'domain group id')
        eq(row_ids(list_book(JAN, gid=groups['example.org'])), [cid(BOB)], 'members of the example.org group (persons only)')
        eq(sorted(row_ids(list_book(JAN, gid=groups['example.com']))), sorted(cid(a) for a in COM_PERSONS), 'members of example.com')
        eq(row_ids(list_book(JAN, gid=G_SALES_COM)), [], 'a department group ID while grouping by domain')
        eq(search_ids(JAN, 'smith')[0], [cid(BOB) + '-' + SRC], 'search across the organization')
    eq(sorted(row_ids(list_book(JAN))), sorted(EXPECTED[JAN]), 'directory after restoring the configuration')


# =========================================================================== final checks

@case('90 no secret column, private forwarding target, hidden field or alias address in any HTTP response', always=True)
def c90():
    check(not E.leaks, 'leaks:\n' + '\n'.join(E.leaks[:20]))


@case('91 Roundcube and plugin logs: no PHP error, no plugin ERROR, no personal data at normal log level', always=True)
def c91():
    content = without_windows('errors')
    check(not content.strip(), 'errors.log:\n' + content[-3000:])
    with open(os.path.join(E.run, 'php-server.log'), errors='replace') as f:
        php = [ln for ln in f if re.search(r'PHP (Fatal|Warning|Notice|Deprecated|Parse)|Uncaught', ln)]
    check(not php, 'PHP messages in the web server log:\n' + ''.join(php[:20]))
    if os.path.exists(log_path('galbridge')):
        # without what the configuration-guard cases provoked on purpose (checked there)
        content = without_windows('galbridge')
        bad = [ln for ln in content.splitlines() if re.search(r'\bERROR\b|db_error|db_down|role_too_broad|config_invalid', ln)]
        check(not bad, 'plugin errors:\n' + '\n'.join(bad[:20]))
        for needle in ['@example.com', '@example.org', 'Kowalski', 'kow', 'SECRET', 'testpass', 'testonly-ro'] + CANARIES:
            check(needle not in content, 'galbridge.log contains %r' % needle)
        with open(log_path('galbridge'), errors='replace') as f:
            full = f.read()
        for needle in ('testonly-ro', 'wrong-password', 'testpass', 'SECRET'):
            check(needle not in full, 'galbridge.log (including the provoked parts) contains %r' % needle)


# =========================================================================== main

def main():
    global E
    ap = argparse.ArgumentParser()
    ap.add_argument('--rc', default='/opt/rcrun/1.7.7')
    ap.add_argument('--php', default='php8.4')
    ap.add_argument('--db', default='mysql', choices=['mysql', 'pgsql'])
    ap.add_argument('--port', type=int, default=8181)
    ap.add_argument('--no-setup', action='store_true')
    ap.add_argument('--keep', action='store_true', help='leave the PHP web server running')
    ap.add_argument('--only', default='')
    ap.add_argument('-v', '--verbose', action='store_true')
    args = ap.parse_args()
    E = Env(args)
    Roundcube.response_observers.append(leak_observer)
    passed = failed = skipped = 0
    t0 = time.time()
    setup_failed = False
    try:
        for name, fn, always in CASES:
            if args.only and not always and not re.search(args.only, name):
                continue
            if setup_failed:
                print('SKIP %s (setup failed)' % name)
                skipped += 1
                continue
            t = time.time()
            try:
                fn()
                print('PASS %s (%.1fs)' % (name, time.time() - t))
                passed += 1
            except Skip as e:
                print('SKIP %s: %s' % (name, e))
                skipped += 1
            except Exception as e:  # noqa: BLE001 - report every failure and continue
                failed += 1
                print('FAIL %s: %s' % (name, str(e).strip().replace('\n', '\n     ')))
                if args.verbose or not isinstance(e, AssertionError):
                    traceback.print_exc()
                if name.startswith('00') or name.startswith('01'):
                    setup_failed = True
            sys.stdout.flush()
    finally:
        if not args.keep:
            pidfile = os.path.join(E.run, 'php-server.pid')
            if os.path.exists(pidfile):
                with contextlib.suppress(Exception), open(pidfile) as f:
                    os.kill(int(f.read().strip()), 15)
    print('E2E (%s): %d passed, %d failed, %d skipped (%.0fs)' % (args.db, passed, failed, skipped, time.time() - t0))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
