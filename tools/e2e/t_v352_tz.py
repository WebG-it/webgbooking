"""0.35.2: the time zone menu, in a real browser (Chrome headless, its own temporary profile; never the user's browser).
For each visitor zone (Rome = the site's, New York, London, Tokyo, India with its half hour) the browser's zone is set with the DevTools emulation, the
direct booking page is opened and what the visitor SEES is compared with what the server offers, converted with an independent library (zoneinfo):
the days that can be chosen, the times of each day (also days that change across midnight), the zone selected by default, the menu labels, changing the
zone; then one slot is booked through the page and the stored booking must be the same instant the visitor saw. The month of the 25/10 (Rome's clocks go
back) and 01/11 (New York's) are covered whenever they fall in the booking window; the pure conversion (any date, any zone, the changes of the clock) is
also checked in Node against zoneinfo, with fixed dates. Needs Chrome (the Corridoio has it), WGB_E2E_ADMIN_PASS is not needed."""
import sys, os, re, json, html, time, base64, subprocess, tempfile, shutil, urllib.request, http.client, urllib.parse, datetime as dt, zoneinfo
from wgbtest import *
import websocket

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.abspath(os.path.join(HERE, '..', '..', 'src', 'plg_system_webgbooking', 'yootheme', 'elements', 'booking', 'assets'))
CHROME = os.environ.get('WGB_CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')
TBL = 'joom_webgbooking_eventtypes'
ROME = zoneinfo.ZoneInfo('Europe/Rome')
DBG = int(os.environ.get('WGB_E2E_CDP_PORT', '9352'))
OUT = os.environ.get('WGB_E2E_SHOTS', '')


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


