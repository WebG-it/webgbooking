"""0.34.3: one email layout for every email of the plugin. Structure, plain-text alternative, escaping of every customer value,
branding from the plugin options, custom text inside the layout, staff data table. Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, html as htmlmod
from html.parser import HTMLParser
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from wgbtest import *

reset_env()
tp.fresh()
D = 6
STAFF = 'staff@wgbtest.invalid'


def mails_to(addr):
    return [decode_mail(mail_text(p)) for p in mails() if addr in mail_text(p)[:300]]


def txt(h):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', htmlmod.unescape(h)))


class Probe(HTMLParser):
    def __init__(self):
        super().__init__()
        self.tags, self.bad_attrs, self.hrefs, self.imgs, self.lang = [], [], [], [], ''

    def handle_starttag(self, tag, attrs):
        self.tags.append(tag)
        d = dict(attrs)
        if tag == 'html':
            self.lang = d.get('lang', '')
        for k, v in attrs:
            if k.startswith('on'):
                self.bad_attrs.append((tag, k))
        if tag == 'a':
            self.hrefs.append(d.get('href', ''))
        if tag == 'img':
            self.imgs.append(d)


def probe(h):
    p = Probe()
    p.feed(h)
    return p


def structure_ok(m):
    h, t = m['html'], m.get('text', '')
    p = probe(h)
    problems = []
    for need, label in (('<meta name="viewport"', 'viewport'), ('color-scheme', 'color-scheme'), ('prefers-color-scheme: dark', 'dark css'), ('max-width:620px', 'mobile css'), ('role="presentation"', 'role presentation'), ('width="600"', '600px card'), ('display:none;font-size:1px', 'preheader')):
        if need not in h:
            problems.append(label)
    if not p.lang:
        problems.append('lang')
    if 'script' in p.tags:
        problems.append('script tag')
    if p.bad_attrs:
        problems.append('on* attribute %s' % p.bad_attrs[:2])
    if [u for u in p.hrefs if not re.match(r'^(https?://|mailto:|tel:)', u)]:
        problems.append('bad href %s' % [u for u in p.hrefs if not re.match(r'^(https?://|mailto:|tel:)', u)][:2])
    if [i for i in p.imgs if not i.get('alt')]:
        problems.append('image without alt')
    if not t.strip() or '<' in t or '>' in t:
        problems.append('text alternative missing or not plain')
    return problems


ALL = []


def bad_styles(h):
    """Every style attribute and style block of an email: no empty value (color:; or color:\"), no PHP leftovers."""
    bad = []
    for st in re.findall(r'style="([^"]*)"', h):
        if re.search(r'(^|;)\s*[a-z-]+\s*:\s*(;|$)', st) or 'Array' in st or 'Notice' in st or 'Warning' in st:
            bad.append(st[:90])
    return bad


def collect(addr_filter=None):
    out = []
    for pth in mails():
        raw = mail_text(pth)
        m = decode_mail(raw)
        m['raw'] = raw
        out.append(m)
        ALL.append(m)
    return out


# ============================================================================ 1. every kind of email shares the layout
print('--- 1. structure of every email')
jx('rm -f /tmp/mail/*.eml')
seen = {}
r = book('hourg', day(D), '09:00', email='cust1@example.invalid', name='Cliente Uno', phone='+39 333 111 2222', guest='ospite1@example.invalid', extra={'custom[cf0]': 'Torino', 'custom[cf1]': 'Sito nuovo'}) if False else None
r = book('brevo1', day(D), '09:00', email='cust1@example.invalid', name='Cliente Uno', phone='+39 333 111 2222', extra={'custom[cf0]': 'Torino', 'custom[cf1]': 'Sito nuovo'})
b1 = last_id(); tok1 = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % b1)
all_mail = collect()
cust = next((m for m in all_mail if 'cust1@' in m['rcpt']), None)
stf = next((m for m in all_mail if STAFF in m['rcpt'] and 'ooking' in m['subject']), None)
for name, m in (('customer confirmation', cust), ('staff new booking', stf)):
    check('M1 %s: complete structure (viewport, colour schemes, dark and mobile CSS, 600 px, preheader, lang, no script/on*, safe links, plain text alternative)' % name, m is not None and not structure_ok(m), m and structure_ok(m))
