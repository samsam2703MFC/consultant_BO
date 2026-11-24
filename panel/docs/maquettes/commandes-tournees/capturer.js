/* Les captures de la maquette « Commandes et tournées » (magasin 3, Gosselies).
 *   node panel/docs/maquettes/commandes-tournees/capturer.js
 * Aucun serveur à lancer : le navigateur lit les fichiers sur le disque (polices et logo du dépôt). */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const ICI = 'file://' + __dirname + '/';
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const v of ['a', 'b', 'c']) {
    await p.goto(ICI + 'index.html?v=' + v, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, 'ecran-' + v + '.png'), fullPage: true });
    console.log('✓ écran', v);
    // L'interrupteur abricot de A et B, de près : la barre du titre et la barre du jour.
    if (v !== 'c') {
      const r = await p.evaluate(() => { const a = document.querySelector('.tete').getBoundingClientRect(), b = document.querySelector('.barre-jour').getBoundingClientRect(); return { x: a.left - 8, y: a.top - 8, width: a.width + 16, height: b.bottom - a.top + 16 }; });
      await p.screenshot({ path: path.join(__dirname, 'bascule-' + (v === 'a' ? 'liste' : 'tournees') + '.png'), clip: r });
    }
  }
  const q = await b.newPage({ viewport: { width: 2400, height: 1000 }, deviceScaleFactor: 1 });
  await q.goto(ICI + 'planche.html', { waitUntil: 'load' }); await q.evaluate(() => document.fonts.ready);
  await q.screenshot({ path: path.join(__dirname, 'planche.jpg'), type: 'jpeg', quality: 84, fullPage: true });
  console.log('✓ planche · erreurs', err.length ? err : 'aucune');
  await b.close();
})();
