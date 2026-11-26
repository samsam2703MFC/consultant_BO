/* Opérationnel › « Fenêtre d'une catégorie ou d'un groupe : 12 dernières semaines · La journée (CA · Pièces) », la
 * vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-fenetre-categorie   le « i » d'un groupe : les 12 dernières semaines face au réseau, la tendance, CA ou
 *                                   pièces ; « La journée » heure par heure face aux 6 derniers jeudis, une heure
 *                                   cliquée ; le « i » d'une catégorie ouvre la même fenêtre.
 * Tout se lit (« Suivre la tendance d'une catégorie ») : pas de chapitre de fonction. Données fictives :
 * mocks/10_ops_categories.js (/analyse/categories/magasin et /journee, que la fausse API ne servait pas). Chaque phrase
 * vient de panel/public/dashboard/dashboard.js (c12Ouvrir, c12Semaines, c12Journee, c12Rendre, ficheTips).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const CAT = '.db-card:has(.db-cvue)';
const FI = '#db-fiche .fi-modale';

module.exports = async function () {
  const f = await ouvrir('01-10-categorie', { sources: ['consultant/src/analyse_produits.php'] });

  await f.onglet({
    id: 'ops-fenetre-categorie', titre: 'La fenêtre d’une catégorie',
    resume: 'Le « i » à côté d’un groupe ou d’une catégorie : la tendance des 12 dernières semaines face au réseau, puis la journée heure par heure face aux 6 derniers mêmes jours, en euros ou en pièces.',
    accroche: 'Une catégorie monte-t-elle ?',
    programme: ['12 dernières semaines', 'La tendance', 'La journée, heure par heure', 'CA ou pièces'],
  });

  await enHautDe(f, CAT, 16);
  await dire(f, '[data-c12="Pâtisserie"]', 'i', 'À côté de chaque groupe et de chaque catégorie : le « <b>i</b> ».', 3600);
  await rien(f);
  await f.cliquer('[data-c12="Pâtisserie"]', { apres: 1500 });

  await dire(f, FI + ' .fi-deux > div:first-child', '12 dernières semaines', '<b>12 dernières semaines</b> : le magasin en bleu, la moyenne par magasin du réseau en orange.', 5200);
  await f.dire('En pointillé, la tendance du magasin ; sous l’axe, l’écart de chaque semaine à la précédente.', 5000);
  await dire(f, FI + ' .fi-cote', 'La tendance', 'À droite : les 4 dernières semaines closes face aux 4 d’avant, le réseau au même calcul.', 5200);
  await rien(f);
  await f.cliquer(FI + ' [data-c12u="q"]', { apres: 1000 });
  await dire(f, FI + ' .fi-seg', 'Pièces', '<b>Pièces</b> : la même lecture, en nombre de pièces.', 3200);
  await rien(f);

  await f.cliquer(FI + ' [data-c12o="2"]', { apres: 1500 });
  await dire(f, FI + ' .fi-deux > div:first-child', 'La journée', '<b>La journée</b> : chaque heure d’aujourd’hui, face à la moyenne des 6 derniers jeudis.', 5000);
  await rien(f);
  await f.cliquer(f.p.locator(FI + ' .fi-gw .hit').nth(6), { apres: 900 });
  await f.dire('Un clic sur une heure : sa valeur.', 2600);
  await dire(f, FI + ' .fi-cote', 'Le côté', 'À droite : le vendu, l’écart, l’heure forte, la part d’avant midi.', 4400);
  await rien(f);
  await f.p.keyboard.press('Escape'); await f.pause(700);

  await f.cliquer('[data-cacc="g:Pâtisserie"]', { apres: 700, dx: 0.04 });
  await f.cliquer('[data-c12="Tartes"]', { apres: 1500 });
  await dire(f, FI + ' .fi-hd', 'Tartes', 'Le « i » d’une catégorie ouvre la même fenêtre, pour elle seule.', 4000);
  await rien(f);
  await f.p.keyboard.press('Escape'); await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
