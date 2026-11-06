/* Maquettes « la modale d'un produit » (demande du 06/10/2026) : un clic sur un produit de la
 * liste des catégories du dashboard ouvre une modale à deux onglets.
 *   1. les ventes du produit sur 12 semaines ;
 *   2. sa position de prix face au réseau, en tableau croisé volume × prix.
 * Chiffres réels, lus en lecture seule le 06/10/2026 (reel-cookie-chocolat-lait.json) :
 * /analyse/produits?mois=3 (ventes par semaine et par magasin), /analyse/produits?mois=3&pid=
 * (le réseau l'an dernier), /analyse/prix-volume?mois=1 et 3 (prix encaissé, volume à taille égale).
 *   node docs/maquettes/modale-produit/generer.js   → a-semaines.html, a-prix.html, b-semaines.html, b-prix.html, planche.html */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = JSON.parse(fs.readFileSync(path.join(D, 'reel-cookie-chocolat-lait.json'), 'utf8'));

const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fN = n => nf(Math.round(n));
const fE = (n, d = 2) => nf(n, d) + ' €';
const fP = (n, d = 1) => (n > 0 ? '+' : (n < 0 ? '−' : '')) + nf(Math.abs(n), d) + ' %';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const somme = a => a.reduce((x, y) => x + (y || 0), 0);

/* --- Les semaines : les 12 dernières closes (S29 à S40) et la semaine en cours (S41, 2 jours) --- */
const W = R.semaines, MOI = String(R.shop);
const I = W.tranches.map((_, i) => i).slice(-13);          // 12 closes + l'en cours
const iEnCours = I[I.length - 1];
const nomCourt = { 2: 'Corbais', 3: 'Gosselies', 4: 'Halle', 5: 'Sombreffe' };
const shops = Object.keys(W.parShop);
const moi = I.map(i => W.parShop[MOI][i]);
const reseau = I.map(i => somme(shops.map(s => W.parShop[s][i])) / shops.length);   // moyenne par magasin, les 4
const autres = shops.filter(s => s !== MOI);
const bande = I.map(i => { const v = autres.map(s => W.parShop[s][i]); return [Math.min(...v), Math.max(...v)]; });
const anDernier = I.map(i => W.anDernierReseau[i]);
const lib = I.map(i => W.tranches[i]);
const libDate = i => { const [du] = W.bornes[i]; return du.slice(8, 10) + '/' + du.slice(5, 7); };
const clos = I.slice(0, 12);
const tot12 = somme(clos.map(i => W.parShop[MOI][i]));
const moy12 = tot12 / 12;
const avant = somme(clos.slice(0, 6).map(i => W.parShop[MOI][i])), apres = somme(clos.slice(6).map(i => W.parShop[MOI][i]));
const tendance = 100 * (apres - avant) / avant;
const res12 = somme(clos.map(i => somme(shops.map(s => W.parShop[s][i])) / shops.length));
const faceReseau = 100 * (tot12 - res12) / res12;
const ad12 = somme(clos.map(i => W.anDernierReseau[i]));
const reseauAn = 100 * (res12 - ad12) / ad12;
// Le rang : pièces sur 12 semaines, et pour 10 000 € de chiffre du magasin (sa taille sur les 14 tranches)
const rang = shops.map(s => { const q = somme(clos.map(i => W.parShop[s][i])), q14 = somme(W.parShop[s]); return { s, nom: nomCourt[s], q, v10k: 10000 * q14 / W.tailles[s] }; });

/* --- Le prix face au réseau --- */
const P1 = R.prix1, P3 = R.prix3;
const lignesPrix = P => Object.entries(P.ref.mag).map(([s, m]) => ({ s, nom: nomCourt[s], ...m })).sort((a, b) => a.p - b.p);
const classePrix = ec => ec < -2 ? 0 : (ec > 2 ? 2 : 1);       // sous, au prix, au-dessus (± 2 %, la règle de l'Analyse magasin)
const classeVol = rel => rel < -15 ? 0 : (rel > 15 ? 2 : 1);   // faible, dans la moyenne, fort (± 15 % à taille égale)

