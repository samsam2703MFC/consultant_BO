/* Capture de la maquette réelle « Aligner les prix sur les fournisseurs » (pleine page, 1700 px).
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/alignement-fournisseurs/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1700, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  await p.goto('http://127.0.0.1:8099/docs/maquettes/alignement-fournisseurs/a.html', { waitUntil: 'networkidle' });
  await p.addStyleTag({ content: 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}' });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(__dirname, 'a.png'), fullPage: true });
  await p.screenshot({ path: path.join(__dirname, 'b.png'), clip: { x: 246, y: 0, width: 1454, height: 1300 } });
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
