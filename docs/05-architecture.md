# WebG Booking — Architettura tecnica (draft v0.1, 2026-06-23)

Ponte tra i requisiti (`02`) e il codice. Tutto pensato **Joomla 6-native** e conforme alle verifiche di `03` (YT Pro 5.0.35) e `04` (sicurezza). Engine unico, skin multiple. Niente offuscamento, stringhe in lingua, GPL (JED).

---

## 1. Pacchetto e struttura
Pacchetto installabile unico (`pkg_webgbooking`) che contiene:
- **`com_webgbooking`** — component MVC (admin + site). È il cuore: dati, engine, endpoint, **e spedisce dentro di sé l'elemento YT** (auto-discovery, no child theme).
- **`plg_system_webgbooking`** — system plugin leggero: registra il type-path dell'elemento nel builder YT, carica gli asset, espone gli eventi.
- **`plg_task_webgbooking`** — task plugin per **`com_scheduler`** (reminder, pulizia slot-lock scaduti, follow-up).
- **(fase 2)** `mod_webgbooking` (modulo Bootstrap standalone) e addon SP Page Builder — stessa engine, skin diversa.

```
com_webgbooking/
  admin/
    services/provider.php            # DI: registra MVCFactory, componenti
    src/Controller, Model, Table, View, Field, Service/
    forms/ , sql/ (install.sql, updates/) , language/en-GB/
  site/
    src/Controller, Model, View(availability,booking,manage)/
    src/Service/CalendarProvider/    # astrazione calendari
    src/Service/Availability.php , Notification/ , Security/
    tmpl/ , language/en-GB/
  media/com_webgbooking/             # JS widget (web component CSS-scoped wgb-*), css
  yootheme/elements/booking/         # element.php + templates + icone (spedito qui)
```

L'engine vero (disponibilità, provider calendario, notifiche) sta in `site/src/Service` ed è **UI-agnostico**: lo richiamano sia l'elemento YT sia gli endpoint `com_ajax` sia (domani) il modulo Bootstrap.

---

## 2. Modello dati (tabelle `#__wgb_*`)
Tipi temporali **in UTC** nel DB; conversione al fuso in presentazione.

| Tabella | Campi chiave | Note sicurezza |
|---|---|---|
| `wgb_services` | id, title, alias, description, durations(JSON), buffer_before/after, price, category_id, color, image, capacity, min_notice, max_window, requires_approval, mode(inherit/sync/rule), state, ordering, params(JSON) | — |
| `wgb_staff` | id, name, photo, bio, email, timezone, work_hours(JSON), days_off(JSON), special_days(JSON), priority, user_id, state, params | — |
| `wgb_service_staff` | service_id, staff_id (PK composta) | mappatura N:N |
| `wgb_staff_calendar` | id, staff_id, provider, **credentials_enc**, calendars(JSON: read_busy[]+write_to), state | **token/secret cifrati at-rest** |
| `wgb_bookings` | id, service_id, staff_id, customer_id, **start_utc, end_utc**, status(pending/confirmed/cancelled/completed/no_show), custom(JSON), price, payment_status, calendar_uid, **manage_token_hash**, language, source, created/modified | indice su (staff_id,start_utc,end_utc); token **hashed**, non in chiaro |
| `wgb_customers` | id, name, email, phone, notes, **gdpr_consent, consent_text, consent_at**, user_id | data minimization |
| `wgb_notifications` | id, type, recipient_role, subject, body_html, body_text, language, timing(JSON), state | template |
| `wgb_notification_log` | id, booking_id, type, recipient, status, error, sent_at | log invii (richiesto) |
| `wgb_custom_fields` | id, service_id, type, label, name, required, options(JSON), ordering | whitelist campi |
| `wgb_slot_locks` | id, staff_id, start_utc, end_utc, lock_token, expires_at | hold temporaneo anti-squatting |

**Anti-double-booking** (no overlap): a `confirm`, **transazione DB** + `SELECT ... FOR UPDATE` sulle prenotazioni dello staff che intersecano `[start,end)`; se ne esiste una → abort con "slot appena occupato". L'unicità "no-overlap" non è esprimibile con un semplice UNIQUE, quindi è garantita dalla transazione + ricontrollo live del free/busy esterno.

---

## 3. Astrazione calendari — `CalendarProvider`
Interfaccia unica, una implementazione per provider (factory che risolve da `provider`).

```php
interface CalendarProvider {
    public function listCalendars(): array;                       // setup staff
    public function getBusy(DateTimeRange $range, array $calIds): array; // SOLO free/busy
    public function createEvent(BookingEvent $e): string;         // ritorna UID stabile
    public function updateEvent(string $uid, BookingEvent $e): void;
    public function deleteEvent(string $uid): void;
}
```
- **GoogleCalendarProvider**: OAuth2; `freebusy.query` per il busy; `events.insert/patch/delete`. Multi-calendario (legge i `read_busy[]`, scrive su `write_to`).
- **CalDavProvider** (Apple iCloud + generico): `free-busy-query REPORT`; `PUT/DELETE` di un `.ics`. **Anti-SSRF (doc 04 §3)**: l'URL CalDAV è validato → schema https, DNS risolto, **range privati/loopback/link-local bloccati**, timeout, no redirect verso IP interni.
- **MsGraphProvider** (Outlook/365): fase successiva, stessa interfaccia (`getSchedule` + `Calendars.ReadWrite`).
- **Storage credenziali**: servizio `Security/Crypto` che cifra/decifra con la chiave segreta del sito; `credentials_enc` mai in chiaro, mai in log, mai in repo (Modello A = secret sul sito del cliente; Modello B = secret WebG fuori dai repo pubblici).

