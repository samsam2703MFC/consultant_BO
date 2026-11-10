/* Captures des maquettes « Compétences sous chaque formation » (serveur local, racine = pilotage-reseau :
   php -S 127.0.0.1:8099 -t pilotage-reseau). node docs/maquettes/portail-formation/capturer-competences.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/portail-formation/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const f of ['aligne-tableau', 'aligne-redacteur']) {
    const ctx = await b.newContext({ viewport: { width: 1800, height: 1000 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, await p.evaluate(() => document.body.scrollHeight), 'px haut,', await p.evaluate(() => document.documentElement.scrollWidth), 'px large', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await ctx.close();
  }
  await b.close();
})();
