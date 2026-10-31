/* Captures des maquettes « Vérification » (bureau 1440 px).
 *   npx http-server . -p 8099 -c-1   (depuis la racine du dépôt — ou php -S 127.0.0.1:8099 -t .)
 *   node docs/maquettes/verification/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 } });
  const err = []; p.on('pageerror', e => err.push(e.message));
  const mes = {};
  for (const m of ['a', 'a-jour', 'b', 'g', 'c', 'd', 'e', 'f']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/verification/' + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    mes[m] = { hauteur: await p.evaluate(() => document.documentElement.scrollHeight), debordement: await p.evaluate(() => document.documentElement.scrollWidth > innerWidth) };
    await p.screenshot({ path: path.join(__dirname, m + '.png'), fullPage: true });
  }
  const pl = await b.newPage({ viewport: { width: 2200, height: 1200 } });
  await pl.goto('http://127.0.0.1:8099/docs/maquettes/verification/planche.html', { waitUntil: 'networkidle' });
  await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(__dirname, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 80 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
