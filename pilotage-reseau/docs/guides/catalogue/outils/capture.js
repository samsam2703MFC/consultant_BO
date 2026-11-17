/*
 * Captures du livret « Créer mon catalogue » (livret.html), prises sur le dashboard en ligne, EN LECTURE SEULE :
 * - la garde d'accès (assets/js/garde.js) est remplacée par une session franchisé fictive (le serveur tourne en
 *   mode d'accès « ouvert ») ;
 * - toute requête vers l'API autre qu'un GET est refusée sur place : rien n'est créé, modifié ni supprimé ;
 * - le lien du catalogue en ligne, que l'étape 5 crée quand il manque, est remplacé par un lien d'exemple.
 * Les réglages faits à l'écran (titre, gamme cochée, titre de l'affiche) ne sont jamais enregistrés.
 *
 * Usage, depuis ce dossier :
 *   DASHBOARD_URL='http://…/consulant_bo/dashboard/?shop=4&vue=catalogue' node outils/capture.js captures
 * Le catalogue ouvert est le premier de la liste de la boutique (« Mes Buches » à Halle, le 17/11/2026).
 */
const { chromium } = require('playwright');
const ADRESSE = process.env.DASHBOARD_URL;
if (!ADRESSE) { console.error('DASHBOARD_URL manquant : l’adresse du dashboard, vue catalogue, de la boutique.'); process.exit(1); }
const OUT = process.argv[2] || 'captures';
async function ouvrir({ largeur = 1440, hauteur = 1000, echelle = 2 } = {}) {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: largeur, height: hauteur }, deviceScaleFactor: echelle, locale: 'fr-BE' });
  const page = await ctx.newPage();
  const bloques = [];
  await page.route('**/assets/js/garde.js', r => r.fulfill({ contentType: 'application/javascript', body: 'window.ACCES={role:"franchise",shops:["4"]};' }));
  await page.route('**/api/**', async r => {
    const req = r.request();
    if (req.method() === 'GET') { return r.continue(); }
    const u = req.url();
    // Le lien du catalogue en ligne n'existe pas encore : un lien d'exemple, sans rien créer sur le serveur.
    if (req.method() === 'POST' && /\/catalogues\/\d+\/lien/.test(u)) {
      return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { url: 'https://catalogue.exemple/mes-buches', token: 'exemple' } }) });
    }
    bloques.push(req.method() + ' ' + u);
    return r.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ success: false, error: { message: 'Écriture bloquée (capture en lecture seule).' } }) });
  });
  page.on('pageerror', e => console.log('ERREUR PAGE', e.message));
  await page.goto(ADRESSE, { waitUntil: 'networkidle', timeout: 60000 });
  return { browser, page, bloques };
}
// Captures du livret « Mon catalogue » : dashboard en ligne, lecture seule, pastilles numérotées posées sur l'écran.
const L = 1600, H = 1000;
const attendre = (page, ms = 1500) => page.waitForLoadState('networkidle').then(() => page.waitForTimeout(ms));

