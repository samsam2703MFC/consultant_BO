/* Boîte mail › Modèles (le bouton « Modèles » de la barre), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    boite-modeles           les modèles du magasin, ceux du réseau, le formulaire et ses variables
 *   fonction  boite-modele-creer      « Dupliquer » un modèle du réseau, l'adapter, « Enregistrer le modèle »
 *   fonction  boite-modele-modifier   « Modifier » un modèle du magasin, « Enregistrer le modèle » ; « Supprimer »
 * Données fictives : mocks/70_boite.js (les six modèles du réseau ; deux modèles du magasin créés avant de filmer).
 * Chaque phrase vient de panel/public/dashboard/boite.js (vueModeles, modEnregistrer) ou de panel/src/boite_mail.php.
 * Contournement (B.garderSaisie) : boite.js redessine le formulaire avant d'en lire les champs, ce qui est tapé se perd
 * (boite.js:971-974) ; le film relève les champs avant « Enregistrer le modèle ». À retirer quand ce sera corrigé. */
const { Film } = require('../film');
const B = require('./boite');

const MAGASIN = [
  { nom: 'Commande prête', objet: 'Votre commande est prête', corps: 'Bonjour {prenom},\n\nVotre commande est prête : vous pouvez passer la chercher au magasin.\n\nÀ tout à l’heure,\n{magasin}' },
  { nom: 'Horaires de la Toussaint', objet: 'Nos horaires de la Toussaint', corps: 'Bonjour {prenom},\n\nLe 1er novembre, le magasin est ouvert de 7 h à 13 h.\n\nBelle journée,\n{magasin}' },
];

