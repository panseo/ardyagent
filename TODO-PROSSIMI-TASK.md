# Ardy Lab — Task aperti & note utili

> TODO ripulito (giugno 2026): tenuti solo i task ancora aperti + le note operative
> che servono sempre. Tutto ciò che era già fatto **e testato in produzione** è stato
> rimosso (lo storico resta nei commit git).

---

## 🔧 NOTE OPERATIVE (servono sempre)

**Deploy sul server** (da root):
```
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```

**Log degli errori/eventi PHP** (utile per debug e per verificare il prompt caching):
```
/home/micoperibg/logs/ardyagent_ardy-lab_it.php.error.log
# es: grep "ARDY USAGE" <file> | tail -8   → righe in/out/cache_read/cache_write
```

**Auth degli endpoint chiamati via fetch**: NON usare `ardyRequireAuth()` negli endpoint
chiamati in fetch dal browser → su questo server l'header `Authorization` non arriva a PHP
in CGI/FPM e rifarebbe la login. Ci si affida al `.htaccess` (Basic Auth) come per gli altri.

**`session_id`**: sempre sanificato (no path traversal) prima di toccare i path file.

---

## ⏳ DA VERIFICARE (codice pronto, manca la prova)

- **Prompt caching ramo titolare (WhatsApp)** — nodo n8n aggiornato per usare `system_static`
  + `crm_context` (vedi `ardy-wa-prompt-caching-n8n.md`). ✅ Canale clienti verificato.
  ⏭️ **Manca**: prova dal numero VERO di Michela ("come va oggi? lead e urgenti") → Sole deve
  rispondere con dati reali del CRM. Se sì → A3 chiuso.
- **"Sole crea scheda da WhatsApp"** (marker `[[CREA_SCHEDA]]`) — codice + nodo n8n pronti.
  ⏭️ Prova end-to-end dal numero di Michela: dettare un cliente nuovo → conferma → "Scheda creata ✅"
  + scheda in dashboard (stato LEAD). Se errore: guardare le **Executions** del nodo Code in n8n.
- **Template `sollecito_pagamento`** — approvato e collegato. Da provare con un caso moroso vero.

---

## 🚧 BLOCCHI ESTERNI (azioni di Michela su Meta, non codice)

- **Carta di credito su Meta** → serve per sbloccare i messaggi **business→cliente/Michela
  fuori dalle 24h** (i **template**: `notifica_michela`, `sollecito_pagamento`, fasi). Senza,
  le **notifiche proattive a Michela non partono**. NB: Michela che scrive a Sole (lei inizia)
  resta GRATIS e non richiede la carta. Da fare in WhatsApp Manager / Meta Business → Fatturazione.
- **Template `aggiornamento_fase`** (notifiche fasi ai clienti) — **codice già pronto**
  (`inviaWhatsAppCliente()` in `ardy-pubblica-lavorazione.php`, 4 var: {{1}}nome {{2}}mobile
  {{3}}fase {{4}}link). Manca: creare+approvare il template su Meta, poi in `ardy-config.php`:
  `define('WA_TEMPLATE_FASI','aggiornamento_fase');`

---

## 📋 TASK DA SVILUPPARE

### ⚡ Risparmio risorse — Item B: compressione dati / disco
Server 200GB condiviso con 5 domini → lo spazio e il peso DB contano. (Item A "prompt caching"
fatto: vedi sezione "Da verificare".)
- **Foto all'upload**: ridimensionare/comprimere le immagini scheda quando entrano
  (`ardy-lead-foto.php` / `ardy-upload-video.php`) → meno peso su disco da subito.
- **gzip** sulle risposte JSON/HTML degli endpoint (se non già attivo a livello server/.htaccess).
- **Preventivi base64 in DB** (`preventivi.voci_json` LONGTEXT): pesano molto. Valutare se
  tenerli tutti o rigenerare il PDF on-demand / spostare le immagini fuori dal DB.

### 🗑️ Gestione archivio cliente (versione completa)
Contesto: i file pesanti sono **foto** (`ARDY_UPLOAD_DIR/<session>/`) e **reel**
(`reels/reel_<session>_*.mp4`). I PDF preventivo **incorporano le immagini in base64** →
cancellare le foto originali NON rompe i documenti. (Versione "leggera" PERSI già in produzione:
`ardy-archivia-persi.php` + pulsante 🧹 LIBERA SPAZIO PERSI; sposta in quarantena, Michela cancella a mano.)

Decisioni già prese con Michela:
1. **🧹 "Libera spazio" (solo immagini)** per i clienti **PAGATO**: cancella subito cartella foto +
   reel; tiene scheda/dati/preventivi+PDF/fasi/storico WA/pagina sito. Conferma forte; segna
   `foto_archiviate_at` sulla scheda.
2. **🗑️ "Elimina tutto" → CESTINO 30 giorni**: soft-delete `deleted_at` su `clienti` → vista
   Cestino con Ripristina; dopo 30gg purga DB (clienti/preventivi/fasi/wa_messaggi/solleciti) +
   tutti i file (foto, reel, PDF). NON tocca pagina WordPress né Media Library. Purga = sweep
   opportunistico (es. in `ardy-crm-api.php`, max N per load), niente cron necessario.
3. **Sicurezza/UX**: modale di conferma (per "Elimina tutto" far scrivere "ELIMINA"); endpoint
   Basic Auth; `session_id` sanificato.

