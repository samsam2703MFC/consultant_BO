/* Cockpit CEO — Résultat › Année › 1 an : les clients mois par mois, l'exercice face à l'an passé (24/10/2026,
 * maquette A de docs/maquettes/clients-annee-n1). Le levier trafic sur l'année complète — et, d'un bouton, le
 * panier moyen (CA ÷ clients) ou les articles par ticket (pièces ÷ clients) face au même mois de l'an passé.
 *
 * Une lecture : GET /ventes/reseau?vue=annee&date=… — chaque magasin, douze mois, chacun face à son N-1 arrêté au
 * même jour servi (`n1`) et au mois civil N-1 entier (`n1Mois`). La courbe de l'an passé trace `n1Mois` (le mois en
 * cours entier, et les mois à venir : ce sont les mois à battre) ; l'écart se calcule toujours sur `n1`.
 *
 *  - le mois en cours se prolonge au rythme de ses jours lus (pointillé, point creux) : un mois entamé contre un
 *    mois entier ressemblerait à une chute ;
 *  - un mois d'ouverture (Halle : 7 jours en février 2026) n'est pas relié : partiel, il ferait croire à une chute ;
 *  - le réseau compte tous les magasins, ou ceux qui ont un N-1 (« périmètre constant ») : les ouvertures ne sont
 *    pas du trafic gagné ;
 *  - le panier (les articles) du réseau est le CA (les pièces) total ÷ les clients totaux : chaque magasin pesé par
 *    ses clients ; le mois en cours se lit à date, sans prolongement — un rapport ne se projette pas ;
 *  - les pièces viennent du panel (sales-kpis, `articles=1`) : lues au premier clic sur « Articles / ticket ».
 *
 * Remplit `common.clAn` ; le gabarit est tplClientsAnnee (clients-annee-tpl.js). */
import { readOne } from './api.js';

const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const MOISL = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const jm = (y, m) => new Date(y, m, 0).getDate();
const nf = n => n == null ? '—' : Math.round(n).toLocaleString('fr-BE').replace(/\u202F/g, '\u00A0');
const sg = n => (n < 0 ? '−\u00A0' : '+\u00A0') + nf(Math.abs(n));
// Le signe suit la valeur ARRONDIE : −0,04 % s'écrit « 0,0 % », pas « −0,0 % ».
const pc = (v, d) => { if (v == null) { return '—'; } const k = d == null ? 1 : d, r = Math.round(v * Math.pow(10, k)) / Math.pow(10, k);
  return (r < 0 ? '−' : (r > 0 ? '+' : '')) + Math.abs(r).toLocaleString('fr-BE', { minimumFractionDigits: k, maximumFractionDigits: k }) + '\u00A0%'; };
const fE2 = v => v == null ? '—' : v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/\u202F/g, '\u00A0') + '\u00A0€';
const sgE = v => (Math.round(v * 100) < 0 ? '−\u00A0' : '+\u00A0') + fE2(Math.abs(v));
const f2 = v => v == null ? '—' : v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sg2 = v => (Math.round(v * 100) < 0 ? '−\u00A0' : '+\u00A0') + f2(Math.abs(v));
// Les seuils des barres jumelles de Trafic clients (rrBarres) : vert dès +3 %, rouge dès −3 %, ocre entre les deux.
const teinte = v => v == null ? { bg: '#F1EEEA', tx: '#8a837a' } : v >= 3 ? { bg: '#E6F2E8', tx: '#2d7a3e' } : v <= -3 ? { bg: '#FBE5E7', tx: '#C0182B' } : { bg: '#F6EFD9', tx: '#8a6508' };

export function clAnCharge(app, an, force, articles){
  const D = app.D, cle = an + (articles ? ':ar' : '');
  if (!D.clAn) { D.clAn = {}; }
  if (!app._clAnEnCours) { app._clAnEnCours = {}; }
  if (app._clAnEnCours[cle] || (D.clAn[cle] && !force)) { return; }
  app._clAnEnCours[cle] = true;
  // Le 31/12 de l'exercice : le serveur le ramène à aujourd'hui pour l'année en cours. Les pièces (`articles=1`)
  // coûtent une lecture du panel par mois : elles ne se lisent qu'à la demande.
  readOne('/ventes/reseau?vue=annee&date=' + an + '-12-31' + (articles ? '&articles=1' : '') + '&_cache=600' + (force ? '&rafraichir=1' : '')).then(d => {
    D.clAn[cle] = d && Array.isArray(d.periodes) && Array.isArray(d.magasins) ? d : { erreur: (d && d.error) || 'lecture impossible' };
  }).catch(e => { D.clAn[cle] = { erreur: (e && e.message) || 'lecture impossible' }; })
    .finally(() => { app._clAnEnCours[cle] = false; app.setState({}); });
}

