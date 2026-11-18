/* Le dashboard au téléphone, refonte (18/11/2026) : les vues qui s'ouvrent depuis les onglets, les cartes du bas des
 * écrans défilés, et l'affichage (?v=…). Chargé après le script d'index.html, dont il reprend les aides (fE, tel, carte…).
 *   Feuilles du bas : ?v=cmd (Commandes du jour), b2b (Ventes B2B), produit (un produit de la vitrine), equipe (L'équipe
 *   du jour), stock, poubelle, bureau (Une demande au bureau).
 *   Écrans pleins : ?v=photo (une photo de contrôle agrandie), reclamation (Réclamer au fournisseur), revue (la revue
 *   du soir). ?v=planche-vues : les dix.
 * Réel : les deux commandes du 18/11 et celles du 19/11 (lues au panel, sans articles : le panel ne les joint pas).
 * Le reste est illustratif ; prénoms, sociétés et fournisseur sont inventés. */

/* ---------- les cartes du bas des écrans défilés ---------- */
function stockCarte() {
  return carte('Stock', 'compté le 16/11', `<div class="ls"><div class="r"><span class="d8 ko"></span><span class="t">3 références à zéro<small>gobelets 33 cl, sacs kraft M, sucre en bûchettes</small></span><span class="ch">›</span></div>
    <div class="r"><span class="d8 wa"></span><span class="t">5 sous leur minimum<small>la commande fournisseur part jeudi</small></span><span class="ch">›</span></div></div>`);
}
function poubelleCarte() {
  return carte('La poubelle d’hier', 'mar. 17 nov.', `<div class="ls"><div class="r"><span class="d8 ok"></span><span class="t">18 pièces · 46 €<small>2,4 % du chiffre · surtout le pain au chocolat (6)</small></span><span class="ch">›</span></div></div>`);
}
function noteCarte() {
  return carte('La note du jour', 'ce qui explique la journée', `<div class="champ ta" style="margin-top:10px">Lendemain de jour férié, beaucoup d’habitués du matin…</div><div class="note">relue l’an prochain la même semaine · 1 note la même semaine l’an dernier</div>`);
}
function fermetureCarte() {
  return `<div class="cd cl" style="opacity:.75"><div class="ct"><b><span class="d8 n"></span>Fermeture</b><small>0 / 9 · s’ouvre à 18:30</small></div><div class="pg"><i style="width:0"></i></div></div>`;
}
function chercheCarte() {
  return `<div class="champ" style="margin-bottom:10px"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#7a736a" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><span class="mu">Chercher une tâche, un guide…</span></div>`;
}
function valeurCarte() {
  return carte('La valeur du magasin', 'les 30 derniers jours', `<div class="hero"><div class="big"><b class="num" style="font-size:28px">98 k€</b><span class="up">+ 2 % sur trois mois</span></div></div>
    <svg viewBox="0 0 330 60" width="100%" height="60" style="margin-top:8px"><polyline fill="none" stroke="#B03048" stroke-width="2" points="0,44 30,42 60,45 90,38 120,40 150,34 180,36 210,30 240,31 270,26 300,27 330,22"/><line x1="0" y1="59" x2="330" y2="59" stroke="#e6e0d8"/></svg>`);
}

/* ---------- les morceaux des vues ---------- */
/** Une page d'onglet, assombrie, et la feuille du bas posée dessus. */
const sur = (page, feuille) => page.replace(/<\/div>$/, `<div class="voile"></div><div class="fe"><div class="poi"></div>${feuille}<div class="home"></div></div></div>`);
const fh = (titre, sous) => `<div class="fh"><div class="t"><h2>${titre}</h2><div class="s">${sous}</div></div><span class="x">✕</span></div>`;
/** Un écran plein, sans barre d'onglets (la revue du soir, la photo agrandie). */
const plein = (cls, h, corps) => `<div class="tel${cls ? ' ' + cls : ''}">${ios().replace('17:43', h)}${corps}<div class="home"></div></div>`;
const li = (h, titre, sous, m, chip, ouvert, dessous) => `<div class="li${ouvert ? ' ouv' : ''}"><div class="r"><span class="h">${h}</span><span class="q"><b>${titre}</b><small>${sous}</small></span><span class="m">${m}${chip ? `<span class="chip ${chip[0]}">${chip[1]}</span>` : ''}</span><span class="c">${ouvert ? '▴' : '▾'}</span></div>${dessous || ''}</div>`;
const forme = {
  vitrine: '<rect x="10" y="58" width="160" height="6" fill="#e9dcc8" opacity=".7"/>' + [20, 48, 76, 104, 132].map((x, i) => `<ellipse cx="${x + 12}" cy="52" rx="12" ry="7" fill="${['#d9a560', '#c98b45', '#e6c08a', '#b9763a', '#d9a560'][i]}"/>`).join(''),
  wc: '<rect x="60" y="20" width="60" height="70" rx="8" fill="#e8e6e1" opacity=".9"/><circle cx="90" cy="70" r="14" fill="#c7c2b8"/><rect x="128" y="62" width="22" height="28" rx="3" fill="#6d6a64"/>',
  pain: '<ellipse cx="90" cy="58" rx="58" ry="20" fill="#c08a4e"/><path d="M50 52c12-6 24-6 36 0M78 50c12-6 24-6 36 0" stroke="#8a5a2b" stroke-width="3" fill="none"/>',
};

