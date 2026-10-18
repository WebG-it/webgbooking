"""Component > Google page: honest states (not connected / connection expired / connected), ONE primary action in each,
optional Sheets section closed by default, no Drive scope or Drive API call, no raw Google messages on screen.
Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, urllib.parse, html as htmlmod
from wgbtest import *
from t_admin_lib import make_admin

reset_env()
adm, EDIT, save_options, opts = make_admin()
GPAGE = BASE + '/administrator/index.php?option=com_webgbooking&view=google'
LOG = '/var/www/html/administrator/logs/webgbooking.php'


def page():
    return adm.get(GPAGE)


def text(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


def flag(v=None):
    jx('rm -f /tmp/gmock/google_fail' if v is None else "printf %s > /tmp/gmock/google_fail; chmod 666 /tmp/gmock/google_fail" % v)


def connect_href(h):
    m = re.search(r'href="(https://accounts\.google\.com/o/oauth2/v2/auth\?[^"]+)"', h)
    return htmlmod.unescape(m.group(1)) if m else ''


# ------------------------------------------------------------------ not connected
sql("DELETE FROM joom_webgbooking_google")
jx('php /tmp/mock/init.php >/dev/null'); sql("DELETE FROM joom_webgbooking_google")
p = page()
href = connect_href(p)
check('G1a not connected: ONE prominent primary button "Connect Google" leading to the Google consent screen', 'btn-primary btn-lg' in p and href != '' and p.count('btn-primary') == 1, (p.count('btn-primary'), href[:60]))
check('G1b one-line explanation, no Sheets section, no "connected" green box', 'alert-success' not in p and 'wgb_sheet' not in p and '<details' not in p, '')
q = urllib.parse.parse_qs(urllib.parse.urlparse(href).query)
scope = q.get('scope', [''])[0]
check('G1c the consent asks for calendar + sheets + identity only: NO Drive scope, no include_granted_scopes', 'calendar.events' in scope and 'spreadsheets' in scope and 'drive' not in scope and 'include_granted_scopes' not in q, scope)

# ------------------------------------------------------------------ connected but token revoked
jx('php /tmp/mock/init.php >/dev/null')
# a log file left by a CLI run (root) would not be writable by the web user: start from none, as on a clean site
jx('rm -f %s' % LOG)
flag('token')
jx('rm -f /tmp/gmock/log.jsonl')
p = page()
t = text(p)
href2 = connect_href(p)
check('G2a revoked token: the page does NOT say "connected" in green; it says the connection expired', 'alert-success' not in p and ('Connection expired' in t or 'Collegamento scaduto' in t), t[:300])
check('G2b ONE primary button "Reconnect Google" that goes straight to the Google consent screen (no Disconnect step)', p.count('btn-primary') == 1 and ('Reconnect Google' in t or 'Ricollega Google' in t) and href2 != '', (p.count('btn-primary'), href2[:50]))
check('G2c no Sheets section and no calendar table while the connection is expired', 'wgb_sheet' not in p and '<details' not in p and 'read[]' not in p, '')
check('G2d no raw Google text on screen (invalid_grant / description)', 'invalid_grant' not in p and 'expired or revoked' not in p, '')
log = jx('cat %s 2>/dev/null' % LOG)
check('G2e the detail went to the log, without secrets', 'invalid_grant' in log and 'mock-revoked' not in log and 'mock-refresh' not in log, log[-200:])

# ------------------------------------------------------------------ the reconnect really works (consent -> callback)
state = urllib.parse.parse_qs(urllib.parse.urlparse(href2).query).get('state', [''])[0]
flag(None)
cb = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=raw&action=oauth&code=goodcode&state=' + state
import http.client
u = urllib.parse.urlparse(cb)
c = http.client.HTTPConnection(u.hostname, u.port, timeout=60); c.request('GET', u.path + '?' + u.query); r = c.getresponse(); r.read()
check('G3a Google comes back to the site: the new token is stored and the admin is sent to the page (wgbconnected)', r.status in (301, 302, 303) and 'wgbconnected=1' in (r.getheader('Location') or ''), (r.status, r.getheader('Location')))
p = page(); t = text(p)
check('G3b now the page is green "Connected as ..." with the calendars, and no reconnect button', 'alert-success' in p and 'reconnected@wgbtest.invalid' in t and 'Altro' in t and href2 not in p, t[:300])
month = json.loads(Sess().get(AJAX + '&action=month&from=%s&to=%s&ev=%s' % (day(3), day(3), urllib.parse.quote(page_cfg('def')['ev'], safe=''))))
check('G3c bookings work again after the reconnect (slots offered)', month.get('days') and not month.get('unavailable'), month)

# ------------------------------------------------------------------ connected: Sheets is optional, closed, no Drive
jx('rm -f /tmp/gmock/log.jsonl')
p = page(); t = text(p)
check('G4a the Sheets section is inside a closed <details> titled "Optional: ..."', re.search(r'<details(?![^>]*\bopen\b)[^>]*>\s*<summary[^>]*>[^<]*(Optional|Opzionale)', p) is not None, '')
check('G4b it says plainly that nothing is written to Sheets unless filled in', 'wgb_sheet_manual' in p and ('nothing is written' in t.lower() or 'nessun dato viene scritto' in t.lower()), t[t.find('Optional') - 50:t.find('Optional') + 400] if 'Optional' in t else t[:200])
check('G4c NO Drive picker, no Drive call while rendering the page', 'wgb_sheet_id' not in p and not [l for l in gm_log() if '/drive/' in l['path']], [l['path'] for l in gm_log()][:5])
check('G4d no "could not list your sheets" box and no Drive API text', 'Drive API' not in p and "couldn't list" not in t.lower() and 'Non siamo riusciti' not in t, '')
check('G4e the redirect URI stays at the bottom as a technical note', p.rfind('action=oauth') > p.rfind('<details'), '')
tokf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', p)
form = {'sheet_id_manual': 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=0', 'sheet_tab': 'Prenotazioni', 'task': 'google.savesheet', 'option': 'com_webgbooking'}
for k in tokf[-1:]:
    form[k] = '1'
from t_admin_lib import post_pairs
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=google.savesheet', list(form.items()))
row = (sql("SELECT sheet_id, sheet_tab FROM joom_webgbooking_google").split('\t') + ['', ''])[:2]
check('G4f a pasted Sheets URL is reduced to the ID and saved with the tab', row[0] == '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789' and row[1] == 'Prenotazioni', row)
p = page()
check('G4g the tab names of the sheet are offered (read with the Sheets scope only)', 'Archivio' in p, '')
form['sheet_id_manual'] = ''
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=google.savesheet', list(form.items()))
check('G4h emptying the field switches the copy off', sql("SELECT sheet_id FROM joom_webgbooking_google") in ('', 'NULL'), '')

# ------------------------------------------------------------------ no raw Google messages when the calendar list fails
flag('calendarlist'); jx('rm -f %s' % LOG)
p = page(); t = text(p)
log = jx('cat %s 2>/dev/null' % LOG)
check('G5a calendar list failing: one clear line, NOT the raw Google message', 'Calendar API has not been used' not in p and 'project 100455972354' not in p, '')
check('G5b ...and the detail is in the log', 'Calendar' in log, log[-200:])
flag(None)

# ------------------------------------------------------------------ a token granted long ago (with the Drive scope) keeps working with no reconnection
jx('php /tmp/mock/init.php >/dev/null')
r = book('def', day(4), '10:00', email='drive-old@example.invalid')
check('G6 an existing connection keeps working untouched (booking creates the Google event, page stays green)', r.get('ok') is True and len(gm_events()) == 1 and 'alert-success' in page(), r)

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
