/* Formations et suivi › une formation (FC-019, la bûche de Noël 2026 : un nouveau produit), et ses fonctions en
 * chapitres (26/11/2026) :
 *   onglet    former-formation          la version adaptée pour le magasin, les étapes et points critiques, la grille de
 *                                       la photo du jour, « Qui la suit », la mise en place, l'échéance, le Professeur
 *   fonction  former-declarer           cocher Lu, Quiz ou Vu faire : l'avancement monte
 *   fonction  former-ajouter-personne   « prénom N. », « Ajouter »
 *   fonction  former-mise-en-place      « Mise en place en magasin : c'est fait » (« annuler » la retire)
 *   fonction  former-photo-du-jour      « Envoyer la photo du jour » : la case du jour passe « envoyée, pas encore relue »
 * Le passage à « À valider » quand tout est fait n'est pas simulé par la fausse API : il est dit, pas montré.
 * Données fictives : mocks/72_formation.js (les déclarations changent le dossier relu) ; la photo est une bûche dessinée
 * (lib/manuel.js), tirée en JPEG avant de filmer. Chaque phrase vient de panel/public/dashboard/formation.js (fiche,
 * brancher, agir, envoyerPhoto, PH) ou de formation/src/formation_continue.php. */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const dossier = fs.mkdtempSync(path.join(os.tmpdir(), 'film-fc-'));
  const PHOTO = path.join(dossier, 'buche-19-11.jpg');
  const f = await Film.ouvrir('22-05-formation', {
    vue: 'formation', serie: 'Formations et suivi', parent: 'se-former',
    sources: ['panel/public/dashboard/formation.js', 'formation/src/formation_continue.php'],
    avant: async p => { await A.jpeg(p, '/formation/continue/magasin/photo?shop=4&id=7', PHOTO); },
  });
  const bloc = sel => f.p.locator('.fs-bloc', { has: f.p.locator(sel) });

  /* L'onglet */
  await f.onglet({
    id: 'former-formation', titre: 'Une formation',
    resume: 'La version adaptée pour le magasin, les étapes et points critiques, la grille de la photo du jour, « Qui la suit », la mise en place et l’état de validation.',
    accroche: 'Votre version, ce qu’il reste à faire',
    programme: ['Déclarer lu, quiz réussi, vu faire', 'Ajouter une personne', 'Déclarer la mise en place', 'Envoyer la photo du jour'],
  });
  await f.cliquer('.fs-mini', { apres: 1200 });
  await f.montrer('.fs-l[data-fs-ouvrir="31"]', 'Une ligne du dossier');
  await f.dire('Une ligne du dossier, ou une carte des Nouveautés, ouvre la formation.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fs-l[data-fs-ouvrir="31"]', { apres: 1300 });
  await f.montrer('.fs-fh', 'La bûche de Noël');
  await f.dire('En tête : le code, la catégorie, la version ; le statut et l’avancement.', 3800);
  await f.montrer('.fs-adapt', 'Adaptée pour vous');
  await f.dire('Adaptée pour votre magasin par votre consultant : c’est cette version que vous suivez.', 4400);
  await f.montrer(f.p.locator('.fs-et.crit').first(), 'Point critique');
  await f.dire('Les étapes, une à une ; les <b>points critiques</b> en rouge.', 3200);
  await f.montrer(bloc('.fs-grille'), 'La photo du jour');
  await f.dire('Un nouveau produit : une <b>photo qualité par jour</b> pendant 14 jours, relue par votre consultant.', 4800);
  await f.montrer(f.p.locator('.fs-grille .fs-j.ko').first(), 'À refaire');
  await f.dire('Conforme, à refaire, envoyée… Un jour manqué ou une photo à refaire ajoute un jour.', 4600);
  await f.montrer(bloc('.fs-pers'), 'Qui la suit');
  await f.dire('<b>Qui la suit</b> : lu, quiz réussi, vu faire, déclaré par vous ; puis la mise en place en magasin.', 4800);
  await f.montrer(f.p.locator('.fs-prof').last(), 'Le Professeur');
  await f.dire('Dessous, le Professeur, sur cette formation.', 2800);
  await f.taire(150); await f.cacher(300);

  /* Déclarer lu, quiz réussi, vu faire */
  await f.fonction({ id: 'former-declarer', titre: 'Déclarer lu, quiz réussi, vu faire',
    resume: 'Dans « Qui la suit », cocher la case de la personne : lue, quiz réussi, vu faire. L’avancement de la formation monte.',
    points: ['« Qui la suit »', 'Cocher Lu, Quiz ou Vu faire', 'L’avancement monte'] });
  await f.montrer('.fs-fh .fs-av', '51 %');
  await f.dire('La bûche en est à 51 %.', 2400);
  await f.montrer(bloc('.fs-pers'), 'Qui la suit');
  await f.dire('Julien P. a été vu faire ; Emma G. a réussi le quiz.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fs-eq="Julien P."][data-k=vu]', { apres: 900 });
  await f.cliquer('[data-fs-eq="Emma G."][data-k=quiz]', { apres: 1000 });
  await f.montrer(bloc('.fs-pers'), 'Cochées');
  await f.dire('Deux cases cochées…', 2200);
  await f.montrer('.fs-fh .fs-av', 'L’avancement');
  await f.dire('… l’avancement monte : 61 %. Tout fait, votre consultant relit et valide.', 4200);
  await f.taire(150); await f.cacher(300);

  /* Ajouter une personne */
  await f.fonction({ id: 'former-ajouter-personne', titre: 'Ajouter une personne qui suit la formation',
    resume: 'Sous « Qui la suit » : « prénom N. », puis « Ajouter ». La personne rejoint la liste, ses trois cases à cocher.',
    points: ['Sous « Qui la suit »', '« prénom N. »', '« Ajouter »'] });
  await f.montrer('[data-fs-personne]', 'Ajouter une personne');
  await f.dire('Lucie B. va aussi monter des bûches : on l’ajoute.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fs-personne]', { apres: 150 });
  await f.taper('Lucie B.', 110);
  await f.cliquer('[data-fs-ajout]', { apres: 1200 });
  await f.montrer('.fs-flash', 'Ajoutée');
  await f.dire('« Lucie B. suit la formation. »', 2600);
  await f.montrer(f.p.locator('.fs-pers', { hasText: 'Lucie B.' }), 'Lucie B.');
  await f.dire('Elle rejoint « Qui la suit », avec ses trois cases à cocher.', 3400);
  await f.taire(150); await f.cacher(300);

  /* Déclarer la mise en place */
  await f.fonction({ id: 'former-mise-en-place', titre: 'Déclarer la mise en place en magasin',
    resume: '« Mise en place en magasin : c’est fait » : la date est notée ; « annuler » la retire. Quand tout est fait, la formation passe « À valider ».',
    points: ['« Mise en place en magasin : c’est fait »', 'La date est notée', '« annuler » si besoin'] });
  await f.montrer('.fs-mep', 'La mise en place');
  await f.dire('La bûche est en vitrine : la mise en place est faite.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fs-mep="1"]', { apres: 1200 });
  await f.montrer('.fs-mep', 'Déclarée');
  await f.dire('Déclarée le 19/11/2026 ; « annuler » la retire.', 3200);
  await f.dire('Quand tout est fait, la formation passe « À valider » : votre consultant relit et valide.', 4400);
  await f.taire(150); await f.cacher(300);

  /* Envoyer la photo du jour */
  await f.fonction({ id: 'former-photo-du-jour', titre: 'Envoyer la photo du jour',
    resume: '« Envoyer la photo du jour », choisir la photo : la case du jour passe « envoyée, pas encore relue » ; votre consultant la relit.',
    points: ['« Envoyer la photo du jour »', 'Choisir la photo', 'Votre consultant la relit'] });
  await f.montrer(f.p.locator('.fs-grille .fs-j.faire'), 'Aujourd’hui');
  await f.dire('La case du 19/11 attend sa photo.', 2600);
  await f.cacher(150); await f.taire(150);
  f.p.once('filechooser', fc => fc.setFiles(PHOTO).catch(() => null));
  await f.cliquer('.fs-photo', { apres: 1600 });
  await f.montrer('.fs-flash', 'Envoyée');
  await f.dire('« Photo du jour envoyée : votre consultant la relit. »', 3200);
  await f.montrer(f.p.locator('.fs-grille .fs-j').filter({ hasText: '19/11' }), 'Pas encore relue');
  await f.dire('La case du jour : envoyée, pas encore relue. Il la notera conforme, ou à refaire.', 4400);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  const mp4 = await f.fin();
  fs.rmSync(dossier, { recursive: true, force: true });
  return mp4;
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
