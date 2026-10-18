from wgbtest import *
clear_state()
def rome(plus_min=0):
    out = jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"%+d minutes\")->format(\"Y-m-d H:i\");'" % plus_min)
    return out.strip().split(' ')
r = book('def', day(9), '09:00', email='lang@example.invalid', name='Lingua')
bid = last_id()
d, t = rome(50)
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[1440]' WHERE id=%d" % (d, t, bid))
print(sql("SELECT params FROM joom_extensions WHERE element='com_languages'"))
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.site','it-IT') WHERE element='com_languages'")
since = len(mails())
print(jx('php /tmp/mock/runrem.php'))
ms = [decode_mail(mail_text(p)) for p in mails()[since:]]
print([ (m['subject']) for m in ms])
check('reminder follows the SITE language (it-IT) even when the scheduler runs in a CLI/en-GB context', ms and ms[0]['subject'].startswith('Promemoria: appuntamento il'), [m['subject'] for m in ms])
check('body is Italian', ms and 'ti ricordiamo il tuo appuntamento' in ms[0]['html'].lower(), ms and ms[0]['html'][:200])
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.site','en-GB') WHERE element='com_languages'")
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
