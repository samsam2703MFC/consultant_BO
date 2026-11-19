/* Maquette « Vérification › Recettes à corriger » : corriger d'ici, ou ouvrir au panel au bon endroit
 * (demande du 13/11/2026 : « dans les problèmes de recette, qu'on puisse en direct si possible corriger, ou un
 * lien direct »).
 *
 *   node docs/maquettes/recettes-correction/generer.js     → a.html, b.html, c.html
 *   node docs/maquettes/recettes-correction/capturer.js    → les PNG
 *
 * Le décor (rail, en-tête) : chrome.html, l'écran du cockpit rendu tel quel (repris de evolution-magasin), la
 * section Vérification ajoutée au rail telle que app.js la construit. Le contenu reprend les classes de la page en
 * ligne (verification.css, préfixe vr-) ; rc.css n'ajoute que le neuf (préfixe rc-).
 * Chiffres d'illustration : les lignes de la maquette e de docs/maquettes/verification, prolongées. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/* ── le décor ─────────────────────────────────────────────────────────── */
const ICO = {
  liens: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
  recherche: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2',
  form: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
  creer: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
};
const rail = (ico, nom, on, n) => `
    <button class="sidebar__item${on ? ' is-active" aria-current="page' : ''}">
      <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICO[ico]}"></path></svg><span class="sidebar__item-label">${esc(nom)}</span>
      ${n ? `<span class="sidebar__count">${n}</span>` : ''}
    </button>`;
const GROUPE_VERIF = `<div class="sidebar__group">
          <div class="sidebar__group-label">Vérification</div>${rail('liens', 'Audit des flux')}${rail('recherche', 'Fuites par magasin')}${rail('form', 'Inventaires')}${rail('modeles', 'Matières ↔ recettes')}${rail('fonds', 'Chiffre d’affaires')}${rail('journal', 'Mouvements d’un produit')}${rail('creer', 'Recettes à corriger', true, 37)}${rail('perf', 'Évolution')}
        </div>
        `;
const INTRO = 'Chaque produit vendu doit avoir une recette complète, chiffrée et plausible : sinon l’écart de matière, la marge et le CA attendu sont faux. Dix règles, magasin par magasin, sur les ventes des trente derniers jours, classées par chiffre d’affaires concerné — et la part du CA déjà couverte, vers 100 %.';

function page(titre, contenu, apres){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">',
    '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="rc.css">');
  h = h.replace(/<title>[^<]*<\/title>/, `<title>${esc(titre)} — Cockpit CEO (maquette)</title>`);
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  h = h.replace(/<div class="sidebar__group">\s*<div class="sidebar__group-label">Produits<\/div>/, m => GROUPE_VERIF + m);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Recettes à corriger</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="rc-pill">Maquette · chiffres d’illustration</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, `<p class="shell-sub page-intro">${esc(INTRO)}</p>`);
  h = h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, contenu);
  if (apres) { h = h.replace(/<\/main>\s*<\/div>/, m => m + apres); }
  return h;
}

/* ── les éléments ─────────────────────────────────────────────────────── */
const SVG = {
  crayon: '<svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  lien: '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>',
  roue: '<svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/></svg>',
};
const btn = (txt, cls = '', ico = 'crayon') => `<button class="rc-btn ${cls}">${ico ? SVG[ico] : ''}${esc(txt)}</button>`;
const liens = l => l && l.length ? `<div class="rc-liens">${l.map(t => `<a class="rc-ln" href="#">${esc(t)} ↗</a>`).join('')}</div>` : '';
const pb = (cls, nom) => `<span class="vr-pb ${cls}">${esc(nom)}</span>`;

/* Les lignes de la liste (mêmes produits et mêmes montants que la maquette e). `geste` : ce que le bouton fait ;
 * `ici` : faux quand la correction ne peut se faire qu'au panel (le bouton y mène, au bon écran). */
