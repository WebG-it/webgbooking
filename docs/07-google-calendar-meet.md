# WebG Booking — Integrazione Google Calendar + Meet (piano) — 2026-06-23

Obiettivo di Marco: alla prenotazione il **link Google Meet deve essere generato automaticamente** (non fisso). Questo richiede l'autenticazione a Google e la creazione di un evento su Google Calendar con `conferenceData` (Google restituisce il link Meet). È la **modalità A** dei requisiti (sync con calendario reale).

## Come funziona (architettura)
1. **OAuth 2.0** (accesso offline): l'host (Marco / lo staff) collega il proprio account Google una volta. Salviamo il **refresh token cifrato** nel DB.
2. **Alla prenotazione**: con il refresh token otteniamo un access token e chiamiamo **Calendar API `events.insert`** con `conferenceDataVersion=1` + un `conferenceData.createRequest` → Google crea l'evento **e** un **Google Meet**, e ci restituisce il link. Lo salviamo nella prenotazione e lo mettiamo nelle email (invito `.ics` + link Meet).
3. Su modifica/cancellazione: `events.update` / `events.delete` (UID stabile).

## Cosa costruisco io (lato codice)
- **Impostazioni**: Google **Client ID** e **Client Secret** (campi nelle opzioni) + scelta del calendario su cui scrivere.
- **"Collega account Google"** nel backend del componente `com_webgbooking`: bottone → consenso Google → callback che salva il refresh token (cifrato). Stato connessione + "Scollega".
- **Callback OAuth**: gestito via `com_ajax` del plugin (`action=oauth`) o controller del componente.
- **Client Calendar/Meet**: creazione evento con Meet alla conferma della prenotazione; fallback al link fisso `meeting_url` se Google non è collegato.
- **Sicurezza** (doc 04): token cifrati at-rest, `state` OAuth verificato (anti-CSRF), scope minimo.

## Cosa serve da te (UNA volta) — passi precisi
Solo tu puoi crearlo (è il tuo account Google Cloud).

1. **console.cloud.google.com** → in alto crea un **progetto** (es. "WebG Booking").
2. **API e servizi → Libreria** → cerca e **abilita "Google Calendar API"**.
   - ℹ️ **NON serve** abilitare la "Google Meet API": il link Meet lo genera la Calendar API stessa tramite `conferenceData.createRequest` + `conferenceDataVersion=1` (Google crea la conferenza e restituisce `hangoutLink`). La Meet API separata serve solo a gestire spazi/registrazioni Meet standalone, cosa che a noi non serve.
3. **API e servizi → Schermata consenso OAuth**:
   - Tipo utente: **Esterno** → Crea.
   - App name: `WebG Booking`, email di supporto e dello sviluppatore = la tua.
   - **Ambiti (scopes)**: aggiungi `.../auth/calendar.events`.
   - **Utenti di test**: aggiungi la tua email. *(In stato "Testing" non serve la verifica Google: funziona subito per i tuoi account.)*
4. **API e servizi → Credenziali → Crea credenziali → ID client OAuth**:
   - Tipo applicazione: **Applicazione web**.
   - **URI di reindirizzamento autorizzati** → aggiungi (sostituisci il dominio col tuo sito):
     ```
     https://www.TUOSITO.it/index.php?option=com_ajax&group=system&plugin=webgbooking&format=raw&action=oauth
     ```
   - Crea → copia **ID client** e **Client secret**.
5. Mi passi **Client ID + Client Secret** (o li incolli nelle impostazioni quando pronte) e l'URI esatto del sito su cui testiamo, così finalizzo il redirect.

## Note
- **Modello A** (le tue credenziali) = quello che useremo: nessun dato di terzi passa da WebG, e per la vendita futura ogni cliente fa lo stesso (con wizard guidato).
- Per i **siti di Marco** in alternativa si può fare una **app Google unica WebG** (Modello B) e riusarla: dimmi se preferisci, cambia solo chi possiede l'app.
- Finché Google non è collegato, resta valido il campo **"Link meeting"** fisso (Zoom/Meet manuale).

## Prossimo step
Tu crei l'ID client OAuth (passi sopra). Io costruisco: impostazioni Client ID/Secret + "Collega account Google" + callback + creazione evento/Meet alla prenotazione. Poi testiamo insieme il flusso reale.
