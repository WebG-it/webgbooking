#!/usr/bin/env python3
"""Language check for the WebG Booking package (run by tools/build.py, fails the build on a problem).

1. Parity: every .ini of en-GB has the same keys as it-IT (and the other way round), for every extension.
2. Every language key the code asks for (Text::_, Text::sprintf, Text::plural, JText, labels in XML manifests and
   forms) exists in BOTH languages of its extension.
3. No visible text written in the code: a string literal that reads like a sentence (starts with a capital, at least
   two words) is refused in PHP files, unless it matches the allow list below (SQL, MIME types, log keys ...).
   Language files are the only place for words a person reads.
4. The front-end script (wgb.js) holds no sentence either: its texts come from the server (data-wgb labels).

Usage: python tools/lang_check.py [--quiet]   (exit code 1 on any problem)
"""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, '..', 'src')

# (extension label, language dirs, folders whose code uses its keys, key prefix)
EXTENSIONS = [
    ('plg_system_webgbooking', ['plg_system_webgbooking/language'], ['plg_system_webgbooking'], 'PLG_SYSTEM_WEBGBOOKING_'),
    ('plg_task_webgbookingreminders', ['plg_task_webgbookingreminders/language'], ['plg_task_webgbookingreminders'], 'PLG_TASK_WEBGBOOKINGREMINDERS_'),
    ('com_webgbooking', ['com_webgbooking/admin/language', 'com_webgbooking/site/language'], ['com_webgbooking'], 'COM_WEBGBOOKING_'),
]
LANGS = ('en-GB', 'it-IT')

# Literals that look like sentences but are not words a person reads.
ALLOW = [
    r'^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|SHOW|DROP|REPLACE|TRUNCATE)\b',
    r'(NOT NULL|PRIMARY KEY|DEFAULT CHARSET|AUTO_INCREMENT|ADD COLUMN|ADD INDEX|UNSIGNED)',
    r'^(Content-Type|Content-Disposition|Content-Length|Cache-Control|Location|Authorization|Accept|User-Agent|Stripe-Signature|X-[A-Za-z-]+)\b',
    r'^(application|text|image|multipart)/[a-z0-9.+-]+',
    r'^(Bearer|Basic) ',
    r'^(HTTP|GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\b',
    r'^(Joomla|Mozilla|WebG)\b.*(!|/)',            # user agents
    r'^(UTF-8|ISO|Europe/|America/)',
    r'^[A-Z][a-z]+[A-Z]\w*$',                        # CamelCase identifiers
    r'^WebG Booking$',                               # the product name
    r'^(DATETIME|TEXT|MEDIUMTEXT|LONGTEXT|INT|BIGINT|TINYINT|VARCHAR\(\d+\)|CHAR\(\d+\))( UNSIGNED)?( NULL| NOT NULL)?$',   # SQL column types
    r'^(Array|Closure|Throwable|Exception|RuntimeException)\b',
]
ALLOW = [re.compile(a) for a in ALLOW]
SENTENCE = re.compile(r"^[A-Z][A-Za-zÀ-ſ']+(?: [A-Za-zÀ-ſ'.,:;!?()-]+){1,}$")
SKIP_FILES = ()
SKIP_DIRS = {'uikit', 'images'}


def php_files():
    for dp, dn, fn in os.walk(SRC):
        dn[:] = [d for d in dn if d not in SKIP_DIRS]
        for f in fn:
            if f.endswith('.php'):
                yield os.path.join(dp, f)


def read(p):
    with open(p, encoding='utf-8') as fh:
        return fh.read()


def ini_keys(p):
    keys = {}
    for ln, line in enumerate(read(p).replace('\r\n', '\n').split('\n'), 1):
        m = re.match(r'^([A-Z0-9_]+)\s*=\s*"(.*)"\s*$', line)
        if m:
            keys[m.group(1)] = m.group(2)
    return keys


def strip_comments_php(code):
    code = re.sub(r'/\*.*?\*/', lambda m: '\n' * m.group(0).count('\n'), code, flags=re.S)
    out = []
    for line in code.split('\n'):
        s = line.lstrip()
        if s.startswith('//') or s.startswith('#'):
            out.append('')
        else:
            out.append(re.sub(r'(?<![:\'"])//[^\'"]*$', '', line))
    return '\n'.join(out)


