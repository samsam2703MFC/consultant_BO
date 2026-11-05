/* Prépare donnees.js à partir de sops.json (les SOP lues dans le Drive le 05/11/2026 : titres, versions, langues,
   sections, étapes, points critiques, quiz) et pose la progression FICTIVE de l'équipe de Halle.
     node docs/maquettes/formation/preparer.js            (lit sops.json à côté de ce script)
   Le reste des maquettes ne lit que donnees.js. */
const fs = require('fs'), path = require('path');
const SRC = JSON.parse(fs.readFileSync(path.join(__dirname, 'sops.json'), 'utf8'));

// --- Les SOP, sous une forme courte et stable ------------------------------------------------------------
// Clé = un mot du titre ; « mini » = l'intitulé des colonnes de la matrice.
const CLES = [
  ['ouv-mag', /Ouverture du magasin/i, 'Ouverture magasin'], ['caisse', /Encodage des commandes/i, 'Commandes en caisse'],
  ['tgtg', /Too Good To Go/i, 'Too Good To Go'], ['plaquage', /Plaquage/i, 'Plaquage · pousses'],
  ['t-fruits', /Tartes aux fruits/i, 'Tartes aux fruits'], ['flans', /Flans/i, 'Flans · tartes sucre'],
  ['fin-poste', /Fin de poste/i, 'Fin de poste'], ['cremes', /Crème fraîche/i, 'Crèmes'],
  ['cramiques', /Cramiques/i, 'Cramiques · craquelins'], ['vienn', /Viennoiseries/i, 'Viennoiseries · sandw.'],
  ['t-class', /Tartes classiques/i, 'Tartes classiques · Brookie'], ['t-froides', /Tartes froides/i, 'Tartes froides'],
  ['quiches', /Quiches/i, 'Quiches · pommes par.'], ['ouv-poste', /Ouverture du poste/i, 'Ouverture du poste'],
  ['fondants', /Fondants/i, 'Fondants · nappage'], ['eclairs', /clairs et choux/i, 'Éclairs · choux'],
  ['cookies', /Cookies/i, 'Cookies · cakes · financ.'], ['recuisson', /Recuisson/i, 'Recuisson après-midi'],
  ['baguettes', /Cuisson des baguettes/i, 'Baguettes et pains'], ['fermeture', /Fermeture du magasin/i, 'Fermeture magasin'],
  ['calmes', /riodes calmes/i, 'Périodes calmes'], ['fours', /Nettoyage des fours/i, 'Nettoyage des fours'],
  ['ferm-quot', /Fermeture quotidienne/i, 'Fermeture quotidienne'], ['memo', /Organisation quotidienne/i, 'Organisation du poste'],
  ['programmes', /Programmes de cuisson/i, 'Programmes cuisson · pousse']
];
const g = (o, ...ks) => { for (const k of ks) { if (o && o[k] != null && o[k] !== '') { return o[k]; } } return null; };
const propre = t => typeof t === 'string' ? t.replace(/≈\s*/g, 'env. ').replace(/→/g, '→') : t;
const titreDe = s => String(g(s, 'title', 'titre') || '');
const court = t => t.replace(/^(SOP|Checklist|Mémo|Fiche)\s*—\s*/i, '').replace(/\s*—\s*(FR|NL|NL-FR|FR-NL)\b.*$/i, '').replace(/\.(pdf|docx)$/i, '').trim();
const sops = [];
for (const s of SRC.sops) {
  const t = titreDe(s);
  const cle = CLES.find(c => c[1].test(t));
  if (!cle) { console.warn('SOP sans clé :', t); continue; }
  if (sops.find(x => x.id === cle[0])) { // deux langues du même sujet (Recuisson FR + NL) : une seule SOP
    const x = sops.find(y => y.id === cle[0]);
    const l = String(g(s, 'langue', 'lang') || '');
    if (l && !x.langue.split('-').includes(l)) { x.langue = [x.langue, l].sort((a, b) => (a === 'FR' ? -1 : 1)).join('-'); x.paire = true; }
    continue;
  }
  const fids = g(s, 'fileIds', 'file_ids') || {};
  const fichiers = [];
  if (Array.isArray(fids)) { fids.forEach(f => fichiers.push(/doc/i.test(JSON.stringify(f)) ? 'DOCX' : 'PDF')); }
  else { if (fids.pdf) { fichiers.push('PDF'); } if (fids.docx) { fichiers.push('DOCX'); } if (fids.gdoc || fids.doc || fids.google_doc) { fichiers.push('Google Doc'); } }
  const sections = (g(s, 'sections') || []).map(sec => ({ titre: g(sec, 'titre', 'title') || '', titre_nl: g(sec, 'titre_nl') || '', etapes: (g(sec, 'etapes', 'steps') || []).map(String) }));
  const nb = g(s, 'nb_etapes', 'nbEtapes') || sections.reduce((a, x) => a + x.etapes.length, 0);
  const ph = g(s, 'nb_photos', 'has_photos', 'photos');
  sops.push({
    id: cle[0], mini: cle[2], court: court(t), titreDrive: t.replace(/\.(pdf|docx)$/i, ''),
    type: g(s, 'type') || 'SOP', langue: String(g(s, 'langue', 'lang') || 'FR').replace('FR-NL', 'NL-FR'),
    version: String(g(s, 'version') || '1.0').replace(/^v/i, ''), statut: /VALIDER/i.test(String(g(s, 'statut') || '') + t) ? 'À valider' : null,
    dossier: String(g(s, 'dossier') || '').slice(0, 2), poste: g(s, 'poste', 'role'), moment: g(s, 'moment'),
    objectif: g(s, 'objectif') || '', sections, nbEtapes: nb, duree: propre(g(s, 'duree')),
    crit: (g(s, 'points_critiques') || []).map(propre), photos: typeof ph === 'number' ? ph : (ph && ph.count) || (ph === true ? 1 : 0),
    materiel: g(s, 'materiel') || [], fichiers: fichiers.length ? fichiers : ['PDF'], decoupe: sections.length > 0,
    quiz: (g(s, 'quiz') || []).map(q => ({ q: g(q, 'question', 'q'), choix: g(q, 'choices', 'choix', 'options') || [], ok: +g(q, 'correct', 'ok', 'answer') || 0 })),
    texte: g(s, 'texte_complet') || null
  });
}
sops.sort((a, b) => a.dossier.localeCompare(b.dossier) || CLES.findIndex(c => c[0] === a.id) - CLES.findIndex(c => c[0] === b.id));
const S = id => sops.find(s => s.id === id);
['fermeture', 'flans'].forEach(id => { if (S(id)) { S(id).revoir = true; } });
sops.forEach(s => { s.quizRelu = !s.statut && !['tgtg', 'eclairs', 'cookies'].includes(s.id); });