const L = [
  { nom: 'Pâtes Amatriciana', cat: 'Plat Prepare - Chaud', cls: 'pb-sans', regle: 'Pas de recette', det: 'Vendu 312 fois en 30 jours, aucune recette au panel : son coût est estimé au taux du magasin.', prop: 'La recette « Amatriciana » (n° 418) existe au panel, rattachée à aucun produit : la rattacher.', ca: '2 650 €', mag: 'Halle · Gosselies · Corbais', dep: '28 j', geste: 'Rattacher la 418', liens: ['Produit', 'Recette 418'] },
  { nom: 'FlipFlap - Thon', cat: 'Sandwiches garnis', cls: 'pb-reseau', regle: 'Coût hors norme du réseau', det: '0,79 € à Halle (coût « custom » du 12/07) ; 3,73 € à 3,91 € ailleurs ; la recette ligne à ligne donne 3,87 €.', prop: 'Retirer le coût custom de Halle et recalculer : 0,79 € → 3,87 €.', ca: '2 140 €', mag: 'Halle', dep: '89 j', geste: 'Recalculer à Halle', ico: 'roue', liens: ['Recette', 'Produit à Halle'] },
  { nom: 'Merveilleux', cat: 'Entremets - Individuel', cls: 'pb-prix', regle: 'Matière sans prix', det: 'Sous-recette Meringue : sucre glace et blancs d’œufs sans prix dans 3 magasins sur 4.', prop: 'Reprendre le prix de Corbais : sucre glace 1,85 €/kg, blancs d’œufs 6,20 €/kg.', ca: '1 980 €', mag: '3 magasins', dep: '41 j', geste: 'Saisir les prix', liens: ['Sucre glace', 'Blancs d’œufs'] },
  { ouvert: true, nom: 'Pain au Chocolat Nappé', cat: 'Viennoiserie Ind.', cls: 'pb-unite', regle: 'Unité suspecte', det: 'Bâtons chocolat : « 2 » lu en kg au lieu de pièces — 26,40 € de coût pour 1,60 € de vente. Le beurre de tourage manque.', ca: '1 540 €', mag: '4 magasins', dep: '12 j' },
  { nom: 'Cookie Noix & Caramel', cat: 'Cookies', cls: 'pb-vide', regle: 'Recette vide', det: 'Recette créée sans aucune ligne : coût 0 €.', prop: 'Partir de la recette du Cookie Chocolat (6 lignes), changer la garniture.', ca: '1 120 €', mag: '4 magasins', dep: '63 j', geste: 'Compléter la recette', liens: ['Recette'] },
  { nom: 'Taboulé Petits Pois & Poulet', cat: 'Salades féculentes', cls: 'pb-sans', regle: 'Pas de recette', det: 'Pas de recette au panel, et aucune recette libre d’un nom proche.', prop: 'À créer au panel : le lien ouvre la création de recette, puis la fiche du produit pour la rattacher.', panel: true, ca: '960 €', mag: 'Halle · Sombreffe', dep: '19 j', geste: 'Créer au panel ↗', liens: ['Produit'] },
  { fait: true, nom: 'Wrapps - Poulet & Parmesan', cat: 'Wrapps', cls: 'pb-inv', regle: 'Coût invraisemblable', fait_txt: 'Corrigée ici à 10:44 : sous-recette Sauce César, 1 l → 0,1 l. Coût de la pièce 6,85 € → 2,70 € (Halle). Relue au panel : plausible, la ligne quitte la liste au prochain calcul.', ca: '880 €', mag: '3 magasins', dep: '7 j' },
  { nom: 'Pain Tradition - Olives', cat: 'Pain Tradition', cls: 'pb-rend', regle: 'Rendement absent', det: 'Rendement à 0 : le pâton entier (12 pains) est imputé à un seul pain — coût 14,20 €.', inl: `Rendement <input class="rc-in mod" value="12"> pains <span class="vr-mu">(le Pain Tradition : 12)</span> → coût <span class="rc-av">14,20 €</span> <b class="rc-ap">1,18 €</b>`, ca: '760 €', mag: '4 magasins', dep: '35 j', geste: 'Enregistrer', liens: ['Recette'] },
  { nom: '1/4 - Quiches', cat: 'Quiches', cls: 'pb-port', regle: 'Portion sans fraction', det: 'La portion n’a pas de fraction : un quart coûte une quiche entière.', inl: `Fraction <input class="rc-in mod" value="0,25"> de la quiche → coût <span class="rc-av">3,76 €</span> <b class="rc-ap">0,94 €</b>`, ca: '610 €', mag: '4 magasins', dep: '89 j', geste: 'Enregistrer', liens: ['Portion'] },
  { nom: 'Couque à la Crème', cat: 'Viennoiserie Ind.', cls: 'pb-prix', regle: 'Matière sans prix', det: 'Crème pâtissière : lait entier sans prix à Sombreffe.', inl: `Lait entier à Sombreffe <input class="rc-in mod" value="1,05"> €/l <span class="vr-mu">(1,02 à 1,08 ailleurs)</span>`, ca: '540 €', mag: 'Sombreffe', dep: '22 j', geste: 'Enregistrer', liens: ['Lait entier'] },
  { nom: 'Duo Chocolat - 180ø', cat: 'Entremets - 180ø', cls: 'pb-reseau', regle: 'Coût hors norme du réseau', det: '11,90 € à Gosselies, médiane du réseau 6,40 € : le biscuit est compté deux fois.', prop: 'Retirer la seconde ligne « Biscuit chocolat » : 11,90 € → 6,35 €.', ca: '520 €', mag: 'Gosselies', dep: '15 j', geste: 'Corriger', liens: ['Recette', 'Biscuit chocolat'] },
  { nom: 'Brookie', cat: 'Tartes', cls: 'pb-sans', regle: 'Pas de recette', det: 'Pas de recette au panel.', prop: 'À créer au panel.', panel: true, ca: '470 €', mag: '3 magasins', dep: '51 j', geste: 'Créer au panel ↗', liens: ['Produit'] },
  { fait: true, nom: 'Pistolet à l’Ancienne', cat: 'Petite Boulangerie', cls: 'pb-inv', regle: 'Coût invraisemblable', fait_txt: 'Corrigée ici à 10:31 : farine T65, 0,045 g → 0,045 kg (×1000). Coût 0,02 € → 0,21 €, 23 % du prix.', ca: '450 €', mag: '4 magasins', dep: '9 j' },
  { nom: 'Fromage & Spéculoos - 120ø', cat: 'Tartissières - 120ø', cls: 'pb-unite', regle: 'Unité suspecte', det: 'Spéculoos : 120 lu en kg au lieu de g.', prop: '120 kg → 120 g (÷1000) : coût 612,40 € → 1,02 €.', ca: '380 €', mag: 'Corbais · Halle', dep: '4 j', geste: 'Appliquer ÷1000', liens: ['Recette'] },
  { nom: 'Pain Doré', cat: 'Pain', cls: 'pb-prix', regle: 'Matière sans prix', det: 'Graines de sésame sans prix dans 2 magasins.', prop: 'Reprendre le prix de Halle : 7,40 €/kg.', ca: '290 €', mag: 'Corbais · Sombreffe', dep: '30 j', geste: 'Saisir les prix', liens: ['Graines de sésame'] },
  { nom: 'Sucre perlé', cat: 'matière première', cls: 'pb-orph', regle: 'Matière jamais en recette', det: '5 kg livrés à Gosselies en octobre, employé par aucune recette.', prop: 'Une recette l’oublie (Chouquettes ?), ou c’est hors recette : la marquer, elle ne remontera plus.', ca: '—', mag: 'Gosselies', dep: '6 j', geste: 'Hors recette', cls2: 'gris', ico: null, liens: ['Matière', 'Chouquettes'] },
];

