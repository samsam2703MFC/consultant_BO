/* Mon catalogue › l'onglet « 5 · Export PDF » (?vue=catalogue), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    catalogue-export           feuilleter (‹ ›, les doubles pages, les flèches, « Agrandir » en plein écran),
 *                                        « Comment je l'imprime ? », « Mon impression », « Mon fichier », diffuser, reprendre
 *   fonction  catalogue-exporter         chez un imprimeur ou sur mon imprimante, puis « Télécharger le PDF imprimeur »
 *   fonction  catalogue-marquer-imprime  la quantité, puis « Marquer imprimé · N ex. »
 *   fonction  catalogue-qr               le QR du catalogue en ligne : « Télécharger le QR (PNG) », « Copier le lien »
 *   fonction  catalogue-nouveau-lien     « Nouveau lien », confirmer : l'ancien QR ne mène plus au catalogue
 *   fonction  catalogue-pdf-leger        « Télécharger le PDF léger » : sans traits de coupe, pour WhatsApp ou un e-mail
 *   fonction  catalogue-affiche          un titre, A4 ou A3, « Télécharger l'affiche (PDF) »
 * Le PDF s'ouvre dans un nouvel onglet du navigateur, qui ne se filme pas : window.open est neutralisé, comme dans la
 * vidéo 19 ; on filme le message du dashboard. Données fictives : mocks/19_catalogue.js ; avant de filmer, on crée
 * « Noël + Saint-Nicolas » (8 pages), on le valide et on l'ouvre à l'export. Chaque phrase vient de
 * panel/public/dashboard/catalogue.js (rendreExport, rendreFeuilleter, telecharger, marquerImprime, rendreDiffuser,
 * copierLien, partager, nouveauLien, affiche). */
const { Film } = require('../film');

