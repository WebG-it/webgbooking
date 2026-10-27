"""Headless Chrome helper for the page tests (0.36.3): shows a page of the test site in an iframe of a given size, runs a small script inside it
(click, type ...) and returns what the script wrote with `R.x = ...`; a screenshot is saved. Same technique as page_render.py / t_v361_page.py."""
import os, re, json, subprocess, tempfile, html as H
from wgbtest import *

CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'


def shoot(port, container, path, width, height, dark, profile, dst, script='', wait=2500, budget=14000):
    """script runs inside the page's document as `d` (the iframe document), `f` (the iframe) and `R` (result object); it may use setTimeout and must end
    by calling done(). Returns R as a dict."""
    js = ('var f=document.getElementById("f"),R={};function done(){document.title="M:"+JSON.stringify(R);}'
          'f.onload=function(){setTimeout(function(){var d=f.contentDocument;try{%s}catch(e){R.err=String(e);done();}},%d);};' % (script or 'done();', wait))
    wrap = ('<!doctype html><html><body style="margin:0;background:%s"><iframe id="f" src="%s" style="width:%dpx;height:%dpx;border:0;display:block"></iframe><script>%s</script></body></html>'
            % ('#14151d' if dark else '#f2f4f7', path, width, height, js))
    tmp = os.path.join(tempfile.gettempdir(), 'wgb_wrap363.html')
    open(tmp, 'w', encoding='utf-8').write(wrap)
    subprocess.run(['docker', 'cp', tmp, container + ':/var/www/html/wgbtest/_wrap363.html'], capture_output=True, env=ENV)
    subprocess.run(['docker', 'exec', container, 'chmod', 'a+r', '/var/www/html/wgbtest/_wrap363.html'], capture_output=True, env=ENV)
    args = [CHROME, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + profile,
            '--force-device-scale-factor=1', '--window-size=%d,%d' % (width, height), '--virtual-time-budget=%d' % budget,
            '--blink-settings=preferredColorScheme=%d' % (0 if dark else 1), '--screenshot=' + dst, '--dump-dom', 'http://localhost:%s/wgbtest/_wrap363.html' % port]
    out = subprocess.run(args, capture_output=True, timeout=240).stdout.decode('utf-8', 'replace')
    m = re.search(r'<title>M:(.*?)</title>', out, re.S)
    try:
        return json.loads(H.unescape(m.group(1)))
    except Exception:
        return {'_raw': out[-300:]}
