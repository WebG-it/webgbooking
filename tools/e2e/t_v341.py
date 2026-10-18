"""0.34.1: the sentinella's ten points. Each check FAILS on 0.34.0 and passes on 0.34.1.
Needs WGB_E2E_ADMIN_PASS. Keys and header values are random throwaway strings; Brevo and the webhook receiver are mocks."""
import sys, os, re, json, time, urllib.request, urllib.error
from wgbtest import *
import t_pay_lib as tp
from t_pay_lib import R
from t_admin_lib import make_admin, post_pairs

reset_env()
adm, EDIT, save_options, opts = make_admin()
D = 6
LOG = '/var/www/html/administrator/logs/webgbooking.php'
BKEY = 'xkeysib-' + R(40)
S1, S2, S3 = 'sec' + R(18), 'sec' + R(18), 'sec' + R(18)
NL = chr(92) + 'n'
STAFF = 'staff@wgbtest.invalid'
SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'src')


def setup(**kw):
    jx("rm -f /tmp/gmock/log.jsonl /tmp/gmock/brevo.json /tmp/gmock/brevo_fail /tmp/gmock/brevo_attrs /tmp/gmock/hook_fail /tmp/mail/*.eml /tmp/gmock/events.json; rm -f %s" % LOG)
    sql('DELETE FROM joom_webgbooking_bookings'); sql('DELETE FROM joom_webgbooking_kv')
    jx("printf %%s '%s' > /tmp/gmock/brevo_key; chmod 666 /tmp/gmock/brevo_key" % BKEY)
    base = {'brevo_key': BKEY,
            'whp1_name': 'crm', 'whp1_headers': 'Authorization: Bearer %s%sHOSTS: www.googleapis.com' % (S1, NL),
            'whp2_name': 'zap', 'whp2_headers': 'URL: https://www.googleapis.com/hook2%sX-Zap: %s' % (NL, S2),
            'whp3_name': 'zaphttp', 'whp3_headers': 'URL: http://www.googleapis.com/hook%sX-Zap: %s' % (NL, S3)}
    base.update(kw)
    jx("php /tmp/mock/init.php %s >/dev/null" % ' '.join("'%s=%s'" % (k, v) for k, v in base.items()))


def hooks(path='/hook'):
    return [l for l in gm_log() if l['host'] == 'www.googleapis.com' and l['path'] == path]


def brevo_calls():
    return [l for l in gm_log() if l['host'] == 'api.brevo.com']


def brevo_state():
    try:
        return json.loads(jx("curl -sk https://api.brevo.com/__mock/state"))
    except Exception:
        return {}


def logtxt():
    return jx('cat %s 2>/dev/null' % LOG)


def task_id():
    return sql("SELECT id FROM joom_scheduler_tasks WHERE type LIKE '%webg%' LIMIT 1")


def run_task():
    sql("UPDATE joom_scheduler_tasks SET locked=NULL, next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE type LIKE '%webg%'")
    jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=%s >/dev/null 2>&1' % task_id())


def rowdb(i, cols='status,fx_state,fx_steps'):
    out = sql('SELECT %s FROM joom_webgbooking_bookings WHERE id=%d' % (cols, i))
    return dict(zip(cols.split(','), out.split('\t')))


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h))


def staff_not_sent():
    return [m for m in mails_to(STAFF) if 'NOT sent' in m['subject']]


