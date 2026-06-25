# Checklist test — Dash Design (Tappa 1)

> Prove da fare **dopo il deploy** su `ardyagent.ardy-lab.it`. Spunta man mano.
> Copre tutto ciò che è stato costruito finora: progetti, costi/BOM, iterazioni,
> fasi-contenuto con foto, reel. Più i controlli di **non-regressione** sul lato cliente
> (perché `ardy-crea-reel.php` è stato modificato).

## 0. Deploy / migrazione
- [ ] `./deploy.sh` gira senza errori; la sezione "Ardy Migrate" mostra **OK** (o skip) per
      `progetti`, `progetto_materiali`, `progetto_iterazioni`, `fasi.progetto_id`, `INDEX fasi.progetto_id`.
- [ ] Ri-eseguire `php ardy-migrate.php` una seconda volta → tutto **skip**, nessun errore (idempotenza).

## 1. Accesso
- [ ] `/ardy-design-app.html` chiede il **Basic Auth** (stesso login della dash principale).
- [ ] La pagina carica (header "ARDY DESIGN", link "← Dash principale" funzionante).

## 2. Progetti (CRUD)
- [ ] "+ Nuovo progetto" → compilo titolo + tipo → "Crea progetto" → compare nella lista a sinistra.
- [ ] Riapro il progetto: i campi sono salvati. Modifico descrizione/materiali/scheda → "Salva" → persiste.
- [ ] La card in lista mostra **badge stato** e **tipo**.
- [ ] "Elimina" → sparisce dalla lista (soft delete, `deleted_at`).

## 3. Pipeline di stato
- [ ] Clic sui passi della pipeline: lo stato cambia e il badge si aggiorna.
- [ ] Lo stato terminale è **A CATALOGO** (nessun "VENDUTO").

## 4. Costi / BOM (margine)
- [ ] Aggiungo una riga **filamento** (g): unità si auto-imposta a `g`, "€ riga" si calcola live mentre digito.
- [ ] Aggiungo **manodopera** (h): il costo unitario si pre-compila a **€50,00** (default).
- [ ] "Costo prod." = somma righe × (1 + scarto%). Cambio **scarto %** sul progetto + "Salva" → costo si aggiorna.
- [ ] Imposto **prezzo vendita** → il **Margine** = prezzo − costo (verde se ≥0, rosso se <0); appare anche in lista.
- [ ] Elimino una riga → costo/margine si ricalcolano.

## 5. File congelato
- [ ] "⏟ Congela file" → inserisco STL/profilo/grammi/ore → stato passa a **FILE CONGELATO**, il bottone
      mostra "🔒 File congelato " + data.

## 6. Iterazioni prototipo (R&D)
- [ ] Aggiungo un'iterazione con nota → compare come **v1**; ne aggiungo un'altra → **v2** (numerazione auto).
- [ ] Elimino un'iterazione → sparisce.

## 7. Fasi-contenuto + foto
- [ ] "+ Aggiungi fase" → titolo + testo + **carico 2-3 foto** → "Salva fase": la fase compare con le **anteprime**.
- [ ] "Modifica" su una fase: le foto esistenti si vedono; ne **rimuovo una** (✕) e ne **aggiungo un'altra** → "Salva" → coerente.
- [ ] Le anteprime si caricano davvero (serving `?file=` dietro Basic Auth ok, niente 404/403).
- [ ] "Elimina" fase → sparisce; (verifica lato server che la cartella foto sia rimossa).

## 8. Reel da progetto
- [ ] Con **almeno 2 foto** totali tra le fasi → "🎬 Crea reel" → dopo il montaggio compare il **video** (anteprima + download) e la **caption**.
- [ ] Con **meno di 2 foto** → messaggio d'errore chiaro ("Servono almeno 2 foto…"), nessun crash.
- [ ] Il video è verticale 9:16, mostra le foto delle fasi; il file sta in `/reels/`.

## 8b. Generazione AI del testo
- [ ] Nel form fase scrivo 2-3 righe grezze → "✨ Scrivi con AI" → il testo diventa un **post professionale** (tono brand, non cliente), fedele alla bozza.
- [ ] Il testo generato è **modificabile** prima di "Salva fase".
- [ ] Con il campo testo vuoto → "Scrivi prima due-tre righe" (nessuna chiamata sprecata).
- [ ] (Se `ARDY_API_KEY` non è configurata → errore pulito "Generazione non riuscita", niente crash.)

## 9. NON-REGRESSIONE lato cliente (importante: ho toccato `ardy-crea-reel.php`)
- [ ] Creo un **reel da un CLIENTE** dalla dash principale come sempre → funziona identico a prima.
- [ ] Le **fasi dei clienti** (lista, pubblicazione, foto) non sono cambiate.

## 10. Storage (nota Backblaze)
- [ ] Le foto delle fasi di progetto finiscono in `ARDY_UPLOAD_DIR/progetti/<id>/fasi/<faseid>/`.
- [ ] (Promemoria) alla migrazione su **Backblaze B2** cambiano solo path di scrittura + serving `?file=`,
      il DB salva solo i nomi file. Nessun blocco previsto.

---
### Esito
- Annotare qui sotto cosa non torna (con quale passo) → poi si sistema prima della slice publish WP/social.
