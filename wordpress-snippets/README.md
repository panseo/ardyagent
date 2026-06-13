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

## Mappa snippet (da screenshot 13/06 — da confermare con l'export)

| File | Nome in WPCode | Tipo | Posizione | Centralizzabile? |
|---|---|---|---|---|
| `performance.php` | performance | php | — | No (PHP backup-only) |
| `chat-corsi.html` | Chat per i corsi | html | footer | Sì (front-end) |
| `pulsante-corsi.php` | Pulsante corsi | php | — | Da valutare |
| `pulsante-flottante-ovunque.php` | Pulsante flottante ovunque | php | — | Da valutare (contiene anche il loader lavorazione!) |
| `snippet-yoast.php` | Snippet yoast | php | — | No (SEO backup-only) |
| `corsi-dato-strutturato.php` | Corsi dato strutturato | php | — | No (schema backup-only) |
| `ardychat.js` | ardychat | js | footer | Sì — chat generale del sito (→ `ardy-proxy.php`, elementi `ac-*`) |

> I nomi file sono provvisori finché non arriva l'export ufficiale.

---

## Piano centralizzazione (passo 2, dopo il backup)

- **Centralizzare SOLO i widget front-end** (chat/pulsanti js/html): il loro contenuto va in
  file serviti dal nostro server (root del repo, già deployati), lasciando in WordPress una
  sola **riga-loader** (`<script src=".../file.js">`).
- Gli snippet **PHP** (hook/SEO/schema: `performance`, `snippet-yoast`, `corsi-dato-strutturato`)
  **restano backup-only** — non si spostano (girano dentro WordPress, dipendono da hook/funzioni WP).
- Procedere **un widget alla volta**, verificando sul sito prima di passare al successivo.
</content>
