/* Maquette « Paramétrage des produits » (demande du 14/11/2026 : « un onglet qui serait une page de paramétrage des
 * produits — saison, toutes les options, tout ce que contiennent les API — et toutes les liaisons possibles entre les API
 * reliées aux produits »).
 *
 *   node docs/maquettes/parametrage-produits/generer.js    → a.html (la fiche), b.html (saisons et gammes), c.html (liaisons)
 *   node docs/maquettes/parametrage-produits/capturer.js   → a.png, b.png, c.png
 *
 * Données : donnees.json, lu en ligne le 14/11/2026 (lecture seule) — les 15 gammes (/product-availability-periods), les 81
 * catégories (/product-categories), les 12 groupes (/product-category-groups), les 8 lieux de stockage
 * (/product-storage-locations), les 44 champs d'un produit tels que le panel les rend, et la fiche « Pommes Tranches »
 * (recette 524, coût et prix de chaque magasin) du cockpit. Les valeurs en italique pointillé sont des exemples : le
 * produit lui-même n'a pas été relu champ par champ pour la maquette. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const DATA = JSON.parse(fs.readFileSync(path.join(D, 'donnees.json'), 'utf8'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');

const ICO = { param: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z' };
function page(contenu, titre){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="pp.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>Paramétrage des produits — Cockpit CEO (maquette)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  // L'onglet se range dans le groupe Produits du rail, en tête.
  h = h.replace(/(<div class="sidebar__group-label">Produits<\/div>)/, `$1<button class="sidebar__item is-active" aria-current="page"><svg class="ico" viewBox="0 0 24 24"><path d="${ICO.param}"></path></svg><span class="sidebar__item-label">Paramétrage produits</span></button>`);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Paramétrage des produits</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="pp-pill">Maquette · champs et listes lus au panel le 14/11 · rien n’est écrit</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  return h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, `<div class="pp">${contenu}</div>`).replace('</body>', (titre || '') + '</body>');
}

const F = DATA.fiche;
const CAT = DATA.categories.find(c => c.name === F.cat) || {};
const P = DATA.periodes;
const MAGS = F.magasins;

/* ── La liste des produits (à gauche, commune aux trois écrans) ─────────────── */
const PRODUITS = [['Pommes Tranches', 'Tartes – Ø 28 cm', 2396, 'ok', true], ['Frangipane & Pommes', 'Tartes – Ø 28 cm', 1874, 'att', false], ['Baguette Tradition', 'Boulangerie', 43275, 'ok', false],
  ['Croissant au beurre', 'Viennoiserie', 21940, 'ok', false], ['Bûche Praliné 6 p.', 'Fêtes & Occasions', 3120, 'att', false], ['Couronne des Rois', 'Fêtes & Occasions', 0, 'mu', false],
  ['Sandwich Poulet Curry', 'Sandwichs', 9802, 'ok', false], ['Tarte au riz 28 Ø', 'Tartes – Ø 28 cm', 1630, 'ko', false], ['Galette Frangipane 6 p.', 'Fêtes & Occasions', 0, 'mu', false],
  ['Quiche Lorraine', 'Quiches', 5410, 'ok', false], ['Cookie chocolat lait', 'Cookies', 7330, 'ok', false], ['Flip-Flap & Boisson', 'Anti-Gaspi', 0, 'att', false]];
const liste = `<aside class="pp-liste"><input class="vm-q" placeholder="Chercher un produit…" value="">
  <div class="pp-filtres"><select class="vr-sel"><option>Toutes les gammes</option>${P.map(p => `<option>${esc(p.name)}</option>`).join('')}</select>
  <select class="vr-sel"><option>Toutes les catégories (81)</option></select>
  <div class="vr-chips pp-chips"><button class="on">Actifs <b>471</b></button><button>Webshop <b>128</b></button><button><i class="vr-pt pb-prix"></i>Champs manquants <b>37</b></button><button>Sans gamme <b>12</b></button></div></div>
  <div class="pp-items">${PRODUITS.map(([n, c, ca, e, on]) => `<div class="pp-it${on ? ' on' : ''}"><i class="pp-dot ${e}"></i><div><b>${esc(n)}</b><small>${esc(c)}${ca ? ' · ' + nf(ca, 0) + ' € / 30 j' : ' · hors saison'}</small></div></div>`).join('')}</div></aside>`;

