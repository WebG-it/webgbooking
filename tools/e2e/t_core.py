import sys, json, threading, time
sys.argv = [sys.argv[0]] + sys.argv[1:]
from wgbtest import *

reset_env()
D = day(6)
D2 = day(7)

cfg_hour, cfg_half, cfg_def = page_cfg('hour'), page_cfg('half'), page_cfg('def')
check('tokens present (form + ev)', all(c.get('form') and c.get('ev') for c in (cfg_hour, cfg_half, cfg_def)))
check('widget cfg: hour duration 60 / choice', cfg_hour['duration'] == 60 and cfg_hour['location'] == 'choice' and cfg_hour['redirectUrl'] == '/wgbtest/thanks.html', cfg_hour)
check('widget cfg: half duration 30 / phone', cfg_half['duration'] == 30 and cfg_half['location'] == 'phone')
check('widget cfg: default falls back to plugin duration 30 / meet / no redirect', cfg_def['duration'] == 30 and cfg_def['location'] == 'meet' and cfg_def['redirectUrl'] == '')

# --- 1. availability depends on the element's duration (server side)
mh = month(cfg_hour['ev'], D, D)
mf = month(cfg_half['ev'], D, D)
md = month(cfg_def['ev'], D, D)
check('60 min element: last slot 17:00, first 09:00', mh.get(D, [])[:1] == ['09:00'] and mh[D][-1] == '17:00', mh.get(D))
check('30 min element: last slot 17:30', mf[D][-1] == '17:30', mf.get(D))
check('default element (30 min global): last slot 17:30', md[D][-1] == '17:30')
# tampered ev token -> plugin defaults (30 min)
bad = cfg_hour['ev'][:-4] + ('0000' if not cfg_hour['ev'].endswith('0000') else '1111')
mb = month(bad, D, D)
check('tampered ev falls back to global defaults (30 min)', mb[D][-1] == '17:30', mb.get(D))
mn = month('', D, D)
check('missing ev (old cached page) falls back to global defaults', mn[D][-1] == '17:30')

# --- 2. a 60 min "choice" booking on the phone, with buffers 15/15
r = book('hour', D, '10:00', phone='+39 333 123 4567', location='phone', guest='ospite@example.invalid', notes='Voglio parlare di SEO')
check('hour/phone booking ok', r.get('ok') is True, r)
bid = last_id()
rw = row(bid)
check('row: duration 60, buffers 15/15, phone, event name, no meeting url', rw['duration_min'] == '60' and rw['buffer_before'] == '15' and rw['buffer_after'] == '15' and rw['location'] == 'phone' and rw['event_name'] == 'Riunione - 1 ora' and rw['meeting_url'] in ('', 'NULL'), rw)
ev = gm_events()
e = list(ev.values())[0] if ev else {}
check('Google event created WITHOUT Meet', bool(e) and 'conferenceData' not in e and 'hangoutLink' not in e, e)
check('Google event: 60 min, phone number in title and description', e.get('summary', '').find('+39 333 123 4567') > 0 and 'Riunione - 1 ora' in e.get('summary', '') and '+39 333 123 4567' in e.get('description', '') and e.get('start', {}).get('dateTime', '').endswith('T10:00:00') and e.get('end', {}).get('dateTime', '').endswith('T11:00:00'), e)
ms = mails()
check('emails sent (customer, guest, staff)', len(ms) >= 3, ms)
cm = [decode_mail(mail_text(p)) for p in ms]
cust = next((m for m in cm if 'mario@example.invalid' in m['rcpt']), None)
check('customer email says "we call you at X", no Meet button', cust is not None and 'Ti chiamo al numero +39 333 123 4567' in cust['html'] or (cust is not None and 'I will call you on +39 333 123 4567' in cust['html']), cust and cust['html'][:300])
check('customer email has no meet link / no Google Meet button', cust is not None and 'meet.google' not in cust['html'] and 'Join the video' not in cust['html'] and 'Partecipa alla videochiamata' not in cust['html'])
check('ics: LOCATION is the call, DTEND 60 min after DTSTART', cust is not None and 'LOCATION:I call you on +39 333 123 4567' in cust['ics'].replace('\r', '') or (cust is not None and 'LOCATION:Ti chiamo' in cust['ics']), cust and cust['ics'])
if cust:
    m1 = re.search(r'DTSTART:(\d{8}T\d{6})Z', cust['ics']); m2 = re.search(r'DTEND:(\d{8}T\d{6})Z', cust['ics'])
    a = datetime.datetime.strptime(m1.group(1), '%Y%m%dT%H%M%S'); b = datetime.datetime.strptime(m2.group(1), '%Y%m%dT%H%M%S')
    check('ics duration is 60 min', (b - a).seconds == 3600)
    check('ics SUMMARY is the event name', 'SUMMARY:Riunione - 1 ora' in cust['ics'])
