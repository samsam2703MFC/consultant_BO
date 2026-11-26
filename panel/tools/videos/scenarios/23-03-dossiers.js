/* Mon coffre-fort › les dossiers remplis pour le magasin : Contrat & DIP, Formation, Finances, Courriers & réunions
 * (?vue=coffre, à gauche) (26/11/2026). Les quatre marchent de la même façon (votre consultant les remplit, ou ils se
 * remplissent d'office) : une vidéo pour les quatre, sans fonction (on y lit, on n'y dépose pas).
 *   onglet    coffre-dossiers   Contrat & DIP (4 sur 4, l'échéance du contrat), Formation (rangés d'office), Finances
 *                               (le dossier d'analyse, le budget sans aperçu, les redevances), Courriers & réunions
 * Données fictives : mocks/23_coffre.js. Chaque phrase vient de panel/public/dashboard/coffre.js (DOS, vueDossier,
 * tiroir) ou de consultant/src/coffre.php (les pièces attendues). */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-03-dossiers', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES, avant: C.marge });

  await f.onglet({
    id: 'coffre-dossiers', titre: 'Contrat & DIP, Formation, Finances, Courriers',
    resume: 'Les dossiers que votre consultant remplit, ou qui se remplissent d’office : leurs pièces attendues, puis leurs autres documents, chacun en aperçu d’un clic.',
    accroche: 'Rangés pour vous',
  });
  await f.montrer('.cf2-dos', 'Les dossiers');
  await f.dire('Contrat & DIP, Formation, Finances, Courriers : ils se remplissent <b>pour vous</b>, par votre consultant ou d’office.', 5600);
  await f.dire('Les quatre marchent de la même façon.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-d[data-cf-dos="contrat"]', { apres: 1000 });
  await f.montrer('.cf2-cont .cf2-ch', 'Contrat & DIP');
  await f.dire('<b>Contrat & DIP</b> : déposés par votre consultant.', 3200);
  await f.montrer('.cf2-att', 'Pièces attendues · 4 sur 4');
  await f.dire('Le DIP, le contrat signé, ses annexes, le dossier d’implantation : <b>4 sur 4</b>.', 4400);
  await f.montrer('.cf2-p[data-cf-sel="d102"]', 'Le contrat');
  await f.dire('Le contrat va jusqu’au <b>28/02/2033</b> : son échéance est aussi une grande date.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-d[data-cf-dos="formation"]', { apres: 1000 });
  await f.montrer('.cf2-att', 'Formation');
  await f.dire('<b>Formation</b> : l’attestation de formation initiale, puis les formations continues validées, rangées d’office.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-d[data-cf-dos="finances"]', { apres: 1000 });
  await f.montrer('.cf2-att', 'Finances');
  await f.dire('<b>Finances</b> : le dossier d’analyse du mois, le budget de l’année, le relevé des redevances.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-p[data-cf-sel="d131"]', { apres: 1200 });
  await C.cadreFixe(f, '.cf2-tir .cf2-page', 'Pas d’aperçu');
  await f.dire('Le budget est un tableur : <b>pas d’aperçu</b>, on l’ouvre ou on le télécharge.', 4200);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-tir [data-cf-ferme].x', 800);
  await f.cliquer('.cf2-p[data-cf-sel="d130"]', { apres: 1400 });
  await C.cadreFixe(f, '.cf2-tir .cf2-page', 'Dossier d’analyse : octobre 2026');
  await f.dire('Le <b>dossier d’analyse</b> d’octobre, nouveau : un clic l’ouvre en aperçu.', 4000);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-tir [data-cf-ferme].x', 800);
  await f.cliquer('.cf2-d[data-cf-dos="courriers"]', { apres: 1000 });
  await f.montrer('.cf2-cont .cf2-carte', 'Courriers & réunions');
  await f.dire('<b>Courriers & réunions</b> : les comptes rendus de réunion et les courriers échangés avec le réseau.', 5000);
  await f.dire('Pas de pièce attendue ici : le dossier est libre.', 3000);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
