/* Offres › l'onglet « Toutes » (?vue=offres), 24/11/2026 :
 *   onglet    offres-toutes    les huit offres, tous états confondus ; chercher un produit ; une offre ; relire
 * Aucune fonction en chapitre : l'onglet ne fait que lire (ses gestes sont ceux des autres onglets).
 * Données fictives : mocks/13_offres.js. Chaque phrase vient de panel/public/dashboard/offres.js (visibles, liste,
 * voir(id) : une offre citée dans un e-mail ouvre cet onglet, « relire »). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-04-toutes', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'panel/src/offres_client.php'],
  });

  await f.onglet({
    id: 'offres-toutes', titre: 'Toutes les offres',
    resume: 'Les huit offres du magasin, tous états confondus : chacune avec son badge d’état, la recherche sur tout, « relire ».',
    accroche: 'Tout l’historique au même endroit',
    programme: ['L’onglet « Toutes »', 'Chercher dans tout', 'Ouvrir une offre'],
  });
  await f.cliquer('[data-ofong="tout"]', { apres: 900 });
  await f.montrer('[data-ofong="tout"]', 'Toutes');
  await f.dire('« <b>Toutes</b> » : les huit offres du magasin dans TF Buddy, en cours, converties et archivées.', 5000);
  await f.montrerTout('.of-t tbody td:last-child', 'L’état');
  await f.dire('Chaque ligne porte son <b>état</b> : en cours, convertie ou archivée.', 3800);
  await f.cacher(250); await f.taire(150);

  await f.cliquer('#of-q', { apres: 250 });
  await f.taper('croissant', 110);
  await f.pause(700);
  await f.montrer('.of-g', 'Chercher');
  await f.dire('Un produit dans la recherche : toutes les offres qui l’ont, quel que soit leur état.', 4600);
  await f.cacher(200); await f.taire(150);
  await f.cliquer('[data-ofsel="412"]', { apres: 800 });
  await f.montrer('.of-d-h', 'L’offre n° 412');
  await f.dire('Un clic l’ouvre à droite, avec son état et les gestes qu’il permet : ici, une offre convertie.', 4800);
  await f.montrer('.of-gestes', 'Ses gestes');
  await f.dire('Les gestes suivent son état : une offre convertie ne se modifie plus, elle s’archive.', 4600);
  await f.cacher(250); await f.taire(150);
  await f.p.locator('#of-q').fill(''); await f.p.locator('#of-q').dispatchEvent('input');
  await f.pause(700);

  await f.montrer('.of-g', 'Toutes');
  await f.dire('Une offre citée dans un e-mail de la <b>Boîte mail</b> s’ouvre aussi ici, dans « Toutes ».', 4600);
  await f.montrer('.of-pied', 'Relire');
  await f.dire('En pied de liste : le nombre d’offres dans TF Buddy, l’heure de lecture et « <b>relire</b> ».', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofrelire]', { apres: 1200 });
  await f.montrer('.of-pied', 'Relue');
  await f.dire('« Relire » relit la liste dans TF Buddy, sans quitter l’écran.', 3600);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