# ============================================================================ 1+3. a profile's headers only go where the profile says
print('--- 1+3. exfiltration of profile headers')
setup()
book('hookexfil', day(D), '09:00', email='x1@example.invalid', name='Esfiltra Uno')
b = last_id()
check('X1 element with its OWN address and a profile that does not allow it: NOTHING is sent (no call at all)', len(hooks()) == 0 and len(hooks('/hook2')) == 0, (len(hooks()), len(hooks('/hook2'))))
check('X2 ...the step is closed as an error, the log says why and the staff is told', '"webhook":"error"' in rowdb(b)['fx_steps'].replace(' ', '') and 'go only to' in logtxt() and len(staff_not_sent()) == 1, (rowdb(b)['fx_steps'], logtxt()[-200:]))
check('X2b the booking itself is fine', rowdb(b)['status'] == 'confirmed' and rowdb(b)['fx_state'] == 'done', rowdb(b))
setup(whp2_headers='URL: https://www.googleapis.com/hook2%sHOSTS: www.googleapis.com%sX-Zap: %s' % (NL, NL, S2))
book('hookexfil', day(D), '09:00', email='x2@example.invalid', name='Esfiltra Due')
h = hooks()
check('X3 the same element once the profile lists the host (HOSTS:): delivered, with the profile headers', len(h) == 1 and h[0]['headers'].get('x-zap') == S2, [x['headers'] for x in h][:1])
setup()
book('hookprof_url', day(D), '09:00', email='x3@example.invalid', name='Esfiltra Tre')
check('X4 no element address, the profile has its own (URL: line /hook2): goes there with the headers', len(hooks('/hook2')) == 1 and hooks('/hook2')[0]['headers'].get('x-zap') == S2, len(hooks('/hook2')))
setup(whp2_headers='URL: https://www.googleapis.com/hook%sX-Zap: %s' % (NL, S2))
book('hookprof_url', day(D), '09:00', email='x4@example.invalid', name='Esfiltra Quattro')
h = hooks()
check('X4b ...the profile address is /hook here: delivered with the profile header', len(h) == 1 and h[0]['headers'].get('x-zap') == S2, [x['headers'] for x in h][:1])
setup()
book('hookhttp', day(D), '09:00', email='x5@example.invalid', name='Esfiltra Cinque')
check('X5 headers over plain http are refused (https required when there are headers)', len(hooks()) == 0 and 'https' in logtxt() and len(staff_not_sent()) == 1, logtxt()[-200:])
setup()
book('hookmissing', day(D), '09:00', email='x6@example.invalid', name='Esfiltra Sei')
check('X6 a profile that is named but does not exist: nothing is sent, not even to the plugin-wide address', len(hooks()) == 0 and len(staff_not_sent()) == 1, len(hooks()))
setup(booking_webhook='https://www.googleapis.com/hook', booking_webhook_profile='ghost')
book('def', day(D), '09:00', email='x7@example.invalid', name='Esfiltra Sette')
check('X7 the plugin-wide webhook names a profile that does not exist: nothing is sent', len(hooks()) == 0 and len(staff_not_sent()) == 1, len(hooks()))
setup(whp1_headers='g:' + 'QUJD' * 20, booking_webhook='https://www.googleapis.com/hook', booking_webhook_profile='crm')
book('def', day(D), '09:00', email='x8@example.invalid', name='Esfiltra Otto')
check('X8 a profile stored encrypted that cannot be decrypted (rotated Joomla secret): nothing is sent', len(hooks()) == 0 and len(staff_not_sent()) == 1, len(hooks()))
setup(booking_webhook='https://www.googleapis.com/hook', booking_webhook_profile='crm')
book('def', day(D), '09:00', email='x9@example.invalid', name='Esfiltra Nove')
h = hooks()
check('X9 the plugin-wide profile still goes to the plugin-wide address', len(h) == 1 and h[0]['headers'].get('authorization') == 'Bearer ' + S1, [x['headers'] for x in h][:1])

