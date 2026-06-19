# Analisi — Firewall host: sostituto di firewalld

> Contesto (19/06/2026): firewalld è stato **disabilitato di proposito** perché, dopo un
> aggiornamento notturno (nftables salito a `el9_8`, firewalld fermo a `el9_7`), il daemon Docker
> non parte più con firewalld attivo (`failed to create NAT chain DOCKER: COMMAND_FAILED:
> INVALID_IPV: 'ipv4' is not a valid backend`). Per ora l'host è coperto da **OVH Anti-DDoS +
> Fail2ban + ModSecurity (WAF)** e n8n è bindato solo su `127.0.0.1`. Va deciso il firewall host
> definitivo. Vedi anche NOTE OPERATIVE in `TODO-PROSSIMI-TASK.md`.

---

## TL;DR (la versione in tre righe)
- **Raccomandazione: installare `csf` (ConfigServer Security & Firewall)**, l'opzione 2. È lo standard
  di fatto su cPanel/WHM (di cui questo VPS è dotato), convive con Docker (chain `DOCKER-USER` +
  opzioni native nelle versioni recenti) e **non dipende da firewalld/nftables** → non ricade nel bug
  che ci ha costretti a disabilitare firewalld.
- **Prerequisito non negoziabile, PRIMA di decidere/installare**: fotografare la superficie esposta
  dell'host (`ss -tlnp`) e confermare che MySQL ascolti **solo su localhost**. Senza questa foto,
  qualsiasi firewall si configura "alla cieca".
- L'host **non è "nudo" oggi**: OVH Anti-DDoS (L3/L4), Fail2ban (brute-force), ModSecurity (L7),
  SSH/FTP **disabilitati**, n8n su `127.0.0.1`. Il rischio attuale è *moderato, non critico* → si può
  procedere con calma e senza fretta, ma csf chiude il buco "nessun firewall di stato a livello host".

---

## 0. Vincoli specifici di QUESTO server (da tenere a mente)
| Vincolo | Conseguenza per il firewall |
|---|---|
| **WHM/cPanel** in uso | csf è *progettato* per cPanel: config di default già cPanel-aware (porte 2082-2087, 2095-2096, ecc.). Forte argomento pro-csf. |
| **SSH attivo come root, auth a chiave** + WHM | (a) **Due canali di accesso indipendenti** (SSH e WHM) → se uno si blocca alzando il firewall, l'altro resta come rete di sicurezza: il rischio di lockout totale crolla. (b) I comandi qui sotto si possono lanciare via **SSH** (o WHM Terminal). (c) **La porta 22 entra nella superficie**: va messa in `TCP_IN` di csf (e idealmente whitelistata sul tuo IP), altrimenti il primo `csf -r` ti chiude fuori da SSH. Stesso per 2086/2087 (WHM, già in default cPanel). (d) **Login solo a chiave** (niente password): il brute-force password su SSH è impossibile → il ruolo di LFD/Fail2ban su SSH è marginale; consigliato comunque confermare `PasswordAuthentication no` in `sshd_config`. |
| **Docker** (n8n) gestisce le sue regole iptables | Il firewall host NON deve azzerare le chain di Docker a ogni restart. È il nodo tecnico centrale (vedi §3). |
| **n8n bindato su `127.0.0.1:5678`** | n8n **non è pubblicamente esposto** a prescindere dal firewall: Docker non pubblica una porta su `0.0.0.0`, quindi non crea regola NAT pubblica. Superficie n8n ≈ nulla già oggi. |
| **nftables `el9_8` vs firewalld `el9_7`** | È la causa-radice del problema firewalld. csf usa **iptables(-legacy/nft) direttamente**, non il daemon firewalld → aggira il disallineamento. |

---

## 1. Le tre opzioni (dal TODO) — pro e contro onesti

