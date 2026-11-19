/* Captures de la maquette « Rappels SMS des contrôles qualité ».
   Serveur statique à la racine du dépôt : php -S 127.0.0.1:8099 -t pilotage-reseau
   puis : node pilotage-reseau/docs/maquettes/rappel-sms-controles/capturer.cjs */
const fs = require('fs');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/rappel-sms-controles/';
const page = fs.readFileSync(__dirname + '/index.html', 'utf8');
const doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>' + page + '</body></html>';
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1.25 });
  for (const [nom, ecran] of [['a-reglages', 'reglages'], ['b-journal', 'journal'], ['c-sms', 'sms']]) {
    if (process.env.SEUL && process.env.SEUL !== nom) { continue; }
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.route(BASE + '__apercu.html', r => r.fulfill({ contentType: 'text/html; charset=utf-8', body: doc }));
    await p.goto(BASE + '__apercu.html', { waitUntil: 'networkidle' });
    await p.evaluate('document.fonts.ready');
    await p.evaluate(e => window.maquette.montrer(e), ecran);
    await p.waitForTimeout(300);
    const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await p.screenshot({ path: __dirname + '/' + nom + '.png', fullPage: true });
    console.log(nom, await p.evaluate(() => document.body.scrollHeight), 'px', large ? '· DÉBORDE' : '', err.join(' | '));
    await p.close();
  }
  await b.close();
})();
