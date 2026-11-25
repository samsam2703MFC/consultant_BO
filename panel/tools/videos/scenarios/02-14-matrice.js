/* Mon équipe › « Matrice A B C D » (?vue=equipe ; option « Matrice A B C D et shift maker »), et sa fonction en
 * chapitre (24/11/2026) :
 *   onglet    equipe-matrice    les quatre roulements sur deux semaines, les deux créneaux, la semaine de
 *                                    référence, la couverture matin · après-midi par jour, qui porte chaque lettre
 *   fonction  equipe-matrice-regler  cliquer une case (matin → après-midi → repos), l'heure d'un créneau, « Enregistrer »
 * Avant de filmer : l'option Matrice allumée. Données fictives : mocks/20_equipe.js (la matrice de départ). Chaque
 * phrase vient de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-14-matrice', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.options(p, { matrice: true }); await E.recharger(p); await E.planning(p); } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-matrice', titre: 'La matrice A B C D',
    resume: 'Les quatre roulements sur deux semaines (matin, après-midi, repos), les deux créneaux, la semaine de référence et la couverture matin · après-midi par jour.',
    accroche: 'Matin, après-midi, un week-end sur deux',
    programme: ['Régler la matrice A B C D'],
  });
  await f.cliquer('.eqp-menu summary', { apres: 700 });
  await f.montrer('[data-eqp-matrice]', 'Matrice A B C D');
  await f.dire('Avec l’option Matrice : « Préparer la semaine » › <b>Matrice A B C D</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-matrice]', { apres: 1300 });
  await f.montrer('.eqp-mat .qui', 'La matrice');
  await f.dire('Quatre <b>roulements</b> sur deux semaines : matin ou après-midi, un week-end sur deux.', 4400);
  await f.montrer('.eqp-mat-t thead tr:first-child', 'Semaine 1 · semaine 2');
  await f.dire('Deux semaines côte à côte, qui reviennent l’une après l’autre ; celle en cours est marquée en couleur.', 4600);
  await f.montrer('.eqp-mat-t tbody tr:nth-child(1)', 'La lettre A');
  await f.dire('Une ligne par lettre : <b>M</b> le matin, <b>AP</b> l’après-midi, un point pour le repos.', 4400);
  await f.montrer('.eqp-mat-cr', 'Les créneaux');
  await f.dire('Les deux créneaux, et leurs heures : le matin, l’après-midi.', 3400);
  await f.montrer('.eqp-mat-cr:nth-of-type(2)', 'La semaine de référence');
  await f.dire('La semaine 1 commence le lundi choisi ; la matrice dit où en est cette semaine-ci.', 4400);
  await f.montrer('.eqp-mat-t tr.tot', 'Matin · après-midi');
  await f.dire('En bas, par jour : combien de personnes de l’équipe le <b>matin</b> et l’<b>après-midi</b>.', 4600);
  await f.montrerTout('.eqp-mat-t tbody .eqp-mx-e', 'Équipe');
  await f.dire('À droite, qui porte chaque lettre : elle se donne dans sa fiche, onglet Responsabilités.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-mat .confirm__actions [data-eqp-fermer]', { apres: 800 });

  /* Régler la matrice A B C D */
  await f.fonction({ id: 'equipe-matrice-regler', titre: 'Régler la matrice A B C D',
    resume: 'Changer une case d’un clic (matin, après-midi, repos), les heures des créneaux et la semaine de référence, puis enregistrer la matrice.',
    points: ['Une case : matin → après-midi → repos', 'Les heures des deux créneaux', 'La semaine de référence', '« Enregistrer la matrice »'] });
  await f.cliquer('.eqp-menu summary', { apres: 700 });
  await f.cliquer('[data-eqp-matrice]', { apres: 1300 });
  const cas = '[data-eqp-mx="D04"]';
  await f.montrer(cas, 'D · vendredi');
  await f.dire('D, le vendredi de la semaine 1 : un clic passe du matin à l’<b>après-midi</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(cas, { apres: 900 });
  await f.montrer('.eqp-mat-t tr.tot', 'La couverture');
  await f.dire('La couverture du jour suit aussitôt : moins de monde le matin, plus l’après-midi.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-cr="M.a"]', { apres: 300 });
  await f.p.locator('[data-eqp-cr="M.a"]').fill('13:30');
  await f.p.locator('[data-eqp-cr="M.a"]').dispatchEvent('change');
  await f.pause(600);
  await f.montrer('.eqp-mat-cr', 'Matin 06:45 – 13:30');
  await f.dire('Le matin finit à <b>13 h 30</b>. La semaine de référence se règle juste dessous.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-mat-ok]', { apres: 1500 });
  await f.montrer('.eqp-flash', 'Matrice enregistrée');
  await f.dire('« <b>Enregistrer la matrice</b> » : le shift maker et les fenêtres en tirent leurs créneaux.', 4400);
  await f.dire('« Revenir à la matrice de départ » la remet comme au premier jour.', 3400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
