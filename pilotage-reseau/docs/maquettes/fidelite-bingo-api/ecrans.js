// Cinq écrans : deux dans l'admin de l'ERP (seule la carte à points y reste), trois dans le cockpit
// (ERP franchisé › Fidélité : le bingo, calculé par l'API de l'ERP, sans table).
'use strict';

const eur = (n) => n.toFixed(2).replace('.', ',') + ' €';

// ── Coquilles ───────────────────────────────────────────────────────────
function railErp() {
  const it = (ic, t, cur, sub) => `<li><a ${cur ? 'aria-current="page"' : ''}>${ico(ic)}<span>${t}</span>${sub ? '<span class="chev">⌄</span>' : ''}</a></li>`;
  return `<nav class="rail"><img class="logo" src="${LOGO}" alt="L’Atelier by"><input value="Rechercher une page…" readonly><ul>
    ${it('grid', 'Tableau de bord')}${it('shop', 'Franchise', 0, 1)}${it('mega', 'Campagnes globales')}${it('card', 'Fidelity', 1)}
    ${it('book', 'Menu', 0, 1)}${it('tag', 'Promotions', 0, 1)}<li><a class="s">Bons d’achat</a></li>${it('users', 'Utilisateurs', 0, 1)}${it('gear', 'Paramètres', 0, 1)}</ul></nav>`;
}
function railCockpit() {
  const sec = (t) => `<li class="sec">${t}</li>`, s = (t, cur, b) => `<li><a class="s" ${cur ? 'aria-current="page"' : ''}>${t}${b ? `<span class="badge-r">${b}</span>` : ''}</a></li>`;
  return `<nav class="rail"><img class="logo" src="${LOGO}" alt="L’Atelier by"><input value="Rechercher une page…" readonly><ul>
    ${['Pilotage', 'Franchisés', 'Magasins', 'Vérification', 'Produits', 'Promotions', 'Marque & marketing'].map(sec).join('')}
    ${sec('ERP franchisé')}${s('Prospection ↗')}${s('MétaDonnée')}${s('Tablette vendeuses')}${s('Fidélité', 1, 4)}${s('Assistant · coachs')}
    ${['Application employé', 'Formation', 'Administration'].map(sec).join('')}</ul></nav>`;
}
function ecran(n, ou, titre, src, rail, main, extra = '') {
  document.body.insertAdjacentHTML('beforeend', `<section class="ecran" id="e${n}">
    <div class="annot"><span class="n">${n}</span><div><div class="t"><span style="color:var(--abricot)">${ou}</span> · ${titre}</div><div class="src">${src}</div></div>
      <div class="leg">Aucune table, aucun champ ajouté · ${v('champ ou route existant')}</div></div>
    <div class="app">${rail}<div class="main"><div class="topbar"><span class="burger">${ico('menu')}</span><span class="sp">FR</span><span class="av">AB</span></div>
      <div class="content">${main}</div>${extra}</div></div></section>`);
}

// ── 1 · ERP › Cartes de fidélité : une seule carte, à points ──────────
function erpListe() {
  const ligne = (th, nom, code, per, reg, rec, val, on, info) => `<tr><td><div class="cardcell">${th}<div><strong>${nom}</strong><span class="code">${code}</span></div></div></td>
    <td>${per}</td><td>${reg}</td><td>${rec}</td><td>${val}</td><td><span class="status ${on ? 'on' : ''}"><i></i>${on ? 'Configuration active' : 'Brouillon / inactive'}</span><small>${info}</small></td>
    <td><div class="rowact"><span class="btn sm">${ico('eye')} Aperçu</span><span class="btn sm">${ico('sliders')} Correspondance</span><div class="dup"><span class="btn sm">${ico('pencil')} Modifier</span><span class="btn sm">${ico('dots')}</span></div></div></td></tr>`;
  return `<div class="ph"><div class="kick">Fidelity</div><h1>Cartes de fidélité</h1><p>Configuration du programme et des récompenses clients.</p></div>
  <div class="notice" style="margin-top:16px">${ico('info')} Une configuration active ne confirme pas l’activation de la collecte des tampons.</div>
  <div class="top"><div class="stats">
    <div><span class="ic">${ico('card')}</span><div><strong><b>2</b> cartes</strong><small>Cartes de fidélité configurées</small></div></div>
    <div><span class="ic">${ico('gear')}</span><div><strong><b>1</b> configuration active</strong><small>Parmi toutes les cartes</small></div></div>
    <div><span class="ic">${ico('shop')}</span><div><strong>Portée : réseau</strong><small>1 carte couvre tous les magasins</small></div></div>
  </div><span class="btn pri big">${ico('plus', 18)} Nouvelle carte</span></div>
  <section class="panel"><h2>Liste des cartes de fidélité</h2><p>Gérez les cartes, modifiez les règles, vérifiez la correspondance ou ajoutez une carte.</p>
    <div class="filters"><div class="fbox" style="display:flex;align-items:center;gap:10px">${ico('search')}<div style="color:#9a9188">Rechercher par nom ou code…</div></div>
      <div class="fbox">Statut<div>Tous <span>⌄</span></div></div><div class="fbox">Périmètre<div>Tous <span>⌄</span></div></div><span class="btn">${ico('search')} Filtrer</span></div>
    <div class="twrap"><table><thead><tr><th style="width:28%">Carte / code</th><th style="width:11%">Périmètre</th><th style="width:15%">Règles</th><th style="width:16%">Récompense</th><th style="width:8%">Validité</th><th style="width:14%">Statut</th><th style="width:150px">Actions</th></tr></thead><tbody>
      ${ligne(`<span class="thumb r"><img src="${ILL}croissant.png" alt=""></span>`, CARTE_PTS.name, CARTE_PTS.code, 'Tous les magasins', '<small>Carte à points : 1 / 1 €</small>', '<small>100 → 5 €</small>', '30 jours', 1, 'Début : 23/11/2026 · sans date de fin')}
      ${ligne(`<span class="thumb"><img src="${ILL}chocolat.png" alt=""></span>`, 'Carte café', 'cafe-10', 'Magasins sélectionnés', '10 tampons', 'Un café offert', '30 jours', 0, 'Fin : 22/11/2026, remplacée par le bingo')}
    </tbody></table></div>
    <div class="pag">Afficher par page : <span class="inp" style="width:auto;min-height:28px;padding:3px 9px">10 ⌄</span><span>1–2 sur 2</span><span class="btn sm dis">${ico('left')}</span><span class="btn sm dis">${ico('right')}</span></div></section>
  <div class="info nm" style="margin-top:0">${ico('info', 18)}<div>Note de maquette : le bingo n’est pas une carte de l’ERP.<p>Ses bons sont des codes des campagnes « Bingo hiver · 2 € » et « Bingo hiver · 10 € », dans Promotions › Bons d’achat. Le cockpit les crée chaque lundi au nom des clients, d’après leurs achats de la semaine lus dans l’ERP.</p></div></div>`;
}

