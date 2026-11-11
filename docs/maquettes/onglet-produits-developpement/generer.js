/* Maquettes « l'onglet Produits : est-ce que ça se développe ? » (demande du 11/11/2026, 2e version) :
 *   la question de l'onglet est : y a-t-il un développement dans la catégorie, dans le produit ?
 *   On compare les 6 dernières semaines closes (S40 → S45) aux 6 d'avant (S34 → S39), et chaque
 *   catégorie, chaque produit, à la moyenne d'un magasin du réseau sur les mêmes semaines.
 *   Un clic sur une catégorie ouvre une modale ; un clic sur un produit déplie son prix, ses
 *   quantités et sa marge, avant et après.
 * Trois directions, chiffres réels d'Atelier by - Halle :
 *   E — le pont : d'où vient la hausse, ce qui se développe, ce qui recule   e.html, e-modale.html
 *   F — les courbes : chaque catégorie sur sa courbe, face au réseau          f.html, f-modale.html
 *   G — la carte et le tableau du développement                            g.html, g-modale.html
 * Données : ../onglet-produits/reel-halle.json (extraire.js) et reel-reseau.json (extraire-reseau.js).
 *   node docs/maquettes/onglet-produits-developpement/generer.js */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const X = JSON.parse(fs.readFileSync(path.join(D, 'reel-reseau.json'), 'utf8'));

/* ---------- Le changement de caisse de S40 : « Tartes » devient « Tartes – Ø 28 cm » ----------
 * Les anciennes références (noms néerlandais) sont rattachées aux nouvelles par leur nom ; les
 * portions vendues sans nom de tarte (« 1/2 », « 1/4 ») restent à part. Sans ce rattachement,
 * toutes les tartes paraissent « nouvelles » en S40 et l'ancienne catégorie « arrêtée ». */
const ANC = 'Tartes', NOUV = 'Tartes – Ø 28 cm';
const NL = { 'Rijst': 'Riz', 'Brooke': 'Brookie', 'Kaas': 'Fromage', 'Appelschijfjes': 'Pommes Tranches', 'Parijse Appels': 'Pommes Parisienne', 'Appels Amandelen': 'Pommes Amandes', 'Aardbei & Rabarber': 'Rhubarbe & Fraises', 'Abrikoos': 'Abricot', 'Abricoted en kersen': 'Abricot & Cerise', 'Frangipane & Poires': 'Frangipane & Poire', 'Frangipanes & Appels': 'Frangipane & Pommes', 'Rabarber': 'Rhubarbe', 'Flan Brézillien': 'Flan Brésilienne', 'Brésilienne': 'Bréslienne' };
const RATTACHES = [];
global.PO_PRETRAITER = R => {
  R.categories = R.categories.filter(c => c.nom !== ANC);
  const garder = [];
  R.produits.forEach(p => {
    if (p.cat !== ANC) { garder.push(p); return; }
    p.cat = NOUV;
    const g = p.nom.match(/^(1\/[24])$/);
    if (g) { p.nom = g[1] + ' — tarte non nommée'; garder.push(p); return; }
    const nom = NL[p.nom] || p.nom;
    const t = R.produits.find(z => z.cat === NOUV && z !== p && !z.pere && z.nom === nom && !z.ancien);
    if (t) { for (let i = 0; i < p.q.length; i++) { t.q[i] += p.q[i]; t.v[i] = +(t.v[i] + p.v[i]).toFixed(2); t.c[i] = +(t.c[i] + p.c[i]).toFixed(2); } t.ids = (t.ids || [t.id]).concat(p.id); RATTACHES.push([p.nom, nom]); return; }
    const pp = R.produits.find(z => z.cat === NOUV && z.pere && z.nom.replace(/\s*—\s*1\/\d\s*$/, '') === nom);
    if (pp) { p.ids = [p.id, pp.pere]; p.id = pp.pere; RATTACHES.push([p.nom, nom]); p.nom = nom; p.ancien = true; garder.push(p); return; }
    if (nom !== p.nom) { RATTACHES.push([p.nom, nom]); p.nom = nom; }
    garder.push(p);
  });
  R.produits = garder;
};
const P = require('../onglet-produits/generer.js');
const { R, W, N, IE, IC, sem, dCourt, dLong, nf, fN, fE, fP, esc, somme, ARTS, CATS, GRPS, TOT, entete, echelle, fAx, motifs, seg, tauxCell } = P;

/* ---------- Le développement : 6 dernières semaines face aux 6 d'avant ---------- */
const AV = [0, 1, 2, 3, 4, 5], AP = [6, 7, 8, 9, 10, 11];
const s6 = (a, I) => I.reduce((x, i) => x + ((a && a[i]) || 0), 0);
const addS = (a, b) => a.map((x, i) => (x || 0) + ((b && b[i]) || 0));
const resProd = new Map(X.produits.map(p => [String(p.id), p]));
const resCat = new Map(X.categories.map(c => [c.nom, c]));
const resGrp = new Map(X.groupes.map(g => [g.nom, g]));
const fS = (n, d = 0) => (Math.round(Math.abs(n) * Math.pow(10, d)) === 0 ? '' : (n > 0 ? '+' : '−')) + nf(Math.abs(n), d);   // pas de « ± » : la police ne l'a pas
const fSE = n => fS(n) + ' €';
const fSP = (n, d = 0) => n == null || !isFinite(n) ? '—' : fS(n, d) + ' %';
function developper(o, res, resQ) {
  o.vA = s6(o.v, AV); o.vP = s6(o.v, AP); o.dV = o.vP - o.vA; o.dVp = o.vA > 0 ? 100 * o.dV / o.vA : null;
  o.qA = s6(o.q, AV); o.qP = s6(o.q, AP); o.dQ = o.qP - o.qA; o.dQp = o.qA > 0 ? 100 * o.dQ / o.qA : null;
  o.mA = s6(o.m, AV); o.mP = s6(o.m, AP); o.vcA = s6(o.vc, AV); o.vcP = s6(o.vc, AP);
  o.tA = o.vcA > 0 ? 100 * o.mA / o.vcA : null; o.tP = o.vcP > 0 ? 100 * o.mP / o.vcP : null;
  o.res = res || null; o.resQ = resQ || null;
  const r = res || resQ;
  o.dRp = r && s6(r, AV) > 0 ? 100 * (s6(r, AP) / s6(r, AV) - 1) : null;
  if (o.etat) { o.vd = o.etat.startsWith('nouveau') ? ['nouv', o.etat] : ['arr', o.etat]; }
  else if (o.qA + o.qP < 24) { o.vd = ['peu', 'peu vendu']; }
  else if (o.dVp >= 8) { o.vd = ['up', '▲ se développe']; }
  else if (o.dVp <= -8) { o.vd = ['dn', '▼ recule']; }
  else { o.vd = ['st', '= stable']; }
  o.faceRes = o.dVp != null && o.dRp != null && !o.etat ? o.dVp - o.dRp : null;
  return o;
}
const vdTag = o => `<span class="vd ${o.vd[0]}">${o.vd[1]}</span>`;
const resTxt = (o, court) => o.dRp == null || o.etat ? '<span class="vr">—</span>' : `<span class="vr ${o.faceRes >= 5 ? 'vr-p' : (o.faceRes <= -5 ? 'vr-m' : '')}" title="${esc(resPhr(o))}">${court ? '' : 'réseau '}${fSP(o.dRp)}</span>`;
const VERBE = { up: 'se développe', st: 'est stable', dn: 'recule', nouv: 'est nouveau', arr: 'est arrêté', peu: 'se vend trop peu pour juger' };
function resPhr(o) {
  if (o.dRp == null || o.etat) { return ''; }
  const r = fSP(o.dRp), k = o.vd[0];
  if (k === 'up') { return o.faceRes >= 5 ? `plus vite que le réseau (${r})` : (o.faceRes <= -5 ? `moins vite que le réseau (${r})` : `comme le réseau (${r})`); }
  if (k === 'dn') { return o.dRp <= -8 ? `comme le réseau (${r}) : la saison, plutôt qu’un problème du magasin` : `alors que le réseau ${o.dRp >= 8 ? 'progresse' : 'tient'} (${r})`; }
  return o.dRp >= 8 ? `quand le réseau progresse (${r})` : (o.dRp <= -8 ? `quand le réseau recule (${r})` : `comme le réseau (${r})`);
}
// les 3 dernières semaines face aux 6 d'avant : ça repart, ou ça ralentit ?
function elan(o, cle = 'v') { const a = s6(o[cle], AV) / 6, r3 = (o[cle][9] + o[cle][10] + o[cle][11]) / 3; return a > 0 ? 100 * (r3 / a - 1) : null; }
const clV = o => o.vd[0] === 'up' ? 'up' : (o.vd[0] === 'dn' ? 'dn' : 'st');

