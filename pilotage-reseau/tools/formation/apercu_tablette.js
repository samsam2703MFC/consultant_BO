// Aperçu, sur la vraie tablette du Manuel, de documents tenus en fichier (data/formation/<dossier>/<id>.json, le format
// d'encoder_sop.py) AVANT leur encodage : ils sont injectés dans les réponses de l'API, dans le navigateur seulement.
// Rien n'est écrit au serveur (aucune route d'écriture n'est appelée). Sert à faire valider un document sur captures.
//
// Usage (depuis pilotage-reseau/) :
//   node tools/formation/apercu_tablette.js <adresse du module /formation/> <dossier de sortie> <fichier.json>…
// Pour chaque document, en PNG 800 × 1110 (tablette en portrait) :
//   <id>-1-partie-1.png        la première partie (ou étape)
//   <id>-2-sommaire.png        le tiroir « Toutes les parties » (ou « Toutes les étapes »)
//   <id>-3-critique-<n>.png    les parties marquées critiques (trois au plus)
//   <id>-4-derniere.png        la dernière partie
//   <id>-5-nl-partie-1.png     la première partie en néerlandais
//   <id>-6-manuel.png          l'onglet Manuel de la tablette, au bouton où le document se range (selon son accès)
// Les photos « fichier.jpg » sont lues dans le dossier photos/ voisin du fichier ; les photos « u/… » sont celles du serveur.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const [F, OUT, ...FICHIERS] = process.argv.slice(2);
if (!F || !OUT || !FICHIERS.length) {
  console.error('usage : node tools/formation/apercu_tablette.js <adresse du module /formation/> <dossier de sortie> <fichier.json>…');
  process.exit(2);
}
const MODULE = F.endsWith('/') ? F : F + '/';
fs.mkdirSync(OUT, { recursive: true });

const DOCS = FICHIERS.map(f => ({ d: JSON.parse(fs.readFileSync(f, 'utf8')), dir: path.dirname(path.resolve(f)) }));
// Les photos locales des documents : nom → chemin (une photo « u/… » est servie par l'API, on n'y touche pas).
const PHOTOS = {};
for (const { d, dir } of DOCS) {
  for (const s of d.sections || []) {
    for (const e of s.etapes || []) {
      if (!e.photo || String(e.photo).startsWith('u/')) { continue; }
      const p = path.join(dir, 'photos', e.photo);
      if (!fs.existsSync(p)) { console.error('photo absente :', p); process.exit(1); }
      PHOTOS[e.photo] = p;
    }
  }
}

const now = new Date().toISOString().slice(0, 19) + '+01:00';
const PARTIES = ['preouverture', 'ouverture', 'operationnel', 'formation'];
const partieDe = d => d.partie || ((d.classement && d.classement.phases) || []).find(p => PARTIES.includes(p)) || 'operationnel';
const contenu = d => ({ titre: d.titre, objectif: d.objectif, pour: d.pour, duree: d.duree, sections: d.sections,
  points_critiques: d.points_critiques || [], quiz: (d.quiz || []).map(q => ({ question: q.question, choix: q.choix })) });
const etapes = d => (d.sections || []).reduce((t, s) => t.concat(s.etapes || []), []);
const ligne = d => ({ id: d.id, code: d.code || '0.0.—', type: d.type, dossier: d.dossier, poste: d.poste, moment: d.moment ?? null,
  acces: d.acces || 'tous', partie: partieDe(d), partieReglee: false, statut: d.statut || 'a_valider', version: d.version || '1.0',
  versionRevalider: d.version || '1.0', langueOrigine: 'FR', trad: { fr: 'origine', nl: 'a_relire' }, competence: null,
  titre: d.titre, objectif: d.objectif, duree: d.duree, nbEtapes: etapes(d).length, nbPhotos: etapes(d).filter(e => e.photo).length,
  majLe: now, majPar: 'Aperçu', classement: d.classement || {} });
const standard = d => ({ id: d.id, code: d.code || '0.0.—', type: d.type, fr: d.titre.fr, nl: d.titre.nl, statut: d.statut || 'a_valider',
  version: d.version || '1.0', ...(d.classement || {}), moment: d.moment ?? null, lien: '#/sop/' + d.id });
const echapper = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

