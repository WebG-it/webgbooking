"""0.36.0, the update with real options. The settings used to be the options of the plugin; now they are the Options of the component.
For each previous release (0.35.3, and 0.35.2 which is the one on the live sites) a site is brought to that release with options filled in
(email look, Stripe test keys, Google, Brevo, webhook with a header profile, Google Sheet, analytics ...), the same bookings are made
(free with Google, newsletter with Brevo, webhook with profile, paid with Stripe + webhook + payment mail), then the 0.36.0 package is installed
over it and the SAME bookings are made again from the pages that were drawn BEFORE the update. Checked:
  - every option is in the Options of the component, with the value it had (nothing lost, nothing changed); the plugin keeps its old values;
  - no secret in clear in the database (neither row), none shown in the Options form;
  - the signed pages drawn before the update keep working (same config epoch);
  - Stripe, Google, Brevo and webhook calls and the emails are the same as before (strict against 0.35.3; against 0.35.2 only what 0.35.3 did not change on purpose);
  - running the update again changes nothing, and a value changed in the Options is not undone by an update;
  - the old plain footer of four lines is drawn exactly as before.
Usage: python t_v360_upgrade.py <port> <container-prefix> [old-zip ...]   (default: dist/pkg_webgbooking-0.35.3.zip and 0.35.2.zip; the new one is the newest in dist/)
Needs WGB_E2E_ADMIN_PASS. Leaves the NEW package installed."""
import sys, os, re, json, glob, difflib, time
from wgbtest import *
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from t_admin_lib import *

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
args = [a for a in sys.argv[3:] if not a.startswith('--')]
zips = sorted(glob.glob(os.path.join(ROOT, 'dist', 'pkg_webgbooking-*.zip')), key=lambda p: [int(x) for x in re.findall(r'\d+', os.path.basename(p))])
NEW = zips[-1]
OLDS = args or [os.path.join(ROOT, 'dist', 'pkg_webgbooking-0.35.3.zip'), os.path.join(ROOT, 'dist', 'pkg_webgbooking-0.35.2.zip')]
COMP = "element='com_webgbooking' AND type='component'"
PLUG = "element='webgbooking' AND folder='system' AND type='plugin'"
BKEY = 'xkeysib-' + R(40)
S1, S2, S3 = 'sec' + R(18), 'sec' + R(18), 'sec' + R(18)
PK = 'pk_' + 'test_' + R(24)
NL = chr(92) + 'n'
FOOTER = 'Rossi Studio S.r.l.\nVia Roma 1, 10100 Torino\nP.IVA 01234567890\nPrivacy: https://www.example.invalid/privacy.html'


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


def rsql(qq):
    return sh(['docker', 'exec', DB, 'mariadb', '-ujoomla', '-pjoomlapw', 'joomla', '-N', '-r', '-B', '-e', qq]).strip()


def params(where):
    return json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % where) or '{}')


def install(zp):
    sh(['docker', 'cp', zp, J + ':/tmp/pkg.zip'])
    out = sh(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'extension:install', '--path=/tmp/pkg.zip'])
    return 'successfully' in out


def version():
    return sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(manifest_cache,'$.version')) FROM joom_extensions WHERE element='pkg_webgbooking'")


