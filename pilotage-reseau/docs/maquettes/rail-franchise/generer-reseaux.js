/* Maquette « Réseaux sociaux » dans le dashboard franchisé (demande du 15/11/2026 : « dans Marketing, il me faut une page
 * Réseaux sociaux, sans redirection, avec le calendrier, le calendrier Meta, IG, un sous-menu Campagnes avec les campagnes
 * en cours et les targets ; ensuite il me faut créer un post — fais-moi une maquette screenshot »).
 *
 *   node docs/maquettes/rail-franchise/generer-reseaux.js   → rs-calendrier, rs-meta, rs-instagram, rs-campagnes, rs-creer (.html)
 *   node docs/maquettes/rail-franchise/capturer.js          → les mêmes en .png, et planche-reseaux.jpg
 *
 * Une entrée « Réseaux sociaux » dans le groupe Marketing du rail en ligne ; la page s'ouvre dans le dashboard, sans
 * redirection, en onglets (§2 du design system : un écran à plusieurs vues = des onglets) : Calendrier (le calendrier
 * marketing du magasin : campagnes, promotions, newsletters, événements), Calendrier Meta (les posts Facebook et
 * Instagram), Instagram (la grille), Campagnes (en cours, leurs objectifs produits, à venir) ; « Créer un post » ouvre
 * l'écran de création. Les écrans reprennent ce que le Marketing a déjà (espace boutique : CalendrierFranchiseView,
 * PublierView, InstagramView, OperationsView ; statuts STATUS) — à servir par son API, pas en lisant ses tables.
 * Chiffres : le rail et la barre, ceux lus au cockpit le 15/11/2026 à 09:57 (Halle) ; les posts, campagnes, objectifs,
 * abonnés et statistiques sont des EXEMPLES (italique pointillé) ; les photos sont celles des contrôles de Halle
 * (comptoirs, personne dessus). */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = '../../../public/';
// Les comptoirs sans personne (la photo des viennoiseries, où l'on voit quelqu'un de dos, n'est pas reprise).
const PH = n => `../dashboard-operationnel/photos/${['2026-10-06-1206', '2026-10-06-1209', '2026-10-06-1210', '2026-10-06-1211', '2026-10-06-1213', '2026-10-06-1214', '2026-10-06-1216'][n % 7]}.jpg`;

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
  grille: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
  retour: 'M19 12H5M12 19l-7-7 7-7',
  ecrire: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
const IA_ICO = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.5h11A2.5 2.5 0 0 1 20 6v7.5a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4A2.5 2.5 0 0 1 4 13.5V6a2.5 2.5 0 0 1 2.5-2.5z"/><path d="M12 6.4l.95 2.35 2.35.95-2.35.95L12 13l-.95-2.35-2.35-.95 2.35-.95z" fill="currentColor" stroke-width="1"/></svg>';
const RV_ICO = '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><rect x="9" y="8" width="26" height="34" rx="4" fill="none" stroke="#5c5852" stroke-width="3.2"/><rect x="16" y="5" width="12" height="6" rx="2" fill="#5c5852"/><path d="M14 20l3 3 5-6M14 31l3 3 5-6" fill="none" stroke="#5c5852" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M25 21h5M25 32h5" stroke="#5c5852" stroke-width="3" stroke-linecap="round"/><circle cx="37" cy="37" r="9" fill="#8D1D2C"/><path d="M39.5 31.5a6 6 0 1 0 3 9 5 5 0 0 1-3-9z" fill="#fff"/></svg>';
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;
const ex = t => `<span class="ex">${t}</span>`;

