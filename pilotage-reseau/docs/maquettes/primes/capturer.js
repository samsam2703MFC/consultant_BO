/* Captures des maquettes : téléphone (premier écran et page entière), cockpit, planche.
 *   npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
 *   node docs/maquettes/primes/generer.js && node docs/maquettes/primes/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname, BASE = 'http://127.0.0.1:8099/docs/maquettes/primes/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const err = [], mes = {};
  const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 }); p.on('pageerror', e => err.push(e.message));
  for (const m of ['a', 'b-moi', 'b-magasin']) {
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    mes[m] = await p.evaluate(() => ({ h: document.documentElement.scrollHeight, deb: document.documentElement.scrollWidth > innerWidth,
      vank: getComputedStyle(document.querySelector('.emp-title')).fontFamily.split(',')[0], icones: [...document.fonts].some(f => f.family.includes('bootstrap-icons') && f.status === 'loaded') }));
    await p.screenshot({ path: path.join(D, m + '-tel.png') });
    // la page entière sans la barre du bas (fixe, elle se collerait au milieu de la capture)
    await p.addStyleTag({ content: '.emp-bottom-nav{display:none}.emp-content{padding-bottom:16px}' });
    await p.screenshot({ path: path.join(D, m + '-long.png'), fullPage: true });
  }
  await p.close();
  const c = await b.newPage({ viewport: { width: 1440, height: 900 } }); c.on('pageerror', e => err.push(e.message));
  await c.goto(BASE + 'cockpit.html', { waitUntil: 'networkidle' }); await c.evaluate(() => document.fonts.ready);
  mes.cockpit = await c.evaluate(() => ({ h: document.documentElement.scrollHeight, deb: document.documentElement.scrollWidth > innerWidth }));
  await c.screenshot({ path: path.join(D, 'cockpit.png'), fullPage: true });
  await c.close();
  const pl = await b.newPage({ viewport: { width: 1520, height: 1000 } });
  await pl.goto(BASE + 'planche.html', { waitUntil: 'networkidle' }); await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(D, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
