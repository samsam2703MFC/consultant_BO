/* Mon catalogue › l'onglet « 1 · Catalogue » (?vue=catalogue), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    catalogue-catalogues   le résumé et « À corriger », la carte « Nouveau · de la marque », « Le feuilleter », mes catalogues et la suite
 *   fonction  catalogue-prendre      « Le prendre tel quel › » : à la Validation, les étapes 2 et 3 sous cadenas
 *   fonction  catalogue-adapter      « L'adapter à ma boutique » : une copie à moi, reprise à l'étape 2
 *   fonction  catalogue-supprimer    « Supprimer » sur sa ligne, confirmer : il disparaît aussi du Marketing
 * Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée « Galettes des rois (en double) ». Chaque phrase
 * vient de panel/public/dashboard/catalogue.js (rendreCatalogues, carteRecu, rendreVerrou, prendre, adapter, supprimer). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues?shop=4';
const poster = (p, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }), [API, corps]);

module.exports = async function () {
  const f = await Film.ouvrir('19-01-catalogues', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      await poster(p, { title: 'Galettes des rois (en double)', source: { kind: 'gamme', ref: 34 }, format: 'A5', pageCount: 8, langs: ['fr'] });
      await p.reload(); await p.waitForTimeout(2500);
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-catalogues', titre: 'Le catalogue : reçu, le mien, ou nouveau',
    resume: 'La première étape : le catalogue préparé par la marque, mes catalogues et ceux de la marque, leur état et la suite à donner.',
    accroche: 'Tous mes catalogues, au même endroit',
    programme: ['Prendre le catalogue de la marque tel quel', 'L’adapter à ma boutique', 'Supprimer un de mes catalogues'],
  });
  await f.montrer('.cm-et5', 'Cinq étapes');
  await f.dire('Mon catalogue est un <b>assistant en cinq étapes</b>. La première, « Catalogue », s’ouvre ici.', 4200);
  await f.montrer('.cm-res', 'En bref');
  await f.dire('En tête, le <b>résumé</b> : mes catalogues, d’où ils viennent, combien sont imprimés ; puis ce qui est <b>à corriger</b>, un bouton par catalogue.', 5600);
  await f.montrer('.cm-recu', 'Nouveau · de la marque');
  await f.dire('La marque a préparé un catalogue pour ma boutique : <b>déjà à mes prix</b>, avec mon adresse, mes horaires et mon QR.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-recu [data-cm=ouvrirM]', { apres: 1800 });
  await f.montrer('.cm-mes-pages', 'Ses pages');
  await f.dire('« <b>Le feuilleter</b> » l’ouvre à la Validation : ses pages, et les points à voir.', 4000);
  await f.cacher(150); await f.taire(150);
  // Revenu à la liste, la carte de la marque ne relit pas ses deux pages (« Lecture… ») : « ↻ Relire » les relit.
  await f.cliquer('.cm-hd [data-cm=liste]', { apres: 500 });
  await f.cliquer('.cm-hd [data-cm=relire]', { apres: 1400 });
  await f.montrer('.cm-tab', 'Mes catalogues');
  await f.dire('Dessous, les catalogues de la marque et les miens : d’où il vient, son <b>état</b>, son nombre de pages.', 4600);
  await f.montrerTout('.cm-tab tbody tr td:nth-child(5)', 'Ensuite');
  await f.dire('« Ensuite » dit la suite : <b>continuer</b> un brouillon, vérifier et imprimer, <b>diffuser</b>.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-tab tr[data-v="5102"] [data-cm=ouvrir]', { apres: 1600 });
  await f.montrer('.cm-et5', 'À l’étape qui suit');
  await f.dire('Un clic sur la ligne l’ouvre à l’étape qui suit son état : un brouillon, à ses <b>produits</b>.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-hd [data-cm=liste]', { apres: 500 });
  await f.cliquer('.cm-hd [data-cm=relire]', { apres: 1400 });
  await f.montrerTout('.cm-hd .cm-btn', 'Relire · Nouveau');
  await f.dire('« ↻ Relire » relit la liste ; « <b>＋ Nouveau catalogue</b> » en commence un, à l’étape 2.', 4200);
  await f.cacher(300); await f.taire(200);

  /* Prendre tel quel */
  await f.fonction({ id: 'catalogue-prendre', titre: 'Prendre le catalogue de la marque tel quel',
    resume: '« Le prendre tel quel › » : le catalogue de la marque, à mes prix, passe à la Validation ; ses étapes 2 et 3 restent sous cadenas.',
    points: ['La carte « Nouveau · de la marque »', '« Le prendre tel quel › »', 'La Validation, puis l’export'] });
  await f.montrer('.cm-recu', 'Nouveau · de la marque');
  await f.dire('Le catalogue de la marque : ses gammes, sa présentation, <b>mes prix</b>.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-recu [data-cm=prendre]', { apres: 1800 });
  await f.montrer('.cm-et5', 'Pris tel quel');
  await f.dire('Il passe à la <b>Validation</b>. Ses gammes et sa mise en page restent celles de la marque : les étapes 2 et 3 sont <b>sous cadenas</b>.', 5600);
  await f.montrer('.cm-verifs', 'Je vérifie avant d’imprimer');
  await f.dire('Je vérifie, puis « Valider mon catalogue › » mène à l’<b>export PDF</b>.', 3600);
  await f.cacher(300); await f.taire(200);

  /* L'adapter */
  await f.fonction({ id: 'catalogue-adapter', titre: 'Adapter le catalogue de la marque à ma boutique',
    resume: '« L’adapter à ma boutique », puis confirmer : une copie à moi, que la marque ne modifie plus, reprise à l’étape 2.',
    points: ['Ouvrir le catalogue de la marque', '« L’adapter à ma boutique »', 'Confirmer la copie'] });
  await f.cliquer('.cm-hd [data-cm=liste]', { apres: 1200 });
  await f.cliquer('.cm-tab tr[data-v="9001"] [data-cm=ouvrirM]', { apres: 1600 });
  await f.cliquer('.cm-et5 [data-cm=etape][data-v="2"]', { apres: 1000 });
  await f.montrer('.cm-verrou', 'Sous cadenas');
  await f.dire('Pris tel quel, ses étapes 2 et 3 sont sous cadenas. « <b>L’adapter à ma boutique</b> » est ici, et sur sa carte.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('.cm-verrou [data-cm=adapter]', 'Faire une copie à moi de ce catalogue ?', 'La marque ne la modifiera plus : ses mises à jour ne m’arriveront plus.', { apres: 1800 });
  await f.montrer('.cm-msg.ok', 'Une copie à moi');
  await f.dire('Le catalogue est <b>à moi</b> : repris à l’étape 2, je change ses produits et sa présentation comme je veux.', 4800);
  await f.cacher(300); await f.taire(200);

  /* Supprimer */
  await f.fonction({ id: 'catalogue-supprimer', titre: 'Supprimer un de mes catalogues',
    resume: '« Supprimer » sur la ligne d’un de mes catalogues, puis confirmer : il disparaît aussi du Marketing.',
    points: ['« Supprimer » sur sa ligne', 'Confirmer'] });
  await f.cliquer('.cm-hd [data-cm=liste]', { apres: 1200 });
  await f.montrer('.cm-tab tr:has-text("(en double)")', 'En double');
  await f.dire('Un catalogue fait <b>en double</b>.', 2400);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('.cm-tab tr:has-text("(en double)") [data-cm=suppr]', 'Supprimer le catalogue « Galettes des rois (en double) » ?', 'Il disparaît aussi du Marketing.', { apres: 1600 });
  await f.montrer('.cm-tab', 'Supprimé');
  await f.dire('Il quitte la liste, et le Marketing. Les catalogues de la marque n’ont pas de « Supprimer ».', 4400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
