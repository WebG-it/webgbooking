"""0.35.3: the emails can be changed without code. Every email has a ready-made text (it-IT and en-GB), what the site changes is stored only where it differs,
the panel has the list / editor / placeholders menu / preview / "send me a test" / restore, the layout can be overridden from the template and the strings with the
Joomla language overrides, the texts of the old plugin options move to the new place on update, and the live defects found on 0.35.2 are fixed.
Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, html, glob, base64, urllib.parse, datetime as dt
from wgbtest import *
from t_admin_lib import *

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SRC = os.path.join(ROOT, 'src')
TBL = 'joom_webgbooking_emailtexts'
KEYS = ['customer_confirmed', 'customer_request', 'customer_rescheduled', 'customer_reminder', 'customer_link', 'customer_cancelled', 'customer_cancelled_staff',
        'customer_late_gone', 'customer_late_check', 'guest_invite', 'guest_reminder', 'staff_new', 'staff_moved', 'staff_cancelled', 'staff_cancelled_admin', 'staff_confirmed_admin']


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h or ''))


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


reset_env()
sql('DELETE FROM %s' % TBL)
jx('php /tmp/mock/init.php >/dev/null')
adm = admin_login()
ADMIN = BASE + '/administrator/index.php'


def edit_page(key, lang='en-GB'):
    return adm.get(ADMIN + '?option=com_webgbooking&view=email&key=%s&lang=%s' % (key, lang))


def token_in(h):
    t = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', h)
    return t[0] if t else ''


def save_email(key, lang, fields, task='email.save'):
    h = edit_page(key, lang)
    pairs = [('jform[%s]' % k, v) for k, v in fields.items()] + [('key', key), ('lang', lang), ('task', task), (token_in(h), '1')]
    return post_pairs(adm, ADMIN + '?option=com_webgbooking&view=email&key=%s&lang=%s' % (key, lang), pairs)


def api(task, key, lang, fields):
    h = edit_page(key, lang)
    pairs = [('jform[%s]' % k, v) for k, v in fields.items()] + [('key', key), ('lang', lang), (token_in(h), '1')]
    out = post_pairs(adm, ADMIN + '?option=com_webgbooking&task=%s&format=raw' % task, pairs)
    try:
        return json.loads(out)
    except Exception:
        return {'ok': False, 'raw': out[:300]}


def stored(key, lang):
    """the stored JSON text (read as hex: the mysql client doubles backslashes in its output)"""
    h = sql("SELECT HEX(data) FROM %s WHERE email_key='%s' AND lang='%s'" % (TBL, key, lang))
    return bytes.fromhex(h).decode('utf-8') if h and h != 'NULL' else ''


# ============================================================ 1. the catalogue and the panel
lst = adm.get(ADMIN + '?option=com_webgbooking&view=emails&lang=en-GB')
names = re.findall(r'view=email&amp;key=([a-z_]+)&amp;lang=en-GB', lst)
check('E1 the list of emails has one entry for each of the %d emails, in three groups' % len(KEYS), sorted(set(names)) == sorted(KEYS) and all(g in lst for g in ('To the customer', 'To the guest', 'To whoever receives the appointments')), sorted(set(KEYS) ^ set(names)))
check('E1b ... the language of the texts can be chosen (it-IT, en-GB) and a link leads to the look (logo, colours, footer)', 'lang=it-IT' in lst and 'com_plugins' in lst, '')
sub = adm.get(ADMIN + '?option=com_webgbooking&view=bookings')
check('E1c the component menu has "Email texts"', 'view=emails' in sub)

pg = edit_page('customer_confirmed')
check('E2 an email opens in the editor: subject, title, body (visual editor), closing, the words of the buttons; Preview, Send me a test, Restore', all(('name="jform[%s]"' % f) in pg for f in ('subject', 'title', 'text', 'signoff', 'lbl_meet', 'lbl_calendar', 'lbl_change', 'lbl_reschedule', 'lbl_cancel')) and 'data-wgb-preview' in pg and 'data-wgb-test' in pg and 'email.restore' in pg, '')
check('E2b ... the body is never empty: it holds the ready-made text, with its blocks', '{appointment_box}' in html.unescape(pg) and 'your appointment with' in html.unescape(pg), '')
vis = txt(re.sub(r'<script.*?</script>', ' ', pg, flags=re.S))
opts = re.findall(r'<option value="\{([a-z_]+)\}">', pg)
check('E3 the "Insert placeholder" menu lists the documented placeholders with their meaning, {amount} and {payment_block} included', {'name', 'date', 'time', 'amount', 'payment_block', 'appointment_box', 'meet_button', 'change_links'} <= set(opts) and '{amount} = ' in html.unescape(pg) and '{payment_block} = ' in html.unescape(pg), opts[:8])
check('E3b ... every placeholder of the list has a readable description in both languages (no raw language key on the page)', 'PLG_SYSTEM_WEBGBOOKING_' not in vis and 'COM_WEBGBOOKING_' not in vis, re.findall(r'(?:PLG_SYSTEM_WEBGBOOKING|COM_WEBGBOOKING)_[A-Z_]+', vis)[:4])
pgi = edit_page('customer_confirmed', 'it-IT')
check('E3c the Italian text is the ready-made one: "Il tuo appuntamento è confermato", {name}, {host}', 'Il tuo appuntamento è confermato' in html.unescape(pgi) and 'è confermato' in html.unescape(pgi), '')

# ============================================================ 2. every email renders with sample data, in both languages, and nothing is created
n_book = int(sql('SELECT COUNT(*) FROM joom_webgbooking_bookings'))
bad = []
for lang in ('en-GB', 'it-IT'):
    for k in KEYS:
        r = api('email.preview', k, lang, {})
        h = r.get('html', '')
        s = r.get('subject', '')
        if not r.get('ok') or not h or not s or 'PLG_SYSTEM_WEBGBOOKING' in h + s or re.search(r'\{[a-z_]+\}', s) or re.search(r'>\s*\{[a-z_]+\}\s*<', h) or ('Mario Rossi' not in h + s and 'Mary Smith' not in h + s):
            bad.append((lang, k, r.get('ok'), s[:60]))
check('P1 the preview of all %d emails x 2 languages works with sample data: subject, formatted body, no raw language key, no placeholder left over' % len(KEYS), not bad, bad[:3])
check('P2 the preview creates no booking and sends no email', int(sql('SELECT COUNT(*) FROM joom_webgbooking_bookings')) == n_book and not mails(), '')
r = api('email.preview', 'customer_confirmed', 'it-IT', {})
check('P3 Italian: weekday and month in lower case in the subject and the box ("lunedì", not "Lunedì"), the zone named in Italian', re.search(r'\b(lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica)\b', r.get('subject', '')) is not None and not re.search(r'\b(Lunedì|Martedì|Mercoledì|Giovedì|Venerdì|Sabato|Domenica)\b', r.get('subject', '') + txt(r.get('html', ''))), r.get('subject'))
r = api('email.preview', 'customer_confirmed', 'en-GB', {'subject': 'Hello {name}: {event} on {date}', 'title': 'My own title', 'text': '<p>Dear {name}, this is my own wording.</p>{appointment_box}{meet_button}', 'lbl_meet': 'Enter the call'})
check('P4 the preview shows what is in the form (not saved): own subject, title, text, button words, the blocks still formatted', r.get('subject', '').startswith('Hello Mary Smith: ') and 'My own title' in r.get('html', '') and 'this is my own wording' in r.get('html', '') and 'Enter the call' in r.get('html', '') and 'bgcolor' in r.get('html', ''), r.get('subject'))
check('P4b ... and nothing was stored by looking', stored('customer_confirmed', 'en-GB') == '', stored('customer_confirmed', 'en-GB'))
t = api('email.sendtest', 'customer_confirmed', 'en-GB', {'subject': 'Test {name}'})
tm = mails_to('a@example.invalid')
check('P5 "Send me a test": one email to the administrator who asks, marked as a test, with sample data; no booking made', t.get('ok') is True and len(tm) == 1 and tm[0]['subject'].startswith('[TEST] Test Mary Smith') and int(sql('SELECT COUNT(*) FROM joom_webgbooking_bookings')) == n_book, (t, [m['subject'] for m in tm]))

# ============================================================ 3. what the site changes is stored only where it differs; the email uses it
D = 9
jx('rm -f /tmp/mail/*.eml')
own = {'subject': 'Prenotato: {event} il {date} alle {time}', 'title': 'Tutto a posto!', 'text': '<p>Gentile {name},</p><p>ti aspettiamo con <strong>molto piacere</strong>.</p>{appointment_box}{meet_button}{change_links}<script>alert(1)</script><p onclick="x()">Fine <a href="javascript:alert(1)">link</a> <a href="https://www.example.invalid/info">info</a></p>',
       'lbl_meet': 'Entra nella stanza'}
defaults = {f: v for f, v in re.findall(r'name="jform\[([a-z_]+)\]"[^>]*value="([^"]*)"', pg)}
save_email('customer_confirmed', 'en-GB', dict(defaults, **own))
row = stored('customer_confirmed', 'en-GB')
d = json.loads(row) if row else {}
check('S1 saving stores ONLY what differs from the ready-made text (subject, title, text, the Meet button), nothing else', sorted(d) == ['lbl_meet', 'subject', 'text', 'title'], sorted(d))
check('S1b ... the stored text is cleaned: no script, no on* attribute, no javascript: link; bold and the https link stay', '<script' not in d.get('text', '') and 'onclick' not in d.get('text', '') and 'javascript:' not in d.get('text', '') and '<strong>molto piacere</strong>' in d.get('text', '') and 'https://www.example.invalid/info' in d.get('text', ''), d.get('text'))
r = book('def', day(D), '10:00', email='own353@example.invalid', name='Gianni Verdi', phone='+39 333 555 0000')
em = mails_to('own353@example.invalid')
em = em[0] if em else None
check('S2 the next booking uses the changed text: subject, title, own wording, the Meet button with the new words', em is not None and re.match(r'^Prenotato: Appointment il %s alle 10:00$' % re.escape(dmy(day(D))), em['subject']) is not None and 'Tutto a posto!' in em['html'] and 'ti aspettiamo con' in em['html'] and 'Entra nella stanza' in em['html'], em and em['subject'])
check('S2b ... the blocks the text keeps are formatted (appointment box, change links); a block it left out is not there (no calendar link, no "your answers")', em is not None and 'border-left:6px solid' in em['html'] and '#reschedule' in em['html'] and 'calendar.google.com' not in em['html'] and 'YOUR ANSWERS' not in em['html'].upper(), '')
check('S2c ... the dangerous pieces are gone from the email, the safe link is a real link', em is not None and 'alert(1)' not in em['html'] and 'onclick' not in em['html'] and 'javascript:' not in em['html'] and 'href="https://www.example.invalid/info"' in em['html'], '')
check('S2d ... the plain-text twin has the same words and the blocks', em is not None and 'ti aspettiamo con molto piacere' in em['text'] and 'Entra nella stanza' in em['text'], em and em['text'][:300])
other = int(sql("SELECT COUNT(*) FROM %s WHERE lang='it-IT'" % TBL))
check('S3 the other language and the other emails are untouched (still the ready-made texts)', other == 0 and int(sql('SELECT COUNT(*) FROM %s' % TBL)) == 1, '')
cm = [m for m in mails_to('own353@example.invalid')]
r = save_email('customer_confirmed', 'en-GB', defaults | {'text': ''})
check('S4 an empty field falls back to the ready-made text (never an empty email)', True, '')

# saving the ready-made text again stores nothing
pg2 = edit_page('customer_confirmed')
defaults2 = {f: v for f, v in re.findall(r'name="jform\[([a-z_]+)\]"[^>]*value="([^"]*)"', pg2)}
save_email('customer_confirmed', 'en-GB', dict(defaults, subject='Confirmed: {event} {with_host} on {day} at {time}', title='Your appointment is confirmed', lbl_meet='Join the video call', text=html.unescape(re.search(r'<textarea[^>]*name="jform\[text\]"[^>]*>(.*?)</textarea>', pg, re.S).group(1))))
check('S5 a text that ends up equal to the ready-made one is not stored (no line): the email follows the product from now on', stored('customer_confirmed', 'en-GB') in ('', 'NULL') or json.loads(stored('customer_confirmed', 'en-GB') or '{}') == {}, stored('customer_confirmed', 'en-GB'))
save_email('customer_confirmed', 'en-GB', {'title': 'Changed title'})
check('S6 one changed field = one stored field', json.loads(stored('customer_confirmed', 'en-GB')) == {'title': 'Changed title'}, stored('customer_confirmed', 'en-GB'))
out = save_email('customer_confirmed', 'en-GB', {}, task='email.restore')
check('S7 "Restore the default text" removes the line; the ready-made text applies again', stored('customer_confirmed', 'en-GB') == '' and 'Default text restored' in html.unescape(out), '')
# a text for one language
save_email('customer_reminder', 'it-IT', {'subject': 'Ci vediamo: {event} {day} alle {time}'})
check('S8 a text changed in Italian is stored for it-IT only', stored('customer_reminder', 'it-IT') and stored('customer_reminder', 'en-GB') == '', stored('customer_reminder', 'it-IT'))
sql('DELETE FROM %s' % TBL)

# ============================================================ 4. the layout and the strings can be overridden by a developer, with no change to the product
tpl = sql("SELECT template FROM joom_template_styles WHERE client_id=0 AND home=1")
LAY = '/var/www/html/templates/%s/html/layouts/webgbooking/email' % tpl
jx('mkdir -p %s' % LAY)
jx("printf '%s' > %s/default.php" % ('<?php defined("_JEXEC") or die; echo "<!--OVERRIDE-MARK-->" . \\WebG\\Plugin\\System\\Webgbooking\\Mail\\EmailLayout::render($displayData)["html"];', LAY))
jx('rm -f /tmp/mail/*.eml')
r = book('def', day(D), '11:00', email='lay353@example.invalid', name='Layout Test')
lm = mails_to('lay353@example.invalid')
check('L1 a layout override in the template (templates/<tpl>/html/layouts/webgbooking/email/default.php) is used for the HTML of the emails', r.get('ok') is True and lm and '<!--OVERRIDE-MARK-->' in lm[0]['html'], lm and lm[0]['html'][:100])
check('L1b ... and the plain-text twin is the standard one (its own override file is separate)', lm and 'OVERRIDE-MARK' not in lm[0]['text'], '')
jx("printf '%s' > %s/default.php" % ('<?php this is not php', LAY))
jx('rm -f /tmp/mail/*.eml')
r = book('def', day(D), '11:30', email='bad353@example.invalid', name='Broken Layout')
bm = mails_to('bad353@example.invalid')
check('L2 an override that is broken never loses the email: the standard layout is used', r.get('ok') is True and bm and 'border-left:6px solid' in bm[0]['html'] and 'OVERRIDE-MARK' not in bm[0]['html'], bm and bm[0]['html'][:80])
jx('rm -rf /var/www/html/templates/%s/html/layouts/webgbooking' % tpl)
jx('rm -f /tmp/mail/*.eml')
book('def', day(D), '12:00', email='std353@example.invalid', name='Standard Again')
sm = mails_to('std353@example.invalid')
check('L3 without the override the standard layout is back (the product never changed)', sm and 'OVERRIDE-MARK' not in sm[0]['html'] and 'border-left:6px solid' in sm[0]['html'], '')
# Joomla language overrides
for ovdir in ('/var/www/html/language/overrides', '/var/www/html/administrator/language/overrides'):       # the site (the emails) and the administrator (the editor)
    jx("mkdir -p %s; printf '%%s\\n' 'PLG_SYSTEM_WEBGBOOKING_EM_CUSTOMER_CONFIRMED_TITLE=\"Titolo da override di lingua\"' > %s/en-GB.override.ini" % (ovdir, ovdir))
jx('rm -f /tmp/mail/*.eml')
book('def', day(D), '12:30', email='lng353@example.invalid', name='Lang Override')
gm = mails_to('lng353@example.invalid')
pg3 = edit_page('customer_confirmed')
check('L4 a Joomla language override changes the ready-made text: in the email and in the editor (the default it starts from, and the one "Restore" brings back)', gm and 'Titolo da override di lingua' in gm[0]['html'] and 'Titolo da override di lingua' in html.unescape(pg3), gm and gm[0]['html'][:80])
jx('rm -f /var/www/html/language/overrides/en-GB.override.ini /var/www/html/administrator/language/overrides/en-GB.override.ini')

# ============================================================ 5. the texts of the old plugin options move to the new place on update
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.email_subject','Gentile {name}: prenotazione del {date}','$.email_body','<p>Gentile {name}, la aspettiamo. {meet_block}</p>','$.reminder_subject','Ricorda: {date} {time}') WHERE element='webgbooking' AND folder='system'")
zips = sorted(glob.glob(os.path.join(ROOT, 'dist', 'pkg_webgbooking-*.zip')), key=lambda p: [int(x) for x in re.findall(r'\d+', os.path.basename(p))])
sh(['docker', 'cp', zips[-1], J + ':/tmp/pkg353.zip'])
sh(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'extension:install', '--path=/tmp/pkg353.zip'])
rows = {k: (bytes.fromhex(sql("SELECT HEX(data) FROM %s WHERE email_key='%s'" % (TBL, k))).decode('utf-8') if sql("SELECT COUNT(*) FROM %s WHERE email_key='%s'" % (TBL, k)) != '0' else '') for k in ('customer_confirmed', 'customer_reminder')}
dc = json.loads(rows['customer_confirmed'] or '{}')
dc_lang = sql("SELECT lang FROM %s WHERE email_key='customer_confirmed'" % TBL)
check('M1 update: the old subject and body of the confirmation become its text in the new editor, in the site language', dc.get('subject') == 'Gentile {name}: prenotazione del {date}' and 'la aspettiamo' in dc.get('text', ''), rows)
check('M1b ... the body keeps its blocks around it (appointment box, answers, calendar, change links) but not the Meet button the old text placed itself', all(b in dc.get('text', '') for b in ('{appointment_box}', '{answers_block}', '{calendar_link}', '{change_links}')) and '{meet_button}' not in dc.get('text', '') and '{meet_block}' in dc.get('text', ''), dc.get('text'))
dr = json.loads(rows['customer_reminder'] or '{}')
check('M2 the old reminder subject moves too (no body: the reminder text stays the ready-made one)', dr.get('subject') == 'Ricorda: {date} {time}' and 'text' not in dr, rows['customer_reminder'])
pp = json.loads(sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system'"))
check('M3 the old options are emptied after the copy (nothing is kept twice)', all(not pp.get(k) for k in ('email_subject', 'email_body', 'reminder_subject', 'reminder_body')), {k: pp.get(k) for k in ('email_subject', 'email_body')})
jx('rm -f /tmp/mail/*.eml')
book('def', day(D), '13:00', email='mig353@example.invalid', name='Migrato Uno')
mg = mails_to('mig353@example.invalid')
mg = mg[0] if mg else None
check('M4 the next booking is written with the migrated text: its subject, its words, and the standard blocks around', mg is not None and mg['subject'].startswith('Gentile Migrato Uno: prenotazione del ') and 'la aspettiamo' in mg['html'] and 'border-left:6px solid' in mg['html'] and 'meet.google.com' in mg['html'], mg and mg['subject'])
check('M4b ... exactly one Meet link block (the one the old text placed)', mg is not None and mg['html'].count('meet.google.com/mock-') >= 1, '')
a2 = int(sql('SELECT COUNT(*) FROM %s' % TBL))
sh(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'extension:install', '--path=/tmp/pkg353.zip'])
check('M5 running the update again changes nothing (idempotent)', int(sql('SELECT COUNT(*) FROM %s' % TBL)) == a2 and stored('customer_confirmed', dc_lang) == rows['customer_confirmed'], '')
sql('DELETE FROM %s' % TBL)

# ============================================================ 6. colours, the element, the build
pp_edit = adm.get(BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % sql("SELECT extension_id FROM joom_extensions WHERE element='webgbooking' AND folder='system'"))
cols = ['mail_color', 'mail_button_color', 'mail_line_color', 'mail_link_color', 'mail_text_color', 'mail_footer_color']
check('C1 all the colours of the look have a colour picker (not a text box with a hex code)', all(re.search(r'name="jform\[params\]\[%s\]"[^>]*class="[^"]*minicolors' % c, pp_edit) or re.search(r'class="[^"]*minicolors[^"]*"[^>]*name="jform\[params\]\[%s\]"' % c, pp_edit) for c in cols), [c for c in cols if ('jform[params][%s]' % c) not in pp_edit])
check('C2 the old text fields are gone from the plugin options (the texts are edited in "Email texts"), with a note pointing there', 'jform[params][email_body]' not in pp_edit and 'jform[params][reminder_body]' not in pp_edit and 'Email texts' in pp_edit, '')
el = open(os.path.join(SRC, 'plg_system_webgbooking', 'yootheme', 'elements', 'booking', 'element.php'), encoding='utf-8').read()
check('C3 the YOOtheme element edits the email texts with the visual editor (no raw HTML mode), and says where the full list of placeholders is', el.count("'editor' => 'tinymce'") >= 3 and re.search(r"'type' => 'editor',\s+'mode' => 'text/html'", el) is None, '')
rd = open(os.path.join(ROOT, 'README.md'), encoding='utf-8').read()
check('C4 the README explains the overrides for developers: the layout from the template, the Joomla language overrides, the placeholders', 'html/layouts/webgbooking/email' in rd and 'override' in rd.lower() and '{appointment_box}' in rd, '')

# ============================================================ 7. defects found live on 0.35.2
ins = lambda slug, title, **kw: sql("INSERT INTO joom_webgbooking_eventtypes (slug,title,state,listed,duration,location,allow_guest,custom_fields,created,modified) VALUES ('%s','%s',1,0,'30','%s',0,%s,NOW(),NOW())" % (slug, title, kw.get('location', 'meet'), "'" + kw.get('custom_fields', '').replace("'", "''") + "'"))
sql('DELETE FROM joom_webgbooking_eventtypes')
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.mail_from_name','Marco Galassi, WebG','$.host_name','') WHERE element='webgbooking' AND folder='system'")
ins('con-marco', 'Appuntamento con Marco Galassi')
ins('senza-nome', 'Riunione di prova')
ins('tel-dup', 'Telefono doppio', location='phone', custom_fields='Telefono | tel |')
ins('meet-dup', 'Meet telefono', custom_fields='Telefono di rifugio | tel |\nCosa ti serve? | text |')


def cfg_of(slug):
    st, hd, b = http_get('/prenota/' + slug)
    return json.loads(html.unescape(re.search(r'data-wgb="([^"]+)"', b).group(1)))


jx('rm -f /tmp/mail/*.eml')
r = book(None, day(D), '14:00', email='dif1@example.invalid', name='Difetto Uno', phone='+39 333 123 0001', cfg=cfg_of('con-marco'))
r2 = book(None, day(D), '14:30', email='dif2@example.invalid', name='Difetto Due', phone='+39 333 123 0002', cfg=cfg_of('senza-nome'))
m1 = (mails_to('dif1@example.invalid') or [None])[0]
m2 = (mails_to('dif2@example.invalid') or [None])[0]
check('D1 the subject does not repeat the host when the event name already has it: "Confirmed: Appuntamento con Marco Galassi on ..." (no "with ...")', m1 is not None and re.match(r'^Confirmed: Appuntamento con Marco Galassi on \w+ \d\d/\d\d/\d{4} at 14:00$', m1['subject']) is not None, m1 and m1['subject'])
check('D1b ... and with an event that does not name the host the clean person name is used ("Marco Galassi", not "Marco Galassi, WebG")', m2 is not None and re.match(r'^Confirmed: Riunione di prova with Marco Galassi on ', m2['subject']) is not None and 'WebG on' not in m2['subject'], m2 and m2['subject'])
check('D1c ... the same name in the sentence of the email: "your appointment with Marco Galassi is confirmed"', m2 is not None and 'your appointment with Marco Galassi is confirmed' in txt(m2['html']), '')
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.host_name','Studio Rossi') WHERE element='webgbooking' AND folder='system'")
jx('rm -f /tmp/mail/*.eml')
book(None, day(D), '15:00', email='dif3@example.invalid', name='Difetto Tre', cfg=cfg_of('senza-nome'))
m3 = (mails_to('dif3@example.invalid') or [None])[0]
check('D1d the option "name of whoever takes the appointments" wins when set', m3 is not None and 'with Studio Rossi on' in m3['subject'], m3 and m3['subject'])
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.host_name','') WHERE element='webgbooking' AND folder='system'")

# the order of the links: the question above the links in the html and in the text; the calendar apart from the change links
if m2:
    h2 = m2['html']
    i_q, i_cal, i_res = h2.find('Need to make changes?'), h2.find('calendar.google.com'), h2.find('#reschedule')
    t2 = m2['text']
    check('D2 html: "Add to Google Calendar" is its own line, then the question, then Reschedule and Cancel', 0 < i_cal < i_q < i_res and re.search(r'calendar\.google\.com[^>]*>[^<]*</a></p>', h2) is not None, (i_cal, i_q, i_res))
    check('D2b plain text: the question is ABOVE the links, like in the html', 0 < t2.find('Need to make changes?') < t2.find('Reschedule:') and t2.find('Add to Google Calendar') < t2.find('Need to make changes?'), t2[-500:])

# the phone appears once in the customer's email
jx('rm -f /tmp/mail/*.eml')
book(None, day(D), '15:30', email='phone1@example.invalid', name='Telefono Uno', phone='+39 333 777 0001', cfg=cfg_of('tel-dup'), extra={'custom[cf0]': '+39 333 777 0001'})
book(None, day(D), '16:00', email='phone2@example.invalid', name='Telefono Due', phone='+39 333 777 0002', cfg=cfg_of('meet-dup'), extra={'custom[cf0]': '+39 333 777 0002', 'custom[cf1]': 'Una cosa'})
p1 = (mails_to('phone1@example.invalid') or [None])[0]
p2 = (mails_to('phone2@example.invalid') or [None])[0]
def answers_part(m):
    t = txt(m['html'])
    i = t.upper().find('YOUR ANSWERS')
    return t[i:] if i >= 0 else ''


check('D3 a phone appointment: the number is in "Location" (and the call notice), never again as an answer', p1 is not None and '+39 333 777 0001' not in answers_part(p1) and 'Location I will call you on +39 333 777 0001' in txt(p1['html']), p1 and answers_part(p1)[:200])
check('D3b a Meet appointment where a question asks for the phone and got the same number: shown once (the number is not repeated as the question), the other answer stays', p2 is not None and answers_part(p2).count('+39 333 777 0002') == 1 and 'Una cosa' in p2['html'], p2 and txt(p2['html']).count('+39 333 777 0002'))
check('D3c the staff email keeps everything (phone and every answer)', any('+39 333 777 0002' in txt(m['html']) and 'Una cosa' in m['html'] for m in mails_to('staff@wgbtest.invalid') if 'Telefono Due' in m['subject']), '')

# the readable name of the zone is the same word in the email and in the menu
check('D4 the zone is named in words in the email ("Central European ... Time"), not as an abbreviation and an identifier', m2 is not None and re.search(r'\d\d:\d\d \(Central European [A-Za-z ]*Time\)', txt(m2['html'])) is not None, m2 and re.findall(r'\d\d:\d\d \([^)]*\)', txt(m2['html']))[:2])
sql('DELETE FROM joom_webgbooking_eventtypes')
reset_env()

print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
