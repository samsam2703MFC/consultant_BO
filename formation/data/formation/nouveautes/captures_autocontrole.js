// Captures lecture seule de l'autocontrôle qualité (tablette du magasin), 1600×1200, bloc entouré.
// Mode essai (&essai=1) et toute requête autre que GET bloquée : rien n'est enregistré. Aucun nom de personne à l'écran
// (le champ « Votre prénom » reste vide).
// node captures_autocontrole.js <sortie> [noms…]
const { chromium } = require('playwright'); const path = require('path');
const OUT = process.argv[2]; const SEULES = process.argv.slice(3);
const W = 1200, H = 900, BASE = 'http://185.180.206.46/consulant_bo/autocontrole/?shop=4&essai=1';
const PROD = BASE + '&produit=1300003';
const clic = s => async p => { await p.locator(s).first().click(); await p.waitForTimeout(1200); };
const suite = (...f) => async p => { for (const x of f) { await x(p); } };
const PLANS = [
  { nom: 'art-autocontrole-liste', url: BASE, cibles: ['.aq-head', '.aq-recherche'] },
  { nom: 'art-autocontrole-cuisson', url: PROD, cibles: ['.aq-steps', '.aq-photos'] },
  { nom: 'art-autocontrole-correction', url: PROD, avant: clic('[data-aq-rep="coloration"][data-v="pale"]'), cibles: ['#aq-coloration'], defile: '#aq-coloration' },
  { nom: 'art-autocontrole-presentation', url: PROD, avant: clic('[data-aq-aller="presentation"]'), cibles: ['#aq-ressemblance', '#aq-planogramme'], defile: '#aq-planogramme' },
  {
    nom: 'art-autocontrole-bilan', url: PROD,
    avant: suite(clic('[data-aq-rep="coloration"][data-v="pale"]'), clic('[data-aq="conforme"]'),
      clic('[data-aq-aller="structure"]'), clic('[data-aq="conforme"]'),
      clic('[data-aq-aller="organoleptique"]'), clic('[data-aq="conforme"]'),
      clic('[data-aq-aller="presentation"]'), clic('[data-aq="conforme"]'),
      clic('[data-aq-aller="bilan"]')),
    cibles: ['.aq-body > .aq-card', '.aq-dec', '.aq-foot'],
  },
];
(async () => {
  const b = await chromium.launch({ args: ['--lang=fr-BE'] }); const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3, locale: 'fr-BE', timezoneId: 'Europe/Brussels' });
  await ctx.route('**/assets/js/garde.js', async r => { const res = await r.fetch(); let t = await res.text(); t = t.replace("location.protocol === 'http:' && !local", 'false'); await r.fulfill({ response: res, body: t }); });
  await ctx.route('**/*', r => r.request().method() === 'GET' ? r.fallback() : r.abort());
  for (const pl of PLANS) {
    if (SEULES.length && !SEULES.includes(pl.nom)) { continue; }
    const p = await ctx.newPage();
    await p.goto(pl.url, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => { });
    await p.waitForSelector('html[data-pret="1"] .aq-ov', { timeout: 60000 }).catch(() => { });
    await p.waitForTimeout(3000);
    if (pl.avant) { await pl.avant(p); }
    if (pl.defile) { await p.locator(pl.defile).first().evaluate(el => el.scrollIntoView({ block: 'center' })); await p.waitForTimeout(800); }
    for (const s of pl.cibles) { try { const l = p.locator(s).first(); await l.waitFor({ state: 'visible', timeout: 15000 }); await l.evaluate(el => { el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = '-4px'; el.style.borderRadius = el.style.borderRadius || '10px'; }); } catch (e) { console.log(pl.nom, 'absent', s); } }
    await p.screenshot({ path: path.join(OUT, pl.nom + '.jpg'), type: 'jpeg', quality: 82 });
    console.log('ok', pl.nom); await p.close();
  }
  await b.close();
})();
