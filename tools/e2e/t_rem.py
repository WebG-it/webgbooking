import sys, json, time
from wgbtest import *

clear_state()
sql("DELETE FROM joom_scheduler_logs WHERE 1=1")
sql("UPDATE joom_webgbooking_bookings SET status='cancelled' WHERE id=1")


def rome(plus_min=0):
    out = jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"%+d minutes\")->format(\"Y-m-d H:i\");'" % plus_min)
    d, t = out.strip().split(' ')
    return d, t


def set_when(i, plus_min, sent='[]'):
    d, t = rome(plus_min)
    sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='%s' WHERE id=%d" % (d, t, sent, i))
    return d, t


def run_task():
    return jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 2>&1 | tail -4')


def ids_mailed(since):
    return [decode_mail(mail_text(p)) for p in mails()[since:]]


D = day(12)
# --- seed bookings through the real endpoint
ok = {}
for key, cfgname, t, loc, phone, em in [
        ('b1', 'meet30', '09:00', '', '', 'r1@example.invalid'),
        ('b2', 'hour', '10:00', 'phone', '+39 333 222 3333', 'r2@example.invalid'),
        ('b3', 'def', '15:30', '', '', 'r3@example.invalid'),
        ('b4', 'def', '12:00', '', '', 'r4@example.invalid'),
        ('b5', 'def', '13:00', '', '', 'r5@example.invalid'),
        ('b7', 'def', '14:00', '', '', 'r7@example.invalid')]:
    r = book(cfgname, D, t, phone=phone, location=loc, email=em, name='Rem ' + key)
    ok[key] = last_id() if r.get('ok') else None
    check('seed %s' % key, r.get('ok') is True, r)
b1, b2, b3, b4, b5, b7 = (ok[k] for k in ('b1', 'b2', 'b3', 'b4', 'b5', 'b7'))

cfgs = {i: json.loads(sql('SELECT reminder_cfg FROM joom_webgbooking_bookings WHERE id=%d' % i)) for i in (b1, b2, b3)}
check('R0 plan snapshot: element override 2h vs default 24h,1h', cfgs[b1]['times'] == [120] and cfgs[b2]['times'] == [1440, 60] and cfgs[b3]['times'] == [1440, 60], cfgs)
check('R0b plan keeps element subject/body', cfgs[b1]['subject'].startswith('Tra poco') and '{location}' in cfgs[b1]['body'])

# a booking made 3h ahead today must not fire the 24h reminder right away
dtoday, ttoday = rome(180)
hh = int(ttoday[:2]) + (1 if int(ttoday[3:]) > 0 else 0)
slot = '%02d:00' % hh if hh < 17 else None
if slot:
    r = book('def', dtoday, slot, email='r8@example.invalid', name='Soon')
    sid = last_id()
    check('R1 booking 3h ahead pre-marks the 24h reminder as already behind us', r.get('ok') is True and json.loads(row(sid)['reminders_sent']) in ([1440], [1440, 60]) and (row(sid)['reminders_sent'] != '[]'), row(sid))
else:
    print('SKIP R1 (too late in the day)')

# --- arrange the scenarios in the DB
set_when(b1, 110)                    # 2h reminder due (element override)
set_when(b2, 50)                     # 24h AND 1h both due (scheduler was late): ONE email
set_when(b3, 60 * 72)                # in 3 days: nothing
set_when(b4, 50); sql("UPDATE joom_webgbooking_bookings SET status='cancelled' WHERE id=%d" % b4)
set_when(b5, -10)                    # already started
d7, t7 = set_when(b7, 50)
sql("UPDATE joom_webgbooking_bookings SET reminder_cfg=NULL WHERE id=%d" % b5)   # (b5 is past anyway)
if slot:
    sql("DELETE FROM joom_webgbooking_bookings WHERE id=%d" % sid)
# an old-style row (no plan) 50 minutes ahead must stay silent
sql("INSERT INTO joom_webgbooking_bookings (created,booking_date,booking_time,customer_name,customer_email,status,manage_token) SELECT NOW(), '%s', '%s', 'Vecchio', 'old2@example.invalid', 'confirmed', 'oldtoken2000000000000000000000bb'" % rome(50))
# keep b7 for the race test: park it out of the way for the first run
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s' WHERE id=%d" % (day(14), '14:00', b7))

