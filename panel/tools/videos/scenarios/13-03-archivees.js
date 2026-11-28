/* Offres › l'onglet « Archivées » (?vue=offres), et sa fonction en chapitre (24/11/2026) :
 *   onglet    offres-archivees           les offres mises de côté, une offre archivée et ses gestes
 *   fonction  offres-remettre-en-cours   « Remettre en cours » : l'offre revient dans « En cours »
 * « Supprimer » (possible aussi ici) est filmé dans 13-01-en-cours.
 * Données fictives : mocks/13_offres.js (deux offres archivées : n° 413 et 411). Chaque phrase vient de
 * panel/public/dashboard/offres.js (detail, gestes) ou de panel/src/offres_client.php (action « reactiver »). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-03-archivees', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'panel/src/offres_client.php'],
  });

  /* L'onglet */
  await f.onglet({
    id: 'offres-archivees', titre: 'Les offres archivées',
    resume: 'Les offres mises de côté : elles quittent « En cours » sans disparaître de TF Buddy, et peuvent revenir.',
    accroche: 'Rangées, pas perdues',
    programme: ['Remettre une offre en cours'],
  });
  await f.cliquer('[data-ofong="arch"]', { apres: 900 });
  await f.montrer('[data-ofong="arch"]', 'Archivées');
  await f.dire('« <b>Archivées</b> » : les offres mises de côté. Elles quittent « En cours » sans disparaître de TF Buddy.', 5200);
  await f.montrer('.of-g', 'La liste');
  await f.dire('Chaque offre garde tout : son client, le jour prévu, retrait ou livraison, son total TTC.', 4800);
  await f.cacher(250); await f.taire(150);
  await f.cliquer('#of-q', { apres: 250 });
  await f.taper('Renard', 110);
  await f.pause(700);
  await f.montrer('.of-g', 'Chercher');
  await f.dire('La recherche retrouve une offre archivée : un numéro, un client ou un produit.', 4200);
  await f.cacher(200); await f.taire(150);
  await f.p.locator('#of-q').fill(''); await f.p.locator('#of-q').dispatchEvent('input');
  await f.pause(600);
  await f.cliquer('[data-ofsel="413"]', { apres: 800 });
  await f.montrer('.of-d-h', 'Offre archivée');
  await f.dire('Un clic l’ouvre à droite, avec son état : « <b>archivée</b> ».', 3600);
  await f.montrer('.of-gestes', 'Les gestes');
  await f.dire('« <b>Remettre en cours</b> » prend la place d’« Archiver ». Les autres gestes restent : modifier, le PDF, convertir.', 5800);
  await f.montrer('[data-ofgeste="supprimer"]', 'Supprimer');
  await f.dire('Une offre archivée peut encore être supprimée ; seule une offre convertie ne se supprime pas.', 4800);
  await f.cacher(250); await f.taire(150);
  await f.montrer('.of-d .of-marge-k', 'La marge');
  await f.dire('Sa <b>marge</b> se lit toujours : prix HT, coût matière, marge brute.', 3800);
  await f.cacher(250);
  await f.montrer('.of-apercu', 'L’aperçu');
  await f.dire('Et son aperçu au gabarit A4, tel qu’il était envoyé.', 3400);
  await f.taire(150); await f.cacher(300);
  await f.defiler(-3000);

  /* Remettre en cours */
  await f.fonction({ id: 'offres-remettre-en-cours', titre: 'Remettre une offre en cours',
    resume: 'Le client revient : l’offre archivée repasse « en cours » dans TF Buddy, prête à être envoyée ou convertie.',
    points: ['Ouvrir « Archivées »', 'Choisir l’offre', '« Remettre en cours »'] });
  await f.cliquer('[data-ofong="arch"]', { apres: 700 });
  await f.cliquer('[data-ofsel="411"]', { apres: 800 });
  await f.montrer('.of-d-h', 'Offre n° 411');
  await f.dire('Le client revient : on ouvre son offre, la n° 411, dans les archivées.', 4600);
  await f.cacher(200); await f.taire(150);
  await f.cliquer('[data-ofgeste="reactiver"]', { apres: 1400 });
  await f.montrer('.of-ok', 'Remise en cours');
  await f.dire('« <b>Remettre en cours</b> » : l’offre repasse en cours dans TF Buddy et quitte les archivées.', 4800);
  await f.cacher(250); await f.taire(150);
  await f.cliquer('[data-ofong="cours"]', { apres: 900 });
  await f.montrer('[data-ofsel="411"]', 'De retour');
  await f.dire('La voici dans « <b>En cours</b> », prête à être modifiée, envoyée ou convertie.', 4200);
  await f.taire(150);
  await f.pause(1800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
