/* Maquettes « Analyse » (demande du 15/11/2026 : « une maquette d'analyse produit et catégories, le plus complet
 * possible ; toutes les données nécessaires à un franchisé sur ses produits : période, marge, quantité, visualisation
 * dans le temps ; 4-5 propositions en captures. Ce sera Analyse dans le rail »).
 *
 *   node docs/maquettes/rail-franchise/generer-analyse.js   → analyse-a.html … analyse-e.html
 *   node docs/maquettes/rail-franchise/capturer-analyse.js  → analyse-a.png … analyse-e.png
 *
 * Les chiffres sont ceux de Halle, lus le 15/11/2026 à l'API du cockpit en ligne (lectures seules) et gardés dans
 * donnees-analyse.json : les 13 dernières semaines par produit (/analyse/developpement/magasin), les mois depuis
 * l'ouverture par produit (/dashboard/marketing/ventes-periode : quantité, CA TTC et HT, marge réalisée), le prix et le
 * coût de recette (/analyse/produits/coefficients), les familles face à la moyenne du réseau
 * (/analyse/categories/magasin), la journée d'une famille (/analyse/categories/journee), la fiche du croissant
 * (/analyse/produits/magasin — les autres magasins y sont rendus anonymes : médiane et bornes du réseau).
 * La marge réalisée = CA HT encaissé − quantité × coût de la recette du jour ; un produit sans coût de recette (ou
 * invraisemblable) n'en a pas : la part du CA couverte est dite. Rien n'est inventé ; Halle a ouvert en février 2026 :
 * pas de N-1 — la comparaison se fait au réseau et à la période précédente.
 *
 * Cinq propositions : A le tableau de bord · B le grand tableau · C marge × volume · D dans le temps · E la fiche produit.
 */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = '../../../public/';
const X = JSON.parse(fs.readFileSync(path.join(D, 'donnees-analyse.json'), 'utf8'));

/* --- formats -------------------------------------------------------------------------------------------------- */
const sp = s => String(s).replace(/[  ]/g, ' ');
const n0 = v => v == null || isNaN(v) ? '—' : sp(Math.round(v).toLocaleString('fr-BE'));
const n1 = v => v == null || isNaN(v) ? '—' : sp(Number(v).toLocaleString('fr-BE', { maximumFractionDigits: 1, minimumFractionDigits: 1 }));
const e0 = v => v == null || isNaN(v) ? '—' : n0(v) + ' €';
const e2 = v => v == null || isNaN(v) ? '—' : sp(Number(v).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + ' €';
const k1 = v => v == null || isNaN(v) ? '—' : Math.abs(v) >= 1000 ? sp((v / 1000).toLocaleString('fr-BE', { maximumFractionDigits: 1 })) + ' k€' : e0(v);
const pc = v => v == null || isNaN(v) ? '—' : n1(v) + ' %';
const sg = v => { if (v == null || isNaN(v)) { return '—'; } const r = Math.round(v * 10) / 10; return (r > 0 ? '+' : r < 0 ? '−' : '') + n1(Math.abs(r)) + ' %'; };
const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const somme = a => a.reduce((s, x) => s + (x || 0), 0);
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const COUL = ['#8D1D2C', '#C08A2B', '#3F7A52', '#5C6F91', '#B5654A', '#7A5C8E', '#2F8F8C', '#A3A04A', '#9C7B6B', '#6B6B6B', '#C45C7B', '#4C8DB0'];

/* --- les données, préparées une fois ------------------------------------------------------------------------ */
const SEM = X.semaines.tranches, BORNES = X.semaines.bornes;
const MOISL = X.mois.map(m => MOIS[+m.du.slice(5, 7) - 1]);
/** La marge réalisée d'un produit sur une période : CA HT − pièces × coût de la recette ; vendu aussi en portions ou en
 * variantes (prix moyen encaissé à plus de 15 % du prix de la pièce entière), CA HT × le taux de marge de sa recette. */
function margeDe(q, ca, caHt, prix, prixHt, cout) {
  if (!q || caHt == null || cout == null || !prixHt) { return { marge: q ? null : 0, taux: false }; }
  const taux = !(prix && Math.abs(ca / q / prix - 1) <= 0.15);
  return { marge: taux ? caHt * (1 - cout / prixHt) : caHt - q * cout, taux };
}
const coutOk = (c, prixHt) => c && prixHt && c < prixHt && c >= 0.05 * prixHt ? c : null;
// Les mois : la marge relue avec la même règle que les 13 semaines (les portions au taux de la recette).
X.mois.forEach(m => { Object.entries(m.p).forEach(([pid, v]) => { const c = X.coef[pid] || {}; const cout = coutOk(c.cout, c.prixHt); v[3] = margeDe(v[0], v[1], v[2], c.prixTtc, c.prixHt, cout).marge; }); });
const P = X.produits.map(p => {
  const c = X.coef[String(p.id)] || {};
  const q13 = somme(p.q), v13 = somme(p.v), ratio = p.prix && p.prixHt ? p.prixHt / p.prix : null;
  const caHt = ratio != null ? v13 * ratio : null;
  const cout = coutOk(c.cout, p.prixHt);
  const M = margeDe(q13, v13, caHt, p.prix, p.prixHt, cout), marge = M.marge;
  const mois = X.mois.map(m => m.p[String(p.id)] || [0, 0, 0, null]);
  return { ...p, q13, v13, caHt, cout, taux: M.taux, coef: c.coef, cible: c.cible, zone: c.zone, marge, margePct: marge != null && caHt ? 100 * marge / caHt : null, mois,
    qSem: q13 / SEM.length };
}).filter(p => p.q13 > 0);
const TOT = { v: somme(X.total.v), q: somme(X.total.q), caHt: somme(P.map(p => p.caHt || 0)), marge: somme(P.map(p => p.marge || 0)), caCouvert: somme(P.filter(p => p.marge != null).map(p => p.caHt)) };
TOT.margePct = 100 * TOT.marge / TOT.caCouvert; TOT.couverture = 100 * TOT.caCouvert / TOT.caHt;
const GROUPES = X.groupes.map((g, i) => {
  const L = P.filter(p => p.groupe === g.nom);
  const caHt = somme(L.map(p => p.caHt || 0)), marge = somme(L.map(p => p.marge || 0)), couv = somme(L.filter(p => p.marge != null).map(p => p.caHt));
  return { ...g, coul: COUL[i % COUL.length], v13: somme(g.v), q13: somme(g.q), caHt, marge, margePct: couv ? 100 * marge / couv : null, couverture: caHt ? 100 * couv / caHt : 0, produits: L,
    mois: X.mois.map((m, k) => somme(L.map(p => p.mois[k][1]))) };
});
const coulG = nom => (GROUPES.find(g => g.nom === nom) || {}).coul || '#999';
const TICKETS = X.mensuel.filter(m => m.mois >= '2026-08').reduce((s, m) => s + (m.tickets || 0), 0);
const CA3M = X.mensuel.filter(m => m.mois >= '2026-08').reduce((s, m) => s + (m.ca || 0), 0);
const hausse = P.filter(p => p.evo && p.evo.verdict === 'monte').length, baisse = P.filter(p => p.evo && p.evo.verdict === 'baisse').length;
const nouveaux = P.filter(p => p.evo && p.evo.verdict === 'nouveau').length, arretes = P.filter(p => p.evo && p.evo.verdict === 'arrete').length;

/* --- les tracés ---------------------------------------------------------------------------------------------- */
const I = {
  tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z', controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9', cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  calEd: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6', fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5', bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
  offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0', analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z', brochures: 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
  personnes: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9', mail: 'M3 5h18v14H3zM3 6l9 7 9-7',
  offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5', pub: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z',
  reseaux: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18', chercher: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2', telecharger: 'M12 3v12M7 10l5 5 5-5M4 19h16',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;

/** Le rail tel qu'il est en ligne (15/11/2026), avec Analyse en tête de Vendre. */
function rail() {
  const G = [['La journée', [['Opérationnel', 'tdb'], ['Tâches', 'controle'], ['Temps morts', 'journal']]],
    ['Les chiffres', [['Semaine', 'cal'], ['Mois', 'calEd'], ['Année', 'perf'], ['Trésorerie', 'fonds']]],
    ['Vendre', [['Analyse', 'analyse', '', true], ['Produits', 'bundles'], ['Promotions', 'offres'], ['Offres', 'offre'], ['Primes', 'personnes'], ['Concurrence', 'analyse']]],
    ['Marketing', [['Mon marketing', 'pub'], ['Réseaux sociaux', 'reseaux'], ['Fidélité', 'etoile'], ['Mon catalogue', 'brochures']]]];
  const it = (l, i, n, on) => `<button class="sidebar__item${on ? ' is-active' : ''}">${ico(I[i])}<span class="sidebar__item-label">${l}</span>${n ? `<span class="sidebar__count">${n}</span>` : ''}${on ? '<span class="an-neuf">nouveau</span>' : ''}</button>`;
  return `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
  <div class="sidebar__groups"><div class="sidebar__group">${it('Boîte mail', 'mail')}</div>
  ${G.map(([g, L]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${L.map(x => it(...x)).join('')}</div>`).join('')}</div>`;
}

/** La barre de l'écran Analyse : la période, la comparaison, la famille — communes aux cinq propositions. */
function filtres(actifP, comp) {
  const per = ['4 semaines', '13 semaines', 'Depuis l’ouverture', 'Un mois…', 'Une saison…', 'Du … au …'];
  const cmp = ['Période précédente', 'Moyenne du réseau', 'N-1 (dès février 2027)'];
  return `<div class="an-filtres"><div class="an-seg">${per.map(p => `<button class="${p === actifP ? 'on' : ''}">${p}</button>`).join('')}</div>
    <span class="an-k">comparé à</span><div class="an-seg an-seg--c">${cmp.map((p, i) => `<button class="${p === comp ? 'on' : ''}${i === 2 ? ' off' : ''}">${p}</button>`).join('')}</div>
    <span class="an-sp"></span><label class="an-sel">Famille <b>Toutes ▾</b></label><button class="an-btn">${ico(I.telecharger)}Exporter</button></div>`;
}
function page(nom, titreDoc, sousTitre, note, corps, actifP, comp) {
  fs.writeFileSync(path.join(D, nom + '.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard magasin — Analyse — ${titreDoc} (maquette)</title>
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css"><link rel="stylesheet" href="final.css"><link rel="stylesheet" href="marketing.css"><link rel="stylesheet" href="analyse.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">${rail()}</nav>
<div class="workspace__main mk-main">
  <div class="mk-barre"><div class="db-vt"><div class="kk">Atelier by – Halle</div><h1>Analyse <small class="an-st">${sousTitre}</small></h1></div><span style="flex:1"></span>
    <button class="btn">↻ Relire</button><span class="an-lu">chiffres lus le 15/11/2026 · S34 → S46 : du ${dfr(BORNES[0][0])} au ${dfr(BORNES[BORNES.length - 1][1])}</span></div>
  <p class="mk-note"><span class="rf-pill">${note}</span></p>
  ${filtres(actifP, comp)}
  ${corps}
</div></div></body></html>
`);
}
const dfr = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? (+s.slice(8, 10)) + ' ' + MOIS[+s.slice(5, 7) - 1] : s;

