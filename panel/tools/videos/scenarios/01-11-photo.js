/* Opérationnel › « Photo de contrôle en grand », la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-photo-controle   un clic sur une photo du carrousel : la photo en plein écran, ses repères, sa note et
 *                                le constat, qui l'a rendue et qui l'a notée ; ‹ › ou les flèches du clavier, les
 *                                vignettes du bas ; la loupe suit le filtre choisi ; Échap ferme.
 * Agrandir et feuilleter : des gestes de lecture, sans chapitre à eux. « Ouvrir la photo seule ↗ » ouvre un nouvel
 * onglet du navigateur : pas filmé. Données fictives : mocks/04_taches.js, 10_ops.js. Chaque phrase vient de
 * panel/public/dashboard/dashboard.js (cqLoupe, cqAller, cqPhoto, le clavier : Échap, ← →).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const CQ = '.db-cq', L = '.db-cql';

module.exports = async function () {
  const f = await ouvrir('01-11-photo');

  await f.onglet({
    id: 'ops-photo-controle', titre: 'Une photo de contrôle en grand',
    resume: 'Un clic sur une photo des contrôles : la photo en plein écran avec ses repères, sa note et le constat ; ‹ › ou les flèches du clavier pour feuilleter, Échap pour fermer.',
    accroche: 'Voir ce que le consultant a vu',
    programme: ['La photo et ses repères', 'La note et le constat', 'Feuilleter', 'Fermer'],
  });

  await enHautDe(f, CQ, 16);
  await dire(f, CQ + ' [data-cqvoir="9003"]', 'Une photo', 'Un clic sur une photo des contrôles l’ouvre <b>en grand</b>.', 3400);
  await rien(f);
  await f.cliquer(CQ + ' [data-cqvoir="9003"]', { apres: 1500 });

  await dire(f, L + ' .ph', 'Les repères', 'La photo en plein écran, avec ses <b>repères</b> : ce que le consultant a relevé.', 4600);
  await dire(f, L + ' .fi', 'La note et le constat', 'À côté : la note, le constat, qui l’a rendue, qui l’a notée, et quand.', 4600);
  await dire(f, L + ' .hd > span', '1 / 6', 'En haut, sa place dans la journée : la première des six.', 3400);
  await rien(f);

  await f.cliquer(f.p.locator(L + ' .fl[data-cqnav="1"]'), { apres: 1200 });
  await f.dire('› passe à la suivante : une photo déposée, pas encore notée.', 3600);
  await f.taire(150);
  await f.p.keyboard.press('ArrowRight'); await f.pause(1300);
  await f.dire('Les flèches du clavier feuillettent aussi.', 2800);
  // Les vignettes sont au ras du bas de l'écran : le sous-titre les couvrirait, l'étiquette du cadre parle seule.
  await f.taire(150);
  await f.montrer(L + ' .ba', 'Toutes les photos du filtre', 2600);
  await f.cliquer(f.p.locator(L + ' .ba [data-cqvoir]').nth(4), { apres: 1300 });
  await f.cacher(150);
  await f.dire('Un clic sur une vignette y va : le plan de travail du fournil, conforme.', 4200);
  await f.taire(150);
  await f.montrer(L + ' .hd .x', 'Fermer');
  await f.dire('× ou <b>Échap</b> ferme la photo.', 2600);
  await rien(f);
  await f.p.keyboard.press('Escape'); await f.pause(1300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
