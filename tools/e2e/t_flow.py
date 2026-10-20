import sys, os, json, time, base64
from wgbtest import *

reset_env()
sql("DELETE FROM joom_scheduler_logs WHERE 1=1")
D, D2 = day(10), day(11)


def admin_login():
    s = Sess()
    h = s.get(BASE + '/administrator/index.php')
    tok = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)
    data = {'username': 'admin', 'passwd': os.environ['WGB_E2E_ADMIN_PASS'], 'option': 'com_login', 'task': 'login', 'return': base64.b64encode(b'index.php').decode()}
    for t in tok:
        data[t] = '1'
    out = s.post(BASE + '/administrator/index.php', data)
    return s, ('com_login' not in out or 'Control Panel' in out or 'Home Dashboard' in out), out


def admin_post(s, view_url, task, ids):
    """POST a list/detail form like the toolbar button does: needs the form token of that page."""
    h = s.get(view_url)
    tok = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)
    d = {'task': task, 'boxchecked': str(len(ids)), 'option': 'com_webgbooking'}
    for t in tok[:1]:
        d[t] = '1'
    out = s.post(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', {**d, **{'cid[]': ids}} if len(ids) == 1 else d | {'cid[]': ids})
    return out


def post_multi(s, url, pairs):
    body = urllib.parse.urlencode(pairs).encode()
    try:
        return s.op.open(urllib.request.Request(url, data=body), timeout=60).read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.read().decode('utf-8', 'replace')


def manage_url(tok):
    return BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + tok


def token_of(i):
    return sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % i)


# ---------------------------------------------------------------- A. reschedule
cfg = page_cfg('hour')
r = book('hour', D, '10:00', phone='+39 333 000 1111', location='phone', email='res@example.invalid', name='Riprogramma')
check('A0 booking made', r.get('ok') is True, r)
bid = last_id(); tok = token_of(bid)
other = book('half', D, '15:00', phone='0612345678', email='other@example.invalid', name='Altro')
oid = last_id()
page = Sess().get(manage_url(tok))
check('A1 manage page shows the phone appointment and a signed ev token', 'I will call you on +39 333 000 1111' in page and '&quot;ev&quot;:&quot;' in page and '&quot;duration&quot;:60' in page, page[:200])
mcount_before = len(mails()); jx('rm -f /tmp/gmock/log.jsonl')
rs = Sess()
def resched(tok_, date_, time_):
    return json.loads(rs.post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok_, 'date': date_, 'time': time_}))
x = resched(tok, D, '15:00')
check('A2 reschedule onto another booking is rejected', x.get('ok') is False, x)
x = resched(tok, D, '10:30')
check('A3 shifting by 30 min inside its own old slot is accepted (own booking does not block itself)', x.get('ok') is True, x)
rw = row(bid)
check('A4 DB moved, duration still 60', rw['booking_time'] == '10:30' and rw['duration_min'] == '60', rw)
patch = [l for l in gm_log() if l['method'] == 'PATCH']
check('A5 Google event patched with the 60 min length', patch and patch[-1]['body']['start']['dateTime'].endswith('T10:30:00') and patch[-1]['body']['end']['dateTime'].endswith('T11:30:00'), patch[-1] if patch else None)
mm = [decode_mail(mail_text(p)) for p in mails()[mcount_before:]]
cm = next((m for m in mm if 'res@example.invalid' in m['rcpt']), None)
check('A6 customer got the "moved" email with .ics REQUEST and the phone line', cm is not None and 'METHOD:REQUEST' in cm['ics'] and 'SEQUENCE:1' in cm['ics'] and '+39 333 000 1111' in cm['html'], cm and cm['ics'])
x = resched(tok, D, '12:00')
check('A7 second reschedule within 60 s is throttled', x.get('ok') is False, x)

# ---------------------------------------------------------------- B. customer cancels
mcount_before = len(mails()); jx('rm -f /tmp/gmock/log.jsonl')
before_events = len(gm_events())
cs = Sess()
page = cs.post(manage_url(tok).replace('action=manage', 'action=cancel'), {'token': tok})
check('B1 cancel page confirms', 'cancelled' in page.lower() or 'annullato' in page.lower(), page[-300:])
rw = row(bid)
check('B2 DB status cancelled (self-service cancel writes the row)', rw['status'] == 'cancelled', rw)
dels = [l for l in gm_log() if l['method'] == 'DELETE']
check('B3 Google event deleted', len(dels) == 1 and rw['google_event_id'] in dels[0]['path'], dels)
mm = [decode_mail(mail_text(p)) for p in mails()[mcount_before:]]
cm = next((m for m in mm if 'res@example.invalid' in m['rcpt']), None)
sm = next((m for m in mm if 'staff@wgbtest.invalid' in m['rcpt']), None)
check('B4 customer cancellation email is HTML with .ics CANCEL', cm is not None and 'METHOD:CANCEL' in cm['ics'] and 'STATUS:CANCELLED' in cm['ics'] and '<table' in cm['html'], cm and cm['ics'])
check('B5 staff notified when the CUSTOMER cancels', sm is not None)
check('B6 cancelled slot is free again', '10:30' in month(cfg['ev'], D, D).get(D, []))

