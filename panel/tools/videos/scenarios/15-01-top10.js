/* Concurrence, l'onglet « Top 10 réseau » (?vue=concurrence), 26/11/2026 :
 *   onglet    concurrence-top10   ouvert par défaut : les tuiles (au prix du marché, au-dessus, en dessous, sans relevé),
 *                                 le tableau (nous, les médianes, la fourchette, la position, les relevés, l'écart, le
 *                                 verdict) ; « Les concurrents du magasin, par code postal » déplié ; un clic sur une
 *                                 ligne ouvre la fiche du produit
 * Aucune fonction en chapitre : l'onglet se lit (ouvrir un produit et déplier les codes postaux sont des gestes de
 * lecture ; le relevé terrain se filme dans 15-02, 15-03 et 15-04). Depuis le 25/11/2026 le tableau tient dans son
 * cadre : plus de défilement de côté. Données fictives : mocks/15_concurrence.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (rendConcurrence, rendTop10, bmParCp) ou de consultant/src/benchmark.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('15-01-top10', {
    vue: 'concurrence', serie: 'Concurrence', parent: 'concurrence',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/benchmark.php'],
  });

  await f.onglet({
    id: 'concurrence-top10', titre: 'Le top 10 du réseau',
    resume: 'Les 10 produits les plus vendus du réseau face aux concurrents du magasin : les tuiles, les médianes, la position, l’écart et le verdict ; les concurrents par code postal.',
    accroche: 'Nos meilleures ventes, face au marché',
    programme: ['Les tuiles', 'Le tableau, produit par produit', 'Les concurrents par code postal'],
  });
  await f.montrer('.bm-choix', 'Ce qu’on compare');
  await f.dire('La page s’ouvre sur le <b>top 10 du réseau</b> ; à côté, le cougnou et la galette des rois.', 4600);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.db-tuiles', 'En un coup d’œil');
  await f.dire('En tête : les pièces vendues par les 4 magasins en 3 mois, puis combien de produits sont au prix du marché, au-dessus, en dessous.', 6600);
  await f.montrer('.db-tuiles .db-tui:nth-child(2)', 'Au prix du marché');
  await f.dire('<b>Au prix du marché</b> : à ± 5 % de la médiane des boulangeries.', 3600);
  await f.montrerTout('.db-tuiles .db-tui:nth-child(n+5)', 'À relever');
  await f.dire('Les relevés de ce magasin dans TFBuddy, et les produits encore <b>sans relevé</b>, à relever sur le terrain.', 5400);
  await f.cacher(300); await f.taire(150);

  await f.montrer('.bm-tab', 'Le tableau');
  await f.dire('Pour chaque produit : notre prix, la médiane des boulangeries, celle des supermarchés, et la fourchette.', 5200);
  await f.montrer('.bm-tab tbody tr:nth-child(5) td:nth-child(7)', 'La position');
  await f.dire('La <b>position</b> : chaque point gris est un concurrent, le trait Ruby notre prix.', 4000);
  await f.montrer('.bm-tab tbody tr:nth-child(5) td:nth-child(8)', 'Les relevés');
  await f.dire('Les relevés : les boulangeries, <b>+</b> les supermarchés.', 3200);
  await f.montrerTout('.bm-tab tbody td:nth-last-child(-n+2)', 'Écart et verdict');
  await f.dire('L’<b>écart</b> et le <b>verdict</b> se lisent face aux boulangeries — artisans, chaînes, stations —, hors supermarchés.', 6000);
  await f.montrer('.bm-tab tbody tr:nth-child(10)', 'Pas de relevé');
  await f.dire('Le pain de campagne n’a pas encore de relevé : rien à comparer.', 3600);
  await f.cacher(300); await f.taire(150);

  await f.montrer(f.p.locator('.db-card', { hasText: 'par code postal' }).first(), 'Par code postal');
  await f.dire('Plus bas, <b>les concurrents du magasin, par code postal</b> : la zone du magasin et ses compteurs.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmcp]', { apres: 1000 });
  await f.montrer(f.p.locator('.db-card', { hasText: 'par code postal' }).first(), 'La liste');
  await f.dire('Un clic déplie la liste : dans la zone, zone d’un autre magasin, hors zone, nationaux.', 4800);
  await f.dire('La zone se règle dans le cockpit, Benchmark.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmcp]', { apres: 900 });
  await f.defiler(-3000);

  await f.survoler('[data-bmtop="122"]', { pause: 300 });
  await f.dire('Un clic sur une ligne ouvre la <b>fiche du produit</b>…', 2800);
  await f.taire(150);
  await f.cliquer('[data-bmtop="122"]', { apres: 1300 });
  await f.montrer('.bm-choix', 'Son nom, en haut');
  await f.dire('… et son nom s’ajoute aux boutons du haut. « <b>Top 10 réseau</b> » y ramène.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bmf="top"]', { apres: 1000 });
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
