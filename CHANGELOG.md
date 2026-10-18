# Changelog

## 0.31.0 (pacchetto) · componente 0.4.0 · plugin di sistema 0.26.0 · plugin task 0.1.0 (nuovo)

Fase 1 per sostituire Calendly.

### Novita'
- **Tipo di evento per elemento** (scheda *Event*): durata, tempo libero prima/dopo (vuoti = opzioni del plugin), luogo, pagina di ritorno. La configurazione e' firmata HMAC; disponibilita' (`month`/`slots`), prenotazione e riprogramma calcolano con la durata dell'elemento (e, per le righe gia' salvate, con la loro). Due elementi diversi convivono nella stessa pagina o in pagine diverse; gli slot si bloccano a vicenda usando durata e buffer di ciascuna prenotazione esistente.
- **Luogo**: Google Meet, Telefonata (ti chiamo io), Scelta del cliente. Telefonata: numero obbligatorio (validato lato server), evento Google senza Meet col numero nel titolo e nella descrizione, email e `.ics` con "ti chiamo al numero X". Nuove colonne in `#__webgbooking_bookings`: `duration_min`, `buffer_before`, `buffer_after`, `location`, `event_name`, `reminder_cfg`, `reminders_sent` (ALTER idempotente in `script.php`); visibili in lista/dettaglio admin, export CSV/XLSX (in coda), webhook e riga Google Sheet (in coda).
- **Promemoria** email al cliente (default 24h e 1h, configurabili per elemento e nelle opzioni del plugin, testo personalizzabile; nuovi segnaposto `{location}` `{duration}` `{event}`, `{meet_block}` mostra il pulsante Meet oppure la riga "ti chiamo"). Nuovo plugin `plg_task_webgbookingreminders` + attivita' pianificata creata dal pacchetto (ogni 5 minuti, lazy scheduler o cron). Reclamo atomico per non inviare mai due volte, nessun invio per annullate/passate, riprogramma ricomincia, invio fallito riprovato.
- **Pagina di ritorno** per elemento (dopo l'evento analytics).
- **Annulla dall'admin** (lista singolo/multiplo e dettaglio): evento Google rimosso, email di annullamento al cliente con `.ics CANCEL`, report a video di cosa e' successo.
- **Niente doppie prenotazioni**: controllo slot + inserimento sotto lock per giorno (`GET_LOCK`), senza modifiche di schema.
- `tools/build.py` (build riproducibile, anche su Windows) e `tools/e2e/` (prove su Joomla 5/6 usa-e-getta in Docker).

### Correzioni
- **Annulla e riprogramma fatti dal cliente non scrivevano nel database** (0.30.0, verificato su Joomla 5.4.9): `updateObject()` vuole l'oggetto per riferimento e riceveva un cast inline, l'errore veniva inghiottito. La pagina diceva "annullato" ma lo stato restava `pending`, l'evento Google restava e il riprogramma falliva. Corretto.
- Lettura delle risposte HTTP compatibile con Joomla 6 (PSR-7, la proprieta' `->body` e' deprecata e sparisce in Joomla 7).
- Il link "modifica o annulla" dei promemoria usa l'URL pubblico salvato alla prenotazione, anche quando lo scheduler gira da cron CLI.

## 0.30.0
Gestione prenotazioni nell'admin (stato, elimina, dettaglio, filtri, export) e hardening di sicurezza.
