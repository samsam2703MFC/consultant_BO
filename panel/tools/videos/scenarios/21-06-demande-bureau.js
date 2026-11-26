/* Assistant IA › « Demande au bureau » (la fenêtre de l'assistant, son troisième onglet ; depuis le 22/11, sa vignette
 * n'est plus que sur l'Opérationnel), et sa fonction en chapitre (26/11/2026) :
 *   onglet    assistant-demande-bureau    la vignette, la fenêtre, l'assistant qui transmet, le formulaire ; « Mes
 *                                         demandes » (lecture) : Ouvertes, Toutes, Répondues, le statut, l'échéance, la
 *                                         reprise dans une visite, la réponse du bureau
 *   fonction  assistant-envoyer-demande   le sujet, le titre, la demande, une photo, un passage du consultant, urgent ou
 *                                         non, « Envoyer au bureau » : la demande rejoint Mes demandes
 * Données fictives : mocks/71_assistant_helpdesk.js (00_commun.js rend GET /helpdesk/magasin vide avant lui : la lecture
 * passe par A.helpdesk) ; la photo est l'illustration des croissants de mocks/lib/taches.js, tirée en JPEG avant de
 * filmer. Chaque phrase vient de dashboard.js (hdVue, hdCarte, hdLier, hdReduire) ou de consultant/src/helpdesk.php
 * (wr_helpdesk_magasin : urgent → SMS au bureau). */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { Film } = require('../film');
const A = require('./assistant');

module.exports = async function () {
  const dossier = fs.mkdtempSync(path.join(os.tmpdir(), 'film-hd-'));
  const PHOTO = path.join(dossier, 'croissants-plaque-du-bas.jpg');
  const f = await Film.ouvrir('21-06-demande-bureau', {
    vue: 'assistant', serie: 'Assistant IA', parent: 'assistant-ia',
    sources: ['panel/public/dashboard/dashboard.js', 'consultant/src/helpdesk.php'],
    avant: async p => {
      await A.helpdesk(p.context());
      // La « photo » : l'illustration des croissants (fausse API), en JPEG, comme une photo de téléphone.
      await A.jpeg(p, '/__photo/croissants.svg', PHOTO);
      await p.click('#db-rail .sidebar__item[data-vue=ops]');
      await p.waitForTimeout(2600);
      await p.evaluate(() => window.scrollTo(0, 0));
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'assistant-demande-bureau', titre: 'La demande au bureau',
    resume: 'L’assistant choisi transmet la demande : sujet, titre, texte, photos, passage du consultant, urgent ; dessous, « Mes demandes » avec statut, échéance et réponse du bureau.',
    accroche: 'Une question au bureau, sa réponse ici',
    programme: ['Envoyer une demande au bureau'],
  });
  await f.montrer('#db-rail .sidebar__item[data-vue=ops]', 'Opérationnel');
  await f.dire('La demande au bureau part de l’<b>Opérationnel</b>…', 2800);
  await f.montrer('.db-avs [data-iaouvrir="d"]', 'Demande au bureau');
  await f.dire('… par sa vignette, en haut : ici, 3 demandes encore ouvertes.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.db-avs [data-iaouvrir="d"]', { apres: 1300 });
  await f.montrer('.ia-modale .ia-modes', 'Demande au bureau');
  await f.dire('La fenêtre de l’assistant s’ouvre sur son troisième onglet, « <b>Demande au bureau</b> ».', 4200);
  await f.montrer('.hd-qui', 'L’assistant transmet');
  await f.dire('L’assistant choisi, ici Mr. Basile, transmet la demande ; la réponse revient ici.', 4200);
  await f.cacher(150); await f.taire(150);
  await A.cadre(f, '.hd-form', 'La demande');
  await f.dire('À gauche, la demande : le sujet, le titre, le texte, des photos, un passage du consultant, urgent ou non.', 5200);
  await f.montrer('.hd-hist .rc-filtres', 'Mes demandes');
  await f.dire('À droite, <b>Mes demandes</b>, ouvertes d’abord ; « Toutes » et « Répondues » à côté.', 4400);
  await f.montrer(f.p.locator('.hd-hist .rc-carte').first(), 'Statut · échéance');
  await f.dire('Chacune dit son statut, et la date de réponse prévue par le bureau.', 3800);
  await f.montrer(f.p.locator('.hd-hist .rc-carte').nth(2), 'Dans une visite');
  await f.dire('Celle-ci est reprise dans la prochaine visite : la réponse viendra après.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-hdfiltre="rep"]', { apres: 800 });
  await f.montrer(f.p.locator('.hd-hist .rc-carte').first(), 'Répondue');
  await f.dire('« <b>Répondues</b> » : la réponse du bureau, avec sa date.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-hdfiltre="ouvertes"]', { apres: 700 });

  /* Envoyer une demande au bureau */
  await f.fonction({ id: 'assistant-envoyer-demande', titre: 'Envoyer une demande au bureau',
    resume: 'Le sujet, le titre, la demande, une photo, un passage du consultant ou non, urgent ou non, puis « Envoyer au bureau » : la demande rejoint Mes demandes.',
    points: ['Le sujet', 'Le titre et la demande', 'Une photo (4 au plus)', 'Passage du consultant ? Urgent ?', '« Envoyer au bureau »'] });
  await f.cliquer('[data-hddom="operational_activities"]', { apres: 600 });
  await f.cliquer('#hd-titre', { apres: 150 });
  await f.taper('Croissants trop colorés sur la plaque du bas', 35);
  await f.cliquer('#hd-desc', { apres: 150 });
  await f.taper('Depuis lundi, les croissants de la plaque du bas sortent trop colorés (contrôle photo : 3 sur 5). Pouvez-vous regarder le four avec nous ?', 13);
  await f.montrerTout('.hd-form .rc-champ:nth-of-type(-n+3)', 'La demande');
  await f.dire('Le sujet, un titre en quelques mots, puis la demande, le plus précisément possible.', 3500);
  await f.cacher(150); await f.taire(150);
  f.p.once('filechooser', fc => fc.setFiles(PHOTO).catch(() => null));
  await f.cliquer('[data-hdphoto]', { apres: 1600 });
  await f.montrer('.hd-form .rc-photos', 'Une photo');
  await f.dire('Une photo, jusqu’à quatre : elle est réduite avant de partir.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-hdpass="1"]', { apres: 600 });
  await f.montrerTout('.hd-form .rc-champ:has([data-hdpass]), .hd-form .rc-champ:has([data-hdurg])', 'Passage · urgent');
  await f.dire('Un passage du consultant : oui, à la prochaine visite. Urgent : non — « Oui, urgent » prévient le bureau par SMS.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-hdenvoyer]', { apres: 1500 });
  await f.montrer('.hd-ok', 'Envoyée');
  await f.dire('« Demande envoyée au bureau », avec sa référence. La réponse s’affichera ici.', 3700);
  await f.montrer(f.p.locator('.hd-hist .rc-carte').first(), 'Mes demandes');
  await f.dire('Elle ouvre Mes demandes : envoyée aujourd’hui, pas encore lue par le bureau.', 4000);
  await f.taire(150); await f.cacher(300);
  await f.pause(600);

  const mp4 = await f.fin();
  fs.rmSync(dossier, { recursive: true, force: true });
  return mp4;
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
