# WebG Booking

Estensione di **prenotazione appuntamenti per Joomla 5/6** con elemento nativo **YOOtheme Pro 5** (builder UIkit). Sviluppata da [WebG](https://www.webg.it).

> 🔒 **Nessun segreto nel codice.** Le credenziali dei calendari (OAuth) vengono inserite a runtime dall'utente e cifrate nel suo database: questo repository non contiene e non deve mai contenere token, password o chiavi (c'è una scansione gitleaks in CI).

## Requisiti
- Joomla **5** o **6**, PHP 8.2 o successivo
- **YOOtheme Pro 5** (l'elemento si registra nel builder senza child theme)

## Installazione (pacchetto unico)
Un solo pacchetto installa **componente + plugin di sistema + plugin delle attività pianificate (promemoria)** e aggiorna tutto in un colpo solo.

1. Joomla → *Sistema → Installa → Installa da URL*, incolla:
   ```
   https://raw.githubusercontent.com/WebG-it/webgbooking/main/dist/pkg_webgbooking-0.34.5.zip
   ```
2. I plugin si **attivano da soli** e il pacchetto crea l'attività pianificata dei promemoria (ogni 5 minuti). YOOtheme Customizer → **Add Element** → gruppo **WebG** → **Booking**.
3. Backend prenotazioni e connessione Google: **Componenti → WebG Booking**.
4. Se usi la copia su Foglio Google: nel progetto Google Cloud della connessione **abilita anche "Google Sheets API"** (*API e servizi → Libreria*), oltre a Google Calendar API. Senza, Google risponde "API non abilitata" e le righe non si scrivono: la pagina Google del componente lo dice chiaramente, e le righe in attesa si scrivono da sole appena l'API e' abilitata.

Gli aggiornamenti successivi arrivano via **Sistema → Aggiorna** come **un unico "WebG Booking"** (clicca *Trova aggiornamenti*).

## Cosa fa (0.36.1)
- **Foglio Google che segue la prenotazione** (0.36.1): prima riga di intestazione (su un foglio o una scheda del tutto vuoti), una riga per prenotazione con l'**id nella colonna O**; la riga si aggiorna quando la prenotazione cambia (annullata dal cliente o dal pannello, confermata, completata, no-show, spostata) e **si cancella quando elimini la prenotazione dal pannello**. Le righe scritte da versioni precedenti (senza id) restano come sono. Un errore di Google non blocca mai l'annullo o la modifica: si riprova da soli. Permesso Google invariato (solo *spreadsheets*).
- **Pagina diretta centrata** nella finestra (anche l'elenco dei tipi e la pagina "non trovato"); con contenuto alto parte dall'alto e scorre.
- **Link diretto alla prenotazione** (come `calendly.com/nome/evento`): *Componenti → WebG Booking → Tipi di evento*. Ogni tipo (titolo, descrizione, durata, margini, luogo, orari, prezzo Stripe, domande, avvisi) ha un indirizzo pubblico `https://sito/prenota/<slug>` (`https://sito/index.php/prenota/<slug>` senza riscrittura degli indirizzi, `index.php?option=com_webgbooking&view=book&event=<slug>` senza indirizzi puliti) e il pulsante **Copia link**. La pagina mostra nome dell'attivita', titolo, durata, descrizione e il widget completo, con lo stile delle email, e' `noindex` e funziona a 375 px. Un evento **segreto** si raggiunge solo col link e non e' nell'elenco `/prenota`; un evento non pubblicato non esiste. Una pagina del sito sulla stessa strada vince sempre. Domande, prezzo e durata sono riletti dal database a ogni prenotazione. Un elemento YOOtheme puo' scegliere un tipo salvato invece di configurare l'evento a mano (scheda *Evento*, primo campo); le configurazioni a mano restano identiche.
- **Tipo di evento per elemento** (scheda *Event* dell'elemento): durata in minuti, tempo libero prima/dopo, luogo, pagina di ritorno. Più elementi nella stessa pagina o in pagine diverse funzionano insieme (es. "Riunione - 1 ora" e "Chiamata veloce"): disponibilità e validazione usano la durata dell'elemento, non un valore globale (che resta il predefinito se il campo è vuoto). Un appuntamento di 60 minuti blocca anche le fasce da 30 che lo toccano, con i suoi buffer.
- **Luogo**: *Google Meet*, *Telefonata (ti chiamo io)* o *Scelta del cliente*. Con la telefonata il numero è obbligatorio (controllato dal server), l'evento Google nasce **senza Meet** con il numero nel titolo e nella descrizione, email e `.ics` dicono "ti chiamo al numero X". Il luogo si salva sulla prenotazione e compare in lista e dettaglio admin, export CSV/Excel, webhook e Google Sheet.
- **Email con un layout unico** (cliente e staff): scheda professionale con riquadro dell'appuntamento, pulsanti, modalita' scura, versione testuale; logo, colore, piede e nome del mittente si impostano nelle Opzioni del componente (scheda *Aspetto delle email*). Il testo scritto nell'elemento va dentro il layout.
- **Promemoria** al cliente prima dell'incontro (predefinito 24 ore e 1 ora, configurabili per elemento o nelle Opzioni del componente, testo personalizzabile con i segnaposto). Li invia il **Task Scheduler di Joomla** (plugin `plg_task_webgbookingreminders`), che gira anche solo col traffico del sito (modalità lazy) o da cron. Mai due volte lo stesso promemoria, mai su prenotazioni annullate o passate; se si sposta l'appuntamento ricominciano.
- **Conferma**: le prenotazioni gratuite sono confermate subito (email *Appuntamento confermato*); l'opzione *Conferma manuale* dell'elemento ripristina il flusso "richiesta, poi confermo io". Le email allo staff (nuova prenotazione, riprogrammazione, annullo, avvisi) vanno al destinatario staff dell'elemento, con ripiego sull'indirizzo di notifica del plugin.
- **Google**: la pagina del componente mostra tre stati (non collegato, collegamento scaduto con un solo *Ricollega Google*, collegato). La copia su Foglio Google e' opzionale e usa solo il permesso Sheets (nessun accesso a Drive).
- **Integrazioni**: *Brevo* nativo (contatto salvato senza sovrascrivere i contatti esistenti, liste solo con la spunta newsletter e il double opt-in, attributi NOME/COGNOME/SMS e dalle risposte del modulo; chiave API cifrata nelle Opzioni del componente) e *webhook* per ogni evento della prenotazione (creata, pagata, spostata, annullata, scaduta, confermata) con *profili di intestazioni* cifrati (Authorization, X-Api-Key...) che non finiscono mai nel codice della pagina: va bene per MailUp, Mailchimp, ActiveCampaign, Make, Zapier, n8n.
- **Eventi a pagamento con Stripe Checkout** (scheda *Event*: Prezzo, Valuta, Descrizione). Lo slot e' tenuto 30 minuti mentre il cliente paga; evento Google, email, promemoria, Sheet e webhook partono solo a pagamento confermato (webhook Stripe firmato e idempotente, rilettura della sessione al ritorno, scadenza che libera lo slot, pagamento tardivo con slot preso = "Da gestire" + avviso allo staff). Annullo gratuito fino a 24 ore prima, rimborsi a mano da Stripe. Chiavi nelle opzioni del plugin, scheda *Payments* (cifrate, mai rimandate al form).
- **Pagina di ritorno** opzionale per elemento: a prenotazione completata il widget porta lì, dopo aver inviato l'evento analytics.
- **Annulla dall'admin** (singolo e multiplo, anche dal dettaglio): cancella l'evento Google e manda al cliente l'email di annullamento con `.ics CANCEL`, come l'annullamento fatto dal cliente.
- **Niente doppie prenotazioni**: la verifica dello slot e l'inserimento avvengono sotto un lock per giorno (MySQL/MariaDB `GET_LOCK`), senza modifiche di schema sull'upgrade.
- Calendario Google (busy, Meet, evento creato/spostato/cancellato), email HTML + `.ics`, annulla/riprogramma da parte del cliente, campi personalizzati, azioni (email cliente/staff, webhook), Google Sheets live, componente admin con stato, filtri ed export.

## Dove stanno le impostazioni (dalla 0.36.0)
Tutte le impostazioni (disponibilità, aspetto delle email, integrazioni, pagamenti, analytics) sono le **Opzioni del componente**: *Componenti → WebG Booking → Opzioni* (il pulsante **Opzioni** è anche nella barra degli strumenti di Prenotazioni, Tipi di evento e Testi delle email). Il plugin di sistema non ha più opzioni sue: nella sua scheda c'è solo il collegamento alle Opzioni del componente. Le Opzioni sono protette dal permesso `core.admin` del componente (scheda *Permessi*).
- **Aggiornando da una versione precedente non si perde niente**: all'aggiornamento ogni valore che il plugin aveva viene **copiato** nelle Opzioni del componente (le chiavi cifrate restano cifrate e valgono così come sono). Una chiave che il componente ha già non viene mai sovrascritta, quindi rilanciare l'aggiornamento non disfa una modifica. I vecchi valori restano nel plugin come ripiego (letti solo se al componente manca una chiave): non si cancella niente finché la copia non è provata. Le chiavi e i segreti in quella copia sono sempre cifrati (uno trovato in chiaro viene cifrato all'aggiornamento) e una chiave cambiata o cancellata dalle Opzioni viene azzerata anche lì.
- **Piede delle email e della pagina di prenotazione**: è un editor. Con il pulsante del collegamento si scrive un link con delle parole ("Privacy Policy") senza mostrare l'indirizzo. Si conservano solo grassetto, corsivo, a capo e collegamenti a `http`, `https` e `mailto`; tutto il resto (script, stile, classi, `onclick`, `javascript:`, `data:`, iframe) viene tolto al salvataggio e ancora al momento di disegnare. Il collegamento che nelle parole o nell'indirizzo ha "privacy" dà l'indirizzo alla casella del consenso. I piedi scritti prima (testo semplice, una riga per voce) si vedono come prima e si aprono nell'editor con le righe a capo.
- Chi sviluppa legge le impostazioni da un solo punto: `WebG\Plugin\System\Webgbooking\Booking\Settings::all()` (le Opzioni del componente, il plugin come ripiego).

## Testi delle email: si cambiano senza codice
*Componenti → WebG Booking → Testi delle email*: una voce per ogni email (conferma, richiesta, spostamento, promemoria, link della videochiamata, annullo, pagamento tardivo, invito e promemoria all'ospite, le quattro notifiche a chi riceve gli appuntamenti). Per ognuna si cambiano oggetto, titolo, testo (editor visuale), parole dei pulsanti e chiusura, in italiano e in inglese. Ogni testo parte già scritto: si cambiano solo le parole.
- **Si salva solo ciò che differisce dal predefinito.** Un testo non toccato segue da solo le nuove versioni; "Ripristina il testo predefinito" cancella la riga salvata.
- **Segnaposto** dal menu "Inserisci segnaposto" dell'editor, ognuno con la sua descrizione: dati nel testo (`{name}`, `{date}`, `{time}`, `{amount}`, `{host}` ...) e **blocchi già formattati** che nessuno scrive in HTML: `{appointment_box}` (riquadro con i dati dell'appuntamento), `{answers_block}`, `{meet_button}`, `{calendar_link}`, `{change_links}` (Riprogramma e Annulla), `{payment_block}`, `{notices}`, `{backend_button}`, `{rebook_button}`. Un blocco che nel testo non c'è, nell'email non c'è.
- **Anteprima** e **Mandami una prova** per ogni email, con dati di esempio: non si crea nessuna prenotazione.
- L'**aspetto** comune (logo, colori con selettore, piede) resta nelle Opzioni del componente, scheda *Aspetto delle email*. L'email è: testata, corpo modificabile, blocchi, piede.
- Nell'elemento YOOtheme (scheda *Actions*) lo stesso editor vale per il singolo elemento; se il campo è vuoto si usa il testo di *Testi delle email*.
- Chi aveva testi nelle vecchie opzioni (quelle che prima stavano nel plugin) (oggetto e corpo della conferma e del promemoria) li ritrova, all'aggiornamento, in *Testi delle email*, con i blocchi standard attorno.

## Per chi sviluppa: personalizzare senza toccare il prodotto
Niente va modificato dentro `plugins/system/webgbooking`: un aggiornamento lo cancellerebbe. Due vie, entrambe standard di Joomla:
1. **Layout dell'email (override dal template).** L'HTML di ogni email è il layout Joomla `webgbooking.email.default`, il testo semplice è `webgbooking.email.text`. Copia `plugins/system/webgbooking/layouts/webgbooking/email/default.php` in
   `templates/<il tuo template>/html/layouts/webgbooking/email/default.php` e modifica la copia (lo stesso per `text.php`). Il file riceve `$displayData` (titolo, corpo con i blocchi, marchio, chiusura, piede: l'elenco è nel commento del file) e può usare `\WebG\Plugin\System\Webgbooking\Mail\EmailLayout::render($displayData)` per partire dal risultato standard. Se l'override dà errore, l'email parte con il layout standard e l'errore va nel registro del plugin: un'email non si perde.
2. **Stringhe (override di lingua di Joomla).** Ogni testo predefinito è una stringa di lingua (`PLG_SYSTEM_WEBGBOOKING_EM_<EMAIL>_<CAMPO>`, per esempio `PLG_SYSTEM_WEBGBOOKING_EM_CUSTOMER_CONFIRMED_TITLE`): *Sistema → Lingue → Override* la sostituisce per tutto il sito, e l'editor mostra e ripristina quella versione. Gli elenchi dei segnaposto e le etichette sono stringhe come le altre. Le email che il sito manda usano gli override del **Sito**, l'editor mostra quelli dell'**Amministrazione**: per vedere lo stesso testo in entrambi i posti metti la stessa riga nei due.
Le tabelle: `#__webgbooking_emailtexts` (`email_key`, `lang`, `data` in JSON, solo i campi che differiscono dal predefinito).

## Promemoria: come funzionano
Il pacchetto crea in *Sistema → Attività pianificate* l'attività **WebG Booking: send appointment reminders** (ogni 5 minuti) e abilita il plugin `Task - WebG Booking reminders`. Joomla la esegue con la *Lazy Scheduler* (attiva di default: parte quando qualcuno visita il sito) oppure da un cron di sistema: `php cli/joomla.php scheduler:run --all`. Se il cron CLI non conosce l'URL pubblico del sito, il link "modifica o annulla" nei promemoria usa comunque quello salvato al momento della prenotazione.

Interruttore generale e testi predefiniti: *Componenti → WebG Booking → Opzioni*. Per ogni elemento: scheda *Actions → Reminders*.

## Pagamenti: configurazione
1. *Componenti → WebG Booking → Opzioni → Payments*: scegli test/produzione, incolla la **chiave segreta** (la verifica con Stripe al salvataggio).
2. Su Stripe (Sviluppatori → Webhook) aggiungi l'endpoint con l'**URL mostrato nella stessa scheda** e gli eventi `checkout.session.completed` e `checkout.session.expired` (consigliati anche `checkout.session.async_payment_succeeded` e `checkout.session.async_payment_failed`); incolla il **segreto di firma** (`whsec_...`) nella scheda.
3. Nell'elemento booking, scheda *Event*, imposta il **Prezzo**. Vuoto = gratis.
Senza chiave segreta un evento a pagamento rifiuta le prenotazioni. L'attivita' pianificata (ogni 5 minuti) libera gli slot non pagati.

## Sviluppo
- Sorgente in `src/`, build con `python tools/build.py` (crea `dist/pkg_webgbooking-<versione>.zip`; con `--xml` aggiorna anche `updates-pkg.xml` con lo sha256).
- Prove end-to-end su Joomla usa-e-getta (Docker): `tools/e2e/README.md`.
- Documenti di design iniziali in `docs/` (l'architettura reale è più semplice: vale `src/`).

## Licenza
GNU General Public License v2 o successiva (vedi [LICENSE](./LICENSE)).
