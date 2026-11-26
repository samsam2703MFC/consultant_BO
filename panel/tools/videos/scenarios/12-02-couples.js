/* Promotions › le Coach Vente, étape 2 « Les couples » (?vue=promos), la vidéo de l'onglet (24/11/2026) :
 *   onglet    promos-couples     chaque couple (séparés, puis en bundle), la marge du ticket, le gain par mois, le
 *                                moment ; choisir le couple à lancer, régler la prise
 * Aucune fonction n'écrit à cette étape (choisir un couple, régler la prise : l'écran se recalcule, rien n'est gardé) :
 * pas de chapitre de fonction. Données fictives : mocks/12_promotions.js. Chaque phrase vient de
 * panel/public/dashboard/bundles.js (phase2) ou de panel/src/bundles.php (bndCouples). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-02-couples', {
    vue: 'promos', serie: 'Promotions', parent: 'promotions',
    sources: ['panel/public/dashboard/bundles.js', 'panel/public/dashboard/bundles.css', 'panel/src/bundles.php'],
    // Le départ : l'étape 1, le bouton « Voir les couples » à l'écran.
    avant: async p => { await p.evaluate(() => { const b = document.querySelector('.bd-act'); window.scrollTo(0, b.getBoundingClientRect().top + window.scrollY - 520); }); await p.waitForTimeout(400); },
  });

  await f.onglet({
    id: 'promos-couples', titre: 'Les couples',
    resume: 'L’étape 2 du Coach Vente : chaque couple séparé puis en bundle, la marge du ticket, le gain par mois, le moment ; choisir le couple à lancer.',
    accroche: 'Un produit qui attire, un produit qui rapporte',
    programme: ['Chaque couple, séparé puis en bundle', 'La marge du ticket', 'Le gain par mois et le moment', 'Choisir le couple à lancer', 'Régler la prise'],
  });

  await f.montrer('.bd-act [data-bdphase="2"]', 'Étape 2');
  await f.dire('Depuis le constat : « <b>Voir les couples qui montent la marge</b> ».', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bd-act [data-bdphase="2"]', { apres: 1500 });
  f.chapitres[f.chapitres.length - 1].ecran = Date.now() / 1000;   // l'affiche du chapitre : l'écran de l'étape, pas le bouton qui y mène
  await f.montrer('.bd-tab', 'Les couples');
  await f.dire('Chaque ligne : un produit qui attire mais rapporte peu, plus un produit à grande valeur ajoutée, d’une autre famille.', 5600);
  await f.montrerTout('.bd-tab th:nth-child(5), .bd-tab td:nth-child(5)', 'Séparés → bundle');
  await f.dire('Les deux séparés, puis le bundle au <b>prix conseillé, −10 %</b>, arrondi à 10 centimes.', 4600);
  await f.montrerTout('.bd-tab th:nth-child(6), .bd-tab td:nth-child(6), .bd-tab th:nth-child(7), .bd-tab td:nth-child(7)', 'La marge du ticket');
  await f.dire('La <b>marge du ticket</b> : celle du produit seul, barrée, puis celle du bundle. « En plus / ticket » : ce qu’il rapporte de plus.', 6000);
  await f.montrerTout('.bd-tab th:nth-child(8), .bd-tab td:nth-child(8), .bd-tab th:nth-child(9), .bd-tab td:nth-child(9)', 'Par mois · le moment');
  await f.dire('<b>Par mois</b> : la marge en plus et le nombre de bundles. Et <b>le moment</b> de la journée où les deux se vendent.', 5600);
  await f.cacher(150); await f.taire(150);

  /* Choisir le couple à lancer */
  await f.cliquer('.bd-tab [data-bdsel="1"]', { apres: 1000 });
  await f.montrer('.bd-tab tr[data-bdsel="1"]', 'Le couple choisi');
  await f.dire('Un clic choisit le <b>couple à lancer</b> : le nuage, le prix et la fiche suivent ce couple.', 4600);
  await f.montrer('.bd-bilan', 'Par mois');
  await f.dire('Dessous, pour ce couple : la marge en plus par mois, et combien de bundles par jour.', 4400);
  await f.cacher(150); await f.taire(150);

  /* Régler la prise */
  await f.survoler('[data-bdprise]', { pause: 400 });
  await f.p.locator('[data-bdprise]').selectOption('0.2');
  await f.pause(900);
  await f.montrer('.bd-bilan', '1 client sur 5');
  await f.dire('La <b>prise</b> est une hypothèse : 1 client sur 20, sur 10 ou sur 5. Les bundles et la marge par mois se recalculent.', 6000);
  await f.montrer('.bd-note', 'L’hypothèse');
  await f.dire('« En plus / ticket » vaut pour le client qui ne prenait que le produit qui attire ; celui qui prenait déjà les deux paie la remise.', 6200);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-bdprise]', { pause: 300 });
  await f.p.locator('[data-bdprise]').selectOption('0.1');
  await f.pause(700);

  await f.montrer('.bd-act [data-bdphase="3"]', 'Étape 3');
  await f.dire('« <b>Régler le prix</b> » passe à l’étape 3, avec le couple choisi.', 3800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
