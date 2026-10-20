"""0.34.0: Brevo integration, webhook header profiles, webhook events for the whole life of a booking, the dedicated
"Appointment moved" staff email and the admin button "Confirm and tell the customer". Needs WGB_E2E_ADMIN_PASS.
Every key and header value is a random throwaway string; Brevo and the webhook receiver are mocks inside the container."""
import sys, os, re, json, time, html as htmlmod
from wgbtest import *
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from t_admin_lib import make_admin, post_pairs

reset_env()
adm, EDIT, save_options, opts = make_admin()
D = 6
LOG = '/var/www/html/administrator/logs/webgbooking.php'
BKEY = 'xkeysib-' + R(40)
S1, S2, S3 = 'sec' + R(18), 'sec' + R(18), 'sec' + R(18)
NL = chr(92) + 'n'      # a literal backslash-n on the command line: init.php turns it into a newline


def setup(**kw):
    """Fresh plugin options (+ the fake Google link) with whatever this scenario needs."""
    jx("rm -f /tmp/gmock/log.jsonl /tmp/gmock/brevo.json /tmp/gmock/brevo_fail /tmp/gmock/brevo_attrs /tmp/gmock/hook_fail /tmp/mail/*.eml /tmp/gmock/events.json; rm -f %s" % LOG)
    sql('DELETE FROM joom_webgbooking_bookings'); sql('DELETE FROM joom_webgbooking_kv')
    jx("printf %%s '%s' > /tmp/gmock/brevo_key; chmod 666 /tmp/gmock/brevo_key" % BKEY)
    base = {'brevo_key': BKEY, 'whp1_name': 'crm', 'whp1_headers': 'Authorization: Bearer %s%sX-Api-Key: %s%sHOSTS: www.googleapis.com%sHost: evil.example%sBad Header: x%sContent-Type: text/plain' % (S1, NL, S2, NL, NL, NL, NL)}
    base.update(kw)
    jx("php /tmp/mock/init.php %s >/dev/null" % ' '.join("'%s=%s'" % (k, v) for k, v in base.items()))


def hooks():
    return [l for l in gm_log() if l['host'] == 'www.googleapis.com' and l['path'] == '/hook']


def brevo_calls():
    return [l for l in gm_log() if l['host'] == 'api.brevo.com']


def brevo_state():
    out = jx("curl -sk https://api.brevo.com/__mock/state")
    try:
        return json.loads(out)
    except Exception:
        return {}


def logtxt():
    return jx('cat %s 2>/dev/null' % LOG)


def task_id():
    return sql("SELECT id FROM joom_scheduler_tasks WHERE type LIKE '%webg%' LIMIT 1")


def run_task():
    sql("UPDATE joom_scheduler_tasks SET locked=NULL, next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE type LIKE '%webg%'")
    jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=%s >/dev/null 2>&1' % task_id())


def rowdb(i, cols='status,fx_state,fx_steps,hook_cfg'):
    out = sql('SELECT %s FROM joom_webgbooking_bookings WHERE id=%d' % (cols, i))
    return dict(zip(cols.split(','), out.split('\t')))


def clear_mail():
    jx('rm -f /tmp/mail/*.eml')


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


def flash(out):
    return txt(' '.join(re.findall(r'<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)</div>', out, flags=re.S)))


# ============================================================================ 1. the key and the profiles are secrets
print('--- 1. secrets')
setup(brevo_key='', whp1_name='', whp1_headers='')
sql("UPDATE joom_extensions SET params=JSON_REMOVE(params,'$.brevo_key','$.whp1_name','$.whp1_headers') WHERE element='webgbooking' AND folder='system'")
jx("printf %%s '%s' > /tmp/gmock/brevo_key; chmod 666 /tmp/gmock/brevo_key; rm -f /tmp/gmock/log.jsonl" % BKEY)
out = save_options({'jform[params][brevo_key]': BKEY, 'jform[params][whp1_name]': 'crm',
                    'jform[params][whp1_headers]': 'Authorization: Bearer %s\nX-Api-Key: %s\nHOSTS: www.googleapis.com\nHost: evil.example\nBad Header: x\nContent-Type: text/plain' % (S1, S2)})
