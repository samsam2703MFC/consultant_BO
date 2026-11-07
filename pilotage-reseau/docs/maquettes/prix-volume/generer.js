/* Maquettes « Prix × volume » — Produits › Où ça se vend, un quatrième onglet.
 * Question du réseau : « les volumes de chaque magasin, face au prix qu'il
 * encaisse ». Données RÉELLES de septembre 2026 lues en ligne : les pièces par
 * magasin (/analyse/produits, tranche de septembre) et le prix encaissé de
 * chaque magasin (/analyse/prix-transfert, les douze couples) — voir LISEZMOI.
 *
 *   node docs/maquettes/prix-volume/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const D = require('./donnees.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const px = n => nf(n, 2) + ' €';
const eur = n => nf(Math.round(n), 0) + ' €';
const sgn = (n, d = 0, suf = ' %') => (n > 0 ? '+ ' : n < 0 ? '− ' : '') + nf(Math.abs(n), d) + suf;
const sgnE = n => (n > 0 ? '+ ' : n < 0 ? '− ' : '') + eur(Math.abs(n));

// Les magasins dans l'ordre du cockpit (par nom), et leurs couleurs : celles
// que l'écran « Où ça se vend » donne déjà aux courbes par magasin.
const ORDRE = ['4', '2', '5', '3'];
const PAL = { 4: '#D55E00', 2: '#0072B2', 5: '#009E73', 3: '#CC79A7' };
const NOM = s => D.magasins[s];

/* --- Le calcul, commun aux trois propositions --------------------------------- */
// Volume « à taille égale » : pièces pour 10 000 € de chiffre du magasin, face à
// la moyenne des AUTRES magasins qui vendent la référence.
D.refs.forEach(r => {
  const S = Object.keys(r.mag);
  S.forEach(s => {
    const autres = S.filter(o => o !== s).map(o => r.mag[o].v10k);
    const moy = autres.reduce((a, b) => a + b, 0) / autres.length;
    r.mag[s].rel = moy > 0 ? 100 * (r.mag[s].v10k / moy - 1) : null;
    r.mag[s].auMed = (r.med - r.mag[s].p) * r.mag[s].q; // € par mois si aligné sur le réseau, à volume constant
  });
  const P = S.map(s => r.mag[s].p);
  r.min = Math.min(...P); r.max = Math.max(...P);
  r.ecart = r.min > 0 ? 100 * (r.max - r.min) / r.min : 0;
  r.diff = r.max - r.min > 0.005;
});
const REFS = D.refs;
const nComp = D.comparables, nMeme = D.memePrix;
const nDiff = REFS.filter(r => r.diff).length, n10 = REFS.filter(r => r.ecart >= 10).length;
const nTous = REFS.filter(r => Object.keys(r.mag).length === 4).length;
const plusBas = {}, plusHaut = {};
REFS.filter(r => r.diff).forEach(r => {
  Object.keys(r.mag).forEach(s => {
    if (r.mag[s].p === r.min) { plusBas[s] = (plusBas[s] || 0) + 1; }
    if (r.mag[s].p === r.max) { plusHaut[s] = (plusHaut[s] || 0) + 1; }
  });
});
const premier = o => Object.entries(o).sort((a, b) => b[1] - a[1])[0];

