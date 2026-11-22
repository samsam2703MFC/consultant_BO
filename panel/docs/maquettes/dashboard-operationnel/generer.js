/* Maquette « dashboard opérationnel » du magasin : la journée en cours, ce qu'il faut faire
 * maintenant. Chiffres réels de Halle lus en lecture seule le mardi 6 octobre 2026 à 08:14
 * (reel-halle-0610.json). Aucun nom de client ni de membre de l'équipe.
 *   node docs/maquettes/dashboard-operationnel/generer.js   → a.html */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = JSON.parse(fs.readFileSync(path.join(D, 'reel-halle-0610.json'), 'utf8'));
const V = R.ventes, P = R.production, C = R.commandes;

const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fE = n => nf(Math.round(n)) + ' €';
const fE2 = n => nf(n, 2) + ' €';
const fP = (n, d = 0) => nf(n, d) + ' %';
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const somme = (a, f) => a.reduce((t, x) => t + f(x), 0);
const min = h => { const [a, b] = String(h).split(':').map(Number); return a * 60 + (b || 0); };
const MAINT = min(R.maintenant);
const dmy = s => s.slice(8, 10) + '/' + s.slice(5, 7);

/* Ce que disent les données */
const ruptures = P.produits.filter(p => p.verdict === 'rupture');
const manques = P.produits.filter(p => p.verdict === 'manque');
const trop = P.produits.filter(p => p.verdict === 'trop');
const okN = P.produits.filter(p => p.verdict === 'ok').length;
/* Vide quand : la première heure où la vitrine passe à zéro ou dessous, et l'heure où une cuisson la remplit. */
const vide = p => {
  const c = p.cases || [], i = c.findIndex((z, k) => z.q < 0 || (z.q <= 0 && c.slice(k).some(y => y.q < 0)));
  if (i < 0) { return null; }
  const j = c.findIndex((z, k) => k > i && z.q > 0);
  return { de: c[i].h, a: j < 0 ? null : c[j].h };
};
const pasFaites = ruptures.filter(p => p.sorti === 0);
const attente = ruptures.filter(p => p.sorti > 0);
const nonRendues = R.taches.filter(t => t.statut === 'nonRendue');
const rendues = R.taches.filter(t => t.faitLe);
const heuresRendues = rendues.map(t => t.faitLe).sort();
const enRetard = C.lignes.filter(l => l.quand < R.date + ' ' + R.maintenant);
const aVenir = C.lignes.filter(l => l.quand >= R.date + ' ' + R.maintenant);
const c1 = P.cuissons[0], c2 = P.cuissons[1];
const attJ7 = 100 * (V.ca - V.j7.ca) / V.j7.ca;
const partObj = 100 * V.ca / V.objectif;
const enPoste = R.equipe.postes.filter(p => min(p.debut) <= MAINT && MAINT < min(p.fin));
const releve = R.equipe.postes.filter(p => min(p.debut) > MAINT).sort((a, b) => min(a.debut) - min(b.debut))[0];
const conseilR = somme(ruptures, p => p.conseil || 0);
const nomTache = t => String(t || '').replace(/^Photo du comptoir\s*-\s*/i, '').replace(/^Vérifier si le magasin est propre\.?$/i, 'Propreté du magasin')
  .replace(/^étiquetage des prix et allergènes$/i, 'Étiquetage prix et allergènes').replace(/^Est-ce que les promotions ont été encadrées et visibles ?\s*\??$/i, 'Promotions encadrées et visibles');
const posteNom = p => !p.postes.length ? 'après-midi' : p.postes.length > 1 ? 'production et vente' : p.postes[0].toLowerCase();
const dans = m => { const d = m - MAINT; return d < 60 ? `dans ${d} min` : `dans ${Math.floor(d / 60)} h ${String(d % 60).padStart(2, '0')}`; };