check('M2 the customer email has title, appointment box (service, date with weekday, time with zone, duration, where), main button, footer', cust is not None and all(x in cust['html'] for x in ('Your appointment is confirmed', 'Europe/Rome', 'Join the video call', 'This email was sent automatically')) and re.search(r'(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday) \d\d/\d\d/\d{4}', txt(cust['html'])) is not None and '30 minutes' in cust['html'], cust and txt(cust['html'])[:300])
check('M3 the plain-text alternative carries the same facts: title, date, time, the Meet address, the manage address', cust is not None and 'Your appointment is confirmed' in cust['text'] and 'Europe/Rome' in cust['text'] and 'meet.google.com' in cust['text'] and 'action=manage' in cust['text'], cust and cust['text'][:400])
check('M4 the staff email is operational: customer table (name, email, phone), the answers of the form, the booking number, and the button "Open in the backend" to that booking', stf is not None and all(x in stf['html'] for x in ('cust1@example.invalid', '+39 333 111 2222', 'Torino', 'Sito nuovo', 'Open in the backend')) and ('view=booking&amp;id=%d' % b1) in stf['html'], stf and txt(stf['html'])[:300])
check('M4b ...and its text alternative has the same data', stf is not None and 'Torino' in stf['text'] and 'cust1@example.invalid' in stf['text'] and ('id=%d' % b1) in stf['text'], stf and stf['text'][:300])

# the other kinds, one by one
jx('rm -f /tmp/mail/*.eml')
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % b1)
Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tok1, 'date': day(D), 'time': '11:00'})
moved = collect()
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tok1})
cancelled = collect()
rs = [m for m in moved if 'cust1@' in m['rcpt']]
rstaff = [m for m in moved if STAFF in m['rcpt']]
kinds = {'customer rescheduled': rs[0] if rs else None, 'staff moved': rstaff[0] if rstaff else None}
cc = [m for m in cancelled if 'cust1@' in m['rcpt'] and 'ancelled' in m['subject']]
cs = [m for m in cancelled if STAFF in m['rcpt'] and 'ancelled' in m['subject']]
kinds['customer cancelled'] = cc[-1] if cc else None
kinds['staff cancelled'] = cs[-1] if cs else None
for name, m in kinds.items():
    check('M5 %s: same layout and structure' % name, m is not None and not structure_ok(m), m and structure_ok(m))
check('M5b titles say what happened', all(kinds[k] is not None for k in kinds) and 'has been moved' in kinds['customer rescheduled']['html'] and 'Appointment moved' in kinds['staff moved']['html'] and 'has been cancelled' in kinds['customer cancelled']['html'] and 'Appointment cancelled' in kinds['staff cancelled']['html'], [k for k in kinds if kinds[k] is None])
check('M5c the reschedule email still carries the .ics (calendar update) and the cancel one the .ics CANCEL', kinds['customer rescheduled'] is not None and 'METHOD:REQUEST' in kinds['customer rescheduled']['ics'] and kinds['customer cancelled'] is not None and 'METHOD:CANCEL' in kinds['customer cancelled']['ics'], '')

