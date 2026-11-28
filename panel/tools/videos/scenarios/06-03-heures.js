/* Semaine › « Le détail heure par heure : Moyenne / jour ouvert · Total », la vidéo de l'onglet (26/11/2026) :
 *   onglet  semaine-heure-par-heure   la puce « Le détail heure par heure » : les repères, la journée type (moyenne par
 *                                     jour ouvert) ou la semaine entière (total), une heure ouverte (ventes − matière −
 *                                     rémunération, le top 5 des produits, le top 3 des catégories), le tableau.
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (statsPeriode).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (rendHeures, rendPanneau) et de panel/src/stats_ventes.php. */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const HH = '#dash [data-qbsec=heures]';
const GR = HH + ' .db-card:has(.db-gr)';
const PAN = HH + ' .db-g2 > .db-card:last-child';

module.exports = async function () {
  const f = await ouvrir('06-03-heures', 'semaine', { sources: ['panel/src/stats_ventes.php'] });

  await f.onglet({
    id: 'semaine-heure-par-heure', titre: 'Le détail heure par heure',
    resume: 'Ce que chaque heure vend et rapporte : la journée type (moyenne par jour ouvert) ou le total de la semaine, la marge nette de chaque heure, une heure ouverte avec ses produits et ses catégories, le tableau.',
    accroche: 'Les heures qui rapportent',
    programme: ['Moyenne par jour ouvert', 'Total de la semaine', 'Une heure en détail'],
  });

  await enHautDe(f, '#dash .qb-plus', 380);
  await dire(f, '#dash [data-qbplus=heures]', 'La puce', 'La dernière puce sous la page : « <b>Le détail heure par heure</b> ».', 4000);
  await rien(f);
  await f.cliquer('#dash [data-qbplus=heures]', { apres: 1500 });
  await enHautDe(f, HH, 20);
  await dire(f, HH + ' .db-tuiles', 'Les repères', 'En tête : l’heure qui a le plus de clients, celle qui fait le plus de CA, la marge brute, la <b>marge nette</b> des heures.', 6000);
  await f.dire('Puis l’heure la plus rentable, et la moins rentable.', 3000);
  await rien(f);

  await enHautDe(f, GR, 20);
  await dire(f, GR, 'La journée type', '<b>La journée type</b> : chaque heure en moyenne par jour ouvert. La barre empile la matière, la rémunération et la marge nette.', 6400);
  await dire(f, GR + ' .db-gr', 'La marge nette', 'Dessous, la case de la <b>marge nette</b> : rouge à perte, or sous 20 % des ventes, vert à 20 % et plus.', 5600);
  await rien(f);
  await f.cliquer(GR + ' [data-mode="tot"]', { apres: 1300 });
  await dire(f, GR, 'Total', '« <b>Total</b> » : la semaine entière, heure par heure, au lieu de la moyenne.', 4200);
  await rien(f);
  await f.cliquer(GR + ' [data-mode="moy"]', { apres: 1200 });

  await f.cliquer(GR + ' .bh[data-h="12"]', { apres: 1300 });
  await enHautDe(f, HH + ' .db-g2', 20);
  await dire(f, PAN + ' .db-wf', '12 – 13 h', 'Un clic sur une barre, une case ou une ligne ouvre l’<b>heure</b> : ventes − matière = marge brute, − rémunération = marge nette.', 6400);
  await dire(f, PAN, 'Top 5 · top 3', 'Dessous, le <b>top 5</b> des produits de l’heure par marge brute, et le top 3 des catégories.', 5200);
  await rien(f);
  await dire(f, HH + ' .db-g2 > .db-card:first-child', 'Heure par heure', 'À gauche, le tableau : clients, panier, ventes, matière, marge brute, personnel en poste, rémunération, marge nette.', 6200);
  await rien(f);
  await f.cliquer(HH + ' tr.hv[data-h="8"]', { apres: 1300 });
  await dire(f, PAN + ' .db-wf', '8 – 9 h', 'Une ligne ouvre son heure : 8 – 9 h, la plus rentable de la semaine.', 4400);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
