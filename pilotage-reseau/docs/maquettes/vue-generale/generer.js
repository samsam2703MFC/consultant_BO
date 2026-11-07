/* Maquettes « vue générale + détail en liste déroulante » du dashboard magasin, sur les
 * chiffres réels de Halle (reel-halle.json, lu en lecture seule le 06/10/2026).
 *   node docs/maquettes/vue-generale/generer.js   → a.html, b.html, c.html
 * Le réseau reste anonyme ; aucun nom de client ni de membre de l'équipe. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = JSON.parse(fs.readFileSync(path.join(D, 'reel-halle.json'), 'utf8'));
const J = R.jour, S = R.semaine, M = R.mois;
const PH = JSON.parse(fs.readFileSync(path.join(D, 'photos.json'), 'utf8'));

const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fE = n => nf(Math.round(n)) + ' €';
const fE2 = n => nf(n, 2) + ' €';
const fP = (n, d = 1) => nf(n, d) + ' %';
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const somme = (a, f) => a.reduce((t, x) => t + f(x), 0);
const COUL = { ok: '#2D7A3E', att: '#D97706', ko: '#C0182B', or: '#C9A227', neutre: '#9a948c' };

/* Les seuils du dashboard (MA dans dashboard.js) */
const vAtt = p => p >= 110 ? 'or' : p >= 100 ? 'ok' : p >= 90 ? 'att' : 'ko';
const vMat = p => p <= 35 ? 'ok' : 'att';
const vInv = p => p <= 1.5 ? 'ok' : p <= 3 ? 'att' : 'ko';
const vNet = p => p == null ? 'neutre' : p >= 15 ? 'ok' : p >= 5 ? 'att' : 'ko';
const vPart = p => p >= 100 ? 'ok' : p >= 80 ? 'att' : 'ko';
const vTache = p => p >= 80 ? 'ok' : p >= 50 ? 'att' : 'ko';

/* Chiffres dérivés */
const attJ = 100 * J.ca / J.objectif;
// Les contrôles comptés sur les photos : une tâche cochée sans photo n'est pas un contrôle rendu.
const CTRL = PH.jours.map(j => ({ date: j.date, rendus: j.photos.length, total: somme(Object.values(j.statuts), v => v), sans: j.sansPhoto.length, nr: j.nonRendues.length, manquent: j.nonRendues.concat(j.sansPhoto) }));
const ctrlR = somme(CTRL, c => c.rendus), ctrlT = somme(CTRL, c => c.total), ctrlSans = somme(CTRL, c => c.sans), ctrlJ = CTRL[CTRL.length - 1];
const ctrlP = 100 * ctrlR / ctrlT;
const tachT = S.taches.faites + S.taches.pasFaites;
const tachMT = M.taches.faites + M.taches.pasFaites;
const jours = S.jours.map((j, i) => ({ ...j, att: 100 * j.ca / j.objectif, ctrl: CTRL[i], pb: S.poubelleJours[i], tache: S.taches.jours[i] }));
const joursOk = jours.filter(j => j.att >= 100).length;
const six = S.six, s39 = six[six.length - 2];
const dS39 = 100 * (S.ca - s39.ca) / s39.ca;
const meilleureSem = six.reduce((a, w) => (w.ca > a.ca ? w : a), six[0]);
const comptoirS = 100 * S.canaux.comptoir / S.canaux.caisse;
const comptoirM = 100 * M.canaux.comptoir / M.canaux.caisse;
const NOMJ = { L: 'lundi', Ma: 'mardi', Me: 'mercredi', J: 'jeudi', V: 'vendredi', S: 'samedi', D: 'dimanche' };
const nomJ = c => NOMJ[c.split(' ')[0]];
const sem3 = S.categories.slice(0, 3);

/* Mini-graphiques */
const bars = (vals, coul, h = 22, w = 14) => {
  const mx = Math.max(...vals.map(v => Math.abs(v || 0))) || 1;
  return `<span class="bars" style="height:${h}px">${vals.map((v, i) => `<i style="width:${w}px;height:${Math.max(2, Math.round(h * (v || 0) / mx))}px;background:${typeof coul === 'function' ? coul(v, i) : coul}"></i>`).join('')}</span>`;
};
const carres = cs => `<span class="sq">${cs.map(c => `<i style="background:${c}"></i>`).join('')}</span>`;
const prog = (p, coul, w = 190) => `<span class="prog" style="width:${w}px"><i style="width:${Math.min(100, p * 100 / 120).toFixed(1)}%;background:${coul}"></i><b style="left:${(100 * 100 / 120).toFixed(1)}%"></b></span>`;
const spark = (vals, w = 130, h = 22, coul = '#8D1D2C') => {
  const mn = Math.min(...vals), mx = Math.max(...vals), d = mx - mn || 1;
  const pts = vals.map((v, i) => [(i * (w - 6) / (vals.length - 1) + 3).toFixed(1), (h - 3 - (v - mn) * (h - 6) / d).toFixed(1)]);
  return `<svg class="spk" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}"><polyline points="${pts.map(p => p.join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="1.6"/><circle cx="${pts[pts.length - 1][0]}" cy="${pts[pts.length - 1][1]}" r="2.6" fill="${coul}"/></svg>`;
};
const couleurPart = p => COUL[vPart(p)];

/* Le carrousel des photos des tâches : la semaine du plus récent au plus ancien, un groupe par jour ;
 * dans chaque jour, ce qui manque d'abord (une case), puis les photos à noter, puis les notées. */
const JOURS_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const nomTache = t => String(t || '').replace(/^Photo du comptoir\s*-\s*/i, '').replace(/^Vérifier si le magasin est propre\.?$/i, 'Propreté du magasin')
  .replace(/^étiquetage des prix et allergènes$/i, 'Étiquetage prix et allergènes').replace(/^Photo /i, '');
