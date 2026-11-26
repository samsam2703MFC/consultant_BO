/* La barre du haut › les fenêtres communes › la fiche produit, onglet « Recette & marge » (?vue=ops), 26/11/2026 :
 *   onglet    barre-fiche-recette   le coefficient et sa jauge, le verdict, tester un autre prix (− / +, « remettre »),
 *                                   la recette ligne par ligne, une matière première (son prix ici, dans le réseau,
 *                                   chez le fournisseur), la marge brute — tester un prix et voir une matière : des
 *                                   gestes de lecture, montrés ici
 *   fonction  barre-fiche-adapter   taper le prix, « Adapter mon prix de vente », l'aperçu, « Confirmer ce prix »
 *   fonction  barre-fiche-relever   un prix au-dessus du maximum du réseau : « Demander au réseau de relever le maximum »
 * Départ (hors film) : Snacking › Sandwichs dépliés. Données fictives : mocks/10_ops.js (lib/fiche.js : la fiche, la
 * recette), 11_produits.js (POST /analyse/produits/prix), 11_barre_fiche.js (/analyse/matieres/fiche),
 * 11_barre_bureau.js (POST /helpdesk/magasin). Chaque phrase vient de panel/public/dashboard/dashboard.js (ficheRecette,
 * ficheMatiere, ficheAdapter, ficheAdapterAgir) et de panel/src/prix_produit.php. */
const { Film } = require('../film');
const B = require('./barre');