async function brancher(ctx) {
  // Photos du dépôt : la tablette les demande en « photos/<nom> » à côté de sop/tablette.html.
  await ctx.route(/\/formation\/sop\/photos\/([^/?#]+)(\?.*)?$/, r => {
    const nom = decodeURIComponent(r.request().url().match(/\/photos\/([^/?#]+)/)[1]);
    return PHOTOS[nom] ? r.fulfill({ path: PHOTOS[nom], contentType: /\.png$/i.test(nom) ? 'image/png' : 'image/jpeg' }) : r.continue();
  });
  await ctx.route(/\/api\/cockpit\/formation\/ref(\?.*)?$/, async r => {
    const res = await r.fetch(); const j = await res.json();
    const ids = new Set(DOCS.map(x => x.d.id));
    j.standards = (j.standards || []).filter(s => !ids.has(s.id)).concat(DOCS.map(x => standard(x.d)));
    r.fulfill({ response: res, json: j });
  });
  await ctx.route(/\/api\/cockpit\/formation\/sop\/tablette\/init(\?.*)?$/, async r => {
    const res = await r.fetch(); const j = await res.json();
    const ids = new Set(DOCS.map(x => x.d.id));
    j.sops = (j.sops || []).filter(s => !ids.has(s.id)).concat(DOCS.map(x => ligne(x.d)));
    r.fulfill({ response: res, json: j });
  });
  for (const { d } of DOCS) {
    await ctx.route(new RegExp('/api/cockpit/formation/sop/tablette/sop/' + echapper(d.id) + '(\\?.*)?$'),
      r => r.fulfill({ json: { ...ligne(d), contenu: contenu(d) } }));
    await ctx.route(new RegExp('/api/cockpit/formation/sop/fiche/' + echapper(d.id) + '(\\?.*)?$'), r => r.fulfill({ json: {
      ...ligne(d), contenu: { ...contenu(d), quiz: d.quiz || [] },
      versions: [{ version: d.version || '1.0', note: 'Aperçu', publieLe: now, publiePar: 'Aperçu', revalider: false }], source: null } }));
  }
}
const pause = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 800, height: 1110 }, deviceScaleFactor: 2, hasTouch: true });
  await brancher(ctx);
  // La tablette retient la langue et le bouton du Manuel sur l'appareil : chaque aperçu part de zéro.
  await ctx.addInitScript(() => { try { localStorage.removeItem('foLangue'); localStorage.removeItem('foManSous'); } catch (e) { /* */ } });
  const q = await ctx.newPage();
  const faits = [];
  const vue = async (d, pas, nom, { langue, apres } = {}) => {
    await q.goto(MODULE + 'sop/tablette.html?sop=' + encodeURIComponent(d.id) + (langue ? '&langue=' + langue : ''),
      { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
    await pause(2500);
    for (let i = 0; i < pas; i++) { await q.keyboard.press('ArrowRight'); await pause(200); }
    await pause(800);
    if (apres) { await apres(); await pause(800); }
    const f = path.join(OUT, d.id + '-' + nom + '.png');
    await q.screenshot({ path: f });
    faits.push(f);
    console.log('ok', path.basename(f));
  };
  for (const { d } of DOCS) {
    const E = etapes(d);
    if (!E.length) { console.log(d.id, ': aucune partie, rien à montrer'); continue; }
    await vue(d, 0, '1-partie-1');
    const tout = d.type === 'Article' ? 'Toutes les parties' : 'Toutes les étapes';
    await vue(d, 0, '2-sommaire', { apres: () => q.locator('button:visible', { hasText: tout }).first().click().catch(e => console.log('sommaire :', e.message)) });
    const critiques = E.map((e, i) => (e.critique ? i : -1)).filter(i => i > 0).slice(0, 3);
    for (const [n, i] of critiques.entries()) { await vue(d, i, '3-critique-' + (n + 1)); }
    if (E.length > 1) { await vue(d, E.length - 1, '4-derniere'); }
    await vue(d, 0, '5-nl-partie-1', { langue: 'nl' });
    // L'onglet Manuel : Franchisé (accès « responsable »), Consultant (« consultant »), Manuel opératoire (le reste).
    const sous = { responsable: 'fr', consultant: 'co' }[d.acces] || 'ma';
    await q.goto(MODULE + 'sop/tablette.html', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
    await pause(2500);
    await q.locator('button:visible, a:visible', { hasText: 'Manuel' }).first().click().catch(e => console.log('manuel :', e.message));
    await pause(900);
    await q.locator('[data-mansous="' + sous + '"]').first().click().catch(e => console.log('bouton du manuel :', e.message));
    await pause(900);
    const f = path.join(OUT, d.id + '-6-manuel.png');
    await q.screenshot({ path: f, fullPage: true });
    faits.push(f);
    console.log('ok', path.basename(f));
  }
  await b.close();
  console.log('\n' + faits.length + ' capture(s) dans ' + OUT + ' — rien n’a été écrit au serveur');
})();
