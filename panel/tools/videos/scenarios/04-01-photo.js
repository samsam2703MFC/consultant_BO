/* Tâches › la photo en grand (la loupe du contrôle), un onglet sans fonction qui écrive (26/11/2026) :
 *   onglet    taches-photo   la vignette d'une tâche, dans une checklist dépliée ; la photo en grand, le repère entouré,
 *                            la note, le constat, qui l'a rendue et qui l'a notée ; ‹ ›, les flèches du clavier, la
 *                            bande des vignettes du jour ; ✕ ou Échap (lectures)
 * Les autres gestes de la page (jour, semaine, guide des codes, filtres, checklists, recherche : des lectures) sont dans
 * la vidéo de la page (04-taches) ; « Ouvrir la photo seule ↗ » ouvre un nouvel onglet : il ne se filme pas.
 * Données fictives : mocks/lib/taches.js (aujourd'hui) et 04_taches.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (cqLoupe, cqAller, la loupe au clavier). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('04-01-photo', {
    vue: 'taches', serie: 'Tâches', parent: 'taches',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'],
  });

  await f.onglet({
    id: 'taches-photo', titre: 'La photo en grand',
    resume: 'La photo du contrôle en grand, les repères entourés, le constat, qui l’a rendue et qui l’a notée, puis ‹ › et la bande des vignettes du jour.',
    accroche: 'Le contrôle, de près',
    programme: ['Ouvrir la photo d’une tâche', 'La note et le constat', 'Feuilleter les photos du jour', 'Refermer'],
  });
  await f.cliquer('[data-tjcl="2.1.01 · Cuisson du matin"]', { apres: 1000 });
  await f.montrer('.tj-cl.open:has([data-tjcl^="2.1.01"]) .tj-t', 'Une tâche à photo');
  await f.dire('Dans une checklist dépliée, chaque tâche à photo a sa <b>vignette</b>.', 3800);
  await f.montrer('.tj-ph[data-cqvoir="9003"]', 'La vignette');
  await f.dire('Sa pastille dit combien de remarques le contrôleur a laissées.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.tj-ph[data-cqvoir="9003"]', { apres: 1400 });
  await f.montrer('.db-cql .ph', 'La photo en grand');
  await f.dire('La photo en grand : le contrôleur a <b>entouré</b> ce qui ne va pas, avec un numéro.', 4400);
  await f.montrer('.db-cql .fi', 'Le constat');
  await f.dire('À côté : la note, 3 sur 5, une non-conformité mineure ; le constat ; la tâche et sa checklist.', 5000);
  await f.montrer('.db-cql .fi dl', 'Rendue · notée');
  await f.dire('Qui l’a rendue, et quand ; qui l’a notée, et quand.', 3200);
  await f.montrer('.db-cql .hd', 'Sa place');
  await f.dire('En haut, sa place parmi les photos du jour : 1 sur 6.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.db-cql [data-cqnav="1"]', { apres: 1200 });
  await f.dire('› passe à la suivante, ‹ revient : ici, les sandwichs du midi, pas encore notés.', 4400);
  await f.taire(150);
  await f.p.keyboard.press('ArrowRight'); await f.pause(1300);
  await f.dire('Les flèches du clavier aussi.', 2400);
  await f.taire(150);
  // La bande est collée au bas de l'écran : on la montre sans sous-titre (il la couvrirait), puis on le dit.
  await f.montrer('.db-cql .ba', 'Les photos du jour', 2400);
  await f.cacher(150);
  await f.cliquer('.db-cql .ba [data-cqvoir="9002"]', { apres: 1300 });
  await f.montrer('.db-cql .fi', 'Conforme');
  await f.dire('En bas, la <b>bande des vignettes</b> du jour : un clic ouvre la photo. Ici, la vitrine des pâtisseries, notée 4 sur 5 : conforme.', 6000);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Escape');
  await f.pause(900);
  await f.dire('✕, ou Échap, referme la photo : la checklist est toujours dépliée.', 3800);
  await f.taire(150);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