module.exports = async function () {
  const f = await Film.ouvrir('01-26-fiche-recette', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'panel/src/prix_produit.php', 'panel/src/matiere_fiche.php'],
    avant: async p => {
      await B.prioritaires(p); await B.relire(p);
      await p.locator('#dash [data-cacc="g:Snacking"]').click(); await p.waitForTimeout(500);
      await p.locator('#dash [data-cacc="c:Sandwichs"]').click(); await p.waitForTimeout(500);
      await p.evaluate(() => window.scrollTo(0, 0));
    },
  });
  const prix = async v => {
    await f.cliquer('#db-fiche [data-fsiminput]', { apres: 200 });
    await f.p.keyboard.press('Control+A');
    await f.taper(v, 150);
    await f.p.keyboard.press('Enter');
    await f.pause(800);
  };
  // Le dashboard redessine la fiche toutes les deux minutes (la relecture de la page) : un clic qui tombe pendant ce
  // redessin se perd (le bouton est remplacé entre l'appui et le relâché). On reclique alors, une fois.
  const agir = async (quoi, attendu, apres = 1400) => {
    await f.cliquer(`#db-fiche [data-fadapt="${quoi}"]`, { apres });
    if (!(await f.p.locator('#db-fiche ' + attendu).count())) { await f.cliquer(`#db-fiche [data-fadapt="${quoi}"]`, { apres }); }
  };
  const enHaut = () => f.p.evaluate(() => { const m = document.querySelector('#db-fiche .fi-modale'); if (m) { m.scrollTop = 0; } });

  await f.onglet({
    id: 'barre-fiche-recette', titre: 'La fiche produit : recette & marge',
    resume: 'Le troisième onglet de la fiche : le coefficient et sa jauge, le verdict, un autre prix à tester, la recette ligne par ligne, le prix d’une matière première, la marge brute.',
    accroche: 'Ce que coûte la pièce, ce qu’elle rapporte',
    programme: ['Le coefficient et le verdict', 'La recette, ligne par ligne', 'Adapter son prix de vente', 'Demander au réseau de relever le maximum'],
  });
  await f.cliquer('#dash [data-fprod="122"]', { apres: 1400 });
  await f.cliquer('#db-fiche [data-fong="3"]', { apres: 1400 });
  await B.cadre(f, '#db-fiche .fi-rjauge', 'Le coefficient');
  await f.dire('« <b>Recette & marge</b> » : le coefficient, le prix hors TVA divisé par le coût de la recette.', 5000);
  await B.cadre(f, '#db-fiche .fi-rjb', 'La jauge');
  await f.dire('Sous × 1,67, la marge passe sous 40 % ; au-dessus de × 2,5, elle dépasse 60 % : <b>dans le vert</b>.', 5600);
  await f.dire('Sur la jauge, ce produit, sa catégorie, le magasin aujourd’hui et l’objectif.', 4200);
  await B.cadre(f, '#db-fiche .fi-rverdict', 'Le verdict');
  await f.dire('Le <b>verdict</b> : le prix qu’il faudrait pour entrer dans le vert, et l’ingrédient qui pèse le plus.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche [data-fsimpas="0.05"]', { apres: 350 });
  await f.cliquer('#db-fiche [data-fsimpas="0.05"]', { apres: 350 });
  await f.cliquer('#db-fiche [data-fsimpas="0.05"]', { apres: 700 });
  await B.cadre(f, '#db-fiche .fi-rjauge', 'Un autre prix');
  await f.dire('Un autre prix, tapé ou à 5 centimes près : le coefficient, la marge et le verdict se recalculent. Rien ne change en caisse.', 6400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche [data-fsimx]', { apres: 800 });
  await f.montrer('#db-fiche .fi-rtab', 'La recette');
  await f.dire('<b>La recette, pièce par pièce</b> : chaque ingrédient, sa quantité, son coût, sa part du coût.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche [data-fmat="1"]', { apres: 1400 });
  await f.montrer('#db-fiche .fi-rdg', 'Une matière première');
  await f.dire('Un clic sur une <b>matière première</b> : son prix ici, le prix conseillé, le réseau, le fournisseur.', 5200);
  await f.montrer('#db-fiche .fi-rdp.four', 'Chez le fournisseur');
  await f.dire('Le prix chez le fournisseur, depuis quand, et le prochain s’il est annoncé.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-fiche [data-fmat="1"]', { apres: 700 });
  await f.montrer('#db-fiche .fi-rmb', 'Marge brute');
  await f.dire('À côté, la <b>marge brute</b> de la pièce, au prix encaissé.', 3600);
  await f.cacher(300); await f.taire(150);

  /* Adapter son prix de vente */
  const s1 = B.sousLaDiapo(f, enHaut);
  await f.fonction({ id: 'barre-fiche-adapter', titre: 'Adapter son prix de vente',
    resume: 'Le prix testé devient le prix de vente du magasin : « Adapter mon prix de vente », l’aperçu en caisse TVA comprise, puis « Confirmer ce prix ».',
    points: ['Taper le nouveau prix', '« Adapter mon prix de vente »', 'Lire l’aperçu', '« Confirmer ce prix »'] });
  await s1;
  await prix('5,20');
  await B.cadre(f, '#db-fiche .fi-rverdict', 'À 5,20 €');
  await f.dire('À 5,20 € hors TVA, la pièce passe à × 2,5 : dans le vert.', 3800);
  await f.cacher(150); await f.taire(150);
  await agir('lire', '[data-fadapt="confirmer"]');
  await B.cadre(f, '#db-fiche .fi-radapt', 'L’aperçu');
  await f.dire('D’abord l’<b>aperçu</b> : le prix en caisse, TVA comprise, et le maximum du réseau. Rien n’a changé.', 5600);
  await f.cacher(150); await f.taire(150);
  await agir('confirmer', '.fi-radapt.fait', 1500);
  await B.cadre(f, '#db-fiche .fi-radapt', 'Enregistré');
  await f.dire('<b>Prix enregistré</b> dans l’ERP ; le prix encaissé de la fiche suivra avec les prochains tickets.', 5200);
  await f.cacher(300); await f.taire(150);

  /* Demander au réseau de relever le maximum */
  const s2 = B.sousLaDiapo(f, enHaut);
  await f.fonction({ id: 'barre-fiche-relever', titre: 'Demander au réseau de relever le prix maximum',
    resume: 'Au-dessus du prix maximum du réseau, le prix ne s’écrit pas : « Demander au réseau de relever le maximum » envoie la demande au bureau.',
    points: ['Un prix au-dessus du maximum', '« Adapter mon prix de vente »', '« Demander au réseau de relever le maximum »'] });
  await s2;
  await prix('5,90');
  await agir('lire', '[data-fadapt="demander"]');
  await B.cadre(f, '#db-fiche .fi-radapt', 'Au-dessus du maximum');
  await f.dire('Au-dessus du <b>prix maximum du réseau</b>, rien ne s’écrit.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.montrer('#db-fiche [data-fadapt="demander"]', 'Demander au réseau');
  await f.dire('« <b>Demander au réseau de relever le maximum</b> » : la demande part au bureau, prix et coefficient compris.', 5400);
  await f.cacher(150); await f.taire(150);
  await agir('demander', '.fi-radapt.fait.ok', 1500);
  await B.cadre(f, '#db-fiche .fi-radapt', 'Demande envoyée');
  await f.dire('<b>Envoyée</b>, avec sa référence : la réponse arrive dans « Demande au bureau ». Le prix en caisse ne change pas.', 5800);
  await f.cacher(300); await f.taire(150);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
