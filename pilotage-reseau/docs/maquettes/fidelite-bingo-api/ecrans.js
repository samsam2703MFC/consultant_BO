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
  <div class="info nm" style="margin-top:0">${ico('info', 18)}<div>Note de maquette : le bingo n’est pas une carte de l’ERP.<p>Ses bons sont des codes des campagnes « Bingo hiver · 2 € » et « Bingo hiver · 10 € », dans Promotions › Bons d’achat. Le cockpit les crée chaque lundi au nom des clients, d’après les points gagnés dans la semaine.</p></div></div>`;
}

// ── 2 · ERP › Modifier la carte à points (étape 2) ────────────────────
function erpCarte() {
  const pc = CARTE_PTS.points_config, b = pc.bonuses[0];
  const champ = (label, ann, val, help = '', cls = '', req = true) => `<div class="field"><label>${label}${req ? '<span class="req"> *</span>' : ''}${ann}</label><div class="inp ${cls}">${val}</div>${help ? `<small>${help}</small>` : ''}</div>`;
  const reg = (n, u) => `<b>${n}</b><span class="u">${u}</span>`;
  const ed = `<div class="panel"><h2>Type de carte</h2><p>Une seule carte, à points. Les cartes à tampons s’arrêtent : le bingo prend leur place.</p>
    <div class="moteurs"><div class="mot off"><span class="ck"><span class="box"></span>Carte produit</span><div class="titre">TAMPONS</div><div class="sous">non utilisée</div><p>Règles du programme et produits offerts masqués.</p><div style="margin-top:9px">${v('points_config.stamps_enabled')}</div></div>
      <div class="mot plein"><span class="ck"><span class="box on"></span>Carte à points</span><div class="titre">POINTS</div><div class="sous">Fait consommer · le panier</div><p>Chaque euro compte, sans plafond. Les points de la semaine font aussi la case du bingo.</p><div style="margin-top:9px">${v('points_config.points_enabled')}</div></div></div></div>
  <div class="panel"><h2>Règle de gain</h2><p>Points sur les produits du programme après réductions et bons. Hors livraison, frais et cadeaux.</p>
    <div class="grid4">${champ('Points par tranche', v('points_per_slice'), reg(1, 'pt'), '', 'set')}${champ('Tranche de dépenses (€)', v('slice_cents'), reg('1,00', '€'), '1 point = 1 € : le cockpit lit la dépense de la semaine dans les points.', 'set')}
      ${champ('Validité du bon (jours)', v('voucher_validity_days'), reg(30, 'jours'), '', 'set')}${champ('Validité des points (jours)', v('points_validity_days'), reg(365, 'jours'), 'Les points survivent à la fin de la saison.', 'set')}</div>
    <h3>Boutiques et coefficients ${v('shop_coefficients')}</h3><div class="grid4">${SHOPS.map(([n, id]) => champ(`${n} (#${id})`, '', reg(1, 'coef.'), '', 'set', false)).join('')}</div>
    <h3>Bonus jour et heure ${v('bonuses')}</h3>
    <div class="rows"><div class="row" style="grid-template-columns:1.7fr .7fr .7fr .7fr auto auto">${champ('Nom', '', b.name, '', '', false)}${champ('De', '', b.from, '', '', false)}${champ('À', '', b.to, '', '', false)}${champ('Coefficient', '', b.coefficient, '', '', false)}
      <div><div class="flabel">Jours : lundi–dimanche</div><div class="days">${[0, 1, 2, 3, 4, 5, 6].map((d) => `<span class="${b.days.includes(d) ? 'on' : ''}">${d + 1}</span>`).join('')}</div></div><span class="btn sm">Supprimer</span></div></div>
    <h3>Paliers de récompense ${v('tiers')}</h3>
    <div class="rows"><div class="row" style="grid-template-columns:1fr 1fr 1fr auto">${champ('Solde de points', '', '100', '', '', false)}${champ('Montant (€)', '', '5,00', '', '', false)}<span class="rate">Retour : 5,00 %</span><span class="btn sm">Supprimer</span></div></div>
    <div class="sim"><h3 style="margin-top:0">Simulateur d’achat</h3><div class="grid3">${champ('Montant (€)', '', '7,50', '', '', false)}${champ('Choisir les boutiques', '', 'Halle (#3)<span class="caret">⌄</span>', '', '', false)}${champ('Date et heure locale de la boutique', '', '13/01/2027 15:30', '', '', false)}</div>
      <div class="simres"><p><code>7 × 1 × 1 × 2 = 14</code> &nbsp;tranches × points par tranche × coefficient boutique × coefficient bonus</p></div></div></div>
  <div class="panel"><h2>Disponibilité du programme</h2><p>Définissez les dates et les boutiques participantes.</p>
    <div class="grid3">${champ('Date de début', v('starts_at'), '22/11/2026 23:00:00', 'Lundi 23/11 00:00, heure belge. UTC.')}${champ('Date de fin', v('ends_at'), '<span class="ph">Sans date de fin</span>', 'Carte permanente : les saisons du bingo passent, la carte reste.', '', false)}${champ('Périmètre', v('shop_selection'), 'Tous les magasins<span class="caret">⌄</span>', '')}</div></div>`;
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
      <div class="regles"><div class="regle"><span class="ic">€</span><div><b>La case se coche toute seule</b><p>Le cockpit lit les points gagnés dans la semaine, tous magasins (<em>1 point = 1 €</em>). Dès <em>10</em> points, la case est cochée. Une case par semaine au plus.</p></div></div>
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
  <div class="cols" style="grid-template-columns:minmax(0,1fr) 380px"><section class="panel"><h2>La case, semaine par semaine</h2><p>Part des membres qui ont atteint 10 € dans la semaine (points gagnés, lus dans l’ERP).</p>
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
ecran(4, 'Cockpit', 'Fidélité › Magasins : la case, semaine par semaine', 'src/fidelite_bingo.php › GET /fidelite/clients?shop=3', railCockpit(), magasins());
ecran(5, 'Cockpit', 'Fidélité › Un client : sa carte de bingo, ses points, ses bons', 'src/fidelite_bingo.php › GET /fidelite/client?id=…', railCockpit(), client());
