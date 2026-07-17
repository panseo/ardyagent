# GBP — Piano B: post pubblico su forum/Issue Tracker (allowlist non propagata)

**Obiettivo:** attaccare il blocco 403 da un canale pubblico, in parallelo al ticket
email col supporto (caso 4-4300000041395), per intercettare un Googler che gestisce
davvero l'allowlist — scavalcando il supporto di primo livello.

## Dove postare (in ordine di efficacia)

1. **Google Issue Tracker** — `issuetracker.google.com`
   Cercare il componente **"Business Profile APIs"** (ex "Google My Business API") e
   aprire una nuova issue tipo *Bug*. È il canale dove i Googler triageano il backend.
2. **Stack Overflow** — tag **`google-my-business-api`** (+ `oauth-2.0`, `google-api`).
   Buono per visibilità; a volte risponde staff Google. Usare lo stesso testo accorciato.

## Regole privacy (post PUBBLICO)

- ✅ OK pubblicare: **project number 532339794075**, gli endpoint, il corpo dell'errore.
- ❌ NON pubblicare: la **Gmail** dell'account (`ardy.documenti@…`), l'ID richiesta
  privato, il numero di caso email. Scrivere "the account that owns the project" e
  fornire l'email **solo in privato** se un Googler la chiede.

---

## Titolo

Business Profile API returns HTTP 403 as text/html ("does not have permission to get URL /v1/accounts") on an allowlisted project

## Corpo del post

**Environment**
- Cloud project number: 532339794075
- API: My Business Account Management API (also enabled: Business Information + all
  Business Profile family APIs)
- Auth: OAuth 2.0 installed-app flow, user consent + refresh token (no service account),
  scope `https://www.googleapis.com/auth/business.manage`
- OAuth consent screen: "In production"

**What I'm doing**
A simple, read-only first call to resolve the accounts:

```
GET https://mybusinessaccountmanagement.googleapis.com/v1/accounts
Authorization: Bearer <valid OAuth access token>
```

The same OAuth client + refresh token work correctly against other Google APIs
(Calendar, Gmail), so token generation/refresh is confirmed working.

**Expected**
HTTP 200 with the list of accounts (or an empty list), or at worst a JSON error.

**Actual**
HTTP **403** returned as a **`text/html`** page from the Google front-end (not a JSON
API error):

```
HTTP/2 403
content-type: text/html; charset=UTF-8

<title>Error 403 (Forbidden)!!1</title>
...
Your client does not have permission to get URL /v1/accounts from this server.
```

**Why this looks like a backend allowlist/provisioning issue, not a client problem**
- A `text/html` 403 from the front-end means the request is rejected **before** it
  reaches the API. An OAuth/token problem would return HTTP 401 or a JSON
  `PERMISSION_DENIED` — never an HTML page.
- The Business Profile API access request for this project was submitted and, per email
  support, **confirmed allowlisted** (project 532339794075, access granted to the owner
  account). Yet the HTML 403 persists across repeated tests over ~2 weeks.
- All required APIs are enabled; the OAuth account both **owns the project** and is a
  **verified manager** of the target Business Profile listing.

**Question**
How can a project that support has confirmed as allowlisted still receive an HTML 403
"does not have permission to get URL /v1/accounts" from the front-end? What backend step
is missing, and can someone verify the actual allowlist/provisioning state for project
number 532339794075? I can provide the owner account details privately.

(An email support case exists in parallel but has only produced first-line, templated
replies that don't address the front-end HTML nature of the 403.)
