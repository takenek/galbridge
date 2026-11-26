#!/usr/bin/env python3
"""
GAL Bridge - browser (headless Chromium) tests of the Roundcube 1.7.x UI with the plugin (TEST ONLY).

Covers what the HTTP-level E2E suite cannot: that Roundcube's own JavaScript renders the directory
(source tree with groups, contact list, contact frame), that the quick search and the compose
autocomplete widget work with the plugin's IDs, that choosing a department group in compose expands
it into its members, that a display name stays text in the recipient widget of the Elastic skin
(which inserts names as HTML), and that no page raises a JavaScript error.

Usage: python3 tests/ui/test_ui.py [--rc /opt/rcrun/1.7.7] [--php php8.4] [--db mysql] [--port 8195]
                                  [--keep] [--allow-skips]
Requires: chromium, chromium-driver (found on PATH, or CHROMEDRIVER=/path; a driver is never
downloaded), python3-selenium, the Dovecot test server (tests/e2e/dovecot.sh) and the vmail test
database (tests/fixtures/vmail/load.sh). The environment is created with tests/e2e/setup.sh; at the
end the PHP web server of the run is stopped - only that process - and the plugin link is put back
(tests/e2e/harness.py). Case U8 adds rows to the fixture through the administrator access of
load.sh or the least-privilege access of docs/TESTING.md (tests/e2e/fixture_admin.py) and removes
them again; without that access it is skipped - and a skipped case fails the run unless
--allow-skips is given.

Run it as an unprivileged user: it refuses root with exit code 2 (GALBRIDGE_E2E_ALLOW_ROOT=1 in a
disposable container only). Chromium keeps its sandbox; it is switched off (with a warning) only for
root or when GALBRIDGE_UI_NO_SANDBOX=1 says so - in a container whose seccomp profile blocks it.

@license GPL-3.0-or-later
"""
import argparse
import contextlib
import os
import re
import shutil
import subprocess
import sys
import time
import traceback

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(PLUGIN, 'tests', 'e2e'))

import harness  # noqa: E402

if __name__ == '__main__':
    harness.refuse_root()       # before anything else is loaded or started

import requests  # noqa: E402
from selenium import webdriver  # noqa: E402
from selenium.common.exceptions import TimeoutException  # noqa: E402
from selenium.webdriver.chrome.service import Service  # noqa: E402
from selenium.webdriver.common.by import By  # noqa: E402
from selenium.webdriver.common.keys import Keys  # noqa: E402
from selenium.webdriver.support.ui import WebDriverWait  # noqa: E402

import fixture_admin  # noqa: E402

RESULTS = []


class Skip(Exception):
    pass

JAN = 'jan.kowalski@example.com'
BOB = 'bob@example.org'


def case(name):
    def deco(fn):
        RESULTS.append((name, fn))
        return fn
    return deco


def check(cond, msg):
    if not cond:
        raise AssertionError(msg)


class Ctx:
    pass


C = Ctx()


def browser_and_driver():
    """The installed Chromium and chromedriver; exit code 2 when one is missing (never a download)."""
    browser = next((p for p in (shutil.which(n) for n in ('chromium', 'chromium-browser', 'google-chrome')) if p), None)
    drv = os.environ.get('CHROMEDRIVER') or shutil.which('chromedriver')
    if not browser:
        sys.exit(_missing('Chromium (install chromium)'))
    if not drv or not os.access(drv, os.X_OK):
        sys.exit(_missing('chromedriver (install chromium-driver, or set CHROMEDRIVER=/path/to/chromedriver)'))
    return browser, drv


def _missing(what):
    print('UI: no %s - a driver is never downloaded' % what, file=sys.stderr)
    return 2


