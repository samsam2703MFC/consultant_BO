/* Formations et suivi › le lecteur (le tiroir), un onglet sans fonction qui écrive (26/11/2026) :
 *   onglet    former-lecteur   un exemple du tableau d'accueil, puis un résultat de la méga-barre, s'ouvrent sur place :
 *                              objectif, étapes et photos, points critiques en rouge, « Feuilleter dans le catalogue »,
 *                              « Demander au Professeur » ; Échap ou la croix referment (lectures)
 * « Feuilleter dans le catalogue ↗ » s'ouvre dans un nouvel onglet : il ne se filme pas ; « Demander au Professeur » :
 * la fonction de 22-02. Données fictives : mocks/72_formation.js (sop), lib/manuel.js. Chaque phrase vient de
 * panel/public/dashboard/seformer.js (ouvrirRef, ouvrirX, rendreLect). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('22-03-lecteur', {
    vue: 'formation', serie: 'Formations et suivi', parent: 'se-former',
    sources: ['panel/public/dashboard/seformer.js', 'formation/src/formation_recherche.php'],
  });

  await f.onglet({
    id: 'former-lecteur', titre: 'Le lecteur',
    resume: 'Le document s’ouvre sur place, en tiroir : objectif, étapes et photos, points critiques en rouge, « Feuilleter dans le catalogue », « Demander au Professeur ».',
    accroche: 'Le document, sans quitter la page',
    programme: ['Depuis un exemple de l’accueil', 'Depuis la méga-barre', 'Refermer : Échap ou la croix'],
  });
  await f.montrer('[data-sf-ex="doc:ouv-mag"]', 'Un exemple');
  await f.dire('Un exemple du tableau d’accueil s’ouvre d’un clic…', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-sf-ex="doc:ouv-mag"]', { apres: 1200 });
  await f.montrer('.sf-tir .h', 'Le lecteur');
  await f.dire('… dans le <b>lecteur</b>, en tiroir, sans quitter la page : le catalogue, le code, le titre.', 4600);
  await f.montrer('.sf-tir .sf-obj', 'L’objectif');
  await f.dire('L’objectif, pour qui, en combien de temps.', 3000);
  await f.montrer(f.p.locator('.sf-tir .sf-et', { has: f.p.locator('img') }).first(), 'Une étape');
  await f.dire('Les étapes, une à une, avec leur photo…', 3000);
  await f.montrer('.sf-tir .sf-et.crit', 'Point critique');
  await f.dire('… et les <b>points critiques</b>, en rouge.', 2800);
  await f.montrer('.sf-tir .sf-bts', 'Deux boutons');
  await f.dire('« Feuilleter dans le catalogue » ouvre le catalogue des manuels dans un autre onglet ; « Demander au Professeur », l’assistant.', 5800);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Escape');
  await f.pause(700);
  await f.dire('Échap le referme.', 2200);
  await f.taire(150);
  await f.defiler(-3000);
  await f.cliquer('[data-sf-q]', { apres: 150 });
  await f.taper('tourage', 90);
  await f.pause(500);
  const sop = f.p.locator('.sf-l', { hasText: 'Tourage de la pâte' }).first();
  if (!(await sop.evaluate(e => e.classList.contains('sel')))) { await f.cliquer(sop, { apres: 700 }); }
  await f.montrer('.sf-ap [data-sf-ouvrir]', 'Ouvrir la SOP');
  await f.dire('Depuis la méga-barre : « Ouvrir la SOP » sur l’aperçu, ou Entrée.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.sf-ap [data-sf-ouvrir]', { apres: 1200 });
  await f.montrer('.sf-tir .sf-sec', 'Par parties');
  await f.dire('Un document long se lit par parties : préparer, puis tourer.', 3400);
  await f.montrer('.sf-tir .sf-crit', 'Les points critiques');
  await f.dire('À la fin, ses points critiques, rappelés.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.sf-tir .x', { apres: 700 });
  await f.dire('La croix, en haut du tiroir, le referme aussi : la recherche est toujours là.', 4000);
  await f.taire(150);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
