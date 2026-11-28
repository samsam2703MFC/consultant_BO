/* La barre du haut › « Revue du soir » › vérifié automatiquement (?vue=ops), 26/11/2026 :
 *   onglet    barre-soir-verifie   après les checklists : les points de gestion que leur sonde dit en ordre (invendus
 *                                  déclarés, stock compté, non-conformités reprises), sans question ; « Continuer »
 * Aucune fonction en chapitre : l'écran ne demande rien. Départ (hors film) : les trois checklists déjà expliquées,
 * la revue rouverte jusqu'à cet écran. Données fictives : mocks/11_barre_soir.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (rvEtapes, rvEcran « ordre » et « pt ») et de panel/src/revue_soir.php (rsSondes,
 * rsEvaluer, le réglage au cockpit). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-20-soir-verifie', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/revue_soir.php'],
    avant: async p => {
      await B.soirFilm(p); await B.prioritaires(p);
      await B.soirEtat(p, { par: 'Julie M.', statut: 'encours', reponses: { checklists: B.REPONSES().checklists, points: {}, demain: '' } });
      await B.relire(p);
      await B.soirJusqua(p, '.rv-ok');
    },
  });

  await f.onglet({
    id: 'barre-soir-verifie', titre: 'La revue du soir : vérifié automatiquement',
    resume: 'Après les checklists, les points de gestion que le serveur a déjà vérifiés en ordre — invendus déclarés, stock compté, non-conformités reprises — sans aucune question.',
    accroche: 'Ce qui est en ordre, sans question',
    programme: ['Ce que le serveur a lu', 'En ordre : rien à expliquer', 'Continuer'],
  });
  await B.cadre(f, '#rv .rv-ctx', 'Gestion · vérifié automatiquement');
  await f.dire('Après les checklists, la <b>gestion</b> : d’abord ce que le serveur a déjà vérifié tout seul.', 5000);
  await B.cadreTout(f, '#rv .rv-ok', 'En ordre');
  await f.dire('Chaque point dit ce que sa <b>sonde</b> a lu, au panel ou dans le cockpit.', 4200);
  await B.cadre(f, '#rv .rv-ok >> nth=0', 'Invendus encodés');
  await f.dire('Les <b>invendus</b> du jour sont déclarés au panel : 32 pièces, 31 € de coût.', 4400);
  await B.cadre(f, '#rv .rv-ok >> nth=1', 'Stock compté');
  await f.dire('Le <b>stock</b> a été compté il y a deux jours : moins de sept jours, c’est en ordre.', 4600);
  await B.cadre(f, '#rv .rv-ok >> nth=2', 'Non-conformités reprises');
  await f.dire('La <b>non-conformité</b> des 30 derniers jours a été reprise.', 3800);
  await B.cadre(f, '#rv .rv-ok ~ .rv-bul', 'Rien à expliquer');
  await f.dire('En ordre : <b>rien à expliquer</b>, aucune question.', 3200);
  await f.dire('Un point qui n’est pas en ordre, ou dont la donnée n’est pas revenue, devient une question, juste après.', 5600);
  await f.dire('Les points, leurs sondes et l’heure de la revue se règlent au cockpit du siège.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvsuivant]', { apres: 1000 });
  await B.cadre(f, '#rv .rv-ctx', 'Le premier point à expliquer');
  await f.dire('« <b>Continuer</b> » : le premier point de gestion à expliquer.', 3400);
  await f.cacher(300); await f.taire(150);
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
