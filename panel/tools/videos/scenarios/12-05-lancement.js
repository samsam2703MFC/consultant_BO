/* Promotions › le Coach Vente, étape 5 « Le lancement » (?vue=promos), et sa fonction en chapitre (24/11/2026) :
 *   onglet    promos-lancement   le bundle lancé : où en est la promotion (n° au panel, fiche envoyée, en caisse, fin),
 *                                à qui la fiche est partie, « Lancés avec le Coach Vente », « Créer un autre bundle »
 *   fonction  promos-renvoyer    « Renvoyer à l'équipe » : la fiche repart, un message dit à combien de personnes
 * « Télécharger la fiche (PDF) » ouvre un nouvel onglet du navigateur : il ne se filme pas. « Créer un autre bundle »
 * ramène aux couples (l'étape 2, sa vidéo) : il est montré, pas cliqué (l'étape 5 ne se rouvre qu'en lançant).
 * Données fictives : mocks/12_promotions.js ; avant de filmer, on lance « Duo du goûter » (cookie chocolat +
 * cappuccino, 4,90 €). Chaque phrase vient de panel/public/dashboard/bundles.js (phase5, lances, renvoyer) ou de
 * panel/src/bundles.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-05-lancement', {
    vue: 'promos', serie: 'Promotions', parent: 'promotions',
    sources: ['panel/public/dashboard/bundles.js', 'panel/public/dashboard/bundles.css', 'panel/src/bundles.php'],
    // Le départ : le bundle « Duo du goûter » validé et lancé, l'étape 5 en haut de l'écran.
    avant: async p => {
      for (const n of [2, 3, 4]) { await p.locator('.bd-act [data-bdphase="' + n + '"]').click(); await p.waitForTimeout(1200); }
      await p.locator('.bd-act [data-bd="lancer"]').click(); await p.waitForTimeout(1800);
      // Une marge sous la page (rien d'autre ne change) : le tableau du pied, « Lancés avec le Coach Vente », remonte au-dessus des sous-titres.
      await p.evaluate(() => { document.body.style.paddingBottom = '140px'; const b = document.querySelector('.bd-ok-h'); window.scrollTo(0, b.getBoundingClientRect().top + window.scrollY - 90); });
      await p.waitForTimeout(500);
    },
  });

  await f.onglet({
    id: 'promos-lancement', titre: 'Le lancement',
    resume: 'L’étape 5 du Coach Vente : le bundle lancé, où en est la promotion, à qui la fiche est partie, et le suivi des bundles lancés.',
    accroche: 'Lancé : en caisse lundi',
    programme: ['Où en est la promotion', 'La fiche de l’équipe', 'Lancés avec le Coach Vente', 'Renvoyer la fiche à l’équipe'],
  });

  await f.montrer('.bd-ok-h', 'Lancé');
  await f.dire('Après « Valider et lancer », l’étape 5 : « Duo du goûter » est lancé.', 4000);
  await f.dire('1 cookie chocolat + 1 cappuccino pour 4,90 €, <b>en caisse</b> du lundi 23 novembre au mardi 22 décembre au soir.', 5600);
  await f.montrer('.bd-deux > div:first-child', 'Où en est la promotion');
  await f.dire('Où en est la promotion : validée et créée au panel, avec son numéro, puis la fiche envoyée à l’équipe.', 5400);
  await f.dire('Restent l’entrée en caisse, lundi à l’ouverture, et la fin de la promotion.', 4200);
  await f.dire('Ses utilisations se liront dans « Où les promotions sont utilisées », dès qu’elle passe en caisse.', 4800);
  await f.montrer('.bd-deux > div:nth-child(2)', 'La fiche de l’équipe');
  await f.dire('À droite : à qui la <b>fiche de l’équipe</b> est partie, adresse par adresse.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.bd-suivi', 'Lancés avec le Coach Vente');
  await f.dire('Tout en bas, chaque bundle lancé : à venir, en caisse ou terminé ; sa période, son prix, sa fiche.', 5200);
  await f.dire('« Duo du goûter » est à venir ; « Duo du midi », lancé plus tôt, est en caisse jusqu’au 8 décembre.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.bd-act', 'Ensuite');
  await f.dire('« <b>Créer un autre bundle</b> » ramène aux couples. Une erreur ? La promotion se modifie au cockpit, ou par une demande au bureau.', 6400);
  await f.taire(200); await f.cacher(300);

  /* Renvoyer la fiche à l'équipe */
  await f.fonction({ id: 'promos-renvoyer', titre: 'Renvoyer la fiche à l’équipe', resume: '« Renvoyer à l’équipe » : la fiche du bundle repart par e-mail, depuis la boîte du magasin.',
    points: ['Le bundle lancé', '« Renvoyer à l’équipe »', 'Le message : à combien de personnes'] });
  await f.montrer('.bd-deux > div:nth-child(2)', 'La fiche de l’équipe');
  await f.dire('La fiche du bundle repart aux adresses de l’équipe, celles de Paramètres › Mails et automatisations.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bdrenvoyer]', { apres: 1200 });
  // Le message s'affiche au-dessus du bundle : on le descend sous le haut de l'écran, son étiquette au-dessus.
  const haut = await f.p.evaluate(() => document.querySelector('.bd-flash').getBoundingClientRect().top);
  if (haut < 80) { await f.defiler(haut - 90); }
  await f.montrer('.bd-flash', 'Renvoyée');
  await f.dire('Un message dit à combien de personnes elle est repartie, depuis la boîte du magasin.', 4400);
  await f.dire('Une fiche qui n’était pas partie au lancement : le même bouton dit « Envoyer à l’équipe ».', 4600);
  await f.montrer('.bd-tl li:nth-child(2)', 'L’heure de l’envoi');
  await f.dire('L’heure de l’envoi se met à jour : 15:41.', 3200);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
