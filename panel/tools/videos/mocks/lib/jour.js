/* /exploitation/jour et les lectures de la journée (stats, suivi de production, canaux) pour le magasin de démo. */
const D = require('./demo');
const { r2, r1, alea, bruit, AUJ, MAINTENANT, SHOP, NOM } = D;

/** La ligne d'un magasin dans /exploitation/jour. `moi` : le magasin de démo (tout le détail), sinon un voisin anonyme. */
function ligneMagasin(s, id, nom, echelle) {
  const auj = s === AUJ, lim = auj ? MAINTENANT : null;
  const J = D.journee(s, lim);
  const k = echelle || 1;
  const ca = r2(J.ca * k), tickets = Math.round(J.tickets * k);
  const P = D.pnl(s + id, ca);
  const obj = Math.round(D.objectifJour(s) * k / 10) * 10;
  const plein = D.journee(s, null);
  // La projection : ce qui est fait ÷ la part de journée normalement écoulée.
  const poids = D.poids(s);
  let part = 0; D.HEURES.forEach((h, i) => { if (lim == null) { part += poids[i]; } else if (h * 60 < lim) { part += poids[i] * Math.min(1, (lim - h * 60) / 60); } });
  const proj = auj ? r2(ca / part) : null;
  const caPro = r2(J.caPro * k), ticketsPro = J.ticketsPro;
  const planning = D.planningDe(s);
  const hPlan = planning.reduce((a, p) => a + p.h, 0);
  const hFr = planning.filter(p => p.franchise).reduce((a, p) => a + p.h, 0);
  const coutPlan = planning.filter(p => !p.franchise).reduce((a, p) => a + p.cout, 0);
  // Le CA attribué à chaque service : le CA de chaque heure partagé entre ceux qui sont là.
  planning.forEach(p => {
    let att = 0; J.heures.forEach(x => { const n = planning.filter(q => D.toMin(q.debut) < (x.h + 1) * 60 && D.toMin(q.fin) > x.h * 60).length; if (D.toMin(p.debut) < (x.h + 1) * 60 && D.toMin(p.fin) > x.h * 60) { att += x.ca / Math.max(1, n); } });
    p.ca = att > 0 ? r2(att) : null; p.caH = att > 0 ? r2(att / p.h) : null;
  });
  const j7d = D.plus(s, -7), J7 = D.journee(j7d, lim), J7p = D.journee(j7d, null);
  const P7 = D.pnl(j7d + id, J7.ca);
  const refCa = r2(D.caJourPlein(s) * 0.98 * k * (auj ? part : 1)), refTickets = Math.round(refCa / D.PANIER[D.dow(s)]);
  const ecartPct = (a, b) => b ? r1(100 * (a / b - 1)) : null;
  const caC = r2(ca - caPro), tkC = tickets - ticketsPro, panC = tkC ? r2(caC / tkC) : null;
  const pph = D.dow(s) === 0 || D.dow(s) === 6 ? 0 : 6.8;
  const objC = obj * (1 - pph / 100);
  const pHt = v => r1(100 * v / P.caHt);
  const L = {
    shopId: String(id), magasin: nom, ouvert: true,
    heures: J.heures.map(x => ({ h: x.h, ca: r2(x.ca * k) })),
    planning: planning.map(p => ({ debut: p.debut, fin: p.fin, nom: p.nom, h: p.h, ca: p.ca, caH: p.caH, tauxH: p.tauxH, franchise: p.franchise, cout: p.cout, horsPnl: p.horsPnl, postes: p.postes })),
    planningSource: 'panel', vendeurs: vendeursDe(J, planning),
    planningHeures: r2(hPlan), planningCout: r2(coutPlan), planningHeuresFranchise: r2(hFr), planningCoutFranchise: 0, planningFranchiseNoms: planning.filter(p => p.franchise).map(p => p.nom),
    planningHeuresZero: 0, planningZeroNoms: [],
    ca, refCa, refJours: 4, caDelta: ecartPct(ca, refCa), tickets, refTickets, ticketsDelta: ecartPct(tickets, refTickets),
    j7: { date: j7d, moment: auj ? D.hm(MAINTENANT) : null, tickets: J7.tickets, ca: J7.ca, ticketsJour: J7p.tickets, caJour: J7p.ca,
      mb: r2(J7.ca - P7.coutMatiere * 1.06), mbJour: r2(J7p.ca * 0.66), heures: J7p.heures,
      caComptoir: J7.caComptoir, ticketsComptoir: J7.ticketsComptoir, caComptoirJour: J7p.caComptoir, ticketsComptoirJour: J7p.ticketsComptoir,
      caPro: J7.caPro, ticketsPro: J7.ticketsPro, caProJour: J7p.caPro, ticketsProJour: J7p.ticketsPro, caProApresRemise: J7.caPro, caProApresRemiseJour: J7p.caPro,
      panierBase: 'comptoir', panierRetenu: J7.ticketsComptoir ? r2(J7.caComptoir / J7.ticketsComptoir) : null, panierRetenuJour: J7p.ticketsComptoir ? r2(J7p.caComptoir / J7p.ticketsComptoir) : null },
    heuresPro: J.heures.filter(x => x.proN).map(x => ({ h: x.h, ca: x.pro, n: x.proN, caApresRemise: x.pro })),
    partProHabituelle: pph || null, partProJours: 4,
    panier: tickets ? r2(ca / tickets) : null, produits: Math.round(tickets * 2.3), produitsParClient: 2.3,
    caHt: P.caHt, tva: P.tva, tvaPart: r1(100 * P.tva / ca), tvaSource: 'taux des produits vendus',
    coutMatiere: P.coutMatiere, coutMatierePct: pHt(P.coutMatiere), coutMatiereSource: 'panel',
    margeBrute: P.margeBrute, margeBrutePct: pHt(P.margeBrute),
    invendus: P.invendus, invendusPct: pHt(P.invendus), invendusPieces: Math.round(P.invendus / 0.9), invendusDeclare: true, invendusSource: 'panel',
    labour: P.labour, labourPct: pHt(P.labour), labourSource: 'planning du panel',
    overhead: P.overhead, overheadPct: pHt(P.overhead), overheadSource: 'frais du mois au panel', overheadSaisi: r2(P.overhead * 1.12), overheadTva: r2(P.overhead * 0.12), fraisAIndiquer: 0, fraisSource: 'frais du mois',
    overheadMois: r2(P.overhead * 30), labourMois: r2(P.labour * 30), joursOuverts: 30,
    objectifJour: obj, objectifSource: 'budget', objectifMois: obj * 30, objectifBase: 'budget', objectifPart: 3.4, objectifJoursVus: 4, objectifProfil: 'jeudis',
    projection: proj, projectionPart: auj ? r1(100 * part) : null, projectionHeure: auj ? D.hm(MAINTENANT) : null, projectionJours: 4, projectionMotif: auj ? null : 'journée terminée',
    projectionReste: auj ? r2(proj - ca) : null, projectionRythme: auj ? r2(proj * 1.01) : null, projectionAtteinte: proj && obj ? Math.round(10000 * proj / obj) / 10000 : null,
    objectifJourNom: D.JOURS[D.dow(s)], objectifAtteinte: obj ? Math.round(10000 * ca / obj) / 10000 : null,
    net: P.net, netPct: pHt(P.net), motifNet: null,
    categories: [], serie: [],
    caPro, ticketsPro, caComptoir: caC, ticketsComptoir: tkC, partPro: ca ? r1(100 * caPro / ca) : null, caProApresRemise: caPro,
    clientsBase: 'comptoir', clientsManquants: panC ? Math.round((objC * (auj ? part : 1) - caC) / panC) : null, ecartComptoir: r2(caC - objC * (auj ? part : 1)), panierComptoir: panC,
    panierBase: 'comptoir', panierRetenu: panC,
  };
  return L;
}
/** Qui a vendu quoi : le CA de chaque heure partagé entre les vendeuses présentes (comptoir, responsable, snacking à midi). */
function vendeursDe(J, planning) {
  const V = {};
  const vend = p => p.postes.some(x => /Comptoir|Responsable/.test(x)) || (p.postes.includes('Snacking'));
  J.heures.forEach(x => {
    const en = planning.filter(p => vend(p) && D.toMin(p.debut) < (x.h + 1) * 60 && D.toMin(p.fin) > x.h * 60 && !(p.postes.includes('Snacking') && (x.h < 11 || x.h > 13)));
    const poids = en.map(p => (p.postes.includes('Responsable') ? 0.6 : 1) * D.bruit('vd' + p.nom + x.h, 0.25)), tot = poids.reduce((a, v) => a + v, 0);
    en.forEach((p, i) => { const o = V[p.nom] || (V[p.nom] = { nom: p.nom, ca: 0, tickets: 0, articles: 0 }); const f = poids[i] / tot; o.ca += x.ca * f; o.tickets += x.tickets * f; });
  });
  return Object.values(V).map(o => ({ nom: o.nom, ca: r2(o.ca), tickets: Math.round(o.tickets), articles: Math.round(o.tickets * 2.3) }));
}
/** /exploitation/pro/detail : les tickets B2B du jour. */
function proDetail(s) {
  const J = D.journee(s, s === AUJ ? MAINTENANT : null), T = [];
  const SOC = { 8: ['Crèche Les Petits Pas', [['Pain de mie', 2, 3.6], ['Croissant au beurre', 20, 1.26], ['Baguette tradition', 6, 1.35]]], 11: ['Bureau d’études Exemple', [['Sandwich jambon-beurre', 6, 4.41], ['Wrap végétarien', 4, 4.68], ['Cookie chocolat', 10, 1.98]]] };
  J.heures.filter(x => x.proN).forEach(x => { const [soc, L] = SOC[x.h] || ['Client pro', []]; const lignes = L.map(([nom, q, pu]) => ({ nom, q, pu, total: r2(q * pu) }));
    const tot = lignes.reduce((a, l) => a + l.total, 0), k = tot ? x.pro / tot : 1; lignes.forEach(l => { l.pu = r2(l.pu * k); l.total = r2(l.q * l.pu); });
    T.push({ heure: String(x.h).padStart(2, '0') + ':' + (x.h === 8 ? '12' : '47'), societe: soc, articles: lignes.reduce((a, l) => a + l.q, 0), montant: x.pro, facture: true, differe: false, lignes }); });
  const caPro = J.caPro, tk = J.tickets;
  return { date: s, maintenant: s === AUJ ? D.hm(MAINTENANT) : null,
    jour: { caPro, part: J.ca ? r1(100 * caPro / J.ca) : null, ticketsPro: J.ticketsPro, societes: T.length, tickets: tk, panierPro: J.ticketsPro ? r2(caPro / J.ticketsPro) : null, panierComptoir: r2(J.caComptoir / J.ticketsComptoir), aFacturer: caPro, factures: T.length, differes: { n: 0, ca: 0 } },
    tickets: T, categories: [{ cat: 'Viennoiserie', ca: r2(caPro * 0.34) }, { cat: 'Snacking', ca: r2(caPro * 0.41) }, { cat: 'Pains', ca: r2(caPro * 0.15) }, { cat: 'Biscuiterie', ca: r2(caPro * 0.1) }], sansTva: {} };
}
/** /exploitation/commandes/detail : les commandes du jour, rapprochées de la caisse. */
function commandesDetail(s) {
  const C = canaux(s), maint = s === AUJ ? D.hm(MAINTENANT) : '24:00';
  const ART = { compt: [['Entremets trois chocolats', 1, 24], ['Croissant au beurre', 6, 1.4]], cc: [['Pain de campagne', 1, 3.8], ['Pain au chocolat', 8, 1.6], ['Tarte aux pommes (part)', 4, 3.2]], liv: [['Sandwich poulet crudités', 4, 5.5], ['Salade César', 2, 7.9]] };
  const commandes = C.liste.map(c => {
    const passee = c.heure <= maint, etat = !passee ? 'avenir' : (c.canal === 'compt' ? 'caisse' : (c.canal === 'cc' ? 'avant' : 'ligne'));
    const L = ART[c.canal].map(([nom, q, pu]) => ({ nom, q, pu, total: r2(q * pu) })), tot = L.reduce((a, l) => a + l.total, 0), k = c.montant / tot;
    L.forEach(l => { l.pu = r2(l.pu * k); l.total = r2(l.q * l.pu); });
    return { canal: c.canal, heure: c.heure, montant: c.montant, statut: passee ? 'remise' : 'à préparer', etat, articles: L.reduce((a, l) => a + l.q, 0), lignes: L,
      ticket: etat === 'caisse' ? { date: s, heure: c.heure } : (etat === 'avant' ? { date: D.plus(s, -1), heure: '17:20' } : null), montantCaisse: null };
  });
  const J = D.journee(s, s === AUJ ? MAINTENANT : null);
  return { date: s, maintenant: s === AUJ ? D.hm(MAINTENANT) : null, caisseJour: J.ca, commandes };
}
const VOISINS = [[1, 1.32], [2, 0.86], [3, 1.12], [5, 0.88], [6, 1.48], [7, 0.78], [8, 0.95], [9, 0.82]];
function exploitationJour(s) {
  const moi = ligneMagasin(s, SHOP, NOM, 1);
  // Le jour dans le mois : chaque jour ouvert du mois jusqu'à la date, sa marge nette.
  const deb = s.slice(0, 8) + '01';
  for (let d = deb; d <= s; d = D.plus(d, 1)) {
    const J = D.journee(d, d === AUJ ? MAINTENANT : null), P = D.pnl(d + SHOP, J.ca);
    moi.serie.push({ date: d, ouvert: true, ca: J.ca, net: P.net, netPct: r1(100 * P.net / P.caHt) });
  }
  moi.categories = D.categoriesDe(s, moi.ca).map(c => ({ categorie: c.nom, ca: c.v, part: Math.round(c.part * 100) / 10000, ref: r2(c.v * 0.97), delta: 3.1 }));
  const L = [moi].concat(VOISINS.map(([id, k]) => {
    const l = ligneMagasin(s, String(100 + id), 'Magasin ' + id, k);
    l.margeBrutePct = r1(l.margeBrutePct + (alea('mb' + id)() * 8 - 4)); l.netPct = r1(l.netPct + (alea('nt' + id)() * 10 - 5));
    l.panierRetenu = r2(l.panierRetenu * bruit('pn' + id, 0.12)); l.caPro = r2(l.caPro * bruit('cp' + id, 0.6));
    delete l.serie; return l;
  }));
  const tot = L.reduce((a, l) => ({ ca: a.ca + l.ca, tk: a.tk + l.tickets, caC: a.caC + l.caComptoir, tkC: a.tkC + l.ticketsComptoir }), { ca: 0, tk: 0, caC: 0, tkC: 0 });
  return {
    date: s, estAujourdhui: s === AUJ, relu: D.hm(MAINTENANT),
    reference: { libelle: 'moyenne des 4 derniers ' + D.JOURS[D.dow(s)] + 's' },
    seuils: { food: 32, labour: 17, overhead: 13.5 },
    magasins: L.sort((a, b) => b.ca - a.ca),
    reseau: { magasins: L.length, ca: r2(tot.ca), tickets: tot.tk, panier: r2(tot.ca / tot.tk), panierComptoir: r2(tot.caC / tot.tkC), panierBase: 'comptoir', categories: [] },
  };
}

