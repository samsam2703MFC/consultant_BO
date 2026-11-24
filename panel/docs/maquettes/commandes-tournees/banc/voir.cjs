/* Les captures du vrai dashboard (site assemblé, servi en local sur 8098) : les routes /dashboard/commandes/* vont au banc
 * (BANC, module réel, base locale, panel réel ou simulé) ; les autres lectures sont relayées à la production (GET seulement).
 *   PERIODES=1 BANC=http://127.0.0.1:8097/api/cockpit TZID=America/Denver BANC_Q=hain DSF=2 node voir.cjs <sortie> <shop> [date] [préfixe]
 * (PERIODES : aussi la semaine et le mois, Liste et Tournées.) */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const [OUT, SHOP, DATE, PRE] = [process.argv[2], process.argv[3] || '3', process.argv[4] || '', process.argv[5] || ''];
const PROD = 'http://185.180.206.46/consulant_bo/api/cockpit', BANC = process.env.BANC || 'http://127.0.0.1:8099/api/cockpit';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 940 }, deviceScaleFactor: +(process.env.DSF || 1), timezoneId: process.env.TZID || undefined, locale: 'fr-BE' });
  await ctx.addInitScript(() => { try { localStorage.setItem('dbTutoVu', '1'); } catch (e) {} });
  const p = await ctx.newPage(); const err = [];
  p.on('pageerror', e => err.push(e.message)); p.on('console', m => { if (m.type() === 'error') { err.push('console: ' + m.text()); } });
  await p.route('**/api/cockpit/**', async r => {
    const u = new URL(r.request().url()), pa = u.pathname.replace(/^.*\/api\/cockpit/, '') + u.search, m = r.request().method();
    if (pa.startsWith('/dashboard/commandes')) {
      const res = await fetch(BANC + pa, { method: m, headers: { 'Content-Type': 'application/json' }, body: m === 'GET' ? undefined : r.request().postData() });
      return r.fulfill({ status: res.status, contentType: 'application/json', body: await res.text() });
    }
    if (m !== 'GET') { return r.fulfill({ status: 403, json: { error: 'banc : lecture seule' } }); }
    try { const res = await fetch(PROD + pa, { signal: AbortSignal.timeout(30000) }); return r.fulfill({ status: res.status, contentType: res.headers.get('content-type') || 'application/json', body: await res.text() }); }
    catch (e) { return r.fulfill({ status: 504, json: { error: 'banc : ' + e.message } }); }
  });
  await p.goto(`http://127.0.0.1:8098/dashboard/?shop=${SHOP}&vue=commandes${DATE ? '&date=' + DATE : ''}`, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('.pco-barre', { timeout: 90000 });
  await p.waitForFunction(() => !document.querySelector('.pco-attente'), null, { timeout: 60000 });
  await p.waitForTimeout(1500);
  const tard = await p.$('text=Plus tard'); if (tard) { await tard.click(); await p.waitForTimeout(300); }
  const plein = async nom => { const h = await p.evaluate(() => document.documentElement.scrollHeight); await p.setViewportSize({ width: 1440, height: Math.max(940, h) }); await p.waitForTimeout(300); await p.screenshot({ path: path.join(OUT, PRE + nom + '.png') }); await p.setViewportSize({ width: 1440, height: 940 }); };
  // La semaine et le mois (24/11/2026) : Liste puis Tournées, avant de revenir au jour.
  if (process.env.PERIODES) {
    for (const per of ['semaine', 'mois']) {
      await p.click(`.pco-per [data-v="${per}"]`); await p.waitForSelector('.pco-kpis'); await p.waitForTimeout(400);
      await p.click('.pco-bascule [data-v="liste"]'); await p.waitForTimeout(300); await plein(per + '-liste');
      await p.click('.pco-bascule [data-v="tournees"]'); await p.waitForTimeout(300); await plein(per + '-tournees');
    }
    await p.click('.pco-per [data-v="jour"]'); await p.click('.pco-bascule [data-v="liste"]'); await p.waitForSelector('.pco-table'); await p.waitForTimeout(400);
  }
  await p.evaluate(() => { const b = document.querySelector('[data-act="vue"][data-v="liste"]'); if (b) { b.click(); } });
  await p.waitForTimeout(300); await plein('liste');
  await p.click('.pco-bascule [data-v="tournees"]'); await p.waitForTimeout(400); await plein('tournees');
  const c = await p.$('[data-act="cmd"]');
  if (c) { await c.click(); await p.waitForTimeout(300); await p.screenshot({ path: path.join(OUT, PRE + 'commande.png') }); await p.keyboard.press('Escape'); }
  await p.click('[data-act="gerer"]'); await p.waitForTimeout(300); await p.screenshot({ path: path.join(OUT, PRE + 'gerer.png') });
  const mod = await p.$('.pco-fen [data-act="t-modifier"]');
  if (mod) { await mod.click(); } else { await p.click('.pco-fen [data-act="t-nouvelle"]'); }
  await p.waitForTimeout(300);
  await p.fill('#pco-bq', process.env.BANC_Q || 'a'); await p.waitForTimeout(2500);
  await p.screenshot({ path: path.join(OUT, PRE + 'tournee.png') });
  console.log(err.length ? 'ERREURS\n' + err.join('\n') : 'aucune erreur de page');
  await b.close();
})();
