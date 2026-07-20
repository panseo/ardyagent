# GBP — Test "secondo account" via OAuth 2.0 Playground (pronto al cassetto)

**Contesto:** Ravi (supporto Google, 17/07) dà l'allowlist del progetto 532339794075 come
"active and fully propagated" per `ardy.documenti@gmail.com`, eppure il check dà ancora
403-HTML. Ha proposto di aggiungere un **secondo account al Google Group** per isolare se
il blocco è specifico dell'account o del progetto. Secondo account fornito:
**`a.panseo@gmail.com`**.

**Quando eseguire:** SOLO dopo che Ravi conferma via email di aver aggiunto
`a.panseo@gmail.com` al Google Group di accesso.

**Perché il Playground:** permette di rifare la chiamata API autenticati come `a.panseo`
**senza toccare il token di produzione** (`ardy-gcal-token.json`, usato da
Calendar/Gmail/lead-monitor). Nessun rischio per la produzione.

**Perché con le NOSTRE credenziali OAuth (non quelle di default del Playground):** la
allowlist GBP è **per-progetto**. Il test è valido solo se la chiamata passa dal nostro
progetto allowlisted **532339794075**. Con il client di default del Playground si
testerebbe il progetto di Google, non il nostro → risultato inutile.

---

## Procedura passo-passo

### 0) Prerequisito (una volta) — client secret
Serve il **client secret** del client OAuth **"Ardy lab social"**
(client_id `532339794075-kg0asdq19rfsiap1ambmtsslvmov9ofr.apps.googleusercontent.com`).
- È salvato sul server in **`ardy-config.php`** (costante del client secret usata da
  `ardy-gcal.php`, es. `ARDY_GCAL_CLIENT_SECRET`). Recuperarlo da lì (WHM/cPanel).
- Se irrecuperabile (la console non lo rimostra più): in Cloud Console → Client → "Ardy lab
  social" → **"Add secret"** per generarne uno nuovo (non invalida quello vecchio).

### 1) Autorizzare il redirect URI del Playground sul client
In Cloud Console → **Client** → **"Ardy lab social"** → *URI di reindirizzamento
autorizzati* → **Aggiungi URI**:
```
https://developers.google.com/oauthplayground
```
**Salva.** (Applicazione fino a ~5 minuti.) — *(A fine test si può rimuovere, per ordine.)*

### 2) Aprire il Playground con le nostre credenziali
Vai su **https://developers.google.com/oauthplayground**
→ ingranaggio ⚙ in alto a destra → spunta **"Use your own OAuth credentials"** →
incolla:
- **OAuth Client ID:** `532339794075-kg0asdq19rfsiap1ambmtsslvmov9ofr.apps.googleusercontent.com`
- **OAuth Client secret:** *(dal punto 0)*

### 3) Inserire lo scope e autorizzare COME a.panseo
Nel riquadro sinistro ("Step 1"), nel campo **"Input your own scopes"** incolla:
```
https://www.googleapis.com/auth/business.manage
```
→ **Authorize APIs** → si apre il login Google:
- ⚠️ **fai login con `a.panseo@gmail.com`** (NON ardy.documenti!)
- comparirà l'avviso "app non verificata" (normale, è la nostra app con scope sensibile):
  **Avanzate → Vai a … (non sicuro)** → **Consenti**.

### 4) Scambiare il codice per il token
Torni al Playground ("Step 2") → clicca **"Exchange authorization code for tokens"**.
Deve comparire un **access token**.

### 5) Fare la chiamata di test
In "Step 3", nel campo dell'URL della richiesta, metti (metodo **GET**):
```
https://mybusinessaccountmanagement.googleapis.com/v1/accounts
```
→ **Send the request**.

### 6) Leggere il risultato — ESITO
- ✅ **HTTP 200 + JSON** (anche `{}` o lista account vuota) →
  **il problema è specifico di `ardy.documenti`**: il suo provisioning è di fatto rotto
  nonostante Ravi lo dia per attivo. → Rispondere a Ravi chiedendo di **ri-provisionare
  ardy.documenti** (oppure valutare di spostare l'integrazione su a.panseo, ma servirebbe
  che a.panseo sia gestore della scheda).
- ⛔ **HTTP 403 + pagina HTML** ("Error 403 (Forbidden)", "does not have permission to get
  URL") → **il blocco è a livello di PROGETTO 532339794075**, indipendente dall'account.
  → Rispondere a Ravi: "due account diversi, stesso 403 → il problema è il backend del
  progetto, non l'account: escalate." (usare/adattare `ardy-gbp-escalation-L2.md`).

In entrambi i casi: **screenshot del risultato del Playground** da allegare alla risposta.

### 7) Pulizia (opzionale, dopo il test)
Rimuovere il redirect URI del Playground dal client "Ardy lab social" (punto 1) e, se
creato un secret nuovo al punto 0, gestirlo di conseguenza.
