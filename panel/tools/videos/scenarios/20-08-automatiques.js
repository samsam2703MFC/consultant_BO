/* Boîte mail › Messages automatiques (le bouton de la barre), et sa fonction en chapitre (24/11/2026) :
 *   onglet    boite-automatiques   les trois règles (accusé de réception, relance d'une offre, rappel la veille),
 *                                  éteintes tant qu'on ne les allume pas, « Le message », « Ce qui est parti »
 *   fonction  boite-automatique    allumer l'accusé de réception, régler son délai et son texte, « Enregistrer »
 * Données fictives : mocks/70_boite.js (la relance et le rappel allumés, deux envois au journal). Chaque phrase vient de
 * panel/public/dashboard/boite.js (vueAuto) ou de panel/src/boite_mail.php (BM_REGLES, bmAutoEcrire).
 * Contournement (B.garderSaisie) : boite.js redessine le formulaire avant d'en lire les champs, le délai et le texte tapés
 * se perdent (boite.js:983) ; le film relève les champs avant « Enregistrer ». À retirer quand ce sera corrigé. */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-08-automatiques', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php'], avant: B.ouverte() });

  /* L'onglet */
  await f.onglet({
    id: 'boite-automatiques', titre: 'Les messages automatiques',
    resume: 'L’accusé de réception, la relance d’une offre sans réponse, le rappel la veille de la commande : éteints tant qu’on ne les allume pas, et ce qui est parti.',
    accroche: 'Ce que la boîte envoie toute seule', programme: ['Allumer un message automatique et régler son texte'],
  });
  await f.montrer('.bx-barre [data-bx="auto"]', 'Messages automatiques');
  await f.dire('Le bouton <b>Messages automatiques</b> de la barre.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-barre [data-bx="auto"]', { apres: 1100 });
  await B.enHaut(f);
  await f.montrer('.bx-cols > div:first-child > p.bx-mu', 'L’horloge');
  await f.dire('Ils partent de l’adresse du magasin, par l’horloge du cockpit, <b>toutes les 10 minutes</b>.', 4600);
  await f.montrerTout('.bx-regle', 'Trois règles');
  await f.dire('Trois règles : l’<b>accusé de réception</b>, la <b>relance</b> d’une offre sans réponse, le <b>rappel</b> la veille de la commande.', 6000);
  await f.montrer('.bx-regle:has([data-bxa="accuse"]) .bx-bascule', 'Éteint');
  await f.dire('Chacune est <b>éteinte</b> tant qu’on ne l’allume pas ; ici, la relance et le rappel sont allumés.', 4800);
  await f.montrer('.bx-regle:has([data-bxa="relance"]) .bx-mu', 'Ce qu’elle fait');
  await f.dire('La relance écrit une fois au client d’une offre en cours restée sans suite.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxao="relance"]', { apres: 800 });
  await f.montrer('.bx-regle:has([data-bxa="relance"]) .bx-form', 'Le message');
  await f.dire('« <b>Le message</b> » déplie le délai, l’objet et le texte, avec les variables des modèles.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxao="relance"]', { apres: 600 });
  await f.montrer('.bx-t-j', 'Ce qui est parti');
  await f.dire('« <b>Ce qui est parti</b> » : quand, quelle règle, à qui, avec quel objet.', 4000);
  await f.cacher(300); await f.taire(200);

  /* Allumer et régler */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-automatique', titre: 'Allumer un message automatique et régler son texte',
    resume: 'Basculer l’interrupteur pour l’allumer ; « Le message » pour régler le délai, l’objet et le texte, puis « Enregistrer ».',
    points: ['Basculer l’interrupteur', '« Le message »', 'Le délai, l’objet, le texte', '« Enregistrer »'] });
  await f.cliquer('.bx-regle:has([data-bxa="accuse"]) .bx-bascule', { apres: 1200 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Allumé');
  await f.dire('L’interrupteur : l’<b>accusé de réception</b> est allumé. Il ne répond qu’aux e-mails qui arrivent à partir de maintenant.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxao="accuse"]', { apres: 800 });
  await f.cliquer('#bx-a-jours', { apres: 150 });
  await f.p.keyboard.press('Control+A'); await f.taper('7', 120);
  await f.montrer('#bx-a-jours', 'Une fois tous les 7 jours');
  await f.dire('Une fois par expéditeur <b>tous les 7 jours</b>.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#bx-a-corps', { apres: 150 });
  await f.p.evaluate(() => { const t = document.querySelector('#bx-a-corps'), a = 'au plus vite.', i = t.value.indexOf(a); if (i >= 0) { t.setSelectionRange(i + a.length, i + a.length); } });
  await f.taper('\nPour une commande urgente, appelez-nous au magasin.', 30);
  await f.montrer('#bx-a-corps', 'Le texte');
  await f.dire('Le texte se règle aussi ; {prenom} et {magasin} se remplissent à l’envoi.', 4000);
  await f.cacher(150); await f.taire(150);
  await B.garderSaisie(f, { jours: '#bx-a-jours', objet: '#bx-a-objet', corps: '#bx-a-corps' });   // contournement : voir scenarios/boite.js
  await f.cliquer('[data-bxas="accuse"]', { apres: 1300 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Enregistré');
  await f.dire('<b>Message enregistré</b>.', 2400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
