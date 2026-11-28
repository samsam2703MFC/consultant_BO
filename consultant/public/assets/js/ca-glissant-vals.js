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
 *
 * Les catégories principales (27/11/2026, Sam : « le même tableau avec les catégories principales traiteur,
 * boulangerie, viennoiseries, quiches, tartes ») : une rangée de six cases au-dessus de la courbe — tout le CA, puis
 * chaque catégorie avec son CA des douze mois, sa part et son évolution à magasins comparables. Un clic sur une case
 * redessine la même section pour elle. Les catégories sont les GROUPES du panel, lus par GET /ventes/groupes-mois
 * (consultant/src/ventes_groupes.php, un relevé par magasin et par mois, gardé une fois le mois clos) ; la première
 * lecture se fait par passes, l'écran rappelle la route jusqu'à ce que tout soit lu.
 */
import { readOne } from './api.js';

const W = 1000, H = 214, ML = 62, MR = 18, MT = 24, MB = 38;

/** Les catégories principales, dans l'ordre demandé. `cle` = le nom du groupe du panel, sans accent ni pluriel. */
export const CA_CATEGORIES = [
  { cle: 'traiteur', nom: 'Traiteur' },
  { cle: 'boulangerie', nom: 'Boulangerie' },
  { cle: 'viennoiserie', nom: 'Viennoiseries' },
  { cle: 'quiche', nom: 'Quiches' },
  { cle: 'tarte', nom: 'Tartes' },
];
const norme = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim().replace(/s$/, '');

/** Un pas « rond » pour l'axe : 1, 2, 2,5 ou 5 × 10^n. */
function pasRond(brut){
  const p = Math.pow(10, Math.floor(Math.log10(brut || 1)));
  const r = brut / p;
  return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * p;
}
const kE = v => Math.round(v / 1000).toLocaleString('fr-BE') + ' k€';
const pct = v => (v == null || !isFinite(v)) ? '' : (v >= 0 ? '+' : '−') + Math.abs(v * 100).toFixed(1).replace('.', ',') + ' %';
const coulEvo = v => v == null ? '' : (v >= 0 ? 'var(--green)' : 'var(--stop)');
const aaaamm = t => Math.floor(t / 12) + '-' + String(((t % 12) + 12) % 12 + 1).padStart(2, '0');

/**
 * Le CA par groupe, mois par mois, pour la fenêtre [du, au] (AAAA-MM) : lu une fois, gardé dans `D.vgm`. Tant que la
 * route dit qu'il reste des relevés, on la rappelle ; si une passe n'avance plus (le panel se tait), on s'arrête et
 * l'écran montre ce qui est lu.
 */
