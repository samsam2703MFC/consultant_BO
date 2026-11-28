/* Vidéo 07 — « La page Mois » (mode d'emploi, la page Mois du rail, section « Les chiffres »). Environ 3 min.
 * Ce qui est dit vient de dashboard.js (la frise des mois, qbChiffre, qbJours, cascade, rendSemaines, qbPlus, rendRentab)
 * et de consultant/public/assets/js/cartes-magasin.js (les six leviers). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('07-mois', { vue: 'mois', serie: 'Mode d’emploi', titreSerie: 'La page Mois', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi · Les chiffres', titre: 'La page<br>Mois', accroche: 'Le mois, et ce qui le fait',
    programme: ['Choisir son mois', 'Le chiffre et les jours', 'Le résultat et la tendance', 'Les six leviers'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Choisir son mois', texte: 'En haut, les douze mois de l’année : un clic en ouvre un.' });
  await f.etape('1 · Choisir son mois');
  await f.montrer('#dash .db-frise', 'Les mois');
  await f.dire('Les <b>douze mois</b> de l’année : le chiffre de chacun et sa jauge vers l’objectif.', 4400);
  await f.dire('Le mois en cours est marqué ; un mois à venir ne montre que son objectif.', 4000);
  await f.montrerTout('#dash .db-frise .fr-an', 'L’année');
  await f.dire('Les flèches, à côté de l’année, changent d’année.', 3200);
  await f.taire(200);
  await f.cacher(200);
  await f.cliquer('#dash .db-frise .fr-c[data-fdate="2026-10-31"]', { apres: 1900 });
  await f.montrer('#dash .chr-l1', 'Octobre, clos');
  await f.dire('Un clic ouvre le mois : <b>octobre</b>, clos, se lit face à son objectif.', 4200);
  await f.montrer('#dash [data-auj]', 'Revenir');
  await f.dire('« <b>Ce mois-ci</b> » ramène au mois en cours.', 3000);
  await f.cliquer('#dash [data-auj]', { apres: 1900 });
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'Le chiffre et les jours', texte: 'Où en est le mois face à ce qui était attendu, et chaque jour face à son objectif.' });
  await f.etape('2 · Le chiffre et les jours');
  await f.montrer('#dash .chr-l1', 'Le chiffre du mois');
  await f.dire('Le mois en cours se lit face à l’<b>attendu à ce jour</b>, le trait sur la jauge, et face à l’objectif du mois.', 5200);
  await f.montrer('#dash .chr-lev', 'Reste à faire');
  await f.dire('À droite : ce qu’il <b>reste à faire</b>, par jour restant ; et au <b>rythme actuel</b>, la fin du mois.', 4400);
  await f.montrer('#dash .chr-l2', 'À corriger');
  await f.dire('Dessous, « <b>À corriger</b> » : les coûts au-dessus de leur seuil, les heures sous 20 % de marge nette. Un clic mène à la ligne.', 5800);
  await f.montrer('#dash .qb-jours-c', 'Les jours');
  await f.dire('<b>Les jours</b> du mois : la barre du chiffre, le trait de l’objectif du jour, la couleur de l’atteinte.', 4800);
  await f.dire('En haut à droite : le jour le plus haut et le plus bas face à leur objectif.', 3800);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Le résultat et la tendance', texte: 'Le P&L du mois hors TVA, les heures, et les douze derniers mois face à l’an passé.' });
  await f.etape('3 · Le résultat et la tendance');
  await f.montrer('#dash .qb-res', 'Le résultat');
  await f.dire('Le <b>résultat</b> du mois : du chiffre encaissé au résultat hors TVA, chaque coût face à son seuil.', 4800);
  await f.montrer('#dash .qb-pile', 'Les heures');
  await f.dire('<b>Les heures</b> d’une journée type du mois : ce que chaque heure vend et rapporte, en moyenne par jour ouvert.', 5000);
  await f.montrer('#dash .qb-douze', 'Douze mois');
  await f.dire('<b>Les douze derniers mois</b> : les clients de chaque mois, en pointillé le même mois un an plus tôt.', 5000);
  await f.dire('À droite, le mois en cours face à l’an passé <b>aux mêmes jours</b>, et le meilleur mois.', 4400);
  await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'Les six leviers', texte: 'Sous la page, replié : les leviers du mois, puis le résultat jour par jour.' });
  await f.etape('4 · Les six leviers');
  await f.montrer('#dash .qb-plus', 'Le détail');
  await f.dire('Sous la page, le détail est <b>replié</b> : un clic sur une puce ouvre sa section.', 4000);
  await f.cliquer('#dash [data-qbplus=leviers]', { apres: 1500 });
  await f.montrer('#dash [data-qbsec=leviers] .lv-card', 'Les six leviers');
  await f.dire('<b>Les six leviers</b> du mois : trafic, récurrence, expérience client, food cost, labour cost, overhead cost.', 5400);
  await f.dire('Chacun face au <b>même mois de l’an passé</b> et au <b>réseau</b>.', 3600);
  await f.montrer('#dash [data-qbsec=leviers] .lv-leg', 'La pastille');
  await f.dire('La <b>pastille</b> suit les seuils de la Table KPI : vert sous l’alerte, puis or, puis rouge.', 4800);
  await f.cliquer('#dash [data-levvue=annee]', { apres: 1500 });
  await f.montrer('#dash [data-qbsec=leviers] .lv-card', 'L’évolution de l’année');
  await f.dire('« <b>L’évolution de l’année</b> » : une barre par mois, le meilleur et le pire mois, la moyenne face au réseau.', 5400);
  await f.taire(200);
  await f.cliquer('#dash [data-levvue=mois]', { apres: 1000 });
  await f.cacher(200);
  await f.cliquer('#dash [data-qbplus=tendance]', { apres: 1500 });
  await f.montrer('#dash [data-qbsec=tendance]', 'Jour par jour');
  await f.dire('<b>Le résultat jour par jour</b> du mois en cours : le résultat net de chaque jour, en couleur.', 4800);
  await f.taire(200);
  await f.cacher();
  await f.defiler(-5000);
  return f.fin({
    titre: 'À vous de lire<br>votre mois', accroche: 'Un mois se joue chaque jour',
    retenir: ['Un clic sur un mois l’ouvre', 'Le trait : l’attendu à ce jour', 'Douze mois face à l’an passé', 'Les six leviers, sous la page'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
