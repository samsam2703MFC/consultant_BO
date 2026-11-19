/* L'étape « Les outils de gestion » telle qu'elle est codée (18/11/2026) : la tablette de la branche (public/), servie en
 * local, l'API simulée (rien n'est lu en ligne, rien n'est écrit : tout appel autre que GET répond « ok » et s'affiche).
 * Visite n° 6 de Halle ; les lectures « lu tout seul », les verdicts et les remarques sont des exemples.
 * Régénérer : node docs/maquettes/audit-outils-gestion/capturer-tablette.js */
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RACINE = path.join(__dirname, '../../../public'), OUT = __dirname + '/';
const TYPES = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.otf': 'font/otf', '.ttf': 'font/ttf', '.webmanifest': 'application/json' };
const srv = http.createServer((q, r) => {
  let f = decodeURIComponent(q.url.split('?')[0]); if (f.endsWith('/')) { f += 'index.html'; }
  const p = path.join(RACINE, f);
  if (!p.startsWith(RACINE) || !fs.existsSync(p)) { r.writeHead(404); return r.end('404'); }
  r.writeHead(200, { 'Content-Type': TYPES[path.extname(p)] || 'application/octet-stream' }); fs.createReadStream(p).pipe(r);
}).listen(8765);
const auj = new Date().toISOString().slice(0, 10);
const APP = { role: 'consultant', id: 'u8', shop: null, maintenant: auj + ' 10:05',
  boutiques: [{ id: '4', court: 'Halle', ville: 'Halle', nom: "L'Atelier by - Halle", feu: 'vert', motifs: [] }],
  consultants: [{ id: 'u8', nom: 'Loic V.' }],
  visites: [{ id: 6, shop: '4', type: 'reguliere', statut: 'en_cours', prevu_le: auj, debut_h: '09:40', duree_min: 120, commence_a: auj + ' 09:40', consultant: 'u8', consultantNom: 'Loic V.', annoncee: true },
    { id: 2, shop: '4', type: 'reguliere', statut: 'terminee', prevu_le: '2026-10-26', debut_h: '09:00', consultant: 'u8', consultantNom: 'Loic V.', annoncee: true,
      outils: { items: { tresorerie: { v: 'ok' }, commandes: { v: 'part' }, etiq_commandes: { v: 'ok' }, etiq_produits: { v: 'ok' }, taches: { v: 'part' }, soir: { v: 'ko' }, fournisseurs: { v: 'ok' } } } }],
  points: [], photos: [], plans: [], msp: [], equipe: {}, checklist: [], photosQuestions: { points: [] }, charte: null, types: [{ code: 'reguliere', nom: 'Régulière', checklist: [] }], cadre: {}, taches: [],
  delaisPoints: { P0: 2, P1: 7, P2: 14 }, causes: {}, causesDiag: {}, reperesNiveaux: [], langues: { 4: 'fr' }, typesReponse: [], frequence: {}, rayons: [], reseau: {}, delaiRapport: 48,
  seuils: { etapeAuto: false, etapesBloquantes: { photos_ext: false, photos_int: false, 'mod:*': false, controles: false, checklist: false, pistes: false, constat: false, meteo: false, evaluation: false, equipe: false } } };
const OUTILS = { shop: '4', lu: {
  tresorerie: { etat: 'att', texte: 'dernière mise à jour le 02/11 · solde réel du dimanche saisi 1 dimanche sur 4' },
  commandes: { etat: 'att', texte: '3 commandes encodées sur 30 jours · 2 passées encore ouvertes · la dernière pour le 14/11' },
  taches: { etat: 'ok', texte: 'tâches faites 86 % sur 30 jours (412 / 479, 29 jours relevés) · contrôle qualité 4,2 / 5' },
  soir: { etat: 'att', texte: 'clôturée 9 soirs sur 30 · la dernière le 12/11' },
  fournisseurs: { etat: 'ok', texte: '5 commandes au panel sur 30 jours · la dernière le 13/11' } } };