const jourLong = d => { const x = new Date(d + 'T12:00:00'); return JOURS_LONG[x.getDay()] + ' ' + d.slice(8) + '/' + d.slice(5, 7); };
const cqStat = (() => {
  const t = { notee: 0, aControler: 0, sansPhoto: 0, nonRendue: 0 };
  PH.jours.forEach(j => Object.entries(j.statuts).forEach(([k, v]) => { t[k] = (t[k] || 0) + v; }));
  return { ...t, photos: somme(PH.jours, j => j.photos.length), total: somme(PH.jours, j => somme(Object.values(j.statuts), v => v)) };
})();
function carrousel(titre) {
  const groupes = PH.jours.slice().reverse().map(j => {
    const manque = j.nonRendues.length + j.sansPhoto.length;
    const cases = [];
    if (!j.photos.length) {
      cases.push(`<div class="cq-c vide ko large"><span class="ph"><b>✗</b>aucune photo<br>${j.sansPhoto.length} tâches cochées sans photo<br>${j.nonRendues.length} pas rendues</span><span class="nm">Toute la journée</span><span class="e ko">cochées sans preuve</span></div>`);
    } else if (manque) {
      cases.push(`<div class="cq-c vide${j.nonRendues.length ? ' ko' : ''}"><span class="ph"><b>${j.nonRendues.length ? '✗' : '—'}</b>${j.nonRendues.length ? j.nonRendues.length + ' pas rendue' + (j.nonRendues.length > 1 ? 's' : '') : ''}${j.nonRendues.length && j.sansPhoto.length ? '<br>' : ''}${j.sansPhoto.length ? j.sansPhoto.length + ' sans photo' : ''}</span><span class="nm">${esc(j.nonRendues.concat(j.sansPhoto).map(nomTache).join(', '))}</span><span class="e ${j.nonRendues.length ? 'ko' : 'mu'}">${j.nonRendues.length ? 'pas rendues' : 'cochées sans photo'}</span></div>`);
    }
    const ordre = { aControler: 0, notee: 1 };
    j.photos.slice().sort((a, b) => (ordre[a.statut] ?? 2) - (ordre[b.statut] ?? 2)).forEach(p => {
      const note = p.note != null;
      cases.push(`<div class="cq-c"><span class="ph"><img src="${p.f}" alt=""><em class="${note ? (p.note >= 4 ? 'ok' : 'nc') : 'ctl'}">${note ? p.note + '/5' : 'à noter'}</em></span><span class="nm">${esc(nomTache(p.tache))}</span><span class="e ${note ? (p.note >= 4 ? 'ok' : 'ko') : 'ctl'}">${note ? (p.note >= 4 ? 'conforme' : 'à reprendre') : 'déposée, pas encore notée'}</span></div>`);
    });
    const etat = !j.photos.length ? '<b class="c-ko">aucune photo</b>' : `<b>${j.photos.length} photos</b>${manque ? ` <b class="c-ko">· ${manque} manquent</b>` : ''}`;
    return `<div class="cq-g"><div class="cq-j">${jourLong(j.date)} ${etat}</div><div class="cq-cs">${cases.join('')}</div></div>`;
  }).join('');
  const puces = `<span class="cqf"><span class="on">Toute la semaine<b>${cqStat.photos}</b></span>${PH.jours.map(j => `<span class="${j.photos.length ? '' : 'ko'}">${jourLong(j.date).split(' ')[0].slice(0, j.date.endsWith('29') || j.date.endsWith('30') ? 3 : 3)} ${j.date.slice(8).replace(/^0/, '')}<b>${j.photos.length}</b></span>`).join('')}</span>`;
  const etats = `<span class="cqf"><span><i style="background:#2d7a3e"></i>notées<b>${cqStat.notee}</b></span><span><i style="background:#2F5D8A"></i>à noter<b>${cqStat.aControler}</b></span><span><i style="background:#8a8177"></i>sans photo<b>${cqStat.sansPhoto}</b></span><span><i style="background:#C0182B"></i>pas rendues<b>${cqStat.nonRendue}</b></span></span>`;
  return `<div class="cq"><div class="cq-t"><span class="lab">${titre}</span>${puces}${etats}</div>
<div class="cq-rail"><span class="cq-fl g">‹</span><div class="cq-piste">${groupes}</div><span class="cq-fl d">›</span></div>
<div class="cq-pied">${cqStat.photos} photos sur ${cqStat.total} contrôles · ${cqStat.notee} notées, toutes à 4 / 5 · ${cqStat.aControler} attendent la note du consultant · les jours les plus récents d’abord · un clic ouvre la photo en grand</div></div>`;
}