/** Les mois d'un magasin, préparés : N, N-1 à date, N-1 entier, jours, ouverture. */
function moisMagasin(mg, P, an){
  // Un mois d'ouverture : le premier mois vendu de la série, pas un mois plein, et rien de vendu avant dans l'année.
  // En janvier on ne peut pas trancher entre une ouverture et une fermeture : seul un magasin sans aucune vente l'an
  // passé (Halle) y est dit « en ouverture » ; une fermeture d'un magasin installé reste un mois comparé.
  const sansN1 = !mg.periodes.some(x => x.n1 || x.n1Mois);
  const ouv = (cle, y, janvier) => { const i = mg.periodes.findIndex(x => cle(x) != null); return i >= 0 && (i > 0 || janvier) && (cle(mg.periodes[i]) || {}).jours < jm(y, i + 1) - 2 ? i : -1; };
  // `n1` à défaut de `n1Mois` (réponse d'avant le 24/10/2026) : pour un mois clos, c'est le même chiffre.
  const iOuv = ouv(x => x.source ? x : null, an, sansN1), iOuv1 = ouv(x => x.n1Mois || x.n1, an - 1, false);
  return mg.periodes.map((x, i) => {
    const p = P[i], etat = p.futur ? 'futur' : (p.enCours ? 'encours' : 'clos');
    const n = !p.futur && x.source ? x.tickets : null;
    const m1 = x.n1Mois || (etat === 'clos' ? x.n1 : null);
    // pièces : null = pas lues — le rapport ne compte alors pas ce magasin ; un zéro face à des clients n'est pas une lecture
    const pc0 = v => v == null || !(+v > 0) ? null : +v;
    return { i, etat, n, nJ: x.jours || 0, nCal: p.joursServis || jm(an, i + 1), nCa: n != null ? +x.ca || 0 : null, nAr: n != null ? pc0(x.articles) : null,
      n1c: x.n1 ? x.n1.tickets : null, n1cJ: x.n1 ? x.n1.jours : 0, n1cCa: x.n1 ? +x.n1.ca || 0 : null, n1cAr: x.n1 ? pc0(x.n1.articles) : null,
      n1: m1 ? m1.tickets : null, n1J: m1 ? m1.jours : 0, n1Ca: m1 ? +m1.ca || 0 : null, n1Ar: m1 ? pc0(m1.articles) : null,
      ouv: i === iOuv, ouv1: i === iOuv1 };
  });
}

/** Une série à tracer : douze points { n, proj, creux, n1, ecart, notes, bulle } — clients, panier moyen ou articles
 *  par ticket. Les deux rapports se calculent sur les magasins dont le numérateur (CA, pièces) est lu. */
