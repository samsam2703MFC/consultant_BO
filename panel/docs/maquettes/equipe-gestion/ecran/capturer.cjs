const { chromium } = require('playwright'); const fs = require('fs'), path = require('path');
// Les données : node capturer.cjs <sortie> lit carte.json, jour.json et semaine.json, rendus par « php panel_illustratif.php » (le vrai code de panel/src/equipe.php sur un panel de doublure).
const D = __dirname, OUT = process.argv[2] || D, PROD = 'http://185.180.206.46/consulant_bo/api/cockpit';
const lire = f => fs.readFileSync(path.join(D, f), 'utf8');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 940 }, deviceScaleFactor: 2, locale: 'fr-BE' });
  const p = await ctx.newPage(); const err = []; const ecrits = [];
  p.on('pageerror', e => err.push(e.message));
  p.on('dialog', d => d.accept());   // les confirmations (retirer quelqu'un du shift) p.on('console', m => { if (m.type() === 'error') err.push('console: ' + m.text()); });
  await p.route('**/api/cockpit/**', async r => {
    const u = new URL(r.request().url()), pa = u.pathname.replace(/^.*\/api\/cockpit/, ''), m = r.request().method();
    if (pa.startsWith('/dashboard/equipe')) {
      if (m !== 'GET') {
        ecrits.push([m, pa, r.request().postData()]);
        if (pa === '/dashboard/equipe/semaine/recopie') { const n = JSON.parse(r.request().postData()).sources.length; return r.fulfill({ status: 200, json: { ok: true, crees: n, demandes: n, echecs: [], arret: null } }); }
        if (pa === '/dashboard/equipe/semaine/generer') { const n = JSON.parse(r.request().postData()).cles.length; return r.fulfill({ status: 200, json: { ok: true, crees: n, demandes: n, echecs: [], arret: null } }); }
        if (pa === '/dashboard/equipe/matrice') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('matrice.json') }); }
        if (pa === '/dashboard/equipe/roulement') { const b = JSON.parse(r.request().postData()), x = JSON.parse(lire('matrice.json')).roulements; if (b.lettre) { x[b.employe] = b.lettre; } else { delete x[b.employe]; } return r.fulfill({ status: 200, json: { ok: true, employe: b.employe, lettre: b.lettre, roulements: x } }); }
        return r.fulfill({ status: 200, json: pa === '/dashboard/equipe/postes' ? Object.assign(JSON.parse(lire('carte.json')), { ok: true }) : { ok: true, id: 999 } });
      }
      if (pa === '/dashboard/equipe/semaine/recopie') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('recopie.json') }); }
      if (pa === '/dashboard/equipe/semaine/generer') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('generer.json') }); }
      if (pa === '/dashboard/equipe/matrice') { return r.fulfill({ status: 200, contentType: 'application/json', body: lire('matrice.json') }); }
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
  await p.waitForSelector('.eqp-ed');
  await p.selectOption('[data-eqp-ajout-sel]', '26');
  await shot('e12-clic-sur-un-service', false);
  await p.click('[data-eqp-ajout-ok]'); await p.waitForTimeout(900);
  console.log('ajout', await p.textContent('.eqp-flash').catch(() => '—'), '| fenêtre rouverte :', !!(await p.$('.eqp-ed')));
  await p.click('.eqp-shift-l [data-eqp-retirer] >> nth=0'); await p.waitForTimeout(900);
  console.log('retrait', await p.textContent('.eqp-flash').catch(() => '—'), '| fenêtre rouverte :', !!(await p.$('.eqp-ed')));
  await p.click('.eqp-ed [data-eqp-annuler]');
  await p.click('tr:has-text("Jonas Willems") td[data-eqp-cell="2026-11-24"]');
  await p.waitForSelector('.eqp-ed');
  await shot('e13-clic-sur-une-case-vide', false);
  await p.click('.eqp-ed [data-eqp-ok]'); await p.waitForTimeout(900);
  console.log('nouveau', await p.textContent('.eqp-flash').catch(() => '—'));
  await p.click('[data-eqp-vue="jour"]');
  await p.waitForSelector('.eqp-pg', { timeout: 20000 });
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
  await p.waitForSelector('.eqp-pop');
  console.log('pop tracé', (await p.textContent('.eqp-pop')).replace(/\s+/g, ' ').slice(0, 220));
  await shot('e15-ligne-tracee', true);
  await p.click('.eqp-pop [data-eqp-ok]'); await p.waitForTimeout(900);
  console.log('tracé enregistré', await p.textContent('.eqp-flash').catch(() => '—'), '· pop ouverte ?', !!(await p.$('.eqp-pop')));
  // Modifier : étirer la fin du premier service de Laura jusqu'à 12:30.
  const bar = await p.$('.eqp-l:has-text("Laura Claes") [data-eqp-bar]');
  await bar.scrollIntoViewIfNeeded(); await p.evaluate(() => window.scrollBy(0, 200));
  const bb = await bar.boundingBox(); const tl = await (await p.$('.eqp-l:has-text("Laura Claes") .eqp-tl')).boundingBox();
  const parMin = tl.width / (await p.evaluate(() => { const t = [...document.querySelectorAll('.eqp-axe .eqp-t')]; return (t.length - 1) * 60; }));
  await p.mouse.move(bb.x + bb.width - 2, bb.y + bb.height / 2); await p.mouse.down();
  await p.mouse.move(bb.x + bb.width - 2 + 90 * parMin, bb.y + bb.height / 2, { steps: 8 }); await p.mouse.up();
  await p.waitForSelector('.eqp-pop');
  console.log('pop', (await p.textContent('.eqp-pop')).replace(/\s+/g, ' ').slice(0, 260));
  await shot('e4-modifier-un-service', true);
  await p.click('[data-eqp-ok]'); await p.waitForTimeout(500);
  // Le mois, puis la semaine (le tableau et le planogramme fondus).
  await p.click('[data-eqp-vue="mois"]'); await p.waitForSelector('.eqp-moi');
  await shot('e5-planning-mois', true);
  await p.click('.eqp-moi tr:has-text("Laura Claes") td[data-eqp-cell="2026-11-25"] [data-eqp-svc]'); await p.waitForSelector('.eqp-ed');
  console.log('mois, service', (await p.textContent('.eqp-ed')).replace(/\s+/g, ' ').slice(0, 120));
  await p.click('.eqp-ed [data-eqp-annuler]');
  await p.click('.eqp-moi [data-eqp-semaine="2026-11-23"]'); await p.waitForSelector('.eqp-tab:not(.eqp-moi)');
  await p.click('[data-eqp-pdf]');
  await shot('e6-tableau-pdf', true);
  // Recopier la semaine précédente : la semaine suivante, vide ; l'aperçu ; décocher un service ; recopier.
  await p.click('[data-eqp-pas="7"]'); await p.waitForSelector('.eqp-vide7');
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