const page = (lettre, titre, nav, corps) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="vg.css"></head>
<body><div class="page">
<div class="hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="t">${esc(R.magasin)}</div><div class="s">${nav.sous}</div></div>
<div class="dr"><span class="valo">Valeur <b>100 328 €</b></span><span class="btn">↻ Relire</span></div></div>
<div class="nav"><span class="ong">${nav.ong.map(([t, on, neuf]) => `<span class="${on ? 'on' : ''}${neuf ? ' neuf' : ''}">${t}</span>`).join('')}</span><span class="lab">${nav.lab}</span><span class="dt">${nav.date}</span></div>
${corps}
<span class="mq">Maquette ${lettre}</span></div>
<script>if (/replie/.test(location.search)) { document.querySelectorAll('.ouv').forEach(e => e.classList.remove('ouv')); document.querySelectorAll('.deroule,.tiroir').forEach(e => e.remove()); }</script>
</body></html>`;

const ONG = actif => ['Jour', 'Semaine', 'Mois', 'Trimestre', 'Année'].map(t => [t, t === actif]);
const compte = (lignes, aide) => {
  const n = k => lignes.filter(l => l[0] === k).length;
  return `<div class="compte"><b>${lignes.length} sections</b><span class="chip ko"><span class="vd ko"></span>${n('ko')} à reprendre</span><span class="chip att"><span class="vd att"></span>${n('att')} à surveiller</span><span class="chip ok"><span class="vd ok"></span>${n('ok') + n('or')} en ordre</span><span class="chip"><span class="vd"></span>${n('neutre')} pour information</span><span class="aide">${aide}</span></div>`;
};

/* ───────────────────────── A : une ligne par section, comme la vue Jour ───────────────────────── */
function maquetteA() {
  const chapitres = [
    ['Ouverture et contrôles', 'la semaine, les tâches, les photos, le stock', [
      ['ok', 'Non-conformités', '<span class="c-ok">Aucune</span>', `${S.nc.notees} photos notées dans la semaine · rien à reprendre`, `<span class="chip ok">${S.nc.notees} notées · 0 à reprendre</span>`],
      [vTache(S.taches.part), 'Tâches de la semaine', `<span class="c-${vTache(S.taches.part)}">${S.taches.faites} / ${tachT}</span>`, `${fP(S.taches.part, 0)} faites · dimanche 0 sur 11 · vendredi et samedi 1 sur 4`, carres(S.taches.jours.map(t => COUL[vTache(t.part)]))],
      [vPart(ctrlP), 'Contrôles en photo', `<span class="c-${vPart(ctrlP)}">${ctrlR} / ${ctrlT}</span>`, `${fP(ctrlP, 0)} avec photo · ${ctrlSans} cochés sans photo, dont ${ctrlJ.sans} dimanche · Biscuiterie et Pâtisseries jamais rendues`, carres(CTRL.map(c => couleurPart(100 * c.rendus / c.total)))],
      ['ok', 'Stock', '<span class="c-ok">À jour</span>', `${nf(R.stock.references)} références · rien à zéro · compté le 05/10`, '<span class="chip ok">compté le 05/10</span>'],
    ]],
    ['Le chiffre de la semaine', 'les ventes, l’objectif, la marge, le réseau', [
      ['neutre', 'Ventes de la semaine', fE(S.ca), `${nf(S.tickets)} clients · panier ${fE2(S.panier)} · ${dS39 >= 0 ? '+' : '−'} ${fP(Math.abs(dS39))} face à ${s39.lab}`, bars(six.map(w => w.ca), (v, i) => i === six.length - 1 ? '#8D1D2C' : '#cfc6ba')],
      [vAtt(S.atteinte), 'Objectif de la semaine', `<span class="c-${vAtt(S.atteinte)}">${fP(S.atteinte)}</span>`, `objectif ${fE(S.objectif)} · il manque ${fE(S.objectif - S.ca)} · ${S.manquants} clients au panier moyen`, prog(S.atteinte, COUL[vAtt(S.atteinte)])],
      [vMat(S.matPct), 'Marge et matière', `${fP(S.mbPct, 0)}`, `de marge brute · matière ${fP(S.matPct, 0)}, seuil 35 % · résultat net : frais connus pour le mois courant seulement`, `<span class="prog" style="width:190px;background:#2D7A3E"><i style="width:${S.matPct}%;background:#D97706"></i></span>`],
      ['neutre', 'Place dans le réseau', `${S.rangCa[0]}<sup>e</sup> / ${S.rangCa[1]}`, `en chiffre d’affaires · ${S.rangAtt[0]}<sup>e</sup> en atteinte · le 1er vend ${fE(S.rangCa[2])} · anonyme`, carres([1, 2, 3, 4].map(i => i === S.rangCa[0] ? '#8D1D2C' : '#ddd'))],
    ]],
    ['Le détail des ventes', 'les canaux, les catégories, les offres, la poubelle', [
      ['neutre', 'Commandes et canaux', `${fP(comptoirS)} comptoir`, `click & collect ${fE(S.canaux.cc.ca)} · ${S.canaux.cc.n} commandes · pas de livraison · clients pro ${fE(S.pro.ca)} · ${S.pro.n} tickets`, `<span class="prog" style="width:190px;background:#2F6EA5"><i style="width:${comptoirS}%;background:#b9ab98"></i></span>`],
      ['neutre', 'Ventes par catégorie', esc(sem3[0].nom.replace(' Ind.', '')), `${fP(sem3[0].part)} du CA · puis ${esc(sem3[1].nom)} ${fP(sem3[1].part, 0)} et ${esc(sem3[2].nom)} ${fP(sem3[2].part, 0)}`, `<span class="prog" style="width:190px"><i style="width:${sem3[0].part}%;background:#E58A2E"></i></span>`],
      ['neutre', 'Promotions et bundles', 'Aucune', 'pas de bundle vendu, pas de promotion posée dans le cockpit', '<span class="chip">rien en cours</span>'],
      [vInv(S.invPct), 'Invendus et poubelle', `<span class="c-${vInv(S.invPct)}">${fE(S.inv)}</span>`, `${fP(S.invPct)} du CA · ${S.invPieces} pièces · d’abord ${esc(S.invTop[0].nom)} : ${S.invTop[0].pieces} pièces, ${fE(S.invTop[0].cout)}`, `<span class="chip ${vInv(S.invPct)}">${S.invPieces} pièces</span>`],
    ]],
    ['La semaine en détail', 'les jours, les heures, six semaines, la note', [
      [joursOk >= 5 ? 'ok' : joursOk >= 3 ? 'att' : 'ko', 'Les jours', `<span class="c-att">${joursOk} / 7</span>`, `à l’objectif · lundi ${fP(jours[0].att, 0)}, mardi ${fP(jours[1].att, 0)}, mercredi ${fP(jours[2].att, 0)} · samedi ${fP(jours[5].att, 0)}`, bars(jours.map(j => j.ca), (v, i) => COUL[vAtt(jours[i].att)])],
      ['neutre', 'Les heures', `${S.meilleure.h} – ${S.meilleure.h + 1} h`, `l’heure la plus rentable : + ${fE(S.meilleure.res)} de résultat sur la semaine · la moins bonne : ${S.pire.h} h`, bars(S.heures.filter(h => h.h >= 6 && h.h <= 19).map(h => h.ca), '#cfc6ba', 22, 9)],
      [meilleureSem === six[six.length - 1] ? 'or' : 'neutre', 'Six semaines', meilleureSem === six[six.length - 1] ? '<span class="c-or">La meilleure</span>' : `${meilleureSem.lab}`, `des six dernières semaines · ${six[0].lab} : ${fE(six[0].ca)} · ${s39.lab} : ${fE(s39.ca)} · ${S.nom} : ${fE(S.ca)}`, spark(six.map(w => w.ca), 190)],
      ['att', 'Note de la semaine', '<span class="c-att">Pas de note</span>', 'ce qui explique la semaine, relue l’an prochain la même semaine', '<span class="chip att">✎ à écrire</span>'],
    ]],
  ];
  const ouverte = 'Les jours';
  const detail = `<div class="deroule"><table class="tb"><thead><tr><th>Jour</th><th class="n">Objectif</th><th class="n">Chiffre</th><th style="width:250px">Face à l’objectif</th><th class="n">Atteinte</th><th class="n">Clients</th><th class="n">Panier</th><th class="n">Avec photo</th><th class="n">Tâches faites</th><th class="n">Poubelle</th></tr></thead><tbody>
