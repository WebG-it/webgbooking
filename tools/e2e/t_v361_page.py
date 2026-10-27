"""0.36.1: the direct booking page (/prenota/<slug>, the index, "not found") is centred in the window, both ways.
Usage: python t_v361_page.py <port> <container-prefix> [outdir]     (Chrome headless on the PC, light and dark, computer and 375 px phone)
The page is shown in an iframe of the wanted size served by the test site itself (as page_render.py does), the browser measures:
  - low content: the distance above the block (card + footer) equals the distance below (+-3 px), horizontally too;
  - tall content (details form open on a phone, long list of event types): the block starts at the top (nothing cut off), the page scrolls,
    and there is no sideways scroll at all;
  - the height of the card at each step of the widget (calendar, times, form): reported, to see how much the card moves.
Screenshots are written to the outdir. Needs a Chrome on the PC (like page_render.py)."""
import sys, os, re, json, subprocess, tempfile, shutil
from wgbtest import *

CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
OUT = os.path.abspath(sys.argv[3]) if len(sys.argv) > 3 else os.path.join(os.path.dirname(os.path.abspath(__file__)), 'reports', 'direct361')
os.makedirs(OUT, exist_ok=True)
TBL = 'joom_webgbooking_eventtypes'

MEASURE = r"""
function geom(d){var p=d.querySelector('.bk-page');if(!p)return null;var k=p.children,a=k[0].getBoundingClientRect(),z=k[k.length-1].getBoundingClientRect();
 var de=d.documentElement,vh=d.defaultView.innerHeight,vw=d.defaultView.innerWidth;
 var cardBox=d.querySelector('.bk-card').getBoundingClientRect();
 return {top:Math.round(a.top*10)/10,bottom:Math.round((vh-z.bottom)*10)/10,left:Math.round(a.left*10)/10,right:Math.round((vw-a.right)*10)/10,vh:vh,vw:vw,scrollH:de.scrollHeight,scrollW:de.scrollWidth,
  sy:d.defaultView.scrollY,card:Math.round(cardBox.height),bodyMin:getComputedStyle(d.body).minHeight,bodyDisp:getComputedStyle(d.body).display};}
"""


def run(port, container, path, width, height, dark, profile, name, steps=False, form=False):
    js = MEASURE + (
        'var f=document.getElementById("f"),R={};f.onload=function(){setTimeout(function(){var d=f.contentDocument;R.g0=geom(d);'
        + ('var av=d.querySelector(".wgb-avail");if(av){av.click();}setTimeout(function(){R.g1=geom(d);var sl=d.querySelector(".wgb-slot");if(sl){sl.click();}setTimeout(function(){R.g2=geom(d);document.title="M:"+JSON.stringify(R);},900);},900);' if (steps or form) else 'document.title="M:"+JSON.stringify(R);')
        + '},1800);};')
    wrap = ('<!doctype html><html><body style="margin:0;background:%s"><iframe id="f" src="%s" style="width:%dpx;height:%dpx;border:0;display:block"></iframe><script>%s</script></body></html>'
            % ('#14151d' if dark else '#f2f4f7', path, width, height, js))
    tmp = os.path.join(tempfile.gettempdir(), 'wgb_wrap361.html')
    open(tmp, 'w', encoding='utf-8').write(wrap)
    subprocess.run(['docker', 'cp', tmp, container + ':/var/www/html/wgbtest/_wrap361.html'], capture_output=True, env=ENV)
    subprocess.run(['docker', 'exec', container, 'chmod', 'a+r', '/var/www/html/wgbtest/_wrap361.html'], capture_output=True, env=ENV)
    dst = os.path.join(OUT, name + '.png')
    args = [CHROME, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + profile,
            '--force-device-scale-factor=1', '--window-size=%d,%d' % (width, height), '--virtual-time-budget=12000',
            '--blink-settings=preferredColorScheme=%d' % (0 if dark else 1), '--screenshot=' + dst, '--dump-dom', 'http://localhost:%s/wgbtest/_wrap361.html' % port]
    outp = subprocess.run(args, capture_output=True, timeout=180).stdout.decode('utf-8', 'replace')
    m = re.search(r'<title>M:(.*?)</title>', outp)
    try:
        import html as H
        return json.loads(H.unescape(m.group(1)))
    except Exception:
        return {}


def ins(slug, title, desc=''):
    sql("INSERT INTO %s (slug,title,description,state,listed,duration,location,allow_guest,created,modified) VALUES ('%s','%s','%s',1,1,'',  'meet',1,NOW(),NOW())" % (TBL, slug, title, desc))


