/* Mois › « Le calendrier du mois : CA · Atteinte · Clients », la vidéo de l'onglet (26/11/2026) :
 *   onglet  mois-calendrier   la puce « Le calendrier du mois » : les jours du mois et leurs trois mesures, le profil
 *                             des jours de la semaine ; la puce « Le résultat jour par jour » (mois en cours).
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (la période,
 * /exploitation/rentabilite). Chaque phrase vient de panel/public/dashboard/dashboard.js (periodeCalendrier,
 * periodeProfil, rendRentab). */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const CAL = '#dash [data-qbsec=calendrier]';
const TD = '#dash [data-qbsec=tendance]';

module.exports = async function () {
  // « Le détail heure par heure » ouvert d'office : de la place sous le calendrier, qui monte en haut de l'écran.
  const f = await ouvrir('07-03-calendrier', 'mois', { puces: ['heures'] });

  await f.onglet({
    id: 'mois-calendrier', titre: 'Le calendrier du mois',
    resume: 'Les jours du mois face à leur objectif : le chiffre d’affaires, l’atteinte ou les clients de chacun ; le profil des jours de la semaine ; le résultat net jour par jour.',
    accroche: 'Le mois, jour par jour',
    programme: ['CA, Atteinte, Clients', 'Le profil des jours', 'Le résultat jour par jour'],
  });

  await enHautDe(f, '#dash .qb-plus', 380);
  await dire(f, '#dash [data-qbplus=calendrier]', 'La puce', 'Sous la page, la puce « <b>Le calendrier du mois</b> ».', 3600);
  await rien(f);
  await f.cliquer('#dash [data-qbplus=calendrier]', { apres: 1500 });
  await enHautDe(f, CAL, 20);
  await dire(f, CAL + ' .cal-a', 'Le calendrier', 'Les jours du mois, semaine par semaine : le chiffre de chacun et sa jauge face à l’objectif du jour.', 5600);
  await dire(f, CAL + ' .cal-seg', 'Le mois', 'En haut à droite, le total du mois : CA, atteinte, clients.', 3800);
  await rien(f);
  await f.cliquer(CAL + ' [data-calval="att"]', { apres: 1200 });
  await dire(f, CAL + ' .cal-a-g', 'Atteinte', '<b>Atteinte</b> : chaque jour en % de son objectif.', 3800);
  await rien(f);
  await f.cliquer(CAL + ' [data-calval="cli"]', { apres: 1200 });
  await dire(f, CAL + ' .cal-a-g', 'Clients', '<b>Clients</b> : les clients du jour, face à l’objectif clients du jour.', 4200);
  await rien(f);
  await f.cliquer(CAL + ' [data-calval="ca"]', { apres: 1000 });
  await enHautDe(f, CAL + ' .cal-a + .db-card', 20);
  await dire(f, CAL + ' .cal-a + .db-card', 'Le profil des jours', 'Dessous, <b>le profil des jours</b> : la moyenne de chaque jour de la semaine, le trait noir de son objectif.', 6000);
  await f.dire('Il dit le point faible du mois.', 2600);
  await rien(f);

  await enHautDe(f, '#dash .qb-plus', 140);
  await f.cliquer('#dash [data-qbplus=tendance]', { apres: 1500 });
  await enHautDe(f, TD, 20);
  await dire(f, TD, 'Jour par jour', '« <b>Le résultat jour par jour</b> », sur le mois en cours : le résultat net de chaque jour, en couleur.', 5600);
  await f.dire('Cette puce n’existe que pour le mois en cours.', 3000);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
