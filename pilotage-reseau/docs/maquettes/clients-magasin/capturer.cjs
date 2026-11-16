/* Captures des maquettes « Clients du magasin » (bureau 1440 px, densité 2).
   Serveur : php -S 127.0.0.1:8099 -t pilotage-reseau   puis : node pilotage-reseau/docs/maquettes/clients-magasin/capturer.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/clients-magasin/';
(async () => {
  // Playwright cache les barres de défilement en headless : on les montre (filet Ruby de 3 px).
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'], ignoreDefaultArgs: ['--hide-scrollbars'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: Number(process.env.DPR || 2) });
  for (const f of ['a-clients', 'b-fiche-client', 'c-fiche-pro']) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.waitForSelector('body[data-pret="1"]');
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    // Une capture « pleine page » ne peint pas les barres de défilement des listes : la fenêtre prend la hauteur de la page.
    for (let i = 0; i < 2; i++) { await p.setViewportSize({ width: 1440, height: await p.evaluate(() => document.documentElement.scrollHeight) }); await p.waitForTimeout(150); }
    await p.screenshot({ path: __dirname + '/' + f + '.png' });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