### Opzione 1 — Lasciare così (nessun firewall host, Docker gestisce iptables)
- **Pro**: zero lavoro; nessun rischio di lockout; Docker e le difese esistenti già attive.
- **Contro**: nessun firewall di **stato** a livello host → ogni porta che un servizio apre su
  `0.0.0.0` è esposta finché non lo si nota a mano; nessun rate-limiting/port-knocking centralizzato;
  Fail2ban senza un firewall "vero" alle spalle è più debole (banna via iptables, ma senza policy di
  default-deny in ingresso il controllo è parziale). **Accettabile come stato-ponte, debole come scelta definitiva.**
- **Verdetto**: ok temporaneamente (è dove siamo ora), **non** come soluzione finale.

### Opzione 2 — Installare `csf` ⭐ RACCOMANDATA
- **Pro**: standard cPanel/WHM (config di default già pensata per questo ambiente, interfaccia in
  WHM); firewall di stato con default-deny configurabile; integra/sostituisce Fail2ban con LFD
  (Login Failure Daemon); **non usa firewalld** (niente bug nftables); convive con Docker tramite la
  chain `DOCKER-USER` e — nelle versioni recenti — opzioni native (vedi §3); whiteliste di default
  le porte cPanel/WHM → minimo rischio di auto-lockout.
- **Contro**: una dipendenza in più da mantenere; va configurato con attenzione al primo giro
  (modalità TESTING prima di LIVE); va verificata l'interazione con Docker sulla versione installata.
- **Verdetto**: miglior rapporto robustezza/idoneità all'ambiente. **Procedere con questa.**

### Opzione 3 — Far rientrare firewalld con downgrade di nftables a `el9_7`
- **Pro**: torneremmo allo stato "noto"; nessun nuovo strumento.
- **Contro**: **rischioso** — il downgrade di nftables tocca dipendenze di sistema su AlmaLinux 9 e
  potrebbe trascinarsi dietro altri pacchetti `el9_8`; è una pezza temporanea (il prossimo update
  rialza nftables e ricasca tutto); combatte contro la direzione degli update invece di assecondarla.
- **Verdetto**: **da evitare.** Si rimette il problema appena ri-fixato.

---

## 2. Prerequisito: fotografare la superficie ESPOSTA (fare PRIMA di tutto)
Questi comandi vanno lanciati come **root** (via SSH o WHM Terminal). Servono a sapere cosa proteggere
e a configurare csf con le porte giuste (niente lockout, niente porte aperte di troppo).

```bash
# 1) Porte TCP in ascolto + processo che le tiene (la "foto" della superficie)
ss -tlnp

# 2) Idem UDP (DNS, ecc.)
ss -ulnp

# 3) MySQL DEVE essere su localhost: attese 127.0.0.1:3306 o ::1, MAI 0.0.0.0:3306
ss -tlnp | grep -E '3306|mysqld'

# 4) Cosa pubblica Docker su 0.0.0.0 (atteso: NIENTE per n8n, è su 127.0.0.1)
docker ps --format '{{.Names}}\t{{.Ports}}'

# 5) Stato attuale del firewalling host (conferma firewalld disattivo + regole iptables vive)
systemctl is-enabled firewalld    # atteso: disabled
systemctl is-active  firewalld    # atteso: inactive
iptables -S | head -50            # vedere le chain (incluse DOCKER, DOCKER-USER)

# 6) È già installato csf? (per non installarlo due volte)
test -e /etc/csf/csf.conf && echo "csf presente" || echo "csf assente"
```

**Cosa cercare nell'output di `ss -tlnp`** (porte tipiche di un host cPanel — ognuna va capita, non
chiusa alla cieca): `22` (SSH — **attivo, va tenuto aperto**), `80/443` (Apache), `2086/2087` (WHM),
`2082/2083` (cPanel), `2095/2096`
(webmail), `2077/2078` (WebDAV), `25/465/587/110/995/143/993` (mail, se l'host fa mail — qui la mail
è su Aruba, quindi probabilmente **non** servono aperte), `53` (DNS se è nameserver), `3306` (MySQL —
**deve** essere solo localhost). Tutto ciò che ascolta su `0.0.0.0` e non riconosciamo è un campanello.

> ⚠️ Se MySQL risultasse in ascolto su `0.0.0.0:3306`, **questa è la priorità #1**: va messo
> `bind-address = 127.0.0.1` (in `my.cnf`) a prescindere dalla scelta del firewall.

