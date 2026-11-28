/* Fidélité (vue=fidelite) : les routes que lit la page Fidélité de panel/public/dashboard/dashboard.js (rendFidelite,
 * rendCartes, rendFideliteClient), aux formes de marketing/src/fidelite_profils.php (profils, tendance, part scannée,
 * fiche d'un client) et marketing/src/fidelite_cartes.php (cartes de fidélité de l'ERP). Tout est fictif : 612 clients
 * reconnus en caisse sur 90 jours, six profils (les réglages par défaut du réseau), des prénoms inventés, deux cartes à
 * timbres, un réseau de magasins aux noms génériques. Depuis le 28/11/2026 (les cartes par public) : une carte à points,
 * trois sociétés et leurs personnes, et le bingo d'une saison fictive (GET /fidelite/cartes/bingo), semaine 4 en cours. */
'use strict';
const D = require('./lib/demo');

const AUJ = D.AUJ;
const AXES = { frequence: 'Fréquence', recence: 'Récence', anciennete: 'Ancienneté', panier: 'Panier', diversite: 'Diversité', comptoir: 'Comptoir' };
const FAMILLES = { pain: 'Pain', vienno: 'Viennoiserie', antigaspi: 'Anti-gaspi', tartes: 'Tartes & fêtes', gouter: 'Goûter', sale: 'Salé' };
// Les six profils par défaut (FP_DEFAUTS), du plus fidèle au profil de base : [id, nom, n, unité, couleur, seuil, condition, équivalent].
const PROFILS = [
  [1, 'Fanbase', 3, 'semaine', '#8D1D2C', 12, '3 fois par semaine ou plus', '12 passages par mois ou plus'],
  [2, 'Fidèle', 2, 'semaine', '#B03048', 8, '2 fois par semaine', '8 à 11 passages par mois'],
  [3, 'Hebdo', 1, 'semaine', '#B0792A', 4, '1 fois par semaine', '4 à 7 passages par mois'],
  [4, 'Régulier', 2, 'mois', '#3f7a52', 2, '2 à 3 fois par mois', '2 à 3 passages par mois'],
  [5, 'Occasionnel', 1, 'mois', '#1B6FA8', 1, '1 fois par mois', '1 passage par mois'],
  [6, 'Découverte', 0, 'base', '#9a948b', 0, 'moins d’1 fois par mois', 'moins d’1 passage par mois'],
].map(([id, nom, n, unite, couleur, seuil, condition, equivalent]) => ({ id, nom, n, unite, couleur, actif: true, seuil, condition, equivalent }));
// Par profil : clients, part du CA scanné, panier, % en commande, radar, familles.
const STATS = {
  1: { clients: 14, partCa: 9.8, panier: 8.4, commande: 4, radar: { frequence: 100, recence: 96, anciennete: 88, panier: 14, diversite: 58, comptoir: 96 }, familles: { pain: 46, vienno: 31, antigaspi: 3, tartes: 4, gouter: 6, sale: 10 } },
  2: { clients: 31, partCa: 13.1, panier: 9.1, commande: 7, radar: { frequence: 74, recence: 92, anciennete: 81, panier: 15, diversite: 61, comptoir: 93 }, familles: { pain: 41, vienno: 29, antigaspi: 4, tartes: 6, gouter: 8, sale: 12 } },
  3: { clients: 68, partCa: 19.6, panier: 11.3, commande: 12, radar: { frequence: 41, recence: 84, anciennete: 72, panier: 19, diversite: 66, comptoir: 88 }, familles: { pain: 33, vienno: 27, antigaspi: 5, tartes: 12, gouter: 9, sale: 14 } },
  4: { clients: 97, partCa: 18.2, panier: 13.2, commande: 18, radar: { frequence: 21, recence: 71, anciennete: 63, panier: 22, diversite: 58, comptoir: 82 }, familles: { pain: 27, vienno: 24, antigaspi: 6, tartes: 18, gouter: 10, sale: 15 } },
  5: { clients: 126, partCa: 15.4, panier: 14.6, commande: 22, radar: { frequence: 10, recence: 55, anciennete: 44, panier: 24, diversite: 49, comptoir: 78 }, familles: { pain: 22, vienno: 21, antigaspi: 7, tartes: 24, gouter: 11, sale: 15 } },
  6: { clients: 276, partCa: 23.9, panier: 15.8, commande: 26, radar: { frequence: 3, recence: 38, anciennete: 12, panier: 26, diversite: 37, comptoir: 74 }, familles: { pain: 19, vienno: 22, antigaspi: 9, tartes: 26, gouter: 10, sale: 14 } },
};
const N = 612;
const MOYENNE = { radar: { frequence: 14, recence: 54, anciennete: 35, panier: 23, diversite: 47, comptoir: 79 }, familles: { pain: 25, vienno: 23, antigaspi: 7, tartes: 20, gouter: 10, sale: 15 } };

