/* La barre du haut › « Revue du soir » › le résumé (?vue=ops), 26/11/2026 :
 *   onglet    barre-soir-resume      la vignette, le résumé (tâches obligatoires pas faites, checklists à passer),
 *                                    « Qui fait la revue ? », « Commencer » et « Plus tard »
 *   fonction  barre-soir-faire       qui fait la revue, « Commencer », une réponse par écran (checklists, points de
 *                                    gestion), ‹ pour revenir : jusqu'au mot pour demain
 *   fonction  barre-soir-reprendre   fermer en cours de route : à la réouverture, « Reprendre · N réponses déjà données »
 * « Clôturer la journée » et « Rouvrir la revue » : 01-22-soir-demain et 01-23-soir-bilan. Données fictives :
 * mocks/11_barre_soir.js (GET installé sur la page, 00_commun.js le sert vide). Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (railBarre, rvOuvrir, rvEtapes, rvEcran, rvRepondre, rvBrancher) ou de
 * panel/src/revue_soir.php (la revue n'est pas une checklist du panel). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-18-soir-resume', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/revue_soir.php'],
    avant: async p => { await B.soirFilm(p); await B.prioritaires(p); await B.soirEtat(p, null); await B.relire(p); },
  });
  const rep = (attr, v, apres = 750) => f.cliquer(`#rv [${attr}="${v}"]`, { apres });

  await f.onglet({
    id: 'barre-soir-resume', titre: 'La revue du soir : le résumé',
    resume: 'La vignette « Revue du soir » et son premier écran : les tâches obligatoires pas faites, les checklists à passer en revue, « Qui fait la revue ? », puis « Commencer » ou « Plus tard ».',
    accroche: 'La journée, passée en revue',
    programme: ['Le résumé de la journée', 'Faire la revue du soir', 'Reprendre une revue plus tard'],
  });
  await f.montrer('#dash .db-avs [data-rvouvrir]', 'Revue du soir');
  await f.dire('La vignette « <b>Revue du soir</b> » : l’heure prévue, 18 h ; ensuite à faire, en cours, faite.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-rvouvrir]', { apres: 1500 });
  await f.dire('Une question à la fois, pour passer la journée en revue avant de fermer.', 3800);
  await B.cadre(f, '#rv .rv-bul', 'Le résumé');
  await f.dire('D’abord le résumé : les <b>tâches obligatoires pas faites</b>, puis ce qu’on va passer en revue.', 5800);
  await B.cadre(f, '#rv .rv-cards', 'Les checklists pas finies');
  await f.dire('Les <b>checklists pas finies</b>, avec le nombre de tâches pas faites de chacune.', 4400);
  await B.cadreTout(f, '#rv .rv-q, #rv [data-rvpar], #rv .rv-rep [data-rvautre]', 'Qui fait la revue ?');
  await f.dire('« <b>Qui fait la revue ?</b> » : les personnes qui ont fait des tâches aujourd’hui, ou « Autre… ».', 5000);
  await B.cadre(f, '#rv .rv-pied', 'Commencer · Plus tard');
  await f.dire('« <b>Commencer</b> » ouvre la première question ; « <b>Plus tard</b> » referme la revue.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv .rv-pied [data-rvfermer]', { apres: 900 });
  await f.dire('La revue n’est pas une checklist de l’équipe : elle n’apparaît que dans sa vignette.', 4800);
  await f.taire(150);

  /* Faire la revue du soir */
  await f.fonction({ id: 'barre-soir-faire', titre: 'Faire la revue du soir',
    resume: 'Qui fait la revue, « Commencer », puis une réponse par écran : chaque checklist pas finie, chaque point de gestion ; ‹ revient en arrière. Chaque réponse s’enregistre tout de suite.',
    points: ['Qui fait la revue', '« Commencer »', 'Une réponse par écran', '‹ pour revenir'] });
  await f.cliquer('#dash .db-avs [data-rvouvrir]', { apres: 1400 });
  await rep('data-rvpar', 'Julie M.', 500);
  await f.cliquer('#rv [data-rvsuivant]', { apres: 800 });
  await f.dire('Chaque checklist pas finie : <b>pourquoi ?</b> Une raison, en un tap ; l’écran suivant vient seul.', 1400);
  await rep('data-rvcl', 'Manque de personnel');
  await rep('data-rvcl', 'Pas eu le temps');
  await rep('data-rvcl', 'Fait, pas coché', 900);
  await f.dire('Ce que le serveur a vérifié en ordre : rien à expliquer.', 2600);
  await f.cliquer('#rv [data-rvsuivant]', { apres: 800 });
  await f.dire('Puis chaque point de gestion : le <b>constat</b>, la question, une réponse.', 2400);
  await rep('data-rvpt', 'Clôturées maintenant');
  await rep('data-rvpt', 'Pas eu le temps', 1000);
  await f.cliquer('#rv [data-rvprec]', { apres: 700 });
  await f.dire('<b>‹</b> revient à l’écran d’avant : la réponse se change.', 2400);
  await rep('data-rvpt', 'Faites, pas envoyées');
  await f.taire(100);
  await rep('data-rvpt', 'Oui, au briefing');
  await rep('data-rvpt', 'Oui, clôturée');
  await rep('data-rvpt', 'Passée');
  await rep('data-rvpt', 'Tout est prêt', 1100);
  await B.cadre(f, '#rv .rv-prog', '11 / 11');
  await f.dire('Chaque réponse s’<b>enregistre</b> tout de suite. Reste le mot pour demain, puis la clôture.', 4000);
  await f.cacher(300);

  /* Reprendre une revue plus tard */
  const s = B.sousLaDiapo(f, async p => { await B.soirEtat(p, null); await p.locator('#rv [data-rvfermer]').first().dispatchEvent('click'); });
  await f.fonction({ id: 'barre-soir-reprendre', titre: 'Reprendre une revue plus tard',
    resume: 'On ferme la revue en cours de route : les réponses sont gardées. À la réouverture, « Reprendre » repart avec les réponses déjà données.',
    points: ['Répondre à quelques écrans', 'Fermer : ✕', 'Rouvrir : « Reprendre »'] });
  await s;
  await f.cliquer('#dash .db-avs [data-rvouvrir]', { apres: 1400 });
  await f.cliquer('#rv [data-rvsuivant]', { apres: 800 });
  await rep('data-rvcl', 'Manque de personnel');
  await rep('data-rvcl', 'Pas eu le temps');
  await f.dire('Deux réponses données ; un client arrive : on ferme.', 3000);
  await f.cliquer('#rv .rv-hd [data-rvfermer]', { apres: 1000 });
  await f.montrer('#dash .db-avs [data-rvouvrir]', 'En cours');
  await f.dire('La vignette dit « <b>en cours</b> » : les réponses sont enregistrées.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-rvouvrir]', { apres: 1400 });
  await B.cadre(f, '#rv .rv-pied [data-rvsuivant]', 'Reprendre');
  await f.dire('À la réouverture : « <b>Reprendre</b> », avec le nombre de réponses déjà données.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv .rv-pied [data-rvsuivant]', { apres: 1000 });
  await B.cadre(f, '#rv .rv-rep .rv-chip.on', 'Déjà répondu');
  await f.dire('Chaque écran garde sa réponse : un tap la confirme, et on avance.', 3600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