(async () => {
  const b = await chromium.launch();
  const ecrits = [];
  const ouvrir = async (lng, vp) => {
    const c = await b.newContext({ viewport: vp || { width: 1180, height: 820 }, deviceScaleFactor: 2 });
    await c.addInitScript(([l]) => { try { localStorage.setItem('vi.moi', 'u8'); localStorage.setItem('vi.lng', l); localStorage.setItem('vi.lng.6', l); } catch (e) {} }, [lng]);
    await c.route('**/api/cockpit/**', async r => {
      const u = new URL(r.request().url()); const p = u.pathname.replace(/^.*\/api\/cockpit/, '');
      if (r.request().method() !== 'GET') { ecrits.push([r.request().method(), p, r.request().postData()]); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
      if (p === '/acces/moi') { return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ connecte: true, role: 'siege' }) }); }
      if (p === '/visites/app') { return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(APP) }); }
      if (p === '/visites/outils/4') { return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(OUTILS) }); }
      return r.fulfill({ status: 404, contentType: 'application/json', body: '{"error":"simulé"}' });
    });
    const p = await c.newPage();
    p.on('pageerror', e => console.log('pageerror:', e.message));
    await p.goto('http://localhost:8765/controle/?id=u8#controle/6', { waitUntil: 'load' });
    await p.waitForFunction(() => document.querySelector('[data-a="etape"]'), null, { timeout: 30000 });
    await p.waitForTimeout(800);
    // La barre du bas : le temps « Analyse ».
    const i = await p.evaluate(() => { const x = [...document.querySelectorAll('.tb-bar [data-a="etape"]')].find(e => /Analyse|Analyse/i.test(e.textContent)); return x ? x.dataset.v : null; });
    await p.click(`.tb-bar [data-a="etape"][data-v="${i}"]`); await p.waitForTimeout(800);
    return p;
  };
  let p = await ouvrir('fr');
  console.log('titre :', await p.evaluate(() => (document.querySelector('.tb-h h1') || {}).textContent));
    // « Suivant » bloqué : ce qu'il dit.
  console.log('reste :', await p.evaluate(() => (document.querySelector('.tb-suiv .xs') || {}).textContent));
  const V = { tresorerie: 'part', commandes: 'ko', etiq_commandes: 'ko', etiq_produits: 'ok', taches: 'ok', soir: 'part', fournisseurs: 'ok' };
  const C = { tresorerie: 'Ouvert mais plus tenu depuis 15 jours ; les fournisseurs de novembre ne sont pas saisis.', commandes: 'Carnet papier à côté de la caisse : 6 commandes de la semaine hors panel.',
    etiq_commandes: '2 commandes sur 5 sans étiquette en chambre froide.', soir: 'Faite seulement quand le franchisé ferme lui-même.' };
  for (const [k, v] of Object.entries(V)) { await p.click(`[data-a="og-v"][data-v="${k}|${v}"]`); await p.waitForTimeout(120); }
  for (const [k, t] of Object.entries(C)) { await p.fill(`[data-f="og|${k}"]`, t); }
  await p.waitForTimeout(900);
  console.log('reste après verdicts :', await p.evaluate(() => (document.querySelector('.tb-suiv .xs') || {}).textContent));
  await p.click('[data-a="og-rappel"]'); await p.waitForTimeout(300);
  console.log('reste après rappel :', await p.evaluate(() => (document.querySelector('.tb-suiv .xs') || { textContent: '(rien)' }).textContent));
  await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(200);
  await p.screenshot({ path: OUT + 'd-tablette-codee.png' });
  await p.addStyleTag({ content: '.vi.tab .tb-bar{position:absolute}.vi .og-som{position:static}' });
  await p.screenshot({ path: OUT + 'd-tablette-codee-page.png', fullPage: true });
  await p.addStyleTag({ content: '.vi.tab .tb-bar{position:fixed}.vi .og-som{position:sticky}' });
  await p.click('.tb-h [data-a="og-info"]'); await p.waitForTimeout(300);
  await p.screenshot({ path: OUT + 'd-tablette-codee-info.png' });
  await p.click('.og-pop [data-a="og-info"]'); await p.waitForTimeout(200);
  // Les points proposés.
  console.log('points :', await p.evaluate(() => [...document.querySelectorAll('.og-pp')].map(e => e.textContent.replace(/\s+/g, ' ').trim())));
  // Terminer : ce qui part au serveur.
  const fin = await p.evaluate(() => { const x = [...document.querySelectorAll('.tb-bar [data-a="etape"]')].find(e => /Météo et fin/.test(e.textContent)); return x ? x.dataset.v : null; });
  await p.click(`.tb-bar [data-a="etape"][data-v="${fin}"]`); await p.waitForTimeout(500);
  const f2 = await p.evaluate(() => { const x = [...document.querySelectorAll('.tb-sous [data-a="etape"]')].find(e => /Fin/.test(e.textContent)); return x ? x.dataset.v : null; });
  if (f2) { await p.click(`.tb-sous [data-a="etape"][data-v="${f2}"]`); await p.waitForTimeout(500); }
  await p.click('[data-a="terminer"]'); await p.waitForTimeout(1500);
  for (const [m, ch, corps] of ecrits) { if (/visites\/6$|\/plans$/.test(ch)) { const j = JSON.parse(corps || '{}'); console.log(m, ch, JSON.stringify(j.outils || (j.plans || []).map(x => x.ref + ' ' + x.priorite + ' ' + x.detail), null, 0).slice(0, 900)); } }
  await p.context().close();
  p = await ouvrir('nl');
  for (const [k, v] of Object.entries(V)) { await p.click(`[data-a="og-v"][data-v="${k}|${v}"]`); await p.waitForTimeout(120); }
  await p.screenshot({ path: OUT + 'e-tablette-nl.png' });
  await p.context().close();
  await b.close(); srv.close();
})().catch(e => { console.error(e); srv.close(); process.exit(1); });