# ============================================================================ 2. nothing that comes from the customer is ever markup
print('--- 2. escaping')
EV = "<script>alert(1)</script><img src=x onerror=alert(2)>\"'&amp;"
jx('rm -f /tmp/mail/*.eml')
r = book('brevo1', day(D + 2), '09:00', email='evil1@example.invalid', name='Nome Valido', phone='+39 333 000 1111', extra={'custom[cf0]': 'ok', 'custom[cf1]': 'ok'})
check('X0 the booking was accepted', r.get('ok'), r)
eb = last_id(); etok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % eb)
# the form input filter strips tags on the way in, so the hostile values are put in the table the way an old row or a manual edit could hold them
q = EV.replace("'", "''")
sql("UPDATE joom_webgbooking_bookings SET customer_name='%s', notes='%s', guest_email='g@example.invalid', customer_phone='%s', updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % (q, q, q, eb))
jx('rm -f /tmp/mail/*.eml')
Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': etok, 'date': day(D + 2), 'time': '11:00'})
ev_mail = collect()
check('X1 hostile name, phone and notes in the booking: the customer, guest and staff emails hold no script/img/on* element and no unsafe link', len(ev_mail) >= 2 and all(not [x for x in structure_ok(m) if x != 'text alternative missing or not plain'] and '<script' not in m['html'].lower() and '<img src=x' not in m['html'] for m in ev_mail), [structure_ok(m) for m in ev_mail])
check('X2 ...the hostile text is there as TEXT (escaped), nothing lost, in the HTML', len(ev_mail) >= 2 and all('&lt;script&gt;alert(1)&lt;/script&gt;' in m['html'] for m in ev_mail if STAFF in m['rcpt'] or 'evil1@' in m['rcpt']), [m['rcpt'] for m in ev_mail])
check('X3 ...and as plain text in the text alternative (no double escaping, no entities)', any('<script>alert(1)</script>' in m['text'] and '&lt;' not in m['text'] for m in ev_mail), [m['text'][:150] for m in ev_mail][:1])
tp.fresh()

# ============================================================================ 3. branding from the plugin options
print('--- 3. branding')
base = ' '.join("'%s=%s'" % (k, v) for k, v in {'mail_logo': 'images/joomla_black.png#joomlaImage://local-images/joomla_black.png?width=225&height=50', 'mail_color': '#ffe066', 'mail_button_color': '#0f766e',
                                                  'mail_footer': 'Via Roma 1, 10100 Torino' + chr(92) + 'nP.IVA 01234567890' + chr(92) + 'Privacy: https://www.example.invalid/privacy', 'mail_from_name': 'Studio Esempio'}.items())
jx("php /tmp/mock/init.php %s >/dev/null" % base)
jx("sed -i 's/\\\\\\\\n/\\\\n/g' /dev/null")
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.mail_footer','Via Roma 1, 10100 Torino\\nP.IVA 01234567890\\nPrivacy: https://www.example.invalid/privacy') WHERE element='webgbooking' AND folder='system'")
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 2), '09:00', email='brand1@example.invalid', name='Cliente Brand')
bm = next((m for m in collect() if 'brand1@' in m['rcpt']), None)
h = bm['html'] if bm else ''
pr = probe(h)
imgs = [i for i in pr.imgs if 'joomla_black.png' in i.get('src', '')]
check('B1 the logo chosen with the media field (value with the #joomlaImage suffix) is shown, full address under the site, with the site name as alt text', len(imgs) >= 1 and imgs[0]['src'].startswith('http') and '#' not in imgs[0]['src'] and imgs[0].get('alt') == 'wgbtest', [i.get('src') for i in pr.imgs])
check('B2 the main colour is the accent line and the box edge; the button colour is the button background, its text is white on the dark teal', 'bgcolor="#ffe066"' in h and 'border-left:6px solid #ffe066' in h and 'bgcolor="#0f766e"' in h and re.search(r'class="btn-a"[^>]*color:#ffffff', h) is not None, h[:200])
m_l = re.search(r'class="t-link"[^>]*style="color:(#[0-9a-f]{6})', h) or re.search(r'a class="t-link" style="color:(#[0-9a-f]{6})', h)
def lum(c):
    f = lambda v: (v / 255 / 12.92) if v / 255 <= 0.03928 else (((v / 255) + 0.055) / 1.055) ** 2.4
    r_, g_, b_ = int(c[1:3], 16), int(c[3:5], 16), int(c[5:7], 16)
    return 0.2126 * f(r_) + 0.7152 * f(g_) + 0.0722 * f(b_)
def contrast(a, b):
    la, lb = lum(a), lum(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)
