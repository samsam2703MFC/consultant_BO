/* Captures des trois maquettes (onglet et modale), puis de la planche.
 *   npx http-server . -p 8099 -c-1        (depuis la racine du dépôt ; ou python3 -m http.server 8099)
 *   node docs/maquettes/onglet-produits-developpement/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname, BASE = 'http://127.0.0.1:8099/docs/maquettes/onglet-produits-developpement/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const err = [], mes = {};
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  p.on('pageerror', e => err.push(e.message));
  const vignette = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
  for (const m of ['e', 'e-modale', 'f', 'f-modale', 'g', 'g-modale']) {
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    mes[m] = await p.evaluate(() => ({ h: document.documentElement.scrollHeight, deborde: document.documentElement.scrollWidth > innerWidth }));
    await p.screenshot({ path: path.join(D, m + '.jpg'), fullPage: true, type: 'jpeg', quality: 80 });
    await vignette.goto(BASE + m + '.html', { waitUntil: 'networkidle' }); await vignette.evaluate(() => document.fonts.ready);
    await vignette.screenshot({ path: path.join(D, m + '-haut.jpg'), clip: { x: 0, y: 0, width: 1440, height: m.endsWith('modale') ? 1180 : 1500 }, type: 'jpeg', quality: 78 });
  }
  const pl = await b.newPage({ viewport: { width: 1800, height: 900 }, deviceScaleFactor: 1 });
  await pl.goto(BASE + 'planche.html', { waitUntil: 'networkidle' }); await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(D, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