def seed(legacy=False):
    """The state of a site on the old release: the options are the plugin's, the component has none."""
    sql("UPDATE joom_extensions SET params='{}' WHERE %s" % COMP)
    sql('DELETE FROM joom_webgbooking_emailtexts') if 'emailtexts' in sql("SHOW TABLES LIKE 'joom_webgbooking_emailtexts'") else None
    jx("rm -f /tmp/mail/*.eml /tmp/gmock/*; mkdir -p /tmp/gmock; chmod 777 /tmp/gmock")
    tp.set_mock_key(SK)
    jx("printf %%s '%s' > /tmp/gmock/brevo_key; chmod 666 /tmp/gmock/brevo_key" % BKEY)
    kv = {
        'row': 'plugin', 'stripe_sk': SK, 'stripe_whsec': WHSEC, 'stripe_mode': 'test', 'stripe_pk': PK, 'brevo_key': BKEY,
        'whp1_name': 'crm', 'whp1_headers': 'Authorization: Bearer %s%sX-Api-Key: %s%sHOSTS: www.googleapis.com' % (S1, NL, S2, NL),
        'booking_webhook': 'https://www.googleapis.com/hook', 'booking_webhook_method': 'POST', 'newsletter_webhook': 'https://www.googleapis.com/nl',
        'mail_business_name': 'Rossi Studio', 'mail_color': '#0f766e', 'mail_from_name': 'Studio Rossi, WebG', 'host_name': 'Studio Rossi',
        'google_sheet_id': 'sheet-' + R(8), 'meeting_url': 'https://meet.example.invalid/room', 'gtm_id': 'GTM-ABC123', 'ga4_id': 'G-ABC123XYZ',
        'reminder_times': '48h, 2h', 'direct_path': 'prenota', 'privacy_url': '', 'window_days': '45', 'min_notice': '1', 'buffer_before': '5',
    }
    if legacy:
        kv['email_subject'] = 'Conferma per {name}'
        kv['email_body'] = '<p>Gentile {name}, la aspettiamo.</p>'
    jx("php /tmp/mock/init.php %s >/dev/null" % ' '.join("'%s=%s'" % (k, v) for k, v in kv.items()))
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.mail_footer',%s) WHERE %s" % (q(FOOTER), PLUG))
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.whp2_name','zap','$.whp2_headers',%s) WHERE %s" % (q('X-Zap: ' + S3), PLUG))      # a secret typed in clear in the database, as it can happen on an old site
    sql('DELETE FROM joom_webgbooking_eventtypes')
    ins_type()
    jx(RENDER)


def ins_type():
    f = dict(description='', state=1, listed=1, duration='', buffer_before='', buffer_after='', location='meet', avail_start='', avail_end='', avail_days='', avail_notice='', avail_window='', price_amount='', price_currency='', price_label='', custom_fields='', staff_to='', redirect_url='', confirm_manual=0, allow_guest=1, show_newsletter=0, ordering=0, slug='prova', title='Prova')
    sql('INSERT INTO joom_webgbooking_eventtypes (%s,created,modified) VALUES (%s,NOW(),NOW())' % (','.join(f), ','.join(q(v) for v in f.values())))


def norm(s):
    s = re.sub(r'\bbk[0-9a-f]{20,}', 'BKID', s)
    s = re.sub(r'mock-bk[0-9a-f]+', 'mock-BK', s)
    s = re.sub(r'[0-9a-f]{32}', 'TOKEN', s)
    s = re.sub(r'(cs_test_mock|pi_mock_|mock_evt_|evt_|mockevt)\w*', 'ID', s)
    s = re.sub(r'\bbk[0-9a-f]{20,}', 'BKID', s)
    s = re.sub(r'mock-bk\w+', 'mock-BK', s)
    s = re.sub(r'wgb-[0-9a-f]{12,}', 'wgb-REQ', s)
    s = re.sub(r'(booking_id(?:%5D=|\\?"\s*:\s*\\?"))\d+', r'\1N', s)
    s = re.sub(r'until\+\d+%2F\d+%2F\d+\+\d+%3A\d+', 'until+HOLD', s)
    s = re.sub(r'until \d+/\d+/\d+ \d+:\d+', 'until HOLD', s)
    s = re.sub(r'\d{4}-\d\d-\d\d \d\d:\d\d:\d\d', 'TS', s)
    s = re.sub(r'(expires_at\\?"?: ?|expires_at=)\d+', r'\1N', s)
    s = re.sub(r'(client_reference_id\\?"?: ?\\?"?|client_reference_id=)\d+', r'\1N', s)
    s = re.sub(r'(?i)(message-id|date|boundary|x-mailer):[^\n]*', '', s)
    s = re.sub(r'boundary="[^"]*"|=_[A-Za-z0-9]{10,}', 'B', s)
    s = re.sub(r'(?<=[?&;])(id|booking|b)=\d+', r'\1=N', s)
    s = re.sub(r'#\d+', '#N', s)
    s = re.sub(r'(\\?"id\\?": )\d+', r'\1N', s)
    s = re.sub(r'\b(DTSTAMP|CREATED|LAST-MODIFIED|UID)[:;][^\r\n]*', r'\1', s)
    s = re.sub(r'\d{8}T\d{6}Z', 'STAMP', s)
    return s.strip()


