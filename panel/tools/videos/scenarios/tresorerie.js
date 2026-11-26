/* Les gestes communs aux vidéos des onglets de la Trésorerie (09-01 à 09-06, 24/11/2026) : la fausse API appelée depuis
 * la page (l'état de départ, hors film), un cadre autour de lignes du plan, le défilement de côté des tableaux plus
 * larges que leur carte, une saisie dans un champ chiffré du module, et le chapitre d'une fonction dont l'état se
 * prépare pendant son intercalaire (la diapositive couvre la page). Données fictives : mocks/09_tresorerie.js. */
const API = '../api/cockpit';
const T = '#db-tresorerie ';
// Les cartes du haut de l'onglet Plan (`f.montrer` prend la première trouvée : la rangée du haut).
const ROUTINE = T + '.tr-g2 > .tr-carte:nth-child(1)';
const DECISION = T + '.tr-g2 > .tr-carte:nth-child(2)';
const GRAPHE = T + '.tr-carte:has(.tr-graph)';
const attendre = ms => new Promise(r => setTimeout(r, ms));

/** Le screencast n'envoie une image que quand l'écran change ; sous une forte charge, la dernière image d'une transition
 * (le cadre qui glisse, le sous-titre qui apparaît) peut se perdre, et l'image d'avant reste à l'écran le temps du
 * sous-titre. `fiabiliser` le prévient : une fois le sous-titre posé et les transitions finies (0,5 s), le curseur bouge
 * d'un pixel et revient, et l'écran final est filmé. La durée de chaque sous-titre ne change pas. */
function fiabiliser(f) {
  const dire = f.dire.bind(f);
  f.dire = async (html, ms) => {
    const total = ms == null ? f.duree(html) : ms;
    if (total < 1200) { return dire(html, ms); }
    await f.p.evaluate(h => window.__film.st(h), html);
    await f.pause(520);
    await f.p.mouse.move(f.souris.x + 1, f.souris.y + 1); await f.pause(60);
    await f.p.mouse.move(f.souris.x, f.souris.y);
    await f.pause(total - 580);
  };
  return f;
}

const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) })
  .then(r => r.json().catch(() => ({}))), [API + chemin, corps]);
const lire = (p, chemin) => p.evaluate(u => fetch(u, { headers: { Accept: 'application/json' } }).then(r => r.json()), API + chemin);

/** Le plan enregistré, modifié comme le ferait un autre écran (la version lue, puis le POST). */
async function modifierPlan(p, fn) {
  const L = await lire(p, '/tresorerie/plan?shop=4');
  fn(L.plan);
  return poster(p, '/tresorerie/plan', { shop: '4', version: L.version, donnees: L.plan });
}

/** Recharge la page sur une vue (l'adresse a pu être réécrite : « vue=frais » devient « vue=tresorerie »). */
async function recharger(p, vue) {
  await p.goto(p.url().replace(/vue=[^&]*/, 'vue=' + vue));
  await p.waitForTimeout(3000);
  await p.evaluate(() => window.__film.prets());
}

/** Un cadre autour de lignes d'un tableau de la Trésorerie, coupé au bord de la carte (les lignes sont plus larges
 * qu'elle, qui défile de côté) : l'écran défile d'abord jusqu'à la première. */
async function lignes(f, sel, etiquette) {
  await f.cacher(150);
  await f.defilerVers(f.p.locator(sel).first());
  // Tout le groupe au-dessus du sous-titre : sinon, la page remonte jusqu'à mettre la première ligne en haut.
  const dy = await f.p.evaluate(sel => {
    const R = [...document.querySelectorAll(sel)].map(e => e.getBoundingClientRect());
    const haut = Math.min(...R.map(r => r.top)), bas = Math.max(...R.map(r => r.bottom));
    return bas > innerHeight - 140 || haut < 50 ? Math.round(haut - 90) : 0;
  }, sel);
  if (dy) { await f.defiler(dy); }
  const r = await f.p.evaluate(sel => {
    const L = [...document.querySelectorAll(sel)];
    if (!L.length) { return null; }
    const c = L[0].closest('.tr-defil').getBoundingClientRect(), R = L.map(e => e.getBoundingClientRect());
    const x = Math.max(c.left, Math.min(...R.map(r => r.left))), x2 = Math.min(c.right, Math.max(...R.map(r => r.right)));
    const y = Math.min(...R.map(r => r.top)), y2 = Math.max(...R.map(r => r.bottom));
    return { x, y, w: x2 - x, h: y2 - y };
  }, sel);
  if (!r) { throw new Error('rien pour ' + sel); }
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiquette]);
}
/** Le sélecteur des lignes du plan dont le libellé est l'un de ceux donnés (leur rang dans la grille : le dessin
 * suivant garde l'ordre des lignes). */