/* ── L'en-tête de la fiche ─────────────────────────────────────────────────── */
const ONGLETS = ['Général', 'Saisons & gammes', 'Vente & canaux', 'Fabrication', 'Conservation & vitrine', 'Étiquette & allergènes', 'Par magasin', 'Liaisons'];
const tete = on => `<div class="pp-tete"><div class="pp-id"><h2>${esc(F.nom)}</h2><div class="pp-sous">produit ${F.pid} · ${esc(F.cat)} · recette ${F.rid} · <span class="pp-ok">actif</span> · 4 magasins sur 4
    <a class="vr-lien" href="#">Ouvrir au panel ↗</a></div></div><span class="vr-sp"></span>
  <div class="pp-score"><b>38 / 44</b><small>champs remplis</small></div><button class="rc-btn gris">Annuler</button><button class="rc-btn">Vérifier ce qui sera écrit</button></div>
  <div class="pp-onglets">${ONGLETS.map(o => `<button class="${o === on ? 'on' : ''}">${esc(o)}${o === 'Saisons & gammes' ? ' <b>3</b>' : ''}${o === 'Liaisons' ? ' <b>28</b>' : ''}</button>`).join('')}</div>`;

/* ── A. La fiche : toutes les sections, tous les champs ──────────────────────── */
// [libellé, clé du panel, type, valeur, exemple ?, aide]
const champ = ([lib, cle, type, val, ex, aide, mod]) => {
  const v = esc(val);
  const ctl = type === 'oui' ? `<button class="vx-tog${val ? ' on' : ''}${mod ? ' mod' : ''}"><i></i>${val ? 'oui' : 'non'}</button>`
    : (type === 'choix' ? `<select class="pp-in${ex ? ' ex' : ''}${mod ? ' mod' : ''}"><option>${v}</option></select>`
      : `<input class="pp-in${ex ? ' ex' : ''}${mod ? ' mod' : ''}" value="${v}">`);
  return `<label class="pp-ch${type === 'oui' ? ' oui' : ''}"><span>${esc(lib)} <code>${esc(cle)}</code></span>${ctl}${aide ? `<small>${esc(aide)}</small>` : ''}</label>`;
};
const SECTIONS = [
  ['Général', 'pour tout le réseau', 'PATCH /products/{id}', [
    ['Nom', 'name', 'texte', F.nom], ['Nom de base', 'base_name', 'texte', F.nom, false, 'les traductions : /products/{id}/aliases'],
    ['Catégorie', 'id_category', 'choix', F.cat, false, 'coefficient ' + nf(CAT.price_coefficient || 0, 2).replace(/0+$/, '').replace(/,$/, '') + ' · ' + (CAT.production_area_name || 'sans zone') + ' · groupe Tartes'],
    ['Secteur', 'sector_id', 'choix', 'Sucré', true, '/consultant/product-sectors'], ['TVA', 'tax_val_perc', 'choix', '6 %'], ['Marque', 'id_brand', 'choix', 'L’Atelier by'],
    ['Actif', 'is_active', 'oui', 1], ['Produit du mois', 'is_pdm', 'oui', 0], ['Assortiment obligatoire', 'is_mandatory_assortment', 'oui', 1, true, 'chaque magasin doit le proposer']]],
  ['Vente & canaux', 'le prix conseillé, la marge, où il se vend', 'PATCH /products/{id}', [
    ['Prix de vente conseillé', 'suggested_sale_price', 'texte', '21,90 €', true, 'TTC'], ['Prix max. d’une portion', 'max_portion_price', 'texte', '—', false], ['Marge attendue', 'expected_margin', 'texte', '70 %', true, 'aussi par catégorie : /product-categories/{id}/products/expected-margin'],
    ['Coefficient minimum', 'min_coefficient', 'texte', '2,70', true, 'celui de la catégorie : 2,70'],
    ['Webshop', 'webshop_active', 'oui', 1], ['Vente au volume (webshop)', 'webshop_bulk_quantity_enabled', 'oui', 0], ['Click & collect', 'click_and_collect', 'oui', 1], ['Livraison bureaux', 'office_delivery', 'oui', 1, false, '', true],
    ['Supplément personnalisable', 'is_personalization_addon', 'oui', 0]]],
  ['Fabrication', 'la recette, la pièce, la production', 'PATCH /products/{id} · /products/{id}/portions · /products/{id}/competences', [
    ['Recette', 'id_recipe', 'choix', F.rid + ' — Pommes Tranches · 2 lignes · 5,37 € la pièce (Corbais)'], ['Vendu à la pièce', 'is_piece_based', 'oui', 1], ['Poids unique', 'single_weight', 'oui', 1],
    ['Divisible en portions', 'is_divisible', 'oui', 1, true, '8 portions : /products/{id}/portions'], ['Préparé avant la vente', 'is_prepared_before_sales', 'oui', 1], ['Délai de préparation', 'preparation_lead_time_hours', 'texte', '24 h', true],
    ['Planning de production', 'production_planning_enabled', 'oui', 1, true], ['Compétences requises', 'required_competences', 'choix', 'Tartes — montage', true, '/product-competences']]],
  ['Conservation & vitrine', 'où il se range, combien de temps il tient', 'PATCH /products/{id}', [
    ['Lieu de stockage', 'id_storage', 'choix', 'Comptoir Tartes', false, DATA.stockage.length + ' lieux : /product-storage-locations'], ['Température de stockage', 'storage_temperature', 'texte', '4 °C', true],
    ['Durée de vie', 'shelf_life_minutes', 'texte', '2 jours', true, 'en minutes au panel : 2 880'], ['Catégorie de durée', 'shelf_life_category', 'choix', 'SSL', false, 'SSL · ESL · LSL'],
    ['Position en vitrine', 'id_positioning', 'choix', 'En longueur face à la vitrine', true, '/product-display-positions'], ['Emballage', 'id_package', 'choix', 'Boîte tarte 28', true, '/product-packages'],
    ['Réchauffage — durée', 'reheating_time_minutes', 'texte', '—'], ['Réchauffage — température', 'reheating_temperature_celsius', 'texte', '—']]],
  ['Étiquette & allergènes', 'ce qui s’imprime', 'PATCH /products/{id} · /recipes/product-labels/reload', [
    ['Allergènes', 'grouped_allergens', 'texte', 'gluten, œufs, lait', true, 'calculés depuis la recette'], ['Ingrédients de l’étiquette', 'label_ingredients', 'texte', 'Pâte (farine de blé, beurre, œufs), pommes 45 %, abricotage…', true],
    ['Nutri-Score', 'nutriscore', 'choix', 'C', true], ['Végétarien', 'is_vegetarian', 'oui', 1, true], ['Taille de l’étiquette', 'label_size', 'choix', 'small'], ['Pièces par étiquette', 'quantity_per_label', 'texte', '1'],
    ['Fiche technique', 'technical-sheet', 'texte', 'PDF — /products/{id}/technical-sheet', false]]],
];
const sectionA = ([titre, sous, route, champs]) => `<div class="vx-sec pp-sec"><h3>${esc(titre)} <span>— ${esc(sous)}</span><code class="pp-route">${esc(route)}</code></h3><div class="pp-grille">${champs.map(champ).join('')}</div></div>`;
const gammesMini = `<div class="vx-sec pp-sec"><h3>Saisons & gammes <span>— quand il est en vente</span><code class="pp-route">PATCH /products/{id}/availability-periods</code></h3>
  <div class="pp-gch">${P.filter(p => p.is_active).map(p => `<button class="${[14, 6, 7].includes(p.id) ? 'on' : ''}">${esc(p.name)}</button>`).join('')}</div></div>`;
