/* Trois propositions pour poser les OBJECTIFS DE CAMPAGNE dans le plan d'action
 * du dashboard magasin (téléphone), et, sur chaque action, un graphique du
 * nombre de clients face au N-1 et à l'objectif.
 *
 * Données RÉELLES de Gosselies (shop 3), lues en ligne le 01/10/2026 — voir
 * donnees.json : la campagne « Réseaux Sociaux - Septembre 2026 » (+10 % de
 * trafic : 131 clients/jour en N-1 → 4 323 clients visés, 3 866 faits), la
 * campagne « Développement B2B - Lancement Webshop » (01/10 → 29/11, sans
 * objectif chiffré), les clients jour par jour et leur N-1 aligné à −364 jours.
 * Les trois ACTIONS du plan sont une ILLUSTRATION : Gosselies n'en a aucune en
 * ligne, et le lien action ↔ campagne n'existe pas encore dans le cockpit.
 *
 *   node docs/maquettes/plan-campagne/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const D = require('./donnees.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const eur = n => nf(Math.round(n)) + ' €';
const sg = n => (n >= 0 ? '+' : '−') + nf(Math.abs(Math.round(n)));
const pct = (a, b) => b ? Math.round(100 * a / b) : null;
const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const fDL = d => { const t = new Date(d + 'T12:00:00'); return t.getDate() + ' ' + MOIS[t.getMonth()]; };
// Le CSS du module visites vit dans visites.js : on le reprend tel quel.
const VI = fs.readFileSync(path.join(__dirname, '../../../public/assets/js/visites.js'), 'utf8');
const VI_CSS = VI.slice(VI.indexOf('const CSS = `') + 13, VI.indexOf('\n`;', VI.indexOf('const CSS = `')));

/* --- Les chiffres --------------------------------------------------------- */
const [CS, CO] = D.campagnes;                   // septembre (close), octobre–novembre (en cours, jour 1)
const JS = D.joursSept, JO = D.joursOctNov;
const objJour = CS.clientsPrevus / CS.jours;     // 144,1 clients / jour visés
const manque = CS.clientsPrevus - CS.reel;       // 457
const vsN1 = CS.reel - CS.clientsA1;             // −67
// Les semaines de septembre, lundi → dimanche, N et N-1 (aligné).
const SEM = [['1–6', 0, 6], ['7–13', 6, 13], ['14–20', 13, 20], ['21–27', 20, 27], ['28–30', 27, 30]].map(([lab, a, b]) => {
  const j = JS.slice(a, b);
  return { lab, jours: j.length, reel: j.reduce((s, x) => s + (x.tickets || 0), 0), n1: j.reduce((s, x) => s + (x.n1 || 0), 0), obj: Math.round(objJour * j.length) };
});
// Les semaines d'octobre–novembre (campagne B2B) : N-1 connu, réel au jour 1.
const SEMO = [];
for (let a = 0; a < JO.length; a += 7) {
  const j = JO.slice(a, Math.min(a + 7, JO.length));
  const ecoules = j.filter(x => x.tickets != null);
  SEMO.push({ lab: fD(j[0].date).slice(0, 2) + '/' + fD(j[0].date).slice(3), jours: j.length, reel: ecoules.length ? ecoules.reduce((s, x) => s + x.tickets, 0) : null, n1: j.reduce((s, x) => s + (x.n1 || 0), 0), n1Ecoule: ecoules.length ? ecoules.reduce((s, x) => s + (x.n1 || 0), 0) : null, joursEcoules: ecoules.length, obj: null });
}
// Les trois actions (illustration).
const ACTIONS = [
  { st: ['Validé', 'st-val'], pr: 'P1', delai: 'faite le 29 sept.', titre: 'Publier 3 stories par semaine : vitrine du matin, nouveautés, coulisses', meta: 'Magasin · Sam V. le 2 sept. · « 2 posts + 3 stories, avant 8 h »', camp: CS },
  { st: ['Ouvert', 'st-ouv'], pr: 'P0', delai: 'délai 15 oct.', titre: 'Proposer le webshop aux clients pro : carte + QR glissés dans chaque commande', meta: 'Magasin · Sam V. le 30 sept. · « les 7 clients pro de la semaine d’abord »', camp: CO },
  { st: ['Ouvert', 'st-ouv'], pr: 'P2', delai: 'délai 8 oct.', titre: 'Remettre les tartes en vitrine avant 7 h', meta: 'Magasin · Sam V. le 30 sept.', camp: null },
];