reset_env()
sql('DELETE FROM %s' % TBL)
ins('chiamata-60', 'Chiamata di 60 minuti', 'Una chiamata per capire di cosa hai bisogno.')
PORT_, PFX_ = PORT, PFX
container = PFX_ + '-j'
profile = tempfile.mkdtemp(prefix='wgb-chrome-')
res = {}
try:
    SIZES = [('desktop', 1100, 900), ('phone', 375, 812)]
    for dark in (False, True):
        sc = 'dark' if dark else 'light'
        for dev, w, h in SIZES:
            # low content, centred both ways
            for nm, path in (('event', '/prenota/chiamata-60'), ('index', '/prenota'), ('notfound', '/index.php?option=com_webgbooking&view=book&event=nonexiste')):
                g = run(PORT_, container, path, w, h, dark, profile, '%s-%s-%s' % (nm, sc, dev)).get('g0') or {}
                res[(nm, sc, dev)] = g
                tall = g and g.get('scrollH', 0) > g.get('vh', 0) + 1
                if nm == 'event' and dev == 'desktop':
                    pass
                if g and not tall:
                    check('P1 %s %s %s: low content is centred, distance above = distance below (+-3 px), left = right' % (nm, sc, dev),
                          abs(g['top'] - g['bottom']) <= 3 and abs(g['left'] - g['right']) <= 3 and g['top'] > 8, g)
                elif g:
                    check('P1b %s %s %s: content taller than the window: starts at the top, nothing cut off' % (nm, sc, dev), g['top'] >= 0 and g['sy'] == 0, g)
                else:
                    check('P1 %s %s %s measured' % (nm, sc, dev), False, 'no measure')
                if g:
                    check('P2 %s %s %s: no sideways scroll' % (nm, sc, dev), g['scrollW'] <= g['vw'], g)
        # tall content: the details form open on a phone, 375x640
        r = run(PORT_, container, '/prenota/chiamata-60', 375, 640, dark, profile, 'event-form-%s-phone' % sc, steps=True)
        g2 = r.get('g2') or {}
        check('P3 %s phone 375x640 with the form open: the page is taller than the window, starts at the top (top>=0, not centred), scrolls, no sideways scroll' % sc,
              g2 and g2['scrollH'] > g2['vh'] and g2['top'] >= 0 and g2['top'] <= 30 and g2['scrollW'] <= g2['vw'], g2)
        if sc == 'light':
            print('STEP HEIGHTS (card px) desktop? phone 375x640: calendar %s, times %s, form %s' % (r.get('g0', {}).get('card'), r.get('g1', {}).get('card'), r.get('g2', {}).get('card')))
            r = run(PORT_, container, '/prenota/chiamata-60', 1100, 900, dark, profile, 'event-steps-%s-desktop' % sc, steps=True)
            hs = [(r.get(k) or {}).get('card') for k in ('g0', 'g1', 'g2')]
            tops = [(r.get(k) or {}).get('top') for k in ('g0', 'g1', 'g2')]
            print('STEP HEIGHTS (card px) desktop 1100x900: calendar %s, times %s, form %s; top of the card %s' % (hs[0], hs[1], hs[2], tops))
            res['steps'] = (hs, tops)
            check('P5 the card does not shrink going from the calendar to the times, so it does not jump (same top, +-2 px); it may only grow for the form',
                  all(hs) and hs[1] >= hs[0] and (hs[1] > hs[0] or abs(tops[0] - tops[1]) <= 2) and hs[2] >= hs[1], (hs, tops))
    # many event types: a long index on a phone
    for i in range(2, 14):
        ins('tipo-%d' % i, 'Tipo di evento numero %d' % i, 'Descrizione breve del tipo %d.' % i)
    for dark in (False, True):
        sc = 'dark' if dark else 'light'
        g = run(PORT_, container, '/prenota', 375, 812, dark, profile, 'index-long-%s-phone' % sc).get('g0') or {}
        check('P4 long index %s phone: taller than the window, starts at the top, no sideways scroll' % sc, g and g['scrollH'] > g['vh'] and 0 <= g['top'] <= 30 and g['sy'] == 0 and g['scrollW'] <= g['vw'], g)
finally:
    shutil.rmtree(profile, ignore_errors=True)
    sql('DELETE FROM %s' % TBL)
    jx('rm -f /var/www/html/wgbtest/_wrap361.html')
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
