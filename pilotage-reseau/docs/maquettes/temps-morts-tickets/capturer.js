/* Captures des trois variantes (bureau 1440 px, densité 2).
 *   npx http-server . -p 8099 -c-1                                  (depuis la racine du dépôt)
 *   node pilotage-reseau/docs/maquettes/temps-morts-tickets/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [v, nom] of [['1', 'v1-grille-minutes'], ['2', 'v2-rythme-5-minutes'], ['3', 'v3-fil-de-la-journee']]) {
    const p = await b.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: Number(process.env.DPR || 2) });
    const err = []; p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:8099/pilotage-reseau/docs/maquettes/temps-morts-tickets/index.html?v=' + v, { waitUntil: 'networkidle' });
    await p.waitForSelector('body[data-pret="1"]'); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, nom + '.png'), fullPage: true });
    console.log(nom, 'erreurs', err.length ? err : 'aucune'); await p.close();
  }
  await b.close();
})();
