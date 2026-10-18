#!/usr/bin/env python3
"""End-to-end checks for WebG Booking 0.31.0 against a throwaway Joomla (docker). Usage: python wgbtest.py <port> <container-prefix> [group...]"""
import sys, os, re, json, html, time, subprocess, threading, datetime, urllib.request, urllib.parse, http.cookiejar

PORT = sys.argv[1] if len(sys.argv) > 1 else '8086'
PFX = sys.argv[2] if len(sys.argv) > 2 else 'wgbtest-6'
GROUPS = sys.argv[3:] or ['all']
BASE = 'http://localhost:%s' % PORT
J, DB = PFX + '-j', PFX + '-db'
ENV = dict(os.environ, MSYS_NO_PATHCONV='1', MSYS2_ARG_CONV_EXCL='*')
RES = {'pass': 0, 'fail': 0}


def sh(cmd, inp=None):
    r = subprocess.run(cmd, capture_output=True, env=ENV, input=inp)
    return r.stdout.decode('utf-8', 'replace') + r.stderr.decode('utf-8', 'replace')


def sql(q):
    return sh(['docker', 'exec', DB, 'mariadb', '-ujoomla', '-pjoomlapw', 'joomla', '-N', '-B', '-e', q]).strip()


def jx(cmd):
    return sh(['docker', 'exec', J, 'sh', '-c', cmd])


def check(name, cond, extra=''):
    RES['pass' if cond else 'fail'] += 1
    print(('PASS ' if cond else 'FAIL ') + name + ((' | ' + str(extra)) if (extra and not cond) else ''))
    return cond


class Sess:
    def __init__(self):
        self.cj = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj))

    def get(self, url):
        try:
            return self.op.open(url, timeout=60).read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.read().decode('utf-8', 'replace')

    def post(self, url, data):
        body = urllib.parse.urlencode(data).encode()
        try:
            return self.op.open(urllib.request.Request(url, data=body), timeout=60).read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.read().decode('utf-8', 'replace')


AJAX = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=json'


def page_cfg(name):
    h = Sess().get('%s/wgbtest/%s.html' % (BASE, name))
    m = re.search(r'data-wgb="([^"]+)"', h)
    return json.loads(html.unescape(m.group(1)))


def month(ev, frm, to):
    q = AJAX + '&action=month&from=%s&to=%s' % (frm, to) + ('&ev=' + urllib.parse.quote(ev, safe='') if ev else '')
    return json.loads(Sess().get(q)).get('days', {})


def book(cfgname, date, time_, name='Mario Rossi', email='mario@example.invalid', phone='', location='', guest='', notes='', privacy='1', sess=None, cfg=None):
    cfg = cfg or page_cfg(cfgname)
    s = sess or Sess()
    tk = json.loads(s.get(AJAX + '&action=token'))['token']
    d = {'action': 'book', tk: '1', 'date': date, 'time': time_, 'name': name, 'email': email, 'phone': phone, 'notes': notes, 'guest': guest,
         'privacy': privacy, 'website': '', 'newsletter': '0', 'form': cfg['form']}
    if location:
        d['location'] = location
    out = s.post(AJAX + '&action=book', d)
    try:
        return json.loads(out)
    except Exception:
        return {'ok': False, 'raw': out[:300]}


def day(offset):
    return (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()


def mails():
    out = jx('ls /tmp/mail/*.eml 2>/dev/null | sort -V')
    return [p for p in out.split() if p.endswith('.eml')]


def mail_text(p):
    raw = jx('cat %s' % p)
    return raw


def decode_mail(raw):
    import email
    from email import policy
    try:
        msg = email.message_from_string(raw.split('\r\n', 2)[2] if raw.startswith('X-Rcpt') else raw, policy=policy.default)
    except Exception:
        return {'subject': '', 'html': raw, 'ics': '', 'rcpt': ''}
    html_part, ics = '', ''
    for part in msg.walk():
        ct = part.get_content_type()
        if ct == 'text/html':
            html_part = part.get_content()
        elif ct == 'text/plain' and not html_part:
            html_part = part.get_content()
        elif ct == 'text/calendar':
            ics = part.get_payload(decode=True).decode('utf-8', 'replace')
    rcpt = re.search(r'X-Rcpt: (.*)', raw)
    return {'subject': str(msg['Subject']), 'html': html_part, 'ics': ics, 'rcpt': rcpt.group(1) if rcpt else '', 'to': str(msg['To'])}


def clear_state():
    jx('rm -f /tmp/mail/*.eml; rm -f /tmp/gmock/log.jsonl /tmp/gmock/events.json /tmp/gmock/busy.json')
    sql('DELETE FROM joom_webgbooking_bookings WHERE id > 1')


def gm_log():
    out = jx('cat /tmp/gmock/log.jsonl 2>/dev/null')
    return [json.loads(l) for l in out.splitlines() if l.strip().startswith('{')]


def gm_events():
    out = jx('cat /tmp/gmock/events.json 2>/dev/null') or '{}'
    try:
        return json.loads(out)
    except Exception:
        return {}


def row(id_):
    cols = ['id', 'booking_date', 'booking_time', 'status', 'duration_min', 'buffer_before', 'buffer_after', 'location', 'event_name', 'meeting_url', 'google_event_id', 'reminders_sent', 'customer_phone']
    out = sql('SELECT %s FROM joom_webgbooking_bookings WHERE id=%d' % (','.join(cols), id_))
    return dict(zip(cols, out.split('\t'))) if out else {}


def last_id():
    return int(sql('SELECT MAX(id) FROM joom_webgbooking_bookings') or 0)
