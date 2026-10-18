from wgbtest import *
clear_state()
jx('php /tmp/mock/init.php booking_webhook=https://www.googleapis.com/hook booking_webhook_method=POST >/dev/null')
sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
r = book('hour', day(13), '10:00', phone='+39 333 555 6666', location='phone', email='hook@example.invalid', name='Hook')
check('booking ok', r.get('ok') is True, r)
lg = gm_log()
wh = [l for l in lg if l['path'] == '/hook']
check('webhook POST has location, duration, event', wh and wh[0]['body'].get('location') == 'phone' and wh[0]['body'].get('duration') == 60 and wh[0]['body'].get('event_name') == 'Riunione - 1 ora', wh)
sh_ = [l for l in lg if 'sheets.googleapis' in l['host']]
vals = sh_[0]['body']['values'][0] if sh_ else []
check('Sheet row keeps the first 11 columns and APPENDS location, duration, event', len(vals) == 14 and vals[11:] == ['phone', '60', 'Riunione - 1 ora'] and vals[5].endswith('+39 333 555 6666'), vals)
jx('php /tmp/mock/init.php >/dev/null')
sql("UPDATE joom_webgbooking_google SET sheet_id='', sheet_tab=''")
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
