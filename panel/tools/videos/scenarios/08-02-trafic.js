/* Année › « Le trafic : Clients · Panier moyen · Articles par client », la vidéo de l'onglet (26/11/2026) :
 *   onglet  annee-trafic   la carte « Le trafic — 2026 face à 2025 » : chaque mois face au même mois de l'an passé, le
 *                          mois en cours prolongé au rythme de ses jours, les trois tuiles ; les trois mesures ; le
 *                          détail replié en bas : « Le détail des mois », « Le long terme ».
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/08_annee.js
 * (/ventes/semaines?vue=annee, /stores/perf, /plan). Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (anTrafic, anTableMois, anRampe, anPli, les gestes data-anm). */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const TR = '#dash .db-card:has([data-anm])';

module.exports = async function () {
  const f = await ouvrir('08-02-trafic', 'annee', { sources: ['consultant/src/plan.php'] });

  await f.onglet({
    id: 'annee-trafic', titre: 'Le trafic, mois par mois',
    resume: 'Les clients, le panier moyen ou les articles par client de chaque mois, face au même mois de l’an passé ; le mois en cours prolongé au rythme de ses jours ; le détail des mois et le long terme, repliés en bas.',
    accroche: 'L’année face à l’an passé',
    programme: ['Clients', 'Panier moyen', 'Articles par client', 'Le détail des mois'],
  });

  await enHautDe(f, TR, 60);
  await dire(f, TR, 'Le trafic', '<b>Le trafic</b> : les clients de chaque mois, en pointillé ceux du même mois de l’an passé, mois entier.', 5600);
  await f.dire('Le mois en cours est <b>prolongé</b> au rythme de ses jours ; son écart se lit aux mêmes jours de l’an passé.', 5600);
  await f.montrerTout(TR + ' .db-tui', 'Trois tuiles');
  await f.dire('À droite : l’année face à l’an passé, le mois en cours, le meilleur mois.', 4200);
  await rien(f);
  await f.cliquer(TR + ' [data-anm="panier"]', { apres: 1300 });
  await dire(f, TR, 'Panier moyen', 'Le même graphique pour le <b>panier moyen</b>…', 3200);
  await rien(f);
  await f.cliquer(TR + ' [data-anm="articles"]', { apres: 1300 });
  await dire(f, TR, 'Articles par client', '… et les <b>articles par client</b>. Le choix est gardé.', 3600);
  await rien(f);
  await f.cliquer(TR + ' [data-anm="clients"]', { apres: 1000 });

  /* Le détail replié */
  await enHautDe(f, '#dash details[data-anpli=mois]', 300);
  await f.cliquer('#dash details[data-anpli=mois] > summary', { apres: 1400 });
  await enHautDe(f, '#dash details[data-anpli=mois]', 20);
  await dire(f, '#dash details[data-anpli=mois]', 'Le détail des mois', '<b>Le détail des mois</b> : chiffre, budget, atteinte, clients, panier, food, labour, overhead et marge nette.', 6000);
  await rien(f);
  await f.cliquer('#dash details[data-anpli=rampe] > summary', { apres: 1400 });
  await enHautDe(f, '#dash details[data-anpli=rampe]', 20);
  await dire(f, '#dash details[data-anpli=rampe]', 'Le long terme', '<b>Le long terme</b> : la rampe à cinq ans de l’étude de marché, et l’engagement pris sur l’année.', 5600);
  await f.dire('Un pli ouvert le reste.', 2400);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
