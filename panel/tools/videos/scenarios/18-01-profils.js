/* Fidélité, l'onglet « Profils des clients » (?vue=fidelite), 26/11/2026 :
 *   onglet    fidelite-profils   ouvert par défaut : la part des tickets scannés (aujourd'hui, le mois, l'année, l'échelle
 *                                de 0 à 90 %), les six profils du moins fidèle au plus fidèle, un profil choisi (ses
 *                                radars face à la moyenne, son évolution sur 30, 90 ou 180 jours), les clients qui
 *                                reviennent (la liste dépliée, la recherche)
 * Aucune fonction en chapitre : la page se lit (choisir un profil, la période de l'évolution, déplier et chercher sont
 * des gestes de lecture). Données fictives : mocks/18_fidelite.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (rendFidelite, fdScan, fdTendance, fdBrancher). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('18-01-profils', {
    vue: 'fidelite', serie: 'Fidélité', parent: 'fidelite',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'marketing/src/fidelite_profils.php'],
  });

  await f.onglet({
    id: 'fidelite-profils', titre: 'Les profils des clients',
    resume: 'La part des tickets scannés, les six profils du moins fidèle au plus fidèle, les radars et l’évolution du profil choisi, puis les clients qui reviennent.',
    accroche: 'Qui revient, et à quel rythme',
    programme: ['Les tickets scannés', 'Les six profils', 'Un profil : radars et évolution', 'Les clients qui reviennent'],
  });
  await f.montrer('.fd-ong', 'Deux onglets');
  await f.dire('Fidélité s’ouvre sur <b>Profils des clients</b> ; à côté, Cartes de fidélité.', 3800);
  await f.montrer('.fd-scan', 'Les tickets scannés');
  await f.dire('D’abord, la part des <b>tickets d’aujourd’hui</b> qui ont un client scanné ; dessous, le mois et l’année.', 5200);
  await f.montrer('.fd-scan-e', 'De 0 à 90 %');
  await f.dire('L’échelle va de 0 à 90 % : du noir au rouge, à l’orange, au jaune, au vert, puis à l’<b>or</b>.', 4800);
  await f.cacher(300); await f.taire(150);

  await f.montrer('.fd-tuiles', 'Six profils');
  await f.dire('Les clients reconnus en caisse, rangés en <b>six profils</b> : du moins fidèle, à gauche, au plus fidèle.', 5000);
  await f.montrer('.fd-tui[data-fdp="3"]', 'Hebdo');
  await f.dire('Chaque profil : sa part des clients, sa <b>condition</b> — ici une fois par semaine —, sa part du chiffre scanné, son panier, la part en commande.', 6800);
  await f.dire('Les conditions se règlent au cockpit.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fd-tui[data-fdp="3"]', { apres: 1300 });
  await f.montrer('.fd-g3', 'Hebdo face à la moyenne');
  await f.dire('Un clic sur un profil : sa <b>récurrence</b> et son <b>type d’achat</b> face à la moyenne, et l’évolution de son nombre de clients.', 6400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdt="180"]', { apres: 1500 });
  await f.montrer('.fd-g3 > .fd-c:nth-child(3)', 'Évolution');
  await f.dire('L’évolution se lit sur <b>30, 90 ou 180 jours</b> : le nombre de clients au début, et aujourd’hui.', 5000);
  await f.cacher(300); await f.taire(150);

  await f.montrer('.fd-dd', 'Les clients qui reviennent');
  await f.dire('Plus bas, <b>les clients qui reviennent</b> : ceux au-dessus du profil de base.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdliste]', { apres: 1000 });
  await f.montrer('.fd-dd', 'La liste');
  await f.dire('Un clic sur le titre déplie la liste : le profil, le rythme, le dernier passage, le panier, ce qu’ils achètent surtout.', 6200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-fdq]', { apres: 300 });
  await f.taper('fidèle', 120);
  await f.pause(600);
  await f.montrer('.fd-dd', 'Chercher');
  await f.dire('La recherche filtre sur place, par <b>nom</b> ou par <b>profil</b> : ici, les fidèles.', 4400);
  await f.dire('Un clic sur un client ouvre sa fiche.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('[data-fdq]').fill(''); await f.p.locator('[data-fdq]').dispatchEvent('input');
  await f.pause(500);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
