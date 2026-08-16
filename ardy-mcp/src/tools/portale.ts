/**
 * Import massivi da bed-and-breakfast.it — nato nel filone B (dash), qui portato
 * sull'MCP perché dal desktop si possa fare un giro di import grosso senza aprire
 * la dash. Nessuno dei due tool scrive sul database: portale_bb legge l'elenco
 * pubblico del portale, places_prova misura quanto renderebbe Google Places PRIMA
 * di spendere per l'arricchimento su tutta la regione. Salvare i lead resta un
 * passo in dash — stessa logica di send_campaign/delete_contact: un fraintendimento
 * in chat non deve poter scrivere centinaia di righe sul CRM.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { apiCall } from '../client.js';
import { NOMI_REGIONE_PORTALE_BB, PLACES_CAMPIONE_MAX, ResponseFormat, SLUG_PORTALE_BB } from '../constants.js';
import { errore, risposta } from '../format.js';

const formatoParam = z
  .nativeEnum(ResponseFormat)
  .default(ResponseFormat.MARKDOWN)
  .describe("Formato: 'markdown' leggibile (default) o 'json' strutturato");

interface LeadPortale {
  nome: string;
  indirizzo: string;
  comune: string;
  provincia: string;
  tipologia: string;
  voto: number | null;
  recensioni: number | null;
  scheda: string;
  exists: boolean;
}

interface RispostaPortaleBb {
  success: boolean;
  regione: string;
  pagina: number;
  fine: boolean;
  count: number;
  results: LeadPortale[];
}

interface RigaPlaces {
  nome: string;
  zona: string;
  telefono: string;
  sito: string;
  esito: 'telefono' | 'senza_telefono' | 'non_trovata';
}

interface RispostaPlacesProva {
  success: boolean;
  righe: RigaPlaces[];
  fatte: number;
  trovate: number;
  con_tel: number;
  con_sito: number;
  perc_tel: number;
  perc_sito: number;
  cap_hit: boolean;
  usate_oggi: number;
  tetto: number;
}

export function registraPortale(server: McpServer): void {
  server.registerTool(
    'ardy_estrai_portale_bb',
    {
      title: 'Estrai B&B da bed-and-breakfast.it',
      description: `Legge una pagina dell'elenco pubblico di bed-and-breakfast.it per una regione e restituisce le strutture trovate (nome, indirizzo, tipologia, voto, link alla scheda). Gratis: legge dato strutturato (JSON-LD) che il portale pubblica per i motori di ricerca, non fa scraping HTML.

⚠️ NON salva niente sul database: è un elenco da rivedere. Ogni struttura ha 'exists' true/false (già presente fra i contatti, per nome) così non riproponi chi c'è già.

⚠️ Il portale NON pubblica telefono, email o sito: quei campi restano vuoti e vanno completati dopo con ardy_arricchisci_contatto (scope='google' per farlo senza spendere sull'agente, su tante strutture).

Circa 24 strutture a pagina. Una chiamata = una pagina: su un giro di più pagine, chiamalo in sequenza (non in parallelo) per non stressare il portale, che non è nostro.

Args:
  - regione_slug (string): slug della regione, uno fra: ${SLUG_PORTALE_BB.join(', ')}
  - pagina (number, default 1): pagina dell'elenco, dalla 1
  - response_format ('markdown'|'json', default 'markdown')

Returns (json):
  { "regione": string, "pagina": number, "fine": boolean, "count": number,
    "results": [ { "nome": string, "indirizzo": string, "comune": string, "provincia": string,
                    "tipologia": string, "voto": number|null, "recensioni": number|null,
                    "scheda": string, "exists": boolean } ] }

'fine'=true significa che questa pagina è oltre l'ultima (elenco esaurito): fermati.

Esempi:
  - "quanti B&B ci sono in Puglia sul portale?" -> regione_slug='puglia', pagina=1, poi avanti finché 'fine'
  - "dammi la seconda pagina dei B&B del Salento" -> serve comunque regione_slug='puglia' (il portale non ha zone più fini della regione): filtra 'comune'/'provincia' nel risultato

Errori:
  - "Scegli una regione" se regione_slug non è fra quelli ammessi.`,
      inputSchema: {
        regione_slug: z.enum(SLUG_PORTALE_BB).describe('Slug regione del portale, es. "puglia"'),
        pagina: z.number().int().min(1).default(1).describe("Pagina dell'elenco, dalla 1"),
        response_format: formatoParam,
      },
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async (p) => {
      try {
        const r = await apiCall<RispostaPortaleBb>('portale_bb', { regione_slug: p.regione_slug, pagina: p.pagina });
        const nomeRegione = r.regione || NOMI_REGIONE_PORTALE_BB[p.regione_slug] || p.regione_slug;
        const dati = { regione: nomeRegione, pagina: r.pagina, fine: r.fine, count: r.count, results: r.results ?? [] };

        const righe = [`# ${nomeRegione} — pagina ${r.pagina}${r.fine ? ' (oltre l\'ultima: elenco esaurito)' : ''}`, ''];
        if (!dati.results.length) {
          righe.push('Nessuna struttura in questa pagina.');
        } else {
          for (const s of dati.results) {
            const dove = [s.comune, s.provincia ? `(${s.provincia})` : ''].filter(Boolean).join(' ');
            const voto = s.voto ? ` · voto ${s.voto}${s.recensioni ? `/${s.recensioni} recensioni` : ''}` : '';
            righe.push(`- **${s.nome}**${s.exists ? ' _(già in Ardy)_' : ''} — ${dove}${s.tipologia ? `, ${s.tipologia}` : ''}${voto}`);
          }
          righe.push('', `_Telefono/email/sito non pubblicati dal portale: completali con ardy_arricchisci_contatto dopo aver importato._`);
        }
        if (!r.fine) righe.push('', `_Altre strutture in seguito: richiama con pagina=${r.pagina + 1}._`);

        return risposta(dati, righe.join('\n'), p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_prova_copertura_places',
    {
      title: 'Misura la copertura di Google Places su un campione',
      description: `Su un campione di strutture (di solito appena lette con ardy_estrai_portale_bb), misura quante Google Places ha in scheda col telefono. Serve a decidere SE conviene arricchire tutta una regione con scope='google' prima di lanciarlo su centinaia di contatti.

⚠️ COSTO: una chiamata Google Places per struttura del campione — sull'account Google Places di Ardy Lab, non su questo account Anthropic. Le prime 1.000 chiamate al mese sono gratis. Il campione è limitato a ${PLACES_CAMPIONE_MAX} per non bruciare budget con un errore.

⚠️ NON scrive niente sul database: è solo una misura, non un import.

Args:
  - campione (array, 1-${PLACES_CAMPIONE_MAX} elementi): strutture da provare, ognuna { nome (string, obbligatorio), comune (string, opz.), provincia (string, opz.) } — passa direttamente i 'results' di ardy_estrai_portale_bb
  - response_format ('markdown'|'json', default 'markdown')

Returns (json):
  { "fatte": number, "trovate": number, "con_tel": number, "con_sito": number,
    "perc_tel": number, "perc_sito": number, "cap_hit": boolean,
    "righe": [ { "nome": string, "zona": string, "telefono": string, "sito": string, "esito": string } ] }

Come leggerla: perc_tel >= 60% → conviene arricchire tutta la regione con scope='google', poi l'agente
solo su chi resta scoperto. 30-59% → conviene solo se i telefoni servono davvero, altrimenti filtra prima
per provincia/voto. Sotto 30% → non spendere sul grosso: meglio l'agente, che trova anche email e social.

Esempi:
  - "vale la pena arricchire tutta la Puglia con Google?" -> prendi 20-30 risultati di ardy_estrai_portale_bb e passali come campione

Errori:
  - "Google Places non è configurato sul server" se manca la chiave lato Ardy.
  - "Nessuna struttura da provare" se il campione è vuoto.`,
      inputSchema: {
        campione: z
          .array(
            z.object({
              nome: z.string().min(1).describe('Nome della struttura'),
              comune: z.string().optional().describe('Comune, per il match su Google'),
              provincia: z.string().optional().describe('Sigla provincia, es. "BA"'),
            }),
          )
          .min(1)
          .max(PLACES_CAMPIONE_MAX)
          .describe(`Strutture da provare (max ${PLACES_CAMPIONE_MAX}), es. i 'results' di ardy_estrai_portale_bb`),
        response_format: formatoParam,
      },
      // Non readOnly: spende sull'account Google Places di Ardy (non su questo account
      // Anthropic). Non scrive né distrugge nulla sul database.
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async (p) => {
      try {
        const r = await apiCall<RispostaPlacesProva>('places_prova', { campione: p.campione });
        const dati = {
          fatte: r.fatte,
          trovate: r.trovate,
          con_tel: r.con_tel,
          con_sito: r.con_sito,
          perc_tel: r.perc_tel,
          perc_sito: r.perc_sito,
          cap_hit: r.cap_hit,
          righe: r.righe ?? [],
        };

        const righe = [`# Copertura Google Places su ${r.fatte} strutture`, ''];
        for (const rr of dati.righe) {
          const esito = rr.telefono ? `☎ ${rr.telefono}` : rr.esito === 'senza_telefono' ? 'trovata, senza telefono' : 'non trovata';
          righe.push(`- **${rr.nome}** (${rr.zona}): ${esito}`);
        }
        righe.push(
          '',
          `**Esito**: ${r.trovate}/${r.fatte} trovate su Google · ${r.con_tel} con telefono (${r.perc_tel}%) · ${r.con_sito} con sito (${r.perc_sito}%)`,
        );
        if (r.cap_hit) righe.push('', '⚠️ Tetto giornaliero Google Places raggiunto: riprova domani per completare la misura.');
        righe.push(
          '',
          r.perc_tel >= 60
            ? '_Copertura buona: conviene arricchire tutta la regione con scope=\'google\', poi l\'agente solo su chi resta scoperto._'
            : r.perc_tel >= 30
              ? "_Copertura media: ha senso su tutta la regione solo se i telefoni servono davvero, altrimenti filtra prima per provincia/voto._"
              : '_Copertura bassa: non spendere sul grosso dell\'elenco. Meglio l\'agente (scope=\'tutto\'), che trova anche email e social._',
        );

        return risposta(dati, righe.join('\n'), p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );
}
