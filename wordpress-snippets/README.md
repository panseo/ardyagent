# Backup snippet WordPress (WPCode + Divi)

Backup versionato dei sorgenti che oggi vivono **solo dentro WordPress** (plugin WPCode +
integrazioni del tema Divi). Servono per non perderli se WordPress si rompe/migra e come
base per la **centralizzazione** dei widget front-end (vedi sotto).

> ⚠️ **IMPORTANTE — questo è solo un BACKUP.**
> Modificare un file qui **NON aggiorna WordPress**: va ricopiato a mano nello snippet
> corrispondente (WPCode → Code Snippets → snippet → incolla → Salva). Finché non si fa la
> *centralizzazione vera* (loader nel sito → file servito dal nostro server), questi file
> sono una copia di sicurezza, non la fonte attiva.
>
> Questa cartella è **esclusa dal deploy** (`deploy.sh` la salta; `.cpanel.yml` copia solo
> i file root + `assets/`/`phpmailer/`), quindi non finisce mai nel document root del sito.

---

## Come aggiornare il backup (export WPCode)

1. WordPress → **WPCode → Tools/Strumenti → Export** → "Export All" → scarica il JSON.
2. Incollare/consegnare il JSON: viene **splittato** in un file per snippet, con il nome
   corretto e l'estensione giusta (`.php` / `.html` / `.js`), e si aggiorna la mappa qui sotto.
3. Il loader della pagina lavorazione **non** è in WPCode (è nelle Integrazioni di Divi):
   è già nel repo come `../wpcode-snippet-lavorazione.html`.

---

## Mappa snippet (export WPCode del 13/06/2026, 7 snippet)

| File | Nome WPCode | ID | Tipo | Posizione | Cosa fa | Centralizzabile? |
|---|---|---|---|---|---|---|
| `ardychat.js` | ardychat | 15170 | js | site_wide_footer | Chat generale del sito sulla pagina `/ardy-agent/` (→ `ardy-proxy.php`, elementi `ac-*`) | ✅ **Centralizzato** → `../ardy-chat-site.js` (vedi sotto) |
| `chat-corsi.html` | Chat per i corsi | 15246 | html | site_wide_footer | `<script>` che adatta `/ardy-agent/?corso=` in "modalità corso" (intestazione, suggerimenti, primo messaggio) | ✅ Sì (è solo un `<script>`) |
| `pulsante-flottante-ovunque.php` | Pulsante flottante ovunque | 15243 | php | everywhere | Bottone flottante "Chatta con Ardy" → `/ardy-agent/`; salta le pagine `/lavori-in-corso/`, `/project/` e categoria 102 | ⚠️ Parziale (la condizione è PHP server-side; markup/CSS sì) |
| `pulsante-corsi.php` | Pulsante corsi | 15245 | php | everywhere | Filtro `the_content`: CTA "Info su questo corso" sulle 9 pagine corso → `/ardy-agent/?corso=` | ⚠️ Parziale (mappa slug + filtro PHP) |
| `corsi-dato-strutturato.php` | Corsi dato strutturato | 15240 | php | everywhere | Schema.org `Course` sui corsi (SEO, hook `wp_head`) | ❌ No (backup-only) |
| `snippet-yoast.php` | Snippet yoast | 15241 | php | everywhere | Schema `LocalBusiness` via filtro `wpseo_schema_organization` (SEO) | ❌ No (backup-only) |
| `performance.php` | performance | 15267 | php | everywhere | Ottimizzazioni performance (hook WP) | ❌ No (backup-only) |

> **Nota:** il *loader della pagina lavorazione* NON è in questi snippet (la vecchia nota nel
> TODO era imprecisa): sta nelle **Integrazioni di Divi** ed è già nel repo come
> `../wpcode-snippet-lavorazione.html`. Il "Pulsante flottante ovunque" è solo il bottone CTA.
>
> **Mini-pendenza:** il testo del bottone in `pulsante-flottante-ovunque.php` dice ancora
> "Chatta con **Ardy**" (l'`aria-label` è già "Sole"). Da uniformare a "Sole" quando si tocca.

---

## ✅ Widget già centralizzati (fonte attiva = file servito dal server)

| Widget | File servito (root repo) | URL servito | Loader in WPCode |
|---|---|---|---|
| `ardychat` | `../ardy-chat-site.js` | `https://ardyagent.ardy-lab.it/ardy-chat-site.js` | snippet HTML (footer) con `<script src=...>` |

**Loader da incollare in WPCode** (snippet "ardychat" → cambia tipo a **HTML**, posizione footer,
sostituisci tutto il contenuto con):
```html
<script src="https://ardyagent.ardy-lab.it/ardy-chat-site.js" defer></script>
```
Da quel momento si modifica solo `ardy-chat-site.js` nella repo + deploy; WordPress non si tocca più.
(Il file `ardychat.js` qui resta come backup dello stato pre-centralizzazione.)

---

## Piano centralizzazione (passo 2, dopo il backup)

- **Centralizzare SOLO i widget front-end** (chat/pulsanti js/html): il loro contenuto va in
  file serviti dal nostro server (root del repo, già deployati), lasciando in WordPress una
  sola **riga-loader** (`<script src=".../file.js">`).
- Gli snippet **PHP** (hook/SEO/schema: `performance`, `snippet-yoast`, `corsi-dato-strutturato`)
  **restano backup-only** — non si spostano (girano dentro WordPress, dipendono da hook/funzioni WP).
- Procedere **un widget alla volta**, verificando sul sito prima di passare al successivo.
</content>
