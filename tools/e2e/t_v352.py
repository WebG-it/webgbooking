"""0.35.2: the Google event carries what a Calendly event carries (description, place, confirmed and busy said out loud).
Checks the REAL payload that reaches the Google mock: insert, the second call that adds the Meet link (no second invite email), reschedule,
the guest (no link to move or cancel), the phone appointment (the business calls), hostile text. Each check fails on 0.35.1."""
import sys, os, re, json, html, http.client, urllib.parse
from wgbtest import *

TBL = 'joom_webgbooking_eventtypes'


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
    b = http_get('/prenota/' + slug)[2]
    return json.loads(html.unescape(re.search(r'data-wgb="([^"]+)"', b).group(1)))


def mtok(i):
    return sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % i)


def gev(bid):
    gid = row(bid)['google_event_id']
    return gid, gm_events().get(gid, {})


reset_env(render=False)
sql('DELETE FROM %s' % TBL)
jx('php /tmp/mock/init.php >/dev/null')
ins('meet-q', 'Riunione di prova', duration='60', location='meet', custom_fields='Argomento della riunione | text |\nQuanti siete? | select | | 1, 2-5, piu di 5')
ins('tel-q', 'Telefonata di prova', duration='30', location='phone', custom_fields='Argomento della riunione | text |')
D = day(3)

# ---------------------------------------------------------------- Meet, no guest
jx('rm -f /tmp/gmock/log.jsonl')
cfg = direct_cfg('meet-q')
cf = {'custom[cf0]': 'Il mio sito non porta contatti', 'custom[cf1]': '2-5'}
r = book(None, D, '10:00', name='Mario Rossi', email='mario351@example.invalid', phone='+39 333 123 4567', notes='Voglio capire se serve la SEO', cfg=cfg, extra=cf)
bid = last_id()
check('M0 the booking works and the Meet link is stored', r.get('ok') is True and row(bid)['meeting_url'].startswith('https://meet.google.com/mock-'), r)
gid, ev = gev(bid)
desc = ev.get('description', '')
url = row(bid)['meeting_url']
tok = mtok(bid)
check('M1 the event is confirmed and blocks the time, said out loud (not left to the default)', ev.get('transparency') == 'opaque' and ev.get('status') == 'confirmed', {k: ev.get(k) for k in ('transparency', 'status')})
check('M2 title unchanged: "<event> - <customer>"', ev.get('summary') == 'Riunione di prova - Mario Rossi', ev.get('summary'))
check('M3 the place is "Google Meet: <link>"', ev.get('location') == 'Google Meet: ' + url, ev.get('location'))
check('M4 description: kind of appointment and length', re.search(r'(Appointment|Appuntamento): Riunione di prova \(60 (minutes|minuti)\)', desc) is not None, desc)
check('M5 ... how it takes place: the Meet link', ('Google Meet: ' + url) in desc, desc)
check('M6 ... the phone number', re.search(r'(Phone number|Numero di telefono): \+39 333 123 4567', desc) is not None, desc)
check('M7 ... the notes', re.search(r'(Notes|Note): Voglio capire se serve la SEO', desc) is not None, desc)
check('M8 ... the answers to the questions, with their labels', 'Argomento della riunione: Il mio sito non porta contatti' in desc and 'Quanti siete?: 2-5' in desc, desc)
check('M9 ... the link to move or cancel (the customer\'s own manage link)', ('action=manage&token=' + tok) in desc and 'http' in desc.split('token=' + tok)[0].splitlines()[-1], desc)
lg = [l for l in gm_log() if '/calendar/v3/calendars/' in l['path'] and l['method'] in ('POST', 'PATCH')]
ins_ = [l for l in lg if l['method'] == 'POST']
pat_ = [l for l in lg if l['method'] == 'PATCH']
check('M10 the insert asks Google to send the invite (sendUpdates=all) and creates the Meet', len(ins_) == 1 and 'sendUpdates=all' in ins_[0]['query'] and 'conferenceDataVersion=1' in ins_[0]['query'], ins_ and ins_[0]['query'])
check('M11 the insert already has description, place and the confirmed/opaque fields, but not the Meet link (Google makes it)', ins_ and ins_[0]['body'].get('transparency') == 'opaque' and ins_[0]['body'].get('status') == 'confirmed' and ins_[0]['body'].get('description') and ('meet.google.com' not in ins_[0]['body']['description']) and ins_[0]['body'].get('location') == 'Google Meet', ins_ and {k: ins_[0]['body'].get(k) for k in ('description', 'location')})
check('M12 a second call adds the Meet link to description and place WITHOUT a second invite email (sendUpdates=none), and touches only those two fields', len(pat_) == 1 and 'sendUpdates=none' in pat_[0]['query'] and sorted(pat_[0]['body']) == ['description', 'location'], pat_)
check('M13 the attendee is the customer, nobody else', ins_ and [a['email'] for a in ins_[0]['body'].get('attendees', [])] == ['mario351@example.invalid'])