${jours.map(j => `<tr><td><b>${nomJ(j.court)} ${j.date.slice(8)}/${j.date.slice(5, 7)}</b></td><td class="n">${fE(j.objectif)}</td><td class="n"><b>${fE(j.ca)}</b></td><td><div class="barre"><i style="width:${Math.min(100, j.att / 1.2).toFixed(1)}%;background:${COUL[vAtt(j.att)]}"></i><b style="left:${(100 / 1.2).toFixed(1)}%"></b></div></td><td class="n c-${vAtt(j.att)}"><b>${fP(j.att, 0)}</b></td><td class="n">${j.tickets}</td><td class="n">${fE2(j.ca / j.tickets)}</td><td class="n c-${vPart(100 * j.ctrl.rendus / j.ctrl.total)}">${j.ctrl.rendus} / ${j.ctrl.total}</td><td class="n c-${vTache(j.tache.part)}">${j.tache.faites} / ${j.tache.faites + j.tache.pasFaites}</td><td class="n">${fE(j.pb.cout)} · ${j.pb.pieces} p.</td></tr>`).join('')}
<tr class="tot"><td>La semaine</td><td class="n">${fE(S.objectif)}</td><td class="n">${fE(S.ca)}</td><td><div class="barre"><i style="width:${(S.atteinte / 1.2).toFixed(1)}%;background:${COUL[vAtt(S.atteinte)]}"></i><b style="left:${(100 / 1.2).toFixed(1)}%"></b></div></td><td class="n c-${vAtt(S.atteinte)}">${fP(S.atteinte, 0)}</td><td class="n">${nf(S.tickets)}</td><td class="n">${fE2(S.panier)}</td><td class="n">${ctrlR} / ${ctrlT}</td><td class="n">${S.taches.faites} / ${tachT}</td><td class="n">${fE(somme(S.poubelleJours, p => p.cout))} · ${somme(S.poubelleJours, p => p.pieces)} p.</td></tr></tbody></table>
<div class="note">Ce que la vue Semaine montre aujourd’hui en cinq blocs (le calendrier, le fil des jours, les tâches, la poubelle jour par jour) tient dans ce tableau. Les autres lignes s’ouvrent de la même façon : le P&L, les heures, les commandes jour par jour, la courbe des six semaines.</div></div>`;
  const toutes = chapitres.flatMap(c => c[2]);
  const corps = compte(toutes, 'un clic sur une ligne ouvre la section · un second clic la replie')
    + chapitres.map(([t, s, lignes], i) => `<div class="chap"><span class="no">${i + 1}</span><h2>${t}</h2><small>${s}</small></div><div class="liste">${lignes.map(l => {
      const o = l[1] === ouverte;
      return `<div class="li${o ? ' ouv' : ''}"><span class="vd ${l[0]}"></span><span class="q">${l[1]}</span><span class="r">${l[2]}</span><span class="p">${l[3]}</span><span class="m">${l[4]}</span><span class="fl">▾</span></div>${o ? detail : ''}`;
    }).join('')}</div>${i === 0 ? carrousel('Les contrôles en photo · semaine 40') : ''}`).join('');
  return page('A', 'Vue générale A : une ligne par section', { sous: `Dashboard magasin · ${S.nom}, du 28/09 au 04/10 · lu le 06/10 à 07:20`, ong: ONG('Semaine'), lab: 'Semaine du', date: '28/09/2026' }, corps);
}

/* ───────────────────────── B : le tableau croisé jour · semaine · mois ───────────────────────── */
function maquetteB() {
  const c = (v, vd, sous) => `<span class="c">${vd ? `<span class="vd ${vd}"></span>` : ''}<b>${v}</b>${sous ? `<span>${sous}</span>` : ''}</span>`;
  const rien = sous => `<span class="c"><b class="c-mu">—</b><span>${sous}</span></span>`;
  const groupes = [
    ['Le chiffre', [
      ['Chiffre d’affaires', c(fE(J.ca), '', `${fP(100 * (J.ca - J.j7.ca) / J.j7.ca)} face à J−7`), c(fE(S.ca), '', `${dS39 >= 0 ? '+' : '−'} ${fP(Math.abs(dS39))} face à ${s39.lab}`), c(fE(M.ca), '', `${M.jours.length} jours`), spark(six.map(w => w.ca))],
      ['Objectif atteint', c(fP(attJ, 0), vAtt(attJ), `de ${fE(J.objectif)} · record ${fE(J.record.ca)}`), c(fP(S.atteinte, 0), vAtt(S.atteinte), `il manque ${fE(S.objectif - S.ca)}`), c(fP(M.atteinte, 0), vAtt(M.atteinte), `il manque ${fE(M.objectif - M.ca)}`), bars(jours.map(j => j.att), (v, i) => COUL[vAtt(jours[i].att)], 20, 12)],
      ['Clients', c(nf(J.tickets), '', `${J.tickets - J.j7.tickets >= 0 ? '+' : '−'} ${Math.abs(J.tickets - J.j7.tickets)} face à J−7`), c(nf(S.tickets), '', `${S.manquants} de moins que l’attendu`), c(nf(M.tickets), '', `${M.manquants} de moins que l’attendu`), spark(six.map(w => w.tickets))],
      ['Panier moyen', c(fE2(J.panier)), c(fE2(S.panier)), c(fE2(M.panier)), spark(six.map(w => w.panier))],
      ['Place dans le réseau', c(`${J.rangCa[0]}<sup>e</sup> / ${J.rangCa[1]}`, '', 'en chiffre'), c(`${S.rangCa[0]}<sup>e</sup> / ${S.rangCa[1]}`, '', `${S.rangAtt[0]}<sup>e</sup> en atteinte`), c(`${M.rangCa[0]}<sup>e</sup> / ${M.rangCa[1]}`, '', `${M.rangAtt[0]}<sup>e</sup> en atteinte`), '<span class="c-mu" style="font-size:10.5px">anonyme</span>'],
    ]],
    ['La marge', [
      ['Matière', c(fP(J.matPct), vMat(J.matPct), 'seuil 35 %'), c(fP(S.matPct), vMat(S.matPct)), c(fP(M.matPct), vMat(M.matPct)), ''],
      ['Marge brute', c(fE(J.mb), '', fP(J.mbPct)), c(fE(S.mb), '', fP(S.mbPct)), c(fE(M.mb), '', fP(M.mbPct)), ''],
      ['Invendus et poubelle', c(fE(J.inv), vInv(J.invPct), `${fP(J.invPct)} · ${J.invPieces} pièces`), c(fE(S.inv), vInv(S.invPct), `${fP(S.invPct)} · ${S.invPieces} pièces`), c(fE(M.inv), vInv(M.invPct), `${fP(M.invPct)} · ${nf(M.invPieces)} pièces`), bars(S.poubelleJours.map(p => p.cout), '#cfc6ba', 20, 12)],
      ['Main-d’œuvre', c(fE(J.labour), '', `${fP(J.labourPct)} · ${nf(J.heuresPlanning, 1)} h · ${J.personnes} personnes`), rien('connue pour le mois courant'), rien('connue pour le mois courant'), ''],
      ['Résultat net', c(fE(J.net), vNet(J.netPct), `${fP(J.netPct, 0)} des ventes`), rien('frais du mois courant seulement'), rien('frais du mois courant seulement'), ''],
    ]],
    ['Le magasin', [
      ['Contrôles en photo', c(`${ctrlJ.rendus} / ${ctrlJ.total}`, vPart(100 * ctrlJ.rendus / ctrlJ.total), `avec photo · ${ctrlJ.sans} cochés sans photo`), c(`${ctrlR} / ${ctrlT}`, vPart(ctrlP), `${fP(ctrlP, 0)} · ${ctrlSans} sans photo`), rien('pas relu au mois'), carres(CTRL.map(x => couleurPart(100 * x.rendus / x.total)))],
      ['Tâches faites', c(`${J.taches.faites} / ${J.taches.faites + J.taches.pasFaites}`, vTache(J.taches.part)), c(`${S.taches.faites} / ${tachT}`, vTache(S.taches.part), fP(S.taches.part, 0)), c(`${M.taches.faites} / ${tachMT}`, vTache(M.taches.part), fP(M.taches.part, 0)), carres(S.taches.jours.map(t => COUL[vTache(t.part)]))],
      ['Non-conformités', c('0', 'ok'), c('0', 'ok', `sur ${S.nc.notees} notées`), rien(''), ''],
      ['Stock', `<span class="large"><span class="vd ok" style="align-self:center"></span><b style="font:400 17px var(--font-display)">À jour</b><span style="font-size:10.5px;color:#666">${nf(R.stock.references)} références · rien à zéro · compté le 05/10</span></span>`, ''],
    ]],
    ['Les ventes', [
      ['Canaux', c(fE(J.canaux.comptoir), '', `comptoir · click & collect ${fE(J.canaux.cc)}`), c(fP(comptoirS), '', `comptoir · click & collect ${fE(S.canaux.cc.ca)}`), c(fP(comptoirM), '', `comptoir · click & collect ${fE(M.canaux.cc.ca)}`), ''],
      ['Clients pro', c(fE(J.canaux.pro || 0), '', `${J.canaux.proN || 0} ticket`), c(fE(S.pro.ca), '', `${S.pro.n} tickets`), c(fE(M.pro.ca), '', `${M.pro.n} tickets`), ''],
      ['Première catégorie', c(esc(J.categories[0].nom.replace(' Ind.', '')), '', fP(J.categories[0].part, 0)), c(esc(S.categories[0].nom.replace(' Ind.', '')), '', fP(S.categories[0].part, 0)), c(esc(M.categories[0].nom.replace(' Ind.', '')), '', fP(M.categories[0].part, 0)), ''],
    ]],
  ];
  const ouverte = 'Objectif atteint';
  const hJ = J.heures.filter(h => h.h >= 6 && h.h <= 19);
  let cum = 0;
  const cumJ = hJ.map(h => (cum += h.ca));
  const pan = (titre, gros, sous, svg) => `<div class="pan"><h4>${titre}</h4><div class="gros">${gros}</div><div class="sous">${sous}</div>${svg}</div>`;
  const colonnes = (vals, objs, labs, coul, w = 400, h = 120) => {
    const mx = Math.max(...vals, ...objs.filter(Boolean)) * 1.08, n = vals.length, bw = (w - 10) / n;
    return `<svg width="100%" viewBox="0 0 ${w} ${h + 16}" style="margin-top:8px">${vals.map((v, i) => {
      const x = 5 + i * bw, bh = h * v / mx, o = objs[i];
      return `<rect x="${(x + bw * .15).toFixed(1)}" y="${(h - bh).toFixed(1)}" width="${(bw * .7).toFixed(1)}" height="${bh.toFixed(1)}" rx="2" fill="${coul(v, i)}"/>${o ? `<line x1="${(x + bw * .05).toFixed(1)}" x2="${(x + bw * .95).toFixed(1)}" y1="${(h - h * o / mx).toFixed(1)}" y2="${(h - h * o / mx).toFixed(1)}" stroke="#222" stroke-width="1.6"/>` : ''}${labs[i] ? `<text x="${(x + bw / 2).toFixed(1)}" y="${h + 12}" font-size="9" text-anchor="middle" fill="#777">${labs[i]}</text>` : ''}`;
    }).join('')}</svg>`;
  };
  const courbe = (vals, obj, labs, w = 400, h = 120) => {
    const mx = Math.max(obj, ...vals) * 1.08, n = vals.length, sx = i => 5 + i * (w - 10) / (n - 1), sy = v => h - h * v / mx;
    return `<svg width="100%" viewBox="0 0 ${w} ${h + 16}" style="margin-top:8px"><line x1="5" x2="${w - 5}" y1="${sy(obj).toFixed(1)}" y2="${sy(obj).toFixed(1)}" stroke="#222" stroke-dasharray="4 3"/><text x="8" y="${(sy(obj) - 4).toFixed(1)}" font-size="9">objectif ${fE(obj)}</text><polyline points="${vals.map((v, i) => sx(i).toFixed(1) + ',' + sy(v).toFixed(1)).join(' ')}" fill="none" stroke="${COUL.or}" stroke-width="2.4"/>${labs.map((l, i) => l ? `<text x="${sx(i).toFixed(1)}" y="${h + 12}" font-size="9" text-anchor="middle" fill="#777">${l}</text>` : '').join('')}<circle cx="${sx(n - 1).toFixed(1)}" cy="${sy(vals[n - 1]).toFixed(1)}" r="3.5" fill="${COUL.or}"/></svg>`;
  };
  const detail = `<div class="deroule"><div class="trois">
