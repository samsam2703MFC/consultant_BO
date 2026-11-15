/* Capture de reel.html remplie avec les chiffres du jour : la page tourne en local, ses lectures de l'API du cockpit
 * partent vers le cockpit en ligne. LECTURE SEULE : seules les requêtes GET passent ; toute écriture (POST, PUT…) est
 * bloquée et notée.
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/)
 *   COCKPIT=<adresse du cockpit, jusqu'à /consulant_bo> node docs/maquettes/rail-franchise/capturer-reel.js
 * → reel.png (1 440 × 1 000) et reel-entier.png (toute la page).
 * Les captures se partagent : les prénoms des personnes (le planning et les vendeurs de /exploitation/jour, qui a fait
 * ou contrôlé une tâche, le consultant) sont remplacés à la lecture par « Personne A », « Personne B »… La page et
 * l'API ne changent pas ; seule la copie que lit le navigateur de la capture est masquée. */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const COCKPIT = (process.env.COCKPIT || '').replace(/\/+$/, '');
if (!COCKPIT) { console.error('COCKPIT attendu : l’adresse du cockpit, jusqu’à /consulant_bo'); process.exit(2); }
const BASE = 'http://127.0.0.1:8099/docs/maquettes/rail-franchise/';
const ALIAS = new Map();
const alias = nom => { if (!ALIAS.has(nom)) { ALIAS.set(nom, 'Personne ' + String.fromCharCode(65 + ALIAS.size % 26)); } return ALIAS.get(nom); };
const CLES_PERSONNE = new Set(['faitePar', 'valideePar', 'consultant', 'par', 'auteur', 'consultantNom', 'vendeur']);
/** Remplace les noms de personnes d'une réponse : les champs de personne partout, `nom` sous planning et vendeurs. */
function masquer(x, parent) {
  if (Array.isArray(x)) { return x.map(v => masquer(v, parent)); }
  if (!x || typeof x !== 'object') { return x; }
  const o = {};
  for (const [k, v] of Object.entries(x)) {
    if (typeof v === 'string' && v && (CLES_PERSONNE.has(k) || (k === 'nom' && (parent === 'planning' || parent === 'vendeurs')))) { o[k] = alias(v); }
    else { o[k] = masquer(v, k); }
  }
  return o;
}
(async () => {
  const b = await pw.chromium.launch({ args: ['--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  const notes = { lues: 0, bloquees: [], echecs: [], erreurs: [] };
  p.on('pageerror', e => notes.erreurs.push(e.message));
  // L'API de la page (../api/cockpit, vue depuis docs/maquettes/rail-franchise/) → l'API du cockpit en ligne.
  await p.route('**/docs/maquettes/api/cockpit/**', async route => {
    const req = route.request();
    if (req.method() !== 'GET') { notes.bloquees.push(req.method() + ' ' + req.url()); return route.abort(); }
    const url = COCKPIT + '/api/cockpit/' + req.url().split('/docs/maquettes/api/cockpit/')[1];
    try {
      const r = await route.fetch({ url, timeout: 120000 }); notes.lues++;
      if (r.status() >= 400) { notes.echecs.push(r.status() + ' ' + url.replace(COCKPIT, '')); }
      let corps = await r.text();
      try { corps = JSON.stringify(masquer(JSON.parse(corps), '')); } catch (e) { /* pas du JSON : tel quel */ }
      return route.fulfill({ response: r, body: corps });
    }
    catch (e) { notes.echecs.push('réseau ' + url.replace(COCKPIT, '')); return route.abort(); }
  });
  await p.goto(BASE + 'reel.html?shop=' + (process.env.SHOP || '4'), { waitUntil: 'load' });
  await p.waitForLoadState('networkidle', { timeout: 180000 }).catch(() => {});
  await p.waitForTimeout(4000);
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(__dirname, 'reel.png') });
  // Toute la page : la colonne du dashboard défile seule ; on l'allonge pour la capture.
  const h = await p.evaluate(() => { const m = document.querySelector('.rl-main'); return Math.max(1000, m.scrollHeight + m.getBoundingClientRect().top); });
  await p.setViewportSize({ width: 1440, height: Math.ceil(h) });
  await p.waitForTimeout(800);
  await p.screenshot({ path: path.join(__dirname, 'reel-entier.png') });
  notes.personnesMasquees = ALIAS.size;
  console.log(JSON.stringify(notes, null, 1));
  await b.close();
})();