/* --- Les graphiques (SVG, étiquettes directes) ---------------------------- */
const W = 330;
/** A — les semaines côte à côte : réel en couleur, N-1 en gris, l'objectif en trait. */
function gBarres(S, obj) {
  const H = 150, top = 22, bas = 26, gL = 4, gR = 4; let h0 = '';
  const max = Math.max(...S.map(s => Math.max(s.reel || 0, s.n1 || 0, s.obj || 0))) * 1.08;
  const y = v => top + (H - top - bas) * (1 - v / max);
  const n = S.length, cw = (W - gL - gR) / n, bw = Math.min(26, cw * 0.36), gap = 3;
  if (obj) { h0 = `<text class="o" x="${gL}" y="9">objectif ${nf(Math.round(objJour))} clients / jour</text>`; }
  let h = `<svg class="pc-g" viewBox="0 0 ${W} ${H}" role="img" aria-label="Clients par semaine, réel face au N-1 et à l’objectif">` + h0;
  h += `<line class="axe" x1="${gL}" x2="${W - gR}" y1="${H - bas}" y2="${H - bas}"/>`;
  S.forEach((s, i) => {
    const cx = gL + cw * i + cw / 2;
    const xN = cx - gap / 2 - bw, xR = cx + gap / 2;
    h += `<rect class="n1" x="${xN.toFixed(1)}" y="${y(s.n1).toFixed(1)}" width="${bw}" height="${(H - bas - y(s.n1)).toFixed(1)}" rx="2"/>`;
    h += `<text class="n" x="${(xN + bw / 2).toFixed(1)}" y="${(y(s.n1) - 3).toFixed(1)}" text-anchor="middle">${nf(s.n1)}</text>`;
    if (s.reel != null) {
      h += `<rect class="reel" x="${xR.toFixed(1)}" y="${y(s.reel).toFixed(1)}" width="${bw}" height="${(H - bas - y(s.reel)).toFixed(1)}" rx="2"/>`;
      h += `<text class="p" x="${(xR + bw / 2).toFixed(1)}" y="${(y(s.reel) - 3).toFixed(1)}" text-anchor="middle">${nf(s.reel)}</text>`;
    } else {
      h += `<rect x="${xR.toFixed(1)}" y="${(H - bas - 3)}" width="${bw}" height="3" rx="1" fill="#e9e2d8"/>`;
    }
    if (s.obj) { const yo = y(s.obj); h += `<line class="objl" x1="${(cx - cw / 2 + 3).toFixed(1)}" x2="${(cx + cw / 2 - 3).toFixed(1)}" y1="${yo.toFixed(1)}" y2="${yo.toFixed(1)}"/>`; }
    h += `<text x="${cx.toFixed(1)}" y="${H - bas + 11}" text-anchor="middle">${esc(s.lab)}</text>`;
    const d = s.reel != null ? s.reel - (s.n1Ecoule != null ? s.n1Ecoule : s.n1) : null;
    if (d != null) { h += `<text x="${cx.toFixed(1)}" y="${H - bas + 22}" text-anchor="middle" style="fill:${d >= 0 ? '#2d7a3e' : '#C0182B'};font-weight:700">${sg(d)}${s.n1Ecoule != null && s.joursEcoules < s.jours ? ' (' + s.joursEcoules + ' j)' : ''}</text>`; }
  });
  return h + '</svg>';
}
/** B — la trajectoire : clients cumulés depuis le début, réel / N-1 / objectif. */
function gCumul(J, prevus, jours, labFin) {
  const H = 150, top = 14, bas = 18, gL = 4, gR = 58;
  const cumR = [], cumN = []; let r = 0, n = 0, dernier = -1;
  J.forEach((j, i) => { if (j.tickets != null) { r += j.tickets; dernier = i; } n += j.n1 || 0; cumR.push(r); cumN.push(n); });
  const max = Math.max(cumN[cumN.length - 1], prevus || 0, cumR[dernier] || 0) * 1.05;
  const x = i => gL + (W - gL - gR) * i / (jours - 1), y = v => top + (H - top - bas) * (1 - v / max);
  const pathN = cumN.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
  const pathR = cumR.slice(0, dernier + 1).map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
  let h = `<svg class="pc-g" viewBox="0 0 ${W} ${H}" role="img" aria-label="Clients cumulés depuis le début de la campagne">`;
  h += `<line class="axe" x1="${gL}" x2="${W - gR}" y1="${H - bas}" y2="${H - bas}"/>`;
  // Les semaines, en repères légers.
  for (let i = 7; i < jours; i += 7) { h += `<line class="axe" x1="${x(i).toFixed(1)}" x2="${x(i).toFixed(1)}" y1="${H - bas}" y2="${H - bas + 3}"/>`; }
  const nFin = cumN[cumN.length - 1];
  if (prevus) { h += `<line class="objl" x1="${x(0)}" y1="${y(0).toFixed(1)}" x2="${x(jours - 1).toFixed(1)}" y2="${y(prevus).toFixed(1)}"/>`; }
  h += `<path class="n1l" d="${pathN}"/>`;
  if (dernier >= 0) { h += `<path class="reell" d="${pathR}"/><circle cx="${x(dernier).toFixed(1)}" cy="${y(cumR[dernier]).toFixed(1)}" r="3.5" class="reel"/>`; }
  // Les étiquettes de fin, écartées d'au moins 10 px : l'ordre vertical reste celui des valeurs.
  const labs = [];
  if (prevus) { labs.push({ y: y(prevus), cls: 'o', txt: nf(prevus) + ' visés', x: x(jours - 1) + 4 }); }
  labs.push({ y: y(nFin), cls: 'n', txt: nf(nFin) + ' N-1', x: x(jours - 1) + 4 });
  if (dernier >= 0) { labs.push({ y: y(cumR[dernier]), cls: 'p', txt: nf(cumR[dernier]) + (dernier === jours - 1 ? '' : ' au j' + (dernier + 1)), x: x(dernier) + (dernier === jours - 1 ? 4 : 6) }); }
  labs.sort((a, b) => a.y - b.y);
  for (let i = 1; i < labs.length; i++) { if (labs[i].y - labs[i - 1].y < 10) { labs[i].y = labs[i - 1].y + 10; } }
  for (let i = labs.length - 1; i >= 0; i--) { if (labs[i].y > H - bas - 2) { labs[i].y = H - bas - 2; } if (i < labs.length - 1 && labs[i + 1].y - labs[i].y < 10) { labs[i].y = labs[i + 1].y - 10; } }
  labs.forEach(l => { h += `<text class="${l.cls}" x="${l.x.toFixed(1)}" y="${(l.y + 3).toFixed(1)}">${l.txt}</text>`; });
  h += `<text x="${gL}" y="${H - 4}">${esc(labFin[0])}</text><text x="${(W - gR).toFixed(1)}" y="${H - 4}" text-anchor="end">${esc(labFin[1])}</text>`;
  return h + '</svg>';
}
/** C — le jour par jour : barres réelles, N-1 en points reliés, objectif en trait. */
function gJours(J, objJ, labs) {
  const H = 130, top = 18, bas = 16, gL = 4, gR = 4;
  const n = J.length, max = Math.max(...J.map(j => Math.max(j.tickets || 0, j.n1 || 0)), objJ || 0) * 1.1;
  const cw = (W - gL - gR) / n, bw = Math.max(2, cw - 2);
  const x = i => gL + cw * i, y = v => top + (H - top - bas) * (1 - v / max);
  let h = `<svg class="pc-g" viewBox="0 0 ${W} ${H}" role="img" aria-label="Clients par jour">`;
  h += `<line class="axe" x1="${gL}" x2="${W - gR}" y1="${H - bas}" y2="${H - bas}"/>`;
  J.forEach((j, i) => { if (j.tickets != null) { h += `<rect class="reel" x="${(x(i) + 1).toFixed(1)}" y="${y(j.tickets).toFixed(1)}" width="${bw.toFixed(1)}" height="${(H - bas - y(j.tickets)).toFixed(1)}" rx="1"/>`; } });
  const pn = J.map((j, i) => (i ? 'L' : 'M') + (x(i) + cw / 2).toFixed(1) + ' ' + y(j.n1 || 0).toFixed(1)).join(' ');
  h += `<path class="n1l" d="${pn}" style="stroke-width:1.3"/>`;
  if (objJ) { h += `<line class="objl" x1="${gL}" x2="${W - gR}" y1="${y(objJ).toFixed(1)}" y2="${y(objJ).toFixed(1)}"/><text class="o" x="${gL}" y="${(y(objJ) - 3).toFixed(1)}">objectif ${nf(Math.round(objJ))} / jour</text>`; }
  // Le dernier jour réel et son N-1, en clair.
  let d = -1; J.forEach((j, i) => { if (j.tickets != null) { d = i; } });
  if (d >= 0) { const j = J[d]; h += `<text class="p" x="${(x(d) + cw / 2).toFixed(1)}" y="${(y(j.tickets) - 3).toFixed(1)}" text-anchor="${d > n * 0.8 ? 'end' : 'middle'}">${nf(j.tickets)}</text>`; }
  // Un repère N-1 au milieu du graphique.
  const m = Math.floor(n / 2);
  h += `<text class="n" x="${(x(m) + cw / 2).toFixed(1)}" y="${(y(J[m].n1 || 0) - 4).toFixed(1)}" text-anchor="middle">N-1</text>`;
  h += `<text x="${gL}" y="${H - 4}">${esc(labs[0])}</text><text x="${(W - gR).toFixed(1)}" y="${H - 4}" text-anchor="end">${esc(labs[1])}</text>`;
  return h + '</svg>';
}
const LEG = '<div class="pc-leg"><span><i class="r"></i>cette année</span><span><i class="n"></i>N-1, mêmes jours</span><span><i class="o"></i>objectif</span></div>';

