/* La barre du haut › les fenêtres communes › « Ventes B2B » (?vue=ops), 26/11/2026 :
 *   onglet    barre-b2b    la jauge B2B d'Opérationnel ouvre la fenêtre : Aujourd'hui · le même jour la semaine
 *                          dernière, les quatre chiffres (CA B2B, tickets pro, panier pro, à facturer), chaque ticket pro
 *                          déplié sur ses articles (lire un ticket B2B : un geste de lecture, montré ici), ce qu'ils ont acheté
 * Aucune fonction en chapitre : la fenêtre ne fait que lire. Données fictives : mocks/10_ops.js (lib/jour.js, proDetail).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (opDuel B2B, b2bOuvrir, b2bRendre, b2bJour). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-27-b2b', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'],
  });

  await f.onglet({
    id: 'barre-b2b', titre: 'Ventes B2B',
    resume: 'La jauge B2B d’Opérationnel ouvre « Ventes B2B » : aujourd’hui ou le même jour la semaine dernière, les quatre chiffres du B2B, chaque ticket pro avec ses articles, et ce qu’ils ont acheté.',
    accroche: 'Vos clients pro, ticket par ticket',
    programme: ['La jauge B2B', 'Les quatre chiffres', 'Un ticket pro, déplié', 'Le même jour, la semaine dernière'],
  });
  await f.montrer('#dash .op-duel.b2b', 'La jauge B2B');
  await f.dire('Sur Opérationnel, la jauge <b>B2B</b> : les clients pro d’aujourd’hui face au même jour la semaine dernière.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .op-duel.b2b .dh', { apres: 1400 });
  await B.cadre(f, '#db-b2b .bm-pil >> nth=0', 'Aujourd’hui');
  await f.dire('Un appui ouvre « <b>Ventes B2B</b> », sur aujourd’hui.', 3400);
  await B.cadre(f, '#db-b2b .bm-k4', 'Les quatre chiffres');
  await f.dire('Le <b>CA B2B</b> et sa part du jour, les tickets pro, le panier pro face au comptoir, le montant à facturer.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-b2b [data-b2bt] >> nth=0', { apres: 900 });
  await B.cadre(f, '#db-b2b .bm-li.ouv', 'Un ticket pro');
  await f.dire('Chaque <b>ticket pro</b> : l’heure, la société, le montant ; un appui le déplie sur ses articles.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-b2b [data-b2bt] >> nth=0', { apres: 700 });
  await f.montrer('#db-b2b .bm-in:has(.bm-bars)', 'Ce qu’ils ont acheté');
  await f.dire('Dessous, <b>ce qu’ils ont acheté</b>, catégorie par catégorie.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-b2b .bm-pil >> nth=1', { apres: 1400 });
  await B.cadre(f, '#db-b2b .fi-modale', 'La semaine dernière');
  await f.dire('L’autre bouton : le <b>même jour la semaine dernière</b>, ses tickets et ses chiffres.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-b2b .fi-x', { apres: 800 });
  await f.dire('La croix referme la fenêtre.', 2400);
  await f.taire(150);
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
