"""0.36.1: the copy of the bookings in a Google Sheet follows the booking.
Header on an empty tab, booking id in column O, the row is rewritten when the booking changes (cancelled by the customer or from the
panel, confirmed, completed, no-show, moved), removed when the booking is deleted; a row that is not found is left alone; Google
errors (API not enabled, 503) never block the change and are retried; the Google page says it clearly without Google's own text;
no new scope. Needs WGB_E2E_ADMIN_PASS. Uses a stateful fake of Sheets (mock/mock.php, /tmp/gmock/sheet.json)."""
import sys, os, re, json, time
from wgbtest import *
from t_admin_lib import admin_login, post_pairs

reset_env()
adm = admin_login()
GPAGE = BASE + '/administrator/index.php?option=com_webgbooking&view=google'
LIST = BASE + '/administrator/index.php?option=com_webgbooking&view=bookings'
D = 13


def text(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


def sheet_cfg(tab='Prenotazioni', sid='SHEET123'):
    sql("UPDATE joom_webgbooking_google SET sheet_id='%s', sheet_tab='%s'" % (sid, tab))


def grid(tab='Prenotazioni'):
    out = jx('cat /tmp/gmock/sheet.json 2>/dev/null') or '{}'
    try:
        return json.loads(out).get('tabs', {}).get(tab, [])
    except Exception:
        return []


def put_sheet(tab, rows):
    """Preload a tab of the fake Sheet (before any booking)."""
    jx('mkdir -p /tmp/gmock; chmod 777 /tmp/gmock')
    st = {'tabs': {'Prenotazioni': [], 'Archivio': []}}
    st['tabs'][tab] = rows
    sh(['docker', 'exec', '-i', J, 'sh', '-c', 'cat > /tmp/gmock/sheet.json; chmod 666 /tmp/gmock/sheet.json'], inp=json.dumps(st).encode())


def fail(mode=None):
    jx('rm -f /tmp/gmock/sheet_fail' if mode is None else "printf %s '" + mode + "' > /tmp/gmock/sheet_fail; chmod 666 /tmp/gmock/sheet_fail")


def reqs(method=None):
    return [l for l in gm_log() if 'sheets.googleapis' in l['host'] and (method is None or l['method'] == method)]


def idrow(bid, tab='Prenotazioni'):
    for i, r in enumerate(grid(tab)):
        if len(r) >= 15 and r[14] == str(bid):
            return i, r
    return None, None


def steps(bid):
    return json.loads(sql('SELECT fx_steps FROM joom_webgbooking_bookings WHERE id=%d' % bid) or '{}')


def kv(k):
    return sql("SELECT v FROM joom_webgbooking_kv WHERE k='%s'" % k)


def run_task():
    sql("UPDATE joom_scheduler_tasks SET locked=NULL, next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE type LIKE '%webg%'")
    jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')


def age_sheet_marks():
    sql("UPDATE joom_webgbooking_bookings SET fx_steps=JSON_SET(fx_steps,'$.sheet_at',1) WHERE fx_steps LIKE '%sheet_sync%'")
    sql("UPDATE joom_webgbooking_kv SET v=JSON_SET(v,'$.at',1) WHERE k LIKE 'sq:%'")


def token_of(i):
    return sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % i)


def cust_cancel(i):
    tok = token_of(i)
    return Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel&token=' + tok, {'token': tok})


def tokf():
    return re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', adm.get(LIST))[0]


def admin(task, ids, view='bookings'):
    tf = tokf()
    pairs = [('task', 'booking.' + task), ('boxchecked', str(len(ids))), ('option', 'com_webgbooking'), (tf, '1')] + [('cid[]', str(i)) for i in ids]
    return post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=' + view, pairs)


def status(i):
    return sql('SELECT status FROM joom_webgbooking_bookings WHERE id=%d' % i)


