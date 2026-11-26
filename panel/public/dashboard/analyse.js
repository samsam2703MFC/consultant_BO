/* Dashboard magasin › Vendre › Analyse.
 *
 * 15/11/2026, « go E et F, A en entrée » (maquettes docs/maquettes/rail-franchise/analyse-a, -e, -f) :
 *   l'entrée (A)       l'essentiel des 13 dernières semaines : chiffre, pièces, marge réalisée, panier, les familles, le
 *                      chiffre semaine par semaine, et quatre classements (gros chiffres, marge, ce qui monte, ce qui baisse) ;
 *                      un clic sur une famille ouvre sa page, un clic sur un produit ouvre la sienne ;
 *   le produit (E)     « ce qu'un exploitant veut savoir d'un produit », une carte par question : combien j'en vends, combien
 *                      il me rapporte, combien j'en jette, quel jour il part, mon prix est-il bon, est-ce que je fais mieux que
 *                      les autres, comment a-t-il évolué depuis l'ouverture ;
 *   la famille (F)     la même chose pour une famille : son poids, sa tendance, qui la porte, ce qui monte ou baisse, ce
 *                      qu'elle perd, à quelle heure elle se vend, les autres magasins, les mois depuis l'ouverture.
 * Lectures :
 *   GET …/dashboard/analyse/periode?shop=&du=&au=&prec= la période choisie : chaque produit tranche par tranche, la période
 *                                                       d'avant, son prix et sa recette (semaine, mois, trimestre, année, 13 semaines, du … au)
 *   GET …/ventes/mensuel?shop=&mois=4                    les tickets des mois (le panier moyen)
 *   GET …/exploitation/invendus/detail?shop=&du=&au=     les pièces jetées de la période, produit par produit
 *   GET …/analyse/produits/magasin?shop=&pid=&mois=3     le prix encaissé face au réseau (repères anonymes)
 *   GET …/analyse/categories/journee?shop=&niveau=groupe&nom=&date=   une journée d'une famille, heure par heure
 *   GET …/dashboard/analyse/reseau|mois|jours            le réseau à taille égale, les mois, les jours (src/franchise/dashboard_analyse.php)
 * Les évolutions sont des flèches (▲ vert, ▶ gris, ▼ orange ou rouge), pas des mini-graphiques. Les autres magasins ne
 * sont jamais nommés. Une question sans donnée le dit ; les questions qu'aucune donnée n'éclaire ne sont pas posées.
 *
 * `window.DashAnalyse.racine(shop, {apiBase})` rend l'élément du module, gardé d'un rendu à l'autre.
 */
