/* Capture les dix écrans de la maquette en PNG (téléphone 390 px, tablette 820 px pour la synthèse).
   node docs/maquettes/pwa-visites/generer.js   — depuis la racine, serveur statique sur 8099. */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
const DIR = __dirname;
const ECRANS = ['agenda', 'portfolio', 'fiche', 'tb', 'checklist', 'review', 'historique', 'sam', 'franchise', 'admin'];
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const e of ECRANS) {
    const tab = e === 'sam';
    const p = await b.newPage({ viewport: { width: tab ? 860 : 420, height: tab ? 760 : 900 }, deviceScaleFactor: 2 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/pwa-visites/index.html?ecran=' + e, { waitUntil: 'load' });
    await p.waitForTimeout(400);
    const el = await p.$('.tel');
    await el.screenshot({ path: path.join(DIR, e + '.png') });
    await p.close();
    console.log('✓', e);
  }
  await b.close();
})();