// nouveau ou arrêté : une des deux périodes presque vide, ou plus rien depuis 3 semaines
function etatDe(o) {
  const av = s6(o.q, AV), ap = s6(o.q, AP);
  if (ap > 0 && (av < 0.15 * ap || ([0, 1, 2].every(i => !o.q[i]) && av < 0.5 * ap))) { return 'nouveau · ' + sem(IC.find(i => o.q[i] >= 0.25 * ap / 6)); }
  if (av > 0 && (ap < 0.15 * av || [9, 10, 11].every(i => !o.q[i]))) { return 'arrêté · ' + sem([...IC].reverse().find(i => o.q[i] > 0)); }
  return null;
}
[...ARTS, ...CATS, ...GRPS].forEach(o => { o.etat = etatDe(o); });

// le réseau des produits : la somme des références rattachées (ancienne et nouvelle)
const idsDe = a => [...new Set(R.produits.filter(p => (p.pere || p.id) === a.id && !p.pere).flatMap(p => p.ids || [p.id]).concat([a.id]))];
const CAISSE = new Set([NOUV, 'Quiches']);
ARTS.forEach(a => {
  const rs = idsDe(a).map(id => resProd.get(String(id))).filter(Boolean);
  const res = rs.length ? rs.reduce((s, x) => addS(s, x.reseau), Array(N).fill(0)) : null;
  a.anDer = rs.length ? rs.reduce((s, x) => addS(s, x.anDernier), Array(N).fill(0)) : null;
  // tartes et quiches vendues en portions : avant S40 la caisse ne nommait pas la quiche d'une portion → on suit la pièce entière
  const ent = a.fmts.find(f => f.fmt === 'entière');
  a.surEnt = CAISSE.has(a.cat) && a.fmts.length > 1 && ent && ent.Q > 0;
  if (a.surEnt) { const o = developper(Object.assign(Object.create(null), ent, { etat: a.etat }), null, res); Object.assign(a, { vA: o.vA, vP: o.vP, dV: o.dV, dVp: o.dVp, qA: o.qA, qP: o.qP, dQ: o.dQ, dQp: o.dQp, vd: o.vd, dRp: o.dRp, faceRes: o.faceRes, resQ: res, mA: s6(a.m, AV), mP: s6(a.m, AP), vcA: s6(a.vc, AV), vcP: s6(a.vc, AP) }); a.tA = a.vcA ? 100 * a.mA / a.vcA : null; a.tP = a.vcP ? 100 * a.mP / a.vcP : null; }
  else { developper(a, null, res); }
});
const resCatDe = nom => resCat.get(nom);
CATS.forEach(c => { const r = resCatDe(c.nom), r2 = c.nom === NOUV ? resCat.get(ANC) : null;
  developper(c, r ? (r2 ? addS(r.reseau, r2.reseau) : r.reseau) : null); });
GRPS.forEach(g => { const r = resGrp.get(g.nom); developper(g, r ? r.reseau : null); });
const RES_TOT = X.groupes.filter(g => g.reseau).reduce((s, g) => addS(s, g.reseau), Array(N).fill(0));
developper(TOT, RES_TOT);
const art = nom => ARTS.find(a => a.nom === nom);
const cat = nom => CATS.find(c => c.nom === nom);
const grp = nom => GRPS.find(g => g.nom === nom);
const juge = ARTS.filter(a => !a.etat && a.qA + a.qP >= 24);
const MONTENT = juge.filter(a => a.vd[0] === 'up').sort((x, y) => y.dV - x.dV);
const BAISSENT = juge.filter(a => a.vd[0] === 'dn').sort((x, y) => x.dV - y.dV);
const compte = (L, k) => L.filter(o => o.vd[0] === k).length;

/* ---------- Le cadre ---------- */
const PERIODE_DV = `${sem(AP[0])} → ${sem(AP[5])} face à ${sem(AV[0])} → ${sem(AV[5])}`;
const periodeDV = () => `<div class="po-per"><span class="txt">Comparer</span><span class="seg vif"><span class="on">6 semaines face aux 6 d’avant</span><span>4 face à 4</span><span>le mois face au mois d’avant</span></span><span class="txt"><b>${dLong(W[AP[0]].du)} → ${dLong(W[AP[5]].au)}</b> face au <b>${dLong(W[AV[0]].du)} → ${dLong(W[AV[5]].au)}</b> · ${sem(IE)} en cours, hors comparaison</span><span class="case"><i style="background:var(--accent);border-color:var(--accent);position:relative"></i>face au réseau (la moyenne d’un magasin)</span></div>`;
const pageDV = (lettre, titre, corps, extra = '') => P.page(lettre, titre, corps, extra).replace('<link rel="stylesheet" href="po.css">', '<link rel="stylesheet" href="../onglet-produits/po.css"><link rel="stylesheet" href="dv.css">');
const pageModaleDV = (lettre, titre, fond, modale) => P.pageModale(lettre, titre, fond, modale).replace('<link rel="stylesheet" href="po.css">', '<link rel="stylesheet" href="../onglet-produits/po.css"><link rel="stylesheet" href="dv.css">');
const NOTE = `<div class="note">Chiffres réels, ${esc(R.magasin)}, lus le ${dLong(R.lu)} 2026 en lecture seule : <code>/ventes/stats?vue=semaine</code> (le magasin, produit par produit), <code>/analyse/categories/magasin</code> et <code>/analyse/produits/magasin</code> (la moyenne d’un magasin du réseau, ${X.groupes[0].magasins} magasins, et le réseau l’an dernier). Se développe : le chiffre des 6 dernières semaines dépasse de 8 % celui des 6 d’avant ; recule : 8 % de moins ; nouveau ou arrêté : quand une des deux périodes est presque vide. Tartes et quiches vendues en portions : jusqu’en ${sem(6)}, la caisse ne nommait pas la tarte d’une portion, on suit donc la pièce entière ; les anciennes références « Tartes » (noms néerlandais) sont rattachées aux nouvelles par leur nom (${RATTACHES.length} rattachements).</div>`;
const kpisDV = () => {
  const nC = CATS.filter(c => c.qA + c.qP >= 24);
  return `<div class="po-k k5">
  <div class="t"><div class="k">Le magasin · chiffre</div><div class="v ec ${clV(TOT)}">${fSP(TOT.dVp, 1)}</div><div class="s">${fSE(TOT.dV)} · ${fE(TOT.vP / 6)} par semaine contre ${fE(TOT.vA / 6)} · réseau ${fSP(TOT.dRp, 1)}</div></div>
  <div class="t"><div class="k">Pièces vendues</div><div class="v ec ${TOT.dQp >= 8 ? 'up' : (TOT.dQp <= -8 ? 'dn' : 'st')}">${fSP(TOT.dQp, 1)}</div><div class="s">${fN(TOT.qP / 6)} par semaine contre ${fN(TOT.qA / 6)}</div></div>
  <div class="t"><div class="k">Marge brute</div><div class="v ec ${TOT.mP > TOT.mA ? 'up' : 'dn'}">${fSE(TOT.mP - TOT.mA)}</div><div class="s">${fP(TOT.tP, 1)} des ventes, contre ${fP(TOT.tA, 1)} avant</div></div>
  <div class="t"><div class="k">Catégories</div><div class="v"><span class="ec up">${compte(nC, 'up')}</span> <small>se développent</small></div><div class="s">${compte(nC, 'st')} stables · <span class="ec dn">${compte(nC, 'dn')} reculent</span> · ${compte(CATS, 'nouv')} nouvelles</div></div>
  <div class="t"><div class="k">Produits</div><div class="v"><span class="ec up">${compte(juge, 'up')}</span> <small>se développent</small></div><div class="s">${compte(juge, 'st')} stables · <span class="ec dn">${compte(juge, 'dn')} reculent</span> · ${compte(ARTS, 'nouv')} nouveaux · ${compte(ARTS, 'arr')} arrêtés</div></div>
</div>`; };

