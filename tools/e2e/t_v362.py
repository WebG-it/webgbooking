"""0.36.2: the header of the Sheet is written WHEN THE SHEET IS SAVED in the component (Google page, "Save sheet"), not at the first booking.
Row 1 of the tab is read: empty -> header written; already our header -> nothing; a booking in row 1 (date-time in A1, id in O1) -> ONE row
inserted on top and the header written in it, the data below untouched; anything else (a header of the user) -> nothing, and the panel says so.
Google errors at saving: the sheet is saved anyway, a clear message, no Google text on screen. Saving twice never adds a second row.
Uses the stateful fake of Sheets (mock/mock.php). Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json
from wgbtest import *
from t_admin_lib import admin_login, post_pairs

reset_env()
adm = admin_login()
GPAGE = BASE + '/administrator/index.php?option=com_webgbooking&view=google'
SID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789'
BOOK_ROW = ['2026-10-01 10:00:00', '2026-11-09', '09:00', 'Mario', 'm@example.invalid', '', '', '', 'confirmed', '', '', 'meet', '60', 'Riunione - 1 ora', '77']
OLD_ROW = BOOK_ROW[:14]


def text(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


def grid(tab='Prenotazioni'):
    out = jx('cat /tmp/gmock/sheet.json 2>/dev/null') or '{}'
    try:
        return json.loads(out).get('tabs', {}).get(tab, [])
    except Exception:
        return []


def put_sheet(tab, rows):
    jx('mkdir -p /tmp/gmock; chmod 777 /tmp/gmock')
    st = {'tabs': {'Prenotazioni': [], 'Archivio': []}}
    st['tabs'][tab] = rows
    sh(['docker', 'exec', '-i', J, 'sh', '-c', 'cat > /tmp/gmock/sheet.json; chmod 666 /tmp/gmock/sheet.json'], inp=json.dumps(st).encode())


def fail(mode=None):
    jx('rm -f /tmp/gmock/sheet_fail' if mode is None else "printf %s '" + mode + "' > /tmp/gmock/sheet_fail; chmod 666 /tmp/gmock/sheet_fail")


def save(tab='Prenotazioni'):
    p = adm.get(GPAGE)
    tf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', p)[0]
    return post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=google.savesheet',
                      [('sheet_id_manual', 'https://docs.google.com/spreadsheets/d/%s/edit#gid=0' % SID), ('sheet_tab', tab), ('task', 'google.savesheet'), ('option', 'com_webgbooking'), (tf, '1')])


def fresh(tab='Prenotazioni', rows=None):
    reset_env()
    sql("UPDATE joom_webgbooking_google SET sheet_id='', sheet_tab=''")
    put_sheet(tab, rows or [])
    fail(None)


def header_ok(r):
    return len(r) == 15 and all(c for c in r)


def sheet_calls(method=None):
    return [l for l in gm_log() if 'sheets.googleapis' in l['host'] and (method is None or l['method'] == method)]


# ---------------------------------------------------------------- a. empty tab
fresh()
out = save()
t = text(out)
g = grid()
check('a1 an empty tab: saving the sheet writes the header in row 1 (15 labels), nothing else', len(g) == 1 and header_ok(g[0]), g)
check('a2 the panel says the header was written', ('Header written' in t or 'Intestazione scritta' in t) and 'already there' not in t, t[t.find('Google Sheet saved') - 50:t.find('Google Sheet saved') + 300])
check('a3 the sheet is saved in the database', sql('SELECT sheet_id FROM joom_webgbooking_google') == SID, '')
out = save()
t = text(out)
check('a4 saving AGAIN adds nothing (still one row), the panel says the header is already there', len(grid()) == 1 and ('already there' in t or 'c\'è già' in t), (len(grid()), t[-300:]))
r = book('hour', day(13), '10:00', email='a@example.invalid', name='Prima')
bid = last_id()
g = grid()
check('a5 the first booking after that goes under the header: no second header, id in column O', len(g) == 2 and header_ok(g[0]) and g[1][14] == str(bid) and g[1][8] == 'confirmed', g)

# ---------------------------------------------------------------- b. our header is already there (either language)
for lab, nm in (('Created', 'English'), ('Creata il', 'Italian')):
    row = [lab] + ['x'] * 14
    fresh(rows=[row, BOOK_ROW])
    save()
    check('b %s header already in row 1: nothing changes (rows identical), no write was sent' % nm, grid() == [row, BOOK_ROW] and not sheet_calls('PUT') and not [l for l in sheet_calls('POST')], grid())

# ---------------------------------------------------------------- c. a booking is in row 1: one row on top, the data untouched
for nm, data in (('new rows (with id)', [BOOK_ROW, BOOK_ROW[:14] + ['78']]), ('old rows (no id, 14 cells)', [OLD_ROW, OLD_ROW]), ('date as Sheets stores it (a serial number)', [['46300.5'] + BOOK_ROW[1:]])):
    fresh(rows=[list(r) for r in data])
    out = save()
    t = text(out)
    g = grid()
    check('c1 %s: ONE row inserted on top, header in it, every data row identical and in the same order below' % nm, len(g) == len(data) + 1 and header_ok(g[0]) and g[1:] == data, g)
    check('c2 %s: the panel says a row was added on top and the bookings were left as they were' % nm, ('row was added at the top' in t or 'riga in cima' in t), t[-300:])
    save()
    check('c3 %s: saving again adds no second row' % nm, len(grid()) == len(data) + 1 and grid()[1:] == data, len(grid()))
# the id search survives the shift: a real booking, header inserted above it, then its cancel
fresh()
sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
put_sheet('Prenotazioni', [['2026-10-01 10:00:00'] + ['v'] * 13])               # an old row (no id) in row 1: the 0.36.0 way
book('hour', day(13), '10:00', email='c@example.invalid', name='Cinque')
cid = last_id()
check('c4 (before) a booking sits under an old row, no header', len(grid()) == 2 and grid()[1][14] == str(cid), grid())
save()
g = grid()
check('c5 the header goes on top of both, the booking row is intact', len(g) == 3 and header_ok(g[0]) and g[2][14] == str(cid) and g[2][8] == 'confirmed', g)
tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % cid)
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel&token=' + tok, {'token': tok})
g = grid()
check('c6 cancelling it afterwards still finds its row by id after the shift', g[2][8] == 'cancelled' and g[2][14] == str(cid) and len(g) == 3, g)

# ---------------------------------------------------------------- d. a header of the user
mine = ['Nome', 'Cognome', 'Email']
fresh(rows=[mine, BOOK_ROW])
out = save()
t = text(out)
check('d1 a header written by the user in row 1: nothing is touched', grid() == [mine, BOOK_ROW] and not sheet_calls('PUT') and not sheet_calls('POST'), grid())
check('d2 the panel says the header was not written and why (warning), the sheet is saved', ('Header not written' in t or 'Intestazione non scritta' in t) and sql('SELECT sheet_id FROM joom_webgbooking_google') == SID, t[-300:])
fresh(rows=[['Nome'], ['qualcosa']])
save()
check('d3 a text that is not a date in A1, no id in O1: left alone', grid() == [['Nome'], ['qualcosa']], grid())

# ---------------------------------------------------------------- e. other tab / row 1 empty with data below (a crash between the insert and the header)
fresh(tab='Archivio')
save('Archivio')
check('e1 the header goes in the tab that was chosen, not in the other one', len(grid('Archivio')) == 1 and header_ok(grid('Archivio')[0]) and grid('Prenotazioni') == [], (grid('Archivio'), grid('Prenotazioni')))
fresh(rows=[[], BOOK_ROW])
save()
g = grid()
check('e2 an empty row 1 with data below (an interrupted insert): the header fills row 1, nothing else moves', len(g) == 2 and header_ok(g[0]) and g[1] == BOOK_ROW, g)

# ---------------------------------------------------------------- f. Google errors at saving
for mode, label in (('disabled', '403 API not enabled'), ('down', '503'), ('denied', '403 denied')):
    fresh(rows=[BOOK_ROW])
    fail(mode)
    out = save()
    t = text(out)
    check('f %s: the sheet is saved all the same' % label, sql('SELECT sheet_id FROM joom_webgbooking_google') == SID, '')
    check('f %s: a clear message says the header could not be written now (try again), the data is untouched' % label, ('header could not be checked' in t or 'non è stato possibile controllare' in t) and grid() == [BOOK_ROW], t[-300:])
    check('f %s: no Google text on screen (no project number, console link, reason code, status text)' % label, not re.search(r'100455972354|console\.developers|SERVICE_DISABLED|PERMISSION_DENIED|UNAVAILABLE|has not been used|does not have permission', out), '')
    fail(None)
    save()
    check('f %s: saving again once Google answers writes the header (one row on top)' % label, len(grid()) == 2 and header_ok(grid()[0]) and grid()[1] == BOOK_ROW, grid())

# ---------------------------------------------------------------- g. nothing new in permissions, and the sheet turned off writes nothing
src = jx('cat /var/www/html/administrator/components/com_webgbooking/src/Model/GoogleModel.php')
scopes = re.search(r"'scope'\s*=>\s*'([^']+)'", src)
check('g1 the scope list is exactly the 0.36.1 one (no new scope, no Drive)', scopes and scopes.group(1) == 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/spreadsheets', scopes and scopes.group(1))
fresh()
jx('rm -f /tmp/gmock/log.jsonl')
p = adm.get(GPAGE)
tf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', p)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=google.savesheet', [('sheet_id_manual', ''), ('sheet_tab', ''), ('task', 'google.savesheet'), ('option', 'com_webgbooking'), (tf, '1')])
check('g2 turning the copy off (empty field) writes nothing and reads nothing on Sheets', not [l for l in sheet_calls() if l['method'] != 'GET' or 'values' in l['path']] and grid() == [], [(l['method'], l['path']) for l in sheet_calls()])
check('g3 no call to the Drive API', not [l for l in gm_log() if '/drive/' in l['path']], '')

fail(None)
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
