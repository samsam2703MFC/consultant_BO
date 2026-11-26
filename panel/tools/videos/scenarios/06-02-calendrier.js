/* Semaine › « Le calendrier de la semaine : CA · Atteinte · Clients », la vidéo de l'onglet (26/11/2026) :
 *   onglet  semaine-calendrier   les puces du détail replié sous la page (un clic ouvre, un second replie, gardé d'une
 *                                visite à l'autre) ; le calendrier et ses trois mesures ; une autre puce, « Tâches et
 *                                contrôles » : les non-conformités de la semaine, une ligne ouverte, le fil des jours.
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (la semaine, les
 * jours relevés), 15_chiffres_periode.js et 04_taches.js (les non-conformités). Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (qbPlus, periodeCalendrier, rendNC, ncListe, ncDetail, rendTaches). */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const CAL = '#dash [data-qbsec=calendrier]';
const TC = '#dash [data-qbsec=taches]';

module.exports = async function () {
  // « Le détail heure par heure » ouvert d'office (une puce ouverte le reste) : le calendrier, au-dessus, peut monter en
  // haut de l'écran au lieu de rester collé au bas de la page.
  const f = await ouvrir('06-02-calendrier', 'semaine', { puces: ['heures'], sources: ['shared/src/endpoints.php'] });

  await f.onglet({
    id: 'semaine-calendrier', titre: 'Le calendrier de la semaine',
    resume: 'Le détail replié sous la page, puce par puce ; le calendrier : chaque jour, son chiffre d’affaires, son atteinte ou ses clients, face à l’objectif du jour ; les tâches et les non-conformités de la semaine.',
    accroche: 'Chaque jour face à son objectif',
    programme: ['Les puces du détail', 'CA, Atteinte, Clients', 'Tâches et contrôles'],
  });

  await enHautDe(f, '#dash .qb-plus', 380);
  await dire(f, '#dash .qb-plus', 'Le détail', 'Sous la page, le détail est <b>replié</b> en puces : un clic ouvre la section, un second la replie.', 5200);
  await f.dire('Une puce ouverte le reste d’une visite à l’autre.', 3000);
  await rien(f);
  await f.cliquer('#dash [data-qbplus=calendrier]', { apres: 1400 });
  await enHautDe(f, CAL, 20);
  await dire(f, CAL + ' .cal-a', 'Le calendrier', '<b>Le calendrier de la semaine</b> : chaque jour, son chiffre et sa jauge face à l’objectif du jour.', 5200);
  await dire(f, CAL + ' .cal-a-g', 'CA', 'Couleur et jauge : le CA face à l’objectif ; le trait, 100 %. Le meilleur jour porte « <b>record</b> ».', 5600);
  await f.dire('Jeudi est <b>hachuré</b> : la journée n’est pas finie. Les jours à venir ne montrent que leur objectif.', 5000);
  await dire(f, CAL + ' .cal-seg', 'Trois mesures', 'En haut, trois mesures, avec le total de la semaine.', 3400);
  await rien(f);

  await f.cliquer(CAL + ' [data-calval="att"]', { apres: 1200 });
  await dire(f, CAL + ' .cal-a', 'Atteinte', '<b>Atteinte</b> : en grand, le CA en % de l’objectif du jour.', 4000);
  await rien(f);
  await f.cliquer(CAL + ' [data-calval="cli"]', { apres: 1200 });
  await dire(f, CAL + ' .cal-a', 'Clients', '<b>Clients</b> : les clients du jour, face à l’objectif clients — l’objectif CA divisé par le panier moyen.', 5600);
  await f.dire('Le choix reste d’une visite à l’autre.', 2800);
  await rien(f);
  await f.cliquer(CAL + ' [data-calval="ca"]', { apres: 1000 });

  /* Une autre puce : Tâches et contrôles */
  await enHautDe(f, '#dash .qb-plus', 140);
  await f.cliquer('#dash [data-qbplus=taches]', { apres: 1500 });
  await enHautDe(f, TC, 20);
  await dire(f, TC + ' .db-ncbar', 'Les non-conformités', '« <b>Tâches et contrôles</b> » : en tête, les non-conformités relevées cette semaine.', 4600);
  await rien(f);
  await f.cliquer(TC + ' [data-ncdrop]', { apres: 1300 });
  await dire(f, TC + ' .db-nclist', 'Une ligne par écart', 'Une ligne par écart : la note, le jour, la tâche, le motif du contrôleur et ce qui a suivi.', 5400);
  await rien(f);
  await f.cliquer(TC + ' [data-ncrow] >> nth=1', { apres: 1300 });
  await dire(f, TC + ' .db-ncd', 'Le détail', 'Un clic ouvre l’écart : le motif, qui l’a relevé et quand, la récidive, ce qui a suivi.', 5000);
  await rien(f);
  await f.cliquer(TC + ' [data-tdrop]', { apres: 1300 });
  await enHautDe(f, TC + ' .db-taches', 20);
  await dire(f, TC + ' .db-tdrop', 'Le fil des jours', '« <b>Le fil des jours</b> » : chaque jour, les tâches faites sur les tâches à faire.', 4800);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