---

## 4. Engine disponibilità
Funzione pura (`Service/Availability::slots()`), come da requisiti §5:
`slot_liberi = work_hours − busy_calendario − bookings − buffer − special_days/chiusure`, filtrati per min_notice, max_window, capienza, granularità durata. Modalità **B** salta il termine `busy_calendario`.
- **Cache free/busy** TTL breve in navigazione; **ricontrollo live** al confirm (verità).
- **Slot-lock**: alla scelta dello slot si crea un `wgb_slot_locks` con scadenza → impedisce squatting; il task plugin pulisce i lock scaduti.
- **Anti-enumerazione**: l'endpoint slot ritorna solo orari liberi, mai il motivo dell'occupazione; rate-limit.

---

## 5. Notifiche / email / .ics
- **NotificationManager**: per ogni evento (created/confirmed/rescheduled/cancelled/reminder/follow-up/no-show) seleziona i template per destinatario+lingua, sostituisce i **placeholder whitelisted ed escaped** (`{customer_name}`…), invia via **Mailer Joomla** (From fisso sul dominio), e logga in `wgb_notification_log`.
- **ICS builder**: `METHOD:REQUEST` su conferma, `METHOD:CANCEL` su cancellazione, **UID stabile** = `calendar_uid` per gli update. Pulsanti add-to-calendar Google/Outlook.
- **Reminder schedulati**: `plg_task_webgbooking` interrogato da `com_scheduler` invia i reminder in scadenza (24h/1h…). Niente cron esterno obbligatorio.

---

## 6. Elemento YOOtheme (verificato su 5.0.35)
`yootheme/elements/booking/element.php` con: `name=>'wgb_booking'`, `title`, `group=>'WebG'`, `element=>true`, `width`, `templates`(render+content), `updates`, e `fieldset` a **tabs Content/Settings/Advanced** riusando `${builder.advanced}` e i placeholder `${builder.*}` per responsive/spaziatura/visibilità (così la parità coi nativi è "gratis", confermato in doc 03). I campi specifici mappano §8 dei requisiti (servizio/staff/modalità, layout inline/popup/slide-in, step attivi, campi da raccogliere, consenso GDPR, esito). `source=>true` dove serve dynamic content.
- **Registrazione**: il `plg_system_webgbooking` chiama `Builder::addTypePath(.../yootheme/elements/*/element.php)` → *(il wiring esatto da una component è la verifica live residua di doc 03; alternativa di fallback documentata: path nel child theme)*.
- **Rendering**: il template emette il **web component CSS-scoped** (`<wgb-booking>`), vestito con classi UIkit. Engine disaccoppiato → domani lo stesso component lo serve il modulo Bootstrap con altra skin.

---

## 7. Endpoint & flusso (sicurezza by design, doc 04)
- **Pubblici** via `com_ajax`/route component, tutti con **token Joomla** sui POST: `getAvailability` (GET, rate-limited), `lockSlot`, `submitBooking` (CAPTCHA server-side + honeypot + whitelist campi + anti-double-booking).
- **Gestione cliente** (cancel/reschedule **senza login**): pagina protetta da **manage_token crypto-random** (no IDOR), monouso/scadenza; la modifica riscrive sul calendario e notifica.
- **Backend**: ACL Joomla per ruolo (admin/manager/staff); lo staff vede solo le proprie prenotazioni/calendari.

---

## 8. Build, versioning, distribuzione
- **Dev** con composer (YOOtheme Starter Plugin per scaffold elemento). **Release** = zip installabile (`pkg_webgbooking` con component + elemento YT dentro).
- **Update server**: riusiamo il pattern **collection** già in piedi (`WebG-it/joomla-extensions`) aggiungendo una riga `<extension>` → aggiornamenti one-click come per cfpurge.
- **JED**: GPL, `_JEXEC` ovunque, stringhe in `.ini` (en-GB shipped), niente offuscamento, naming `{Type} - {Name}`, changelog feed. (Stessa checklist imparata col plugin cache.)

---

## 9. Roadmap di build (proposta)
1. **Scaffold**: `pkg` + `com_webgbooking` skeleton + elemento YT minimo → **validare auto-discovery su install reale** (rischio aperto n°1).
2. **Modalità B (rule-based)** end-to-end: services/staff/work_hours → availability → submit → booking → email + .ics + log. Zero account esterni: ship rapido e GDPR-friendly.
3. **Elemento YT ricco**: tutti i campi §8, varianti UI, responsive.
4. **Modalità A**: `CalendarProvider` Google, poi CalDAV (Apple); sync bidirezionale + cache + slot-lock.
5. **Reminder** (`com_scheduler`), self-service cancel/reschedule.
6. **Hardening + checklist sicurezza** (doc 04) + packaging JED.

> Si parte da 1→2 perché chiude il rischio tecnico (YT discovery) e consegna subito valore (booking funzionante senza calendari).
