/* Captures « L'assistant fait des graphiques » (bureau 1440 px, densité 2).
   Serveur : php -S 127.0.0.1:8099 -t pilotage-reseau   puis : node pilotage-reseau/docs/maquettes/assistant-graphiques/capturer.cjs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/assistant-graphiques/';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: Number(process.env.DPR || 2) });
  for (const f of (process.env.SEUL ? [process.env.SEUL] : ['a-graphique', 'b-modele', 'c-mes-modeles', 'd-radar', 'e-catalogue', 'f-heures', 'g-produit', 'h-poubelle', 'i-google-trafic'])) {
    const p = await ctx.newPage(); const err = []; p.on('pageerror', e => err.push(e.message));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.waitForSelector('body[data-pret="1"]'); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    const h = await p.evaluate(() => Math.max(1000, ...[...document.querySelectorAll('.gr-mod, .gr-menu, .gr-dlg')].map(e => e.getBoundingClientRect().bottom + 40)));
    await p.setViewportSize({ width: 1440, height: Math.ceil(h) }); await p.waitForTimeout(200);
    await p.screenshot({ path: __dirname + '/' + f + '.png', clip: { x: 0, y: 0, width: 1440, height: Math.ceil(h) } });
    console.log(f, Math.ceil(h), 'px', err.length ? 'ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
