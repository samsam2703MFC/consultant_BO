/* Maquette « Boîte mail » dans le dashboard franchisé (demande du 15/11/2026 : « au-dessus, mettre dans le rail : boîte
 * mail » ; choix : « une maquette d'une boîte dans le dashboard »).
 *
 *   node docs/maquettes/rail-franchise/generer-mail.js   → mail.html
 *   node docs/maquettes/rail-franchise/capturer.js       → mail.png
 *
 * Le rail en ligne, avec « Boîte mail » au-dessus des groupes (et le nombre de non lus) ; la page dans le dashboard : les
 * dossiers en onglets (Réception, Envoyés, Brouillons, Archives), la recherche, la liste des e-mails, l'e-mail ouvert (ses
 * pièces jointes, Répondre, Transférer, Archiver) et « Écrire » en action principale. Quand un e-mail parle de ce que le
 * dashboard connaît (une campagne, une commande, une visite), un lien y mène.
 * Aujourd'hui le cockpit ENVOIE des e-mails (src/smtp.php, src/ca_mail.php) et n'en lit aucun : tous les e-mails de la
 * maquette sont des EXEMPLES (italique pointillé) ; aucun nom de personne. Le rail et la barre : les chiffres du 15/11. */
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
  retour: 'M19 12H5M12 19l-7-7 7-7',
  // La boîte mail (ajout, signalé au LISEZMOI) : l'enveloppe au trait.
  mail: 'M3 5h18v14H3zM3 6l9 7 9-7',
  ecrire: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  trombone: 'M21 11l-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7',
  chercher: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
const IA_ICO = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.5h11A2.5 2.5 0 0 1 20 6v7.5a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4A2.5 2.5 0 0 1 4 13.5V6a2.5 2.5 0 0 1 2.5-2.5z"/><path d="M12 6.4l.95 2.35 2.35.95-2.35.95L12 13l-.95-2.35-2.35-.95 2.35-.95z" fill="currentColor" stroke-width="1"/></svg>';
const RV_ICO = '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><rect x="9" y="8" width="26" height="34" rx="4" fill="none" stroke="#5c5852" stroke-width="3.2"/><rect x="16" y="5" width="12" height="6" rx="2" fill="#5c5852"/><path d="M14 20l3 3 5-6M14 31l3 3 5-6" fill="none" stroke="#5c5852" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M25 21h5M25 32h5" stroke="#5c5852" stroke-width="3" stroke-linecap="round"/><circle cx="37" cy="37" r="9" fill="#8D1D2C"/><path d="M39.5 31.5a6 6 0 1 0 3 9 5 5 0 0 1-3-9z" fill="#fff"/></svg>';
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;
const ex = t => `<span class="ex">${t}</span>`;

