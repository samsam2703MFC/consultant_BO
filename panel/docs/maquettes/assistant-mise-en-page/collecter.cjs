// Collecte 2 : variantes locales (rien n'est enregistré) et pages du document (étape 4).
const { chromium } = require('playwright');
const fs = require('fs');
const L = require('path').join(__dirname, '../../../public/dashboard/');
async function ouvrir(b, etape) {
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 } });
  await p.route(u => /\/catalogues?(\/|\?|$)/.test(u.pathname) && !/\.(js|css)$/.test(u.pathname), r => r.request().method() !== 'GET' ? r.fulfill({ status: 409, body: '{}' }) : r.continue());
  await p.route('**/garde.js*', r => r.fulfill({ contentType: 'application/javascript', body: '' }));
  for (const f of ['catalogue.js', 'catalogue.css']) await p.route('**/dashboard/' + f + '*', r => r.fulfill({ contentType: f.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(L + f, 'utf8') }));
  await p.goto('http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue=catalogue', { waitUntil: 'networkidle', timeout: 180000 }).catch(() => {});
  await p.waitForSelector('[data-cm="ouvrir"]', { timeout: 60000 }); await p.waitForTimeout(1500);
  await p.locator('[data-cm="ouvrir"]').filter({ hasText: 'Continuer' }).first().click();
  await p.waitForSelector('[data-cm="etape"][data-v="' + etape + '"]', { timeout: 60000 }); await p.waitForTimeout(2500);
  await p.locator('[data-cm="etape"][data-v="' + etape + '"]').first().click();
  await p.waitForTimeout(7000);
  return p;
}
const zoom = async (p, i) => {
  await p.locator('.cm-pg[data-pg="' + i + '"] [data-cm="zoomPage"]').first().click(); await p.waitForTimeout(1500);
  const h = await p.$$eval('.cm-zoom__p', l => l.map(x => x.innerHTML));
  await p.locator('.cm-zoom__x').click(); await p.waitForTimeout(300);
  return h;
};
(async () => {
  const b = await chromium.launch();
  const out = {};
  // Étape 4 : toutes les pages du document du Marketing.
  const p4 = await ouvrir(b, 4);
  
  out.doc = await p4.$$eval('.cm-feuille', l => l.map(x => ({ html: x.outerHTML, leg: (x.parentElement.querySelector('.cm-leg') || {}).textContent || '' })));
  console.log('document', out.doc.length, out.doc.map(d => d.leg).join(' | '));
  await p4.close();
  // Étape 3 : Pain Tradition sur 2 pages (grille), Plateau Sandwich en pleine page + liste.
  const p = await ouvrir(b, 3);
  console.log('rangées', await p.locator('.cm-pg[data-pg]').count());
  out.pain = await zoom(p, 0);
  await p.locator('select[data-cm="pcat"]').nth(4).selectOption({ label: 'Pain Tradition' }); await p.waitForTimeout(600);
  out.pain2 = await zoom(p, 0);
  out.cougnou = await zoom(p, 1);
  await p.locator('[data-cm="layout"][data-i="3"][data-v="pleine"]').click(); await p.waitForTimeout(500);
  await p.locator('select[data-cm="pprod"][data-i="3"]').selectOption('5610003'); await p.waitForTimeout(500);
  await p.locator('select[data-cm="pcat"]').nth(5).selectOption({ label: 'Plateau Sandwich' }); await p.waitForTimeout(600);
  await p.locator('[data-cm="layout"][data-i="5"][data-v="liste"]').click(); await p.waitForTimeout(600);
  out.sand = await zoom(p, 3);
  console.log('zooms', out.pain.length, out.pain2.length, out.cougnou.length, out.sand.length);
  fs.writeFileSync(require('path').join(__dirname, 'am_donnees2.json'), JSON.stringify(out));
  await b.close();
})();
