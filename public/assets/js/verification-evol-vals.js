/* Cockpit CEO — Vérification › Évolution (#/verification/evolution), lot 3 (31/10/2026).
 * Plan : docs/plan-verification.md § 5 et lot 3 ; maquette docs/maquettes/verification/f.png.
 *
 * GET /verification/evolution?semaines= : la base quotidienne ceo_verif_jour, sommée semaine par semaine — magasin par
 * magasin et pour le réseau — et « avant / maintenant » (les premières semaines face aux dernières). Pour voir si la
 * gestion s'améliore : moins de poubelle, moins de non expliqué, plus de journées mesurées, plus de recettes fiables.
 *
 * Remplit `common.ve` ; gabarit : verification-evol-tpl.js ; style : verification.css (ve-). */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const pc = n => n == null ? '—' : nf(n, 1) + ' %';
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const jourDe = s => new Date(String(s).slice(0, 10) + 'T12:00:00');
const court = s => { const d = jourDe(s); return d.getDate() + ' ' + MOIS[d.getMonth()]; };
const jjmm = s => { const d = jourDe(s); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
const heure = s => { const m = /(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || '')); return m ? jjmm(m[1]) + ' à ' + m[2] + ':' + m[3] : '—'; };

/** Les couleurs des magasins, toujours dans le même ordre ; le réseau en noir pointillé. */
const COULEURS = ['#2F5D8A', '#8D1D2C', '#B26A00', '#5b3f86', '#0f766e', '#a0522d', '#4b5563', '#be185d'];
/** Les bandes des seuils (vert, orange) pour les indicateurs qui en ont. */
const BANDES = { nonExpl: 'nonExpl', poubelle: 'poubelle' };
/** Ce qui n'est qu'une estimation quand la semaine n'est pas mesurée (produit = plan) : le non expliqué et l'invendu. */
const ESTIMABLE = { nonExpl: true, invendu: true };
const LIB = {
  nonExpl: ['Non expliqué', 'ni vendu, ni jeté, ni gardé · % du disponible'],
  poubelle: ['Poubelle déclarée', 'déclarée en caisse · % du disponible'],
  invendu: ['Invendu gardé', 'gardé pour le lendemain · % du disponible'],
  mesure: ['Journées mesurées', 'part du disponible venue de cuissons validées'],
  recettes: ['Recettes fiables', 'part du CA dont la recette est complète et plausible'],
  matiere: ['Matière expliquée', 'part de la matière consommée (inventaire à inventaire) que ventes et poubelle expliquent'],
};
const ORDRE = ['nonExpl', 'poubelle', 'invendu', 'mesure', 'recettes', 'matiere'];

