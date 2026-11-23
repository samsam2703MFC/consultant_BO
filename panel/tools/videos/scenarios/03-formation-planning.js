/* Vidéo 03 — module de FORMATION « Construire le planning de la semaine » (à suivre en session, avec un formateur
 * ou seul) : objectifs, démonstration pas à pas, deux mises en pratique, trois questions, récapitulatif. ~5 min 20. */
const { Film } = require('../film');
const E = require('./equipe');

module.exports = async function () {
  const f = await Film.ouvrir('03-formation-planning', { vue: '', serie: 'Formation · module 1', titreSerie: 'Formation · Construire le planning de la semaine', duree: 'env. 5 min' });
  await f.couverture({
    kicker: 'Formation · module 1', titre: 'Construire<br>le planning de la semaine', accroche: 'Le bon monde, au bon moment',
    programme: ['Lire la semaine', 'Le budget d’heures', 'Corriger un trou au comptoir', 'Tenir le budget', 'Le jour, le mois, l’impression'],
    ms: 6000,
  });
  await f.objectifs({ points: [
    'lire le planning de la semaine et son budget d’heures',
    'repérer un trou au comptoir et le corriger',
    'ramener un jour rouge sous la barre des 17 %',
    'créer un service en tirant une ligne à la souris',
    'imprimer le planning pour l’équipe',
  ] });
  await E.ouvrirMonEquipe(f);

  // 1. Lire la semaine
  await f.chapitre({ n: 1, total: 5, titre: 'Lire la semaine', texte: 'Le tableau de la semaine : qui travaille, quand, dans quel secteur, et ce que ça coûte.' });
  await f.etape('1 · Lire la semaine');
  await E.lireLaSemaine(f, { long: true });
  await f.quiz({
    n: 1, question: 'Sous le tableau, un jour affiche <b style="color:#A32014">20,9 %</b> en rouge. Que veut dire le rouge ?',
    reponses: ['Le jour n’est pas encore planifié', 'Le personnel dépasse 20 % du chiffre d’affaires du jour', 'Il manque quelqu’un au comptoir', 'Le chiffre d’affaires est sous l’objectif'],
    bonne: 1, explication: 'La cible du réseau est 17 % : orange au-dessus, rouge au-dessus de 20 %, noir au-dessus de 22 %. Un trou au comptoir se lit, lui, dans les linéaires.',
  });

  // 2. Le budget d'heures
  await f.chapitre({ n: 2, total: 5, titre: 'Le budget d’heures', texte: 'Le coût du personnel face au chiffre d’affaires : la caisse les jours passés, la projection aujourd’hui, l’objectif ensuite.' });
  await f.etape('2 · Le budget');
  await E.leBudget(f, { long: true });

  // 3. Corriger un trou au comptoir
  await f.chapitre({ n: 3, total: 5, titre: 'Corriger un trou au comptoir', texte: 'Mardi, personne au comptoir de 13 h 45 à 14 h 30. La correction la plus simple : déplacer un bord de service.' });
  await f.etape('3 · Un trou au comptoir');
  await E.corrigerLeTrou(f);
  await f.exercice({ titre: 'Repérez les trous de votre semaine', consignes: [
    'Ouvrez <b>Mon équipe › Planning</b>, vue <b>Semaine</b>',
    'Lisez la ligne <b>Comptoir</b> des linéaires, sous le tableau',
    'Notez chaque trou signalé en jaune : le jour et les heures',
    'Pour chacun, cherchez le service le plus proche à avancer ou à prolonger',
  ], pause: 12000 });
  await f.quiz({
    n: 2, question: 'Le comptoir est vide mardi de 13 h 45 à 14 h 30. Quelle est la correction la plus simple ?',
    reponses: ['Ajouter une personne de 8 h pour la journée', 'Avancer le début du service qui commence à 14 h 30', 'Supprimer un service du matin', 'Laisser ainsi : 45 minutes, ce n’est rien'],
    bonne: 1, explication: 'Déplacer un bord de service comble le trou pour trois quarts d’heure de plus seulement : la fenêtre du service en montre aussitôt l’effet sur le budget.',
  });

  // 4. Tenir le budget
  await f.chapitre({ n: 4, total: 5, titre: 'Tenir le budget', texte: 'Un jour rouge : on retire une personne d’un shift, ou on raccourcit un service, en gardant chaque secteur tenu.' });
  await f.etape('4 · Tenir le budget');
  await E.retirerDuShift(f);
  await f.dire('Avant de retirer quelqu’un, vérifiez les <b>linéaires</b> : chaque secteur et le comptoir doivent rester tenus.', 4800);

  // 5. Le jour, le mois, l'impression
  await f.chapitre({ n: 5, total: 5, titre: 'Le jour, le mois, l’impression', texte: 'Créer un service à la souris, voir le mois, imprimer le planning pour l’équipe.' });
  await f.etape('5 · Jour, mois, impression');
  await E.vueJour(f);
  await E.tracerUnService(f);
  await E.vueMois(f);
  await E.imprimer(f);
  await f.exercice({ titre: 'Préparez la semaine prochaine', consignes: [
    'Passez à la semaine suivante avec la flèche <b>›</b>',
    'Ajoutez les services : un clic sur une case vide, ou une ligne tirée au jour',
    'Vérifiez les linéaires : aucun trou au comptoir pendant l’ouverture',
    'Visez <b>17 %</b> sur la semaine, puis imprimez le planning',
  ], pause: 14000 });
  await f.quiz({
    n: 3, question: 'Dans la fenêtre d’un service, quelle durée la ligne montre-t-elle en <b style="color:#3f7a52">vert</b> ?',
    reponses: ['De 2 h à 4 h', 'De 4 h à 8 h', 'De 8 h à 10 h', 'Toutes les durées'],
    bonne: 1, explication: 'Un service de 4 h à 8 h est vert ; en dessous ou au-dessus, la ligne passe au rouge. C’est un repère, pas un refus : le planning l’accepte quand même.',
  });

  return f.fin({
    titre: 'Module terminé', accroche: 'Le planning qui tient le budget',
    retenir: ['La semaine se lit d’un coup d’œil : personnes, heures, %', 'Cible 17 % : orange, rouge au-dessus de 20 %', 'Un trou au comptoir : déplacer un bord de service', 'Créer un service : tirer une ligne au jour', 'Imprimer le planning pour l’équipe'],
    ms: 8000,
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