/* ---------- les feuilles du bas ---------- */
function vCmd() {
  // Les deux commandes du 18/11, lues au panel : encaissées en caisse, donc déjà dans le comptoir de la jauge.
  const rap = `<div class="rap"><div class="bar"><i style="flex:1;background:var(--ok)"></i></div>
    <div class="r"><span class="d8 ok"></span><span class="t">Encaissées en caisse<small>déjà dans le comptoir du jour</small></span><span class="n">15,60 € · 2</span></div>
    <div class="r"><span class="d8 w"></span><span class="t">Payées en ligne<small>webshop, hors caisse</small></span><span class="n mu">0 €</span></div>
    <div class="r"><span class="d8 wa"></span><span class="t">Prêtes, pas retirées</span><span class="n mu">0 €</span></div>
    <div class="r"><span class="d8 ko"></span><span class="t">Remises sans ticket</span><span class="n mu">0 €</span></div></div>`;
  const pas = '<div class="art"><div class="note" style="margin:0">le panel ne joint pas les articles de cette commande</div></div>';
  const corps = `<div class="fc"><div class="ong"><span class="on">Toutes <small>2</small></span><span>Comptoir <small>1</small></span><span>Webshop <small>1</small></span></div>
    <div class="lab" style="margin-top:0">Le CA en commandes<span>15,60 €</span></div>${rap}
    <div class="sec"><b><span class="d8 k" style="margin-right:6px"></span>Comptoir</b><span>1 · 9,80 €</span></div>
    ${li('08:30', 'Commande comptoir', 'remise · ticket de caisse lié', '9,80 €', ['ok', 'en caisse'], true, pas)}
    <div class="sec"><b><span class="d8 w" style="margin-right:6px"></span>Webshop</b><span>1 · 5,80 € · click &amp; collect 1 · livraison 0</span></div>
    ${li('13:30', 'Click &amp; collect', 'remise · payée au retrait', '5,80 €', ['ok', 'en caisse'])}
    <div class="lab">Demain · jeu. 19 nov.<span>2 commandes · 108,58 €</span></div>
    <div class="note" style="margin-top:0">1 au comptoir (38,50 €) · 1 click &amp; collect (70,08 €) · à préparer pour la cuisson du matin</div></div>`;
  return sur(jour(false), fh('Commandes du jour', 'Atelier by - Halle · mer. 18 nov. · à 17:43') + corps);
}
function vB2B() {
  const L = [[24, 'Mini-viennoiseries', 1.90], [8, 'Sandwich club', 5.90], [1, 'Tarte aux pommes 8 p.', 23.20]];
  const art = `<div class="art"><table>${L.map(l => `<tr><td>${l[0]} × ${l[1]}</td><td class="n p">${fU(l[2])}</td><td class="n">${fU(l[0] * l[2])}</td></tr>`).join('')}</table></div>`;
  const corps = `<div class="fc"><div class="ong"><span class="on">Aujourd’hui</span><span>Mer. 11/11</span></div>
    <div class="k4"><div><div class="k">CA B2B</div><div class="v">116 €</div><div class="s">5,2 % du jour</div></div><div><div class="k">Tickets pro</div><div class="v">1</div><div class="s">sur 238 tickets</div></div>
    <div><div class="k">Panier pro</div><div class="v">116 €</div><div class="s">comptoir 8,96 €</div></div><div><div class="k">À facturer</div><div class="v">116 €</div><div class="s">facture du mois</div></div></div>
    <div class="lab">Les tickets<span>1 société</span></div>${li('14:12', 'Société A', '33 articles · TVA valide', '116,00 €', ['', 'facturé'], true, art)}
    <div class="lab">D’habitude le mercredi<span>part habituelle 2 %</span></div><div class="note" style="margin-top:0">Société A vient 3 mercredis sur 4 · Société B n’est pas passée depuis le 28/10</div></div>`;
  return sur(jour(false), fh('Ventes B2B', 'Atelier by - Halle · tickets des clients pro') + corps);
}
function vProduit() {
  const H = [18, 26, 21, 14, 12, 9, 8, 6, 7, 10, 11, 0], M = [16, 22, 19, 13, 11, 8, 7, 6, 6, 8, 9, 5], mx = 28;
  const b = H.map((v, i) => `<div class="b"><i class="${i === 10 ? 'en' : (i === 11 ? 'av' : '')}" style="height:${(100 * v / mx).toFixed(1)}%"></i><u style="bottom:${(100 * M[i] / mx).toFixed(1)}%"></u></div>`).join('');
  const corps = `<div class="fc"><div class="k4"><div><div class="k">Vendus</div><div class="v">142</div><div class="s">128 d’habitude à 17:43</div></div><div><div class="k">En vitrine</div><div class="v" style="color:var(--ko)">0</div><div class="s">vide depuis 17:10</div></div>
    <div><div class="k">Prix</div><div class="v">1,40 €</div><div class="s">TVA 6 %</div></div><div><div class="k">Coût matière</div><div class="v">0,31 €</div><div class="s">coefficient 4,5</div></div></div>
    <div class="lab">Vendus heure par heure<span>face aux 6 derniers mercredis</span></div><div class="hh" style="height:80px">${b}</div><div class="hh-ax">${H.map((_, i) => `<span>${7 + i}</span>`).join('')}</div>
    <div class="cd" style="margin-top:14px;background:var(--table-head)"><div class="ct"><b>Recuire 12 croissants</b><small>pour 18:00</small></div><div class="note" style="margin-top:4px">14 vendus après 17 h d’habitude le mercredi · 2 commandes demain matin n’en prennent pas</div></div>
    <button class="btn p">Noter 12 en cuisson</button></div>`;
  return sur(jour(false), fh('Croissant', 'Viennoiserie · short life · cuisson de 17:30') + corps);
}
function vEquipe() {
  const P = [['Léa', '06:30–14:00', '7,5 h', 612, 82, 'fini'], ['Inès', '07:00–15:30', '8,5 h', 734, 86, 'fini'], ['Tom', '11:00–19:00', '6,7 h', 498, 74, 'en poste'], ['Sarah', '14:00–19:30', '3,7 h', 280, 76, 'en poste']];
  const corps = `<div class="fc"><div class="k4"><div><div class="k">Au planning</div><div class="v">30,5 h</div><div class="s">26,4 h faites à 17:43</div></div><div><div class="k">Vendu à l’heure</div><div class="v">80 €</div><div class="s">le magasin, heures de vente</div></div></div>
    <div class="lab">Par vendu à l’heure<span>toucher : son heure par heure</span></div>
    ${P.sort((a, b) => b[4] - a[4]).map(p => li(`<span class="av" style="width:32px;height:32px;border-radius:50%;background:var(--chip);display:inline-flex;align-items:center;justify-content:center">${p[0][0]}</span>`, p[0], `${p[1]} · ${p[2]} · ${p[5]}`, fE(p[3]), ['', p[4] + ' €/h'])).join('')}
    <div class="note">Vendu : le vendeur sur chaque ticket. À l’heure : ses ventes ÷ ses heures où le magasin a vendu. Le coût de chacun reste au bureau.</div></div>`;
  return sur(jour(false), fh('L’équipe du jour', 'Atelier by - Halle · mer. 18 nov. · à 17:43') + corps);
}
function vStock() {
  const Z = [['Gobelets 33 cl', 'emballage · minimum 200', 'à zéro'], ['Sacs kraft M', 'emballage · minimum 150', 'à zéro'], ['Sucre en bûchettes', 'épicerie · minimum 500', 'à zéro']];
  const M = [['Lait entier 1 L', '4 pour un minimum de 12'], ['Beurre de tourage', '6 kg pour 10 kg'], ['Café en grains', '2 kg pour 4 kg']];
  const corps = `<div class="fc"><div class="ong"><span class="on">À zéro <small>3</small></span><span>Sous le minimum <small>5</small></span><span>Tout <small>212</small></span></div>
    ${Z.map(z => `<div class="li"><div class="r" style="grid-template-columns:12px minmax(0,1fr) auto 12px"><span class="d8 ko"></span><span class="q"><b>${z[0]}</b><small>${z[1]}</small></span><span class="m"><span class="chip ko">${z[2]}</span></span><span class="c">›</span></div></div>`).join('')}
    <div class="lab">Sous leur minimum<span>3 sur 5</span></div>
    ${M.map(z => `<div class="li"><div class="r" style="grid-template-columns:12px minmax(0,1fr) auto 12px"><span class="d8 wa"></span><span class="q"><b>${z[0]}</b><small>${z[1]}</small></span><span class="m"></span><span class="c">›</span></div></div>`).join('')}
    <div class="note">Compté le lun. 16/11 · la commande fournisseur part jeudi : ce qui est à zéro y est déjà proposé.</div>
    <button class="btn" style="margin-top:12px">Être prévenu quand une référence tombe à zéro</button></div>`;
  return sur(jour(false), fh('Stock du magasin', '212 références · compté le 16/11') + corps);
}
function vPoubelle() {
  const P = [['Pain au chocolat', 6, 1.60], ['Croissant', 4, 1.40], ['Pain Tradition', 2, 4.30], ['Sandwich club', 3, 5.90], ['Cookie chocolat', 3, 2.10]];
  const tot = P.reduce((a, p) => a + p[1] * p[2], 0);
  const corps = `<div class="fc"><div class="k4"><div><div class="k">Pièces</div><div class="v">18</div><div class="s">27 le mardi d’avant</div></div><div><div class="k">Au prix de vente</div><div class="v">${fE(tot)}</div><div class="s">2,4 % du chiffre</div></div></div>
    <div class="lab">Par produit<span>déclarée à 19:12 · Tom</span></div>
    <div class="bars">${P.map(p => `<div class="r" style="grid-template-columns:118px 1fr 64px"><span>${p[0]}</span><span class="tr"><i style="width:${(100 * p[1] / 6).toFixed(0)}%;background:#b9b2a8"></i></span><span class="n">${p[1]} · ${fE(p[1] * p[2])}</span></div>`).join('')}</div>
    <div class="note">Le pain au chocolat revient trois mardis de suite : la cuisson de 15 h peut descendre de 6.</div></div>`;
  return sur(chiffres(false), fh('La poubelle d’hier', 'mar. 17 nov. · déclarée à la fermeture') + corps);
}
function vBureau() {
  const corps = `<div class="fc"><div class="lab" style="margin-top:0">Ouverte<span>1</span></div>
    <div class="li"><div class="r" style="grid-template-columns:12px minmax(0,1fr) auto 12px"><span class="d8 wa"></span><span class="q"><b>Four : la porte ferme mal</b><small>envoyée lun. 16/11 · le bureau a répondu hier</small></span><span class="m"><span class="chip wa">en cours</span></span><span class="c">›</span></div></div>
    <div class="lab">Nouvelle demande</div><div class="chips"><span class="on">Matériel</span><span>Commande</span><span>Planning</span><span>Caisse</span><span>Autre</span></div>
    <div class="champ ta" style="margin-top:10px">Votre demande, en quelques mots…</div>
    <div class="vign" style="margin-top:10px"><div class="plus"><b>+</b>photo</div></div>
    <button class="btn p" style="margin-top:14px">Envoyer au bureau</button>
    <div class="note">La réponse arrive ici et dans la cloche du téléphone.</div></div>`;
  return sur(controle(false), fh('Une demande au bureau', 'Atelier by - Halle · réponse sous 24 h ouvrées') + corps);
}

