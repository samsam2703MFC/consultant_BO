/* Promotions › le Coach Vente, étape 4 « La fiche » (?vue=promos), et sa fonction en chapitre (24/11/2026) :
 *   onglet    promos-fiche       la fiche pré-encodée : le nom (vide, « Valider » se grise), ce que le client achète et
 *                                paie, où, quand (la durée), ce qu'elle laisse, ce qui se passe à la validation
 *   fonction  promos-lancer      « ✓ Valider et lancer lundi 23/11 » : la promotion créée au panel, la fiche à l'équipe
 * « Voir la fiche » (le PDF dans un nouvel onglet du navigateur) ne se filme pas. Les liens « Relier la boîte » et
 * « Ajouter l'équipe » n'apparaissent que sans boîte reliée ou sans adresse d'équipe (le magasin de démo a les deux) :
 * une phrase les dit. Données fictives : mocks/12_promotions.js (le lancement rend le bundle n° 2118 au panel).
 * Chaque phrase vient de panel/public/dashboard/bundles.js (phase4, phase5) ou de panel/src/bundles.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-04-fiche', {
    vue: 'promos', serie: 'Promotions', parent: 'promotions',
    sources: ['panel/public/dashboard/bundles.js', 'panel/public/dashboard/bundles.css', 'panel/src/bundles.php'],
    // Le départ : l'étape 3, le premier couple (cookie chocolat + cappuccino) au prix conseillé, 4,90 €.
    avant: async p => {
      await p.locator('.bd-act [data-bdphase="2"]').click(); await p.waitForTimeout(1200);
      await p.locator('.bd-act [data-bdphase="3"]').click(); await p.waitForTimeout(1500);
      // Une marge sous la page (rien d'autre ne change) : le tableau du pied, « Lancés avec le Coach Vente », remonte au-dessus des sous-titres.
      await p.evaluate(() => { document.body.style.paddingBottom = '140px'; });
    },
  });

  await f.onglet({
    id: 'promos-fiche', titre: 'La fiche',
    resume: 'L’étape 4 du Coach Vente : la fiche du bundle, déjà encodée ; vous vérifiez le nom, où et quand, puis vous validez.',
    accroche: 'Déjà encodée : vous vérifiez',
    programme: ['La fiche pré-encodée', 'Le nom, où, quand', 'Ce qu’elle vous laisse', 'Valider et lancer le bundle'],
  });

  await f.montrer('.bd-act [data-bdphase="4"]', 'Étape 4');
  await f.dire('Depuis le prix : « <b>Préparer la fiche du bundle</b> ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bd-act [data-bdphase="4"]', { apres: 1500 });
  f.chapitres[f.chapitres.length - 1].ecran = Date.now() / 1000;   // l'affiche du chapitre : l'écran de l'étape, pas le bouton qui y mène
  await f.montrer('.bd-fiche', 'La fiche');
  await f.dire('Le Coach Vente l’a encodée avec vos prix et vos coûts de recette : vous <b>vérifiez</b>.', 4600);

  /* Le nom */
  await f.montrer('.bd-fiche .bd-l4:nth-child(1)', 'Le nom');
  await f.dire('Le <b>nom</b> est proposé selon le moment de la journée : « Duo du goûter ». Il se remplace.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bdnom]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.p.keyboard.press('Backspace');
  await f.p.locator('[data-bdnom]').dispatchEvent('input');
  await f.pause(600);
  await f.montrer('.bd-act [data-bd="lancer"]', 'Grisé');
  await f.dire('Sans nom, « <b>Valider</b> » se grise.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bdnom]', { apres: 200 });
  await f.taper('Duo du goûter', 90);
  await f.pause(500);

  /* Ce que le client achète et paie */
  await f.montrerTout('.bd-fiche .bd-l4:nth-child(2), .bd-fiche .bd-l4:nth-child(3)', 'Ce qu’il achète, ce qu’il paie');
  await f.dire('Le client achète un cookie chocolat et un cappuccino, sur le même ticket : <b>4,90 €</b> au lieu de 5,40 €.', 5400);
  await f.cacher(150); await f.taire(150);

  /* Où */
  await f.cliquer('[data-bdcanal="comptoir"]', { apres: 800 });
  await f.montrer('.bd-fiche .bd-l4:nth-child(4)', 'Où');
  await f.dire('<b>Où</b> : au comptoir et sur le webshop, ou l’un des deux.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bdcanal="tous"]', { apres: 700 });

  /* Quand */
  await f.survoler('[data-bdduree]', { pause: 400 });
  await f.p.locator('[data-bdduree]').selectOption('2s');
  await f.pause(900);
  await f.montrer('.bd-fiche .bd-l4:nth-child(5)', 'Quand');
  await f.dire('<b>Quand</b> : 2 semaines, 1 mois ou 2 mois, toujours à partir du lundi qui suit la validation. Les dates suivent.', 5800);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-bdduree]', { pause: 300 });
  await f.p.locator('[data-bdduree]').selectOption('1m');
  await f.pause(800);

  /* Ce qu'elle laisse, la validation */
  await f.montrer('.bd-cote', 'Ce qu’elle vous laisse');
  await f.dire('À droite : la marge du bundle, son coefficient, et combien de bundles il faut pour garder votre marge.', 5200);
  await f.montrer('.bd-envoi', 'À la validation');
  await f.dire('À la validation, <b>la promotion est créée au panel</b>, et sa fiche part par e-mail à l’équipe.', 4800);
  await f.dire('Sans boîte mail reliée, ou sans adresse d’équipe, un lien ici mène à la Boîte mail ou aux Paramètres.', 5400);
  await f.taire(200); await f.cacher(300);

  /* Valider et lancer */
  await f.fonction({ id: 'promos-lancer', titre: 'Valider et lancer le bundle', resume: '« Valider et lancer » : la promotion est créée au panel, en caisse le lundi qui suit, et l’équipe reçoit sa fiche.',
    points: ['Relire la fiche', 'Laisser cochée « La fiche part par e-mail »', '« ✓ Valider et lancer lundi 23/11 »'] });
  await f.montrer('.bd-fiche', 'Duo du goûter');
  await f.dire('La fiche est prête : « Duo du goûter », 4,90 €, du lundi 23 novembre au mardi 22 décembre.', 4800);
  await f.montrer('.bd-envoi .bd-coche', 'La fiche à l’équipe');
  await f.dire('Cochée, la fiche de la promotion part aux 6 personnes de l’équipe, depuis la boîte du magasin.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bd-act [data-bd="lancer"]', { apres: 1800 });
  await f.montrer('.bd-ok-h', 'Lancé');
  await f.dire('« Duo du goûter » est lancé : créé au panel, en caisse <b>lundi 23 novembre</b> à l’ouverture.', 5000);
  await f.montrer('.bd-deux', 'Où en est la promotion');
  await f.dire('La fiche est partie aux 6 personnes de l’équipe, depuis la boîte du magasin.', 4200);
  await f.montrer('.bd-suivi tbody tr:first-child', 'À venir');
  await f.dire('Le bundle s’ajoute aux « Lancés avec le Coach Vente » : à venir jusqu’à lundi.', 4400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
