"""0.33.1: the five open points of the last review. Each check FAILS on 0.33.0 and passes on 0.33.1.
Needs WGB_E2E_ADMIN_PASS. Keys are random throwaway strings."""
import sys, os, json, time, base64, re
from wgbtest import *
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from t_admin_lib import make_admin

reset_env()
tp.fresh()
D = 6


def run_task():
    jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')


def ctx_for(cfgname):
    cfg = page_cfg(cfgname)
    actions = form_actions(cfg['form'])
    return json.dumps({'actions': actions, 'newsletter': 0, 'redirect': '', 'analytics': ''})


def sqlq(s):
    return s.replace("'", "''")


def mails_for(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


# ============================================================================ 1. effects stuck in 'pending'
print('--- 1. stuck pending effects')
# (a) paid booking: the PHP process died after the payment was recorded, before the effects UPDATE
r, a = tp.start_paid('paid30', D, '09:00', 'stuck1@example.invalid', name='Processo Morto')
sql("UPDATE joom_webgbooking_bookings SET status='confirmed', payment_status='paid', paid_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), stripe_payment_intent='pi_stuck', fx_state='pending', fx_steps='{}', fx_tries=0, fx_last=NULL WHERE id=%d" % a)
# (b) free booking in the same situation
r2 = book('def', day(D), '10:00', email='stuck2@example.invalid', name='Gratis Morto')
b = last_id()
jx('rm -f /tmp/mail/*.eml; rm -f /tmp/gmock/events.json /tmp/gmock/log.jsonl')
sql("UPDATE joom_webgbooking_bookings SET fx_state='pending', fx_steps='{}', fx_tries=0, fx_last=NULL, created=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), google_event_id='', meeting_url='', pending_ctx='%s' WHERE id=%d" % (sqlq(ctx_for('def')), b))
# (c) a row that is only seconds old: its process may still be running, it must be left alone
r3 = book('def', day(D), '11:00', email='young@example.invalid', name='Ancora Vivo')
c = last_id()
jx('rm -f /tmp/mail/*.eml')
sql("UPDATE joom_webgbooking_bookings SET fx_state='pending', fx_steps='{}', fx_tries=0, fx_last=NULL, created=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND), google_event_id='', meeting_url='', pending_ctx='%s' WHERE id=%d" % (sqlq(ctx_for('def')), c))
jx('rm -f /tmp/mail/*.eml')
run_task()
ma, mb, mc = mails_for('stuck1@'), mails_for('stuck2@'), mails_for('young@')
fa = sql("SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d" % a)
fb = sql("SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d" % b)
fc = sql("SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d" % c)
check('V1a paid booking stuck in fx_state pending: the task runs the effects (state done, customer mail sent)', fa == 'done' and len(ma) >= 1, (fa, len(ma)))
check('V1b free booking stuck in pending: the task runs the effects too', fb == 'done' and len(mb) >= 1, (fb, len(mb)))
check('V1c a pending row that is only seconds old is NOT taken (its process may still be alive)', fc == 'pending' and len(mc) == 0, (fc, len(mc)))
n_mail = len(mails())
n_ev = len(gm_events())
run_task()
check('V1d a second run does nothing more: effects ran exactly once (no extra mail, no extra Google event)', len(mails()) == n_mail and len(gm_events()) == n_ev, (len(mails()) - n_mail, len(gm_events()) - n_ev))
evs = [e for e in gm_events().values() if 'Ancora Vivo' not in e.get('summary', '')]
check('V1e one Google event per recovered booking (2), not more', len(evs) == 2, [e.get('summary') for e in evs])

# ============================================================================ 2. Google event idempotency
print('--- 2. Google event idempotency')
reset_env(); tp.fresh()
jx("printf events_lost > /tmp/gmock/google_fail; chmod 666 /tmp/gmock/google_fail")          # created, but the answer is lost
r = book('def', day(D + 1), '09:00', email='lost@example.invalid', name='Risposta Persa')
g = last_id()
st = sql("SELECT fx_state, google_event_id FROM joom_webgbooking_bookings WHERE id=%d" % g).split('\t')
check('V2a response lost after the event was created: booking kept, flagged for retry, the event already exists once at Google', r.get('ok') is True and st[0] == 'retry' and len(gm_events()) == 1, (r, st, len(gm_events())))
jx("rm -f /tmp/gmock/google_fail")
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % g)
run_task()
rw = sql("SELECT fx_state, google_event_id, meeting_url FROM joom_webgbooking_bookings WHERE id=%d" % g).split('\t')
posts = [l for l in gm_log() if l['method'] == 'POST' and l['path'].endswith('/events')]
check('V2b the retry does NOT create a second event: still ONE event at Google', len(gm_events()) == 1, [e.get('summary') for e in gm_events().values()])
check('V2c the retry recovers the existing event (id and Meet link stored on the booking)', rw[0] == 'done' and rw[1] in gm_events() and rw[2].startswith('https://meet.google.com/'), rw)
check('V2d the event id is deterministic (base32hex), derived from the booking, and contains no token', re.fullmatch(r'[a-v0-9]{20,}', rw[1] or '') is not None and sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % g) not in rw[1], rw[1])
rids = {((p['body'] or {}).get('conferenceData') or {}).get('createRequest', {}).get('requestId') for p in posts if isinstance(p['body'], dict)}
check('V2e the Meet requestId is the same on every attempt for the booking', len(posts) >= 2 and len(rids) == 1 and None not in rids, (len(posts), rids))

