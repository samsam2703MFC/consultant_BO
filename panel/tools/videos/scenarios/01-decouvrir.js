/* Vidéo 01 — « Découvrir le dashboard » (mode d'emploi, la bulle d'Opérationnel). Environ 2 min. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('01-decouvrir', { vue: '', bulle: true, serie: 'Mode d’emploi', titreSerie: 'Découvrir le dashboard', duree: 'env. 2 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Découvrir<br>le dashboard', accroche: 'Votre magasin, d’un coup d’œil',
    programme: ['Le rail', 'La barre du haut', 'La journée en cours', 'Les chiffres de la semaine et du mois'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Le rail', texte: 'À gauche, toutes les pages du dashboard, rangées par moment de la journée.' });
  await f.etape('1 · Le rail');
  await f.montrer('#db-rail .sidebar__groups', 'Le rail');
  await f.dire('Le <b>rail</b> ouvre chaque page : la journée, les chiffres, la vente, le marketing…', 4200);
  await f.montrer('#db-rail .db-rail-rep', 'En direct');
  await f.dire('En tête du rail, en direct : l’<b>objectif du jour</b> et la <b>note Google</b> du magasin.', 4200);
  await f.montrer('#db-rail .sidebar__item[data-vue=boite]', 'Boîte mail');
  await f.dire('La <b>boîte mail</b> du magasin et l’<b>assistant IA</b> sont tout en haut.', 3600);
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'La barre du haut', texte: 'Les raccourcis de tous les jours, sur chaque page.' });
  await f.etape('2 · La barre du haut');
  await f.montrer('#dash .db-avs', 'Les raccourcis');
  await f.dire('La barre du haut : le dossier de formation, relire, la valeur du magasin, une demande au bureau, la revue du soir.', 5400);
  await f.montrer('#dash .db-avs [data-recharger]', 'Relire');
  await f.dire('<b>Relire</b> remet les chiffres à jour ; l’heure de la dernière lecture est écrite dessous.', 4000);
  await f.montrer('#dash .db-avs [data-iaouvrir="d"]', 'Demande au bureau');
  await f.dire('Une question pour le siège ? <b>Demande au bureau</b> : on la pose et on suit la réponse ici.', 4200);
  await f.montrer('#dash .db-avs [data-rvouvrir]', 'Revue du soir');
  await f.dire('Le soir, la <b>revue du soir</b> fait le point de la journée en quelques écrans.', 3800);
  await f.cacher();
  await f.montrer('#dash .db-frise', 'La semaine');
  await f.dire('La <b>frise</b> : chaque jour de la semaine avec son chiffre ; un clic ouvre le jour.', 4000);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'La journée en cours', texte: 'La page Opérationnel : le chiffre, l’objectif, les heures, la vitrine.' });
  await f.etape('3 · La journée');
  await f.montrer('#dash .db-bench', 'Ta place dans le réseau');
  await f.dire('Ta place dans le réseau : le magasin face aux autres, chiffre d’affaires, clients, panier, marge.', 4600);
  await f.montrer('#dash .op-duel:not(.b2b)', 'L’objectif du jour');
  await f.dire('L’<b>objectif du jour</b> : en rouge aujourd’hui, en bleu le même jour la semaine passée, en hachuré la projection de fin de journée.', 6000);
  await f.montrer('#dash .db-card:has-text("Heure par heure")', 'Heure par heure');
  await f.dire('<b>Heure par heure</b> : où le chiffre se fait, et les heures creuses.', 3600);
  await f.montrer('#dash .db-card:has-text("Le P&L court")', 'Le P&L court');
  await f.dire('Le <b>P&L court</b> de la journée : ventes, matière, personnel, résultat.', 3800);
  await f.montrer('#dash .db-card:has-text("Ventes par catégorie")', 'Les catégories');
  await f.dire('Les <b>ventes par catégorie</b> ; un clic sur une catégorie ouvre ses produits.', 3800);
  await f.montrer('#dash .db-card.vt', 'La vitrine');
  await f.dire('La <b>vitrine</b> : ce qui risque de manquer avant la fermeture.', 3600);
  await f.cacher();
  if (await f.p.locator('.tu-bulle:not([hidden])').count()) {
    await f.montrer('.tu-bulle', 'Les vidéos');
    await f.dire('En bas à droite, une <b>bulle</b> ouvre la vidéo de la page ; « Nouveau » y annonce une nouvelle fonction.', 4800);
    await f.cacher();
  }

  await f.chapitre({ n: 4, total: 4, titre: 'Les chiffres', texte: 'La semaine et le mois : le chiffre, l’objectif, la rentabilité.' });
  await f.etape('4 · Les chiffres');
  await f.cliquer('#db-rail .sidebar__item[data-vue=semaine]', { apres: 2000 });
  await f.dire('<b>Semaine</b> : la semaine jour par jour face à l’objectif ; en haut, les semaines d’avant.', 4200);
  await f.defiler(520);
  await f.dire('Plus bas : du chiffre d’affaires au <b>résultat</b>, et les heures d’une journée type.', 3400);
  await f.defiler(-2000);
  await f.cliquer('#db-rail .sidebar__item[data-vue=mois]', { apres: 2000 });
  await f.dire('<b>Mois</b> : le mois jour par jour face à l’objectif ; en haut, les mois de l’année.', 4000);
  await f.defiler(520);
  await f.pause(1400);
  await f.defiler(-2000);
  await f.cacher();
  return f.fin({
    titre: 'À vous de jouer', accroche: 'Bonne journée en magasin',
    retenir: ['Le rail ouvre chaque page', 'La barre du haut : les raccourcis', 'Opérationnel : la journée en direct', 'Semaine et Mois : la tendance'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
