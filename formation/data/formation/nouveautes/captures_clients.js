// Captures lecture seule (modèle : captures_equipe_videos.js), 1600×1200, bloc entouré.
// Vendre › Clients : les noms des clients et des sociétés, les TVA, villes, téléphones, e-mails et adresses sont floutés
// avant la capture. La fenêtre « Connecter le compte TF Buddy du magasin » est capturée vide : rien n'y est saisi.
// node captures_clients.js <sortie> [noms…]
const { chromium } = require('playwright'); const path = require('path');
const OUT = process.argv[2]; const SEULES = process.argv.slice(3);
const W = 1200, H = 900, BASE = 'http://185.180.206.46/consulant_bo/';
const onglet = v => async p => { await p.locator(`[data-act="onglet"][data-v="${v}"]`).first().click(); };
const attendre = (s, ms) => async p => { await p.locator(s).first().waitFor({ state: 'visible', timeout: ms || 60000 }).catch(() => console.log('attente', s)); await p.waitForTimeout(1500); };
const suite = (...f) => async p => { for (const x of f) { await x(p); } };
const PLANS = [
  { nom: 'art-dash-clients-entreprises', avant: suite(onglet('b2b'), attendre('.pcl-table tbody tr'), async p => { await p.locator('.pcl-table tbody tr').nth(3).click(); }, attendre('.pcl-fiche .pcl-kpis')), cibles: ['.pcl-tete', '.pcl-2'], top: true },
  { nom: 'art-dash-clients-particuliers', avant: suite(onglet('b2c'), attendre('.pcl-table tbody tr', 90000)), cibles: ['.pcl-tete', '.pcl-2 .pcl-carte'], top: true },
  { nom: 'art-dash-clients-bureaux', avant: suite(onglet('bur'), attendre('.pcl-refus')), cibles: ['.pcl-refus'], top: true },
  { nom: 'art-dash-clients-connexion', avant: suite(onglet('bur'), attendre('.pcl-refus [data-act="tfb-connecter"]'), async p => { await p.locator('.pcl-refus [data-act="tfb-connecter"]').first().click(); await p.waitForTimeout(1200); }), cibles: ['.tfbm-fen .confirm__box'], pasDeScroll: true },
  { nom: 'art-dash-clients-analyse', avant: suite(onglet('an'), attendre('.pcl-kpi6', 90000)), cibles: ['.pcl-barre', '.pcl-kpi6'], top: true },
];
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3 });
  await ctx.route('**/assets/js/garde.js', async r => { const res = await r.fetch(); let t = await res.text(); t = t.replace("location.protocol === 'http:' && !local", 'false'); await r.fulfill({ response: res, body: t }); });
  await ctx.route('**/api/**', r => r.request().method() === 'GET' ? r.continue() : r.abort());
  for (const pl of PLANS) {
    if (SEULES.length && !SEULES.includes(pl.nom)) { continue; }
    const p = await ctx.newPage();
    await p.addInitScript(() => { try { const j = new Date(); const d = j.getFullYear() + '-' + String(j.getMonth() + 1).padStart(2, '0') + '-' + String(j.getDate()).padStart(2, '0'); localStorage.setItem('db.videos.plusTard', JSON.stringify({ clients: d })); } catch (e) { } });
    await p.goto(BASE + 'dashboard/?shop=4&vue=clients', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => { }); await p.waitForTimeout(5000);
    await p.addStyleTag({ content: '.tu-annonce{display:none!important}' });
    if (pl.avant) { await pl.avant(p); }
    // Flou : tout ce qui nomme ou situe un client (liste, fiche).
    await p.evaluate(() => {
      const flou = el => { el.style.filter = 'blur(6px)'; };
      document.querySelectorAll('.pcl-table tbody tr').forEach(tr => {
        const td = tr.children[0]; if (td) { [...td.childNodes].forEach(n => { if (n.nodeType === 3 && n.nodeValue.trim()) { const s = document.createElement('span'); s.textContent = n.nodeValue; flou(s); n.replaceWith(s); } }); td.querySelectorAll('.sub').forEach(flou); }
        if (document.querySelector('#pcl-pq') && tr.children[1]) { flou(tr.children[1]); }
      });
      document.querySelectorAll('.pcl-fiche .pcl-fnom, .pcl-fiche .pcl-fmeta, .pcl-fiche .pcl-lf > span:last-child, .pcl-fiche .pcl-chaine, .pcl-fiche .pcl-mini td:nth-child(2)').forEach(flou);
    });
    const boites = [];
    for (const s of pl.cibles) { try { const l = p.locator(s).first(); await l.waitFor({ state: 'visible', timeout: 15000 }); await l.evaluate(el => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = '5px'; el.style.borderRadius = el.style.borderRadius || '10px'; }); boites.push(await l.boundingBox()); } catch (e) { console.log(pl.nom, 'absent', s); } }
    let y = 0;
    if (pl.top && boites.length) { y = Math.max(0, Math.min(...boites.map(b => b.y)) - 30); }
    const opts = { path: path.join(OUT, pl.nom + '.jpg'), type: 'jpeg', quality: 82 };
    if (pl.pasDeScroll) { await p.screenshot(opts); } else { const hP = await p.evaluate(() => document.documentElement.scrollHeight); y = Math.min(y, Math.max(0, hP - H)); await p.screenshot({ ...opts, fullPage: true, clip: { x: 0, y, width: W, height: H } }); }
    console.log('ok', pl.nom, 'y=' + Math.round(y)); await p.close();
  }
  await b.close();
})();
