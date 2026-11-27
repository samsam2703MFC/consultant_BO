/* L'écran construit (27/11/2026) : Mon équipe › absences, congés et remplacements, joué de bout en bout sur le vrai
 * dashboard (site assemblé, servi en local) avec la fausse API du tournage (panel/tools/videos/mocks/20_equipe.js :
 * Magasin démo, horloge figée au jeudi 19/11/2026 à 15 h 40). Aucune donnée réelle, aucune écriture hors de la fausse API.
 *   node panel/docs/maquettes/equipe-gestion/absences-remplacements/ecran/capturer.cjs [dossier de sortie]
 * Les gestes : Camille malade du mercredi au vendredi (depuis son service, « Absente ou en congé »), Inès la remplace
 * mercredi ; Fanny en congé payé jeudi (la barre « Absence ou congé »), Lucie la remplace ; vendredi, un intérimaire
 * remplace Camille (sa case « À remplacer ») ; puis l'absence de Fanny annulée, son remplaçant retiré. Chaque étape est
 * vérifiée (ce que la fausse API a reçu, ce que l'écran montre) ; une vérification ratée arrête tout. */
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const DEPOT = path.resolve(__dirname, '../../../../../..');
const { DEMO, nouveauContexte } = require(path.join(DEPOT, 'panel/tools/videos/mock'));
const OUT = process.argv[2] || __dirname;
const PORT = +(process.env.MAQ_PORT || 8094);

async function serveur(site) {
  const php = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', site], { stdio: 'ignore' });
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch('http://127.0.0.1:' + PORT + '/dashboard/index.html'); if (r.ok) { return php; } } catch (e) { /* pas encore */ }
    await new Promise(r => setTimeout(r, 200));
  }
  php.kill(); throw new Error('le serveur local ne répond pas sur 127.0.0.1:' + PORT);
}
function verifie(nom, cond, detail) {
  console.log((cond ? '  ok  ' : '  KO  ') + nom + (cond || detail === undefined ? '' : ' — ' + detail));
  if (!cond) { throw new Error('vérification ratée : ' + nom); }
}

