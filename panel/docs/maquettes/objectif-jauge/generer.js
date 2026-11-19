/* Maquettes « l'objectif en jauge pleine largeur, les vignettes face à la semaine passée » du
 * dashboard magasin, onglet Opérationnel. Chiffres réels de Halle lus en lecture seule le mercredi
 * 7 octobre 2026 à 15:12 (reel-halle-0710.json : /exploitation/jour et /ventes/stats du jour et de
 * mercredi dernier, heure par heure). Aucun nom de client ni de membre de l'équipe.
 *   node docs/maquettes/objectif-jauge/generer.js   → a.html, b.html */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = JSON.parse(fs.readFileSync(path.join(D, 'reel-halle-0710.json'), 'utf8'));
const M = R.m, SJ = R.statsJour, S7 = R.statsJ7;

const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fE = n => nf(Math.round(n)) + ' €';
const fE2 = n => nf(n, 2) + ' €';
const fP = (n, d = 0) => nf(n, d) + ' %';
const fS = (n, d = 1) => (n >= 0 ? '+ ' : '− ') + fP(Math.abs(n), d);
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const dmy = s => s.slice(8, 10) + '/' + s.slice(5, 7);
const min = h => { const [a, b] = String(h).split(':').map(Number); return a * 60 + (b || 0); };
const MAINT = min(R.maintenant), H = Math.floor(MAINT / 60), FRAC = (MAINT % 60) / 60;

/* Mercredi dernier à la même heure : les heures pleines avant, et la part de l'heure en cours. */
function cumulA(heures, h, frac) {
  const t = { ca: 0, tickets: 0, mb: 0, mat: 0 };
  heures.forEach(x => {
    const k = x.h < h ? 1 : (x.h === h ? frac : 0);
    t.ca += (x.ca || 0) * k; t.tickets += (x.tickets || 0) * k; t.mb += (x.mb || 0) * k; t.mat += (x.mat || 0) * k;
  });
  return t;
}
/* La courbe cumulée, heure par heure, pour le graphique du duel. */
function cumul(heures, jusqua) {
  let c = 0; const out = [];
  heures.forEach(x => { if (jusqua != null && x.h > jusqua) { return; } c += x.ca || 0; out.push({ h: x.h, ca: c }); });
  return out;
}
const J7 = cumulA(S7.heures, H, FRAC);             // mercredi dernier à 15:12
const J7J = S7.totaux;                                // mercredi dernier, journée
const OBJ = M.objectifJour, CA = M.ca, TK = M.tickets;
const pct = v => 100 * v / OBJ;
const dCa = 100 * (CA - J7.ca) / J7.ca;
const dTk = 100 * (TK - J7.tickets) / J7.tickets;
const panJ7 = J7.ca / J7.tickets, dPan = 100 * (M.panier - panJ7) / panJ7;
const mbPctJ7 = 100 * J7.mb / J7.ca, dMb = M.margeBrutePct - mbPctJ7;
const dProj = 100 * (M.projection - J7J.ca) / J7J.ca;
const reste = OBJ - CA;
const serie = M.serie;

/* Un petit graphique : les 7 derniers jours, le jour en cours en rubis. */
function spark(vals, fmt) {
  const pts = vals.filter(v => v != null); if (pts.length < 2) { return ''; }
  const mn = Math.min(...pts), mx = Math.max(...pts), W = 120, Hh = 30;
  const xy = vals.map((v, i) => [i * W / (vals.length - 1), v == null ? null : 26 - 22 * (v - mn) / ((mx - mn) || 1)]);
  const d = xy.filter(p => p[1] != null).map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
  const last = xy[xy.length - 1];
  return `<svg class="sp" viewBox="0 0 ${W} ${Hh}" preserveAspectRatio="none"><path d="${d}"/><circle cx="${last[0].toFixed(1)}" cy="${(last[1] || 0).toFixed(1)}" r="2.6"/></svg><span class="sp-l">7 derniers jours · ${fmt(pts[0])} → ${fmt(pts[pts.length - 1])}</span>`;
}

