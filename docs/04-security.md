# WebG Booking — Specifica di sicurezza (2026-06-23)

Requisito di Marco: il form è un **veicolo di attacchi informatici**, va prevenuto. Un form di prenotazione ha una superficie più ampia di un contact form: raccoglie **PII** (nome/email/telefono), **invia email**, **scrive su calendari esterni**, gestisce **OAuth/CalDAV**, ed espone **link tokenizzati** di cancel/reschedule. Questo documento è il contratto di sicurezza da rispettare in ogni riga di codice. Principio guida: **mai fidarsi dell'input**, **privilegio minimo**, **difesa in profondità** (Joomla + applicazione + Cloudflare).

## 1. Anti-spam / anti-bot / abuso (il rischio n°1 dei form pubblici)
- **CAPTCHA** pluggabile: Cloudflare **Turnstile** (consigliato, privacy-friendly) / reCAPTCHA v3 / hCaptcha. Validazione **server-side obbligatoria** del token prima di creare qualsiasi booking. Configurabile, ma **on di default**.
- **Honeypot** + **time-trap** (campo nascosto + scarto invii più veloci di N secondi = bot).
- **Rate limiting applicativo**: max prenotazioni per IP e per email in una finestra (es. 5/ora), oltre → 429 + challenge. Conteggio lato server, non solo JS.
- **Rate limiting Cloudflare** sull'endpoint di submit (difesa di bordo, ferma i flood prima di Joomla).
- **Double opt-in opzionale**: la prenotazione diventa `confirmed` solo dopo click sul link email → blocca booking con email altrui/fasulle e lo slot-squatting.
- **Slot-locking a tempo**: lo slot scelto è bloccato per X minuti durante la compilazione, poi rilasciato → impedisce il blocco malevolo di tutta l'agenda.

## 2. CSRF / sessione
- **Token Joomla** (`Session::checkToken` / `Form` token) su **ogni** POST (submit, cancel, reschedule, connessione calendari nel backend). Niente azione di stato senza token valido.
- Endpoint pubblici via `com_ajax`/route dedicata con verifica token; nessuna azione di scrittura su richiesta GET.