const API = '../api/cockpit/dashboard/catalogues';
const appel = (p, m, chemin, corps) => p.evaluate(([m, u, b]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [m, API + chemin, corps]);

module.exports = async function () {
  const f = await Film.ouvrir('19-08-export', {
    vue: 'catalogue', serie: 'Mon catalogue', parent: 'catalogue',
    sources: ['panel/public/dashboard/catalogue.js', 'panel/public/dashboard/catalogue.css'],
    avant: async p => {
      await p.context().grantPermissions(['clipboard-read', 'clipboard-write']);
      const c = await appel(p, 'POST', '?shop=4', { title: 'Noël + Saint-Nicolas', source: { kind: 'gamme', ref: [31, 32] }, format: 'A5', pageCount: 8, langs: ['fr'] });
      await p.reload(); await p.waitForTimeout(2500);
      // L'onglet du PDF (window.open) ne se filme pas : la page d'impression du Marketing s'y ouvrirait.
      await p.evaluate(() => { window.open = () => null; });
      await p.click('.cm-tab tr[data-v="' + c.data.id + '"] [data-cm=ouvrir]'); await p.waitForTimeout(1800);
      await p.click('.cm-et5 [data-cm=etape][data-v="4"]'); await p.waitForTimeout(2000);
      await p.click('.cm-hd [data-cm=valider]'); await p.waitForTimeout(2200);
      // De la place sous le module : un bouton du bas de page se montre au-dessus des sous-titres.
      await p.evaluate(() => { document.querySelector('.cm').style.paddingBottom = '160px'; });
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'catalogue-export', titre: 'L’export PDF et la diffusion',
    resume: 'Feuilleter, choisir l’impression, télécharger le PDF, marquer imprimé, puis diffuser : QR, PDF léger, affiche.',
    accroche: 'Imprimer, puis faire circuler',
    programme: ['Exporter le PDF', 'Marquer imprimé', 'Le QR du catalogue en ligne', 'Un nouveau lien', 'Le PDF léger', 'L’affiche en magasin'],
  });
  await f.montrer('.cm-pan.fb', 'Feuilleter');
  await f.dire('En tête, mon catalogue à <b>feuilleter</b>, comme imprimé et plié : la couverture, puis les doubles pages.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fb-nav[data-v="1"]', { apres: 1500 });
  await f.cliquer('.fb-nav[data-v="1"]', { apres: 1500 });
  await f.cliquer('.fb-pls [data-v="4"]', { apres: 1000 });
  await f.montrer('.fb-bas', 'Les doubles pages');
  await f.dire('« ‹ › », les <b>flèches du clavier</b>, ou les numéros des doubles pages.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-pan.fb [data-cm=fbGrand]', { apres: 1200 });
  await f.montrer('.fb-plein .fb-livre', 'En grand');
  await f.dire('« <b>Agrandir</b> » l’ouvre en grand ; « Fermer », ou Échap, le referme.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.fb-plein .fb-nav[data-v="-1"]', { apres: 1500 });
  await f.cliquer('.fb-plein [data-cm=fbGrand]', { apres: 1000 });
  await f.montrer('.cm-modes', 'Comment je l’imprime ?');
  await f.dire('Dessous : <b>comment je l’imprime</b>, et mon impression — format, langue, quantité.', 4400);
  await f.montrer('.cm-fichier', 'Mon fichier');
  await f.dire('À droite, <b>mon fichier</b> : son nom, ses pages, les prix de mon webshop lus maintenant.', 4600);
  await f.montrer('.cm-diff', 'Diffuser');
  await f.dire('Puis <b>diffuser</b> : le QR du catalogue en ligne, un PDF léger, une affiche.', 4000);
  await f.montrer('.cm-reprendre', 'Reprendre');
  await f.dire('Et pour le reprendre : mes produits, la mise en page, ou un <b>nouveau catalogue</b>.', 4000);
  await f.cacher(300); await f.taire(200);
  await f.defiler(-6000);

  /* Exporter le PDF */
  await f.fonction({ id: 'catalogue-exporter', titre: 'Exporter le PDF imprimeur ou le livret',
    resume: '« Chez un imprimeur » ou « Sur mon imprimante » (livret A5), la langue, puis « Télécharger… » : le PDF s’ouvre dans un nouvel onglet, lien valable 48 h.',
    points: ['Chez un imprimeur, ou sur mon imprimante', 'Le format, la langue', '« Télécharger… »'] });
  await f.cliquer('[data-cm=impmode][data-v="livret"]', { apres: 800 });
  await f.montrer('.cm-modes', 'Sur mon imprimante');
  await f.dire('Sur mon imprimante : des feuilles A4 recto verso, pliées au milieu, un <b>livret A5</b>.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=impmode][data-v="imprimeur"]', { apres: 800 });
  await f.montrerTout('.cm-modes .cm-mode.on, .cm-imp3', 'Chez un imprimeur');
  await f.dire('Chez un imprimeur : fond perdu 3 mm et <b>traits de coupe</b>. Puis le format et la langue ; FR + NL, un fichier par langue.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-fichier [data-cm=pdf][data-v="imprimeur"]', { apres: 1800 });
  await f.montrer('.cm-msg.ok', 'Le PDF imprimeur');
  await f.dire('Le PDF s’ouvre dans un <b>nouvel onglet</b> : « Enregistrer au format PDF ». Le lien vaut 48 heures.', 4800);
  await f.cacher(300); await f.taire(200);

  /* Marquer imprimé */
  await f.fonction({ id: 'catalogue-marquer-imprime', titre: 'Marquer le catalogue imprimé',
    resume: 'La quantité imprimée, puis « Marquer imprimé · N ex. » : pour savoir combien il en reste à distribuer.',
    points: ['La quantité', '« Marquer imprimé · N ex. »'] });
  await f.cliquer('[data-cm=qte]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('300', 140);
  // La saisie ne redessine pas le bouton (« Marquer imprimé · 250 ex. » resterait) : un rendu, sans geste filmé.
  await f.p.locator('[data-cm=implang].on').dispatchEvent('click');
  await f.pause(600);
  await f.montrer('.cm-marq', '300 ex.');
  await f.dire('Une fois imprimé : la <b>quantité</b>, puis « Marquer imprimé ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-marq [data-cm=imprime]', { apres: 1800 });
  await f.montrer('.cm-msg.ok', 'Imprimé · 300 ex.');
  await f.dire('Noté sur le catalogue : <b>imprimé · 300 ex.</b>, pour savoir combien il en reste à distribuer.', 4600);
  await f.cacher(300); await f.taire(200);

  /* Le QR */
  await f.fonction({ id: 'catalogue-qr', titre: 'Diffuser le QR du catalogue en ligne',
    resume: 'Le QR ouvre le catalogue en ligne, toujours la dernière version : « Télécharger le QR (PNG) », « Copier le lien », « Partager… ».',
    points: ['Le QR du catalogue en ligne', '« Télécharger le QR (PNG) »', '« Copier le lien », « Partager… »'] });
  await f.montrer('.cm-diff > .cm-pan:first-child', 'QR code du catalogue');
  await f.dire('Scanné, il ouvre le <b>catalogue en ligne</b>, toujours la dernière version.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=qrPng]', { apres: 900 });
  await f.dire('« Télécharger le QR (PNG) » : le QR en <b>image</b>.', 2800);
  await f.taire(150);
  await f.cliquer('[data-cm=copier]', { apres: 1000 });
  await f.montrer('.cm-msg.ok', 'Lien copié');
  await f.dire('« <b>Copier le lien</b> » : à coller où je veux. « Partager… » passe par le partage de l’appareil, s’il en a un.', 5200);
  await f.cacher(300); await f.taire(200);

  /* Nouveau lien */
  await f.fonction({ id: 'catalogue-nouveau-lien', titre: 'Faire un nouveau lien',
    resume: '« Nouveau lien », puis confirmer : un autre lien et un autre QR ; l’ancien ne mène plus au catalogue.',
    points: ['« Nouveau lien »', 'Confirmer'] });
  await f.montrer('.cm-diff .cm-url', 'Le lien');
  await f.dire('Le lien du catalogue en ligne, et son QR.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-cm=lienNouveau]', 'Faire un nouveau lien ?', 'L’ancien QR (affiches, catalogues déjà distribués) ne mènera plus au catalogue.', { apres: 1500 });
  await f.montrer('.cm-diff > .cm-pan:first-child', 'Nouveau lien');
  await f.dire('Un <b>nouveau lien</b>, un nouveau QR : l’ancien, sur les affiches et les catalogues déjà distribués, ne mène plus au catalogue.', 5600);
  await f.cacher(300); await f.taire(200);

  /* PDF léger */
  await f.fonction({ id: 'catalogue-pdf-leger', titre: 'Télécharger le PDF léger à partager',
    resume: '« Télécharger le PDF léger » : sans traits de coupe, pour WhatsApp, un e-mail ou Facebook.',
    points: ['« PDF à partager »', '« Télécharger le PDF léger »'] });
  await f.montrer('.cm-diff > .cm-pan:nth-child(2)', 'PDF à partager');
  await f.dire('Un PDF pour <b>WhatsApp</b>, un e-mail ou Facebook, sans traits de coupe.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=pdf][data-v="leger"]', { apres: 1800 });
  await f.montrer('.cm-msg.ok', 'Le PDF à partager');
  await f.dire('Il s’ouvre dans un nouvel onglet : « Enregistrer au format PDF », puis je le partage.', 4200);
  await f.cacher(300); await f.taire(200);

  /* L'affiche */
  await f.fonction({ id: 'catalogue-affiche', titre: 'Télécharger l’affiche en magasin',
    resume: 'Un titre si on veut, A4 ou A3, puis « Télécharger l’affiche (PDF) » : en vitrine, le client scanne et feuillette.',
    points: ['Un titre, si on veut', 'A4 ou A3', '« Télécharger l’affiche (PDF) »'] });
  await f.montrer('.cm-diff > .cm-affiche', 'Affiche en magasin');
  await f.dire('L’<b>affiche</b>, en vitrine ou au comptoir : le client scanne et feuillette le catalogue.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cm=afficheTitre]', { apres: 200 });
  await f.taper('Nos fêtes, chez nous', 90);
  await f.cliquer('[data-cm=afficheTaille][data-v="A3"]', { apres: 900 });
  await f.montrer('.cm-diff > .cm-affiche', 'A3 · mon titre');
  await f.dire('Mon <b>titre</b>, et le format <b>A3</b>. Le QR et l’illustration de la couverture sont déjà dessus.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cm-affiche [data-cm=pdf][data-v="affiche"]', { apres: 1800 });
  await f.montrer('.cm-msg.ok', 'L’affiche');
  await f.dire('L’affiche s’ouvre dans un nouvel onglet, prête à imprimer.', 3400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