/* La ligne ouverte (n° 4) : la correction en place, avant / après. */
function ouverte(){
  const lignes = [
    { nom: 'Bâtons chocolat', q: '2', u: ['kg', 'pièce'], cout: ['26,40 €', '0,24 €'], why: '« 2 » en kg : 2 000 g de chocolat pour un pain. En pièces : 2 bâtons, 0,12 € l’un.' },
    { nom: 'Pâte croissant', sous: 'sous-recette', q: '0,060', u: ['kg'], cout: ['0,21 €'] },
    { nom: 'Nappage chocolat', q: '0,012', u: ['kg'], cout: ['0,11 €'] },
    { ajout: true, nom: 'Beurre de tourage', q: '0,018', u: ['kg'], cout: ['', '0,15 €'], why: 'Absent ; le Pain au Chocolat en compte 0,018 kg.' },
  ];
  const tr = l => `<tr class="${l.u.length > 1 ? 'rc-mod' : ''}${l.ajout ? 'rc-ajout' : ''}"><td class="g nom"><b>${esc(l.nom)}</b>${l.sous ? `<small>${esc(l.sous)}</small>` : ''}${l.why ? `<span class="rc-why">${esc(l.why)}</span>` : ''}</td>
    <td><input class="rc-in${l.ajout ? ' mod' : ''}" value="${l.q}"></td>
    <td class="g">${l.u.length > 1 ? `<span class="rc-av">${l.u[0]}</span> <select class="rc-sel mod"><option>${l.u[1]}</option></select>` : `<select class="rc-sel${l.ajout ? ' mod' : ''}"><option>${l.u[0]}</option></select>`}</td>
    <td>${l.cout.length > 1 ? `${l.cout[0] ? `<span class="rc-av">${l.cout[0]}</span> ` : ''}<b class="rc-ap">${l.cout[1]}</b>` : l.cout[0]}</td></tr>`;
  const mags = [['Halle', '26,72 €', '0,71 €', '1,60 €', '× 2,25'], ['Gosselies', '26,80 €', '0,72 €', '1,60 €', '× 2,22'], ['Corbais', '26,65 €', '0,70 €', '1,65 €', '× 2,36'], ['Sombreffe', '26,74 €', '0,71 €', '1,60 €', '× 2,25']];
  return `<tr class="rc-ouvert"><td colspan="8"><div class="rc-ed">
    <div class="rc-bloc"><h4>La recette « Pain au Chocolat Nappé » — 2 changements proposés</h4>
      <table class="vr-t"><thead><tr><th class="g">Matière</th><th>Quantité<small>par pièce</small></th><th class="g">Unité</th><th>Coût<small>Halle</small></th></tr></thead><tbody>${lignes.map(tr).join('')}</tbody></table></div>
    <div class="rc-bloc"><h4>Le coût de la pièce, magasin par magasin</h4>
      <table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Avant</th><th>Après</th><th>Prix HT</th><th>Coefficient<small>après</small></th></tr></thead><tbody>
      ${mags.map(m => `<tr><td class="g nom"><b>${m[0]}</b></td><td><span class="rc-av">${m[1]}</span></td><td><b class="rc-ap">${m[2]}</b></td><td>${m[3]}</td><td><span class="vx-p att">${m[4]}</span></td></tr>`).join('')}
      </tbody></table>
      <div class="vr-mu" style="font-size:11px;padding:8px 10px 10px;line-height:1.45">Après correction, la recette est plausible (44 % du prix) : la ligne sort de la liste. Le coefficient reste sous × 2,5 : la marge du produit se lira dans le Catalogue.</div></div>
    </div>
    <div class="rc-pied">${btn('Appliquer au panel', 'ok', null)}${btn('Fermer', 'gris', null)}<span><b>Écrit au panel</b> par le compte consultant (<span class="rc-code">PATCH /recipes/412</span>), noté au registre des corrections avec l’avant et l’après ; le cockpit relit la recette aussitôt et la ligne se met à jour.</span><span class="vr-sp"></span>${liens(['Recette au panel', 'Bâtons chocolat', 'Beurre de tourage'])}</div>
  </td></tr>`;
}

