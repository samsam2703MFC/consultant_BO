/* Vendre › Promotions (dashboard.js, rendPromos ; bundles.js) : les promotions qui touchent le magasin de démo, où elles
 * sont utilisées (comptoir, click & collect, livraison), et les bundles du Coach Vente — le constat, les couples, le prix,
 * la fiche — calculés comme le serveur (panel/src/bundles.php : bndProduits, bndBase, bndCouples) sur les ventes fictives
 * de lib/ventes.js. Les écritures du Coach Vente (24/11/2026, les vidéos par onglet 12-04 et 12-05) : « Valider et
 * lancer » (POST …/bundles/lancer : le bundle lancé, en tête de « Lancés avec le Coach Vente » au GET suivant, à venir
 * le lundi qui suit), « Renvoyer à l'équipe » (POST …/bundles/renvoyer), la fiche PDF de l'équipe (POST …/apercu,
 * GET …/fiche : un PDF d'une page, fictif). L'état vit le temps d'un tournage : chaque contexte relit ce module.
 * Données fictives.
 */
const D = require('./lib/demo');
const V = require('./lib/ventes');
const { r2, r1 } = D;

/* --- les produits du magasin : prix, catégorie, coût de recette --------------------------------------------------------- */
const CATS = [...new Set(V.P.map(p => p.cat))];
const catId = cat => 10 + CATS.indexOf(cat);
function produits() {
  const L = V.P.map(p => ({ id: p.pid, nom: p.nom, catId: catId(p.cat), cat: p.cat, prixTtc: p.prix, prixHt: V.prixHt(p), tva: V.TVA, cout: V.cout(p), source: V.cout(p) != null ? 'magasin' : null }));
  return { shop: D.SHOP, produits: L, nMagasin: L.filter(p => p.source === 'magasin').length, nReseau: 0, nSansCout: L.filter(p => p.cout == null).length, seuils: { ko: 1.67, att: 2.5, food: 32 } };
}

