/* Maquette « Boîte mail » dans le dashboard franchisé — orientée réponse rapide et ventes.
 * Demandes du 15/11/2026 : « au-dessus, mettre dans le rail : boîte mail » ; « dans le mail, créer une offre, bouton
 * assistant » ; « corriger le mail : toutes les possibilités fonctionnelles pour une boîte mail efficace, orientée réponse
 * rapide et développement des ventes » ; « only api ».
 *
 *   node docs/maquettes/rail-franchise/generer-mail.js   → mail.html (le client accepte une offre), mail-offre.html (créer)
 *   node docs/maquettes/rail-franchise/capturer.js       → les mêmes en .png
 *
 * Pour aller vite et vendre, la boîte ne fait que trois choses de plus qu'une boîte ordinaire, et chacune passe par l'API
 * Client offers de TF Buddy (l'appel est écrit sous le geste) :
 *  - une demande de client → « Créer une offre » : l'assistant lit l'e-mail et remplit OfferInput (name, description,
 *    event_day, in_store_pickup ou delivery_*, conditions, products du catalogue, id_client — le PDF l'exige : un nouveau
 *    client se crée par POST /api/v1/clients) → POST /api/v1/offers ; le PDF
 *    (GET /offers/{id}/document?lang=…) part en pièce jointe de la réponse ; « Modifier » = PATCH, « Supprimer » = DELETE ;
 *  - un client qui accepte une offre → « Convertir en commande » : POST /offers/{id}/convert-to-order (pick_up_datetime lu
 *    dans l'e-mail, id_employee choisi) ;
 *  - les chiffres du haut : la liste des offres du magasin (GET /shops/{shopId}/offers), rien d'autre.
 * Le reste est la boîte elle-même : les onglets, la recherche, les réponses rapides, Répondre, Transférer, Archiver.
 * Aujourd'hui le cockpit envoie des e-mails et n'en lit aucun : e-mails, clients, montants sont des EXEMPLES (italique
 * pointillé) ; aucun nom de personne (le personnel : « Personne A »). */
const { I, ico, IA, ex, eur, page } = require('./commun-maquettes');
const D = __dirname;
const api = (m, p) => `<span class="of-api"><b>${m}</b> ${p}</span>`;

const KPIS = `<div class="of-kpis">
  <div><span class="k">À traiter</span><span class="v">${ex('4')}</span><span class="s">${ex('dont 2 demandes de clients')}</span></div>
  <div><span class="k">Offres en cours</span><span class="v">${ex('3 · 501,12 €')}</span><span class="s">${api('GET', '/shops/{shopId}/offers')}</span></div>
  <div><span class="k">Converties en commande · novembre</span><span class="v">${ex('2 · 520,00 €')}</span><span class="s">status 1</span></div>
  <div><span class="k">En cours depuis le plus longtemps</span><span class="v">${ex('n° 409')}</span><span class="s">${ex('créée le 12/11 · communion du 6/12')}</span></div></div>`;

