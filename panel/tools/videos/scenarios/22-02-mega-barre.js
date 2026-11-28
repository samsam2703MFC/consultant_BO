/* Formations et suivi › la méga-barre (le panneau ouvert), et sa fonction en chapitre (26/11/2026) :
 *   onglet    former-mega-barre             deux lettres : les catalogues et leurs nombres, les résultats étiquetés et
 *                                           surlignés, l'aperçu ; ↑ ↓, un catalogue à gauche, Échap ; rien ne répond :
 *                                           « Posez la question au Professeur » (lectures)
 *   fonction  former-professeur-recherche   « Demander au Professeur » sur l'aperçu : la fenêtre du Professeur explique le
 *                                           document et le cite
 * « Feuilleter dans le catalogue ↗ » s'ouvre dans un nouvel onglet : il ne se filme pas. « Ouvrir » un résultat : 22-03.
 * Données fictives : mocks/72_formation.js (recherche), lib/manuel.js, 71_assistant.js (le Professeur). Chaque phrase
 * vient de panel/public/dashboard/seformer.js (chercher, panneau, apercu, brancherHaut) et formation.js. */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('22-02-mega-barre', {
    vue: 'formation', serie: 'Formations et suivi', parent: 'se-former',
    sources: ['panel/public/dashboard/seformer.js', 'panel/public/dashboard/formation.js', 'formation/src/formation_recherche.php', 'panel/public/dashboard/dashboard.js'],
    avant: async p => { await A.lenteur(p.context(), 1800); },
  });

  await f.onglet({
    id: 'former-mega-barre', titre: 'La méga-barre',
    resume: 'Deux lettres ouvrent le panneau : les catalogues à gauche avec leurs nombres, les résultats étiquetés et surlignés, l’aperçu à droite ; ↑ ↓, Entrée, Échap.',
    accroche: 'Une seule recherche, tout le catalogue',
    programme: ['Demander au Professeur depuis la recherche'],
  });
  await f.cliquer('[data-sf-q]', { apres: 200 });
  await f.taper('croissant', 90);
  await f.pause(500);
  await f.montrer('.sf-in', 'Deux lettres suffisent');
  await f.dire('Dès deux lettres, le panneau s’ouvre : les titres, les codes, les objectifs et le texte des étapes, accents ou non.', 5600);
  await A.cadre(f, '.sf-f', 'Les catalogues');
  await f.dire('À gauche, les catalogues, avec le nombre de résultats dans chacun.', 3600);
  await A.cadre(f, '.sf-r', 'Les résultats');
  await f.dire('Au centre, le plus pertinent d’abord : l’étiquette du catalogue, le mot surligné, où mène le lien, la photo.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('ArrowDown'); await f.pause(500);
  await f.p.keyboard.press('ArrowDown'); await f.pause(500);
  await A.cadre(f, '.sf-ap', 'L’aperçu');
  await f.dire('↑ ↓ choisissent un résultat ; l’aperçu, à droite, montre son objectif, sa photo, ses premières étapes.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.sf-f [data-sf-cat="chk"]', { apres: 900 });
  await A.cadre(f, '.sf-r', 'Checklists');
  await f.dire('Un catalogue, à gauche, ne garde que ses résultats ; « Tout » les rend.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.sf-f [data-sf-cat=""]', { apres: 700 });
  // Le pied du panneau est au bas de l'écran : montré sans sous-titre (il le couvrirait), puis dit.
  await A.cadre(f, '.sf-pied', 'Le clavier', 2200);
  await f.cacher(150);
  await f.dire('Au pied du panneau, les touches : Entrée ouvre le résultat choisi ; Échap referme.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.p.locator('[data-sf-q]').fill('');
  await f.taper('congélateur', 90);
  await f.pause(500);
  await f.montrer('.sf-vide', 'Rien ne répond');
  await f.dire('Rien ne répond ? Le lien vers le <b>Professeur</b> est là.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Escape');
  await f.pause(600);

  /* Demander au Professeur depuis la recherche */
  await f.fonction({ id: 'former-professeur-recherche', titre: 'Demander au Professeur depuis la recherche',
    resume: '« Demander au Professeur » sur l’aperçu d’un résultat (ou dans le lecteur) : la fenêtre de l’assistant s’ouvre sur le Professeur, qui explique le document et le cite.',
    points: ['Chercher, choisir un résultat', '« Demander au Professeur »', 'Il explique, et cite le document'] });
  await f.cliquer('[data-sf-q]', { apres: 150 });
  await f.p.keyboard.press('Control+A');
  await f.taper('tourage', 90);
  await f.pause(500);
  // Un clic choisit un résultat ; un clic sur celui déjà choisi l'ouvrirait : on ne clique que s'il ne l'est pas.
  const sop = f.p.locator('.sf-l', { hasText: 'Tourage de la pâte' }).first();
  if (!(await sop.evaluate(e => e.classList.contains('sel')))) { await f.cliquer(sop, { apres: 700 }); } else { await f.survoler(sop, { pause: 700 }); }
  await A.cadre(f, '.sf-ap', 'La SOP choisie');
  await f.dire('La SOP du tourage, choisie : son aperçu, à droite.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.sf-ap [data-sf-prof]', { apres: 300 });
  await f.dire('« <b>Demander au Professeur</b> » : la fenêtre de l’assistant s’ouvre…', 2400);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('#db-ia .ia-bulle.moi').last(), 'La question');
  await f.dire('La question part toute faite : « Explique-moi » le document, avec son code.', 3800);
  await f.montrer(f.p.locator('#db-ia .ia-bulle.ia').last(), 'Le Professeur');
  await f.dire('Il explique les étapes et les points critiques…', 3000);
  await f.montrer(f.p.locator('#db-ia .ia-srcs').last(), 'Lu dans le manuel');
  await f.dire('… et cite le document qu’il a lu.', 2800);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
