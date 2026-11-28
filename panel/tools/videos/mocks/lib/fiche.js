/* La fiche d'un produit (ventes 12 semaines, prix face au réseau, recette) et le détail des invendus, magasin de démo. */
const D = require('./demo');
const { r2, r1 } = D;
const RECETTES = {
  Viennoiserie: [['Pâte levée feuilletée', 'recette', 70, 'g', 0.22], ['Beurre de tourage', 'matiere', 22, 'g', 0.19], ['Dorure (œuf)', 'matiere', 4, 'g', 0.02]],
  Pains: [['Farine de tradition', 'matiere', 180, 'g', 0.16], ['Levain', 'recette', 40, 'g', 0.03], ['Sel', 'matiere', 4, 'g', 0.01]],
  Pâtisserie: [['Pâte sucrée', 'recette', 50, 'g', 0.21], ['Crème pâtissière', 'recette', 60, 'g', 0.34], ['Fruits et garniture', 'matiere', 40, 'g', 0.42]],
  Snacking: [['Pain baguette', 'recette', 120, 'g', 0.36], ['Garniture', 'matiere', 80, 'g', 1.12], ['Beurre et sauce', 'matiere', 15, 'g', 0.14]],
  Biscuiterie: [['Pâte à cookie', 'recette', 60, 'g', 0.38], ['Chocolat noir 64 %', 'matiere', 12, 'g', 0.17]],
  Boissons: [['Café en grains', 'matiere', 8, 'g', 0.17], ['Lait entier', 'matiere', 12, 'cl', 0.12]],
};
function produitDe(pid) { return D.PRODUITS.find(p => String(p.pid) === String(pid)) || D.PRODUITS[0]; }
function fiche(pid, mois) {
  const p = produitDe(pid), semQ = p.q * 7 * 0.97;
  const magasin = [], reseau = [], tranches = [], jours = [];
  // 13 semaines, comme le serveur (consultant/src/analyse_produits.php) : 12 closes et l'en cours (26/11/2026 : avec 12,
  // « 6 dernières face aux 6 d'avant » comparait 6 semaines à 5).
  for (let i = 12; i >= 0; i--) {
    const lun = D.plus('2026-11-16', -7 * i);
    tranches.push('S' + (47 - i)); jours.push(i === 0 ? 4 : 7);
    const k = i === 0 ? 4 / 7 : 1;
    magasin.push(Math.round(semQ * D.bruit('fm' + pid + i, 0.12) * (1 + (12 - i) * 0.008) * k));
    reseau.push(Math.round(semQ * 0.92 * D.bruit('fr' + pid + i, 0.07) * k));
  }
  const pHt = p.prix / 1.06 * 0.985, med = r2(p.prix * 1.02 / 1.06), v10k = r1(semQ * 4.3 / 6.2);
  const autres = [['Magasin 1', 1.04, 0.92], ['Magasin 2', 0.97, 1.05], ['Magasin 3', 1.02, 0.98], ['Magasin 5', 1.06, 0.86], ['Magasin 6', 0.99, 1.12]];
  const magasins = [{ nom: D.NOM, moi: true, p: r2(pHt), brut: p.prix, v10k, q: Math.round(semQ * 4.3) }].concat(autres.map(([nom, kp, kv]) => ({ nom, p: r2(med * kp), brut: r2(p.prix * kp), v10k: r1(v10k * kv), q: Math.round(semQ * 4.3 * kv) })));
  const ps = magasins.map(x => x.p), vs = magasins.map(x => x.v10k);
  return { pid: String(p.pid), nom: p.nom, cat: p.cat, semaines: { magasin, reseau, tranches, jours, magasinQ: magasin, reseauQ: reseau },
    prix: { periode: mois === 12 ? '12 derniers mois' : (mois === 3 ? '3 derniers mois' : 'dernier mois'),
      magasin: { p: r2(pHt), brut: p.prix, ec: r1(100 * (pHt - med) / med), q: Math.round(semQ * 4.3), v10k, rel: 6.4, auMed: r2((med - pHt) * semQ * 4.3), peu: false },
      reseau: { med, min: Math.min(...ps), max: Math.max(...ps), volMin: Math.min(...vs), volMax: Math.max(...vs), volMoyen: r1(vs.reduce((a, v) => a + v, 0) / vs.length) }, magasins } };
}
function recette(pid) {
  const p = produitDe(pid), L = RECETTES[p.groupe] || RECETTES.Viennoiserie;
  const cible = p.prix / 1.06 * (1 - p.taux), tot = L.reduce((a, l) => a + l[4], 0), k = cible / tot;
  const lignes = L.map(([nom, type, qte, unite, cout], i) => ({ id: type === 'matiere' ? 700 + i : null, nom, type, qte, unite, cout: r2(cout * k), prixSource: 'magasin', cat: type === 'matiere' ? 'Matière première' : 'Sous-recette' }));
  return { api: true, total: r2(lignes.reduce((a, l) => a + l.cout, 0)), recette: { nom: p.nom, rendement: 1 }, lignes, cout: { net: r2(cible), source: 'panel' }, sansPrix: 0 };
}
function invendusDetail(s) {
  const S = [[1, '13:05', 'Thomas D.', 'Éclair au chocolat', 'Pâtissieries individuelles', 3, 'quality', 'Qualité'], [2, '14:20', 'Julie M.', 'Croissant au beurre', 'Croissants', 4, 'damage', 'Casse'],
    [3, '08:10', 'Julie M.', 'Pain aux raisins', 'Pains au chocolat', 5, 'carryover', 'Report de la veille'], [4, '07:15', 'Camille R.', 'Baguette tradition', 'Baguettes', 12, 'expiration', 'Fin de journée'],
    [5, '07:16', 'Camille R.', 'Tarte aux pommes (part)', 'Tartes', 6, 'expiration', 'Fin de journée'], [6, '11:30', 'Lucas V.', 'Cookie chocolat', 'Biscuits et cookies', 2, 'tasting', 'Dégustation']];
  const saisies = S.map(([id, heure, operateur, produit, categorie, pieces, motif, motifLib]) => ({ id, heure, operateur, operateurId: id, produit, categorie: categorie.replace('Pâtissieries', 'Pâtisseries'), pid: (D.PRODUITS.find(x => x.nom === produit) || {}).pid, pieces, motif, motifLib, le: s }));
  const ops = {}; saisies.forEach(x => { const o = ops[x.operateur] || (ops[x.operateur] = { operateur: x.operateur, pieces: 0, saisies: 0 }); o.pieces += x.pieces; o.saisies++; });
  const pc = saisies.reduce((a, x) => a + x.pieces, 0);
  return { date: s, saisies, saisiesPieces: pc, pieces: pc, references: saisies.length, cout: 31, produits: [], journal: {}, parOperateur: Object.values(ops), motifsQualite: ['quality', 'damage'], remarques: [] };
}
module.exports = { fiche, recette, invendusDetail };