function serie(mags, P, an, perim, reseau, mesure){
  const ratio = mesure !== 'clients', suf = mesure === 'panier' ? 'Ca' : 'Ar';
  const jl = (P.find(p => p.enCours) || {}).joursServis || null;
  const nbMag = k => k + ' magasin' + (k > 1 ? 's' : '');
  const parJ = (t, j) => j ? ' · ' + nf(t / j) + ' / jour' : '';
  const rap = (a, b) => b > 0 ? a / b : null;
  const fR = v => mesure === 'panier' ? fE2(v) : f2(v) + ' articles';
  const detail = (t, num) => nf(t) + ' clients · ' + nf(num) + (mesure === 'panier' ? ' € de CA' : ' pièces');
  return P.map((p, i) => {
    const etat = p.futur ? 'futur' : (p.enCours ? 'encours' : 'clos');
    const comparable = x => x.n != null && x.n1c != null && !x.ouv && !x.ouv1;
    const pris = mags.filter(m => perim !== 'comp' || (etat === 'futur' ? m.mois[i].n1 != null : comparable(m.mois[i])));
    // brut (la bulle), tracé (la courbe : un mois d'ouverture d'un magasin n'est pas relié), écart (à date)
    let nR = 0, nRJ = 0, nbR = 0, n1R = 0, n1RJ = 0, nb1R = 0;
    let n = 0, nP = 0, nb = 0, n1 = 0, nb1 = 0, n1c = 0, nbc = 0, ouv = false;
    // les rapports : numérateur et clients des seuls magasins dont le numérateur est lu
    let rRN = 0, rRD = 0, r1RN = 0, r1RD = 0, rN = 0, rD = 0, r1N = 0, r1D = 0, rcN = 0, rcD = 0;
    pris.forEach(m => {
      const x = m.mois[i], v = x['n' + suf], v1 = x['n1' + suf], vc = x['n1c' + suf];
      if (x.ouv || x.ouv1) { ouv = true; }
      if (x.n != null) { nR += x.n; nRJ += x.nJ; nbR++; if (v != null) { rRN += v; rRD += x.n; } }
      if (x.n1c != null) { n1R += x.n1c; n1RJ += x.n1cJ; nb1R++; if (vc != null) { r1RN += vc; r1RD += x.n1c; } }
      if (x.n != null && (reseau || !x.ouv)) { n += x.n; nb++; nP += etat === 'encours' ? x.n / Math.max(1, x.nCal) * jm(an, i + 1) : x.n; if (v != null) { rN += v; rD += x.n; } }
      if (x.n1 != null && (reseau || !x.ouv1)) { n1 += x.n1; nb1++; if (v1 != null) { r1N += v1; r1D += x.n1; } }
      if (reseau && perim !== 'comp' ? x.n1c != null : comparable(x)) { n1c += x.n1c; nbc++; if (vc != null) { rcN += vc; rcD += x.n1c; } }
    });
    const pt = { i, lab: MOIS[i], etat, cur: etat === 'encours', notes: [] };
    // Clients : le mois en cours prolongé au rythme des jours lus. Rapports : lus tels quels, à date (point creux).
    pt.n = !nb ? null : (ratio ? rap(rN, rD) : Math.round(nP));
    pt.proj = !ratio && etat === 'encours' && nb > 0;
    pt.creux = etat === 'encours' && pt.n != null;
    pt.n1 = !nb1 ? null : (ratio ? rap(r1N, r1D) : n1);
    pt.nonComp = !reseau && ouv && etat !== 'futur';
    const ok = etat !== 'futur' && nb && nbc && n1c > 0 && !pt.nonComp;
    pt.ecart = !ok ? null : (ratio ? (rD > 0 && rcD > 0 && rcN > 0 ? 100 * (rap(rN, rD) / rap(rcN, rcD) - 1) : null) : 100 * (n / n1c - 1));
    if (!ratio && !reseau && etat === 'clos' && pt.ecart != null) {
      const x = mags[0].mois[i];
      if (Math.abs(x.nJ - x.n1cJ) >= 3) { pt.notes.push(x.n1cJ < x.nJ ? (an - 1) + ' : ' + (jm(an - 1, i + 1) - x.n1cJ) + ' j fermés' : an + ' : ' + (jm(an, i + 1) - x.nJ) + ' j fermés'); }
    }
    // La bulle : des lignes { k, v, s }, échappées par le gabarit.
    const L = [];
    const qui = k => reseau ? nbMag(k) + ' · ' : '';
    if (etat === 'futur') {
      L.push(pt.n1 == null ? { s: 'pas de ' + (an - 1) }
        : ratio ? { k: String(an - 1), v: fR(pt.n1), s: qui(nb1) + detail(r1D, r1N) + ' · le mois à battre' }
        : { k: String(an - 1), v: nf(n1) + ' clients', s: qui(nb1) + 'le mois à battre' });
    } else if (ratio) {
      if (!nbR) { L.push({ s: an + ' : pas de vente lue' }); }
      else if (!rRD) { L.push({ s: an + ' : pièces pas encore lues au panel' }); }
      else { L.push({ k: String(an), v: fR(rap(rRN, rRD)), s: (etat === 'encours' ? 'en ' + jl + ' j · ' : '') + qui(nbR) + detail(rRD, rRN) }); }
      if (!nb1R) { L.push({ s: (an - 1) + ' : pas encore ouvert' }); }
      else if (r1RD) { L.push({ k: (an - 1) + (etat === 'encours' ? ' au ' + jl + '/' + String(i + 1).padStart(2, '0') : ''), v: fR(rap(r1RN, r1RD)),
        s: qui(nb1R) + (etat === 'encours' && pt.n1 != null ? nf(r1RD) + ' clients · mois entier ' + fR(pt.n1) : detail(r1RD, r1RN)) }); }
      if (pt.nonComp) { L.push({ s: 'mois d’ouverture : pas d’écart' }); }
      if (pt.ecart != null) { const d = rap(rN, rD) - rap(rcN, rcD);
        pt.ecartTxt = { v: (mesure === 'panier' ? sgE(d) : sg2(d) + ' article') + ' · ' + pc(pt.ecart), up: d >= 0, a: etat === 'encours' ? 'à date' : '', s: '' }; }
    } else {
      if (!nbR) { L.push({ s: an + ' : pas de vente lue' }); }
      else if (etat === 'encours') {
        L.push({ k: String(an), v: nf(nR) + ' clients', s: 'en ' + jl + ' j' + (reseau ? ' · ' + nbMag(nbR) : '') + parJ(nR, reseau ? jl : nRJ) });
        L.push({ s: 'environ ' + nf(nP) + ' au rythme du mois entier' });
      } else { L.push({ k: String(an), v: nf(nR) + ' clients', s: (reseau ? nbMag(nbR) : nRJ + ' j ouverts') + parJ(nR, reseau ? jm(an, i + 1) : nRJ) }); }
      if (!nb1R) { L.push({ s: (an - 1) + ' : pas encore ouvert' }); }
      else if (etat === 'encours') { L.push({ k: (an - 1) + ' au ' + jl + '/' + String(i + 1).padStart(2, '0'), v: nf(n1R) + ' clients', s: qui(nb1R) + (pt.n1 != null ? 'mois entier ' + nf(pt.n1) : '') }); }
      else { L.push({ k: String(an - 1), v: nf(n1R) + ' clients', s: (reseau ? nbMag(nb1R) : n1RJ + ' j ouverts') + parJ(n1R, reseau ? jm(an - 1, i + 1) : n1RJ) }); }
      if (pt.nonComp) { L.push({ s: 'mois d’ouverture : pas d’écart' }); }
      if (pt.ecart != null) {
        const x = !reseau ? mags[0].mois[i] : null;
        const ePj = x && x.nJ && x.n1cJ ? 100 * ((x.n / x.nJ) / (x.n1c / x.n1cJ) - 1) : null;
        pt.ecartTxt = { v: sg(n - n1c) + ' clients · ' + pc(pt.ecart), up: n >= n1c, a: etat === 'encours' ? 'à date' : '',
          s: ePj != null && Math.abs(ePj - pt.ecart) >= 1 ? 'par jour ouvert : ' + pc(ePj) : '' };
      }
    }
    if (etat !== 'futur' && reseau && perim !== 'comp' && nb !== nbc && nbc > 0) { L.push({ s: nbMag(nb) + ' en ' + an + ' contre ' + nbc + ' en ' + (an - 1) + ' : l’écart compte les ouvertures' }); }
    pt.bulle = { titre: MOISL[i] + (reseau ? ' · réseau' + (perim === 'comp' ? ' à périmètre constant' : '') : ''), lignes: L, ecart: pt.ecartTxt || null };
    return pt;
  });
}

