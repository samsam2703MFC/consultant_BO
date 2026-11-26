/* Paramètres › le rappel, dans la fenêtre (« Rappel la veille du retrait » dans la fenêtre de la roue), et ses fonctions
 * en chapitres (26/11/2026) :
 *   onglet    param-rappel          allumé ou non, par où, à quelle heure, « Ouvrir la page complète » ; boîte fermée,
 *                                   rien ne se règle
 *   fonction  param-rappel-allumer  l'interrupteur : enregistré tout de suite (« Rappel éteint. », « Rappel allumé… »)
 *   fonction  param-rappel-canal    E-mail, SMS ou Les deux : enregistré tout de suite
 *   fonction  param-rappel-heure    une heure entre 12:00 et 21:00 : enregistrée tout de suite
 * Les mêmes réglages sont sur la page complète (24-03-page), avec « Enregistrer » ; l'interrupteur de la liste des envois,
 * lui, enregistre tout de suite. Données fictives : mocks/24_parametres.js (le rappel allumé, les deux canaux, 17:00), la
 * boîte ouverte avant de filmer (70_boite.js). Chaque phrase vient de panel/public/dashboard/dashboard.js (parRappel,
 * parRappelEnvoyer) ou de panel/src/automatisations.php (amValider). */
const { Film } = require('../film');
const P = require('./parametres');

module.exports = async function () {
  const f = await Film.ouvrir('24-02-rappel', { vue: 'mails', serie: 'Paramètres', parent: 'mails-automatisations', sources: P.SOURCES, avant: P.depart() });

  /* L'onglet */
  await f.onglet({
    id: 'param-rappel', titre: 'Le rappel, dans la fenêtre',
    resume: 'Le rappel la veille du retrait se règle dans la fenêtre des Paramètres : allumé ou non, par où, à quelle heure ; « Ouvrir la page complète » pour le reste.',
    accroche: 'Prévenir la veille',
    programme: ['Allumer ou éteindre le rappel', 'Choisir par où il part', 'Choisir son heure'],
  });
  await P.ouvrirFenetre(f);
  await P.cadreFixe(f, '#db-par [data-pari="rappel"]', 'Rappel la veille du retrait');
  await f.dire('Dans la fenêtre des Paramètres : « <b>Rappel la veille du retrait</b> », allumé, à 17 h.', 4400);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-pari="rappel"]', 900);
  await P.cadreFixe(f, '#db-par .par-det > p', 'Le rappel');
  await f.dire('Chaque commande à retirer demain : son client reçoit un <b>e-mail</b> de la boîte du magasin, ou un <b>SMS</b>, à l’heure choisie.', 6400);
  await P.cadreTout(f, '#db-par .par-ch', 'Trois réglages');
  await f.dire('Trois réglages, enregistrés <b>tout de suite</b> : actif ou non, par où, à quelle heure.', 4600);
  await P.cadreFixe(f, '#db-par .par-bts', 'La page complète');
  await f.dire('Le texte, l’essai et l’équipe : « <b>Ouvrir la page complète</b> ».', 3800);
  await f.dire('Sur un appareil où la boîte est fermée, rien ne se règle : il faut d’abord l’ouvrir.', 4400);
  await f.cacher(300); await f.taire(200);

  /* Allumer ou éteindre */
  await f.fonction({ id: 'param-rappel-allumer', titre: 'Allumer ou éteindre le rappel',
    resume: 'L’interrupteur « Rappel actif » : c’est enregistré tout de suite. Sur la page complète, l’interrupteur de la liste des envois fait de même.',
    points: ['La roue, puis « Rappel la veille du retrait »', 'L’interrupteur « Rappel actif »'] });
  await P.cadreFixe(f, '#db-par [data-parset="actif"]', 'Rappel actif');
  await f.dire('L’interrupteur « <b>Rappel actif</b> ».', 2600);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parset="actif"]', 1100);
  await P.relirePage(f);
  await P.cadreFixe(f, '#db-par .par-flash', 'Éteint');
  await f.dire('<b>Rappel éteint</b> : demain, aucun client ne le reçoit.', 3400);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parset="actif"]', 1100);
  await P.relirePage(f);
  await P.cadreFixe(f, '#db-par .par-flash', 'Allumé');
  await f.dire('Un clic encore : « <b>Rappel allumé</b> : il part chaque jour à 17:00 ».', 4000);
  await f.cacher(300); await f.taire(200);

  /* Par où */
  await f.fonction({ id: 'param-rappel-canal', titre: 'Choisir par où part le rappel',
    resume: 'E-mail, SMS ou les deux : enregistré tout de suite dans la fenêtre. Un client sans e-mail reçoit le SMS à la place, et l’inverse.',
    points: ['La roue, puis « Rappel la veille du retrait »', '« Envoyer par » : E-mail, SMS ou Les deux'] });
  await P.cadreFixe(f, '#db-par .par-seg', 'Envoyer par');
  await f.dire('« <b>Envoyer par</b> » : e-mail, SMS, ou les deux.', 3000);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parset="canal"][data-v="email"]', 1100);
  await P.relirePage(f);
  await P.cadreTout(f, '#db-par .par-seg, #db-par .par-flash', 'E-mail');
  await f.dire('<b>E-mail</b> : enregistré. Un client sans e-mail reçoit le SMS à la place.', 4200);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parset="canal"][data-v="les2"]', 1100);
  await P.relirePage(f);
  await P.cadreFixe(f, '#db-par .par-seg', 'Les deux');
  await f.dire('<b>Les deux</b> : l’e-mail et le SMS, à chacun ce que sa fiche permet.', 3800);
  await f.cacher(300); await f.taire(200);

  /* L'heure */
  await f.fonction({ id: 'param-rappel-heure', titre: 'Choisir l’heure du rappel',
    resume: 'Une heure entre 12:00 et 21:00, de demi-heure en demi-heure : enregistrée tout de suite dans la fenêtre.',
    points: ['La roue, puis « Rappel la veille du retrait »', '« À quelle heure » : la veille, entre 12:00 et 21:00'] });
  await P.cadreFixe(f, '#db-par .par-heure', 'À quelle heure');
  await f.dire('« <b>À quelle heure</b> » : la veille du retrait, entre 12 h et 21 h.', 3600);
  await f.cacher(150);
  await P.choisir(f, '#db-par .par-heure', '17:30', 1100, true);
  await P.relirePage(f);
  await f.taire(150);
  await P.cadreTout(f, '#db-par .par-heure, #db-par .par-flash', '17:30');
  await f.dire('<b>17 h 30</b> : enregistré.', 2600);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parret]', 800);
  await P.cadreFixe(f, '#db-par [data-pari="rappel"]', 'à 17 h 30');
  await f.dire('Dans la liste des Paramètres, le rappel dit maintenant « <b>à 17 h 30</b> ».', 3800);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
