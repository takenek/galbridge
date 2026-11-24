"""
GAL Bridge E2E - minimal Roundcube 1.7 HTTP client (TEST ONLY).

Drives a real Roundcube instance exactly like the browser does: login form, request token, AJAX
actions (X-Roundcube-Request header + _remote=1), token-protected GET downloads, and parses the
JSON responses: "exec" is a list of "this.<command>(<json args>);" lines (rcmail_output_json).

@license GPL-3.0-or-later
"""
import html
import json
import re
import urllib.parse

import requests


class RcError(Exception):
    pass


class Roundcube:
    # every response of every session is offered to these callbacks (e.g. secret leak checks)
    response_observers = []

    def __init__(self, base, user, password='testpass'):
        self.base = base.rstrip('/') + '/'
        self.user = user
        self.password = password
        self.s = requests.Session()
        self.s.hooks['response'].append(self._observe)
        self.token = None

    def _observe(self, r, *args, **kwargs):
        for cb in Roundcube.response_observers:
            cb(self.user, r)
        return r

    # ---------------------------------------------------------------- helpers
    def url(self, **params):
        return self.base + '?' + urllib.parse.urlencode(params, doseq=True)

    @staticmethod
    def env_token(text):
        m = re.search(r'"request_token":"([^"]+)"', text)
        if m:
            return m.group(1)
        m = re.search(r'name="_token" value="([^"]+)"', text)
        return m.group(1) if m else None

    @staticmethod
    def env_all(text):
        """The object passed to rcmail.set_env({...}) in an HTML page."""
        m = re.search(r'rcmail\.set_env\((\{.*?\})\);\s*\n', text, re.S)
        if not m:
            return {}
        try:
            obj, _ = json.JSONDecoder().raw_decode(m.group(1))
            return obj
        except ValueError:
            return {}

    @classmethod
    def env(cls, text, name):
        return cls.env_all(text).get(name)

    # ---------------------------------------------------------------- session
    def login(self):
        r = self.s.get(self.base + '?_task=login')
        tok = self.env_token(r.text)
        r = self.s.post(self.base + '?_task=login', data={
            '_token': tok, '_task': 'login', '_action': 'login', '_timezone': 'Europe/Warsaw',
            '_url': '', '_user': self.user, '_pass': self.password,
        }, allow_redirects=True)
        if 'rcmloginuser' in r.text and '_task=logout' not in r.text:
            raise RcError('login failed for %s' % self.user)
        r = self.s.get(self.base + '?_task=mail&_mbox=INBOX')
        self.token = self.env_token(r.text)
        if not self.token:
            raise RcError('no request token after login for %s' % self.user)
        return self

    def logout(self):
        self.s.get(self.url(_task='logout', _token=self.token))
        self.token = None

    # ---------------------------------------------------------------- requests
    def get(self, **params):
        return self.s.get(self.url(**params))

    def post(self, params, data):
        """A form POST (non-AJAX) carrying the request token."""
        d = dict(data)
        d.setdefault('_token', self.token)
        return self.s.post(self.url(**params), data=d)

    def ajax(self, task, action, data=None, method='GET'):
        """AJAX request like rcmail.http_request(): returns (status, parsed JSON or {'raw': text})."""
        headers = {'X-Roundcube-Request': self.token, 'X-Requested-With': 'XMLHttpRequest'}
        d = list((data or {}).items()) if isinstance(data, dict) else list(data or [])
        d.append(('_remote', '1'))
        d.append(('_unlock', '0'))
        if method == 'POST':
            r = self.s.post(self.url(_task=task, _action=action), data=d, headers=headers)
        else:
            r = self.s.get(self.base + '?' + urllib.parse.urlencode([('_task', task), ('_action', action)] + d, doseq=True),
                           headers=headers)
        try:
            return r.status_code, r.json()
        except ValueError:
            return r.status_code, {'raw': r.text}

    # ---------------------------------------------------------------- response parsing
    @staticmethod
    def commands(resp):
        """[(name, [args...])] from the "exec" string of a JSON response."""
        out = []
        for line in (resp.get('exec') or '').split('\n'):
            m = re.match(r'^this\.([A-Za-z_][\w.]*)\((.*)\);$', line)
            if not m:
                continue
            try:
                args = json.loads('[' + m.group(2) + ']')
            except ValueError:
                args = [m.group(2)]
            out.append((m.group(1), args))
        return out

    @classmethod
    def command_args(cls, resp, name):
        return [a for n, a in cls.commands(resp) if n == name]

    @classmethod
    def contact_rows(cls, resp):
        """add_contact_row(id, cols, classes, data) calls: list of dicts {id, cols, classes, data}."""
        rows = []
        for a in cls.command_args(resp, 'add_contact_row'):
            rows.append({'id': a[0], 'cols': a[1] if len(a) > 1 else {}, 'classes': a[2] if len(a) > 2 else '',
                         'data': a[3] if len(a) > 3 else {}})
        return rows

    @staticmethod
    def messages(text):
        """display_message("text", "type", ...) calls in an HTML page or a JSON exec string."""
        out = []
        for m in re.finditer(r'display_message\("((?:[^"\\]|\\.)*)","(\w+)"', text):
            out.append((json.loads('"' + m.group(1) + '"'), m.group(2)))
        return out

    @classmethod
    def resp_messages(cls, resp):
        return [(a[0], a[1] if len(a) > 1 else '') for a in cls.command_args(resp, 'display_message')]

    @staticmethod
    def textarea(page, name):
        m = re.search(r'<textarea[^>]*name="%s"[^>]*>(.*?)</textarea>' % re.escape(name), page, re.S)
        return html.unescape(m.group(1)) if m else None

    @staticmethod
    def text_of(fragment):
        return html.unescape(re.sub(r'<[^>]+>', ' ', fragment))

    # ---------------------------------------------------------------- address book flows
    def addressbook_page(self, **params):
        p = {'_task': 'addressbook'}
        p.update(params)
        return self.get(**p).text

    def list_contacts(self, source, gid=None, page=None):
        d = {'_source': source}
        if gid is not None:
            d['_gid'] = gid
        if page is not None:
            d['_page'] = page
        return self.ajax('addressbook', 'list', d)

    def quick_search(self, q, headers='name,firstname,surname,email,*', scope=None):
        d = {'_q': q, '_headers': headers}
        if scope is not None:
            d['_scope'] = scope
        return self.ajax('addressbook', 'search', d)

    def autocomplete(self, search, source='', reqid='1'):
        return self.ajax('mail', 'autocomplete', {'_search': search, '_source': source, '_reqid': reqid}, method='POST')

    @classmethod
    def autocomplete_results(cls, resp):
        calls = cls.command_args(resp, 'ksearch_query_results')
        return calls[0][0] if calls else None

    def export(self, **params):
        p = {'_task': 'addressbook', '_action': 'export', '_token': self.token}
        p.update(params)
        return self.s.get(self.base + '?' + urllib.parse.urlencode(p, doseq=True))

    def mailto(self, data):
        """POST addressbook/mailto (compose to selected / group); returns (resp, To value or None)."""
        _, resp = self.ajax('addressbook', 'mailto', data, method='POST')
        steps = self.command_args(resp, 'open_compose_step')
        if not steps:
            return resp, None
        mid = steps[0][0].get('_mailto')
        page = self.s.get(self.url(_task='mail', _action='compose', _mailto=mid), allow_redirects=True).text
        return resp, self.textarea(page, '_to')

    def compose(self):
        r = self.s.get(self.url(_task='mail', _action='compose'), allow_redirects=True)
        return r.text


def parse_vcards(text):
    """[{fn, emails, lines}] from a text/vcard download (unfolded)."""
    cards = []
    text = re.sub(r'\r?\n[ \t]', '', text)
    for block in re.findall(r'BEGIN:VCARD\r?\n(.*?)END:VCARD', text, re.S):
        lines = [ln for ln in re.split(r'\r?\n', block) if ln]
        card = {'fn': None, 'emails': [], 'lines': lines}
        for ln in lines:
            key, _, value = ln.partition(':')
            name = key.split(';')[0].upper()
            if name == 'FN':
                card['fn'] = value
            elif name == 'EMAIL':
                card['emails'].append(value.lower())
        cards.append(card)
    return cards
