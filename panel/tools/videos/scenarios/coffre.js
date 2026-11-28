/* Les gestes communs des vidéos de Mon coffre-fort par onglet (23-01-tout … 23-10-vide, 26/11/2026).
 * Les données sont celles de mocks/23_coffre.js (fictives). Pas un scénario : tourner.js ne lance que les fichiers NN-…. */
const API = '../api/cockpit';
const SOURCES = ['panel/public/dashboard/coffre.js', 'panel/public/dashboard/coffre.css', 'consultant/src/coffre.php', 'consultant/src/coffre_dates.php'];

/** Un POST JSON à la fausse API, depuis la page (hors film). */
const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()),
  [API + chemin, Object.assign({ shop: '4' }, corps || {})]);

/** Pour le film seulement : de la marge sous le coffre, pour que le bas de la page (« tout afficher », la dernière pièce)
 * remonte au-dessus des sous-titres quand on le montre. */
async function marge(p) {
  const css = '.cf2{padding-bottom:170px}';
  await p.context().addInitScript(c => { document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = c; document.head.appendChild(s); }); }, css);
  await p.addStyleTag({ content: css });
}

/** L'assistant prend quelques secondes pour lire un document : la fausse API répond tout de suite, on la fait attendre. */
const lenteur = (p, ms = 2800) => p.route('**/api/cockpit/coffre/magasin/assistant', async r => { await new Promise(x => setTimeout(x, ms)); await r.fallback().catch(() => null); });

/** Un PDF fictif du poids voulu (en Ko). */
const pdf = (name, ko) => ({ name, mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n% document fictif\n'), Buffer.alloc(ko * 1024, 32)]) });

/** Dans la fenêtre du dépôt : un clic sur la zone du fichier ; le sélecteur du système (qui ne se filme pas) reçoit le fichier. */
async function choisirFichier(f, fichier) {
  const [c] = await Promise.all([f.p.waitForEvent('filechooser'), f.cliquer('[data-cf-drop]', { apres: 300 })]);
  await c.setFiles(fichier);
  await f.pause(700);
}

/** Un cadre sans faire défiler la page : le bas du rail est épinglé ; le tiroir et la fenêtre du dépôt sont fixes. */
async function cadreFixe(f, sel, etiq, ms) {
  const r = await f.rect(sel, false);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq || '']);
  if (ms) { await f.pause(ms); }
}
async function cliquerFixe(f, sel, apres = 900) {
  const r = await f.rect(sel, false);
  await f.aller(r.x + r.w / 2, r.y + r.h / 2); await f.pause(250);
  await f.p.mouse.down(); await f.pause(90); await f.p.mouse.up();
  await f.pause(apres);
}

/** Taper une date dans un champ date (jj/mm/aaaa à l'écran) : le champ, puis les chiffres. */
async function taperDate(f, sel, iso) {
  await f.cliquer(sel, { dx: 0.12, apres: 200 });
  await f.taper(iso.slice(8, 10) + iso.slice(5, 7) + iso.slice(0, 4), 90);
  await f.p.locator(sel).dispatchEvent('change');
}

/** Le message vert (« Rangé », « Date retirée »…) s'efface seul après 6 s et la page remonte d'autant : on attend qu'il
 * soit parti avant de montrer ce qui est dessous (sinon le cadre glisse d'une ligne). */
async function apresFlash(f) {
  await f.p.waitForSelector('.cf2-flash.ok', { state: 'detached', timeout: 8000 }).catch(() => null);
  await f.pause(250);
}

/** Remettre la page en haut, sans à-coup. */
const enHaut = f => f.defiler(-5000);

module.exports = { API, SOURCES, poster, marge, lenteur, pdf, choisirFichier, cadreFixe, cliquerFixe, taperDate, apresFlash, enHaut };