function charge(app, chemin, force){
  const b = app.state.veData;
  if (!force && b && b.cle === chemin && (b.d || b.chargement)) { return; }
  if (app._veData === chemin && !force) { return; }
  app._veData = chemin;
  app.setState({ veData: { cle: chemin, chargement: true, d: b && b.cle === chemin ? b.d : null } });
  readOne(chemin).then(d => {
    app._veData = null;
    if ((app.state.veData || {}).cle !== chemin) { return; }
    app.setState({ veData: { cle: chemin, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Une échelle « ronde » pour l'axe des valeurs. */
function echelle(max, min, ind){
  if (ind === 'mesure' || ind === 'recettes' || ind === 'matiere') { return { haut: 100, bas: 0, pas: 25 }; }
  const m = Math.max(max, ind === 'poubelle' ? 10 : (ind === 'nonExpl' ? 7 : 4));
  // Sous zéro (vendu plus que produit : cuissons non saisies), l'axe descend aussi.
  const etendue = m - Math.min(0, min);
  const pas = etendue <= 8 ? 2 : (etendue <= 20 ? 4 : (etendue <= 40 ? 10 : 20));
  return { haut: Math.ceil(m * 1.1 / pas) * pas, bas: min < 0 ? Math.floor(min * 1.1 / pas) * pas : 0, pas };
}

/** Le graphique : des polylignes (une par magasin, le réseau en pointillé), les bandes des seuils, les étiquettes de fin. */
function graphique(d, ind, shop, mags){
  const W = 1000, H = 250, G = 38, D = 150, Hh = 14, B = 26;
  const sem = d.semaines || [];
  const n = sem.length;
  const xs = i => G + (n <= 1 ? 0 : i * (W - G - D) / (n - 1));
  const series = mags.map((m, k) => ({ id: m.id, nom: m.nom, couleur: COULEURS[k % COULEURS.length], s: (m.series || {}) }))
    .concat([{ id: 'reseau', nom: 'Réseau', couleur: '#222', reseau: true, s: ((d.reseau || {}).series || {}) }]);
  let max = 0, min = 0;
  series.forEach(S => (S.s[ind] || []).forEach(v => { if (v != null && v > max) { max = v; } if (v != null && v < min) { min = v; } }));
  const E = echelle(max, min, ind);
  const ys = v => Hh + (H - Hh - B) * (1 - (Math.max(E.bas, Math.min(v, E.haut)) - E.bas) / (E.haut - E.bas));
  const grille = [];
  for (let v = E.bas; v <= E.haut + 1e-9; v += E.pas) { grille.push({ y: ys(v), txt: (v < 0 ? '−' + nf(-v) : nf(v)) + ' %', zero: v === 0 && E.bas < 0 }); }
  const seuil = (d.seuils || {})[BANDES[ind]];
  const bandes = seuil ? [{ y1: ys(Math.min(seuil[0], E.haut)), y2: ys(0), ton: 'ok' }, { y1: ys(Math.min(seuil[1], E.haut)), y2: ys(Math.min(seuil[0], E.haut)), ton: 'att' }] : [];
  const lignes = series.map(S => {
    const vals = S.s[ind] || [], est = S.s.estime || [], jours = S.s.jours || [];
    const pts = vals.map((v, i) => v == null ? null : { x: xs(i), y: ys(v), v, estime: !!(ESTIMABLE[ind] && est[i]),
      titre: S.nom + ' · ' + (sem[i] ? sem[i].num + ' (' + court(sem[i].du) + ' → ' + court(sem[i].au) + ')' : '') + ' : ' + pc(v) + ' · ' + (jours[i] || 0) + ' journée' + ((jours[i] || 0) > 1 ? 's' : '') + (ESTIMABLE[ind] && est[i] ? ' · estimé (plan)' : '') });
    // Les segments : plein entre deux semaines mesurées, pointillé dès qu'une des deux est estimée ; une semaine vide coupe le trait.
    const segs = [];
    for (let i = 1; i < pts.length; i++) {
      const a = pts[i - 1], b = pts[i];
      if (a && b) { segs.push({ x1: a.x, y1: a.y, x2: b.x, y2: b.y, estime: a.estime || b.estime }); }
    }
    let dernier = null;
    for (let i = pts.length - 1; i >= 0; i--) { if (pts[i]) { dernier = pts[i]; break; } }
    const pale = !!shop && S.id !== shop && !S.reseau;
    return { id: S.id, nom: S.nom, couleur: S.couleur, reseau: !!S.reseau, pale, fort: !!shop && S.id === shop, pts: pts.filter(Boolean), segs, dernier };
  }).filter(L => L.pts.length);
  // Les étiquettes de fin, sans chevauchement : triées par hauteur, poussées vers le bas d'au moins 13 px.
  const etiq = lignes.filter(L => L.dernier).map(L => ({ y: L.dernier.y, x: L.dernier.x, txt: L.nom.replace(/^Atelier by\s*(?:[^-]*-\s*)?/i, '') + ' ' + pc(L.dernier.v), couleur: L.couleur, pale: L.pale, reseau: L.reseau }))
    .sort((a, b) => a.y - b.y);
  for (let i = 1; i < etiq.length; i++) { if (etiq[i].y < etiq[i - 1].y + 13) { etiq[i].y = etiq[i - 1].y + 13; } }
  const debord = etiq.length ? etiq[etiq.length - 1].y - (H - B) : 0;
  if (debord > 0) { etiq.forEach(e => { e.y -= debord; }); for (let i = etiq.length - 2; i >= 0; i--) { if (etiq[i].y > etiq[i + 1].y - 13) { etiq[i].y = etiq[i + 1].y - 13; } } }
  const axeX = sem.map((s, i) => ({ x: xs(i), txt: s.num }));
  const estimeQuelquePart = !!ESTIMABLE[ind] && lignes.some(L => L.pts.some(p => p.estime));
  return { W, H, G, B, grille, bandes, lignes, etiq, axeX, estimeQuelquePart, vide: !lignes.length, xFin: W - D };
}

/** Une petite courbe (une ligne du tableau) : un chemin par morceau continu. */
function etincelle(vals){
  const W = 48, H = 22;
  const ok = (vals || []).filter(v => v != null);
  if (ok.length < 2) { return null; }
  let lo = Math.min(...ok), hi = Math.max(...ok);
  if (hi - lo < 1) { const m = (hi + lo) / 2; lo = m - 0.5; hi = m + 0.5; }
  const n = vals.length;
  const morceaux = []; let cour = [];
  vals.forEach((v, i) => {
    if (v == null) { if (cour.length) { morceaux.push(cour); } cour = []; return; }
    cour.push((n <= 1 ? 0 : (i * (W - 2) / (n - 1)) + 1).toFixed(1) + ',' + (H - 2 - (v - lo) / (hi - lo) * (H - 4)).toFixed(1));
  });
  if (cour.length) { morceaux.push(cour); }
  return { W, H, chemins: morceaux.filter(m => m.length > 1).map(m => 'M' + m.join('L')), points: morceaux.filter(m => m.length === 1).map(m => m[0].split(',')) };
}

/** Avant → maintenant d'un indicateur : les valeurs, l'écart en points, le sens et sa couleur. */
function compare(c, vals){
  if (!c) { return { vide: true, sp: etincelle(vals) }; }
  const fleche = c.sens === 'mieux' ? '▲' : (c.sens === 'moins bien' ? '▼' : '=');
  return { avant: pc(c.avant), apres: pc(c.apres), ecart: (c.ecart > 0 ? '+' : (c.ecart < 0 ? '−' : '')) + nf(Math.abs(c.ecart), 1) + ' pt',
    sens: c.sens, ton: c.sens === 'mieux' ? 'ok' : (c.sens === 'moins bien' ? 'ko' : 'mu'), fleche, semaines: c.semaines, sp: etincelle(vals) };
}

/** Ce que les semaines disent, en une phrase : qui s'améliore, qui se dégrade, sur quel indicateur. */
function synthese(mags, reseau, auPlan){
  const nomCourt = s => s.replace(/^Atelier by\s*(?:[^-]*-\s*)?/i, '');
  const mieux = [], pire = [];
  mags.forEach(m => {
    const cm = m.comparaison || {};
    ['poubelle', 'nonExpl', 'recettes'].forEach(k => {
      const c = cm[k];
      if (!c || c.sens === 'stable' || (ESTIMABLE[k] && auPlan(m))) { return; }
      const t = nomCourt(m.nom) + ' (' + LIB[k][0].toLowerCase() + ' ' + pc(c.avant) + ' → ' + pc(c.apres) + ')';
      (c.sens === 'mieux' ? mieux : pire).push({ t, nom: m.nom, poids: Math.abs(c.ecart) });
    });
  });
  // Le plus gros changement de chaque magasin, trois magasins au plus.
  const top = a => { const vus = {}; return a.sort((x, y) => y.poids - x.poids).filter(x => !vus[x.nom] && (vus[x.nom] = true)).slice(0, 3).map(x => x.t).join(', '); };
  const morceaux = [];
  if (mieux.length) { morceaux.push('s’améliorent : ' + top(mieux)); }
  if (pire.length) { morceaux.push('se dégradent : ' + top(pire)); }
  // Les magasins qui ne déclarent aucune poubelle : rien à comparer, et c'est à dire.
  const muets = mags.filter(m => { const p = ((m.series || {}).poubelle || []).filter(x => x != null); return p.length > 0 && p.every(x => x <= 0); }).map(m => nomCourt(m.nom));
  if (muets.length) { morceaux.push((muets.length > 1 ? muets.join(', ') + ' ne déclarent' : muets[0] + ' ne déclare') + ' aucune poubelle sur la période'); }
  const r = (reseau.comparaison || {}).poubelle;
  if (r) { morceaux.push('poubelle du réseau ' + pc(r.avant) + ' → ' + pc(r.apres) + ' (' + r.sens + ')'); }
  return morceaux.length ? morceaux.join(' ; ') + '.' : '';
}

export function valsVerifEvol(app, common){
  const S = app.state;
  const n = S.veSem || 12, shop = S.veShop || '', ind = S.veInd || 'nonExpl';
  const chemin = '/verification/evolution?semaines=' + n;
  charge(app, chemin, false);
  const b = S.veData || {};
  const d = b.cle === chemin && b.d && !b.d.error ? b.d : null;
  const mags = d ? (d.magasins || []) : (app._veMags || []);
  if (d) { app._veMags = mags.map(m => ({ id: m.id, nom: m.nom })); }
  const v = {
    chargement: !!b.chargement && !d, relecture: !!b.chargement && !!d,
    erreur: b.d && b.d.error && b.cle === chemin ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null,
    rafraichir: () => charge(app, chemin, true),
    periodes: [8, 12, 26].map(k => ({ id: k, nom: k + ' semaines', on: k === n, choisir: () => app.setState({ veSem: k }) })),
    magasins: [{ id: '', nom: 'Tout le réseau', on: !shop }].concat(mags.map(m => ({ id: m.id, nom: m.nom, on: m.id === shop }))),
    choisirShop: id => app.setState({ veShop: id }),
    indicateurs: ORDRE.map(k => ({ id: k, nom: LIB[k][0], on: k === ind })),
    choisirInd: k => app.setState({ veInd: k }),
    ind, nomInd: LIB[ind][0], sousInd: LIB[ind][1], shop,
  };
  common.ve = v;
  if (!d) { return; }
  const sem = d.semaines || [];
  v.periode = sem.length ? sem[0].num + ' → ' + sem[sem.length - 1].num + ' · ' + court(sem[0].du) + ' → ' + court(sem[sem.length - 1].au) + ' ' + jourDe(sem[sem.length - 1].au).getFullYear() : '';
  const B = d.base || {};
  const joursBase = B.premier && B.dernier ? Math.round((jourDe(B.dernier) - jourDe(B.premier)) / 864e5) + 1 : 0;
  v.base = { lignes: B.lignes || 0, magasins: B.magasins || 0, jours: joursBase, premier: B.premier ? jjmm(B.premier) + '/' + jourDe(B.premier).getFullYear() : '—',
    dernier: B.dernier ? jjmm(B.dernier) : '—', fluxLe: heure(B.fluxLe), recettesLe: heure(B.recettesLe), erreur: B.erreur || null, vide: !(B.lignes > 0) };
  // Les semaines où la base a quelque chose (pour le réseau) : il en faut deux pour une courbe, quatre pour comparer.
  const R = (d.reseau || {}).series || {};
  const semRenseignees = (R.jours || []).filter(j => j > 0).length + 0;
  const semRecettes = (R.recettes || []).filter(x => x != null).length;
  v.semRenseignees = Math.max(semRenseignees, semRecettes);
  // « Tout estimé » : aucune semaine d'aucun magasin n'est mesurée (sans cuissons validées, le produit est le plan).
  const est = mags.map(m => (m.series || {}).estime || []).flat();
  v.toutEstime = est.some(e => e === true) && !est.some(e => e === false);
  // Une ligne est « au plan » quand les dernières semaines comparées sont toutes estimées : pas de jugement sur son non
  // expliqué ni son invendu (comme à l'audit des flux).
  const auPlan = L => { const e = ((L.series || {}).estime || []).filter(x => x != null); const k = Math.max(1, Math.min(4, Math.floor(e.length / 2))); return e.length > 0 && e.slice(-k).every(x => x === true); };
  v.synthese = synthese(mags, d.reseau || {}, auPlan);
  v.g = graphique(d, ind, shop, mags);
  v.colonnes = ORDRE.map(k => ({ id: k, nom: LIB[k][0], on: k === ind, choisir: () => app.setState({ veInd: k }) }));
  const ligne = (id, nom, L, reseau) => ({ id, nom, reseau, fort: !!shop && id === shop, choisir: reseau ? () => app.setState({ veShop: '' }) : () => app.setState({ veShop: shop === id ? '' : id }),
    cases: ORDRE.map(k => { const est = !!ESTIMABLE[k] && auPlan(L); const c = compare((L.comparaison || {})[k], (L.series || {})[k]);
      // Estimé : l'écart au plan ne dit rien de la gestion — ni flèche ni couleur.
      if (est && !c.vide) { Object.assign(c, { ton: 'mu', fleche: '·', sens: 'écart au plan' }); }
      return Object.assign({ id: k, on: k === ind, estime: est }, c); }) });
  v.lignes = mags.map(m => ligne(m.id, m.nom, m, false)).concat([ligne('reseau', 'Réseau', d.reseau || {}, true)]);
  v.compareN = Math.max(0, ...v.lignes.flatMap(l => l.cases.map(c => c.semaines || 0)));
  v.seuils = d.seuils || {};
}
