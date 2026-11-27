/* Mon coffre-fort › « Grandes dates » (?vue=coffre : la barre du haut, le menu de gauche, « Prochaine grande date »
 * dans le résumé, ou un point de la frise), et ses fonctions en chapitres (26/11/2026) :
 *   onglet    coffre-dates          toutes les échéances, par année, avec leur origine ; en or, les 4 prochains mois ;
 *                                   les dates passées repliées (« ▸ 1 date passée », un geste de lecture)
 *   fonction  coffre-date-ajouter   « Ajouter une date » : la sorte, le titre, la date, la note, « Ajouter »
 *   fonction  coffre-date-retirer   « × » au bout d'une date rangée par le magasin, puis confirmer
 * Avant de filmer, le bail est déposé et ses grandes dates rangées (comme à la fin de la vidéo 23 : les dates calculées
 * depuis la prise de cours, l'indexation relevée par l'assistant). Données fictives : mocks/23_coffre.js. Chaque phrase
 * vient de panel/public/dashboard/coffre.js (vueDates, ajouterDate, retirerDate) ou de consultant/src/coffre_dates.php. */
const { Film } = require('../film');
const C = require('./coffre');

/** Hors film : le bail déposé, lu par l'assistant, ses dates cochées rangées avec son échéance. */
async function bailRange(p) {
  await p.evaluate(async api => {
    const J = { Accept: 'application/json', 'Content-Type': 'application/json' };
    const fd = new FormData();
    [['shop', '4'], ['titre', 'Bail commercial'], ['date', '2024-05-15'], ['echeance', ''], ['note', ''], ['piece', 'bail']].forEach(([k, v]) => fd.append(k, v));
    fd.append('fichiers[]', new Blob(['%PDF-1.4\n% bail fictif\n'], { type: 'application/pdf' }), 'bail-commercial-boutique.pdf');
    const d = await (await fetch(api + '/coffre/magasin/document', { method: 'POST', body: fd })).json();
    const id = d.ajoutes[0].id;
    const a = await (await fetch(api + '/coffre/magasin/assistant', { method: 'POST', headers: J, body: JSON.stringify({ shop: '4', id }) })).json();
    const dates = a.propositions.filter(x => x.coche).map(x => ({ sorte: x.sorte, titre: x.titre, le: x.le, fin: x.fin, note: x.note, docId: id, source: x.source }));
    await fetch(api + '/coffre/magasin/dates', { method: 'POST', headers: J, body: JSON.stringify({ shop: '4', dates, echeanceDoc: { docId: id, le: a.echeanceDoc.proposee } }) });
  }, C.API);
  await p.reload(); await p.waitForTimeout(2500);
  await p.evaluate(() => window.__film.prets());
}

