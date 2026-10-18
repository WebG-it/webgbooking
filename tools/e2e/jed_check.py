#!/usr/bin/env python3
"""Run the rules of the official Joomla Extensions Directory checker (github.com/joomla-extensions/jedchecker, develop,
the current official code) on the package zip, inside the throw-away Joomla containers, and save the report.

JED Checker has no command line: it is an administrator component. Here its OWN rules (RuleDiscovery + each rule's
check()) run through a small harness (mock/jed_harness.php) inside a real Joomla, on the package unpacked the way the
JED unpacks it (the package, and every extension inside it unpacked under packages/<name>/).

Usage:
  python tools/e2e/jed_check.py [--zip dist/pkg_webgbooking-X.zip] [--containers wgbtest-5-j wgbtest-6-j] [--sha <jedchecker commit>] [--max-errors 0]
Writes tools/e2e/reports/jed-<version>-J<major>.md and exits 1 when the errors exceed --max-errors.
Needs: docker, the test containers up (tools/e2e/up.sh), network access to github.com (to fetch the checker).
"""
import argparse, glob, io, json, os, re, shutil, subprocess, sys, tarfile, tempfile, urllib.request, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
ENV = dict(os.environ, MSYS_NO_PATHCONV='1', MSYS2_ARG_CONV_EXCL='*')


def sh(cmd, timeout=900):
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=ENV, encoding='utf-8', errors='replace')
    return r.returncode, (r.stdout or '') + (r.stderr or '')


def http(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'webgbooking-jed-check'})
    return urllib.request.urlopen(req, timeout=120).read()


def newest_zip():
    zips = sorted(glob.glob(os.path.join(ROOT, 'dist', 'pkg_webgbooking-*.zip')), key=lambda p: [int(x) for x in re.findall(r'\d+', os.path.basename(p))])
    return zips[-1]


def unpack(zpath, dest):
    """The package, then each extension zip inside it under packages/<name>/ (what a reviewer sees)."""
    with zipfile.ZipFile(zpath) as z:
        z.extractall(dest)
    pk = os.path.join(dest, 'packages')
    if os.path.isdir(pk):
        for f in sorted(os.listdir(pk)):
            if f.endswith('.zip'):
                sub = os.path.join(pk, f[:-4])
                os.makedirs(sub, exist_ok=True)
                with zipfile.ZipFile(os.path.join(pk, f)) as z:
                    z.extractall(sub)
                os.remove(os.path.join(pk, f))


def fetch_checker(dest, sha):
    if not sha:
        sha = json.loads(http('https://api.github.com/repos/joomla-extensions/jedchecker/commits/develop'))['sha']
    tgz = http('https://github.com/joomla-extensions/jedchecker/archive/%s.tar.gz' % sha)
    with tarfile.open(fileobj=io.BytesIO(tgz), mode='r:gz') as tf:
        tf.extractall(dest, filter='data')
    top = os.path.join(dest, os.listdir(dest)[0])
    return os.path.join(top, 'src', 'administrator', 'components', 'com_jedchecker', 'src'), os.path.join(top, 'src', 'administrator', 'language', 'en-GB', 'en-GB.com_jedchecker.ini'), sha


def lang_map(ini):
    m = {}
    try:
        for line in open(ini, encoding='utf-8'):
            x = re.match(r'^([A-Z0-9_#]+)="(.*)"\s*$', line)
            if x:
                m[x.group(1)] = x.group(2)
    except OSError:
        pass
    return m


