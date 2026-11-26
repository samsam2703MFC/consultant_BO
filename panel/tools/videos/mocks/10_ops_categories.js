/* Opérationnel › la fenêtre d'une catégorie ou d'un groupe (le « i » des Ventes par catégorie, c12Ouvrir dans
 * dashboard.js), pour la vidéo 01-10-categorie. Données fictives, tirées des ventes de démo (lib/ventes.js) :
 *   /analyse/categories/magasin   les 12 semaines closes et l'en cours, face à la moyenne par magasin du réseau
 *                                 (la forme de ep_analyse_categorie_magasin, consultant/src/analyse_produits.php) ;
 *   /analyse/categories/journee   la journée heure par heure face aux 6 mêmes jours d'avant, au niveau groupe ou
 *                                 catégorie. 00_analyse.js ne répond qu'aux lectures de l'Analyse (`_cache=900`) ; celle
 *                                 de la fenêtre d'Opérationnel passe sans `_cache` et arrive ici.
 */
const D = require('./lib/demo');
const V = require('./lib/ventes');
const { r2, r1 } = D;

const NB = 13;   // 12 semaines closes + l'en cours, comme apTranches(3) côté serveur
/** Les tranches : du lundi au dimanche, la dernière coupée à aujourd'hui ; libellé « S47 ». */
function tranches() {
  const lundi = D.plus(D.AUJ, -((D.dow(D.AUJ) + 6) % 7)), L = [];
  for (let i = NB - 1; i >= 0; i--) {
    const du = D.plus(lundi, -7 * i), fin = D.plus(du, 6), au = fin < D.AUJ ? fin : D.AUJ;
    L.push([du, au, 'S' + V.semaineIso(du)]);
  }
  return L;
}
const dans = (niveau, nom) => p => (niveau === 'groupe' ? p.groupe : p.cat) === nom;

function semaines(q) {
  const niveau = q.get('niveau') === 'groupe' ? 'groupe' : 'categorie', nom = q.get('nom') || '';
  const P = V.P.filter(dans(niveau, nom));
  if (!P.length) { return { shop: +D.SHOP, niveau, nom, indispo: true, motif: 'aucune vente de « ' + nom + ' » sur ces semaines' }; }
  const T = tranches();
  const mag = [], magQ = [];
  T.forEach(([du, au]) => {
    const S = V.periode(du, au);
    mag.push(r2(P.reduce((a, p) => a + ((S[p.pid] || {}).v || 0), 0)));
    magQ.push(r1(P.reduce((a, p) => a + ((S[p.pid] || {}).q || 0), 0)));
  });
  // Le réseau : la moyenne par magasin, un peu sous le magasin de démo, plus plate (sa tendance n'est pas la nôtre).
  const clos = mag.slice(0, -1), moy = clos.reduce((a, v) => a + v, 0) / clos.length;
  const closQ = magQ.slice(0, -1), moyQ = closQ.reduce((a, v) => a + v, 0) / closQ.length;
  const jours = T.map(([du, au]) => Math.round((D.jourDe(au) - D.jourDe(du)) / 864e5) + 1);
  const k = i => 0.9 * (1 + 0.04 * (i - (NB - 2) / 2) / NB) * D.bruit('c12r' + nom + i, 0.05) * (jours[i] < 7 ? mag[i] / Math.max(1, moy) : 1);
  const res = T.map((t, i) => r2(moy * k(i))), resQ = T.map((t, i) => r1(moyQ * k(i)));
  const cats = niveau === 'groupe' ? [...new Set(P.map(p => p.cat))].sort((a, b) => a.localeCompare(b, 'fr')) : [];
  return { shop: +D.SHOP, niveau, nom, groupe: niveau === 'categorie' ? P[0].groupe : null, categories: cats,
    semaines: { tranches: T.map(t => t[2]), bornes: T.map(t => [t[0], t[1]]), jours, magasin: mag, reseau: res, magasinQ: magQ, reseauQ: resQ, muettes: [], magasins: 9 } };
}

/* La part de chaque heure, de 6 h à 17 h, par groupe (la même que 00_analyse.js). */
const PROFIL = {
  Viennoiserie: [0.06, 0.17, 0.18, 0.15, 0.11, 0.07, 0.05, 0.04, 0.04, 0.05, 0.05, 0.03], Pains: [0.04, 0.11, 0.12, 0.1, 0.09, 0.1, 0.08, 0.06, 0.06, 0.08, 0.1, 0.06],
  Pâtisserie: [0.01, 0.02, 0.04, 0.05, 0.07, 0.09, 0.1, 0.11, 0.13, 0.15, 0.14, 0.09], Snacking: [0.0, 0.02, 0.03, 0.04, 0.08, 0.26, 0.28, 0.14, 0.06, 0.04, 0.03, 0.02],
  Boissons: [0.05, 0.14, 0.15, 0.13, 0.1, 0.09, 0.08, 0.06, 0.06, 0.06, 0.05, 0.03], Biscuiterie: [0.01, 0.04, 0.06, 0.07, 0.08, 0.1, 0.11, 0.11, 0.12, 0.12, 0.11, 0.07],
};
function journee(q) {
  const niveau = q.get('niveau') === 'groupe' ? 'groupe' : 'categorie', nom = q.get('nom') || '', date = q.get('date') || D.AUJ;
  const P = V.P.filter(dans(niveau, nom));
  if (!P.length) { return { shop: +D.SHOP, niveau, nom, date, indispo: true, motif: 'aucune vente de « ' + nom + ' » ce jour' }; }
  const prof = PROFIL[P[0].groupe] || PROFIL.Viennoiserie;
  const somme = d => { const J = V.jour(d); return P.reduce((a, p) => { const o = J[p.pid] || { q: 0, v: 0 }; a.v += o.v; a.q += o.q; return a; }, { v: 0, q: 0 }); };
  const jours = []; for (let i = 1; i <= 6; i++) { jours.push(D.plus(date, -7 * i)); }
  const auj = date === D.AUJ, t = D.MAINTENANT / 60;
  // Aujourd'hui, le vendu jusqu'à 15 h 40 se répartit sur les heures passées (l'heure entamée au prorata) : rien après.
  const poidsJ = prof.map((w, i) => auj ? w * Math.max(0, Math.min(1, t - (6 + i))) : w), totJ = poidsJ.reduce((a, w) => a + w, 0) || 1;
  const moy = jours.map(somme).reduce((a, o) => ({ v: a.v + o.v / 6, q: a.q + o.q / 6 }), { v: 0, q: 0 });
  // Aujourd'hui : un peu mieux que d'habitude à la même heure (la journée de démo l'est aussi, lib/demo.js).
  const fait = totJ / prof.reduce((a, w) => a + w, 0), k = 1.06 * D.bruit('c12k' + nom, 0.04);
  const du = auj ? { v: moy.v * fait * k, q: moy.q * fait * k } : somme(date);
  const heures = prof.map((w, i) => {
    const h = 6 + i, b = poidsJ[i] / totJ * D.bruit('c12h' + nom + date + h, 0.12), m = w * D.bruit('c12m' + nom + date + h, 0.06);
    return { h, ca: r2(du.v * b), q: r1(du.q * b), moyCa: r2(moy.v * m), moyQ: r1(moy.q * m) };
  });
  return { shop: +D.SHOP, niveau, nom, date, jourNom: D.JOURS[D.dow(date)], maintenant: auj ? D.hm(D.MAINTENANT) : null, lu: true, jours, manquants: [], heures };
}

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/analyse/categories/magasin') { return semaines(q); }
  if (p === '/analyse/categories/journee') { return journee(q); }
  return undefined;
};
