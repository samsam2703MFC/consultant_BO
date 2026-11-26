/* Mon coffre-fort › le coffre vide (il s'affiche seul tant qu'aucun document n'est rangé) (26/11/2026) :
 *   onglet    coffre-vide   « Ce que votre coffre doit contenir » : chaque dossier, ses pièces, qui les dépose, avec
 *                           « Déposer » sur les pièces à vous ; l'état du coffre (0 sur 16) et « À traiter »
 * Pas de fonction : « Déposer » ouvre la fenêtre du dépôt (23-07-deposer). Avant de filmer, le coffre de démo est vidé
 * (mocks/23_coffre.js, demo.vider). Chaque phrase vient de panel/public/dashboard/coffre.js (etat, aFaire, vueVide). */
const path = require('path');
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-10-vide', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES,
    avant: async p => {
      await C.marge(p);
      // Le module de la fausse API que mock.js vient de charger (require le garde en cache) : un coffre sans document.
      require(path.join(__dirname, '../mocks/23_coffre.js')).demo.vider();
      await p.reload(); await p.waitForTimeout(2500);
      await p.evaluate(() => window.__film.prets());
    } });

  await f.onglet({
    id: 'coffre-vide', titre: 'Le coffre vide',
    resume: 'Tant qu’aucun document n’est rangé, le coffre dit ce qu’il doit contenir : chaque dossier, ses pièces, qui les dépose, et « Déposer » sur les vôtres.',
    accroche: 'Ce qu’il doit contenir',
  });
  await f.montrer('.cf2-etat > div:nth-child(1)', 'Votre coffre');
  await f.dire('Un coffre neuf : <b>vide pour l’instant</b>, 0 pièce sur 16.', 3600);
  await f.dire('Votre consultant y dépose le DIP, le contrat, vos rapports de visite ; vous, vos <b>pièces légales</b>.', 5200);
  await f.montrer('.cf2-etat > div:nth-child(2)', 'À traiter');
  await f.dire('<b>À traiter</b> : sept pièces légales à déposer ; le contrat et le DIP arriveront de votre consultant.', 5400);
  await f.montrer('.cf2-proch', 'Grandes dates');
  await f.dire('Les grandes dates viendront avec les documents : l’assistant les relève au dépôt.', 4400);
  await f.montrer('.cf2-carte .cf2-ch', 'Ce que votre coffre doit contenir');
  await f.dire('Dessous : <b>ce que votre coffre doit contenir</b>. 7 pièces à déposer par vous, 8 attendues de votre consultant.', 5800);
  await f.montrer('.cf2-vide .cf2-vc:nth-child(1)', 'Légal');
  await f.dire('<b>Légal</b> : vos pièces, chacune avec « Déposer ».', 3400);
  await f.montrer('.cf2-vide .cf2-vc:nth-child(2)', 'Contrat & DIP, Finances');
  await f.dire('Contrat & DIP, Finances : <b>attendus</b> de votre consultant.', 3600);
  await f.montrer('.cf2-vide .cf2-vc:nth-child(3)', 'Formation, visites, courriers');
  await f.dire('La formation, puis les rapports de visite et les courriers, qui arrivent tout seuls.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-vide [data-cf-depot="statuts"]', { apres: 1100 });
  await C.cadreFixe(f, '.cf2-mod', 'Déposer');
  await f.dire('« <b>Déposer</b> » ouvre la fenêtre du dépôt, la pièce déjà choisie.', 3800);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-mod .f [data-cf-depot-ferme]', 900);
  await f.montrer('.cf2-etat [data-cf-faire="depot:"]', 'Déposer ma première pièce');
  await f.dire('Ou « <b>Déposer ma première pièce</b> », en haut.', 3200);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
