// node docs/maquettes/manuel-formation/captures.js — une capture PNG par maquette, iPad paysage (1180 × 820).
const { chromium } = require('playwright');
const path = require('path');
const ici = __dirname;
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1180, height: 820 }, deviceScaleFactor: 2 });
  for (const f of ['A-fiche-a-lire', 'B-cinq-etapes', 'C-tableau-de-bord', 'D-onglets']) {
    await p.goto('file://' + path.join(ici, f + '.html'));
    await p.waitForTimeout(500);
    await p.screenshot({ path: path.join(ici, f + '.png'), fullPage: true });
  }
  await b.close();
})();
