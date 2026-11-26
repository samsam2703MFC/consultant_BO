/* Assistant IA › « Les deux coachs » (Mr. Basile, bienveillant ; Le Coach, à la dure), un onglet sans fonction qui
 * écrive (26/11/2026) :
 *   onglet    assistant-coachs   « Votre assistant » : choisir son coach (gardé sur l'appareil) ; les mêmes questions
 *                                toutes prêtes, la même question posée aux deux, les mêmes chiffres : seul le ton change
 * « Choisir son assistant » est une lecture (gardé dans le navigateur, rien au serveur) : il se montre ici, sans chapitre.
 * Données fictives : mocks/71_assistant.js (le Coach tutoie et finit sur l'ordre de marche du jour, comme AM_COACHS.coach
 * de panel/src/assistant_magasin.php) ; avant de filmer, Mr. Basile a répondu en conversation. Chaque phrase vient de
 * dashboard.js (iaChoixTon, iaQ, localStorage dbIaTon) ou d'assistant_magasin.php (AM_COACHS : ton, tutoiement). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('21-03-coachs', {
    vue: 'assistant', serie: 'Assistant IA', parent: 'assistant-ia',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/src/assistant_magasin.php'],
    avant: async p => {
      await p.click('.ia-page .ia-modes [data-iamode=a]');
      await p.waitForTimeout(600);
      await p.locator('.ia-q .ia-qb', { hasText: 'Quel est mon jour le plus faible' }).click();
      await p.waitForTimeout(300);
      await A.reponse(p);
      await A.lenteur(p.context());
      await p.evaluate(() => { window.scrollTo(0, 0); const fil = document.querySelector('.ia-fil'); if (fil) { fil.scrollTop = 0; } });
    },
  });

  await f.onglet({
    id: 'assistant-coachs', titre: 'Les deux coachs',
    resume: 'Mr. Basile, bienveillant, et Le Coach, à la dure : les mêmes questions et les mêmes chiffres ; seul le ton change. Le choix reste sur l’appareil.',
    accroche: 'Le ton qui vous va',
    programme: ['Choisir son coach', 'La même question, deux tons', 'Le choix reste sur l’appareil'],
  });
  await f.montrer('.ia-tons', 'Votre assistant');
  await f.dire('« <b>Votre assistant</b> » : quatre portraits. Les deux premiers sont les coachs du magasin.', 4400);
  await f.montrer('[data-iaton=pierre]', 'Bienveillant');
  await f.dire('<b>Mr. Basile</b>, bienveillant et motivateur : il vous vouvoie, et propose un premier pas simple.', 4600);
  await f.montrer('[data-iaton=coach]', 'À la dure');
  await f.dire('<b>Le Coach</b>, à la dure : il tutoie, et ne laisse passer aucune excuse.', 4000);
  await f.cacher(150); await f.taire(150);
  await A.cadre(f, '.ia-fil', 'Mr. Basile');
  await f.dire('Le jour le plus faible, demandé à Mr. Basile : « le lundi est <b>votre</b> jour le plus faible ».', 4400);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-iaton=coach]', { apres: 900 });
  await f.montrer('.ia-q', 'Les mêmes questions');
  await f.dire('Le Coach choisi : les questions toutes prêtes sont les mêmes.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-q .ia-qb', { hasText: 'Quel est mon jour le plus faible' }), { apres: 300 });
  await f.dire('La même question…', 1600);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-res'), 'Le Coach');
  await f.dire('… « c’est <b>ton</b> maillon faible » : il tutoie, style vestiaire.', 4000);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-k'), 'Les mêmes chiffres');
  await f.dire('Les chiffres, eux, ne changent pas : 1 453 €, −25 %, 120 clients, 12,10 € de panier.', 4800);
  await f.montrer(f.p.locator('.ia-fil .ia-bulle.ia').last().locator('.ia-fin'), 'L’ordre de marche');
  await f.dire('Le Coach finit sur l’<b>ordre de marche</b> du jour.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.ia-tons', 'Gardé sur l’appareil');
  await f.dire('Le coach choisi reste sur cet appareil : il répondra la prochaine fois aussi.', 4000);
  await f.taire(150); await f.cacher(300);
  await f.pause(1000);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
