/* Les gestes communs des vidéos de Paramètres par onglet (24-01-fenetre … 24-04-porte, 26/11/2026) : la roue du rail,
 * sa fenêtre, Mails et automatisations. Données fictives : mocks/24_parametres.js, mocks/70_boite.js. Pas un scénario :
 * tourner.js ne lance que les fichiers NN-…. */
const API = '../api/cockpit';
const SOURCES = ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/parametres.css', 'panel/public/dashboard/automatisations.js',
  'panel/public/dashboard/automatisations.css', 'panel/src/automatisations.php'];

/** Un POST JSON à la fausse API, depuis la page (hors film). */
const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()),
  [API + chemin, Object.assign({ shop: '4' }, corps || {})]);

/** L'état de départ (Film.ouvrir({avant})) : les notifications « pas encore demandées », comme sur l'appareil d'un
 * magasin (le Chromium sans écran du tournage les dit refusées d'office, et « Alertes de stock » afficherait
 * « notifications bloquées dans les réglages du navigateur ») ; la boîte du magasin ouverte sur l'appareil (sauf
 * ouverte: false), puis `plus`, la page relue. */
const depart = ({ ouverte = true, plus } = {}) => async p => {
  await p.context().addInitScript(() => { try { Object.defineProperty(Notification, 'permission', { get: () => 'default' }); } catch (e) { /* sans Notification */ } });
  if (ouverte) { await poster(p, '/dashboard/boite/ouvrir', { motDePasse: 'demo' }); }
  if (plus) { await plus(p); }
  await p.reload(); await p.waitForTimeout(2500);
  await p.evaluate(() => window.__film.prets());
};

/* La roue est épinglée au bas du rail, la fenêtre des paramètres est fixe : les montrer ou les cliquer sans faire défiler
 * (le haut du rail resterait caché, la page glisserait sous la fenêtre). */
async function cadreFixe(f, sel, etiq, ms) {
  const r = await f.rect(sel, false);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq || '']);
  if (ms) { await f.pause(ms); }
}
/** Un cadre autour d'un grand bloc tel qu'il est à l'écran, coupé aux bords de la fenêtre, sans défiler. */
async function cadreVu(f, sel, etiq, ms) {
  const r = await f.p.locator(sel).first().evaluate(e => {
    const b = e.getBoundingClientRect(), y = Math.max(8, b.top), y2 = Math.min(innerHeight - 8, b.bottom);
    return { x: b.left, y, w: b.width, h: Math.max(12, y2 - y) };
  });
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiq || '']);
  if (ms) { await f.pause(ms); }
}
/** Un cadre autour de tous les éléments d'un sélecteur, sans défiler. */
async function cadreTout(f, sel, etiq, ms) {
  const r = await f.p.evaluate(sel => {
    const L = [...document.querySelectorAll(sel)].map(e => e.getBoundingClientRect()).filter(r => r.width && r.height);
    const x = Math.min(...L.map(r => r.left)), y = Math.min(...L.map(r => r.top)), x2 = Math.max(...L.map(r => r.right)), y2 = Math.max(...L.map(r => r.bottom));
    return { x, y, w: x2 - x, h: y2 - y };
  }, sel);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiq || '']);
  if (ms) { await f.pause(ms); }
}
async function cliquerFixe(f, sel, apres = 900) {
  const r = await f.rect(sel, false);
  await f.aller(r.x + r.w / 2, r.y + r.h / 2); await f.pause(250);
  await f.p.mouse.down(); await f.pause(90); await f.p.mouse.up();
  await f.pause(apres);
}
/** Choisir dans une liste déroulante : le curseur y va, une onde, la valeur (la liste du système ne se filme pas). */
async function choisir(f, sel, valeur, apres = 700, fixe = false) {
  if (fixe) { const r = await f.rect(sel, false); await f.aller(r.x + r.w / 2, r.y + r.h / 2); await f.pause(300); } else { await f.survoler(sel, { pause: 300 }); }
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator(sel).selectOption(valeur);
  await f.pause(apres);
}
/** La roue, puis la fenêtre ; « rappel » : le détail du rappel. */
async function ouvrirFenetre(f, rappel = false) {
  await cliquerFixe(f, '#db-rail [data-par]', 1100);
  if (rappel) { await cliquerFixe(f, '#db-par [data-pari="rappel"]', 900); }
}
/** CONTOURNEMENT, à retirer quand dashboard.js sera corrigé : un réglage du rappel changé dans la fenêtre
 * (parRappelEnvoyer, dashboard.js:7880-7887) ne relit pas Mails et automatisations, ouverte derrière : ses tuiles et sa
 * liste gardent l'ancien réglage jusqu'au prochain chargement. Pour le film, on la relit (DashAuto.relire). */
async function relirePage(f) {
  await f.p.evaluate(() => window.DashAuto && window.DashAuto.relire());
  await f.pause(300);
}
/** Pour le film seulement : de la marge sous la page, pour que le bas (l'équipe, « Envoyer un essai ») remonte au-dessus
 * des sous-titres quand on le montre. */
async function marge(p) {
  const css = '.au{padding-bottom:170px}';
  await p.context().addInitScript(c => { document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = c; document.head.appendChild(s); }); }, css);
  await p.addStyleTag({ content: css });
}

module.exports = { API, SOURCES, poster, depart, cadreFixe, cadreVu, cadreTout, cliquerFixe, choisir, ouvrirFenetre, relirePage, marge };
