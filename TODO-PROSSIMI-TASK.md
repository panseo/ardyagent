# Task da sviluppare — Ardy Lab

---

## TASK 1 — Michela come "capo": notifiche WhatsApp dalla AI

**Cosa fa:**
Dopo ogni evento rilevante nelle chat (lead salvato, appuntamento fissato, cliente con dubbi/reclami/richieste strane), la AI manda automaticamente un messaggio WhatsApp a Michela (351 967 7973) come farebbe una segretaria efficiente.

**Tono:** breve, diretto, azionabile. Esempio:
> "Ciao Michela, ti aggiorno: Mario Rossi (Roma Prati) vuole un preventivo per rilaccatura divano. Ho fissato sopralluogo martedì 17/6 alle 10. Nessuna nota particolare."

**Dove si interviene:**
- `ardy-proxy.php` — aggiungere chiamata a funzione di notifica dopo `salva_lead_crm` e `fissa_appuntamento_calendario`
- `ardy-whatsapp-webhook.php` — aggiungere logica di inoltro verso numero Michela
- Nuova funzione `ardy-notifica-michela.php` (o integrata nel proxy) che chiama l'API WhatsApp Business con il messaggio di riepilogo

**Trigger da notificare:**
- Lead salvato nel CRM
- Appuntamento/sopralluogo fissato
- Cliente menziona un reclamo o insoddisfazione
- Cliente menziona un problema di pagamento
- Cliente chiede modifiche al lavoro già concordato
- Richiesta fuori standard (es. tempi urgenti, lavori particolari)

**Note tecniche:**
- Usare stesso sistema WhatsApp già presente (ardy-whatsapp-webhook.php + API Business)
- Il messaggio va da un numero "Ardy AI" a Michela — non da Michela a se stessa
- Mantenere log dei messaggi inviati per evitare duplicati nella stessa sessione

---

## TASK 2 — Segretaria antipatica: modulo WhatsApp per clienti morosi

**Cosa fa:**
Modulo WhatsApp dedicato alla gestione dei clienti che non pagano o che trovano scuse. Tono progressivo, formale, con riferimenti normativi. Tutela Ardy senza essere volgare, ma senza fare sconti.

**Flusso in 4 livelli di escalation:**

| Livello | Quando | Tono | Azione |
|---------|--------|------|--------|
| 1 | Primo sollecito | Cordiale, ricorda la scadenza | Messaggio WA automatico |
| 2 | Dopo 7 giorni senza risposta | Fermo, cita il preventivo firmato | Messaggio WA + email |
| 3 | Dopo altri 7 giorni | Formale, cita normativa | WA + email con allegato PDF |
| 4 | Oltre 21 giorni | Diffida formale | Bozza lettera da inviare manualmente |

**Riferimenti normativi da usare:**
- Art. 1453 C.C. — risoluzione del contratto per inadempimento
- Art. 1454 C.C. — diffida ad adempiere (termine perentorio)
- D.Lgs. 231/2002 — interessi di mora (8% oltre tasso BCE per contratti commerciali)
- Art. 2 D.Lgs. 206/2005 (Codice del Consumo) — trasparenza e correttezza anche a tutela di Ardy come operatore professionale
- Eventuale richiamo al preventivo firmato come contratto vincolante (proposta + accettazione = art. 1326 C.C.)

**Storico solleciti (nuovo DB o tabella):**
```
tabella: solleciti_pagamento
- id
- session_id / telefono
- nome_cliente
- importo_dovuto
- data_scadenza
- numero_sollecito (1-4)
- data_ultimo_sollecito
- risposta_cliente (testo o null)
- stato: APERTO / PAGATO / DIFFIDA / ARCHIVIATO
- preventivo_ref (link o testo del preventivo approvato)
- note_interne
```

**Verifica preventivo:**
Prima di ogni sollecito, il modulo verifica che nel preventivo approvato ci siano:
- Importo totale chiaro
- Modalità di pagamento
- Acconto versato (e importo residuo)
- Firma/accettazione del cliente
- Data di accettazione

Se manca qualcosa, avvisa Michela PRIMA di procedere con il sollecito.

**File da creare:**
- `ardy-solleciti.php` — API per gestire solleciti (crea, aggiorna stato, genera messaggio)
- `ardy-solleciti-system.txt` — system prompt "segretaria antipatica" per Claude
- Sezione nella dashboard Michela per visualizzare e gestire i morosi

**Note:**
- Solo via WhatsApp (non chatbot pubblico)
- Michela decide quando avviare il flusso (non automatico) — inserisce numero, nome, importo, preventivo
- La AI genera il messaggio del livello corretto, Michela approva prima dell'invio
- Mantenere tono professionale anche al livello 4: Ardy deve risultare sempre dalla parte della ragione

---

## Note generali

- Entrambi i task sono indipendenti, possono essere sviluppati separatamente
- Task 1 è più veloce (~2-3 ore di sviluppo)
- Task 2 richiede nuovo DB + UI dashboard + logica normativa (~1 giornata)
