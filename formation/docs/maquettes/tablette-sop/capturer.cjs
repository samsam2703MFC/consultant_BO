/* Captures des maquettes « Lire une SOP sur la tablette » (07/11/2026). Serveur local, racine = pilotage-reseau :
   php -S 127.0.0.1:8099 -t pilotage-reseau ; puis node docs/maquettes/tablette-sop/capturer.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/tablette-sop/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const m of ['a', 'b', 'c', 'd']) {
    const ctx = await b.newContext({ viewport: { width: m === 'a' ? 1900 : 1420, height: 900 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(BASE + 'planche.html?m=' + m, { waitUntil: 'networkidle' });
    await p.waitForTimeout(800);
    await p.screenshot({ path: __dirname + '/maquette-' + m + '.png', fullPage: true });
    console.log(m, err.length ? 'ERREURS ' + err.join(' | ') : 'ok');
    await ctx.close();
  }
  await b.close();
})();
