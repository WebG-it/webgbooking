"""0.34.3: "I pick a date, go to sleep, meanwhile it is taken, next day I pay: it must say no."
The Stripe session ends with the hold, the customer is told clearly in every case (expired tab, late payment with the slot
taken, late payment with the slot free), by page and by email. Needs WGB_E2E_ADMIN_PASS (random throwaway Stripe keys)."""
import sys, os, re, json, time, datetime, urllib.parse
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from wgbtest import *

reset_env()
tp.fresh()
D = 6
STAFF = 'staff@wgbtest.invalid'
SRCURL = 'https://www.example.invalid/prenota'


def now_c():
    return int(jx('date +%s').strip())


def utc_ts(sqltime):
    return int(datetime.datetime.strptime(sqltime, '%Y-%m-%d %H:%M:%S').replace(tzinfo=datetime.timezone.utc).timestamp())


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


def start(day_off, time_, email, **kw):
    r, i = tp.start_paid('paid30', day_off, time_, email, name=kw.pop('name', 'Cliente Tardivo'))
    sql("UPDATE joom_webgbooking_bookings SET source_url='%s' WHERE id=%d" % (SRCURL, i))
    return i


def sid_of(i):
    return tp.dbrow(i)['stripe_session_id']


def page(i, action):
    row = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % i)
    sid = sid_of(i)
    url = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&token=%s&action=%s' % (row, action) + ('&session_id=' + sid if action == 'payreturn' else '')
    return tp.noredirect_get(url)


def run_task():
    sql("UPDATE joom_scheduler_tasks SET locked=NULL, next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE type LIKE '%webg%'")
    jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=%s >/dev/null 2>&1' % sql("SELECT id FROM joom_scheduler_tasks WHERE type LIKE '%webg%' LIMIT 1"))


def lapse(i):
    """The hold ran out by OUR clock (the Stripe session may still be open for a few seconds: the race at the edge)."""
    sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), status='expired', payment_status='expired' WHERE id=%d" % i)


# ============================================================================ 1. the Stripe session ends with the hold
print('--- 1. session and hold')
jx('rm -f /tmp/gmock/log.jsonl')
t0 = now_c(); a = start(D, '09:00', 'e1@example.invalid'); t1 = now_c()
exp = tp.sessions()[sid_of(a)]['expires_at']
hold = utc_ts(sql("SELECT hold_until FROM joom_webgbooking_bookings WHERE id=%d" % a))
check('E1 30 minute hold: the Stripe session ends exactly when the hold ends (Stripe\'s own 30 minute floor gives it a few seconds more, and the hold follows the session)', abs(exp - hold) <= 1 and t0 + 1825 <= exp <= t1 + 1840, (exp - t0, hold - t0))
body = ' '.join(str(l['body']) for l in gm_log() if l['host'] == 'api.stripe.com' and l['path'] == '/v1/checkout/sessions' and l['method'] == 'POST')
check('E1b the payment page itself says until when the slot is held and what happens after (custom text of the Checkout page)', 'custom_text%5Bsubmit%5D%5Bmessage%5D' in body and 'held+for+you+until' in body, body[:200])
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test', stripe_hold_minutes='45')
t0 = now_c(); b = start(D, '10:00', 'e2@example.invalid'); t1 = now_c()
exp = tp.sessions()[sid_of(b)]['expires_at']
hold = utc_ts(sql("SELECT hold_until FROM joom_webgbooking_bookings WHERE id=%d" % b))
check('E2 45 minute hold: session end = hold end = creation + 45 minutes', abs(exp - hold) <= 1 and t0 + 2695 <= exp <= t1 + 2710, (exp - t0, hold - t0))
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test')