/* --- Les briques ------------------------------------------------------------ */
const lien = c => c ? `<span class="pc-lien">📣 ${esc(c.nom.replace(/ - .*$/, ''))} · ${c === CS ? 'septembre' : 'oct. → nov.'}</span>` : '<span class="pc-lien mu">pas liée à une campagne · <u>lier</u></span>';
const phraseSept = `<div class="pc-phrase">Sur la campagne : <b class="ko">${nf(CS.reel)} clients</b>, ${nf(CS.clientsA1)} l’an dernier (${sg(vsN1)}) · objectif ${nf(CS.clientsPrevus)} : <b class="ko">il en a manqué ${nf(manque)}</b>, soit ${nf(Math.round(manque / CS.jours))} par jour.</div>`;
const phraseOct = `<div class="pc-phrase">Jour 1 sur ${CO.jours} : <b class="ko">${nf(CO.reel)} clients</b> contre ${nf(JO[0].n1)} le même jour l’an dernier. Sur la période, le N-1 fait ${nf(CO.clientsA1)} clients (${CO.clientsJour} par jour).</div>`;
const aFixer = `<div class="pc-afixer"><b>Objectif de clients à fixer</b>La campagne n’a pas de « + x % » dans le cockpit. Avec le budget d’octobre–novembre (${eur(CO.budget)}) et le panier de ${nf(CO.panier, 2)} €, il faudrait ${nf(Math.round(CO.budget / CO.panier))} clients, soit ${sg(100 * (CO.budget / CO.panier / CO.clientsA1 - 1))} % face au N-1.</div>`;
const manqueTuile = `<div class="pc-manque"><b>${nf(Math.round(manque / CS.jours))}</b>clients par jour qu’il aurait fallu en plus pour tenir l’objectif</div>`;