/** L'année à date, sur les mois comparables (ou tout le réseau, ouvertures comprises) : clients, panier, articles. */
function cumul(mags, P, perim, reseau){
  let n = 0, n1 = 0, tout = 0; const mois = new Set();
  const R = { Ca: [0, 0, 0, 0, 0, 0], Ar: [0, 0, 0, 0, 0, 0] };   // [num N, clients N, num N-1, clients N-1, num tout, clients tout]
  const ajoute = (x, n1Aussi) => ['Ca', 'Ar'].forEach(k => { const r = R[k];
    if (x['n' + k] != null) { r[0] += x['n' + k]; r[1] += x.n; }
    if (n1Aussi && x['n1c' + k] != null) { r[2] += x['n1c' + k]; r[3] += x.n1c; } });
  mags.forEach(m => m.mois.forEach((x, i) => {
    if (P[i].futur || x.n == null) { return; }
    tout += x.n;
    ['Ca', 'Ar'].forEach(k => { if (x['n' + k] != null) { R[k][4] += x['n' + k]; R[k][5] += x.n; } });
    const comp = x.n1c != null && !x.ouv && !x.ouv1;
    if (reseau && perim !== 'comp') { n += x.n; if (x.n1c != null) { n1 += x.n1c; } ajoute(x, x.n1c != null); mois.add(i); return; }
    if (comp) { n += x.n; n1 += x.n1c; ajoute(x, true); mois.add(i); }
  }));
  const M = [...mois].sort((a, b) => a - b), nMois = P.filter(p => !p.futur).length, ec = P.find(p => p.enCours);
  const fin = ec ? ' (au ' + ec.joursServis + '/' + String(ec.iso).padStart(2, '0') + ')' : '';
  const rap = (a, b) => b > 0 ? a / b : null;
  const out = { n, n1, tout, ecart: n1 > 0 ? 100 * (n / n1 - 1) : null,
    plage: !M.length ? '' : (M.length === nMois ? 'janv. → ' + MOIS[nMois - 1] + fin : MOIS[M[0]] + ' → ' + MOIS[M[M.length - 1]] + (M[M.length - 1] === nMois - 1 ? fin : '')) };
  // pa (panier) et ar (articles) : la valeur, l'an passé, tout l'exercice, l'écart.
  [['pa', 'Ca'], ['ar', 'Ar']].forEach(([c, k]) => { const r = R[k], v = rap(r[0], r[1]), v1 = rap(r[2], r[3]);
    out[c] = v; out[c + '1'] = v1; out[c + 'Tout'] = rap(r[4], r[5]); out['ecart' + c.replace(/^./, x => x.toUpperCase())] = v != null && v1 ? 100 * (v / v1 - 1) : null; });
  return out;
}

