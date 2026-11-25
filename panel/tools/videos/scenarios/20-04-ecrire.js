/* Boîte mail › Écrire (Nouvel e-mail · Répondre · Transférer : le volet de droite devient le formulaire), et ses
 * fonctions en chapitres (24/11/2026) :
 *   onglet    boite-ecrire              À, + Cc, Objet, « Insérer un modèle… », « Joindre une offre… », Pièce jointe, Envoyer
 *   fonction  boite-repondre            « Répondre », un modèle inséré au-dessus de la citation, « Envoyer » (fusion :
 *                                       « Répondre à un e-mail » et « Répondre avec un modèle »)
 *   fonction  boite-nouvel-email        « Écrire », À, + Cc, Objet, le texte, « Envoyer »
 *   fonction  boite-transferer          « Transférer » : l'e-mail d'origine part en pièce jointe
 *   fonction  boite-joindre-fichier     « Importer » un fichier, « Coffre-fort » : chercher, cocher (5 au plus)
 *   fonction  boite-joindre-catalogue   « Catalogues » : un catalogue prêt part en lien vers sa version en ligne
 *   fonction  boite-joindre-offre       « Joindre une offre (PDF de TF Buddy)… » et le modèle « Envoi de l'offre »
 * Données fictives : mocks/70_boite.js (les envois rejoignent les Envoyés), 13_offres.js (l'offre n° 419 de Bureau
 * Horizon SRL, créée avant de filmer), 23_coffre.js, 19_catalogue.js ; le fichier importé est un PDF de démonstration.
 * Chaque phrase vient de panel/public/dashboard/boite.js (vueEcrire, pjCoffre, pjCatalogues, envoyer, remplir). */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { Film } = require('../film');
const B = require('./boite');

/* Le fichier « importé » : un petit PDF de démonstration, écrit pour le film. */
const FICHIER = path.join(os.tmpdir(), 'film-boite', 'releve-de-caisse-octobre.pdf');

