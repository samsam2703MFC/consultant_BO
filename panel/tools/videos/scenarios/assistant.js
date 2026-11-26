/* Les gestes communs des vidéos de l'Assistant IA par onglet (21-01-bilan … 21-06-demande-bureau, 26/11/2026) et de la
 * fenêtre du Professeur (22-02, 22-04, 22-06). Les données sont celles de mocks/71_assistant.js et
 * 71_assistant_helpdesk.js (fictives). Pas un scénario : tourner.js ne lance que les fichiers NN-…. */
const path = require('path');

/** La fausse API répond tout de suite : l'assistant, lui, prend le temps de lire ; le PDF, de se composer. */
async function lenteur(ctx, ms = 2200) {
  await ctx.route('**/api/cockpit/assistant/magasin', async r => {
    if (r.request().method() === 'POST') { await new Promise(ok => setTimeout(ok, ms)); }
    await r.fallback();
  });
  await ctx.route('**/api/cockpit/assistant/magasin/pdf', async r => { await new Promise(ok => setTimeout(ok, 1800)); await r.fallback(); });
}

/** Pour le film seulement : de la marge sous la page de l'assistant, pour que le pied de la réponse (PDF, « Continuer
 * en conversation ») remonte au-dessus des sous-titres quand on le montre. */
async function marge(p) {
  const css = '.ia-page.mb{margin-bottom:170px}';
  await p.context().addInitScript(c => { document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = c; document.head.appendChild(s); }); }, css);
  await p.addStyleTag({ content: css });
}

/** Attend que l'assistant ait répondu (plus de « … lit les chiffres » ni « … ouvre le manuel » à l'écran). */
const reponse = f => (f.p || f).waitForFunction(() => !document.querySelector('.ia-att'), null, { timeout: 20000 }).then(() => (f.pause ? f.pause(400) : null));

/** Hors film (Film.ouvrir({avant})) : une question tapée en haut du Bilan, puis la réponse attendue. */
async function demander(p, q) {
  await p.fill('.ia-saisie.haut input', q);
  await p.click('.ia-saisie.haut .ia-envoi');
  await p.waitForTimeout(300);
  await reponse(p);
}

/** Pendant la diapositive qui vient (elle couvre l'écran ~0,6 s après son début) : remonter en haut de la page.
 * Appeler juste avant f.fonction(). */
async function sousLaDiapo(f) {
  await f.p.evaluate(() => setTimeout(() => window.scrollTo(0, 0), 900));
}

/** « Mes demandes » de la demande au bureau : 00_commun.js rend GET /helpdesk/magasin vide et passe avant tous les
 * autres modules ; la lecture et l'envoi passent ici par mocks/71_assistant_helpdesk.js (le module que mock.js vient de
 * charger : le même état). */
async function helpdesk(ctx) {
  const H = require(path.join(__dirname, '..', 'mocks', '71_assistant_helpdesk.js'));
  await ctx.route('**/api/cockpit/helpdesk/magasin**', async r => {
    const req = r.request(), u = new URL(req.url());
    let c = null; try { c = req.postDataJSON(); } catch (e) { c = null; }
    const rep = H(req.method(), '/helpdesk/magasin', u.searchParams, c);
    if (rep && rep.__status) { return r.fulfill({ status: rep.__status, json: rep.json || {} }); }
    return r.fulfill({ json: rep || {} });
  });
}

/** Un cadre autour d'un élément tel qu'il est à l'écran, sans faire défiler (coupé aux bords de la fenêtre). */
async function cadre(f, cible, etiq, ms) {
  const loc = typeof cible === 'string' ? f.p.locator(cible).first() : cible;
  const r = await loc.evaluate(e => {
    const b = e.getBoundingClientRect(), y = Math.max(8, b.top), y2 = Math.min(innerHeight - 8, b.bottom);
    return { x: b.left, y, w: b.width, h: Math.max(12, y2 - y) };
  });
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiq || '']);
  if (ms) { await f.pause(ms); }
}

/** Hors film : une illustration de la fausse API (SVG) tirée en JPEG 1280 × 960 dans `fichier`, comme une photo de
 * téléphone, pour un champ « photo » (filechooser). `chemin` : sous ../api/cockpit (ex. '/__photo/croissants.svg'). */
async function jpeg(p, chemin, fichier) {
  const fs = require('fs');
  const b64 = await p.evaluate(u => fetch(u).then(r => r.text()).then(svg => new Promise(ok => {
    const i = new Image();
    i.onload = () => { const c = document.createElement('canvas'); c.width = 1280; c.height = 960; c.getContext('2d').drawImage(i, 0, 0, 1280, 960); ok(c.toDataURL('image/jpeg', 0.88).split(',')[1]); };
    i.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  })), '../api/cockpit' + chemin);
  fs.writeFileSync(fichier, Buffer.from(b64, 'base64'));
}

module.exports = { lenteur, marge, reponse, demander, sousLaDiapo, helpdesk, cadre, jpeg };
