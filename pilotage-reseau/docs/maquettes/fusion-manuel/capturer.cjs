/* Captures des maquettes de fusion (serveur local à la racine du dépôt : php -S 127.0.0.1:8099 -t .).
   node docs/maquettes/fusion-manuel/capturer.cjs [page] */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/fusion-manuel/';
const PAGES = [['p1-phases', 1560], ['p2-domaines', 1560], ['p3-postes', 1560], ['plan', 1560]];
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const seul = process.argv[2];
  for (const [f, w] of PAGES) {
    if (seul && seul !== f) { continue; }
    const ctx = await b.newContext({ viewport: { width: w, height: 1000 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(400);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', await p.evaluate(() => document.documentElement.scrollWidth), 'large', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await ctx.close();
  }
  await b.close();
})();