---

## 2-bis. Foto della superficie — RILEVATA il 19/06/2026 (via SSH root)
`ss -tlnp` / `ss -ulnp` / `docker ps` sul VPS. Stato di partenza reale:

**✅ A posto — privati su localhost (nessuna azione):**
| Servizio | Bind | Note |
|---|---|---|
| MariaDB | `127.0.0.1:3306` | DB app — **non esposto** ✅ |
| n8n (docker-proxy) | `127.0.0.1:5678` | container `n8n_app` `127.0.0.1:5678->5678/tcp` — **non esposto** ✅ |
| Redis | `127.0.0.1` / `[::1]:6379` | **non esposto** ✅ |
| cPHulkd | `127.0.0.1:579` | brute-force protection cPanel (già attiva) |
| chronyd (NTP) | `127.0.0.1:323` | localhost |
| pdns control | `127.0.0.1:953` | localhost |

`firewalld` = **disabled** (confermato) · `csf` = **assente** (da installare).

**🌐 Esposti su `0.0.0.0`/`[::]` (la superficie da governare con csf):**
- **Legittimi da tenere aperti**: `22` (SSH), `80`/`443` (Apache), `2082/2083` (cPanel),
  `2086/2087` (WHM), `2095/2096` (webmail UI), `2077/2078/2079/2080/2091` (cpdavd WebDAV/CalDAV),
  `53` (PowerDNS).
- **⚠️ Da CHIUDERE — `111` (rpcbind, TCP+UDP)**: portmapper, serve solo a NFS. Inutile qui e noto
  vettore di amplificazione DDoS. **NFS verificato NON in uso** (19/06: nessun mount nfs reale —
  `rpc_pipefs` è solo plumbing kernel; `rpcinfo -p` mostra solo il portmapper, nessun servizio NFS).
  → `systemctl disable --now rpcbind rpcbind.socket` + csf non lo whitelista.
- **✅ DECISO — chiudere lo stack mail (25,110,143,465,587,993,995,4190)**: la posta è tutta su
  **Aruba** (MX) + invio via **Brevo**; questo host non riceve mail da fuori. Le porte protocollo mail
  si chiudono (exim resta vivo per la consegna locale/outbound su loopback, che csf consente sempre).
- **❓ Da valutare — `53` (PowerDNS)**: i NS autoritativi sono **Cloudflare**; il pdns locale forse non
  serve pubblico. Non urgente (autoritativo, non resolver aperto). **Tenuto aperto per ora.**
- **✅ DECISO — rpcbind (111)**: disabilitare il servizio + lasciarlo chiuso in csf (no NFS in uso).

**Config csf risultante (decisa il 19/06):**
```
# /etc/csf/csf.conf  — mail chiusa, 111 esclusa, 53 tenuta
TCP_IN  = "22,53,80,443,2077,2078,2079,2080,2082,2083,2086,2087,2091,2095,2096"
UDP_IN  = "53"
# TCP_OUT/UDP_OUT: lasciare permissivi all'inizio (Sole fa molte chiamate in uscita:
#   API Claude, Meta, Brevo SMTP, Cloudflare, git, docker pull). L'egress filtering
#   e' una rifinitura successiva, da fare testando che Sole non smetta di funzionare.
```
> Webmail UI (2095/2096) e cPanel/WHM (2082-2087) restano aperti: sono i pannelli di controllo, non
> protocolli mail. Se in futuro si vuole chiudere anche pdns, togliere `53` da TCP_IN/UDP_IN.

---

