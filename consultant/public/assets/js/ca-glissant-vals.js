/* Cockpit CEO — Résultat › Mois : le CA du réseau sur douze mois glissants (27/11/2026, gabarit : ca-glissant-tpl.js).
 *
 * Une courbe, les douze derniers mois CLOS jusqu'au mois regardé : le mois en cours n'y entre pas (un mois partiel
 * casserait la courbe ; les tuiles juste au-dessus le donnent déjà). En pointillé, les mêmes mois un an plus tôt.
 * Les chiffres sont ceux de /stores/perf — le CA du panel, mois par mois et magasin par magasin, celui de la heatmap
 * en dessous. L'exercice et le précédent sont lus au chargement ; une année plus ancienne (le N-1 de novembre et
 * décembre, ou un mois regardé loin en arrière) se lit une fois, à la demande, et se garde dans `D.perfX`.
 *
 * Trois chiffres : le CA des douze mois, son évolution face aux douze mois d'avant (le réseau entier, ouvertures
 * comprises) et la même évolution à magasins comparables — un magasin ne compte un mois que s'il avait déjà, un an
 * plus tôt, un mois plein derrière lui (le mois d'ouverture, partiel, ne sert pas de base).
 */
import { readOne } from './api.js';

const W = 1000, H = 214, ML = 62, MR = 18, MT = 24, MB = 38;

/** Un pas « rond » pour l'axe : 1, 2, 2,5 ou 5 × 10^n. */
function pasRond(brut){
  const p = Math.pow(10, Math.floor(Math.log10(brut || 1)));
  const r = brut / p;
  return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * p;
}
const kE = v => Math.round(v / 1000).toLocaleString('fr-BE') + ' k€';
const pct = v => (v == null || !isFinite(v)) ? '' : (v >= 0 ? '+' : '−') + Math.abs(v * 100).toFixed(1).replace('.', ',') + ' %';

/**
 * Les valeurs de la courbe, pour la période `r` de /exploitation/periode (vue mois). Rend null tant que la période
 * n'est pas lue.
 */
