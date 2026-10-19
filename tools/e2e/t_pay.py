"""Paid events (Stripe Checkout) against the mock Stripe inside the test container."""
import sys, os, json, time, base64, urllib.parse
from wgbtest import *
from t_pay_lib import *
import t_pay_lib
SK, WHSEC = t_pay_lib.SK, t_pay_lib.WHSEC

reset_env()
fresh()
D = 12
# ------------------------------------------------------------------ setup checks
params = sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system'")
check('P0 keys are stored encrypted (g: prefix), the raw key is nowhere in the options', json.loads(params)['stripe_sk'].startswith('g:') and SK not in params and WHSEC not in params, params[:80])

# ------------------------------------------------------------------ free events unchanged
r, fid = start_paid('def', D, '09:00', 'free@example.invalid')
check('P1 free event: confirmed as before (no redirect, no Stripe call)', r.get('ok') is True and 'redirect' not in r and not stripe_calls('POST', '/v1/checkout'), r)

# ------------------------------------------------------------------ paid booking creates a held slot + a Checkout Session
clear_calls = jx('rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml')
cfg30 = page_cfg('paid30')
check('P2a widget cfg carries the price text and hold time', cfg30['price'] and '29' in cfg30['price']['text'] and cfg30['price']['hold'] == 30, cfg30.get('price'))
r, b1 = start_paid('paid30', D, '10:00', 'pay1@example.invalid', name='Prima Pagante', guest='ospite@example.invalid')
rw = dbrow(b1)
check('P2 paid booking answers with a redirect to the Stripe page, not a confirmation', r.get('ok') is True and r.get('redirect', '').startswith('https://checkout.stripe.test/c/pay/cs_test_mock'), r)
check('P2b row: awaiting_payment / pending / 2900 EUR / session id / hold ~30 min', rw['status'] == 'awaiting_payment' and rw['payment_status'] == 'pending' and rw['amount_cents'] == '2900' and rw['currency'] == 'EUR' and rw['stripe_session_id'].startswith('cs_test_mock') and rw['hold_until'] != 'NULL', rw)
mins = int(sql("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), hold_until) FROM joom_webgbooking_bookings WHERE id=%d" % b1))
check('P2c hold is 29-30 minutes from now', 28 <= mins <= 30, mins)
cs = stripe_calls('POST', '/v1/checkout/sessions')
f = urllib.parse.parse_qs(cs[0]['body'] if cs and isinstance(cs[0]['body'], str) else '', keep_blank_values=True)
check('P2d Checkout Session request: amount, currency, receipt_email, customer_email, metadata, success/cancel URLs', cs and f.get('line_items[0][price_data][unit_amount]') == ['2900'] and f.get('line_items[0][price_data][currency]') == ['eur'] and f.get('payment_intent_data[receipt_email]') == ['pay1@example.invalid'] and f.get('customer_email') == ['pay1@example.invalid'] and f.get('metadata[booking_id]') == [str(b1)] and 'payreturn' in f['success_url'][0] and '{CHECKOUT_SESSION_ID}' in f['success_url'][0] and 'paycancel' in f['cancel_url'][0] and f.get('mode') == ['payment'], f)
check('P2e secret key sent only to Stripe as a Bearer header (never in the body/URL)', SK not in json.dumps([c['body'] for c in cs]) and SK not in ' '.join(c['path'] + c['query'] for c in cs))
check('P2f NOTHING else ran: no email, no Google event, no webhook/sheet', len(mails()) == 0 and not [l for l in gm_log() if 'googleapis' in l['host'] and l['path'].startswith('/calendar/v3/calendars')], (len(mails()), [l['path'] for l in gm_log()]))
mm = month(cfg30['ev'], day(D), day(D)).get(day(D), [])
check('P3 the held slot is not offered any more', '10:00' not in mm, mm[:4])
r2, _ = start_paid('paid30', D, '10:00', 'other@example.invalid')
check('P3b a second visitor cannot take the held slot', r2.get('ok') is False, r2)

