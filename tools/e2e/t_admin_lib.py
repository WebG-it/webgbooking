"""Admin helpers shared by the e2e tests (login, plugin options save, form parser)."""
import os, re, json, base64, urllib.parse, urllib.request, urllib.error
from html.parser import HTMLParser
from wgbtest import *


def admin_login():
    s = Sess()
    h = s.get(BASE + '/administrator/index.php')
    tok = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)
    data = {'username': 'admin', 'passwd': os.environ['WGB_E2E_ADMIN_PASS'], 'option': 'com_login', 'task': 'login', 'return': base64.b64encode(b'index.php').decode()}
    for t in tok:
        data[t] = '1'
    s.post(BASE + '/administrator/index.php', data)
    return s


def post_pairs(s, url, pairs):
    body = urllib.parse.urlencode(pairs).encode()
    try:
        return s.op.open(urllib.request.Request(url, data=body), timeout=90).read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.read().decode('utf-8', 'replace')


class FormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.pairs, self.action, self._sel, self._ta, self._in_form = [], '', None, None, False
        self._opts = {}

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == 'form' and a.get('name') == 'adminForm' or (tag == 'form' and 'plugin-form' in (a.get('id') or '')):
            self._in_form = True
            self.action = a.get('action', '')
        if not self._in_form:
            return
        if tag == 'input' and a.get('name'):
            t = (a.get('type') or 'text').lower()
            if t in ('radio', 'checkbox'):
                if 'checked' in a:
                    self.pairs.append((a['name'], a.get('value', 'on')))
            elif t not in ('submit', 'button', 'file'):
                self.pairs.append((a['name'], a.get('value', '')))
        elif tag == 'select' and a.get('name'):
            self._sel = a['name']
            self._opts[self._sel] = None
            self._first = None
        elif tag == 'option' and self._sel:
            if self._first is None:
                self._first = a.get('value', '')
            if 'selected' in a:
                self._opts[self._sel] = a.get('value', '')
        elif tag == 'textarea' and a.get('name'):
            self._ta = a['name']
            self._buf = ''

    def handle_endtag(self, tag):
        if tag == 'select' and self._sel:
            v = self._opts.get(self._sel)
            self.pairs.append((self._sel, v if v is not None else (self._first or '')))
            self._sel = None
        elif tag == 'textarea' and self._ta:
            self.pairs.append((self._ta, self._buf))
            self._ta = None
        elif tag == 'form':
            self._in_form = False

    def handle_data(self, d):
        if self._ta:
            self._buf += d



def make_admin():
    adm = admin_login()
    ext = sql("SELECT extension_id FROM joom_extensions WHERE element='webgbooking' AND folder='system'")
    edit_url = BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % ext

    def save_options(overrides, task='plugin.apply'):
        page = adm.get(edit_url)
        fp = FormParser()
        fp.feed(page)
        pairs = [(k, v) for k, v in fp.pairs if k not in overrides and k != 'task']
        pairs += [(k, v) for k, v in overrides.items()] + [('task', task)]
        url = urllib.parse.urljoin(BASE + '/administrator/', fp.action) if fp.action else edit_url
        return post_pairs(adm, url, pairs)

    def opts():
        return json.loads(sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system'"))

    return adm, edit_url, save_options, opts
