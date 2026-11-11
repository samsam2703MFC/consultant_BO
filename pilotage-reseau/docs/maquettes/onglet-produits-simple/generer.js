/* Maquettes « l'onglet Produits, en simple » (demande du 11/11/2026, 3e version : « ça doit être plus simple »).
 * Une seule question : est-ce que ça se développe ? Une seule comparaison : les 6 dernières semaines
 * face aux 6 d'avant. Un seul graphique : le chiffre de chaque semaine. Pas de réseau, pas d'indice.
 *   H — la liste des catégories      h.html
 *   I — les tuiles                    i.html
 *   J — ça monte / ça baisse          j.html
 *   la modale d'une catégorie, ses produits, un produit déplié (prix, quantité, marge) : modale.html
 * Données : les mêmes que la 2e version (../onglet-produits-developpement/generer.js, réutilisé).
 *   node docs/maquettes/onglet-produits-simple/generer.js */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const V = require('../onglet-produits-developpement/generer.js');
const { P, R, W, N, IE, AV, AP, s6, sem, dCourt, dLong, nf, fN, fE, fP, fSP, esc, GRPS, TOT, grp, entete, motifs, seg } = V;

/* ---------- Les mots et les flèches ---------- */
const parSem = (o, I) => s6(o.v, I) / 6;
function evo(o) {
  const k = o.vd[0];
  if (k === 'nouv') { return '<span class="evo nouv">nouveau</span>'; }
  if (k === 'arr') { return '<span class="evo nouv" style="color:var(--muted);border-color:var(--line)">arrêté</span>'; }
  if (k === 'peu') { return '<span class="evo st" style="font-weight:400;font-size:12px;color:var(--muted)">trop peu vendu</span>'; }
  const p = o.surEnt ? o.dQp : o.dVp;
  if (k === 'up') { return `<span class="evo up">▲ ${fSP(p)}</span>`; }
  if (k === 'dn') { return `<span class="evo dn">▼ ${fSP(p)}</span>`; }
  return `<span class="evo st">= stable <small>${fSP(p)}</small></span>`;
}
const MOT = { up: 'se développe', dn: 'recule', st: 'est stable', nouv: 'est nouveau', arr: 'est arrêté', peu: 'se vend peu' };

