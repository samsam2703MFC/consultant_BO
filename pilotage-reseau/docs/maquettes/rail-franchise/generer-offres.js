/* Maquette « Vendre › Offres » du dashboard franchisé (demande du 15/11/2026 : « rajouter dans Vendre : Offres — Client
 * offers, avec le gabarit fait précédemment » ; puis « only api »).
 *
 *   node docs/maquettes/rail-franchise/generer-offres.js   → offres.html, offre-edition.html, offre-a4.html
 *   node docs/maquettes/rail-franchise/capturer.js         → les mêmes en .png
 *
 * Seulement l'API Client offers de TF Buddy, rien d'autre : pas de table du cockpit, pas de PDF fabriqué par le cockpit,
 * pas de chiffre venu d'ailleurs. Chaque geste porte, sous lui, l'appel qu'il fait :
 *   GET    /api/v1/shops/{shopId}/offers?include=products,client   la liste, les onglets, les chiffres du haut
 *   GET    /api/v1/offers/{offerId}?include=products,client        l'offre ouverte, son aperçu au gabarit A4 version A
 *   POST   /api/v1/offers                                          « Nouvelle offre » (corps : OfferInput)
 *   PATCH  /api/v1/offers/{offerId}                                « Modifier », « Archiver » (active = 0)
 *   DELETE /api/v1/offers/{offerId}                                « Supprimer »
 *   POST   /api/v1/offers/{offerId}/convert-to-order               « Convertir en commande » (pick_up_datetime, id_employee)
 *   GET    /api/v1/offers/{offerId}/document?lang=fr|nl|en         le PDF (celui que TF Buddy rend)
 * Les onglets se déduisent des champs de l'offre : En cours (active 1, status 0), Converties (status 1 : c'est ce que
 * convert-to-order pose, OfferService::convertToOrder), Archivées (active 0). L'API ne donne pas de date de validité : elle
 * va dans `conditions`. Le PDF exige un client (`id_client`, sinon 404 MISSING_OFFER_CLIENT) et des produits ; son nom :
 * « Offre_{id}_{client} » (OfferService::generateFileName). Offres, clients, produits et montants : EXEMPLES (italique pointillé) ; aucun nom de
 * personne (le personnel : « Personne A »). L'API des offres répond 401 au cockpit aujourd'hui : rien n'a été lu. */
const fs = require('fs');
const path = require('path');
const { R, I, ico, ex, eur, page } = require('./commun-maquettes');
const D = __dirname;
const api = (m, p) => `<span class="of-api"><b>${m}</b> ${p}</span>`;
const IA_PLUS = 'M12 5v14M5 12h14';

// Les offres du magasin, telles que GET /shops/{shopId}/offers?include=products,client les rendrait (exemples).
// [id, name, client, event_day, retrait ? , lieu, total TTC, create_timestamp, état]
const OFFRES = [
  [412, 'Anniversaire 21/11', 'Client particulier · nouveau', '21/11', true, 'retrait en magasin', 48.5, '15/11 18:20', 'cours'],
  [411, 'Plateaux réunion 26/11', 'Société Exemple SRL · B2B', '26/11', false, 'livraison · 1000 Bruxelles', 266.62, '15/11 17:52', 'cours'],
  [409, 'Communion 6/12', 'Client particulier', '06/12', true, 'retrait en magasin', 186, '12/11 10:05', 'cours'],
  [405, 'Saint-Nicolas de l’école', 'École Exemple ASBL', '04/12', false, 'livraison · 1500 Halle', 312, '08/11 14:30', 'conv'],
  [402, 'Brunch du club', 'Club Exemple ASBL', '14/11', true, 'retrait en magasin', 208, '05/11 09:12', 'conv'],
  [398, 'Baptême 22/11', 'Client particulier', '22/11', true, 'retrait en magasin', 95, '03/11 16:40', 'arch'],
];
const ETAT = { cours: ['cours', 'en cours'], conv: ['conv', 'convertie'], arch: ['arch', 'archivée'] };
const enCours = OFFRES.filter(o => o[8] === 'cours');
const conv = OFFRES.filter(o => o[8] === 'conv');
const somme = L => L.reduce((t, o) => t + o[6], 0);

