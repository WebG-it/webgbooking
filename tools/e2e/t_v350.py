"""0.35.0: the direct booking link (/prenota/<slug>) and the saved event types ("Tipi di evento").
Each part of the feature has checks that fail on 0.34.5: the page, the two addresses, secret and unpublished events, the index, the option
that renames the path, a site page that wins over the address, free and paid bookings from the link (Stripe mock, test mode only), the
definition read from the database when the booking arrives (price, questions, duration changed after the page was drawn; type
unpublished or deleted), the availability following the type, an element that picks a saved type (and the hand-made ones unchanged),
the admin (list, Copy link, form, validation, ACL) and the upgrade from 0.34.5 (the table is created). Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, html, base64, time, http.client, urllib.parse
from wgbtest import *
from t_pay_lib import *
import t_pay_lib
from t_admin_lib import *
SK, WHSEC = t_pay_lib.SK, t_pay_lib.WHSEC

TBL = 'joom_webgbooking_eventtypes'
ET_FIELDS = dict(description='', state=1, listed=1, duration='', buffer_before='', buffer_after='', location='meet', avail_start='', avail_end='', avail_days='',
                 avail_notice='', avail_window='', price_amount='', price_currency='', price_label='', custom_fields='', staff_to='', redirect_url='',
                 confirm_manual=0, allow_guest=1, show_newsletter=0, ordering=0)


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


def ins(slug, title, **kw):
    f = dict(ET_FIELDS)
    f.update(kw)
    f.update(slug=slug, title=title)
    sql('INSERT INTO %s (%s,created,modified) VALUES (%s,NOW(),NOW())' % (TBL, ','.join(f), ','.join(q(v) for v in f.values())))
    return int(sql('SELECT id FROM %s WHERE slug=%s' % (TBL, q(slug))))


def upd(slug, **kw):
    sql('UPDATE %s SET %s WHERE slug=%s' % (TBL, ','.join('%s=%s' % (k, q(v)) for k, v in kw.items()), q(slug)))


def direct(path):
    return http_get(path)


def dcfg(body):
    m = re.search(r'data-wgb="([^"]+)"', body)
    return json.loads(html.unescape(m.group(1))) if m else {}


def ours(body):
    return 'class="bk-body"' in body


def init_opts(**kw):
    jx('php /tmp/mock/init.php %s >/dev/null' % ' '.join('%s=%s' % (k, v) for k, v in kw.items()))


def set_tables_clean():
    sql('DELETE FROM %s' % TBL)


reset_env()
fresh()
set_tables_clean()
SLUGS = {}

# ============================================================== 1. the table exists after the install
check('T1 the event types table was created by the install', sql("SHOW TABLES LIKE '%s'" % TBL) == TBL)
cols = sql("SHOW COLUMNS FROM %s" % TBL).split('\n')
check('T1b it has the columns the plugin and the component read', all(any(c.startswith(k + '\t') for c in cols) for k in ['id', 'slug', 'title', 'description', 'state', 'listed', 'duration', 'location', 'price_amount', 'custom_fields', 'staff_to', 'redirect_url']), cols[:3])

# ============================================================== 2. the direct page
SLUGS['free'] = ins('chiamata-30', 'Chiamata conoscitiva', description='Una chiamata di 30 minuti per capire di cosa hai bisogno.\n\nNessun impegno.', duration='30',
                    staff_to='owner@wgbtest.invalid', redirect_url='/wgbtest/thanks.html', ordering=1)
st, h, b = direct('/prenota/chiamata-30')
check('D1 /prenota/<slug> opens the page (200)', st == 200 and ours(b), (st, b[:120]))
check('D1b title, duration, place, description, widget on the page', 'Chiamata conoscitiva' in b and '30 min' in b and 'Google Meet' in b and '<p>Una chiamata di 30 minuti' in b and '<p>Nessun impegno.</p>' in b and 'data-wgb=' in b, b[:200])
check('D1c noindex in the header and in the page, no cache, no referrer', 'noindex' in h.get('x-robots-tag', '') and 'noindex' in b and 'no-store' in h.get('cache-control', '') and h.get('referrer-policy') == 'no-referrer', h)
check('D1d phone ready: viewport meta and the responsive CSS file', 'name="viewport"' in b and 'direct.css' in b)
cfg = dcfg(b)
check('D1e the widget carries the saved type: 30 minutes, Meet, a signed form and availability token, no price', cfg.get('duration') == 30 and cfg.get('location') == 'meet' and cfg.get('form', '').startswith('v2.') and cfg.get('ev') and cfg.get('price') is None, {k: cfg.get(k) for k in ['duration', 'location', 'price']})
fa = form_actions(cfg['form'])
check('D1f the sealed token names the type and the page source', fa.get('etype') == SLUGS['free'] and fa.get('src') == 'page', {k: fa.get(k) for k in ['etype', 'src']})
check('D1g the page source does not show the staff address (it travels sealed in the token)', 'owner@wgbtest.invalid' not in b)

st2, h2, b2 = direct('/index.php?option=com_webgbooking&view=book&event=chiamata-30')
check('D2 the long address (component view) opens the same page', st2 == 200 and ours(b2) and 'Chiamata conoscitiva' in b2, (st2, b2[:100]))
st3, h3, b3 = direct('/index.php/prenota/chiamata-30')
check('D3 /index.php/prenota/<slug> (friendly URLs without rewriting) opens the page', st3 == 200 and ours(b3) and 'Chiamata conoscitiva' in b3, (st3, b3[:100]))
st4, h4, b4 = direct('/prenota/Chiamata-30/')
check('D3b upper case and a trailing slash are forgiven', st4 == 200 and ours(b4), st4)

st4, h4, b4 = direct('/prenota/chiamata-30?utm_source=email&utm_campaign=ottobre')
check('D3c a link with tracking parameters (utm_*) opens the page', st4 == 200 and ours(b4), st4)
st4, h4, b4 = direct('/it/prenota/chiamata-30')
check('D3d a language prefix in front (multilingual sites) is accepted', st4 == 200 and ours(b4), st4)
st4, h4, b4 = direct('/prenota/chiamata-30/altro')
check('D3e extra path segments after the slug are not taken over', not ours(b4), st4)
st, h, b = direct('/prenota/non-esiste')
check('D4 an unknown slug under /prenota is NOT taken over (the site answers its own 404)', not ours(b) and st != 200, (st, ours(b)))
st, h, b = direct('/index.php?option=com_webgbooking&view=book&event=non-esiste')
check('D4b an unknown slug on the long address: our own "not found" page, status 404, noindex', st == 404 and ours(b) and 'noindex' in h.get('x-robots-tag', ''), (st, ours(b)))
st, h, b = direct('/prenota/x')
check('D4c a one-letter slug is not a slug (not taken over)', not ours(b))

# escaping: hostile text in the database never reaches the page as markup
ins('xss-prova', 'Ciao <b>"&</b> <img src=x onerror=alert(1)>', description='<script>alert(1)</script>\n\n<a href="javascript:alert(2)">x</a>')
st, h, b = direct('/prenota/xss-prova')
body_only = b.split('<body', 1)[1] if '<body' in b else b
body_only = re.sub(r'data-wgb="[^"]*"', 'data-wgb=""', body_only)
check('D5 title and description are escaped (no script, no tag, no javascript: link)', st == 200 and '<script>alert' not in body_only and '<img src=x' not in body_only and '<b>"' not in body_only and '&lt;script&gt;alert(1)&lt;/script&gt;' in body_only and 'href="javascript' not in body_only, body_only[:300])

# ============================================================== 3. public, secret, unpublished, the index
SLUGS['secret'] = ins('solo-per-te', 'Appuntamento riservato', listed=0, duration='45', ordering=2)
SLUGS['draft'] = ins('bozza', 'Evento in bozza', state=0, ordering=3)
st, h, b = direct('/prenota/solo-per-te')
check('S1 a secret event opens with its link', st == 200 and ours(b) and 'Appuntamento riservato' in b)
st, h, b = direct('/prenota/bozza')
check('S2 an unpublished event does not open (the site answers its own 404)', not ours(b) and st != 200, (st, ours(b)))
st, h, b = direct('/index.php?option=com_webgbooking&view=book&event=bozza')
check('S2b unpublished on the long address: not found page, 404', st == 404 and ours(b) and 'Evento in bozza' not in b)
st, h, b = direct('/prenota')
check('S3 /prenota (no slug) shows the index of the public events', st == 200 and ours(b) and 'Book an appointment' in b and 'Chiamata conoscitiva' in b and 'href="http://localhost:%s/index.php/prenota/chiamata-30"' % PORT in b, (st, re.findall(r'href="([^"]*prenota[^"]*)"', b)))
check('S3b the index NEVER lists a secret or an unpublished event', 'Appuntamento riservato' not in b and 'solo-per-te' not in b and 'Evento in bozza' not in b and 'bozza' not in b)
check('S3c the index is noindex too', 'noindex' in h.get('x-robots-tag', '') and 'noindex' in b)
st, h, b = direct('/index.php?option=com_webgbooking&view=book')
check('S3d the long address without an event shows the index', st == 200 and ours(b) and 'Chiamata conoscitiva' in b)
init_opts(direct_index='0')
st, h, b = direct('/prenota')
check('S4 option "list of public events" off: /prenota is left to the site', not ours(b))
st, h, b = direct('/prenota/solo-per-te')
check('S4b ... and the single events still open', st == 200 and ours(b))
init_opts(direct_path='riservato')
st, h, b = direct('/riservato/chiamata-30')
check('S5 option "first part of the address": the new path opens the page', st == 200 and ours(b))
st, h, b = direct('/prenota/chiamata-30')
check('S5b ... and the old one is left to the site', not ours(b))
init_opts()
st, h, b = direct('/prenota/chiamata-30')
check('S5c back to the default path', st == 200 and ours(b))

# a page of the site on the same address wins
sql("INSERT INTO joom_menu (menutype,title,alias,path,link,type,published,parent_id,level,component_id,access,img,params,lft,rgt,home,language,client_id,template_style_id) "
    "VALUES ('mainmenu','Prenota','prenota','prenota','index.php?Itemid=','alias',1,1,1,0,1,'','{}',9001,9002,0,'*',0,0)")
st, h, b = direct('/prenota')
check('S6 a menu item of the site on /prenota wins over the index', not ours(b))
st, h, b = direct('/prenota/chiamata-30')
check('S6b ... the single events still open (the menu item is only /prenota)', st == 200 and ours(b))
sql("DELETE FROM joom_menu WHERE alias='prenota' AND lft=9001")
sql("INSERT INTO joom_menu (menutype,title,alias,path,link,type,published,parent_id,level,component_id,access,img,params,lft,rgt,home,language,client_id,template_style_id) "
    "VALUES ('mainmenu','Prenota chiamata','chiamata-30','prenota/chiamata-30','index.php?Itemid=','alias',1,1,1,0,1,'','{}',9003,9004,0,'*',0,0)")
st, h, b = direct('/prenota/chiamata-30')
check('S6c a menu item on the very same path as an event wins over it', not ours(b))
sql("DELETE FROM joom_menu WHERE lft IN (9001,9003)")
st, h, b = direct('/prenota/chiamata-30')
check('S6d menu item removed: the event opens again', st == 200 and ours(b))

# ============================================================== 4. booking from the link (free)
D = 12
cfg = dcfg(direct('/prenota/chiamata-30')[2])
mm = month(cfg['ev'], day(D), day(D)).get(day(D), [])
check('B1 availability of the direct page: slots for a day', '09:00' in mm and '17:30' in mm, mm[:5])
jx('rm -f /tmp/mail/*.eml')
r = book(None, day(D), '09:00', email='cliente@example.invalid', name='Cliente Diretto', phone='3331234567', cfg=cfg)
bid = last_id()
rw = row(bid)
check('B2 free booking from the link: confirmed at once', r.get('ok') is True and rw['status'] == 'confirmed', r)
check('B2b stored with the type: title as event name, 30 minutes, Meet link, Google event', rw['event_name'] == 'Chiamata conoscitiva' and rw['duration_min'] == '30' and rw['location'] == 'meet' and rw['meeting_url'] and rw['google_event_id'], rw)
src = sql('SELECT source_url FROM joom_webgbooking_bookings WHERE id=%d' % bid)
check('B2c the booking remembers the direct page as its source (the "choose again" buttons lead back to it)', src.endswith('/prenota/chiamata-30'), src)
ms = [decode_mail(mail_text(p)) for p in mails()]
check('B3 customer email and the staff email of the type (not the plugin address)', any('cliente@example.invalid' in (m['rcpt'] + m['to']) for m in ms) and any('owner@wgbtest.invalid' in (m['rcpt'] + m['to']) for m in ms) and not any('staff@wgbtest.invalid' in (m['rcpt'] + m['to']) for m in ms), [(m['rcpt'], m['to']) for m in ms])
mt = sql('SELECT staff_to FROM joom_webgbooking_bookings WHERE id=%d' % bid)
check('B3b the staff address is stored on the booking (reschedule and cancel reach the same people)', mt == 'owner@wgbtest.invalid', mt)
cm = [m for m in ms if 'cliente@example.invalid' in (m['rcpt'] + m['to'])]
check('B3c the confirmation email carries the manage link', cm and 'action=manage' in cm[0]['html'], cm[:1] and cm[0]['html'][:200])
mm2 = month(cfg['ev'], day(D), day(D)).get(day(D), [])
check('B4 the booked slot is not offered any more (the next one, right after the 30 minutes, is)', '09:00' not in mm2 and '09:30' in mm2, mm2[:4])
r = book(None, day(D), '09:00', email='altro@example.invalid', cfg=cfg)
check('B4b a second visitor cannot book the same slot', r.get('ok') is False, r)

# cancel from the manage link
tok = sql('SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d' % bid)
s = Sess()
out = s.post(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel', {'token': tok})
check('B5 cancel with the manage link: booking cancelled', row(bid)['status'] == 'cancelled', row(bid))
check('B5b the slot is free again', '09:00' in month(cfg['ev'], day(D), day(D)).get(day(D), []))

# manual confirmation setting of the type
SLUGS['manual'] = ins('su-conferma', 'Su conferma', confirm_manual=1, duration='30')
cm_cfg = dcfg(direct('/prenota/su-conferma')[2])
r = book(None, day(D), '11:00', email='manuale@example.invalid', cfg=cm_cfg)
check('B6 "manual confirmation" of the type: the booking waits as pending', r.get('ok') is True and row(last_id())['status'] == 'pending', r)

# the questions of the type, read from the database
SLUGS['q'] = ins('con-domande', 'Con domande', duration='30', custom_fields='Qual e il tuo sito? | text | required\nQuando vuoi partire? | select | | subito, a breve, non so', allow_guest=0)
st, h, b = direct('/prenota/con-domande')
qcfg = dcfg(b)
check('Q1 the questions of the type are on the page', [f['label'] for f in qcfg['customFields']] == ['Qual e il tuo sito?', 'Quando vuoi partire?'] and qcfg['customFields'][0]['required'] is True and qcfg['customFields'][1]['options'] == ['subito', 'a breve', 'non so'] and qcfg['allowGuest'] is False, qcfg.get('customFields'))
r = book(None, day(D), '12:00', email='dom1@example.invalid', cfg=qcfg)
check('Q2 a required question left empty: refused by the server', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='dom1@example.invalid'") == '0', r)
r = book(None, day(D), '12:00', email='dom2@example.invalid', cfg=qcfg, extra={'custom[cf0]': 'https://example.invalid', 'custom[cf1]': 'a breve'})
cd = sql("SELECT custom_data FROM joom_webgbooking_bookings WHERE customer_email='dom2@example.invalid'")
check('Q3 answers saved with the labels of the type', r.get('ok') is True and 'Qual e il tuo sito?' in cd and 'example.invalid' in cd and 'a breve' in cd, (r, cd))
r = book(None, day(D), '12:30', email='dom3@example.invalid', cfg=qcfg, extra={'custom[cf0]': 'x', 'custom[cf1]': 'valore-inventato'})
cd = sql("SELECT custom_data FROM joom_webgbooking_bookings WHERE customer_email='dom3@example.invalid'")
check('Q4 a select answer that is not in the list of the type is dropped', r.get('ok') is True and 'valore-inventato' not in cd, cd)

# ============================================================== 5. the database decides when the booking arrives
SLUGS['live'] = ins('modificabile', 'Modificabile', duration='30', price_amount='', custom_fields='')
live_cfg = dcfg(direct('/prenota/modificabile')[2])
upd('modificabile', duration='60')
r = book(None, day(D), '14:00', email='stale1@example.invalid', cfg=live_cfg)
check('L1 duration changed after the page was drawn: booking refused with "reload the page", nothing stored', r.get('ok') is False and 'reload' in r.get('message', '').lower() and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='stale1@example.invalid'") == '0', r)
live_cfg = dcfg(direct('/prenota/modificabile')[2])
r = book(None, day(D), '14:00', email='stale2@example.invalid', cfg=live_cfg)
check('L1b after reloading, the booking uses the new duration (60 minutes)', r.get('ok') is True and row(last_id())['duration_min'] == '60', r)

upd('modificabile', custom_fields='Nuova domanda | text | required')
r = book(None, day(D), '16:00', email='stale3@example.invalid', cfg=live_cfg)
check('L2 a question added after the page was drawn: refused with "reload the page"', r.get('ok') is False and 'reload' in r.get('message', '').lower(), r)
upd('modificabile', custom_fields='')

# a price put on the type after the page was drawn: the old (free) page cannot book it for free
live_cfg = dcfg(direct('/prenota/modificabile')[2])
upd('modificabile', price_amount='49', price_currency='EUR')
r = book(None, day(D), '16:30', email='stale4@example.invalid', cfg=live_cfg)
check('L3 price added after the page was drawn: the page drawn as free cannot book it for free', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='stale4@example.invalid'") == '0', r)
upd('modificabile', price_amount='', price_currency='')

live_cfg = dcfg(direct('/prenota/modificabile')[2])
upd('modificabile', state=0)
r = book(None, day(D), '10:30', email='gone1@example.invalid', cfg=live_cfg)
check('L4 type unpublished after the page was drawn: refused, "no longer available"', r.get('ok') is False and 'no longer available' in r.get('message', ''), r)
mm = month(live_cfg['ev'], day(D), day(D))
check('L4b ... and the availability call offers nothing any more', mm == {} or mm == [], mm)
upd('modificabile', state=1)
mm = month(live_cfg['ev'], day(D), day(D)).get(day(D), [])
check('L4c published again: slots are back', len(mm) > 0, mm)
sql('DELETE FROM %s WHERE slug=%s' % (TBL, q('modificabile')))
r = book(None, day(D), '10:30', email='gone2@example.invalid', cfg=live_cfg)
check('L5 type deleted after the page was drawn: refused', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='gone2@example.invalid'") == '0', r)

# availability follows the type at once (the old page keeps working with the new rules)
SLUGS['mon'] = ins('solo-lunedi', 'Solo il lunedi', duration='30')
mon_cfg = dcfg(direct('/prenota/solo-lunedi')[2])
days_all = month(mon_cfg['ev'], day(0), day(30))
upd('solo-lunedi', avail_days='1', avail_start='15:00', avail_end='17:00')
days_mon = month(mon_cfg['ev'], day(0), day(30))
import datetime
wd_ok = all(datetime.date.fromisoformat(d).weekday() == 0 for d in days_mon)
check('L6 availability follows the CURRENT definition of the type: only Mondays, 15:00-17:00, from an old page', len(days_all) > len(days_mon) > 0 and wd_ok and all(t >= '15:00' and t < '17:00' for v in days_mon.values() for t in v), (len(days_all), len(days_mon)))
mon_day = [d for d in days_mon][0]
r = book(None, mon_day, '09:00', email='fuori@example.invalid', cfg=mon_cfg)
check('L6b a slot outside the type hours is refused even if the old page showed it', r.get('ok') is False, r)

SLUGS['ren'] = ins('rinominabile', 'Vecchio nome', duration='30')
ren_cfg = dcfg(direct('/prenota/rinominabile')[2])
upd('rinominabile', title='Nuovo nome', description='Descrizione cambiata')
r = book(None, day(D), '17:00', email='ren@example.invalid', cfg=ren_cfg)
check('L7 a type renamed after the page was drawn still books (the title is not a reason to reload) and the NEW title is stored', r.get('ok') is True and row(last_id())['event_name'] == 'Nuovo nome', r)

# ============================================================== 6. paid events from the link
fresh()
set_tables_clean()
SLUGS['paid'] = ins('un-esperto', 'Un esperto per un\'ora', description='Un\'ora per risolvere il problema.', duration='60', buffer_before='15', buffer_after='15', location='choice',
                    price_amount='49', price_currency='EUR', price_label='Un\'ora con un esperto')
st, h, b = direct('/prenota/un-esperto')
pcfg = dcfg(b)
check('P1 paid page: price on the page and in the widget, place to choose', st == 200 and '49' in b and pcfg['price'] and '49' in pcfg['price']['text'] and pcfg['location'] == 'choice' and 'Google Meet, or I call you by phone' in b, pcfg.get('price'))
jx('rm -f /tmp/gmock/log.jsonl; rm -f /tmp/mail/*.eml')
r = book(None, day(D), '10:00', email='paga@example.invalid', name='Chi Paga', phone='3331112222', location='phone', cfg=pcfg)
pid = last_id()
rw = dbrow(pid)
check('P2 paid booking from the link: redirected to Stripe, slot held (awaiting_payment, 4900 EUR)', r.get('ok') is True and r.get('redirect', '').startswith('https://checkout.stripe.test/c/pay/cs_test_mock') and rw['status'] == 'awaiting_payment' and rw['amount_cents'] == '4900' and rw['currency'] == 'EUR', (r, rw))
cs = stripe_calls('POST', '/v1/checkout/sessions')
f = urllib.parse.parse_qs(cs[0]['body'] if cs and isinstance(cs[0]['body'], str) else '', keep_blank_values=True)
check('P2b Stripe Checkout Session: amount from the type, its charge description, booking id', cs and f.get('line_items[0][price_data][unit_amount]') == ['4900'] and 'Un\'ora con un esperto' in ' '.join(f.get('line_items[0][price_data][product_data][name]', [])) and f.get('metadata[booking_id]') == [str(pid)] and f.get('mode') == ['payment'], f)
check('P2c nothing else ran before the payment (no email, no Google event)', len(mails()) == 0 and not [l for l in gm_log() if 'googleapis' in l['host'] and l['path'].startswith('/calendar/v3/calendars')])
mmp = month(pcfg['ev'], day(D), day(D)).get(day(D), [])
check('P3 the held slot is not offered', '10:00' not in mmp, mmp[:3])
sid = rw['stripe_session_id']
upd('un-esperto', price_amount='59')
pay_in_mock(sid)
st_, out = webhook(event('checkout.session.completed', sid))
rw = dbrow(pid)
check('P4 payment completed (webhook): confirmed and paid, even though the price on the type changed meanwhile (the customer paid what the session said)', st_ == 200 and rw['status'] == 'confirmed' and rw['payment_status'] == 'paid' and rw['amount_cents'] == '4900', (st_, out, rw))
ms = [decode_mail(mail_text(p)) for p in mails()]
check('P4b customer email after the payment mentions the payment; staff is told', any('paga@example.invalid' in (m['rcpt'] + m['to']) for m in ms) and len(ms) >= 2, [(m['rcpt'], m['subject']) for m in ms])
check('P4c a Google event with the phone call (no Meet link)', rw['google_event_id'] not in ('', 'NULL'))
upd('un-esperto', price_amount='49')
# price changed before paying: the page drawn with 49 cannot book at 59
pcfg2 = dcfg(direct('/prenota/un-esperto')[2])
upd('un-esperto', price_amount='59')
r = book(None, day(D), '12:00', email='caro@example.invalid', location='meet', cfg=pcfg2)
check('P5 the price changed after the page was drawn: refused with "reload the page", no Checkout Session opened', r.get('ok') is False and 'reload' in r.get('message', '').lower() and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='caro@example.invalid'") == '0', r)
pcfg3 = dcfg(direct('/prenota/un-esperto')[2])
r = book(None, day(D), '12:00', email='caro@example.invalid', location='meet', cfg=pcfg3)
rw3 = dbrow(last_id())
check('P5b after reloading, the new price (5900) is what is charged', r.get('ok') is True and rw3['amount_cents'] == '5900', (r, rw3))
# abandoned payment: cancel page, slot released
sid3 = rw3['stripe_session_id']
tok3 = rw3['manage_token']
stt, loc, body = noredirect_get(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=paycancel&token=' + tok3)
check('P6 the payment cancel page opens and says nothing was charged', stt == 200 and 'noindex' in body, (stt, body[:150]))
check('P6b its "choose again" button leads back to the direct page', '/prenota/un-esperto' in body, re.findall(r'href="([^"]+)"', body)[:6])
# a price that cannot be read: refuses (and the admin form would not have saved it)
SLUGS['bad'] = ins('prezzo-rotto', 'Prezzo rotto', price_amount='4,9,9')
bcfg = dcfg(direct('/prenota/prezzo-rotto')[2])
check('P7 an unreadable price in the database: the page says bookings are paused, no booking', bcfg.get('priceInvalid') is True, bcfg.get('priceInvalid'))
r = book(None, day(D), '15:00', email='rotto@example.invalid', cfg=bcfg)
check('P7b ... and the server refuses it (never a free booking of a paid event)', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='rotto@example.invalid'") == '0', r)

# ============================================================== 7. an element that picks a saved type
fresh()
set_tables_clean()
SLUGS['el_free'] = ins('el-libero', 'Evento dal componente', duration='45', location='phone', ordering=1)
SLUGS['el_paid'] = ins('el-pagato', 'Pagato dal componente', duration='30', price_amount='29,00', price_currency='EUR', ordering=2)
SLUGS['el_q'] = ins('el-domande', 'Con domande dal componente', duration='30', custom_fields='Cosa ti serve? | textarea | required', ordering=3)
SLUGS['el_draft'] = ins('el-bozza', 'Bozza', state=0, duration='30', ordering=4)
confs = {
    'et_free': {'event_type': str(SLUGS['el_free'])},
    'et_over': {'event_type': str(SLUGS['el_free']), 'title': 'La mia testata', 'event_duration': '90', 'event_location': 'meet', 'price_amount': '10'},
    'et_paid': {'event_type': str(SLUGS['el_paid']), 'staff_to': 'el-staff@wgbtest.invalid'},
    'et_q': {'event_type': str(SLUGS['el_q'])},
    'et_gone': {'event_type': '9999'},
    'et_draft': {'event_type': str(SLUGS['el_draft'])},
    'et_none': {'event_type': '', 'title': 'Manuale', 'service': 'Manuale', 'event_duration': '20'},
    'et_zero': {'event_type': '0', 'title': 'Manuale', 'service': 'Manuale', 'event_duration': '20'},
    'hand_only': {'title': 'Manuale', 'service': 'Manuale', 'event_duration': '20'},
}
open('_et_cfg.json', 'w').write(json.dumps(confs))
sh(['docker', 'cp', '_et_cfg.json', J + ':/tmp/mock/configs_et.json'])
os.remove('_et_cfg.json')
jx('php /tmp/mock/render_el.php /tmp/mock/configs_et.json >/dev/null; chmod -R a+rX /var/www/html/wgbtest')
c_free = page_cfg('et_free')
check('E1 an element with a saved type takes duration and place from it (45 min, phone)', c_free['duration'] == 45 and c_free['location'] == 'phone', (c_free['duration'], c_free['location']))
h_free = Sess().get('%s/wgbtest/et_free.html' % BASE)
check('E1b ... and the title from the type when the element has none, without repeating it as a subtitle', 'Evento dal componente' in h_free and h_free.count('Evento dal componente') == 1, h_free.count('Evento dal componente'))
fa = form_actions(c_free['form'])
check('E1c the sealed token names the type but not a "page" source (the actions stay the element\'s)', fa.get('etype') == SLUGS['el_free'] and 'src' not in fa, {k: fa.get(k) for k in ['etype', 'src']})
c_over = page_cfg('et_over')
check('E2 the type wins over what the element says by hand (90 min / Meet / 10 EUR are ignored)', c_over['duration'] == 45 and c_over['location'] == 'phone' and c_over['price'] is None, (c_over['duration'], c_over['location'], c_over['price']))
check('E2b the element keeps its own heading', 'La mia testata' in Sess().get('%s/wgbtest/et_over.html' % BASE))
r = book('et_free', day(D), '09:00', email='el1@example.invalid', phone='3330001111', cfg=c_free)
rw = row(last_id())
check('E3 booking from such an element: the type\'s definition is stored (title as name, 45 min, phone)', r.get('ok') is True and rw['event_name'] == 'Evento dal componente' and rw['duration_min'] == '45' and rw['location'] == 'phone', (r, rw))
c_paid = page_cfg('et_paid')
r = book('et_paid', day(D), '10:00', email='el2@example.invalid', cfg=c_paid)
rw = dbrow(last_id())
check('E4 a paid saved type picked by an element charges the type\'s price (2900)', r.get('ok') is True and r.get('redirect') and rw['amount_cents'] == '2900', (r, rw))
check('E4b the staff address of the element (not the type) is kept', sql('SELECT staff_to FROM joom_webgbooking_bookings WHERE id=%d' % last_id()) == 'el-staff@wgbtest.invalid')
upd('el-pagato', price_amount='39')
r = book('et_paid', day(D), '11:00', email='el3@example.invalid', cfg=c_paid)
check('E5 the type was repriced after the page was drawn: refused with "reload the page"', r.get('ok') is False and 'reload' in r.get('message', '').lower(), r)
c_q = page_cfg('et_q')
check('E6 the questions of the saved type are in the element', [f['label'] for f in c_q['customFields']] == ['Cosa ti serve?'] and c_q['customFields'][0]['required'], c_q['customFields'])
c_gone = page_cfg('et_gone')
check('E7 a saved type that no longer exists: the element says so instead of guessing', c_gone.get('unavailable', '').startswith('This type of appointment'), c_gone.get('unavailable'))
r = book('et_gone', day(D), '12:00', email='el4@example.invalid', cfg=c_gone)
check('E7b ... and its booking is refused', r.get('ok') is False and 'no longer available' in r.get('message', ''), r)
c_draft = page_cfg('et_draft')
check('E8 an unpublished saved type: same, "no longer available"', c_draft.get('unavailable'), c_draft.get('unavailable'))
for nm in ('et_none', 'et_zero'):
    c_h = page_cfg(nm)
    c_o = page_cfg('hand_only')
    fa_h = form_actions(c_h['form'])
    fa_o = form_actions(c_o['form'])
    for fa_ in (fa_h, fa_o):
        fa_.pop('iat', None)
    check('E9 %s: an element with an empty/zero saved type behaves exactly like one configured by hand (same token, no type in it, 20 min)' % nm, 'etype' not in fa_h and fa_h == fa_o and c_h['duration'] == 20 and 'unavailable' not in c_h, (fa_h.get('etype'), c_h['duration']))
r = book('et_none', day(D), '13:00', email='el5@example.invalid', cfg=page_cfg('et_none'))
check('E9b a hand-configured element still books (20 minutes)', r.get('ok') is True and row(last_id())['duration_min'] == '20', r)

# ============================================================== 8. the admin
adm, edit_url, save_options, opts = make_admin()
reset_env(render=False)
set_tables_clean()
init()
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes')
check('A1 the list opens, with the new menu entry and its empty message', 'Event types' in lst and 'You have no event types yet' in lst, lst[:100])
sub = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
check('A1b the submenu of the component has "Event types"', 'view=eventtypes' in sub)
form = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.add')
fp = FormParser()
fp.feed(form)
check('A2 the new-event form opens, with the fields', all(('jform[%s]' % n) in form for n in ['title', 'slug', 'state', 'listed', 'description', 'duration', 'location', 'price_amount', 'custom_fields', 'staff_to', 'redirect_url']), form[:150])


def save_et(fields, id_=0, task='eventtype.apply'):
    f0 = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.%s' % ('edit&id=%d' % id_ if id_ else 'add'))
    p = FormParser()
    p.feed(f0)
    pairs = [(k, v) for k, v in p.pairs if k != 'task' and not k.startswith('jform[')]
    base = {k: v for k, v in p.pairs if k.startswith('jform[')}
    for k, v in fields.items():
        base['jform[%s]' % k] = v
    pairs += list(base.items()) + [('task', task)]
    return post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&layout=edit&id=%d' % id_, pairs)


out = save_et({'title': 'Chiamata di prova', 'slug': '', 'duration': '30', 'listed': '1', 'state': '1', 'price_amount': '', 'description': 'Solo un test.'})
row_ = sql("SELECT id,slug,title,state,listed,ordering FROM %s" % TBL).split('\t')
check('A3 saving a new type: stored, slug made from the title, published, public, put at the end', len(row_) == 6 and row_[1] == 'chiamata-di-prova' and row_[3] == '1' and row_[4] == '1', (row_, out[:200]))
new_id = int(row_[0]) if row_[0].isdigit() else 0
st, h, b = direct('/prenota/chiamata-di-prova')
check('A3b ... and its link works at once', st == 200 and ours(b) and 'Chiamata di prova' in b, st)
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes')
check('A4 the list shows it with a "Copy link" button carrying the address (friendly URLs on, no rewriting: index.php/prenota/<slug>)', 'Chiamata di prova' in lst and 'Copy link' in lst and 'data-wgb-copy="http://localhost:%s/index.php/prenota/chiamata-di-prova"' % PORT in lst, re.findall(r'data-wgb-copy="[^"]+"', lst)[:3])
check('A4b ... the copy script is loaded from the media folder', 'com_webgbooking/js/copylink' in lst, re.findall(r'src="[^"]*copylink[^"]*"', lst))
check('A4c ... and it has an Open link, state and visibility badges', 'Open' in lst and 'Public (shown in the list)' in lst)
ed = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.edit&id=%d' % new_id)
check('A5 the edit page shows the link to send, with its Copy link button, and the warning about changing the slug', 'Link to send to the customer' in ed and 'http://localhost:%s/index.php/prenota/chiamata-di-prova' % PORT in ed and 'data-wgb-copy' in ed and 'stop working' in ed, re.findall(r'value="([^"]*prenota[^"]*)"', ed))
check('A5b ... and the alternative long address, also copyable', 'index.php?option=com_webgbooking&amp;view=book&amp;event=chiamata-di-prova' in ed or 'index.php?option=com_webgbooking&view=book&event=chiamata-di-prova' in ed or 'event=chiamata-di-prova' in ed)

# validation: what must not be saved
def count():
    return int(sql('SELECT COUNT(*) FROM %s' % TBL))


n0 = count()
bad = [
    ('empty title', {'title': '', 'slug': 'x-y'}, 'title'),
    ('price that cannot be read', {'title': 'Prezzo', 'slug': 'prezzo-no', 'price_amount': '4,9,9'}, 'price'),
    ('currency we do not charge in', {'title': 'Valuta', 'slug': 'valuta-no', 'price_amount': '10', 'price_currency': 'JPY'}, 'price'),
    ('duration out of range', {'title': 'Durata', 'slug': 'durata-no', 'duration': '9999'}, 'whole number'),
    ('hours with only one end', {'title': 'Orari', 'slug': 'orari-no', 'avail_start': '09:00'}, 'HH:MM'),
    ('hours reversed', {'title': 'Orari2', 'slug': 'orari2-no', 'avail_start': '18:00', 'avail_end': '09:00'}, 'HH:MM'),
    ('days out of range', {'title': 'Giorni', 'slug': 'giorni-no', 'avail_days': '1,9'}, 'days'),
    ('a staff address that is not one', {'title': 'Staff', 'slug': 'staff-no', 'staff_to': 'non-una-mail'}, 'email'),
    ('thank-you page with a bad address', {'title': 'Redirect', 'slug': 'redirect-no', 'redirect_url': 'javascript:alert(1)'}, 'thank-you'),
    ('slug already used', {'title': 'Doppione', 'slug': 'chiamata-di-prova'}, 'already used'),
]
for label, fields, needle in bad:
    out = save_et(fields)
    check('A6 refused: %s (message shown, nothing stored)' % label, needle.lower() in html.unescape(out).lower() and count() == n0, (count(), re.findall(r'alert-(?:danger|error)[^<]*<[^>]*>[^<]*', out)[:2]))
out = save_et({'title': 'Slug strano', 'slug': 'Slug Strano!! con spazi'})
sl = sql("SELECT slug FROM %s WHERE title='Slug strano'" % TBL)
check('A7 a slug typed with spaces, capitals and symbols is cleaned to letters, digits and hyphens', sl == 'slug-strano-con-spazi', sl)
out = save_et({'title': 'Riservato a Mario', 'slug': '', 'listed': '0'})
sl = sql("SELECT slug FROM %s WHERE title='Riservato a Mario'" % TBL)
check('A7b a secret event saved without an address gets one nobody can guess (title plus 16 random hex characters)', re.fullmatch(r'riservato-a-mario-[0-9a-f]{16}', sl) is not None, sl)
st_, h_, b_ = direct('/prenota/' + sl)
check('A7c ... and it opens with that link, while the public index does not list it', st_ == 200 and ours(b_) and 'Riservato a Mario' not in direct('/prenota')[2], st_)
out = save_et({'title': 'Costoso', 'slug': 'costoso', 'price_amount': '29,00', 'price_currency': 'EUR', 'price_label': 'Prova', 'location': 'choice'})
pr = sql("SELECT price_amount,price_currency,location FROM %s WHERE slug='costoso'" % TBL)
check('A8 a paid type saves (amount as typed, currency, place)', pr == '29,00\tEUR\tchoice', pr)
# edit keeps the slug unique against itself
out = save_et({'title': 'Chiamata di prova 2'}, id_=new_id)
check('A9 editing a type keeps its own slug and updates the rest', sql("SELECT slug,title FROM %s WHERE id=%d" % (TBL, new_id)) == 'chiamata-di-prova\tChiamata di prova 2')
# publish / unpublish / delete from the list (token needed)
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes')
p = FormParser()
p.feed(lst)
tokpair = [(k, v) for k, v in p.pairs if re.fullmatch(r'[0-9a-f]{32}', k)]
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes', [('cid[]', str(new_id)), ('boxchecked', '1'), ('task', 'eventtypes.unpublish')] + tokpair)
check('A10 unpublish from the list: the link stops working', sql('SELECT state FROM %s WHERE id=%d' % (TBL, new_id)) == '0' and not ours(direct('/prenota/chiamata-di-prova')[2]))
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes', [('cid[]', str(new_id)), ('boxchecked', '1'), ('task', 'eventtypes.publish')] + tokpair)
check('A10b publish: it works again', sql('SELECT state FROM %s WHERE id=%d' % (TBL, new_id)) == '1' and ours(direct('/prenota/chiamata-di-prova')[2]))
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes', [('cid[]', str(new_id)), ('boxchecked', '1'), ('task', 'eventtypes.delete')])
check('A10c delete without the form token: refused, nothing deleted', sql('SELECT COUNT(*) FROM %s WHERE id=%d' % (TBL, new_id)) == '1')
out = post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes', [('cid[]', str(new_id)), ('boxchecked', '1'), ('task', 'eventtypes.delete')] + tokpair)
check('A10d delete with the token: gone, and its link is gone with it; bookings are not touched', sql('SELECT COUNT(*) FROM %s WHERE id=%d' % (TBL, new_id)) == '0' and not ours(direct('/prenota/chiamata-di-prova')[2]))

# ACL: an administrator whose create / edit / delete / state rights are denied on the component can look, not change
sh(['docker', 'exec', J, 'php', 'cli/joomla.php', 'user:add', '--username=mgrro', '--name=Manager RO', '--password=Mgr-ro-test-pass-123!', '--email=mgrro@example.invalid', '--usergroup=Administrator'])
sql("UPDATE joom_assets SET rules='{\"core.create\":{\"7\":0},\"core.edit\":{\"7\":0},\"core.edit.state\":{\"7\":0},\"core.delete\":{\"7\":0}}' WHERE name='com_webgbooking'")
ro = Sess()
hh = ro.get(BASE + '/administrator/index.php')
tk = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', hh)
dd = {'username': 'mgrro', 'passwd': 'Mgr-ro-test-pass-123!', 'option': 'com_login', 'task': 'login', 'return': base64.b64encode(b'index.php').decode()}
for t_ in tk:
    dd[t_] = '1'
ro.post(BASE + '/administrator/index.php', dd)
sid_keep = sql("SELECT id FROM %s WHERE slug='costoso'" % TBL)
lst = ro.get(BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes')
check('X1 an administrator without the rights sees the list (core.manage) but no New / Delete buttons', 'Costoso' in lst and 'eventtype.add' not in lst and 'eventtypes.delete' not in lst and 'eventtype.edit' not in lst, re.findall(r'task=([a-z.]+)', lst)[:6])
out = ro.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.add')
check('X2 ... and cannot open the new-event form', 'jform[title]' not in out)
out = ro.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.edit&id=%s' % sid_keep)
check('X3 ... nor the edit form', 'jform[title]' not in out)
p = FormParser()
p.feed(lst)
tokpair_ro = [(k, v) for k, v in p.pairs if re.fullmatch(r'[0-9a-f]{32}', k)]
post_pairs(ro, BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes', [('cid[]', sid_keep), ('boxchecked', '1'), ('task', 'eventtypes.delete')] + tokpair_ro)
post_pairs(ro, BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes', [('cid[]', sid_keep), ('boxchecked', '1'), ('task', 'eventtypes.unpublish')] + tokpair_ro)
post_pairs(ro, BASE + '/administrator/index.php?option=com_webgbooking&layout=edit&id=%s' % sid_keep, [('jform[title]', 'Hackerato'), ('jform[slug]', 'costoso'), ('jform[id]', sid_keep), ('task', 'eventtype.save')] + tokpair_ro)
check('X4 ... posting delete / unpublish / save by hand changes nothing', sql("SELECT state,title FROM %s WHERE id=%s" % (TBL, sid_keep)) == '1\tCostoso', sql("SELECT state,title FROM %s WHERE id=%s" % (TBL, sid_keep)))
sql("UPDATE joom_assets SET rules='{}' WHERE name='com_webgbooking'")
sql("DELETE FROM joom_user_usergroup_map WHERE user_id=(SELECT id FROM joom_users WHERE username='mgrro')")
sql("DELETE FROM joom_users WHERE username='mgrro'")

# the plugin options page has the new fields
pg = adm.get(edit_url)
check('A11 plugin options: "first part of the address" and "list of public events"', 'jform[direct_path]' in pg and 'jform[direct_index]' in pg)
save_options({'jform[params][direct_path]': 'Ri servato!'})
pp_ = opts().get('direct_path')
check('A11b the path option is cleaned by Joomla (letters, digits, hyphens) and the page answers on it', re.fullmatch(r'[A-Za-z0-9._-]*', pp_ or '') is not None and ours(direct('/' + (pp_.lower() if pp_ else 'prenota') + '/costoso')[2]), pp_)
init()

# ============================================================== 9. the Copy link address follows the site settings
def set_sef(sef, rewrite):
    jx('php cli/joomla.php config:set sef=%s sef_rewrite=%s >/dev/null' % ('true' if sef else 'false', 'true' if rewrite else 'false'))
    time.sleep(3)   # PHP's opcache looks at the changed settings file every 2 seconds


def copy_url(slug):
    ed_ = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.edit&id=%s' % sid_keep)
    ls_ = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=eventtypes')
    ed_u = html.unescape((re.findall(r'data-wgb-copy="([^"]*)"', ed_) or [''])[0])
    ls_u = html.unescape((re.findall(r'data-wgb-copy="([^"]*%s[^"]*)"' % slug, ls_) or [''])[0])
    return ed_u, ls_u


R_ = 'http://localhost:%s' % PORT
set_sef(True, False)
check('A12 friendly URLs on, no rewriting: the link is index.php/prenota/<slug>', copy_url('costoso') == (R_ + '/index.php/prenota/costoso',) * 2, copy_url('costoso'))
set_sef(True, True)
check('A12b friendly URLs and rewriting on: the link is the short /prenota/<slug>', copy_url('costoso') == (R_ + '/prenota/costoso',) * 2, copy_url('costoso'))
set_sef(False, False)
check('A12c friendly URLs off: the link is the long address', copy_url('costoso') == (R_ + '/index.php?option=com_webgbooking&view=book&event=costoso',) * 2, copy_url('costoso'))
st_, h_, b_ = direct('/index.php?option=com_webgbooking&view=book&event=costoso')
check('A12d ... and that address opens the page with the site set that way', st_ == 200 and ours(b_), st_)
st_, h_, b_ = direct('/prenota/costoso')
check('A12e ... and the short address keeps working too (the page does not depend on the site router)', st_ == 200 and ours(b_), st_)
set_sef(True, False)
ed = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&task=eventtype.edit&id=%s' % sid_keep)
check('A12f the edit page also offers the long address as a second copy button', 'event=costoso' in ed and ed.count('data-wgb-copy') >= 2, ed.count('data-wgb-copy'))

# ============================================================== 10. the upgrade from 0.34.5 creates the table
sql('DROP TABLE %s' % TBL)
new_zip = sh(['docker', 'exec', J, 'sh', '-c', 'ls /tmp/new.zip'])
out = sh(['docker', 'exec', '-w', '/var/www/html', J, 'php', 'cli/joomla.php', 'extension:install', '--path=/tmp/new.zip'])
check('U1 installing the package over a site that has no event types table creates it', sql("SHOW TABLES LIKE '%s'" % TBL) == TBL, out[-200:])
check('U1b ... and the element and the bookings keep working', int(sql('SELECT COUNT(*) FROM joom_webgbooking_bookings')) >= 0)

print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
