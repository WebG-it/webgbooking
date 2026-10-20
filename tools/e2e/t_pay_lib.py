"""Helpers shared by the paid-event tests (mock Stripe in the test container). No real key is ever used: the secret
key and the webhook signing secret are random throwaway strings generated at run time."""
import sys, os, json, time, hmac, hashlib, random, string, http.client, urllib.parse
from wgbtest import *

R = lambda n: ''.join(random.choice(string.ascii_lowercase + string.digits) for _ in range(n))
SK = 'sk_' + 'test_' + R(24)          # built at run time: no key-shaped literal in the repo
WHSEC = 'whsec_' + R(32)
MOCK_KEY_FILE = '/tmp/gmock/stripe_key'


def init(**kw):
    args = ' '.join('%s=%s' % (k, v) for k, v in kw.items())
    jx('php /tmp/mock/init.php %s >/dev/null' % args)


def set_mock_key(k):
    jx("printf %%s '%s' > %s; chmod 666 %s" % (k, MOCK_KEY_FILE, MOCK_KEY_FILE))


def sign(payload, secret=None, t=None):
    t = int(time.time()) if t is None else t
    return 't=%d,v1=%s' % (t, hmac.new((secret or WHSEC).encode(), ('%d.%s' % (t, payload)).encode(), hashlib.sha256).hexdigest())


def webhook(event, secret=None, t=None, header=None, raw=None):
    body = raw if raw is not None else json.dumps(event)
    hdr = header if header is not None else sign(body, secret, t)
    u = urllib.parse.urlparse(AJAX + '&action=stripewebhook')
    c = http.client.HTTPConnection(u.hostname, u.port, timeout=60)
    h = {'Content-Type': 'application/json'}
    if hdr:
        h['Stripe-Signature'] = hdr
    c.request('POST', u.path + '?' + u.query, body, h)
    r = c.getresponse()
    out = r.read().decode()
    try:
        out = json.loads(out)
    except Exception:
        pass
    return r.status, out


def sessions():
    out = jx('cat /tmp/gmock/stripe.json 2>/dev/null') or '{}'
    try:
        return json.loads(out)
    except Exception:
        return {}


def pay_in_mock(sid, amount=None, strict=False):
    """The customer pays. strict=True: like the real Stripe, a session that is not open any more refuses the payment."""
    jx("curl -sk 'https://api.stripe.com/__mock/pay?id=%s%s%s' >/dev/null" % (sid, ('&amount=%d' % amount) if amount else '', '&strict=1' if strict else ''))


def event(etype, sid, eid=None, live=False):
    s = sessions().get(sid, {})
    return {'id': eid or 'evt_' + R(14), 'object': 'event', 'type': etype, 'livemode': live, 'data': {'object': s}}


def dbrow(i):
    cols = ['id', 'status', 'payment_status', 'amount_cents', 'currency', 'stripe_session_id', 'stripe_payment_intent', 'hold_until', 'paid_at', 'google_event_id', 'reminders_sent', 'manage_token', 'booking_date', 'booking_time']
    out = sql('SELECT %s FROM joom_webgbooking_bookings WHERE id=%d' % (','.join(cols), i))
    return dict(zip(cols, out.split('\t'))) if out else {}


def noredirect_get(url):
    u = urllib.parse.urlparse(url)
    for attempt in range(4):                      # a GET can be repeated when the connection is reset (loaded PC)
        try:
            c = http.client.HTTPConnection(u.hostname, u.port, timeout=60)
            c.request('GET', u.path + ('?' + u.query if u.query else ''))
            r = c.getresponse()
            return r.status, r.getheader('Location') or '', r.read().decode('utf-8', 'replace')
        except (ConnectionError, OSError):
            if attempt == 3:
                raise
            time.sleep(2)


def stripe_calls(method=None, path=None):
    return [l for l in gm_log() if l['host'] == 'api.stripe.com' and (method is None or l['method'] == method) and (path is None or l['path'].startswith(path))]


def fresh():
    reset_env(render=False)
    set_mock_key(SK)
    init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test')


def start_paid(cfgname, day_off, time_, email, **kw):
    r = book(cfgname, day(day_off), time_, email=email, name=kw.pop('name', 'Pagante'), phone=kw.pop('phone', ''), **kw)
    return r, last_id()


