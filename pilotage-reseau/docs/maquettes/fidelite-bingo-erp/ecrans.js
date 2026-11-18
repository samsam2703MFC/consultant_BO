// Les six écrans. Chaque bloc reprend la structure du gabarit Twig de l'ERP cité dans son bandeau,
// avec ses seuls champs. Les textes sont ceux de resources/lang/page/fr/loyalty.json.
'use strict';

// ── Coquille : rail (page_components/sidebar), barre (navbar), contenu ──
function rail() {
  const it = (ic, t, cur, sub) => `<li><a ${cur ? 'aria-current="page"' : ''}>${ico(ic)}<span>${t}</span>${sub ? '<span class="chev">⌄</span>' : ''}</a></li>`;
  return `<nav class="rail"><img class="logo" src="${LOGO}" alt="L’Atelier by"><input value="Rechercher une page…" readonly><ul>
    ${it('grid', 'Tableau de bord')}${it('shop', 'Franchise', 0, 1)}${it('mega', 'Campagnes globales')}
    ${it('card', 'Fidelity', 1)}
    ${it('book', 'Menu', 0, 1)}${it('tag', 'Promotions', 0, 1)}${it('users', 'Utilisateurs', 0, 1)}${it('gear', 'Paramètres', 0, 1)}
  </ul></nav>`;
}
function ecran(n, titre, src, main, extra = '') {
  document.body.insertAdjacentHTML('beforeend', `<section class="ecran" id="e${n}">
    <div class="annot"><span class="n">${n}</span><div><div class="t">${titre}</div><div class="src">${src}</div></div>
      <div class="leg">Structure de l’ERP uniquement · ${v('nom du champ existant')}</div></div>
    <div class="app">${rail()}<div class="main"><div class="topbar"><span class="burger">${ico('menu')}</span><span class="sp">FR</span><span class="av">AB</span></div>
      <div class="content">${main}</div>${extra}</div></div></section>`);
}

// ── Assistant (form.twig) ───────────────────────────────────────────────
const ETAPES = ['Informations générales', 'Règles et récompense', 'Boutiques et produits', 'Apparence et récapitulatif'];
const entete = () => `<div class="ph"><h1>Nouvelle carte de fidélité</h1><nav class="crumb"><a>Fidelity</a><span>/</span><a>Cartes Fidelity</a><span>/</span><span>Nouvelle carte</span></nav></div>`;
const etapes = (cur) => `<nav class="steps">${ETAPES.map((t, i) => `<div class="${i + 1 < cur ? 'done' : i + 1 === cur ? 'cur' : ''}"><span class="n">${i + 1 < cur ? '✓' : i + 1}</span><span>${i + 1}. ${t}</span></div>`).join('')}</nav>`;
function apercu(tab) {
  return `<aside class="prev"><div class="panel"><h2>Aperçu de la carte</h2><p>Voici le projet de votre carte de fidélité.</p>
    <div class="ptabs"><span class="${tab === 'stamps' ? 'on' : ''}">Carte produit</span><span class="${tab === 'points' ? 'on' : ''}">Carte à points</span></div>
    ${tab === 'stamps' ? `<div class="fit">${carte()}</div>` : apercuPoints()}
    <div class="info">${ico('info', 18)}<div>Aperçu en direct<p>Vos modifications apparaissent immédiatement.</p><small>Tampons illustratifs, pas le solde d’un client.</small></div></div>
  </div></aside>`;
}
function actions(step) {
  const next = step < 4 ? `<span class="btn pri">Suivant : ${ETAPES[step]} →</span>` : '<span class="btn pri">Enregistrer et activer</span>';
  return `<footer class="actions"><span class="btn">${step === 1 ? 'Retour' : '← Retour'}</span>
    <span class="note">Enregistrez un brouillon ou enregistrez et activez la configuration complète à la dernière étape.</span>
    <span class="btn">Enregistrer le brouillon</span>${next}</footer>`;
}
const assistant = (step, editeur, tab) => `${entete()}${etapes(step)}<div class="ws"><div>${editeur}</div>${apercu(tab)}</div>${actions(step)}`;
const champ = (label, ann, val, help = '', cls = '', req = true) =>
  `<div class="field"><label>${label}${req ? '<span class="req"> *</span>' : ''}${ann}</label><div class="inp ${cls}">${val}</div>${help ? `<small>${help}</small>` : ''}</div>`;
