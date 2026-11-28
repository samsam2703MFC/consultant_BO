/* Les captures des maquettes « la fiche plus serrée et quand il vient ».
 *   node panel/docs/maquettes/clients-fidelite-fiche/capturer.js
 * Aucun serveur : le navigateur lit les fichiers sur le disque (les feuilles de style du vrai dashboard comprises). */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const ICI = 'file://' + __dirname + '/';
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 2000, height: 1300 }, deviceScaleFactor: Number(process.env.DSF || 2) });
  const err = []; p.on('pageerror', e => err.push(e.message));
  await p.goto(ICI + 'index.html', { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(__dirname, 'ensemble.png'), fullPage: true });
  console.log('✓ ensemble');
  for (const v of ['avant', 'a', 'b', 'c', 'd']) {
    await p.locator(`.mq-col[data-v="${v}"]`).screenshot({ path: path.join(__dirname, 'fiche-' + v + '.png') });
    console.log('✓', v, await p.locator(`.mq-col[data-v="${v}"] .mq-h`).textContent());
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