links = re.findall(r'color:(#[0-9a-f]{6});text-decoration:underline', h.split('class="px footer"')[0])
check('B3 an unreadable main colour (pale yellow) does not make unreadable links: every link colour reads at 4.5:1 on white', links and all(contrast(c, '#ffffff') >= 4.5 for c in links), links[:4])
check('B4 the footer text is in the footer (lines kept, the address as a link), and the sender name is the one set in the options', 'Via Roma 1, 10100 Torino' in h.replace(chr(0x200B), '') and 'P.IVA 01234567890' in h.replace(chr(0x200B), '') and 'href="https://www.example.invalid/privacy"' in h and 'Studio Esempio' in bm['raw'][:1500], bm and bm['raw'][:600])
check('B5 the plain-text alternative has the footer text too', bm is not None and 'Via Roma 1' in bm['text'] and 'https://www.example.invalid/privacy' in bm['text'], bm and bm['text'][-300:])
tp.fresh()
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 2), '10:00', email='plain1@example.invalid', name='Senza Marchio')
pm = next((m for m in collect() if 'plain1@' in m['rcpt']), None)
ph = pm['html'] if pm else ''
check('B6 neutral by default (no options): no logo, the site name as text in the header, the default blue accent, clean footer', pm is not None and not probe(ph).imgs and 'bgcolor="#2b59c3"' in ph and '>wgbtest<' in ph and not structure_ok(pm), pm and structure_ok(pm))

# ============================================================================ 4. the element's own text goes INTO the layout
print('--- 4. custom text')
jx('rm -f /tmp/mail/*.eml')
tp.fresh()
book('legacyedit', day(D + 14), '09:00', email='custom1@example.invalid', name='Testo Elemento')
cm = next((m for m in collect() if 'custom1@' in m['rcpt']), None)
check('C1 an element with its own email text: the text is in the body of the layout, which stays (header, title, appointment box, footer, meet button)', cm is not None and 'Ti aspettiamo' in cm['html'] and all(x in cm['html'] for x in ('Your appointment is confirmed', 'class="box"', 'class="px footer"', 'Join the video call')) and not structure_ok(cm), cm and structure_ok(cm))
check('C2 ...and in the plain-text alternative', cm is not None and 'Ti aspettiamo' in cm['text'], cm and cm['text'][:300])

# ============================================================================ 5. dates in the staff subjects
print('--- 5. staff subject dates')
tp.fresh()
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 5), '09:00', email='sd1@example.invalid', name='Data Oggetto')
sb = last_id(); stok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % sb)
sm = next((m for m in collect() if STAFF in m['rcpt'] and 'ooking' in m['subject']), None)
check('D1 the staff subject of a new booking has the date as 28/10/2026 (site order), not 2026-10-28', sm is not None and dmy(day(D + 5)) in sm['subject'] and day(D + 5) not in sm['subject'], sm and sm['subject'])
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % sb)
jx('rm -f /tmp/mail/*.eml')
Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': stok, 'date': day(D + 5), 'time': '11:00'})
mv = next((m for m in collect() if STAFF in m['rcpt']), None)
check('D2 the staff subject of a moved appointment and the previous slot in its text use the same order', mv is not None and dmy(day(D + 5)) in mv['subject'] and dmy(day(D + 5)) in mv['html'] and day(D + 5) not in mv['subject'], mv and mv['subject'])
sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % sb)
jx('rm -f /tmp/mail/*.eml')
Sess().post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': stok})
cx = next((m for m in collect() if STAFF in m['rcpt']), None)
check('D3 the staff subject of a cancelled appointment too', cx is not None and dmy(day(D + 5)) in cx['subject'] and day(D + 5) not in cx['subject'], cx and cx['subject'])

# ============================================================================ 6. the colours of a brand look (the WebG newsletter palette)
print('--- 6. brand colours')
tp.fresh()


def opts(**kv):
    args = ','.join("'$.%s','%s'" % (k, v) for k, v in kv.items())
    sql("UPDATE joom_extensions SET params=JSON_SET(params,%s) WHERE element='webgbooking' AND folder='system'" % args)