# ============================================================================ 2. Brevo consent
print('--- 2. Brevo consent')
setup()
r = book('brevo_nobox', day(D), '09:00', email='c1@example.invalid', name='Casella Assente', newsletter='1')
check('C1 a forged newsletter=1 on an element WITHOUT the newsletter box is ignored: nothing reaches Brevo', r.get('ok') is True and not brevo_calls(), len(brevo_calls()))
setup(newsletter_webhook='https://www.googleapis.com/nl')
book('nlhook_nobox', day(D), '09:00', email='c2@example.invalid', name='Casella Assente', newsletter='1')
check('C2 ...and the plain newsletter webhook is not called either', not [l for l in gm_log() if l['path'] == '/nl'], '')
book('nlhook', day(D), '10:00', email='c3@example.invalid', name='Casella Presente', newsletter='1')
check('C2b with the box on the element and the tick, the newsletter webhook IS called', len([l for l in gm_log() if l['path'] == '/nl']) == 1, '')
setup()
book('brevo_nl', day(D), '09:00', email='c4@example.invalid', name='Spunta Si', newsletter='1')
st = brevo_state()
check('C3 tick + lists: double opt-in (never a direct subscription, never listIds on /v3/contacts)', len(st.get('doi', [])) == 1 and not st.get('contacts') and not [l for l in brevo_calls() if l['path'] == '/v3/contacts'], st)
setup()
r = book('brevo_nl_notpl', day(D), '09:00', email='c5@example.invalid', name='Senza Modello', newsletter='1')
b = last_id()
check('C4 tick + lists but NO double opt-in template: no subscription at all, error in the log, the staff is told, the booking works', r.get('ok') is True and not brevo_state().get('contacts') and not brevo_state().get('doi') and 'double opt-in' in logtxt() and any('Brevo' in m['subject'] for m in mails_to(STAFF)) and '"brevo":"error"' in rowdb(b)['fx_steps'].replace(' ', ''), (logtxt()[-200:], rowdb(b)['fx_steps']))
setup()
book('brevo1', day(D), '09:00', email='c6@example.invalid', name='Sempre Senza Liste', extra={'custom[cf0]': 'Torino'})
c = brevo_state().get('contacts', {}).get('c6@example.invalid', {})
bc = [l for l in brevo_calls() if l['path'] == '/v3/contacts']
check('C5 "every booking" without the tick: contact created WITHOUT lists, no listIds and no updateEnabled in the call', c.get('listIds') == [] and len(bc) == 1 and 'listIds' not in bc[0]['body'] and 'updateEnabled' not in bc[0]['body'], (c, [x['body'] for x in bc]))
setup()
book('brevo1', day(D), '09:00', email='c7@example.invalid', name='Sempre Con Spunta', newsletter='1')
st = brevo_state()
check('C6 "every booking" WITH the tick and lists but no template: no subscription, no contact with lists', not st.get('doi') and not st.get('contacts'), st)
setup()
book('brevo_nolist', day(D), '09:00', email='c8@example.invalid', name='Spunta Senza Liste', newsletter='1')
c = brevo_state().get('contacts', {}).get('c8@example.invalid', {})
check('C7 tick but the element has no lists: just the contact, no list', c.get('listIds') == [], c)

# ============================================================================ 4. sealed form token
print('--- 4. sealed token')
for name in ('hookprof', 'hookother', 'staffto', 'brevo1', 'paidhook'):
    cfg = page_cfg(name)
    h = Sess().get('%s/wgbtest/%s.html' % (BASE, name))
    t = cfg['form']
    import base64
    dec = ''.join(base64.b64decode(p + '=' * (-len(p) % 4)).decode('latin1') for p in t.split('.')[1:2] if re.fullmatch(r'[A-Za-z0-9+/=]*', p))
    leaks = [x for x in ('googleapis.com', 'elem-staff@example.invalid', 'brevo_lists', '"webhook"') if x in h or x in dec]
    check('T1 the page of "%s" shows no webhook address, staff recipient or Brevo setting (token is sealed: starts with v2.)' % name, t.startswith('v2.') and not leaks, (t[:6], leaks))
