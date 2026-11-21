/* Maquettes « Réclamations fournisseur : les photos, et le tableau annuel réclamé / livré » (demande du 21/11/2026 :
 * « est-ce que je peux avoir à chaque fois les photos ? et aussi un tableau annuel (volume réclamé / volume vendu, sur le
 * client et en général) — faire les maquettes en screenshot et ensuite décider »).
 *
 *   node consultant/docs/maquettes/reclamations-photos-volumes/generer.js   → a.html, b.html, c.html
 *   node consultant/docs/maquettes/reclamations-photos-volumes/capturer.js  → a.png, b.png, c.png
 *
 * Données : les 14 réclamations visibles sur la capture de l'écran du 21/11/2026 (avant.png), recopiées telles quelles.
 * Les VOLUMES LIVRÉS et les mois hors capture sont des chiffres d'ILLUSTRATION (le serveur n'était pas joignable) :
 * chaque écran le dit. Les deux photos sont celles des maquettes panel/docs/maquettes/reclamations (illustration). */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (v, d = 0) => v == null ? '—' : Number(v).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ /g, ' ');
const eur = v => v == null ? '—' : nf(v, 2) + ' €';
const pct = v => v == null ? '—' : nf(v, v < 1 ? 2 : 1) + ' %';

/* ── le cadre du cockpit (repris des maquettes du helpdesk) ───────────────── */
let CHROME = fs.readFileSync(path.join(D, '..', 'helpdesk-franchises', 'chrome.html'), 'utf8');
CHROME = CHROME.split('../../../public/assets/').join('../../../../shared/public/assets/');
function page(contenu, titre){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../../shared/public/assets/css/controles-photo.css">',
    '<link rel="stylesheet" href="../../../../shared/public/assets/css/verification.css">\n<link rel="stylesheet" href="../../../../shared/public/assets/css/helpdesk.css">\n<link rel="stylesheet" href="rp.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>' + esc(titre) + ' — Cockpit CEO (maquette)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  h = h.replace(/(<div class="sidebar__group-label">Franchisés<\/div>)/i, '$1<button class="sidebar__item is-active" aria-current="page"><svg class="ico" viewBox="0 0 24 24"><path d="M4 5h16v11H8l-4 4zM8 9h8M8 12h5"></path></svg><span class="sidebar__item-label">Demandes des franchisés</span><span class="hd-badge">9</span></button>');
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Demandes des franchisés</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="hd-pill">Maquette · ' + esc(titre) + '</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  return h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, `<div class="vr hd">${contenu}</div>`);
}
const onglets = actif => `<div class="hd-onglets">${[['dem', 'Demandes au bureau', '9 ouvertes'], ['rc', 'Réclamations fournisseur', '7 ouvertes · tous les magasins'], ['an', 'Tableau annuel', 'réclamé / livré · nouveau']]
  .map(([id, n, s]) => `<button class="${id === actif ? 'on' : ''}">${esc(n)}<small>${esc(s)}</small></button>`).join('')}</div>`;
const kpis = l => `<div class="vm-kpis" style="grid-template-columns:repeat(${l.length},1fr)">${l.map(k => `<div class="vm-kpi ${k[3] || ''}"><div class="k">${esc(k[0])}</div><div class="v">${k[1]}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
const illu = t => `<span class="rp-illu">${esc(t || 'chiffres d’illustration')}</span>`;

