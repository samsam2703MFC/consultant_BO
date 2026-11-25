/* Maquettes « Clients » (25/11/2026) : le module Klienci du panel TF Buddy (Klienci indywidualni, Firmy B2B, Biura,
 * Oferty, Analiza klientów B2B) dans le dashboard franchisé. Une entrée de rail, Vendre › Clients, quatre onglets ;
 * les offres restent la page Offres du dashboard (« Préparer une offre » y mène). Magasin 3, Gosselies, données fictives.
 *   ?v=b2b · ?v=bureaux · ?v=ajout · ?v=analyse · ?v=particuliers */
(() => {
  const D = window.MQ, eur = D.eur;
  const V = (new URLSearchParams(location.search).get('v') || 'b2b').toLowerCase();
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const P = {
    tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z', commandes: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2 2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2M9 12h6M9 16h4',
    controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11', equipe: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', perf: 'M3 17l6-6 4 4 8-8M15 7h6v6', fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
    loupe: 'M3 3v18h18M7 15l4-5 3 3 5-7', bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8', offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
    offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5', clients: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2', pub: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z', etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
    mail: 'M3 5h18v14H3zM3 6l9 7 9-7', ia: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h8M8 13h5', alerte: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    plus: 'M12 5v14M5 12h14', camion: 'M1 5h13v11H1zM14 9h4l4 4v3h-8M5.5 18.5a1.8 1.8 0 1 0 0-.01M17.5 18.5a1.8 1.8 0 1 0 0-.01', chev: 'M9 18l6-6-6-6',
  };
  const ic = (k, cls = 'ic') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true"><path d="${P[k]}"/></svg>`;
  const pin = (n, abs) => `<span class="pin${abs ? ' abs' : ''}">${n}</span>`;
  const legende = (titre, L) => `<div class="legende"><h3>${titre}</h3><ol>${L.map((x, i) => `<li>${pin(i + 1)}<span>${x}</span></li>`).join('')}</ol></div>`;

  function rail() {
    const it = (i, l, x = '') => `<div class="sidebar__item${x === 'on' ? ' is-active' : ''}${x === 'ia' ? ' is-ia' : ''}">${ic(i, 'ico')}<span>${l}</span>${x === 'new' ? '' : ''}</div>`;
    return `<nav class="sidebar"><div class="sidebar__head"><img class="sidebar__logo" src="../../../public/tablette/img/logo.png" alt=""><div class="sidebar__kicker">Dashboard magasin</div></div>
      <div class="rcarte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">54 %<small>de 1 420 €</small></div><div class="barre"><i style="width:54%"></i></div><div class="s">772 € · trait : projection 1 498 €</div></div>
      <div class="sidebar__groups"><div class="sidebar__group">${it('mail', 'Boîte mail')}${it('ia', 'Assistant IA', 'ia')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">La journée</div>${it('tdb', 'Opérationnel')}${it('commandes', 'Commandes')}${it('controle', 'Tâches')}${it('equipe', 'Mon équipe')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Les chiffres</div>${it('cal', 'Semaine')}${it('cal', 'Mois')}${it('perf', 'Année')}${it('fonds', 'Trésorerie')}</div>
      <div class="sidebar__group rel"><div class="sidebar__group-label">Vendre</div>${it('loupe', 'Analyse')}${it('bundles', 'Produits')}${it('offres', 'Promotions')}${it('clients', 'Clients', 'on')}${it('offre', 'Offres')}${it('analyse', 'Concurrence')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Marketing</div>${it('pub', 'Mon marketing')}${it('etoile', 'Fidélité')}</div></div></nav>`;
  }
  function tete(on, action) {
    const o = (k, l, n) => `<span class="${on === k ? 'is-on' : ''}">${l}${n != null ? ` <em>${n}</em>` : ''}</span>`;
    return `<div class="tete" style="margin-bottom:10px"><div><p class="tete__kicker">${esc(D.MAGASIN.nom)}</p><h1>Clients</h1></div>${action || ''}</div>
      <div class="onglets rel">${on === 'b2b' ? pin(1, true) : ''}${o('b2c', 'Particuliers', '1 170')}${o('b2b', 'Entreprises B2B', 162)}${o('bur', 'Bureaux', 13)}${o('an', 'Analyse B2B')}<span class="droite">Les offres : Vendre › Offres</span></div>`;
  }

  /* Les entreprises (fictives) : les bureaux de la maquette Commandes et quelques autres. */
  const ENT = [
    ['Hainaut Assurances', 'BE0412.338.901', 'A', 'Standard', 'Livraison', 46, 5040, [2500, 1550], 'Charleroi', true],
    ['Centre de formation Horizon', 'BE0789.114.256', 'A', 'Standard', 'Livraison', 61, 7980, null, 'Jumet', true],
    ['Fiduciaire Delvaux & associés', 'BE0455.902.117', 'A', 'VIP', 'Les deux', 52, 6120, [3000, 820], 'Gosselies', true],
    ['Techni-Sol SRL', 'BE0634.551.090', 'B', 'Standard', 'Livraison', 34, 2410, null, 'Jumet', true],
    ['Atelier numérique Charleroi', 'BE0711.004.663', 'B', 'Standard', 'Livraison', 29, 2215, [1500, 1260], 'Charleroi', true],
    ['Bureau d’études Lambot', 'BE0567.203.448', 'B', 'Standard', 'Livraison', 22, 1940, null, 'Courcelles', true],
    ['Brasserie du Centre', 'BE0598.771.205', 'B', 'Restaurant', 'Magasin', 18, 3360, null, 'Gosselies', false],
    ['Biolab Hainaut', 'BE0822.660.391', 'C', 'Standard', 'Livraison', 9, 420, null, 'Gosselies', true],
    ['Garage Dumont', 'BE0477.318.552', 'C', 'Standard', 'Livraison', 6, 312, [500, 520], 'Courcelles', true],
    ['Hôtel du Parc', '', 'D', 'Hôtel', 'Magasin', 2, 96, null, 'Charleroi', false],
  ];
  const credit = c => { if (!c) { return '<span class="muted" style="font-size:12px">—</span>'; } const p = Math.round(100 * c[1] / c[0]), col = p >= 100 ? '#A32014' : p >= 80 ? 'var(--prize)' : p >= 50 ? '#1B6FA8' : 'var(--green)';
    return `<div class="credit"><div class="b"><i style="width:${Math.min(100, p)}%;background:${col}"></i></div><div class="t">${eur(c[1]).replace(',00', '')} / ${eur(c[0]).replace(',00', '')} · ${p} %${p >= 100 ? ' · dépassé' : ''}</div></div>`; };

  function vueB2B() {
    const lignes = ENT.map((e, i) => `<tr class="${i === 0 ? 'sel' : ''}"><td>${esc(e[0])}${!e[1] ? ` <span class="tag-warn">${ic('alerte')} à compléter</span>` : ''}<span class="sub">${e[1] || 'pas de TVA'} · ${e[8]}</span></td>
      <td><span class="score ${e[2]}">${e[2]}</span></td><td>${e[3]}<span class="sub">${e[4]}</span></td><td class="num">${e[5]}<span class="sub">commandes</span></td>
      <td class="num">${eur(e[6]).replace(',00', '')}</td><td>${credit(e[7])}</td><td class="num"><span class="btn btn--sm">Offre</span></td></tr>`).join('');
    const e = ENT[0];
    const fiche = `<div class="card fiche rel">${pin(3, true)}<div class="fiche__tete"><div class="fiche__nom">${esc(e[0])} <span class="score A">A</span></div><div class="fiche__meta">${e[1]} · client depuis mars 2025 · paiement différé à 14 jours · remise 5 %</div></div>
      <div class="fiche__kpis"><div><div class="v">46</div><div class="k">Commandes 12M</div></div><div><div class="v">5 040 €</div><div class="k">CA 12M</div></div><div><div class="v">~ 7 j</div><div class="k">Cycle d’achat</div></div>
        <div><div class="v">Standard</div><div class="k">Type</div></div><div><div class="v">Livraison</div><div class="k">Mode</div></div><div><div class="v">62 %</div><div class="k">Crédit utilisé</div></div></div>
      <div class="fiche__bloc"><h4>Crédit<span>1 550 € sur 2 500 €</span></h4>${credit([2500, 1550])}</div>
      <div class="fiche__bloc"><h4>Ses bureaux<span>livrés par une tournée</span></h4><div class="chaine"><span>Hainaut Assurances</span><i>›</i><span>Lunchroom, bd Tirou</span><i>›</i><span>${ic('camion')} Midi · Gosselies – Charleroi</span></div></div>
      <div class="fiche__bloc"><h4>Dernières commandes<span>12 mois</span></h4><table class="miniT"><tbody>
        <tr><td>24/11</td><td>14 lunchs</td><td class="num">118,30 €</td><td class="num"><span class="chip st-pret">Prête</span></td></tr>
        <tr><td>19/11</td><td>12 lunchs</td><td class="num">101,40 €</td><td class="num"><span class="chip st-ok">Retirée</span></td></tr>
        <tr><td>17/11</td><td>Réunion · 20 pers.</td><td class="num">164,00 €</td><td class="num"><span class="chip st-ok">Retirée</span></td></tr></tbody></table></div>
      <div class="fiche__bloc acts"><span class="btn btn--primary btn--sm">Préparer une offre</span><span class="btn btn--sm">Données de base</span><span class="btn btn--sm">Profil commercial</span></div></div>`;
    return tete('b2b', `<span class="btn btn--primary">${ic('plus')}Ajouter une entreprise</span>`) + `<div class="cl2"><div>
      <div class="alerte rel" style="margin:0 0 12px">${pin(2, true)}${ic('alerte')}<div class="txt">9 entreprises ont des données incomplètes (TVA, adresse, ou ni e-mail ni téléphone) : leurs factures et leurs offres ne peuvent pas partir.</div><span class="btn btn--sm">Les afficher</span></div>
      <div class="card tab-card"><div class="filtres"><input value="" placeholder="Société, TVA, téléphone…">
        <span class="grp">Scoring <span class="seg"><span class="is-on">Tous</span><span>A</span><span>B</span><span>C</span><span>D</span></span></span>
        <span class="grp">CA 12M <span class="seg"><span class="is-on">Tous</span><span>&lt; 1 k</span><span>1–5 k</span><span>&gt; 5 k</span></span></span>
        <span class="grp">Commandes <span class="seg"><span class="is-on">Toutes</span><span>1–5</span><span>6–19</span><span>20 +</span></span></span></div>
        <table><thead><tr><th>Société</th><th>Score</th><th>Type · mode</th><th class="num">Fréquence 12M</th><th class="num">CA 12M</th><th>Crédit</th><th></th></tr></thead><tbody>${lignes}</tbody></table></div>
      ${legende('Ce qui change par rapport au panel', [
        '<b>Une entrée, quatre onglets</b> (Particuliers, Entreprises B2B, Bureaux, Analyse B2B) au lieu de cinq entrées de menu ; les offres restent la page Offres du dashboard, ouverte depuis chaque fiche (« Préparer une offre »).',
        '<b>Ce qui bloque, en tête</b> : les entreprises à compléter (TVA, adresse, contact) se disent par ce qu’elles empêchent, avec le bouton pour les afficher.',
        '<b>La fiche à côté de la liste</b>, pas une ligne qui se déplie : le score, le CA, le cycle d’achat, le crédit (vert, bleu, or, rouge au-dessus de 100 %) et la chaîne société › bureau › site › tournée, qui relie Clients à Commandes.',
        '<b>Le design system du cockpit</b> (Gotham, bordeaux, filets, pas de gras ni de pilules) à la place de Bootstrap et Mazer ; les mêmes routes de l’API (/panel/shops/{shop}/b2b/clients…, vat-lookup, profile, attributes).',
      ])}</div>${fiche}</div>`;
  }

  function vueBureaux() {
    const B = ['hainaut', 'horizon', 'numerique', 'veto', 'urbanisme', 'delvaux', 'aerotech', 'renard', 'biolab', 'technisol', 'mutualis', 'lambot', 'dumont'];
    const stat = i => i === 4 ? '<span class="tag-warn">En attente</span>' : '<span class="chip st-ok">Actif</span>';
    const tn = k => (D.TOURNEES.find(t => t.bureaux.includes(k)) || {}).nom || '—';
    const lignes = B.map((k, i) => { const b = D.BUREAUX[k], t = D.TOURNEES.find(x => x.bureaux.includes(k)); return `<tr class="${i === 0 ? 'sel' : ''}"><td>${esc(b.nom)}<span class="sub">${esc(b.zone)} · ${i % 3 === 0 ? 'facturé à la société' : 'chaque personne paie'}</span></td>
      <td>${stat(i)}</td><td>${i % 4 === 0 ? 'Réduit<span class="sub">38 produits</span>' : 'Complet'}</td><td class="num">${i % 3}</td><td class="num">${b.pers}</td>
      <td>${i === 4 ? '<span class="tag-warn">pas de site</span>' : `<span class="tournee-chip">${ic('camion')}${esc(t ? t.court + ' ' + t.depart : '—')}</span>`}</td></tr>`; }).join('');
    const b = D.BUREAUX.hainaut;
    const det = `<div class="card fiche rel">${pin(2, true)}<div class="fiche__tete"><div class="fiche__nom">${esc(b.nom)} <span class="chip st-ok">Actif</span></div><div class="fiche__meta">Société : Hainaut Assurances · 40 personnes · facturé à la société</div></div>
      <div class="ong2"><span class="is-on">Livraison</span><span>Catalogue</span><span>Offres</span><span>Personnes</span><span>Documents</span></div>
      <div class="fiche__bloc"><h4>Site par défaut</h4><div class="ligne-f"><span>Adresse</span><span>Lunchroom, bd Tirou, Charleroi</span></div><div class="ligne-f"><span>Accès</span><span>Badge à l’accueil · 5 min</span></div></div>
      <div class="fiche__bloc"><h4>Livraison</h4><div class="ligne-f"><span>Tournée</span><span class="tournee-chip">${ic('camion')}Midi · Gosselies – Charleroi</span></div>
        <div class="ligne-f"><span>Créneau</span><span>12:00 – 12:30</span></div><div class="ligne-f"><span>Commandes jusqu’à</span><span>16:00 la veille <span class="muted">(celle de la tournée)</span></span></div>
        <div class="ligne-f"><span>Remise sur place</span><span>5 min · validation par QR</span></div><div class="ligne-f"><span>Jours</span><span>du lundi au vendredi</span></div></div>
      <div class="fiche__bloc"><h4>Webshop</h4><div class="ligne-f"><span>Lien d’invitation</span><span>actif jusqu’au 31/12 · 23 inscrits</span></div><div class="ligne-f"><span>Remise webshop</span><span>5 % · livraison offerte dès 60 €</span></div></div>
      <div class="fiche__bloc acts"><span class="btn btn--primary btn--sm">Modifier la livraison</span><span class="btn btn--sm">Inviter des personnes</span><span class="btn btn--sm">Affiche A2</span><span class="btn btn--sm">Cartes de bienvenue</span></div></div>`;
    return tete('bur', `<span class="btn btn--primary rel">${pin(3, true)}${ic('plus')}Ajouter un bureau</span>`) + `<div class="cl2"><div>
      <div class="rel">${pin(1, true)}<div class="card tab-card"><div class="filtres"><input placeholder="Bureau, société, TVA, ville, contact"><span class="grp">Statut <span class="seg"><span class="is-on">Tous 13</span><span>Actifs 12</span><span>En attente 1</span></span></span></div>
        <table><thead><tr><th>Bureau</th><th>Statut</th><th>Catalogue</th><th class="num">Offres</th><th class="num">Personnes</th><th>Tournée</th></tr></thead><tbody>${lignes}</tbody></table></div></div>
      ${legende('Ce qui change par rapport au panel', [
        '<b>Une ligne par bureau, neuf icônes en moins</b> : le panel aligne 12 boutons par ligne (points, personnes, catalogue, offres, brochure, affiche, cartes, invitation, paramètres…) ; ici un clic ouvre le bureau, et ses gestes sont dans sa fiche.',
        '<b>La fiche en onglets</b> — Livraison, Catalogue, Offres, Personnes, Documents — et la livraison d’abord : le site, la tournée, le créneau, l’heure limite, la remise. Ce que la page Commandes et l’appli du livreur lisent.',
        '<b>« Ajouter un bureau »</b> garde l’assistant en 7 étapes du panel (voir l’écran suivant), avec la chaîne société › bureau › site › tournée visible à chaque étape.',
        '<b>Le bureau sans site</b> (Bureau Lefèvre, en attente) se voit dans la liste : il ne peut être livré par aucune tournée tant qu’il n’a pas de point de livraison.',
      ])}</div>${det}</div>`;
  }

  function vueAjout() {
    const et = ['Société', 'Contacts', 'Sites', 'Départements', 'Webshop', 'Personnes', 'Récapitulatif'];
    const ch = (l, v, plein, vide) => `<label class="champ${plein ? ' plein' : ''}">${l}<span class="in${vide ? ' vide' : ''}">${v}</span></label>`;
    return tete('bur') + `<div class="cl2"><div class="card" style="padding:0">
      <div class="fiche__tete"><div class="fiche__nom">Ajouter un bureau · Centre de formation Horizon</div><div class="fiche__meta">Étape 3 sur 7 · ce qui est saisi se garde si l’on revient en arrière</div></div>
      <div class="stepper">${et.map((x, i) => `<div class="${i < 2 ? 'fait' : i === 2 ? 'on' : ''}"><i></i>${i + 1} · ${x}</div>`).join('')}</div>
      <div class="form2">
        <div class="plein" style="font-size:15px">Où livrer ?</div>
        ${ch('Point de livraison', 'Nouveau point · ou choisir un point existant ▾', true)}
        ${ch('Nom du site', 'Jumet · zoning, salle 2')}${ch('Pays', 'Belgique (BE)')}
        ${ch('Adresse', 'Rue de la Station 14 — suggestions Google pendant la saisie', true)}
        ${ch('Code postal', '6040')}${ch('Ville', 'Jumet (Charleroi) ▾')}
        ${ch('Bâtiment · étage', 'B · 1er', false)}${ch('Temps d’accès sur place', '5 min')}
        <div class="plein" style="font-size:15px;margin-top:6px">Livré par</div>
        ${ch('Tournée', `<span style="display:inline-flex;gap:8px;align-items:center">${ic('camion')}Midi · Gosselies – Charleroi (11:15 → 12:40)</span>▾`, true)}
        ${ch('Créneau de livraison', '11:45 – 12:15')}${ch('Validation de la remise', 'QR · signature · dépôt ▾')}
        <label class="champ plein">Jours desservis<span class="jours7"><span class="on">L</span><span class="on">M</span><span class="on">M</span><span class="on">J</span><span class="on">V</span><span>S</span><span>D</span></span></label>
      </div>
      <div class="pied-ass"><span class="btn btn--ghost">Retour</span><span class="muted" style="font-size:12px">Au moins un site avec une adresse, pour pouvoir livrer</span><span class="btn btn--primary">Suivant · Départements</span></div></div>
      <div><div class="card fiche"><div class="fiche__tete"><div class="fiche__nom" style="font-size:15px">Ce que l’on crée</div><div class="fiche__meta">mis à jour à chaque étape</div></div>
        <div class="fiche__bloc"><h4>La chaîne</h4><div class="chaine"><span>Centre de formation Horizon SRL</span><i>›</i><span>Bureau · Horizon Jumet</span><i>›</i><span>Site · zoning, salle 2</span><i>›</i><span>${ic('camion')} Midi · Charleroi</span></div></div>
        <div class="fiche__bloc"><h4>Société</h4><div class="ligne-f"><span>TVA</span><span>BE0789.114.256 · vérifiée VIES</span></div><div class="ligne-f"><span>Segment</span><span>Corporate</span></div><div class="ligne-f"><span>Paiement</span><span>Différé · 14 jours</span></div></div>
        <div class="fiche__bloc"><h4>Contact</h4><div class="ligne-f"><span>Responsable</span><span>Office manager</span></div><div class="ligne-f"><span>E-mail</span><span>renseigné</span></div></div>
        <div class="fiche__bloc"><h4>Ensuite</h4><div class="muted" style="font-size:12px;line-height:1.5">Départements (18 personnes), webshop (remise, prix affichés), les personnes (recherche ou import CSV nom;email), puis le récapitulatif : facturation à la société ou par personne, envoi du résumé et de l’invitation.</div></div></div></div></div>`;
  }

  function vueAnalyse() {
    const k = (l, v, m, y, fort) => `<div class="card k6${fort ? ' fort' : ''}"><div class="l">${l}</div><div class="v">${v}</div><div class="t"><span class="${m >= 0 ? 'up' : 'down'}">${m >= 0 ? '▲ +' : '▼ '}${m} %</span> vs octobre<span class="${y >= 0 ? 'up' : 'down'}">${y >= 0 ? '▲ +' : '▼ '}${y} %</span> vs nov. 2025</div></div>`;
    const cur = [6.2, 6.8, 7.4, 7.1, 7.9, 6.4, 4.8, 5.2, 8.1, 8.6, 9.4, 0], prev = [5.1, 5.4, 6.0, 6.3, 6.6, 5.9, 4.1, 4.6, 6.9, 7.2, 8.0, 7.6];
    return tete('an') + `<div class="alerte" style="margin:0 0 12px">${ic('alerte')}<div class="txt">Seuil B2B du réseau : les tickets pro de moins de 15 € ne comptent pas ici (réglage du panel, lu tel quel).</div></div>
      <div class="barre-jour" style="margin-bottom:12px"><span class="btn btn--icon btn--sm">‹</span><span class="date">Novembre 2026</span><span class="btn btn--icon btn--sm">›</span><span class="ici">mois en cours, jusqu’au 25</span></div>
      <div class="kpi6">${k('Ventes B2B', '7 012 €', 9, 14, true)}${k('Tickets B2B', '212', 6, 11)}${k('Panier moyen B2B', '33,08 €', 3, 2)}${k('Clients B2B uniques', '58', 4, 18)}${k('Part B2B du CA', '21,4 %', 1.2, 3.1)}${k('Factures B2B', '31 · 4 380 €', -5, 7)}</div>
      <div class="an2"><div class="card"><h2 class="bloc">Ventes B2B, mois par mois<small><span class="seg"><span class="is-on">Ventes</span><span>Tickets</span><span>Clients</span><span>Part du CA</span></span></small></h2>
        <div class="barres">${cur.map((c, i) => `<div class="m"><i class="a" style="height:${c * 10}%"></i><i class="p" style="height:${prev[i] * 10}%"></i></div>`).join('')}</div>
        <div class="mois12">${['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'].map(m => `<span>${m}</span>`).join('')}</div>
        <div class="frise__leg" style="margin:10px 0 0"><span><i class="dot" style="background:var(--accent)"></i>2026</span><span><i class="dot" style="background:#EBD3D8"></i>2025</span><span class="muted">décembre 2026 : le mois n’est pas commencé</span></div></div>
        <div class="card"><h2 class="bloc">Part B2B du chiffre</h2><svg viewBox="0 0 120 120" style="width:170px;display:block;margin:10px auto"><circle cx="60" cy="60" r="44" fill="none" stroke="#EDE7DE" stroke-width="18"/><circle cx="60" cy="60" r="44" fill="none" stroke="#8D1D2C" stroke-width="18" stroke-dasharray="${2 * Math.PI * 44 * 0.214} 999" transform="rotate(-90 60 60)"/><text x="60" y="65" text-anchor="middle" font-family="Gotham" font-size="16" fill="#1c1a17">21,4 %</text></svg>
          <div class="ligne-f"><span>B2B</span><span>7 012 €</span></div><div class="ligne-f"><span>Particuliers</span><span>25 755 €</span></div>
          <div class="muted" style="font-size:12px;margin-top:8px;line-height:1.5">Les mêmes chiffres que la jauge B2B de l’Opérationnel, sur le mois : un pro = une fiche avec une TVA valide.</div></div></div>
      ${legende('Ce qui change par rapport au panel', [
        '<b>Les deux comparaisons sur chaque chiffre</b> (le mois d’avant, le même mois l’an dernier), en mots et en flèches, au lieu des flèches seules.',
        '<b>Un mois, une barre de navigation</b> (‹ novembre ›) comme Semaine et Mois du dashboard, au lieu de deux formulaires « Appliquer ».',
        '<b>Le seuil B2B du réseau dit en clair</b>, et la définition du « pro » alignée sur celle du dashboard (TVA valide).',
        '<b>Corrigé au passage</b> : le panel garde les données du premier affichage quand on change de métrique ; ici chaque mois relu change aussi le graphique.',
      ])}`;
  }

  function vueParticuliers() {
    const L = [['Isabelle B.', '+32 4•• •• •• 18', '12 commandes', '5 %'], ['Marc G.', '+32 4•• •• •• 07', '3 commandes', '—'], ['Sophie V.', '+32 4•• •• •• 52', '9 commandes', '—'], ['Laura M.', '+32 4•• •• •• 33', '1 commande', '—'], ['Céline D.', '+32 4•• •• •• 90', '6 commandes', '10 %'], ['Anne P.', '+32 4•• •• •• 21', '2 commandes', '—']];
    const lignes = L.map((x, i) => `<tr class="${i === 0 ? 'sel' : ''}"><td>${x[0]}<span class="sub">${x[2]}</span></td><td>${x[1]}</td><td class="num">${x[3]}</td><td class="num"><span class="btn btn--sm">Offre</span></td></tr>`).join('');
    const fiche = `<div class="card fiche rel">${pin(2, true)}<div class="fiche__tete"><div class="fiche__nom">Isabelle B.</div><div class="fiche__meta">cliente depuis 2024 · carte de fidélité active</div></div>
      <div class="fiche__kpis"><div><div class="v">12</div><div class="k">Commandes</div></div><div><div class="v">284 €</div><div class="k">12 mois</div></div><div><div class="v">5 %</div><div class="k">Remise</div></div></div>
      <div class="fiche__bloc"><h4>Coordonnées</h4><div class="ligne-f"><span>Téléphone</span><span>+32 4•• •• •• 18</span></div><div class="ligne-f"><span>E-mail</span><span>renseigné</span></div><div class="ligne-f"><span>Adresse</span><span>6041 Gosselies</span></div></div>
      <div class="fiche__bloc"><h4>Comment elle achète<span>la fiche Fidélité</span></h4><div class="part" style="margin:6px 0"><i style="width:55%;background:#B0792A"></i><i style="width:30%;background:#8D1D2C"></i><i style="width:15%;background:#1B6FA8"></i></div><div class="muted" style="font-size:11.5px">comptoir 55 % · commandes 30 % · webshop 15 %</div></div>
      <div class="fiche__bloc acts"><span class="btn btn--primary btn--sm">Préparer une offre</span><span class="btn btn--sm">Modifier</span><span class="btn btn--sm rel">${pin(3, true)}Passer en entreprise</span><span class="btn btn--sm btn--danger">Supprimer</span></div>
      <div class="fiche__bloc" style="background:#FFFBF4"><h4>Passer en entreprise</h4><div class="muted" style="font-size:12px;line-height:1.5">Le numéro de TVA se vérifie dans VIES et remplit la société et l’adresse ; la fiche passe dans Entreprises B2B avec ses commandes, ses conditions de paiement et sa remise.</div></div></div>`;
    return tete('b2c', `<span class="btn btn--primary">${ic('plus')}Ajouter un client</span>`) + `<div class="cl2"><div>
      <div class="rel">${pin(1, true)}<div class="card tab-card"><div class="filtres"><input placeholder="Nom, téléphone, e-mail"><span class="grp">Avec <span class="seg"><span class="is-on">Tous</span><span>Une remise</span><span>Une carte de fidélité</span></span></span></div>
        <table><thead><tr><th>Client</th><th>Téléphone</th><th class="num">Remise</th><th></th></tr></thead><tbody>${lignes}</tbody></table></div></div>
      ${legende('Ce qui change par rapport au panel', [
        '<b>La recherche d’abord</b> : 1 170 particuliers ne se parcourent pas ; le téléphone n’est montré qu’en partie dans la liste, en entier dans la fiche.',
        '<b>La fiche à côté</b>, reliée à la Fidélité du dashboard (comment le client achète), au lieu d’une fenêtre de modification seule.',
        '<b>« Passer en entreprise »</b> garde le geste du panel (TVA vérifiée dans VIES, la fiche devient B2B) ; « Supprimer » reste discret et demande une confirmation.',
        '<b>Les mêmes routes</b> : GET /shops/{shop}/clients?type=b2c, POST /shops/{shop}/clients (nom et téléphone obligatoires), PATCH et DELETE /clients/{id}.',
      ])}</div>${fiche}</div>`;
  }

  const corps = V === 'bureaux' ? vueBureaux() : V === 'ajout' ? vueAjout() : V === 'analyse' ? vueAnalyse() : V === 'particuliers' ? vueParticuliers() : vueB2B();
  document.getElementById('app').innerHTML = `<div class="workspace">${rail()}<main class="main">${corps}</main></div>`;
})();