o = opts()
check('K1 the Brevo key is stored ENCRYPTED, not in clear', str(o.get('brevo_key', '')).startswith('g:') and BKEY not in json.dumps(o), str(o.get('brevo_key', ''))[:12])
check('K2 the header profile is stored encrypted: neither header value is in the database in clear', str(o.get('whp1_headers', '')).startswith('g:') and S1 not in json.dumps(o) and S2 not in json.dumps(o), str(o.get('whp1_headers', ''))[:12])
bl = [l for l in brevo_calls() if l['path'] == '/v3/account']
check('K3 saving a new key checks it at once (GET /v3/account with the api-key header) and says so', bl and bl[0]['headers'].get('api-key') == BKEY and 'WebG Mock' in out, (len(bl), flash(out)[:200]))
check('K4 the profile save says how many headers are valid (2: Host, Content-Type and the line without a colon are refused)', 'crm' in out and re.search(r'2 valid header', out) is not None, flash(out)[:300])
page = adm.get(EDIT)
check('K5 the options page never shows the key or the header values again (only "set")', BKEY not in page and S1 not in page and S2 not in page and 'badge bg-success' in page, '')
out = save_options({'jform[params][notify_email]': 'staff@wgbtest.invalid'})
o = opts()
check('K6 saving something else keeps both secrets untouched (empty = keep)', str(o.get('brevo_key', '')).startswith('g:') and str(o.get('whp1_headers', '')).startswith('g:'), '')
wrong = 'xkeysib-' + R(40)
out = save_options({'jform[params][brevo_key]': wrong})
check('K7 a key Brevo refuses is saved with a clear warning (HTTP 401), not silently', '401' in out, flash(out)[:200])
out = save_options({'jform[params][brevo_key]': BKEY})
out = save_options({'jform[params][whp2_name]': 'zap', 'jform[params][whp2_headers]': 'URL: https://www.googleapis.com/hook\nX-Zap: ' + S3})
check('K8 a profile may carry the endpoint ("URL:" line) so a secret hook address never needs to be in the page', str(opts().get('whp2_headers', '')).startswith('g:') and 'https://www.googleapis.com/hook' not in json.dumps(opts()), '')
for name in ('brevo1', 'hookprof', 'hookprof_url', 'paidhook'):
    h = Sess().get('%s/wgbtest/%s.html' % (BASE, name))
    check('K9 the public markup of "%s" holds no key and no header value' % name, BKEY not in h and S1 not in h and S2 not in h and S3 not in h and 'Bearer' not in h, name)
cfg = page_cfg('hookprof')
tokjson = form_actions(cfg['form'])
check('K10 the signed token carries only the profile NAME', tokjson['webhook'].get('profile') == 'crm' and S1 not in json.dumps(tokjson), tokjson['webhook'])

# ============================================================================ 2. webhook header profiles
print('--- 2. webhook profiles')
setup()
r = book('hookprof', day(D), '09:00', email='h1@example.invalid', name='Hook Uno')
h = hooks()
check('W1 the element webhook carries the profile headers (Authorization, X-Api-Key)', len(h) == 1 and h[0]['headers'].get('authorization') == 'Bearer ' + S1 and h[0]['headers'].get('x-api-key') == S2, [x['headers'] for x in h][:1])
check('W2 forbidden header lines are ignored: Host stays the real host, Content-Type stays JSON', h and h[0]['headers'].get('host') == 'www.googleapis.com' and 'json' in h[0]['headers'].get('content-type', ''), h and (h[0]['headers'].get('host'), h[0]['headers'].get('content-type')))
check('W3 the payload names the event: booking.created', h and h[0]['body'].get('event') == 'booking.created' and h[0]['body'].get('id'), h and h[0]['body'].get('event'))

