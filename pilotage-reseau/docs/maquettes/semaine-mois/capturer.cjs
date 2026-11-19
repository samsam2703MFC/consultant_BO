/* Captures des maquettes « Semaine et Mois » : quatre directions × deux périodes (bureau 1440 px, densité 2).
   Serveur : php -S 127.0.0.1:8099 -t pilotage-reseau   puis : node pilotage-reseau/docs/maquettes/semaine-mois/capturer.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/semaine-mois/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'], ignoreDefaultArgs: ['--hide-scrollbars'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: Number(process.env.DPR || 2) });
  for (const f of ['a-quatre-blocs', 'b-tuiles-onglets', 'c-recit', 'd-tableau']) {
    for (const p of ['semaine', 'mois']) {
      const pg = await ctx.newPage(); const err = [];
      pg.on('pageerror', e => err.push(e.message));
      await pg.goto(BASE + f + '.html?p=' + p, { waitUntil: 'networkidle' });
      await pg.waitForSelector('body[data-pret="1"]');
      await pg.evaluate(() => document.fonts.ready);
      for (let i = 0; i < 2; i++) { await pg.setViewportSize({ width: 1440, height: await pg.evaluate(() => document.documentElement.scrollHeight) }); await pg.waitForTimeout(150); }
      await pg.screenshot({ path: __dirname + '/' + f + '-' + p + '.png' });
      console.log(f, p, '·', await pg.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
      await pg.close();
    }
  }
  await b.close();
})();
