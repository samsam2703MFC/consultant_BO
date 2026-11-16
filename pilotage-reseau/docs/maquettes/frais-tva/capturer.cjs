/* Captures des maquettes « Frais et TVA » (bureau 1440 px, densité 2).
   Serveur : php -S 127.0.0.1:8099 -t pilotage-reseau   puis : node pilotage-reseau/docs/maquettes/frais-tva/capturer.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/frais-tva/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: Number(process.env.DPR || 2) });
  for (const f of ['a-onglet-frais', 'b-pnl-hors-tva']) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.waitForSelector('body[data-pret="1"]');
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: true });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
