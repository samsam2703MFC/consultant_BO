/* Offres › Newsletter › Nouvelle campagne, étape 1 « Segment » (?vue=offres, onglet « Newsletter »), 24/11/2026 :
 *   onglet    offres-nl-segment   la base, le segment, le compteur opt-in, les langues d'envoi
 * Aucune fonction en chapitre : choisir les destinataires n'écrit rien (la campagne s'écrit à l'étape Envoi,
 * 13-13-envoi).
 * Données fictives : mocks/13_offres.js (deux bases, trois segments). Chaque phrase vient de
 * marketing/public/assets/js/newsletter.js (vWizard, step1, I18N fr). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-10-segment', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const carte = '.nl-wiz > div:first-child';
  const bloc = n => carte + ' > :nth-child(' + n + ')';   // 1 titre, 2 base, 3 segments, 4 « seule la marque… », 5 langues

  await f.onglet({
    id: 'offres-nl-segment', titre: 'Nouvelle campagne · 1 Segment',
    resume: 'À qui on écrit : la base, le segment, le nombre de destinataires opt-in, les langues d’envoi.',
    accroche: 'À qui écrit-on ?',
    programme: ['La base', 'Le segment', 'Les langues d’envoi'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.montrer('[data-a="wizard"]', 'Nouvelle campagne');
  await f.dire('« <b>Nouvelle campagne</b> » ouvre l’assistant.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="wizard"]', { apres: 1000 });
  await f.montrer('.nl-body > div:first-child > div:last-child', 'Quatre étapes');
  await f.dire('Quatre étapes : le <b>segment</b>, le template, le texte, l’envoi.', 3800);
  await f.montrer(bloc(2), 'La base');
  await f.dire('D’abord la <b>base</b> : clients individuels ou clients B2B. Le compteur ne compte que les contacts opt-in.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="source"][data-v="b2b"]', { apres: 800 });
  await f.montrer(bloc(3), 'Ses segments');
  await f.dire('Chaque base a ses segments : en B2B, tous les comptes actifs.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="source"][data-v="indiv"]', { apres: 800 });
  await f.cliquer('[data-a="segment"][data-v="dormant"]', { apres: 800 });
  await f.montrer(bloc(3), 'Le segment');
  await f.dire('Puis le <b>segment</b> : par exemple, pas d’achat depuis 45 jours — 214 destinataires.', 4800);
  await f.montrer(bloc(4), 'Réservé à la marque');
  await f.dire('Seule la marque peut créer un segment ; le magasin choisit parmi les siens et ceux du réseau.', 5000);
  await f.cacher(250);
  await f.montrer(bloc(5), 'Les langues d’envoi');
  await f.dire('Les <b>langues d’envoi</b> : chaque client reçoit le mail dans la langue de sa fiche.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="lang-toggle"][data-v="nl"]', { apres: 800 });
  await f.montrer(bloc(5), 'Sans le néerlandais');
  await f.dire('Décocher une langue exclut ces clients : le compteur de destinataires suit.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="lang-toggle"][data-v="nl"]', { apres: 700 });
  await f.montrer('.nl-sticky', 'L’aperçu');
  await f.dire('À droite, dès cette étape, l’<b>aperçu mobile</b> du mail.', 3400);
  await f.montrer('[data-a="next"]', 'Continuer');
  await f.dire('« Continuer » passe au template.', 2600);
  await f.taire(150); await f.cacher(300);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
