/* Vidéo 11 — « Produits : est-ce que ça se développe ? » (mode d'emploi de Vendre › Produits et de la fiche d'un produit).
 * Environ 2 min 40. Données fictives : mocks/11_produits.js (13 semaines, S35 → S47) ; la fiche : mocks/lib/fiche.js.
 * L'écriture du prix s'arrête à son aperçu (« Annuler »). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('11-produits', { vue: 'produits', serie: 'Mode d’emploi', titreSerie: 'Produits : est-ce que ça se développe ?', duree: 'env. 2 min 40' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Produits :<br>est-ce que ça<br>se développe ?', accroche: 'Semaine après semaine',
    programme: ['Le magasin, semaine par semaine', 'Vos catégories en tuiles', 'Les produits d’une catégorie', 'La fiche d’un produit et son prix'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Le magasin, semaine par semaine', texte: 'Une seule comparaison, en chiffre d’affaires : les 6 dernières semaines closes face aux 6 d’avant.' });
  await f.etape('1 · Le magasin');
  await f.montrer('.pd-quand', 'La comparaison');
  await f.dire('Une seule comparaison : les <b>6 dernières semaines closes</b>, face aux 6 d’avant.', 4200);
  await f.montrer('.pd-rep', 'Le verdict');
  await f.dire('Le verdict en grand : le magasin se développe, recule, ou, comme ici, <b>est stable</b>.', 4200);
  await f.montrer('.pd-c .pd-gr', 'Chaque semaine');
  await f.dire('Le chiffre de chaque semaine : les 6 d’avant en clair, les 6 dernières en foncé, la semaine en cours hachurée.', 5400);
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'Vos catégories en tuiles', texte: 'Une tuile par catégorie : verte si elle monte, ocre si elle baisse.' });
  await f.etape('2 · Les catégories');
  await f.montrer('.pd-tuiles', 'Vos catégories');
  await f.dire('Une tuile par catégorie : sa flèche, son chiffre par semaine, ses 13 semaines en petit.', 4400);
  await f.montrer('.pd-ti.up', 'Elle monte');
  await f.dire('<b>Verte</b> : plus de 8 % de chiffre sur les 6 dernières semaines, face aux 6 d’avant.', 4600);
  await f.montrer('.pd-ti.dn', 'Elle baisse');
  await f.dire('<b>Ocre</b> : 8 % de moins. Entre les deux, le signe = : stable.', 4000);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Les produits d’une catégorie', texte: 'Un clic ouvre la catégorie : ses semaines, ses produits, et pour chacun son prix, sa quantité, sa marge.' });
  await f.etape('3 · Une catégorie');
  await f.cliquer('[data-pdg="Snacking"]', { apres: 1500 });
  await f.montrer('#db-pd .pd-mrep', 'Snacking');
  await f.dire('La fenêtre de la catégorie : sa flèche, et son chiffre semaine par semaine.', 4000);
  await f.montrer('#db-pd .pd-prods', 'Ses produits');
  await f.dire('Ses produits : le prix, les pièces par semaine, l’évolution, et leurs 12 semaines en petit.', 4600);
  await f.montrer('#db-pd .pd-prods .pd-mot.up', 'Nouveau');
  await f.dire('Un produit qui démarre est marqué <b>nouveau</b>, avec la semaine de son départ.', 3800);
  await f.cacher(150);
  await f.cliquer('#db-pd [data-pdp="123"]', { apres: 1300 });
  await f.montrer('#db-pd .pd-ddb', 'Sandwich poulet crudités');
  await f.dire('Un clic sur un produit le déplie : <b>prix de vente</b>, quantité par semaine avant et maintenant, <b>marge</b>.', 5200);
  await f.montrer('#db-pd .pd-fiche', 'Fiche complète');
  await f.dire('Et sa <b>fiche complète</b>.', 2400);
  await f.cacher(150);
  await f.cliquer('#db-pd .pd-fiche', { apres: 1400 });

  await f.chapitre({ n: 4, total: 4, titre: 'La fiche d’un produit et son prix', texte: 'Ses ventes face au réseau, son prix face aux autres magasins, sa recette, et le prix de vente à adapter.' });
  await f.etape('4 · La fiche');
  await f.montrer('#db-fiche .fi-ong', 'Trois onglets');
  await f.dire('Trois onglets : les ventes sur 12 semaines face au réseau, le prix face au réseau, la recette et la marge.', 5200);
  await f.cacher(150);
  await f.taire(200);
  await f.cliquer('#db-fiche [data-fong="2"]', { apres: 1500 });
  await f.dire('<b>Prix face au réseau</b> : votre prix encaissé et votre volume, parmi les autres magasins.', 4400);
  await f.cliquer('#db-fiche [data-fong="3"]', { apres: 1500 });
  await f.montrer('#db-fiche .fi-rjauge', 'Le coefficient');
  await f.dire('<b>Recette & marge</b> : le coefficient, le prix hors TVA divisé par le coût de la recette.', 4600);
  await f.dire('Sous × 1,67, la marge passe sous 40 % ; au-dessus de × 2,5, elle dépasse 60 % : <b>dans le vert</b>.', 5400);
  await f.montrer('#db-fiche .fi-rverdict', 'Le verdict');
  await f.dire('Ce sandwich est <b>à surveiller</b> : le verdict dit le prix qu’il faudrait pour entrer dans le vert.', 5000);
  await f.cacher(150);
  await f.taire(200);
  await f.cliquer('#db-fiche [data-fsiminput]', { apres: 200 });
  await f.p.keyboard.press('Control+A');
  await f.taper('5,85', 140);
  await f.p.keyboard.press('Enter');
  await f.pause(900);
  await f.montrer('#db-fiche .fi-rjauge', 'À 5,85 €');
  await f.dire('On tape un autre prix hors TVA : le coefficient, la marge et le verdict se recalculent.', 4600);
  await f.montrer('#db-fiche .fi-radapt', 'Adapter mon prix');
  await f.dire('<b>Adapter mon prix de vente</b> enregistre ce prix comme prix de vente du magasin, dans l’ERP.', 4600);
  await f.cacher(150);
  await f.cliquer('#db-fiche [data-fadapt="lire"]', { apres: 1500 });
  await f.montrer('#db-fiche .fi-radapt.conf', 'L’aperçu');
  await f.dire('D’abord un aperçu : le prix en caisse, TVA comprise, et le <b>maximum du réseau</b>. Rien ne change avant « Confirmer ce prix ».', 6200);
  await f.dire('Au-dessus du maximum du réseau, rien ne s’écrit : un bouton envoie au réseau la demande de le relever.', 5200);
  await f.cacher(150);
  await f.cliquer('#db-fiche [data-fadapt="annuler"]', { apres: 1000 });

  return f.fin({
    titre: 'À vous de suivre<br>vos produits', accroche: 'Ce qui monte, ce qui baisse',
    retenir: ['6 semaines face aux 6 d’avant', 'Une tuile par catégorie : verte, ocre ou stable', 'Un produit déplié : prix, quantité, marge', 'Recette & marge : tester un prix, puis l’adapter'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
