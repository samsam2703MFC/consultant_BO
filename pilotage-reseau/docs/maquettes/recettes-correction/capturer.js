/* Captures de la maquette « Recettes à corriger : corriger d'ici ».
 *   php -S 127.0.0.1:8099 -t .        (depuis pilotage-reseau/ — ou npx http-server . -p 8099 -c-1)
 *   node docs/maquettes/recettes-correction/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname;
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8099/docs/maquettes/recettes-correction/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  p.on('requestfailed', r => err.push('échec ' + r.url()));
  // Le cockpit défile dans son <main> : on lève les hauteurs pour capturer la page entière.
  const DEPLIER = 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}';
  for (const m of ['a', 'c']) {
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' });
    await p.addStyleTag({ content: DEPLIER });
    await p.evaluate(() => document.fonts.ready);
    const deb = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await p.screenshot({ path: path.join(D, m + '.png'), fullPage: true });
    console.log(m, 'hauteur', await p.evaluate(() => document.documentElement.scrollHeight), deb ? 'DÉBORDE' : '');
  }
  // b : le volet est fixe ; la fenêtre prend la hauteur du volet pour le montrer en entier.
  await p.goto(BASE + 'b.html', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  const h = await p.evaluate(() => document.querySelector('.vr-fiche').scrollHeight);
  await p.setViewportSize({ width: 1440, height: Math.max(950, h) });
  await p.screenshot({ path: path.join(D, 'b.png') });
  console.log('b', 'hauteur du volet', h);
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
