/* La barre du haut › les fenêtres communes › « Invendus et poubelle » (?vue=ops), 26/11/2026 :
 *   onglet    barre-invendus            « les saisies ▸ » du P&L court : chaque pièce jetée telle qu'encodée en caisse
 *                                       (heure, opérateur, produit, quantité, motif), le total par opérateur
 *   fonction  barre-invendus-reclamer   une saisie Casse : « Réclamer au fournisseur », vérifier la référence, la
 *                                       livraison, la quantité et le problème, une photo, « Envoyer la réclamation »
 *   fonction  barre-invendus-remarque   une saisie Qualité : « Remarque à l'opérateur », la personne (celle qui a
 *                                       produit, choisie d'office), le mot, « Enregistrer la remarque »
 * Données fictives : mocks/10_ops.js (lib/fiche.js, invendusDetail), 11_barre_fiche.js (/exploitation/invendus/actions,
 * POST /fournisseurs/reclamation, POST /equipe/remarques). La photo : une illustration de la fausse API (lib/taches.js,
 * « croissants »), choisie par le sélecteur de fichier. Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (invModaleRendre, invActForm, invActEnvoyer) et de consultant/src/invendus.php. */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  let PHOTO = null;
  const f = await Film.ouvrir('01-29-invendus', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/invendus.php', 'panel/src/reclamations_magasin.php'],
    avant: async p => { PHOTO = await B.photo(p, 'croissants'); },
  });

  await f.onglet({
    id: 'barre-invendus', titre: 'Invendus et poubelle',
    resume: '« les saisies ▸ » du P&L court : chaque pièce jetée telle qu’encodée en caisse — l’heure, l’opérateur, le produit, la quantité, le motif — et le total par opérateur.',
    accroche: 'Chaque pièce jetée, saisie par saisie',
    programme: ['Les saisies de la caisse', 'Le total par opérateur', 'Réclamer au fournisseur', 'Une remarque à l’opérateur'],
  });
  await f.montrer('#dash [data-invmodale]', 'les saisies ▸');
  await f.dire('Dans le <b>P&L court</b> d’Opérationnel, la ligne « Invendus et poubelle » : « les saisies ▸ ».', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash [data-invmodale]', { apres: 1500 });
  await B.cadre(f, '#db-invm .fi-chips', 'En tête');
  await f.dire('En tête : les saisies et leurs pièces, ce que le panel totalise, le coût de production, les opérateurs.', 5600);
  await B.cadre(f, '#db-invm .im-tab', 'Chaque saisie');
  await f.dire('Chaque pièce jetée, telle qu’<b>encodée en caisse</b> : l’heure, l’opérateur, le produit, la quantité, le motif.', 6000);
  await B.cadreTout(f, '#db-invm .im-tab .db-inv-tag', 'Le motif');
  await f.dire('Le <b>motif</b> de chacune : qualité, casse, report de la veille, fin de journée, dégustation.', 5000);
  await f.dire('Si le journal de la caisse et le panel ne comptent pas les mêmes pièces, une note le dit sous le tableau.', 5200);
  await B.cadre(f, '#db-invm .im-ops', 'Par opérateur');
  await f.dire('Dessous, le total <b>par opérateur</b>.', 2800);
  await f.cacher(150); await f.taire(150);
  await B.cadreTout(f, '#db-invm .im-rowact', 'Qualité · Casse');
  await f.dire('Une saisie « <b>Qualité</b> » ou « <b>Casse</b> » se traite depuis sa ligne : réclamer au fournisseur, ou une remarque à l’opérateur.', 6800);
  await f.cacher(300); await f.taire(150);

  /* Réclamer au fournisseur */
  await f.fonction({ id: 'barre-invendus-reclamer', titre: 'Réclamer au fournisseur depuis un invendu',
    resume: 'Sur une saisie Qualité ou Casse : « Réclamer au fournisseur » ouvre la réclamation déjà remplie ; on vérifie la référence, la livraison, la quantité et le problème, on ajoute une photo, on envoie.',
    points: ['« Réclamer au fournisseur »', 'Vérifier la référence et la livraison', 'Le problème, une photo', '« Envoyer la réclamation »'] });
  await f.cliquer('#db-invm [data-imact="recl"][data-imk="s2"]', { apres: 1300 });
  await B.cadre(f, '#db-invm .im-actf', 'Déjà remplie');
  await f.dire('Déjà remplie : le croissant, acheté fini, chez son fournisseur ; la dernière livraison reçue ; quatre pièces.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-invm [data-iamotif="broken_packaging"]', { apres: 600 });
  await B.cadre(f, '#db-invm .im-puces', 'Le problème');
  await f.dire('Le <b>problème</b> : ici, l’emballage abîmé ; un mot pour le fournisseur est déjà écrit.', 4600);
  await f.cacher(150); await f.taire(150);
  f.p.once('filechooser', fc => fc.setFiles(PHOTO).catch(() => null));
  await f.cliquer('#db-invm .im-photos .plus', { apres: 1500 });
  await B.cadre(f, '#db-invm .im-photos', 'La photo');
  await f.dire('La <b>photo</b> est obligatoire : le produit entier et l’étiquette du carton.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-invm [data-iaenvoyer]', { apres: 1600 });
  await B.cadre(f, '#db-invm .im-fait', 'Envoyée');
  await f.dire('<b>Envoyée</b> au panel, avec son numéro ; la ligne le garde.', 3800);
  await f.cacher(300); await f.taire(150);

  /* Une remarque à l'opérateur */
  await f.fonction({ id: 'barre-invendus-remarque', titre: 'Faire une remarque à l’opérateur',
    resume: 'Sur une saisie Qualité ou Casse : « Remarque à l’opérateur », la personne qui a produit la référence (choisie d’office), le mot, « Enregistrer la remarque ».',
    points: ['« Remarque à l’opérateur »', 'Choisir la personne', 'Écrire la remarque', '« Enregistrer la remarque »'] });
  await f.cliquer('#db-invm [data-imact="rem"][data-imk="s1"]', { apres: 1300 });
  await B.cadre(f, '#db-invm .im-actf label >> nth=0', 'L’opérateur');
  await f.dire('Celui qui a <b>produit</b> la référence ce jour est déjà choisi ; sinon, la liste, ou un nom.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-invm [data-ia="texte"]', { apres: 200 });
  await f.p.keyboard.press('Control+End');
  await f.taper('Glaçage terne et craquelé : revoir la température du fondant.', 40);
  await B.cadre(f, '#db-invm [data-ia="texte"]', 'La remarque');
  await f.dire('La remarque reprend le produit, l’heure et le motif ; on la complète.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-invm [data-iaenvoyer]', { apres: 1500 });
  await B.cadre(f, '#db-invm .im-fait', 'Enregistrée');
  await f.dire('<b>Enregistrée</b> dans les évaluations de l’opérateur, avec la date, le produit et la quantité.', 5200);
  await f.cacher(300); await f.taire(150);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