/* --- Le cadre de page, comme les écrans du cockpit ----------------------------- */
const page = (titre, corps) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="pv.css"></head>
<body><div id="ecran">${corps}</div></body></html>`;
const TETE = `<div class="cap">Produits › Où ça se vend</div>
  <div class="barre"><div><h1>Où ça se vend</h1><div class="sous">Chaque référence face à chaque magasin. Par référence, par magasin, en euros — et maintenant le prix encaissé par chaque magasin, face à ce qu’il vend.</div></div>
  <span class="simu">maquette · données réelles de septembre 2026 (panel) · ${D.comparables} références vendues dans au moins deux magasins</span></div>
  <div class="ongl"><span>Par référence</span><span>Par magasin</span><span>En euros · manque à gagner</span><span class="on">Prix × volume <i>nouveau</i></span></div>`;
const PERIODE = `<span class="seg"><span class="on">Septembre 2026 · dernier mois clos</span><span>3 mois</span><span>12 mois</span></span>`;
const tuile = (k, v, s, cls) => `<div class="tuile ${cls || ''}"><div class="cap">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
// L'écart au prix réseau : deux teintes et un neutre — moins cher (bleu), plus cher (abricot).
const teinte = ec => ec <= -5 ? 'bas' : (ec >= 5 ? 'haut' : 'neutre');
const fleche = rel => rel == null ? '' : `<span class="rel ${rel >= 15 ? 'plus' : (rel <= -15 ? 'moins' : '')}">${rel >= 0 ? '▲' : '▼'} ${sgn(rel)}</span>`;

/* A — La grille : chaque référence, chaque magasin, son prix et ce qu'il vend -- */
function ecranA() {
  const L = REFS.slice(0, 22);
  const cell = (r, s) => {
    const m = r.mag[s];
    if (!m) { return `<td class="mag vide">—<div class="q">pas vendue</div></td>`; }
    return `<td class="mag"><span class="px ${teinte(m.ec)}">${px(m.p)}</span><div class="q">${nf(m.q, 0)} u ${fleche(m.rel)}</div></td>`;
  };
  const [bas, nBas] = premier(plusBas), [haut, nHaut] = premier(plusHaut);
  return page('A — la grille prix × volume', `${TETE}
  <div class="barre">${PERIODE}
    <span class="ctl">Toutes les catégories ▾</span><span class="ctl rech">Rechercher une référence…</span>
    <span class="seg"><span>Toutes <b>${nComp}</b></span><span class="on">Prix différents <b>${nDiff}</b></span><span>Écart ≥ 10 % <b>${n10}</b></span></span></div>
  <div class="tuiles">
    ${tuile('Références comparables', String(nComp), `vendues dans au moins deux magasins en septembre · ${nMeme} au même prix partout`)}
    ${tuile('Prix différents', String(nDiff), `${nf(100 * nDiff / nComp, 0)} % des comparables n’ont pas le même prix d’un magasin à l’autre · ${n10} à 10 % d’écart ou plus`)}
    ${tuile('Le moins cher, le plus souvent', esc(NOM(bas)), `prix le plus bas du réseau sur ${nBas} références`, 'bleu')}
    ${tuile('Le plus cher, le plus souvent', esc(NOM(haut)), `prix le plus haut du réseau sur ${nHaut} références`, 'abricot')}
  </div>
  <div class="carte sans">
    <table class="t"><thead><tr><th class="g">Référence</th><th>Prix réseau<small>médiane</small></th>
      ${ORDRE.map(s => `<th class="mag"><i style="background:${PAL[s]}"></i>${esc(NOM(s))}</th>`).join('')}<th>Écart<small>max − min</small></th></tr></thead>
    <tbody>${L.map(r => `<tr><td class="g"><b>${esc(r.nom)}</b><div class="mu">${esc(r.cat)} · ${eur(r.ca)} en septembre</div></td>
      <td class="num">${px(r.med)}</td>${ORDRE.map(s => cell(r, s)).join('')}
      <td class="num ${r.ecart >= 10 ? 'fort' : ''}">${r.diff ? nf(r.ecart, 0) + ' %' : '<span class="mu">même prix</span>'}</td></tr>`).join('')}</tbody></table>
    <div class="pied"><span><span class="px bas">bleu</span> 5 % ou plus sous le prix réseau · <span class="px haut">abricot</span> 5 % ou plus au-dessus · <span class="rel plus">▲</span> <span class="rel moins">▼</span> volume à taille égale (pièces pour 10 000 € de chiffre du magasin) face aux autres magasins</span>
    <span>Prix encaissé = chiffre ÷ pièces, remises comprises · ${L.length} premières sur ${nDiff}, classées par chiffre réseau · un clic ouvre la fiche</span></div>
  </div>`);
}

