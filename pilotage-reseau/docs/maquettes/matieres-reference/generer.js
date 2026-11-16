/* Maquette « Recettes à corriger › Matières premières » (demande du 14/11/2026 : « en toggle un tableau avec toutes
 * les matières premières utilisées dans les recettes, où je peux adapter avec le prix référence fournisseur, en liste
 * déroulante »).
 *
 *   node docs/maquettes/matieres-reference/generer.js    → a.html
 *   node docs/maquettes/matieres-reference/capturer.js   → a.png, b.png
 *
 * Le décor : chrome.html (le cockpit rendu tel quel, repris de recettes-correction), la section Vérification du rail,
 * la page en pleine largeur (app__body--wide). Les styles de la page en ligne (verification.css : vr-, rc-) ; mm.css
 * n'ajoute que le neuf. Données : noms, catégories, sources (centrale / libre), fournisseurs et listes de prix lus en
 * ligne le 14/11/2026 par /analyse/matieres/fiche (lecture seule) ; les compteurs d'emploi et de CA sont d'illustration. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const ICO = {
  liens: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
  recherche: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2', form: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5', fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9', creer: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
};
const rail = (ico, nom, on, n) => `
    <button class="sidebar__item${on ? ' is-active" aria-current="page' : ''}"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICO[ico]}"></path></svg><span class="sidebar__item-label">${esc(nom)}</span>${n ? `<span class="sidebar__count">${n}</span>` : ''}</button>`;
const GROUPE = `<div class="sidebar__group"><div class="sidebar__group-label">Vérification</div>${rail('liens', 'Audit des flux')}${rail('recherche', 'Fuites par magasin')}${rail('form', 'Inventaires')}${rail('modeles', 'Matières ↔ recettes')}${rail('fonds', 'Chiffre d’affaires')}${rail('journal', 'Mouvements d’un produit')}${rail('creer', 'Recettes à corriger', true, 212)}${rail('perf', 'Évolution')}</div>
        `;
const INTRO = 'Chaque produit vendu doit avoir une recette complète, chiffrée et plausible : sinon l’écart de matière, la marge et le CA attendu sont faux. La bascule « Matières premières » montre les matières de ces recettes et leur prix dans chaque magasin, face au prix de référence de leur fournisseur.';

function page(contenu){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="mm.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>Matières premières — Cockpit CEO (maquette)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  h = h.replace(/<div class="sidebar__group">\s*<div class="sidebar__group-label">Produits<\/div>/, m => GROUPE + m);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Recettes à corriger</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="mm-pill">Maquette · noms et fournisseurs réels, montants en partie d’illustration</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, `<p class="shell-sub page-intro">${esc(INTRO)}</p>`);
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  h = h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, contenu);
  return h;
}

/* Les magasins dans l'ordre du réseau. */
const MAGS = ['Halle', 'Gosselies', 'Corbais', 'Sombreffe'];
const nf = (n, d) => n.toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const px = (v, u) => `${nf(v, v < 1 ? 4 : (u === 'pce' && v < 10 ? 3 : 2))} €`;

/* Une cellule de prix : ok (égal à la référence), att (écart), ko (sans prix), new (choisi, à écrire). */
const cell = (c, u) => {
  if (!c) { return '<span class="mm-p ko">sans prix</span>'; }
  const [v, t, s] = c;
  return `<span class="mm-p ${t}">${px(v, u)}${s ? `<small>${esc(s)}</small>` : ''}</span>`;
};

