/* Formations et suivi › Mon dossier de formation, et ses fonctions en chapitres (26/11/2026) :
 *   onglet    former-dossier            le bandeau de l'accueil l'ouvre (lecture) ; les Nouveautés, les quatre nombres,
 *                                       le dossier (statut, avancement, échéance), le suivi de l'équipe ; « Mon document
 *                                       de formation » mène au coffre-fort, dossier Formation (lecture) ; le Professeur
 *   fonction  former-ouvrir-formation   une carte des Nouveautés : la formation s'ouvre, « NOUVEAU » s'efface
 *   fonction  former-question-dossier   une question toute prête du Professeur, tirée du dossier : il répond
 * Données fictives : mocks/72_formation.js, 23_coffre.js, 71_assistant.js (professeurDossier). Chaque phrase vient de
 * panel/public/dashboard/formation.js (page, ouvrir, professeur) et coffre.js (le dossier Formation). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('22-04-dossier', {
    vue: 'formation', serie: 'Formations et suivi', parent: 'se-former',
    sources: ['panel/public/dashboard/formation.js', 'formation/src/formation_continue.php', 'panel/public/dashboard/coffre.js'],
    avant: async p => { await A.lenteur(p.context(), 1800); },
  });

  await f.onglet({
    id: 'former-dossier', titre: 'Mon dossier de formation',
    resume: 'Les Nouveautés, les quatre nombres, le dossier (statut, avancement, échéance), le suivi de l’équipe, le document de formation au coffre-fort, et le Professeur.',
    accroche: 'La formation continue de votre magasin',
    programme: ['Ouvrir une formation', 'Poser une question au Professeur sur son dossier'],
  });
  await f.montrer('.fs-mini', 'Ouvrir ›');
  await f.dire('Le bandeau de l’accueil ouvre <b>votre dossier de formation</b>.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-mini', { apres: 1300 });
  await f.montrer('.fs-nouv', 'Nouveautés');
  await f.dire('Les <b>nouveautés</b> : les formations continues reçues ces deux derniers mois, la plus récente d’abord.', 4800);
  await f.montrer('.fs-kpis', 'Où j’en suis');
  await f.dire('En retard, en cours, à valider chez votre consultant, validées : archivées dans votre coffre-fort.', 4800);
  await f.montrer(f.p.locator('.fs-carte', { has: f.p.locator('.fs-th') }), 'Le dossier');
  await f.dire('Chaque formation : son statut, son avancement, son échéance ; en retard, en rouge.', 4200);
  await f.cacher(150);
  await f.montrer('.fs-eq', 'Le suivi de l’équipe');
  await f.dire('Le <b>suivi de l’équipe</b> : qui a lu, réussi le quiz, été vu faire. C’est vous qui le déclarez.', 4800);
  await f.montrer('.fs-coffre', 'Mon document de formation');
  await f.dire('« Mon document de formation » : l’archive de chaque formation validée, dans votre coffre-fort.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-coffre', { apres: 2200 });
  await f.cliquer('.cf2-dos [data-cf-dos="formation"]', { apres: 1200 });
  await f.montrer('.cf2-dos [data-cf-dos="formation"]', 'Formation');
  await f.dire('Au coffre-fort, le dossier <b>Formation</b> les range.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-rail .sidebar__item[data-vue=formation]', { apres: 1500 });
  await f.montrer('.fs-prof', 'Le Professeur');
  await f.dire('De retour au dossier : à droite, le <b>Professeur</b>, et ses questions tirées de vos formations.', 4600);
  await f.montrer('.fs-retour', 'L’accueil');
  await f.dire('« ← Accueil de la formation » ramène à l’accueil.', 3000);
  await f.taire(150); await f.cacher(300);

  /* Ouvrir une formation */
  await f.fonction({ id: 'former-ouvrir-formation', titre: 'Ouvrir une formation',
    resume: 'Une carte des Nouveautés ou une ligne du dossier : la formation s’ouvre, sa première ouverture est notée et « NOUVEAU » s’efface. « ← Mon dossier » pour revenir.',
    points: ['Une carte des Nouveautés', 'ou « Ouvrir » sur une ligne du dossier', '« ← Mon dossier »'] });
  await f.montrer('.fs-nc[data-fs-ouvrir="33"]', 'NOUVEAU');
  await f.dire('Saint-Nicolas, reçue hier : pas encore ouverte.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-nc[data-fs-ouvrir="33"]', { apres: 1300 });
  await f.montrer('.fs-fh', 'La formation');
  await f.dire('Elle s’ouvre : ses étapes, et ce qu’il reste à faire.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-fh [data-fs-fermer]', { apres: 1500 });
  await f.montrer('.fs-nc[data-fs-ouvrir="33"]', 'Ouverte');
  await f.dire('« ← Mon dossier » : la carte n’est plus « NOUVEAU ». Son ouverture est notée.', 4200);
  await f.taire(150); await f.cacher(300);

  /* Poser une question au Professeur sur son dossier */
  await f.fonction({ id: 'former-question-dossier', titre: 'Poser une question au Professeur sur son dossier',
    resume: 'Une question toute prête tirée du dossier, ou la sienne puis « Demander » : la fenêtre du Professeur répond à partir du dossier du magasin.',
    points: ['Le Professeur, à droite du dossier', 'Une question toute prête, ou la vôtre', 'Il répond à partir du dossier'] });
  await f.montrer('.fs-prof', 'Le Professeur');
  await f.dire('Ses questions viennent de vos échéances et de vos formations ; dessous, la vôtre, puis « Demander ».', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-prof [data-fs-prof="0"]', { apres: 300 });
  await f.dire('Le Professeur lit votre dossier…', 1800);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('#db-ia .ia-bulle.ia').last(), 'Sa réponse');
  await f.dire('D’après vos échéances : d’abord le nouveau four, en retard depuis le 15/11, puis la photo du jour de la bûche.', 5400);
  await f.dire('Il ne voit que le dossier de votre magasin.', 2800);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
