/**
 * Scoperta dei canali social e arricchimento dati.
 *
 * Entrambi i tool restituiscono PROPOSTE: non scrivono sul database. La scrittura
 * passa da ardy_aggiorna_contatto, dopo che qualcuno ha guardato i valori. È la
 * stessa regola della dash — l'AI può sbagliare profilo, e un profilo sbagliato
 * significa scrivere a un estraneo.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { apiCall, getContatto, type CampoProposto } from '../client.js';
import { MODELLI, ResponseFormat } from '../constants.js';
import { errore, risposta } from '../format.js';

interface RispostaEnrich {
  success: boolean;
  id: number;
  campi: Record<string, CampoProposto>;
  log: string[];
}

/** Rende leggibile una proposta, mostrando fonte e confidenza. */
function proposteMarkdown(titolo: string, campi: Record<string, CampoProposto>, log: string[]): string {
  const righe = [`# ${titolo}`, ''];
  const nomi = Object.keys(campi);
  if (!nomi.length) {
    righe.push('Nessuna proposta. Non è un errore: meglio niente che un dato indovinato.');
  } else {
    for (const [campo, p] of Object.entries(campi)) {
      righe.push(`## ${campo}`);
      righe.push(`- **Valore**: ${p.valore}`);
      righe.push(`- **Confidenza**: ${p.confidenza}${p.fonte ? ` · **Fonte**: ${p.fonte}` : ''}`);
      if (p.attuale) righe.push(`- ⚠️ **Sovrascriverebbe**: ${p.attuale}`);
      righe.push('');
    }
    righe.push('_Per salvare, usa ardy_aggiorna_contatto con i campi che hai verificato._');
  }
  if (log.length) righe.push('', '**Cosa ha fatto l\'agente**', ...log.map((l) => `- ${l}`));
  return righe.join('\n');
}

export function registraCanali(server: McpServer): void {
  server.registerTool(
    'ardy_trova_canali_social',
    {
      title: 'Trova i canali social di un contatto',
      description: `Cerca i profili social (Instagram, Facebook, LinkedIn) di UN contatto e li propone senza salvarli.

Come funziona: legge le icone social del sito ufficiale (gratis, ed è il soggetto stesso a dichiarare i propri canali); se non bastano, fa una ricerca web con l'AI.

⚠️ COSTO: la ricerca web si paga (~$0,02-0,07 a contatto). Il passo gratuito sul sito viene sempre tentato per primo. Non usarlo a raffica su liste lunghe senza motivo.

⚠️ NON salva niente: restituisce proposte con fonte e confidenza. Verifica che il profilo sia davvero di quel soggetto (e non di un omonimo), poi salvalo con ardy_aggiorna_contatto.

Args:
  - id (number): id del contatto
  - model ('claude-haiku-4-5'|'claude-sonnet-4-6', default haiku): Sonnet costa di più ma è più preciso sui casi difficili
  - response_format ('markdown'|'json', default 'markdown')

Returns (json):
  { "id": number, "campi": { "instagram"?: { "valore": string, "fonte": string, "confidenza": "alta"|"media"|"bassa" }, ... },
    "log": string[] }

Esempi:
  - "trova i social di questo B&B" -> id=42
  - Se torna vuoto, il sito non ha icone social e la ricerca web non ha trovato nulla di certo: incolla il profilo a mano se lo conosci.

Errori:
  - "Nessun contatto con id N" se l'id è sbagliato.
  - "AI non configurata" se manca la chiave Anthropic sul server.`,
      inputSchema: {
        id: z.number().int().positive().describe('Id del contatto'),
        model: z.enum(MODELLI).default('claude-haiku-4-5').describe('Modello AI per la ricerca web'),
        response_format: z
          .nativeEnum(ResponseFormat)
          .default(ResponseFormat.MARKDOWN)
          .describe("Formato: 'markdown' (default) o 'json'"),
      },
      // Non readOnly: spende soldi e interroga servizi esterni. Ma non distrugge
      // nulla e non scrive sul database.
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async (p) => {
      try {
        const c = await getContatto(p.id);
        const r = await apiCall<RispostaEnrich>('enrich_contact', { id: p.id, model: p.model, scope: 'social' });
        const campi = r.campi ?? {};
        const dati = { id: p.id, nome: c.nome, campi, log: r.log ?? [] };
        return risposta(dati, proposteMarkdown(`Canali social proposti per ${c.nome}`, campi, r.log ?? []), p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_arricchisci_contatto',
    {
      title: 'Arricchisci i dati di un contatto',
      description: `Cerca i dati mancanti di un contatto (email, telefono, sito, indirizzo, referente e canali social) e li propone senza salvarli.

Differenza con ardy_trova_canali_social: quello cerca solo i profili social, questo completa la scheda intera. Se al contatto manca solo il social, usa l'altro.

⚠️ COSTO, secondo lo scope:
  - scope='tutto' (default): se mancano dati di contatto, parte una ricerca web a pagamento (~$0,05-0,10, include Google Places). Se manca solo il social, la ricerca a pagamento NON parte: viene solo riletto il sito, gratis.
  - scope='google': MAI ricerca web a pagamento sull'account Anthropic. Prova solo i passi gratuiti (sito ufficiale) più Google Places (sito/telefono/indirizzo — costa sull'account Google Places di Ardy Lab, non su questo). Pensato per gli import grossi: completa telefono e sito su centinaia di contatti senza far partire l'agente su ognuno. Prima di lanciarlo su tante schede, misura quanto renderebbe con ardy_prova_copertura_places.

⚠️ NON salva niente: verifica le proposte e salvale con ardy_aggiorna_contatto.

Args:
  - id (number): id del contatto
  - scope ('tutto'|'google', default 'tutto')
  - model ('claude-haiku-4-5'|'claude-sonnet-4-6', default haiku): ignorato con scope='google', che non chiama l'agente
  - response_format ('markdown'|'json', default 'markdown')

Returns (json): stessa forma di ardy_trova_canali_social, ma i campi possono includere
anche email, telefono, sito, indirizzo, referente (email e referente restano vuoti con scope='google': Places non li fornisce).

Esempi:
  - "completa i dati di questo antiquario" -> id=17
  - "arricchisci questo B&B senza spendere sull'agente" -> id=17, scope='google'`,
      inputSchema: {
        id: z.number().int().positive().describe('Id del contatto'),
        scope: z
          .enum(['tutto', 'google'])
          .default('tutto')
          .describe("'tutto' = anche l'agente web se serve; 'google' = solo sito+Places, mai l'agente"),
        model: z.enum(MODELLI).default('claude-haiku-4-5').describe("Modello AI per la ricerca web (ignorato con scope='google')"),
        response_format: z
          .nativeEnum(ResponseFormat)
          .default(ResponseFormat.MARKDOWN)
          .describe("Formato: 'markdown' (default) o 'json'"),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async (p) => {
      try {
        const c = await getContatto(p.id);
        const r = await apiCall<RispostaEnrich>('enrich_contact', { id: p.id, model: p.model, scope: p.scope });
        const campi = r.campi ?? {};
        const dati = { id: p.id, nome: c.nome, campi, log: r.log ?? [] };
        return risposta(dati, proposteMarkdown(`Dati proposti per ${c.nome}`, campi, r.log ?? []), p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );
}