## 3. Il nodo tecnico: csf **e** Docker insieme (senza rompersi a vicenda)
Docker scrive da sé le sue regole iptables (chain `DOCKER`, `DOCKER-USER`, NAT). Il rischio è che csf,
quando (ri)applica le sue regole, **flushi** le chain e spezzi il networking dei container già attivi
(Docker le ri-crea all'avvio del container, ma non "al volo" su un csf restart). Due modi, complementari:

1. **Chain `DOCKER-USER`** — è la chain che Docker **non** tocca e che è pensata apposta per le regole
   utente/firewall: sopravvive ai restart di Docker. Eventuali regole custom verso i container vanno lì.
2. **Opzioni native di csf** — le versioni recenti di csf (v14+) hanno aggiunto supporto Docker
   esplicito in `/etc/csf/csf.conf` (es. `DOCKER` e relative `DOCKER_NETWORK`/`DOCKER_DEVICE`): csf
   "lascia stare" le chain di Docker e si integra invece di azzerarle.
   - ⚠️ **Da verificare sulla versione effettivamente installata** (`csf --version`): se l'opzione
     nativa non c'è, si usa il fallback storico `/usr/local/csf/bin/csfpost.sh` che **ri-applica** le
     regole Docker dopo ogni (re)start di csf.

**Nel nostro caso il rischio è minimo** perché n8n è su `127.0.0.1:5678` (nessuna pubblicazione su
`0.0.0.0`, nessuna regola NAT pubblica da preservare). Resta comunque buona pratica abilitare il
supporto Docker in csf per non spezzare il bridge interno dei container ai restart di csf.

**Test di non-regressione obbligatorio dopo aver alzato csf**: `docker start n8n_app` + aprire
`https://n8n.ardy-lab.it` e mandare un messaggio WhatsApp di prova a Sole (il ramo WA passa da n8n).
Se Sole risponde, il bridge Docker è intatto.

### ✅ Soluzione Docker VERIFICATA (19/06, csf v16.20 cPanel)
Topologia reale: n8n_app è sul bridge **`br-b118407a7c22`** (rete `n8n_default`, **`172.18.0.0/16`**,
gw .1, container .2), **non** su `docker0` (172.17, DOWN/inutilizzato). Pubblicazione `127.0.0.1:5678`.

Servono **DUE** cose, perché coprono due percorsi diversi:
1. **`DOCKER=1` puntato sul bridge giusto** → copre il FORWARD (egress del container verso Internet:
   API Claude/Meta, chiamate ai `.php`):
   ```
   DOCKER = "1"
   DOCKER_DEVICE   = "br-b118407a7c22"
   DOCKER_NETWORK4 = "172.18.0.0/16"
   DOCKER_NETWORK6 = ""
   ```
2. **`ETH_DEVICE_SKIP = "br-b118407a7c22"`** → copre il percorso **OUTPUT locale**. Poiché n8n è
   pubblicato su `127.0.0.1`, Docker usa **docker-proxy**: un processo dell'host (UID 0) che si connette
   a `172.18.0.2:5678`. Questo è traffico OUTPUT, **non** FORWARD → l'integrazione `DOCKER=1` da sola
   **non lo copre** e `TCP_OUT` lo droppa (`*TCP_OUT Blocked* OUT=br-b118... DST=172.18.0.2 DPT=5678`).
   Escludendo il bridge dal filtraggio locale, l'hop docker-proxy→container passa.

Esito verificato: n8n `HTTP=200` stabile, nessun nuovo blocco `172.18` nel `dmesg` dopo il reload.

> ⚠️ **Fragilità da ricordare**: `br-b118407a7c22` è derivato dall'ID della rete Docker. Se la rete
> `n8n_default` viene **ricreata** (`docker network rm/create`, o un `docker compose down/up` che la
> rifà), il nome bridge **cambia** → aggiornare `DOCKER_DEVICE` ed `ETH_DEVICE_SKIP`. Da annotare nelle
> NOTE OPERATIVE. Alternativa più robusta se capitasse spesso: regole su subnet via `csfpost.sh`.

---

## 3-ter. Interazioni con la sicurezza esistente (conflitti / complementarità)
L'host ha già più strati. csf si inserisce **senza conflitti** con quasi tutti — operano a livelli
diversi — con **un solo conflitto vero** (Fail2ban) e **un rischio operativo** (Cloudflare real-IP).

| Strato | Layer | Rapporto con csf | Verdetto |
|---|---|---|---|
| OVH Anti-DDoS | Edge di rete (a monte) | Flood volumetrici a monte; csf a valle | ✅ Complementare |
| ModSecurity | L7 (WAF Apache) | csf è L3/L4; LFD può leggere i log ModSec per bannare i recidivi | ✅ Complementare/sinergico |
| cPHulk | App (login cPanel/WHM/FTP/mail) | Overlap parziale con LFD, chain separate | ⚠️ Overlap, non conflitto |
| **Fail2ban** | Host (scan log → ban iptables) | **Stesso compito di LFD**, stesso iptables | 🔴 **Conflitto → disabilitare** |
| WP Cerber | App (dentro WordPress/PHP) | Bana a livello app/.htaccess, non iptables | ✅ Nessun conflitto diretto |

**Decisioni prese (19/06):**
- **Fail2ban → DISABILITARE** una volta che LFD è operativo (LFD unico banner host-based; standard cPanel).
- **cPHulk + ModSecurity + OVH Anti-DDoS → restano** (layer diversi). cPHulk possiede i ban dei login
  cPanel, LFD il resto. Non sovrapporre gli stessi log.

**🔴 Rischio Cloudflare real-IP — circoscritto a `ardy-lab.it`:** solo `ardy-lab.it` è dietro proxy
Cloudflare (arancione); gli altri domini del server (`micoperibg.eu` = hostname, `omnialga.com`,
`micoperi.com`, `micoperibg.com`) arrivano **diretti** con IP reale. Per il traffico di ardy-lab.it
l'host vede gli **IP di Cloudflare** su 80/443. Senza mitigazione, csf/LFD potrebbero bannare un IP CF
→ **ardy-lab.it giù per tutti**, e gli attaccanti veri restano nascosti. WordPress+Cerber girano su
**questo** server, quindi anche Cerber è coinvolto. Mitigazioni (da applicare):
1. **Whitelist dei range IP Cloudflare in `/etc/csf/csf.allow`** → csf/LFD non bannano mai CF.
2. **`mod_remoteip`** (EA4) per ripristinare l'IP reale del visitatore (`CF-Connecting-IP`/XFF) sui
   log Apache di ardy-lab.it → ModSec/Cerber/LFD agiscono sull'attaccante vero.
3. **Cerber**: attivare l'opzione "dietro reverse proxy / Cloudflare" così legge `CF-Connecting-IP`.

---

## 4. Runbook csf (RPM cPanel — NON ancora eseguito)
> Esecuzione su server (via SSH come root o WHM Terminal), non da questo repo. Procedere **solo dopo**
> la foto del §2 e fuori dagli orari di punta. csf ha anche un'interfaccia in WHM (Plugins →
> ConfigServer Security & Firewall) per il dopo.

