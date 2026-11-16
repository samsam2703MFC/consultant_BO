/**
 * Le PDF du rapport : rapport.html → Sombreffe-rapport-2026-11.pdf, en A4, avec les polices Gotham du cockpit et
 * « Page x / y » en pied (docs/DESIGN_SYSTEM.md §13.1). Les graphiques se dessinent à la largeur de la page imprimée.
 *
 *   python3 docs/dossiers/sombreffe-2026-11/generer.py && node docs/dossiers/sombreffe-2026-11/pdf.js
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const ICI = __dirname;
const SORTIE = path.join(ICI, 'Sombreffe-rapport-2026-11.pdf');
const POLICES = path.join(ICI, '..', '..', '..', 'public', 'assets', 'ds', 'fonts');
const ECHELLE = 0.8;           // 186 mm utiles ÷ 0,8 ≈ 880 px : l’état des lieux tient sans couper un graphique (13/11/2026)
const LARGEUR = 880;

(async () => {
  // Les polices le temps du rendu : la page les attend dans fonts/, le dépôt les garde dans public/assets/ds/fonts.
  const dossierPolices = path.join(ICI, 'fonts');
  const copiees = !fs.existsSync(dossierPolices);
  fs.mkdirSync(dossierPolices, { recursive: true });
  for (const f of ['Gotham_Medium.otf', 'Gotham_Regular.otf']) { fs.copyFileSync(path.join(POLICES, f), path.join(dossierPolices, f)); }
  const navigateur = await chromium.launch();
  try {
    const page = await navigateur.newPage({ viewport: { width: LARGEUR, height: 1200 } });
    const erreurs = [];
    page.on('pageerror', e => erreurs.push(String(e)));
    await page.goto('file://' + path.join(ICI, 'rapport.html'));
    await page.evaluate(() => document.fonts.ready);
    await page.emulateMedia({ media: 'print' });
    const debordent = await page.evaluate(() => [...document.querySelectorAll('.rp-table-zone, .rp-graphe')]
      .filter(z => z.scrollWidth > z.clientWidth + 1).map(z => (z.querySelector('table') || z).id));
    if (erreurs.length) { throw new Error('la page a échoué : ' + erreurs.join(' | ')); }
    if (debordent.length) { console.warn('déborde de la page :', debordent.join(', ')); }
    const pied = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:7.5px;color:#7a736a;width:100%;padding:0 12mm;'
      + 'display:flex;justify-content:space-between"><span>Sombreffe, comptoir et B2B · rapport du 13/11/2026</span>'
      + '<span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>';
    await page.pdf({ path: SORTIE, format: 'A4', printBackground: true, scale: ECHELLE, displayHeaderFooter: true,
      headerTemplate: '<div></div>', footerTemplate: pied, margin: { top: '12mm', bottom: '16mm', left: '12mm', right: '12mm' } });
    console.log('écrit :', path.relative(process.cwd(), SORTIE));
  } finally {
    await navigateur.close();
    if (copiees) { fs.rmSync(dossierPolices, { recursive: true, force: true }); }
  }
})();
