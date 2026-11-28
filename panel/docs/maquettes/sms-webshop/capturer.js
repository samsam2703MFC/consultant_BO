/* Les captures des maquettes « Clients › SMS ».
 *   node panel/docs/maquettes/sms-webshop/capturer.js
 * Aucun serveur : le navigateur lit les fichiers sur le disque. */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const ICI = 'file://' + __dirname + '/';
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: Number(process.env.DSF || 2) });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const v of ['envoi', 'parcours', 'suivi', 'particuliers']) {
    await p.goto(ICI + 'index.html?v=' + v, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, 'ecran-' + v + '.png'), fullPage: true });
    console.log('✓ écran', v);
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
