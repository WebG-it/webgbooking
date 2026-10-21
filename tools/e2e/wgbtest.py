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


# 0.36.0: the settings are the Options of the component, not of the plugin. ONE place says where, for every test.
OPTIONS_ROW = "element='com_webgbooking' AND type='component'"


def opt_set(key, value):
    """Set one option in the Options of the component (a string value)."""
    sql("UPDATE joom_extensions SET params=JSON_SET(IF(params IN ('', 'null'), '{}', params),'$.%s',%s) WHERE %s" % (key, "'" + str(value).replace(chr(92), chr(92) * 2).replace("'", "''") + "'", OPTIONS_ROW))


def opts_get():
    """All the options of the component as a dict."""
    return json.loads(sql("SELECT params FROM joom_extensions WHERE %s" % OPTIONS_ROW) or '{}')


def check(name, cond, extra=''):
    RES['pass' if cond else 'fail'] += 1
    print(('PASS ' if cond else 'FAIL ') + name + ((' | ' + str(extra)) if (extra and not cond) else ''))
    return cond


class Sess:
    def __init__(self):
        self.cj = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj))

    def get(self, url):
        for attempt in range(4):                      # Docker Desktop on a loaded PC now and then resets a connection: a GET can simply be repeated
            try:
                return self.op.open(url, timeout=60).read().decode('utf-8', 'replace')
            except urllib.error.HTTPError as e:
                return e.read().decode('utf-8', 'replace')
            except (ConnectionError, urllib.error.URLError):
                if attempt == 3:
                    raise
                time.sleep(2)

    def post(self, url, data):
        body = urllib.parse.urlencode(data).encode()
        try:
            return self.op.open(urllib.request.Request(url, data=body), timeout=60).read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.read().decode('utf-8', 'replace')


def http_get(path):
    """(status, {lower-case header: value}, body) of a page of the test site, with no cookies; repeated if the connection is reset."""
    import http.client
    u = urllib.parse.urlparse(BASE + path)
    for attempt in range(4):
        try:
            c = http.client.HTTPConnection(u.hostname, u.port, timeout=60)
            c.request('GET', u.path + ('?' + u.query if u.query else ''))
            r = c.getresponse()
            body = r.read().decode('utf-8', 'replace')
            return r.status, {k.lower(): v for k, v in r.getheaders()}, body
        except (ConnectionError, OSError):
            if attempt == 3:
                raise
            time.sleep(2)



def tz_label(tz, d, t, loc='en_GB'):
    """The readable name of a zone on a date, as the plugin writes it in the emails: the long name with intl, else "EDT, America/New_York"."""
    code = ('$z=new DateTimeZone("%s");$dt=new DateTime("%s %s:00",$z);'
            'echo class_exists("IntlDateFormatter")?(new IntlDateFormatter("%s",0,0,$z,null,"zzzz"))->format($dt):($dt->format("T").", %s");') % (tz, d, t, loc, tz)
    return jx("php -r '%s'" % code).strip()


AJAX = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=json'


def page_cfg(name):
    h = Sess().get('%s/wgbtest/%s.html' % (BASE, name))
    m = re.search(r'data-wgb="([^"]+)"', h)
    return json.loads(html.unescape(m.group(1)))


def month(ev, frm, to):
    q = AJAX + '&action=month&from=%s&to=%s' % (frm, to) + ('&ev=' + urllib.parse.quote(ev, safe='') if ev else '')
    return json.loads(Sess().get(q)).get('days', {})


def book(cfgname, date, time_, name='Mario Rossi', email='mario@example.invalid', phone='', location='', guest='', notes='', privacy='1', sess=None, cfg=None, newsletter='0', extra=None):
    cfg = cfg or page_cfg(cfgname)
    s = sess or Sess()
    tk = json.loads(s.get(AJAX + '&action=token'))['token']
    d = {'action': 'book', tk: '1', 'date': date, 'time': time_, 'name': name, 'email': email, 'phone': phone, 'notes': notes, 'guest': guest,
         'privacy': privacy, 'website': '', 'newsletter': newsletter, 'form': cfg['form']}
    if location:
        d['location'] = location
    if extra:
        d.update(extra)
    out = s.post(AJAX + '&action=book', d)
    try:
        return json.loads(out)
    except Exception:
        return {'ok': False, 'raw': out[:300]}


