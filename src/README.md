# WebG Booking — sorgente

> Repo **pubblico** `WebG-it/webgbooking` (serve a Joomla per auto-update + install-by-URL). Il codice è GPL e **non contiene segreti**: gli OAuth/token dei calendari sono inseriti a runtime e cifrati nel DB del cliente, **mai** committati qui. Strategia/pricing/competitor stanno nel **KB privato** mg-knowledge, NON in questo repo.

## Stato: tracer bullet (step 1 della roadmap di `docs/05-architecture.md`)
Obiettivo di questo primo pacchetto: **validare su un sito YT Pro 5 reale** che il nostro elemento si registra nel builder **senza child theme**, con icona e markup UIkit corretti. È il rischio tecnico n°1.

## Contenuto
```
plg_system_webgbooking/                 # system plugin che inietta l'elemento in YOOtheme
  webgbooking.xml                        # manifest (group=system)
  services/provider.php                  # DI service provider
  src/Extension/Webgbooking.php          # onAfterInitialise(LOW) -> YOOtheme\Application::getInstance()->load(bootstrap)
  yootheme/bootstrap.php                 # extend(Builder) -> addType('wgb_booking', ...)
  yootheme/elements/booking/
    element.php                          # definizione elemento (API verificata su YT 5.0.35)
    templates/template.php               # render UIkit
    templates/content.php                # versione no-markup (ricerca/portabilità)
    images/icon.svg, iconSmall.svg       # icone stile linea UIkit
  language/en-GB/*.ini                   # stringhe (JED: niente hardcoded)
```

## Come testarlo (5 minuti)
1. **Zippa** la cartella `plg_system_webgbooking` (la root dello zip deve contenere `webgbooking.xml`).
2. Joomla 6 + YOOtheme Pro 5 → *Sistema → Installa → Carica pacchetto* → carica lo zip.
3. *Sistema → Plugin* → abilita **"System - WebG Booking"**.
4. Apri lo **YOOtheme Customizer** su una pagina → **Add Element** → cerca il gruppo **WebG** → **Booking**.
5. Trascinalo: deve comparire l'icona calendario, i 3 slot placeholder e il pulsante; il pannello deve avere i tab **Content / Settings / Advanced**.

## Checklist di verifica (compat YT / UIkit / design / bug)
- [ ] L'elemento appare nel gruppo **WebG** della libreria (registrazione via singleton OK → no child theme).
- [ ] Icona libreria (`icon.svg`) e icona piccola (`iconSmall.svg`) si vedono pulite.
- [ ] Tab **Content / Settings / Advanced** renderizzano (i `${builder.*}` e `${builder.advanced}` risolvono).
- [ ] Il frontend rende markup **UIkit** valido (uk-card, uk-button, uk-grid) e responsive.
- [ ] Nessun errore PHP nel log con/ senza YOOtheme attivo (guardia `class_exists` + contesti).
- [ ] **Seam da validare**: timing della registrazione (priorità LOW vs builder già risolto) e contesti admin del customizer. Se l'elemento non appare nel customizer ma sì in frontend, si allarga la guardia dei contesti.

## Build pacchetto completo (più avanti)
Quando aggiungiamo `com_webgbooking`, il tutto diventa `pkg_webgbooking` (package) con component + questo plugin + plugin task. Update server: riuso del collection server `WebG-it/joomla-extensions` (come cfpurge).
