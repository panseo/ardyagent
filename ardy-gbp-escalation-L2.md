# GBP — Email di escalation "secondo livello" (pronta al cassetto)

**Quando usarla:** SOLO se, dopo la risposta inviata il 17/07, Ravi (o il supporto)
rimbalza di nuovo con un'altra risposta-copione — segnali: (a) richiede cose già
fornite, (b) cambia argomento invece di rispondere sulla natura **HTML** del 403,
(c) ri-suggerisce di abilitare API / usare OAuth (cose già fatte e confermate).

**Come usarla:** rispondere nel thread Gmail `[4-4300000041395]` (account
`ardy.documenti@gmail.com`), copiando il testo qui sotto. In inglese, firma Michela.
Aggiornare la data dell'ultimo test se nel frattempo si è rilanciato `ardy-gbp-check.php`.

**Piano B in parallelo:** postare la stessa domanda tecnica sul forum ufficiale
(issuetracker Google / Stack Overflow tag `google-my-business-api`): lì rispondono
a volte i Googler che gestiscono l'allowlist, scavalcando il supporto email.

---

## Testo email

Subject: Re: [4-4300000041395]

Hi Ravi,

This is now the fifth reply on this case, and each one asks us to change something on
the client side that is already correct and verified. I'd like to respectfully ask that
this case be **escalated to a second-level / engineering contact** who can inspect the
backend allowlist state directly, because the issue is demonstrably not on our side.

To save everyone time, here is the complete, verified state of the integration:

1. **OAuth**: OAuth 2.0 installed-app flow, one-time user consent, stored refresh token.
   No service account, no impersonation, no externally generated tokens. The same OAuth
   client and refresh token successfully call Calendar and Gmail, proving token
   generation and refresh work.
2. **Identity**: the OAuth account `ardy.documenti@gmail.com` **owns** the Cloud project
   532339794075 **and** is a verified **manager of the Business Profile listing**
   ("Ardy di Michela Panella"). No account/identity mismatch.
3. **APIs**: all 8 Business Profile family APIs from your July 13 list are enabled.
4. **Allowlist**: on July 6 you confirmed in writing that project 532339794075 was
   allowlisted and access granted to `ardy.documenti@gmail.com`.
5. **OAuth app**: status "In production", `business.manage` scope authorized.

Despite all of the above, the request:
`GET https://mybusinessaccountmanagement.googleapis.com/v1/accounts`
still returns **HTTP 403 as a text/html page** from the Google front-end
("Error 403 (Forbidden) — Your client does not have permission to get URL /v1/accounts
from this server"), with `content-type: text/html`.

This is the crux, and it is a purely server-side signal: an `text/html` 403 from the
front-end means the request is **rejected before it reaches the API**. It cannot be
caused by, or fixed with, any client-side OAuth change — an OAuth/token problem would
return HTTP 401 or a JSON `PERMISSION_DENIED`, never an HTML page. The only remaining
cause consistent with this behavior is that the **allowlist / provisioning for project
532339794075 is not actually active or fully propagated on the backend**, notwithstanding
the July 6 confirmation.

Specific requests:
- Please **escalate to engineering / second-level support**.
- Please have someone **verify, on the backend, the actual allowlist state** for project
  number 532339794075 and account `ardy.documenti@gmail.com` — not the client-side OAuth
  configuration, which is confirmed correct.
- If the allowlist shows as active on your side, please explain how a correctly
  allowlisted project can still receive an HTML 403 "does not have permission to get URL"
  from the front-end, and what additional backend step is required.

Reference details:
- Google account: `ardy.documenti@gmail.com`
- Cloud project: ardy-lab (project number 532339794075)
- Original request ID: 3-7851000041139
- Case: 4-4300000041395

Thank you — I appreciate your help in getting this in front of someone who can inspect
the backend directly.

Best,
Michela Panella