export function valsCaGlissant(app, r){
  if (!r || !r.du) { return null; }
  const S = app.state, D = app.D, E = app.exo();
  // Le dernier mois de la fenêtre : le mois regardé s'il est clos, sinon celui d'avant.
  const d0 = new Date(r.du + 'T12:00:00');
  const tFin = d0.getFullYear() * 12 + d0.getMonth() - (r.enCours ? 1 : 0);
  const ym = t => ({ y: Math.floor(t / 12), m: ((t % 12) + 12) % 12 });
  const mois = Array.from({ length: 12 }, (_, k) => tFin - 11 + k);

  // Les années à lire en plus : de la plus ancienne utile (le mois avant le N-1 du premier mois) à E-2.
  const X = D.perfX || {};
  const anMin = ym(mois[0] - 13).y;
  const manquent = [];
  for (let y = anMin; y < E - 1; y++) { if (!X[y]) { manquent.push(y); } }
  if (manquent.length && !app._rgLecture) {
    app._rgLecture = true;
    readOne('/stores/perf?granularite=mois&annees=' + manquent.join(',')).then(p => {
      D.perfX = D.perfX || {};
      // Une lecture échouée se garde vide : le N-1 manque et l'écran le dit, sans relire à chaque rendu.
      manquent.forEach(y => { D.perfX[y] = (p || []).filter(l => +l.annee === y); });
      if (!p) { D.perfXEchec = true; }
      app._rgLecture = false; app.setState({});
    });
  }
  const connu = y => y >= E - 1 || !!X[y];
  // Le CA d'un magasin pour le mois t : null si rien d'encaissé, undefined si l'année n'est pas (encore) lue.
  const caDe = (s, t) => {
    const { y, m } = ym(t);
    const c = s.perf && s.perf[y] && s.perf[y][m];
    if (c) { return c.ca > 0 ? c.ca : null; }
    if (!connu(y)) { return undefined; }
    const l = (X[y] || []).find(l2 => String(l2.storeId) === String(s.id) && +l2.mois === m + 1);
    return l && l.ca > 0 ? l.ca : null;
  };

  const mags = app.open();
  const pts = mois.map(t => {
    const { y, m } = ym(t);
    const det = mags.map(s => {
      const ca = caDe(s, t), n1 = caDe(s, t - 12), avant = caDe(s, t - 13);
      // Comparable : un CA ce mois-ci, un CA le même mois un an plus tôt, et déjà un mois encaissé avant celui-là.
      return { nom: s.nom, ca, n1, comp: ca > 0 && n1 > 0 && avant !== null };
    });
    const somme = k => det.some(d => d[k] > 0) ? det.reduce((a, d) => a + (d[k] || 0), 0) : null;
    const n1Lu = connu(ym(t - 12).y);
    return { t, y, m, ca: somme('ca'), n1: n1Lu ? somme('n1') : undefined, det,
      compCa: det.filter(d => d.comp).reduce((a, d) => a + d.ca, 0),
      compN1: det.filter(d => d.comp).reduce((a, d) => a + d.n1, 0) };
  });

  const avecCa = pts.filter(p => p.ca != null);
  if (!avecCa.length) { return { vide: true, titre: 'Le CA du réseau — 12 mois glissants', motif: 'Aucun CA mensuel lu pour ces douze mois.' }; }
  const lecture = pts.some(p => p.n1 === undefined);
  const total = avecCa.reduce((a, p) => a + p.ca, 0);
  // L'évolution sur douze mois ne se donne que si les douze mois et leurs douze N-1 sont là.
  const complet = !lecture && pts.every(p => p.ca != null && p.n1 != null);
  const totalN1 = complet ? pts.reduce((a, p) => a + p.n1, 0) : null;
  const compCa = pts.reduce((a, p) => a + p.compCa, 0), compN1 = pts.reduce((a, p) => a + p.compN1, 0);

  // --- la géométrie : une échelle, des pas ronds, la ligne coupée là où un mois manque
  const vals = pts.flatMap(p => [p.ca, p.n1]).filter(v => v > 0);
  const vMin = Math.min(...vals), vMax = Math.max(...vals);
  const pas = pasRond((vMax - vMin || vMax) / 4);
  const lo = Math.max(0, Math.floor(vMin / pas) * pas - (vMin - Math.floor(vMin / pas) * pas < pas * 0.25 ? pas : 0));
  const hi = Math.ceil(vMax / pas) * pas + (Math.ceil(vMax / pas) * pas - vMax < pas * 0.25 ? pas : 0);
  const x = i => ML + i * (W - ML - MR) / 11;
  const yv = v => MT + (hi - v) / (hi - lo || 1) * (H - MT - MB);
  const segments = cle => {
    const out = []; let cur = [];
    pts.forEach((p, i) => { if (p[cle] > 0) { cur.push({ x: x(i).toFixed(1), y: yv(p[cle]).toFixed(1) }); } else if (cur.length) { out.push(cur); cur = []; } });
    if (cur.length) { out.push(cur); }
    return out.map(s => s.map(q => q.x + ',' + q.y).join(' '));
  };
  const grad = [];
  for (let v = lo; v <= hi + 0.5; v += pas) { grad.push({ y: yv(v).toFixed(1), t: kE(v) }); }

  // Les étiquettes directes : le dernier mois et le plus haut des douze, pas un chiffre par point.
  const iDernier = pts.map(p => p.ca != null).lastIndexOf(true);
  const iMax = pts.reduce((b, p, i) => (p.ca != null && (b < 0 || p.ca > pts[b].ca) ? i : b), -1);
  const moisCourt = (y, m) => new Date(y, m, 15).toLocaleDateString('fr-BE', { month: 'short' }).replace(/\.$/, '');
  const moisLong = (y, m) => new Date(y, m, 15).toLocaleDateString('fr-BE', { month: 'long', year: 'numeric' });
  const h = S.rgHover;
  const points = pts.map((p, i) => ({
    x: x(i).toFixed(1), y: p.ca != null ? yv(p.ca).toFixed(1) : null, yN1: p.n1 > 0 ? yv(p.n1).toFixed(1) : null,
    lab: moisCourt(p.y, p.m), an: (i === 0 || p.m === 0) ? String(p.y) : '',
    etiq: (i === iDernier || i === iMax) && p.ca != null ? kE(p.ca) : '',
    on: h === i,
    enter: () => { if (app.state.rgHover !== i) { app.setState({ rgHover: i }); } },
    hx: (x(i) - (W - ML - MR) / 22).toFixed(1), hw: ((W - ML - MR) / 11).toFixed(1),
  }));

  // --- la bulle du mois survolé : le réseau, son N-1, puis magasin par magasin
  const P = (h != null && pts[h]) ? pts[h] : null;
  const bulle = !P ? null : {
    gauche: (100 * x(h) / W).toFixed(2), cote: h <= 5 ? 'g' : 'd',
    titre: moisLong(P.y, P.m),
    ca: P.ca != null ? app.fE(P.ca) : '—',
    n1: P.n1 > 0 ? app.fE(P.n1) : (P.n1 === undefined ? 'lecture…' : '—'),
    ecart: (P.ca > 0 && P.n1 > 0) ? pct(P.ca / P.n1 - 1) : '',
    ecartCol: (P.ca > 0 && P.n1 > 0) ? (P.ca >= P.n1 ? 'var(--green)' : 'var(--stop)') : 'var(--color-text-muted)',
    mags: P.det.filter(d => d.ca > 0 || d.n1 > 0).sort((a, b) => (b.ca || 0) - (a.ca || 0)).map(d => ({
      nom: app.rrCourt ? app.rrCourt(d.nom) : d.nom, ca: d.ca > 0 ? kE(d.ca) : '—',
      ecart: d.ca > 0 && d.n1 > 0 ? pct(d.ca / d.n1 - 1) : (d.ca > 0 && !(d.n1 > 0) ? 'sans N-1' : ''),
      ecartCol: d.ca > 0 && d.n1 > 0 ? (d.ca >= d.n1 ? 'var(--green)' : 'var(--stop)') : 'var(--color-text-muted)' })),
  };

  const premier = ym(mois[0]), dernier = ym(mois[11]);
  const evo = totalN1 ? total / totalN1 - 1 : null;
  const evoComp = compN1 > 0 ? compCa / compN1 - 1 : null;
  return {
    titre: 'Le CA du réseau — 12 mois glissants',
    periode: moisCourt(premier.y, premier.m) + ' ' + premier.y + ' → ' + moisCourt(dernier.y, dernier.m) + ' ' + dernier.y
      + (r.enCours ? ' · mois clos, le mois en cours n’y entre pas' : ''),
    kpis: [
      { k: '12 mois glissants', v: app.fE(total), s: avecCa.length < 12 ? avecCa.length + ' mois avec du CA sur 12' : 'CA TTC encaissé, ' + mags.length + ' magasin(s)' },
      { k: 'Face aux 12 mois d’avant', v: evo == null ? '—' : pct(evo), col: evo == null ? '' : (evo >= 0 ? 'var(--green)' : 'var(--stop)'),
        s: lecture ? 'lecture de l’année d’avant…' : (totalN1 ? 'réseau entier, ouvertures comprises · ' + kE(totalN1) : (D.perfXEchec ? 'année d’avant illisible au panel' : 'historique incomplet')) },
      { k: 'À magasins comparables', v: evoComp == null ? '—' : pct(evoComp), col: evoComp == null ? '' : (evoComp >= 0 ? 'var(--green)' : 'var(--stop)'),
        s: lecture ? 'lecture de l’année d’avant…' : 'les magasins qui avaient un mois plein un an plus tôt' },
    ],
    svg: { W, H, ML, MR, MB, base: (H - MB).toFixed(1), grad, ca: segments('ca'), n1: segments('n1'), points },
    bulle,
    sortir: () => { if (app.state.rgHover != null) { app.setState({ rgHover: null }); } },
    avecN1: pts.some(p => p.n1 > 0),
  };
}
