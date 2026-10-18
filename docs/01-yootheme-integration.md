# WebG Booking — Verifica integrazione YOOtheme Pro (2026-06-23)

Requisito di Marco: l'integrazione con YOOtheme Pro deve essere **reale, efficace e sull'ultima versione**, perché userà l'estensione su siti che montano davvero questo template. Niente roba incompatibile.

## Versione target confermata
- **YOOtheme Pro 5** è l'attuale stabile (rilasciato inizio 2026, build 5.0.28–5.0.34). Basato su **UIkit 3**. Compatibile **Joomla 5 e 6**.
- Pro 5 ha introdotto: consent manager, **date filter per dynamic content** (filtra per data pubblicazione/campo data, ideale per eventi e calendari), relazioni bidirezionali, language switcher. → Niente di tutto questo è un booking engine: **non confligge** con noi, anzi conferma che YT spinge su contenuti data-driven.
- Terze parti serie (es. JPro Studio) shippano già estensioni **compatibili YT Pro 5 / Joomla 6** → dev di elementi e custom source è **vivo e supportato oggi**.

## API elementi builder — struttura (verificata, matura)
Un elemento custom è una cartella con:
- `element.php` — config: `name` (univoco), `title`, `icon`, `iconSmall`, `element => true`, `width` (sidebar), `templates` (render + content), `fields`, `fieldset`, `defaults`, `transforms`, `updates`, opzionali `group` e `container`.
- `templates/template.php` — render con markup (variabili disponibili: `$node`, `$props`, `$children`, `$builder`). Helper `$this->el('div', [...])` per markup UIkit compatto; `$this->render(...)` per partial.
- `templates/content.php` — contenuto senza markup (per ricerca/portabilità).
- `images/icon.svg` + `iconSmall.svg`.

**Tipi di campo disponibili**: text, textarea, number, range, select, radio, checkbox, color, font, icon, image, link, location, video, editor. Layout via grid/group. Tab via `fieldset.type = 'tabs'`. Visibilità condizionale via `show`/`enable`.

**Dynamic content**: `source => true` sul campo abilita il mapping a sorgenti dinamiche.

**Migrazioni**: blocco `updates` keyed per versione (es. `'2.1.0' => function($node){...}`) per non rompere i layout salvati quando evolviamo l'elemento. → da usare fin da subito.

**Container/parent**: `container => true` + campo `content-items` per elementi con figli ripetibili.

## Punto cruciale per l'architettura
- **Gli elementi si auto-caricano da un pacchetto estensione Joomla**: YT Pro li scopre automaticamente. → La nostra **component `com_webgbooking` può spedire l'elemento dentro di sé**, niente child theme richiesto al cliente. Con `group => 'WebG'` finisce in un gruppo dedicato nella libreria, non nel calderone "Custom".
- Tooling: **YOOtheme Starter Plugin** + `composer create:element` per lo scaffold; c'è un example-element ufficiale su GitHub come reference.

## Due pattern di integrazione (li useremo entrambi)
1. **Builder element** (primario) = il widget di booking come elemento UIkit trascinabile, con pannello settings nativo nel builder (scelta tipo-appuntamento, staff, modalità A/B, colori). È esattamente ciò che Marco vuole: lo metti dove serve e lo configuri dal builder.
2. **Custom source / dynamic content** (secondario) = espone servizi/staff come sorgente dinamica per costruire liste/cataloghi con i layout YT (pattern alla JP CS Event Booking).

## Compatibilità CSS doppia (YT/UIkit oggi, Bootstrap domani)
- Engine di rendering unico = **web component con CSS scoped** (classi namespaced `wgb-*` o Shadow DOM) così non litiga né con UIkit (YT) né con Bootstrap 5 (core Joomla, SP Page Builder).
- L'**elemento YT** veste il widget con token/classi UIkit (uk-*). Il **modulo Bootstrap** standalone lo veste con classi core. Un engine, più skin.

## Rischi/verifiche ancora da fare in pratica (non bloccanti)
- [ ] Provare lo scaffold `composer create:element` contro una install reale YT Pro 5 e verificare auto-discovery quando l'elemento è dentro una component (non child theme).
- [ ] Confermare che il pannello settings con campi condizionali (`show`/`enable`) renda bene in YT Pro 5.
- [ ] Verificare il caricamento asset JS del widget dentro l'iframe del builder in fase di preview.

## Verdetto
Integrazione **fattibile, reale e sull'ultima versione**. L'API è stabile e documentata, gli elementi viaggiano dentro la component, e ci sono terze parti che già rilasciano su YT Pro 5/Joomla 6. Si parte dall'elemento builder; SP Page Builder 5 (addon via plugin indipendente dal core) e modulo Bootstrap sono evoluzione successiva, non condizionano l'architettura perché l'engine è disaccoppiato dalla skin.