# ============================================================================ 2. the tab taken up again after the hold
print('--- 2. old tab')
tp.fresh()
p1 = start(D, '09:00', 'old1@example.invalid')
s1 = sid_of(p1)
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % p1)
run_task()
check('S1 after the hold the task releases the slot and closes the Stripe session', tp.dbrow(p1)['status'] == 'expired' and tp.sessions()[s1]['status'] == 'expired', (tp.dbrow(p1)['status'], tp.sessions()[s1]['status']))
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(s1, strict=True)
check('S2 the customer opens the old tab the next day and tries to pay: Stripe refuses (the session is expired), nothing is charged, the booking stays released', tp.sessions()[s1]['payment_status'] == 'unpaid' and tp.dbrow(p1)['status'] == 'expired' and tp.dbrow(p1)['payment_status'] == 'expired', (tp.sessions()[s1]['payment_status'], tp.dbrow(p1)['status']))
st, loc, html = page(p1, 'payreturn')
t = txt(html)
check('S3 the return page of an expired booking says in plain words that the time ran out, the slot was released, no charge, choose again', st == 200 and 'time to pay has run out' in t and 'not been charged' in t and 'Choose a date and time again' in t, t[:300])
check('S3b ...with a button back to the booking page the customer came from', 'href="%s"' % SRCURL in html and 'Choose date and time again' in html, '')
st, loc, html = page(p1, 'paycancel')
check('S4 the cancel page (browser back from Stripe) of an expired booking says the same, with the same button', st == 200 and 'time to pay has run out' in txt(html) and 'href="%s"' % SRCURL in html, txt(html)[:200])
p2 = start(D, '11:00', 'back1@example.invalid')
st, loc, html = page(p2, 'paycancel')
check('S5 the customer who presses "back" while the hold is still running gets the "you did not complete the payment" page (not the expired one) and the slot is freed', 'did not complete the payment' in txt(html) and 'run out' not in txt(html) and tp.dbrow(p2)['status'] == 'expired', txt(html)[:200])
r = book('paid30', day(D), '09:00', email='other1@example.invalid')
check('S6 the released slot can be booked by someone else at once', r.get('ok') is True, r)

# ============================================================================ 3. payment arrives after the hold
print('--- 3. late payment')
tp.fresh()
l1 = start(D + 1, '09:00', 'late1@example.invalid', name='Tardivo Uno')
lapse(l1)
thief = book('def', day(D + 1), '09:00', email='thief1@example.invalid', name='Ladro Uno')
sid = sid_of(l1)
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid)
st_, out = tp.webhook(tp.event('checkout.session.completed', sid))
row = tp.dbrow(l1)
check('L1 paid at the edge of the hold with the slot taken meanwhile: the booking is NOT confirmed, it needs review, money recorded', thief.get('ok') is True and out.get('result') == 'review' and row['status'] == 'needs_review' and row['payment_status'] == 'paid', (out, row['status']))
check('L1b the reason is stored: the time is no longer available', sql("SELECT review_why FROM joom_webgbooking_bookings WHERE id=%d" % l1) == 'slot', '')
cm = mails_to('late1@example.invalid')
check('L2 the CUSTOMER gets an email at once: payment received but the time is no longer available, refund or choose another time, with the link back', len(cm) == 1 and 'no longer available' in cm[0]['subject'] and 'refund' in cm[0]['html'] and SRCURL in cm[0]['html'] and 'late1' not in cm[0]['subject'], [m['subject'] for m in cm])
check('L2b ...and the staff alert still goes out as before', any('payment' in m['subject'].lower() or 'pagamento' in m['subject'].lower() for m in mails_to(STAFF)), [m['subject'] for m in mails_to(STAFF)])
st, loc, html = page(l1, 'payreturn')
check('L3 the return page says so too (not the vague "we are checking"), with the button to choose another time', st == 200 and 'no longer available' in txt(html) and 'refunded in full' in txt(html) and 'href="%s"' % SRCURL in html, txt(html)[:240])
check('L3b the other customer who took the slot keeps it, confirmed', sql("SELECT status FROM joom_webgbooking_bookings WHERE customer_email='thief1@example.invalid'") == 'confirmed', '')
st_, out = tp.webhook(tp.event('checkout.session.completed', sid, eid='evt_' + R(14)))
check('L4 the same payment announced again (redelivery) does not send the customer a second email', len(mails_to('late1@example.invalid')) == 1, len(mails_to('late1@example.invalid')))

l2 = start(D + 2, '09:00', 'late2@example.invalid', name='Tardivo Due')
lapse(l2)
sid2 = sid_of(l2)
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid2)
st_, out = tp.webhook(tp.event('checkout.session.completed', sid2))
cm = mails_to('late2@example.invalid')
check('L5 paid after the hold but the slot is still free: the booking is CONFIRMED, the customer gets the normal confirmation (not the "no longer available" one)', out.get('result') == 'confirmed' and tp.dbrow(l2)['status'] == 'confirmed' and cm and all('no longer available' not in m['subject'] for m in cm), (out, [m['subject'] for m in cm]))
st, loc, html = page(l2, 'payreturn')
check('L5b the return page of the confirmed late payment sends the customer to the thank-you page', st in (301, 302, 303) and 'thanks' in loc, (st, loc))