/** La géométrie du graphique, en repère 1000 × H : des primitives SVG que le gabarit écrit telles quelles.
 *  `fv` écrit une valeur sur un point, `ft` une graduation (clients : entiers ; panier : euros à deux décimales). */
function geometrie(Pt, fv, ft){
  const W = 1000, notes = Pt.some(p => p.notes.length), H = notes ? 300 : 286;
  const ml = 48, mr = 10, mt = 24, cb = H - (notes ? 74 : 60);
  const el = [];
  const vals = []; Pt.forEach(p => { if (p.n != null) { vals.push(p.n); } if (p.n1 != null) { vals.push(p.n1); } });
  if (!vals.length) { return null; }
  const pas = v => { const q = Math.pow(10, Math.floor(Math.log10(v))); const r = v / q; return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * q; };
  let lo = Math.min(...vals), hi = Math.max(...vals);
  const st = pas((hi - lo || hi || 1) / 3);
  lo = Math.max(0, Math.floor((lo - st * 0.6) / st) * st); hi = Math.ceil((hi + st * 0.35) / st) * st;
  const cw = (W - ml - mr) / 12, X = i => ml + (i + 0.5) * cw, Y = v => mt + (hi - v) / (hi - lo || 1) * (cb - mt);
  const f = v => +v.toFixed(1);
  for (let v = lo; v <= hi + 1e-6; v += st) {
    el.push(['line', { x1: ml, x2: W - mr, y1: f(Y(v)), y2: f(Y(v)), stroke: v === lo ? 'rgba(34,34,34,.16)' : 'rgba(34,34,34,.07)' }]);
    el.push(['text', { x: ml - 6, y: f(Y(v) + 3.5), 'text-anchor': 'end', 'font-size': 10, fill: '#9a948c' }, ft(v)]);
  }
  const ie = Pt.findIndex(p => p.cur);
  if (ie >= 0) { el.push(['rect', { x: f(X(ie) - cw / 2), y: mt - 8, width: f(cw), height: f(cb - mt + 8), fill: '#8D1D2C', 'fill-opacity': '.045', rx: 4 }]); }
  // La bande entre les deux années, coupée là où les courbes se croisent.
  const bande = (pts, up) => el.push(['polygon', { points: pts.map(q => f(q[0]) + ',' + f(q[1])).join(' '), fill: up ? '#2E6DB4' : '#C0632B', 'fill-opacity': '.14' }]);
  for (let i = 0; i < 11; i++) {
    const a = Pt[i], b = Pt[i + 1];
    if (a.n == null || a.n1 == null || b.n == null || b.n1 == null || a.proj || b.proj) { continue; }
    const x1 = X(i), x2 = X(i + 1), d1 = a.n - a.n1, d2 = b.n - b.n1;
    if (d1 * d2 >= 0) { bande([[x1, Y(a.n)], [x2, Y(b.n)], [x2, Y(b.n1)], [x1, Y(a.n1)]], d1 + d2 >= 0); }
    else {
      const t = d1 / (d1 - d2), xc = x1 + t * (x2 - x1), yc = Y(a.n + t * (b.n - a.n));
      bande([[x1, Y(a.n)], [xc, yc], [x1, Y(a.n1)]], d1 > 0); bande([[xc, yc], [x2, Y(b.n)], [x2, Y(b.n1)]], d2 > 0);
    }
  }
  const segs = (cle, garde) => { const out = []; let cur = []; Pt.forEach(p => { if (p[cle] != null && garde(p)) { cur.push(p); } else { if (cur.length) { out.push(cur); } cur = []; } }); if (cur.length) { out.push(cur); } return out; };
  segs('n1', () => true).forEach(s => { if (s.length > 1) { el.push(['polyline', { points: s.map(p => f(X(p.i)) + ',' + f(Y(p.n1))).join(' '), fill: 'none', stroke: '#b9b1a6', 'stroke-width': 2, 'stroke-dasharray': '5 4', 'stroke-linejoin': 'round' }]); } });
  segs('n', p => !p.proj).forEach(s => { if (s.length > 1) { el.push(['polyline', { points: s.map(p => f(X(p.i)) + ',' + f(Y(p.n))).join(' '), fill: 'none', stroke: '#8D1D2C', 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }]); } });
  Pt.forEach(p => { if (p.proj && p.i > 0 && Pt[p.i - 1].n != null) { el.push(['line', { x1: f(X(p.i - 1)), y1: f(Y(Pt[p.i - 1].n)), x2: f(X(p.i)), y2: f(Y(p.n)), stroke: '#8D1D2C', 'stroke-width': 2.2, 'stroke-dasharray': '2 4', 'stroke-linecap': 'round' }]); } });
  Pt.forEach(p => {
    const up = p.n1 == null || p.n == null || p.n >= p.n1;
    if (p.n1 != null) {
      el.push(['circle', { cx: f(X(p.i)), cy: f(Y(p.n1)), r: 3.4, fill: '#fff', stroke: '#b9b1a6', 'stroke-width': 2 }]);
      el.push(['text', { x: f(X(p.i)), y: f(Y(p.n1) + (up ? 15 : -8)), 'text-anchor': 'middle', 'font-size': 10, fill: '#7d776f' }, fv(p.n1)]);
    }
    if (p.n != null) {
      el.push(p.proj || p.creux ? ['circle', { cx: f(X(p.i)), cy: f(Y(p.n)), r: 4.4, fill: '#fff', stroke: '#8D1D2C', 'stroke-width': 2 }] : ['circle', { cx: f(X(p.i)), cy: f(Y(p.n)), r: 4, fill: '#8D1D2C' }]);
      el.push(['text', { x: f(X(p.i)), y: f(Y(p.n) + (up ? -9 : 17)), 'text-anchor': 'middle', 'font-size': 11, 'font-weight': 600, fill: '#8D1D2C' }, fv(p.n)]);
    }
  });
  const yl = cb + 17, yp = cb + 26, hp = 18, pw = Math.min(cw - 6, 66);
  Pt.forEach(p => {
    el.push(['text', { x: f(X(p.i)), y: f(yl), 'text-anchor': 'middle', 'font-size': 10.5, 'font-weight': 600, fill: p.cur ? '#8D1D2C' : (p.etat === 'futur' ? '#a8a197' : '#222') }, p.lab + (p.cur && p.jl ? ' · ' + p.jl + ' j' : '')]);
    if (p.etat === 'futur') { return; }
    const c = teinte(p.ecart);
    el.push(['rect', { x: f(X(p.i) - pw / 2), y: f(yp), width: f(pw), height: hp, rx: hp / 2, fill: c.bg }]);
    el.push(['text', { x: f(X(p.i)), y: f(yp + hp / 2 + 3.6), 'text-anchor': 'middle', 'font-size': 10.5, 'font-weight': 600, fill: c.tx }, p.nonComp ? 'ouverture' : (p.ecart == null ? '—' : pc(p.ecart))]);
    if (p.notes.length) { el.push(['text', { x: f(X(p.i)), y: f(yp + hp + 12), 'text-anchor': 'middle', 'font-size': 9, fill: '#9a6b2b' }, p.notes[0]]); }
  });
  el.push(['text', { x: ml - 6, y: f(yp + hp / 2 + 3.6), 'text-anchor': 'end', 'font-size': 9, 'font-weight': 600, fill: '#9a948c', 'letter-spacing': '.04em' }, 'ÉCART']);
  // Les colonnes du survol, en % de la largeur : la bulle est du HTML posé sur le dessin.
  const cols = Pt.map(p => ({ l: +(100 * (X(p.i) - cw / 2) / W).toFixed(3), w: +(100 * cw / W).toFixed(3), droite: p.i >= 7, bulle: p.bulle, hb: +(100 * (cb + 4) / H).toFixed(2) }));
  return { W, H, el, cols };
}

