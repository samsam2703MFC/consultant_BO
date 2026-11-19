/* Les captures des maquettes « Le dashboard magasin au téléphone, refonte ».
 *   node pilotage-reseau/docs/maquettes/dashboard-mobile-v3/capturer.js
 * Pas de serveur à lancer : le navigateur lit pilotage-reseau/ sur le disque (page.route). */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const fs = require('fs'), path = require('path');
['long', 'vues'].forEach(d => fs.mkdirSync(path.join(__dirname, d), { recursive: true }));
const RACINE = path.resolve(__dirname, '../../..');
const TYPES = { html: 'text/html', css: 'text/css', js: 'application/javascript', png: 'image/png', jpg: 'image/jpeg', svg: 'image/svg+xml', otf: 'font/otf', ttf: 'font/ttf', woff2: 'font/woff2' };
const B = 'http://maquette.local/docs/maquettes/dashboard-mobile-v3/index.html?v=';
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  await p.route('http://maquette.local/**', r => {
    const f = path.join(RACINE, decodeURIComponent(new URL(r.request().url()).pathname));
    if (!f.startsWith(RACINE) || !fs.existsSync(f)) { return r.fulfill({ status: 404, body: '' }); }
    return r.fulfill({ body: fs.readFileSync(f), contentType: TYPES[path.extname(f).slice(1)] || 'application/octet-stream' });
  });
  const err = []; p.on('pageerror', e => err.push(e.message));
  const ecrans = ['jour', 'chiffres', 'controle', 'taches', 'semaine', 'primes'];
  for (const v of [...ecrans, ...ecrans.map(e => e + '-long'), 'cmd', 'b2b', 'produit', 'equipe', 'stock', 'poubelle', 'bureau', 'photo', 'reclamation', 'revue']) {
    await p.goto(B + v, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    await (await p.$('.tel')).screenshot({ path: path.join(__dirname, (v.endsWith('-long') ? 'long/' : (ecrans.includes(v) ? '' : 'vues/')) + v + '.png') });
    console.log('✓ écran', v);
  }
  for (const [q, f, w] of [['planche', 'planche.jpg', 2700], ['planche-vues', 'planche-vues.jpg', 2300]]) {
    await p.setViewportSize({ width: w, height: 1000 });
    await p.goto(B + q, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, f), type: 'jpeg', quality: 84, fullPage: true });
    await p.setViewportSize({ width: 390, height: 844 });
  }
  console.log('✓ planche · erreurs', err.length ? err : 'aucune');
  await b.close();
})();