# ============================================================================ 3. guest and customer mails are separate steps
print('--- 3. separate guest step')
reset_env(); tp.fresh()
r = book('hour', day(D + 2), '10:00', phone='', location='meet', email='cust3@example.invalid', name='Cliente Tre', guest='reject-guest@example.invalid')
h = last_id()
steps = json.loads(sql("SELECT fx_steps FROM joom_webgbooking_bookings WHERE id=%d" % h) or '{}')
n1 = len(mails_for('cust3@'))
check('V3a the guest mail fails but the customer mail was sent and recorded as its own step', r.get('ok') is True and n1 == 1 and steps.get('mail_customer') is True and not steps.get('mail_guest'), (n1, steps))
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % h)
run_task()
check('V3b the retry does NOT send the customer mail again (still exactly one)', len(mails_for('cust3@')) == 1, len(mails_for('cust3@')))
tries = int(sql("SELECT fx_tries FROM joom_webgbooking_bookings WHERE id=%d" % h) or 0)
check('V3c only the guest step is being retried (tries went up, state still retry)', tries >= 2 and sql("SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d" % h) == 'retry', tries)

# ============================================================================ 5. livemode missing / unknown key prefix
print('--- 5. livemode / key prefix')
reset_env(); tp.fresh()
r, p = tp.start_paid('paid30', D + 3, '09:00', 'nolive@example.invalid')
sid = tp.dbrow(p)['stripe_session_id']
jx("curl -sk 'https://api.stripe.com/__mock/pay?id=%s&nolive=1' >/dev/null" % sid)
jx('rm -f /tmp/mail/*.eml')
st, out = tp.webhook(tp.event('checkout.session.completed', sid))
check('V5a a paid session WITHOUT livemode is a mismatch: not confirmed (it used to be skipped)', out.get('result') == 'mismatch' and tp.dbrow(p)['status'] == 'awaiting_payment', (out, tp.dbrow(p)))
ret = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=%s' % (tp.dbrow(p)['manage_token'], sid)
tp.noredirect_get(ret)
check('V5b the return page does not confirm it either', tp.dbrow(p)['status'] == 'awaiting_payment', tp.dbrow(p))
adm, EDIT, save_options, opts = make_admin()
before = dict(opts())
out = save_options({'jform[params][stripe_sk]': 'plainkey' + R(20), 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
check('V5c a key with an unknown prefix is refused at save (error, not saved)', opts()['stripe_sk'] == before['stripe_sk'] and ('recognised' in txt.lower() or 'riconosciuto' in txt.lower()), txt[-250:])
weird = 'whatever_' + R(20)
tp.set_mock_key(weird)
tp.init(stripe_sk=weird, stripe_whsec=WHSEC, stripe_mode='test')
r = book('paid30', day(D + 3), '10:00', email='weird@example.invalid')
check('V5d a stored key with an unknown prefix cannot open a payment either', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='weird@example.invalid'") == '0', r)

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
