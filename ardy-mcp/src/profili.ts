/**
 * Link ai profili e alle chat, a partire da quel che è salvato sul contatto.
 * Stessa logica del pannello "Canali social" della dash (ardy-outreach.html):
 * accetta URL completo, @handle o handle nudo.
 */

export type Canale = 'instagram' | 'facebook' | 'linkedin';

const DOMINI: Record<Canale, string> = {
  instagram: 'instagram.com',
  facebook: 'facebook.com',
  linkedin: 'linkedin.com',
};

/** URL completo del profilo. Stringa vuota se il campo è vuoto. */
export function canaleUrl(canale: Canale, valore: string | null | undefined): string {
  const v = String(valore ?? '').trim();
  if (!v) return '';
  if (/^https?:\/\//i.test(v)) return v;
  if (v.toLowerCase().includes(DOMINI[canale])) return 'https://' + v.replace(/^\/+/, '');
  const h = v.replace(/^@/, '').replace(/^\/+/, '');
  // Su LinkedIn un handle nudo è quasi sempre un'azienda: i contatti outreach lo sono.
  if (canale === 'linkedin') return `https://linkedin.com/company/${h}`;
  return `https://${DOMINI[canale]}/${h}`;
}

/** Handle/slug del profilo, per costruire il link diretto alla chat. */
export function canaleHandle(canale: Canale, valore: string | null | undefined): string {
  const url = canaleUrl(canale, valore);
  if (!url) return '';
  const fbId = url.match(/profile\.php\?id=(\d+)/i); // m.me funziona con l'id numerico
  if (fbId?.[1]) return fbId[1];
  const m = url.match(/^https?:\/\/[^/]+\/(?:in\/|company\/|school\/)?([^/?#]+)/i);
  return m?.[1] ?? '';
}

/**
 * Link che apre la conversazione. Instagram e Facebook hanno un deep link alla
 * chat; LinkedIn no, quindi si ripiega sul profilo (poi si usa "Messaggio").
 */
export function canaleChat(canale: Canale, valore: string | null | undefined): string {
  const handle = canaleHandle(canale, valore);
  if (!handle) return canaleUrl(canale, valore);
  if (canale === 'instagram') return `https://ig.me/m/${handle}`;
  if (canale === 'facebook') return `https://m.me/${handle}`;
  return canaleUrl(canale, valore);
}