# ---------------------------------------------------------------- reschedule
jx('rm -f /tmp/gmock/log.jsonl')
rs = Sess()
D2 = day(4)
x = json.loads(rs.post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': D2, 'time': '11:00'}))
check('R1 the customer reschedules', x.get('ok') is True, x)
gid2, ev2 = gev(bid)
lg = [l for l in gm_log() if '/calendar/v3/calendars/' in l['path'] and l['method'] == 'PATCH']
check('R2 the same event moves (no second event) and keeps everything in the description', gid2 == gid and ev2.get('start', {}).get('dateTime', '').startswith(D2 + 'T11:00') and ('Google Meet: ' + url) in ev2.get('description', '') and 'Argomento della riunione: Il mio sito non porta contatti' in ev2.get('description', '') and ('token=' + tok) in ev2.get('description', ''), ev2.get('description'))
check('R3 the reschedule PATCH carries start, end, description and place (the conference is not touched)', lg and sorted(lg[0]['body']) == ['description', 'end', 'location', 'start'] and 'sendUpdates=all' in lg[0]['query'], lg and (sorted(lg[0]['body']), lg[0]['query']))
check('R4 the place still has the link', ev2.get('location') == 'Google Meet: ' + url, ev2.get('location'))

# ---------------------------------------------------------------- guest: no link to move or cancel
cfg = direct_cfg('meet-q')
r = book(None, D, '14:00', name='Anna Verdi', email='anna351@example.invalid', phone='+39 333 999 8888', notes='Con collega', guest='collega351@example.invalid', cfg=cfg, extra=cf)
bid_g = last_id()
tok_g = mtok(bid_g)
_, evg = gev(bid_g)
dg = evg.get('description', '')
check('G1 a booking with a guest: the guest sees the description, so it has NO link to move or cancel (and not the token)', r.get('ok') is True and 'action=manage' not in dg and tok_g not in dg, dg)
check('G2 ... the guest is named, the type and the Meet link are there, but NOTHING personal of the customer: no phone number, no notes, no answers', re.search(r'(Guest|Ospite): collega351@example\.invalid', dg) is not None and 'Google Meet: ' in dg and '+39 333 999 8888' not in dg and 'Con collega' not in dg and 'Il mio sito non porta contatti' not in dg and '2-5' not in dg, dg)
check('G3 the guest is an attendee of the event', 'collega351@example.invalid' in json.dumps(evg.get('attendees')), evg.get('attendees'))
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok_g, 'date': day(5), 'time': '10:00'}))
_, evg2 = gev(bid_g)
check('G4 after a reschedule the description still has no link to move or cancel', x.get('ok') is True and 'action=manage' not in evg2.get('description', '') and tok_g not in evg2.get('description', ''), x)

