/* Offres › Newsletter › la rétrospective d'une campagne envoyée, et sa fonction en chapitre (24/11/2026) :
 *   onglet    offres-nl-retrospective   les résultats d'une campagne et son parcours, comparés à la précédente
 *   fonction  offres-nl-test-ab         « Dupliquer pour test A/B » : l'assistant à l'étape Texte, l'objet « (B) »,
 *                                       puis « Programmer l'envoi » : la version B rejoint le tableau
 * Données fictives : mocks/13_offres.js (« La tarte du mois — octobre », envoyée le 8/10). Chaque phrase vient de
 * marketing/public/assets/js/newsletter.js (vRetro, I18N fr, onClick « dupAB », nextStep). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-07-retrospective', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
  });
  const ligne = nom => f.p.locator('.nl-row').filter({ hasText: nom }).first();
  // Le message en bas de l'écran (position fixe) : un cadre sans faire défiler la page.
  const toast = async (texte, etiq) => { const r = await f.rect(f.p.getByText(texte, { exact: false }).first(), false); await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq]); };
  const kpis = '.nl-body > div:nth-child(3)';

  /* L'onglet */
  await f.onglet({
    id: 'offres-nl-retrospective', titre: 'Newsletter : la rétrospective',
    resume: 'Les résultats d’une campagne envoyée — envois, ouvertures, clics, vouchers — et son parcours comparé à la campagne précédente.',
    accroche: 'Ce que la campagne a donné',
    programme: ['Dupliquer une campagne pour un test A/B'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.montrer(ligne('La tarte du mois'), 'Envoyée');
  await f.dire('Dans le tableau des campagnes, une campagne <b>envoyée</b> s’ouvre d’un clic.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(ligne('La tarte du mois'), { apres: 1000 });
  await f.montrer('.nl-body > div:nth-child(2)', 'La campagne');
  await f.dire('Sa <b>rétrospective</b> : le nom, la date d’envoi, le segment et l’objet.', 4000);
  await f.montrer(kpis, 'Les résultats');
  await f.dire('Les envois, les <b>ouvertures</b>, les <b>clics</b>, les vouchers activés et le revenu attribué.', 5000);
  await f.montrer(kpis + ' > div:nth-child(2)', 'Ouvertures');
  await f.dire('Sous chaque chiffre, l’écart avec la campagne précédente : en points pour les ouvertures et les clics.', 5200);
  await f.montrer(kpis + ' > div:nth-child(4)', 'Vouchers');
  await f.dire('Les vouchers activés, sur ceux prévus : 23 sur 30.', 3400);
  await f.cacher(250);
  await f.montrer('.nl-body > div:nth-child(4)', 'Le parcours');
  await f.dire('Le <b>parcours</b>, de l’envoi au voucher. Le trait noir marque la campagne précédente.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.montrer('[data-a="dupAB"]', 'Test A/B');
  await f.dire('En haut à droite : « Dupliquer pour test A/B ».', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.nl-body > button[data-a="view"]', { apres: 900 });
  await f.montrer('.nl-scroll', 'Les campagnes');
  await f.dire('« ← Campagnes » ramène au tableau.', 2800);
  await f.taire(150); await f.cacher(300);

  /* Dupliquer pour test A/B */
  await f.fonction({ id: 'offres-nl-test-ab', titre: 'Dupliquer une campagne pour un test A/B',
    resume: 'La même campagne avec un autre objet : l’assistant s’ouvre à l’étape Texte, même segment, même modèle, l’objet suivi de « (B) » ; « Programmer l’envoi » l’ajoute au tableau.',
    points: ['Ouvrir la rétrospective', '« Dupliquer pour test A/B »', 'Changer l’objet', '« Programmer l’envoi »'], ms: 4800 });
  await f.cliquer(ligne('La tarte du mois'), { apres: 800 });
  await f.cliquer('[data-a="dupAB"]', { apres: 900 });
  await f.montrer('[data-f="subject"]', 'Objet (B)');
  await f.dire('L’assistant s’ouvre à l’étape <b>Texte</b> : même segment, même modèle, l’objet suivi de « (B) ».', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-f="subject"]', { apres: 200 });
  await f.p.keyboard.press('Control+A');
  await f.taper('Une tarte diamant vous attend (B)', 45);
  await f.pause(500);
  await f.montrer('.nl-sticky', 'L’aperçu');
  await f.dire('On change ce qu’on veut comparer, ici l’<b>objet</b> ; l’aperçu suit.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="next"]', { apres: 900 });
  await f.montrer('[data-a="next"]', 'Programmer l’envoi');
  await f.dire('Rien n’est écrit tant qu’on n’a pas programmé : à l’étape Envoi, « <b>Programmer l’envoi</b> ».', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="next"]', { apres: 350 });
  await toast('Campagne programmée', 'Mode test');
  await f.pause(2600);
  await f.cacher(200);
  await f.montrer(ligne('(B)'), 'La version B');
  await f.dire('La version B rejoint le tableau, « <b>Programmé</b> ». En mode test, rien ne part.', 4600);
  await f.taire(150);
  await f.pause(1000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
