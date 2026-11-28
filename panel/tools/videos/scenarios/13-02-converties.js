/* Offres › l'onglet « Converties » (?vue=offres), 24/11/2026 :
 *   onglet    offres-converties    les offres devenues commandes, chercher, une offre et ses gestes grisés, sa marge
 * Aucune fonction en chapitre : « Archiver » (le seul geste qui reste) est filmé dans 13-01-en-cours.
 * Données fictives : mocks/13_offres.js (trois offres converties : n° 415, 414, 412). Chaque phrase vient de
 * panel/public/dashboard/offres.js (kpis, detail : les boutons grisés et leurs titres). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-02-converties', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'panel/src/offres_client.php'],
  });

  await f.onglet({
    id: 'offres-converties', titre: 'Les offres converties',
    resume: 'Les offres devenues commandes dans TF Buddy : les chiffres, la recherche, une offre convertie et ce qui ne se fait plus.',
    accroche: 'Le devis est devenu commande',
    programme: ['L’onglet « Converties »', 'Retrouver une offre', 'Ce qui reste possible'],
  });
  await f.cliquer('[data-ofong="conv"]', { apres: 900 });
  await f.montrer('[data-ofong="conv"]', 'Converties');
  await f.dire('« <b>Converties</b> » : les offres que le client a acceptées, devenues commandes dans TF Buddy.', 4600);
  await f.montrer('.of-res-sep', 'Converties en commande');
  await f.dire('En tête, à droite : leur nombre et leur montant TTC, dans la liste de TF Buddy.', 4200);
  await f.montrer('.of-res-sep span', 'Taux de conversion');
  await f.dire('Le <b>taux de conversion</b> : les offres converties, sur toutes les offres faites.', 4200);
  await f.montrer('.of-g', 'La liste');
  await f.dire('Chaque ligne : le numéro, l’offre et son client, le jour de l’événement, retrait ou livraison, le total TTC.', 5600);
  await f.cacher(250); await f.taire(150);

  await f.cliquer('#of-q', { apres: 250 });
  await f.taper('Horizon', 110);
  await f.pause(700);
  await f.montrer('.of-g', 'Chercher');
  await f.dire('La recherche marche ici aussi : un numéro, un client ou un produit.', 3800);
  await f.cacher(200); await f.taire(150);
  await f.p.locator('#of-q').fill(''); await f.p.locator('#of-q').dispatchEvent('input');
  await f.pause(600);

  await f.cliquer('[data-ofsel="414"]', { apres: 800 });
  await f.montrer('.of-d-h', 'Offre convertie');
  await f.dire('Un clic ouvre l’offre à droite : son état, « <b>convertie</b> », son client et sa date.', 4400);
  await f.montrer('.of-gestes', 'Les gestes');
  await f.dire('« Modifier », « Convertir » et « Supprimer » sont <b>grisés</b> : une offre convertie ne se modifie plus.', 5200);
  await f.montrer('[data-ofgeste="archiver"]', 'Archiver');
  await f.dire('Elle ne se supprime pas non plus : elle <b>s’archive</b>. Le PDF et l’e-mail restent possibles.', 4800);
  await f.cacher(250); await f.taire(150);
  await f.montrer('.of-d .of-marge-k', 'La marge');
  await f.dire('Sa <b>marge</b> reste lisible : le prix HT, le coût matière, la marge brute.', 4200);
  await f.cacher(250);
  await f.montrer('.of-apercu', 'L’aperçu');
  await f.dire('Plus bas, l’aperçu au gabarit A4 ; le document officiel reste le PDF de TF Buddy.', 4400);
  await f.taire(150); await f.cacher(300);
  await f.defiler(-3000);
  await f.pause(1200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