/* ── Le rail en ligne ; « Boîte mail » au-dessus des groupes ─────────────────── */
const RAIL = [
  ['La journée', [['Opérationnel', 'tdb'], ['Tâches', 'controle'], ['Temps morts', 'journal']]],
  ['Les chiffres', [['Semaine', 'cal'], ['Mois', 'calEd'], ['Année', 'perf'], ['Trésorerie', 'fonds']]],
  ['Vendre', [['Produits', 'bundles'], ['Promotions', 'offres'], ['Primes', 'personnes'], ['Concurrence', 'analyse']]],
  ['Marketing', [['Calendrier des posts', 'calEd', '↗'], ['Fidélité', 'etoile'], ['Mon catalogue', 'brochures']]],
];
const rail = `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
  <div class="sidebar__back"><a href="#">${ico(I.retour)}Cockpit › Résultat</a></div>
  <div class="rf-rep" style="margin-top:12px"><div class="rf-cartes">
    <button class="rf-carte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">36 %<small>de 2 480 €</small></div>
      <div class="rf-echelle"><i class="att" style="width:36%"></i><b style="left:100%;margin-left:-2px"></b></div><div class="s">898 € · trait : projection 2 547 €</div></button>
    <button class="rf-carte"><div class="k">Note Google<em>55 avis</em></div><div class="v">4,5${etoiles(4.5)}<small>cible 4,6</small></div>
      <div class="rf-echelle"><i class="att" style="width:50%"></i><b style="left:60%"></b></div></button>
  </div></div>
  <div class="sidebar__groups">
    <div class="ml-rail"><button class="sidebar__item is-active" aria-current="page">${ico(I.mail)}<span class="sidebar__item-label">Boîte mail</span><span class="rf-etat">${ex('4')}</span></button></div>
    ${RAIL.map(([g, L]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${L.map(([l, i, ext]) => `<button class="sidebar__item">${ico(I[i])}<span class="sidebar__item-label">${l}</span>${ext ? `<span class="rf-ext">${ext}</span>` : ''}</button>`).join('')}</div>`).join('')}</div>`;

const barre = `<div class="mk-barre"><div class="db-vt"><div class="kk">Atelier by – Halle</div><h1>Boîte mail</h1></div>
  <span style="flex:1"></span><button class="btn">↻ Relire</button>
  <span class="rf-avs"><button class="fn-vignette"><span class="k">Valeur du magasin</span><span class="v">102 172 €</span></button>
    <button class="rf-av"><span class="rf-av__o">${IA_ICO}</span><span><span class="rf-av__k">Coach</span><span class="rf-av__v">assistant</span></span></button>
    <button class="rf-av"><span class="rf-av__o">${RV_ICO}</span><span><span class="rf-av__k">Revue du soir</span><span class="rf-av__v">à 18 h</span></span></button></span></div>`;

/* ── La boîte : dossiers, recherche, liste, e-mail ouvert ───────────────────── */
// [de, objet, extrait, quand, non lu ?, pièce jointe ?, étiquette, ouvert ?]
const MAILS = [
  ['Le réseau L’Atelier By', 'Saint-Nicolas : les visuels et le planning des posts', 'Bonjour à toute l’équipe de Halle, voici les visuels de la campagne et les dates…', '09:42', true, true, 'Campagne', true],
  ['Centrale d’achat', 'Votre commande du 17/11 est confirmée', 'Farine T65 · 12 sacs, beurre AOP · 8 cartons, livraison mardi entre 6 h et 8 h…', '08:15', true, true, 'Commande'],
  ['Client · commande en ligne', 'Gâteau d’anniversaire pour samedi 21/11', 'Bonjour, serait-il possible d’avoir un fraisier pour 12 personnes avec…', 'hier', true, false, ''],
  ['Google Business Profile', 'Nouvel avis sur L’Atelier By Halle', '★★★★★ « Les croissants du dimanche sont les meilleurs du coin… »', 'hier', true, false, 'Avis'],
  ['Votre consultant', 'Compte rendu de la visite du 12/11', '3 points du plan d’action : la vitrine des biscuits, l’étiquetage des prix…', '13/11', false, true, 'Visite'],
  ['Comptabilité', 'TVA du 3e trimestre : les pièces manquantes', 'Il manque deux factures fournisseurs de septembre pour clôturer…', '12/11', false, true, ''],
  ['Le réseau L’Atelier By', 'Newsletter de novembre : à relire avant envoi', 'La newsletter part le 05/11 à 1 284 abonnés de Halle…', '04/11', false, false, 'Campagne'],
  ['Fournisseur · emballages', 'Boîtes à tartes 28 Ø : retour en stock', 'Les boîtes à tartes sont de nouveau disponibles, commande minimum…', '03/11', false, false, ''],
];
const liste = MAILS.map(([de, obj, ext, quand, nonlu, pj, etiq, ouvert]) => `<div class="ml-l${nonlu ? ' nonlu' : ''}${ouvert ? ' on' : ''}">
    <div class="ml-l-h"><span class="ml-de">${ex(de)}</span><span class="ml-q">${ex(quand)}</span></div>
    <div class="ml-obj">${nonlu ? '<i class="ml-pt"></i>' : ''}${ex(obj)}</div>
    <div class="ml-ext">${pj ? ico(I.trombone) : ''}${etiq ? `<span class="ml-et">${etiq}</span>` : ''}<span>${ex(ext)}</span></div></div>`).join('');
const PJ = [['Saint-Nicolas · affiche A3.pdf', '2,4 Mo'], ['Saint-Nicolas · post Instagram.jpg', '820 Ko'], ['Planning des posts · décembre.pdf', '310 Ko']];
const lecture = `<div class="ml-r">
  <div class="ml-r-h"><div><h2 class="ml-r-obj">${ex('Saint-Nicolas : les visuels et le planning des posts')}</h2>
    <div class="ml-r-de">${ex('Le réseau L’Atelier By')} <span class="muted">· ${ex('reseau@atelierby.be')} → halle@atelierby.be · ${ex('aujourd’hui à 09:42')}</span></div></div>
    <div class="ml-r-act"><button class="btn btn--sm">Répondre</button><button class="btn btn--sm">Transférer</button><button class="btn btn--sm btn--ghost">Archiver</button></div></div>
  <div class="ml-lien">${ico(I.calEd)}<span>Cet e-mail parle de la campagne <b class="ml-b">Saint-Nicolas : les cougnous</b> (du 15/11 au 06/12).</span><a href="#">Ouvrir la campagne dans Réseaux sociaux ›</a></div>
  <div class="ml-corps">${ex('Bonjour à toute l’équipe de Halle,')}<br><br>${ex('La campagne Saint-Nicolas démarre aujourd’hui avec les précommandes de cougnous. Vous trouverez en pièces jointes l’affiche A3 pour la vitrine, le visuel du post Instagram et le planning des posts jusqu’au 6 décembre.')}<br><br>
    ${ex('Trois choses à faire cette semaine :')}<br>${ex('1. Afficher l’affiche en vitrine dès lundi ;')}<br>${ex('2. Valider le post du 19/11 dans votre calendrier ;')}<br>${ex('3. Saisir vos objectifs de cougnous (sucre, chocolat, coffrets).')}<br><br>${ex('Bonne campagne,')}<br>${ex('Le réseau L’Atelier By')}</div>
  <div class="ml-pjs">${PJ.map(([n, t]) => `<div class="ml-pj">${ico(I.trombone)}<span>${ex(n)}<small>${ex(t)}</small></span><button class="btn btn--sm btn--ghost">Ouvrir</button></div>`).join('')}</div>
  <div class="ml-rep"><span class="muted">Répondre à ${ex('Le réseau L’Atelier By')}…</span><button class="btn">Répondre</button></div>
