"""0.35.1 (same check as 0.35.0, against the previous release): the elements configured by hand are rendered EXACTLY as before.
Renders every test element (mock/configs.json: free, paid, with questions, staff, webhook, Brevo, hostile text ...) with the previous
package and with the new one, in the same container, and compares what the page carries: the widget configuration, the content of the
sealed form token and of the availability token (minus the time they were made), and the markup around them.
Usage: python t_v350_same.py <port> <container-prefix> [old-zip] [new-zip]   (default old: dist/pkg_webgbooking-0.35.0.zip, new: the newest in dist/)
Leaves the NEW package installed. Run it on a container where the new package is installed (redeploy.sh) and the old zip is in dist/."""
import sys, os, re, json, html, glob, base64, tempfile, shutil
from wgbtest import *

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
args = [a for a in sys.argv[1:] if not a.startswith('--')]
OLD = args[2] if len(args) > 2 else os.path.join(ROOT, 'dist', 'pkg_webgbooking-0.35.0.zip')
zips = sorted(glob.glob(os.path.join(ROOT, 'dist', 'pkg_webgbooking-*.zip')), key=lambda p: [int(x) for x in re.findall(r'\d+', os.path.basename(p))])
NEW = args[3] if len(args) > 3 else zips[-1]
OUT = tempfile.mkdtemp(prefix='wgb-same-')


def install(zp):
    sh(['docker', 'cp', zp, J + ':/tmp/pkg.zip'])
    out = sh(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'extension:install', '--path=/tmp/pkg.zip'])
    return 'successfully' in out


def render_and_collect(tag):
    jx('rm -rf /var/www/html/wgbtest; php /tmp/mock/render_el.php /tmp/mock/configs.json >/dev/null; chmod -R a+rX /var/www/html/wgbtest')
    dst = os.path.join(OUT, tag)
    os.makedirs(dst)
    sh(['docker', 'cp', J + ':/var/www/html/wgbtest/.', dst])
    pages = {}
    for f in sorted(os.listdir(dst)):
        if f.endswith('.html') and not f.startswith('_') and f != 'thanks.html':
            pages[f[:-5]] = open(os.path.join(dst, f), encoding='utf-8').read()
    return pages


def tokens_of(pages):
    """Every sealed form token of every page, decoded inside the container in one go."""
    toks = []
    for n, h in pages.items():
        for m in re.finditer(r'data-wgb="([^"]+)"', h):
            toks.append((n, json.loads(html.unescape(m.group(1)))['form']))
    fp = os.path.join(OUT, 'toks.json')
    json.dump([t for _, t in toks], open(fp, 'w'))
    sh(['docker', 'cp', fp, J + ':/tmp/toks.json'])
    dec = json.loads(jx('php /tmp/mock/decforms.php /tmp/toks.json').strip().splitlines()[-1])
    return {i: (toks[i][0], dec[i]) for i in range(len(toks))}


def ev_of(token):
    return json.loads(base64.b64decode(token.split('.')[0]))


def normalise(pages):
    """Pages with what is random or time-bound taken out."""
    out = {}
    cfgs = {}
    for n, h in pages.items():
        lst = []

        def keep(m):
            c = json.loads(html.unescape(m.group(1)))
            c['form'] = 'F'
            for k in ('locPhone', 'fPhoneNeeded', 'privacyHtml', 'phoneHint'):   # 0.35.1: the wording of the phone call and the privacy link changed on purpose
                c.get('labels', {}).pop(k, None)
            e = ev_of(c['ev'])
            e.pop('iat', None)
            c['ev'] = e
            lst.append(c)
            return 'data-wgb="X"'
        body = re.sub(r'data-wgb="([^"]+)"', keep, h)
        body = re.sub(r'wgb-[0-9a-f]{8}', 'wgb-ID', body)
        body = re.sub(r'\?\d+\.\d+\.\d+', '?V', body)
        out[n] = body
        cfgs[n] = lst
    return out, cfgs


check('S0 the previous package installs', install(OLD))
old_pages = render_and_collect('old')
old_tok = tokens_of(old_pages)
check('S0b the new package installs over it', install(NEW))
new_pages = render_and_collect('new')
new_tok = tokens_of(new_pages)

check('S1 the same test elements are rendered by both packages (%d)' % len(old_pages), set(old_pages) == set(new_pages) and len(old_pages) >= 30, (sorted(set(old_pages) ^ set(new_pages)), len(old_pages)))
o_html, o_cfg = normalise(old_pages)
n_html, n_cfg = normalise(new_pages)
bad_markup = [n for n in o_html if o_html[n] != n_html.get(n)]
check('S2 the markup around the widget is identical, element by element', not bad_markup, bad_markup[:5])
bad_cfg = [n for n in o_cfg if o_cfg[n] != n_cfg.get(n)]
check('S3 the widget configuration (labels, price text, duration, places, questions, availability token content) is identical', not bad_cfg, bad_cfg[:5])
diffs = []
for i, (n, a) in old_tok.items():
    b = new_tok[i][1]
    a = dict(a)
    b = dict(b)
    a.pop('iat', None)
    b.pop('iat', None)
    if a != b:
        diffs.append((n, [k for k in set(a) | set(b) if a.get(k) != b.get(k)]))
check('S4 the sealed form token of every element has the same content (questions, event, price, staff, webhook, Brevo, reminders ...), no new key for hand-made elements (%d tokens)' % len(old_tok), len(old_tok) == len(new_tok) and not diffs, diffs[:5])
check('S5 no hand-made element carries a saved type in its token', all('etype' not in d and 'src' not in d for _, d in new_tok.values()))
shutil.rmtree(OUT, ignore_errors=True)
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