/* ── A. Les photos, à chaque réclamation ──────────────────────────────────── */
// Les 14 réclamations de la capture du 21/11/2026 (date, magasin, référence, qté, SKU, motif, texte, statut, réponse, montant)
// + le nombre de photos (illustration : la capture ne montre que « Pièces jointes 1 » pour le pain au chocolat).
const RC = [
  ['20/11', '1 j', 'Gosselies', 'Soupe Potiron & Citron (6 x 1l.)', '1 pcs', '1003985', 'Problème de qualité du produit', 'Pomme de terre noire', 'Ouverte', 'att', 'nouvelle', 5.23, 2],
  ['20/11', '1 j', 'Gosselies', 'Soupe Potiron & Citron (6 x 1l.)', '6 pcs', '1003985', 'Problème de taille ou de poids', 'Pomme de terre noire', 'Ouverte', 'att', 'nouvelle', 31.37, 0],
  ['19/11', '2 j', 'Gosselies', 'Cougnou Sucre 200 g. - Cru', '126 pcs', '1003088', 'Problème de taille ou de poids', 'Probleme de pousse, 5h00 a 30°. Cougnous pale et non développer', 'Refusée', 'mu', 'répondue le 20/11', 123.87, 0],
  ['10/11', '11 j', 'Gosselies', 'Fond de pâte - 28 Ø', '10 pcs', '2580106', 'Non-conformité avec la commande', '2x boite complete renvoyée chauffeur , non conforme , merci de rembourser', 'Refusée', 'mu', 'répondue le 12/11', 38.61, 1],
  ['10/11', '11 j', 'Gosselies', 'Fond de pâte - 28 Ø', '10 pcs', '2580106', 'Non-conformité avec la commande', 'merci d’effectuer le remboursement, car non conforme à ma commande et renvoyé via le chau…', 'Refusée', 'mu', 'répondue le 12/11', 38.61, 0],
  ['05/11', '16 j', 'Halle', 'Croissant', '25 pcs', '1001958', 'Problème de qualité du produit', 'Pièces jetées en caisse le 05/11 : 25 × Croissant, motif « qualité ». Pas poussé, pâte mo…', 'Acceptée', 'ok', 'répondue le 09/11', 17.47, 3],
  ['09/10', '43 j', 'Halle', 'Croissant', '110 pcs', '1001958', 'Problème de qualité du produit', 'Pièces jetées en caisse le 09/10 : 110 × Croissant, motif « qualité ».', 'Acceptée', 'ok', 'répondue le 12/10', 76.88, 2],
  ['09/10', '43 j', 'Halle', 'Croissant', '110 pcs', '1001958', 'Problème de qualité du produit', 'Ne poussent pas correctement', 'Acceptée', 'ok', 'répondue le 12/10', 76.88, 1],
  ['05/10', '47 j', 'Gosselies', 'Pain au Chocolat', '56 pcs', '1002343', 'Quantité incorrecte de produits dans l’emballage', 'dans une boite de 56, j’ai des sachets incomplet , des pains au chocolat de 50g et d’autre…', 'Refusée', 'mu', 'répondue le 13/10', 45.06, 2],
  ['02/10', '50 j', 'Gosselies', 'Riz Croustillant - 28 Ø', '8 pcs', '2580116', 'Problème de taille ou de poids', 'Non conforme à la qualité prévue', 'Refusée', 'mu', 'répondue le 13/10', 73.46, 0],
  ['01/10', '51 j', 'Gosselies', 'Tendresse au Chocolat', '40 pcs', '1008248', 'Problème de taille ou de poids', 'pas reçu lors de la précédente livraison', 'Ouverte', 'att', 'nouvelle', 43.96, 0],
  ['01/10', '51 j', 'Gosselies', 'Blanc (Pauvre en Gluten) 600 g.', '1 pcs', '1002518', 'Non-conformité avec la commande', 'Test — signalé par Sam Photos (1) : https://185.180.206.46/consulant_bo/uploads/reclamat…', 'Ouverte', 'att', 'nouvelle', 2.21, 1],
  ['30/09', '52 j', 'Gosselies', 'Riz Croustillant - 28 Ø', '10 pcs', '2580116', 'Problème de qualité du produit', 'problème de recette, le temps de cuisson est doublée mais la composition reste liquide et…', 'Refusée', 'mu', 'répondue le 01/10', 91.83, 1],
  ['30/09', '52 j', 'Gosselies', 'Tendresse au Chocolat', '40 pcs', '1008248', 'Autre raison', 'Commande… Facturé, lors de la réception marchandise, inventaire de vérif fait et pas de te…', 'Ouverte', 'att', 'nouvelle', 43.96, 0]];
