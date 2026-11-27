/* Mon coffre-fort › « Déposer », étape 1 : le fichier (la fenêtre qu'ouvrent « Déposer un document », « Déposer » sur
 * une pièce ou « Déposer la nouvelle »), et sa fonction en chapitre (26/11/2026) :
 *   onglet    coffre-depot      la fenêtre : le fichier (glissé, choisi, ou une photo au téléphone), « C'est quelle
 *                               pièce ? », le titre, la date, l'échéance, la note ; à droite, ce que fera l'assistant
 *   fonction  coffre-deposer    choisir le fichier, la pièce, le titre et la date, « Déposer » : le document est rangé et
 *                               l'assistant commence à le lire (la suite : « Déposer, étape 2 », 23-08-assistant)
 * Le glisser-déposer depuis l'ordinateur ne se filme pas (hors de la page) : on choisit le fichier d'un clic. Données
 * fictives : mocks/23_coffre.js. Chaque phrase vient de panel/public/dashboard/coffre.js (ouvrirDepot, fenetre,
 * envoyerDepot) ou de consultant/src/coffre.php (wr_coffre_document). */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-07-deposer', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES,
    avant: async p => { await C.marge(p); await C.lenteur(p, 15000); } });

  /* L'onglet */
  await f.onglet({
    id: 'coffre-depot', titre: 'Déposer, étape 1 : le fichier',
    resume: 'La fenêtre du dépôt : le fichier, glissé ou choisi (au téléphone, une photo), « C’est quelle pièce ? », le titre, la date, l’échéance et une note.',
    accroche: 'Un document, une place', programme: ['Déposer un document'],
  });
  await f.montrer('.cf2-barre [data-cf-depot]', 'Déposer un document');
  await f.dire('« <b>Déposer un document</b> », en haut du coffre. « Déposer » sur une pièce ouvre la même fenêtre.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-barre [data-cf-depot]', { apres: 1100 });
  await C.cadreFixe(f, '.cf2-mod [data-cf-drop]', 'Le fichier');
  await f.dire('Le <b>fichier</b> : on le glisse ici, ou un clic pour le choisir. Au téléphone : une photo.', 4800);
  await C.cadreFixe(f, '.cf2-mod .cf2-chips', 'C’est quelle pièce ?');
  await f.dire('« <b>C’est quelle pièce ?</b> » : les pièces à fournir d’abord, puis celles déjà là ✓, ou « Autre document ».', 5400);
  await C.cadreFixe(f, '.cf2-mod .g .cf2-2', 'Les dates');
  await f.dire('Le titre, la <b>date</b> du document, son <b>échéance</b> s’il en a une, et une note.', 4400);
  await C.cadreFixe(f, '.cf2-mod .d', 'L’assistant');
  await f.dire('À droite, ce que fera l’<b>assistant</b> : lire le document et proposer ses grandes dates. Rien n’est rangé sans votre accord.', 6000);
  await C.cadreFixe(f, '.cf2-mod .f', 'Déposer');
  await f.dire('« <b>Déposer</b> » attend le fichier. Le document rejoint votre coffre ; votre consultant le voit aussi.', 5000);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod .f [data-cf-depot-ferme]', 900);

  /* Déposer un document */
  await f.fonction({ id: 'coffre-deposer', titre: 'Déposer un document',
    resume: 'Le fichier, la pièce, le titre et la date, puis « Déposer » : le document est rangé dans le coffre, et l’assistant commence à le lire.',
    points: ['« Déposer un document »', 'Choisir le fichier', 'La pièce, le titre, la date', '« Déposer »'] });
  await f.cliquer('.cf2-barre [data-cf-depot]', { apres: 1100 });
  await C.choisirFichier(f, C.pdf('bail-commercial-boutique.pdf', 1235));
  await C.cadreFixe(f, '.cf2-mod [data-cf-drop]', 'Prêt à déposer');
  await f.dire('Le fichier choisi : le bail de la boutique, <b>prêt à déposer</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod [data-cf-piece="bail"]', 700);
  await C.cadreFixe(f, '.cf2-mod [data-cf-dp="titre"]', 'Titre');
  await f.dire('La pièce : <b>Bail commercial</b>. Le titre suit.', 3200);
  await f.cacher(150); await f.taire(150);
  await C.taperDate(f, '.cf2-mod [data-cf-dp="date"]', '2024-05-15');
  await C.cadreFixe(f, '.cf2-mod [data-cf-dp="date"]', 'Date du document');
  await f.dire('La date du document : sa signature, le 15 mai 2024.', 3200);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod [data-cf-envoi]', 1800);
  await C.cadreFixe(f, '.cf2-mod .cf2-drop', 'Rangé');
  await f.dire('<b>Déposer</b> : le bail est rangé dans votre coffre.', 3400);
  await C.cadreFixe(f, '.cf2-mod .d', 'L’assistant lit le document');
  await f.dire('L’<b>assistant</b> le lit déjà : la suite, ses grandes dates, est l’étape 2.', 4400);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
