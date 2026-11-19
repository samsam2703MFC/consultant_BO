/* Les captures des maquettes « B2B et commandes au téléphone ».
 * Serveur statique sur 8099 depuis pilotage-reseau/ :
 *   python3 -m http.server 8099      (ou npx http-server . -p 8099 -c-1)
 *   node pilotage-reseau/docs/maquettes/b2b-commandes-mobile/capturer.js */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const B = 'http://127.0.0.1:8099/docs/maquettes/b2b-commandes-mobile/index.html?v=';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  for (const v of ['1', '2', '3']) {
    await p.goto(B + v, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    await (await p.$('.tel')).screenshot({ path: path.join(__dirname, 'ecran-' + v + '.png') });
    console.log('✓ écran', v);
  }
  await p.setViewportSize({ width: 1400, height: 900 });
  await p.goto(B + 'planche', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(__dirname, 'planche.jpg'), type: 'jpeg', quality: 84, fullPage: true });
  console.log('✓ planche');
  await b.close();
})();
