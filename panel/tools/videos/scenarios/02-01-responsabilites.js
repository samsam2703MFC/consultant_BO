/* Mon équipe › l'onglet « Responsabilités » (?vue=equipe), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    equipe-responsabilites  les chiffres, le tableau employés × secteurs, chercher, filtrer, la fiche
 *   fonction  equipe-affectations     cocher un poste, lire l'effet, enregistrer au panel
 *   fonction  equipe-lettre           l'option Matrice allumée : la lettre de roulement dans la fiche
 * Données fictives : mocks/20_equipe.js ; avant de filmer, Lucie Bernard perd sa lettre (on la lui donne à l'écran).
 * Chaque phrase vient de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-01-responsabilites', {
    vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => { await E.envoyer(p, '/dashboard/equipe/roulement', { employe: 210, lettre: null }); },
  });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-responsabilites', titre: 'Les responsabilités',
    resume: 'Qui tient quel poste et quels produits en dépendent : les chiffres, le tableau employés × secteurs et la fiche de la personne choisie.',
    accroche: 'Qui s’occupe de quoi',
    programme: ['Modifier les affectations', 'Donner sa lettre de roulement'],
  });
  await f.montrer('.eqp-tabs', 'Responsabilités');
  await f.dire('Mon équipe s’ouvre sur « <b>Responsabilités</b> » : qui s’occupe de quoi, poste par poste.', 4000);
  await f.montrer('.eqp-kpis', 'Les chiffres');
  await f.dire('En tête : les employés actifs, les postes occupés, et les produits <b>sans responsable</b>.', 4200);
  await f.montrer('.eqp-grille .eqp-table', 'Employés × secteurs');
  await f.dire('Une ligne par personne, une colonne par secteur : ses postes, et les produits qui en dépendent.', 4400);
  await f.cacher(200);
  await f.cliquer('[data-eqp-q]', { apres: 250 });
  await f.taper('Croissant', 110);
  await f.pause(500);
  await f.montrer('.eqp-grille .eqp-table', 'Rechercher');
  await f.dire('La recherche trouve un employé, un poste ou un produit : ici, qui tient les <b>croissants</b>.', 4400);
  await f.cacher(200); await f.taire(200);
  await f.p.locator('[data-eqp-q]').fill(''); await f.pause(500);
  await f.survoler('[data-eqp-secteur]', { pause: 300 });
  await f.p.locator('[data-eqp-secteur]').selectOption('2'); await f.pause(700);
  await f.montrer('.eqp-grille .eqp-table', 'Un secteur');
  await f.dire('« Tous les secteurs » : un secteur choisi, il ne reste que <b>sa colonne</b>.', 3800);
  await f.cacher(200); await f.taire(200);
  await f.p.locator('[data-eqp-secteur]').selectOption(''); await f.pause(500);
  await f.cliquer('[data-eqp-sel="209"]', { apres: 800 });
  await f.montrer('.eqp-fiche', 'Sa fiche');
  await f.dire('Un clic sur une ligne ouvre sa <b>fiche</b> : poste, secteur, nombre de produits.', 4000);
  await f.cacher(150);
  await f.cliquer('[data-eqp-tous]', { apres: 700 });
  await f.dire('« Voir les 23 produits » les déplie tous.', 2800);
  await f.cliquer('[data-eqp-tous]', { apres: 500 });
  await f.montrer('.eqp-fiche .eqp-sem', 'Sa semaine');
  await f.dire('Dessous, ses heures de la semaine. Un clic ouvre le <b>planning</b> de la semaine.', 3800);
  await f.cacher(150);
  await f.cliquer('.eqp-fiche .eqp-sem', { apres: 900 });
  await f.defiler(-3000);
  await f.dire('La présence, elle, est dans l’onglet Planning.', 2800);
  await f.taire(150);
  await f.cliquer('[data-eqp-onglet=resp]', { apres: 900 });

  /* Modifier les affectations */
  await f.fonction({ id: 'equipe-affectations', titre: 'Modifier les affectations',
    resume: 'Cocher ou décocher les postes d’une personne, lire l’effet sur ses secteurs et ses produits, puis enregistrer au panel.',
    points: ['Choisir la personne', '« Modifier ses postes »', 'Cocher ou décocher un poste', '« Enregistrer »'] });
  await f.cliquer('[data-eqp-sel="201"]', { apres: 700 });
  await f.cliquer('.eqp-fiche [data-eqp-postes]', { apres: 900 });
  await f.montrer('.eqp-mod', 'Ses postes');
  await f.dire('Les postes du panel : de production, avec leur secteur, puis les postes généraux.', 4000);
  await f.cacher(150);
  await f.cliquer('[data-eqp-coche="4"]', { apres: 900 });
  await f.montrer('.eqp-apres', 'L’effet');
  await f.dire('Avant d’enregistrer : ses secteurs et ses produits. Ici, le <b>snacking</b>, neuf produits de plus.', 4600);
  await f.cacher(150);
  await f.cliquer('[data-eqp-enregistrer-postes]', { apres: 1400 });
  await f.montrer('[data-eqp-sel="201"]', 'Enregistré au panel');
  await f.dire('Enregistré au panel : Camille tient aussi le snacking, ses produits suivent.', 3800);
  await f.cacher(300);

  /* Donner sa lettre de roulement */
  await f.fonction({ id: 'equipe-lettre', titre: 'Donner sa lettre de roulement',
    resume: 'Avec l’option Matrice A B C D : la lettre de chacun dans sa fiche, et ce qu’elle donne cette semaine et la suivante.',
    points: ['Option « Matrice A B C D » allumée', 'Choisir la personne', 'A, B, C, D ou « aucun »'] });
  await E.allumer(f, 'matrice', { dire: 'La lettre vient avec l’option « <b>Matrice A B C D</b> et shift maker ».' });
  await f.cliquer('[data-eqp-sel="210"]', { apres: 900 });
  await f.montrer('.eqp-fiche .eqp-seg-l', 'Roulement');
  await f.dire('Dans sa fiche : « Roulement · matrice A B C D ». Sans lettre, le shift maker ne la planifie pas.', 4800);
  await f.cacher(150);
  await f.cliquer('[data-eqp-lettre="A"][data-emp="210"]', { apres: 1300 });
  await f.montrerTout('.eqp-fiche .eqp-seg-l, .eqp-fiche .eqp-roul', 'Roulement A');
  await f.dire('La fiche dit ce que sa lettre donne <b>cette semaine</b> et la suivante : matin ou après-midi, et ses repos.', 5000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
