"""Turns the saved email previews (email_previews.py) into PNGs and one index page.
Light and dark, desktop (700 px) and phone (375 px), through the host's Chrome in headless mode with the colour scheme forced the same
way a mail app would ask for it. Usage: python email_render.py [it|en ...] [--index]   (default: it en; --index only rebuilds the index page)."""
import sys, os, json, subprocess, tempfile, shutil, html as H
from PIL import Image, ImageChops

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.path.join(HERE, 'reports', 'email')
CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
PROFILE = tempfile.mkdtemp(prefix='wgb-chrome-')
VARIANTS = [('light-desktop', 700, 0), ('dark-desktop', 700, 1), ('light-phone', 375, 0), ('dark-phone', 375, 1)]
LABELS = {'light-desktop': 'Chiaro, computer', 'dark-desktop': 'Scuro, computer', 'light-phone': 'Chiaro, telefono', 'dark-phone': 'Scuro, telefono'}


def shot(src, dst, width, dark):
    """Renders the email inside an iframe of the wanted width (so the width-based media queries answer like a phone would);
    dark mode is a preview copy where the dark rules apply always, which is what a mail app in dark mode makes of them."""
    folder = os.path.dirname(src)
    h = open(src, encoding='utf-8').read()
    inner = os.path.join(folder, '_tmp_inner.html')
    wrap = os.path.join(folder, '_tmp_wrap.html')
    if dark:
        h = h.replace('@media (prefers-color-scheme: dark)', '@media all')
    open(inner, 'w', encoding='utf-8').write(h)
    open(wrap, 'w', encoding='utf-8').write('<!doctype html><html><body style="margin:0;background:%s"><iframe src="_tmp_inner.html" style="width:%dpx;height:3300px;border:0;display:block"></iframe></body></html>' % ('#0b0c10' if dark else '#f2f4f7', width))
    tmp = dst + '.raw.png'
    args = [CHROME, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + PROFILE,
            '--force-device-scale-factor=1', '--window-size=%d,3400' % max(width, 600), '--screenshot=' + tmp, 'file:///' + wrap.replace(chr(92), '/')]
    subprocess.run(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=90)
    im = Image.open(tmp).convert('RGB').crop((0, 0, width, 3300))
    bg = im.getpixel((2, im.height - 3))
    diff = ImageChops.difference(im, Image.new('RGB', im.size, bg)).convert('L').point(lambda v: 255 if v > 6 else 0)
    box = diff.getbbox()
    bottom = min(im.height, (box[3] if box else im.height) + 28)
    im = im.crop((0, 0, im.width, bottom))
    im = im.quantize(colors=160, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
    im.save(dst, optimize=True)
    for f in (tmp, inner, wrap):
        os.remove(f)


def build_index(langs):
    css = '''
:root{--bg:#f4f5f8;--card:#fff;--ink:#1f2433;--mut:#5b6275;--line:#dde1ea;--acc:#2b59c3;--tag1:#e6efff;--tag2:#fff0dc}
@media (prefers-color-scheme:dark){:root{--bg:#12141b;--card:#1b1e28;--ink:#e8ebf3;--mut:#9aa3b8;--line:#2d3243;--acc:#8fb0ff;--tag1:#1d2c4d;--tag2:#4a3516}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,Segoe UI,Roboto,Arial,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:24px 16px 64px}h1{font-size:28px;margin:0 0 6px}h2{font-size:21px;margin:38px 0 12px}
p.lead{color:var(--mut);margin:0 0 18px;max-width:760px}a{color:var(--acc)}
.tabs{display:flex;gap:8px;margin:16px 0}.tabs a{padding:6px 14px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--ink);background:var(--card)}
.item{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin:0 0 16px}
.item h3{margin:0 0 4px;font-size:17px}.meta{color:var(--mut);font-size:14px;margin:0 0 12px;word-break:break-word}
.tag{display:inline-block;font-size:12px;font-weight:700;padding:1px 9px;border-radius:999px;margin-right:8px;vertical-align:1px}.t-cliente{background:var(--tag1)}.t-staff{background:var(--tag2)}
.shots{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}@media(max-width:900px){.shots{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.shots{grid-template-columns:1fr}}
.shots figure{margin:0}.shots figcaption{font-size:12px;color:var(--mut);margin-top:4px}
.shots .ph{height:340px;overflow:hidden;border:1px solid var(--line);border-radius:8px;background:#fff}.shots img{width:100%;display:block}
.links{margin-top:10px;font-size:14px}.links a{margin-right:14px}
'''
    out = ['<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           '<title>Anteprime email WebG Booking</title><style>%s</style></head><body><div class="wrap">' % css,
           '<h1>Anteprime delle email di WebG Booking 0.34.3</h1>',
           '<p class="lead">Tutte le email del plugin, cliente e staff, con lo stesso layout. Sono le email vere, generate dai flussi veri su un sito di prova '
           '(dati inventati). Ogni scheda mostra chiaro e scuro, sul computer e sul telefono; un clic apre l\'immagine intera. '
           'Il testo semplice di ogni email (per chi non vede l\'HTML) e\' linkato sotto.</p>',
           '<div class="tabs">' + ''.join('<a href="#lang-%s">%s</a>' % (l, {'it': 'Italiano', 'en': 'English', 'webg': 'Marchio WebG'}.get(l, l)) for l in langs) + '</div>']
    for l in langs:
        man = json.load(open(os.path.join(BASE, l, 'manifest.json'), encoding='utf-8'))
        out.append('<h2 id="lang-%s" style="font-size:25px">%s</h2>' % (l, {'it': 'Italiano', 'en': 'English', 'webg': 'Marchio WebG'}.get(l, l)))
        group = None
        for m in man:
            if m.get('missing'):
                continue
            if m['group'] != group:
                group = m['group']
                out.append('<h2>%s</h2>' % H.escape(group.split(' ', 1)[1]))
            slug = m['slug']
            shots = ''
            for v, w, d in VARIANTS:
                p = '%s/%s.%s.png' % (l, slug, v)
                if os.path.exists(os.path.join(BASE, p)):
                    shots += '<figure><a href="%s"><div class="ph"><img loading="lazy" src="%s" alt="%s"></div></a><figcaption>%s</figcaption></figure>' % (p, p, H.escape(m['label'] + ' ' + LABELS[v]), LABELS[v])
            out.append('<div class="item"><h3><span class="tag t-%s">%s</span>%s</h3><p class="meta">Oggetto: %s<br>A: %s%s</p><div class="shots">%s</div>'
                       '<p class="links"><a href="%s/%s.html">Apri l\'HTML</a><a href="%s/%s.txt">Testo semplice</a></p></div>' % (
                           m['audience'], 'cliente' if m['audience'] == 'cliente' else 'staff', H.escape(m['label']), H.escape(m['subject']), H.escape(m['rcpt']),
                           ' &middot; con allegato calendario (.ics)' if m.get('attach_ics') else '', shots, l, slug, l, slug))
    out.append('</div></body></html>')
    open(os.path.join(BASE, 'index.html'), 'w', encoding='utf-8').write('\n'.join(out))


def main():
    index_only = '--index' in sys.argv
    langs = [a for a in sys.argv[1:] if not a.startswith('--')] or ['it', 'en']
    for l in ([] if index_only else langs):
        man = json.load(open(os.path.join(BASE, l, 'manifest.json'), encoding='utf-8'))
        for m in man:
            if m.get('missing'):
                continue
            src = os.path.join(BASE, l, m['slug'] + '.html')
            variants = VARIANTS if l in ('it', 'webg') else VARIANTS[:1] + VARIANTS[3:4]
            for v, w, d in variants:
                dst = os.path.join(BASE, l, '%s.%s.png' % (m['slug'], v))
                shot(src, dst, w, d)
            print('rendered', l, m['slug'])
    build_index(langs)
    shutil.rmtree(PROFILE, ignore_errors=True)
    print('INDEX', os.path.join(BASE, 'index.html'))


main()
