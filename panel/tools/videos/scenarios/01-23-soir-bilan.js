/* La barre du haut › « Revue du soir » › le bilan (?vue=ops), 26/11/2026 :
 *   onglet    barre-soir-bilan     la revue clôturée : « Journée clôturée », les quatre chiffres, une carte par zone
 *                                  (Vente, Boulangerie, Traiteur, Nettoyage), la gestion en tuiles, le mot pour demain
 *   fonction  barre-soir-rouvrir   « Rouvrir la revue », corriger une réponse, clôturer de nouveau
 * Départ (hors film) : la revue du jour clôturée, toutes les réponses données. Données fictives :
 * mocks/11_barre_soir.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rvEcran « fin », rvOuvrir, le
 * bouton « Rouvrir la revue ») et de panel/src/revue_soir.php (rsRevueEcrire : « rouvrir »). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const R = () => Object.assign({}, B.REPONSES(), { demain: 'Commande traiteur de 8 h à préparer dès l’ouverture.' });
  const f = await Film.ouvrir('01-23-soir-bilan', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/revue_soir.php'],
    avant: async p => { await B.soirFilm(p); await B.prioritaires(p); await B.soirEtat(p, { par: 'Julie M.', statut: 'cloturee', reponses: R() }); await B.relire(p); },
  });

  await f.onglet({
    id: 'barre-soir-bilan', titre: 'La revue du soir : le bilan',
    resume: 'La revue clôturée : les chiffres de la journée, une carte par zone avec chaque checklist faite ou pas et sa raison, la gestion en tuiles, le mot pour demain, et « Rouvrir la revue ».',
    accroche: 'La journée, en un écran',
    programme: ['Les chiffres de la journée', 'Une carte par zone', 'La gestion en tuiles', 'Rouvrir une revue clôturée'],
  });
  await f.montrer('#dash .db-avs [data-rvouvrir]', 'Faite');
  await f.dire('La revue est faite : la vignette le dit. Un clic rouvre son <b>bilan</b>.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-rvouvrir]', { apres: 1500 });
  await B.cadre(f, '#rv .rv-fin', 'Journée clôturée');
  await f.dire('« <b>Journée clôturée</b> », à quelle heure et par qui.', 3400);
  await B.cadre(f, '#rv .rv-sc4', 'Les chiffres');
  await f.dire('Les tâches pas faites, les checklists faites ✓ et pas faites ✗, les points de gestion en ordre.', 5200);
  await B.cadre(f, '#rv .rv-zc >> nth=0', 'Une carte par zone');
  await f.dire('Puis une carte par <b>zone</b> — Vente, Boulangerie, Traiteur, Nettoyage — et son score.', 4800);
  await B.cadre(f, '#rv .rv-cl.e-ko >> nth=0', 'Pas faite : sa raison');
  await f.dire('Chaque checklist : ✓ faite, ou ✗ avec la raison du soir et ses tâches pas faites.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.defiler(420, '#rv .rv-sc');
  await B.cadre(f, '#rv .rv-cl.e-npc >> nth=-1', 'Faite, pas cochée');
  await f.dire('« Fait, pas coché » compte comme faite : un ✓ vert clair.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.defiler(700, '#rv .rv-sc');
  await B.cadre(f, '#rv .rv-tu', 'La gestion');
  await f.dire('La <b>gestion</b>, en tuiles : vert en ordre, rouge à reprendre.', 3800);
  await B.cadre(f, '#rv .rv-dem', 'Pour demain');
  await f.dire('Et le <b>mot pour demain</b>.', 2600);
  await B.cadre(f, '#rv .rv-pied', 'Fermer · Rouvrir');
  await f.dire('« Fermer », ou « <b>Rouvrir la revue</b> » pour corriger une réponse.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.pause(400);

  /* Rouvrir une revue clôturée */
  await f.fonction({ id: 'barre-soir-rouvrir', titre: 'Rouvrir une revue clôturée',
    resume: '« Rouvrir la revue » : on corrige une réponse, puis on avance jusqu’à « Clôturer la journée » ; le bilan se refait.',
    points: ['« Rouvrir la revue »', 'Corriger une réponse', 'Clôturer de nouveau'] });
  await f.cliquer('#rv [data-rvrouvrir]', { apres: 1200 });
  await B.cadre(f, '#rv .rv-rep', 'Les réponses gardées');
  await f.dire('La revue se rouvre sur la première checklist ; les réponses sont gardées.', 4000);
  await f.dire('Correction : le réassort avait été fait, pas coché.', 1500);
  await f.cliquer('#rv [data-rvcl="Fait, pas coché"]', { apres: 1000 });
  await f.cacher(100);
  await f.dire('Puis un tap sur chaque réponse gardée, jusqu’à la fin.', 1200);
  for (let i = 0; i < 10; i++) {
    const suiv = f.p.locator('#rv [data-rvsuivant]');
    if (await suiv.count()) { await f.cliquer(suiv.first(), { apres: 650 }); continue; }
    if (await f.p.locator('#rv [data-rvdemain]').count()) { break; }
    await f.cliquer('#rv .rv-chip.on', { apres: 650 });
  }
  await f.taire(150);
  await f.cliquer('#rv [data-rvcloturer]', { apres: 1500 });
  await B.cadre(f, '#rv .rv-zc >> nth=0', 'Vente : 2 / 2');
  await f.dire('<b>Clôturée de nouveau</b> : le bilan est refait, la checklist du comptoir passe au vert.', 4600);
  await f.cacher(300); await f.taire(150);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