module.exports = async function () {
  fs.mkdirSync(path.dirname(FICHIER), { recursive: true });
  fs.writeFileSync(FICHIER, '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
  const f = await Film.ouvrir('20-04-ecrire', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php', 'panel/public/dashboard/coffre.js'],
    avant: B.ouverte(p => B.poster(p, '/exploitation/offres-client', { action: 'creer', offre: { nom: 'Plateaux lunch pour le 2 décembre', clientId: 7201, jour: '2026-12-02', retrait: false,
      livraison: { rue: 'Parc d’activités 8', cp: '5004', ville: 'Bois-Joli', frais: 15 }, lignes: [{ nom: 'Sandwich poulet crudités', qte: 18 }, { nom: 'Cookie chocolat', qte: 18 }] } })) });
  const choisir = async (sel, valeur, ms = 700) => {
    const r = await f.survoler(sel);
    await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [r.x + r.w / 2, r.y + r.h / 2]);
    await f.p.selectOption(sel, valeur); await f.pause(ms);
  };
  const envoyer = async () => { await f.cliquer('.bx-ecrire [data-bx="envoyer"]', { apres: 1400 }); await B.enHaut(f); };

  /* L'onglet */
  await f.onglet({
    id: 'boite-ecrire', titre: 'Écrire, répondre, transférer',
    resume: 'Le volet de droite devient le formulaire : À, + Cc, Objet, un modèle, une offre en PDF, des pièces jointes, puis Envoyer.',
    accroche: 'Depuis l’adresse du magasin',
    programme: ['Répondre à un e-mail', 'Écrire un nouvel e-mail', 'Transférer un e-mail', 'Joindre un fichier ou du coffre-fort', 'Joindre un catalogue', 'Joindre une offre en PDF'],
  });
  await f.cliquer('[data-bxu="1038"]', { apres: 1000 });
  await f.montrer('.bx-gestes', 'Trois entrées');
  await f.dire('<b>Répondre</b>, <b>Répondre à tous</b> quand l’e-mail a plusieurs destinataires, <b>Transférer</b>…', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-barre [data-bx="ecrire"]', { apres: 1000 });
  await B.cadre(f, '.bx-ecrire', 'Nouvel e-mail');
  await f.dire('… et <b>Écrire</b>, dans la barre : le volet de droite devient le formulaire.', 4000);
  await f.montrer('.bx-ecrire .bx-m-h', 'L’expéditeur');
  await f.dire('Tout part de l’adresse du magasin.', 2600);
  await f.montrer('#bx-a', 'À');
  await f.dire('<b>À</b> : une ou plusieurs adresses, séparées par des virgules ; « + Cc » ajoute une copie.', 4600);
  await f.montrer('.bx-outils', 'Modèle · offre');
  await f.dire('« <b>Insérer un modèle…</b> » et « <b>Joindre une offre</b> », le PDF de TF Buddy.', 4000);
  await f.montrer('.bx-pj', 'Pièce jointe');
  await f.dire('En pièce jointe : <b>Importer</b> un fichier, le <b>Coffre-fort</b>, les <b>Catalogues</b>.', 4000);
  await f.montrer('.bx-ecrire .bx-actions', 'Envoyer');
  await f.dire('Puis <b>Envoyer</b> ; « Annuler » referme le formulaire, rien ne part.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-ecrire [data-bx="annuler"]', { apres: 700 });

  /* Répondre (avec un modèle) */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-repondre', titre: 'Répondre à un e-mail',
    resume: '« Répondre » remplit À et Objet et cite l’e-mail d’origine ; un modèle s’insère au-dessus, rempli tout seul ; puis Envoyer.',
    points: ['Ouvrir l’e-mail, « Répondre »', 'Insérer un modèle', 'Relire, compléter', '« Envoyer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('[data-bxu="1046"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bx="repondre"]', { apres: 1000 });
  await B.cadre(f, '.bx-ecrire', 'Répondre');
  await f.dire('À et Objet sont remplis ; l’e-mail d’origine est cité dessous.', 3600);
  await f.cacher(150); await f.taire(150);
  await choisir('#bx-modele', 'b-accuse');
  await f.montrer('#bx-texte', 'Accusé de réception');
  await f.dire('Un <b>modèle</b> s’insère, rempli : le prénom, le magasin. La citation reste dessous.', 4400);
  await f.cacher(150); await f.taire(150);
  await envoyer();
  await f.montrer('.bx-ok', 'Envoyé');
  await f.dire('L’e-mail part de l’adresse du magasin, et il est marqué <b>répondu</b>.', 3600);
  await f.cacher(300); await f.taire(200);

  /* Écrire un nouvel e-mail */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-nouvel-email', titre: 'Écrire un nouvel e-mail',
    resume: '« Écrire » ouvre un e-mail vierge : À, + Cc, Objet, le texte, puis Envoyer, depuis l’adresse du magasin.',
    points: ['« Écrire »', 'À, + Cc, Objet', 'Le texte', '« Envoyer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('.bx-barre [data-bx="ecrire"]', { apres: 800 });
  await f.cliquer('#bx-a', { apres: 150 });
  await f.taper('commandes@moulins-demo.exemple.be', 35);
  await f.cliquer('.bx-ecrire .bx-cc', { apres: 500 });
  await f.cliquer('#bx-cc', { apres: 150 });
  await f.taper('camille.r@exemple.be', 40);
  await f.cliquer('#bx-objet', { apres: 150 });
  await f.taper('Commande de farine pour décembre', 35);
  await f.cliquer('#bx-texte', { apres: 150 });
  await f.taper('Bonjour,\n\nPour le 1er décembre : 10 sacs de farine T55.\n\nMerci,\nMagasin démo', 20);
  await envoyer();
  await f.montrer('.bx-ok', 'Envoyé');
  await f.dire('<b>Envoyer</b> : il part de l’adresse du magasin et rejoint les Envoyés.', 3600);
  await f.cacher(300); await f.taire(200);

  /* Transférer */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-transferer', titre: 'Transférer un e-mail',
    resume: '« Transférer » : on donne le destinataire, un mot si besoin, et l’e-mail d’origine part en pièce jointe, avec ses propres pièces jointes.',
    points: ['Ouvrir l’e-mail', '« Transférer »', 'Le destinataire', '« Envoyer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('[data-bxu="1047"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bx="transferer"]', { apres: 1000 });
  await f.montrer('.bx-ecrire .bx-note', 'En pièce jointe');
  await f.dire('Une facture pour la comptabilité : l’e-mail d’origine part <b>en pièce jointe</b>, avec la sienne.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#bx-a', { apres: 150 });
  await f.taper('compta@fiduciaire-demo.exemple.be', 35);
  await f.cliquer('#bx-texte', { dy: 0.08, apres: 150 });
  await f.p.keyboard.press('Control+Home');
  await f.taper('Bonjour,\nLa facture de la livraison du 17/11, pour la comptabilité.', 25);
  await f.montrer('#bx-texte', 'Un mot');
  await f.dire('Un mot au-dessus du texte transféré.', 2600);
  await f.cacher(150); await f.taire(150);
  await envoyer();
  await f.montrer('.bx-ok', 'Transféré');
  await f.dire('Envoyé depuis l’adresse du magasin.', 2800);
  await f.cacher(300); await f.taire(200);

  /* Joindre un fichier ou un document du coffre-fort */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-joindre-fichier', titre: 'Joindre un fichier ou un document du coffre-fort',
    resume: '« Importer » joint un fichier de l’ordinateur ; « Coffre-fort » : chercher, puis cocher jusqu’à cinq documents, envoyés tels qu’ils sont rangés.',
    points: ['« Importer » un fichier', '« Coffre-fort » : chercher', 'Cocher (5 au plus)', '« Envoyer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('.bx-barre [data-bx="ecrire"]', { apres: 800 });
  await f.cliquer('#bx-a', { apres: 150 });
  await f.taper('compta@fiduciaire-demo.exemple.be', 35);
  await f.cliquer('#bx-objet', { apres: 150 });
  await f.taper('Documents d’octobre', 40);
  f.p.once('filechooser', fc => fc.setFiles(FICHIER).catch(() => null));
  await f.cliquer('.bx-pj .bx-fichier', { apres: 1200 });
  await f.montrer('.bx-ecrire .bx-pieces', 'Importé');
  await f.dire('<b>Importer</b> : un fichier de l’ordinateur, ici le relevé de caisse d’octobre.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxpj="coffre"]', { apres: 1200 });
  await f.cliquer('#bx-cfq', { apres: 150 });
  await f.taper('octobre', 90);
  await f.pause(500);
  await f.montrer('.bx-pjp', 'Le coffre-fort');
  await f.dire('Le <b>Coffre-fort</b> : on cherche, puis on coche ; cinq documents au plus.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('[data-bxcf]', { hasText: 'Dossier d’analyse' }).first(), { apres: 600 });
  await f.cliquer(f.p.locator('[data-bxcf]', { hasText: 'Relevé des redevances' }).first(), { apres: 700 });
  await f.montrer('.bx-ecrire .bx-pieces', 'Trois pièces');
  await f.dire('Trois pièces : le fichier importé et deux documents du coffre, tels qu’ils sont rangés.', 4400);
  await f.cacher(150); await f.taire(150);
  await envoyer();
  await f.montrer('.bx-ok', 'Envoyé');
  await f.dire('Envoyé.', 2200);
  await f.cacher(300); await f.taire(200);

  /* Joindre un catalogue */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-joindre-catalogue', titre: 'Joindre un catalogue',
    resume: '« Catalogues » : un catalogue prêt, le mien ou celui de la marque, part en lien vers sa version en ligne, ajouté au texte à l’envoi.',
    points: ['« Catalogues »', 'Cocher un catalogue prêt', '« Envoyer » : le lien s’ajoute au texte'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('.bx-barre [data-bx="ecrire"]', { apres: 800 });
  await f.cliquer('#bx-a', { apres: 150 });
  await f.taper('Nathalie Fiévez <accueil@horizon.exemple.be>', 25);
  await f.cliquer('#bx-objet', { apres: 150 });
  await f.taper('Nos fêtes', 40);
  await f.cliquer('#bx-texte', { apres: 150 });
  await f.taper('Bonjour Nathalie,\n\nVoici notre catalogue des fêtes.\n\nMagasin démo', 20);
  await f.cliquer('[data-bxpj="catalogues"]', { apres: 1200 });
  await f.montrer('.bx-pjp', 'Les catalogues');
  await f.dire('Mes catalogues prêts, et ceux de la marque ; un brouillon se valide d’abord.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxcat="b9001"]', { apres: 1400 });
  await f.montrer('.bx-ecrire .bx-pieces', 'Lien en ligne');
  await f.dire('Il part en <b>lien</b> vers sa version en ligne, ajouté au texte à l’envoi.', 4000);
  await f.cacher(150); await f.taire(150);
  await envoyer();
  await f.cliquer('[data-bxd="Sent"]', { apres: 1000 });
  await f.cliquer('.bx-items .bx-it:first-child [data-bxu]', { apres: 1000 });
  await f.montrer('.bx-lire .bx-texte', 'Dans les Envoyés');
  await f.dire('Dans les Envoyés : le lien du catalogue, sous le texte.', 3400);
  await f.cacher(300); await f.taire(200);

  /* Joindre une offre en PDF */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-joindre-offre', titre: 'Joindre une offre en PDF',
    resume: 'Dans la réponse, « Joindre une offre (PDF de TF Buddy)… » ; le modèle « Envoi de l’offre » reprend son numéro, son total et son jour.',
    points: ['Répondre à la demande', 'Choisir l’offre', 'Le modèle « Envoi de l’offre »', '« Envoyer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('[data-bxd="INBOX"]', { apres: 800 });
  await f.cliquer('[data-bxu="1042"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bx="repondre"]', { apres: 1100 });
  await choisir('#bx-offre', '419', 900);
  await f.montrer('.bx-ecrire .bx-pieces', 'Offre n° 419.pdf');
  await f.dire('« <b>Joindre une offre</b> » : son PDF, tiré de TF Buddy, part avec l’e-mail.', 4000);
  await f.cacher(150); await f.taire(150);
  await choisir('#bx-modele', 'b-offre');
  await f.montrer('#bx-texte', 'Envoi de l’offre');
  await f.dire('Le modèle « <b>Envoi de l’offre</b> » reprend son numéro, son total et son jour.', 4200);
  await f.cacher(150); await f.taire(150);
  await envoyer();
  await f.montrer('.bx-ok', 'Envoyé');
  await f.dire('Envoyé : l’offre en PDF est partie avec la réponse.', 3000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