const magasins = `<div class="vx-sec pp-sec"><h3>Par magasin <span>— le prix, la disponibilité, le coût</span><code class="pp-route">PATCH /shops/{id}/products/{p}/price · GET /shops/{id}/products/available</code></h3>
  <table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Proposé</th><th>Prix HT<small>de la pièce</small></th><th>Prix TTC</th><th>Coût<small>recette</small></th><th>Coefficient</th><th>Matière<small>% du prix</small></th><th>Portion<small>prix</small></th><th>Promotion<small>en cours</small></th></tr></thead><tbody>
  ${MAGS.map(m => `<tr><td class="g nom"><b>${esc(m.nom)}</b></td><td><button class="vx-tog on"><i></i></button></td><td><input class="pp-in n" value="${nf(m.prixHt, 2)}"></td><td>${nf(m.prixHt * 1.06, 2)} €</td><td>${nf(m.cout, 2)} €</td><td>×${nf(m.coef, 2)}</td><td><span class="vx-p ${m.food > 35 ? 'att' : 'ok'}">${nf(m.food, 1)} %</span></td><td class="pp-ex">${nf(m.prixHt * 1.06 / 8, 2)} €</td><td class="vr-mu">${m.id === '3' ? '<span class="pp-ex">−20 % le samedi</span>' : '—'}</td></tr>`).join('')}</tbody></table></div>`;