> ⚠️ **IMPORTANTE — il vecchio metodo tarball è MORTO.** ConfigServer (Way to the Web Ltd) ha chiuso
> il **31/08/2025**: `download.configserver.com` non risolve più e il classico
> `wget .../csf.tgz && sh install.sh` (quello di tutte le guide online) **fallisce in DNS**. Dal
> **25/02/2026 cPanel mantiene un fork ufficiale** (`github.com/cpanel/cpanel-csf`, GPLv3) distribuito
> come **RPM `cpanel-csf`** nei repo cPanel. Su questo server (AlmaLinux + cPanel) si installa così:

```bash
# A) Installazione (metodo cPanel 2026 — RPM, niente tarball)
dnf info cpanel-csf            # verifica disponibilita nei repo (dry-run, non installa)
yum install cpanel-csf         # installa il fork cPanel; richiede cPanel presente (lo e')
#   NB: l'RPM dipende da cpanel-perl; fallisce di proposito se cPanel non e' rilevato.
#   WHM rileva csf come plugin automaticamente e si auto-aggiorna dai mirror cPanel.

# B) Test compatibilità iptables/moduli kernel
perl /usr/local/csf/bin/csftest.pl
# Atteso: "RESULTING TEST: csf should function on this server"

# C) PRIMA di andare LIVE: restare in TESTING (default a install) ed editare la config
#    /etc/csf/csf.conf  →  punti chiave:
#    - TESTING = "1"            (resta in test: csf si auto-disattiva ogni 5 min → niente lockout)
#    - TCP_IN = "22,53,80,443,2077,2078,2079,2080,2082,2083,2086,2087,2091,2095,2096"
#                              (decisa 19/06: mail chiusa, 111 esclusa, 53 tenuta)
#                              ⚠️ NON dimenticare la 22 (SSH) o ti chiudi fuori al primo csf -r
#    - UDP_IN = "53"           (DNS; chronyd NTP e' su localhost)
#    - TCP_OUT/UDP_OUT permissivi all'inizio (vedi §2-bis: Sole fa molte chiamate in uscita)
#    - abilitare il supporto Docker (opzione nativa se presente nella versione, vedi §3)
#    - LF_DAEMON = "1"         (LFD: rileva i brute-force; coordinare/sostituire Fail2ban — NON
#                               tenerli entrambi a bannare le stesse cose o si pestano i piedi)

# D) Whiteliste di sicurezza anti-lockout PRIMA del LIVE
csf -a <IP_FISSO_DI_MICHELA_SE_ESISTE>     # opzionale ma consigliato se c'è un IP statico
# (le porte WHM 2086/2087 sono già in TCP_IN di default su cPanel: verificare che ci siano)

# E) Test e messa in produzione
csf -r                       # ricarica le regole
# verificare di avere ANCORA accesso a WHM in una NUOVA scheda del browser
# se tutto ok → TESTING = "0" in csf.conf, poi:
csf -r

# F) Coordinare con Docker
docker start n8n_app         # se fermo
docker ps | grep -i n8n      # verificare su
# + test WhatsApp/n8n end-to-end (vedi §3)
```

