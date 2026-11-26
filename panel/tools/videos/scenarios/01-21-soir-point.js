/* La barre du haut › « Revue du soir » › un point de gestion (?vue=ops), 26/11/2026 :
 *   onglet    barre-soir-point   un point par écran : le constat lu par le serveur (un point « auto » pas en ordre),
 *                                la question, la réponse en un tap, « + commentaire » ; puis un point « question »,
 *                                ce que le serveur ne peut pas voir
 * Aucune fonction en chapitre : répondre est le geste de « Faire la revue du soir » (01-18-soir-resume), montré ici
 * écran par écran. Départ (hors film) : les checklists expliquées, la revue rouverte jusqu'au premier point.
 * Données fictives : mocks/11_barre_soir.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rvEcran « pt »)
 * et de panel/src/revue_soir.php (rsDefaut : les points « auto » et « question »). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-21-soir-point', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/revue_soir.php'],
    avant: async p => {
      await B.soirFilm(p); await B.prioritaires(p);
      await B.soirEtat(p, { par: 'Julie M.', statut: 'encours', reponses: { checklists: B.REPONSES().checklists, points: {}, demain: '' } });
      await B.relire(p);
      await B.soirJusqua(p, '[data-rvpt]');
    },
  });

  await f.onglet({
    id: 'barre-soir-point', titre: 'La revue du soir : un point de gestion',
    resume: 'Un point de gestion par écran : le constat lu par le serveur, la question, la réponse en un tap, un commentaire ; puis ce que le serveur ne peut pas voir.',
    accroche: 'Le constat, la question, la réponse',
    programme: ['Le constat lu par le serveur', 'La réponse en un tap', 'Ce que le serveur ne voit pas'],
  });
  await B.cadre(f, '#rv .rv-ctx', 'Un point de gestion');
  await f.dire('Un <b>point de gestion</b> par écran : ici, les commandes clients.', 3800);
  await B.cadre(f, '#rv .rv-bul', 'Le constat');
  await f.dire('D’abord le <b>constat</b> lu par le serveur : une commande passée encore « en cours » au panel.', 5000);
  await f.dire('Puis la question : on les clôture ?', 2600);
  await B.cadre(f, '#rv .rv-rep', 'Les réponses');
  await f.dire('Les réponses prévues, en un tap ; « Autre… » pour la sienne.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvpt="Clôturées maintenant"]', { apres: 1100 });
  await B.cadre(f, '#rv .rv-bul', '4 photos sur 5');
  await f.dire('Le point suivant : <b>4 photos rendues sur 5</b>. Des photos de contrôle manquent : pourquoi ?', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvcomouvre]', { apres: 500 });
  await f.taper('la photo de 14 h est restée sur le téléphone', 45);
  await f.cliquer('#rv [data-rvcomok]', { apres: 700 });
  await B.cadre(f, '#rv .rv-lien', 'Un commentaire');
  await f.dire('Un <b>commentaire</b> précise la réponse.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvpt="Faites, pas envoyées"]', { apres: 1100 });
  await B.cadre(f, '#rv .rv-bul', 'Une question');
  await f.dire('Les points « <b>question</b> » : ce que le serveur ne peut pas voir, comme les retours donnés à l’équipe.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvpt="Oui, au briefing"]', { apres: 1100 });
  await B.cadre(f, '#rv .rv-ctx', 'Caisse clôturée');
  await f.dire('Puis la caisse, la commande fournisseurs, demain : une réponse chacun.', 4000);
  await f.cacher(300); await f.taire(150);
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
