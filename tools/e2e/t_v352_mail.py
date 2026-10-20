"""0.35.2: time zones on the server side, emails like Calendly's, the manage-page header, the staff trace of what the admin does.
Time zones: the page sends the date and time in the SITE zone, the exact instant (ts) and the visitor's zone (tz); the server keeps the zone on the booking,
refuses a time that does not match its instant, ignores a zone PHP does not know, and writes the customer's emails, reminders and .ics description in THEIR zone
(naming it), while the staff reads the site's time and a line with the customer's zone. Each check fails on 0.35.1. Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, html, http.client, urllib.parse, datetime as dt, zoneinfo
from wgbtest import *
from t_admin_lib import *

TBL = 'joom_webgbooking_eventtypes'
ROME = zoneinfo.ZoneInfo('Europe/Rome')


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


ET = dict(description='', state=1, listed=1, duration='', buffer_before='', buffer_after='', location='meet', avail_start='', avail_end='', avail_days='',
          avail_notice='', avail_window='', price_amount='', price_currency='', price_label='', custom_fields='', staff_to='', redirect_url='',
          confirm_manual=0, allow_guest=1, show_newsletter=0, ordering=0)


def ins(slug, title, **kw):
    f = dict(ET)
    f.update(kw)
    f.update(slug=slug, title=title)
    sql('INSERT INTO %s (%s,created,modified) VALUES (%s,NOW(),NOW())' % (TBL, ','.join(f), ','.join(q(v) for v in f.values())))


def direct_cfg(slug):
    u = urllib.parse.urlparse(BASE + '/prenota/' + slug)
    c = http.client.HTTPConnection(u.hostname, u.port, timeout=60)
    c.request('GET', u.path)
    b = c.getresponse().read().decode('utf-8', 'replace')
    return json.loads(html.unescape(re.search(r'data-wgb="([^"]+)"', b).group(1)))


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h or ''))


def site_instant(d, t):
    return dt.datetime.fromisoformat('%s %s:00' % (d, t)).replace(tzinfo=ROME)


def in_zone(d, t, tz):
    x = site_instant(d, t).astimezone(zoneinfo.ZoneInfo(tz))
    return x.strftime('%Y-%m-%d'), x.strftime('%H:%M'), x.strftime('%Z')


reset_env(render=False)
sql('DELETE FROM %s' % TBL)
jx('php /tmp/mock/init.php >/dev/null')
ins('tz-prova', 'Riunione di prova', duration='30', location='meet', custom_fields='Argomento della riunione | text |')
cfg = direct_cfg('tz-prova')
check('Z0 the page tells the script the site zone (siteTz) and the label of the zone menu', cfg.get('siteTz') == 'Europe/Rome' and cfg['labels'].get('tzLabel'), {k: cfg.get(k) for k in ('siteTz',)})

check('Z0b the upgrade created the column that keeps the customer zone', sql("SHOW COLUMNS FROM joom_webgbooking_bookings LIKE 'customer_tz'").startswith('customer_tz'), '')
D = day(5)
NY = 'America/New_York'
ts = int(site_instant(D, '10:00').timestamp())
jx('rm -f /tmp/mail/*.eml')
r = book(None, D, '10:00', name='Mario Rossi', email='ny352@example.invalid', phone='+1 212 555 0100', notes='Voglio capire', cfg=cfg,
         extra={'ts': str(ts), 'tz': NY, 'custom[cf0]': 'Il mio sito'})
bid = last_id()
check('Z1 a booking that carries the exact instant and the visitor zone is accepted, the zone is stored', r.get('ok') is True and sql('SELECT customer_tz FROM joom_webgbooking_bookings WHERE id=%d' % bid) == NY, r)
d_ny, t_ny, ab = in_zone(D, '10:00', NY)
cm = mails_to('ny352@example.invalid')
cm = cm[0] if cm else None
check('Z2 the customer email says the time in THEIR zone, naming it (e.g. "%s (%s, %s)")' % (t_ny, ab, NY), cm is not None and ('%s (%s, %s)' % (t_ny, ab, NY)) in txt(cm['html']), cm and txt(cm['html'])[:700])
check('Z2b ... not the site time with the site zone as the appointment time', cm is not None and ('10:00 (CEST, Europe/Rome)' not in txt(cm['html']) and '10:00 (CET, Europe/Rome)' not in txt(cm['html'])) or t_ny == '10:00', cm and txt(cm['html'])[:300])
dd = dt.date.fromisoformat(d_ny)
check('Z3 default customer subject: "Confirmed|Confermato: <event> with|con <host> ... at|alle <time in their zone>"', cm is not None and re.match(r'^(Confirmed|Confermato): Riunione di prova (with|con) .+ (on|il) .*%s$' % re.escape(('at ' if cm['subject'].startswith('Confirmed') else 'alle ') + t_ny), cm['subject']) is not None, cm and cm['subject'])
check('Z3b ... and the day in the subject is the day THEIR calendar shows (%s)' % d_ny, cm is not None and dd.strftime('%d/%m/%Y') in cm['subject'], cm and cm['subject'])
check('Z4 the .ics keeps the true instant in UTC and says the time in their zone in the description', cm is not None and ('DTSTART:' + site_instant(D, '10:00').astimezone(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')) in cm['ics'].replace('\r', '') and ('%s (%s, %s)' % (t_ny, ab, NY)).replace(',', '\\,') in cm['ics'].replace('\r', '').replace('\n ', ''), cm and cm['ics'][:600])
check('Z5 the customer email has the questions with their answers, the change question and the two links (reschedule, cancel)', cm is not None and 'Il mio sito' in cm['html'] and re.search(r'(Need to make changes\?|Devi cambiare qualcosa\?)', cm['html']) and '#reschedule' in cm['html'] and '#cancel' in cm['html'], cm and txt(cm['html'])[:900])
check('Z5b ... "Hi/Ciao <name>, your appointment with <host> is confirmed" and the invitee asked questions (phone and notes included)', cm is not None and re.search(r'(your appointment with .+ is confirmed|il tuo appuntamento con .+ è confermato)', txt(cm['html'])) and '+1 212 555 0100' in cm['html'] and 'Voglio capire' in cm['html'], cm and txt(cm['html'])[:500])
sm = [m for m in mails_to('staff@wgbtest.invalid') if m['subject']]
sm = sm[0] if sm else None
mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic']
sd = dt.date.fromisoformat(D)
check('Z6 the staff subject is "New Event: <name> - 10:00 <dow>, <d> <mon> <year> - <event>" in the SITE time', sm is not None and re.match(r'^(New Event|Nuovo evento): Mario Rossi - 10:00 \w+, %d \w+ %d - Riunione di prova$' % (sd.day, sd.year), sm['subject']) is not None, sm and sm['subject'])
check('Z6b ... the body has the invitee email as a link, the place as a Meet line, the zone of the customer with their time, the questions in bold under their text', sm is not None and 'mailto:ny352@example.invalid' in sm['html'] and (NY in txt(sm['html'])) and ('%s (%s)' % (t_ny, ab)) in txt(sm['html']) and 'Il mio sito' in sm['html'] and 'Meet' in sm['html'], sm and txt(sm['html'])[:900])
check('Z6c ... and a button that leads to the booking in the panel', sm is not None and 'view=booking&id=%d' % bid in sm['html'].replace('&amp;', '&'), sm and re.findall(r'href="([^"]+)"', sm['html'])[:4])
check('Z6d ... the order of the Calendly notice: event type, invitee, invitee email, date and time, place', sm is not None and (lambda t: [t.find(x) for x in ('Event type', 'Invitee', 'Invitee email', 'Date and time', 'Location')] == sorted(t.find(x) for x in ('Event type', 'Invitee', 'Invitee email', 'Date and time', 'Location')) and min(t.find(x) for x in ('Event type', 'Invitee', 'Invitee email', 'Date and time', 'Location')) > 0)(txt(sm['html']).replace('Invitee email', 'Invitee email')) or (sm is not None and re.search(r'Tipo di evento.*Invitato.*Email dell.invitato.*Data e ora', txt(sm['html'])) is not None), sm and txt(sm['html'])[:500])

# the instant must match the date and time the server will book
n0 = int(sql('SELECT COUNT(*) FROM joom_webgbooking_bookings'))
r = book(None, D, '10:30', email='bad352@example.invalid', cfg=cfg, extra={'ts': str(ts), 'tz': NY})
check('Z7 a time that is not the instant the page shows (ts off by 30 minutes) is refused, nothing is booked', r.get('ok') is False and int(sql('SELECT COUNT(*) FROM joom_webgbooking_bookings')) == n0, r)
r = book(None, D, '11:00', email='noz352@example.invalid', cfg=cfg, extra={'tz': 'Not/AZone'})
check('Z8 a zone PHP does not know is ignored (booked in the site zone, no zone stored)', r.get('ok') is True and sql("SELECT IFNULL(customer_tz,'NULL') FROM joom_webgbooking_bookings WHERE customer_email='noz352@example.invalid'") == 'NULL', r)
r = book(None, D, '11:30', email='inj352@example.invalid', cfg=cfg, extra={'tz': "America/New_York'; DROP TABLE x;--"})
check('Z8b a zone with odd characters is ignored too', r.get('ok') is True and sql("SELECT IFNULL(customer_tz,'NULL') FROM joom_webgbooking_bookings WHERE customer_email='inj352@example.invalid'") == 'NULL', r)
r = book(None, D, '12:00', email='old352@example.invalid', cfg=cfg)
cm0 = mails_to('old352@example.invalid')
check('Z9 a booking without zone (an old page in cache) keeps the site time and zone in its email', r.get('ok') is True and cm0 and '12:00 (' in txt(cm0[0]['html']) and 'Europe/Rome' in txt(cm0[0]['html']), cm0 and txt(cm0[0]['html'])[:300])

# ---- reschedule: the page header follows at once, the zone is updated
tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % bid)
murl = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + tok
pg = Sess().get(murl)
check('B1 the manage page shows date and time in the customer\'s zone (and has ids the page can update)', ('%s (%s, %s)' % (t_ny, ab, NY)) in html.unescape(pg) and 'id="wgb-m-date"' in pg and 'id="wgb-m-time"' in pg and 'id="reschedule"' in pg and 'id="cancel"' in pg, re.findall(r'wgb-m-time">([^<]*)', pg))
D2 = day(6)
ts2 = int(site_instant(D2, '14:00').timestamp())
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': D2, 'time': '14:00', 'ts': str(ts2), 'tz': 'Asia/Tokyo'}))
d_t, t_t, ab_t = in_zone(D2, '14:00', 'Asia/Tokyo')
check('B2 the reschedule answer carries the new date and time as the customer sees them (the header showed the old ones until a reload)', x.get('ok') is True and x.get('date') == dt.date.fromisoformat(d_t).strftime('%d/%m/%Y') and x.get('time', '').startswith(t_t) and 'Asia/Tokyo' in x.get('time', ''), x)
check('B3 the zone of the booking follows the new choice', sql('SELECT customer_tz FROM joom_webgbooking_bookings WHERE id=%d' % bid) == 'Asia/Tokyo')
js = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'src', 'plg_system_webgbooking', 'yootheme', 'elements', 'booking', 'assets', 'wgb.js'), encoding='utf-8').read()
check('B4 the script writes that date and time into the page header on success', "getElementById('wgb-m-date')" in js and "getElementById('wgb-m-time')" in js and 'res.date' in js)
mv = [m for m in mails_to('ny352@example.invalid') if m['subject'].lower().startswith(('rescheduled', 'spostato'))]
check('B5 the customer email for the move: "Rescheduled|Spostato: ..." with the time in the new zone', mv and ('%s (%s, Asia/Tokyo)' % (t_t, ab_t)) in txt(mv[-1]['html']), [m['subject'] for m in mails_to('ny352@example.invalid')])
ms = [m for m in mails_to('staff@wgbtest.invalid') if re.match(r'^(Event moved|Evento spostato)', m['subject'])]
check('B6 the staff email for the move: "Event moved|Evento spostato: ..." in the site time', ms and re.search(r'14:00 \w+, %d' % dt.date.fromisoformat(D2).day, ms[-1]['subject']), [m['subject'] for m in mails_to('staff@wgbtest.invalid')][-3:])

# ---- reminder in the customer's zone
def rome_now(plus):
    return (dt.datetime.now(ROME) + dt.timedelta(minutes=plus))


w = rome_now(50)
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[]' WHERE id=%d" % (w.strftime('%Y-%m-%d'), w.strftime('%H:%M'), bid))
jx('rm -f /tmp/mail/*.eml')
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 2>&1 | tail -2')
rm_ = [m for m in mails_to('ny352@example.invalid')]
d_r, t_r, ab_r = in_zone(w.strftime('%Y-%m-%d'), w.strftime('%H:%M'), 'Asia/Tokyo')
check('R1 the reminder gives the time in the customer\'s zone, naming it', rm_ and ('%s (%s, Asia/Tokyo)' % (t_r, ab_r)) in txt(rm_[0]['html']), rm_ and txt(rm_[0]['html'])[:500])
check('R1b ... and the subject too (the time of the reminder subject is theirs)', rm_ and t_r in rm_[0]['subject'], rm_ and rm_[0]['subject'])

# ---- what the admin does leaves a trace in the staff mailbox
adm, edit_url, save_options, opts = make_admin()
ins('tz-tel', 'Telefonata di prova', duration='30', location='phone')
cfg2 = direct_cfg('tz-tel')
r = book(None, day(7), '10:00', email='adm352@example.invalid', phone='+39 333 000 7777', cfg=cfg2, name='Per Admin')
aid = last_id()
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % aid)
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', det)[0]
jx('rm -f /tmp/mail/*.eml')
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.cancel', [('cid[]', str(aid)), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tk, '1')])
st_mails = mails_to('staff@wgbtest.invalid')
cu_mails = mails_to('adm352@example.invalid')
check('S0 cancelled from the panel: the booking is cancelled and the customer is told', sql('SELECT status FROM joom_webgbooking_bookings WHERE id=%d' % aid) == 'cancelled' and cu_mails, sql('SELECT status FROM joom_webgbooking_bookings WHERE id=%d' % aid))
check('S1 ... and the staff gets a notice too, saying it was done from the panel (a trace in the mailbox)', st_mails and re.search(r'(Canceled by you|Annullato da te)', st_mails[0]['subject']) and re.search(r'(You canceled|Hai annullato)', txt(st_mails[0]['html'])), [m['subject'] for m in st_mails])
check('S2 the customer\'s cancellation notice has the new default subject ("Canceled|Annullato: <event> with|con <host> ...")', cu_mails and re.match(r'^(Canceled|Annullato): Telefonata di prova (with|con) ', cu_mails[0]['subject']) is not None, [m['subject'] for m in cu_mails])
# a customer cancelling by themselves still tells the staff
r = book(None, day(7), '11:00', email='self352@example.invalid', phone='+39 333 000 8888', cfg=cfg2, name='Da Solo')
sid = last_id()
tk2 = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % sid)
jx('rm -f /tmp/mail/*.eml')
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tk2})
st2 = mails_to('staff@wgbtest.invalid')
check('S3 a cancellation by the customer: the staff subject is "Canceled|Evento annullato: ..." (not "by you")', st2 and re.match(r'^(Canceled|Evento annullato): Da Solo - ', st2[0]['subject']) and not re.search(r'by you|da te', st2[0]['subject']), [m['subject'] for m in st2])
# confirm and tell the customer, from the panel (manual confirmation)
ins('tz-man', 'Su conferma', duration='30', location='meet', confirm_manual=1)
cfg3 = direct_cfg('tz-man')
r = book(None, day(8), '10:00', email='man352@example.invalid', cfg=cfg3, name='Su Conferma')
mid = last_id()
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % mid)
tk3 = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', det)[0]
jx('rm -f /tmp/mail/*.eml')
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(mid)), ('option', 'com_webgbooking'), (tk3, '1')])
st3 = mails_to('staff@wgbtest.invalid')
check('S4 "Confirm and tell the customer" from the panel: the customer is told and the staff gets a trace', mails_to('man352@example.invalid') and st3 and re.search(r'(Confirmed by you|Confermato da te)', st3[0]['subject']), [m['subject'] for m in st3])
reset_env()

print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
