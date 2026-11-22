/* Captures de la maquette « Fiche matière » : le volet entier (sa hauteur fait la fenêtre).
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/fiche-matiere/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1600, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  for (const m of ['a', 'b']) {
    await p.setViewportSize({ width: 1600, height: 950 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/fiche-matiere/' + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.querySelector('.vr-fiche').scrollHeight);
    await p.setViewportSize({ width: 1600, height: Math.max(950, h) });
    await p.screenshot({ path: path.join(__dirname, m + '.png') });
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