cfg = page_cfg('staffto')
act = form_actions(cfg['form'])
check('T2 the server still reads the full content (recipient, price, fields) after checking the signature', act.get('staff', {}).get('to') == 'elem-staff@example.invalid' and 'event' in act, list(act)[:8])
check('T3 token size: the typical sealed token stays small (under 4 KB)', len(cfg['form']) < 4096, len(cfg['form']))
tok = cfg['form']
for label, bad in (('a changed ciphertext', tok[:12] + ('A' if tok[12] != 'A' else 'B') + tok[13:]), ('a changed signature', tok[:-3] + ('abc' if not tok.endswith('abc') else 'def')), ('a truncated token', tok[:40]), ('the sealed part with another signature', tok.rsplit('.', 1)[0] + '.' + '0' * 64)):
    r = book('staffto', day(D), '09:00', email='t4@example.invalid', cfg={**cfg, 'form': bad})
    check('T4 %s is refused' % label, r.get('ok') is False, r)
check('T4b nothing was stored for the forgeries', sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='t4@example.invalid'") == '0')
old = jx("php /tmp/mock/signform.php '%s'" % json.dumps(act, separators=(',', ':'))).strip()
r = book('staffto', day(D), '10:00', email='t5@example.invalid', cfg={**cfg, 'form': old})
check('T5 compatibility: a page cached before the update (readable old-format token, same epoch) still books', r.get('ok') is True, r)
old_noflag = dict(act); old_noflag.pop('showNewsletter', None)
tokold = jx("php /tmp/mock/signform.php '%s'" % json.dumps(old_noflag, separators=(',', ':'))).strip()
setup(newsletter_webhook='https://www.googleapis.com/nl')
book('nlhook', day(D), '09:00', email='t6@example.invalid', name='Vecchia Pagina', newsletter='1', cfg={**page_cfg('nlhook'), 'form': jx("php /tmp/mock/signform.php '%s'" % json.dumps({k: v for k, v in form_actions(page_cfg('nlhook')['form']).items() if k != 'showNewsletter'}, separators=(',', ':'))).strip()})
check('T6 an old token (no signed newsletter flag) cannot subscribe anyone: the tick is ignored', not [l for l in gm_log() if l['path'] == '/nl'], '')

# ============================================================================ 5. SSRF
print('--- 5. SSRF')
res = json.loads(jx("php /tmp/mock/ipcheck.php public 127.0.0.1 10.1.2.3 172.16.0.1 172.31.255.255 192.168.1.1 169.254.169.254 100.64.0.1 0.0.0.0 ::1 fe80::1 fc00::1 ::ffff:127.0.0.1 ::ffff:10.0.0.1 224.0.0.1 192.0.2.1 8.8.8.8 1.1.1.1 93.184.216.34 2606:4700:4700::1111 not-an-ip").strip().splitlines()[-1])
internal = ['127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '224.0.0.1', '192.0.2.1', 'not-an-ip']
check('N1 loopback, private, link-local (cloud metadata), CGNAT, IPv6 local, IPv4-in-IPv6 and multicast are NOT public', not [i for i in internal if res.get(i)], {i: res.get(i) for i in internal if res.get(i)})
check('N2 real public addresses are', all(res.get(i) for i in ('8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111')), res)
for ip, cidr, want in (('173.245.48.5', '173.245.48.0/20', True), ('173.245.64.1', '173.245.48.0/20', False), ('2606:4700::1', '2606:4700::/32', True), ('2607:4700::1', '2606:4700::/32', False), ('8.8.8.8', '173.245.48.0/20', False), ('1.2.3.4', 'garbage', False)):
    got = json.loads(jx("php /tmp/mock/ipcheck.php cidr %s %s" % (ip, cidr)).strip().splitlines()[-1])
    check('N3 %s in %s -> %s' % (ip, cidr, want), got is want, got)
setup(webhook_allow_private='')
book('hookother', day(D), '09:00', email='n1@example.invalid', name='Rete Interna')
b = last_id()
check('N4 a customer-chosen address that resolves to an internal network (here 127.0.0.1) is refused: no call, closed as error, log and staff', len(hooks()) == 0 and '"webhook":"error"' in rowdb(b)['fx_steps'].replace(' ', '') and 'internal or local network' in logtxt() and len(staff_not_sent()) == 1, (len(hooks()), logtxt()[-200:]))
for name in ('hooklit', 'hookloc', 'hookcred'):
    setup(webhook_allow_private='')
    book(name, day(D), '09:00', email='n2@example.invalid', name='Rete ' + name)
    b = last_id()
    check('N5 "%s": refused (cloud-metadata address / localhost / credentials in the URL)' % name, '"webhook":"error"' in rowdb(b)['fx_steps'].replace(' ', '') and len(staff_not_sent()) == 1, rowdb(b)['fx_steps'])
setup()
jx("printf 302 > /tmp/gmock/hook_fail; chmod 666 /tmp/gmock/hook_fail; rm -f /tmp/gmock/log.jsonl")
book('hookother', day(D), '09:00', email='n3@example.invalid', name='Rimanda Via')
check('N6 a redirect from the receiver is NOT followed (one request, none to the Location), and the log says so', len(hooks()) == 1 and len(hooks('/hook-redirected')) == 0 and 'redirect' in logtxt(), (len(hooks()), len(hooks('/hook-redirected')), logtxt()[-200:]))
jx('rm -f /tmp/gmock/hook_fail')
# retry outbox: capped
setup()
book('hookother', day(D), '09:00', email='n4@example.invalid', name='Coda Piena')
b = last_id(); tok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % b)
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % b)
sql("INSERT INTO joom_webgbooking_kv (k, v, updated) SELECT CONCAT('hq:9:filler.', seq), '{\"id\":99999,\"event\":\"booking.created\",\"tries\":1,\"at\":%d}', UTC_TIMESTAMP() FROM seq_1_to_200" % int(time.time()))
jx("printf 500 > /tmp/gmock/hook_fail; chmod 666 /tmp/gmock/hook_fail")
Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok, 'date': day(D), 'time': '14:00'})
n = sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k LIKE 'hq:%'")
check('N7 the outbox of failed deliveries is capped at 200: a 201st is not queued, the log says so', n == '200' and 'queue of failed deliveries' in logtxt(), (n, logtxt()[-200:]))
jx('rm -f /tmp/gmock/hook_fail')