setup(booking_webhook='https://www.googleapis.com/hook', booking_webhook_profile='crm')
book('hookother', day(D), '10:00', email='h2@example.invalid', name='Hook Due')
h = hooks()
check('W4 an element with its OWN address and no profile never gets the plugin-wide profile (no credentials sent to another endpoint)', len(h) == 1 and 'authorization' not in h[0]['headers'] and 'x-api-key' not in h[0]['headers'], [x['headers'] for x in h][:1])
jx('rm -f /tmp/gmock/log.jsonl')
book('def', day(D), '11:00', email='h3@example.invalid', name='Hook Tre')
h = hooks()
check('W5 the plugin-wide webhook uses the plugin-wide profile', len(h) == 1 and h[0]['headers'].get('authorization') == 'Bearer ' + S1, [x['headers'] for x in h][:1])

setup(whp2_name='zap', whp2_headers='URL: https://www.googleapis.com/hook%sX-Zap: %s' % (NL, S3))
book('hookprof_url', day(D), '09:00', email='h4@example.invalid', name='Hook Quattro')
h = hooks()
check('W6 a profile with a "URL:" line is the endpoint when the element has none', len(h) == 1 and h[0]['headers'].get('x-zap') == S3, [x['headers'] for x in h][:1])

setup()
book('hookmissing', day(D), '09:00', email='h5@example.invalid', name='Hook Cinque')
h = hooks()
check('W7 an unknown profile name sends NOTHING (no fall back), says so in the log and the staff is told', len(h) == 0 and 'header profile' in logtxt() and 'NOT sent' in logtxt() and any('NOT sent' in m['subject'] for m in mails_to('staff@wgbtest.invalid')), logtxt()[-300:])
check('W8 no header value ever reaches the log', S1 not in logtxt() and S2 not in logtxt() and BKEY not in logtxt(), '')

# ============================================================================ 3. events for the whole life of a booking
print('--- 3. webhook events')
setup()
r = book('hookprof', day(D), '09:00', email='ev1@example.invalid', name='Evento Uno')
b = last_id(); tok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % b)
check('V0 hook_cfg is stored on the booking with the profile NAME only', 'crm' in rowdb(b)['hook_cfg'] and S1 not in rowdb(b)['hook_cfg'], rowdb(b)['hook_cfg'])
jx('rm -f /tmp/gmock/log.jsonl')
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % b)
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': day(D), 'time': '11:00'}))
h = hooks()
check('V1 RESCHEDULED: the webhook hears booking.rescheduled with the new and the previous slot, with the profile headers',
      x.get('ok') is True and len(h) == 1 and h[0]['body'].get('event') == 'booking.rescheduled' and h[0]['body'].get('time') == '11:00' and h[0]['body'].get('previous_time') == '09:00' and h[0]['body'].get('previous_date') == day(D) and h[0]['headers'].get('authorization') == 'Bearer ' + S1, (x, [y['body'].get('event') for y in h]))
jx('rm -f /tmp/gmock/log.jsonl')
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tok})
h = hooks()
check('V2 CANCELLED by the customer: booking.cancelled, cancelled_by customer, status cancelled', len(h) == 1 and h[0]['body'].get('event') == 'booking.cancelled' and h[0]['body'].get('cancelled_by') == 'customer' and h[0]['body'].get('status') == 'cancelled', [y['body'].get('event') for y in h])
r = book('hookprof', day(D), '12:00', email='ev2@example.invalid', name='Evento Due')
b2 = last_id(); jx('rm -f /tmp/gmock/log.jsonl')
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', lst)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tk, '1'), ('cid[]', str(b2))])
h = hooks()
check('V3 CANCELLED by the staff from the admin: booking.cancelled, cancelled_by staff', len(h) == 1 and h[0]['body'].get('event') == 'booking.cancelled' and h[0]['body'].get('cancelled_by') == 'staff', [y['body'].get('event') for y in h])

