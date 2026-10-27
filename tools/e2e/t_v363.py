"""0.36.3: (1) after a booking the confirmation shows what was booked (event, long date, start - end and the zone name, length, how it takes place,
the address the confirmation goes to, confirmed / waiting for confirmation); (2) the customer's management page (link of the email): the reschedule
block says what to do, and after a day and a time are chosen it shows "Now" and "New date" and a clear "Confirm the new date" button, which moves the
booking, the Google event and the Sheet row.
Real clicks in the PC's Chrome (headless): light and dark, desktop and 375 px phone. Screenshots in reports/direct363/ and reports/manage363/.
Usage: python t_v363.py <port> <container-prefix>     (needs WGB_E2E_ADMIN_PASS only through wgbtest's reset_env)"""
import sys, os, re, json, tempfile, shutil
from wgbtest import *
from shot361lib import shoot

HERE = os.path.dirname(os.path.abspath(__file__))
OUT1 = os.path.join(HERE, 'reports', 'direct363')
OUT2 = os.path.join(HERE, 'reports', 'manage363')
os.makedirs(OUT1, exist_ok=True)
os.makedirs(OUT2, exist_ok=True)
TBL = 'joom_webgbooking_eventtypes'
CONT = PFX + '-j'


def ins(slug, title, loc='meet', manual=0, desc='', dur='60'):
    sql("INSERT INTO %s (slug,title,description,state,listed,duration,location,allow_guest,confirm_manual,created,modified) VALUES ('%s','%s','%s',1,1,'%s','%s',1,%d,NOW(),NOW())" % (TBL, slug, title, desc, dur, loc, manual))


# fills the form after picking the first free day and its first time, books, then reads the thank-you
BOOK_JS = r'''
var av=d.querySelector(".wgb-avail");R.hasDay=!!av;if(av)av.click();
setTimeout(function(){var sl=d.querySelector(".wgb-slot");R.hasSlot=!!sl;if(sl){R.slotTxt=sl.textContent.trim();sl.click();}
setTimeout(function(){
 function set(c,v){var n=d.querySelector("."+c);if(n){n.value=v;n.dispatchEvent(new f.contentWindow.Event("input",{bubbles:true}));}}
 set("wgb-f-name","NAME_X");set("wgb-f-email","EMAIL_X");set("wgb-f-phone","PHONE_X");
 var pv=d.querySelector(".wgb-f-privacy");if(pv&&!pv.checked)pv.click();
 R.hasForm=!!d.querySelector("[data-act=book]");
 var b=d.querySelector("[data-act=book]");if(b)b.click();
 setTimeout(function(){
   var dn=d.querySelector(".wgb-done");R.done=!!dn;R.alertTxt=(d.querySelector(".uk-alert-success")||{}).textContent||"";
   R.recap=(d.querySelector(".wgb-recap")||{}).innerText||"";
   R.title=(d.querySelector(".wgb-recap-t")||{}).textContent||"";R.status=(d.querySelector(".wgb-recap-st")||{}).textContent||"";
   R.rows=Array.prototype.map.call(d.querySelectorAll(".wgb-recap-l li"),function(x){return x.textContent.trim();});
   var de=d.documentElement,fw=f.contentWindow;R.sw=de.scrollWidth;R.vw=fw.innerWidth;R.vh=fw.innerHeight;R.sh=de.scrollHeight;
   var cd=d.querySelector(".bk-card");if(cd){var rc=cd.getBoundingClientRect();R.cardTop=Math.round(rc.top);R.cardBottom=Math.round(rc.bottom);}
   var cf=d.querySelector(".wgb-booking");try{R.cfg=JSON.parse(cf.getAttribute("data-wgb"));R.siteTz=R.cfg.siteTz;}catch(e){}
   R.brTz=Intl.DateTimeFormat().resolvedOptions().timeZone;done();},2600);
 },900);},900);
'''