## 3. Injection (SQL / XSS / email / template / SSRF)
- **SQL**: solo **Joomla Database API** con query parametrizzate / `quote`/`bind`. Mai concatenazione di input. Whitelist su ORDER BY/colonne dinamiche.
- **XSS**: escape di **ogni** valore in output — frontend, **viste backend** (admin che legge le prenotazioni), **e soprattutto le email** (un `Nome` con HTML/script non deve eseguire nel client dell'admin). Escape contestuale (HTML/attr/JS/URL).
- **Email header injection**: usare **solo il Mailer Joomla** con metodi strutturati (`addRecipient`, `setSubject`, `setBody`); **mai** inserire input utente in header (To/From/Reply-To/Subject) senza strip di `\r\n`. Il `From` è **fisso, sul dominio del sito** (mai l'email del cliente come mittente → SPF/DKIM + anti-spoofing).
- **Template injection nei placeholder**: i placeholder (`{customer_name}` ecc.) sono **sostituzione di stringa con escape**, non `eval`/render di codice. Whitelist dei placeholder ammessi.
- **SSRF (critico, specifico del booking)**: il **CalDAV** fa inserire all'utente un **URL server** che il nostro backend poi contatta → un URL malevolo (`http://169.254.169.254`, `http://localhost:...`, IP privati) può colpire risorse interne. Mitigazione: **validare lo schema (https), risolvere il DNS e bloccare range privati/loopback/link-local**, timeouts, niente redirect verso IP interni, all;owlist opzionale di host. Stesso vaglio per qualunque URL/webhook configurabile.

## 4. Autorizzazione / IDOR / link tokenizzati
- **Cancel/reschedule senza login** = pagina protetta da **token crypto-random** (≥128 bit, `random_bytes`), **non** dall'ID prenotazione → niente IDOR (incrementare l'ID non deve dare accesso ad altre prenotazioni).
- Token **monouso o a scadenza**, legati alla singola prenotazione, revocati dopo l'uso.
- **Backend**: ogni azione verifica i permessi ACL Joomla per ruolo (admin/manager/staff); lo staff vede **solo** le proprie prenotazioni/calendari.
- **Mass-assignment**: il handler di submit accetta **solo una whitelist** di campi mappati sul record; ignora tutto il resto (niente set di `status`, `staff_id`, `price` da input cliente).

## 5. Credenziali calendario / OAuth (dati ad alto valore)
- **Token e secret cifrati at-rest** (chiave dall'app secret di Joomla / config separata, non in chiaro nel DB). Decifrati solo al momento dell'uso.
- **Scope minimo**: free/busy in lettura, scrittura solo sull'evento creato. Nessun accesso ai dettagli degli altri eventi.
- **Refresh token** custoditi come i secret; revoca e rotazione gestite; logout = revoca.
- In **Modello A** (credenziali del cliente) i secret restano sul suo sito; in **Modello B** (app WebG) il secret WebG **non** finisce mai in repo pubblici né in output.
- **State/nonce** OAuth verificati al callback (anti-CSRF del flusso OAuth); redirect URI in allowlist.

## 6. Privacy / GDPR (è anche sicurezza)
- **Consenso esplicito** registrato (timestamp + testo della privacy accettata).
- **Data minimization**: si legge solo free/busy, mai i dettagli degli eventi altrui.
- **Export & delete** dei dati cliente (diritto all'oblio); cancellazione che rimuove anche l'evento dal calendario.
- **Log accessi/azioni** sui dati personali; ritenzione configurabile.

## 7. Disponibilità / DoS / enumerazione
- **Caching free/busy** con TTL breve + rate limit verso le API calendario → un attaccante non può farci esaurire la quota o rallentare il sito martellando gli slot.
- **Anti-enumerazione**: l'endpoint slot espone **solo** orari liberi, **mai** il motivo dell'occupazione (privacy dello staff). Rate-limit anche su questo endpoint.
- Query di disponibilità con **limiti** (range massimo di date interrogabile per richiesta).

## 8. Open redirect / esito configurabile
- L'opzione "redirect URL dopo la prenotazione" deve **validare la destinazione** (stesso host o allowlist) → niente open-redirect sfruttabile in phishing.

## 9. Upload (se i campi custom permettono file — fase 2)
- Whitelist estensioni/MIME, limite dimensione, rinomina server-side, storage **fuori dalla webroot** o con accesso controllato, scansione se disponibile. Di default **niente upload** nell'MVP.

## 10. Hardening operativo / JED
- **Niente debug/error_reporting** in produzione; errori loggati lato server, messaggi generici all'utente.
- **`_JEXEC` in ogni PHP**, **header licenza GPL in ogni file**, **nessuna stringa hardcoded** (tutto in `.ini` lingua), **nessun base64/offuscamento** → requisiti JED **e** buone pratiche di sicurezza (codice ispezionabile).
- Dipendenze (librerie calendario/OAuth) **pinnate e aggiornabili**; preferire i componenti già in Joomla core dove possibile.
- **Cloudflare** come ultimo strato: Rate Limiting + Managed Challenge/WAF sull'endpoint del form per i siti dietro CF (come offerteesconti.it).

## Checklist di accettazione (da spuntare prima di ogni release)
- [ ] CAPTCHA validato server-side + honeypot + time-trap attivi di default
- [ ] Token Joomla su tutti i POST (submit/cancel/reschedule/backend)
- [ ] Query 100% parametrizzate; output 100% escaped (frontend+backend+email)
- [ ] Mailer Joomla, From fisso sul dominio, zero input in header
- [ ] CalDAV/URL: blocco IP privati/loopback/link-local (anti-SSRF)
- [ ] Link cancel/reschedule con token crypto-random monouso/scadenza (no IDOR)
- [ ] Whitelist campi nel submit (no mass-assignment di status/staff/price)
- [ ] Token/secret calendario cifrati at-rest; scope minimo; state OAuth verificato
- [ ] Rate limit applicativo + (se dietro CF) Cloudflare su submit e slot-endpoint
- [ ] Consenso GDPR registrato; export/delete dati; redirect di esito validato
- [ ] `_JEXEC` + GPL header + stringhe in lingua + niente offuscamento (JED + ispezionabilità)
