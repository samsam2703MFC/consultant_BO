/* Mon équipe › Planning › l'onglet « Jour » (?vue=equipe), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    equipe-jour        un clic sur un jour l'ouvre en grand ; les chiffres, le budget du jour, le planogramme,
 *                                la présence face aux tickets, les temps morts, les linéaires ; les flèches, les constats
 *   fonction  equipe-tracer      Camille, au repos jeudi : une ligne tirée de 14 h à 18 h, « Ajouter le service »
 *   fonction  equipe-deplacer    le bord de la barre de Fanny tiré de 13 h à 13 h 30, « Enregistrer »
 * Données fictives : mocks/20_equipe.js (jeudi 19 novembre 2026 = aujourd'hui, 15 h 40). Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-03-jour', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.planning(p); } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-jour', titre: 'Le planning du jour',
    resume: 'Le planogramme du jour par section, le budget du jour, la présence face aux tickets habituels, les temps morts, les linéaires et les constats.',
    accroche: 'Qui est là, heure par heure',
    programme: ['Tracer un service à la souris', 'Déplacer ou étirer un service'],
  });
  await f.montrer('[data-eqp-jour="2026-11-19"]', 'Jeudi 19');
  await f.dire('Un clic sur un jour l’ouvre en grand : la vue <b>Jour</b>. Le bouton « Jour » aussi.', 4000);
  await f.cacher(150);
  await f.cliquer('[data-eqp-jour="2026-11-19"]', { apres: 1500 });
  await f.montrer('.eqp-rs-l1', 'Le budget du jour');
  await f.dire('En tête, le <b>budget du jour</b> : aujourd’hui face au CA projeté, un jour passé face à la caisse ; les heures et ce qu’il faut retirer.', 5400);
  await f.defilerVers(f.p.locator('.eqp-pg'), 24);
  await f.montrerTout('.eqp-pg .eqp-l:has([data-eqp-tl])', 'Le planogramme');
  await f.dire('Le <b>planogramme</b> : une ligne par personne, groupées par section ; chaque service est une barre.', 4800);
  await f.montrer('.eqp-pg .eqp-pres', 'Présence');
  await f.dire('La présence, quart d’heure par quart d’heure, face aux <b>tickets</b> des quatre jeudis d’avant.', 4600);
  await f.montrerTout('.eqp-pg .eqp-tml', 'Temps morts');
  await f.dire('Les <b>temps morts</b> : 10 minutes et plus sans ticket, d’habitude, et aujourd’hui.', 4200);
  await f.montrerTout('.eqp-pg .eqp-li:not(.eqp-pres):not(.eqp-tml)', 'Les linéaires');
  await f.dire('Les linéaires : qui tient chaque secteur et le comptoir, d’après les postes de chacun.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-pas="-1"]', { apres: 1100 });
  await f.cliquer('[data-eqp-pas="-1"]', { apres: 1400 });
  await f.montrer('.eqp-rs-l2', 'Ce jour-là');
  await f.dire('Les flèches changent de jour. Mardi, <b>ce jour-là</b> : le comptoir vide de 13 h 45 à 14 h 30 ; un clic montre sa ligne.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-auj]', { apres: 1400 });
  await f.dire('« <b>Aujourd’hui</b> » ramène à jeudi.', 2600);
  await f.taire(150);

  /* Tracer un service à la souris */
  await f.fonction({ id: 'equipe-tracer', titre: 'Tracer un service à la souris',
    resume: 'Au jour, sur la ligne d’une personne au repos : tirer de l’heure de début à l’heure de fin, puis ajouter le service.',
    points: ['Vue Jour', 'Sur la ligne d’une personne au repos', 'Tirer du début à la fin', '« Ajouter le service »'] });
  const cam = '[data-eqp-tl="201"]';
  await f.montrer(cam, 'Camille est au repos');
  await f.dire('Camille est au repos jeudi. Pas de formulaire : on <b>tire une ligne</b> sur la sienne.', 4200);
  await f.cacher(150); await f.taire(150);
  const r = await f.rect(cam);
  await f.tirer(E.xJour(r, '14:00'), r.y + r.h / 2, E.xJour(r, '18:00'), r.y + r.h / 2, 1800);
  await f.montrer('.eqp-fo-tl', 'De 14 h à 18 h');
  await f.dire('La fenêtre s’ouvre sur ces heures, de <b>14 h à 18 h</b>, avec l’effet sur le budget du jour.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ok]', { apres: 1700 });
  await f.montrer(cam, 'Au planning');
  await f.dire('Le service est au planning : la ligne de Camille et le budget du jour sont à jour.', 4000);
  await f.cacher(300); await f.taire(150);

  /* Déplacer ou étirer un service */
  await f.fonction({ id: 'equipe-deplacer', titre: 'Déplacer ou étirer un service',
    resume: 'Au jour : glisser une barre la déplace, tirer un de ses bords change une heure, au quart d’heure ; puis enregistrer.',
    points: ['Glisser la barre pour la déplacer', 'Ou tirer un de ses bords', 'La fenêtre s’ouvre', '« Enregistrer »'] });
  const fan = '[data-eqp-tl="206"] [data-eqp-bar]';
  await f.montrer(fan, 'Fanny · 06:45–13:00');
  await f.dire('Glisser une barre la <b>déplace</b> ; tirer un de ses bords change une heure, au quart d’heure.', 4400);
  await f.cacher(150); await f.taire(150);
  const b = await f.rect(fan), ligne = await f.rect('[data-eqp-tl="206"]');
  // La poignée du bord droit : la barre coupe ce qui dépasse (overflow hidden) ; on la prend dans ses 3 px visibles.
  await f.tirer(b.x + b.w - 2, b.y + b.h / 2, E.xJour(ligne, '13:30') - 2, b.y + b.h / 2, 1400);
  await f.montrer('.eqp-fo-tl', '13:00 → 13:30');
  await f.dire('Fanny finit à <b>13 h 30</b> : l’ancien horaire reste en fond, l’effet se recalcule.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ok]', { apres: 1700 });
  await f.montrer('[data-eqp-tl="206"]', 'Enregistré');
  await f.dire('Rien n’est écrit au panel avant « <b>Enregistrer</b> ». C’est fait.', 3400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
