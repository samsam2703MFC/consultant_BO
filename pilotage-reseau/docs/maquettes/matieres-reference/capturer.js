/* Captures de la maquette « Matières premières » (bureau 1600 px).
 *   php -S 127.0.0.1:8099 -t .        (depuis pilotage-reseau/)
 *   node docs/maquettes/matieres-reference/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname;
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8099/docs/maquettes/matieres-reference/';
const DEPLIER = 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1600, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  await p.goto(BASE + 'a.html', { waitUntil: 'networkidle' });
  await p.addStyleTag({ content: DEPLIER });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(D, 'a.png'), fullPage: true });
  // b : la liste déroulante ouverte, de près.
  const t = await p.locator('tr.mm-on').first().boundingBox();
  await p.screenshot({ path: path.join(D, 'b.png'), clip: { x: 640, y: t.y - 10, width: 960, height: 330 } });
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