/* L'en-tête commun : celui du dashboard. */
const entete = mq => `<div class="hd"><div><div class="t">${esc(R.magasin.replace('Atelier by - ', ''))} · dashboard magasin</div><div class="s">${R.jourNom} ${dmy(R.date)} · ${R.maintenant} · <span class="direct"><i></i>en direct</span></div></div>
  <div class="dr"><div class="ong"><span class="on">Opérationnel</span><span>Jour</span><span>Semaine</span><span>Mois</span></div><span class="dt">${dmy(R.date)}</span></div></div><div class="mq">Maquette ${mq}</div>`;

/* ───────────── A : une jauge pleine largeur, puis six vignettes face à J−7 à la même heure ───────────── */
const jaugeA = `<div class="jg">
  <div class="jg-h"><span class="lab">Objectif du ${R.jourNom}</span><b>${fE(OBJ)}</b><span class="chip">budget du mois · profil des ${R.jourNom}s</span><span class="dr">${R.maintenant} · ${fP(M.projectionPart)} de la journée écoulée</span></div>
  <div class="jg-big">
    <div class="n">${fE(CA)}</div>
    <div class="p">${fP(pct(CA))}<small>réalisé</small></div>
    <div class="jv ${dCa >= 0 ? 'ok' : (dCa > -10 ? 'att' : 'ko')}"><b>${fS(dCa)}</b> face à ${R.jourNom} dernier à la même heure<small>${fE(J7.ca)} à ${R.maintenant} le ${dmy(S7.date)}</small></div>
    <div class="reste"><b>il reste ${fE(reste)}</b><br>au rythme de la journée : ${fE(M.projection)} (${fP(pct(M.projection))})</div>
  </div>
  <div class="jg-bar">
    <div class="seg fait" style="width:${pct(CA).toFixed(1)}%"></div>
    <div class="seg proj" style="left:${pct(CA).toFixed(1)}%;width:${(pct(M.projection) - pct(CA)).toFixed(1)}%"></div>
    <i class="rep haut j7j" style="left:${pct(J7J.ca).toFixed(1)}%"><span>${R.jourNom} dernier, journée · ${fE(J7J.ca)}</span></i>
    <i class="rep bas j7h" style="left:${pct(J7.ca).toFixed(1)}%"><span>J−7 à ${R.maintenant} · ${fE(J7.ca)}</span></i>
    <i class="rep bas temps" style="left:${M.projectionPart.toFixed(1)}%"><span>${fP(M.projectionPart)} du temps</span></i>
  </div>
  <div class="jg-ax"><span>0 €</span><span>${fE(OBJ / 2)}</span><span>objectif ${fE(OBJ)}</span></div>
  <div class="jg-reps"><span><i class="j7"></i>J−7 à ${R.maintenant} : ${fE(J7.ca)} (${fP(pct(J7.ca))})</span><span><i class="j7"></i>${R.jourNom} dernier, journée : ${fE(J7J.ca)} (${fP(pct(J7J.ca))})</span><span><i class="tp"></i>${fP(M.projectionPart)} de la journée écoulée</span></div>
  <div class="jg-leg"><span><i class="fait"></i>encaissé</span><span><i class="proj"></i>projection au rythme de la journée</span><span><i class="j7"></i>mercredi dernier (même heure, journée)</span><span><i class="tp"></i>part de la journée écoulée</span></div>
</div>`;

