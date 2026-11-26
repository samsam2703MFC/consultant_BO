/* La barre du haut › « Revue du soir » › une checklist pas finie (?vue=ops), 26/11/2026 :
 *   onglet    barre-soir-checklist   « Commencer » : une checklist pas finie par écran, ses tâches pas faites à leur
 *                                    heure prévue (« cochée sans photo »), « Pourquoi ? » en un tap, « Autre… »,
 *                                    « + commentaire », la barre d'avancement
 * Aucune fonction en chapitre : répondre est le geste de « Faire la revue du soir » (01-18-soir-resume), montré ici
 * écran par écran. Données fictives : mocks/11_barre_soir.js. Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (rvEcran « cl », rvPasFaites, rvChips, rvBrancher). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-19-soir-checklist', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/revue_soir.php'],
    avant: async p => { await B.soirFilm(p); await B.prioritaires(p); await B.soirEtat(p, null); await B.relire(p); },
  });

  await f.onglet({
    id: 'barre-soir-checklist', titre: 'La revue du soir : une checklist pas finie',
    resume: 'Un écran par checklist pas finie : ses tâches pas faites à leur heure prévue, « Pourquoi ? » en un tap, « Autre… » pour écrire sa raison, « + commentaire ».',
    accroche: 'Pourquoi ce n’est pas fait ?',
    programme: ['Les tâches pas faites', 'Pourquoi ? en un tap', 'Autre… et + commentaire'],
  });
  await f.cliquer('#dash .db-avs [data-rvouvrir]', { apres: 1400 });
  await f.cliquer('#rv [data-rvpar="Thomas D."]', { apres: 500 });
  await f.cliquer('#rv [data-rvsuivant]', { apres: 1000 });
  await B.cadre(f, '#rv .rv-ctx', 'La checklist');
  await f.dire('Après « Commencer » : une <b>checklist pas finie</b> par écran, son code, combien de tâches sont faites.', 5200);
  await B.cadre(f, '#rv .rv-pf', 'Les tâches pas faites');
  await f.dire('Ses <b>tâches pas faites</b>, à leur heure prévue ; « cochée sans photo » quand seule la photo manque.', 5400);
  await B.cadre(f, '#rv .rv-rep', 'Pourquoi ?');
  await f.dire('« <b>Pourquoi ?</b> » : une raison, en un tap.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvcomouvre]', { apres: 500 });
  await f.taper('Camille malade, seule au comptoir de 13 h à 15 h', 45);
  await f.cliquer('#rv [data-rvcomok]', { apres: 700 });
  await B.cadre(f, '#rv .rv-lien', 'Un commentaire');
  await f.dire('« <b>+ commentaire</b> » ajoute un mot, facultatif.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvcl="Manque de personnel"]', { apres: 1000 });
  await f.dire('La réponse s’<b>enregistre</b>, et l’écran suivant vient tout seul.', 3400);
  await B.cadre(f, '#rv .rv-prog', 'Où on en est');
  await f.dire('En haut, la barre dit où on en est : ici, la deuxième question sur onze.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv .rv-rep [data-rvautre]', { apres: 500 });
  await f.taper('Hotte en réparation', 70);
  await B.cadre(f, '#rv .rv-com', 'Autre…');
  await f.dire('« <b>Autre…</b> » : sa propre raison, en quelques mots.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#rv [data-rvautreok]', { apres: 1100 });
  await B.cadre(f, '#rv .rv-ctx', 'La suivante');
  await f.dire('Puis la checklist suivante, jusqu’à la dernière.', 3000);
  await f.cacher(300); await f.taire(150);
  await f.pause(600);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
