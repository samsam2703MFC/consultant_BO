// Captures du cockpit pour l'article ART-CHK-01 « Les checklists du magasin » (data/formation/checklists/) :
// cadre 4:3 (1600 × 1200), le bloc décrit entouré en bordeaux. Lecture seule : rien n'est cliqué qui écrive.
// Les autres photos de l'article (chk-organisation, chk-checklists-jour, chk-controles, chk-retours, chk-revue) sont
// celles du dashboard (data/formation/dashboard/photos/dash-*.jpg), copiées sous ce nom.
// Usage : node tools/formation/captures_checklists.js <adresse du cockpit, …/consulant_bo/> data/formation/checklists/photos
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2], OUT = process.argv[3] || 'data/formation/checklists/photos';
if (!BASE) { console.error('adresse du cockpit attendue (…/consulant_bo/)'); process.exit(2); }
const W = 1200, H = 900;
const PLANS = [
  // Le Document : une checklist, ses tâches et leurs réglages (heure, jours, photo, obligatoire, fait par, contrôle).
  { nom: 'chk-taches', url: 'task/?checklist=51', cible: '#dc-51', attendre: '#dc-51 .dc-b.fait:not(.att)' },
];
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3 });
  for (const pl of PLANS) {
    const p = await ctx.newPage();
    await p.goto(new URL(pl.url, BASE).href, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
    await p.locator(pl.attendre).first().waitFor({ timeout: 120000 }).catch(() => console.log(pl.nom, 'attente', pl.attendre));
    await p.waitForTimeout(1500);
    const loc = p.locator(pl.cible).first();
    await loc.evaluate(el => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = '5px'; el.style.borderRadius = el.style.borderRadius || '10px'; el.scrollIntoView({ block: 'start' }); });
    await p.evaluate(() => scrollBy(0, -30));
    await p.waitForTimeout(400);
    await p.screenshot({ path: path.join(OUT, pl.nom + '.jpg'), type: 'jpeg', quality: 82 });
    console.log('ok', pl.nom);
    await p.close();
  }
  await b.close();
})();
