/* Assistant IA › « Coach Vente » (« Votre assistant » › Coach Vente), et sa fonction en chapitre (26/11/2026) :
 *   onglet    assistant-coach-vente    ses tuiles, sa saisie, ses questions par thème (les prix, les bundles, les ventes,
 *                                      les objectifs 2026)
 *   fonction  assistant-comparer-prix  « Mon croissant face aux boulangeries proches ? » : les prix relevés, la marge, la
 *                                      hausse proposée et le lot qui garde le prix aux fidèles
 * Données fictives : mocks/71_assistant.js (VENTE, croissant : relevés de concurrents inventés). Chaque phrase vient de
 * dashboard.js (iaQ, iaSaisie, iaConversation) ou de panel/src/LISEZMOI.md (Coach Vente : prix_concurrence,
 * prix_a_monter « la hausse à la médiane et un lot au prix actuel »). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('21-04-coach-vente', {
    vue: 'assistant', serie: 'Assistant IA', parent: 'assistant-ia',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/src/assistant_magasin.php', 'panel/src/assistant_vente.php'],
    avant: async p => { await A.lenteur(p.context()); await A.marge(p); },
  });

  await f.onglet({
    id: 'assistant-coach-vente', titre: 'Le Coach Vente',
    resume: 'Ses questions — prix face à la concurrence, bundles, ventes, objectifs 2026 — et ses réponses : prix relevés, marge, hausse et lot.',
    accroche: 'Vos prix, vos bundles, votre panier',
    programme: ['Comparer un prix à la concurrence'],
  });
  await f.montrer('[data-iaton=vente]', 'Coach Vente');
  await f.dire('Le <b>Coach Vente</b> : un expert de la vente au comptoir. Il se choisit dans « Votre assistant ».', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-iaton=vente]', { apres: 900 });
  await f.montrer('[data-iaton=vente]', 'Ce qu’il lit');
  await f.dire('Il lit les ventes du magasin, les relevés de la concurrence — ceux de l’onglet Concurrence — et la marge de chaque produit.', 5600);
  await f.montrer('.ia-tqs', 'Ses questions');
  await f.dire('Ses tuiles : les prix face à la concurrence, le bundle de la semaine, les points forts et faibles, le panier.', 5000);
  await f.montrer('.ia-saisie.haut', 'Sa saisie');
  await f.dire('Sa saisie le dit aussi : « mon croissant est-il bien placé face aux concurrents ? »', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-page .ia-modes [data-iamode=a]', { apres: 1000 });
  await f.montrer('.ia-q', 'Par thème');
  await f.dire('En conversation, ses questions par thème : <b>les prix</b>, <b>les bundles</b>, <b>les ventes</b>…', 4200);
  await f.montrer(f.p.locator('.ia-q .ia-q-g', { hasText: 'Les bundles' }), 'Les bundles');
  await f.dire('Ses bundles partent de vos points forts et de vos points faibles, face au réseau.', 4000);
  await f.montrer(f.p.locator('.ia-q .ia-q-g', { hasText: 'objectifs 2026' }), 'Les objectifs 2026');
  await f.dire('… et <b>les objectifs 2026</b> : le food cost, les clients scannés.', 3600);
  await f.taire(150); await f.cacher(300);

  /* Comparer un prix à la concurrence */
  await f.fonction({ id: 'assistant-comparer-prix', titre: 'Comparer un prix à la concurrence',
    resume: '« Mon croissant face aux boulangeries proches ? » : les prix relevés autour du magasin, le sien et sa marge, puis la hausse proposée et un lot qui garde le prix aux fidèles.',
    points: ['Coach Vente, en conversation', '« Les prix » : la question', 'Les prix relevés, la marge', 'La hausse et le lot'] });
  await f.montrer(f.p.locator('.ia-q .ia-qb', { hasText: 'Mon croissant' }), 'Les prix');
  await f.dire('Coach Vente, en conversation : « Mon croissant face aux boulangeries proches ? »', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-q .ia-qb', { hasText: 'Mon croissant' }), { apres: 300 });
  await f.dire('Il lit les chiffres et les relevés…', 1800);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-k'), 'Mon prix · la médiane');
  await f.dire('Mon prix, <b>1,40 €</b>, la médiane des commerces relevés, <b>1,50 €</b>, et ma marge.', 4400);
  await f.montrer(f.p.locator('.ia-fil .ia-vis').last(), 'Les prix relevés');
  await f.dire('Les prix relevés autour du magasin, avec la distance ; le mien en Ruby.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-fil .ia-vis').last().locator('[data-v=tableau]'), { apres: 700 });
  await f.montrer(f.p.locator('.ia-fil .ia-vis').last(), 'Le tableau');
  await f.dire('En tableau : le produit relevé, sa taille, la date du relevé et la qualité notée.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.montrerTout('.ia-fil .ia-prio', 'Hausse et lot');
  await f.dire('La hausse proposée, à la médiane, et un <b>lot</b> qui garde le prix d’aujourd’hui aux fidèles.', 4800);
  await f.dire('Le prix se change dans Produits ; le lot se pose dans Promotions.', 3600);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
