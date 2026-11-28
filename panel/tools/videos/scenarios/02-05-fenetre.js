/* Mon équipe › « La fenêtre d'un service » (?vue=equipe), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    equipe-fenetre          un clic sur un service : la personne, les sections, la ligne à tirer, qui est
 *                                     là, l'effet, « Sur ce shift » et « Ouvrir » (le service d'un collègue)
 *   fonction  equipe-adapter          mardi, Camille commence à 13 h 45 au lieu de 14 h 30 : le comptoir est comblé
 *   fonction  equipe-ajouter-shift    samedi, Camille ajoutée sur le shift d'Inès (9 h – 13 h)
 *   fonction  equipe-retirer          « Retirer du shift » (Inès, vendredi) et « Retirer de l'horaire de la semaine »
 *                                     (Lucie ne travaille pas ; une absence se pose avec « Absente ou en congé »), fusionnés ; les confirmations du navigateur redessinées
 *   fonction  equipe-parcourir        la section « Comptoir », ‹ ›, « Enregistrer et suivant »
 *   fonction  equipe-creneau          l'option Matrice allumée : lundi, la carte « Matin » pour Camille
 * Données fictives : mocks/20_equipe.js (semaine du 16 au 22 novembre 2026). Chaque phrase vient de
 * panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

const svc = (date, emp) => `[data-eqp-cell="${date}"][data-emp="${emp}"] [data-eqp-svc]`;

module.exports = async function () {
  const f = await Film.ouvrir('02-05-fenetre', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.planning(p); } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-fenetre', titre: 'La fenêtre d’un service',
    resume: 'Les sections et les personnes du jour, la ligne des heures à tirer, qui est là ce jour, l’effet (comptoir, budget du jour et de la semaine, ses heures) et ceux du même shift.',
    accroche: 'Un clic, et on adapte',
    programme: ['Adapter les heures d’un service', 'Ajouter une personne sur le même shift', 'Retirer du shift ou de l’horaire', 'Parcourir le jour personne par personne', 'Choisir un créneau de la matrice'],
  });
  await E.sousLaTete(f, svc('2026-11-20', 201));
  await f.montrer(svc('2026-11-20', 201), 'Vendredi · Camille');
  await f.dire('Un clic sur un service ouvre sa <b>fenêtre</b> : la même au jour, à la semaine et au mois.', 4200);
  await f.cacher(150); await f.taire(150);
  await E.sousLaTete(f, svc('2026-11-20', 201));
  await f.cliquer(svc('2026-11-20', 201), { apres: 1200 });
  await f.montrer('.eqp-fo-tete', 'Le service');
  await f.dire('La personne, ses postes, le jour, et ses heures au planning.', 3200);
  await f.montrer('.eqp-fo-nav', 'Sections · personnes');
  await f.dire('Les sections du jour et chacun avec ses heures : on passe de l’un à l’autre.', 4000);
  await f.montrer('.eqp-fo-tl', 'Sa ligne');
  await f.dire('Sa <b>ligne</b>, sur l’axe des heures : on la tire au quart d’heure. Verte de 4 à 8 heures, rouge sinon.', 5000);
  await f.montrerTout('.eqp-fo-plan > .eqp-fo-l', 'Qui est là ce jour');
  await f.dire('Dessous, à la même échelle : qui est là ce jour, et le <b>comptoir</b>.', 3600);
  await f.montrer('.eqp-fo-effs', 'L’effet');
  await f.dire('L’effet : le comptoir, le budget du jour et de la semaine, ses heures de la semaine.', 4200);
  await E.sousTitre(f, 'haut');
  await f.montrer('.eqp-shift', 'Sur ce shift');
  await f.dire('« <b>Sur ce shift</b> » : ceux qui travaillent en même temps, à ouvrir ou à retirer.', 4000);
  await f.cacher(150); await f.taire(150);
  await E.sousTitre(f, 'bas');
  await f.cliquer('.eqp-shift-l:has-text("Inès") [data-eqp-ouvrir-svc]', { apres: 1100 });
  await f.montrer('.eqp-fo-tete', 'Inès');
  await f.dire('« Ouvrir » passe au service d’un collègue du shift.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fo-pied [data-eqp-annuler]', { apres: 800 });

  /* Adapter les heures d'un service */
  await f.fonction({ id: 'equipe-adapter', titre: 'Adapter les heures d’un service',
    resume: 'Tirer un bord de la ligne du service, lire l’effet sur le comptoir et le budget, puis enregistrer au panel.',
    points: ['Ouvrir le service', 'Tirer un bord de sa ligne', 'Lire l’effet', '« Enregistrer »'] });
  await E.sousLaTete(f, svc('2026-11-17', 201));
  await f.cliquer(svc('2026-11-17', 201), { apres: 1200 });
  await f.montrer('.eqp-fo-plan .eqp-fo-l.cpt', 'Le comptoir');
  await f.dire('Mardi, le comptoir est vide de <b>13 h 45 à 14 h 30</b>. Camille commence à 14 h 30.', 4200);
  await f.cacher(150); await f.taire(150);
  await E.tirerBord(f, 'de', '13:45');
  await f.montrer('.eqp-fo-effs', 'L’effet');
  await f.dire('Le début à 13 h 45 : <b>le comptoir est comblé</b>, le budget passe d’avant à après.', 4400);
  await f.dire('Au clavier : les flèches sur un bord, Maj pour une heure entière.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ok]', { apres: 1600 });
  await f.montrerTout('.eqp-tab tr.eqp-lin', 'Les linéaires');
  await f.dire('Enregistré au panel : le trou a disparu des linéaires.', 3400);
  await f.cacher(300); await f.taire(150);

  /* Ajouter une personne sur le même shift */
  await f.fonction({ id: 'equipe-ajouter-shift', titre: 'Ajouter une personne sur le même shift',
    resume: 'Dans « Sur ce shift », choisir quelqu’un de libre : le coût et le budget du jour s’affichent, puis l’ajouter aux mêmes heures.',
    points: ['Ouvrir le service', '« + Ajouter une personne sur ce shift »', 'Lire le coût et le budget', '« Ajouter Prénom HH:MM–HH:MM »'] });
  await E.sousLaTete(f, svc('2026-11-21', 208));
  await f.cliquer(svc('2026-11-21', 208), { apres: 1200 });
  await E.sousTitre(f, 'haut');
  await f.montrer('.eqp-shift', 'Sur ce shift');
  await f.dire('Samedi, le shift d’Inès, de <b>9 h à 13 h</b> : ceux qui sont là en même temps.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-eqp-ajout-sel]', { pause: 300 });
  await f.p.locator('[data-eqp-ajout-sel]').selectOption('201'); await f.pause(900);
  await f.montrer('.eqp-shift', 'Camille · 09:00–13:00');
  await f.dire('Dans la liste, ceux qui sont libres sur ces heures. Camille : son coût, et le <b>budget du jour</b>.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ajout-ok]', { apres: 1900 });
  await f.montrer('.eqp-shift', 'Sur ce shift');
  await f.dire('Camille est sur le shift ; la fenêtre reste ouverte pour la suite.', 3600);
  await f.cacher(150); await f.taire(150);
  await E.sousTitre(f, 'bas');
  await f.cliquer('.eqp-fo-pied [data-eqp-annuler]', { apres: 800 });

  /* Retirer du shift, retirer de l'horaire de la semaine (fusionnés) */
  await f.fonction({ id: 'equipe-retirer', titre: 'Retirer du shift ou de l’horaire',
    resume: 'Retirer une personne d’un shift (son service de ce jour), ou de l’horaire de toute la semaine quand elle ne travaille pas, après confirmation. Malade ou en congé : « Absente ou en congé » (son coût reste).',
    points: ['Ouvrir le service', '« Retirer Prénom du shift » : ce jour-là', '« Retirer de l’horaire de la semaine » : elle ne travaille pas', 'Confirmer'] });
  const ven = `.eqp-sem tr.eqp-pal th:nth-child(${await E.colonne(f, '2026-11-20')})`;
  await f.montrer(ven, 'Vendredi');
  await f.dire('Vendredi est <b>rouge</b> : trop d’heures pour le CA du jour.', 3200);
  await f.cacher(150); await f.taire(150);
  await E.sousLaTete(f, svc('2026-11-20', 208));
  await f.cliquer(svc('2026-11-20', 208), { apres: 1200 });
  await f.montrer('[data-eqp-suppr]', 'Retirer du shift');
  await f.dire('« <b>Retirer Inès du shift</b> » : son service de ce jour est supprimé.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-eqp-suppr]', 'Retirer cette personne du shift ?', 'Son service de ce jour est supprimé du planning.', { apres: 1600 });
  await f.montrer(ven, 'Vendredi');
  await f.dire('Vendredi repasse en or : le pourcentage se recalcule aussitôt.', 3400);
  await f.cacher(150); await f.taire(150);
  await E.sousLaTete(f, svc('2026-11-16', 210));
  await f.cliquer(svc('2026-11-16', 210), { apres: 1200 });
  await f.montrer('[data-eqp-retirer-semaine]', 'Toute la semaine');
  await f.dire('Lucie ne travaille pas cette semaine : « <b>Retirer de l’horaire de la semaine</b> ». Malade ou en congé, c’est « Absente ou en congé » : son coût reste.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-eqp-retirer-semaine]', 'Retirer Lucie Bernard de l’horaire de la semaine du 16 novembre au 22 novembre ?', '2 services sont supprimés du planning du panel.', { apres: 1800 });
  await f.montrer('.eqp-tab tbody tr:has([data-emp="210"])', 'Lucie · plus aucun service', 1600);
  await f.montrerTout('.eqp-tab tr.eqp-lin', 'Les linéaires');
  await f.dire('Ses services sont supprimés ; les linéaires montrent quoi recouvrir : lundi matin, le comptoir.', 4400);
  await f.cacher(300); await f.taire(150);

  /* Parcourir le jour personne par personne */
  await f.fonction({ id: 'equipe-parcourir', titre: 'Parcourir le jour personne par personne',
    resume: 'Section par section, personne par personne : adapter chacun, puis « Enregistrer et suivant ».',
    points: ['Ouvrir un service', 'Choisir une section', '‹ › ou un clic sur une personne', '« Enregistrer et suivant »'] });
  await E.sousLaTete(f, svc('2026-11-19', 206));
  await f.cliquer(svc('2026-11-19', 206), { apres: 1200 });
  await f.montrer('.eqp-fo-secs', 'Les sections');
  await f.dire('Jeudi. En haut, les <b>sections</b> du jour, chacune avec ses présents.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fo-section="Comptoir"]', { apres: 900 });
  await f.montrer('.eqp-fo-pers', 'Comptoir');
  await f.dire('« Comptoir » : ses personnes, avec leurs heures. ‹ et › passent de l’une à l’autre.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fo-pers [aria-label="Personne précédente"]', { apres: 1000 });
  await f.cliquer('.eqp-fo-pers [aria-label="Personne suivante"]', { apres: 1000 });
  await E.tirerBord(f, 'a', '13:15');
  await f.montrer('[data-eqp-ok-suivant]', 'Enregistrer et suivant');
  await f.dire('Fanny finit à 13 h 15. « <b>Enregistrer et suivant</b> » enregistre, puis ouvre Inès.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ok-suivant]', { apres: 1900 });
  await f.montrer('.eqp-fo-tete', 'Inès');
  await f.dire('Inès est au repos jeudi : on lui tire un service, ou on passe à la suivante.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fo-pied [data-eqp-annuler]', { apres: 800 });

  /* Choisir un créneau de la matrice */
  await f.fonction({ id: 'equipe-creneau', titre: 'Choisir un créneau de la matrice',
    resume: 'Avec l’option Matrice A B C D : les cartes « Matin » et « Après-midi » en haut de la fenêtre, chacune avec son effet ; un clic, puis enregistrer.',
    points: ['Option « Matrice A B C D » allumée', 'Ouvrir le service ou une case vide', 'Cliquer « Matin » ou « Après-midi »', '« Enregistrer » ou « Ajouter le service »'] });
  await E.allumer(f, 'matrice', { dire: 'Les créneaux viennent avec l’option « <b>Matrice A B C D</b> et shift maker ».' });
  const lun = '[data-eqp-cell="2026-11-16"][data-emp="201"]';
  await E.sousLaTete(f, lun);
  await f.montrer(lun, 'Lundi · Camille');
  await f.dire('Lundi matin, le comptoir est vide. Camille est au repos : un clic sur sa case.', 3700);
  await f.cacher(150); await f.taire(150);
  await E.sousLaTete(f, lun);
  await f.cliquer(lun, { apres: 1200 });
  await f.montrer('.eqp-fo-cartes', 'Matin · Après-midi');
  await f.dire('En haut, les <b>créneaux</b> de la matrice, chacun avec son effet : le comptoir, le budget, le coût.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-cren="M"]', { apres: 1000 });
  await f.montrer('.eqp-fo-carte.on', 'Matin');
  await f.dire('Le <b>matin</b> comble le comptoir. On l’ajoute.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-ok]', { apres: 1700 });
  await f.montrerTout('.eqp-tab tr.eqp-lin', 'Les linéaires');
  await f.dire('Lundi, le comptoir est tenu le matin.', 3000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
