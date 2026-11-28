/* Mon équipe › la fiche d'une personne › l'onglet « Ventes » (?vue=equipe, 24/11/2026), sans fonction qui écrit :
 *   onglet    equipe-ventes   les 12 dernières semaines complètes : vendu, panier moyen, pièces par ticket, tickets, rang
 *                             au panier moyen ; le graphique (ventes en €, panier moyen, pièces par ticket), le survol
 *                             d'une semaine, « Voir en tableau »
 * Données fictives : mocks/20_equipe.js (les ventes de Camille Roussel, du 24 août au 15 novembre 2026). Chaque phrase
 * vient de panel/public/dashboard/equipe.js (fpVentes, fpGraphe) ou de l'en-tête de panel/src/equipe_fiche.php. */
const { Film } = require('../film');

const SOURCES = ['panel/public/dashboard/equipe.js', 'panel/public/dashboard/equipe.css', 'panel/src/equipe_fiche.php'];

module.exports = async function () {
  const f = await Film.ouvrir('02-18-ventes', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: SOURCES });

  await f.onglet({
    id: 'equipe-ventes', titre: 'Les ventes d’une personne',
    resume: 'Dans sa fiche, ses 12 dernières semaines complètes : vendu, panier moyen, pièces par ticket, tickets et rang, face à l’équipe et à la cible du réseau ; en graphique ou en tableau.',
    accroche: 'Ses 12 dernières semaines',
    programme: ['Les chiffres des 12 semaines', 'Le graphique : trois mesures', 'Le tableau'],
  });
  await f.cliquer('[data-eqp-sel="201"]', { apres: 700 });
  await f.cliquer('.eqp-fiche [data-eqp-fiche]', { apres: 1400 });
  await f.cliquer('[data-eqp-fp-onglet=ventes]', { apres: 1500 });
  await f.montrer('.eqp-fp-kpis', 'Ses 12 semaines');
  await f.dire('« <b>Ventes</b> » : ses 12 dernières semaines complètes, du 24 août au 15 novembre.', 4400);
  await f.montrer('.eqp-fp-kpis .kpi:nth-child(1)', 'Vendu');
  await f.dire('Ce qu’elle a vendu, et sa part des ventes du magasin.', 3200);
  await f.montrerTout('.eqp-fp-kpis .kpi:nth-child(2), .eqp-fp-kpis .kpi:nth-child(3)', 'Panier · pièces');
  await f.dire('Son <b>panier moyen</b> et ses <b>pièces par ticket</b>, face à l’équipe et à la cible du réseau.', 5000);
  await f.montrerTout('.eqp-fp-kpis .kpi:nth-child(4), .eqp-fp-kpis .kpi:nth-child(5)', 'Tickets · rang');
  await f.dire('Ses tickets, et son <b>rang</b> au panier moyen dans l’équipe.', 3400);
  await f.montrer('.eqp-fp-gr', 'Ventes en €');
  await f.dire('Le graphique : ses ventes, semaine par semaine, et le trait gris de sa moyenne.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.survoler('[data-eqp-fp-sem="4"]', { pause: 1200 });
  await f.survoler('[data-eqp-fp-sem="8"]', { pause: 600 });
  await f.dire('La souris sur une semaine : ses ventes, ses tickets, son panier et ses pièces.', 4200);
  await f.taire(150);
  await f.cliquer('[data-eqp-fp-mode=panier]', { apres: 1200 });
  await f.montrer('.eqp-fp-gr', 'Panier moyen');
  await f.dire('« Panier moyen » : sa courbe, le trait de l’<b>équipe</b> en gris et la <b>cible du réseau</b> en bleu.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-mode=pieces]', { apres: 1200 });
  await f.montrer('.eqp-fp-gr', 'Pièces par ticket');
  await f.dire('« Pièces par ticket » : les lignes par ticket — un ticket de 3 croissants compte 1.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-fp-tableau]', { apres: 1200 });
  await f.montrer('.eqp-fp-sem', 'En tableau');
  await f.dire('« <b>Voir en tableau</b> » : les mêmes semaines en chiffres. Un clic revient au graphique.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-2000, '.eqp-fp-voile');
  await f.cliquer('[data-eqp-fp-tableau]', { apres: 1000 });
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
