"""Collects every email of the plugin through the REAL flows in a test container and saves them as previews:
tools/e2e/reports/email/<slug>.html and .txt (plus manifest.json). email_render.py then turns them into PNGs and the index page.
Usage: python email_previews.py <port> <container-prefix>   (needs WGB_E2E_ADMIN_PASS, like the other suites)."""
import sys, os, re, json, time, datetime, html as htmlmod
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from wgbtest import *

LANG = (sys.argv[3] if len(sys.argv) > 3 else 'it-IT')
BASEDIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'reports', 'email')
OUT = os.path.join(BASEDIR, LANG[:2])
os.makedirs(OUT, exist_ok=True)
STAFF = 'staff@wgbtest.invalid'
SRCURL = 'https://www.example.invalid/prenota'
D = 6
MAN = []
JC = 'wgbtest-6-j' if 'wgbtest-6' in sys.argv[2] else sys.argv[2] + '-j'


def collect():
    out = []
    for pth in mails():
        raw = mail_text(pth)
        m = decode_mail(raw)
        m['raw'] = raw
        out.append(m)
    return out


def clear():
    jx('rm -f /tmp/mail/*.eml')


def sample_logos():
    """Two throwaway logo files (light and dark) so the branded previews show a real image."""
    from PIL import Image, ImageDraw, ImageFont
    for name, fg in (('studio-esempio.png', (24, 38, 61, 255)), ('studio-esempio-scuro.png', (240, 243, 250, 255))):
        im = Image.new('RGBA', (600, 160), (0, 0, 0, 0))
        d = ImageDraw.Draw(im)
        try:
            f = ImageFont.truetype('C:/Windows/Fonts/seguisb.ttf', 64)
        except Exception:
            f = ImageFont.load_default()
        d.ellipse((8, 30, 108, 130), fill=(43, 89, 195, 255))
        d.text((128, 38), 'Studio Esempio', font=f, fill=fg)
        p = os.path.join(BASEDIR, name)
        im.save(p)
        os.system('docker cp "%s" %s:/var/www/html/images/%s' % (p, JC, name))
    jx('chmod a+r /var/www/html/images/studio-esempio*.png')


def language_stub(tag):
    """The test container only has en-GB: a minimal pack for the tag makes the site (and so the emails) run in that language;
    the plugin brings its own strings."""
    if tag == 'en-GB':
        return
    x = ('<?xml version="1.0" encoding="UTF-8"?>' + chr(10) + '<metafile client="site"><name>Test</name><version>6.0.0</version><creationDate>2026-01-01</creationDate>'
         '<author>test</author><copyright>x</copyright><license>GPL</license><description>Test stub</description><metadata><name>Test</name><nativeName>Test</nativeName>'
         '<tag>%s</tag><rtl>0</rtl><locale>it_IT.utf8, it_IT, it</locale><firstDay>1</firstDay><weekEnd>0,6</weekEnd><calendar>gregorian</calendar></metadata></metafile>' + chr(10)) % tag
    tmp = os.path.join(BASEDIR, 'langmetadata.xml')
    open(tmp, 'w', encoding='utf-8').write(x)
    jx('mkdir -p /var/www/html/language/%s' % tag)
    os.system('docker cp "%s" %s:/var/www/html/language/%s/langmetadata.xml' % (tmp, JC, tag))
    os.remove(tmp)
    jx('chmod -R a+rX /var/www/html/language/%s' % tag)


def fix_html(h):
    """Previews are standalone files: the images of the test site become the local sample files."""
    h = re.sub(r'http://localhost:\d+/images/(studio-esempio[-a-z]*\.png)', r'../\g<1>', h)
    # throwaway test values that look like keys to a secret scanner: neutral sample values instead
    h = re.sub(r'mock-bk[0-9a-f]+', 'abc-defg-hij', h)
    h = re.sub(r'(token=)[0-9a-zA-Z]{20,}', r'\g<1>ESEMPIO', h)
    return h


def save(slug, label, audience, m, group):
    if m is None:
        print('MISSING', slug)
        MAN.append({'slug': slug, 'label': label, 'audience': audience, 'group': group, 'missing': True})
        return
    open(os.path.join(OUT, slug + '.html'), 'w', encoding='utf-8').write(fix_html(m['html']))
    open(os.path.join(OUT, slug + '.txt'), 'w', encoding='utf-8').write(fix_html(m['text']))
    MAN.append({'slug': slug, 'label': label, 'audience': audience, 'group': group, 'subject': m['subject'], 'rcpt': m['rcpt'].strip('<>\r '),
                'attach_ics': bool(m['ics'])})
    print('saved', slug, '|', m['subject'])