/** Pose des pastilles : [sélecteur | 'txt:Libellé', numéro, position ('hg' | 'g' | 'd' | 'hd'), rang]. */
async function marquer(page, liste) {
  const absents = await page.evaluate(liste => {
    document.querySelectorAll('.mk-guide').forEach(e => e.remove());
    const manque = [];
    const trouver = (sel, idx) => {
      if (sel.startsWith('txt:')) {
        const t = sel.slice(4);
        const tous = Array.from(document.querySelectorAll('button, a, span, div, li')).filter(e => e.textContent.trim() === t && e.offsetParent);
        return tous.sort((a, b) => a.getBoundingClientRect().width - b.getBoundingClientRect().width).reverse()[0] || null;
      }
      return document.querySelectorAll(sel)[idx || 0] || null;
    };
    liste.forEach(([sel, n, ou, idx]) => {
      const el = trouver(sel, idx); if (!el) { manque.push(sel); return; }
      const r = el.getBoundingClientRect(), t = 26;
      let x = r.left - t / 2, y = r.top - t / 2;
      if (ou === 'g') { x = r.left - t - 6; y = r.top + r.height / 2 - t / 2; }
      if (ou === 'd') { x = r.right + 6; y = r.top + r.height / 2 - t / 2; }
      if (ou === 'hd') { x = r.right - t / 2; y = r.top - t / 2; }
      const d = document.createElement('div'); d.className = 'mk-guide'; d.textContent = n;
      d.style.cssText = 'position:absolute;left:' + (x + scrollX) + 'px;top:' + (y + scrollY) + 'px;width:' + t + 'px;height:' + t + 'px;border-radius:50%;background:#8D1D2C;color:#fff;'
        + 'font:700 14px/' + t + 'px Gotham,system-ui,sans-serif;text-align:center;z-index:99999;outline:2px solid #fff;pointer-events:none';
      document.body.appendChild(d);
    });
    return manque;
  }, liste);
  if (absents.length) { console.log('  pastilles sans cible :', absents); }
}
/** Une zone de la page, du haut de `de` au bas de `a`, avec une marge. */
async function zone(page, nom, de, a, marge = 18) {
  const r = await page.evaluate(([de, a, m]) => {
    const x = document.querySelector(de), y = a ? document.querySelector(a) : x;
    const r1 = x.getBoundingClientRect(), r2 = y.getBoundingClientRect();
    const g = Math.min(r1.left, r2.left) - m, d = Math.max(r1.right, r2.right) + m;
    return { x: Math.max(0, g + scrollX), y: Math.max(0, r1.top + scrollY - m), width: d - g, height: r2.bottom - r1.top + 2 * m };
  }, [de, a, marge]);
  await page.screenshot({ path: OUT + '/' + nom + '.jpg', type: 'jpeg', quality: 86, clip: r, fullPage: true });
  console.log(nom, Math.round(r.width) + ' × ' + Math.round(r.height));
}
async function ouvrirMesBuches(page) {
  await page.click('button.cm-lien[data-cm="ouvrir"]');
  await page.waitForSelector('.cm-acc', { timeout: 90000 });
  await attendre(page, 2000);
}

