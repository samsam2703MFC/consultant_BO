/* Les planches du devis : planche.html → planche.jpg (A, B, C),
 * planche-c.html → planche-c.jpg (les trois versions de C).
 * Les JPG des devis viennent de generer.php (TCPDF), à lancer d'abord.
 *
 *   node docs/maquettes/offre-pdf/planche.js   (serveur statique sur 8099 depuis la racine)
 */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname + '/';
(async () => {
  const b = await chromium.launch();
  const q = await b.newPage();
  await q.setViewportSize({ width: 1760, height: 1000 });
  const err = [];
  q.on('pageerror', e => err.push(e.message));
  q.on('requestfailed', r => err.push('échec ' + r.url()));
  for (const nom of ['planche', 'planche-c']) {
    await q.goto(`http://127.0.0.1:8099/docs/maquettes/offre-pdf/${nom}.html`, { waitUntil: 'load' });
    await q.evaluate(() => document.fonts.ready);
    await q.waitForTimeout(500);
    await (await q.$('#planche')).screenshot({ path: OUT + nom + '.jpg', type: 'jpeg', quality: 82 });
  }
  console.log('erreurs :', err.join(' | ') || 'aucune');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