/** /ventes/stats?vue=jour : les tickets du jour, heure par heure, et les catégories avec leur marge. */
function ventesStatsJour(s) {
  const auj = s === AUJ, J = D.journee(s, auj ? MAINTENANT : null);
  const plan = D.planningDe(s);
  const cats = D.categoriesDe(s, J.ca);
  const heures = J.heures.map(x => {
    const en = plan.filter(p => D.toMin(p.debut) < (x.h + 1) * 60 && D.toMin(p.fin) > x.h * 60);
    const trav = en.filter(p => !p.franchise).reduce((a, p) => a + p.tauxH * Math.min(1, (Math.min(D.toMin(p.fin), (x.h + 1) * 60) - Math.max(D.toMin(p.debut), x.h * 60)) / 60), 0);
    return { h: x.h, ca: x.ca, tickets: x.tickets, mat: r2(x.ca / 1.06 * 0.33), mb: r2(x.ca / 1.06 * 0.67), trav: r2(trav), poste: en.length, panier: r2(x.ca / x.tickets) };
  });
  const best = heures.slice().sort((a, b) => b.ca - a.ca)[0];
  return { shop: SHOP, vue: 'jour', date: s, totaux: { ca: J.ca, tickets: J.tickets }, heures, categories: cats, meilleure: best ? { h: best.h } : null, produits: { aSuivre: false }, aCompleter: null };
}

