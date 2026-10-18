"""Auto-update as Joomla really does it: the update found through the PUBLISHED updates-pkg.xml must be tied to the
installed package (extension_id, client_id), listed in Extensions > Update and applicable from there.
The older kit only looked rows up by element name, which hid a missing <client> for three releases.
Install an OLDER release of the package first, then run this (it uses the live update server)."""
import sys, os, re, json, base64, urllib.parse
from wgbtest import *
from t_admin_lib import admin_login, post_pairs

pkg = sql("SELECT extension_id, JSON_UNQUOTE(JSON_EXTRACT(manifest_cache,'$.version')), client_id FROM joom_extensions WHERE element='pkg_webgbooking' AND type='package'").split('\t')
eid, installed, pcid = int(pkg[0]), pkg[1], int(pkg[2])
print('installed', installed, 'package client_id', pcid)
sql("DELETE FROM joom_updates WHERE element='pkg_webgbooking'")
sql("UPDATE joom_update_sites SET last_check_timestamp=0 WHERE location LIKE '%webgbooking%'")
jx('cd /var/www/html && php cli/joomla.php update:extensions:check 2>&1 | tail -2')
row = sql("SELECT update_id, extension_id, client_id, version FROM joom_updates WHERE element='pkg_webgbooking'").split('\t')
check('U1 the update server offers a newer version', len(row) == 4 and row[3] != installed, row)
if len(row) == 4:
    check('U2 the update row is tied to the INSTALLED package (extension_id)', int(row[1]) == eid, row)
    check('U3 the update row has the package client (0 = site), not administrator (1)', int(row[2]) == pcid, row)
adm = admin_login()
page = adm.get(BASE + '/administrator/index.php?option=com_installer&view=update')
check('U4 Extensions > Update lists WebG Booking with the new version', 'WebG Booking' in page and len(row) == 4 and row[3] in page, '')
if len(row) == 4 and int(row[1]) == eid and 'WebG Booking' in page:
    tok = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', page)[0]
    out = post_pairs(adm, BASE + '/administrator/index.php?option=com_installer&view=update', [('task', 'update.update'), ('boxchecked', '1'), ('cid[]', row[0]), (tok, '1'), ('option', 'com_installer')])
    now = sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(manifest_cache,'$.version')) FROM joom_extensions WHERE element='pkg_webgbooking'")
    check('U5 applying it from Extensions > Update installs the new version (download + sha256 checked by Joomla)', now == row[3], (now, row[3], re.sub('<[^>]+>', ' ', out)[-200:]))
    children = sql("SELECT element, JSON_UNQUOTE(JSON_EXTRACT(manifest_cache,'$.version')) FROM joom_extensions WHERE element IN ('com_webgbooking','webgbooking','webgbookingreminders')").split('\n')
    check('U6 the children moved to the same version', all(c.split('\t')[1] == row[3] for c in children), children)
print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
