/* Captures des maquettes Formation (serveur local à la racine du dépôt : php -S 127.0.0.1:8099 -t .).
   node docs/maquettes/formation/capturer.cjs */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const BASE = 'http://127.0.0.1:8099/docs/maquettes/formation/';
const PAGES = [
  ['a-bibliotheque', 1500, 1000],
  ['b-fiche-sop', 1500, 1000],
  ['c1-tablette-qui', 1280, 800, true],
  ['c2-tablette-etape', 1280, 800, true],
  ['c3-tablette-validation', 1280, 800, true],
  ['d-manuel', 1500, 1000],
  ['e-competences', 1500, 1000],
  ['f-redaction', 1500, 1000],
  ['planche', 1600, 1000]
];
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const seul = process.argv[2];
  for (const [f, w, h, fixe] of PAGES) {
    if (seul && seul !== f) { continue; }
    const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1.25 });
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(BASE + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(500);
    await p.screenshot({ path: __dirname + '/' + f + '.png', fullPage: !fixe });
    console.log(f, '·', await p.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await ctx.close();
  }
  await b.close();
})();
