/* Semaine › « La fenêtre Invendus et poubelle », la vidéo de l'onglet, et ses fonctions en chapitres (26/11/2026) :
 *   onglet    semaine-invendus            « Le résultat » : « les saisies ▸ » et « Frais ▸ » (la Trésorerie › Frais) ; la
 *                                         puce « Invendus » : les chiffres de tête, le détail par produit ; la fenêtre :
 *                                         chaque pièce jetée de la semaine, jour par jour, le total par opérateur
 *   fonction  semaine-invendus-reclamer   une saisie Casse : « Réclamer au fournisseur », la référence, la livraison, la
 *                                         quantité, le problème, une photo, « Envoyer la réclamation »
 *   fonction  semaine-invendus-remarque   une saisie Qualité : « Remarque à l'opérateur », la personne (celle qui a
 *                                         produit), le mot, « Enregistrer la remarque »
 * Le Mois a la même fenêtre (07-06) : ces deux fonctions n'y sont pas retournées. Données fictives :
 * mocks/15_chiffres_periode.js (les saisies de la semaine), 11_barre_fiche.js (/exploitation/invendus/actions, POST
 * /fournisseurs/reclamation, POST /equipe/remarques), 09_tresorerie.js (les frais). La photo : une illustration de la
 * fausse API (lib/taches.js, « croissants »). Chaque phrase vient de panel/public/dashboard/dashboard.js (cascade,
 * invCarte, invModaleRendre, invActForm, invActEnvoyer) et de consultant/src/invendus.php. */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');
const B = require('./barre');

const RES = '#dash .qb-res';
const INV = '#dash [data-qbsec=invendus]';
const M = '#db-invm';
const CASSE = M + ' tr:has(.db-inv-tag.casse):has-text("Croissant au beurre")';
const QUAL = M + ' tr:has(.db-inv-tag.qual):has-text("Éclair au chocolat")';

/** Ouvre la fenêtre depuis « les saisies ▸ » du résultat, et met la ligne voulue sous les yeux. */
async function fenetre(f, ligne) {
  await enHautDe(f, RES, 20);
  await f.cliquer(RES + ' [data-invmodale]', { dx: 0.9, apres: 1500 });
  await f.p.locator(ligne).first().evaluate(e => { const m = e.closest('.fi-modale'); m.scrollTop += e.getBoundingClientRect().top - m.getBoundingClientRect().top - 140; });
  await f.pause(500);
}