/* --- les graphiques (SVG) ---------------------------------------------------------------------------------------- */
function echelle(max, n = 4) { const p = Math.pow(10, Math.floor(Math.log10(max || 1))); const s = [1, 2, 2.5, 5, 10].map(x => x * p).find(x => max / x <= n) || p * 10; return { pas: s, max: Math.ceil(max / s) * s }; }
function courbes(o) {
  const W = o.w, H = o.h, gl = 48, gb = 22, gt = 10, gr = 10, n = o.libelles.length;
  const tous = o.series.flatMap(s => s.vals.filter(v => v != null));
  const { pas, max } = echelle(Math.max(...tous) * 1.05);
  const x = i => gl + (W - gl - gr) * (n === 1 ? 0.5 : i / (n - 1)), y = v => gt + (H - gt - gb) * (1 - v / max);
  let g = '';
  for (let v = 0; v <= max + 1e-9; v += pas) { g += `<line x1="${gl}" x2="${W - gr}" y1="${y(v)}" y2="${y(v)}" class="an-gl"/><text x="${gl - 6}" y="${y(v) + 4}" class="an-ax" text-anchor="end">${o.fmt ? o.fmt(v) : n0(v)}</text>`; }
  o.libelles.forEach((l, i) => { if (n <= 14 || i % 2 === 0) { g += `<text x="${x(i)}" y="${H - 6}" class="an-ax" text-anchor="middle">${esc(l)}</text>`; } });
  if (o.bande) { const [lo, hi] = o.bande; g += `<path d="M${lo.map((v, i) => x(i) + ',' + y(v)).join('L')}L${hi.map((v, i) => x(n - 1 - i) + ',' + y(hi[n - 1 - i])).join('L')}Z" class="an-bande"/>`; }
  o.series.forEach(s => {
    const pts = s.vals.map((v, i) => v == null ? null : [x(i), y(v)]).filter(Boolean);
    if (s.aire) { g += `<path d="M${pts[0][0]},${y(0)}L${pts.map(p => p.join(',')).join('L')}L${pts[pts.length - 1][0]},${y(0)}Z" fill="${s.coul}" opacity=".08"/>`; }
    g += `<polyline points="${pts.map(p => p.join(',')).join(' ')}" fill="none" stroke="${s.coul}" stroke-width="${s.ep || 2}"${s.tirets ? ' stroke-dasharray="4 4"' : ''} stroke-linejoin="round" stroke-linecap="round"/>`;
    if (s.points) { pts.forEach(p => { g += `<circle cx="${p[0]}" cy="${p[1]}" r="2.6" fill="#fff" stroke="${s.coul}" stroke-width="1.6"/>`; }); }
  });
  return `<svg class="an-svg" viewBox="0 0 ${W} ${H}" width="100%" height="${H}" preserveAspectRatio="none">${g}</svg>`;
}
function barresEmpilees(o) {
  const W = o.w, H = o.h, gl = 48, gb = 22, gt = 10, gr = 8, n = o.colonnes.length;
  const tot = o.colonnes.map(c => somme(c.vals)); const { pas, max } = echelle(Math.max(...tot) * 1.05);
  const bw = (W - gl - gr) / n, y = v => gt + (H - gt - gb) * (1 - v / max);
  let g = '';
  for (let v = 0; v <= max + 1e-9; v += pas) { g += `<line x1="${gl}" x2="${W - gr}" y1="${y(v)}" y2="${y(v)}" class="an-gl"/><text x="${gl - 6}" y="${y(v) + 4}" class="an-ax" text-anchor="end">${k1(v)}</text>`; }
  o.colonnes.forEach((c, i) => {
    let base = 0; const x0 = gl + i * bw + bw * 0.18, w = bw * 0.64;
    c.vals.forEach((v, k) => { if (!v) { return; } g += `<rect x="${x0}" y="${y(base + v)}" width="${w}" height="${y(base) - y(base + v)}" fill="${o.couls[k]}"/>`; base += v; });
    g += `<text x="${x0 + w / 2}" y="${H - 6}" class="an-ax" text-anchor="middle">${esc(c.lib)}</text><text x="${x0 + w / 2}" y="${y(base) - 4}" class="an-ax an-ax--f" text-anchor="middle">${k1(base)}</text>`;
  });
  return `<svg class="an-svg" viewBox="0 0 ${W} ${H}" width="100%" height="${H}" preserveAspectRatio="none">${g}</svg>`;
}
function spark(vals, coul = '#8D1D2C', w = 96, h = 24) {
  const max = Math.max(...vals, 1), min = Math.min(...vals, 0);
  const pts = vals.map((v, i) => `${(w * i / (vals.length - 1)).toFixed(1)},${(h - 2 - (h - 4) * (v - min) / (max - min || 1)).toFixed(1)}`);
  return `<svg class="an-spark" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}"><polyline points="${pts.join(' ')}" fill="none" stroke="${coul}" stroke-width="1.5" stroke-linejoin="round"/></svg>`;
}
/** Une évolution en flèche (15/11/2026, « éviter les mini graphiques illisibles : ▲ vert, ▼ rouge ou orange ») :
 * ▲ vert à +5 % et plus · ▶ gris entre −5 et +5 % · ▼ orange entre −5 et −15 % · ▼ rouge à −15 % et moins. */
function fleche(v, titre) {
  if (v == null || isNaN(v)) { return '<span class="an-f mu">—</span>'; }
  const [cl, f] = v >= 5 ? ['up', '▲'] : v > -5 ? ['flat', '▶'] : v > -15 ? ['warn', '▼'] : ['down', '▼'];
  return `<span class="an-f ${cl}"${titre ? ` title="${esc(titre)}"` : ''}>${f} ${sg(v)}</span>`;
}
const LEGENDE_F = '<span class="an-legf"><span class="an-f up">▲</span> +5 % et plus <span class="an-f flat">▶</span> stable <span class="an-f warn">▼</span> −5 à −15 % <span class="an-f down">▼</span> −15 % et moins</span>';
const evoMois = (a, b) => b ? 100 * (a / b - 1) : null;
const verdict = e => e ? ({ monte: ['ok', 'en hausse'], baisse: ['ko', 'en baisse'], stable: ['mu', 'stable'], nouveau: ['att', 'nouveau'], arrete: ['ko', 'arrêté'], peu: ['mu', 'peu vendu'] }[e.verdict] || ['mu', e.verdict]) : ['mu', '—'];
const zoneM = m => m == null ? 'mu' : m < 40 ? 'ko' : m < 60 ? 'att' : 'ok';
const kpi = (k, v, s, cl) => `<div class="an-kpi${cl ? ' ' + cl : ''}"><span class="k">${k}</span><span class="v">${v}</span>${s ? `<span class="s">${s}</span>` : ''}</div>`;