const tuile = (k, v, delta, sous, sp, cls) => `<div class="tl ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div>${delta || ''}<div class="s">${sous}</div>${sp || ''}</div>`;
const fPts = d => (d >= 0 ? '+ ' : '− ') + nf(Math.abs(d), 1) + ' pts';
const dl = (d, lib, inv, pts) => d == null ? '' : `<div class="dl ${Math.abs(d) < 1 ? 'eq' : ((d > 0) !== !!inv ? 'ok' : 'ko')}"><b>${pts ? fPts(d) : fS(d)}</b><small>${lib}</small></div>`;
const tuilesA = `<div class="tl6">
${tuile('CA du jour', fE(CA), dl(dCa, `vs ${R.jourNom} dernier à ${R.maintenant}`), `${R.jourNom} dernier à ${R.maintenant} : ${fE(J7.ca)} · journée : ${fE(J7J.ca)} · référence ${fE(M.refCa)}`, spark(serie.map(x => x.ca), fE))}
${tuile('Clients', nf(TK), dl(dTk, `vs ${nf(J7.tickets)} à J−7 même heure`), `${R.jourNom} dernier : ${nf(Math.round(J7.tickets))} à ${R.maintenant}, ${nf(J7J.tickets)} sur la journée · référence ${nf(M.refTickets)}`, spark(serie.map(x => x.tickets), nf))}
${tuile('Panier moyen', fE2(M.panier), dl(dPan, `vs ${fE2(panJ7)} à J−7 même heure`), `réseau ${fE2(R.reseau.panier)} · ${nf(M.produitsParClient, 2)} produits par client`, '')}
${tuile('Marge brute', fE(M.margeBrute) + `<small>${fP(M.margeBrutePct, 1)}</small>`, dl(dMb, `vs J−7 même heure (${fP(mbPctJ7, 1)})`, false, true), `matière ${fE(M.coutMatiere)} (${fP(M.coutMatierePct, 1)}) · seuil ${fP(R.seuils.food)} · ${R.jourNom} dernier, journée : ${fP(J7J.mbPct, 1)}`, '')}
${tuile('Projection fin de journée', fE(M.projection), dl(dProj, `vs la journée de ${R.jourNom} dernier`), `${fP(pct(M.projection))} de l'objectif · ${fP(M.projectionPart)} de la journée écoulée · au rythme : ${fE(M.projectionRythme)}`, '')}
${tuile('Résultat net du jour', (M.net >= 0 ? '+ ' : '− ') + fE(Math.abs(M.net)), `<div class="dl ${M.net >= 0 ? 'ok' : 'ko'}"><b>${fP(M.netPct, 1)}</b><small>des ventes</small></div>`, `après personnel ${fE(M.labour)} et frais généraux ${fE(M.overhead)} · hier : ${(serie[serie.length - 2].net >= 0 ? '+ ' : '− ') + fE(Math.abs(serie[serie.length - 2].net))}`, '')}
</div>`;

/* ───────────── B : le duel avec mercredi dernier ───────────── */
const duelJauge = `<div class="jg duel">
  <div class="jg-h"><span class="lab">Objectif du ${R.jourNom} · ${fE(OBJ)}</span><span class="chip">budget du mois · profil des ${R.jourNom}s</span><span class="dr">${R.maintenant} · ${fP(M.projectionPart)} de la journée écoulée</span></div>
  <div class="piste"><div class="pl"><b>Aujourd'hui</b><small>à ${R.maintenant}</small></div>
    <div class="pb"><div class="seg fait" style="width:${pct(CA).toFixed(1)}%"></div><div class="seg proj" style="left:${pct(CA).toFixed(1)}%;width:${(pct(M.projection) - pct(CA)).toFixed(1)}%"></div><i class="temps" style="left:${M.projectionPart.toFixed(1)}%"></i><span class="val" style="left:${pct(CA).toFixed(1)}%">${fE(CA)} · ${fP(pct(CA))}</span></div></div>
  <div class="piste"><div class="pl"><b>${R.jourNom[0].toUpperCase() + R.jourNom.slice(1)} dernier</b><small>${dmy(S7.date)}</small></div>
    <div class="pb"><div class="seg j7" style="width:${pct(J7.ca).toFixed(1)}%"></div><div class="seg j7j" style="left:${pct(J7.ca).toFixed(1)}%;width:${(pct(J7J.ca) - pct(J7.ca)).toFixed(1)}%"></div><i class="temps" style="left:${M.projectionPart.toFixed(1)}%"></i><span class="val" style="left:${pct(J7.ca).toFixed(1)}%">${fE(J7.ca)} à ${R.maintenant}</span><span class="val fin" style="left:${pct(J7J.ca).toFixed(1)}%">journée ${fE(J7J.ca)} · ${fP(pct(J7J.ca))}</span></div></div>
  <div class="jg-ax"><span>0 €</span><span>${fE(OBJ / 2)}</span><span>objectif ${fE(OBJ)}</span></div>
  <div class="jv-l ${dCa >= 0 ? 'ok' : (dCa > -10 ? 'att' : 'ko')}"><b>${fS(dCa)}</b> face à ${R.jourNom} dernier à la même heure · <b>${fS(dTk)}</b> clients · panier <b>${fS(dPan)}</b> · au rythme de la journée, ${fE(M.projection)} contre ${fE(J7J.ca)} la semaine passée (<b>${fS(dProj)}</b>)</div>