# paid + expired
tp.fresh()
jx("php /tmp/mock/init.php stripe_sk=%s stripe_whsec=%s stripe_mode=test brevo_key=%s 'whp1_name=crm' 'whp1_headers=Authorization: Bearer %s%sHOSTS: www.googleapis.com' >/dev/null; rm -f /tmp/gmock/log.jsonl" % (SK, WHSEC, BKEY, S1, NL))
r, p1 = tp.start_paid('paidhook', D + 1, '09:00', 'ev3@example.invalid', name='Pagante Uno')
check('V4a an unpaid hold is NOT announced to the webhook', not hooks(), [y['body'].get('event') for y in hooks()])
sid = tp.dbrow(p1)['stripe_session_id']; tp.pay_in_mock(sid); tp.webhook(tp.event('checkout.session.completed', sid))
h = hooks()
check('V4 PAID: booking.paid with status confirmed, payment_status paid and the amount', len(h) == 1 and h[0]['body'].get('event') == 'booking.paid' and h[0]['body'].get('status') == 'confirmed' and h[0]['body'].get('payment_status') == 'paid' and h[0]['body'].get('amount') == 29.0, [y['body'].get('event') for y in h])
jx('rm -f /tmp/gmock/log.jsonl')
r, p2 = tp.start_paid('paidhook', D + 2, '09:00', 'ev4@example.invalid', name='Pagante Due')
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % p2)
run_task()
h = [y for y in hooks() if y['body'].get('event') == 'booking.expired']
check('V5 EXPIRED: the hold that lapsed is announced as booking.expired', len(h) == 1 and h[0]['body'].get('status') == 'expired' and tp.dbrow(p2)['status'] == 'expired', (tp.dbrow(p2)['status'], [y['body'].get('event') for y in hooks()]))
r, p3 = tp.start_paid('paidhook', D + 3, '09:00', 'ev5@example.invalid', name='Pagante Tre')
sid3 = tp.dbrow(p3)['stripe_session_id']; jx('rm -f /tmp/gmock/log.jsonl')
tp.webhook(tp.event('checkout.session.expired', sid3))
h = [y for y in hooks() if y['body'].get('event') == 'booking.expired']
check('V5b EXPIRED also when Stripe says the session expired', len(h) == 1, [y['body'].get('event') for y in hooks()])

