/* Vidéo 08 — « La page Année et ses trimestres » (mode d'emploi, la page Année du rail, section « Les chiffres » ;
 * l'ex-onglet Trimestre y est fondu). Environ 2 min 45. Ce qui est dit vient de dashboard.js (rendAnnee, anObjectif,
 * anTrimestres, anDetail, anBande, anTableMois, anRampe), de cartes-magasin.js (annee) et de consultant/src/plan.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('08-annee', { vue: 'annee', serie: 'Mode d’emploi', titreSerie: 'La page Année', duree: 'env. 2 min 45' });
  await f.couverture({
    kicker: 'Mode d’emploi · Les chiffres', titre: 'L’année et<br>ses trimestres', accroche: 'L’objectif, trimestre après trimestre',
    programme: ['L’objectif de l’année', 'Les quatre trimestres', 'Les douze mois et le trafic', 'Le détail et le long terme'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'L’objectif de l’année', texte: 'L’engagement de l’année en une carte : où vous en êtes, et où vous allez.' });
  await f.etape('1 · L’objectif');
  await f.montrer('#dash .db-an-obj', 'L’objectif de l’année');
  await f.dire('En tête, l’<b>objectif de l’année</b> : l’engagement du franchisé, que le consultant valide.', 4600);
  await f.montrer('#dash .db-an-barre', 'La barre');
  await f.dire('La barre : le <b>réalisé</b>, la <b>projection</b> hachurée, et le trait de l’<b>attendu à ce jour</b>.', 4800);
  await f.dire('L’attendu suit les budgets des mois : la part de l’année déjà écoulée.', 4000);
  await f.montrer('#dash .db-an-3', 'Trois chiffres');
  await f.dire('Le <b>réalisé</b>, l’<b>écart</b> à l’attendu — en avance ou en retard — et la <b>projection</b> de fin d’année au rythme actuel.', 6000);
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'Les quatre trimestres', texte: 'Le trimestre a rejoint l’Année : un clic sur un trimestre ouvre son détail.' });
  await f.etape('2 · Les trimestres');
  await f.montrer('#dash .db-an-q', 'Les trimestres');
  await f.dire('Les <b>quatre trimestres</b> côte à côte : clos, en cours ou à venir, le réalisé sur l’objectif, et ses trois mois.', 5600);
  await f.montrer('#dash .db-an-qc[data-anq="4"] .db-pill', 'La pastille');
  await f.dire('La <b>pastille</b> compare le réalisé à l’attendu à ce jour : rouge sous 80 %, orange jusqu’à 95, vert jusqu’à 105, doré au-delà.', 6400);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#dash .db-an-qc[data-anq="3"]', { apres: 1600 });
  await f.montrer('#dash .db-an-det', 'Le trimestre T3');
  await f.dire('Le <b>troisième trimestre</b>, clos : ses trois mois face à leur budget, et ses chiffres.', 4600);
  await f.montrer('#dash .db-an-det dl.kv', 'Ses chiffres');
  await f.dire('Objectif, réalisé, attendu, <b>écart</b>, les <b>clients manquants</b> au panier moyen, la marge nette.', 5200);
  await f.montrer('#dash .db-an-det .an', 'Le rituel');
  await f.dire('À droite, les <b>annotations</b> du franchisé, du consultant et de la marque, les <b>actions</b> du trimestre et le <b>rituel</b> en cinq étapes.', 6400);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#dash .db-an-qc[data-anq="4"]', { apres: 1400 });
  await f.montrer('#dash .db-an-det', 'Le trimestre en cours');
  await f.dire('Le <b>quatrième trimestre</b>, en cours : décembre n’a encore que son budget.', 4400);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Les douze mois et le trafic', texte: 'Chaque mois face à son budget et à l’an passé ; les clients, le panier, les articles.' });
  await f.etape('3 · Les douze mois');
  await f.montrer('#dash .db-card:has(.db-an-12)', 'Les douze mois');
  await f.dire('<b>Les douze mois</b>, rangés par trimestre : la barre du chiffre, sa couleur dit l’atteinte du budget.', 5200);
  await f.dire('Le mois en cours est en or ; le trait gris, c’est le même mois de l’an passé.', 4200);
  await f.montrer('#dash .db-card:has([data-anm])', 'Le trafic');
  await f.dire('<b>Le trafic</b> : les clients de chaque mois, et en pointillé ceux du même mois de l’an passé.', 4800);
  await f.dire('Le mois en cours est prolongé au rythme de ses jours ; son écart se lit aux mêmes jours de l’an passé.', 5200);
  await f.cliquer('#dash [data-anm=panier]', { apres: 1400 });
  await f.dire('Le même pour le <b>panier moyen</b>, ou les <b>articles par client</b>.', 3400);
  await f.taire(200);
  await f.cliquer('#dash [data-anm=clients]', { apres: 900 });
  await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'Le détail et le long terme', texte: 'Repliés en bas : le tableau des mois, et la rampe à cinq ans de l’étude de marché.' });
  await f.etape('4 · Le détail');
  await f.cliquer('#dash details[data-anpli=mois] > summary', { apres: 1400 });
  await f.montrer('#dash details[data-anpli=mois]', 'Le détail des mois');
  await f.dire('<b>Le détail des mois</b> : chiffre, budget, atteinte, clients, panier, food, labour, overhead et marge nette.', 5600);
  await f.cliquer('#dash details[data-anpli=rampe] > summary', { apres: 1400 });
  await f.montrer('#dash details[data-anpli=rampe]', 'Le long terme');
  await f.dire('<b>Le long terme</b> : la rampe à cinq ans de l’étude de marché, et l’engagement pris sur l’année.', 5000);
  await f.taire(200);
  await f.cacher(200);
  await f.defiler(-5000);
  await f.montrerTout('#dash [data-pas], #db-date', 'Changer d’année');
  await f.dire('En haut, « <b>Année de</b> » et ses flèches changent d’année.', 3400);
  await f.cacher();
  return f.fin({
    titre: 'À vous de suivre<br>votre année', accroche: 'Un trimestre après l’autre',
    retenir: ['L’objectif face à l’attendu', 'Un clic ouvre un trimestre', 'Douze mois face à l’an passé', 'Le détail est replié en bas'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