def driver(browser, drv):
    o = webdriver.ChromeOptions()
    args = ['--headless=new', '--disable-dev-shm-usage', '--window-size=1500,1000', '--lang=en-US']
    if os.geteuid() == 0 or os.environ.get('GALBRIDGE_UI_NO_SANDBOX') == '1':
        # Chromium refuses to run as root with its sandbox, and Docker's default seccomp profile
        # blocks it: only then, and said aloud
        print('UI: WARNING - Chromium runs WITHOUT its sandbox (%s)'
              % ('root' if os.geteuid() == 0 else 'GALBRIDGE_UI_NO_SANDBOX=1'))
        args.append('--no-sandbox')
    for a in args:
        o.add_argument(a)
    o.set_capability('goog:loggingPrefs', {'browser': 'ALL'})
    o.binary_location = browser
    # Selenium Manager must not fetch anything: the driver and the browser are given
    os.environ['SE_OFFLINE'] = 'true'
    d = webdriver.Chrome(options=o, service=Service(executable_path=drv))
    d.set_page_load_timeout(30)
    return d


def wait(d, cond, timeout=10, msg='timeout'):
    try:
        return WebDriverWait(d, timeout).until(lambda x: cond(x))
    except TimeoutException:
        raise AssertionError(msg)


def js(d, script, *args):
    return d.execute_script(script, *args)


def login(d, user):
    d.delete_all_cookies()
    d.get(C.base + '?_task=logout')
    d.get(C.base + '?_task=login')
    wait(d, lambda x: x.find_elements(By.ID, 'rcmloginuser'), msg='no login form')
    d.find_element(By.ID, 'rcmloginuser').send_keys(user)
    d.find_element(By.ID, 'rcmloginpwd').send_keys('testpass')
    d.find_element(By.ID, 'rcmloginsubmit').click()
    wait(d, lambda x: js(x, 'return !!(window.rcmail && rcmail.env.task == "mail")'), msg='login failed for %s' % user)


def js_errors(d):
    errs = []
    for e in d.get_log('browser'):
        m = e.get('message', '')
        if e.get('level') == 'SEVERE' and 'favicon' not in m and "'beforeunload' confirmation panel" not in m:
            errs.append(m)
    return errs


def open_addressbook(d):
    d.get(C.base + '?_task=addressbook')
    # rcmail.busy: the first contact list is still being fetched - rcmail.command() ignores every
    # click until it has arrived (app.js: "do nothing if interface is locked by another command")
    wait(d, lambda x: js(x, 'return !!(window.rcmail && rcmail.contact_list && document.readyState == "complete" && !rcmail.busy)'),
         msg='address book did not load')


def directory_link(d):
    links = d.find_elements(By.CSS_SELECTOR, '#directorylist a[rel="galbridge_domain"]')
    return links[0] if links else None


def list_names(d):
    return js(d, 'return $("#contacts-table tbody tr td.name").map(function(){ return $(this).text().trim(); }).get();')


def wait_list(d, count, msg):
    wait(d, lambda x: len(list_names(x)) == count and not js(x, 'return rcmail.busy'), timeout=10,
         msg='%s: %r' % (msg, list_names(d)))
    return list_names(d)


def open_directory(d):
    link = directory_link(d)
    check(link is not None, 'directory not in the source list')
    wait(d, lambda x: not js(x, 'return rcmail.busy'), msg='address book still busy')
    link.click()
    wait(d, lambda x: js(x, 'return rcmail.env.source') == 'galbridge_domain', msg='directory not selected')


def compose_to_input(d):
    d.get(C.base + '?_task=mail&_action=compose')
    wait(d, lambda x: js(x, 'return !!(window.rcmail && rcmail.env.compose_id && $("#compose_to .recipient-input input").length)'),
         timeout=15, msg='compose page did not load')
    return d.find_element(By.CSS_SELECTOR, '#compose_to .recipient-input input')


def ksearch_items(d):
    return js(d, 'return $("#rcmKSearchpane:visible li").map(function(){ return $(this).text().trim(); }).get();') or []


# =========================================================================== cases

