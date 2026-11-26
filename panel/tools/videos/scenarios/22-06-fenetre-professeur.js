/* Formations et suivi › la fenêtre du Professeur, un onglet sans fonction propre (26/11/2026) :
 *   onglet    former-fenetre-professeur   « Demander au Professeur » ouvre l'assistant par-dessus la page, sur le
 *                                         Professeur : ses questions du manuel, une réponse et ses sources, les autres
 *                                         assistants, les trois onglets ; Échap la referme
 * Poser une question au Professeur depuis le dossier ou la recherche : les fonctions de 22-04 et 22-02.
 * Données fictives : mocks/71_assistant.js (expliquer), lib/manuel.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (fcProfesseur, iaOuvrir, iaRendre, iaLier : au bureau, Bilan et Conversation de
 * la fenêtre mènent à la page) et formation.js (le bouton de l'accueil). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('22-06-fenetre-professeur', {
    vue: 'formation', serie: 'Formations et suivi', parent: 'se-former',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/formation.js'],
    avant: async p => { await A.lenteur(p.context(), 1800); },
  });

  await f.onglet({
    id: 'former-fenetre-professeur', titre: 'La fenêtre du Professeur',
    resume: 'L’assistant s’ouvre par-dessus la page, sur le Professeur, et répond à partir du dossier et du manuel en citant ce qu’il a lu.',
    accroche: 'Le Professeur, par-dessus la page',
    programme: ['L’ouvrir depuis la formation', 'Ses questions, tirées du manuel', 'Une réponse et ses sources', 'La refermer'],
  });
  await f.montrer('.fs-prof-b', 'Demander au Professeur');
  await f.dire('« <b>Demander au Professeur</b> », sur l’accueil ; ou une question du dossier, d’une formation, de la méga-barre.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-prof-b', { apres: 1200 });
  await A.cadre(f, '#db-ia .ia-modale', 'La fenêtre');
  await f.dire('L’assistant s’ouvre par-dessus la page, sur le <b>Professeur</b>.', 3600);
  await f.montrer('#db-ia .ia-h', 'Il lit le manuel');
  await f.dire('Il lit le manuel opératoire du réseau, document par document.', 3400);
  // CONTOURNEMENT : sans question, la fenêtre s'ouvre sur son dernier onglet (Bilan au départ), pas en Conversation
  // comme le demande fcProfesseur (dashboard.js:7677 → iaOuvrir, 5924, n'applique que le mode « d »). On filme ce que
  // l'écran fait : au Bilan, une tuile ; en Conversation (si c'est corrigé), une question de la colonne de gauche.
  const conv = await f.p.locator('#db-ia .ia-q').count() > 0;
  if (conv) {
    await f.montrer('#db-ia .ia-q', 'Famille par famille');
    await f.dire('À gauche, ses questions : le manuel, famille par famille.', 3200);
    await f.cacher(150); await f.taire(150);
    await f.cliquer(f.p.locator('#db-ia .ia-qb', { hasText: 'Ouverture du magasin et des comptoirs' }), { apres: 300 });
  } else {
    await f.montrer('#db-ia .ia-tqs', 'Ses questions');
    await f.dire('Ses questions toutes prêtes viennent du manuel : ouvrir, fermer, l’hygiène, la vitrine.', 4200);
    await f.cacher(150); await f.taire(150);
    await f.cliquer(f.p.locator('#db-ia .ia-tq', { hasText: 'Comment ouvrir le magasin' }), { apres: 300 });
  }
  await f.dire('Le Professeur ouvre le manuel…', 1800);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(conv ? f.p.locator('#db-ia .ia-bulle.ia').last() : '#db-ia .ia-rep .ia-pts', 'Sa réponse');
  await f.dire('Sa réponse : les étapes de la SOP, le point critique signalé…', 3400);
  await f.montrer(f.p.locator('#db-ia .ia-srcs').last(), 'Lu dans le manuel');
  await f.dire(conv ? '… et ce qu’il a lu : la SOP 0.1.01.' : '… et ce qu’il a lu : la SOP 0.1.01, et la checklist 1.1.01.', 3400);
  await f.montrer('#db-ia .ia-tons', 'Les assistants');
  await f.dire('Les autres assistants restent à un clic.', 2600);
  await f.montrer('#db-ia .ia-modes', 'Trois onglets');
  await f.dire('Bilan et Conversation mènent à la page Assistant IA ; « Demande au bureau » reste dans la fenêtre.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Escape');
  await f.pause(900);
  await f.dire('Échap, ou la croix, la referme : la page de la formation est là.', 3600);
  await f.taire(150);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