const SEL = 8;
const PH = ['photos/viennoiseries.jpg', 'photos/vitrine.jpg'];
const ligneA = (r, i) => {
  const [le, age, mag, ref, q, sku, motif, txt, st, ton, sous, mt, nph] = r;
  const vign = nph ? `<span class="rp-vign"><img src="${PH[i % 2]}" alt=""><b>${nph}</b></span>` : '<span class="rp-sansph">sans photo</span>';
  return `<tr class="${i === SEL ? 'hd-on' : ''}"><td class="hd-lis ${ton === 'mu' ? 'mu' : ton}"></td>
    <td class="g">${le}<small class="${parseInt(age) > 45 && st === 'Ouverte' ? 'ko' : ''}">${age}</small></td><td class="g">${mag}</td><td class="g">SLFood</td>
    <td class="g hd-titre"><b>${esc(ref)}</b><small>${q} · SKU ${sku}</small></td>
    <td class="g hd-titre"><span class="hd-dom">${esc(motif)}</span><small>${esc(txt.slice(0, 74))}${txt.length > 74 ? '…' : ''}</small></td>
    <td>${vign}</td>
    <td><span class="hd-st ${ton}">${st}</span><small>${sous}</small></td><td>${eur(mt)}</td></tr>`;
};
const ficheA = `<aside class="vr-card hd-fiche rp-fiche"><div class="hd-ft"><div><span class="hd-ref">CMP-3-2D0AB6898DA0924A</span><h3>Pain au Chocolat · SLFood</h3><small>Gosselies · le 05/10 (47 j)</small></div><span class="hd-st mu">Refusée</span></div>
  <div class="hd-desc">dans une boite de 56, j’ai des sachets incomplet , des pains au chocolat de 50g et d’autre de 100gr... Merci de veillez à votre régularité</div>
  <div class="rp-gal"><div class="rp-gal-h"><b>Photos du franchisé · 2</b><span>tablette de Gosselies · 05/10 à 07:42</span></div>
    <div class="rp-gal-g"><figure class="rp-big"><img src="${PH[0]}" alt=""><figcaption>1 / 2 · cliquer pour agrandir</figcaption></figure>
    <div class="rp-th"><img class="on" src="${PH[0]}" alt=""><img src="${PH[1]}" alt=""><button>Tout télécharger</button></div></div></div>
  <div class="hd-rc-kv"><span>Référence</span><b>Pain au Chocolat · SKU 1002343</b><span>Quantité</span><b>56 pcs</b><span>Motif</span><b>Quantité incorrecte de produits dans l’emballage</b><span>Ce qui est demandé</span><b>REPLACEMENT</b><span>Commande</span><b>ORD-3-AF56438EF5731FB4</b><span>Montant</span><b>45,06 € (0,80 € / unité)</b><span>Quantité acceptée</span><b>0</b></div>
  <div class="rp-part"><div class="rp-part-h"><b>Part de ce produit dans ce qui a été livré à Gosselies</b>${illu()}</div>
    <div class="rp-part-g">${[['Semaine 40', 56, 280], ['Octobre', 56, 1120], ['2026', 56, 9800]].map(([p, r, l]) => `<div><span>${p}</span><b>${pct(100 * r / l)}</b><small>${r} réclamés / ${nf(l)} livrés</small><i style="width:${Math.min(100, 100 * r / l * 4)}%"></i></div>`).join('')}</div>
    <small class="rp-mu">Réseau, 2026 : ${pct(0.31)} des pains au chocolat livrés réclamés · Gosselies : ${pct(100 * 56 / 9800)}</small></div>
  <div class="hd-dejarep"><b>Réponse du fournisseur · 13/10</b>Vous déposez une réclamation. Il ne s’agit pas d’un problème de quantité, mais plutôt de la quantité des 60 chocolats que vous prétendiez avoir reçus le 22 septembre 2026. Vous avez reçu 7 sachets de 8 chocolats et 1 sachet de 4 chocolats.</div></aside>`;
