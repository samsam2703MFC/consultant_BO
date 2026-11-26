/* Offres › Newsletter › Nouvelle campagne, étape 3 « Texte » (?vue=offres, onglet « Newsletter »), 24/11/2026 :
 *   onglet    offres-nl-texte   le canal, la version par langue, l'objet (45 caractères), le texte et « Insérer… »,
 *                               le lien du bouton (« Webshop »), le SMS
 * Aucune fonction en chapitre : écrire le mail, ajouter un SMS ou diriger le bouton vers le webshop n'écrit rien tant
 * que la campagne n'est pas programmée (13-13-envoi).
 * Données fictives : mocks/13_offres.js ; le code du bon webshop (DEMO10) est inventé. Chaque phrase vient de
 * marketing/public/assets/js/newsletter.js (step3, champLien, preview, onChange « insert », I18N fr). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-12-texte', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const carte = '.nl-wiz > div:first-child';
  const bloc = n => carte + ' > :nth-child(' + n + ')';   // 2 canal, 4 objet, 5 texte, 7 lien, 8 SMS (avec « Email + SMS »)

  await f.onglet({
    id: 'offres-nl-texte', titre: 'Nouvelle campagne · 3 Texte',
    resume: 'Le canal (e-mail, SMS ou les deux), une version par langue, l’objet, le texte et ses insertions, le lien du bouton, le SMS.',
    accroche: 'Court, comme au comptoir',
    programme: ['L’objet et le texte', 'Une version par langue', 'Le lien du bouton', 'Le SMS'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.cliquer('[data-a="wizard"]', { apres: 900 });
  await f.cliquer('[data-a="next"]', { apres: 800 });
  await f.cliquer('[data-a="next"]', { apres: 1000 });
  await f.montrer(bloc(2), 'Le canal');
  await f.dire('« Le texte » : court, comme au comptoir. D’abord le <b>canal</b> : e-mail, SMS, ou les deux.', 5000);
  await f.cacher(150); await f.taire(150);

  /* L'objet : au-delà de 45 caractères, l'écran prévient. */
  await f.cliquer('[data-f="subject"]', { apres: 200 });
  await f.p.keyboard.press('Control+A');
  await f.taper('Une tarte diamant vous attend dans votre boutique', 35);
  await f.pause(500);
  await f.montrer(bloc(4), 'L’objet');
  await f.dire('L’<b>objet</b> : au-delà de 45 caractères, l’écran prévient — trop long pour un téléphone.', 4400);
  await f.cacher(150); await f.taire(150);
  for (let i = 0; i < ' dans votre boutique'.length; i++) { await f.p.keyboard.press('Backspace'); await f.pause(35); }
  await f.pause(400);
  await f.montrer(bloc(4), 'Bonne longueur');
  await f.dire('Plus court : « Bonne longueur, ça tient sur mobile ».', 2800);
  await f.cacher(150); await f.taire(150);

  /* Le texte et « Insérer… » */
  const texte = f.p.locator('[data-f="body"]');
  await f.cliquer(texte, { apres: 200 });
  await f.p.keyboard.press('Control+End');
  await f.taper(' Votre code : ', 40);
  await f.survoler('[data-f="insert"]', { pause: 400 });
  await f.p.locator('[data-f="insert"]').selectOption('code');
  await f.pause(700);
  await f.montrer(bloc(5), 'Insérer…');
  await f.dire('Dans le texte, « <b>Insérer…</b> » ajoute le prénom, le code voucher ou un lien.', 3800);
  await f.montrer('.nl-sticky', 'L’aperçu');
  await f.dire('L’aperçu les remplace par un exemple : « Marie », un code.', 2800);
  await f.cacher(150); await f.taire(150);

  /* Une version par langue */
  await f.cliquer('[data-a="editlang"][data-v="nl"]', { apres: 900 });
  await f.montrer('.nl-sticky', 'Aperçu · NL');
  await f.dire('Une <b>version</b> par langue cochée à l’étape 1 : « NL » passe au néerlandais, objet et texte ; l’aperçu suit.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="editlang"][data-v="fr"]', { apres: 600 });

  /* Le lien du bouton : le webshop */
  await f.cliquer('[data-a="lienwebshop"]', { apres: 700 });
  await f.taper('DEMO10', 90);
  await f.pause(500);
  await f.montrer(bloc(7), 'Lien du bouton');
  await f.dire('« <b>Webshop</b> » met l’adresse du webshop du magasin, à compléter avec le code du bon webshop. L’adresse est vérifiée.', 5800);
  await f.cacher(150); await f.taire(150);

  /* Le SMS */
  await f.cliquer('[data-a="channel"][data-v="both"]', { apres: 900 });
  await f.montrer(bloc(8), 'Le SMS');
  await f.dire('« <b>Email + SMS</b> » ajoute le texte du SMS : 160 caractères, STOP obligatoire.', 4000);
  await f.cacher(250);
  await f.montrer('.nl-sticky > div:last-child', 'Aperçu du SMS');
  await f.dire('Et son aperçu, sous celui du mail.', 2600);
  await f.taire(150); await f.cacher(300);
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
