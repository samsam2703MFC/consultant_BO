/* Les vidéos par onglet de l'Opérationnel (?vue=ops), 01-01 à 01-11 (25/11/2026) : ce qu'elles partagent.
 * Aucun geste de l'Opérationnel n'écrit au serveur (seul « M'avertir » de la barre Stock le fait, et il demande les
 * notifications du navigateur : pas filmable) : chaque vidéo est un chapitre d'onglet, ses gestes de lecture dedans.
 * Les écrans au téléphone (Opérationnel, Exploitation, Contrôle, onglets 12 à 14 de la liste) ne se filment pas ici. */
const { Film } = require('../film');

const SRC = ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'];

/** Ouvre l'Opérationnel pour une vidéo d'onglet. */
const ouvrir = (nom, o = {}) => Film.ouvrir(nom, Object.assign({ vue: 'ops', serie: 'Opérationnel', parent: 'decouvrir', chargement: 3000 }, o, { sources: SRC.concat(o.sources || []) }));

/** Met un élément en haut de l'écran (marge en px sous le bord), en douceur, même s'il est déjà visible. */
async function enHautDe(f, sel, marge = 24) {
  const loc = typeof sel === 'string' ? f.p.locator(sel).first() : sel;
  const dy = await loc.evaluate((el, m) => el.getBoundingClientRect().top - m, marge);
  if (Math.abs(dy) > 4) { await f.defiler(dy); }
}
/** Un cadre, un sous-titre, puis rien : le geste le plus courant. */
async function dire(f, sel, etiq, texte, ms) {
  if (sel) { await f.montrer(sel, etiq); }
  await f.dire(texte, ms);
}
async function rien(f) { await f.taire(150); await f.cacher(250); }

module.exports = { SRC, ouvrir, enHautDe, dire, rien };
