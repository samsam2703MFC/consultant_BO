/* Vidéo 21 — « L’Assistant IA » (mode d'emploi, ouverte depuis Assistant IA). Environ 2 min 30.
 * Données fictives : mocks/71_assistant.js (réponses écrites à la forme du serveur, chiffres du magasin de démo). */
const { Film } = require('../film');

/** La fausse API répond tout de suite : l'assistant, lui, prend le temps de lire. On fait attendre sa réponse. */
async function lenteur(f, ms) {
  await f.ctx.route('**/api/cockpit/assistant/magasin', async r => {
    if (r.request().method() === 'POST') { await new Promise(ok => setTimeout(ok, ms)); }
    await r.fallback();
  });
}

/** Attend que l'assistant ait répondu (plus de « … lit les chiffres » ni « … ouvre le manuel » à l'écran). */
const reponse = f => f.p.waitForFunction(() => !document.querySelector('.ia-att'), null, { timeout: 20000 }).then(() => f.pause(400));

module.exports = async function () {
  const f = await Film.ouvrir('21-assistant-ia', { vue: 'assistant', serie: 'Mode d’emploi', titreSerie: 'L’Assistant IA', duree: 'env. 2 min 30' });
  await lenteur(f, 2200);
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'L’Assistant IA', accroche: 'Vos chiffres, en une question',
    programme: ['Choisir son assistant', 'Poser une question, lire la réponse', 'Creuser en conversation', 'Le Coach Vente et le Professeur'], ms: 4600,
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Choisir son assistant', texte: 'Il lit les chiffres du magasin et le manuel opératoire.', ms: 3100 });
  await f.etape('1 · Choisir son assistant');
  await f.montrer('#db-rail .db-rail-ia', 'Assistant IA');
  await f.dire('L’<b>Assistant IA</b> s’ouvre en pleine page, sous la Boîte mail.', 3000);
  await f.montrer('.ia-page > .ia-h', 'Ce qu’il lit');
  await f.dire('Il lit les chiffres du magasin dans l’ERP, au moment de la question, et le manuel opératoire.', 4000);
  await f.montrer('.ia-tons', 'Votre assistant');
  await f.dire('Quatre assistants. Les deux coachs, l’un <b>bienveillant</b>, l’autre <b>à la dure</b>, ne changent que le ton : les chiffres restent les mêmes.', 5600);
  await f.montrer('[data-iaton=vente]', 'Coach Vente');
  await f.dire('Le <b>Coach Vente</b> : les prix face à la concurrence, les bundles, le panier.', 3400);
  await f.montrer('[data-iaton=professeur]', 'Le Professeur');
  await f.dire('Le <b>Professeur</b> répond sur le manuel opératoire : les gestes, les standards.', 3400);
  await f.cacher(200);

  await f.chapitre({ n: 2, total: 4, titre: 'Poser une question, lire la réponse', texte: 'Un résumé, des chiffres, un graphique, des priorités.', ms: 3100 });
  await f.etape('2 · Poser une question');
  await f.montrer('.ia-tqs', 'Questions toutes prêtes');
  await f.dire('Des <b>questions toutes prêtes</b>, en tuiles… ou la vôtre, tapée au-dessus.', 3400);
  await f.cacher(150);
  await f.cliquer('.ia-saisie.haut input', { apres: 200 });
  await f.taper('Quel est mon jour le plus faible, et pourquoi ?', 38);
  await f.cliquer('.ia-saisie.haut .ia-envoi', { apres: 300 });
  await f.dire('L’assistant <b>lit les chiffres</b> du magasin…', 2000);
  await reponse(f);
  await f.taire(200);
  await f.montrer('.ia-rep-h', 'La réponse');
  await f.dire('La réponse, l’assistant qui l’a écrite, et l’heure où les chiffres ont été lus.', 3600);
  await f.montrer('.ia-rep .ia-k', 'Les chiffres');
  await f.dire('Le résumé et les <b>chiffres</b> clés : chacun vient des chiffres du magasin.', 3400);
  await f.montrer('.ia-rep .ia-vis', 'Le graphique');
  await f.dire('Un <b>graphique</b> quand il aide : l’assistant décrit la demande, le serveur relit les chiffres.', 4200);
  await f.cacher(150);
  await f.cliquer('.ia-rep .ia-vis [data-v=tableau]', { apres: 500 });
  await f.montrer('.ia-rep .ia-vis', 'Graphique · Tableau');
  await f.dire('Graphique ou tableau, d’un clic ; il se télécharge, en CSV ou en image.', 3400);
  await f.cacher(150);
  await f.cliquer('.ia-rep .ia-vis [data-v=graphe]', { apres: 300 });
  await f.cliquer('.ia-rep [data-iamod]', { apres: 600 });
  await f.cacher(150);
  await f.montrer('.ia-mods', 'Mes modèles');
  await f.dire('<b>Garder comme modèle</b> : il rejoint « Mes modèles », refait chaque fois avec les chiffres du jour.', 4400);
  await f.cacher(150);
  await f.montrerTout('.ia-rep .ia-prio', 'Les priorités');
  await f.dire('Trois <b>priorités</b>, chacune avec l’écran du dashboard où agir.', 3200);
  await f.cacher(150);
  await f.montrer('.ia-rep .ia-pied', 'PDF · Conversation');
  await f.dire('En bas : la réponse en <b>PDF</b>, et « Continuer en conversation ».', 3200);
  await f.cacher(200);

  await f.chapitre({ n: 3, total: 4, titre: 'Creuser en conversation', texte: 'Les questions toutes prêtes à gauche, le fil à droite.', ms: 3100 });
  await f.etape('3 · Creuser');
  await f.cliquer('.ia-rep .ia-pied [data-iamode=a]', { apres: 1000 });
  await f.montrer('.ia-q', 'Questions toutes prêtes');
  await f.dire('En <b>conversation</b> : les questions toutes prêtes à gauche, rangées par thème, le fil à droite.', 4000);
  await f.cacher(150);
  await f.cliquer(f.p.locator('.ia-conv .ia-suites button').first(), { apres: 300 });
  await f.dire('Une question pour <b>creuser</b>, d’un clic…', 2000);
  await reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last(), 'La suite');
  await f.dire('… et la réponse arrive dans le fil : ici, un bundle pour le lundi après-midi, chiffré.', 4000);
  await f.cacher(200);

  await f.chapitre({ n: 4, total: 4, titre: 'Le Coach Vente et le Professeur', texte: 'Chacun ses questions, chacun ses sources.', ms: 3100 });
  await f.etape('4 · Coach Vente et Professeur');
  await f.cliquer('[data-iaton=vente]', { apres: 600 });
  await f.montrer('.ia-q', 'Ses questions');
  await f.dire('Le <b>Coach Vente</b> a ses questions : les prix, les bundles, les ventes, les objectifs 2026.', 3800);
  await f.cacher(150);
  await f.cliquer(f.p.locator('.ia-qb', { hasText: 'Mon croissant' }), { apres: 300 });
  await f.dire('Mon croissant face aux boulangeries proches…', 2000);
  await reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-vis').last(), 'Les prix relevés');
  await f.dire('Les prix relevés autour du magasin, le sien en Ruby, et sa marge.', 3400);
  await f.cacher(150);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-prio').first(), 'Hausse et lot');
  await f.dire('La hausse proposée vient avec un lot qui garde le prix aux fidèles.', 3400);
  await f.taire(150);
  await f.cacher(150);
  await f.cliquer('[data-iaton=professeur]', { apres: 600 });
  await f.cliquer(f.p.locator('.ia-qb', { hasText: 'Fermeture du magasin' }), { apres: 300 });
  await f.dire('Le <b>Professeur</b> ouvre le manuel…', 2000);
  await reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-srcs').last(), 'Lu dans le manuel');
  await f.dire('Il cite les documents qu’il a lus, et peut recopier un tableau du manuel.', 3600);
  await f.dire('L’assistant rédige ; vérifiez avant d’agir.', 2600);
  await f.cacher(200);

  return f.fin({
    titre: 'À vous<br>de demander', accroche: 'Une question, une réponse chiffrée', ms: 6000,
    retenir: ['Quatre assistants : le ton change, pas les chiffres', 'Une réponse : chiffres, graphique, priorités', 'Gardez un graphique comme modèle', 'Vérifiez avant d’agir'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