const A = `<div class="pp-cols">${liste}<section class="vr-card pp-fiche">${tete('Général')}<div class="pp-corps">${sectionA(SECTIONS[0])}${gammesMini}${SECTIONS.slice(1).map(sectionA).join('')}${magasins}
  <div class="pp-pied"><b>Les 44 champs du produit</b> tels que le panel les rend, rangés en sections ; une valeur changée se souligne en vert, « Vérifier » montre l’envoi exact (route, champs), « Écrire » l’écrit avec le compte consultant, le relit et le note au registre (« Annuler » remet l’avant). <span class="pp-ex">En italique pointillé</span> : valeur d’exemple de la maquette.</div></div></section></div>`;

/* ── B. Saisons & gammes : l'année, les 15 gammes, le produit dedans ─────────── */
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const jour = md => { const m = Math.floor(md / 100), j = md % 100; return new Date(Date.UTC(2026, m - 1, j)).getTime() / 864e5 - new Date(Date.UTC(2026, 0, 1)).getTime() / 864e5; };
const barres = p => {
  const a = jour(p.from_md), b = jour(p.to_md) + 1;
  const seg = (x, y) => `<i style="left:${(100 * x / 365).toFixed(2)}%;width:${Math.max(0.6, 100 * (y - x) / 365).toFixed(2)}%"></i>`;
  return b > a ? seg(a, b) : seg(a, 365) + seg(0, b);
};
const NB = { 14: 241, 19: 38, 13: 9, 4: 22, 12: 14, 11: 11, 5: 0, 6: 18, 8: 31, 10: 4, 7: 26, 17: 12, 15: 6, 16: 44, 9: 7 };
const dmy = md => String(md % 100).padStart(2, '0') + '/' + String(Math.floor(md / 100)).padStart(2, '0');
const B = `<div class="pp-cols">${liste}<section class="vr-card pp-fiche">${tete('Saisons & gammes')}<div class="pp-corps">
  <div class="vx-sec pp-sec"><h3>Saisons & gammes <span>— cochées : la tarte est en vente pendant ces périodes ; hors de toutes, elle sort des caisses et du webshop</span><code class="pp-route">PATCH /products/{id}/availability-periods</code></h3>
  <div class="pp-an"><div class="pp-an-h"><span></span>${MOIS.map(m => `<b>${m}</b>`).join('')}<em style="left:calc(220px + (100% - 220px - 210px) * ${(jour(1114) / 365).toFixed(3)})">aujourd’hui</em></div>
  ${P.map(p => { const on = [14, 6, 7].includes(p.id); return `<div class="pp-an-l${on ? ' on' : ''}${p.is_active ? '' : ' off'}"><label><button class="vx-tog${on ? ' on' : ''}"><i></i></button><span>${esc(p.name)}</span></label>
    <div class="pp-an-b">${barres(p)}</div><div class="pp-an-x">${dmy(p.from_md)} → ${dmy(p.to_md)}${p.is_recurring ? ' · chaque année' : ''}<small>${NB[p.id] != null ? NB[p.id] + ' produits' : ''}${p.webshop_active ? ' · webshop' : ''}</small></div></div>`; }).join('')}</div>
  <div class="pp-note">Les gammes elles-mêmes (nom, dates, photo du webshop, traductions) se règlent ici aussi : <code>POST /product-availability-periods</code>, <code>PATCH /product-availability-periods/{id}</code>, la liste de leurs produits <code>GET|PATCH /product-availability-periods/{id}/products</code> et leur document par magasin.</div></div>
  <div class="vx-sec pp-sec"><h3>Ce que ça change pour Pommes Tranches <span>— calculé, avant d’écrire</span></h3>
  <div class="pp-effet"><div><b>Gamme Standard</b><small>toute l’année : la tarte reste en vente quoi qu’il arrive</small></div><div><b>Automnale + Hivernale</b><small>sans la Standard, elle sortirait du 2 février au 31 août : 7 mois, ≈ 13 700 € de CA sur l’année passée</small></div><div><b>Webshop</b><small>visible au webshop pendant les gammes qui y sont actives (12 sur 15)</small></div></div></div>
  </div></section></div>`;

