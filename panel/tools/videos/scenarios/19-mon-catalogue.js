/* Vidéo 19 — « Éditer mon catalogue » (mode d'emploi de Marketing › Mon catalogue, vue=catalogue). Environ 4 min.
 * L'assistant en entier (panel/public/dashboard/catalogue.js) : choisir le catalogue (reçu de la marque, le mien,
 * nouveau), les gammes et les produits, la présentation catégorie par catégorie puis l'ordre, la validation, l'export
 * PDF et la diffusion (QR, PDF léger, affiche). Données fictives : mocks/19_catalogue.js. */
const { Film } = require('../film');

/** Pendant un glisser-déposer natif, la page ne reçoit plus de mousemove : le curseur filmé suit le dragover. */
async function curseurPendantGlisser(f) {
  await f.p.evaluate(() => {
    if (window.__filmGlisser) { return; }
    window.__filmGlisser = true;
    addEventListener('dragover', e => { const c = document.getElementById('film-curseur'); if (c && e.clientX) { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; } }, true);
  });
}

module.exports = async function () {
  const f = await Film.ouvrir('19-mon-catalogue', { vue: 'catalogue', serie: 'Mode d’emploi', titreSerie: 'Éditer mon catalogue', duree: 'env. 4 min' });
  await f.couverture({
    kicker: 'Mode d’emploi · Marketing', titre: 'Éditer<br>mon catalogue', accroche: 'Mes produits, à mes prix, sous ma marque',
    programme: ['Choisir le catalogue', 'Les gammes et les produits', 'La présentation', 'La validation', 'L’export PDF et la diffusion'],
  });

  /* 1 · Le catalogue : reçu de la marque, le mien, ou nouveau. */
  await f.chapitre({ n: 1, total: 5, titre: 'Choisir le catalogue', texte: 'Reçu de la marque, l’un des miens, ou un nouveau.', ms: 2600 });
  await f.etape('1 · Le catalogue');
  await f.montrer('.cm-et5', 'Cinq étapes');
  await f.dire('Mon catalogue est un <b>assistant en cinq étapes</b> : le catalogue, les gammes, la présentation, la validation, l’export PDF.', 4600);
  await f.montrer('.cm-recu', 'Reçu de la marque');
  await f.dire('La marque a préparé un catalogue pour ma boutique : <b>déjà à mes prix</b>, avec mon adresse, mes horaires et mon QR.', 4400);
  await f.montrer('.cm-recu__act', 'Tel quel, ou adapté');
  await f.dire('Je le prends <b>tel quel</b>, avec les gammes et la présentation de la marque, ou je l’<b>adapte</b> : une copie à moi.', 4800);
  await f.montrer('.cm-tab', 'Mes catalogues');
  await f.dire('Dessous, mes catalogues et ceux de la marque : leur état, et la suite — <b>continuer</b>, imprimer, <b>diffuser</b>.', 4200);
  await f.cacher(150);
  await f.cliquer('.cm-hd [data-cm=nouveau]', { apres: 1000 });

  /* 2 · Les gammes et les produits. */
  await f.chapitre({ n: 2, total: 5, titre: 'Les gammes et les produits', texte: 'Les produits de mon webshop, à mes prix.', ms: 2600 });
  await f.etape('2 · Les gammes');
  await f.cliquer('.cm-src [data-v="31"]', { apres: 500 });
  await f.cliquer('.cm-src [data-v="32"]', { apres: 600 });
  await f.montrer('.cm-src', 'Le point de départ');
  await f.dire('Je coche <b>une ou plusieurs gammes</b>, huit au plus. Noël et Saint-Nicolas : <b>17 produits</b>, un produit des deux comptant une fois.', 5600);
  await f.montrer('.cm-nbp', '8 pages');
  await f.dire('<b>8 pages</b>, dont 4 de la marque.', 2700);
  await f.cacher(150);
  await f.taire(100);
  await f.cliquer('[data-cm=creer]', { apres: 1100 });
  await f.montrer('.cm-acc.ouvert', 'Ma boutique, mes prix');
  await f.dire('Seuls les produits de <b>mon webshop</b>, à <b>mes prix</b> ; un interrupteur retire ce que je ne veux pas.', 4400);
  await f.cacher(150);
  await f.cliquer('[data-cm=prod][data-v="427"]', { apres: 900 });
  await f.montrer('.cm-offres', 'Promotions et bundles');
  await f.dire('Les <b>promotions</b> lues dans TFBuddy, cochées si elles touchent un produit choisi ; et la disponibilité en pied de page.', 5000);
  await f.cacher(150);
  await f.cliquer('.cm-pied [data-cm=etape][data-v="3"]', { apres: 1100 });
  await f.defiler(-3000);

  /* 3 · La présentation : catégorie par catégorie, puis l'ordre. */
  await f.chapitre({ n: 3, total: 5, titre: 'La présentation', texte: 'Catégorie par catégorie, puis l’ordre des pages.', ms: 2600 });
  await f.etape('3 · La présentation');
  await f.montrer('.mep-sous', 'Une catégorie à la fois');
  await f.dire('Une étape par catégorie choisie, dans l’ordre de l’étape 2 ; à la fin, la <b>mise en page finale</b>.', 4000);
  await f.montrer('.mep-lays', 'Comment les présenter ?');
  await f.dire('1 vedette + 4, 2 par largeur, 1 par largeur, 2 grandes : chaque choix dit son <b>nombre de pages</b>.', 4200);
  await f.cacher(150);
  await f.cliquer('[data-cm=mepPleine][data-v="1"]', { apres: 900 });
  await f.montrer('.mep-opt.on', 'Pleine page');
  await f.dire('Un produit seul sur une page, <b>avec sa promotion</b> : la bûche en grand, le « Duo de bûches » imprimé dessous.', 4600);
  await f.montrer('.cm-msg.inf', 'Toujours par 2');
  await f.dire('Il faut plus de place : le catalogue passe à <b>10 pages</b>, toujours par 2.', 3600);
  await f.cacher(150);
  await f.cliquer('[data-cm=mepLay][data-v="mosaique"]', { apres: 700 });
  await f.cliquer('[data-cm=mepGab][data-v="B"]', { apres: 600 });
  await f.dire('Les trois autres en grandes vignettes, gabarit B : les pages se dessinent aussitôt, avec mes produits et mes prix.', 4800);
  await f.taire(100);
  await f.cliquer('.mep-ss[data-v="2"]', { apres: 800 });
  await f.cliquer('[data-cm=mepLay][data-v="mosaique"]', { apres: 700 });
  await f.montrer('.mep-aps', 'Deux pages');
  await f.dire('Six biscuits en grandes vignettes : <b>deux pages</b>. La page restée libre fait la place.', 3800);
  await f.cacher(150);
  await f.cliquer('.mep-ss.fin', { apres: 1000 });
  await f.montrer('.mep-fin', 'La mise en page finale');
  await f.dire('La <b>mise en page finale</b> : l’ordre de l’impression. Les pages de la marque ne bougent pas.', 3800);
  await f.cacher(150);
  await curseurPendantGlisser(f);
  const de = await f.rect('.mep-bloc:has-text("Pains de fête") .mep-poi');
  const vers = await f.rect('.mep-bloc:has-text("Biscuits et confiseries")');
  await f.dire('Je <b>glisse</b> une catégorie pour changer l’ordre : ses pages la suivent. Les pains de fête passent en page 6.', 600);
  await f.tirer(de.x + de.w / 2, de.y + de.h / 2, vers.x + vers.w * 0.18, vers.y + vers.h / 2, 1600);
  await f.pause(3000);
  await f.cliquer('.mep-pied [data-cm=etape][data-v="4"]', { apres: 1300 });

  /* 4 · La validation : ce qui s'imprimera. */
  await f.chapitre({ n: 4, total: 5, titre: 'La validation', texte: 'Ce qui s’imprimera, vérifié avant le PDF.', ms: 2600 });
  await f.etape('4 · La validation');
  await f.montrer('.cm-verifs', 'Je vérifie avant d’imprimer');
  await f.dire('Le Marketing compose le catalogue et le <b>vérifie</b> : les points à voir, un par un.', 4000);
  await f.cacher(150);
  await f.cliquer('[data-cm=geste][data-v="c-est-juste"]', { apres: 600 });
  await f.cliquer('[data-cm=geste][data-v="dates-vues"]', { apres: 700 });
  await f.dire('Les prix de mon webshop : <b>c’est juste</b>. Les dates en pied de page : <b>vues</b>.', 3600);
  await f.cliquer('[data-cm=geste][data-v="ouvrir-page"]', { apres: 900 });
  await f.montrer('.cm-grand', 'La page en grand');
  await f.dire('Un point montre sa page : la voilà en grand, comme elle s’imprimera.', 3600);
  await f.cacher(150);
  await f.cliquer('[data-cm=geste][data-v="imprimer-ainsi"]', { apres: 700 });
  await f.montrer('.cm-verif:has([data-v="retirer-promo"])', 'Un point ouvert');
  await f.dire('Une promotion finit avant le catalogue : je la retire, ou je la garde. Imprimer reste possible : le point sera <b>rappelé au PDF</b>.', 5000);
  await f.cacher(150);
  await f.cliquer('.cm-hd [data-cm=valider]', { apres: 1300 });

  /* 5 · L'export PDF et la diffusion. */
  await f.chapitre({ n: 5, total: 5, titre: 'L’export PDF et la diffusion', texte: 'Feuilleter, imprimer, puis le QR, le PDF léger et l’affiche.', ms: 2700 });
  await f.etape('5 · L’export PDF');
  await f.montrer('.cm-pan.fb', 'Feuilleter');
  await f.dire('<b>Feuilleter</b> mon catalogue comme imprimé et plié : la couverture, puis les doubles pages.', 4000);
  await f.cacher(150);
  await f.cliquer('.fb-nav[data-v="1"]', { apres: 1700 });
  await f.montrer('.cm-modes', 'Comment je l’imprime ?');
  await f.dire('Chez un imprimeur : fond perdu et traits de coupe. Sur mon imprimante : un <b>livret A5</b> plié.', 4400);
  await f.montrer('.cm-imp3', 'Mon impression');
  await f.dire('Puis le format, la langue, la <b>quantité</b>.', 2600);
  await f.cacher(150);
  // L'onglet du PDF (window.open) ne se filme pas : la page d'impression du Marketing s'y ouvrirait.
  await f.p.evaluate(() => { window.open = () => null; });
  await f.cliquer('.cm-fichier [data-cm=pdf][data-v="imprimeur"]', { apres: 1200 });
  await f.montrer('.cm-msg.ok', 'Le PDF imprimeur');
  await f.dire('Le PDF s’ouvre dans un nouvel onglet : « Enregistrer au format PDF ». Le lien vaut <b>48 heures</b>.', 4200);
  await f.cacher(150);
  await f.cliquer('.cm-fichier [data-cm=imprime]', { apres: 1200 });
  await f.montrer('.cm-msg.ok', 'Marqué imprimé');
  await f.dire('Une fois imprimé : <b>« Marquer imprimé »</b>, pour savoir combien il en reste à distribuer.', 3800);
  await f.cacher(150);
  await f.montrer('.cm-diff', 'Diffuser');
  await f.dire('Puis diffuser : le <b>QR</b> du catalogue en ligne, toujours la dernière version ; un <b>PDF léger</b> pour WhatsApp ou un e-mail.', 5000);
  await f.montrer('.cm-diff > .cm-affiche', 'L’affiche');
  await f.dire('Et l’<b>affiche</b>, en A4 ou A3 : en vitrine, le client scanne et feuillette.', 3400);
  await f.cacher(150);
  return f.fin({ ms: 5500,
    titre: 'À vous<br>de l’éditer', accroche: 'Un catalogue à votre image',
    retenir: ['Cinq étapes, du catalogue au PDF', 'Les produits de mon webshop, à mes prix', 'Une catégorie à la fois, puis l’ordre', 'Vérifier, imprimer, diffuser le QR'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
