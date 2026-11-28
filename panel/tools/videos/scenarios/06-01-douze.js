/* Semaine › « Les douze dernières semaines : Clients · Panier moyen · Articles par client », la vidéo de l'onglet
 * (26/11/2026) :
 *   onglet  semaine-douze-semaines   la courbe des douze semaines closes face au N-1, l'écart de chacune, les trois
 *                                    tuiles ; les trois mesures ; la frise du haut : ouvrir une semaine passée, la
 *                                    décaler d'une semaine, « Cette semaine ».
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js. Chaque phrase
 * vient de panel/public/dashboard/dashboard.js (rendSemaines, friseHtml, les gestes data-s12m, data-fdate, data-frsem,
 * data-auj). */
const { ouvrir, enHautDe, dire, rien, enHaut } = require('./chiffres');

const DZ = '#dash .qb-douze';

module.exports = async function () {
  const f = await ouvrir('06-01-douze', 'semaine');

  await f.onglet({
    id: 'semaine-douze-semaines', titre: 'Les douze dernières semaines',
    resume: 'Les clients, le panier moyen ou les articles par client de chaque semaine close, face à la même semaine un an plus tôt ; l’écart semaine par semaine, les trois tuiles ; ouvrir une semaine passée depuis la frise du haut.',
    accroche: 'La tendance, semaine après semaine',
    programme: ['Clients', 'Panier moyen', 'Articles par client', 'Ouvrir une semaine passée'],
  });

  await enHautDe(f, DZ, 20);
  await dire(f, DZ + ' .db-s6', 'Douze semaines', '<b>Les douze dernières semaines</b> closes : les clients de chaque semaine, en pointillé la même semaine un an plus tôt.', 5600);
  await f.dire('La bande entre les deux : <b>verte</b> au-dessus de l’an passé, <b>or</b> en dessous.', 4400);
  await f.montrerTout(DZ + ' .ch', 'L’écart');
  await f.dire('Dessous, l’<b>écart</b> de chaque semaine face au N-1.', 3400);
  await f.montrerTout(DZ + ' .db-tui', 'Trois tuiles');
  await f.dire('À droite : l’écart sur les douze semaines, la <b>semaine en cours</b> face au N-1 aux mêmes jours, la meilleure semaine.', 6000);
  await f.dire('La semaine en cours reste hors de la courbe : à moitié servie, elle la ferait plonger.', 4600);
  await rien(f);

  await f.cliquer(DZ + ' [data-s12m="panier"]', { apres: 1200 });
  await dire(f, DZ + ' .db-card', 'Panier moyen', '<b>Panier moyen</b> : hors clients pro, dès que leurs tickets sont lus.', 4200);
  await rien(f);
  await f.cliquer(DZ + ' [data-s12m="articles"]', { apres: 1200 });
  await dire(f, DZ + ' .db-card', 'Articles par client', '<b>Articles par client</b> : les pièces vendues divisées par les clients.', 4200);
  await f.dire('Le choix reste d’une visite à l’autre.', 2800);
  await rien(f);
  await f.cliquer(DZ + ' [data-s12m="clients"]', { apres: 1000 });

  /* La frise : une semaine passée, puis la semaine en cours */
  await enHaut(f);
  await dire(f, '#dash .db-frise', 'Les semaines', 'En haut, la <b>frise</b> : les six semaines d’avant et celle qu’on regarde.', 4200);
  await rien(f);
  await f.cliquer('#dash .db-frise .fr-c[data-fdate="2026-11-08"]', { apres: 2000 });
  await dire(f, '#dash .chr-l1', 'La S45, close', 'Un clic ouvre la <b>S45</b>, close : elle se lit face à son objectif.', 4200);
  await rien(f);
  await enHautDe(f, DZ, 20);
  await dire(f, DZ + ' .db-card', 'Jusqu’à la S45', 'Les douze semaines s’arrêtent à la S45 ; sa tuile la compare à la même semaine de l’an passé.', 5200);
  await rien(f);
  await enHaut(f);
  await f.cliquer('#dash .db-frise [data-frsem="-1"]', { apres: 1800 });
  await dire(f, '#dash .db-frise', '‹ et ›', '<b>‹</b> et <b>›</b> décalent d’une semaine ; sur la semaine en cours, › est grisée.', 4600);
  await rien(f);
  await dire(f, '#dash [data-auj]', 'Cette semaine', '« <b>Cette semaine</b> » ramène à la semaine en cours.', 3200);
  await f.cacher(150);
  await f.cliquer('#dash [data-auj]', { apres: 2000 });
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
