/* Capture les trois écrans en PNG (largeur cockpit 1300 px).
   node docs/maquettes/assortiment-obligatoire/generer.js — serveur statique sur 8099 depuis la racine. */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const v of ['1', '2', '3']) {
    const p = await b.newPage({ viewport: { width: 1348, height: 900 }, deviceScaleFactor: 1.5 });
    const err = []; p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/assortiment-obligatoire/index.html?v=' + v, { waitUntil: 'load' });
    await p.waitForSelector('#ecran > *'); await p.waitForTimeout(600);
    await p.screenshot({ path: path.join(__dirname, 'ecran-' + v + '.png'), fullPage: true });
    await p.close(); console.log('✓ écran', v, err.length ? err : '');
  }
  await b.close();
})();
