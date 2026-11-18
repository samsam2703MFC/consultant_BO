/* Les captures des maquettes « Comptoir et webshop dans la jauge ».
 *   node pilotage-reseau/docs/maquettes/comptoir-webshop/capturer.js
 * Pas de serveur à lancer : le navigateur lit pilotage-reseau/ sur le disque (page.route). */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const fs = require('fs'), path = require('path');
const RACINE = path.resolve(__dirname, '../../..');
const TYPES = { html: 'text/html', css: 'text/css', js: 'application/javascript', png: 'image/png', jpg: 'image/jpeg', svg: 'image/svg+xml', otf: 'font/otf', ttf: 'font/ttf', woff2: 'font/woff2' };
const B = 'http://maquette.local/docs/maquettes/comptoir-webshop/index.html?v=';
(async () => {
  const b = await pw.chromium.launch();
  const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  await p.route('http://maquette.local/**', r => {
    const f = path.join(RACINE, decodeURIComponent(new URL(r.request().url()).pathname));
    if (!f.startsWith(RACINE) || !fs.existsSync(f)) { return r.fulfill({ status: 404, body: '' }); }
    return r.fulfill({ body: fs.readFileSync(f), contentType: TYPES[path.extname(f).slice(1)] || 'application/octet-stream' });
  });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const v of ['0', 'a', 'b', 'c']) {
    await p.goto(B + v, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    await (await p.$('.tel')).screenshot({ path: path.join(__dirname, 'ecran-' + v + '.png') });
    console.log('✓ écran', v);
  }
  await p.setViewportSize({ width: 1800, height: 1000 });
  await p.goto(B + 'planche', { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(__dirname, 'planche.jpg'), type: 'jpeg', quality: 84, fullPage: true });
  console.log('✓ planche · erreurs', err.length ? err : 'aucune');
  await b.close();
})();