</div>`;
const corps = `<div class="rs-onglets"><div class="page-tabs" style="margin:0;border:0">${[['Réception', '4'], ['Envoyés', ''], ['Brouillons', '1'], ['Archives', '']].map(([l, n], i) => `<button class="page-tabs__tab${i === 0 ? ' is-on' : ''}">${l}${n ? ` <span class="page-tabs__count">${ex(n)}</span>` : ''}</button>`).join('')}</div>
  <span style="flex:1"></span><span class="muted ml-adr">halle@atelierby.be</span><button class="btn btn--primary">${ico(I.ecrire)}Écrire</button></div>
  <div class="ml">
    <div class="ml-g"><div class="ml-q-b">${ico(I.chercher)}<input class="ml-q-in" placeholder="Chercher un e-mail, un expéditeur, une pièce jointe…"></div>
      <div class="ml-filtres"><button class="rs-puce on">Tout</button><button class="rs-puce">Non lus ${ex('4')}</button><button class="rs-puce">Le réseau</button><button class="rs-puce">Commandes</button><button class="rs-puce">Avec pièce jointe</button></div>
      <div class="ml-liste">${liste}</div></div>
    ${lecture}
  </div>`;

fs.writeFileSync(path.join(D, 'mail.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard magasin — Boîte mail (maquette)</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css"><link rel="stylesheet" href="final.css"><link rel="stylesheet" href="marketing.css"><link rel="stylesheet" href="reseaux.css"><link rel="stylesheet" href="mail.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail}
</nav>
<div class="workspace__main mk-main">${barre}
  <p class="mk-note"><span class="rf-pill">Maquette · la boîte mail du magasin dans le dashboard · e-mails, expéditeurs et pièces jointes : exemples · rail et barre : chiffres du 15/11</span></p>
  <div class="rs-page">${corps}</div>
</div>
</div>
</body></html>
`);
console.log('mail.html');
