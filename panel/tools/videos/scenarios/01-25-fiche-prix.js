/* La barre du haut › les fenêtres communes › la fiche produit, onglet « Prix face au réseau » (?vue=ops), 26/11/2026 :
 *   onglet    barre-fiche-prix   le nuage prix × volume (ce magasin, les autres magasins, le prix réseau et le volume
 *                                moyen), un clic sur un point, le prix en caisse et encaissé face à la médiane, ce que
 *                                rapporterait le prix réseau ; Dernier mois · 3 derniers mois · 12 mois (comparer son prix
 *                                au réseau : un geste de lecture, montré ici)
 * Aucune fonction en chapitre. Départ (hors film) : Snacking › Sandwichs dépliés. Données fictives : mocks/10_ops.js
 * (lib/fiche.js). Chaque phrase vient de panel/public/dashboard/dashboard.js (ficheRendre onglet 2, ficheNuage). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-25-fiche-prix', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'],
    avant: async p => {
      await p.locator('#dash [data-cacc="g:Snacking"]').click(); await p.waitForTimeout(500);
      await p.locator('#dash [data-cacc="c:Sandwichs"]').click(); await p.waitForTimeout(500);
      await p.evaluate(() => window.scrollTo(0, 0));
    },
  });

  await f.onglet({
    id: 'barre-fiche-prix', titre: 'La fiche produit : le prix face au réseau',
    resume: 'Le deuxième onglet de la fiche : le nuage prix × volume, ce magasin face aux autres, le prix réseau, et ce que rapporterait le prix réseau ; sur le dernier mois, 3 ou 12 mois.',
    accroche: 'Votre prix, parmi les autres',
    programme: ['Le nuage prix × volume', 'Votre prix face à la médiane', 'Ce que rapporterait le prix réseau', 'Changer de période'],
  });
  await f.cliquer('#dash [data-fprod="122"]', { apres: 1400 });
  await f.cliquer('#db-fiche [data-fong="2"]', { apres: 1200 });
  await B.cadre(f, '#db-fiche [data-fong="2"]', 'Prix face au réseau');
  await f.dire('Dans la fiche d’un produit, le deuxième onglet : « <b>Prix face au réseau</b> ».', 4200);
  await B.cadre(f, '#db-fiche .fi-gw', 'Le nuage');
  await f.dire('Le <b>nuage</b> : le prix encaissé, de gauche à droite ; les pièces vendues pour 10 000 € de chiffre, de bas en haut.', 6000);
  await f.dire('Le point bleu, c’est ce magasin ; en gris, les autres. Les traits : le prix réseau et le volume moyen.', 5400);
  await f.dire('Les coins disent la place : moins cher et vend plus, plus cher et vend moins…', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche .fi-gw .hit >> nth=0', { apres: 900 });
  await B.cadre(f, '#db-fiche .fi-tip', 'Un point');
  await f.dire('Un clic sur un point : son prix de vente, son prix encaissé, ses pièces.', 4000);
  await f.cacher(150); await f.taire(150);
  await B.cadre(f, '#db-fiche .fi-cote .r >> nth=0', 'Le prix');
  await f.dire('À droite : le <b>prix de vente en caisse</b>, puis le prix encaissé, le chiffre divisé par les pièces…', 5000);
  await B.cadre(f, '#db-fiche .fi-cote .r >> nth=2', 'La médiane du réseau');
  await f.dire('… face au <b>prix réseau</b>, la médiane, et l’écart.', 3400);
  await B.cadre(f, '#db-fiche .fi-cote .r >> nth=7', 'Au prix réseau');
  await f.dire('« Au prix réseau, par mois » : ce que rapporterait le <b>prix réseau</b>, à volume égal.', 4800);
  await B.cadre(f, '#db-fiche .fi-mg', 'Les magasins');
  await f.dire('Dessous, chaque magasin : son prix de vente, son prix encaissé, son volume.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche [data-fmois="3"]', { apres: 1200 });
  await B.cadre(f, '#db-fiche .fi-seg', 'La période');
  await f.dire('La <b>période</b> : le dernier mois, les 3 derniers mois ou 12 mois ; tout se relit.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche [data-fmois="12"]', { apres: 1200 });
  await f.pause(800);
  await f.cliquer('#db-fiche .fi-x', { apres: 800 });
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