${pan('Le jour · dimanche 4 octobre', `<span class="c-or">${fP(attJ, 0)}</span> · ${fE(J.ca)}`, `l’objectif de ${fE(J.objectif)} est passé vers 16 h · chiffre cumulé heure par heure`, courbe(cumJ, J.objectif, hJ.map(h => h.h % 2 === 0 ? h.h + ' h' : '')))}
${pan('La semaine 40', `<span class="c-att">${fP(S.atteinte, 0)}</span> · ${fE(S.ca)}`, `${joursOk} jours sur 7 à l’objectif · le trait noir : l’objectif du jour`, colonnes(jours.map(j => j.ca), jours.map(j => j.objectif), jours.map(j => j.court), (v, i) => COUL[vAtt(jours[i].att)]))}
${pan('Le mois · septembre', `<span class="c-ko">${fP(M.atteinte, 0)}</span> · ${fE(M.ca)}`, `${M.jours.filter(j => j.ca >= j.objectif).length} jours sur ${M.jours.length} à l’objectif · les week-ends tirent, les lundis et mardis manquent`, colonnes(M.jours.map(j => j.ca), M.jours.map(j => j.objectif), M.jours.map((j, i) => i % 5 === 0 ? j.court.split(' ')[1] : ''), v => '#cfc6ba'))}
</div><div class="note">Chaque ligne s’ouvre ainsi sur ses trois périodes côte à côte. Les onglets Jour, Semaine et Mois restent là pour le détail complet d’une seule période.</div></div>`;
  const phrase = `<div class="phrase"><div class="txt">Dimanche a dépassé son objectif <span class="c-or">(${fP(attJ, 0)})</span>. La semaine 40 finit à <span class="c-att">${fP(S.atteinte, 0)}</span> et septembre à <span class="c-ko">${fP(M.atteinte, 0)}</span> : le chiffre manque du lundi au mercredi, et la matière reste au-dessus de 35 %.</div><div style="display:flex;gap:6px">__COMPTE__</div></div>`;
  const tete = `<div class="tete"><div>Mesure</div><div>Le jour<b>dimanche 4 octobre</b></div><div>La semaine<b>40 · 28/09 au 04/10</b></div><div>Le mois<b>septembre</b></div><div>Tendance<b style="font:600 10.5px var(--font-ui);color:#666">6 semaines, 7 jours</b></div><div></div></div>`;
  const corps = phrase + carrousel('Les contrôles en photo · la semaine au 04/10').replace('class="cq"', 'class="cq" style="margin:0 0 12px"') + `<div class="croise">${tete}${groupes.map(([g, rgs]) => `<div class="gr">${g}</div>${rgs.map(r => {
    const o = r[0] === ouverte;
    const cellules = r.length === 3 ? r[1] + '<span></span>' : `${r[1]}${r[2]}${r[3]}<span>${r[4]}</span>`;
    return `<div class="rg${o ? ' ouv' : ''}"><span class="q">${r[0]}</span>${cellules}<span class="fl">▾</span></div>${o ? detail : ''}`;
  }).join('')}`).join('')}</div>`;
  // Le pire verdict de chaque ligne, pour le décompte en tête.
  const pires = groupes.flatMap(g => g[1]).map(r => { const h = r.slice(1, 4).join(''); return /vd ko/.test(h) ? 'ko' : /vd att/.test(h) ? 'att' : /vd (ok|or)/.test(h) ? 'ok' : 'neutre'; });
  const nb = k => pires.filter(p => p === k).length;
  const corpsB = corps.replace('__COMPTE__', `<span class="chip ko">${nb('ko')} à reprendre</span><span class="chip att">${nb('att')} à surveiller</span><span class="chip ok">${nb('ok')} en ordre</span>`);
  return page('B', 'Vue générale B : le tableau croisé', { sous: 'Dashboard magasin · vue générale au dimanche 4 octobre 2026 · lu le 06/10 à 07:20', ong: [['Vue générale', true, true], ...ONG('')], lab: 'Au', date: '04/10/2026' }, corpsB);
}

/* ───────────────────────── C : six domaines en cartes, le détail en tiroir ───────────────────────── */
function maquetteC() {
  const ouverte = 'Contrôles et tâches';
  const cartes = [
    ['att', 'Ventes', `${fE(S.ca)}<small>${fP(S.atteinte, 0)} de l’objectif</small>`, `il manque ${fE(S.objectif - S.ca)} · lundi, mardi et mercredi sous 80 % · samedi et dimanche au-dessus`,
      bars(jours.map(j => j.ca), (v, i) => COUL[vAtt(jours[i].att)], 54, 46), `<span>objectif <b>${fE(S.objectif)}</b></span><span>${s39.lab} <b>${fE(s39.ca)}</b></span>`],
    ['att', 'Marge', `${fP(S.mbPct, 0)}<small>de marge brute</small>`, `matière ${fP(S.matPct, 0)}, au-dessus du seuil de 35 % · poubelle ${fP(S.invPct)} · ${fE(S.mb)} de marge brute`,
      `<div style="display:flex;height:16px;border-radius:5px;overflow:hidden"><i style="width:${S.matPct}%;background:#D97706"></i><i style="width:${S.invPct}%;background:#C0182B"></i><i style="flex:1;background:#2D7A3E"></i></div><div style="display:flex;justify-content:space-between;font-size:10px;color:#666;margin-top:4px"><span>matière ${fP(S.matPct, 0)}</span><span>poubelle ${fP(S.invPct)}</span><span>marge ${fP(S.mbPct, 0)}</span></div>`,
      '<span>résultat net : <b>frais connus pour le mois courant seulement</b></span>'],
    ['att', 'Clients', `${nf(S.tickets)}<small>clients</small>`, `${S.manquants} de moins que l’attendu au panier de ${fE2(S.panier)} · ${s39.lab} : ${nf(s39.tickets)}`,
      spark(six.map(w => w.tickets), 400, 54), `<span>panier <b>${fE2(S.panier)}</b></span><span>pro <b>${S.pro.n} tickets</b></span>`],
    [vPart(ctrlP) === 'ok' && vTache(S.taches.part) === 'ok' ? 'ok' : 'ko', 'Contrôles et tâches', `${fP(ctrlP, 0)}<small>des contrôles avec photo</small>`, `${ctrlSans} cochés sans photo, dont ${ctrlJ.sans} dimanche · tâches faites ${fP(S.taches.part, 0)} · aucune non-conformité sur ${S.nc.notees} notées`,
      ['photos', CTRL.map(x => couleurPart(100 * x.rendus / x.total)), 'tâches', S.taches.jours.map(t => COUL[vTache(t.part)])].reduce((h, v, i, a) => i % 2 ? h : h + `<div style="display:flex;align-items:center;gap:4px;margin-top:4px"><span style="width:50px;font-size:10px;color:#666">${v}</span>${a[i + 1].map(c => `<i style="display:block;flex:1;height:18px;border-radius:4px;background:${c}"></i>`).join('')}</div>`, ''),
      `<span>stock <b>à jour</b></span><span>${nf(R.stock.references)} références</span>`],
    ['neutre', 'Produits', `${esc(S.categories[0].nom.replace(' Ind.', ''))}<small>${fP(S.categories[0].part, 0)} du CA</small>`, `puis ${esc(S.categories[1].nom)} ${fP(S.categories[1].part, 0)} et ${esc(S.categories[2].nom)} ${fP(S.categories[2].part, 0)} · le plus jeté : ${esc(S.invTop[0].nom)}`,
      S.categories.slice(0, 4).map(k => `<div style="display:flex;align-items:center;gap:8px;font-size:10.5px;margin-top:3px"><span style="width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(k.nom)}</span><span class="barre" style="flex:1;min-width:0;height:8px"><i style="width:${(100 * k.v / S.categories[0].v).toFixed(1)}%;background:#E58A2E"></i></span><span style="width:52px;text-align:right">${fE(k.v)}</span></div>`).join(''),
      `<span>poubelle <b>${fE(S.inv)}</b></span><span><b>${S.invPieces}</b> pièces</span>`],
    ['neutre', 'Canaux et réseau', `${fP(comptoirS, 0)}<small>au comptoir</small>`, `click & collect ${fE(S.canaux.cc.ca)} · ${S.canaux.cc.n} commandes · clients pro ${fE(S.pro.ca)} · pas de livraison`,
      `<div style="display:flex;align-items:center;gap:10px"><span class="lab">réseau</span>${[1, 2, 3, 4].map(i => `<span style="width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font:400 15px var(--font-display);${i === S.rangCa[0] ? 'background:#8D1D2C;color:#fff' : 'background:#eee6da;color:#999'}">${i}</span>`).join('')}<span style="font-size:10.5px;color:#666">en chiffre · anonyme</span></div>`,
      `<span>réseau <b>${S.rangCa[0]}<sup>e</sup> / ${S.rangCa[1]}</b> en chiffre</span><span><b>${S.rangAtt[0]}<sup>e</sup></b> en atteinte</span>`],
  ];
  const ligne = (titre, cel) => `<div class="l">${titre}</div>${cel.join('')}`;
  const cs = (cls, gros, petit) => `<div class="cs ${cls}"><b>${gros}</b>${petit || ''}</div>`;
  const tiroir = `<div class="tiroir" style="--x:0"><h3>Contrôles et tâches, jour par jour <small>semaine 40 · une case par jour · rouge : à reprendre · orange : à surveiller · vert : en ordre</small></h3>
<div class="damier"><div class="h g"></div>${jours.map(j => `<div class="h">${nomJ(j.court)} ${j.date.slice(8)}</div>`).join('')}<div class="h">Semaine</div>
${ligne('Avec photo', jours.map(j => cs(vPart(100 * j.ctrl.rendus / j.ctrl.total), `${j.ctrl.rendus} / ${j.ctrl.total}`, [j.ctrl.sans ? j.ctrl.sans + ' sans photo' : '', j.ctrl.nr ? j.ctrl.nr + ' pas rendues' : ''].filter(Boolean).join(' · '))).concat(`<div class="t">${ctrlR} / ${ctrlT}</div>`))}
${ligne('Tâches faites', jours.map(j => cs(vTache(j.tache.part), `${j.tache.faites} / ${j.tache.faites + j.tache.pasFaites}`, fP(j.tache.part, 0))).concat(`<div class="t">${S.taches.faites} / ${tachT}</div>`))}
${ligne('Non-conformités', jours.map(() => cs('ok', '0')).concat('<div class="t">0</div>'))}
${ligne('Poubelle', jours.map(j => cs(vInv(100 * j.pb.cout / j.ca), fE(j.pb.cout), `${j.pb.pieces} pièces`)).concat(`<div class="t">${fE(somme(S.poubelleJours, p => p.cout))}</div>`))}
${ligne('Chiffre / objectif', jours.map(j => cs(vAtt(j.att), fP(j.att, 0), fE(j.ca))).concat(`<div class="t">${fP(S.atteinte, 0)}</div>`))}
</div><div class="note">Biscuiterie et Pâtisseries ne sont jamais rendues ; dimanche, 9 contrôles sont cochés sans aucune photo. Les tâches lâchent à partir du vendredi : 1 sur 4 vendredi et samedi, 0 sur 11 dimanche. Les autres cartes s’ouvrent de la même façon : le P&L sous Marge, les heures sous Ventes, six semaines sous Clients.</div></div>`;
  const carte = ([vd, k, v, s, g, pied]) => `<div class="carte${k === ouverte ? ' ouv' : ''}"><div class="k"><span class="vd ${vd}"></span>${k}<span class="fl">▾</span></div><div class="v">${v}</div><div class="s">${s}</div><div class="graph">${g}</div><div class="pied">${pied}</div></div>`;
  const verdict = `<div class="verdict"><div class="gros c-att">${fP(S.atteinte, 0)}</div><div class="txt">Semaine 40 : ${fE(S.ca)} pour ${fE(S.objectif)}.<br>Il manque ${fE(S.objectif - S.ca)}, surtout du lundi au mercredi.</div><div class="chips"><span class="chip ko">les tâches : ${fP(S.taches.part, 0)}</span><span class="chip att">la matière : ${fP(S.matPct, 0)}</span><span class="chip ${vPart(ctrlP)}">les photos : ${fP(ctrlP, 0)}</span><span class="chip ok">la poubelle : ${fP(S.invPct)}</span><span class="chip ok">le stock</span></div></div>`;
  const idx = cartes.findIndex(c => c[1] === ouverte);
  const corps = verdict + `<div class="dom">${cartes.slice(0, 3).map(carte).join('')}${cartes.slice(3).map(carte).join('')}${tiroir.replace('style="--x:0"', `style="--x:${idx % 3}"`)}</div>` + carrousel('Les contrôles en photo · semaine 40').replace('class="cq"', 'class="cq" style="margin-top:12px"')
    + `<style>.tiroir::before{left:calc(${(idx % 3)} * (100% + 12px) / 3 + 60px)}</style>`;
  return page('C', 'Vue générale C : six domaines en cartes', { sous: `Dashboard magasin · ${S.nom}, du 28/09 au 04/10 · lu le 06/10 à 07:20`, ong: ONG('Semaine'), lab: 'Semaine du', date: '28/09/2026' }, corps);
}

