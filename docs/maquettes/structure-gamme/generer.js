/* Capture les écrans en PNG (cockpit à 1440 px, rail compris) ; ecran-2b : une catégorie où la marge change le standard.
   Depuis la racine du dépôt : php -S 127.0.0.1:8099 -t .   puis   node docs/maquettes/structure-gamme/generer.js */
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [v, nom] of [['1', '1'], ['2', '2'], ['2&cat=Quiches', '2b'], ['3', '3'], ['4', '4']]) {
    const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
    const err = []; p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/structure-gamme/index.html?v=' + v, { waitUntil: 'load' });
    await p.waitForSelector('#ecran > *'); await p.waitForTimeout(500);
    await p.screenshot({ path: path.join(__dirname, 'ecran-' + nom + '.png'), fullPage: true });
    await p.close(); console.log('✓ écran', nom, err.length ? err : '');
  }
  await b.close();
})();