// [de, objet, extrait, quand, non lu ?, pièce jointe ?, étiquette, [geste, état ?]]
const MAILS = [
  ['Client particulier', 'Re : Offre n° 409 · Communion du 6/12', 'C’est parfait, nous confirmons ! Nous passerons la chercher le samedi 5/12 vers 15 h…', '18:40', true, false, 'Offre', ['Convertir en commande']],
  ['Client particulier', 'Bûches de Noël : vous prenez déjà les commandes ?', 'Bonjour, nous serons 10 le 24/12. Deux bûches, une praliné et une framboise…', '18:31', true, false, 'Demande', ['Créer une offre']],
  ['Client particulier · nouveau', 'Gâteau d’anniversaire pour samedi 21/11', 'Serait-il possible d’avoir un fraisier pour 12 personnes avec « Joyeux anniversaire »…', '18:12', false, false, 'Demande', ['Offre n° 412 envoyée', true]],
  ['Société Exemple SRL', 'Plateaux sandwichs pour 25 personnes, jeudi 26/11', 'Comme le mois dernier, pourriez-vous nous livrer 25 plateaux pour notre réunion…', '17:40', false, false, 'Demande', ['Offre n° 411 envoyée', true]],
  ['Google Business Profile', 'Nouvel avis 5 étoiles sur L’Atelier By Halle', '« Les croissants du dimanche sont les meilleurs du coin, et l’accueil… »', '16:05', true, false, 'Avis', null],
  ['Le réseau L’Atelier By', 'Saint-Nicolas : les visuels et le planning des posts', 'Voici les visuels de la campagne et les dates des posts jusqu’au 6/12…', '09:42', true, true, 'Campagne', null],
  ['Centrale d’achat', 'Votre commande du 17/11 est confirmée', 'Farine T65 · 12 sacs, beurre AOP · 8 cartons, livraison mardi entre 6 h et 8 h…', '08:15', false, true, 'Commande', null],
];
const liste = (sel, M = MAILS) => M.map(([de, obj, ext, quand, nonlu, pj, etiq, g], i) => `<div class="ml-l${nonlu && i !== sel ? ' nonlu' : ''}${i === sel ? ' on' : ''}">
    <div class="ml-l-h"><span class="ml-de">${ex(de)}</span><span class="ml-q">${ex(quand)}</span></div>
    <div class="ml-obj">${nonlu && i !== sel ? '<i class="ml-pt"></i>' : ''}${ex(obj)}</div>
    <div class="ml-ext">${pj ? ico(I.trombone) : ''}${etiq ? `<span class="ml-et">${etiq}</span>` : ''}<span>${ex(ext)}</span></div>
    ${g ? `<div class="ml-geste${g[1] ? ' etat' : ''}">${g[1] ? ico(I.offre) : IA}${g[0]}</div>` : ''}</div>`).join('');

const REPONSES = ['Merci, nous revenons vers vous aujourd’hui', 'Votre commande est prête', 'Nos horaires et l’adresse', 'Il nous manque une précision'];
const rapides = `<div class="ml-rapides"><span class="ml-ia-k">Réponses rapides</span><div class="rs-puces">${REPONSES.map(r => `<button class="rs-puce">${r}</button>`).join('')}<button class="rs-puce ml-puce-ia">${IA}Rédiger avec l’assistant</button></div></div>`;
const tete = (obj, de, adr, quand) => `<div class="ml-r-h"><div><h2 class="ml-r-obj">${ex(obj)}</h2>
    <div class="ml-r-de">${ex(de)} <span class="muted">· ${ex(adr)} → halle@atelierby.be · ${ex(quand)}</span></div></div>
    <div class="ml-r-act"><button class="btn btn--sm">Répondre</button><button class="btn btn--sm">Transférer</button><button class="btn btn--sm btn--ghost">Archiver</button></div></div>`;
const tableOffre = (L, liv) => {
  const tot = L.reduce((t, l) => t + l[2] * l[3], 0) + (liv || 0);
  return `<table class="ml-off-t"><thead><tr><th>Produit du catalogue</th><th class="num">Quantité</th><th class="num">Prix TTC</th><th class="num">Total TTC</th></tr></thead><tbody>
      ${L.map(([p, s2, q, u]) => `<tr><td>${ex(p)}${s2 ? `<small>${s2}</small>` : ''}</td><td class="num">${ex(q)}</td><td class="num">${ex(eur(u))}</td><td class="num">${ex(eur(q * u))}</td></tr>`).join('')}
      <tr class="ml-off-tot"><td>Total TTC <small>TVA 6 % comprise · calculée par TF Buddy</small></td><td></td><td></td><td class="num">${ex(eur(tot))}</td></tr></tbody></table>`;
};

