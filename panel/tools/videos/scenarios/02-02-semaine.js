/* Mon équipe › Planning › l'onglet « Semaine » (?vue=equipe), et sa fonction en chapitre (24/11/2026) :
 *   onglet    equipe-semaine           les chiffres, la jauge du budget, le tableau, « Par jour », les linéaires,
 *                                      les flèches, « Imprimer le planning » (lecture : le PDF se télécharge, hors film)
 *   fonction  equipe-ajouter-service   « Ajouter un service » et « + Ajouter » dans une case vide (fusionnés) : Inès,
 *                                      mardi, de 13 h 30 à 17 h 30, comble le trou du comptoir
 * Données fictives : mocks/20_equipe.js (semaine du 16 au 22 novembre 2026). Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-02-semaine', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-semaine', titre: 'Le planning de la semaine',
    resume: 'Une case par personne et par jour, la jauge du budget d’heures face à la cible de 17 %, la ligne « Par jour » et les linéaires avec leurs trous.',
    accroche: 'Faire ses horaires à la semaine',
    programme: ['Ajouter un service'],
  });
  await f.cliquer('[data-eqp-onglet=planning]', { apres: 1400 });
  await f.montrer('.eqp-pbar .seg', 'Jour · Semaine · Mois');
  await f.dire('« <b>Planning</b> » s’ouvre sur la semaine : c’est là qu’on fait ses horaires.', 3800);
  await f.montrer('.eqp-nav', 'La semaine');
  await f.dire('Les flèches passent d’une semaine à l’autre…', 2600);
  await f.cacher(150);
  await f.cliquer('[data-eqp-pas="-7"]', { apres: 1300 });
  await f.cliquer('[data-eqp-auj]', { apres: 1300 });
  await f.dire('… et « <b>Cette semaine</b> » y revient.', 2600);
  await f.montrer('.eqp-rs-l1', 'La semaine en chiffres');
  await f.dire('En tête : la part du <b>personnel dans le CA</b>, et la jauge : <b>17 %</b>, la cible du réseau. Or au-dessus, rouge au-dessus de 20 %, noir au-dessus de 22 %.', 5600);
  await f.montrer('.eqp-rs-lev', 'Pour revenir à 17 %');
  await f.dire('À droite : les heures à retirer pour revenir à la cible.', 3200);
  await f.montrer('.eqp-rs-l2', 'À corriger');
  await f.dire('« <b>À corriger</b> » : les jours à venir qui ont un souci. Un clic ouvre le jour.', 4200);
  await f.montrer('.eqp-tab tbody tr:nth-child(2)', 'Une case par jour');
  await f.dire('Une ligne par personne, une case par jour : chaque service, avec sa petite barre sur la journée.', 4600);
  await f.montrer('.eqp-sem tr.eqp-pal', 'Budget du jour');
  await f.dire('Sous chaque jour : son <b>budget</b>, un ruban de couleur et son mot, les personnes et les heures. Cette tête reste en haut quand on descend.', 5400);
  await f.montrerTout('.eqp-sem tr.eqp-lin', 'Les linéaires');
  await f.dire('Les <b>linéaires</b> : qui tient chaque secteur et le comptoir. Mardi, le comptoir est vide de 13 h 45 à 14 h 30.', 5600);
  await f.cacher(200); await f.taire(200);
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-pdf]', { apres: 900 });
  await f.montrer('.eqp-pdf', 'Imprimer le planning');
  await f.dire('« <b>Imprimer le planning</b> » : à partir de quel jour, une ou deux semaines, tout le magasin ou une page par section.', 5600);
  await f.dire('« Télécharger le PDF » : une page A4 paysage par semaine, ou une par section.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-pdf]', { apres: 700 });

  /* Ajouter un service (« Ajouter un service » et « + Ajouter » dans une case vide, fusionnés) */
  await f.fonction({ id: 'equipe-ajouter-service', titre: 'Ajouter un service',
    resume: 'Une personne, un jour, ses heures tirées sur la ligne : l’effet se lit avant d’ajouter. Par le bouton, ou d’un clic sur une case vide.',
    points: ['« Ajouter un service », ou « + Ajouter » dans une case vide', 'Qui, et quel jour', 'Tirer la ligne des heures', '« Ajouter le service »'] });
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-ajouter]', { apres: 1000 });
  await f.montrer('.eqp-ed-ch', 'Qui · Jour');
  await f.dire('Le bouton « <b>Ajouter un service</b> » ouvre la fenêtre : qui, et quel jour.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fo-pied [data-eqp-annuler]', { apres: 700 });
  const cas = '[data-eqp-cell="2026-11-17"][data-emp="208"]';
  await f.montrer(cas, 'Inès, mardi');
  await f.dire('Plus court : un clic sur la case vide d’une personne ouvre la fenêtre <b>à son nom</b>, ce jour-là.', 4200);
  await f.cacher(150);
  await f.cliquer(cas, { apres: 1100 });
  await f.taire(150);
  await E.tracerLigne(f, '13:30', '17:30');
  await f.montrer('.eqp-fo-tl', 'Sa ligne');
  await f.dire('On tire sa ligne de <b>13 h 30 à 17 h 30</b> : verte, entre 4 et 8 heures.', 3400);
  await f.montrer('.eqp-fo-effs', 'L’effet');
  await f.dire('L’effet, avant d’ajouter : le comptoir <b>comblé</b>, mais mardi passe au rouge.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ok]', { apres: 1600 });
  await f.montrer(cas, 'Ajouté au planning', 1600);
  await f.montrerTout('.eqp-tab tr.eqp-lin', 'Les linéaires');
  await f.dire('Le service est au panel ; mardi, le trou du comptoir a disparu.', 3800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
