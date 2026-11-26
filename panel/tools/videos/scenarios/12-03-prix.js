/* Promotions › le Coach Vente, étape 3 « Le prix » (?vue=promos), la vidéo de l'onglet (24/11/2026) :
 *   onglet    promos-prix        la marge du bundle selon son prix, la remise conseillée (8 à 12 %, au-delà de 15 % :
 *                                trop), les quatre tuiles, le garde-fou ; choisir le prix d'une colonne
 * Aucune fonction n'écrit à cette étape (le prix choisi se garde à l'écran, il part avec la fiche) : pas de chapitre
 * de fonction. Données fictives : mocks/12_promotions.js (cookie chocolat + cappuccino : 5,40 € séparés, 4,90 € conseillé).
 * Chaque phrase vient de panel/public/dashboard/bundles.js (phase3, colonnes) ou de panel/src/bundles.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-03-prix', {
    vue: 'promos', serie: 'Promotions', parent: 'promotions',
    sources: ['panel/public/dashboard/bundles.js', 'panel/public/dashboard/bundles.css', 'panel/src/bundles.php'],
    // Le départ : l'étape 2, le premier couple choisi (cookie chocolat + cappuccino).
    avant: async p => { await p.locator('.bd-act [data-bdphase="2"]').click(); await p.waitForTimeout(1500); },
  });

  await f.onglet({
    id: 'promos-prix', titre: 'Le prix',
    resume: 'L’étape 3 du Coach Vente : la marge du bundle selon son prix, la remise conseillée de 8 à 12 %, les quatre tuiles et le garde-fou.',
    accroche: 'Une petite remise, une marge qui monte',
    programme: ['La marge selon le prix', 'La remise conseillée : 8 à 12 %', 'Ce que donne le prix choisi', 'Le garde-fou', 'Changer le prix'],
  });

  await f.montrer('.bd-act [data-bdphase="3"]', 'Étape 3');
  await f.dire('Depuis les couples : « <b>Régler le prix</b> », avec le couple choisi.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bd-act [data-bdphase="3"]', { apres: 1500 });
  f.chapitres[f.chapitres.length - 1].ecran = Date.now() / 1000;   // l'affiche du chapitre : l'écran de l'étape, pas le bouton qui y mène
  await f.montrer('.bd-c3 > div:first-child', 'Selon son prix');
  await f.dire('Chaque colonne : un prix possible du bundle, de 10 en 10 centimes, et la marge qu’il laisse.', 5000);
  await f.dire('En rouge, la <b>remise conseillée : de 8 à 12 %</b>. En saumon, plus de 15 % : trop de remise.', 5000);
  await f.dire('Le pointillé : la marge du cookie chocolat vendu seul. Chaque colonne la dépasse.', 4400);
  await f.montrer('.bd-choix .bd-tuiles', 'À 4,90 €');
  await f.dire('Le prix choisi, ici 4,90 € : ce que gagne le client, la marge du bundle, la <b>marge en plus</b>, et par mois.', 5600);
  await f.montrer('.bd-pq', 'Le garde-fou');
  await f.dire('Le <b>garde-fou</b> : la marge du bundle reste au-dessus de votre marge de base.', 4200);
  await f.cacher(150); await f.taire(150);

  /* Changer le prix : une colonne */
  await f.cliquer('[data-bdprix="4.5"]', { apres: 1200 });
  await f.montrer('.bd-choix', 'À 4,50 €');
  await f.dire('Un clic sur une colonne change le prix : la remise, la marge du bundle, la marge en plus suivent.', 5000);
  await f.montrer('.bd-pq li:first-child', 'Le garde-fou');
  await f.dire('À 4,50 €, −17 % : trop de remise. La marge du bundle passe sous votre marge de base.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bdprix="4.9"]', { apres: 1200 });
  await f.montrer('.bd-choix .bd-tuiles', 'À 4,90 €');
  await f.dire('On revient au prix conseillé : 4,90 €, −9 %.', 3400);
  await f.cacher(150); await f.taire(150);

  await f.montrer('.bd-act [data-bdphase="4"]', 'Étape 4');
  await f.dire('« <b>Préparer la fiche du bundle</b> » passe à l’étape 4, à ce prix.', 3800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