Da costruire:
- *Backend* `ardy-elimina-cliente.php` (azioni `libera_spazio | cestina | ripristina | purga`),
  helper cancellazione file per session (riusa `ardy_clean_session`), colonne `deleted_at` /
  `foto_archiviate_at` auto-create.
- *API CRM* `ardy-crm-api.php`: escludere i `deleted_at` dalla lista normale; endpoint/param Cestino.
- *Dashboard*: pulsanti 🧹 (su PAGATO) e 🗑️ sulla scheda; vista Cestino; modali conferma.

### 💡 Briefing del mattino — opzionale rimasto
Parte "lavori" e "calendario" già in produzione (riepilogo titolare con IN LAVORAZIONE, URGENTI ≤4gg,
impegni Google Calendar). ⏭️ Rimasto (opzionale): **trigger "prima risposta del giorno"** — salvare
data ultimo briefing per numero così il riepilogo lungo parte da solo al primo "buongiorno" e non a
ogni messaggio. Senza, funziona quando Michela chiede "come va oggi?".

### Migliorie minori UX (bassa priorità)
- **Popup date all'attivazione stato IN_LAVORAZIONE**: al click del bottone stato, aprire un
  modale che chiede subito `inizio_lavoro` / `fine_lavoro_prevista` (riusa campi/salvataggio
  esistenti). Tocca solo `ardy-michela-app.html/.css`.
- **Filtro sidebar di default su ACCONTO** (o IN_LAVORAZIONE) invece di TUTTI, se Michela lavora
  quasi sempre sui lavori in corso. Da decidere in base al suo uso reale.

---

## 🔒 BACKLOG SICUREZZA (rimasti — priorità bassa)
> A monte c'è già difesa infrastrutturale (OVH Anti-DDoS, Fail2ban, ModSecurity WAF, mod_hulk):
> brute-force/DoS/flood sono contenuti a livello server; i rate-limit applicativi restano come
> difesa in profondità e per il **controllo costi** dell'API a pagamento.
- **OAuth Google senza `state`** (`ardy-gcal-auth.php`): aggiungere parametro `state` casuale verificato.
- **`get_stats` SQL** (`ardy-outreach-api.php`): unica query con interpolazione (oggi da array
  interno, non sfruttabile). Parametrizzare per pulizia.
- **`mode=download` preventivo**: serve qualsiasi PDF della cartella a chi è dietro Basic Auth
  (no ownership). Basso rischio (utente unico); legare alla sessione se servisse.

---

## ⚡ BACKLOG PERFORMANCE (rimasti)

### Alto impatto / basso sforzo
- **Ricerca telefono full-scan** (`ardy-wa-lookup.php`, `ardy-proxy.php`): `REPLACE(...) LIKE '%...'`
  impedisce gli indici. Fix: colonna `telefono_last9` normalizzata + indice, match esatto.
- **DDL su ogni request** (`SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS` in `ardy-proxy.php`,
  `ardy-stats.php`, `ardy-pubblica-lavorazione.php`, `ardy-libreria-api.php`,
  `ardy-reel-template-api.php`): spostare in una migrazione one-shot, togliere dal path di richiesta.
- **`ardy-crm-api.php`**: `SELECT *` su `clienti` senza `LIMIT`. Selezionare solo le colonne usate +
  paginazione + indice su `updated_at`.
- **Quick-win**: `finfo::file()` invece di `buffer(file_get_contents())` in `ardy-lead-foto.php`;
  memoizzare in `static` i system-prompt riletti da disco (`ardy-wa-lookup.php`, `ardy-proxy.php`).

### Da pianificare
- Cache PDF preventivo per content-hash + memoizzazione logo base64 (`ardy-preventivo.php`).
- Estrarre JS/CSS dalle HTML monolitiche + header cache/cache-busting.
- Rate-limit su APCu/Redis invece che su file (`ardy-proxy.php`).
- Unificare `dbConnect()` (mysqli) di `ardy-preventivo.php` sul PDO di `ardyDB()`.

---

## 📄 FUORI REPO / OPERATIVO
- **Termini & Condizioni su WordPress** (ardy-lab.it): aggiornarli coerenti con l'informativa
  GDPR già presente nel preventivo PDF. (Testo da preparare e incollare.)
- **Import preventivi storici**: strumento pronto (`ardy-import-preventivi.php`, CSV + PDF).
  Operativo con Michela: lei mette i PDF in una cartella Google Drive → si genera il CSV
  precompilato (Claude può leggere da Drive) e si importa. Dati mancanti (telefono/email/stato)
  da farsi dare a parte.

---

## ❌ SCENARI WhatsApp VALUTATI E SCARTATI (non riproporre)
- Foto/video su WhatsApp → attivano una fase di lavoro: **NO** (richiede pipeline media Meta assente).
- WhatsApp come "telecomando" unico della webapp: **NO**.
- Si tiene solo lo **Scenario 1** (creazione scheda da dati/PDF-template), già implementato.

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio** (system grosso + riepilogo CRM). → mitigato col
  **prompt caching** (item A): chat web ✅ verificata (cache_read ~7500 token, ~0,1× di costo);
  lavorazione ✅ deployata; WhatsApp ✅ deployato, titolare da verificare.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h
  (Utility ~3-4 cent/msg). Vedi blocco "carta di credito" sopra.
- Media Meta **scadono** → vanno scaricati subito col media ID.
