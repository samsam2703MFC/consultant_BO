/* Année › « Trimestre (T1 à T4) », la vidéo de l'onglet (26/11/2026) :
 *   onglet  annee-trimestre   les quatre cartes de trimestre (le trimestre en cours ouvert d'office), le détail d'un
 *                             trimestre clos puis du trimestre en cours : ses trois mois face au budget, objectif,
 *                             réalisé, attendu, écart, clients manquants, marge nette, annotations, actions et rituel en
 *                             cinq étapes (lecture seule) ; changer d'année (le trimestre regardé est gardé), « Cette année ».
 * L'ancienne adresse ?vue=trimestre ouvre l'Année. Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données
 * fictives : mocks/08_annee.js (/plan, /stores/perf). Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (anTrimestres, anDetail, les gestes data-anq, data-pas, data-auj) et de consultant/src/plan.php. */
const { ouvrir, enHautDe, dire, rien, enHaut } = require('./chiffres');

const Q = '#dash .db-an-q';
const DET = '#dash .db-an-det';

module.exports = async function () {
  const f = await ouvrir('08-01-trimestre', 'annee', { sources: ['consultant/src/plan.php', 'consultant/public/assets/js/cartes-magasin.js'] });

  await f.onglet({
    id: 'annee-trimestre', titre: 'Les trimestres',
    resume: 'Les quatre trimestres de l’année, puis le détail de celui qu’on ouvre : ses trois mois face au budget, l’écart à l’attendu, les clients manquants, la marge nette, les annotations, les actions et le rituel ; changer d’année.',
    accroche: 'L’objectif, trimestre après trimestre',
    programme: ['Un trimestre clos', 'Le trimestre en cours', 'Une autre année'],
  });

  await enHautDe(f, Q, 20);
  await dire(f, Q, 'T1 à T4', 'Les <b>quatre trimestres</b> : clos, en cours ou à venir ; le réalisé sur l’objectif, ses trois mois, l’écart à l’attendu.', 6000);
  await f.dire('Le trimestre en cours est ouvert d’office, dessous.', 3000);
  await rien(f);
  await f.cliquer(Q + ' [data-anq="3"]', { apres: 1600 });
  await enHautDe(f, DET, 20);
  await dire(f, DET, 'T3, clos', 'Un clic ouvre le trimestre : le <b>T3</b>, clos. Ses trois mois face à leur budget.', 4800);
  await dire(f, DET + ' dl.kv', 'Ses chiffres', 'Objectif, réalisé, attendu, <b>écart</b>, les clients manquants au panier moyen, la marge nette.', 5600);
  await dire(f, DET + ' .an', 'Lecture seule', 'À droite : les <b>annotations</b> du franchisé, du consultant et de la marque, les actions, le <b>rituel</b> en cinq étapes.', 6400);
  await f.dire('Ici, tout se lit : rien ne s’écrit.', 2600);
  await rien(f);
  await enHautDe(f, Q, 20);
  await f.cliquer(Q + ' [data-anq="4"]', { apres: 1500 });
  await enHautDe(f, DET, 20);
  await dire(f, DET, 'T4, en cours', 'Le <b>T4</b>, en cours : novembre à date, décembre n’a que son budget.', 4600);
  await dire(f, DET + ' dl.kv', 'D’avance', 'Les clients manquants deviennent des clients d’<b>avance</b> quand l’écart est positif.', 4800);
  await rien(f);

  /* Changer d'année */
  await enHaut(f);
  await f.montrerTout('#dash [data-pas], #db-date', 'Année de');
  await f.dire('En haut, « <b>Année de</b> » : les flèches ou le champ de date changent d’année.', 4200);
  await rien(f);
  await f.cliquer('#dash [data-pas="-1"]', { apres: 2400 });
  await enHautDe(f, Q, 20);
  await dire(f, Q, '2025', 'L’année <b>2025</b> : le trimestre regardé est gardé, le T4.', 4200);
  await rien(f);
  await enHaut(f);
  await dire(f, '#dash [data-auj]', 'Cette année', '« <b>Cette année</b> » ramène à l’année en cours, sur son trimestre en cours.', 4200);
  await f.cacher(150);
  await f.cliquer('#dash [data-auj]', { apres: 2400 });
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
