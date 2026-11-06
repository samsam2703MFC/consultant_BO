// Captures des maquettes : node docs/maquettes/parcours-competences/captures.js
const { chromium } = require('playwright');
const path = require('path');
const ici = __dirname;
(async () => {
  const b = await chromium.launch();
  const tel = ['02-assistant-1-destination', '03-assistant-2-acquis', '04-assistant-3-parcours', '05-frise-suivi'];
  const bureau = ['06-parcours-equipes', '07-fiche-formation'];
  const p = await b.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  for (const n of tel) {
    await p.goto('file://' + path.join(ici, n + '.html')); await p.waitForTimeout(400);
    await (await p.$('.phone')).screenshot({ path: path.join(ici, n + '.png') });
  }
  for (const n of bureau) {
    await p.goto('file://' + path.join(ici, n + '.html')); await p.waitForTimeout(400);
    await p.screenshot({ path: path.join(ici, n + '.png') });
  }
  await p.setViewportSize({ width: 700, height: 640 });
  await p.goto('file://' + path.join(ici, '01-rail.html')); await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(ici, '01-rail.png') });
  await p.setViewportSize({ width: 1720, height: 920 });
  await p.goto('file://' + path.join(ici, 'planche.html')); await p.waitForTimeout(400);
  await p.screenshot({ path: path.join(ici, 'planche.png'), fullPage: true });
  await b.close();
  console.log('captures écrites');
})();
