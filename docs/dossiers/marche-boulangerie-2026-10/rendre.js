// Rend chaque HTML en PDF A4 avec le Chromium de Playwright : node rendre.js a.html a.pdf [b.html b.pdf …]
// Refuse un rendu dont le contenu déborde de la page A4 : la note tient sur une page, ou elle n'est pas écrite.
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
      const m = await page.evaluate(() => {
        const p = document.querySelector('.page');
        const bas = p.querySelector('.bas');
        const contenu = [...p.children].filter(e => e !== bas).reduce((h, e) => Math.max(h, e.getBoundingClientRect().bottom), 0);
        return { page: p.getBoundingClientRect().height, contenu, bas: bas.getBoundingClientRect().top, fond: bas.getBoundingClientRect().bottom, scroll: p.scrollHeight, client: p.clientHeight };
      });
      if (m.scroll > m.client + 1 || m.contenu > m.bas + 1 || m.fond > m.page + 1) {
        console.error(args[i], 'déborde', JSON.stringify(m)); process.exit(1);
      }
      await page.pdf({ path: args[i + 1], format: 'A4', printBackground: true, preferCSSPageSize: true });
      console.log(args[i + 1], '· marge libre', Math.round((m.bas - m.contenu) * 25.4 / 96 * 10) / 10, 'mm');
      await page.close();
    }
  } finally { await browser.close(); }
})();
