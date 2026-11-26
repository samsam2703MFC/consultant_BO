/* La barre du haut › les fenêtres communes d'Opérationnel (vidéos 01-26 et 01-29) : ce que 10_ops.js ne sert pas.
 *   GET  /analyse/matieres/fiche?mid=&shop=        une matière de la recette (fiche produit › Recette & marge) : son
 *                                                  prix ici, dans le réseau, chez ses fournisseurs (panel/src/matiere_fiche.php) ;
 *   GET  /exploitation/invendus/actions?pid=…      une saisie Qualité ou Casse (Invendus et poubelle) : la recette, les
 *                                                  références réclamables, qui a produit, l'équipe (consultant/src/invendus.php) ;
 *   POST /fournisseurs/reclamation                 la réclamation envoyée (panel/src/reclamations_magasin.php) ;
 *   POST /equipe/remarques                         la remarque à l'opérateur (consultant/src/invendus.php).
 * Aucun autre fichier de mocks/ ne répond à ces routes. Fournisseurs, références, livraisons et prénoms : fictifs. */
const D = require('./lib/demo');

/* --- la fiche d'une matière (les lignes « matière » des recettes de lib/fiche.js : 700, 701, 702) --- */
const MATIERES = {
  700: { nom: 'Farine de tradition', cat: 'Farines', par: 'kg', prix: 0.92, f: 0.89 },
  701: { nom: 'Garniture', cat: 'Charcuterie et garnitures', par: 'kg', prix: 18.0, f: 17.4 },
  702: { nom: 'Beurre et sauce', cat: 'Crèmerie', par: 'kg', prix: 12.0, f: 11.6 },
};
function matiere(q) {
  const mid = +(q.get('mid') || 0), M = MATIERES[mid] || MATIERES[701];
  const k = [['Magasin 2', 0.94], ['Magasin 6', 0.97], ['Magasin 3', 0.99], [D.NOM, 1], ['Magasin 1', 1.03], ['Magasin 5', 1.06]];
  const vals = k.map(([, x]) => M.prix * x).sort((a, b) => a - b), med = (vals[2] + vals[3]) / 2, r4 = v => Math.round(v * 10000) / 10000;
  const ec = v => Math.round(1000 * (v - med) / med) / 10;
  return { mid, nom: M.nom, cat: M.cat, uniteBase: M.par, par: M.par, facteur: 1, perte: null, typeMatiere: 'RAW', sourceType: 'OPEN', sourceCentrale: false, integre: false, tva: 6,
    conseille: null, reference: null, repere: r4(med), repereNom: 'médiane du réseau',
    magasin: { id: D.SHOP, nom: D.NOM, court: D.NOM, prix: M.prix, sansPrix: false, absente: false, ecart: ec(M.prix) },
    reseau: k.map(([nom, x], i) => ({ id: String(i + 1), nom, court: nom, ceMagasin: nom === D.NOM, lu: true, prix: r4(M.prix * x), ecart: ec(M.prix * x) })),
    stats: { n: k.length, min: r4(vals[0]), max: r4(vals[vals.length - 1]), med: r4(med) },
    fournisseurs: [
      { id: '31', nom: 'Grossiste Démo SRL', type: 'WHOLESALER', typeNom: 'grossiste', ville: 'Bois-Joli', integre: false, centrale: false, via: 'catalogue', sku: 'GD-' + (4400 + mid), nomCatalogue: M.nom, listeLue: true,
        colis: { prix: r4(M.f * 2), taille: 2, unite: M.par, parColis: 1, tva: 6 }, parUnite: M.f, depuis: '2026-10-01', enVigueur: true, actif: true, ecart: Math.round(1000 * (M.f - M.prix) / M.prix) / 10,
        prochain: { prix: r4(M.f * 2 * 1.03), parUnite: r4(M.f * 1.03), depuis: '2026-12-01' } },
    ],
    fournisseursLus: true, lu: { magasin: '2026-11-19T15:38:00+01:00', age: 120, secondesPrix: 600, heuresReseau: 24, minutesMagasin: 10 },
    source: 'API du panel (fausse API des vidéos) : prix du magasin et du réseau, listes de prix des fournisseurs' };
}

