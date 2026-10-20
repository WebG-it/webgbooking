"""0.35.2 on a PAID saved event type (Stripe mock, test mode): the customer's zone in the payment flow.
A paid type with questions, booked from the direct page by a customer who sees New York time: the Stripe page text, the email after the payment, the
staff notice, the Google event, and the late payment when the slot was taken meanwhile (the customer is told by email in THEIR zone, the staff alert stays in the
site's time). Needs WGB_E2E_ADMIN_PASS (random throwaway Stripe keys)."""
import sys, os, re, json, html, datetime as dt, zoneinfo, urllib.parse
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC
from wgbtest import *

ROME = zoneinfo.ZoneInfo('Europe/Rome')
NY = 'America/New_York'
TBL = 'joom_webgbooking_eventtypes'
STAFF = 'staff@wgbtest.invalid'


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


ET = dict(description='', state=1, listed=0, duration='30', buffer_before='', buffer_after='', location='meet', avail_start='', avail_end='', avail_days='',
          avail_notice='', avail_window='', price_amount='', price_currency='', price_label='', custom_fields='', staff_to='', redirect_url='',
          confirm_manual=0, allow_guest=1, show_newsletter=0, ordering=0)


def ins(slug, title, **kw):
    f = dict(ET)
    f.update(kw)
    f.update(slug=slug, title=title)
    sql('INSERT INTO %s (%s,created,modified) VALUES (%s,NOW(),NOW())' % (TBL, ','.join(f), ','.join(q(v) for v in f.values())))


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h or ''))


def instant(d, t):
    return dt.datetime.fromisoformat('%s %s:00' % (d, t)).replace(tzinfo=ROME)


def in_ny(d, t):
    x = instant(d, t).astimezone(zoneinfo.ZoneInfo(NY))
    return x.strftime('%d/%m/%Y'), x.strftime('%H:%M'), x.strftime('%Z')


tp.fresh()
sql('DELETE FROM %s' % TBL)
ins('audit-pagato', 'Audit pagato', price_amount='29', price_currency='EUR', price_label='Audit di prova',
    custom_fields='Indirizzo del sito | text | required\nCosa ti preoccupa? | textarea |\nHai Search Console? | select | | si, no\nTelefono alternativo | tel |', staff_to='staff@wgbtest.invalid')
ins('gratis-uguale', 'Libero', duration='30')


def cfg_of(slug):
    st, hd, b = http_get('/prenota/' + slug)
    return json.loads(html.unescape(re.search(r'data-wgb="([^"]+)"', b).group(1)))


cfg = cfg_of('audit-pagato')
D = day(6)
ts = int(instant(D, '10:00').timestamp())
d_ny, t_ny, ab = in_ny(D, '10:00')
jx('rm -f /tmp/mail/*.eml; rm -f /tmp/gmock/log.jsonl')
ans = {'custom[cf0]': 'https://sito-cliente.example.invalid', 'custom[cf1]': 'Poco traffico', 'custom[cf2]': 'si', 'custom[cf3]': '+1 212 555 0101'}
r = book(None, D, '10:00', email='payny@example.invalid', name='Pay New York', phone='+1 212 555 0100', notes='Prima volta', cfg=cfg, extra=dict(ans, tz=NY, ts=str(ts)))
pid = last_id()
rw = tp.dbrow(pid)
check('P1 paid booking with the visitor zone: redirected to Stripe, slot held, the zone stored', r.get('ok') is True and r.get('redirect', '').startswith('https://checkout.stripe.test/') and rw['status'] == 'awaiting_payment' and sql('SELECT customer_tz FROM joom_webgbooking_bookings WHERE id=%d' % pid) == NY, (r, rw.get('status')))
cs = tp.stripe_calls('POST', '/v1/checkout/sessions')
body = urllib.parse.unquote_plus(cs[-1]['body'] if cs and isinstance(cs[-1]['body'], str) else '')
check('P2 the Stripe page text names the appointment in the customer\'s zone, not in the site\'s', cs and ('%s %s' % (d_ny, t_ny)) in body and NY in body, body[:600])
check('P3 before the payment: no email, no Google event', not mails() and not [l for l in gm_log() if l['path'].startswith('/calendar/v3/calendars') and l['method'] == 'POST'])
sid = rw['stripe_session_id']
tp.pay_in_mock(sid)
st_, out = tp.webhook(tp.event('checkout.session.completed', sid))
rw = tp.dbrow(pid)
check('P4 payment completed (webhook): confirmed and paid', st_ == 200 and rw['status'] == 'confirmed' and rw['payment_status'] == 'paid' and rw['amount_cents'] == '2900', (out, rw.get('status')))
cm = mails_to('payny@example.invalid')
cm = cm[0] if cm else None
check('P5 the confirmation email after the payment: the time in New York (%s %s, %s) and the payment line' % (d_ny, t_ny, ab), cm is not None and ('%s (%s, %s)' % (t_ny, ab, NY)) in txt(cm['html']) and re.search(r'(Payment received|Pagamento ricevuto|29[.,]00)', txt(cm['html'])) is not None, cm and txt(cm['html'])[:700])
check('P5b ... subject "Confirmed|Confermato: <event> with|con <host> ... at|alle <time in their zone>"', cm is not None and re.match(r'^(Confirmed|Confermato): Audit pagato (with|con) ', cm['subject']) and t_ny in cm['subject'], cm and cm['subject'])
check('P5c ... their answers (the four questions, phone included) as plain text, the change links', cm is not None and 'sito-cliente.example.invalid' in cm['html'] and 'href="https://sito-cliente.example.invalid' not in cm['html'] and 'Poco traffico' in cm['html'] and '#reschedule' in cm['html'] and '+1 212 555 0101' in cm['html'], cm and txt(cm['html'])[:500])
sm = [m for m in mails_to(STAFF) if re.match(r'^(New Event|Nuovo evento):', m['subject'])]
sm = sm[0] if sm else None
dd = dt.date.fromisoformat(D)
check('P6 the staff notice: subject in the SITE time, the line with the customer zone and their time, the payment line, the questions', sm is not None and re.search(r'10:00 \w+, %d' % dd.day, sm['subject']) and NY in txt(sm['html']) and t_ny in txt(sm['html']) and re.search(r'(29[.,]00|Paid|Pagato)', txt(sm['html'])) and 'Poco traffico' in sm['html'], sm and txt(sm['html'])[:700])
posts = [l for l in gm_log() if l['method'] == 'POST' and '/events' in l['path']]
check('P7 the Google event is created only after the payment, confirmed and busy, with the answers in the description', len(posts) == 1 and posts[0]['body'].get('transparency') == 'opaque' and 'Poco traffico' in posts[0]['body'].get('description', ''), posts and posts[0]['body'].get('description'))

