/* Boîte mail › les dossiers (?vue=boite, la rangée de boutons sous la barre, en mode Classique) (24/11/2026) :
 *   onglet    boite-dossiers   Réception et ses non-lus, Brouillons, Envoyés (« À : »), Archives (deux pages),
 *                              Indésirables et Corbeille (les gestes changent), puis les dossiers du magasin
 * Changer de dossier, chercher, tourner la page : des gestes de lecture, sans chapitre à eux. « Effacer pour de bon » et
 * « Remettre en Réception » ont leur chapitre dans « La sélection » (20-05-selection). Données fictives :
 * mocks/70_boite.js. Chaque phrase vient de panel/public/dashboard/boite.js (barre, liste, lecture, vueSelection). */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-03-dossiers', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php'], avant: B.ouverte() });

  await f.onglet({
    id: 'boite-dossiers', titre: 'Les dossiers',
    resume: 'La rangée de boutons sous la barre : la Réception et ses non-lus, les Brouillons, les Envoyés, les Archives, les Indésirables, la Corbeille, puis les dossiers du magasin.',
    accroche: 'Chaque e-mail à sa place',
  });
  await f.montrer('.bx-dossiers', 'Les dossiers');
  await f.dire('Sous la barre, les <b>dossiers</b> en boutons : la Réception d’abord, avec ses non-lus.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxd="Drafts"]', { apres: 900 });
  await B.cadre(f, '.bx-liste', 'Brouillons');
  await f.dire('Les <b>Brouillons</b>, comptés sur le bouton : les réponses rédigées par le pilote y attendent.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxd="Sent"]', { apres: 900 });
  await B.cadre(f, '.bx-liste', 'Envoyés');
  await f.dire('Les <b>Envoyés</b> : la liste dit « À : » et le destinataire, plutôt que l’expéditeur.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxd="Archive"]', { apres: 900 });
  await f.montrer('.bx-pied', 'Deux pages');
  await f.dire('Les <b>Archives</b> : 62 e-mails, cinquante par page ; ‹ et › tournent les pages.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-pied [data-bxp="1"]', { apres: 900 });
  await f.montrer('.bx-pied', 'Page 2');
  await f.dire('La page 2 : les douze plus anciens.', 2600);
  await f.cacher(150); await f.taire(150);
  await B.enHaut(f);
  await f.cliquer('[data-bxd="Junk"]', { apres: 900 });
  await f.cliquer('[data-bxu="703"]', { apres: 1000 });
  await f.montrer('.bx-gestes', 'Les gestes changent');
  await f.dire('Dans les <b>Indésirables</b> et la <b>Corbeille</b>, la poubelle devient « Effacer pour de bon ».', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxd="Trash"]', { apres: 900 });
  await f.cliquer(B.coche(803), { apres: 900 });
  await f.montrer('.bx-sel .bx-tuiles', 'Remettre ou effacer');
  await f.dire('Cochés, ses e-mails se <b>remettent en Réception</b>, ou s’effacent pour de bon : sans retour.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-sel-h [data-bx="deselect"]', { apres: 700 });
  await f.montrerTout('nav.bx-dossiers > .bx-dos-sep ~ .bx-dos', 'Les dossiers du magasin');
  await f.dire('Puis les dossiers du magasin : <b>Candidatures</b>, <b>Commandes</b>, <b>Fournisseurs</b>…', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxd="INBOX.Candidatures"]', { apres: 900 });
  await f.montrer('.bx-q', 'Chercher');
  await f.dire('La recherche porte sur le dossier ouvert : « Chercher dans Candidatures… ».', 4000);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