**Decisione collaterale — Fail2ban vs LFD (di csf)**: csf porta LFD, che copre i login-failure.
Tenere **entrambi** a bannare gli stessi servizi crea doppioni e regole iptables in conflitto.
Scelta consigliata: lasciare LFD gestire SSH/login (qui SSH è off, quindi poco rilevante) e
**decidere caso per caso** se Fail2ban presidia ancora qualcosa che LFD non copre (es. jail custom su
log Apache/ModSecurity). Documentare la scelta finale per non averli a combattersi.

---

## 5. Rischi e mitigazioni
| Rischio | Impatto | Mitigazione |
|---|---|---|
| **Auto-lockout** alzando csf | Medio (due canali: SSH **e** WHM → ridondanza) | Mettere **22 + 2086/2087** in TCP_IN; modalità `TESTING=1` (auto-off ogni 5 min); tenere aperta una sessione SSH/WHM già autenticata mentre si testa in una NUOVA; console OVH/KVM come ultima rete di sicurezza |
| **csf flusha le chain Docker** → n8n offline | Medio (basso qui: n8n su 127.0.0.1) | Abilitare supporto Docker in csf (§3) o `csfpost.sh`; test `docker start n8n_app` + WhatsApp dopo ogni `csf -r` |
| **Doppio ban** Fail2ban + LFD | Medio | Scegliere chi banna cosa (§4); non sovrapporre i jail |
| **Porte chiuse di troppo** (mail/cPanel) → servizio rotto | Medio | Aprire solo ciò che è in `ss -tlnp` (§2); cambiare una cosa per volta e verificare |
| **MySQL esposto** scoperto al §2 | Alto se presente | `bind-address=127.0.0.1` subito, indipendentemente dal firewall |
| **Update futuri** rcompromettono la scelta | Basso | csf non dipende da firewalld/nftables-daemon → resiliente al bug che ha colpito firewalld |

---

## 6. Raccomandazione e prossimi passi
**Scelta: Opzione 2 — csf.** È l'unica che (a) è nativa per cPanel/WHM, (b) dà un vero firewall di
stato con default-deny e protezione brute-force integrata, e (c) **non ripropone** il problema
firewalld/nftables che ci ha costretti al `disable`. L'opzione 1 resta lo stato-ponte accettabile fin
qui; l'opzione 3 è da scartare (rischiosa e temporanea).

