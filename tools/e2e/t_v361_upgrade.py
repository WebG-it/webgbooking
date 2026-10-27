"""0.36.1, the update from 0.36.0 loses nothing.
Installs the published 0.36.0 package (dist/pkg_webgbooking-0.36.0.zip), sets a Sheet and makes bookings the OLD way (14 cells, no header, no id),
takes a snapshot of options, Google row, bookings and the Sheet, installs 0.36.1 over it and checks:
  - options of the component and of the plugin, the Google connection and the bookings are identical (nothing lost, nothing added to the schema);
  - the old rows in the Sheet are exactly as they were; cancelling or deleting an OLD booking (no id) changes nothing in the Sheet and records no error;
  - a new booking is appended after them with its id in column O and NO header (row 1 already had content).
Run it on a container with the mock Google (up.sh) and WGB_E2E_ADMIN_PASS set:  python t_v361_upgrade.py <port> <prefix>"""
import sys, os, re, json
from wgbtest import *

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
RES_ = RES


def install(zipname):
    sh(['docker', 'cp', os.path.join(ROOT, 'dist', zipname), J + ':/tmp/pkg.zip'])
    return jx('cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/pkg.zip 2>&1 | tail -3')


def snap():
    return {
        'comp': sql("SELECT params FROM joom_extensions WHERE element='com_webgbooking' AND type='component'"),
        'plug': sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system' AND type='plugin'"),
        'google': sql('SELECT * FROM joom_webgbooking_google'),
        'bookings': sql('SELECT * FROM joom_webgbooking_bookings ORDER BY id'),
        'kv': sql("SELECT k FROM joom_webgbooking_kv WHERE k NOT LIKE 'alert:%' AND k NOT LIKE 'busy:%' ORDER BY k"),
        'cols': sql("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='joomla' AND TABLE_NAME IN ('joom_webgbooking_bookings','joom_webgbooking_google','joom_webgbooking_kv')"),
    }


def grid(tab='Prenotazioni'):
    out = jx('cat /tmp/gmock/sheet.json 2>/dev/null') or '{}'
    try:
        return json.loads(out).get('tabs', {}).get(tab, [])
    except Exception:
        return []


out = install('pkg_webgbooking-0.36.0.zip')
check('U0 the published 0.36.0 package installs', 'successfully' in out, out)
sh(['docker', 'cp', os.path.join(HERE, 'mock') + '/.', J + ':/tmp/mock/'])
reset_env()
check('U1 the installed version is 0.36.0', sql("SELECT JSON_VALUE(manifest_cache,'$.version') FROM joom_extensions WHERE element='pkg_webgbooking'") == '0.36.0', sql("SELECT manifest_cache FROM joom_extensions WHERE element='pkg_webgbooking'")[:80])
sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
ids = []
for h, nm in (('09:00', 'Vecchia1'), ('11:00', 'Vecchia2')):
    r = book('hour', day(13), h, email='%s@example.invalid' % nm.lower(), name=nm)
    ids.append(last_id())
old = grid()
check('U2 the 0.36.0 code wrote its two rows the old way: no header, 14 cells, no id', len(old) == 2 and all(len(r) == 14 for r in old) and old[0][3] == 'Vecchia1', old)

before = snap()
out = install('pkg_webgbooking-0.36.1.zip')
check('U3 0.36.1 installs over it', 'successfully' in out, out)
sh(['docker', 'cp', os.path.join(HERE, 'mock') + '/.', J + ':/tmp/mock/'])
check('U4 the installed version is 0.36.1', sql("SELECT JSON_VALUE(manifest_cache,'$.version') FROM joom_extensions WHERE element='pkg_webgbooking'") == '0.36.1', '')
after = snap()
for k in ('comp', 'plug', 'google', 'bookings', 'cols'):
    check('U5 %s identical before and after the update (nothing lost, no schema change)' % k, before[k] == after[k], (before[k][:120], after[k][:120]))
check('U6 the kv store only lost nothing (keys before are all still there)', set(before['kv'].split()) <= set(after['kv'].split()), '')
check('U7 the Sheet is exactly as it was', grid() == old, grid())

# old booking (no id): cancel and delete leave the Sheet alone and record no error
tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % ids[0])
jx('rm -f /tmp/gmock/log.jsonl')
page = Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel&token=' + tok, {'token': tok})
steps = sql('SELECT fx_steps FROM joom_webgbooking_bookings WHERE id=%d' % ids[0])
check('U8 an old booking (row without id) is cancelled by the customer as always, the Sheet is not changed, no retry marked, no error', sql('SELECT status FROM joom_webgbooking_bookings WHERE id=%d' % ids[0]) == 'cancelled' and grid() == old and 'sheet_sync' not in steps and sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k='sheet_err'") == '0', (steps, grid()))
check('U9 ... and no write at all went to Sheets', not [l for l in gm_log() if 'sheets.googleapis' in l['host'] and l['method'] in ('PUT', 'POST')], '')

# a new booking: appended after the old rows, with the id, no header
r = book('hour', day(13), '14:00', email='nuova@example.invalid', name='Nuova')
nid = last_id()
g = grid()
check('U10 a booking after the update is appended under the old rows, 15 cells, id in column O, still NO header (row 1 already had content)', len(g) == 3 and g[:2] == old and len(g[2]) == 15 and g[2][14] == str(nid) and g[2][3] == 'Nuova', g)
# and it follows its changes
tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % nid)
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel&token=' + tok, {'token': tok})
g = grid()
check('U11 the new booking follows its cancellation in the Sheet, the old rows are untouched', g[:2] == old and g[2][8] == 'cancelled', g)
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
