/* Captures des maquettes « Analyse ».
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/rail-franchise/capturer-analyse.js
 * Chaque proposition à 1440 de large, sur toute sa hauteur. */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const BASE = 'http://127.0.0.1:8099/docs/maquettes/rail-franchise/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const m of ['analyse-a', 'analyse-b', 'analyse-c', 'analyse-d', 'analyse-e', 'analyse-f']) {
    await p.setViewportSize({ width: 1440, height: 1000 });
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    // La hauteur : le bas du dernier bloc de la page (le conteneur défile seul : son scrollHeight ne suffit pas).
    const h = await p.evaluate(() => { const m = document.querySelector('.workspace__main'); return Math.ceil(Math.max(document.querySelector('.sidebar').scrollHeight, ...[...m.children].map(c => c.getBoundingClientRect().bottom + m.scrollTop)) + 32); });
    await p.setViewportSize({ width: 1440, height: Math.max(1000, h) }); await p.waitForTimeout(150);
    await p.screenshot({ path: path.join(__dirname, m + '.png') });
    console.log(m, h);
  }
  if (err.length) { console.log('erreurs :', err); }
  await b.close();
})();