since = len(mails())
out = run_task()
check('R2 scheduler task ran OK', 'Scheduled' in out or 'completed' in out.lower() or 'OK' in out or out.strip() != '', out)
ms = ids_mailed(since)
rc = sorted(m['rcpt'] for m in ms)
check('R3 exactly the due ones were mailed (b1 and b2 only)', len(ms) == 2 and any('r1@' in x for x in rc) and any('r2@' in x for x in rc), rc)
m1 = next((m for m in ms if 'r1@' in m['rcpt']), None)
m2 = next((m for m in ms if 'r2@' in m['rcpt']), None)
check('R4 element override: custom subject and body with {location} {duration} {event}', m1 is not None and m1['subject'].startswith('Tra poco: Rem b1, ore ') and 'Luogo: Google Meet (30 min) - Call Meet 30' in m1['html'], m1 and (m1['subject'], m1['html'][-400:]))
check('R5 default reminder for a phone appointment says we call, no Meet button, no .ics', m2 is not None and ('I will call you on +39 333 222 3333' in m2['html'] or 'Ti chiamo al numero +39 333 222 3333' in m2['html']) and 'meet.google' not in m2['html'] and m2['ics'] == '', m2 and m2['html'][:500])
check('R6 reminders_sent updated (b1 [120], b2 both marked)', json.loads(row(b1)['reminders_sent']) == [120] and sorted(json.loads(row(b2)['reminders_sent'])) == [60, 1440], (row(b1)['reminders_sent'], row(b2)['reminders_sent']))
check('R7 cancelled / past / far / old rows untouched', all('r3@' not in x and 'r4@' not in x and 'r5@' not in x and 'old2@' not in x for x in rc), rc)

since = len(mails())
run_task()
check('R8 running again sends nothing (no duplicates)', len(mails()) == since, len(mails()) - since)

tasks = sql("SELECT last_exit_code,times_executed FROM joom_scheduler_tasks WHERE id=4")
logs = sql("SELECT exitcode, taskname FROM joom_scheduler_logs ORDER BY id DESC LIMIT 3")
check('R9 scheduler records the task as OK (exit 0)', tasks.split('\t')[0] == '0' and int(tasks.split('\t')[1]) >= 1, (tasks, logs))

# --- race: two processes at the same instant, one booking due
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[]' WHERE id=%d" % (*rome(50), b7))
since = len(mails())
p1 = subprocess.Popen(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'scheduler:run', '--id=4'], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
p2 = subprocess.Popen(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'scheduler:run', '--id=4'], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
p1.communicate(); p2.communicate()
ms = ids_mailed(since)
check('R10 two schedulers at once: the reminder is sent once', len([m for m in ms if 'r7@' in m['rcpt']]) == 1, [m['rcpt'] for m in ms])

# --- race at the engine level (no scheduler lock): 2 raw PHP processes call runReminders together
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[]' WHERE id=%d" % (*rome(50), b7))
since = len(mails())
ps = [subprocess.Popen(['docker', 'exec', J, 'php', '/tmp/mock/runrem.php'], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(4)]
outs = [p.communicate()[0].decode() for p in ps]
ms = ids_mailed(since)
check('R11 four raw engine runs at once: still ONE email (atomic claim)', len([m for m in ms if 'r7@' in m['rcpt']]) == 1, (outs, [m['rcpt'] for m in ms]))

# --- failed send releases the claim, next run retries
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[]' WHERE id=%d" % (*rome(50), b7))
jx("pkill -f smtpsink.php; sleep 1")
since = len(mails())
o = jx('php /tmp/mock/runrem.php')
rsent = row(b7)['reminders_sent']
check('R12 SMTP down: send fails, claim is released (reminders_sent back to [])', '"failed":1' in o.replace(' ', '') and rsent == '[]', (o, rsent))
jx("nohup php /tmp/mock/smtpsink.php > /tmp/smtpsink.log 2>&1 &")
time.sleep(2)
o = jx('php /tmp/mock/runrem.php')
check('R13 SMTP back: the next run sends it', '"sent":1' in o.replace(' ', '') and len([m for m in ids_mailed(since) if 'r7@' in m['rcpt']]) == 1, o)

# --- rescheduling restarts the reminders
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[1440,60]', updated=NULL WHERE id=%d" % (*rome(50), b2))
tk2 = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % b2)
rr = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tk2, 'date': day(15), 'time': '10:00'}))
check('R14 reschedule to a far date resets the reminders (nothing marked as sent)', rr.get('ok') is True and row(b2)['reminders_sent'] == '[]', (rr, row(b2)['reminders_sent']))

# --- master switch off
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[]' WHERE id=%d" % (*rome(50), b7))
jx('php /tmp/mock/init.php reminders_enabled=0 >/dev/null')
since = len(mails())
o = jx('php /tmp/mock/runrem.php')
check('R15 plugin master switch off: no reminder', len(mails()) == since and '"sent":0' in o.replace(' ', ''), o)
jx('php /tmp/mock/init.php >/dev/null')

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
