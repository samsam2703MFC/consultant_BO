/* Les gestes communs aux vidéos par onglet de Mon équipe (02-01 … 02-14, 24/11/2026) : l'état de départ hors film
 * (options, services posés par la fausse API, la semaine montrée), et quelques gestes filmés (tirer la ligne d'un
 * service, allumer une option). Données fictives : mocks/20_equipe.js (planning du 1er au 29 novembre 2026, jeudi 19 =
 * aujourd'hui ; la semaine du 30 novembre est vide). Ce fichier n'est pas un scénario (pas de numéro en tête). */
const API = '../api/cockpit';

/** Un appel à la fausse API depuis la page (hors film, dans `avant`). */
const envoyer = (p, chemin, corps, m = 'POST') => p.evaluate(([u, b, m]) => fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()), [API + chemin, Object.assign({ shop: 4 }, corps), m]);
/** Allume ou éteint des options du magasin (assistant, taches, matrice, recopie). */
const options = (p, o) => envoyer(p, '/dashboard/equipe/options', { options: o });
async function recharger(p) { await p.reload(); await p.waitForTimeout(2500); await p.evaluate(() => window.__film.prets()); }

/** Hors film : l'onglet Planning, une vue (tableau = Semaine, jour, mois), puis `pas` clics sur ‹ (négatif) ou › (positif). */
async function planning(p, { vue = 'tableau', pas = 0 } = {}) {
  await p.click('[data-eqp-onglet=planning]'); await p.waitForTimeout(1300);
  if (vue !== 'tableau') { await p.click(`[data-eqp-vue=${vue}]`); await p.waitForTimeout(1300); }
  for (let i = 0; i < Math.abs(pas); i++) { await p.locator('[data-eqp-pas]').nth(pas > 0 ? 1 : 0).click(); await p.waitForTimeout(1100); }
}

/** Hors film : l'assistant de la semaine montrée, ouvert, puis `suites` clics sur « Continuer › » ; `etats` : {employé: 'a'|'r'}
 * posés à l'étape 2 (absent·e, à revoir). */
async function assistant(p, { suites = 0, etats = {} } = {}) {
  await p.locator('.eqp-vide7 [data-eqp-assistant]').click(); await p.waitForTimeout(1800);
  for (let i = 0; i < suites; i++) {
    if (i === 1) { for (const [emp, v] of Object.entries(etats)) { await p.click(`[data-eqp-as-etat="${v}"][data-emp="${emp}"]`); await p.waitForTimeout(250); } }
    await p.click('[data-eqp-as-suite]'); await p.waitForTimeout(900);
  }
}

const mn = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
/** L'abscisse d'une heure « HH:MM » sur la ligne de la fenêtre d'un service (son échelle : data-h0, data-h1). */
async function xTrace(f, hhmm) {
  const r = await f.rect('[data-eqp-trace]');
  const [h0, h1] = await f.p.evaluate(() => { const t = document.querySelector('[data-eqp-trace]'); return [+t.dataset.h0, +t.dataset.h1]; });
  return { x: r.x + r.w * (mn(hhmm) - h0) / (h1 - h0), y: r.y + r.h * 0.62 };
}
/** Tire un bord (« de » ou « a ») de la ligne de la fenêtre jusqu'à une heure. */
async function tirerBord(f, bord, hhmm, ms = 1400) {
  const b = await f.rect(`[data-eqp-poignee=${bord}]`), c = await xTrace(f, hhmm);
  await f.tirer(b.x + b.w / 2, b.y + b.h / 2, c.x, b.y + b.h / 2, ms);
}
/** Trace une nouvelle ligne sur la piste de la fenêtre, d'une heure à l'autre (hors de la ligne existante). */
async function tracerLigne(f, de, a, ms = 1600) {
  const d = await xTrace(f, de), b = await xTrace(f, a);
  await f.tirer(d.x, d.y, b.x, b.y, ms);
}
/** L'abscisse d'une heure sur une ligne du planogramme du jour (de 4 h à 20 h, l'échelle de la démo). */
function xJour(r, hhmm, h0 = 240, h1 = 1200) { return r.x + r.w * (mn(hhmm) - h0) / (h1 - h0); }

/** Filmé : « Options », l'interrupteur d'un module, « Fermer ». */
async function allumer(f, cle, { dire } = {}) {
  await f.cliquer('[data-eqp-options]', { apres: 900 });
  await f.montrer(`.eqp-opt-l:has([data-eqp-opt="${cle}"])`, 'Options');
  if (dire) { await f.dire(dire); }
  await f.cacher(150);
  await f.cliquer(`[data-eqp-opt="${cle}"]`, { apres: 1300 });
  await f.taire(150);
  await f.cliquer('[data-eqp-options-fermer]', { apres: 1100 });
}

/** Le sous-titre en haut de l'écran (ou de retour en bas) : pour parler du bas de la fenêtre d'un service (« Sur ce
 * shift »), que le sous-titre couvrirait. */
async function sousTitre(f, ou) {
  await f.taire(300);   // le sous-titre affiché s'efface d'abord : il ne saute pas d'un bord à l'autre
  await f.p.evaluate(ou => { const s = document.getElementById('film-st'); if (!s) { return; } s.style.top = ou === 'haut' ? '18px' : ''; s.style.bottom = ou === 'haut' ? 'auto' : ''; }, ou);
}

/** Le numéro de colonne (1…) d'un jour dans le tableau de la semaine. */
async function colonne(f, date) {
  return f.p.evaluate(date => { const td = document.querySelector(`[data-eqp-cell="${date}"]`); return td ? [...td.parentElement.children].indexOf(td) + 1 : 2; }, date);
}

const SOURCES = ['panel/public/dashboard/equipe.js', 'panel/public/dashboard/equipe.css', 'panel/src/equipe.php'];

module.exports = { envoyer, options, recharger, planning, assistant, xTrace, tirerBord, tracerLigne, xJour, allumer, sousTitre, colonne, SOURCES };
