/* Vidéo 02 — « Mon équipe : faire ses horaires » (nouvelle fonction, la bulle de Mon équipe). Environ 2 min 20. */
const { Film } = require('../film');
const E = require('./equipe');

module.exports = async function () {
  const f = await Film.ouvrir('02-mon-equipe', { vue: '', serie: 'Nouvelle fonction', titreSerie: 'Mon équipe : faire ses horaires', duree: 'env. 2 min 20' });
  await f.couverture({
    kicker: 'Nouveau · Mon équipe', titre: 'Faire ses horaires<br>avec Mon équipe', accroche: 'Le bon monde, au bon moment',
    programme: ['La semaine d’un coup d’œil', 'Le budget d’heures', 'Corriger un trou au comptoir', 'Revenir sous la barre'],
  });
  await E.ouvrirMonEquipe(f);

  await f.chapitre({ n: 1, total: 4, titre: 'La semaine d’un coup d’œil', texte: 'Qui travaille, quand, et ce que ça coûte face au chiffre d’affaires.' });
  await f.etape('1 · La semaine');
  await E.lireLaSemaine(f);

  await f.chapitre({ n: 2, total: 4, titre: 'Le budget d’heures', texte: 'La part du personnel dans le chiffre d’affaires : la cible du réseau est 17 %.' });
  await f.etape('2 · Le budget');
  await E.leBudget(f, { long: true });

  await f.chapitre({ n: 3, total: 4, titre: 'Corriger un trou au comptoir', texte: 'Mardi, personne au comptoir de 13 h 45 à 14 h 30. On avance le service qui suit.' });
  await f.etape('3 · Un trou au comptoir');
  await E.corrigerLeTrou(f);

  await f.chapitre({ n: 4, total: 4, titre: 'Revenir sous la barre', texte: 'Vendredi est rouge : on retire une personne d’un shift.' });
  await f.etape('4 · Revenir sous la barre');
  await E.retirerDuShift(f);

  return f.fin({
    titre: 'À vous de faire<br>vos horaires', accroche: 'Le planning qui tient le budget',
    retenir: ['Le planning se lit à la semaine', 'Visez 17 % de personnel', 'Un clic sur un service pour l’adapter', 'Les linéaires montrent les trous'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
