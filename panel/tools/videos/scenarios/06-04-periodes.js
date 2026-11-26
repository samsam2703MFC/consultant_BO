/* Semaine › « Les périodes (matin, midi, après-midi) : Colorer par CA · Marge nette · Clients », la vidéo de l'onglet
 * (26/11/2026) :
 *   onglet  semaine-periodes   dans « Le détail heure par heure », la carte « Les périodes » dépliée : une case par jour
 *                              et par période (ventes, marge nette, clients, son rang dans la journée), la couleur
 *                              selon le chiffre choisi, la semaine et chaque journée en total.
 * Aucun geste n'écrit au serveur : pas de chapitre de fonction. Données fictives : mocks/15_chiffres.js (statsPeriode,
 * periodes). Chaque phrase vient de panel/public/dashboard/dashboard.js (rendPeriodes) et de panel/src/stats_ventes.php
 * (svPeriodes). */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');

const PER = '#dash .db-card:has([data-perdrop])';

module.exports = async function () {
  const f = await ouvrir('06-04-periodes', 'semaine', { puces: ['heures'], sources: ['panel/src/stats_ventes.php'] });

  await f.onglet({
    id: 'semaine-periodes', titre: 'Les périodes : matin, midi, après-midi',
    resume: 'La semaine découpée en matin, midi et après-midi : chaque case dit ses ventes, sa marge nette et ses clients, et son rang dans la journée ; la couleur suit le chiffre choisi.',
    accroche: 'Quand se fait la semaine',
    programme: ['Colorer par CA', 'Colorer par marge nette', 'Colorer par clients'],
  });

  await enHautDe(f, PER, 120);
  await dire(f, PER, 'Les périodes', 'Dans « Le détail heure par heure », la carte « <b>Les périodes</b> », repliée : matin, midi, après-midi.', 5400);
  await dire(f, PER + ' .ct .db-mini', 'D’un coup d’œil', 'Même repliée, elle dit quand se fait la semaine : ici le midi, 40 % des ventes.', 4600);
  await rien(f);
  await f.cliquer(PER + ' [data-perdrop]', { dx: 0.3, apres: 1400 });
  await enHautDe(f, PER, 20);
  await dire(f, PER + ' .db-per', 'Jour × période', 'Une case par jour et par période : ses ventes, sa <b>marge nette</b> et sa part des ventes, ses clients.', 6000);
  await f.montrer(PER + ' .db-per .c em >> nth=0', 'Le rang');
  await f.dire('Le petit chiffre : le <b>rang</b> de la période dans sa journée, au CA.', 4000);
  await dire(f, PER + ' .db-per', 'Colorer par CA', 'La couleur compare chaque case à la meilleure case de la semaine, sur le chiffre choisi.', 4800);
  await rien(f);

  await f.cliquer(PER + ' [data-percol="res"]', { apres: 1300 });
  await dire(f, PER + ' .db-per', 'Marge nette', '« <b>Marge nette</b> » : la grille se recolore sur la marge ; le rang, lui, reste au CA.', 5000);
  await rien(f);
  await f.cliquer(PER + ' [data-percol="tickets"]', { apres: 1300 });
  await dire(f, PER + ' .db-per', 'Clients', '« <b>Clients</b> » : la même grille, colorée par le nombre de clients.', 4200);
  await rien(f);
  await f.montrerTout(PER + ' .db-per .c.sem', 'La semaine');
  await f.dire('À droite, la semaine par période ; dessous, le total de chaque journée.', 4000);
  await rien(f);
  await f.cliquer(PER + ' [data-percol="ca"]', { apres: 1200 });
  await dire(f, PER + ' .db-perleg', 'Avant 6 h, après 19 h', 'Les ventes d’avant 6 h comptent dans le matin, celles d’après 19 h dans l’après-midi.', 4600);
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
