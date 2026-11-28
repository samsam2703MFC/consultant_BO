/* Vidéo 20 — « La boîte mail du magasin » (mode d'emploi, ouverte depuis la Boîte mail). Environ 3 min.
 * Données fictives : mocks/70_boite.js (la boîte magasin.demo@exemple.be, fermée au départ, des e-mails inventés). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('20-boite-mail', { vue: 'boite', serie: 'Mode d’emploi', titreSerie: 'La boîte mail du magasin', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'La boîte mail<br>du magasin', accroche: 'Vos e-mails, sans quitter le dashboard',
    programme: ['Ouvrir la boîte et s’y retrouver', 'Lire, répondre, créer une offre', 'Briefing : les non-lus en cartes', 'Pilote et Radar'], ms: 4600,
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Ouvrir la boîte', texte: 'La boîte e-mail du magasin, en tête du rail.', ms: 3100 });
  await f.etape('1 · Ouvrir la boîte');
  await f.montrer('#db-rail .sidebar__item[data-vue=boite]', 'Boîte mail');
  await f.dire('La <b>Boîte mail</b> est tout en haut du rail : c’est la boîte e-mail du magasin.', 3200);
  await f.montrer('.bx-porte-c', 'Le mot de passe');
  await f.dire('Elle s’ouvre avec <b>son mot de passe</b>, une fois par appareil, pour 30 jours.', 3400);
  await f.cacher(150);
  await f.cliquer('#bx-o-mdp', { apres: 200 });
  await f.taper('demo-boite', 50);
  await f.cliquer('[data-bx=ouvrir]', { apres: 1300 });
  await f.montrer('.bx-dossiers', 'Les dossiers');
  await f.dire('Les <b>dossiers</b> : la réception et ses non-lus, les brouillons, les envoyés… puis les vôtres. Dessous, la liste et ses filtres.', 4800);
  await f.montrer('.bx-barre [data-bx=ecrire]', 'Écrire');
  await f.dire('<b>Écrire</b> un e-mail ; à côté, les <b>modèles</b> de réponse et les <b>messages automatiques</b>.', 3800);
  await f.cacher(200);

  await f.chapitre({ n: 2, total: 4, titre: 'Lire, répondre, créer une offre', texte: 'Un e-mail s’ouvre d’un clic.', ms: 3100 });
  await f.etape('2 · Lire et répondre');
  await f.cliquer('[data-bxu="1049"]', { apres: 1000 });
  await f.montrer('.bx-gestes', 'Les gestes');
  await f.dire('<b>Répondre</b>, <b>Transférer</b> ; à droite : archiver, corbeille, suivre, non lu.', 3600);
  await f.montrer('.bx-vendre', 'Créer une offre');
  await f.dire('Une demande ? <b>Créer une offre</b> en fait une offre : le client, le jour et les produits repérés, à vérifier.', 5000);
  await f.cacher(150);
  await f.cliquer('.bx-gestes [data-bx=repondre]', { apres: 1000 });
  const mo = await f.survoler('#bx-modele');
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [mo.x + mo.w / 2, mo.y + mo.h / 2]);
  await f.p.selectOption('#bx-modele', 'b-accuse');
  await f.pause(600);
  await f.montrer('#bx-texte', 'Un modèle');
  await f.dire('La réponse est prête, l’e-mail cité dessous. Un <b>modèle</b> s’insère, rempli tout seul : le prénom, le magasin.', 5000);
  await f.montrer('.bx-pj', 'Pièces jointes');
  await f.dire('En pièce jointe : un fichier, le <b>coffre-fort</b>, un <b>catalogue</b>, ou une offre en PDF.', 3800);
  await f.cacher(150);
  await f.cliquer('.bx-ecrire [data-bx=envoyer]', { apres: 1000 });
  await f.montrer('.bx-ok', 'Envoyé');
  await f.dire('<b>Envoyer</b> : l’e-mail part de l’adresse du magasin.', 3000);
  await f.cacher(200);

  await f.chapitre({ n: 3, total: 4, titre: 'Briefing : les non-lus en cartes', texte: 'Quatre modes : Classique, Briefing, Pilote, Radar. Rien ne part sans votre validation.', ms: 3800 });
  await f.etape('3 · Le briefing');
  await f.defiler(-2000);
  await f.cliquer('[data-bxmode=briefing]', { apres: 1200 });
  await f.montrer('.bx-carte', 'Une carte');
  await f.dire('Une carte par non-lu, déjà lu par l’IA : la catégorie, l’urgence, sa <b>confiance</b>, le résumé.', 4600);
  await f.montrer('.bx-carte-rep', 'Réponse rédigée');
  await f.dire('La <b>réponse rédigée</b>. Ce que l’IA ne sait pas — un prix, un geste — reste « [à compléter] » : à vous de l’écrire.', 5400);
  await f.montrer('.bx-voix', 'Mains dans la farine');
  await f.dire('« Mains dans la farine » : la carte lue à voix haute ; on répond « oui », « plus tard », « ouvre » ou « stop ».', 5000);
  await f.cacher(150);
  await f.cliquer('.bx-br-file [data-bxbr="1046"]', { apres: 800 });
  await f.montrer('.bx-carte-rep', 'Prête');
  await f.dire('Une candidature : la réponse est <b>prête</b>. <b>Valider</b> l’envoie ; <b>plus tard</b> remet la carte en file.', 4000);
  await f.cacher(150);
  await f.cliquer('.bx-carte-pied [data-bx=brValider]', { apres: 1100 });
  await f.montrer('.bx-ok', 'Validée');
  await f.dire('Validée : la réponse part, la carte suivante arrive. Au clavier : ← et →.', 3200);
  await f.cacher(200);

  await f.chapitre({ n: 4, total: 4, titre: 'Pilote et Radar', texte: 'Des réponses dans des garde-fous fixes ; la demande à venir.', ms: 3100 });
  await f.etape('4 · Pilote et Radar');
  await f.cliquer('[data-bxmode=pilote]', { apres: 1200 });
  await f.montrer('.bx-crans', 'Trois crans');
  await f.dire('Le <b>Pilote</b>, éteint par défaut. <b>Brouillons</b> : il rédige, vous envoyez. <b>Simples</b> : il envoie seul les réponses simples.', 5600);
  await f.montrer('.bx-gardes', 'Les garde-fous');
  await f.dire('Des garde-fous fixes : jamais seul sur les prix, les remises, les réclamations, les allergènes, les commandes, les professionnels, le personnel.', 6000);
  await f.dire('Il s’arrête sous <b>85 %</b> de confiance, et laisse <b>10 minutes</b> pour annuler chaque envoi.', 3800);
  await f.cacher(150);
  await f.defiler(-2000);
  await f.cliquerConfirme('[data-bxpin="2"]', 'Passer le pilote en « Simples » ?',
    'Le pilote répondra seul, depuis magasin.demo@exemple.be, aux e-mails simples qui arrivent à partir de maintenant : 10 minutes après les avoir lus, annulables jusque-là. Jamais aux prix, remises, réclamations, allergènes, commandes ni aux professionnels.', { apres: 1100 });
  await f.montrer('.bx-pi-hero', 'Simples');
  await f.dire('Allumé, il ne lit que les e-mails qui arrivent <b>après</b>, jamais l’arriéré.', 3400);
  await f.cacher(150);
  await f.cliquer('[data-bxmode=radar]', { apres: 1200 });
  await f.montrer('.bx-ra-alertes', 'Le Radar');
  await f.dire('Le <b>Radar</b> lit les e-mails des trois dernières semaines : demandes sans réponse, jour chargé, clients mécontents.', 4600);
  await f.montrer('.bx-ra-frise', 'Les trois semaines à venir');
  await f.dire('La demande des trois semaines à venir, estimée aux prix du catalogue. Le Radar n’agit jamais seul.', 4600);
  await f.cacher(200);

  return f.fin({
    titre: 'À vous<br>de répondre', accroche: 'Des clients servis, une boîte rangée',
    retenir: ['La boîte s’ouvre avec son mot de passe, par appareil', 'Répondre avec un modèle, ou créer une offre', 'Briefing : rien ne part sans votre accord', 'Pilote : éteint par défaut, garde-fous fixes'], ms: 6000,
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
