/* Maquette « L'équipe en poste » de la tablette de contrôle : le planning du jour du magasin, chaque personne en poste
 * notée de 1 à 5, trois contrôles éclair Oui / Non (tenue de travail, SBAM, expérience client), une remarque ; la carte
 * interne au rapport du bureau.
 * Photographiée sur l'application en ligne, en lecture seule (tout appel autre que GET est intercepté), avec visites.js,
 * visites-nl.js, suivi-tpl.js, suivi-vals.js et app.css de ce dépôt. La route /visites/equipe/{shop} n'est pas encore en
 * ligne : sa réponse est construite ici avec les heures et les postes réels du planning de Halle (lus à
 * /exploitation/jour), mais des PRÉNOMS FICTIFS — aucune personne réelle n'est notée. L'horloge est fixée à 10:20.
 * Régénérer : node docs/maquettes/equipe-en-poste/generer.js */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = __dirname + '/', A = path.join(__dirname, '../../../public/assets/');
const BASE = 'http://185.180.206.46/consulant_bo/';
const VISITE = '6', SHOP = '4', JOUR = '2026-11-05', HEURE = JOUR + 'T10:20:00';
const FICTIFS = ['Lina V.', 'Emma D.', 'Tom C.', 'Inès B.', 'Hugo L.', 'Sacha M.', 'Noé R.', 'Léa P.'];
const NOTES = [{ note: 4, tenue: 1, sbam: 1, xp: 0, com: 'Accueil souriant ; ne propose pas la dégustation du jour.' },
  { note: 3, tenue: 0, sbam: 1, xp: 1, com: 'Sans tablier au comptoir ; bon conseil sur les tartes.' }];
