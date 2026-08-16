/**
 * Costanti condivise del server MCP di Ardy.
 */

/** Tetto di caratteri per una singola risposta: oltre, si tronca e si dice come paginare. */
export const CHARACTER_LIMIT = 25_000;

/** Timeout delle chiamate all'API di Ardy. L'arricchimento con web search è lento. */
export const TIMEOUT_MS = 120_000;

/** Formati di risposta supportati dai tool di lettura. */
export enum ResponseFormat {
  MARKDOWN = 'markdown',
  JSON = 'json',
}

/** Categorie previste da ardy-outreach-api.php. */
export const CATEGORIE = ['antiquari', 'mercatini', 'interior_designer', 'bb', 'clienti', 'partner'] as const;

/** Stati della pipeline lead. */
export const STATI = ['da_contattare', 'inviato', 'risposto', 'partner', 'cliente', 'non_interessato'] as const;

/** Canali social gestiti (colonne su outreach_contatti). */
export const CANALI = ['instagram', 'facebook', 'linkedin'] as const;

/** Modelli AI ammessi dall'API (whitelist lato server). */
export const MODELLI = ['claude-haiku-4-5', 'claude-sonnet-4-6'] as const;

/** Slug di regione ammessi da bed-and-breakfast.it (ardyPortaleBbRegioni in ardy-portale-bb.php). */
export const NOMI_REGIONE_PORTALE_BB: Record<string, string> = {
  abruzzo: 'Abruzzo',
  basilicata: 'Basilicata',
  calabria: 'Calabria',
  campania: 'Campania',
  'emilia-romagna': 'Emilia-Romagna',
  'friuli-venezia-giulia': 'Friuli-Venezia Giulia',
  lazio: 'Lazio',
  liguria: 'Liguria',
  lombardia: 'Lombardia',
  marche: 'Marche',
  molise: 'Molise',
  piemonte: 'Piemonte',
  puglia: 'Puglia',
  sardegna: 'Sardegna',
  sicilia: 'Sicilia',
  toscana: 'Toscana',
  'trentino-alto-adige': 'Trentino-Alto Adige',
  umbria: 'Umbria',
  'valle-d-aosta': "Valle d'Aosta",
  veneto: 'Veneto',
};

export const SLUG_PORTALE_BB = [
  'abruzzo',
  'basilicata',
  'calabria',
  'campania',
  'emilia-romagna',
  'friuli-venezia-giulia',
  'lazio',
  'liguria',
  'lombardia',
  'marche',
  'molise',
  'piemonte',
  'puglia',
  'sardegna',
  'sicilia',
  'toscana',
  'trentino-alto-adige',
  'umbria',
  'valle-d-aosta',
  'veneto',
] as const;

/** Tetto di sicurezza sulla spesa di places_prova, uguale al lato server. */
export const PLACES_CAMPIONE_MAX = 50;
