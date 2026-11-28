/* La barre du haut › les fenêtres communes › la fiche produit, onglet « Ventes · 12 semaines » (?vue=ops), 26/11/2026 :
 *   onglet    barre-fiche-ventes   d'Opérationnel, « Ventes par catégorie » : un groupe, une catégorie, un produit ; la
 *                                  fiche (ses pastilles, trois onglets), la courbe face à la moyenne du réseau, un clic
 *                                  sur une semaine, les 6 dernières semaines face aux 6 d'avant
 * Aucune fonction en chapitre : l'onglet ne fait que lire. Données fictives : mocks/10_ops.js (lib/fiche.js).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (ficheOuvrir, ficheRendre onglet 1, ficheCourbe, ficheTips). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-24-fiche-ventes', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'],
  });

  await f.onglet({
    id: 'barre-fiche-ventes', titre: 'La fiche produit : les ventes',
    resume: 'Un produit d’Opérationnel ouvre sa fiche : ses ventes des 12 dernières semaines face à la moyenne du réseau, et les 6 dernières semaines face aux 6 d’avant.',
    accroche: 'Un produit, douze semaines',
    programme: ['Ouvrir la fiche d’un produit', 'La courbe face au réseau', 'La tendance'],
  });
  await f.cliquer('#dash [data-cacc="g:Snacking"]', { apres: 700 });
  await f.dire('Dans « <b>Ventes par catégorie</b> », un groupe s’ouvre sur ses catégories, une catégorie sur ses produits.', 5000);
  await f.cliquer('#dash [data-cacc="c:Sandwichs"]', { apres: 800 });
  await f.montrer('#dash [data-fprod="122"]', 'Un produit');
  await f.dire('Un clic sur un produit ouvre sa <b>fiche</b>.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash [data-fprod="122"]', { apres: 1500 });
  await B.cadre(f, '#db-fiche .fi-chips', 'Aujourd’hui');
  await f.dire('En tête : vendus aujourd’hui, le prix encaissé, le prix réseau, la marge brute, le <b>coefficient</b>.', 5400);
  await B.cadre(f, '#db-fiche .fi-ong', 'Trois onglets');
  await f.dire('Trois onglets ; la fiche s’ouvre sur « <b>Ventes · 12 semaines</b> ».', 3800);
  await B.cadre(f, '#db-fiche .fi-deux > div:first-child', 'La courbe');
  await f.dire('En bleu, le magasin ; en orange, la <b>moyenne par magasin du réseau</b>, semaine par semaine.', 5200);
  await f.dire('La dernière semaine est en cours : quatre jours. Aucun autre magasin n’est nommé.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche .fi-gw .hit >> nth=6', { apres: 900 });
  await B.cadre(f, '#db-fiche .fi-tip', 'Une semaine');
  await f.dire('Un clic sur une semaine : le magasin, le réseau, et l’écart à la semaine d’avant.', 4400);
  await f.cacher(150); await f.taire(150);
  await B.cadre(f, '#db-fiche .fi-cote', 'Douze semaines');
  await f.dire('À droite : les pièces des 12 semaines, la moyenne par semaine…', 3800);
  await B.cadre(f, '#db-fiche .fi-cote .r >> nth=2', 'La tendance');
  await f.dire('… les <b>6 dernières semaines face aux 6 d’avant</b> : la tendance du produit…', 4400);
  await B.cadre(f, '#db-fiche .fi-cote .r >> nth=4', 'Face au réseau');
  await f.dire('… et ses ventes face à la moyenne du réseau.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche .fi-x', { apres: 800 });
  await f.dire('La croix referme la fiche.', 2400);
  await f.taire(150);
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