def run_scenarios():
    """The same four bookings; returns what was sent out, normalised."""
    sql('DELETE FROM joom_webgbooking_bookings'); sql('DELETE FROM joom_webgbooking_kv'); sql('DELETE FROM joom_webgbooking_stripe_events')
    jx("rm -f /tmp/mail/*.eml /tmp/gmock/log.jsonl /tmp/gmock/events.json /tmp/gmock/stripe.json /tmp/gmock/brevo.json")
    out = {}
    res = {}
    res['free'] = book('def', day(3), '10:00', email='free@example.invalid', name='Libero Uno')
    res['brevo'] = book('brevo_nl', day(3), '11:00', email='brevo@example.invalid', name='Brevo Due', newsletter='1')
    res['hook'] = book('hookprof', day(3), '12:00', email='hook@example.invalid', name='Hook Tre')
    r, bid = tp.start_paid('paid30', 4, '10:00', 'pay@example.invalid', name='Paga Quattro')
    res['paid'] = r
    sid = tp.dbrow(bid).get('stripe_session_id', '')
    if sid:
        tp.pay_in_mock(sid)
        res['webhook'] = tp.webhook(tp.event('checkout.session.completed', sid))
    time.sleep(1)
    out['answers'] = json.dumps({k: (v if not isinstance(v, dict) else {a: b for a, b in v.items() if a not in ('redirect', 'manage', 'url', 'token')}) for k, v in res.items()}, sort_keys=True, default=str)
    out['redirect_ok'] = str(res['paid'].get('redirect', '')).startswith('https://checkout.stripe.test/c/pay/')
    ms = []
    for p in mails():
        m = decode_mail(mail_text(p))
        ms.append('TO %s | %s\n%s\n--text--\n%s' % (m['rcpt'].strip(), norm(m['subject']), norm(m['html']), norm(m.get('text', ''))))
    out['emails'] = sorted(ms)
    calls = []
    for l in gm_log():
        hdr = sorted(k for k in (l.get('headers') or {}) if k in ('authorization', 'api-key', 'x-api-key', 'content-type'))
        calls.append(norm('%s %s %s?%s %s %s' % (l['host'], l['method'], l['path'], l['query'], hdr, json.dumps(l['body'], sort_keys=True) if not isinstance(l['body'], str) else l['body'])))
    out['calls'] = sorted(c for c in calls if 'oauth2.googleapis' not in c)
    rows = rsql("SELECT status,payment_status,amount_cents,currency,booking_date,booking_time,customer_email,event_name,location FROM joom_webgbooking_bookings ORDER BY customer_email")
    out['rows'] = rows
    out['stripe'] = sorted(norm(json.dumps(v, sort_keys=True)) for v in tp.sessions().values())
    return out


def diff(a, b, what):
    """Only the places that differ, a few characters around each."""
    if isinstance(a, dict) or isinstance(b, dict):
        a, b = ['%s=%s' % (k, v) for k, v in sorted((a or {}).items())], ['%s=%s' % (k, v) for k, v in sorted((b or {}).items())]
    if isinstance(a, str) or isinstance(b, str):
        a, b = [str(a)], [str(b)]
    out = []
    for i in range(max(len(a), len(b))):
        x, y = (a[i] if i < len(a) else ''), (b[i] if i < len(b) else '')
        if x == y:
            continue
        sm = difflib.SequenceMatcher(None, x, y, autojunk=False)
        for tag, i1, i2, j1, j2 in sm.get_opcodes():
            if tag != 'equal':
                out.append('item %d: ...%s[[%s -> %s]]%s...' % (i, x[max(0, i1 - 40):i1], x[i1:i2][:120], y[j1:j2][:120], x[i2:i2 + 30]))
                if len(out) > 8:
                    return '\n'.join(out)
    return '\n'.join(out)


def no_clear_secrets():
    raw = rsql("SELECT CONCAT(IFNULL(params,'')) FROM joom_extensions WHERE %s UNION ALL SELECT IFNULL(params,'') FROM joom_extensions WHERE %s" % (COMP, PLUG))
    return not any(s in raw for s in (SK, WHSEC, BKEY, S1, S2, S3))


SECRETS = ('stripe_sk', 'stripe_whsec', 'brevo_key', 'whp1_headers')
adm, edit_url, save_options, opts = make_admin()

