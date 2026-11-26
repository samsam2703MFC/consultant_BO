/* La barre du haut › « Demande au bureau » (?vue=ops : la fenêtre de l'assistant, mode « Demande au bureau »), 26/11/2026 :
 *   onglet    barre-bureau            le formulaire transmis par l'avatar, et « Mes demandes » (Toutes · Ouvertes ·
 *                                     Répondues) : suivre ses demandes, lire la réponse du bureau (geste de lecture)
 *   fonction  barre-bureau-demander   le sujet, le titre, la demande, une photo, un passage du consultant, « Envoyer au
 *                                     bureau » : la demande rejoint « Mes demandes »
 * Données fictives : mocks/11_barre_bureau.js (quatre demandes d'exemple ; GET installé sur la page, 00_commun.js le
 * sert vide). La photo : une illustration de la fausse API (lib/taches.js, « croissants »), choisie par le sélecteur de
 * fichier. Chaque phrase vient de panel/public/dashboard/dashboard.js (hdVue, hdCarte, hdLier). */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  let PHOTO = null;
  const f = await Film.ouvrir('01-17-bureau', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'consultant/src/helpdesk.php'],
    avant: async p => { await B.prioritaires(p); PHOTO = await B.photo(p, 'croissants'); await B.relire(p); },
  });

  await f.onglet({
    id: 'barre-bureau', titre: 'Demande au bureau',
    resume: 'La vignette « Demande au bureau » : le formulaire que l’assistant transmet au bureau, et « Mes demandes » — Toutes, Ouvertes, Répondues — avec la réponse du bureau.',
    accroche: 'Une question pour le siège',
    programme: ['Le formulaire', 'Mes demandes', 'La réponse du bureau', 'Poser une demande au bureau'],
  });
  await f.montrer('#dash .db-avs [data-iaouvrir="d"]', 'Demande au bureau');
  await f.dire('La vignette « <b>Demande au bureau</b> » compte les demandes encore ouvertes.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-iaouvrir="d"]', { apres: 1300 });
  await f.montrer('.hd-qui', 'L’assistant transmet');
  await f.dire('Elle s’ouvre dans la fenêtre de l’<b>assistant</b>, qui transmet votre demande au bureau. La réponse revient ici.', 5600);
  await B.cadre(f, '.hd-form', 'Le formulaire');
  await f.dire('À gauche, le <b>formulaire</b> : le sujet, le titre, la demande, des photos, un passage du consultant, l’urgence.', 5600);
  await B.cadre(f, '.hd-hist', 'Mes demandes');
  await f.dire('À droite, « <b>Mes demandes</b> » ; elle s’ouvre sur les demandes encore ouvertes.', 4400);
  await f.montrerTout('.hd-hist .rc-carte .rep', 'Où en est chacune');
  await f.dire('Chacune dit où elle en est : pas encore lue, en cours avec la date de réponse prévue, ou reprise dans la visite du consultant.', 6400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.hd-hist [data-hdfiltre="rep"]', { apres: 900 });
  await f.montrer('.hd-hist .rc-carte', 'Répondue');
  await f.dire('« <b>Répondues</b> » : la réponse du bureau, avec sa date.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.hd-hist [data-hdfiltre="toutes"]', { apres: 900 });
  await f.dire('« <b>Toutes</b> » : les ouvertes et les fermées, la plus récente en tête.', 3600);
  await f.taire(150);
  await f.cliquer('.ia-x', { apres: 800 });

  /* Poser une demande */
  await f.fonction({ id: 'barre-bureau-demander', titre: 'Poser une demande au bureau',
    resume: 'Le sujet, le titre, la demande, une photo, un passage du consultant ou non, urgent ou non, puis « Envoyer au bureau » : la demande rejoint « Mes demandes ».',
    points: ['Le sujet', 'Le titre et la demande', 'Une photo, un passage du consultant', '« Envoyer au bureau »'] });
  await f.cliquer('#dash .db-avs [data-iaouvrir="d"]', { apres: 1100 });
  await f.cliquer('[data-hddom="operational_activities"]', { apres: 600 });
  await f.cliquer('#hd-titre', { apres: 200 });
  await f.taper('Croissants trop cuits sur la plaque du bas', 55);
  await f.cliquer('#hd-desc', { apres: 200 });
  await f.taper('Depuis mardi, la plaque du bas sort plus foncée que les autres. Faut-il régler le four ?', 24);
  await f.montrer('.hd-form', 'Le sujet, le titre, la demande');
  await f.dire('Le <b>sujet</b>, un titre en quelques mots, puis la demande, le plus précisément possible.', 3400);
  await f.cacher(150); await f.taire(150);
  f.p.once('filechooser', fc => fc.setFiles(PHOTO).catch(() => null));
  await f.cliquer('[data-hdphoto]', { apres: 1400 });
  await f.montrer('.hd-form .rc-photos', 'Une photo');
  await f.dire('« <b>Ajouter</b> » : une photo, jusqu’à quatre.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-hdpass="1"]', { apres: 500 });
  await f.montrerTout('.hd-form [data-hdpass], .hd-form [data-hdurg]', 'Un passage ? Urgent ?');
  await f.dire('Un <b>passage du consultant</b> : la demande sera reprise à sa visite. Urgente, le bureau est prévenu par SMS.', 4900);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-hdenvoyer]', { apres: 1600 });
  await f.montrer('.hd-ok', 'Envoyée');
  await f.dire('<b>Envoyée</b>, avec sa référence : elle rejoint « Mes demandes », où la réponse s’affichera.', 4600);
  await f.montrer('.hd-hist .rc-carte >> nth=0', 'Mes demandes');
  await f.pause(1600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