/* Les matières. `ref` : ce que montre la liste déroulante fermée ; `ouvert` : la liste ouverte ; `a` : appliquer à. */
const L = [
  { nom: 'Poche Sauce Tikka', cat: 'Plat Prepare - Chaud', src: 'c', u: 'kg', emploi: '<b>4 recettes</b> · 1 380 € de CA', prix: [null, null, null, null],
    ref: { txt: 'Berdiff s.a. · 5,42 € / kg', sous: 'colis de 4 poches à 10,83 € · poche de 500 g', cls: 'choisi ouvert' }, ouvert: true, a: 'Les 4 magasins' },
  { nom: 'Mayonnaise', cat: 'Matières premières (traiteur)', src: 'c', u: 'kg', emploi: '<b>9 recettes</b> · 6 460 € — Plateau mini sandwiches - 30 pièces, - 20 pièces…', prix: [null, null, null, null],
    ref: { txt: 'Choisir un prix…', sous: 'aucune liste de prix fournisseur liée', cls: 'vide' }, a: '—' },
  { nom: 'Houmous', cat: 'Matières premières (traiteur)', src: 'c', u: 'kg', emploi: '<b>3 recettes</b> · 4 860 € — Plateau mini sandwiches - 30 pièces…', prix: [null, null, null, null],
    ref: { txt: 'Choisir un prix…', sous: 'aucune liste de prix fournisseur liée', cls: 'vide' }, a: '—' },
  { nom: 'Fondant chocolat', cat: 'Matières premières (boulangerie)', src: '', u: 'kg', emploi: '<b>1 recette</b> · 2 640 € — Pain au Chocolat Nappé', prix: [[2.56, 'new', 'à écrire'], [2.56, 'ok'], [2.56, 'ok'], [2.56, 'ok']],
    ref: { txt: 'Médiane du réseau · 2,56 € / kg', sous: '3 magasins sur 4 · Bruyerre S.A. : liste de prix non lue', cls: 'choisi' }, a: 'Halle (sans prix)', impact: true },
  { nom: 'Frangipane & Abricots – 28 Ø', cat: 'Tartes - 28', src: 'c', u: 'pce', emploi: '<b>1 recette</b> · 1 130 € — Frangipane & Abricots – 28 Ø', prix: [[9.622, 'ok'], [9.622, 'new', 'à écrire'], [9.622, 'new', 'à écrire'], [9.622, 'new', 'à écrire']],
    ref: { txt: 'SLFood · 9,622 € / pce', sous: 'colis de 10 pcs à 96,22 € · en vigueur depuis le 09/10', cls: 'choisi' }, a: 'Les 3 magasins sans prix' },
  { nom: 'Cougnou - Nature 400 g. - Cru', cat: 'Noël', src: 'c', u: 'pce', emploi: '<b>1 recette</b> · pas encore vendu', prix: [null, null, null, null],
    ref: { txt: 'SLFood · 2,408 € / pce — disponible', sous: 'colis de 24 pcs à 57,78 € · en vigueur depuis le 02/11', cls: 'vide' }, a: '—' },
  { nom: 'Sucre (Blanc)', cat: 'Matières premières (boulangerie)', src: '', u: 'kg', emploi: '<b>14 recettes</b> · 9 210 €', prix: [null, [1.89, 'ok'], [1.89, 'ok'], [1.89, 'ok']],
    ref: { txt: 'Médiane du réseau · 1,89 € / kg — disponible', sous: 'Bruyerre S.A. : liste de prix non lue', cls: 'vide' }, a: '—' },
  { nom: 'Oignons grillés', cat: 'Matières premières (viandes)', src: '', u: 'kg', emploi: '<b>5 recettes</b> · 2 120 €', prix: [null, [9.89, 'ok'], [9.89, 'ok'], [9.89, 'ok']],
    ref: { txt: 'Médiane du réseau · 9,89 € / kg — disponible', sous: 'aucun fournisseur lié', cls: 'vide' }, a: '—' },
  { nom: 'Poulet effiloché', cat: 'Matières premières (traiteur)', src: '', u: 'kg', emploi: '<b>6 recettes</b> · 3 940 €', prix: [null, [19.8, 'ok'], [19.8, 'ok'], [19.8, 'ok']],
    ref: { txt: 'Médiane du réseau · 19,80 € / kg — disponible', sous: 'aucun fournisseur lié', cls: 'vide' }, a: '—' },
  { nom: 'Épices poulet', cat: 'Matières premières (traiteur)', src: '', u: 'kg', emploi: '<b>3 recettes</b> · 2 262 € — Wrapps - Poulet & Parmesan…', prix: [[12.9, 'ok'], [12.9, 'ok'], [12.9, 'ok'], [12.9, 'ok']],
    ref: { txt: 'Médiane du réseau · 12,90 € / kg', sous: 'les 4 magasins alignés', cls: '' }, a: '—' },
  { nom: 'iceberg', cat: 'Matières premières (légumes)', src: 'c', u: 'kg', emploi: '<b>5 recettes</b> · 3 310 € — Wrapps - Poulet & Parmesan…', prix: [null, null, null, null],
    ref: { txt: 'Choisir un prix…', sous: 'aucune liste de prix fournisseur liée', cls: 'vide' }, a: '—' },
  { nom: 'Boîte pâtissière carton blanc - 250x250x80 PEFC', cat: 'Matières premières (Emballage)', src: '', u: 'kg', emploi: 'hors recette (emballage)', prix: [null, [188.6, 'att', 'compté en g'], [188.6, 'att', 'compté en g'], [188.6, 'att', 'compté en g']],
    ref: { txt: 'Unité à corriger au panel ↗', sous: 'une boîte se compte en pièces, pas en grammes', cls: 'vide' }, a: '—' },
];