function ligne(l, i){
  const rang = `<span class="vr-rang${i < 5 ? ' h' : ''}">${i + 1}</span>`;
  const tete = `<td class="g">${rang}</td><td class="g nom vr-ouvre"><b>${esc(l.nom)}</b><small>${esc(l.cat)}</small></td><td class="g">${pb(l.cls, l.regle)}</td>`;
  const queue = `<td>${l.ca === '—' ? '<span class="vr-mu">—</span>' : `<b>${l.ca}</b>`}</td><td class="g vr-mu">${esc(l.mag)}</td><td>${l.dep}</td>`;
  if (l.fait) {
    return `<tr class="rc-fait">${tete}<td class="g vr-det"><span class="rc-ok"><i>✓</i>${esc(l.fait_txt)}</span></td>${queue}<td><div class="rc-act">${btn('Annuler', 'gris', null)}${liens(['Recette'])}</div></td></tr>`;
  }
  if (l.ouvert) {
    return `<tr class="rc-on">${tete}<td class="g vr-det">${esc(l.det)}<span class="rc-prop">2 bâtons en pièces, et le beurre de tourage du Pain au Chocolat : 26,72 € → 0,71 €.</span></td>${queue}<td><div class="rc-act">${btn('Corriger ▴', 'sec')}</div></td></tr>` + ouverte();
  }
  const prop = l.inl ? `<div class="rc-inl">${l.inl}</div>` : (l.prop ? `<span class="rc-prop${l.panel ? ' panel' : ''}">${esc(l.prop)}</span>` : '');
  const geste = l.panel ? `<a class="vr-lien" href="#">${esc(l.geste)}</a>` : btn(l.geste, l.cls2 || '', l.ico === undefined ? 'crayon' : l.ico);
  return `<tr>${tete}<td class="g vr-det">${esc(l.det)}${prop}</td>${queue}<td><div class="rc-act">${geste}${liens(l.liens)}</div></td></tr>`;
}