/* Les clients qui reviennent (au-dessus du profil de base), prénoms inventés. */
const PRENOMS = ['Sophie L.', 'Marc D.', 'Nadia B.', 'Julien P.', 'Claire V.', 'Hélène R.', 'Karim A.', 'Isabelle M.', 'Pierre G.', 'Laura F.', 'Thomas B.', 'Anne-Marie C.',
  'Mehdi K.', 'Chantal W.', 'Olivier S.', 'Céline T.', 'Bruno H.', 'Valérie N.', 'Yasmine E.', 'Philippe J.', 'Martine L.', 'Stéphane Q.', 'Émilie O.', 'Jean-Luc P.',
  'Sarah Z.', 'Didier M.', 'Aline R.', 'Romain C.', 'Fatima H.', 'Bernard V.'];
const PROFIL_DE = r => (PROFILS.find(p => p.unite !== 'base' && r >= p.seuil) || PROFILS[5]).id;
const CLIENTS = PRENOMS.map((nom, i) => {
  const a = D.alea('cli' + i);
  const rythme = D.r1(i < 3 ? 13 + a() * 4 : i < 8 ? 8 + a() * 3 : i < 18 ? 4 + a() * 3.6 : i < 25 ? 2 + a() * 1.8 : 1 + a() * 0.8);
  const tk = Math.round(rythme * 3 * (0.9 + a() * 0.3)), panier = 7 + a() * 10;
  const f = STATS[PROFIL_DE(rythme)].familles, familles = {};
  Object.keys(f).forEach(k => { familles[k] = Math.max(0, Math.round(f[k] * (0.6 + a() * 0.8))); });
  return { client: 50100 + i * 7, nom, profil: PROFIL_DE(rythme), rythme, passagesFenetre: Math.round(rythme), depuis: Math.floor(a() * (rythme > 4 ? 4 : 12)),
    eur: D.r2(tk * panier), tk, cmd: Math.round(tk * a() * 0.2), familles };
}).sort((x, y) => y.rythme - x.rythme);

function profilsMagasin() {
  const P = PROFILS.map(p => Object.assign({}, p, STATS[p.id], { part: D.r1(100 * STATS[p.id].clients / N), dernierPassage: p.id < 4 ? 2 : 9 }));
  return { shop: D.SHOP, du: D.plus(AUJ, -89), au: AUJ, fenetre: 30, memoire: 90, axes: AXES, familles: FAMILLES, scannes: N, tickets: 13480, identifies: 2140, joursLus: 90,
    aLire: 0, sansFamilles: 0, profils: P, moyenne: MOYENNE, clients: CLIENTS };
}
function scan() {
  const j = D.journee(AUJ, D.MAINTENANT), t = j.tickets, i = Math.round(t * 0.186);
  return { shop: D.SHOP, jour: { du: AUJ, tickets: t, identifies: i, jours: 1, part: D.r1(100 * i / t) },
    mois: { du: '2026-11-01', tickets: 2486, identifies: 421, jours: 19, part: 16.9 }, annee: { du: '2026-01-01', tickets: 41620, identifies: 5910, jours: 323, part: 14.2 }, aLire: 0 };
}
function tendance(jours) {
  const pas = jours <= 30 ? 1 : jours <= 90 ? 3 : 7, points = [];
  // Les profils fidèles montent depuis l'été (la carte café), la découverte baisse un peu.
  const depart = { 1: 11, 2: 26, 3: 58, 4: 90, 5: 121, 6: 285 };
  const dates = []; for (let k = jours; k > 0; k -= pas) { dates.push(D.plus(AUJ, -k)); } dates.push(AUJ);
  dates.forEach((d, n) => {
    const f = n / (dates.length - 1), r = D.alea('td' + d), par = {};
    PROFILS.forEach(p => { const a = depart[p.id] * (1 - jours / 180 * 0.15) + (STATS[p.id].clients - depart[p.id] * (1 - jours / 180 * 0.15)) * f; par[p.id] = Math.max(0, Math.round(a + (r() - 0.5) * (p.id === 6 ? 8 : 2.4))); });
    PROFILS.forEach(p => { if (n === dates.length - 1) { par[p.id] = STATS[p.id].clients; } });
    points.push({ date: d, scannes: Object.values(par).reduce((a, b) => a + b, 0), parProfil: par });
  });
  return { shop: D.SHOP, jours, pas, fenetre: 30, memoire: 90, aLire: 0, points };
}

