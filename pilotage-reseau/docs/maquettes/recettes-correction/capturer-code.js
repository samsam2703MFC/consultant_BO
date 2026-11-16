/* Captures de l'écran CODÉ « Recettes à corriger : corriger d'ici » (13/11/2026) : le vrai cockpit (public/index.html),
 * son API simulée par les réponses que produit le test PHP (bin/tests_verification_correction.php, faux panel).
 *
 *   VRC_FIXTURES=/tmp/vrc php bin/tests_verification_correction.php     (depuis pilotage-reseau/)
 *   php -S 127.0.0.1:8099 -t .
 *   node docs/maquettes/recettes-correction/capturer-code.js /tmp/vrc
 *
 * Rend code-liste.png (la liste et ses gestes), code-ligne.png (une ligne ouverte : vérifier, puis appliquer),
 * code-apres.png (la liste recalculée, la correction en tête), code-volet.png (la recette en correction),
 * code-registre.png (le registre). */
const fs = require('fs'), path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const FX = process.argv[2] || process.env.VRC_FIXTURES;
const D = __dirname;
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8099/';
const J = n => JSON.parse(fs.readFileSync(path.join(FX, n + '.json'), 'utf8'));
const META = { reseau: { nom: 'L’Atelier by', sousTitre: '' }, utilisateur: { initiales: '', nom: '', role: 'CEO' }, aujourdhui: '2026-11-13', dateLabel: 'vendredi 13 novembre 2026', periodeLabel: 'Novembre 2026',
  exercice: 2026, moisLabels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'], seuils: { food: 32, labour: 33, overhead: 13.5, royalties: 3, financieres: 2.2, caEtp: 13000 },
  contribOuverture: 0, notes: {}, familles: [], reportTypes: [] };
const OBJ = { '/reporting': { reports: [], alertRules: [] }, '/pwa/reports': { base: '', magasins: [], partages: [] },
  '/pwa/tasks': { date: '', dates: [], shops: [], consultants: [], totals: { taches: 0, valides: 0, refuses: 0, aValider: 0, noteMoy: null }, indispo: true },
  '/pwa/compte': { base: '', phone: '', motDePasseDefini: false, configure: false }, '/erp/compte': { base: '', phone: '', motDePasseDefini: false, configure: false, jetonValide: false },
  '/production/groupes': { groupes: [] }, '/production/categories': { categories: [] }, '/production/periodes': { periodes: [] }, '/referentiels/roles': { source: null, roles: [] } };
const DEPLIER = 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}';

(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  const etat = { liste: J('recettes') };
  await p.route('**/api/cockpit/**', async r => {
    const u = new URL(r.request().url()); const ch = u.pathname.replace(/^.*\/api\/cockpit/, ''); const m = r.request().method();
    const rep = v => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(v) });
    if (ch === '/auth/status') { return rep({ setup: true, authed: true, disabled: true }); }
    if (ch === '/meta') { return rep(META); }
    if (ch === '/verification/recettes') { return rep(etat.liste); }
    if (ch === '/verification/recettes/fiche') { return rep(J('fiche-' + u.searchParams.get('pid'))); }
    if (ch === '/verification/recettes/edition') { return rep(J('edition-' + u.searchParams.get('pid'))); }
    if (ch === '/verification/recettes/candidats') { return rep(J('candidats-100')); }
    if (ch === '/verification/recettes/registre') { return rep(J('registre')); }
    if (ch === '/verification/recettes/corriger' && m === 'POST') {
      const c = JSON.parse(r.request().postData() || '{}');
      const nom = c.geste === 'recette' ? 'volet' : 'unite';
      if (c.apercu) { return rep(J('apercu-' + nom)); }
      const res = J('corriger-' + nom); etat.liste = res.liste; return rep(res);
    }
    if (m === 'GET' && OBJ[ch]) { return rep(OBJ[ch]); }
    if (m === 'GET') { return rep([]); }
    return rep({ ok: true });
  });
  const pleine = async nom => { await p.addStyleTag({ content: DEPLIER }); await p.waitForTimeout(150); await p.screenshot({ path: path.join(D, nom), fullPage: true }); };
  const clic = async (texte, dans) => { const el = p.locator((dans || '') + ' button', { hasText: texte }).first(); await el.click(); await p.waitForTimeout(400); };

  await p.goto(BASE + 'public/index.html#/verification/recettes', { waitUntil: 'networkidle' });
  await p.waitForSelector('[data-screen="verification-recettes"] .vr-t');
  await p.evaluate(() => document.fonts.ready);
  await pleine('code-liste.png');

  // Une ligne : ÷1000 sur les bâtons du Pain au Chocolat Nappé — vérifier, puis appliquer.
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForSelector('.vr-t');
  await clic('Appliquer ÷1000');
  await clic('Vérifier ce qui sera écrit');
  await p.locator('.rc-apercu details').first().evaluate(e => { e.open = true; });
  const ligne = p.locator('tr.rc-ouvert');
  await ligne.scrollIntoViewIfNeeded();
  const bb = await p.locator('tr:has(+ tr.rc-ouvert)').boundingBox(), bo = await ligne.boundingBox();
  await p.screenshot({ path: path.join(D, 'code-ligne.png'), clip: { x: 250, y: Math.max(0, bb.y - 60), width: 1190, height: bo.y + bo.height - bb.y + 80 } });
  await clic('Appliquer au panel');
  await p.waitForSelector('.rc-recentes');
  await pleine('code-apres.png');

  // Le volet : la sous-recette Sauce César du Wrapps, de 1 l à 0,1 l.
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForSelector('.vr-t');
  await p.locator('tr', { hasText: 'Wrapps - Poulet' }).locator('button', { hasText: 'Corriger' }).first().click();
  await p.waitForSelector('.vr-fiche .rc-barre');
  const champ = p.locator('.vr-fiche tr', { hasText: 'Sauce César' }).locator('input').first();
  await champ.fill('0,1'); await p.waitForTimeout(300);
  await clic('Vérifier ce qui sera écrit', '.vr-fiche');
  await p.waitForSelector('.vr-fiche .rc-apercu');
  const h = await p.evaluate(() => document.querySelector('.vr-fiche').scrollHeight);
  await p.setViewportSize({ width: 1440, height: Math.min(2400, Math.max(950, h)) });
  await p.waitForTimeout(200);
  await p.screenshot({ path: path.join(D, 'code-volet.png') });
  await p.setViewportSize({ width: 1440, height: 950 });

  // Le registre.
  await p.goto(BASE + 'public/index.html#/verification/recettes', { waitUntil: 'networkidle' }); await p.reload({ waitUntil: 'networkidle' }); await p.waitForSelector('.vr-t');
  await clic('Ouvrir', '.rc-reg');
  await p.waitForSelector('.rc-reg .vr-t');
  await p.addStyleTag({ content: DEPLIER });
  const reg = p.locator('.rc-reg'); await reg.scrollIntoViewIfNeeded();
  await reg.screenshot({ path: path.join(D, 'code-registre.png') });

  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