/* --- A · le tableau de bord ------------------------------------------------------------------------------------ */
(function () {
  const evoT = X.total.evo;
  const kp = `<div class="an-kpis">${kpi('Chiffre d’affaires TTC', k1(TOT.v), sg(evoT.pct) + ' sur les 6 dernières semaines')}
    ${kpi('Pièces vendues', n0(TOT.q), n0(TOT.q / SEM.length) + ' par semaine')}
    ${kpi('Marge réalisée', k1(TOT.marge), pc(TOT.margePct) + ' du CA HT · connue sur ' + n0(TOT.couverture) + ' % du CA', 'fort')}
    ${kpi('Panier moyen', e2(CA3M / TICKETS), n0(TICKETS) + ' tickets d’août à octobre')}
    ${kpi('Références vendues', n0(P.length), hausse + ' en hausse · ' + baisse + ' en baisse · ' + nouveaux + ' nouvelles')}
    ${kpi('Recettes sans coût', n0(P.filter(p => p.marge == null).length), 'marge inconnue : à compléter au panel', 'alerte')}</div>`;
  const maxV = Math.max(...GROUPES.map(g => g.v13));
  const fam = `<section class="an-carte"><h3>Les familles <small>CA des 13 semaines, marge réalisée, évolution</small></h3>
    ${GROUPES.map(g => `<div class="an-fam"><span class="nm"><i style="background:${g.coul}"></i>${esc(g.nom)}<small>${g.n} réf.</small></span>
      <span class="br"><b style="width:${(100 * g.v13 / maxV).toFixed(1)}%;background:${g.coul}"></b></span><span class="n">${k1(g.v13)}</span>
      <span class="n mz ${zoneM(g.margePct)}">${pc(g.margePct)}</span><span class="n">${fleche(g.evo.pct)}</span></div>`).join('')}
    <p class="an-src">Évolution : les 6 dernières semaines face aux 6 d’avant · marge : CA HT − pièces × coût de la recette. ${LEGENDE_F}</p></section>`;
  const vue = X.cats.Viennoiserie.semaines;
  const courbe = `<section class="an-carte"><h3>Le chiffre de la semaine <small>toutes familles, les 13 dernières semaines</small></h3>
    ${courbes({ w: 640, h: 230, libelles: SEM, fmt: k1, series: [{ vals: X.total.v, coul: '#8D1D2C', ep: 2.4, points: true, aire: true }] })}
    <div class="an-leg"><span><i style="background:#8D1D2C"></i>Halle, toutes familles</span><span>S46 : semaine en cours</span></div></section>`;
  const top = (titre, L, f) => `<section class="an-carte"><h3>${titre}</h3><table class="an-t an-t--top"><colgroup><col style="width:18px"><col><col style="width:76px"><col style="width:84px"></colgroup><tbody>${L.map((p, i) => `<tr><td class="rg">${i + 1}</td><td class="nm"><span>${esc(p.nom)}</span><small>${esc(p.cat)}</small></td><td class="n">${f(p)}</td><td class="n">${fleche(p.evo && p.evo.pct)}</td></tr>`).join('')}</tbody></table></section>`;
  const parCA = P.slice().sort((a, b) => b.v13 - a.v13).slice(0, 8);
  const parMarge = P.filter(p => p.marge != null).sort((a, b) => b.marge - a.marge).slice(0, 8);
  const montent = P.filter(p => p.evo && p.evo.verdict === 'monte' && p.q13 >= 60).sort((a, b) => b.evo.pct - a.evo.pct).slice(0, 8);
  const baissent = P.filter(p => p.evo && p.evo.verdict === 'baisse' && p.q13 >= 60).sort((a, b) => a.evo.pct - b.evo.pct).slice(0, 8);
  page('analyse-a', 'A · Le tableau de bord', '· A le tableau de bord', 'Proposition A — le tableau de bord : l’essentiel en un écran (chiffres réels de Halle)',
    kp + `<div class="an-g2">${fam}${courbe}</div><div class="an-g4">${top('Les plus gros chiffres', parCA, p => k1(p.v13))}${top('Ce qui rapporte le plus', parMarge, p => k1(p.marge))}
      ${top('Ce qui monte', montent, p => k1(p.v13))}${top('Ce qui baisse', baissent, p => k1(p.v13))}</div>`, '13 semaines', 'Période précédente');
})();

/* --- B · le grand tableau --------------------------------------------------------------------------------------- */
(function () {
  const lignes = GROUPES.slice(0, 5).map(g => {
    const L = g.produits.slice().sort((a, b) => b.v13 - a.v13);
    const vis = L.slice(0, g.nom === 'Viennoiserie' ? 9 : 4);
    const row = p => `<tr><td class="nm"><span class="pt" style="background:${g.coul}"></span><span class="t">${esc(p.nom)}</span><small>${esc(p.cat)}</small></td>
      <td class="n">${e2(p.prix)}</td><td class="n">${p.cout != null ? e2(p.cout) : '<span class="mu">—</span>'}</td>
      <td class="n"><span class="mz ${p.coef == null ? 'mu' : p.coef < 1.67 ? 'ko' : p.coef < 2.5 ? 'att' : 'ok'}">${p.coef != null ? '× ' + n1(p.coef).replace(',0', '') : '—'}</span>${p.cible ? `<small>cible × ${n1(p.cible)}</small>` : ''}</td>
      <td class="n">${n0(p.q13)}<small>${n1(p.qSem)} / sem.</small></td><td class="n">${e0(p.v13)}<small>${pc(100 * p.v13 / TOT.v)} du CA</small></td>
      <td class="n">${p.marge != null ? e0(p.marge) + `<small>${p.taux ? 'au taux de la recette' : 'sur ' + e0(p.caHt) + ' HT'}</small>` : '<span class="mu">coût de recette manquant</span>'}</td><td class="n"><span class="mz ${zoneM(p.margePct)}">${pc(p.margePct)}</span></td>
      <td class="n">${['nouveau', 'arrete'].includes(p.evo && p.evo.verdict) ? `<span class="an-v ${verdict(p.evo)[0]}">${verdict(p.evo)[1]}</span>` : fleche(p.evo && p.evo.pct)}</td>
      <td class="n">${fleche(evoMois(p.mois[8][1], p.mois[7][1]))}</td></tr>`;
    return `<tr class="an-sous"><td colspan="10"><b>${esc(g.nom)}</b> · ${g.n} références · ${k1(g.v13)} · marge ${pc(g.margePct)} · ${fleche(g.evo.pct)}<span class="an-plus">${L.length > vis.length ? '+ ' + (L.length - vis.length) + ' autres ▾' : ''}</span></td></tr>${vis.map(row).join('')}`;
  }).join('');
  const t = `<section class="an-carte an-carte--t"><div class="an-outils"><label class="an-rech">${ico(I.chercher)}<span>Chercher un produit, une catégorie…</span></label>
      <span class="an-chip on">Toutes</span><span class="an-chip">En hausse ${hausse}</span><span class="an-chip">En baisse ${baisse}</span><span class="an-chip">Nouveaux ${nouveaux}</span><span class="an-chip">Marge sous 40 %</span><span class="an-chip">Sans coût de recette ${P.filter(p => p.marge == null).length}</span>
      <span class="an-sp"></span><span class="mu">Grouper par <b>famille ▾</b> · trier par <b>CA ▾</b> · colonnes <b>10 ▾</b></span></div>
    <table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Prix TTC</th><th class="n">Coût recette</th><th class="n">Coefficient</th><th class="n">Pièces</th><th class="n">CA TTC</th><th class="n">Marge réalisée</th><th class="n">Marge %</th><th class="n">6 sem. / 6 d’avant</th><th class="n">Oct. / sept.</th></tr></thead>
    <tbody>${lignes}</tbody><tfoot><tr><td>Le magasin · ${P.length} références</td><td></td><td></td><td></td><td class="n">${n0(TOT.q)}</td><td class="n">${e0(TOT.v)}</td><td class="n">${e0(TOT.marge)}<small>sur ${e0(TOT.caCouvert)} HT</small></td><td class="n">${pc(TOT.margePct)}</td><td class="n">${fleche(X.total.evo.pct)}</td><td class="n">${fleche(evoMois(somme(Object.values(X.mois[8].p).map(v => v[1])), somme(Object.values(X.mois[7].p).map(v => v[1]))))}</td></tr></tfoot></table>
    <p class="an-src">${LEGENDE_F} · Les ${GROUPES.length} familles de Halle (${P.length} références vendues en 13 semaines) ; 5 familles montrées ici. Marge connue sur ${n0(TOT.couverture)} % du CA : un produit sans coût de recette au panel n’a pas de marge.</p></section>`;
  page('analyse-b', 'B · Le grand tableau', '· B le grand tableau', 'Proposition B — le grand tableau : chaque produit, toutes ses données, groupé par famille (chiffres réels de Halle)', t, '13 semaines', 'Période précédente');
})();