(function () {
  'use strict';

  /* --- formats ------------------------------------------------------------------------------------------------------ */
  const sp = s => String(s).replace(/[  ]/g, ' ');
  const nf = (v, d) => sp(Number(v).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }));
  const n0 = v => v == null || isNaN(v) ? '—' : nf(Math.round(v), 0);
  const n1 = v => v == null || isNaN(v) ? '—' : nf(v, 1);
  const e0 = v => v == null || isNaN(v) ? '—' : n0(v) + ' €';
  const e2 = v => v == null || isNaN(v) ? '—' : nf(v, 2) + ' €';
  const k1 = v => v == null || isNaN(v) ? '—' : Math.abs(v) >= 1000 ? sp((v / 1000).toLocaleString('fr-BE', { maximumFractionDigits: 1 })) + ' k€' : e0(v);
  const pc = v => v == null || isNaN(v) ? '—' : n1(v) + ' %';
  const sg = v => { if (v == null || isNaN(v)) { return '—'; } const r = Math.round(v * 10) / 10; return (r > 0 ? '+' : r < 0 ? '−' : '') + n1(Math.abs(r)) + ' %'; };
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const somme = a => (a || []).reduce((s, x) => s + (x || 0), 0);
  const med = a => { const b = a.filter(x => x != null).slice().sort((x, y) => x - y), m = b.length >> 1; return !b.length ? null : b.length % 2 ? b[m] : (b[m - 1] + b[m]) / 2; };
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const dfr = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? (+s.slice(8, 10)) + ' ' + MOIS[+s.slice(5, 7) - 1] : (s || '—');
  const iso = t => t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
  const JL = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const COUL = ['#8D1D2C', '#C08A2B', '#3F7A52', '#5C6F91', '#B5654A', '#7A5C8E', '#2F8F8C', '#A3A04A', '#9C7B6B', '#6B6B6B', '#C45C7B', '#4C8DB0'];
  const er = n => n === 1 ? '1<sup>er</sup>' : n + '<sup>e</sup>';
  const ere = n => n === 1 ? '1<sup>re</sup>' : n + '<sup>e</sup>';
  const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
  const I = { chercher: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35', retour: 'M15 18l-6-6 6-6' };

  /* --- la période (15/11/2026, « on doit pouvoir choisir mois, semaine, trimestre, année et range ») ------------------- */
  const TYPES = [['semaine', 'Semaine'], ['mois', 'Mois'], ['trimestre', 'Trimestre'], ['annee', 'Année'], ['sem13', '13 semaines'], ['plage', 'Du … au']];
  const MOISL = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const jd = s => new Date(s + 'T12:00:00');
  const plus = (s, n) => { const t = jd(s); t.setDate(t.getDate() + n); return iso(t); };
  const lundi = s => { const t = jd(s); t.setDate(t.getDate() - (t.getDay() + 6) % 7); return iso(t); };
  const nbJours = (du, au) => Math.round((jd(au) - jd(du)) / 864e5) + 1;
  const premier = (an, m) => iso(new Date(an, m, 1, 12)), dernier = (an, m) => iso(new Date(an, m + 1, 0, 12));
  /** Les bornes d'une période (du, au), le début de la période d'avant (prec) et ses libellés. */
  function bornesPer(p) {
    const a = p.ancre, an = +a.slice(0, 4), m = +a.slice(5, 7) - 1;
    if (p.type === 'semaine') { const du = lundi(a); return { du, au: plus(du, 6), prec: plus(du, -7), lib: 'semaine du ' + dfr(du) + ' au ' + dfr(plus(du, 6)), sur: 'sur la semaine', avant: 'la semaine d’avant', face: 'face à la semaine d’avant' }; }
    if (p.type === 'mois') { return { du: premier(an, m), au: dernier(an, m), prec: premier(an, m - 1), lib: MOISL[m] + ' ' + an, sur: 'en ' + MOISL[m], avant: MOISL[(m + 11) % 12], face: 'face à ' + MOISL[(m + 11) % 12] }; }
    if (p.type === 'trimestre') { const q = Math.floor(m / 3); return { du: premier(an, 3 * q), au: dernier(an, 3 * q + 2), prec: premier(an, 3 * q - 3), lib: (q + 1) + (q ? 'e' : 'er') + ' trimestre ' + an, sur: 'sur le trimestre', avant: 'le trimestre d’avant', face: 'face au trimestre d’avant' }; }
    if (p.type === 'annee') { return { du: an + '-01-01', au: an + '-12-31', prec: (an - 1) + '-01-01', lib: 'année ' + an, sur: 'en ' + an, avant: String(an - 1), face: 'face à ' + (an - 1) }; }
    if (p.type === 'plage') { const n = nbJours(p.du, p.au); return { du: p.du, au: p.au, prec: plus(p.du, -n), lib: 'du ' + dfr(p.du) + ' au ' + dfr(p.au), sur: 'sur la période', avant: 'les ' + n + ' jours d’avant', face: 'face aux ' + n + ' jours d’avant' }; }
    const du = plus(lundi(a), -84); return { du, au: a, prec: plus(du, -91), lib: '13 semaines, du ' + dfr(du) + ' au ' + dfr(a), sur: 'sur 13 semaines', avant: 'les 13 semaines d’avant', face: 'face aux 13 semaines d’avant' };
  }
  /** La période d'à côté : ‹ (−1) ou › (+1). */
  function decaler(sens) {
    const p = st.per, a = p.ancre, an = +a.slice(0, 4), m = +a.slice(5, 7) - 1;
    if (p.type === 'semaine') { p.ancre = plus(a, 7 * sens); }
    else if (p.type === 'mois') { p.ancre = premier(an, m + sens); }
    else if (p.type === 'trimestre') { p.ancre = premier(an, m + 3 * sens); }
    else if (p.type === 'annee') { p.ancre = (an + sens) + a.slice(4); }
    else if (p.type === 'plage') { const n = nbJours(p.du, p.au); p.du = plus(p.du, n * sens); p.au = plus(p.au, n * sens); }
    else { p.ancre = plus(a, 91 * sens); }
  }
  const PER = () => bornesPer(st.per);
  const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
  /** L'adresse de la page garde la période (15/11/2026, « garde la période dans l'adresse de la page ») et la page ouverte :
   * per (le type), a (la date repère) ou du et au (une plage), produit ou famille. Un rechargement rouvre la même vue. */
  function urlEcrire() {
    const u = new URL(location.href), q = u.searchParams, p = st.per;
    q.set('per', p.type);
    if (p.type === 'plage') { q.set('du', p.du); q.set('au', p.au); q.delete('a'); } else { q.set('a', p.ancre); q.delete('du'); q.delete('au'); }
    if (st.vue === 'produit' && st.pid) { q.set('produit', st.pid); } else { q.delete('produit'); }
    if (st.vue === 'famille' && st.groupe) { q.set('famille', st.groupe); } else { q.delete('famille'); }
    history.replaceState(history.state, '', u.toString());
  }
  function urlLire() {
    const q = new URLSearchParams(location.search), t = q.get('per'), auj = iso(new Date());
    if (TYPES.some(x => x[0] === t)) {
      const a = q.get('a'), du = q.get('du'), au = q.get('au');
      if (t === 'plage' && DATE_RE.test(du || '') && DATE_RE.test(au || '') && du <= au && du <= auj) { st.per = { type: t, ancre: au, du, au: au > auj ? auj : au }; }
      else if (t !== 'plage') { st.per = { type: t, ancre: DATE_RE.test(a || '') && a <= auj ? a : auj, du: null, au: null }; }
    }
    if (q.get('produit')) { st.vue = 'produit'; st.pid = q.get('produit'); } else if (q.get('famille')) { st.vue = 'famille'; st.groupe = q.get('famille'); }
  }
  const PK = () => { const b = PER(); return b.du + '|' + b.au; };
  const PQ = () => { const b = PER(); return '&du=' + b.du + '&au=' + b.au + '&prec=' + b.prec; };

  /** Une évolution en flèche : ▲ vert à +5 % et plus · ▶ gris entre −5 et +5 % · ▼ orange de −5 à −15 % · ▼ rouge au-delà. */
  function fleche(v) {
    if (v == null || isNaN(v)) { return '<span class="an-f mu">—</span>'; }
    const [cl, f] = v >= 5 ? ['up', '▲'] : v > -5 ? ['flat', '▶'] : v > -15 ? ['warn', '▼'] : ['down', '▼'];
    return `<span class="an-f ${cl}">${f} ${sg(v)}</span>`;
  }
  const LEGENDE_F = '<span class="an-legf"><span class="an-f up">▲</span> +5 % et plus <span class="an-f flat">▶</span> stable <span class="an-f warn">▼</span> −5 à −15 % <span class="an-f down">▼</span> −15 % et moins</span>';

  /* --- l'état et les lectures --------------------------------------------------------------------------------------- */
  const st = { shop: null, api: '../api/cockpit', el: null, vue: 'accueil', pid: null, groupe: null, jourF: 6, d: {}, err: {}, enCours: {}, prep: null, frais: false, cleBarre: null,
    per: { type: 'sem13', ancre: iso(new Date()), du: null, au: null } };

  function lire(p) {
    return fetch(st.api + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok && !(j && j.indispo) ? j : Promise.reject(new Error((j && ((j.error && j.error.message) || j.error || j.motif || j.message)) || ('HTTP ' + r.status)))));
  }
  /** Une lecture rangée sous `cle`, une seule fois (sauf « ↻ Relire ») ; la page se redessine à l'arrivée. */
  // « ↻ Relire » vide les lectures et passe st.frais : chacune est relue une fois, sans le cache partagé.
  function prendre(cle, chemin) {
    if (st.enCours[cle] || cle in st.d || cle in st.err) { return; }
    st.enCours[cle] = true;
    lire(chemin + (chemin.includes('?') ? '&' : '?') + (st.frais ? 'rafraichir=1' : '_cache=900'))
      .then(d => { st.d[cle] = d; delete st.err[cle]; })
      .catch(e => { st.err[cle] = e.message; delete st.d[cle]; })
      // La période arrivée, les lectures qui en dépendent partent (les invendus de la période, les mois d'une famille).
      .finally(() => { delete st.enCours[cle]; if (cle.startsWith('per|')) { charger(); } rendre(); });
  }
  const S = () => encodeURIComponent(st.shop);
  function charger() {
    const b = PER(), k = PK();
    prendre('per|' + k, '/dashboard/analyse/periode?shop=' + S() + PQ());
    prendre('mens', '/ventes/mensuel?shop=' + S() + '&mois=16');
    if (st.per.type === 'semaine') { prendre('sv|' + b.du, '/ventes/stats?shop=' + S() + '&vue=semaine&date=' + b.du); }
    const D = st.d['per|' + k];
    if (D) { prendre('inv|' + D.du + '|' + D.au, '/exploitation/invendus/detail?shop=' + S() + '&du=' + D.du + '&au=' + D.au); }
    if (st.vue === 'produit' && st.pid) {
      const p = encodeURIComponent(st.pid);
      prendre('fiche|' + st.pid, '/analyse/produits/magasin?shop=' + S() + '&pid=' + p + '&mois=3');
      prendre('res|p' + st.pid + '|' + k, '/dashboard/analyse/reseau?shop=' + S() + '&pid=' + p + PQ());
      prendre('mois|' + st.pid, '/dashboard/analyse/mois?shop=' + S() + '&pids=' + p);
      prendre('jours|' + st.pid + '|' + finJours(), '/dashboard/analyse/jours?shop=' + S() + '&pid=' + p + '&au=' + finJours());
    }
    if (st.vue === 'famille' && st.groupe) {
      const g = encodeURIComponent(st.groupe);
      prendre('res|g' + st.groupe + '|' + k, '/dashboard/analyse/reseau?shop=' + S() + '&groupe=' + g + PQ());
      prendre('journee|' + st.groupe + '|' + jourDe(st.jourF), '/analyse/categories/journee?shop=' + S() + '&niveau=groupe&nom=' + g + '&date=' + jourDe(st.jourF));
      const P = prep();
      if (P) {
        const ids = P.produits.filter(x => x.groupe === st.groupe).sort((a, b) => b.v13 - a.v13).slice(0, 80).map(x => x.id);
        if (ids.length) { prendre('moisG|' + st.groupe, '/dashboard/analyse/mois?shop=' + S() + '&pids=' + ids.join(',')); }
      }
    }
  }
  /** La fin des 28 jours de « Quel jour il part ? » : la fin de la période, au plus tard hier. */
  function finJours() { const h = plus(iso(new Date()), -1), au = PER().au; return au < h ? au : h; }
  /** Le dernier jour clos de la période qui tombe tel jour de la semaine (0 = dimanche). */
  function jourDe(j) { const t = jd(finJours()); while (t.getDay() !== j) { t.setDate(t.getDate() - 1); } return iso(t); }

  /* --- les données préparées ----------------------------------------------------------------------------------------- */
  /** La marge réalisée : CA HT − pièces × coût de la recette ; vendu en portions (prix moyen à plus de 15 % du prix de la
   * pièce), CA HT × le taux de marge de la recette — la règle du serveur (daMarge). */
  function margeDe(q, ca, ttc, ht, cout) {
    const caHt = ttc && ht ? ca * ht / ttc : null;
    if (!q) { return { caHt, marge: 0, taux: false }; }
    if (cout == null || caHt == null) { return { caHt, marge: null, taux: false }; }
    const taux = !(Math.abs(ca / q / ttc - 1) <= 0.15);
    return { caHt, marge: taux ? caHt * (1 - cout / ht) : caHt - q * cout, taux };
  }
  function coutDe(p) {
    const c = p.cout != null ? p.cout : (p.prixHt && p.marge != null ? p.prixHt * (1 - p.marge / 100) : null);
    return c != null && p.prixHt && c < p.prixHt && c >= 0.05 * p.prixHt ? c : null;
  }
  /** Le libellé d'une tranche : le jour (« lun. 9 », ou « 9 » au-delà d'une semaine), la semaine (« S46 »), le mois. */
  function libTranche(D, t) {
    if (D.pas === 'jour') { return D.tranches.length <= 8 ? JL[jd(t[0]).getDay()].slice(0, 3) + '. ' + (+t[0].slice(8, 10)) : String(+t[0].slice(8, 10)); }
    if (D.pas === 'semaine') { const x = jd(lundi(t[0])); x.setDate(x.getDate() + 3); const j1 = new Date(x.getFullYear(), 0, 4, 12); return 'S' + (1 + Math.round(((x - j1) / 864e5 - 3 + (j1.getDay() + 6) % 7) / 7)); }
    return MOIS[+t[0].slice(5, 7) - 1];
  }
  function prep() {
    const D = st.d['per|' + PK()];
    if (!D || !D.produits) { return null; }
    if (st.prep && st.prep.D === D) { return st.prep; }
    const n = D.tranches.length, libs = D.tranches.map(t => libTranche(D, t));
    // Par semaine dès deux semaines, sinon par jour.
    const parSem = D.jours >= 14, rythme = q => q / D.jours * (parSem ? 7 : 1), unite = parSem ? 'par semaine' : 'par jour';
    const avecDepuis = e => e && e.depuisI != null ? { ...e, depuis: libs[e.depuisI] } : e;
    const produits = D.produits.map(p => {
      const q13 = somme(p.q), v13 = somme(p.v), cout = coutDe(p), M = margeDe(q13, v13, p.prix, p.prixHt, cout);
      return { ...p, evo: avecDepuis(p.evo), q13, v13, cout, caHt: M.caHt, marge: M.marge, taux: M.taux, margePct: M.marge != null && M.caHt ? 100 * M.marge / M.caHt : null,
        qSem: rythme(q13) };
    }).filter(p => p.q13 > 0 || p.v13 > 0);
    const caCouv = somme(produits.filter(p => p.marge != null).map(p => p.caHt));
    const tot = { v: somme(D.total.v), q: somme(D.total.q), caHt: somme(produits.map(p => p.caHt || 0)), marge: somme(produits.map(p => p.marge || 0)) };
    tot.margePct = caCouv ? 100 * tot.marge / caCouv : null; tot.couverture = tot.caHt ? 100 * caCouv / tot.caHt : null;
    const groupes = D.groupes.map((g, i) => {
      const L = produits.filter(p => p.groupe === g.nom), couv = somme(L.filter(p => p.marge != null).map(p => p.caHt));
      const marge = somme(L.map(p => p.marge || 0));
      return { ...g, coul: COUL[i % COUL.length], v13: somme(g.v), q13: somme(g.q), marge, margePct: couv ? 100 * marge / couv : null, produits: L };
    });
    // Les libellés : la période, « sur la période », la période d'avant (« à date » quand la période est entamée).
    const b = PER(), partiel = D.au < b.au;
    st.prep = { D, n, produits, tot, groupes, sem: { tranches: libs, bornes: D.tranches }, rythme, unite, jours: D.jours,
      lib: b.lib + (partiel ? ' (jusqu’au ' + dfr(D.au) + ')' : ''), sur: b.sur, avant: b.avant,
      face: b.face + (partiel ? ', mêmes jours' : ''), Face: 'F' + b.face.slice(1) + (partiel ? ', mêmes jours' : ''), rienAvant: !(D.total.vp > 0) };
    return st.prep;
  }
  const coulG = nom => ((prep() || { groupes: [] }).groupes.find(g => g.nom === nom) || {}).coul || '#999';

  /* --- les tracés ---------------------------------------------------------------------------------------------------- */
  function echelle(max, n = 4) { const p = Math.pow(10, Math.floor(Math.log10(max || 1))); const s = [1, 2, 2.5, 5, 10].map(x => x * p).find(x => max / x <= n) || p * 10; return { pas: s, max: Math.ceil(max / s) * s }; }
  /** Des courbes sur une grille, à la largeur de la carte (le texte garde sa taille : viewBox ≈ largeur affichée). */
  function courbes(o) {
    const W = o.w || 640, H = o.h || 220, gl = 50, gb = 22, gt = 10, gr = 12, n = o.libelles.length;
    const tous = o.series.flatMap(s => s.vals.filter(v => v != null));
    if (!tous.length) { return '<p class="an-src">Pas de vente sur ces semaines.</p>'; }
    const { pas, max } = echelle(Math.max(...tous, 1) * 1.05);
    const x = i => gl + (W - gl - gr) * (n === 1 ? 0.5 : i / (n - 1)), y = v => gt + (H - gt - gb) * (1 - v / max);
    let g = '';
    for (let v = 0; v <= max + 1e-9; v += pas) { g += `<line x1="${gl}" x2="${W - gr}" y1="${y(v)}" y2="${y(v)}" class="an-gl"/><text x="${gl - 6}" y="${y(v) + 4}" class="an-ax" text-anchor="end">${o.fmt ? o.fmt(v) : n0(v)}</text>`; }
    o.libelles.forEach((l, i) => { g += `<text x="${x(i)}" y="${H - 5}" class="an-ax" text-anchor="middle">${esc(l)}</text>`; });
    o.series.forEach(s => {
      const pts = s.vals.map((v, i) => v == null ? null : [x(i), y(v)]).filter(Boolean);
      if (!pts.length) { return; }
      if (s.aire) { g += `<path d="M${pts[0][0]},${y(0)}L${pts.map(p => p.join(',')).join('L')}L${pts[pts.length - 1][0]},${y(0)}Z" fill="${s.coul}" opacity=".08"/>`; }
      g += `<polyline points="${pts.map(p => p.join(',')).join(' ')}" fill="none" stroke="${s.coul}" stroke-width="${s.ep || 2}"${s.tirets ? ' stroke-dasharray="4 4"' : ''} stroke-linejoin="round" stroke-linecap="round"/>`;
      if (s.points) { pts.forEach(p => { g += `<circle cx="${p[0]}" cy="${p[1]}" r="2.6" fill="#fff" stroke="${s.coul}" stroke-width="1.6"/>`; }); }
    });
    return `<svg class="an-svg" viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="${esc(o.titre || 'courbe')}">${g}</svg>`;
  }
  const barre = (v, max, coul = '#8D1D2C') => `<span class="an-bar"><i style="width:${(100 * Math.max(0, v || 0) / (max || 1)).toFixed(1)}%;background:${coul}"></i></span>`;
  const kpi = (k, v, s, cl) => `<div class="an-kpi${cl ? ' ' + cl : ''}"><span class="k">${k}</span><span class="v">${v}</span>${s ? `<span class="s">${s}</span>` : ''}</div>`;
  const Q = (n, question, reponse, corps, cl) => `<section class="an-carte an-qq${cl ? ' ' + cl : ''}"><div class="an-qh"><span class="an-qn">${n}</span><h4>${question}</h4></div><p class="an-rep">${reponse}</p>${corps || ''}</section>`;
  const attente = () => '<div class="an-att"><span class="db-sk" style="height:12px;width:70%"></span><span class="db-sk" style="height:12px;width:45%"></span></div>';
  /** L'état d'une lecture pour une carte : null si elle est là, sinon le message (attente ou erreur). */
  const manque = (cle, quoi) => cle in st.d ? null : cle in st.err ? `<p class="an-err">${esc(quoi)} : ${esc(st.err[cle])}</p>` : attente();
  const retenir = L => L.length ? `<section class="an-ret"><h4>Ce qu’il faut retenir</h4><ul>${L.map(x => `<li>${x}</li>`).join('')}</ul></section>` : '';

  /** Les mois depuis l'ouverture : pièces et chiffre, par jour du mois pour comparer des mois inégaux ; la flèche face au mois
   * d'avant dès le troisième mois (le premier est celui de l'ouverture, le deuxième ne se compare pas à un mois entamé). */
  function tableMois(M, lignes) {
    const mois = M.mois, lib = mois.map(m => MOIS[+m.cle.slice(5, 7) - 1]);
    const ql = lignes.map((l, k) => l && mois[k].jours ? l.q / mois[k].jours : null);
    return `<div class="an-defile"><table class="an-t an-t--mo"><thead><tr><th></th>${lib.map((m, k) => `<th class="n">${m}${mois[k].enCours ? '*' : ''}</th>`).join('')}</tr></thead><tbody>
      <tr><td>Pièces</td>${lignes.map(l => `<td class="n">${l ? n0(l.q) : '—'}</td>`).join('')}</tr>
      <tr><td>par jour</td>${ql.map(v => `<td class="n">${n0(v)}</td>`).join('')}</tr>
      <tr><td>face au mois d’avant</td>${ql.map((v, k) => `<td class="n">${k >= 2 && ql[k - 1] ? fleche(100 * (v / ql[k - 1] - 1)) : '<span class="an-f mu">—</span>'}</td>`).join('')}</tr>
      <tr><td>Chiffre TTC</td>${lignes.map(l => `<td class="n">${l ? k1(l.ca) : '—'}</td>`).join('')}</tr>
      <tr><td>Marge réalisée</td>${lignes.map(l => `<td class="n">${l && l.marge != null ? k1(l.marge) : '—'}</td>`).join('')}</tr></tbody></table></div>
      <p class="an-src">${mois.some(m => m.enCours) ? '* le mois en cours, jusqu’à aujourd’hui · ' : ''}le premier mois est celui de l’ouverture · « par jour » : sur les jours du mois, pour comparer des mois inégaux · ${LEGENDE_F}</p>`;
  }
  function periode(P) { return `du ${dfr(P.D.du)} au ${dfr(P.D.au)}`; }
  /** La courbe d'une période : « jour par jour », « semaine par semaine », « mois par mois ». */
  const moisEnCours = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); };
  const parTranche = P => ({ jour: 'jour par jour', semaine: 'semaine par semaine', mois: 'mois par mois' }[P.D.pas]);
  /** Le seuil des listes « monte / baisse » : 60 pièces sur 13 semaines, à la longueur de la période (10 au moins). */
  const seuilQ = P => Math.max(10, Math.round(60 * P.jours / 91));

  /* --- A · l'entrée -------------------------------------------------------------------------------------------------- */
  function vueAccueil(P) {
    const T = P.tot, libs = P.sem.tranches;
    // Le panier : les tickets de la semaine (Semaine), sinon des mois de la période qui ont leurs tickets. /ventes/mensuel
    // écarte le mois en cours (incomplet) : sur ce seul mois, on le dit au lieu de « pas de tickets relevés » (25/11/2026).
    const M = st.d.mens, SV = st.per.type === 'semaine' ? st.d['sv|' + PER().du] : null;
    const mm = M && Array.isArray(M.mois) ? M.mois.filter(m => m.tickets && m.ca && m.mois >= P.D.du.slice(0, 7) && m.mois <= P.D.au.slice(0, 7)) : [];
    const tickets = SV && SV.totaux ? SV.totaux.tickets : somme(mm.map(m => m.tickets)), caT = SV && SV.totaux ? SV.totaux.ca : somme(mm.map(m => m.ca));
    const panierDe = SV && SV.totaux ? 'la semaine' : mm.map(m => MOIS[+m.mois.slice(5, 7) - 1]).join(', ');
    const cleSV = 'sv|' + PER().du, svAttente = st.per.type === 'semaine' && !SV && !st.err[cleSV];
    const evoT = P.D.total.evo || {};
    const nb = v => P.produits.filter(p => p.evo && p.evo.verdict === v).length;
    const sansCout = P.produits.filter(p => p.marge == null).length;
    const kp = `<div class="an-kpis">${kpi('Chiffre d’affaires TTC', k1(T.v), P.rienAvant ? 'pas de période d’avant à comparer' : fleche(evoT.pct) + ' ' + esc(P.face))}
      ${kpi('Pièces vendues', n0(T.q), n0(P.rythme(T.q)) + ' ' + P.unite)}
      ${kpi('Marge réalisée', k1(T.marge), pc(T.margePct) + ' du CA HT · connue sur ' + n0(T.couverture) + ' % du CA', 'fort')}
      ${kpi('Panier moyen', tickets ? e2(caT / tickets) : '—', tickets ? n0(tickets) + ' tickets, ' + panierDe : svAttente || !M ? (st.err.mens ? 'tickets illisibles' : 'lecture…') : (P.D.du.slice(0, 7) === moisEnCours() && P.D.au.slice(0, 7) === moisEnCours() ? 'le mois en cours se lit à sa clôture ; la Semaine a ses tickets' : 'pas de tickets relevés'))}
      ${kpi('Références vendues', n0(P.produits.length), nb('monte') + ' en hausse · ' + nb('baisse') + ' en baisse · ' + nb('nouveau') + ' nouvelles')}
      ${kpi('Recettes sans coût', n0(sansCout), 'marge inconnue : à compléter au panel', sansCout ? 'alerte' : '')}</div>`;
    const maxV = Math.max(...P.groupes.map(g => g.v13), 1);
    const fam = `<section class="an-carte"><h3>Les familles <small>chiffre, marge réalisée, évolution ${esc(P.face)} · un clic ouvre la famille</small></h3>
      ${P.groupes.map(g => `<button type="button" class="an-fam" data-groupe="${esc(g.nom)}"><span class="nm"><i style="background:${g.coul}"></i>${esc(g.nom)}<small>${g.n} réf.</small></span>
        <span class="br"><b style="width:${(100 * g.v13 / maxV).toFixed(1)}%;background:${g.coul}"></b></span><span class="n">${k1(g.v13)}</span>
        <span class="n mz ${g.margePct == null ? 'mu' : g.margePct < 40 ? 'ko' : g.margePct < 60 ? 'att' : 'ok'}">${pc(g.margePct)}</span><span class="n">${fleche(g.evo && g.evo.pct)}</span></button>`).join('')}
      <p class="an-src">Évolution : la période ${esc(P.face)}, au jour · marge : CA HT − pièces × coût de la recette. ${LEGENDE_F}</p></section>`;
    const courbe = `<section class="an-carte"><h3>Le chiffre, ${parTranche(P)} <small>toutes familles, ${esc(P.lib)}</small></h3>
      ${courbes({ w: 640, h: 300, libelles: libs, fmt: k1, titre: 'le chiffre ' + parTranche(P), series: [{ vals: P.D.total.v, coul: '#8D1D2C', ep: 2.4, points: true, aire: true }] })}
      <div class="an-leg"><span><i style="background:#8D1D2C"></i>chiffre TTC</span>${P.D.enCours ? `<span>${esc(libs[P.n - 1])} : en cours</span>` : ''}</div></section>`;
    const top = (titre, L, f) => `<section class="an-carte"><h3>${titre}</h3>${L.length ? `<table class="an-t an-t--top"><colgroup><col style="width:22px"><col><col style="width:80px"><col style="width:90px"></colgroup><tbody>${L.map((p, i) => `<tr data-pid="${p.id}" class="an-lien"><td class="rg">${i + 1}</td><td class="nm"><span>${esc(p.nom)}</span><small>${esc(p.cat)}</small></td><td class="n">${f(p)}</td><td class="n">${fleche(p.evo && p.evo.pct)}</td></tr>`).join('')}</tbody></table>` : '<p class="an-src">Aucun produit.</p>'}</section>`;
    const parCA = P.produits.slice().sort((a, b) => b.v13 - a.v13).slice(0, 8);
    const parMarge = P.produits.filter(p => p.marge != null).sort((a, b) => b.marge - a.marge).slice(0, 8);
    const montent = P.produits.filter(p => p.evo && p.evo.verdict === 'monte' && p.q13 >= seuilQ(P)).sort((a, b) => b.evo.pct - a.evo.pct).slice(0, 8);
    const baissent = P.produits.filter(p => p.evo && p.evo.verdict === 'baisse' && p.q13 >= seuilQ(P)).sort((a, b) => a.evo.pct - b.evo.pct).slice(0, 8);
    return kp + `<div class="an-g2">${fam}${courbe}</div><div class="an-g2 an-g2--top">${top('Les plus gros chiffres', parCA, p => k1(p.v13))}${top('Ce qui rapporte le plus', parMarge, p => k1(p.marge))}
      ${top('Ce qui monte', montent, p => k1(p.v13))}${top('Ce qui baisse', baissent, p => k1(p.v13))}</div>
      <p class="an-src">Un clic sur un produit ouvre sa fiche · « ce qui monte / baisse » : produits vendus au moins ${seuilQ(P)} fois, ±8 % et plus ${esc(P.face)}, au jour.</p>`;
  }

  /* --- E · le produit ------------------------------------------------------------------------------------------------- */
  function vueProduit(P) {
    const p = P.produits.find(x => String(x.id) === String(st.pid));
    if (!p) { return `<section class="an-carte"><p class="an-rep">Ce produit n’a pas été vendu sur les 13 dernières semaines.</p></section>`; }
    const libs = P.sem.tranches;
    const ht = p.prixHt, cout = p.cout, mu = ht != null && cout != null ? ht - cout : null;
    const rang = (cle, x) => P.produits.filter(y => y[cle] != null).sort((a, b) => b[cle] - a[cle]).indexOf(x) + 1;
    const rgCA = rang('v13', p), rgM = p.marge != null ? rang('marge', p) : null;
    const partCA = 100 * p.v13 / P.tot.v, partM = p.marge != null && P.tot.marge ? 100 * p.marge / P.tot.marge : null;
    const L = [];
    // 1. Combien j'en vends ?
    const cleR = 'res|p' + p.id + '|' + PK(), R = st.d[cleR], rp = R && R.produits ? R.produits.find(x => String(x.pid) === String(p.id)) : null;
    const evR = R && R.reseau ? R.reseau.evo : null;
    const q1 = Q(1, 'Combien j’en vends ?', `<b>${n0(p.qSem)} pièces ${P.unite}</b> ${P.sur}, ${n0(p.q13)} en tout. ${esc(P.Face)} : ${fleche(p.evo && p.evo.pct)} chez vous${R && R.reseau ? `, ${fleche(evR)} dans le réseau` : ''}.`,
      courbes({ w: 640, h: 220, libelles: libs, titre: 'pièces ' + parTranche(P), series: (R && R.reseau ? [{ vals: R.reseau.q, coul: '#8f8778', ep: 1.6, tirets: true }] : []).concat([{ vals: p.q, coul: '#8D1D2C', ep: 2.4, points: true, aire: true }]) })
      + `<div class="an-leg"><span><i style="background:#8D1D2C"></i>vous, pièces ${parTranche(P)}</span>${R && R.reseau ? '<span><i style="background:#8f8778"></i>les autres magasins, ramenés à votre taille</span>' : ''}${P.D.enCours ? `<span>${esc(libs[P.n - 1])} : en cours</span>` : ''}</div>`
      + (R ? '' : manque(cleR, 'Le réseau') || ''));
    // 2. Combien il me rapporte ?
    const q2 = mu == null ? Q(2, 'Combien il me rapporte ?', `Le coût de la recette n’est pas renseigné au panel${p.tauxCatalogue == null && ht == null ? ', ni le prix' : ''} : la marge ne se calcule pas. Le chiffre : <b>${k1(p.v13)} ${P.sur}</b>, ${er(rgCA)} du magasin.`)
      : Q(2, 'Combien il me rapporte ?', `<b>${e2(mu)} par pièce</b>, soit ${pc(100 * mu / ht)} du prix hors TVA. <b>${k1(p.marge)} ${P.sur}</b> : ${er(rgM)} marge du magasin.`,
        `<div class="an-eq"><span><b>${e2(p.prix)}</b>prix TTC</span><i>−</i><span><b>${e2(p.prix - ht)}</b>TVA</span><i>=</i><span><b>${e2(ht)}</b>hors TVA</span><i>−</i><span><b>${e2(cout)}</b>recette</span><i>=</i><span class="ok"><b>${e2(mu)}</b>marge</span></div>
        <table class="an-t an-t--kv"><tbody><tr><td>Coefficient (prix HT ÷ recette)</td><td class="n">× ${n1(ht / cout)}</td></tr><tr><td>Part du chiffre · de la marge du magasin</td><td class="n">${pc(partCA)} · ${pc(partM)}</td></tr><tr><td>Rang au chiffre · à la marge</td><td class="n">${er(rgCA)} · ${er(rgM)} sur ${P.produits.length}</td></tr></tbody></table>
        ${p.taux ? '<p class="an-src">Vendu aussi à la part : la marge applique le taux de la recette au chiffre hors TVA.</p>' : ''}`);
    if (rgCA) { L.push(`C’est votre <b>${ere(rgCA)} vente</b> sur ${P.produits.length} références : ${pc(partCA)} du chiffre${partM != null ? ` et <b>${pc(partM)} de la marge</b>` : ''} du magasin.`); }
    // 3. Combien j'en jette ?
    let q3;
    const cleInv = 'inv|' + P.D.du + '|' + P.D.au, INV = st.d[cleInv];
    if (!INV) { q3 = Q(3, 'Combien j’en jette ?', '', manque(cleInv, 'Les invendus')); }
    else {
      const inv = (INV.produits || []).find(x => String(x.pid) === String(p.id));
      if (!inv || !inv.pieces) { q3 = Q(3, 'Combien j’en jette ?', `<b>Aucun invendu saisi</b> ${P.sur}, ${periode(P)}.`); L.push('Aucun invendu saisi ' + P.sur + '.'); }
      else {
        const cuits = p.q13 + inv.pieces, tauxJ = 100 * inv.pieces / cuits;
        const rgJ = (INV.produits || []).slice().sort((a, b) => b.pieces - a.pieces).indexOf(inv) + 1;
        q3 = Q(3, 'Combien j’en jette ?', `<b>${n0(inv.pieces)} pièces ${P.sur}</b>, ${n0(P.rythme(inv.pieces))} ${P.unite} : ${pc(tauxJ)} de ce qui sort du four. ${e0(inv.cout)} de matière perdue${p.marge ? `, ${pc(100 * inv.cout / p.marge)} de sa marge` : ''}.`,
          `<table class="an-t an-t--kv"><tbody><tr><td>Sortis du four (vendus + jetés)</td><td class="n">${n0(cuits)}</td></tr><tr><td>Vendus</td><td class="n">${n0(p.q13)}</td></tr>
            <tr><td>Jetés en fin de journée · pour une autre raison</td><td class="n">${inv.motif === 'expiration' ? n0(inv.motifPieces) + ' · ' + n0(inv.pieces - inv.motifPieces) : '— · ' + n0(inv.pieces)}</td></tr><tr><td>Le chiffre qu’ils auraient fait</td><td class="n">${e0(inv.caPerdu)}</td></tr>
            ${p.marge != null ? `<tr class="tot"><td>Ce qu’il vous laisse, invendus déduits</td><td class="n">${k1(p.marge - inv.cout)}</td></tr>` : ''}</tbody></table>`);
        L.push(`<b>Un sur ${n0(100 / tauxJ)} est jeté</b> : ${n0(inv.pieces)} ${P.sur}, ${e0(inv.cout)} de matière${rgJ <= 3 ? `, la ${ere(rgJ)} perte du magasin` : ''}.`);
      }
    }
    // 4. Quel jour il part ?
    let q4;
    const cleJ = 'jours|' + p.id + '|' + finJours(), J = st.d[cleJ];
    if (!J) { q4 = Q(4, 'Quel jour il part ?', '', manque(cleJ, 'Les jours')); }
    else {
      const parJ = {};
      J.dates.forEach((d, i) => { const k = new Date(d + 'T12:00:00').getDay(); const o = parJ[k] = parJ[k] || { v: [], j: [] }; if (J.ventes[i] != null) { o.v.push(J.ventes[i]); } if (J.jetes[i] != null) { o.j.push(J.jetes[i]); } });
      const ordre = [1, 2, 3, 4, 5, 6, 0].filter(k => parJ[k] && parJ[k].v.length).map(k => { const o = parJ[k]; return { k, moy: somme(o.v) / o.v.length, max: Math.max(...o.v), min: Math.min(...o.v), jet: o.j.length ? somme(o.j) / o.j.length : null }; });
      const tri = ordre.slice().sort((a, b) => b.moy - a.moy), fort = tri[0], faible = tri[tri.length - 1], maxJ = fort ? fort.moy : 1;
      const pire = ordre.filter(o => o.jet != null && o.moy > 0).sort((a, b) => b.jet / b.moy - a.jet / a.moy)[0];
      const rep = fort && faible && faible.moy > 0 ? `<b>${n0(fort.moy)} le ${JL[fort.k]}</b>, ${n0(faible.moy)} le ${JL[faible.k]} : ${n1(fort.moy / faible.moy)} fois plus.${pire && pire.jet > 0 ? ` C’est le ${JL[pire.k]} qu’on en jette le plus : ${n0(pire.jet)} pour ${n0(pire.moy)} vendus.` : ''}` : 'Pas assez de ventes sur 4 semaines pour lire les jours.';
      q4 = Q(4, 'Quel jour il part ?', rep,
        `<table class="an-t an-t--g an-t--j"><thead><tr><th>Jour</th><th>Vendus, en moyenne</th><th class="n">Au plus</th><th class="n">Au moins</th><th class="n">Jetés, en moyenne</th></tr></thead><tbody>
          ${ordre.map(o => `<tr><td>${JL[o.k]}</td><td><span class="an-bv">${barre(o.moy, maxJ)}<b>${n0(o.moy)}</b></span></td><td class="n">${n0(o.max)}</td><td class="n">${n0(o.min)}</td><td class="n">${o.jet == null ? '—' : n0(o.jet)}</td></tr>`).join('')}</tbody></table>
         <p class="an-src">Les 28 jours du ${dfr(J.dates[0])} au ${dfr(J.dates[J.dates.length - 1])} (4 de chaque jour, jusqu’à la fin de la période) · jetés : au jour de leur saisie en caisse, sur les jours où des invendus ont été saisis.</p>`);
      if (fort && faible && faible.moy > 0 && fort.moy / faible.moy >= 1.5) { L.push(`Il part le ${JL[fort.k]} : <b>${n0(fort.moy)}</b>, contre ${n0(faible.moy)} le ${JL[faible.k]} — la production suit le jour, pas une moyenne.`); }
    }
    // 5. Mon prix est-il bon ?
    let q5; const F = st.d['fiche|' + p.id];
    let prixPos = null;
    if (!F) { q5 = Q(5, 'Mon prix est-il bon ?', '', manque('fiche|' + p.id, 'Le prix')); }
    else if (!F.prix) { q5 = Q(5, 'Mon prix est-il bon ?', `Le prix ne se compare pas : ${esc(F.prixMotif || 'pas de repère du réseau')}.`); }
    else {
      const pr = F.prix, enc = pr.magasin.p, rs = pr.reseau, remise = p.prix ? 100 * (enc / p.prix - 1) : null;
      prixPos = enc >= rs.max - 0.005 ? 'haut' : enc <= rs.min + 0.005 ? 'bas' : 'milieu';
      const mot = { haut: 'le prix le plus haut du réseau', bas: 'le prix le plus bas du réseau', milieu: 'dans la fourchette du réseau' }[prixPos];
      q5 = Q(5, 'Mon prix est-il bon ?', `<b>${e2(enc)} encaissés</b>${p.prix ? ` pour ${e2(p.prix)} affichés (${sg(remise)} de remises)` : ''} : ${mot}, dont la médiane est à ${e2(rs.med)}.`,
        `<div class="an-reg"><div><span class="k">Affiché</span><span class="v">${e2(p.prix)}</span><span class="s">TTC, en caisse</span></div><div><span class="k">Encaissé</span><span class="v">${e2(enc)}</span><span class="s">remises comprises, ${esc(pr.periode)}</span></div>
          <div><span class="k">Le réseau</span><span class="v">${e2(rs.med)}</span><span class="s">médiane de ${n0(rs.magasins)} magasins · de ${e2(rs.min)} à ${e2(rs.max)}</span></div></div>`);
    }
    // 6. Est-ce que je fais mieux que les autres ?
    let q6, rgR = null, nR = 0;
    if (!R) { q6 = Q(6, 'Est-ce que je fais mieux que les autres ?', '', manque(cleR, 'Le réseau')); }
    else if (!rp || !rp.autres.length) { q6 = Q(6, 'Est-ce que je fais mieux que les autres ?', `Aucun des ${n0(R.autresMagasins)} autres magasins ne l’a vendu ${P.sur} : pas de comparaison.`); }
    else {
      const mags = [{ moi: true, p10k: rp.p10k, evo: rp.evo }].concat(rp.autres).sort((a, b) => b.p10k - a.p10k);
      let na = 0; mags.forEach(m => { m.nom = m.moi ? 'Vous' : 'Magasin ' + String.fromCharCode(65 + na++); });
      rgR = mags.findIndex(m => m.moi) + 1; nR = mags.length;
      const ak = rp.autres.map(a => a.p10k), mx = Math.max(...mags.map(m => m.p10k));
      q6 = Q(6, 'Est-ce que je fais mieux que les autres ?', `${rgR === 1 ? 'Oui' : rgR === nR ? 'Non' : 'Dans la moyenne'} : <b>${n0(rp.p10k)} pièces pour 10 k€ de chiffre</b>, contre ${ak.length > 1 ? n0(Math.min(...ak)) + ' à ' + n0(Math.max(...ak)) : n0(ak[0])} dans ${ak.length > 1 ? 'les ' + ak.length + ' autres magasins qui le vendent' : 'l’autre magasin qui le vend'}. ${er(rgR)} sur ${nR} à taille égale.`,
        `<table class="an-t an-t--g"><thead><tr><th>Magasin</th><th>Pièces pour 10 k€ de chiffre du magasin</th><th class="n">Face à la période d’avant</th></tr></thead><tbody>
          ${mags.map(m => `<tr class="${m.moi ? 'moi' : ''}"><td>${m.nom}</td><td><span class="an-bv">${barre(m.p10k, mx, m.moi ? '#8D1D2C' : '#b9b1a6')}<b>${n0(m.p10k)}</b></span></td><td class="n">${fleche(m.evo)}</td></tr>`).join('')}</tbody></table>
         <p class="an-src">${esc(P.lib)} · « pour 10 k€ » : les pièces rapportées au chiffre de chaque magasin, pour comparer un grand et un petit · les autres magasins ne sont pas nommés.</p>`);
    }
    if (prixPos && rgR) {
      const vol = rgR === 1 ? 'au plus haut' : rgR === nR ? 'au plus bas' : 'dans la moyenne';
      const pp = { haut: 'au plus haut', bas: 'au plus bas', milieu: 'dans la fourchette' }[prixPos];
      L.push(`Prix encaissé ${pp} du réseau, volume à taille égale ${vol} (${er(rgR)} sur ${nR})${prixPos === 'haut' && rgR === 1 ? ' : le prix tient, rien à baisser' : prixPos !== 'bas' && rgR === nR ? ' : le prix est à regarder' : ''}.`);
    }
    // 7. Depuis l'ouverture.
    const M = st.d['mois|' + p.id];
    let q7;
    if (!M) { q7 = Q(7, 'Comment a-t-il évolué depuis l’ouverture ?', '', manque('mois|' + p.id, 'Les mois'), 'an-qq--l'); }
    else {
      const lignes = (M.produits[String(p.id)] || []).map(x => x ? { q: x[0], ca: x[1], marge: x[3] } : null);
      const parJour = lignes.map((l, k) => l && M.mois[k].jours ? l.q / M.mois[k].jours : -1);
      const kMax = parJour.indexOf(Math.max(...parJour.slice(1)));
      q7 = Q(7, 'Comment a-t-il évolué depuis l’ouverture ?', lignes.length > 2 ? `Le meilleur mois : <b>${MOIS[+M.mois[kMax].cle.slice(5, 7) - 1]}</b>, ${n0(parJour[kMax])} pièces par jour. Mois par mois, face au mois d’avant :` : 'Mois par mois :', tableMois(M, lignes), 'an-qq--l');
    }
    return retenir(L) + `<div class="an-qs">${q1}${q2}${q3}${q4}${q5}${q6}${q7}</div>`;
  }

  /* --- F · la famille ------------------------------------------------------------------------------------------------- */
  function vueFamille(P) {
    const fam = P.groupes.find(g => g.nom === st.groupe);
    if (!fam) { return `<section class="an-carte"><p class="an-rep">Cette famille n’a pas de vente sur les 13 dernières semaines.</p></section>`; }
    const Lp = fam.produits.slice().sort((a, b) => b.v13 - a.v13), libs = P.sem.tranches;
    const partCA = 100 * fam.v13 / P.tot.v, partM = fam.marge && P.tot.marge ? 100 * fam.marge / P.tot.marge : null;
    const cleR = 'res|g' + fam.nom + '|' + PK(), R = st.d[cleR];
    const L = [];
    // 1. Combien pèse-t-elle ?
    const kH = R && R.produits ? R.produits.reduce((s, x) => s + (x.p10k || 0), 0) : null;
    const q1 = Q(1, 'Combien pèse-t-elle chez moi ?', `<b>${k1(fam.v13)} ${P.sur}</b>, ${pc(partCA)} du chiffre du magasin${fam.marge ? `, et <b>${k1(fam.marge)} de marge</b> (${pc(fam.margePct)} du hors TVA), ${pc(partM)} de la marge du magasin` : ''}.`,
      `<div class="an-reg an-reg--4"><div><span class="k">Pièces ${P.unite}</span><span class="v">${n0(P.rythme(fam.q13))}</span><span class="s">${Lp.length} références vendues</span></div>
        <div><span class="k">Pour 10 k€ de chiffre</span><span class="v">${kH != null ? n0(kH) : '—'}</span><span class="s">pièces de la famille, ${R ? 'à comparer aux autres (question 7)' : 'lecture…'}</span></div>
        <div><span class="k">Marge par pièce</span><span class="v">${fam.marge ? e2(fam.marge / fam.q13) : '—'}</span><span class="s">en moyenne sur la famille</span></div>
        <div><span class="k">Part du chiffre</span><span class="v">${pc(partCA)}</span><span class="s">le reste : ${pc(100 - partCA)} sur ${P.groupes.length - 1} familles</span></div></div>`);
    // 2. Monte-t-elle ?
    const evH = fam.evo && fam.evo.pct, evR = R && R.reseau ? R.reseau.evo : null;
    const q2 = Q(2, 'Est-ce qu’elle monte ou qu’elle baisse ?', `${esc(P.Face)} : <b>${fleche(evH)} chez vous</b>${R && R.reseau ? `, ${fleche(evR)} dans le réseau` : ''}.`,
      courbes({ w: 640, h: 210, libelles: libs, fmt: k1, titre: 'le chiffre de la famille ' + parTranche(P), series: (R && R.reseau ? [{ vals: R.reseau.v, coul: '#8f8778', ep: 1.6, tirets: true }] : []).concat([{ vals: fam.v, coul: '#8D1D2C', ep: 2.4, points: true, aire: true }]) })
      + `<div class="an-leg"><span><i style="background:#8D1D2C"></i>vous, chiffre TTC ${parTranche(P)}</span>${R && R.reseau ? '<span><i style="background:#8f8778"></i>les autres magasins, ramenés à votre taille</span>' : ''}</div>`);
    L.push(`${esc(fam.nom)} fait <b>${pc(partCA)} de votre chiffre</b>${partM != null ? ` et ${pc(partM)} de votre marge` : ''} ; ${fleche(evH)} ${esc(P.face)}${R && R.reseau ? `, le réseau ${fleche(evR)}` : ''}.`);
    // 3. Qui la porte ?
    let cum = 0; const par = Lp.map(x => { cum += x.v13; return { ...x, part: 100 * x.v13 / fam.v13, cum: 100 * cum / fam.v13 }; });
    const k3 = Math.min(3, par.length), n3 = par[k3 - 1].cum, vus = par.slice(0, 9);
    const q3 = Q(3, 'Quels produits la portent ?', `<b>${par.slice(0, k3).map(x => esc(x.nom)).join(', ')}</b> : ${n0(n3)} % du chiffre de la famille${k3 > 1 ? ' à eux ' + (k3 === 2 ? 'deux' : 'trois') : ''}.`,
      `<table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Chiffre</th><th>Part de la famille</th><th class="n">Marge</th><th class="n">6 sem. / 6 d’avant</th></tr></thead><tbody>
        ${vus.map(x => `<tr data-pid="${x.id}" class="an-lien"><td>${esc(x.nom)}</td><td class="n">${k1(x.v13)}</td><td><span class="an-bv">${barre(x.part, par[0].part)}<b>${pc(x.part)}</b></span></td><td class="n">${x.margePct == null ? '—' : pc(x.margePct)}</td><td class="n">${fleche(x.evo && x.evo.pct)}</td></tr>`).join('')}
        ${par.length > 9 ? `<tr class="tot"><td>Les ${par.length - 9} autres</td><td class="n">${k1(somme(par.slice(9).map(x => x.v13)))}</td><td>${pc(100 - par[8].cum)}</td><td></td><td></td></tr>` : ''}</tbody></table><p class="an-src">Un clic sur un produit ouvre sa fiche.</p>`);
    if (k3 > 1) { L.push(`<b>${k3} produits font ${n0(n3)} % de son chiffre</b> : ${par.slice(0, k3).map(x => esc(x.nom)).join(', ')}. Ce sont eux qu’il ne faut jamais manquer.`); }
    // 4. Montent, baissent : la semaine type (médiane), les semaines hors norme à part, les nouveaux à part.
    const nouveaux = Lp.filter(x => x.evo && x.evo.verdict === 'nouveau');
    const jp = P.D.precedent.jours;
    const suivis = Lp.filter(x => x.q13 >= seuilQ(P) && !nouveaux.includes(x)).map(x => {
      const m13 = P.n >= 4 ? med(x.q) : null, hors = m13 ? x.q.map((v, k) => ({ v, s: libs[k] })).filter(w => m13 > 0 && w.v > 3 * m13) : [];
      const qAdj = hors.length ? somme(x.q.map(v => v > 3 * m13 ? m13 : v)) : x.q13;
      return { ...x, pctM: x.qp > 0 && jp ? 100 * ((qAdj / P.jours) / (x.qp / jp) - 1) : null, hors, mApres: P.rythme(qAdj), m13 };
    }).filter(x => x.pctM != null && x.qp / jp * 7 >= 5);
    const montent = suivis.filter(x => x.pctM >= 5).sort((a, b) => b.pctM - a.pctM), baissent = suivis.filter(x => x.pctM <= -5).sort((a, b) => a.pctM - b.pctM);
    const hors = suivis.filter(x => x.hors.length);
    const lst = (A, vide) => A.length ? `<ul class="an-lst">${A.slice(0, 6).map(x => `<li data-pid="${x.id}" class="an-lien"><span>${esc(x.nom)}</span><small>${n0(x.mApres)} ${P.unite === 'par semaine' ? '/sem' : '/jour'}</small>${fleche(x.pctM)}</li>`).join('')}</ul>` : `<p class="an-src">${vide}</p>`;
    const q4 = Q(4, 'Lesquels montent, lesquels baissent ?', `<b>${montent.length} en hausse</b>, ${baissent.length} en baisse, ${suivis.length - montent.length - baissent.length} stables, ${esc(P.face)}.${nouveaux.length ? ` Et ${nouveaux.length} nouveau${nouveaux.length > 1 ? 'x' : ''}.` : ''}`,
      `<div class="an-g2 an-g2--l"><div><h5>Montent</h5>${lst(montent, 'aucun')}</div><div><h5>Baissent</h5>${lst(baissent, 'aucun')}</div></div>
       ${nouveaux.length ? `<h5>Nouveaux</h5><ul class="an-lst">${nouveaux.map(x => `<li data-pid="${x.id}" class="an-lien"><span>${esc(x.nom)}</span><small>${x.evo.depuis ? 'depuis ' + esc(x.evo.depuis) : ''}</small><b>${n0(x.qSem)} ${P.unite}</b></li>`).join('')}</ul>` : ''}
       ${hors.length ? `<h5>Hors norme, à ne pas prendre pour une tendance</h5><ul class="an-lst">${hors.map(x => `<li data-pid="${x.id}" class="an-lien"><span>${esc(x.nom)}</span><small>d’habitude : ${n0(x.m13)}</small><b>${x.hors.map(w => esc(w.s) + ' : ' + n0(w.v)).join(' · ')}</b></li>`).join('')}</ul>` : ''}
       <p class="an-src">Au jour, ${esc(P.face)} · hors norme : une tranche à plus de 3 fois l’habitude (une commande, une fête), ramenée à l’habitude dans le calcul · produits vendus au moins ${seuilQ(P)} fois et 5 par semaine avant.</p>`);
    // 5. Où je perds de l'argent ?
    let q5; const cleInv = 'inv|' + P.D.du + '|' + P.D.au, INV = st.d[cleInv];
    if (!INV) { q5 = Q(5, 'Où est-ce que je perds de l’argent ?', '', manque(cleInv, 'Les invendus')); }
    else {
      const inv = Lp.map(x => { const i = (INV.produits || []).find(y => String(y.pid) === String(x.id)); return i && i.pieces ? { id: x.id, nom: x.nom, vendus: x.q13, jet: i.pieces, cout: i.cout || 0, taux: 100 * i.pieces / (x.q13 + i.pieces), margePct: x.margePct } : null; })
        .filter(Boolean).sort((a, b) => b.cout - a.cout);
      const jetT = somme(inv.map(x => x.jet)), coutT = somme(inv.map(x => x.cout)), top3 = somme(inv.slice(0, 3).map(x => x.cout));
      const faibles = Lp.filter(x => x.margePct != null && x.margePct < 50 && x.q13 >= seuilQ(P));
      q5 = Q(5, 'Où est-ce que je perds de l’argent ?', jetT ? `<b>${n0(jetT)} pièces jetées</b> ${P.sur}, ${e0(coutT)} de matière${fam.marge ? ` : ${pc(100 * coutT / fam.marge)} de la marge de la famille` : ''}.${faibles.length ? ` Et ${faibles.length} produit${faibles.length > 1 ? 's' : ''} sous 50 % de marge.` : ''}` : `<b>Aucun invendu saisi</b> ${P.sur}.${faibles.length ? ` ${faibles.length} produit${faibles.length > 1 ? 's' : ''} sous 50 % de marge.` : ''}`,
        inv.length ? `<table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Vendus</th><th class="n">Jetés</th><th>Part des sortis du four</th><th class="n">Matière perdue</th></tr></thead><tbody>
          ${inv.slice(0, 8).map(x => `<tr data-pid="${x.id}" class="an-lien"><td>${esc(x.nom)}</td><td class="n">${n0(x.vendus)}</td><td class="n">${n0(x.jet)}</td><td><span class="an-bv">${barre(x.taux, 25, x.taux >= 15 ? '#A61E1E' : x.taux >= 8 ? '#B26A00' : '#3F7A52')}<b>${pc(x.taux)}</b></span></td><td class="n">${e0(x.cout)}</td></tr>`).join('')}</tbody></table>
         <p class="an-src">Jetés : saisis en caisse, ${periode(P)} · la barre : vert sous 8 %, orange de 8 à 15 %, rouge au-delà.</p>` : '');
      if (jetT) { L.push(`<b>${n0(jetT)} pièces jetées</b> ${P.sur}, ${e0(coutT)} de matière${inv.length >= 3 ? ` : ${inv.slice(0, 3).map(x => esc(x.nom)).join(', ')} en font ${n0(100 * top3 / coutT)} %` : ''}.`); }
    }
    // 6. À quelle heure ?
    const cleJ = 'journee|' + fam.nom + '|' + jourDe(st.jourF), JN = st.d[cleJ];
    const choixJ = `<div class="an-jours"><div class="an-seg an-seg--j">${[1, 2, 3, 4, 5, 6, 0].map(k => `<button type="button" data-jour="${k}" class="${k === st.jourF ? 'on' : ''}">${JL[k].slice(0, 3)}.</button>`).join('')}</div></div>`;
    let q6;
    if (!JN) { q6 = Q(6, 'À quelle heure se vend-elle ?', choixJ, manque(cleJ, 'La journée')); }
    else {
      const H = (JN.heures || []).filter(h => h.moyCa != null), totH = somme(H.map(h => h.moyCa));
      let ch = 0; const HC = H.map(h => { ch += h.moyCa; return { ...h, cum: totH ? 100 * ch / totH : 0 }; });
      const a = (HC.find(h => h.cum >= 50) || {}).h, maxH = Math.max(...H.map(h => h.moyCa), 1);
      const avant = hh => { const x = HC.filter(h => h.h < hh); return x.length ? x[x.length - 1].cum : 0; };
      const nomJ = JN.jourNom || JL[st.jourF];
      q6 = Q(6, 'À quelle heure se vend-elle ?', `${choixJ}${totH ? `Un ${esc(nomJ)} moyen, <b>la moitié est vendue avant ${a + 1} h</b>, ${n0(avant(11))} % avant 11 h.` : 'Pas de vente lue ces jours-là.'}`,
        totH ? `<table class="an-t an-t--g an-t--j"><thead><tr><th>Heure</th><th>Chiffre, un ${esc(nomJ)} moyen</th><th class="n">Pièces</th><th class="n">Vendu à la fin de l’heure</th></tr></thead><tbody>
          ${HC.map(h => `<tr><td>${h.h} h</td><td><span class="an-bv">${barre(h.moyCa, maxH)}<b>${e0(h.moyCa)}</b></span></td><td class="n">${n0(h.moyQ)}</td><td class="n">${n0(h.cum)} %</td></tr>`).join('')}</tbody></table>
         <p class="an-src">La moyenne des ${(JN.jours || []).length} ${esc(nomJ)}s avant le ${dfr(JN.date)}.</p>` : '');
      if (totH && a != null) { L.push(`Un ${esc(nomJ)}, la moitié de la famille est vendue avant ${a + 1} h : c’est la première fournée qui fait la journée.`); }
    }
    // 7. Et chez les autres ?
    let q7;
    if (!R) { q7 = Q(7, 'Et chez les autres ?', '', manque(cleR, 'Le réseau'), 'an-qq--l'); }
    else {
      const face = (R.produits || []).filter(x => x.autres.length && somme(x.q) >= Math.max(10, Math.round(100 * P.jours / 91))).map(x => { const m = somme(x.autres.map(a => a.p10k)) / x.autres.length; return { ...x, moy: m, rel: m ? 100 * (x.p10k / m - 1) : null }; });
      const ab = R.absents || [];
      const kA = R.autresMagasins;
      q7 = Q(7, 'Et chez les autres ?', `${face.length ? `À taille égale, produit par produit : <b>${face.filter(x => x.rel >= 5).length} au-dessus</b> des autres magasins, ${face.filter(x => x.rel <= -5).length} en dessous.` : 'Pas de produit de la famille vendu ailleurs à comparer.'}${ab.length ? ` Ils vendent ${ab.length === 1 ? 'un produit' : ab.length + ' produits'} que vous n’avez pas.` : ''}`,
        `<div class="an-g2 an-g2--l an-g2--q7"><div>${face.length ? `<table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Vous, pour 10 k€</th><th class="n">Les autres, pour 10 k€</th><th class="n">Vous face aux autres</th></tr></thead><tbody>
          ${face.slice(0, 8).map(x => `<tr data-pid="${x.pid}" class="an-lien"><td>${esc(x.nom)}</td><td class="n">${n0(x.p10k)}</td><td class="n">${n0(x.moy)}<small>${x.autres.length} magasin${x.autres.length > 1 ? 's' : ''}</small></td><td class="n">${fleche(x.rel)}</td></tr>`).join('')}</tbody></table>` : ''}
          <p class="an-src">« pour 10 k€ » : les pièces rapportées au chiffre de chaque magasin, ${esc(P.lib)} · les autres : la moyenne des magasins qui le vendent, sans leur nom.</p></div>
         <div>${ab.length ? `<h5>Vendus ailleurs, pas chez vous</h5><ul class="an-lst">${ab.slice(0, 8).map(x => `<li><span>${esc(x.nom)}</span><small>${x.magasins} magasin${x.magasins > 1 ? 's' : ''} sur ${kA}</small><b>~${n0(x.aVotreTaille)} par semaine à votre taille</b></li>`).join('')}</ul>
           <p class="an-src">Des références que vous n’avez vendues aucune semaine, et que d’autres magasins vendent : « à votre taille », ce que leur rythme donnerait chez vous. À essayer, ou à écarter en connaissance de cause.</p>` : '<h5>Vendus ailleurs, pas chez vous</h5><p class="an-src">Rien : vous vendez tout ce que les autres vendent dans cette famille.</p>'}</div></div>`, 'an-qq--l');
      if (ab.length) { L.push(`Les autres magasins vendent <b>${esc(ab[0].nom)}</b> (${ab[0].magasins} sur ${kA}, ~${n0(ab[0].aVotreTaille)} par semaine à votre taille) : vous ne l’avez pas.`); }
    }
    // 8. Depuis l'ouverture.
    const M = st.d['moisG|' + fam.nom];
    let q8;
    if (!M) { q8 = Q(8, 'Comment a-t-elle évolué depuis l’ouverture ?', '', manque('moisG|' + fam.nom, 'Les mois'), 'an-qq--l'); }
    else {
      const ids = Object.keys(M.produits);
      const lignes = M.mois.map((m, k) => ({ q: somme(ids.map(id => (M.produits[id][k] || [])[0])), ca: somme(ids.map(id => (M.produits[id][k] || [])[1])), marge: somme(ids.map(id => (M.produits[id][k] || [])[3] || 0)) }));
      q8 = Q(8, 'Comment a-t-elle évolué depuis l’ouverture ?', 'Mois par mois, en pièces par jour, face au mois d’avant :', tableMois(M, lignes), 'an-qq--l');
    }
    return retenir(L) + `<div class="an-qs">${q1}${q2}${q3}${q4}${q5}${q6}${q7}${q8}</div>`;
  }

  /* --- la page --------------------------------------------------------------------------------------------------------- */
  /** Le choix de la période (15/11/2026) : le type, ‹ et › pour la période d'avant ou d'après, les deux dates d'une plage. */
  function choixPeriode() {
    const b = PER(), auj = iso(new Date()), p = st.per;
    const suivante = (() => { const x = { ...p }; const g = st.per; st.per = x; decaler(1); const du = PER().du; st.per = g; return du <= auj; })();
    return `<div class="an-pers"><div class="an-seg an-seg--p" role="group" aria-label="La période">${TYPES.map(([t, l]) => `<button type="button" data-per="${t}" class="${p.type === t ? 'on' : ''}">${l}</button>`).join('')}</div>
      ${p.type === 'plage' ? `<label class="an-date">du <input type="date" data-plage="du" value="${esc(p.du)}" max="${auj}"></label><label class="an-date">au <input type="date" data-plage="au" value="${esc(p.au)}" max="${auj}"></label>` : ''}
      <span class="an-nav"><button type="button" class="an-btn an-btn--p" data-pas="-1" aria-label="La période d’avant">‹</button><b>${esc(b.lib)}</b><button type="button" class="an-btn an-btn--p" data-pas="1" aria-label="La période d’après"${suivante ? '' : ' disabled'}>›</button></span>
      <span class="an-per">${esc('comparé' + b.face.slice(4))}, au jour</span></div>`;
  }
  function rendre() {
    if (!st.el) { return; }
    const P = prep(), L = P || st.dernier;
    if (P) { st.dernier = P; }
    const barre = st.el.querySelector('.an-barre'), corps = st.el.querySelector('.an-corps');
    // La barre (retour, titre, recherche, période) ne se redessine que si la page ou la période change : la recherche garde
    // ce qu'on y tape, et le choix de la période reste là pendant la lecture d'une autre période.
    const cle = [st.vue, st.pid, st.groupe, PK(), st.per.type, !!L].join('|');
    if (st.cleBarre !== cle) {
      st.cleBarre = cle;
      const opts = L ? L.produits.slice().sort((a, b) => b.v13 - a.v13).map(p => `<option value="${esc(p.nom)}">${esc(p.cat)}</option>`).join('')
        + L.groupes.map(g => `<option value="Famille · ${esc(g.nom)}"></option>`).join('') : '';
      const x = st.vue === 'produit' && L ? L.produits.find(y => String(y.id) === String(st.pid)) : null;
      const titre = x ? x.nom : st.vue === 'famille' ? st.groupe : null;
      const sous = x ? `${esc(x.cat)} · famille <button type="button" class="an-lien-t" data-groupe="${esc(x.groupe)}">${esc(x.groupe)}</button> · réf. ${esc(x.id)}`
        : st.vue === 'famille' && L ? `famille · ${(L.groupes.find(g => g.nom === st.groupe) || { n: 0 }).n} références vendues` : '';
      barre.innerHTML = `<div class="an-ligne">${st.vue !== 'accueil' ? `<button type="button" class="an-btn" data-retour="1">${ico(I.retour)}Analyse</button>` : ''}
        ${titre ? `<div class="an-titre"><b>${esc(titre)}</b><span>${sous}</span></div>` : ''}
        <label class="an-rech">${ico(I.chercher)}<input type="search" list="an-dl" placeholder="Chercher un produit ou une famille…" aria-label="Chercher un produit ou une famille"></label><datalist id="an-dl">${opts}</datalist></div>
        ${choixPeriode()}`;
    }
    if (!P) {
      const e = st.err['per|' + PK()];
      corps.innerHTML = e ? `<div class="db-err">Les ventes de la période ne se lisent pas : ${esc(e)}</div>` : '<section class="an-carte">' + attente() + '<p class="an-src">Lecture de la période…</p></section>';
      return;
    }
    corps.innerHTML = st.vue === 'produit' ? vueProduit(P) : st.vue === 'famille' ? vueFamille(P) : vueAccueil(P);
  }
  /** Une autre période : on garde la page (l'entrée, le produit, la famille) et on relit. */
  function changerPeriode() { st.prep = null; st.cleBarre = null; urlEcrire(); charger(); rendre(); }
  function ouvrir(vue, cle) {
    st.vue = vue; st.pid = vue === 'produit' ? String(cle) : null; st.groupe = vue === 'famille' ? String(cle) : null; st.frais = false;
    urlEcrire(); charger(); rendre();
    st.el.scrollIntoView({ block: 'start' });
  }
  function brancher(el) {
    el.addEventListener('click', e => {
      const t = e.target.closest('[data-pid],[data-groupe],[data-retour],[data-jour],[data-per],[data-pas]');
      if (!t || !el.contains(t)) { return; }
      if (t.dataset.per) {
        const p = st.per; if (p.type === t.dataset.per) { return; }
        // Une plage part de la période affichée ; les autres types gardent la date du jour comme repère.
        if (t.dataset.per === 'plage') { const b = PER(); p.du = b.du; p.au = b.au < iso(new Date()) ? b.au : iso(new Date()); }
        else if (p.type === 'plage') { p.ancre = p.au; }
        p.type = t.dataset.per; changerPeriode(); return;
      }
      if (t.dataset.pas) { if (t.disabled) { return; } decaler(+t.dataset.pas); changerPeriode(); return; }
      if (t.dataset.retour) { ouvrir('accueil'); return; }
      if (t.dataset.jour != null) { st.jourF = +t.dataset.jour; charger(); rendre(); return; }
      if (t.dataset.groupe != null) { ouvrir('famille', t.dataset.groupe); return; }
      if (t.dataset.pid) { ouvrir('produit', t.dataset.pid); }
    });
    el.addEventListener('change', e => {
      if (e.target.matches('[data-plage]')) {
        const v = e.target.value, p = st.per;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { return; }
        p[e.target.dataset.plage] = v;
        if (p.du > p.au) { if (e.target.dataset.plage === 'du') { p.au = p.du; } else { p.du = p.au; } }
        if (nbJours(p.du, p.au) > 400) { p.du = plus(p.au, -399); }
        changerPeriode(); return;
      }
      if (!e.target.matches('.an-rech input')) { return; }
      const v = e.target.value.trim(), P = prep();
      if (!P || !v) { return; }
      const g = v.startsWith('Famille · ') ? P.groupes.find(x => x.nom === v.slice(10)) : null;
      if (g) { ouvrir('famille', g.nom); return; }
      const p = P.produits.find(x => x.nom === v) || P.produits.find(x => x.nom.toLowerCase().includes(v.toLowerCase()));
      if (p) { ouvrir('produit', p.id); }
    });
  }

  window.DashAnalyse = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (st.el && st.shop === String(shop)) { st.cleBarre = null; urlEcrire(); charger(); rendre(); return st.el; }
      st.shop = String(shop); st.d = {}; st.err = {}; st.enCours = {}; st.prep = null; st.dernier = null; st.vue = 'accueil'; st.pid = null; st.groupe = null; st.frais = false; st.cleBarre = null;
      st.per = { type: 'sem13', ancre: iso(new Date()), du: null, au: null };
      urlLire();
      st.el = document.createElement('div'); st.el.className = 'an-racine'; st.el.id = 'db-analyse';
      st.el.innerHTML = '<div class="an-barre"></div><div class="an-corps"></div>';
      brancher(st.el);
      urlEcrire(); charger(); rendre();
      return st.el;
    },
    /** « ↻ Relire » : tout relire une fois, sans le cache partagé. */
    relire() { st.d = {}; st.err = {}; st.prep = null; st.frais = true; st.cleBarre = null; charger(); rendre(); },
    /** Ouvrir une page : {pid} → le produit, {groupe} → la famille, sinon l'entrée. */
    ouvrir(i) { if (!st.el) { return; } if (i && i.pid) { ouvrir('produit', i.pid); } else if (i && i.groupe) { ouvrir('famille', i.groupe); } else { ouvrir('accueil'); } },
  };
})();