def go(slug, w, h, dark, name, email, phone, prof, tag):
    js = BOOK_JS.replace('NAME_X', name).replace('EMAIL_X', email).replace('PHONE_X', phone)
    return shoot(PORT, CONT, '/prenota/' + slug, w, h, dark, prof, os.path.join(OUT1, '%s-%s-%s.png' % (tag, 'dark' if dark else 'light', 'phone' if w < 500 else 'desktop')), js, wait=2500, budget=20000)


reset_env()
sql('DELETE FROM %s' % TBL)
ins('chiamata-60', 'Chiamata di 60 minuti', 'meet', 0, 'Una chiamata per capire di cosa hai bisogno.')
ins('telefono-30', 'Telefonata veloce', 'phone', 0, '', '30')
ins('su-richiesta', 'Appuntamento su richiesta', 'meet', 1)
prof = tempfile.mkdtemp(prefix='wgb363-')
try:
    # ------------------------------------------------------------------ 1. the confirmation after a booking
    n = 0
    for dark in (False, True):
        for w, h in ((1100, 900), (375, 812)):
            n += 1
            tag = 'meet'
            em = 'cliente%d@example.invalid' % n
            r = go('chiamata-60', w, h, dark, 'Mario Rossi', em, '+39 333 111 2222', prof, tag)
            sc = ('dark' if dark else 'light', 'phone' if w < 500 else 'desktop')
            ok = r.get('done') and r.get('rows')
            check('R1 %s %s: after the booking the thank-you holds a summary (event, date, time, length, place, email)' % sc, bool(ok), r)
            if not ok:
                continue
            rows = r['rows']
            check('R2 %s %s: the event name is there and the state says Confirmed' % sc, 'Chiamata di 60 minuti' in r['title'] and r['status'].strip() == 'Confirmed', (r['title'], r['status']))
            check('R3 %s %s: long date with weekday, day, month and year' % sc, re.search(r'(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),? \d{1,2} \w+ 20\d\d', rows[0] or '') is not None, rows[:1])
            m = re.match(r'^(\d\d):(\d\d) - (\d\d):(\d\d) \((.+)\)$', rows[1] if len(rows) > 1 else '')
            check('R4 %s %s: start - end with the name of the zone in brackets, end = start + 60 minutes' % sc, bool(m) and (int(m.group(3)) * 60 + int(m.group(4)) - int(m.group(1)) * 60 - int(m.group(2))) % 1440 == 60, rows[1:2])
            check('R5 %s %s: length, place and the address the confirmation goes to' % sc, '60 min' in ' '.join(rows) and 'Google Meet' in ' '.join(rows) and ('The confirmation goes to ' + em) in ' '.join(rows), rows)
            check('R6 %s %s: the server\'s own sentence is still there' % sc, 'Booking confirmed' in r['alertTxt'], r['alertTxt'])
            check('R7 %s %s: no sideways scroll' % sc, r['sw'] <= r['vw'], (r['sw'], r['vw']))
            if m and r.get('siteTz') == r.get('brTz'):
                bt = sql("SELECT booking_time FROM joom_webgbooking_bookings WHERE customer_email='%s'" % em)
                check('R8 %s %s: the start shown is the start the server stored (same zone as the site)' % sc, bt == '%s:%s' % (m.group(1), m.group(2)), (bt, rows[1]))
    # phone event: "I will call you on <number>"
    r = go('telefono-30', 1100, 900, False, 'Anna', 'anna@example.invalid', '+39 333 999 8888', prof, 'phone')
    rows = r.get('rows') or []
    check('R9 phone event: how it takes place says "I will call you on" and the number given; 30 min', any('I will call you on +39 333 999 8888' in x for x in rows) and any(x.startswith('30 min') for x in rows), rows)
    # waiting for confirmation (manual): the words are different
    r = go('su-richiesta', 1100, 900, False, 'Luca', 'luca@example.invalid', '+39 333 777 6666', prof, 'pending')
    rows = r.get('rows') or []
    check('R10 manual confirmation: the summary says "Waiting for confirmation", the sentence says the request was sent, the mail line says we will write', (r.get('status') or '').strip() == 'Waiting for confirmation' and 'Request sent' in (r.get('alertTxt') or '') and any('We will write to luca@example.invalid' in x for x in rows), r)
    # nothing new in the logs
    log = jx('cat /var/www/html/administrator/logs/webgbooking.php 2>/dev/null')
    check('R11 no email address or phone number in the plugin log', not re.search(r'cliente\d@example|anna@example|luca@example|\+39 333', log), '')
    # the Italian strings exist next to the English ones, with the same placeholders
    for k in ('RC_CONFIRMED', 'RC_PENDING', 'RC_CALL', 'RC_MAIL', 'RC_MAIL_WAIT', 'RS_FROM', 'RS_TO', 'RS_STEPS', 'RESCHEDULE_CONFIRM'):
        it = jx('grep -c "^PLG_SYSTEM_WEBGBOOKING_%s=" /var/www/html/plugins/system/webgbooking/language/it-IT/plg_system_webgbooking.ini' % k).strip()
        en = jx('grep -c "^PLG_SYSTEM_WEBGBOOKING_%s=" /var/www/html/plugins/system/webgbooking/language/en-GB/plg_system_webgbooking.ini' % k).strip()
        check('R12 string %s exists in it-IT and en-GB' % k, it == '1' and en == '1', (it, en))
    check('R13 the button of the management page is called "Conferma la nuova data" / "Confirm the new date"', 'Conferma la nuova data' in jx('cat /var/www/html/plugins/system/webgbooking/language/it-IT/plg_system_webgbooking.ini') and 'Confirm the new date' in jx('cat /var/www/html/plugins/system/webgbooking/language/en-GB/plg_system_webgbooking.ini'), '')

    # ------------------------------------------------------------------ 2. the management page: reschedule
    reset_env()
    sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
    r0 = book('hour', day(13), '10:00', email='gest@example.invalid', name='Gestione')
    bid = last_id()
    tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % bid)
    old = (sql('SELECT booking_date FROM joom_webgbooking_bookings WHERE id=%d' % bid), sql('SELECT booking_time FROM joom_webgbooking_bookings WHERE id=%d' % bid))
    page = '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + tok
    MANAGE_JS = r'''
R.steps=(d.querySelector("p.muted+.wgb-booking")?"ok":"no");R.intro=Array.prototype.map.call(d.querySelectorAll("p.muted"),function(x){return x.textContent.trim();});
R.before=Array.prototype.map.call(d.querySelectorAll("button"),function(b){return b.textContent.trim().slice(0,40);});
var av=d.querySelector(".wgb-avail");if(av)av.click();
setTimeout(function(){var sl=d.querySelector(".wgb-slot");R.slotTxt=sl?sl.textContent.trim():"";if(sl)sl.click();
setTimeout(function(){
 var bt=d.querySelector("[data-act=rebook]");R.btn=bt?bt.textContent.trim():"";R.btnH=bt?Math.round(bt.getBoundingClientRect().height):0;R.btnVisible=bt?(bt.getBoundingClientRect().top>=0&&bt.getBoundingClientRect().bottom<=f.contentWindow.innerHeight):false;
 R.change=Array.prototype.map.call(d.querySelectorAll(".wgb-change li"),function(x){return x.textContent.trim();});
 R.sw=d.documentElement.scrollWidth;R.vw=f.contentWindow.innerWidth;
 CLICK_CONFIRM
 setTimeout(function(){R.after=(d.querySelector(".uk-alert")||{}).textContent||"";R.hdate=(d.getElementById("wgb-m-date")||{}).textContent;R.htime=(d.getElementById("wgb-m-time")||{}).textContent;done();},2600);
},900);},900);
'''
    for dark in (False, True):
        for w, h in ((1100, 900), (375, 812)):
            sc = ('dark' if dark else 'light', 'phone' if w < 500 else 'desktop')
            # a fresh booking each time, so the confirm click is real only for the first run (light desktop)
            real = (not dark and w > 500)
            js = MANAGE_JS.replace('CLICK_CONFIRM', 'if(bt)bt.click();' if real else '')
            res = shoot(PORT, CONT, page, w, h, dark, prof, os.path.join(OUT2, 'manage-%s-%s.png' % sc), js, wait=2500, budget=20000)
            check('M1 %s %s: the reschedule block says what to do (pick a day and a time, then the button)' % sc, any('Confirm the new date' in x for x in res.get('intro', [])), res.get('intro'))
            check('M2 %s %s: after a day and a time are chosen a "Confirm the new date" button is on screen' % sc, res.get('btn') == 'Confirm the new date' and res.get('btnH', 0) >= 36 and res.get('btnVisible'), res)
            ch = res.get('change') or []
            check('M3 %s %s: the confirmation shows what it is now and what it becomes' % sc, len(ch) == 2 and ch[0].startswith('Now:') and ch[1].startswith('New date:') and re.search(r'\d\d:\d\d - \d\d:\d\d', ch[1]) and re.search(r'\d\d/\d\d/20\d\d, \d\d:\d\d', ch[0]), ch)
            check('M4 %s %s: no sideways scroll' % sc, res.get('sw', 0) <= res.get('vw', 1), res.get('sw'))
            if real:
                nd, nt = sql('SELECT booking_date FROM joom_webgbooking_bookings WHERE id=%d' % bid), sql('SELECT booking_time FROM joom_webgbooking_bookings WHERE id=%d' % bid)
                check('M5 confirming moves the booking (date or time changed in the database), the page says rescheduled and shows the new date', (nd, nt) != old and 'rescheduled' in (res.get('after') or '').lower() and res.get('hdate') and res.get('htime'), (old, nd, nt, res.get('after')))
                gp = [l for l in gm_log() if l['method'] == 'PATCH' and '/events/' in l['path'] and isinstance(l['body'], dict) and l['body'].get('start')]
                check('M6 the Google event is moved (a PATCH with the new start)', len(gp) == 1 and nt in json.dumps(gp[0]['body']) and nd in json.dumps(gp[0]['body']), [(l['path'][-20:], l['body'].get('start') if isinstance(l['body'], dict) else '') for l in gp])
                out = jx('cat /tmp/gmock/sheet.json')
                grid = json.loads(out)['tabs']['Prenotazioni']
                rowx = [r for r in grid if len(r) >= 15 and r[14] == str(bid)]
                check('M7 the Sheet row follows (new date and time in the same row)', len(rowx) == 1 and rowx[0][1] == nd and rowx[0][2] == nt, rowx)
                check('M8 the customer got the "moved" email', any('Appointment moved' in mail_text(p)[:2000] or 'spostato' in mail_text(p)[:2000] for p in mails()), '')
            else:
                # a fresh booking for the next screenshot state
                reset_env()
                sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
                book('hour', day(13), '10:00', email='gest@example.invalid', name='Gestione')
                bid = last_id()
                tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % bid)
                page = '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + tok
    # a booking waiting for confirmation can be moved from the same page
    reset_env()
    book('manual30', day(13), '10:00', email='att@example.invalid', name='Attesa')
    pid = last_id()
    ptok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % pid)
    ppage = '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + ptok
    po = (sql('SELECT booking_date FROM joom_webgbooking_bookings WHERE id=%d' % pid), sql('SELECT booking_time FROM joom_webgbooking_bookings WHERE id=%d' % pid))
    res = shoot(PORT, CONT, ppage, 1100, 900, False, prof, os.path.join(OUT2, 'manage-pending-light-desktop.png'), MANAGE_JS.replace('CLICK_CONFIRM', 'if(bt)bt.click();'), wait=2500, budget=20000)
    pn = (sql('SELECT booking_date FROM joom_webgbooking_bookings WHERE id=%d' % pid), sql('SELECT booking_time FROM joom_webgbooking_bookings WHERE id=%d' % pid))
    check('M9 a booking WAITING for confirmation shows the same block and can be moved; it stays pending', res.get('btn') == 'Confirm the new date' and pn != po and sql('SELECT status FROM joom_webgbooking_bookings WHERE id=%d' % pid) == 'pending', (res.get('btn'), po, pn))
finally:
    shutil.rmtree(prof, ignore_errors=True)
    sql('DELETE FROM %s' % TBL)
    jx('rm -f /var/www/html/wgbtest/_wrap363.html')
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