guest = next((m for m in cm if 'ospite@example.invalid' in m['rcpt']), None)
check('guest email does not reveal the customer phone', guest is not None and '+39 333' not in guest['html'])
staff = next((m for m in cm if 'staff@wgbtest.invalid' in m['rcpt']), None)
check('staff email mentions the phone call and number', staff is not None and '+39 333 123 4567' in (staff['html']))
rs = json.loads(row(bid)['reminders_sent'] or '[]')
check('reminders pre-marked: 24h not sent for a booking in 3 days? (24h still ahead => not marked)', rs == [], rs)

# --- 3. overlap logic with different durations and buffers
mh2 = month(cfg_hour['ev'], D, D)[D]
mf2 = month(cfg_half['ev'], D, D)[D]
check('after 10:00-11:00 (buf 15/15), 60-min slots: 09:00, 10:00, 10:30, 11:00 gone', all(t not in mh2 for t in ('09:00', '09:30', '10:00', '10:30', '11:00')) and '11:30' in mh2 and '17:00' in mh2, mh2)
check('30-min slots: 09:00 ok, 09:30..11:00 gone (existing buffers), 11:30 ok', '09:00' in mf2 and all(t not in mf2 for t in ('09:30', '10:00', '10:30', '11:00')) and '11:30' in mf2, mf2)
r = book('half', D, '10:30', phone='0612345678')
check('overlapping 30-min booking at 10:30 is REJECTED (different start, same time range)', r.get('ok') is False, r)
r = book('half', D, '09:30', phone='0612345678', email='b@example.invalid')
check('booking inside the 15-min buffer is rejected', r.get('ok') is False, r)
r = book('half', D, '09:00', phone='0612345678', email='c@example.invalid')
check('adjacent 09:00-09:30 booking accepted (gap >= buffer)', r.get('ok') is True, r)

# --- 4. phone validation (server side)
r = book('half', D2, '09:00', phone='', email='d@example.invalid')
check('phone-call element without phone rejected', r.get('ok') is False and ('telefon' in json.dumps(r).lower() or 'phone' in json.dumps(r).lower()), r)
r = book('half', D2, '09:00', phone='abc', email='d@example.invalid')
check('phone-call element with junk phone rejected', r.get('ok') is False, r)
r = book('half', D2, '09:00', phone='12', email='d@example.invalid')
check('phone-call element with 2-digit phone rejected', r.get('ok') is False, r)
r = book('hour', D2, '09:00', phone='', email='d@example.invalid', location='phone')
check('choice element, customer picks phone, no phone -> rejected', r.get('ok') is False, r)
r = book('hour', D2, '09:00', phone='', email='d@example.invalid', location='meet')
check('choice element, customer picks Meet, no phone -> accepted', r.get('ok') is True, r)
mid = last_id()
rw = row(mid)
check('Meet booking stores location meet + Meet link from Google', rw['location'] == 'meet' and rw['meeting_url'].startswith('https://meet.google.com/'), rw)
evs = gm_events()
em = evs.get(rw['google_event_id'], {})
check('Google event has conferenceData for Meet booking, 60 min', 'conferenceData' in em and em['end']['dateTime'].endswith('T10:00:00'), em)
r = book('hour', D2, '12:00', phone='', email='e@example.invalid', location='nonsense')
check('invalid posted location on a choice element falls back to Meet (accepted)', r.get('ok') is True, r)
r = book('half', D2, '15:00', phone='0612345678', email='f@example.invalid', location='meet')
check('fixed phone element ignores a posted location=meet (still phone)', r.get('ok') is True and row(last_id())['location'] == 'phone', r)

# --- 5. concurrency: many visitors, one slot
results = []
def worker(i):
    r = book('half', day(8), '14:00', phone='06123456%02d' % i, email='race%d@example.invalid' % i, name='Race %d' % i)
    results.append(r.get('ok'))
ths = [threading.Thread(target=worker, args=(i,)) for i in range(8)]
[t.start() for t in ths]; [t.join() for t in ths]
n = int(sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE booking_date='%s' AND booking_time='14:00' AND status!='cancelled'" % day(8)))
check('8 parallel bookings of the same slot -> exactly 1 stored', n == 1 and results.count(True) == 1, (n, results))
# overlapping-but-different-start race
results = []
def worker2(i):
    t = ['16:00', '16:30'][i % 2]
    r = book('hour' if i % 2 == 0 else 'half', day(8), t, phone='06123456%02d' % i, email='ov%d@example.invalid' % i, location='phone' if i % 2 == 0 else '')
    results.append(r.get('ok'))
ths = [threading.Thread(target=worker2, args=(i,)) for i in range(8)]
[t.start() for t in ths]; [t.join() for t in ths]
rows = sql("SELECT booking_time,duration_min FROM joom_webgbooking_bookings WHERE booking_date='%s' AND booking_time>='16:00' AND status!='cancelled'" % day(8)).split('\n')
check('racing 16:00(60min) vs 16:30(30min): never two overlapping bookings stored', len([r for r in rows if r]) == 1, rows)

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
