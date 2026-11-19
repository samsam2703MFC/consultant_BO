/* Captures de la maquette (bureau 1440 px, densité 2).
 *   npx http-server . -p 8099 -c-1                 (depuis la racine du dépôt)
 *   node pilotage-reseau/docs/maquettes/temps-morts/capturer.js     */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const VUES = [['reseau', 'reseau'], ['2', 'corbais'], ['3', 'gosselies'], ['4', 'halle'], ['5', 'sombreffe']];
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const dpr = Number(process.env.DPR || 2);
  for (const [v, nom] of VUES) {
    const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: dpr });
    const err = []; p.on('pageerror', e => err.push(e.message)); p.on('console', m => { if (m.type() === 'error') err.push(m.text()); });
    await p.goto('http://127.0.0.1:8099/pilotage-reseau/docs/maquettes/temps-morts/index.html?v=' + v + '&seuil=10', { waitUntil: 'networkidle' });
    await p.waitForSelector('body[data-pret="1"]');
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.documentElement.scrollHeight);
    const deb = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    await p.screenshot({ path: path.join(__dirname, nom + '.png'), fullPage: true });
    console.log(nom.padEnd(10), 'hauteur', h, 'débordement', deb, 'erreurs', err.length ? err : 'aucune');
    await p.close();
  }
  await b.close();
})();