function groupes(app, du, au){
  const D = app.D, cle = du + '|' + au;
  D.vgm = D.vgm || {};
  if (D.vgm[cle]) { return D.vgm[cle]; }
  const e = D.vgm[cle] = { etat: 'lecture', ca: {}, illisibles: [], lus: 0, total: 0, reste: null };
  const lire = () => readOne('/ventes/groupes-mois?du=' + du + '&au=' + au).then(p => {
    if (!p || p.error || p.indispo) {
      e.etat = 'erreur'; e.motif = (p && (p.motif || p.error)) || 'les ventes par catégorie n’ont pas pu être lues au panel';
      app.setState({}); return;
    }
    const n = (p.mois || []).length * (p.magasins || []).length;
    const avance = e.reste == null || p.reste < e.reste;
    e.ca = p.ca || {}; e.illisibles = p.illisibles || []; e.total = n;
    e.lus = Math.max(0, n - (p.reste || 0) - e.illisibles.length); e.reste = p.reste || 0;
    if (e.reste > 0 && avance) { app.setState({}); lire(); return; }
    e.etat = 'ok'; app.setState({});
  });
  lire();
  return e;
}

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
  const caTotal = (s, t) => {
    const { y, m } = ym(t);
    const c = s.perf && s.perf[y] && s.perf[y][m];
    if (c) { return c.ca > 0 ? c.ca : null; }
    if (!connu(y)) { return undefined; }
    const l = (X[y] || []).find(l2 => String(l2.storeId) === String(s.id) && +l2.mois === m + 1);
    return l && l.ca > 0 ? l.ca : null;
  };

  // --- les catégories : le CA d'un magasin pour un mois, dans un groupe (ou tous les groupes, pour la part)
  const vg = groupes(app, aaaamm(mois[0] - 12), aaaamm(mois[11]));
  const accGroupe = cle => (s, t) => {
    const k = aaaamm(t);
    if (vg.illisibles.includes(String(s.id) + ':' + k)) { return undefined; }
    const g = (vg.ca[String(s.id)] || {})[k];
    if (!g) { return null; }
    let v = 0;
    for (const nom in g) { if (cle === '*' || norme(nom) === cle) { v += +g[nom] || 0; } }
    return v > 0 ? v : null;
  };

  const mags = app.open();
  // Une série : douze points (le mois, son N-1, magasin par magasin) et ses totaux.
  const serie = acc => {
    const pts = mois.map(t => {
      const { y, m } = ym(t);
      const det = mags.map(s => {
        const ca = acc(s, t), n1 = acc(s, t - 12), avant = caTotal(s, t - 13);
        // Comparable : un CA ce mois-ci, un CA le même mois un an plus tôt, et le magasin avait déjà un mois encaissé
        // avant celui-là (le CA total du magasin : c'est son ancienneté qu'on teste, pas celle de la catégorie).
        return { nom: s.nom, ca, n1, comp: ca > 0 && n1 > 0 && avant !== null };
      });
      const somme = k => det.some(d => d[k] > 0) ? det.reduce((a, d) => a + (d[k] || 0), 0) : null;
      return { t, y, m, ca: somme('ca'), n1: det.some(d => d.n1 === undefined) ? undefined : somme('n1'), det,
        compCa: det.filter(d => d.comp).reduce((a, d) => a + d.ca, 0),
        compN1: det.filter(d => d.comp).reduce((a, d) => a + d.n1, 0) };
    });
    const avecCa = pts.filter(p => p.ca != null);
    const lecture = pts.some(p => p.n1 === undefined);
    const total = avecCa.reduce((a, p) => a + p.ca, 0);
    // L'évolution sur douze mois ne se donne que si les douze mois et leurs douze N-1 sont là.
    const complet = !lecture && pts.every(p => p.ca != null && p.n1 != null);
    const totalN1 = complet ? pts.reduce((a, p) => a + p.n1, 0) : null;
    const compCa = pts.reduce((a, p) => a + p.compCa, 0), compN1 = pts.reduce((a, p) => a + p.compN1, 0);
    return { pts, avecCa, lecture, total, totalN1, evo: totalN1 ? total / totalN1 - 1 : null,
      evoComp: compN1 > 0 ? compCa / compN1 - 1 : null };
  };

  // --- la rangée des cases : tout le CA, puis les catégories principales
  const SEL = CA_CATEGORIES.some(c => c.cle === S.rgCat) ? S.rgCat : 'tout';
  const series = { tout: serie(caTotal) };
  const vgOk = vg.etat === 'ok';
  const totGroupes = vgOk ? serie(accGroupe('*')).total : 0;
  if (vgOk) { CA_CATEGORIES.forEach(c => { series[c.cle] = serie(accGroupe(c.cle)); }); }
  const part = sr => (vgOk && totGroupes > 0) ? Math.round(100 * sr.total / totGroupes) + ' % du CA' : '';
  const choisir = cle => () => { if (app.state.rgCat !== cle) { app.setState({ rgCat: cle === 'tout' ? null : cle, rgHover: null }); } };
  const lectureTxt = vg.total ? 'lecture ' + vg.lus + ' / ' + vg.total + '…' : 'lecture…';
  const cases = [{ cle: 'tout', nom: 'Tout le CA' }].concat(CA_CATEGORIES).map(c => {
    const sr = series[c.cle];
    if (!sr) {
      return { nom: c.nom, v: '…', evo: '', s: vg.etat === 'erreur' ? 'illisible au panel' : lectureTxt, on: SEL === c.cle, go: choisir(c.cle), titre: vg.motif || '' };
    }
    return { nom: c.nom, v: sr.avecCa.length ? kE(sr.total) : '—', evo: sr.evoComp == null ? '' : pct(sr.evoComp), evoCol: coulEvo(sr.evoComp),
      s: c.cle === 'tout' ? mags.length + ' magasin(s)' : part(sr), on: SEL === c.cle, go: choisir(c.cle),
      titre: c.nom + ' : CA des 12 mois' + (sr.evoComp == null ? '' : ', ' + pct(sr.evoComp) + ' à magasins comparables') };
  });

  const cat = CA_CATEGORIES.find(c => c.cle === SEL) || null;
  const titre = cat ? cat.nom + ' — le CA du réseau, 12 mois glissants' : 'Le CA du réseau — 12 mois glissants';
  const premier = ym(mois[0]), dernier = ym(mois[11]);
  const moisCourt = (y, m) => new Date(y, m, 15).toLocaleDateString('fr-BE', { month: 'short' }).replace(/\.$/, '');
  const moisLong = (y, m) => new Date(y, m, 15).toLocaleDateString('fr-BE', { month: 'long', year: 'numeric' });
  const periode = moisCourt(premier.y, premier.m) + ' ' + premier.y + ' → ' + moisCourt(dernier.y, dernier.m) + ' ' + dernier.y
    + (r.enCours ? ' · mois clos, le mois en cours n’y entre pas' : '');
  const base = { titre, periode, cases, serieNom: cat ? cat.nom : 'CA du réseau',
    casesNote: 'CA des 12 mois · en couleur, l’évolution à magasins comparables face aux 12 mois d’avant · cliquer une case pour sa courbe' };

  // Une catégorie choisie dont les ventes ne sont pas encore lues : la section le dit, la rangée reste cliquable.
  const sr = series[SEL];
  if (!sr) {
    return Object.assign(base, { attente: vg.etat === 'erreur' ? vg.motif
      : 'Lecture des ventes par catégorie au panel — une fois, puis gardé' + (vg.total ? ' : ' + vg.lus + ' relevés sur ' + vg.total : '') + '…',
      enCours: vg.etat !== 'erreur' });
  }
  const { pts, avecCa, lecture, total, totalN1, evo, evoComp } = sr;
  if (!avecCa.length) { return Object.assign(base, { attente: 'Aucun CA mensuel lu pour ces douze mois.' }); }

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
  // L'axe garde la décimale de son pas : 22,5 k€, pas « 23 k€ » (un pas de 2,5 k€ arrondi au millier mentait).
  const kAxe = v => (v / 1000).toLocaleString('fr-BE', { maximumFractionDigits: pas % 1000 ? 1 : 0 }) + ' k€';
  for (let v = lo; v <= hi + 0.5; v += pas) { grad.push({ y: yv(v).toFixed(1), t: kAxe(v) }); }

  // Les étiquettes directes : le dernier mois et le plus haut des douze, pas un chiffre par point.
  const iDernier = pts.map(p => p.ca != null).lastIndexOf(true);
  const iMax = pts.reduce((b, p, i) => (p.ca != null && (b < 0 || p.ca > pts[b].ca) ? i : b), -1);
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
    titre: moisLong(P.y, P.m) + (cat ? ' · ' + cat.nom : ''),
    ca: P.ca != null ? app.fE(P.ca) : '—',
    n1: P.n1 > 0 ? app.fE(P.n1) : (P.n1 === undefined ? 'lecture…' : '—'),
    ecart: (P.ca > 0 && P.n1 > 0) ? pct(P.ca / P.n1 - 1) : '',
    ecartCol: (P.ca > 0 && P.n1 > 0) ? coulEvo(P.ca / P.n1 - 1) : 'var(--color-text-muted)',
    mags: P.det.filter(d => d.ca > 0 || d.n1 > 0).sort((a, b) => (b.ca || 0) - (a.ca || 0)).map(d => ({
      nom: app.rrCourt ? app.rrCourt(d.nom) : d.nom, ca: d.ca > 0 ? kE(d.ca) : '—',
      ecart: d.ca > 0 && d.n1 > 0 ? pct(d.ca / d.n1 - 1) : (d.ca > 0 && !(d.n1 > 0) ? 'sans N-1' : ''),
      ecartCol: d.ca > 0 && d.n1 > 0 ? coulEvo(d.ca / d.n1 - 1) : 'var(--color-text-muted)' })),
  };

  const nIll = cat ? vg.illisibles.length : 0;
  return Object.assign(base, {
    kpis: [
      { k: '12 mois glissants', v: app.fE(total),
        s: avecCa.length < 12 ? avecCa.length + ' mois avec du CA sur 12'
          : (cat ? part(sr) + ' des 12 mois, ventes TTC par catégorie' + (nIll ? ' · ' + nIll + ' relevé(s) illisible(s)' : '') : 'CA TTC encaissé, ' + mags.length + ' magasin(s)') },
      { k: 'Face aux 12 mois d’avant', v: evo == null ? '—' : pct(evo), col: coulEvo(evo),
        s: lecture ? (cat ? 'relevés manquants au panel' : 'lecture de l’année d’avant…')
          : (totalN1 ? 'réseau entier, ouvertures comprises · ' + kE(totalN1) : (D.perfXEchec && !cat ? 'année d’avant illisible au panel' : 'historique incomplet')) },
      { k: 'À magasins comparables', v: evoComp == null ? '—' : pct(evoComp), col: coulEvo(evoComp),
        s: lecture && !cat ? 'lecture de l’année d’avant…' : 'les magasins qui avaient un mois plein un an plus tôt' },
    ],
    svg: { W, H, ML, MR, MB, base: (H - MB).toFixed(1), grad, ca: segments('ca'), n1: segments('n1'), points },
    bulle,
    sortir: () => { if (app.state.rgHover != null) { app.setState({ rgHover: null }); } },
    avecN1: pts.some(p => p.n1 > 0),
  });
}
