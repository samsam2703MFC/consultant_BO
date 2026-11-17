// Compose le livret A4 (livret.html → livret-catalogue.pdf) avec le Chromium de Playwright.
// Usage, depuis ce dossier : node outils/pdf.js
const path = require('path');
const { chromium } = require('playwright');
(async () => {
  const dossier = path.resolve(__dirname, '..');
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('file://' + path.join(dossier, 'livret.html'), { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  // Une page déborde-t-elle ? (le contenu ne doit pas passer sous le pied)
  const debords = await page.evaluate(() => Array.from(document.querySelectorAll('.page')).map((p, i) => {
    const pied = p.querySelector('.foot').getBoundingClientRect().top;
    const bas = Math.max(...Array.from(p.children).filter(e => !e.classList.contains('foot')).map(e => e.getBoundingClientRect().bottom));
    return { page: i + 1, marge_mm: Math.round((pied - bas) / 96 * 25.4 * 10) / 10 };
  }));
  console.log(debords.map(d => 'p' + d.page + ' ' + d.marge_mm + ' mm').join(' · '));
  await page.pdf({ path: path.join(dossier, 'livret-catalogue.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true });
  await browser.close();
})();
