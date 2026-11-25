/* Boîte mail › la sélection (cocher des e-mails : le volet de droite devient la sélection), et ses fonctions en
 * chapitres (24/11/2026) :
 *   onglet    boite-selection         le nombre sélectionné, les tuiles de gestes, « Déplacer vers », « Nouveau dossier »,
 *                                     « Tout sélectionner », puis « Sélectionner les 62 e-mails du dossier »
 *   fonction  boite-selection-agir    cocher, puis une tuile : ici « Marquer lu » sur trois e-mails
 *   fonction  boite-deplacer          « Déplacer vers » un dossier ; « Nouveau dossier » : le nom, « Créer et déplacer »
 *   fonction  boite-effacer           dans la Corbeille : « Effacer pour de bon » (confirmé), « Remettre en Réception »
 * Maj+clic (une plage de cases) n'est pas filmé : à la souris, il sélectionne le texte de la page et ne coche rien (voir
 * le rapport du tournage). Données fictives : mocks/70_boite.js (62 e-mails dans les Archives, trois dans la Corbeille).
 * Chaque phrase vient de panel/public/dashboard/boite.js (liste, vueSelection, geste, gesteSelection). */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-05-selection', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php'], avant: B.ouverte() });

  /* L'onglet */
  await f.onglet({
    id: 'boite-selection', titre: 'La sélection',
    resume: 'Cocher des e-mails : le volet de droite devient la sélection, avec ses gestes en tuiles, « Déplacer vers » et « Nouveau dossier ».',
    accroche: 'Plusieurs e-mails, un seul geste',
    programme: ['Agir sur plusieurs e-mails en une fois', 'Déplacer vers un dossier, ou un nouveau', 'Effacer pour de bon, ou remettre en Réception'],
  });
  await f.cliquer(B.coche(1049), { apres: 800 });
  await B.cadre(f, '.bx-sel', 'La sélection');
  await f.dire('Une case cochée, et le volet de droite devient la <b>sélection</b>.', 3600);
  await f.montrer('.bx-sel-h', 'Combien');
  await f.dire('Le nombre d’e-mails sélectionnés, et le dossier où ils sont.', 3200);
  await f.montrer('.bx-sel .bx-tuiles', 'Les gestes');
  await f.dire('Les gestes, en tuiles : <b>Archiver</b>, <b>Corbeille</b>, <b>Lu</b>, <b>Non lu</b>, <b>Suivre</b>.', 4400);
  await f.montrer('.bx-dossiers--vers', 'Déplacer vers');
  await f.dire('<b>Déplacer vers</b> : les dossiers en boutons, et « Nouveau dossier ».', 3800);
  await f.montrer('.bx-sel .bx-ia', 'Ranger avec l’IA');
  await f.dire('Dessous, « <b>Ranger avec l’IA</b> » propose un dossier pour chaque e-mail.', 3800);
  await f.montrer('.bx-rapides', 'Gestes rapides');
  await f.dire('En tête de liste, les gestes rapides et la croix qui désélectionne tout.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-rapides [data-bx="deselect"]', { apres: 600 });
  await f.cliquer('[data-bxd="Archive"]', { apres: 1100 });
  await f.cliquer('label.bx-coche--tout', { apres: 900 });
  await f.montrer('.bx-sel-h', 'La page');
  await f.dire('« <b>Tout sélectionner</b> » coche la page : cinquante e-mails sur soixante-deux.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-sel-h [data-bx="toutDossier"]', { apres: 900 });
  await f.montrer('.bx-sel-h', 'Tout le dossier');
  await f.dire('Puis « Sélectionner les 62 e-mails du dossier » : tout le dossier, recherche et filtre compris.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-sel-h [data-bx="deselect"]', { apres: 700 });

  /* Agir sur plusieurs e-mails en une fois */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-selection-agir', titre: 'Sélectionner des e-mails et agir en une fois',
    resume: 'Cocher les e-mails (ou « Tout sélectionner »), puis une tuile : Archiver, Corbeille, Lu, Non lu ou Suivre.',
    points: ['Cocher les e-mails', 'Une tuile : Archiver, Corbeille, Lu…', 'Le geste vaut pour tous'] });
  await f.cliquer('[data-bxd="INBOX"]', { apres: 900 });
  await f.cliquer(B.coche(1048), { apres: 400 });
  await f.cliquer(B.coche(1047), { apres: 400 });
  await f.cliquer(B.coche(1046), { apres: 700 });
  await f.montrer('.bx-sel-h', 'Trois e-mails');
  await f.dire('Trois e-mails non lus, cochés.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-sel .bx-tuiles [data-bxs="lu"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Marqués lus');
  await f.dire('<b>Marquer lu</b> : les trois en une fois ; la Réception n’a plus qu’un non-lu.', 4200);
  await f.cacher(300); await f.taire(200);

  /* Déplacer vers un dossier ou un nouveau dossier */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-deplacer', titre: 'Déplacer vers un dossier ou un nouveau dossier',
    resume: '« Déplacer vers » un dossier de la boîte ; ou « Nouveau dossier » : taper son nom, puis « Créer et déplacer ».',
    points: ['Cocher les e-mails', '« Déplacer vers » un dossier', 'ou « Nouveau dossier » : le nom', '« Créer et déplacer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer(B.coche(1046), { apres: 800 });
  await f.cliquer('.bx-dossiers--vers [data-bxv="INBOX.Candidatures"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Déplacé');
  await f.dire('Une candidature, déplacée vers « <b>Candidatures</b> ».', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(B.coche(1045), { apres: 400 });
  await f.cliquer(B.coche(1039), { apres: 800 });
  await f.cliquer('.bx-dossiers--vers [data-bx="nouveauDossier"]', { apres: 600 });
  await f.taper('Clients pro', 80);
  await f.montrer('.bx-dos--saisie', 'Nouveau dossier');
  await f.dire('Deux clients professionnels : « <b>Nouveau dossier</b> », son nom…', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bx="creerDeplacer"]', { apres: 1400 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Créé et déplacé');
  await f.dire('… puis « <b>Créer et déplacer</b> » : le dossier est créé dans la boîte, les e-mails y sont.', 4600);
  await f.cacher(300); await f.taire(200);

  /* Effacer pour de bon, ou remettre en Réception */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-effacer', titre: 'Effacer pour de bon, ou remettre en Réception',
    resume: 'Dans la Corbeille ou les Indésirables : cocher, puis « Effacer pour de bon » (après confirmation), ou « Remettre en Réception ».',
    points: ['La Corbeille', 'Cocher', '« Effacer pour de bon », confirmer', 'ou « Remettre en Réception »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('[data-bxd="Trash"]', { apres: 1000 });
  await f.cliquer(B.coche(802), { apres: 800 });
  await f.montrer('.bx-sel .bx-tuiles', 'Dans la Corbeille');
  await f.dire('Dans la Corbeille : <b>Effacer pour de bon</b>, ou <b>Remettre en Réception</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('.bx-sel .bx-tuiles [data-bxs="effacer"]', 'Effacer pour de bon 1 e-mail ?', 'Ils ne pourront plus être récupérés.');
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Effacé');
  await f.dire('Effacé pour de bon : il ne se récupère plus.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(B.coche(803), { apres: 800 });
  await f.cliquer('.bx-sel .bx-tuiles [data-bxv="INBOX"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Remis en Réception');
  await f.dire('Un e-mail jeté par erreur : <b>Remettre en Réception</b>, et il y revient.', 3800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