# the return page is another way the payment is noticed: the customer must be told exactly once there as well
l3 = start(D + 3, '09:00', 'late3@example.invalid', name='Tardivo Tre')
lapse(l3)
thief = book('def', day(D + 3), '09:00', email='thief3@example.invalid')
sid3 = sid_of(l3)
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid3)
st, loc, html = page(l3, 'payreturn')
tp.webhook(tp.event('checkout.session.completed', sid3))
check('L6 payment first noticed by the return page (slot taken): the page says it, one email only even when the webhook arrives after', 'no longer available' in txt(html) and len(mails_to('late3@example.invalid')) == 1, (txt(html)[:120], len(mails_to('late3@example.invalid'))))

# asynchronous payments (the money comes days later): same two outcomes
l4 = start(D + 4, '09:00', 'async1@example.invalid', name='Asincrono Uno')
sid4 = sid_of(l4)
st_, out = tp.webhook(tp.event('checkout.session.completed', sid4))
check('A1 the session completes but the money is not there yet (bank debit): nothing is confirmed', out.get('result') == 'unpaid' and tp.dbrow(l4)['status'] == 'awaiting_payment', out)
lapse(l4)
thief = book('def', day(D + 4), '09:00', email='thief4@example.invalid')
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid4)
st_, out = tp.webhook(tp.event('checkout.session.async_payment_succeeded', sid4))
cm = mails_to('async1@example.invalid')
check('A2 the money arrives days later and the slot was taken: needs review, the customer is told by email at once', out.get('result') == 'review' and tp.dbrow(l4)['status'] == 'needs_review' and len(cm) == 1 and 'no longer available' in cm[0]['subject'], (out, [m['subject'] for m in cm]))
l5 = start(D + 5, '09:00', 'async2@example.invalid', name='Asincrono Due')
sid5 = sid_of(l5)
tp.webhook(tp.event('checkout.session.completed', sid5))
lapse(l5)
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid5)
st_, out = tp.webhook(tp.event('checkout.session.async_payment_succeeded', sid5))
check('A3 the money arrives days later and the slot is free: confirmed', out.get('result') == 'confirmed' and tp.dbrow(l5)['status'] == 'confirmed' and mails_to('async2@example.invalid'), out)

# the calendar cannot be asked: say "we are checking", not "no longer available"
l6 = start(D + 6, '09:00', 'check1@example.invalid', name='Verifica Uno')
lapse(l6)
sid6 = sid_of(l6)
jx("printf freebusy > /tmp/gmock/google_fail; chmod 666 /tmp/gmock/google_fail")
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid6)
st_, out = tp.webhook(tp.event('checkout.session.completed', sid6))
jx('rm -f /tmp/gmock/google_fail')
cm = mails_to('check1@example.invalid')
check('L7 the calendar cannot be read when the late payment arrives: needs review, and the customer is told "we are checking" (not that the time is gone)', tp.dbrow(l6)['status'] == 'needs_review' and len(cm) == 1 and 'checking' in cm[0]['subject'] and 'no longer available' not in cm[0]['subject'], [m['subject'] for m in cm])

# ============================================================================ 4. the payment line is always in the emails of a paid booking
print('--- 4. payment line')
tp.fresh()


def paid_booking(cfg, day_off, email):
    r, i = tp.start_paid(cfg, day_off, '09:00', email, name='Pagante Linea')
    sid = sid_of(i)
    jx('rm -f /tmp/mail/*.eml')
    tp.pay_in_mock(sid)
    tp.webhook(tp.event('checkout.session.completed', sid))
    return i