/* La fiche d'un client : 180 jours de passages, tirés de son rythme. */
const ARTICLES = { pain: ['Baguette tradition', 'Pain de campagne', 'Pain complet'], vienno: ['Croissant au beurre', 'Pain au chocolat', 'Pain aux raisins'],
  tartes: ['Tarte au citron meringuée (part)', 'Éclair au chocolat'], gouter: ['Cookie chocolat', 'Chausson aux pommes'], sale: ['Sandwich jambon-beurre', 'Quiche lorraine'], antigaspi: ['Panier anti-gaspi'] };
function fiche(cid) {
  const c = CLIENTS.find(x => String(x.client) === String(cid)) || CLIENTS[10];
  const a = D.alea('fiche' + c.client), du = D.plus(AUJ, -179);
  const T = [];
  // Ses jours : surtout le samedi matin et un jour de semaine, au rythme de son profil (les derniers mois un peu plus).
  for (let k = 179; k >= 0; k--) {
    const j = D.plus(AUJ, -k), w = D.dow(j), mois = 179 - k;
    const p = c.rythme / 30 * (w === 6 ? 2.6 : w === 0 ? 0.4 : 0.85) * (0.7 + 0.5 * mois / 179);
    if (j === D.plus(AUJ, -c.depuis) || (k > c.depuis && a() < p)) {
      const h = w === 6 ? 8 + Math.floor(a() * 3) : 7 + Math.floor(a() * 11), m = Math.floor(a() * 60), cmd = a() < c.cmd / Math.max(1, c.tk);
      const fams = Object.keys(c.familles).filter(f => a() * 60 < c.familles[f] + 8).slice(0, 3);
      const arts = (fams.length ? fams : ['pain']).map(f => { const L = ARTICLES[f]; return L[Math.floor(a() * L.length)] + ' ×' + (1 + Math.floor(a() * 2)); });
      T.push({ quand: j + ' ' + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0'), montant: D.r2(4 + a() * 14 + (cmd ? 12 : 0)), commande: cmd, articles: arts });
    }
  }
  const parJour = [0, 0, 0, 0, 0, 0, 0], moments = { matin: 0, midi: 0, 'après-midi': 0, soir: 0 }, art = {}, mois = {};
  T.forEach(t => {
    const j = t.quand.slice(0, 10), h = +t.quand.slice(11, 13);
    parJour[(D.dow(j) + 6) % 7]++;
    moments[h < 11 ? 'matin' : h < 14 ? 'midi' : h < 18 ? 'après-midi' : 'soir']++;
    t.articles.forEach(x => { const [n, q] = x.split(' ×'); art[n] = (art[n] || 0) + +q; });
    (mois[j.slice(0, 7)] = mois[j.slice(0, 7)] || new Set()).add(j);
  });
  const premier = T.length ? T[0].quand.slice(0, 10) : du;
  const parMois = Object.keys(mois).sort().map(ym => {
    const n = new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0).getDate();
    let ec = ym === AUJ.slice(0, 7) ? +AUJ.slice(8, 10) : n;
    if (ym === premier.slice(0, 7)) { ec = ec - +premier.slice(8, 10) + 1; }
    const r = mois[ym].size * 30 / Math.max(1, ec);
    return { mois: ym, passages: mois[ym].size, jours: ec, rythme: D.r1(r), profil: PROFIL_DE(r) };
  });
  const tk = T.length, eur = D.r2(T.reduce((s, t) => s + t.montant, 0)), cmd = T.filter(t => t.commande).length;
  const passages = new Set(T.map(t => t.quand.slice(0, 10))).size;
  return { shop: D.SHOP, client: c.client, nom: c.nom, du, au: AUJ, fenetre: 30, profils: PROFILS, moyenne: MOYENNE, axes: AXES, famillesLib: FAMILLES,
    tk, eur, cmd, parJour, moments, articles: Object.entries(art).sort((x, y) => y[1] - x[1]).slice(0, 10).map(([nom, q]) => ({ nom, q })),
    parMois, tickets: T.slice().reverse(), canaux: { comptoir: tk - cmd, commande: Math.max(0, cmd - 2), webshop: Math.min(cmd, 2), livraison: 0, inconnu: 0 }, aLire: 0,
    profil: c.profil, rythme: c.rythme, passagesFenetre: c.passagesFenetre, passages, premier, dernier: D.plus(AUJ, -c.depuis), depuis: c.depuis,
    radar: { frequence: Math.min(100, Math.round(100 * c.rythme / 12)), recence: Math.max(0, Math.round(100 * (1 - c.depuis / 90))), anciennete: 86, panier: Math.min(100, Math.round(100 * eur / Math.max(1, tk) / 60)), diversite: 67, comptoir: Math.round(100 * (tk - cmd) / Math.max(1, tk)) },
    familles: c.familles, famLues: true };
}

/* Les cartes de fidélité de l'ERP (deux cartes à timbres). */
const DEFS = [
  { id: 1, nom: 'Carte café', timbres: 10, recompense: 'Un café offert', couleur: '#8D1D2C', points: false, du: '2026-06-01', au: null, active: true, portee: 'NETWORK' },
  { id: 2, nom: 'Carte viennoiserie', timbres: 8, recompense: 'Une viennoiserie offerte', couleur: '#B0792A', points: false, du: '2026-09-01', au: '2026-12-31', active: true, portee: 'NETWORK' },
  { id: 3, nom: 'Carte à points', timbres: null, recompense: 'Un bon de 5 € tous les 100 points', couleur: '#6B4F3A', points: true, du: '2026-11-01', au: null, active: true, portee: 'NETWORK' },
];
/* Les sociétés (fictives) : le compte de la société inscrit ou non, ses personnes parmi les clients (par leur rang). */
const SOCIETES = [
  { id: 70001, nom: 'Brasserie du Canal', ville: 'Halle', bureaux: ['Siège'], facturation: ['facturé à la société'], personnes: 23, compte: 'Brasserie du Canal', rangs: [5, 9, 13, 17] },
  { id: 70002, nom: 'Fiduciaire Lambert', ville: 'Halle', bureaux: ['Rue du Marché'], facturation: ['chaque personne paie'], personnes: 11, compte: null, rangs: [11, 21] },
  { id: 70003, nom: 'Garage du Pont', ville: 'Lembeek', bureaux: [], facturation: [], personnes: 7, compte: 'Garage du Pont', rangs: [] },
];
const pointsDe = (a, n) => { const solde = n == null ? Math.floor(a() * 140) : n; return { solde, gagnes: solde, depenses: 0, paliers: [{ points: 100, euros: 5, atteint: solde >= 100, reste: Math.max(0, 100 - solde) }] }; };
const REPART = { 1: [21, 18, 22, 19, 17, 16, 14, 12, 13, 12], 2: [16, 14, 12, 11, 9, 8, 7, 6] };
function cartesClients() {
  return CLIENTS.slice(0, 24).map((c, i) => {
    const a = D.alea('carte' + c.client), cartes = [];
    const t1 = Math.floor(a() * 10), t2 = Math.floor(a() * 8);
    cartes.push({ id: 1, nom: 'Carte café', active: true, timbres: { progres: t1, requis: 10, reste: 10 - t1, pretes: i % 7 === 2 ? 1 : 0, dette: 0 }, points: null, gagnesIci: { timbres: 2 + Math.floor(a() * 9), points: 0 } });
    if (i % 3 !== 1) { cartes.push({ id: 2, nom: 'Carte viennoiserie', active: true, timbres: { progres: t2, requis: 8, reste: 8 - t2, pretes: i % 9 === 4 ? 1 : 0, dette: 0 }, points: null, gagnesIci: { timbres: 1 + Math.floor(a() * 6), points: 0 } }); }
    if (i % 2 === 1) { const pt = pointsDe(a); cartes.push({ id: 3, nom: 'Carte à points', active: true, timbres: null, points: pt, gagnesIci: { timbres: 0, points: Math.min(pt.solde, 20 + Math.floor(a() * 40)) } }); }
    const soc = SOCIETES.find(x => x.rangs.includes(i));
    return { id: c.client, nom: c.nom, actif: true, bloque: false, cartes, type: soc ? 'personne' : 'particulier', societe: soc ? soc.id : null, achats: Math.max(1, Math.round(c.rythme * 0.7)), montant: D.r2(c.eur / 4), dernierAchat: D.plus(AUJ, -c.depuis) + 'T10:12:00+01:00',
      reconnaissances: Math.round(c.rythme * 0.6), derniereReconnaissance: D.plus(AUJ, -c.depuis) + 'T10:12:00+01:00', pretes: cartes.reduce((s, p) => s + (p.timbres ? p.timbres.pretes : 0), 0) };
  }).concat(SOCIETES.filter(x => x.compte).map((x, k) => {
    const a = D.alea('soc' + x.id), pt = pointsDe(a, k ? 128 : 340);
    return { id: x.id, nom: x.compte, actif: true, bloque: false, cartes: [{ id: 3, nom: 'Carte à points', active: true, timbres: null, points: pt, gagnesIci: { timbres: 0, points: Math.round(pt.solde * 0.4) } }],
      type: 'societe', societe: x.id, achats: k ? 6 : 31, montant: k ? 128.4 : 1486.2, dernierAchat: D.plus(AUJ, -k * 2) + 'T09:30:00+01:00', reconnaissances: 0, derniereReconnaissance: null, pretes: 0 };
  })).sort((x, y) => (y.pretes - x.pretes) || (y.achats - x.achats));
}
/* Les entreprises montrées (celles qui ont une carte ou une personne inscrite) et les liens lus dans TF Buddy. */
function entreprises(L) {
  return SOCIETES.map(x => ({ id: x.id, nom: x.nom, ville: x.ville, bureaux: x.bureaux, facturation: x.facturation, personnes: x.personnes,
    compte: !!x.compte, inscrites: L.filter(c => c.type === 'personne' && c.societe === x.id).length }));
}
/* Le bingo d'une saison fictive de 13 semaines, commencée le lundi 26/10 : la semaine 4 (16 au 22/11) est en cours. */
const SAISON_DU = '2026-10-26';
const THEMES = ['La rentrée des soupes', 'Toussaint', 'La tarte aux pommes', 'Saint-Martin', 'Le pain d’épices', 'Saint-Nicolas', 'Le chocolat chaud', 'Noël',
  'Nouvel An', 'Épiphanie', 'Le bon pain', 'Le croissant', 'Chandeleur'];
function bingo() {
  const sem = THEMES.map((titre, i) => { const du = D.plus(SAISON_DU, 7 * i), au = D.plus(du, 6); return { n: i + 1, du, au, titre, sous: '', etat: au < AUJ ? 'passee' : du <= AUJ ? 'cours' : 'avenir' }; });
  const n = sem.filter(w => w.etat !== 'avenir').length, clients = {};
  cartesClients().forEach((c, k) => {
    const a = D.alea('bingo' + c.id), fid = 0.25 + a() * 0.7;
    const euros = sem.slice(0, n).map((w, i) => { const r = a(); return i === n - 1 ? D.r2(k % 5 === 1 ? 7 + a() * 2.6 : r < fid ? 10 + a() * 25 : a() * 9) : D.r2(r < fid ? 10 + a() * 30 : r < fid + 0.2 ? a() * 9 : 0); });
    clients[c.id] = { euros, cases: euros.filter(e => e >= 10).length };
  });
  return { saison: { id: 'DEMO', nom: 'Automne 2026', du: SAISON_DU, au: sem[12].au, tirage: null, etat: 'cours', enCours: n, closes: n - 1, semaines: sem },
    regles: { seuil: 10, tousLes: 3, bons: [2, 2, 2, 2], superBon: 10 }, semaine: n, clients, autresIllisibles: 0, lu: AUJ + ' 15:40' };
}
function cartes(periode) {
  const k = periode === 'annee' ? 3.2 : periode === '30' ? 1.1 : 1;
  const C = [1, 2].map(id => {
    const def = DEFS.find(x => x.id === id), R = REPART[id];
    return { id, nom: def.nom, clients: R.reduce((a, b) => a + b, 0), actifsIci: Math.round((id === 1 ? 96 : 58) * Math.min(1.6, k)), pretes: id === 1 ? 7 : 3, presque: R[R.length - 1] + R[R.length - 2],
      repartition: R, pointsSoldes: [], timbresIci: Math.round((id === 1 ? 512 : 268) * k), pointsIci: 0, requis: def.timbres, definition: def };
  });
  const L = cartesClients();
  // La carte à points (28/11/2026) : ses clients et leurs soldes, comme fcProgramme les compte côté serveur.
  const P3 = L.map(c => c.cartes.find(p => p.id === 3)).filter(Boolean);
  C.push({ id: 3, nom: 'Carte à points', clients: P3.length, actifsIci: P3.length, pretes: 0, presque: 0, repartition: [], pointsSoldes: P3.map(p => p.points.solde).sort((x, y) => x - y),
    timbresIci: 0, pointsIci: P3.reduce((s, p) => s + p.gagnesIci.points, 0), definition: DEFS[2] });
  return { shop: D.SHOP, periode: periode || 'mois', du: '2026-11-01', au: AUJ, lu: AUJ + ' 15:40',
    participants: { unique_customers: 248, active_in_period: Math.round(131 * Math.min(1.6, k)) }, reconnaissances: { count: Math.round(402 * k), unique_customers: Math.round(167 * Math.min(1.5, k)) },
    activite: { stamps_earned: Math.round(780 * k) }, reconnusCaisse90: N, cartes: C, definitions: DEFS, clients: L, clientsTotal: 248, erreurClients: null,
    entreprises: entreprises(L), liens: { lu: true, bureaux: true, societes: 31, sansCarte: 28 },
    reseau: [['4', D.NOM, 248, 131, 780, 4210], ['11', 'Magasin Nord', 312, 164, 1020, 5480], ['12', 'Magasin Gare', 187, 92, 540, 2890], ['13', 'Magasin Centre', 276, 140, 860, 4630], ['14', 'Magasin Sud', 154, 71, 410, 2240]]
      .map(([id, nom, inscrits, actifs, timbres, ventes]) => ({ id, nom, inscrits, actifs: Math.round(actifs * Math.min(1.6, k)), timbres: Math.round(timbres * k), points: 0, ventes: D.r2(ventes * k), reconnaissances: 0 })) };
}
function carte(id) {
  const def = DEFS.find(x => String(x.id) === String(id)) || DEFS[0];
  return { id: def.id, nom: def.nom, code: def.id === 1 ? 'CAFE-10' : 'VIENNO-8', version: 2, active: true, portee: 'NETWORK', magasins: [D.NOM, 'Magasin Nord', 'Magasin Gare', 'Magasin Centre', 'Magasin Sud'],
    du: def.du, au: def.au, couleur: def.couleur, timbres: def.timbres, recompense: def.recompense, recompenseValideJours: 30,
    recompenseProduits: def.id === 1 ? ['Café', 'Cappuccino'] : ['Croissant au beurre', 'Pain au chocolat', 'Pain aux raisins'], recompensePortions: 0,
    gagnerProduits: def.id === 1 ? ['Café', 'Cappuccino', 'Thé'] : [], gagnerCategories: def.id === 1 ? [] : ['Viennoiseries'], gagnerGroupes: 0, motsCles: [], exclus: def.id === 1 ? [] : ['Mini-croissant'],
    conditionsFigees: true, points: null };
}
function carteClient(cid) {
  const c = cartesClients().find(x => String(x.id) === String(cid));
  if (!c) { return { inscrit: false, cartes: [], disponibles: DEFS }; }
  return { inscrit: true, cartes: c.cartes.map(p => Object.assign({}, p, { definition: DEFS.find(x => x.id === p.id) })), achats: c.achats * 4, montant: D.r2(c.montant * 4), dernierAchat: c.dernierAchat,
    reconnaissances: c.reconnaissances * 4, derniereReconnaissance: c.derniereReconnaissance, disponibles: DEFS };
}

module.exports = function (m, p, q) {
  if (m !== 'GET' || !p.startsWith('/fidelite/')) { return undefined; }
  if (p === '/fidelite/profils/magasin') { return profilsMagasin(); }
  if (p === '/fidelite/scan') { return scan(); }
  if (p === '/fidelite/profils/tendance') { return tendance([30, 90, 180].includes(+q.get('jours')) ? +q.get('jours') : 90); }
  if (p === '/fidelite/client') { return fiche(q.get('client')); }
  if (p === '/fidelite/carte-client') { return carteClient(q.get('client')); }
  if (p === '/fidelite/cartes') { return cartes(q.get('periode')); }
  if (p === '/fidelite/cartes/bingo') { return bingo(); }
  if (p === '/fidelite/carte') { return carte(q.get('id')); }
  return undefined;
};
