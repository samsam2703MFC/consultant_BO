/* Opérationnel › « Fiche de stock d'un produit », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-fiche-stock   un produit de La vitrine : la réponse d'abord (recuire combien, dans quelle cuisson), le
 *                             calcul, la courbe de la journée, les cuissons du jour, sans et avec recuisson, « la fiche
 *                             du produit » ; un long life : de quoi tenir trois jours de vente ; Échap pour fermer.
 * Ouvrir la fiche et la lire : des gestes de lecture, sans chapitre à eux (« Savoir combien recuire » de la liste se
 * montre ici). Données fictives : /production/flux/suivi dans mocks/10_ops.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (vitStock, stkCourbe, stkRendre).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

module.exports = async function () {
  const f = await ouvrir('01-09-fiche-stock');

  await f.onglet({
    id: 'ops-fiche-stock', titre: 'La fiche de stock d’un produit',
    resume: 'Un clic sur un produit de La vitrine : faut-il recuire, et combien ? Le calcul, la courbe de la vitrine et les cuissons du jour ; pour un long life, de quoi tenir trois jours.',
    accroche: 'Recuire, et combien',
    programme: ['La réponse d’abord', 'Le calcul', 'La courbe et les cuissons', 'Un long life'],
  });

  await enHautDe(f, '.vt', 16);
  await f.cliquer('[data-vitb="S"]', { apres: 900, dx: 0.1 });
  await f.montrer('.vt-b.on [data-vstk="100"]', 'Croissant au beurre');
  await f.dire('Un clic sur un produit de La vitrine ouvre sa <b>fiche de stock</b>.', 3600);
  await rien(f);
  await f.cliquer('.vt-b.on [data-vstk="100"]', { apres: 1300, dx: 0.2 });

  await dire(f, '#db-stk .sk-rep', 'La réponse', 'La réponse d’abord : <b>recuire 22 pièces</b> dans la cuisson de 16:30.', 4400);
  await dire(f, '#db-stk .sk-rep .eq', 'Le calcul', 'Le calcul : ce qui se vendra d’ici la fermeture, moins la vitrine et ce qui est déjà prévu.', 5200);
  await dire(f, '#db-stk .sk-duo > div:first-child', 'La journée du produit', 'La courbe : la moyenne des 6 derniers jeudis, le vendu du jour, la vitrine, et la vitrine avec la recuisson.', 6000);
  await dire(f, '#db-stk .sk-cu', 'Les cuissons du jour', 'Les <b>cuissons du jour</b>, la recuisson proposée en vert ; dessous, la soirée sans recuisson et avec.', 5600);
  await dire(f, '#db-stk .sk-fi', 'La fiche du produit', '« <b>la fiche du produit</b> » ouvre ses ventes, son prix et sa recette.', 3800);
  await rien(f);
  await f.p.keyboard.press('Escape'); await f.pause(800);

  await f.cliquer('[data-vitb="L"]', { apres: 900, dx: 0.1 });
  await f.cliquer('.vt-b.on [data-vstk="132"]', { apres: 1300, dx: 0.2 });
  await dire(f, '#db-stk .sk-rep', 'Long life', 'Un <b>long life</b> : de quoi tenir trois jours de vente, moins ce qui est en stock.', 4800);
  await rien(f);
  await f.montrer('#db-stk .fi-x', 'Fermer');
  await f.dire('× ou Échap ferme la fiche.', 2400);
  await rien(f);
  await f.p.keyboard.press('Escape'); await f.pause(1200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