# delivery: a failing receiver is retried, a refusing one is not
setup()
r = book('hookprof', day(D), '09:00', email='ev6@example.invalid', name='Evento Sei')
b6 = last_id(); tok6 = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % b6)
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % b6)
jx("printf 500 > /tmp/gmock/hook_fail; chmod 666 /tmp/gmock/hook_fail; rm -f /tmp/gmock/log.jsonl")
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok6, 'date': day(D), 'time': '14:00'}))
q = sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'hq:%'")
check('V6 the receiver is down (500): the reschedule still works for the customer and the event waits in the outbox', x.get('ok') is True and q == '1', (x, q))
jx('rm -f /tmp/gmock/hook_fail; rm -f /tmp/gmock/log.jsonl')
run_task()
check('V6b still inside the backoff: nothing is re-sent yet', not [y for y in hooks() if y['body'].get('event') == 'booking.rescheduled'], len(hooks()))
sql("UPDATE joom_webgbooking_kv SET v=JSON_SET(v,'$.at',0) WHERE k LIKE 'hq:%'")
run_task()
h = [y for y in hooks() if y['body'].get('event') == 'booking.rescheduled']
check('V6c once the receiver is back the scheduled task delivers it, once, and empties the outbox', len(h) == 1 and h[0]['body'].get('time') == '14:00' and sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'hq:%'") == '0', (len(h), sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'hq:%'")))
jx("printf 401 > /tmp/gmock/hook_fail; chmod 666 /tmp/gmock/hook_fail; rm -f /tmp/gmock/log.jsonl")
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tok6})
check('V7 a receiver that REFUSES (401) is not retried forever: nothing in the outbox, the log says it', sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'hq:%'") == '0' and 'answered HTTP 401' in logtxt(), logtxt()[-250:])
jx('rm -f /tmp/gmock/hook_fail')

# ============================================================================ 4. admin: confirm and tell the customer
print('--- 4. confirm and tell the customer')
setup()
r = book('manual30', day(D), '09:00', email='man1@example.invalid', name='Manuale Uno')
m1 = last_id()
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % m1)
check('M1 a pending booking shows the button "Confirm and tell the customer" on its detail page', rowdb(m1)['status'] == 'pending' and 'booking.confirmnotify' in det, rowdb(m1)['status'])
r = book('def', day(D), '10:00', email='free1@example.invalid', name='Libero Uno')
f1 = last_id()
det2 = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % f1)
check('M2 a booking that is already confirmed does not show it', 'booking.confirmnotify' not in det2, '')
clear_mail(); jx('rm -f /tmp/gmock/log.jsonl')
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', det)[0]
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(m1)), ('option', 'com_webgbooking'), (tk, '1')])
cm = mails_to('man1@example.invalid')
check('M3 the click confirms the booking and sends the customer the "Appointment confirmed" email (with the .ics)', rowdb(m1)['status'] == 'confirmed' and len(cm) == 1 and ('confirmed' in cm[0]['subject'].lower() or 'confermato' in cm[0]['subject'].lower()) and 'request' not in cm[0]['subject'].lower() and 'richiesta' not in cm[0]['subject'].lower(), (rowdb(m1)['status'], [m['subject'] for m in cm]))
check('M3b the admin is told in a clear line', 'customer was emailed' in txt(out) or 'cliente avvisato' in txt(out), txt(out)[:200])
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(m1)), ('option', 'com_webgbooking'), (tk, '1')])
check('M4 a second click does nothing: no second email, the admin is told it was not waiting', len(mails_to('man1@example.invalid')) == 1 and ('not waiting' in txt(out) or 'in attesa di conferma' in txt(out)), txt(out)[:200])

# the webhook hears booking.confirmed
setup()
r = book('manual30', day(D), '09:00', email='man2@example.invalid', name='Manuale Due')
m2 = last_id()
sql("UPDATE joom_webgbooking_bookings SET hook_cfg='%s' WHERE id=%d" % (json.dumps({'url': 'https://www.googleapis.com/hook', 'method': '', 'profile': 'crm'}), m2))
jx('rm -f /tmp/gmock/log.jsonl')
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % m2)
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', det)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(m2)), ('option', 'com_webgbooking'), (tk, '1')])
h = hooks()
check('M5 the webhook hears booking.confirmed (confirmed_by staff)', len(h) == 1 and h[0]['body'].get('event') == 'booking.confirmed' and h[0]['body'].get('confirmed_by') == 'staff' and h[0]['headers'].get('authorization') == 'Bearer ' + S1, [y['body'].get('event') for y in h])

# ============================================================================ 5. the staff email of a reschedule has its own subject
print('--- 5. staff email "moved"')
setup()
clear_mail()
r = book('staffto', day(D), '09:00', email='mv1@example.invalid', name='Spostato Uno')
s1 = last_id(); tok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % s1)
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % s1)
clear_mail()
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': day(D + 1), 'time': '15:00'}))
st = [m for m in mails_to('elem-staff@example.invalid')]
check('S1 the staff email of a reschedule says "Appointment moved" (not "New booking")', x.get('ok') is True and len(st) == 1 and ('moved' in st[0]['subject'].lower() or 'spostato' in st[0]['subject'].lower()) and 'new booking' not in st[0]['subject'].lower() and 'nuova prenotazione' not in st[0]['subject'].lower(), [m['subject'] for m in st])
check('S2 ...with the new slot in the subject and the previous one in the text', st and '15:00' in st[0]['subject'] and str((__import__('datetime').date.fromisoformat(day(D + 1))).day) in st[0]['subject'] and dmy(day(D)) in st[0]['html'] and '09:00' in st[0]['html'], st and st[0]['subject'])
clear_mail()
r = book('staffto', day(D + 2), '09:00', email='mv2@example.invalid', name='Nuovo Due')
st = mails_to('elem-staff@example.invalid')
check('S3 a NEW booking has the "New Event" subject', len(st) == 1 and ('new event' in st[0]['subject'].lower() or 'nuovo evento' in st[0]['subject'].lower()), [m['subject'] for m in st])

