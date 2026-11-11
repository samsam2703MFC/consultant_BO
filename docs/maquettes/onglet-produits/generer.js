/* Maquettes « l'onglet Produits » du dashboard magasin (demande du 11/11/2026) :
 *   un onglet qui dit quels produits je vends, combien, à quel prix et à quelle marge ;
 *   un graphique sur 12 semaines ; un clic sur une catégorie ouvre une modale ;
 *   un clic sur un produit ouvre une liste déroulante : prix de vente, quantités, marge…
 * Quatre directions, chiffres réels d'Atelier by - Halle (reel-halle.json, lu par extraire.js) :
 *   A — le tableau qui se déplie      a.html, a-modale.html
 *   B — les tuiles de catégorie       b.html, b-modale.html
 *   C — la carte volume × marge       c.html, c-modale.html
 *   D — la grille semaine par semaine d.html, d-modale.html
 *   node docs/maquettes/onglet-produits/generer.js */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = JSON.parse(fs.readFileSync(path.join(D, 'reel-halle.json'), 'utf8'));

/* ---------- Formats ---------- */
const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fN = n => nf(Math.round(n));
const fE = (n, d = 0) => nf(n, d) + ' €';
const fP = (n, d = 1) => nf(n, d) + ' %';
const fT = n => n == null || !isFinite(n) ? '—' : (n > 0.5 ? '▲ ' : (n < -0.5 ? '▼ ' : '= ')) + nf(Math.abs(n), 0) + ' %';
const tendC = o => o.etat ? `<span class="tag ${o.etat.startsWith('nouveau') ? 'ok' : 'mu'}" style="margin:0">${o.etat}</span>` : `<span class="${clT(o.tend)}">${fT(o.tend)}</span>`;
const clT = n => n == null || !isFinite(n) ? 'mu' : (n > 0.5 ? 'up' : (n < -0.5 ? 'dn' : 'mu'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const somme = a => a.reduce((x, y) => x + (y || 0), 0);

/* ---------- Les semaines : 12 closes (S34 à S45) et la semaine en cours (S46) ---------- */
const W = R.semaines, N = W.length, IE = N - 1, IC = [...Array(N - 1).keys()];
const sem = i => W[i].s;
const dCourt = s => s.slice(8, 10) + '/' + s.slice(5, 7);
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const dLong = s => +s.slice(8, 10) + ' ' + MOIS[+s.slice(5, 7) - 1];
const J12 = somme(IC.map(i => W[i].jours));
const PERIODE = `${sem(0)} → ${sem(IE - 1)} · ${dLong(W[0].du)} → ${dLong(W[IE - 1].au)}`;
const sur12 = a => somme(IC.map(i => a[i]));

/* ---------- Le coût : celui de la recette actuelle, pour les 13 semaines ----------
 * Les coûts par produit que /ventes/stats rend pour S34 à S39 sont gonflés (entremets × 15, croûtes × 20,
 * pains + 30 %) : 8 541 € de matière en S34 pour 4 629 € au P&L. Depuis S41 ils collent au P&L (± 2 %).
 * Chaque produit prend donc le coût unitaire de ses ventes S41 → S46 (= le coût de sa recette actuelle),
 * sinon le coût de la fiche ; sans l'un ni l'autre, il reste « sans coût ». */
const RECENT = [...Array(N).keys()].slice(-6);
R.produits.forEach(p => {
  const q = somme(RECENT.map(i => p.c[i] > 0 ? p.q[i] : 0)), c = somme(RECENT.map(i => p.c[i]));
  let u = q > 0 && c > 0 ? c / q : null;
  if (u == null && !p.pere && p.fiche && p.fiche.cout) { u = p.fiche.cout; }
  if (u == null && somme(p.c) === 0) { return; }
  if (u == null) { const q0 = somme(p.q.map((x, i) => p.c[i] > 0 ? x : 0)); u = q0 ? somme(p.c) / q0 : 0; }
  p.cLu = p.c; p.c = p.q.map(x => +(x * u).toFixed(2)); p.cu = u;
});

/* ---------- Les articles : une ligne par produit, ses portions rangées dessous ---------- */
const catGrp = new Map(R.categories.map(c => [c.nom, c.groupe]));
const A = new Map();
R.produits.forEach(p => {
  const k = p.pere || p.id;
  if (!A.has(k)) { A.set(k, { id: k, nom: null, cat: p.cat, fiche: null, fmts: [] }); }
  const a = A.get(k);
  const fmt = p.pere ? ((p.nom.match(/—\s*(1\/\d)\s*$/) || [])[1] || 'portion') : ((p.nom.match(/^(1\/[24])\b/) || [])[1] || 'entière');
  if (!p.pere) { a.nom = p.nom; a.cat = p.cat; a.fiche = p.fiche || a.fiche; } else { a.fiche = a.fiche || p.fiche; if (!a.nom) { a.nomP = p.nom.replace(/\s*—\s*1\/\d\s*$/, ''); } }
  a.fmts.push({ fmt, q: p.q, v: p.v, c: p.c });
});
function chiffrer(o) {          // o.q, o.v, o.c par semaine → la marge, seulement là où le coût est connu
  o.vc = o.v.map((v, i) => o.c[i] > 0 ? v : 0);
  o.m = o.v.map((v, i) => o.c[i] > 0 ? v - o.c[i] : 0);
  o.Q = sur12(o.q); o.V = sur12(o.v); o.C = sur12(o.c); o.VC = sur12(o.vc); o.M = sur12(o.m);
  o.taux = o.VC > 0 ? 100 * o.M / o.VC : null;
  o.vSans = o.V - o.VC;
  const av = somme(IC.slice(0, 6).map(i => o.q[i])), ap = somme(IC.slice(6).map(i => o.q[i]));
  o.tend = av > 0 ? 100 * (ap - av) / av : null;
  o.etat = null;
  if (ap > 0 && av < 0.15 * ap) { const r = ap / 6; o.etat = 'nouveau · ' + sem(IC.find(i => o.q[i] >= 0.25 * r)); }
  else if (av > 0 && ap < 0.15 * av) { const r = av / 6; o.etat = 'arrêté · ' + sem([...IC].reverse().find(i => o.q[i] >= 0.25 * r)); }
  o.prix = o.Q > 0 ? o.V / o.Q : null;
  return o;
}
const ARTS = [...A.values()].map(a => {
  a.nom = a.nom || a.nomP; a.grp = catGrp.get(a.cat) || a.cat;
  a.q = Array(N).fill(0); a.v = Array(N).fill(0); a.c = Array(N).fill(0);
  a.fmts.forEach(f => { for (let i = 0; i < N; i++) { a.q[i] += f.q[i]; a.v[i] += f.v[i]; a.c[i] += f.c[i]; } chiffrer(f); });
  a.fmts.sort((x, y) => ({ 'entière': 0, '1/2': 1, '1/4': 2 }[x.fmt] ?? 3) - ({ 'entière': 0, '1/2': 1, '1/4': 2 }[y.fmt] ?? 3));
  return chiffrer(a);
}).filter(a => a.Q > 0 && a.grp !== 'Sans catégorie');
const agr = (liste, extra) => { const o = { ...extra, n: liste.length, liste, q: Array(N).fill(0), v: Array(N).fill(0), c: Array(N).fill(0) };
  liste.forEach(a => { for (let i = 0; i < N; i++) { o.q[i] += a.q[i]; o.v[i] += a.v[i]; o.c[i] += a.c[i]; } });
  chiffrer(o);
  // la marge d'un ensemble : celle de ses articles (chacun seulement sur ses semaines chiffrées)
  o.m = Array(N).fill(0); o.vc = Array(N).fill(0); liste.forEach(a => { for (let i = 0; i < N; i++) { o.m[i] += a.m[i]; o.vc[i] += a.vc[i]; } });
  o.M = sur12(o.m); o.VC = sur12(o.vc); o.taux = o.VC > 0 ? 100 * o.M / o.VC : null; o.vSans = o.V - o.VC;
  o.neg = liste.filter(a => a.M < 0).length; o.sans = liste.filter(a => a.VC === 0).length;
  return o; };
const TOT = agr(ARTS, { nom: 'Le magasin' });
const parV = (x, y) => y.V - x.V;
const CATS = [...new Set(ARTS.map(a => a.cat))].map(c => agr(ARTS.filter(a => a.cat === c).sort(parV), { nom: c, grp: catGrp.get(c) || c })).sort(parV);
const GRPS = [...new Set(ARTS.map(a => a.grp))].map(g => { const cs = CATS.filter(c => c.grp === g); return agr(ARTS.filter(a => a.grp === g).sort(parV), { nom: g, cats: cs }); }).sort(parV);
const rangM = [...ARTS].filter(a => a.VC > 0).sort((x, y) => y.M - x.M);
rangM.forEach((a, k) => { a.rang = k + 1; });
const art = nom => ARTS.find(a => a.nom === nom);
const cat = nom => CATS.find(c => c.nom === nom);
const grp = nom => GRPS.find(g => g.nom === nom);

/* ---------- Les graphiques (SVG ; marques fines, une seule échelle, survol par <title>) ---------- */
function echelle(max, n = 4) { const brut = max / n, p = Math.pow(10, Math.floor(Math.log10(brut))), pas = [1, 2, 2.5, 5, 10].map(x => x * p).find(x => x >= brut); const t = []; for (let v = 0; v <= max + 1e-9 || t.length < 2; v += pas) { t.push(v); } if (t[t.length - 1] < max) { t.push(t[t.length - 1] + pas); } return t; }
const fAx = v => v >= 1000 ? nf(v / 1000, v % 1000 ? 1 : 0) + ' k' : nf(v, 0);
const motifs = `<defs><pattern id="hc" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#F3E7D4"/><line x1="0" y1="0" x2="0" y2="6" stroke="#E4C9A0" stroke-width="2.4"/></pattern>
  <pattern id="hm" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#D5E6DA"/><line x1="0" y1="0" x2="0" y2="6" stroke="#3f7a52" stroke-width="2.4"/></pattern>
  <pattern id="hr" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#F4E4E7"/><line x1="0" y1="0" x2="0" y2="6" stroke="#B03048" stroke-width="2.4"/></pattern>
  <pattern id="hs" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#fff"/><line x1="0" y1="0" x2="0" y2="6" stroke="#d9d2c8" stroke-width="2"/></pattern></defs>`;
const seg = (x, y0, w, h, fill, rond, titre) => h <= 0.4 ? '' : (rond
  ? `<path d="M${x.toFixed(1)},${y0.toFixed(1)} v${(-h + Math.min(4, h)).toFixed(1)} q0,${-Math.min(4, h)} ${Math.min(4, h)},${-Math.min(4, h)} h${(w - 2 * Math.min(4, h)).toFixed(1)} q${Math.min(4, h)},0 ${Math.min(4, h)},${Math.min(4, h)} v${(h - Math.min(4, h)).toFixed(1)} z" fill="${fill}"><title>${titre}</title></path>`
  : `<rect x="${x.toFixed(1)}" y="${(y0 - h).toFixed(1)}" width="${w.toFixed(1)}" height="${h.toFixed(1)}" fill="${fill}"><title>${titre}</title></rect>`);

/* Colonnes empilées : coût matière + marge brute (+ ventes sans coût) = chiffre de la semaine */
function colonnesMarge(o, { w = 960, h = 290, bw = 44 } = {}) {
  const m = { l: 46, r: 8, t: 26, b: 40 };
  const ts = echelle(Math.max(...o.v));
  const haut = ts[ts.length - 1], y = v => m.t + (h - m.t - m.b) * (1 - v / haut);
  const slot = (w - m.l - m.r) / N, cx = k => m.l + slot * (k + .5);
  let g = ts.map(v => `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${fAx(v)}</text>`).join('');
  for (let i = 0; i < N; i++) {
    const ec = i === IE, x = cx(i) - bw / 2, cout = o.c[i], marge = o.m[i], sans = o.v[i] - o.vc[i];
    const hC = y(0) - y(cout), hM = y(0) - y(Math.max(0, marge)), hS = y(0) - y(sans);
    const tt = `${sem(i)} (${dCourt(W[i].du)} → ${dCourt(W[i].au)})${ec ? ', en cours, ' + W[i].jours + ' jours' : ''} · ventes ${fE(o.v[i])} · coût matière ${fE(cout)} · marge brute ${fE(marge)}`;
    let y0 = y(0);
    g += seg(x, y0, bw, hC, ec ? 'url(#hc)' : 'var(--po-cout)', false, tt); y0 -= hC;
    if (hM > 0.4) { y0 -= 2; g += seg(x, y0, bw, hM, ec ? 'url(#hm)' : 'var(--po-marge)', hS <= 0.4, tt); y0 -= hM; }
    if (hS > 0.4) { y0 -= 2; g += seg(x, y0, bw, hS, 'url(#hs)', true, tt + ' · sans coût ' + fE(sans)); y0 -= hS; }
    g += `<text class="val" x="${cx(i).toFixed(1)}" y="${(y(o.v[i]) - 7).toFixed(1)}" text-anchor="middle">${fAx(o.v[i])}</text>`;
    if (!ec && o.vc[i] > 0 && hM > 16) { g += `<text x="${cx(i).toFixed(1)}" y="${(y(o.c[i]) - 2 - hM / 2 + 4).toFixed(1)}" text-anchor="middle" style="font-size:10px;fill:#fff">${nf(100 * o.m[i] / o.vc[i], 0)} %</text>`; }
    g += `<text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 16}" text-anchor="middle" style="${ec ? '' : 'fill:var(--ink)'}">${sem(i)}</text><text class="axs" x="${cx(i).toFixed(1)}" y="${h - m.b + 29}" text-anchor="middle">${ec ? 'en cours · ' + W[i].jours + ' j' : dCourt(W[i].du)}</text>`;
  }
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Chiffre, coût matière et marge brute, semaine par semaine">${motifs}${g}</svg>
  <div class="leg"><span><i style="background:var(--po-cout)"></i>coût matière</span><span><i style="background:var(--po-marge)"></i>marge brute · le % : marge sur les ventes chiffrées</span>${o.vSans > 0 || somme(o.v.map((v, i) => v - o.vc[i])) > 0 ? '<span><i style="background:url();border:1px solid #d9d2c8;background:repeating-linear-gradient(135deg,#d9d2c8 0 2px,#fff 2px 5px)"></i>vendu sans coût de recette</span>' : ''}<span><i class="hach"></i>semaine en cours, pas finie</span><span class="mu">au-dessus de chaque colonne : le chiffre de la semaine (k = milliers d’euros)</span></div>`;
}

/* Petites colonnes d'une ligne de tableau (12 semaines + l'en cours en clair) */
function spark(a, { w = 104, h = 24, cle = 'q' } = {}) {
  const vals = a[cle], max = Math.max(1, ...IC.map(i => vals[i])), bw = (w - 2 * (N - 1)) / N;
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="display:block;margin-left:auto">${vals.map((v, i) => { const hh = Math.max(v > 0 ? 1.5 : 0, (h - 1) * Math.max(0, v) / max); return `<rect x="${(i * (bw + 2)).toFixed(1)}" y="${(h - hh).toFixed(1)}" width="${bw.toFixed(1)}" height="${hh.toFixed(1)}" rx="1" fill="${i === IE ? 'var(--po-real-soft)' : 'var(--po-real)'}"><title>${sem(i)} · ${fN(v)}${cle === 'q' ? ' pièces' : ' €'}</title></rect>`; }).join('')}</svg>`;
}

/* Les pièces de chaque semaine d'un produit (la liste déroulante) */
function colonnesPieces(a, { w = 560, h = 150 } = {}) {
  const m = { l: 34, r: 6, t: 18, b: 32 };
  const ts = echelle(Math.max(...a.q), 3), haut = ts[ts.length - 1], y = v => m.t + (h - m.t - m.b) * (1 - v / haut);
  const slot = (w - m.l - m.r) / N, cx = k => m.l + slot * (k + .5), bw = Math.min(26, slot - 10);
  const qs = IC.map(i => a.q[i]), iMax = IC[qs.indexOf(Math.max(...qs))], iMin = IC[qs.indexOf(Math.min(...qs))];
  let g = ts.map(v => `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 6}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${fAx(v)}</text>`).join('');
  const moy = a.Q / 12;
  for (let i = 0; i < N; i++) {
    const ec = i === IE, hh = y(0) - y(a.q[i]);
    g += seg(cx(i) - bw / 2, y(0), bw, hh, ec ? 'url(#hr)' : 'var(--po-real)', true, `${sem(i)} · ${fN(a.q[i])} pièces · ${fE(a.v[i])}${a.c[i] > 0 ? ' · marge ' + fE(a.m[i]) : ''}${ec ? ' · en cours, ' + W[i].jours + ' jours' : ''}`);
    if (i === iMax || i === iMin || i === IE - 1 || i === IE) { g += `<text class="val" x="${cx(i).toFixed(1)}" y="${(y(a.q[i]) - 5).toFixed(1)}" text-anchor="middle">${fN(a.q[i])}</text>`; }
    g += `<text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 14}" text-anchor="middle" style="font-size:9.5px">${sem(i)}</text>`;
  }
  g += `<line x1="${m.l}" x2="${w - m.r}" y1="${y(moy).toFixed(1)}" y2="${y(moy).toFixed(1)}" stroke="var(--ink)" stroke-dasharray="3 3" stroke-width="1"/>`;
  return `<svg class="gr" viewBox="0 0 ${w} ${h}">${motifs}${g}</svg>`;
}

/* ---------- La liste déroulante d'un produit : prix de vente, quantités, marge ---------- */
function deroulant(a, colspan) {
  const F = a.fiche || {};
  const pc = F.prixTtc, ht = F.prixHt, cout = F.cout;
  const ent = a.fmts.find(f => f.fmt === 'entière' && f.Q > 0), pEnc = ent ? ent.prix : a.prix;
  const ecPrix = pc && pEnc ? 100 * (pEnc - pc) / pc : null;
  const mU = ht != null && cout != null ? ht - cout : null;
  const multi = a.fmts.length > 1;
  const lignesPrix = [
    pc != null ? [a.fmts.length > 1 ? 'Encaissé, la pièce entière' : 'Prix encaissé moyen', `${fE(pEnc, 2)} ${ecPrix != null && Math.abs(ecPrix) >= 0.5 ? `<small>${ecPrix < 0 ? '−' : '+'}${nf(Math.abs(ecPrix), 0)} % · remises</small>` : '<small>= catalogue</small>'}`] : ['Prix encaissé moyen', fE(a.prix, 2)],
    ht != null ? [`Prix hors TVA (${F.tva} %)`, fE(ht, 2)] : null,
    cout != null ? ['Coût de la recette', fE(cout, 2)] : ['Coût de la recette', '<span class="tag warn" style="margin:0">à chiffrer</span>'],
    mU != null ? ['Marge par pièce', `${fE(mU, 2)} <small>HT · ${nf(100 * mU / ht, 0)} %</small>`] : null,
    F.coef ? ['Coefficient', `× ${nf(F.coef, 2)} <small>cible × ${nf(F.cible, 2)}</small>`] : null,
    F.prixCible && F.coef < F.cible ? ['À la cible de la catégorie', `${fE(F.prixCible, 2)} <small>+${nf(F.prixCible - pc, 2)} €</small>`] : null,
    F.prixObjectif && F.coef < R.seuils.objectif ? [`À l’objectif réseau (× ${nf(R.seuils.objectif, 2)})`, `${fE(F.prixObjectif, 2)} <small>+${nf(F.prixObjectif - pc, 2)} €</small>`] : null
  ].filter(Boolean);
  const fmts = multi ? `<table class="fmts"><thead><tr><th>Format</th><th>Prix moyen</th><th>Pièces</th><th>Chiffre</th><th>Marge</th></tr></thead><tbody>${a.fmts.filter(f => f.Q > 0).map(f => `<tr><td>${f.fmt === 'entière' ? 'entière' : 'au ' + (f.fmt === '1/2' ? 'demi' : 'quart')}</td><td>${fE(f.prix, 2)}</td><td>${fN(f.Q)}</td><td>${fE(f.V)}</td><td>${f.taux != null ? fP(f.taux, 0) : '—'}</td></tr>`).join('')}</tbody></table>` : '';
  const qs = IC.map(i => a.q[i]), best = IC[qs.indexOf(Math.max(...qs))];
  const negatif = a.M < 0, sansCout = a.VC === 0;
  const partM = TOT.M > 0 && a.M > 0 ? 100 * a.M / TOT.M : null;
  const corps = `<div class="dd">
    <div><div class="k">Le prix de vente</div>
      <div class="big">${pc != null ? fE(pc, 2) : fE(a.prix, 2)} <small>TTC${pc != null ? ' · catalogue' : ' · encaissé'}</small></div>
      <dl>${lignesPrix.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl></div>
    <div><div class="k" style="display:flex;align-items:center;gap:10px">Les quantités · 12 semaines <span class="seg" style="margin-left:auto"><span class="on">Pièces</span><span>Chiffre</span><span>Marge</span></span></div>
      ${colonnesPieces(a)}
      <div class="stats"><span><b>${fN(a.Q)}</b> pièces</span><span><b>${nf(a.Q / 12, 0)}</b> par semaine <span style="letter-spacing:1px">┄</span></span><span><b>${nf(a.Q / J12, 1)}</b> par jour ouvert</span>${a.etat ? tendC(a) : `<span class="${clT(a.tend)}"><b class="${clT(a.tend)}">${fT(a.tend)}</b> 6 dernières sem. face aux 6 d’avant</span>`}<span>meilleure : <b>${sem(best)}</b> ${fN(a.q[best])}</span><span>${sem(IE)} en cours : <b>${fN(a.q[IE])}</b> en ${W[IE].jours} j</span></div>${fmts}</div>
    <div><div class="k">La marge brute · 12 semaines</div>
      ${sansCout ? `<div class="big mu">—</div><div class="note" style="margin-top:6px">Recette sans coût : ${fE(a.V)} vendus, marge inconnue. À chiffrer dans la recette.</div>`
      : `<div class="big ${negatif ? 'neg' : ''}">${fE(a.M)} <small>${fP(a.taux, 1)} des ventes</small></div>${negatif ? '<div style="margin-top:6px"><span class="tag stop" style="margin:0">coût au-dessus du prix · recette à vérifier</span></div>' : ''}
      <div class="hbar"><i style="flex:${Math.max(0, a.C)};background:var(--po-cout)"></i><i style="flex:${Math.max(0, a.M)};background:var(--po-marge)"></i>${a.vSans > 0 ? `<i style="flex:${a.vSans};background:var(--chip)"></i>` : ''}</div>
      <div class="hbar-l"><span>coût ${fE(a.C)}</span><span>ventes ${fE(a.V)}</span></div>`}
      <dl>${!sansCout ? `<dt>Par semaine</dt><dd>${fE(a.M / 12)}</dd>` : ''}<dt>Part du chiffre du magasin</dt><dd>${fP(100 * a.V / TOT.V, 1)}</dd>${partM != null ? `<dt>Part de la marge du magasin</dt><dd>${fP(partM, 1)}</dd>` : ''}${a.rang ? `<dt>Rang à la marge</dt><dd>${a.rang}<small> sur ${rangM.length}</small></dd>` : ''}<dt>Durée de vie</dt><dd>${{ SSL: 'short life', MSL: 'medium life', LSL: 'long life' }[F.vie] || '—'}</dd></dl>
      <div class="act"><span class="pr">Fiche du produit ↗</span><span>Recette &amp; marge ↗</span></div></div>
  </div>`;
  return colspan ? `<tr class="dd-row"><td colspan="${colspan}" style="padding:0;border:none;text-align:left">${corps}</td></tr>` : corps;
}

/* ---------- Le cadre commun : l'en-tête du dashboard, l'onglet Produits allumé ---------- */
const ONGLETS = ['Opérationnel', 'Tâches', 'Semaine', 'Mois', 'Trimestre', 'Année', 'Produits', 'Promotions', 'Primes', 'Concurrence', 'Temps morts'];
const entete = () => `<div class="db-hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="db-titre">${esc(R.magasin)}</div></div><span style="flex:1"></span><a class="db-lien">Cockpit › Résultat ›</a></div>
<div class="db-nav"><div class="db-ong">${ONGLETS.map(o => `<button class="${o === 'Produits' ? 'on' : ''}">${o}${o === 'Produits' ? '<span class="neuf">nouveau</span>' : ''}</button>`).join('')}</div><span style="flex:1"></span><button class="db-valoc"><span class="k">Valeur</span><span class="v">102 172 €</span><span class="dr">▾</span></button><button class="db-btn">↻ Relire</button></div>`;
const periode = (plus = '') => `<div class="po-per"><span class="seg vif"><span class="on">12 semaines</span><span>4 semaines</span><span>Cette semaine</span><span>Mois</span></span><span class="txt"><b>${PERIODE} 2026</b> · ${J12} jours ouverts</span><span class="case"><i></i>compter la semaine en cours (${sem(IE)}, ${W[IE].jours} jours)</span>${plus}</div>`;
const NOTE = `<div class="note">Chiffres réels, ${esc(R.magasin)}, lus le ${dLong(R.lu)} 2026 en lecture seule (${esc(R.source)}). Ventes = encaissé TTC ; marge brute = ventes − pièces × coût de la recette, le même calcul que « Ventes par catégorie » ; une vente sans coût de recette reste hors marge et se signale « sans coût ». Pièces : les portions (demi, quart) comptent chacune pour une vente.</div>`;
const page = (lettre, titre, corps, extraBody = '') => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/dashboard/dashboard.css"><link rel="stylesheet" href="po.css"></head>
<body><span class="mq">Maquette ${lettre}</span>${corps}${extraBody}</body></html>`;
const pageModale = (lettre, titre, fond, modale) => page(lettre, titre, `<div class="page-m"><div class="fond" id="fond"><div id="dash">${fond}</div></div><div class="voile"></div><div class="modale" id="mod">${modale}</div></div>`,
  `<script>(function(){var m=document.getElementById('mod'),f=document.getElementById('fond');f.style.height=Math.max(window.innerHeight,m.offsetHeight+96)+'px';})();</script>`);
const kpis = (o, cls = 'k5', ref = TOT) => { const partV = 100 * o.V / ref.V;
  return `<div class="po-k ${cls}">
  <div class="t"><div class="k">Chiffre · 12 semaines</div><div class="v">${fE(o.V)}</div><div class="s">${fE(o.V / 12)} par semaine${o !== ref ? ' · ' + fP(partV, 1) + ' du magasin' : ''}</div></div>
  <div class="t"><div class="k">Pièces vendues</div><div class="v">${fN(o.Q)}</div><div class="s">${fN(o.Q / 12)} par semaine · ${o.etat ? tendC(o) : `<span class="${clT(o.tend)}">${fT(o.tend)}</span> sur 6 sem.`}</div></div>
  <div class="t"><div class="k">Marge brute</div><div class="v ${o.M < 0 ? 'neg' : ''}">${fE(o.M)} <small>${fP(o.taux, 1)}</small></div><div class="s">coût matière ${fE(o.C)}${o.vSans > 1 ? ' · ' + fE(o.vSans) + ' vendus sans coût' : ''}</div></div>
  <div class="t"><div class="k">Prix moyen d’une pièce</div><div class="v">${fE(o.V / o.Q, 2)}</div><div class="s">marge brute ${fE(o.M / o.Q, 2)} par pièce</div></div>
  ${cls === 'k5' ? `<div class="t"><div class="k">Références vendues</div><div class="v">${o.n}</div><div class="s">${o.sans} sans coût de recette · ${o.neg} à marge négative</div></div>` : ''}
</div>`; };
const meilleure = o => { const k = IC.reduce((b, i) => o.v[i] > o.v[b] ? i : b, 0); return k; };
const cote12 = o => { const b = meilleure(o), d = o.v[IE - 1], p = o.v[IE - 2]; const pm = o.vc[IE - 1] > 0 ? 100 * o.m[IE - 1] / o.vc[IE - 1] : null;
  return `<div class="po-cote">
  <div class="t"><div class="k">Semaine ${sem(IE - 1)}</div><div class="v">${fE(d)}</div><div class="s"><span class="${clT(100 * (d - p) / p)}">${fT(100 * (d - p) / p)}</span> face à ${sem(IE - 2)} · marge ${pm != null ? fP(pm, 1) : '—'}</div></div>
  <div class="t"><div class="k">Moyenne par semaine</div><div class="v">${fE(o.V / 12)}</div><div class="s">la plus forte ${sem(b)} ${fE(o.v[b])} · la plus faible ${sem(IC.reduce((x, i) => o.v[i] < o.v[x] ? i : x, 0))} ${fE(Math.min(...IC.map(i => o.v[i])))}</div></div>
  <div class="t"><div class="k">${sem(IE)} en cours · ${W[IE].jours} jours</div><div class="v">${fE(o.v[IE])}</div><div class="s">${fN(o.q[IE])} pièces · marge ${o.vc[IE] > 0 ? fP(100 * o.m[IE] / o.vc[IE], 1) : '—'}</div></div>
</div>`; };
const tauxCell = o => o.VC === 0 ? '<span class="tag mu" style="margin:0">sans coût</span>' : (o.M < 0 ? `<span class="neg">${fP(o.taux, 0)}</span><span class="tag stop">à vérifier</span>` : fP(o.taux, 1) + (o.vSans > 0.02 * o.V ? '<span class="tag mu" title="une partie des ventes sans coût de recette">partiel</span>' : ''));
const margeCell = o => o.VC === 0 ? '<span class="mu">—</span>' : `<span class="${o.M < 0 ? 'neg' : ''}">${fE(o.M)}</span>`;
const partCell = (o, ref) => { const p = 100 * o.V / ref.V; return `<span class="part"><i style="width:${Math.max(2, 46 * p / 100 * (ref === TOT ? 3 : 1)).toFixed(0)}px"></i>${fP(p, 1)}</span>`; };

/* ============================================================================================
   A — LE TABLEAU QUI SE DÉPLIE
   ============================================================================================ */
const A_OUV_G = 'Viennoiserie', A_OUV_C = 'Viennoiserie Ind.', A_OUV_P = 'Croissant';
function corpsA({ hov = false } = {}) {
  const COLS = 10;
  const ligneP = a => `<tr class="p${a.nom === A_OUV_P ? ' ouv' : ''}"><td><span class="fl">${a.nom === A_OUV_P ? '▾' : '▸'}</span>${esc(a.nom)}${a.fmts.length > 1 ? `<span class="tag mu">${a.fmts.length} formats</span>` : ''}</td><td>${fN(a.Q)}</td><td>${nf(a.Q / 12, 0)}</td><td>${fE(a.prix, 2)}</td><td>${fE(a.V)}</td><td>${margeCell(a)}</td><td>${tauxCell(a)}</td><td>${partCell(a, TOT)}</td><td>${spark(a)}</td><td class="tend">${tendC(a)}</td></tr>${a.nom === A_OUV_P ? deroulant(a, COLS) : ''}`;
  const ligneC = (c, ouv) => `<tr class="k${hov && c.nom === A_OUV_C ? ' hov' : ''}"><td><span class="fl">${ouv ? '▾' : '▸'}</span><span class="lien">${esc(c.nom)}</span>${c.nom === A_OUV_C ? '<span class="ouvrir">↗ ouvrir</span>' : ''}<span class="sous" style="display:inline;margin-left:8px">${c.n} réf.</span></td><td>${fN(c.Q)}</td><td>${nf(c.Q / 12, 0)}</td><td>${fE(c.V / c.Q, 2)}</td><td>${fE(c.V)}</td><td>${margeCell(c)}</td><td>${tauxCell(c)}</td><td>${partCell(c, TOT)}</td><td>${spark(c)}</td><td class="tend">${tendC(c)}</td></tr>${ouv ? c.liste.map(ligneP).join('') : ''}`;
  const ligneG = g => { const ouv = g.nom === A_OUV_G; return `<tr class="g"><td><span class="fl">${ouv ? '▾' : '▸'}</span>${esc(g.nom)}<span class="sous" style="display:inline;margin-left:8px">${g.cats.length} catégorie${g.cats.length > 1 ? 's' : ''} · ${g.n} réf.</span></td><td>${fN(g.Q)}</td><td>${nf(g.Q / 12, 0)}</td><td>${fE(g.V / g.Q, 2)}</td><td>${fE(g.V)}</td><td>${margeCell(g)}</td><td>${tauxCell(g)}</td><td>${partCell(g, TOT)}</td><td>${spark(g)}</td><td class="tend">${tendC(g)}</td></tr>${ouv ? g.cats.map(c => ligneC(c, c.nom === A_OUV_C)).join('') : ''}`; };
  return `${entete()}${periode()}
  ${kpis(TOT)}
  <div class="po-c"><div class="ct"><span class="lab">Les douze dernières semaines — chiffre, coût matière, marge brute</span><span class="seg"><span class="on">Chiffre &amp; marge</span><span>Pièces</span><span>Marge %</span></span><span class="aside">${sem(0)} → ${sem(IE - 1)} · ${sem(IE)} en cours (${W[IE].jours} jours)</span></div>
    <div class="in po-12"><div>${colonnesMarge(TOT, { w: 1080, h: 300, bw: 50 })}</div>${cote12(TOT)}</div></div>
  <div class="po-c"><div class="ct"><span class="lab">Ce que je vends — groupe › catégorie › produit</span><span class="recherche">chercher un produit</span><span class="seg"><span class="on">Tout</span><span>Marge à vérifier</span><span>Sans coût</span><span>En baisse</span></span><span class="aside">un clic sur une catégorie ouvre sa fiche · un clic sur un produit déplie son prix, ses quantités, sa marge</span></div>
    <table class="tb"><thead><tr><th>Produit</th><th>Pièces</th><th>Par sem.</th><th>Prix moyen</th><th class="on">Chiffre ▾</th><th>Marge brute</th><th>Marge %</th><th>Part du chiffre</th><th>12 semaines <span style="text-transform:none;letter-spacing:0">(pièces)</span></th><th>Tendance</th></tr></thead>
    <tbody>${GRPS.map(ligneG).join('')}<tr class="tot"><td>Le magasin<span class="sous" style="display:inline;margin-left:8px">${GRPS.length} groupes · ${CATS.length} catégories · ${TOT.n} réf.</span></td><td>${fN(TOT.Q)}</td><td>${fN(TOT.Q / 12)}</td><td>${fE(TOT.V / TOT.Q, 2)}</td><td>${fE(TOT.V)}</td><td>${fE(TOT.M)}</td><td>${fP(TOT.taux, 1)}</td><td>100 %</td><td>${spark(TOT)}</td><td class="tend">${tendC(TOT)}</td></tr></tbody></table></div>
  ${NOTE}`;
}
function modaleA(c) {
  const g = grp(c.grp);
  const lignes = c.liste.map((a, k) => { const maxV = c.liste[0].V; return `<tr class="p"><td class="rg" style="color:var(--muted);width:24px">${k + 1}</td><td style="text-align:left"><span class="fl">▸</span>${esc(a.nom)}</td><td>${fN(a.Q)}</td><td>${nf(a.Q / 12, 0)}</td><td>${fE(a.prix, 2)}</td>
    <td><div style="display:flex;align-items:center;gap:8px;justify-content:flex-end"><span style="display:flex;gap:2px;width:${(110 * a.V / maxV).toFixed(0)}px;height:10px"><i style="flex:${Math.max(0, a.C)};background:var(--po-cout);border-radius:2px 0 0 2px"></i><i style="flex:${Math.max(0, a.M)};background:var(--po-marge);border-radius:0 2px 2px 0"></i></span><span style="min-width:62px">${fE(a.V)}</span></div></td>
    <td>${margeCell(a)}</td><td>${tauxCell(a)}</td><td>${fP(100 * a.V / c.V, 1)}</td><td>${spark(a)}</td><td class="tend">${tendC(a)}</td></tr>`; }).join('');
  return `<div class="m-hd"><div class="t"><div class="kick">${esc(g.nom)} ›</div><h1>${esc(c.nom)}</h1><div class="s">${c.n} références vendues · ${PERIODE} · ${esc(R.magasin)}</div></div><div class="nav"><span>‹ ${esc(CATS[CATS.indexOf(c) - 1] ? CATS[CATS.indexOf(c) - 1].nom : '')}</span><span>${esc(CATS[CATS.indexOf(c) + 1].nom)} ›</span></div><span class="x">✕</span></div>
  <div class="m-bd">${kpis(c, 'k4')}
    <div class="m-sec">Ventes · 12 semaines <span class="seg"><span class="on">Chiffre &amp; marge</span><span>Pièces</span><span>Marge %</span></span><span class="aside">la catégorie dans le chiffre du magasin : ${fP(100 * c.V / TOT.V, 1)}</span></div>
    ${colonnesMarge(c, { w: 1136, h: 250, bw: 52 })}
    <div class="m-sec">Les produits de la catégorie <span class="aside">classés au chiffre · un clic déplie le prix, les quantités, la marge</span></div>
    <table class="tb lp2" style="font-size:12px"><thead><tr><th></th><th style="text-align:left">Produit</th><th>Pièces</th><th>Par sem.</th><th>Prix moyen</th><th>Chiffre <span style="text-transform:none;letter-spacing:0">(coût + marge)</span></th><th>Marge</th><th>Marge %</th><th>Part</th><th>12 semaines</th><th>Tendance</th></tr></thead><tbody>${lignes}</tbody></table>
  </div>`;
}

/* ============================================================================================
   B — LES TUILES DE CATÉGORIE
   ============================================================================================ */
const B_TOP = 5;
const B_GRPS = GRPS.slice(0, B_TOP), B_AUT = GRPS.slice(B_TOP);
function colonnesGroupes({ w = 1080, h = 300, bw = 50 } = {}) {
  const m = { l: 46, r: 120, t: 22, b: 40 };
  const series = B_GRPS.map((g, k) => ({ nom: g.nom, v: g.v, col: `var(--g${k + 1})` })).concat([{ nom: 'Les autres (' + B_AUT.length + ')', v: Array(N).fill(0).map((_, i) => somme(B_AUT.map(g => g.v[i]))), col: 'var(--g6)' }]);
  const ts = echelle(Math.max(...TOT.v)), haut = ts[ts.length - 1], y = v => m.t + (h - m.t - m.b) * (1 - v / haut);
  const slot = (w - m.l - m.r) / N, cx = k => m.l + slot * (k + .5);
  let g = ts.map(v => `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${fAx(v)}</text>`).join('');
  for (let i = 0; i < N; i++) {
    let y0 = y(0); const ec = i === IE;
    series.forEach((s, k) => { const hh = y(0) - y(s.v[i]); if (k) { y0 -= 2; } g += seg(cx(i) - bw / 2, y0, bw, hh, s.col, k === series.length - 1, `${sem(i)} · ${s.nom} ${fE(s.v[i])} · ${fP(100 * s.v[i] / TOT.v[i], 0)} du chiffre`).replace('<path', `<path opacity="${ec ? .45 : 1}"`).replace('<rect', `<rect opacity="${ec ? .45 : 1}"`); y0 -= hh; });
    g += `<text class="val" x="${cx(i).toFixed(1)}" y="${(y(TOT.v[i]) - 9).toFixed(1)}" text-anchor="middle">${fAx(TOT.v[i])}</text>`;
    g += `<text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 16}" text-anchor="middle" style="${ec ? '' : 'fill:var(--ink)'}">${sem(i)}</text><text class="axs" x="${cx(i).toFixed(1)}" y="${h - m.b + 29}" text-anchor="middle">${ec ? 'en cours · ' + W[i].jours + ' j' : dCourt(W[i].du)}</text>`;
  }
  // étiquettes directes, à droite de la dernière semaine close
  let y0 = y(0); const iL = IE - 1, pos = [];
  series.forEach((s, k) => { const hh = y(0) - y(s.v[iL]); pos.push(y0 - hh / 2 - (k ? 2 : 0)); y0 -= hh + (k ? 2 : 0); });
  for (let k = 1; k < pos.length; k++) { if (pos[k - 1] - pos[k] < 15) { pos[k] = pos[k - 1] - 15; } }
  series.forEach((s, k) => { g += `<text class="lab" x="${cx(iL) + bw / 2 + slot + 2}" y="${(pos[k] + 4).toFixed(1)}"><tspan style="fill:var(--ink)">${esc(s.nom)}</tspan><tspan class="axs" dx="5" style="fill:var(--muted)">${fP(100 * s.v[iL] / TOT.v[iL], 0)}</tspan></text>`; });
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Le chiffre de chaque semaine, par groupe">${g}</svg>
  <div class="leg">${series.map(s => `<span><i style="background:${s.col}"></i>${esc(s.nom)}</span>`).join('')}<span class="mu">en clair : ${sem(IE)}, en cours · à droite : la part de chaque groupe en ${sem(IE - 1)}</span></div>`;
}
const B_HOV = 'Quiches';
function tuile(c) {
  const neg = c.M < 0, w = 120, h = 30;
  const vals = c.q, max = Math.max(1, ...IC.map(i => vals[i])), bw = (w - 2 * (N - 1)) / N;
  const sp = `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">${vals.map((v, i) => { const hh = Math.max(v > 0 ? 1.5 : 0, (h - 1) * v / max); return `<rect x="${(i * (bw + 2)).toFixed(1)}" y="${(h - hh).toFixed(1)}" width="${bw.toFixed(1)}" height="${hh.toFixed(1)}" rx="1" fill="${i === IE ? 'var(--po-real-soft)' : 'var(--po-real)'}"><title>${sem(i)} · ${fN(v)} pièces</title></rect>`; }).join('')}</svg>`;
  return `<div class="tc${c.nom === B_HOV ? ' hov' : ''}${neg ? ' neg' : ''}"><span class="go">↗</span><div class="kk">${esc(c.grp)}</div><div class="n">${esc(c.nom)}</div>
    <div class="row"><div><div class="v">${fE(c.V)}</div><div class="s">${fN(c.Q)} pièces · ${c.n} réf.</div></div><div style="text-align:right">${sp}<div class="s" style="margin-top:2px">${c.etat ? tendC(c) : `<span class="${clT(c.tend)}">${fT(c.tend)}</span> <span class="mu">pièces</span>`}</div></div></div>
    <div class="mb"><span>marge</span><span class="tr"><i style="width:${Math.max(0, Math.min(100, c.taux || 0)).toFixed(0)}%"></i><b style="left:${TOT.taux.toFixed(0)}%"></b></span><span class="${neg ? 'neg' : ''}">${c.VC === 0 ? 'sans coût' : fP(c.taux, 0)}</span>${neg ? '<span class="tag stop" style="margin-left:0">à vérifier</span>' : ''}</div></div>`;
}
const B_OUV = 'Pain au Chocolat';
function listeProduits(liste, { ouv, rangs = true, ref = TOT, barW = 220 } = {}) {
  const maxV = Math.max(...liste.map(a => a.V));
  return `<table class="lp"><thead><tr><th></th><th>Produit</th><th>Pièces</th><th>Par sem.</th><th>Prix moyen</th><th style="text-align:right">Chiffre <span style="text-transform:none;letter-spacing:0">— coût matière + marge</span></th><th style="color:var(--accent)">Marge ▾</th><th>Marge %</th><th>12 semaines</th><th>Tendance</th></tr></thead><tbody>${liste.map((a, k) => `<tr class="${a.nom === ouv ? 'ouv' : ''}"><td class="rg">${rangs ? k + 1 : ''}</td><td><span class="fl" style="display:inline-block;width:14px;color:var(--muted);font-size:10px">${a.nom === ouv ? '▾' : '▸'}</span>${esc(a.nom)}<span class="sous" style="padding-left:14px">${esc(a.cat)}</span></td><td>${fN(a.Q)}</td><td>${nf(a.Q / 12, 0)}</td><td>${fE(a.prix, 2)}</td>
    <td><div class="barre"><span class="hb" style="width:${(barW * a.V / maxV).toFixed(0)}px"><i style="flex:${Math.max(0, a.C)};background:var(--po-cout)"></i><i style="flex:${Math.max(0, a.M)};background:var(--po-marge)"></i>${a.vSans > 0 ? `<i style="flex:${a.vSans};background:var(--chip)"></i>` : ''}</span><span style="min-width:64px">${fE(a.V)}</span></div></td>
    <td>${margeCell(a)}</td><td>${tauxCell(a)}</td><td>${spark(a)}</td><td class="tend">${tendC(a)}</td></tr>${a.nom === ouv ? deroulant(a, 10) : ''}`).join('')}</tbody></table>`;
}
function corpsB() {
  const top = CATS.slice(0, 12);
  return `${entete()}${periode()}
  ${kpis(TOT)}
  <div class="po-c"><div class="ct"><span class="lab">Ce que je vends, semaine par semaine — le chiffre par groupe</span><span class="seg"><span class="on">Chiffre</span><span>Pièces</span><span>Marge brute</span></span><span class="aside">${sem(0)} → ${sem(IE - 1)} · ${sem(IE)} en cours (${W[IE].jours} jours)</span></div>
    <div class="in">${colonnesGroupes({ w: 1350, h: 300, bw: 56 })}</div></div>
  <div class="po-c"><div class="ct"><span class="lab">Les catégories</span><span class="seg"><span class="on">Chiffre</span><span>Pièces</span><span>Marge</span><span>Tendance</span></span><span class="aside">${CATS.length} catégories vendues · un clic ouvre la catégorie · le trait noir de la jauge : la marge du magasin, ${fP(TOT.taux, 0)}</span></div>
    <div class="in"><div class="tuiles">${top.map(tuile).join('')}</div><div class="plus">▾ les ${CATS.length - top.length} autres catégories (${fE(somme(CATS.slice(12).map(c => c.V)))} sur 12 semaines)</div></div></div>
  <div class="po-c"><div class="ct"><span class="lab">Les produits — les 15 premiers à la marge</span><span class="recherche">chercher un produit</span><span class="aside">${TOT.n} références · <span style="color:var(--accent)">voir toute la liste ›</span></span></div>
    ${listeProduits(rangM.slice(0, 15), { ouv: B_OUV })}</div>
  ${NOTE}`;
}
function colonnesFormats(c, { w = 1136, h = 240, bw = 52 } = {}) {
  const m = { l: 46, r: 8, t: 22, b: 40 };
  const fm = ['entière', '1/2', '1/4'], cols = ['var(--g1)', 'var(--g2)', 'var(--g3)'], noms = ['entière', 'au demi', 'au quart'];
  const s = fm.map(f => Array(N).fill(0).map((_, i) => somme(c.liste.map(a => somme(a.fmts.filter(x => x.fmt === f).map(x => x.q[i]))))));
  const tot = Array(N).fill(0).map((_, i) => somme(s.map(x => x[i])));
  const ts = echelle(Math.max(...tot)), haut = ts[ts.length - 1], y = v => m.t + (h - m.t - m.b) * (1 - v / haut);
  const slot = (w - m.l - m.r) / N, cx = k => m.l + slot * (k + .5);
  let g = ts.map(v => `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${fAx(v)}</text>`).join('');
  for (let i = 0; i < N; i++) { let y0 = y(0); const ec = i === IE;
    s.forEach((x, k) => { const hh = y(0) - y(x[i]); if (hh <= .4) { return; } if (y0 < y(0)) { y0 -= 2; } const last = s.slice(k + 1).every(z => z[i] === 0); g += seg(cx(i) - bw / 2, y0, bw, hh, cols[k], last, `${sem(i)} · ${noms[k]} ${fN(x[i])} pièces`).replace(/<(path|rect)/, `<$1 opacity="${ec ? .45 : 1}"`); y0 -= hh; });
    g += `<text class="val" x="${cx(i).toFixed(1)}" y="${(y(tot[i]) - 7).toFixed(1)}" text-anchor="middle">${fN(tot[i])}</text><text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 16}" text-anchor="middle" style="${ec ? '' : 'fill:var(--ink)'}">${sem(i)}</text><text class="axs" x="${cx(i).toFixed(1)}" y="${h - m.b + 29}" text-anchor="middle">${ec ? 'en cours · ' + W[i].jours + ' j' : dCourt(W[i].du)}</text>`; }
  return `<svg class="gr" viewBox="0 0 ${w} ${h}">${g}</svg><div class="leg">${noms.map((n, k) => `<span><i style="background:${cols[k]}"></i>${n} <span class="mu">${fN(sur12(s[k]))}</span></span>`).join('')}<span class="mu">pièces vendues chaque semaine, par format</span></div>`;
}
function modaleB(c) {
  const ouv = 'Quiche Lorraine';
  const lignes = c.liste.slice(0, 14);
  return `<div class="m-hd"><div class="t"><div class="kick">${esc(c.grp)} ›</div><h1>${esc(c.nom)}</h1><div class="s">${c.n} références vendues · ${PERIODE} · ${esc(R.magasin)}</div></div><span class="x">✕</span></div>
  <div class="m-bd">${kpis(c, 'k4')}
    <div class="m-sec">Ventes · 12 semaines <span class="seg"><span>Chiffre &amp; marge</span><span class="on">Pièces par format</span></span><span class="aside">une quiche se vend entière, au demi ou au quart · jusqu’en S40, la caisse ne nommait pas la quiche d’une portion</span></div>
    ${colonnesFormats(c)}
    <div class="m-sec">Les produits de la catégorie <span class="seg"><span class="on">Chiffre</span><span>Pièces</span><span>Marge</span></span><span class="aside">les 14 premiers sur ${c.n} · un clic déplie le produit</span></div>
    ${listeProduits(lignes, { ouv, ref: c, barW: 90 }).replace('style="color:var(--accent)">Marge ▾', '>Marge').replace('<th style="text-align:right">Chiffre', '<th style="text-align:right;color:var(--accent)">Chiffre ▾')}
  </div>`;
}