/* --- les bundles du Coach Vente -------------------------------------------------------------------------------------- */
const B = { jours: 30, margeHaute: 78, prixX: 8, remise: 0.1, remiseConseil: [8, 12], remiseTrop: 15, prise: 0.1, couples: 6, yMaxFois: 2 };
const CRENEAUX = { matin: 'le matin', midi: 'le midi', apres: 'l’après-midi' };
const AU = D.plus(D.AUJ, -1), DU = D.plus(AU, -(B.jours - 1));
// Le moment de la journée d'un produit qui ne suit pas son groupe (le cappuccino et le thé se prennent aussi au goûter).
const MOMENT_PRODUIT = { Cappuccino: { matin: 0.4, midi: 0.25, apres: 0.35 }, Thé: { matin: 0.3, midi: 0.2, apres: 0.5 } };
function bndProduits() {
  const S = V.periode(DU, AU), P = [];
  V.P.forEach(p => {
    const o = S[p.pid], c = V.cout(p);
    if (!o || c == null) { return; }
    const ht = V.prixHt(p), m = r1(100 * (ht - c) / ht), parts = {};
    // Le moment de la journée : celui du groupe, un peu différent d'un produit à l'autre.
    Object.entries(MOMENT_PRODUIT[p.nom] || V.MOMENTS[p.groupe]).forEach(([k, x]) => { parts[k] = x * D.bruit('mo' + p.pid + k, 0.15); });
    const t = Object.values(parts).reduce((a, x) => a + x, 0); Object.keys(parts).forEach(k => { parts[k] /= t; });
    P.push({ id: p.pid, nom: p.nom, cat: p.cat, q: r1(o.q), v: o.v, ttc: p.prix, ht, cout: c, m, me: Math.round((ht - c) * 10000) / 10000, vht: o.v * ht / p.prix, parts });
  });
  return P.sort((a, b) => b.q - a.q);
}
function bndBase(P) {
  let ht = 0, mg = 0, q = 0; P.forEach(p => { ht += p.vht; mg += p.vht * p.m / 100; q += p.q; });
  const base = ht > 0 ? 100 * mg / ht : 0;
  let sous = 0, haut = 0; P.forEach(p => { if (p.m < base) { sous += p.q; } if (p.m >= B.margeHaute) { haut += p.q; } });
  return { base: r1(base), ventesHt: r2(ht), marge: r2(mg), pieces: Math.round(q), produits: P.length, sousPct: q > 0 ? r1(100 * sous / q) : 0, hautPct: q > 0 ? r1(100 * haut / q) : 0 };
}
const prixConseille = sep => r2(Math.round(sep * (1 - B.remise) * 10) / 10);
function bndCouple(X, Y, prix) {
  const sep = r2(X.ttc + Y.ttc), htB = prix * (X.ht + Y.ht) / (X.ttc + Y.ttc), mB = htB - X.cout - Y.cout, gain = mB - X.me;
  return { x: X.id, y: Y.id, separes: sep, prix: r2(prix), remise: r2(sep - prix), remisePct: sep > 0 ? r1(100 * (sep - prix) / sep) : 0, prixY: r2(prix - X.ttc),
    margeBundle: r2(mB), margeBundlePct: htB > 0 ? r1(100 * mB / htB) : 0, margeXSeul: r2(X.me), margeXSeulPct: X.m, gainTicket: r2(gain),
    bundlesMois: Math.round(B.prise * X.q * 30 / B.jours), gainMois: r2(B.prise * X.q * 30 / B.jours * gain) };
}
const famille = cat => (String(cat).trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').split(/[^a-z0-9]+/).filter(Boolean)[0] || '');
function moment(X, Y) {
  let ov = 0, meilleur = null, max = -1;
  Object.keys(CRENEAUX).forEach(k => { const a = X.parts[k] || 0, b = Y.parts[k] || 0; ov += Math.min(a, b); if (a + b > max) { max = a + b; meilleur = k; } });
  return [ov, meilleur];
}
function bndCouples(P, base) {
  const q = P.reduce((a, p) => a + p.q, 0), qMin = Math.max(20, 0.01 * q);
  const X = P.filter(p => p.m < base && p.q >= qMin && p.ttc <= B.prixX).slice(0, 10);
  const Y = P.filter(p => p.m >= B.margeHaute && p.q >= 1 && p.ttc >= 1);
  const cands = [];
  X.forEach(x => Y.forEach(y => {
    if (x.id === y.id || y.ttc > Math.max(3.5, 2 * x.ttc) || famille(x.cat) === famille(y.cat)) { return; }
    const c = bndCouple(x, y, prixConseille(x.ttc + y.ttc));
    if (c.gainTicket <= 0 || c.margeBundlePct <= x.m) { return; }
    const [ov, mo] = moment(x, y);
    c.moment = mo ? CRENEAUX[mo] : null; c.score = c.gainMois * (0.5 + ov);
    cands.push(c);
  }));
  cands.sort((a, b) => b.score - a.score);
  const out = [], xs = {}, ys = {};
  for (const c of cands) {
    if (xs[c.x] || (ys[c.y] || 0) >= B.yMaxFois) { continue; }
    xs[c.x] = true; ys[c.y] = (ys[c.y] || 0) + 1; delete c.score; out.push(c);
    if (out.length >= B.couples) { break; }
  }
  return out.sort((a, b) => b.gainMois - a.gainMois);
}
// Le bundle déjà lancé avec le Coach Vente, en caisse depuis le lundi 9 novembre (sa fiche est partie à l'équipe) :
// un produit qui attire (sous la marge de base) et un produit qui rapporte (78 % et plus), comme le Coach les choisit.
const EQUIPE = [['Julie', 'julie'], ['Thomas', 'thomas'], ['Inès', 'ines'], ['Lucas', 'lucas'], ['Sarah', 'sarah'], ['Nora', 'nora']];
function lances(P) {
  const X = P.find(p => p.nom === 'Sandwich jambon-beurre'), Y = P.find(p => p.nom === 'Café');
  if (!X || !Y) { return []; }
  const c = bndCouple(X, Y, prixConseille(X.ttc + Y.ttc));
  return [{ id: 3, nom: 'Duo du midi', le: '2026-11-05 09:42:00', x: X.id, xNom: X.nom, xTtc: X.ttc, yNom: Y.nom, prix: c.prix, separes: c.separes, du: '2026-11-09', au: '2026-12-08', canal: 'tous',
    panelId: 2117, statut: 'en_caisse', margeBundle: c.margeBundle, gainTicket: c.gainTicket,
    envoi: { le: '2026-11-05 09:42:31', a: EQUIPE.map(([n, a]) => ({ nom: n, adresse: a[0] + '•••@exemple.be' })), erreur: null, avertissement: null } }];
}
function bundles() {
  const P = bndProduits(), base = bndBase(P), couples = bndCouples(P, base.base), nom = id => (P.find(p => p.id === id) || {}).nom || ('Produit ' + id);
  couples.forEach(c => { c.xNom = nom(c.x); c.yNom = nom(c.y); });
  return { shop: +D.SHOP, magasin: D.NOM, periode: { du: DU, au: AU, jours: B.jours }, base,
    produits: P.map(p => ({ id: p.id, nom: p.nom, cat: p.cat, q: p.q, ttc: p.ttc, ht: Math.round(p.ht * 10000) / 10000, cout: Math.round(p.cout * 10000) / 10000, m: p.m, me: p.me })),
    couples, lances: LANCES.concat(lances(P).map(l => Object.assign(l, ENVOIS[l.id] ? { envoi: ENVOIS[l.id] } : {}))),
    regles: { margeHaute: B.margeHaute, remise: B.remise * 100, remiseConseil: B.remiseConseil, remiseTrop: B.remiseTrop, prise: B.prise,
      durees: { '2s': '2 semaines', '1m': '1 mois', '2m': '2 mois' }, canaux: { tous: 'au comptoir et sur le webshop', comptoir: 'au comptoir', webshop: 'sur le webshop' },
      lundi: '2026-11-23', finMois: '2026-12-22' },
    equipe: { n: EQUIPE.length, boite: true } };
}

/* --- les écritures du Coach Vente : lancer, renvoyer, la fiche (bundles.php : bndPreparer, wr_bnd_lancer, bndEnvoyer) --- */
const LANCES = [];          // les bundles lancés pendant le tournage, le plus récent d'abord (ORDER BY cree_le DESC)
const FICHES = {}, ENVOIS = {};   // la fiche de chaque bundle lancé (son PDF) ; la fiche renvoyée d'un bundle d'avant le tournage
let ECRITS = 0;             // l'heure des écritures : 15:40:12, puis une minute de plus à chacune (l'horloge de la page est figée)
const maintenant = () => '2026-11-19 15:' + String(40 + ECRITS++).padStart(2, '0') + ':12';
const nomCourt = n => String(n || '').replace(/\s*\([^)]*\)\s*$/, '').trim() || n;
/** Les dates du bundle : du lundi qui suit aujourd'hui, pour la durée (bndDates). */
function dates(duree) {
  const du = D.plus(D.AUJ, ((8 - D.dow(D.AUJ)) % 7) || 7), d = D.jourDe(du);
  if (duree === '2s') { return [du, D.plus(du, 13)]; }
  d.setMonth(d.getMonth() + (duree === '2m' ? 2 : 1)); d.setDate(d.getDate() - 1);
  return [du, D.iso(d)];
}
/** La fiche envoyée à l'équipe : les six adresses de l'équipe, masquées. */
const envoi = le => ({ le: le || maintenant(), a: EQUIPE.map(([n, a]) => ({ nom: n, adresse: a[0] + '•••@exemple.be' })), erreur: null, avertissement: null });
/** Un bundle demandé, vérifié et chiffré (bndPreparer) : [fiche, erreur]. */
function preparer(b) {
  const P = bndProduits(), X = P.find(p => p.id === +b.x), Y = P.find(p => p.id === +b.y);
  if (!X || !Y) { return [null, 'Les deux produits doivent avoir un prix et un coût de recette au panel.']; }
  if (X.id === Y.id) { return [null, 'Choisissez deux produits différents.']; }
  const sep = r2(X.ttc + Y.ttc), prix = b.prix != null && !isNaN(+b.prix) ? r2(+b.prix) : prixConseille(sep);
  if (prix <= X.ttc || prix >= sep) { return [null, 'Le prix du bundle se place entre le prix du ' + X.nom.toLowerCase() + ' seul et celui des deux séparés.']; }
  const c = bndCouple(X, Y, prix), duree = ['2s', '1m', '2m'].includes(b.duree) ? b.duree : '1m', canal = ['tous', 'comptoir', 'webshop'].includes(b.canal) ? b.canal : 'tous';
  const [du, au] = dates(duree);
  return [{ nom: String(b.nom || '').trim().slice(0, 80) || (nomCourt(X.nom) + ' + ' + nomCourt(Y.nom)), x: X.id, xNom: nomCourt(X.nom), y: Y.id, yNom: nomCourt(Y.nom),
    prix, separes: sep, duree, canal, du, au, couple: c }, null];
}
/** POST /assistant/magasin/bundles/lancer : la promotion « créée au panel » (n° 2118, 2119…), gardée, la fiche envoyée. */
function lancer(b) {
  const [f, err] = preparer(b || {});
  if (err) { return { __status: 422, json: { error: err } }; }
  const le = maintenant();
  const ligne = { id: 4 + LANCES.length, nom: f.nom, le, x: f.x, xNom: f.xNom, yNom: f.yNom, prix: f.prix, separes: f.separes, du: f.du, au: f.au, canal: f.canal,
    panelId: 2118 + LANCES.length, statut: D.AUJ < f.du ? 'a_venir' : (D.AUJ <= f.au ? 'en_caisse' : 'terminee'), margeBundle: f.couple.margeBundle, gainTicket: f.couple.gainTicket,
    envoi: b.envoyer ? envoi(le.slice(0, 17) + '14') : null };
  LANCES.unshift(ligne); FICHES[ligne.id] = f;
  return { ok: true, bundle: ligne, panelErreur: null, exclusAuto: [] };
}
/** POST /assistant/magasin/bundles/renvoyer {id} : la fiche repart aux six adresses de l'équipe. */
function renvoyer(b) {
  const L = LANCES.concat(lances(bndProduits())), l = L.find(x => x.id === +(b || {}).id);
  if (!l) { return { __status: 404, json: { error: 'bundle inconnu pour ce magasin' } }; }
  l.envoi = envoi();
  if (!LANCES.includes(l)) { ENVOIS[l.id] = l.envoi; }
  return { ok: true, bundle: l };
}
/** La fiche de l'équipe en PDF (bndFicheHtml, en plus court) : une page A4, Helvetica, ni marge ni coefficient. */
function fichePdf(f) {
  const eur = v => r2(v).toFixed(2).replace('.', ',') + ' EUR';
  const t = s => String(s).replace(/[’‘]/g, "'").replace(/[−–]/g, '-').replace(/€/g, 'EUR').replace(/[^\x20-\xff]/g, '').replace(/[\\()]/g, m => '\\' + m);
  const L = [[18, 'L\'Atelier by ' + D.NOM + ' - fiche promotion'], [26, f.nom], [13, '1 ' + f.xNom.toLowerCase() + ' + 1 ' + f.yNom.toLowerCase() + ' pour ' + eur(f.prix) + ' au lieu de ' + eur(f.separes)],
    [13, 'Du ' + f.du.split('-').reverse().join('/') + ' à l\'ouverture au ' + f.au.split('-').reverse().join('/') + ' au soir'],
    [13, 'En caisse : la remise s\'applique toute seule quand les deux produits sont sur le même ticket.'],
    [13, '« ' + f.yNom + ' avec votre ' + f.xNom.toLowerCase() + ' ? Les deux sont à ' + eur(f.prix) + '. »']];
  let y = 790; const flux = L.map(([s, x]) => { y -= s + 18; return 'BT /F1 ' + s + ' Tf 56 ' + y + ' Td (' + t(x) + ') Tj ET'; }).join('\n');
  const objets = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Length ' + flux.length + ' >>\nstream\n' + flux + '\nendstream'];
  let s = '%PDF-1.4\n'; const pos = [];
  objets.forEach((o, i) => { pos.push(s.length); s += (i + 1) + ' 0 obj\n' + o + '\nendobj\n'; });
  const x = s.length;
  s += 'xref\n0 ' + (objets.length + 1) + '\n0000000000 65535 f \n' + pos.map(p => String(p).padStart(10, '0') + ' 00000 n \n').join('') +
    'trailer\n<< /Size ' + (objets.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + x + '\n%%EOF\n';
  return { __body: Buffer.from(s, 'latin1'), type: 'application/pdf' };
}
function fiche(id) {
  const l = LANCES.concat(lances(bndProduits())).find(x => x.id === +id);
  if (!l) { return { __status: 404, json: { error: 'bundle inconnu pour ce magasin' } }; }
  return fichePdf(FICHES[l.id] || { nom: l.nom, xNom: l.xNom, yNom: l.yNom, prix: l.prix, separes: l.separes, du: l.du, au: l.au });
}

/* --- les promotions du panel qui touchent le magasin ---------------------------------------------------------------- */
const cat = nom => ({ [catId(nom)]: nom });
const prod = nom => { const p = V.P.find(x => x.nom === nom); return { [p.pid]: p.nom }; };
// La liste gardée a été lue au panel à 15:38 ; « ↻ relire le panel » (&frais=1) la relit à l'instant (15:40).
function liste(frais) {
  const P = bndProduits(), duo = lances(P)[0];
  const panel = [
    { id: 2101, nom: '3 baguettes, la 4e offerte', type: 'xy', texte: '3 achetés (Baguettes), 1 Baguettes offerte', statut: 'active', etat: 'en_caisse', du: '2026-11-01 00:00', au: '2026-11-30 23:59',
      reseau: true, magasins: null, x: { q: 3, c: cat('Baguettes') }, y: { q: 1, c: cat('Baguettes') }, recompense: { type: 'FREE' } },
    { id: 2102, nom: 'Boisson chaude à −50 % avec une pâtisserie', type: 'xy', texte: '1 acheté (Pâtisseries individuelles), 1 Boissons chaudes à −50 %', statut: 'active', etat: 'en_caisse',
      du: '2026-10-12 00:00', au: '2026-12-20 23:59', reseau: true, magasins: null, x: { q: 1, c: cat('Pâtisseries individuelles') }, y: { q: 1, c: cat('Boissons chaudes') },
      recompense: { type: 'PERCENTAGE_DISCOUNT', valeur: 50 } },
    { id: 2103, nom: 'Le 6e cookie offert', type: 'xy', texte: '5 achetés, le 6e offert', statut: 'active', etat: 'en_caisse', du: '2026-10-01 00:00', au: '2026-12-31 23:59',
      reseau: true, magasins: null, x: { q: 5, c: cat('Biscuits et cookies') }, y: { q: 1, c: cat('Biscuits et cookies') }, recompense: { type: 'FREE' } },
  ];
  if (duo) {
    panel.push({ id: duo.panelId, nom: duo.nom, type: 'xy', texte: '1 ' + duo.xNom + ' + 1 ' + duo.yNom, statut: 'active', etat: 'en_caisse', du: duo.du + ' 00:00', au: duo.au + ' 23:59',
      reseau: false, magasins: 1, x: { q: 1, p: prod(duo.xNom) }, y: { q: 1, p: prod(duo.yNom) }, recompense: { type: 'FIXED_PRICE', valeur: r2(duo.prix - duo.xTtc) } });
  }
  panel.forEach(r => Object.assign(r, { bundle: null, creneaux: [], repetable: true, maxParTicket: null, canaux: null, seuil: null }));
  return { shop: D.SHOP, demandes: [], panel, erreurPanel: null, source: 'panel', lu: frais ? '15:40' : '15:38', luTs: Math.floor(new Date('2026-11-19T15:' + (frais ? '40' : '38') + ':00+01:00') / 1000), luJour: D.AUJ,
    regles: { coefMin: 2, conditions: { seuil: true, canal: true, comptoir: true, webshop: true, noms: { seuil: 'min_order_amount', canal: 'channel' } } } };
}

/* --- où les promotions sont utilisées : 7 ou 30 jours ---------------------------------------------------------------- */
// Par jour ouvré : combien de fois, la remise d'une fois, la part du webshop (click & collect, livraison).
const USAGES = [[2101, '3 baguettes, la 4e offerte', 4.2, 1.5, 0.12, 0.06, null], [2102, 'Boisson chaude à −50 % avec une pâtisserie', 3.4, 1.4, 0.05, 0.03, null],
  [2103, 'Le 6e cookie offert', 1.1, 2.2, 0.18, 0.1, null], [2117, 'Duo du midi', 3.1, 0.7, 0.1, 0.04, '2026-11-09']];
function canaux(q) {
  const n = +q.get('jours') === 7 ? 7 : 30, jours = []; for (let i = n - 1; i >= 0; i--) { jours.push(D.plus(D.AUJ, -i)); }
  const z = () => ({ n: 0, r: 0, t: 0 }), agg = {}, parJour = [];
  jours.forEach(j => {
    const J = { j, compt: { n: 0, r: 0 }, web: { n: 0, r: 0 }, lu: true }, k = j === D.AUJ ? 0.79 : (D.dow(j) === 0 || D.dow(j) === 6 ? 1.3 : 1);
    USAGES.forEach(([id, nom, parJ, rem, cc, liv, des]) => {
      if (des && j < des) { return; }
      const a = D.alea('us' + id + j), tot = Math.max(0, Math.round(parJ * k * (0.55 + a() * 0.9))), w = a();
      const nCc = w < cc ? 1 : 0, nLiv = w > 1 - liv ? 1 : 0, nC = Math.max(0, tot - nCc - nLiv);
      const o = agg[id] || (agg[id] = { id, nom, type: 'xy', texte: '', compt: z(), cc: z(), liv: z() });
      [['compt', nC], ['cc', nCc], ['liv', nLiv]].forEach(([c, x]) => { o[c].n += x; o[c].r = r2(o[c].r + x * rem); o[c].t += x; });
      J.compt.n += nC; J.compt.r = r2(J.compt.r + nC * rem); J.web.n += nCc + nLiv; J.web.r = r2(J.web.r + (nCc + nLiv) * rem);
    });
    parJour.push(J);
  });
  const promos = Object.values(agg).sort((a, b) => (b.compt.n + b.cc.n + b.liv.n) - (a.compt.n + a.cc.n + a.liv.n));
  const total = { compt: z(), cc: z(), liv: z() };
  promos.forEach(p => ['compt', 'cc', 'liv'].forEach(c => { total[c].n += p[c].n; total[c].r = r2(total[c].r + p[c].r); total[c].t += p[c].t; }));
  return { shop: D.SHOP, jours: n, du: jours[0], au: jours[n - 1], promos, parJour, total, joursSans: [], commandes: true, enLigne: { cc: 0, liv: 0 }, erreur: null };
}

module.exports = function (m, p, q, corps) {
  if (m === 'POST' && p === '/assistant/magasin/bundles/lancer') { return lancer(corps); }
  if (m === 'POST' && p === '/assistant/magasin/bundles/renvoyer') { return renvoyer(corps); }
  if (m === 'POST' && p === '/assistant/magasin/bundles/apercu') { const [f, err] = preparer(corps || {}); return err ? { __status: 422, json: { error: err } } : fichePdf(f); }
  if (m !== 'GET') { return undefined; }
  if (p === '/assistant/magasin/bundles/fiche') { return fiche(q.get('id')); }
  if (p === '/exploitation/promos-magasin') { return liste(q.get('frais') === '1'); }
  if (p === '/exploitation/promos-magasin/produits') { return produits(); }
  if (p === '/exploitation/promos-magasin/canaux') { return canaux(q); }
  if (p === '/assistant/magasin/bundles') { return bundles(); }
  return undefined;
};