/* ── a : la liste, un geste par ligne ─────────────────────────────────── */
function ecranA(){
  const chips = [['Toutes', 37, ''], ['Pas de recette', 8, 'pb-sans'], ['Formule sans ses produits', 2, 'pb-form'], ['Recette vide', 3, 'pb-vide'], ['Matière sans prix', 8, 'pb-prix'], ['Coût invraisemblable', 5, 'pb-inv'], ['Hors norme du réseau', 4, 'pb-reseau'], ['Unité suspecte', 3, 'pb-unite'], ['Rendement absent', 2, 'pb-rend'], ['Portion sans fraction', 2, 'pb-port'], ['Matière jamais en recette', 2, 'pb-orph']];
  return `<div data-screen="verification-recettes" class="vr">
  <div class="vr-haut"><span class="vr-lu">Ventes du 14/10 au 12/11 · lu à 10:46</span><span class="vr-sp"></span><button class="vr-btn">Relire</button><button class="vr-btn">Exporter la liste</button></div>
  <div class="vr-card">
    <div class="vr-cov">
      <div class="big att">93,6 %<small>du CA passe par une recette fiable</small></div>
      <div><div class="jg"><i class="att" style="width:93.6%"></i><b class="rc-gh" style="left:93.6%;width:5.3%"></b><u></u></div>
        <div class="tx">Objectif <b>100 %</b> : chaque produit vendu a une recette complète, chiffrée et plausible. Il manque <b>6,4 % du CA, soit 8 120 € de ventes sur 30 jours</b>, dont 72 % dans les dix premières lignes.</div>
        <div class="rc-cov3"><span><b class="ok">29 lignes se corrigent ici</b> : toutes appliquées, la couverture passerait à <b>98,9 %</b> (hachuré).</span><span><b>8 se font au panel</b>, le bouton ouvre le bon écran.</span><span><b>2 corrigées ce matin</b> depuis le cockpit (+1,1 pt).</span></div></div>
    </div>
    <div class="vr-chips">${chips.map((c, i) => `<button class="${i === 0 ? 'on' : ''}">${c[2] ? `<i class="vr-pt ${c[2]}"></i>` : ''}${esc(c[0])} <b>${c[1]}</b></button>`).join('')}</div>
    <div class="vr-fil">
      <button class="vr-tog on"><i></i>Formules et promotions <b>2</b></button>
      <div class="rc-seg"><button class="on">Toutes<b>37</b></button><button>Se corrigent ici<b>29</b></button><button>Au panel<b>8</b></button></div>
      <select class="vr-sel"><option>Tous les magasins</option></select>
      <select class="vr-sel"><option>Classées par CA concerné (30 jours)</option></select>
      <span class="vr-sp"></span><span class="vr-lu">16 lignes sur 37</span></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g" style="width:34px">#</th><th class="g">Produit</th><th class="g">Problème</th><th class="g">Ce qui cloche <span style="color:#245f33">→ ce que le cockpit propose</span></th><th>CA concerné<small>30 jours</small></th><th class="g">Magasins</th><th>Repérée<small>depuis</small></th><th>Corriger<small>ici, ou au bon écran du panel</small></th></tr></thead><tbody>
      ${L.map(ligne).join('')}
    </tbody></table></div>
    <div class="vr-pied"><b>Corriger d’ici</b> : chaque bouton montre d’abord l’avant et l’après (la ligne, le coût de la pièce dans chaque magasin), puis écrit au panel avec le compte consultant ; rien ne part sans « Appliquer ». Chaque correction est notée au registre (avant, après, heure) et s’annule d’un clic. <b>Lien direct</b> : les petits liens ↗ ouvrent au panel l’écran exact — la recette, le produit, la matière, la portion — et plus seulement la fiche du produit. Ce qui ne peut pas se faire d’ici (créer une recette de zéro, une promotion « bundle ») garde un seul bouton : il ouvre le panel au bon endroit.</div>
  </div>
</div>`;
}

