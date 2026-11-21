/* Captures des maquettes « réclamations : photos et tableau annuel » : node generer.js puis node capturer.js (fichiers locaux). */
const path = require('path');
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1920, height: 1000 }, deviceScaleFactor: 1 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  for (const m of ['a', 'b', 'c']) {
    await p.goto('file://' + path.join(__dirname, m + '.html'), { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.addStyleTag({ content: 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}.hd-fiche{position:static!important;max-height:none!important}' });
    await p.waitForTimeout(200);
    const h = await p.evaluate(() => Math.ceil(document.querySelector('.vr.hd').getBoundingClientRect().bottom + window.scrollY + 24));
    await p.screenshot({ path: path.join(__dirname, m + '.png'), fullPage: true, clip: { x: 0, y: 0, width: 1920, height: h } });
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