Checklist operativa — **ESEGUITA il 19/06/2026** (via SSH root):
- [x] **§2 — Foto della superficie**: MariaDB/n8n/Redis su localhost ✅; rpcbind(111) esposto → chiuso.
- [x] MySQL già su `127.0.0.1` (nessun intervento bind-address necessario).
- [x] **§4 A-B** — Installato `cpanel-csf` v16.20 (RPM cPanel); `csftest` → "csf should function".
- [x] `TCP_IN` mail-chiusa applicato; egress aperto (tightening = follow-up); supporto Docker (§3) risolto.
- [x] Whitelist Cloudflare (ardy-lab.it) + IP admin in `csf.allow`.
- [x] `rpcbind` disabilitato (`systemctl disable --now`), 111 non più in ascolto.
- [x] `TESTING=0` → `csf -r` → **LIVE**, `lfd active`. Accesso SSH/WHM verificato da seconda sessione.
- [x] **§3** — Docker OK: n8n `HTTP=200` stabile, nessun blocco `172.18` residuo.
- [x] Fail2ban → `systemctl disable --now fail2ban` (LFD unico banner host-based).
- [x] NOTE OPERATIVE del TODO aggiornate: "csf LIVE, firewalld resta disabled di proposito".

---

## 7. ESITO — csf LIVE (19/06/2026) ✅
csf **v16.20 (fork cPanel)** attivo e operativo, `lfd` running, firewalld resta `disabled` di proposito.

**Config finale applicata** (`/etc/csf/csf.conf`):
```
TESTING = "0"
TCP_IN  = "22,53,80,443,2077,2078,2079,2080,2082,2083,2086,2087,2091,2095,2096"
UDP_IN  = "53"
TCP_OUT = "1:65535"   # egress aperto (tightening = follow-up)
UDP_OUT = "1:65535"
DOCKER = "1" · DOCKER_DEVICE = "br-b118407a7c22" · DOCKER_NETWORK4 = "172.18.0.0/16" · DOCKER_NETWORK6 = ""
ETH_DEVICE_SKIP = "br-b118407a7c22"   # OUTPUT docker-proxy→container (vedi §3)
```
`csf.allow`: range Cloudflare (14 v4 + 6 v6) + IP admin. `rpcbind` disabilitato.
Difese coesistenti: OVH Anti-DDoS (edge) · ModSecurity (L7) · cPHulk (login cPanel) · **LFD** (host) ·
WP Cerber (app). **Fail2ban rimosso** (rimpiazzato da LFD).

**Follow-up rimasti (non bloccanti):**
- [x] **mod_remoteip** → ✅ già attivo e **auto-gestito da cPanel** (`/etc/apache2/conf.d/includes/
      cloudflare.conf`, rigenerato di notte dai range CF ufficiali; `RemoteIPHeader CF-Connecting-IP`
      + 22 range CF fidati). **Verificato dal vivo** (19/06): i domlog di ardy-lab.it mostrano l'IP
      reale del visitatore, **nessun IP Cloudflare** → Apache/PHP/ModSec/LFD vedono l'attaccante vero.
      Resta solo da confermare in WP-admin che **Cerber** mostri IP reali nell'Activity (default
      `REMOTE_ADDR`, già corretto da mod_remoteip → non fargli leggere lui `CF-Connecting-IP`).
- [ ] **Egress tightening**: da `1:65535` a una lista mirata, testando le uscite di Sole (verificare
      la **porta SMTP di Brevo** — 587/465/2525 — prima di chiudere).
- [ ] **`RESTRICT_SYSLOG = "3"`** in csf.conf (csf avvisa che è disabilitato; evita log-injection che
      falserebbe i ban LFD).
- [ ] (Eventuale) valutare se chiudere anche `53` pubblico se nessun dominio è servito dal pdns locale.

> ⚠️ **Fragilità bridge n8n**: se la rete Docker `n8n_default` viene ricreata, il nome `br-b118407a7c22`
> cambia → aggiornare `DOCKER_DEVICE` ed `ETH_DEVICE_SKIP` e `csf -r` (vedi §3). Da tenere a mente.
>
> **Server gemello**: stessa procedura RPM (`yum install cpanel-csf`) — il vecchio tarball è morto.