/* ============================================================================================
   C — LA CARTE VOLUME × MARGE
   ============================================================================================ */
const PTS = ARTS.filter(a => a.VC > 0 && a.Q >= 1);
const medQ = (() => { const s = PTS.map(a => a.Q / 12).sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; })();
const X0 = Math.max(1, Math.round(medQ)), Y0 = TOT.taux;
const quadrant = a => a.M < 0 ? 'verif' : ((a.Q / 12 >= X0 ? 'D' : 'G') + (a.taux >= Y0 ? 'H' : 'B'));
const QUADS = { DH: ['Les piliers', 'beaucoup vendus, bien margés : à protéger, jamais en rupture'], DB: ['Le volume', 'beaucoup vendus, marge sous celle du magasin : un prix ou une recette à revoir'], GH: ['Les pépites', 'peu vendus, bien margés : à mettre en avant'], GB: ['À questionner', 'peu vendus, peu margés : les garder ?'] };
PTS.forEach(a => { a.qd = quadrant(a); });
const QA = Object.fromEntries(Object.keys(QUADS).map(k => [k, agr(PTS.filter(a => a.qd === k).sort((x, y) => y.V - x.V), { nom: QUADS[k][0] })]));
const VERIF = PTS.filter(a => a.qd === 'verif');
function nuage(liste, { w = 900, h = 540, focus = null, etiq = [], droite = [], fond = null } = {}) {
  const m = { l: 52, r: droite.length ? 150 : 18, t: 16, b: 56 };
  const xs = [0.1, 0.25, 0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500].filter(v => v >= 0.08);
  const lx0 = Math.log10(0.08), lx1 = Math.log10(900);
  const x = q => m.l + (w - m.l - m.r) * (Math.log10(Math.max(0.08, q)) - lx0) / (lx1 - lx0);
  const yb = h - m.b - 26;     // le bas de l'échelle des marges ; dessous, la bande « marge négative »
  const y = p => m.t + (yb - m.t) * (1 - Math.max(0, Math.min(100, p)) / 100);
  const Mmax = Math.max(...PTS.map(a => a.M));
  const r = a => a.M < 0 ? 5 : 3 + 15 * Math.sqrt(a.M / Mmax);
  let g = '';
  // les quatre cadrans
  const xm = x(X0), ym = y(Y0);
  g += `<rect x="${xm}" y="${m.t}" width="${w - m.r - xm}" height="${ym - m.t}" fill="#F3F8F4"/><rect x="${xm}" y="${ym}" width="${w - m.r - xm}" height="${yb - ym}" fill="${fond === 'DB' ? '#FBF1E6' : '#FCF8F3'}"/><rect x="${m.l}" y="${m.t}" width="${xm - m.l}" height="${ym - m.t}" fill="#FAFAF8"/>`;
  [0, 20, 40, 60, 80, 100].forEach(p => { g += `<line x1="${m.l}" x2="${w - m.r}" y1="${y(p)}" y2="${y(p)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${y(p) + 3.5}" text-anchor="end">${p} %</text>`; });
  xs.forEach(v => { if (x(v) < m.l - 1 || x(v) > w - m.r + 1) { return; } g += `<line x1="${x(v)}" x2="${x(v)}" y1="${m.t}" y2="${yb}" stroke="var(--po-grille)"/><text class="ax" x="${x(v)}" y="${h - m.b + 14}" text-anchor="middle">${v < 1 ? nf(v, 2).replace(/0$/, '') : nf(v, 0)}</text>`; });
  g += `<rect x="${m.l}" y="${yb + 6}" width="${w - m.l - m.r}" height="18" rx="4" fill="var(--stop-soft)"/><text x="${w - m.r - 8}" y="${yb + 18}" text-anchor="end" style="font-size:10px;fill:var(--stop)">marge négative · recette à vérifier (${liste.filter(a => a.M < 0).length})</text>`;
  g += `<line x1="${xm}" x2="${xm}" y1="${m.t}" y2="${yb}" stroke="var(--muted-2)" stroke-dasharray="4 4"/><line x1="${m.l}" x2="${w - m.r}" y1="${ym}" y2="${ym}" stroke="var(--muted-2)" stroke-dasharray="4 4"/>`;
  g += `<text x="${xm + 4}" y="${m.t + 12}" class="axs" style="fill:var(--muted-2)">${X0} pièces / semaine (la médiane des produits)</text>`;
  const qlab = (k, xx, yy, anc) => `<text x="${xx}" y="${yy}" text-anchor="${anc}" style="font-size:12px;fill:var(--ink)">${QUADS[k][0]}</text>`;
  g += qlab('DH', w - m.r - 8, m.t + 30, 'end') + qlab('DB', w - m.r - 8, yb - 10, 'end') + qlab('GH', m.l + 8, m.t + 30, 'start') + qlab('GB', m.l + 8, yb - 10, 'start');
  // les points, les plus gros dessous
  [...liste].sort((p, q) => r(q) - r(p)).forEach(a => { const on = !focus || focus(a); const cy = a.M < 0 ? yb + 15 : y(a.taux);
    g += `<circle cx="${x(a.Q / 12).toFixed(1)}" cy="${cy.toFixed(1)}" r="${r(a).toFixed(1)}" fill="${a.M < 0 ? 'var(--stop)' : (on ? 'rgba(176,48,72,.32)' : 'rgba(184,178,168,.25)')}" stroke="${a.M < 0 ? '#fff' : (on ? '#B03048' : '#c9c2b8')}" stroke-width="${a.M < 0 ? 2 : 1}"><title>${esc(a.nom)} · ${esc(a.cat)} · ${nf(a.Q / 12, 1)} pièces / semaine · marge ${fP(a.taux, 1)} · ${fE(a.M)} sur 12 semaines</title></circle>`; });
  etiq.forEach(([nom, dx, dy, anc]) => { const a = liste.find(z => z.nom === nom); if (!a) { return; } const cx = x(a.Q / 12), cy = a.M < 0 ? yb + 15 : y(a.taux);
    g += `<line x1="${cx.toFixed(1)}" y1="${cy.toFixed(1)}" x2="${(cx + dx * .8).toFixed(1)}" y2="${(cy + dy * .8).toFixed(1)}" stroke="var(--muted)" stroke-width="1"/><text class="lab" x="${(cx + dx).toFixed(1)}" y="${(cy + dy + 4).toFixed(1)}" text-anchor="${anc || 'start'}">${esc(nom)}</text>`; });
  // les gros produits, tassés au bord droit : leur nom dans une colonne à droite du graphique
  const dr = droite.map(nom => liste.find(z => z.nom === nom)).filter(Boolean).map(a => ({ a, cx: x(a.Q / 12), cy: a.M < 0 ? yb + 15 : y(a.taux) })).sort((p, q) => p.cy - q.cy);
  dr.forEach((d, k) => { d.ly = Math.max(d.cy, k ? dr[k - 1].ly + 15 : m.t + 6); });
  for (let k = dr.length - 1; k >= 0; k--) { if (k < dr.length - 1 && dr[k + 1].ly - dr[k].ly < 15) { dr[k].ly = dr[k + 1].ly - 15; } }
  dr.forEach(d => { const xl = w - m.r + 16; g += `<polyline points="${(d.cx + r(d.a) + 1).toFixed(1)},${d.cy.toFixed(1)} ${(w - m.r + 8).toFixed(1)},${d.ly.toFixed(1)} ${(xl - 3).toFixed(1)},${d.ly.toFixed(1)}" fill="none" stroke="var(--muted)" stroke-width="1"/><text class="lab" x="${xl}" y="${(d.ly + 4).toFixed(1)}">${esc(d.a.nom)}</text>`; });
  g += `<text class="ax" x="${(m.l + w - m.r) / 2}" y="${h - 6}" text-anchor="middle" style="fill:var(--muted-2)">pièces vendues par semaine (échelle logarithmique) →</text><text class="ax" transform="translate(13 ${(m.t + yb) / 2}) rotate(-90)" text-anchor="middle" style="fill:var(--muted-2)">marge brute, en % des ventes →</text>`;
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Chaque produit : pièces par semaine et marge">${g}</svg>`;
}
function miniQuad(o, haut) { const w = 250, h = 54, bw = (w - 2 * (N - 1)) / N;
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="display:block;margin-top:6px">${o.m.map((v, i) => { const hh = Math.max(v > 0 ? 1.5 : 0, (h - 12) * Math.max(0, v) / haut); return `<rect x="${(i * (bw + 2)).toFixed(1)}" y="${(h - 12 - hh).toFixed(1)}" width="${bw.toFixed(1)}" height="${hh.toFixed(1)}" rx="1" fill="${i === IE ? 'var(--po-real-soft)' : 'var(--po-marge)'}"><title>${sem(i)} · marge ${fE(v)}</title></rect>`; }).join('')}<text class="axs" x="0" y="${h - 1}" style="font-size:9px;fill:var(--faint);font-family:var(--font-ui)">${sem(0)}</text><text x="${w}" y="${h - 1}" text-anchor="end" style="font-size:9px;fill:var(--faint);font-family:var(--font-ui)">${sem(IE)}</text></svg>`; }
const C_OUV = 'Baguette Tradition 500 g.';
function corpsC() {
  const haut = Math.max(...Object.values(QA).flatMap(o => IC.map(i => o.m[i])));
  const ordre = ['GH', 'DH', 'GB', 'DB'];
  const qt = k => { const o = QA[k]; return `<div class="qt${k === 'DB' ? ' on' : ''}"><div class="k">${o.n} produits</div><div class="n">${QUADS[k][0]}</div><div class="s">${QUADS[k][1]}</div><div class="v">${fE(o.M)} <small>de marge · ${fP(100 * o.M / TOT.M, 0)}</small></div><div class="s">${fE(o.V)} de ventes · marge ${fP(o.taux, 0)}</div>${miniQuad(o, haut)}</div>`; };
  const liste = QA.DB.liste;
  return `${entete()}${periode()}
  ${kpis(TOT)}
  <div class="po-c"><div class="ct"><span class="lab">Où se place chaque produit — pièces vendues × marge</span><span class="chips"><span class="on">Tous les groupes</span>${GRPS.slice(0, 8).map(g => `<span>${esc(g.nom)}<small>${g.n}</small></span>`).join('')}<span>+ ${GRPS.length - 8}</span></span><span class="aside">la taille du rond : la marge en euros sur 12 semaines</span></div>
    <div class="in" style="display:grid;grid-template-columns:minmax(0,1fr) 540px;gap:18px;align-items:start">
      <div>${nuage(PTS, { w: 820, h: 560, fond: 'DB', etiq: [['Couque à la Crème', 22, -26], ['éclair chocolat', -22, -30, 'end']], droite: ['Sandwich', "Pistolet à l'Ancienne", 'Croissant', 'Pain au Chocolat Nappé', 'Pain au Chocolat', 'Couque au Beurre', 'Pain Doré', 'Pain 10 Céréales', 'Baguette Tradition 500 g.'] })}
      <div class="leg"><span><i style="background:rgba(176,48,72,.32);border:1px solid #B03048;border-radius:50%"></i>un produit vendu, coût de recette connu (${PTS.length})</span><span><i style="background:var(--stop);border-radius:50%"></i>marge négative</span><span><i class="ln" style="background:repeating-linear-gradient(90deg,var(--muted-2) 0 4px,transparent 4px 8px)"></i>les seuils : ${X0} pièces par semaine, la marge du magasin (${fP(Y0, 0)})</span><span class="mu">${ARTS.length - PTS.length} produits sans coût, hors carte</span></div></div>
      <div><div class="m-sec" style="margin-top:0">Les quatre familles · la marge brute, semaine par semaine</div><div class="quad">${ordre.map(qt).join('')}</div>
        <div class="note">Les seuils : ${X0} pièces par semaine (la médiane des ${PTS.length} produits) et la marge du magasin, ${fP(Y0, 1)}. Un clic sur un rond ou une famille ouvre la liste dessous ; un clic sur une catégorie (filtre au-dessus) ouvre sa fiche.</div></div>
    </div></div>
  <div class="po-c"><div class="ct"><span class="lab">Le volume — ${liste.length} produits : beaucoup vendus, marge sous celle du magasin</span><span class="seg"><span>Les piliers</span><span class="on">Le volume</span><span>Les pépites</span><span>À questionner</span><span>À vérifier (${VERIF.length})</span></span><span class="aside">classés au chiffre</span></div>
    ${listeProduits(liste.slice(0, 12), { ouv: C_OUV }).replace('style="color:var(--accent)">Marge ▾', '>Marge').replace('<th style="text-align:right">Chiffre', '<th style="text-align:right;color:var(--accent)">Chiffre ▾')}</div>
  ${NOTE}`;
}
function modaleC(c) {
  const ids = new Set(c.liste.map(a => a.id));
  const gros = c.liste.filter(a => a.VC > 0 && a.Q / 12 >= 20).map(a => a.nom);
  const lab = c.liste.filter(a => a.VC > 0 && a.Q / 12 < 20).slice(0, 3).map((a, k) => [a.nom, [-26, 24, -30][k], [-24, 26, 28][k], ['end', 'start', 'end'][k]]);
  return `<div class="m-hd"><div class="t"><div class="kick">${esc(c.grp)} ›</div><h1>${esc(c.nom)}</h1><div class="s">${c.n} références vendues · ${PERIODE} · ${esc(R.magasin)}</div></div><span class="x">✕</span></div>
  <div class="m-bd">${kpis(c, 'k4')}
    <div class="m-sec">Ventes · 12 semaines <span class="seg"><span class="on">Chiffre &amp; marge</span><span>Pièces</span></span></div>
    ${colonnesMarge(c, { w: 1136, h: 230, bw: 52 })}
    <div style="display:grid;grid-template-columns:600px minmax(0,1fr);gap:22px;align-items:start">
      <div><div class="m-sec">Ses produits sur la carte du magasin <span class="aside">en gris : les autres produits</span></div>
      ${nuage(PTS, { w: 600, h: 420, focus: a => ids.has(a.id), etiq: lab, droite: gros })}</div>
      <div><div class="m-sec">Les produits de la catégorie <span class="aside">un clic déplie le produit</span></div>
      ${c.liste.map(a => `<div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--row-line);font-size:12px"><span style="flex:1;min-width:0"><span style="color:var(--muted);font-size:10px;display:inline-block;width:14px">▸</span>${esc(a.nom)}<span style="display:block;font-size:10.5px;color:var(--muted);padding-left:14px">${a.qd ? (QUADS[a.qd] ? QUADS[a.qd][0] : 'à vérifier') : 'sans coût'} · ${nf(a.Q / 12, 0)} / sem. · ${fE(a.prix, 2)}</span></span><span>${fN(a.Q)} p.</span><span style="width:70px;text-align:right">${fE(a.V)}</span><span style="width:52px;text-align:right">${a.VC ? fP(a.taux, 0) : '—'}</span><span class="tend" style="width:92px;text-align:right">${tendC(a)}</span></div>`).join('')}</div>
    </div>
  </div>`;
}

/* ============================================================================================
   D — LA GRILLE SEMAINE PAR SEMAINE
   ============================================================================================ */
function lignesCM(o, { w = 1080, h = 280 } = {}) {
  const m = { l: 50, r: 96, t: 22, b: 40 };
  const ts = echelle(Math.max(...o.v)), haut = ts[ts.length - 1], y = v => m.t + (h - m.t - m.b) * (1 - v / haut);
  const slot = (w - m.l - m.r) / N, cx = k => m.l + slot * (k + .5);
  let g = ts.map(v => `<line x1="${m.l}" x2="${w - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--po-grille)"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${fAx(v)}</text>`).join('');
  const serie = (vals, col, nom) => { const pts = vals.map((v, i) => [cx(i), y(v)]);
    let s = `<polyline points="${pts.slice(0, IE).map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${col}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`;
    const iMax = IC.reduce((b, i) => vals[i] > vals[b] ? i : b, 0);
    pts.forEach((p, i) => { s += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="4" fill="${i === IE ? '#fff' : col}" stroke="${i === IE ? col : '#fff'}" stroke-width="2"><title>${sem(i)} · ${nom} ${fE(vals[i])}</title></circle>`; if (i === iMax || i === 0 || i === IE - 1) { s += `<text class="val" x="${p[0].toFixed(1)}" y="${(p[1] - 10).toFixed(1)}" text-anchor="middle">${fAx(vals[i])}</text>`; } });
    s += `<text class="axs" x="${pts[IE][0].toFixed(1)}" y="${(pts[IE][1] - 9).toFixed(1)}" text-anchor="middle">${fAx(vals[IE])}</text><text class="lab" x="${(cx(IE) + 12).toFixed(1)}" y="${(pts[IE - 1][1] + 4).toFixed(1)}" style="fill:var(--ink)">${nom}</text>`; return s; };
  for (let i = 0; i < N; i++) { g += `<text class="ax" x="${cx(i).toFixed(1)}" y="${h - m.b + 16}" text-anchor="middle" style="${i === IE ? '' : 'fill:var(--ink)'}">${sem(i)}</text><text class="axs" x="${cx(i).toFixed(1)}" y="${h - m.b + 29}" text-anchor="middle">${i === IE ? 'en cours · ' + W[i].jours + ' j' : dCourt(W[i].du)}</text>`; }
  g += serie(o.v, 'var(--po-real)', 'Chiffre') + serie(o.m, 'var(--po-marge)', 'Marge brute');
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Chiffre et marge brute, semaine par semaine">${g}</svg>
  <div class="leg"><span><i class="ln" style="background:var(--po-real)"></i>chiffre de la semaine</span><span><i class="ln" style="background:var(--po-marge)"></i>marge brute</span><span class="mu">rond vide : ${sem(IE)}, en cours (${W[IE].jours} jours), pas relié</span></div>`;
}
const RAMPE = ['#FBF3F1', '#F2D9D9', '#E3B2B6', '#CC8189', '#AE4E5B', '#8D1D2C'];
const teinte = t => RAMPE[Math.max(0, Math.min(RAMPE.length - 1, Math.round(t * (RAMPE.length - 1))))];
function cellules(o, cle = 'q') { const vs = IC.map(i => o[cle][i]), max = Math.max(1, ...vs), min = Math.min(...vs.filter(v => v > 0));
  return o[cle].map((v, i) => { const ec = i === IE, t = max > min ? (v - min) / (max - min) : 1, fond = ec ? '#F6F2EC' : teinte(t), enc = !ec && t > 0.62;
    return `<td${i === IE ? ' class="sep"' : ''}><span class="cel${ec ? ' ec' : ''}" style="background:${v > 0 ? fond : '#fff'};color:${enc ? '#fff' : (v > 0 ? 'var(--ink)' : 'var(--faint)')}" title="${sem(i)} · ${fN(v)}">${v > 0 ? fN(v) : '·'}</span></td>`; }).join(''); }
const D_OUV_C = 'Viennoiserie Ind.', D_OUV_P = 'Couque à la Crème', D_HOV = 'Pain';
function grille(cats, { ouvC, ouvP, hov, dd = true } = {}) {
  const COLS = 1 + N + 3;
  const lp = a => `<tr class="p${a.nom === ouvP ? ' ouv' : ''}"><td title="${esc(a.nom)}"><span class="fl" style="display:inline-block;width:14px;color:var(--muted);font-size:10px">${a.nom === ouvP ? '▾' : '▸'}</span>${esc(a.nom)}</td>${cellules(a)}<td class="r">${fN(a.Q)}</td><td class="r">${fE(a.prix, 2)}</td><td class="r">${tauxCell(a)}</td></tr>${a.nom === ouvP && dd ? deroulant(a, COLS).replace('margin:2px 10px 12px 44px', 'margin:4px 12px 12px 34px') : ''}`;
  const lc = c => `<tr class="k"${c.nom === hov ? ' style="outline:1px solid var(--accent);outline-offset:-1px"' : ''}><td><span class="fl" style="display:inline-block;width:14px;color:var(--muted);font-size:10px">${c.nom === ouvC ? '▾' : '▸'}</span><span class="lien" style="color:${c.nom === hov ? 'var(--accent)' : 'var(--ink)'};border-bottom:1px dotted ${c.nom === hov ? 'var(--accent)' : 'rgba(92,88,82,.5)'}">${esc(c.nom)}</span>${c.nom === hov ? '<span class="ouvrir">↗ ouvrir</span>' : `<span style="font-size:10.5px;color:var(--muted);margin-left:8px">${c.n} réf.</span>`}</td>${cellules(c)}<td class="r">${fN(c.Q)}</td><td class="r">${fE(c.V / c.Q, 2)}</td><td class="r">${tauxCell(c)}</td></tr>${c.nom === ouvC ? c.liste.map(lp).join('') : ''}`;
  return `<table class="hm"><thead><tr><th>Catégorie › produit</th>${W.map((s, i) => `<th${i === IE ? ' class="sep"' : ''}>${s.s}<small>${i === IE ? 'en cours' : dCourt(s.du)}</small></th>`).join('')}<th class="r">Pièces<small>12 sem.</small></th><th class="r">Prix<small>moyen</small></th><th class="r">Marge<small>% des ventes</small></th></tr></thead><tbody>${cats.map(lc).join('')}</tbody></table>`;
}
function corpsD() {
  const cats = CATS.slice(0, 14);
  return `${entete()}${periode()}
  ${kpis(TOT)}
  <div class="po-c"><div class="ct"><span class="lab">Les douze dernières semaines — chiffre et marge brute</span><span class="aside">${sem(0)} → ${sem(IE - 1)} · ${sem(IE)} en cours (${W[IE].jours} jours)</span></div>
    <div class="in po-12"><div>${lignesCM(TOT, { w: 1080, h: 270 })}</div>${cote12(TOT)}</div></div>
  <div class="po-c"><div class="ct"><span class="lab">Semaine par semaine, chaque produit</span><span class="seg"><span class="on">Pièces</span><span>Chiffre</span><span>Marge</span></span><span class="recherche">chercher un produit</span><span class="aside" style="display:flex;align-items:center;gap:10px"><span class="hm-leg"><span>la semaine la plus faible de la ligne</span><span class="ramp">${RAMPE.map(c => `<i style="background:${c}"></i>`).join('')}</span><span>la plus forte</span></span></span></div>
    ${grille(cats, { ouvC: D_OUV_C, ouvP: D_OUV_P, hov: D_HOV })}
    <div class="plus" style="margin:10px 16px 14px">▾ les ${CATS.length - cats.length} autres catégories</div></div>
  ${NOTE}`;
}
function modaleD(c) {
  const ouv = c.liste[0].nom;
  return `<div class="m-hd"><div class="t"><div class="kick">${esc(c.grp)} ›</div><h1>${esc(c.nom)}</h1><div class="s">${c.n} références vendues · ${PERIODE} · ${esc(R.magasin)}</div></div><span class="x">✕</span></div>
  <div class="m-bd">${kpis(c, 'k4')}
    <div class="m-sec">Ventes · 12 semaines <span class="seg"><span class="on">Chiffre &amp; marge</span><span>Pièces</span></span></div>
    ${lignesCM(c, { w: 1136, h: 230 })}
    <div class="m-sec">Semaine par semaine <span class="seg"><span class="on">Pièces</span><span>Chiffre</span><span>Marge</span></span><span class="aside">un clic sur un produit le déplie</span></div>
    ${grille([c], { ouvC: c.nom, ouvP: null }).replace(/<tr class="k"[\s\S]*?<\/tr>/, '')}
  </div>`;
}

/* ---------- Écriture ---------- */
const ecrire = (f, s) => fs.writeFileSync(path.join(D, f), s);
const T = 'Onglet Produits — maquette ';
ecrire('a.html', page('A · le tableau', T + 'A', `<div id="dash">${corpsA({ hov: true })}</div>`));
ecrire('a-modale.html', pageModale('A · le tableau — la modale catégorie', T + 'A, modale', corpsA(), modaleA(cat(A_OUV_C))));
ecrire('b.html', page('B · les tuiles', T + 'B', `<div id="dash">${corpsB()}</div>`));
ecrire('b-modale.html', pageModale('B · les tuiles — la modale catégorie', T + 'B, modale', corpsB(), modaleB(cat(B_HOV))));
ecrire('c.html', page('C · la carte volume × marge', T + 'C', `<div id="dash">${corpsC()}</div>`));
ecrire('c-modale.html', pageModale('C · la carte — la modale catégorie', T + 'C, modale', corpsC(), modaleC(cat('Petite Boulangerie'))));
ecrire('d.html', page('D · la grille', T + 'D', `<div id="dash">${corpsD()}</div>`));
ecrire('d-modale.html', pageModale('D · la grille — la modale catégorie', T + 'D, modale', corpsD(), modaleD(cat(D_HOV))));

/* La planche : les quatre côte à côte (les images viennent de capturer.js) */
const PL = [
  ['A', 'Le tableau qui se déplie', 'Groupe › catégorie › produit dans une seule liste, colonnes chiffrées. Le graphique : coût matière + marge = chiffre de chaque semaine.', ['<b>Le plus proche</b> de « Ventes par catégorie » : rien à apprendre', 'Tout se compare en colonne (prix, marge, part, tendance)', 'Long à parcourir : 240 produits, il faut déplier']],
  ['B', 'Les tuiles de catégorie', 'Une tuile par catégorie (chiffre, pièces, jauge de marge, 12 semaines) ; dessous, le palmarès des produits à la marge.', ['<b>Se lit d’un coup d’œil</b> : quelles catégories portent le magasin', 'Le graphique montre le mix par groupe, semaine par semaine', 'Moins de chiffres à l’écran ; 12 tuiles, le reste replié']],
  ['C', 'La carte volume × marge', 'Chaque produit placé selon ses pièces par semaine et sa marge : piliers, volume, pépites, à questionner.', ['<b>Dit quoi faire</b> : le prix à revoir, ce qui est à pousser', 'Les produits à marge négative sautent aux yeux', 'Demande une explication la première fois']],
  ['D', 'La grille semaine par semaine', 'Chaque produit sur 12 colonnes de semaines, la case plus foncée quand la semaine est forte ; courbes chiffre et marge au-dessus.', ['<b>Le détail de chaque semaine</b>, pour chaque produit', 'Les ruptures, les lancements et les arrêts se voient', 'Large : les chiffres d’abord, la marge en dernière colonne']]
];
ecrire('planche.html', `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Onglet Produits — les quatre maquettes</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="po.css"></head><body>
<div style="padding:22px 24px 0"><div class="topbar__kicker" style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)">Dashboard magasin · ${esc(R.magasin)} · ${PERIODE} 2026</div><div style="font-size:26px;letter-spacing:-.01em;margin-top:4px">L’onglet Produits — quatre maquettes</div><div style="font-size:12.5px;color:var(--muted-2);margin-top:6px">Quels produits je vends, combien, à quel prix et à quelle marge ; un graphique sur 12 semaines ; un clic sur une catégorie ouvre une modale, un clic sur un produit le déplie.</div></div>
<div class="planche">${PL.map(([l, t, p, li]) => `<div class="pl-c"><div class="hd"><div class="l">Maquette ${l}</div><h2>${t}</h2><p>${p}</p></div><ul>${li.map(x => `<li>${x}</li>`).join('')}</ul><img src="${l.toLowerCase()}-haut.jpg" alt=""><img src="${l.toLowerCase()}-modale-haut.jpg" alt=""></div>`).join('')}</div></body></html>`);

console.log('écrit : a, b, c, d (+ modales) et planche ·', ARTS.length, 'articles,', CATS.length, 'catégories,', GRPS.length, 'groupes · marge', fP(TOT.taux), '· médiane', medQ.toFixed(2), 'pièces/sem · quadrants', Object.entries(QA).map(([k, o]) => k + ' ' + o.n).join(', '), '· à vérifier', VERIF.length);
