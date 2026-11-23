/* Les captures de la maquette « Mon équipe » : le vrai dashboard (site assemblé servi en local, lectures GET relayées
 * vers la production, aucune écriture), et la maquette posée par-dessus (maquette.css, maquette.js).
 *   php shared/bin/assembler_public.php <site> && php -S 127.0.0.1:8098 -t <site> <site>/router.php
 *   node capturer.cjs <dossier de sortie> */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = process.argv[2] || __dirname;
const PROD = 'http://185.180.206.46/consulant_bo/api/cockpit';
const CSS = fs.readFileSync(path.join(__dirname, 'maquette.css'), 'utf8'), JS = fs.readFileSync(path.join(__dirname, 'maquette.js'), 'utf8');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 940 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage(); const err = [];
  p.on('pageerror', e => err.push(e.message));
  await p.route('**/api/cockpit/**', async r => {
    const u = new URL(r.request().url()), pa = u.pathname.replace(/^.*\/api\/cockpit/, '') + u.search;
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 403, json: { error: 'maquette : lecture seule' } }); }
    try { const res = await fetch(PROD + pa, { signal: AbortSignal.timeout(30000) });
      return r.fulfill({ status: res.status, contentType: res.headers.get('content-type') || 'application/json', body: await res.text() }); }
    catch (e) { return r.fulfill({ status: 504, json: { error: 'maquette : ' + e.message } }); }
  });
  await p.goto('http://127.0.0.1:8098/dashboard/?shop=5&vue=formation&date=2026-11-23', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('#db-rail [data-vue="morts"]', { timeout: 90000 }); await p.waitForTimeout(12000);
  await p.addStyleTag({ content: CSS }); await p.addScriptTag({ content: JS });
  for (const [v, nom] of [['resp', '01-responsabilites'], ['modale', '02-modifier-les-affectations'], ['planning', '03-planning'], ['employes', '04-employes']]) {
    await p.setViewportSize({ width: 1440, height: 940 });
    console.log(nom, await p.evaluate(x => window.EQ.monter(x), v));
    // La page entière : la fenêtre prend la hauteur du contenu de la maquette.
    const h = await p.evaluate(() => document.getElementById('eq-maq').scrollHeight);
    await p.setViewportSize({ width: 1440, height: Math.max(940, h) });
    await p.waitForTimeout(400);
    await p.screenshot({ path: path.join(OUT, nom + '.png') });
  }
  console.log(err.length ? 'ERREURS ' + err.join(' | ') : 'sans erreur de page');
  await b.close();
})();
