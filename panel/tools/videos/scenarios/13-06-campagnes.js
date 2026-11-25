/* Offres › Newsletter › Campagnes (?vue=offres, onglet « Newsletter »), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    offres-nl-campagnes   le mode test, les chiffres, les bases, les filtres, le tableau des campagnes
 *   fonction  offres-nl-envoyer     « envoyer maintenant → » sous une campagne programmée (mode test : rien ne part)
 *   fonction  offres-nl-retirer     « retirer × » sous une campagne du magasin pas encore envoyée
 * Données fictives : mocks/13_offres.js (la newsletter du Magasin démo, en mode test) ; avant de filmer, on programme
 * « Happy hour — Magasin démo » (le 27/11), la campagne qu'on retire. Chaque phrase vient de
 * marketing/public/assets/js/newsletter.js (I18N fr, rowCampagne, onClick) ou de panel/src/offres_newsletter.php. */
const { Film } = require('../film');

const API = '../api/cockpit';
const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }), [API + chemin, corps]);

module.exports = async function () {
  const f = await Film.ouvrir('13-06-campagnes', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'marketing/public/assets/js/newsletter.js', 'panel/src/offres_newsletter.php'],
    avant: async p => {
      await poster(p, '/dashboard/marketing/newsletter/campagnes', { role: '4', name: 'Happy hour — Magasin démo', segment: 'tous-4', langs: ['fr', 'nl'], templateId: 'happy',
        channel: 'email', subjects: { fr: 'Après 17h, c’est moins cher', nl: 'Na 17u is het goedkoper' }, sendMode: 'manual', date: '2026-11-27', time: '08:30', maxVouchers: '', sender: '4', testSent: false, lien: '' });
    },
  });
  const ligne = nom => f.p.locator('.nl-row').filter({ hasText: nom }).first();
  // Le message en bas de l'écran (position fixe) : un cadre sans faire défiler la page.
  const toast = async (texte, etiq) => { const r = await f.rect(f.p.getByText(texte, { exact: false }).first(), false); await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq]); };

  /* L'onglet */
  await f.onglet({
    id: 'offres-nl-campagnes', titre: 'Newsletter : les campagnes',
    resume: 'Les campagnes du magasin à ses clients : le mode test, les contacts opt-in, les bases, les filtres et le tableau des envois.',
    accroche: 'Écrire à ses clients',
    programme: ['Envoyer maintenant une campagne programmée', 'Retirer une campagne programmée'],
  });
  await f.cliquer('[data-ofong="news"]', { apres: 1600 });
  await f.montrer('.of-nl .nl-top', 'Newsletter');
  await f.dire('L’onglet « <b>Newsletter</b> » : les campagnes du magasin à ses clients, par e-mail ou par SMS.', 4800);
  await f.montrer(f.p.getByText('Mode test — aucun envoi ne part', { exact: false }).first(), 'Mode test');
  await f.dire('Tant que la marque n’a pas lancé les envois : <b>mode test</b>, aucun envoi ne part.', 4200);
  await f.montrer('.nl-body > div:first-child > div:last-child', 'Les chiffres');
  await f.dire('En haut : les contacts <b>opt-in</b>, les envois du mois et les vouchers utilisés sur ceux prévus.', 4800);
  await f.montrer('.nl-body > div:nth-child(2)', 'Les bases');
  await f.dire('Les bases de contacts : clients individuels et clients B2B, avec leurs opt-in.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="filtre"][data-v="auto"]', { apres: 700 });
  await f.montrer('.nl-scroll', 'Automatisées');
  await f.dire('« <b>Automatisées</b> » : elles partent à chaque client qui remplit la règle. Seule la marque en crée.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="filtre"][data-v="manual"]', { apres: 700 });
  await f.montrer('.nl-scroll', 'Manuelles');
  await f.dire('« <b>Manuelles</b> » : les envois uniques, à la date choisie.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-a="filtre"][data-v="all"]', { apres: 700 });
  await f.montrer('.nl-tbl.h', 'Les colonnes');
  await f.dire('Pour chaque campagne : l’envoi, le segment, le <b>statut</b>, les ouvertures, les clics, les vouchers.', 5000);
  await f.montrer(ligne('Cougnou 2026'), 'Programmée');
  await f.dire('Sous une campagne du magasin pas encore envoyée : « <b>envoyer maintenant</b> » et « <b>retirer</b> ».', 4800);
  await f.montrer(ligne('La tarte du mois'), 'Envoyée');
  await f.dire('Une campagne envoyée s’ouvre d’un clic : sa rétrospective.', 3400);
  await f.taire(150); await f.cacher(300);
  await f.defiler(-3000);

  /* Envoyer maintenant */
  await f.fonction({ id: 'offres-nl-envoyer', titre: 'Envoyer maintenant une campagne programmée',
    resume: '« envoyer maintenant » avance l’envoi d’une campagne programmée à tout de suite ; en mode test, rien ne part.',
    points: ['La campagne programmée', '« envoyer maintenant → »', 'Confirmer'] });
  await f.montrer(ligne('Cougnou 2026'), 'Cougnou 2026');
  await f.dire('« Cougnou 2026 » est <b>programmée</b> pour le 1er décembre à 8 h 30. On l’envoie tout de suite.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-a="envoyer"][data-v="31"]', 'Envoyer cette campagne maintenant ?', '', { apres: 350 });
  await toast('Programmée pour maintenant', 'Mode test');
  await f.pause(2600);
  await f.cacher(200);
  await f.montrer(ligne('Cougnou 2026'), 'Maintenant');
  await f.dire('L’envoi est avancé à maintenant. En <b>mode test</b>, rien ne part vers les clients.', 4400);
  await f.dire('Une fois les envois lancés par la marque, l’horloge l’envoie par lots de 100 par minute.', 4600);
  await f.taire(150); await f.cacher(300);

  /* Retirer */
  await f.fonction({ id: 'offres-nl-retirer', titre: 'Retirer une campagne programmée',
    resume: '« retirer × » sous une campagne du magasin pas encore envoyée : elle quitte le tableau. Une campagne envoyée reste dans l’historique.',
    points: ['La campagne pas encore envoyée', '« retirer × »', 'Confirmer'] });
  await f.montrer(ligne('Happy hour'), 'Happy hour');
  await f.dire('« Happy hour », programmée pour le 27 novembre, n’a plus lieu d’être.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-a="retirer"][data-v="32"]', 'Retirer cette campagne ?', '', { apres: 1200 });
  await f.montrer('.nl-scroll', 'Retirée');
  await f.dire('Elle quitte le tableau. Une campagne <b>envoyée</b>, elle, reste dans l’historique.', 4400);
  await f.montrer(ligne('Réveil 45 jours'), 'De la marque');
  await f.dire('Seules les campagnes du magasin se retirent : l’automatisation de la marque n’a pas ce lien.', 4800);
  await f.taire(150); await f.cacher(300);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
