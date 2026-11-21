/* Captures et PDF de la maquette « Relevé de compensation » (fichiers locaux, sans serveur).
 *   node consultant/docs/maquettes/releve-compensation/generer.js
 *   node consultant/docs/maquettes/releve-compensation/capturer.js
 * Sorties : releve.png, releve-nl.png (le cadre, trois pages) ; releve-halle-2026-09.pdf, …-nl.pdf (A4). */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch();
  const err = []; const mes = {};
  for (const s of ['', '-nl']) {
    const p = await b.newPage({ viewport: { width: 2558, height: 1000 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => err.push('releve' + s + ' : ' + e.message));
    await p.goto('file://' + path.join(__dirname, 'releve' + s + '.html'), { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    // Marge entre le contenu de chaque page A4 et la note de bas de page : négative = la page déborde.
    mes['releve' + s] = await p.evaluate(() => [...document.querySelectorAll('.doc__page')].map(pg => {
      const bas = pg.querySelector('.doc__illu').getBoundingClientRect().top;
      const der = [...pg.children].filter(c => !c.classList.contains('doc__foot') && !c.classList.contains('doc__illu')).pop();
      return Math.round(bas - der.getBoundingClientRect().bottom);
    }));
    await p.screenshot({ path: path.join(__dirname, 'releve' + s + '.png'), fullPage: true });
    await p.close();

    const d = await b.newPage();
    await d.goto('file://' + path.join(__dirname, 'document' + s + '.html'), { waitUntil: 'load' });
    await d.evaluate(() => document.fonts.ready);
    await d.pdf({ path: path.join(__dirname, 'releve-halle-2026-09' + s + '.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true });
    await d.close();
  }
  console.log(JSON.stringify(mes), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
