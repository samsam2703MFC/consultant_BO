/* Capture les écrans en PNG (cockpit à 1440 px, rail compris) ; ecran-2b : une catégorie où la marge change le standard.
   Depuis la racine du dépôt : php -S 127.0.0.1:8099 -t .   puis   node docs/maquettes/structure-gamme/generer.js
   Les variables se passent en arguments, comme dans l'adresse de la maquette (défauts : std=80 max=6 sai=4 seuil=3) :
     node docs/maquettes/structure-gamme/generer.js seuil=5 max=8 */
const { chromium } = require('playwright');
const path = require('path');
const vars = process.argv.slice(2).filter(a => /^(std|max|sai|seuil|base)=[\w.,]+$/.test(a)).join('&');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [v, nom] of [['1', '1'], ['2', '2'], ['2&cat=Quiches', '2b'], ['3', '3'], ['4', '4']]) {
    const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
    const err = []; p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/structure-gamme/index.html?v=' + v + (vars ? '&' + vars : ''), { waitUntil: 'load' });
    await p.waitForSelector('#ecran > *'); await p.waitForTimeout(500);
    await p.screenshot({ path: path.join(__dirname, 'ecran-' + nom + '.png'), fullPage: true });
    await p.close(); console.log('✓ écran', nom, vars || '(défauts)', err.length ? err : '');
  }
  await b.close();
})();
