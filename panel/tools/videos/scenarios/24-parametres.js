/* Vidéo 24 — « Mails et automatisations » (mode d'emploi de Paramètres › Mails et automatisations, ?vue=mails, et de la
 * roue des paramètres en bas du rail). Environ 3 min.
 * Données fictives : mocks/24_parametres.js (le rappel la veille du retrait allumé ; demain, six commandes ; le journal ;
 * trois personnes dans l'équipe) ; la boîte du magasin est ouverte sur cet appareil (00_commun.js).
 * Le récit : la roue, la fenêtre des paramètres et le rappel ; la page complète ; l'heure passe à 17:30, on enregistre,
 * on envoie un essai ; Sarah K. rejoint l'équipe qui reçoit les fiches des promotions. */
const { Film } = require('../film');

/* La roue est épinglée au bas du rail, la fenêtre des paramètres est fixe : les montrer ou les cliquer sans faire défiler
 * (le haut du rail resterait caché tout le film, la page glisserait sous la fenêtre). */
async function cadreFixe(f, sel, etiq) {
  const r = await f.rect(sel, false);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq]);
}
async function cliquerFixe(f, sel, apres = 900) {
  const r = await f.rect(sel, false);
  await f.aller(r.x + r.w / 2, r.y + r.h / 2); await f.pause(250);
  await f.p.mouse.down(); await f.pause(90); await f.p.mouse.up();
  await f.pause(apres);
}

