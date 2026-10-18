from wgbtest import *
clear_state()
sql("DELETE FROM joom_scheduler_logs WHERE 1=1")
def rome(plus_min=0):
    out = jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"%+d minutes\")->format(\"Y-m-d H:i\");'" % plus_min)
    d, t = out.strip().split(' ')
    return d, t
r = book('def', day(9), '09:00', email='lazy@example.invalid', name='Lazy')
bid = last_id()
d, t = rome(50)
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[1440]' WHERE id=%d" % (d, t, bid))
sql("UPDATE joom_scheduler_tasks SET next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE), locked=NULL WHERE id=4")
since = len(mails())
s = Sess()
out = s.get(BASE + '/index.php?option=com_ajax&plugin=RunSchedulerLazy&group=system&format=json')
print('lazy response:', out[:200])
time.sleep(3)
ms = [decode_mail(mail_text(p)) for p in mails()[since:]]
check('lazy scheduler (plain web request, no cron) sent the 1h reminder', len([m for m in ms if 'lazy@' in m['rcpt']]) == 1, [m['rcpt'] for m in ms])
print(sql("SELECT last_exit_code,times_executed,next_execution FROM joom_scheduler_tasks WHERE id=4"))
print(sql("SELECT exitcode,taskname,duration FROM joom_scheduler_logs ORDER BY id DESC LIMIT 2"))
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
