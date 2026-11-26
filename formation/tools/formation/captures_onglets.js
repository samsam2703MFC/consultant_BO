// Captures de la section « Le dashboard magasin, onglet par onglet » (data/formation/dashboard-onglets/).
// Chaque article a son plan : plans/<id>.json = [{ nom, vue, cibles: [css…], clics: [css…], attendre: css, cadre, mobile, date }]
//   nom      : le fichier rendu (photos/<nom>.jpg), le même que le champ « photo » de la partie ;
//   vue      : l'onglet du dashboard (ops, taches, semaine, mois, trimestre, annee, produits, promos, primes, concurrence,
//              morts, fidelite) ; mobile: true = le téléphone (iPhone 13, onglet `mo` : ops, exp, ctrl, tach, sem, prim) ;
//   clics    : ce qu'on touche avant la capture, dans l'ordre (sous-onglet, bouton qui ouvre une fenêtre…) ;
//   attendre : un élément qu'il faut voir avant la capture (lecture lente) ;
//   cibles   : le ou les blocs entourés en bordeaux ;
//   flou     : les éléments à flouter avant la capture (noms de clients : donnée personnelle — jamais lisible) ;
//   shop     : un autre magasin pour cette capture (un bloc vide au magasin par défaut) ;
//   cadre    : « centre » (défaut : le bloc au milieu du cadre), « haut » (depuis le haut de la page), « bas » (le bas du
//              bloc en bas du cadre), « ecran » (l'écran tel qu'il est : une fenêtre ouverte par-dessus la page).
// LECTURE SEULE : toute requête autre que GET est refusée par le navigateur (rien ne s'écrit au serveur).
// Usage : node tools/formation/captures_onglets.js <adresse …/dashboard/> data/formation/dashboard-onglets [plan…] [-- nom…]
//   SHOP (défaut 4) et DATE (défaut aujourd'hui) en variables d'environnement.
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.argv[2], DIR = process.argv[3] || 'data/formation/dashboard-onglets';
if (!BASE) { console.error('adresse du dashboard attendue (…/dashboard/)'); process.exit(2); }
const reste = process.argv.slice(4), sep = reste.indexOf('--');
const PLANS_F = sep < 0 ? reste : reste.slice(0, sep), SEULES = sep < 0 ? [] : reste.slice(sep + 1);
const SHOP = process.env.SHOP || '4', DATE = process.env.DATE || new Date().toISOString().slice(0, 10);
const W = 1200, H = 900;
const fichiers = PLANS_F.length ? PLANS_F.map(f => f.endsWith('.json') ? f : path.join(DIR, 'plans', f + '.json')) : fs.readdirSync(path.join(DIR, 'plans')).filter(f => f.endsWith('.json')).map(f => path.join(DIR, 'plans', f));
const OUT = path.join(DIR, 'photos');
const url = pl => pl.mobile ? `${BASE}?shop=${pl.shop || SHOP}&date=${pl.date || DATE}&mobile=1&mo=${pl.vue}` : `${BASE}?shop=${pl.shop || SHOP}&date=${pl.date || DATE}&vue=${pl.vue}`;

async function entourer(p, sel) {
  const loc = p.locator(sel).first();
  await loc.waitFor({ state: 'visible', timeout: 20000 });
  await loc.evaluate(el => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = '5px'; });
  // La boîte en coordonnées de la PAGE (boundingBox est relative à l'écran : un clic qui a fait défiler la page la décalait).
  const bx = await loc.boundingBox(), sy = await p.evaluate(() => scrollY);
  return bx && { ...bx, y: bx.y + sy };
}
(async () => {
  const b = await chromium.launch();
  const ordi = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3 });
  const tel = await b.newContext({ ...devices['iPhone 13'] });
  for (const ctx of [ordi, tel]) await ctx.route('**/*', r => r.request().method() === 'GET' ? r.continue() : r.abort());
  let ok = 0, ko = 0;
  for (const f of fichiers) {
    for (const pl of JSON.parse(fs.readFileSync(f, 'utf8'))) {
      if (SEULES.length && !SEULES.includes(pl.nom)) continue;
      const p = await (pl.mobile ? tel : ordi).newPage();
      try {
        await p.goto(url(pl), { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
        await p.waitForTimeout(pl.pause || 6000);
        for (const c of pl.clics || []) { await p.locator(c).first().click({ timeout: 20000 }); await p.waitForTimeout(pl.pauseClic || 2000); }
        if (pl.attendre) await p.locator(pl.attendre).first().waitFor({ timeout: 90000 });
        for (const f of pl.flou || []) await p.locator(f).evaluateAll(els => els.forEach(e => { e.style.filter = 'blur(6px)'; }));
        if ((pl.flou || []).length) await p.waitForTimeout(300);
        const boites = [];
        for (const s of pl.cibles || []) { try { boites.push(await entourer(p, s)); } catch (e) { console.log('  cible absente', pl.nom, s); } }
        const dest = path.join(OUT, pl.nom + '.jpg');
        if (pl.mobile || pl.cadre === 'ecran') {
          if (boites.length && !pl.mobile) await p.locator(pl.cibles[0]).first().scrollIntoViewIfNeeded().catch(() => {});
          if (pl.mobile && boites.length) await p.locator(pl.cibles[0]).first().evaluate(el => el.scrollIntoView({ block: 'center' }));
          await p.waitForTimeout(400);
          await p.screenshot({ path: dest, type: 'jpeg', quality: 82 });
        } else {
          await p.evaluate(() => scrollTo(0, 0));
          const hPage = await p.evaluate(() => document.documentElement.scrollHeight);
          let y = 0;
          if (pl.cadre !== 'haut' && boites.length) {
            const top = Math.min(...boites.map(x => x.y)), bas = Math.max(...boites.map(x => x.y + x.height)), h = bas - top;
            y = pl.cadre === 'bas' ? bas + 30 - H : (h + 40 >= H ? top - 30 : top - (H - h) / 2);
            y = Math.max(0, Math.min(y, hPage - H));
          }
          await p.screenshot({ path: dest, type: 'jpeg', quality: 82, fullPage: true, clip: { x: 0, y, width: W, height: H } });
        }
        console.log(boites.length === (pl.cibles || []).length ? 'ok' : 'PARTIEL', pl.nom); ok++;
      } catch (e) { console.log('ÉCHEC', pl.nom, e.message.split('\n')[0]); ko++; }
      await p.close();
    }
  }
  await b.close();
  console.log(`${ok} capture(s), ${ko} échec(s)`);
})();