const filA = `<div class="vt-fil"><div class="vr-chips hd-chips"><button class="on">Toutes <b>25</b></button><button>Ouvertes <b>7</b></button><button>Réglées <b>12</b></button><button>Refusées <b>6</b></button><button class="rp-chip-ko">Sans photo <b>9</b></button></div>
  <select class="vr-sel"><option>Tous les magasins</option></select><select class="vr-sel"><option>Tous les fournisseurs</option></select><select class="vr-sel"><option>3 derniers mois</option></select>
  <input class="vm-q" type="search" placeholder="Chercher une référence, un motif…"><span class="vr-sp"></span><button class="vr-btn">Relire</button></div>`;
const A = page(`${onglets('rc')}<div class="vr-card">${kpis([['Ouvertes', '<span class="rp-att">7</span>', 'sans réponse du fournisseur'], ['Montant ouvert', '<span class="rp-ko">126,73 €</span>', 'au prix d’achat (prix incomplets)'], ['Plus ancienne', '<span class="rp-ko">89 j</span>', 'Gosselies, le 24/08'], ['Réglées', '<span class="rp-ok">12</span>', 'réponse du fournisseur'], ['Refusées', '6', 'par le fournisseur'], ['Avec photo', '16 / 25', '9 sans photo · nouveau']])}
  ${filA}<div class="hd-cols rp-cols"><div class="vr-tw"><table class="vr-t hd-t"><thead><tr><th></th><th class="g">Date</th><th class="g">Magasin</th><th class="g">Fournisseur</th><th class="g">Référence</th><th class="g">Motif</th><th>Photos</th><th>Statut</th><th>Montant</th></tr></thead>
  <tbody>${RC.map(ligneA).join('')}</tbody></table></div>${ficheA}</div>
  <div class="vr-pied"><b>Nouveau :</b> la colonne <b>Photos</b> (vignette et nombre), le filtre <b>Sans photo</b>, et dans la fiche les photos en grand avec « Tout télécharger » et la part du produit dans ce qui a été livré (semaine, mois, année ; magasin et réseau). Les photos viennent des pièces jointes de la réclamation au panel (lien signé, relu à l’ouverture de la fiche) et de celles prises depuis le dashboard. <span class="vr-mu">Réclamations : capture du 21/11/2026 · nombres de photos et volumes livrés : illustration.</span></div></div>`, 'A · les photos à chaque réclamation');

/* ── B. Le tableau annuel par magasin (montant réclamé / livré, mois par mois) ─ */
// Illustration déterministe : un générateur à graine fixe (pas d'aléa d'une génération à l'autre).
let g = 7; const rnd = () => (g = (g * 9301 + 49297) % 233280) / 233280;
const MOIS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
const MAGS = [['Gosselies', 14500, 3.2], ['Halle', 16800, 0.9], ['Corbais', 11200, 1.1], ['Sombreffe', 9800, 0.6]];
const grille = MAGS.map(([m, liv, base]) => ({ m, mois: MOIS.map((_, i) => {
  if (i > 10) { return null; }
  const l = Math.round(liv * (0.85 + rnd() * 0.3) * (i === 11 ? 0 : 1));
  const r = Math.round(l * base / 100 * (0.3 + rnd() * 1.4) * 100) / 100;
  const a = Math.round(r * (m === 'Gosselies' ? 0.25 : 0.7) * (0.6 + rnd() * 0.5) * 100) / 100;
  return { l, r, a, n: Math.max(1, Math.round(r / 38)) };
}) }));
const tot = rows => rows.reduce((s, x) => x ? { l: s.l + x.l, r: s.r + x.r, a: s.a + x.a, n: s.n + x.n } : s, { l: 0, r: 0, a: 0, n: 0 });
const reseau = MOIS.map((_, i) => i > 10 ? null : tot(grille.map(x => x.mois[i])));
const chaleur = t => t >= 3 ? 'h3' : t >= 1.5 ? 'h2' : t >= 0.75 ? 'h1' : 'h0';
const cell = x => x ? `<td class="rp-c ${chaleur(100 * x.r / x.l)}"><b>${nf(x.r, 0)} €</b><small>${pct(100 * x.r / x.l)}</small></td>` : '<td class="rp-c rp-fut">—</td>';
const ligneB = (nom, cells, fort) => { const t = tot(cells); return `<tr class="${fort ? 'rp-tot' : ''}"><td class="g"><b>${nom}</b><small>${nf(t.n)} réclamations</small></td>${cells.map(cell).join('')}
  <td class="rp-c ${chaleur(100 * t.r / t.l)}"><b>${nf(t.r, 0)} €</b><small>${pct(100 * t.r / t.l)}</small></td><td class="r">${nf(t.l, 0)} €</td><td class="r">${nf(t.a, 0)} €<small>${pct(100 * t.a / t.r)} du réclamé</small></td></tr>`; };