/* --- C · marge × volume ------------------------------------------------------------------------------------------- */
(function () {
  const L = P.filter(p => p.marge != null && p.q13 >= 10);
  const W = 860, H = 520, gl = 56, gb = 40, gt = 16, gr = 16;
  const qs = L.map(p => p.qSem), medQ = qs.slice().sort((a, b) => a - b)[Math.floor(qs.length / 2)];
  const lx = v => Math.log10(Math.max(0.5, v)), xmin = lx(0.7), xmax = lx(Math.max(...qs) * 1.2);
  const x = v => gl + (W - gl - gr) * (lx(v) - xmin) / (xmax - xmin), y = m => gt + (H - gt - gb) * (1 - (m - 20) / 70);
  const maxM = Math.max(...L.map(p => p.marge));
  let g = `<rect x="${x(medQ)}" y="${y(90)}" width="${W - gr - x(medQ)}" height="${y(60) - y(90)}" class="q1"/><rect x="${gl}" y="${y(90)}" width="${x(medQ) - gl}" height="${y(60) - y(90)}" class="q2"/>
    <rect x="${x(medQ)}" y="${y(60)}" width="${W - gr - x(medQ)}" height="${y(20) - y(60)}" class="q3"/><rect x="${gl}" y="${y(60)}" width="${x(medQ) - gl}" height="${y(20) - y(60)}" class="q4"/>`;
  [20, 30, 40, 50, 60, 70, 80, 90].forEach(m => { g += `<line x1="${gl}" x2="${W - gr}" y1="${y(m)}" y2="${y(m)}" class="an-gl"/><text x="${gl - 8}" y="${y(m) + 4}" class="an-ax" text-anchor="end">${m} %</text>`; });
  [1, 2, 5, 10, 20, 50, 100, 200, 400].forEach(v => { if (lx(v) <= xmax) { g += `<line y1="${gt}" y2="${H - gb}" x1="${x(v)}" x2="${x(v)}" class="an-gl"/><text x="${x(v)}" y="${H - gb + 16}" class="an-ax" text-anchor="middle">${v}</text>`; } });
  g += `<text x="${(W + gl) / 2}" y="${H - 6}" class="an-ax" text-anchor="middle">pièces par semaine (échelle log.)</text>`;
  g += `<line x1="${x(medQ)}" x2="${x(medQ)}" y1="${gt}" y2="${H - gb}" class="an-med"/><line x1="${gl}" x2="${W - gr}" y1="${y(60)}" y2="${y(60)}" class="an-med"/>`;
  L.sort((a, b) => b.marge - a.marge).forEach(p => { const r = 3 + 13 * Math.sqrt(Math.max(0, p.marge) / maxM); g += `<circle cx="${x(p.qSem)}" cy="${y(Math.max(20, Math.min(90, p.margePct)))}" r="${r.toFixed(1)}" fill="${coulG(p.groupe)}" fill-opacity=".55" stroke="${coulG(p.groupe)}" stroke-width="1"/>`; });
  const noms = L.slice().sort((a, b) => b.marge - a.marge).slice(0, 9);
  // Étiquettes sans chevauchement : on essaie quatre places autour du point, on saute le nom si aucune n'est libre.
  const poses = [];
  noms.forEach(p => {
    const t = p.nom.slice(0, 24), lw = t.length * 5.9, cx = x(p.qSem), cy = y(Math.max(20, Math.min(90, p.margePct)));
    const essais = [[cx + 9, cy - 7, 'start'], [cx - 9, cy - 7, 'end'], [cx + 9, cy + 14, 'start'], [cx - 9, cy + 14, 'end']];
    for (const [tx, ty, an] of essais) {
      const x0 = an === 'end' ? tx - lw : tx, b = { x0, x1: x0 + lw, y0: ty - 10, y1: ty + 2 };
      if (b.x0 < gl || b.x1 > W - gr) continue;
      if (poses.some(q => b.x0 < q.x1 && b.x1 > q.x0 && b.y0 < q.y1 && b.y1 > q.y0)) continue;
      poses.push(b); g += `<text x="${tx}" y="${ty}" class="an-ax an-ax--n" text-anchor="${an}">${esc(t)}</text>`; break;
    }
  });
  g += `<text x="${W - gr - 8}" y="${y(90) + 18}" class="an-q" text-anchor="end">LOCOMOTIVES</text><text x="${gl + 8}" y="${y(90) + 18}" class="an-q">À POUSSER</text>
    <text x="${W - gr - 8}" y="${y(20) - 10}" class="an-q" text-anchor="end">À REVOIR (PRIX OU RECETTE)</text><text x="${gl + 8}" y="${y(20) - 10}" class="an-q">À QUESTIONNER</text>`;
  const svg = `<svg class="an-svg" viewBox="0 0 ${W} ${H}" width="100%" height="${H}">${g}</svg>`;
  const quad = (t, cond, act, cl) => { const Q = L.filter(cond).sort((a, b) => b.marge - a.marge); return `<div class="an-quad ${cl}"><h4>${t} <small>${Q.length} réf. · ${k1(somme(Q.map(p => p.marge)))} de marge</small></h4><p>${act}</p><ul>${Q.slice(0, 5).map(p => `<li><span>${esc(p.nom)}</span><b>${n1(p.qSem)}/sem · ${pc(p.margePct)}</b></li>`).join('')}</ul></div>`; };
  const cote = `<div class="an-quads">${quad('Locomotives', p => p.qSem >= medQ && p.margePct >= 60, 'Volume et marge : à ne jamais manquer — stock, vitrine, production du matin.', 'q1')}
    ${quad('À pousser', p => p.qSem < medQ && p.margePct >= 60, 'Bien margés, peu vendus : vitrine, dégustation, post, promotion.', 'q2')}
    ${quad('À revoir', p => p.qSem >= medQ && p.margePct < 60, 'Beaucoup vendus, peu margés : le prix, la recette, la portion.', 'q3')}
    ${quad('À questionner', p => p.qSem < medQ && p.margePct < 60, 'Ni volume ni marge : garder, remplacer, ou retirer de la gamme.', 'q4')}</div>`;
  page('analyse-c', 'C · Marge × volume', '· C marge × volume', 'Proposition C — marge × volume : chaque produit placé par ses pièces et sa marge (taille = marge en €), quatre décisions (chiffres réels de Halle)',
    `<div class="an-g2 an-g2--c"><section class="an-carte"><h3>Marge × volume <small>${L.length} références avec un coût de recette · repères : la médiane des pièces (${n1(medQ)}/sem.) et 60 % de marge</small></h3>${svg}
      <div class="an-leg">${GROUPES.slice(0, 8).map(g => `<span><i style="background:${g.coul}"></i>${esc(g.nom)}</span>`).join('')}</div></section>${cote}</div>`, '13 semaines', 'Période précédente');
})();