module.exports = async function () {
  let PHOTO = null;
  const f = await ouvrir('06-05-invendus', 'semaine', {
    puces: ['heures'], sources: ['consultant/src/invendus.php', 'panel/src/reclamations_magasin.php'],
    avant: async p => { PHOTO = await B.photo(p, 'croissants'); },
  });

  await f.onglet({
    id: 'semaine-invendus', titre: 'Invendus et poubelle',
    resume: 'Les pièces jetées de la semaine : leur coût retranché du résultat, le détail par produit, puis la fenêtre des saisies — chaque pièce jetée, jour par jour, avec l’heure, l’opérateur et le motif.',
    accroche: 'Ce qui part à la poubelle',
    programme: ['Réclamer au fournisseur', 'Une remarque à l’opérateur'],
  });

  await enHautDe(f, RES, 20);
  await dire(f, RES + ' [data-invmodale]', 'Invendus et poubelle', 'Dans « <b>Le résultat</b> », la ligne des invendus : les pièces jetées, leur coût de production, retranché du résultat.', 6000);
  await dire(f, RES + ' .db-cl-lien', 'Frais ▸', 'Plus bas, « <b>Frais ▸</b> » ouvre les frais du mois dans la Trésorerie.', 4000);
  await rien(f);
  await f.cliquer(RES + ' .db-cl-lien', { apres: 2600 });
  await f.dire('La Trésorerie, sur ses <b>frais</b> du mois en cours.', 3400);
  await f.taire(150);
  await f.cliquer('.sidebar__item[data-vue="semaine"]', { apres: 2200 });

  /* La puce Invendus */
  await enHautDe(f, '#dash .qb-plus', 380);
  await f.cliquer('#dash [data-qbplus=invendus]', { apres: 1500 });
  await enHautDe(f, INV, 20);
  await dire(f, INV + ' .db-inv-kpi', 'La semaine', 'La puce « <b>Invendus</b> » : les pièces jetées, leur coût de production, la valeur de vente perdue, les motifs.', 6000);
  await rien(f);
  await f.cliquer(INV + ' [data-invdrop]', { dx: 0.85, apres: 1300 });
  await dire(f, INV + ' .db-pro-corps', 'Par produit', '« <b>voir le détail ▾</b> » : les produits jetés, du plus coûteux au moins coûteux.', 4800);
  await rien(f);
  await enHautDe(f, INV, 20);
  await dire(f, INV + ' [data-invmodale]', 'Les saisies', '« Les saisies une par une » ouvre la même fenêtre que « les saisies ▸ » du résultat.', 4600);
  await rien(f);
  await f.cliquer(INV + ' [data-invmodale]', { apres: 1600 });

  /* La fenêtre */
  await B.cadre(f, M + ' .fi-chips', 'En tête');
  await f.dire('En tête : les saisies et leurs pièces, ce que le panel totalise, le coût de production, les opérateurs.', 5600);
  await B.cadre(f, M + ' .im-tab', 'Jour par jour');
  await f.dire('Chaque pièce jetée, telle qu’<b>encodée en caisse</b>, jour par jour : l’heure, l’opérateur, le produit, la quantité, le motif.', 6400);
  await f.cacher(150); await f.taire(150);
  await f.p.locator(QUAL).first().evaluate(e => { const m = e.closest('.fi-modale'); m.scrollTop += e.getBoundingClientRect().top - m.getBoundingClientRect().top - 260; });
  await f.pause(700);
  await B.cadreTout(f, M + ' .im-rowact', 'Qualité · Casse');
  await f.dire('Une saisie « <b>Qualité</b> » ou « <b>Casse</b> » se traite depuis sa ligne : réclamer au fournisseur, ou une remarque à l’opérateur.', 6600);
  await f.cacher(150); await f.taire(150);
  await f.p.locator(M + ' .im-ops').evaluate(e => { const m = e.closest('.fi-modale'); m.scrollTop += e.getBoundingClientRect().top - m.getBoundingClientRect().top - 300; });
  await f.pause(700);
  // La ligne « Par opérateur » est tout en bas de la fenêtre : le sous-titre la couvrirait, l'étiquette suffit.
  await B.cadre(f, M + ' .im-ops', 'Le total par opérateur', 3000);
  await f.cacher(150);
  await f.cliquer(M + ' .fi-x', { apres: 1000 });
  await f.dire('✕ ou Échap ferme la fenêtre.', 2600);
  await rien(f);

  /* Réclamer au fournisseur */
  await f.fonction({ id: 'semaine-invendus-reclamer', titre: 'Réclamer au fournisseur depuis un invendu',
    resume: 'Sur une saisie Qualité ou Casse de la semaine : « Réclamer au fournisseur » ouvre la réclamation déjà remplie ; on vérifie la référence, la livraison, la quantité et le problème, on ajoute une photo, on envoie.',
    points: ['« Réclamer au fournisseur »', 'Vérifier la référence et la livraison', 'Le problème, une photo', '« Envoyer la réclamation »'] });
  await fenetre(f, CASSE);
  await B.cadre(f, CASSE, 'Casse');
  await f.dire('Mercredi, 7 h 40 : quatre croissants jetés pour <b>casse</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(CASSE + ' [data-imact="recl"]', { apres: 1400 });
  await B.cadre(f, M + ' .im-actf', 'Déjà remplie');
  await f.dire('Déjà remplie : le croissant, acheté fini, chez son fournisseur ; la dernière livraison reçue ; quatre pièces.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(M + ' [data-iamotif="broken_packaging"]', { apres: 600 });
  await B.cadre(f, M + ' .im-puces', 'Le problème');
  await f.dire('Le <b>problème</b> : ici, l’emballage abîmé. Un mot pour le fournisseur est déjà écrit.', 4600);
  await f.cacher(150); await f.taire(150);
  f.p.once('filechooser', fc => fc.setFiles(PHOTO).catch(() => null));
  await f.cliquer(M + ' .im-photos .plus', { apres: 1500 });
  await B.cadre(f, M + ' .im-photos', 'La photo');
  await f.dire('La <b>photo</b> est obligatoire : le produit entier et l’étiquette du carton.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(M + ' [data-iaenvoyer]', { apres: 1600 });
  await B.cadre(f, M + ' .im-fait', 'Envoyée');
  await f.dire('<b>Envoyée</b> au panel, avec son numéro ; la ligne le garde.', 3800);
  await f.cacher(300); await f.taire(150);
  await f.cliquer(M + ' .fi-x', { apres: 900 });

  /* Une remarque à l'opérateur */
  await f.fonction({ id: 'semaine-invendus-remarque', titre: 'Faire une remarque à l’opérateur',
    resume: 'Sur une saisie Qualité ou Casse de la semaine : « Remarque à l’opérateur », la personne qui a produit la référence (choisie d’office), le mot, « Enregistrer la remarque ».',
    points: ['« Remarque à l’opérateur »', 'Choisir la personne', 'Écrire la remarque', '« Enregistrer la remarque »'] });
  await fenetre(f, QUAL);
  await B.cadre(f, QUAL, 'Qualité');
  await f.dire('Mardi, 13 h 05 : trois éclairs jetés pour un problème de <b>qualité</b>.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(QUAL + ' [data-imact="rem"]', { apres: 1400 });
  await B.cadre(f, M + ' .im-actf label >> nth=0', 'L’opérateur');
  await f.dire('Celui qui a <b>produit</b> la référence ce jour est déjà choisi ; sinon, la liste, ou un nom.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(M + ' [data-ia="texte"]', { apres: 200 });
  await f.p.keyboard.press('Control+End');
  await f.taper('Glaçage terne et craquelé : revoir la température du fondant.', 40);
  await B.cadre(f, M + ' [data-ia="texte"]', 'La remarque');
  await f.dire('La remarque reprend le produit, le jour, l’heure et le motif ; on la complète.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(M + ' [data-iaenvoyer]', { apres: 1500 });
  await B.cadre(f, M + ' .im-fait', 'Enregistrée');
  await f.dire('<b>Enregistrée</b> dans les évaluations de l’opérateur, avec la date, le produit et la quantité.', 5200);
  await f.cacher(300); await f.taire(150);
  await f.pause(1200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
