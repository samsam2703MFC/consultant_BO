/* Mon coffre-fort › « Tout », l'accueil du coffre (?vue=coffre, le lien tout en bas du rail) (26/11/2026) :
 *   onglet    coffre-tout   le résumé du coffre (14 pièces sur 16), « À corriger », la prochaine grande date, la frise des
 *                           12 mois, puis tous les documents (« Ce mois-ci », « Avant »), les filtres, la recherche
 * Corriger un point « À corriger », chercher, filtrer, « tout afficher » : des gestes de lecture, sans chapitre à eux.
 * Données fictives : mocks/23_coffre.js. Chaque phrase vient de panel/public/dashboard/coffre.js (etat, aFaire, frise,
 * nav, vueTout, filtrer). Le résumé (.cf2-rs) remplace l'ancien état (27/11/2026). */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-01-tout', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES, avant: C.marge });

  await f.onglet({
    id: 'coffre-tout', titre: 'Tout : l’accueil du coffre',
    resume: 'Le résumé du coffre (les pièces attendues, À corriger, la prochaine grande date), la frise des 12 mois, puis tous les documents, avec leurs filtres et la recherche.',
    accroche: 'Ce qui manque, ce qui arrive',
  });
  await C.cadreFixe(f, '#db-rail .db-rail-coffre .sidebar__item', 'Mon coffre-fort');
  await f.dire('<b>Mon coffre-fort</b>, tout en bas du rail. Le chiffre : les nouveaux documents.', 4400);
  await f.montrer('.cf2-rs-v', 'Votre coffre');
  await f.dire('Les <b>pièces attendues</b> : 14 sur 16 sont là, le coffre est incomplet. Il en manque 2, à déposer par vous.', 5000);
  await f.montrer('.cf2-rs-l2', 'À corriger');
  await f.dire('<b>À corriger</b> : trois points au plus ; chaque bouton fait son geste.', 3600);
  await f.montrer('.cf2-rs-b[data-cf-faire^="depot:"]', 'À renouveler');
  await f.dire('L’assurance RC arrive à échéance : son bouton ouvre le <b>dépôt de la nouvelle</b>.', 4200);
  await f.montrer('.cf2-rs-b[data-cf-faire="dos:legal"]', 'Pièces à déposer');
  await f.dire('« <b>2 pièces légales à déposer</b> » ouvre le dossier Légal, où elles attendent.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-rs-b[data-cf-faire="nouveaux"]', { apres: 1000 });
  await f.montrer('.cf2-carte', 'Nouveaux');
  await f.dire('« <b>2 nouveaux documents à lire</b> » ne garde que les nouveaux : le dernier rapport de visite, le dossier d’analyse.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-filtre="tous"]', { apres: 800 });
  await C.enHaut(f);
  await f.montrer('.cf2-rs-pr', 'Prochaine grande date');
  await f.dire('À droite du résumé, la <b>prochaine grande date</b> : l’échéance de l’assurance RC, dans 42 jours.', 4400);
  await f.montrer('.cf2-frise', 'Les 12 prochains mois');
  await f.dire('La <b>frise</b> des 12 prochains mois ; en or, ce qui tombe bientôt. Un clic ouvre les grandes dates.', 5000);
  await f.montrer('.cf2-dos', 'Les dossiers');
  await f.dire('À gauche, les <b>dossiers</b> : qui les remplit, et combien de pièces sont là.', 4200);
  await f.montrer('.cf2-carte .cf2-ch', 'Tous les documents');
  await f.dire('« <b>Tout</b> » : documents et rapports, « Ce mois-ci » puis « Avant ».', 4000);
  await f.montrerTout('.cf2-carte .cf2-l:nth-of-type(-n+3) .dos, .cf2-carte .cf2-l:nth-of-type(-n+3) .qd, .cf2-carte .cf2-l:nth-of-type(-n+3) .et', 'Le dossier, la date, l’état');
  await f.dire('Chaque ligne : son dossier, sa date, son état — à lire, déposé, valide, à renouveler.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-filtre="renouveler"]', { apres: 800 });
  await f.montrer('.cf2-carte', 'À renouveler');
  await f.dire('Les filtres : <b>Nouveaux</b>, <b>À renouveler</b>, <b>Déposés par moi</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-filtre="tous"]', { apres: 800 });
  await f.montrer('.cf2-pied', 'Les plus anciens');
  await f.dire('Douze lignes d’abord ; « <b>tout afficher</b> » montre les plus anciens.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-pied [data-cf-toutvoir]', { apres: 900 });
  await C.enHaut(f);
  await f.cliquer('.cf2-q', { apres: 200 });
  await f.taper('assurance', 110);
  await f.pause(600);
  await f.montrer('.cf2-carte', 'Chercher');
  await f.dire('<b>Chercher un document</b> : la liste ne garde que ce qui répond, dans tous les dossiers.', 4600);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