/* --- D · dans le temps --------------------------------------------------------------------------------------------- */
(function () {
  const G6 = GROUPES.slice(0, 7);
  const cols = X.mois.map((m, k) => ({ lib: MOISL[k] + (m.au < m.du.slice(0, 8) + '28' ? '*' : ''), vals: G6.map(g => g.mois[k]).concat([somme(GROUPES.slice(7).map(g => g.mois[k]))]) }));
  const pile = `<section class="an-carte"><h3>Le chiffre, mois par mois depuis l’ouverture <small>par famille · février : ouverture en cours de mois · novembre : jusqu’au 14</small></h3>
    ${barresEmpilees({ w: 760, h: 270, colonnes: cols, couls: G6.map(g => g.coul).concat(['#cfc8bd']) })}
    <div class="an-leg">${G6.map(g => `<span><i style="background:${g.coul}"></i>${esc(g.nom)}</span>`).join('')}<span><i style="background:#cfc8bd"></i>Autres</span></div></section>`;
  const margeM = X.mois.map(m => { const v = Object.values(m.p); const c = v.filter(x => x[3] != null); const ht = somme(c.map(x => x[2])); return ht ? 100 * somme(c.map(x => x[3])) / ht : null; });
  const mm = `<section class="an-carte"><h3>La marge réalisée, mois par mois <small>% du CA HT, sur les produits qui ont un coût de recette</small></h3>
    ${courbes({ w: 460, h: 270, libelles: MOISL, fmt: v => n0(v) + ' %', series: [{ vals: margeM, coul: '#3F7A52', ep: 2.4, points: true }] })}
    <div class="an-leg"><span><i style="background:#3F7A52"></i>marge réalisée</span><span>repère du réseau : matière à 32 % · marge brute 68 %</span></div></section>`;
  const maxC = Math.max(...GROUPES.flatMap(g => g.mois.slice(1, 9)));
  const chaleur = `<section class="an-carte"><h3>La saisonnalité <small>le chiffre de chaque famille, mois par mois (plus foncé = plus vendu)</small></h3>
    <table class="an-heat"><thead><tr><th></th>${MOISL.map(m => `<th>${m}</th>`).join('')}</tr></thead><tbody>${GROUPES.map(g => `<tr><td><i style="background:${g.coul}"></i>${esc(g.nom)}</td>${g.mois.map((v, k) => `<td style="background:rgba(141,29,44,${(0.06 + 0.82 * Math.min(1, v / maxC)).toFixed(2)});color:${v / maxC > 0.45 ? '#fff' : 'var(--ink)'}">${k1(v)}</td>`).join('')}</tr>`).join('')}</tbody></table></section>`;
  const petits = `<table class="an-t an-t--r"><thead><tr><th>Famille</th><th class="n">Halle, 13 sem.</th><th class="n">Un magasin du réseau</th><th class="n">Halle face au réseau</th><th class="n">Halle : 6 sem. / 6 d’avant</th><th class="n">Réseau : 6 sem. / 6 d’avant</th><th class="n">Pièces Halle</th><th class="n">Pièces réseau</th></tr></thead><tbody>${Object.entries(X.cats).map(([nom, c]) => { const s = c.semaines; const ev = a => 100 * (somme(a.slice(-6)) / somme(a.slice(-12, -6)) - 1);
    return `<tr><td><i class="pt" style="background:${coulG(nom)}"></i>${esc(nom)}</td><td class="n">${k1(somme(s.magasin))}</td><td class="n">${k1(somme(s.reseau))}</td><td class="n">${fleche(100 * (somme(s.magasin) / somme(s.reseau) - 1))}</td>
      <td class="n">${fleche(ev(s.magasin))}</td><td class="n">${fleche(ev(s.reseau))}</td><td class="n">${n0(somme(s.magasinQ))}</td><td class="n">${n0(somme(s.reseauQ))}</td></tr>`; }).join('')}</tbody></table><p class="an-src">${LEGENDE_F}</p>`;
  const J = X.journee;
  const jour = `<section class="an-carte"><h3>La journée d’une famille <small>Viennoiserie, le ${J.jourNom} ${dfr(J.date)}, heure par heure, face aux 6 derniers ${J.jourNom}s</small></h3>
    ${courbes({ w: 1340, h: 230, libelles: J.heures.map(h => h.h + ' h'), fmt: e0, series: [{ vals: J.heures.map(h => h.moyCa), coul: '#b9b1a6', ep: 1.5, tirets: true }, { vals: J.heures.map(h => h.ca), coul: '#8D1D2C', ep: 2.2, points: true }] })}
    <div class="an-leg"><span><i style="background:#8D1D2C"></i>${J.jourNom} ${dfr(J.date)}</span><span><i style="background:#b9b1a6"></i>moyenne des 6 derniers ${J.jourNom}s</span></div></section>`;
  page('analyse-d', 'D · Dans le temps', '· D dans le temps', 'Proposition D — dans le temps : les mois depuis l’ouverture, la saisonnalité, chaque famille face au réseau, la journée heure par heure (chiffres réels de Halle)',
    `<div class="an-g2 an-g2--d">${pile}${mm}</div>${chaleur}<section class="an-carte"><h3>Chaque famille face au réseau <small>les 13 dernières semaines · le réseau : la moyenne d’un magasin</small></h3>${petits}</section>${jour}`,
    'Depuis l’ouverture', 'Moyenne du réseau');
})();

/* --- E et F · les questions de l'exploitant (15/11/2026, « pose-toi les bonnes questions : qu'est-ce qu'un exploitant
   veut savoir d'une catégorie ou d'un produit ») : une carte par question, la réponse en une phrase chiffrée, le détail
   dessous. Les questions sans donnée (avec quoi il se vend, les ruptures) ne sont pas posées plutôt que devinées. --- */
const Q = (n, question, reponse, corps, cl) => `<section class="an-carte an-qq${cl ? ' ' + cl : ''}"><div class="an-qh"><span class="an-qn">${n}</span><h4>${question}</h4></div><p class="an-rep">${reponse}</p>${corps || ''}</section>`;
const retenir = L => `<section class="an-ret"><h4>Ce qu’il faut retenir</h4><ul>${L.map(x => `<li>${x}</li>`).join('')}</ul></section>`;
const ev6 = a => { const r = somme(a.slice(-6)), b = somme(a.slice(-12, -6)); return b ? 100 * (r / b - 1) : null; };
const JL = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const joursOuv = m => Math.round((new Date(m.au + 'T12:00:00') - new Date(m.du + 'T12:00:00')) / 864e5) + 1;
const G = X.grille, G14 = a => somme(a || []);
const p10k = (q, taille) => taille ? q / taille * 1e4 : null;
const rangDe = (L, cle, x) => L.filter(y => y[cle] != null).sort((a, b) => b[cle] - a[cle]).findIndex(y => y === x) + 1;
const barre = (v, max, coul = '#8D1D2C') => `<span class="an-bar"><i style="width:${(100 * Math.max(0, v) / (max || 1)).toFixed(1)}%;background:${coul}"></i></span>`;
const INV = X.invendus13, invDe = pid => INV.produits.find(x => x.pid === pid) || { pieces: 0, cout: 0, caPerdu: 0, motifPieces: 0 };
const er = n => n === 1 ? '1<sup>er</sup>' : n + '<sup>e</sup>';
// Les mois d'un produit ou d'une famille : pièces et chiffre par jour ouvert, la flèche face au mois d'avant (pas avant
// avril : février est le mois de l'ouverture, mars ne se compare pas à un demi-mois).
function tableMois(lignes) {
  const jours = X.mois.map(joursOuv);
  const ql = lignes.map((l, k) => l.q / jours[k]);
  return `<table class="an-t an-t--mo"><thead><tr><th></th>${MOISL.map((m, k) => `<th class="n">${m}${k === MOISL.length - 1 ? '*' : ''}</th>`).join('')}</tr></thead><tbody>
    <tr><td>Pièces</td>${lignes.map(l => `<td class="n">${n0(l.q)}</td>`).join('')}</tr>
    <tr><td>par jour</td>${ql.map(v => `<td class="n">${n0(v)}</td>`).join('')}</tr>
    <tr><td>face au mois d’avant</td>${ql.map((v, k) => `<td class="n">${k >= 2 ? fleche(evoMois(v, ql[k - 1])) : '<span class="an-f mu">—</span>'}</td>`).join('')}</tr>
    <tr><td>Chiffre TTC</td>${lignes.map(l => `<td class="n">${k1(l.ca)}</td>`).join('')}</tr>
    <tr><td>Marge réalisée</td>${lignes.map(l => `<td class="n">${k1(l.marge)}</td>`).join('')}</tr></tbody></table>
    <p class="an-src">* novembre jusqu’au 14 · février : le mois de l’ouverture · « par jour » : sur les jours du mois, pour comparer des mois inégaux · ${LEGENDE_F}</p>`;
}

