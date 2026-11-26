/* Assistant IA › l'onglet « Conversation » (?vue=assistant), et sa fonction en chapitre (26/11/2026) :
 *   onglet    assistant-conversation   « Continuer en conversation › » ; les questions toutes prêtes par thème à gauche
 *                                      (avec Mes modèles), le fil à droite, la saisie en bas ; une priorité mène à son
 *                                      écran (lecture : « Voir mes promotions › »), le fil reste
 *   fonction  assistant-creuser        un bouton « Creuser : » sous la réponse : la suite arrive dans le fil
 * Graphique · Tableau, CSV, image, PDF, garder et refaire un modèle : les mêmes gestes qu'au Bilan (21-01).
 * Données fictives : mocks/71_assistant.js ; avant de filmer, la question « Quel est mon jour le plus faible, et
 * pourquoi ? » est posée au Bilan. Chaque phrase vient de panel/public/dashboard/dashboard.js (iaConversation, iaSuites,
 * iaPrios, iaAller, iaDemander : le fil part avec la question). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('21-02-conversation', {
    vue: 'assistant', serie: 'Assistant IA', parent: 'assistant-ia',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/src/assistant_magasin.php', 'panel/src/assistant_ia.php'],
    avant: async p => {
      await A.marge(p);
      await A.demander(p, 'Quel est mon jour le plus faible, et pourquoi ?');
      await A.lenteur(p.context());
      await p.evaluate(() => window.scrollTo(0, 0));
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'assistant-conversation', titre: 'La conversation',
    resume: 'Les questions toutes prêtes par thème à gauche, avec Mes modèles ; le fil à droite ; la saisie en bas.',
    accroche: 'Creuser, question après question',
    programme: ['Creuser une question'],
  });
  await f.montrer('.ia-page .ia-modes', 'Bilan · Conversation');
  await f.dire('On passe en <b>Conversation</b> par le sélecteur du haut…', 3200);
  await f.montrer('.ia-rep .ia-pied [data-iamode=a]', 'Continuer en conversation');
  await f.dire('… ou sous une réponse du Bilan, « <b>Continuer en conversation</b> ».', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-rep .ia-pied [data-iamode=a]', { apres: 1200 });
  await f.montrer('.ia-q', 'Questions toutes prêtes');
  await f.dire('À gauche, les <b>questions toutes prêtes</b>, rangées par thème : ma semaine, mes ventes, mon équipe…', 4800);
  await f.montrer(f.p.locator('.ia-q .ia-qb[data-iamodo]').first(), 'Mes modèles');
  await f.dire('… et, tout en bas, <b>Mes modèles</b>.', 2800);
  await f.cacher(150); await f.taire(150);
  await A.cadre(f, '.ia-conv', 'Le fil');
  await f.dire('À droite, <b>le fil</b> : la question posée au Bilan, et la réponse de Mr. Basile, avec son portrait.', 4800);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-src'), 'Lu à 15:40 · PDF');
  await f.dire('Sous chaque réponse : l’heure où les chiffres ont été lus, et son PDF.', 3800);
  await f.montrer('.ia-conv .ia-saisie', 'Votre question');
  await f.dire('En bas, votre question, puis « → ».', 2800);
  await f.cacher(150); await f.taire(150);
  const prio = f.p.locator('.ia-fil .ia-bulle.ia').last().locator('[data-iaaller="promos"]');
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-prio').first(), 'Une priorité');
  await f.dire('Chaque priorité mène à l’écran où agir…', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(prio, { apres: 2200 });
  await f.dire('… ici, les <b>Promotions</b>.', 2600);
  await f.taire(150);
  await f.cliquer('#db-rail .db-rail-ia', { apres: 1500 });
  await A.cadre(f, '.ia-conv', 'Le fil est resté');
  await f.dire('Retour à l’Assistant IA : la conversation est restée, le temps de la page.', 4000);
  await f.taire(150); await f.cacher(300);

  /* Creuser une question */
  await f.fonction({ id: 'assistant-creuser', titre: 'Creuser une question',
    resume: 'Un bouton « Creuser » sous la réponse, ou une question de la colonne de gauche : la réponse arrive dans le fil, qui part avec la question.',
    points: ['« Creuser : » sous la réponse', 'ou une question à gauche', 'La suite arrive dans le fil'] });
  await f.montrer('.ia-conv .ia-suites', 'Creuser');
  await f.dire('Sous la dernière réponse, « <b>Creuser</b> » propose la suite.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-conv .ia-suites button', { hasText: 'bundle' }), { apres: 300 });
  await f.dire('Mr. Basile lit les chiffres…', 1800);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last(), 'La suite');
  await f.dire('La réponse arrive dans le fil : un café et un cookie, de 14 h à 17 h le lundi, avec son prix et sa marge.', 5200);
  await f.dire('Le fil part avec chaque question : l’assistant sait de quoi on parle.', 3800);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