/* B — La fiche d'une référence : prix contre volume, magasin par magasin ------ */
/** Un pas « rond » (1, 2, 2,5, 5 × 10ⁿ) pour environ n graduations. */
function pasRond(etendue, n) {
  const brut = etendue / n, p10 = Math.pow(10, Math.floor(Math.log10(brut)));
  return [1, 2, 2.5, 5, 10].map(m => m * p10).find(m => m >= brut);
}
function nuage(r, W, H, mini) {
  const S = Object.keys(r.mag);
  const P = S.map(s => r.mag[s].p), V = S.map(s => r.mag[s].v10k);
  const pad = mini ? { l: 8, r: 8, t: 10, b: 14 } : { l: 58, r: 26, t: 24, b: 46 };
  const x0 = Math.min(...P, r.med), x1 = Math.max(...P, r.med);
  const pasX = pasRond(Math.max(0.1, x1 - x0) * 1.4, 5);
  const xa = Math.floor((x0 - (x1 - x0) * 0.12) / pasX) * pasX, xb = Math.ceil((x1 + (x1 - x0) * 0.12) / pasX) * pasX;
  const X = p => pad.l + (W - pad.l - pad.r) * ((p - xa) / (xb - xa));
  const pasY = pasRond(Math.max(...V) * 1.15, 4);
  const vMax = Math.ceil(Math.max(...V) * 1.12 / pasY) * pasY;
  const Y = v => H - pad.b - (H - pad.t - pad.b) * (v / vMax);
  let g = '';
  if (!mini) {
    // Une grille discrète : quatre lignes horizontales, les graduations de prix.
    for (let v = 0; v <= vMax + 1e-9; v += pasY) {
      const y = Y(v);
      g += `<line x1="${pad.l}" x2="${W - pad.r}" y1="${y}" y2="${y}" class="gr"/><text x="${pad.l - 8}" y="${y + 4}" class="ax" text-anchor="end">${nf(v, 0)}</text>`;
    }
    for (let p = xa; p <= xb + 1e-9; p += pasX) { g += `<text x="${X(p)}" y="${H - pad.b + 18}" class="ax" text-anchor="middle">${nf(p, 2)} €</text>`; }
    g += `<text x="${pad.l}" y="${pad.t - 10}" class="ax t">pièces pour 10 000 € de chiffre du magasin</text>`;
    g += `<text x="${W - pad.r}" y="${H - 6}" class="ax t" text-anchor="end">prix encaissé →</text>`;
  }
  g += `<line x1="${X(r.med)}" x2="${X(r.med)}" y1="${pad.t}" y2="${H - pad.b}" class="med"/>`;
  if (!mini) { g += `<text x="${X(r.med) + 6}" y="${pad.t + 12}" class="ax">prix réseau ${px(r.med)}</text>`; }
  const pts = S.map(s => ({ s, x: X(r.mag[s].p), y: Y(r.mag[s].v10k), m: r.mag[s] })).sort((a, b) => a.y - b.y);
  const poses = []; // boîtes des étiquettes déjà posées : on évite de les chevaucher
  const chevauche = b => poses.some(q => b.x < q.x + q.w && q.x < b.x + b.w && b.y < q.y + q.h && q.y < b.y + b.h)
    || (b.y < pad.t + 16 && b.x < X(r.med) + 120 && b.x + b.w > X(r.med));
  pts.forEach(o => {
    g += `<circle cx="${o.x}" cy="${o.y}" r="${mini ? 5 : 8}" fill="${PAL[o.s]}" stroke="#fff" stroke-width="2"><title>${esc(NOM(o.s))} · ${px(o.m.p)} · ${nf(o.m.q, 0)} pièces · ${nf(o.m.v10k, 1)} pour 10 000 €</title></circle>`;
    if (!mini) {
      const txt = NOM(o.s) + ' ' + px(o.m.p) + ' · ' + nf(o.m.q, 0) + ' u', w = txt.length * 6.6 + 4;
      const essais = [[13, 0], [-13 - w, 0], [13, -16], [-13 - w, -16], [13, 16], [-13 - w, 16], [13, -30], [-13 - w, 30]];
      let pos = essais.map(([ex, ey]) => ({ x: o.x + ex, y: o.y + ey - 9, w, h: 15 }))
        .find(b => b.x > pad.l && b.x + b.w < W - 4 && !chevauche(b) && !pts.some(q => q !== o && Math.hypot(q.x - (b.x + b.w / 2), q.y - (b.y + 7)) < 10));
      if (!pos) { pos = { x: o.x + 13, y: o.y - 9, w, h: 15 }; }
      poses.push(pos);
      g += `<text x="${pos.x}" y="${pos.y + 11}" class="lab">${esc(NOM(o.s))} <tspan class="mu2">${px(o.m.p)} · ${nf(o.m.q, 0)} u</tspan></text>`;
    }
  });
  return `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" class="nuage" role="img" aria-label="${esc(r.nom)} : prix encaissé contre volume, par magasin">${g}</svg>`;
}
function ecranB() {
  const SEL = REFS.find(r => r.nom === 'Pain 10 Céréales');
  // La liste de gauche : là où l'écart pèse le plus (écart × chiffre).
  const L = REFS.filter(r => r.diff && Object.keys(r.mag).length >= 3).sort((a, b) => b.ecart * b.ca - a.ecart * a.ca).slice(0, 12);
  if (!L.includes(SEL)) { L.unshift(SEL); L.pop(); }
  const S = ORDRE.filter(s => SEL.mag[s]);
  const cher = S.slice().sort((a, b) => SEL.mag[b].p - SEL.mag[a].p)[0];
  const phrase = `${esc(NOM(cher))} vend le ${esc(SEL.nom)} ${sgn(SEL.mag[cher].ec)} au-dessus du prix réseau et en vend ${nf(Math.abs(SEL.mag[cher].rel), 0)} % ${SEL.mag[cher].rel < 0 ? 'de moins' : 'de plus'} que les autres, à taille égale.`;
  const mini = L.filter(r => r !== SEL).slice(0, 6);
  return page('B — prix contre volume', `${TETE}
  <div class="barre">${PERIODE}<span class="ctl">Toutes les catégories ▾</span><span class="ctl rech">Pain 10 Céréales</span></div>
  <div class="grille">
    <div class="carte liste"><div class="cap">Là où les prix diffèrent le plus</div><div class="mu s">écart de prix × chiffre du réseau</div>
      ${L.map(r => `<div class="li${r === SEL ? ' on' : ''}"><b>${esc(r.nom)}</b><span class="mu">${px(r.min)} → ${px(r.max)} · ${nf(r.ecart, 0)} %</span>
        <span class="pts">${ORDRE.filter(s => r.mag[s]).map(s => `<i style="left:${(100 * (r.mag[s].p - r.min) / Math.max(0.01, r.max - r.min)).toFixed(0)}%;background:${PAL[s]}" title="${esc(NOM(s))} ${px(r.mag[s].p)}"></i>`).join('')}</span></div>`).join('')}</div>
    <div class="droite">
      <div class="carte"><div class="barre"><div><h2>${esc(SEL.nom)}</h2><div class="mu">${esc(SEL.cat)} · septembre 2026 · ${eur(SEL.ca)} dans le réseau</div></div>
        <span class="legende">${S.map(s => `<span><i style="background:${PAL[s]}"></i>${esc(NOM(s))}</span>`).join('')}</span></div>
        ${nuage(SEL, 900, 330, false)}
        <div class="lecture">${phrase} <span class="mu">Le nuage montre ce qui va ensemble, pas ce qui le cause : l’emplacement, la clientèle ou la fraîcheur pèsent aussi.</span></div>
        <table class="t petit"><thead><tr><th class="g">Magasin</th><th>Prix encaissé</th><th>Face au réseau</th><th>Pièces</th><th>Pour 10 000 €</th><th>Face aux autres</th><th>Chiffre</th><th>Au prix réseau<small>à volume constant</small></th></tr></thead>
        <tbody>${S.map(s => { const m = SEL.mag[s]; return `<tr><td class="g"><i class="pt" style="background:${PAL[s]}"></i>${esc(NOM(s))}</td><td class="num"><span class="px ${teinte(m.ec)}">${px(m.p)}</span></td>
          <td class="num">${sgn(m.ec, 1)}</td><td class="num">${nf(m.q, 0)}</td><td class="num">${nf(m.v10k, 1)}</td><td class="num">${fleche(m.rel)}</td><td class="num">${eur(m.q * m.p)}</td>
          <td class="num ${m.auMed > 0 ? 'ok' : (m.auMed < 0 ? 'ko' : '')}">${Math.abs(m.auMed) < 1 ? '<span class="mu">au prix réseau</span>' : sgnE(m.auMed) + ' / mois'}</td></tr>`; }).join('')}</tbody></table>
      </div>
      <div class="carte"><div class="cap">Les autres références où les prix diffèrent — un clic pour la fiche</div>
        <div class="minis">${mini.map(r => `<div class="mini"><b>${esc(r.nom)}</b><span class="mu">${px(r.min)} → ${px(r.max)}</span>${nuage(r, 190, 110, true)}</div>`).join('')}</div></div>
    </div></div>`);
}

