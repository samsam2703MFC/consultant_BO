/* Maquette « Le rail du dashboard franchisé » (demande du 15/11/2026 : « propose-moi un rail pour le dashboard
 * franchisé, trois propositions, avec les assistants, la revue… » ; puis : « la partie du haut doit reprendre la valeur,
 * la note Google… »).
 *
 *   node docs/maquettes/rail-franchise/generer.js    → a.html, b.html, c.html, planche.html
 *   node docs/maquettes/rail-franchise/capturer.js   → a.png, b.png, c.png, planche.jpg
 *
 * Le rail remplace l'en-tête et la rangée de onze onglets du dashboard magasin au bureau (public/dashboard). Il suit le
 * §8 du design system commun (docs/DESIGN_SYSTEM.md) : 246 px, blanc, trait 400, seule l'entrée active en gras.
 * Les entrées sont celles du dashboard d'aujourd'hui (S.vue dans dashboard.js) ; les assistants, ceux qu'il a déjà :
 * la revue du soir (/soir/revue), le plan d'action et la campagne (module des visites, vues actions et campagne),
 * la réclamation fournisseur (au téléphone seulement aujourd'hui), la note du jour (/exploitation/notes).
 * Les chiffres du rail sont des EXEMPLES (italique pointillé) : la maquette ne les a pas lus. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = '../../../public/';

/* Les tracés : le catalogue du §8.4, sauf la revue du soir (la planchette et la lune du logo L2, redessinées au trait :
 * un ajout, signalé dans le LISEZMOI). */
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
  ecrire: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
  modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  retour: 'M19 12H5M12 19l-7-7 7-7',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;

/* Une entrée : [libellé, tracé, compteur ou état, active ?, classe de l'état]. */
const entree = ([lib, d, etat, on, cl]) => `<a class="sidebar__item${on ? ' is-active' : ''}" href="#"${on ? ' aria-current="page"' : ''}>${ico(d)}<span class="sidebar__item-label">${lib}</span>${etat ? `<span class="${cl || 'rf-etat'}">${etat}</span>` : ''}</a>`;
const groupe = (lib, items) => `<div class="sidebar__group"><div class="sidebar__group-label">${lib}</div>${items.map(entree).join('')}</div>`;

const tete = kicker => `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">${kicker}</div></div>
  <div class="sidebar__back"><a href="#">${ico(I.retour)}Retour à l’ERP</a></div>`;
const enseigne = `<div class="sidebar__brand">Atelier by – Halle</div>`;

/* Les cinq étoiles de la note : pleines jusqu'à la note entière, la dernière au trait. */
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;
/* La courbe des six trimestres de la valeur (le tiroir « Valeur » du dashboard montre les mêmes six). */
function courbe(vals) {
  const w = 200, h = 26, lo = Math.min(...vals), hi = Math.max(...vals);
  const pts = vals.map((v, i) => [4 + i * (w - 8) / (vals.length - 1), h - 4 - (v - lo) / (hi - lo || 1) * (h - 8)]);
  const last = pts[pts.length - 1];
  return `<svg class="rf-courbe" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><polyline points="${pts.map(p => p.map(x => x.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="#5c5852" stroke-width="1.5" vector-effect="non-scaling-stroke"/><circle cx="${last[0].toFixed(1)}" cy="${last[1].toFixed(1)}" r="2.6" fill="#8D1D2C"/></svg>`;
}