# ============================================================================ 6. Brevo
print('--- 6. Brevo')
setup()
r = book('brevo1', day(D), '09:00', email='anna@example.invalid', name='Mario De Luca', phone='+39 333 777 0001', newsletter='0',
         extra={'custom[cf0]': 'Torino', 'custom[cf1]': 'WebG'})
b = last_id()
bc = [l for l in brevo_calls() if l['path'] == '/v3/contacts']
c = brevo_state().get('contacts', {}).get('anna@example.invalid', {})
check('B1 a booking on an element set to "every booking" creates the contact (POST /v3/contacts, api-key header)', r.get('ok') is True and len(bc) == 1 and bc[0]['headers'].get('api-key') == BKEY and True, (len(bc), r))
check('B2 NOME = first word, COGNOME = the rest, SMS in E.164 (spaces removed)', c.get('attributes', {}).get('NOME') == 'Mario' and c.get('attributes', {}).get('COGNOME') == 'De Luca' and c.get('attributes', {}).get('SMS') == '+393337770001', c)
check('B3 extra attributes come from the form answers (CITTA=Citta, progetto = progetto, case and spaces forgiven)', c.get('attributes', {}).get('CITTA') == 'Torino' and c.get('attributes', {}).get('PROGETTO') == 'WebG', c.get('attributes'))
check('B4 without the newsletter tick the contact is saved WITHOUT any list (no subscription without consent)', c.get('listIds') == [], c.get('listIds'))
check('B4b ...and the call carries no listIds and no updateEnabled (never overwrites an existing contact)', 'listIds' not in bc[0]['body'] and 'updateEnabled' not in bc[0]['body'], bc[0]['body'].keys())
check('B5 the step is recorded and the booking is done', '"brevo":"ok"' in rowdb(b)['fx_steps'].replace(' ', '') and rowdb(b)['fx_state'] == 'done', rowdb(b))
check('B6 the customer sees nothing about Brevo', 'revo' not in json.dumps(r), r)

# newsletter tick -> subscription only through double opt-in; an existing contact is never overwritten
setup()
book('brevo_nl', day(D), '09:00', email='piero@example.invalid', name='Piero', newsletter='1')
st = brevo_state()
check('B7 newsletter tick on a "newsletter only" element with lists 7 and 12: the subscription goes THROUGH double opt-in (lists 7 and 12, NOME only), no direct contact', len(st.get('doi', [])) == 1 and sorted(st['doi'][0]['lists']) == [7, 12] and st['doi'][0]['attributes'].get('NOME') == 'Piero' and 'COGNOME' not in st['doi'][0]['attributes'] and not st.get('contacts'), st)
setup()
book('brevo1', day(D), '09:00', email='piero@example.invalid', name='Piero Vero')
sql("DELETE FROM joom_webgbooking_bookings")
jx('rm -f /tmp/gmock/log.jsonl')
r = book('brevo1', day(D), '10:00', email='piero@example.invalid', name='Piero Falso', extra={'custom[cf0]': 'Roma'})
b8 = last_id()
c2 = brevo_state().get('contacts', {}).get('piero@example.invalid', {})
check('B8 the same email again (anyone can type any email): the existing contact is left EXACTLY as it was, no overwrite, no error', c2.get('attributes', {}).get('COGNOME') == 'Vero' and 'CITTA' not in c2.get('attributes', {}) and c2.get('updates') == 1 and '"brevo":"ok"' in rowdb(b8)['fx_steps'].replace(' ', '') and not [m for m in mails_to('staff@wgbtest.invalid') if m['subject'].startswith('WebG Booking') and 'Brevo' in m['subject']], (c2, rowdb(b8)['fx_steps']))
setup()
book('brevo_nl', day(D), '09:00', email='senza@example.invalid', name='Senza Spunta', newsletter='0')
check('B9 no newsletter tick on a "newsletter only" element: Brevo is not called at all', not brevo_calls() and not brevo_state().get('contacts'), len(brevo_calls()))

