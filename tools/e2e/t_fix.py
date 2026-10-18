"""0.33.0 fixes (points 1-13 of the hardening round). Needs WGB_E2E_ADMIN_PASS. Keys are random throwaway strings."""
import sys, os, json, time, base64, re, http.client, urllib.parse
from wgbtest import *
import t_pay_lib as tp
from t_pay_lib import SK, WHSEC, R
from t_admin_lib import make_admin, post_pairs

tp.fresh()
adm, EDIT, save_options, opts = make_admin()
D = 3


def alerts(substr):
    out = []
    for p in mails():
        m = decode_mail(mail_text(p))
        if 'staff@' in m['rcpt'] and substr.lower() in (m['subject'] + ' ' + m['html']).lower():
            out.append(m)
    return out


def clear_mail():
    jx('rm -f /tmp/mail/*.eml')


def get_headers(url, method='GET', body=None):
    u = urllib.parse.urlparse(url)
    c = http.client.HTTPConnection(u.hostname, u.port, timeout=60)
    c.request(method, u.path + ('?' + u.query if u.query else ''), body, {'Content-Type': 'application/x-www-form-urlencoded'} if body else {})
    r = c.getresponse()
    r.read()
    return r.status, {k.lower(): v for k, v in r.getheaders()}


def mock_flag(name, val=None):
    if val is None:
        jx('rm -f /tmp/gmock/%s' % name)
    else:
        jx("printf %%s '%s' > /tmp/gmock/%s; chmod 666 /tmp/gmock/%s" % (val, name, name))


