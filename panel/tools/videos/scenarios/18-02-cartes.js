/* Fidélité, l'onglet « Cartes de fidélité » (?vue=fidelite), 26/11/2026 :
 *   onglet    fidelite-cartes   du client reconnu à la récompense : l'entonnoir, la période (ce mois, 30 j, l'année),
 *                               chaque carte et ses timbres, son détail lu dans l'ERP (un clic l'ouvre, un second la
 *                               referme), « Par client » (filtres, recherche), le réseau sur la même période
 * Aucune fonction en chapitre : l'onglet se lit (lectures GET /fidelite/cartes, /fidelite/carte). Données fictives :
 * mocks/18_fidelite.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rendCartes, fdCarteDetail,
 * fdBrancher) ou de marketing/src/fidelite_cartes.php. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('18-02-cartes', {
    vue: 'fidelite', serie: 'Fidélité', parent: 'fidelite',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'marketing/src/fidelite_cartes.php'],
  });

  await f.onglet({
    id: 'fidelite-cartes', titre: 'Les cartes de fidélité',
    resume: 'Du client reconnu à la récompense : l’entonnoir, chaque carte et ses timbres, son détail lu dans l’ERP, les clients inscrits, et le réseau sur la même période.',
    accroche: 'Du client reconnu à la récompense',
    programme: ['L’entonnoir, la période', 'Chaque carte, son détail', 'Par client', 'Le réseau'],
  });
  await f.cliquer('[data-fdong="c"]', { apres: 1500 });
  await f.montrer('.fdc-fun', 'Du client reconnu à la récompense');
  await f.dire('Les cartes, lues en direct dans l’ERP : des clients reconnus en caisse aux inscrits, à ceux qui gagnent, aux <b>récompenses prêtes</b>.', 6600);
  await f.montrer('.db-sec .fd-tg', 'La période');
  await f.dire('La période : <b>ce mois, 30 jours ou l’année</b>.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdper="annee"]', { apres: 1400 });
  await f.montrer('.fdc-fun', 'L’année');
  await f.dire('L’entonnoir, les timbres gagnés ici et le réseau se relisent sur l’année.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdper="mois"]', { apres: 1300 });

  await f.montrer('.fdc-row[data-fdcarte="1"]', 'Une carte');
  await f.dire('Chaque carte : où en sont ses clients, timbre par timbre ; les timbres gagnés ici ; les récompenses prêtes.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fdc-row[data-fdcarte="1"]', { apres: 1500 });
  await f.montrer('.fdc-det-g', 'Le détail de la carte');
  await f.dire('Un clic ouvre son détail, tel que l’ERP la définit : la récompense, ce qui fait gagner un timbre, ce qu’elle donne ici.', 6200);
  await f.montrer('.fdc-siens', 'Ses clients');
  await f.dire('Ses clients, du plus avancé au moins avancé ; un clic ouvre leur fiche.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fdc-row[data-fdcarte="1"]', { apres: 1000 });
  await f.dire('Un second clic referme le détail.', 2400);
  await f.taire(150);

  await f.montrer('.fdc-g2 > div:first-child', 'Par client');
  await f.dire('<b>Par client</b> : chaque inscrit, sa carte et où il en est, ses achats, son dernier achat.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdcf="pret"]', { apres: 900 });
  await f.montrer('.fdc-g2 > div:first-child', 'Récompense prête');
  await f.dire('Le filtre <b>Récompense prête</b> : celles à offrir en caisse. « Inactifs ici » : les inscrits sans achat ici sur la période.', 6200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdcf="tous"]', { apres: 700 });
  await f.cliquer('[data-fdcq]', { apres: 300 });
  await f.taper('Claire', 120);
  await f.pause(500);
  await f.montrer('.fdc-g2 > div:first-child', 'Rechercher un client');
  await f.dire('<b>Rechercher un client</b> : pour vérifier où il en est sur sa carte.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('[data-fdcq]').fill(''); await f.p.locator('[data-fdcq]').dispatchEvent('input');
  await f.pause(300);
  await f.montrer('.fdc-g2 > div:nth-child(2)', 'Le réseau');
  await f.dire('<b>Le réseau</b>, sur la même période : inscrits, actifs, timbres et ventes des inscrits, magasin par magasin.', 5400);
  await f.cacher(300); await f.taire(150);
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