const KPIS = `<div class="of-kpis">
  <div><span class="k">En cours</span><span class="v">${ex(enCours.length + ' · ' + eur(somme(enCours)))}</span><span class="s">active 1 · status 0</span></div>
  <div><span class="k">Converties en commande · novembre</span><span class="v">${ex(conv.length + ' · ' + eur(somme(conv)))}</span><span class="s">status 1 (posé par la conversion)</span></div>
  <div><span class="k">Taux de conversion · novembre</span><span class="v">${ex('33 %')}</span><span class="s">${ex('2 converties sur 6 offres créées')}</span></div>
  <div><span class="k">Prochain événement</span><span class="v">${ex('sam. 21/11')}</span><span class="s">${ex('offre n° 412 · anniversaire · retrait')}</span></div></div>`;

const onglets = (actif, principal = true) => `<div class="rs-onglets"><div class="page-tabs" style="margin:0;border:0">${[['En cours', enCours.length], ['Converties', conv.length], ['Archivées', 1], ['Toutes', OFFRES.length]].map(([l, n]) => `<button class="page-tabs__tab${l === actif ? ' is-on' : ''}">${l} <span class="page-tabs__count">${ex(n)}</span></button>`).join('')}</div>
  <span style="flex:1"></span>${api('POST', '/api/v1/offers')}<button class="btn${principal ? ' btn--primary' : ''}">${ico(IA_PLUS)}Nouvelle offre</button></div>`;

const liste = sel => `<div class="of-g"><div class="of-outils"><div class="ml-q-b">${ico(I.chercher)}<input class="ml-q-in" placeholder="Chercher une offre, un client, un produit…"></div>
    <button class="btn btn--sm">Jour de l’événement ▾</button></div>
  <table class="of-t"><thead><tr><th>N°</th><th>Offre · client</th><th>Événement · retrait ou livraison</th><th class="num">Total TTC</th><th>État</th></tr></thead><tbody>
  ${OFFRES.map(([id, nom, cl, jour, , lieu, tot, cree, et]) => `<tr${id === sel ? ' class="on"' : ''}><td>${ex(id)}</td><td>${ex(nom)}<small>${ex(cl)} · créée le ${ex(cree)}</small></td><td>${ex(jour)}<small>${ex(lieu)}</small></td><td class="num">${ex(eur(tot))}</td><td><span class="of-etat ${ETAT[et][0]}">${ETAT[et][1]}</span></td></tr>`).join('')}
  </tbody><tfoot><tr><td colspan="5">${OFFRES.length} offres · ${api('GET', '/api/v1/shops/{shopId}/offers?include=products,client')}</td></tr></tfoot></table></div>`;

