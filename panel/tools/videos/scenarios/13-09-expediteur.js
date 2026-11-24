/* Offres › Newsletter › Expéditeur (?vue=offres, onglet « Newsletter »), et sa fonction en chapitre (24/11/2026) :
 *   onglet    offres-nl-expediteur           le nom et l'adresse d'expédition, le domaine, l'envoi, les droits
 *   fonction  offres-nl-changer-expediteur   changer le nom et l'adresse, sortir du champ : c'est enregistré
 * Données fictives : mocks/13_offres.js (magasin.demo@exemple.be, domaine exemple.be). Chaque phrase vient de
 * marketing/public/assets/js/newsletter.js (vSettings, I18N fr, putShop) ou de marketing/src/newsletter.php
 * (wr_newsletter_magasin_put : le statut suit l'adresse). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-09-expediteur', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const ligne = '.nl-shop:not(.h)';

  /* L'onglet */
  await f.onglet({
    id: 'offres-nl-expediteur', titre: 'Newsletter : l’expéditeur',
    resume: 'Le nom et l’adresse d’où partent les campagnes du magasin, l’état du domaine, l’envoi autorisé, et les droits de la marque et du franchisé.',
    accroche: 'De qui vient le mail',
    programme: ['Changer l’adresse d’expéditeur'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.cliquer('.nl-top [data-a="view"][data-v="settings"]', { apres: 900 });
  await f.montrer('.nl-body > div:first-child', 'Expéditeur');
  await f.dire('« <b>Expéditeur</b> » : chaque magasin envoie depuis sa propre adresse. La marque crée les segments et les modèles.', 5800);
  await f.montrer(ligne + ' > div:nth-child(2)', 'Nom · adresse');
  await f.dire('La ligne du magasin : le <b>nom d’expéditeur</b> et l’<b>adresse</b> que les clients voient.', 4600);
  await f.montrer(ligne + ' > div:nth-child(3)', 'Domaine');
  await f.dire('« Domaine vérifié » : l’adresse est sur le domaine de la marque.', 3800);
  await f.montrer(ligne + ' > div:nth-child(4)', 'Envoi');
  await f.dire('L’envoi est <b>autorisé</b> par la marque. Bloqué, le magasin garde l’accès en lecture.', 4600);
  await f.montrer(ligne, 'La ligne du magasin');
  await f.dire('Le magasin change ici son nom et son adresse ; l’état du domaine et l’envoi, c’est la marque qui les règle.', 5600);
  await f.montrer('.nl-set > div:last-child > div:first-child', 'Les droits');
  await f.dire('Les <b>droits</b> : la marque fait tout ; le franchisé envoie ses campagnes, change son expéditeur, voit ses chiffres.', 6000);
  await f.dire('Segments, modèles et automatisations restent l’affaire de la marque.', 3800);
  await f.cacher(250); await f.taire(150);
  await f.pause(500);

  /* Changer l'adresse d'expéditeur */
  await f.fonction({ id: 'offres-nl-changer-expediteur', titre: 'Changer l’adresse d’expéditeur',
    resume: 'Le nom ou l’adresse d’expéditeur du magasin, sur sa ligne : on le change, on sort du champ, c’est enregistré.',
    points: ['« Expéditeur »', 'Changer le nom ou l’adresse', 'Sortir du champ'] });
  await f.cliquer('.nl-top [data-a="view"][data-v="settings"]', { apres: 700 });
  await f.cliquer('[data-f="shopname-4"]', { apres: 200 });
  await f.p.keyboard.press('Control+A');
  await f.taper('L’Atelier By · Val-Fleuri', 60);
  await f.p.keyboard.press('Tab'); await f.pause(900);
  await f.p.keyboard.press('Control+A');
  await f.taper('bonjour.valfleuri@exemple.be', 55);
  await f.p.keyboard.press('Tab'); await f.pause(1000);
  await f.montrer(ligne, 'Enregistré');
  await f.dire('En sortant du champ, c’est <b>enregistré</b>. Sur le domaine de la marque, le domaine reste vérifié.', 5200);
  await f.dire('Une adresse sur un autre domaine passerait « Non vérifié ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="wizard"]', { apres: 1100 });
  await f.montrer('.nl-sticky > div:nth-child(2) > div:first-child', 'L’expéditeur');
  await f.dire('Les prochaines campagnes partent sous ce nom et depuis cette adresse.', 4000);
  await f.taire(150);
  await f.pause(1200);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