for (const [f, html] of [['a.html', maquetteA()], ['b.html', maquetteB()], ['c.html', maquetteC()]]) {
  fs.writeFileSync(path.join(D, f), html);
  console.log(f, html.length, 'octets');
}

/* La planche : les trois côte à côte, repliées puis ouvertes, avec leurs apports et leurs limites. */
const PL = [
  ['A', 'Une ligne par section, partout', 'La forme de la vue Jour, reprise pour la semaine et le mois : quatre chapitres, seize lignes, chaque section en liste déroulante.',
    ['la même lecture que la vue Jour, déjà en place : rien à réapprendre', 'la vue Semaine passe de 3 770 px à moins de 1 000 px repliée', 'chaque section garde tout son détail sous sa ligne'],
    ['une période à la fois : comparer jour, semaine et mois demande trois onglets', 'seize lignes de texte : il faut lire pour trier']],
  ['B', 'Le tableau croisé', 'Un onglet « Vue générale » : les mesures en lignes, le jour, la semaine et le mois en colonnes. Une ligne s’ouvre sur ses trois périodes.',
    ['une seule page répond à « comment va le magasin ? »', 'les écarts sautent aux yeux : le dimanche à 114 %, le mois à 83 %', 'les onglets Jour, Semaine et Mois restent pour le détail complet'],
    ['un onglet de plus', 'des cases vides là où le serveur ne chiffre pas encore : le résultat net d’une semaine ou d’un mois clos']],
  ['C', 'Six domaines en cartes', 'Ventes, marge, clients, contrôles, produits, canaux : une carte par domaine, un graphique, la couleur d’abord. Le détail s’ouvre en tiroir sous la carte.',
    ['le plus visuel : on voit avant de lire', 'tient sur un écran ; le tiroir garde la page sous les yeux'],
    ['moins de chiffres d’un coup', 'regroupe des sections : la note, les promotions et le stock passent en pied de carte']],
];
const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Vue générale : trois maquettes</title><link rel="stylesheet" href="/public/assets/ds/global.css"><style>
body{margin:0;background:#EAE4DC;font-family:var(--font-ui);color:#222}.w{width:2280px;margin:0 auto;padding:24px}h1{font:400 30px var(--font-display);margin:0 0 4px}.sous{font-size:13px;color:#555;margin-bottom:18px}
.g{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}.col{background:#fff;border-radius:14px;padding:16px}.col h2{font:400 22px var(--font-display);margin:0}.col h2 span{color:#8D1D2C;margin-right:8px}
.col p{font-size:12.5px;color:#444;margin:6px 0 10px;line-height:1.45}.col img{width:100%;border:.5px solid rgba(0,0,0,.12);border-radius:8px;display:block;margin-bottom:10px}
h4{font:600 10px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;color:#666;margin:10px 0 4px}ul{margin:0;padding-left:18px;font-size:12.5px;line-height:1.5}ul.p li::marker{color:#2D7A3E}ul.m li::marker{color:#C0182B}
.cap{font-size:11px;color:#777;margin:-4px 0 10px}</style></head><body><div class="w">
<h1>Le dashboard magasin : une vue générale, le détail en liste déroulante</h1>
<div class="sous">Atelier by - Halle · chiffres et photos réels lus le 06/10/2026 · dimanche 4 octobre, semaine 40 (28/09 au 04/10), septembre · réseau anonyme · le téléphone garde ses trois onglets · dans les trois, le carrousel des photos des tâches de la semaine reste visible, sans clic</div>
<div class="g">${PL.map(([l, t, d, plus, moins]) => `<div class="col"><h2><span>${l}</span>${t}</h2><p>${d}</p>
<img src="${l.toLowerCase()}-replie.png" alt=""><div class="cap">repliée</div><img src="${l.toLowerCase()}.png" alt=""><div class="cap">une section ouverte</div>
<h4>Ce qu’elle apporte</h4><ul class="p">${plus.map(x => `<li>${x}</li>`).join('')}</ul><h4>Ce qu’elle coûte</h4><ul class="m">${moins.map(x => `<li>${x}</li>`).join('')}</ul></div>`).join('')}</div></div></body></html>`;
fs.writeFileSync(path.join(D, 'planche.html'), planche);
console.log('planche.html', planche.length, 'octets');
