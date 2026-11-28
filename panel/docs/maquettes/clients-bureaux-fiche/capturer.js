/* Les captures des maquettes « fiche d'un bureau ».  node panel/docs/maquettes/clients-bureaux-fiche/capturer.js */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const v of ['a', 'b', 'c']) {
    await p.goto('file://' + __dirname + '/index.html?v=' + v, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, 'maquette-' + v + '.png'), fullPage: true }); console.log('✓', v);
  }
  console.log('erreurs', err.length ? err : 'aucune'); await b.close();
})();
