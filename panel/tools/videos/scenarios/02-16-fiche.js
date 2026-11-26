/* Mon équipe › « La fiche d'une personne » (?vue=equipe, 24/11/2026), et ses fonctions en chapitres :
 *   onglet    equipe-fiche            « Fiche complète » (Responsabilités) ou un clic sur un nom (semaine, mois) : l'en-tête,
 *                                     les six onglets, Identité et contrat, Heures et coût, Compétences et postes, Primes
 *   fonction  equipe-fiche-identite   Inès passe de « Stage » à « Contrat de travail », son nom affiché devient « Inès »
 *   fonction  equipe-fiche-taux       Camille passe de 15,80 à 16,40 €/h : l'effet sur le mois, puis Heures et coût
 * Les onglets Évaluations et Ventes ont leur vidéo (02-17-evaluations, 02-18-ventes). « Modifier ses postes » (onglet
 * Compétences et postes) ouvre la fenêtre des affectations : le chapitre equipe-affectations de 02-01-responsabilites.
 * Données fictives : mocks/20_equipe.js. Chaque phrase vient de panel/public/dashboard/equipe.js (rendFichePersonne et
 * voisins) ou de l'en-tête de panel/src/equipe_fiche.php.
 * Contournement : l'encadré de l'effet du taux (.eqp-fp-effet) est affiché vide quand le taux n'a pas changé — sa
 * règle display:flex (equipe.css) passe devant l'attribut hidden ; `avant` rétablit display:none pour [hidden]. */
const { Film } = require('../film');

const SOURCES = ['panel/public/dashboard/equipe.js', 'panel/public/dashboard/equipe.css', 'panel/src/equipe_fiche.php'];
const RETABLIR = p => p.addStyleTag({ content: '.eqp-fp-effet[hidden]{display:none}' });
/** Remplace le contenu d'un champ de la fiche (tout sélectionner, puis taper). */
async function remplir(f, champ, texte) {
  await f.cliquer(`[data-eqp-fp-champ=${champ}]`, { apres: 250 });
  await f.p.keyboard.press('Control+A'); await f.pause(200);
  await f.taper(texte, 110);
}

