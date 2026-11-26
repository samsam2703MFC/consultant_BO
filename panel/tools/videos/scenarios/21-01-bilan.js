/* Assistant IA › l'onglet « Bilan » (?vue=assistant, la page s'ouvre en Bilan), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    assistant-bilan            la question en haut, les quatre tuiles, Mes modèles ; un modèle refait avec les
 *                                        chiffres du jour (lecture), Graphique · Tableau, CSV, Image, PDF
 *   fonction  assistant-poser            taper sa question, « Demander » : résumé, chiffres, graphique, priorités, Creuser
 *   fonction  assistant-question-prete   une tuile, « Comment va ma journée ? »
 *   fonction  assistant-garder-modele    « Garder comme modèle » : le graphique rejoint Mes modèles
 *   fonction  assistant-retirer-modele   la croix d'un modèle
 * Ni « Choisir son assistant » (21-03), ni « Aller à l'écran d'une priorité » (21-02), lectures montrées ailleurs ; les
 * téléchargements (CSV, image, PDF) se montrent dans l'onglet : le fichier, lui, ne se voit pas à l'écran.
 * Données fictives : mocks/71_assistant.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (iaBilan,
 * iaVisuels, iaMesModeles, iaPdf) ou de panel/src/LISEZMOI.md (assistant_ia.php). */
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const f = await Film.ouvrir('21-01-bilan', {
    vue: 'assistant', serie: 'Assistant IA', parent: 'assistant-ia',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/src/assistant_magasin.php', 'panel/src/assistant_ia.php'],
    avant: async p => { await A.lenteur(p.context()); await A.marge(p); },
  });

  /* L'onglet */
  await f.onglet({
    id: 'assistant-bilan', titre: 'Le Bilan',
    resume: 'La question en haut, les quatre tuiles et Mes modèles, puis la réponse : graphique ou tableau, téléchargements, PDF.',
    accroche: 'Une question, une réponse chiffrée',
    programme: ['Poser une question', 'Poser une question toute prête', 'Garder un graphique comme modèle', 'Retirer un modèle'],
  });
  await f.montrer('.ia-page .ia-modes', 'Bilan · Conversation');
  await f.dire('L’<b>Assistant IA</b> s’ouvre en <b>Bilan</b> : une question, sa réponse. La Conversation, elle, garde le fil.', 4800);
  await f.montrer('.ia-page > .ia-h', 'Ce qu’il lit');
  await f.dire('Il lit les chiffres du magasin dans l’ERP, au moment de la question, et le manuel opératoire.', 4400);
  await f.montrer('.ia-tons', 'Votre assistant');
  await f.dire('Au-dessus, l’assistant qui répond ; ses questions toutes prêtes changent avec lui.', 4000);
  await f.montrer('.ia-saisie.haut', 'Votre question');
  await f.dire('En haut, <b>votre question</b>, puis « Demander ».', 3000);
  await f.montrer('.ia-tqs', 'Questions toutes prêtes');
  await f.dire('Dessous, <b>quatre questions toutes prêtes</b>, d’un clic.', 3200);
  await f.montrer('.ia-mods', 'Mes modèles');
  await f.dire('<b>Mes modèles</b> : les graphiques que vous avez gardés. Un clic en refait un avec les chiffres du jour.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-mods [data-iamodo="3"]', { apres: 1200 });
  await f.montrer('.ia-rep .ia-vis', 'Refait à 15 h 40');
  await f.dire('Ses ventes heure par heure, relues à l’instant : la période et la source sont notées.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-rep .ia-vis [data-v=tableau]', { apres: 700 });
  await f.montrer('.ia-rep .ia-vis', 'Graphique · Tableau');
  await f.dire('« <b>Tableau</b> » montre les mêmes chiffres en lignes ; « Graphique » revient au dessin.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-rep .ia-vis [data-v=graphe]', { apres: 700 });
  await f.montrer('.ia-rep .ia-vis-act', 'Télécharger');
  await f.dire('« <b>Tableau (CSV)</b> » et « <b>Image</b> » le téléchargent sur l’ordinateur.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.ia-rep .ia-pied', 'PDF');
  await f.cliquer('.ia-rep [data-iapdf]', { apres: 200 });
  await f.dire('« <b>PDF</b> » : la réponse en page A4, graphiques compris, qui se télécharge.', 4000);
  await f.dire('Et « Continuer en conversation » garde la réponse dans le fil.', 3200);
  await f.taire(150); await f.cacher(300);

  /* Poser une question */
  await A.sousLaDiapo(f);
  await f.fonction({ id: 'assistant-poser', titre: 'Poser une question',
    resume: 'Taper sa question en haut, « Demander » : l’assistant lit les chiffres du magasin, puis répond — résumé, chiffres, graphique, priorités.',
    points: ['Taper la question en haut', '« Demander »', 'Lire la réponse'] });
  await f.cliquer('.ia-saisie.haut input', { apres: 200 });
  await f.taper('Quel est mon jour le plus faible, et pourquoi ?', 38);
  await f.cliquer('.ia-saisie.haut .ia-envoi', { apres: 300 });
  await f.dire('Mr. Basile <b>lit les chiffres</b> du magasin…', 2000);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer('.ia-rep-h', 'La réponse');
  await f.dire('Le titre, l’assistant qui répond, et l’heure où les chiffres ont été lus.', 3800);
  await f.montrer('.ia-rep .ia-k', 'Les chiffres');
  await f.dire('Le résumé, puis les <b>chiffres clés</b>, tirés des ventes du magasin.', 3600);
  await f.montrer('.ia-rep .ia-vis', 'Le graphique');
  await f.dire('Un <b>graphique</b> quand il aide : le chiffre moyen de chaque jour, face au réseau.', 4000);
  await f.cacher(150);
  await f.montrerTout('.ia-rep .ia-prio', 'Les priorités');
  await f.dire('Les <b>priorités</b>, chacune avec l’écran où agir.', 3200);
  await f.cacher(150);
  await f.montrer('.ia-rep .ia-suites', 'Creuser');
  await f.dire('« Creuser » propose la suite. La question et sa réponse sont gardées.', 3800);
  await f.taire(150); await f.cacher(300);
  await f.pause(600);

  /* Poser une question toute prête */
  await A.sousLaDiapo(f);
  await f.fonction({ id: 'assistant-question-prete', titre: 'Poser une question toute prête',
    resume: 'Un clic sur une des quatre tuiles : la question part telle quelle, et sa réponse prend la place de la précédente.',
    points: ['Choisir une tuile', 'Lire la réponse'] });
  await f.montrer('.ia-tqs', 'Les tuiles');
  await f.dire('Quatre questions toutes prêtes : un clic suffit.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.ia-tq', { hasText: 'Comment va ma journée' }), { apres: 300 });
  await f.dire('Il lit les chiffres de la journée…', 1800);
  await A.reponse(f);
  await f.taire(200);
  await f.montrer('.ia-tq.on', 'La tuile choisie');
  await f.dire('La tuile choisie reste marquée.', 2600);
  await f.montrer('.ia-rep .ia-k', 'À 15 h 40');
  await f.dire('La journée à cette heure : <b>88 %</b> de l’objectif, +18 % face à hier à la même heure.', 4400);
  await f.montrer('.ia-rep .ia-vis', 'Heure par heure');
  await f.dire('Heure par heure, face à jeudi dernier.', 3000);
  await f.taire(150); await f.cacher(300);

  /* Garder un graphique comme modèle */
  await f.fonction({ id: 'assistant-garder-modele', titre: 'Garder un graphique comme modèle',
    resume: '« Garder comme modèle », sous un graphique : il rejoint Mes modèles, d’où un clic le refait avec les chiffres du jour.',
    points: ['Sous le graphique', '« Garder comme modèle »', 'Il rejoint Mes modèles'] });
  await f.montrer('.ia-rep .ia-vis', 'Un graphique utile');
  await f.dire('Ce graphique, on le reverra chaque jour : on le garde.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-rep [data-iamod]', { apres: 1000 });
  await f.montrer('.ia-rep .ia-vis-act', 'Gardé');
  await f.dire('« <b>Garder comme modèle</b> » : gardé dans Mes modèles.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-3000);
  await f.montrer('.ia-mods', 'Mes modèles');
  await f.dire('Il est en tête de <b>Mes modèles</b>, ici et dans la conversation : un clic le refait avec les chiffres du jour.', 5200);
  await f.taire(150); await f.cacher(300);

  /* Retirer un modèle */
  await f.fonction({ id: 'assistant-retirer-modele', titre: 'Retirer un modèle',
    resume: 'La croix à côté d’un modèle le retire de Mes modèles ; les autres restent.',
    points: ['Mes modèles', 'La croix à côté du modèle'] });
  await f.montrer('.ia-mods', 'Mes modèles');
  await f.dire('Un modèle qui ne sert plus…', 2400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-mods [data-iamodx="3"]', { apres: 1000 });
  await f.montrer('.ia-mods', 'Retiré');
  await f.dire('… sort de Mes modèles d’un clic sur sa croix. Les autres restent.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.ia-page .ia-modes [data-iamode=a]', { apres: 1000 });
  await f.montrer(f.p.locator('.ia-q .ia-qb[data-iamodo]').first(), 'Mes modèles');
  await f.dire('En conversation aussi, à gauche : il n’y est plus.', 3200);
  await f.taire(150); await f.cacher(300);
  await f.pause(1200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
