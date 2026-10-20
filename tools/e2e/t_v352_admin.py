"""0.35.2: the admin booking detail page, redone like a Calendly event card. Rows are seeded straight into the table so every
case is exact: header (name, status badge, long date, duration, event), the big Meet / Call button, the customer card, answers and notes,
the history as a dated timeline (reminders as dates, never minutes), the closed "Technical details" box, hostile text escaped,
a bare booking with no empty rows, rows without customer_tz, and the toolbar actions / ACL as before. Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, base64, datetime, zoneinfo, html
from t_admin_lib import *

reset_env(render=False)
ROME = zoneinfo.ZoneInfo('Europe/Rome')
UTC = datetime.timezone.utc
TBL = 'joom_webgbooking_bookings'
adm = admin_login()
URL = BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d'


def q(v):
    return 'NULL' if v is None else "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


def seed(**kw):
    f = dict(created=(datetime.datetime.now(UTC) - datetime.timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S'), booking_date=day(3), booking_time='09:30',
             customer_name='Maria Verdi', customer_email='maria@example.invalid', status='confirmed', manage_token='tok' + os.urandom(14).hex(), seq=0)
    f.update(kw)
    sql('INSERT INTO %s (%s) VALUES (%s)' % (TBL, ','.join(f), ','.join(q(v) for v in f.values())))
    return last_id()


def page(i, sess=None):
    return (sess or adm).get(URL % i)


def text(h):
    h = re.sub(r'<(script|style)\b.*?</\1>', ' ', h, flags=re.S)
    return re.sub(r'\s+', ' ', html.unescape(re.sub('<[^>]+>', ' ', h))).replace(' ,', ',').strip()


def body(h):
    """The card area before the technical box, and the technical box itself."""
    a = h.find('class="wgb-bk"')
    b = h.find('<details class="wgb-bk-card wgb-bk-tech"')
    return (h[a:b], h[b:]) if a >= 0 and b > a else (h, '')


def long_date(d):
    dt = datetime.datetime.strptime(d, '%Y-%m-%d')
    return '%s %d %s %d' % (dt.strftime('%A'), dt.day, dt.strftime('%B'), dt.year)


def short(dtobj):
    return '%d %s %d, %s' % (dtobj.day, dtobj.strftime('%b'), dtobj.year, dtobj.strftime('%H:%M'))


def tasks(h):
    return re.findall(r'<joomla-toolbar-button[^>]* task="([^"]+)"', h)


D = day(3)
created = datetime.datetime.now(UTC).replace(microsecond=0) - datetime.timedelta(days=1)
fxlast = created + datetime.timedelta(minutes=1)
S = lambda dt: dt.strftime('%Y-%m-%d %H:%M:%S')
TOKEN = 'tokfull' + os.urandom(8).hex()
start = datetime.datetime.strptime(D + ' 09:30', '%Y-%m-%d %H:%M').replace(tzinfo=ROME)

# ---------------------------------------------------------------- 1. a full Meet booking
cd = json.dumps([{'l': 'Argomento della riunione', 'v': 'Il sito non porta contatti'}, {'l': 'Quanti siete?', 'v': '2-5'}])
b1 = seed(created=S(created), customer_name='Maria Verdi', customer_phone='+39 333 123 4567', guest_email='socio@example.invalid', notes='Voglio capire se serve la SEO.\nGrazie.',
          custom_data=cd, event_name='Riunione di prova', duration_min=60, location='meet', meeting_url='https://meet.google.com/abc-defg-hij', google_event_id='gev_abc123',
          fx_state='done', fx_steps=json.dumps({'google': True, 'mail_customer': True, 'mail_staff': True, 'webhook': True, 'sheet': True}), fx_tries=1, fx_last=S(fxlast),
          reminder_cfg=json.dumps({'on': 1, 'times': [1440, 60]}), reminders_sent='[]', source_url='https://example.invalid/prenota/riunione', manage_token=TOKEN)
h = page(b1)
main, tech = body(h)
t = text(main)
check('F0 the page renders, no PHP error', 'wgb-bk-hero' in h and 'Fatal error' not in h and 'Warning:' not in h and 'Notice:' not in h and 'Deprecated:' not in h, text(h)[-300:])
check('F1 header: the customer name is the title (h2) with the status as a text badge', re.search(r'<h2 class="wgb-bk-name">\s*<span class="wgb-bk-name-text">Maria Verdi</span>\s*<span class="badge text-bg-success[^"]*">Confirmed</span>', main) is not None, main[:400])
check('F2 the date is written in full, with the time range, duration and event type', long_date(D) + ', 09:30-10:30' in t and '60 min' in t and 'Riunione di prova' in t, t[:300])
check('F3 the raw date (2026-..) is not shown as text anywhere outside attributes', not re.search(r'20\d\d-\d\d-\d\d', t) and not re.search(r'20\d\d-\d\d-\d\d', text(tech)), re.findall(r'20\d\d-\d\d-\d\d', t))
mb = re.search(r'<a class="btn btn-primary btn-lg wgb-bk-btn" href="([^"]+)"([^>]*)>', main)
check('F4 the big button: "Join with Google Meet" opens the right link in a new tab, rel noopener', mb is not None and mb.group(1) == 'https://meet.google.com/abc-defg-hij' and 'target="_blank"' in mb.group(2) and 'noopener' in mb.group(2) and 'Join with Google Meet' in t, mb and mb.group(0))
check('F5 customer card: email, phone and guest are links', 'href="mailto:maria@example.invalid"' in main and 'href="tel:+393331234567"' in main and 'href="mailto:socio@example.invalid"' in main, '')
check('F5b no customer time zone row when the booking has none', "Customer's time zone" not in t)
flat = re.sub(r'\s+', ' ', main)
check('F6 answers: each question in bold (dt) with the answer under it (dd), then the notes with the line break', '<dt>Argomento della riunione</dt> <dd>Il sito non porta contatti</dd>' in flat and '<dt>Quanti siete?</dt> <dd>2-5</dd>' in flat and 'Voglio capire se serve la SEO.<br />' in main.replace('\n', '') and '<dt>Notes</dt>' in main, flat[flat.find('wgb-bk-answers'):][:500])
tl = main[main.find('<ol class="wgb-bk-timeline">'):]
labels = ['Booking created', 'Event created on Google Calendar', 'Confirmation email sent to the customer', 'Notification email sent to the staff', 'Reminder scheduled, 1 day before', 'Reminder scheduled, 1 hour before']
pos = [tl.find(x) for x in labels]
check('F7 history lists the real events in time order: created, Google event, customer mail, staff mail, then the reminders still to come', all(p >= 0 for p in pos) and pos == sorted(pos), pos)
check('F8 reminders are real dates and hours (appointment minus the offset), flagged "Upcoming"', short(start - datetime.timedelta(days=1)) in tl and short(start - datetime.timedelta(hours=1)) in tl and tl.count('Upcoming') == 2, text(tl)[-700:])
check('F9 created and effect times are shown in the site time zone (Rome)', short(created.astimezone(ROME)) in tl and short(fxlast.astimezone(ROME)) in tl, short(created.astimezone(ROME)))
check('F10 no raw numbers or step names outside the technical box', '1440' not in main and 'mail_customer' not in main and 'google, ' not in main and 'minutes' not in main, '')
check('F11 the technical box exists, is CLOSED by default (no open attribute) and holds effects, tries, source page, ids and the manage link', '<details class="wgb-bk-card wgb-bk-tech">' in h and 'Booking number' in tech and '#%d' % b1 in text(tech) and 'Google Calendar event: done' in text(tech) and 'Email to the customer: done' in text(tech) and 'tries: 1' in text(tech) and 'https://example.invalid/prenota/riunione' in tech and 'gev_abc123' in tech and ('token=' + TOKEN) in tech, text(tech)[:500])
check('F12 the manage token appears only inside the technical box, once', h.count(TOKEN) == 1 and TOKEN not in main, h.count(TOKEN))
hd = re.findall(r'<h([1-6])', main)
check('F13 headings in order: h2 name then h3 sections, no h1 inside the card area', hd[:1] == ['2'] and set(hd[1:]) == {'3'}, hd)
ids_ok = all(('id="%s"' % i_) in main for i_ in re.findall(r'aria-labelledby="([^"]+)"', main))
check('F14 sections have accessible names (aria-labelledby points at a heading)', ids_ok and 'aria-labelledby' in main)
check('F15 the stylesheet is the component asset (no inline style attributes in the card area)', 'com_webgbooking/css/booking-detail.css' in h and 'style="' not in main, re.findall(r'style="[^"]*"', main)[:2])
css = Sess().get(BASE + '/media/com_webgbooking/css/booking-detail.css')
check('F16 the CSS file is served, uses Bootstrap variables', '.wgb-bk-hero' in css and 'var(--bs-border-color' in css , css[:80])
check('F17 the toolbar: Cancel yes, "Confirm and tell the customer" no (already confirmed), same tasks as before', 'booking.cancel' in tasks(h) and 'booking.confirmnotify' not in tasks(h), tasks(h))

# ---------------------------------------------------------------- 2. a phone booking
b2 = seed(location='phone', customer_phone='+39 333 777 0001', event_name='Telefonata', duration_min=30, meeting_url='')
h = page(b2)
t = text(body(h)[0])
check('P1 the phone booking has a big "Call" button with a tel: link on the customer number, and no Meet button', re.search(r'<a class="btn btn-primary btn-lg wgb-bk-btn" href="tel:\+393337770001">', h) is not None and 'Call' in t and 'Join with Google Meet' not in t and 'Phone call' in t, t[:300])
b2b = seed(location='phone', customer_phone='', duration_min=30)
h = page(b2b)
check('P2 a phone booking with no number: a clear line, no broken tel: link', 'did not give a phone number' in h and 'href="tel:"' not in h)

# ---------------------------------------------------------------- 3. Meet link still missing
b3 = seed(location='meet', meeting_url='', duration_min=60, fx_state='retry', fx_steps='{}', fx_tries=2, fx_last=S(fxlast))
h = page(b3)
check('M1 no Meet link yet: a plain line instead of a button, no empty href', 'not there yet' in h and 'Join with Google Meet' not in h and 'href=""' not in h)
check('M2 the technical box shows the pending effects state', 'to retry' in text(body(h)[1]) and 'tries: 2' in text(body(h)[1]))

# ---------------------------------------------------------------- 4. hostile text
H1, H2, H3 = '<script>alert(1)</script>', '<img src=x onerror=alert(2)>', '"><svg onload=alert(3)>'
b4 = seed(customer_name=H1 + 'Nome', customer_email='x@example.invalid', notes=H2 + '\n' + H3, event_name=H3, guest_email='g"@example.invalid',
          custom_data=json.dumps([{'l': H2, 'v': H1}, {'l': 'ok', 'v': H3}]), source_url='javascript:alert(4)', location='meet', meeting_url='javascript:alert(5)', duration_min=60,
          customer_phone='+39 <b>1</b>')
h = page(b4)
main, tech = body(h)
check('H1 hostile name, notes, answers and event type are escaped everywhere', all(x not in h for x in (H1, H2, H3, '<svg onload', '<img src=x')) and '&lt;script&gt;alert(1)&lt;/script&gt;Nome' in h and '&lt;img src=x onerror=alert(2)&gt;' in h, [x for x in (H1, H2, H3) if x in h])
hrefs = re.findall(r'href="([^"]*)"', main + tech)
check('H2 a javascript: Meet link or source page never becomes a link', not any(x.startswith('javascript') for x in hrefs) and 'Join with Google Meet' not in main, hrefs)
check('H3 hostile phone: the tel: link only carries digits and +', 'href="tel:+391"' in h and '<b>1</b>' not in h, re.findall(r'href="tel:[^"]*"', h))

# ---------------------------------------------------------------- 5. a bare booking: nothing optional, no empty rows
b5 = seed(manage_token=None)
h = page(b5)
main, tech = body(h)
t = text(main)
area = h[h.find('class="wgb-bk"'):]
check('B1 bare booking: renders without warnings', 'wgb-bk-hero' in h and 'Fatal error' not in h and 'Warning:' not in h and 'Notice:' not in h and 'Deprecated:' not in h, t[:200])
check('B2 no empty value anywhere: no empty dd / dt / li / link, no "Answers and notes" box, no "None" or "null"', not re.search(r'<dd>\s*</dd>|<dt>\s*</dt>|<li[^>]*>\s*</li>|<a [^>]*>\s*</a>', area) and 'Answers and notes' not in t and not re.search(r'\bNone\b|\bnull\b|\bNaN\b', t), t)
check('B3 the history still has the creation line', 'Booking created' in t and '<ol class="wgb-bk-timeline">' in main)
check('B4 date and time alone in the header (no duration), the not-yet-available line for Meet', long_date(D) + ', 09:30' in t and '09:30-' not in t and 'not there yet' in t, t[:300])
check('B5 technical box still there, closed, with the number and no manage link', '<details class="wgb-bk-card wgb-bk-tech">' in h and '#%d' % b5 in text(tech) and 'Manage link' not in text(tech), text(tech))

# ---------------------------------------------------------------- 6. history: past reminders, moved, cancelled, paid, status
pastday = day(-3)
created6 = datetime.datetime.now(UTC).replace(microsecond=0) - datetime.timedelta(days=10)
b6 = seed(booking_date=pastday, created=S(created6), reminder_cfg=json.dumps({'on': 1, 'times': [10080, 1440, 60]}), reminders_sent=json.dumps([1440, 60]), duration_min=60,
          status='completed', updated=S(datetime.datetime.now(UTC) - datetime.timedelta(days=2)), seq=0, fx_state='done', fx_steps=json.dumps({'mail_customer': True, 'google': True}), fx_tries=1, fx_last=S(created6), google_event_id='g6')
h = page(b6)
tl = body(h)[0]
st6 = datetime.datetime.strptime(pastday + ' 09:30', '%Y-%m-%d %H:%M').replace(tzinfo=ROME)
check('R1 past reminders show as sent with their real date; none is "upcoming"', 'Reminder sent to the customer, 1 day before' in tl and 'Reminder sent to the customer, 1 hour before' in tl and short(st6 - datetime.timedelta(days=1)) in tl and 'Upcoming' not in tl, text(tl)[-500:])
check('R2 a reminder whose time was past when the booking was made (7 days) is not claimed as sent, and 10080 minutes is never written', 'Reminder sent to the customer, 7 days before' not in tl and '10080' not in tl, '')
check('R3 a completed booking has a "status set" line and the badge in text', 'Status set to: Completed' in tl and re.search(r'text-bg-info[^"]*">Completed</span>', tl) is not None, '')
b7 = seed(status='cancelled', seq=1, updated=S(datetime.datetime.now(UTC) - datetime.timedelta(hours=2)), meeting_url='https://meet.google.com/zzz-zzzz-zzz', duration_min=60, location='meet',
          customer_phone='+39 333 000 1234', reminder_cfg=json.dumps({'on': 1, 'times': [60]}), reminders_sent='[]')
h = page(b7)
tl = body(h)[0]
check('C1 cancelled: line "Booking cancelled", red text badge, no Meet button, no upcoming reminder', 'Booking cancelled' in tl and 'text-bg-danger' in tl and 'Join with Google Meet' not in tl and 'Upcoming' not in tl, text(tl)[:400])
check('C2 cancelled: the toolbar has no Cancel button (as before)', 'booking.cancel' not in tasks(h), tasks(h))
b8 = seed(status='confirmed', seq=2, updated=S(datetime.datetime.now(UTC) - datetime.timedelta(hours=3)), duration_min=60)
check('C3 moved: a confirmed booking with seq > 0 shows "Appointment moved"', 'Appointment moved' in body(page(b8))[0])
b9 = seed(status='confirmed', amount_cents=2900, currency='EUR', payment_status='paid', paid_at=S(created + datetime.timedelta(minutes=5)), stripe_payment_intent='pi_test_999', stripe_session_id='cs_test_999', duration_min=60)
h = page(b9)
main, tech = body(h)
check('Y1 paid: payment card (amount, status, paid at) and a history line; the Stripe ids only in the technical box', '29,00 EUR' in text(main) and 'Payment received: 29,00 EUR' in text(main) and 'Paid at' in text(main) and 'pi_test_999' not in main and 'pi_test_999' in tech and 'cs_test_999' in tech and 'Stripe payment intent' in tech, text(main)[-400:])
b10 = seed(status='cancelled', amount_cents=2900, currency='EUR', payment_status='paid', paid_at=S(created), seq=1, updated=S(datetime.datetime.now(UTC)))
tt = text(body(page(b10))[0]).lower()
check('Y2 paid and cancelled: the refund notice is in the payment card', 'refund' in tt or 'rimborsare' in tt, tt[-300:])
b11 = seed(status='awaiting_payment', amount_cents=2900, currency='EUR', payment_status='pending', hold_until=S(datetime.datetime.now(UTC) + datetime.timedelta(minutes=20)))
tt = text(body(page(b11))[0])
check('Y3 awaiting payment: badge text and "Slot held until" a formatted time', 'Awaiting payment' in tt and re.search(r'Slot held until \d+ \w+ 20\d\d, \d\d:\d\d', tt) is not None, tt[-300:])

# ---------------------------------------------------------------- 7. customer time zone (the column may not exist yet: add it for these checks only)
had = sql("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='joomla' AND table_name='joom_webgbooking_bookings' AND column_name='customer_tz'") != '0'
if not had:
    sql('ALTER TABLE %s ADD COLUMN customer_tz VARCHAR(64) NULL' % TBL)
try:
    b12 = seed(customer_tz='America/New_York', duration_min=60)
    b13 = seed(customer_tz='Europe/Rome', duration_min=60)
    b14 = seed(customer_tz='Mars/Olympus', duration_min=60)
    b15 = seed(duration_min=60)
    loc = start.astimezone(zoneinfo.ZoneInfo('America/New_York'))
    t12 = text(body(page(b12))[0])
    check('Z1 customer time zone shown with the customer local time when it differs from the site', "Customer's time zone America/New_York" in t12 and 'Time for the customer %s' % loc.strftime('%H:%M') in t12, t12[t12.find('Customer'):][:200])
    t13 = text(body(page(b13))[0])
    check('Z2 same offset as the site: the zone is shown, no second time', 'Europe/Rome' in t13 and 'Time for the customer' not in t13, '')
    h14 = page(b14)
    check('Z3 an unknown zone name is ignored without errors', 'wgb-bk-hero' in h14 and 'Mars' not in h14 and 'Warning:' not in h14 and 'Fatal error' not in h14, '')
    check('Z4 a NULL zone: no row', "Customer's time zone" not in text(body(page(b15))[0]))
finally:
    if not had:
        sql('ALTER TABLE %s DROP COLUMN customer_tz' % TBL)

# ---------------------------------------------------------------- 8. toolbar actions and rights work as before
b16 = seed(status='pending', duration_min=60)
h = page(b16)
check('T1 pending: both "Confirm and tell the customer" and Cancel in the toolbar, badge "Pending"', 'booking.confirmnotify' in tasks(h) and 'booking.cancel' in tasks(h) and 'Pending' in text(body(h)[0]), tasks(h))
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(b16)), ('option', 'com_webgbooking'), (tk, '1')])
check('T2 the toolbar action still works: confirmed, and the page then shows the badge', sql('SELECT status FROM %s WHERE id=%d' % (TBL, b16)) == 'confirmed' and 'Confirmed' in text(body(page(b16))[0]))
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % b16, [('task', 'booking.cancel'), ('id', str(b16)), ('boxchecked', '0'), ('option', 'com_webgbooking'), (tk, '1')])
check('T3 Cancel from the detail still cancels, and the page then reads "Booking cancelled"', sql('SELECT status FROM %s WHERE id=%d' % (TBL, b16)) == 'cancelled' and 'Booking cancelled' in body(page(b16))[0])
check('T4 the Back button is there', 'view=bookings' in h)

b17 = seed(status='pending', duration_min=60)
sh(['docker', 'exec', J, 'php', 'cli/joomla.php', 'user:add', '--username=mgrro', '--name=Manager RO', '--password=Mgr-ro-test-pass-123!', '--email=mgrro@example.invalid', '--usergroup=Administrator'])
sql("UPDATE joom_assets SET rules='{\"core.create\":{\"7\":0},\"core.edit\":{\"7\":0},\"core.edit.state\":{\"7\":0},\"core.delete\":{\"7\":0}}' WHERE name='com_webgbooking'")
ro = Sess()
hh = ro.get(BASE + '/administrator/index.php')
tks = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', hh)
dd = {'username': 'mgrro', 'passwd': 'Mgr-ro-test-pass-123!', 'option': 'com_login', 'task': 'login', 'return': base64.b64encode(b'index.php').decode()}
for t_ in tks:
    dd[t_] = '1'
ro.post(BASE + '/administrator/index.php', dd)
h = page(b17, ro)
check('X1 without the state right: the page is readable but the toolbar has no Confirm / Cancel', 'wgb-bk-hero' in h and 'booking.confirmnotify' not in tasks(h) and 'booking.cancel' not in tasks(h), tasks(h))
tk_ro = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)
post_pairs(ro, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(b17)), ('option', 'com_webgbooking')] + [(k, '1') for k in tk_ro[:1]])
post_pairs(ro, BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % b17, [('task', 'booking.cancel'), ('id', str(b17)), ('option', 'com_webgbooking')] + [(k, '1') for k in tk_ro[:1]])
check('X2 ... and posting the tasks by hand changes nothing', sql('SELECT status FROM %s WHERE id=%d' % (TBL, b17)) == 'pending', sql('SELECT status FROM %s WHERE id=%d' % (TBL, b17)))
sql("UPDATE joom_assets SET rules='{}' WHERE name='com_webgbooking'")
sql("DELETE FROM joom_user_usergroup_map WHERE user_id=(SELECT id FROM joom_users WHERE username='mgrro')")
sql("DELETE FROM joom_users WHERE username='mgrro'")

check('N1 a booking that does not exist: no PHP error', 'Fatal error' not in adm.get(URL % 999999))
check('D1 dark mode: every colour in the stylesheet goes through a Bootstrap / Atum variable (the only hex values are var() fallbacks)', all('var(' in l for l in css.splitlines() if re.search(r'#[0-9a-fA-F]{3,6}\b', l)), [l for l in css.splitlines() if re.search(r'#[0-9a-fA-F]{3,6}\b', l) and 'var(' not in l][:3])

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
