/* Vendre › Produits (dashboard.js, rendProduits ; serveur consultant/src/analyse_produits.php, adCalculer) : est-ce que le
 * magasin, chaque catégorie, chaque produit se développe ? Les 6 dernières semaines closes face aux 6 d'avant (Pâtisserie
 * y a cinq références de plus que le catalogue de démo : SUPPL, plus bas). Et
 * l'aperçu du nouveau prix de vente de la fiche produit (POST /analyse/produits/prix, panel/src/prix_produit.php :
 * pxpPlan) — l'aperçu seulement ; la confirmation répond comme le panel, mais les vidéos s'arrêtent à l'aperçu.
 * La fiche elle-même (ventes, prix face au réseau, recette) vient de lib/fiche.js, par 10_ops.js. Données fictives.
 */
const D = require('./lib/demo');
const V = require('./lib/ventes');
const { r2, r1 } = D;

const AVANT = [0, 5], APRES = [6, 11], SEUIL = 8, PEU = 24;

/** Les 13 semaines : les 12 closes et celle en cours (du lundi 16 au jeudi 19 novembre). */
function tranches() {
  const lundi = D.plus(D.AUJ, -((D.dow(D.AUJ) + 6) % 7)), L = [];
  for (let i = 12; i >= 0; i--) { const du = D.plus(lundi, -7 * i), au = i === 0 ? D.AUJ : D.plus(du, 6); L.push([du, au, 'S' + V.semaineIso(du)]); }
  return L;
}
const somme = (a, [i, j]) => a.slice(i, j + 1).reduce((s, x) => s + x, 0);
/** L'évolution d'une série (adEvolution) : par semaine avant et après, l'écart en %, le verdict. */
function evolution(v, q, lib) {
  const nA = AVANT[1] - AVANT[0] + 1, nP = APRES[1] - APRES[0] + 1;
  const av = somme(v, AVANT), ap = somme(v, APRES), qA = somme(q, AVANT), qP = somme(q, APRES);
  const pct = av > 0 ? r1(100 * (ap / av - 1)) : null;
  const vide3 = de => [de, de + 1, de + 2].every(i => !(v[i] > 0));
  let verdict, depuis = null;
  if (ap > 0 && (av < 0.15 * ap || (vide3(AVANT[0]) && av < 0.5 * ap))) {
    verdict = 'nouveau';
    for (let i = AVANT[0]; i <= APRES[1]; i++) { if (v[i] >= 0.25 * ap / nP) { depuis = lib[i]; break; } }
  } else if (av > 0 && (ap < 0.15 * av || vide3(APRES[1] - 2))) {
    verdict = 'arrete';
    for (let i = APRES[1]; i >= AVANT[0]; i--) { if (v[i] > 0) { depuis = lib[i]; break; } }
  } else if (qA + qP < PEU) { verdict = 'peu'; }
  else if (pct != null && pct >= SEUIL) { verdict = 'monte'; }
  else if (pct != null && pct <= -SEUIL) { verdict = 'baisse'; }
  else { verdict = 'stable'; }
  return { avant: r2(av / nA), apres: r2(ap / nP), qAvant: r1(qA / nA), qApres: r1(qP / nP), pct, verdict, depuis };
}

/* La gamme de Pâtisserie, plus large ici qu'au catalogue de démo (26/11/2026, vidéo 11-01-categorie) : la fenêtre d'une
 * catégorie montre ses 12 premiers produits au chiffre, puis « ▾ les N autres produits » ; aucune catégorie de lib/ventes.js
 * n'en a plus de 9. Cinq références de plus, pièces par semaine de S35 à S47 (la dernière : la semaine en cours) : une
 * stable, une qui monte, une tarte de saison arrêtée depuis S41, une nouveauté depuis S45, une trop peu vendue. Fictif. */
const SUPPL = [
  { pid: 951, nom: 'Religieuse au café', cat: 'Pâtisseries individuelles', prix: 3.70, taux: 0.62, q: [43, 44, 42, 45, 44, 46, 43, 45, 47, 44, 46, 45, 21] },
  { pid: 952, nom: 'Chou à la crème', cat: 'Pâtisseries individuelles', prix: 2.40, taux: 0.64, q: [36, 38, 37, 39, 40, 41, 44, 46, 48, 49, 51, 52, 25] },
  { pid: 953, nom: 'Tarte aux prunes (part)', cat: 'Tartes', prix: 3.30, taux: 0.64, q: [42, 40, 41, 38, 36, 30, 9, 0, 0, 0, 0, 0, 0] },
  { pid: 954, nom: 'Bûchette aux marrons', cat: 'Pâtisseries individuelles', prix: 4.50, taux: 0.60, q: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 22, 31, 16] },
  { pid: 955, nom: 'Opéra (part)', cat: 'Pâtisseries individuelles', prix: 4.40, taux: 0.60, q: [1, 2, 1, 0, 2, 1, 2, 1, 2, 2, 1, 2, 1] },
].map(s => Object.assign({ groupe: 'Pâtisserie', vie: 'S' }, s));