async function equipe() {
  const d = await (await fetch(BASE + 'api/cockpit/exploitation/jour?date=' + JOUR)).json();
  const m = (d.magasins || []).find(x => String(x.shopId) === SHOP) || {};
  const par = {};
  (m.planning || []).forEach((p, i) => { const k = p.nom; (par[k] = par[k] || { emp: 9000 + Object.keys(par).length, postes: p.postes || [], creneaux: [] }).creneaux.push([p.debut, p.fin]); });
  return { source: 'panel', erreur: null, shop: SHOP, date: JOUR, personnes: Object.values(par).map((p, i) => Object.assign(p, { nom: FICTIFS[i % FICTIFS.length] })) };
}
async function contexte(b, vp, lng, EQ, PERS) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2, timezoneId: 'Europe/Brussels' });
  await c.clock.setFixedTime(new Date(HEURE + '+01:00'));
  await c.addInitScript(([l, v]) => { try { localStorage.setItem('vi.moi', 'u8'); localStorage.setItem('vi.lng', l); localStorage.setItem('vi.lng.' + v, l); } catch (e) {} }, [lng, VISITE]);
  await c.route('**/*', async r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    const m = /\/assets\/(js\/visites\.js|js\/visites-nl\.js|js\/suivi-tpl\.js|js\/suivi-vals\.js|css\/app\.css)(\?|$)/.exec(u);
    if (m) { return r.fulfill({ status: 200, contentType: m[1].endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(A + m[1]) }); }
    if (/\/api\/cockpit\/visites\/equipe\//.test(u)) { return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(EQ) }); }
    if (/\/api\/cockpit\/visites\/app\?/.test(u)) {
      const rep = await r.fetch(); const d = await rep.json();
      d.seuils = Object.assign({}, d.seuils, { etapesBloquantes: { photos_ext: false, photos_int: false, 'mod:*': false, controles: false, checklist: false, pistes: false, constat: false, meteo: false, evaluation: false, equipe: false }, etapeAuto: false });
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(d) });
    }
    if (PERS && /\/api\/cockpit\/suivi\/rapport\?visite=/.test(u)) { const rep = await r.fetch(); const d = await rep.json(); d.visite.personnel = PERS; return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(d) }); }
    return r.continue();
  });
  return c;
}
async function tablette(b, vp, lng, EQ) {
  const c = await contexte(b, vp, lng, EQ, null);
  const p = await c.newPage(); p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(BASE + 'controle/?id=u8#controle/' + VISITE, { waitUntil: 'load' });
  await p.waitForFunction(() => document.querySelector('[data-a="etape"]'), null, { timeout: 180000 });
  await p.waitForTimeout(1200);
  const etape = async re => { const i = await p.evaluate(s => { const x = [...document.querySelectorAll('[data-a="etape"]')].find(e => new RegExp(s, 'i').test(e.textContent)); return x ? x.dataset.v : null; }, re); if (i != null) { await p.click(`[data-a="etape"][data-v="${i}"]`); await p.waitForTimeout(600); } return i; };
  await etape('2\\s*·\\s*(Int|Binnen)'); await etape('^\\s*\\d+\\s*(Équipe|Team)');
  await p.waitForFunction(() => document.querySelector('[data-a="eq"]'), null, { timeout: 30000 }).catch(async () => { console.log('étapes :', await p.$$eval('[data-a="etape"]', l => l.map(e => e.dataset.v + ':' + e.textContent.replace(/\s+/g, ' ').trim().slice(0, 40)))); await p.screenshot({ path: OUT + 'debug.png' }); throw new Error('pas d’étape Équipe'); });
  return p;
}
async function noter(p, EQ) {
  for (const [i, n] of NOTES.entries()) {
    const k = 'e' + EQ.personnes[i].emp;
    for (const [f, v] of [['note', n.note], ['tenue', n.tenue], ['sbam', n.sbam], ['xp', n.xp]]) {
      for (let t = 0; t < 4 && !(await p.$(`[data-a="eq"][data-v="${k}|${f}|${v}"].on`)); t++) { await p.click(`[data-a="eq"][data-v="${k}|${f}|${v}"]`); await p.waitForTimeout(150); }
    }
    await p.fill(`[data-f="eqc|${k}"]`, n.com);
  }
  await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(300);
}
(async () => {
  const EQ = await equipe();
  console.log('planning :', EQ.personnes.map(x => x.nom + ' ' + x.creneaux.map(c => c.join('-')).join(',')).join(' · '));
  const b = await chromium.launch();
  let p = await tablette(b, { width: 1180, height: 820 }, 'fr', EQ);
  await p.screenshot({ path: OUT + 'a-equipe-a-noter.png' });
  await noter(p, EQ);
  await p.screenshot({ path: OUT + 'b-equipe-notee.png' });
  await p.screenshot({ path: OUT + 'b-equipe-notee-page.png', fullPage: true });
  await p.context().close();
  p = await tablette(b, { width: 820, height: 1180 }, 'nl', EQ);
  await noter(p, EQ);
  await p.screenshot({ path: OUT + 'c-portrait-nl.png' });
  await p.context().close();
  // Le rapport du bureau (Visites › rapport à adapter de Halle) : la carte interne.
  const PERS = { personnes: NOTES.map((n, i) => Object.assign({ emp: EQ.personnes[i].emp, nom: EQ.personnes[i].nom, heures: EQ.personnes[i].creneaux.map(c => c[0] + ' – ' + c[1]).join(' · '), vu: true }, n))
    .concat(EQ.personnes[2] ? [{ emp: EQ.personnes[2].emp, nom: EQ.personnes[2].nom, heures: EQ.personnes[2].creneaux.map(c => c[0] + ' – ' + c[1]).join(' · '), vu: false, note: null, com: '', tenue: null, sbam: null, xp: null }] : []) };
  const c = await contexte(b, { width: 1440, height: 1000 }, 'fr', EQ, PERS);
  p = await c.newPage(); p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(BASE + '#/franchises', { waitUntil: 'load' });
  await p.waitForFunction(() => [...document.querySelectorAll('button')].some(x => /Halle\s+rapport à adapter/i.test(x.innerText.replace(/\s+/g, ' '))), null, { timeout: 120000 });
  await p.evaluate(() => [...document.querySelectorAll('button')].find(x => /Halle\s+rapport à adapter/i.test(x.innerText.replace(/\s+/g, ' '))).click());
  await p.waitForFunction(() => [...document.querySelectorAll('.mko-lab')].some(e => /équipe en poste/i.test(e.textContent)), null, { timeout: 60000 });
  await p.waitForTimeout(800);
  const carte = (await p.evaluateHandle(() => [...document.querySelectorAll('.mko-carte')].find(e => { const l = e.querySelector('.mko-lab'); return l && /équipe en poste/i.test(l.textContent); }))).asElement();
  await carte.scrollIntoViewIfNeeded(); await carte.screenshot({ path: OUT + 'd-bureau-carte.png' });
  await c.close(); await b.close();
})().catch(e => { console.error(e); process.exit(1); });
