/* Mon coffre-fort › « Relever les grandes dates » (« ✦ Relever les dates » dans l'aperçu d'un document, ou « ✦ relire »
 * sous ses grandes dates), et sa fonction en chapitre (26/11/2026) :
 *   onglet    coffre-releve    la fenêtre du dépôt, sur un document déjà rangé : l'assistant relit, propose ; ce qu'il
 *                              signale ; « Valider » quand rien n'est coché ; « Fermer »
 *   fonction  coffre-relever   le contrat de franchise : « Relever les dates », garder les dates cochées, « Ranger et
 *                              N grandes dates » ; elles apparaissent dans son aperçu
 * Données fictives : mocks/23_coffre.js (la lecture du contrat de franchise). Chaque phrase vient de
 * panel/public/dashboard/coffre.js (tiroir, relever, fenetre, rangerDepot). */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-09-relever', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES,
    avant: async p => { await C.marge(p); await C.lenteur(p, 2600); } });

  /* L'onglet */
  await f.onglet({
    id: 'coffre-releve', titre: 'Relever les grandes dates',
    resume: 'La fenêtre du dépôt, sur un document déjà rangé : l’assistant le relit et propose ses grandes dates ; on garde celles qui sont cochées, puis « Ranger », ou « Valider ».',
    accroche: 'Un document relu', programme: ['Relever les grandes dates d’un document rangé'],
  });
  await f.cliquer('.cf2-d[data-cf-dos="legal"]', { apres: 800 });
  await f.cliquer('.cf2-p[data-cf-sel="d112"]', { apres: 1400 });
  await f.montrer('.cf2-tir .cf2-k:has([data-cf-releve])', 'relire');
  await f.dire('Une pièce à vous qui a des grandes dates : « <b>✦ relire</b> », au-dessus d’elles.', 4200);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-tir .h .x', 800);
  await f.cliquer('.cf2-d[data-cf-dos="contrat"]', { apres: 800 });
  await f.cliquer('.cf2-p[data-cf-sel="d102"]', { apres: 1400 });
  await f.montrer('.cf2-tir [data-cf-releve]', 'Relever les dates');
  await f.dire('Les autres documents lisibles : « <b>✦ Relever les dates</b> », dans l’aperçu.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-tir [data-cf-releve]', { apres: 600 });
  await C.cadreFixe(f, '.cf2-mod .h', 'Relever les grandes dates');
  await f.dire('La même fenêtre que pour déposer, sur un document déjà dans votre coffre.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.p.waitForSelector('.cf2-mod .cf2-res', { timeout: 10000 });
  await C.cadreFixe(f, '.cf2-mod .d', 'L’assistant relit');
  await f.dire('L’assistant le <b>relit</b> : un contrat de franchise de neuf ans, et deux grandes dates à venir.', 4800);
  await f.montrer('.cf2-mod .cf2-pas', 'Déjà passée');
  await f.dire('La prise d’effet, passée, n’est pas cochée.', 3000);
  await f.montrer('.cf2-mod .cf2-rq', 'À vérifier');
  await f.dire('En orange, ce qu’il <b>signale</b> : ici, une clause à relire avec votre consultant.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(f.p.locator('.cf2-mod label.cf2-pp input[type=checkbox]').nth(0), { apres: 500 });
  await f.cliquer(f.p.locator('.cf2-mod label.cf2-pp input[type=checkbox]').nth(1), { apres: 700 });
  await C.cadreFixe(f, '.cf2-mod .f', 'Valider');
  await f.dire('Rien de coché ? Le bouton devient « <b>Valider</b> » : rien ne change.', 3800);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod .f [data-cf-depot-ferme]', 900);
  await f.dire('« Fermer » referme la fenêtre ; le document reste où il est.', 3200);
  await f.taire(200);

  /* Relever les grandes dates d'un document rangé */
  await f.fonction({ id: 'coffre-relever', titre: 'Relever les grandes dates d’un document rangé',
    resume: '« ✦ Relever les dates » dans l’aperçu : l’assistant relit le document ; on garde les dates cochées, puis « Ranger et N grandes dates ».',
    points: ['L’aperçu du document', '« ✦ Relever les dates »', 'Garder les dates cochées', '« Ranger et N grandes dates »'] });
  await f.cliquer('.cf2-p[data-cf-sel="d102"]', { apres: 1400 });
  await f.cliquer('.cf2-tir [data-cf-releve]', { apres: 600 });
  await f.dire('Le contrat de franchise : « <b>✦ Relever les dates</b> ». L’assistant le relit.', 3600);
  await f.taire(150);
  await f.p.waitForSelector('.cf2-mod .cf2-res', { timeout: 10000 });
  await f.montrerTout('.cf2-mod .d label.cf2-pp', 'Deux grandes dates');
  await f.dire('Le dernier jour pour s’opposer à la <b>reconduction</b>, et la <b>fin du contrat</b> : on les garde.', 4800);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod [data-cf-range]', 1500);
  await f.montrer('.cf2-flash', 'Rangé');
  await f.dire('<b>Rangé</b>, avec deux grandes dates.', 2800);
  await f.cacher(150); await C.apresFlash(f);
  await f.cliquer('.cf2-p[data-cf-sel="d102"]', { apres: 1400 });
  await f.montrerTout('.cf2-tir .cf2-gdl', 'Ses grandes dates');
  await f.dire('Dans son aperçu, ses <b>grandes dates</b> : 28 février 2032, 28 février 2033.', 4400);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
