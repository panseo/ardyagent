/**
 * Client HTTP verso l'API di Ardy (ardy-outreach-api.php).
 *
 * L'API è un endpoint unico che smista su `action` nel corpo JSON, protetto da
 * Basic Auth (.htaccess + ardyRequireAuth). Qui centralizziamo autenticazione,
 * timeout e traduzione degli errori in messaggi che dicano cosa fare.
 */

import { TIMEOUT_MS } from './constants.js';

/** Riga di `outreach_contatti` come la restituisce l'API. */
export interface Contatto {
  id: number;
  nome: string;
  referente: string | null;
  categoria: string;
  email: string | null;
  telefono: string | null;
  sito: string | null;
  instagram: string | null;
  facebook: string | null;
  linkedin: string | null;
  indirizzo: string | null;
  regione: string | null;
  stato: string;
  note: string | null;
  data_contatto: string | null;
  created_at: string;
  updated_at: string;
}

/** Proposta di arricchimento per un singolo campo. */
export interface CampoProposto {
  valore: string;
  fonte: string;
  confidenza: string;
  passo?: string;
  attuale?: string;
}

export interface Template {
  id: number;
  nome: string;
  categoria: string;
  oggetto: string;
  corpo: string;
}

/** Errore già formulato per l'agente: dice cosa è andato storto e come rimediare. */
export class ArdyError extends Error {}

interface Config {
  baseUrl: string;
  auth: string;
}

let config: Config | null = null;

/**
 * Legge la configurazione dalle variabili d'ambiente e la valida una volta sola.
 * Fallisce presto e in modo esplicito: un server MCP mal configurato che parte
 * e poi dà 401 a ogni chiamata è molto più difficile da diagnosticare.
 */
export function initConfig(): Config {
  if (config) return config;

  const baseUrl = process.env.ARDY_API_URL?.trim().replace(/\/+$/, '');
  const user = process.env.ARDY_USER?.trim();
  const pass = process.env.ARDY_PASS;

  const mancanti: string[] = [];
  if (!baseUrl) mancanti.push('ARDY_API_URL');
  if (!user) mancanti.push('ARDY_USER');
  if (!pass) mancanti.push('ARDY_PASS');
  if (mancanti.length) {
    throw new ArdyError(
      `Configurazione mancante: ${mancanti.join(', ')}. ` +
        `Vanno impostate nella configurazione del server MCP (vedi README).`,
    );
  }
  if (!baseUrl!.startsWith('https://')) {
    // Le credenziali Basic viaggiano in chiaro su HTTP: non lo permettiamo.
    throw new ArdyError(`ARDY_API_URL deve usare https:// (valore attuale: ${baseUrl}).`);
  }

  config = { baseUrl: baseUrl!, auth: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64') };
  return config;
}

/**
 * Esegue un'azione sull'API di Ardy.
 *
 * @param action  nome dell'azione (es. 'get_contacts')
 * @param payload campi aggiuntivi del corpo JSON
 */
export async function apiCall<T>(action: string, payload: Record<string, unknown> = {}): Promise<T> {
  const cfg = initConfig();
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

  let res: Response;
  try {
    res = await fetch(`${cfg.baseUrl}/ardy-outreach-api.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: cfg.auth,
      },
      body: JSON.stringify({ action, ...payload }),
      signal: controller.signal,
    });
  } catch (err) {
    if (err instanceof Error && err.name === 'AbortError') {
      throw new ArdyError(
        `Timeout dopo ${TIMEOUT_MS / 1000}s sull'azione '${action}'. ` +
          `L'arricchimento con ricerca web può essere lento: riprova, oppure lavora su un contatto alla volta.`,
      );
    }
    throw new ArdyError(
      `Impossibile raggiungere Ardy (${cfg.baseUrl}): ${err instanceof Error ? err.message : String(err)}. ` +
        `Controlla ARDY_API_URL e la connessione.`,
    );
  } finally {
    clearTimeout(timer);
  }

  if (res.status === 401) {
    throw new ArdyError('Autenticazione rifiutata (401). Controlla ARDY_USER e ARDY_PASS.');
  }
  if (!res.ok) {
    throw new ArdyError(`L'API di Ardy ha risposto ${res.status} sull'azione '${action}'.`);
  }

  const testo = await res.text();
  let dati: unknown;
  try {
    dati = JSON.parse(testo);
  } catch {
    // Tipicamente è una pagina di login o un errore PHP: non riversiamo l'HTML
    // nel contesto dell'agente, ma diciamo cosa guardare.
    throw new ArdyError(
      `Risposta non JSON dall'azione '${action}' (${testo.length} caratteri). ` +
        `Di solito significa che il Basic Auth ha intercettato la richiesta o che l'endpoint ha dato errore.`,
    );
  }

  // L'API segnala i suoi errori dentro il JSON, con 200.
  if (dati && typeof dati === 'object' && !Array.isArray(dati)) {
    const obj = dati as Record<string, unknown>;
    if (typeof obj.error === 'string') throw new ArdyError(obj.error);
    if (obj.success === false) throw new ArdyError(String(obj.error ?? `Azione '${action}' fallita.`));
  }

  return dati as T;
}

/** Scorciatoia: carica un contatto per id, con errore parlante se non esiste. */
export async function getContatto(id: number): Promise<Contatto> {
  const tutti = await apiCall<Contatto[]>('get_contacts');
  const c = tutti.find((x) => Number(x.id) === id);
  if (!c) {
    throw new ArdyError(`Nessun contatto con id ${id}. Usa ardy_cerca_contatti per trovare l'id giusto.`);
  }
  return c;
}