@case('U1 login as jan, open the address book: "Domain directory" is a read-only source with its groups')
def u1(d):
    login(d, JAN)
    open_addressbook(d)
    link = directory_link(d)
    check(link is not None and link.text.strip() == 'Domain directory', 'directory link: %r' % (link and link.text))
    li = link.find_element(By.XPATH, '..')
    check('readonly' in (li.get_attribute('class') or ''), 'directory item not marked readonly: %r' % li.get_attribute('class'))
    groups = js(d, 'return $(arguments[0]).find("ul.groups li a").map(function(){ return $(this).text().trim(); }).get();', li)
    check(sorted(groups) == sorted(['IT', 'Księgowość', 'Sprzedaż']), 'groups under the directory: %r' % groups)
    check(not d.find_elements(By.CSS_SELECTOR, '#directorylist a[rel="galbridge_global"]'), 'disabled global book listed')


@case('U2 the directory lists its 13 entries, without the other tenant')
def u2(d):
    open_addressbook(d)
    open_directory(d)
    names = wait_list(d, 13, 'directory rows')
    for n in ('Jan Kowalski', 'Zofia Żółkiewska', 'Anna Nowak', 'Łukasz Słowik', 'Zespół sprzedaży', 'Newsletter firmy'):
        check(n in names, '%r missing in %r' % (n, names))
    check('Bob Smith' not in names, 'tenant B listed')
    check(not js(d, 'return !!rcmail.commands["add"] && rcmail.env.source == "galbridge_domain" && !rcmail.env.readonly'),
          'directory not treated as read-only by the client')


@case('U3 a contact opens in the contact frame (name, e-mail, department, no edit)')
def u3(d):
    open_addressbook(d)
    open_directory(d)
    wait_list(d, 13, 'directory rows')
    row = [r for r in d.find_elements(By.CSS_SELECTOR, '#contacts-table tbody tr') if 'Zofia' in r.text]
    check(row, 'no row for Zofia')
    row[0].click()
    frame = wait(d, lambda x: x.find_elements(By.ID, 'contact-frame'), msg='no contact frame')[0]
    wait(d, lambda x: js(x, 'var f = document.getElementById("contact-frame"); return !!(f && f.contentWindow && f.contentWindow.rcmail '
                            '&& f.contentWindow.rcmail.env.cid);'), timeout=15, msg='contact frame did not load')
    d.switch_to.frame(frame)
    try:
        text = d.find_element(By.TAG_NAME, 'body').text
        for needle in ('Zofia', 'Żółkiewska', 'zofia.zolkiewska@example.com', 'Księgowość'):
            check(needle in text, 'contact frame lacks %r' % needle)
        check(js(d, 'return rcmail.env.readonly') is True, 'contact frame env.readonly')
    finally:
        d.switch_to.default_content()
    check(not js(d, 'return !!rcmail.commands["edit"]'), 'edit command enabled for a directory contact')


@case('U4 a department group lists its members; the quick search finds a colleague')
def u4(d):
    open_addressbook(d)
    link = [a for a in d.find_elements(By.CSS_SELECTOR, '#directorylist ul.groups li a') if a.text.strip() == 'Sprzedaż']
    check(link, 'group Sprzedaż not in the tree')
    link[0].click()
    names = wait_list(d, 2, 'group rows')
    check(sorted(names) == ['Anna Nowak', 'Jan Kowalski'], 'group members: %r' % names)
    box = d.find_element(By.CSS_SELECTOR, '#searchform input[type="text"], #layout-list .searchbar input')
    box.clear()
    box.send_keys('kowal' + Keys.ENTER)
    names = wait_list(d, 1, 'search rows')
    check(names == ['Jan Kowalski'], 'search result: %r' % names)


@case('U5 compose: the autocomplete suggests a colleague from the directory and inserts the address')
def u5(d):
    inp = compose_to_input(d)
    inp.send_keys('zof')
    wait(d, lambda x: any('zofia.zolkiewska@example.com' in i for i in ksearch_items(x)), timeout=10,
         msg='no autocomplete suggestion: %r' % ksearch_items(d))
    items = ksearch_items(d)
    check(any('Zofia Żółkiewska' in i for i in items), 'suggestion text: %r' % items)
    inp.send_keys(Keys.ENTER)
    wait(d, lambda x: 'zofia.zolkiewska@example.com' in (js(x, 'return $("#_to").val()') or ''), msg='recipient not inserted')