/* --- E · le produit ----------------------------------------------------------------------------------------------- */
(function () {
  const F = X.fiche, p = P.find(x => x.id === 1610006), s = F.semaines, pr = F.prix;
  const ht = p.prixHt, cout = p.cout, tva = p.prix - p.prixHt, mu = ht - cout;
  const evH = ev6(s.magasin), evR = ev6(s.reseau);
  const rgCA = rangDe(P, 'v13', p), rgM = rangDe(P, 'marge', p), partM = 100 * p.marge / TOT.marge;
  const inv = invDe(p.id), cuits = p.q13 + inv.pieces, tauxJ = 100 * inv.pieces / cuits;
  const rgJ = INV.produits.slice().sort((a, b) => b.pieces - a.pieces).findIndex(x => x.pid === p.id) + 1;
  // Les jours de la semaine : les 4 dernières semaines closes (19/10 → 14/11), les jetés au jour de leur saisie.
  const J = X.joursCroissant, parJ = {};
  J.dates.forEach((d, i) => { if (d >= '2026-11-15') { return; } const k = new Date(d + 'T12:00:00').getDay(); const o = parJ[k] = parJ[k] || { v: [], j: [] };
    o.v.push(J.magasin[i]); const ij = X.invJours.find(x => x.date === d); if (ij && ij.pieces > 0) { o.j.push(ij.p[String(p.id)] || 0); } });
  const ordre = [1, 2, 3, 4, 5, 6, 0].map(k => { const o = parJ[k]; return { k, moy: somme(o.v) / o.v.length, max: Math.max(...o.v), min: Math.min(...o.v), jet: o.j.length ? somme(o.j) / o.j.length : null, nj: o.j.length, n: o.v.length }; });
  const maxJ = Math.max(...ordre.map(o => o.moy)), fort = ordre.slice().sort((a, b) => b.moy - a.moy), faible = fort[fort.length - 1];
  const pireJ = ordre.filter(o => o.jet != null).sort((a, b) => b.jet / b.moy - a.jet / a.moy)[0];
  // Les autres magasins, sans leur nom : pièces par semaine et pièces pour 10 k€ de chiffre (14 semaines, S33 → S46).
  const g = G.produits.find(x => x.pid === p.id);
  const mags = [{ nom: 'Halle', q: G14(g.halle), t: G.tailleHalle, ev: ev6((g.halle || []).slice(1)), moi: true }]
    .concat(g.autres.map((a, i) => ({ q: G14(a), t: G.taillesAutres[i], ev: ev6((a || []).slice(1)) })))
    .map(m => ({ ...m, k: p10k(m.q, m.t) })).sort((a, b) => b.k - a.k);
  // Pièces par semaine : les 13 mêmes semaines que la question 1 (S34 → S46) ; « pour 10 k€ » : les 14 de la grille.
  [G14((g.halle || []).slice(1))].concat(g.autres.map(a => G14((a || []).slice(1)))).forEach((q, i) => { const m = mags.find(x => (i === 0) === !!x.moi && (i === 0 || x.t === G.taillesAutres[i - 1])); if (m) { m.sem = q / 13; } });
  let na = 0; mags.forEach(m => { if (!m.moi) { m.nom = 'Magasin ' + String.fromCharCode(65 + na++); } });
  const autresK = mags.filter(m => !m.moi).map(m => m.k), rgR = mags.findIndex(m => m.moi) + 1;
  const remise = 100 * (pr.magasin.p / p.prix - 1);

  const tete = `<div class="an-fiche-h"><div class="an-choix">${ico(I.chercher)}<b>${esc(p.nom)}</b><span>${esc(p.cat)} · réf. 1610006 ▾</span></div>
    <div class="an-chips"><span>13 semaines : du ${dfr(BORNES[0][0])} au ${dfr(BORNES[BORNES.length - 1][1])}</span><span>comparé aux 6 semaines d’avant et aux 3 autres magasins, sans leur nom</span></div></div>`;
  const ret = retenir([
    `C’est votre <b>${er(rgCA)} vente</b> sur ${P.length} références : ${pc(100 * p.v13 / TOT.v)} du chiffre et <b>${pc(partM)} de la marge</b> du magasin.`,
    `Il part le week-end : <b>${n0(fort[0].moy)} le ${JL[fort[0].k]}</b>, ${n0(fort[1].moy)} le ${JL[fort[1].k]}, ${n0(faible.moy)} le ${JL[faible.k]} — la cuisson suit le jour, pas une moyenne.`,
    `<b>Un croissant sur ${n0(100 / tauxJ)} est jeté</b> : ${n0(inv.pieces)} en 13 semaines, ${e0(inv.cout)} de matière, la ${rgJ === 1 ? '1<sup>re</sup>' : rgJ + '<sup>e</sup>'} perte du magasin.`,
    `Prix encaissé et volume, à taille égale, au plus haut des 4 magasins : le prix tient, il n’y a rien à baisser.`]);

  const q1 = Q(1, 'Combien j’en vends ?', `<b>${n0(p.qSem)} pièces par semaine</b>. Les 6 dernières semaines face aux 6 d’avant : ${fleche(evH)} chez vous, ${fleche(evR)} pour un magasin moyen du réseau.`,
    courbes({ w: 700, h: 230, libelles: s.tranches, series: [{ vals: s.reseau, coul: '#8f8778', ep: 1.6, tirets: true }, { vals: s.magasin, coul: '#8D1D2C', ep: 2.4, points: true, aire: true }] })
    + `<div class="an-leg"><span><i style="background:#8D1D2C"></i>Halle, pièces par semaine</span><span><i style="background:#8f8778"></i>un magasin moyen du réseau</span></div>`);
  const q2 = Q(2, 'Combien il me rapporte ?', `<b>${e2(mu)} par pièce</b>, soit ${pc(100 * mu / ht)} du prix hors TVA. <b>${k1(p.marge)} en 13 semaines</b> : ${er(rgM)} marge du magasin.`,
    `<div class="an-eq"><span><b>${e2(p.prix)}</b>prix TTC</span><i>−</i><span><b>${e2(tva)}</b>TVA</span><i>=</i><span><b>${e2(ht)}</b>hors TVA</span><i>−</i><span><b>${e2(cout)}</b>recette</span><i>=</i><span class="ok"><b>${e2(mu)}</b>marge</span></div>
     <table class="an-t an-t--kv"><tbody><tr><td>Coefficient (prix HT ÷ recette)</td><td class="n">× ${n1(p.coef)}</td></tr><tr><td>Part de la marge du magasin</td><td class="n">${pc(partM)}</td></tr><tr><td>Rang au chiffre · à la marge</td><td class="n">${er(rgCA)} · ${er(rgM)} sur ${P.length}</td></tr></tbody></table>`);
  const q3 = Q(3, 'Combien j’en jette ?', `<b>${n0(inv.pieces)} pièces en 13 semaines</b>, ${n0(inv.pieces / 13)} par semaine : ${pc(tauxJ)} de ce qui sort du four. ${e0(inv.cout)} de matière perdue, ${pc(100 * inv.cout / p.marge)} de sa marge.`,
    `<table class="an-t an-t--kv"><tbody><tr><td>Sortis du four (vendus + jetés)</td><td class="n">${n0(cuits)}</td></tr><tr><td>Vendus</td><td class="n">${n0(p.q13)}</td></tr>
      <tr><td>Jetés en fin de journée · pour une autre raison</td><td class="n">${n0(inv.motifPieces)} · ${n0(inv.pieces - inv.motifPieces)}</td></tr><tr><td>Le chiffre qu’ils auraient fait</td><td class="n">${e0(inv.caPerdu)}</td></tr>
      <tr class="tot"><td>Ce qu’il vous laisse, invendus déduits</td><td class="n">${k1(p.marge - inv.cout)}</td></tr></tbody></table>`);
  const q4 = Q(4, 'Quel jour il part ?', `<b>${n0(fort[0].moy)} le ${JL[fort[0].k]}</b>, ${n0(faible.moy)} le ${JL[faible.k]} : ${n1(fort[0].moy / faible.moy)} fois plus. C’est le ${JL[pireJ.k]} qu’on en jette le plus : ${n0(pireJ.jet)} pour ${n0(pireJ.moy)} vendus.`,
    `<table class="an-t an-t--g an-t--j"><thead><tr><th>Jour</th><th>Vendus, en moyenne</th><th class="n">Au plus</th><th class="n">Au moins</th><th class="n">Jetés, en moyenne</th></tr></thead><tbody>
      ${ordre.map(o => `<tr><td>${JL[o.k]}</td><td><span class="an-bv">${barre(o.moy, maxJ)}<b>${n0(o.moy)}</b></span></td><td class="n">${n0(o.max)}</td><td class="n">${n0(o.min)}</td><td class="n">${o.jet == null ? '—' : n0(o.jet)}</td></tr>`).join('')}</tbody></table>
     <p class="an-src">Les 4 dernières semaines closes, du 19 octobre au 14 novembre · jetés : au jour de leur saisie en caisse, sur les jours où des invendus ont été saisis.</p>`);
  const q5 = Q(5, 'Mon prix est-il bon ?', `<b>${e2(pr.magasin.p)} encaissés</b> pour ${e2(p.prix)} affichés (${sg(remise)} de remises) : le prix le plus haut du réseau, dont la médiane est à ${e2(pr.reseau.med)}.`,
    `<div class="an-reg"><div><span class="k">Affiché</span><span class="v">${e2(p.prix)}</span><span class="s">TTC, en caisse</span></div><div><span class="k">Encaissé</span><span class="v">${e2(pr.magasin.p)}</span><span class="s">remises comprises, ${esc(pr.periode)}</span></div>
      <div><span class="k">Le réseau</span><span class="v">${e2(pr.reseau.med)}</span><span class="s">médiane · de ${e2(pr.reseau.min)} à ${e2(pr.reseau.max)}</span></div></div>`);
  const q6 = Q(6, 'Est-ce que je fais mieux que les autres ?', `Oui : <b>${n0(mags.find(m => m.moi).k)} croissants pour 10 k€ de chiffre</b>, contre ${n0(Math.min(...autresK))} à ${n0(Math.max(...autresK))} dans les 3 autres magasins. ${er(rgR)} sur 4 à taille égale.`,
    `<table class="an-t an-t--g"><thead><tr><th>Magasin</th><th class="n">Pièces par semaine</th><th>Pour 10 k€ de chiffre du magasin</th><th class="n">6 sem. / 6 d’avant</th></tr></thead><tbody>
      ${mags.map(m => `<tr class="${m.moi ? 'moi' : ''}"><td>${m.nom}</td><td class="n">${n0(m.sem)}</td><td><span class="an-bv">${barre(m.k, Math.max(...mags.map(x => x.k)), m.moi ? '#8D1D2C' : '#b9b1a6')}<b>${n0(m.k)}</b></span></td><td class="n">${fleche(m.ev)}</td></tr>`).join('')}</tbody></table>
     <p class="an-src">Pièces par semaine : les 13 semaines · « pour 10 k€ » : les pièces rapportées au chiffre de chaque magasin, du ${dfr(G.bornes[0][0])} au ${dfr(G.bornes[G.bornes.length - 1][1])}, pour comparer un grand et un petit · les autres magasins ne sont pas nommés.</p>`);
  const q7 = Q(7, 'Comment a-t-il évolué depuis l’ouverture ?', `Le meilleur mois : <b>${MOISL[p.mois.reduce((b, m, k, a) => m[0] / joursOuv(X.mois[k]) > a[b][0] / joursOuv(X.mois[b]) ? k : b, 0)]}</b>, au nombre de pièces par jour. Mois par mois, face au mois d’avant :`,
    tableMois(p.mois.map(m => ({ q: m[0], ca: m[1], marge: m[3] }))), 'an-qq--l');
  page('analyse-e', 'E · Le produit, question par question', '· E le produit, question par question', 'Proposition E — le produit, question par question : ce qu’un exploitant veut savoir du croissant (chiffres réels de Halle ; les autres magasins sans leur nom)',
    tete + ret + `<div class="an-qs">${q1}${q2}${q3}${q4}${q5}${q6}${q7}</div>`, '13 semaines', 'Période précédente');
})();