/** /production/flux/suivi : la vitrine, produit par produit, heure par heure. */
function suiviProduction(s) {
  const auj = s === AUJ, H = D.HEURES, nowH = auj ? MAINTENANT / 60 : 99, P = D.poids(s);
  const fac = D.caJourPlein(s) / D.BASE[D.dow(s)];
  // Les cuissons du jour : 06:30, 10:30, 13:30 faites ; 16:30 à venir.
  const CUI = [['Cuisson du matin', '06:30', '1'], ['Cuisson de 10 h 30', '10:30', '1'], ['Cuisson de midi', '13:30', '2'], ['Cuisson de l’après-midi', '16:30', '1']];
  const cuissons = CUI.map(([nom, de, four]) => ({ nom, de, four, valide: auj ? D.toMin(de) < MAINTENANT : true, lignes: [] }));
  // Quelques produits dont la journée se passe moins bien (rupture, va manquer, trop).
  const SORT = { 'Sandwich poulet crudités': 'rupture', 'Croissant au beurre': 'manque', 'Pain au chocolat': 'manque', 'Éclair au chocolat': 'trop', 'Tartelette aux fruits': 'manque', 'Baguette tradition': 'ok' };
  const produits = D.PRODUITS.filter(p => p.groupe !== 'Boissons').map(p => {
    const jourQ = p.q * fac * bruit('q' + s + p.pid, 0.1);
    const prevH = {}, moy = {}, vc = {};
    H.forEach((h, i) => { const w = p.cat === 'Sandwichs' || p.cat === 'Salades et bowls' ? [0, 0.02, 0.03, 0.06, 0.18, 0.3, 0.22, 0.08, 0.04, 0.03, 0.03, 0.01][i] : P[i]; prevH[h] = r1(jourQ * w); moy[h] = r1(jourQ * w * bruit('m' + p.pid + h, 0.1)); });
    if (p.vie === 'L') {
      const stock = Math.round(p.q * (p.nom === 'Cookie chocolat' ? 1.2 : 4.5) * bruit('st' + p.pid, 0.3));
      let vendu = 0; H.forEach(h => { if (h + 1 <= nowH || (h < nowH)) { const v = Math.round(prevH[h] * bruit('v' + s + p.pid + h, 0.3) * Math.min(1, nowH - h)); vc[h] = v; vendu += v; } });
      return { pid: p.pid, nom: p.nom, cat: p.cat, groupe: p.groupe, vie: 'L', prix: p.prix, sorti: 0, vendu, stock: stock - vendu, stockMin: Math.round(p.q * 1.5), moyJ: r1(jourQ), prevJ: r1(jourQ), prevH, moy, vc, cases: [], verdict: 'ok', conseil: null, finJour: stock - vendu };
    }
    const sorte = SORT[p.nom] || 'ok';
    // Les sorties : 60 % le matin, 25 % à 10 h 30, 15 % à midi ; plus ou moins selon la sorte.
    const k = sorte === 'rupture' ? 0.72 : sorte === 'manque' ? 0.86 : sorte === 'trop' ? 1.35 : 1.06;
    const tot = Math.round(jourQ * k);
    const sorties = { '06:30': Math.round(tot * 0.6), '10:30': Math.round(tot * 0.25), '13:30': tot - Math.round(tot * 0.6) - Math.round(tot * 0.25) };
    if (p.cat === 'Sandwichs' || p.cat === 'Salades et bowls') { sorties['06:30'] = Math.round(tot * 0.15); sorties['10:30'] = Math.round(tot * 0.7); sorties['13:30'] = tot - sorties['06:30'] - sorties['10:30']; }
    cuissons.forEach(c => { const q = sorties[c.de]; if (q > 0) { c.lignes.push({ pid: p.pid, sortie: q }); } });
    let st = 0, vendu = 0;
    const cases = H.map(h => {
      Object.entries(sorties).forEach(([de, q]) => { const m = D.toMin(de) / 60; if (m >= h && m < h + 1 || (h === H[0] && m < H[0])) { st += q; } });
      if (h + 1 <= nowH) {
        const dem = prevH[h] * bruit('d' + s + p.pid + h, 0.25), v = Math.max(0, Math.min(st, Math.round(dem)));
        st -= v; vendu += v; vc[h] = v;
        return { h, q: st, v, reel: true, prev: prevH[h] };
      }
      if (h < nowH) {   // l'heure entamée
        const fr = nowH - h, v = Math.max(0, Math.min(st, Math.round(prevH[h] * fr)));
        st -= v; vendu += v; vc[h] = v;
        const reste = prevH[h] * (1 - fr);
        return { h, q: r1(st - reste), v, reel: false, prev: r1(reste) };
      }
      return { h, q: null, v: null, reel: false, prev: prevH[h] };
    });
    // Les heures à venir : la vitrine projetée (prévision retirée).
    const stockNow = st;
    let proj = cases.find(c => c.h < nowH && !c.reel) ? cases.find(c => c.h < nowH && !c.reel).q : st;
    cases.forEach(c => { if (c.h >= nowH && c.q === null) { proj -= prevH[c.h]; c.q = r1(proj); } });
    const reste = H.reduce((a, h) => a + prevH[h] * Math.max(0, Math.min(1, h + 1 - nowH)), 0);
    let verdict = 'ok', conseil = null;
    if (stockNow <= 0 && reste >= 0.5) { verdict = 'rupture'; conseil = { pieces: Math.ceil(reste), plaque: 12 }; }
    else if (proj < -0.5) { verdict = 'manque'; conseil = { pieces: Math.ceil(-proj), plaque: 12 }; }
    else if (proj > 4 && p.vie === 'S') { verdict = 'trop'; }
    return { pid: p.pid, nom: p.nom, cat: p.cat, groupe: p.groupe, vie: p.vie, prix: p.prix, sorti: tot, vendu, stock: stockNow, prevH, moy, vc, cases, verdict, conseil,
      finJour: r1(proj), moyJ: r1(jourQ), prevJ: r1(jourQ), manque: verdict === 'rupture' || verdict === 'manque' ? { q: r1(Math.max(1, -proj)) } : null };
  });
  const tot = k => produits.reduce((a, p) => a + Math.max(0, +p[k] || 0), 0);
  return { shop: SHOP, date: s, jourNom: D.JOURS[D.dow(s)], maintenant: auj ? D.hm(MAINTENANT) : null, heures: H, base: { lus: 6, semaines: 6 },
    cuissons, produits, totaux: { sorti: tot('sorti'), vendu: tot('vendu'), stock: tot('stock') } };
}

