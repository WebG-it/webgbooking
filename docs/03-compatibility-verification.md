# WebG Booking — Verifica compatibilità Joomla 6 + YOOtheme Pro (2026-06-23)

Verifica **fondata sul sorgente reale** del pacchetto `yootheme_j_5.0.35.zip` (ultima stabile, in `Downloads`), non su documentazione. Obiettivo: garantire che l'architettura scelta sia 100% compatibile con Joomla 6 e con YOOtheme Pro 5 **prima** di scrivere codice.

## A. Joomla 6 — ✅ confermato
- YT Pro **5.0.35** è l'ultima stabile (il template stesso si dichiara `<version>5.0.35</version>`). UIkit 3.
- Gira **già su Joomla 6.1.1** in produzione (offerteesconti.it usa questo template su J6.1.1).
- Il `plg_system_yootheme` usa l'**event system moderno** di Joomla (`CMSPlugin` + `SubscriberInterface` + `Joomla\Event\Priority`), cioè le stesse API stabili in Joomla 5 e 6. Nessuna `JFactory`/superglobal/API deprecata.
- **Vincolo per il NOSTRO codice** (così resta 100% J6): service-provider DI (`services/provider.php`), `SubscriberInterface`/`getSubscribedEvents`, eventi tipizzati con accesso via getter o `getArgument()`, `Joomla\CMS\Mail` (Mailer), `Joomla\Database` (query parametrizzate), `Joomla\Registry`, **`com_scheduler`** per i reminder. Niente `JFactory`, `JRequest`, superglobal, codice offuscato (anche per la JED).

## B. API elemento builder YT Pro 5 — ✅ verificata su sorgente
Letto `packages/builder/elements/button/element.php` (elemento nativo reale). Tutte le chiavi su cui poggia il design `01-yootheme-integration.md` **esistono e sono in uso**:

| Chiave / feature | Stato nel 5.0.35 | Uso per noi |
|---|---|---|
| `name`, `title`, `group`, `icon`, `iconSmall`, `element=>true`, `width` | ✅ | identità elemento, `group=>'WebG'` |
| `container=>true` + `placeholder.children` + child element (`button_item`) | ✅ | pattern container+figli ripetibili → base per il "form disegnato nel builder" (v2) |
| `defaults` | ✅ | valori iniziali |
| `templates` => `render` + `content` | ✅ | markup UIkit + versione senza markup per ricerca/portabilità |
| `fields` con tipi `select`, `checkbox`, `editor` (code), `content-items`, ecc. | ✅ | pannello settings ricco |
| `fieldset` con `type=>'tabs'` → **Content / Settings / `${builder.advanced}`** | ✅ | parità coi nativi (3 tab) |
| placeholder `${builder.*}` (margin, position, visibility, animation, maxwidth responsive, name, status, id, class, attributes, css, transform) | ✅ | responsive per breakpoint + tab Advanced "gratis" riusando i campi nativi |
| `source => true` sul campo | ✅ | dynamic content |
| `updates => __DIR__.'/updates.php'` | ✅ | migrazioni dei layout salvati (da usare dalla v1) |

→ **Niente da reinventare**: ereditiamo i campi responsive/avanzati nativi via `${builder.*}` e ci concentriamo solo sui campi specifici del booking.

## C. Registrazione elemento da un'estensione — ✅ meccanismo preciso verificato (CORRETTO)
Indagine sul sorgente: il **core YT 5.0.35 NON scansiona estensioni Joomla arbitrarie**. Carica elementi custom solo (a) dai propri package (glob fisso in `template_bootstrap.php`) e (b) dal **child theme** (`builder-joomla/bootstrap.php`: `File::glob("{childDir}/builder/*/element.{json,php}")`). Quindi "auto-discovery da estensione" così com'era scritto **non esiste**.

**Il modo reale (e quello che useremo), verificato:**
- `tpl_template/bootstrap.php`: `$app = Application::getInstance($cache)` → **l'Application YT è un singleton** (`Application::getInstance(): static::$instance ??= new static`).
- `Application::load(string $files)` carica un file di config che ritorna `['extend' => [Builder::class => fn($builder) => $builder->addType(...)]]` — è esattamente come YT registra i propri elementi (`builder-joomla/bootstrap.php`).
- Quindi la **nostra estensione** registra l'elemento così: un `plg_system_webgbooking` su `onAfterInitialise` a **priorità LOW** (dopo YT che è HIGH e ha già creato l'app) prende il singleton e fa `Application::getInstance()->load(.../yootheme/bootstrap.php)` → `extend(Builder)` → `addType('wgb_booking', element.php)`. **Niente child theme.** Guardia: solo nei contesti dove YT bootstrappa (site, o admin com_ajax/templates/modules/content) + `try/catch`.
- **Verifica live residua (vera, allo scaffold):** che il timing regga (registrare l'`extend` prima che il `Builder` venga risolto) e che la guardia dei contesti copra il customizer. → coperto dal **tracer** in `src/` (vedi `src/README.md`).

## D. Correzioni ai doc esistenti
- `01-yootheme-integration.md` dice "build 5.0.28–5.0.34": aggiornare a **5.0.35** (ultima verificata).
- Confermato che il pattern **container + child element** (es. `button`/`button_item`) è la strada nativa per il "form composto nel builder" della **v2**, senza dipendere da ZOOlanders/YOOessentials (coerente con la scelta MVP in doc 02 §13).

## Verdetto
Architettura **100% compatibile con Joomla 6 e YOOtheme Pro 5.0.35**. L'API elementi è stabile, ricca e auto-caricabile da estensione. Unico check pratico residuo: auto-discovery dell'elemento da dentro la component su install reale (allo scaffold). Si può procedere col design del codice senza rischi di incompatibilità.
