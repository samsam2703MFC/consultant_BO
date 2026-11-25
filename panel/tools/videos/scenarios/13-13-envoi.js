/* Offres › Newsletter › Nouvelle campagne, étape 4 « Envoi » (?vue=offres, onglet « Newsletter »), et ses fonctions
 * en chapitres (24/11/2026) :
 *   onglet    offres-nl-envoi          envoi unique, la date et l'heure, l'expéditeur, l'anti-spam, les vouchers, le récapitulatif
 *   fonction  offres-nl-envoyer-test   « Envoyer un test à 3 adresses » : la ligne du test se coche, « Prêt à partir »
 *   fonction  offres-nl-programmer     la date, l'heure, les vouchers, « Programmer l'envoi » : la campagne au tableau
 * Données fictives : mocks/13_offres.js (mode test : rien ne part vers les clients ; le test aux adresses de la
 * marque, lui, part). Chaque phrase vient de marketing/public/assets/js/newsletter.js (step5, checks, onClick « test »,
 * nextStep, I18N fr) ou de marketing/src/newsletter_envoi.php (nlEnvoyerTest : « même en mode test »). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-13-envoi', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const carte = '.nl-wiz > div:first-child';
  // 1 titre, 2 mode d'envoi, 3 date et heure, 4 conseil d'heure, 5 expéditeur, 6 anti-spam, 7 vouchers, 8 récapitulatif
  const bloc = n => carte + ' > :nth-child(' + n + ')';
  const ligne = nom => f.p.locator('.nl-row').filter({ hasText: nom }).first();
  // Le message en bas de l'écran (position fixe) : un cadre sans faire défiler la page.
  const toast = async (texte, etiq) => { const r = await f.rect(f.p.getByText(texte, { exact: false }).first(), false); await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq]); };

  /* L'onglet */
  await f.onglet({
    id: 'offres-nl-envoi', titre: 'Nouvelle campagne · 4 Envoi',
    resume: 'Quand part le mail : la date et l’heure (envoi unique pour un magasin), l’expéditeur, le contrôle anti-spam, les vouchers et le récapitulatif.',
    accroche: 'Quand part-il ?',
    programme: ['Envoyer un test', 'Programmer une campagne'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.cliquer('[data-a="wizard"]', { apres: 800 });
  await f.cliquer('[data-a="next"]', { apres: 700 });
  await f.cliquer('[data-a="next"]', { apres: 700 });
  await f.cliquer('[data-a="next"]', { apres: 1000 });
  await f.montrer(bloc(1), 'Étape 4 · Envoi');
  await f.dire('« Quand part-il ? » Le mail part par lots de 100 par minute, un mail par client et par jour au plus.', 5400);
  await f.montrer(bloc(2), 'Envoi unique');
  await f.dire('Pour un magasin, l’<b>envoi unique</b> : il part une fois, à la date choisie. Les automatisations sont l’affaire de la marque.', 6200);
  await f.montrer(bloc(3), 'Date et heure');
  await f.dire('La <b>date</b> et l’<b>heure</b> ; dessous, un conseil : ce segment ouvre le plus entre 7 h 30 et 9 h en semaine.', 5800);
  await f.montrer(bloc(5), 'L’expéditeur');
  await f.dire('L’<b>expéditeur</b> : le nom et l’adresse du magasin, les réponses vers la même adresse, le domaine vérifié.', 5600);
  await f.montrer(bloc(6), 'Contrôle anti-spam');
  await f.dire('Le <b>contrôle anti-spam</b> : sept points. Ici, un seul reste à corriger : le test.', 4800);
  await f.montrer(bloc(7), 'Vouchers max');
  await f.dire('<b>Vouchers max</b> : des codes uniques générés au départ, premier arrivé, premier servi. Vide : pas de voucher.', 5800);
  await f.montrer(bloc(8), 'Le récapitulatif');
  await f.dire('Le <b>récapitulatif</b> : la base, le segment, les langues, le canal, la date, le modèle, l’expéditeur, l’objet, le lien.', 6200);
  await f.taire(150); await f.cacher(300);

  /* Envoyer un test */
  await f.fonction({ id: 'offres-nl-envoyer-test', titre: 'Envoyer un test',
    resume: '« Envoyer un test à 3 adresses » : le mail part aux adresses de test de la marque, même en mode test ; le contrôle anti-spam se coche.',
    points: ['Étape « Envoi »', '« Envoyer un test à 3 adresses »', 'Le contrôle se coche'] });
  await f.montrer(bloc(6), '1 · À corriger');
  await f.dire('Avant le test, le contrôle anti-spam dit : <b>1 point à corriger</b> — test envoyé et rendu vérifié.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="test"]', { apres: 350 });
  await toast('Test envoyé à 3 adresse', 'Test envoyé');
  await f.pause(2600);
  await f.cacher(200);
  await f.montrer(bloc(6), 'Prêt à partir');
  await f.dire('Le test part aux adresses de test de la marque, même en mode test. L’anti-spam passe à « <b>Prêt à partir</b> ».', 5800);
  await f.dire('On ouvre le test sur Gmail, Outlook, Apple Mail : on vérifie le rendu avant de programmer.', 4600);
  await f.cacher(250);
  await f.montrer('[data-a="test"]', 'Test envoyé ✓');
  await f.taire(150);
  await f.pause(2000);
  await f.cacher(300);

  /* Programmer une campagne */
  await f.fonction({ id: 'offres-nl-programmer', titre: 'Programmer une campagne',
    resume: 'La date et l’heure, les vouchers, l’anti-spam : « Programmer l’envoi », et la campagne rejoint le tableau. En mode test, rien ne part.',
    points: ['La date et l’heure', 'Les vouchers max', '« Programmer l’envoi »'] });
  await f.survoler('[data-f="date"]', { pause: 300 });
  await f.p.locator('[data-f="date"]').fill('2026-11-26');
  await f.pause(500);
  await f.survoler('[data-f="time"]', { pause: 300 });
  await f.p.locator('[data-f="time"]').fill('07:45');
  await f.pause(500);
  await f.montrer(bloc(3), 'Jeudi 26/11, 7 h 45');
  await f.dire('La date et l’heure : un jeudi matin, à 7 h 45.', 3200);
  await f.cacher(150); await f.taire(150);
  // Le champ est de type number et l'écran se redessine à chaque touche : tapé chiffre par chiffre, « 20 » devient
  // « 02 » (le curseur revient au début, marketing/public/assets/js/newsletter.js, render). On le remplit d'un coup.
  await f.cliquer('[data-f="maxVouchers"]', { apres: 300 });
  await f.p.locator('[data-f="maxVouchers"]').fill('20');
  await f.pause(700);
  await f.montrer(bloc(7), '20 vouchers');
  await f.dire('Vingt vouchers au plus pour cette campagne.', 2800);
  await f.cacher(250);
  await f.montrer(bloc(8), 'Le récapitulatif');
  await f.dire('On relit le récapitulatif : envoi unique, le 26/11 à 7 h 45, depuis l’adresse du magasin.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="next"]', { apres: 350 });
  await toast('Campagne programmée', 'Mode test');
  await f.pause(2600);
  await f.cacher(200);
  await f.montrer(ligne('26 nov.'), 'Programmé');
  await f.dire('« <b>Programmer l’envoi</b> » : la campagne rejoint le tableau, « Programmé ». En mode test, rien ne part.', 5400);
  await f.taire(150);
  await f.pause(1600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
