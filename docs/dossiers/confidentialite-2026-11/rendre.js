// Rend chaque HTML en PDF A4 avec le Chromium de Playwright : node rendre.js a.html a.pdf [b.html b.pdf …]
// Chaque <section class="page"> doit tenir sur sa feuille A4 : un rendu qui déborde est refusé, et le PDF doit compter
// exactement autant de pages que le HTML a de sections.
const { chromium } = require('playwright');

(async () => {
  const args = process.argv.slice(2);
  if (args.length === 0 || args.length % 2) { console.error('usage : node rendre.js a.html a.pdf [b.html b.pdf …]'); process.exit(2); }
  const browser = await chromium.launch();
  try {
    for (let i = 0; i < args.length; i += 2) {
      const page = await browser.newPage();
      await page.goto('file://' + args[i], { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      const pages = await page.evaluate(() => [...document.querySelectorAll('.page')].map(p => {
        const bas = p.querySelector('.bas');
        const top = p.getBoundingClientRect().top;
        const contenu = [...p.children].filter(e => e !== bas).reduce((h, e) => Math.max(h, e.getBoundingClientRect().bottom - top), 0);
        return { haut: p.getBoundingClientRect().height, contenu, bas: bas ? bas.getBoundingClientRect().top - top : p.clientHeight, scroll: p.scrollHeight, client: p.clientHeight };
      }));
      pages.forEach((m, k) => {
        if (m.scroll > m.client + 1 || m.contenu > m.bas + 1) { console.error(args[i], 'page', k + 1, 'déborde', JSON.stringify(m)); process.exit(1); }
      });
      await page.pdf({ path: args[i + 1], format: 'A4', printBackground: true, preferCSSPageSize: true });
      console.log(args[i + 1], '·', pages.length, 'pages · marge libre', pages.map(m => Math.round((m.bas - m.contenu) * 25.4 / 96 * 10) / 10 + ' mm').join(', '));
      await page.close();
    }
  } finally { await browser.close(); }
})();