/* C — Par magasin : ses prix face au réseau, dans les deux sens, et la simulation */
function ecranC() {
  const s = '3', nom = NOM(s);
  const L = REFS.filter(r => r.mag[s]);
  const bas = L.filter(r => r.mag[s].ec <= -2 && r.med - r.mag[s].p >= 0.05).sort((a, b) => b.mag[s].auMed - a.mag[s].auMed);
  const haut = L.filter(r => r.mag[s].ec >= 2 && r.mag[s].p - r.med >= 0.05).sort((a, b) => a.mag[s].auMed - b.mag[s].auMed);
  const egal = L.length - bas.length - haut.length;
  const sB = bas.reduce((a, r) => a + r.mag[s].auMed, 0), sH = haut.reduce((a, r) => a + r.mag[s].auMed, 0);
  const SIM = D.simu[s];
  const meilleur = Object.entries(SIM).sort((a, b) => b[1].delta - a[1].delta)[0];
  const sim = SIM[meilleur[0]];
  const ligne = (r, sens) => { const m = r.mag[s];
    const signal = sens === 'bas' ? (m.rel != null && m.rel >= 15 ? '<span class="sig ok" title="son volume dépasse celui des autres : le prix bas travaille">prix qui travaille</span>' : '')
      : (m.rel != null && m.rel <= -25 ? '<span class="sig wa" title="volume nettement sous celui des autres">le prix freine ?</span>' : '');
    return `<tr><td class="g"><b>${esc(r.nom)}</b><div class="mu">${esc(r.cat)}</div></td><td class="num"><span class="px ${teinte(m.ec)}">${px(m.p)}</span></td><td class="num mu">${px(r.med)}</td>
      <td class="num">${sgn(m.ec, 0)}</td><td class="num">${nf(m.q, 0)}</td><td class="num">${fleche(m.rel)}${signal}</td><td class="num ${sens === 'bas' ? 'ko' : 'ok'}">${sens === 'bas' ? eur(m.auMed) : '+ ' + eur(-m.auMed)}</td></tr>`; };
  const table = (T, sens, titre, sous) => `<div class="carte sans"><div class="tt"><div class="cap">${titre}</div><div class="mu s">${sous}</div></div>
    <table class="t petit"><thead><tr><th class="g">Référence</th><th>Son prix</th><th>Réseau</th><th>Écart</th><th>Pièces</th><th>Volume<small>face aux autres</small></th><th>${sens === 'bas' ? 'Laissé' : 'Gagné'}<small>/ mois</small></th></tr></thead>
    <tbody>${T.slice(0, 9).map(r => ligne(r, sens)).join('')}${T.length > 9 ? `<tr><td colspan="7" class="g mu">+ ${T.length - 9} autres références · ${eur(Math.abs(T.slice(9).reduce((a, r) => a + r.mag[s].auMed, 0)))} / mois</td></tr>` : ''}</tbody></table></div>`;
  return page('C — par magasin', `${TETE}
  <div class="barre"><span class="ctl fort">${esc(nom)} ▾</span>${PERIODE}<span class="mu">${L.length} références vendues par ${esc(nom)} et au moins un autre magasin</span></div>
  <div class="tuiles">
    ${tuile('Sous le prix réseau', String(bas.length), `${eur(sB)} / mois laissés, à volume constant`, 'bleu')}
    ${tuile('Au-dessus du prix réseau', String(haut.length), `+ ${eur(-sH)} / mois gagnés, à volume constant`, 'abricot')}
    ${tuile('Au prix réseau', String(egal), 'à moins de 2 % ou de 5 centimes de la médiane des autres')}
    ${tuile('Aux prix de ' + esc(NOM(meilleur[0])), sgnE(sim.delta) + ' / mois', `${sgn(sim.pct, 1)} du chiffre · simulation à volumes inchangés ↓`, 'or')}
  </div>
  <div class="deux">${table(bas, 'bas', 'Moins cher que le réseau', 'ce qu’un alignement rapporterait, si le volume tient — « prix qui travaille » : il vend plus que les autres')}
    ${table(haut, 'haut', 'Plus cher que le réseau', 'ce que son prix lui rapporte de plus — « le prix freine ? » : il vend nettement moins que les autres')}</div>
  <div class="carte"><div class="barre"><div><div class="cap">Simulation — appliquer à ${esc(nom)} les prix d’un autre magasin</div><div class="mu s">à volumes inchangés · une référence que l’autre magasin ne vend pas garde le prix de ${esc(nom)}</div></div>
    <span class="seg">${ORDRE.filter(o => o !== s).map(o => `<span class="${o === meilleur[0] ? 'on' : ''}">${esc(NOM(o))} <b class="${SIM[o].delta >= 0 ? 'ok' : 'ko'}">${sgnE(SIM[o].delta)}</b></span>`).join('')}</span></div>
    <div class="simres"><div><div class="v ${sim.delta >= 0 ? 'ok' : 'ko'}">${sgnE(sim.delta)} / mois</div><div class="mu">${sgn(sim.pct, 2)} sur ${eur(sim.caCible)} de chiffre en septembre · ${sim.hausses} hausses, ${sim.baisses} baisses · ${sim.sansPrix} références non vendues à ${esc(NOM(meilleur[0]))} restent au prix de ${esc(nom)}</div></div>
      <table class="t petit"><thead><tr><th class="g">Ce qui pèse le plus</th><th>Pièces</th><th>Prix ${esc(nom)}</th><th>Prix ${esc(NOM(meilleur[0]))}</th><th>Effet / mois</th></tr></thead>
      <tbody>${sim.top.slice(0, 6).map(l => `<tr><td class="g">${esc(l.nom)}</td><td class="num">${nf(l.pieces, 0)}</td><td class="num">${px(l.pc)}</td><td class="num">${px(l.ps)}</td><td class="num ${l.impact >= 0 ? 'ok' : 'ko'}">${sgnE(l.impact)}</td></tr>`).join('')}</tbody></table></div></div>`);
}