/* Écran 1 : le client accepte l'offre n° 409 → la convertir en commande, sans quitter l'e-mail. */
const L409 = [['Gâteau de communion · 20 parts', 'vanille-framboise, plaque prénom', 2, 58], ['Mignardises', 'assortiment', 40, 1.75]];
const accepte = `<div class="ml-r">
  ${tete('Re : Offre n° 409 · Communion du 6/12', 'Client particulier', 'client@exemple.be', 'aujourd’hui à 18:40')}
  <div class="ml-corps">${ex('Bonjour,')}<br>${ex('c’est parfait, nous confirmons l’offre n° 409 ! Nous passerons la chercher le samedi 5 décembre vers 15 h.')}<br>${ex('Merci beaucoup.')}</div>
  <div class="ml-ia"><span class="ml-ia-o">${IA}</span><div class="ml-ia-t"><span class="ml-ia-k">Coach · l’assistant a lu la réponse</span>
      <span>${ex('Le client accepte l’offre n° 409 · retrait le samedi 05/12 à 15:00')}</span></div>
    <a class="ml-lien-off" href="#">Ouvrir dans Offres ›</a></div>
  <section class="ml-off"><div class="ml-off-h"><div><span class="mk-eyebrow">L’offre acceptée ${api('GET', '/offers/409?include=products,client')}</span><h3>Offre n° ${ex(409)} · ${ex('Communion du 6/12')}</h3></div>
      <span class="ml-off-v">créée le ${ex('12/11')} · retrait en magasin · <span class="of-etat cours">en cours</span></span></div>
    ${tableOffre(L409)}
    <div class="ml-conv"><label>Retrait<span class="in">${ex('samedi 05/12/2026 · 15:00')}</span><span class="of-api">pick_up_datetime · lu dans l’e-mail · obligatoire</span></label>
      <label>Préparée par<span class="in sel">${ex('Personne A')}</span><span class="of-api">id_employee</span></label>
      <div><button class="btn btn--primary">${ico(I.panier)}Convertir en commande</button>${api('POST', '/offers/409/convert-to-order')}</div></div>
  </section>
  ${rapides}
  <div class="ml-rep"><span class="muted">${ex('Bonjour, merci pour votre confirmation ! Votre commande est enregistrée : elle vous attendra le samedi 5/12 à 15 h…')}</span><button class="btn">Répondre</button></div>
</div>`;