/* --- Le cadre commun : la modale par-dessus le dashboard --- */
const PR = R.produit;
const prixAuj = P1.ref.mag[MOI].p;
const tete = (lettre, onglet) => `<div class="m-hd"><div class="t"><h1>${esc(PR.nom)}</h1><div class="s">${esc(PR.groupe)} › ${esc(PR.cat)} · Atelier by - Halle · mardi 6 octobre 2026</div>
  <div class="chips"><span class="chip">aujourd’hui <b>7 vendus</b> · 18 €</span><span class="chip">prix encaissé en septembre <b>${fE(prixAuj)}</b></span><span class="chip">prix réseau <b>${fE(P1.ref.med)}</b></span><span class="chip">marge brute <b>50 %</b></span></div></div><span class="x">✕</span></div>
  <div class="onglets"><span class="${onglet === 1 ? 'on' : ''}">Ventes · 12 semaines</span><span class="${onglet === 2 ? 'on' : ''}">Prix face au réseau <small>volume × prix</small></span></div>`;
const page = (lettre, titre, onglet, corps) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="mp.css"></head>
<body><div class="voile"></div><span class="mq">Maquette ${lettre}</span><div class="modale">${tete(lettre, onglet)}<div class="m-bd">${corps}</div></div></body></html>`;

/* --- Les graphiques en SVG (thin marks, une seule échelle, légende, survol par <title>) --- */
function echelle(max) { const pas = max > 60 ? 20 : (max > 30 ? 10 : 5); const haut = Math.ceil(max / pas) * pas; const t = []; for (let v = 0; v <= haut; v += pas) { t.push(v); } return { haut, t }; }
function cadre(Wd, Hd, m, haut, t) {
  const y = v => m.t + (Hd - m.t - m.b) * (1 - v / haut);
  return { y, grille: t.map(v => `<line x1="${m.l}" x2="${Wd - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="var(--grille)" stroke-width="1"/><text class="ax" x="${m.l - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${v}</text>`).join('') };
}
const enCoursMotif = `<defs><pattern id="hach" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#d7e6f8"/><line x1="0" y1="0" x2="0" y2="6" stroke="var(--s1)" stroke-width="2"/></pattern></defs>`;
/* A : colonnes pour le magasin, lignes pour le réseau et le réseau l'an dernier */
function graphA() {
  const Wd = 936, Hd = 280, m = { l: 36, r: 12, t: 14, b: 34 };
  const { haut, t } = echelle(Math.max(...moi, ...reseau, ...anDernier.map(v => v || 0)));
  const { y, grille } = cadre(Wd, Hd, m, haut, t);
  const slot = (Wd - m.l - m.r) / I.length, cx = k => m.l + slot * (k + .5), bw = 24;
  const col = moi.map((v, k) => { const enC = I[k] === iEnCours, h = y(0) - y(v);
    return `<path d="M${(cx(k) - bw / 2).toFixed(1)},${y(0)} v${(-h + 4).toFixed(1)} q0,-4 4,-4 h${bw - 8} q4,0 4,4 v${(h - 4).toFixed(1)} z" fill="${enC ? 'url(#hach)' : 'var(--s1)'}"><title>${lib[k]} (${libDate(I[k])}) · Halle ${v} pièces${enC ? ' · semaine en cours, 2 jours' : ''}</title></path>`; }).join('');
  const ligne = (serie, coul, nom) => { const pts = serie.map((v, k) => v == null ? null : [cx(k), y(v)]); const ok = pts.filter(Boolean);
    return `<polyline points="${ok.slice(0, -1).map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`
      + `<polyline points="${ok.slice(-2).map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="2" stroke-dasharray="1 4" stroke-linecap="round"/>`
      + pts.map((p, k) => p ? `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="4" fill="${coul}" stroke="#fff" stroke-width="2"><title>${lib[k]} · ${nom} ${nf(serie[k], 1)}</title></circle>` : '').join(''); };
  const x = lib.map((l, k) => `<text class="ax" x="${cx(k).toFixed(1)}" y="${Hd - 18}" text-anchor="middle">${l}</text><text class="ax" x="${cx(k).toFixed(1)}" y="${Hd - 5}" text-anchor="middle" style="font-size:9.5px">${I[k] === iEnCours ? '2 j' : libDate(I[k])}</text>`).join('');
  const kMax = moi.slice(0, 12).indexOf(Math.max(...moi.slice(0, 12)));
  const labels = `<text class="lab" x="${cx(kMax).toFixed(1)}" y="${(y(moi[kMax]) - 8).toFixed(1)}" text-anchor="middle">${moi[kMax]}</text>`
    + `<text class="lab" x="${(cx(11) + 16).toFixed(1)}" y="${(y(reseau[11]) + 4).toFixed(1)}">${nf(reseau[11], 0)}</text>`;
  return `<svg class="graph" viewBox="0 0 ${Wd} ${Hd}" role="img" aria-label="Ventes par semaine, 12 semaines">${enCoursMotif}${grille}${col}${ligne(anDernier, 'var(--s3)', 'réseau, l’an dernier, moyenne par magasin')}${ligne(reseau, 'var(--s2)', 'réseau, moyenne par magasin')}${labels}${x}</svg>`;
}
/* B : une courbe pour le magasin, la bande des trois autres magasins (du plus bas au plus haut) et leur moyenne */
function graphB() {
  const Wd = 660, Hd = 280, m = { l: 36, r: 78, t: 14, b: 34 };
  const { haut, t } = echelle(Math.max(...moi, ...bande.map(b => b[1])));
  const { y, grille } = cadre(Wd, Hd, m, haut, t);
  const step = (Wd - m.l - m.r) / (I.length - 1), cx = k => m.l + step * k;
  const moyAutres = I.map(i => somme(autres.map(s => W.parShop[s][i])) / autres.length);
  const zone = `<path d="M${bande.map((b, k) => cx(k).toFixed(1) + ',' + y(b[1]).toFixed(1)).join(' L')} L${bande.map((b, k) => [k, b]).reverse().map(([k, b]) => cx(k).toFixed(1) + ',' + y(b[0]).toFixed(1)).join(' L')} Z" fill="var(--s2)" opacity=".10"><title>les trois autres magasins, du plus bas au plus haut</title></path>`;
  const ligne = (serie, coul, nom, r) => { const pts = serie.map((v, k) => [cx(k), y(v)]);
    return `<polyline points="${pts.slice(0, -1).map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="2" stroke-linejoin="round"/><polyline points="${pts.slice(-2).map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="2" stroke-dasharray="1 4" stroke-linecap="round"/>`
      + (r ? pts.map((p, k) => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="4" fill="${coul}" stroke="#fff" stroke-width="2"><title>${lib[k]} · ${nom} ${nf(serie[k], 1)}</title></circle>`).join('') : ''); };
  const x = lib.map((l, k) => (k % 2 === 0 || k === I.length - 1) ? `<text class="ax" x="${cx(k).toFixed(1)}" y="${Hd - 18}" text-anchor="middle">${l}</text><text class="ax" x="${cx(k).toFixed(1)}" y="${Hd - 5}" text-anchor="middle" style="font-size:9.5px">${I[k] === iEnCours ? '2 j' : libDate(I[k])}</text>` : '').join('');
  // Les étiquettes de la dernière semaine close, à droite du graphique, chacune à la hauteur de sa valeur (écartées si elles se touchent)
  let yH = y(moi[11]) + 4, yA = y(moyAutres[11]) + 4; if (Math.abs(yH - yA) < 14) { const mid = (yH + yA) / 2, s = yH <= yA ? -1 : 1; yH = mid + 7 * s; yA = mid - 7 * s; }
  const fin = `<text class="lab" x="${Wd - m.r + 10}" y="${yH.toFixed(1)}">Halle ${moi[11]}</text><text class="ax" x="${Wd - m.r + 10}" y="${yA.toFixed(1)}" style="font-weight:600">autres ${nf(moyAutres[11], 0)}</text><text class="ax" x="${Wd - m.r + 10}" y="${(Math.max(yH, yA) + 13).toFixed(1)}" style="font-size:9.5px">en ${lib[11]}</text>`;
  return `<svg class="graph" viewBox="0 0 ${Wd} ${Hd}" role="img" aria-label="Halle face aux autres magasins, 12 semaines">${grille}${zone}${ligne(moyAutres, 'var(--s2)', 'moyenne des trois autres magasins', false)}${ligne(moi, 'var(--s1)', 'Halle', true)}${fin}${x}</svg>`;
}
/* B : le nuage prix × volume, coupé en quatre par le prix réseau et le volume moyen */
function nuage(P) {
  const L = lignesPrix(P), Wd = 640, Hd = 330, m = { l: 52, r: 24, t: 22, b: 44 };
  const px = L.map(l => l.p), vy = L.map(l => l.v10k);
  const x0 = Math.floor((Math.min(...px) - .08) * 10) / 10, x1 = Math.ceil((Math.max(...px) + .08) * 10) / 10;
  const y1 = Math.ceil((Math.max(...vy) + 4) / 5) * 5;
  const X = v => m.l + (Wd - m.l - m.r) * (v - x0) / (x1 - x0), Y = v => m.t + (Hd - m.t - m.b) * (1 - v / y1);
  const vMoy = somme(vy) / vy.length;
  let g = '';
  for (let v = 0; v <= y1; v += 5) { g += `<line x1="${m.l}" x2="${Wd - m.r}" y1="${Y(v).toFixed(1)}" y2="${Y(v).toFixed(1)}" stroke="var(--grille)"/><text class="ax" x="${m.l - 8}" y="${(Y(v) + 3.5).toFixed(1)}" text-anchor="end">${v}</text>`; }
  for (let v = x0; v <= x1 + 1e-9; v += .1) { g += `<text class="ax" x="${X(v).toFixed(1)}" y="${Hd - 26}" text-anchor="middle">${nf(v, 2)} €</text>`; }
  g += `<line x1="${X(P.ref.med).toFixed(1)}" x2="${X(P.ref.med).toFixed(1)}" y1="${m.t}" y2="${Hd - m.b}" stroke="#5f5a54" stroke-width="1"/><text class="ax" x="${(X(P.ref.med) + 5).toFixed(1)}" y="${m.t + 10}">prix réseau ${fE(P.ref.med)}</text>`;
  g += `<line x1="${m.l}" x2="${Wd - m.r}" y1="${Y(vMoy).toFixed(1)}" y2="${Y(vMoy).toFixed(1)}" stroke="#5f5a54" stroke-width="1"/><text class="ax" x="${Wd - m.r}" y="${(Y(vMoy) - 6).toFixed(1)}" text-anchor="end">volume moyen ${nf(vMoy, 1)}</text>`;
  const q = (x, y, t, a) => `<text x="${x}" y="${y}" text-anchor="${a}" style="font:600 10px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;fill:#b3aca2">${t}</text>`;
  g += q(m.l + 8, m.t + 26, 'moins cher · vend plus', 'start') + q(Wd - m.r - 8, m.t + 26, 'plus cher · vend plus', 'end') + q(m.l + 8, Hd - m.b - 8, 'moins cher · vend moins', 'start') + q(Wd - m.r - 8, Hd - m.b - 8, 'plus cher · vend moins', 'end');
  const qMax = Math.max(...L.map(l => l.q));
  const pts = L.map(l => { const r = 6 + 8 * Math.sqrt(l.q / qMax), moiS = l.s === MOI; return `<circle cx="${X(l.p).toFixed(1)}" cy="${Y(l.v10k).toFixed(1)}" r="${r.toFixed(1)}" fill="${moiS ? 'var(--s1)' : '#8a847c'}" stroke="#fff" stroke-width="2"><title>${l.nom} · ${fE(l.p)} (${fP(l.ec)} face au réseau) · ${nf(l.v10k, 1)} pièces pour 10 000 € de chiffre (${fP(l.rel)}) · ${fN(l.q)} pièces</title></circle>`
    + (() => { const t = `${l.nom} · ${fE(l.p)}`, lg = t.length * 6.4, xd = X(l.p) + r + 5, gauche = X(l.p) < X(P.ref.med) && xd + lg > X(P.ref.med) - 4;
      return `<text class="${moiS ? 'lab' : 'ax'}" x="${(gauche ? X(l.p) - r - 5 : xd).toFixed(1)}" y="${(Y(l.v10k) + 4).toFixed(1)}" text-anchor="${gauche ? 'end' : 'start'}" style="${moiS ? '' : 'font-weight:600;fill:#5f5a54'}">${t}</text>`; })(); }).join('');
  const axes = `<text class="ax" x="${(m.l + Wd - m.r) / 2}" y="${Hd - 6}" text-anchor="middle" style="font-weight:600">prix encaissé →</text><text class="ax" transform="translate(14 ${(m.t + Hd - m.b) / 2}) rotate(-90)" text-anchor="middle" style="font-weight:600">pièces pour 10 000 € de chiffre →</text>`;
  return `<svg class="graph" viewBox="0 0 ${Wd} ${Hd}" role="img" aria-label="Prix encaissé face au volume, par magasin">${g}${pts}${axes}</svg>`;
}

/* --- Le tableau croisé 3 × 3 (A) --- */
function croise(P) {
  const L = lignesPrix(P);
  const lignes = [[2, 'Au-dessus du prix réseau', 'au-dessus de +2 %'], [1, 'Au prix réseau', 'de −2 à +2 %'], [0, 'Sous le prix réseau', 'en dessous de −2 %']];
  const cols = [[0, 'Vend moins', 'en dessous de −15 %'], [1, 'Dans la moyenne', 'de −15 à +15 %'], [2, 'Vend plus', 'au-dessus de +15 %']];
  let h = `<div class="croise"><div class="titre-y">prix ↓ · volume →</div>${cols.map(([, t, s]) => `<div class="cx">${t}<br>${s}</div>`).join('')}`;
  lignes.forEach(([lp, t, s]) => { h += `<div class="cy">${t}<small>${s} face à ${fE(P.ref.med)}</small></div>`;
    cols.forEach(([cv]) => { const ici = L.filter(l => classePrix(l.ec) === lp && classeVol(l.rel) === cv);
      h += `<div class="cl${ici.length ? '' : ' vide'}">${ici.map(l => `<span class="mag${l.s === MOI ? ' moi' : ''}" title="${esc(l.nom)} · ${fE(l.p)} · ${nf(l.v10k, 1)} pour 10 000 €"><i></i>${l.nom}<small>${fE(l.p)} · vol. ${fP(l.rel, 0)}</small></span>`).join('')}</div>`; }); });
  return h + '</div>';
}
function tablePrix(P) {
  const L = lignesPrix(P);
  return `<table class="tab large"><thead><tr><th>Magasin</th><th>Prix encaissé</th><th>Face au prix réseau</th><th>Pièces</th><th>Pour 10 000 € de chiffre</th><th>Face aux autres</th><th>Au prix réseau, par mois</th></tr></thead><tbody>
    ${L.map(l => `<tr class="${l.s === MOI ? 'moi' : ''}"><td><span class="sw" style="background:${l.s === MOI ? 'var(--s1)' : '#8a847c'}"></span>${l.nom}</td><td>${fE(l.p)}</td><td>${fP(l.ec)}</td><td>${fN(l.q)}</td><td>${nf(l.v10k, 1)}</td><td class="${l.rel < 0 ? 'dn' : 'up'}">${fP(l.rel, 0)}</td><td class="${l.auMed > 0 ? 'up' : 'mu'}">${l.auMed > 0 ? '+' : (l.auMed < 0 ? '−' : '')}${fE(Math.abs(l.auMed), 0)}</td></tr>`).join('')}</tbody></table>`;
}
const lecture = P => { const h = P.ref.mag[MOI], L = lignesPrix(P);
  const plusVend = L.slice().sort((a, b) => b.rel - a.rel)[0];
  return `<div class="lecture"><b>Halle encaisse ${fE(h.p)}</b>, ${fP(h.ec)} face au prix réseau (${fE(P.ref.med)}, la médiane des magasins), et vend ${fP(h.rel, 0)} face aux autres à taille égale. Au prix réseau, à volume égal : <b class="gain">${h.auMed > 0 ? '+' : ''}${fE(h.auMed, 0)} par mois</b>. Le magasin qui vend le plus à taille égale, ${plusVend.nom}, est ${plusVend.ec < 0 ? 'lui aussi sous' : 'au-dessus du'} prix réseau (${fP(plusVend.ec)}).</div>`; };

/* --- A : colonnes + tableau croisé 3 × 3 --- */
function maquetteA(onglet) {
  let c;
  if (onglet === 1) {
    c = `<div class="barre"><span class="seg"><span class="on">12 semaines</span><span>6 mois</span></span><span class="seg"><span class="on">Pièces</span><span>Chiffre</span></span><span class="note">semaine du lundi au dimanche · la dernière est en cours (2 jours)</span></div>
      <div class="tuiles">
        <div class="tuile"><div class="k">12 semaines</div><div class="v">${fN(tot12)} <small>pièces</small></div><div class="d">${nf(moy12, 1)} par semaine</div></div>
        <div class="tuile"><div class="k">6 dernières semaines</div><div class="v">${fN(apres)} <small>pièces</small></div><div class="d"><b class="${tendance >= 0 ? 'up' : 'dn'}">${fP(tendance, 0)}</b> face aux 6 d’avant (${fN(avant)})</div></div>
        <div class="tuile"><div class="k">Face au réseau</div><div class="v">${fP(faceReseau, 0)}</div><div class="d">moyenne par magasin : ${fN(res12)} pièces</div></div>
        <div class="tuile"><div class="k">Le réseau, un an avant</div><div class="v">${fP(reseauAn, 0)}</div><div class="d">${fN(res12)} cette année, ${fN(ad12)} l’an dernier</div></div></div>
      <div class="leg"><span><i class="bar" style="background:var(--s1)"></i>Halle</span><span><i class="ln" style="background:var(--s2)"></i>le réseau, moyenne par magasin</span><span><i class="ln" style="background:var(--s3)"></i>le réseau l’an dernier, mêmes semaines</span><span><i class="bar" style="background:url(#);background-image:repeating-linear-gradient(45deg,#d7e6f8 0 3px,#2a78d6 3px 5px)"></i>semaine en cours</span></div>
      ${graphA()}
      <table class="tab sem"><thead><tr><th>Semaine</th>${I.map((i, k) => `<th>${lib[k]}${i === iEnCours ? '*' : ''}</th>`).join('')}<th>12 sem.</th></tr></thead><tbody>
        <tr class="moi"><td>Halle</td>${moi.map(v => `<td>${v}</td>`).join('')}<td>${fN(tot12)}</td></tr>
        <tr><td>Réseau, moyenne</td>${reseau.map(v => `<td>${nf(v, 0)}</td>`).join('')}<td>${fN(res12)}</td></tr>
        <tr><td>Réseau, l’an dernier</td>${anDernier.map(v => `<td>${v == null ? '—' : nf(v, 0)}</td>`).join('')}<td>${fN(ad12)}</td></tr></tbody></table>
      <div class="note" style="margin-top:6px">* en cours, 2 jours. Source : les ventes par semaine de chaque magasin, la fiche « Où ça se vend » du cockpit.</div>`;
  } else {
    c = `<div class="barre"><span class="seg"><span class="on">Septembre</span><span>3 derniers mois</span><span>12 mois</span></span><span class="note">prix encaissé = chiffre ÷ pièces, remises comprises · volume à taille égale = pièces pour 10 000 € de chiffre du magasin</span></div>
      ${croise(P1)}${lecture(P1)}${tablePrix(P1)}`;
  }
  return page('A', 'Modale A : colonnes et tableau croisé', onglet, c);
}
/* --- B : courbe et rang + nuage prix × volume --- */
function maquetteB(onglet) {
  let c;
  if (onglet === 1) {
    const maxQ = Math.max(...rang.map(r => r.q)), maxV = Math.max(...rang.map(r => r.v10k));
    const parQ = rang.slice().sort((a, b) => b.q - a.q), parV = rang.slice().sort((a, b) => b.v10k - a.v10k);
    c = `<div class="barre"><span class="seg"><span class="on">12 semaines</span><span>6 mois</span></span><span class="note">semaine du lundi au dimanche · la dernière est en cours (2 jours)</span></div>
      <div class="deux"><div><div class="leg"><span><i class="ln" style="background:var(--s1)"></i>Halle</span><span><i class="ln" style="background:var(--s2)"></i>moyenne des trois autres magasins</span><span><i class="band" style="background:var(--s2);opacity:.18"></i>du plus bas au plus haut des trois autres</span></div>${graphB()}</div>
        <div class="rang"><div class="k">Le rang sur 12 semaines</div>${parQ.map(r => `<div class="r${r.s === MOI ? ' moi' : ''}"><span>${r.nom}</span><span class="b"><i style="width:${(100 * r.q / maxQ).toFixed(0)}%"></i></span><span class="n">${fN(r.q)}</span></div>`).join('')}
          <div class="sep"></div><div class="k">À taille égale</div><div class="note" style="margin:-3px 0 4px">pièces pour 10 000 € de chiffre du magasin</div>${parV.map(r => `<div class="r${r.s === MOI ? ' moi' : ''}"><span>${r.nom}</span><span class="b"><i style="width:${(100 * r.v10k / maxV).toFixed(0)}%"></i></span><span class="n">${nf(r.v10k, 1)}</span></div>`).join('')}</div></div>
      <div class="lecture">Sur 12 semaines, Halle a vendu <b>${fN(tot12)} cookies chocolat lait</b>, ${nf(moy12, 1)} par semaine ; les 6 dernières semaines font <b>${fP(tendance, 0)}</b> face aux 6 d’avant. Corbais vend le plus en pièces, mais c’est le plus grand magasin : à taille égale, ${parV[0].nom} est devant.</div>`;
  } else {
    c = `<div class="barre"><span class="seg"><span class="on">Septembre</span><span>3 derniers mois</span><span>12 mois</span></span><span class="note">chaque point : un magasin, sa taille = les pièces vendues</span></div>
      <div class="deux d270"><div>${nuage(P1)}</div>
        <div class="rang"><div class="k">Halle, en septembre</div>
          <div class="r moi" style="grid-template-columns:1fr auto"><span>Prix encaissé</span><span class="n">${fE(P1.ref.mag[MOI].p)}</span></div>
          <div class="r" style="grid-template-columns:1fr auto"><span>Prix réseau (médiane)</span><span class="n">${fE(P1.ref.med)}</span></div>
          <div class="r" style="grid-template-columns:1fr auto"><span>Écart</span><span class="n">${fP(P1.ref.mag[MOI].ec)}</span></div>
          <div class="sep"></div>
          <div class="r moi" style="grid-template-columns:1fr auto"><span>Pour 10 000 € de chiffre</span><span class="n">${nf(P1.ref.mag[MOI].v10k, 1)}</span></div>
          <div class="r" style="grid-template-columns:1fr auto"><span>Face aux autres</span><span class="n" style="color:#2d7a3e">${fP(P1.ref.mag[MOI].rel, 0)}</span></div>
          <div class="sep"></div>
          <div class="r" style="grid-template-columns:1fr auto"><span>Au prix réseau, par mois</span><span class="n" style="color:#2d7a3e">+${fE(P1.ref.mag[MOI].auMed, 0)}</span></div>
          <div class="note">à volume égal ; un prix plus haut peut faire vendre moins</div></div></div>
      ${tablePrix(P1)}`;
  }
  return page('B', 'Modale B : courbe, rang et nuage', onglet, c);
}

for (const [f, h] of [['a-semaines.html', maquetteA(1)], ['a-prix.html', maquetteA(2)], ['b-semaines.html', maquetteB(1)], ['b-prix.html', maquetteB(2)]]) {
  fs.writeFileSync(path.join(D, f), h); console.log(f, h.length, 'octets');
}
console.log(JSON.stringify({ tot12, moy12: +moy12.toFixed(2), tendance: +tendance.toFixed(1), faceReseau: +faceReseau.toFixed(1), reseauAn: +reseauAn.toFixed(1), rang }));

/* --- La planche --- */
const PL = [
  ['A', 'Colonnes et tableau croisé', 'Onglet 1 : les 12 semaines de Halle en colonnes, la moyenne du réseau et le réseau l’an dernier en lignes, quatre chiffres clés et le tableau semaine par semaine. Onglet 2 : un tableau croisé 3 × 3, prix (sous, au, au-dessus du réseau) × volume à taille égale (moins, dans la moyenne, plus), chaque magasin dans sa case, puis le détail.', 'a-semaines.png', 'a-prix.png',
    ['le tableau croisé se lit d’un coup d’œil : la case de Halle et celle des autres', 'les chiffres de chaque semaine sont sous le graphique'],
    ['les seuils (2 % de prix et 15 % de volume, de part et d’autre) rangent les magasins en cases : un magasin juste au bord change de case']],
  ['B', 'Courbe, rang et nuage', 'Onglet 1 : Halle en courbe face à la bande des trois autres magasins et à leur moyenne, et le rang du magasin sur 12 semaines, en pièces et à taille égale. Onglet 2 : un nuage prix × volume coupé en quatre par le prix réseau et le volume moyen, chaque magasin un point à sa vraie place.', 'b-semaines.png', 'b-prix.png',
    ['la place exacte de chaque magasin, sans seuil', 'le rang à taille égale montre ce que les pièces cachent'],
    ['un nuage demande un peu plus de lecture qu’un tableau', 'pas de chiffres semaine par semaine à l’écran (au survol)']],
];
const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>La modale d’un produit</title><link rel="stylesheet" href="/public/assets/ds/global.css"><style>
body{margin:0;background:#EAE4DC;font-family:var(--font-ui);color:#222}.w{width:2100px;margin:0 auto;padding:24px}h1{font:400 30px var(--font-display);margin:0 0 4px}.sous{font-size:13px;color:#555;margin-bottom:18px;line-height:1.5}
.g{display:grid;grid-template-columns:1fr 1fr;gap:24px}.col{background:#fff;border-radius:14px;padding:16px}.col h2{font:400 22px var(--font-display);margin:0}.col h2 span{color:#8D1D2C;margin-right:8px}
.col p{font-size:12.5px;color:#444;margin:6px 0 10px;line-height:1.45}.cadre{overflow:hidden;border:.5px solid rgba(0,0,0,.12);border-radius:8px;margin-bottom:6px}.cadre img{width:100%;display:block}
h4{font:600 10px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;color:#666;margin:10px 0 4px}ul{margin:0;padding-left:18px;font-size:12.5px;line-height:1.5}ul.p li::marker{color:#2D7A3E}ul.m li::marker{color:#C0182B}.cap{font-size:11px;color:#777;margin:0 0 10px}</style></head><body><div class="w">
<h1>La modale d’un produit : ventes sur 12 semaines, prix face au réseau</h1>
<div class="sous">Un clic sur un produit de la liste des catégories du dashboard. Exemple réel : Cookie Chocolat Lait, Atelier by - Halle, lu le 6 octobre 2026 · ${fN(tot12)} pièces en 12 semaines · prix encaissé en septembre ${fE(prixAuj)} pour un prix réseau de ${fE(P1.ref.med)}</div>
<div class="g">${PL.map(([l, t, d, i1, i2, plus, moins]) => `<div class="col"><h2><span>${l}</span>${t}</h2><p>${d}</p><div class="cadre"><img src="${i1}" alt=""></div><div class="cap">onglet 1 · ventes, 12 semaines</div><div class="cadre"><img src="${i2}" alt=""></div><div class="cap">onglet 2 · prix face au réseau</div>
<h4>Ce qu’elle apporte</h4><ul class="p">${plus.map(x => `<li>${x}</li>`).join('')}</ul><h4>Ce qu’elle coûte</h4><ul class="m">${moins.map(x => `<li>${x}</li>`).join('')}</ul></div>`).join('')}</div></div></body></html>`;
fs.writeFileSync(path.join(D, 'planche.html'), planche);
console.log('planche.html', planche.length, 'octets');