def pick(ms, rcpt, subj=None, nth=0):
    c = [m for m in ms if rcpt in m['rcpt'] and (subj is None or re.search(subj, m['subject'], re.I))]
    return c[nth] if len(c) > nth else None


def tok_of(i):
    return sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % i)


def backdate(i):
    sql("UPDATE joom_webgbooking_bookings SET updated=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % i)


def run_task():
    sql("UPDATE joom_scheduler_tasks SET locked=NULL, next_execution=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE type LIKE '%webg%'")
    jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=%s >/dev/null 2>&1' % sql("SELECT id FROM joom_scheduler_tasks WHERE type LIKE '%webg%' LIMIT 1"))


def rome(plus_min=0):
    out = jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"%+d minutes\")->format(\"Y-m-d H:i\");'" % plus_min)
    d, t = out.strip().split(' ')
    return d, t


def mock_flag(name, v):
    jx('rm -f /tmp/gmock/%s' % name if v is None else "printf %s > /tmp/gmock/%s; chmod 666 /tmp/gmock/%s" % (v, name, name))


CANCEL = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel'
RESCH = AJAX + '&action=reschedule'

reset_env()
tp.fresh()
sample_logos()
language_stub(LANG)
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.site','%s') WHERE element='com_languages'" % LANG)
CUST = dict(name='Giulia Bianchi', phone='+39 333 123 4567', email='giulia.bianchi@example.invalid')

# ---------------------------------------------------------------- 1. a free appointment with a video call
print('--- video call')
clear()
book('meet30', day(D), '09:30', guest='marco.verdi@example.invalid', notes='Vorrei capire se il sito attuale si puo aggiornare senza rifarlo da zero.', **CUST)
b = last_id()
ms = collect()
save('cliente-confermato-meet', 'Appuntamento confermato, con videochiamata', 'cliente', pick(ms, 'giulia.bianchi@'), '1 Prenotazione')
save('cliente-ospite-invito', 'Invito per l\'ospite', 'cliente', pick(ms, 'marco.verdi@'), '1 Prenotazione')
save('staff-nuova-prenotazione', 'Nuova prenotazione', 'staff', pick(ms, STAFF, r'booking|prenotazione'), '1 Prenotazione')
backdate(b)
clear()
Sess().post(RESCH, {'action': 'reschedule', 'token': tok_of(b), 'date': day(D), 'time': '11:00'})
ms = collect()
save('cliente-spostato', 'Appuntamento spostato', 'cliente', pick(ms, 'giulia.bianchi@'), '1 Prenotazione')
save('staff-spostato', 'Appuntamento spostato dal cliente', 'staff', pick(ms, STAFF), '1 Prenotazione')
backdate(b)
clear()
Sess().post(CANCEL, {'token': tok_of(b)})
ms = collect()
save('cliente-annullato', 'Appuntamento annullato', 'cliente', pick(ms, 'giulia.bianchi@'), '1 Prenotazione')
save('staff-annullato', 'Appuntamento annullato dal cliente', 'staff', pick(ms, STAFF), '1 Prenotazione')

# ---------------------------------------------------------------- 2. phone call and manual approval
print('--- phone and request')
tp.fresh()
clear()
book('hour', day(D + 1), '10:00', location='phone', name='Paolo Neri', phone='+39 347 555 0102', email='paolo.neri@example.invalid')
ms = collect()
save('cliente-confermato-telefono', 'Appuntamento confermato, telefonata', 'cliente', pick(ms, 'paolo.neri@'), '1 Prenotazione')
save('staff-nuova-telefono', 'Nuova prenotazione con telefonata', 'staff', pick(ms, STAFF), '1 Prenotazione')
clear()
book('manual30', day(D + 2), '15:00', name='Elena Gallo', phone='+39 340 111 2233', email='elena.gallo@example.invalid', notes='Preferirei il pomeriggio.')
ms = collect()
save('cliente-richiesta', 'Richiesta ricevuta (conferma manuale)', 'cliente', pick(ms, 'elena.gallo@'), '1 Prenotazione')
save('staff-richiesta', 'Nuova richiesta da confermare', 'staff', pick(ms, STAFF), '1 Prenotazione')

