"""0.36.0, a NEW install (not an update): the package is installed on a Joomla that has never had it, with no option set by the test kit.
The Options of the component start with the ready-made values (a component does not store them by itself, so the package install does),
the plugin starts empty, the Options screen opens, a booking works with the ready-made values, and saving the Options keeps them.
Run it on a container where the package was just installed and init.php was NOT run:  up.sh, then
  docker cp dist/pkg_webgbooking-<ver>.zip <container>:/tmp/new.zip; docker exec -w /var/www/html <container> php cli/joomla.php extension:install --path=/tmp/new.zip
  python t_v360_fresh.py <port> <prefix>      (needs WGB_E2E_ADMIN_PASS)"""
import sys, os, re, json
from wgbtest import *
from t_admin_lib import *

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
COMP = "element='com_webgbooking' AND type='component'"
PLUG = "element='webgbooking' AND folder='system' AND type='plugin'"


def rsql(qq):
    return sh(['docker', 'exec', DB, 'mariadb', '-ujoomla', '-pjoomlapw', 'joomla', '-N', '-r', '-B', '-e', qq]).strip()


cfg_xml = open(os.path.join(ROOT, 'src', 'com_webgbooking', 'admin', 'config.xml'), encoding='utf-8').read()
defaults = {}
for tag in re.findall(r'<field\b[^>]*>', cfg_xml):
    n, d, t = re.search(r'\bname="([^"]+)"', tag), re.search(r'\bdefault="([^"]*)"', tag), re.search(r'\btype="([^"]+)"', tag)
    if n and d and (not t or t.group(1) not in ('note', 'rules', 'spacer')):
        defaults[n.group(1)] = d.group(1)
comp = json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % COMP) or '{}')
plug = json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % PLUG) or '{}')
check('N1 a new install stores the ready-made value of every option in the Options of the component (%d)' % len(defaults), all(comp.get(k) == v for k, v in defaults.items()), {k: comp.get(k) for k, v in defaults.items() if comp.get(k) != v})
check('N1b ... nothing secret, nothing invented: the secrets and the headers are empty or absent', all(comp.get(k, '') == '' for k in ('stripe_sk', 'stripe_whsec', 'brevo_key', 'whp1_headers', 'google_client_secret')), '')
check('N2 the plugin starts without options of its own', not plug, plug)

adm, edit_url, save_options, opts = make_admin()
pg = adm.get(edit_url)
fp = FormParser()
fp.feed(pg)
form = dict(fp.pairs)
check('N3 the Options screen opens on a new site and shows the ready-made values (hours, slot every 30 minutes, test mode, the address of the booking page)', form.get('jform[work_start]') == '09:00' and form.get('jform[work_end]') == '18:00' and form.get('jform[slot_interval]') == '30' and form.get('jform[stripe_mode]') == 'test' and form.get('jform[direct_path]') == 'prenota', {k: form.get('jform[%s]' % k) for k in ('work_start', 'slot_interval', 'stripe_mode', 'direct_path')})
check('N3b ... and the permissions tab', 'jform[rules][core.admin]' in pg, '')
before = json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % COMP))
out = save_options({'jform[notify_email]': 'nuovo@wgbtest.invalid'})
after = json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % COMP))
check('N4 saving the Options on a new site stores the change and keeps every other value (an empty colour picker saves "none", which reads as no colour, as it always did)', after.get('notify_email') == 'nuovo@wgbtest.invalid' and all(str(after.get(k)) == str(v) for k, v in before.items() if k not in ('notify_email', 'config_epoch', 'config_bump', 'work_days') and not k.endswith('_color')) and sorted(after.get('work_days') or []) == ['1', '2', '3', '4', '5'], {k: (v, after.get(k)) for k, v in before.items() if str(after.get(k)) != str(v) and not k.endswith('_color')})

# a booking with the ready-made values: working days Monday to Friday, 09:00 to 18:00, a slot every 30 minutes, 2 hours of notice
jx(RENDER)
d = day(1)
import datetime
while datetime.date.fromisoformat(d).weekday() >= 5:
    d = (datetime.date.fromisoformat(d) + datetime.timedelta(days=1)).isoformat()
d2 = (datetime.date.fromisoformat(d) + datetime.timedelta(days=1)).isoformat()
while datetime.date.fromisoformat(d2).weekday() >= 5:
    d2 = (datetime.date.fromisoformat(d2) + datetime.timedelta(days=1)).isoformat()
cfg = page_cfg('def')
days = month(cfg['ev'], d, d)
check('N5 the offered times follow the ready-made hours (09:00 to 18:00, every 30 minutes) on a working day', days.get(d, [])[:1] == ['09:00'] and '17:30' in days.get(d, []) and '18:00' not in days.get(d, []), days.get(d, [])[:3])
sat = d
while datetime.date.fromisoformat(sat).weekday() != 5:
    sat = (datetime.date.fromisoformat(sat) + datetime.timedelta(days=1)).isoformat()
check('N5b ... and nothing on a Saturday', not month(cfg['ev'], sat, sat).get(sat, []), month(cfg['ev'], sat, sat).get(sat, []))
sql('DELETE FROM joom_webgbooking_bookings')      # (an uninstall keeps the tables: a run on a container used before must not meet an old booking)
jx('rm -f /tmp/mail/*.eml')
r = book('def', d, '10:00', email='nuovo@example.invalid', name='Nuovo Sito')
check('N6 a booking on a new site works (confirmed, an email to the customer)', r.get('ok') is True and any('nuovo@example.invalid' in mail_text(p)[:400] for p in mails()), r)
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
