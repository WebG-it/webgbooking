# WebG Booking — sorgente

> Repo **pubblico** `WebG-it/webgbooking` (serve a Joomla per auto-update e install-by-URL). Codice GPL, **nessun segreto**: i token dei calendari si inseriscono a runtime e restano cifrati nel DB del cliente. Strategia, prezzi e concorrenti stanno nel KB privato, non qui.

## Contenuto
```
pkg_webgbooking/                  # pacchetto (type=package): manifest + script.php (update site pulito, attivita' pianificata dei promemoria)
com_webgbooking/                  # componente admin: prenotazioni (lista, dettaglio, stato, annulla, export) + pagina Google
plg_system_webgbooking/           # plugin di sistema: elemento YOOtheme + TUTTO il motore pubblico via com_ajax
  src/Extension/Webgbooking.php   #   disponibilita', prenotazione, Google, email/ics, annulla/riprogramma, promemoria
  yootheme/elements/booking/      #   element.php (campi), templates/template.php (config firmata HMAC), assets/wgb.js
plg_task_webgbookingreminders/    # plugin Task Scheduler: chiama il motore dei promemoria
```

`src/pkg_webgbooking/packages/*.zip` si genera con `python tools/build.py` e non e' versionato.

Per la descrizione completa vedi il [README](../README.md) e la scheda `progetti/webg-booking.md` del KB.
