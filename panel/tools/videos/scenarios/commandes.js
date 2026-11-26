/* Les gestes partagés des vidéos de la page Commandes (25-commandes, 25-01-liste, 25-02-tournees) et de la page Clients
 * (26-*) : les listes déroulantes, les champs d'heure, les fenêtres qui se préparent sous leur intercalaire.
 * Données fictives : mocks/25_commandes.js, mocks/26_clients.js. */
const attendre = ms => new Promise(r => setTimeout(r, ms));
const API = '../api/cockpit';
const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [API + chemin, corps]);

/** Une liste déroulante (la liste ouverte ne se filme pas) : le curseur y va, l'onde du clic, le choix. */
async function choisir(f, sel, valeur, apres = 800) {
  await f.survoler(sel, { pause: 300 });
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator(sel).first().selectOption(valeur);
  await f.pause(apres);
}
/** Un champ d'heure ou de date (le sélecteur du navigateur ne se filme pas) : le curseur, l'onde, la valeur. */
async function heure(f, sel, v, apres = 600) {
  await f.survoler(sel, { dx: 0.3, pause: 250 });
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator(sel).first().fill(v);
  await f.pause(apres);
}
/** Un champ texte : un clic, tout sélectionner, taper. */
async function ecrire(f, sel, texte, { delai = 55, apres = 300 } = {}) {
  await f.cliquer(sel, { apres: 150 });
  await f.p.keyboard.press('Control+A');
  await f.taper(texte, delai);
  await f.pause(apres);
}
/** L'intercalaire d'une fonction : le temps de le lire, 5,4 s au plus. */
const dureeCarte = (f, o) => Math.min(5400, Math.max(3400, Math.round(f.duree(o.titre + ' ' + (o.points || []).join(' ')) * 0.75)));
function fonction(f, o) { return f.fonction(Object.assign({ ms: dureeCarte(f, o) }, o)); }
/** Une fonction dont l'état de départ se prépare sous l'intercalaire, hors de l'écran. */
async function fonctionPreparee(f, o, preparer) {
  const carte = f.fonction(Object.assign({ ms: Math.max(4600, dureeCarte(f, o)) }, o));
  await attendre(900);
  await preparer();
  await carte;
}

/** Le compte TF Buddy du magasin connecté sur cet appareil (hors film) : la connexion à la fausse API, la session gardée
 * dans le navigateur comme tfb-magasin.js la garde, puis la page relue. */
async function connecterTfb(p) {
  await poster(p, '/dashboard/tfb/connexion', { shop: '4', phone: '+32 400 12 34 56', pin: '2580' });
  const d = await poster(p, '/dashboard/tfb/confirmer', { shop: '4', challenge: 'demo-challenge-magasin-quatre', code: '481516' });
  await p.evaluate(j => localStorage.setItem('tfb-magasin:4', JSON.stringify(j)), d.jetons);
}
async function recharger(p, ms = 2500) {
  await p.reload(); await p.waitForTimeout(ms);
  await p.evaluate(() => window.__film.prets());
}

module.exports = { attendre, API, poster, choisir, heure, ecrire, dureeCarte, fonction, fonctionPreparee, connecterTfb, recharger };
