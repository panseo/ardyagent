/**
 * Formattazione condivisa delle risposte: markdown per la lettura, JSON per
 * l'elaborazione, più il troncamento sotto il tetto di caratteri.
 */

import { CHARACTER_LIMIT, ResponseFormat } from './constants.js';
import type { Contatto } from './client.js';

/** Vista compatta di un contatto: quel che serve per decidere, senza rumore. */
export interface ContattoSintesi {
  id: number;
  nome: string;
  categoria: string;
  stato: string;
  email: string | null;
  telefono: string | null;
  sito: string | null;
  regione: string | null;
  indirizzo: string | null;
  canali: Record<string, string>;
}

export function sintesi(c: Contatto): ContattoSintesi {
  const canali: Record<string, string> = {};
  for (const k of ['instagram', 'facebook', 'linkedin'] as const) {
    const v = (c[k] ?? '').trim();
    if (v) canali[k] = v;
  }
  return {
    id: Number(c.id),
    nome: c.nome,
    categoria: c.categoria,
    stato: c.stato,
    email: c.email || null,
    telefono: c.telefono || null,
    sito: c.sito || null,
    regione: c.regione || null,
    indirizzo: c.indirizzo || null,
    canali,
  };
}

/** Riga markdown di un contatto in elenco. */
export function contattoMarkdown(c: ContattoSintesi): string {
  const righe = [`## ${c.nome} (id ${c.id})`];
  righe.push(`- **Categoria**: ${c.categoria} · **Stato**: ${c.stato}`);
  if (c.email) righe.push(`- **Email**: ${c.email}`);
  if (c.telefono) righe.push(`- **Telefono**: ${c.telefono}`);
  if (c.sito) righe.push(`- **Sito**: ${c.sito}`);
  if (c.regione || c.indirizzo) {
    righe.push(`- **Dove**: ${[c.regione, c.indirizzo].filter(Boolean).join(' · ')}`);
  }
  const canali = Object.entries(c.canali);
  righe.push(
    canali.length
      ? `- **Canali social**: ${canali.map(([k, v]) => `${k} → ${v}`).join(' · ')}`
      : `- **Canali social**: nessuno`,
  );
  return righe.join('\n');
}

/**
 * Confeziona la risposta di un tool nei due formati, troncando se troppo lunga.
 *
 * @param dati        oggetto strutturato (diventa structuredContent)
 * @param markdown    resa leggibile
 * @param formato     scelta del chiamante
 */
export function risposta(dati: Record<string, unknown>, markdown: string, formato: ResponseFormat) {
  let testo = formato === ResponseFormat.JSON ? JSON.stringify(dati, null, 2) : markdown;

  if (testo.length > CHARACTER_LIMIT) {
    testo =
      testo.slice(0, CHARACTER_LIMIT) +
      `\n\n[…risposta troncata a ${CHARACTER_LIMIT} caratteri. ` +
      `Restringi con i filtri (categoria, regione, stato) oppure usa 'limit' e 'offset'.]`;
  }

  return { content: [{ type: 'text' as const, text: testo }], structuredContent: dati };
}

/** Risposta di errore: l'agente la legge come testo, non come crash del protocollo. */
export function errore(err: unknown) {
  const msg = err instanceof Error ? err.message : String(err);
  return { isError: true as const, content: [{ type: 'text' as const, text: `Errore: ${msg}` }] };
}
