/* Vidéo 12 — « Promotions : un bundle qui monte la marge » (mode d'emploi de Vendre › Promotions : où les promotions
 * sont utilisées, puis les bundles du Coach Vente, bundles.js). Environ 2 min 55. Données fictives : mocks/12_promotions.js.
 * Le lancement n'est pas filmé : la vidéo s'arrête à la fiche pré-encodée, avant « Valider et lancer ». */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-promotions', { vue: 'promos', serie: 'Mode d’emploi', titreSerie: 'Promotions : un bundle qui monte la marge', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Promotions :<br>un bundle qui<br>monte la marge', accroche: 'Le Coach Vente prépare, vous validez',
    programme: ['Où les promotions sont utilisées', 'Le constat : votre marge de base', 'Les couples et leur prix', 'La fiche, prête à lancer'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Où les promotions sont utilisées', texte: 'Les promotions reconnues dans les tickets : au comptoir, en click & collect, en livraison.' });
  await f.etape('1 · Les promotions');
  await f.montrer('.pm-lecture', 'Le principe');
  await f.dire('Le Coach Vente prépare vos bundles avec vos prix et vos coûts : vous validez, la promotion passe en caisse <b>le lundi qui suit</b>.', 6000);
  await f.montrer('.cn-card', 'Où les promotions sont utilisées');
  await f.dire('Chaque promotion reconnue dans les tickets, par canal : comptoir, click & collect, livraison. Et jour par jour, à droite.', 5800);
  await f.cacher(150);
  await f.taire(200);
  await f.cliquer('[data-pmcanm="r"]', { apres: 1300 });
  await f.dire('<b>En remise €</b> : ce que chaque promotion a rendu aux clients, sur 7 ou 30 jours.', 4400);
  await f.montrerTout('.cn-card .cn-r:not(.cn-th) .cn-rc', 'Coef. du lot');
  await f.dire('Le <b>coefficient du lot</b> : prix payé hors TVA ÷ coût matière, au plus serré. ×2 est un repère : en dessous, il passe en rouge.', 6400);
  await f.cacher();
  await f.cliquer('[data-pmcanm="n"]', { apres: 800 });

  await f.chapitre({ n: 2, total: 4, titre: 'Le constat : votre marge de base', texte: 'Le prix hors TVA moins le coût de recette, pondéré par ce que vous avez vendu en 30 jours.' });
  await f.etape('2 · Le constat');
  await f.montrer('.bd-k4', 'Le constat');
  await f.dire('Le <b>Coach Vente</b> part de votre <b>marge de base</b> : prix hors TVA moins coût de recette, sur les ventes des 30 derniers jours.', 5800);
  await f.montrer('.bd-c1 > div:first-child', 'Chaque produit');
  await f.dire('Chaque produit : sa marge en hauteur, ses ventes en largeur. Le pointillé : votre marge de base.', 5000);
  await f.montrer('.bd-listes', 'Les deux listes');
  await f.dire('En rouge, <b>ils font le volume, pas la marge</b>. En bleu, 78 % de marge et plus, mais peu vendus.', 5200);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Les couples et leur prix', texte: 'Un produit qui attire, plus un produit à grande valeur ajoutée, d’une autre famille, au même moment de la journée.' });
  await f.etape('3 · Les couples');
  await f.cliquer('.bd-act [data-bdphase="2"]', { apres: 1600 });
  await f.montrer('.bd-tab', 'Les couples');
  await f.dire('Chaque couple : les deux séparés, puis le <b>prix conseillé, −10 %</b>, la marge du ticket, la marge en plus.', 5600);
  await f.montrer('.bd-bilan', 'Par mois');
  await f.dire('Par mois : si un client sur dix qui prend le produit qui attire prend le bundle. Une hypothèse, réglable.', 5400);
  await f.cacher(150);
  await f.taire(200);
  await f.cliquer('.bd-tab [data-bdsel="1"]', { apres: 1000 });
  await f.dire('On choisit le couple à lancer : ici, <b>éclair au chocolat et cappuccino</b>.', 3800);
  await f.cliquer('.bd-act [data-bdphase="3"]', { apres: 1600 });
  await f.montrer('.bd-c3 > div:first-child', 'Le prix');
  await f.dire('La marge que laisse le bundle selon son prix. La remise conseillée : <b>de 8 à 12 %</b> ; au-delà de 15 %, trop de remise.', 6000);
  await f.cacher(150);
  await f.taire(200);
  await f.cliquer('[data-bdprix="5.7"]', { apres: 1200 });
  await f.montrer('.bd-choix', 'À 5,70 €');
  await f.dire('Un clic sur une colonne change le prix : la remise, la marge du bundle, la <b>marge en plus</b>.', 5000);
  await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'La fiche, prête à lancer', texte: 'Le Coach Vente l’a pré-encodée avec vos prix et vos coûts : vous vérifiez le nom, les canaux et la durée.' });
  await f.etape('4 · La fiche');
  await f.cliquer('.bd-act [data-bdphase="4"]', { apres: 1600 });
  await f.montrer('.bd-fiche', 'La fiche');
  await f.dire('La fiche est déjà encodée : le nom, ce que le client achète, ce qu’il paie, <b>où</b> : au comptoir, sur le webshop, ou les deux.', 5600);
  await f.montrer('.bd-fiche .bd-l4:nth-child(5)', 'Quand');
  await f.dire('<b>Quand</b> : toujours à partir du lundi qui suit la validation ; un mois par défaut.', 4400);
  await f.montrer('.bd-cote', 'Ce qu’elle vous laisse');
  await f.dire('À droite : la marge du bundle, son coefficient, et combien de bundles il faut pour garder votre marge.', 5200);
  await f.montrer('.bd-envoi', 'À la validation');
  await f.dire('À la validation, la promotion est créée au panel, et sa fiche part par e-mail à l’équipe, depuis la boîte du magasin.', 6000);
  await f.montrer('.bd-act [data-bd="lancer"]', 'Valider et lancer');
  await f.dire('Il ne reste qu’à <b>valider</b>. Ensuite, le bundle se suit tout en bas : son statut, sa période, la fiche envoyée.', 5600);
  // « Lancés avec le Coach Vente » est au pied de la page : le sous-titre le couvrirait, l'étiquette du cadre suffit.
  await f.taire(200);
  await f.montrer('.bd-suivi', 'Lancés avec le Coach Vente', 2600);
  await f.cacher();

  return f.fin({
    titre: 'À vous de monter<br>votre marge', accroche: 'Un bundle bien choisi',
    retenir: ['Le coefficient du lot : ×2 est un repère', 'Un produit qui attire + un produit qui rapporte', 'Une remise conseillée de 8 à 12 %', 'En caisse le lundi qui suit, la fiche à l’équipe'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
