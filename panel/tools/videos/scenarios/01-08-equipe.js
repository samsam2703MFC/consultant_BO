/* Opérationnel › « L'équipe du jour », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-equipe-du-jour   la carte « La journée » (le planning de 4 h à 20 h, les ventes au comptoir de chaque
 *                                heure face à la moyenne), « L'équipe du jour › » (planning, heures faites, vendu, par
 *                                heure de vente, panier, coût, coût / ventes), « heure par heure » sous une personne,
 *                                la barre d'une personne, × pour fermer.
 * Ouvrir, déplier, fermer : des gestes de lecture, sans chapitre à eux. Données fictives : mocks/10_ops.js,
 * /exploitation/equipe-heures dans mocks/10_ops_equipe.js. Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (rendOps, opHeures, opEquipeLignes, opEquipe).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const JR = '.op-bloc:has(.op-frise)';

module.exports = async function () {
  const f = await ouvrir('01-08-equipe');

  await f.onglet({
    id: 'ops-equipe-du-jour', titre: 'L’équipe du jour',
    resume: 'Qui est là aujourd’hui : le planning sur la journée, puis, pour chaque personne, ses heures faites, ses ventes, ses ventes par heure de vente et son coût ; son heure par heure en un clic.',
    accroche: 'Qui est là, et ce que ça donne',
    programme: ['La journée : le planning', 'L’équipe du jour', 'Heure par heure', 'Fermer'],
  });

  await enHautDe(f, JR, 16);
  await dire(f, JR + ' .op-frise', 'Le planning', '<b>La journée</b> : le planning de 4 h à 20 h ; le trait rouge, l’heure qu’il est.', 4600);
  await dire(f, JR + ' .op-hr', 'Les ventes de chaque heure', 'À droite, les ventes au comptoir de chaque heure, face à la moyenne des 6 derniers jeudis.', 5000);
  await rien(f);

  await f.cliquer('[data-eqouvre]', { apres: 1100 });
  await enHautDe(f, '.eq-carte', 16);
  await dire(f, '.eq-carte .ct', 'L’équipe du jour', '<b>L’équipe du jour</b>, à 15:40 : une ligne par personne, rangée par ventes à l’heure de vente.', 5400);
  await dire(f, '.eq-carte thead', 'Les colonnes', 'Son planning, ses heures faites, ce qu’elle a vendu, par heure de vente, son panier, son coût.', 5400);
  await dire(f, '.eq-carte tbody tr:first-child td:last-child', 'Coût / ventes', '<b>Coût / ventes</b> : son taux horaire fois ses heures faites, face à ce qu’elle a vendu.', 5000);
  await rien(f);

  await f.cliquer('.eq-carte tr[data-eqsel="Julie M."] .eq-hp', { apres: 1500 });
  await dire(f, '.eq-carte .eq-dep', 'Heure par heure', '« <b>heure par heure</b> » : ses ventes de chaque heure de son poste, et sa part du magasin.', 5200);
  await rien(f);

  await enHautDe(f, JR, 16);
  await f.cliquer(JR + ' b[data-eqsel="Inès B."]', { apres: 1500, dx: 0.3 });
  await enHautDe(f, '.eq-carte', 16);
  await dire(f, '.eq-carte .eq-dep', 'Inès B.', 'Une barre du planning choisit aussi la personne : son heure par heure s’ouvre.', 4400);
  await rien(f);
  await f.montrer('.eq-carte .eq-ferme', 'Fermer');
  await f.dire('× ferme l’équipe du jour.', 2400);
  await rien(f);
  await f.cliquer('.eq-carte .eq-ferme', { apres: 1400 });
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
