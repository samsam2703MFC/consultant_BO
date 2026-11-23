/* Se former › Formations et suivi (?vue=formation) : formation.js et seformer.js, servis par
 * formation/src/formation_continue.php (le dossier de formation du magasin) et formation_recherche.php (la méga-barre).
 * Données fictives : six formations continues diffusées au magasin de démo (FC-0xx), l'équipe de Mon équipe (initiales),
 * une consultante au prénom inventé, le manuel de démonstration de lib/manuel.js, des photos dessinées.
 * Les déclarations du franchisé (lu, quiz, vu faire, mise en place, photo du jour) changent le dossier relu.
 * /formation/continue/magasin est aussi lu par la barre du haut de toutes les pages (« Dossier de formation », ce qu'il
 * reste à faire : en retard + en cours). */
const D = require('./lib/demo');
const M = require('./lib/manuel');

const AUJ = '2026-11-19';
const CAT = { technique: 'Technique', connaissance: 'Connaissance', nouveau_produit: 'Nouveau produit', nouvelle_technique: 'Nouvelle technique' };
const STATUT = { en_cours: 'En cours', a_valider: 'À valider', validee: 'Validée' };
const CONSULTANTE = 'Nathalie D.';
const E = (titre, texte, o) => Object.assign({ titre, texte, critique: false, photo: null, video: null }, o || {});

