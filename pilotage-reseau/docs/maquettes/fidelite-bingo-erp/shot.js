// Capture chaque écran (.ecran) d'index.html en JPEG.
// Usage : node shot.js [dossier_de_sortie] [échelle]   (par défaut : ./captures, échelle 2)
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const out = process.argv[2] || path.join(__dirname, 'captures');
  const dsf = Number(process.argv[3] || 2);
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1500, height: 1000 }, deviceScaleFactor: dsf });
  await p.goto('file://' + path.join(__dirname, 'index.html'), { waitUntil: 'load' });
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(400);
  const noms = ['1-liste', '2-etape1-informations', '3-etape2-regles', '4-etape3-produits', '5-etape4-apparence', '6-apercu-carte'];
  const els = await p.$$('.ecran');
  for (let i = 0; i < els.length; i++) {
    await els[i].screenshot({ path: path.join(out, noms[i] + '.jpg'), type: 'jpeg', quality: 88 });
  }
  await b.close();
})();