# ============================================================================ 6. Cloudflare header
print('--- 6. CF-Connecting-IP')
setup()


def book_with_header(email, hdr):
    cfg = page_cfg('def')
    s = Sess()
    tk = json.loads(s.get(AJAX + '&action=token'))['token']
    d = {'action': 'book', tk: '1', 'date': day(D), 'time': '09:00', 'name': 'Header Prova', 'email': email, 'phone': '', 'notes': '', 'guest': '', 'privacy': '1', 'website': '', 'newsletter': '0', 'form': cfg['form']}
    import urllib.parse
    req = urllib.request.Request(AJAX + '&action=book', data=urllib.parse.urlencode(d).encode(), headers=hdr)
    try:
        return json.loads(s.op.open(req, timeout=60).read().decode())
    except urllib.error.HTTPError as e:
        return {'ok': False, 'raw': e.read().decode()[:200]}


r0 = book_with_header('i0@example.invalid', {})
h0 = sql("SELECT ip_hash FROM joom_webgbooking_bookings WHERE customer_email='i0@example.invalid'")
sql("DELETE FROM joom_webgbooking_bookings")
r1 = book_with_header('i1@example.invalid', {'CF-Connecting-IP': '203.0.113.77'})
h1 = sql("SELECT ip_hash FROM joom_webgbooking_bookings WHERE customer_email='i1@example.invalid'")
sql("DELETE FROM joom_webgbooking_bookings")
r2 = book_with_header('i2@example.invalid', {'CF-Connecting-IP': '198.51.100.9'})
h2 = sql("SELECT ip_hash FROM joom_webgbooking_bookings WHERE customer_email='i2@example.invalid'")
check('P1 a forged CF-Connecting-IP from a connection that is NOT Cloudflare changes nothing: the visitor is the connection address (the rate limit cannot be dodged)', r0.get('ok') and r1.get('ok') and r2.get('ok') and h0 and h0 == h1 == h2, (h0, h1, h2))
setup(cf_ranges_extra='172.16.0.0/12 10.0.0.0/8 192.168.0.0/16')
sql("DELETE FROM joom_webgbooking_bookings")
r3 = book_with_header('i3@example.invalid', {'CF-Connecting-IP': '203.0.113.77'})
h3 = sql("SELECT ip_hash FROM joom_webgbooking_bookings WHERE customer_email='i3@example.invalid'")
check('P2 when the connection comes from a listed Cloudflare range (the option adds ranges) the header IS used', r3.get('ok') and h3 and h3 != h0, (h0, h3))

