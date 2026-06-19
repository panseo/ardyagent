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
  `2086/2087` (WHM), `2095/2096` (webmail), `2077/2078/2079/2080/2091` (cpdavd WebDAV/CalDAV),
  `25/465/587` (exim SMTP), `110/143/993/995` (dovecot POP/IMAP), `4190` (managesieve), `53` (PowerDNS).
- **⚠️ Da CHIUDERE — `111` (rpcbind, TCP+UDP)**: portmapper, serve solo a NFS. Inutile qui e noto
  vettore di amplificazione DDoS. csf non lo whitelista (→ chiuso); meglio anche disabilitare il
  servizio: `systemctl disable --now rpcbind rpcbind.socket` (verificare prima che NFS non sia in uso).
- **❓ Da DECIDERE — stack mail (25,110,143,465,587,993,995,4190)**: la posta è su **Aruba** (MX) e
  l'invio via **Brevo**, quindi questo host probabilmente **non riceve mail** da fuori → porte
  chiudibili. Confermare che nessun account/flow usi la mail locale prima di chiuderle. In dubbio:
  tenerle aperte (default cPanel) e stringere dopo.
- **❓ Da valutare — `53` (PowerDNS)**: i NS autoritativi sono **Cloudflare**; il pdns locale forse non
  serve pubblico. Non urgente (autoritativo, non resolver aperto). Tenere per ora.

**Config csf risultante (stato di partenza consigliato):**
```
# /etc/csf/csf.conf
TCP_IN  = "22,25,53,80,110,143,443,465,587,993,995,2077,2078,2079,2080,2082,2083,2086,2087,2091,2095,2096,4190"
UDP_IN  = "53"
# TCP_OUT/UDP_OUT: lasciare permissivi all'inizio (Sole fa molte chiamate in uscita:
#   API Claude, Meta, Brevo SMTP, Cloudflare, git, docker pull). L'egress filtering
#   e' una rifinitura successiva, da fare testando che Sole non smetta di funzionare.
```
> Se in futuro si chiudono mail/DNS, togliere i relativi numeri da TCP_IN/UDP_IN.

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

---

## 4. Runbook csf (proposta, da eseguire in WHM Terminal — NON ancora eseguito)
> Esecuzione su server (via SSH come root o WHM Terminal), non da questo repo. Procedere **solo dopo**
> la foto del §2 e fuori dagli orari di punta. csf ha anche un'interfaccia in WHM (Plugins →
> ConfigServer Security & Firewall) per il dopo.

```bash
# A) Installazione (metodo ufficiale ConfigServer)
cd /usr/src
wget https://download.configserver.com/csf.tgz
tar -xzf csf.tgz
cd csf
sh install.sh
# WHM rileva csf come plugin automaticamente.

# B) Test compatibilità iptables/moduli kernel
perl /usr/local/csf/bin/csftest.pl
# Atteso: "RESULTING TEST: csf should function on this server"

# C) PRIMA di andare LIVE: restare in TESTING (default a install) ed editare la config
#    /etc/csf/csf.conf  →  punti chiave:
#    - TESTING = "1"            (resta in test: csf si auto-disattiva ogni 5 min → niente lockout)
#    - TCP_IN = "22,25,53,80,110,143,443,465,587,993,995,2077,2078,2079,2080,2082,2083,2086,2087,2091,2095,2096,4190"
#                              (valori reali dal §2-bis; ESCLUSA la 111/rpcbind)
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

Checklist operativa (via SSH come root o WHM Terminal, fuori dagli orari di punta):
- [ ] **§2 — Foto della superficie**: `ss -tlnp`, `ss -ulnp`, conferma MySQL su localhost,
      `docker ps` sulle porte pubblicate. Incollare l'output qui sotto come allegato.
- [ ] (Se MySQL su `0.0.0.0`) → `bind-address=127.0.0.1`, riavvio MySQL, riverifica.
- [ ] **§4 A-B** — Installare csf + `csftest.pl` (resta in `TESTING=1`).
- [ ] Configurare `TCP_IN/TCP_OUT` con SOLO le porte viste al §2; abilitare supporto Docker (§3).
- [ ] Verificare accesso WHM in nuova scheda → `TESTING=0` → `csf -r`.
- [ ] **§3** — `docker start n8n_app` + test WhatsApp/n8n end-to-end.
- [ ] Decidere Fail2ban vs LFD e documentarlo.
- [ ] Aggiornare NOTE OPERATIVE nel TODO: da "firewalld disabilitato, decisione aperta" a
      "csf attivo, firewalld resta disabled di proposito".

> Nota: nessuna di queste azioni è nel repo (sono config di host) e da qui non c'è accesso SSH al
> server. Questo documento è la **decisione + il runbook**; l'esecuzione è manuale su WHM, da Michela/Andrea.
