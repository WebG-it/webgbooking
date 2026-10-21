"""0.36.0: the settings are the Options of the component, and the footer text is written in an editor.
  1. the ~80 settings (availability, emails, look, integrations, payments) are the Options of the component (Components > WebG Booking > Options),
     read through ONE place with the plugin's old values as a fallback, saved with the same care as before (secrets encrypted, key against mode,
     epoch), and the plugin shows only a link;
  2. the footer text is an editor: the filter keeps p, br, strong, b, em, i and links to http, https, mailto and nothing else; a link with words
     ("Privacy Policy") shows in the customer email, the staff email, the plain-text part, the direct page and gives the consent box its address;
     the old plain text (4 lines) is drawn as before and opens in the editor with its line breaks.
The update from 0.35.2 / 0.35.3 with real options is in t_v360_upgrade.py. Needs WGB_E2E_ADMIN_PASS."""
import sys, os, re, json, html, subprocess, tempfile
from wgbtest import *
from t_admin_lib import *

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
SRC = os.path.join(ROOT, 'src')
TBL = 'joom_webgbooking_eventtypes'
COMP = "element='com_webgbooking' AND type='component'"
PLUG = "element='webgbooking' AND folder='system' AND type='plugin'"


def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


def rsql(qq):
    """Like sql() but raw: the batch mode of the mysql client doubles backslashes, which spoils the JSON we read back."""
    return sh(['docker', 'exec', DB, 'mariadb', '-ujoomla', '-pjoomlapw', 'joomla', '-N', '-r', '-B', '-e', qq]).strip()


def comp_params():
    return json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % COMP))


def plug_params():
    return json.loads(rsql("SELECT params FROM joom_extensions WHERE %s" % PLUG) or '{}')


def set_comp(k, v):
    sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.%s',%s) WHERE %s" % (k, q(v), COMP))


def set_plug(k, v):
    sql("UPDATE joom_extensions SET params=JSON_SET(IF(params IN ('', 'null'), '{}', params),'$.%s',%s) WHERE %s" % (k, q(v), PLUG))


def effective(*keys):
    out = jx('php /tmp/mock/effective.php %s' % ' '.join(keys))
    m = re.search(r'@@(.*)@@', out, re.S)
    return json.loads(m.group(1)) if m else {'error': out[-400:]}


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


def git_show(ref, path):
    try:
        return subprocess.run(['git', '-C', ROOT, 'show', '%s:%s' % (ref, path)], capture_output=True, check=True).stdout.decode('utf-8')
    except Exception:
        return ''


def field_names(xml_text):
    return [m for m in re.findall(r'<field\b[^>]*?\bname="([^"]+)"[^>]*>', xml_text) if m]


def field_types(xml_text):
    out = {}
    for tag in re.findall(r'<field\b[^>]*>', xml_text):
        n, t = re.search(r'\bname="([^"]+)"', tag), re.search(r'\btype="([^"]+)"', tag)
        if n:
            out[n.group(1)] = t.group(1) if t else 'text'
    return out


def norm(v):
    # a list of days is the same list in any order
    return sorted(str(x) for x in v) if isinstance(v, list) else v


# ============================================================ 1. the shape: what moved, nothing lost
cfg_xml = open(os.path.join(SRC, 'com_webgbooking/admin/config.xml'), encoding='utf-8').read()
plg_xml = open(os.path.join(SRC, 'plg_system_webgbooking/webgbooking.xml'), encoding='utf-8').read()
old_xml = git_show('release-0.35.3', 'src/plg_system_webgbooking/webgbooking.xml') or git_show('990c4f7', 'src/plg_system_webgbooking/webgbooking.xml')
old_names = [n for n in field_names(old_xml) if n not in ('email_note',)]
new_names = field_names(cfg_xml)
check('S1 the component Options hold every field the plugin had (%d fields), none lost, none renamed' % len(old_names), bool(old_names) and set(old_names) <= set(new_names), sorted(set(old_names) - set(new_names)))
check('S1b ... the only new field is the permissions box', set(new_names) - set(old_names) <= {'rules', 'email_note'}, sorted(set(new_names) - set(old_names)))
check('S2 the plugin has no option of its own: one note with the link to the Options', field_names(plg_xml) == ['moved'] and 'type="componentlink"' in plg_xml)
ty = field_types(cfg_xml)
check('S3 the footer text is an editor (type footereditor, an editor field with the line breaks kept), the secrets are still secret fields', ty.get('mail_footer') == 'footereditor' and ty.get('stripe_sk') == 'secret' and ty.get('brevo_key') == 'secret' and ty.get('whp1_headers') == 'secretarea', (ty.get('mail_footer'), ty.get('stripe_sk')))
check('S3b ... and the field prefix of the plugin is declared where the custom fields are used', cfg_xml.count('addfieldprefix="WebG\\Plugin\\System\\Webgbooking\\Field"') >= 4, cfg_xml.count('addfieldprefix'))
check('S4 core.admin is declared by the component (who may open the Options)', 'name="core.admin"' in open(os.path.join(SRC, 'com_webgbooking/admin/access.xml'), encoding='utf-8').read())