/* ── b : le volet de la recette, en correction ────────────────────────── */
function ecranB(){
  const lignes = [
    ['Tortilla blé', '', '1', 'pièce', '0,38 €', '6 %'],
    ['Poulet émincé', '', '0,080', 'kg', '1,12 €', '16 %'],
    ['Parmesan', '', '0,015', 'kg', '0,41 €', '6 %'],
    ['Roquette', '', '0,010', 'kg', '0,12 €', '2 %'],
    ['Tomate séchée', '', '0,015', 'kg', '0,21 €', '3 %'],
  ];
  const tr = r => `<tr><td class="g nom"><b>${r[0]}</b></td><td><input class="rc-in" value="${r[2]}"></td><td class="g"><select class="rc-sel"><option>${r[3]}</option></select></td><td><span class="rc-av" style="text-decoration:none">${r[4]}</span></td><td>${r[4]}</td><td>${r[5]}</td><td><button class="rc-x" title="Retirer la ligne">×</button></td></tr>`;
  const sauce = `<tr class="vr-lourde rc-mod"><td class="g nom"><b>Sauce César</b><small>sous-recette · 4,61 € le litre</small><span class="rc-why">Pesait 67 % du coût. 1 l de sauce pour un wrap ; les autres wraps en mettent 0,08 à 0,12 l.</span></td><td><span class="rc-av">1</span> <input class="rc-in mod" value="0,1"></td><td class="g"><select class="rc-sel"><option>l</option></select></td><td><span class="rc-av">4,61 €</span></td><td><b class="rc-ap">0,46 €</b></td><td>17 %</td><td><button class="rc-x">×</button></td></tr>`;
  const mags = [['Halle', '6,85 €', '2,70 €', '5,50 €', '× 2,04', '49 %'], ['Gosselies', '6,92 €', '2,74 €', '5,50 €', '× 2,01', '50 %'], ['Corbais', '6,78 €', '2,66 €', '5,45 €', '× 2,05', '49 %']];
  const volet = `<div class="vr"><div class="vr-voile"></div><div class="vr-fiche">
    <div class="vr-fi-t"><div><b>Wrapps - Poulet &amp; Parmesan</b><small>Wrapps · recette 377 · vendu dans 3 magasins</small></div><button class="vr-x" aria-label="Fermer">×</button></div>
    <div class="rc-fiche-act"><div class="rc-seg"><button>Lire</button><button class="on">Corriger</button></div><span class="vr-mu">Liens directs au panel :</span>${['Recette 377', 'Sous-recette Sauce César', 'Produit', 'Prix des matières à Halle'].map(t => `<a class="rc-ln" href="#">${t} ↗</a>`).join('')}</div>
    <div class="vr-fi-s"><div>${pb('pb-inv', 'Coût invraisemblable')} Coût 6,85 € pour un prix de 5,50 € HT (125 %) : la sous-recette Sauce César compte 1 l au lieu de 10 cl. <span class="vr-mu">Halle · Gosselies · Corbais</span></div></div>
    <div class="vr-fi-h">Dans chaque magasin — avant et après la correction</div>
    <table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Coût<small>avant</small></th><th>Coût<small>après</small></th><th>Prix HT</th><th>Coefficient<small>après</small></th><th>Matière<small>% du prix, après</small></th></tr></thead><tbody>
      ${mags.map(m => `<tr><td class="g nom"><b>${m[0]}</b></td><td><span class="rc-av">${m[1]}</span></td><td><b class="rc-ap">${m[2]}</b></td><td>${m[3]}</td><td>${m[4]}</td><td><span class="vx-p att">${m[5]}</span></td></tr>`).join('')}
    </tbody></table>
    <div class="vr-fi-h">La recette « Wrapps Poulet Parmesan » — 6 lignes, la pièce à Halle</div>
    <div class="rc-rend">Rendement <input class="rc-in" value="1"> pièce <span class="vr-sp"></span></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Matière</th><th>Quantité<small>par pièce</small></th><th class="g">Unité</th><th>Coût<small>avant</small></th><th>Coût<small>après</small></th><th>Part<small>après</small></th><th></th></tr></thead><tbody>
      ${lignes.map(tr).join('')}${sauce}
      <tr class="tot"><td class="g nom"><b>La pièce</b></td><td></td><td></td><td><span class="rc-av">6,85 €</span></td><td><b class="rc-ap">2,70 €</b></td><td>49 %<small>du prix</small></td><td></td></tr>
    </tbody></table></div>
    <div class="rc-plus">+ <input class="rc-in" placeholder="Ajouter une matière ou une sous-recette…"><span class="vr-mu">les matières du réseau, avec leur prix dans chaque magasin</span></div>
    <div style="height:70px"></div>
    <div class="rc-barre"><div><b>1 changement</b> — Sauce César 1 l → 0,1 l · la pièce <span class="rc-av">6,85 €</span> <b class="rc-ap">2,70 €</b> à Halle<small>La recette est la même dans les trois magasins : la correction vaut pour les trois. Écrite par <span class="rc-code">PATCH /recipes/377</span>, notée au registre, relue aussitôt.</small></div><span class="vr-sp"></span>${btn('Annuler les changements', 'gris', null)}${btn('Enregistrer au panel', 'ok', null)}</div>
  </div></div>`;
  // La page derrière le volet : la liste, comme en a.
  return [ecranA(), volet];
}

