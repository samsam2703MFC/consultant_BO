/* Les captures des maquettes « un passage de la frise ouvre son ticket ».
 *   node panel/docs/maquettes/clients-fidelite-fiche/capturer-ticket.js
 * Aucun serveur : le navigateur lit les fichiers sur le disque. */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 1520, height: 1200 }, deviceScaleFactor: Number(process.env.DSF || 2) });
  const err = []; p.on('pageerror', e => err.push(e.message));
  await p.goto('file://' + __dirname + '/ticket.html', { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(__dirname, 'ticket-ensemble.png'), fullPage: true });
  for (const v of ['1', '2', '3']) { await p.locator(`.mq-col[data-v="${v}"]`).screenshot({ path: path.join(__dirname, 'ticket-' + v + '.png') }); console.log('✓ ticket', v); }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
