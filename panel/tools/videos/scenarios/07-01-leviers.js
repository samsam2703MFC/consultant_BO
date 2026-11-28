/* Mois › « Les six leviers : Le mois · L'évolution de l'année », la vidéo de l'onglet (26/11/2026) :
 *   onglet  mois-leviers   les puces du détail replié (celles de la Semaine, plus « Les six leviers » et « Le résultat
 *                          jour par jour ») ; les six leviers du mois face au N-1 et au réseau, la pastille des seuils ;
 *                          l'évolution de l'année (une barre par mois, le meilleur, le pire, la moyenne face au réseau) ;
 *                          une autre puce, « Canaux, commandes et promotions » : l'évolution d'un canal sur six mois.
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/07_mois.js (/leviers/magasins),
 * 15_chiffres_periode.js (les canaux du mois, /exploitation/canaux-mois). Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (qbPlus, canauxMoisCarte, grapheCanal) et de
 * consultant/public/assets/js/cartes-magasin.js (les six leviers, evolution). */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const LV = '#dash [data-qbsec=leviers]';
const CO = '#dash [data-qbsec=canaux]';

module.exports = async function () {
  // « Le détail heure par heure » ouvert d'office : de la place sous les sections, qui montent en haut de l'écran.
  const f = await ouvrir('07-01-leviers', 'mois', { puces: ['heures'], sources: ['consultant/public/assets/js/cartes-magasin.js', 'marketing/src/canaux_offres.php'] });

  await f.onglet({
    id: 'mois-leviers', titre: 'Les six leviers du mois',
    resume: 'Sous la page, replié : les six leviers du mois face au même mois de l’an passé et au réseau, avec la pastille des seuils ; puis l’évolution de l’année, mois par mois ; et l’évolution de chaque canal de vente.',
    accroche: 'Ce qui fait le mois',
    programme: ['Le mois', 'L’évolution de l’année', 'L’évolution d’un canal'],
  });

  await enHautDe(f, '#dash .qb-plus', 380);
  await dire(f, '#dash .qb-plus', 'Le détail', 'Sous la page, le détail est <b>replié</b> : les puces de la Semaine, plus « Les six leviers » et « Le résultat jour par jour ».', 6200);
  await f.dire('Un clic ouvre la section, un second la replie ; elle reste ouverte d’une visite à l’autre.', 4600);
  await rien(f);
  await f.cliquer('#dash [data-qbplus=leviers]', { apres: 1600 });
  await enHautDe(f, LV, 20);
  await dire(f, LV + ' .lv-g', 'Le mois', '<b>Les six leviers</b> du mois : trafic, récurrence, expérience client, food cost, labour cost, overhead cost.', 6000);
  await f.dire('Chacun face au <b>même mois de l’an passé</b> et au <b>réseau</b>.', 3600);
  await dire(f, LV + ' .lv-leg', 'La pastille', 'La <b>pastille</b> suit les seuils de la Table KPI : sous l’alerte, entre alerte et critique, au-delà.', 5400);
  await rien(f);
  await f.cliquer(LV + ' [data-levvue="annee"]', { apres: 1600 });
  await dire(f, LV + ' .lv-card', 'L’évolution de l’année', '« <b>L’évolution de l’année</b> » : une barre par mois, à la couleur des seuils.', 4600);
  await f.dire('À droite de chaque levier : le meilleur mois, le pire, et la moyenne face au <b>réseau</b>.', 4600);
  await f.dire('La note Google n’a pas de relevé mois par mois : elle se lit dans « Le mois ».', 4200);
  await rien(f);
  await f.cliquer(LV + ' [data-levvue="mois"]', { apres: 1200 });

  /* Une autre puce : l'évolution d'un canal */
  await enHautDe(f, '#dash .qb-plus', 140);
  await f.cliquer('#dash [data-qbplus=canaux]', { apres: 1600 });
  await enHautDe(f, CO, 20);
  await dire(f, CO + ' .db-split-g', 'Quatre canaux', '« <b>Canaux, commandes et promotions</b> » : comptoir, clients pro, click & collect, livraison, chacun avec ses six derniers mois.', 6400);
  await rien(f);
  await f.cliquer(CO + ' [data-coc="p"]', { apres: 1500 });
  await dire(f, CO + ' .co-graphe', 'Clients pro', 'Un clic sur une tuile ouvre son <b>évolution</b> : le cumul du mois face au mois d’avant, aux mêmes dates.', 5800);
  await f.dire('En pointillé, la fin du mois au rythme actuel.', 3000);
  await rien(f);
  await f.cliquer(CO + ' [data-coc="p"]', { apres: 1200 });
  await dire(f, CO + ' .db-split-g', 'Refermé', 'Un second clic le referme.', 2600);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
