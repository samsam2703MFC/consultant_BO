/* Avant / après de Mon équipe › Planning › Semaine (maquette du 26/11/2026).
 * Le vrai dashboard (site assemblé, servi en local) avec la fausse API du tournage (panel/tools/videos/mock.js :
 * « Magasin démo », dix prénoms inventés, horloge figée au jeudi 19/11/2026 à 15 h 40) ; l'après pose apres.css et
 * apres.js par-dessus. Aucune donnée réelle, aucune écriture.
 *   node panel/docs/maquettes/equipe-gestion/semaine-avant-apres/capturer.cjs [dossier de sortie]
 * Sortie : avant.png, apres.png (l'écran à l'ouverture, 1440 × 900), apres-defile.png (la tête du tableau reste
 * collée quand on descend dans l'équipe), apres-clic-a-corriger.png (le premier « À corriger » ouvre le jour),
 * apres-tablette.png (1180 × 820), avant-plein.png, apres-plein.png (la page
 * entière). */
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const DEPOT = path.resolve(__dirname, '../../../../..');
const { DEMO, nouveauContexte } = require(path.join(DEPOT, 'panel/tools/videos/mock'));
const OUT = process.argv[2] || __dirname;
const PORT = +(process.env.MAQ_PORT || 8097);
const CSS = fs.readFileSync(path.join(__dirname, 'apres.css'), 'utf8'), JS = fs.readFileSync(path.join(__dirname, 'apres.js'), 'utf8');

async function serveur(site) {
  const php = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', site], { stdio: 'ignore' });
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch('http://127.0.0.1:' + PORT + '/dashboard/index.html'); if (r.ok) { return php; } } catch (e) { /* pas encore */ }
    await new Promise(r => setTimeout(r, 200));
  }
  php.kill(); throw new Error('le serveur local ne répond pas sur 127.0.0.1:' + PORT);
}

async function ouvrir(b, apres, w, h) {
  const { ctx, nonServis } = await nouveauContexte(b, { viewport: { width: w || 1440, height: h || 900 }, dsf: 2 });
  const p = await ctx.newPage(); const err = [];
  p.on('pageerror', e => err.push(e.message));
  await p.goto('http://127.0.0.1:' + PORT + '/dashboard/?shop=' + DEMO.shop + '&vue=equipe');
  await p.waitForSelector('[data-eqp-onglet="planning"]', { timeout: 30000 });
  // L'annonce de la vidéo (« Nouveau ») se range pour la journée, des deux côtés : elle n'est pas le sujet.
  const tard = p.locator('[data-tu="tard"]'); if (await tard.count()) { await tard.first().click(); }
  await p.click('[data-eqp-onglet="planning"]');
  await p.waitForSelector('section.eqp-tab table tbody tr', { timeout: 30000 });
  await p.waitForTimeout(800);
  if (apres) {
    await p.addStyleTag({ content: CSS }); await p.addScriptTag({ content: JS });
    await p.waitForFunction(() => document.body.dataset.maqApres === 'ok', null, { timeout: 10000 });
  }
  await p.mouse.move(2, 2);
  await p.waitForTimeout(400);
  return { ctx, p, err, nonServis };
}

(async () => {
  const site = fs.mkdtempSync(path.join(os.tmpdir(), 'maq-semaine-'));
  execFileSync('php', [path.join(DEPOT, 'shared/bin/assembler_public.php'), site], { stdio: 'ignore' });
  const php = await serveur(site);
  const b = await chromium.launch({ args: ['--no-proxy-server'] });
  try {
    for (const apres of [false, true]) {
      const nom = apres ? 'apres' : 'avant';
      const o = await ouvrir(b, apres);
      await o.p.screenshot({ path: path.join(OUT, nom + '.png') });
      await o.p.screenshot({ path: path.join(OUT, nom + '-plein.png'), fullPage: true });
      if (apres) {
        // Descendre dans l'équipe : la rangée des jours, de leur budget et des linéaires reste en haut.
        await o.p.evaluate(() => { const t = document.querySelector('section.eqp-tab tbody tr:nth-child(7)'); if (t) { window.scrollTo(0, t.getBoundingClientRect().top + window.scrollY - 330); } });
        await o.p.waitForTimeout(400);
        await o.p.screenshot({ path: path.join(OUT, 'apres-defile.png') });
        // « À corriger » mène au geste : le premier bouton (mardi) ouvre le jour en grand, où l'on tire le service.
        await o.p.evaluate(() => window.scrollTo(0, 0));
        await o.p.click('[data-ap-jour]');
        await o.p.waitForTimeout(1500);
        await o.p.screenshot({ path: path.join(OUT, 'apres-clic-a-corriger.png') });
      }
      if (apres) {
        // Une tablette posée en paysage (1180 × 820) : le résumé passe sur plusieurs lignes, le tableau défile de côté.
        const t = await ouvrir(b, true, 1180, 820);
        await t.p.screenshot({ path: path.join(OUT, 'apres-tablette.png') });
        await t.ctx.close();
      }
      console.log(nom, o.err.length ? 'ERREURS ' + o.err.join(' | ') : 'sans erreur de page', o.nonServis.size ? '· non servis : ' + [...o.nonServis].join(', ') : '');
      await o.ctx.close();
    }
  } finally { await b.close(); php.kill(); fs.rmSync(site, { recursive: true, force: true }); }
})();