// Les trois mesures : ce que chacune lit dans le cumul, comment elle s'écrit, ce qu'elle dit.
const MESURES = {
  clients: { nom: 'Clients', titre: 'Clients par mois', col: 'Clients vs ', chiffre: 'Clients ', unite: ' de clients',
    e: k => k.ecart, v: k => k.n, v1: k => k.n1, tout: k => k.tout, f: nf, fd: sg, geo: [nf, nf] },
  panier: { nom: 'Panier moyen', titre: 'Panier moyen par mois', col: 'Panier vs ', chiffre: 'Panier moyen ', unite: ' par client',
    e: k => k.ecartPa, v: k => k.pa, v1: k => k.pa1, tout: k => k.paTout, f: fE2, fd: sgE, geo: [f2, v => f2(v) + '\u00A0€'] },
  articles: { nom: 'Articles / ticket', titre: 'Articles par ticket, mois par mois', col: 'Articles vs ', chiffre: 'Articles par ticket ', unite: ' par ticket',
    e: k => k.ecartAr, v: k => k.ar, v1: k => k.ar1, tout: k => k.arTout, f: f2, fd: sg2, geo: [f2, f2] },
};

export function valsClientsAnnee(app, common, ordre){
  const S = app.state, an = app.exo();
  // Trois mesures, un bouton : les clients (le levier trafic), le panier moyen (CA ÷ clients), les articles par
  // ticket (pièces ÷ clients). Les pièces ont leur propre lecture, faite à la demande.
  const mesure = MESURES[S.clAnMesure] ? S.clAnMesure : 'clients', M = MESURES[mesure], articles = mesure === 'articles';
  clAnCharge(app, an, false, articles);
  const D = app.D.clAn || {};
  const d = articles ? D[an + ':ar'] : (D[an] || (D[an + ':ar'] && !D[an + ':ar'].erreur ? D[an + ':ar'] : null));
  const c = { an: String(an), an1: String(an - 1), relire: () => clAnCharge(app, an, true, articles),
    titre: M.titre + ' — ' + an + ' face à ' + (an - 1), colTitre: M.col + (an - 1),
    chargementTxt: articles ? 'Lecture des pièces vendues au panel… (une lecture par mois la première fois)' : 'Lecture des ventes de l’année au panel…',
    mesures: Object.keys(MESURES).map(k => ({ nom: MESURES[k].nom, on: k === mesure, go: () => app.setState({ clAnMesure: k }) })) };
  common.clAn = c;
  if (!d) { c.chargement = true; return; }
  if (d.erreur) { c.erreur = (articles ? 'Les pièces vendues' : 'Les ventes de l’année') + ' n’ont pas pu être lues (' + d.erreur + ').'; return; }
  const P = d.periodes;
  const jl = (P.find(p => p.enCours) || {}).joursServis || null;
  const mags = d.magasins.map(mg => ({ id: String(mg.id), nom: mg.nom, motif: mg.n1Motif, mois: moisMagasin(mg, P, an) }));
  // Les puces suivent l'ordre du tableau des magasins ; un magasin hors tableau ferme la marche.
  const rang = id => { const k = (ordre || []).indexOf(id); return k < 0 ? 999 : k; };
  mags.sort((a, b) => rang(a.id) - rang(b.id) || a.nom.localeCompare(b.nom));
  const sel = S.clAnSel && mags.some(m => m.id === S.clAnSel) ? S.clAnSel : 'reseau';
  const perim = S.clAnPerim === 'comp' ? 'comp' : 'tous';
  const reseau = sel === 'reseau';
  const choisis = reseau ? mags : mags.filter(m => m.id === sel);
  const Pt = serie(choisis, P, an, perim, reseau, mesure);
  Pt.forEach(p => { if (p.cur) { p.jl = jl; } });
  const cu = cumul(choisis, P, perim, reseau);
  // Le nom court : la zone du référentiel (« Corbais »), sinon ce qui suit « Atelier by … - ».
  const court = m => { const st = (app.D.stores || []).find(z => String(z.id) === m.id); return (st && st.zone) || String(m.nom).replace(/^Atelier by\s*(?:.*?\s)?-\s+/i, '').trim() || m.nom; };
  const cuDe = id => id === 'reseau' ? cumul(mags, P, perim, true) : cumul(mags.filter(m => m.id === id), P, perim, false);
  c.puces = [{ id: 'reseau', nom: 'Réseau · ' + mags.length + ' magasins' }].concat(mags.map(m => ({ id: m.id, nom: court(m) }))).map(o => {
    const e = M.e(cuDe(o.id));
    return { nom: o.nom, on: o.id === sel, ecart: e == null ? 'pas de N-1' : pc(e), coul: teinte(e).tx,
      go: () => app.setState({ clAnSel: o.id }) };
  });
  c.estReseau = reseau;
  c.perimTous = perim === 'tous'; c.perimComp = perim === 'comp';
  c.setTous = () => app.setState({ clAnPerim: 'tous' }); c.setComp = () => app.setState({ clAnPerim: 'comp' });
  const mg = reseau ? null : choisis[0];
  const prem = mg && mg.motif ? (/(\d\d\/\d\d\/\d{4})/.exec(mg.motif) || [])[1] : null;
  const fin = jl ? 'au ' + jl + '/' + String(P.findIndex(p => p.enCours) + 1).padStart(2, '0') : '';
  const e = M.e(cu), v = M.v(cu), v1 = M.v1(cu);
  const constant = reseau && perim === 'tous' ? ' · à périmètre constant ' + pc(M.e(cumul(mags, P, 'comp', true))) : '';
  c.chiffres = [
    { k: M.chiffre + an, v: M.f(e == null ? M.tout(cu) : v), s: e == null ? fin : cu.plage },
    { k: (an - 1) + ', même période', v: e == null ? '—' : M.f(v1), s: e == null ? (prem ? 'première vente le ' + prem : 'pas d’année précédente') : 'arrêté au même jour servi' },
    { k: 'Écart', v: e == null ? '—' : M.fd(v - v1), coul: teinte(e).tx, s: e == null ? '' : pc(e) + M.unite + constant }];
  c.svg = geometrie(Pt, M.geo[0], M.geo[1]);
  c.vide = articles ? 'Aucune pièce vendue lue au panel pour ' + an + '.' : 'Aucun client lu pour ' + an + '.';
  c.legende = { n: String(an), n1: String(an - 1) };
  const ec = P.find(p => p.enCours);
  const Mois = ec ? MOISL[ec.iso - 1].replace(/^./, x => x.toUpperCase()) : '';
  c.note = (mesure === 'panier' ? 'Panier moyen = CA du mois ÷ clients du mois ; celui du réseau pèse chaque magasin par ses clients. '
      : articles ? 'Articles par ticket = pièces vendues du mois ÷ clients du mois (pièces : relevé des ventes du panel ; clients : ventes par jour) ; celui du réseau pèse chaque magasin par ses clients. ' : '')
    + (ec ? (mesure !== 'clients' ? Mois + ' est en cours (' + jl + ' jours lus) : sa valeur se lit à date (point creux), face aux ' + jl + ' premiers jours de ' + (an - 1) + '. '
      : Mois + ' est en cours (' + jl + ' jours lus) : sa valeur est prolongée au rythme du mois (pointillé, point creux), mais son écart se calcule à date, ' + (an - 1) + ' arrêté au même jour. ') : '')
    + (P.some(p => p.futur) ? 'Les mois à venir montrent ' + (an - 1) + ' : ce sont les mois à battre. ' : '')
    + (reseau && perim === 'tous' ? 'Le réseau compte tous les magasins, ouvertures comprises ; « périmètre constant » ne garde que ceux qui ont un ' + (an - 1) + '. ' : '')
    + 'Un mois d’ouverture n’est pas relié (mois partiel). Source : ' + (d.source || 'panel') + '.';
  // La colonne du tableau, par magasin.
  c.parMagasin = {};
  mags.forEach(m => { const k = cumul([m], P, 'comp', false), ek = M.e(k);
    c.parMagasin[m.id] = ek == null ? { v: M.f(M.tout(k)), e: '', s: 'pas de ' + (an - 1), coul: '' }
      : { v: M.f(M.v(k)), e: pc(ek), coul: teinte(ek).tx, s: (an - 1) + ' : ' + M.f(M.v1(k)) + (k.plage.startsWith('janv.') ? '' : '*'),
        titre: (an - 1) + ' sur la même période' + (k.plage.startsWith('janv.') ? '' : ' (* mois comparables : ' + k.plage + ')') }; });
  c.sel = sel;
}
