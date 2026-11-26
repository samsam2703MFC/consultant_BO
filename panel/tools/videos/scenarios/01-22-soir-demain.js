/* La barre du haut › « Revue du soir » › pour demain (?vue=ops), 26/11/2026 :
 *   onglet    barre-soir-demain     le dernier écran : un mot pour l'équipe de demain (facultatif), ‹ pour revenir sur
 *                                   une réponse, « Clôturer la journée »
 *   fonction  barre-soir-cloturer   écrire le mot pour demain, « Clôturer la journée », le bilan ; la vignette dit « faite »
 * Départ (hors film) : toutes les réponses données, la revue rouverte jusqu'à cet écran. Données fictives :
 * mocks/11_barre_soir.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rvEcran « demain » et « fin »,
 * rvEnregistrer, railBarre) et de panel/src/revue_soir.php (rsRevueEcrire : « cloturer » fige la journée). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-22-soir-demain', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/revue_soir.php'],
    avant: async p => {
      await B.soirFilm(p); await B.prioritaires(p);
      await B.soirEtat(p, { par: 'Julie M.', statut: 'encours', reponses: B.REPONSES() });
      await B.relire(p);
      await B.soirJusqua(p, '[data-rvdemain]');
    },
  });

  await f.onglet({
    id: 'barre-soir-demain', titre: 'La revue du soir : pour demain',
    resume: 'Le dernier écran de la revue : un mot pour l’équipe de demain, facultatif, ‹ pour revenir sur une réponse, puis « Clôturer la journée ».',
    accroche: 'Un mot pour l’équipe de demain',
    programme: ['Le mot pour demain', 'Revenir sur une réponse', 'Clôturer la journée'],
  });
  await B.cadre(f, '#rv .rv-prog', '11 / 11');
  await f.dire('Toutes les questions sont passées : le dernier écran, « <b>Pour demain</b> ».', 4200);
  await B.cadreTout(f, '#rv .rv-bul, #rv [data-rvdemain]', 'Un mot pour demain');
  await f.dire('Un <b>mot pour l’équipe de demain</b>, facultatif : il reste dans le bilan de la journée.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvprec]', { apres: 900 });
  await B.cadre(f, '#rv .rv-rep', 'La réponse donnée');
  await f.dire('<b>‹</b> revient sur une réponse : elle est là, avec son commentaire.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvcomouvre]', { apres: 600 });
  await B.cadre(f, '#rv .rv-com', 'Le commentaire');
  await f.dire('Le <b>commentaire</b> se reprend aussi : on le complète, ou on le laisse.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvcomok]', { apres: 700 });
  await f.dire('Un tap confirme la réponse, ou une autre la remplace, et l’on revient ici.', 3800);
  await f.cliquer('#rv [data-rvpt="En partie"]', { apres: 1100 });
  await B.cadre(f, '#rv .rv-pied', 'Clôturer la journée');
  await f.dire('Chaque réponse est déjà enregistrée. « <b>Clôturer la journée</b> » fige la revue ; elle ne se change plus sans la rouvrir.', 6200);
  await f.dire('Si l’enregistrement ne passe pas, l’écran le dit, « Pas enregistrée », et l’on réessaie.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.pause(500);

  /* Clôturer la journée */
  await f.fonction({ id: 'barre-soir-cloturer', titre: 'Clôturer la journée',
    resume: 'Le mot pour l’équipe de demain, puis « Clôturer la journée » : la revue est figée, le bilan s’affiche et la vignette dit « faite ».',
    points: ['Le mot pour demain', '« Clôturer la journée »', 'Le bilan'] });
  await B.cadre(f, '#rv .rv-ctx', 'Pour demain');
  await f.pause(900);
  await f.cacher(150);
  await f.cliquer('#rv [data-rvdemain]', { apres: 300 });
  await f.taper('Commande traiteur de 8 h à préparer dès l’ouverture.', 45);
  await f.cliquer('#rv [data-rvcloturer]', { apres: 1500 });
  await B.cadre(f, '#rv .rv-fin', 'Journée clôturée');
  await f.dire('<b>Journée clôturée</b>, à l’heure, par Julie M. : le bilan s’affiche.', 4000);
  await B.cadre(f, '#rv .rv-sc4', 'Le bilan');
  await f.dire('Le bilan : tâches pas faites, checklists faites et pas faites, gestion en ordre.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv .rv-hd [data-rvfermer]', { apres: 1200 });
  await f.montrer('#dash .db-avs [data-rvouvrir]', 'Faite');
  await f.dire('La vignette dit « <b>faite</b> ».', 2600);
  await f.cacher(300); await f.taire(150);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
