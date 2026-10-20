"""Screenshots of the direct booking pages through the host's Chrome in headless mode (no window).
Usage: python page_render.py <port> <container> <outdir> <path> [<path> ...] [--form]
  python page_render.py 8096 wgblink-6-j reports/direct /prenota/chiamata-30 /prenota
Writes <outdir>/<name>-<light|dark>-<desktop|phone>.png (desktop 1100 px, phone 375 px) and prints what the browser measured: the page is
shown inside an iframe of the wanted width served by the test site itself (same origin), so the width rules answer like a phone does and
scrollWidth can be read (it must be equal to the width: no sideways scroll on a phone)."""
import sys, os, re, subprocess, tempfile, shutil

CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
VARIANTS = [('light', 'desktop', 1100, 1000), ('dark', 'desktop', 1100, 1000), ('light', 'phone', 375, 1500), ('dark', 'phone', 375, 1500)]
# the "form" variant: pick the first free day, then the first time, so the details form (name, email, questions, privacy, pay or book button) is on the screen
CLICKS = 'setTimeout(function(){var d=f.contentDocument.querySelector(".wgb-avail");if(d)d.click();setTimeout(function(){var t=f.contentDocument.querySelector(".wgb-slot");if(t)t.click();},700);},900);'
ENV = dict(os.environ, MSYS_NO_PATHCONV='1', MSYS2_ARG_CONV_EXCL='*')


def shot(port, container, path, dst, width, height, dark, profile, form=False):
    wrap = ('<!doctype html><html><body style="margin:0;background:%s"><iframe id="f" src="%s" style="width:%dpx;height:%dpx;border:0;display:block"></iframe>'
            '<script>var f=document.getElementById("f");f.onload=function(){%s setTimeout(function(){try{var d=f.contentDocument.documentElement;document.title="M:"+d.scrollWidth+"x"+d.scrollHeight+":"+f.contentDocument.body.scrollWidth;}catch(e){document.title="M:err";}},%d);};</script></body></html>'
            % ('#14151d' if dark else '#f2f4f7', path, width, height, CLICKS if form else '', 3600 if form else 1800))
    tmp = os.path.join(tempfile.gettempdir(), 'wgb_wrap.html')
    open(tmp, 'w', encoding='utf-8').write(wrap)
    subprocess.run(['docker', 'cp', tmp, container + ':/var/www/html/wgbtest/_wrap.html'], capture_output=True, env=ENV)
    subprocess.run(['docker', 'exec', container, 'chmod', 'a+r', '/var/www/html/wgbtest/_wrap.html'], capture_output=True, env=ENV)
    args = [CHROME, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + profile,
            '--force-device-scale-factor=1', '--window-size=%d,%d' % (width, height), '--virtual-time-budget=8000',
            '--blink-settings=preferredColorScheme=%d' % (0 if dark else 1), '--screenshot=' + dst, '--dump-dom',
            'http://localhost:%s/wgbtest/_wrap.html' % port]
    out = subprocess.run(args, capture_output=True, timeout=120).stdout.decode('utf-8', 'replace')
    m = re.search(r'<title>(M:[^<]*)</title>', out)
    return m.group(1) if m else 'M:?'


def main():
    args_ = [a for a in sys.argv[1:] if not a.startswith('--')]
    port, container, outdir, paths = args_[0], args_[1], args_[2], args_[3:]
    outdir = os.path.abspath(outdir)
    os.makedirs(outdir, exist_ok=True)
    profile = tempfile.mkdtemp(prefix='wgb-chrome-')
    try:
        for p in paths:
            name = re.sub(r'[^a-z0-9]+', '-', p.lower()).strip('-') or 'index'
            for scheme, dev, w, h in VARIANTS:
                dst = os.path.join(outdir, '%s-%s-%s.png' % (name, scheme, dev))
                print(p, scheme, dev, shot(port, container, p, dst, w, h, scheme == 'dark', profile), dst)
                if '--form' in sys.argv and dev == 'phone' or ('--form' in sys.argv and scheme == 'light'):
                    dst2 = os.path.join(outdir, '%s-form-%s-%s.png' % (name, scheme, dev))
                    print(p, 'form', scheme, dev, shot(port, container, p, dst2, w, h, scheme == 'dark', profile, True), dst2)
    finally:
        shutil.rmtree(profile, ignore_errors=True)


if __name__ == '__main__':
    main()