const T = tot(reseau);
const B = page(`${onglets('an')}<div class="vr-card">
  <div class="vt-fil"><div class="vr-chips hd-chips"><button class="on">Par magasin</button><button>Par produit</button></div>
  <select class="vr-sel"><option>2026</option></select><select class="vr-sel"><option>SLFood</option></select><div class="rp-seg"><span>Afficher</span><button class="on">€ et taux</button><button>Pièces</button><button>Nombre</button></div><span class="vr-sp"></span>${illu('volumes livrés et mois hors capture : illustration')}<button class="vr-btn">Excel</button><button class="vr-btn">PDF</button></div>
  ${kpis([['Réclamé 2026', nf(T.r, 0) + ' €', nf(T.n) + ' réclamations · 4 magasins'], ['Livré 2026', nf(T.l, 0) + ' €', 'au prix d’achat, SLFood'], ['Taux de réclamation', `<span class="rp-att">${pct(100 * T.r / T.l)}</span>`, 'réclamé ÷ livré'], ['Accepté', nf(T.a, 0) + ' €', pct(100 * T.a / T.r) + ' du réclamé'], ['Le plus haut', '<span class="rp-ko">Gosselies</span>', pct(100 * tot(grille[0].mois).r / tot(grille[0].mois).l) + ' du livré, ' + nf((tot(grille[0].mois).r / tot(grille[0].mois).l) / (T.r / T.l), 1) + ' × le réseau', 'ko']])}
  <div class="vr-tw"><table class="vr-t rp-an"><thead><tr><th class="g">Magasin</th>${MOIS.map(m => `<th>${m}</th>`).join('')}<th>Année</th><th class="r">Livré</th><th class="r">Accepté</th></tr></thead>
  <tbody>${grille.map(x => ligneB(x.m, x.mois)).join('')}${ligneB('Réseau', reseau, true)}</tbody></table></div>
  <div class="rp-leg"><span>Taux réclamé ÷ livré :</span><i class="h0"></i>&lt; 0,75 %<i class="h1"></i>0,75 – 1,5 %<i class="h2"></i>1,5 – 3 %<i class="h3"></i>≥ 3 %<span class="vr-sp"></span>Cliquer une case : les réclamations de ce magasin ce mois-là.</div>
  <div class="vr-pied"><b>Chaque case :</b> le montant réclamé au fournisseur ce mois-là, et sa part dans ce qui a été livré au magasin le même mois (au prix d’achat). « Livré » vient des livraisons fournisseur reçues au panel (<code>material_order_item_delivery_log</code> : packs livrés × prix du pack). <span class="vr-mu">Maquette : chiffres d’illustration ; décembre est à venir.</span></div></div>`, 'B · tableau annuel par magasin');