# a phone appointment WITH a guest: the guest must not read the customer's number (description, place, title)
ins('tel-guest', 'Telefonata con ospite', duration='30', location='phone', custom_fields='')
cfgx = direct_cfg('tel-guest')
r = book(None, D, '15:00', name='Carla Gialli', email='carla352@example.invalid', phone='+39 333 444 5555', notes='Una nota riservata', guest='ospitex352@example.invalid', cfg=cfgx)
bx = last_id()
_, evx = gev(bx)
alltxt = ' '.join([evx.get('summary', ''), evx.get('location', ''), evx.get('description', '')])
check('G5 phone appointment with a guest: no number of the customer in the title, the place or the description, and still says the business calls', r.get('ok') is True and '333 444 5555' not in alltxt and 'riservata' not in alltxt and re.search(r'(I will call you|ti chiamo io)', evx.get('description', ''), re.I) is not None and re.search(r'(I will call you|ti chiamo io)', evx.get('location', ''), re.I) is not None, alltxt)
# the option "put the management link in the Google event"
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.gcal_manage_link','0') WHERE element='com_webgbooking' AND type='component'")
r = book(None, D, '09:00', name='Dino Blu', email='dino352@example.invalid', phone='+39 333 111 2222', cfg=direct_cfg('meet-q'), extra={'custom[cf0]': 'x', 'custom[cf1]': '1'})
bo = last_id()
_, evo = gev(bo)
check('O1 option "management link in the Google event" set to No: no link, no token (the rest of the description stays)', r.get('ok') is True and 'action=manage' not in evo.get('description', '') and mtok(bo) not in evo.get('description', '') and '+39 333 111 2222' in evo.get('description', ''), evo.get('description'))
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.gcal_manage_link','1') WHERE element='com_webgbooking' AND type='component'")
r = book(None, D, '11:00', name='Elia Rosa', email='elia352@example.invalid', phone='+39 333 222 3333', cfg=direct_cfg('meet-q'), extra={'custom[cf0]': 'x', 'custom[cf1]': '1'})
be = last_id()
_, eve_ = gev(be)
check('O2 ... and Yes (the default) writes it again', r.get('ok') is True and ('token=' + mtok(be)) in eve_.get('description', ''), eve_.get('description'))
op = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'src', 'com_webgbooking', 'admin', 'config.xml'), encoding='utf-8').read()
check('O3 the option exists in the Options of the component, default Yes', 'name="gcal_manage_link"' in op and re.search(r'name="gcal_manage_link"[^>]*default="1"', op) is not None)

# ---------------------------------------------------------------- phone appointment: the business calls
cfg = direct_cfg('tel-q')
r = book(None, D, '16:00', name='Paolo Neri', email='paolo351@example.invalid', phone='+39 333 777 6666', notes='', cfg=cfg, extra={'custom[cf0]': 'Preventivo sito'})
bid_p = last_id()
gp, evp = gev(bid_p)
dp = evp.get('description', '')
check('P1 phone appointment: place "Phone call: I will call you on <number>"', r.get('ok') is True and re.search(r'^(Telefonata: ti chiamo io al|Phone call: I will call you on) \+39 333 777 6666$', evp.get('location', '')) is not None, evp.get('location'))
check('P2 ... the description says the business calls, with the number', re.search(r'(Ti chiamo io al numero|I will call you on) \+39 333 777 6666', dp) is not None, dp)
check('P3 ... no sentence tells the customer to call, no Meet, no conference in the insert', not re.search(r'chiamare|chiamaci|call us|call me|meet\.google', dp + ' ' + evp.get('location', ''), re.I) and not evp.get('hangoutLink'), dp)
lgp = [l for l in gm_log() if l['method'] == 'POST' and '/events' in l['path']][-1]
check('P4 the insert has no conferenceData, and no second call (there is no link to add)', 'conferenceData' not in lgp['body'] and not [l for l in gm_log() if l['method'] == 'PATCH' and gp in l['path']], lgp['body'].keys())
check('P5 the answers and the manage link are there too', 'Argomento della riunione: Preventivo sito' in dp and ('token=' + mtok(bid_p)) in dp, dp)

# ---------------------------------------------------------------- hostile text
cfg = direct_cfg('meet-q')
r = book(None, D, '12:00', name='Evil <b>Name</b>', email='evil351@example.invalid', phone='+39 333 000 1111', notes='<script>alert(1)</script> <a href="http://evil.example.invalid">click</a>\nsecond line', cfg=cfg, extra={'custom[cf0]': '<img src=x onerror=alert(1)>', 'custom[cf1]': '1'})
bid_e = last_id()
_, eve = gev(bid_e)
de = eve.get('description', '')
check('H1 hostile notes and answers: the description has no angle brackets and no markup', r.get('ok') is True and '<' not in de and '>' not in de, de)
check('H2 ... and no control characters, bounded size', all(ord(c) >= 32 or c == '\n' for c in de) and len(de) < 4000, len(de))

# ---------------------------------------------------------------- cancelling still removes the event
gid_g = row(bid_g)['google_event_id']
check('C0 the event of the guest booking is in Google before the cancellation', gid_g in gm_events())
murl = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel&token=' + tok_g
Sess().post(murl, {'token': tok_g})
check('C1 cancelling from the manage link still removes the event from Google (and the booking is cancelled)', gid_g not in gm_events() and row(bid_g)['status'] == 'cancelled', row(bid_g)['status'])
reset_env()

print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