def form_actions(token):
    """The content of a form token (sealed since 0.34.1, readable before), read inside the container after its signature is checked."""
    out = jx("php /tmp/mock/decform.php '%s'" % token).strip().splitlines()
    return json.loads(out[-1]) if out else {}


def day(offset):
    return (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()


def dmy(iso):
    """2026-10-28 as the plugin writes it in subjects and alerts: 28/10/2026."""
    return '%s/%s/%s' % (iso[8:10], iso[5:7], iso[0:4])


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
        msg = email.message_from_bytes((raw.split('\r\n', 2)[2] if raw.startswith('X-Rcpt') else raw).encode('utf-8'), policy=policy.default)   # bytes: an 8bit utf-8 part keeps its accents
    except Exception:
        return {'subject': '', 'html': raw, 'ics': '', 'rcpt': ''}
    html_part, ics, text_part = '', '', ''
    for part in msg.walk():
        ct = part.get_content_type()
        if ct == 'text/html':
            html_part = part.get_content()
        elif ct == 'text/plain':
            text_part = part.get_content()
            if not html_part:
                html_part = text_part
        elif ct == 'text/calendar':
            ics = part.get_payload(decode=True).decode('utf-8', 'replace')
    rcpt = re.search(r'X-Rcpt: (.*)', raw)
    return {'subject': str(msg['Subject']), 'html': html_part, 'text': text_part, 'ics': ics, 'rcpt': rcpt.group(1) if rcpt else '', 'to': str(msg['To'])}


RENDER = 'php /tmp/mock/render_el.php /tmp/mock/configs.json >/dev/null; chmod -R a+rX /var/www/html/wgbtest'


def reset_env(render=True):
    """Back to a known state, whatever ran before: no bookings (not even a seeded row), empty kv/events/mock state,
    default plugin options + fake Google connection, SMTP sink alive, test pages re-rendered with the current config
    epoch. Every suite starts with it, so suites pass in any order and twice in a row on the same container."""
    if 'smtpsink' not in jx('ps aux | grep "[s]mtpsink"'):
        jx('nohup php /tmp/mock/smtpsink.php > /tmp/smtpsink.log 2>&1 &')
        time.sleep(1)
    jx('rm -f /tmp/mail/*.eml; rm -f /tmp/gmock/*')
    for t in ('webgbooking_bookings', 'webgbooking_kv', 'webgbooking_stripe_events', 'scheduler_logs'):
        sql('DELETE FROM joom_%s' % t)
    sql("UPDATE joom_scheduler_tasks SET locked=NULL, next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE type LIKE '%webg%'")
    jx('php /tmp/mock/init.php >/dev/null')
    if render:
        jx(RENDER)


def clear_state():
    reset_env()


def soon_slot():
    """A bookable slot always between ~0 and 24 h ahead, whatever the time of day (no dependence on the clock):
    today's first slot if it is early, the next free half hour of today if the day is running, else tomorrow 09:00."""
    out = jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"+3 hours\")->format(\"Y-m-d H:i\");' ; php -r 'echo \" \".(new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->format(\"Y-m-d\");'").strip().split()
    d, t, today = out[0], out[1], out[2]
    hh, mm = int(t[:2]), int(t[3:])
    if d != today:
        tomorrow = (datetime.date.fromisoformat(today) + datetime.timedelta(days=1)).isoformat()
        return tomorrow, '09:00'
    mins = hh * 60 + mm
    if mins < 9 * 60:
        return today, '09:00'
    if mins > 17 * 60 + 30:
        return (datetime.date.fromisoformat(today) + datetime.timedelta(days=1)).isoformat(), '09:00'
    mins = ((mins + 29) // 30) * 30
    return today, '%02d:%02d' % (mins // 60, mins % 60)


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