# ---------------------------------------------------------------- A. header on an empty tab, id in column O
sheet_cfg()
r = book('hour', day(D), '10:00', phone='+39 333 000 1111', location='phone', email='a1@example.invalid', name='Uno')
a1 = last_id()
g = grid()
check('A1 booking made and the empty tab got a header row first, then the booking (2 rows)', r.get('ok') is True and len(g) == 2, (r, g))
check('A2 header has 15 labels, in the language of the plugin, the last one is the id', len(g[0]) == 15 and g[0][14] and g[0][0] and all(c for c in g[0]), g[:1])
check('A3 the data row keeps A..N as before and carries the booking id in column O', len(g[1]) == 15 and g[1][8] == 'confirmed' and g[1][14] == str(a1) and g[1][4] == 'a1@example.invalid' and g[1][11:14] == ['phone', '60', 'Riunione - 1 ora'], g[1:])
r = book('hour', day(D), '12:00', email='a2@example.invalid', name='Due')
a2 = last_id()
g = grid()
check('A4 a second booking: no second header, one more row at the bottom', len(g) == 3 and g[2][14] == str(a2) and g[0][14] != str(a2), g)
check('A5 header labels are read from the .ini (it-IT and en-GB both define all 15)', all(len(re.findall(r'^PLG_SYSTEM_WEBGBOOKING_SHEET_H_[A-Z]+=', open_ini, re.M)) == 15 for open_ini in [jx('cat /var/www/html/plugins/system/webgbooking/language/%s/plg_system_webgbooking.ini' % l) for l in ('it-IT', 'en-GB')]), '')

# ---------------------------------------------------------------- B. a tab that already has something in row 1: no header, nothing overwritten
reset_env()
sheet_cfg('Archivio')
put_sheet('Archivio', [['Mio titolo', 'altro'], ['dato vecchio', '1', '2']])
r = book('hour', day(D), '10:00', email='b1@example.invalid', name='Tre')
b1 = last_id()
g = grid('Archivio')
check('B1 row 1 already had content: no header written, the old rows are untouched', g[0] == ['Mio titolo', 'altro'] and g[1] == ['dato vecchio', '1', '2'], g[:2])
check('B2 the booking is appended after them with its id', len(g) == 3 and g[2][14] == str(b1), g)

# ---------------------------------------------------------------- C. every change rewrites the same row (cancel by customer, admin cancel single/bulk/detail, confirm, complete, no-show, move)
reset_env()
sheet_cfg()
ids = []
for h, nm in (('09:00', 'C1'), ('11:00', 'C2'), ('13:00', 'C3'), ('15:00', 'C4'), ('17:00', 'C5')):
    book('hour', day(D), h, email='%s@example.invalid' % nm.lower(), name=nm)
    ids.append(last_id())