/* ---------- Les graphiques ---------- */
// Colonnes semaine par semaine, avant en clair, après en foncé, la moyenne de chaque période, et le réseau
function colsDev(o, { w = 1000, h = 280, bw = 46, cle = 'v', res = null, unite = '€', lignesMoy = true, mini = false } = {}) {
  const m = mini ? { l: 30, r: 8, t: 8, b: 18 } : { l: 48, r: 112, t: 24, b: 40 };
  const vals = o[cle];
  // le réseau, ramené au niveau d'avant du magasin : la ligne que le magasin aurait suivie au rythme du réseau
  const k = res && s6(res, AV) > 0 ? s6(vals, AV) / s6(res, AV) : 0, rs = k ? res.map(v => v * k) : null;
  const ts = echelle(Math.max(...vals, ...(rs ? rs.slice(0, 12) : [0])));
  const haut = ts[ts.length - 1], y = v => m.t + (h - m.t - m.b) * (1 - v / haut);
  const slot = (w - m.l - m.r) / N, cx = k => m.l + slot * (k + .5);
  const fv = v => unite === '€' ? fE(v) : fN(v) + ' pièces';
  let g = ts.map(v => `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${fAx(v)}</text>`).join('');
  g += `<rect x="${(m.l + slot * 6 - 1).toFixed(1)}" y="${m.t - 14}" width="${(slot * 6 + 2).toFixed(1)}" height="${(h - m.b - m.t + 14).toFixed(1)}" fill="#FCF7F7"/>`;
  for (let i = 0; i < N; i++) {
    const ec = i === IE, hh = y(0) - y(vals[i]), fill = ec ? 'url(#hr)' : (i < 6 ? 'var(--dv-avant)' : 'var(--dv-apres)');
    g += seg(cx(i) - bw / 2, y(0), bw, hh, fill, true, `${sem(i)} (${dCourt(W[i].du)} → ${dCourt(W[i].au)}) · ${fv(vals[i])}${rs ? ' · au rythme du réseau ' + fv(rs[i]) : ''}${ec ? ' · en cours, ' + W[i].jours + ' jours' : ''}`);
    if (mini) { if (i === 0 || i === 11) { g += `<text class="axs" x="${cx(i).toFixed(1)}" y="${h - 4}" text-anchor="middle">${sem(i)}</text>`; } continue; }
    g += `<text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 16}" text-anchor="middle" style="${ec ? '' : 'fill:var(--ink)'}">${sem(i)}</text>${slot >= 48 || (i !== IE && i % 2 === 0) ? `<text class="axs" x="${cx(i).toFixed(1)}" y="${h - m.b + 29}" text-anchor="middle">${ec ? (slot >= 48 ? 'en cours · ' + W[i].jours + ' j' : '') : dCourt(W[i].du)}</text>` : ''}`;
  }
  if (!mini) { g += `<text class="axs" x="${(m.l + slot * 3).toFixed(1)}" y="${m.t - 4}" text-anchor="middle" style="fill:var(--muted-2)">avant · ${sem(0)} → ${sem(5)}</text><text class="axs" x="${(m.l + slot * 9).toFixed(1)}" y="${m.t - 4}" text-anchor="middle" style="fill:var(--accent)">les 6 dernières · ${sem(6)} → ${sem(11)}</text>`; }
  if (rs) {
    const pts = rs.slice(0, 12).map((v, i) => [cx(i), y(v)]);
    g += `<polyline points="${pts.map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="var(--dv-res)" stroke-width="2" stroke-linejoin="round"/>` + pts.map((p, i) => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3.5" fill="var(--dv-res)" stroke="#fff" stroke-width="1.5"><title>${sem(i)} · le rythme du réseau, à votre niveau : ${fv(rs[i])} (un magasin du réseau en moyenne : ${fv(res[i])})</title></circle>`).join('');
    g += `<text class="lab" x="${(cx(11) + bw / 2 + 8).toFixed(1)}" y="${(pts[11][1] + 4).toFixed(1)}" style="fill:var(--muted-2)">réseau <tspan class="axs">${fSP(100 * (s6(rs, AP) / s6(rs, AV) - 1))}</tspan></text>`;
  }
  if (lignesMoy) {
    const mA = s6(vals, AV) / 6, mP = s6(vals, AP) / 6;
    g += `<line x1="${(m.l + 4).toFixed(1)}" x2="${(m.l + slot * 6 - 4).toFixed(1)}" y1="${y(mA).toFixed(1)}" y2="${y(mA).toFixed(1)}" stroke="var(--ink)" stroke-width="1.5" stroke-dasharray="5 4"/><line x1="${(m.l + slot * 6 + 4).toFixed(1)}" x2="${(m.l + slot * 12 - 4).toFixed(1)}" y1="${y(mP).toFixed(1)}" y2="${y(mP).toFixed(1)}" stroke="var(--ink)" stroke-width="1.5" stroke-dasharray="5 4"/>`;
    const lbl = (x, yy, t) => `<rect x="${(x - 2).toFixed(1)}" y="${(yy - 12).toFixed(1)}" width="${(t.length * 5.9 + 6).toFixed(0)}" height="16" rx="3" fill="#fff" opacity=".92"/><text class="val" x="${(x + 1).toFixed(1)}" y="${yy.toFixed(1)}">${t}</text>`;
    g += lbl(m.l + 8, y(mA) - 6, 'moyenne ' + (unite === '€' ? fE(mA) : fN(mA))) + lbl(m.l + slot * 6 + 8, y(mP) - 6, 'moyenne ' + (unite === '€' ? fE(mP) : fN(mP)) + '  ' + fSP(100 * (mP / mA - 1)));
  }
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Semaine par semaine : les 6 d’avant et les 6 dernières">${motifs}${g}</svg>
  <div class="leg"><span><i style="background:var(--dv-avant)"></i>${sem(0)} → ${sem(5)}</span><span><i style="background:var(--dv-apres)"></i>${sem(6)} → ${sem(11)}</span><span><i class="ln" style="background:repeating-linear-gradient(90deg,var(--ink) 0 5px,transparent 5px 9px)"></i>la moyenne de chaque période</span>${rs ? '<span><i class="ln" style="background:var(--dv-res)"></i>au rythme du réseau : sa courbe, ramenée à votre niveau d’avant</span>' : ''}<span><i style="background:repeating-linear-gradient(135deg,#B03048 0 2px,#F4E4E7 2px 5px)"></i>${sem(IE)}, en cours</span></div>`;
}
// Petites colonnes bicolores pour une ligne
function spark2(o, { w = 104, h = 24, cle = 'v' } = {}) {
  const vals = o[cle], max = Math.max(1, ...IC.map(i => vals[i])), bw = (w - 2 * (N - 1)) / N;
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="display:block;margin-left:auto">${vals.map((v, i) => { const hh = Math.max(v > 0 ? 1.5 : 0, (h - 1) * Math.max(0, v) / max); return `<rect x="${(i * (bw + 2)).toFixed(1)}" y="${(h - hh).toFixed(1)}" width="${bw.toFixed(1)}" height="${hh.toFixed(1)}" rx="1" fill="${i === IE ? '#F4E4E7' : (i < 6 ? 'var(--dv-avant)' : 'var(--dv-apres)')}"><title>${sem(i)} · ${cle === 'v' ? fE(v) : fN(v) + ' pièces'}</title></rect>`; }).join('')}</svg>`;
}
// Indice 100 = la moyenne des 6 semaines d'avant ; le magasin et le réseau (S46 en cours exclue)
function indice(o, { w = 1000, h = 260, res = o.res, dom = null, mini = false, cle = 'v' } = {}) {
  const m = mini ? { l: 4, r: 4, t: 6, b: 4 } : { l: 44, r: 96, t: 18, b: 34 };
  const vals = o[cle] || o.v, bA = s6(vals, AV) / 6, ix = vals.slice(0, 12).map(v => bA > 0 ? 100 * v / bA : null);
  const rA = res ? s6(res, AV) / 6 : 0, ir = res && rA > 0 ? res.slice(0, 12).map(v => 100 * v / rA) : null;
  const tout = ix.concat(ir || []).filter(v => v != null);
  const [y0, y1] = dom || [Math.floor(Math.min(80, ...tout) / 10) * 10, Math.ceil(Math.max(120, ...tout) / 10) * 10];
  const y = v => m.t + (h - m.t - m.b) * (1 - (Math.max(y0, Math.min(y1, v)) - y0) / (y1 - y0));
  const slot = (w - m.l - m.r) / 12, cx = k => m.l + slot * (k + .5);
  let g = `<rect x="${(m.l + slot * 6).toFixed(1)}" y="${m.t}" width="${(slot * 6).toFixed(1)}" height="${(h - m.t - m.b).toFixed(1)}" fill="#FCF7F7"/>`;
  if (!mini) { for (let v = y0; v <= y1; v += (y1 - y0 > 80 ? 20 : 10)) { g += `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${v}</text>`; }
    for (let i = 0; i < 12; i++) { g += `<text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 15}" text-anchor="middle" style="fill:var(--ink)">${sem(i)}</text><text class="axs" x="${cx(i).toFixed(1)}" y="${h - m.b + 27}" text-anchor="middle">${dCourt(W[i].du)}</text>`; } }
  g += `<line x1="${m.l}" x2="${w - m.r}" y1="${y(100).toFixed(1)}" y2="${y(100).toFixed(1)}" stroke="var(--ink)" stroke-width="1" stroke-dasharray="3 3"/>`;
  const ligne = (s, col, larg, nom) => { const pts = s.map((v, i) => v == null ? null : [cx(i), y(v)]).filter(Boolean);
    return `<polyline points="${pts.map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${col}" stroke-width="${larg}" stroke-linejoin="round" stroke-linecap="round"/>` + (mini ? '' : s.map((v, i) => v == null ? '' : `<circle cx="${cx(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="${larg > 1.8 ? 4 : 3}" fill="${col}" stroke="#fff" stroke-width="1.5"><title>${sem(i)} · ${nom} : indice ${nf(v, 0)}</title></circle>`).join('')); };
  if (ir) { g += ligne(ir, 'var(--dv-res)', mini ? 1.5 : 2, 'un magasin du réseau'); }
  g += ligne(ix, 'var(--dv-apres)', mini ? 2 : 2.5, esc(R.magasin));
  if (!mini) {
    const mP = s6(ix, AP) / 6, rP = ir ? s6(ir, AP) / 6 : null;
    const ly = [y(ix[11]), ir ? y(ir[11]) : null]; if (ir && Math.abs(ly[0] - ly[1]) < 15) { if (ly[0] < ly[1]) { ly[1] = ly[0] + 15; } else { ly[0] = ly[1] + 15; } }
    g += `<text class="lab" x="${(cx(11) + 12).toFixed(1)}" y="${(ly[0] + 4).toFixed(1)}" style="fill:var(--dv-apres)">Halle <tspan class="axs" style="fill:var(--ink)">${nf(mP, 0)}</tspan></text>`;
    if (ir) { g += `<text class="lab" x="${(cx(11) + 12).toFixed(1)}" y="${(ly[1] + 4).toFixed(1)}" style="fill:var(--muted-2)">réseau <tspan class="axs" style="fill:var(--ink)">${nf(rP, 0)}</tspan></text>`; }
  }
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" ${mini ? `width="${w}" height="${h}" style="display:block;width:100%;height:auto"` : ''} role="img" aria-label="Indice 100 = la moyenne des 6 semaines d’avant">${g}</svg>`;
}
const legIndice = `<div class="leg"><span><i class="ln" style="background:var(--dv-apres)"></i>${esc(R.magasin)}</span><span><i class="ln" style="background:var(--dv-res)"></i>un magasin du réseau, en moyenne (lui aussi en indice)</span><span><i class="ln" style="background:repeating-linear-gradient(90deg,var(--ink) 0 3px,transparent 3px 6px)"></i>100 = la moyenne de ${sem(0)} → ${sem(5)}</span><span><i style="background:#FCF7F7;border:1px solid var(--line)"></i>les 6 dernières semaines</span><span class="mu">à droite : la moyenne des 6 dernières semaines, en indice · ${sem(IE)} en cours, hors courbe</span></div>`;
// Barre d'écart centrée sur zéro
const barreEcart = (d, max) => `<div class="div"><span class="g">${d < 0 ? `<i style="width:${(100 * -d / max).toFixed(1)}%"></i>` : ''}</span><span class="d">${d > 0 ? `<i style="width:${(100 * d / max).toFixed(1)}%"></i>` : ''}</span></div>`;

/* ---------- La liste déroulante : est-ce que ce produit se développe ? ---------- */
function deroulantDV(a, { colspan = 0, etroit = false, largeur = 900 } = {}) {
  const F = a.fiche || {};
  const ent = a.fmts.find(f => f.fmt === 'entière' && f.Q > 0);
  const base = a.surEnt ? ent : a;
  const pA = base.qA > 0 ? s6(base.v, AV) / base.qA : null, pP = base.qP > 0 ? s6(base.v, AP) / base.qP : null;
  const lig = (k, av, ap, ec) => `<tr><td>${k}</td><td>${av}</td><td>${ap}</td><td>${ec}</td></tr>`;
  const ecP = (x, y, d = 0) => x > 0 && y != null ? `<span class="ec ${y >= x * 1.08 ? 'up' : (y <= x * .92 ? 'dn' : 'st')}">${fSP(100 * (y / x - 1), d)}</span>` : '—';
  const aDer = a.anDer && s6(a.anDer, AV) > 0 ? 100 * (s6(a.anDer, AP) / s6(a.anDer, AV) - 1) : null;
  const phr = [];
  phr.push(`${a.surEnt ? 'Pièces entières' : 'Chiffre'} des 6 dernières semaines : <b style="font-weight:400;color:var(--ink)">${a.surEnt ? fN(a.qP) + ' pièces contre ' + fN(a.qA) : fE(a.vP) + ' contre ' + fE(a.vA)}</b>.`);
  if (a.dRp != null && !a.etat) { phr.push(`Le produit ${VERBE[a.vd[0]]} <b style="font-weight:400;color:var(--ink)">${resPhr(a)}</b>.`); }
  const el = elan(a.surEnt ? ent : a, a.surEnt ? 'q' : 'v');
  if (el != null && !a.etat) { const sensV = a.vd[0] === 'up' ? 1 : (a.vd[0] === 'dn' ? -1 : 0), sensE = el >= 8 ? 1 : (el <= -8 ? -1 : 0);
    if (sensE !== sensV) { phr.push(`<b style="font-weight:400;color:var(--ink)">${sensE > 0 ? 'Mais ça repart' : (sensE < 0 ? 'Mais ça ralentit' : 'Les 3 dernières semaines reviennent au niveau d’avant')}</b> : les 3 dernières semaines sont à ${fSP(el)} face aux 6 d’avant.`); } }
  if (aDer != null) { phr.push(`L’an dernier, sur les mêmes semaines, le réseau faisait ${fSP(aDer)} : ${Math.abs(aDer) < 5 ? 'pas d’effet de saison' : (aDer > 0 ? 'une partie de la hausse est la saison' : 'la saison est plutôt à la baisse')}.`); }
  const corps = `<div class="${etroit ? 'dvd etroit' : 'dvd'}">
    <div><div class="k">Est-ce que ça se développe ? <span class="seg" style="margin-left:auto"><span class="on">Pièces</span><span>Chiffre</span><span>Marge</span></span></div>
      <div class="ver">${vdTag(a)}<span class="big ec ${clV(a)}">${a.surEnt ? fSP(a.dQp) : fSP(a.dVp)}</span><span class="vr">${a.surEnt ? 'pièces entières' : 'de chiffre'}, ${sem(6)} → ${sem(11)} face à ${sem(0)} → ${sem(5)}</span></div>
      <div class="phr">${phr.join(' ')}</div>
      ${colsDev(a.surEnt ? ent : a, { w: etroit ? largeur : 700, h: etroit ? 210 : 190, bw: etroit ? Math.round(largeur / 24) : 28, cle: 'q', res: a.resQ ? a.resQ : null, unite: 'p' }).replace(/<div class="leg">/, '<div class="leg" style="font-size:10.5px">')}</div>
    <div><div class="k">Prix, quantités, marge · avant → après</div>
      <table class="aa"><thead><tr><th></th><th>${sem(0)}→${sem(5)}</th><th>${sem(6)}→${sem(11)}</th><th>écart</th></tr></thead><tbody>
      ${lig('Pièces par semaine', nf(base.qA / 6, 0), nf(base.qP / 6, 0), ecP(base.qA, base.qP))}
      ${lig('Prix encaissé' + (a.surEnt ? ' (entière)' : ''), pA ? fE(pA, 2) : '—', pP ? fE(pP, 2) : '—', pA && pP ? (Math.abs(pP - pA) < 0.005 ? '<span class="ec st">inchangé</span>' : `<span class="ec st">${fS(pP - pA, 2)} €</span>`) : '—')}
      ${lig('Chiffre par semaine', fE(a.vA / 6), fE(a.vP / 6), ecP(a.vA, a.vP))}
      ${a.vcA + a.vcP > 0 ? lig('Marge par semaine', fE(a.mA / 6), fE(a.mP / 6), ecP(a.mA, a.mP)) + lig('Marge, % des ventes', a.tA != null ? fP(a.tA, 1) : '—', a.tP != null ? fP(a.tP, 1) : '—', a.tA != null && a.tP != null ? `<span class="ec st">${fS(a.tP - a.tA, 1)} pt</span>` : '—') : lig('Marge', '—', '—', '<span class="tag mu" style="margin:0">sans coût</span>')}
      </tbody></table>
      <dl>${F.prixTtc != null ? `<dt>Prix de vente catalogue</dt><dd>${fE(F.prixTtc, 2)} <small>TTC</small></dd>` : ''}${F.cout != null ? `<dt>Coût de la recette</dt><dd>${fE(F.cout, 2)}</dd>` : ''}${F.coef ? `<dt>Coefficient</dt><dd>× ${nf(F.coef, 2)} <small>cible × ${nf(F.cible, 2)}</small></dd>` : ''}${F.prixCible && F.coef < F.cible ? `<dt>Prix à la cible</dt><dd>${fE(F.prixCible, 2)} <small>+${nf(F.prixCible - F.prixTtc, 2)} €</small></dd>` : ''}</dl></div>
    <div><div class="k">Son poids</div>
      <dl style="margin-top:0">
        <dt>Dans sa catégorie</dt><dd>${fP(100 * a.V / cat(a.cat).V, 1)}</dd>
        <dt>Dans le magasin</dt><dd>${fP(100 * a.V / TOT.V, 1)}</dd>
        <dt>Son écart, dans celui de sa catégorie</dt><dd>${fSE(a.dV)} <small>sur ${fSE(cat(a.cat).dV)}</small></dd>
        <dt>Rang à la marge</dt><dd>${a.rang || '—'}<small>${a.rang ? ' sur ' + P.rangM.length : ''}</small></dd>
      </dl>
      <div class="act"><span class="pr">Fiche du produit ↗</span><span>Recette &amp; marge ↗</span></div></div>
  </div>`;
  return colspan ? `<tr class="dd-row"><td colspan="${colspan}" style="padding:0;border:none;text-align:left">${corps}</td></tr>` : corps;
}

/* ============================================================================================
   E — LE PONT : d'où vient la hausse, ce qui se développe, ce qui recule
   ============================================================================================ */
const E_OUV_G = 'Viennoiserie', E_HOV = 'Viennoiserie';
const E_OUV_P = 'Baguette Tradition 500 g.';
function tableEcarts(liste, { sous = null, ouvG = null, hov = null, nomTot = null, tot = null, cols = 'groupe' } = {}) {
  const max = Math.max(...liste.flatMap(o => [Math.abs(o.dV)].concat(o === ouvG ? sous(o).map(c => Math.abs(c.dV)) : [])));
  const row = (o, cls) => `<tr class="${cls}${hov && o.nom === hov ? ' hov' : ''}"><td class="nm"><span class="fl" style="display:inline-block;width:14px;color:var(--muted);font-size:10px">${sous && cls === 'g' ? (o === ouvG ? '▾' : '▸') : (cols === 'produit' ? '▸' : '')}</span>${cols === 'produit' ? esc(o.nom) + (o.surEnt ? '<span class="ent"> · entières</span>' : '') : `<span class="lien">${esc(o.nom)}</span>`}${hov && o.nom === hov ? '<span class="ouvrir">↗ ouvrir</span>' : ''}</td>
    <td>${fE(o.vA)}</td><td>${fE(o.vP)}</td><td style="width:34%">${barreEcart(o.dV, max)}</td><td class="ec ${clV(o)}">${fSE(o.dV)}</td><td class="ec ${clV(o)}">${o.etat ? '—' : fSP(o.dVp)}</td><td>${resTxt(o, true)}</td><td class="c">${vdTag(o)}</td><td>${spark2(o)}</td></tr>`;
  return `<table class="ecarts"><thead><tr><th>${cols === 'groupe' ? 'Groupe › catégorie' : 'Produit'}</th><th>${sem(0)} → ${sem(5)}</th><th>${sem(6)} → ${sem(11)}</th><th class="c">l’écart, en euros</th><th>Écart</th><th>%</th><th>Réseau</th><th class="c">Verdict</th><th>12 semaines</th></tr></thead><tbody>
    ${tot ? `<tr class="tot"><td>${nomTot}</td><td>${fE(tot.vA)}</td><td>${fE(tot.vP)}</td><td></td><td class="ec ${clV(tot)}">${fSE(tot.dV)}</td><td class="ec ${clV(tot)}">${fSP(tot.dVp)}</td><td>${resTxt(tot, true)}</td><td class="c">${vdTag(tot)}</td><td>${spark2(tot)}</td></tr>` : ''}
    ${liste.map(o => row(o, 'g') + (o === ouvG ? sous(o).map(c => row(c, 'k')).join('') : '')).join('')}</tbody></table>`;
}
function listeMv(L, { ouv, titre, cls }) {
  return `<div class="po-c"><div class="ct"><span class="lab ${cls}">${titre}</span><span class="aside">classés par écart en euros · un clic déplie le produit</span></div>
  <table class="mv"><thead><tr><th>Produit</th><th>Pièces / sem.</th><th>Écart</th><th>%</th><th>Réseau</th><th>12 semaines</th></tr></thead><tbody>${L.map(a => `<tr class="${a.nom === ouv ? 'ouv' : ''}"><td><span class="fl">${a.nom === ouv ? '▾' : '▸'}</span>${esc(a.nom)}<span class="sous">${esc(a.cat)}${a.surEnt ? ' · pièces entières' : ''}</span></td><td>${nf(a.qA / 6, 0)} → ${nf(a.qP / 6, 0)}</td><td class="ec ${clV(a)}">${fSE(a.dV)}</td><td class="ec ${clV(a)}">${fSP(a.surEnt ? a.dQp : a.dVp)}</td><td>${resTxt(a, true)}</td><td>${spark2(a, { w: 92 })}</td></tr>${a.nom === ouv ? `<tr><td colspan="6" style="padding:0;border:none">${deroulantDV(a, { etroit: true, largeur: 640 })}</td></tr>` : ''}`).join('')}</tbody></table></div>`;
}
const repMagasin = () => `<div class="dv-rep"><div class="k">Le magasin se développe-t-il ?</div><div class="gros ec ${clV(TOT)}">${fSP(TOT.dVp, 1)}</div>
  <div class="phr">Oui : <b style="font-weight:700">${fSE(TOT.dV)}</b> sur les 6 dernières semaines face aux 6 d’avant, ${resPhr(TOT)}.</div>
  <dl><dt>Par semaine</dt><dd>${fE(TOT.vA / 6)} → ${fE(TOT.vP / 6)}</dd><dt>Pièces par semaine</dt><dd>${fN(TOT.qA / 6)} → ${fN(TOT.qP / 6)}</dd><dt>Marge par semaine</dt><dd>${fE(TOT.mA / 6)} → ${fE(TOT.mP / 6)}</dd><dt>Marge, % des ventes</dt><dd>${fP(TOT.tA, 1)} → ${fP(TOT.tP, 1)}</dd></dl>
  <div class="s">${sem(IE)} en cours : ${fE(TOT.v[IE])} en ${W[IE].jours} jours.</div></div>`;
function corpsE({ hov = true } = {}) {
  const gs = GRPS.filter(g => g.qA + g.qP > 0);
  return `${entete()}${periodeDV()}${kpisDV()}
  <div class="po-c"><div class="ct"><span class="lab">Le magasin, semaine par semaine</span><span class="seg"><span class="on">Chiffre</span><span>Pièces</span><span>Marge</span></span><span class="aside">la ligne grise : ce que fait en moyenne un magasin du réseau</span></div>
    <div class="in dv-q"><div>${colsDev(TOT, { w: 1050, h: 290, bw: 50, res: RES_TOT })}</div>${repMagasin()}</div></div>
  <div class="po-c"><div class="ct"><span class="lab">D’où vient la hausse — ${fSE(TOT.dV)}, groupe par groupe</span><span class="seg"><span class="on">Groupes</span><span>Catégories</span></span><span class="aside">un clic sur un groupe ou une catégorie ouvre sa fiche</span></div>
    ${tableEcarts(gs.sort((x, y) => y.dV - x.dV), { sous: g => g.cats.filter(c => c.qA + c.qP > 0).sort((x, y) => y.dV - x.dV), ouvG: grp(E_OUV_G), hov: hov ? E_HOV : null, nomTot: 'Le magasin', tot: TOT })}</div>
  <div class="deux">${listeMv(MONTENT.slice(0, 9), { ouv: E_OUV_P, titre: `▲ Ce qui se développe — ${MONTENT.length} produits`, cls: 'up' })}${listeMv(BAISSENT.slice(0, 12), { ouv: null, titre: `▼ Ce qui recule — ${BAISSENT.length} produits`, cls: 'dn' })}</div>
  <div class="plus">${compte(ARTS, 'nouv')} produits nouveaux · ${compte(ARTS, 'arr')} arrêtés · ${ARTS.filter(a => !a.etat && a.qA + a.qP < 24).length} trop peu vendus pour juger ▾</div>
  ${NOTE}`;
}
function modaleE(g) {
  const prods = g.liste.filter(a => !a.etat && a.qA + a.qP >= 24).sort((x, y) => y.dV - x.dV);
  return `<div class="m-hd"><div class="t"><div class="kick">Groupe</div><h1>${esc(g.nom)}</h1><div class="s">${g.cats.length} catégories · ${g.n} références · ${PERIODE_DV} · ${esc(R.magasin)}</div></div><span class="x">✕</span></div>
  <div class="m-bd"><div class="dv-q" style="grid-template-columns:minmax(0,1fr) 300px"><div>${colsDev(g, { w: 820, h: 250, bw: 40, res: g.res })}</div>
    <div class="dv-rep"><div class="k">Se développe-t-il ?</div><div class="gros ec ${clV(g)}">${fSP(g.dVp, 1)}</div><div class="phr">${vdTag(g)} ${fSE(g.dV)} en 6 semaines, ${resPhr(g)}. Il fait ${fP(100 * g.dV / TOT.dV, 0)} de la hausse du magasin.</div>
    <dl><dt>Par semaine</dt><dd>${fE(g.vA / 6)} → ${fE(g.vP / 6)}</dd><dt>Pièces par semaine</dt><dd>${fN(g.qA / 6)} → ${fN(g.qP / 6)}</dd><dt>Marge, % des ventes</dt><dd>${fP(g.tA, 1)} → ${fP(g.tP, 1)}</dd></dl></div></div>
    <div class="m-sec">Ses catégories</div>
    ${tableEcarts(g.cats.filter(c => c.qA + c.qP > 0).sort((x, y) => y.dV - x.dV), { cols: 'groupe' }).replace('Groupe › catégorie', 'Catégorie')}
    <div class="m-sec">Ses produits <span class="aside">${prods.length} jugés · classés par écart · un clic déplie le produit</span></div>
    ${tableEcarts(prods.slice(0, 12), { cols: 'produit' })}
  </div>`;
}

/* ============================================================================================
   F — LES COURBES : chaque catégorie sur sa courbe, face au réseau
   ============================================================================================ */
const F_HOV = 'Sandwiches garnis';
function carteC(c) {
  return `<div class="mc${c.nom === F_HOV ? ' hov' : ''}"><span class="go">↗</span><div class="kk">${esc(c.grp)}</div><div class="n">${esc(c.nom)}</div>
    <div class="row"><span class="v ec ${clV(c)}">${c.etat ? '—' : fSP(c.dVp)}</span>${vdTag(c)}</div>
    <div class="s">${fE(c.vA / 6)} → ${fE(c.vP / 6)} / sem. · ${resTxt(c)}</div>
    <div style="margin-top:6px">${indice(c, { w: 300, h: 74, mini: true, dom: [40, 180] })}</div></div>`;
}
function corpsF() {
  const cs = CATS.filter(c => c.qA + c.qP >= 24 && c.V >= 250);
  const bloc = (k, t) => { const L = cs.filter(c => c.vd[0] === k).sort((x, y) => y.dV - x.dV); return L.length ? `<div class="grp-t"><span class="vd ${k}">${t}</span> ${L.length} catégorie${L.length > 1 ? 's' : ''} · ${fSE(somme(L.map(c => c.dV)))}</div><div class="mult">${L.map(carteC).join('')}</div>` : ''; };
  const top = MONTENT.slice(0, 5).concat(BAISSENT.slice(0, 5));
  return `${entete()}${periodeDV()}${kpisDV()}
  <div class="po-c"><div class="ct"><span class="lab">Le magasin face au réseau — indice 100 = la moyenne de ${sem(0)} → ${sem(5)}</span><span class="seg"><span class="on">Chiffre</span><span>Pièces</span></span><span class="aside">au-dessus de 100 : le magasin vend plus qu’avant</span></div>
    <div class="in dv-q"><div>${indice(TOT, { w: 1050, h: 250, res: RES_TOT })}${legIndice}</div>${repMagasin()}</div></div>
  <div class="po-c"><div class="ct"><span class="lab">Chaque catégorie sur sa courbe</span><span class="seg"><span class="on">Toutes</span><span>Se développent</span><span>Stables</span><span>Reculent</span></span><span class="aside">même échelle partout (indice 40 → 180) · trait rouge : ${esc(R.magasin)} · trait gris : le réseau · un clic ouvre la catégorie</span></div>
    <div class="in">${bloc('up', '▲ se développent')}${bloc('st', '= stables')}${bloc('dn', '▼ reculent')}<div class="plus">${CATS.length - cs.length} petites catégories (moins de 250 € en 12 semaines), nouvelles ou arrêtées ▾</div></div></div>
  <div class="po-c"><div class="ct"><span class="lab">Les produits qui bougent le plus</span><span class="aside">5 qui montent, 5 qui reculent · un clic déplie le produit</span></div>
    ${tableEcarts(top, { cols: 'produit' })}</div>
  ${NOTE}`;
}
function modaleF(c) {
  const prods = c.liste.filter(a => a.qA + a.qP > 0).sort((x, y) => y.dV - x.dV);
  const ouv = prods.find(a => !a.etat && a.qA + a.qP >= 24 && a.dRp != null && a.vd[0] === 'up') || prods[0];
  const COLS = 7;
  return `<div class="m-hd"><div class="t"><div class="kick">${esc(c.grp)} ›</div><h1>${esc(c.nom)}</h1><div class="s">${c.n} références · ${PERIODE_DV} · ${esc(R.magasin)}</div></div><span class="x">✕</span></div>
  <div class="m-bd"><div class="dv-q" style="grid-template-columns:minmax(0,1fr) 300px"><div>${indice(c, { w: 820, h: 230, res: c.res })}${legIndice}</div>
    <div class="dv-rep"><div class="k">Se développe-t-elle ?</div><div class="gros ec ${clV(c)}">${fSP(c.dVp, 1)}</div><div class="phr">${vdTag(c)} ${fSE(c.dV)} en 6 semaines, ${resPhr(c)}.</div>
    <dl><dt>Par semaine</dt><dd>${fE(c.vA / 6)} → ${fE(c.vP / 6)}</dd><dt>Pièces par semaine</dt><dd>${fN(c.qA / 6)} → ${fN(c.qP / 6)}</dd><dt>Marge, % des ventes</dt><dd>${fP(c.tA, 1)} → ${fP(c.tP, 1)}</dd></dl></div></div>
    <div class="m-sec">Ses produits, chacun sur sa courbe <span class="aside">classés par écart · un clic déplie le produit</span></div>
    <table class="mv" style="font-size:12.5px"><thead><tr><th>Produit</th><th>Pièces / sem.</th><th>Écart</th><th>%</th><th>Réseau</th><th>Verdict</th><th style="width:230px">indice 100 = avant</th></tr></thead><tbody>
    ${prods.slice(0, 11).map(a => `<tr class="${a === ouv ? 'ouv' : ''}"><td><span class="fl">${a === ouv ? '▾' : '▸'}</span>${esc(a.nom)}</td><td>${nf(a.qA / 6, 0)} → ${nf(a.qP / 6, 0)}</td><td class="ec ${clV(a)}">${fSE(a.dV)}</td><td class="ec ${clV(a)}">${a.etat ? '—' : fSP(a.dVp)}</td><td>${resTxt(a, true)}</td><td>${vdTag(a)}</td><td>${a.etat || a.qA === 0 ? spark2(a, { w: 220 }) : indice(a, { w: 220, h: 34, mini: true, res: a.resQ, cle: 'q', dom: [0, 220] })}</td></tr>${a === ouv ? deroulantDV(a, { colspan: COLS, etroit: true }) : ''}`).join('')}</tbody></table>
  </div>`;
}

/* ============================================================================================
   G — LA CARTE ET LE TABLEAU DU DÉVELOPPEMENT
   ============================================================================================ */
const G_OUV_G = 'Viennoiserie', G_OUV_C = 'Viennoiserie Ind.', G_OUV_P = 'Baulus', G_HOV = 'Boissons froides';
function carteDev(liste, { w = 860, h = 470, focus = null } = {}) {
  const m = { l: 56, r: 20, t: 18, b: 50 };
  const pts = liste.filter(c => !c.etat && c.dVp != null && c.V > 0);
  const vx = pts.map(c => c.V), lx0 = Math.log10(Math.min(...vx) * .8), lx1 = Math.log10(Math.max(...vx) * 1.25);
  const x = v => m.l + (w - m.l - m.r) * (Math.log10(v) - lx0) / (lx1 - lx0);
  const Y0 = -50, Y1 = 60, y = p => m.t + (h - m.t - m.b) * (1 - (Math.max(Y0, Math.min(Y1, p)) - Y0) / (Y1 - Y0));
  const r = c => 4 + 18 * Math.sqrt(c.V / Math.max(...vx));
  const seuilX = TOT.V * 0.02;
  let g = `<rect x="${x(seuilX)}" y="${m.t}" width="${w - m.r - x(seuilX)}" height="${y(0) - m.t}" fill="#F3F8F4"/><rect x="${x(seuilX)}" y="${y(0)}" width="${w - m.r - x(seuilX)}" height="${h - m.b - y(0)}" fill="#FBF3E8"/>`;
  for (let p = -40; p <= 60; p += 20) { g += `<line x1="${m.l}" x2="${w - m.r}" y1="${y(p)}" y2="${y(p)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${y(p) + 3.5}" text-anchor="end">${p > 0 ? '+' : (p < 0 ? '−' : '')}${Math.abs(p)} %</text>`; }
  [100, 300, 1000, 3000, 10000, 30000].forEach(v => { if (x(v) < m.l || x(v) > w - m.r) { return; } g += `<line x1="${x(v)}" x2="${x(v)}" y1="${m.t}" y2="${h - m.b}" stroke="var(--po-grille)"/><text class="ax" x="${x(v)}" y="${h - m.b + 15}" text-anchor="middle">${fAx(v)} €</text>`; });
  g += `<line x1="${m.l}" x2="${w - m.r}" y1="${y(0)}" y2="${y(0)}" stroke="var(--ink)" stroke-width="1"/><line x1="${m.l}" x2="${w - m.r}" y1="${y(TOT.dVp)}" y2="${y(TOT.dVp)}" stroke="var(--accent)" stroke-dasharray="4 4"/><text class="axs" x="${m.l + 6}" y="${y(TOT.dVp) - 5}" style="fill:var(--accent)">le magasin ${fSP(TOT.dVp)}</text>`;
  g += `<line x1="${x(seuilX)}" x2="${x(seuilX)}" y1="${m.t}" y2="${h - m.b}" stroke="var(--muted-2)" stroke-dasharray="4 4"/><text class="axs" x="${x(seuilX) + 5}" y="${h - m.b - 6}" style="fill:var(--muted-2)">2 % du chiffre</text>`;
  const ql = (t, xx, yy, anc) => `<text x="${xx}" y="${yy}" text-anchor="${anc}" style="font-size:12px;fill:var(--ink)">${t}</text>`;
  g += ql('Les moteurs', w - m.r - 8, m.t + 16, 'end') + ql('À surveiller', w - m.r - 8, h - m.b - 8, 'end') + ql('Ça démarre', m.l + 8, m.t + 16, 'start') + ql('S’essouffle', m.l + 8, h - m.b - 8, 'start');
  const etiq = [];
  [...pts].sort((p, q) => r(q) - r(p)).forEach(c => { const on = !focus || focus(c), cx = x(c.V), cy = y(c.dVp);
    g += `<circle cx="${cx.toFixed(1)}" cy="${cy.toFixed(1)}" r="${r(c).toFixed(1)}" fill="${on ? (c.dVp >= 0 ? 'rgba(63,122,82,.28)' : 'rgba(176,121,42,.30)') : 'rgba(184,178,168,.22)'}" stroke="${on ? (c.dVp >= 0 ? '#3f7a52' : '#B0792A') : '#c9c2b8'}" stroke-width="1.2"><title>${esc(c.nom)} · ${fE(c.V)} sur 12 semaines · ${fSP(c.dVp)} · réseau ${fSP(c.dRp)}</title></circle>`;
    if (c.dVp < -50 || c.dVp > 60) { g += `<text class="axs" x="${cx.toFixed(1)}" y="${(cy + (c.dVp > 0 ? -r(c) - 3 : r(c) + 10)).toFixed(1)}" text-anchor="middle">${fSP(c.dVp)}</text>`; }
    if (on && (c.V >= seuilX * .5 || focus)) { etiq.push({ c, cx, cy, rr: r(c) }); } });
  // étiquettes : à droite du rond, sinon à gauche, sinon dessus ou dessous, sans se chevaucher
  const pris = pts.map(c => { const cx = x(c.V), cy = y(c.dVp), rr = r(c); return [cx - rr, cy - rr, cx + rr, cy + rr]; });
  etiq.sort((p, q) => q.c.V - p.c.V).forEach(e => { const t = e.c.nom, lw = t.length * 6 + 4;
    const essais = [[e.cx + e.rr + 4, e.cy + 4, 'start'], [e.cx - e.rr - 4, e.cy + 4, 'end'], [e.cx - lw / 2, e.cy - e.rr - 5, 'start'], [e.cx - lw / 2, e.cy + e.rr + 13, 'start'], [e.cx + e.rr + 4, e.cy - e.rr - 2, 'start'], [e.cx - e.rr - 4, e.cy + e.rr + 10, 'end'], [e.cx + e.rr + 4, e.cy + e.rr + 10, 'start'], [e.cx - e.rr - 4, e.cy - e.rr - 2, 'end']];
    const soi = [e.cx - e.rr, e.cy - e.rr, e.cx + e.rr, e.cy + e.rr];
    for (const [lx, ly, anc] of essais) { const bx = anc === 'end' ? lx - lw : lx, box = [bx, ly - 11, bx + lw, ly + 3];
      if (box[0] < m.l || box[2] > w - m.r || pris.some(b => b.join() !== soi.join() && !(box[2] < b[0] || box[0] > b[2] || box[3] < b[1] || box[1] > b[3]))) { continue; }
      pris.push(box); g += `<text class="lab" x="${lx.toFixed(1)}" y="${ly.toFixed(1)}" text-anchor="${anc}">${esc(t)}</text>`; break; } });
  g += `<text class="ax" x="${(m.l + w - m.r) / 2}" y="${h - 8}" text-anchor="middle" style="fill:var(--muted-2)">le poids : chiffre sur 12 semaines (échelle logarithmique) →</text><text class="ax" transform="translate(13 ${(m.t + h - m.b) / 2}) rotate(-90)" text-anchor="middle" style="fill:var(--muted-2)">le développement : 6 dernières sem. face aux 6 d’avant →</text>`;
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Chaque catégorie : son poids et son développement">${g}</svg>`;
}
function tableDev({ ouvG, ouvC, ouvP, hov }) {
  const COLS = 10, max = Math.max(...GRPS.map(g => Math.abs(g.dV)));
  const mini = d => `<div class="div" style="min-width:120px">${barreEcart(d, max).replace('<div class="div">', '').replace(/<\/div>$/, '')}</div>`;
  const cells = o => `<td>${fE(o.vA / 6)}</td><td>${fE(o.vP / 6)}</td><td style="width:140px">${mini(o.dV)}</td><td class="ec ${clV(o)}">${fSE(o.dV)}</td><td class="ec ${clV(o)}">${o.etat ? '—' : fSP(o.surEnt ? o.dQp : o.dVp)}</td><td>${resTxt(o, true)}</td><td>${o.tA != null && o.tP != null ? `${nf(o.tA, 0)} → ${nf(o.tP, 0)} %` : '—'}</td><td>${spark2(o)}</td><td class="c">${vdTag(o)}</td>`;
  const lp = a => `<tr class="p${a.nom === ouvP ? ' ouv' : ''}"><td><span class="fl">${a.nom === ouvP ? '▾' : '▸'}</span>${esc(a.nom)}${a.surEnt ? '<span class="ent"> · entières</span>' : ''}</td>${cells(a)}</tr>${a.nom === ouvP ? deroulantDV(a, { colspan: COLS }) : ''}`;
  const lc = c => `<tr class="k${c.nom === hov ? ' hov' : ''}"><td><span class="fl">${c.nom === ouvC ? '▾' : '▸'}</span><span class="lien">${esc(c.nom)}</span>${c.nom === hov ? '<span class="ouvrir">↗ ouvrir</span>' : ''}</td>${cells(c)}</tr>${c.nom === ouvC ? c.liste.filter(a => a.qA + a.qP > 0).sort((x, y) => y.dV - x.dV).map(lp).join('') : ''}`;
  const lg = g => `<tr class="g"><td><span class="fl">${g.nom === ouvG || g.cats.some(c => c.nom === hov) ? '▾' : '▸'}</span>${esc(g.nom)}</td>${cells(g)}</tr>${g.nom === ouvG || g.cats.some(c => c.nom === hov) ? g.cats.filter(c => c.qA + c.qP > 0).sort((x, y) => y.dV - x.dV).map(lc).join('') : ''}`;
  return `<table class="tb"><thead><tr><th>Groupe › catégorie › produit</th><th>Avant / sem.</th><th>Après / sem.</th><th style="text-align:center">l’écart</th><th class="on">Écart ▾</th><th>%</th><th>Réseau</th><th>Marge %</th><th>12 semaines</th><th style="text-align:center">Verdict</th></tr></thead><tbody>
  ${GRPS.filter(g => g.qA + g.qP > 0).sort((x, y) => y.dV - x.dV).map(lg).join('')}
  <tr class="tot"><td>Le magasin</td>${cells(TOT)}</tr></tbody></table>`;
}
function corpsG() {
  const quad = (t, f, cls) => { const L = CATS.filter(c => !c.etat && c.dVp != null && c.qA + c.qP >= 24).filter(f).sort((x, y) => y.V - x.V); return `<div class="qt"><div class="k">${L.length} catégories</div><div class="n ${cls}">${t}</div><div class="s">${L.slice(0, 5).map(c => esc(c.nom) + ' <span class="ec ' + clV(c) + '">' + fSP(c.dVp) + '</span>').join(' · ')}${L.length > 5 ? ' …' : ''}</div></div>`; };
  const gros = c => c.V >= TOT.V * 0.02;
  return `${entete()}${periodeDV()}${kpisDV()}
  <div class="po-c"><div class="ct"><span class="lab">Où ça se développe — chaque catégorie : son poids et son développement</span><span class="seg"><span class="on">Catégories</span><span>Produits</span></span><span class="aside">un rond par catégorie, sa taille : son chiffre · vert : en hausse, ocre : en baisse · hors échelle : la valeur est écrite · un clic ouvre la catégorie</span></div>
    <div class="in" style="display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:18px;align-items:start"><div>${carteDev(CATS, { w: 900, h: 480 })}</div>
      <div><div class="dv-rep" style="margin-bottom:10px"><div class="k">Le magasin</div><div class="gros ec ${clV(TOT)}">${fSP(TOT.dVp, 1)}</div><div class="phr">${fSE(TOT.dV)} en 6 semaines, ${resPhr(TOT)}.</div>${colsDev(TOT, { w: 370, h: 110, bw: 18, lignesMoy: false, mini: true }).replace(/<div class="leg">[\s\S]*<\/div>$/, '')}</div>
      <div class="quad">${quad('Les moteurs', c => gros(c) && c.vd[0] === 'up', 'ec up')}${quad('À surveiller', c => gros(c) && c.vd[0] !== 'up', 'ec dn')}${quad('Ça démarre', c => !gros(c) && c.vd[0] === 'up', 'ec up')}${quad('S’essouffle', c => !gros(c) && c.vd[0] === 'dn', 'ec dn')}</div></div></div></div>
  <div class="po-c"><div class="ct"><span class="lab">Le tableau du développement — groupe › catégorie › produit</span><span class="recherche">chercher un produit</span><span class="seg"><span class="on">Tout</span><span>Se développe</span><span>Recule</span><span>Plus lent que le réseau</span></span><span class="aside">classé par écart en euros · un clic sur une catégorie ouvre sa fiche · un clic sur un produit le déplie</span></div>
    ${tableDev({ ouvG: G_OUV_G, ouvC: G_OUV_C, ouvP: G_OUV_P, hov: G_HOV })}</div>
  ${NOTE}`;
}
function modaleG(c) {
  const ids = new Set(c.liste.map(a => a.id));
  const prods = c.liste.filter(a => a.qA + a.qP > 0).sort((x, y) => y.dV - x.dV);
  return `<div class="m-hd"><div class="t"><div class="kick">${esc(c.grp)} ›</div><h1>${esc(c.nom)}</h1><div class="s">${c.n} références · ${PERIODE_DV} · ${esc(R.magasin)}</div></div><span class="x">✕</span></div>
  <div class="m-bd"><div class="dv-q" style="grid-template-columns:minmax(0,1fr) 300px"><div>${colsDev(c, { w: 820, h: 240, bw: 40, res: c.res })}</div>
    <div class="dv-rep"><div class="k">Se développe-t-elle ?</div><div class="gros ec ${clV(c)}">${fSP(c.dVp, 1)}</div><div class="phr">${vdTag(c)} ${fSE(c.dV)} en 6 semaines, ${resPhr(c)}.</div>
    <dl><dt>Par semaine</dt><dd>${fE(c.vA / 6)} → ${fE(c.vP / 6)}</dd><dt>Pièces par semaine</dt><dd>${fN(c.qA / 6)} → ${fN(c.qP / 6)}</dd><dt>Marge, % des ventes</dt><dd>${fP(c.tA, 1)} → ${fP(c.tP, 1)}</dd></dl></div></div>
    <div class="m-sec">Ses produits <span class="aside">classés par écart · un clic déplie le produit</span></div>
    ${tableEcarts(prods.slice(0, 12), { cols: 'produit' })}
  </div>`;
}

/* ---------- Écriture ---------- */
const ecrire = (f, s) => fs.writeFileSync(path.join(D, f), s);
const T = 'Onglet Produits, le développement — maquette ';
ecrire('e.html', pageDV('E · le pont', T + 'E', `<div id="dash">${corpsE()}</div>`));
ecrire('e-modale.html', pageModaleDV('E · le pont — la modale d’un groupe', T + 'E, modale', corpsE({ hov: false }), modaleE(grp(E_HOV))));
ecrire('f.html', pageDV('F · les courbes', T + 'F', `<div id="dash">${corpsF()}</div>`));
ecrire('f-modale.html', pageModaleDV('F · les courbes — la modale catégorie', T + 'F, modale', corpsF(), modaleF(cat(F_HOV))));
ecrire('g.html', pageDV('G · la carte et le tableau', T + 'G', `<div id="dash">${corpsG()}</div>`));
ecrire('g-modale.html', pageModaleDV('G · la carte et le tableau — la modale catégorie', T + 'G, modale', corpsG(), modaleG(cat(G_HOV))));
const PL = [
  ['E', 'Le pont', 'D’où vient la hausse du magasin, groupe par groupe, en euros ; dessous, deux listes : ce qui se développe, ce qui recule.', ['<b>Répond en euros</b> : qui porte la hausse, qui la freine', 'Les deux listes disent quoi regarder en premier', 'La courbe d’une catégorie est dans sa fiche, pas à l’écran']],
  ['F', 'Les courbes', 'Chaque catégorie sur sa petite courbe (indice 100 = avant), face au réseau ; rangées en « se développent », « stables », « reculent ».', ['<b>Se voit d’un coup d’œil</b> : la pente de chaque catégorie', 'Le réseau à côté : vraie hausse ou saison ?', 'Les montants en euros passent au second plan']],
  ['G', 'La carte et le tableau', 'Les catégories placées selon leur poids et leur développement (moteurs, à surveiller, ça démarre, s’essouffle) ; le tableau groupe › catégorie › produit dessous.', ['<b>Le plus complet</b> : le poids, l’écart, le réseau, la marge', 'Le tableau reprend la liste de « Ventes par catégorie »', 'La carte demande une explication la première fois']]
];
ecrire('planche.html', `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Onglet Produits — est-ce que ça se développe ?</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="../onglet-produits/po.css"><link rel="stylesheet" href="dv.css"><style>.planche{grid-template-columns:repeat(3,minmax(0,1fr))}</style></head><body>
<div style="padding:22px 24px 0"><div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)">Dashboard magasin · ${esc(R.magasin)} · ${PERIODE_DV}</div><div style="font-size:26px;letter-spacing:-.01em;margin-top:4px">L’onglet Produits — est-ce que la catégorie, le produit se développe ?</div><div style="font-size:12.5px;color:var(--muted-2);margin-top:6px">Le magasin : ${fSP(TOT.dVp, 1)} (${fSE(TOT.dV)}) sur les 6 dernières semaines face aux 6 d’avant ; un magasin du réseau : ${fSP(TOT.dRp, 1)}. Un clic sur une catégorie ouvre sa fiche, un clic sur un produit le déplie : prix, quantités, marge, avant et après.</div></div>
<div class="planche">${PL.map(([l, t, p, li]) => `<div class="pl-c"><div class="hd"><div class="l">Maquette ${l}</div><h2>${t}</h2><p>${p}</p></div><ul>${li.map(x => `<li>${x}</li>`).join('')}</ul><img src="${l.toLowerCase()}-haut.jpg" alt=""><img src="${l.toLowerCase()}-modale-haut.jpg" alt=""></div>`).join('')}</div></body></html>`);

console.log('écrit : e, f, g (+ modales) et planche · magasin', fSP(TOT.dVp, 1), fSE(TOT.dV), '· réseau', fSP(TOT.dRp, 1),
  '· catégories up/st/dn', ['up', 'st', 'dn'].map(k => compte(CATS.filter(c => c.qA + c.qP >= 24), k)).join('/'),
  '· produits up/st/dn', ['up', 'st', 'dn'].map(k => compte(juge, k)).join('/'), '· nouveaux', compte(ARTS, 'nouv'), 'arrêtés', compte(ARTS, 'arr'),
  '· rattachés', RATTACHES.length, '· ouvert E', E_OUV_P);
console.log('montent', MONTENT.slice(0, 9).map(a => a.nom + ' ' + fSE(a.dV) + ' ' + fSP(a.dVp) + ' rés ' + fSP(a.dRp)).join(' | '));
console.log('baissent', BAISSENT.slice(0, 12).map(a => a.nom + ' ' + fSE(a.dV) + ' ' + fSP(a.dVp) + ' rés ' + fSP(a.dRp)).join(' | '));
