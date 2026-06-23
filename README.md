# WebG Booking

Estensione di **prenotazione appuntamenti per Joomla 5/6** con elemento nativo **YOOtheme Pro 5** (builder UIkit). Sviluppata da [WebG](https://www.webg.it).

> ⚠️ **Stato: pre-release (0.1.0, "tracer").** Questo primo pacchetto valida l'integrazione con il builder YOOtheme Pro su un'installazione reale. Non è ancora il prodotto completo.
>
> 🔒 **Nessun segreto nel codice.** Le credenziali dei calendari (OAuth/CalDAV) vengono inserite a runtime dall'utente e cifrate nel suo database: questo repository non contiene e non deve mai contenere token, password o chiavi.

## Requisiti
- Joomla **5** o **6**
- **YOOtheme Pro 5** (l'elemento si registra nel builder senza child theme)

## Installazione
1. Joomla → *Sistema → Installa → Installa da URL*, incolla:
   ```
   https://raw.githubusercontent.com/WebG-it/webgbooking/main/dist/plg_system_webgbooking-0.10.0.zip
   ```
2. *Sistema → Plugin* → abilita **"System - WebG Booking"**.
3. YOOtheme Customizer → **Add Element** → gruppo **WebG** → **Booking**.

Gli aggiornamenti successivi arrivano via **Sistema → Aggiorna** (Joomla Update System).

## Licenza
GNU General Public License v2 o successiva (vedi [LICENSE](./LICENSE)).
