# WebG Booking — Requisiti & Specifica funzionale (draft v0.1, 2026-06-23)

Documento di lavoro PRE-codice. Obiettivo: essere esaustivi sui requisiti prima di scrivere. Si itera con Marco. Tre pilastri richiesti esplicitamente: (1) sync calendario **bidirezionale** su qualunque calendario, (2) elemento YOOtheme **ricco di opzioni come un elemento nativo**, (3) **email automatiche** a staff (1+) e a chi prenota.

---

## 1. Principi di prodotto
- Engine unico, due modalità di disponibilità: **A) calendar-sync** (bidirezionale), **B) rule-based** (senza calendari). La modalità può essere globale o per tipo-appuntamento.
- Privacy-first: per il controllo conflitti leggiamo SOLO **libero/occupato** (free/busy), mai i dettagli degli eventi. Per scrivere la prenotazione serve permesso di scrittura.
- Multi-provider via **layer di astrazione** `CalendarProvider`: Google, Microsoft (Outlook/365), CalDAV (Apple iCloud + generico). Stessa interfaccia: `getBusy(range)`, `createEvent()`, `updateEvent()`, `deleteEvent()`.
- Anti-double-booking garantito a livello DB + ricontrollo live al momento della conferma.

---

## 2. Sincronizzazione calendario bidirezionale (cuore del prodotto)

### 2.1 Cosa significa bidirezionale, in pratica
1. **Lettura (calendario → Joomla)**: prima di mostrare gli slot, interroghiamo il free/busy del calendario dello staff. Se c'è già un evento in quello slot, **non è prenotabile**.
2. **Scrittura (Joomla → calendario)**: alla prenotazione, creiamo l'evento sul calendario dello staff (con cliente, servizio, note, eventuale link meeting). Su modifica/cancellazione, aggiorniamo/eliminiamo lo stesso evento (UID stabile).

### 2.2 Chi collega il calendario
È lo **staff** (la persona prenotabile) a collegare il proprio calendario. I token/credenziali sono **per-staff**, salvati cifrati nel DB Joomla. Un servizio può avere più staff, ciascuno col proprio calendario.

### 2.3 Provider e autenticazione (verificato 2026-06)
| Provider | Protocollo | Auth | Free/busy | Scrittura |
|---|---|---|---|---|
| **Google Calendar** | Google Calendar API | OAuth 2.0 (Google Cloud app) | `freebusy.query` | scope `calendar.events` (**sensibile** → verifica Google) |
| **Outlook / Microsoft 365** | Microsoft Graph | OAuth 2.0 (Azure app registration) | `getSchedule` | `Calendars.ReadWrite` |
| **Apple iCloud** | **CalDAV** (no OAuth) | **password specifica per app** | `free-busy-query` REPORT | PUT evento .ics via CalDAV |
| **Generico CalDAV** | CalDAV | URL + user + password | REPORT | PUT |

**Nota Apple**: iCloud non usa OAuth, serve una *app-specific password* generata dall'utente su appleid.apple.com, incollata nel backend. Stesso meccanismo per qualunque server CalDAV.

### 2.4 L'app Google (e Microsoft): chi la possiede — DECISIONE CHIAVE
Lo scope `calendar.events` di Google è **sensibile**: un'app pubblica che lo usa deve passare la **verifica Google** (consent screen brandizzato, privacy policy sullo stesso dominio dell'app, principio del minimo privilegio). Non è lo scope "restricted" (niente security assessment costoso tipo Gmail/Drive), ma la verifica c'è. Due modelli possibili:

- **Modello A — l'utente porta la sua app (consigliato per il prodotto venduto)**: ogni proprietario del sito crea il proprio progetto Google Cloud + OAuth client (e Azure app per Outlook), e incolla Client ID/Secret nel backend. Vantaggi: WebG non diventa responsabile del trattamento dei dati di tutti, niente verifica centralizzata sui dati altrui, niente quota condivisa. È il modello di Amelia & co. Costo: setup un po' più tecnico per il cliente → lo mitighiamo con **wizard guidato + documentazione passo-passo**.
- **Modello B — app centrale WebG (comodo per i SOLI siti di Marco)**: un unico progetto Google/Azure di WebG, riusato sui suoi siti, verificato una volta. Pratico in agenzia, NON adatto alla vendita di massa.

Design: **supportare entrambi**. Campo "usa credenziali proprie" vs "modalità agenzia". Per il lancio sui siti di Marco si parte col Modello B; per la vendita si documenta il Modello A.

