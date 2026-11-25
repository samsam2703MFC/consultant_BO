/* Mon équipe › « Recopier la semaine précédente » (?vue=equipe ; option « Recopier »), et sa fonction en chapitre
 * (24/11/2026) :
 *   onglet    equipe-recopie   l'aperçu : les services proposés jour par jour, le budget avant → après, les
 *                                      écartés et leur motif (déjà au planning, chevauche)
 *   fonction  equipe-recopier          décocher un service, « Recopier N services » : la semaine se remplit
 * Avant de filmer : l'option « Recopier » allumée ; deux services déjà posés le lundi 30 novembre (Lucie, comme la
 * semaine d'avant ; Antoine, qui chevauche) ; la semaine du 30 montrée. Données fictives : mocks/20_equipe.js. Chaque
 * phrase vient de panel/public/dashboard/equipe.js ou de panel/src/LISEZMOI.md (equipe.php). */
const { Film } = require('../film');
const E = require('./equipe-onglets');

module.exports = async function () {
  const f = await Film.ouvrir('02-12-recopier', { vue: 'equipe', serie: 'Mon équipe', parent: 'equipe-horaires', sources: E.SOURCES,
    avant: async p => {
      await E.options(p, { recopie: true });
      await E.envoyer(p, '/dashboard/equipe/service', { employe: 210, date: '2026-11-30', de: '06:45', a: '12:45' });
      await E.envoyer(p, '/dashboard/equipe/service', { employe: 202, date: '2026-11-30', de: '06:00', a: '10:00' });
      await E.recharger(p); await E.planning(p, { pas: 2 });
    } });

  /* L'onglet */
  await f.onglet({
    id: 'equipe-recopie', titre: 'Recopier la semaine précédente',
    resume: 'La semaine d’avant reportée de sept jours : les services proposés à cocher, et ceux écartés avec leur motif (déjà au planning, chevauche…).',
    accroche: 'La semaine d’avant, en un geste',
    programme: ['Recopier la semaine précédente'],
  });
  await f.montrer('.eqp-menu', 'Préparer la semaine');
  await f.dire('Avec l’option « Recopier » : « <b>Préparer la semaine</b> » › Recopier la semaine précédente.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-menu summary', { apres: 700 });
  await f.cliquer('[data-eqp-recopier]', { apres: 1500 });
  await f.montrer('.eqp-rec .qui', 'L’aperçu');
  await f.dire('Les services du 23 au 29 novembre, reportés sur la semaine du 30. Ceux déjà prévus <b>restent</b>.', 5000);
  await f.montrer('.eqp-rec-r', 'Ce qui sera recopié');
  await f.dire('Ce qui sera recopié : les services cochés, leurs heures et leur coût.', 3600);
  await f.montrer('.eqp-rec-b', 'Le budget');
  await f.dire('Le <b>budget de la semaine</b>, avant et après, et ce qu’il faudrait retirer pour revenir à 17 %.', 5000);
  await f.montrer('.eqp-rec-liste .eqp-opt-g', 'Lundi ← lundi 23');
  await f.dire('Jour par jour, chaque service à cocher ; la flèche dit le jour d’où il vient.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-rec-d summary', { apres: 900 });
  await f.montrer('.eqp-rec-d', 'Les écartés');
  await f.dire('Les <b>écartés</b> et leur motif : déjà au planning, ou chevauche un service prévu.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.eqp-rec .confirm__actions [data-eqp-fermer]', { apres: 800 });

  /* Recopier la semaine précédente */
  await f.fonction({ id: 'equipe-recopier', titre: 'Recopier la semaine précédente',
    resume: 'Lire l’aperçu, cocher ou décocher les services, puis les recopier au panel : la semaine se remplit, à ajuster ensuite jour par jour.',
    points: ['« Préparer la semaine › Recopier »', 'Lire l’aperçu', 'Cocher ou décocher', '« Recopier N services »'] });
  await f.cliquer('.eqp-menu summary', { apres: 700 });
  await f.cliquer('[data-eqp-recopier]', { apres: 1500 });
  const ines = '.eqp-rec-l:has-text("Inès Fontaine"):has-text("14:45–19:15")';
  await f.montrer('.eqp-rec-l:has-text("Emma Garnier"):has-text("13:00–19:15")', 'Vendredi · Emma');
  await f.dire('Vendredi, Emma tient le comptoir de 13 h à 19 h 15…', 3000);
  await f.montrer(ines, 'Vendredi · Inès');
  await f.dire('… Inès, en même temps, de 14 h 45 : on la <b>décoche</b>.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(ines + ' input', { apres: 900 });
  await f.montrer('.eqp-rec-b', 'Le budget');
  await f.dire('Le budget de la semaine se recalcule à chaque case.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-eqp-rec-ok]', { apres: 2000 });
  await f.montrer('.eqp-flash', 'Recopié');
  await f.dire('Les services sont au panel. <b>Ajustez maintenant jour par jour.</b>', 3600);
  await f.montrer('.eqp-tab tbody tr:nth-child(2)', 'La semaine du 30');
  await f.dire('La semaine du 30 est remplie.', 2600);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
