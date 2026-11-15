/* Ce que les maquettes de la boîte mail et des offres partagent : les tracés, le rail en ligne (avec la Boîte mail au-dessus
 * et Offres dans Vendre), la barre du haut, la page. Le rail et la barre portent les chiffres lus le 15/11/2026. */
const fs = require('fs');
const path = require('path');
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
  // Ajouts (signalés au LISEZMOI) : l'enveloppe (boîte mail) et la pièce (offre de prix, le tracé « Modèles » du §8.4).
  mail: 'M3 5h18v14H3zM3 6l9 7 9-7',
  offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  ecrire: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  trombone: 'M21 11l-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7',
  chercher: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2',
  envoyer: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
  pdf: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M12 18v-6M9 15l3 3 3-3',
  panier: 'M6 6h15l-1.5 9h-12zM6 6L5 3H2M9 20a1 1 0 1 0 0-.01M18 20a1 1 0 1 0 0-.01',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
const IA = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 3.5h11A2.5 2.5 0 0 1 20 6v7.5a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4A2.5 2.5 0 0 1 4 13.5V6a2.5 2.5 0 0 1 2.5-2.5z"/><path d="M12 6.4l.95 2.35 2.35.95-2.35.95L12 13l-.95-2.35-2.35-.95 2.35-.95z" fill="currentColor" stroke-width="1"/></svg>';
const IA_ICO = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.5h11A2.5 2.5 0 0 1 20 6v7.5a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4A2.5 2.5 0 0 1 4 13.5V6a2.5 2.5 0 0 1 2.5-2.5z"/><path d="M12 6.4l.95 2.35 2.35.95-2.35.95L12 13l-.95-2.35-2.35-.95 2.35-.95z" fill="currentColor" stroke-width="1"/></svg>';
const RV_ICO = '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><rect x="9" y="8" width="26" height="34" rx="4" fill="none" stroke="#5c5852" stroke-width="3.2"/><rect x="16" y="5" width="12" height="6" rx="2" fill="#5c5852"/><path d="M14 20l3 3 5-6M14 31l3 3 5-6" fill="none" stroke="#5c5852" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M25 21h5M25 32h5" stroke="#5c5852" stroke-width="3" stroke-linecap="round"/><circle cx="37" cy="37" r="9" fill="#8D1D2C"/><path d="M39.5 31.5a6 6 0 1 0 3 9 5 5 0 0 1-3-9z" fill="#fff"/></svg>';
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;
const ex = t => `<span class="ex">${t}</span>`;
const eur = n => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/[  ]/g, ' ') + ' €';

/* Le rail en ligne (15/11/2026), sans « Cockpit › Résultat », avec la Boîte mail au-dessus et Offres dans Vendre. */
function rail(actif) {
  const G = [
    ['La journée', [['Opérationnel', 'tdb'], ['Tâches', 'controle'], ['Temps morts', 'journal']]],
    ['Les chiffres', [['Semaine', 'cal'], ['Mois', 'calEd'], ['Année', 'perf'], ['Trésorerie', 'fonds']]],
    ['Vendre', [['Produits', 'bundles'], ['Promotions', 'offres'], ['Offres', 'offre', '3'], ['Primes', 'personnes'], ['Concurrence', 'analyse']]],
    ['Marketing', [['Calendrier des posts', 'calEd', '', '↗'], ['Fidélité', 'etoile'], ['Mon catalogue', 'brochures']]],
  ];
  const it = (l, i, n, ext) => `<button class="sidebar__item${l === actif ? ' is-active' : ''}"${l === actif ? ' aria-current="page"' : ''}>${ico(I[i])}<span class="sidebar__item-label">${l}</span>${n ? `<span class="rf-etat">${ex(n)}</span>` : ''}${ext ? `<span class="rf-ext">${ext}</span>` : ''}</button>`;
  return `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
  <div class="rf-rep" style="margin-top:8px"><div class="rf-cartes">
    <button class="rf-carte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">36 %<small>de 2 480 €</small></div>
      <div class="rf-echelle"><i class="att" style="width:36%"></i><b style="left:100%;margin-left:-2px"></b></div><div class="s">898 € · trait : projection 2 547 €</div></button>
    <button class="rf-carte"><div class="k">Note Google<em>55 avis</em></div><div class="v">4,5${etoiles(4.5)}<small>cible 4,6</small></div>
      <div class="rf-echelle"><i class="att" style="width:50%"></i><b style="left:60%"></b></div></button>
  </div></div>
  <div class="sidebar__groups"><div class="ml-rail">${it('Boîte mail', 'mail', '4')}</div>
    ${G.map(([g, L]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${L.map(x => it(...x)).join('')}</div>`).join('')}</div>`;
}
const barre = titre => `<div class="mk-barre"><div class="db-vt"><div class="kk">Atelier by – Halle</div><h1>${titre}</h1></div>
  <span style="flex:1"></span><button class="btn">↻ Relire</button>
  <span class="rf-avs"><button class="fn-vignette"><span class="k">Valeur du magasin</span><span class="v">102 172 €</span></button>
    <button class="rf-av"><span class="rf-av__o">${IA_ICO}</span><span><span class="rf-av__k">Coach</span><span class="rf-av__v">assistant</span></span></button>
    <button class="rf-av"><span class="rf-av__o">${RV_ICO}</span><span><span class="rf-av__k">Revue du soir</span><span class="rf-av__v">à 18 h</span></span></button></span></div>`;
function page(dossier, nom, titreDoc, actif, titre, note, corps) {
  fs.writeFileSync(path.join(dossier, nom + '.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard magasin — ${titreDoc} (maquette)</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css"><link rel="stylesheet" href="final.css"><link rel="stylesheet" href="marketing.css"><link rel="stylesheet" href="reseaux.css"><link rel="stylesheet" href="mail.css"><link rel="stylesheet" href="offres.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail(actif)}
</nav>
<div class="workspace__main mk-main">${barre(titre)}
  <p class="mk-note"><span class="rf-pill">${note}</span></p>
  <div class="rs-page">${corps}</div>
</div>
</div>
</body></html>
`);
}
module.exports = { R, I, ico, IA, ex, eur, page };