/* ── Le contenu à droite : la barre supérieure, puis la page esquissée (elle ne change pas) ── */
const JOURS = [['L 9', 'pas de vente'], ['Ma 10', ''], ['Me 11', ''], ['J 12', ''], ['V 13', ''], ['S 14', ''], ['D 15', 'en cours']];
function contenu(titre, avant) {
  return `<header class="topbar"><div><p class="topbar__kicker">Vue Franchisé</p><h1>${titre}</h1></div>
    <div class="topbar__actions"><span class="rf-pill">Maquette · contenu inchangé · chiffres du rail : exemples</span>
      <button class="btn btn--icon">‹</button><input class="btn" style="font:inherit;font-size:13px;width:118px" value="15/11/2026"><button class="btn btn--icon">›</button><button class="btn">↻ Relire</button></div></header>
  <main class="app__body"><div class="rf-esq">${avant || ''}
    <div class="rf-frise">${JOURS.map(([j, s], i) => `<div class="rf-jour${i === 6 ? ' on' : ''}">${j}<small>${s || '<span class="rf-sk" style="display:block;width:70%;margin:4px 0 0"></span>'}</small></div>`).join('')}</div>
    <div class="rf-tuiles">${['Ventes', 'Objectif du jour', 'Vitrine', 'Équipe en poste'].map(t => `<div class="rf-bloc"><h2>${t}</h2><div class="rf-sk" style="width:45%;height:20px"></div><div class="rf-sk" style="width:85%"></div></div>`).join('')}</div>
    <div class="rf-deux"><div class="rf-bloc"><h2>Ventes par catégorie</h2>${[92, 80, 86, 70, 76, 62, 74].map(w => `<div class="rf-sk" style="width:${w}%"></div>`).join('')}</div>
      <div class="rf-bloc"><h2>Le P&amp;L court de la journée</h2>${[88, 72, 80, 66, 84, 60].map(w => `<div class="rf-sk" style="width:${w}%"></div>`).join('')}</div></div>
    <div class="rf-bloc"><h2>La journée</h2>${[96, 90, 94].map(w => `<div class="rf-sk" style="width:${w}%"></div>`).join('')}</div>
  </div></main>`;
}
function page(lettre, titreDoc, rail, titre, avant) {
  return `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rail du dashboard franchisé — ${titreDoc} (maquette ${lettre.toUpperCase()})</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail}
</nav>
<div class="workspace__main">${contenu(titre, avant)}</div>
</div>
</body></html>
`;
}

/* ══ A — Les repères en liste : le rail du Marketing, les onze onglets rangés, les assistants en bas ══════════════════ */
const railA = `${tete('Dashboard magasin')}${enseigne}
  <div class="rf-rep"><div class="rf-rep__t">Repères<small>au 15/11</small></div>
    <div class="rf-liste">
      <button class="rf-ligne"><span class="k"><i class="rf-dot mu"></i>Valeur</span><span class="v ex">100 328 €</span><span class="s">deux mois de chiffre d’affaires</span></button>
      <button class="rf-ligne"><span class="k"><i class="rf-dot ok"></i>Note Google</span><span class="v ex">4,6 ★</span><span class="s ex">287 avis · cible 4,5</span></button>
      <button class="rf-ligne"><span class="k"><i class="rf-dot att"></i>Objectif du mois</span><span class="v ex">92 %</span><span class="s ex">30 950 € sur 33 640 € attendus</span></button>
      <button class="rf-ligne"><span class="k"><i class="rf-dot ok"></i>Résultat du mois</span><span class="v ex">+ 3 980 €</span></button>
      <button class="rf-ligne"><span class="k"><i class="rf-dot mu"></i>Place au réseau</span><span class="v ex">3<sup>e</sup> sur 9</span></button>
    </div></div>
  <div class="sidebar__groups">
    ${groupe('La journée', [['Opérationnel', I.tdb, '', true], ['Tâches', I.controle, '<span class="ex">4</span>'], ['Temps morts', I.journal]])}
    ${groupe('Les chiffres', [['Semaine', I.cal], ['Mois', I.calEd], ['Trimestre', I.bilan], ['Année', I.perf]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Concurrence', I.analyse], ['Primes', I.fonds]])}
    ${groupe('Assistants', [['Revue du soir', I.revue, '<span class="ex">à faire</span>'], ['Plan d’action', I.formulaires, '<span class="ex">3</span>'], ['Campagne', I.campagnes], ['Réclamation fournisseur', I.commentaires]])}
  </div>`;

/* ══ B — Le tableau de bord dans le rail : un repère, une forme ; Résultat réunit les quatre périodes ═══════════════ */
const railB = `${tete('Dashboard magasin')}
  <div class="rf-rep"><div class="rf-rep__t">Atelier by – Halle<small>au 15/11</small></div>
    <div class="rf-cartes">
      <button class="rf-carte"><div class="k">Valeur<em>six trimestres</em></div><div class="v ex">100 328 €</div>${courbe([94.1, 95.8, 97.2, 98.0, 99.6, 100.3])}<div class="s"><span class="ex">+ 2,4 %</span> en trois mois</div></button>
      <button class="rf-carte"><div class="k">Note Google<em class="ex">287 avis</em></div><div class="v"><span class="ex">4,6</span>${etoiles(4.6)}<small class="ex">+ 9 ce mois</small></div>
        <div class="rf-echelle"><i style="width:60%"></i><b style="left:50%"></b></div><div class="rf-echelle-l"><span>4,0</span><span>cible 4,5</span><span>5,0</span></div></button>
      <button class="rf-carte"><div class="k">Objectif du mois<em>au 15/11</em></div><div class="v"><span class="ex">92 %</span><small class="ex">− 2 690 €</small></div>
        <div class="rf-echelle"><i class="att" style="width:92%"></i><b style="left:100%;margin-left:-2px"></b></div><div class="s ex">résultat du mois + 3 980 €</div></button>
    </div></div>
  <div class="sidebar__groups">
    ${groupe('Le magasin', [['Opérationnel', I.tdb], ['Tâches', I.controle, '<span class="ex">4</span>'], ['Résultat', I.perf, '', true], ['Temps morts', I.journal]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Concurrence', I.analyse], ['Primes', I.fonds]])}
    ${groupe('Assistants', [['Revue du soir', I.revue, '<span class="ex">à faire</span>'], ['Plan d’action', I.formulaires, '<span class="ex">3</span>'], ['Campagne', I.campagnes], ['Réclamation fournisseur', I.commentaires]])}
  </div>`;