for old in OLDS:
    ov = re.search(r'pkg_webgbooking-([0-9.]+)\.zip', os.path.basename(old)).group(1)
    strict = ov == '0.35.3'
    print('================ from %s to %s' % (ov, os.path.basename(NEW)))
    if not os.path.exists(old):
        check('%s the old package is in dist/' % ov, False, old)
        continue
    check('%s the old release installs' % ov, install(old) and version() == ov, version())
    seed(legacy=not strict)
    before_plug = params(PLUG)
    check('%s start: the options are the plugin\'s (%d keys), the component has none' % (ov, len(before_plug)), len(before_plug) >= 30 and params(COMP) == {}, (len(before_plug), params(COMP)))
    A = run_scenarios()
    check('%s before: the four bookings work (free + Google, Brevo, webhook with profile, paid + Stripe + payment)' % ov, A['redirect_ok'] and len(A['emails']) >= 6 and any('api.brevo.com' in c for c in A['calls']) and any('/hook' in c for c in A['calls']) and any('api.stripe.com' in c for c in A['calls']) and any('calendar/v3' in c for c in A['calls']), (A['redirect_ok'], len(A['emails']), len(A['calls'])))

    # ---------------------------------------------------------------- the update
    check('%s the 0.36.0 package installs over it' % ov, install(NEW) and version() == '0.36.0', version())
    comp, plug = params(COMP), params(PLUG)
    miss = [k for k in before_plug if k not in comp]
    changed = {k: (before_plug[k], comp[k]) for k in before_plug if k in comp and comp[k] != before_plug[k] and k not in ('email_subject', 'email_body', 'whp2_headers')}
    check('%s every option of the plugin is now in the Options of the component (%d keys, none missing)' % (ov, len(before_plug)), not miss, miss)
    check('%s ... with the value it had (secrets too: same encrypted text, portable)' % ov, not changed, changed)
    check('%s ... the plugin row keeps its old values (nothing deleted, they are only a fallback)' % ov, all(plug.get(k) == v for k, v in before_plug.items() if k not in ('email_subject', 'email_body', 'whp2_headers')), {k: (v, plug.get(k)) for k, v in before_plug.items() if plug.get(k) != v and k not in ('email_subject', 'email_body', 'whp2_headers')})
    check('%s no secret in clear in the database, neither row' % ov, no_clear_secrets() and all(str(comp.get(k, '')).startswith('g:') for k in SECRETS), [k for k in SECRETS if not str(comp.get(k, '')).startswith('g:')])
    check('%s the secret that was in CLEAR in the plugin is encrypted in both rows after the update (the copy in the component and the plugin\'s own)' % ov, str(comp.get('whp2_headers', '')).startswith('g:') and str(plug.get('whp2_headers', '')).startswith('g:') and all(str(plug.get(k, 'g:')).startswith('g:') or plug.get(k) == '' for k in ('stripe_sk', 'stripe_whsec', 'brevo_key', 'whp1_headers', 'whp2_headers')), (str(comp.get('whp2_headers', ''))[:4], str(plug.get('whp2_headers', ''))[:4]))
    pg = adm.get(edit_url)
    fp = FormParser(); fp.feed(pg)
    form = dict(fp.pairs)
    check('%s the Options form shows the old values (email look, Google, Stripe mode and public key, webhook, analytics, days ...)' % ov, all(form.get('jform[%s]' % k) == str(before_plug[k]) for k in ('notify_email', 'mail_business_name', 'mail_from_name', 'host_name', 'google_client_id', 'google_calendar_id', 'stripe_mode', 'stripe_pk', 'booking_webhook', 'newsletter_webhook', 'gtm_id', 'ga4_id', 'meeting_url', 'reminder_times', 'whp1_name', 'window_days')), {k: (form.get('jform[%s]' % k), before_plug[k]) for k in ('notify_email', 'mail_business_name', 'stripe_pk', 'window_days', 'reminder_times') if form.get('jform[%s]' % k) != str(before_plug[k])})
    check('%s ... and no secret is sent to the browser' % ov, all(form.get('jform[%s]' % k, '') == '' for k in SECRETS) and not any(s in pg for s in (SK, WHSEC, BKEY, S1, S2, S3)) and 'value="g:' not in pg, '')
    shown = re.search(r'<textarea[^>]*name="jform\[mail_footer\]"[^>]*>(.*?)</textarea>', pg, re.S)
    shown = html.unescape(shown.group(1)).strip() if shown else ''
    check('%s the old plain footer opens in the editor with its four lines and its address as a link; the database still has the plain text' % ov, shown.count('<br>') == 3 and 'Privacy: <a href="https://www.example.invalid/privacy.html">' in shown and params(COMP).get('mail_footer') == FOOTER, shown)

    if not strict:
        txts = sql('SELECT COUNT(*) FROM joom_webgbooking_emailtexts')
        check('%s the old email texts of the options moved to the email texts, as in 0.35.3, and are empty in the options now' % ov, int(txts or 0) >= 1 and params(COMP).get('email_subject', '') == '' and params(COMP).get('email_body', '') == '', (txts, params(COMP).get('email_subject')))

    # ---------------------------------------------------------------- the same bookings, from the pages drawn BEFORE the update
    B = run_scenarios()
    for key, label in (('answers', 'the answers of the booking page'), ('rows', 'the stored bookings'), ('stripe', 'the Stripe Checkout Sessions (amount, currency, mode, customer, metadata)'), ('calls', 'every call to Google, Stripe, Brevo and the webhook (address, method, body, the headers that carry the secrets)')):
        if key == 'answers' or True:
            ok = A[key] == B[key]
            check('%s after: %s are the same as before the update' % (ov, label), ok, diff(A[key], B[key], key) if not ok else '')
    check('%s after: the pages drawn before the update still book and still start the payment (same config epoch)' % ov, B['redirect_ok'] and len(B['emails']) == len(A['emails']), (B['redirect_ok'], len(B['emails']), len(A['emails'])))
    subjA = sorted(e.split('\n')[0] for e in A['emails'])
    subjB = sorted(e.split('\n')[0] for e in B['emails'])
    check('%s after: the same emails reach the same people with the same subjects' % ov, (subjA == subjB) or (not strict and len(subjA) == len(subjB)), diff(subjA, subjB, 'subjects') if subjA != subjB else '')
    if strict:
        ok = A['emails'] == B['emails']
        check('%s after: every email (customer, staff, payment) is identical to before, html and text' % ov, ok, diff(A['emails'], B['emails'], 'emails') if not ok else '')
    # drawn again with the new code
    jx(RENDER)
    C = run_scenarios()
    for key in ('rows', 'stripe', 'calls'):
        check('%s after, pages drawn again with 0.36.0: %s unchanged' % (ov, key), A[key] == C[key], diff(A[key], C[key], key) if A[key] != C[key] else '')
    if strict:
        ok = A['emails'] == C['emails']
        check('%s after, pages drawn again: every email identical to before' % ov, ok, diff(A['emails'], C['emails'], 'emails') if not ok else '')
    st, hd, body = http_get('/prenota/prova')
    fl = (re.search(r'<footer class="bk-foot">(.*?)</footer>', body, re.S) or [0, ''])[1]
    check('%s the direct page: the old plain footer is drawn as before (lines and address as a link, new tab)' % ov, '<p class="bk-f2">Rossi Studio S.r.l.<br>\nVia Roma 1, 10100 Torino<br>\nP.IVA 01234567890<br>\nPrivacy: <a href="https://www.example.invalid/privacy.html" target="_blank" rel="noopener noreferrer">https://www.example.invalid/privacy.html</a></p>' in fl, fl)

    # ---------------------------------------------------------------- again, and a changed value is not undone
    snap = params(COMP)
    install(NEW)
    check('%s running the same update again changes nothing' % ov, params(COMP) == snap and version() == '0.36.0', diff(snap, params(COMP), 'again'))
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.notify_email','changed@wgbtest.invalid','$.window_days','60') WHERE %s" % COMP)
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.notify_email','stale@wgbtest.invalid') WHERE %s" % PLUG)
    install(NEW)
    c2 = params(COMP)
    check('%s a value changed in the Options is not undone by an update (the plugin\'s old value never overwrites)' % ov, c2.get('notify_email') == 'changed@wgbtest.invalid' and c2.get('window_days') == '60', (c2.get('notify_email'), c2.get('window_days')))

# leave nothing behind for the next suites: the migrated email texts, the test event type, the test options
sql('DELETE FROM joom_webgbooking_emailtexts')
sql('DELETE FROM joom_webgbooking_eventtypes')
reset_env(render=True)
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
