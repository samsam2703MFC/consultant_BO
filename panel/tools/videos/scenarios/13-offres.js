/* Vidéo 13 — « Les offres de prix » (mode d'emploi de Vendre › Offres, ?vue=offres). Environ 2 min 50.
 * Données fictives : mocks/13_offres.js (huit offres du Magasin démo, clients inventés). Le récit : on lit ses offres,
 * on ouvre l'offre n° 418 et sa marge, on prépare le devis d'une cliente (n° 419), on le convertit en commande, puis
 * l'onglet Newsletter. Chaque phrase vient de panel/public/dashboard/offres.js, de panel/src/offres_client.php ou de
 * panel/src/LISEZMOI.md. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-offres', { vue: 'offres', serie: 'Mode d’emploi', titreSerie: 'Les offres de prix', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Les offres<br>de prix', accroche: 'Du devis à la commande',
    programme: ['Vos offres d’un coup d’œil', 'Une offre et sa marge', 'Faire une nouvelle offre', 'Convertir, et la newsletter'],
  });

  /* 1 — la liste */
  await f.chapitre({ n: 1, total: 4, titre: 'Vos offres d’un coup d’œil', texte: 'Les offres de prix du magasin, telles que TF Buddy les garde.' });
  await f.etape('1 · Vos offres');
  await f.montrer('.of-onglets .page-tabs', 'Les onglets');
  await f.dire('Les offres se rangent par état : <b>en cours</b>, <b>converties</b> en commande, <b>archivées</b>, et toutes.', 4600);
  await f.montrer('.of-kpis', 'Les chiffres');
  await f.dire('En tête : les offres en cours et converties, leur montant, le <b>taux de conversion</b>, le prochain événement.', 4800);
  await f.montrer('.of-g', 'Mes offres');
  await f.dire('La liste : l’offre et son client, le jour de l’événement, le total TTC, l’état. Au-dessus, la recherche.', 5200);
  await f.taire(150); await f.cacher(500);

  /* 2 — une offre et sa marge */
  await f.chapitre({ n: 2, total: 4, titre: 'Une offre et sa marge', texte: 'Un clic l’ouvre à droite : ses gestes, sa marge, son aperçu.' });
  await f.etape('2 · Une offre');
  await f.cliquer('[data-ofsel="418"]', { apres: 700 });
  await f.montrer('.of-gestes', 'Les gestes');
  await f.dire('Ses gestes : <b>modifier</b>, le <b>PDF</b> de TF Buddy (FR, NL, EN), l’envoyer par e-mail, la <b>convertir en commande</b>, l’archiver ou la supprimer.', 6200);
  await f.montrer('.of-d .of-marge-k', 'La marge de l’offre');
  await f.dire('La <b>marge de l’offre</b> : ce que le client paie hors TVA, remises déduites, moins le coût matière des recettes.', 5200);
  await f.montrer('.of-d .of-marge-h', 'La couleur');
  await f.dire('Les couleurs de Recette & marge : vert à <b>60 %</b> de marge brute et plus, orange entre 40 et 60 %, rouge en dessous.', 5600);
  await f.cacher(450);
  await f.montrer('.of-d .of-marge-o', 'L’objectif du réseau');
  await f.dire('Dessous : l’objectif du réseau pour la matière, ce qu’il faudrait facturer pour l’atteindre, puis le détail ligne par ligne.', 5400);
  await f.cacher(450);
  await f.montrer('.of-apercu', 'L’aperçu');
  await f.dire('Plus bas, l’<b>aperçu</b> au gabarit A4. Le document officiel reste le PDF que TF Buddy rend.', 4400);
  await f.taire(150); await f.cacher(450);
  await f.defiler(-3000);

  /* 3 — une nouvelle offre */
  await f.chapitre({ n: 3, total: 4, titre: 'Faire une nouvelle offre', texte: 'On prépare le devis ici ; il se crée dans TF Buddy.' });
  await f.etape('3 · Une nouvelle offre');
  await f.cliquer('[data-ofnouvelle]', { apres: 900 });
  await f.cliquer('[data-off=nom]', { apres: 250 });
  await f.taper('Anniversaire 28/11', 55);
  await f.cliquer('#of-cli-q', { apres: 250 });
  await f.taper('Renard', 110);
  await f.pause(900);
  await f.montrer('.of-client', 'Le client');
  await f.dire('Le client se cherche dans TF Buddy, particulier ou B2B. Sans client, TF Buddy ne rend pas de PDF.', 4600);
  await f.cacher(150);
  await f.taire(150);
  await f.cliquer('[data-ofcli="7104"]', { apres: 700 });
  await f.survoler('[data-off=jour]', { pause: 300 });
  await f.p.locator('[data-off=jour]').fill('2026-11-28');
  await f.pause(500);
  await f.cliquer('[data-ofajout]', { apres: 400 });
  await f.taper('Éclair au chocolat', 50);
  await f.p.keyboard.press('Tab'); await f.pause(600);
  await f.cliquer('[data-ofl="0"][data-ofc="qte"]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('24', 120);
  await f.p.keyboard.press('Tab'); await f.pause(600);
  await f.cliquer('[data-ofajout]', { apres: 400 });
  await f.taper('Croissant au beurre', 50);
  await f.p.keyboard.press('Tab'); await f.pause(600);
  await f.cliquer('[data-ofl="1"][data-ofc="qte"]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('30', 120);
  await f.p.keyboard.press('Tab'); await f.pause(700);
  await f.montrer('.of-lignes', 'Les produits');
  await f.dire('Chaque produit vient du <b>catalogue du magasin</b>, à son prix TTC.', 3400);
  await f.cacher(450);
  await f.montrer('.of-f .of-marge', 'La marge de l’offre');
  await f.dire('La marge se calcule pendant qu’on écrit l’offre, ligne par ligne et pour le tout.', 4000);
  await f.cacher(150);
  await f.taire(150);
  await f.cliquer('[data-ofenregistrer]', { apres: 1500 });
  await f.montrer('.of-ok', 'Créée');
  await f.dire('<b>Créer l’offre dans TF Buddy</b> : elle y est, en cours, avec son numéro.', 3800);
  await f.cacher(300);

  /* 4 — convertir, et la newsletter */
  await f.chapitre({ n: 4, total: 4, titre: 'Convertir, et la newsletter', texte: 'La cliente dit oui : l’offre devient une commande.' });
  await f.etape('4 · Convertir');
  await f.cliquer('[data-ofgeste=convertir]', { apres: 900 });
  await f.montrer('.of-panneau', 'Convertir en commande');
  await f.dire('On donne l’heure du retrait, ou du départ de la livraison, et qui prépare la commande.', 4400);
  await f.cacher(150);
  await f.taire(150);
  await f.survoler('#of-conv-emp', { pause: 400 });
  await f.p.locator('#of-conv-emp').selectOption('205');
  await f.pause(700);
  await f.cliquer('[data-ofconvertir]', { apres: 1500 });
  await f.montrer('.of-ok', 'Convertie');
  await f.dire('La commande se crée dans TF Buddy ; l’offre passe en <b>convertie</b> et ne se modifie plus.', 4600);
  await f.cacher(150);
  await f.taire(150);
  await f.cliquer('[data-ofong=news]', { apres: 1500 });
  await f.montrer('.of-nl', 'Newsletter');
  await f.dire('Dernier onglet, la <b>Newsletter</b> : les campagnes du magasin à ses clients, par e-mail ou par SMS.', 4600);
  await f.montrer(f.p.getByText('Mode test — aucun envoi ne part', { exact: false }).first(), 'Mode test');
  await f.dire('Tant que la marque n’envoie pas : <b>mode test</b>, aucun envoi ne part.', 3400);
  await f.cacher();

  return f.fin({
    titre: 'À vous de faire<br>vos offres', accroche: 'Du devis à la commande',
    retenir: ['Les offres vivent dans TF Buddy', 'Chaque offre dit sa marge', 'Un client, sinon pas de PDF', 'Convertie, l’offre devient commande'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
