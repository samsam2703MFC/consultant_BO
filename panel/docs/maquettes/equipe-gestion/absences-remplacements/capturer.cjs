/* Absences, congés et remplacements dans Mon équipe › Planning (maquette du 27/11/2026).
 * Le vrai dashboard (site assemblé, servi en local) avec la fausse API du tournage (panel/tools/videos/mock.js :
 * « Magasin démo », dix prénoms inventés, horloge figée au jeudi 19/11/2026 à 15 h 40) ; la maquette (absences.css,
 * absences.js) se pose par-dessus. Les remplaçants sont créés par la fausse API (POST /dashboard/equipe/service, en
 * mémoire) : le vrai écran compte leur coût. Aucune donnée réelle, aucune écriture hors de la fausse API.
 *   node panel/docs/maquettes/equipe-gestion/absences-remplacements/capturer.cjs [dossier de sortie]
 * Sortie : 1-semaine-absences.png, 2-absente-et-remplacer.png, 3-conge-paye.png. */
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const DEPOT = path.resolve(__dirname, '../../../../..');
const { DEMO, nouveauContexte } = require(path.join(DEPOT, 'panel/tools/videos/mock'));
const OUT = process.argv[2] || __dirname;
const PORT = +(process.env.MAQ_PORT || 8095);
const CSS = fs.readFileSync(path.join(__dirname, 'absences.css'), 'utf8'), JS = fs.readFileSync(path.join(__dirname, 'absences.js'), 'utf8');

// Le scénario. Camille Roussel (vendeuse, contrat de travail) est malade du mercredi 18 au vendredi 20 (certificat) :
// Inès la remplace mercredi, vendredi reste à remplacer. Fanny Leroy (responsable) est en congé payé jeudi 19 : Lucie
// la remplace. Les remplaçants sont de vrais services de la fausse API, aux mêmes heures que l'absente.
const SC = {
  semaine: '2026-11-16', interim: 24.5, choix: 210,
  absences: [{ emp: 201, du: '2026-11-18', au: '2026-11-20', motif: 'maladie', certificat: true }, { emp: 206, du: '2026-11-19', au: '2026-11-19', motif: 'cp' }],
  remplacements: [{ emp: 208, date: '2026-11-18', remplace: 201, de: '12:45', a: '19:15' }, { emp: 210, date: '2026-11-19', remplace: 206, de: '06:45', a: '13:00' }],
};

async function serveur(site) {
  const php = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', site], { stdio: 'ignore' });
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch('http://127.0.0.1:' + PORT + '/dashboard/index.html'); if (r.ok) { return php; } } catch (e) { /* pas encore */ }
    await new Promise(r => setTimeout(r, 200));
  }
  php.kill(); throw new Error('le serveur local ne répond pas sur 127.0.0.1:' + PORT);
}

(async () => {
  const site = fs.mkdtempSync(path.join(os.tmpdir(), 'maq-absences-'));
  execFileSync('php', [path.join(DEPOT, 'shared/bin/assembler_public.php'), site], { stdio: 'ignore' });
  const php = await serveur(site);
  const b = await chromium.launch({ args: ['--no-proxy-server'] });
  try {
    const { ctx, nonServis } = await nouveauContexte(b, { viewport: { width: 1440, height: 900 }, dsf: 2 });
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message));
    const URL0 = 'http://127.0.0.1:' + PORT + '/dashboard/?shop=' + DEMO.shop + '&vue=equipe';
    await p.goto(URL0);
    await p.waitForSelector('[data-eqp-onglet="planning"]', { timeout: 30000 });
    // Les remplaçants, dans la fausse API (l'état reste d'un chargement à l'autre), puis l'écran relu.
    const crees = await p.evaluate(async L => Promise.all(L.map(r => fetch(location.origin + '/api/cockpit/dashboard/equipe/service', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ shop: 4, employe: r.emp, date: r.date, de: r.de, a: r.a }) }).then(x => x.json()))), SC.remplacements);
    console.log('remplaçants créés', JSON.stringify(crees));
    await p.reload();
    await p.waitForSelector('[data-eqp-onglet="planning"]', { timeout: 30000 });
    const tard = p.locator('[data-tu="tard"]'); if (await tard.count()) { await tard.first().click(); }
    await p.click('[data-eqp-onglet="planning"]');
    await p.waitForSelector('section.eqp-tab table tbody tr', { timeout: 30000 });
    await p.waitForTimeout(800);
    await p.addStyleTag({ content: CSS });
    await p.evaluate(sc => { window.MAQ_SC = sc; }, SC);
    await p.addScriptTag({ content: JS });
    console.log('semaine', JSON.stringify(await p.evaluate(() => window.MAQ.semaine())));
    console.log('largeur de la page', await p.evaluate(() => document.documentElement.scrollWidth), '(fenêtre 1440)');
    await p.mouse.move(2, 2); await p.waitForTimeout(400);
    await p.screenshot({ path: path.join(OUT, '1-semaine-absences.png'), fullPage: true });

    const pleine = async (nom) => {
      await p.evaluate(() => window.scrollTo(0, 0));
      const h = await p.evaluate(() => Math.ceil(document.querySelector('.maq-fo').getBoundingClientRect().height) + 56);
      await p.setViewportSize({ width: 1440, height: Math.max(900, h) }); await p.waitForTimeout(400);
      await p.screenshot({ path: path.join(OUT, nom + '.png') });
      await p.setViewportSize({ width: 1440, height: 900 });
    };
    console.log('absence', JSON.stringify(await p.evaluate(() => window.MAQ.absence(201, '2026-11-20'))));
    await pleine('2-absente-et-remplacer');
    console.log('congé', JSON.stringify(await p.evaluate(() => window.MAQ.conge(206, '2026-11-23', '2026-11-29'))));
    await pleine('3-conge-paye');
    console.log(err.length ? 'ERREURS ' + err.join(' | ') : 'sans erreur de page', nonServis.size ? '· non servis : ' + [...nonServis].join(', ') : '');
    await ctx.close();
  } finally { await b.close(); php.kill(); fs.rmSync(site, { recursive: true, force: true }); }
})();