</div>`;

/* Le graphique heure par heure : le cumul d'aujourd'hui face à celui de mercredi dernier. */
function courbes(W = 1100, Hh = 220, pas = 1, cls = '') {
  const X0 = 44, X1 = W - 16, Y0 = 12, Y1 = Hh - 26;
  const h0 = 6, h1 = 19, mx = OBJ;
  const x = h => X0 + (X1 - X0) * (h - h0) / (h1 - h0), y = v => Y1 - (Y1 - Y0) * v / mx;
  const cJ7 = cumul(S7.heures, null), cA = cumul(SJ.heures, H);
  const pathOf = (c, prefix) => c.map((p, i) => (i ? 'L' : 'M') + x(p.h + 1).toFixed(1) + ' ' + y(p.ca).toFixed(1)).join(' ');
  const dA = 'M' + x(h0).toFixed(1) + ' ' + y(0).toFixed(1) + ' ' + pathOf(cA).replace(/^M/, 'L');
  const d7 = 'M' + x(h0).toFixed(1) + ' ' + y(0).toFixed(1) + ' ' + pathOf(cJ7).replace(/^M/, 'L');
  const lastA = cA[cA.length - 1], last7 = cJ7[cJ7.length - 1];
  let g = `<svg class="crb ${cls}" viewBox="0 0 ${W} ${Hh}">`;
  [0.25, 0.5, 0.75, 1].forEach(f => { g += `<line x1="${X0}" x2="${X1}" y1="${y(mx * f).toFixed(1)}" y2="${y(mx * f).toFixed(1)}" class="gr"/><text x="${X0 - 6}" y="${(y(mx * f) + 3).toFixed(1)}" text-anchor="end" class="ax">${nf(Math.round(mx * f / 10) * 10)}</text>`; });
  g += `<text x="${X1}" y="${(y(mx) - 4).toFixed(1)}" text-anchor="end" class="obj">objectif ${fE(OBJ)}</text>`;
  for (let h = h0; h <= h1; h += pas) { g += `<text x="${x(h).toFixed(1)}" y="${Hh - 8}" text-anchor="middle" class="ax">${h} h</text>`; }
  g += `<path d="${d7}" class="l7"/><path d="${dA}" class="la"/>`;
  g += `<line x1="${x(H + FRAC).toFixed(1)}" x2="${x(H + FRAC).toFixed(1)}" y1="${Y0}" y2="${Y1}" class="now"/><text x="${(x(H + FRAC) + 5).toFixed(1)}" y="${Y0 + 12}" class="nowt">${R.maintenant}</text>`;
  g += `<circle cx="${x(lastA.h + 1).toFixed(1)}" cy="${y(lastA.ca).toFixed(1)}" r="4" class="pa"/><text x="${(x(lastA.h + 1) - 8).toFixed(1)}" y="${(y(lastA.ca) + 18).toFixed(1)}" text-anchor="end" class="ta">aujourd'hui ${fE(lastA.ca)}</text>`;
  g += `<circle cx="${x(last7.h + 1).toFixed(1)}" cy="${y(last7.ca).toFixed(1)}" r="4" class="p7"/><text x="${(x(last7.h + 1) - 8).toFixed(1)}" y="${(y(last7.ca) - 10).toFixed(1)}" text-anchor="end" class="t7">${R.jourNom} dernier, journée ${fE(last7.ca)}</text>`;
  return g + '</svg>';
}
const courbeB = `<div class="bloc"><div class="t"><h2>Heure par heure</h2><small>le cumul d'aujourd'hui face à celui de ${R.jourNom} dernier, jusqu'à l'objectif</small><span class="dr">ventes encaissées, pro compris</span></div><div class="crb-w">${courbes()}${courbes(380, 230, 2, 'tel')}</div>
  <div class="leg2"><span><i class="la"></i>aujourd'hui</span><span><i class="l7"></i>${R.jourNom} dernier (${dmy(S7.date)})</span><span><i class="now"></i>maintenant</span></div></div>`;

