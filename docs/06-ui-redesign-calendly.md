# WebG Booking — redesign grafico widget (rif. Calendly) — TODO

Marco (23-06-2026): "graficamente non mi piace affatto, prendi spunto da qui" + screenshot di una pagina **Calendly reale**. Da applicare **dopo** lo step funzionale (prenotazione reale). Questo doc fissa il target.

## Layout di riferimento (Calendly) — due colonne in una card
**Colonna sinistra (info evento):**
- Logo brand in alto (es. logo webG).
- **Avatar** dell'host (foto) + **nome** host (es. "Marco Galassi").
- **Titolo evento** grande/bold (es. "Riunione - 1 ora").
- **Durata** con icona orologio (es. "🕐 1 h").
- In basso: link **"Impostazioni cookie" · "Politica sulla privacy"**.

**Colonna destra (selezione):**
- Heading **"Seleziona data e ora"**.
- **Calendario mensile** pulito: nav `‹ giugno 2026 ›`, intestazioni `lun mar mer gio ven sab dom`, date in griglia.
- Stati date: **disponibile** = numero in **blu** con cerchio chiaro al hover/disponibilità; **selezionata** = cerchio pieno chiaro; **oggi** = pallino sotto il numero; **non disponibile** = grigio tenue non cliccabile.
- Sotto il calendario: **"Fuso orario"** con selettore (es. "Ora dell'Europa centrale (12:30)") con icona globo.

## Note di stile
- Card bianca, bordi morbidi, ombra leggera, molto **arioso** (spaziatura generosa) ma **sobrio**.
- Niente bottoni "pieni" per le date: le date sono numeri cliccabili con cerchio, non pulsanti UIkit squadrati.
- Tipografia pulita, colore primario blu (o accento configurabile).
- Deve restare responsive: su mobile le due colonne si impilano (info sopra, calendario sotto).

## Da rendere configurabile (già chiesto da Marco)
- Avatar/host name/titolo/durata (campi elemento o da servizio/staff del componente).
- Colore accento, colore intestazioni, densità.
- Logo (campo immagine o dal brand del tema).

## Implementazione prevista
Riscrivere `templates/template.php` del widget: struttura a due colonne (UIkit `uk-grid` `uk-child-width-1-2@m`), calendario con celle "a cerchio" (no pulsanti squadrati), selettore fuso orario, footer link. Mantenere il flusso a step (data → ora → dati → conferma) ma con questa estetica. Lo step "ora" e "dati" appaiono nella colonna destra al posto del calendario quando si avanza.
