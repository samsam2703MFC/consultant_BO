/* Avant / après du dashboard magasin au design system (maquette du 28/11/2026).
 * Avant : le vrai dashboard (site assemblé, servi en local) avec la fausse API du tournage
 * (panel/tools/videos/mock.js : « Magasin démo », prénoms inventés, horloge figée au jeudi 19/11/2026 à 15 h 40),
 * vue Opérationnel. Après : index.html de ce dossier (la feuille commune global.css + dashboard-ds.css).
 * Aucune donnée réelle, aucune écriture.
 *   node panel/docs/maquettes/design-system-dashboard/capturer.cjs [dossier de sortie]
 * Sortie : avant.png, apres.png (l'écran à l'ouverture, 1440 × 900), avant-plein.png, apres-plein.png (la page entière). */
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const DEPOT = path.resolve(__dirname, '../../../..');
const { DEMO, nouveauContexte } = require(path.join(DEPOT, 'panel/tools/videos/mock'));
const OUT = process.argv[2] || __dirname;
const PORT = +(process.env.MAQ_PORT || 8098);

async function serveur(site) {
  const php = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', site], { stdio: 'ignore' });
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch('http://127.0.0.1:' + PORT + '/dashboard/index.html'); if (r.ok) { return php; } } catch (e) { /* pas encore */ }
    await new Promise(r => setTimeout(r, 200));
  }
  php.kill(); throw new Error('le serveur local ne répond pas sur 127.0.0.1:' + PORT);
}

(async () => {
  const site = fs.mkdtempSync(path.join(os.tmpdir(), 'maq-ds-dash-'));
  execFileSync('php', [path.join(DEPOT, 'shared/bin/assembler_public.php'), site], { stdio: 'ignore' });
  const php = await serveur(site);
  const b = await chromium.launch({ args: ['--no-proxy-server'] });
  try {
    // Avant : le dashboard tel qu'il est servi, vue Opérationnel.
    const { ctx, nonServis } = await nouveauContexte(b, { viewport: { width: 1440, height: 900 }, dsf: 2 });
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:' + PORT + '/dashboard/?shop=' + DEMO.shop + '&vue=ops');
    await p.waitForSelector('.sidebar__item.is-active', { timeout: 30000 });
    await p.waitForTimeout(2500);
    const tard = p.locator('[data-tu="tard"]'); if (await tard.count()) { await tard.first().click(); }
    await p.mouse.move(2, 2); await p.waitForTimeout(400);
    await p.screenshot({ path: path.join(OUT, 'avant.png') });
    await p.screenshot({ path: path.join(OUT, 'avant-plein.png'), fullPage: true });
    console.log('avant', err.length ? 'ERREURS ' + err.join(' | ') : 'sans erreur de page', nonServis.size ? '· non servis : ' + [...nonServis].join(', ') : '');
    await ctx.close();

    // Après : la maquette, servie depuis le dépôt (la feuille commune est lue à sa place).
    const c2 = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    const q = await c2.newPage(); const err2 = [];
    q.on('pageerror', e => err2.push(e.message));
    const url = 'file://' + path.join(__dirname, 'index.html');
    await q.goto(url); await q.evaluate(() => document.fonts.ready); await q.waitForTimeout(300);
    await q.screenshot({ path: path.join(OUT, 'apres.png') });
    await q.goto(url + '#plein'); await q.reload(); await q.evaluate(() => document.fonts.ready); await q.waitForTimeout(300);
    await q.screenshot({ path: path.join(OUT, 'apres-plein.png'), fullPage: true });
    console.log('après', err2.length ? 'ERREURS ' + err2.join(' | ') : 'sans erreur de page');
    await c2.close();
  } finally { await b.close(); php.kill(); fs.rmSync(site, { recursive: true, force: true }); }
})();
