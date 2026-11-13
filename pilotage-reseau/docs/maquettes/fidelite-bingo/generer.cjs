/* Fidélité · carte à points + bingo de saison — génère les trois maquettes et leurs captures.
 *
 *   node docs/maquettes/fidelite-bingo/generer.cjs          (depuis pilotage-reseau/)
 *
 * Les pages s'ouvrent en fichier local (polices et logo pris dans public/assets, dessins dans ill/). */
const path = require('path');
const { chromium } = require('playwright');

const PAGES = ['m1-programme', 'm2-client', 'm3-clients'];
const seules = process.argv.slice(2);

(async () => {
  for (const p of PAGES) { if (!seules.length || seules.includes(p)) { require('./' + p + '.cjs'); } }
  const nav = await chromium.launch();
  const page = await nav.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  for (const p of PAGES) {
    if (seules.length && !seules.includes(p)) { continue; }
    await page.goto('file://' + path.join(__dirname, p + '.html'));
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(150);
    await page.screenshot({ path: path.join(__dirname, p + '.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
    console.log('capture', p + '.jpg');
  }
  await nav.close();
})();