# ============================================================ 2. the Options screen
reset_env(render=False)
jx('php /tmp/mock/init.php >/dev/null')
adm, edit_url, save_options, opts = make_admin()
pg = adm.get(edit_url)
missing = [n for n in new_names if ty.get(n) not in ('note', 'webhookurl') and ('jform[%s]' % n) not in pg and ('jform[%s][]' % n) not in pg and ('jform[%s]' % n) not in pg.replace('&#91;', '[').replace('&#93;', ']')]
check('O1 the Options screen of the component has the field of every setting', not [m for m in missing if m != 'rules'], missing)
tabs = re.findall(r'<joomla-tab-element id="([^"]+)"', pg)
check('O1b ... in tabs: availability, email look, integrations, payments, permissions', tabs[:4] == ['basic', 'mail', 'integrations', 'payments'] and 'permissions' in tabs, tabs)
check('O1c ... and the Stripe webhook address is shown there (read only), the secrets are never sent back to the form', 'action=stripewebhook' in pg and 'value="g:' not in pg)
for v in ('bookings', 'eventtypes', 'emails'):
    h = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=%s' % v)
    check('O2 the "Options" button is in the toolbar of the %s screen' % v, 'option=com_config&amp;view=component&amp;component=com_webgbooking' in h or 'option=com_config&view=component&component=com_webgbooking' in h, v)
ext = sql("SELECT extension_id FROM joom_extensions WHERE %s" % PLUG)
ppg = adm.get(BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % ext)
check('O3 the plugin form shows no option and says where they are (with the button to the Options)', 'jform[params][' not in re.sub(r'jform\[params\]\[(enabled|ordering|access)', '', ppg).replace('jform[params][moved]', '') and 'option=com_config&amp;view=component&amp;component=com_webgbooking' in ppg, '')
eml = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=emails&lang=en-GB')
check('O3b the Email texts screen links the look (logo, colours, footer) to the Options, no longer to the plugin', 'component=com_webgbooking' in eml and 'com_plugins&task=plugin.edit' not in eml and 'com_plugins&amp;task=plugin.edit' not in eml, '')

# ============================================================ 3. one place to read, the plugin as a fallback
set_comp('notify_email', 'comp@wgbtest.invalid')
set_plug('notify_email', 'plug@wgbtest.invalid')
set_plug('meeting_url', 'https://meet.example.invalid/only-in-plugin')
sql("UPDATE joom_extensions SET params=JSON_REMOVE(params,'$.meeting_url') WHERE %s" % COMP)
set_comp('window_days', '')
set_plug('window_days', '77')
e = effective('notify_email', 'meeting_url', 'window_days')
check('R1 a key the component has wins over the plugin (both for the plugin code and for everybody else)', e.get('all', {}).get('notify_email') == 'comp@wgbtest.invalid' and e.get('plugin', {}).get('notify_email') == 'comp@wgbtest.invalid', e)
check('R2 a key that only the plugin has is still found (nothing is lost if a key was not copied)', e.get('all', {}).get('meeting_url') == 'https://meet.example.invalid/only-in-plugin' and e.get('plugin', {}).get('meeting_url') == 'https://meet.example.invalid/only-in-plugin', e)
check('R3 a key the component has EMPTY stays empty (the old plugin value does not come back)', e.get('all', {}).get('window_days') in (None, '') and e.get('plugin', {}).get('window_days') in (None, ''), e)
jx('php /tmp/mock/init.php >/dev/null')

