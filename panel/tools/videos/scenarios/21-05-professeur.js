/* Assistant IA › « Le Professeur » (« Votre assistant » › Le Professeur), et sa fonction en chapitre (26/11/2026) :
 *   onglet    assistant-professeur        ses tuiles tirées du manuel, le manuel famille par famille (conversation), une
 *                                         réponse qui cite les documents lus (« Lu dans le manuel »), sans lire l'ERP
 *   fonction  assistant-demander-geste    « Que faut-il vérifier à la fermeture ? » : les étapes, un tableau du manuel,
 *                                         « Lu dans le manuel »
 * Données fictives : mocks/71_assistant.js (PROF, expliquer, fermeture) et mocks/lib/manuel.js (le manuel de
 * démonstration). Chaque phrase vient de dashboard.js (iaProf, iaQ, iaSaisie, iaConversation, iaSources, iaLit) ou de
 * panel/src/LISEZMOI.md (le Professeur : l'agent rapide du manuel, sans lecture de l'ERP ; seul un tableau du manuel
 * est recopié d'un document lu). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('21-05-professeur', {
    vue: 'assistant', serie: 'Assistant IA', parent: 'assistant-ia',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/src/assistant_magasin.php', 'panel/src/assistant_ia.php'],
    avant: async p => { await A.lenteur(p.context(), 1800); await A.marge(p); },
  });

  await f.onglet({
    id: 'assistant-professeur', titre: 'Le Professeur',
    resume: 'Les questions tirées du manuel, famille par famille, et des réponses qui citent les documents lus (« Lu dans le manuel »), sans lire l’ERP.',
    accroche: 'Le manuel, à la question',
    programme: ['Demander un geste au Professeur'],
  });
  await f.montrer('[data-iaton=professeur]', 'Le Professeur');
  await f.dire('<b>Le Professeur</b> répond sur le manuel opératoire : les gestes, les recettes, les standards.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-iaton=professeur]', { apres: 900 });
  await f.montrer('.ia-page > .ia-h', 'Il lit le manuel');
  await f.dire('Il lit le <b>manuel opératoire</b> du réseau, document par document. Pas les chiffres du magasin.', 4600);
  await f.montrer('.ia-tqs', 'Ses questions');
  await f.dire('Ses questions toutes prêtes viennent du manuel : ouvrir, fermer, l’hygiène, la vitrine.', 4200);
  await f.montrer('.ia-saisie.haut', 'Sa saisie');
  await f.dire('Sa saisie aussi : « comment fermer la caisse ? », « à quelle température cuire les croissants ? »', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-page .ia-modes [data-iamode=a]', { apres: 1000 });
  await f.montrer('.ia-q', 'Famille par famille');
  await f.dire('En conversation : <b>le manuel, famille par famille</b> — les contrôles photo, l’ouverture, la journée, la fermeture.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-q .ia-qb', { hasText: 'Ouverture du magasin et des comptoirs' }), { apres: 300 });
  await f.dire('Le Professeur <b>ouvre le manuel</b>…', 1800);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last(), 'Sa réponse');
  await f.dire('Les étapes de la SOP, dans l’ordre ; le point critique est signalé.', 4000);
  await f.montrer(f.p.locator('.ia-fil .ia-srcs').last(), 'Lu dans le manuel');
  await f.dire('« <b>Lu dans le manuel</b> » : les documents qu’il a lus, avec leur code.', 3800);
  await f.taire(150); await f.cacher(300);

  /* Demander un geste au Professeur */
  await f.fonction({ id: 'assistant-demander-geste', titre: 'Demander un geste au Professeur',
    resume: '« Que faut-il vérifier à la fermeture ? » : les étapes dans l’ordre de la SOP, un tableau recopié du manuel, et les documents lus.',
    points: ['Le Professeur, au Bilan', 'La question toute prête', 'Les étapes, le tableau', '« Lu dans le manuel »'] });
  await f.cliquer('.ia-page .ia-modes [data-iamode=b]', { apres: 900 });
  await f.montrer(f.p.locator('.ia-tq', { hasText: 'vérifier à la fermeture' }), 'La fermeture');
  await f.dire('Le Professeur, au Bilan : « Que faut-il vérifier à la fermeture ? »', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-tq', { hasText: 'vérifier à la fermeture' }), { apres: 300 });
  await f.dire('Il ouvre le manuel…', 1600);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer('.ia-rep .ia-pts', 'Les étapes');
  await f.dire('Les étapes, dans l’ordre de la SOP : la vitrine, le nettoyage, les frigos, la caisse.', 4400);
  await f.montrer('.ia-rep .ia-vis', 'Un tableau du manuel');
  await f.dire('Un <b>tableau</b> recopié du manuel : les deux points critiques, les frigos et la caisse.', 4400);
  await f.montrer('.ia-rep .ia-srcs', 'Lu dans le manuel');
  await f.dire('Lu dans la SOP 0.4.01 et la checklist 1.4.01 : les deux documents qu’il cite.', 4000);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
