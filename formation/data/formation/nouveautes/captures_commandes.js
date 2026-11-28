// Captures lecture seule (modèle : captures_lecture_seule.js), 1600×1200, bloc entouré.
// Dashboard › Commandes (Halle, ?shop=4). Les noms des clients et les notes des commandes sont floutés avant la capture.
// node captures_commandes.js <sortie> [noms…]
const { chromium } = require('playwright'); const path = require('path');
const OUT = process.argv[2]; const SEULES = process.argv.slice(3);
const W = 1200, H = 900, BASE = 'http://185.180.206.46/consulant_bo/';
const clic = s => async p => { await p.locator(s).first().click(); await p.waitForTimeout(4000); };
const suite = (...f) => async p => { for (const x of f) { await x(p); } };
const PLANS = [
  { nom: 'art-dash-commandes-liste', cibles: ['.pco-barre', '.pco-kpis', '.pco-tab'], top: true },
  { nom: 'art-dash-commandes-fiche', avant: clic('.pco-ligne[data-act="cmd"]'), cibles: ['.pco-fen .confirm__box'], pasDeScroll: true },
  { nom: 'art-dash-commandes-tournees', avant: clic('[data-act="vue"][data-v="tournees"]'), cibles: ['.pco-frise', '.pco-vide-t'], top: true },
  { nom: 'art-dash-commandes-nouvelle', avant: suite(clic('[data-act="gerer"]'), clic('.pco-fen [data-act="t-nouvelle"]')), cibles: ['.pco-fen .confirm__box'], pasDeScroll: true },
  { nom: 'art-dash-commandes-semaine', avant: clic('[data-act="per"][data-v="semaine"]'), cibles: ['.pco-kpis', '.pco-tab'], top: true },
  { nom: 'art-dash-commandes-mois', avant: clic('[data-act="per"][data-v="mois"]'), cibles: ['.pco-cal'], top: true },
];
const FLOU = () => {
  const s = document.createElement('style');
  s.textContent = '.pco-nom,.pco-note,.pco-anom,.pco-anote,.pco-dliste li span:first-child{filter:blur(5px)}'
    + '.tu-annonce{display:none!important}';
  document.head.appendChild(s);
  document.querySelectorAll('.pco-fic').forEach(f => { const k = f.querySelector('.k'); if (k && /^(Client|Note)$/.test(k.textContent.trim())) { f.lastElementChild.style.filter = 'blur(5px)'; } });
};
(async () => {
  const b = await chromium.launch({ args: ['--lang=fr-BE'], env: { ...process.env, LANG: 'fr_BE.UTF-8', LANGUAGE: 'fr_BE:fr' } }); const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3, locale: 'fr-BE' });
  await ctx.route('**/assets/js/garde.js', async r => { const res = await r.fetch(); let t = await res.text(); t = t.replace("location.protocol === 'http:' && !local", 'false'); await r.fulfill({ response: res, body: t }); });
  await ctx.route('**/api/**', r => r.request().method() === 'GET' ? r.continue() : r.abort());
  for (const pl of PLANS) {
    if (SEULES.length && !SEULES.includes(pl.nom)) { continue; }
    const p = await ctx.newPage();
    await p.addInitScript(() => { try { localStorage.removeItem('pco-vue:4'); localStorage.removeItem('pco-per:4'); const j = new Date(); const d = j.getFullYear() + '-' + String(j.getMonth() + 1).padStart(2, '0') + '-' + String(j.getDate()).padStart(2, '0'); localStorage.setItem('db.videos.plusTard', JSON.stringify({ commandes: d, 'equipe-horaires': d })); } catch (e) { } });
    await p.goto(BASE + 'dashboard/?shop=4&vue=commandes', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => { }); await p.waitForTimeout(7000);
    await p.evaluate(FLOU);
    if (pl.avant) { await pl.avant(p); }
    await p.evaluate(FLOU);
    const boites = [];
    for (const s of pl.cibles) { try { const l = p.locator(s).first(); await l.waitFor({ state: 'visible', timeout: 15000 }); await l.evaluate(el => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = '5px'; el.style.borderRadius = el.style.borderRadius || '10px'; }); boites.push(await l.boundingBox()); } catch (e) { console.log(pl.nom, 'absent', s); } }
    let y = 0;
    if (!pl.top && boites.length && !pl.pasDeScroll) { const hP = await p.evaluate(() => document.documentElement.scrollHeight); const top = Math.min(...boites.map(b => b.y)), bas = Math.max(...boites.map(b => b.y + b.height)); const h = bas - top; y = h + 40 >= H ? top - 30 : top - (H - h) / 2; y = Math.max(0, Math.min(y, hP - H)); }
    const opts = { path: path.join(OUT, pl.nom + '.jpg'), type: 'jpeg', quality: 82 };
    if (pl.pasDeScroll) { await p.screenshot(opts); } else { await p.screenshot({ ...opts, fullPage: true, clip: { x: 0, y, width: W, height: H } }); }
    console.log('ok', pl.nom, 'y=' + Math.round(y)); await p.close();
  }
  await b.close();
})();
