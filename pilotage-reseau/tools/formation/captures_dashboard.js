// Captures du dashboard magasin pour l'article ART-PIL-01 et la SOP SOP-PIL-01 (data/formation/dashboard/) :
// cadre 4:3 (1600 × 1200, le cadre photo de la tablette), le bloc décrit entouré en bordeaux.
// Usage : node tools/formation/captures_dashboard.js <adresse du dashboard> data/formation/dashboard/photos [nom…]
//   l'adresse : celle de la page /dashboard/ du serveur (en http tant que le https du serveur est coupé) ;
//   SHOP (défaut 4) et DATE (défaut aujourd'hui) en variables d'environnement ; [nom…] : ne refaire que ces captures.
// Playwright et Chromium : ceux de la machine (require('playwright')).
const { chromium, devices } = require('playwright');
const path = require('path');
const BASE = process.argv[2];
if (!BASE) { console.error('adresse du dashboard attendue (…/dashboard/)'); process.exit(2); }
const OUT = process.argv[3] || 'data/formation/dashboard/photos';
const SEULES = process.argv.slice(4);
const SHOP = process.env.SHOP || '4', DATE = process.env.DATE || new Date().toISOString().slice(0, 10);
const url = vue => `${BASE}?shop=${SHOP}&date=${DATE}&vue=${vue}`;

// nom → vue, cible(s) entourée(s), cadrage (top = cadrer depuis le haut de la page), action avant capture.
const PLANS = [
  { nom: 'dash-entete', vue: 'ops', cibles: ['.db-nav'], top: true },
  { nom: 'dash-valeur', vue: 'semaine', cibles: ['.db-vdl'], avant: async p => { await p.locator('[data-vdrop]').first().click().catch(e => console.log('clic', e.message)); await p.waitForTimeout(2500); }, top: true },
  { nom: 'dash-frise', vue: 'ops', cibles: ['.db-frise'], top: true },
  { nom: 'dash-reseau', vue: 'ops', cibles: ['.db-bench'] },
  { nom: 'dash-objectif', vue: 'ops', cibles: ['.op-duel:not(.b2b)'] },
  { nom: 'dash-heures', vue: 'ops', cibles: ['.db-card.op-bloc >> nth=0'] },
  { nom: 'dash-duel', vue: 'ops', cibles: ['.db-card.op-bloc >> nth=1'] },
  { nom: 'dash-pl', vue: 'ops', cibles: ['.db-card:has(.db-cascade)'] },
  { nom: 'dash-categories', vue: 'ops', cibles: ['.db-card:has(.db-acc)'] },
  { nom: 'dash-journee', vue: 'ops', cibles: ['.db-card.op-bloc:has(.op-jr)'] },
  { nom: 'dash-vitrine', vue: 'ops', cibles: ['.db-card.vt'] },
  { nom: 'dash-controles', vue: 'ops', cibles: ['.db-cq'] },
  { nom: 'dash-stock', vue: 'ops', cibles: ['.op-stock'], bas: true },
  { nom: 'dash-taches', vue: 'semaine', cibles: ['.db-taches'] },
  { nom: 'dash-resultat', vue: 'semaine', cibles: ['.db-tuiles.huit'] },
  { nom: 'dash-canaux', vue: 'semaine', cibles: ['.db-split.co'] },
  { nom: 'dash-invendus', vue: 'semaine', cibles: ['.db-inv'] },
  { nom: 'dash-cal-semaine', vue: 'semaine', cibles: ['.cal-a'] },
  { nom: 'dash-douze', vue: 'semaine', cibles: ['.db-card:has-text("DOUZE DERNIÈRES SEMAINES")'] },
  { nom: 'dash-heures-semaine', vue: 'semaine', cibles: ['.db-tuiles:not(.huit)'], avecSuite: true },
  { nom: 'dash-leviers', vue: 'mois', cibles: ['.lv-card'] },
  { nom: 'dash-cal-mois', vue: 'mois', cibles: ['.cal-a'] },
  { nom: 'dash-rentab', vue: 'mois', cibles: ['.db-card:has-text("RENTABILITÉ")'], avecAvant: true },
  { nom: 'dash-trimestre', vue: 'trimestre', cibles: ['.db-tuiles'], top: true },
  { nom: 'dash-annotations', vue: 'trimestre', cibles: ['.db-g2'] },
  { nom: 'dash-annee', vue: 'annee', cibles: ['.db-tuiles', '.db-card >> nth=0'], top: true },
  { nom: 'dash-heatmap', vue: 'annee', cibles: ['.db-card:has-text("HEATMAP MENSUELLE")'] },
  { nom: 'dash-promos', vue: 'promos', cibles: ['.db-card >> nth=0'], top: true },
  // La ligne du franchisé (07/11/2026) : dans le tableau, en gris, « franchisé · hors primes ».
  { nom: 'dash-primes', vue: 'primes', cibles: ['tr:has(.pr-fr)'], avecAvant: true },
];

