/* Les captures de la maquette « formulaire d'un bureau ».  node panel/docs/maquettes/bureau-formulaire/capturer.js */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
(async () => {
  const b = await pw.chromium.launch(); const err = [];
  for (const [e, w, h] of [['societe', 1440, 900], ['livraison', 1440, 900], ['mobile', 390, 844], ['merci', 1440, 820]]) {
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 2 }); p.on('pageerror', x => err.push(x.message));
    await p.goto('file://' + __dirname + '/index.html?ecran=' + e, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, 'maquette-' + e + '.png'), fullPage: true }); console.log('✓', e); await p.close();
  }
  console.log('erreurs', err.length ? err : 'aucune'); await b.close();
})();