# ============================================================================ 7. GET warning
print('--- 7. GET warning')
el = open(os.path.join(SRC, 'plg_system_webgbooking', 'yootheme', 'elements', 'booking', 'element.php'), encoding='utf-8').read()
en = open(os.path.join(SRC, 'plg_system_webgbooking', 'language', 'en-GB', 'plg_system_webgbooking.ini'), encoding='utf-8').read()
it = open(os.path.join(SRC, 'plg_system_webgbooking', 'language', 'it-IT', 'plg_system_webgbooking.ini'), encoding='utf-8').read()
check('G1 the element label says GET puts the customer data in the address (logs of every server)', 'ends up in the logs' in en, '')
check('G2 the plugin option label says it too, in English and in Italian (language files)', re.search(r'WEBHOOK_METHOD_LABEL="[^"]*address', en) is not None and re.search(r'WEBHOOK_METHOD_LABEL="[^"]*indirizzo', it) is not None, '')

# ============================================================================ 8. Brevo attributes from visitor input
print('--- 8. attributes')
setup()
book('brevo1', day(D), '09:00', email='a1@example.invalid', name='Mario <b>Rossi</b>', extra={'custom[cf0]': '<script>alert(1)</script>Torino', 'custom[cf1]': 'P' * 400})
a = brevo_state().get('contacts', {}).get('a1@example.invalid', {}).get('attributes', {})
check('A1 markup is stripped from names and mapped answers', a.get('NOME') == 'Mario' and a.get('COGNOME') == 'Rossi' and '<' not in json.dumps(a) and a.get('CITTA', '').endswith('Torino'), a)
check('A2 a long mapped answer is capped (200)', len(a.get('PROGETTO', '')) == 200, len(a.get('PROGETTO', '')))
book('brevo1', day(D), '10:00', email='a2@example.invalid', name='Nome ' + 'x' * 100)
a = brevo_state().get('contacts', {}).get('a2@example.invalid', {}).get('attributes', {})
check('A3 first name and surname are capped at 60 characters', len(a.get('COGNOME', '')) == 60 and a.get('NOME') == 'Nome', {k: len(v) for k, v in a.items()})
book('brevo1', day(D), '11:00', email='a3@example.invalid', name='Premio http://evil.example/vinci Rossi')
a = brevo_state().get('contacts', {}).get('a3@example.invalid', {}).get('attributes', {})
check('A4 a link in the name fields is refused (the field is left out, not saved)', 'COGNOME' not in a and a.get('NOME') == 'Premio', a)
book('brevo1', day(D), '12:00', email='a4@example.invalid', name='scrivi@evil.example Bianchi')
a = brevo_state().get('contacts', {}).get('a4@example.invalid', {}).get('attributes', {})
check('A5 an email address in the name fields is refused', 'NOME' not in a and a.get('COGNOME') == 'Bianchi', a)
book('brevo1', day(D), '13:00', email='a5@example.invalid', name='Visita www.evil.example ora')
a = brevo_state().get('contacts', {}).get('a5@example.invalid', {}).get('attributes', {})
check('A6 a web address (www.) in the name fields is refused', 'COGNOME' not in a, a)

