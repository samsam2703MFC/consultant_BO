/* Captures de la maquette « Le rail du dashboard franchisé ».
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/rail-franchise/capturer.js
 * Chaque écran à 1440 × 1000 ; si le rail est plus haut que la fenêtre, la capture s'allonge pour le montrer entier
 * (sa hauteur est notée dans mesures.json). */
const fs = require('fs');
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const BASE = 'http://127.0.0.1:8099/docs/maquettes/rail-franchise/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => { if (!/\/api\/cockpit\//.test(r.url())) { err.push('échec ' + r.url()); } });
  const mesures = {};
  // reel.html se capture avec capturer-reel.js (le cockpit en ligne, en lecture). Ici, ses lectures resteraient en attente
  // ; la route ne sert plus qu'à ne rien lire par erreur.
  await p.route('**/api/cockpit/**', () => {});
  const LONGUES = ['offres', 'offre-edition', 'mail', 'mail-offre'];
  for (const m of ['offres', 'offre-edition', 'mail', 'mail-offre', 'rs-calendrier', 'rs-meta', 'rs-instagram', 'rs-campagnes', 'rs-creer', 'marketing', 'final', 'a', 'b', 'b-coach', 'b-semaine', 'b-mois', 'b-trimestre', 'b-annee', 'c', 'b1', 'b2', 'b3']) {
    await p.setViewportSize({ width: 1440, height: 1000 });
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.querySelector('.sidebar').scrollHeight);
    mesures[m] = { rail: h, tientDans1000: h <= 1000 };
    // Les écrans des offres et de la boîte mail : la capture montre aussi la page entière (sa colonne de droite).
    const hm = LONGUES.includes(m) ? await p.evaluate(() => document.querySelector('.workspace__main').scrollHeight) : 0;
    if (hm > Math.max(h, 1000)) { await p.setViewportSize({ width: 1440, height: hm }); await p.waitForTimeout(100); } else if (h > 1000) { await p.setViewportSize({ width: 1440, height: h }); await p.waitForTimeout(100); }
    await p.screenshot({ path: path.join(__dirname, m + '.png') });
  }
  // Le document seul (gabarit A4 version A), à l'échelle 1.
  await p.setViewportSize({ width: 860, height: 1200 });
  await p.goto(BASE + 'offre-a4.html', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  await (await p.$('.doc-a4')).screenshot({ path: path.join(__dirname, 'offre-a4.png') });
  await p.setViewportSize({ width: 2400, height: 900 });
  const q = await b.newPage({ viewport: { width: 2400, height: 900 }, deviceScaleFactor: 1 });
  for (const [nom, l] of [['planche', 2400], ['planche-b', 3000], ['planche-objectif', 2600], ['planche-reseaux', 2600]]) {
    await q.setViewportSize({ width: l, height: 900 });
    await q.goto(BASE + nom + '.html', { waitUntil: 'networkidle' });
    await q.screenshot({ path: path.join(__dirname, nom + '.jpg'), fullPage: true, type: 'jpeg', quality: 86 });
  }
  fs.writeFileSync(path.join(__dirname, 'mesures.json'), JSON.stringify(mesures, null, 1) + '\n');
  console.log('mesures', mesures, 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
