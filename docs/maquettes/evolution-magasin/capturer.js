/* Captures des maquettes « Résultat › Évolution ».
 *   npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
 *   node docs/maquettes/evolution-magasin/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname;
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8099/docs/maquettes/evolution-magasin/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const m of ['a', 'a-halle', 'b', 'c']) {
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.documentElement.scrollHeight);
    const deb = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await p.screenshot({ path: path.join(D, m + '.png'), fullPage: true });
    console.log(m, 'hauteur', h, deb ? 'DÉBORDE' : '');
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