# =========================================================================== 1. test/live
print('--- 1. test/live')
r, b1 = tp.start_paid('paid30', D, '09:00', 'live1@example.invalid')
sid = tp.dbrow(b1)['stripe_session_id']
tp.pay_in_mock(sid)
jx("curl -sk 'https://api.stripe.com/__mock/pay?id=%s&live=1' >/dev/null" % sid)          # the SESSION says livemode=true
clear_mail()
st, out = tp.webhook(tp.event('checkout.session.completed', sid))          # the EVENT says livemode=false (test site): passes the event check
check('F1a session.livemode=true on a test-mode site: webhook does NOT confirm (mismatch) and alerts the staff', out.get('result') == 'mismatch' and tp.dbrow(b1)['status'] == 'awaiting_payment' and alerts('mode') or alerts('modalit'), (out, tp.dbrow(b1)))
ret = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=%s' % (tp.dbrow(b1)['manage_token'], sid)
st, loc, page = tp.noredirect_get(ret)
check('F1b return page with a livemode mismatch does not confirm either', tp.dbrow(b1)['status'] == 'awaiting_payment', tp.dbrow(b1))
# live key + test mode
live_key = 'sk_' + 'live_' + R(24)
tp.set_mock_key(live_key)
tp.init(stripe_sk=live_key, stripe_whsec=WHSEC, stripe_mode='test')
r = book('paid30', day(D), '10:00', email='livekey@example.invalid')
check('F1c a live key with mode "test": paid booking refused, nothing stored, staff alerted', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='livekey@example.invalid'") == '0' and alerts('modalit') or alerts('mode'), r)
tp.fresh()
adm, EDIT, save_options, opts = make_admin()
before = dict(opts())
out = save_options({'jform[params][stripe_sk]': 'sk_' + 'live_' + R(24), 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
o = opts()
check('F1d options save with a live key + mode test is an ERROR: key and mode NOT saved', o['stripe_sk'] == before['stripe_sk'] and o['stripe_mode'] == before['stripe_mode'] and ('NOT saved' in txt or 'NON salvate' in txt), (txt[-250:]))
out = save_options({'jform[params][stripe_mode]': 'live'})
o = opts()
txt = re.sub(r'<[^>]+>', ' ', out)
check('F1e switching to live while the stored key is a test key is refused too (mode stays test)', o['stripe_mode'] == 'test' and ('NOT saved' in txt or 'NON salvate' in txt), (o['stripe_mode'], txt[-200:]))
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
check('F1f admin list shows the TEST MODE banner while Stripe is in test', 'TEST mode' in lst or 'modalita\' TEST' in lst or 'modalità TEST' in lst, '')
lk = 'sk_' + 'live_' + R(24)
tp.set_mock_key(lk)
tp.init(stripe_sk=lk, stripe_whsec=WHSEC, stripe_mode='live')
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
check('F1g no test banner once live', 'TEST mode' not in lst and 'TEST:' not in lst, '')
r, bl = tp.start_paid('paid30', D, '10:30', 'live2@example.invalid')
check('F1h live key + live mode: paid booking opens a payment', r.get('ok') is True and 'redirect' in r, r)
tp.fresh()
adm, EDIT, save_options, opts = make_admin()

# =========================================================================== 2. Google fail closed + retry + cache
print('--- 2. Google')
cfgd = page_cfg('def')
clear_mail(); jx('rm -f /tmp/gmock/log.jsonl; rm -f /tmp/gmock/events.json'); sql("DELETE FROM joom_webgbooking_kv")
mock_flag('google_fail', 'freebusy')
mm = json.loads(Sess().get(AJAX + '&action=month&from=%s&to=%s&ev=%s' % (day(D), day(D + 1), urllib.parse.quote(cfgd['ev'], safe=''))))
check('F2a freeBusy failing: the month offers NO slots and says the calendar is unavailable (fail closed)', mm.get('unavailable') is True and not mm.get('days') and mm.get('message'), mm)
r = book('def', day(D), '10:00', email='gfail@example.invalid')
check('F2b booking refused while the calendar cannot be read; nothing stored', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='gfail@example.invalid'") == '0', r)
for _ in range(4):
    Sess().get(AJAX + '&action=month&from=%s&to=%s&ev=%s' % (day(D), day(D + 1), urllib.parse.quote(cfgd['ev'], safe='')))
check('F2c ONE alert to the staff for 6 requests (throttled), not one per request', len(alerts('Google Calendar')) == 1, len(alerts('Google Calendar')))
mock_flag('google_fail', None)
sql("DELETE FROM joom_webgbooking_kv WHERE k LIKE 'fb:%'")
jx('rm -f /tmp/gmock/log.jsonl')
q = AJAX + '&action=month&from=%s&to=%s&ev=%s' % (day(D), day(D + 1), urllib.parse.quote(cfgd['ev'], safe=''))
Sess().get(q); Sess().get(q); Sess().get(q)
fb = [l for l in gm_log() if l['path'] == '/calendar/v3/freeBusy']
check('F2d freeBusy is cached ~90 s: three identical month calls = ONE Google request', len(fb) == 1, len(fb))
r = book('def', day(D), '11:00', email='gcache@example.invalid')
fb2 = len([l for l in gm_log() if l['path'] == '/calendar/v3/freeBusy'])
Sess().get(q)
fb3 = len([l for l in gm_log() if l['path'] == '/calendar/v3/freeBusy'])
check('F2e booking confirmation reads Google FRESH (not the cache) and a created event purges the cache', r.get('ok') is True and fb2 >= 2 and fb3 == fb2 + 1, (r, fb, fb2, fb3))

# event creation fails after a confirmed booking
clear_mail(); jx('rm -f /tmp/gmock/log.jsonl')
mock_flag('google_fail', 'events')
r = book('def', day(D + 1), '09:00', email='gev@example.invalid', name='Evento Fallito')
gid = last_id()
rw = sql("SELECT fx_state,fx_steps,fx_tries,google_event_id,meeting_url FROM joom_webgbooking_bookings WHERE id=%d" % gid).split('\t')
ms = [decode_mail(mail_text(p)) for p in mails()]
cm = next((m for m in ms if 'gev@' in m['rcpt']), None)
check('F2f Google event creation fails: booking kept, flagged "retry", staff alerted', r.get('ok') is True and rw[0] == 'retry' and ('google' not in json.loads(rw[1])) and alerts('did not complete') or alerts('non completati'), rw)
check('F2g customer is told the link comes shortly (no empty Meet link)', cm is not None and ('send you the video call link shortly' in cm['html'] or 'Ti mando il link' in cm['html']) and 'meet.google' not in cm['html'] and 'href=""' not in cm['html'], cm and cm['html'][-500:])
check('F2h the steps that worked are recorded (customer + staff mail), the others are not duplicated later', json.loads(rw[1]).get('mail_customer') is True and json.loads(rw[1]).get('mail_staff') is True, rw[1])
mock_flag('google_fail', None)
n_mails = len(mails())
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % gid)
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')
rw2 = sql("SELECT fx_state,google_event_id,meeting_url,pending_ctx FROM joom_webgbooking_bookings WHERE id=%d" % gid).split('\t')
new = [decode_mail(mail_text(p)) for p in mails()[n_mails:]]
check('F2i the task retries: Google event + Meet link created, state done, stored config cleared', rw2[0] == 'done' and rw2[1] not in ('', 'NULL') and rw2[2].startswith('https://meet.google.com/') and rw2[3] == 'NULL', rw2)
check('F2j exactly ONE new email: the "here is your link" mail (customer confirmation/staff mails NOT repeated)', len(new) == 1 and 'gev@' in new[0]['rcpt'] and 'meet.google.com' in new[0]['html'], [m['subject'] for m in new])
n2 = len(mails())
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')
check('F2k a further run does nothing', len(mails()) == n2)

# =========================================================================== 3. paid but effects fail / orphans
print('--- 3. effects after payment, orphans')
tp.fresh(); clear_mail()
r, p1 = tp.start_paid('paid30', D + 2, '09:00', 'fx1@example.invalid', name='Smtp Giu')
sid = tp.dbrow(p1)['stripe_session_id']; tp.pay_in_mock(sid)
jx('pkill -f smtpsink.php; sleep 1')
st, out = tp.webhook(tp.event('checkout.session.completed', sid))
rw = sql("SELECT status,payment_status,fx_state,fx_steps FROM joom_webgbooking_bookings WHERE id=%d" % p1).split('\t')
check('F3a SMTP down at payment time: the booking is PAID and confirmed, effects flagged for retry (not mute, not lost)', rw[0] == 'confirmed' and rw[1] == 'paid' and rw[2] == 'retry' and json.loads(rw[3]).get('google') is True and not json.loads(rw[3]).get('mail_customer'), rw)
jx('nohup php /tmp/mock/smtpsink.php > /tmp/smtpsink.log 2>&1 &'); time.sleep(2)
clear_mail()
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % p1)
n_ev = len([e for e in gm_events().values() if e.get('summary', '').startswith('Audit SEO')])
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')
ms = [decode_mail(mail_text(p)) for p in mails()]
check('F3b SMTP back: the retry sends the customer + staff emails once, no second Google event', any('fx1@' in m['rcpt'] for m in ms) and sql("SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d" % p1) == 'done' and len([e for e in gm_events().values() if e.get('summary', '').startswith('Audit SEO')]) == n_ev, ([m['rcpt'] for m in ms], n_ev))
# Google down at payment time: customer mail w/ "link shortly", link mail after retry
r, p2 = tp.start_paid('paid30', D + 2, '10:00', 'fx2@example.invalid', name='Google Giu')
sid2 = tp.dbrow(p2)['stripe_session_id']; tp.pay_in_mock(sid2)
mock_flag('google_fail', 'events'); clear_mail()
tp.webhook(tp.event('checkout.session.completed', sid2))
ms = [decode_mail(mail_text(p)) for p in mails()]
cm = next((m for m in ms if 'fx2@' in m['rcpt']), None)
check('F3c paid + Google down: customer gets the confirmation with "link shortly" and the payment line', cm is not None and ('shortly' in cm['html'] or 'a breve' in cm['html']) and ('29' in cm['html']), cm and cm['html'][-300:])
mock_flag('google_fail', None); clear_mail()
sql("UPDATE joom_webgbooking_bookings SET fx_last=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id=%d" % p2)
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')
ms = [decode_mail(mail_text(p)) for p in mails()]
check('F3d after the retry: link mail sent, state done', any('fx2@' in m['rcpt'] and 'meet.google.com' in m['html'] for m in ms) and sql("SELECT fx_state FROM joom_webgbooking_bookings WHERE id=%d" % p2) == 'done', [m['rcpt'] for m in ms])
# orphans: admin cancels the hold while the customer pays
r, p3 = tp.start_paid('paid30', D + 2, '11:00', 'orph1@example.invalid', name='Orfano Uno')
sid3 = tp.dbrow(p3)['stripe_session_id']
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
tokf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', lst)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(p3))])
tp.pay_in_mock(sid3); clear_mail()
st, out = tp.webhook(tp.event('checkout.session.completed', sid3))
rw = tp.dbrow(p3)
check('F3e hold cancelled by the admin while the customer pays: payment recorded on the row, status stays cancelled, staff alerted to refund', out.get('result') == 'orphan' and rw['status'] == 'cancelled' and rw['payment_status'] == 'paid' and (alerts('annullata dall') or alerts('cancelled by the admin')), (out, rw))
r, p4 = tp.start_paid('paid30', D + 2, '12:00', 'orph2@example.invalid', name='Orfano Due')
sid4 = tp.dbrow(p4)['stripe_session_id']
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.delete'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(p4))])
tp.pay_in_mock(sid4); clear_mail()
st, out = tp.webhook(tp.event('checkout.session.completed', sid4))
check('F3f hold DELETED while paying: no row, the staff is told about the paid session anyway', out.get('result') == 'orphan' and (alerts('without a booking') or alerts('senza prenotazione')), out)
st, out = tp.webhook(tp.event('checkout.session.completed', 'cs_test_mock999999'))
check('F3g a session of another site/product on the same Stripe account stays ignored', out.get('result') == 'ignored', out)
# day lock busy while the webhook arrives
r, p5 = tp.start_paid('paid30', D + 3, '09:00', 'lock@example.invalid', name='Lock')
sid5 = tp.dbrow(p5)['stripe_session_id']; tp.pay_in_mock(sid5)
import hashlib
lname = 'wgb:' + hashlib.md5(('joomla|joom_|' + day(D + 3)).encode()).hexdigest()[:24]
holder = subprocess.Popen(['docker', 'exec', DB, 'mariadb', '-ujoomla', '-pjoomlapw', 'joomla', '-e', "SELECT GET_LOCK('%s',60); SELECT SLEEP(13);" % lname], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
time.sleep(2)
st, out = tp.webhook(tp.event('checkout.session.completed', sid5))
check('F3h day lock busy: webhook answers 503, NOTHING changes (Stripe will redeliver)', st == 503 and tp.dbrow(p5)['status'] == 'awaiting_payment', (st, out))
holder.communicate()
st, out = tp.webhook(tp.event('checkout.session.completed', sid5))
check('F3i redelivery after the lock is free confirms it', out.get('result') == 'confirmed' and tp.dbrow(p5)['status'] == 'confirmed', (st, out))

# =========================================================================== 4. GA4
print('--- 4. GA4 events')
tp.fresh()
cfgga = page_cfg('ga30')
r = book('ga30', day(D + 4), '09:00', email='ga@example.invalid', name='Analytics Pay')
gb = last_id(); gs = tp.dbrow(gb)['stripe_session_id']; tp.pay_in_mock(gs)
ret = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=%s' % (tp.dbrow(gb)['manage_token'], gs)
st, loc, page = tp.noredirect_get(ret)
m = re.search(r'wgb_done=([a-f0-9]{24})', loc)
check('F4a return page redirects to the thank-you page with a random one-time token (no personal data in the URL)', st in (301, 302, 303) and m and 'ga@example' not in loc and 'Analytics' not in loc and 'cs_test' not in loc, (st, loc))
home = Sess().get(BASE + loc if loc.startswith('/') else loc)
check('F4b the thank-you page pushes the booking event with value, currency and transaction id', m and 'meeting_booked' in home and '"value":29' in home.replace(' ', '') and '"currency":"EUR"' in home and gs in home and 'dataLayer' in home, home[home.find('dataLayer') - 80:home.find('dataLayer') + 400] if 'dataLayer' in home else 'no script')
home2 = Sess().get(BASE + loc if loc.startswith('/') else loc)
check('F4c reloading the page does NOT fire it again (single-use token)', 'meeting_booked' not in home2, '')
st, loc2, page2 = tp.noredirect_get(ret)
check('F4d returning to the payment page again redirects without a new token (fires once per booking)', 'wgb_done' not in loc2, loc2)
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test', gtm_id='GTM-TEST123')
r = book('paid60', day(D + 4), '11:00', email='ga2@example.invalid', phone='0612345678', location='meet')
gb2 = last_id(); gs2 = tp.dbrow(gb2)['stripe_session_id']; tp.pay_in_mock(gs2)
ret2 = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=%s' % (tp.dbrow(gb2)['manage_token'], gs2)
st, loc3, page3 = tp.noredirect_get(ret2)
check('F4e element WITHOUT thank-you page: the return page itself loads the GTM container and pushes the event with value/currency', st == 200 and 'GTM-TEST123' in page3 and 'wgb_booking_completed' in page3 and '"value":49' in page3.replace(' ', ''), page3[-500:])
check('F4f the same event name is configurable per element (analytics_event default)', 'wgb_booking_completed' in page3)
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test')

# =========================================================================== 5. availability per element
print('--- 5. availability per element')
cfga = page_cfg('avail')
days = month(cfga['ev'], day(0), day(30))
import datetime
wd = {datetime.date.fromisoformat(d).weekday() for d in days}          # Mon=0
check('F5a only Tuesday and Thursday are offered', days and wd <= {1, 3}, sorted(wd))
d0 = sorted(days)[0]
check('F5b slots 15:00-18:30 (30 min inside 15:00-19:00)', days[d0][0] == '15:00' and days[d0][-1] == '18:30', days[d0])
check('F5c element notice 48 h: nothing in the next 2 days; window 14 days: nothing after', all((datetime.date.fromisoformat(d) - datetime.date.today()).days >= 2 for d in days) and all((datetime.date.fromisoformat(d) - datetime.date.today()).days <= 14 for d in days), sorted(days)[:3])
mon = next(day(i) for i in range(5, 20) if datetime.date.fromisoformat(day(i)).weekday() == 0)
r = book('avail', mon, '16:00', email='av1@example.invalid')
check('F5d server refuses a Monday for that element even with its valid token', r.get('ok') is False, r)
tue = sorted(days)[0]
r = book('avail', tue, '16:00', email='av2@example.invalid')
check('F5e a valid slot books', r.get('ok') is True, r)
av = last_id()
rw = sql("SELECT avail_cfg FROM joom_webgbooking_bookings WHERE id=%d" % av)
check('F5f the overrides are stored with the booking', '"ws":"15:00"' in rw.replace(' ', '') and '"wd":[2,4]' in rw.replace(' ', ''), rw)
tk = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % av)
x = json.loads(Sess().post(AJAX + '&action=reschedule', {'action': 'reschedule', 'token': tk, 'date': mon, 'time': '16:00'}))
check('F5g rescheduling to a Monday is refused (the booking keeps its element rules)', x.get('ok') is False, x)
dfl = month(page_cfg('def')['ev'], day(0), day(30))
check('F5h other elements keep the global hours', any(datetime.date.fromisoformat(d).weekday() in (0, 4) for d in dfl), sorted(dfl)[:3])

# =========================================================================== 6. epoch + lifetime
print('--- 6. config epoch / lifetime')
tp.fresh(); adm, EDIT, save_options, opts = make_admin()
cfg_old = page_cfg('paid30')
r0 = book('paid30', day(D + 5), '09:00', email='ep0@example.invalid', cfg=cfg_old)
check('F6a before the bump the page books', r0.get('ok') is True, r0)
out = save_options({'jform[params][config_bump]': '1'})
check('F6b "Invalidate cached configurations" raises the epoch (1 -> 2) and the switch resets', opts().get('config_epoch') == 2 and opts().get('config_bump') == '0' or str(opts().get('config_epoch')) == '2', {k: opts().get(k) for k in ('config_epoch', 'config_bump')})
r1 = book('paid30', day(D + 5), '10:00', email='ep1@example.invalid', cfg=cfg_old)
check('F6c a page cached BEFORE the bump (old price/free config) can no longer book; the message says reload', r1.get('ok') is False and ('Ricarica' in r1.get('message', '') or 'Reload' in r1.get('message', '')), r1)
jx('php /tmp/mock/render_el.php /tmp/mock/configs.json >/dev/null; chmod -R a+rX /var/www/html/wgbtest')
cfg_new = page_cfg('paid30')
r2 = book('paid30', day(D + 5), '10:00', email='ep2@example.invalid', cfg=cfg_new)
check('F6d the regenerated page books with the new epoch', r2.get('ok') is True, r2)
payload = json.loads(base64.b64decode(cfg_new['form'].split('.')[0]))
payload['iat'] = int(time.time()) - 40 * 86400
tok = jx("php /tmp/mock/signform.php '%s'" % json.dumps(payload, separators=(',', ':'))).strip()
r3 = book('paid30', day(D + 5), '11:00', email='ep3@example.invalid', cfg={**cfg_new, 'form': tok})
check('F6e a configuration older than the lifetime (30 days) is refused', r3.get('ok') is False, r3)
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test', config_ttl_days='0', config_epoch=str(opts().get('config_epoch', 2)))
r4 = book('paid30', day(D + 5), '11:00', email='ep3@example.invalid', cfg={**cfg_new, 'form': tok})
check('F6f lifetime 0 = no limit: the same old token is accepted', r4.get('ok') is True, r4)
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test')            # epoch back to 1: pages must be re-rendered with it
jx('php /tmp/mock/render_el.php /tmp/mock/configs.json >/dev/null; chmod -R a+rX /var/www/html/wgbtest')

# =========================================================================== 7. ics
print('--- 7. ics')
tp.fresh(); clear_mail()
r = book('hour', day(D + 6), '10:00', phone='+39 333 777 8899', location='phone', email='ics1@example.invalid', name='Ics Cliente', guest='icsg@example.invalid')
ib = last_id(); itok = sql("SELECT manage_token FROM joom_webgbooking_bookings WHERE id=%d" % ib)
ms = [decode_mail(mail_text(p)) for p in mails()]
cm = next(m for m in ms if 'ics1@' in m['rcpt'])
gm = next(m for m in ms if 'icsg@' in m['rcpt'])
uid = re.search(r'UID:(.*)', cm['ics']).group(1).strip()
check('F7a customer .ics UID is an HMAC of the id, not the manage token', itok not in cm['ics'] and re.fullmatch(r'wgb-%d-[a-f0-9]{20}@.+' % ib, uid), uid)
check('F7b guest .ics has the same UID but NO customer phone', itok not in gm['ics'] and '777 8899' not in gm['ics'] and ('Phone appointment' in gm['ics'] or 'telefonico' in gm['ics']) and uid in gm['ics'], gm['ics'])
check('F7c customer .ics still has the number ("I call you on ...")', '777 8899' in cm['ics'], cm['ics'])
sql("INSERT INTO joom_webgbooking_bookings (created,booking_date,booking_time,customer_name,customer_email,status,manage_token) VALUES (NOW(),'%s','10:00','Legacy','legacy@example.invalid','confirmed','legacytoken000000000000000000zz')" % day(D + 7))
lid = last_id(); clear_mail()
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
tokf = re.findall(r'<input type="hidden" name="([0-9a-f]{32})" value="1"', lst)[0]
post_pairs(adm, BASE + '/administrator/index.php?option=com_webgbooking&view=bookings', [('task', 'booking.cancel'), ('boxchecked', '1'), ('option', 'com_webgbooking'), (tokf, '1'), ('cid[]', str(lid))])
ms = [decode_mail(mail_text(p)) for p in mails()]
lm = next((m for m in ms if 'legacy@' in m['rcpt']), None)
check('F7d a booking made BEFORE 0.33.0 keeps its old UID so the calendar entry still matches its cancellation', lm is not None and 'UID:wgb-%d-legacytoken' % lid in lm['ics'], lm and lm['ics'])

# =========================================================================== 8. abuse
print('--- 8. anti-abuse')
tp.fresh(); clear_mail()
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test', limit_ip_hour='3')
res = [book('def', day(D + 8), '%02d:00' % (9 + i), email='ip%d@example.invalid' % i).get('ok') for i in range(5)]
check('F8a per-IP limit: the 4th and 5th booking from the same address in an hour are refused', res == [True, True, True, False, False], res)
check('F8b the IP is not stored: only a hash', sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE ip_hash IS NOT NULL AND ip_hash NOT LIKE '%.%' AND LENGTH(ip_hash)=24") != '0' and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE ip_hash LIKE '172.%' OR ip_hash LIKE '127.%'") == '0')
tp.fresh()
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test', max_holds_ip='1')
a1, _ = tp.start_paid('paid30', D + 9, '09:00', 'h1@example.invalid')
a2, _ = tp.start_paid('paid30', D + 9, '10:00', 'h2@example.invalid')
check('F8c per-IP open holds: the second concurrent paid hold is refused', a1.get('ok') is True and a2.get('ok') is False, (a1, a2))
tp.fresh(); clear_mail()
tp.init(stripe_sk=SK, stripe_whsec=WHSEC, stripe_mode='test', max_open_holds='2')
g = [tp.start_paid('paid30', D + 10, '%02d:00' % (9 + i), 'g%d@example.invalid' % i)[0].get('ok') for i in range(3)]
check('F8d global cap of open holds: the third is refused and the staff is alerted', g == [True, True, False] and (alerts('cap') or alerts('tetto')), g)
tp.fresh()
for i in range(3):
    rr, bid = tp.start_paid('paid30', D + 11, '%02d:00' % (9 + i), 'same@example.invalid')
    ret = BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=paycancel&token=' + tp.dbrow(bid)['manage_token']
    tp.noredirect_get(ret)
r = book('def', day(D + 11), '14:00', email='same@example.invalid')
check('F8e three ABANDONED paid holds by one email do not lock that email out (expired rows are not counted)', r.get('ok') is True, r)
# reminders only for confirmed bookings
tp.fresh(); clear_mail()
def rome(plus):
    return jx("php -r 'echo (new DateTime(\"now\", new DateTimeZone(\"Europe/Rome\")))->modify(\"%+d minutes\")->format(\"Y-m-d H:i\");'" % plus).strip().split(' ')
ids = {}
for k, t in (('await', '09:00'), ('exp', '10:00'), ('rev', '11:00'), ('ok', '12:00')):
    rr, ids[k] = tp.start_paid('paid30', D + 12, t, '%s@example.invalid' % k)
d, t = rome(50)
for k in ids:
    sql("UPDATE joom_webgbooking_bookings SET booking_date='%s', booking_time='%s', reminders_sent='[1440]' WHERE id=%d" % (d, t, ids[k]))
sql("UPDATE joom_webgbooking_bookings SET status='expired' WHERE id=%d" % ids['exp'])
sql("UPDATE joom_webgbooking_bookings SET status='needs_review', payment_status='paid' WHERE id=%d" % ids['rev'])
sql("UPDATE joom_webgbooking_bookings SET status='confirmed', payment_status='paid' WHERE id=%d" % ids['ok'])
clear_mail(); jx('php /tmp/mock/runrem.php >/dev/null')
rc = ' '.join(decode_mail(mail_text(p))['rcpt'] for p in mails())
check('F8f reminders go ONLY to confirmed bookings (awaiting_payment, expired, needs_review get none)', 'ok@' in rc and 'await@' not in rc and 'exp@' not in rc and 'rev@' not in rc, rc)

# =========================================================================== 9. pending_ctx
print('--- 9. pending_ctx')
tp.fresh()
rr, c1 = tp.start_paid('paid30', D + 13, '09:00', 'ctx1@example.invalid')
check('F9a an open hold keeps its stored config (needed after payment)', sql("SELECT pending_ctx IS NOT NULL FROM joom_webgbooking_bookings WHERE id=%d" % c1) == '1')
s1 = tp.dbrow(c1)['stripe_session_id']; tp.pay_in_mock(s1); tp.webhook(tp.event('checkout.session.completed', s1))
check('F9b after payment and completed effects the stored config is NULL', sql("SELECT pending_ctx IS NULL FROM joom_webgbooking_bookings WHERE id=%d" % c1) == '1')
rr, c2 = tp.start_paid('paid30', D + 13, '10:00', 'ctx2@example.invalid')
tp.noredirect_get(BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=paycancel&token=' + tp.dbrow(c2)['manage_token'])
check('F9c after the hold dies (cancel page) it is NULL', sql("SELECT pending_ctx IS NULL FROM joom_webgbooking_bookings WHERE id=%d" % c2) == '1')
rr, c3 = tp.start_paid('paid30', D + 13, '11:00', 'ctx3@example.invalid')
sql("UPDATE joom_webgbooking_bookings SET hold_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=%d" % c3)
jx('cd /var/www/html && php cli/joomla.php scheduler:run --id=4 >/dev/null 2>&1')
check('F9d after the scheduled expiry it is NULL', sql("SELECT pending_ctx IS NULL FROM joom_webgbooking_bookings WHERE id=%d" % c3) == '1' and tp.dbrow(c3)['status'] == 'expired')
rr, c4 = tp.start_paid('def', D + 13, '12:00', 'ctx4@example.invalid')
check('F9e a free booking whose effects are done has no stored config either', sql("SELECT pending_ctx IS NULL AND fx_state='done' FROM joom_webgbooking_bookings WHERE id=%d" % c4) == '1')

# =========================================================================== 10. secrets
print('--- 10. secrets')
tp.fresh()
p = json.loads(sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system'"))
p['stripe_sk'] = SK                                   # found IN CLEAR (set by hand / imported)
sql("UPDATE joom_extensions SET params='%s' WHERE element='webgbooking' AND folder='system'" % json.dumps(p).replace("'", "''"))
r, k1 = tp.start_paid('paid30', D + 14, '09:00', 'clear@example.invalid')
p2 = json.loads(sql("SELECT params FROM joom_extensions WHERE element='webgbooking' AND folder='system'"))
check('F10a a secret found in clear works AND is encrypted on first read', r.get('ok') is True and p2['stripe_sk'].startswith('g:') and SK not in json.dumps(p2), (r, p2['stripe_sk'][:4]))
p2['stripe_sk'] = 'g:' + base64.b64encode(b'x' * 60).decode()          # undecryptable (rotated Joomla secret)
sql("UPDATE joom_extensions SET params='%s' WHERE element='webgbooking' AND folder='system'" % json.dumps(p2).replace("'", "''"))
jx('rm -f /var/www/html/administrator/logs/webgbooking.php')
r = book('paid30', day(D + 14), '10:00', email='broken@example.invalid')
log = jx('cat /var/www/html/administrator/logs/webgbooking.php 2>/dev/null')
check('F10b undecryptable secret: paid booking refused, a log line WITHOUT values names the option', r.get('ok') is False and 'cannot be decrypted' in log and 'stripe_sk' in log and SK not in log and 'g:' not in log, log[-300:])
lst = adm.get(BASE + '/administrator/index.php?option=com_webgbooking&view=bookings')
check('F10c admin list warns that the key cannot be decrypted (different from "not configured")', 'CANNOT be decrypted' in lst or 'NON si riesce a decifrarla' in lst, '')
tp.fresh(); adm, EDIT, save_options, opts = make_admin()
mock_flag('stripe_restricted', '1')
rk = 'rk_' + 'test_' + R(24)
tp.set_mock_key(rk)
out = save_options({'jform[params][stripe_sk]': rk, 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
check('F10d restricted key (account read forbidden): saved and verified through a Checkout Sessions read', opts()['stripe_sk'].startswith('g:') and ('restricted key verified' in txt or 'Chiave limitata Stripe verificata' in txt), txt[-250:])
mock_flag('stripe_nolist', '1')
rk2 = 'rk_' + 'test_' + R(24); tp.set_mock_key(rk2)
out = save_options({'jform[params][stripe_sk]': rk2, 'jform[params][stripe_mode]': 'test'})
txt = re.sub(r'<[^>]+>', ' ', out)
check('F10e restricted key that cannot be verified at all: still SAVED, reported as "not verifiable" (not blocked)', opts()['stripe_sk'].startswith('g:') and ('does not let this restricted key be verified' in txt or 'non permette' in txt), txt[-250:])
r, k2 = tp.start_paid('paid30', D + 14, '11:00', 'rkpay@example.invalid')
check('F10f the restricted key creates a Checkout Session', r.get('ok') is True, r)
mock_flag('stripe_restricted'); mock_flag('stripe_nolist')

# =========================================================================== 11. amounts / currencies
print('--- 11. amounts')
cases = ['49', '49,00', '49.00', '49,5', '1.234,50', '1,234.50', '1.234.567', '0', '', '  29 ', '€ 49,00', '49.999', '1,234', '4,9,9', '49abc', '1.234,5,6', '1000000', '49,999', '-5', '1.2.3']
import tempfile
_f = tempfile.NamedTemporaryFile('w', suffix='.json', delete=False, encoding='utf-8'); json.dump(cases, _f); _f.close()
sh(['docker', 'cp', _f.name.replace(chr(92), '/'), J + ':/tmp/amounts_in.json'])
os.unlink(_f.name)
out = json.loads(jx('php /tmp/mock/amounts.php /tmp/amounts_in.json').strip().splitlines()[-1])
exp = {'1.234': None, '49': 4900, '49,00': 4900, '49.00': 4900, '49,5': 4950, '1.234,50': 123450, '1,234.50': 123450, '1.234.567': None, '0': 0, '': 0, '  29 ': 2900, '€ 49,00': 4900, '49.999': None, '1,234': None, '4,9,9': None, '49abc': None, '1.234,5,6': None, '1000000': None, '49,999': None, '-5': None, '1.2.3': None}
bad = {k: (out.get(k), v) for k, v in exp.items() if out.get(k) != v}
check('F11a strict amount parser: exact cents for clear inputs, refusal (not guessing) for ambiguous/malformed ones', not bad, bad)
cb = page_cfg('badprice')
check('F11b an element whose price is "49.999" is flagged invalid in the widget', cb.get('priceInvalid') is True and cb.get('price') is None, {k: cb.get(k) for k in ('priceInvalid', 'price')})
r = book('badprice', day(D + 15), '09:00', email='bp@example.invalid')
check('F11c ...and refuses bookings (it does NOT turn into a free event)', r.get('ok') is False and sql("SELECT COUNT(*) FROM joom_webgbooking_bookings WHERE customer_email='bp@example.invalid'") == '0', r)
cj = page_cfg('badcur')
r = book('badcur', day(D + 15), '09:00', email='jpy@example.invalid')
check('F11d an unsupported currency (JPY) is invalid too', cj.get('priceInvalid') is True and r.get('ok') is False, (cj.get('priceInvalid'), r))
cc = page_cfg('chf')
r = book('chf', day(D + 15), '10:00', email='chf@example.invalid')
cbk = last_id()
check('F11e "1.234,50" in "chf" (lowercase) is a valid CHF 1234.50 price', cc.get('priceInvalid') is False and r.get('ok') is True and tp.dbrow(cbk)['amount_cents'] == '123450' and tp.dbrow(cbk)['currency'] == 'CHF', (r, tp.dbrow(cbk)))
cs = tp.sessions()[tp.dbrow(cbk)['stripe_session_id']]
check('F11f Stripe got amount 123450 and currency chf', cs['amount_total'] == 123450 and cs['currency'] == 'chf', cs)

# =========================================================================== 12. headers
print('--- 12. headers')
tp.fresh()
r, h1 = tp.start_paid('paid30', D + 16, '09:00', 'hd@example.invalid')
hr = tp.dbrow(h1)
urls = {
 'manage page': BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' + hr['manage_token'],
 'payreturn': BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=payreturn&token=%s&session_id=%s' % (hr['manage_token'], hr['stripe_session_id']),
 'paycancel': BASE + '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=paycancel&token=' + hr['manage_token'],
 'json token': AJAX + '&action=token',
 'json month': AJAX + '&action=month&from=%s&to=%s' % (day(D), day(D + 1)),
}
for name, u in urls.items():
    st, hd = get_headers(u)
    ok = 'no-store' in hd.get('cache-control', '') and hd.get('referrer-policy') == 'no-referrer' and 'noindex' in hd.get('x-robots-tag', '')
    check('F12 %s: Cache-Control no-store, Referrer-Policy no-referrer, X-Robots-Tag noindex' % name, ok, (st, {k: hd.get(k) for k in ('cache-control', 'referrer-policy', 'x-robots-tag')}))
mp = Sess().get(urls['paycancel'])
check('F12b the HTML pages carry meta robots noindex + referrer no-referrer', 'noindex' in mp and 'no-referrer' in mp, '')

# =========================================================================== 13. small things
print('--- 13. small fixes')
tp.fresh(); clear_mail(); jx('rm -f /tmp/gmock/log.jsonl')
jx('php /tmp/mock/init.php stripe_sk=%s stripe_whsec=%s stripe_mode=test booking_webhook=https://www.googleapis.com/hook >/dev/null' % (SK, WHSEC))
r = book('def', day(D + 17), '09:00', email='wh@example.invalid', name='Webhook Key')
wh = [l for l in gm_log() if l['path'] == '/hook']
check('F13a booking webhook payload: "event" is booking.created (no duplicate key overriding it), event name in "event_name"', wh and wh[0]['body'].get('event') == 'booking.created' and 'event_name' in wh[0]['body'], wh and wh[0]['body'])
ce = page_cfg('evil')
conf = json.loads(base64.b64decode(ce['form'].split('.')[0]))
check('F13b redirect "/\\evil.example" is dropped; element webhook URL with ftp:// is dropped', ce.get('redirectUrl') == '' and conf['webhook']['url'] == '', (ce.get('redirectUrl'), conf['webhook']))
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'src', 'plg_system_webgbooking', 'src', 'Extension', 'Webgbooking.php'), encoding='utf-8').read()
check('F13c the OAuth callback no longer puts exception text in the redirect URL', "wgberror=' . rawurlencode('exchange: '" not in src and 'wgberror=exchange' in src)
vers = sql("SELECT element,JSON_UNQUOTE(JSON_EXTRACT(manifest_cache,'$.version')) FROM joom_extensions WHERE element IN ('com_webgbooking','webgbooking','webgbookingreminders','pkg_webgbooking')").split('\n')
check('F13d package and all children carry the same version', len({v.split('\t')[1] for v in vers}) == 1, vers)
# ALTER failures are reported (revoke ALTER, drop a column, reinstall)
sh(['docker', 'exec', DB, 'mariadb', '-uroot', '-prootpw', 'joomla', '-e', "ALTER TABLE joom_webgbooking_bookings DROP COLUMN ip_hash; REVOKE ALTER ON joomla.* FROM 'joomla'@'%'; FLUSH PRIVILEGES;"])
jx('rm -f /var/www/html/administrator/logs/webgbooking.php')
out = jx('cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/new.zip 2>&1 | tail -6')
log = jx('cat /var/www/html/administrator/logs/webgbooking.php 2>/dev/null')
check('F13e a failing ALTER during install is logged and reported (not silent)', 'database update problems' in log and 'ip_hash' in log, (log[-300:], out[-300:]))
sh(['docker', 'exec', DB, 'mariadb', '-uroot', '-prootpw', 'joomla', '-e', "GRANT ALL PRIVILEGES ON joomla.* TO 'joomla'@'%'; FLUSH PRIVILEGES;"])
jx('cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/new.zip >/dev/null 2>&1')
check('F13f after the rights are back, reinstalling repairs the table', sql("SHOW COLUMNS FROM joom_webgbooking_bookings LIKE 'ip_hash'") != '')

print('\nRESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