/* ---------- les écrans pleins ---------- */
function vPhoto() {
  const im = photo('#8e8a83', forme.wc).replace('viewBox="0 0 180 104"', 'viewBox="0 0 180 240"').replace('height="104"', 'height="240"');
  return plein('noir', '17:43', `<div class="nr-hd"><div class="t"><b>Toilettes</b><small>12:30 · Tom · 4 sur 6</small></div><span class="x">✕</span></div>
    <div class="nr-im">${im}<span class="nav" style="left:10px">‹</span><span class="nav" style="right:10px">›</span><span class="chip ko" style="position:absolute;left:12px;top:12px">non conforme</span></div>
    <div class="nr-bas"><div class="ref"><i>${photo('#9b9790', forme.wc)}</i><span>La photo de référence : papier, savon, poubelle vidée, sol sec.</span></div>
    <div class="champ" style="margin-bottom:10px">Papier manquant, poubelle pleine</div>
    <div class="duo"><button class="btn v">Conforme</button><button class="btn r" style="background:var(--ko);border-color:var(--ko);color:#fff">Non conforme ✓</button></div></div>`);
}
function vReclamation() {
  const hd = `<div class="hd"><div class="l1"><span class="ic" style="font-size:18px">‹</span><div class="mag"><b>Réclamation fournisseur</b><small>Atelier by - Halle · depuis Contrôle</small></div></div></div>`;
  const corps = `<div class="cd"><div class="lab" style="margin-top:0">Fournisseur</div><div class="champ"><span>Fournisseur pain</span><span class="ch">▾</span></div>
    <div class="lab">Produit</div><div class="champ"><span>Pain Tradition · 4 céréales<small>2,15 € HT la pièce · livré ce matin</small></span><span class="ch">▾</span></div>
    <div class="lab">Quantité · motif</div><div class="duo"><div class="qte"><b>−</b><span>6</span><b>+</b></div><div class="champ" style="justify-content:center">= 12,90 € HT</div></div>
    <div class="chips" style="margin-top:8px"><span class="on">Abîmé</span><span>Manquant</span><span>Qualité</span><span>Date</span><span>Autre</span></div>
    <div class="lab">Photos</div><div class="vign"><div>${photo('#8a6a48', forme.pain)}</div><div>${photo('#7a5c40', forme.pain)}</div><div class="plus"><b>+</b>photo</div></div>
    <button class="btn p" style="margin-top:14px">Envoyer la réclamation · 12,90 €</button></div>
    ${carte('Les dernières', '2 ce mois', `<div class="ls"><div class="r"><span class="d8 ok"></span><span class="t">Croissants surgelés · 20 · manquant<small>10/11 · avoir reçu le 13/11</small></span><span class="n">16,40 €</span></div><div class="r"><span class="d8 wa"></span><span class="t">Beurre · 2 kg · date<small>03/11 · en attente du fournisseur</small></span><span class="n">18,60 €</span></div></div>`)}`;
  return `<div class="tel">${ios()}${hd}<div class="sc">${corps}</div>${onglets('ctrl')}</div>`;
}
function vRevue() {
  const corps = `<div class="hd"><div class="l1"><div class="mag"><b>La revue du soir</b><small>mer. 18 nov. · par Tom</small></div><span class="x">✕</span></div>
    <div class="rv-p"><i style="width:33%"></i></div><div class="note" style="margin:0;text-align:right">2 / 6</div></div>
    <div class="sc"><div class="ctx"><span class="d8 wa"></span>Après-midi <span class="mu">· 3 / 6 · 14:00–18:00</span></div>
    <div class="bul"><b style="font-weight:400">3 tâches</b> de « Après-midi » ne sont pas cochées. Pourquoi ?<ul><li>Nettoyer la trancheuse</li><li>Préparer la commande de jeudi</li><li>Vitrine 17 h en photo</li></ul></div>
    <div class="bul moi">Pas eu le temps</div>
    <div class="chips" style="margin-top:6px"><span>Faites, pas cochées</span><span class="on">Pas eu le temps</span><span>Personne au poste</span><span>Matériel en panne</span><span>Autre…</span></div>
    <div class="note" style="margin-top:12px;color:var(--color-primary)">+ commentaire</div></div>
    <div style="padding:10px 14px 4px" class="duo"><button class="btn">‹ Précédent</button><button class="btn p">Suivant ›</button></div>`;
  return plein('', '18:52', corps);
}

