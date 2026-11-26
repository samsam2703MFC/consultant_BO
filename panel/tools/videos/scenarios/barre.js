/* Les gestes communs des vidéos de la barre du haut et de ses fenêtres (01-15 … 01-29, 26/11/2026).
 * Pas un scénario : tourner.js ne lance que les fichiers NN-…. Données fictives : mocks/11_barre_*.js, 10_ops.js. */
const fs = require('fs');
const os = require('os');
const path = require('path');
const API = '../api/cockpit';
const MOCKS = path.join(__dirname, '..', 'mocks');
const attendre = ms => new Promise(r => setTimeout(r, ms));

/** Une écriture à la fausse API, depuis la page (hors film, ou sous une diapositive). */
const ecrire = (p, chemin, corps, methode = 'POST') => p.evaluate(([u, b, m]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()),
  [API + chemin, corps || {}, methode]);

/** La page relue, prête à filmer (le calque chargé). */
async function relire(p) { await p.reload(); await p.waitForTimeout(2600); await p.evaluate(() => window.__film && window.__film.prets()); }

/** Les GET que 00_commun.js sert déjà (vides) et que les vidéos de la barre lisent pleins : GET /helpdesk/magasin
 * (11_barre_bureau.js) et GET /soir/revue (11_barre_soir.js), en routes de la page (elles passent avant le contexte).
 * Le module est requis APRÈS la création du contexte : c'est l'instance que mock.js vient de charger (son état). */
async function prioritaires(p, opts) {
  await require(path.join(MOCKS, '11_barre_bureau.js')).prioritaires(p, opts);
  await require(path.join(MOCKS, '11_barre_soir.js')).prioritaires(p);
}
const REPONSES = () => require(path.join(MOCKS, '11_barre_soir.js')).REPONSES;

/** L'état de la revue du soir pour le film : null (pas commencée) ou { par, statut, reponses }. */
const soirEtat = (p, revue) => ecrire(p, '/__film/soir', { revue });

/** Hors champ : la revue ouverte, puis on avance jusqu'à l'écran voulu (sa classe : rv-ok, rv-pf, data-rvdemain…). On
 * avance en recliquant la réponse déjà donnée (elle s'enregistre de nouveau, à l'identique) ou « Continuer ». */
async function soirJusqua(p, sel, max = 14) {
  if (!(await p.locator('#rv .rv-tel').count())) { await p.locator('#dash [data-rvouvrir]').first().dispatchEvent('click'); await p.waitForTimeout(1200); }
  for (let i = 0; i < max; i++) {
    if (await p.locator('#rv ' + sel).count()) { return; }
    const suiv = p.locator('#rv [data-rvsuivant]');
    if (await suiv.count()) { await suiv.first().dispatchEvent('click'); await p.waitForTimeout(500); continue; }
    const on = p.locator('#rv .rv-chip.on:not([data-rvpar])');
    if (await on.count()) { await on.first().dispatchEvent('click'); await p.waitForTimeout(900); continue; }
    throw new Error('revue du soir : écran sans réponse donnée, avant ' + sel);
  }
  throw new Error('revue du soir : ' + sel + ' introuvable');
}

/** Une « photo » pour les formulaires (demande au bureau, réclamation) : une illustration de la fausse API (lib/taches.js),
 * rendue en JPEG dans le dossier temporaire. */
async function photo(p, nom) {
  const fichier = path.join(os.tmpdir(), 'film-barre', nom + '.png');
  fs.mkdirSync(path.dirname(fichier), { recursive: true });
  const svg = require(path.join(MOCKS, 'lib', 'taches.js')).illustration(nom);
  const pg = await p.context().newPage();
  await pg.setContent('<style>html,body{margin:0}#film-calque{display:none!important}</style>' + svg);
  await pg.locator('svg').first().screenshot({ path: fichier });
  await pg.close();
  return fichier;
}

/** Choisir dans une liste déroulante, filmé : le curseur y va, l'onde, la valeur change. */
async function choisir(f, sel, valeur, ms = 700) {
  const r = await f.survoler(sel);
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [r.x + r.w / 2, r.y + r.h / 2]);
  await f.p.selectOption(sel, valeur); await f.pause(ms);
}

/** Un cadre autour d'un élément tel qu'il est à l'écran, sans faire défiler (coupé aux bords de la fenêtre). */
async function cadre(f, sel, etiq, ms) {
  const r = await f.p.locator(sel).first().evaluate(e => {
    const b = e.getBoundingClientRect(), y = Math.max(8, b.top), y2 = Math.min(innerHeight - 8, b.bottom);
    return { x: b.left, y, w: b.width, h: Math.max(12, y2 - y) };
  });
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiq || '']);
  if (ms) { await f.pause(ms); }
}

/** Un cadre autour de tous les éléments d'un sélecteur, sans faire défiler (la revue du soir est une fenêtre fixe : faire
 * défiler la ferait glisser la page derrière). */
async function cadreTout(f, sel, etiq, ms) {
  const r = await f.p.evaluate(sel => {
    const L = [...document.querySelectorAll(sel)].map(e => e.getBoundingClientRect()).filter(r => r.width && r.height);
    if (!L.length) { return null; }
    const x = Math.min(...L.map(r => r.left)), y = Math.min(...L.map(r => r.top)), x2 = Math.max(...L.map(r => r.right)), y2 = Math.min(innerHeight - 8, Math.max(...L.map(r => r.bottom)));
    return { x, y, w: x2 - x, h: y2 - y };
  }, sel);
  if (!r) { throw new Error('rien pour ' + sel); }
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiq || '']);
  if (ms) { await f.pause(ms); }
}

/** Pour le film seulement, deux réglages du calque tant que la revue du soir est ouverte (html.rv-ouvert, posé par
 * dashboard.js) ; rien ne change dans le dashboard :
 *  - la revue est une colonne pleine hauteur au milieu de l'écran, ses boutons tout en bas : le sous-titre du film (en
 *    bas, au milieu) les couvrirait ; il se range en bas à gauche, à côté d'elle ;
 *  - le curseur du film fait défiler la page jusqu'au bouton qu'il vise (film.js, defilerVers) : derrière la revue, une
 *    fenêtre fixe, la page glisserait pour rien ; elle reste où elle est. */
async function soirFilm(p) {
  const css = 'html.rv-ouvert #film-st{left:28px;max-width:440px;transform:translate(0,10px)}html.rv-ouvert #film-st.on{transform:translate(0,0)}';
  const init = c => {
    document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = c; document.head.appendChild(s); });
    let y0 = 0;
    addEventListener('scroll', () => { const h = document.documentElement; if (!h.classList.contains('rv-ouvert')) { y0 = scrollY; } else if (scrollY !== y0) { scrollTo(0, y0); } }, { passive: true });
  };
  await p.context().addInitScript(init, css);
  await p.addStyleTag({ content: css });
}

/** Pendant la diapositive qui vient (elle couvre l'écran ~0,6 s après son début) : `faire(page)` côté Node. Appeler
 * juste avant f.fonction() ; la diapositive dure au moins 2,6 s. */
function sousLaDiapo(f, faire) { return attendre(900).then(() => faire(f.p)).catch(e => console.log('  sous la diapo :', e.message)); }

module.exports = { API, ecrire, relire, prioritaires, REPONSES, soirEtat, soirJusqua, soirFilm, photo, choisir, cadre, cadreTout, sousLaDiapo, attendre };