/* ── C. Les liaisons : tout ce qui touche un produit dans l'API ───────────────── */
// [nom, routes, ce qu'on voit pour ce produit, état]
const L = {
  definit: ['Ce qui le définit', [
    ['Catégorie', 'GET|PATCH /product-categories/{id}', 'Tartes – Ø 28 cm · coefficient 2,70', 'ec'],
    ['Groupe de catégories', 'GET|PATCH /product-category-groups · POST /product-categories/{id}/groups', 'Tartes (1 des 12 groupes)', 'ec'],
    ['Zone de production', '/shops/production-areas', 'Production Boulangerie (par la catégorie)', 'lu'],
    ['Secteur', '/consultant/product-sectors · /admin/product-sectors', 'Sucré', 'mes'],
    ['Saisons & gammes', '/product-availability-periods · /products/{id}/availability-periods', '3 gammes sur 15', 'ec'],
    ['Traductions', '/products/{id}/aliases', 'fr · nl · en', 'ec']]],
  fabrique: ['Ce qui le fabrique', [
    ['Recette', '/recipes/{id} · /franchise/{f}/product-recipe/{r}/calculation', 'recette 524 · 2 lignes', 'ec'],
    ['Matières premières', '/materials/{id} · /shops/{id}/materials', 'Abricotage · Pomme Tranches 28 Ø', 'ec'],
    ['Fournisseurs & listes de prix', '/material-suppliers/{f}/shops/{s}/price-lists/current', 'SLFood · 7,71 € en vigueur', 'lu'],
    ['Sous-recettes', '/subrecipes · /subrecipe-categories', 'aucune', 'ec'],
    ['Portions', '/products/{id}/portions · /product-portions/{id}', '8 portions', 'ec'],
    ['Compétences', '/product-competences · /products/{id}/competences', 'Tartes — montage', 'mes'],
    ['Production', '/shops/{id}/statistics/production-planning · raw-planogram', 'planifiée chaque jour', 'lu']]],
  range: ['Où il se range, s’emballe, s’étiquette', [
    ['Lieu de stockage', '/product-storage-locations', 'Comptoir Tartes (1 des 8)', 'ec'],
    ['Position en vitrine', '/product-display-positions', 'En longueur face à la vitrine', 'mes'],
    ['Emballage', '/product-packages · /materials/pack-options', 'Boîte tarte 28', 'mes'],
    ['Étiquette & allergènes', '/recipes/product-labels/reload · /products/allergens/document', 'gluten, œufs, lait', 'lu'],
    ['Fiche technique', '/products/{id}/technical-sheet', 'PDF', 'mes']]],
  vend: ['Ce qui le vend', [
    ['Magasins : présence', '/shops/{id}/products/available · /shops/{id}/categories', '4 magasins sur 4', 'lu'],
    ['Magasins : prix', '/shops/{id}/products/{p}/price · /portions/prices', '18,77 → 20,66 € HT', 'ec'],
    ['Stock & inventaire', '/shops/{id}/products/{p}/inventory · /product-movements', 'in_stock, inventaire', 'ec'],
    ['Promotions', '/admin/promotions/bundles · buy-x-get-y · quantity · scheduled-product-discount', '1 remise programmée', 'mes'],
    ['Codes promo & bons', '/promo-codes · /admin/voucher-campaigns', '—', 'mes'],
    ['Commandes clients (B2B)', '/client-orders/{id}/products', 'Gamme B.-2-B.', 'mes']]],
  mesure: ['Ce qu’on en mesure', [
    ['Ventes', '/shops/{id}/statistics/sales/… · /transactions/{id}/products', '2 396 € / 30 j', 'lu'],
    ['Pertes', '/shops/{id}/products/{p}/waste · waste-evidence', 'invendus, poubelle', 'lu'],
    ['Concurrence', '/shops/{id}/benchmarks/analysis/my-products', 'prix des concurrents', 'mes'],
    ['Réclamations', '/material-complaints', '—', 'mes']]],
};
const ETAT = { lu: ['lu par le cockpit', 'lu'], ec: ['lisible et modifiable', 'ec'], mes: ['à mesurer avec le jeton consultant', 'mes'] };
const carte = ([n, r, v, e]) => `<div class="pp-lien ${e}" data-lien><b>${esc(n)}</b><small>${esc(v)}</small><code>${esc(r)}</code><span class="pp-et ${e}">${ETAT[e][0]}</span></div>`;
const colonne = k => `<div class="pp-lcol"><h4>${esc(L[k][0])}</h4>${L[k][1].map(carte).join('')}</div>`;
const nL = Object.values(L).reduce((t, x) => t + x[1].length, 0);
const C = `<div class="pp-cols">${liste}<section class="vr-card pp-fiche">${tete('Liaisons')}<div class="pp-corps">
  <div class="pp-legende"><span><i class="lu"></i>lu par le cockpit aujourd’hui</span><span><i class="ec"></i>lisible et modifiable (route du swagger)</span><span><i class="mes"></i>à mesurer : route au swagger, lecture refusée au jeton consultant ou jamais lue</span><span class="vr-sp"></span><span>${nL} liaisons · ${nL - 2} routes</span></div>
  <div class="pp-carte"><svg class="pp-fils"></svg>${colonne('definit')}${colonne('fabrique')}
  <div class="pp-centre" id="pp-centre"><div class="pp-prod"><small>Produit ${F.pid}</small><b>${esc(F.nom)}</b><span>44 champs</span><code>GET|PATCH|DELETE /products/{id}</code><code>GET|PATCH /shops/{id}/products/{p}</code></div></div>
  ${colonne('vend')}<div>${colonne('range')}${colonne('mesure')}</div></div>
  <div class="pp-pied">Chaque carte ouvre la liaison : sa valeur pour ce produit, et, quand la route l’accepte, sa modification (même chemin : vérifier, écrire, relire, registre). Les liaisons en gris sont au swagger sans avoir été lues : on les mesure une à une avant de les rendre modifiables.</div>
  </div></section></div>`;
