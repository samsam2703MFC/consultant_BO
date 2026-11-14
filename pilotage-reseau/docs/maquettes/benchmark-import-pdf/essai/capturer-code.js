/* Captures de l'écran CODÉ « Benchmark » (14/11/2026) : le vrai cockpit et le vrai serveur, sur le banc d'essai
 * (routeur.php : SQLite, TFBuddy simulé, catalogue réel de Sombreffe, IA simulée qui rend le relevé d'EXEMPLE).
 *
 *   rm -rf /tmp/bench-essai
 *   BENCH_ESSAI=/tmp/bench-essai php -S 127.0.0.1:8099 -t . docs/maquettes/benchmark-import-pdf/essai/routeur.php   (depuis pilotage-reseau/)
 *   node docs/maquettes/benchmark-import-pdf/essai/capturer-code.js
 *
 * Rend, à côté de la maquette : code-1-nouveau.png, code-2-releve.png, code-3-encode.png, code-4-classement.png,
 * code-5-zones.png, code-6-internet.png, code-7-dashboard-concurrence.png. */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = path.join(__dirname, '..');
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8099/';
const DEPLIER = 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}';
const PDF = Buffer.from('%PDF-1.4\n% exemple de tarif (banc d’essai)\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');

(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('console', m => { if (m.type() === 'error') { err.push(m.text()); } });
  p.on('dialog', d => d.accept());
  const pleine = async nom => { await p.addStyleTag({ content: DEPLIER }); await p.waitForTimeout(250); await p.screenshot({ path: path.join(D, nom), fullPage: true }); };
  const clic = async (texte, dans) => { await p.locator((dans || '[data-screen="benchmark"]') + ' button', { hasText: texte }).first().click(); await p.waitForTimeout(500); };

  await p.goto(BASE + 'public/index.html#/benchmark', { waitUntil: 'networkidle' });
  await p.waitForSelector('[data-screen="benchmark"] .bk-modes');
  await p.evaluate(() => document.fonts.ready);
  // 1. Nouveau relevé : le PDF choisi, le code postal annoncé.
  await p.locator('[data-screen="benchmark"] input[type=file]').setInputFiles({ name: 'dumont-tarif-2026.pdf', mimeType: 'application/pdf', buffer: PDF });
  await p.waitForTimeout(300);
  await p.locator('#bk-n-cnom').fill('Dumont et Partners');
  await p.waitForTimeout(200);
  await pleine('code-1-nouveau.png');
  // 2. Lu : le relevé rapproché du catalogue de Sombreffe.
  await clic('Lire le PDF');
  await p.waitForSelector('[data-screen="benchmark"] .bk-t tbody tr', { timeout: 30000 });
  await p.waitForTimeout(400);
  await pleine('code-2-releve.png');
  // 3. Décider : une ligne sans équivalent, une ligne ouverte, le reste confirmé ; vérifier puis encoder.
  const choisir = async (texte, valeur) => { await p.locator('.bk-t tr', { hasText: texte }).first().locator('select').selectOption(valeur); await p.waitForTimeout(700); };
  await choisir('Bûche glacée', '0');
  await choisir('Pralines maison', '0');
  await choisir('Pain blanc 800 g', '-1');
  await clic('Confirmer les');
  await p.waitForTimeout(800);
  await clic('Vérifier ce qui sera écrit');
  await p.waitForSelector('.bk-plan');
  await clic('Encoder dans le benchmark');
  await p.waitForSelector('.bk-res', { timeout: 30000 });
  await p.waitForTimeout(600);
  await pleine('code-3-encode.png');
  // 4. Classement par code postal.
  await p.reload({ waitUntil: 'networkidle' });
  await clic('Classement par code postal');
  await p.waitForSelector('[data-screen="benchmark"] .bk-carte .bk-t', { timeout: 30000 });
  await pleine('code-4-classement.png');
  // 5. Zones, avec un code postal testé.
  await p.reload({ waitUntil: 'networkidle' });
  await clic('Zones des magasins');
  await p.waitForSelector('.bk-zone');
  await p.locator('#bk-test').fill('5140');
  await p.waitForTimeout(300);
  await pleine('code-5-zones.png');
  // 6. La recherche internet.
  await p.reload({ waitUntil: 'networkidle' });
  await clic('Importer');
  await clic('+ Nouveau relevé');
  await clic('Recherche internet');
  await p.locator('#bk-n-cnom').fill('Dumont et Partners');
  await p.locator('#bk-n-lieu').fill('Tongrinne 5140');
  await p.locator('#bk-n-consignes').fill('cougnou, galette, viennoiseries');
  await p.waitForTimeout(300);
  await pleine('code-6-internet.png');
  // 7. Le dashboard magasin de Sombreffe, onglet Concurrence : les concurrents par code postal.
  await p.goto(BASE + 'public/dashboard/index.html?shop=5&vue=concurrence', { waitUntil: 'networkidle' });
  await p.waitForSelector('.bm-cl', { timeout: 30000 });
  await p.waitForTimeout(500);
  const carte = p.locator('.db-card', { hasText: 'Les concurrents du magasin, par code postal' }).first();
  await carte.scrollIntoViewIfNeeded();
  await carte.screenshot({ path: path.join(D, 'code-7-dashboard-concurrence.png') });
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
