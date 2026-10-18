# WebG Booking — Analisi competitor (2026-06-23)

Obiettivo: capire cosa fanno di buono gli incumbent e dove lasciano scoperto, per blindare il posizionamento. Riferimento di mercato/feature = **Amelia** (WordPress), che su WP fattura molto ma non esiste su Joomla.

## Metodo
Censiti i booking/appointment Joomla attivi nella JED + ricerca su sync calendario, self-scheduling stile Calendly e integrazione YOOtheme Pro. Fonte feature verificate via siti ufficiali e JED.

---

## 1. Vik Appointments — e4j (il più serio)
**Cosa fa di buono**
- Modello dati maturo: dipendenti con turni di lavoro, servizi, durate, sedi.
- Pagamenti (PayPal, carta offline, pay on arrival), Zoom per videoconferenze.
- Statistiche/report per dipendente, multilingua servizi e dipendenti.
- Export appuntamenti CSV e ICS.

**Cosa MANCA (il varco)**
- **Sync calendario SOLO one-way**: esporta le prenotazioni VERSO Google/Apple/Outlook via ICS, ma **NON legge** gli eventi esterni. Se crei un impegno su Google, Vik non lo sa → **non può fare la meccanica Calendly** ("controlla la mia agenda e proponi gli slef liberi"). Limite confermato dalla loro KB.
- UX frontend datata.
- **Nessun elemento YOOtheme Pro nativo**.

## 2. OS Services Booking — Joomdonation/Ossolution
**Buono**: molti gateway (PayPal, Stripe, Square, Authorize.net, PayU), servizi, staff, calendario disponibilità.
**Manca**: sync calendario bidirezionale, UX moderna, self-scheduling Calendly-style, integrazione YOOtheme.

## 3. more Calendar — morejoomla.com (l'unico con elemento YT)
**Buono**: **ha un elemento YOOtheme Pro** (raro!), calendario nel builder.
**Manca/limite**: è orientato al **rental** (hotel, auto), mostra disponibilità di asset e **invia richieste a un form di terze parti**. Non calcola la disponibilità appuntamenti, non fa self-scheduling, non scrive sul calendario. Non è un competitor diretto sugli appuntamenti, ma dimostra che un elemento YT booking è fattibile.

## 4. JP CS Event Booking — JPro Studio (riferimento tecnico YT)
**Buono**: integra Joomdonation **Event Booking** dentro YOOtheme Pro come **custom source / dynamic content** (nome, descrizione, date, prezzo, capacità, ecc.). Attivamente aggiornato per **YOOtheme Pro 5 + Joomla 6**.
**Limite per noi**: è per **EVENTI** (iscrizioni a un evento con data fissa), non per **appuntamenti 1:1** con calcolo slot. → Non competitor diretto, ma **migliore riferimento di come si fa integrazione YT pulita** (loro usano il pattern "custom source"; noi useremo soprattutto il pattern "builder element").

## 5. Minori / fuori target
- **iCagenda**: agenda/eventi, non appuntamenti.
- **RSAppointments / RSform**: form-based, nessun availability engine vero.
- **Vik Booking / Solidres**: hotel/property rental, non appuntamenti.
- **JoomBooking / GBJ / BookPro**: minori, datati.

---

## Matrice del varco (whitespace)

| Capacità | Vik App. | OS Services | more Calendar | JP Event | **WebG Booking** |
|---|---|---|---|---|---|
| Appuntamenti 1:1 con calcolo slot | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Meccanica Calendly (legge busy → propone)** | ❌ (one-way) | ❌ | ❌ | ❌ | ✅ |
| **Sync calendario a DUE vie** | ❌ | ❌ | ❌ | ❌ | ✅ |
| Funziona ANCHE senza calendario (rule-based) | ✅ | ✅ | n/a | n/a | ✅ |
| **Elemento YOOtheme Pro nativo** | ❌ | ❌ | ✅ (rental) | ✅ (eventi) | ✅ (appuntamenti) |
| Email .ics + notifica backend | parziale | parziale | ❌ | parziale | ✅ |
| UX moderna | ❌ | ❌ | ~ | ✅ | ✅ |

**Conclusione**: il posizionamento difendibile non è "un altro booking Joomla" ma **"il primo booking appuntamenti Calendly-style nativo per YOOtheme Pro"**. I tre vantaggi che nessuno ha insieme: (1) legge i busy time e propone slot, (2) sync a due vie, (3) elemento builder YT nativo. Bonus: funziona anche senza collegare calendari (più flessibile di Calendly che lo obbliga).

## Cosa rubare ai migliori
- Da **Amelia**: modello dati Servizi→Durate→Categorie→Staff→Sedi→Risorse; pannelli per ruolo; reminder schedulati; deposito/coupon (fase Pro).
- Da **Calendly**: onboarding in 5 minuti; time zone del cliente; un solo "tipo appuntamento" configurabile al volo.
- Da **JP CS Event Booking**: pattern tecnico di integrazione YT (custom source per liste servizi) come complemento all'elemento di booking.
