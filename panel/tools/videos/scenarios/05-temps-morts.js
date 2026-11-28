/* Vidéo 05 — « Les temps morts » (mode d'emploi de la page Temps morts, ?vue=morts). Environ 2 min 15.
 * Données fictives : mocks/05_temps_morts.js (les tickets du magasin de démo, minute par minute, et la mesure du serveur).
 * Le récit : jeudi 19/11, 15 h 40, deux temps morts jusqu'ici (le plus long de 14 h 19 à 14 h 58) ; mardi, douze, deux de
 * plus que l'habitude des quatre mardis d'avant ; le seuil passe à 15 puis à 5 minutes, et revient à 10. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('05-temps-morts', { vue: 'morts', serie: 'Mode d’emploi', titreSerie: 'Les temps morts', duree: 'env. 2 min 15' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Les temps morts', accroche: 'Le comptoir sans client, minute par minute',
    programme: ['Ce qu’est un temps mort', 'La journée minute par minute', 'La semaine et l’habitude', 'Régler le seuil'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Ce qu’est un temps mort', texte: '10 minutes ou plus sans un seul ticket, entre le premier et le dernier ticket de la journée.' });
  await f.etape('1 · Le temps mort');
  await f.montrer('.tm-sous', 'La définition');
  await f.dire('Un <b>temps mort</b> : 10 minutes ou plus sans un seul ticket, entre le premier et le dernier ticket du jour.', 5200);
  await f.montrer('.tm-res .tm-v', 'Sans client');
  await f.dire('En tête, le temps <b>sans client</b> : 49 minutes sur l’ouverture, du premier au dernier ticket.', 4400);
  await f.montrer('.tm-res .tm-r1 > .tm-q:nth-child(3)', 'Temps morts');
  await f.dire('Aujourd’hui, en cours : <b>2 temps morts</b>. Dessous, l’habitude : les 4 jeudis d’avant.', 4600);
  await f.montrer('.tm-res .tm-r1 > .tm-q:nth-child(4)', 'Le plus long trou');
  await f.dire('Le <b>plus long trou</b> : 39 minutes, de 14 h 19 à 14 h 58.', 3800);
  await f.montrer('.tm-res', 'Le résumé');
  await f.dire('Tous les tickets comptent : ceux du <b>comptoir</b> et ceux des <b>pros</b>.', 3800);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'La journée minute par minute', texte: 'Une ligne par heure, un carré par minute.' });
  await f.etape('2 · La journée');
  await f.montrer('.tm-frise', 'Minute par minute');
  await f.dire('La journée en grille : <b>une ligne par heure</b>, un carré par minute.', 3800);
  await f.dire('En noir, une minute avec un ticket ; le chiffre dit combien, quand il y en a plusieurs.', 4400);
  await f.montrer('.tm-frise .tm-l:has(.tm-hr:has-text("14 h"))', '14 h');
  await f.dire('En rose, les minutes d’un temps mort : de <b>14 h 19 à 14 h 58</b>, personne au comptoir.', 4600);
  await f.montrerTout('.tm-frise .tm-pct', 'Sans client');
  await f.dire('À droite, la part de chaque heure <b>sans client</b> : plus elle monte, plus la case fonce.', 4600);
  await f.montrer('.tm-leg', 'La légende');
  await f.dire('Hachuré : avant le premier ou après le dernier ticket. Ces minutes-là ne comptent pas.', 4400);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'La semaine et l’habitude', texte: 'Chaque jour de la semaine, et chaque jour face aux mêmes jours des semaines d’avant.' });
  await f.etape('3 · La semaine');
  await f.montrer('.db-card:has(.tm-sem)', 'La semaine');
  await f.dire('La <b>semaine</b>, jour par jour : temps morts, temps sans client, part de l’ouverture, plus long trou.', 5000);
  await f.montrerTout('.tm-sem tr.tot', 'Les moyennes');
  await f.dire('Dessous, la <b>moyenne par jour</b>, face à la semaine d’avant.', 3600);
  await f.dire('Seules les <b>journées closes</b> comptent dans les moyennes.', 3400);
  await f.taire(150); await f.cacher(200);
  await f.cliquer('[data-tmjour="2026-11-17"]', { apres: 1500 });
  await f.montrer('.tm-res', 'Mardi');
  await f.dire('Un clic sur un jour l’ouvre : mardi, <b>12 temps morts</b>, 2 de plus que l’habitude des 4 mardis d’avant.', 5200);
  await f.dire('Rouge : plus de temps mort qu’à l’habitude. Vert : moins.', 3400);
  await f.montrer('.tm-frise', 'Mardi, minute par minute');
  await f.dire('L’après-midi est calme : à 15 h et à 18 h, les trois quarts de l’heure sans client.', 4600);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'Régler le seuil', texte: '5, 10 ou 15 minutes : à partir de quand un creux compte.' });
  await f.etape('4 · Le seuil');
  await f.montrer('.tm-seuil', 'Temps mort dès');
  await f.dire('Le <b>seuil</b> se choisit en haut : 5, 10 ou 15 minutes. 10, par défaut.', 4000);
  await f.cacher(150);
  await f.cliquer('[data-tmseuil="15"]', { apres: 1400 });
  await f.montrer('.tm-res .tm-r1 > .tm-q:nth-child(3)', 'Dès 15 min');
  await f.dire('Dès 15 minutes, ne restent que les longs creux : <b>2</b> ce mardi.', 3800);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-tmseuil="5"]', { apres: 1400 });
  await f.montrer('.tm-res .tm-r1 > .tm-q:nth-child(3)', 'Dès 5 min');
  await f.dire('Dès 5 minutes, chaque petit creux compte : <b>58</b>.', 3600);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-tmseuil="10"]', { apres: 1400 });
  await f.dire('Le choix reste sur cet appareil. <b>Mon équipe</b> reprend ces temps morts dans le planning du jour.', 4800);
  await f.taire(150);

  return f.fin({
    titre: 'À vous de jouer', accroche: 'Chaque creux se voit',
    retenir: ['Un temps mort : 10 min ou plus sans ticket', 'La grille montre chaque minute du jour', 'Chaque jour face à son habitude', 'Le seuil : 5, 10 ou 15 minutes'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