opts(mail_color='#ee6c2d', mail_line_color='#fda079', mail_link_color='#b34a16', mail_button_color='#fd9265', mail_text_color='#363650', mail_footer_color='#363650')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 6), '09:00', email='wg1@example.invalid', name='Palette Webg')
wm = next((m for m in collect() if 'wg1@' in m['rcpt']), None)
wh = wm['html'] if wm else ''
check('W1 top line in its own colour, box edge in the main colour, button in its colour with dark text (white would not read on peach)', 'bgcolor="#fda079"' in wh and 'border-left:6px solid #ee6c2d' in wh and 'bgcolor="#fd9265"' in wh and re.search(r'class="btn-a"[^>]*color:#111827', wh) is not None, wh[:120])
check('W2 links in the chosen colour (it already reads on white), text and titles in the chosen text colour', 'color:#b34a16;text-decoration:underline' in wh and wh.count('color:#363650') >= 4, wh.count('color:#363650'))
check('W3 footer background in its colour, footer text light on it, dark-mode footer rule kept', 'class="px footer" style="padding:26px 40px 28px 40px;background-color:#363650;' in wh and '.footer { background-color:#0f1017' in wh, '')
check('W4 in dark mode the link colour follows the line colour (peach), not a derived one', 'a.t-link { color:#fda079' in wh, re.findall(r't-link, a.t-link [{] color:#[0-9a-f]{6}', wh))
opts(mail_text_color='#eeeeee', mail_link_color='#f4e04d', mail_footer_color='#f1f3f8')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 6), '10:00', email='wg2@example.invalid', name='Palette Chiara')
w2 = next((m for m in collect() if 'wg2@' in m['rcpt']), None)
h2 = w2['html'] if w2 else ''
lk = re.findall(r'color:(#[0-9a-f]{6});text-decoration:underline', h2.split('class="px footer"')[0])
check('W5 a text colour that does not read on white is ignored, a link colour too pale is darkened until it reads', 'color:#eeeeee' not in h2.split('class="px footer"')[0] and lk and all(contrast(c, '#ffffff') >= 4.5 for c in lk), lk[:3])
check('W6 a light footer background gets dark footer text with a thin top border, and in dark mode still turns dark with light text', 'background-color:#f1f3f8;border-top:1px solid #e3e6ec;' in h2 and re.search(r'class="px footer".*?color:#111827', h2, re.S) is not None and '.footer { background-color:#0f1017' in h2 and '.footer .f1, .footer a' in h2 and 'class="f1"' in h2, '')
opts(mail_text_color='red', mail_link_color='javascript:1', mail_footer_color='#12', mail_line_color='x', mail_color='', mail_button_color='')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 6), '11:00', email='wg3@example.invalid', name='Palette Errata')
w3 = next((m for m in collect() if 'wg3@' in m['rcpt']), None)
h3 = w3['html'] if w3 else ''
check('W7 invalid values in the new options are ignored: nothing of them reaches the markup, the default look stays', w3 is not None and 'javascript:1' not in h3 and 'bgcolor="#2b59c3"' in h3 and 'background-color:#2d3142;' in h3 and not structure_ok(w3), w3 and structure_ok(w3))
opts(mail_logo='images/joomla_black.png', mail_logo_dark='', mail_text_color='', mail_link_color='', mail_footer_color='', mail_line_color='')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 6), '12:00', email='wg4@example.invalid', name='Palette Logo')
w4 = next((m for m in collect() if 'wg4@' in m['rcpt']), None)
h4 = w4['html'] if w4 else ''
check('W8 a logo without a dark version keeps a white plate in dark mode (no dark rule on its header); with a dark version the header turns dark', 'class="px hdr-plate"' in h4 and '.hdr-plate' not in h4, '')
opts(mail_logo_dark='images/joomla_black.png')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 6), '13:00', email='wg5@example.invalid', name='Palette Logo Due')
w5 = next((m for m in collect() if 'wg5@' in m['rcpt']), None)
check('W8b ...with a dark version the header turns dark and the dark logo is shown', w5 is not None and 'class="px hdr"' in w5['html'] and 'logo-d' in w5['html'], '')
tp.fresh()