const ligne = (k, a, b, d, j, r, note) => `<div class="dr-l"><div class="k">${k}${note ? `<small>${note}</small>` : ''}</div><div class="a"><span class="lab">aujourd'hui · ${R.maintenant}</span>${a}</div><div class="b"><span class="lab">${R.jourNom} dernier · ${R.maintenant}</span>${b}</div><div class="d">${d}</div><div class="j"><span class="lab">${R.jourNom} dernier · journée</span>${j}</div><div class="r"><span class="lab">référence</span>${r}</div></div>`;
const ecart = (d, inv, suf) => d == null ? '<span class="eq">—</span>' : `<span class="${Math.abs(d) < 1 ? 'eq' : ((d > 0) !== !!inv ? 'ok' : 'ko')}">${fS(d)}${suf || ''}</span>`;
const duelTable = `<div class="bloc"><div class="t"><h2>Le duel, chiffre par chiffre</h2><small>à la même heure, puis la journée entière de la semaine passée</small></div>
  <div class="dr-t">
  ${ligne('Chiffre d’affaires', fE(CA), fE(J7.ca), ecart(dCa), fE(J7J.ca), fE(M.refCa) + '<small>moyenne des 6 derniers ' + R.jourNom + 's</small>')}
  ${ligne('Clients', nf(TK), nf(Math.round(J7.tickets)), ecart(dTk), nf(J7J.tickets), nf(M.refTickets) + '<small>' + fS(M.ticketsDelta) + ' face à la référence</small>')}
  ${ligne('Panier moyen', fE2(M.panier), fE2(panJ7), ecart(dPan), fE2(J7J.panier), fE2(R.reseau.panier) + '<small>réseau aujourd’hui</small>')}
  ${ligne('Marge brute', fE(M.margeBrute) + '<small>' + fP(M.margeBrutePct, 1) + ' des ventes</small>', fE(J7.mb) + '<small>' + fP(mbPctJ7, 1) + '</small>', `<span class="${Math.abs(dMb) < 1 ? 'eq' : (dMb > 0 ? 'ok' : 'ko')}">${fPts(dMb)}</span>`, fE(J7J.mb) + '<small>' + fP(J7J.mbPct, 1) + '</small>', 'matière ' + fP(M.coutMatiereePct || M.coutMatierePct, 1) + '<small>seuil ' + fP(R.seuils.food) + '</small>')}
  ${ligne('Projection fin de journée', fE(M.projection) + '<small>' + fP(pct(M.projection)) + ' de l’objectif</small>', '<span class="eq">—</span>', ecart(dProj, false, '<small> vs la journée</small>'), fE(J7J.ca) + '<small>' + fP(pct(J7J.ca)) + ' de l’objectif</small>', fE(OBJ) + '<small>objectif du jour</small>')}
  ${ligne('Résultat net', (M.net >= 0 ? '+ ' : '− ') + fE(Math.abs(M.net)) + '<small>' + fP(M.netPct, 1) + ' des ventes</small>', '<span class="eq">—</span>', '<span class="eq">—</span>', fE(J7J.res) + '<small>avant frais généraux</small>', 'personnel ' + fE(M.labour) + '<small>frais généraux ' + fE(M.overhead) + '</small>', 'personnel et frais généraux du jour')}
  </div></div>`;

const note = `<div class="note">Les six vignettes d'aujourd'hui (CA du jour, marge brute, clients, panier moyen, projection, résultat net) comparent le CA à la journée entière de mercredi dernier, ce qui donne un « − 17,5 % » trompeur à 15 h. Ici, chaque chiffre se compare d'abord à <b>mercredi dernier à la même heure</b>, puis à sa journée entière. Chiffres réels de Halle, mercredi 7 octobre 2026 à ${R.maintenant}.</div>`;

const page = (mq, corps) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Maquette ${mq} · l'objectif en jauge</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="../vue-generale/vg.css"><link rel="stylesheet" href="jg.css"></head>
<body><div class="page">${entete(mq)}${corps}${note}</div></body></html>`;

fs.writeFileSync(path.join(D, 'a.html'), page('A', jaugeA + tuilesA));
fs.writeFileSync(path.join(D, 'b.html'), page('B', duelJauge + courbeB + duelTable));
console.log('a.html, b.html écrits ·', 'J−7 à', R.maintenant, ':', fE(J7.ca), Math.round(J7.tickets), 'clients, marge', fP(mbPctJ7, 1), '· écarts CA', fS(dCa), 'clients', fS(dTk), 'panier', fS(dPan), 'projection', fS(dProj));
