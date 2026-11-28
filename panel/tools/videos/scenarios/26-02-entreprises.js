/* Clients › l'onglet « Entreprises B2B » (?vue=clients), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    clients-entreprises          les entreprises à compléter, les filtres (score, CA 12 mois, commandes), la
 *                                          recherche, la fiche : crédit, bureaux, dernières commandes, factures
 *   fonction  clients-ajouter-entreprise   « Ajouter une entreprise » par sa TVA, VIES complète (action creer)
 *   fonction  clients-donnees-base         « Données de base » : une entreprise à compléter (action modifier)
 *   fonction  clients-profil-commercial    « Profil commercial » : score, type, mode, crédit (action profil)
 * Données fictives : mocks/26_clients.js (14 entreprises inventées à Val-Fleuri et Bois-Joli). Le compte TF Buddy du
 * magasin est connecté avant de filmer : la liste est celle du panel (score, crédit). Chaque phrase vient de
 * panel/public/dashboard/clients.js (vueEntreprises, ficheEntreprise, fenCourante) ou de panel/src/clients_panel.php
 * (pclManques, pclCredit, wr_clients_client, wr_clients_tva). */
const { Film } = require('../film');
const { ecrire, choisir, fonction, connecterTfb, recharger } = require('./commandes');

module.exports = async function () {
  const f = await Film.ouvrir('26-02-entreprises', {
    vue: 'clients', serie: 'Clients', parent: 'clients',
    sources: ['panel/public/dashboard/clients.js', 'panel/src/clients_panel.php'],
    avant: async p => { await connecterTfb(p); await recharger(p); },
  });

  /* L'onglet */
  await f.onglet({
    id: 'clients-entreprises', titre: 'Les entreprises B2B',
    resume: 'Les entreprises du magasin : celles à compléter, les filtres par score, chiffre et commandes, la fiche avec le crédit, les bureaux et les factures.',
    accroche: 'Vos clients pro, et leur crédit',
    programme: ['Ajouter une entreprise', 'Compléter les données de base', 'Régler le profil commercial'],
  });
  await f.montrer('.pcl-tabs', 'Entreprises B2B');
  await f.dire('« <b>Entreprises B2B</b> » : l’onglet qui s’ouvre d’abord, avec le profil commercial que donne le compte du magasin.', 5400);
  await f.montrer('.pcl-res', 'À compléter');
  await f.dire('En tête, « À corriger » : <b>2 entreprises</b> aux données incomplètes. Leurs factures et leurs offres ne peuvent pas partir.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-res [data-act="e-incompl"]', { apres: 1000 });
  await f.montrer('.pcl-carte', 'Les afficher');
  await f.dire('« <b>N’afficher que les 2 incomplètes</b> » ne garde qu’elles ; le repère <b>à compléter</b> dit ce qui manque.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-res [data-act="e-incompl"]', { apres: 900 });
  await f.cliquer('[data-act="e-score"][data-v="A"]', { apres: 900 });
  await f.montrer('.pcl-filtres', 'Les filtres');
  await f.dire('Les filtres : le <b>score</b> A à D, le chiffre sur 12 mois, le nombre de commandes ; et la recherche par société, TVA ou ville.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-act="e-score"][data-v=""]', { apres: 900 });
  await f.montrer('.pcl-table thead', 'Une ligne');
  await f.dire('Chaque ligne : le score, le type et le mode, les commandes et le CA des 12 mois, le <b>crédit</b> utilisé.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-ligne[data-id="7216"] td:first-child', { apres: 1300 });
  await f.montrer('.pcl-fiche .pcl-bloc:has(.pcl-credit)', 'Crédit dépassé');
  await f.dire('La fiche : ici, des factures impayées au-delà de la limite — le crédit est <b>dépassé</b>.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.defilerVers(f.p.locator('.pcl-fiche .pcl-acts'));
  await f.montrer('.pcl-fiche', 'Bureaux, commandes, factures');
  await f.dire('Puis ses bureaux, ses dernières commandes et ses <b>factures</b>, payées ou dues avec leur échéance.', 5000);
  await f.cacher(300); await f.taire(200);
  await f.defiler(-3000);

  /* Ajouter une entreprise */
  await fonction(f, { id: 'clients-ajouter-entreprise', titre: 'Ajouter une entreprise',
    resume: 'Par son numéro de TVA : TF Buddy complète la société depuis VIES ; une entreprise déjà cliente d’un autre magasin est rattachée à celui-ci.',
    points: ['« Ajouter une entreprise »', 'La TVA : « Vérifier »', 'Le téléphone, l’e-mail', '« Ajouter »'] });
  await f.cliquer('.pcl-tete [data-act="e-ajouter"]', { apres: 900 });
  await ecrire(f, '#pcl-c-m-tax_number', 'BE0000.999.000', { delai: 60 });
  await f.cliquer('.pcl-fen [data-act="tva"]', { apres: 1300 });
  await f.montrer('.pcl-fen .confirm__box', 'Trouvée');
  await f.dire('<b>Vérifier</b> : TF Buddy, puis VIES, remplit la société et son adresse.', 4000);
  await f.cacher(150); await f.taire(150);
  await ecrire(f, '#pcl-c-m-phone', '+32 400 27 27 00', { delai: 45 });
  await ecrire(f, '#pcl-c-m-email', 'bonjour@ateliersduval.exemple.be', { delai: 35 });
  await f.cliquer('.pcl-fen [data-act="e-ajouter-ok"]', { apres: 2000 });
  await f.montrer('.pcl-fiche', 'Ajoutée');
  await f.dire('L’entreprise est dans TF Buddy, rattachée au magasin : sa fiche s’ouvre.', 4000);
  await f.cacher(300); await f.taire(200);
  await f.defiler(-3000);

  /* Données de base */
  await fonction(f, { id: 'clients-donnees-base', titre: 'Compléter les données de base',
    resume: 'Société, TVA, identifiant Peppol, adresse, téléphone, e-mail : ce qui manque à une entreprise pour que ses factures partent.',
    points: ['Une entreprise « à compléter »', '« Données de base »', '« Enregistrer »'] });
  await f.cliquer('.pcl-res [data-act="e-incompl"]', { apres: 900 });
  await f.cliquer('.pcl-ligne[data-id="7231"] td:first-child', { apres: 1200 });
  await f.montrer('.pcl-fiche .pcl-ft', 'Manque la TVA');
  await f.dire('Le traiteur n’a pas de <b>TVA</b> : sa fiche le dit.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-fiche [data-act="e-base"]', { apres: 900 });
  await ecrire(f, '#pcl-c-m-tax_number', 'BE0000.565.656', { delai: 60 });
  await ecrire(f, '#pcl-c-m-email', 'commandes@saveursduval.exemple.be', { delai: 35 });
  await f.cliquer('.pcl-fen [data-act="e-base-ok"]', { apres: 2000 });
  await f.montrer('.pcl-res', 'Une de moins');
  await f.dire('<b>Enregistrer</b> : la fiche est complète dans TF Buddy, il ne reste qu’une entreprise à compléter.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.pcl-res [data-act="e-incompl"]', { apres: 600 });
  await f.defiler(-3000);

  /* Profil commercial */
  await fonction(f, { id: 'clients-profil-commercial', titre: 'Régler le profil commercial',
    resume: 'Le score, le type de client, le mode de service et le crédit accordé d’une entreprise, écrits dans TF Buddy.',
    points: ['Ouvrir l’entreprise', '« Profil commercial »', 'Score, type, mode, crédit', '« Enregistrer »'] });
  await f.cliquer('.pcl-ligne[data-id="7216"] td:first-child', { apres: 1200 });
  await f.cliquer('.pcl-fiche [data-act="e-profil"]', { apres: 900 });
  await f.montrer('.pcl-fen .confirm__box', 'Profil commercial');
  await f.dire('Le <b>score</b>, le type de client, le mode de service (livraison, magasin, les deux), et le <b>crédit</b> accordé.', 5400);
  await f.cacher(150); await f.taire(150);
  await choisir(f, '#pcl-c-m-score', 'B');
  await ecrire(f, '#pcl-c-m-creditLimite', '800');
  await f.cliquer('.pcl-fen [data-act="e-profil-ok"]', { apres: 2000 });
  await f.montrer('.pcl-fiche .pcl-bloc:has(.pcl-credit)', '520 € sur 800 €');
  await f.dire('Score B, limite de 800 € : le crédit utilisé retombe à <b>65 %</b>.', 4200);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
