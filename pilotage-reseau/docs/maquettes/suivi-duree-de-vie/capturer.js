/* Captures des maquettes (bureau 1440 px).
 *   npx http-server . -p 8099 -c-1   (depuis la racine du dépôt)
 *   node docs/maquettes/suivi-duree-de-vie/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 } });
  const err = []; p.on('pageerror', e => err.push(e.message));
  const mes = {};
  for (const m of ['a', 'a-reglage', 'b', 'b-long', 'c', 'c-long']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/suivi-duree-de-vie/' + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    mes[m] = { hauteur: await p.evaluate(() => document.documentElement.scrollHeight), debordement: await p.evaluate(() => document.documentElement.scrollWidth > innerWidth) };
    if (m === 'b-long') {
      // le bloc long life : de sa ligne d'en-tête jusqu'à la fin du tableau
      const y = await p.evaluate(() => { const e = document.querySelector('tr.bloc.L'); return e.getBoundingClientRect().top + scrollY - 60; });
      await p.screenshot({ path: path.join(__dirname, m + '.png'), fullPage: true, clip: { x: 0, y, width: 1440, height: 760 } });
    } else {
      await p.screenshot({ path: path.join(__dirname, m + '.png'), fullPage: m !== 'a-reglage', clip: m === 'a-reglage' ? { x: 0, y: 0, width: 1440, height: 1500 } : undefined });
    }
  }
  const pl = await b.newPage({ viewport: { width: 2280, height: 1200 } });
  await pl.goto('http://127.0.0.1:8099/docs/maquettes/suivi-duree-de-vie/planche.html', { waitUntil: 'networkidle' });
  await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(__dirname, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
