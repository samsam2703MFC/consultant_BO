/* Maquette « Le calendrier des posts dans le rail » (demande du 15/11/2026 : « rajouter dans le rail le marketing
 * franchisé, post calendar — faire maquette screenshot »).
 *
 *   node docs/maquettes/rail-franchise/generer-marketing.js   → marketing.html
 *   node docs/maquettes/rail-franchise/capturer.js            → marketing.png (avec les autres écrans)
 *
 * Le rail du dashboard tel qu'il est en ligne (15/11/2026), avec un groupe Marketing : Calendrier des posts, Fidélité et
 * Mon catalogue (les deux derniers quittent Vendre : ils relèvent du Marketing). « Calendrier des posts » ouvre, à
 * droite, l'espace boutique du Marketing (`/marketing/franchise/calendrier?shop=…`, dépôt `marketing`,
 * CalendrierFranchiseView) incorporé dans le dashboard (§9 du design system : `embed=1`, sans son propre en-tête).
 * Ce que montre le cadre reprend la structure de cet écran : les onglets Calendrier · Vues et abonnés · Suivi de ma
 * boutique, l'en-tête (Mois · Semaine · Liste, ‹ Aujourd'hui ›, Publier un post), « Ma semaine », le mois et ses posts
 * aux couleurs des statuts du Marketing (STATUS de src/lib/franchise/store.ts).
 * Chiffres : le rail et la barre, ceux lus au cockpit le 15/11/2026 à 09:57 (Halle) ; la note Google aussi ; les posts,
 * les abonnés et le rang sont des EXEMPLES (italique pointillé). */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = '../../../public/';