/* ── Le rail en ligne, avec Réseaux sociaux en tête du groupe Marketing ────────── */
const RAIL = [
  ['La journée', [['Opérationnel', 'tdb'], ['Tâches', 'controle'], ['Temps morts', 'journal']]],
  ['Les chiffres', [['Semaine', 'cal'], ['Mois', 'calEd'], ['Année', 'perf'], ['Trésorerie', 'fonds']]],
  ['Vendre', [['Produits', 'bundles'], ['Promotions', 'offres'], ['Primes', 'personnes'], ['Concurrence', 'analyse']]],
  ['Marketing', [['Réseaux sociaux', 'grille', true], ['Fidélité', 'etoile'], ['Mon catalogue', 'brochures']]],
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

/* ── La barre du haut du dashboard, puis les onglets de la page ──────────────── */
const ONGLETS = [['cal', 'Calendrier'], ['meta', 'Calendrier Meta'], ['ig', 'Instagram'], ['camp', 'Campagnes']];
const barre = `<div class="mk-barre"><div class="db-vt"><div class="kk">Atelier by – Halle</div><h1>Réseaux sociaux</h1></div>
  <span style="flex:1"></span><button class="btn">↻ Relire</button>
  <span class="rf-avs"><button class="fn-vignette"><span class="k">Valeur du magasin</span><span class="v">102 172 €</span></button>
    <button class="rf-av"><span class="rf-av__o">${IA_ICO}</span><span><span class="rf-av__k">Coach</span><span class="rf-av__v">assistant</span></span></button>
    <button class="rf-av"><span class="rf-av__o">${RV_ICO}</span><span><span class="rf-av__k">Revue du soir</span><span class="rf-av__v">à 18 h</span></span></button></span></div>`;
const onglets = on => `<div class="rs-onglets"><div class="page-tabs" style="margin:0;border:0">${ONGLETS.map(([k, l]) => `<button class="page-tabs__tab${k === on ? ' is-on' : ''}">${l}${k === 'camp' ? ' <span class="page-tabs__count">2</span>' : ''}</button>`).join('')}</div>
  <span style="flex:1"></span>${on === 'creer' ? '' : `<button class="btn btn--primary">${ico(I.ecrire)}Créer un post</button>`}</div>`;

const STATUS = { 'À valider': ['#F6E7CE', '#7A5207'], Programmé: ['#F2E2CE', '#6b4420'], Publié: ['#E2F0E8', '#3f7a52'], Refusé: ['#F6DCDF', '#8D1D2C'], Échec: ['#8D1D2C', '#FFFFFF'], Brouillon: ['#EDE7DE', '#5c5852'] };
const iso = d => d.toISOString().slice(0, 10);
const JOURS_NOV = []; for (let d = new Date('2026-11-01T12:00:00Z'); iso(d) <= '2026-11-30'; d.setUTCDate(d.getUTCDate() + 1)) { JOURS_NOV.push(iso(d)); }

/* ── 1. Calendrier : le calendrier marketing du magasin, un mois en ligne de temps ── */
const TYPES = { campagne: ['Campagne du réseau', '#F6E7CE', '#7A5207'], promo: ['Promotion', '#E2F0E8', '#3f7a52'], news: ['Newsletter', '#EDE7DE', '#5c5852'], post: ['Post du réseau', '#F2E2CE', '#6b4420'], evt: ['Événement', '#F6DCDF', '#8D1D2C'] };
const ITEMS = [
  ['campagne', 'Saint-Nicolas : les cougnous', '2026-11-15', '2026-11-30', 'jusqu’au 6/12 · FB, IG, vitrine'],
  ['campagne', 'Les tartes d’automne', '2026-11-01', '2026-11-15', 'FB, IG, affiche'],
  ['promo', '3 croissants achetés + 1 offert', '2026-11-01', '2026-11-30', 'le matin, avant 10 h'],
  ['promo', '−20 % sur la 2e quiche', '2026-11-16', '2026-11-22', 'à valider au réseau'],
  ['news', 'Newsletter de novembre', '2026-11-05', '2026-11-05', '1 284 abonnés'],
  ['news', 'Newsletter Saint-Nicolas', '2026-11-26', '2026-11-26', 'brouillon'],
  ['post', 'Le brunch du dimanche', '2026-11-08', '2026-11-08', 'chaque dimanche'],
  ['post', 'Le brunch du dimanche', '2026-11-15', '2026-11-15', ''], ['post', 'Le brunch du dimanche', '2026-11-22', '2026-11-22', ''], ['post', 'Le brunch du dimanche', '2026-11-29', '2026-11-29', ''],
  ['evt', '11 novembre : horaires réduits', '2026-11-11', '2026-11-11', '7 h – 13 h'],
  ['evt', 'Black Friday (pas de remise)', '2026-11-27', '2026-11-27', 'décision du réseau'],
];
const lignesCal = (() => {
  const grp = []; ITEMS.forEach(it => { const g = grp.find(x => x[1] === it[1]); if (g) { g.push(it); } else { grp.push([it[0], it[1], it]); } });
  return grp.map(g => { const t = TYPES[g[0]], its = g.slice(2), sous = its[0][4];
    return `<div class="rs-tl-l"><div class="rs-tl-n"><i style="background:${t[1]};border-color:${t[2]}"></i><span>${ex(g[1])}<small>${t[0]}${sous ? ' · ' + sous : ''}</small></span></div>
      <div class="rs-tl-j">${its.map(it => { const a = JOURS_NOV.indexOf(it[2]), b = JOURS_NOV.indexOf(it[3]); return `<span class="rs-tl-b" style="grid-column:${a + 1} / ${b + 2};background:${t[1]};color:${t[2]};border-color:${t[2]}"></span>`; }).join('')}</div></div>`; }).join('');
})();
const ecranCal = `${onglets('cal')}
  <div class="rs-tete"><div><span class="mk-eyebrow">Le calendrier marketing · L’Atelier By Halle · campagnes, promotions, newsletters, événements</span><h2>Novembre 2026</h2></div>
    <div class="mk-outils"><div class="seg"><button class="is-on">Mois</button><button>Trimestre</button></div><button class="btn btn--sm btn--icon">‹</button><button class="btn btn--sm">Aujourd’hui</button><button class="btn btn--sm btn--icon">›</button></div></div>
  <div class="rs-tl"><div class="rs-tl-l rs-tl-h"><div class="rs-tl-n"></div><div class="rs-tl-j">${JOURS_NOV.map(j => { const t = new Date(j + 'T12:00:00Z'), dim = t.getUTCDay() === 0; return `<span class="${dim ? 'dim' : ''}${j === '2026-11-15' ? ' auj' : ''}">${+j.slice(8)}</span>`; }).join('')}</div></div>${lignesCal}</div>
  <div class="mk-legende">${Object.values(TYPES).map(t => `<span><i style="background:${t[1]};border-color:${t[2]}"></i>${t[0]}</span>`).join('')}<span class="muted">le trait bordeaux : aujourd’hui</span></div>`;

/* ── 2. Calendrier Meta : les posts Facebook et Instagram du mois ─────────────── */
const POSTS = [
  ['2026-10-27', '09:00', 'Les tartes d’automne', 'Publié', 'FB · IG'], ['2026-10-31', '10:00', 'Halloween : les biscuits', 'Publié', 'IG'],
  ['2026-11-01', '08:30', 'Toussaint : ouvert 7 h – 13 h', 'Publié', 'FB'], ['2026-11-04', '09:00', 'Nouveau : le pain aux noix', 'Publié', 'FB · IG'],
  ['2026-11-08', '08:00', 'Le brunch du dimanche', 'Publié', 'FB · IG'], ['2026-11-11', '08:30', '11 novembre : horaires', 'Publié', 'FB'],
  ['2026-11-12', '12:00', '−20 % sur les quiches', 'Refusé', 'IG'], ['2026-11-15', '09:00', 'Croissants du dimanche', 'Publié', 'FB · IG'],
  ['2026-11-17', '11:00', 'Le menu midi de la semaine', 'Programmé', 'FB'], ['2026-11-19', '10:00', 'Cougnous : précommandes', 'À valider', 'FB · IG'],
  ['2026-11-22', '08:00', 'Le brunch du dimanche', 'Programmé', 'FB · IG'], ['2026-11-26', '', 'Idée : la semaine des soupes', 'Brouillon', 'IG'],
  ['2026-11-29', '09:30', 'Saint-Nicolas approche', 'Programmé', 'IG'], ['2026-12-03', '10:00', 'Bûches : réservations', 'À valider', 'FB · IG'],
  ['2026-12-06', '08:00', 'Saint-Nicolas : les cougnous', 'Programmé', 'FB · IG'],
];
const cases = []; for (let d = new Date('2026-10-26T12:00:00Z'); iso(d) <= '2026-12-06'; d.setUTCDate(d.getUTCDate() + 1)) { cases.push(iso(d)); }
const tuile = (lab, val, sub, bon) => `<div class="mk-tuile"><span class="lab">${lab}</span><span class="val${bon ? ' bon' : ''}">${val}</span><span class="sub">${sub}</span></div>`;
const ecranMeta = `${onglets('meta')}
  <div class="rs-tete"><div><span class="mk-eyebrow">Calendrier Meta · L’Atelier By Halle · Facebook et Instagram</span><h2>Novembre 2026</h2></div>
    <div class="mk-outils"><div class="seg"><button class="is-on">Mois</button><button>Semaine</button><button>Liste</button></div><button class="btn btn--sm btn--icon">‹</button><button class="btn btn--sm">Aujourd’hui</button><button class="btn btn--sm btn--icon">›</button></div></div>
  <section class="mk-semaine"><div class="mk-st"><span>Ma semaine · du 9 au 15 novembre</span><span class="mk-rang">${ex('3<sup>e</sup> sur 4 boutiques')}</span></div>
    <div class="mk-tuiles">${tuile('Régularité', ex('3 posts'), 'objectif : 3 par semaine', true)}${tuile('À traiter', ex('2'), 'commentaires sans réponse')}${tuile('Avis Google', '4,5 ★', '55 avis · cible 4,6')}${tuile('Abonnés', ex('1 284'), ex('+ 12') + ' cette semaine', true)}</div></section>
  <div class="mk-mois"><div class="mk-jours">${['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'].map(j => `<span>${j}</span>`).join('')}</div>
  <div class="mk-cases">${cases.map(j => { const ps = POSTS.filter(p => p[0] === j);
    return `<div class="mk-case${j.slice(5, 7) === '11' ? '' : ' hors'}${j === '2026-11-15' ? ' auj' : ''}"><span class="n">${+j.slice(8)}</span>${ps.map(([, h, t, st, rs]) => `<span class="mk-post" style="background:${STATUS[st][0]};color:${STATUS[st][1]}">${ex((h ? h + ' · ' : '') + t)}<small>${st} · ${rs}</small></span>`).join('')}</div>`; }).join('')}</div></div>
  <div class="mk-legende">${Object.keys(STATUS).map(s => `<span><i style="background:${STATUS[s][0]};border-color:${STATUS[s][1]}"></i>${s}</span>`).join('')}</div>`;

/* ── 3. Instagram : la grille du compte, ses chiffres, ce qui est programmé ───── */
const IG = [['Croissants du dimanche', '15/11', 64, 5, 'Publié'], ['Les viennoiseries du matin', '12/11', 48, 2, 'Publié'], ['Le pain aux noix', '04/11', 71, 9, 'Publié'],
  ['Les tartes d’automne', '27/10', 92, 11, 'Publié'], ['Le traiteur du midi', '24/10', 37, 1, 'Publié'], ['Les quiches', '20/10', 41, 3, 'Publié'],
  ['La vitrine du samedi', '17/10', 55, 4, 'Publié'], ['Halloween : les biscuits', '31/10', 83, 7, 'Publié'], ['Saint-Nicolas approche', '29/11', 0, 0, 'Programmé']];
const ordreIG = [8, 0, 1, 2, 7, 3, 4, 5, 6];
const ecranIG = `${onglets('ig')}
  <div class="rs-ig">
    <div class="rs-ig-g"><div class="rs-ig-p"><img src="${R}assets/img/logo.png" alt=""><div><b>atelierby.halle</b><small>${ex('214 publications · 1 284 abonnés · 312 abonnements')}</small></div></div>
      <div class="rs-ig-grille">${ordreIG.map((k, n) => { const [t, d, l, c, st] = IG[k]; return `<figure class="rs-ig-c${st === 'Programmé' ? ' prog' : ''}"><img src="${PH(n)}" alt=""><figcaption>${st === 'Programmé' ? `<span class="rs-st" style="background:${STATUS[st][0]};color:${STATUS[st][1]}">${st} · ${d}</span>` : `<span>${ex('♥ ' + l + ' · 💬 ' + c).replace('💬', '')}</span>`}<small>${ex(t)}</small></figcaption></figure>`; }).join('')}</div></div>
    <aside class="rs-ig-a">
      <section class="card rs-carte"><h2>Les 28 derniers jours</h2>${[['Portée', ex('6 420'), ex('+ 18 %') + ' sur les 28 jours d’avant', true], ['Interactions', ex('512'), 'j’aime, commentaires, partages'], ['Nouveaux abonnés', ex('+ 46'), ex('3,7 %') + ' du compte', true], ['Meilleur post', ex('Les tartes d’automne'), ex('92 j’aime · 11 commentaires')]].map(([k, v, s, b]) => `<div class="rs-kv"><span class="k">${k}</span><span class="v${b ? ' bon' : ''}">${v}</span><span class="s">${s}</span></div>`).join('')}</section>
      <section class="card rs-carte"><h2>La grille, à venir</h2><p class="rs-p">La case pâle en haut à gauche : « Saint-Nicolas approche », programmé le ${ex('29/11 à 09:30')}. La grille se lit comme le compte la montrera.</p>
        <p class="rs-p">Conseil du réseau : alterner produit, vitrine et équipe ; ${ex('3 posts')} par semaine.</p></section>
    </aside>
  </div>`;

/* ── 4. Campagnes : en cours (et leurs objectifs produits), puis à venir ─────── */
const ETATS = { avance: ['en avance', 'ok'], clous: ['dans les clous', 'att'], retard: ['en retard', 'ko'], atteint: ['objectif atteint', 'ok'] };
const objectif = ([prod, fait, cible, attendu, etat]) => { const pct = Math.min(100, 100 * fait / cible); return `<div class="rs-obj"><div class="rs-obj-h"><span>${ex(prod)}</span><span class="rs-obj-v">${ex(fait + ' / ' + cible)}<small> pièces</small></span><span class="rs-etat ${ETATS[etat][1]}">${ETATS[etat][0]}</span></div>
  <div class="rs-jauge"><i class="${ETATS[etat][1]}" style="width:${pct.toFixed(1)}%"></i><b style="left:${attendu}%"></b></div><div class="rs-obj-s">${ex(Math.round(pct) + ' %')} fait · attendu à ce jour ${ex(attendu + ' %')}</div></div>`; };
const campagne = (nom, dates, canaux, statut, objs, txt) => `<section class="card rs-camp"><div class="rs-camp-h"><div><span class="mk-eyebrow">${statut}</span><h3>${ex(nom)}</h3><span class="rs-p">${ex(dates)} · ${canaux}</span></div>
  <button class="btn btn--sm">Voir les posts de la campagne</button></div>${txt ? `<p class="rs-p">${txt}</p>` : ''}<div class="rs-objs">${objs.map(objectif).join('')}</div></section>`;
const ecranCamp = `${onglets('camp')}
  <div class="rs-tete"><div><span class="mk-eyebrow">Les campagnes du réseau dans le magasin · et leurs objectifs produits</span><h2>En cours</h2></div>
    <div class="mk-outils"><div class="seg"><button class="is-on">En cours <b>2</b></button><button>À venir <b>1</b></button><button>Terminées</button></div></div></div>
  ${campagne('Saint-Nicolas : les cougnous', 'du 15/11 au 06/12 · précommandes', 'Facebook, Instagram, vitrine, newsletter', 'Campagne du réseau · en cours depuis aujourd’hui', [['Cougnou sucre 250 g', 42, 300, 5, 'avance'], ['Cougnou chocolat 250 g', 9, 150, 5, 'clous'], ['Coffret Saint-Nicolas', 2, 40, 5, 'clous']], 'Précommandes ouvertes : 1 post à valider (19/11), 2 programmés.')}
  ${campagne('Les tartes d’automne', 'du 01/11 au 15/11 · dernier jour', 'Facebook, Instagram, affiche', 'Campagne du réseau · en cours', [['Tarte aux pommes 28 Ø', 168, 200, 93, 'retard'], ['Tarte au riz 28 Ø', 121, 120, 93, 'atteint'], ['Tarte frangipane-poires', 87, 80, 93, 'avance']])}`;

/* ── 5. Créer un post : le modèle, la photo, le texte, les réseaux, la date ; l'aperçu ── */
const MODELES = [['Produit du moment', true], ['Horaires', false], ['Événement', false], ['Campagne Saint-Nicolas', false], ['Texte libre', false]];
const ecranCreer = `<div class="rs-onglets"><button class="btn btn--sm">‹ Retour au calendrier Meta</button><span style="flex:1"></span><span class="rs-p">Brouillon enregistré à ${ex('10:02')}</span></div>
  <div class="rs-creer">
    <section class="card rs-form"><h2>Créer un post</h2>
      <div class="rs-champ"><span class="lab">1 · Le modèle du réseau</span><div class="rs-puces">${MODELES.map(([m, on]) => `<button class="rs-puce${on ? ' on' : ''}">${m}</button>`).join('')}</div><small>Le modèle donne le texte et la charte ; « Produit du moment » passe par la validation du réseau.</small></div>
      <div class="rs-champ"><span class="lab">2 · La photo</span><div class="rs-photos">${[0, 1, 2, 3, 4].map(n => `<figure class="${n === 1 ? 'on' : ''}"><img src="${PH(n)}" alt=""></figure>`).join('')}<button class="rs-plus">+ Depuis mon téléphone</button></div><small>Les photos de la médiathèque du magasin et du réseau.</small></div>
      <div class="rs-champ"><span class="lab">3 · Le texte</span><div class="rs-texte">Ce matin chez L’Atelier By Halle : ${ex('les viennoiseries sortent du four à 7 h. Croissants, pains au chocolat, couques au beurre')}.<br>Avenue Auguste Demaeghtlaan 227 · ouvert de 7 h à 19 h.</div><small>${ex('148 / 2 200')} caractères · la phrase en italique est la vôtre, le reste vient du modèle.</small></div>
      <div class="rs-ligne2"><div class="rs-champ"><span class="lab">4 · Où</span><div class="rs-puces"><button class="rs-puce on">Facebook</button><button class="rs-puce on">Instagram</button></div></div>
        <div class="rs-champ"><span class="lab">5 · Quand</span><div class="rs-puces"><button class="rs-puce">Maintenant</button><button class="rs-puce on">${ex('mardi 17/11 · 07:30')}</button></div></div></div>
      <div class="rs-valid"><span class="rs-st" style="background:${STATUS['À valider'][0]};color:${STATUS['À valider'][1]}">À valider</span><span class="rs-p">Le réseau relit ce post avant qu’il parte (le modèle le demande). Vous êtes prévenu dès qu’il est validé ou refusé.</span></div>
      <div class="rs-actions"><button class="btn">Enregistrer le brouillon</button><button class="btn btn--primary">Envoyer au réseau</button></div></section>
    <aside class="rs-apercu"><span class="mk-eyebrow">Aperçu · Instagram</span>
      <div class="rs-ap"><div class="rs-ap-h"><img src="${R}assets/img/logo.png" alt=""><b>atelierby.halle</b><small>Halle</small></div><img class="rs-ap-img" src="${PH(1)}" alt="">
        <div class="rs-ap-t"><b>atelierby.halle</b> Ce matin chez L’Atelier By Halle : ${ex('les viennoiseries sortent du four à 7 h…')}</div><small class="rs-p">${ex('mardi 17 novembre · 07:30')}</small></div>
      <span class="mk-eyebrow" style="margin-top:12px">Aperçu · Facebook</span>
      <div class="rs-ap rs-ap--fb"><div class="rs-ap-h"><img src="${R}assets/img/logo.png" alt=""><b>L’Atelier By Halle</b><small>${ex('mar. 17 nov. · 07:30')}</small></div><div class="rs-ap-t">Ce matin chez L’Atelier By Halle : ${ex('les viennoiseries sortent du four…')}</div><img class="rs-ap-img" src="${PH(1)}" alt=""></div>
    </aside>
  </div>`;

function page(nom, titre, corps) {
  fs.writeFileSync(path.join(D, nom + '.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard magasin — Réseaux sociaux · ${titre} (maquette)</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css"><link rel="stylesheet" href="final.css"><link rel="stylesheet" href="marketing.css"><link rel="stylesheet" href="reseaux.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail}
</nav>
<div class="workspace__main mk-main">${barre}
  <p class="mk-note"><span class="rf-pill">Maquette · Réseaux sociaux dans le dashboard, sans redirection · rail et barre : chiffres du 15/11 · posts, campagnes, objectifs et statistiques : exemples</span></p>
  <div class="rs-page">${corps}</div>
</div>
</div>
</body></html>
`);
}
page('rs-calendrier', 'Calendrier', ecranCal);
page('rs-meta', 'Calendrier Meta', ecranMeta);
page('rs-instagram', 'Instagram', ecranIG);
page('rs-campagnes', 'Campagnes', ecranCamp);
page('rs-creer', 'Créer un post', ecranCreer);

const PL = [['rs-calendrier', 'Calendrier', 'le calendrier marketing du magasin : campagnes, promotions, newsletters, événements'], ['rs-meta', 'Calendrier Meta', 'les posts Facebook et Instagram du mois, « Ma semaine »'],
  ['rs-instagram', 'Instagram', 'la grille du compte, ce qui est programmé, les 28 derniers jours'], ['rs-campagnes', 'Campagnes', 'les campagnes en cours et leurs objectifs produits, à venir, terminées'], ['rs-creer', 'Créer un post', 'le modèle, la photo, le texte, où et quand ; l’aperçu ; la validation du réseau']];
fs.writeFileSync(path.join(D, 'planche-reseaux.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Réseaux sociaux — planche</title>
<link rel="stylesheet" href="${R}assets/ds/global.css"><link rel="stylesheet" href="rf.css"></head>
<body><div class="rf-planche" style="grid-template-columns:repeat(3, 1fr)">${PL.map(([l, t, s]) => `<figure><figcaption>${t}<small>${s}</small></figcaption><img src="${l}.png" alt="${t}"></figure>`).join('')}</div></body></html>
`);
console.log('rs-calendrier, rs-meta, rs-instagram, rs-campagnes, rs-creer (.html), planche-reseaux.html');