const ECRANS = [['a-grille', ecranA], ['b-nuage', ecranB], ['c-magasin', ecranC]];
const PLANCHES = [
  { id: 'a', titre: 'A — La grille : chaque référence, chaque magasin', ecran: 'a-grille',
    acc: 'Une ligne par référence, une colonne par magasin : le prix encaissé (bleu sous le réseau, abricot au-dessus) et, dessous, les pièces vendues et le volume à taille égale face aux autres magasins.',
    plus: ['Tout le réseau d’un coup d’œil, comme « Par référence ».', 'Les anomalies sautent aux yeux (une quiche à 21,90 € à Halle, 14,90 € ailleurs).', 'Filtre « prix différents » : on ne lit que ce qui diffère.'],
    moins: ['Beaucoup de chiffres par case.', 'Le lien prix → volume se lit, mais ne se voit pas.'] },
  { id: 'b', titre: 'B — La fiche : le prix contre le volume, en nuage', ecran: 'b-nuage',
    acc: 'Une référence à la fois : chaque magasin est un point — à droite il vend plus cher, en haut il vend plus (à taille égale). Le trait pointillé est le prix réseau. Dessous, le tableau et ce qu’un alignement changerait.',
    plus: ['Répond à la question telle qu’elle est posée : le volume face au prix.', 'Une phrase de lecture, chiffrée, sous le graphique.', 'Les petites vignettes mènent aux autres références.'],
    moins: ['Une référence à la fois.', 'Quatre magasins, quatre points : une tendance, pas une preuve.'] },
  { id: 'c', titre: 'C — Par magasin : ses prix dans les deux sens, et la simulation', ecran: 'c-magasin',
    acc: 'On choisit un magasin : ce qu’il vend moins cher que le réseau (et ce que ça lui coûte), ce qu’il vend plus cher (et ce que ça lui rapporte), avec un signal quand le volume suit ou décroche. En bas : « si Gosselies appliquait les prix de Corbais ».',
    plus: ['Le prolongement naturel de l’étape « Prix » de l’Analyse magasin, mais dans les deux sens et à jour (panel).', 'Branche le calcul « aux prix de » déjà présent côté serveur.', 'Prêt pour la visite : une page par magasin.'],
    moins: ['Un magasin à la fois.', 'La simulation suppose des volumes inchangés.'] },
];