/** /exploitation/canaux : les commandes du jour (comptoir, click & collect, livraison) et celles de J−7. */
function canaux(s) {
  const auj = s === AUJ;
  const mk = (d, seed) => {
    const r = alea('cmd' + d + seed), L = [];
    const H = ['09:30', '10:15', '11:45', '12:30', '14:00', '16:30', '17:15', '17:45'];
    H.forEach((h, i) => {
      const canal = i % 3 === 0 ? 'cc' : (i % 4 === 1 ? 'liv' : 'compt');
      const montant = r2(18 + r() * 42), passee = !auj || d < AUJ || D.toMin(h) < MAINTENANT;
      L.push({ id: 'C' + (500 + i), canal, montant, heure: h, quand: d + ' ' + h, statut: passee ? 'remise' : 'à préparer', encaissee: canal === 'compt' ? passee : true, pro: false, differe: false, client: 'Client ' + (i + 1) });
    });
    return L;
  };
  const j7 = D.plus(s, -7);
  return { date: s, liste: mk(s, 1), caisseCmd: mk(s, 1).filter(c => c.canal === 'compt' && c.encaissee).map(c => ({ heure: c.heure, montant: c.montant, pro: false })),
    j7: { date: j7, liste: mk(j7, 1), caisseCmd: mk(j7, 1).filter(c => c.canal === 'compt').map(c => ({ heure: c.heure, montant: c.montant, pro: false })) } };
}

module.exports = { exploitationJour, ventesStatsJour, suiviProduction, canaux, ligneMagasin, proDetail, commandesDetail };
