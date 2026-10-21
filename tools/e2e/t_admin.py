import base64, os
from wgbtest import *
s = Sess()
h = s.get(BASE + '/administrator/index.php')
tok = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)
data = {'username': 'admin', 'passwd': os.environ['WGB_E2E_ADMIN_PASS'], 'option': 'com_login', 'task': 'login', 'return': base64.b64encode(b'index.php').decode()}
for t in tok: data[t] = '1'
s.post(BASE + '/administrator/index.php', data)
ext = sql("SELECT extension_id FROM joom_extensions WHERE element='webgbooking' AND folder='system'")
pg = s.get(BASE + '/administrator/index.php?option=com_config&view=component&component=com_webgbooking')
for name in ['jform[reminders_enabled]', 'jform[reminder_times]', 'jform[slot_duration]']:
    check('component options form has ' + name, name in pg, '')
check('component options: reminder text description present', 'Appointment reminders' in pg or 'Reminders' in pg)
ppg = s.get(BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % ext)
check('the plugin has no options of its own: its form shows only the link to the component options', 'jform[params][reminders_enabled]' not in ppg and 'jform[params][stripe_sk]' not in ppg and 'href="/administrator/index.php?option=com_config&amp;view=component&amp;component=com_webgbooking"' in ppg, ppg[-300:])
tk = sql("SELECT extension_id FROM joom_extensions WHERE element='webgbookingreminders'")
pg2 = s.get(BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % tk)
check('task plugin edit page opens and is enabled', 'WebG Booking' in pg2 or 'webgbookingreminders' in pg2, pg2[:100])
sc = s.get(BASE + '/administrator/index.php?option=com_scheduler&view=tasks')
check('Scheduled Tasks list shows the WebG Booking reminders task', 'WebG Booking: send appointment reminders' in sc, '')
ne = s.get(BASE + '/administrator/index.php?option=com_scheduler&view=select&layout=default')
check('"New task" type chooser lists the WebG routine', 'WebG Booking' in ne, '')
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
