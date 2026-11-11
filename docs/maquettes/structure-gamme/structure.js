/* Structure de gamme — les règles, sans affichage.
 *
 * Une référence reçoit UN statut par catégorie du panel :
 *   standard    vendue toute l'année, parmi celles qui font stdPart (80 %) du CA ou de la marge de la catégorie, stdMax (6) au plus ;
 *   saison      vendue surtout pendant une ou deux saisons, parmi les saiMax (4) premières de la catégorie pour cette saison ;
 *   periode     vendue autour de Noël, du Nouvel An ou de Pâques, parmi les saiMax (4) premières de la catégorie ;
 *   desactiver  moins de « seuil » (3 %) du CA de sa catégorie, sur les mois où elle se vend ;
 *   arbitrer    ni retenue ni sous le seuil : au-delà du plafond ou des 80 % — à garder hors structure ou à retirer ;
 *   observation lancée il y a moins de 6 mois : jugée à 6 mois de ventes, ni retenue ni retirée d'ici là ;
 *   hors        catégories qui ne sont pas des produits (B2B, bundles, extras payants) ;
 *   retiree     déjà inactive au catalogue et sans saison : ne compte plus.
 *
 * Le profil d'une référence est sa part du volume réseau, mois par mois, ramenée à 100 % sur l'année :
 * un magasin qui ouvre (Halle en février) ou un mois en cours (novembre) ne déforme pas la saison.
 * Toutes les références du jeu de données sont rangées ; le moteur ne fait aucun appel.
 */