const ongletsB = `<div class="page-tabs" style="margin:0">${['Semaine', 'Mois', 'Trimestre', 'Année'].map(o => `<button class="page-tabs__tab${o === 'Mois' ? ' is-on' : ''}">${o}</button>`).join('')}</div>`;

/* ══ C — Les assistants d'abord : quatre cases, la revue du soir, puis ce qu'il reste à faire ═══════════════════════ */
const railC = `${tete('Dashboard magasin')}${enseigne}
  <div class="rf-rep"><div class="rf-grille">
      <button class="rf-case"><div class="k">Valeur</div><div class="v ex">100,3 k€</div><div class="s"><span class="ex">+ 2,4 %</span> en 3 mois</div></button>
      <button class="rf-case"><div class="k"><i class="rf-dot ok"></i>Google</div><div class="v ex">4,6 ★</div><div class="s">cible 4,5</div></button>
      <button class="rf-case"><div class="k"><i class="rf-dot att"></i>Objectif</div><div class="v ex">92 %</div><div class="s ex">du mois, au 15/11</div></button>
      <button class="rf-case"><div class="k"><i class="rf-dot ok"></i>Résultat</div><div class="v ex">+ 3 980 €</div><div class="s">du mois</div></button>
  </div></div>
  <div class="rf-revue"><div class="rf-revue__h">${ico(I.revue)}Revue du soir<span class="ex">à faire</span></div>
    <div class="rf-revue__s"><span class="ex">3 checklists et 4 points de gestion</span>, une question à la fois.</div>
    <a class="sidebar__action" href="#">Commencer la revue</a></div>
  <div class="sidebar__groups">
    ${groupe('À faire', [['Tâches du jour', I.controle, '<span class="ex">4</span>'], ['Plan d’action', I.formulaires, '<span class="ex">3</span>'], ['Note du jour', I.ecrire], ['Réclamation fournisseur', I.commentaires]])}
    ${groupe('Le magasin', [['Opérationnel', I.tdb, '', true], ['Résultat', I.perf], ['Temps morts', I.journal]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Campagne', I.campagnes], ['Concurrence', I.analyse], ['Primes', I.fonds]])}
  </div>`;

fs.writeFileSync(path.join(D, 'a.html'), page('a', 'les repères en liste', railA, 'Opérationnel'));
fs.writeFileSync(path.join(D, 'b.html'), page('b', 'le tableau de bord dans le rail', railB, 'Résultat', ongletsB));
fs.writeFileSync(path.join(D, 'c.html'), page('c', 'les assistants d’abord', railC, 'Opérationnel'));

/* La planche : les trois captures côte à côte. */
const PL = [['a', 'A — Les repères en liste', 'le rail du Marketing tel quel ; les onze onglets rangés en trois groupes, les assistants en bas'],
  ['b', 'B — Le tableau de bord dans le rail', 'chaque repère a sa forme ; Résultat réunit Semaine, Mois, Trimestre et Année en onglets'],
  ['c', 'C — Les assistants d’abord', 'quatre cases, la revue du soir en tête, puis ce qu’il reste à faire']];
fs.writeFileSync(path.join(D, 'planche.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Rail du dashboard franchisé — planche</title>
<link rel="stylesheet" href="${R}assets/ds/global.css"><link rel="stylesheet" href="rf.css"></head>
<body><div class="rf-planche">${PL.map(([l, t, s]) => `<figure><figcaption>${t}<small>${s}</small></figcaption><img src="${l}.png" alt="${t}"></figure>`).join('')}</div></body></html>
`);
console.log('a.html, b.html, c.html, planche.html');