# ------------------------------------------------------------------ forged / missing signed config
cfgx = dict(cfg30)
sig = cfg30['form'].rsplit('.', 1)[1]
import base64
conf = form_actions(cfg30['form'])
conf['price'] = {}
forged = base64.b64encode(json.dumps(conf).encode()).decode() + '.' + sig
r = book('paid30', day(D), '12:00', email='forge@example.invalid', cfg={**cfg30, 'form': forged})
check('P4 forged price in the signed config is rejected', r.get('ok') is False, r)
r = book('paid30', day(D), '12:00', email='forge@example.invalid', cfg={**cfg30, 'form': ''})
check('P4b missing config token is rejected (no free booking of a paid event)', r.get('ok') is False, r)
check('P4c nothing stored for the forgeries', sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='forge@example.invalid'") == '0')

# ------------------------------------------------------------------ webhook: signature checks
sid1 = rw['stripe_session_id']
pay_in_mock(sid1)
evp = event('checkout.session.completed', sid1)
st, out = webhook(evp, secret='whsec_' + R(32))
check('P5 webhook with a WRONG secret -> 400, booking untouched', st == 400 and dbrow(b1)['status'] == 'awaiting_payment', (st, out))
st, out = webhook(evp, header='t=%d,v1=%s' % (int(time.time()), 'a' * 64))
check('P5b webhook with a made-up signature -> 400', st == 400 and dbrow(b1)['status'] == 'awaiting_payment', (st, out))
st, out = webhook(evp, header='')
check('P5c webhook without the header -> 400', st == 400)
st, out = webhook(evp, t=int(time.time()) - 3600)
check('P5d correctly signed but 1 hour old (replay) -> 400', st == 400 and dbrow(b1)['status'] == 'awaiting_payment', (st, out))
body = json.dumps(evp)
hdr = sign(body)
st, out = webhook(None, raw=body + ' ', header=hdr)
check('P5e payload altered after signing -> 400', st == 400, (st, out))
jx("php /tmp/mock/init.php stripe_sk=%s stripe_mode=test >/dev/null" % SK)
st, out = webhook(evp)
check('P5f no signing secret configured -> every webhook rejected (400)', st == 400, (st, out))
init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test')
st, out = webhook(event('checkout.session.completed', sid1, live=True))
check('P5g live-mode event on a test-mode site is acknowledged and ignored', st == 200 and out.get('ignored') == 'mode' and dbrow(b1)['status'] == 'awaiting_payment', (st, out))

# ------------------------------------------------------------------ webhook: valid payment confirms everything once
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
jx('php /tmp/mock/init.php stripe_sk=%s stripe_whsec=%s stripe_mode=test booking_webhook=https://www.googleapis.com/hook >/dev/null' % (SK, WHSEC))
sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
st, out = webhook(evp)
rw = dbrow(b1)
check('P6 valid signed checkout.session.completed -> confirmed + paid', st == 200 and out.get('result') == 'confirmed' and rw['status'] == 'confirmed' and rw['payment_status'] == 'paid' and rw['paid_at'] != 'NULL' and rw['stripe_payment_intent'].startswith('pi_mock_'), (st, out, rw))
ms = [decode_mail(mail_text(p)) for p in mails()]
cust = next((m for m in ms if 'pay1@' in m['rcpt']), None)
check('P6b customer confirmation says "Payment received: 29,00 €"', cust is not None and ('Payment received' in cust['html'] or 'Pagamento ricevuto' in cust['html']) and ('29.00' in cust['html'] or '29,00' in cust['html']), cust and cust['html'][:300])
check('P6c guest invited, staff notified (with the paid line)', any('ospite@' in m['rcpt'] for m in ms) and any('staff@' in m['rcpt'] and ('Paid:' in m['html'] or 'Pagato:' in m['html']) for m in ms), [m['rcpt'] for m in ms])
evs = gm_events()
audit = [e for e in evs.values() if e.get('summary', '').startswith('Audit SEO')]
check('P6d Google event + Meet for the paid booking created only now (one)', len(audit) == 1 and 'conferenceData' in audit[0], evs)
lg = gm_log()
check('P6e webhook and Sheet row sent now (paid booking)', [l for l in lg if l['path'] == '/hook'] and [l for l in lg if 'sheets.googleapis' in l['host']], len(lg))
n_mail, n_ev, n_log = len(mails()), len(gm_events()), len(gm_log())
st, out = webhook(evp)
check('P7 same event delivered again -> duplicate, no second effect', st == 200 and out.get('duplicate') is True and len(mails()) == n_mail and len(gm_events()) == n_ev and len(gm_log()) == n_log, (st, out))
st, out = webhook(event('checkout.session.completed', sid1, eid='evt_' + R(14)))
check('P7b same payment under a NEW event id -> "already", still one effect', st == 200 and out.get('result') == 'already' and len(mails()) == n_mail and len(gm_events()) == n_ev, (st, out))
st, out = webhook(event('checkout.session.completed', 'cs_test_unknown'))
check('P7c unknown session -> ignored (200)', st == 200 and out.get('result') == 'ignored', (st, out))

# ------------------------------------------------------------------ safety net: return page re-reads the session from Stripe
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
r, b2 = start_paid('paid30', D, '11:00', 'pay2@example.invalid', name='Seconda')
rw2 = dbrow(b2)
ret = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=%s' % (rw2['manage_token'], rw2['stripe_session_id'])
st, loc, page = noredirect_get(ret)
check('P8 return page with the session still UNPAID: nothing confirmed ("in elaborazione")', st == 200 and dbrow(b2)['status'] == 'awaiting_payment' and ('elaborazione' in page or 'processed' in page), (st, page[-200:]))
st, loc, page = noredirect_get(ret + '&paid=1&payment_status=paid&status=complete')
check('P8b forged "paid" URL parameters change nothing', dbrow(b2)['status'] == 'awaiting_payment')
st, loc, page = noredirect_get(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=cs_test_mock999999' % rw2['manage_token'])
check('P8c return page with another session id -> not found', dbrow(b2)['status'] == 'awaiting_payment' and ('non trovato' in page.lower() or 'not found' in page.lower()), page[-160:])
pay_in_mock(rw2['stripe_session_id'])           # customer really paid, webhook has NOT arrived
st, loc, page = noredirect_get(ret)
check('P8d paid session at Stripe -> return page confirms the booking and redirects to the thank-you page', st in (301, 302, 303) and loc.split('?')[0].endswith('/wgbtest/thanks.html') and dbrow(b2)['status'] == 'confirmed' and dbrow(b2)['payment_status'] == 'paid', (st, loc, dbrow(b2)))
check('P8e the Stripe GET used the secret key as Bearer', stripe_calls('GET', '/v1/checkout/sessions/cs_test_mock'))
n = len(mails())
st, loc, page = noredirect_get(ret)
check('P8f return page reloaded: no duplicate email', len(mails()) == n)
st, out = webhook(event('checkout.session.completed', rw2['stripe_session_id']))
check('P8g the late webhook after the return page is a no-op', out.get('result') == 'already' and len(mails()) == n, out)

# ------------------------------------------------------------------ expiry
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
r, b3 = start_paid('paid30', D, '14:00', 'exp@example.invalid')
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE id=%d" % b3)
cfgp = page_cfg('paid30')
check('P9 an expired hold stops blocking the slot even before the task runs', '14:00' in month(cfgp['ev'], day(D), day(D)).get(day(D), []))
out = jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 2>&1 | tail -3')
rw3 = dbrow(b3)
check('P9b scheduled task marks it expired and closes the Stripe session', rw3['status'] == 'expired' and rw3['payment_status'] == 'expired' and stripe_calls('POST', '/v1/checkout/sessions/' + rw3['stripe_session_id'] + '/expire'), (rw3, out))
check('P9c expired booking sent nothing', len(mails()) == 0)
r, b4 = start_paid('paid30', D, '14:30', 'exp2@example.invalid')
sid4 = dbrow(b4)['stripe_session_id']
st, out = webhook(event('checkout.session.expired', sid4))
check('P9d checkout.session.expired webhook -> expired', out.get('result') == 'expired' and dbrow(b4)['status'] == 'expired', out)
# paid during the last seconds, webhook lost: the task asks Stripe before giving the slot up
r, b5 = start_paid('paid30', D, '15:00', 'last@example.invalid', name='Ultimo Secondo')
sid5 = dbrow(b5)['stripe_session_id']
pay_in_mock(sid5)
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id=%d" % b5)
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')
check('P9e hold lapsed but Stripe says paid -> the task confirms instead of expiring', dbrow(b5)['status'] == 'confirmed' and dbrow(b5)['payment_status'] == 'paid', dbrow(b5))

# ------------------------------------------------------------------ late payment
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
r, b6 = start_paid('paid30', D, '16:00', 'late1@example.invalid', name='Tardivo Libero')
sid6 = dbrow(b6)['stripe_session_id']
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), status='expired', payment_status='expired' WHERE id=%d" % b6)
pay_in_mock(sid6)
st, out = webhook(event('checkout.session.completed', sid6))
check('P10 late webhook, slot still free -> booking confirmed anyway', out.get('result') == 'confirmed' and dbrow(b6)['status'] == 'confirmed' and any('late1@' in decode_mail(mail_text(p))['rcpt'] for p in mails()), (out, dbrow(b6)))
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
r, b7 = start_paid('paid30', D, '17:00', 'late2@example.invalid', name='Tardivo Occupato')
sid7 = dbrow(b7)['stripe_session_id']
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), status='expired', payment_status='expired' WHERE id=%d" % b7)
r3 = book('def', day(D), '17:00', email='thief@example.invalid', name='Ha Preso Lo Slot')     # someone else took the freed slot
check('P11a the freed slot was taken by another visitor', r3.get('ok') is True, r3)
n_ev = len(gm_events())
pay_in_mock(sid7)
st, out = webhook(event('checkout.session.completed', sid7))
rw7 = dbrow(b7)
check('P11 late webhook, slot TAKEN -> needs_review, marked paid (no silent double meeting)', out.get('result') == 'review' and rw7['status'] == 'needs_review' and rw7['payment_status'] == 'paid', (out, rw7))
ms = [decode_mail(mail_text(p)) for p in mails()]
alert = next((m for m in ms if 'staff@' in m['rcpt'] and ('TO REVIEW' in m['subject'] or 'DA GESTIRE' in m['subject'])), None)
late_mail = [m for m in ms if 'late2@' in m['rcpt']]
check('P11b staff alert sent; the late payer is told by ONE email that the time is no longer available (not a confirmation), and no extra Google event is created', alert is not None and len(late_mail) == 1 and 'no longer available' in late_mail[0]['subject'] and len(gm_events()) == n_ev, [m['subject'] for m in ms])
check('P11c alert mentions refund by hand and the payment reference', alert is not None and ('refund' in alert['html'].lower() or 'rimbors' in alert['html'].lower()) and 'pi_mock_' in alert['html'], alert and alert['html'][:300])
check('P11d needs_review does not block the slot of the other booking (single booking at 17:00 active)', sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE booking_date='%s' AND booking_time='17:00' AND status IN ('pending','confirmed')" % day(D)) == '1')
# amount mismatch
r, b8 = start_paid('paid30', D + 1, '09:00', 'mism@example.invalid')
sid8 = dbrow(b8)['stripe_session_id']
pay_in_mock(sid8, amount=100)
st, out = webhook(event('checkout.session.completed', sid8))
check('P12 amount at Stripe differs from the price -> needs_review (not confirmed) + staff alert', out.get('result') == 'mismatch' and dbrow(b8)['status'] == 'needs_review', (out, dbrow(b8)))

# ------------------------------------------------------------------ cancel page / Stripe errors / not configured
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
r, b9 = start_paid('paid30', D + 1, '10:00', 'back@example.invalid')
rw9 = dbrow(b9)
st, loc, page = noredirect_get(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=paycancel&token=' + rw9['manage_token'])
check('P13 customer backs out of Stripe: booking expired, slot free again, Stripe session closed', dbrow(b9)['status'] == 'expired' and '10:00' in month(page_cfg('paid30')['ev'], day(D + 1), day(D + 1)).get(day(D + 1), []) and stripe_calls('POST', '/v1/checkout/sessions/' + rw9['stripe_session_id'] + '/expire'), (dbrow(b9), page[-120:]))
set_mock_key('wrong_' + R(10))
r = book('paid30', day(D + 1), '11:00', email='err@example.invalid')
check('P14 Stripe refuses the key: clean error to the visitor, the held slot is released at once', r.get('ok') is False and sql("SELECT status FROM joom_webgbooking_bookings WHERE customer_email='err@example.invalid'") == 'expired', r)
set_mock_key(SK)
jx('php /tmp/mock/init.php stripe_mode=test >/dev/null')       # no secret key at all
r = book('paid30', day(D + 1), '11:00', email='nokey@example.invalid')
check('P15 Stripe not configured: paid event refuses to book, nothing stored', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='nokey@example.invalid'") == '0', r)
init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test')

# ------------------------------------------------------------------ cancellation policy (paid bookings)
jx("rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml")
r, p1 = start_paid('paid30', D + 3, '10:00', 'pol1@example.invalid', name='Politica Uno')
pay_in_mock(dbrow(p1)['stripe_session_id']); webhook(event('checkout.session.completed', dbrow(p1)['stripe_session_id']))
tk = dbrow(p1)['manage_token']
ms0 = len(mails()); jx('rm -f /tmp/gmock/log.jsonl')
page = Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tk})
check('P16 paid booking cancelled by the customer well ahead (>24h): cancelled, Google event removed', dbrow(p1)['status'] == 'cancelled' and [l for l in gm_log() if l['method'] == 'DELETE'], dbrow(p1))
mm = [decode_mail(mail_text(p)) for p in mails()[ms0:]]
check('P16b customer told the amount will be refunded; staff told to refund by hand', any('pol1@' in m['rcpt'] and ('refund the amount' in m['html'] or 'rimborseremo' in m['html']) for m in mm) and any('staff@' in m['rcpt'] and ('refund by hand' in m['html'] or 'rimborsare a mano' in m['html']) for m in mm), [m['rcpt'] for m in mm])
r, p2 = start_paid('paid30', 0, '17:30', 'pol2@example.invalid', name='Politica Due')
pay_in_mock(dbrow(p2)['stripe_session_id'])
if r.get('ok'):
    webhook(event('checkout.session.completed', dbrow(p2)['stripe_session_id']))
    tk2 = dbrow(p2)['manage_token']
    mp = Sess().get(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + tk2)
    check('P17 booking in less than 24h: manage page explains it cannot be cancelled online, no cancel button', ('24' in mp) and 'Annulla appuntamento' not in mp and 'Cancel appointment' not in mp, re.sub('<[^>]+>', ' ', mp)[-300:])
    Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tk2})
    check('P17b forced cancel POST inside 24h is refused', dbrow(p2)['status'] == 'confirmed', dbrow(p2))
    x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tk2, 'date': day(20), 'time': '09:00'}))
    check('P17c reschedule inside 24h is refused', x.get('ok') is False and dbrow(p2)['booking_date'] == day(0), x)
else:
    print('SKIP P17 (no slot left today)', r)

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