/* Les formations continues du réseau, puis le dossier du magasin (sa version de chacune). */
const FC = [
  { id: 31, code: 'FC-019', titre: 'Bûche de Noël 2026 : montage et décor', categorie: 'nouveau_produit', version: '2', photos: 14, diffuse: '2026-11-09 08:30', echeance: '2026-12-05', statut: 'en_cours', ouvert: true,
    assignes: ['Chloé V.', 'Julien P.', 'Emma G.'], equipe: { 'Chloé V.': [1, 1, 1], 'Julien P.': [1, 1, 0], 'Emma G.': [1, 0, 0] }, photosDu: '2026-11-10',
    adaptation: { par: CONSULTANTE, le: '2026-11-10 14:20', note: 'décor sans fruits à coque pour votre magasin', contenu: true },
    contenu: { objectif: 'Monter et décorer la bûche de la saison, régulière d’un magasin à l’autre, et la présenter en vitrine dès le 1er décembre.',
      sections: [{ titre: 'Le biscuit', etapes: [E('Préparer le biscuit', 'Étaler le biscuit sur la plaque et le cuire juste doré.'), E('Rouler le biscuit tiède', 'Garnir de crème et rouler le biscuit encore tiède, sans le casser.', { critique: true })] },
        { titre: 'Le montage', etapes: [E('Masquer', 'Recouvrir la bûche de crème au beurre et tracer l’écorce à la fourchette.', { photo: 'u/buche' }), E('Les bouts', 'Couper les bouts en biais et les poser en souche.')] },
        { titre: 'Le décor', etapes: [E('Le décor de saison', 'Décor de saison sans fruits à coque (version de votre magasin).'), E('La vitrine', 'Étiqueter avec le prix et les allergènes, et garder au froid jusqu’à la vitrine.', { critique: true })] }],
      points_critiques: ['Rouler le biscuit encore tiède : froid, il casse.', 'La bûche reste au froid jusqu’à la vitrine.'] } },
  { id: 33, code: 'FC-021', titre: 'Saint-Nicolas : la vitrine du 6 décembre', categorie: 'connaissance', version: '1', photos: 0, diffuse: '2026-11-18 09:00', echeance: '2026-12-04', statut: 'en_cours', ouvert: false,
    assignes: ['Fanny L.', 'Camille R.', 'Lucie B.'], equipe: {},
    contenu: { objectif: 'Préparer la vitrine de Saint-Nicolas : les produits, la mise en place, le calendrier.',
      sections: [{ titre: '', etapes: [E('Les produits', 'Les spéculoos, les figurines en chocolat et les brioches du 6 décembre.'), E('La mise en place', 'Installer la vitrine le 1er décembre, selon le planogramme de la saison.'), E('Les commandes', 'Noter les commandes des écoles et des associations dès maintenant.')] }], points_critiques: [] } },
  { id: 29, code: 'FC-017', titre: 'Le nouveau four : programmes de cuisson', categorie: 'nouvelle_technique', version: '1', photos: 0, diffuse: '2026-11-02 08:00', echeance: '2026-11-15', statut: 'en_cours', ouvert: true,
    assignes: ['Antoine M.', 'Hugo M.', 'Fanny L.'], equipe: { 'Antoine M.': [1, 1, 0], 'Hugo M.': [1, 0, 0] },
    contenu: { objectif: 'Utiliser les programmes du nouveau four pour la viennoiserie et les pains.',
      sections: [{ titre: '', etapes: [E('Choisir le programme', 'Un programme par famille de produits : viennoiserie, pains, pâtisserie.'), E('Charger le four', 'Toujours le même nombre de plaques, bien espacées.', { critique: true }), E('Le nettoyage', 'Le cycle de nettoyage chaque soir.')] }], points_critiques: ['Ne jamais ouvrir la porte pendant le programme de viennoiserie.'] } },
  { id: 26, code: 'FC-016', titre: 'Allergènes : l’étiquetage en vitrine', categorie: 'connaissance', version: '3', photos: 0, diffuse: '2026-10-20 08:00', echeance: '2026-11-20', statut: 'a_valider', ouvert: true,
    assignes: ['Emma G.', 'Inès F.', 'Lucie B.'], equipe: { 'Emma G.': [1, 1, 1], 'Inès F.': [1, 1, 1], 'Lucie B.': [1, 1, 1] }, miseEnPlace: '2026-11-17 16:40', termine: '2026-11-17 16:40',
    contenu: { objectif: 'Chaque produit de la vitrine porte ses allergènes, et l’équipe sait répondre au client.',
      sections: [{ titre: '', etapes: [E('L’étiquette', 'Chaque étiquette de la vitrine dit les allergènes du produit.'), E('Répondre au client', 'Montrer la fiche du produit ; dans le doute, demander au responsable.', { critique: true })] }], points_critiques: [] } },
  { id: 21, code: 'FC-014', titre: 'Tourage du croissant : les gestes clés', categorie: 'technique', version: '2', photos: 0, diffuse: '2026-10-01 08:00', echeance: '2026-11-01', statut: 'validee', ouvert: true,
    assignes: ['Antoine M.', 'Hugo M.'], equipe: { 'Antoine M.': [1, 1, 1], 'Hugo M.': [1, 1, 1] }, miseEnPlace: '2026-10-28 11:00', termine: '2026-10-28 11:00',
    validation: { par: CONSULTANTE, le: '2026-11-06 10:30', mot: 'Très beau feuilletage, bravo à l’équipe.', archive: 412 },
    contenu: { objectif: 'Les gestes clés du tourage : un feuilletage régulier, un croissant léger.', sections: [{ titre: '', etapes: [E('Le beurre et la pâte', 'La même souplesse, sortis du froid ensemble.'), E('Les tours', 'Un quart de tour à chaque fois, le repos au froid entre deux.', { critique: true, photo: 'u/tourage' })] }], points_critiques: [] } },
  { id: 18, code: 'FC-012', titre: 'Café : le réglage du moulin', categorie: 'technique', version: '1', photos: 0, diffuse: '2026-09-10 08:00', echeance: '2026-10-10', statut: 'validee', ouvert: true,
    assignes: ['Camille R.', 'Emma G.'], equipe: { 'Camille R.': [1, 1, 1], 'Emma G.': [1, 1, 1] }, miseEnPlace: '2026-10-02 09:00', termine: '2026-10-02 09:00',
    validation: { par: CONSULTANTE, le: '2026-10-08 15:10', mot: null, archive: 377 },
    contenu: { objectif: 'Régler le moulin chaque matin pour un café constant.', sections: [{ titre: '', etapes: [E('Le réglage', 'Un réglage le matin, contrôlé sur deux tasses.')] }], points_critiques: [] } },
];

/* La photo du jour de la bûche : relue par la consultante (conforme, à refaire), une case par jour depuis le 10/11. */
const PHOTOS = { '2026-11-10': ['conforme'], '2026-11-11': ['conforme'], '2026-11-12': ['a_refaire', 'Biscuit fendu au roulage : à rouler plus tiède.'], '2026-11-13': ['conforme'],
  '2026-11-14': ['conforme'], '2026-11-16': ['conforme'], '2026-11-17': ['conforme'], '2026-11-18': ['en_attente'] };

