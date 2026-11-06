/* Captures des deux maquettes (bureau et téléphone) et de la planche.
 *   npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
 *   node docs/maquettes/modale-produit/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname, BASE = 'http://127.0.0.1:8099/docs/maquettes/modale-produit/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const err = [], mes = {};
  for (const [suf, vp] of [['', { width: 1440, height: 900 }], ['-tel', { width: 390, height: 844 }]]) {
    const p = await b.newPage({ viewport: vp, deviceScaleFactor: suf ? 2 : 1 }); p.on('pageerror', e => err.push(e.message));
    for (const m of ['a-semaines', 'a-prix', 'b-semaines', 'b-prix']) {
      await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
      mes[m + suf] = await p.evaluate(() => ({ h: document.documentElement.scrollHeight, deb: document.documentElement.scrollWidth > innerWidth }));
      await p.screenshot({ path: path.join(D, m + suf + '.png'), fullPage: true });
    }
    await p.close();
  }
  const pl = await b.newPage({ viewport: { width: 2148, height: 1200 } });
  await pl.goto(BASE + 'planche.html', { waitUntil: 'networkidle' }); await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(D, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