(async () => {
  const site = fs.mkdtempSync(path.join(os.tmpdir(), 'ecran-absences-'));
  execFileSync('php', [path.join(DEPOT, 'shared/bin/assembler_public.php'), site], { stdio: 'ignore' });
  const php = await serveur(site);
  const b = await chromium.launch({ args: ['--no-proxy-server'] });
  let echec = null;
  try {
    const { ctx } = await nouveauContexte(b, { viewport: { width: 1440, height: 900 }, dsf: 2 });
    const p = await ctx.newPage(); const err = [], ecrits = [];
    p.on('pageerror', e => err.push(e.message));
    p.on('dialog', d => d.accept());
    p.on('request', r => { if (r.url().includes('/dashboard/equipe/absence')) { ecrits.push([r.method(), r.postDataJSON()]); } });
    const shot = async (nom, plein) => { await p.waitForTimeout(400); await p.screenshot({ path: path.join(OUT, nom + '.png'), fullPage: !!plein }); console.log('capture', nom); };
    const fenetre = async nom => {   // la fenêtre entière : la hauteur de la page suit la fenêtre
      const h = await p.evaluate(() => { const f = document.querySelector('.eqp-ab-fo'); f.style.maxHeight = 'none'; return Math.ceil(f.getBoundingClientRect().height) + 60; });
      await p.setViewportSize({ width: 1440, height: Math.max(900, h) }); await p.waitForTimeout(300);
      await shot(nom, false); await p.setViewportSize({ width: 1440, height: 900 });
    };
    const date = async (sel, v) => { await p.fill(sel, v); await p.dispatchEvent(sel, 'change'); };
    const attendreTable = () => p.waitForSelector('.eqp-ab-fo .eqp-ab-t table', { timeout: 15000 });
    const enregistrer = async () => { await p.click('[data-eqp-ab-ok]'); await p.waitForSelector('.eqp-flash', { timeout: 15000 }); await p.waitForSelector('section.eqp-tab table tbody tr', { timeout: 15000 }); await p.waitForTimeout(600); return (await p.textContent('.eqp-flash')).trim(); };

    await p.goto('http://127.0.0.1:' + PORT + '/dashboard/?shop=' + DEMO.shop + '&vue=equipe');
    await p.waitForSelector('[data-eqp-onglet="planning"]', { timeout: 30000 });
    const tard = p.locator('[data-tu="tard"]'); if (await tard.count()) { await tard.first().click(); }
    await p.click('[data-eqp-onglet="planning"]');
    await p.waitForSelector('section.eqp-tab table tbody tr', { timeout: 30000 }); await p.waitForTimeout(600);
    const pct0 = (await p.textContent('.eqp-rs-pct')).trim();

    console.log('1. Camille malade du mercredi au vendredi, Inès la remplace mercredi');
    await p.click('tr:has-text("Camille Roussel") td[data-eqp-cell="2026-11-18"] [data-eqp-svc]');
    await p.waitForSelector('.eqp-fo [data-eqp-absente]');
    await p.click('.eqp-fo [data-eqp-absente]');
    await attendreTable();
    await date('[data-eqp-ab-au]', '2026-11-20'); await attendreTable();
    await p.check('[data-eqp-ab-cert]');
    verifie('la fenêtre : ses deux services de la période, mercredi et vendredi', (await p.$$('.eqp-ab-t tbody tr')).length === 2);
    const opts = await p.$$eval('[data-eqp-ab-rp="2026-11-18"] option', L => L.map(o => o.textContent));
    verifie('les remplaçants proposés : libres à ces heures (David travaille jusqu’à 13 h), du même rôle d’abord, chacun avec son coût', opts.some(o => /Inès Fontaine · \+81/.test(o)) && !opts.some(o => /David|Camille/.test(o)) && opts[1].includes('(proposé)'), opts.join(' | '));
    await p.selectOption('[data-eqp-ab-rp="2026-11-18"]', 'e:208');
    const effet = (await p.textContent('.eqp-ab-fo .eqp-fo-effs')).replace(/\s+/g, ' ');
    verifie('l’effet : ses heures restent payées, le remplacement s’ajoute', /L’absence de Camille ?201 € ?12,75 h payées sans présence/.test(effet) && /Les remplacements ?\+81 €/.test(effet), effet);
    await fenetre('ecran-1-absente-et-remplacer');
    let f = await enregistrer();
    let e = ecrits.pop();
    verifie('envoyé : maladie, certificat, du 18 au 20, Inès le 18', e[0] === 'POST' && e[1].motif === 'maladie' && e[1].certificat === true && e[1].du === '2026-11-18' && e[1].au === '2026-11-20' && JSON.stringify(e[1].remplacements) === '[{"date":"2026-11-18","employe":208}]', JSON.stringify(e));
    verifie('le message', /Camille malade du mer 18 au ven 20 : 2 services, ses heures restent payées, 1 remplacement/.test(f), f);
    const cam = (await p.textContent('tr:has-text("Camille Roussel")')).replace(/\s+/g, ' ');
    verifie('la semaine : Camille malade mercredi (Inès la remplace) et vendredi (à remplacer)', /Malade · payée→ Inès la remplace/.test(cam) && /Malade · payéeÀ remplacer/.test(cam), cam);
    verifie('Inès : un service mercredi, marqué « remplace Camille »', /remplace Camille/.test(await p.textContent('tr:has-text("Inès Fontaine") td[data-eqp-cell="2026-11-18"]')));
    const pct1 = (await p.textContent('.eqp-rs-pct')).trim();
    verifie('le budget de la semaine monte du coût du remplaçant (Camille reste payée)', pct0 !== pct1, pct0 + ' → ' + pct1);
    const corr = (await p.textContent('.eqp-rs-l2')).replace(/\s+/g, ' ');
    verifie('« À corriger » : vendredi, Camille malade à remplacer, et le comptoir vide qu’elle laisse', /Camille malade : à remplacer/.test(corr) && /comptoir vide de 13 h 45 à 14 h 45/.test(corr), corr);

    console.log('2. Fanny en congé payé jeudi (la barre), Lucie la remplace');
    await p.click('[data-eqp-abs-nouv]');
    await p.waitForSelector('[data-eqp-ab-emp]');
    await p.selectOption('[data-eqp-ab-emp]', '206');
    await attendreTable();
    verifie('le motif par défaut depuis la barre : congé payé', await p.$eval('[data-eqp-ab-motif="cp"]', n => n.classList.contains('on')));
    await p.selectOption('[data-eqp-ab-rp="2026-11-19"]', 'e:210');
    await fenetre('ecran-2-conge-paye');
    f = await enregistrer(); e = ecrits.pop();
    verifie('envoyé : congé payé de Fanny jeudi, Lucie', e[1].employe === 206 && e[1].motif === 'cp' && e[1].du === '2026-11-19' && JSON.stringify(e[1].remplacements) === '[{"date":"2026-11-19","employe":210}]', JSON.stringify(e));

    console.log('3. Vendredi, un intérimaire remplace Camille');
    await p.click('tr:has-text("Camille Roussel") td[data-eqp-cell="2026-11-20"] [data-eqp-abs]');
    await attendreTable();
    verifie('l’absence posée se rouvre : mercredi déjà remplacé par Inès', /Inès Fontaine · au planning/.test(await p.textContent('.eqp-ab-t tbody tr:first-child')));
    await p.selectOption('[data-eqp-ab-rp="2026-11-20"]', 'x');
    await p.fill('[data-eqp-ab-xtaux]', '24.50'); await p.dispatchEvent('[data-eqp-ab-xtaux]', 'change');
    await p.waitForTimeout(300);
    const rx = (await p.textContent('.eqp-ab-t tbody tr:nth-child(2)')).replace(/\s+/g, ' ');
    verifie('l’extérieur : son taux donne son coût', /\+153 €/.test(rx), rx);
    await fenetre('ecran-3-remplacer-par-un-exterieur');
    f = await enregistrer(); e = ecrits.pop();
    verifie('envoyé : un extérieur vendredi à 24,50 €/h', JSON.stringify(e[1].remplacements) === '[{"date":"2026-11-20","externe":{"nom":"Intérim","taux":24.5}}]', JSON.stringify(e));
    verifie('la ligne des extérieurs : l’intérim vendredi', /Intérim/.test(await p.textContent('tr.eqp-ext-l td[class*="eqp-c"]:nth-child(6)')));
    const bloc = (await p.textContent('.eqp-rs-abs')).replace(/\s+/g, ' ');
    verifie('le résumé : 309 € d’absences payées, chacune et ses remplacements', /309 € ?payés sans présence/.test(bloc) && /ven 20 : Intérim/.test(bloc) && /jeu 19 : Lucie/.test(bloc), bloc);
    await p.mouse.move(2, 2); await p.evaluate(() => window.scrollTo(0, 0));
    await shot('ecran-4-semaine', true);

    console.log('4. Le jour et le mois');
    await p.click('[data-eqp-jour="2026-11-20"]');
    await p.waitForSelector('.eqp-pg', { timeout: 15000 }); await p.waitForTimeout(500);
    verifie('le jour : 6,25 h d’absence payée, au quart d’heure', /6,25 h d’absence payée/.test(await p.textContent('.eqp-rs-meta')));
    await p.evaluate(() => window.scrollTo(0, 0));
    verifie('le jour : Camille hachurée, l’intérim dans les extérieurs', !!(await p.$('.eqp-absbar[data-eqp-abs]')) && /Extérieurs · 1/.test(await p.textContent('.eqp-pg')));
    await shot('ecran-5-jour', true);
    await p.click('[data-eqp-vue="mois"]');
    await p.waitForSelector('.eqp-moi table tbody tr', { timeout: 15000 }); await p.waitForTimeout(500);
    verifie('le mois : les absences en lettres (M, CP)', (await p.$$eval('.eqp-ms.abs', L => L.map(n => n.textContent))).join(',') === 'M,M,CP');
    await shot('ecran-6-mois', false);

    console.log('5. Fanny travaille finalement : l’absence est annulée, Lucie retirée');
    await p.click('[data-eqp-vue="tableau"]');
    await p.waitForSelector('section.eqp-tab table tbody tr', { timeout: 15000 });
    await p.click('tr:has-text("Fanny Leroy") [data-eqp-abs]');
    await attendreTable();
    await p.click('[data-eqp-ab-annuler]');
    await p.waitForSelector('.eqp-flash', { timeout: 15000 }); await p.waitForTimeout(600);
    e = ecrits.pop();
    verifie('envoyé : DELETE de son absence, avec son remplaçant', e[0] === 'DELETE' && e[1].ids.length === 1 && e[1].remplacants === true, JSON.stringify(e));
    verifie('Fanny de nouveau présente jeudi, Lucie retirée', !(await p.$('tr:has-text("Fanny Leroy") [data-eqp-abs]')) && /—/.test(await p.textContent('tr:has-text("Lucie Bernard") td[data-eqp-cell="2026-11-19"]')));
    verifie('sans erreur de page', err.length === 0, err.join(' | '));
  } catch (e) { echec = e; console.log(e.message); }
  finally { await b.close(); php.kill(); fs.rmSync(site, { recursive: true, force: true }); }
  if (echec) { process.exit(1); }
})();