(async () => {
  for (const [id, f] of ECRANS) { fs.writeFileSync(path.join(OUT, id + '.html'), f()); }
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [id] of ECRANS) {
    const p = await b.newPage({ viewport: { width: 1348, height: 900 }, deviceScaleFactor: 1.25 });
    const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/prix-volume/' + id + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(OUT, id + '.jpg'), type: 'jpeg', quality: 86, fullPage: true });
    console.log('✓', id, await p.evaluate(() => document.documentElement.scrollHeight), err.length ? err : '');
    await p.close();
  }
  for (const P of PLANCHES) {
    const html = page('Planche ' + P.id.toUpperCase(), `<div class="pl"><span class="u">maquette · cockpit › Produits › Où ça se vend › Prix × volume · septembre 2026 · données réelles du panel</span>
      <h1>${esc(P.titre)}</h1><p class="acc">${esc(P.acc)}</p>
      <div class="pg"><div class="col"><h4>Pour</h4><ul class="pl-p">${P.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Contre</h4><ul class="pl-m">${P.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
      <img src="${P.ecran}.jpg" style="width:1290px"></div></div>`).replace('<div id="ecran">', '<div>');
    fs.writeFileSync(path.join(OUT, 'planche-' + P.id + '.html'), html);
    const p = await b.newPage({ viewport: { width: 1720, height: 1000 }, deviceScaleFactor: 1 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/prix-volume/planche-' + P.id + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(OUT, 'planche-' + P.id + '.jpg'), type: 'jpeg', quality: 86, fullPage: true });
    await p.close(); console.log('✓ planche', P.id);
  }
  await b.close();
})();