/* --- F · la famille ----------------------------------------------------------------------------------------------- */
(function () {
  const fam = GROUPES.find(g => g.nom === 'Viennoiserie'), L = fam.produits.slice().sort((a, b) => b.v13 - a.v13);
  const ids = new Set(L.map(x => x.id)), cats = new Set(L.map(x => x.cat).concat(X.cats.Viennoiserie.categories));
  const cs = X.cats.Viennoiserie.semaines, evH = ev6(cs.magasin), evR = ev6(cs.reseau);
  const partCA = 100 * fam.v13 / TOT.v, partM = 100 * fam.marge / TOT.marge;
  // Qui la porte : le chiffre cumulé.
  let cum = 0; const pareto = L.map(x => { cum += x.v13; return { ...x, part: 100 * x.v13 / fam.v13, cum: 100 * cum / fam.v13 }; });
  const n3 = pareto[2].cum;
  // Les autres magasins, sans leur nom, à taille égale.
  const GF = G.produits.filter(x => cats.has(x.cat));
  const qH = somme(GF.map(x => G14(x.halle))), kH = p10k(qH, G.tailleHalle);
  const kA = G.taillesAutres.map((t, i) => p10k(somme(GF.map(x => G14(x.autres[i]))), t));
  const absents = GF.filter(x => G14(x.halle) === 0).map(x => { const v = x.autres.filter(a => G14(a) > 0); return { nom: x.nom, n: v.length, sem: v.length ? somme(v.map(G14)) / v.length / G.tranches.length : 0 }; })
    .filter(x => x.n > 0).sort((a, b) => b.n - a.n || b.sem - a.sem);
  const face = L.filter(x => x.q13 >= 100).map(x => { const gx = GF.find(y => y.pid === x.id); if (!gx) { return null; }
    const kh = p10k(G14(gx.halle), G.tailleHalle), ka = gx.autres.map((a, i) => p10k(G14(a), G.taillesAutres[i])).filter(v => v > 0);
    const moy = ka.length ? somme(ka) / ka.length : null; return { nom: x.nom, kh, moy, n: ka.length, rel: moy ? 100 * (kh / moy - 1) : null }; }).filter(Boolean);
  // Montent, baissent.
  // La semaine type (la médiane des pièces) : une commande ou une fête d'une semaine ne fait pas une tendance.
  const med = a => { const b = a.slice().sort((x, y) => x - y), m = b.length >> 1; return b.length % 2 ? b[m] : (b[m - 1] + b[m]) / 2; };
  const nouveaux = L.filter(x => x.evo && x.evo.verdict === 'nouveau');
  const avecEvo = L.filter(x => x.q13 >= 60 && !nouveaux.includes(x)).map(x => { const a = med(x.q.slice(-6)), b = med(x.q.slice(-12, -6)), m13 = med(x.q);
    const hors = x.q.map((v, k) => ({ v, s: SEM[k] })).filter(w => m13 > 0 && w.v > 3 * m13); return { ...x, pctM: b ? 100 * (a / b - 1) : null, hors }; }).filter(x => x.pctM != null && med(x.q.slice(-12, -6)) >= 5);
  const montent = avecEvo.filter(x => x.pctM >= 5).sort((a, b) => b.pctM - a.pctM), baissent = avecEvo.filter(x => x.pctM <= -5).sort((a, b) => a.pctM - b.pctM);
  const horsNorme = avecEvo.filter(x => x.hors.length);
  // Les pertes : les invendus de la famille, produit par produit.
  const inv = L.map(x => { const i = invDe(x.id); return { nom: x.nom, vendus: x.q13, jet: i.pieces, cout: i.cout, taux: i.pieces ? 100 * i.pieces / (x.q13 + i.pieces) : 0, margePct: x.margePct }; })
    .filter(x => x.jet > 0).sort((a, b) => b.cout - a.cout);
  const jetT = somme(inv.map(x => x.jet)), coutT = somme(inv.map(x => x.cout)), top3J = somme(inv.slice(0, 3).map(x => x.cout));
  const faibles = L.filter(x => x.margePct != null && x.margePct < 50 && x.q13 >= 60).sort((a, b) => a.margePct - b.margePct);
  // L'heure : le samedi moyen (les 6 derniers), le cumul du chiffre heure par heure.
  const H = X.journee.heures, totH = somme(H.map(h => h.moyCa)); let ch = 0; const HC = H.map(h => { ch += h.moyCa; return { ...h, cum: 100 * ch / totH }; });
  const avant9 = HC.find(h => h.h === 8).cum, avant11 = HC.find(h => h.h === 10).cum, maxH = Math.max(...H.map(h => h.moyCa));

  const tete = `<div class="an-fiche-h"><div class="an-choix">${ico(I.chercher)}<b>Viennoiserie</b><span>famille · ${L.length} références vendues · ${[...X.cats.Viennoiserie.categories].join(', ')} ▾</span></div>
    <div class="an-chips"><span>13 semaines : du ${dfr(BORNES[0][0])} au ${dfr(BORNES[BORNES.length - 1][1])}</span><span>comparée aux 6 semaines d’avant et aux 3 autres magasins, sans leur nom</span></div></div>`;
  const ret = retenir([
    `La viennoiserie fait <b>${pc(partCA)} de votre chiffre</b> et ${pc(partM)} de votre marge ; ${fleche(evH)} sur 6 semaines quand le réseau est à ${fleche(evR)}.`,
    `<b>3 produits font ${n0(n3)} % de son chiffre</b> : ${pareto.slice(0, 3).map(x => esc(x.nom)).join(', ')}. Ce sont eux qu’il ne faut jamais manquer.`,
    `<b>${n0(jetT)} pièces jetées</b> en 13 semaines, ${e0(coutT)} de matière : ${inv.slice(0, 3).map(x => esc(x.nom)).join(', ')} en font ${n0(100 * top3J / coutT)} %.`,
    `${n0(avant9)} % du samedi est vendu avant 9 h : la fournée de 7 h fait la journée.`,
    absents.length ? `Les autres magasins vendent <b>${esc(absents[0].nom)}</b> (${absents[0].n} sur 3, ${n0(absents[0].sem)} par semaine chacun) : vous ne l’avez pas.` : null].filter(Boolean));

  const q1 = Q(1, 'Combien pèse-t-elle chez moi ?', `<b>${k1(fam.v13)} en 13 semaines</b>, ${pc(partCA)} du chiffre du magasin, et <b>${k1(fam.marge)} de marge</b> (${pc(fam.margePct)} du hors TVA), ${pc(partM)} de la marge du magasin.`,
    `<div class="an-reg an-reg--4"><div><span class="k">Pièces par semaine</span><span class="v">${n0(fam.q13 / 13)}</span><span class="s">${L.length} références vendues</span></div>
      <div><span class="k">Pour 10 k€ de chiffre</span><span class="v">${n0(kH)}</span><span class="s">pièces · les 3 autres : ${n0(Math.min(...kA))} à ${n0(Math.max(...kA))}</span></div>
      <div><span class="k">Marge par pièce</span><span class="v">${e2(fam.marge / fam.q13)}</span><span class="s">en moyenne sur la famille</span></div>
      <div><span class="k">Part du chiffre</span><span class="v">${pc(partCA)}</span><span class="s">le reste : ${pc(100 - partCA)} sur ${GROUPES.length - 1} familles</span></div></div>`);
  const q2 = Q(2, 'Est-ce qu’elle monte ou qu’elle baisse ?', `Les 6 dernières semaines face aux 6 d’avant : <b>${fleche(evH)} chez vous</b>, ${fleche(evR)} pour un magasin moyen du réseau.`,
    courbes({ w: 700, h: 210, libelles: cs.tranches, fmt: k1, series: [{ vals: cs.reseau, coul: '#8f8778', ep: 1.6, tirets: true }, { vals: cs.magasin, coul: '#8D1D2C', ep: 2.4, points: true, aire: true }] })
    + `<div class="an-leg"><span><i style="background:#8D1D2C"></i>Halle, chiffre TTC par semaine</span><span><i style="background:#8f8778"></i>un magasin moyen du réseau</span></div>`);
  const q3 = Q(3, 'Quels produits la portent ?', `<b>${pareto.slice(0, 3).map(x => esc(x.nom)).join(', ')}</b> : ${n0(n3)} % du chiffre de la famille à eux trois.`,
    `<table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Chiffre</th><th>Part de la famille</th><th class="n">Cumul</th><th class="n">Marge</th><th class="n">6 sem. / 6 d’avant</th></tr></thead><tbody>
      ${pareto.slice(0, 9).map(x => `<tr><td>${esc(x.nom)}</td><td class="n">${k1(x.v13)}</td><td><span class="an-bv">${barre(x.part, pareto[0].part)}<b>${pc(x.part)}</b></span></td><td class="n">${n0(x.cum)} %</td><td class="n">${x.margePct == null ? '—' : pc(x.margePct)}</td><td class="n">${fleche(x.evo && x.evo.pct)}</td></tr>`).join('')}
      <tr class="tot"><td>Les ${L.length - 9} autres</td><td class="n">${k1(somme(pareto.slice(9).map(x => x.v13)))}</td><td>${pc(100 - pareto[8].cum)}</td><td class="n">100 %</td><td></td><td></td></tr></tbody></table>`);
  const lst = (A, vide) => A.length ? `<ul class="an-lst">${A.slice(0, 6).map(x => `<li><span>${esc(x.nom)}</span><small>${n0(med(x.q.slice(-6)))}/sem</small>${fleche(x.pctM)}</li>`).join('')}</ul>` : `<p class="an-src">${vide}</p>`;
  const q4 = Q(4, 'Lesquels montent, lesquels baissent ?', `<b>${montent.length} en hausse</b>, ${baissent.length} en baisse, ${avecEvo.length - montent.length - baissent.length} stables : la semaine type des 6 dernières face à celle des 6 d’avant.${nouveaux.length ? ` Et ${nouveaux.length} nouveaux.` : ''}`,
    `<div class="an-g2 an-g2--l"><div><h5>Montent</h5>${lst(montent, 'aucun')}</div><div><h5>Baissent</h5>${lst(baissent, 'aucun')}</div></div>
     ${nouveaux.length ? `<h5>Nouveaux</h5><ul class="an-lst">${nouveaux.map(x => `<li><span>${esc(x.nom)}</span><small>depuis ${esc(x.evo.depuis)}</small><b>${n0(med(x.q.slice(-4)))} par semaine</b></li>`).join('')}</ul>` : ''}
     ${horsNorme.length ? `<h5>Des semaines hors norme, à ne pas prendre pour une tendance</h5><ul class="an-lst">${horsNorme.map(x => `<li><span>${esc(x.nom)}</span><small>une semaine type : ${n0(med(x.q))}</small><b>${x.hors.map(w => w.s + ' : ' + n0(w.v)).join(' · ')}</b></li>`).join('')}</ul>` : ''}
     <p class="an-src">Semaine type : la médiane des pièces, qu’une commande ou une fête d’une semaine ne déplace pas · hors norme : plus de 3 fois la semaine type · produits vendus plus de 60 fois en 13 semaines, et au moins 5 par semaine avant.</p>`);
  const q5 = Q(5, 'Où est-ce que je perds de l’argent ?', `<b>${n0(jetT)} pièces jetées</b> en 13 semaines, ${e0(coutT)} de matière : ${pc(100 * coutT / fam.marge)} de la marge de la famille.${faibles.length ? ` Et ${faibles.length} produit${faibles.length > 1 ? 's' : ''} sous 50 % de marge.` : ''}`,
    `<table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Vendus</th><th class="n">Jetés</th><th>Part des sortis du four</th><th class="n">Matière perdue</th><th class="n">Marge</th></tr></thead><tbody>
      ${inv.slice(0, 8).map(x => `<tr><td>${esc(x.nom)}</td><td class="n">${n0(x.vendus)}</td><td class="n">${n0(x.jet)}</td><td><span class="an-bv">${barre(x.taux, 25, x.taux >= 15 ? '#A61E1E' : x.taux >= 8 ? '#B26A00' : '#3F7A52')}<b>${pc(x.taux)}</b></span></td><td class="n">${e0(x.cout)}</td><td class="n">${x.margePct == null ? '—' : pc(x.margePct)}</td></tr>`).join('')}</tbody></table>
     <p class="an-src">Jetés : en fin de journée surtout, saisis en caisse · la barre : vert sous 8 %, orange de 8 à 15 %, rouge au-delà.</p>`);
  const q6 = Q(6, 'À quelle heure se vend-elle ?', `Un samedi, <b>${n0(avant9)} % est vendu avant 9 h</b> et ${n0(avant11)} % avant 11 h. L’après-midi ne fait presque rien.`,
    `<table class="an-t an-t--g an-t--j"><thead><tr><th>Heure</th><th>Chiffre, un samedi moyen</th><th class="n">Pièces</th><th class="n">Vendu à la fin de l’heure</th></tr></thead><tbody>
      ${HC.map(h => `<tr><td>${h.h} h</td><td><span class="an-bv">${barre(h.moyCa, maxH)}<b>${e0(h.moyCa)}</b></span></td><td class="n">${n0(h.moyQ)}</td><td class="n">${n0(h.cum)} %</td></tr>`).join('')}</tbody></table>
     <p class="an-src">La moyenne des 6 derniers samedis, du ${dfr(X.journee.jours[X.journee.jours.length - 1])} au ${dfr(X.journee.jours[0])}.</p>`);
  const q7 = Q(7, 'Et chez les autres ?', `À taille égale, <b>vous vendez ${n0(kH)} viennoiseries pour 10 k€ de chiffre</b>, les 3 autres magasins ${n0(Math.min(...kA))} à ${n0(Math.max(...kA))}.${absents.length ? ` Ils vendent ${absents.length === 1 ? 'un produit' : absents.length + ' produits'} que vous n’avez pas.` : ''}`,
    `<div class="an-g2 an-g2--l"><div><table class="an-t an-t--g"><thead><tr><th>Produit</th><th class="n">Halle, pour 10 k€</th><th class="n">Les autres, pour 10 k€</th><th class="n">Halle face aux autres</th></tr></thead><tbody>
      ${face.slice(0, 7).map(x => `<tr><td>${esc(x.nom)}</td><td class="n">${n0(x.kh)}</td><td class="n">${n0(x.moy)}<small>${x.n} magasin${x.n > 1 ? 's' : ''}</small></td><td class="n">${fleche(x.rel)}</td></tr>`).join('')}</tbody></table><p class="an-src">« pour 10 k€ » : les pièces rapportées au chiffre de chaque magasin, du ${dfr(G.bornes[0][0])} au ${dfr(G.bornes[G.bornes.length - 1][1])} · les autres : la moyenne des magasins qui le vendent.</p></div>
     <div>${absents.length ? `<h5>Vendus ailleurs, pas chez vous</h5><ul class="an-lst">${absents.map(x => `<li><span>${esc(x.nom)}</span><small>${x.n} magasin${x.n > 1 ? 's' : ''} sur 3</small><b>${n0(x.sem)} par semaine chacun</b></li>`).join('')}</ul><p class="an-src">Des références du catalogue que vous n’avez vendues aucune semaine, et que d’autres magasins vendent : à essayer, ou à écarter en connaissance de cause.</p>` : ''}</div></div>`, 'an-qq--l');
  const q8 = Q(8, 'Comment a-t-elle évolué depuis l’ouverture ?', `Mois par mois, en pièces par jour, face au mois d’avant :`,
    tableMois(X.mois.map((m, k) => ({ q: somme(L.map(x => x.mois[k][0])), ca: somme(L.map(x => x.mois[k][1])), marge: somme(L.map(x => x.mois[k][3] || 0)) }))), 'an-qq--l');
  page('analyse-f', 'F · La famille, question par question', '· F la famille, question par question', 'Proposition F — la famille, question par question : ce qu’un exploitant veut savoir de sa viennoiserie (chiffres réels de Halle ; les autres magasins sans leur nom)',
    tete + ret + `<div class="an-qs">${q1}${q2}${q3}${q4}${q5}${q6}${q7}${q8}</div>`, '13 semaines', 'Période précédente');
})();

console.log('analyse-a … analyse-f écrites ; CA 13 sem.', k1(TOT.v), '; marge', k1(TOT.marge), pc(TOT.margePct), '; couverture', pc(TOT.couverture));