const W = 1200, H = 900;
async function entourer(p, sel) {
  const loc = p.locator(sel).first();
  await loc.waitFor({ state: 'visible', timeout: 20000 });
  await loc.evaluate(el => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = '5px'; el.style.borderRadius = el.style.borderRadius || '10px'; });
  return loc.boundingBox();
}

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3 });
  const pages = {};
  for (const plan of PLANS) {
    if (SEULES.length && !SEULES.includes(plan.nom)) continue;
    const p = await ctx.newPage();
    await p.goto(url(plan.vue), { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
    await p.waitForTimeout(6000);
    if (plan.avant) await plan.avant(p);
    let boites = [];
    for (const s of plan.cibles || []) { try { boites.push(await entourer(p, s)); } catch (e) { console.log(plan.nom, 'cible absente', s); } }
    await p.evaluate(() => scrollTo(0, 0));
    const hPage = await p.evaluate(() => document.documentElement.scrollHeight);
    let y = 0;
    if (!plan.top && boites.length) {
      const top = Math.min(...boites.map(b => b.y)), bas = Math.max(...boites.map(b => b.y + b.height));
      const h = bas - top;
      y = plan.bas ? bas + 40 - H : (h + 40 >= H || plan.avecSuite ? top - 30 : top - (H - h) / 2);
      if (plan.avecAvant) y = bas + 30 - H;
      y = Math.max(0, Math.min(y, hPage - H));
    }
    // Une capture pleine page, puis le cadre 4:3 qu'on garde.
    await p.screenshot({ path: path.join(OUT, plan.nom + '.jpg'), type: 'jpeg', quality: 82, fullPage: true, clip: { x: 0, y, width: W, height: H } });
    console.log('ok', plan.nom, 'y=' + Math.round(y));
    await p.close();
  }
  // Le téléphone : la vue du jour et celle de la semaine, côte à côte dans le même cadre 4:3 (dash-mobile.jpg).
  if (!SEULES.length || SEULES.includes('dash-mobile')) {
    const m = await b.newContext({ ...devices['iPhone 13'] });
    const ecrans = [];
    for (const v of ['ops', 'semaine']) {
      const p = await m.newPage();
      await p.goto(url(v) + '&mobile=1', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
      await p.waitForTimeout(6000);
      ecrans.push((await p.screenshot()).toString('base64'));
      await p.close();
    }
    await m.close();
    const c = await b.newPage({ viewport: { width: 1600, height: 1200 } });
    await c.setContent(`<body style="margin:0;background:#F7F4EF;display:flex;gap:80px;justify-content:center;align-items:center;height:1200px">${ecrans
      .map(e => `<img src="data:image/png;base64,${e}" style="height:1120px;border:4px solid #8D1D2C;border-radius:18px">`).join('')}</body>`);
    await c.screenshot({ path: path.join(OUT, 'dash-mobile.jpg'), type: 'jpeg', quality: 82 });
    console.log('ok dash-mobile');
  }
  await b.close();
})();
