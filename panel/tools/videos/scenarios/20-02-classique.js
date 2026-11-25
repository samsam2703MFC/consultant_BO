/* Boîte mail › le mode Classique (?vue=boite), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    boite-classique     la barre, la liste et ses filtres, chercher, lire un e-mail, une offre citée
 *   fonction  boite-gestes        archiver, mettre à la corbeille, suivre, marquer non lu (l'e-mail ouvert)
 *   fonction  boite-creer-offre   « Créer une offre » : Vendre › Offres pré-remplie, l'offre créée, la réponse prête
 * Lire un e-mail le marque « lu » dans la boîte : c'est un geste de lecture, montré dans l'onglet ; « Ouvrir l'offre
 * n° … » aussi. La pièce jointe reçue (nouvel onglet du navigateur) n'est pas ouverte. Données fictives :
 * mocks/70_boite.js, 13_offres.js (l'offre n° 418 d'Hélène Marchal ; Bureau Horizon SRL, client de TF Buddy).
 * Chaque phrase vient de panel/public/dashboard/boite.js (barre, liste, lecture, creerOffre) ou d'offres.js (nouvelle). */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-02-classique', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php', 'panel/public/dashboard/offres.js'], avant: B.ouverte() });

  /* L'onglet */
  await f.onglet({
    id: 'boite-classique', titre: 'Le mode Classique',
    resume: 'La boîte comme un webmail : la barre, la liste et ses filtres, la recherche, puis l’e-mail ouvert à droite avec ses gestes.',
    accroche: 'Lire, chercher, retrouver', programme: ['Archiver, corbeille, suivre, non lu', 'Créer une offre depuis un e-mail'],
  });
  await f.montrer('.bx-modes', 'Classique');
  await f.dire('Quatre modes : <b>Classique</b> est celui de départ, et l’appareil garde le dernier choisi.', 4400);
  await f.montrer('.bx-barre', 'La barre');
  await f.dire('La barre : chercher, <b>Écrire</b>, les <b>Modèles</b> et les <b>Messages automatiques</b>.', 4400);
  await f.montrer('.bx-dossiers', 'Les dossiers');
  await f.dire('Dessous, les dossiers de la boîte : la Réception et ses non-lus d’abord.', 3800);
  await B.cadre(f, '.bx-liste', 'La liste');
  await f.dire('La liste, la plus récente en haut : un point pour un <b>non-lu</b>, un trombone pour une pièce jointe, une flèche pour un e-mail répondu.', 6200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxf="nonlus"]', { apres: 900 });
  await f.montrer('.bx-filtres', 'Les filtres');
  await f.dire('Les filtres : Tous, <b>Non lus</b>, Suivis, Avec pièce jointe.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxf=""]', { apres: 700 });
  await f.cliquer('#bx-q', { apres: 200 });
  await f.taper('communion', 90);
  await f.pause(900);
  await B.cadre(f, '.bx-liste', 'Chercher');
  await f.dire('La recherche lit l’objet, l’expéditeur et le texte, dans le dossier ouvert.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('#bx-q').fill(''); await f.p.locator('#bx-q').dispatchEvent('input');
  await f.pause(900);
  await f.cliquer('[data-bxu="1047"]', { apres: 1000 });
  await B.cadre(f, '.bx-lire', 'L’e-mail ouvert');
  await f.dire('Un clic ouvre l’e-mail à droite : l’en-tête, le texte, les pièces jointes. Il passe en « lu ».', 5000);
  await f.montrer('.bx-pieces', 'Pièce jointe');
  await f.dire('Une pièce jointe s’ouvre d’un clic, dans un nouvel onglet du navigateur.', 3600);
  await f.montrer('.bx-gestes', 'Les gestes');
  await f.dire('<b>Répondre</b>, <b>Transférer</b> ; à droite : archiver, corbeille, suivre, non lu.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxu="1044"]', { apres: 1000 });
  await f.montrer('.bx-vendre', 'Une offre citée');
  await f.dire('Cet e-mail cite une offre : « <b>Ouvrir l’offre n° 418</b> » la montre dans Vendre › Offres.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxoffre="418"]', { apres: 1800 });
  await f.montrer('.of-d', 'L’offre n° 418');
  await f.dire('L’offre d’Hélène Marchal, dans l’onglet « Toutes » des Offres.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-rail .sidebar__item[data-vue=boite]', { apres: 1200 });

  /* Archiver, corbeille, suivre, non lu */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-gestes', titre: 'Archiver, mettre à la corbeille, suivre, marquer non lu',
    resume: 'Les icônes à droite de Répondre et Transférer, sur l’e-mail ouvert : chacune agit tout de suite dans la boîte.',
    points: ['Ouvrir l’e-mail', 'Archiver ou corbeille : il quitte la Réception', 'Suivre : un drapeau', 'Non lu : il redevient non lu'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 500 });
  await f.cliquer('[data-bxu="1041"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bxg="archiver"]', { apres: 1100 });
  await f.montrer('.bx-ok', 'Archivé');
  await f.dire('<b>Archiver</b> : la lettre quitte la Réception et rejoint les Archives.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxu="1037"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bxg="corbeille"]', { apres: 1100 });
  await f.montrer('.bx-ok', 'Corbeille');
  await f.dire('Une livraison passée : à la <b>corbeille</b>.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxu="1042"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bxg="suivi"]', { apres: 900 });
  await f.montrer('.bx-gestes [data-bxg="nonsuivi"]', 'Suivi');
  await f.dire('Une demande à traiter : <b>Suivre</b> pose un drapeau ; le filtre « Suivis » la retrouve.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxu="1049"]', { apres: 800 });
  await f.cliquer('.bx-gestes [data-bxg="nonlu"]', { apres: 1000 });
  await f.montrer('.bx-it.on', 'Non lu');
  await f.dire('<b>Marquer non lu</b> : le point revient, le compteur de la Réception aussi.', 3800);
  await f.cacher(300); await f.taire(200);

  /* Créer une offre depuis un e-mail */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-creer-offre', titre: 'Créer une offre depuis un e-mail',
    resume: '« Créer une offre » ouvre Vendre › Offres, pré-remplie avec le client, le jour et les produits repérés dans l’e-mail ; on vérifie, on crée, et la réponse part avec l’offre en PDF.',
    points: ['Ouvrir la demande', '« Créer une offre »', 'Vérifier, puis créer', 'Répondre avec l’offre en PDF'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 500 });
  await f.cliquer('[data-bxu="1042"]', { apres: 800 });
  await f.montrer('.bx-vendre', 'Créer une offre');
  await f.dire('Une demande de plateaux lunch : « <b>Créer une offre</b> ».', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-vendre [data-bx="offre"]', { apres: 2000 });
  await f.montrer('.of-ok', 'Repéré dans l’e-mail');
  await f.dire('Vendre › Offres s’ouvre, pré-remplie : le client retrouvé dans TF Buddy, le jour, les produits repérés.', 4800);
  await f.montrer('.of-lignes', 'Les produits');
  await f.dire('Les produits, aux prix du catalogue : tout est <b>à vérifier</b> avant de créer.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofenregistrer]', { apres: 1500 });
  await B.enHaut(f);
  await f.montrer('.of-ok', 'Offre créée');
  await f.dire('L’offre est créée dans TF Buddy ; un bouton y <b>répond avec l’offre en PDF</b>.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofrepondre]', { apres: 2000 });
  await f.montrer('.bx-ecrire .bx-pieces', 'L’offre en PDF');
  await f.dire('La réponse est prête : le modèle « Envoi de l’offre », l’offre en PDF. On relit, on envoie.', 4800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
