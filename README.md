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
   https://raw.githubusercontent.com/WebG-it/webgbooking/main/dist/pkg_webgbooking-0.31.0.zip
   ```
2. I plugin si **attivano da soli** e il pacchetto crea l'attività pianificata dei promemoria (ogni 5 minuti). YOOtheme Customizer → **Add Element** → gruppo **WebG** → **Booking**.
3. Backend prenotazioni e connessione Google: **Componenti → WebG Booking**.

Gli aggiornamenti successivi arrivano via **Sistema → Aggiorna** come **un unico "WebG Booking"** (clicca *Trova aggiornamenti*).

## Cosa fa (0.31.0)
- **Tipo di evento per elemento** (scheda *Event* dell'elemento): durata in minuti, tempo libero prima/dopo, luogo, pagina di ritorno. Più elementi nella stessa pagina o in pagine diverse funzionano insieme (es. "Riunione - 1 ora" e "Chiamata veloce"): disponibilità e validazione usano la durata dell'elemento, non un valore globale (che resta il predefinito se il campo è vuoto). Un appuntamento di 60 minuti blocca anche le fasce da 30 che lo toccano, con i suoi buffer.
- **Luogo**: *Google Meet*, *Telefonata (ti chiamo io)* o *Scelta del cliente*. Con la telefonata il numero è obbligatorio (controllato dal server), l'evento Google nasce **senza Meet** con il numero nel titolo e nella descrizione, email e `.ics` dicono "ti chiamo al numero X". Il luogo si salva sulla prenotazione e compare in lista e dettaglio admin, export CSV/Excel, webhook e Google Sheet.
- **Promemoria** al cliente prima dell'incontro (predefinito 24 ore e 1 ora, configurabili per elemento o nelle opzioni del plugin, testo personalizzabile con i segnaposto). Li invia il **Task Scheduler di Joomla** (plugin `plg_task_webgbookingreminders`), che gira anche solo col traffico del sito (modalità lazy) o da cron. Mai due volte lo stesso promemoria, mai su prenotazioni annullate o passate; se si sposta l'appuntamento ricominciano.
- **Pagina di ritorno** opzionale per elemento: a prenotazione completata il widget porta lì, dopo aver inviato l'evento analytics.
- **Annulla dall'admin** (singolo e multiplo, anche dal dettaglio): cancella l'evento Google e manda al cliente l'email di annullamento con `.ics CANCEL`, come l'annullamento fatto dal cliente.
- **Niente doppie prenotazioni**: la verifica dello slot e l'inserimento avvengono sotto un lock per giorno (MySQL/MariaDB `GET_LOCK`), senza modifiche di schema sull'upgrade.
- Calendario Google (busy, Meet, evento creato/spostato/cancellato), email HTML + `.ics`, annulla/riprogramma da parte del cliente, campi personalizzati, azioni (email cliente/staff, webhook), Google Sheets live, componente admin con stato, filtri ed export.

## Promemoria: come funzionano
Il pacchetto crea in *Sistema → Attività pianificate* l'attività **WebG Booking: send appointment reminders** (ogni 5 minuti) e abilita il plugin `Task - WebG Booking reminders`. Joomla la esegue con la *Lazy Scheduler* (attiva di default: parte quando qualcuno visita il sito) oppure da un cron di sistema: `php cli/joomla.php scheduler:run --all`. Se il cron CLI non conosce l'URL pubblico del sito, il link "modifica o annulla" nei promemoria usa comunque quello salvato al momento della prenotazione.

Interruttore generale e testi predefiniti: *Plugin → System - WebG Booking*. Per ogni elemento: scheda *Actions → Reminders*.

## Sviluppo
- Sorgente in `src/`, build con `python tools/build.py` (crea `dist/pkg_webgbooking-<versione>.zip`; con `--xml` aggiorna anche `updates-pkg.xml` con lo sha256).
- Prove end-to-end su Joomla usa-e-getta (Docker): `tools/e2e/README.md`.
- Documenti di design iniziali in `docs/` (l'architettura reale è più semplice: vale `src/`).

## Licenza
GNU General Public License v2 o successiva (vedi [LICENSE](./LICENSE)).