# phone formats
setup()
for em, ph, want in (('p1@example.invalid', '333 777 0002', '+393337770002'), ('p2@example.invalid', '0039 333 777 0003', '+393337770003'), ('p3@example.invalid', 'non e un numero', None), ('p4@example.invalid', '+39 12', None), ('p5@example.invalid', '', None), ('p6@example.invalid', '+44 7911 123456', '+447911123456')):
    book('brevo1', day(D), '09:00', email=em, name='Tel Prova', phone=ph)
    sql("DELETE FROM joom_webgbooking_bookings");
    got = brevo_state().get('contacts', {}).get(em, {}).get('attributes', {}).get('SMS')
    check('B10 phone "%s" -> %s' % (ph, want), got == want, got)

# double opt-in
setup()
book('brevo_doi', day(D), '09:00', email='doi1@example.invalid', name='Doppio Optin', newsletter='1')
st = brevo_state()
doi = st.get('doi', [])
check('B11 double opt-in with the newsletter tick: POST doubleOptinConfirmation (template 5, list 12, redirect) and NO direct contact creation', len(doi) == 1 and doi[0]['templateId'] == 5 and doi[0]['lists'] == [12] and doi[0]['redirectionUrl'] == 'https://www.example.invalid/grazie' and 'doi1@example.invalid' not in st.get('contacts', {}), (doi, list(st.get('contacts', {}))))
setup()
book('brevo_doi', day(D), '09:00', email='doi2@example.invalid', name='Doppio Due', newsletter='0')
check('B12 double opt-in element without the tick: nothing is sent to Brevo (no consent)', not brevo_calls(), len(brevo_calls()))
setup()
r = book('brevo_doi_bad', day(D), '09:00', email='doi3@example.invalid', name='Doppio Tre', newsletter='1')
b = last_id()
al = mails_to('staff@wgbtest.invalid')
check('B13 double opt-in turned on WITHOUT a template: no contact is added without consent, the staff is told, the booking works', r.get('ok') is True and not brevo_state().get('contacts') and not brevo_state().get('doi') and any('Brevo' in m['subject'] for m in al) and 'template ID' in logtxt(), [m['subject'] for m in al])

# Brevo down: the booking never suffers, the contact follows
setup()
jx("printf 500 > /tmp/gmock/brevo_fail; chmod 666 /tmp/gmock/brevo_fail")
clear_mail()
r = book('brevo1', day(D), '09:00', email='down@example.invalid', name='Brevo Giu', extra={'custom[cf0]': 'Torino'})
b = last_id()
nm = len(mails())
check('B14 Brevo down (500): the booking is confirmed, the customer email and the Google event are done', r.get('ok') is True and len(mails_to('down@example.invalid')) == 1 and len(gm_events()) == 1, (r, len(mails_to('down@example.invalid'))))
check('B15 ...and only the Brevo step is left to retry (fx_state retry)', rowdb(b)['fx_state'] == 'retry' and '"brevo"' not in rowdb(b)['fx_steps'] and '"mail_customer":true' in rowdb(b)['fx_steps'].replace(' ', ''), rowdb(b))
jx('rm -f /tmp/gmock/brevo_fail')
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % b)
run_task()
check('B16 Brevo back: the scheduled task adds the contact, the booking is done, NOTHING is sent twice (mails, events)', 'down@example.invalid' in brevo_state().get('contacts', {}) and rowdb(b)['fx_state'] == 'done' and len(mails()) == nm and len(gm_events()) == 1, (rowdb(b)['fx_state'], len(mails()), nm))
jx("printf 429 > /tmp/gmock/brevo_fail; chmod 666 /tmp/gmock/brevo_fail")
r = book('brevo1', day(D), '10:00', email='slow@example.invalid', name='Brevo Lento')
b = last_id()
check('B17 Brevo answers 429 (rate limit): retried, not an error', rowdb(b)['fx_state'] == 'retry' and '"brevo":"error"' not in rowdb(b)['fx_steps'].replace(' ', ''), rowdb(b)['fx_state'])
jx('rm -f /tmp/gmock/brevo_fail')