const reglage = (n, u) => `<b>${n}</b><span class="u">${u}</span>`;

// ── 1 · Liste des cartes (overview.twig) ────────────────────────────────
function liste() {
  const lignes = [
    { th: `<span class="thumb r"><img src="${ILL}hiver.png" alt=""></span>`, nom: 'Bingo d’hiver', code: CARD.code,
      per: 'Tous les magasins', reg: '13 tampons<small>Carte à points : 1 / 1 €</small>', rec: 'Une pâtisserie offerte<small>100 → 5 €</small>', val: '30 jours', on: 1 },
    { th: `<span class="thumb"><img src="${ILL}printemps.png" alt=""></span>`, nom: 'Bingo de printemps', code: 'fid-a71c09e4f25b38d6c0e19b54',
      per: 'Tous les magasins', reg: '13 tampons<small>Carte à points : 1 / 1 €</small>', rec: 'Une pâtisserie offerte<small>100 → 5 €</small>', val: '30 jours', on: 0 },
    { th: `<span class="thumb"><img src="${ILL}chocolat.png" alt=""></span>`, nom: 'Carte café', code: 'cafe-10',
      per: 'Magasins sélectionnés', reg: '10 tampons', rec: 'Un café offert', val: '30 jours', on: 1 },
    { th: `<span class="thumb"><img src="${ILL}croissant.png" alt=""></span>`, nom: 'Carte à points 2026', code: 'points-2026',
      per: 'Tous les magasins', reg: '<small>Carte à points : 10 / 10 €</small>', rec: '<small>100 → 5 €</small><small>250 → 15 €</small><small>500 → 35 €</small><small>1000 → 80 €</small>', val: '30 jours', on: 0 },
  ];
  const tr = lignes.map((l) => `<tr><td><div class="cardcell">${l.th}<div><strong>${l.nom}</strong><span class="code">${l.code}</span></div></div></td>
    <td>${l.per}</td><td>${l.reg}</td><td>${l.rec}</td><td>${l.val}</td>
    <td><span class="status ${l.on ? 'on' : ''}"><i></i>${l.on ? 'Configuration active' : 'Brouillon / inactive'}</span></td>
    <td><div class="rowact"><span class="btn sm">${ico('eye')} Aperçu</span><span class="btn sm">${ico('sliders')} Correspondance</span><div class="dup"><span class="btn sm">${ico('pencil')} Modifier</span><span class="btn sm">${ico('dots')}</span></div></div></td></tr>`).join('');
  return `<div class="ph"><div class="kick">Fidelity</div><h1>Cartes de fidélité</h1><p>Configuration du programme et des récompenses clients.</p></div>
  <div class="notice" style="margin-top:16px">${ico('info')} Une configuration active ne confirme pas l’activation de la collecte des tampons.</div>
  <div class="top"><div class="stats">
    <div><span class="ic">${ico('card')}</span><div><strong><b>4</b> cartes</strong><small>Cartes de fidélité configurées</small></div></div>
    <div><span class="ic">${ico('gear')}</span><div><strong><b>2</b> configurations actives</strong><small>Parmi toutes les cartes</small></div></div>
    <div><span class="ic">${ico('shop')}</span><div><strong>Portée : réseau</strong><small>3 cartes couvrent tous les magasins</small></div></div>
  </div><span class="btn pri big">${ico('plus', 18)} Nouvelle carte</span></div>
  <section class="panel"><h2>Liste des cartes de fidélité</h2><p>Gérez les cartes, modifiez les règles, vérifiez la correspondance ou ajoutez une carte.</p>
    <div class="filters"><div class="fbox" style="display:flex;align-items:center;gap:10px">${ico('search')}<div style="color:#9a9188">Rechercher par nom ou code…</div></div>
      <div class="fbox">Statut<div>Tous <span>⌄</span></div></div><div class="fbox">Périmètre<div>Tous <span>⌄</span></div></div><span class="btn">${ico('search')} Filtrer</span></div>
    <div class="twrap"><table><thead><tr><th style="width:28%">Carte / code</th><th style="width:11%">Périmètre</th><th style="width:15%">Règles</th><th style="width:16%">Récompense</th><th style="width:8%">Validité</th><th style="width:14%">Statut</th><th style="width:150px">Actions</th></tr></thead><tbody>${tr}</tbody></table></div>
    <div class="pag">Afficher par page : <span class="inp" style="width:auto;min-height:28px;padding:3px 9px">10 ⌄</span><span>1–4 sur 4</span><span class="btn sm dis">${ico('left')}</span><span class="btn sm dis">${ico('right')}</span></div>
  </section>
  <section class="panel"><h2>Vérifier les correspondances</h2><p>Aperçu de configuration ; ne confirme pas l’attribution de tampons.</p>
    <div style="display:flex;gap:12px;align-items:end"><div class="field" style="width:320px"><label>Magasin</label><div class="inp">Halle<span class="caret">⌄</span></div></div><span class="btn pri">${ico('search')} Vérifier</span></div></section>`;
}

