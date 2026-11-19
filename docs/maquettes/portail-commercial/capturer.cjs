/* Capture des six maquettes du portail Commercial, à 1440 px de large (×2), page entière.
 *   python3 docs/maquettes/portail-commercial/generer.py && node docs/maquettes/portail-commercial/capturer.cjs */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  const ecrans = fs.readdirSync(__dirname).filter(f => /^\d-.*\.html$/.test(f)).sort();
  for (const f of ecrans) {
    await p.goto('file://' + path.join(__dirname, f));
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, f.replace('.html', '.png')), fullPage: true });
    console.log('capturé', f);
  }
  await b.close();
})();
