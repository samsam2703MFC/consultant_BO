/* Captures des maquettes « Formation continue » (22/11/2026).
   Serveur local à la racine du dépôt : php -S 127.0.0.1:8099 -t .
   Puis : node formation/docs/maquettes/formation-continue/capturer.cjs [nom…] */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8099/formation/docs/maquettes/formation-continue/';
const tous = fs.readdirSync(__dirname).filter(f => /^\d\d-.*\.html$/.test(f)).map(f => f.replace(/\.html$/, ''));
const voulus = process.argv.slice(2);
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--proxy-server=direct://'] });
  for (const f of tous.filter(f => !voulus.length || voulus.some(v => f.startsWith(v)))) {
    const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(250);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, await p.evaluate(() => document.body.scrollHeight), 'px haut,', await p.evaluate(() => document.documentElement.scrollWidth), 'px large', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await ctx.close();
  }
  await b.close();
})();
