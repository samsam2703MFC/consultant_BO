/* Vidéo 26 — « Les clients du magasin » (mode d'emploi de Vendre › Clients, ?vue=clients). Environ 2 min 50.
 * Données fictives : mocks/26_clients.js. Le compte TF Buddy du magasin est connecté avant de filmer (sa session gardée
 * dans le navigateur, comme tfb-magasin.js la garde) : les quatre onglets se lisent en entier. Chaque phrase vient de
 * panel/public/dashboard/clients.js, de panel/src/clients_panel.php ou de panel/src/bureaux_dev.php.
 * Contournement : ouvrir l'onglet Bureaux après Entreprises B2B ne lit pas les bureaux en développement (clients.js,
 * geste « onglet ») ; le module est relu aussitôt (DashClients.relire), comme « relire » le ferait. */
const { Film } = require('../film');
const { connecterTfb, recharger } = require('./commandes');

module.exports = async function () {
  const f = await Film.ouvrir('26-clients', { vue: 'clients', serie: 'Mode d’emploi', titreSerie: 'Les clients du magasin', duree: 'env. 2 min 50',
    avant: async p => { await connecterTfb(p); await recharger(p); } });
  await f.couverture({
    kicker: 'Mode d’emploi · Vendre', titre: 'Les clients<br>du magasin', accroche: 'Vos clients, tels que TF Buddy les connaît',
    programme: ['Quatre onglets, un compte', 'Les particuliers', 'Les entreprises B2B', 'Les bureaux', 'L’analyse B2B'],
  });

  await f.chapitre({ n: 1, total: 5, titre: 'Quatre onglets, un compte', texte: 'Le module « Clients » du panel TF Buddy, dans le dashboard : tout s’y lit et s’y écrit.' });
  await f.etape('1 · Les onglets');
  await f.montrer('#db-rail .sidebar__item[data-vue=clients]', 'Clients');
  await f.dire('<b>Clients</b>, dans Vendre : quatre onglets, les particuliers, les entreprises B2B, les bureaux et l’analyse B2B.', 5200);
  await f.montrer('.pcl-tabs', 'Les onglets');
  await f.dire('Le module s’ouvre sur les <b>entreprises B2B</b>, puis sur le dernier onglet choisi.', 4000);
  await f.montrer('.pcl-tfb', 'Le compte du magasin');
  await f.dire('Le <b>compte TF Buddy du magasin</b> est connecté sur cet appareil : il ouvre les bureaux et le profil commercial des entreprises.', 6000);
  await f.dire('Sans lui, le module lit ce qu’il peut avec les comptes du cockpit, et le dit à l’écran.', 4400);
  await f.cacher(150); await f.taire(150);

  await f.chapitre({ n: 2, total: 5, titre: 'Les particuliers', texte: 'La recherche d’abord ; la fiche à côté, avec ses gestes.' });
  await f.etape('2 · Les particuliers');
  await f.cliquer('.pcl-tabs [data-act="onglet"][data-v="b2c"]', { apres: 1200 });
  await f.montrer('.pcl-carte', 'Les particuliers');
  await f.dire('Les derniers créés d’abord, le téléphone <b>en partie</b> dans la liste.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#pcl-pq', { apres: 200 });
  await f.taper('renard', 110);
  await f.pause(1100);
  await f.dire('On cherche par le <b>nom</b>, le <b>téléphone</b> ou l’<b>e-mail</b> : ici, deux fiches au même nom.', 4400);
  await f.cliquer('.pcl-ligne[data-id="8391"]', { apres: 1000 });
  await f.montrer('.pcl-fiche', 'La fiche');
  await f.dire('La fiche : la remise, le paiement différé, les coordonnées en entier.', 4000);
  await f.montrer('.pcl-fiche .pcl-acts', 'Les gestes');
  await f.dire('Ses gestes : <b>préparer une offre</b>, modifier, passer en entreprise, supprimer un client né dans ce magasin.', 5400);
  await f.cacher(150); await f.taire(150);

  await f.chapitre({ n: 3, total: 5, titre: 'Les entreprises B2B', texte: 'Celles à compléter en tête, les filtres, la fiche : le crédit, les bureaux, les factures.' });
  await f.etape('3 · Les entreprises');
  await f.cliquer('.pcl-tabs [data-act="onglet"][data-v="b2b"]', { apres: 1200 });
  await f.montrer('.pcl-res', 'À compléter');
  await f.dire('En tête, « À corriger » : les entreprises aux <b>données incomplètes</b> — TVA, adresse, ou ni e-mail ni téléphone. Leurs factures ne peuvent pas partir.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.pcl-filtres', 'Les filtres');
  await f.dire('Les filtres : le <b>score</b>, le chiffre sur 12 mois, le nombre de commandes.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-ligne[data-id="7201"] td:first-child', { apres: 1200 });
  await f.montrer('.pcl-fiche .pcl-kpis', 'La fiche');
  await f.dire('La fiche : commandes et CA sur 12 mois, le <b>cycle d’achat</b>, le type, le mode, le crédit utilisé.', 5000);
  await f.montrer('.pcl-fiche .pcl-bloc:has(.pcl-credit)', 'Le crédit');
  await f.dire('Le <b>crédit</b> : les factures impayées face à la limite accordée.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.defilerVers(f.p.locator('.pcl-fiche .pcl-acts'));
  await f.montrer('.pcl-fiche', 'Bureaux, commandes, factures');
  await f.dire('Puis ses bureaux, ses dernières commandes, ses <b>factures</b> payées ou dues.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);

  await f.chapitre({ n: 4, total: 5, titre: 'Les bureaux', texte: 'Les bureaux livrés par une tournée, et ceux en développement.' });
  await f.etape('4 · Les bureaux');
  await f.cliquer('.pcl-tabs [data-act="onglet"][data-v="bur"]', { apres: 300 });
  await f.p.evaluate(() => window.DashClients.relire());
  await f.pause(1200);
  await f.montrer('.pcl-dev', 'En développement');
  await f.dire('Les <b>bureaux en développement</b> : une personne et son futur bureau, reçus par le questionnaire du magasin ou saisis ici.', 6000);
  await f.dire('La personne, la société et le bon de bienvenue sont déjà dans TF Buddy ; le bureau attend votre <b>validation</b>.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.defilerVers(f.p.locator('.pcl-carte:not(.pcl-dev)'));
  await f.montrer('.pcl-carte:not(.pcl-dev)', 'Les bureaux');
  await f.dire('Puis une ligne par bureau : statut, catalogue, offres, départements, sites.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-ligne[data-id="951"]', { apres: 1400 });
  await f.dire('Un bureau s’ouvre dans un <b>tiroir</b> : livraison et tournée, sites, catalogue, offres, personnes, invitations et documents.', 6000);
  await f.cliquer('.pcl-tir-nav [data-act="b-pas"][data-v="1"]', { apres: 1200 });
  await f.dire('Les flèches passent au bureau suivant ; Échap ferme le tiroir.', 3400);
  await f.taire(150);
  await f.cliquer('.pcl-tir [data-act="b-fermer"]', { apres: 700 });
  await f.defiler(-3000);

  await f.chapitre({ n: 5, total: 5, titre: 'L’analyse B2B', texte: 'Un mois : ses ventes B2B face au mois d’avant et à l’an dernier, puis l’année.' });
  await f.etape('5 · L’analyse');
  await f.cliquer('.pcl-tabs [data-act="onglet"][data-v="an"]', { apres: 1300 });
  await f.montrer('.pcl-res', 'Le résumé');
  await f.dire('Les <b>ventes B2B</b> du mois ; à côté, tickets, panier, clients uniques, factures — face au mois d’avant et à l’an dernier.', 6200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-act="a-metrique"][data-v="clients"]', { apres: 900 });
  await f.montrer('.pcl-an2 > section:first-child', 'Mois par mois');
  await f.dire('L’année mois par mois, face à l’an dernier : ventes, tickets, clients ou part du CA.', 4600);
  await f.cacher();

  return f.fin({
    titre: 'À vous de jouer', accroche: 'Chaque client, à sa place',
    retenir: ['Particuliers : la recherche, puis la fiche', 'Entreprises : à compléter, crédit, factures', 'Bureaux : à valider, puis livrés', 'Le compte TF Buddy du magasin ouvre tout'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
