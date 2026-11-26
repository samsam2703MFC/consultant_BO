/* Boîte mail › Ranger avec l'IA (dans le volet de la sélection, « Proposer un rangement »), et sa fonction (24/11/2026) :
 *   onglet    boite-ranger-ia   l'IA propose un dossier par e-mail coché, groupé par dossier d'arrivée ; rien ne bouge
 *   fonction  boite-ranger      cocher, « Proposer un rangement », « Changer » pour corriger, « Ranger N e-mails »
 * La proposition seule n'écrit rien ; « Ranger » déplace et crée les dossiers. Données fictives : mocks/70_boite.js
 * (POST /dashboard/boite/ranger : un dossier de la boîte, les Archives, un nouveau dossier « Clients pro », ou laisser).
 * Chaque phrase vient de panel/public/dashboard/boite.js (vueRangement, proposerRangement, appliquerRangement). */
const { Film } = require('../film');
const B = require('./boite');

const COCHER = [1048, 1047, 1046, 1045, 1041, 1039];

module.exports = async function () {
  const f = await Film.ouvrir('20-06-ranger-ia', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php'], avant: B.ouverte() });
  const cocher = async () => { for (const u of COCHER) { await f.cliquer(B.coche(u), { apres: 150 }); } await f.pause(300); };

  /* L'onglet */
  await f.onglet({
    id: 'boite-ranger-ia', titre: 'Ranger avec l’IA',
    resume: 'L’IA propose un dossier pour chaque e-mail coché, groupé par dossier d’arrivée ; chaque proposition se corrige, et rien ne bouge avant « Ranger ».',
    accroche: 'Une proposition, pas une décision', programme: ['Ranger avec l’IA'],
  });
  await cocher();
  await B.enHaut(f);
  await f.montrer('.bx-sel .bx-ia', 'Ranger avec l’IA');
  await f.dire('Six e-mails cochés. Dans la sélection : « <b>Ranger avec l’IA</b> ».', 3800);
  await f.montrer('.bx-ia-h p', 'Ce que lit l’IA');
  await f.dire('L’IA lit l’expéditeur, l’objet et le début de chaque e-mail, et propose un dossier.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bx="ranger"]', { apres: 1400 });
  await B.cadre(f, '.bx-ia--prop', 'La proposition');
  await f.dire('La proposition, <b>groupée par dossier d’arrivée</b> : un dossier de la boîte, les Archives…', 4600);
  await f.montrer('.bx-badge--neuf', 'Nouveau dossier');
  await f.dire('… ou un <b>nouveau dossier</b>, créé seulement si on range.', 3600);
  await f.montrer('.bx-rg--laisser', 'Reste en place');
  await f.dire('Ce qui attend encore une réponse reste en Réception.', 3200);
  await f.montrer('.bx-ia--prop [data-bx="rangAppliquer"]', 'Ranger');
  await f.dire('Chaque ligne se corrige ; <b>rien ne bouge</b> avant « Ranger ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-ia--prop [data-bx="rangAnnuler"]', { apres: 700 });
  await f.dire('« Annuler » : la proposition disparaît, les e-mails n’ont pas bougé.', 3400);
  await f.taire(150);
  await B.enHaut(f);
  await f.cliquer('.bx-sel-h [data-bx="deselect"]', { apres: 600 });

  /* Ranger */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-ranger', titre: 'Ranger avec l’IA',
    resume: 'Cocher (50 e-mails au plus), « Proposer un rangement », corriger avec « Changer » ou en décochant, puis « Ranger N e-mails ».',
    points: ['Cocher les e-mails', '« Proposer un rangement »', '« Changer » ou décocher', '« Ranger N e-mails »'] });
  await cocher();
  await B.enHaut(f);
  await f.cliquer('[data-bx="ranger"]', { apres: 1100 });
  await f.montrer('.bx-rg-l:has([data-bxrp="1039"])', 'À corriger');
  await f.dire('Le goûter de l’asbl du quartier : on le préfère dans « Commandes ». « <b>Changer</b> »…', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxrp="1039"]', { apres: 700 });
  await f.cliquer('.bx-dossiers--palette [data-bxrc="1039"][data-cible="d:INBOX.Commandes"]', { apres: 900 });
  await f.montrer('.bx-ia--prop [data-bx="rangAppliquer"]', 'Ranger');
  await f.dire('Corrigé. Puis « <b>Ranger</b> » : le bouton dit combien d’e-mails, dans combien de dossiers.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-ia--prop [data-bx="rangAppliquer"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Rangé');
  await f.dire('Rangés ; le dossier « <b>Clients pro</b> » est créé dans la boîte.', 3300);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