// ── 2 · ERP › Modifier la carte à points (étape 2) ────────────────────
function erpCarte() {
  const pc = CARTE_PTS.points_config, b = pc.bonuses[0];
  const champ = (label, ann, val, help = '', cls = '', req = true) => `<div class="field"><label>${label}${req ? '<span class="req"> *</span>' : ''}${ann}</label><div class="inp ${cls}">${val}</div>${help ? `<small>${help}</small>` : ''}</div>`;
  const reg = (n, u) => `<b>${n}</b><span class="u">${u}</span>`;
  const ed = `<div class="panel"><h2>Type de carte</h2><p>Une seule carte, à points. Les cartes à tampons s’arrêtent : le bingo prend leur place.</p>
    <div class="moteurs"><div class="mot off"><span class="ck"><span class="box"></span>Carte produit</span><div class="titre">TAMPONS</div><div class="sous">non utilisée</div><p>Règles du programme et produits offerts masqués.</p><div style="margin-top:9px">${v('points_config.stamps_enabled')}</div></div>
      <div class="mot plein"><span class="ck"><span class="box on"></span>Carte à points</span><div class="titre">POINTS</div><div class="sous">Fait consommer · le panier</div><p>Chaque euro compte, sans plafond. La case du bingo, elle, se lit dans les achats de la semaine.</p><div style="margin-top:9px">${v('points_config.points_enabled')}</div></div></div></div>
  <div class="panel"><h2>Règle de gain</h2><p>Points sur les produits du programme après réductions et bons. Hors livraison, frais et cadeaux.</p>
    <div class="grid4">${champ('Points par tranche', v('points_per_slice'), reg(1, 'pt'), '', 'set')}${champ('Tranche de dépenses (€)', v('slice_cents'), reg('1,00', '€'), '1 point par euro, arrondi à l’euro inférieur.', 'set')}
      ${champ('Validité du bon (jours)', v('voucher_validity_days'), reg(30, 'jours'), '', 'set')}${champ('Validité des points (jours)', v('points_validity_days'), reg(365, 'jours'), 'Les points survivent à la fin de la saison.', 'set')}</div>
    <h3>Boutiques et coefficients ${v('shop_coefficients')}</h3><div class="grid4">${SHOPS.map(([n, id]) => champ(`${n} (#${id})`, '', reg(1, 'coef.'), '', 'set', false)).join('')}</div>
    <h3>Bonus jour et heure ${v('bonuses')}</h3>
    <div class="rows"><div class="row" style="grid-template-columns:1.7fr .7fr .7fr .7fr auto auto">${champ('Nom', '', b.name, '', '', false)}${champ('De', '', b.from, '', '', false)}${champ('À', '', b.to, '', '', false)}${champ('Coefficient', '', b.coefficient, '', '', false)}
      <div><div class="flabel">Jours : lundi–dimanche</div><div class="days">${[0, 1, 2, 3, 4, 5, 6].map((d) => `<span class="${b.days.includes(d) ? 'on' : ''}">${d + 1}</span>`).join('')}</div></div><span class="btn sm">Supprimer</span></div></div>
    <h3>Paliers de récompense ${v('tiers')}</h3>
    <div class="rows"><div class="row" style="grid-template-columns:1fr 1fr 1fr auto">${champ('Solde de points', '', '100', '', '', false)}${champ('Montant (€)', '', '5,00', '', '', false)}<span class="rate">Retour : 5,00 %</span><span class="btn sm">Supprimer</span></div></div>
    <div class="sim"><h3 style="margin-top:0">Simulateur d’achat</h3><div class="grid3">${champ('Montant (€)', '', '7,50', '', '', false)}${champ('Choisir les boutiques', '', 'Halle (#4)<span class="caret">⌄</span>', '', '', false)}${champ('Date et heure locale de la boutique', '', '13/01/2027 15:30', '', '', false)}</div>
      <div class="simres"><p><code>7 × 1 × 1 × 2 = 14</code> &nbsp;tranches × points par tranche × coefficient boutique × coefficient bonus</p></div></div></div>
  <div class="panel"><h2>Disponibilité du programme</h2><p>Définissez les dates et les boutiques participantes.</p>
    <div class="grid3">${champ('Date de début', v('starts_at'), '22/11/2026 23:00:00', 'Lundi 23/11 00:00, heure belge. UTC.')}${champ('Date de fin', v('ends_at'), '<span class="ph">Sans date de fin</span>', 'Carte permanente : les saisons du bingo passent, la carte reste.', '', false)}${champ('Périmètre', v('shop_selection'), 'Tous les magasins<span class="caret">⌄</span>', '')}</div></div>
  <div class="panel"><h2>Sociétés liées <span class="etat at" style="font-family:var(--font);font-size:11px"><i></i>ajout demandé</span></h2><p>Lier la carte à points à une ou plusieurs sociétés, choisies dans la liste des bureaux du magasin. La liste existe dans l’ERP ${v('GET /panel/shops/{shop}/offices')} (société, bureau, ville, sites, statut). La carte n’a pas encore de champ pour garder le lien : ${'<code class="var new">office_ids · à créer dans l’ERP</code>'}</p>
    <div class="toolbar"><div class="inp" style="max-width:200px">Halle (#4)<span class="caret">⌄</span></div><div class="inp">${ico('search')} <span class="ph">Rechercher une société ou un bureau…</span></div><span class="count">2 sur 5 liées</span></div>
    <div class="twrap"><table><thead><tr><th style="width:36px"><span class="box"></span></th><th>Société</th><th>Bureau</th><th>Ville</th><th>Sites</th><th>Statut</th></tr></thead><tbody><tr class="sel"><td><span class="box on"></span></td><td>Société Démo Logistique SA</td><td>Bureau Halle-Centre</td><td>Halle</td><td>2</td><td><span class="etat ok"><i></i>active</span></td></tr><tr class="sel"><td><span class="box on"></span></td><td>Démo Santé ASBL</td><td>Bureau de la gare</td><td>Halle</td><td>1</td><td><span class="etat ok"><i></i>active</span></td></tr><tr class=""><td><span class="box "></span></td><td>Démo Industries SRL</td><td>Siège</td><td>Tubize</td><td>3</td><td><span class="etat ok"><i></i>active</span></td></tr><tr class=""><td><span class="box "></span></td><td>Démo Conseil SA</td><td>Bureau Nord</td><td>Halle</td><td>1</td><td><span class="etat no"><i></i>en attente</span></td></tr><tr class=""><td><span class="box "></span></td><td>École Démo</td><td>Administration</td><td>Lembeek</td><td>1</td><td><span class="etat ok"><i></i>active</span></td></tr></tbody></table></div>
    <small class="hint" style="margin-top:8px">Sociétés et bureaux fictifs. Ce que le lien déclenche (carte réservée aux salariés, commandes du bureau qui comptent, points au nom de la société) reste à préciser.</small></div>`;
  return `<div class="ph"><h1>Modifier la carte</h1><nav class="crumb"><a>Fidelity</a><span>/</span><a>Cartes Fidelity</a><span>/</span><span>Modifier la carte</span></nav></div>
    <nav class="steps"><div class="done"><span class="n">✓</span><span>1. Informations générales</span></div><div class="cur"><span class="n">2</span><span>2. Règles et récompense</span></div><div><span class="n">3</span><span>3. Boutiques et produits</span></div><div><span class="n">4</span><span>4. Apparence et récapitulatif</span></div></nav>
    <div class="ws"><div>${ed}</div><aside class="prev"><div class="panel"><h2>Aperçu de la carte</h2><p>Voici le projet de votre carte de fidélité.</p>${apercuPoints(CARTE_PTS)}
      <div class="info">${ico('info', 18)}<div>Aperçu en direct<p>Vos modifications apparaissent immédiatement.</p></div></div></div></aside></div>
    <footer class="actions"><span class="btn">← Retour</span><span class="note">La carte est enregistrée avec sa ${v('revision')} : une modification concurrente est refusée.</span><span class="btn">Enregistrer le brouillon</span><span class="btn pri">Suivant : Boutiques et produits →</span></footer>`;
}

// ── En-tête du cockpit › Fidélité ─────────────────────────────────────
const tete = (onglet) => `<div class="ph"><div class="kick">ERP franchisé › <b>Fidélité</b></div><h1>Bingo + points</h1>
  <p class="lead">Le <b>bingo</b> fait revenir, les <b>points</b> font consommer. La carte à points vit dans l’ERP ; le bingo se calcule ici, par l’API de l’ERP, sans table.</p></div>
  <div style="display:flex;align-items:center;gap:14px"><div class="tabs">${['Programme', 'Magasins', 'Un client'].map((t) => `<span class="${t === onglet ? 'on' : ''}">${t}</span>`).join('')}</div><span class="date">${ico('info')} jeudi 14/01/2027 · semaine 8 / 13 · 11/01 – 17/01</span></div>`;

// ── 3 · Cockpit › Fidélité › Programme ─────────────────────────────────
function programme() {
  const piece = (k, etat, titre, txt, api) => `<div class="piece"><div class="k">${k}${etat}</div><strong>${titre}</strong><p>${txt}</p><div class="api">${api}</div></div>`;
  const ok = (t) => `<span class="etat ok"><i></i>${t}</span>`, at = (t) => `<span class="etat at"><i></i>${t}</span>`;
  const saisons = SAISONS.map((s, i) => `<div class="saison ${i === 0 ? 'on' : ''}"><img src="${ILL}${s.ill}.png" alt=""><h4>${s.nom}</h4><div class="d">${s.du} → ${s.au}</div>
    <span class="et ${i ? 'm' : ''}">${s.etat}</span><div class="chips"><span>${s.camp} · 2 €</span><span>${s.camp} · 10 €</span></div></div>`).join('');
  const main = `${tete('Programme')}
  <div class="kick" style="margin:4px 0 10px"><b>Dans l’ERP</b> · lu par l’API, rien n’est gardé ici</div>
  <div class="pieces">
    ${piece('Carte à points', ok('active'), 'Carte points L’Atelier', '<code>points-atelier</code> · v1 · 1 pt / 1 € · 100 pts → 5 € · tous les magasins', 'GET /admin/loyalty/cards')}
    ${piece('Campagnes de la saison', at('1 à activer'), 'Bingo hiver · 2 € <span class="etat ok"><i></i>active</span><br>Bingo hiver · 10 € <span class="etat at"><i></i>brouillon</span>', 'Le super bon arrive en semaine 13 : activez sa campagne dans Promotions › Bons d’achat.', 'GET /admin/voucher-campaigns')}
    ${piece('Bons du lundi', ok('à jour'), '37 codes créés lundi 11/01', 'Bons de la 6e case (Bon des Rois). Prochain passage : lundi 18/01, 06:00.', 'POST /admin/voucher-campaigns/{id}/codes')}
    ${piece('Cartes à tampons', ok('arrêtée'), 'Carte café · fin le 22/11', 'Les tampons déjà gagnés restent utilisables jusqu’à leur échéance.', 'PATCH /admin/loyalty/cards/{id}')}
  </div>
  <div class="cols" style="margin-top:18px"><div>
    <section class="panel"><h2>Les règles du bingo</h2><p>Les valeurs de départ du handoff, réglées dans le fichier de saison ${v('src/data/fidelite_bingo.json')}. À valider par la direction.</p>
      <div class="vals2"><div><span>Seuil de la semaine</span><strong>10<small>€ du lundi au dimanche</small></strong></div><div><span>Un bon toutes les</span><strong>3<small>cases cochées</small></strong></div><div><span>Valeur des bons</span><strong>2 €<small>× 4 bons</small></strong></div>
        <div><span>Super bon cadeau</span><strong>10 €<small>13 / 13 · provisoire</small></strong></div><div><span>Validité des bons</span><strong>30<small>jours</small></strong></div><div><span>Palier de points</span><strong>100<small>pts → 5 € (ERP)</small></strong></div></div>
      <div class="regles"><div class="regle"><span class="ic">€</span><div><b>La case se coche toute seule</b><p>Le cockpit lit les achats de la semaine du client, tous magasins (<em>gross_sales_before_corrections</em> de <em>/loyalty/customers</em>). Dès <em>10 €</em>, la case est cochée. Une case par semaine au plus.</p></div></div>
        <div class="regle"><span class="ic">x</span><div><b>Toutes les 3 cases, un bon à son nom</b><p>Chaque lundi, un code de la campagne « Bingo hiver · 2 € » est créé pour le client, valable <em>30</em> jours. Il arrive dans le portefeuille des bons de l’ERP.</p></div></div>
        <div class="regle"><span class="ic">13</span><div><b>Carte pleine</b><p>13 cases sur 13 : un code de « Bingo hiver · 10 € ». Une semaine manquée le rend impossible pour la saison.</p></div></div></div></section>
    <section class="panel"><h2>Une carte par saison</h2><p>Quatre cartes font l’année. Les campagnes de chaque saison se créent en brouillon depuis ce cockpit, puis s’activent dans l’ERP.</p>
      <div class="grid4">${saisons}</div><div style="display:flex;gap:10px;margin-top:14px"><span class="btn out">Créer les campagnes du printemps (brouillon)</span><span class="hint" style="margin:0;align-self:center">POST /admin/voucher-campaigns · confirmé, puis noté au journal</span></div></section>
    <section class="panel"><h2>Pas dans l’API de l’ERP</h2><p>Le handoff les prévoit ; l’ERP ne sait pas les faire. L’écran le dit, rien n’est simulé à leur place.</p>
      <div class="lims"><ul><li><b>×2 après la case</b>, pour un client, jusqu’au dimanche : l’ERP n’a que des bonus par jour et heure, pour tous.</li><li><b>Produit de la semaine ×2</b> : pas de coefficient par produit.</li><li><b>Échange automatique au palier</b> : le client échange lui-même ses points.</li><li><b>Coefficients personnels</b> (jour perso, offre perso) : rien par client.</li></ul>
      <p class="hint" style="margin-top:8px">Une seule voie : la demande à l’équipe de l’ERP.</p></div></section>
  </div>
  <aside class="panel" style="position:sticky;top:20px"><h2>La carte de l’hiver</h2><p>Imprimée en A6, donnée en caisse. Thèmes et dessins du fichier de saison.</p><div style="zoom:.664">${carteSaison()}</div></aside></div>`;
  return main;
}

// ── 4 · Cockpit › Fidélité › Magasins (Halle) ─────────────────────────
function magasins() {
  const bar = PARTS.map((p, i) => `<div class="${i === 7 ? 'cours' : ''}"><span>${p} %</span><i style="height:${p * 2.2}px"></i></div>`).join('') + Array.from({ length: 5 }, () => '<div class="vide"><span>&nbsp;</span><i style="height:4px"></i></div>').join('');
  const lab = H27.map((s) => `<div>S${s.n}<img src="${ILL}${s.ill}.png" alt=""></div>`).join('');
  const bande = (c) => `<span class="bande">${Array.from({ length: 13 }, (_, i) => `<i class="${c[i] === 'c' ? 'c' : c[i] === 'm' ? 'm' : c[i] === 'e' ? 'e' : 'f'}"></i>`).join('')}</span>`;
  const membres = MEMBRES.map((m) => { const n = [...m.cases].filter((x) => x === 'c').length;
    return `<tr><td><strong style="font-weight:400">${m.nom}</strong></td><td>${bande(m.cases)}</td><td><b style="font:400 18px var(--display);color:var(--rubis)">${n}</b> / 13</td>
      <td>${eur(m.sem)}<div class="mini-bar"><i style="width:${Math.min(100, m.sem * 10)}%"></i></div></td><td>${m.pts} pts</td><td>${m.bons}</td><td>${m.der}</td></tr>`; }).join('');
  const rel = RELANCES.map((r) => `<div class="wl" style="grid-template-columns:1fr auto"><div><strong>${r.nom}</strong><small>${r.txt}</small></div><span class="etat ${r.tag === 'absent' ? 'no' : 'at'}"><i></i>${{ case: 'presque la case', bon: 'presque un bon', absent: 'absent' }[r.tag]}</span></div>`).join('');
  return `${tete('Magasins')}
  <div style="display:flex;gap:8px;margin-bottom:14px">${SHOPS.map(([n]) => `<span class="btn sm ${n === 'Halle' ? 'out' : ''}">${n}</span>`).join('')}</div>
  <div class="kpis"><div class="kpi"><b>64</b><span>membres de la carte</span><em>clients reconnus avec la carte à points</em></div>
    <div class="kpi"><b>29</b><span>venus cette semaine</span><em>45 % des membres</em></div><div class="kpi"><b>18</b><span>case cochée cette semaine</span><em>28 % · la semaine court jusqu’à dimanche</em></div>
    <div class="kpi"><b>47</b><span>joueurs de la saison</span><em>73 % ont au moins une case</em></div><div class="kpi"><b>6</b><span>bons dus lundi 18/01</span><em>en simulation jusqu’au passage du lundi</em></div></div>
  <div class="cols" style="grid-template-columns:minmax(0,1fr) 380px"><section class="panel"><h2>La case, semaine par semaine</h2><p>Part des membres qui ont atteint 10 € d’achats dans la semaine (lus dans l’ERP, tous magasins).</p>
      <div class="barres">${bar}</div><div class="barres-l">${lab}</div></section>
    <section class="panel"><h2>À relancer</h2><p>Avant dimanche : la case ou un bon est à portée.</p><div class="wallet">${rel}</div></section></div>
  <section class="panel"><h2>Les membres et leur carte</h2><p>Une ligne par client : ses 13 semaines, sa dépense de la semaine, ses points et ses bons. ${v('GET /shops/{id}/loyalty/customers')}</p>
    <div class="twrap"><table><thead><tr><th>Client</th><th>13 semaines</th><th>Cases</th><th>Cette semaine</th><th>Points (ERP)</th><th>Bons</th><th>Dernier passage</th></tr></thead><tbody>${membres}</tbody></table></div>
    <div style="display:flex;gap:16px;margin-top:10px;font-size:11px;color:var(--muted);align-items:center"><span class="bande" style="grid-template-columns:14px"><i class="c"></i></span>cochée <span class="bande" style="grid-template-columns:14px"><i class="m"></i></span>manquée <span class="bande" style="grid-template-columns:14px"><i class="e"></i></span>en cours <span class="bande" style="grid-template-columns:14px"><i class="f"></i></span>à venir</div></section>`;
}

// ── 5 · Cockpit › Fidélité › Un client ─────────────────────────────────
function client() {
  const J = JULIE, x = CONF.tousLes, nC = Object.keys(J.coche).length;
  let cells = '', bi = 0;
  H27.forEach((s, idx) => {
    const n = s.n; let cls = 'cc', inner = `<span class="d">${s.du}</span><img src="${ILL}${s.ill}.png" alt=""><strong>${s.titre}</strong>`;
    if (J.coche[n]) { cls += ' coche' + (s.fete ? ' fete' : ''); inner += `<span class="st">✓<small>${J.coche[n]}</small></span>`; }
    else if (J.manque.includes(n)) { cls += ' manque'; inner += '<span class="mq">manquée</span>'; }
    else if (n === SEMAINE) { cls += ' cours'; inner += `<span class="g"><i style="width:${J.cours * 10}%"></i></span>`; }
    else cls += ' futur';
    cells += `<div class="${cls}">${inner}</div>`;
    if ((idx + 1) % x === 0) {
      bi++; const k = bi * x, [nom, ill] = BONS_H27[bi - 1], b = J.bons.find((y) => y.i === bi);
      if (b) cells += `<div class="cc bon"><span class="d">${k}e case</span><img src="${ILL}${ill}.png" alt=""><b>2 €</b><small>${b.etat === 'utilise' ? 'utilisé le ' + b.util : 'jusqu’au ' + b.fin}</small></div>`;
      else if (k - nC === 3) cells += `<div class="cc proche"><span class="d">${k}e case</span><img src="${ILL}${ill}.png" alt=""><b>2 €</b><small>encore ${k - nC} cases</small></div>`;
      else cells += `<div class="cc lointain"><span class="d">${k}e case</span><img src="${ILL}${ill}.png" alt=""><b>2 €</b><small>à la ${k}e case</small></div>`;
    }
  });
  cells += `<div class="cc plein" style="grid-column:span 3"><img src="${ILL}cake.png" alt=""><div><span class="d">13 / 13 cases · 10 €</span><b>SUPER BON CADEAU</b><small>une semaine manquée (S6) : au printemps</small></div></div>`;
  const hist = H27.slice(0, SEMAINE).map((s) => { const c = J.coche[s.n], m = J.manque.includes(s.n), dep = c ? [10.4, 11.2, 10.6, 10.9, 14.8, 0, 13.1][s.n - 1] : m ? 4.2 : J.cours;
    return `<tr><td>S${s.n} · ${s.du}</td><td>${eur(dep)}</td><td>${Math.floor(dep)} pts</td><td>${c ? `<span class="etat ok"><i></i>cochée le ${c}</span>` : m ? '<span class="etat no"><i></i>manquée</span>' : '<span class="etat at"><i></i>en cours</span>'}</td></tr>`; }).reverse().join('');
  return `${tete('Un client')}
  <div class="cols" style="grid-template-columns:minmax(0,1fr) 420px"><section class="panel">
    <div class="bt"><div><span class="t">BINGO</span><span class="s">de Julie D.</span></div><div class="r"><b>${nC} / 13</b><small>cases · 2 bons débloqués</small></div></div>
    <div class="pp-card" style="border:1.6px dashed var(--rubis);display:flex;gap:14px;align-items:center;margin:0 0 14px"><img src="${ILL}bread-1.png" alt="" style="width:54px"><div style="flex:1"><div class="k" style="margin-bottom:2px">Cette semaine · 11/01 – 17/01</div><strong style="font-weight:400;font-size:14px">Le bon pain</strong>
      <div class="bar" style="margin:7px 0 5px"><i style="width:64%"></i></div><span class="hint" style="margin:0">6,40 € sur 10 € — encore 3,60 € d’ici dimanche et la case se coche toute seule.</span></div></div>
    <div class="cg" style="grid-template-columns:repeat(4,1fr)">${cells}</div></section>
  <div><section class="panel"><h2>Points · carte de l’ERP</h2><div class="pp-h" style="margin:6px 0 8px"><div><span class="pp-t">78</span><span class="pp-s">points</span></div><div class="pp-r"><small>encore 22 pts pour un bon de 5 €</small></div></div><div class="bar ab"><i style="width:78%"></i></div>
      <p class="hint">+4 pts au dernier ticket (jeudi 14/01). Le ×2 après la case n’est pas dans l’API de l’ERP : pas affiché au client.</p></section>
    <section class="panel"><h2>Ses bons</h2><p>Codes des campagnes de la saison, à son nom. ${v('GET /admin/voucher-campaigns/{id}/codes')}</p><div class="wallet">
      <div class="wl"><span class="ti2"><img src="${ILL}tartelette.png" alt=""></span><div><small>Bingo · 6e case</small><strong>Bon des Rois · 2 €</strong><small><code>FID7K2QM9PA</code> · jusqu’au 05/02</small></div><span class="etat ok"><i></i>à utiliser</span></div>
      <div class="wl"><span class="ti2 gr"><img src="${ILL}cake.png" alt=""></span><div><small>Bingo · 3e case</small><strong>Bon de l’Avent · 2 €</strong><small><code>FIDR4WQ8NZC</code> · utilisé le 17/12</small></div><span class="etat no"><i></i>utilisé</span></div>
      <div class="wl"><span class="ti2 ab"><img src="${ILL}croissant.png" alt=""></span><div><small>Points · palier 100</small><strong>Bon de 5 €</strong><small>à 22 points</small></div><span class="etat no"><i></i>pas encore</span></div></div></section>
    <section class="panel"><h2>Semaine par semaine</h2><div class="twrap"><table class="compact"><thead><tr><th>Semaine</th><th>Dépense</th><th>Points</th><th>Case</th></tr></thead><tbody>${hist}</tbody></table></div></section></div></div>`;
}

ecran(1, 'ERP admin', 'Cartes de fidélité : il ne reste que la carte à points', 'GET /loyalty/cards · admin/views/loyalty/overview.twig', railErp(), erpListe());
ecran(2, 'ERP admin', 'La carte à points, étape 2 · Règles et récompense', 'GET /loyalty/cards/{id}/edit · form.twig + points.twig (carte produit décochée)', railErp(), erpCarte());
ecran(3, 'Cockpit', 'Fidélité › Programme : le bingo de la saison et ce qui est dans l’ERP', 'src/fidelite_bingo.php › GET /fidelite · src/data/fidelite_bingo.json', railCockpit(), programme());
ecran(4, 'Cockpit', 'Fidélité › Magasins : la case, semaine par semaine', 'src/fidelite_bingo.php › GET /fidelite/clients?shop=4', railCockpit(), magasins());
ecran(5, 'Cockpit', 'Fidélité › Un client : sa carte de bingo, ses points, ses bons', 'src/fidelite_bingo.php › GET /fidelite/client?id=…', railCockpit(), client());

// ── 6 · App client (l'app de l'ERP, routes /webshop, jeton du client) ──
function grilleMini() {
  const J = JULIE, x = CONF.tousLes, nC = Object.keys(J.coche).length;
  let cells = '', bi = 0;
  H27.forEach((s, idx) => {
    const n = s.n; let cls = 'cc', inner = `<span class="d">${s.du}</span><img src="${ILL}${s.ill}.png" alt=""><strong>${s.titre}</strong>`;
    if (J.coche[n]) { cls += ' coche' + (s.fete ? ' fete' : ''); inner += '<span class="st">✓</span>'; }
    else if (J.manque.includes(n)) { cls += ' manque'; inner += '<span class="mq">manquée</span>'; }
    else if (n === SEMAINE) { cls += ' cours'; inner += `<span class="g"><i style="width:${J.cours * 10}%"></i></span>`; }
    else cls += ' futur';
    cells += `<div class="${cls}">${inner}</div>`;
    if ((idx + 1) % x === 0) {
      bi++; const k = bi * x, [, ill] = BONS_H27[bi - 1], b = J.bons.find((y) => y.i === bi);
      if (b) cells += `<div class="cc bon"><span class="d">${k}e case</span><img src="${ILL}${ill}.png" alt=""><b>2 €</b><small>${b.etat === 'utilise' ? 'utilisé' : 'jusqu’au ' + b.fin}</small></div>`;
      else cells += `<div class="cc ${k - nC === 3 ? 'proche' : 'lointain'}"><span class="d">${k}e case</span><img src="${ILL}${ill}.png" alt=""><b>2 €</b><small>${k - nC === 3 ? 'encore 3 cases' : 'à la ' + k + 'e'}</small></div>`;
    }
  });
  return `<div class="cg mini" style="grid-template-columns:repeat(4,1fr)">${cells}</div>`;
}
function qrCode() {
  let s = 11, h = ''; const r = () => (s = (s * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
  const f = (i, j, x, y) => { const a = i - x, b = j - y; return a >= 0 && b >= 0 && a < 7 && b < 7 ? (a === 0 || b === 0 || a === 6 || b === 6 || (a > 1 && a < 5 && b > 1 && b < 5)) : null; };
  for (let j = 0; j < 21; j++) for (let i = 0; i < 21; i++) { let on = f(i, j, 0, 0) ?? f(i, j, 14, 0) ?? f(i, j, 0, 14); if (on === null) on = (i < 8 && j < 8) || (i > 12 && j < 8) || (i < 8 && j > 12) ? false : r() > .5; h += on ? '<i></i>' : '<b></b>'; }
  return `<div class="qr">${h}</div>`;
}
function telephone(onglet, corps) {
  return `<div class="phone"><div class="scr"><div class="sb"><b style="font-weight:400">9:41</b><span>5G ▮▮▮</span></div>
    <div class="ah"><img src="${LOGO}" alt=""><span>Bonjour Julie</span></div>
    <div class="ptabs">${['Bingo', 'Points', 'Portefeuille'].map((t) => `<span class="${t === onglet ? 'on' : ''}">${t}</span>`).join('')}</div>
    <div class="bd">${corps}</div>
    <div class="nav"><span>Accueil</span><span class="on">Fidélité</span><span>Commander</span><span>Profil</span></div></div></div>`;
}
function appClient() {
  const bingo = `<div class="bt" style="margin:0"><div><span class="t" style="font-size:34px">BINGO</span><span class="s" style="font-size:17px">d’hiver</span></div><div class="r"><b style="font-size:24px">6 / 13</b><small>cases · 2 bons</small></div></div>
    <div class="pp-card" style="border:1.6px dashed var(--rubis);margin:0;display:flex;gap:10px;align-items:center"><img src="${ILL}bread-1.png" alt="" style="width:44px"><div style="flex:1"><div class="k" style="margin-bottom:1px">Cette semaine · 11/01 – 17/01</div><strong style="font-weight:400">Le bon pain</strong>
      <div class="bar" style="margin:6px 0 4px"><i style="width:64%"></i></div><span class="hint" style="margin:0;font-size:9.5px">6,40 € sur 10 € — encore 3,60 € d’ici dimanche et la case se coche toute seule.</span></div></div>
    <div class="pp-card" style="margin:0"><div class="k" style="margin-bottom:4px">Mes points <span>encore 22 pts pour 5 €</span></div><div style="display:flex;align-items:baseline;gap:6px"><b style="font:400 24px var(--display);color:var(--rubis)">78</b><span class="hint" style="margin:0">/ 100</span></div><div class="bar ab" style="margin-top:6px"><i style="width:78%"></i></div></div>
    ${grilleMini()}`;
  const jours = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((d, i) => `<div class="jour ${i < 3 ? 'passe' : ''} ${i === 3 ? 'auj' : ''} ${i === 2 ? 'c2' : ''}">${d}<br>${String(11 + i).padStart(2, '0')}/01<b><i>×</i>${i === 2 ? 2 : 1}</b></div>`).join('');
  const points = `<div class="bt" style="margin:0"><div><span class="t" style="font-size:34px">POINTS</span><span class="s" style="font-size:17px">de Julie</span></div><div class="r"><b style="font-size:24px">78 pts</b><small>encore 22 pts pour 5 €</small></div></div>
    <div class="bar ab"><i style="width:78%"></i></div>
    <div class="pp-card" style="margin:0"><div class="k">Votre semaine <span>11/01 – 17/01</span></div><div class="jours">${jours}</div>
      <div class="expl"><span class="x" style="background:var(--abricot);color:var(--rubis)">×2</span><span>Le mercredi de 14:00 à 17:00, tout compte double.</span></div></div>
    <div class="pp-card" style="margin:0"><div class="k">Échanger mes points</div><div style="display:flex;justify-content:space-between;align-items:center"><span><b style="font:400 20px var(--display);color:var(--rubis)">100 pts</b> → un bon de 5 €</span><span class="btn sm dis">Échanger</span></div><span class="hint">Encore 22 points. Le bon arrive dans le portefeuille.</span></div>
    <div class="k" style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--rubis);margin-top:2px">Derniers points</div>
    <div class="lots"><div class="lot"><span>Jeudi 14/01<small>Halle · 4,00 €</small></span><b>+4</b></div><div class="lot"><span>Lundi 11/01<small>Halle · 2,40 €</small></span><b>+2</b></div>
      <div class="lot"><span>Vendredi 08/01<small>Gosselies · 5,30 €</small></span><b>+5</b></div><div class="lot"><span>Mercredi 06/01<small>Halle · 7,80 € · la galette</small></span><b>+7</b></div></div>`;
  const porte = `<div class="k" style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--rubis)">Mes bons</div>
    <div class="wl" style="background:#fff;border:0"><span class="ti2"><img src="${ILL}tartelette.png" alt=""></span><div><small style="color:var(--rubis)">Bingo · 6e case</small><strong>Bon des Rois · 2 €</strong><small>code FID7K2QM9PA · jusqu’au 05/02</small></div><span class="btn sm pri">Montrer</span></div>
    <div class="info" style="margin-top:0;background:#fff">${ico('info', 18)}<div>Le bon de 5 € arrive ici<p>quand vous échangez 100 points.</p></div></div>`;
  const montrer = `<div class="code-g"><div class="k" style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--rubis)">Bingo · 6e case</div><div style="font:400 26px var(--display);color:var(--rubis);margin-top:6px">BON DES ROIS</div><div class="hint" style="margin:2px 0 0">2 € · valable jusqu’au 05/02</div>
      ${qrCode()}<div class="c">FID7K2QM9PA</div><span class="hint">Montrez ce code à la caisse. Il ne sert qu’une fois.</span></div>`;
  const col = (cap, tel, srcs) => `<div><div class="cap">${cap}</div>${tel}<div class="srcs">${srcs.map((x) => `<span>${x}</span>`).join('')}</div></div>`;
  return `<div class="apps"><div class="kick">App client · <b>Fidélité</b> · jeudi 14/01/2027</div>
    <h1 style="font:400 34px/1.05 var(--display);color:var(--rubis);margin:6px 0 4px">L’app de Julie D.</h1>
    <p style="color:var(--muted);font-size:13px">L’app client de l’ERP, avec son jeton client : elle ne voit que les données de la cliente connectée. Sous chaque écran, la route qui le remplit.</p>
    <div class="phones">
      ${col('Bingo <span>· sa carte de la saison</span>', telephone('Bingo', bingo), [`Grille : ${v('GET /webshop/loyalty/history')} par semaine + calendrier de saison`, `Bons débloqués : ${v('GET /webshop/vouchers')}`, `Points : ${v('GET /webshop/loyalty/points')}`])}
      ${col('Points <span>· solde, semaine, échange</span>', telephone('Points', points), [`Solde et derniers points : ${v('GET /webshop/loyalty/points')}`, `Bonus du mercredi, palier : ${v('GET /webshop/loyalty/program')}`, `Échange : ${v('POST /webshop/loyalty/points/redeem')}`])}
      ${col('Portefeuille <span>· ses bons</span>', telephone('Portefeuille', porte), [`Bons à son nom : ${v('GET /webshop/vouchers')}`, `Bingo : codes créés par le cockpit avec ${v('id_customer')}`])}
      ${col('Montrer <span>· à la caisse</span>', telephone('Portefeuille', montrer), [`Caisse : ${v('POST /vouchers/validate')} puis ${v('POST /vouchers/redeem')}`, 'Code à usage unique (usage_limit 1)'])}
    </div>
    <div class="manque-l"><div><h4>Ce qui existe déjà dans l’ERP</h4><ul><li>Le solde, les lots et l’échange de points (<code>/webshop/loyalty/points</code>, <code>…/redeem</code> : palier exact, bon au nom du client).</li><li>Les bons du client (<code>/webshop/vouchers</code>), dont les codes du bingo créés avec <code>id_customer</code>.</li><li>Les règles de la carte (<code>/webshop/loyalty/program</code>) et l’historique (<code>/webshop/loyalty/history</code>).</li></ul></div>
      <div><h4>Ce qui reste à écrire, sans table</h4><ul><li>Aucune route de l’ERP ne rend la grille du bingo : l’app la calcule à partir de l’historique de la cliente et du calendrier de saison (fichier). Le détail des montants de <code>/webshop/loyalty/history</code> n’est pas décrit dans la spécification : à vérifier.</li><li>Pas de ×2 après la case ni d’offre perso : l’ERP ne les a pas, l’app ne les montre pas.</li></ul></div></div></div>`;
}
document.body.insertAdjacentHTML('beforeend', `<section class="ecran" id="e6"><div class="annot"><span class="n">6</span><div><div class="t"><span style="color:var(--abricot)">App client</span> · Fidélité : bingo, points, portefeuille, le bon à la caisse</div><div class="src">routes /webshop de l’ERP (jeton client) · aucune table</div></div>
  <div class="leg">Aucune table, aucun champ ajouté · ${v('route existante')}</div></div>${appClient()}</section>`);
