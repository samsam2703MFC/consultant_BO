/* Fidélité, l'onglet « La fiche d'un client » (?vue=fidelite), 26/11/2026 :
 *   onglet    fidelite-client   un clic sur un client (les clients qui reviennent, « Par client », les clients d'une
 *                               carte) : tout l'écran devient le client — son profil, ses passages, comment il achète,
 *                               sa carte et « À faire en caisse », ses habitudes, ses radars, ses tickets ; « ‹ Retour »
 * Aucune fonction en chapitre : la fiche se lit (GET /fidelite/client, /fidelite/carte-client). Données fictives :
 * mocks/18_fidelite.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (rendFideliteClient, fdCanaux,
 * fdCarteClient, fdBrancher). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('18-03-client', {
    vue: 'fidelite', serie: 'Fidélité', parent: 'fidelite',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'marketing/src/fidelite_profils.php', 'marketing/src/fidelite_cartes.php'],
  });

  await f.onglet({
    id: 'fidelite-client', titre: 'La fiche d’un client',
    resume: 'Un clic sur un client : son profil, ses passages, comment il achète, sa carte et ce qu’il y a à faire en caisse, ses habitudes et ses tickets ; « ‹ Retour » pour revenir.',
    accroche: 'Un client, toute son histoire',
    programme: ['Ouvrir la fiche', 'Sa carte, à faire en caisse', 'Ses habitudes, ses tickets'],
  });
  await f.cliquer('[data-fdliste]', { apres: 900 });
  await f.montrer('tr[data-fdtexte^="sophie"]', 'Un client');
  await f.dire('Dans <b>les clients qui reviennent</b>, un clic sur un client ouvre sa fiche.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('tr[data-fdtexte^="sophie"]', { apres: 1700 });

  await f.montrer('.fd-tete', 'Sa fiche');
  await f.dire('Tout l’écran devient le client : son <b>profil</b>, ses passages sur 180 jours, ce qu’il dépense, la part en commande.', 6000);
  await f.montrer('.fd-can', 'Comment il achète');
  await f.dire('<b>Comment il achète</b> : en commande au comptoir, au comptoir, sur le webshop, en livraison au bureau.', 5200);
  await f.montrer('.fdc-cc', 'Sa carte');
  await f.dire('Sa <b>carte de fidélité</b>, lue dans l’ERP : ses timbres, tous magasins.', 3800);
  await f.montrer('.fdc-faire', 'À faire en caisse');
  await f.dire('<b>À faire en caisse</b> : ici, un café à offrir, et à quatre timbres de la viennoiserie.', 4800);
  await f.cacher(300); await f.taire(150);
  await f.montrer('.fd-g3', 'Ses habitudes');
  await f.dire('Son profil mois par mois, ses passages semaine par semaine, ses jours, ses moments et ce qu’il prend.', 5400);
  await f.montrer('.fd-g3b', 'Ses radars, ses tickets');
  await f.dire('Ses radars face à la moyenne, et ses <b>tickets</b>, du plus récent au plus ancien.', 4400);
  await f.cacher(300); await f.taire(150);
  await f.defiler(-4000);
  await f.cliquer('[data-fdretour]', { apres: 1500 });
  await f.dire('« <b>‹ Retour aux profils</b> » ramène à la page.', 3000);
  await f.taire(150);

  await f.cliquer('[data-fdong="c"]', { apres: 1500 });
  await f.montrer(f.p.locator('tr[data-fdct]').first(), 'Par client');
  await f.dire('La fiche s’ouvre aussi depuis les cartes : « <b>Par client</b> », ou les clients d’une carte.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('tr[data-fdct] .db-lien').first(), { apres: 1700 });
  await f.montrer('.fd-retour', 'Retour aux cartes');
  await f.dire('Le retour ramène alors <b>aux cartes</b>.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdretour]', { apres: 1300 });
  await f.pause(400);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
