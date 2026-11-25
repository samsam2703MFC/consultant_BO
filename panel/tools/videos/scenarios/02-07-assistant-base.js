/* Mon équipe › l'assistant de la semaine, étape 1 « Partir de quoi ? » (?vue=equipe), sans fonction qui écrit ici
 * (24/11/2026 : l'assistant n'écrit au panel qu'à « Créer », chapitre « Préparer la semaine avec l'assistant » de
 * 02-11-assistant-verifier) :
 *   onglet    equipe-assistant-base   la semaine vide, « Lancer l'assistant », les étapes, les trois bases et leur aperçu
 * Avant de filmer : les options « Assistant de la semaine » et « Matrice A B C D » allumées, la semaine du 30 novembre
 * (vide dans la démo) montrée. Données fictives : mocks/20_equipe.js. Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-07-assistant-base', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.options(p, { assistant: true, matrice: true }); await E.recharger(p); await E.planning(p, { pas: 2 }); } });

  await f.onglet({
    id: 'equipe-assistant-base', titre: 'L’assistant : partir de quoi ?',
    resume: 'Le choix de la base : reprendre la semaine passée (l’aperçu jour par jour, avec ses %), partir de la matrice, ou d’une semaine vide.',
    accroche: 'Une base, puis on l’ajuste',
    programme: ['Reprendre la semaine passée', 'Partir de la matrice A B C D', 'Partir d’une semaine vide'],
  });
  await f.montrer('.eqp-vide7', 'Une semaine vide');
  await f.dire('La semaine du 30 novembre est vide. Avec l’option « Assistant », il se lance d’ici…', 4400);
  await f.montrer('.eqp-menu', 'Préparer la semaine');
  await f.dire('… ou par « <b>Préparer la semaine</b> » › Assistant de la semaine.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-vide7 [data-eqp-assistant]', { apres: 1800 });
  await f.montrer('.eqp-as-rail', 'Les étapes');
  await f.dire('À gauche, les étapes. <b>Rien n’est écrit au panel</b> avant la dernière.', 3800);
  await f.montrer('[data-eqp-as-base="recopie"]', 'La semaine passée');
  await f.dire('Reprendre la <b>semaine passée</b> : chacun garde ses jours et ses heures, sept jours plus tard.', 4600);
  await f.montrer('[data-eqp-as-base="recopie"] .eqp-as-jours', 'Jour par jour');
  await f.dire('L’aperçu, jour par jour : les heures, et le pourcentage de chaque jour.', 3800);
  await f.montrer('[data-eqp-as-base="matrice"]', 'La matrice');
  await f.dire('Partir de la <b>matrice A B C D</b> : matin ou après-midi selon la lettre de chacun (option Matrice).', 5000);
  await f.montrer('[data-eqp-as-base="vide"]', 'Une semaine vide');
  await f.dire('Ou d’une <b>semaine vide</b> : on place chacun soi-même, en tirant sa ligne.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-as-base="matrice"]', { apres: 900 });
  await f.cliquer('[data-eqp-as-base="recopie"]', { apres: 900 });
  await E.sousTitre(f, 'haut');
  await f.montrer('.eqp-as-pied .res', 'Le CA planifié');
  await f.dire('En bas : le <b>CA planifié</b> de la semaine, comptoir + B2B. Le budget en découle.', 4200);
  await f.montrer('[data-eqp-as-suite]', 'Continuer');
  await f.dire('La base choisie, « Continuer › » passe à « Qui adapter ? ».', 3200);
  await f.cacher(300);
  await E.sousTitre(f, 'bas');

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
