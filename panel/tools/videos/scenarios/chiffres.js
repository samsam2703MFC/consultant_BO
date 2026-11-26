/* Les vidéos par onglet des pages Semaine, Mois et Année (section « Les chiffres » du rail), 06-01 à 08-02 (26/11/2026) :
 * ce qu'elles partagent. Les onglets : la liste validée du 24/11 (lot 3). Les gestes de lecture (ouvrir une semaine, un
 * mois, une année ; déplier une puce ; lire une heure…) se montrent dans la vidéo de l'onglet ; seules la réclamation au
 * fournisseur et la remarque à l'opérateur (fenêtre « Invendus et poubelle », tournées en Semaine) écrivent au serveur.
 * Données fictives : mocks/15_chiffres.js, 15_chiffres_periode.js, 07_mois.js, 08_annee.js. */
const { Film } = require('../film');

const SRC = ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'];
const PAGES = { semaine: { serie: 'Semaine', parent: 'semaine' }, mois: { serie: 'Mois', parent: 'mois' }, annee: { serie: 'Année', parent: 'annee' } };

/** Ouvre une page des chiffres pour une vidéo d'onglet. `puces` : les sections du détail ouvertes d'office (hors film). */
function ouvrir(nom, vue, o = {}) {
  const puces = o.puces, avant = o.avant;
  return Film.ouvrir(nom, Object.assign({ vue, chargement: 3000 }, PAGES[vue], o, {
    sources: SRC.concat(o.sources || []),
    avant: async p => {
      if (puces) {
        await p.evaluate(L => { const O = {}; L.forEach(k => { O[k] = true; }); localStorage.setItem('dbQbPlus', JSON.stringify(O)); }, puces);
        await p.reload(); await p.waitForTimeout(3000);
        await p.evaluate(() => window.__film.prets());
      }
      if (avant) { await avant(p); }
    },
  }));
}

/** Met un élément en haut de l'écran (marge en px sous le bord), en douceur, même s'il est déjà visible. */
async function enHautDe(f, sel, marge = 24) {
  const loc = typeof sel === 'string' ? f.p.locator(sel).first() : sel;
  const dy = await loc.evaluate((el, m) => el.getBoundingClientRect().top - m, marge);
  if (Math.abs(dy) > 4) { await f.defiler(dy); }
}
/** Un cadre, un sous-titre. */
async function dire(f, sel, etiq, texte, ms) {
  if (sel) { await f.montrer(sel, etiq); }
  await f.dire(texte, ms);
}
async function rien(f) { await f.taire(150); await f.cacher(250); }
/** Le haut de la page. */
async function enHaut(f) { await f.defiler(-8000); }

module.exports = { SRC, ouvrir, enHautDe, dire, rien, enHaut };