function carteAction(a, graphe, phrase, bouton) {
  return `<div class="card${a.pr === 'P0' ? ' alerte' : ''}">
    <div class="row"><span class="pill ${a.st[1]}">${a.st[0]}</span><span class="pill ${a.pr}">${a.pr}</span><span class="sp"></span><span class="xs mu">${esc(a.delai)}</span></div>
    <b style="display:block;margin-top:6px">${esc(a.titre)}</b>
    <div class="sm mu">${esc(a.meta)}</div>
    ${lien(a.camp)}${graphe || ''}${graphe ? LEG : ''}${phrase || ''}
    ${bouton || ''}</div>`;
}
const btnPhoto = '<div class="btns"><button class="btn p w">📷 Photo de la correction</button></div>';
const boutique = `<div class="cap">Ma boutique cette semaine</div><div class="card sm"><div class="row"><span>CA</span><span class="sp"></span><b>${eur(D.boutique.ca)}</b><span class="mu">/ ${eur(D.boutique.objectifSemaine)}</span></div><div class="bar"><i style="width:${Math.round(100 * D.boutique.ca / D.boutique.objectifSemaine)}%"></i></div><div class="row" style="margin-top:6px"><span>Google</span><span class="sp"></span><b>${nf(D.boutique.google, 1)}</b><span class="mu">${D.boutique.avis} avis</span></div></div>`;
const hd = (sous) => `<div class="hd"><div><h2>Mon plan d’action</h2><div class="d">${sous}</div></div><span class="sp"></span></div>`;

