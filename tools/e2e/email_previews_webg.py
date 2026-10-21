"""Previews of the customer confirmation and the staff new-booking email with the WebG look taken from the Brevo templates
(logo, colours, footer, sender name). Same machinery as email_previews.py, output in reports/email/webg/.
Usage: python email_previews_webg.py <port> <container-prefix>"""
import sys
sys.argv = [sys.argv[0], sys.argv[1], sys.argv[2], 'it-IT']
_src = open(__import__('os').path.join(__import__('os').path.dirname(__import__('os').path.abspath(__file__)), 'email_previews.py'), encoding='utf-8').read()
exec(_src.split("CUST = dict")[0])
OUT = os.path.join(BASEDIR, 'webg')
os.makedirs(OUT, exist_ok=True)
MAN = []

# the real file of webg.it, at the same media path the site uses (copied from the public address, kept in reports/email/logo_webg.png)
jx('mkdir -p /var/www/html/images/yootheme/webg')
os.system('docker cp "%s" %s:/var/www/html/images/yootheme/webg/logo_webg.png' % (os.path.join(BASEDIR, 'logo_webg.png'), JC))
jx('chmod -R a+rX /var/www/html/images/yootheme')

# values read from the Brevo templates (newsletter 22-10 and ADV): see the report table
FOOTER = chr(92).join(['WebG di Marco Galassi', '']) 
footer = 'WebG di Marco Galassi' + chr(92) + 'nCorso Taranto 62, 10154 Torino (TO)' + chr(92) + 'nP. IVA 12774610013' + chr(92) + 'nPrivacy: https://www.webg.it/privacy.html'
tp.fresh()
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.site','it-IT') WHERE element='com_languages'")
sql("UPDATE joom_extensions SET params=JSON_SET(params,"
    "'$.mail_logo','images/yootheme/webg/logo_webg.png#joomlaImage://local-images/yootheme/webg/logo_webg.png?width=400&height=180',"
    "'$.mail_color','#ee6c2d','$.mail_line_color','','$.mail_link_color','#b34a16','$.mail_button_color','#fd9265',"
    "'$.mail_business_name','WebG','$.mail_text_color','#363650','$.mail_footer_color','#f2f4f7','$.mail_logo_pos','center','$.mail_header_style','rule','$.mail_footer_out','1','$.mail_footer','%s','$.mail_from_name','Marco Galassi, WebG') "
    "WHERE element='com_webgbooking' AND type='component'" % footer)


def fix_webg(h):
    h = re.sub(r'http://localhost:\d+/images/yootheme/webg/logo_webg\.png', '../logo_webg.png', h)
    h = re.sub(r'mock-bk[0-9a-f]+', 'abc-defg-hij', h)
    h = re.sub(r'(token=)[0-9a-zA-Z]{20,}', r'\g<1>ESEMPIO', h)
    return h.replace('wgbtest', 'WebG')


def save_w(slug, label, audience, m):
    open(os.path.join(OUT, slug + '.html'), 'w', encoding='utf-8').write(fix_webg(m['html']))
    open(os.path.join(OUT, slug + '.txt'), 'w', encoding='utf-8').write(fix_webg(m['text']))
    MAN.append({'slug': slug, 'label': label, 'audience': audience, 'group': '1 Con il marchio WebG (valori presi da Brevo)', 'subject': m['subject'], 'rcpt': m['rcpt'].strip('<>\r '), 'attach_ics': bool(m['ics'])})
    print('saved', slug, '|', m['subject'])


clear()
CUST = dict(name='Giulia Bianchi', phone='+39 333 123 4567', email='giulia.bianchi@example.invalid')
book('meet30', day(D), '09:30', notes='Vorrei capire se il sito attuale si puo aggiornare senza rifarlo da zero.', **CUST)
ms = collect()
save_w('webg-cliente-confermato', 'Conferma al cliente', 'cliente', pick(ms, 'giulia.bianchi@'))
save_w('webg-staff-nuova', 'Nuova prenotazione per lo staff', 'staff', pick(ms, STAFF, r'booking|prenotazione'))
sender = [l for l in ms[0]['raw'].split(chr(10)) if l.lower().startswith('from:')][:1]
print('FROM header:', sender)
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.site','en-GB') WHERE element='com_languages'")
jx('rm -rf /var/www/html/language/it-IT')
tp.fresh()
json.dump(MAN, open(os.path.join(OUT, 'manifest.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('WEBG PREVIEWS', len(MAN))