# ============================================================================ 7. paragraph colour, business name, footer that mail apps cannot turn into blue links
print('--- 7. body colour, business name, footer')
tp.fresh()
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 7), '09:00', email='bn0@example.invalid', name='Corpo Colore')
b0 = next((m for m in collect() if 'bn0@' in m['rcpt']), None)
h0 = b0['html'] if b0 else ''
paras = re.findall(r'<p class="t-body" style="([^"]*)"', h0)
check('Y1 every paragraph of the body has its text colour (the intro paragraphs used to come out as color:;)', len(paras) >= 2 and all(re.search(r'color:#[0-9a-f]{6};', x) for x in paras), paras[:3])
opts(mail_text_color='#363650')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 7), '10:00', email='bn1@example.invalid', name='Corpo Colore Due')
b1m = next((m for m in collect() if 'bn1@' in m['rcpt']), None)
paras = re.findall(r'<p class="t-body" style="([^"]*)"', b1m['html']) if b1m else []
check('Y2 ...and it is the text colour of the options', len(paras) >= 2 and all('color:#363650;' in x for x in paras), paras[:3])
opts(mail_text_color='', mail_business_name='WebG', mail_logo='images/joomla_black.png',
     mail_footer='Corso Taranto 62, 10154 Torino (TO)' + chr(92) + 'nP. IVA 12774610013' + chr(92) + 'nPrivacy: https://www.webg.it/privacy.html')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 7), '11:00', email='bn2@example.invalid', name='Nome Attivita')
ms7 = collect()
bn = next((m for m in ms7 if 'bn2@' in m['rcpt']), None)
bs = next((m for m in ms7 if STAFF in m['rcpt'] and 'ooking' in m['subject']), None)
hb = bn['html'] if bn else ''
check('Y3 business name: the alt text of the logo and the first footer line use it (not the site name)', 'alt="WebG"' in hb and 'alt="wgbtest"' not in hb and re.search(r'font-weight:700;color:#ffffff;">WebG</p>', hb) is not None and '>wgbtest<' not in hb, '')
check('Y3b ...in the staff email footer note too, and in the text alternative', bs is not None and 'booking system of WebG.' in bs['text'] and 'booking system of WebG.' in bs['html'], bs and bs['text'][-120:])
opts(mail_logo='')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 7), '12:00', email='bn3@example.invalid', name='Nome Senza Logo')
bn3 = next((m for m in collect() if 'bn3@' in m['rcpt']), None)
check('Y3c without a logo the header text is the business name', bn3 is not None and re.search(r'font-size:20px;line-height:26px;font-weight:700;color:#[0-9a-f]{6};">WebG</span>', bn3['html']) is not None, '')
ft = hb.split('class="px footer"')[1] if 'class="px footer"' in hb else ''
zw = chr(0x200B)
check('Y4 footer text cut with zero-width spaces so Gmail and Apple Mail do not turn the address, phone or VAT number into links (reads the same)', zw in ft and 'Corso ' + zw + 'Taranto' in ft and '127' + zw + '746' in ft, ft[:200])
check('Y4b ...URLs stay whole, and the plain-text part keeps the text as written (no invisible characters)', 'href="https://www.webg.it/privacy.html"' in ft and zw not in bn['text'] and 'Corso Taranto 62, 10154 Torino (TO)' in bn['text'], '')
check('Y5 footer links keep a readable colour whatever the mail app does: rules for .footer a, Gmail (u + #body), Samsung, Apple data detectors, body id', all(x in hb for x in ('.footer a, .footer a:link, .footer a:visited { color:#ffffff !important', 'u + #body .footer a', '#MessageViewBody .footer a', 'a[x-apple-data-detectors]')) and '<body id="body"' in hb, '')
tp.fresh()