EXTRA = {
    'COM_JEDCHECKER_ERROR_XML_LICENCE_NOT_FOUND': 'the manifest has no <licence> element (British spelling, the JED rule PH3)',
    'COM_JEDCHECKER_ERROR_XML_LICENCE_NOT_GPL': 'the <licence> element does not mention GPL',
    'COM_JEDCHECKER_LANG_TRANSLATION_ERROR': 'language file line is not NAME=\"value\" with escaped inner quotes',
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--zip')
    ap.add_argument('--containers', nargs='+', default=['wgbtest-5-j', 'wgbtest-6-j'])
    ap.add_argument('--sha', default='')
    ap.add_argument('--max-errors', type=int, default=0)
    a = ap.parse_args()
    zpath = os.path.abspath(a.zip) if a.zip else newest_zip()
    ver = re.search(r'pkg_webgbooking-([0-9.]+)\.zip', zpath).group(1)
    tmp = tempfile.mkdtemp(prefix='jedcheck_')
    try:
        pkgdir = os.path.join(tmp, 'pkg')
        unpack(zpath, pkgdir)
        src, ini, sha = fetch_checker(os.path.join(tmp, 'jed'), a.sha)
        lm = dict(EXTRA, **lang_map(ini))
        os.makedirs(os.path.join(HERE, 'reports'), exist_ok=True)
        worst = 0
        for c in a.containers:
            rc, out = sh(['docker', 'exec', c, 'php', '-r', 'echo PHP_VERSION;'])
            php = out.strip()
            rc, out = sh(['docker', 'exec', c, 'sh', '-c', "grep -o \"RELEASE = '[0-9.]*'\" /var/www/html/libraries/src/Version.php | head -1; grep -o \"MAJOR_VERSION = [0-9]*\" /var/www/html/libraries/src/Version.php | head -1; grep -o \"MINOR_VERSION = [0-9]*\" /var/www/html/libraries/src/Version.php | head -1; grep -o \"PATCH_VERSION = [0-9]*\" /var/www/html/libraries/src/Version.php | head -1"])
            nums = re.findall(r'= (\d+)', out)
            jver = '.'.join(nums[-3:]) if len(nums) >= 3 else '?'
            major = jver.split('.')[0]
            dst = '/var/www/html/administrator/components/com_jedchecker/src'
            sh(['docker', 'exec', c, 'rm', '-rf', dst, '/tmp/jedpkg'])
            sh(['docker', 'exec', c, 'mkdir', '-p', os.path.dirname(dst)])
            sh(['docker', 'cp', src, c + ':' + dst])
            sh(['docker', 'cp', pkgdir, c + ':/tmp/jedpkg'])
            sh(['docker', 'cp', os.path.join(HERE, 'mock', 'jed_harness.php'), c + ':/tmp/jed_harness.php'])
            rc, out = sh(['docker', 'exec', c, 'php', '/tmp/jed_harness.php', '/tmp/jedpkg', dst], 900)
            m = re.search(r'JSONSTART(.*)JSONEND', out, re.S)
            if not m:
                print('J%s: the checker produced no result:\n%s' % (major, out[-400:]))
                worst = max(worst, 999)
                continue
            d = json.loads(m.group(1))
            voci = [re.sub(r'COM_JEDCHECKER_[A-Z0-9_]+(?:#\d+)?', lambda x: lm.get(x.group(0), x.group(0)), v.replace('/tmp/jedpkg', '')) for v in d['voci']]
            lines = ['# JED Checker report: WebG Booking %s' % ver, '',
                     'Checker: joomla-extensions/jedchecker develop, commit %s (%d rules). Joomla %s, PHP %s. Package: %s.' % (sha[:7], len(d['regole']), jver, php, os.path.basename(zpath)), '',
                     '**%d errors, %d warnings, %d compatibility notes, %d rule exceptions.**' % (d['errori'], d['avvisi'], d['compat'], d.get('eccezioni', 0)), '']
            for v in voci:
                lines.append('- ' + v)
            rp = os.path.join(HERE, 'reports', 'jed-%s-J%s.md' % (ver, major))
            open(rp, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines) + '\n')
            print('J%s (Joomla %s, PHP %s): %d errors, %d warnings, %d compat, %d exceptions -> %s' % (major, jver, php, d['errori'], d['avvisi'], d['compat'], d.get('eccezioni', 0), os.path.relpath(rp, ROOT)))
            worst = max(worst, d['errori'] + d.get('eccezioni', 0))
        return 1 if worst > a.max_errors else 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
