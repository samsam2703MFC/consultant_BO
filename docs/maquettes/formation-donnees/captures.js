// node docs/maquettes/formation-donnees/captures.js — une capture PNG par maquette (Chromium).
const { chromium } = require('playwright');
const path = require('path');
const ici = __dirname;
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  for (const f of ['01-carte-des-metiers', '02-fiche-niveau', '03-matrice-competences', '04-matrice-formations', '05-equipe']) {
    await p.goto('file://' + path.join(ici, f + '.html'));
    await p.waitForTimeout(600);
    await p.screenshot({ path: path.join(ici, f + '.png'), fullPage: true });
  }
  await b.close();
})();
