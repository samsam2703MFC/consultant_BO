/* Boîte mail › Briefing (la bascule IA › Briefing), et sa fonction en chapitre (24/11/2026) :
 *   onglet    boite-briefing           les non-lus en cartes lues par l'IA (catégorie, urgence, confiance, résumé,
 *                                      réponse rédigée), « Plus tard », la file du matin, « Mains dans la farine »
 *   fonction  boite-briefing-valider   compléter les « [à compléter] » de la réponse, puis « Valider » (ou →)
 * « Plus tard », « Ouvrir l'e-mail » et la file n'écrivent rien : ils se voient dans l'onglet. « Écouter le briefing »
 * (la voix, le micro) ne se filme pas : la vidéo est muette ; le bloc est seulement montré. Données fictives :
 * mocks/70_boite.js (les quatre non-lus déjà lus par l'IA). Chaque phrase vient de panel/public/dashboard/boite.js
 * (vueBriefing, validerCarte, plusTardCarte) ou de panel/src/LISEZMOI.md. */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-09-briefing', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_ia.php'], avant: B.ouverte() });

  /* L'onglet */
  await f.onglet({
    id: 'boite-briefing', titre: 'Le Briefing',
    resume: 'Les non-lus en cartes, déjà lus par l’IA : catégorie, urgence, confiance, résumé et réponse rédigée ; la file du matin et « Mains dans la farine ».',
    accroche: 'Le matin, la boîte en cartes', programme: ['Valider une réponse du Briefing'],
  });
  await f.montrer('.bx-modes', 'Les modes IA');
  await f.dire('La bascule <b>IA</b> : Classique, puis trois modes où l’IA lit les e-mails.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxmode="briefing"]', { apres: 1300 });
  await f.montrer('.bx-br-hero', 'Le Briefing');
  await f.dire('Le <b>Briefing</b> : les non-lus de la Réception, déjà lus par l’IA.', 3600);
  await f.montrer('.bx-br-puces', 'En bref');
  await f.dire('En bref : les réponses rédigées, les offres à créer, ce qui est à ranger, les clients mécontents.', 4800);
  await f.montrer('.bx-carte-h', 'Une carte');
  await f.dire('Une carte par e-mail, l’urgent d’abord : la catégorie, l’urgence, et la <b>confiance</b> de l’IA.', 4800);
  await f.montrer('.bx-carte-resume', 'Le résumé');
  await f.dire('Le résumé de l’e-mail, en une phrase.', 2800);
  await f.montrer('.bx-carte-rep', 'Réponse rédigée');
  await f.dire('La <b>réponse rédigée</b>. Ce que l’IA ne sait pas — un prix, un geste — reste « [à compléter] ».', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-carte-pied [data-bx="brTard"]', { apres: 1000 });
  await f.montrer('.bx-carte-h', 'La suivante');
  await f.dire('« <b>Plus tard</b> » (ou ←) : la carte passe en fin de file, la suivante arrive.', 4200);
  await f.montrer('.bx-br-file', 'La file du matin');
  await f.dire('<b>La file du matin</b> : un clic y choisit une carte.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-br-file [data-bxbr="1046"]', { apres: 900 });
  await f.montrer('.bx-carte-pied [data-bxbro]', 'Ouvrir l’e-mail');
  await f.dire('« <b>Ouvrir l’e-mail</b> » le montre en Classique, en entier.', 3200);
  await f.montrer('.bx-voix', 'Mains dans la farine');
  await f.dire('« <b>Mains dans la farine</b> » : les cartes lues à voix haute ; on répond « oui », « plus tard », « ouvre » ou « stop ».', 5800);
  await f.cacher(150); await f.taire(150);
  await f.dire('Rien ne part sans votre validation.', 2600);
  await f.taire(150);

  /* Valider une réponse */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-briefing-valider', titre: 'Valider une réponse du Briefing',
    resume: 'Relire la carte, corriger la réponse et remplir les « [à compléter] », puis « Valider » (ou →) : la réponse part.',
    points: ['Relire la carte', 'Remplir les « [à compléter] »', '« Valider » (ou →)'] });
  await f.cliquer('[data-bxmode="briefing"]', { apres: 600 });
  await f.cliquer('.bx-br-file [data-bxbr="1048"]', { apres: 900 });
  await f.montrer('.bx-carte-rep', 'À compléter');
  await f.dire('Une réclamation : l’IA a rédigé, mais le geste offert est <b>à compléter</b>.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-carte-pied [data-bx="brValider"]', { apres: 900 });
  await B.enHaut(f);
  await f.montrer('.db-err', 'Pas encore');
  await f.dire('Tant qu’il reste un « [à compléter] », la réponse ne part pas.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.survoler('#bx-br-rep', { dy: 0.55 });
  await f.p.evaluate(() => { const t = document.querySelector('#bx-br-rep'), i = t.value.indexOf('[à compléter'), j = t.value.indexOf(']', i) + 1; t.focus(); t.setSelectionRange(i, j); });
  await f.pause(700);
  await f.taper('un éclair vous attend, offert, à votre prochain passage', 35);
  await f.montrer('#bx-br-rep', 'Complété');
  await f.dire('Complété. La réponse se corrige comme on veut, directement dans la carte.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-carte-pied [data-bx="brValider"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Envoyée');
  await f.dire('« <b>Valider</b> » : la réponse part, la carte suivante arrive. Au clavier : →.', 4000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