// --- L'équipe de Halle : FICTIVE ------------------------------------------------------------------------
// va = vu faire · qz = quiz réussi · lu = lue · no = pas ouverte · rv = à revoir (nouvelle version) ; absent = pas son poste.
const PROG = {
  ev: { 'ouv-mag': 'va', caisse: 'va', tgtg: 'va', recuisson: 'va', fermeture: 'va', calmes: 'va', 'ferm-quot': 'va', fours: 'va' },
  sm: { 'ouv-mag': 'va', caisse: 'va', tgtg: 'va', recuisson: 'va', fermeture: 'rv', calmes: 'va', 'ferm-quot': 'va' },
  ib: { 'ouv-mag': 'qz', caisse: 'lu', tgtg: 'va', recuisson: 'va', fermeture: 'rv', calmes: 'lu', 'ferm-quot': 'qz' },
  jp: { 'ouv-mag': 'va', caisse: 'qz', tgtg: 'no', recuisson: 'lu', fermeture: 'rv', calmes: 'no', 'ferm-quot': 'va' },
  ka: { memo: 'va', programmes: 'va', 'ouv-poste': 'va', plaquage: 'va', vienn: 'va', baguettes: 'lu', 'fin-poste': 'va', fours: 'qz', quiches: 'va', cramiques: 'qz', cookies: 'lu', flans: 'rv', cremes: 'va', 't-fruits': 'va' },
  tw: { recuisson: 'va', programmes: 'va', 'fin-poste': 'qz', fours: 'va', fermeture: 'rv', 'ferm-quot': 'va', calmes: 'va', plaquage: 'lu', memo: 'va' },
  mr: { memo: 'va', cremes: 'va', 't-fruits': 'qz', flans: 'qz', 't-class': 'lu', fondants: 'lu', 't-froides': 'no', eclairs: 'no', cookies: 'no', quiches: 'no' }
};
const matrice = {};
let valides = 0, attendues = 0, aRevoir = 0;
const parSop = {};
for (const p of Object.keys(PROG)) {
  matrice[p] = {};
  for (const s of sops) {
    const st = PROG[p][s.id] || 'na';
    matrice[p][s.id] = st;
    if (st === 'na') { continue; }
    attendues++; if (st === 'va') { valides++; } if (st === 'rv') { aRevoir++; }
    parSop[s.id] = parSop[s.id] || { v: 0, n: 0 }; parSop[s.id].n++; if (st === 'va') { parSop[s.id].v++; }
  }
}
const aValider = [
  ['05/11', 'Mila R.', 't-fruits', 'parcours Production · jour 3'], ['05/11', 'Mila R.', 'flans', 'parcours Production · jour 3'],
  ['04/11', 'Inès B.', 'ouv-mag', 'lue en FR (traduction de travail), l’original est en NL'], ['03/11', 'Tom W.', 'fin-poste', 'demande faite à Elke V.'],
  ['02/11', 'Jana P.', 'caisse', 'week-end : à voir samedi'], ['30/10', 'Karim A.', 'cramiques', 'à la visite production du 12/11 ?'],
  ['29/10', 'Karim A.', 'fours', 'à la visite production du 12/11 ?'], ['31/10', 'Inès B.', 'ferm-quot', '']
].map(a => ({ le: a[0], qui: a[1], sop: S(a[2]) ? S(a[2]).court : a[2], quiz: '3 / 3', note: a[3] || 'en attente depuis ' + a[0] }));
const nomS = id => S(id) ? S(id).court : id;
const fil = [
  { le: '05/11', x: `<b>Mila R.</b> réussit le quiz de <b>${nomS('t-fruits')}</b> et de <b>${nomS('flans')}</b> (3 / 3)`, s: 'reste à être vue faire' },
  { le: '05/11', x: `<b>Drive relu</b> : ${sops.length} SOP, 1 à valider (${nomS('baguettes')} v1.0)`, s: '44 fichiers · PDF et DOCX réunis' },
  { le: '04/11', x: `<b>Inès B.</b> lit <b>${nomS('ouv-mag')}</b> en FR et réussit le quiz`, s: 'l’original est en NL : traduction de travail' },
  { le: '04/11', x: `<b>${nomS('fermeture')} v1.3</b> publiée : 4 personnes passent en « à revoir »`, s: 'Sofie M., Inès B., Jana P., Tom W.' },
  { le: '03/11', x: `<b>Mila R.</b> arrive · parcours <b>Production</b> assigné (10 SOP)`, s: 'par Elke V.' },
  { le: '03/11', x: `<b>Elke V.</b> a vu faire Mila R. : <b>${nomS('cremes')}</b> ✓`, s: '' },
  { le: '31/10', x: `<b>Sam V.</b> a vu faire Karim A. : <b>${nomS('quiches')}</b> ✓`, s: 'visite production' }
];
const mila = PROG.mr, milaIds = ['memo', 'cremes', 't-fruits', 'flans', 't-class', 'fondants', 't-froides', 'eclairs', 'cookies', 'quiches'];
const NOTE = { va: 'vue faire ✓', qz: 'quiz réussi · à voir faire', lu: 'lue · quiz à faire', no: 'à lire', rv: 'nouvelle version · à revoir' };
const tel = {
  parcours: 'Production', faits: milaIds.filter(i => mila[i] === 'va').length, quiz: milaIds.filter(i => ['va', 'qz'].includes(mila[i])).length,
  items: milaIds.map(i => ({ court: nomS(i), st: mila[i], note: NOTE[mila[i]] + (S(i) && S(i).nbEtapes ? ' · ' + S(i).nbEtapes + ' étapes' : '') })),
  quiz2: { court: nomS('t-fruits'), quiz: (S('t-fruits') || {}).quiz || [] }, suivante: nomS('t-class')
};

