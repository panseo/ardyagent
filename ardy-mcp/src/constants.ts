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