module.exports = async function () {
  const f = await Film.ouvrir('20-07-modeles', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php'],
    avant: B.ouverte(async p => { for (const m of MAGASIN) { await B.poster(p, '/dashboard/boite/modeles', Object.assign({ action: 'creer' }, m)); } }) });
  /** Placer le curseur du texte juste après `apres` (ou tout sélectionner : apres = null), puis taper. */
  const ecrireDans = async (sel, texte, apres) => {
    await f.cliquer(sel, { apres: 150 });
    await f.p.evaluate(([s, a]) => { const t = document.querySelector(s); const i = a == null ? -1 : t.value.indexOf(a); if (i < 0) { t.select(); } else { t.setSelectionRange(i + a.length, i + a.length); } }, [sel, apres == null ? null : apres]);
    await f.taper(texte, 40);
  };

  /* L'onglet */
  await f.onglet({
    id: 'boite-modeles', titre: 'Les modèles',
    resume: 'Les modèles du magasin et ceux du réseau, puis le formulaire « Nouveau modèle » et ses variables ({prenom}, {magasin}, {offre}…).',
    accroche: 'Les réponses qui reviennent, écrites une fois',
    programme: ['Créer ou dupliquer un modèle', 'Modifier ou supprimer un modèle du magasin'],
  });
  await f.montrer('.bx-barre [data-bx="modeles"]', 'Modèles');
  await f.dire('Le bouton <b>Modèles</b> de la barre.', 2600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-barre [data-bx="modeles"]', { apres: 1100 });
  await B.enHaut(f);
  await B.cadre(f, '.bx-cols > div:first-child', 'Les modèles');
  await f.dire('Les modèles <b>du magasin</b> d’abord, puis ceux <b>du réseau</b>, avec leur objet et leur texte.', 4800);
  await f.montrer('.bx-mod:has([data-bxmd="b-accuse"]) .bx-mod-h', 'Réseau');
  await f.dire('Un modèle du réseau ne se change pas : il se <b>duplique</b>.', 3200);
  await f.montrerTout('.bx-mod:has([data-bxmd])', 'Six modèles du réseau');
  await f.dire('Six modèles du réseau : accusé de réception, demande de précisions, envoi de l’offre, relance, confirmation, rappel la veille.', 6000);
  await f.cacher(150); await B.enHaut(f);
  await f.montrer('.bx-mod:has([data-bxme]) .bx-mod-h', 'Magasin');
  await f.dire('Ceux du magasin se <b>modifient</b> ou se <b>suppriment</b>.', 3200);
  await f.montrer('.bx-form', 'Nouveau modèle');
  await f.dire('À droite, le formulaire : le nom, l’objet, le texte.', 3000);
  await f.montrer('.bx-form p.bx-mu', 'Les variables');
  await f.dire('Les <b>variables</b> se remplissent à l’insertion : {prenom}, {magasin}, {offre}, {total}, {jour}…', 5000);
  await f.cacher(150); await f.taire(150);
  await f.dire('Un modèle s’insère ensuite dans une réponse : « Insérer un modèle… ».', 3600);
  await f.taire(150);

  /* Créer ou dupliquer */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-modele-creer', titre: 'Créer ou dupliquer un modèle',
    resume: '« Dupliquer » un modèle du réseau (ou écrire le sien), l’adapter avec ses variables, puis « Enregistrer le modèle ».',
    points: ['« Dupliquer » un modèle du réseau', 'Le nom, le texte, les variables', '« Enregistrer le modèle »'] });
  await f.cliquer('[data-bxmd="b-precisions"]', { apres: 800 });
  await f.montrer('.bx-form', 'Nouveau modèle');
  await f.dire('« <b>Dupliquer</b> » : le formulaire reprend le nom, l’objet et le texte.', 3800);
  await f.cacher(150); await f.taire(150);
  await ecrireDans('#bx-m-nom', 'Précisions pour un buffet', null);
  await ecrireDans('#bx-m-corps', '\n- sucré, salé, ou les deux ?', '(et l’adresse) ?');
  await f.montrer('#bx-m-corps', 'Adapté');
  await f.dire('Un nom à soi, une question de plus ; {prenom} et {magasin} restent des variables.', 4400);
  await f.cacher(150); await f.taire(150);
  await B.garderSaisie(f, { nom: '#bx-m-nom', objet: '#bx-m-objet', corps: '#bx-m-corps' });   // contournement : voir scenarios/boite.js
  await f.cliquer('[data-bx="modEnregistrer"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Enregistré');
  await f.dire('<b>Modèle enregistré</b> : il rejoint les modèles du magasin.', 3400);
  await f.cacher(300); await f.taire(200);

  /* Modifier ou supprimer */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-modele-modifier', titre: 'Modifier ou supprimer un modèle du magasin',
    resume: '« Modifier » ouvre le modèle du magasin dans le formulaire ; « Enregistrer le modèle ». « Supprimer » le retire.',
    points: ['« Modifier »', 'Changer, puis « Enregistrer le modèle »', 'ou « Supprimer »'] });
  await f.cliquer('[data-bxmode="classique"]', { apres: 400 });
  await f.cliquer('.bx-mod:has(b:text-is("Commande prête")) [data-bxme]', { apres: 800 });
  await f.montrer('.bx-form', 'Modifier le modèle');
  await f.dire('« <b>Modifier</b> » : le modèle s’ouvre dans le formulaire.', 3200);
  await f.cacher(150); await f.taire(150);
  await ecrireDans('#bx-m-objet', 'Votre commande vous attend', null);
  await B.garderSaisie(f, { nom: '#bx-m-nom', objet: '#bx-m-objet', corps: '#bx-m-corps' });   // contournement : voir scenarios/boite.js
  await f.cliquer('[data-bx="modEnregistrer"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-mod:has(b:text-is("Commande prête"))', 'Modifié');
  await f.dire('Enregistré : le nouvel objet est dans la liste.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.montrer('.bx-mod:has(b:text-is("Horaires de la Toussaint"))', 'Plus utile');
  await f.dire('Les horaires de la Toussaint ne servent plus : « <b>Supprimer</b> ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-mod:has(b:text-is("Horaires de la Toussaint")) [data-bxms]', { apres: 1200 });
  await B.cadre(f, '.bx-cols > div:first-child', 'Supprimé');
  await f.dire('Il disparaît des modèles du magasin.', 2800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
