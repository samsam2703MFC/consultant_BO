const { chromium } = require('playwright'); const fs = require('fs'), path = require('path');
// Les données : node capturer.cjs <sortie> lit carte.json, jour.json et semaine.json, rendus par « php panel_illustratif.php » (le vrai code de panel/src/equipe.php sur un panel de doublure).
const D = __dirname, OUT = process.argv[2] || D, PROD = 'http://185.180.206.46/consulant_bo/api/cockpit';
const lire = f => fs.readFileSync(path.join(D, f), 'utf8');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 940 }, deviceScaleFactor: 2, locale: 'fr-BE' });
  const p = await ctx.newPage(); const err = []; const ecrits = [];
  const OPTS = { assistant: true, taches: true, matrice: true, recopie: true };   // les options du magasin (la doublure)
  // Les options et leur description, telles que les rend panel/src/equipe.php (EQP_OPTIONS).
  const LISTE = [['assistant', 'Assistant de la semaine', 'Reprendre la semaine passée ou la matrice, qui adapter, les heures de trop et des propositions, puis créer la semaine au panel.'],
    ['taches', 'Tâches dans les temps morts', 'Les checklists sans heure fixe posées dans les creux habituels du comptoir, à quelqu’un du comptoir présent : une étape de l’assistant et une ligne au jour.'],
    ['matrice', 'Matrice A B C D et shift maker', 'Quatre roulements sur deux semaines, la lettre de chacun dans sa fiche, les créneaux matin et après-midi, et le shift maker (test).'],
    ['recopie', 'Recopier la semaine précédente', 'Dans « Préparer la semaine » : la semaine d’avant reportée de sept jours, aperçu puis création.']];
  const optionsJson = () => ({ shop: 5, options: OPTS, liste: LISTE.map(([cle, nom, dit]) => ({ cle, nom, dit, on: OPTS[cle] })) });
  p.on('pageerror', e => err.push(e.message));
  p.on('dialog', d => d.accept());   // les confirmations (retirer quelqu'un du shift) p.on('console', m => { if (m.type() === 'error') err.push('console: ' + m.text()); });
  await p.route('**/api/cockpit/**', async r => {
    const u = new URL(r.request().url()), pa = u.pathname.replace(/^.*\/api\/cockpit/, ''), m = r.request().method();
    if (pa.startsWith('/dashboard/equipe')) {
      if (m !== 'GET') {
        ecrits.push([m, pa, r.request().postData()]);
        if (pa === '/dashboard/equipe/semaine/recopie') { const n = JSON.parse(r.request().postData()).sources.length; return r.fulfill({ status: 200, json: { ok: true, crees: n, demandes: n, echecs: [], arret: null } }); }
        if (pa === '/dashboard/equipe/semaine/generer') { const n = JSON.parse(r.request().postData()).cles.length; return r.fulfill({ status: 200, json: { ok: true, crees: n, demandes: n, echecs: [], arret: null } }); }
        if (pa === '/dashboard/equipe/options') { Object.assign(OPTS, JSON.parse(r.request().postData()).options); return r.fulfill({ status: 200, json: { ok: true, options: OPTS } }); }
        if (pa === '/dashboard/equipe/semaine/taches') { const n = JSON.parse(r.request().postData()).places.length; return r.fulfill({ status: 200, json: { ok: true, places: n } }); }
        if (pa === '/dashboard/equipe/semaine/retirer') { return r.fulfill({ status: 200, json: { ok: true, retires: 4, demandes: 4, echecs: [], arret: null } }); }
        if (pa === '/dashboard/equipe/semaine/creer') { const n = JSON.parse(r.request().postData()).services.length; return r.fulfill({ status: 200, json: { ok: true, crees: n, demandes: n, echecs: [], arret: null } }); }
        if (pa === '/dashboard/equipe/matrice') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('matrice.json') }); }
        if (pa === '/dashboard/equipe/roulement') { const b = JSON.parse(r.request().postData()), x = JSON.parse(lire('matrice.json')).roulements; if (b.lettre) { x[b.employe] = b.lettre; } else { delete x[b.employe]; } return r.fulfill({ status: 200, json: { ok: true, employe: b.employe, lettre: b.lettre, roulements: x } }); }
        return r.fulfill({ status: 200, json: pa === '/dashboard/equipe/postes' ? Object.assign(JSON.parse(lire('carte.json')), { ok: true }) : { ok: true, id: 999 } });
      }
      if (pa === '/dashboard/equipe/semaine/recopie') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('recopie.json') }); }
      if (pa === '/dashboard/equipe/semaine/generer') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('generer.json') }); }
      if (pa === '/dashboard/equipe/matrice') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('matrice.json') }); }
      if (pa === '/dashboard/equipe/options') { return r.fulfill({ status: 200, json: optionsJson() }); }
      if (pa === '/dashboard/equipe/semaine/taches') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('taches.json') }); }
      if (pa === '/dashboard/equipe/carte') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('carte.json') }); }
      if (pa === '/dashboard/equipe/planning') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire(u.searchParams.get('jours') === '1' ? 'jour.json' : +u.searchParams.get('jours') >= 28 ? 'mois.json' : u.searchParams.get('du') === '2026-11-30' ? 'semaine2.json' : 'semaine.json') }); }
      return r.fulfill({ status: 404, json: { error: 'x' } });
    }
    if (m !== 'GET') { return r.fulfill({ status: 403, json: { error: 'lecture seule' } }); }
    try { const res = await fetch(PROD + pa + u.search, { signal: AbortSignal.timeout(30000) }); return r.fulfill({ status: res.status, contentType: res.headers.get('content-type') || 'application/json', body: await res.text() }); }
    catch (e) { return r.fulfill({ status: 504, json: { error: e.message } }); }
  });
  const shot = async (nom, plein) => { await p.waitForTimeout(500); await p.screenshot({ path: path.join(OUT, nom + '.png'), fullPage: !!plein }); console.log('capture', nom); };
  await p.goto('http://127.0.0.1:8098/dashboard/?shop=5&vue=equipe&date=2026-11-23', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('.eqp .eqp-grille', { timeout: 90000 }); await p.waitForTimeout(6000);
  await p.evaluate(() => { const r = document.getElementById('db-rail'); if (r) { r.scrollTop = 0; } });
  await shot('e1-responsabilites', true);
  await p.click('[data-eqp-options]'); await p.waitForSelector('.eqp-opts');
  console.log('options', (await p.$$eval('.eqp-opt-l b', L => L.map(n => n.textContent))).join(' | '), '| allumées :', await p.$$eval('.eqp-switch.on', L => L.length));
  await p.click('[data-eqp-opt="matrice"]'); await p.waitForTimeout(800);
  console.log('matrice éteinte :', OPTS.matrice === false, '| interrupteur :', await p.$eval('[data-eqp-opt="matrice"]', n => n.getAttribute('aria-checked')));
  await shot('e23-options', false);
  await p.click('[data-eqp-options-fermer]'); await p.waitForTimeout(300);
  console.log('fiche sans roulement :', !(await p.$('.eqp-fiche .eqp-seg-l')));
  await p.click('[data-eqp-options]'); await p.click('[data-eqp-opt="matrice"]'); await p.waitForTimeout(800); await p.click('[data-eqp-options-fermer]');
  console.log('matrice rallumée :', OPTS.matrice === true);
  // La fiche d'une autre personne, puis la fenêtre des postes : cocher Traiteur.
  await p.click('.eqp-ligne:nth-child(2)');
  await p.click('.eqp-fiche [data-eqp-postes]');
  await p.click('.eqp-opt:has-text("Traiteur") input');
  await shot('e2-affectations', false);
  await p.click('[data-eqp-enregistrer-postes]'); await p.waitForTimeout(400);
  console.log('flash', await p.textContent('.eqp-flash').catch(() => '—'));
  // Le planning, jour.
  await p.click('[data-eqp-onglet="planning"]');
  // La semaine en tableau (vue par défaut) : un clic sur un service, ajouter quelqu'un au shift, retirer quelqu'un,
  // puis un clic sur une case vide.
  await p.waitForSelector('.eqp-tab', { timeout: 20000 });
  await shot('e11-semaine-tableau', true);
  await p.click('tr:has-text("Laura Claes") td[data-eqp-cell="2026-11-23"] [data-eqp-svc] >> nth=0');
  await p.waitForSelector('.eqp-fo');
  await p.selectOption('[data-eqp-ajout-sel]', '26');
  await shot('e12-clic-sur-un-service', false);
  await p.click('[data-eqp-ajout-ok]'); await p.waitForTimeout(900);
  console.log('ajout', await p.textContent('.eqp-flash').catch(() => '—'), '| fenêtre rouverte :', !!(await p.$('.eqp-fo')));
  await p.click('.eqp-shift-l [data-eqp-retirer] >> nth=0'); await p.waitForTimeout(900);
  console.log('retrait', await p.textContent('.eqp-flash').catch(() => '—'), '| fenêtre rouverte :', !!(await p.$('.eqp-fo')));
  await p.click('.eqp-fo [data-eqp-annuler]');
  await p.click('tr:has-text("Jonas Willems") td[data-eqp-cell="2026-11-24"]');
  await p.waitForSelector('.eqp-fo');
  await shot('e13-clic-sur-une-case-vide', false);
  // Dans la fenêtre, tirer sa ligne : de 09:00 à 15:00 (verte, 6 h), puis le bord de fin jusqu'à 18:00 (rouge, plus de 8 h).
  const trf = await p.$('.eqp-fo [data-eqp-trace]'); await trf.scrollIntoViewIfNeeded(); const tbf = await trf.boundingBox();
  const f0 = +(await trf.getAttribute('data-h0')), f1 = +(await trf.getAttribute('data-h1')), xf = m => tbf.x + (m - f0) / (f1 - f0) * tbf.width;
  await p.mouse.move(xf(9 * 60) + 1, tbf.y + 12); await p.mouse.down(); await p.mouse.move(xf(15 * 60) + 1, tbf.y + 12, { steps: 10 }); await p.mouse.up();
  await p.waitForTimeout(300);
  console.log('ligne verte', await p.textContent('.eqp-fo-dur'), '|', (await p.textContent('.eqp-fo-res')).replace(/\s+/g, ' '), '| rouge ?', await p.$eval('.eqp-fo-trait', n => n.classList.contains('rouge')));
  await shot('e16-fenetre-ligne-verte', false);
  const tbf2 = await (await p.$('.eqp-fo [data-eqp-trace]')).boundingBox();
  await p.mouse.move(xf(15 * 60), tbf2.y + 36); await p.mouse.down(); await p.mouse.move(xf(18 * 60), tbf2.y + 36, { steps: 10 });
  console.log('pendant le geste', await p.textContent('.eqp-fo-dur'));
  await p.mouse.up(); await p.waitForTimeout(300);
  console.log('ligne rouge', await p.textContent('.eqp-fo-dur'), '|', (await p.textContent('.eqp-fo-res')).replace(/\s+/g, ' '), '| rouge ?', await p.$eval('.eqp-fo-trait', n => n.classList.contains('rouge')));
  await shot('e17-fenetre-ligne-rouge', false);
  await p.click('.eqp-fo [data-eqp-ok]'); await p.waitForTimeout(900);
  console.log('nouveau', await p.textContent('.eqp-flash').catch(() => '—'));
  // Retirer quelqu'un de l'horaire de la semaine : Marc, depuis la fenêtre de son service du mardi.
  await p.click('tr:has-text("Marc Lefèvre") td[data-eqp-cell="2026-11-24"] [data-eqp-svc]'); await p.waitForSelector('.eqp-fo [data-eqp-retirer-semaine]');
  await shot('e25-retirer-de-l-horaire', false);
  await p.click('.eqp-fo [data-eqp-retirer-semaine]'); await p.waitForTimeout(900);
  console.log('retirer de l’horaire', await p.textContent('.eqp-flash').catch(() => '—'), '| fenêtre fermée :', !(await p.$('.eqp-fo')));
  await p.click('[data-eqp-vue="jour"]');
  await p.waitForSelector('.eqp-pg', { timeout: 20000 });
  console.log('ligne des tâches', (await p.textContent('.eqp-tcl').catch(() => 'aucune')).replace(/\s+/g, ' '));
  await shot('e3-planning-jour', true);
  // Tracer : sur la ligne d'une personne au repos ce jour, tirer une ligne de 14:00 à 18:00 crée un service.
  const libre = await p.$('.eqp-l.repos:not(.eqp-axe) .eqp-tl');
  await libre.scrollIntoViewIfNeeded(); await p.evaluate(() => window.scrollBy(0, 200));
  const tb = await libre.boundingBox();
  const h0 = await p.evaluate(() => +document.querySelector('.eqp-axe .eqp-t').textContent * 60);
  const pm = tb.width / (await p.evaluate(() => { const t = [...document.querySelectorAll('.eqp-axe .eqp-t')]; return (t.length - 1) * 60; }));
  const xDe = tb.x + (14 * 60 - h0) * pm + 1, xA = tb.x + (18 * 60 - h0) * pm + 1, yL = tb.y + tb.height / 2;
  await p.mouse.move(xDe, yL); await p.mouse.down();
  await p.mouse.move((xDe + xA) / 2, yL, { steps: 5 });
  console.log('trace', await p.textContent('.eqp-bar.trace').catch(() => '—'));
  await shot('e14-tracer-une-ligne', true);
  await p.mouse.move(xA, yL, { steps: 5 }); await p.mouse.up();
  await p.waitForSelector('.eqp-fo');
  console.log('pop tracé', (await p.textContent('.eqp-fo')).replace(/\s+/g, ' ').slice(0, 220));
  await shot('e15-ligne-tracee', true);
  await p.click('.eqp-fo [data-eqp-ok]'); await p.waitForTimeout(900);
  console.log('tracé enregistré', await p.textContent('.eqp-flash').catch(() => '—'), '· pop ouverte ?', !!(await p.$('.eqp-fo')));
  // Modifier : étirer la fin du premier service de Laura jusqu'à 12:30.
  const bar = await p.$('.eqp-l:has-text("Laura Claes") [data-eqp-bar]');
  await bar.scrollIntoViewIfNeeded(); await p.evaluate(() => window.scrollBy(0, 200));
  const bb = await bar.boundingBox(); const tl = await (await p.$('.eqp-l:has-text("Laura Claes") .eqp-tl')).boundingBox();
  const parMin = tl.width / (await p.evaluate(() => { const t = [...document.querySelectorAll('.eqp-axe .eqp-t')]; return (t.length - 1) * 60; }));
  await p.mouse.move(bb.x + bb.width - 2, bb.y + bb.height / 2); await p.mouse.down();
  await p.mouse.move(bb.x + bb.width - 2 + 90 * parMin, bb.y + bb.height / 2, { steps: 8 }); await p.mouse.up();
  await p.waitForSelector('.eqp-fo');
  console.log('pop', (await p.textContent('.eqp-fo')).replace(/\s+/g, ' ').slice(0, 260));
  await shot('e4-modifier-un-service', true);
  await p.click('[data-eqp-ok]'); await p.waitForTimeout(500);
  // Le mois, puis la semaine (le tableau et le planogramme fondus).
  await p.click('[data-eqp-vue="mois"]'); await p.waitForSelector('.eqp-moi');
  await shot('e5-planning-mois', true);
  await p.click('.eqp-moi tr:has-text("Laura Claes") td[data-eqp-cell="2026-11-25"] [data-eqp-svc]'); await p.waitForSelector('.eqp-fo');
  console.log('mois, service', (await p.textContent('.eqp-fo')).replace(/\s+/g, ' ').slice(0, 120));
  await p.click('.eqp-fo [data-eqp-annuler]');
  await p.click('.eqp-moi [data-eqp-semaine="2026-11-23"]'); await p.waitForSelector('.eqp-tab:not(.eqp-moi)');
  // Parcourir le jour dans la fenêtre : Chloé le mercredi, la section Comptoir, « Ajouter et suivant » (Laura), puis la
  // section Boulangerie (la fenêtre passe à sa première personne).
  await p.click('tr:has-text("Chloé Peeters") td[data-eqp-cell="2026-11-25"]'); await p.waitForSelector('.eqp-fo');
  await p.click('.eqp-fo [data-eqp-fo-section="Comptoir"]'); await p.waitForTimeout(200);
  console.log('section', (await p.textContent('.eqp-fo-pl')).replace(/\s+/g, ' '), '| suivant :', (await p.textContent('.eqp-fo [data-eqp-ok-suivant]')).trim());
  await shot('e18-parcourir-le-jour', false);
  await p.click('.eqp-fo [data-eqp-ok-suivant]'); await p.waitForTimeout(1200);
  console.log('après « et suivant »', await p.textContent('.eqp-fo-tete b').catch(() => 'pas de fenêtre'), '|', (await p.textContent('.eqp-fo-pos').catch(() => '')).trim());
  await p.click('.eqp-fo [data-eqp-fo-section="Boulangerie"]'); await p.waitForTimeout(300);
  console.log('section Boulangerie', await p.textContent('.eqp-fo-tete b'), '|', (await p.textContent('.eqp-fo-pl')).replace(/\s+/g, ' '));
  await p.click('.eqp-fo-pers [aria-label="Personne suivante"]'); await p.waitForTimeout(300);
  console.log('personne suivante', await p.textContent('.eqp-fo-tete b'));
  await p.click('.eqp-fo-x');
  await p.click('[data-eqp-pdf]');
  await shot('e6-tableau-pdf', true);
  // L'assistant de la semaine, sur la semaine suivante (vide) : reprendre la semaine passée ; Jonas absent, Chloé à
  // revoir ; le chiffre et les propositions ; vérifier ; créer (la fenêtre de Chloé s'ouvre ensuite).
  await p.click('[data-eqp-pas="7"]'); await p.waitForSelector('.eqp-vide7');
  await p.click('.eqp-vide7 [data-eqp-assistant]'); await p.waitForSelector('.eqp-as-c', { timeout: 20000 });
  console.log('assistant 1', (await p.textContent('.eqp-as-choix')).replace(/\s+/g, ' ').slice(0, 200));
  await shot('e19-assistant-1', false);
  await p.click('[data-eqp-as-suite]'); await p.waitForSelector('.eqp-as-pers');
  await p.click('[data-eqp-as-etat="a"][data-emp="27"]'); await p.click('[data-eqp-as-etat="r"][data-emp="23"]');
  console.log('assistant 2', (await p.textContent('.eqp-as-pied .res')).replace(/\s+/g, ' '));
  await shot('e20-assistant-2', false);
  await p.click('[data-eqp-as-suite]'); await p.waitForSelector('.eqp-as-msg');
  console.log('assistant 3', (await p.textContent('.eqp-as-msg')).replace(/\s+/g, ' '));
  console.log('propositions', (await p.$$eval('.eqp-as-prop .t', L => L.map(n => n.textContent))).join(' | '));
  console.log('bilan', (await p.textContent('.eqp-as-bilan').catch(() => '—')).replace(/\s+/g, ' '));
  await shot('e21-assistant-3', false);
  await p.click('[data-eqp-as-suite]'); await p.waitForSelector('.eqp-tc-sem');
  console.log('temps morts', (await p.textContent('.eqp-as-pied .res')).replace(/\s+/g, ' '), '|', (await p.textContent('.eqp-as-bilan')).replace(/\s+/g, ' ').slice(0, 160));
  await shot('e24-assistant-temps-morts', false);
  await p.click('[data-eqp-as-suite]'); await p.waitForSelector('.eqp-as-tab');
  console.log('assistant 4', (await p.textContent('.eqp-as-verif')).replace(/\s+/g, ' '), '|', (await p.textContent('[data-eqp-as-creer]')).trim());
  await shot('e22-assistant-4', false);
  await p.click('[data-eqp-as-creer]'); await p.waitForTimeout(1500);
  console.log('créé', await p.textContent('.eqp-flash').catch(() => '—'), '| fenêtre :', await p.textContent('.eqp-fo-tete b').catch(() => 'aucune'));
  await p.click('.eqp-fo-x').catch(() => {});
  // Recopier la semaine précédente : la semaine suivante, vide ; l'aperçu ; décocher un service ; recopier.
  await p.waitForSelector('.eqp-vide7');
  await p.click('.eqp-menu > summary');
  await p.click('[data-eqp-recopier]'); await p.waitForSelector('.eqp-rec-liste');
  await p.click('.eqp-rec-l:has-text("Jonas Willems") input');
  await shot('e7-recopier-la-semaine', false);
  await p.click('[data-eqp-rec-ok]'); await p.waitForTimeout(600);
  console.log('flash', await p.textContent('.eqp-flash').catch(() => '—'));
  // Le shift maker (test) sur la même semaine vide, puis la matrice A B C D.
  await p.click('[data-eqp-fermer]').catch(() => {});
  await p.click('.eqp-menu > summary');
  await p.click('[data-eqp-generer]'); await p.waitForSelector('.eqp-rec-liste');
  await shot('e8-shift-maker', false);
  await p.click('[data-eqp-rec-ok]'); await p.waitForTimeout(600);
  console.log('flash', await p.textContent('.eqp-flash').catch(() => '—'));
  await p.click('.eqp-menu > summary');
  await p.click('[data-eqp-matrice]'); await p.waitForSelector('.eqp-mat');
  await shot('e9-matrice', false);
  await p.click('.eqp-mat [data-eqp-fermer].btn');
  // La fiche de Chloé : sa lettre, ce qu'elle donne ; puis lui donner C.
  await p.click('[data-eqp-onglet="resp"]'); await p.waitForSelector('.eqp-grille');
  await p.click('.eqp-ligne:has-text("Chloé Peeters")');
  await shot('e10-fiche-roulement', false);
  await p.click('.eqp-fiche [data-eqp-lettre="C"]'); await p.waitForTimeout(500);
  console.log('lettre', await p.textContent('.eqp-fiche .eqp-seg-l .is-on'));
  console.log('écritures', JSON.stringify(ecrits));
  console.log(err.length ? 'ERREURS ' + err.join(' | ') : 'sans erreur de page');
  await b.close();
})();
