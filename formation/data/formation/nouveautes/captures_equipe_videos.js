// Captures lecture seule (modèle : captures_lecture_seule.js), 1600×1200, bloc entouré.
// Mon équipe : tout nom d'employé (lu dans /dashboard/equipe/carte, GET) et les initiales sont floutés à l'écran avant la capture.
// node captures_equipe_videos.js <sortie> [noms…]
const { chromium } = require('playwright'); const path = require('path');
const OUT = process.argv[2]; const SEULES = process.argv.slice(3);
const W = 1200, H = 900, BASE = 'http://185.180.206.46/consulant_bo/';
const clic = s => async p => { await p.locator(s).first().click(); await p.waitForTimeout(3500); };
const suite = (...f) => async p => { for (const x of f) { await x(p); } };
const planning = clic('[data-eqp-onglet="planning"]');
const PLANS = [
  { nom: 'art-dash-equipe-resp', vue: 'equipe', cibles: ['.eqp-kpis', '.eqp-grille'], top: true },
  { nom: 'art-dash-equipe-affect', vue: 'equipe', avant: clic('.eqp-fiche [data-eqp-postes]'), cibles: ['.eqp-mod'], pasDeScroll: true },
  { nom: 'art-dash-equipe-semaine', vue: 'equipe', avant: planning, cibles: ['.eqp-pbar', '.eqp-bj'], top: true },
  { nom: 'art-dash-equipe-jour', vue: 'equipe', avant: suite(planning, clic('[data-eqp-vue="jour"]')), cibles: ['.eqp-pg'], },
  { nom: 'art-dash-equipe-service', vue: 'equipe', avant: suite(planning, clic('[data-eqp-svc]')), cibles: ['.eqp-fo-tete', '.eqp-fo-plan', '.eqp-fo-effs'], pasDeScroll: true, dedans: true },
  { nom: 'art-dash-equipe-options', vue: 'equipe', avant: clic('[data-eqp-options]'), cibles: ['.eqp-opts'], pasDeScroll: true },
  { nom: 'art-dash-videos-carre', vue: 'equipe', videos: true, cibles: ['.tu-carre', '.tu-annonce'], pasDeScroll: true, dedans: false },
  { nom: 'art-dash-videos-lecteur', vue: 'equipe', videos: true, avant: async p => { await p.locator('.tu-carre').first().click(); await p.waitForTimeout(4000); await p.evaluate(() => { const v = document.querySelector('.tu-video'); if (v) { v.pause(); v.currentTime = 20; } }); await p.waitForTimeout(2500); }, cibles: ['.tu-fen'], pasDeScroll: true },
  { nom: 'art-dash-videos-videotheque', url: BASE + 'formation/catalogue/#/videos', cibles: ['.ct-corps'], top: true, videos: true },
];
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3 });
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
      document.querySelectorAll('.eqp-av').forEach(e => { e.style.filter = 'blur(4px)'; });
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