/* --- A : la jauge en tête, les semaines sur chaque action ----------------------- */
const bullet = (reel, n1, obj) => { const p = v => Math.max(0, Math.min(100, 100 * v / obj)); return `<div class="pc-bul"><div class="piste"></div><div class="fill" style="width:${p(reel).toFixed(1)}%"></div><span class="reel" style="left:${(p(reel) / 2).toFixed(1)}%">${pct(reel, obj)} % de l’objectif</span><div class="n1" style="left:${p(n1).toFixed(1)}%"><i>N-1</i></div><div class="obj"><i>objectif</i></div></div>`; };
const A_SEPT = `<div class="pc-camp"><div class="t"><b>${esc(CS.nom.replace(/ - .*$/, ''))}</b><span class="pill st-fer">clos</span><span class="pill">+${CS.pct} % de clients</span><span class="d">${fDL(CS.debut)} → ${fDL(CS.fin)} · ${esc(CS.type)} · objectif posé par le cockpit sur le N-1</span></div>
  <div class="pc-3"><div><div class="k">Clients</div><div class="v p">${nf(CS.reel)}</div><div class="s">${nf(Math.round(CS.reel / CS.jours))} par jour</div></div><div><div class="k">N-1</div><div class="v n">${nf(CS.clientsA1)}</div><div class="s">${sg(vsN1)} · ${nf(100 * vsN1 / CS.clientsA1, 1)} %</div></div><div><div class="k">Objectif</div><div class="v">${nf(CS.clientsPrevus)}</div><div class="s">${pct(CS.reel, CS.clientsPrevus)} % atteint</div></div></div>
  ${bullet(CS.reel, CS.clientsA1, CS.clientsPrevus)}
  <div class="pc-ca"><span>CA <b>${eur(CS.caReel)}</b> sur ${eur(CS.objectifCA)} visés (${pct(CS.caReel, CS.objectifCA)} %)</span><span>N-1 ${eur(CS.caN1)}</span></div></div>`;
const A_OCT = `<div class="pc-camp"><div class="t"><b>${esc(CO.nom.replace(/ - .*$/, ''))}</b><span class="pill st-ouv">jour 1 / ${CO.jours}</span><span class="d">${fDL(CO.debut)} → ${fDL(CO.fin)} · ${esc(CO.type)}</span></div>
  <div class="pc-3"><div><div class="k">Clients</div><div class="v p">${nf(CO.reel)}</div><div class="s">hier, jour 1</div></div><div><div class="k">N-1</div><div class="v n">${nf(CO.clientsA1)}</div><div class="s">${CO.clientsJour} par jour · ${nf(JO[0].n1)} le même jour</div></div><div><div class="k">Objectif</div><div class="v" style="color:#B26A00">?</div><div class="s">non fixé</div></div></div>${aFixer}</div>`;
const A = hd('Gosselies · 2 campagnes · 3 actions en cours') + '<div class="pc-cap">Objectifs de campagne</div>' + A_SEPT + A_OCT
  + '<div class="pc-cap">Mes actions <em>les clients, semaine par semaine</em></div>'
  + carteAction(ACTIONS[0], gBarres(SEM, true), phraseSept)
  + carteAction(ACTIONS[1], gBarres(SEMO, false), phraseOct, btnPhoto)
  + carteAction(ACTIONS[2], '', '', btnPhoto) + boutique;

