/* Mois › « Le détail heure par heure : Moyenne / jour ouvert · Total », la vidéo de l'onglet (26/11/2026) :
 *   onglet  mois-heure-par-heure   la puce « Le détail heure par heure » : la journée type du mois, ou le total du mois ;
 *                                  une heure ouverte ; le tableau. Le même écran qu'en Semaine (06-03), sur le mois.
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (statsPeriode).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (rendHeures, rendPanneau) et de panel/src/stats_ventes.php. */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const HH = '#dash [data-qbsec=heures]';
const GR = HH + ' .db-card:has(.db-gr)';
const PAN = HH + ' .db-g2 > .db-card:last-child';

module.exports = async function () {
  const f = await ouvrir('07-04-heures', 'mois', { sources: ['panel/src/stats_ventes.php'] });

  await f.onglet({
    id: 'mois-heure-par-heure', titre: 'Le détail heure par heure du mois',
    resume: 'La journée type du mois (moyenne par jour ouvert) ou le total du mois : ce que chaque heure vend et rapporte, une heure ouverte avec ses produits et ses catégories, le tableau.',
    accroche: 'Les heures du mois',
    programme: ['Moyenne par jour ouvert', 'Total du mois', 'Une heure en détail'],
  });

  await enHautDe(f, '#dash .qb-plus', 380);
  await f.cliquer('#dash [data-qbplus=heures]', { apres: 1600 });
  await enHautDe(f, HH, 20);
  await dire(f, HH + ' .db-tuiles', 'Les repères', 'Le même écran qu’en Semaine, sur le mois : les heures qui comptent, la marge brute, la <b>marge nette</b> des heures.', 6000);
  await rien(f);
  await enHautDe(f, GR, 20);
  await dire(f, GR, 'La journée type', '<b>La journée type du mois</b> : chaque heure en moyenne par jour ouvert ; dessous, sa marge nette.', 5400);
  await rien(f);
  await f.cliquer(GR + ' [data-mode="tot"]', { apres: 1300 });
  await dire(f, GR, 'Total', '« <b>Total</b> » : le mois entier, heure par heure.', 3600);
  await rien(f);
  await f.cliquer(GR + ' [data-mode="moy"]', { apres: 1200 });
  await f.cliquer(GR + ' .bh[data-h="15"]', { apres: 1300 });
  await enHautDe(f, HH + ' .db-g2', 20);
  await dire(f, PAN + ' .db-wf', '15 – 16 h', 'Un clic ouvre l’heure : ventes, matière, marge brute, rémunération, <b>marge nette</b>.', 5000);
  await dire(f, PAN, 'Top 5 · top 3', 'Puis ses produits et ses catégories, par marge brute.', 3400);
  await rien(f);
  await dire(f, HH + ' .db-g2 > .db-card:first-child', 'Heure par heure', 'Le tableau : une ligne par heure, et le jour type du mois en bas.', 4200);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