/* L'offre n° 411 : les lignes de OfferProductRecord (prix TTC, TVA, net calculé par TF Buddy). */
const L411 = [
  { p: 'Plateau sandwichs · 6 pièces', s: 'remise 5 % · 9,88 €', q: 25, pu: 7.9, tva: 6, fin: 187.62 },
  { p: 'Mignardises · plateau de 25', s: '', q: 2, pu: 32, tva: 6, fin: 64 },
];
const LIV = 15;
const net = l => l.fin / (1 + l.tva / 100);
function doc(L, o) {
  const ht = L.reduce((t, l) => t + net(l), 0);
  const ttc = L.reduce((t, l) => t + l.fin, 0);
  return `<div class="doc-a4">
  <div class="doc__head"><img class="doc__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="doc__type"><b>Offre de prix</b>N° ${ex(o.id)} · ${ex(o.cree)}</div></div>
  <div class="doc__parties">
    <div><div class="doc__label">Émetteur</div><p><b>L’Atelier By Halle</b></p><p class="manque">adresse, n° d’entreprise et TVA : la fiche du magasin dans TF Buddy</p></div>
    <div><div class="doc__label">Destinataire</div><p><b>${ex(o.client)}</b></p><p>${ex(o.adr)}</p><p>${ex(o.tva)}</p></div></div>
  <div class="doc__refs">
    <div><div class="doc__label">Date</div><div class="v">${ex(o.cree)}</div></div>
    <div><div class="doc__label">Jour de l’événement</div><div class="v">${ex(o.jour)}</div></div>
    <div><div class="doc__label">${o.retrait ? 'Retrait' : 'Livraison'}</div><div class="v">${ex(o.lieu)}</div></div>
    <div><div class="doc__label">Conditions</div><div class="v">${ex(o.cond)}</div></div></div>
  <div class="doc__objet"><div class="doc__label">Objet</div><p>${ex(o.nom)} · ${ex(o.desc)}</p></div>
  <table><thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">PU TTC</th><th class="num">TVA</th><th class="num">Total HT</th><th class="num">Total TTC</th></tr></thead><tbody>
    ${L.map(l => `<tr><td><b>${ex(l.p)}</b>${l.s ? `<span class="sub">${ex(l.s)}</span>` : ''}</td><td class="num">${ex(l.q)}</td><td class="num">${ex(eur(l.pu))}</td><td class="num">${ex(l.tva + ' %')}</td><td class="num">${ex(eur(net(l)))}</td><td class="num">${ex(eur(l.fin))}</td></tr>`).join('')}
    ${o.liv ? `<tr><td><b>Livraison</b><span class="sub">${ex(o.lieu)}</span></td><td class="num">1</td><td class="num">${ex(eur(o.liv))}</td><td class="num"></td><td class="num"></td><td class="num">${ex(eur(o.liv))}</td></tr>` : ''}
  </tbody></table>
  <table class="doc__totals"><tbody>
    <tr><td>Total HT des produits</td><td class="num">${ex(eur(ht))}</td></tr>
    <tr><td>TVA 6 %</td><td class="num">${ex(eur(ttc - ht))}</td></tr>
    ${o.liv ? `<tr><td>Livraison</td><td class="num">${ex(eur(o.liv))}</td></tr>` : ''}
    <tr class="is-due"><td>Total TTC</td><td class="num">${ex(eur(ttc + (o.liv || 0)))}</td></tr></tbody></table>
  <div class="doc__box"><div class="doc__label">Bon pour accord</div><p>${ex('Offre valable jusqu’au 20/11/2026 ; acompte de 30 % à la commande.')}</p>
    <div class="doc__sign"><div>Date</div><div>Nom</div><div>Signature</div></div></div>
  <div class="doc__foot"><span class="manque">Mentions légales (raison sociale, siège, n° d’entreprise, TVA, RPM, conditions générales) : la fiche du magasin dans TF Buddy, non lue ici.</span><span>Page 1 / 1</span></div>
</div>`;
}
const O411 = { id: 411, cree: '15/11/2026', client: 'Société Exemple SRL', adr: 'Rue de l’Exemple 12, 1000 Bruxelles', tva: 'TVA BE 0000.000.000', jour: '26/11/2026', retrait: false, lieu: 'Rue de l’Exemple 12, 1000 Bruxelles · 11:30', cond: 'acompte 30 % · valable jusqu’au 20/11', nom: 'Plateaux réunion 26/11', desc: 'réunion de 12 h, 25 personnes', liv: LIV };

