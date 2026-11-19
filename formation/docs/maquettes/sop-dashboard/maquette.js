// Maquette : le vrai module Formation (portail + tablette) servi par le serveur, avec l'article et la SOP du dashboard
// injectés dans les réponses de l'API, dans le navigateur seulement. Rien n'est écrit en production.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
// Usage (depuis pilotage-reseau/) : node docs/maquettes/sop-dashboard/maquette.js <adresse du module /formation/>
//   lit data/formation/dashboard/*.json et ses photos, écrit les écrans en PNG (convertis en JPEG pour le dépôt) dans le dossier donné.
const F = process.argv[2];
if (!F) { console.error('adresse du module Formation attendue (…/formation/)'); process.exit(2); }
const PH = 'data/formation/dashboard/photos', OUT = process.argv[3] || 'docs/maquettes/sop-dashboard/ecrans';
const DOCS = ['data/formation/dashboard/art-pil-01.json', 'data/formation/dashboard/sop-pil-01.json'].map(f => JSON.parse(fs.readFileSync(f, 'utf8')));
const now = new Date().toISOString().slice(0, 19) + '+01:00';
const contenu = d => ({ titre: d.titre, objectif: d.objectif, pour: d.pour, duree: d.duree, sections: d.sections, points_critiques: d.points_critiques,
  quiz: d.quiz.map(q => ({ question: q.question, choix: q.choix })) });
const nbE = d => d.sections.reduce((n, s) => n + s.etapes.length, 0), nbP = d => d.sections.reduce((n, s) => n + s.etapes.filter(e => e.photo).length, 0);
const ligne = d => ({ id: d.id, code: d.code, type: d.type, dossier: d.dossier, poste: d.poste, moment: d.moment, partie: 'operationnel', partieReglee: false,
  statut: d.statut, version: d.version, versionRevalider: d.version, langueOrigine: 'FR', trad: { fr: 'origine', nl: 'a_relire' }, competence: null,
  titre: d.titre, objectif: d.objectif, duree: d.duree, nbEtapes: nbE(d), nbPhotos: nbP(d), majLe: now, majPar: 'Maquette', classement: d.classement });
const standard = d => ({ id: d.id, code: d.code, type: d.type, fr: d.titre.fr, nl: d.titre.nl, statut: d.statut, version: d.version, ...d.classement, moment: d.moment, lien: '#/sop/' + d.id });

async function brancher(ctx) {
  await ctx.route(/\/formation\/sop\/photos\/(dash-[\w-]+\.jpg)$/, r => r.fulfill({ path: path.join(PH, r.request().url().match(/(dash-[\w-]+\.jpg)$/)[1]), contentType: 'image/jpeg' }));
  await ctx.route(/\/api\/cockpit\/formation\/ref(\?.*)?$/, async r => {
    const res = await r.fetch(); const j = await res.json();
    j.standards = (j.standards || []).concat(DOCS.map(standard));
    r.fulfill({ response: res, json: j });
  });
  await ctx.route(/\/api\/cockpit\/formation\/sop\/tablette\/init/, async r => {
    const res = await r.fetch(); const j = await res.json();
    j.sops = (j.sops || []).concat(DOCS.map(ligne));
    r.fulfill({ response: res, json: j });
  });
  for (const d of DOCS) {
    await ctx.route(new RegExp('/api/cockpit/formation/sop/tablette/sop/' + d.id + '(\\?.*)?$'), r => r.fulfill({ json: { ...ligne(d), contenu: contenu(d) } }));
    await ctx.route(new RegExp('/api/cockpit/formation/sop/fiche/' + d.id + '(\\?.*)?$'), r => r.fulfill({ json: { ...ligne(d), contenu: { ...contenu(d), quiz: d.quiz },
      versions: [{ version: d.version, note: 'Maquette', publieLe: now, publiePar: 'Maquette', revalider: false }], source: null } }));
  }
}
const pause = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const b = await chromium.launch();
  // 1. Le portail : la fiche FO-05 en pré-ouverture et en formation continue.
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
  await brancher(ctx);
  const p = await ctx.newPage();
  for (const [ph, nom] of [['pre', '01-portail-fo05-preouverture'], ['con', '02-portail-fo05-continue']]) {
    await p.goto(F + '#/rfformations/' + ph + '/FO-05', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
    await pause(5000);
    await p.screenshot({ path: path.join(OUT, nom + '.png'), fullPage: true });
    console.log('ok', nom);
  }
  // 2. « Vue tablette » depuis la fiche.
  await p.locator('[data-tbx="art-pil-01"]').first().click().catch(e => console.log('vue tablette', e.message));
  await pause(5000);
  await p.screenshot({ path: path.join(OUT, '03-portail-vue-tablette.png') });
  console.log('ok 03');
  // 3. La fiche d'un standard dans le portail.
  await p.goto(F + '#/sop/sop-pil-01', { waitUntil: 'networkidle' }).catch(() => {});
  await pause(4000);
  await p.screenshot({ path: path.join(OUT, '04-portail-fiche-sop.png'), fullPage: true });
  console.log('ok 04');
  await ctx.close();

  // 4. La tablette elle-même, en portrait (800 × 1110, comme la vue tablette du portail).
  const t = await b.newContext({ viewport: { width: 800, height: 1110 }, deviceScaleFactor: 1, hasTouch: true });
  await brancher(t);
  const q = await t.newPage();
  const tab = async (sop, pas, nom, apres) => {
    await q.goto(F + 'sop/tablette.html?sop=' + sop + '&apercu=1', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
    await pause(3000);
    for (let i = 0; i < pas; i++) { await q.keyboard.press('ArrowRight'); await pause(250); }
    await pause(900);
    if (apres) { await apres(); await pause(900); }
    await q.screenshot({ path: path.join(OUT, nom + '.png') });
    console.log('ok', nom);
  };
  await tab('art-pil-01', 0, '10-tablette-article-partie-1');
  await tab('art-pil-01', 8, '11-tablette-article-pl-critique');
  await tab('art-pil-01', 20, '12-tablette-article-six-leviers');
  await tab('art-pil-01', 3, '13-tablette-article-sommaire', () => q.locator('button:visible', { hasText: 'Toutes les parties' }).first().click());
  await tab('sop-pil-01', 3, '20-tablette-sop-vitrine-critique');
  await tab('sop-pil-01', 15, '21-tablette-sop-lundi-decider');
  await tab('sop-pil-01', 0, '22-tablette-sop-qui-prevenir', () => q.locator('button:visible', { hasText: 'Qui prévenir' }).first().click());
  await tab('sop-pil-01', 0, '23-tablette-sop-sommaire', () => q.locator('button:visible', { hasText: 'Toutes les étapes' }).first().click());
  // L'accueil de la tablette : l'onglet Ouverture et l'onglet Manuel.
  await q.goto(F + 'sop/tablette.html', { waitUntil: 'networkidle' }).catch(() => {});
  await pause(3000);
  await q.locator('button:visible, a:visible', { hasText: 'Ouverture' }).first().click().catch(() => {});
  await pause(1200);
  await q.screenshot({ path: path.join(OUT, '30-tablette-accueil-ouverture.png') });
  await q.locator('button:visible, a:visible', { hasText: 'Manuel' }).first().click().catch(e => console.log('manuel', e.message));
  await pause(1200);
  await q.screenshot({ path: path.join(OUT, '31-tablette-accueil-manuel.png') });
  console.log('ok accueil');
  await b.close();
})();
