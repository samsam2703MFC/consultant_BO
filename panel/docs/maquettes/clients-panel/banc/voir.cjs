/* Les captures du banc de Vendre › Clients (le vrai écran, le vrai module, un TF Buddy simulé).
 *   php -S 127.0.0.1:8098 panel/docs/maquettes/clients-panel/banc/banc.php &                      (accès refusé : aujourd'hui)
 *   BANC_ACCES=panel php -S 127.0.0.1:8099 panel/docs/maquettes/clients-panel/banc/banc.php &     (avec l'accès de service R1)
 *   node panel/docs/maquettes/clients-panel/banc/voir.cjs [dossier]                                 */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const OUT = process.argv[2] || path.join(__dirname, '..');
(async () => {
  const b = await pw.chromium.launch();
  const err = [];
  const page = async (port, onglet, w) => {
    const p = await b.newPage({ viewport: { width: w || 1440, height: 1000 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => err.push(onglet + ' : ' + e.message)); p.on('console', m => { if (m.type() === 'error') { err.push(onglet + ' : ' + m.text()); } });
    await p.goto(`http://127.0.0.1:${port}/banc/?onglet=${onglet}`, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    return p;
  };
  const prise = async (p, nom) => { await p.waitForTimeout(250); await p.screenshot({ path: path.join(OUT, nom + '.png'), fullPage: true }); console.log('✓', nom); };
  // Aujourd'hui : les comptes du cockpit, les routes du panel refusées.
  let p = await page(8098, 'b2b'); await p.click('tr.pcl-ligne >> nth=0'); await p.waitForSelector('.pcl-kpis'); await prise(p, 'code-b2b'); await p.close();
  p = await page(8098, 'b2c'); await p.fill('#pcl-pq', 'bertrand'); await p.waitForTimeout(700); await p.click('tr.pcl-ligne >> nth=0'); await p.waitForSelector('.pcl-kpis'); await prise(p, 'code-particuliers'); await p.close();
  p = await page(8098, 'an'); await p.waitForSelector('.pcl-kpi6'); await prise(p, 'code-analyse'); await p.close();
  p = await page(8098, 'bur'); await p.waitForSelector('.pcl-refus'); await prise(p, 'code-bureaux-refus'); await p.close();
  // Avec l'accès de service (R1) : le profil commercial, les bureaux, l'assistant.
  p = await page(8099, 'b2b'); await p.click('tr.pcl-ligne >> nth=0'); await p.waitForSelector('.pcl-kpis'); await prise(p, 'code-b2b-r1'); await p.close();
  p = await page(8099, 'bur'); await p.click('tr.pcl-ligne >> nth=0'); await p.waitForSelector('.pcl-ong2'); await p.waitForTimeout(400); await prise(p, 'code-bureaux-r1'); await p.close();
  p = await page(8099, 'bur'); await p.click('[data-act="w-ouvrir"]'); await p.waitForSelector('.pcl-stepper');
  await p.selectOption('select[data-f="id_company_client"]', '801'); await p.fill('input[data-f="name"]', 'Horizon · Jumet'); await p.fill('input[data-f="tax_number"]', 'BE0789114256');
  await p.click('[data-act="w-suivant"]'); await p.fill('input[data-f="contact_name"]', 'Office manager'); await p.fill('input[data-f="contact_email"]', 'accueil@horizon.be');
  await p.click('[data-act="w-suivant"]'); await p.fill('input[data-ou="s0"][data-f="name"]', 'Zoning, salle 2'); await p.fill('input[data-ou="s0"][data-f="address"]', 'Rue de la Station 14');
  await p.fill('input[data-ou="s0"][data-f="postal_code"]', '6040'); await p.fill('input[data-ou="s0"][data-f="city"]', 'Jumet'); await p.selectOption('select[data-ou="s0"][data-f="route_id"]', '1');
  await prise(p, 'code-ajout-r1'); await p.close();
  p = await page(8099, 'an'); await p.waitForSelector('.pcl-kpi6'); await prise(p, 'code-analyse-r1'); await p.close();
  // Le téléphone.
  p = await page(8098, 'b2b', 390); await p.click('tr.pcl-ligne >> nth=0'); await p.waitForSelector('.pcl-kpis'); await prise(p, 'code-mobile'); await p.close();
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
