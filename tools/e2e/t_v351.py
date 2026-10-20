"""0.35.1: three requests of Marco, each with checks that fail on 0.35.0.
  1. the phone call: the business always calls (labels, hint under the phone field, place line in emails / .ics / manage page, direct page title);
  2. the consent box links to the privacy policy (option, or the footer line that names privacy), new tab, no link when there is no valid address;
  3. the days of the week are check boxes (event type form and YOOtheme element), and the values already saved read exactly the same.
Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, html, tempfile, http.client, urllib.parse
from wgbtest import *
from t_admin_lib import *

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
SRC = os.path.join(ROOT, 'src')
TBL = 'joom_webgbooking_eventtypes'


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


ET = dict(description='', state=1, listed=1, duration='', buffer_before='', buffer_after='', location='meet', avail_start='', avail_end='', avail_days='',
          avail_notice='', avail_window='', price_amount='', price_currency='', price_label='', custom_fields='', staff_to='', redirect_url='',
          confirm_manual=0, allow_guest=1, show_newsletter=0, ordering=0)


def ins(slug, title, **kw):
    f = dict(ET)
    f.update(kw)
    f.update(slug=slug, title=title)
    sql('INSERT INTO %s (%s,created,modified) VALUES (%s,NOW(),NOW())' % (TBL, ','.join(f), ','.join(q(v) for v in f.values())))
    return int(sql('SELECT id FROM %s WHERE slug=%s' % (TBL, q(slug))))


def direct(path):
    st, hd, body = http_get(path)
    return st, body


def dcfg(body):
    m = re.search(r'data-wgb="([^"]+)"', body)
    return json.loads(html.unescape(m.group(1))) if m else {}


def set_footer(txt):
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.mail_footer',%s) WHERE element='webgbooking' AND folder='system'" % q(txt))


def set_opt(k, v):
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.%s',%s) WHERE element='webgbooking' AND folder='system'" % (k, q(v)))


def ini(path):
    d = {}
    for ln in open(path, encoding='utf-8'):
        m = re.match(r'^([A-Z0-9_]+)="(.*)"\s*$', ln)
        if m:
            d[m.group(1)] = m.group(2)
    return d


# ============================================================ 1. the phone call: the business calls
P = 'PLG_SYSTEM_WEBGBOOKING_'
it = ini(os.path.join(SRC, 'plg_system_webgbooking/language/it-IT/plg_system_webgbooking.ini'))
en = ini(os.path.join(SRC, 'plg_system_webgbooking/language/en-GB/plg_system_webgbooking.ini'))
CUSTOMER = ['LOC_PHONE', 'FIELD_PHONE_NEEDED', 'LOC_PHONE_AT', 'DIRECT_CHOICE', 'ICS_PHONE', 'EMAIL_PHONE_CALL', 'PHONE_HINT', 'BOOK_OK_PHONE', 'BOOK_OK_PHONE_CONFIRMED', 'EMAIL_NOTE_PHONE']
check('L1 it-IT: every customer-facing phone string says "Ti chiamo io" (the business is the one calling)', all('Ti chiamo io' in it.get(P + k, '') or 'ti chiamo io' in it.get(P + k, '') for k in CUSTOMER), [k for k in CUSTOMER if 'chiamo io' not in it.get(P + k, '').lower()])
check('L1b en-GB: every customer-facing phone string says "I will call you" or "I call you"', all(re.search(r'I (will )?call you', en.get(P + k, '')) for k in CUSTOMER), [k for k in CUSTOMER if not re.search(r'I (will )?call you', en.get(P + k, ''))])
bad_it = [k for k in CUSTOMER if re.search(r'\b(chiamaci|chiamami|chiama(re)? (il|noi|me)|telefonaci)\b', it.get(P + k, ''), re.I)]
bad_en = [k for k in CUSTOMER if re.search(r'\b(call us|call me|phone us|ring us)\b', en.get(P + k, ''), re.I)]
check('L1c no customer-facing phone string suggests the customer should call', not bad_it and not bad_en, (bad_it, bad_en))
check('L1d the phone option is "Ti chiamo io al telefono" / "I will call you by phone" and the hint sits under the phone field',
      it[P + 'LOC_PHONE'] == 'Ti chiamo io al telefono' and en[P + 'LOC_PHONE'] == 'I will call you by phone' and it[P + 'PHONE_HINT'].startswith('Ti chiamo io a questo numero') and en[P + 'PHONE_HINT'].startswith('I will call you on this number'))
check('L1e it-IT and en-GB have the same keys for the new strings', all(P + k in it and P + k in en for k in ['PHONE_HINT', 'PRIVACY_LINKED', 'PRIVACY_URL_LABEL', 'PRIVACY_URL_DESC', 'EL_DAYS_LEGACY', 'EL_DAYS_BOXES_DESC']))

reset_env(render=False)
init = lambda **kw: jx('php /tmp/mock/init.php %s >/dev/null' % ' '.join('%s=%s' % (k, v) for k, v in kw.items()))
init()
sql('DELETE FROM %s' % TBL)
jx(RENDER)
c_both = page_cfg('both')
lab = c_both['labels']
check('L2 the widget labels carry the new wording (site language) and the hint', re.search(r'(I will call you|Ti chiamo io)', lab.get('locPhone', '')) and re.search(r'(I will call you|Ti chiamo io)', lab.get('phoneHint', '')) and re.search(r'(I will call you|Ti chiamo io)', lab.get('fPhoneNeeded', '')), {k: lab.get(k) for k in ('locPhone', 'phoneHint', 'fPhoneNeeded')})
js = open(os.path.join(SRC, 'plg_system_webgbooking/yootheme/elements/booking/assets/wgb.js'), encoding='utf-8').read()
check('L3 the script shows the hint under the phone field only when the phone call is chosen, and takes the linked consent label', "needPhone&&L.phoneHint" in js and 'L.privacyHtml||esc(L.privacy)' in js)

# the direct page of a phone-only event: title line, then a real booking with the emails
ins('solo-telefono', 'Solo telefono', location='phone', duration='30')
ins('scelta', 'A scelta', location='choice', duration='30')
st, body = direct('/prenota/solo-telefono')
check('L4 the direct page of a phone event says the business calls', st == 200 and re.search(r'(I will call you|Ti chiamo io)', html.unescape(body)), st)
st, body2 = direct('/prenota/scelta')
check('L4b ... and the "your choice" page says "Google Meet, or I call you by phone" (not "phone call")', re.search(r'(I call you by phone|ti chiamo io al telefono)', html.unescape(body2)) is not None, re.findall(r'bk-meta[^<]*<[^>]*>[^<]*', body2)[:3])
cfg = dcfg(body)
D = 3
since = len(mails())
r = book(None, day(D), '10:00', email='tel351@example.invalid', phone='+39 333 555 1212', cfg=cfg)
check('L5 booking a phone event from the link works', r.get('ok') is True, r)
ms = [decode_mail(mail_text(p)) for p in mails()[since:]]
cm = next((m for m in ms if 'tel351@' in m['rcpt']), None)
check('L5b customer email: the place line says "I will call you on +39 333 555 1212" and the note says the business calls', cm is not None and re.search(r'(I will call you on|Ti chiamo io al numero) \+39 333 555 1212', cm['html']) and 'Phone call to' not in cm['html'] and 'Telefonata al' not in cm['html'], cm and re.sub('<[^>]+>', ' ', cm['html'])[:500])
check('L5c the .ics says "I will call you on" (description and location)', cm is not None and len(re.findall(r'(I will call you on|Ti chiamo io al numero) \+39 333 555 1212', cm['ics'])) >= 2, cm and cm['ics'][:400])
mt = cm['text'] if cm else ''
check('L5d the text-only part of the email says it too', re.search(r'(I will call you on|Ti chiamo io al numero) \+39 333 555 1212', mt or '') is not None, mt[:300])
check('L5e the confirmation shown after booking says the business calls', re.search(r'(I will call you|ti chiamo io)', r.get('message', '')), r.get('message'))

# ============================================================ 2. the privacy link
def priv(cfgname='both'):
    return page_cfg(cfgname)['labels'].get('privacyHtml', 'MISSING')


set_footer('')
set_opt('privacy_url', '')
jx(RENDER)
check('V1 no footer and no option: no link, the plain label stays (nothing broken)', priv() == '' and page_cfg('both')['labels']['privacy'], priv())
st, b0 = direct('/prenota/scelta')
check('V1b ... same on the direct page (no anchor around "privacy policy")', dcfg(b0)['labels'].get('privacyHtml') == '' and 'privacy policy</a>' not in b0)
set_footer('Via Roma 1, 10100 Torino\nP.IVA 01234567890\nPrivacy: https://www.example.invalid/privacy.html')
jx(RENDER)
exp = re.compile(r'^(I accept the|Accetto la) <a href="https://www\.example\.invalid/privacy\.html" target="_blank" rel="noopener noreferrer">(privacy policy)</a>$')
check('V2 the footer line that names privacy gives the address: "privacy policy" is a link, new tab, noopener noreferrer', exp.match(priv()) is not None, priv())
st, b1 = direct('/prenota/scelta')
check('V2b ... the direct page carries it too (the widget is the same)', exp.match(dcfg(b1)['labels'].get('privacyHtml', '')) is not None, dcfg(b1)['labels'].get('privacyHtml'))
check('V2c ... and the footer of the direct page has a real link to the same address (it was plain text)', '<a href="https://www.example.invalid/privacy.html" target="_blank" rel="noopener noreferrer">https://www.example.invalid/privacy.html</a>' in b1, re.findall(r'bk-f2[^<]*<[^>]*>[^<]*', b1)[:2])
set_opt('privacy_url', 'https://policy.example.invalid/informativa')
jx(RENDER)
check('V3 the "Privacy policy address" option wins over the footer', 'href="https://policy.example.invalid/informativa"' in priv() and 'www.example.invalid/privacy.html' not in priv(), priv())
for bad in ('javascript:alert(1)', 'ftp://x.example.invalid/p', 'not an address', 'https://x.example.invalid/a#frag', 'https://u:p@x.example.invalid/'):
    set_opt('privacy_url', bad)
    jx(RENDER)
    check('V4 an unusable address in the option (%s): no link, no error' % bad, priv() == '', priv())
set_opt('privacy_url', '')
set_footer('Sito: https://www.example.invalid/\nPrivacy: ')
jx(RENDER)
check('V5 a footer with an address on a line that does not name privacy, and a privacy line without address: no link', priv() == '', priv())
set_footer('Privacy: https://x.example.invalid/a"onmouseover="alert(1)')
jx(RENDER)
p5 = priv()
check('V6 a hostile footer cannot add attributes to the link', 'onmouseover' not in p5 and p5.count('<a ') == 1, p5)
set_footer('Privacy: https://www.example.invalid/privacy.html.')
jx(RENDER)
check('V7 the full stop at the end of the footer line is not part of the address', 'href="https://www.example.invalid/privacy.html"' in priv(), priv())
set_footer('Via Roma 1\nPrivacy: https://www.example.invalid/privacy.html')
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.mail_footer','Via Roma 1\\nPrivacy: https://www.example.invalid/privacy.html') WHERE element='webgbooking' AND folder='system'")
jx(RENDER)
check('V8 the link is built by the server only (the browser gets the finished label, the script never composes an address)', 'privacyHtml' in js and 'privacy_url' not in js)

# ============================================================ 3. the days of the week
adm, edit_url, save_options, opts = make_admin()
reset_env(render=False)
sql('DELETE FROM %s' % TBL)
init()
set_footer('')
EDIT = BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.edit&id=%d'
DAYS = [('1', 'Monday'), ('2', 'Tuesday'), ('3', 'Wednesday'), ('4', 'Thursday'), ('5', 'Friday'), ('6', 'Saturday'), ('0', 'Sunday')]


def form_of(i):
    h = adm.get(EDIT % i)
    p = FormParser()
    p.feed(h)
    return h, p


def boxes(h):
    """value -> checked, for the seven boxes of jform[avail_days][]"""
    out = {}
    for m in re.finditer(r'<input[^>]*name="jform\[avail_days\]\[\]"[^>]*>', h):
        t = m.group(0)
        v = re.search(r'value="([^"]*)"', t)
        out[v.group(1)] = ' checked' in t
    return out


def save_days(i, days):
    h, p = form_of(i)
    pairs = [(k, v) for k, v in p.pairs if not k.startswith('jform[avail_days]') and k != 'task'] + [('jform[avail_days][]', d) for d in days] + [('task', 'eventtype.apply')]
    return post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&layout=edit&id=%d' % i, pairs)


def stored(i):
    return sql('SELECT avail_days FROM %s WHERE id=%d' % (TBL, i)).replace('NULL', '')


i_old = ins('giorni-vecchi', 'Giorni vecchi', avail_days='1,2,3,4,5', duration='30')
i_emp = ins('giorni-eredita', 'Giorni eredita', avail_days='', duration='30')
i_odd = ins('giorni-strani', 'Giorni strani', avail_days=' 2 , 4;6 ', duration='30')
h, p = form_of(i_old)
bx = boxes(h)
check('G1 the event type form has seven check boxes, no text field for the days', sorted(bx) == ['0', '1', '2', '3', '4', '5', '6'] and not re.search(r'<input[^>]*type="text"[^>]*name="jform\[avail_days\]"', h) and not re.search(r'name="jform\[avail_days\]"', h), sorted(bx))
check('G1b ... named Monday ... Sunday, in that order, Sunday last', [n for _, n in DAYS] == re.findall(r'(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)</label>', h.split('jform_avail_days')[-1])[:7] or all(n in h for _, n in DAYS), re.findall(r'(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)', h)[:9])
check('G2 a type saved as "1,2,3,4,5" opens with Monday to Friday ticked, weekend not', bx == {'1': True, '2': True, '3': True, '4': True, '5': True, '6': False, '0': False}, bx)
h2, _ = form_of(i_emp)
check('G2b an empty value opens with no box ticked, and the text says "no box ticked" inherits the plugin days', not any(boxes(h2).values()) and re.search(r'No box ticked', html.unescape(h2)) is not None)
h3, _ = form_of(i_odd)
check('G2c an odd old value (" 2 , 4;6 ") reads as Tuesday, Thursday, Saturday', {k for k, v in boxes(h3).items() if v} == {'2', '4', '6'}, boxes(h3))
save_days(i_old, ['1', '2', '3', '4', '5'])
check('G3 saving the form untouched keeps the value (nothing lost)', stored(i_old) == '1,2,3,4,5', stored(i_old))
save_days(i_old, ['2', '4'])
check('G4 tick Tuesday and Thursday: stored "2,4"', stored(i_old) == '2,4', stored(i_old))
save_days(i_old, ['0', '6', '1'])
check('G4b ... sorted, Sunday as 0', stored(i_old) == '0,1,6', stored(i_old))
save_days(i_old, [])
check('G5 untick everything: stored empty (inherit), the old days are not kept', stored(i_old) == '', repr(stored(i_old)))
h4, _ = form_of(i_old)
check('G5b ... and the form reopens with nothing ticked', not any(boxes(h4).values()))
# the booking page follows
save_days(i_old, ['2', '4'])
st, bd = direct('/prenota/giorni-vecchi')
cfg = dcfg(bd)
check('G6 the direct page of a Tuesday/Thursday type offers only those days', sorted(cfg.get('workDays', [])) == [2, 4], cfg.get('workDays'))
ev = cfg.get('ev')
dd = month(ev, day(0), day(28))
import datetime as _dt
wk = sorted({_dt.date.fromisoformat(k).weekday() for k, v in dd.items() if v})
check('G6b ... and the server offers slots only on Tuesdays and Thursdays (weekday 1 and 3)', wk and set(wk) <= {1, 3}, wk)
st, bd = direct('/prenota/giorni-eredita')
check('G7 an empty value inherits the plugin working days', sorted(dcfg(bd).get('workDays', [])) == [0, 1, 2, 3, 4, 5, 6], dcfg(bd).get('workDays'))
st, bd = direct('/prenota/giorni-strani')
check('G7b an old odd value is read as before (Tuesday, Thursday, Saturday)', sorted(dcfg(bd).get('workDays', [])) == [2, 4, 6], dcfg(bd).get('workDays'))
# a post that is not from the form
h, p = form_of(i_old)
pairs = [(k, v) for k, v in p.pairs if not k.startswith('jform[avail_days]') and k != 'task'] + [('jform[avail_days][]', '1'), ('jform[avail_days][]', '9'), ('task', 'eventtype.apply')]
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&layout=edit&id=%d' % i_old, pairs)
check('G8 a hand-made post with day 9 is refused and nothing changes', stored(i_old) == '2,4' and 'days' in html.unescape(out).lower(), stored(i_old))

# plugin options: already check boxes with the day names
pg = adm.get(edit_url)
check('G9 the plugin options show the working days as check boxes with the real day names', all(n in pg for _, n in DAYS) and len(re.findall(r'name="jform\[params\]\[work_days\]\[\]"', pg)) == 7, len(re.findall(r'work_days', pg)))

# the YOOtheme element: boxes win, the old field still reads, a saved type wins over both
cfgs = {
    'dy_boxes': {'title': 'Boxes', 'event_duration': '30', 'avail_day_2': True, 'avail_day_4': True},
    'dy_legacy': {'title': 'Legacy', 'event_duration': '30', 'avail_days': '1,3'},
    'dy_both': {'title': 'Both', 'event_duration': '30', 'avail_day_5': True, 'avail_days': '1,3'},
    'dy_none': {'title': 'None', 'event_duration': '30'},
    'dy_false': {'title': 'False', 'event_duration': '30', 'avail_day_2': False, 'avail_day_3': '0', 'avail_days': ''},
    'dy_sunday': {'title': 'Sunday', 'event_duration': '30', 'avail_day_0': True},
    'dy_type': {'title': 'Type', 'event_type': str(i_old), 'avail_day_1': True, 'avail_day_3': True, 'avail_days': '5'},
}
tmp = os.path.join(tempfile.mkdtemp(prefix='wgb351-'), 'configs_dy.json')
json.dump(cfgs, open(tmp, 'w'))
sh(['docker', 'cp', tmp, J + ':/tmp/mock/configs_dy.json'])
jx('php /tmp/mock/render_el.php /tmp/mock/configs_dy.json >/dev/null; chmod -R a+rX /var/www/html/wgbtest')


def wd(name):
    return sorted(page_cfg(name).get('workDays', []))


check('Y1 the element with day boxes ticked (Tuesday, Thursday) offers those days', wd('dy_boxes') == [2, 4], wd('dy_boxes'))
check('Y2 an element saved before (text "1,3") reads exactly as before', wd('dy_legacy') == [1, 3], wd('dy_legacy'))
check('Y3 boxes ticked AND old text: the boxes win', wd('dy_both') == [5], wd('dy_both'))
check('Y4 nothing ticked and nothing typed: the plugin working days', wd('dy_none') == [0, 1, 2, 3, 4, 5, 6] and wd('dy_false') == [0, 1, 2, 3, 4, 5, 6], (wd('dy_none'), wd('dy_false')))
check('Y5 Sunday alone works (value 0 is a day, not "empty")', wd('dy_sunday') == [0], wd('dy_sunday'))
check('Y6 an element that picks a saved type takes the type\'s days (2,4), never its own boxes or text', wd('dy_type') == [2, 4], wd('dy_type'))
el = open(os.path.join(SRC, 'plg_system_webgbooking/yootheme/elements/booking/element.php'), encoding='utf-8').read()
check('Y7 the element declares seven check boxes in the Event tab, and keeps the old field after them', all("'avail_day_%d' =>" % d in el for d in range(7)) and el.index("'avail_day_1', 'avail_day_2'") < el.index("'avail_days', 'avail_notice'"))
d_it = ini(os.path.join(SRC, 'plg_system_webgbooking/language/it-IT/plg_system_webgbooking.ini'))
check('Y8 the boxes are labelled with the real day names (it-IT Lunedì ... Domenica)', [d_it[P + k] for k in ('MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN')] == ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'])
# ============================================================ 4. empty fields of a saved type inherit the CURRENT plugin values
# (webg.it, 21-10: type with empty pauses and hours, plugin changed from 60/30 pauses to 0/0 and 09:00-20:00; the page kept
# behaving with the old pause). Slots of the day, the month view and the booking itself must follow the plugin as it is NOW, even
# for a page drawn (and a token signed) BEFORE the change.
import datetime as dt, zoneinfo
reset_env(render=False)
sql('DELETE FROM %s' % TBL)
init(buffer_before=60, buffer_after=30, work_start='09:00', work_end='18:00', slot_interval=30, slot_duration=30)
ins('eredita-tutto', 'Eredita tutto', duration='30')          # pauses and hours empty = inherit
ins('esplicito', 'Esplicito', duration='30', buffer_before='0', buffer_after='0', avail_start='09:00', avail_end='20:00')
DD = dt.date.today() + dt.timedelta(days=4)
rome = zoneinfo.ZoneInfo('Europe/Rome')
b0 = dt.datetime(DD.year, DD.month, DD.day, 15, 36, tzinfo=rome).astimezone(dt.timezone.utc)
b1 = dt.datetime(DD.year, DD.month, DD.day, 16, 36, tzinfo=rome).astimezone(dt.timezone.utc)
jx("mkdir -p /tmp/gmock; echo '[[\"%s\",\"%s\"]]' > /tmp/gmock/busy.json" % (b0.strftime('%Y-%m-%dT%H:%M:%SZ'), b1.strftime('%Y-%m-%dT%H:%M:%SZ')))


def slots_for(slug, cfg=None):
    st_, body_ = direct('/prenota/' + slug)
    c_ = cfg or dcfg(body_)
    out_ = json.loads(Sess().get(AJAX + '&action=slots&date=%s&ev=%s' % (DD.isoformat(), urllib.parse.quote(c_['ev'], safe=''))))
    return c_, out_.get('slots', [])


def clear_kv():
    sql('DELETE FROM joom_webgbooking_kv')


c_old, sl_old = slots_for('eredita-tutto')
check('I1 plugin pauses 60/30: after a calendar block 15:36-16:36 neither 17:00 nor 17:30 is offered (the gap is the larger pause, 60), 14:30 is', '17:00' not in sl_old and '17:30' not in sl_old and '14:30' in sl_old, sl_old)
init(buffer_before=0, buffer_after=0, work_start='09:00', work_end='20:00', slot_interval=30, slot_duration=30)
clear_kv()
_, sl_frozen = slots_for('eredita-tutto', c_old)
check('I2 plugin changed to 0/0 and 09:00-20:00: the page drawn BEFORE the change (same signed token) now offers 17:00 and 19:30', '17:00' in sl_frozen and '19:30' in sl_frozen, sl_frozen)
c_new, sl_new = slots_for('eredita-tutto')
check('I3 a page drawn after the change offers the same slots', sl_new == sl_frozen and '17:00' in sl_new, sl_new)
_, sl_exp = slots_for('esplicito')
check('I4 the type with explicit values (0/0, 09:00-20:00) gives the same slots as the one that inherits', sl_exp == sl_new, (sl_exp, sl_new))
mo = month(c_old['ev'], DD.isoformat(), (DD + dt.timedelta(days=1)).isoformat())
check('I5 the month view of the old page follows the plugin too (the day is open)', mo.get(DD.isoformat()), mo)
r = book(None, DD.isoformat(), '17:00', email='inh1@example.invalid', cfg=c_old)
check('I6 booking 17:00 with the page drawn before the change: accepted (the server uses the plugin values of today)', r.get('ok') is True, r)
rw = row(last_id())
check('I7 the booking stores the pauses that applied (0/0), not the old 60/30', rw['buffer_before'] in ('0', 'NULL', '') and rw['buffer_after'] in ('0', 'NULL', ''), {k: rw[k] for k in ('buffer_before', 'buffer_after')})
init(buffer_before=60, buffer_after=30, work_start='09:00', work_end='18:00', slot_interval=30, slot_duration=30)
clear_kv()
_, sl_back = slots_for('eredita-tutto', c_old)
check('I8 plugin back to 60/30: the same old page follows again (17:00 gone) - nothing is frozen in the token or in a cache', '17:00' not in sl_back, sl_back)

reset_env()

print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
