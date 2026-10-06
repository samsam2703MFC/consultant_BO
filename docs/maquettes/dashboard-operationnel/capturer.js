/* Capture de la maquette (bureau 1440 px) et mesure de sa hauteur.
 *   npx http-server . -p 8099 -c-1   (depuis la racine du dépôt)
 *   node docs/maquettes/dashboard-operationnel/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 } });
  const err = []; p.on('pageerror', e => err.push(e.message));
  await p.goto('http://127.0.0.1:8099/docs/maquettes/dashboard-operationnel/a.html', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  const h = await p.evaluate(() => document.documentElement.scrollHeight), deb = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth);
  await p.screenshot({ path: path.join(__dirname, 'a.png'), fullPage: true });
  await p.screenshot({ path: path.join(__dirname, 'a-ecran.png') });
  console.log('hauteur', h, 'débordement', deb, 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