/* --- agir sur une saisie d'invendus : réclamer au fournisseur, ou une remarque à l'opérateur --- */
const FOURN = [{ id: '30', nom: 'Fournil Démo SA' }, { id: '31', nom: 'Grossiste Démo SRL' }];
const REFS = [
  { id: '5101', nom: 'Fondant chocolat noir (seau de 5 kg)', sku: 'GD-2210', fournisseur: '31', idUnite: 3, unite: 'kg', prix: 9.6 },
  { id: '5102', nom: 'Crème pâtissière en poudre (sac de 2 kg)', sku: 'GD-1180', fournisseur: '31', idUnite: 3, unite: 'kg', prix: 6.2 },
  { id: '5001', nom: 'Croissant au beurre cru 70 g', sku: 'FD-0070', fournisseur: '30', idUnite: 1, unite: 'pièce', prix: 0.28 },
  { id: '5002', nom: 'Pain au chocolat cru 75 g', sku: 'FD-0075', fournisseur: '30', idUnite: 1, unite: 'pièce', prix: 0.32 },
  { id: '5103', nom: 'Farine de tradition (sac de 25 kg)', sku: 'GD-0025', fournisseur: '31', idUnite: 3, unite: 'kg', prix: 0.92 },
];
const LIVRAISONS = [
  { id: '88120', cle: 'CMD-2026-088120', fournisseur: '31', le: '2026-11-16', attendue: '2026-11-18', statut: 'DELIVERED', source: 'en cours' },
  { id: '88054', cle: 'CMD-2026-088054', fournisseur: '31', le: '2026-11-09', attendue: '2026-11-11', statut: 'DELIVERED', source: 'en cours' },
  { id: '88131', cle: 'CMD-2026-088131', fournisseur: '30', le: '2026-11-17', attendue: '2026-11-19', statut: 'DELIVERED', source: 'en cours' },
  { id: '88098', cle: 'CMD-2026-088098', fournisseur: '30', le: '2026-11-12', attendue: '2026-11-14', statut: 'DELIVERED', source: 'en cours' },
];
const MOTIFS = [['product_quality', 'Qualité du produit'], ['broken_packaging', 'Emballage abîmé'], ['size_or_weight_issue', 'Taille ou poids'],
  ['lack_of_conformity', 'Non conforme à la commande'], ['incorrect_quantity_of_products_in_package', 'Quantité dans l’emballage'], ['incorrect_information', 'Informations incorrectes'], ['other', 'Autre raison']]
  .map(([code, nom]) => ({ code, nom }));
const OPERATEURS = [{ id: 4, nom: 'Camille R.' }, { id: 2, nom: 'Julie M.' }, { id: 6, nom: 'Lucas V.' }, { id: 1, nom: 'Thomas D.' }];
/** Par produit : les matières de sa recette chez un fournisseur réclamable, et qui l'a produit ce jour. */
const PRODUITS = {
  'Éclair au chocolat': { lignes: [['5101', 'Fondant chocolat noir', 0.28], ['5102', 'Crème pâtissière en poudre', 0.34]], producteur: { id: 6, nom: 'Lucas V.', source: 'journal de production' } },
  'Croissant au beurre': { lignes: [['5001', 'Croissant au beurre cru 70 g', 1]], producteur: { id: 2, nom: 'Julie M.', source: 'journal de production' } },
};
const remarques = [];
function actions(q) {
  const pid = +(q.get('pid') || 0), prod = (D.PRODUITS.find(p => +p.pid === pid) || {}).nom || '', P = PRODUITS[prod] || { lignes: [], producteur: null };
  const cands = P.lignes.map(([id, , part]) => Object.assign({ part }, REFS.find(r => r.id === id)));
  return { shop: +D.SHOP, date: q.get('date') || D.AUJ, pid, produit: prod,
    recette: { lue: true, sansRecette: false, lignes: P.lignes.map(([id, nom, part]) => ({ id: +id, nom, part, fournisseur: null })) },
    reclamation: { indispo: false, motif: null, motifs: MOTIFS, candidates: cands, acheteFini: P.lignes.length === 1 && cands.length === 1,
      matieres: REFS, livraisons: LIVRAISONS, fournisseurs: FOURN, motifSuggere: 'product_quality' },
    producteur: P.producteur, operateurs: OPERATEURS, remarques: remarques.filter(r => r.pid === pid), source: 'fausse API des vidéos' };
}
let nReclamation = 7730;
function reclamer(c) {
  c = c || {};
  if (!c.idMatiere || !c.idLivraison || !(c.quantite > 0) || !c.motif) { return { __status: 422, json: { error: 'il manque : la référence, la livraison, la quantité ou le motif' } }; }
  if (!Array.isArray(c.photos) || !c.photos.length) { return { __status: 422, json: { error: 'ajoutez au moins une photo : sans photo, le fournisseur ne peut pas traiter la réclamation' } }; }
  nReclamation++;
  return { ok: true, id: nReclamation, photos: c.photos.map((x, i) => 'uploads/reclamations/demo-' + nReclamation + '-' + (i + 1) + '.jpg') };
}
function remarquer(c) {
  c = c || {};
  const texte = String(c.texte || '').trim(), nom = String(c.employeNom || '').trim();
  if (!texte) { return { __status: 422, json: { error: 'un mot, au moins : la remarque est vide' } }; }
  if (!c.employeId && !nom) { return { __status: 422, json: { error: 'à qui ? choisissez l’opérateur' } }; }
  const r = { id: 900 + remarques.length + 1, employeId: c.employeId || null, employe: nom, le: c.le || D.AUJ, heure: c.heure || null, pid: c.pid ? +c.pid : null, produit: c.produit || '',
    pieces: c.pieces != null ? +c.pieces : null, motif: c.motif || '', motifLib: c.motif === 'damage' ? 'Casse' : 'Qualité', texte: texte.slice(0, 1000), source: 'invendus', saisieId: c.saisieId || null,
    auteur: c.auteur || '', creeLe: '2026-11-19 15:42:00' };
  remarques.push(r);
  return { ok: true, id: r.id, remarque: r };
}

module.exports = function (m, p, q, c) {
  if (m === 'GET' && p === '/analyse/matieres/fiche') { return matiere(q); }
  if (m === 'GET' && p === '/exploitation/invendus/actions') { return actions(q); }
  if (m === 'POST' && p === '/fournisseurs/reclamation') { return reclamer(c); }
  if (m === 'POST' && p === '/equipe/remarques') { return remarquer(c); }
  return undefined;
};
