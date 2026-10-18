# Changelog

## 0.32.0 (pacchetto) · componente 0.5.0 · plugin di sistema 0.27.0 · plugin task 0.2.0

Fase 2: pagamento con Stripe Checkout, piu' due chiusure della fase 1.

### Novita'
- **Eventi a pagamento** (scheda *Event* dell'elemento: Prezzo, Valuta, Descrizione riga). La config e' firmata HMAC con il resto: il visitatore non puo' abbassare o togliere il prezzo, e una richiesta senza config firmata valida viene rifiutata (prima prenotava un evento "gratis" di default). Prezzo vuoto o 0 = evento gratuito, tutto come prima.
- **Flusso**: al conferma la prenotazione nasce `awaiting_payment` (slot tenuto 30 minuti, configurabile da 30 a 1440) e il server crea una Checkout Session (API REST via HTTP Joomla, nessun SDK); il browser va a Stripe. Email, evento Google/Meet, Sheet, webhook e promemoria partono SOLO a pagamento confermato. La ricevuta e' quella di Stripe (`receipt_email` = email del cliente). Il widget mostra il prezzo e "Paga X e prenota".
- **Conferma pagamento**: endpoint webhook Stripe (`action=stripewebhook`) con verifica di `Stripe-Signature` (HMAC-SHA256, tolleranza 5 minuti, `hash_equals`), idempotente (tabella `#__webgbooking_stripe_events` + passaggio di stato atomico: lo stesso evento due volte, o lo stesso pagamento con due id evento, ha un solo effetto), eventi `checkout.session.completed`, `checkout.session.expired` e `async_payment_succeeded/failed`. Gli eventi del modo sbagliato (test/live) sono ignorati.
- **Rete di sicurezza**: la pagina di ritorno rilegge la sessione da Stripe con la chiave segreta e conferma solo se pagata (i parametri dell'URL non contano). Importo e valuta della sessione devono coincidere col prezzo.
- **Pagamento tardivo**: se l'hold e' scaduto ma lo slot e' ancora libero la prenotazione si conferma; se qualcuno l'ha presa (o l'importo non torna) la prenotazione passa a `needs_review` ("Da gestire") e lo staff riceve un'email per rimborsare a mano. Niente doppio incontro silenzioso.
- **Scadenza**: un hold scaduto non blocca piu' lo slot (subito, nel calcolo disponibilita') e l'attivita' pianificata lo marca `expired` e chiude la sessione Stripe; se Stripe dice che nel frattempo era stato pagato, conferma invece di scadere. Il tasto "indietro" di Stripe libera subito lo slot.
- **Opzioni del plugin, scheda Payments**: modalita' test/live, chiave pubblicabile, chiave segreta e segreto del webhook **cifrati a riposo (AES-256-GCM come il token Google) e mai rimandati al form** (mostra solo "impostata"; vuoto = non cambia, trattino = cancella), URL del webhook da registrare su Stripe, durata hold, preavviso di annullo gratuito. Al salvataggio la chiave segreta viene verificata con Stripe e controllata contro la modalita'.
- **Politica di annullo**: una prenotazione PAGATA si annulla/sposta da sola dal cliente fino a 24 ore prima (configurabile); dopo, la pagina spiega di contattarti. Se annulla il cliente, mail di conferma con "ti rimborseremo X" e avviso allo staff; se annulla l'admin, avviso a video di rimborsare a mano. I rimborsi si fanno a mano da Stripe.
- **Admin**: stati `awaiting_payment`, `expired`, `needs_review` (filtro, badge, nomi tradotti), colonna Pagamento in lista (importo, stato, riferimento Stripe, "annullata: da rimborsare a mano"), dettaglio con payment intent e sessione, export CSV/XLSX con le colonne di pagamento in coda. L'email al cliente dice "Pagamento ricevuto: 29,00 €" (segnaposto `{amount}` e `{payment_block}`).
- Nuove colonne in `#__webgbooking_bookings`: `payment_status`, `amount_cents`, `currency`, `stripe_session_id`, `stripe_payment_intent`, `hold_until`, `paid_at`, `pending_ctx`; nuova tabella `#__webgbooking_stripe_events` (ALTER/CREATE idempotenti).

### Chiusure della fase 1
- **Promemoria anche all'ospite** invitato (senza link di gestione e senza il telefono del cliente).
- **Eliminare dall'admin rimuove anche l'evento Google** (e chiude una sessione Stripe aperta); il messaggio dice cosa e' stato fatto.

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