/* ── C. Le tableau annuel par produit, le client face au réseau ───────────── */
const PROD = [['Croissant', 1001958, 0.42, 0.44], ['Pain au Chocolat', 1002343, 0.8, 0.31], ['Cougnou Sucre 200 g. - Cru', 1003088, 0.98, 0.0], ['Riz Croustillant - 28 Ø', 2580116, 9.18, 2.6], ['Tendresse au Chocolat', 1008248, 1.1, 1.4], ['Fond de pâte - 28 Ø', 2580106, 3.86, 0.9], ['Soupe Potiron & Citron (6 x 1l.)', 1003985, 5.23, 0.7]];
const prodRows = (client) => PROD.map(([n, sku, pu, reseauPct], k) => {
  const liv = Math.round((client ? 1 : 4.2) * (k === 2 ? 900 : k === 3 ? 260 : k === 5 ? 320 : k === 6 ? 140 : 2600 + 1400 * rnd()));
  const rec = client ? [0, 56, 126, 18, 80, 20, 7][k] : Math.round(liv * reseauPct / 100) + [245, 56, 126, 18, 80, 20, 7][k];
  const sem = client ? [0, 20, 31.5, 38, 12.5, 15.6, 18][k] : [3.2, 6, 14, 12, 4.4, 4.1, 5.5][k];
  const moisMax = client ? [0, 5, 14, 11.5, 6.1, 6.2, 5][k] : [1.1, 1.4, 4.2, 3.9, 1.6, 1.5, 1.4][k];
  const spark = MOIS.slice(0, 11).map((_, i) => rec === 0 ? 0 : Math.max(0, (rec / liv) * 100 * (0.2 + rnd() * 1.8) * (i > 7 ? 1.4 : 0.8)));
  return { n, sku, liv, rec, an: 100 * rec / liv, sem, moisMax, mt: rec * pu, spark };
}).sort((a, b) => b.an - a.an);
const sparkSvg = v => { const m = Math.max(...v, 0.01); return `<svg class="rp-sp" viewBox="0 0 110 26"><polyline points="${v.map((y, i) => `${i * 10 + 2},${24 - 21 * y / m}`).join(' ')}"/></svg>`; };
const tabC = (titre, sous, rows) => `<div class="rp-colC"><div class="rp-colC-h"><b>${titre}</b><small>${sous}</small></div><table class="vr-t rp-prod"><thead><tr><th class="g">Produit</th><th class="r">Livré</th><th class="r">Réclamé</th><th class="r">Année</th><th class="r">Pire mois</th><th class="r">Pire semaine</th><th class="r">Montant</th><th>Janv. → nov.</th></tr></thead>
  <tbody>${rows.map(r => `<tr><td class="g"><b>${esc(r.n)}</b><small>SKU ${r.sku}</small></td><td class="r">${nf(r.liv)} pcs</td><td class="r">${nf(r.rec)} pcs</td><td class="r"><span class="rp-tx ${chaleur(r.an)}">${pct(r.an)}</span></td><td class="r">${pct(r.moisMax)}</td><td class="r">${pct(r.sem)}</td><td class="r">${eur(r.mt)}</td><td>${sparkSvg(r.spark)}</td></tr>`).join('')}</tbody></table></div>`;
const C = page(`${onglets('an')}<div class="vr-card">
  <div class="vt-fil"><div class="vr-chips hd-chips"><button>Par magasin</button><button class="on">Par produit</button></div>
  <select class="vr-sel"><option>2026</option></select><select class="vr-sel"><option>SLFood</option></select><select class="vr-sel rp-on"><option>Gosselies face au réseau</option></select><div class="rp-seg"><span>Trier par</span><button class="on">Taux annuel</button><button>Montant</button><button>Pire semaine</button></div><span class="vr-sp"></span>${illu('volumes livrés : illustration')}<button class="vr-btn">Excel</button><button class="vr-btn">PDF</button></div>
  <div class="rp-dux">${tabC('Gosselies', 'le client · 2026 au 21/11', prodRows(true))}${tabC('Réseau', '4 magasins · 2026 au 21/11', prodRows(false))}</div>
  <div class="vr-pied"><b>Pour chaque produit :</b> les pièces livrées au magasin (ou au réseau) dans l’année, les pièces réclamées, et la part réclamée sur l’année, sur le pire mois et sur la pire semaine ; le montant au prix d’achat ; la courbe du taux mois par mois. Un produit qui se détache côté client mais pas côté réseau pointe le magasin ; s’il se détache des deux côtés, il pointe le fournisseur. <span class="vr-mu">Maquette : volumes livrés d’illustration ; produits et prix unitaires repris de la capture du 21/11/2026.</span></div></div>`, 'C · tableau annuel par produit, client face au réseau');

fs.writeFileSync(path.join(D, 'a.html'), A);
fs.writeFileSync(path.join(D, 'b.html'), B);
fs.writeFileSync(path.join(D, 'c.html'), C);
console.log('a.html, b.html, c.html écrits');
