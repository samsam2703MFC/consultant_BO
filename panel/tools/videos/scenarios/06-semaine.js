/* Vidéo 06 — « La page Semaine » (mode d'emploi, la page Semaine du rail, section « Les chiffres »). Environ 3 min.
 * Ce qui est dit vient de dashboard.js (qbChiffre, qbJours, cascade, qbHeures, rendSemaines, qbPlus, la frise des
 * semaines) et de panel/src/LISEZMOI.md. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('06-semaine', { vue: 'semaine', serie: 'Mode d’emploi', titreSerie: 'La page Semaine', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi · Les chiffres', titre: 'La page<br>Semaine', accroche: 'Votre semaine, jour par jour',
    programme: ['Choisir sa semaine', 'Le chiffre et les jours', 'Du chiffre au résultat', 'La tendance et le reste'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Choisir sa semaine', texte: 'En haut, les six semaines d’avant et celle que vous regardez.' });
  await f.etape('1 · Choisir sa semaine');
  await f.montrer('#dash .db-frise', 'Les semaines');
  await f.dire('Chaque <b>semaine</b> avec son chiffre d’affaires et la part de son objectif. La semaine regardée est encadrée.', 4800);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#dash .db-frise .fr-c[data-fdate="2026-11-15"]', { apres: 1800 });
  await f.montrer('#dash .db-frise', 'Une semaine close');
  await f.dire('Un clic ouvre une semaine : ici la <b>S46</b>, du 9 au 15 novembre, close.', 4000);
  await f.montrer('#dash .qb-jauge', 'Le chiffre');
  await f.dire('Une semaine close se lit face à son <b>objectif</b> : 101 %, 206 € au-dessus.', 4200);
  await f.montrer('#dash [data-auj]', 'Revenir');
  await f.dire('« <b>Cette semaine</b> » ramène à la semaine en cours.', 3000);
  await f.cliquer('#dash [data-auj]', { apres: 1800 });
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'Le chiffre et les jours', texte: 'Où en est la semaine, et comment chaque jour s’est passé face à son objectif.' });
  await f.etape('2 · Le chiffre et les jours');
  await f.montrer('#dash .qb-jauge', 'Le chiffre d’affaires');
  await f.dire('La semaine en cours se lit face à l’<b>attendu à ce jour</b> : le trait sur la jauge.', 4200);
  await f.dire('Dessous : au <b>rythme actuel</b>, la fin de semaine, et ce qu’il <b>reste à faire</b> dans les jours qui restent.', 5000);
  await f.montrer('#dash .qb-tuiles', 'Quatre tuiles');
  await f.dire('Les <b>clients</b> par jour ouvert, le <b>panier moyen</b> hors clients pro face au réseau, la <b>marge brute</b> et le <b>résultat</b> hors TVA.', 5800);
  await f.montrer('#dash .qb-jours-c', 'Les jours');
  await f.dire('<b>Les jours</b> : la barre du chiffre, le trait noir de l’objectif du jour, la couleur de l’atteinte.', 4800);
  await f.dire('Vert foncé à 110 % et plus, orange sous 90 %. Aujourd’hui est <b>hachuré</b> : la journée n’est pas finie.', 5000);
  await f.dire('Les jours à venir ne montrent que leur objectif.', 3000);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Du chiffre au résultat', texte: 'Le P&L de la semaine, hors TVA, et les heures qui rapportent.' });
  await f.etape('3 · Le résultat');
  await f.montrer('#dash .qb-res', 'Le résultat');
  await f.dire('Du <b>chiffre encaissé</b> au <b>résultat</b> : la TVA des tickets est retirée, tout se lit hors TVA.', 4800);
  await f.dire('Matière, main-d’œuvre, frais généraux : sous chacun, son <b>seuil</b> ; la couleur compare le coût à ce seuil.', 5400);
  await f.montrer('#dash .qb-res .db-cl-lien', 'Frais');
  await f.dire('« <b>Frais</b> » ouvre le détail des frais, dans la Trésorerie.', 3400);
  await f.montrer('#dash .qb-pile', 'Les heures');
  await f.dire('<b>Les heures</b> d’une journée type : les ventes de chaque heure en moyenne par jour ouvert, et dessous sa marge nette.', 5600);
  await f.dire('La couleur dit ce que l’heure rapporte ; en haut, celle qui rapporte le plus et celle qui rapporte le moins.', 5000);
  await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'La tendance et le reste', texte: 'Les douze dernières semaines face à l’an passé, puis le détail, replié sous la page.' });
  await f.etape('4 · La tendance');
  await f.montrer('#dash .qb-douze', 'Douze semaines');
  await f.dire('<b>Les douze dernières semaines</b> : les clients de chaque semaine, et en pointillé la même semaine un an plus tôt.', 5400);
  await f.dire('Au-dessus de l’an passé en bleu, en dessous en orange ; à droite, l’écart sur douze semaines.', 4800);
  await f.cliquer('#dash .qb-douze button:has-text("Panier moyen")', { apres: 1400 });
  await f.dire('Le même graphique pour le <b>panier moyen</b>, ou les <b>articles par client</b>.', 3800);
  await f.taire(200);
  await f.cliquer('#dash .qb-douze button:has-text("Clients")', { apres: 1000 });
  await f.cacher(200);
  await f.montrer('#dash .qb-plus', 'Le détail');
  await f.dire('Le reste est <b>replié</b> sous la page : un clic sur une puce ouvre sa section.', 4200);
  await f.cliquer('#dash [data-qbplus=reseau]', { apres: 1400 });
  await f.montrer('#dash [data-qbsec=reseau]', 'Ma place dans le réseau');
  await f.dire('<b>Ma place dans le réseau</b> : le magasin face aux autres, sans aucun nom.', 4200);
  await f.cliquer('#dash [data-qbplus=calendrier]', { apres: 1400 });
  await f.montrer('#dash [data-qbsec=calendrier]', 'Le calendrier');
  await f.dire('<b>Le calendrier</b> : chaque jour, son chiffre et sa jauge face à l’objectif.', 4000);
  await f.dire('Une puce ouverte le reste d’une visite à l’autre.', 3000);
  await f.taire(200);
  await f.cacher();
  await f.defiler(-4000);
  return f.fin({
    titre: 'À vous de lire<br>votre semaine', accroche: 'Chaque jour compte',
    retenir: ['Un clic sur une semaine l’ouvre', 'Le trait : l’attendu à ce jour', 'Le résultat se lit hors TVA', 'Le détail est replié en bas'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