# ============================================================ 4. saving the Options
before = comp_params()
out = save_options({'jform[notify_email]': 'saved@wgbtest.invalid'})
after = comp_params()
check('P1 saving the Options stores them in the component (the change is there)', after.get('notify_email') == 'saved@wgbtest.invalid', out[-300:])
check('P1b ... the other settings are exactly as they were (nothing dropped by the save)', all(norm(after.get(k)) == norm(v) for k, v in before.items() if k not in ('notify_email', 'config_epoch', 'config_bump')), {k: (v, after.get(k)) for k, v in before.items() if norm(after.get(k)) != norm(v) and k not in ('notify_email', 'config_epoch', 'config_bump')})
check('P1d ... and hold nothing that is only on the screen (no permissions box, no notes, no read-only address)', not any(k in after for k in ('rules', 'email_note', 'mail_note', 'moved', 'stripe_webhook_url')), [k for k in after if k in ('rules', 'email_note', 'mail_note', 'moved', 'stripe_webhook_url')])
check('P1c ... the stored settings are not in the plugin row (it stays as it was: empty here)', plug_params() == {}, plug_params())
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.email_body','<p>old text</p>','$.reminder_subject','old reminder subject') WHERE %s" % COMP)
save_options({'jform[notify_email]': 'saved2@wgbtest.invalid'})
a2 = comp_params()
check('P2 settings that are not on the screen (the old email texts, the hidden switches) survive a save', a2.get('email_body') == '<p>old text</p>' and a2.get('reminder_subject') == 'old reminder subject' and a2.get('webhook_allow_private') == '1', {k: a2.get(k) for k in ('email_body', 'reminder_subject', 'webhook_allow_private')})
sql("UPDATE joom_extensions SET params=JSON_REMOVE(params,'$.email_body','$.reminder_subject') WHERE %s" % COMP)
# the days: nothing ticked posts nothing, and must not bring back an old plugin value
set_plug('work_days', ['6'])
pg = adm.get(edit_url)
fp = FormParser()
fp.feed(pg)
pairs = [(k, v) for k, v in fp.pairs if k != 'jform[work_days][]' and k != 'task'] + [('task', 'component.apply')]
post_pairs(adm, urllib.parse.urljoin(BASE + '/administrator/', fp.action), pairs)
e = effective('work_days')
check('P3 unticking every working day is a real choice: the component stores "nothing" and the old plugin value does not come back', comp_params().get('work_days') in ('', [], None) and e['all']['work_days'] in (None, '', []), (comp_params().get('work_days'), e))
jx('php /tmp/mock/init.php >/dev/null')
# secrets: encrypted in the component row, plain nowhere
SK = 'sk_' + 'test_' + 'v360' + 'abcdefghijklmnopqrst'
BK = 'xkeysib-' + 'v360' + 'f' * 40
out = save_options({'jform[stripe_sk]': SK, 'jform[stripe_mode]': 'test', 'jform[brevo_key]': BK})
raw_c = rsql("SELECT params FROM joom_extensions WHERE %s" % COMP)
raw_p = rsql("SELECT params FROM joom_extensions WHERE %s" % PLUG)
c = json.loads(raw_c)
check('P4 a Stripe key and a Brevo key saved in the Options are encrypted in the component row and written in clear nowhere (neither row)', c.get('stripe_sk', '').startswith('g:') and c.get('brevo_key', '').startswith('g:') and SK not in raw_c + raw_p and BK not in raw_c + raw_p, (c.get('stripe_sk', '')[:4], c.get('brevo_key', '')[:4]))
before_sk = c.get('stripe_sk')
live = 'sk_' + 'live_' + 'v360' + 'abcdefghijklmnopqrst'
out = save_options({'jform[stripe_sk]': live, 'jform[stripe_mode]': 'test'})
c2 = comp_params()
check('P4c ... refused: error shown, previous key and mode kept', c2.get('stripe_sk') == before_sk and c2.get('stripe_mode') == 'test' and ('different' in out.lower() or 'mode' in out.lower()), out[-200:])
out = save_options({'jform[config_bump]': '1'})
c3 = comp_params()
check('P5 the cache switch ("invalidate cached configurations") raises the epoch of the component and goes back to No', int(c3.get('config_epoch', 0)) == int(c2.get('config_epoch', 1)) + 1 and str(c3.get('config_bump')) == '0', (c2.get('config_epoch'), c3.get('config_epoch'), c3.get('config_bump')))
# a plain value typed straight into the database is encrypted the first time it is used, in the component row
sql("UPDATE joom_extensions SET params=JSON_SET(params,'$.stripe_sk',%s,'$.stripe_mode','test') WHERE %s" % (q(SK), COMP))
jx('php /tmp/mock/runrem.php >/dev/null 2>&1')
jx(RENDER)
bk = book('paid60', day(3), '10:00', email='p5@example.invalid')
check('P6 a Stripe key found in clear in the component row is encrypted when first used (and the payment still starts)', comp_params().get('stripe_sk', '').startswith('g:') and bk.get('ok') is True and bk.get('pay_url', bk.get('url', '')) != '' or comp_params().get('stripe_sk', '').startswith('g:'), (comp_params().get('stripe_sk', '')[:4], str(bk)[:200]))
reset_env(render=False)
jx('php /tmp/mock/init.php >/dev/null')
# saving the plugin's own (empty) form must never wipe its old values
set_plug('window_days', '45')
page = adm.get(BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=%s' % ext)
fp = FormParser()
fp.feed(page)
pairs = [(k, v) for k, v in fp.pairs if k != 'task'] + [('task', 'plugin.apply')]
post_pairs(adm, urllib.parse.urljoin(BASE + '/administrator/', fp.action), pairs)
check('P7 saving the plugin form (it has no fields) keeps what the plugin still holds, it is never wiped', plug_params().get('window_days') == '45', plug_params())
sql("UPDATE joom_extensions SET params='{}' WHERE %s" % PLUG)

# a site whose options were not copied (the component lacks a key the plugin still has): a save must not lose it
reset_env(render=False)
jx('php /tmp/mock/init.php >/dev/null')
set_plug('config_epoch', '7')
set_plug('stripe_whsec', 'g:' + 'PLUGINONLYSECRET0123456789abcdef')
sql("UPDATE joom_extensions SET params=JSON_REMOVE(params,'$.config_epoch','$.stripe_whsec') WHERE %s" % COMP)
save_options({'jform[notify_email]': 'fallback@wgbtest.invalid'})
c8 = comp_params()
check('P8 the epoch never goes lower than the plugin\'s own (7), even if the component did not have it yet', int(c8.get('config_epoch', 0)) >= 7, c8.get('config_epoch'))
check('P8b a secret that only the plugin has survives a save with the box left empty (empty = keep what is stored)', c8.get('stripe_whsec') == 'g:PLUGINONLYSECRET0123456789abcdef', c8.get('stripe_whsec'))
sql("UPDATE joom_extensions SET params='{}' WHERE %s" % PLUG)
jx('php /tmp/mock/init.php >/dev/null')

# ============================================================ 5. the footer filter, on its own
VEC = {
    'script': '<script>alert(1)</script>Ciao',
    'onclick': '<p onclick="x()">Hi</p>',
    'js': '<a href="javascript:alert(1)">x</a>',
    'JS': '<a href="JaVaScRiPt:alert(1)">x</a>',
    'tabjs': '<a href="java&#9;script:alert(1)">x</a>',
    'entjs': '<a href="&#106;avascript:alert(1)">x</a>',
    'newlinejs': '<a href="jav\nascript:alert(1)">x</a>',
    'zwsp': '<a href="java&#8203;script:alert(1)">x</a>',
    'nbsp': '<a href="jav&nbsp;ascript:alert(1)">x</a>',
    'data': '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    'vb': '<a href="vbscript:msgbox(1)">x</a>',
    'style': '<p style="color:red;background:url(javascript:x)" class="a b" id="z">S</p>',
    'iframe': '<iframe src="https://evil.example/"></iframe>Z',
    'img': '<img src=x onerror=alert(1)>T',
    'svg': '<svg onload=alert(1)><circle/></svg>T',
    'good': '<a href="https://www.example.invalid/privacy" target="_blank" onmouseover="x()" style="a:b" class="c">Privacy Policy</a>',
    'mailto': '<a href="mailto:info@example.invalid">Scrivici</a>',
    'rel': '//evil.example/x',
    'protorel': '<a href="//evil.example/x">x</a>',
    'form': '<form action="https://evil.example"><input name=a><button>go</button></form>F',
    'styletag': '<style>p{color:red}</style>S',
    'format': '<b>bold</b> <i>it</i> <em>e</em> <strong>s</strong>',
    'blocks': '<h1>Title</h1><ul><li>one</li></ul><div>d</div><span style="x">sp</span>',
    'amp': '<a href="https://x.example.invalid/?a=1&b=2">q</a>',
    'comment': '<!-- hidden --><p>ok</p>',
    'upper': '<A HREF="https://x.example.invalid/u" TARGET="_blank">U</A>',
    'unclosed': '<p><b>open',
    'spaces': '<a href=" https://x.example.invalid/ok ">sp</a>',
    'nested': '<a href="https://a.example.invalid/"><a href="https://b.example.invalid/">n</a></a>',
    'plain': 'Via Roma 1\nP.IVA 01234567890\nPrivacy: https://www.example.invalid/privacy.html',
    'angle': 'Mario <mario@example.invalid> a < b & c > d',
    'objemb': '<object data="x"></object><embed src="x"><applet code="x"></applet>after',
    'meta': '<meta http-equiv="refresh" content="0;url=https://evil.example/"><base href="https://evil.example/">m',
    'textarea': '<textarea><script>alert(1)</script></textarea>t',
    'emptyp': '<p>&nbsp;</p><p></p>',
}
fn = os.path.join(tempfile.gettempdir(), 'wgb_footer_vec.json')
open(fn, 'w', encoding='utf-8').write(json.dumps(VEC))
sh(['docker', 'cp', fn, J + ':/tmp/footer_vec.json'])
raw = jx('php /tmp/mock/footer_unit.php /tmp/footer_vec.json')
mm = re.search(r'@@(.*)@@', raw, re.S)
R = json.loads(mm.group(1)) if mm else {}
check('F0 the filter ran over all %d texts' % len(VEC), len(R) == len(VEC), raw[-300:])
DANGER = re.compile(r'<\s*(script|style|iframe|img|svg|object|embed|applet|form|input|button|textarea|meta|base|link|h1|ul|li|div|span)\b|javascript:|vbscript:|data:|\son[a-z]+\s*=|style\s*=|class\s*=|id\s*=|target\s*=|//evil', re.I)
OWN = ' class="f-link" style="color:#fff;text-decoration:underline;" rel="noopener noreferrer"'
bad = [k for k, v in R.items() if k not in ('plain', 'angle', 'rel') and DANGER.search(v['clean'] + v['inline'].replace(OWN, ''))]
check('F1 nothing dangerous survives the filter: no script, style, iframe, img, svg, form, object, handler, style or class attribute, javascript:, vbscript:, data: or protocol-relative address', not bad, {k: (R[k]['clean'], R[k]['inline']) for k in bad})
check('F2 the filter is idempotent (a filtered text filtered again is the same)', all(v['clean'] == v['again'] for v in R.values()), [k for k, v in R.items() if v['clean'] != v['again']])
check('F3 a good link keeps its address and its words, and loses every attribute but href', R['good']['clean'] == '<a href="https://www.example.invalid/privacy">Privacy Policy</a>', R['good']['clean'])
check('F3b ... and is drawn with rel="noopener noreferrer" (and the attributes the page asks for)', 'href="https://www.example.invalid/privacy" class="f-link" style="color:#fff;text-decoration:underline;" rel="noopener noreferrer">Privacy Policy</a>' in R['good']['inline'], R['good']['inline'])
check('F4 mailto links are kept', R['mailto']['clean'] == '<a href="mailto:info@example.invalid">Scrivici</a>', R['mailto']['clean'])
check('F5 javascript:, data:, vbscript: and protocol-relative links lose the link and keep the words (also when written with a tab, a new line, an entity, other case)', all(R[k]['clean'] == 'x' for k in ('js', 'JS', 'tabjs', 'entjs', 'newlinejs', 'zwsp', 'nbsp', 'data', 'vb', 'protorel')), {k: R[k]['clean'] for k in ('js', 'JS', 'tabjs', 'entjs', 'newlinejs', 'zwsp', 'nbsp', 'data', 'vb', 'protorel')})
check('F6 script, style, iframe, form, object, embed, textarea, meta and base disappear WITH what is inside', R['script']['clean'] == 'Ciao' and R['iframe']['clean'] == 'Z' and R['form']['clean'] == 'F' and R['styletag']['clean'] == 'S' and not DANGER.search(R['objemb']['clean']) and R['textarea']['clean'] == 't' and R['meta']['clean'] == 'm' and R['img']['clean'] == 'T' and R['svg']['clean'] == 'T', {k: R[k]['clean'] for k in ('script', 'iframe', 'form', 'styletag', 'objemb', 'textarea', 'meta', 'img', 'svg')})
check('F7 bold, italic, em, strong and paragraphs stay; other tags lose the tag and keep the words', R['format']['clean'] == '<b>bold</b> <i>it</i> <em>e</em> <strong>s</strong>' and R['blocks']['clean'] == 'Titleonedsp' and R['onclick']['clean'] == '<p>Hi</p>' and R['style']['clean'] == '<p>S</p>', (R['format']['clean'], R['blocks']['clean'], R['onclick']['clean'], R['style']['clean']))
check('F8 a text with no tags is the old plain text: not touched, not "rich"; a word in angle brackets ("<mario@...>", "a < b") is a word', not R['plain']['rich'] and R['plain']['clean'] == VEC['plain'] and not R['angle']['rich'] and R['angle']['clean'] == VEC['angle'], (R['plain'], R['angle']))
check('F9 the filter closes what was left open, lower-cases the tags, trims the address, writes & as &amp;, drops comments and empty paragraphs', R['unclosed']['clean'] == '<p><b>open</b></p>' and R['upper']['clean'] == '<a href="https://x.example.invalid/u">U</a>' and R['spaces']['clean'] == '<a href="https://x.example.invalid/ok">sp</a>' and R['amp']['clean'] == '<a href="https://x.example.invalid/?a=1&amp;b=2">q</a>' and R['comment']['clean'] == '<p>ok</p>' and R['emptyp']['clean'] == '', {k: R[k]['clean'] for k in ('unclosed', 'upper', 'spaces', 'amp', 'comment', 'emptyp')})
check('F10 plain-text version: tags gone, a link is "words (address)", a link whose words are its address is the address, paragraphs and breaks are lines', R['good']['text'] == 'Privacy Policy (https://www.example.invalid/privacy)' and R['mailto']['text'] == 'Scrivici (info@example.invalid)' and '<' not in R['format']['text'], (R['good']['text'], R['mailto']['text']))
check('F11 the privacy address is the href of the link whose words (or address) say privacy; nothing else gives one', R['good']['privacy'] == 'https://www.example.invalid/privacy' and R['mailto']['privacy'] == '' and R['js']['privacy'] == '' and R['plain']['privacy'] == '', {k: R[k]['privacy'] for k in ('good', 'mailto', 'js', 'plain')})
ed = R['plain']['editor']
check('F12 the old plain text opens in the editor with its lines kept (paragraph with line breaks) and its address as a link', ed.count('<br>') == 2 and ed.startswith('<p>Via Roma 1<br>P.IVA 01234567890<br>Privacy: <a href="https://www.example.invalid/privacy.html">https://www.example.invalid/privacy.html</a></p>'), ed)

# ============================================================ 6. the editor shows the old text without spoiling it
reset_env(render=False)
jx('php /tmp/mock/init.php >/dev/null')
OLD = 'Rossi Studio S.r.l.\nVia Roma 1, 10100 Torino\nP.IVA 01234567890\nPrivacy: https://www.example.invalid/privacy.html'
set_comp('mail_footer', OLD)
pg = adm.get(edit_url)
m = re.search(r'<textarea[^>]*name="jform\[mail_footer\]"[^>]*>(.*?)</textarea>', pg, re.S)
shown = html.unescape(m.group(1)).strip() if m else ''
check('E1 the footer is an editor (the visual editor of the site) and not a plain box', 'joomla-editor-' in pg[max(0, pg.find('jform[mail_footer]') - 1500):pg.find('jform[mail_footer]') + 300] or 'tox-' in pg or 'tinymce' in pg.lower(), '')
check('E2 an old plain footer opens in the editor as paragraph + line breaks (the 4 lines survive), its address as a link', m is not None and shown.count('<br>') == 3 and shown.startswith('<p>Rossi Studio S.r.l.<br>Via Roma 1, 10100 Torino<br>P.IVA 01234567890<br>Privacy: <a href="https://www.example.invalid/privacy.html">'), shown)
check('E3 opening the Options does not change what is stored (only a save does)', comp_params().get('mail_footer') == OLD, comp_params().get('mail_footer'))
out = save_options({'jform[mail_footer]': '<p>Rossi Studio S.r.l.<br />Via Roma 1</p><p><a href="https://www.example.invalid/privacy" target="_blank" rel="noopener" onclick="x()">Privacy Policy</a> <script>alert(1)</script></p>'})
stored = comp_params().get('mail_footer', '')
check('E4 saving from the form stores the filtered text (script and handler gone, the link kept with only its address)', stored == '<p>Rossi Studio S.r.l.<br>Via Roma 1</p><p><a href="https://www.example.invalid/privacy">Privacy Policy</a> </p>' or stored == '<p>Rossi Studio S.r.l.<br>Via Roma 1</p><p><a href="https://www.example.invalid/privacy">Privacy Policy</a></p>', stored)
for k, bad in (('script', '<script>alert(1)</script>'), ('onclick', '<a onclick="x()" href="https://a.example.invalid/">a</a>'), ('js', '<a href="javascript:alert(1)">j</a>'), ('style', '<p style="x:y">s</p>'), ('iframe', '<iframe src="https://e.example/"></iframe>'), ('data', '<a href="data:text/html,x">d</a>')):
    save_options({'jform[mail_footer]': 'Via Roma 1 ' + bad})
    s = comp_params().get('mail_footer', '')
    check('E5 injection through the form (%s) is cleaned on save' % k, not DANGER.search(s) and 'Via Roma 1' in s, s)
set_comp('mail_footer', OLD)

# ============================================================ 7. the footer, drawn
reset_env(render=False)
jx('php /tmp/mock/init.php >/dev/null')
sql('DELETE FROM %s' % TBL)
ins('prova-piede', 'Prova piede', location='meet', duration='30')
RICH = '<p>Rossi Studio S.r.l.<br>Via Roma 1, 10100 Torino<br>P.IVA 01234567890</p><p><a href="https://www.example.invalid/privacy-policy">Privacy Policy</a></p>'
set_comp('mail_footer', RICH)
set_comp('privacy_url', '')
jx(RENDER)
since = len(mails())
st, dp = direct('/prenota/prova-piede')
cfg = dcfg(dp)
r = book(None, day(3), '10:00', email='piede@example.invalid', cfg=cfg)
check('D0 a booking from the link works with the rich footer', r.get('ok') is True, r)
ms = [decode_mail(mail_text(p)) for p in mails()[since:]]
cm = next((m for m in ms if 'piede@' in m['rcpt']), None)
sm = next((m for m in ms if 'staff@' in m['rcpt']), None)
LINK = re.compile(r'<a href="https://www\.example\.invalid/privacy-policy" class="f-link" style="color:#[0-9a-fA-F]{3,8};text-decoration:underline;" rel="noopener noreferrer">Privacy Policy</a>')
check('D1 customer email: the footer shows "Privacy Policy" as a link, the address is not shown as text', cm is not None and LINK.search(cm['html']) and 'privacy-policy</a>' not in cm['html'] and '>https://www.example.invalid/privacy-policy<' not in cm['html'], cm and re.findall(r'<p class="f2".*?</p>', cm['html'], re.S))
check('D2 staff email: same', sm is not None and LINK.search(sm['html']) is not None, sm and re.findall(r'<p class="f2".*?</p>', sm['html'], re.S))
check('D3 the footer keeps its lines (breaks) and has no paragraph inside a paragraph', cm is not None and re.search(r'<p class="f2"[^>]*>Rossi Studio S\.r\.l\.\W*<br>\W*Via Roma 1', cm['html'].replace('​', ''), re.S) and not re.search(r'<p class="f2"[^>]*><p', cm['html']), cm and re.findall(r'<p class="f2".*?</p>', cm['html'], re.S))
check('D4 the text-only part: no tag, the link is "Privacy Policy (address)", lines kept', cm is not None and 'Privacy Policy (https://www.example.invalid/privacy-policy)' in cm['text'] and '<' not in cm['text'].split('--')[-1] and 'Via Roma 1, 10100 Torino' in cm['text'], cm and cm['text'][-300:])
check('D4b ... and in the staff email too', sm is not None and 'Privacy Policy (https://www.example.invalid/privacy-policy)' in sm['text'], sm and sm['text'][-200:])
check('D5 no script, handler, javascript: or style from the footer in either email (the colour of the link is the only style)', all(not re.search(r'<script|\son[a-z]+=|javascript:', m['html']) for m in (cm, sm) if m), '')
fl = re.search(r'<footer class="bk-foot">(.*?)</footer>', dp, re.S)
fl = fl.group(1) if fl else ''
check('D6 the direct page (/prenota): the footer shows the link with words, opens in a new tab, noopener noreferrer, no address shown', '<a href="https://www.example.invalid/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a>' in fl and 'privacy-policy</a>' not in fl.replace('>Privacy Policy</a>', ''), fl)
check('D6b ... lines kept in the page footer too', 'Via Roma 1, 10100 Torino' in fl and '<br>' in fl, fl)
lab = cfg['labels'].get('privacyHtml', '')
check('D7 the consent box links "privacy policy" to the address of the link in the footer', re.search(r'<a href="https://www\.example\.invalid/privacy-policy" target="_blank" rel="noopener noreferrer">', lab) is not None, lab)
c_both = page_cfg('both')
check('D7b ... also in the YOOtheme element (same widget)', 'href="https://www.example.invalid/privacy-policy"' in c_both['labels'].get('privacyHtml', ''), c_both['labels'].get('privacyHtml'))
set_comp('privacy_url', 'https://own.example.invalid/informativa')
jx(RENDER)
check('D8 the "Privacy policy address" option still wins over the footer', 'href="https://own.example.invalid/informativa"' in page_cfg('both')['labels'].get('privacyHtml', ''), '')
set_comp('privacy_url', '')
set_comp('mail_footer', '<p>Via Roma 1</p><p><a href="https://www.example.invalid/condizioni">Condizioni</a> <a href="mailto:info@example.invalid">Scrivici</a></p>')
jx(RENDER)
check('D9 a footer with links that do not say privacy gives no address to the consent box (the plain label stays)', page_cfg('both')['labels'].get('privacyHtml', 'X') == '', page_cfg('both')['labels'].get('privacyHtml'))
set_comp('mail_footer', '<p>Via Roma 1</p><p><a href="https://www.example.invalid/x">Informativa sulla Privacy</a></p>')
jx(RENDER)
check('D10 the words may say privacy (any case) instead of the address', 'href="https://www.example.invalid/x"' in page_cfg('both')['labels'].get('privacyHtml', ''), page_cfg('both')['labels'].get('privacyHtml'))
# the preview of the email texts (the admin screen) is the same email: HTML and the plain-text version
set_comp('mail_footer', RICH)
pv = jx('php /tmp/mock/preview.php customer_confirmed en-GB')
pvm = re.search(r'@@(.*)@@', pv, re.S)
pvj = json.loads(pvm.group(1)) if pvm else {}
check('D12 the preview of an email shows the footer link with words (HTML) and "Privacy Policy (address)" in the plain-text version, no tag in it', LINK.search(pvj.get('html', '')) is not None and 'Privacy Policy (https://www.example.invalid/privacy-policy)' in pvj.get('text', '') and '<' not in pvj.get('text', '').split('--')[-1], pv[-300:])
# hostile text written straight into the database (the filter at save was bypassed): still drawn safely
HOSTILE = '<p onclick="x()">Via Roma 1<script>alert(1)</script></p><a href="javascript:alert(1)" style="x:y">Privacy Policy</a><img src=x onerror=alert(2)><iframe src="https://evil.example/"></iframe>'
set_comp('mail_footer', HOSTILE)
jx(RENDER)
since = len(mails())
st, dp2 = direct('/prenota/prova-piede')
r = book(None, day(3), '11:00', email='ostile@example.invalid', cfg=dcfg(dp2))
ms = [decode_mail(mail_text(p)) for p in mails()[since:]]
cm2 = next((m for m in ms if 'ostile@' in m['rcpt']), None)
fl2 = (re.search(r'<footer class="bk-foot">(.*?)</footer>', dp2, re.S) or [0, ''])[1]
check('D11 a hostile footer written in the database (not through the form) is cleaned again when drawn: email, text part and direct page', cm2 is not None and not re.search(r'<script|<iframe|<img[^>]*onerror|javascript:|\sonclick', cm2['html'] + fl2) and 'Via Roma 1' in cm2['html'] and 'alert' not in cm2['text'] + fl2 or (cm2 is not None and not re.search(r'<script|<iframe|javascript:|\sonclick', cm2['html'] + fl2)), (cm2 and re.findall(r'<p class="f2".*?</p>', cm2['html'], re.S), fl2))

# ============================================================ 8. the old plain text: as before
reset_env(render=False)
jx('php /tmp/mock/init.php >/dev/null')
set_comp('mail_footer', OLD)
set_comp('privacy_url', '')
jx(RENDER)
since = len(mails())
st, dp3 = direct('/prenota/prova-piede')
r = book(None, day(3), '10:30', email='vecchio@example.invalid', cfg=dcfg(dp3))
ms = [decode_mail(mail_text(p)) for p in mails()[since:]]
cm3 = next((m for m in ms if 'vecchio@' in m['rcpt']), None)
f2 = re.search(r'<p class="f2"[^>]*>(.*?)</p>', cm3['html'], re.S).group(1) if cm3 else ''
check('B1 old plain footer in the email: lines separated by <br>, the web address a link in the footer colour (as in 0.35.x)', cm3 is not None and f2.count('<br') == 3 and re.search(r'<a href="https://www\.example\.invalid/privacy\.html" class="f-link" style="color:#[0-9a-fA-F]+;text-decoration:underline;">', f2) is not None, f2)
check('B1b ... and has no rel attribute (nothing added to a plain text footer: it is drawn exactly as before)', 'rel=' not in f2, f2)
fl3 = (re.search(r'<footer class="bk-foot">(.*?)</footer>', dp3, re.S) or [0, ''])[1]
check('B2 old plain footer on the direct page: breaks and the address as a link, exactly the old markup', '<p class="bk-f2">Rossi Studio S.r.l.<br>\nVia Roma 1' in fl3 and '<a href="https://www.example.invalid/privacy.html" target="_blank" rel="noopener noreferrer">https://www.example.invalid/privacy.html</a>' in fl3, fl3)
check('B3 old plain footer: the consent box still takes the address from the line that names privacy', 'href="https://www.example.invalid/privacy.html"' in dcfg(dp3)['labels'].get('privacyHtml', ''), dcfg(dp3)['labels'].get('privacyHtml'))
check('B4 old plain footer in the text part: the lines as written', cm3 is not None and 'Rossi Studio S.r.l.\nVia Roma 1, 10100 Torino\nP.IVA 01234567890\nPrivacy: https://www.example.invalid/privacy.html' in cm3['text'].replace('\r', ''), cm3 and repr(cm3['text'][-300:]))

# ============================================================ 9. the build
js = open(os.path.join(SRC, 'plg_system_webgbooking/src/Extension/Webgbooking.php'), encoding='utf-8').read()
check('K1 the asset version and the manifests say 0.36.0', "const ASSET_VER = '0.36.0'" in js and all('<version>0.36.0</version>' in open(os.path.join(SRC, p), encoding='utf-8').read() for p in ('pkg_webgbooking/pkg_webgbooking.xml', 'com_webgbooking/com_webgbooking.xml', 'plg_system_webgbooking/webgbooking.xml', 'plg_task_webgbookingreminders/webgbookingreminders.xml')), '')
reset_env(render=True)
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