const menuTikka = `<div class="mm-menu">
  <h5>Liste de prix du fournisseur, en vigueur</h5>
  <div class="mm-opt on alerte"><b>Berdiff s.a.</b><span class="px"><b>2,71 € / poche</b></span>
    <small>Colis de 4 pcs à 10,83 € HT · en vigueur depuis le 21/08 · centrale. La matière se compte en grammes, le colis en pièces : poids d’une poche <input class="rc-in mod" value="500"> g → <b>5,42 € / kg</b></small></div>
  <h5>Le réseau</h5>
  <div class="mm-opt"><span class="vr-mu">Prix des autres magasins</span><span class="px vr-mu">aucun ne la chiffre</span></div>
  <h5>Autre</h5>
  <div class="mm-opt"><b>Saisir un prix</b><span class="px"><input class="rc-in" placeholder="€ / kg"></span></div>
  <div class="mm-opt"><span class="vr-mu">Laisser sans prix</span><span></span></div>
</div>`;

function ligne(l){
  const ddd = `<div class="mm-dd"><button class="mm-dd-btn ${l.ref.cls}">${esc(l.ref.txt)}<small>${esc(l.ref.sous)}</small></button>${l.ouvert ? menuTikka : ''}</div>`;
  const a = l.a === '—' ? '<span class="vr-mu">—</span>' : `<select class="mm-a"><option>${esc(l.a)}</option></select>`;
  const tr = `<tr${l.ouvert || l.impact ? ' class="mm-on"' : ''}><td class="g nom vr-ouvre"><b>${esc(l.nom)}</b><small>${esc(l.cat)}<span class="mm-src${l.src ? ' c' : ''}">${l.src ? 'centrale' : 'libre'}</span> · € / ${l.u}</small></td>
    <td class="g mm-emploi">${l.emploi}</td>${l.prix.map(c => `<td class="mm-px">${cell(c, l.u)}</td>`).join('')}<td>${ddd}</td><td>${a}</td></tr>`;
  if (!l.impact) { return tr; }
  return tr + `<tr class="mm-imp"><td colspan="8"><div class="mm-impact"><div class="rc-bloc"><h4>Ce que change le prix choisi — Fondant chocolat à Halle, 2,56 € / kg</h4>
    <table class="vr-t"><thead><tr><th class="g">Recette</th><th>Quantité<small>par pièce</small></th><th>Coût de la pièce<small>Halle, avant</small></th><th>Après</th><th>Matière<small>% du prix, après</small></th></tr></thead><tbody>
    <tr><td class="g nom"><b>Pain au Chocolat Nappé</b></td><td>12 g</td><td><span class="rc-av">0,35 €</span></td><td><b class="rc-ap">0,38 €</b></td><td><span class="vx-p ok">24 %</span></td></tr>
    </tbody></table></div><div class="vr-mu" style="flex:1 1 280px;font-size:11.5px;line-height:1.5;padding-top:8px">Le signalement « matière sans prix » de ces recettes à Halle disparaît après l’écriture. Le prix part au panel par la route réglée dans le registre (<span class="rc-code">POST /materials/{mid}/manual-price</span>), il est relu, et « Annuler » remet l’état d’avant.</div></div></td></tr>`;
}

