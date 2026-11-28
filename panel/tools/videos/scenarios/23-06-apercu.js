/* Mon coffre-fort › l'aperçu d'un document (le tiroir à droite, d'un clic sur un document ou une pièce reçue), et sa
 * fonction en chapitre (26/11/2026) :
 *   onglet    coffre-apercu   la page, les gestes (Ouvrir, Télécharger, Joindre à un e-mail, Remplacer ou Relever les
 *                             dates), les repères, les grandes dates, « Qui l'a ouvert » ; × ou Échap le ferme
 *   fonction  coffre-joindre  « Joindre à un e-mail » : la Boîte mail s'ouvre sur l'e-mail, le document joint ; on écrit,
 *                             on envoie ; au coffre, la trace « joint à un mail »
 * Ouvrir et Télécharger sont des gestes de lecture (dans le chapitre de l'onglet) : le nouvel onglet du navigateur
 * qu'ouvre « Ouvrir » ne se filme pas, on montre la trace qu'il laisse. Avant de filmer, la boîte du magasin est ouverte
 * sur l'appareil (mocks/70_boite.js). Données fictives : mocks/23_coffre.js. Chaque phrase vient de
 * panel/public/dashboard/coffre.js (tiroir, ouvert, data-cf-mail) ou de panel/public/dashboard/boite.js (nouvelEcrit). */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-06-apercu', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort',
    sources: C.SOURCES.concat(['panel/public/dashboard/boite.js']),
    avant: async p => {
      await C.marge(p);
      // « Ouvrir » ouvre un nouvel onglet du navigateur : il ne se filme pas ; on le ferme dès que le document est servi.
      p.context().on('page', async pg => { if (pg === p) { return; } await pg.waitForLoadState().catch(() => null); await pg.close().catch(() => null); });
      await C.poster(p, '/dashboard/boite/ouvrir', { motDePasse: 'demo' });
      await p.reload(); await p.waitForTimeout(2500);
      await p.evaluate(() => window.__film.prets());
    } });

  /* L'onglet */
  await f.onglet({
    id: 'coffre-apercu', titre: 'L’aperçu d’un document',
    resume: 'Un clic sur un document ouvre son aperçu à droite : la page, ses gestes, ses repères, ses grandes dates, et qui l’a ouvert.',
    accroche: 'Tout sur un document', programme: ['Joindre un document à un e-mail'],
  });
  await f.cliquer('.cf2-l[data-cf-sel="d112"]', { apres: 1500 });
  await f.montrer('.cf2-tir .cf2-page', 'La page');
  await f.dire('Un clic sur un document : son <b>aperçu</b> s’ouvre à droite, la page d’abord.', 4000);
  await f.montrer('.cf2-tir .cf2-acts', 'Les gestes');
  await f.dire('Ses gestes : <b>Ouvrir</b>, <b>Télécharger</b>, <b>Joindre à un e-mail</b>, et <b>Remplacer</b> pour une pièce à vous.', 5400);
  await f.montrer('.cf2-tir .cf2-kv', 'Les repères');
  await f.dire('Ses repères : la date, l’échéance — à renouveler dans 42 jours —, la pièce, qui l’a déposée.', 5000);
  await f.montrer('.cf2-tir .cf2-gdl', 'Ses grandes dates');
  await f.dire('Ses <b>grandes dates</b>.', 2400);
  await f.montrerTout('.cf2-tir .cf2-tr', 'Qui l’a ouvert');
  await f.dire('Et <b>qui l’a ouvert</b> : chaque ouverture, chaque téléchargement, chaque envoi est noté.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-tir .cf2-acts a:not([target])', { apres: 1900 });
  await f.montrer('.cf2-tir .cf2-tr', 'Téléchargé');
  await f.dire('« <b>Télécharger</b> » : le fichier arrive sur l’appareil, et la ligne s’ajoute.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-tir .cf2-acts a[target]', { apres: 1900 });
  await f.montrer('.cf2-tir .cf2-tr', 'Ouvert');
  await f.dire('« <b>Ouvrir</b> » l’affiche en entier dans un nouvel onglet : noté aussi.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.survoler('.cf2-tir .h .x', { pause: 300 });
  await f.dire('× ou <b>Échap</b> referment l’aperçu.', 2600);
  await f.cliquer('.cf2-tir .h .x', { apres: 900 });
  await f.taire(150);
  await f.cliquer('.cf2-d[data-cf-dos="contrat"]', { apres: 900 });
  await f.cliquer('.cf2-p[data-cf-sel="d102"]', { apres: 1500 });
  await f.montrer('.cf2-tir .cf2-acts', 'Relever les dates');
  await f.dire('Un document de votre consultant : « <b>Relever les dates</b> » à la place de « Remplacer ».', 4600);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Escape');
  await f.pause(900);

  /* Joindre à un e-mail */
  await f.fonction({ id: 'coffre-joindre', titre: 'Joindre un document à un e-mail',
    resume: '« Joindre à un e-mail » ouvre la Boîte mail sur un e-mail, le document en pièce jointe et l’objet rempli ; on écrit, puis on envoie. Le coffre note « joint à un mail ».',
    points: ['Ouvrir l’aperçu du document', '« Joindre à un e-mail »', 'Le destinataire, un mot', '« Envoyer »'] });
  await f.cliquer('.cf2-d[data-cf-dos="legal"]', { apres: 700 });
  await f.cliquer('.cf2-p[data-cf-sel="d112"]', { apres: 1200 });
  await f.cliquer('.cf2-tir [data-cf-mail]', { apres: 2200 });
  await f.montrer('.bx-pieces', 'La pièce jointe');
  await f.dire('La <b>Boîte mail</b> s’ouvre sur un e-mail : l’objet rempli, le document du coffre joint.', 4600);
  await f.cacher(150);
  await f.dire('Le destinataire, un mot, puis <b>Envoyer</b>.', 0);
  await f.cliquer('#bx-a', { apres: 100 });
  await f.taper('assurances@exemple.be', 40);
  await f.cliquer('#bx-texte', { dy: 0.15, apres: 100 });
  await f.taper('Bonjour,\n\nVoici notre attestation RC 2026.\n\nCamille R.', 30);
  await f.taire(150);
  await f.cliquer('.bx-ecrire [data-bx="envoyer"]', { apres: 1400 });
  await f.montrer('.bx-ok', 'Envoyé');
  await f.dire('<b>Envoyé</b>, depuis l’adresse du magasin.', 2600);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '#db-rail .db-rail-coffre .sidebar__item', 1200);
  // CONTOURNEMENT : revenu par le rail, le coffre ne se relit pas (dashboard.js:162, charger(false) ; coffre.js:430 ne
  // relit pas après « Joindre à un e-mail », comme ouvert() le fait après Ouvrir) : la trace n'apparaîtrait qu'au
  // rechargement de la page. On le relit pour le film.
  await f.p.evaluate(() => window.DashCoffre && window.DashCoffre.relire());
  await f.pause(700);
  await f.cliquer('.cf2-p[data-cf-sel="d112"]', { apres: 1500 });
  await f.montrer('.cf2-tir .cf2-tr', 'Joint à un mail');
  await f.dire('Au coffre, l’envoi est noté : « <b>joint à un mail</b> », par Camille R.', 4000);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