/* 1. Maintenant : six tuiles */
const tuile = (cls, k, v, s) => `<div class="tl ${cls}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
const maintenant = `<div class="mnt">
${tuile('heure', `${R.jourNom} ${dmy(R.date)}`, R.maintenant, 'ouvert depuis 06:00 · relu toutes les 2 min')}
${tuile(attJ7 < -10 ? 'att' : 'ok', 'Ventes', fE(V.ca) + `<small>${V.tickets} clients</small>`, `J−7 à la même heure : ${fE(V.j7.ca)}, ${V.j7.tickets} clients · ${attJ7 >= 0 ? '+' : '−'} ${fP(Math.abs(attJ7))}`)}
${tuile('', 'Objectif du jour', fP(partObj) + `<small>de ${fE(V.objectif)}</small>`, `il reste ${fE(V.objectif - V.ca)} · mardi dernier, à cette heure : ${fP(100 * V.j7.ca / V.j7.caJour)} de sa journée`)}
${tuile(ruptures.length ? 'ko' : 'ok', 'Vitrine', nf(P.totaux.stock) + '<small>pièces</small>', `${ruptures.length} références vides · ${manques.length} vont manquer · ${trop.length} finiront en trop`)}
${tuile('', 'Équipe en poste', enPoste.length + `<small>personne${enPoste.length > 1 ? 's' : ''}</small>`, `${enPoste.map(p => 'jusqu’à ' + p.fin).join(' et ')} · relève à ${releve ? releve.debut : '—'} · ${nf(R.equipe.heures, 1)} h au planning`)}
${tuile('', 'Prochaine cuisson', `${c2.nom} <small>four à ${c2.four}</small>`, `${c2.pieces} pièces à sortir de ${c2.de} à ${c2.a} · ${dans(min(c2.four))}`)}
</div>`;

/* 2. À faire maintenant */
const actions = [
  ['ko', `Remplir ${ruptures.length} références vides`, `${attente.length} cookies vides jusqu’à la cuisson de ${c2.de} : ${attente.map(p => esc(p.nom.replace(/^Cookie /, ''))).join(', ')} · ${pasFaites.length} pas produites aujourd’hui : ${pasFaites.map(p => esc(p.nom)).join(', ')}`, 'maintenant', `${conseilR} pièces conseillées`],
  ['ko', `Rendre ${nonRendues.length} contrôles`, `${nonRendues.map(t => esc(nomTache(t.tache))).join(', ')} · ${rendues.length} rendus entre ${heuresRendues[0]} et ${heuresRendues[heuresRendues.length - 1]}`, 'maintenant', `${rendues.length} / ${R.taches.length} rendus`],
  ['att', `Clôturer ${enRetard.length} commandes en retard`, `l’heure de retrait est passée et la commande reste ouverte dans la caisse · du ${dmy(enRetard[0].quand)} au ${dmy(enRetard[enRetard.length - 1].quand)} · ${fE2(somme(enRetard, l => l.montant))}`, 'ce matin', 'dans la caisse'],
  ['att', `Valider la cuisson du matin`, `${c1.pieces} pièces prévues, four à ${c1.four} · pas encore validée dans le plan de production`, 'avant 09:00', 'plan de production'],
  ['att', `Compléter ${manques.length} références qui vont manquer`, manques.map(p => `${esc(p.nom)} vers ${p.manque.h} h, ${p.conseil} pièce${p.conseil > 1 ? 's' : ''}`).join(' · '), 'à la cuisson de midi', `${somme(manques, p => p.conseil)} pièces`],
  ['ctl', `Lancer la cuisson de midi`, `${c2.pieces} pièces pour ${c2.de} à ${c2.a} · réduire ${trop.length} références qui finiront en trop : ${trop.slice(0, 4).map(p => esc(p.nom)).join(', ')}…`, `four à ${c2.four}`, dans(min(c2.four))],
  ['mu', 'À la fermeture : encoder la poubelle', 'rien n’est déclaré aujourd’hui · le coût de ce qui est jeté se retire du résultat', 'à 19:00', 'au panel'],
  ['mu', `Demain : ${C.demain.n} commande à préparer`, `${fE2(C.demain.ca)} · retrait à 14:00 · ${aVenir.length} commandes à venir d’ici le ${dmy(aVenir[aVenir.length - 1].quand)}`, 'mercredi', 'à prévoir au plan'],
];
const aFaire = `<div class="bloc"><div class="t"><h2>À faire maintenant</h2><small>du plus urgent au moins urgent · chaque ligne ouvre l’écran où on le fait</small><span class="dr">${actions.filter(a => a[0] === 'ko').length} urgentes · ${actions.filter(a => a[0] === 'att').length} ce matin</span></div>
<ol class="af">${actions.map(([c, q, d, qd, s], i) => `<li class="${c}"><i></i><span class="no">${i + 1}</span><div><div class="q">${q}</div><div class="d">${d}</div></div><div class="qd">${qd}<small>${s}</small></div><span class="fl">›</span></li>`).join('')}</ol></div>`;

/* Les heures du matin : la moyenne des 6 derniers mardis et ce qui est vendu, en barres côte à côte. */
function heuresSvg() {
  const H = Object.keys(R.moyHeure).map(Number), W = 520, Ht = 120, bw = W / H.length;
  const mx = Math.max(...Object.values(R.moyHeure), ...V.heures.map(h => h.ca)) * 1.15;
  const y = v => Ht - Ht * v / mx;
  return `<svg width="100%" viewBox="0 0 ${W} ${Ht + 18}">${H.map((h, i) => {
    const m = R.moyHeure[h], z = V.heures.find(q => q.h === h), v = z ? z.ca : null, X = i * bw;
    const passe = h * 60 + 60 <= MAINT, enCours = h * 60 <= MAINT && MAINT < h * 60 + 60;
    const coul = enCours ? '#8D1D2C' : (v != null && v >= m ? '#2D7A3E' : '#D97706');
    return `<rect x="${(X + bw * .12).toFixed(1)}" y="${y(m).toFixed(1)}" width="${(bw * .36).toFixed(1)}" height="${(Ht - y(m)).toFixed(1)}" rx="2" fill="#e3dbcf"/><text x="${(X + bw * .3).toFixed(1)}" y="${(y(m) - 3).toFixed(1)}" font-size="9" text-anchor="middle" fill="#888">${nf(m)}</text>`
      + (v != null && (passe || enCours) ? `<rect x="${(X + bw * .52).toFixed(1)}" y="${y(v).toFixed(1)}" width="${(bw * .36).toFixed(1)}" height="${(Ht - y(v)).toFixed(1)}" rx="2" fill="${coul}"/><text x="${(X + bw * .7).toFixed(1)}" y="${(y(v) - 3).toFixed(1)}" font-size="9.5" font-weight="700" text-anchor="middle" fill="${coul}">${nf(v)}</text>` : '')
      + `<text x="${(X + bw / 2).toFixed(1)}" y="${Ht + 13}" font-size="10" text-anchor="middle" fill="#666">${h} h</text>`;
  }).join('')}</svg>`;
}

/* La journée en une frise, de 04:00 à 19:00 */
const H0 = 4 * 60, H1 = 19 * 60, x = m => (100 * (m - H0) / (H1 - H0)).toFixed(2) + '%', w = (a, b) => (100 * (b - a) / (H1 - H0)).toFixed(2) + '%';
const mxH = Math.max(...Object.values(R.moyHeure), ...V.heures.map(h => h.ca));
const barresH = Object.entries(R.moyHeure).map(([h, m]) => {
  const v = (V.heures.find(z => z.h === +h) || {}).ca;
  const passe = +h * 60 + 60 <= MAINT, enCours = +h * 60 <= MAINT && MAINT < +h * 60 + 60;
  return `<em style="left:calc(${x(+h * 60 + 12)});height:${(18 * m / mxH).toFixed(1)}px;background:#e3dbcf"></em>`
    + (v != null ? `<em style="left:calc(${x(+h * 60 + 30)});height:${(18 * v / mxH).toFixed(1)}px;background:${passe ? (v >= m ? '#2D7A3E' : '#D97706') : enCours ? '#8D1D2C' : '#cfc6ba'}"></em>` : '');
}).join('');
const frise = `<div class="bloc"><div class="t"><h2>La journée</h2><small>de 04:00 à 19:00 · le trait rouge : maintenant</small></div><div class="frise">
<div class="fr-l" style="height:${12 + 15 * R.equipe.postes.length}px"><span class="lb">Équipe<br><small style="font-weight:500;color:#888">${nf(R.equipe.heures, 1)} h</small></span><div class="pis" style="height:${6 + 15 * R.equipe.postes.length}px">${R.equipe.postes.map((p, i) => `<b class="eq" style="left:${x(min(p.debut))};width:${w(min(p.debut), min(p.fin))};top:${3 + 15 * i}px;bottom:auto;height:12px;font-size:9px${min(p.debut) <= MAINT && MAINT < min(p.fin) ? '' : ';background:#bdb3a6'}">${p.debut}–${p.fin} · ${esc(posteNom(p))}</b>`).join('')}<span class="fr-now" style="left:${x(MAINT)}" data-h="${R.maintenant}"></span></div></div>
<div class="fr-l"><span class="lb">Four</span><div class="pis">${P.cuissons.map(c => `<b class="fo${min(c.four) > MAINT ? ' av' : ''}" style="left:${x(min(c.four))};width:${w(min(c.four), min(c.four) + 45)}"></b><span style="position:absolute;top:5px;left:calc(${x(min(c.four) + 50)});font:600 9.5px var(--font-ui);color:#8a5a12;white-space:nowrap">${c.nom} ${c.four}${c.valide || min(c.four) > MAINT ? '' : ' · à valider'}</span>`).join('')}<span class="fr-now" style="left:${x(MAINT)}" data-h=""></span></div></div>
<div class="fr-l"><span class="lb">Vitrine</span><div class="pis">${P.cuissons.map(c => `<b class="eq" style="left:${x(min(c.de))};width:${w(min(c.de), min(c.a))};background:#E8CBA0;color:#6b4a14">${c.pieces} p.</b>`).join('')}<span class="fr-now" style="left:${x(MAINT)}" data-h=""></span></div></div>
<div class="fr-l"><span class="lb">Commandes</span><div class="pis">${C.aujourdhui.map(l => `<b class="cmd" style="left:${x(min(l.heure))}" title="${l.heure}"></b>`).join('')}<span style="position:absolute;left:${x(6 * 60 + 25)};top:4px;font-size:9.5px;color:#555">06:00 · 1 retirée · rien d’autre aujourd’hui</span><span class="fr-now" style="left:${x(MAINT)}" data-h=""></span></div></div>
<div class="fr-ax"><span></span><div class="ax">${[4, 6, 8, 10, 12, 14, 16, 18].map(h => `<span style="left:${x(h * 60)}">${h} h</span>`).join('')}</div></div>
<h4 style="margin:14px 0 2px;font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#666">Ce matin, heure par heure · vendu au comptoir face aux 6 derniers mardis</h4>
${heuresSvg()}
<div class="leg"><span><i style="background:#e3dbcf"></i>moyenne des 6 derniers mardis</span><span><i style="background:#D97706"></i>vendu, sous la moyenne</span><span><i style="background:#2D7A3E"></i>au-dessus</span><span><i style="background:#8D1D2C"></i>heure en cours, à ${R.maintenant}</span></div>
</div></div>`;

/* 3. La vitrine : production face aux ventes */
const lib = { rupture: 'rupture', manque: 'va manquer', trop: 'en trop', ok: 'en ordre' };
const faire = p => p.verdict === 'rupture' || p.verdict === 'manque' ? `recuire ${p.conseil}` : p.verdict === 'trop' ? 'ne pas recuire' : '';
const lignesV = ruptures.concat(manques, trop);
const etat = p => { const v = vide(p); if (p.verdict === 'trop') { return 'finira en trop'; } if (!v) { return lib[p.verdict]; } if (p.sorti === 0) { return 'pas produite'; } return v.a ? `vide de ${v.de} h à ${v.a} h` : `vide vers ${v.de} h`; };
const vitrine = `<div class="bloc vit"><div class="t"><h2>La vitrine</h2><small>ce qui est sorti du four face à ce qui est vendu · prévision des 6 derniers mardis</small><span class="dr">${nf(P.totaux.sorti)} sorties · ${nf(P.totaux.vendu)} vendues · ${nf(P.totaux.stock)} en vitrine</span></div>
<table class="tb"><thead><tr><th>Référence</th><th>Rayon</th><th class="n">Sorties</th><th class="n">Vendues</th><th class="n">En vitrine</th><th class="n">Fin de journée prévue</th><th>État</th><th class="n">À faire</th></tr></thead><tbody>
${lignesV.map(p => `<tr><td><b>${esc(p.nom)}</b></td><td>${esc(p.groupe)}</td><td class="n">${p.sorti}</td><td class="n">${p.vendu}</td><td class="n${p.stock <= 0 ? ' c-ko' : ''}"><b>${p.stock}</b></td><td class="n">${p.finJour > 0 ? '+' : ''}${nf(p.finJour, 1)}</td><td><span class="vd2 ${p.verdict}">${etat(p)}</span></td><td class="n"><b>${faire(p)}</b></td></tr>`).join('')}
</tbody></table><div class="plie"><span class="vd ok"></span>${okN} autres références en ordre<span class="fl">▾</span></div></div>`;

/* 4. Le carrousel des photos du matin */
const photos = `<div class="cq"><div class="cq-t"><span class="lab">Les contrôles en photo · ce matin</span><span class="cqf"><span class="on">Tout<b>${R.taches.length}</b></span><span><i style="background:#2F5D8A"></i>à noter<b>${R.photos.length}</b></span><span class="ko">pas rendus<b>${nonRendues.length}</b></span></span><span class="mini">${rendues.length} photos rendues entre ${heuresRendues[0]} et ${heuresRendues[heuresRendues.length - 1]} · pas encore notées par le consultant · les écarts d’abord</span></div>
<div class="cq-rail"><span class="cq-fl g">‹</span><div class="cq-piste"><div class="cq-g"><div class="cq-cs">
${nonRendues.map(t => `<div class="cq-c vide ko"><span class="ph"><b>✗</b>pas encore rendue</span><span class="nm">${esc(nomTache(t.tache))}</span><span class="e ko">à faire</span></div>`).join('')}
${R.photos.map(p => { const t = R.taches.find(z => z.taskId === p.taskId) || {}; return `<div class="cq-c"><span class="ph"><img src="${p.f}" alt=""><em class="ctl">à noter</em></span><span class="nm">${esc(nomTache(p.tache))}</span><span class="e ctl">rendue à ${t.faitLe || '—'}</span></div>`; }).join('')}
</div></div></div><span class="cq-fl d">›</span></div></div>`;

/* 5. Le reste, en un coup d'œil */
const mini = (vd, k, v, s) => `<div class="bloc"><div class="k"><span class="vd ${vd}"></span>${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
const reste = `<div class="mini4">
${mini('att', 'Commandes clients', `${C.retard} en retard`, `${C.aVenir} à venir · aujourd’hui : 1 retirée à 06:00 · demain : ${C.demain.n}, ${fE2(C.demain.ca)}`)}
${mini('ok', 'Stock', 'À jour', `${nf(R.stock.references)} références · rien à zéro · compté hier à ${R.stock.dernierComptage.slice(11, 16)}`)}
${mini('ok', 'Hier', 'Aucune non-conformité', `${R.hier.notees} photos notées le lundi 5 · rien à reprendre`)}
${mini('', 'Poubelle', 'Pas encore déclarée', 'à encoder à la fermeture · rien jeté pour l’instant')}
${mini('', 'Promotions', 'Aucune', 'pas de promotion posée pour aujourd’hui dans le cockpit')}
</div>`;

const corps = maintenant + `<div class="deux">${aFaire}${frise}</div>` + vitrine + photos.replace('class="cq"', 'class="cq" style="margin-top:12px"') + reste
  + '<div class="renvoi">Le chiffre, la marge, le résultat et le réseau restent dans l’onglet Jour.</div>';

const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dashboard opérationnel</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="../vue-generale/vg.css"><link rel="stylesheet" href="op.css"></head>
<body><div class="page">
<div class="hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="t">${esc(R.magasin)}</div><div class="s">Dashboard magasin · la journée en cours · ${R.jourNom} 6 octobre 2026</div></div>
<div class="dr"><span class="direct"><i></i>en direct · relu à ${R.maintenant}</span><span class="btn">↻ Relire</span></div></div>
<div class="nav"><span class="ong"><span class="on neuf">Opérationnel</span><span>Jour</span><span>Semaine</span><span>Mois</span><span>Trimestre</span><span>Année</span></span><span class="lab">Aujourd’hui</span><span class="dt">06/10/2026</span></div>
${corps}
<span class="mq">Maquette</span></div></body></html>`;
fs.writeFileSync(path.join(D, 'a.html'), html);
console.log('a.html', html.length, 'octets');