const FILS = `<script>(function(){const c=document.querySelector('.pp-carte');if(!c)return;const s=c.querySelector('.pp-fils');const r0=c.getBoundingClientRect();s.setAttribute('width',r0.width);s.setAttribute('height',r0.height);
const p=document.getElementById('pp-centre').querySelector('.pp-prod').getBoundingClientRect();const cx=p.left+p.width/2-r0.left,cy=p.top+p.height/2-r0.top;let h='';
c.querySelectorAll('[data-lien]').forEach(e=>{const r=e.getBoundingClientRect();const g=r.left+r.width/2<p.left+p.width/2;const x=(g?r.right:r.left)-r0.left,y=r.top+r.height/2-r0.top;const px=g?p.left-r0.left:p.right-r0.left;
const cl=e.classList.contains('mes')?'mes':(e.classList.contains('lu')?'lu':'ec');h+='<path class="'+cl+'" d="M'+x+','+y+' C'+((x+px)/2)+','+y+' '+((x+px)/2)+','+cy+' '+px+','+cy+'"/>';});s.innerHTML=h;})();</script>`;

fs.writeFileSync(path.join(D, 'a.html'), page(A));
fs.writeFileSync(path.join(D, 'b.html'), page(B));
fs.writeFileSync(path.join(D, 'c.html'), page(C, FILS));
console.log('a.html, b.html, c.html —', nL, 'liaisons');
