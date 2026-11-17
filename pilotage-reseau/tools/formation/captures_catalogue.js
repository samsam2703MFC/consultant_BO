// Captures du dashboard magasin › Mon catalogue pour l'article 0.0.52 « Le dashboard : l’onglet Mon catalogue »
// (data/formation/dashboard/art-dash-catalogue.json) : cadre 4:3 (1600 × 1200, le cadre photo de la tablette), le bloc
// décrit entouré en bordeaux, comme tools/formation/captures_dashboard.js.
//
// EN LECTURE SEULE : l'assistant du catalogue enregistre à chaque passage d'étape ; ici, toute requête vers l'API autre
// qu'un GET est refusée sur place (rien n'est créé, modifié ni supprimé), et le lien du catalogue en ligne, que l'étape 5
// crée quand il manque, est remplacé par un lien d'exemple. Les réglages faits à l'écran (titre, gamme cochée,
// titre de l'affiche) ne sont jamais enregistrés. La garde d'accès (assets/js/garde.js, qui
// repasse en https) est remplacée par une session franchisé : le serveur tourne en accès « ouvert ».
//
// Usage : node tools/formation/captures_catalogue.js <adresse du dashboard> data/formation/dashboard/photos [nom…]
//   l'adresse : celle de la page /dashboard/ du serveur (en http tant que le https du serveur est coupé) ;
//   SHOP (défaut 4) en variable d'environnement ; le catalogue ouvert est le premier de la liste de la boutique.
// Playwright et Chromium : ceux de la machine (require('playwright')).
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2];
if (!BASE) { console.error('adresse du dashboard attendue (…/dashboard/)'); process.exit(2); }
const OUT = process.argv[3] || 'data/formation/dashboard/photos';
const SEULES = process.argv.slice(4);
const SHOP = process.env.SHOP || '4';
const ADRESSE = `${BASE}?shop=${SHOP}&vue=catalogue`;
const L = 1600, H = 1000;            // à 1600 px de large, les réglages d'une page de l'étape 3 ne se chevauchent pas
const SORTIE = [1600, 1200];         // le cadre photo de la tablette