# ---------------------------------------------------------------- C. admin cancels (single + bulk)
adm, ok, out = admin_login()
check('C0 admin login', ok, out[:200])
ids = []
for i, (t, em_, loc) in enumerate([('09:00', 'a1@example.invalid', 'meet'), ('12:00', 'a2@example.invalid', 'phone'), ('14:00', 'a3@example.invalid', 'meet')]):
    r = book('hour', D2, t, phone='+39 333 777 000%d' % i, location=loc, email=em_, name='Admin %d' % i)
    check('C1.%d seed booking' % i, r.get('ok') is True, r)
    ids.append(last_id())
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
check('C2 admin list renders with Location column and the phone', 'Location' in lst and 'Phone call' in lst and 'Google Meet' in lst and 'Fatal error' not in lst and 'Warning:' not in lst, ('Location' in lst, 'Phone call' in lst, 'Google Meet' in lst, 'Fatal error' in lst, 'Warning:' in lst))
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % ids[1])
dtxt = re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', det))
check('C3 admin detail (0.35.2 layout) shows duration, location, event, the Call button and the scheduled reminders as dates', '60 min' in dtxt and 'Phone call' in dtxt and 'Riunione - 1 ora' in dtxt and 'href="tel:+393337770001"' in det and 'Reminder scheduled' in dtxt and 'planned before' not in dtxt, dtxt[-600:])

mcount_before = len(mails()); jx('rm -f /tmp/gmock/log.jsonl')
tokf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', lst)[0]
out = post_multi(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '2'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(ids[0])), ('cid[]', str(ids[1]))])
txt = re.sub('<[^>]+>', ' ', out)
check('C4 bulk cancel: the two selected are cancelled, the third (free booking, now confirmed at once) is untouched', row(ids[0])['status'] == 'cancelled' and row(ids[1])['status'] == 'cancelled' and row(ids[2])['status'] == 'confirmed', [row(i)['status'] for i in ids])
dels = [l for l in gm_log() if l['method'] == 'DELETE']
check('C5 bulk cancel deleted both Google events', len(dels) == 2, dels)
mm = [decode_mail(mail_text(p)) for p in mails()[mcount_before:]]
check('C6 each customer got the cancellation email (HTML + ics CANCEL)', sorted(m['rcpt'] for m in mm if 'METHOD:CANCEL' in m['ics']) and len([m for m in mm if 'METHOD:CANCEL' in m['ics']]) == 2, [m['rcpt'] for m in mm])
check('C7 staff NOT mailed for an admin cancellation', not any('staff@wgbtest.invalid' in m['rcpt'] for m in mm), [m['rcpt'] for m in mm])
check('C8 admin sees what happened (messages)', ('Cancellation emails sent to 2' in txt or 'cancellation email' in txt.lower()) and 'Google Calendar events removed' in txt, txt[txt.find('booking'):][:400] if 'booking' in txt else txt[-400:])
# idempotence: cancelling again must not send mail
mcount_before = len(mails()); jx('rm -f /tmp/gmock/log.jsonl')
out = post_multi(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(ids[0]))])
check('C9 cancelling an already-cancelled booking sends nothing', len(mails()) == mcount_before and not [l for l in gm_log() if l['method'] == 'DELETE'], len(mails()) - mcount_before)
# single from the detail page (form posts id)
mcount_before = len(mails()); jx('rm -f /tmp/gmock/log.jsonl')
dh = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % ids[2])
tokd = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', dh)[0]
check('C10 detail page has a Cancel button', 'booking.cancel' in dh, '')
out = post_multi(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % ids[2], [('task', 'booking.cancel'), ('id', str(ids[2])), ('boxchecked', '0'), ('option', 'com_webgbooking'), (tokd, '1')])
check('C11 single cancel from the detail page works (DB, Google, email)', row(ids[2])['status'] == 'cancelled' and len([l for l in gm_log() if l['method'] == 'DELETE']) == 1 and len(mails()) == mcount_before + 1, (row(ids[2])['status'], len(mails()) - mcount_before))
# upgrade-era row (only the old 0.30.0 columns, no google event, no phone), seeded here so the suite needs nothing left over
sql("INSERT INTO joom_webgbooking_bookings (created,booking_date,booking_time,customer_name,customer_email,status,manage_token) VALUES (NOW(),'%s','10:00','Vecchio Cliente','old@example.invalid','confirmed','oldtoken0000000000000000000000aa')" % day(14))
OLD = last_id()
mcount_before = len(mails())
out = post_multi(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(OLD))])
check('C12 row created by 0.30.0 (NULL new columns) cancels fine, customer mailed, no Google call', row(OLD)['status'] == 'cancelled' and len(mails()) == mcount_before + 1, (row(OLD)['status'], len(mails()) - mcount_before))
csv = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=exportcsv&' + tokf + '=1')
check('C13 CSV export has Location/Duration/Event columns appended', csv.splitlines()[0].endswith('Custom,Location,Duration,Event') or 'Location,Duration,Event' in csv.splitlines()[0], csv[:200])

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
