/* Maquette « Le vrai dashboard, avec un rail à côté » (demande du 15/11/2026 : « ne pas changer ce qui était dans le
 * dashboard, je veux juste un rail à côté »).
 *
 *   node docs/maquettes/rail-franchise/generer-reel.js   → reel.html
 *   node docs/maquettes/rail-franchise/capturer.js       → reel.png (avec les autres écrans)
 *
 * La page charge le VRAI code du dashboard (public/dashboard/dashboard.js et dashboard.css, sans une ligne changée) dans
 * son conteneur #dash, à droite du rail. Seuls l'en-tête du dashboard (logo, nom du magasin) et sa rangée d'onglets
 * sont masqués : le rail les remplace, entrée pour entrée, et la barre du haut reprend la date, « Relire », la valeur en
 * vignette, le coach (l'assistant IA) et la revue du soir. Tout le reste est le dashboard tel qu'il est.
 * Ici, l'API du cockpit n'est pas lue (le serveur de production n'est pas interrogé depuis la maquette) : les blocs
 * restent sur « lecture… ». Les chiffres du rail et de la barre sont des exemples. */
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
  bilan: 'M3 3v18h18M7 15l4-4 3 3 5-6',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
  offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
  fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  revue: 'M9 3h6v3H9zM9 4.5H6a2 2 0 0 0-2 2V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6.5a2 2 0 0 0-2-2h-3M15.5 15.5a3.2 3.2 0 1 1-3.7-4.4 2.6 2.6 0 0 0 3.7 4.4z',
  formulaires: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  campagnes: 'M3 11l14-7v16L3 13zM7 14v5',
  commentaires: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h8M8 13h5',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
  retour: 'M19 12H5M12 19l-7-7 7-7',
  personne: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 21v-1a7 7 0 0 1 14 0v1',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
const entree = ([lib, d, etat, on]) => `<a class="sidebar__item${on ? ' is-active' : ''}" href="#"${on ? ' aria-current="page"' : ''}>${ico(d)}<span class="sidebar__item-label">${lib}</span>${etat ? `<span class="rf-etat">${etat}</span>` : ''}</a>`;
const groupe = (lib, items) => `<div class="sidebar__group"><div class="sidebar__group-label">${lib}</div>${items.map(entree).join('')}</div>`;
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;

/* Le rail : en haut, l'objectif du jour et la note Google (B) ; puis les onglets du dashboard d'aujourd'hui, les mêmes,
 * dans le même ordre, rangés en groupes — aucun écran n'est fusionné ni ajouté, sauf les assistants (le plan d'action et
 * la campagne, qui sont déjà des vues du dashboard sans onglet, et la réclamation fournisseur du téléphone). */
const rail = `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard · Halle</div></div>
  <div class="sidebar__back"><a href="#">${ico(I.retour)}Retour à l’ERP</a></div>
  <div class="rf-rep">
    <div class="rf-cartes">
      <button class="rf-carte"><div class="k">Objectif du jour<em class="ex">à 13 h 05</em></div><div class="v"><span class="ex">68 %</span><small class="ex">de 2 380 €</small></div>
        <div class="rf-echelle"><i style="width:68%"></i><b style="left:64%"></b></div></button>
      <button class="rf-carte"><div class="k">Note Google<em class="ex">287 avis</em></div><div class="v"><span class="ex">4,6</span>${etoiles(4.6)}<small>cible 4,5</small></div></button>
    </div></div>
  <div class="sidebar__groups">
    ${groupe('La journée', [['Opérationnel', I.tdb, '', true], ['Tâches', I.controle, '<span class="ex">4</span>'], ['Temps morts', I.journal]])}
    ${groupe('Les chiffres', [['Semaine', I.cal], ['Mois', I.calEd], ['Trimestre', I.bilan], ['Année', I.perf]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Concurrence', I.analyse], ['Primes', I.fonds]])}
    ${groupe('Assistants', [['Plan d’action', I.formulaires], ['Campagne', I.campagnes], ['Réclamation fournisseur', I.commentaires]])}
  </div>`;

const avatar = (d, k, v, cl) => `<button class="rf-av${cl ? ' ' + cl : ''}"><span class="rf-av__o">${ico(d)}</span><span><span class="rf-av__k">${k}</span><span class="rf-av__v">${v}</span></span></button>`;
const topbar = `<header class="topbar"><div><p class="topbar__kicker">Vue Franchisé</p><h1>Opérationnel</h1></div>
  <div class="topbar__actions">
    <button class="btn btn--icon" aria-label="Jour précédent">‹</button><span class="fn-date">dimanche 15 novembre</span><button class="btn btn--icon" aria-label="Jour suivant">›</button>
    <button class="btn">↻ Relire</button>
    <div class="rf-avs">
      <button class="fn-vignette"><span class="k">Valeur du magasin</span><span class="v ex">100 328 €</span></button>
      ${avatar(I.personne, 'Coach', 'assistant IA')}
      ${avatar(I.revue, 'Revue du soir', '<span class="ex">à faire</span>', 'vif')}
    </div></div></header>`;

const html = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard magasin — le vrai, avec un rail à côté (maquette)</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="${R}assets/css/controles-photo.css">
<link rel="stylesheet" href="${R}dashboard/dashboard.css">
<link rel="stylesheet" href="rf.css">
<link rel="stylesheet" href="final.css">
<style>
  /* Le dashboard tel qu'il est, dans la colonne de droite. Seuls son en-tête et sa rangée d'onglets sont masqués :
     le rail et la barre du haut les remplacent. Rien d'autre n'est touché. */
  html, body { height: 100%; }
  .rl-main { flex: 1; overflow-y: auto; background: var(--paper); }
  .rl-main #dash { max-width: none; padding: 16px 24px 40px; }
  .rl-main #dash > .db-hd, .rl-main #dash > .db-nav { display: none; }
  .rf-rep { margin-top: 6px; }
</style>
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail}
</nav>
<div class="workspace__main">${topbar}
<main class="rl-main"><div id="dash"><div class="db-attente">Lecture du magasin…</div></div></main>
</div>
</div>
<script src="${R}assets/js/visites.js"></script>
<script src="${R}assets/js/cartes-magasin.js"></script>
<script src="${R}dashboard/dashboard.js"></script>
</body></html>
`;
fs.writeFileSync(path.join(D, 'reel.html'), html);
console.log('reel.html');