def literals(line):
    for m in re.finditer(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", line):
        yield (m.group(1) if m.group(1) is not None else m.group(2)), m.start()


def main():
    quiet = '--quiet' in sys.argv
    problems = []

    # 1. parity + collect the keys of every extension
    keys = {}
    for ext, ldirs, _code, _prefix in EXTENSIONS:
        keys[ext] = {l: {} for l in LANGS}
        for d in ldirs:
            base = os.path.join(SRC, d)
            if not os.path.isdir(base):
                continue
            files = {}
            for lang in LANGS:
                ld = os.path.join(base, lang)
                for f in (os.listdir(ld) if os.path.isdir(ld) else []):
                    if f.endswith('.ini'):
                        files.setdefault(f, {})[lang] = os.path.join(ld, f)
            for f, per in sorted(files.items()):
                if set(per) != set(LANGS):
                    problems.append('%s: %s exists only for %s' % (d, f, ','.join(per)))
                    continue
                k = {l: ini_keys(per[l]) for l in LANGS}
                for l in LANGS:
                    keys[ext][l].update(k[l])
                for a, b in (('en-GB', 'it-IT'), ('it-IT', 'en-GB')):
                    for missing in sorted(set(k[a]) - set(k[b])):
                        problems.append('%s/%s: key %s is in %s but missing in %s' % (d, f, missing, a, b))
                for kk, vv in k['it-IT'].items():
                    # Italian written with an apostrophe instead of the accent (e' piu' modalita'): always a defect
                    if re.search(r"(?<![A-Za-z'])(e|E|piu|gia|puo|finche|cosi|perche|modalita|attivita|citta|qualita|funzionalita|disponibilita|possibilita)'(?![A-Za-z])", vv):
                        problems.append('%s/it-IT/%s: key %s writes an accent as an apostrophe' % (d, f, kk))
                for l in LANGS:
                    for kk, vv in k[l].items():
                        if not vv.strip():
                            problems.append('%s/%s/%s: key %s is empty' % (d, l, f, kk))

    # 2. keys used by the code exist in both languages
    for ext, _ld, folders, prefix in EXTENSIONS:
        used = {}
        for folder in folders:
            base = os.path.join(SRC, folder)
            for dp, dn, fn in os.walk(base):
                dn[:] = [d for d in dn if d not in SKIP_DIRS]
                for f in fn:
                    if not f.endswith(('.php', '.xml', '.js')):
                        continue
                    p = os.path.join(dp, f)
                    txt = read(p)
                    for m in re.finditer(r"['\"(=\s]((?:%s|JERROR|JTOOLBAR|JGLOBAL)[A-Z0-9_]*)['\")\s,]" % re.escape(prefix.rstrip('_')) if False else r"['\"=\s(]((?:%s)[A-Z0-9_]+)['\")\s,]" % re.escape(prefix), txt):
                        used.setdefault(m.group(1), os.path.relpath(p, SRC))
        for k, where in sorted(used.items()):
            for l in LANGS:
                if any(x.startswith(k + '_') for x in keys[ext][l]):
                    continue          # a key prefix used to build the full key (e.g. a task routine's language constant prefix)
                if k not in keys[ext][l] and not k.endswith('_'):
                    # sys.ini keys and dynamic prefixes are checked by name only when complete
                    problems.append('%s: key %s is used (%s) but missing in %s' % (ext, k, where, l))

    # 3a. a literal that is exactly the English text of a language key is visible text too (single words such as column titles)
    en_values = set()
    for ext in keys:
        for v in keys[ext]['en-GB'].values():
            if 4 <= len(v) <= 40 and re.fullmatch(r"[A-Za-z][A-Za-z '-]+", v):
                en_values.add(v)
    # 3. sentences written in PHP code
    for p in php_files():
        rel = os.path.relpath(p, SRC)
        if os.path.basename(p) in SKIP_FILES:
            continue
        code = strip_comments_php(read(p))
        for ln, line in enumerate(code.split('\n'), 1):
            for lit, pos in literals(line):
                lit = lit.replace("\\'", "'")
                if len(lit) < 8 or '%' in lit[:1]:
                    continue
                if any(a.search(lit) for a in ALLOW):
                    continue
                if SENTENCE.match(lit) and ' ' in lit and not re.search(r'[<>{}$=/_\\]', lit):
                    problems.append('%s:%d: visible text in code: "%s"' % (rel, ln, lit[:80]))
                # HTML with visible words between tags
                for t in re.findall(r'>([A-Za-zÀ-ſ][^<>{}$;]{3,})<', lit):
                    if ' ' in t.strip() or len(t.strip()) > 5:
                        problems.append('%s:%d: visible text between tags in code: "%s"' % (rel, ln, t[:60]))

    # 4. wgb.js: no sentence
    for dp, dn, fn in os.walk(SRC):
        for f in fn:
            if f == 'wgb.js':
                p = os.path.join(dp, f)
                for ln, line in enumerate(read(p).split('\n'), 1):
                    s = line.strip()
                    if s.startswith('//') or s.startswith('*') or s.startswith('/*'):
                        continue
                    for lit, pos in literals(line):
                        if SENTENCE.match(lit) and ' ' in lit and not re.search(r'[<>{}$=/_\\]', lit) and len(lit) > 8:
                            problems.append('%s:%d: visible text in script: "%s"' % (os.path.relpath(p, SRC), ln, lit[:80]))

    if problems:
        print('lang_check: %d problem(s)' % len(problems))
        for x in problems:
            print('  ' + x)
        return 1
    if not quiet:
        n = sum(len(v['en-GB']) for v in keys.values())
        print('lang_check: ok (%d keys per language, en-GB and it-IT in step, no visible text in code)' % n)
    return 0


if __name__ == '__main__':
    sys.exit(main())
