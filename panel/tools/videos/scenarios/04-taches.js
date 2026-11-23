/* Vidéo 04 — « Les tâches du magasin » (mode d'emploi de la page Tâches, ?vue=taches). Environ 2 min 40.
 * Données fictives : mocks/04_taches.js (les jours passés, les retours du contrôle) et lib/taches.js (aujourd'hui).
 * Le récit : jeudi 19/11, 15 h 40 ; la checklist d'ouverture est en partie faite ; les croissants du matin sont notés
 * 3 sur 5 (non-conformité mineure), comme lundi et mardi ; la recherche montre la semaine de la tâche. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('04-taches', { vue: 'taches', serie: 'Mode d’emploi', titreSerie: 'Les tâches du magasin', duree: 'env. 2 min 40' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Les tâches<br>du magasin', accroche: 'Ce qui est fait, ce qui reste',
    programme: ['La semaine d’un coup d’œil', 'Les codes des checklists', 'Les checklists du jour', 'Le contrôle en photo'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'La semaine d’un coup d’œil', texte: 'Chaque jour, la part des tâches obligatoires faites, les photos et le contrôle.' });
  await f.etape('1 · La semaine');
  await f.montrer('.tj-bande', 'La semaine');
  await f.dire('La page <b>Tâches</b> s’ouvre sur la semaine : une case par jour, du lundi au dimanche.', 4200);
  await f.montrer('.tj-j.on', 'Aujourd’hui');
  await f.dire('Aujourd’hui, en cours : <b>63 %</b> des tâches obligatoires faites, 5 sur 8.', 4000);
  await f.dire('Dessous : les <b>photos rendues</b>, la <b>note moyenne</b> du contrôle et les <b>non-conformités</b> du jour.', 4800);
  await f.montrer('.tj-nc', 'Non-conformités · 30 jours');
  await f.dire('En haut, les <b>non-conformités</b> des 30 derniers jours, et combien ont été reprises.', 4400);
  await f.cacher(200);
  await f.cliquer('[data-tjjour="2026-11-17"]', { apres: 1500 });
  await f.montrer('.tj-j.on', 'Mardi');
  await f.dire('Un clic sur un jour l’ouvre : mardi, <b>88 %</b>, 7 tâches sur 8.', 3800);
  await f.taire(150); await f.cacher(200);
  await f.cliquer('[data-tjjour="2026-11-19"]', { apres: 1500 });

  await f.chapitre({ n: 2, total: 4, titre: 'Les codes des checklists', texte: 'Une checklist, c’est une liste de tâches à faire à un moment de la journée.' });
  await f.etape('2 · Les codes');
  await f.montrer('.tj-g-code', 'Le code');
  await f.dire('Chaque checklist a un <b>code à trois parties</b> : 1.1.01.', 3800);
  await f.montrer('.tj-g-col:nth-of-type(2)', 'Qui');
  await f.dire('Le 1er chiffre dit <b>qui</b> : 1 la vente, 2 la boulangerie, 3 le traiteur, 4 le nettoyage, 5 la gestion.', 5200);
  await f.montrer('.tj-g-col:nth-of-type(3)', 'Quand');
  await f.dire('Le 2e dit <b>quand</b> : l’ouverture, la journée, l’après-midi, la fermeture… Puis le numéro de la checklist.', 5200);
  await f.montrer('.tj-g-txt', 'Les règles');
  await f.dire('Seules les tâches <b>obligatoires</b> comptent dans les pourcentages.', 3600);
  await f.dire('Une tâche qui demande une <b>photo</b> n’est faite qu’avec sa photo : cochée sans photo, elle ne compte pas.', 5200);
  await f.cacher(200);
  await f.cliquer('[data-tjguide]', { apres: 1000 });
  await f.dire('Un clic sur le titre <b>replie</b> ce guide.', 3000);

  await f.chapitre({ n: 3, total: 4, titre: 'Les checklists du jour', texte: 'Dans l’ordre des codes : faites, en partie, pas commencées ou à venir.' });
  await f.etape('3 · Les checklists du jour');
  await f.montrer('.db-card:has(.tj-filtres)', 'Les checklists du jour');
  await f.dire('Les <b>checklists du jour</b> : chacune avec son heure, sa barre et son état.', 4000);
  await f.cacher(200);
  await f.montrer('.tj-filtres', 'Les filtres');
  await f.dire('Les filtres trient par état. Voyons ce qui est <b>en partie</b> fait.', 3600);
  await f.cacher(150);
  await f.cliquer('.tj-filtres [data-tjf="partie"]', { apres: 1000 });
  await f.cliquer('[data-tjcl="1.1.01 · Ouverture du comptoir"]', { apres: 1000 });
  await f.montrer('.tj-cl.open', 'Ouverture du comptoir');
  await f.dire('Ouverte, la checklist montre ses tâches : qui l’a faite, à quelle heure, sa photo et sa note.', 4800);
  await f.dire('Ici, il reste une tâche : <b>les températures des frigos</b> ne sont pas relevées.', 4400);
  await f.taire(150); await f.cacher(200);
  await f.cliquer('[data-tjcl="1.1.01 · Ouverture du comptoir"]', { apres: 700 });
  await f.cliquer('.tj-filtres [data-tjf="tout"]', { apres: 1000 });

  await f.chapitre({ n: 4, total: 4, titre: 'Le contrôle en photo', texte: 'Chaque photo rendue est notée de 1 à 5. Sous 4 : une non-conformité, à corriger.' });
  await f.etape('4 · Le contrôle en photo');
  await f.cliquer('[data-tjcl="2.1.01 · Cuisson du matin"]', { apres: 1000 });
  await f.montrer('.tj-cl.open:has([data-tjcl^="2.1.01"]) .tj-t', 'Les croissants du matin');
  await f.dire('Les croissants à la sortie du four : notés <b>3 sur 5</b>, une non-conformité mineure, avec le mot du contrôleur.', 5200);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('.tj-ph[data-cqvoir="9003"]', { apres: 1400 });
  await f.dire('Un clic sur la photo l’ouvre en grand : le contrôleur y a <b>entouré</b> ce qui ne va pas.', 4400);
  await f.montrer('.db-cql .fi', 'Le constat');
  await f.dire('À côté : qui l’a rendue et quand, qui l’a notée et quand.', 3800);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('.db-cql [data-cqfermer]', { apres: 900 });
  await f.cliquer('[data-tjq]', { apres: 300 });
  await f.taper('croissant', 90);
  await f.pause(1600);
  await f.montrer('#tj-q-res', 'Chercher une tâche');
  await f.dire('<b>Chercher une tâche</b> montre sa semaine, jour par jour, avec ses photos et ses notes.', 4400);
  await f.dire('Les croissants : 3 lundi et mardi, <b>conformes mercredi</b>, de nouveau 3 aujourd’hui. L’écart revient.', 5200);
  await f.cacher();

  return f.fin({
    titre: 'À vous de jouer', accroche: 'Rien ne reste dans l’ombre',
    retenir: ['Chaque jour : la part des tâches obligatoires faites', 'Une tâche à photo compte avec sa photo', 'Sous 4 sur 5 : une non-conformité à corriger', 'Chercher une tâche : sa semaine et ses notes'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