const geste = (b, a) => `<div class="of-geste">${b}${a}</div>`;
const detail = `<div class="of-d">
  <div class="of-d-h"><div><span class="mk-eyebrow">Offre n° ${ex(411)} · <span class="of-etat cours">en cours</span></span><h2>${ex('Plateaux réunion 26/11')}</h2>
    <p>${ex('Société Exemple SRL · B2B')} · créée le ${ex('15/11 à 17:52')} depuis la boîte mail</p>${api('GET', '/offers/411?include=products,client')}</div></div>
  <div class="of-gestes">
    ${geste('<button class="btn">Modifier</button>', api('PATCH', '/offers/411'))}
    ${geste(`<button class="btn">${ico(I.pdf)}PDF · FR · NL</button>`, api('GET', '/offers/411/document?lang=fr|nl'))}
    ${geste(`<button class="btn">${ico(I.mail)}Envoyer par la boîte mail</button>`, '<span class="of-api">le PDF ci-contre en pièce jointe</span>')}
    ${geste(`<button class="btn">${ico(I.panier)}Convertir en commande</button>`, api('POST', '/offers/411/convert-to-order'))}
    ${geste('<button class="btn">Archiver</button>', api('PATCH', '/offers/411 · active 0'))}
    ${geste('<button class="btn">Supprimer</button>', api('DELETE', '/offers/411'))}
  </div>
  <div class="of-apercu"><div class="of-apercu-l"><span>Aperçu · gabarit A4 version A</span><a href="#">Ouvrir en grand ›</a></div>${doc(L411, O411)}</div>
</div>`;

page(D, 'offres', 'Offres', 'Offres', 'Offres',
  'Maquette · Vendre › Offres · seulement l’API Client offers de TF Buddy (l’appel est écrit sous chaque geste) · offres, clients, montants : exemples',
  onglets('En cours') + KPIS + `<div class="of">${liste(411)}${detail}</div>`);

