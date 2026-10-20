"""Admin side of paid events: Stripe options (secrets never echoed, encrypted, verified), lists/filters/export,
cancel/delete with Stripe + Google, plus the guest reminder (phase 1 closure). Needs WGB_E2E_ADMIN_PASS."""
import sys, os, json, time, base64
from html.parser import HTMLParser
from wgbtest import *

sys.argv = sys.argv[:3]
import importlib
tp = importlib.import_module('t_pay_lib')
SK, WHSEC, R = tp.SK, tp.WHSEC, tp.R
reset_env()
tp.fresh()


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


adm = admin_login()
ext = sql("SELECT extension_id FROM joom_extensions WHERE element='webgbooking' AND folder='system'")
EDIT = BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % ext


def save_options(overrides, task='plugin.apply'):
    page = adm.get(EDIT)
    fp = FormParser()
    fp.feed(page)
    pairs = [(k, v) for k, v in fp.pairs if k not in overrides and k != 'task']
    pairs += [(k, v) for k, v in overrides.items()] + [('task', task)]
    url = urllib.parse.urljoin(BASE + '/administrator/', fp.action) if fp.action else EDIT
    return post_pairs(adm, url, pairs)


def opts():
    return json.loads(sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system'"))


# ---------------------------------------------------------------- plugin options form
sql("UPDATE joom_extensions SET params=JSON_REMOVE(JSON_REMOVE(params,'$.stripe_sk'),'$.stripe_whsec') WHERE element='webgbooking' AND folder='system'")
tp.set_mock_key(SK)
page = adm.get(EDIT)
check('A1 options form shows the webhook URL to register', 'action=stripewebhook' in page and 'readonly' in page, '')
check('A1b secret fields are password boxes marked "not set"', page.count('type="password"') >= 2 and ('not set' in page or 'non impostata' in page), '')
out = save_options({'jform[params][stripe_sk]': SK, 'jform[params][stripe_whsec]': WHSEC, 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
o = opts()
check('A2 saving stores both secrets ENCRYPTED (AES-GCM prefix), never in clear', o.get('stripe_sk', '').startswith('g:') and o.get('stripe_whsec', '').startswith('g:') and SK not in json.dumps(o) and WHSEC not in json.dumps(o), {k: v[:6] for k, v in o.items() if k.startswith('stripe')})
check('A2b the key was checked with Stripe on save (account shown)', 'acct_mockaccount' in txt, txt[txt.find('Stripe'):][:200] if 'Stripe' in txt else txt[-300:])
page = adm.get(EDIT)
cipher = o['stripe_sk']
check('A3 re-opening the form NEVER echoes the key or its ciphertext; shows "set"', SK not in page and WHSEC not in page and cipher not in page and cipher[2:20] not in page and ('>set<' in page or '>impostata<' in page), '')
before = dict(opts())
out = save_options({'jform[params][stripe_hold_minutes]': '45'})
o2 = opts()
check('A4 saving with the secret boxes empty keeps the stored secrets, other options change', o2['stripe_sk'] == before['stripe_sk'] and o2['stripe_whsec'] == before['stripe_whsec'] and o2['stripe_hold_minutes'] == '45', o2.get('stripe_hold_minutes'))
r, bid = tp.start_paid('paid30', 12, '10:00', 'optkey@example.invalid')
check('A5 after the save/encrypt round trip the stored key still works against Stripe', r.get('ok') is True and 'redirect' in r, r)
out = save_options({'jform[params][stripe_sk]': 'sk_' + 'test_' + R(24), 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
check('A6 a key Stripe refuses is reported (401), not silently accepted', 'did not accept' in txt or 'non ha accettato' in txt, txt[-300:])
out = save_options({'jform[params][stripe_sk]': 'sk_' + 'live_' + R(24), 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
check('A7 a live key with test mode selected is an ERROR and is not saved', ('NOT saved' in txt or 'NON salvate' in txt) and opts().get('stripe_mode') == 'test', txt[-300:])
out = save_options({'jform[params][stripe_sk]': '-', 'jform[params][stripe_whsec]': '-'})
check('A8 a dash clears the secrets', opts().get('stripe_sk') == '' and opts().get('stripe_whsec') == '', {k: v for k, v in opts().items() if k.startswith('stripe')})
tp.fresh()   # back to a working config (keys via init.php)

# ---------------------------------------------------------------- admin list, filter, detail, export
D = 14
r, a1 = tp.start_paid('paid30', D, '09:00', 'adm1@example.invalid', name='Cliente Attesa')
r, a2 = tp.start_paid('paid30', D, '10:00', 'adm2@example.invalid', name='Cliente Pagato', guest='')
sid2 = tp.dbrow(a2)['stripe_session_id']
tp.pay_in_mock(sid2); tp.webhook(tp.event('checkout.session.completed', sid2))
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
check('A9 list: payment column with amount and state, Stripe reference, translated status badges', 'Payment' in lst and '29,00 EUR' in lst and 'paid' in lst and 'pi_mock_' in lst and 'Awaiting payment' in lst, re.sub('<[^>]+>', ' ', lst)[-500:])
flt = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings&filter[status]=awaiting_payment')
check('A10 status filter has the new states and works', 'Cliente Attesa' in flt and 'Cliente Pagato' not in flt and 'value="needs_review"' in lst, '')
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % a2)
check('A11 detail: payment status, amount, paid at, Stripe PI and session', '29,00 EUR' in det and 'Stripe payment intent' in det and 'pi_mock_' in det and 'Stripe checkout session' in det and 'cs_test_mock' in det and 'Paid at' in det, '')
tokf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', lst)[0]
csv = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=exportcsv&' + tokf + '=1')
check('A12 CSV export: payment columns appended with amount 29.00 EUR and PI', 'Stripe payment intent' in csv.splitlines()[0] and '29.00' in csv and 'EUR' in csv and 'pi_mock_' in csv, csv.splitlines()[0])

# admin cancel of the PAID booking: refund notice, customer mail without refund promise, staff not mailed
jx('rm -f /tmp/mail/*.eml; rm -f /tmp/gmock/log.jsonl')
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(a2))])
txt = re.sub(r'<[^>]+>', ' ', out)
mm = [decode_mail(mail_text(p)) for p in mails()]
check('A13 admin cancelling a PAID booking: warning to refund by hand from Stripe; staff gets the trace; customer mailed', ('PAID' in txt and 'Stripe' in txt) and any('staff@' in m['rcpt'] for m in mm) and any('adm2@' in m['rcpt'] for m in mm), txt[-300:])
lst2 = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings&filter[status]=')
check('A13b cancelled-but-paid shows "refund by hand" in the list', 'refund by hand' in lst2 or 'rimborsare a mano' in lst2, '')
# cancelling an UNPAID hold: quiet release (no email, no refund message)
jx('rm -f /tmp/mail/*.eml; rm -f /tmp/gmock/log.jsonl')
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(a1))])
txt = re.sub(r'<[^>]+>', ' ', out)
check('A14 admin cancelling an unpaid hold: slot released, Stripe session closed, no email, no refund notice', tp.dbrow(a1)['status'] == 'cancelled' and len(mails()) == 0 and tp.stripe_calls('POST', '/v1/checkout/sessions/' + tp.dbrow(a1)['stripe_session_id'] + '/expire') and 'was PAID' not in txt and 'were PAID' not in txt and 'era PAGATA' not in txt, txt[-200:])

