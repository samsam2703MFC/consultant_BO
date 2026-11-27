/* Vidéo 18 — « Fidélité » (mode d'emploi de Marketing › Fidélité, vue=fidelite). Environ 2 min 30.
 * panel/public/dashboard/dashboard.js (rendFidelite, rendFideliteClient, rendCartes) : la part des tickets scannés, les
 * profils des clients et leurs radars, les clients qui reviennent et la fiche d'un client, les cartes de fidélité.
 * Données fictives : mocks/18_fidelite.js. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('18-fidelite', { vue: 'fidelite', serie: 'Mode d’emploi', titreSerie: 'Fidélité', duree: 'env. 2 min 30' });
  await f.couverture({
    kicker: 'Mode d’emploi · Marketing', titre: 'La fidélité<br>de mes clients', accroche: 'Qui revient, à quel rythme, et pour quoi',
    programme: ['Les tickets scannés', 'Les profils des clients', 'La fiche d’un client', 'Les cartes de fidélité'],
  });

  /* 1 · Les tickets scannés et les profils. */
  await f.chapitre({ n: 1, total: 3, titre: 'Les profils des clients', texte: 'Les clients reconnus en caisse, rangés selon le rythme de leurs passages.', ms: 3400 });
  await f.etape('1 · Les profils');
  await f.montrer('.fd-scan', 'Les tickets scannés');
  await f.dire('D’abord, la part des <b>tickets d’aujourd’hui</b> qui ont un client scanné ; à droite, le mois et l’année.', 5000);
  await f.dire('Trois paliers : sous 20 %, <b>faible</b> ; de 20 à 50 %, moyenne ; 50 % et plus, <b>bonne</b>.', 4600);
  await f.montrer('.fd-tuiles', 'Six profils');
  await f.dire('Les clients reconnus en caisse, rangés par <b>profil</b> : du moins fidèle, à gauche, au plus fidèle.', 4800);
  await f.montrer('.fd-tui[data-fdp="3"]', 'Hebdo');
  await f.dire('Chaque profil : sa part des clients, sa <b>condition</b> — ici une fois par semaine —, sa part du chiffre, son panier, la part en commande.', 6400);
  await f.cacher(150);
  await f.cliquer('.fd-tui[data-fdp="3"]', { apres: 1200 });
  await f.montrer('.fd-g3', 'Hebdo face à la moyenne');
  await f.dire('Un clic sur un profil : sa <b>récurrence</b> et son <b>type d’achat</b> face à la moyenne, et l’évolution du nombre de clients.', 5600);
  await f.cacher(150);
  await f.cliquer('[data-fdt="180"]', { apres: 1400 });
  await f.dire('L’évolution se lit sur <b>30, 90 ou 180 jours</b>.', 3200);

  /* 2 · Les clients qui reviennent, la fiche d'un client. */
  await f.chapitre({ n: 2, total: 3, titre: 'La fiche d’un client', texte: 'Les clients qui reviennent, puis l’historique de l’un d’eux.', ms: 3400 });
  await f.etape('2 · Un client');
  await f.cliquer('[data-fdliste]', { apres: 1000 });
  await f.montrer('.fd-dd', 'Les clients qui reviennent');
  await f.dire('<b>Les clients qui reviennent</b> : leur profil, leur rythme, leur dernier passage, leur panier, ce qu’ils achètent surtout.', 5600);
  await f.cacher(150);
  await f.cliquer('[data-fdq]', { apres: 300 });
  await f.taper('Pierre', 90);
  await f.pause(700);
  await f.cliquer('tr[data-fdtexte^="pierre"]', { apres: 1600 });
  await f.montrer('.fd-tete', 'Sa fiche');
  await f.dire('Sa fiche : son <b>profil</b>, ses passages sur 180 jours, ce qu’il dépense, la part en commande.', 4600);
  await f.montrer('.fd-can', 'Comment il achète');
  await f.dire('<b>Comment il achète</b> : au comptoir, en commande, sur le webshop.', 3600);
  await f.montrer('.fdc-cc', 'Sa carte');
  await f.dire('Sa <b>carte de fidélité</b>, lue dans l’ERP : ses timbres, et ce qu’il y a à faire en caisse.', 4600);
  await f.montrer('.fd-g3', 'Ses habitudes');
  await f.dire('Son profil mois par mois, ses passages semaine par semaine, ses jours, ses moments et ce qu’il prend.', 5000);
  await f.cacher(150);
  await f.cliquer('[data-fdretour]', { apres: 1400 });

  /* 3 · Les cartes de fidélité. */
  await f.chapitre({ n: 3, total: 3, titre: 'Les cartes de fidélité', texte: 'Du client reconnu à la récompense, carte par carte et client par client.', ms: 3400 });
  await f.etape('3 · Les cartes');
  await f.cliquer('[data-fdong="c"]', { apres: 1400 });
  await f.montrer('.fdc-fun', 'Le résumé');
  await f.dire('L’onglet <b>Cartes de fidélité</b> : les inscrits, sur les clients reconnus en caisse ; ceux qui gagnent ; et, à corriger, les <b>récompenses prêtes</b>.', 5800);
  await f.montrer('.fdc-row[data-fdcarte="1"]', 'Une carte');
  await f.dire('Chaque carte : où en sont ses clients, timbre par timbre ; les timbres gagnés ici ; les récompenses prêtes.', 5000);
  await f.cacher(150);
  await f.cliquer('.fdc-row[data-fdcarte="1"]', { apres: 1400 });
  await f.montrer('.fdc-det', 'Le détail de la carte');
  await f.dire('Un clic : la carte telle que l’ERP la définit — ce qui fait gagner un timbre, la récompense — et ses clients.', 5400);
  await f.cacher(150);
  await f.cliquer('.fdc-row[data-fdcarte="1"]', { apres: 900 });
  await f.cliquer('[data-fdcf="pret"]', { apres: 1000 });
  await f.montrer('.fdc-g2', 'Par client · le réseau');
  await f.dire('Par client : les <b>récompenses prêtes</b> à offrir en caisse. À côté, le réseau sur la même période.', 5200);
  await f.cacher(150);
  return f.fin({
    titre: 'À vous<br>de fidéliser', accroche: 'Chaque client compte',
    retenir: ['La part des tickets scannés : faible, moyenne, bonne', 'Six profils, du rythme des passages', 'La fiche d’un client : ses habitudes', 'Les récompenses prêtes, à offrir en caisse'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
