/* Mois › « Les périodes : Colorer par CA · Marge nette · Clients », la vidéo de l'onglet (26/11/2026) :
 *   onglet  mois-periodes   dans « Le détail heure par heure », la carte « Les périodes » du mois : une colonne par
 *                           semaine (lundi → dimanche, bornée au mois), le rang de la période dans sa semaine, le total
 *                           du mois ; « Colorer par ». Le même écran qu'en Semaine (06-04), regroupé par semaine.
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (periodes).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (rendPeriodes) et de panel/src/stats_ventes.php. */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const PER = '#dash .db-card:has([data-perdrop])';

module.exports = async function () {
  const f = await ouvrir('07-05-periodes', 'mois', { puces: ['heures'], sources: ['panel/src/stats_ventes.php'] });

  await f.onglet({
    id: 'mois-periodes', titre: 'Les périodes du mois',
    resume: 'Le mois en matin, midi et après-midi, une colonne par semaine : les ventes, la marge nette et les clients de chaque case, son rang dans la semaine, le total du mois ; la couleur suit le chiffre choisi.',
    accroche: 'Matin, midi, après-midi',
    programme: ['Colorer par CA', 'Colorer par marge nette', 'Colorer par clients'],
  });

  await enHautDe(f, PER, 120);
  await dire(f, PER, 'Les périodes', 'Dans « Le détail heure par heure », la carte « <b>Les périodes</b> » : le même écran qu’en Semaine.', 5000);
  await rien(f);
  await f.cliquer(PER + ' [data-perdrop]', { dx: 0.3, apres: 1400 });
  await enHautDe(f, PER, 20);
  await dire(f, PER + ' .db-per', 'Semaine par semaine', 'Au mois, les jours se regroupent <b>par semaine</b>, du lundi au dimanche, bornée au mois.', 5400);
  await f.montrer(PER + ' .db-per .c em >> nth=0', 'Le rang');
  await f.dire('Le rang se lit alors dans la <b>semaine</b>, au CA.', 3400);
  await f.montrerTout(PER + ' .db-per .c.sem', 'Le mois');
  await f.dire('La colonne de droite totalise le mois.', 3000);
  await rien(f);
  await f.cliquer(PER + ' [data-percol="res"]', { apres: 1300 });
  await dire(f, PER + ' .db-per', 'Marge nette', 'Colorée par la <b>marge nette</b>…', 3200);
  await rien(f);
  await f.cliquer(PER + ' [data-percol="tickets"]', { apres: 1300 });
  await dire(f, PER + ' .db-per', 'Clients', '… ou par les <b>clients</b>. Le rang reste au CA.', 3600);
  await rien(f);
  await f.cliquer(PER + ' [data-percol="ca"]', { apres: 1200 });
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