function ecran(){
  const kpis = [['Matières dans les recettes', '412', 'celles des produits vendus ces 30 jours', ''], ['Sans prix dans un magasin', '75', 'au moins un magasin qui vend la recette ne la chiffre pas', 'ko'],
    ['Centrale sans liste de prix', '58', 'aucun prix de référence fournisseur lié au panel', 'att'], ['Écart à la référence', '9', 'plus de 10 % entre le magasin et le prix de référence', 'att'], ['Unité à vérifier', '3', 'colis en pièces, matière en grammes (ou l’inverse)', 'att']];
  const chips = [['Toutes', 412, ''], ['Sans prix', 75, 'pb-prix'], ['Écart à la référence', 9, 'pb-reseau'], ['Sans fournisseur lié', 58, 'pb-orph'], ['Unité à vérifier', 3, 'pb-unite'], ['Prix choisis, à écrire', 4, 'pb-port']];
  return `<div data-screen="verification-recettes" class="vr mm-page">
  <div class="vr-haut"><div class="mm-bascule"><button>Recettes à corriger <b>212</b></button><button class="on">Matières premières <b>412</b></button></div><span class="vr-sp"></span><span class="vr-lu">Prix des magasins lus à 08:19 · listes de prix des fournisseurs gardées 24 h</span><button class="vr-btn">Relire</button><button class="vr-btn">Exporter la liste</button></div>
  <div class="vr-card">
    <div class="mm-kpis">${kpis.map(k => `<div class="mm-kpi ${k[3]}"><div class="k">${k[0]}</div><div class="v">${k[1]}</div><div class="s">${k[2]}</div></div>`).join('')}</div>
    <div class="vr-chips">${chips.map((c, i) => `<button class="${i === 0 ? 'on' : ''}">${c[2] ? `<i class="vr-pt ${c[2]}"></i>` : ''}${esc(c[0])} <b>${c[1]}</b></button>`).join('')}</div>
    <div class="vr-fil"><input class="mm-q" placeholder="Chercher une matière…"><select class="vr-sel"><option>Centrale et libres</option></select><select class="vr-sel"><option>Classées par CA des recettes (30 jours)</option></select><span class="vr-sp"></span><span class="vr-lu">12 lignes sur 412</span></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Matière première</th><th class="g">Employée dans<small>recettes vendues, CA 30 jours</small></th>${MAGS.map(m => `<th>${m}<small>prix du magasin</small></th>`).join('')}<th>Prix de référence<small>fournisseur, réseau ou saisi</small></th><th>Appliquer à</th></tr></thead><tbody>
      ${L.map(ligne).join('')}
    </tbody></table></div>
    <div class="mm-barre"><div><b>4 prix choisis — 9 écritures</b> (une par matière et par magasin) : Poche Sauce Tikka dans 4 magasins, Frangipane & Abricots dans 3, Fondant chocolat à Halle, Sucre (Blanc) à Halle.
      <small>Rien ne part sans « Vérifier » puis « Appliquer » : chaque prix est écrit au panel avec le compte consultant, relu, et noté au registre des corrections (« Annuler » remet l’avant).</small></div>
      <button class="rc-btn gris">Annuler les choix</button><button class="rc-btn">Vérifier ce qui sera écrit</button></div>
    <div class="vr-pied"><b>Le prix de référence</b>, au choix dans la liste déroulante de chaque matière : la liste de prix <b>en vigueur</b> d’un fournisseur lié à la matière au panel (prix du colis ÷ taille du colis, daté), le prix médian des autres magasins, ou un prix saisi. Quand le colis et la matière ne se comptent pas dans la même unité (poches, pièces face aux grammes), la conversion se pose dans la liste avant de choisir. <b>Appliquer à</b> : un magasin, les magasins sans prix, ou tous. Source : /shops/{id}/materials (prix des magasins), /material-suppliers + catalog-mappings, /material-suppliers/{f}/shops/{s}/price-lists/current.</div>
  </div>
</div>`;
}

fs.writeFileSync(path.join(D, 'a.html'), page(ecran()));
console.log('a.html');
