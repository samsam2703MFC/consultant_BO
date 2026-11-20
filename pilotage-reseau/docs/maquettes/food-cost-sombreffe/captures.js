/**
 * Les captures de la maquette : node docs/maquettes/food-cost-sombreffe/captures.js
 */
const { chromium, devices } = require('playwright');
const path = require('path');
const ICI = __dirname, URL = 'file://' + path.join(ICI, 'index.html');

(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  const ouvrir = async opts => { const p = await b.newPage(opts); p.on('pageerror', e => erreurs.push(String(e))); await p.goto(URL); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300); return p; };
  // a · à l'ouverture : les leviers par défaut (top 20 protégé, aligner +15 %, marges sous 50 % +10 %, volumes −3 %).
  let p = await ouvrir({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  await p.screenshot({ path: path.join(ICI, 'a-ouverture.png'), fullPage: true });
  // b · « Atteindre l'objectif » : la hausse sur le reste qu'il faut pour 40 %.
  await p.click('#atteindre'); await p.waitForTimeout(200);
  await p.screenshot({ path: path.join(ICI, 'b-objectif-atteint.png'), fullPage: true });
  console.log('message :', await p.textContent('#message'));
  console.log('kpis :', (await p.textContent('#kpis')).replace(/\s+/g, ' '));
  // c · les quiches, triées par gain.
  await p.selectOption('#filtre-cat', 'Quiches'); await p.waitForTimeout(150);
  const t = await p.$('#tableau'); await t.scrollIntoViewIfNeeded();
  await (await p.$('main > section.card:last-of-type')).screenshot({ path: path.join(ICI, 'c-tableau-quiches.png') });
  await p.close();
  // d · au téléphone.
  p = await ouvrir({ ...devices['iPhone 13'] });
  await p.screenshot({ path: path.join(ICI, 'd-telephone.png'), fullPage: false });
  await p.evaluate(() => document.getElementById('kpis').scrollIntoView());
  await p.screenshot({ path: path.join(ICI, 'e-telephone-chiffres.png'), fullPage: false });
  await p.close(); await b.close();
  if (erreurs.length) { console.error('ERREURS', erreurs); process.exit(1); }
  console.log('captures écrites');
})();