const I = {
  tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
  cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  calEd: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
  offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
  analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
  brochures: 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
  personnes: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9',
  formulaires: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  campagnes: 'M3 11l14-7v16L3 13zM7 14v5',
  retour: 'M19 12H5M12 19l-7-7 7-7',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
// Les deux icônes de la barre du dashboard en ligne : l'Assistant (iaIcone) et la revue du soir (rvLogo).
const IA_ICO = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.5h11A2.5 2.5 0 0 1 20 6v7.5a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4A2.5 2.5 0 0 1 4 13.5V6a2.5 2.5 0 0 1 2.5-2.5z"/><path d="M12 6.4l.95 2.35 2.35.95-2.35.95L12 13l-.95-2.35-2.35-.95 2.35-.95z" fill="currentColor" stroke-width="1"/></svg>';
const RV_ICO = (c, d) => `<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><rect x="9" y="8" width="26" height="34" rx="4" fill="none" stroke="${d}" stroke-width="3.2"/><rect x="16" y="5" width="12" height="6" rx="2" fill="${d}"/><path d="M14 20l3 3 5-6M14 31l3 3 5-6" fill="none" stroke="${d}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M25 21h5M25 32h5" stroke="${d}" stroke-width="3" stroke-linecap="round"/><circle cx="37" cy="37" r="9" fill="${c}"/><path d="M39.5 31.5a6 6 0 1 0 3 9 5 5 0 0 1-3-9z" fill="#fff"/></svg>`;
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;

/* ── Le rail : celui en ligne, plus le groupe Marketing ─────────────────────── */
const RAIL = [
  ['La journée', [['Opérationnel', 'tdb'], ['Tâches', 'controle'], ['Temps morts', 'journal']]],
  ['Les chiffres', [['Semaine', 'cal'], ['Mois', 'calEd'], ['Année', 'perf'], ['Trésorerie', 'fonds']]],
  ['Vendre', [['Produits', 'bundles'], ['Promotions', 'offres'], ['Primes', 'personnes'], ['Concurrence', 'analyse']]],
  ['Marketing', [['Calendrier des posts', 'calEd', true], ['Fidélité', 'etoile'], ['Mon catalogue', 'brochures']]],
  ['Assistants', [['Plan d’action', 'formulaires'], ['Campagne', 'campagnes']]],
];
const rail = `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
  <div class="sidebar__back"><a href="#">${ico(I.retour)}Cockpit › Résultat</a></div>
  <div class="rf-rep" style="margin-top:12px"><div class="rf-cartes">
    <button class="rf-carte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">36 %<small>de 2 480 €</small></div>
      <div class="rf-echelle"><i class="att" style="width:36%"></i><b style="left:100%;margin-left:-2px"></b></div><div class="s">898 € · trait : projection 2 547 €</div></button>
    <button class="rf-carte"><div class="k">Note Google<em>55 avis</em></div><div class="v">4,5${etoiles(4.5)}<small>cible 4,6</small></div>
      <div class="rf-echelle"><i class="att" style="width:50%"></i><b style="left:60%"></b></div></button>
  </div></div>
  <div class="sidebar__groups">${RAIL.map(([g, L]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${L.map(([l, i, on]) => `<button class="sidebar__item${on ? ' is-active' : ''}"${on ? ' aria-current="page"' : ''}>${ico(I[i])}<span class="sidebar__item-label">${l}</span></button>`).join('')}</div>`).join('')}</div>`;

/* ── La barre du haut : celle du dashboard en ligne ─────────────────────────── */
const barre = `<div class="mk-barre"><div class="db-vt"><div class="kk">Atelier by – Halle</div><h1>Calendrier des posts</h1></div>
  <span style="flex:1"></span><button class="btn">↻ Relire</button>
  <span class="rf-avs"><button class="fn-vignette"><span class="k">Valeur du magasin</span><span class="v">102 172 €</span></button>
    <button class="rf-av"><span class="rf-av__o">${IA_ICO}</span><span><span class="rf-av__k">Coach</span><span class="rf-av__v">assistant</span></span></button>
    <button class="rf-av"><span class="rf-av__o">${RV_ICO('#8D1D2C', '#5c5852')}</span><span><span class="rf-av__k">Revue du soir</span><span class="rf-av__v">à 18 h</span></span></button></span></div>`;

/* ── Le cadre : l'espace boutique du Marketing, incorporé ───────────────────── */
const STATUS = { 'À valider': ['#F6E7CE', '#7A5207'], Programmé: ['#F2E2CE', '#6b4420'], Publié: ['#E2F0E8', '#3f7a52'], Refusé: ['#F6DCDF', '#8D1D2C'], Échec: ['#8D1D2C', '#FFFFFF'], Brouillon: ['#EDE7DE', '#5c5852'] };
// Exemples : [jour ISO, heure, titre, statut, réseaux]
const POSTS = [
  ['2026-10-27', '09:00', 'Les tartes d’automne', 'Publié', 'FB · IG'],
  ['2026-10-31', '10:00', 'Halloween : les biscuits', 'Publié', 'IG'],
  ['2026-11-01', '08:30', 'Toussaint : ouvert de 7 h à 13 h', 'Publié', 'FB'],
  ['2026-11-04', '09:00', 'Nouveau : le pain aux noix', 'Publié', 'FB · IG'],
  ['2026-11-08', '08:00', 'Le brunch du dimanche', 'Publié', 'FB · IG'],
  ['2026-11-11', '08:30', '11 novembre : nos horaires', 'Publié', 'FB'],
  ['2026-11-12', '12:00', '−20 % sur les quiches', 'Refusé', 'IG'],
  ['2026-11-15', '09:00', 'Croissants du dimanche', 'Publié', 'FB · IG'],
  ['2026-11-17', '11:00', 'Le menu midi de la semaine', 'Programmé', 'FB'],
  ['2026-11-19', '10:00', 'Cougnous : précommandes', 'À valider', 'FB · IG'],
  ['2026-11-22', '08:00', 'Le brunch du dimanche', 'Programmé', 'FB · IG'],
  ['2026-11-26', '', 'Idée : la semaine des soupes', 'Brouillon', 'IG'],
  ['2026-11-29', '09:30', 'Saint-Nicolas approche', 'Programmé', 'IG'],
  ['2026-12-03', '10:00', 'Bûches : réservations ouvertes', 'À valider', 'FB · IG'],
  ['2026-12-06', '08:00', 'Saint-Nicolas : les cougnous', 'Programmé', 'FB · IG'],
];
const iso = d => d.toISOString().slice(0, 10);
const cases = [];
for (let d = new Date('2026-10-26T12:00:00Z'); iso(d) <= '2026-12-06'; d.setUTCDate(d.getUTCDate() + 1)) { cases.push(iso(d)); }
const grille = `<div class="mk-mois"><div class="mk-jours">${['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'].map(j => `<span>${j}</span>`).join('')}</div>
  <div class="mk-cases">${cases.map(j => {
    const dans = j.slice(5, 7) === '11', auj = j === '2026-11-15';
    const ps = POSTS.filter(p => p[0] === j);
    return `<div class="mk-case${dans ? '' : ' hors'}${auj ? ' auj' : ''}"><span class="n">${+j.slice(8)}</span>${ps.map(([, h, t, st, rs]) => `<span class="mk-post" style="background:${STATUS[st][0]};color:${STATUS[st][1]}"><span class="ex">${h ? h + ' · ' : ''}${t}</span><small>${st} · ${rs}</small></span>`).join('')}</div>`;
  }).join('')}</div></div>`;
const tuile = (lab, val, sub, bon) => `<div class="mk-tuile"><span class="lab">${lab}</span><span class="val${bon ? ' bon' : ''}">${val}</span><span class="sub">${sub}</span></div>`;
const cadre = `<div class="mk-cadre">
  <div class="mk-onglets seg"><button class="is-on">Calendrier</button><button>Vues et abonnés</button><button>Suivi de ma boutique</button></div>
  <div class="mk-tete"><div><span class="mk-eyebrow">Calendrier Meta · L’Atelier By Halle · Facebook et Instagram</span><h2>Novembre 2026</h2></div>
    <div class="mk-outils"><div class="seg"><button class="is-on">Mois</button><button>Semaine</button><button>Liste</button></div>
      <button class="btn btn--sm btn--icon">‹</button><button class="btn btn--sm">Aujourd’hui</button><button class="btn btn--sm btn--icon">›</button>
      <button class="btn btn--primary">Publier un post</button></div></div>
  <section class="mk-semaine"><div class="mk-st"><span>Ma semaine · du 9 au 15 novembre</span><span class="mk-rang ex">3<sup>e</sup> sur 4 boutiques</span></div>
    <div class="mk-tuiles">${tuile('Régularité', '<span class="ex">3 posts</span>', 'objectif : 3 par semaine', true)}${tuile('À traiter', '<span class="ex">2</span>', 'commentaires sans réponse')}${tuile('Avis Google', '4,5 ★', '55 avis · cible 4,6')}${tuile('Abonnés', '<span class="ex">1 284</span>', '<span class="ex">+ 12</span> cette semaine', true)}</div>
    <span class="mk-conseil">Commencez par répondre : <span class="ex">2 commentaires</span> vous attendent.</span></section>
  ${grille}
  <div class="mk-legende">${Object.keys(STATUS).map(s => `<span><i style="background:${STATUS[s][0]};border-color:${STATUS[s][1]}"></i>${s}</span>`).join('')}</div>
</div>`;

const html = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard magasin — le calendrier des posts dans le rail (maquette)</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css">
<link rel="stylesheet" href="final.css">
<link rel="stylesheet" href="marketing.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail}
</nav>
<div class="workspace__main mk-main">${barre}
  <p class="mk-note"><span class="rf-pill">Maquette · le cadre montre l’espace boutique du Marketing (/marketing/franchise/calendrier?shop=4&amp;embed=1) · posts, abonnés et rang : exemples</span></p>
  ${cadre}
</div>
</div>
</body></html>
`;
fs.writeFileSync(path.join(D, 'marketing.html'), html);
console.log('marketing.html');