async function ouvrir(b) {
  const ctx = await b.newContext({ viewport: { width: L, height: H }, deviceScaleFactor: 2, locale: 'fr-BE' });
  const p = await ctx.newPage();
  const bloquees = [];
  await p.route('**/assets/js/garde.js', r => r.fulfill({ contentType: 'application/javascript', body: 'window.ACCES={role:"franchise",shops:["' + SHOP + '"]};' }));
  await p.route('**/api/**', r => {
    const q = r.request();
    if (q.method() === 'GET') { return r.continue(); }
    if (q.method() === 'POST' && /\/catalogues\/\d+\/lien/.test(q.url())) {
      return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { url: 'https://catalogue.exemple/mon-catalogue', token: 'exemple' } }) });
    }
    bloquees.push(q.method() + ' ' + q.url());
    return r.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ success: false, error: { message: 'Écriture bloquée (capture en lecture seule).' } }) });
  });
  await p.goto(ADRESSE, { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForSelector('.cm-tab, .cm-vide', { timeout: 90000 });
  await p.waitForTimeout(1500);
  return { ctx, p, bloquees };
}
const calme = (p, ms = 1500) => p.waitForLoadState('networkidle').then(() => p.waitForTimeout(ms));

/** Les boîtes (coordonnées de la page) des premiers éléments de chaque sélecteur. */
function boites(p, sels) {
  return p.evaluate(sels => sels.map(s => {
    const e = document.querySelector(s); if (!e) { return null; }
    const r = e.getBoundingClientRect();
    return { x: r.left + scrollX, y: r.top + scrollY, w: r.width, h: r.height, s };
  }), sels);
}
async function entourer(p, sels) {
  await p.evaluate(sels => {
    document.querySelectorAll('[data-entoure]').forEach(e => { e.style.outline = ''; e.style.outlineOffset = ''; e.removeAttribute('data-entoure'); });
    sels.forEach(s => { const e = document.querySelector(s); if (e) { e.setAttribute('data-entoure', '1'); e.style.outline = '4px solid #8D1D2C'; e.style.outlineOffset = '5px'; } });
  }, sels);
}
/** Le cadre 4:3 autour des blocs : assez large pour lire, centré, dans le module ; un bloc trop haut montre son début. */
function cadre(bs, module, hPage) {
  const g = Math.min(...bs.map(b => b.x)), d = Math.max(...bs.map(b => b.x + b.w));
  const t = Math.min(...bs.map(b => b.y)), bas = Math.max(...bs.map(b => b.y + b.h));
  const maxW = module.w + 24;
  let w = Math.min(maxW, Math.max(960, d - g + 140, (bas - t + 70) * 4 / 3));
  const h = w * 3 / 4;
  const x = Math.max(module.x - 12, Math.min((g + d) / 2 - w / 2, module.x + module.w + 12 - w));
  let y = bas - t + 70 > h ? t - 35 : (t + bas) / 2 - h / 2;
  y = Math.max(0, Math.min(y, hPage - h));
  return { x, y, width: w, height: h };
}
/** Une capture ramenée au cadre photo (1600 × 1200), redimensionnée dans le navigateur. */
async function photo(b, png, nom) {
  const p = await b.newPage();
  const data = await p.evaluate(async ([src, w, h]) => {
    const img = new Image(); img.src = src; await img.decode();
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const g = c.getContext('2d'); g.imageSmoothingQuality = 'high'; g.drawImage(img, 0, 0, w, h);
    return c.toDataURL('image/jpeg', 0.86).split(',')[1];
  }, ['data:image/png;base64,' + png.toString('base64'), SORTIE[0], SORTIE[1]]);
  await p.close();
  require('fs').writeFileSync(path.join(OUT, nom + '.jpg'), Buffer.from(data, 'base64'));
  console.log('ok', nom);
}
/** Entoure `cibles`, cadre sur `cibles` + `contexte`, capture. */
async function plan(b, p, nom, cibles, contexte = []) {
  if (SEULES.length && !SEULES.includes(nom)) { return; }
  await entourer(p, cibles);
  await p.waitForTimeout(200);
  const bs = (await boites(p, cibles.concat(contexte))).filter(Boolean);
  if (!bs.length) { console.log(nom, ': cible absente', cibles); return; }
  const [module] = await boites(p, ['.cm']);
  const hPage = await p.evaluate(() => document.documentElement.scrollHeight);
  const png = await p.screenshot({ type: 'png', fullPage: true, clip: cadre(bs, module, hPage) });
  await entourer(p, []);
  await photo(b, png, nom);
}

(async () => {
  const b = await chromium.launch();
  const bloquees = [];

  // 1. La liste, les étapes, un nouveau catalogue (on s'arrête avant « Créer ») ----------------------------------
  let s = await ouvrir(b), p = s.p;
  await plan(b, p, 'cat-liste', ['.db-card:has(.cm-tab)'], ['.cm-hd']);
  await p.click('[data-cm="nouveau"]');
  await p.waitForSelector('.cm-src button.gam', { timeout: 90000 });
  await calme(p, 800);
  await p.locator('.cm-src button.gam', { hasText: 'Noël' }).first().click();
  await p.fill('[data-cm="titre"]', 'Noël & Nouvel An 2026');
  await p.waitForTimeout(300);
  await plan(b, p, 'cat-nouveau', ['label.cm-champ:has([data-cm="titre"])', '.cm-champ:has(.cm-nbp)'], ['.cm-hd']);
  await plan(b, p, 'cat-gammes', ['.cm-src', '[data-cm="creer"]']);
  bloquees.push(...s.bloquees); await s.ctx.close();

  // 2. Le premier catalogue : produits, promotions, mise en page, tels qu'enregistrés -----------------------------
  s = await ouvrir(b); p = s.p;
  await p.click('button.cm-lien[data-cm="ouvrir"]');
  await p.waitForSelector('.cm-acc', { timeout: 90000 });
  await calme(p, 1500);
  await plan(b, p, 'cat-produits', ['.cm-acc.ouvert'], ['input.cm-rech']);
  await plan(b, p, 'cat-promos', ['.cm-offres']);
  await p.click('.cm-hd [data-cm="etape"][data-v="3"]');
  await p.waitForSelector('.cm-pg select[data-cm="pcat"]', { timeout: 90000 });
  await calme(p, 1500);
  await plan(b, p, 'cat-reglages', ['.cm-reglages', '[data-cm="repartir"]'], ['.cm-pg.lock']);
  await plan(b, p, 'cat-page', ['.cm-pg:not(.lock)']);
  if (!SEULES.length || SEULES.includes('cat-apercu')) {
    await p.evaluate(() => scrollTo(0, 0));
    await p.locator('.cm-pg:not(.lock) .cm-ap').first().click();
    await p.waitForSelector('.cm-zoom__in', { timeout: 30000 });
    await p.waitForTimeout(1500);
    const r = await p.locator('.cm-zoom__in').boundingBox();
    const h = Math.min(H, r.height + 60), w = h * 4 / 3;
    const png = await p.screenshot({ type: 'png', clip: { x: r.x + r.width / 2 - w / 2, y: Math.max(0, r.y + r.height / 2 - h / 2), width: w, height: h } });
    await photo(b, png, 'cat-apercu');
    await p.click('.cm-zoom__x');
  }
  bloquees.push(...s.bloquees); await s.ctx.close();

  // 3. Validation et export -------------------------------------------------------------------------------------
  s = await ouvrir(b); p = s.p;
  await p.click('button.cm-lien[data-cm="ouvrir"]');
  await p.waitForSelector('.cm-acc', { timeout: 90000 });
  await calme(p, 1000);
  await p.click('.cm-et5 [data-cm="etape"][data-v="4"]');
  await p.waitForSelector('.cm-verif', { timeout: 90000 });
  await p.waitForSelector('.cm-vg', { timeout: 90000 });
  await calme(p, 2500);
  await plan(b, p, 'cat-etapes', ['.cm-et5'], ['.cm-hd']);   // les étapes faites portent leur coche
  await plan(b, p, 'cat-verifs', ['.cm-verifs']);
  const vg = p.locator('.cm-vg');
  if (await vg.count() > 5) { await vg.nth(5).click(); await p.waitForTimeout(1500); }
  await plan(b, p, 'cat-pages', ['.cm-mes-pages'], ['.cm-corps--valid']);
  await p.click('.cm-et5 [data-cm="etape"][data-v="5"]');
  await p.waitForSelector('.cm-qrbox svg', { timeout: 90000 });
  await calme(p, 2500);
  await plan(b, p, 'cat-imprimer', ['.cm-modes', '.cm-fichier'], ['.cm-corps']);
  await p.fill('[data-cm="afficheTitre"]', 'Nos bûches de Noël');
  await p.click('[data-cm="afficheTaille"][data-v="A4"]');
  await p.waitForTimeout(500);
  await plan(b, p, 'cat-diffuser', ['.cm-diff']);
  bloquees.push(...s.bloquees); await s.ctx.close();

  console.log('écritures bloquées :', bloquees.length ? bloquees : 'aucune');
  await b.close();
})();
