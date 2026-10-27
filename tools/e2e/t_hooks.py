from wgbtest import *
reset_env()
jx('php /tmp/mock/init.php booking_webhook=https://www.googleapis.com/hook booking_webhook_method=POST >/dev/null')
sql("UPDATE joom_webgbooking_google SET sheet_id='SHEET123', sheet_tab='Prenotazioni'")
r = book('hour', day(13), '10:00', phone='+39 333 555 6666', location='phone', email='hook@example.invalid', name='Hook')
check('booking ok', r.get('ok') is True, r)
lg = gm_log()
wh = [l for l in lg if l['path'] == '/hook']
check('webhook POST has location, duration, event', wh and wh[0]['body'].get('location') == 'phone' and wh[0]['body'].get('duration') == 60 and wh[0]['body'].get('event_name') == 'Riunione - 1 ora', wh)
sh_ = [l for l in lg if 'sheets.googleapis' in l['host'] and l['method'] == 'POST' and l['path'].endswith(':append')]
rows_ = sh_[0]['body']['values'] if sh_ else []
vals = rows_[-1] if rows_ else []
check('Sheet row keeps the first 11 columns and APPENDS location, duration, event (0.36.1: an empty tab gets a header row first, and the booking id closes the row as column O)', len(rows_) == 2 and len(rows_[0]) == 15 and len(vals) == 15 and vals[11:14] == ['phone', '60', 'Riunione - 1 ora'] and vals[14].isdigit() and vals[5].endswith('+39 333 555 6666'), rows_)
jx('php /tmp/mock/init.php >/dev/null')
sql("UPDATE joom_webgbooking_google SET sheet_id='', sheet_tab=''")
print('RESULT pass=%d fail=%d' % (RES['pass'], RES['fail']))
