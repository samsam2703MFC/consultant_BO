/* Captures de la maquette « Paramétrage des produits ».
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/parametrage-produits/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1700, height: 1200 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  for (const m of ['a', 'b', 'c']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/parametrage-produits/' + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.addStyleTag({ content: 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}' });
    await p.waitForTimeout(200);
    if (m === 'c') { await p.evaluate(() => { const s = document.querySelector('script:last-of-type'); if (s) { eval(s.textContent); } }); }
    await p.screenshot({ path: path.join(__dirname, m + '.png'), fullPage: true });
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
