"""0.34.2: the five low residues of the security review. Each check FAILS on 0.34.1 and passes on 0.34.2. Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json
from wgbtest import *

reset_env()
SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'src', 'plg_system_webgbooking')


def php_json(cmd):
    out = jx(cmd).strip().splitlines()
    return json.loads(out[-1]) if out else {}


# 1. curl only, no fall back
r = php_json('php -d disable_functions=curl_init /tmp/mock/nocurl.php')
check('Q1 curl not usable: the pinned HTTP client is NOT built (the call is refused, no fall back to the stream transport)', r.get('curl_usable') is False and r.get('client') is None, r)
r = php_json('php /tmp/mock/nocurl.php')
check('Q1b curl usable: the client is built (normal case)', r.get('curl_usable') is True and r.get('client') is not None, r)
code = open(os.path.join(SRC, 'src', 'Extension', 'Webgbooking.php'), encoding='utf-8').read()
check("Q1c the pinned client asks for the curl transport only (getHttp(..., ['curl']))", re.search(r"\],\s*\['curl'\]\);", code) is not None, '')
check('Q1d both callers handle the missing client: the webhook is refused (staff told), the newsletter webhook is skipped with a log line', "WHY_NOCURL" in code and "LOG_NL_NOCURL" in code, '')

# 2. httpUrlOk
urls = ['https://hooks.example.com/path?x=1', 'http://hooks.example.com/a', 'https://example.com' + chr(92) + '@evil.example/x', 'https://good.example@evil.example/x', 'https://example.com/path#frag', 'https://example.com/a@b', 'ftp://example.com/x', 'https://exa mple.com/x', 'javascript:alert(1)']
res = php_json("php /tmp/mock/ipcheck.php url " + ' '.join("'%s'" % u for u in urls))
check('Q2 a normal http(s) address is accepted', res.get(urls[0]) is True and res.get(urls[1]) is True, res)
bad = [u for u in urls[2:] if res.get(u)]
check('Q2b backslash, "@", "#", other schemes and spaces are refused', not bad, bad)

# 3. IPv4 written inside IPv6
res = php_json("php /tmp/mock/ipcheck.php norm ::ffff:173.245.48.5 ::FFFF:8.8.8.8 2606:4700::1 ::ffff:7f00:1 173.245.48.5 not-an-ip")
check('Q3 ::ffff:a.b.c.d is read as a.b.c.d (the other forms are left as they are)', res.get('::ffff:173.245.48.5') == '173.245.48.5' and res.get('::FFFF:8.8.8.8') == '8.8.8.8' and res.get('2606:4700::1') == '2606:4700::1' and res.get('173.245.48.5') == '173.245.48.5', res)
cidr = php_json("php /tmp/mock/ipcheck.php cidr 173.245.48.5 173.245.48.0/20")
check('Q3b ...so a Cloudflare address that arrives as ::ffff:173.245.48.5 falls in the IPv4 range', cidr is True and res.get('::ffff:173.245.48.5') == '173.245.48.5', cidr)

# 4 + 5: the wording
en = open(os.path.join(SRC, 'language', 'en-GB', 'plg_system_webgbooking.ini'), encoding='utf-8').read()
it = open(os.path.join(SRC, 'language', 'it-IT', 'plg_system_webgbooking.ini'), encoding='utf-8').read()


def val(t, k):
    m = re.search(r'^%s="(.*)"$' % k, t, re.M)
    return m.group(1) if m else ''


check('Q4 the HOSTS: help warns against hosts shared between customers (hooks.zapier.com, hook.*.make.com) and points to the URL: line, in both languages',
      all(('zapier' in v and 'make.com' in v and 'URL:' in v) for v in (val(en, 'PLG_SYSTEM_WEBGBOOKING_WHP_DESC'), val(it, 'PLG_SYSTEM_WEBGBOOKING_WHP_DESC'))), '')
check('Q5 the Brevo "every booking" option says it creates a contact for anyone who books, even without marketing consent, in both languages',
      'even without marketing consent' in val(en, 'PLG_SYSTEM_WEBGBOOKING_EL_EVERY_BOOKING_CONTACT_ONLY') and 'senza consenso marketing' in val(it, 'PLG_SYSTEM_WEBGBOOKING_EL_EVERY_BOOKING_CONTACT_ONLY')
      and 'even without marketing consent' in val(en, 'PLG_SYSTEM_WEBGBOOKING_EL_NEWSLETTER_TICK_THE_CONTACT') and 'senza consenso marketing' in val(it, 'PLG_SYSTEM_WEBGBOOKING_EL_NEWSLETTER_TICK_THE_CONTACT'), '')
d = php_json('php /tmp/mock/elcheck.php it-IT')
check('Q5b ...and the builder shows it in Italian (no unresolved key)', d.get('unresolved') == [] and 'Telefonata' in ' '.join(d.get('meet_option', [])), d.get('unresolved'))

# ============================================================================ 6. admin diagnostics: how the visitor address is detected
print('--- 6. IP diagnostics line')
cases = [('173.245.48.5', '-', 'noheader', 'v4', '8.5'), ('::ffff:173.245.48.5', '198.51.100.9', 'cloudflare', 'v4mapped', '0.9'), ('2606:4700::1', '2001:db8::5', 'cloudflare', 'v6', '::5'),
         ('8.8.8.8', '-', 'direct', 'v4', '8.8'), ('8.8.8.8', '1.2.3.4', 'unrecognized', 'v4', '8.8'), ('not-an-ip', '-', 'direct', 'unknown', ''), ('::ffff:8.8.8.8', '1.2.3.4', 'unrecognized', 'v4mapped', '8.8'), ('8.8.8.8', '8.8.8.8', 'restored', 'v4', '8.8'), ('::ffff:8.8.8.8', '8.8.8.8', 'restored', 'v4mapped', '8.8')]
res = php_json("php /tmp/mock/ipcheck.php diag " + ' '.join("'%s' '%s'" % (c[0], c[1]) for c in cases))
for rem, cf, mode, fmt, tail in cases:
    d = res.get(rem + '|' + cf, {})
    check('D1 REMOTE_ADDR %s, header %s -> %s, %s, tail "%s"' % (rem, cf, mode, fmt, tail), d.get('mode') == mode and d.get('fmt') == fmt and d.get('tail') == tail, d)
check('D1b only three characters of the detected address come out (never the address, never a hash)', all(len(v.get('tail', '')) <= 3 and set(v) == {'mode', 'fmt', 'tail'} for v in res.values()), res)

from t_admin_lib import admin_login, make_admin
import urllib.request
adm = admin_login()
LIST = BASE + '/administrator/index.php?option=com_webgbooking&view=bookings'


def diag_line(hdr=None):
    req = urllib.request.Request(LIST, headers=hdr or {})
    page = adm.op.open(req, timeout=60).read().decode('utf-8', 'replace')
    m = re.search(r'id="wgb-ipdiag"[^>]*>(.*?)</div>', page, re.S)
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', m.group(1))).strip() if m else None


line = diag_line()
check('D2 the bookings list of a component administrator shows the line: direct, IPv4, last three characters', line is not None and 'Rilevamento' not in (line or '') and 'direct' in line and 'IPv4' in line and re.search(r'ends with: \.\.\.\S{3}$', line) is not None, line)
check('D2b ...and neither the full address nor a hash is on the screen', line is not None and not re.search(r'\d+\.\d+\.\d+\.\d+', line.replace('IPv4', '')) and not re.search(r'[0-9a-f]{16,}', line), line)
line = diag_line({'CF-Connecting-IP': '203.0.113.77'})
check('D3 a Cloudflare header from a connection OUTSIDE the ranges: "Cloudflare not recognised", the header is ignored (tail is the connection one)', line is not None and 'not recognised' in line and '.77' not in line.split('ends with:')[-1], line)
jx("php /tmp/mock/init.php 'cf_ranges_extra=172.16.0.0/12 10.0.0.0/8 192.168.0.0/16' >/dev/null")
line = diag_line({'CF-Connecting-IP': '203.0.113.77'})
check('D4 the same header from a connection inside a listed Cloudflare range: "through Cloudflare", the tail is the header address', line is not None and 'through Cloudflare' in line and line.endswith('...' + '.77'), line)
line = diag_line()
check('D5 a Cloudflare-range connection with no header: says every visitor shows the edge address', line is not None and 'without the CF-Connecting-IP header' in line, line)
vsrc = open(os.path.join(SRC, '..', 'com_webgbooking', 'admin', 'src', 'View', 'Bookings', 'HtmlView.php'), encoding='utf-8').read()
check('D6 the line is built only for core.admin (checked in the view)', "authorise('core.admin', 'com_webgbooking')" in vsrc and 'ipDiagnostic' in vsrc, '')
jx("php /tmp/mock/init.php >/dev/null")

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