n0 = len(grid())
check('C0 five bookings, header + five rows', n0 == 6, grid())
cust_cancel(ids[0])
i0, row0 = idrow(ids[0])
check('C1 the customer cancels from the email page: Joomla says cancelled and the row (same place) says cancelled', status(ids[0]) == 'cancelled' and row0 and row0[8] == 'cancelled' and len(grid()) == n0, row0)
admin('cancel', [ids[1], ids[2]])
check('C2 the panel cancels two at once: both rows say cancelled, other rows untouched', all(idrow(i)[1][8] == 'cancelled' for i in ids[1:3]) and idrow(ids[3])[1][8] == 'confirmed' and len(grid()) == n0, [idrow(i)[1][8] for i in ids])
jx('rm -f /tmp/gmock/log.jsonl')
tf = tokf()
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % ids[3], [('task', 'booking.cancel'), ('id', str(ids[3])), ('boxchecked', '0'), ('option', 'com_webgbooking'), (tf, '1'), ('cid[]', str(ids[3]))])
check('C3 cancelling from the detail page rewrites the row too', idrow(ids[3])[1][8] == 'cancelled', idrow(ids[3]))
admin('complete', [ids[4]])
check('C4 completed -> row says completed', idrow(ids[4])[1][8] == 'completed', idrow(ids[4]))
admin('noshow', [ids[4]])
check('C5 no-show -> row says no-show', idrow(ids[4])[1][8] == 'no-show', idrow(ids[4]))
admin('confirm', [ids[4]])
check('C6 confirm -> row says confirmed', idrow(ids[4])[1][8] == 'confirmed', idrow(ids[4]))
# manual confirmation flow: pending then confirm and notify
book('manual30', day(D + 1), '10:00', email='man@example.invalid', name='Manuale')
mid = last_id()
check('C7 a booking waiting for confirmation is in the sheet as pending', idrow(mid)[1] and idrow(mid)[1][8] == 'pending', idrow(mid))
admin('confirmnotify', [mid], 'booking')
check('C8 "Confirm and tell the customer" -> the row says confirmed', idrow(mid)[1][8] == 'confirmed', idrow(mid))
# move (reschedule by the customer)
book('hour', day(D + 3), '09:00', email='mv@example.invalid', name='Sposta')
mv = last_id()
time.sleep(4)
rs = Sess()
tok = token_of(mv)
x = json.loads(rs.post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': day(D + 2), 'time': '15:00'}))
rw = idrow(mv)[1]
check('C9 reschedule: the row has the new date and time, same row, same id', x.get('ok') is True and rw[1] == day(D + 2) and rw[2] == '15:00' and len(grid()) == n0 + 2, (x, rw))

# ---------------------------------------------------------------- D. delete from the panel removes the row (and only that row)
before = [r[14] for r in grid()[1:]]
admin('delete', [ids[0], ids[2]])
after = [r[14] for r in grid()[1:]]
check('D1 deleting two bookings removes exactly their two rows, the header and the other rows stay in order', after == [i for i in before if i not in (str(ids[0]), str(ids[2]))] and grid()[0][14] and sql('SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE id IN (%d,%d)' % (ids[0], ids[2])) == '0', (before, after))
admin('delete', [ids[1]])
check('D2 deleting a cancelled booking removes its row too (the cancelled row was kept until then)', str(ids[1]) not in [r[14] for r in grid()[1:]], grid())
# Google down when deleting: the booking is deleted anyway, the removal is queued and done later
fail('down')
admin('delete', [ids[3]])
q = sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'sq:%'")
check('D3 Google down while deleting: the booking is gone, the row removal is queued (never lost, never blocking)', sql('SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE id=%d' % ids[3]) == '0' and q == '1' and idrow(ids[3])[1] is not None, (q, idrow(ids[3])))
fail(None); age_sheet_marks(); run_task()
check('D4 the task removes the queued row once Google answers', idrow(ids[3])[1] is None and sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'sq:%'") == '0', (grid(), sql("SELECT k FROM joom_webgbooking_kv")))

# ---------------------------------------------------------------- E. row not found: nothing to do, no error
reset_env()
sheet_cfg()
sql("UPDATE joom_webgbooking_google SET sheet_id='', sheet_tab=''")       # copy off: this booking is never written
book('hour', day(D), '10:00', email='e1@example.invalid', name='SenzaRiga')
e1 = last_id()
sheet_cfg()
put_sheet('Prenotazioni', [['A', 'B'], ['vecchia', 'riga', '', '', '', '', '', '', 'confirmed', '', '', '', '', '', '']])   # a row of an older version: 15 cells but column O empty
jx('rm -f /tmp/gmock/log.jsonl')
before = grid()
cust_cancel(e1)
check('E1 the booking has no row (or an old row without id): cancelling works, the sheet is not changed, no row appended', status(e1) == 'cancelled' and grid() == before, grid())
check('E2 no error is recorded, nothing is marked for a retry', 'sheet_sync' not in steps(e1) and kv('sheet_err') == '', (steps(e1), kv('sheet_err')))
check('E3 no write at all was sent to Sheets (only reads)', not [l for l in reqs() if l['method'] in ('PUT', 'POST')], [(l['method'], l['path']) for l in reqs()])
admin('delete', [e1])
check('E4 deleting it is fine too, the old row stays', grid() == before and sql('SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE id=%d' % e1) == '0', grid())

# ---------------------------------------------------------------- F. API not enabled (403 SERVICE_DISABLED)
reset_env()
sheet_cfg()
fail('disabled')
r = book('hour', day(D), '10:00', email='f1@example.invalid', name='ApiSpenta')
f1 = last_id()
check('F1 the Sheets API is off: the booking is made all the same (confirmed, event, mail)', r.get('ok') is True and status(f1) == 'confirmed' and sql('SELECT google_event_id FROM joom_webgbooking_bookings WHERE id=%d' % f1) != '' and len(mails()) >= 1, r)
check('F2 the step is left to retry, the kind of problem is recorded (not Google\'s text)', sql('SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d' % f1) == 'retry' and json.loads(kv('sheet_err') or '{}').get('kind') == 'api_disabled' and 'SERVICE_DISABLED' not in kv('sheet_err'), (kv('sheet_err'), steps(f1)))
p = adm.get(GPAGE); t = text(p)
check('F3 the Google page says it clearly: Google Sheets API not enabled in the Cloud project, enable it', ('Google Sheets API' in t or 'Google Sheets' in t) and ('not enabled' in t or 'non è abilitato' in t) and 'wgb-sheet' not in p, t[t.find('Optional') - 20: t.find('Optional') + 700])
check('F4 no raw Google text on screen (no project number, no console link, no reason code)', not re.search(r'100455972354|console\.developers|SERVICE_DISABLED|PERMISSION_DENIED|has not been used', p), '')
check('F5 the section is open so the message is seen', re.search(r'<details[^>]*\bopen\b', p) is not None, '')
fail(None); age_sheet_marks()
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(NOW(), INTERVAL 10 MINUTE)")
run_task()
check('F6 API enabled: the task writes the waiting row (header + row), the warning goes away', idrow(f1)[1] is not None and len(grid()) == 2 and kv('sheet_err') == '', (grid(), kv('sheet_err')))
p = adm.get(GPAGE)
check('F7 the Google page has no Sheets warning any more', 'COM_WEBGBOOKING_GOOGLE_SHEET_ERR' not in p and not re.search(r'<details[^>]*\bopen\b', p), '')

# ---------------------------------------------------------------- G. cancel while Google answers an error: the cancel succeeds, the row is fixed later
reset_env()
sheet_cfg()
book('hour', day(D), '10:00', email='g1@example.invalid', name='Annulla')
g1 = last_id()
fail('down')
jx('rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml')
page = cust_cancel(g1)
check('G1 Google errors during the cancel: the customer still sees "cancelled", Joomla says cancelled, the calendar event is removed, the mail goes', status(g1) == 'cancelled' and ('cancel' in page.lower() or 'annull' in page.lower()) and [l for l in gm_log() if l['method'] == 'DELETE'] and len(mails()) >= 1, page[-200:])
check('G2 the row is marked "to rewrite" in fx_steps and still says confirmed in the sheet', steps(g1).get('sheet_sync') == 'needed' and idrow(g1)[1][8] == 'confirmed', (steps(g1), idrow(g1)))
run_task()
check('G3 a retry before the backoff has passed does not hammer Google (still marked, still confirmed)', steps(g1).get('sheet_sync') == 'needed' and idrow(g1)[1][8] == 'confirmed', steps(g1))
fail('down:2'); age_sheet_marks(); run_task()
s1 = steps(g1)
check('G4 Google still failing for two calls: the retry fails again, tries counted, still marked', s1.get('sheet_sync') == 'needed' and int(s1.get('sheet_tries', 0)) >= 1 and idrow(g1)[1][8] == 'confirmed', s1)
fail(None); age_sheet_marks(); run_task()
check('G5 Google answers again: the task rewrites the row (cancelled) and clears the mark', idrow(g1)[1][8] == 'cancelled' and 'sheet_sync' not in steps(g1), (steps(g1), idrow(g1)))
# gives up after 10 tries and tells the staff once
book('hour', day(D), '12:00', email='g2@example.invalid', name='Rinuncia')
g2 = last_id()
fail('down')
cust_cancel(g2)
sql("UPDATE joom_webgbooking_bookings SET fx_steps=JSON_SET(fx_steps,'$.sheet_tries',9,'$.sheet_at',1) WHERE id=%d" % g2)
jx('rm -f /tmp/mail/*.eml')
run_task()
check('G6 after ten failed tries the mark becomes "failed" and the staff gets one mail', steps(g2).get('sheet_sync') == 'failed' and len([m for m in mails() if 'staff@' in mail_text(m)[:400]]) >= 1, steps(g2))
fail(None)

# ---------------------------------------------------------------- H. two changes on the same row never write a wrong row; rows are found by id every time
reset_env()
sheet_cfg()
hs = []
for h in ('09:00', '11:00', '13:00'):
    book('hour', day(D), h, email='h%s@example.invalid' % h[:2], name='H' + h[:2])
    hs.append(last_id())
admin('delete', [hs[0]])                       # the row numbers of the others shift up by one
cust_cancel(hs[2])
admin('complete', [hs[1]])
st = {i: idrow(i)[1][8] for i in hs[1:]}
check('H1 after another row was removed, a change still lands on the right row (found by id, no remembered row number)', st == {hs[1]: 'completed', hs[2]: 'cancelled'} and len(grid()) == 3, (st, grid()))
lg_ = reqs()
ok_ = all(i > 0 and 'O%3AO' in lg_[i - 1]['path'] or lg_[i]['method'] != 'PUT' for i in range(len(lg_)))
check('H2 every rewrite of a row is preceded by a fresh read of column O (the row is looked up at each write)', ok_ and any(l['method'] == 'PUT' for l in lg_), [(l['method'], l['path'][-30:]) for l in lg_][-8:])

# ---------------------------------------------------------------- I. scopes and calls: nothing new
src = jx('cat /var/www/html/administrator/components/com_webgbooking/src/Model/GoogleModel.php')
scopes = re.search(r"'scope'\s*=>\s*'([^']+)'", src)
check('I1 the scope list is exactly the same as before: openid email, calendar.events, calendar.readonly, spreadsheets', scopes and scopes.group(1) == 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/spreadsheets', scopes and scopes.group(1))
allsrc = jx("grep -rhoE 'googleapis\\.com/auth/[a-z._]+' /var/www/html/plugins/system/webgbooking /var/www/html/administrator/components/com_webgbooking /var/www/html/components/com_webgbooking | sort -u")
check('I2 the only scopes named anywhere in the code are the same three, no Drive', sorted(allsrc.split()) == ['googleapis.com/auth/calendar.events', 'googleapis.com/auth/calendar.readonly', 'googleapis.com/auth/spreadsheets'], allsrc)
check('I3 no call to the Drive API in the whole run', not [l for l in gm_log() if '/drive/' in l['path']], '')

# ---------------------------------------------------------------- K. mass delete in ONE pass; Google disconnected: the removal waits
reset_env()
sheet_cfg()
ks = []
for h in ('09:00', '11:00', '13:00', '15:00'):
    book('hour', day(D), h, email='k%s@example.invalid' % h[:2], name='K' + h[:2])
    ks.append(last_id())
jx('rm -f /tmp/gmock/log.jsonl')
admin('delete', ks[:3])
bu = [l for l in reqs('POST') if l['path'].endswith(':batchUpdate')]
check('K1 deleting three bookings at once: three rows gone with ONE batch of removals (not one round trip each), the fourth row stays', len(bu) == 1 and len(bu[0]['body']['requests']) == 3 and [r[14] for r in grid()[1:]] == [str(ks[3])], (len(bu), grid()))
tokb = sql('SELECT refresh_token FROM joom_webgbooking_google')
sql("UPDATE joom_webgbooking_google SET refresh_token=''")
admin('delete', [ks[3]])
check('K2 a Sheet is set but Google is disconnected: the booking is deleted, the removal waits in the queue (never silently forgotten), the row is still there', sql('SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE id=%d' % ks[3]) == '0' and sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'sq:%'") == '1' and idrow(ks[3])[1] is not None, grid())
sql("UPDATE joom_webgbooking_google SET refresh_token='%s'" % tokb)
age_sheet_marks(); run_task()
check('K3 Google connected again: the task removes the row and empties the queue', idrow(ks[3])[1] is None and sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'sq:%'") == '0', grid())
# a column O formatted by the admin (thousands separator): the id is compared as the number it is (the fake Sheet answers raw values when asked)
reqs_raw = [l for l in gm_log() if 'sheets.googleapis' in l['host'] and 'valueRenderOption=UNFORMATTED_VALUE' in l['query']]
check('K4 the id column is read as raw values (a formatted column cannot hide a row)', len(reqs_raw) >= 1, '')

# ---------------------------------------------------------------- J. an expired token / no Sheet configured / copy off: nothing happens, nothing breaks
reset_env()
book('hour', day(D), '10:00', email='j1@example.invalid', name='Spento')
j1 = last_id()
cust_cancel(j1)
check('J1 copy off (no sheet configured): no call to Sheets at all, cancel works', status(j1) == 'cancelled' and not reqs() and grid() == [], reqs())

jx('rm -f /tmp/gmock/sheet_fail')
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