# ---------------------------------------------------------------- 3. the element's own text
print('--- own text')
tp.fresh()
clear()
book('legacyedit', day(D + 14), '09:00', name='Sara Conti', email='sara.conti@example.invalid')
save('cliente-testo-proprio', 'Testo scritto nell\'elemento, dentro il layout', 'cliente', pick(collect(), 'sara.conti@'), '1 Prenotazione')

# ---------------------------------------------------------------- 4. reminders
print('--- reminders')
tp.fresh()
clear()
book('def', day(D + 3), '09:00', guest='ospite.promemoria@example.invalid', name='Luca Ferri', phone='+39 333 999 0001', email='luca.ferri@example.invalid')
r = last_id()
d, t = rome(55)
sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[]' WHERE id=%d" % (d, t, r))
clear()
run_task()
ms = collect()
save('cliente-promemoria', 'Promemoria prima dell\'appuntamento', 'cliente', pick(ms, 'luca.ferri@'), '2 Promemoria e link')
save('cliente-promemoria-ospite', 'Promemoria per l\'ospite', 'cliente', pick(ms, 'ospite.promemoria@'), '2 Promemoria e link')

# ---------------------------------------------------------------- 5. the video call link arrives later
print('--- link later')
tp.fresh()
clear()
mock_flag('google_fail', 'events')
book('meet30', day(D + 4), '09:00', name='Anna Riva', email='anna.riva@example.invalid')
gid = last_id()
ms = collect()
save('cliente-confermato-link-dopo', 'Confermato, il link arriva a breve', 'cliente', pick(ms, 'anna.riva@'), '2 Promemoria e link')
save('staff-avviso-tecnico', 'Avviso tecnico per lo staff (passaggi non completati)', 'staff', next((m for m in ms if STAFF in m['rcpt'] and re.search(r'non completati|not completed|incomplete|did not complete', m['subject'], re.I)), None), '5 Avvisi tecnici')
mock_flag('google_fail', None)
clear()
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % gid)
run_task()
ms = collect()
save('cliente-link-videochiamata', 'Ecco il link per la videochiamata', 'cliente', next((m for m in ms if 'anna.riva@' in m['rcpt'] and 'meet.google' in m['html']), None), '2 Promemoria e link')

# ---------------------------------------------------------------- 6. paid appointment, refund
print('--- paid')
tp.fresh()
r, i = tp.start_paid('paid30', D + 5, '10:00', 'marta.pagante@example.invalid', name='Marta Pagante', phone='+39 333 456 7890', guest='')
sid = tp.dbrow(i)['stripe_session_id']
clear()
tp.pay_in_mock(sid)
tp.webhook(tp.event('checkout.session.completed', sid))
ms = collect()
save('cliente-pagato', 'Pagamento ricevuto, appuntamento confermato', 'cliente', pick(ms, 'marta.pagante@'), '3 Prenotazione a pagamento')
save('staff-pagato', 'Nuova prenotazione pagata', 'staff', next((m for m in ms if STAFF in m['rcpt']), None), '3 Prenotazione a pagamento')
backdate(i)
clear()
Sess().post(CANCEL, {'token': tok_of(i)})
ms = collect()
save('cliente-annullato-rimborso', 'Annullato, con rimborso', 'cliente', pick(ms, 'marta.pagante@'), '3 Prenotazione a pagamento')
save('staff-annullato-rimborso', 'Annullato dal cliente, da rimborsare', 'staff', pick(ms, STAFF), '3 Prenotazione a pagamento')

# ---------------------------------------------------------------- 7. payment after the hold
print('--- late payment')
tp.fresh()


def lapse(i):
    sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), status='expired', payment_status='expired' WHERE id=%d" % i)