i = paid_booking('paidcustom', D + 8, 'pc@example.invalid')
cm = mails_to('pc@example.invalid'); sm = [m for m in mails_to(STAFF) if 'Pagato' in m['html'] or 'Nuova' in m['html'] or 'Paid' in m['html']]
check('P1 customer email of a PAID booking whose custom text has no {payment_block}: the "Payment received" line is added under the text', len(cm) >= 1 and 'grazie per aver prenotato' in cm[0]['html'] and 'Payment received' in cm[0]['html'], [m['subject'] for m in cm])
check('P1b staff email with a custom text: the line "Paid: amount (Stripe pi_...)" is always there', sm and 'Paid:' in sm[0]['html'] and 'pi_mock' in sm[0]['html'], [m['subject'] for m in mails_to(STAFF)])
paid_booking('paidblock', D + 9, 'pb@example.invalid')
cm = mails_to('pb@example.invalid')
check('P2 a text that already has {payment_block}: the line appears once (the layout shows it, the placeholder adds nothing)', cm and cm[0]['html'].count('Payment received') == 1, cm and cm[0]['html'].count('Payment received'))
paid_booking('paidamount', D + 10, 'pa@example.invalid')
cm = mails_to('pa@example.invalid')
check('P3 a text that writes {amount} itself: the amount is in the text and the payment line of the layout is still there, once', cm and cm[0]['html'].count('Payment received') == 1 and 'Totale' in cm[0]['html'], cm and txt(cm[0]['html'])[:160])

# ============================================================================ 5. the page after cancelling a paid booking
print('--- 5. cancel page')
pid = paid_booking('paidcustom', D + 11, 'pcancel@example.invalid')
tok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % pid)
CANCEL = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel'
out = Sess().post(CANCEL, {'token': tok})
check('C1 the customer cancels a PAID booking outside the lock window: the page itself says "We will refund the amount you paid (29,00 ...)"', 'We will refund the amount you paid' in txt(out) and '29' in txt(out) and tp.dbrow(pid)['status'] == 'cancelled', txt(out)[:260])
r = book('def', day(D + 11), '12:00', email='freec@example.invalid')
ftok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE customer_email='freec@example.invalid'")
out = Sess().post(CANCEL, {'token': ftok})
check('C2 a free booking: cancelled page without any refund line', 'cancelled' in txt(out).lower() and 'refund' not in txt(out).lower(), txt(out)[:200])
pid2 = paid_booking('paidcustom', D + 12, 'plock@example.invalid')
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='23:59' WHERE id=%d" % (day(0), pid2))
tok2 = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % pid2)
out = Sess().post(CANCEL, {'token': tok2})
check('C3 inside the 24 hour window the customer gets the message already in place (write to us), and the booking stays', 'online cancellation and rescheduling are possible up to' in txt(out) and tp.dbrow(pid2)['status'] == 'confirmed', txt(out)[:200])

# ============================================================================ 6. the old default text saved by the builder is the same as empty
print('--- 6. old default text')
for cfgname in ('legacyc', 'legacyc2'):
    jx('rm -f /tmp/mail/*.eml')
    book(cfgname, day(D + 13), '09:00', email='%s@example.invalid' % cfgname, name='Vecchio Testo')
    cm = mails_to('%s@example.invalid' % cfgname)
    sm = [m for m in mails_to(STAFF) if re.match(r'^(New Event|Nuovo evento):', m['subject'])]
    check('O1 %s: the old Italian default saved by the builder is treated as empty: the customer gets the current translated default (not the fixed Italian text)' % cfgname, cm and 'In allegato' not in cm[0]['html'] and 'is confirmed' in cm[0]['html'], cm and txt(cm[0]['html'])[:160])
    check('O2 %s: and the staff gets the current default (not "Nuova prenotazione:" in fixed Italian)' % cfgname, sm and 'Nuova prenotazione:' not in sm[0]['html'] and ('Invitee' in sm[0]['html'] or 'Name' in sm[0]['html']), sm and txt(sm[0]['html'])[:160])
    sql("DELETE FROM joom_webgbooking_bookings WHERE customer_email='%s@example.invalid'" % cfgname)
jx('rm -f /tmp/mail/*.eml')
book('legacyedit', day(D + 14), '09:00', email='legacyedit@example.invalid', name='Testo Modificato')
cm = mails_to('legacyedit@example.invalid'); sm = [m for m in mails_to(STAFF) if 'Nuova richiesta' in m['html']]
check('O3 the same text with one word changed is a real customisation: it is kept as written', cm and 'Ti aspettiamo' in cm[0]['html'] and sm, cm and txt(cm[0]['html'])[:140])

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
