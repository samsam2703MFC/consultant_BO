/* Formations et suivi › l'accueil (?vue=formation, la page s'ouvre dessus), un onglet sans fonction qui écrive
 * (26/11/2026) :
 *   onglet    former-accueil   le bandeau « Mon dossier de formation » et ses quatre nombres, « Demander au
 *                              Professeur », la méga-barre et ses catalogues, la phrase et le tableau Apprendre ·
 *                              Comprendre · Faire · Vérifier ; « Parcourir un catalogue » par le nom d'une colonne (lecture)
 * « Ouvrir son dossier de formation » (lecture) se montre dans 22-04, son onglet. Les quatre cartes du bas de l'accueil
 * ne s'affichent qu'au téléphone (seformer.css : .sf-cartes{display:none} hors de l'écran étroit) : pas filmées.
 * Données fictives : mocks/72_formation.js et mocks/lib/manuel.js. Chaque phrase vient de
 * panel/public/dashboard/formation.js (accueil) et seformer.js (COLS, AC_LIGNES, rendreAccueil, chercher). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('22-01-accueil', {
    vue: 'formation', serie: 'Formations et suivi', parent: 'se-former',
    sources: ['panel/public/dashboard/formation.js', 'panel/public/dashboard/seformer.js', 'formation/src/formation_recherche.php'],
  });

  await f.onglet({
    id: 'former-accueil', titre: 'L’accueil de la formation',
    resume: 'Le bandeau « Mon dossier de formation » et ses quatre nombres, la méga-barre et ses catalogues, la phrase et le tableau Apprendre · Comprendre · Faire · Vérifier ; chaque colonne ouvre son catalogue.',
    accroche: 'Tout ce qui s’apprend, à un clic',
    programme: ['Le dossier en quatre nombres', 'La méga-barre et ses catalogues', 'Apprendre, comprendre, faire, vérifier', 'Parcourir un catalogue'],
  });
  await f.montrer('#db-rail .sidebar__item[data-vue=formation]', 'Formations et suivi');
  await f.dire('<b>Formations et suivi</b>, dans le rail, sous « Se former » : la page s’ouvre sur l’accueil.', 4400);
  await f.montrer('.fs-mini', 'Mon dossier de formation');
  await f.dire('En haut, <b>votre dossier de formation</b> en quatre nombres : en retard, en cours, à valider, validées.', 5000);
  await f.dire('« 1 nouveau » : une formation reçue, pas encore ouverte. « Ouvrir › » mène au dossier.', 4400);
  await f.montrer('.fs-prof-b', 'Le Professeur');
  await f.dire('À côté, « <b>Demander au Professeur</b> ».', 2800);
  await f.montrer('.sf-in', 'La méga-barre');
  await f.dire('La <b>méga-barre</b> cherche dans tout ce qui s’apprend ; la touche « / » y place le curseur.', 4400);
  await f.montrer('.sf-cats', 'Six catalogues');
  await f.dire('Dessous, les six catalogues, chacun à la couleur de sa couverture, avec son nombre.', 4200);
  await f.montrer('.sf-phrase', 'En une phrase');
  await f.dire('La <b>formation</b> apprend le métier, le <b>manuel</b> explique pourquoi, la <b>SOP</b> montre comment faire, la <b>checklist</b> vérifie.', 5600);
  await f.montrer('.sf-ac thead', 'Apprendre · Comprendre · Faire · Vérifier');
  await f.dire('Le tableau les compare : c’est quoi, pour qui, quand, où, comment, pourquoi, comment c’est validé.', 4800);
  await f.montrer('.sf-ac .sf-ac-ex', 'Un exemple');
  await f.dire('Dernière ligne : un exemple de chacun, qui s’ouvre d’un clic.', 3400);
  await f.montrer('.sf-ac thead .nom[data-sf-cat="std"]', 'SOP');
  await f.dire('Le nom d’une colonne ouvre son catalogue : ici, les SOP.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.sf-ac thead .nom[data-sf-cat="std"]', { apres: 1200 });
  await A.cadre(f, '.sf-f', 'Standards (SOP)');
  await f.dire('Le catalogue « Standards (SOP) » s’ouvre dans la méga-barre, tout entier…', 3800);
  await A.cadre(f, '.sf-r', 'Rangé par code');
  await f.dire('… rangé par code : 0.1 l’ouverture, 0.2 la journée, 0.4 la fermeture.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Escape');
  await f.pause(700);
  await f.dire('Échap, ou la croix, referme le panneau.', 2800);
  await f.taire(150);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
