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
  euro: 'M17.5 6.5A7 7 0 1 0 17.5 17.5M4 10h10M4 14h10',
  personne: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 21v-1a7 7 0 0 1 14 0v1',
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
function contenu(titre, avant, avatars) {
  const pill = '<span class="rf-pill">Maquette · contenu inchangé · chiffres du rail : exemples</span>';
  return `<header class="topbar"><div><p class="topbar__kicker">Vue Franchisé</p><h1>${titre}</h1></div>
    <div class="topbar__actions">${avatars ? '' : pill}
      <button class="btn btn--icon">‹</button><input class="btn" style="font:inherit;font-size:13px;width:118px" value="15/11/2026"><button class="btn btn--icon">›</button><button class="btn">↻ Relire</button>${avatars || ''}</div></header>
  <main class="app__body"><div class="rf-esq">${avatars ? `<div>${pill.replace('chiffres du rail', 'chiffres du rail et des avatars')}</div>` : ''}${avant || ''}
    <div class="rf-frise">${JOURS.map(([j, s], i) => `<div class="rf-jour${i === 6 ? ' on' : ''}">${j}<small>${s || '<span class="rf-sk" style="display:block;width:70%;margin:4px 0 0"></span>'}</small></div>`).join('')}</div>
    <div class="rf-tuiles">${['Ventes', 'Objectif du jour', 'Vitrine', 'Équipe en poste'].map(t => `<div class="rf-bloc"><h2>${t}</h2><div class="rf-sk" style="width:45%;height:20px"></div><div class="rf-sk" style="width:85%"></div></div>`).join('')}</div>
    <div class="rf-deux"><div class="rf-bloc"><h2>Ventes par catégorie</h2>${[92, 80, 86, 70, 76, 62, 74].map(w => `<div class="rf-sk" style="width:${w}%"></div>`).join('')}</div>
      <div class="rf-bloc"><h2>Le P&amp;L court de la journée</h2>${[88, 72, 80, 66, 84, 60].map(w => `<div class="rf-sk" style="width:${w}%"></div>`).join('')}</div></div>
    <div class="rf-bloc"><h2>La journée</h2>${[96, 90, 94].map(w => `<div class="rf-sk" style="width:${w}%"></div>`).join('')}</div>
  </div></main>`;
}
function page(lettre, titreDoc, rail, titre, avant, avatars, tiroir) {
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
<div class="workspace__main">${contenu(titre, avant, avatars)}${tiroir || ''}</div>
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

/* ══ B — Le tableau de bord dans le rail : un repère, une forme ; Résultat réunit les quatre périodes ═══════════════
 * Retouche du 15/11/2026 : « le premier B avec l'objectif du jour ; les objectifs de la semaine et de l'année ne se
 * voient que sur les onglets Semaine, Mois et Année ». La carte de l'objectif suit l'écran ouvert : l'objectif du jour
 * partout (Opérationnel, Tâches, Produits…), celui de la période dans Résultat › Semaine, Mois, Trimestre, Année.
 *   Jour : le CA de la journée face à son objectif entier ; le trait, J−7 à la même heure (comme la tuile « Objectif du
 *   jour » de l'Opérationnel). Période : le CA face à l'objectif attendu à ce jour (réparti par la pondération réseau) ;
 *   le trait, 100 %. Chiffres : exemples. */
const OBJ = {
  jour: { lib: 'Objectif du jour', quand: 'à 13 h 05', pct: 68, ecart: 'de 2 380 €', larg: 68, trait: 64,
    s: 'reste <span class="ex">770 €</span> · J−7 à cette heure : <span class="ex">64 %</span>' },
  semaine: { lib: 'Objectif semaine', quand: '9 → 15/11', pct: 88, ecart: '− 1 430 €', larg: 88, trait: 100,
    s: 'résultat de la semaine <span class="ex">+ 1 120 €</span>' },
  mois: { lib: 'Objectif du mois', quand: 'au 15/11', pct: 92, ecart: '− 2 690 €', larg: 92, trait: 100,
    s: 'résultat du mois <span class="ex">+ 3 980 €</span>' },
  trimestre: { lib: 'Objectif T4', quand: 'au 15/11', pct: 96, ecart: '− 2 140 €', larg: 96, trait: 100,
    s: 'résultat du trimestre <span class="ex">+ 7 410 €</span>' },
  annee: { lib: 'Objectif 2026', quand: 'au 15/11', pct: 97, ecart: '− 9 870 €', larg: 97, trait: 100,
    s: 'résultat de l’année <span class="ex">+ 31 200 €</span>' },
};
function carteObjectif(per) {
  const o = OBJ[per], ok = o.pct >= o.trait;
  return `<button class="rf-carte"><div class="k">${o.lib}<em>${o.quand}</em></div><div class="v"><span class="ex">${o.pct} %</span><small class="ex">${o.ecart}</small></div>
        <div class="rf-echelle"><i class="${ok ? '' : 'att'}" style="width:${o.larg}%"></i><b style="left:${o.trait}%;${o.trait >= 100 ? 'margin-left:-2px' : ''}"></b></div><div class="s">${o.s}</div></button>`;
}
const railB = per => `${tete('Dashboard magasin')}
  <div class="rf-rep"><div class="rf-rep__t">Atelier by – Halle<small>au 15/11</small></div>
    <div class="rf-cartes">
      <button class="rf-carte"><div class="k">Note Google<em class="ex">287 avis</em></div><div class="v"><span class="ex">4,6</span>${etoiles(4.6)}<small class="ex">+ 9 ce mois</small></div>
        <div class="rf-echelle"><i style="width:60%"></i><b style="left:50%"></b></div><div class="rf-echelle-l"><span>4,0</span><span>cible 4,5</span><span>5,0</span></div></button>
      ${carteObjectif(per)}
    </div></div>
  <div class="sidebar__groups">
    ${groupe('Le magasin', [['Opérationnel', I.tdb, '', per === 'jour'], ['Tâches', I.controle, '<span class="ex">4</span>'], ['Résultat', I.perf, '', per !== 'jour'], ['Temps morts', I.journal]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Concurrence', I.analyse], ['Primes', I.fonds]])}
    ${groupe('Assistants', [['Plan d’action', I.formulaires, '<span class="ex">3</span>'], ['Campagne', I.campagnes], ['Réclamation fournisseur', I.commentaires]])}
  </div>`;
/* Les trois avatars du haut à droite (retouche du 15/11/2026 : « la valeur doit être en haut à droite avec l'avatar
 * coach et l'avatar revue du jour ; les trois avatars de taille et de forme identiques ») : un cercle de 36 px, le même
 * pour les trois, une icône au trait ; à côté, ce qu'il dit. Bordeaux quand il attend un geste (la revue à faire).
 * Un clic ouvre : la valeur, son tiroir (la formule, six trimestres) ; le coach, l'assistant IA ; la revue, l'assistant
 * du soir, une question à la fois.
 * « Le coach, c'est l'assistant IA » (15/11/2026) : une conversation dans un tiroir à droite, sous la barre. Il lit les
 * chiffres du dashboard (les mêmes lectures), cite d'où vient chaque chiffre, PROPOSE et n'écrit rien : un geste qu'il
 * suggère ouvre l'assistant concerné, pré-rempli, et le franchisé valide (la règle de src/anthropic.php). */
const avatar = (d, k, v, vif, on) => `<button class="rf-av${vif ? ' vif' : ''}${on ? ' on' : ''}"${on ? ' aria-expanded="true"' : ''}><span class="rf-av__o">${ico(d)}</span><span><span class="rf-av__k">${k}</span><span class="rf-av__v">${v}</span></span></button>`;
const avatars = coachOuvert => `<div class="rf-avs">${avatar(I.euro, 'Valeur', '<span class="ex">100 328 €</span>')}${avatar(I.personne, 'Coach', '<span class="ex">2 à regarder</span>', false, coachOuvert)}${avatar(I.revue, 'Revue du soir', '<span class="ex">à faire</span>', true)}</div>`;
const src = l => `<a class="rf-ia__src" href="#">${l} ›</a>`;
const TIROIR_COACH = `<aside class="rf-ia" aria-label="Coach, l’assistant IA">
  <div class="rf-ia__h"><span class="rf-av__o">${ico(I.personne)}</span><div><div class="rf-ia__t">Coach</div><div class="rf-ia__st">l’assistant IA · lit les chiffres de Halle, n’écrit rien</div></div>
    <button class="btn btn--icon btn--ghost" aria-label="Fermer">×</button></div>
  <div class="rf-ia__fil">
    <div class="rf-pill" style="align-self:center">Conversation : exemple</div>
    <div class="rf-ia__m">Bonjour. À 13 h 05, Halle est à 68 % de l’objectif du jour (2 380 €), 4 points devant dimanche dernier à la même heure.
      <br><br>Deux choses à regarder :<br>1. la matière du mois est à 39,6 %, au-dessus du seuil de 35 % ;<br>2. 12 commandes clients sont en retard.
      <div class="rf-ia__srcs">${src('Opérationnel')}${src('Résultat › Mois')}</div></div>
    <div class="rf-ia__moi">Comment rattraper les 2 690 € du mois ?</div>
    <div class="rf-ia__m">Il reste 15 jours d’ouverture : 180 € de plus par jour suffisent. Un dimanche à 110 % de son objectif en rapporte 240 € à lui seul.
      <br><br>Je peux en faire un point du plan d’action&nbsp;: «&nbsp;vitrine pleine jusqu’à 17 h le dimanche&nbsp;». Vous le relisez avant qu’il soit noté.
      <div class="rf-ia__srcs">${src('Résultat › Mois')}${src('Résultat › Semaine')}</div>
      <div class="rf-ia__gestes"><button class="btn btn--sm">Préparer ce point du plan d’action</button></div></div>
  </div>
  <div class="rf-ia__sugg">${['Pourquoi la matière est-elle haute ?', 'Quelles commandes sont en retard ?', 'Prépare ma revue du soir'].map(t => `<button class="btn btn--sm">${t}</button>`).join('')}</div>
  <div class="rf-ia__saisie"><input class="rf-ia__champ" placeholder="Poser une question au coach…"><button class="btn btn--primary">Envoyer</button></div>
  <div class="rf-ia__pied">Le coach propose, vous décidez. Il ne lit que ce magasin et cite chaque chiffre.</div>
</aside>`;
const AVATARS = avatars(false);
const ONGLET = { semaine: 'Semaine', mois: 'Mois', trimestre: 'Trimestre', annee: 'Année' };
const ongletsB = per => `<div class="page-tabs" style="margin:0">${['Semaine', 'Mois', 'Trimestre', 'Année'].map(o => `<button class="page-tabs__tab${o === ONGLET[per] ? ' is-on' : ''}">${o}</button>`).join('')}</div>`;

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

/* ══ Trois variantes de B (demande du 15/11/2026 : « donne-moi trois autres propositions comme la B ») ═══════════════
 * Le même rail que B — les repères en cartes, chacun avec sa forme ; Résultat réunit les quatre périodes ; les
 * assistants en entrées — mais chaque variante pose une autre question en haut : B1 « d'où vient-on ? » (douze mois),
 * B2 « qu'est-ce que l'équipe gagne ? » (les primes en jeu), B3 « qu'est-ce qui cloche ? » (le verdict en phrases). */
const navB = (actif, primes) => `<div class="sidebar__groups">
    ${groupe('Le magasin', [['Opérationnel', I.tdb, '', actif === 'Opérationnel'], ['Tâches', I.controle, '<span class="ex">4</span>'], ['Résultat', I.perf, '', actif === 'Résultat'], ['Temps morts', I.journal]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Concurrence', I.analyse], ['Primes', I.fonds, primes || '']])}
    ${groupe('Assistants', [['Revue du soir', I.revue, '<span class="ex">à faire</span>'], ['Plan d’action', I.formulaires, '<span class="ex">3</span>'], ['Campagne', I.campagnes], ['Réclamation fournisseur', I.commentaires]])}
  </div>`;
const C = { vert: '#3f7a52', or: '#B0792A', stop: '#A32014', trait: '#5c5852', filet: '#e6e0d8', accent: '#8D1D2C' };
/* Une courbe sur une échelle donnée, avec un trait de référence pointillé (la cible) ; le dernier point en bordeaux. */
function ligne(vals, lo, hi, ref) {
  const w = 100, h = 28, y = v => h - 3 - (v - lo) / (hi - lo) * (h - 6);
  const pts = vals.map((v, i) => [3 + i * (w - 6) / (vals.length - 1), y(v)]), last = pts[pts.length - 1];
  return `<svg class="rf-mini" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${ref != null ? `<line x1="0" x2="${w}" y1="${y(ref).toFixed(1)}" y2="${y(ref).toFixed(1)}" stroke="${C.trait}" stroke-width="1" stroke-dasharray="2 2" vector-effect="non-scaling-stroke"/>` : ''}<polyline points="${pts.map(p => p.map(x => x.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${C.trait}" stroke-width="1.5" vector-effect="non-scaling-stroke"/><circle cx="${last[0].toFixed(1)}" cy="${last[1].toFixed(1)}" r="2.4" fill="${C.accent}"/></svg>`;
}
/* Des barres mois par mois. `ref` : la barre est verte au-dessus, or en dessous (objectif) ; sans `ref`, verte si
 * positive, rouge d'arrêt si négative (résultat). Le dernier mois, en cours, est pâle. */
function barres(vals, lo, hi, ref) {
  const w = 100, h = 28, n = vals.length, pas = w / n, bw = pas * 0.62, y = v => h - 2 - (v - lo) / (hi - lo) * (h - 4), base = ref != null ? h - 2 : y(0);
  const b = vals.map((v, i) => { const c = ref != null ? (v >= ref ? C.vert : C.or) : (v >= 0 ? C.vert : C.stop); const t = Math.min(base, y(v)), hh = Math.max(1, Math.abs(base - y(v)));
    return `<rect x="${(i * pas + (pas - bw) / 2).toFixed(1)}" y="${t.toFixed(1)}" width="${bw.toFixed(1)}" height="${hh.toFixed(1)}" fill="${c}"${i === n - 1 ? ' opacity="0.45"' : ''}/>`; }).join('');
  const r = ref != null ? `<line x1="0" x2="${w}" y1="${y(ref).toFixed(1)}" y2="${y(ref).toFixed(1)}" stroke="${C.trait}" stroke-width="1" stroke-dasharray="2 2" vector-effect="non-scaling-stroke"/>` : `<line x1="0" x2="${w}" y1="${base.toFixed(1)}" y2="${base.toFixed(1)}" stroke="${C.filet}" stroke-width="1" vector-effect="non-scaling-stroke"/>`;
  return `<svg class="rf-mini" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${b}${r}</svg>`;
}
// Douze mois, de décembre 2025 à novembre 2026 (en cours) — exemples.
const OBJ12 = [96, 104, 88, 93, 101, 107, 99, 95, 102, 98, 97, 92];
const RES12 = [2.1, 3.4, -0.6, 1.2, 3.9, 5.1, 4.4, 2.8, 4.0, 3.1, 4.6, 3.98];
const VAL12 = [96.0, 96.4, 96.9, 97.3, 97.6, 98.0, 98.4, 98.9, 99.3, 99.6, 100.0, 100.3];

/* ── B1 — Douze mois : quatre cartes en grille, chacune sa courbe ou ses barres sur un an ─────────────────────────── */
const railB1 = `${tete('Dashboard magasin')}
  <div class="rf-rep"><div class="rf-rep__t">Atelier by – Halle<small>12 mois</small></div>
    <div class="rf-g4">
      <button class="rf-carte"><div class="k">Valeur</div><div class="v ex">100,3 k€</div>${ligne(VAL12, 95.5, 100.6)}<div class="s"><span class="ex">+ 4,5 %</span> en un an</div></button>
      <button class="rf-carte"><div class="k">Google</div><div class="v"><span class="ex">4,6</span><small>★</small></div>${ligne([4.4, 4.5, 4.5, 4.6], 4.3, 4.7, 4.5)}<div class="s">cible 4,5</div></button>
      <button class="rf-carte"><div class="k">Objectif</div><div class="v ex">92 %</div>${barres(OBJ12, 80, 110, 100)}<div class="s"><span class="ex">4 mois</span> sur 11 à 100 %</div></button>
      <button class="rf-carte"><div class="k">Résultat</div><div class="v ex">+ 3 980 €</div>${barres(RES12, -1.5, 5.5)}<div class="s">positif <span class="ex">10 mois</span> sur 11</div></button>
    </div></div>
  ${navB('Opérationnel')}`;

/* ── B2 — Les primes en jeu : chaque repère dit ce qu'il rapporte à l'équipe ─────────────────────────────────────── */
const railB2 = `${tete('Dashboard magasin')}
  <div class="rf-rep"><div class="rf-rep__t">Primes d’équipe<small>novembre</small></div>
    <div class="rf-cartes">
      <button class="rf-carte"><div class="k">Objectif du mois<em>au 15/11</em></div><div class="v"><span class="ex">92 %</span><small class="ex">− 2 690 €</small></div>
        <div class="rf-echelle"><i class="att" style="width:83.6%"></i><b style="left:90.9%"></b></div>
        <div class="rf-prime"><span>Prime à 100 %</span><span class="m ex">40 € chacune</span></div><div class="s"><span class="ex">+ 180 € par jour</span> d’ici le 30/11</div></button>
      <button class="rf-carte"><div class="k">Note Google<em class="ex">287 avis</em></div><div class="v"><span class="ex">4,6</span>${etoiles(4.6)}</div>
        <div class="rf-echelle"><i style="width:60%"></i><b style="left:50%"></b></div><div class="rf-echelle-l"><span>4,0</span><span>cible 4,5</span><span>5,0</span></div>
        <div class="rf-prime ok"><span>Prime gagnée</span><span class="m ex">25 € chacune</span></div></button>
      <button class="rf-carte rf-carte--ligne"><div><div class="k">Valeur</div><div class="v ex">100 328 €</div></div>${courbe([94.1, 95.8, 97.2, 98.0, 99.6, 100.3])}</button>
    </div></div>
  ${navB('Opérationnel', '<span class="ex">1 / 2</span>')}`;

/* ── B3 — Le verdict : chaque carte commence par une phrase ; une carte « À regarder » mène où ça cloche ──────────── */
const railB3 = `${tete('Dashboard magasin')}
  <div class="rf-rep"><div class="rf-rep__t">Atelier by – Halle<small>au 15/11</small></div>
    <div class="rf-cartes">
      <button class="rf-carte"><div class="k">Objectif du mois<em class="ex">92 %</em></div><div class="rf-phrase ex">Il manque 2 690 € pour l’objectif.</div>
        <div class="rf-echelle"><i class="att" style="width:92%"></i><b style="left:100%;margin-left:-2px"></b></div><div class="s ex">+ 180 € par jour, 15 jours</div></button>
      <button class="rf-carte"><div class="rf-duo"><span class="rf-dot ok"></span><span class="t ex">Google tient la cible.</span><span class="n ex">4,6 ★</span></div>
        <div class="rf-duo"><span class="rf-dot ok"></span><span class="t ex">La valeur monte.</span><span class="n ex">100,3 k€</span></div></button>
      <div class="rf-carte rf-regarder"><div class="k">À regarder</div>
        <a class="rf-alerte" href="#"><i class="rf-dot ko"></i><span class="ex">Matière 39,6 % (seuil 35)</span><span class="fl">›</span></a>
        <a class="rf-alerte" href="#"><i class="rf-dot att"></i><span class="ex">12 commandes en retard</span><span class="fl">›</span></a>
        <a class="rf-alerte" href="#"><i class="rf-dot att"></i><span class="ex">Biscuiterie : 3 j sans photo</span><span class="fl">›</span></a></div>
    </div></div>
  ${navB('Opérationnel')}`;

fs.writeFileSync(path.join(D, 'b1.html'), page('b1', 'douze mois', railB1, 'Opérationnel'));
fs.writeFileSync(path.join(D, 'b2.html'), page('b2', 'les primes en jeu', railB2, 'Opérationnel'));
fs.writeFileSync(path.join(D, 'b3.html'), page('b3', 'le verdict', railB3, 'Opérationnel'));

fs.writeFileSync(path.join(D, 'a.html'), page('a', 'les repères en liste', railA, 'Opérationnel'));
fs.writeFileSync(path.join(D, 'b.html'), page('b', 'le tableau de bord dans le rail', railB('jour'), 'Opérationnel', '', AVATARS));
fs.writeFileSync(path.join(D, 'b-coach.html'), page('b', 'le coach ouvert', railB('jour'), 'Opérationnel', '', avatars(true), TIROIR_COACH));
// La carte de l'objectif dans chaque onglet de Résultat.
['semaine', 'mois', 'trimestre', 'annee'].forEach(per => fs.writeFileSync(path.join(D, 'b-' + per + '.html'), page('b', 'objectif ' + ONGLET[per].toLowerCase(), railB(per), 'Résultat', ongletsB(per), AVATARS)));
fs.writeFileSync(path.join(D, 'c.html'), page('c', 'les assistants d’abord', railC, 'Opérationnel'));

/* Les planches : les captures côte à côte (A, B, C ; puis B et ses trois variantes). */
function planche(nom, titre, L) {
  fs.writeFileSync(path.join(D, nom + '.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Rail du dashboard franchisé — ${titre}</title>
<link rel="stylesheet" href="${R}assets/ds/global.css"><link rel="stylesheet" href="rf.css"></head>
<body><div class="rf-planche" style="grid-template-columns:repeat(${L.length}, 1fr)">${L.map(([l, t, s]) => `<figure><figcaption>${t}<small>${s}</small></figcaption><img src="${l}.png" alt="${t}"></figure>`).join('')}</div></body></html>
`);
}
const PB = ['b', 'B — Le tableau de bord dans le rail', 'la note Google et l’objectif dans le rail ; la valeur, le coach et la revue en avatars, en haut à droite'];
planche('planche', 'planche', [['a', 'A — Les repères en liste', 'le rail du Marketing tel quel ; les onze onglets rangés en trois groupes, les assistants en bas'],
  PB, ['c', 'C — Les assistants d’abord', 'quatre cases, la revue du soir en tête, puis ce qu’il reste à faire']]);
planche('planche-b', 'B et ses variantes', [PB,
  ['b1', 'B1 — Douze mois', 'quatre cartes en grille : la valeur, la note Google, l’objectif et le résultat sur un an'],
  ['b2', 'B2 — Les primes en jeu', 'l’objectif et la note Google disent ce qu’ils rapportent à l’équipe ; la valeur en une ligne'],
  ['b3', 'B3 — Le verdict', 'chaque carte commence par une phrase ; « À regarder » mène où ça cloche']]);
// La carte de l'objectif écran par écran : le haut de chaque capture (le rail et les onglets de la page).
const PO = [['b', 'Opérationnel (et tout écran hors Résultat)', 'l’objectif du jour'], ['b-semaine', 'Résultat › Semaine', 'l’objectif de la semaine'],
  ['b-mois', 'Résultat › Mois', 'l’objectif du mois'], ['b-trimestre', 'Résultat › Trimestre', 'l’objectif du trimestre'], ['b-annee', 'Résultat › Année', 'l’objectif de l’année']];
fs.writeFileSync(path.join(D, 'planche-objectif.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Rail du dashboard franchisé — B, la carte de l’objectif</title>
<link rel="stylesheet" href="${R}assets/ds/global.css"><link rel="stylesheet" href="rf.css"></head>
<body><div class="rf-planche rf-planche--haut" style="grid-template-columns:repeat(${PO.length}, 1fr)">${PO.map(([l, t, s]) => `<figure><figcaption>${t}<small>${s}</small></figcaption><div class="rf-haut"><img src="${l}.png" alt="${t}"></div></figure>`).join('')}</div></body></html>
`);
console.log('a, b, b-coach, b-semaine, b-mois, b-trimestre, b-annee, c, b1, b2, b3 (.html), planche.html, planche-b.html, planche-objectif.html');