module.exports = async function () {
  const f = await Film.ouvrir('24-parametres', { vue: 'mails', serie: 'Mode d’emploi', titreSerie: 'Mails et automatisations', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Mails et<br>automatisations', accroche: 'Ce que le magasin envoie tout seul',
    programme: ['La roue des paramètres', 'Les envois du magasin', 'Régler le rappel de la veille', 'L’équipe qui reçoit les fiches'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'La roue des paramètres', texte: 'En bas du rail : tous les réglages, dans une fenêtre.' });
  await f.etape('1 · Les paramètres');
  await cadreFixe(f, '#db-rail [data-par]', 'Paramètres');
  await f.dire('La <b>roue</b>, en bas du rail : tous les paramètres du magasin.', 3600);
  await f.taire(150); await f.cacher(150);
  await cliquerFixe(f, '#db-rail [data-par]', 1200);
  await cadreFixe(f, '#db-par', 'Paramètres');
  await f.dire('Ils sont rangés : la <b>boîte mail</b>, les <b>automatisations</b>, cet appareil.', 3800);
  await f.taire(150); await f.cacher(150);
  await cliquerFixe(f, '#db-par [data-pari="rappel"]', 1000);
  await cadreFixe(f, '#db-par', 'Rappel la veille du retrait');
  await f.dire('Le rappel se règle sur place : <b>allumé</b> ou éteint, par où, à quelle heure.', 4000);
  await f.taire(150); await f.cacher(150);
  await cliquerFixe(f, '#db-par [data-paraller="mails"]', 1400);

  await f.chapitre({ n: 2, total: 4, titre: 'Les envois du magasin', texte: 'Ce que le magasin envoie tout seul, à l’heure dite.' });
  await f.etape('2 · Les envois');
  await f.montrer('.au-tuiles', 'En un coup d’œil');
  await f.dire('En tête : le rappel <b>allumé</b>, les commandes de demain, le canal, et l’horloge qui passe <b>toutes les 10 minutes</b>.', 4800);
  await f.montrer('.au-liste', 'Les automatisations');
  await f.dire('Le <b>rappel la veille du retrait</b>, pour les clients ; la <b>fiche d’une promotion</b>, pour l’équipe.', 5000);
  await f.montrerTout('.au-liste tr.gris', 'À venir');
  await f.dire('En gris, ce qui arrive : le dossier comptable du mois, « Commande prête ».', 4200);
  await f.dire('Tout se règle <b>boîte ouverte</b>, avec son mot de passe : personne d’autre ne change ce qui part aux clients.', 5000);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Régler le rappel de la veille', texte: 'Un e-mail, un SMS, ou les deux, la veille du retrait.' });
  await f.etape('3 · Le rappel');
  const quand = '.au-g2 .au-col:first-child > .db-card:nth-child(1)', parOu = '.au-g2 .au-col:first-child > .db-card:nth-child(2)';
  await f.montrer(quand, 'Quand');
  await f.dire('La veille du retrait, à l’heure choisie : entre 12 h et 21 h.', 3600);
  await f.cacher(150);
  await f.survoler('[data-au-f="heure"]');
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator('[data-au-f="heure"]').selectOption('17:30');
  await f.pause(500);
  await f.dire('On passe à <b>17 h 30</b>. Une commande passée moins de 24 h avant le retrait n’a pas de rappel : la confirmation suffit.', 5200);
  await f.montrer(parOu, 'Par où');
  await f.dire('<b>Par où</b> : e-mail, SMS, ou les deux. Un client sans e-mail reçoit le SMS seul, et l’inverse.', 4800);
  await f.montrer('.au-vars', 'Les variables');
  await f.dire('Le texte de l’e-mail et du SMS, avec ses <b>variables</b> : le prénom, le jour, l’heure, ce qui reste à payer…', 4800);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-au="enregistrer"]', { apres: 1200 });
  await f.montrer('.au-flash', 'Enregistré');
  await f.dire('<b>Enregistrer</b> : le rappel partira désormais à 17 h 30.', 3600);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-au-tel]', { apres: 300 });
  await f.taper('0470 12 34 56', 60);
  await f.cliquer('[data-au="essai"]', { apres: 1200 });
  await f.montrer('.au-flash', 'L’essai');
  await f.dire('<b>Envoyer un essai</b> : l’e-mail part à la boîte du magasin, le SMS au GSM donné.', 4600);
  const demain = '.au-g2 .au-col:nth-child(2) > .db-card:nth-child(2)';
  await f.montrer(demain, 'Demain');
  await f.dire('<b>Demain</b>, vendredi 20 novembre : chaque commande, son canal, son état.', 4200);
  await f.defiler(220, demain + ' .au-defile');
  await f.dire('Sans e-mail ni téléphone, pas de rappel ; une commande trop récente non plus.', 4400);
  await f.montrer('.au-g2 .au-col:nth-child(2) > .db-card:nth-child(3)', 'Ce qui est parti');
  await f.dire('<b>Ce qui est parti</b> : les 30 derniers rappels. Chacun ne part qu’une fois.', 4200);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'L’équipe qui reçoit les fiches', texte: 'Qui reçoit la fiche d’une promotion lancée.' });
  await f.etape('4 · L’équipe');
  await f.montrer('.au-eq', 'L’équipe');
  await f.dire('Une promotion lancée avec le <b>Coach Vente</b> : sa fiche PDF part à ces adresses. Ni marge ni coefficient.', 4800);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-au-eq="nom"]', { apres: 300 });
  await f.taper('Sarah K.', 70);
  await f.cliquer('[data-au-eq="adresse"]', { apres: 300 });
  await f.taper('sarah.k@exemple.be', 50);
  await f.cliquer('[data-au="eq-ajouter"]', { apres: 1300 });
  await f.montrer('.au-eq', 'L’équipe');
  await f.dire('<b>Ajouter</b> : Sarah reçoit la prochaine fiche. « Retirer » l’enlève.', 4000);
  await f.taire(150); await f.cacher();

  return f.fin({
    titre: 'À vous de jouer', accroche: 'Le magasin prévient, vous servez',
    retenir: ['La roue, en bas du rail : les paramètres', 'Le rappel part la veille, à l’heure choisie', 'L’essai : l’e-mail à la boîte, le SMS au GSM', 'L’équipe reçoit les fiches des promotions'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