/* --- B : la frise des campagnes, la trajectoire sur chaque action ------------- */
const B_CAMP = `<div class="pc-camp"><div class="pc-frise">
  <div class="c"><div class="m">sept.<b>30 j</b></div><div class="n"><b>${esc(CS.nom.replace(/ - .*$/, ''))}</b><div class="s">+${CS.pct} % de clients sur le N-1 · ${nf(CS.clientsA1)} → objectif ${nf(CS.clientsPrevus)} · CA visé ${eur(CS.objectifCA)}</div></div><div class="r"><b class="ko">${nf(CS.reel)}</b><small>${pct(CS.reel, CS.clientsPrevus)} % de l’objectif · ${sg(vsN1)} vs N-1</small></div></div>
  <div class="c"><div class="m">oct. → nov.<b>60 j</b></div><div class="n"><b>${esc(CO.nom.replace(/ - .*$/, ''))}</b><div class="s">pas d’objectif chiffré · N-1 ${nf(CO.clientsA1)} clients (${CO.clientsJour} / jour) · budget ${eur(CO.budget)}</div></div><div class="r"><b class="mu">jour 1</b><small>${nf(CO.reel)} clients · N-1 ${nf(JO[0].n1)}</small></div></div></div></div>`;
const B = hd('Gosselies · 2 campagnes · 3 actions en cours') + '<div class="pc-cap">Objectifs de campagne</div>' + B_CAMP
  + '<div class="pc-cap">Mes actions <em>la trajectoire depuis le début</em></div>'
  + carteAction(ACTIONS[0], gCumul(JS, CS.clientsPrevus, CS.jours, ['1er sept.', '30 sept.']), phraseSept + manqueTuile)
  + carteAction(ACTIONS[1], gCumul(JO, null, CO.jours, ['1er oct.', '29 nov.']), phraseOct + aFixer, btnPhoto)
  + carteAction(ACTIONS[2], '', '', btnPhoto) + boutique;

/* --- C : les tuiles, le jour par jour sur chaque action -------------------------- */
const C_SEPT = `<div class="pc-camp"><div class="t"><b>${esc(CS.nom.replace(/ - .*$/, ''))}</b><span class="pill st-fer">clos</span><span class="d">${fDL(CS.debut)} → ${fDL(CS.fin)} · +${CS.pct} % de clients sur le N-1</span></div>
  <div class="pc-tuiles"><div><div class="k">Clients</div><div class="v p">${nf(CS.reel)}</div><div class="s">${pct(CS.reel, CS.clientsPrevus)} % de l’objectif</div></div><div><div class="k">N-1</div><div class="v n">${nf(CS.clientsA1)}</div><div class="s">${sg(vsN1)}</div></div><div><div class="k">Objectif</div><div class="v">${nf(CS.clientsPrevus)}</div><div class="s">manque ${nf(manque)}</div></div></div>
  <div class="pc-ca"><span>CA <b>${eur(CS.caReel)}</b> / ${eur(CS.objectifCA)} (${pct(CS.caReel, CS.objectifCA)} %)</span><span>panier ${nf(CS.panier, 2)} €</span></div></div>`;
const C_OCT = `<div class="pc-camp"><div class="t"><b>${esc(CO.nom.replace(/ - .*$/, ''))}</b><span class="pill st-ouv">jour 1 / ${CO.jours}</span><span class="d">${fDL(CO.debut)} → ${fDL(CO.fin)}</span></div>
  <div class="pc-tuiles"><div><div class="k">Clients</div><div class="v p">${nf(CO.reel)}</div><div class="s">jour 1</div></div><div><div class="k">N-1</div><div class="v n">${nf(CO.clientsA1)}</div><div class="s">${CO.clientsJour} / jour</div></div><div><div class="k">Objectif</div><div class="v" style="color:#B26A00">à fixer</div><div class="s">${nf(Math.round(CO.budget / CO.panier))} si le budget fait foi</div></div></div></div>`;
const C = hd('Gosselies · 2 campagnes · 3 actions en cours') + '<div class="pc-cap">Objectifs de campagne</div>' + C_SEPT + C_OCT
  + '<div class="pc-cap">Mes actions <em>jour par jour</em></div>'
  + carteAction(ACTIONS[0], gJours(JS, objJour, ['1er sept.', '30 sept.']), phraseSept)
  + carteAction(ACTIONS[1], gJours(JO.slice(0, 31), null, ['1er oct.', '31 oct.']), phraseOct, btnPhoto)
  + carteAction(ACTIONS[2], '', '', btnPhoto) + boutique;