# ---------------------------------------------------------------- delete removes the Google event (and closes a Stripe hold)
r, f1 = tp.start_paid('def', D + 1, '09:00', 'del1@example.invalid', name='Da Eliminare')
gid = tp.dbrow(f1)['google_event_id']
r, h1 = tp.start_paid('paid30', D + 1, '11:00', 'del2@example.invalid', name='Hold Da Eliminare')
hs = tp.dbrow(h1)['stripe_session_id']
jx('rm -f /tmp/gmock/log.jsonl')
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.delete'), ('boxchecked', '2'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(f1)), ('cid[]', str(h1))])
txt = re.sub(r'<[^>]+>', ' ', out)
dels = [l for l in gm_log() if l['method'] == 'DELETE' and gid in l['path']]
check('A15 deleting a confirmed booking from the admin also removes its Google event', sql('SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE id IN (%d,%d)' % (f1, h1)) == '0' and len(dels) == 1 and gid not in gm_events(), (gid, dels))
check('A15b deleting an open Stripe hold closes its session; the admin is told', tp.stripe_calls('POST', '/v1/checkout/sessions/' + hs + '/expire') and ('Google Calendar event removed' in txt or 'removed' in txt), txt[-250:])

# ---------------------------------------------------------------- phase-1 closure: reminder to the invited guest
def rome(plus):
    return jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"%+d minutes\")->format(\"Y-m-d H:i\");'" % plus).strip().split(' ')
r = book('hour', day(D + 2), '10:00', phone='+39 333 444 5566', location='phone', email='gcust@example.invalid', name='Cliente Con Ospite', guest='gguest@example.invalid')
gid_ = last_id()
d, t = rome(50)
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[1440]' WHERE id=%d" % (d, t, gid_))
jx('rm -f /tmp/mail/*.eml')
jx('php /tmp/mock/runrem.php')
mm = [decode_mail(mail_text(p)) for p in mails()]
cm = next((m for m in mm if 'gcust@' in m['rcpt']), None)
gm = next((m for m in mm if 'gguest@' in m['rcpt']), None)
check('A16 reminder goes to the customer (with the manage link) AND to the guest', cm is not None and gm is not None and 'action=manage' in cm['html'], [m['rcpt'] for m in mm])
check('A16b guest reminder: no manage/cancel link, no customer phone number', gm is not None and 'action=manage' not in gm['html'] and '444 5566' not in gm['html'] and 'token=' not in gm['html'], gm and gm['html'][:400])
jx('php /tmp/mock/runrem.php >/dev/null'); n = len(mails())
jx('php /tmp/mock/runrem.php >/dev/null')
check('A16c no repeat', len(mails()) == n)

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