@case('U6 compose: choosing a department group from the autocomplete expands it into its members')
def u6(d):
    inp = compose_to_input(d)
    inp.send_keys('sprz')
    wait(d, lambda x: any(i.startswith('Sprzedaż (2)') for i in ksearch_items(x)), timeout=10,
         msg='group not suggested: %r' % ksearch_items(d))
    js(d, '$("#rcmKSearchpane li").filter(function(){ return $(this).text().trim().indexOf("Sprzedaż (2)") === 0; })'
          '.first().trigger("mousedown").trigger("mouseup").trigger("click");')
    wait(d, lambda x: all(a in (js(x, 'return $("#_to").val()') or '') for a in (JAN, 'anna.nowak@example.com')), timeout=10,
         msg='group not expanded: %r' % js(d, 'return $("#_to").val()'))
    val = js(d, 'return $("#_to").val()')
    check('team@example.com' not in val, 'group expansion added an alias account: %r' % val)


@case('U7 tenant B (bob@example.org) sees only his own domain')
def u7(d):
    login(d, BOB)
    open_addressbook(d)
    open_directory(d)
    names = wait_list(d, 4, 'rows for bob')
    check(sorted(names) == sorted(['Bob Smith', 'Org Team', 'Org Internal', 'Org list']), 'bob sees %r' % names)
    groups = js(d, 'return $("#directorylist ul.groups li a").map(function(){ return $(this).text().trim(); }).get();')
    check('Sprzedaż' not in groups and 'Sales' in groups, 'groups for bob: %r' % groups)


def recipient_boxes(d):
    """The recipient elements of the Elastic widget: [name text, e-mail text, title attribute]."""
    return js(d, 'return $("#compose_to .recipient-input li.recipient").map(function(){ return [[$(this).find(".name").text(), '
                 '$(this).find(".email").text(), $(this).attr("title") || ""]]; }).get();') or []


@case('U8 audit U-04: a directory name stays text in the suggestion list and in the recipient widget (Elastic)')
def u8_names(d):
    with open(os.path.join(C.run, 'galbridge.inc.php')) as f:
        dsn = re.search(r"galbridge_db_dsn'\] = '([^']+)'", f.read()).group(1)
    try:
        fixture_admin.execute(dsn, ['SELECT 1'])
    except fixture_admin.Unavailable as e:
        raise Skip(str(e))
    login(d, JAN)
    fixture_admin.execute(dsn, fixture_admin.SPOOF_APPLY)
    try:
        # what the widget does with a name it is given: it is inserted as HTML, so a character reference
        # in a name would be shown decoded (recorded only - the plugin must not depend on the core here)
        probe = compose_to_input(d)
        probe.send_keys('"ceo&#64;bank.example" <probe@example.com>,')
        shown = [b[0] for b in recipient_boxes(d)]
        print('     (core: a typed name "ceo&#64;bank.example" is shown as %r)' % (shown[0] if shown else None))
        for address in sorted(fixture_admin.SPOOF_ROWS):
            inp = compose_to_input(d)
            inp.send_keys(address.split('@')[0])
            wait(d, lambda x: any(address in i for i in ksearch_items(x)), timeout=10, msg='no suggestion for %s: %r' % (address, ksearch_items(d)))
            item = [i for i in ksearch_items(d) if address in i][0]
            check('ceo@' not in item and '@bank' not in item and item.count('@') == 1, 'suggestion shows another address: %r' % item)
            inp.send_keys(Keys.ENTER)
            wait(d, lambda x: address in (js(x, 'return $("#_to").val()') or ''), msg='recipient not inserted: %r' % js(d, 'return $("#_to").val()'))
            boxes = recipient_boxes(d)
            check(len(boxes) == 1, 'recipient elements: %r' % boxes)
            name, mail, title = boxes[0]
            check('@' not in name and '\uff20' not in name, 'the name of the recipient element looks like an address: %r' % name)
            # the notation of Normalizer::displayText(): '&' and curly brackets full-width, both delimiters
            # of an encoded word split, and no '=' or '?' at either end of the text (full-width there)
            expected = fixture_admin.SPOOF_ROWS[address].replace('&', '\uff06').replace('{', '\uff5b').replace('}', '\uff5d')
            expected = expected.replace('=?', '= ?').replace('?=', '? =')
            expected = re.sub(r'^[=?]|[=?]$', lambda m: '\uff1d' if m.group(0) == '=' else '\uff1f', expected)
            # (Roundcube quotes a display name that holds special characters)
            check(name.strip('"') == expected, 'name shown for %s: %r, expected %r' % (address, name, expected))
            check(address in mail and address in title, 'the element does not carry the real address: %r' % boxes[0])
            value = js(d, 'return $("#_to").val()')
            check(value.count('@') == 1 and address in value, 'To field: %r' % value)
            check('ceo@bank.example' not in value and '=?' not in value, 'To field carries a decoded or encoded address: %r' % value)
    finally:
        fixture_admin.execute(dsn, fixture_admin.SPOOF_REVERT)


