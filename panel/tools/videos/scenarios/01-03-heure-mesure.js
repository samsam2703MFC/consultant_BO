/* Opérationnel › « Heure par heure : CA · Clients · Panier », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-heure-par-heure   le cumul du CA face à jeudi dernier et à l'objectif comptoir, le cumul des clients face
 *                                 à la référence, le panier moyen de chaque heure (clients de l'heure, points creux,
 *                                 le plus haut et le plus bas).
 * Le sélecteur à trois positions est un geste de lecture : pas de chapitre de fonction. Données fictives :
 * mocks/10_ops.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (opDuelHeures, opPanierHeures).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const HH = '.op-bloc:has(.op-crb-w)';

module.exports = async function () {
  const f = await ouvrir('01-03-heure-mesure');

  await f.onglet({
    id: 'ops-heure-par-heure', titre: 'Heure par heure : CA, clients, panier',
    resume: 'La carte « Heure par heure » : le cumul du chiffre, puis celui des clients, puis le panier moyen de chaque heure, face au même jour la semaine d’avant.',
    accroche: 'Le rythme de la journée',
    programme: ['CA : le chiffre cumulé', 'Clients : les clients cumulés', 'Panier : le panier de chaque heure'],
  });

  await enHautDe(f, HH, 44);
  await dire(f, HH + ' .op-crbtog:last-of-type', 'CA · Clients · Panier', 'La carte <b>Heure par heure</b> a trois mesures : CA, clients, panier.', 4000);
  await dire(f, HH + ' .op-crb-w', 'CA', '<b>CA</b> : le chiffre cumulé d’aujourd’hui, face à jeudi dernier en pointillé bleu.', 4600);
  await f.dire('En haut, l’<b>objectif comptoir</b> ; le trait vertical, l’heure qu’il est.', 4000);
  await rien(f);

  await f.cliquer(HH + ' [data-opcrb="tk"]', { apres: 900 });
  await dire(f, HH + ' .op-crb-w', 'Clients', '<b>Clients</b> : les clients cumulés, face à jeudi dernier et à la référence.', 4600);
  await rien(f);

  await f.cliquer(HH + ' [data-opcrb="pan"]', { apres: 900 });
  await dire(f, HH + ' .op-crb-w', 'Panier', '<b>Panier</b> : le panier moyen de chaque heure, pas un cumul.', 3800);
  await f.dire('En pâle au pied, les clients de l’heure. Un point creux : moins de 5 clients, ou l’heure en cours.', 5200);
  await f.dire('La ligne « <b>panier du jour</b> » : le panier moyen de toute la journée.', 3600);
  await dire(f, HH + ' .op-pk', 'Le plus haut, le plus bas', 'En haut, l’heure du panier le plus haut et du plus bas.', 3600);
  await rien(f);

  await f.cliquer(HH + ' [data-opcrb="ca"]', { apres: 1200 });
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
