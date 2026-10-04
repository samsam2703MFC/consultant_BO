/* Les images des maquettes : une planche par direction, et chaque téléphone seul.
 * Serveur statique sur 8099 depuis la racine du dépôt :
 *   npx http-server . -p 8099 -c-1
 *   node docs/maquettes/dashboard-3-onglets/capturer.js */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const OUT = __dirname, B = 'http://127.0.0.1:8099/docs/maquettes/dashboard-3-onglets/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1340, height: 1100 }, deviceScaleFactor: 2 });
  for (const k of ['a', 'b', 'c']) {
    await p.goto(B + k + '.html', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(OUT, k + '.jpg'), type: 'jpeg', quality: 82, fullPage: true });
    const ph = await p.$$('.ph');
    for (let i = 0; i < ph.length; i++) { await ph[i].screenshot({ path: path.join(OUT, `${k}-${['exploitation', 'controle', 'semaine'][i]}.png`) }); }
    // Rien ne doit déborder du téléphone : le contenu tient dans l'écran, sans défiler.
    const deb = await p.$$eval('.ph .sc', L => L.map(e => e.scrollHeight - e.clientHeight));
    console.log(k, 'débordement par onglet (px) :', deb.join(' · '));
  }
  await p.setViewportSize({ width: 1340, height: 1100 });
  await p.goto(B + 'planche.html', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(OUT, 'planche.jpg'), type: 'jpeg', quality: 78, fullPage: true });
  await b.close();
  console.log('images écrites');
})();
