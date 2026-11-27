/* Mon équipe › Planning › « Absences, congés et remplacements » (?vue=equipe, 27/11/2026) :
 *   onglet    equipe-absences     depuis son service : « Absente ou en congé », le motif, la période, le remplaçant ;
 *                                 l'absence payée garde son coût et sort de la présence, le remplaçant ajoute le sien
 *   fonction  equipe-conge        « Absence ou congé » dans la barre : un congé payé posé d'avance, son remplaçant
 *   fonction  equipe-exterieur    un intérimaire : son nom, son taux ; la ligne des extérieurs
 * Données fictives : mocks/20_equipe.js (semaine du 16 au 22 novembre 2026, jeudi 19 = aujourd'hui). Chaque phrase vient
 * de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe_absences.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

const svc = (date, emp) => `[data-eqp-cell="${date}"][data-emp="${emp}"] [data-eqp-svc]`;
const abs = (date, emp) => `[data-eqp-cell="${date}"][data-emp="${emp}"] [data-eqp-abs]`;

module.exports = async function () {
  const f = await Film.ouvrir('02-19-absences', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES.concat(['panel/src/equipe_absences.php']),
    avant: async p => { await E.planning(p); } });
  const p = f.p;
  /** Choisir dans une liste de la fenêtre (le menu natif ne se filme pas) : le curseur va sur la liste, puis le choix. */
  const choisir = async (sel, v) => { await f.survoler(sel); await p.selectOption(sel, v); await f.pause(900); };

  /* L'onglet */
  await f.onglet({
    id: 'equipe-absences', titre: 'Absences, congés et remplacements',
    resume: 'Depuis son service, « Absente ou en congé » : sous contrat de travail, l’absence payée (maladie, congé payé, circonstance) garde son coût et sort de la présence ; un remplaçant aux mêmes heures ajoute le sien.',
    accroche: 'Le coût reste, le remplaçant s’ajoute',
    programme: ['Marquer une personne absente', 'La remplacer aux mêmes heures', 'Poser un congé payé', 'Un remplaçant extérieur'],
  });

  /* Absente ou en congé, depuis son service (l'onglet lui-même) */
  await E.sousLaTete(f, svc('2026-11-18', 201));
  await f.cliquer(svc('2026-11-18', 201), { apres: 1200 });
  await f.montrer('[data-eqp-absente]', 'Absente ou en congé');
  await f.dire('Camille est malade : dans la fenêtre de son service, « <b>Absente ou en congé</b> ».', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-absente]', { apres: 1800 });
  await f.montrer('.eqp-ab-note', 'Contrat de travail');
  await f.dire('Sous <b>contrat de travail</b>, ses heures restent payées : elles restent au budget, mais elle ne compte plus comme présence.', 5400);
  await f.montrer('.eqp-ab-ms', 'Pourquoi');
  await f.dire('Le motif : maladie, congé payé, congé de circonstance. L’<b>absence non payée</b>, elle, retire le coût.', 4800);
  await f.montrer('[data-eqp-ab-au]', 'Jusqu’à vendredi');
  await f.dire('Un certificat jusqu’à vendredi : on allonge la période.', 3200);
  await f.cacher(150);
  await p.fill('[data-eqp-ab-au]', '2026-11-20'); await p.dispatchEvent('[data-eqp-ab-au]', 'change');
  await p.waitForSelector('.eqp-ab-fo .eqp-ab-t table', { timeout: 15000 }); await f.pause(700);
  await f.cliquer('[data-eqp-ab-cert]', { apres: 600 });
  await f.montrer('.eqp-ab-t', 'Jour par jour');
  await f.dire('Jour par jour : ce qui reste payé, le budget du jour, et le comptoir.', 4000);
  await f.montrer('[data-eqp-ab-rp="2026-11-18"]', 'Remplacer par');
  await f.dire('« <b>Remplacer par</b> » : ceux qui sont libres à ces heures, ceux du même rôle d’abord, chacun avec ce qu’il coûte.', 5400);
  await f.cacher(150); await f.taire(150);
  await choisir('[data-eqp-ab-rp="2026-11-18"]', 'e:208');
  await f.montrer('.eqp-ab-fo .eqp-fo-effs', 'L’effet');
  await f.dire('Inès la remplace mercredi : <b>+81 €</b>. Camille reste payée : 201 € sur la période.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ab-ok]', { apres: 2400 });
  await f.montrer('tr:has-text("Camille Roussel")', 'Camille');
  await f.dire('Dans la semaine, ses services sont <b>hachurés</b>, avec qui la remplace ; vendredi est encore « À remplacer ».', 5200);
  await f.defiler(-3000);
  await f.montrer('.eqp-rs-abs', 'Absences et congés');
  await f.dire('Le résumé dit ce que coûtent les absences, et le budget sans elles.', 4000);
  await f.cacher(150); await f.taire(150);

  /* Un congé payé, posé d'avance */
  await f.fonction({ id: 'equipe-conge', titre: 'Poser un congé payé',
    resume: '« Absence ou congé » dans la barre : la personne, du … au … ; chaque jour où elle travaillait devient congé payé, avec son remplaçant.',
    points: ['« Absence ou congé »', 'La personne et la période', 'Un remplaçant par jour', '« Poser l’absence »'] });
  await f.montrer('[data-eqp-abs-nouv]', 'Absence ou congé');
  await f.dire('Un congé se pose d’avance : « <b>Absence ou congé</b> », dans la barre du planning.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-abs-nouv]', { apres: 1400 });
  await choisir('[data-eqp-ab-emp]', '206');
  await p.waitForSelector('.eqp-ab-fo .eqp-ab-t table', { timeout: 15000 }); await f.pause(700);
  await f.montrer('.eqp-ab-ms', 'Congé payé');
  await f.dire('Fanny, en <b>congé payé</b> jeudi : ses heures restent payées.', 3600);
  await f.cacher(150); await f.taire(150);
  await choisir('[data-eqp-ab-rp="2026-11-19"]', 'e:210');
  await f.montrer('.eqp-ab-t', 'Lucie la remplace');
  await f.dire('Lucie la remplace aux mêmes heures : le budget du jour monte, et on le voit avant d’enregistrer.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ab-ok]', { apres: 2400 });

  /* Un remplaçant extérieur */
  await f.fonction({ id: 'equipe-exterieur', titre: 'Un remplaçant extérieur',
    resume: 'Un intérimaire ou un extra : son nom et son taux horaire ; son coût s’ajoute au budget, sa facture passe par les frais.',
    points: ['La case « À remplacer »', '« Quelqu’un d’extérieur »', 'Son taux horaire', 'Enregistrer'] });
  await E.sousLaTete(f, abs('2026-11-20', 201));
  await f.montrer(abs('2026-11-20', 201), 'À remplacer');
  await f.dire('Vendredi, Camille est encore <b>à remplacer</b> : un clic sur sa case rouvre l’absence.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(abs('2026-11-20', 201), { apres: 1800 });
  await choisir('[data-eqp-ab-rp="2026-11-20"]', 'x');
  await f.montrer('.eqp-ab-ext', 'Extérieur');
  await f.dire('« <b>Quelqu’un d’extérieur</b> » : son nom et son taux horaire, celui de la facture de l’agence.', 4600);
  await f.cliquer('[data-eqp-ab-xtaux]', { apres: 200 });
  await f.taper('24.50');
  await p.dispatchEvent('[data-eqp-ab-xtaux]', 'change'); await f.pause(900);
  await f.montrer('.eqp-ab-fo .eqp-fo-effs', 'L’effet');
  await f.dire('Son coût s’ajoute : <b>+153 €</b>, et le comptoir est tenu.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ab-ok]', { apres: 2400 });
  await f.montrer('tr.eqp-ext-l', 'Extérieurs');
  await f.dire('Les extérieurs ont leur ligne : connus du cockpit, pas du panel.', 3600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