module.exports = { sops, S, SRC };
if (require.main === module) {
  const extra = require('./preparer-detail.js')({ sops, S, SRC });
  const FO = Object.assign({ lu_le: SRC.lu_le || '2026-11-05', sops: sops.map(s => { const c = Object.assign({}, s); delete c.texte; return c; }),
    halle: { matrice, valides, attendues, aRevoir, taux: Math.round(valides / attendues * 100), parSop, aValider, fil, mila: tel.faits + ' / ' + milaIds.length + ' vus faire' },
    tel, quizExemple: { court: nomS('eclairs'), quiz: (S('eclairs') || {}).quiz || [] },
    // Les écarts relevés en lisant les SOP entre elles (sops.json › remarques) — réels.
    incoherences: [
      { t: 'Caisse : ouverture ou fermeture, on compte ?', d: 'Ouverture du magasin v5 : code 12, valeur 0, on ne compte pas. Fermeture du magasin v1.3 : on compte et on saisit le montant réel (« 12 » et « 0 » marqués TE BEVESTIGEN).', qui: 'vente' },
      { t: 'Pains de la veille : 4 min + 20 % ou 5 min ?', d: 'Ouverture du poste v1.1 et Fiche programmes : +4 min et 20 % d’humidité. Cuisson des baguettes et pains (à valider) : 5 min.', qui: 'cuisson' },
      { t: 'Flan et tarte au sucre : critères inversés', d: 'Fiche programmes v1.0 : flan « centre mobile », tarte au sucre « fermée au toucher ». SOP Flans v1.1 : l’inverse.', qui: 'cuisson', c: 'ko' },
      { t: 'Fermeture quotidienne écrite sur la v1.2', d: 'La checklist dit « gebaseerd op versie 1.2 » : il lui manque les photos Too Good To Go de 17 h de la Fermeture v1.3.', qui: 'fermeture' },
      { t: 'Nettoyage des fours : vers, à, ou après 16 h ?', d: 'Fermeture v1.3 « vers 16 h », checklist « à 16 h », SOP fours « après 16 h ».', qui: 'fermeture' },
      { t: 'Fermeture v1.3 : traduction FR et validation', d: '« koude producten » traduit « produits meublés » ; signal d’alarme à confirmer ; champ « Approuvé par » vide.', qui: 'fermeture' },
      { t: 'Recuisson l’après-midi : vente ou cuisson ?', d: 'Écrite pour les vendeurs, rangée dans 02 Cuisson ; FR et NL identiques sous deux codes (SOP-VEN-001 / SOP-VER-001).', qui: 'classement', c: '' }
    ] }, extra);
  fs.writeFileSync(path.join(__dirname, 'donnees.js'), '/* Généré par preparer.js — ne pas éditer. SOP réelles (Drive, 05/11/2026), équipe fictive. */\nconst FO = ' + JSON.stringify(FO, null, 1) + ';\n');
  console.log(sops.length, 'SOP ·', valides + '/' + attendues, 'vus faire ·', aRevoir, 'à revoir');
}