/* L'offre ouverte en modification : exactement les champs de OfferInput, et la conversion en commande. */
const ch = (l, v, a, cls = '') => `<label class="${cls}">${l}<span class="in${cls.includes('haut') ? ' haut' : ''}${cls.includes('sel') ? ' sel' : ''}">${v}</span>${a ? `<span class="of-api">${a}</span>` : ''}</label>`;
const ttc411 = L411.reduce((t, l) => t + l.fin, 0);
const ht411 = L411.reduce((t, l) => t + net(l), 0);
const edition = `<div class="of-form">
  <section class="card of-f"><h2>L’offre n° ${ex(411)} · ${api('PATCH', '/api/v1/offers/411')}</h2>
    <div class="of-ch">
      ${ch('Nom de l’offre', ex('Plateaux réunion 26/11'), 'name')}
      ${ch('Client', ex('Société Exemple SRL · B2B'), 'id_client', 'sel')}
      ${ch('Jour de l’événement', ex('jeudi 26/11/2026'), 'event_day')}
      <label>Retrait ou livraison<span class="seg" style="width:fit-content"><button>Retrait en magasin</button><button class="is-on">Livraison</button></span><span class="of-api">in_store_pickup 0</span></label>
      ${ch('Adresse de livraison', ex('Rue de l’Exemple 12'), 'delivery_street')}
      <label>Code postal · ville<span style="display:grid;grid-template-columns:90px 1fr;gap:8px"><span class="in">${ex('1000')}</span><span class="in">${ex('Bruxelles')}</span></span><span class="of-api">delivery_zip · delivery_city</span></label>
      ${ch('Frais de livraison', ex(eur(LIV)), 'delivery_cost')}
      ${ch('Conditions', ex('acompte 30 % · valable jusqu’au 20/11'), 'conditions')}
      ${ch('Description', ex('Réunion de 12 h, 25 personnes, livraison à 11 h 30, comme la commande du 29/10.'), 'description', 'large haut')}
    </div>
    <table class="of-lignes"><thead><tr><th>Produit du catalogue</th><th class="num">Quantité</th><th class="num">Prix TTC</th><th class="num">Remise</th><th class="num">Total TTC</th></tr></thead><tbody>
      ${L411.map(l => `<tr><td>${ex(l.p)}<small>id_product · product_name</small></td><td class="num">${ex(l.q)}</td><td class="num">${ex(eur(l.pu))}</td><td class="num">${l.s ? ex('5 %') : '—'}</td><td class="num">${ex(eur(l.fin))}</td></tr>`).join('')}
      <tr class="of-ajout"><td colspan="5">${ico(IA_PLUS)} Ajouter un produit du catalogue du magasin <span class="of-api">products[]</span></td></tr></tbody></table>
    <div class="of-tot"><span class="k">Total HT des produits</span><span class="num">${ex(eur(ht411))}</span><span class="k">TVA 6 %</span><span class="num">${ex(eur(ttc411 - ht411))}</span><span class="k">Livraison</span><span class="num">${ex(eur(LIV))}</span><span class="k du">Total TTC</span><span class="num du">${ex(eur(ttc411 + LIV))}</span></div>
    <div class="rs-actions"><button class="btn btn--ghost">Annuler</button><button class="btn btn--primary">Enregistrer l’offre</button></div>
  </section>
  <div class="of-cote">
    <section class="card"><h2>Le client accepte</h2><p>La commande se crée dans TF Buddy à partir de l’offre, avec ses produits ; l’offre passe en status 1, « convertie ».</p>
      <div class="of-conv"><div class="of-ch">${ch('Retrait ou départ de la livraison', ex('jeudi 26/11/2026 · 11:00'), 'pick_up_datetime · obligatoire')}${ch('Préparée par', ex('Personne A'), 'id_employee', 'sel')}</div>
        <button class="btn">${ico(I.panier)}Convertir en commande</button>${api('POST', '/api/v1/offers/411/convert-to-order')}</div></section>
    <section class="card"><h2>Le PDF</h2><p>Le document que TF Buddy rend pour l’offre, dans la langue du client.</p>
      <div class="rs-puces"><button class="rs-puce on">FR</button><button class="rs-puce">NL</button><button class="rs-puce">EN</button></div>
      <div class="ml-pj-off">${ico(I.pdf)}<span>${ex('Offre_411_Societe_Exemple_SRL.pdf')}</span><a href="#">Ouvrir</a></div>${api('GET', '/api/v1/offers/411/document?lang=fr')}</section>
    <section class="card"><h2>Ce que l’écran appelle</h2><ul class="of-liste-api">
      <li>La liste et les chiffres${api('GET', '/shops/{shopId}/offers')}</li>
      <li>L’offre ouverte${api('GET', '/offers/{offerId}')}</li>
      <li>Nouvelle offre${api('POST', '/offers')}</li>
      <li>Modifier, archiver${api('PATCH', '/offers/{offerId}')}</li>
      <li>Supprimer${api('DELETE', '/offers/{offerId}')}</li>
      <li>Convertir en commande${api('POST', '/offers/{offerId}/convert-to-order')}</li>
      <li>Le PDF (client et produits obligatoires)${api('GET', '/offers/{offerId}/document')}</li></ul></section>
  </div></div>`;
page(D, 'offre-edition', 'Offres · modifier', 'Offres', 'Offres',
  'Maquette · une offre ouverte : les champs de OfferInput de TF Buddy, rien d’autre · exemples',
  onglets('En cours', false) + edition);

/* Le document seul, à l'échelle 1 (capture de l'élément). */
fs.writeFileSync(path.join(D, 'offre-a4.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Offre de prix n° 411 — gabarit A4 version A (maquette)</title>
<link rel="stylesheet" href="${R}assets/ds/global.css"><link rel="stylesheet" href="rf.css"><link rel="stylesheet" href="offres.css">
<style>body{margin:0;padding:24px;background:#faf7f2}.doc-a4{margin:0 auto;border:1px solid #e6e0d8}</style></head>
<body>${doc(L411, O411)}</body></html>
`);
console.log('offres.html, offre-edition.html, offre-a4.html');
