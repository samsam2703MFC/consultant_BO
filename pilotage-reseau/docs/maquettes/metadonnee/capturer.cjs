/* Captures des trois maquettes MétaDonnée (serveur local : php -S 127.0.0.1:8099 -t <racine du dépôt>). */
let chromium;
try { ({ chromium } = require('playwright')); } catch (e) { ({ chromium } = require('/opt/node22/lib/node_modules/playwright')); }
const BASE = 'http://127.0.0.1:8099/docs/maquettes/metadonnee/';
const seules = process.argv.slice(2);
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1720, height: 1000 }, deviceScaleFactor: 1.25 });
  for (const f of ['a-assistant', 'b-flux', 'c-franchises'].filter(f => !seules.length || seules.includes(f))) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    p.on('console', m => { if (m.type() === 'error') err.push(m.text()); });
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(500);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', '· débordement', await p.evaluate(() => document.documentElement.scrollWidth), err.length ? '· ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