function developpement() {
  const T = tranches(), n = T.length, lib = T.map(t => t[2]), parT = T.map(([du, au]) => V.periode(du, au));
  const G = {}, tot = { v: Array(n).fill(0), q: Array(n).fill(0) }, produits = [];
  const series = p => p.pid > 900
    ? { q: p.q.map(x => r1(x)), v: p.q.map(x => r2(x * p.prix * V.REMISE)) }
    : { v: parT.map(x => r2((x[p.pid] || {}).v || 0)), q: parT.map(x => r1((x[p.pid] || {}).q || 0)) };
  V.P.concat(SUPPL).forEach(p => {
    const { v, q } = series(p);
    if (!v.some(x => x > 0)) { return; }
    const g = G[p.groupe] || (G[p.groupe] = { nom: p.groupe, n: 0, v: Array(n).fill(0), q: Array(n).fill(0) });
    g.n++;
    for (let i = 0; i < n; i++) { g.v[i] += v[i]; g.q[i] += q[i]; tot.v[i] += v[i]; tot.q[i] += q[i]; }
    const e = evolution(v, q, lib), ht = V.prixHt(p), c = V.cout(p);
    const qa = somme(q, APRES), va = somme(v, APRES);
    produits.push({ id: p.pid, nom: p.nom, cat: p.cat, groupe: p.groupe, v, q, evo: e, prix: p.prix, prixHt: ht, cout: c,
      prixEncaisse: qa > 0 ? r2(va / qa) : null,
      marge: c != null ? r1(100 * (ht - c) / ht) : null, margeSemaine: c != null ? r2(e.qApres * (ht - c)) : null, margeMotif: c != null ? null : 'coût de la recette manquant' });
  });
  const s12 = a => a.slice(0, APRES[1] + 1).reduce((s, x) => s + x, 0);
  produits.sort((a, b) => s12(b.v) - s12(a.v));
  const arr = (a, f) => a.map(f);
  const groupes = Object.values(G).map(g => ({ nom: g.nom, n: g.n, v: arr(g.v, r2), q: arr(g.q, r1), evo: evolution(g.v, g.q, lib) })).sort((a, b) => s12(b.v) - s12(a.v));
  return { shop: +D.SHOP, magasin: D.NOM,
    semaines: { tranches: lib, bornes: T.map(t => [t[0], t[1]]), jours: T.map(t => Math.round((D.jourDe(t[1]) - D.jourDe(t[0])) / 864e5) + 1), muettes: [], complet: true },
    regle: { avant: AVANT, apres: APRES, seuil: SEUIL, peu: PEU },
    total: { v: arr(tot.v, r2), q: arr(tot.q, r1), evo: evolution(tot.v, tot.q, lib) },
    groupes, produits };
}

/** L'aperçu (ou l'envoi) d'un nouveau prix de vente : le plan de pxpPlan, avec le maximum du réseau du produit. */
function prix(c) {
  const p = V.produit(c && c.pid);
  if (!p) { return { __status: 404, json: { error: 'Ce produit n’est pas dans la liste du magasin au panel : son prix ne se règle pas d’ici.' } }; }
  const prixHt = Math.round(+c.prixHt * 10000) / 10000, tva = V.TVA, part = 1, avant = p.prix;
  const max = Math.round(p.prix * 1.22 * 20) / 20;                     // le prix maximum du réseau : 22 % au-dessus du prix du magasin
  const ecrit = Math.round(Math.round(prixHt * (1 + tva / 100) * part * 20) / 20 * 100) / 100;
  const plan = { shop: +D.SHOP, pid: p.pid, nom: p.nom, prixHt, tva, part, avant, avantPiece: avant, ecrit, pieceTtc: ecrit, pieceHt: r2(ecrit / (1 + tva / 100)), max,
    change: Math.abs(ecrit - avant) >= 0.005, erreur: null, bloque: null };
  const eur = v => v.toFixed(2).replace('.', ',') + ' €';
  if (ecrit > max + 0.005) { plan.erreur = 'Au-dessus du prix maximum du réseau : ' + eur(ecrit) + ' TTC demandé, ' + eur(max) + ' TTC au plus.'; plan.bloque = 'max'; }
  else if (!plan.change) { plan.erreur = 'Le panel porte déjà ce prix : ' + eur(ecrit) + ' TTC.'; plan.bloque = 'inchange'; }
  if (c.apercu) { return Object.assign({ ok: !plan.erreur, apercu: true }, plan); }
  if (plan.erreur) { return { __status: 422, json: Object.assign({ error: plan.erreur }, plan) }; }
  return Object.assign({ ok: true, resultat: 'ok', message: '', relu: ecrit }, plan);
}

module.exports = function (m, p, q, c) {
  if (m === 'GET' && p === '/analyse/developpement/magasin') { return developpement(); }
  if (m === 'POST' && p === '/analyse/produits/prix') { return prix(c); }
  return undefined;
};
