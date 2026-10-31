// Régénère les fiches A4 des primes depuis leur HTML (polices et logo du cockpit, le QR code est dans personnel*.html) :
//   node docs/primes/generer.js
// Les PDF vont dans public/assets/primes : la page d'un magasin les ouvre pour l'impression et envoie l'affiche à l'équipe
// (src/primes_fiches.php).
// Chromium de Playwright ; chaque fiche doit tenir sur une page — le script le vérifie.
const { chromium } = require('playwright');
const path = require('path');
const FICHES = [['franchise.html', 'primes-franchise-mode-emploi.pdf'], ['personnel.html', 'primes-personnel-qr.pdf'], ['personnel-nl.html', 'primes-personnel-qr-nl.pdf']];
(async () => {
  const b = await chromium.launch();
  const pg = await b.newPage({ viewport: { width: 794, height: 1123 } });
  for (const [src, out] of FICHES) {
    await pg.goto('file://' + path.join(__dirname, src));
    await pg.evaluate(() => document.fonts.ready);
    const deborde = await pg.evaluate(() => { const p = document.querySelector('.page'); return p.scrollHeight - p.clientHeight; });
    if (deborde > 0) { console.error(src + ' déborde de ' + deborde + ' px : raccourcir avant d’imprimer'); process.exitCode = 1; continue; }
    await pg.pdf({ path: path.join(__dirname, '../../public/assets/primes', out), format: 'A4', printBackground: true, preferCSSPageSize: true });
    console.log(out);
  }
  await b.close();
})();
