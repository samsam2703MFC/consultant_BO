/* Maquette « La fiche d'une matière, modifiable » (demande du 14/11/2026 : « un outil pour encoder les prix, tous les
 * paramètres de la matière (material, voir swagger) ; les matières sont reliées à des fournisseurs ou à un prix général :
 * faire la comparaison » — « tout modifiable »).
 *
 *   node docs/maquettes/fiche-matiere/generer.js    → a.html (Sucre (Blanc)), b.html (Poche Sauce Tikka)
 *   node docs/maquettes/fiche-matiere/capturer.js   → a.png, b.png
 *
 * La fiche s'ouvre au clic sur une matière de la bascule « Matières premières » (en ligne depuis le 14/11). Données lues
 * en ligne le 14/11/2026 (lecture seule) : /verification/recettes/matieres (prix des magasins, prix conseillé et de
 * référence du panel, fournisseurs et listes de prix, recettes qui l'emploient) et la ligne brute de /shops/{id}/materials
 * (les paramètres d'une matière : catégorie, unité, type, source, perte, TVA, emballage, supplément, allergènes). */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const ICO = { creer: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', liens: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7', modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5' };
const rail = (ico, nom, on, n) => `<button class="sidebar__item${on ? ' is-active" aria-current="page' : ''}"><svg class="ico" viewBox="0 0 24 24"><path d="${ICO[ico]}"></path></svg><span class="sidebar__item-label">${esc(nom)}</span>${n ? `<span class="sidebar__count">${n}</span>` : ''}</button>`;
const GROUPE = `<div class="sidebar__group"><div class="sidebar__group-label">Vérification</div>${rail('liens', 'Audit des flux')}${rail('modeles', 'Matières ↔ recettes')}${rail('creer', 'Recettes à corriger', true, 212)}</div>`;

function page(fond, volet){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="fm.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>Fiche matière — Cockpit CEO (maquette)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  h = h.replace(/<div class="sidebar__group">\s*<div class="sidebar__group-label">Produits<\/div>/, m => GROUPE + m);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Recettes à corriger</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="fm-pill">Maquette · données lues en ligne le 14/11, saisies d’illustration</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  h = h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, fond);
  return h.replace(/<\/main>\s*<\/div>/, m => m + volet);
}

/* Le fond : la bascule « Matières premières » telle qu'elle est en ligne, quelques lignes. */
const fond = `<div class="vr fm-fond"><div class="vr-haut"><div class="vm-bascule"><button>Recettes à corriger <b>212</b></button><button class="on">Matières premières <b>400</b></button></div></div>
  <div class="vr-card"><div class="vm-kpis"><div class="vm-kpi"><div class="k">Matières dans les recettes</div><div class="v">400</div></div><div class="vm-kpi ko"><div class="k">Sans prix dans un magasin</div><div class="v">106</div></div><div class="vm-kpi att"><div class="k">Sans liste de prix fournisseur</div><div class="v">178</div></div><div class="vm-kpi att"><div class="k">Écart à la référence</div><div class="v">83</div></div><div class="vm-kpi att"><div class="k">Unité à vérifier</div><div class="v">13</div></div></div>
  <div class="vr-tw"><table class="vr-t vm-t"><thead><tr><th class="g">Matière première</th><th class="g">Employée dans</th><th>Corbais</th><th>Gosselies</th><th>Sombreffe</th><th>Halle</th></tr></thead><tbody>
  ${[['Fondant chocolat', '1 recette · 2 640 €'], ['Houmous', '3 recettes · 4 860 €'], ['Sucre (Blanc)', '11 recettes · 4 959 €'], ['Poche Sauce Tikka', '1 recette · 538 €'], ['Mayonnaise', '9 recettes · 6 460 €']].map(r => `<tr><td class="g nom vr-ouvre"><b>${r[0]}</b></td><td class="g">${r[1]}</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>`).join('')}
  </tbody></table></div></div></div>`;

const ALLERGENES = ['Gluten', 'Crustacés', 'Œufs', 'Poissons', 'Arachides', 'Soja', 'Lait', 'Fruits à coque', 'Céleri', 'Moutarde', 'Sésame', 'Sulfites', 'Lupin', 'Mollusques'];
const champ = (lib, ctrl, note, cls) => `<label class="fm-champ${cls ? ' ' + cls : ''}">${esc(lib)}${ctrl}${note ? `<small${note.startsWith('!') ? ' class="av"' : ''}>${esc(note.replace(/^!/, ''))}</small>` : ''}</label>`;
const inp = (v, mod) => `<input value="${esc(v)}"${mod ? ' class="mod"' : ''}>`;
const sel = (v, mod) => `<select${mod ? ' class="mod"' : ''}><option>${esc(v)}</option></select>`;
const tog = (lib, on) => `<span class="fm-tog${on ? ' on' : ''}"><i></i>${esc(lib)}</span>`;

function volet(M){
  const tete = `<div class="vr-fi-t"><div><b>${esc(M.nom)}</b><small>${esc(M.sous)}</small></div><button class="vr-x">×</button></div>
    <div class="rc-fiche-act"><div class="rc-seg"><button>Lire</button><button class="on">Modifier</button></div><span class="vr-mu">Au panel :</span><a class="rc-ln" href="#">Matière ${M.mid} ↗</a><a class="rc-ln" href="#">Liste de prix ${esc(M.fourn)} ↗</a></div>`;
  const params = `<div class="fm-sec"><h3>Paramètres <span>— pour tout le réseau : chaque magasin, chaque recette qui l’emploie</span></h3>
    <div class="fm-grille">${M.champs.join('')}</div>
    <div style="display:flex;gap:26px;margin:12px 0 6px">${tog('Fait partie d’un emballage', false)}${tog('Peut être un supplément d’un produit', false)}${tog('Intégrée chez le fournisseur', M.integre)}</div>
    <div class="fm-champ" style="margin-top:8px">Allergènes<div class="fm-al">${ALLERGENES.map(a => `<span class="${M.allergenes.indexOf(a) >= 0 ? 'on' : ''}">${a}</span>`).join('')}</div></div>
    <div class="fm-champ" style="margin-top:10px">Employée dans<div class="fm-rec">${M.recettes.map(r => `<span>${esc(r)}</span>`).join('')}</div></div>
    ${M.alerte ? `<div class="fm-alerte">${M.alerte}</div>` : ''}</div>`;
  const ligne = r => r.grp ? `<tr class="fm-grp"><td colspan="6">${esc(r.grp)}</td></tr>`
    : `<tr${r.gen ? ' class="fm-gen"' : ''}><td class="g nom"><b>${esc(r.nom)}</b>${r.s ? `<small>${esc(r.s)}</small>` : ''}</td>
      <td>${r.av ? `<span class="rc-av">${r.av}</span> ` : ''}${r.inp != null ? `<input value="${esc(r.inp)}"${r.mod ? ' class="mod"' : ''}>` : (r.px || '<span class="vr-mu">—</span>')}</td>
      <td>${r.e ? `<span class="fm-e ${r.e[1]}">${esc(r.e[0])}</span>` : ''}${r.e2 ? ` → <span class="fm-e ${r.e2[1]}">${esc(r.e2[0])}</span>` : ''}</td>
      <td class="g vr-mu" style="font-size:11.5px;white-space:normal">${esc(r.etat || '')}</td><td>${r.action ? `<button class="fm-lien">${esc(r.action)}</button>` : ''}</td></tr>`;
  const prix = `<div class="fm-sec fm-px"><h3>Prix — la comparaison <span>— par ${esc(M.par)}, hors TVA ; écart de chaque prix au prix général</span></h3>
    <table class="vr-t"><thead><tr><th class="g">Source</th><th>Prix<small>€ / ${esc(M.par)}</small></th><th>Écart<small>au prix général</small></th><th class="g">État</th><th></th></tr></thead><tbody>${M.prix.map(ligne).join('')}</tbody></table>
    <div class="fm-synthese">${M.synthese}</div></div>`;
  const effet = M.effet ? `<div class="fm-sec"><h3>L’effet sur les recettes <span>— le coût de la pièce, avant et après</span></h3><table class="vr-t"><thead><tr><th class="g">Produit</th><th class="g">Magasin</th><th>Quantité<small>par pièce</small></th><th>Avant</th><th>Après</th></tr></thead><tbody>
    ${M.effet.map(e => `<tr><td class="g nom"><b>${esc(e[0])}</b></td><td class="g">${esc(e[1])}</td><td>${esc(e[2])}</td><td><span class="rc-av">${esc(e[3])}</span></td><td><b class="rc-ap">${esc(e[4])}</b></td></tr>`).join('')}</tbody></table></div>` : '';
  const barre = `<div style="height:14px"></div><div class="rc-barre"><div><b>${esc(M.n)}</b><small>${M.ecritures}</small></div><span class="vr-sp"></span><button class="rc-btn gris">Annuler les changements</button><button class="rc-btn">Vérifier ce qui sera écrit</button></div>`;
  return `<div class="vr"><div class="vr-voile"></div><div class="vr-fiche fm-fiche">${tete}${params}${prix}${effet}${barre}</div></div>`;
}

/* a — Sucre (Blanc) : le prix général du panel (1,00 €/kg) face à ce que paient les magasins (1,89 €/kg). */
const SUCRE = {
  mid: 491, nom: 'Sucre (Blanc)', sous: 'Matières premières (boulangerie) · libre · matière 491 · 11 recettes, 4 959 € de CA sur 30 jours', fourn: 'Bruyerre S.A.', par: 'kg', integre: false, allergenes: [],
  champs: [champ('Nom', inp('Sucre (Blanc)')), champ('Catégorie', sel('Matières premières (boulangerie)')), champ('Unité de base', sel('g — gramme'), 'les prix se lisent par kg'),
    champ('Type', sel('Matière première (RAW)')), champ('Source', sel('Libre (OPEN)'), 'achetée par le magasin chez un fournisseur autorisé'), champ('Perte', inp('0 %')),
    champ('TVA', sel('6 %')), champ('Matière au panel', inp('n° 491 · « Sucre (Blanc) »'), 'recettes : 11 · sous-recettes : —', 'l2')],
  recettes: ['Choux Nappe', 'Flan Breton', 'Saint-Honoré - Classique', 'Poke Poulet Classique', 'Poke Poulet Effiloché', '+ 6 autres'],
  prix: [
    { gen: true, nom: 'Prix général', s: 'prix de référence du panel, pour tout le réseau', av: '1,00', inp: '1,89', mod: true, etat: 'POST /materials/491/manual-price', action: '' },
    { nom: 'Prix conseillé du panel', av: '1,00', inp: '1,89', mod: true, e: ['0 %', 'ok'] },
    { grp: 'Fournisseurs liés' },
    { nom: 'Bruyerre S.A.', s: 'libre · lien « connexion »', px: '<span class="vr-mu">pas de prix</span>', etat: 'liste de prix lue : aucun prix en vigueur pour cette matière', action: 'Lier une référence…' },
    { grp: 'Magasins' },
    { nom: 'Corbais', px: '1,89', e: ['+89 %', 'ko'], e2: ['0 %', 'ok'], action: 'Prendre comme prix général' },
    { nom: 'Gosselies', px: '1,89', e: ['+89 %', 'ko'], e2: ['0 %', 'ok'] },
    { nom: 'Sombreffe', px: '1,89', e: ['+89 %', 'ko'], e2: ['0 %', 'ok'] },
    { nom: 'Halle', inp: '1,89', mod: true, e: ['sans prix', 'ko'], e2: ['0 %', 'ok'], etat: 'à écrire : le prix général' },
  ],
  synthese: '<b>Le prix général du panel (1,00 €/kg) est 47 % sous ce que paient les trois magasins qui chiffrent le sucre (1,89 €/kg)</b>, et Halle n’a pas de prix : une recette chiffrée au prix général sous-estime le sucre, celle de Halle l’ignore. Proposé : le prix général et le prix conseillé à 1,89 €/kg, et Halle au prix général.',
  effet: [['Flan Breton', 'Halle', '181,8 g', '3,46 €', '3,81 €'], ['Choux Nappe', 'Halle', '0,65 g', '—', '+0,001 €']],
  n: '3 changements',
  ecritures: 'Prix conseillé et de référence : <span class="rc-code">PATCH /materials/491</span> · prix général : <span class="rc-code">POST /materials/491/manual-price</span> · Halle : <span class="rc-code">prix du magasin</span>. Relus aussitôt, notés au registre, « Annuler » remet l’avant.',
};

/* b — Poche Sauce Tikka : l'unité (g) ne colle pas au colis du fournisseur (4 poches) ; on la passe en pièces. */
const TIKKA = {
  mid: 649, nom: 'Poche Sauce Tikka', sous: 'Plat Prepare - Chaud · centrale · matière 649 · 1 recette, 538 € de CA sur 30 jours', fourn: 'Berdiff s.a.', par: 'pièce', integre: true, allergenes: ['Lait', 'Moutarde'],
  champs: [champ('Nom', inp('Poche Sauce Tikka')), champ('Catégorie', sel('Plat Prepare - Chaud')), champ('Unité de base', sel('pcs — pièce', true), '!avant : g — le colis du fournisseur se compte en poches'),
    champ('Type', sel('Matière première (RAW)')), champ('Source', sel('Centrale (CENTRAL)'), 'achetée à la centrale (Berdiff s.a.)'), champ('Perte', inp('0 %')),
    champ('TVA', sel('6 %')), champ('Matière au panel', inp('n° 649 · « Poche Sauce Tikka »'), 'recettes : 1 · sous-recettes : —', 'l2')],
  recettes: ['Poulet Tikka Masala & Riz'],
  alerte: '<b>L’unité change : g → pièce.</b> La recette « Poulet Tikka Masala & Riz » emploie 180 g de sauce par pièce : à l’enregistrement, elle s’ouvre en correction pour y poser le nombre de poches (180 g sur une poche de 500 g : 0,36 poche). Tant qu’elle n’est pas reposée, la recette garde « 180 » et le coût serait faux : le cockpit le signale dans « Recettes à corriger ».',
  prix: [
    { gen: true, nom: 'Prix général', s: 'prix de référence du panel, pour tout le réseau', av: '—', inp: '2,71', mod: true, etat: 'pris sur la liste de Berdiff s.a.' },
    { nom: 'Prix conseillé du panel', av: '—', inp: '2,71', mod: true, e: ['0 %', 'ok'] },
    { grp: 'Fournisseurs liés' },
    { nom: 'Berdiff s.a.', s: 'centrale · catalogue', px: '2,7075', e: ['0 %', 'ok'], etat: 'colis de 4 pcs à 10,83 € HT · en vigueur depuis le 21/08', action: 'Prendre comme prix général' },
    { grp: 'Magasins' },
    { nom: 'Corbais', inp: '2,71', mod: true, e: ['sans prix', 'ko'], e2: ['0 %', 'ok'], etat: 'à écrire' },
    { nom: 'Gosselies', inp: '2,71', mod: true, e: ['sans prix', 'ko'], e2: ['0 %', 'ok'], etat: 'à écrire' },
    { nom: 'Sombreffe', inp: '2,71', mod: true, e: ['sans prix', 'ko'], e2: ['0 %', 'ok'], etat: 'à écrire' },
    { nom: 'Halle', inp: '2,71', mod: true, e: ['sans prix', 'ko'], e2: ['0 %', 'ok'], etat: 'à écrire' },
  ],
  synthese: '<b>Aucun magasin ne chiffre la sauce, et le panel n’a pas de prix général</b> ; la seule référence est la liste de Berdiff (10,83 € les 4 poches), qui ne se compare qu’une fois l’unité en pièces. Proposé : l’unité en pièces, le prix général à 2,71 €/poche, et les quatre magasins au prix général.',
  n: '7 changements',
  ecritures: 'Unité, prix conseillé et de référence : <span class="rc-code">PATCH /materials/649</span> · prix général : <span class="rc-code">POST /materials/649/manual-price</span> · 4 × <span class="rc-code">prix du magasin</span>. Relus aussitôt, notés au registre ; la recette concernée s’ouvre ensuite en correction.',
};

fs.writeFileSync(path.join(D, 'a.html'), page(fond, volet(SUCRE)));
fs.writeFileSync(path.join(D, 'b.html'), page(fond, volet(TIKKA)));
fs.writeFileSync(path.join(D, 'capturer.js'), `/* Captures de la maquette « Fiche matière » : le volet entier (sa hauteur fait la fenêtre).
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/fiche-matiere/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1600, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  for (const m of ['a', 'b']) {
    await p.setViewportSize({ width: 1600, height: 950 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/fiche-matiere/' + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.querySelector('.vr-fiche').scrollHeight);
    await p.setViewportSize({ width: 1600, height: Math.max(950, h) });
    await p.screenshot({ path: path.join(__dirname, m + '.png') });
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
`);
console.log('a.html, b.html, capturer.js');