# ============================================================================ 9. admin confirm respects the element's customer settings
print('--- 9. confirm and tell the customer')
setup()
r = book('manual_custom', day(D), '09:00', email='m1@example.invalid', name='Testo Mio')
m1 = last_id()
row = sql("SELECT cust_cfg FROM joom_webgbooking_bookings WHERE id=%d" % m1)
check('Z1 the element customer-email settings are saved on the booking', 'Confermato davvero' in row and 'Testo mio' in row, row[:120])
jx('rm -f /tmp/mail/*.eml')
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % m1)
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', det)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(m1)), ('option', 'com_webgbooking'), (tk, '1')])
cm = mails_to('m1@example.invalid')
check('Z2 the confirmation email uses the element\'s own subject and text', len(cm) == 1 and cm[0]['subject'] == 'Confermato davvero Testo Mio' and 'Testo mio per Testo Mio' in cm[0]['html'], [m['subject'] for m in cm])
r = book('manual_off', day(D), '10:00', email='m2@example.invalid', name='Email Spenta')
m2 = last_id()
jx('rm -f /tmp/mail/*.eml')
det = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=booking&id=%d' % m2)
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', det)[0]
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&task=booking.confirmnotify', [('id', str(m2)), ('option', 'com_webgbooking'), (tk, '1')])
check('Z3 an element that does NOT email the customer: the booking is confirmed but no email goes out, and the admin is told', rowdb(m2)['status'] == 'confirmed' and not mails_to('m2@example.invalid') and 'does not email the customer' in txt(out), txt(out)[:200])

# ============================================================================ 10. old alert markers
print('--- 10. purge')
setup()
sql("INSERT INTO joom_webgbooking_kv (k, v, updated) VALUES ('alert:old-one', '1', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY)), ('alert:new-one', '1', UTC_TIMESTAMP())")
run_task()
check('Y1 alert markers older than 30 days are removed by the scheduled task, recent ones stay', sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k='alert:old-one'") == '0' and sql("SELECT COUNT(*) FROM joom_webgbooking_kv WHERE k='alert:new-one'") == '1', '')

# ============================================================================ 11. language: every label and message comes from the language files
print('--- 11. language')
for tag, dur, price in (('en-GB', 'Duration (minutes)', 'Price'), ('it-IT', 'Durata (minuti)', 'Prezzo')):
    out = jx("php /tmp/mock/elcheck.php %s" % tag).strip().splitlines()
    d = json.loads(out[-1]) if out else {}
    check('L1 the builder element in %s: labels come from the language files (no unresolved key)' % tag, d.get('unresolved') == [] and d.get('duration_label') == dur and d.get('price_label') == price, (d.get('unresolved', [])[:3], d.get('duration_label')))
    check('L2 ...and the customer-email body has no fixed Italian default in %s (the translated default template of the plugin applies)' % tag, d.get('body_default') in (None, ''), d.get('body_default'))
d = json.loads(jx("php /tmp/mock/elcheck.php ini").strip().splitlines()[-1])
bad = [n for n, r in d.items() if sorted(r['langs']) != ['en-GB', 'it-IT'] or r.get('en-GB') != r.get('it-IT') or r.get('en-GB', 0) < 1 or sorted(r.get('en-GB_keys', [])) != sorted(r.get('it-IT_keys', []))]
check('L3 every language file of the package parses with Joomla own parser and has the same keys in en-GB and it-IT (%d files)' % len(d), d and not bad, bad)
check('L4 quotes inside a string are written the Joomla 5/6 way (backslash-quote) and read back as quotes', any(r.get('quote_ok') for r in d.values()), '')

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
