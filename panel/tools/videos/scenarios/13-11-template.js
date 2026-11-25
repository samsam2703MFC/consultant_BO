/* Offres › Newsletter › Nouvelle campagne, étape 2 « Template » (?vue=offres, onglet « Newsletter »), 24/11/2026 :
 *   onglet    offres-nl-template   le type de mail parmi les modèles, l'aperçu mobile qui suit
 * Aucune fonction en chapitre : choisir un modèle n'écrit rien (la campagne s'écrit à l'étape Envoi, 13-13-envoi).
 * Données fictives : mocks/13_offres.js. Chaque phrase vient de marketing/public/assets/js/newsletter.js (step2,
 * TEMPLATES, onClick « template » : 30 vouchers pour un modèle à voucher, I18N fr). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-11-template', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const carte = '.nl-wiz > div:first-child';

  await f.onglet({
    id: 'offres-nl-template', titre: 'Nouvelle campagne · 2 Template',
    resume: 'Le type de mail : cinq modèles prêts ou une page blanche ; l’aperçu mobile, à droite, suit chaque choix.',
    accroche: 'Quel type de mail ?',
    programme: ['Les modèles', 'L’aperçu mobile', 'La page blanche'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.cliquer('[data-a="wizard"]', { apres: 1000 });
  await f.montrer('[data-a="next"]', 'Continuer');
  await f.dire('Le segment choisi, « <b>Continuer</b> » mène à l’étape 2.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="next"]', { apres: 1000 });
  await f.montrer(carte + ' > :nth-child(2)', 'Les modèles');
  await f.dire('« Quel type de mail ? » : <b>cinq modèles</b> prêts, ou une page blanche.', 4200);
  await f.montrer('[data-a="template"][data-v="gagne"]', 'Choisi');
  await f.dire('Le modèle choisi est encadré ; au départ, « Vous avez gagné ».', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="template"][data-v="produit"]', { apres: 900 });
  await f.montrer('.nl-sticky', 'L’aperçu mobile');
  await f.dire('Un clic sur un autre modèle : l’<b>aperçu mobile</b>, à droite, le montre aussitôt — objet, visuel, texte, bouton.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="template"][data-v="office"]', { apres: 900 });
  await f.montrer('.nl-sticky', 'Plateau bureau');
  await f.dire('Le plateau bureau, pour les clients office et B2B.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="template"][data-v="blank"]', { apres: 900 });
  await f.montrer('.nl-sticky', 'Page blanche');
  await f.dire('La <b>page blanche</b> ne garde que l’en-tête et le pied de page : le texte est à écrire.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="template"][data-v="gagne"]', { apres: 900 });
  await f.montrer('[data-a="template"][data-v="gagne"]', 'Avec vouchers');
  await f.dire('Un modèle à voucher, comme « Vous avez gagné », prévoit <b>30 vouchers</b> ; on les règle à l’étape Envoi.', 5400);
  await f.cacher(150);
  await f.montrer(carte + ' > :last-child', 'Retour · Continuer');
  await f.dire('« Retour » revient au segment ; « Continuer » passe au texte.', 3400);
  await f.taire(150); await f.cacher(300);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
