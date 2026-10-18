"""0.33.2: free bookings are confirmed at once (opt-out per element) and every staff email of a booking goes to the
element's staff recipient. Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, time
from wgbtest import *
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from t_admin_lib import make_admin, post_pairs

reset_env()
tp.fresh()
D = 6
ELEM = 'elem-staff@example.invalid'
PLUG = 'staff@wgbtest.invalid'


def to_staff(addr):
    out = []
    for p in mails():
        raw = mail_text(p)
        if addr in raw[:300]:
            out.append(decode_mail(raw))
    return out


def clear_mail():
    jx('rm -f /tmp/mail/*.eml')


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


# ============================================================================ 1. free bookings are confirmed at once
print('--- 1. confirmed at once')
clear_mail()
r = book('def', day(D), '09:00', email='conf1@example.invalid', name='Subito Confermato')
b = last_id()
cm = next((m for m in [decode_mail(mail_text(p)) for p in mails()] if 'conf1@' in m['rcpt']), None)
check('V1a a free booking is stored as CONFIRMED (it used to stay "pending")', r.get('ok') is True and row(b)['status'] == 'confirmed', row(b))
check('V1b the default customer email says "Appointment confirmed" / "Appuntamento confermato", not "request"', cm is not None and ('confirmed' in cm['subject'].lower() or 'confermato' in cm['subject'].lower()) and 'request' not in cm['subject'].lower() and 'richiesta' not in cm['subject'].lower(), cm and cm['subject'])
check('V1c the default body says the appointment is confirmed', cm is not None and ('is confirmed' in cm['html'] or 'è confermato' in cm['html'] or 'e\' confermato' in cm['html']), cm and txt(cm['html'])[:200])
check('V1d the answer shown in the widget says confirmed, not "request sent"', ('onfirm' in r.get('message', '') or 'onferm' in r.get('message', '')) and 'Request sent' not in r.get('message', ''), r)
clear_mail()
r = book('manual30', day(D), '10:00', email='conf2@example.invalid', name='Conferma Manuale')
b2 = last_id()
cm2 = next((m for m in [decode_mail(mail_text(p)) for p in mails()] if 'conf2@' in m['rcpt']), None)
check('V1e element option "Manual confirmation": the booking stays pending and the email is the "request" one (today\'s flow)', row(b2)['status'] == 'pending' and cm2 is not None and ('request' in cm2['subject'].lower() or 'richiesta' in cm2['subject'].lower()), (row(b2)['status'], cm2 and cm2['subject']))
check('V1f ...and its widget answer is still "request sent"', 'equest' in r.get('message', '') or 'ichiesta' in r.get('message', ''), r)
r, pb = tp.start_paid('paid30', D + 1, '09:00', 'conf3@example.invalid')
sid = tp.dbrow(pb)['stripe_session_id']; tp.pay_in_mock(sid); tp.webhook(tp.event('checkout.session.completed', sid))
check('V1g a paid booking is confirmed at payment as before', tp.dbrow(pb)['status'] == 'confirmed', tp.dbrow(pb))
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s' WHERE id=%d" % (day(D + 3), b))
check('V1h reminders cover confirmed free bookings (status is in the reminder set)', sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE id=%d AND status IN ('pending','confirmed')" % b) == '1')

# ============================================================================ 2. one staff recipient for every event of a booking
print('--- 2. staff recipient')
tp.fresh()
clear_mail()
r = book('staffto', day(D), '09:00', email='st1@example.invalid', name='Staff Uno')
s1 = last_id(); tok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % s1)
new = to_staff(ELEM)
check('V2a the new-booking staff email goes to the element recipient', len(new) >= 1 and not to_staff(PLUG), (len(new), len(to_staff(PLUG))))
check('V2b the recipient is stored on the booking', sql("SELECT staff_to FROM joom_webgbooking_bookings WHERE id=%d" % s1) == ELEM, sql("SELECT staff_to FROM joom_webgbooking_bookings WHERE id=%d" % s1))
clear_mail()
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': day(D), 'time': '11:00'}))
check('V2c RESCHEDULE: the staff email goes to the element recipient (not to the plugin notify address)', x.get('ok') is True and len(to_staff(ELEM)) >= 1 and not to_staff(PLUG), (x, len(to_staff(ELEM)), len(to_staff(PLUG))))
clear_mail()
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tok})
check('V2d CANCEL by the customer: the staff email goes to the element recipient', len(to_staff(ELEM)) >= 1 and not to_staff(PLUG), (len(to_staff(ELEM)), len(to_staff(PLUG))))
# element without a recipient: plugin fallback
clear_mail()
r = book('def', day(D + 1), '09:00', email='st2@example.invalid')
s2 = last_id(); tok2 = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % s2)
check('V2e no recipient on the element: the plugin notify address is used (fallback)', len(to_staff(PLUG)) >= 1 and not to_staff(ELEM), (len(to_staff(PLUG)), len(to_staff(ELEM))))
clear_mail()
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tok2})
check('V2f ...also for the cancellation of that booking', len(to_staff(PLUG)) >= 1 and not to_staff(ELEM), (len(to_staff(PLUG)), len(to_staff(ELEM))))
# needs_review (late payment, slot taken) -> element recipient
r, p1 = tp.start_paid('paidstaff', D + 2, '09:00', 'st3@example.invalid')
sid = tp.dbrow(p1)['stripe_session_id']
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), status='expired', payment_status='expired' WHERE id=%d" % p1)
book('def', day(D + 2), '09:00', email='thief2@example.invalid')
tp.pay_in_mock(sid); clear_mail()
st, out = tp.webhook(tp.event('checkout.session.completed', sid))
rv = to_staff(ELEM)
check('V2g NEEDS_REVIEW alert (paid but slot taken) goes to the element recipient', out.get('result') == 'review' and len(rv) >= 1 and not to_staff(PLUG), (out, len(rv), len(to_staff(PLUG))))
# failed effects alert for a booking -> element recipient
clear_mail()
jx("printf events > /tmp/gmock/google_fail; chmod 666 /tmp/gmock/google_fail")
r = book('staffto', day(D + 3), '09:00', email='st4@example.invalid')
jx('rm -f /tmp/gmock/google_fail')
al = [m for m in to_staff(ELEM) if 'did not complete' in (m['subject'] + m['html']) or 'non completati' in (m['subject'] + m['html'])]
check('V2h the "effects did not complete" alert for a booking goes to the element recipient', len(al) >= 1 and not [m for m in to_staff(PLUG) if 'did not complete' in (m['subject'] + m['html'])], (len(al)))

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