module.exports = async function () {
  const f = await Film.ouvrir('02-16-fiche', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: SOURCES, avant: RETABLIR });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-fiche', titre: 'La fiche d’une personne',
    resume: '« Fiche complète » ou un clic sur un nom au planning : identité et contrat, heures et coût du mois, compétences et postes, primes.',
    accroche: 'Tout sur une personne',
    programme: ['Modifier l’identité et le contrat', 'Changer le taux horaire'],
  });
  await f.cliquer('[data-eqp-sel="201"]', { apres: 800 });
  await f.montrer('.eqp-fiche [data-eqp-fiche]', 'Fiche complète');
  await f.dire('Dans Responsabilités, la personne choisie : « <b>Fiche complète</b> » ouvre sa fiche.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fiche [data-eqp-fiche]', { apres: 1600 });
  await f.montrer('.eqp-fp-tete', 'Camille Roussel');
  await f.dire('En tête : sa fonction, son contrat, et si elle est active.', 3200);
  await f.montrer('.eqp-fp-tabs', 'Six onglets');
  await f.dire('Six onglets : identité et contrat, heures et coût, évaluations, ventes, compétences et postes, primes.', 5200);
  await f.montrer('.eqp-fp-cols > div:first-child', 'Identité');
  await f.dire('Prénom, nom, langue, et le <b>nom affiché</b> : sur la caisse, l’app employés et le planning imprimé.', 5000);
  await f.montrer('.eqp-fp-cols > div:nth-child(2)', 'Contrat et coût');
  await f.dire('Le contrat, le rôle et le <b>taux horaire</b> se modifient ici ; le téléphone et le PIN, au panel.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-onglet=cout]', { apres: 1300 });
  await f.montrer('.eqp-fp-kpis', 'Heures et coût');
  await f.dire('« Heures et coût » : son taux, ses heures de novembre au planning, leur coût, ses ETP.', 4600);
  await f.montrer('.eqp-fp-sem', 'Semaine par semaine');
  await f.dire('Semaine par semaine : ses services, ses heures et leur coût — heures du planning × taux horaire.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-onglet=competences]', { apres: 1100 });
  await f.montrer('.eqp-fp-cols', 'Compétences et postes');
  await f.dire('Son poste, son niveau et ses compétences, réglés au panel ; ses <b>postes de travail</b>, à modifier.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-onglet=primes]', { apres: 1000 });
  await f.montrer('.eqp-fp-primes', 'Primes');
  await f.dire('« Primes » mène à l’onglet Primes de l’équipe : chaque prime, sa place, ce qu’elle a gagné.', 4600);
  await f.dire('Ses évaluations et ses ventes ont chacune leur vidéo.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fp-tete [data-eqp-fp-fermer]', { apres: 900 });
  await f.cliquer('[data-eqp-onglet=planning]', { apres: 1500 });
  await f.montrer('.eqp-fp-lien[data-eqp-fiche="208"]', 'Un nom');
  await f.dire('Au planning, à la semaine comme au mois, un clic sur un <b>nom</b> ouvre aussi sa fiche.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fp-lien[data-eqp-fiche="208"]', { apres: 1500 });
  await f.montrer('.eqp-fp-tete', 'Inès Fontaine', 1800);
  await f.cacher(150);
  await f.cliquer('.eqp-fp-tete [data-eqp-fp-fermer]', { apres: 900 });

  /* Modifier l'identité et le contrat */
  await f.fonction({ id: 'equipe-fiche-identite', titre: 'Modifier l’identité et le contrat',
    resume: 'Dans « Identité et contrat » : le nom affiché, la langue, le type de contrat ou le rôle ; rien n’est envoyé avant « Enregistrer », puis c’est écrit dans TF Buddy.',
    points: ['Ouvrir sa fiche', 'Changer un champ', '« Enregistrer »'] });
  await f.cliquer('.eqp-fp-lien[data-eqp-fiche="208"]', { apres: 1500 });
  await f.montrer('.eqp-fp-tete', 'Stage');
  await f.dire('Inès a fini son stage : elle passe à un <b>contrat de travail</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-eqp-fp-champ=contrat]', { pause: 300 });
  await f.p.locator('[data-eqp-fp-champ=contrat]').selectOption('employment_contract'); await f.pause(600);
  await f.montrer('[data-eqp-fp-champ=contrat]', 'Type de contrat', 1500);
  await f.cacher(150);
  await remplir(f, 'affiche', 'Inès');
  await f.montrer('[data-eqp-fp-champ=affiche]', 'Nom affiché');
  await f.dire('Son nom affiché devient « Inès », sur la caisse comme au planning imprimé.', 3800);
  await f.montrer('.eqp-fp-pied', 'Enregistrer');
  await f.dire('Rien n’est envoyé avant « <b>Enregistrer</b> » ; « Annuler » remet la fiche comme au panel.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-enr]', { apres: 1500 });
  await f.montrerTout('.eqp-fp-tete, .eqp-fp-corps .eqp-flash', 'Enregistré');
  await f.dire('Écrit dans TF Buddy : l’en-tête dit « <b>Contrat de travail</b> ».', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-fp-tete [data-eqp-fp-fermer]', { apres: 900 });

  /* Changer le taux horaire */
  await f.fonction({ id: 'equipe-fiche-taux', titre: 'Changer le taux horaire',
    resume: 'Le taux horaire dans « Identité et contrat » : avant d’enregistrer, ce que le mois au planning coûte avec ce taux et la part du personnel dans le CA ; ensuite, les coûts suivent.',
    points: ['Ouvrir sa fiche', '« Taux horaire (€/h) »', 'Lire l’effet sur le mois', '« Enregistrer »'] });
  await f.cliquer('.eqp-fp-lien[data-eqp-fiche="201"]', { apres: 1500 });
  await f.montrer('.eqp-fp-ch:has([data-eqp-fp-champ=taux])', 'Taux horaire');
  await f.dire('Camille est à <b>15,80 €/h</b> au panel ; la moyenne de l’équipe est dessous.', 4000);
  await f.cacher(150); await f.taire(150);
  await remplir(f, 'taux', '16,40');
  await f.pause(400);
  await f.montrer('.eqp-fp-effet', 'L’effet sur le mois');
  await f.dire('Avant d’enregistrer : ce que <b>novembre</b> au planning coûte avec 16,40 €/h, et l’écart…', 4600);
  await f.dire('… et la part du personnel dans le CA du mois, face à la cible de 17 %.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-enr]', { apres: 1400 });
  await f.montrer('.eqp-fp-corps .eqp-flash', 'Enregistré', 1500);
  await f.cacher(150);
  await f.cliquer('[data-eqp-fp-onglet=cout]', { apres: 1500 });
  await f.montrer('.eqp-fp-kpis', 'Heures et coût');
  await f.dire('Enregistré : « Heures et coût » compte avec le <b>nouveau taux</b>, et le planning se relit avec lui.', 5000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