module.exports = async function () {
  const f = await Film.ouvrir('23-05-dates', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES,
    avant: async p => { await C.marge(p); await bailRange(p); } });
  const ligne = t => f.p.locator('.cf2-gd', { hasText: t }).first();

  /* L'onglet */
  await f.onglet({
    id: 'coffre-dates', titre: 'Les grandes dates',
    resume: 'Toutes les échéances du coffre, par année, et d’où chacune vient : calculée, relevée par l’assistant, échéance d’un document, ajoutée à la main. En or, les quatre prochains mois.',
    accroche: 'Rien n’échappe', programme: ['Ajouter une grande date', 'Retirer une grande date'],
  });
  await f.montrer('.cf2-barre [data-cf-dos="dates"]', 'Grandes dates');
  await f.dire('Les <b>grandes dates</b> s’ouvrent d’ici, du menu de gauche, de la prochaine grande date…', 4400);
  await f.montrer('.cf2-frise', 'La frise');
  await f.dire('… ou d’un point de la frise.', 2400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-frise .ev', { apres: 1100 });
  await f.montrer('.cf2-cont .cf2-ch', 'Grandes dates');
  await f.dire('Toutes les échéances, <b>par année</b> : triennats du bail, préavis, renouvellements, échéances.', 4800);
  await f.montrerTout('.cf2-gd.bientot', 'Les 4 prochains mois');
  await f.dire('En <b>or</b>, celles des quatre prochains mois, et dans combien de jours.', 3800);
  await f.montrer(ligne('1er triennat'), 'Calculée');
  await f.dire('Chacune dit d’où elle vient : <b>calculée</b> depuis la prise de cours du bail…', 4200);
  await f.montrer(ligne('Indexation du loyer'), 'Relevée par l’assistant');
  await f.dire('… <b>relevée par l’assistant</b> dans le document…', 3000);
  await f.montrer(ligne('Contrôle AFSCA annuel'), 'Ajoutée à la main');
  await f.dire('… <b>ajoutée</b> à la main, avec sa note…', 2800);
  await f.montrer(ligne('Assurance incendie'), 'Échéance du document');
  await f.dire('… ou l’<b>échéance</b> posée sur un document : son titre ouvre son aperçu.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-passees]', { apres: 900 });
  await f.montrer(f.p.locator('.cf2-gd.passee').first(), 'Passée');
  await f.dire('En bas, les <b>dates passées</b>, repliées : un clic les déplie.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-passees]', { apres: 700 });
  await C.enHaut(f);

  /* Ajouter une date */
  await f.fonction({ id: 'coffre-date-ajouter', titre: 'Ajouter une grande date',
    resume: '« Ajouter une date » : la sorte, le titre, la date (et « jusqu’au »), le document et une note si besoin, puis « Ajouter ».',
    points: ['« Ajouter une date »', 'La sorte, le titre, la date', 'Une note si besoin', '« Ajouter »'] });
  await f.cliquer('[data-cf-ajout]', { apres: 900 });
  await f.montrer('.cf2-ajout', 'Ajouter une date');
  await f.dire('Une date qu’aucun document ne donne : le contrôle des extincteurs.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-cf-a="sorte"]', { pause: 300 });
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator('[data-cf-a="sorte"]').selectOption('autorisation');
  await f.pause(600);
  await f.cliquer('[data-cf-a="titre"]', { apres: 200 });
  await f.taper('Contrôle des extincteurs', 60);
  await C.taperDate(f, '[data-cf-a="le"]', '2027-01-15');
  await f.cliquer('[data-cf-a="note"]', { apres: 200 });
  await f.taper('Avec la société de maintenance.', 50);
  await f.montrer('.cf2-ajout', 'La sorte, le titre, la date');
  await f.dire('La sorte, le titre, la date ; « jusqu’au » pour une fenêtre, et le document lié, si besoin.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-a-ok]', { apres: 1300 });
  await f.montrer('.cf2-flash', 'Rangée');
  await f.dire('<b>Date rangée</b> : elle prend sa place dans l’année.', 3800);
  await f.cacher(150); await C.apresFlash(f);
  await f.montrer(ligne('Contrôle des extincteurs'), 'Ajoutée par Camille R.');
  await f.dire('Le 15 janvier 2027, ajoutée par Camille R., avec sa note.', 3600);
  await f.cacher(300); await f.taire(200);

  /* Retirer une date */
  await f.fonction({ id: 'coffre-date-retirer', titre: 'Retirer une grande date',
    resume: 'La croix au bout d’une date rangée par le magasin, puis confirmer. L’échéance d’un document n’en a pas : elle suit le document.',
    points: ['« × » au bout de la date', 'Confirmer'] });
  await f.montrer(ligne('Contrôle des extincteurs'), 'Contrôle des extincteurs');
  await f.dire('Une date rangée par le magasin se retire : la <b>croix</b>, au bout de sa ligne.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme(ligne('Contrôle des extincteurs').locator('[data-cf-retire]'), 'Retirer « Contrôle des extincteurs » des grandes dates ?', '', { apres: 1300 });
  await f.montrer('.cf2-flash', 'Retirée');
  await f.dire('<b>Date retirée</b> : elle quitte la liste et la frise.', 3600);
  await f.cacher(150); await C.apresFlash(f);
  await f.montrer(ligne('Assurance incendie'), 'Sans croix');
  await f.dire('L’échéance d’un document n’a pas de croix : elle suit le document.', 3800);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