/* --- D : le choix — A, avec la trajectoire de B sous les semaines ------------------ */
const sousTitre = t => `<div class="pc-cap" style="margin:10px 0 0">${t}</div>`;
const D_ = hd('Gosselies · 2 campagnes · 3 actions en cours') + '<div class="pc-cap">Objectifs de campagne</div>' + A_SEPT + A_OCT
  + '<div class="pc-cap">Mes actions <em>les clients face au N-1 et à l’objectif</em></div>'
  + carteAction(ACTIONS[0], sousTitre('Semaine par semaine') + gBarres(SEM, true) + sousTitre('Depuis le début de la campagne') + gCumul(JS, CS.clientsPrevus, CS.jours, ['1er sept.', '30 sept.']), phraseSept + manqueTuile)
  + carteAction(ACTIONS[1], sousTitre('Semaine par semaine') + gBarres(SEMO, false) + sousTitre('Depuis le début de la campagne') + gCumul(JO, null, CO.jours, ['1er oct.', '29 nov.']), phraseOct, btnPhoto)
  + carteAction(ACTIONS[2], '', '', btnPhoto) + boutique;

/* --- Les pages ------------------------------------------------------------------ */
const HD = `<div class="mb-hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="t">${esc(D.magasin)}</div><div class="d">jeudi 1 octobre 2026</div></div><span class="sp"></span><button class="mb-ic">↻</button></div>`;
const TABS = `<div class="mb-tabs mb-tabs4">${[['jour', 'Le jour', '◉'], ['semaine', 'La semaine', '▤'], ['actions', 'Plan d’action', '✓'], ['reclamation', 'Réclamation', '📷']].map(o => `<button class="${o[0] === 'actions' ? 'on' : ''}"><i>${o[2]}</i>${o[1]}</button>`).join('')}</div>`;
const page = (titre, corps) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<style>${VI_CSS}</style>
<link rel="stylesheet" href="pc.css"></head>
<body><div id="dash" class="mob">${HD}<div class="mb-sc pc-sc"><div class="vi desk"><div class="sc">${corps}</div></div></div>${TABS}</div></body></html>`;

const ECRANS = [['a', 'A — jauge en tête, semaines côte à côte', A], ['b', 'B — frise des campagnes, trajectoire cumulée', B], ['c', 'C — tuiles, jour par jour', C], ['d', 'D — le choix : A, plus la trajectoire de B', D_]];
const PLANCHES = [
  { id: 'd', titre: 'D — Le choix : A, avec la trajectoire de B sur chaque action',
    acc: 'La jauge de A en tête de plan. Sur chaque action liée à une campagne, deux lectures l’une sous l’autre : les semaines côte à côte (cette année, N-1, objectif, l’écart écrit), puis la trajectoire cumulée depuis le premier jour (la courbe, celle de l’an dernier, la droite de l’objectif). La phrase et le « combien par jour » ferment la carte.',
    plus: ['Les deux questions ont leur réponse : « quelle semaine a décroché » et « où en est-on sur l’ensemble »', 'Même palette, mêmes trois séries sur les deux graphiques : une seule légende', 'Une campagne sans objectif (B2B, octobre) montre quand même ce qu’il y a à battre'],
    moins: ['Une carte d’action fait un écran entier : deux graphiques, c’est le maximum', 'À coder : le lien action ↔ campagne (campagne_id) et le « + x % » de la campagne d’octobre'] },
  { id: 'a', titre: 'A — La jauge en tête, les semaines côte à côte',
    acc: 'En haut du plan d’action, une carte par campagne : clients faits, N-1, objectif, et une jauge qui va de zéro à l’objectif avec le repère N-1. Sur chaque action liée à une campagne, les clients semaine par semaine : cette année en couleur, l’an dernier en gris, l’objectif en trait, l’écart écrit sous chaque semaine.',
    plus: ['Un coup d’œil suffit : trois chiffres et une jauge, la même lecture pour toutes les campagnes', 'Les semaines sont le rythme du magasin : on voit laquelle a décroché (14–20 et 21–27 sept.)', 'L’écart au N-1 est écrit sous chaque semaine, pas à deviner', 'Une campagne sans objectif chiffré le dit, et propose un chiffre à partir du budget'],
    moins: ['Cinq semaines × deux barres : lisible au téléphone, pas plus de six semaines', 'Le cumul (« où en est-on sur l’ensemble ») n’est lu que sur la jauge, pas sur le graphique', 'Deux cartes de campagne avant les actions : le plan commence plus bas'] },
  { id: 'b', titre: 'B — La frise des campagnes, la trajectoire cumulée',
    acc: 'Les campagnes en frise compacte (mois, objectif, résultat). Sur chaque action, les clients cumulés depuis le premier jour : la courbe de cette année face à celle de l’an dernier, et la droite de l’objectif. L’écart se voit grandir ou se refermer, et « combien par jour il aurait fallu » est écrit.',
    plus: ['La trajectoire dit tout de suite si on tient ou si on perd, et depuis quand', 'Le même graphique sert en cours de campagne (courbe qui avance) et après (bilan)', 'La frise prend peu de place : les actions arrivent vite', 'Jour 1 d’octobre : la courbe N-1 montre ce qu’il y a à battre sur 60 jours'],
    moins: ['Moins intuitif qu’une barre pour une partie des franchisés (cumul)', 'Les mauvaises semaines se voient moins (une pente, pas un creux)', 'Trois courbes proches en fin de mois : les étiquettes se serrent'] },
  { id: 'c', titre: 'C — Les tuiles, le jour par jour',
    acc: 'Trois tuiles par campagne (clients, N-1, objectif). Sur chaque action, les 30 jours : une barre par jour cette année, la ligne grise du N-1 aligné au même jour de semaine, l’objectif du jour en trait. C’est l’écran le plus proche de ce que vit le magasin.',
    plus: ['Le jour par jour parle au magasin : on y retrouve le marché, le dimanche, le lundi creux', 'Le N-1 est aligné au même jour de semaine (−364 jours) : la comparaison est juste', 'L’objectif par jour (144) est un chiffre qu’une équipe peut viser', 'Les tuiles sont les plus légères des trois en-têtes'],
    moins: ['30 barres fines au téléphone : on lit la forme, pas chaque jour', 'Sur 60 jours (octobre–novembre), il faut couper par mois', 'Le total de la campagne n’est que dans les tuiles, pas sur le graphique'] },
];

(async () => {
  for (const [id, titre, corps] of ECRANS) { fs.writeFileSync(path.join(OUT, id + '.html'), page(titre, corps)); }
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [id] of ECRANS) {
    const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/plan-campagne/' + id + '.html', { waitUntil: 'load' });
    await p.waitForTimeout(400);
    // Un seul écran, aussi haut que le contenu : les onglets restent en bas.
    const h = await p.evaluate(() => document.querySelector('.mb-sc').scrollHeight + document.querySelector('.mb-hd').offsetHeight + document.querySelector('.mb-tabs').offsetHeight + 2);
    await p.setViewportSize({ width: 390, height: Math.max(844, h) });
    await p.waitForTimeout(200);
    await p.screenshot({ path: path.join(OUT, id + '.jpg'), type: 'jpeg', quality: 88 });
    const carte = (await p.$$('.vi .card'))[0];
    await carte.screenshot({ path: path.join(OUT, id + '-action.png') });
    const camp = await p.$('.pc-camp');
    await camp.screenshot({ path: path.join(OUT, id + '-campagne.png') });
    console.log('✓', id, 'hauteur', h);
    await p.close();
  }
  for (const P of PLANCHES) {
    const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>${esc(P.titre)}</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/dashboard/dashboard.css"><link rel="stylesheet" href="pc.css"></head><body class="pl">
      <span class="u">Plan d’action · objectifs de campagne · Gosselies · données réelles du 01/10/2026, actions en illustration</span><h1>${esc(P.titre)}</h1><div class="acc">${esc(P.acc)}</div>
      <div class="g"><div><img src="${P.id}.jpg" style="width:390px"><div class="leg">Le plan d’action au téléphone (390 px)</div></div>
      <div class="zoom"><img src="${P.id}-campagne.png"><div class="leg">La campagne de septembre</div><img src="${P.id}-action.png" style="margin-top:16px"><div class="leg">L’action liée à la campagne</div></div>
      <div class="col"><h4>Pour</h4><ul class="pl-p">${P.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Contre</h4><ul class="pl-m">${P.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div></div></body></html>`;
    fs.writeFileSync(path.join(OUT, 'planche-' + P.id + '.html'), html);
    const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/plan-campagne/planche-' + P.id + '.html', { waitUntil: 'load' });
    await p.waitForTimeout(500);
    await p.screenshot({ path: path.join(OUT, 'planche-' + P.id + '.jpg'), type: 'jpeg', quality: 86, fullPage: true });
    await p.close(); console.log('✓ planche', P.id);
  }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
