/* Rend les documents du programme commercial B2B en PDF A4 (Chromium, Playwright) : chaque .html de ce dossier donne
 * son .pdf à côté. Le pied (nom du document, « Page n / N ») vient de l'attribut data-pied de la page.
 *
 *   node commercial/docs/documents/rendre.cjs [fichier.html …]
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const dossier = __dirname;
const fichiers = process.argv.slice(2).length ? process.argv.slice(2).map(f => path.resolve(f)) : fs.readdirSync(dossier).filter(f => f.endsWith('.html')).map(f => path.join(dossier, f));
const police = fs.readFileSync(path.join(dossier, '../../../shared/public/assets/ds/fonts/Gotham_Medium.otf')).toString('base64');
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

(async () => {
  const b = await chromium.launch();
  for (const f of fichiers) {
    const p = await b.newPage();
    await p.goto('file://' + f, { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    const pied = await p.evaluate(() => document.documentElement.dataset.pied || document.title);
    const modele = `<style>@font-face{font-family:G;src:url(data:font/otf;base64,${police})}</style>
      <div style="font:400 8px/1.6 G,Helvetica,Arial,sans-serif;color:#7a736a;width:100%;margin:0 14mm;padding-top:6px;border-top:1px solid #e6e0d8;display:flex;justify-content:space-between;gap:24px;-webkit-print-color-adjust:exact">
      <span>${esc(pied)}</span><span style="white-space:nowrap">Page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`;
    const sortie = f.replace(/\.html$/, '.pdf');
    await p.pdf({ path: sortie, format: 'A4', printBackground: true, preferCSSPageSize: true, displayHeaderFooter: true,
      headerTemplate: '<span></span>', footerTemplate: modele, margin: { top: '14mm', right: '14mm', bottom: '18mm', left: '14mm' } });
    await p.close();
    console.log(path.basename(sortie));
  }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