/* ---------- Le graphique : le chiffre de chaque semaine ---------- */
function barres(o, { w = 1350, h = 210, cle = 'v', etiq = true } = {}) {
  const m = { t: 22, b: etiq ? 36 : 4 };
  const vals = o[cle], max = Math.max(...vals.slice(0, 12)) * 1.05;
  const slot = w / N, bw = Math.min(64, slot * 0.62), y = v => m.t + (h - m.t - m.b) * (1 - v / max);
  const fv = v => cle === 'v' ? (v >= 1000 ? nf(v / 1000, 1) + ' k€' : fE(v)) : fN(v);
  let g = '';
  for (let i = 0; i < N; i++) {
    const cx = slot * (i + .5), ec = i === IE, hh = y(0) - y(vals[i]);
    g += seg(cx - bw / 2, y(0), bw, hh, ec ? 'url(#hr)' : (i < 6 ? 'var(--sp-avant)' : 'var(--sp-apres)'), true, `${sem(i)} · ${cle === 'v' ? fE(vals[i]) : fN(vals[i]) + ' pièces'}${ec ? ' · en cours, ' + W[i].jours + ' jours' : ''}`);
    if (etiq) {
      g += `<text x="${cx.toFixed(1)}" y="${(y(vals[i]) - 6).toFixed(1)}" text-anchor="middle" style="font-size:11px;fill:${ec ? 'var(--muted)' : 'var(--ink)'}">${fv(vals[i])}</text>`;
      g += `<text x="${cx.toFixed(1)}" y="${h - 18}" text-anchor="middle" style="font-size:11px;fill:${ec ? 'var(--muted)' : 'var(--ink)'}">${sem(i)}</text><text x="${cx.toFixed(1)}" y="${h - 5}" text-anchor="middle" style="font-size:10px;fill:var(--faint)">${ec ? 'en cours' : dCourt(W[i].du)}</text>`;
    }
  }
  g += `<line x1="0" x2="${w}" y1="${y(0)}" y2="${y(0)}" stroke="var(--line)"/>`;
  return `<svg class="gr" viewBox="0 0 ${w} ${h}" role="img" aria-label="Chaque semaine">${motifs}${g}</svg>`;
}
const legende = `<div class="leg-s"><span><i style="background:var(--sp-avant)"></i>les 6 semaines d’avant</span><span><i style="background:var(--sp-apres)"></i>les 6 dernières semaines</span><span><i style="background:repeating-linear-gradient(135deg,#B03048 0 2px,#F4E4E7 2px 5px)"></i>${sem(IE)}, en cours</span></div>`;
function mini(o, { w = 150, h = 30, cle = 'v' } = {}) {
  const vals = o[cle], max = Math.max(1, ...vals.slice(0, 12)), bw = (w - 2 * (N - 1)) / N;
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="display:block">${vals.map((v, i) => { const hh = Math.max(v > 0 ? 1.5 : 0, (h - 1) * Math.min(1, v / max)); return `<rect x="${(i * (bw + 2)).toFixed(1)}" y="${(h - hh).toFixed(1)}" width="${bw.toFixed(1)}" height="${hh.toFixed(1)}" rx="1.5" fill="${i === IE ? '#F4E4E7' : (i < 6 ? 'var(--sp-avant)' : 'var(--sp-apres)')}"><title>${sem(i)} · ${cle === 'v' ? fE(v) : fN(v) + ' pièces'}</title></rect>`; }).join('')}</svg>`;
}

/* ---------- Le cadre ---------- */
const page = (lettre, titre, corps, extra) => P.page(lettre, titre, corps, extra).replace('<link rel="stylesheet" href="po.css">', '<link rel="stylesheet" href="../onglet-produits/po.css"><link rel="stylesheet" href="sp.css">');
const pageModale = (lettre, titre, fond, modale) => P.pageModale(lettre, titre, fond, modale).replace('<link rel="stylesheet" href="po.css">', '<link rel="stylesheet" href="../onglet-produits/po.css"><link rel="stylesheet" href="sp.css">');
const quand = `<div style="font-size:13px;color:var(--muted-2);margin:-4px 0 14px">Les 6 dernières semaines (${dLong(W[6].du)} → ${dLong(W[11].au)}) comparées aux 6 d’avant (${dLong(W[0].du)} → ${dLong(W[5].au)}).</div>`;
const magasin = () => `<div class="sp-c"><div class="hd"><h2>Le magasin</h2><span class="aide">le chiffre de chaque semaine</span></div><div class="in">
  <div class="rep"><span class="fl ${TOT.vd[0] === 'up' ? 'up' : (TOT.vd[0] === 'dn' ? 'dn' : 'st')}">${TOT.vd[0] === 'up' ? '▲' : (TOT.vd[0] === 'dn' ? '▼' : '=')} ${fSP(TOT.dVp)}</span><span class="txt">Le magasin ${MOT[TOT.vd[0]]}.</span>
  <span class="sous">${fE(parSem(TOT, AP))} par semaine sur les 6 dernières semaines, contre ${fE(parSem(TOT, AV))} avant.</span></div>
  <div style="margin-top:14px">${barres(TOT)}</div>${legende}</div></div>`;
const NOTE = `<div class="note-s">Chiffres réels, ${esc(R.magasin)}, lus le ${dLong(R.lu)} 2026. Se développe : plus de 8 % de chiffre sur les 6 dernières semaines face aux 6 d’avant ; recule : 8 % de moins ; entre les deux : stable.</div>`;
const CATS_S = GRPS.filter(g => g.qA + g.qP > 0 && g.nom !== 'Sans catégorie').sort((x, y) => y.V - x.V);
const HOV = 'Viennoiserie';
const nbProd = g => g.liste.filter(a => a.qA + a.qP > 0).length;

/* ---------- H — la liste ---------- */
function corpsH({ hov = true } = {}) {
  return `${entete()}${quand}${magasin()}
  <div class="sp-c"><div class="hd"><h2>Vos catégories</h2><span class="aide">cliquez une catégorie pour voir ses produits</span></div><div style="padding-top:10px">
  <table class="liste"><tbody>${CATS_S.map(g => `<tr class="${hov && g.nom === HOV ? 'hov' : ''}"><td class="n">${esc(g.nom)}<small>${nbProd(g)} produits</small></td><td class="r v">${fE(parSem(g, AP))} <small>par semaine</small></td><td class="r" style="width:150px">${evo(g)}</td><td style="width:180px">${mini(g)}</td><td class="go">›</td></tr>`).join('')}</tbody></table></div></div>
  ${NOTE}`;
}
/* ---------- I — les tuiles ---------- */
function corpsI() {
  return `${entete()}${quand}${magasin()}
  <div class="sp-c"><div class="hd"><h2>Vos catégories</h2><span class="aide">cliquez une catégorie pour voir ses produits</span></div><div class="in"><div class="tuil">
  ${CATS_S.map(g => { const k = g.vd[0]; return `<div class="ti ${k === 'up' || k === 'nouv' ? 'up' : (k === 'dn' ? 'dn' : '')}${g.nom === HOV ? ' hov' : ''}"><span class="go">›</span><div class="n">${esc(g.nom)}</div>
    <div class="big ${k === 'up' || k === 'nouv' ? 'up' : (k === 'dn' ? 'dn' : 'st')}">${k === 'up' ? '▲ ' + fSP(g.dVp) : (k === 'dn' ? '▼ ' + fSP(g.dVp) : (k === 'nouv' ? 'nouveau' : '= ' + fSP(g.dVp)))}</div>
    <div class="s">${fE(parSem(g, AP))} par semaine</div><div style="margin-top:10px">${mini(g, { w: 270, h: 34 })}</div></div>`; }).join('')}</div></div></div>
  ${NOTE}`;
}
/* ---------- J — ça monte, ça baisse ---------- */
function corpsJ() {
  const it = g => `<div class="it${g.nom === HOV ? ' hov' : ''}"><span class="n">${esc(g.nom)}<small>${fE(parSem(g, AP))} par semaine · ${nbProd(g)} produits</small></span>${evo(g)}<span>${mini(g, { w: 130, h: 28 })}</span><span class="go">›</span></div>`;
  const monte = CATS_S.filter(g => g.vd[0] === 'up' || g.vd[0] === 'nouv').sort((x, y) => y.dV - x.dV);
  const baisse = CATS_S.filter(g => g.vd[0] === 'dn').sort((x, y) => x.dV - y.dV);
  const stables = CATS_S.filter(g => g.vd[0] === 'st');
  return `${entete()}${quand}${magasin()}
  <div class="sp-c"><div class="hd"><h2>Vos catégories</h2><span class="aide">cliquez une catégorie pour voir ses produits</span></div>
  <div class="in"><div class="mb2"><div class="col"><h3 class="up">▲ Ça monte <span style="font-weight:400;font-size:12.5px;color:var(--muted)">${monte.length} catégories</span></h3>${monte.map(it).join('')}</div>
  <div class="col"><h3 class="dn">▼ Ça baisse <span style="font-weight:400;font-size:12.5px;color:var(--muted)">${baisse.length} catégorie${baisse.length > 1 ? 's' : ''}</span></h3>${baisse.map(it).join('') || '<div class="it"><span class="n" style="color:var(--muted)">rien ne baisse</span></div>'}</div></div></div>
  ${stables.length ? `<div class="stables">= Stables : ${stables.map(g => `${esc(g.nom)} <span class="mu">(${fSP(g.dVp)})</span>`).join(' · ')}</div>` : ''}</div>
  ${NOTE}`;
}

/* ---------- La modale d'une catégorie : ses produits, un produit déplié ---------- */
const OUV = 'Suisse';
function deroulant(a) {
  const F = a.fiche || {}, prix = F.prixTtc || a.prix;
  const qA = a.qA / 6, qP = a.qP / 6, dq = qA > 0 ? 100 * (qP / qA - 1) : null;
  const tP = a.tP, mP = a.mP / 6;
  const ecPrix = F.prixTtc && a.prix ? 100 * (a.prix / F.prixTtc - 1) : 0;
  return `<tr><td colspan="5" style="padding:0;border:none"><div class="dds">
    <div><div class="k">Prix de vente</div><div class="v">${fE(prix, 2)}</div><div class="s">${Math.abs(ecPrix) >= 1 ? 'encaissé en moyenne ' + fE(a.prix, 2) : 'la pièce, TTC'}</div></div>
    <div><div class="k">Quantité</div><div class="v">${nf(qP, 0)} <small>par semaine</small></div><div class="s">avant : ${nf(qA, 0)} · <span class="${dq >= 8 ? 'up' : (dq <= -8 ? 'dn' : 'st')}">${dq >= 8 ? '▲' : (dq <= -8 ? '▼' : '=')} ${fSP(dq)}</span></div></div>
    <div><div class="k">Marge</div>${tP != null ? `<div class="v">${fP(tP, 0)}</div><div class="s">${fE(mP)} par semaine</div>` : '<div class="v">—</div><div class="s">coût de la recette manquant</div>'}</div>
    <div><div class="k">Pièces vendues, chaque semaine</div><div style="margin-top:8px">${barres(a, { w: 420, h: 96, cle: 'q', etiq: false })}</div></div>
    <div class="lien"><span>Fiche complète ↗</span></div>
  </div></td></tr>`;
}
function modale(g) {
  const prods = g.liste.filter(a => a.qA + a.qP > 0).sort((x, y) => y.V - x.V);
  const vus = prods.slice(0, 10);
  return `<div class="m-hd"><div class="t"><h1>${esc(g.nom)}</h1>
    <div class="m-rep">${evo(g).replace('class="evo', 'style="font-size:20px" class="evo')}<span style="font-size:13.5px;color:var(--ink)">${fE(parSem(g, AP))} par semaine sur les 6 dernières semaines, contre ${fE(parSem(g, AV))} avant.</span></div></div><span class="x">✕</span></div>
  <div class="m-bd">${barres(g, { w: 996, h: 190 })}${legende}
    <div class="m-sec" style="margin-top:18px">Ses produits <span class="aside">cliquez un produit : prix, quantité, marge</span></div>
    <table class="prods"><thead><tr><th>Produit</th><th>Prix</th><th>Par semaine</th><th>Évolution</th><th style="width:170px">12 semaines</th></tr></thead><tbody>
    ${vus.map(a => `<tr class="${a.nom === OUV ? 'ouv' : ''}"><td><span class="fl">${a.nom === OUV ? '▾' : '▸'}</span>${esc(a.nom)}</td><td>${fE((a.fiche && a.fiche.prixTtc) || a.prix, 2)}</td><td>${nf(a.qP / 6, 0)} pièces</td><td>${evo(a)}</td><td>${mini(a, { cle: 'q' })}</td></tr>${a.nom === OUV ? deroulant(a) : ''}`).join('')}</tbody></table>
    ${prods.length > vus.length ? `<div class="plus2">▾ les ${prods.length - vus.length} autres produits</div>` : ''}
  </div>`;
}

/* ---------- Écriture ---------- */
const ecrire = (f, s) => fs.writeFileSync(path.join(D, f), s);
ecrire('h.html', page('H · la liste', 'Onglet Produits, en simple — H', `<div id="dash">${corpsH()}</div>`));
ecrire('i.html', page('I · les tuiles', 'Onglet Produits, en simple — I', `<div id="dash">${corpsI()}</div>`));
ecrire('j.html', page('J · ça monte, ça baisse', 'Onglet Produits, en simple — J', `<div id="dash">${corpsJ()}</div>`));
ecrire('modale.html', pageModale('· la modale, commune à H, I, J', 'Onglet Produits, en simple — la modale', corpsH({ hov: false }), modale(grp(HOV))));
ecrire('planche.html', `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Onglet Produits, en simple</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="../onglet-produits/po.css"><style>.planche{grid-template-columns:repeat(3,minmax(0,1fr))}.pl-m{margin:0 24px 30px;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden;display:grid;grid-template-columns:320px 1fr}.pl-m .hd{padding:16px}.pl-m img{width:100%;display:block;border-left:1px solid var(--line)}</style></head><body>
<div style="padding:22px 24px 0"><div style="font-size:26px;letter-spacing:-.01em">L’onglet Produits, en simple — est-ce que ça se développe ?</div><div style="font-size:13px;color:var(--muted-2);margin-top:6px">Un graphique, vos catégories avec une flèche, et au clic : les produits, puis le prix, la quantité et la marge.</div></div>
<div class="planche">${[['H', 'La liste', 'Une ligne par catégorie : son chiffre par semaine, sa flèche, ses 12 semaines.'], ['I', 'Les tuiles', 'Une tuile par catégorie, verte si elle monte, ocre si elle baisse.'], ['J', 'Ça monte, ça baisse', 'Deux colonnes : ce qui monte, ce qui baisse ; les stables en une ligne.']].map(([l, t, p]) => `<div class="pl-c"><div class="hd"><div class="l">Maquette ${l}</div><h2>${t}</h2><p>${p}</p></div><img src="${l.toLowerCase()}-haut.jpg" alt=""></div>`).join('')}</div>
<div class="pl-m"><div class="hd"><div style="font:400 10.5px var(--font-ui);letter-spacing:.14em;text-transform:uppercase;color:var(--accent)">Commun aux trois</div><div style="font-size:18px;margin:4px 0">Le clic sur une catégorie</div><div style="font-size:12.5px;color:var(--muted-2);line-height:1.5">Sa flèche, son graphique, ses produits. Un clic sur un produit le déplie : prix de vente, quantité par semaine (avant → maintenant), marge.</div></div><img src="modale-haut.jpg" alt=""></div></body></html>`);
console.log('écrit : h, i, j, modale, planche ·', CATS_S.map(g => g.nom + ' ' + g.vd[0] + ' ' + fSP(g.dVp)).join(' | '));
