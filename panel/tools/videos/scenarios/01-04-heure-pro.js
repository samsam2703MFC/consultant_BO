/* Opérationnel › « Heure par heure : comptoir et pro séparés · combinés », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-heure-comptoir-pro   séparés : le comptoir face à l'objectif comptoir, le pro en marches face à la part
 *                                    B2B habituelle ; combinés : une courbe par journée face à l'objectif du jour ;
 *                                    le choix gardé dans ce navigateur.
 * La bascule est un geste de lecture : pas de chapitre de fonction. Données fictives : mocks/10_ops.js. Chaque phrase
 * vient de panel/public/dashboard/dashboard.js (opDuelHeures, opDuelBase, S.opPro gardé sous db.opPro).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const HH = '.op-bloc:has(.op-crb-w)';

module.exports = async function () {
  const f = await ouvrir('01-04-heure-pro');

  await f.onglet({
    id: 'ops-heure-comptoir-pro', titre: 'Heure par heure : comptoir et pro',
    resume: 'La bascule de la carte « Heure par heure » : le comptoir et les clients pro séparés, chacun face à sa part de l’objectif, ou combinés, face à l’objectif du jour.',
    accroche: 'Le B2B à part, ou avec le reste',
    programme: ['Comptoir et pro séparés', 'Combinés', 'Le choix reste'],
  });

  await enHautDe(f, HH, 44);
  await dire(f, HH + ' [data-oppro="sep"]', 'Séparés', '<b>Comptoir et pro séparés</b> : la vente au comptoir d’un côté, les clients pro de l’autre.', 4800);
  await dire(f, HH + ' .op-crb-w', 'Le comptoir', 'Le comptoir monte heure par heure, face à l’<b>objectif comptoir</b>.', 4000);
  await f.dire('Le pro tombe par tickets : sa courbe monte <b>en marches</b>, face à la part B2B habituelle.', 4800);
  await dire(f, HH + ' .op-dleg', 'Quatre courbes', 'Quatre courbes : aujourd’hui et jeudi dernier, au comptoir et en pro.', 4000);
  await rien(f);

  await f.cliquer(HH + ' [data-oppro="combi"]', { apres: 1000 });
  await dire(f, HH + ' .op-crb-w', 'Combinés', '<b>Combinés</b> : une courbe par journée, tous les tickets ensemble, face à l’objectif du jour entier.', 5400);
  await rien(f);
  await f.cliquer(HH + ' [data-opcrb="pan"]', { apres: 1000 });
  await dire(f, HH + ' .op-crb-w', 'Panier', 'En <b>Panier</b> aussi : combinés, tous les tickets ; séparés, le panier du comptoir seul.', 5000);
  await rien(f);
  await f.cliquer(HH + ' [data-oppro="sep"]', { apres: 1000 });
  await dire(f, HH + ' [data-oppro="sep"]', 'Le choix reste', 'Le choix reste d’une visite à l’autre, dans ce navigateur.', 3600);
  await rien(f);
  await f.cliquer(HH + ' [data-opcrb="ca"]', { apres: 1400 });
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