# ============================================================================ 8b. header style, logo position, footer outside the card (the look of the Brevo personal emails)
print('--- 8b. header and footer layout')
tp.fresh()
jx('rm -f /tmp/mail/*.eml')
opts(mail_logo='images/joomla_black.png', mail_color='#ee6c2d')
book('def', day(D + 8), '09:00', email='hd0@example.invalid', name='Testata Predefinita')
d0 = next((m for m in collect() if 'hd0@' in m['rcpt']), None)
h0 = d0['html'] if d0 else ''
check('V1 default (logo left, coloured band, footer in the card): band on top, no thin line, logo not centred, footer row inside the card', 'class="accent" height="5"' in h0 and 'border-top:3px solid' not in h0 and 'display:block;margin:0 auto;border:0' not in h0 and '<td class="px footer" style=' in h0 and 'fout' not in h0.split('</style>')[1], '')
opts(mail_logo_pos='center', mail_header_style='rule')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 8), '10:00', email='hd1@example.invalid', name='Testata Centro Filetto')
d1 = next((m for m in collect() if 'hd1@' in m['rcpt']), None)
h1 = d1['html'] if d1 else ''
check('V2 centred logo with a thin line: logo centred, 3 px line in the main colour under it across the content width, NO band on top', d1 is not None and 'display:block;margin:0 auto;border:0' in h1 and 'class="px hdr-plate" align="center"' in h1 and 'border-top:3px solid #ee6c2d' in h1 and 'class="accent" height="5"' not in h1 and not structure_ok(d1), d1 and structure_ok(d1))
check('V2b the line sits between the logo and the content, and the title keeps its box and button', h1.index('hdr-plate') < h1.index('border-top:3px solid #ee6c2d') < h1.index('<h1') and 'class="box"' in h1 and 'class="btn-a"' in h1, '')
opts(mail_logo='')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 8), '11:00', email='hd2@example.invalid', name='Testata Senza Logo')
d2 = next((m for m in collect() if 'hd2@' in m['rcpt']), None)
check('V2c without a logo the business name in the header follows the position too', d2 is not None and 'class="px hdr" align="center"' in d2['html'] and 'text-align:center;' in d2['html'], '')
opts(mail_logo='images/joomla_black.png', mail_footer_out='1', mail_footer='Corso Taranto 62, 10154 Torino (TO)' + chr(92) + 'nP. IVA 12774610013')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 8), '12:00', email='hd3@example.invalid', name='Piede Fuori')
d3 = next((m for m in collect() if 'hd3@' in m['rcpt']), None)
h3 = d3['html'] if d3 else ''
body3 = h3.split('</style>')[1] if '</style>' in h3 else h3
card_end = body3.index('</table>', body3.index('class="btn-a"'))
check('V3 footer on the page background: it comes after the card, centred, in dark text on the light page, not inside the card', d3 is not None and body3.index('class="px footer fout"') > card_end and 'align="center" style="padding:20px 32px 4px 32px;text-align:center;"' in body3 and 'color:#1b2a4a' in body3 and '<tr><td class="px footer" style=' not in body3 and not structure_ok(d3), d3 and structure_ok(d3))
check('V3b in dark mode the footer text is light and it has no band of its own', '.fout { background-color:transparent !important; }' in h3 and '.footer .f1, .footer a' in h3, '')
check('V3c the text alternative and the zero-width cutting are the same as in the card', d3 is not None and 'Corso Taranto 62, 10154 Torino (TO)' in d3['text'] and chr(0x200B) not in d3['text'] and chr(0x200B) in h3, '')
opts(mail_logo_pos='diagonal', mail_header_style='x', mail_footer_out='7')
jx('rm -f /tmp/mail/*.eml')
book('def', day(D + 8), '13:00', email='hd4@example.invalid', name='Testata Errata')
d4 = next((m for m in collect() if 'hd4@' in m['rcpt']), None)
h4b = d4['html'] if d4 else ''
check('V4 invalid values of the three options fall back to left, band and footer in the card', d4 is not None and 'class="accent" height="5"' in h4b and 'align="center" style="padding:26px' not in h4b and '<td class="px footer" style=' in h4b, '')
tp.fresh()

# ============================================================================ 8. no empty style value in any email of this suite
bad = [(m['subject'][:40], bad_styles(m['html'])) for m in ALL if bad_styles(m['html'])]
check('Z1 across all %d emails of this suite (all kinds, all colour options) no style attribute holds an empty value such as "color:;"' % len(ALL), len(ALL) > 40 and not bad, bad[:3])

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
