/* Boîte mail › Pilote (la bascule IA › Pilote), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    boite-pilote           les trois crans, le temps gagné, « Ce que le pilote a fait », « Ce qui vous
 *                                    attend », les garde-fous fixes
 *   fonction  boite-pilote-regler    passer en « Simples » (confirmé) ; « Éteint » annule les envois prévus
 *   fonction  boite-pilote-traiter   « C'est traité » sur ce que le pilote a laissé au magasin
 *   fonction  boite-pilote-annuler   « Annuler l'envoi » d'une réponse qui « part dans 10 min »
 * Données fictives : mocks/70_boite.js (le pilote en « Brouillons » depuis lundi ; sous la diapositive du dernier
 * chapitre, un passage de l'horloge — GET …/cron — fait arriver un e-mail simple, auquel le pilote en « Simples »
 * prévoit de répondre dans 10 minutes). Chaque phrase vient de panel/public/dashboard/boite.js (vuePilote) ou de
 * panel/src/boite_ia.php (bmPiloteEcrire, bmPiloteTourner) et panel/src/LISEZMOI.md. */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-10-pilote', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_ia.php'], avant: B.ouverte() });

  /* L'onglet */
  await f.onglet({
    id: 'boite-pilote', titre: 'Le Pilote',
    resume: 'Le pilote automatique : trois crans, le temps gagné, ce qu’il a fait, ce qu’il a laissé au magasin, et ses garde-fous fixes.',
    accroche: 'Des réponses, dans des garde-fous',
    programme: ['Régler le pilote', 'Traiter ce que le pilote a laissé', 'Annuler un envoi du pilote'],
  });
  await f.cliquer('[data-bxmode="pilote"]', { apres: 1300 });
  await f.montrer('.bx-crans', 'Trois crans');
  await f.dire('Trois crans : <b>Éteint</b>, <b>Brouillons</b>, <b>Simples</b>. Il est éteint par défaut.', 4400);
  await f.dire('Ici, « Brouillons » : il rédige dans les Brouillons, vous envoyez.', 3600);
  await f.montrer('.bx-pi-gain', 'Temps gagné');
  await f.dire('Le <b>temps gagné</b> sur sept jours, et le dernier passage de l’horloge.', 3800);
  await f.montrer('.bx-pi-chiffres', 'Les chiffres');
  await f.dire('Envoyées seules, brouillons déposés, en partance, annulées, et ce qui est <b>pour vous</b>.', 4600);
  await f.montrer('.bx-pi-fil', 'Ce que le pilote a fait');
  await f.dire('« <b>Ce que le pilote a fait</b> » : sept jours, avec la confiance de l’IA.', 3800);
  await f.montrer('.bx-pi-a', 'Ce qui vous attend');
  await f.dire('« <b>Ce qui vous attend</b> » : ce qu’il vous laisse, et pourquoi.', 3600);
  await f.montrer('.bx-gardes', 'Les garde-fous');
  await f.dire('Des <b>garde-fous fixes</b> : jamais seul sur les prix, les remises, les réclamations, les allergènes, les commandes, les professionnels, le personnel.', 6400);
  await f.dire('Il s’arrête sous <b>85 %</b> de confiance, et laisse <b>10 minutes</b> pour annuler chaque envoi.', 4400);
  await f.cacher(300); await f.taire(200);

  /* Régler le pilote */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-pilote-regler', titre: 'Régler le pilote',
    resume: 'Choisir le cran : Éteint, Brouillons ou Simples. « Simples » demande une confirmation ; « Éteint » annule les envois prévus.',
    points: ['Choisir le cran', '« Simples » : confirmer', '« Éteint » annule les envois prévus'] });
  await f.cliquer('[data-bxmode="pilote"]', { apres: 900 });
  await f.montrer('.bx-cran:has(b:text-is("Simples"))', 'Simples');
  await f.dire('« <b>Simples</b> » : il envoie seul les réponses simples.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-bxpin="2"]', 'Passer le pilote en « Simples » ?',
    'Le pilote répondra seul, depuis magasin.demo@exemple.be, aux e-mails simples qui arrivent à partir de maintenant : 10 minutes après les avoir lus, annulables jusque-là. Jamais aux prix, remises, réclamations, allergènes, commandes ni aux professionnels.', { apres: 1200 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Simples');
  await f.dire('Confirmé : il ne lit que les e-mails qui arrivent <b>après</b>, jamais l’arriéré.', 4000);
  await f.montrer('.bx-cran:has(b:text-is("Éteint"))', 'Éteint');
  await f.dire('Revenir à « Éteint » annule les envois prévus.', 3000);
  await f.cacher(300); await f.taire(200);

  /* Traiter ce que le pilote a laissé */
  await B.sousLaDiapo(f);
  await f.fonction({ id: 'boite-pilote-traiter', titre: 'Traiter ce que le pilote a laissé',
    resume: 'Dans « Ce qui vous attend » : « Ouvrir l’e-mail » pour y répondre, ou « C’est traité » quand c’est fait.',
    points: ['« Ce qui vous attend »', '« Ouvrir l’e-mail »', 'ou « C’est traité »'] });
  await f.cliquer('[data-bxmode="pilote"]', { apres: 900 });
  await f.montrer('.bx-pi-a:has([data-bxpiv="57"])', 'Rien à répondre');
  await f.dire('Une facture : le pilote n’avait <b>rien à répondre seul</b>, il la laisse au magasin.', 4200);
  await f.montrer('.bx-pi-a:has([data-bxpiv="57"]) [data-bxbro]', 'Ouvrir l’e-mail');
  await f.dire('« Ouvrir l’e-mail » la montrerait en Classique.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxpiv="57"]', { apres: 1200 });
  await f.montrer('.bx-pi-fil .bx-pi-l:first-of-type', 'Traité');
  await f.dire('« <b>C’est traité</b> » : elle quitte « Ce qui vous attend » et passe au fil, marquée « traité ».', 4600);
  await f.cacher(300); await f.taire(200);

  /* Annuler un envoi (sous la diapositive : un passage de l'horloge apporte un e-mail simple) */
  await B.sousLaDiapo(f, { ecritures: [['/dashboard/boite/cron']] });
  await f.fonction({ id: 'boite-pilote-annuler', titre: 'Annuler un envoi du pilote',
    resume: 'En « Simples », une réponse part 10 minutes après la lecture : « Annuler l’envoi » l’arrête jusque-là.',
    points: ['Une réponse « part dans N min »', '« Annuler l’envoi »'] });
  await f.cliquer('[data-bxmode="pilote"]', { apres: 1200 });
  await f.montrer('.bx-pi-l:has([data-bxpia])', 'Part dans 10 min');
  await f.dire('En « Simples » : un merci pour une tarte, réponse simple ; elle <b>part dans 10 minutes</b>.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bxpia]', { apres: 1200 });
  await B.enHaut(f);
  await f.montrer('.bx-ok', 'Annulé');
  await f.dire('« <b>Annuler l’envoi</b> » : la réponse ne partira pas.', 3000);
  await f.montrer(f.p.locator('.bx-pi-fil .bx-pi-l').filter({ hasText: 'annulé par le magasin' }).first(), 'Annulé');
  await f.dire('Le fil la garde, marquée « annulé ».', 2600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