// ── 2 · Étape 1 ────────────────────────────────────────────────────────
function etape1() {
  const ed = `<div class="panel"><h2>Informations générales</h2><p>Donnez à la carte un nom visible par vos clients.</p>
    <div class="grid2">${champ('Nom', v('name'), CARD.name)}
    ${champ('Code (minuscules, chiffres, - ou _)', v('code'), CARD.code, 'Code attribué automatiquement, sans modification nécessaire.', 'ro')}</div>
    <details class="adv" open><summary>Paramètres avancés</summary><div class="grid2" style="margin-top:12px">${champ('Ordre (la valeur la plus basse départage les égalités)', v('sort_order'), '0', 'Par défaut 0. À priorité égale, la carte la plus ancienne est retenue. Les valeurs plus basses sont prioritaires.')}</div></details></div>`;
  return assistant(1, ed, 'stamps');
}

// ── 3 · Étape 2 (form.twig section 2 + points.twig) ────────────────────
function etape2() {
  const pc = CARD.points_config, b = pc.bonuses[0];
  const shops = SHOPS.map(([n, id]) => champ(`${n} (#${id})`, '', reglage(pc.shop_coefficients[id], 'coef.'), '', 'set', false)).join('');
  const ed = `
  <div class="panel"><h2>Type de carte</h2><p>Cochez l’un, l’autre ou les deux. L’aperçu montre chaque carte dans son onglet.</p>
    <div class="moteurs">
      <div class="mot plein"><span class="ck"><span class="box on"></span>Carte produit</span><div class="titre">TAMPONS</div><div class="sous">Fait revenir</div>
        <p>Les règles du programme, la récompense et les produits offerts ci-dessous.</p><div style="margin-top:9px">${v('points_config.stamps_enabled')}</div></div>
      <div class="mot"><span class="ck"><span class="box on"></span>Carte à points</span><div class="titre">POINTS</div><div class="sous">Fait consommer</div>
        <p>La règle de gain, les boutiques, les bonus et les paliers ci-dessous.</p><div style="margin-top:9px">${v('points_config.points_enabled')}</div></div>
    </div></div>

  <div class="panel"><h2>Règles du programme</h2><p>Définissez le nombre de tampons, la récompense et sa validité.</p>
    <div class="grid3">
      ${champ('Nombre de tampons', v('stamps_required'), reglage(CARD.stamps_required, 'tampons'), 'Tampons nécessaires pour obtenir un cadeau.', 'set')}
      ${champ('Libellé de la récompense', v('reward_label'), CARD.reward_label, 'Nom court du cadeau affiché sur la carte.')}
      ${champ('Validité du cadeau (jours)', v('reward_validity_days'), reglage(CARD.reward_validity_days, 'jours'), 'Jours après obtention. 0 signifie sans expiration.', 'set')}
    </div></div>

  <div class="panel"><h2>Règle de gain</h2><p>Points sur les produits du programme après réductions et bons. Hors livraison, frais et cadeaux.</p>
    <div class="grid4">
      ${champ('Points par tranche', v('points_per_slice'), reglage(pc.points_per_slice, 'pt'), '', 'set')}
      ${champ('Tranche de dépenses (€)', v('slice_cents'), reglage('1,00', '€'), '', 'set')}
      ${champ('Validité du bon (jours)', v('voucher_validity_days'), reglage(pc.voucher_validity_days, 'jours'), 'Pour la validité : 0 = sans expiration', 'set')}
      ${champ('Validité des points (jours)', v('points_validity_days'), reglage(pc.points_validity_days, 'jours'), 'Pour la validité : 0 = sans expiration', 'set')}
    </div>
    <h3>Boutiques et coefficients ${v('shop_coefficients')}</h3><p class="help" style="margin-bottom:8px">Uniquement pour les boutiques sélectionnées et les points, sans modifier les tampons.</p>
    <div class="grid4">${shops}</div>
    <h3>Bonus jour et heure ${v('bonuses')}</h3>
    <div class="rows"><div class="row" style="grid-template-columns:1.7fr .7fr .7fr .7fr auto auto">${champ('Nom', '', b.name, '', '', false)}${champ('De', '', b.from, '', '', false)}${champ('À', '', b.to, '', '', false)}${champ('Coefficient', '', b.coefficient, '', '', false)}
      <div><div class="flabel">Jours : lundi–dimanche</div><div class="days">${[0, 1, 2, 3, 4, 5, 6].map((d) => `<span class="${b.days.includes(d) ? 'on' : ''}">${d + 1}</span>`).join('')}</div></div><span class="btn sm">Supprimer</span></div></div>
    <span class="btn sm" style="margin-top:8px">${ico('plus')} Ajouter un bonus</span>
    <h3>Paliers de récompense ${v('tiers')}</h3>
    <div class="rows"><div class="row" style="grid-template-columns:1fr 1fr 1fr auto">${champ('Solde de points', '', '100', '', '', false)}${champ('Montant (€)', '', '5,00', '', '', false)}<span class="rate">Retour : 5,00 %</span><span class="btn sm">Supprimer</span></div></div>
    <span class="btn sm" style="margin-top:8px">${ico('plus')} Ajouter un palier</span>
    <div class="sim"><h3 style="margin-top:0">Simulateur d’achat</h3><p class="help" style="margin-bottom:10px">La simulation ne sauvegarde ni points ni bons. Le montant est la dépense éligible après réductions.</p>
      <div class="grid3">${champ('Montant (€)', '', '7,50', '', '', false)}${champ('Choisir les boutiques', '', 'Halle (#3)<span class="caret">⌄</span>', '', '', false)}${champ('Date et heure locale de la boutique', '', '13/01/2027 15:30', '', '', false)}</div>
      <div style="display:flex;gap:8px;margin-top:12px"><span class="btn out">Ajouter l’achat</span><span class="btn">Réinitialiser</span></div>
      <div class="simres"><p><code>7 × 1 × 1 × 2 = 14</code> &nbsp;tranches × points par tranche × coefficient boutique × coefficient bonus (mercredi 15:30)</p>
        <p style="color:var(--muted)">Réponse de POST /loyalty/cards/simulate (slices, shop_coefficient, bonus_coefficient, points). Solde de la simulation : 78 points.</p></div></div>
  </div>

  <div class="panel"><h2>Disponibilité du programme</h2><p>Définissez les dates et les boutiques participantes.</p>
    <div class="grid3">
      ${champ('Date de début', v('starts_at'), '22/11/2026 23:00:00', 'Début de collecte des tampons. Date et heure UTC (= lundi 23/11 00:00, heure belge).')}
      ${champ('Date de fin', v('ends_at'), '21/02/2027 23:00:00', 'Laissez vide pour une durée illimitée. UTC (= lundi 22/02 00:00, heure belge).', '', false)}
      ${champ('Périmètre', v('shop_selection'), 'Tous les magasins<span class="caret">⌄</span>', 'Tous les magasins utilise la liste au moment de l’enregistrement.')}
    </div>
    <div class="field" style="margin-top:14px"><label>Choisir les boutiques<span class="req"> *</span>${v('shop_ids[]')}</label><div class="inp ro">${ico('search')} <span class="ph">Rechercher des boutiques…</span><span class="caret">⌄</span></div>
      <div class="chips" style="margin-top:8px">${SHOPS.map(([n, id]) => `<span>${n} (#${id})</span>`).join('')}</div><small>4 sur 4 sélectionnés</small></div></div>

  <div class="panel"><h2>Produits offerts en récompense ${v('reward_product_ids[]')}</h2><p>Choisissez des produits entiers et/ou leurs portions du catalogue comme récompenses.</p>
    <div class="toolbar"><div class="inp">${ico('search')} <span class="ph">Rechercher des produits…</span></div><span class="count">3 sur 52 sélectionnés</span><span class="chk"><span class="box"></span>Afficher la sélection</span></div>
    <div class="twrap"><table><thead><tr><th style="width:36px"><span class="box"></span></th><th>Produit</th><th>Code</th><th>Catégorie</th><th>Prix</th></tr></thead><tbody>
      ${[['Éclair au chocolat — Entier', 210, 'Pâtisseries', 1], ['Tartelette aux fruits — Entier', 214, 'Pâtisseries', 1], ['Cake marbré — Entier', 220, 'Pâtisseries', 0], ['&nbsp;&nbsp;&nbsp;Cake marbré — Tranche', 220, 'Pâtisseries', 1], ['Croissant — Entier', 101, 'Viennoiseries', 0], ['Pain au chocolat — Entier', 102, 'Viennoiseries', 0]]
        .map(([p, c, cat, on]) => `<tr class="${on ? 'sel' : ''}"><td><span class="box ${on ? 'on' : ''}"></span></td><td>${p}</td><td>#${c}</td><td>${cat}</td><td title="Non disponible">—</td></tr>`).join('')}
    </tbody></table></div></div>`;
  return assistant(2, ed, 'points');
}

// ── 4 · Étape 3 ────────────────────────────────────────────────────────
function picker(label, ann, help, count, items) {
  return `<div><div class="flabel">${label}${ann}</div><p class="hint" style="margin:0 0 8px">${help}</p>
    <div class="toolbar" style="margin-bottom:6px"><div class="inp"><span class="ph">Rechercher dans la liste…</span></div></div>
    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px"><span class="count">${count}</span><span class="chk" style="font-size:10.5px"><span class="box"></span>Afficher la sélection</span></div>
    <div class="opts">${items.map(([t, s, on]) => `<div class="opt"><span class="box ${on ? 'on' : ''}"></span><div>${t}${s ? `<small>${s}</small>` : ''}</div></div>`).join('')}</div></div>`;
}
function etape3() {
  const ed = `<div class="panel"><h2>Produits donnant des tampons</h2><p>Choisissez des catégories et/ou produits. Les unités entières payées donnent des tampons après confirmation du paiement. Les exclusions sont prioritaires.</p>
    <div class="grid3">
      ${picker('Catégories donnant des tampons', v('earning_category_ids[]'), 'Inclut les produits admissibles des catégories choisies, y compris les ajouts futurs.', '4 sur 9 sélectionnés',
        [['Viennoiseries', '', 1], ['Pâtisseries', '', 1], ['Pains spéciaux', '', 1], ['Boissons chaudes', '', 1], ['Pains', '', 0], ['Sandwichs', '', 0], ['Épicerie fine', '', 0], ['Emballages', '', 0], ['Cartes cadeaux', '', 0]])}
      ${picker('Produits donnant des tampons', v('earning_product_ids[]'), 'Choisissez des produits du catalogue, seuls ou avec des catégories et groupes.', '2 sur 128 sélectionnés',
        [['Bûche de Noël (#412)', 'Fêtes', 1], ['Galette des rois (#418)', 'Fêtes', 1], ['Baguette tradition (#110)', 'Pains', 0], ['Café en grains (#431)', 'Épicerie fine', 0], ['Sandwich jambon (#501)', 'Sandwichs', 0]])}
      ${picker('Produits exclus', v('excluded_product_ids[]'), 'Les exclusions sont prioritaires, même sur les produits choisis directement.', '2 sur 128 sélectionnés',
        [['Boisson offerte (#320)', 'Boissons chaudes', 1], ['Mini-viennoiserie (#115)', 'Viennoiseries', 1], ['Croissant (#101)', 'Viennoiseries', 0], ['Chocolat chaud (#310)', 'Boissons chaudes', 0], ['Cougnou (#140)', 'Pains spéciaux', 0]])}
    </div>
    <div class="sim" style="background:var(--soft)"><h3 style="margin-top:0">Produits couverts par ces règles</h3><p class="help" style="margin-bottom:8px">Catalogue actuel après exclusions. La disponibilité en magasin et les conditions d’achat sont vérifiées séparément.</p>
      <div style="font:400 22px var(--display);color:var(--rubis);margin-bottom:8px">58 produits</div>
      <div class="chips">${['Croissant', 'Pain au chocolat', 'Cougnou', 'Éclair au chocolat', 'Tartelette aux fruits', 'Cake marbré', 'Chocolat chaud', 'Café', 'Thé', 'Bûche de Noël', 'Galette des rois'].map((p) => `<span style="background:#fff">${p}</span>`).join('')}<span style="background:#fff;color:var(--muted)">+ 47 autres</span></div></div>
  </div>
  <div class="panel"><h2>Boutiques et cadeaux sélectionnés</h2><p>Vérifiez votre sélection. Les cadeaux ne donnent pas de tampons.</p>
    <dl class="sum"><dt>Périmètre</dt><dd>Tous les magasins</dd><dt>Boutiques</dt><dd>${SHOPS.map(([n, id]) => `${n} (#${id})`).join(' · ')}</dd>
      <dt>Produits offerts</dt><dd>Éclair au chocolat — Entier · Tartelette aux fruits — Entier · Cake marbré — Tranche</dd>
      <dt>Donnent des tampons</dt><dd><b>4</b> catégories, <b>2</b> produits, <b>2</b> exclusions · 58 produits</dd></dl></div>`;
  return assistant(3, ed, 'stamps');
}

// ── 5 · Étape 4 ────────────────────────────────────────────────────────
function etape4() {
  const coul = (l, k, hex, nom, help) => `<div class="field"><label>${l}<span class="req"> *</span>${v(k)}</label><div class="inp"><span class="sw" style="background:${hex}"></span>${hex}<span class="u">${nom}</span></div><small>${help}</small></div>`;
  const pc = CARD.points_config;
  const ed = `<div class="panel"><h2>Apparence et récapitulatif</h2><p>Personnalisez couleurs et image. L’aperçu se met à jour. Ici, la palette L’Atelier by du handoff.</p>
    <div class="grid3">${coul('Couleur du fond', 'color_tint', CARD.color_tint, 'beige clair', 'Fond de la carte.')}${coul('Couleur de remplissage', 'color_fill', CARD.color_fill, 'rubis', 'Titre, tampons, bande du cadeau.')}${coul('Couleur d’accent', 'color_accent', CARD.color_accent, 'abricot', 'Cadre et libellés du cadeau.')}</div>
    <div class="grid2" style="margin-top:14px">${champ('Chemin de l’image (facultatif)', v('image_path'), CARD.image_path, 'Image : chemin existant commençant par /, par ex. /images/pain.png.', '', false)}</div></div>
  <div class="panel"><h2>Récapitulatif de la carte</h2>
    <dl class="sum"><dt>Nom</dt><dd>${CARD.name}</dd><dt>Code de la carte</dt><dd>${CARD.code}</dd>
      <dt>Type de carte</dt><dd>Carte produit + carte à points</dd>
      <dt>Règles du programme</dt><dd><b>${CARD.stamps_required}</b> tampons = ${CARD.reward_label} · validité <b>${CARD.reward_validity_days}</b> jours</dd>
      <dt>Règle de gain</dt><dd><b>${pc.points_per_slice}</b> point par <b>1,00 €</b> · bonus mercredi 14:00–17:00 ×2 · coefficient boutique 1</dd>
      <dt>Paliers de récompense</dt><dd><b>100</b> points → <b>5 €</b> · bon valable ${pc.voucher_validity_days} jours · points valables ${pc.points_validity_days} jours</dd>
      <dt>Disponibilité</dt><dd>2026-11-22 23:00:00 → 2027-02-21 23:00:00 UTC · Tous les magasins (4)</dd>
      <dt>Donnent des tampons</dt><dd>4 catégories, 2 produits, 2 exclusions</dd>
      <dt>Produits offerts</dt><dd>Éclair au chocolat — Entier · Tartelette aux fruits — Entier · Cake marbré — Tranche</dd></dl>
    <p class="help" style="margin:12px 0 0">Enregistrez un brouillon ou enregistrez et activez la configuration complète à la dernière étape.</p></div>`;
  return assistant(4, ed, 'stamps');
}

// ── 6 · Aperçu depuis la liste (overview.twig › dialog.ll-card-dialog) ─
function dialogue() {
  const val = (l, x) => `<div><span>${l}</span><strong>${x}</strong></div>`;
  return `<div class="voile"></div><div class="dlg">
    <header><div><h2>Aperçu de la carte de fidélité</h2><p style="color:var(--muted);font-size:12.5px">${CARD.name}</p></div>
      <div class="notice" style="margin-left:24px;padding:7px 12px;font-size:11.5px">${ico('info')} Tampons illustratifs, pas le solde d’un client.</div><span class="x">×</span></header>
    <div class="body"><div style="zoom:.689">${carte()}</div>
      <div class="det">
        <section><h3>${ico('gear')} Règles du programme</h3><div class="vals">${val('Nombre de tampons', `<b>${CARD.stamps_required}</b>`)}${val('Récompense', CARD.reward_label)}${val('Validité du cadeau (jours)', CARD.reward_validity_days)}</div>
          <div class="vals" style="margin-top:8px">${val('Ordre', '0')}</div><p class="hint">Par défaut 0. À priorité égale, la carte la plus ancienne est retenue.</p></section>
        <section><h3>${ico('award')} Règle de gain</h3><div class="vals">${val('Points', '1 / 1 €')}${val('Validité des points (jours)', '365')}${val('Validité du bon (jours)', '30')}</div>
          <div class="chips" style="margin-top:9px"><span>100 → 5 €</span></div></section>
        <section><h3>${ico('shop')} Disponibilité du programme</h3><div class="vals">${val('Périmètre', 'Tous les magasins')}${val('Date de début', '2026-11-22 23:00:00 UTC')}${val('Date de fin', '2027-02-21 23:00:00 UTC')}</div>
          <div class="chips" style="margin-top:9px">${SHOPS.map(([n, id]) => `<span>${n} (#${id})</span>`).join('')}</div></section>
        <section><h3>${ico('gift')} Produits offerts</h3><div class="chips"><span>Éclair au chocolat — Entier (#210)</span><span>Tartelette aux fruits — Entier (#214)</span><span>Cake marbré — Tranche</span></div></section>
        <section><h3>${ico('tag')} Produits donnant des tampons</h3><p class="hint" style="margin:0 0 6px">Catégories donnant des tampons</p>
          <div class="chips"><span>Viennoiseries (#3)</span><span>Pâtisseries (#4)</span><span>Pains spéciaux (#2)</span><span>Boissons chaudes (#6)</span></div>
          <p class="hint" style="margin:8px 0 6px">Produits donnant des tampons</p><div class="chips"><span>Bûche de Noël (#412)</span><span>Galette des rois (#418)</span></div>
          <p class="hint" style="margin:8px 0 6px">Produits exclus</p><div class="chips"><span>Boisson offerte (#320)</span><span>Mini-viennoiserie (#115)</span></div></section>
      </div></div>
    <footer><span class="btn">Fermer</span><span class="btn pri">${ico('pencil')} Modifier la carte</span></footer></div>`;
}

ecran(1, 'Liste des cartes de fidélité', 'GET /loyalty/cards · admin/views/loyalty/overview.twig', liste());
ecran(2, 'Nouvelle carte · étape 1 · Informations générales', 'GET /loyalty/cards/create · form.twig, section data-step="1"', etape1());
ecran(3, 'Nouvelle carte · étape 2 · Règles et récompense', 'form.twig section data-step="2" + loyalty/points.twig · POST /loyalty/cards/simulate', etape2());
ecran(4, 'Nouvelle carte · étape 3 · Boutiques et produits', 'form.twig section data-step="3" · options.categories / options.products', etape3());
ecran(5, 'Nouvelle carte · étape 4 · Apparence et récapitulatif', 'form.twig section data-step="4" · save_action=activate', etape4());
ecran(6, 'Liste · aperçu d’une carte', 'GET /loyalty/cards?card_id=… · overview.twig, dialog.ll-card-dialog', liste(), dialogue());