### 2.5 Strategia di sync (performance + correttezza)
- **Sempre** ricontrollo live del free/busy al momento della conferma (verità in tempo reale).
- **Cache** del free/busy con TTL breve per la fase di navigazione slot (evita rate-limit).
- Opzionale: **push/watch** (Google watch channels, Graph subscriptions) o polling CalDAV per invalidare la cache quando il calendario cambia.
- **Anti-race**: al confirm, transazione DB + vincolo di non-sovrapposizione su (staff, start, end). Se lo slot è stato preso nel frattempo → messaggio "slot appena occupato, scegline un altro".

### 2.6 Fusi orari
Slot calcolati nel fuso dello staff/business, mostrati nel fuso del **cliente** (auto-detect browser, override possibile), come Calendly. L'evento sul calendario porta il fuso corretto.

---

## 3. Modello dati (entità principali)
- **Service / Booking Type**: nome, descrizione, durata (una o più), buffer pre/post, prezzo (fase Pro), categoria, colore, immagine, capienza (1:1 o gruppo), preavviso min, finestra max, approvazione manuale on/off.
- **Staff / Resource**: nome, foto, bio, email, servizi assegnati, orari di lavoro settimanali, giorni di chiusura/ferie, giorni speciali, fuso, **connessione calendario** (provider + token cifrati), priorità per auto-assegnazione.
- **Location** (fase 2): sede fisica/virtuale, indirizzo, link meeting.
- **Booking**: servizio, staff, cliente, start/end, stato (pending/confirmed/cancelled/completed/no-show), campi custom, prezzo/pagamento (Pro), UID evento calendario, token cancel/reschedule, lingua, sorgente.
- **Customer**: nome, email, telefono, note, storico, consenso GDPR, eventuale link a utente Joomla.
- **Notification template**: tipo evento, destinatario, oggetto, corpo HTML/testo, lingua, timing (per i reminder).
- **Custom field**: definizione campi extra del form (tipo, label, required, opzioni).

---

## 4. Flusso di prenotazione (frontend)
Step configurabili (ordine e presenza attivabili):
1. (opz.) Scelta **servizio** / tipo-appuntamento.
2. (opz.) Scelta **staff** (oppure "chiunque" → auto-assegnazione).
3. **Data & ora**: calendario mese + slot liberi del giorno (dal free/busy + regole).
4. **Dati cliente**: nome, email, telefono, note + campi custom + consenso GDPR.
5. (Pro) **Pagamento**/deposito.
6. **Conferma**: riepilogo → crea booking → scrive su calendario → invia email → schermata di successo (messaggio/redirect + pulsanti "aggiungi al calendario").

Varianti UI: **inline**, **popup modale**, **slide-in**, **trigger da pulsante**. "Prossimo slot disponibile" come scorciatoia. Pre-compilazione dati se l'utente è loggato in Joomla.

---

## 5. Algoritmo disponibilità (sintesi)
`slot_liberi(giorno, staff, servizio) = orari_di_lavoro − busy_calendario − booking_esistenti − buffer − giorni_speciali/chiusure`, filtrati per preavviso minimo, finestra massima, capienza e granularità della durata. In modalità B si salta il termine `busy_calendario`.

---

## 6. Sistema di notifiche / email (requisito esplicito)