/* ── c : règle par règle, et le registre ──────────────────────────────── */
function ecranC(){
  const R = [
    ['pb-sans', 'Pas de recette', 'oui', 'Rattacher une recette libre du même nom (proposée)', 'PATCH /products/{id} (id_recipe)', 'Produit · Recette', 'Sans recette proche : « Créer au panel ↗ »'],
    ['pb-form', 'Formule sans ses produits', 'non', 'La promotion « bundle » se règle au panel', '—', 'Produit · Promotions', 'Le compte consultant reçoit 403 sur /admin/promotions (10/10)'],
    ['pb-vide', 'Recette vide', 'oui', 'Le volet en correction : copier une recette proche, puis ajuster', 'PATCH /recipes/{id}', 'Recette', ''],
    ['pb-prix', 'Matière sans prix', 'oui', 'Le prix dans la ligne, prérempli du magasin le plus proche', 'POST /shops/{id}/suppliers/{f}/materials/price', 'Matière · Prix du magasin', 'Un geste « prix saisi à la main » : le cockpit le note lui-même, il ne le signale pas comme suspect'],
    ['pb-inv', 'Coût invraisemblable', 'oui', 'La ligne fautive en évidence, sa correction proposée', 'PATCH /recipes/{id} ou /subrecipes/{id}', 'Recette · Sous-recette', ''],
    ['pb-reseau', 'Hors norme du réseau', 'oui', 'Retirer le coût custom et recalculer le magasin', 'PATCH /shops/{id}/products/{pid}, POST /shops/{id}/recipes/recalculate', 'Produit du magasin · Recette', ''],
    ['pb-unite', 'Unité suspecte', 'oui', 'Un clic : ×1000, ÷1000 ou l’unité', 'PATCH /recipes/{id}', 'Recette', ''],
    ['pb-rend', 'Rendement absent', 'oui', 'Le nombre de pièces, dans la ligne', 'PATCH /recipes/{id} (yield_quantity)', 'Recette', ''],
    ['pb-port', 'Portion sans fraction', 'oui', 'La fraction (½ = 0,5, ¼ = 0,25), dans la ligne', 'PATCH /product-portions/{id}', 'Portion', ''],
    ['pb-orph', 'Matière jamais en recette', 'cockpit', 'Ouvrir la recette qui l’oublie, ou « hors recette » (emballage, hygiène)', 'rien : noté dans le cockpit', 'Matière', ''],
  ];
  const ou = v => v === 'oui' ? '<span class="rc-oui">Ici</span>' : (v === 'non' ? '<span class="rc-non">Au panel</span>' : '<span class="rc-loc">Ici, sans écrire au panel</span>');
  const J = [
    ['13/11 10:44', 'Wrapps - Poulet & Parmesan', 'Sauce César 1 l → 0,1 l', '6,85 € → 2,70 €', 'PATCH /recipes/377', 'ok', 'Relue : plausible'],
    ['13/11 10:31', 'Pistolet à l’Ancienne', 'Farine T65 ×1000 (g → kg)', '0,02 € → 0,21 €', 'PATCH /recipes/205', 'ok', 'Relue : plausible'],
    ['12/11 16:12', 'Couque au Beurre', 'Beurre de tourage à Sombreffe : 7,90 €/kg', '— → 0,64 €', 'POST /shops/7/suppliers/3/materials/price', 'ok', 'Relue : plausible'],
    ['12/11 15:58', 'FlipFlap - Thon', 'Coût custom retiré à Halle', '0,79 € → 3,87 €', 'PATCH /shops/4/products/2300010', 'ko', 'Refusée par le panel (HTTP 422) : rien n’a changé'],
    ['12/11 15:40', 'Sucre perlé', 'Marquée « hors recette »', '—', 'cockpit seulement', 'ok', 'Ne remonte plus'],
  ];
  return `<div data-screen="verification-recettes" class="vr">
  <div class="vr-card">
    <div class="vx-ct"><h3>Chaque règle, sa correction</h3><span class="vr-lu">ce qui se corrige d’ici, ce qui s’ouvre au panel au bon écran</span></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Règle</th><th class="g">Où</th><th class="g">Le geste</th><th class="g">Écrit au panel par<small>route du swagger, format à mesurer</small></th><th class="g">Liens directs ↗</th><th class="g">À savoir</th></tr></thead><tbody>
    ${R.map(r => `<tr><td class="g">${pb(r[0], r[1])}</td><td class="g">${ou(r[2])}</td><td class="g rc-w">${esc(r[3])}</td><td class="g rc-w">${r[4].split(', ').map(x => `<span class="rc-code">${esc(x)}</span>`).join('<br>')}</td><td class="g vr-mu">${esc(r[5])}</td><td class="g rc-w vr-mu" style="font-size:11.5px">${esc(r[6])}</td></tr>`).join('')}
    </tbody></table></div>
    <div class="rc-note"><b>Les liens directs</b> remplacent le lien unique d’aujourd’hui (la fiche du produit au panel, réglage <span class="rc-code">verifLienRecette</span>) par un modèle d’adresse par objet : produit, recette, sous-recette, matière, portion, prix d’un magasin. Les adresses des écrans du panel sont à relever une fois (elles ne sont pas dans le swagger) et se règlent dans Paramètres, sans rien écrire en dur.</div>
  </div>
  <div class="vr-card">
    <div class="vx-ct"><h3>Le registre des corrections</h3><span class="vr-lu">chaque écriture au panel faite depuis cette page, avec l’avant et l’après</span><span class="vr-sp"></span><button class="vr-btn">Exporter</button></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Quand</th><th class="g">Produit</th><th class="g">Correction</th><th>Coût de la pièce</th><th class="g">Route</th><th class="g">Résultat</th><th></th></tr></thead><tbody>
    ${J.map(j => `<tr><td class="g">${j[0]}</td><td class="g nom"><b>${esc(j[1])}</b></td><td class="g rc-w" style="min-width:190px">${esc(j[2])}</td><td>${esc(j[3])}</td><td class="g"><span class="rc-code">${esc(j[4])}</span></td><td class="g rc-w" style="max-width:220px">${j[5] === 'ok' ? `<span class="rc-ok"><i>✓</i>${esc(j[6])}</span>` : `<span class="rc-ko">✕ ${esc(j[6])}</span>`}</td><td>${j[5] === 'ok' ? btn('Annuler', 'gris', null) : `<a class="vr-lien" href="#">Ouvrir au panel ↗</a>`}</td></tr>`).join('')}
    </tbody></table></div>
    <div class="rc-note"><b>Annuler</b> réécrit l’avant au panel, par la même route. Une correction refusée ne change rien et laisse le bouton « Ouvrir au panel ». Le registre se garde en base (<span class="rc-code">ceo_verif_correction</span>), et l’onglet « Gestes à surveiller » reconnaît ces écritures comme venant du cockpit.</div>
  </div>
</div>`;
}

fs.writeFileSync(path.join(D, 'a.html'), page('Recettes à corriger — corriger d’ici', ecranA()));
const [fondB, voletB] = ecranB();
fs.writeFileSync(path.join(D, 'b.html'), page('Recettes à corriger — le volet en correction', fondB, voletB));
fs.writeFileSync(path.join(D, 'c.html'), page('Recettes à corriger — règles et registre', ecranC()));
console.log('a.html, b.html, c.html');
