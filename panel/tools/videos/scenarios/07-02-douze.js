/* Mois › « Les douze derniers mois : Clients · Panier moyen · Articles par client », la vidéo de l'onglet (26/11/2026) :
 *   onglet  mois-douze-mois   les douze mois glissants face au même mois un an plus tôt, le mois en cours face aux
 *                             mêmes jours de l'an passé ; les trois mesures ; la frise des mois : ouvrir un mois clos,
 *                             changer d'année, « Ce mois-ci ».
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (semaines,
 * vue mois ; /stores/perf) et 08_annee.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rendSemaines,
 * friseHtml, les gestes data-s12m, data-fdate, data-fan, data-auj). */
const { ouvrir, enHautDe, dire, rien, enHaut } = require('./chiffres');

const DZ = '#dash .qb-douze';

module.exports = async function () {
  const f = await ouvrir('07-02-douze', 'mois');

  await f.onglet({
    id: 'mois-douze-mois', titre: 'Les douze derniers mois',
    resume: 'Les clients, le panier moyen ou les articles par client des douze derniers mois glissants, chacun face au même mois un an plus tôt ; le mois en cours aux mêmes jours ; ouvrir un autre mois depuis la frise.',
    accroche: 'Un an de tendance',
    programme: ['Clients', 'Panier moyen', 'Articles par client', 'Ouvrir un autre mois'],
  });

  await enHautDe(f, DZ, 20);
  await dire(f, DZ + ' .db-s6', 'Douze mois', '<b>Les douze derniers mois</b>, glissants : les clients de chaque mois, en pointillé le même mois un an plus tôt.', 5800);
  await f.dire('La bande : <b>verte</b> au-dessus de l’an passé, <b>or</b> en dessous ; dessous, l’écart de chaque mois.', 5000);
  await f.montrerTout(DZ + ' .db-tui', 'Trois tuiles');
  await f.dire('À droite : l’écart sur douze mois, le <b>mois en cours</b> face aux mêmes jours de l’an passé, le meilleur mois.', 5800);
  await rien(f);
  await f.cliquer(DZ + ' [data-s12m="panier"]', { apres: 1200 });
  await dire(f, DZ + ' .db-card', 'Panier moyen', 'Le même sélecteur qu’en Semaine : <b>panier moyen</b>, hors clients pro…', 4200);
  await rien(f);
  await f.cliquer(DZ + ' [data-s12m="articles"]', { apres: 1200 });
  await dire(f, DZ + ' .db-card', 'Articles par client', '… ou <b>articles par client</b>. Le choix est gardé.', 3600);
  await rien(f);
  await f.cliquer(DZ + ' [data-s12m="clients"]', { apres: 1000 });

  /* La frise des mois */
  await enHaut(f);
  await dire(f, '#dash .db-frise', 'Les mois', 'En haut, les <b>douze mois</b> de l’année : le chiffre de chacun et sa jauge vers l’objectif.', 4800);
  await rien(f);
  await f.cliquer('#dash .db-frise .fr-c[data-fdate="2026-10-31"]', { apres: 2000 });
  await dire(f, '#dash .chr-l1', 'Octobre, clos', 'Un clic ouvre le mois : <b>octobre</b>, clos, se lit face à son objectif.', 4400);
  await rien(f);
  await enHautDe(f, DZ, 20);
  await dire(f, DZ + ' .db-card', 'Jusqu’à octobre', 'Les douze mois s’arrêtent à octobre.', 3200);
  await rien(f);
  await enHaut(f);
  await f.cliquer('#dash .db-frise [data-fan="-1"]', { apres: 2000 });
  await dire(f, '#dash .db-frise', '2025', 'Les flèches autour de l’année changent d’année : octobre 2025.', 4000);
  await f.dire('Sur l’année en cours, › est grisée.', 2600);
  await rien(f);
  await f.cliquer('#dash .db-frise .fr-c[data-fdate="2025-06-30"]', { apres: 2000 });
  await dire(f, '#dash .db-frise', 'Juin 2025', 'Puis un clic sur un mois : <b>juin 2025</b>.', 3400);
  await rien(f);
  await dire(f, '#dash [data-auj]', 'Ce mois-ci', '« <b>Ce mois-ci</b> » ramène au mois en cours.', 3200);
  await f.cacher(150);
  await f.cliquer('#dash [data-auj]', { apres: 2000 });
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