### 6.1 Eventi che generano notifiche
Prenotazione creata, prenotazione confermata (se c'è approvazione), riprogrammata, cancellata, **reminder** (uno o più, es. 24h e 1h prima), follow-up post-appuntamento, no-show, apertura posto da lista d'attesa.

### 6.2 Destinatari (configurabili)
- **Chi prenota** (cliente): conferma, reminder, modifica/cancellazione, follow-up.
- **Staff assegnato** (uno o più se servizio in team): nuova prenotazione, modifica, cancellazione, reminder.
- **Admin/Manager**: lista indirizzi configurabile (anche più di uno) che riceve tutto o una selezione. CC/BCC opzionali (es. reception).
- Gestione **multi-staff**: lo staff assegnato riceve la sua; i manager ricevono tutte.

### 6.3 Contenuto
- Template per-tipo con **oggetto + corpo HTML e testo**, **multilingua** (stringhe Joomla o template per-lingua).
- **Placeholder**: `{customer_name} {service} {staff} {date} {time} {duration} {location} {meeting_link} {price} {cancel_link} {reschedule_link} {custom_*}` ecc.
- **Allegato .ics**: `METHOD:REQUEST` sulla conferma (arriva come invito nel calendario del cliente), `METHOD:CANCEL` sulla cancellazione, **UID stabile** per gli update. In più, pulsanti "Aggiungi a Google/Outlook".

### 6.4 Invio & affidabilità
- Via mailer Joomla (SMTP configurabile). Note deliverability: from sul **dominio del sito**, SPF/DKIM. Predisposizione per ESP transazionale (fase 2).
- **Log notifiche** per prenotazione (inviata/fallita) per debugging.

### 6.5 Reminder schedulati
Task del **Task Scheduler nativo di Joomla** (`com_scheduler`) che a intervalli invia i reminder in scadenza. Richiede cron del sito o lo scheduler "lazy" di Joomla. Niente cron esterno obbligatorio.

### 6.6 Self-service cancel/reschedule
Link tokenizzati (senza login) nelle email → pagina di gestione → la modifica **riscrive sul calendario** (update/delete evento) e notifica le parti.

---

## 7. Backend component (com_webgbooking)
Dashboard, gestione Servizi, Staff (con connessione calendari), Prenotazioni (calendario admin: mese/settimana/giorno/lista), Clienti, Template notifiche, Campi custom, Impostazioni globali (modalità A/B default, fusi, branding, gateway pagamenti in Pro), Log. Ruoli: admin, manager, staff, cliente.

---

## 8. Elemento YOOtheme Pro — inventario opzioni (parità con elementi nativi)
Obiettivo di Marco: **tante opzioni configurabili quanto un elemento nativo YT**. Gli elementi nativi YT hanno tab **Content / Settings(Style) / Advanced**, valori **responsive per breakpoint** e molti campi **dynamic-content**. Replichiamo questa ricchezza.

### 8.1 Tab CONTENT (comportamento & sorgente)
- **Servizio/Tipo**: singolo, oppure "tutti di una categoria".
- **Staff**: tutti / selezione multipla / scelta del cliente / auto-assegnazione (round-robin, meno occupato, per servizio).
- **Modalità**: eredita globale / calendar-sync / rule-based.
- **Layout widget**: inline / popup / slide-in / trigger da pulsante (con testo, icona, stile del pulsante).
- **Step attivi e ordine** (toggle ciascuno): servizio, staff, data/ora, dati, pagamento, conferma.
- **Finestra**: giorni/settimane mostrati, preavviso minimo, anticipo massimo.
- **Ora**: formato 12/24h, primo giorno settimana, fuso (auto-cliente / fisso).
- **Campi da raccogliere**: nome, email, telefono, note + custom (text/select/checkbox/date), required per campo, **checkbox consenso GDPR** con link a privacy.
- **Extra**: "prossimo slot disponibile", mostra posti rimanenti, prenotazioni multiple/carrello, prefill da utente Joomla.
- **Esito**: messaggio di successo / redirect URL / riepilogo con pulsanti add-to-calendar.
- **Presentazione contenuto** (con **dynamic source**): titolo, sottotitolo, descrizione; mostra immagine/descrizione/durata/prezzo servizio; mostra foto/bio staff; **override etichette** di ogni pulsante e step (i18n).

### 8.2 Tab SETTINGS / STYLE (responsive + stati, come nativo)
- **Layout**: larghezza, max-width, allineamento, **colonne della griglia slot** (responsive), gutter.
- **Spaziatura**: margin & padding (per breakpoint).
- **Contenitore widget**: background (colore/gradiente/immagine), bordo, border-radius, ombra.
- **Colore primario/accento**: guida pulsanti e slot selezionato.
- **Calendario**: stile griglia mese, colori giorno oggi/selezionato/disabilitato, hover.
- **Slot**: stile pulsante (default/primary/secondary/outline UIkit), dimensione, raggio, colonne, gap, colore stato selezionato.
- **Indicatore step/progress**: stile.
- **Tipografia**: font/size/weight per titoli, etichette, slot (responsive).
- **Form**: stile input, posizione label, colore focus.
- **CTA principale**: preset pulsante UIkit + dimensione + larghezza + icona + hover.
- **Animazioni**: transizione UIkit al cambio step, reveal scrollspy, spinner di caricamento.
- **Tema**: chiaro/scuro/eredita; **Style presets** (meccanismo preset nativo YT).
- **Visibilità responsive**: nascondi su S/M/L/XL.

### 8.3 Tab ADVANCED
- Nome elemento nel builder, **ID**, **classe CSS**, attributi custom, **CSS custom**.
- Stato (pubblica/nascondi), **visibilità condizionale** (solo loggati, per data, per lingua).

### 8.4 Integrazione tecnica
- Elemento spedito dentro `com_webgbooking` → **auto-discovery** YT (no child theme), `group => 'WebG'`.
- Markup **UIkit nativo**; widget come **web component con CSS scoped** (`wgb-*`) per non confliggere; l'elemento lo "veste" con token UIkit.
- `updates` per versioning dei layout salvati; campi `source => true` dove ha senso il dynamic content.
- (Secondario) **custom source** YT per liste servizi/staff con i layout del builder.

---

## 9. Modalità A vs B (riepilogo)
- **A — calendar-sync**: bidirezionale, OAuth/CalDAV per-staff. Differenziatore forte.
- **B — rule-based**: disponibilità solo da regole, nessun account esterno, GDPR-friendly, ship più rapido.
- Stesso engine: A = B + sorgente busy esterna + write-back.

---

## 10. Sicurezza / privacy / GDPR
Token cifrati at-rest; lettura free/busy minimale (no dettagli evento); consenso esplicito del cliente; export/delete dati cliente; log accessi; rispetto scope minimo Google. Privacy policy richiesta per la verifica OAuth.

---

## 11. Decisioni aperte per Marco
1. **Provider al lancio**: Google + Apple + Outlook tutti subito, o Google first e gli altri a seguire?
2. **App OAuth**: per i tuoi siti partiamo col Modello B (app centrale WebG)? E documentiamo il Modello A per la vendita futura?
3. **Approvazione prenotazioni**: auto-conferma sempre, o opzione "richiede conferma manuale dello staff"?
4. **Pagamenti**: fuori dall'MVP (solo fase Pro), giusto?
5. **Capienza**: solo 1:1 all'inizio, o anche appuntamenti di gruppo (più partecipanti per slot)?
6. **Lista d'attesa** e **no-show**: MVP o fase 2?
7. Multilingua template: stringhe Joomla o template separati per-lingua?

---

## 12. Decisioni recepite (23-06-2026)
- **Google multi-calendario**: un account Google ha più calendari. Lo staff deve poter **selezionare quali calendari controllare** per i conflitti (lettura multipla) e **su quale calendario scrivere** la prenotazione (uno scelto). → config per-staff: lista calendari con checkbox "controlla per busy" + radio "scrivi qui".
- **Provider MVP**: si parte con **Google**. Si aggiunge **subito anche Apple iCloud** via CalDAV (non richiede app/verifica, solo app-specific password). Outlook/Microsoft a seguire.
- **Modello A** (bring-your-own-credentials) fin da subito: il proprietario del sito incolla le proprie credenziali OAuth Google. Serve **wizard di setup guidato** + doc passo-passo nel backend.
- **Pagamenti**: NON nell'MVP.
- **Capienza**: **1:1** ora. Fast-follow: permettere a chi prenota di **aggiungere email di altri partecipanti** che ricevono anch'essi l'invito .ics. Gruppi (più posti per slot) più avanti.
- **Lista d'attesa**: fase 2.
- **Multilingua**: da subito.
- **Log invii email**: visibili nel **backend** (vista dedicata con stato inviata/fallita per prenotazione).
- **JED compliance (vincolante)**: licenza **GPL**, **codice non offuscato/encrypted**, **tutte le stringhe in file lingua** Joomla (`.ini`, ship `en-GB`, nessuna stringa hardcoded — verificato da JED Checker). Email template **sovrascrivibili per-lingua**. Questo risolve la domanda "stringhe Joomla vs template per-lingua": si usano le stringhe lingua Joomla (obbligatorio per la JED) + override template per-lingua dove serve.

## 13. Design del form di prenotazione (risposta alla domanda "form via page builder?")
**Si può comporre il form col page builder di YOOtheme?** Sì, tecnicamente, ma con vincoli:
- I framework che permettono form builder-composti (**ZOOlanders Essentials Forms**, **more Form**) sono **addon di terze parti a pagamento**: i campi-elemento usano un loro contratto (es. ZOOlanders: prop `submittable: true`, prefisso `yooessentials_form_`). Dipendere da loro = imporre al cliente un altro acquisto e accoppiarci a una loro API. **Scartato come base.**
- Costruire un **nostro** sistema di campi-elemento componibili nel builder (pattern container + child elements, supportato da YT) è fattibile ma costoso: stato multi-step, validazione, mapping di campi arbitrari sul record prenotazione, logica condizionale. → **v2**.

**Scelta MVP (più di quanto chiesto, ma a bassa complessità)**: i campi si configurano **nel pannello dell'elemento** (e/o nel backend component):
- Campi core: **nome, cognome, email, telefono** (toggle visibilità/obbligatorietà per ciascuno).
- **Checkbox privacy policy** (con link configurabile) + **checkbox newsletter** (opt-in).
- **Campi personalizzati** ripetibili: tipo (text/textarea/select/checkbox/radio/date), label, obbligatorio, opzioni. Così ogni sito fa le domande che vuole **senza** addon esterni.
- Opzione di **mappare campi su Joomla core custom fields** (`com_fields`, nativo e JED-friendly) per riusare campi esistenti.

In sintesi: partiamo col set configurabile (core + privacy + newsletter + custom fields), e il "form interamente disegnato nel builder" resta evoluzione v2 con nostri field-element nativi (niente dipendenza ZOOlanders).