# ---- a late payment: the hold lapsed, somebody else took the slot, then the money arrives
D2 = day(7)
ts2 = int(instant(D2, '11:00').timestamp())
d2, t2, ab2 = in_ny(D2, '11:00')
r = book(None, D2, '11:00', email='late352@example.invalid', name='Late NY', phone='+1 212 555 0102', cfg=cfg, extra=dict(ans, tz=NY, ts=str(ts2)))
lid = last_id()
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % lid)
thief = book(None, D2, '11:00', email='thief352@example.invalid', name='Ladro', cfg=cfg_of('gratis-uguale'))
sid2 = tp.dbrow(lid)['stripe_session_id']
jx('rm -f /tmp/mail/*.eml')
tp.pay_in_mock(sid2)
st_, out = tp.webhook(tp.event('checkout.session.completed', sid2))
row = tp.dbrow(lid)
check('L1 payment after the hold with the slot taken: needs review, money recorded', thief.get('ok') is True and out.get('result') == 'review' and row['status'] == 'needs_review' and row['payment_status'] == 'paid', (out, row.get('status')))
lm = mails_to('late352@example.invalid')
lm = lm[0] if lm else None
check('L2 the customer is told at once, and the time in the email is THEIR time (%s, %s)' % (t2, ab2), lm is not None and ('%s (%s, %s)' % (t2, ab2, NY)) in txt(lm['html']) or (lm is not None and t2 in txt(lm['html']) and NY in txt(lm['html'])), lm and txt(lm['html'])[:700])
alert = [m for m in mails_to(STAFF) if re.search(r'(review|verific|rimbors|refund)', m['subject'], re.I) or 'Late NY' in m['subject']]
check('L3 the staff alert goes out and states the SITE time (11:00)', alert and '11:00' in (alert[0]['subject'] + txt(alert[0]['html'])), [m['subject'] for m in mails_to(STAFF)])
# ---- the customer who left and never paid: the slot is released and the page says so
D3 = day(8)
r = book(None, D3, '12:00', email='gone352@example.invalid', name='Gone', cfg=cfg, extra=dict(ans, tz=NY, ts=str(int(instant(D3, '12:00').timestamp()))))
gid = last_id()
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % gid)
tk = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % gid)
stt, loc, pg = tp.noredirect_get(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=paycancel&token=' + tk)
check('E1 payment never made, hold lapsed: the cancel page opens, says the payment was not completed and offers to choose again (noindex)', stt == 200 and 'noindex' in pg and re.search(r'(not completed|did not complete|run out|scaduto|non completato|expired)', txt(pg), re.I) is not None, txt(pg)[:300])
reset_env()
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
