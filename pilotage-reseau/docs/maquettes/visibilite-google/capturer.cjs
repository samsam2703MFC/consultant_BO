/* Captures des maquettes « Visibilité Google ».
   Serveur : php -S 127.0.0.1:8099 -t pilotage-reseau   puis : node pilotage-reseau/docs/maquettes/visibilite-google/capturer.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/visibilite-google/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  for (const f of ['a-fiches-google', 'b-rang-local']) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(400);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