(async () => {
  /* Session 1 : le module, puis un nouveau catalogue (rien n'est créé : on s'arrête avant « Créer »). */
  let s = await ouvrir({ largeur: L, hauteur: H });
  let page = s.page;
  await attendre(page);
  await page.evaluate(() => { const e = Array.from(document.querySelectorAll('*')).find(x => x.children.length === 0 && x.textContent.trim() === 'Mon catalogue' && x.closest('nav, aside, [class*="rail"], [class*="side"]')); if (e) { e.scrollIntoView({ block: 'center' }); } });
  await page.waitForTimeout(400);
  await marquer(page, [['txt:Mon catalogue', 1, 'd'], ['[data-cm="nouveau"]', 2, 'hg'], ['.cm-tab tr.cl .cm-lien', 3, 'g'], ['.cm-et', 4, 'hg'], ['.cm-et5', 5, 'hg']]);
  // Le menu (la rubrique Marketing) et la liste : deux zones lisibles plutôt que l'écran entier.
  const menu = await page.evaluate(() => {
    const e = Array.from(document.querySelectorAll('button, a, span, div, li')).filter(x => x.textContent.trim() === 'Mon catalogue' && x.offsetParent)
      .sort((a, b) => b.getBoundingClientRect().width - a.getBoundingClientRect().width)[0];
    const r = e.getBoundingClientRect();
    return { x: 0, y: Math.max(0, r.top - 136), width: r.right + 40, height: 280 };
  });
  await page.screenshot({ path: OUT + '/01a-menu.jpg', type: 'jpeg', quality: 86, clip: menu });
  await zone(page, '01b-liste', '.cm', '.cm-tab', 10);
  await page.click('[data-cm="nouveau"]');
  await page.waitForSelector('.cm-src button.gam', { timeout: 90000 });
  await attendre(page, 1000);
  await page.locator('.cm-src button.gam', { hasText: 'Noël' }).first().click();
  await page.fill('[data-cm="titre"]', 'Noël & Nouvel An 2026');
  await page.waitForTimeout(300);
  await page.evaluate(() => window.scrollTo(0, 0));
  await marquer(page, [['[data-cm="titre"]', 1, 'd'], ['.cm-nbp', 2, 'd'], ['.cm-src button.gam.on', 3, 'hg'], ['.cm-src button[data-v="tous"]', 4, 'hg'], ['[data-cm="creer"]', 5, 'hg'], ['.cm-cote', 6, 'hd']]);
  await zone(page, '02-nouveau', '.cm');
  console.log('écritures bloquées :', s.bloques);
  await s.browser.close();

  /* Session 2 : étape 2 (produits, promotions) et étape 3 (mise en page, réglée à l'écran sans enregistrer). */
  s = await ouvrir({ largeur: L, hauteur: H }); page = s.page;
  await attendre(page);
  await ouvrirMesBuches(page);
  await page.evaluate(() => window.scrollTo(0, 0));
  await marquer(page, [['input.cm-rech', 1, 'hg'], ['[data-cm="tout"][data-v="1"]', 2, 'hg'], ['.cm-acc.ouvert .cm-acc-t', 3, 'hg'], ['.cm-acc.ouvert .cm-acc-hd .cm-sw', 4, 'g'], ['.cm-acc.ouvert .cm-pl .cm-sw', 5, 'hd'], ['.cm-cote', 6, 'hg'], ['.cm-hd [data-cm="etape"][data-v="3"]', 7, 'hg']]);
  await zone(page, '03-produits', '.cm', '.cm-acc.ouvert');
  await page.evaluate(() => document.querySelectorAll('.mk-guide').forEach(e => e.remove()));
  await page.locator('.cm-offres').scrollIntoViewIfNeeded();
  await marquer(page, [['.cm-offre .cm-sw', 1, 'hd'], ['.cm-offre [data-cm="offreImg"]', 2, 'd'], ['.cm-offre.dispo .cm-sw', 3, 'hd']]);
  // Les trois premières offres, puis la ligne « Afficher la disponibilité » : deux zones assemblées dans le livret.
  await page.evaluate(() => { const o = document.querySelectorAll('.cm-offre:not(.dispo)'); o.forEach((e, i) => { if (i >= 3) { e.style.display = 'none'; } }); });
  await page.waitForTimeout(200);
  await marquer(page, [['.cm-offre .cm-sw', 1, 'hd'], ['.cm-offre [data-cm="offreImg"]', 2, 'd'], ['.cm-offre.dispo .cm-sw', 3, 'hd']]);
  await zone(page, '04-promotions', '.cm-offres');
  await page.evaluate(() => { document.querySelectorAll('.mk-guide').forEach(e => e.remove()); document.querySelectorAll('.cm-offre').forEach(e => { e.style.display = ''; }); });
  await page.click('.cm-hd [data-cm="etape"][data-v="3"]');
  await page.waitForSelector('.cm-pg select[data-cm="pcat"]', { timeout: 90000 });
  await attendre(page, 1500);
  await page.evaluate(() => window.scrollTo(0, 0));
  // Étape 3 (17/11/2026) : une seule rangée de choix, la mise en page en dessins ; l'en-tête ne se choisit plus.
  await marquer(page, [['.cm-reglages .cm-nbp', 1, 'd'], ['.cm-reglages .cm-champ:nth-child(2) .cm-seg', 2, 'hd'], ['.cm-reglages .cm-cases', 3, 'd'], ['[data-cm="repartir"]', 4, 'hg'],
    ['.cm-pg.lock .cm-lock', 5, 'hg'], ['.cm-pg:not(.lock) select[data-cm="pcat"]', 6, 'hg'], ['.cm-pg:not(.lock) .cm-lay', 7, 'g'], ['.cm-pg:not(.lock) .cm-script', 8, 'g'],
    ['.cm-pg:not(.lock) .cm-gab:not(.cm-lay)', 9, 'g'], ['.cm-pg:not(.lock) .mv', 10, 'hg'], ['.cm-pg:not(.lock) .cm-place', 11, 'g'], ['.cm-pg:not(.lock) .cm-ap', 12, 'hg']]);
  await zone(page, '05-mise-en-page', '.cm', '.cm-pg:not(.lock)');
  await page.evaluate(() => document.querySelectorAll('.mk-guide').forEach(e => e.remove()));
  // L'aperçu agrandi de la page 3 (un clic sur sa vignette).
  await page.locator('.cm-pg:not(.lock) .cm-ap').first().click();
  await page.waitForSelector('.cm-zoom__in', { timeout: 30000 });
  await page.waitForTimeout(1500);
  { const r = await page.locator('.cm-zoom__p .cm-ap').first().boundingBox(); await page.screenshot({ path: OUT + '/05b-apercu.jpg', type: 'jpeg', quality: 86, clip: r }); console.log('05b-apercu', Math.round(r.width) + ' × ' + Math.round(r.height)); }
  await page.click('.cm-zoom__x');
  await page.waitForTimeout(400);
  await zone(page, '06-mise-en-page-fin', '.cm-pg.lock:nth-last-of-type(2)', '.cm-corps .cm-pan .cm-pied', 14);
  console.log('écritures bloquées :', s.bloques);
  await s.browser.close();

  /* Session 3 : étape 4 (validation) et étape 5 (export, diffusion). */
  s = await ouvrir({ largeur: L, hauteur: H }); page = s.page;
  await attendre(page);
  await ouvrirMesBuches(page);
  await page.click('.cm-et5 [data-cm="etape"][data-v="4"]');
  await page.waitForSelector('.cm-verif', { timeout: 90000 });
  await page.waitForSelector('.cm-vg', { timeout: 90000 });
  await attendre(page, 2500);
  const vg = page.locator('.cm-vg');
  if (await vg.count() > 5) { await vg.nth(5).click(); await page.waitForTimeout(1500); }
  await page.evaluate(() => window.scrollTo(0, 0));
  await marquer(page, [['.cm-corps--valid .cm-pan .h .cm-seg', 1, 'hg'], ['.cm-verif.alerte .ge .cm-btn', 2, 'hg'], ['.cm-verif.question .ge', 3, 'hg'], ['.cm-verif.ok .cm-tag', 4, 'g'], ['.cm-grand', 5, 'hg'], ['.cm-mes-pages .cm-vign', 6, 'hg'], ['.cm-pied [data-cm="valider"]', 7, 'hg']]);
  await zone(page, '07-validation', '.cm');
  await page.evaluate(() => document.querySelectorAll('.mk-guide').forEach(e => e.remove()));
  await page.click('.cm-et5 [data-cm="etape"][data-v="5"]');
  await page.waitForSelector('.cm-qrbox svg', { timeout: 90000 });
  await page.waitForSelector('.cm-leger .cm-vide', { state: 'detached', timeout: 90000 }).catch(() => {});
  await attendre(page, 2500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await marquer(page, [['.cm-modes', 1, 'hg'], ['.cm-imp3 .cm-seg', 2, 'hd'], ['.cm-fichier .cm-msg.warn', 3, 'hg'], ['.cm-fichier .cm-large', 4, 'hg'], ['[data-cm="imprime"]', 5, 'hg']]);
  await zone(page, '08-imprimer', '.cm', '.cm-corps');
  await page.evaluate(() => document.querySelectorAll('.mk-guide').forEach(e => e.remove()));
  await page.fill('[data-cm="afficheTitre"]', 'Nos bûches de Noël');
  await page.click('[data-cm="afficheTaille"][data-v="A4"]');
  await page.waitForTimeout(300);
  await page.locator('.cm-diff').scrollIntoViewIfNeeded();
  await marquer(page, [['.cm-diff .cm-pan', 1, 'hg', 0], ['.cm-diff .cm-pan', 2, 'hg', 1], ['.cm-diff .cm-pan', 3, 'hg', 2], ['.cm-reprendre', 4, 'hg']]);
  await zone(page, '09-diffuser', '.cm-diff', '.cm-reprendre', 14);
  console.log('écritures bloquées :', s.bloques);
  await s.browser.close();
})();
