/**
 * Tool di lettura e modifica dei contatti outreach.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { apiCall, getContatto, type Contatto } from '../client.js';
import { CATEGORIE, ResponseFormat, STATI } from '../constants.js';
import { contattoMarkdown, errore, risposta, sintesi } from '../format.js';

const formatoParam = z
  .nativeEnum(ResponseFormat)
  .default(ResponseFormat.MARKDOWN)
  .describe("Formato: 'markdown' leggibile (default) o 'json' strutturato");

export function registraContatti(server: McpServer): void {
  server.registerTool(
    'ardy_cerca_contatti',
    {
      title: 'Cerca contatti outreach',
      description: `Cerca fra i contatti outreach di Ardy Lab (antiquari, mercatini, interior designer, B&B, clienti, partner) e restituisce un elenco sintetico con l'id di ognuno.

È il punto di partenza di quasi ogni operazione: gli altri tool vogliono l'id del contatto, e l'id lo trovi qui.

Args:
  - query (string, opz.): testo cercato in nome, email, telefono, indirizzo
  - categoria ('antiquari'|'mercatini'|'interior_designer'|'bb'|'clienti'|'partner', opz.)
  - regione (string, opz.): zona libera, es. "Salento", "Roma"
  - stato ('da_contattare'|'inviato'|'risposto'|'partner'|'cliente'|'non_interessato', opz.)
  - solo_senza_email (boolean, default false): solo contatti senza email — quelli per cui servono i canali social
  - canali ('con'|'senza'|'qualsiasi', default 'qualsiasi'): filtra per presenza di profili social
  - limit (number 1-100, default 25), offset (number, default 0)
  - response_format ('markdown'|'json', default 'markdown')

Returns (json):
  { "total": number, "count": number, "offset": number, "has_more": boolean,
    "next_offset"?: number,
    "contatti": [ { "id": number, "nome": string, "categoria": string, "stato": string,
                    "email": string|null, "telefono": string|null, "sito": string|null,
                    "regione": string|null, "indirizzo": string|null,
                    "canali": { "instagram"?: string, "facebook"?: string, "linkedin"?: string } } ] }

Esempi:
  - "i B&B del Salento senza email" -> categoria='bb', regione='Salento', solo_senza_email=true
  - "antiquari che hanno già un profilo Instagram" -> categoria='antiquari', canali='con'
  - "cerca Serena" -> query='Serena'

Errori:
  - "Nessun contatto trovato con questi filtri" se la ricerca è vuota: allarga i criteri.`,
      inputSchema: {
        query: z.string().max(200).optional().describe('Testo in nome, email, telefono o indirizzo'),
        categoria: z.enum(CATEGORIE).optional().describe('Categoria del contatto'),
        regione: z.string().max(100).optional().describe('Regione/zona, es. "Salento"'),
        stato: z.enum(STATI).optional().describe('Stato nella pipeline'),
        solo_senza_email: z.boolean().default(false).describe('Solo contatti senza email'),
        canali: z
          .enum(['con', 'senza', 'qualsiasi'])
          .default('qualsiasi')
          .describe("'con' = ha almeno un profilo social, 'senza' = non ne ha nessuno"),
        limit: z.number().int().min(1).max(100).default(25).describe('Massimo risultati'),
        offset: z.number().int().min(0).default(0).describe('Risultati da saltare'),
        response_format: formatoParam,
      },
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async (p) => {
      try {
        // L'API filtra su categoria/regione/stato/search e restituisce tutto:
        // presenza social e paginazione le applichiamo qui.
        const tutti = await apiCall<Contatto[]>('get_contacts', {
          categoria: p.categoria ?? '',
          regione: p.regione ?? '',
          stato: p.stato ?? '',
          search: p.query ?? '',
        });

        let filtrati = tutti.map(sintesi);
        if (p.solo_senza_email) filtrati = filtrati.filter((c) => !c.email);
        if (p.canali === 'con') filtrati = filtrati.filter((c) => Object.keys(c.canali).length > 0);
        if (p.canali === 'senza') filtrati = filtrati.filter((c) => Object.keys(c.canali).length === 0);

        const total = filtrati.length;
        const pagina = filtrati.slice(p.offset, p.offset + p.limit);
        const hasMore = p.offset + pagina.length < total;

        if (!total) {
          return {
            content: [
              {
                type: 'text' as const,
                text: 'Nessun contatto trovato con questi filtri. Prova ad allargare i criteri (togli la categoria o la regione).',
              },
            ],
          };
        }

        const dati = {
          total,
          count: pagina.length,
          offset: p.offset,
          has_more: hasMore,
          ...(hasMore ? { next_offset: p.offset + pagina.length } : {}),
          contatti: pagina,
        };

        const md = [
          `# Contatti trovati: ${total}${hasMore ? ` (mostrati ${pagina.length} dal ${p.offset + 1})` : ''}`,
          '',
          ...pagina.map(contattoMarkdown),
          ...(hasMore ? ['', `_Altri ${total - p.offset - pagina.length}: richiama con offset=${p.offset + pagina.length}._`] : []),
        ].join('\n');

        return risposta(dati, md, p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_dettaglio_contatto',
    {
      title: 'Dettaglio di un contatto',
      description: `Restituisce la scheda completa di un contatto outreach, note comprese.

Args:
  - id (number): id del contatto (lo ottieni da ardy_cerca_contatti)
  - response_format ('markdown'|'json', default 'markdown')

Returns (json): l'oggetto contatto completo, con nome, referente, categoria, email, telefono,
sito, instagram, facebook, linkedin, indirizzo, regione, stato, note, data_contatto.

Errori:
  - "Nessun contatto con id N" se l'id non esiste.`,
      inputSchema: {
        id: z.number().int().positive().describe('Id del contatto'),
        response_format: formatoParam,
      },
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async (p) => {
      try {
        const c = await getContatto(p.id);
        const md = [
          contattoMarkdown(sintesi(c)),
          c.referente ? `- **Referente**: ${c.referente}` : '',
          c.data_contatto ? `- **Ultimo contatto**: ${c.data_contatto}` : '',
          c.note ? `\n**Note**\n${c.note}` : '',
        ]
          .filter(Boolean)
          .join('\n');
        return risposta(c as unknown as Record<string, unknown>, md, p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_aggiorna_contatto',
    {
      title: 'Aggiorna un contatto',
      description: `Scrive uno o più campi sulla scheda di un contatto. Passa SOLO i campi da cambiare: quelli omessi restano come sono.

Usalo per salvare i profili social proposti da ardy_trova_canali_social dopo averli verificati, o per correggere dati a mano.

Args:
  - id (number): id del contatto
  - nome, referente, email, telefono, sito, indirizzo, regione, note (string, opz.)
  - instagram, facebook, linkedin (string, opz.): URL del profilo, o stringa vuota per cancellarlo
  - categoria (enum, opz.), stato (enum, opz.)

Returns (json): { "success": true, "id": number, "aggiornati": string[] }

Esempi:
  - Salvare un profilo trovato -> id=42, instagram='https://instagram.com/bottega_rossi'
  - Segnare un lead come non interessato -> id=42, stato='non_interessato'

Errori:
  - "Nessun campo da aggiornare" se non passi nessun campo oltre all'id.`,
      inputSchema: {
        id: z.number().int().positive().describe('Id del contatto'),
        nome: z.string().max(200).optional(),
        referente: z.string().max(100).optional(),
        email: z.string().max(191).optional(),
        telefono: z.string().max(50).optional(),
        sito: z.string().max(500).optional(),
        instagram: z.string().max(500).optional().describe('URL profilo Instagram (vuoto per cancellare)'),
        facebook: z.string().max(500).optional().describe('URL profilo Facebook (vuoto per cancellare)'),
        linkedin: z.string().max(500).optional().describe('URL profilo LinkedIn (vuoto per cancellare)'),
        indirizzo: z.string().max(400).optional(),
        regione: z.string().max(100).optional(),
        note: z.string().max(5000).optional(),
        categoria: z.enum(CATEGORIE).optional(),
        stato: z.enum(STATI).optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async (p) => {
      try {
        const { id, ...campi } = p;
        const daScrivere = Object.fromEntries(Object.entries(campi).filter(([, v]) => v !== undefined));
        if (!Object.keys(daScrivere).length) {
          throw new Error("Nessun campo da aggiornare: passa almeno un campo oltre all'id.");
        }
        await getContatto(id); // esiste? errore parlante prima di scrivere
        await apiCall('update_contact', { id, ...daScrivere });

        const aggiornati = Object.keys(daScrivere);
        const dati = { success: true, id, aggiornati };
        return risposta(dati, `Contatto ${id} aggiornato: ${aggiornati.join(', ')}.`, ResponseFormat.MARKDOWN);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_statistiche_outreach',
    {
      title: 'Statistiche outreach',
      description: `Riepilogo dei contatti per categoria: totale, da contattare, inviati, risposte.

Args:
  - response_format ('markdown'|'json', default 'markdown')

Returns (json): { "<categoria>": { "totale": number, "da_fare": number, "inviati": number, "risposte": number } }

Usalo per capire dove conviene lavorare prima di lanciare una campagna.`,
      inputSchema: { response_format: formatoParam },
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async (p) => {
      try {
        const s = await apiCall<Record<string, Record<string, number>>>('get_stats');
        const righe = ['# Outreach — statistiche', ''];
        for (const [cat, v] of Object.entries(s)) {
          righe.push(
            `- **${cat}**: ${v.totale ?? 0} totali · ${v.da_fare ?? 0} da contattare · ${v.inviati ?? 0} inviati · ${v.risposte ?? 0} risposte`,
          );
        }
        return risposta(s, righe.join('\n'), p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_lista_regioni',
    {
      title: 'Regioni/zone in uso',
      description: `Elenca le regioni/zone già assegnate ai contatti (campo libero, es. "Salento", "Roma").

Utile per sapere quali valori passare al filtro 'regione' di ardy_cerca_contatti senza tirare a indovinare.

Args: nessuno.
Returns (json): { "regioni": string[] }`,
      inputSchema: {},
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async () => {
      try {
        const regioni = await apiCall<string[]>('get_regioni');
        const dati = { regioni };
        const md = regioni.length
          ? `# Zone in uso\n\n${regioni.map((r) => `- ${r}`).join('\n')}`
          : 'Nessuna regione/zona assegnata finora.';
        return risposta(dati, md, ResponseFormat.MARKDOWN);
      } catch (e) {
        return errore(e);
      }
    },
  );
}
