# Pubblicazione post su Google Business Profile (fasi di lavorazione)

> ✅ **STATO (04/07/2026): approvazione Google arrivata, toggle riabilitato.**
> Richiesta `3-7851000041139` (quota 0 → 300 QPM) sbloccata. Il toggle Google nel
> pannello social è ora attivo e collegato (vedi sotto). Prima di considerarlo live:
> confermare `ardy-gbp-check.php` verde e fare un post di test reale sulla scheda.

## Idea
Quando una fase di lavorazione viene pubblicata, oltre a sito + social, si può
pubblicare un **post (localPost)** sulla scheda Google "Ardy di Michela Panella".
È un **passo separato e manuale**, identico nello spirito ai social
(`ardy-pubblica-social.php`): Michela/Andrea rivedono il testo e decidono.

## Architettura (server-side, NON serve n8n)
A differenza dei social (che passano da un webhook n8n → Graph API), il post
Google è gestito **interamente sul server**, perché il token OAuth è già lì:

```
Dashboard ──POST JSON──> ardy-gbp-post.php ──> ardy-gbp.php ──> mybusiness.googleapis.com/v4
                                                  │
                                                  └─ riusa ardy-gcal-token.json (scope business.manage)
```

File:
- **`ardy-gbp.php`** — helper: `gbp_create_local_post($testo, $imageUrl, $ctaUrl)`,
  risoluzione+cache di account/location (`ardy-gbp-location.json`), token condiviso
  con Calendar/Gmail.
- **`ardy-gbp-post.php`** — endpoint HTTP (CORS, POST JSON), dietro Basic Auth.
- **`ardy-gbp-check.php`** — diagnostica accesso/quota (già esistente).

## Payload accettato da `ardy-gbp-post.php`
Stesso formato che la dashboard già produce per i social, quindi riusabile 1:1:

```json
{
  "testo":     "Testo del post (max 1500 char, troncato se più lungo)",
  "immagini":  ["https://ardy-lab.it/.../foto-fase.jpg"],   // usa la prima; opzionale
  "post_link": "https://ardy-lab.it/lavorazione/...",        // bottone "Scopri di più"; opzionale
  "fase":      "Levigatura",      // solo per log
  "mobile":    "Credenza '800"    // solo per log
}
```
Accetta anche `immagine` (stringa singola) e `testo_social` come alias di `testo`.

Risposta:
```json
{ "success": true,  "name": "accounts/123/locations/456/localPosts/789" }
{ "success": false, "error": "messaggio leggibile" }
```
Su 401/403/429 il messaggio è esplicito: *"accesso API non ancora attivo"*.

## Collegamento alla dashboard — ✅ FATTO (04/07/2026)
Nel pannello **"📲 Vuoi pubblicare sui social?"** di `ardy-michela-app.html` il toggle
**Google** in `socialDestHtml` è ora attivo. `inviaSocial()` smista la richiesta:
piattaforme `facebook`/`instagram` vanno a `ardy-pubblica-social.php` (invariato,
via n8n); `google` va **in aggiunta**, in parallelo, a `ardy-gbp-post.php` con lo
stesso payload (`testo`/`testo_social`, `immagini`, `post_link`, `fase`, `mobile`,
`cliente`). L'esito è unito: successo solo se tutte le piattaforme scelte vanno a
buon fine; un fallimento parziale lo dice esplicitamente (ed elenca quelle uscite
comunque, per non doppiare la pubblicazione al retry).

> Google e i social sono indipendenti: si può pubblicare solo su Google, solo sui
> social, o su entrambi. Nessuna modifica al nodo n8n "Meta".

## Note operative
- **Foto**: `sourceUrl` deve essere un URL **pubblico** (le foto fase su
  `ardy-lab.it` lo sono). Niente upload binario.
- **Cache location**: `ardy-gbp-location.json` viene creato alla prima chiamata
  riuscita (gitignored, escluso dal deploy). Per forzare il refresh, cancellarlo.
- **Override manuale** (se la scheda giusta non fosse la prima restituita): definire
  in `ardy-config.php` la costante `GBP_PARENT` = `"accounts/<id>/locations/<id>"`.
- **Lingua/tipo**: post `STANDARD`, `languageCode: it`, CTA `LEARN_MORE`.
- Le statistiche dei post (views/click) NON sono in v4: stanno nella Business
  Profile **Performance API** (eventuale sviluppo futuro).
