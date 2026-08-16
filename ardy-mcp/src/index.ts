#!/usr/bin/env node
/**
 * Server MCP di Ardy Lab.
 *
 * Espone al desktop (Claude Desktop) i contatti outreach di Ardy: cercarli,
 * completarne i dati, trovarne i canali social, scrivere messaggi e inviare
 * email. Trasporto stdio: gira come sottoprocesso del client, in locale.
 *
 * Confine di progetto: l'unico tool che manda qualcosa fuori è ardy_invia_email
 * (Brevo, con unsubscribe di legge, un destinatario alla volta, conferma
 * esplicita). I DM social si scrivono ma non si inviano — le piattaforme non lo
 * consentono a freddo. Invio di campagne in massa e cancellazione dei contatti
 * NON sono esposti di proposito: restano in dash, dove si vede cosa si sta per
 * fare prima di farlo.
 *
 * Configurazione (variabili d'ambiente):
 *   ARDY_API_URL   es. https://ardyagent.ardy-lab.it   (https obbligatorio)
 *   ARDY_USER      utente del Basic Auth
 *   ARDY_PASS      password del Basic Auth
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { initConfig } from './client.js';
import { registraCanali } from './tools/canali.js';
import { registraContatti } from './tools/contatti.js';
import { registraMessaggi } from './tools/messaggi.js';
import { registraPortale } from './tools/portale.js';

const server = new McpServer({ name: 'ardy-mcp-server', version: '1.0.0' });

registraContatti(server);
registraCanali(server);
registraMessaggi(server);
registraPortale(server);

async function main(): Promise<void> {
  // Fallisce subito se la configurazione manca: un server che parte e poi dà
  // 401 a ogni chiamata è molto più difficile da diagnosticare.
  initConfig();

  const transport = new StdioServerTransport();
  await server.connect(transport);
  // Su stdio il log va SEMPRE su stderr: stdout è il canale del protocollo.
  console.error('ardy-mcp-server in ascolto su stdio');
}

main().catch((err: unknown) => {
  console.error('Avvio fallito:', err instanceof Error ? err.message : String(err));
  process.exit(1);
});
