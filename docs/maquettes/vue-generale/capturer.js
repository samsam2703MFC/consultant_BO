/* Captures des trois maquettes (ouvertes et repliées) et mesure de leur hauteur.
 *   npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
 *   node docs/maquettes/vue-generale/capturer.js */
const path = require('path');
const fs = require('fs');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname;
const BASE = 'http://127.0.0.1:8099/docs/maquettes/vue-generale/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 }, deviceScaleFactor: 1 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  const mes = {};
  for (const m of ['a', 'b', 'c']) {
    for (const [suffixe, q] of [['', ''], ['-replie', '?replie']]) {
      await p.goto(BASE + m + '.html' + q, { waitUntil: 'networkidle' });
      await p.evaluate(() => document.fonts.ready);
      const h = await p.evaluate(() => document.documentElement.scrollHeight);
      const deb = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      mes[m + suffixe] = { hauteur: h, debordement: deb };
      await p.screenshot({ path: path.join(D, m + suffixe + '.png'), fullPage: true });
    }
  }
  fs.writeFileSync(path.join(D, 'mesures.json'), JSON.stringify(mes, null, 1));
  const pl = await b.newPage({ viewport: { width: 2280, height: 1200 } });
  await pl.goto(BASE + 'planche.html', { waitUntil: 'networkidle' });
  await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(D, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