r, l1 = tp.start_paid('paid30', D + 1, '09:00', 'carlo.tardivo@example.invalid', name='Carlo Tardivo')
sql("UPDATE joom_webgbooking_bookings SET source_url='%s' WHERE id=%d" % (SRCURL, l1))
lapse(l1)
book('def', day(D + 1), '09:00', email='altro.cliente@example.invalid', name='Altro Cliente')
sid = tp.dbrow(l1)['stripe_session_id']
clear()
tp.pay_in_mock(sid)
tp.webhook(tp.event('checkout.session.completed', sid))
ms = collect()
save('cliente-pagamento-tardivo', 'Pagamento arrivato ma l\'orario non e piu disponibile', 'cliente', pick(ms, 'carlo.tardivo@'), '4 Pagamento tardivo')
save('staff-pagamento-da-verificare', 'Pagamento da verificare (soldi incassati, orario occupato)', 'staff', next((m for m in ms if STAFF in m['rcpt']), None), '4 Pagamento tardivo')
r, l2 = tp.start_paid('paid30', D + 2, '09:00', 'dario.controllo@example.invalid', name='Dario Controllo')
sql("UPDATE joom_webgbooking_bookings SET source_url='%s' WHERE id=%d" % (SRCURL, l2))
lapse(l2)
sid = tp.dbrow(l2)['stripe_session_id']
mock_flag('google_fail', 'freebusy')
clear()
tp.pay_in_mock(sid)
tp.webhook(tp.event('checkout.session.completed', sid))
mock_flag('google_fail', None)
ms = collect()
save('cliente-pagamento-verifica', 'Pagamento ricevuto, stiamo verificando', 'cliente', pick(ms, 'dario.controllo@'), '4 Pagamento tardivo')

# ---------------------------------------------------------------- 8. technical alerts
print('--- alerts')
tp.fresh()
clear()
book('brevo1', day(D + 7), '09:00', name='Irene Testa', email='irene.testa@example.invalid', extra={'custom[cf0]': 'Torino', 'custom[cf1]': 'Sito nuovo'})
ms = collect()
save('staff-avviso-brevo', 'Avviso: contatto non salvato in Brevo', 'staff', next((m for m in ms if STAFF in m['rcpt'] and 'Brevo' in m['subject']), None), '5 Avvisi tecnici')
clear()
book('hookmissing', day(D + 8), '09:00', name='Omar Sala', email='omar.sala@example.invalid')
ms = collect()
save('staff-avviso-webhook', 'Avviso: webhook non configurato', 'staff', next((m for m in ms if STAFF in m['rcpt'] and re.search(r'webhook', m['subject'], re.I)), None), '5 Avvisi tecnici')

# ---------------------------------------------------------------- 9. the same emails with a brand
print('--- branded')
tp.fresh()
footer = 'Studio Esempio S.r.l.\\nVia Roma 1, 10121 Torino\\nP.IVA 01234567890\\nInformativa privacy: https://www.example.invalid/privacy'
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.mail_logo','images/studio-esempio.png#joomlaImage://local-images/studio-esempio.png?width=600&height=160',"
    "'$.mail_logo_dark','images/studio-esempio-scuro.png#joomlaImage://local-images/studio-esempio-scuro.png?width=600&height=160',"
    "'$.mail_color','#0f766e','$.mail_button_color','#0f766e','$.mail_footer','%s','$.mail_from_name','Studio Esempio') WHERE element='webgbooking' AND folder='system'" % footer)
clear()
book('meet30', day(D + 9), '09:30', guest='', notes='Prima consulenza.', **CUST)
ms = collect()
save('marchio-cliente-confermato', 'Con logo, colore e piede di pagina propri: cliente', 'cliente', pick(ms, 'giulia.bianchi@'), '6 Con il marchio del cliente')
save('marchio-staff-nuova', 'Con logo, colore e piede di pagina propri: staff', 'staff', pick(ms, STAFF, r'booking|prenotazione'), '6 Con il marchio del cliente')
tp.fresh()

sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.site','en-GB') WHERE element='com_languages'")
jx('rm -rf /var/www/html/language/%s' % LANG if LANG != 'en-GB' else 'true')
# every preview must be clean: no empty style value ("color:;"), no PHP leftovers
_bad = []
for _m in MAN:
    if _m.get('missing'):
        continue
    _h = open(os.path.join(OUT, _m['slug'] + '.html'), encoding='utf-8').read()
    for _st in re.findall(r'style="([^"]*)"', _h):
        if re.search(r'(^|;)\s*[a-z-]+\s*:\s*(;|$)', _st) or 'Array' in _st or 'Warning' in _st or 'Notice' in _st:
            _bad.append((_m['slug'], _st[:80]))
print('STYLE CHECK:', 'ok, no empty value in %d previews' % len(MAN) if not _bad else 'PROBLEMS %s' % _bad[:5])
json.dump(MAN, open(os.path.join(OUT, 'manifest.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('PREVIEWS', len([m for m in MAN if not m.get('missing')]), 'saved,', len([m for m in MAN if m.get('missing')]), 'missing')
