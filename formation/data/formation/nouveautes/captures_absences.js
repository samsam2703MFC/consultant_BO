// Captures lecture seule (modèle : captures_lecture_seule.js), 1600×1200, bloc entouré.
// Mon équipe : tout nom d'employé (lu dans /dashboard/equipe/carte, GET) et les initiales sont floutés à l'écran avant la capture.
// Mon équipe : absences, congés et remplacements (28/11/2026). node captures_absences.js <sortie> [noms…]
// La fenêtre « Absence ou congé » est ouverte et remplie à l'écran (GET seulement) ; « Poser l'absence » n'est jamais cliqué.
const { chromium } = require('playwright'); const path = require('path');
const OUT = process.argv[2]; const SEULES = process.argv.slice(3);
const W = 1200, H = 900, BASE = 'http://185.180.206.46/consulant_bo/';
const clic = s => async p => { await p.locator(s).first().click(); await p.waitForTimeout(3500); };
const suite = (...f) => async p => { for (const x of f) { await x(p); } };
const planning = clic('[data-eqp-onglet="planning"]');
const fiche = clic('[data-eqp-fiche]');
const ouvrirAbs = async p => { await p.locator('[data-eqp-abs-nouv]').first().click(); await p.waitForTimeout(1500);
  const sel = p.locator('[data-eqp-ab-emp]').first(); const vals = await sel.locator('option').evaluateAll(o => o.map(x => x.value).filter(Boolean));
  for (const v of vals) { await sel.selectOption(v); await p.waitForTimeout(4000); if (await p.locator('.eqp-ab-t').count()) { break; } }
  await p.locator('[data-eqp-ab-au]').first().fill('2026-11-29').catch(() => { }); await p.locator('.eqp-ab-t').first().waitFor({ state: 'visible', timeout: 60000 }).catch(() => console.log('tableau absent')); await p.waitForTimeout(1500);
  const rp = p.locator('[data-eqp-ab-rp]').first(); if (await rp.count()) { const o = await rp.locator('option').evaluateAll(x => x.map(y => y.value).filter(v => v && v !== 'x')); if (o.length) { await rp.selectOption(o[0]); await p.waitForTimeout(1500); } } };
const PLANS = [
  { nom: 'art-dash-absences-barre', vue: 'equipe', avant: planning, cibles: ['.eqp-pbar'], top: true },
  { nom: 'art-dash-absences-fenetre', vue: 'equipe', avant: suite(planning, ouvrirAbs), cibles: ['.eqp-ab-fo'], pasDeScroll: true, dedans: true },
  { nom: 'art-dash-absences-statut', vue: 'equipe', avant: suite(fiche), cibles: ['.eqp-fp-ch:has(#eqp-fp-statut)'], pasDeScroll: true },
];
(async () => {
  const b = await chromium.launch({ args: ['--lang=fr-BE'] }); const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3, locale: 'fr-BE' });
  await ctx.route('**/assets/js/garde.js', async r => { const res = await r.fetch(); let t = await res.text(); t = t.replace("location.protocol === 'http:' && !local", 'false'); await r.fulfill({ response: res, body: t }); });
  await ctx.route('**/api/**', r => r.request().method() === 'GET' ? r.continue() : r.abort());
  const carte = await (await ctx.request.get(BASE + 'api/cockpit/dashboard/equipe/carte?shop=4')).json();
  const noms = [...new Set(carte.employes.flatMap(e => [e.nom, ...e.nom.split(/\s+/).filter(x => x.length > 2)]))].sort((a, c) => c.length - a.length);
  for (const pl of PLANS) {
    if (SEULES.length && !SEULES.includes(pl.nom)) { continue; }
    const p = await ctx.newPage();
    // L'annonce d'une nouvelle vidéo : rangée (« Plus tard ») sauf pour les captures des vidéos.
    await p.addInitScript(v => { try { localStorage.removeItem('db.videos.plusTard'); localStorage.removeItem('db.videos.vues'); if (!v) { const j = new Date(); const d = j.getFullYear() + '-' + String(j.getMonth() + 1).padStart(2, '0') + '-' + String(j.getDate()).padStart(2, '0'); localStorage.setItem('db.videos.plusTard', JSON.stringify({ 'equipe-horaires': d })); } } catch (e) { } }, !!pl.videos);
    await p.goto(pl.url || (BASE + 'dashboard/?shop=4&vue=' + pl.vue), { waitUntil: 'networkidle', timeout: 120000 }).catch(() => { }); await p.waitForTimeout(6000);
    if (pl.avant) { await pl.avant(p); }
    // Flou : les noms des employés et les initiales.
    await p.evaluate(N => {
      const re = new RegExp('(' + N.map(n => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'g');
      const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); const L = []; let n; while ((n = w.nextNode())) { if (re.test(n.nodeValue)) { L.push(n); } re.lastIndex = 0; }
      L.forEach(t => { const s = document.createElement('span'); s.innerHTML = t.nodeValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(re, '<span style="filter:blur(5px)">$1</span>'); t.replaceWith(s); });
      document.querySelectorAll('.eqp-av, .eqp-fp-nm, .eqp-fp-li .c, .eqp-fp-li small, .eqp-moi tbody td:first-child').forEach(e => { e.style.filter = 'blur(5px)'; });
      document.querySelectorAll('input').forEach(i => { if (re.test(i.value)) { i.style.filter = 'blur(5px)'; } re.lastIndex = 0; });
      document.querySelectorAll('option').forEach(o => { if (re.test(o.textContent)) { o.textContent = '…'; } re.lastIndex = 0; });
    }, noms);
    if (!pl.videos) { await p.addStyleTag({ content: '.tu-annonce{display:none!important}' }); }
    const boites = [];
    for (const s of pl.cibles) { try { const l = p.locator(s).first(); await l.waitFor({ state: 'visible', timeout: 15000 }); await l.evaluate((el, DEDANS) => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = DEDANS ? '-4px' : '5px'; el.style.borderRadius = el.style.borderRadius || '10px'; }, !!pl.dedans); boites.push(await l.boundingBox()); } catch (e) { console.log(pl.nom, 'absent', s); } }
    let y = 0;
    if (!pl.top && boites.length && !pl.pasDeScroll) { const hP = await p.evaluate(() => document.documentElement.scrollHeight); const top = Math.min(...boites.map(b => b.y)), bas = Math.max(...boites.map(b => b.y + b.height)); const h = bas - top; y = h + 40 >= H ? top - 30 : top - (H - h) / 2; y = Math.max(0, Math.min(y, hP - H)); }
    const opts = { path: path.join(OUT, pl.nom + '.jpg'), type: 'jpeg', quality: 82 };
    if (pl.pasDeScroll) { await p.screenshot(opts); } else { await p.screenshot({ ...opts, fullPage: true, clip: { x: 0, y, width: W, height: H } }); }
    console.log('ok', pl.nom, 'y=' + Math.round(y)); await p.close();
  }
  await b.close();
})();
