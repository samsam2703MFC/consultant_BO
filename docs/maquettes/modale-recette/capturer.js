/* Capture de la maquette, bureau (1440 px) et téléphone (390 px).
 *   php -S 127.0.0.1:8099 -t .   (depuis la racine du dépôt)
 *   node docs/maquettes/modale-recette/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [suf, vp] of [['', { width: 1440, height: 950 }], ['-tel', { width: 390, height: 844 }]]) {
    const p = await b.newPage({ viewport: vp, deviceScaleFactor: suf ? 2 : 1 });
    const err = []; p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/modale-recette/a.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.documentElement.scrollHeight), deb = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    await p.screenshot({ path: path.join(__dirname, 'a' + suf + '.png'), fullPage: true });
    console.log('a' + suf, 'hauteur', h, 'débordement', deb, 'erreurs', err.length ? err : 'aucune');
    await p.close();
  }
  await b.close();
})();