const S = { equipe: {}, mep: {}, photosAjout: {}, ouverts: new Set() };   // les déclarations de la séance
const plusJ = (s, n) => D.plus(s, n);
function grille(f) {
  if (!f.photos) { return { jours: 0, conformes: 0, refusees: 0, manquantes: 0, enAttente: 0, du: null, fin: null, grille: [], complet: true }; }
  const g = []; let conf = 0, ref = 0, manq = 0, att = 0;
  for (let i = 0; i < 40; i++) {
    const jour = plusJ(f.photosDu, i), p = S.photosAjout[f.id + '|' + jour] || PHOTOS[jour];
    let etat;
    if (p) { etat = p[0]; } else { etat = jour < AUJ ? 'manquante' : jour === AUJ ? 'a_faire' : 'a_venir'; }
    g.push({ jour, etat, photo: p ? 100 + i : null, commentaire: p ? (p[1] || null) : null });
    if (etat === 'conforme') { conf++; } else if (etat === 'a_refaire') { ref++; } else if (etat === 'manquante') { manq++; } else if (etat === 'en_attente') { att++; }
    if (conf >= f.photos || g.length >= f.photos + ref + manq) { break; }
  }
  return { jours: f.photos, conformes: conf, refusees: ref, manquantes: manq, enAttente: att, du: f.photosDu, fin: g[g.length - 1].jour, grille: g, complet: conf >= f.photos };
}
function equipe(f) {
  return f.assignes.map(p => { const v = Object.assign({}, { lu: !!(f.equipe[p] || [])[0], quiz: !!(f.equipe[p] || [])[1], vu: !!(f.equipe[p] || [])[2] }, S.equipe[f.id + '|' + p] || {}); return Object.assign({ personne: p }, v); });
}
function vue(f) {
  const ph = grille(f), eq = equipe(f), mep = S.mep[f.id] !== undefined ? S.mep[f.id] : (f.miseEnPlace || null);
  const faits = eq.reduce((s, p) => s + (+p.lu) + (+p.quiz) + (+p.vu), 0) + (mep ? 1 : 0), e = faits / (3 * eq.length + 1);
  const p = ph.jours > 0 ? Math.min(1, ph.conformes / ph.jours) : null;
  const pct = Math.round(100 * (p === null ? e : (e + p) / 2));
  const retard = f.statut === 'en_cours' && f.echeance < AUJ;
  return { id: f.id, shop: D.SHOP, magasin: D.NOM, fc: 'fc-' + f.id, code: f.code, titre: f.titre, titreNl: '', categorie: f.categorie, categorieLib: CAT[f.categorie], produit: null,
    statut: f.statut, statutLib: retard ? 'En retard' : STATUT[f.statut], retard, echeance: f.echeance, assignes: f.assignes.slice(), adapte: !!f.adaptation,
    adaptation: f.adaptation ? { note: f.adaptation.note, par: f.adaptation.par, le: f.adaptation.le, contenu: !!f.adaptation.contenu } : { note: null, par: null, le: null, contenu: false },
    avancement: { pct: f.statut === 'validee' ? 100 : pct, equipe: Math.round(100 * e), photos: p === null ? null : Math.round(100 * p), complet: e >= 1 && (p === null || p >= 1) },
    photos: ph, equipe: eq, miseEnPlace: mep ? { le: mep, par: 'Fanny L.' } : null, termineLe: f.termine || null,
    validation: f.validation ? { par: f.validation.par, le: f.validation.le, mot: f.validation.mot, archive: f.validation.archive } : null,
    diffuseLe: f.diffuse, nouveau: !f.ouvert && !S.ouverts.has(f.id), version: f.version };
}
function dossiers() {
  const L = FC.map(vue), rang = d => d.retard ? 0 : d.statut === 'en_cours' ? 1 : d.statut === 'a_valider' ? 2 : 3;
  return L.sort((a, b) => rang(a) - rang(b) || b.diffuseLe.localeCompare(a.diffuseLe));
}
function magasin() {
  const L = dossiers(), lim = plusJ(AUJ, -60);
  const nouv = L.filter(d => d.diffuseLe.slice(0, 10) >= lim).sort((a, b) => b.diffuseLe.localeCompare(a.diffuseLe));
  const pers = [...new Set(L.flatMap(d => d.equipe.map(p => p.personne)))];
  return { shop: D.SHOP, nom: D.NOM,
    nouveautes: nouv.slice(0, 8).map(d => ({ id: d.id, code: d.code, titre: d.titre, categorie: d.categorie, categorieLib: d.categorieLib, date: d.diffuseLe.slice(0, 10), nouveau: d.nouveau, lien: '?shop=4&vue=formation&fc=' + d.id })),
    nouveautesTotal: nouv.length, dossiers: L,
    reperes: { enRetard: L.filter(d => d.retard).length, enCours: L.filter(d => d.statut === 'en_cours' && !d.retard).length, aValider: L.filter(d => d.statut === 'a_valider').length,
      validees: L.filter(d => d.statut === 'validee').length, photoDuJour: L.filter(d => d.statut === 'en_cours' && d.photos.grille.some(g => g.etat === 'a_faire')).length },
    equipe: pers, categories: CAT };
}
function formation(id) {
  const f = FC.find(x => x.id === +id);
  if (!f) { return { __status: 404, json: { error: 'formation inconnue pour ce magasin' } }; }
  S.ouverts.add(f.id);
  const c = f.contenu;
  return Object.assign(vue(f), { contenu: { titre: f.titre, objectif: c.objectif, sections: M.numeroter(c.sections), points_critiques: c.points_critiques },
    journal: [{ action: 'diffusee', detail: 'diffusée au magasin', par: 'le réseau', role: 'siège', le: f.diffuse.slice(0, 16), shop: D.SHOP }] });
}

