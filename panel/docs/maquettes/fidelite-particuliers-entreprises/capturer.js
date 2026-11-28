/* Les captures des maquettes « Fidélité : points et bingo, particuliers et entreprises ».
 *   node panel/docs/maquettes/fidelite-particuliers-entreprises/capturer.js */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const VUES = [['a-particuliers', 'v=a&l=p'], ['a-entreprises', 'v=a&l=e'], ['b-bingo', 'v=b&c=bingo'], ['b-points', 'v=b&c=points'],
  ['c-particuliers', 'v=c&l=p'], ['c-entreprises', 'v=c&l=e']];
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: Number(process.env.ECHELLE || 2) });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('console', m => { if (m.type() === 'error') { err.push(m.text()); } });
  for (const [nom, q] of VUES) {
    await p.goto('file://' + __dirname + '/index.html?' + q, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, nom + '.png'), fullPage: true }); console.log('✓', nom);
  }
  console.log('erreurs', err.length ? err : 'aucune'); await b.close();
})();