(function (racine) {
  'use strict';

  // Les variables de la structure, et leur valeur par défaut. Toutes se passent en options à structurer() :
  // la maquette les règle dans sa ligne de règles. Seuil de désactivation : 3 % (décision du 11/11/2026, 5 % avant).
  const REGLES = { base: 'ca', stdPart: 0.8, stdMax: 6, saiPart: 0.8, saiMax: 4, seuil: 0.03 };
  // Ce qui n'est pas un produit à structurer : le B2B, les bundles et paniers, les extras facturés.
  const HORS_GRP = ['B. 2 B.', 'Bundle & Promotion'];
  const horsDe = p => HORS_GRP.includes(p.grp) || /^Extra\b/.test(p.cat);
  const VOL_SAISON = 80;    // en dessous de 80 pièces sur l'année, un profil mensuel n'est que du bruit
  const SAISONS = [
    { id: 'hiver', nom: 'Hiver', mois: [12, 1, 2], lib: 'déc. – févr.' },
    { id: 'printemps', nom: 'Printemps', mois: [3, 4, 5], lib: 'mars – mai' },
    { id: 'ete', nom: 'Été', mois: [6, 7, 8], lib: 'juin – août' },
    { id: 'automne', nom: 'Automne', mois: [9, 10, 11], lib: 'sept. – nov.' }];
  const PERIODES = [
    { id: 'noel', nom: 'Noël', mois: [11, 12], lib: '15 nov. – 26 déc.' },
    { id: 'nouvelan', nom: 'Nouvel An', mois: [12, 1], lib: '27 déc. – 15 janv.' },
    { id: 'paques', nom: 'Pâques', mois: [3, 4], lib: '3 semaines avant Pâques' }];

  function structurer(D, opts) {
    const R = Object.assign({}, REGLES, opts || {});
    const n = D.mois.length;
    const mc = D.bornes.map(b => +b[0].slice(5, 7));            // mois calendaire de chaque index
    const idx = m => mc.indexOf(m);
    const somme = a => a.reduce((s, x) => s + x, 0);

    // 1. La nature de chaque référence : d'après son profil, jamais d'après son nom.
    const P = D.produits.map(p0 => {
      const p = Object.assign({}, p0);
      if (horsDe(p)) { p.nature = 'hors'; return p; }
      if (!p.u) {
        // Sous le plancher de lecture sur douze mois. Si le dernier mois en a vendu au moins le tiers du
        // plancher, elle n'a pas pu vendre à ce rythme toute l'année : c'est un lancement, on l'observe.
        p.nature = !p.actif ? 'retiree' : (p.v1 || 0) >= D.plancher / 3 ? 'observation' : 'faible';
        if (p.nature === 'observation') { p.recente = true; }
        return p;
      }
      const sh = p.u.map((u, i) => D.total[i] ? u / D.total[i] : 0);
      const st = somme(sh) || 1;
      const pr = sh.map(x => x / st);
      p.profil = pr.map(x => Math.round(x * 1000) / 1000);
      const M = m => pr[idx(m)] || 0;
      const pieces = somme(p.u);
      p.pu = pieces ? p.ca / pieces : 0;                          // prix encaissé moyen
      p.mu = (p.mg != null && pieces) ? p.mg / pieces : null;     // marge par pièce
      const max = Math.max(...p.u);
      p.debut = p.u.findIndex(u => u >= 0.1 * max);
      p.fin = n - 1 - p.u.slice().reverse().findIndex(u => u >= 0.1 * max);
      // Les périodes d'abord : un pic court et daté.
      if (M(12) >= 0.3 && M(11) + M(12) >= 0.7 && M(12) >= M(1)) { p.nature = 'periode'; p.periode = 'noel'; }
      else if (M(1) >= 0.4 && M(12) + M(1) >= 0.8 && M(1) > M(12)) { p.nature = 'periode'; p.periode = 'nouvelan'; }
      else if (M(3) + M(4) >= 0.6 && M(2) + M(3) + M(4) + M(5) >= 0.85) { p.nature = 'periode'; p.periode = 'paques'; }
      if (p.nature) { p.mois = PERIODES.find(x => x.id === p.periode).mois.map(idx).filter(i => i >= 0); return p; }
      if (!p.actif) { p.nature = 'retiree'; return p; }
      // Lancée il y a moins de six mois : on ne juge pas encore.
      if (p.debut >= n - 6) { p.nature = 'observation'; p.mois = range(p.debut, n); return p; }
      // Une saison : un creux de trois mois, et l'essentiel du volume sur une ou deux saisons. Un creux en début
      // d'année suivi de ventes encore hautes en octobre, c'est un lancement, pas une saison.
      let creux = 1, ou = 0;
      for (let i = 0; i < n; i++) {
        const v = pr[i] + pr[(i + 1) % n] + pr[(i + 2) % n];
        if (v < creux) { creux = v; ou = i; }
      }
      const parS = SAISONS.map(s => somme(s.mois.map(M)));
      const garde = SAISONS.filter((s, k) => parS[k] >= 0.25);
      // Deux saisons : voisines (printemps-été), jamais deux pics isolés (un mois d'hiver, un mois d'été).
      const voisines = garde.length < 2 || [1, 3].includes(Math.abs(SAISONS.indexOf(garde[0]) - SAISONS.indexOf(garde[1])));
      const recent = Math.max(pr[idx(9)] || 0, pr[idx(10)] || 0);
      const lancement = ou <= 1 && recent >= 0.6 * Math.max(...pr);
      if (pieces >= VOL_SAISON && creux <= 0.05 && !lancement && voisines && garde.length >= 1 && garde.length <= 2
          && somme(garde.map(s => parS[SAISONS.indexOf(s)])) >= 0.7) {
        p.nature = 'saison'; p.saisons = garde.map(s => s.id);
        p.mois = [].concat(...garde.map(s => s.mois.map(idx))).filter(i => i >= 0);
        return p;
      }
      // Vendue toute l'année — ou depuis son lancement : sa part se mesure sur ses mois en rayon, son rang
      // dans la catégorie sur les douze mois (une nouveauté n'entre au standard que si elle pèse déjà).
      p.nature = 'permanent';
      if (lancement || p.debut > 1) { p.lance = p.debut; }
      p.mois = range(p.lance || 0, n);
      return p;
    });

    // 2. Par catégorie : la valeur de chaque référence sur ses mois, et sa part de la catégorie sur ces mêmes mois.
    const parCat = new Map();
    P.forEach(p => {
      if (p.nature === 'hors' || p.nature === 'retiree') { return; }
      if (!parCat.has(p.cat)) { parCat.set(p.cat, { cat: p.cat, grp: p.grp, refs: [] }); }
      parCat.get(p.cat).refs.push(p);
    });
    const caMois = p => p.u ? p.u.map(u => u * p.pu) : null;
    const cats = [...parCat.values()].map(C => {
      const lus = C.refs.filter(p => p.u);
      const caCat = range(0, n).map(i => somme(lus.map(p => p.u[i] * p.pu)));
      // Marge manquante : le taux médian de la catégorie, et on le dit.
      const taux = lus.filter(p => p.mg != null && p.ca > 0).map(p => p.mg / p.ca).sort((a, b) => a - b);
      const med = taux.length ? taux[Math.floor(taux.length / 2)] : null;
      C.ca = Math.round(somme(caCat));
      C.mg = Math.round(somme(lus.map(p => p.mg != null ? p.mg : (med != null ? p.ca * med : 0))));
      C.mgEstimees = lus.filter(p => p.mg == null).length;
      C.actives = C.refs.filter(p => p.actif).length;
      const val = (p, mois) => {
        const ca = somme(mois.map(i => p.u[i] * p.pu));
        if (R.base !== 'mg') { return ca; }
        if (p.mg != null) { return somme(mois.map(i => p.u[i] * p.mu)); }
        p.mgEst = true; return med != null ? ca * med : 0;
      };
      C.refs.forEach(p => {
        if (!p.u) {
          // Sous le plancher de lecture : au plus « plancher » pièces en douze mois. Une catégorie
          // entière sous le plancher n'a pas de total : rien à comparer, on arbitre.
          p.partMax = C.ca ? D.plancher * (p.prix || 0) / C.ca : Infinity;
          p.part = null;
          return;
        }
        const tot = somme(p.mois.map(i => caCat[i]));
        p.caPer = somme(p.mois.map(i => p.u[i] * p.pu));
        p.part = tot ? p.caPer / tot : 0;
        p.val = val(p, p.nature === 'permanent' ? range(0, n) : p.mois);
      });

      // Le standard : les références vendues toute l'année, de la plus forte à la plus faible, jusqu'à 80 %.
      // Une référence sous le seuil n'y entre pas, même seule de sa catégorie : elle compte dans le total.
      const perm = C.refs.filter(p => p.nature === 'permanent').sort((a, b) => b.val - a.val);
      const totPerm = somme(perm.map(p => p.val));
      let cum = 0;
      C.std = [];
      perm.forEach((p, k) => {
        p.rang = k + 1; p.cumAvant = totPerm ? cum / totPerm : 0;
        if (C.std.length < R.stdMax && cum < R.stdPart * totPerm && p.part >= R.seuil) { C.std.push(p); p.statut = 'standard'; }
        cum += p.val; p.cum = totPerm ? cum / totPerm : 0;
      });
      C.couverture = totPerm ? somme(C.std.map(p => p.val)) / totPerm : null;
      C.plafonne = C.std.length === R.stdMax && C.couverture < R.stdPart;
      C.valPerm = totPerm;

      // Les saisons et les périodes : même règle, plafond 4, sur la valeur réalisée pendant la saison.
      const choisir = (cands, mois) => {
        const v = cands.map(p => ({ p, v: val(p, mois) })).sort((a, b) => b.v - a.v);
        const t = somme(v.map(x => x.v)); let c = 0; const pris = [];
        v.forEach(x => { if (pris.length < R.saiMax && c < R.saiPart * t && x.p.part >= R.seuil) { pris.push(x.p); } c += x.v; });
        return { pris, tot: t, v };
      };
      C.saisons = {};
      SAISONS.forEach(s => {
        const mois = s.mois.map(idx).filter(i => i >= 0);
        const r = choisir(C.refs.filter(p => p.nature === 'saison' && p.saisons.includes(s.id)), mois);
        C.saisons[s.id] = r.pris;
        r.pris.forEach(p => { p.statut = 'saison'; (p.retenue = p.retenue || []).push(s.id); });
      });
      C.periodes = {};
      PERIODES.forEach(e => {
        const r = choisir(C.refs.filter(p => p.nature === 'periode' && p.periode === e.id), p0mois(e));
        C.periodes[e.id] = r.pris;
        r.pris.forEach(p => { p.statut = 'periode'; });
      });
      function p0mois(e) { return e.mois.map(idx).filter(i => i >= 0); }

      // Le reste : sous le seuil, on désactive ; au-dessus, on arbitre ; une nouveauté, on observe.
      C.refs.forEach(p => {
        if (p.statut) { return; }
        if (p.nature === 'observation') { p.statut = 'observation'; return; }
        if (p.part == null) { p.statut = p.partMax < R.seuil ? 'desactiver' : 'arbitrer'; return; }
        p.statut = p.part < R.seuil ? 'desactiver' : 'arbitrer';
      });
      ['desactiver', 'arbitrer', 'observation'].forEach(s => {
        C[s] = C.refs.filter(p => p.statut === s).sort((a, b) => (b.caPer || 0) - (a.caPer || 0));
      });
      return C;
    });

    // 3. Les groupes, dans l'ordre du CA.
    const grps = new Map();
    cats.forEach(C => {
      if (!grps.has(C.grp)) { grps.set(C.grp, { grp: C.grp, ca: 0, cats: [] }); }
      const g = grps.get(C.grp); g.ca += C.ca; g.cats.push(C);
    });
    const groupes = [...grps.values()].sort((a, b) => b.ca - a.ca);
    groupes.forEach(g => g.cats.sort((a, b) => b.ca - a.ca));

    const actifs = P.filter(p => p.actif && p.nature !== 'hors');
    const compte = s => actifs.filter(p => p.statut === s).length;
    const caStruct = somme(cats.map(C => C.ca));
    const caDe = l => somme(l.map(p => p.caPer || 0));
    return {
      regles: R, saisons: SAISONS, periodes: PERIODES, hors: HORS_GRP, groupes, cats, produits: P,
      totaux: {
        actives: P.filter(p => p.actif).length, structure: actifs.length,
        hors: P.filter(p => p.actif && p.nature === 'hors').length,
        standard: compte('standard'), saison: compte('saison'), periode: compte('periode'),
        aReactiver: P.filter(p => !p.actif && p.statut === 'periode').length,
        desactiver: compte('desactiver'), arbitrer: compte('arbitrer'), observation: compte('observation'),
        caStructure: caStruct,
        caStandard: caDe(actifs.filter(p => p.statut === 'standard')),
        caDesactiver: caDe(actifs.filter(p => p.statut === 'desactiver')),
        retirees: P.filter(p => p.nature === 'retiree').length,
        doublons: actifs.filter(p => p.doublon).length,
      },
    };
  }

  function range(a, b) { const r = []; for (let i = a; i < b; i++) { r.push(i); } return r; }

  const API = { structurer, REGLES, SAISONS, PERIODES, HORS_GRP };
  if (typeof module !== 'undefined' && module.exports) { module.exports = API; } else { racine.Structure = API; }
})(typeof window !== 'undefined' ? window : globalThis);
