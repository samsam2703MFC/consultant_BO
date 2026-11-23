// Les tâches dans les temps morts (maquette), en trois écrans, sur le vrai dashboard.
// node capturer.cjs <données> <sortie> : <données> est le dossier rendu par « php ../ecran/panel_illustratif.php <données> » ;
// le site assemblé tourne sur 127.0.0.1:8098 (shared/bin/assembler_public.php).
const { chromium } = require('playwright'); const fs = require('fs'), path = require('path');
const [DATA, OUT] = [process.argv[2], process.argv[3] || __dirname], PROD = 'http://185.180.206.46/consulant_bo/api/cockpit';
const lire = f => fs.readFileSync(path.join(DATA, f), 'utf8');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 940 }, deviceScaleFactor: 2, locale: 'fr-BE' });
  const p = await ctx.newPage(); const err = [];
  p.on('pageerror', e => err.push(e.message));
  await p.route('**/api/cockpit/**', async r => {
    const u = new URL(r.request().url()), pa = u.pathname.replace(/^.*\/api\/cockpit/, '');
    if (pa.startsWith('/dashboard/equipe')) {
      const f = { '/dashboard/equipe/carte': 'carte.json', '/dashboard/equipe/matrice': 'matrice.json' }[pa]
        || (pa === '/dashboard/equipe/planning' ? (u.searchParams.get('jours') === '1' ? 'jour.json' : 'semaine.json') : null);
      return f ? r.fulfill({ status: 200, contentType: 'application/json', body: lire(f) }) : r.fulfill({ status: 404, json: { error: 'x' } });
    }
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 403, json: { error: 'lecture seule' } }); }
    try { const res = await fetch(PROD + pa + u.search, { signal: AbortSignal.timeout(30000) }); return r.fulfill({ status: res.status, contentType: res.headers.get('content-type') || 'application/json', body: await res.text() }); }
    catch (e) { return r.fulfill({ status: 504, json: { error: e.message } }); }
  });
  await p.goto('http://127.0.0.1:8098/dashboard/?shop=5&vue=equipe&date=2026-11-23', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('.eqp .eqp-grille', { timeout: 90000 }); await p.waitForTimeout(5000);
  await p.click('[data-eqp-onglet="planning"]'); await p.waitForSelector('.eqp-tab', { timeout: 20000 });
  await p.addStyleTag({ path: path.join(__dirname, 'taches.css') });
  await p.addScriptTag({ path: path.join(__dirname, 'taches.js') });
  await p.evaluate(() => window.EQT.monter(1)); await p.waitForTimeout(400);
  await p.screenshot({ path: path.join(OUT, 'taches-1-assistant-temps-morts.png') }); console.log('capture 1');
  await p.evaluate(() => document.querySelectorAll('.eqt-voile,.eqt-etiq').forEach(x => x.remove()));
  await p.click('[data-eqp-vue="jour"]'); await p.waitForSelector('.eqp-pg .eqp-tml', { timeout: 20000 }); await p.waitForTimeout(800);
  await p.evaluate(() => window.EQT.monter(2)); await p.waitForTimeout(500);
  await p.screenshot({ path: path.join(OUT, 'taches-2-jour-ligne-des-taches.png') }); console.log('capture 2');
  await p.evaluate(() => { document.querySelectorAll('.eqt-x').forEach(x => x.remove()); window.EQT.monter(3); }); await p.waitForTimeout(400);
  await p.screenshot({ path: path.join(OUT, 'taches-3-tablette-au-creux.png') }); console.log('capture 3');
  console.log(err.length ? 'erreurs : ' + err.join(' | ') : 'sans erreur de page');
  await b.close();
})();
