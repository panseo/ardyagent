/**
 * Generazione dei messaggi e invio sui canali dove è lecito.
 *
 * Confine importante: l'email parte davvero da qui (Brevo, con unsubscribe di
 * legge). Il messaggio social NO — viene solo scritto, insieme al link che apre
 * la chat, perché Instagram/Facebook/LinkedIn non consentono DM a freddo via
 * API. A inviarlo è una persona.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { apiCall, getContatto, type Template } from '../client.js';
import { CANALI, ResponseFormat } from '../constants.js';
import { errore, risposta } from '../format.js';
import { canaleChat, canaleUrl, type Canale } from '../profili.js';

const formatoParam = z
  .nativeEnum(ResponseFormat)
  .default(ResponseFormat.MARKDOWN)
  .describe("Formato: 'markdown' (default) o 'json'");

export function registraMessaggi(server: McpServer): void {
  server.registerTool(
    'ardy_scrivi_messaggio_social',
    {
      title: 'Scrivi un messaggio per un canale social',
      description: `Scrive la bozza di un messaggio diretto (DM) per un contatto su un suo canale social, e restituisce il link che apre la conversazione.

⚠️ NON INVIA. Instagram, Facebook e LinkedIn non espongono API per il DM a freddo: si può solo rispondere entro 24h a chi ha aperto lui la conversazione. Chi automatizza l'invio usa scorciatoie non ufficiali e si fa bloccare l'account. Questo tool prepara il testo e il link: a incollare e inviare è una persona.

Il testo è tarato sul canale: LinkedIn professionale, Instagram/Facebook più diretti. Massimo ~60 parole, perché un DM lungo non viene letto.

⚠️ COSTO: una chiamata AI per messaggio (pochi centesimi).

Args:
  - id (number): id del contatto
  - canale ('instagram'|'facebook'|'linkedin'): deve essere già salvato sul contatto
  - obiettivo (string, opz.): cosa vuoi ottenere, es. "proporre la rete Galleria Diffusa"
  - response_format ('markdown'|'json', default 'markdown')

Returns (json):
  { "id": number, "nome": string, "canale": string, "messaggio": string,
    "link_chat": string, "link_profilo": string, "parole": number }

Esempi:
  - "scrivi a questo B&B su Instagram" -> id=42, canale='instagram'
  - Con un obiettivo -> id=42, canale='instagram', obiettivo='invitarlo nella rete Galleria Diffusa'

Errori:
  - "Il contatto non ha un profilo <canale>": prima trovalo con ardy_trova_canali_social e salvalo.`,
      inputSchema: {
        id: z.number().int().positive().describe('Id del contatto'),
        canale: z.enum(CANALI).describe('Canale su cui scrivere'),
        obiettivo: z.string().max(500).optional().describe('Obiettivo del messaggio'),
        response_format: formatoParam,
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async (p) => {
      try {
        const c = await getContatto(p.id);
        const profilo = (c[p.canale] ?? '').trim();
        if (!profilo) {
          throw new Error(
            `Il contatto "${c.nome}" non ha un profilo ${p.canale}. ` +
              `Cercalo con ardy_trova_canali_social, poi salvalo con ardy_aggiorna_contatto.`,
          );
        }

        const r = await apiCall<{ success: boolean; messaggio: string }>('genera_messaggio_social', {
          contact_id: p.id,
          canale: p.canale,
          ...(p.obiettivo ? { obiettivo: p.obiettivo } : {}),
        });

        const messaggio = r.messaggio ?? '';
        const canale = p.canale as Canale;
        const dati = {
          id: p.id,
          nome: c.nome,
          canale: p.canale,
          messaggio,
          link_chat: canaleChat(canale, profilo),
          link_profilo: canaleUrl(canale, profilo),
          parole: messaggio.trim() ? messaggio.trim().split(/\s+/).length : 0,
        };

        const md = [
          `# Messaggio per ${c.nome} su ${p.canale}`,
          '',
          '> ' + messaggio.split('\n').join('\n> '),
          '',
          `**${dati.parole} parole.** Rileggi e correggi: l'AI non ha visto il profilo, quindi non può citare i suoi lavori.`,
          '',
          `- **Apri la chat**: ${dati.link_chat}`,
          `- **Apri il profilo**: ${dati.link_profilo}`,
          '',
          '_Copia il testo e invialo tu: l\'invio automatico non è consentito da queste piattaforme._',
        ].join('\n');

        return risposta(dati, md, p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_lista_template_email',
    {
      title: 'Template email disponibili',
      description: `Elenca i template email salvati in Ardy, con id, nome, categoria e oggetto.

Serve a scegliere il template_id da passare a ardy_invia_email (o a decidere di scrivere un testo su misura).

Args:
  - response_format ('markdown'|'json', default 'markdown')

Returns (json): { "count": number, "template": [ { "id": number, "nome": string, "categoria": string, "oggetto": string } ] }`,
      inputSchema: { response_format: formatoParam },
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    async (p) => {
      try {
        const rows = await apiCall<Template[]>('get_templates');
        const template = rows.map((t) => ({
          id: Number(t.id),
          nome: t.nome,
          categoria: t.categoria,
          oggetto: t.oggetto,
        }));
        const dati = { count: template.length, template };
        const md = template.length
          ? ['# Template email', '', ...template.map((t) => `- **${t.nome}** (id ${t.id}, ${t.categoria}) — _${t.oggetto}_`)].join('\n')
          : 'Nessun template salvato.';
        return risposta(dati, md, p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_invia_email',
    {
      title: 'Invia una email a un contatto',
      description: `INVIA DAVVERO una email a UN contatto, via Brevo, dall'indirizzo di Ardy Lab.

⚠️ AZIONE IRREVERSIBILE E VERSO L'ESTERNO. Una volta partita, l'email è nella casella del destinatario. Prima di chiamare questo tool: mostra all'utente destinatario, oggetto e corpo, e ottieni conferma esplicita. Il parametro 'conferma' deve valere true — serve proprio a rendere l'intenzione esplicita, non è una formalità.

L'email include automaticamente il link di disiscrizione richiesto dalla legge, la CTA WhatsApp e il codice etico AI di Ardy Lab: non aggiungerli al corpo.

Il contatto passa a stato 'inviato' e la data di contatto a oggi.

Args:
  - id (number): id del contatto (deve avere un'email)
  - template_id (number, opz.): usa un template salvato (vedi ardy_lista_template_email)
  - oggetto (string, opz.): oggetto su misura — ha la precedenza sul template
  - corpo (string, opz.): corpo su misura. I segnaposto {{nome}} e {{azienda}} vengono sostituiti
  - conferma (boolean): DEVE essere true. Mettilo solo dopo che l'utente ha approvato il testo

Passa template_id OPPURE oggetto+corpo.

Returns (json): { "success": true, "id": number, "destinatario": string, "oggetto": string }

Esempi:
  - Con template -> id=42, template_id=3, conferma=true
  - Su misura -> id=42, oggetto='Una proposta per il vostro B&B', corpo='Gentile {{nome}}, ...', conferma=true

Errori:
  - "Serve la conferma esplicita" se conferma non è true.
  - "Contatto o email mancante" se il contatto non ha email: usa i canali social.
  - "Oggetto o corpo mancante" se non passi né template_id né oggetto+corpo.`,
      inputSchema: {
        id: z.number().int().positive().describe('Id del contatto destinatario'),
        template_id: z.number().int().positive().optional().describe('Id del template da usare'),
        oggetto: z.string().min(1).max(200).optional().describe('Oggetto su misura'),
        corpo: z.string().min(1).max(20000).optional().describe('Corpo su misura ({{nome}}, {{azienda}})'),
        conferma: z.boolean().describe("Deve essere true: conferma esplicita dell'invio"),
      },
      // L'unico tool che manda qualcosa fuori. destructiveHint=true: non si disfa.
      annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
    },
    async (p) => {
      try {
        if (p.conferma !== true) {
          throw new Error(
            "Serve la conferma esplicita: mostra all'utente destinatario, oggetto e corpo, e richiama con conferma=true solo dopo la sua approvazione.",
          );
        }
        if (!p.template_id && !(p.oggetto && p.corpo)) {
          throw new Error('Passa template_id, oppure sia oggetto sia corpo.');
        }

        const c = await getContatto(p.id);
        if (!c.email?.trim()) {
          throw new Error(
            `"${c.nome}" non ha un'email. Per raggiungerlo usa i canali social: ardy_scrivi_messaggio_social.`,
          );
        }

        await apiCall('send_email', {
          contact_id: p.id,
          ...(p.template_id ? { template_id: p.template_id } : {}),
          ...(p.oggetto ? { oggetto: p.oggetto } : {}),
          ...(p.corpo ? { corpo: p.corpo } : {}),
        });

        const dati = { success: true, id: p.id, destinatario: c.email, oggetto: p.oggetto ?? '(dal template)' };
        return risposta(
          dati,
          `Email inviata a **${c.nome}** (${c.email}). Il contatto è ora in stato "inviato".`,
          ResponseFormat.MARKDOWN,
        );
      } catch (e) {
        return errore(e);
      }
    },
  );

  server.registerTool(
    'ardy_scrivi_email',
    {
      title: 'Scrivi il testo di una email di outreach',
      description: `Genera oggetto e corpo di una email di outreach B2B nel tono di Ardy Lab, SENZA inviarla e senza salvarla.

Usalo per preparare un testo da far leggere all'utente; l'invio è un passo separato (ardy_invia_email), volutamente.

⚠️ COSTO: una chiamata AI.

Args:
  - categoria ('antiquari'|'mercatini'|'interior_designer'|'bb'|'clienti'|'partner'): il pubblico
  - obiettivo (string, opz.): es. "prima presentazione", "invito alla rete Galleria Diffusa"
  - tono (string, opz., default 'professionale')
  - canale ('email'|'posta', default 'email'): 'posta' per una lettera cartacea
  - note (string, opz.): offerta o dettagli specifici da includere

Returns (json): { "oggetto": string, "corpo": string }

Il corpo usa il segnaposto {{nome}} nel saluto. Non contiene link di disiscrizione: li aggiunge il sistema all'invio.`,
      inputSchema: {
        categoria: z
          .enum(['antiquari', 'mercatini', 'interior_designer', 'bb', 'clienti', 'partner'])
          .describe('Pubblico a cui è rivolta'),
        obiettivo: z.string().max(500).optional().describe("Obiettivo dell'email"),
        tono: z.string().max(100).optional().describe('Tono, default professionale'),
        canale: z.enum(['email', 'posta']).default('email').describe("'posta' = lettera cartacea"),
        note: z.string().max(2000).optional().describe('Offerta o dettagli specifici'),
        response_format: formatoParam,
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async (p) => {
      try {
        const r = await apiCall<{ oggetto: string; corpo: string }>('genera_template', {
          categoria: p.categoria,
          ...(p.obiettivo ? { obiettivo: p.obiettivo } : {}),
          ...(p.tono ? { tono: p.tono } : {}),
          canale: p.canale,
          ...(p.note ? { note: p.note } : {}),
        });
        const dati = { oggetto: r.oggetto, corpo: r.corpo };
        const md = `# ${r.oggetto}\n\n${r.corpo}\n\n_Bozza non inviata e non salvata. Per mandarla: ardy_invia_email._`;
        return risposta(dati, md, p.response_format);
      } catch (e) {
        return errore(e);
      }
    },
  );
}
