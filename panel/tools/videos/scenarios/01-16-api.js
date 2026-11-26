/* La barre du haut › « API » (?vue=ops), 26/11/2026 :
 *   onglet    barre-api    la fenêtre « Les appels à l’API » : Cette session (chemin, appels, statut, durée, fonction qui
 *                          répond), la recherche, puis l'Index des chemins du dashboard
 * Aucune fonction en chapitre : la fenêtre ne fait que lire. Les appels sont ceux de la page filmée (fausse API des
 * vidéos) ; l'index est panel/public/dashboard/api-index.json. Chaque phrase vient de panel/public/dashboard/api-index.js. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('01-16-api', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/api-index.js', 'panel/public/dashboard/api-index.css', 'panel/public/dashboard/api-index.json'],
  });

  await f.onglet({
    id: 'barre-api', titre: 'Les appels à l’API',
    resume: 'La vignette « API » : les appels de la page à l’API du cockpit (statut, durée, fonction qui répond), puis l’index des chemins du dashboard, avec la recherche.',
    accroche: 'Ce que la page demande au serveur',
    programme: ['Cette session', 'Chercher un chemin', 'L’index des chemins'],
  });
  await f.montrer('#dash .db-avs [data-apiouvrir]', 'API');
  await f.dire('La vignette « <b>API</b> » compte les appels de la page à l’API du cockpit.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-apiouvrir]', { apres: 1200 });
  await f.montrer('.api-boite .page-tabs', 'Cette session');
  await f.dire('« <b>Cette session</b> » : chaque chemin que la page a appelé depuis son ouverture.', 4200);
  await f.montrer('.api-t thead', 'Les colonnes');
  await f.dire('Pour chacun : le nombre d’appels, le <b>dernier statut</b>, la durée moyenne, l’heure du dernier appel.', 5200);
  await f.montrerTout('.api-t tbody tr:nth-child(-n+4) td:last-child', 'Servi par');
  await f.dire('« <b>Servi par</b> » : la fonction du serveur qui répond, et son fichier.', 4000);
  await f.dire('Rien ne part ailleurs : ce journal vit dans l’onglet du navigateur, et s’oublie avec lui.', 4600);
  await f.cacher(150); await f.taire(150);

  await f.cliquer('#api-q', { apres: 200 });
  await f.taper('soir', 130);
  await f.pause(600);
  await f.montrer('.api-corps', 'Chercher');
  await f.dire('La <b>recherche</b> garde les chemins et les fonctions qui contiennent le mot.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('#api-q').fill(''); await f.p.locator('#api-q').dispatchEvent('input');
  await f.pause(400);

  await f.cliquer('.api-boite [data-apiong="index"]', { apres: 1200 });
  await f.montrer('.api-boite .page-tabs', 'Index');
  await f.dire('« <b>Index</b> » : tous les chemins que le dashboard appelle, rangés par famille.', 4200);
  await f.montrer('.api-corps .api-t >> nth=0', 'Une famille');
  await f.dire('Chacun avec sa méthode, ce qu’il fait, la fonction qui le sert, et combien de fois cette page l’a appelé.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#api-q', { apres: 200 });
  await f.taper('avis', 130);
  await f.pause(700);
  await f.montrer('.api-corps', 'Chercher dans l’index');
  await f.dire('Ici, « <b>siège seulement</b> » : ce chemin ne s’ouvre pas à une session de magasin. Les autres disent « magasin ».', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.api-boite .api-x', { apres: 800 });
  await f.dire('La croix, ou Échap, referme la fenêtre.', 2800);
  await f.taire(150);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