/* La méga-barre : l'index de tout ce qui s'apprend (les six catalogues de FR_CATALOGUES, leurs couleurs de couverture). */
const CATALOGUES = [
  { cle: 'fo', nom: 'Formations', couleur: '#FFFFFF', encre: '#8D1D2C', contour: true, manuel: null },
  { cle: 'man', nom: 'Manuel opératoire', couleur: '#8D1D2C', encre: '#F5EFE4', contour: false, manuel: 'operatoire' },
  { cle: 'std', nom: 'Standards (SOP)', couleur: '#222222', encre: '#F5EFE4', contour: false, manuel: 'standards' },
  { cle: 'chk', nom: 'Checklists', couleur: '#78554B', encre: '#F5EFE4', contour: false, manuel: 'checklists' },
  { cle: 'res', nom: 'Manuel du réseau', couleur: '#F2C9A0', encre: '#222222', contour: false, manuel: 'reseau' },
  { cle: 'fc', nom: 'Formation continue', couleur: '#FFF8F1', encre: '#7A3E12', contour: true, manuel: null },
];
function recherche() {
  const plat = x => String(x || '').replace(/\s+/g, ' ').trim();
  const versDoc = (d, c) => {
    const etapes = [], texte = []; let photo = null, n = 0;
    c.sections.forEach(s => { if (s.titre) { texte.push(s.titre); } s.etapes.forEach(e => { n++; texte.push(plat(e.titre + ' ' + e.texte)); if (etapes.length < 4) { etapes.push({ titre: e.titre || e.texte.slice(0, 120), critique: !!e.critique }); } if (!photo && e.photo) { photo = e.photo; } }); });
    (c.points_critiques || []).forEach(p => texte.push(plat(p)));
    return { id: d.id, cat: d.cat, type: d.type, code: d.code, titre: d.titre, objectif: plat(c.objectif), pour: plat(c.pour), texte: texte.join(' · '), etapes, nbEtapes: n, photo,
      poste: d.poste || '', moment: d.moment || '', acces: d.acces || 'tous', statut: 'publiee', categorie: d.categorie || null };
  };
  const docs = M.DOCS.map(d => versDoc(d, M.contenu(d)))
    .concat(FC.map(f => versDoc({ id: 'fc-' + f.id, cat: 'fc', type: 'FC', code: f.code, titre: f.titre, categorie: f.categorie }, { objectif: f.contenu.objectif, pour: '', sections: f.contenu.sections.map(s => ({ titre: s.titre, etapes: s.etapes })), points_critiques: f.contenu.points_critiques })));
  const n = docs.reduce((a, d) => { a[d.cat] = (a[d.cat] || 0) + 1; return a; }, {});
  const types = docs.reduce((a, d) => { a[d.type] = (a[d.type] || 0) + 1; return a; }, {});
  return { genere: AUJ + ' 15:40', catalogues: CATALOGUES.map(c => Object.assign({}, c, { n: c.cle === 'fo' ? M.FORMATIONS.length : c.cle === 'res' ? M.DOMAINES.length : n[c.cle] || 0 })), types, docs,
    formations: M.FORMATIONS.map(f => Object.assign({}, f)), domaines: M.DOMAINES.map(d => Object.assign({}, d)) };
}
/** Un document ouvert dans le lecteur (la route publique de la tablette). */
function sop(id) {
  const d = M.doc(id);
  if (d) { return { id: d.id, code: d.code, type: d.type, titre: d.titre, version: '3', statut: 'publiee', contenu: M.contenu(d) }; }
  const f = FC.find(x => 'fc-' + x.id === id);
  if (f) { return { id, code: f.code, type: 'FC', titre: f.titre, version: f.version, statut: 'publiee', contenu: { titre: f.titre, objectif: f.contenu.objectif, sections: M.numeroter(f.contenu.sections), points_critiques: f.contenu.points_critiques } }; }
  return { __status: 404, json: { error: 'SOP inconnue' } };
}