/* Écran 2 : « Créer une offre » sur la demande du gâteau → l'offre n° 412 dans TF Buddy, son PDF, la réponse. */
const L412 = [['Fraisier · 12 parts', '', 1, 42], ['Plaque « Joyeux anniversaire »', 'chocolat blanc', 1, 3.5], ['Bougies chiffres', '', 2, 1.5]];
const champ = (k, v) => `<span class="k">${k}</span><span class="v">${v}</span>`;
const creer = `<div class="ml-r">
  ${tete('Gâteau d’anniversaire pour samedi 21/11', 'Client particulier · nouveau', 'client@exemple.be', 'aujourd’hui à 18:12')}
  <div class="ml-corps">${ex('Bonjour, serait-il possible d’avoir un fraisier pour 12 personnes avec « Joyeux anniversaire » écrit dessus, pour samedi 21 novembre ? Je passerais le chercher vers 10 h. Merci !')}</div>
  <div class="ml-ia fait"><span class="ml-ia-o">${IA}</span><div class="ml-ia-t"><span class="ml-ia-k">Coach · offre créée dans TF Buddy ${api('POST', '/api/v1/offers')}</span>
      <span>Offre ${ex('n° 412')} · ${ex('fraisier 12 parts, plaque, bougies')} · retrait en magasin ${ex('samedi 21/11')}</span></div>
    <a class="ml-lien-off" href="#">Ouvrir dans Offres ›</a></div>
  <section class="ml-off"><div class="ml-off-h"><div><span class="mk-eyebrow">Ce que l’assistant a envoyé · OfferInput</span><h3>Offre n° ${ex(412)} · ${ex('Anniversaire 21/11')}</h3></div>
      <span class="ml-off-v">à relire avant l’envoi</span></div>
    <div class="ml-api-l">
      ${champ('name', ex('Anniversaire 21/11'))}
      ${champ('description', ex('Fraisier 12 personnes, « Joyeux anniversaire », demande par e-mail de client@exemple.be'))}
      ${champ('event_day', ex('2026-11-21'))}
      ${champ('in_store_pickup', 'oui · retrait en magasin')}
      ${champ('conditions', ex('acompte de 30 % · valable jusqu’au 18/11'))}
      ${champ('id_client', ex('n° 2431') + ' · client créé depuis l’e-mail (nom, e-mail) ' + api('POST', '/api/v1/clients'))}
    </div>
    ${tableOffre(L412)}
    <div class="ml-pj-off">${ico(I.pdf)}<span>${ex('Offre_412_Prenom_Nom.pdf')} · en pièce jointe de la réponse ${api('GET', '/offers/412/document?lang=fr')}</span><a href="#">Voir le PDF</a></div>
    <div class="ml-off-rep" style="margin-top:12px"><span class="ml-ia-k">La réponse, rédigée par l’assistant</span><div class="rs-texte">${ex('Bonjour, merci pour votre demande ! Vous trouverez en pièce jointe notre offre pour un fraisier de 12 parts avec la plaque « Joyeux anniversaire » et deux bougies chiffres : 48,50 €. Pour la confirmer, il suffit de nous répondre ; le gâteau vous attendra samedi 21/11 à 10 h.')}<br>${ex('L’équipe de L’Atelier By Halle')}</div></div>
    <div class="rs-actions ml-gestes"><div class="of-geste"><button class="btn btn--ghost">Supprimer l’offre</button>${api('DELETE', '/offers/412')}</div><div class="of-geste"><button class="btn">Modifier l’offre</button>${api('PATCH', '/offers/412')}</div><div class="of-geste"><button class="btn btn--primary">${ico(I.envoyer)}Envoyer la réponse et l’offre</button><span class="of-api">la boîte mail · le PDF joint</span></div></div></section>
</div>`;

const corps = (sel, lect, M) => `<div class="rs-onglets"><div class="page-tabs" style="margin:0;border:0">${[['À traiter', '4'], ['Demandes de clients', '2'], ['Tout', ''], ['Envoyés', ''], ['Brouillons', '1']].map(([l, n], i) => `<button class="page-tabs__tab${i === 0 ? ' is-on' : ''}">${l}${n ? ` <span class="page-tabs__count">${ex(n)}</span>` : ''}</button>`).join('')}</div>
  <span style="flex:1"></span><span class="muted ml-adr">halle@atelierby.be</span><button class="btn">${ico(I.ecrire)}Écrire</button></div>
  ${KPIS}
  <div class="ml">
    <div class="ml-g"><div class="ml-q-b">${ico(I.chercher)}<input class="ml-q-in" placeholder="Chercher un e-mail, un client, une offre…"></div>
      <div class="ml-liste">${liste(sel, M)}</div></div>
    ${lect}
  </div>`;

const NOTE = 'Maquette · la boîte mail, réponse rapide et ventes · les offres : seulement l’API Client offers de TF Buddy (l’appel est écrit sous chaque geste) · e-mails, clients, montants : exemples';
page(D, 'mail', 'Boîte mail', 'Boîte mail', 'Boîte mail', NOTE, corps(0, accepte));
// Au moment de « Créer une offre », l'offre n° 412 existe mais la réponse n'est pas encore partie.
const M2 = MAILS.map((m, i) => i === 2 ? [...m.slice(0, 7), ['Offre n° 412 créée · à envoyer', true]] : m);
page(D, 'mail-offre', 'Boîte mail · Créer une offre', 'Boîte mail', 'Boîte mail', NOTE, corps(2, creer, M2));
console.log('mail.html, mail-offre.html');
