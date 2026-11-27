/* Vidéo 22 — « Formations et suivi » (mode d'emploi, ouverte depuis Se former › Formations et suivi). Environ 2 min 20.
 * Données fictives : mocks/72_formation.js (le dossier de formation du magasin de démo, la méga-barre) et
 * mocks/lib/manuel.js (le manuel de démonstration) ; le Professeur répond par mocks/71_assistant.js. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('22-se-former', { vue: 'formation', serie: 'Mode d’emploi', titreSerie: 'Formations et suivi', duree: 'env. 2 min 20' });
  // Le Professeur prend le temps d'ouvrir le manuel (la fausse API répond tout de suite).
  await f.ctx.route('**/api/cockpit/assistant/magasin', async r => {
    if (r.request().method() === 'POST') { await new Promise(ok => setTimeout(ok, 1800)); }
    await r.fallback();
  });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Formations<br>et suivi', accroche: 'Tout ce qui s’apprend, à un clic',
    programme: ['L’accueil de la formation', 'La méga-barre de recherche', 'Mon dossier de formation', 'Une formation, pas à pas'], ms: 4600,
  });

  await f.chapitre({ n: 1, total: 4, titre: 'L’accueil de la formation', texte: 'Se former › Formations et suivi.', ms: 3100 });
  await f.etape('1 · L’accueil');
  await f.montrer('#db-rail .sidebar__item[data-vue=formation]', 'Formations et suivi');
  await f.dire('<b>Formations et suivi</b>, dans le rail, sous « Se former ».', 3000);
  await f.montrer('.fs-acc-h', 'Mon dossier');
  await f.dire('En haut, <b>votre dossier de formation</b> en quatre nombres : en retard, en cours, à valider, validées.', 4400);
  await f.montrer('.sf-phrase', 'En une phrase');
  await f.dire('La <b>formation</b> apprend le métier, le <b>manuel</b> explique pourquoi, la <b>SOP</b> montre comment faire, la <b>checklist</b> vérifie.', 5400);
  await f.montrer('.sf-ac thead', 'Apprendre · Comprendre · Faire · Vérifier');
  await f.dire('Pour chacun : c’est quoi, pour qui, quand, où, comment, et comment c’est validé.', 4000);
  await f.cacher(200);

  await f.chapitre({ n: 2, total: 4, titre: 'La méga-barre de recherche', texte: 'Tout le catalogue, d’une seule recherche.', ms: 3100 });
  await f.etape('2 · La méga-barre');
  await f.defiler(-2000);
  await f.montrer('.sf-in', 'La méga-barre');
  await f.dire('La <b>méga-barre</b> cherche dans tout le catalogue : titres, codes, objectifs et texte des étapes.', 4400);
  await f.cacher(150);
  await f.cliquer('[data-sf-q]', { apres: 200 });
  await f.taper('croissant', 80);
  await f.pause(500);
  await f.montrer('.sf-f', 'Les catalogues');
  await f.dire('À gauche, les catalogues ; chaque résultat porte l’étiquette et la couleur du sien.', 4000);
  await f.montrer('.sf-r', 'Les résultats');
  await f.dire('Le mot trouvé surligné, où mène le lien, et la photo.', 3200);
  await f.montrer('.sf-ap', 'L’aperçu');
  await f.dire('L’aperçu à droite ; ↑ ↓ pour choisir, Entrée pour ouvrir.', 3200);
  await f.cacher(150);
  await f.cliquer('.sf-ap [data-sf-ouvrir]', { apres: 1100 });
  await f.montrer('.sf-tir', 'La SOP');
  await f.dire('Le document s’ouvre ici, en tiroir : ses étapes, ses photos, ses <b>points critiques</b>.', 4200);
  await f.taire(150);
  await f.cacher(150);
  await f.cliquer('.sf-tir .x', { apres: 500 });
  await f.cliquer('.sf-x', { apres: 600 });

  await f.chapitre({ n: 3, total: 4, titre: 'Mon dossier de formation', texte: 'La formation continue de votre magasin.', ms: 3100 });
  await f.etape('3 · Mon dossier');
  await f.cliquer('.fs-mini', { apres: 1100 });
  await f.montrer('.fs-res', 'Où j’en suis');
  await f.dire('Le <b>résumé</b> : validées, à valider chez votre consultant, en cours, en retard ; dessous, ce qui est <b>à corriger</b>.', 5000);
  await f.montrer('.fs-nouv', 'Nouveautés');
  await f.dire('Les <b>nouveautés</b> : les formations continues reçues ces deux derniers mois, la plus récente d’abord.', 4400);
  await f.montrer(f.p.locator('.fs-carte', { has: f.p.locator('.fs-th') }), 'Mon dossier');
  await f.dire('Chaque formation : son statut, son avancement, son échéance.', 3200);
  await f.cacher(150);
  await f.montrer('.fs-eq', 'Le suivi de l’équipe');
  await f.dire('Le <b>suivi de l’équipe</b> : qui a lu, réussi le quiz, été vu faire. C’est vous qui le déclarez.', 4400);
  await f.cacher(150);
  await f.montrer('.fs-prof', 'Le Professeur');
  await f.dire('Le <b>Professeur</b> répond à partir de votre dossier et du manuel ; ses questions viennent de vos formations.', 4800);
  await f.cacher(200);

  await f.chapitre({ n: 4, total: 4, titre: 'Une formation, pas à pas', texte: 'Votre version, ce qu’il reste à faire, la photo du jour.', ms: 3100 });
  await f.etape('4 · Une formation');
  await f.cliquer('.fs-l[data-fs-ouvrir="31"]', { apres: 1200 });
  await f.montrer('.fs-adapt', 'Adaptée pour vous');
  await f.dire('Adaptée pour votre magasin par votre consultant : c’est cette version que vous suivez.', 4000);
  await f.montrer(f.p.locator('.fs-et.crit').first(), 'Point critique');
  await f.dire('Les étapes, une à une ; les <b>points critiques</b> en rouge.', 3000);
  await f.cacher(150);
  await f.montrer(f.p.locator('.fs-bloc', { has: f.p.locator('.fs-grille') }), 'La photo du jour');
  await f.dire('Un nouveau produit : une <b>photo qualité par jour</b> pendant 14 jours, relue par votre consultant.', 4400);
  await f.dire('Un jour manqué ou une photo à refaire ajoute un jour.', 2800);
  await f.cacher(150);
  await f.montrer(f.p.locator('.fs-bloc', { has: f.p.locator('.fs-pers') }), 'Qui la suit');
  await f.dire('Qui la suit : vous cochez ce qui est lu, le quiz réussi, ce qui a été vu faire.', 3800);
  await f.cacher(150);
  await f.cliquer('[data-fs-eq="Julien P."][data-k=vu]', { apres: 900 });
  await f.montrer('.fs-fh', 'L’avancement');
  await f.dire('L’avancement monte. Tout fait : votre consultant relit et valide.', 3400);
  await f.taire(150);
  await f.cacher(150);
  await f.cliquer(f.p.locator('.fs-prof [data-fs-prof]').nth(1), { apres: 300 });
  await f.dire('Une question au <b>Professeur</b>…', 1800);
  await f.p.waitForFunction(() => !document.querySelector('#db-ia .ia-att'), null, { timeout: 20000 });
  await f.pause(400);
  await f.montrer(f.p.locator('#db-ia .ia-bulle.ia').last(), 'Le Professeur');
  await f.dire('… il répond à partir de cette formation, et cite ce qu’il a lu.', 3600);
  await f.cacher(200);

  return f.fin({
    titre: 'À vous<br>de vous former', accroche: 'Apprendre, comprendre, faire, vérifier', ms: 6000,
    retenir: ['La méga-barre cherche dans tout le catalogue', 'Le dossier : échéances et suivi de l’équipe', 'Une photo par jour pour un nouveau produit', 'Le Professeur répond à partir de votre dossier'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