module.exports = function (m, p, q, c) {
  const brut = typeof c === 'string' ? c : '';   // la photo du jour part en multipart : le corps brut
  c = c && typeof c === 'object' ? c : {};
  if (p === '/formation/recherche' && m === 'GET') { return recherche(); }
  let x = p.match(/^\/formation\/sop\/tablette\/sop\/([\w-]{1,32})$/);
  if (x && m === 'GET') { return sop(x[1]); }
  x = p.match(/^\/formation\/sop\/photo\/([\w.-]+)$/);
  if (x) { const svg = M.photo(x[1]); return svg ? { __body: svg, type: 'image/svg+xml' } : undefined; }
  if (!p.startsWith('/formation/continue/magasin')) { return undefined; }
  if (p === '/formation/continue/magasin' && m === 'GET') { return magasin(); }
  if (p === '/formation/continue/magasin/formation') { return formation(q.get('id')); }
  if (p === '/formation/continue/magasin/photo' && m === 'GET') { return { __body: M.buche(+q.get('id') || 0), type: 'image/svg+xml' }; }
  if (p === '/formation/continue/magasin/photo' && m === 'POST') {
    const id = +((brut.match(/name="id"\r?\n\r?\n(\d+)/) || [])[1] || 31);
    S.photosAjout[id + '|' + AUJ] = ['en_attente'];
    return { ok: true, dossier: vue(FC.find(f => f.id === id)) };
  }
  if (p === '/formation/continue/magasin/avancement' && m === 'POST') {
    const f = FC.find(y => y.id === +c.id); if (!f) { return { __status: 404, json: { error: 'formation inconnue pour ce magasin' } }; }
    const per = String(c.personne || '').trim(); if (!per) { return { __status: 422, json: { error: 'personne attendue' } }; }
    if (!f.assignes.includes(per)) { f.assignes.push(per); }
    const k = f.id + '|' + per; S.equipe[k] = Object.assign({}, S.equipe[k] || {});
    ['lu', 'quiz', 'vu'].forEach(z => { if (c[z] !== undefined) { S.equipe[k][z] = !!c[z]; } });
    return { ok: true, dossier: vue(f) };
  }
  if (p === '/formation/continue/magasin/mise-en-place' && m === 'POST') {
    const f = FC.find(y => y.id === +c.id); if (!f) { return { __status: 404, json: { error: 'formation inconnue pour ce magasin' } }; }
    S.mep[f.id] = c.annuler ? null : AUJ + ' 15:40';
    return { ok: true, dossier: vue(f) };
  }
  return undefined;
};
