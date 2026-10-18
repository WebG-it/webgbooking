# Prove end-to-end (Joomla usa-e-getta in Docker)

Provano il pacchetto vero su un Joomla 5 o 6 pulito, senza toccare nessun sito reale e senza Google, SMTP o YOOtheme veri:

- **Google** e' simulato **dentro il container**: Apache risponde in HTTPS a `www.googleapis.com`, `oauth2.googleapis.com` e `sheets.googleapis.com` con `mock/mock.php` (token, freeBusy, eventi creati/spostati/cancellati, Sheets) e registra ogni richiesta; la CA di prova si aggiunge solo al bundle di quel container.
- **Stripe**: `api.stripe.com` punta allo stesso mock (Checkout Session create/retrieve/expire, `GET /v1/account`, e `/__mock/pay` per simulare il cliente che paga). I webhook li firma il test con un segreto di prova generato a caso a ogni esecuzione; nessuna chiave vera, nemmeno test, viene mai usata.
- **Email**: `mock/smtpsink.php` e' un server SMTP che salva ogni messaggio in `/tmp/mail/N.eml`.
- **Elemento YOOtheme**: `mock/render_el.php` esegue il VERO `template.php` (con un finto builder) per le configurazioni di `mock/configs.json` e scrive pagine statiche in `/wgbtest/` che caricano il VERO `wgb.js`. Il customizer di YOOtheme Pro (a pagamento) non e' nel container: l'interfaccia dei campi dell'elemento va provata a mano.

## Uso
```bash
export WGB_E2E_ADMIN_PASS='una-password-qualsiasi-di-prova'
bash tools/e2e/up.sh 6 8086            # Joomla 6 su http://localhost:8086 (oppure: up.sh 5 8085)
bash tools/e2e/redeploy.sh wgbtest-6-j # costruisce e installa il pacchetto, opzioni di prova, pagine di prova
export WGB_E2E_ADMIN_PASS                # le prove admin la leggono
for t in t_core t_flow t_rem t_lazy t_hooks t_admin t_lang t_lock t_pay t_pay_admin t_fix t_v331; do python tools/e2e/$t.py 8086 wgbtest-6; done
```
Ogni prova stampa `PASS`/`FAIL` e chiude con `RESULT pass=N fail=M`. Alla fine: `docker rm -f wgbtest-6-j wgbtest-6-db; docker network rm wgbtest-6-net`.

| File | Cosa prova |
|---|---|
| `t_core.py` | durata per elemento, buffer, sovrapposizioni fra eventi di durata diversa, luogo (Meet/telefono/scelta), telefono obbligatorio, evento Google senza Meet, email e `.ics`, gara di 8 prenotazioni parallele |
| `t_flow.py` | riprogramma e annulla del cliente, annulla dall'admin (singolo, multiplo, dal dettaglio), messaggi, export |
| `t_rem.py` | promemoria: tempi per elemento, nessun doppione (anche con processi in parallelo), annullate/passate/lontane, invio fallito che si ripete, riprogramma |
| `t_lazy.py` | il Task Scheduler in modalita' lazy (una richiesta web qualsiasi, nessun cron) invia il promemoria |
| `t_hooks.py` | webhook e riga Google Sheet con luogo, durata, evento |
| `t_admin.py` | opzioni del plugin, attivita' pianificata e plugin task nell'admin |
| `t_lang.py` | i promemoria seguono la lingua predefinita del sito anche da CLI |
| `t_pay.py` | pagamenti: sessione e hold, config firmata (prezzo falsificato rifiutato), webhook (firme false, replay, doppioni, evento nuovo stesso pagamento), pagina di ritorno, scadenza, pagamento tardivo con slot libero/preso, importo diverso, politica di annullo |
| `t_pay_admin.py` | opzioni Stripe (cifrate, mai rimandate al form, verifica chiave), lista/filtro/dettaglio/export, annullo e eliminazione con Google e Stripe, promemoria all'ospite |
| `t_fix.py` | le correzioni 0.33.0 punto per punto: test/live, Google fail closed + cache + retry, effetti dopo il pagamento e pagamenti orfani, eventi GA4, disponibilita' per elemento, epoca/scadenza dei token, UID .ics, anti abuso, `pending_ctx`, segreti, importi, header, piccole correzioni |
| `t_v331.py` | gli ultimi cinque punti: effetti rimasti `pending`, evento Google creato ma risposta persa (un solo evento), mail ospite separata, livemode mancante, prefisso chiave sconosciuto |
| `t_lock.py` | il lock per giorno e' vero: chi lo trova occupato aspetta e viene rifiutato |

Ogni suite comincia con `reset_env()` (in `wgbtest.py`): le suite si possono lanciare in qualsiasi ordine e piu' volte sullo stesso container.