/* ---------- l'affichage ---------- */
const E = { jour: () => jour(false), 'jour-long': () => jour(true), chiffres: () => chiffres(false), 'chiffres-long': () => chiffres(true),
  controle: () => controle(false), 'controle-long': () => controle(true), taches: () => taches(false), 'taches-long': () => taches(true),
  semaine: () => semaine(false), 'semaine-long': () => semaine(true), primes: () => primes(false), 'primes-long': () => primes(true),
  cmd: vCmd, b2b: vB2B, produit: vProduit, equipe: vEquipe, stock: vStock, poubelle: vPoubelle, bureau: vBureau, photo: vPhoto, reclamation: vReclamation, revue: vRevue };
const ONG_LEG = { jour: ['Jour · ex-Opérationnel', 'Un seul chiffre en tête : le magasin entier face à l’objectif du jour. La barre se colore par canal, mercredi dernier dessous ; quatre cases : comptoir, commandes, webshop, B2B. Puis les mercredis, les heures, la vitrine, l’équipe.'],
  chiffres: ['Chiffres · ex-Exploitation', 'Le résultat du jour en tête, le compte en escalier dessous : chaque charge avec sa pastille face à son seuil. Puis le rang, les canaux, les catégories, les promotions.'],
  controle: ['Contrôle', 'Combien de photos sont rendues, ce qui cloche, puis les photos en grille de deux, chacune avec son verdict. La réclamation et la demande au bureau en bas.'],
  taches: ['Tâches', 'Le compte du jour et ce qui bloque. Une carte par checklist : sa barre, et seulement les tâches pas faites.'],
  semaine: ['Semaine', 'L’avance ou le retard sur l’objectif à ce jour, sept colonnes face à leur objectif, le damier jour par jour, quatre tuiles.'],
  primes: ['Primes', 'Le montant acquis du mois, une ligne par personne avec ses primes en pastilles, les règles du mois dessous.'] };