async function ligne(f, ...libelles) {
  const rangs = await f.p.evaluate(L => [...document.querySelectorAll('#db-tresorerie .tr-carte-t .tr-grille > .tr-r')]
    .map((r, i) => { const s = r.querySelector('.tr-lab-t > span, .tr-lab-s > span'); return s && L.includes(s.textContent.trim()) ? i + 1 : 0; }).filter(Boolean), libelles);
  if (rangs.length !== libelles.length) { throw new Error('lignes introuvables : ' + libelles.join(', ')); }
  return rangs.map(k => T + `.tr-carte-t .tr-grille > .tr-r:nth-child(${k})`).join(', ');
}

/** Un peu de place sous la dernière carte de la page : elle peut alors remonter au-dessus du sous-titre (le tournage
 * seul ; la page n'en garde rien au rechargement). */
async function placeSousLaPage(f, px = 170) {
  await f.p.evaluate(px => { const el = document.getElementById('db-tresorerie'); if (el) { el.style.paddingBottom = px + 'px'; } }, px);
}

/** Fait défiler de côté le tableau de l'onglet (Paiements, Budget : plus larges que leur carte), en douceur. */
async function defilerCote(f, x) {
  await f.p.evaluate(async x => {
    const c = document.querySelector('#db-tresorerie .tr-defil'); if (!c) { return; }
    const de = c.scrollLeft, a = Math.max(0, Math.min(c.scrollWidth - c.clientWidth, x));
    const n = Math.max(20, Math.min(50, Math.round(Math.abs(a - de) / 14)));
    for (let i = 1; i <= n; i++) { const t = i / n, e = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; c.scrollLeft = de + (a - de) * e; await new Promise(r => requestAnimationFrame(r)); }
  }, x);
  await f.pause(300);
}

/** Un champ chiffré du module (data-trn) : le clic le sélectionne, on tape, Entrée valide ; l'enregistrement part 0,7 s plus tard. */
async function saisir(f, cle, valeur, { apres = 1300 } = {}) {
  await f.cliquer(T + `[data-trn="${cle}"]`, { apres: 250 });
  await f.taper(String(valeur), 110);
  await f.p.keyboard.press('Enter');
  await f.aller(f.souris.x + 34, f.souris.y + 40);   // le curseur s'écarte : le chiffre tapé se lit
  await f.pause(apres);
}
/** Un champ texte (libellé, remarque) : tout sélectionner, taper, Entrée. */
async function ecrire(f, cible, texte, { apres = 700 } = {}) {
  await f.cliquer(cible, { apres: 200 });
  await f.p.keyboard.press('Control+A');
  await f.taper(texte, 55);
  await f.p.keyboard.press('Enter');
  await f.pause(apres);
}

/** Une liste déroulante (la liste ouverte ne se filme pas) : le curseur y va, l'onde du clic, le choix. */
async function choisir(f, sel, valeur) {
  await f.survoler(T + sel, { pause: 300 });
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator(T + sel).selectOption(valeur);
  await f.pause(800);
}
/** Un champ de date (le calendrier du navigateur ne se filme pas) : le curseur y va, l'onde, la date. */
async function dater(f, sel, iso) {
  await f.survoler(T + sel, { dx: 0.3, pause: 300 });
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator(T + sel).fill(iso);
  await f.pause(650);
}

async function onglet(f, cle, apres = 1300) { await f.cliquer(T + `[data-tra="onglet:${cle}"]`, { apres }); }

/** Le chapitre d'une fonction : l'intercalaire « Pas à pas » reste le temps de le lire, 5,4 s au plus (un titre et trois
 * ou quatre étapes courtes ; au-delà, il pèse sur un chapitre de 30 à 50 s). */
const dureeCarte = (f, o) => Math.min(5400, Math.max(3400, Math.round(f.duree(o.titre + ' ' + (o.points || []).join(' ')) * 0.75)));
function fonction(f, o) { return f.fonction(Object.assign({ ms: dureeCarte(f, o) }, o)); }

/** Le chapitre d'une fonction dont l'état de départ se prépare pendant l'intercalaire, hors de l'écran : `preparer`
 * commence quand la diapositive couvre la page, et doit finir avant elle (ms : la durée de la diapositive). */
async function fonctionPreparee(f, opts, preparer) {
  const carte = f.fonction(Object.assign({ ms: Math.max(4600, dureeCarte(f, opts)) }, opts));
  await attendre(900);
  await preparer();
  await carte;
}

module.exports = { fiabiliser, API, T, ROUTINE, DECISION, GRAPHE, poster, lire, modifierPlan, recharger, lignes, ligne, placeSousLaPage, defilerCote, saisir, ecrire, choisir, dater, onglet, fonction, fonctionPreparee, attendre };
