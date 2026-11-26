/* Offres › Newsletter › Templates (?vue=offres, onglet « Newsletter »), 24/11/2026 :
 *   onglet    offres-nl-templates   les modèles prêts, lus comme le mail ; « Utiliser » ouvre l'assistant à l'étape Texte
 * Aucune fonction en chapitre : rien ne s'écrit ici (« Utiliser » ne fait qu'ouvrir l'assistant ; la campagne se
 * programme à l'étape Envoi, filmée dans 13-13-envoi).
 * Données fictives : mocks/13_offres.js. Chaque phrase vient de marketing/public/assets/js/newsletter.js (vTemplates,
 * TEMPLATES, I18N fr, onClick « use »). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-08-templates', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const carte = nom => f.p.locator('.nl-body > div:nth-child(2) > div').filter({ hasText: nom }).first();

  await f.onglet({
    id: 'offres-nl-templates', titre: 'Newsletter : les templates',
    resume: 'Les modèles prêts de la newsletter : un visuel, un texte court, un seul bouton, le même pied de page ; « Utiliser » part d’un modèle.',
    accroche: 'Un modèle, un mail',
    programme: ['Les modèles', 'Lire un modèle', 'Partir d’un modèle'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.cliquer('.nl-top [data-a="view"][data-v="templates"]', { apres: 900 });
  await f.montrer('.nl-body > div:first-child', 'Templates');
  await f.dire('« <b>Templates</b> » : les modèles prêts. Chacun : un visuel, un texte court, un seul lien ; le pied de page est commun.', 6000);
  await f.montrer(carte('Annonce produit saisonnier'), 'Un modèle');
  await f.dire('Chaque modèle se lit comme le mail : l’expéditeur et l’objet, le visuel, le titre, le texte, le bouton.', 5400);
  await f.cacher(250);
  await f.dire('Annonce d’un produit de saison, happy hour, fidélité progressive, « vous avez gagné »…', 4600);
  await f.montrer(carte('Fidélité progressive'), 'Fidélité progressive');
  await f.dire('La <b>fidélité progressive</b> porte le badge du 3e mail ; elle et « vous avez gagné » donnent un code voucher.', 5600);
  await f.cacher(250);
  await f.defiler(600);
  await f.montrer(carte('Plateau bureau'), 'Plateau bureau');
  await f.dire('…et le <b>plateau bureau</b>, pour les clients office et B2B.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);
  await f.montrer('[data-a="use"][data-v="happy"]', 'Utiliser');
  await f.dire('« <b>Utiliser</b> », au-dessus d’un modèle, part de ce modèle.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="use"][data-v="happy"]', { apres: 1100 });
  await f.montrer('.nl-body > div:first-child', 'Étape 3 · Texte');
  await f.dire('L’assistant « Nouvelle campagne » s’ouvre directement à l’étape <b>Texte</b>, avec ce modèle.', 4800);
  await f.montrer('.nl-sticky', 'L’aperçu');
  await f.dire('À droite, l’<b>aperçu mobile</b> montre déjà le mail ; il reste à écrire, puis à programmer.', 4800);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
