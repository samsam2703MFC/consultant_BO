/* Captures des maquettes : téléphone (premier écran et page entière), menus ouverts, cockpit, planche.
 *   npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
 *   node docs/maquettes/primes-epure/generer.js && node docs/maquettes/primes-epure/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname, BASE = 'http://127.0.0.1:8099/docs/maquettes/primes-epure/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const err = [], mes = {};
  const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 }); p.on('pageerror', x => err.push(x.message));
  p.on('requestfailed', r => err.push('échec ' + r.url()));
  const ouvrir = async m => {
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    mes[m] = await p.evaluate(() => ({ h: document.documentElement.scrollHeight, deb: document.documentElement.scrollWidth > innerWidth,
      vank: getComputedStyle(document.querySelector('.emp-title')).fontFamily.split(',')[0] }));
  };
  for (const m of ['a-liste', 'b-une-prime', 'c-deroule']) {
    await ouvrir(m);
    await p.screenshot({ path: path.join(D, m + '-tel.png') });
    // la page entière sans la barre du bas (fixe, elle se collerait au milieu de la capture)
    await p.addStyleTag({ content: '.emp-bottom-nav{display:none}.emp-content{padding-bottom:16px}' });
    await p.screenshot({ path: path.join(D, m + '-long.png'), fullPage: true });
  }
  // A, le menu ouvert : l'écran calé sur le classement
  await ouvrir('a-menu');
  // la barre du haut, sortie du flux, est reposée au-dessus du classement (une capture pleine page ne défile pas)
  await p.addStyleTag({ content: '.emp-topbar{position:absolute;left:0;width:390px}.emp-content{padding-top:68px}.emp-bottom-nav{position:absolute;bottom:auto}' });
  const y = await p.evaluate(() => Math.round(document.getElementById('classement').getBoundingClientRect().top + scrollY - 66));
  await p.addStyleTag({ content: '.tel{min-height:' + (y + 844) + 'px}.emp-topbar{top:' + y + 'px}.emp-bottom-nav{top:' + (y + 844 - 72) + 'px}' });
  await p.screenshot({ path: path.join(D, 'a-menu.png'), fullPage: true, clip: { x: 0, y, width: 390, height: 844 } });
  // B, le menu ouvert : le premier écran
  await ouvrir('b-menu');
  await p.screenshot({ path: path.join(D, 'b-menu.png') });
  await p.close();
  const c = await b.newPage({ viewport: { width: 1200, height: 480 } }); c.on('pageerror', x => err.push(x.message));
  await c.goto(BASE + 'cockpit.html', { waitUntil: 'networkidle' }); await c.evaluate(() => document.fonts.ready);
  mes.cockpit = await c.evaluate(() => ({ h: document.documentElement.scrollHeight, deb: document.documentElement.scrollWidth > innerWidth }));
  await c.screenshot({ path: path.join(D, 'cockpit.png'), fullPage: true });
  await c.close();
  const pl = await b.newPage({ viewport: { width: 1290, height: 1000 } });
  await pl.goto(BASE + 'planche.html', { waitUntil: 'networkidle' }); await pl.evaluate(() => document.fonts.ready);
  await pl.screenshot({ path: path.join(D, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 80 });
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