const VUE_LEG = { cmd: ['Commandes du jour', 'Depuis la ligne du chiffre du jour. Le CA en commandes rapproché de la caisse, puis comptoir et webshop, et demain.'],
  b2b: ['Ventes B2B', 'Depuis la case B2B. Quatre chiffres, chaque ticket pro et ses articles, les habitudes du mercredi.'],
  produit: ['Un produit de la vitrine', 'Depuis « Croissant · recuire 12 ». Vendu, en vitrine, prix, coût ; l’heure par heure ; la recuisson proposée.'],
  equipe: ['L’équipe du jour', 'Depuis « La journée ». Une ligne par personne, rangée par vendu à l’heure ; le coût reste au bureau.'],
  stock: ['Stock', 'Depuis le bas de Jour. Ce qui est à zéro, puis sous le minimum ; l’alerte sur le téléphone.'],
  photo: ['Une photo de contrôle', 'Depuis une vignette de Contrôle. La photo plein écran, la référence, le commentaire, le verdict au pouce.'],
  reclamation: ['Réclamation fournisseur', 'Depuis Contrôle. Fournisseur, produit, quantité, motif, photos ; les dernières dessous.'],
  bureau: ['Une demande au bureau', 'Depuis Contrôle. La demande ouverte, puis une nouvelle : le sujet, le texte, une photo.'],
  revue: ['La revue du soir', 'Depuis la planchette de l’en-tête. Une question à la fois, la réponse en un appui.'],
  poubelle: ['La poubelle d’hier', 'Depuis Chiffres. Les pièces jetées par produit et ce qu’elles disent de la cuisson.'] };
const v = new URLSearchParams(location.search).get('v') || 'jour';
const el = document.getElementById('ecran');
const fig = (k, L) => `<figure><figcaption><b>${L[k][0]}</b>${L[k][1]}</figcaption>${E[k]()}</figure>`;
if (v === 'planche' || v === 'planche-vues') {
  document.body.style.background = '#ece6dd';
  const vues = v === 'planche-vues', L = vues ? VUE_LEG : ONG_LEG;
  el.innerHTML = `<div class="planche-c"><div class="planche-w"><h1>${vues ? 'Le dashboard au téléphone — les vues qui s’ouvrent depuis les onglets' : 'Le dashboard magasin au téléphone — refonte des six onglets'}</h1><p>Atelier by - Halle · mercredi 18 novembre 2026 à 17:43 · ${vues ? 'feuilles du bas, écrans pleins, même grammaire que les onglets' : 'un en-tête, un héros, des cartes, la même barre d’onglets partout'}</p></div>
    <div class="planche${vues ? ' wrap' : ''}">${Object.keys(L).map(k => fig(k, L)).join('')}</div></div>`;
} else { el.innerHTML = E[v](); }
