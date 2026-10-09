/* Captures des trois maquettes (serveur local : php -S 127.0.0.1:8099 -t <racine du dépôt>). */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/gestion-consultant/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1.25 });
  for (const f of ['a-mon-planning', 'b-taches-controles', 'c-reseau', 'd-cadre-visite']) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.waitForTimeout(600);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