# permanent errors: logged in the site language, staff mailed once, never on screen, no retry loop
setup()
jx("printf 'NOME,COGNOME,SMS' > /tmp/gmock/brevo_attrs; chmod 666 /tmp/gmock/brevo_attrs")
clear_mail()
r = book('brevo1', day(D), '09:00', email='bad@example.invalid', name='Attributo Mancante', extra={'custom[cf0]': 'Torino'})
b = last_id()
lg = logtxt()
al = [m for m in mails_to('staff@wgbtest.invalid') if m['subject'].startswith('WebG Booking') and 'Brevo' in m['subject']]
check('B18 an attribute Brevo does not know (400): the booking works, nothing is shown to the customer', r.get('ok') is True and 'revo' not in json.dumps(r) and 'CITTA' not in json.dumps(r), r)
check('B19 ...the step ends as "error" (a retry could not fix it), the booking is done, no retry loop', '"brevo":"error"' in rowdb(b)['fx_steps'].replace(' ', '') and rowdb(b)['fx_state'] == 'done', rowdb(b))
check('B20 ...the log explains it with Brevo\'s reason, without the customer email or any key', 'Brevo: contact of booking' in lg and 'CITTA' in lg and 'bad@example.invalid' not in lg and BKEY not in lg, lg[-300:])
check('B21 ...and the staff gets one email about it, in the element recipient or the plugin address, without the key', len(al) == 1 and BKEY not in (al[0].get('html', '') + al[0].get('text', '')) and 'Attributo Mancante' in (al[0].get('html', '') + al[0].get('text', '')), [m['subject'] for m in al])
n_calls = len(brevo_calls())
run_task()
check('B22 the next task run does not call Brevo again for it', len(brevo_calls()) == n_calls, (len(brevo_calls()), n_calls))
# wrong key
setup()
jx("printf %%s 'xkeysib-%s' > /tmp/gmock/brevo_key" % R(30))
clear_mail()
r = book('brevo1', day(D), '09:00', email='key@example.invalid', name='Chiave Sbagliata')
b = last_id()
check('B23 a key Brevo refuses (401): booking works, error step, log says the key is refused, staff mailed', r.get('ok') is True and '"brevo":"error"' in rowdb(b)['fx_steps'].replace(' ', '') and 'refuses the API key' in logtxt() and any('Brevo' in m['subject'] for m in mails_to('staff@wgbtest.invalid')), logtxt()[-200:])
# no key
setup(brevo_key='')
sql("UPDATE joom_extensions SET params=JSON_REMOVE(params,'$.brevo_key') WHERE element='webgbooking' AND folder='system'")
r = book('brevo1', day(D), '09:00', email='nokey@example.invalid', name='Senza Chiave')
b = last_id()
check('B24 Brevo switched on for the element but no key in the plugin options: booking works, clear log line, staff mailed', r.get('ok') is True and 'API key is not set' in logtxt() and '"brevo":"error"' in rowdb(b)['fx_steps'].replace(' ', ''), logtxt()[-200:])

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