@case('U9 no JavaScript errors on any visited page')
def u9(d):
    errs = js_errors(d)
    check(not errs, 'JS errors: %r' % errs)


# =========================================================================== main

def prepare():
    a = C.args
    r = subprocess.run([os.path.join(PLUGIN, 'tests', 'e2e', 'setup.sh'), a.rc, a.php, a.db, str(a.port)],
                       capture_output=True, timeout=180)
    if r.returncode != 0:
        raise SystemExit('setup.sh failed: %s %s' % (r.stdout.decode()[-2000:], r.stderr.decode()[-2000:]))
    C.run = [ln.split('=', 1)[1] for ln in r.stdout.decode().splitlines() if ln.startswith('RUN=')][0]
    C.base = 'http://127.0.0.1:%d/' % a.port
    end = time.time() + 20
    while time.time() < end:
        with contextlib.suppress(requests.RequestException):
            if requests.get(C.base + '?_task=login', timeout=3).status_code == 200:
                return
        time.sleep(0.3)
    raise SystemExit('Roundcube did not come up on %s' % C.base)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--rc', default='/opt/rcrun/1.7.7')
    ap.add_argument('--php', default='php8.4')
    ap.add_argument('--db', default='mysql', choices=['mysql', 'pgsql'])
    ap.add_argument('--port', type=int, default=8195)
    ap.add_argument('--keep', action='store_true', help='leave the PHP web server running and the plugin link in place')
    ap.add_argument('--allow-skips', action='store_true', help='a skipped case does not fail the run (partial local runs only)')
    C.args = ap.parse_args()
    harness.refuse_root()
    browser, drv = browser_and_driver()
    t0 = time.time()
    # the run directory setup.sh uses (known before it runs: a setup that fails half-way is cleaned up too)
    C.run = os.path.join(PLUGIN, 'tests', 'e2e', 'run', '%s-%s-%s' % (
        os.path.basename(os.path.realpath(C.args.rc)), os.path.basename(C.args.php), C.args.db))
    ok = bad = skipped = 0
    d = None
    try:
        prepare()
        d = driver(browser, drv)
        for name, fn in RESULTS:
            t = time.time()
            try:
                fn(d)
                ok += 1
                print('PASS %s (%.1fs)' % (name, time.time() - t))
            except Skip as e:
                skipped += 1
                print('SKIP %s: %s' % (name, e))
            except Exception as e:  # noqa: BLE001
                bad += 1
                print('FAIL %s: %s' % (name, e))
                if os.environ.get('UI_DEBUG'):
                    traceback.print_exc()
            sys.stdout.flush()
    finally:
        if d is not None:
            d.quit()
        if not C.args.keep:
            harness.cleanup(C.run, C.args.rc, C.args.port)
    if skipped and not C.args.allow_skips:
        print('UI: %d case(s) skipped - a skipped case proves nothing, so the run fails (--allow-skips for a partial local run)' % skipped)
    print('UI (%s): %d passed, %d failed, %d skipped (%.0fs)' % (C.args.db, ok, bad, skipped, time.time() - t0))
    sys.exit(1 if bad or (skipped and not C.args.allow_skips) else 0)


if __name__ == '__main__':
    main()
