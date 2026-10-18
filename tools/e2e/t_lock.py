import hashlib
from wgbtest import *
clear_state()
D = day(20)
name = 'wgb:' + hashlib.md5(('joomla|joom_|' + D).encode()).hexdigest()[:24]
holder = subprocess.Popen(['docker', 'exec', DB, 'mariadb', '-ujoomla', '-pjoomlapw', 'joomla', '-e', "SELECT GET_LOCK('%s',60); SELECT SLEEP(14);" % name], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
time.sleep(2)
t0 = time.time()
r = book('half', D, '10:00', phone='0612345678', email='lock@example.invalid')
dt = time.time() - t0
check('while another session holds the day lock the booking waits (~8 s) and is refused, nothing stored', r.get('ok') is False and 7 <= dt <= 12 and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE booking_date='%s'" % D) == '0', (r, round(dt, 1)))
holder.communicate()
r = book('half', D, '10:00', phone='0612345678', email='lock2@example.invalid')
check('after the lock is released the same slot books fine', r.get('ok') is True, r)
# a different day is not blocked by that lock
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