# ================================================================ 1. the pure conversion, in Node, against zoneinfo
def node_conversion():
    src = open(os.path.join(SRC, 'wgb.js'), encoding='utf-8').read()
    m = re.search(r'  var fmtCache=\{\}.*?(?=  function esc\(s\))', src, flags=re.S)
    lib = m.group(0) if m else ''
    cases = []
    zones = ['Europe/Rome', 'America/New_York', 'Europe/London', 'Asia/Tokyo', 'Asia/Kolkata', 'Australia/Sydney', 'America/Sao_Paulo', 'Pacific/Auckland', 'UTC']
    days = ['2026-03-28', '2026-03-29', '2026-03-30', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-31', '2026-11-01', '2026-11-02', '2027-01-15', '2026-07-01']
    for z in zones:
        for d in days:
            for hm in ('00:00', '02:30', '09:00', '12:30', '17:30', '23:30'):
                a = dt.datetime.fromisoformat('%s %s:00' % (d, hm)).replace(tzinfo=ROME)
                if a.astimezone(ROME).strftime('%H:%M') != hm or a.replace(fold=1).utcoffset() != a.utcoffset():
                    continue                                     # a time that does not exist in Rome that day (clock change): not a slot
                # the instant a Rome wall time has, and the wall time in the other zone
                u = a.astimezone(zoneinfo.ZoneInfo(z))
                cases.append([z, d, hm, int(a.timestamp()) * 1000, u.strftime('%Y-%m-%d'), u.strftime('%H:%M')])
    js = "var window={},document={},navigator={};\n" + lib + "\nvar cases=%s,bad=[];\n" % json.dumps(cases) + r"""
cases.forEach(function(c){var y=+c[1].slice(0,4),mo=+c[1].slice(5,7),d=+c[1].slice(8,10),h=+c[2].slice(0,2),mi=+c[2].slice(3,5);
 var ms=wallMs(y,mo,d,h,mi,'Europe/Rome');var p=tzParts(ms,c[0]);var vk=p.year+'-'+pad2x(p.month)+'-'+pad2x(p.day),vt=pad2x(p.hour)+':'+pad2x(p.minute);
 if(ms!==c[3]||vk!==c[4]||vt!==c[5])bad.push([c,ms,vk,vt]);
 var back=wallMs(p.year,p.month,p.day,p.hour,p.minute,c[0]);if(back!==ms)bad.push(['roundtrip',c,back]);});
console.log(JSON.stringify({n:cases.length,bad:bad.slice(0,5)}));"""
    f = os.path.join(tempfile.mkdtemp(prefix='wgbtz-'), 'conv.js')
    open(f, 'w', encoding='utf-8').write(js)
    node = shutil.which('node') or 'node'
    out = subprocess.run([node, f], capture_output=True, text=True, timeout=120)
    try:
        return json.loads(out.stdout.strip().splitlines()[-1])
    except Exception:
        return {'n': 0, 'bad': [out.stdout[-300:], out.stderr[-300:]]}


res = node_conversion()
check('N1 the conversion (instant of a wall time in a zone, and back) matches zoneinfo for %d cases: 9 zones x 11 dates around the clock changes of 29/03, 25/10, 01/11 x 6 times' % res['n'], res['n'] > 300 and not res['bad'], res['bad'])
reset_env(render=False)
sql('DELETE FROM %s' % TBL)
jx('php /tmp/mock/init.php >/dev/null')
sql("INSERT INTO %s (slug,title,state,listed,duration,location,allow_guest,created,modified) VALUES ('tz-browser','Prova fuso',1,1,'30','meet',0,NOW(),NOW())" % TBL)

# ================================================================ 2. the page in a real browser
class Chrome:
    def __init__(self):
        self.prof = tempfile.mkdtemp(prefix='wgbtz-chrome-')
        self.p = subprocess.Popen([CHROME, '--headless=new', '--disable-gpu', '--user-data-dir=' + self.prof, '--remote-debugging-port=%d' % DBG, '--remote-allow-origins=*', '--no-first-run', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        tabs = None
        for _ in range(60):
            try:
                tabs = json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % DBG))
                break
            except Exception:
                time.sleep(0.5)
        self.ws = websocket.create_connection([t for t in tabs if t['type'] == 'page'][0]['webSocketDebuggerUrl'], timeout=60)
        self.n = 0
        self.cmd('Page.enable')
        self.cmd('Runtime.enable')

    def cmd(self, m, **k):
        self.n += 1
        self.ws.send(json.dumps({'id': self.n, 'method': m, 'params': k}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get('id') == self.n:
                return r.get('result', r)

    def js(self, e):
        r = self.cmd('Runtime.evaluate', expression=e, returnByValue=True, awaitPromise=True)
        return r.get('result', {}).get('value')

    def goto(self, url, wait='[data-wgb] .wgb-cal-grid'):
        self.cmd('Page.navigate', url=url)
        self.waitfor("!!document.querySelector('%s')" % wait)
        self.waitfor("!!document.querySelector('.wgb-cal-grid .wgb-cell.wgb-avail')", 60)    # the month answer has been drawn

    def waitfor(self, cond, tries=80):
        for _ in range(tries):
            if self.js(cond):
                return True
            time.sleep(0.25)
        return False

    def shot(self, name):
        if not OUT:
            return
        os.makedirs(OUT, exist_ok=True)
        open(os.path.join(OUT, name + '.png'), 'wb').write(base64.b64decode(self.cmd('Page.captureScreenshot', format='png')['data']))

    def close(self):
        try:
            self.p.kill()
        except Exception:
            pass
        shutil.rmtree(self.prof, ignore_errors=True)


def server_month(ev, a, b):
    return month(ev, a, b)


def expected_days(site_days, tz):
    """visitor date -> sorted visitor times, from the server's site-zone slots, converted with zoneinfo"""
    z = zoneinfo.ZoneInfo(tz)
    out = {}
    for sd, ts_ in site_days.items():
        for t in ts_:
            x = dt.datetime.fromisoformat('%s %s:00' % (sd, t)).replace(tzinfo=ROME).astimezone(z)
            out.setdefault(x.strftime('%Y-%m-%d'), []).append(x.strftime('%H:%M'))
    return {k: sorted(v) for k, v in out.items()}


ALIAS = {'Asia/Kolkata': ['Asia/Kolkata', 'Asia/Calcutta']}   # Chrome names the zone with the old alias
URL = BASE + '/prenota/tz-browser'
ch = Chrome()
try:
    ZONES = [('Europe/Rome', 'it-IT'), ('America/New_York', 'en-US'), ('Europe/London', 'en-GB'), ('Asia/Tokyo', 'ja-JP'), ('Asia/Kolkata', 'en-IN')]
    first_page_cfg = None
    for tz, loc in ZONES:
        ch.cmd('Emulation.setTimezoneOverride', timezoneId=tz)
        ch.cmd('Emulation.setDeviceMetricsOverride', width=1100, height=900, deviceScaleFactor=1, mobile=False)
        ch.goto(URL)
        cfg = json.loads(ch.js("document.querySelector('[data-wgb]').getAttribute('data-wgb')") or '{}')
        first_page_cfg = first_page_cfg or cfg
        sel = ch.js("(function(){var s=document.querySelector('.wgb-tz-sel');return s?{v:s.value,t:s.options[s.selectedIndex].text,n:s.options.length,l:(document.querySelector('label[for=\"'+s.id+'\"]')||{}).textContent}:null})()")
        check('V1 %s: the zone menu is under the calendar, with a label, and the zone of the browser is selected' % tz, bool(sel) and bool(sel.get('l')) and sel.get('v') in ALIAS.get(tz, [tz]), sel)
        check('V1b %s: the option reads "<name of the zone> (hh:mm)" with the time it is there now, and there are many zones to choose from' % tz, bool(sel) and re.search(r'\(\d{2}:\d{2}\)$', sel.get('t', '')) is not None and sel.get('n', 0) > 20, sel)
        if tz == 'Europe/Rome':
            # the menu names each group by its best-known city (New York, not Detroit), with the same name the emails use
            today_iso = dt.date.today().isoformat()
            ny_name = tz_label('America/New_York', today_iso, '12:00')
            eo = ch.js("(function(n){var o=[].slice.call(document.querySelectorAll('.wgb-tz-sel option')).filter(function(x){return x.text.indexOf(n+' (')===0})[0];return o?{v:o.value,t:o.text}:null})(%s)" % json.dumps(ny_name))
            check('V1c the "Eastern" group of the menu stands for America/New_York (not Detroit), named like the emails name it (%s)' % tz_label('America/New_York', today_iso, '12:00'), bool(eo) and eo['v'] == 'America/New_York' and eo['t'].startswith(tz_label('America/New_York', today_iso, '12:00') + ' ('), eo)
        # what the visitor can pick this month vs what the server offers, converted
        now = dt.datetime.now(zoneinfo.ZoneInfo(tz))
        y, m = now.year, now.month
        for step in range(2):                          # this month and the next (the clocks of Oct 25 and Nov 1)
            first = dt.date(y, m, 1) - dt.timedelta(days=2)
            last = (dt.date(y + (m == 12), m % 12 + 1, 1) - dt.timedelta(days=1)) + dt.timedelta(days=2)
            site = server_month(cfg['ev'], first.isoformat(), last.isoformat())
            exp = expected_days(site, tz)
            shown = ch.js("[].slice.call(document.querySelectorAll('.wgb-cal-grid .wgb-cell[data-date]:not([disabled])')).map(function(b){return b.getAttribute('data-date')})") or []
            want = sorted(k for k in exp if k.startswith('%04d-%02d-' % (y, m)))
            check('V2 %s %04d-%02d: the days the visitor can choose are exactly the days that have a slot in THEIR zone (%d)' % (tz, y, m, len(want)), sorted(shown) == want, (sorted(set(shown) ^ set(want))[:6], len(shown), len(want)))
            bad = []
            for d in want[:12]:
                got = ch.js("(function(){var b=document.querySelector('.wgb-cal-grid [data-date=\"%s\"]');b.click();return new Promise(function(r){setTimeout(function(){r([].slice.call(document.querySelectorAll('.wgb-slot')).map(function(x){return x.textContent.trim()}));},150);});})()" % d) or []
                if got != exp[d]:
                    bad.append((d, got[:4], exp[d][:4]))
                ch.js("(function(){var b=document.querySelector('[data-act=\"back\"]');if(b)b.click();})()")
                ch.waitfor("!!document.querySelector('.wgb-cal-grid')")
            check('V3 %s %04d-%02d: the times of each day, checked for %d days, are the converted site slots (days that change across midnight included)' % (tz, y, m, min(12, len(want))), not bad, bad[:3])
            if step == 0:
                ch.js("document.querySelector('[data-act=\"next\"]').click()")
                ch.waitfor("!!document.querySelector('.wgb-cal-grid .wgb-cell.wgb-avail')", 120)     # the next month has been answered and drawn
                m = m % 12 + 1
                y = y + (m == 1)
        # one real booking through the page: what the visitor picked must be the booked instant
        ch.goto(URL)
        pick = ch.js("(function(){var b=document.querySelector('.wgb-cal-grid .wgb-cell.wgb-avail');return b?b.getAttribute('data-date'):null})()")
        slot = ch.js("(function(){document.querySelector('.wgb-cal-grid .wgb-cell.wgb-avail').click();return new Promise(function(r){setTimeout(function(){var s=document.querySelectorAll('.wgb-slot');r(s.length?s[Math.min(3,s.length-1)].textContent.trim():null);},200);});})()")
        ch.js("(function(){var s=[].filter.call(document.querySelectorAll('.wgb-slot'),function(x){return x.textContent.trim()=='%s'})[0];s.click();})()" % slot)
        ch.waitfor("!!document.querySelector('.wgb-f-name')")
        email = 'br%s@example.invalid' % re.sub(r'[^a-z]', '', tz.lower())
        ch.js("(function(){function set(c,v){var e=document.querySelector(c);e.value=v;}set('.wgb-f-name','Visitor %s');set('.wgb-f-email','%s');document.querySelector('.wgb-f-privacy').checked=true;document.querySelector('[data-act=\"book\"]').click();})()" % (tz, email))
        ok = ch.waitfor("!!document.querySelector('.uk-alert-success')", 60)
        ch.shot('tz-%s' % tz.replace('/', '-'))
        rw = sql("SELECT booking_date,booking_time,IFNULL(customer_tz,'NULL') FROM joom_webgbooking_bookings WHERE customer_email=%s" % q(email)).split('\t')
        check('V4 %s: booked through the page (%s %s), the answer is shown' % (tz, pick, slot), ok and len(rw) == 3, rw)
        if len(rw) == 3:
            booked = dt.datetime.fromisoformat('%s %s:00' % (rw[0], rw[1])).replace(tzinfo=ROME).astimezone(zoneinfo.ZoneInfo(tz))
            check('V4b %s: the stored booking is the very instant the visitor picked (%s %s there), the zone is stored' % (tz, pick, slot), booked.strftime('%Y-%m-%d') == pick and booked.strftime('%H:%M') == slot and rw[2] in ALIAS.get(tz, [tz]), (rw, booked.isoformat(), pick, slot))

    # changing the zone: a visitor in New York chooses Tokyo, the calendar and times are redrawn in Tokyo
    ch.cmd('Emulation.setTimezoneOverride', timezoneId='America/New_York')
    ch.goto(URL)
    ev = json.loads(ch.js("document.querySelector('[data-wgb]').getAttribute('data-wgb')"))['ev']
    ch.js("(function(){var s=document.querySelector('.wgb-tz-sel');s.value='Asia/Tokyo';s.dispatchEvent(new Event('change',{bubbles:true}));})()")
    ch.waitfor("!!document.querySelector('.wgb-cal-grid') && document.querySelector('.wgb-tz-sel').value=='Asia/Tokyo'")
    time.sleep(0.5)
    now = dt.datetime.now(zoneinfo.ZoneInfo('Asia/Tokyo'))
    exp = expected_days(server_month(ev, (dt.date(now.year, now.month, 1) - dt.timedelta(days=2)).isoformat(), (dt.date(now.year + (now.month == 12), now.month % 12 + 1, 1) + dt.timedelta(days=1)).isoformat()), 'Asia/Tokyo')
    shown = ch.js("[].slice.call(document.querySelectorAll('.wgb-cal-grid .wgb-cell[data-date]:not([disabled])')).map(function(b){return b.getAttribute('data-date')})") or []
    want = sorted(k for k in exp if k.startswith('%04d-%02d-' % (now.year, now.month)))
    check('V5 a visitor in New York who picks Tokyo in the menu: the calendar is redrawn with the days of Tokyo', bool(want) and sorted(shown) == want, (sorted(set(shown) ^ set(want))[:6]))
    pick = want[0]
    got = ch.js("(function(){document.querySelector('.wgb-cal-grid [data-date=\"%s\"]').click();return new Promise(function(r){setTimeout(function(){r([].slice.call(document.querySelectorAll('.wgb-slot')).map(function(x){return x.textContent.trim()}));},200);});})()" % pick) or []
    check('V5b ... and the times of that day are Tokyo\'s', got == exp[pick], (got[:4], exp[pick][:4]))
    lbl = ch.js("(function(){var s=document.querySelector('.wgb-tz-sel');return s?1:0})()")
    check('V5c ... the menu stays available from the time list, to change again', lbl == 1)

    # phone width: no horizontal scroll with the menu
    ch.cmd('Emulation.setDeviceMetricsOverride', width=375, height=812, deviceScaleFactor=1, mobile=True)
    ch.goto(URL)
    ws_ = ch.js("[document.documentElement.scrollWidth, innerWidth, (function(){var s=document.querySelector('.wgb-tz-sel');var r=s.getBoundingClientRect();return [Math.round(r.left),Math.round(r.right)]})()]")
    ch.shot('tz-phone')
    check('V6 phone 375: no horizontal scroll, the menu fits inside the screen', ws_ and ws_[0] <= ws_[1] and ws_[2][0] >= 0 and ws_[2][1] <= 375, ws_)
finally:
    ch.close()

reset_env()
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
