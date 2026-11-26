/* Clients › l'onglet « Analyse B2B » (?vue=clients), sans fonction en chapitre (26/11/2026) : l'onglet ne fait que lire.
 *   onglet    clients-analyse    un mois, six chiffres face au mois d'avant et au même mois l'an dernier ; l'année mois
 *                                par mois (ventes, tickets, clients, part du CA) ; la part B2B du chiffre ; le mois d'avant
 * Données fictives : mocks/26_clients.js. Le compte TF Buddy du magasin est connecté avant de filmer (le tableau de bord
 * B2B de TF Buddy) ; sans lui, l'onglet lit le relevé des tickets pro de la caisse et le dit. Chaque phrase vient de
 * panel/public/dashboard/clients.js (vueAnalyse) ou de panel/src/clients_panel.php (ep_clients_analyse). */
const { Film } = require('../film');
const { connecterTfb, recharger } = require('./commandes');

module.exports = async function () {
  const f = await Film.ouvrir('26-04-analyse', {
    vue: 'clients', serie: 'Clients', parent: 'clients',
    sources: ['panel/public/dashboard/clients.js', 'panel/src/clients_panel.php'],
    avant: async p => { await connecterTfb(p); await p.evaluate(() => localStorage.setItem('pcl-onglet:4', 'an')); await recharger(p); },
  });

  await f.onglet({
    id: 'clients-analyse', titre: 'L’analyse B2B',
    resume: 'Un mois de ventes aux clients pro : six chiffres face au mois d’avant et à l’an dernier, l’année mois par mois, la part B2B du chiffre.',
    accroche: 'Vos clients pro, en chiffres',
  });
  await f.montrer('.pcl-barre', 'Le mois');
  await f.dire('« <b>Analyse B2B</b> » s’ouvre sur le mois en cours, jusqu’à aujourd’hui.', 3800);
  await f.montrer('.pcl-kpi6', 'Six chiffres');
  await f.dire('Six chiffres : les <b>ventes B2B</b>, les tickets, le panier moyen, les clients uniques, la part B2B du CA, les factures.', 5800);
  await f.montrer('.pcl-kpi6 .pcl-k6:first-child', 'Face à…');
  await f.dire('Chacun face au <b>mois d’avant</b> et au <b>même mois l’an dernier</b>, en flèche et en pourcentage ; la part, en points.', 5800);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.pcl-an2 > section:first-child', 'Mois par mois');
  await f.dire('L’année <b>mois par mois</b>, face à l’an dernier.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-act="a-metrique"][data-v="tickets"]', { apres: 1100 });
  await f.cliquer('[data-act="a-metrique"][data-v="part"]', { apres: 1100 });
  await f.montrer('.pcl-an2 > section:first-child', 'Ventes, tickets, clients, part');
  await f.dire('Le graphique change de mesure : ventes, tickets, clients, ou <b>part du CA</b>.', 4200);
  await f.montrer('.pcl-an2 > section:last-child', 'Part B2B du chiffre');
  await f.dire('À côté, la <b>part B2B</b> du chiffre du mois. Un pro, c’est une fiche avec une TVA valide.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-barre [data-act="a-pas"][data-v="-1"]', { apres: 1500 });
  await f.montrer('.pcl-kpi6', 'Octobre 2026');
  await f.dire('Les flèches changent de mois : <b>octobre</b>, un mois clos.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.pcl-tabs .pcl-tfb', 'Le compte du magasin');
  await f.dire('Ces chiffres viennent du tableau de bord B2B de TF Buddy, par le compte du magasin. Sans lui, l’onglet les lit dans les tickets de la caisse, et le dit.', 7200);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
